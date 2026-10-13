<?php

/**
 * Plugin_Usage_Tracker
 *
 * Opt-in usage tracking SDK (WP Insights). Responsible for collecting
 * non-sensitive diagnostic data and sending it to the insights endpoint
 * when the site administrator has explicitly opted in.
 *
 * Ported from the WPDeveloper WP Insights SDK and adapted for ThinkRank:
 * standalone notice wiring (no external notice library) and ThinkRank
 * feature usage as the optional data payload.
 *
 * @package ThinkRank\Core
 * @since 1.12.0
 * @version 3.0.3
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main SDK for Plugin_Usage_Tracker.
 */
class Plugin_Usage_Tracker {

    /**
     * WP Insights Version
     */
    const WPINS_VERSION = '3.0.3';

    /**
     * API URL
     */
    const API_URL = 'https://send.wpinsight.com/process-plugin-data';

    /**
     * Installed Plugin File
     *
     * @var string
     */
    private $plugin_file = null;

    /**
     * Installed Plugin Name
     *
     * @var string
     */
    private $plugin_name = null;

    /**
     * How often the event should subsequently
     *
     * @var string
     */
    public $recurrence = 'daily';

    private $event_hook = null;

    /**
     * Instance of Plugin_Usage_Tracker
     *
     * @var Plugin_Usage_Tracker
     */
    private static $_instance = null;

    private $disabled_wp_cron;
    private $enable_self_cron;
    private $require_optin;
    private $include_goodbye_form;
    private $marketing;
    private $options;
    private $item_id;
    private $notice_options;

    /**
     * Unix timestamp before which the opt-in notice stays hidden.
     *
     * 0 keeps the SDK's original behaviour (ask on the first admin page load).
     * ThinkRank sets it to install time + a grace period via
     * {@see Usage_Tracker_Manager::start_tracking()}.
     *
     * @var int
     */
    private $notice_after = 0;

    /**
     * Seconds to wait for the insights endpoint. The daily ping runs in cron
     * and can wait; the deactivation ping runs while the user waits on the
     * Plugins screen, so deactivate_this_plugin() lowers it.
     *
     * @var int
     */
    private $request_timeout = 30;

    /**
     * Get Instance of Plugin_Usage_Tracker
     *
     * @return Plugin_Usage_Tracker
     */
    public static function get_instance($plugin_file, $args = []) {
        if (is_null(static::$_instance)) {
            static::$_instance = new static($plugin_file, $args);
        }
        return static::$_instance;
    }

    /**
     * Automatically Invoked when initialized.
     *
     * @param string $plugin_file
     * @param array  $args
     */
    public function __construct($plugin_file, $args = []) {
        $this->plugin_file      = $plugin_file;
        $this->plugin_name      = basename($this->plugin_file, '.php');
        $this->disabled_wp_cron = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON == true;
        $this->enable_self_cron = $this->disabled_wp_cron == true ? true : false;

        $this->event_hook = 'thinkrank_put_do_daily_action';

        $this->require_optin        = isset($args['opt_in']) ? $args['opt_in'] : true;
        $this->include_goodbye_form = isset($args['goodbye_form']) ? $args['goodbye_form'] : true;
        $this->marketing            = isset($args['email_marketing']) ? $args['email_marketing'] : true;
        $this->options              = isset($args['options']) ? $args['options'] : [];
        $this->item_id              = isset($args['item_id']) ? $args['item_id'] : false;

        /**
         * Activation Hook
         */
        register_activation_hook($this->plugin_file, array($this, 'activate_this_plugin'));
        /**
         * Deactivation Hook
         */
        register_deactivation_hook($this->plugin_file, array($this, 'deactivate_this_plugin'));
    }

    /**
     * When user agreed to opt-in tracking schedule is enabled.
     *
     * @since 1.12.0
     */
    public function schedule_tracking() {
        if ($this->disabled_wp_cron) {
            return;
        }
        if (!wp_next_scheduled($this->event_hook)) {
            wp_schedule_event(time(), $this->recurrence, $this->event_hook);
        }
    }

    /**
     * Add the schedule event if the plugin is tracked.
     *
     * @return void
     */
    public function activate_this_plugin() {
        $allow_tracking = $this->is_tracking_allowed();
        if (!$allow_tracking) {
            return;
        }
        $this->schedule_tracking();
    }

    /**
     * Remove the schedule event when plugin is deactivated and send the
     * deactivated reason to insights if user submitted.
     *
     * @since 1.12.0
     */
    public function deactivate_this_plugin() {
        /**
         * Check tracking is allowed or not.
         */
        $allow_tracking = $this->is_tracking_allowed();
        if (!$allow_tracking) {
            return;
        }
        // The user is waiting on this request: do not hold the Plugins
        // screen for the full cron timeout if the endpoint is slow.
        $this->request_timeout    = 5;
        $body                     = $this->get_data();
        $body['status']           = 'Deactivated';
        $body['deactivated_date'] = time();

        // Check deactivation reason and add for insights data.
        if (false !== get_option('wpins_deactivation_reason_' . $this->plugin_name)) {
            $body['deactivation_reason'] = get_option('wpins_deactivation_reason_' . $this->plugin_name);
        }
        if (false !== get_option('wpins_deactivation_details_' . $this->plugin_name)) {
            $body['deactivation_details'] = get_option('wpins_deactivation_details_' . $this->plugin_name);
        }

        $this->send_data($body);
        delete_option('wpins_deactivation_reason_' . $this->plugin_name);
        delete_option('wpins_deactivation_details_' . $this->plugin_name);
        /**
         * Clear the event schedule.
         */
        if (!$this->disabled_wp_cron) {
            wp_clear_scheduled_hook($this->event_hook);
        }
    }

    /**
     * Initial Method to Hook Everything.
     *
     * @return void
     */
    public function init() {
        add_action('admin_init', array($this, 'clicked'));
        add_action($this->event_hook, array($this, 'do_tracking'));

        // Both hooks, deliberately: Admin\Manager::remove_admin_notice() strips
        // every `admin_notices` callback on ThinkRank's own screens and re-fires
        // `thinkrank_admin_notices` in their place. Since notice() now renders
        // only on those screens, registering on `admin_notices` alone would mean
        // it never renders anywhere. The two never both run on one request.
        add_action('admin_notices', array($this, 'notice'));
        add_action('thinkrank_admin_notices', array($this, 'notice'));
        /**
         * Deactivation Reason Form and Submit Data to Insights.
         */
        add_action('admin_enqueue_scripts', array($this, 'enqueue_deactivation_assets'));
        add_action('admin_footer-plugins.php', array($this, 'deactivate_reasons_form'));
        add_action('wp_ajax_' . $this->feedback_action(), array($this, 'deactivate_reasons_form_submit'));
    }

    /**
     * For Redirecting Current Page without Arguments!
     *
     * @return string
     */
    private function redirect_to() {
        $request_uri  = !empty($_SERVER['REQUEST_URI']) ? wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '';
        $query_string = !empty($_SERVER['REQUEST_URI']) ? wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_QUERY) : '';
        parse_str((string) $query_string, $current_url);

        $unset_array = array('dismiss', 'plugin', '_wpnonce', 'later', 'plugin_action', 'marketing_optin');

        foreach ($unset_array as $value) {
            if (isset($current_url[$value])) {
                unset($current_url[$value]);
            }
        }

        $current_url  = http_build_query($current_url);
        $redirect_url = $request_uri . '?' . $current_url;
        return $redirect_url;
    }

    /**
     * This method forcing the do_tracking method to execute instant.
     *
     * @return void
     */
    public function force_tracking() {
        $this->do_tracking(true);
    }

    /**
     * This method is responsible for all the magic from the front of the plugin.
     *
     * @since 1.12.0
     * @param bool $force Force tracking if it's not the correct time to track.
     */
    public function do_tracking($force = false) {
        /**
         * Check URL is set or not.
         */
        if (empty(self::API_URL)) {
            return;
        }
        /**
         * Check is tracking allowed or not.
         */
        if (!$this->is_tracking_allowed()) {
            return;
        }
        /**
         * Check is this the correct time to track or not.
         * or Force to track.
         */
        if (!$this->is_time_to_track() && !$force) {
            return;
        }
        /**
         * Get All Data.
         */
        $body = $this->get_data();
        /**
         * Send all data.
         */
        return $this->send_data($body);
    }

    /**
     * Is tracking allowed?
     *
     * @since 1.12.0
     */
    private function is_tracking_allowed() {
        // First, check if the user has changed their mind and opted out of tracking
        if ($this->has_user_opted_out()) {
            $this->set_is_tracking_allowed(false, $this->plugin_name);
            return false;
        }
        // The wpins_allow_tracking option is an array of plugins that are being tracked
        $allow_tracking = get_option('wpins_allow_tracking');
        // If this plugin is in the array, then tracking is allowed
        if (isset($allow_tracking[$this->plugin_name])) {
            return true;
        }
        return false;
    }

    /**
     * Set a flag in DB If tracking is allowed.
     *
     * @since 1.12.0
     * @param bool        $is_allowed true if is allowed.
     * @param string|null $plugin
     */
    public function set_is_tracking_allowed($is_allowed, $plugin = null) {
        if (empty($plugin)) {
            $plugin = $this->plugin_name;
        }
        /**
         * Get All Tracked Plugin List using this Tracker.
         */
        $allow_tracking = get_option('wpins_allow_tracking');
        /**
         * Check user is opted out for tracking or not.
         */
        if ($this->has_user_opted_out()) {
            if (isset($allow_tracking[$plugin])) {
                unset($allow_tracking[$plugin]);
            }
        } elseif ($is_allowed || !$this->require_optin) {
            /**
             * If user has agreed to allow tracking
             */
            if (empty($allow_tracking) || !is_array($allow_tracking)) {
                $allow_tracking = array($plugin => $plugin);
            } else {
                $allow_tracking[$plugin] = $plugin;
            }
        } else {
            if (isset($allow_tracking[$plugin])) {
                unset($allow_tracking[$plugin]);
            }
        }
        update_option('wpins_allow_tracking', $allow_tracking);
    }

    /**
     * Check the user has opted out or not.
     *
     * @since 1.12.0
     * @return bool
     */
    protected function has_user_opted_out() {
        if (!empty($this->options)) {
            foreach ($this->options as $option_name) {
                $options = get_option($option_name);
                if (!empty($options['wpins_opt_out'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if it's time to track
     *
     * @since 1.12.0
     */
    public function is_time_to_track() {
        $track_times = get_option('wpins_last_track_time', array());
        return !isset($track_times[$this->plugin_name]) ? true :
                (($track_times[$this->plugin_name]) < strtotime('-1 day') ? true : false);
    }

    /**
     * Set tracking time.
     *
     * @since 1.12.0
     */
    public function set_track_time() {
        $track_times                       = get_option('wpins_last_track_time', array());
        $track_times[$this->plugin_name]   = time();
        update_option('wpins_last_track_time', $track_times);
    }

    /**
     * This method is responsible for collecting all data.
     *
     * @since 1.12.0
     */
    public function get_data() {
        $body = array(
            'plugin_slug'   => sanitize_text_field($this->plugin_name),
            'url'           => get_bloginfo('url'),
            'site_name'     => get_bloginfo('name'),
            'site_version'  => get_bloginfo('version'),
            'site_language' => get_bloginfo('language'),
            'charset'       => get_bloginfo('charset'),
            'wpins_version' => self::WPINS_VERSION,
            'php_version'   => phpversion(),
            'multisite'     => is_multisite(),
            'file_location' => __FILE__,
        );

        // Collect the email if the correct option has been set
        if ($this->marketing) {
            if (!function_exists('wp_get_current_user')) {
                include ABSPATH . 'wp-includes/pluggable.php';
            }
            $current_user = wp_get_current_user();
            $email        = $current_user->user_email;
            if (is_email($email)) {
                $body['email'] = $email;
            } else {
                $email = get_option('admin_email');
                if (is_email($email)) {
                    $body['email'] = $email;
                }
            }
        }
        $body['marketing_method'] = $this->marketing;
        $body['server']           = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';

        /**
         * Collect all active and inactive plugins
         */
        if (!function_exists('get_plugins')) {
            include ABSPATH . '/wp-admin/includes/plugin.php';
        }
        $plugins        = array_keys(get_plugins());
        $active_plugins = is_network_admin() ? array_keys(get_site_option('active_sitewide_plugins', array())) : get_option('active_plugins', array());
        foreach ($plugins as $key => $plugin) {
            if (in_array($plugin, $active_plugins)) {
                unset($plugins[$key]);
            }
        }
        $body['active_plugins']   = $active_plugins;
        $body['inactive_plugins'] = $plugins;

        /**
         * Text Direction.
         */
        $body['text_direction'] = (function_exists('is_rtl') ? (is_rtl() ? 'RTL' : 'LTR') : 'NOT SET');
        /**
         * Get Our Plugin Data.
         *
         * @since 1.12.0
         */
        $plugin = $this->plugin_data();
        if (empty($plugin)) {
            $body['message'] = __('We can\'t detect any plugin information. This is most probably because you have not included the code in the plugin main file.', 'thinkrank');
            $body['status']  = 'NOT FOUND';
        } else {
            if (isset($plugin['Name'])) {
                $body['plugin'] = sanitize_text_field($plugin['Name']);
            }
            if (isset($plugin['Version'])) {
                $body['version'] = sanitize_text_field($plugin['Version']);
            }
            $body['status'] = 'Active';
        }

        /**
         * Get active theme name and version
         *
         * @since 1.12.0
         */
        $theme = wp_get_theme();
        if ($theme->Name) {
            $body['theme'] = sanitize_text_field($theme->Name);
        }
        if ($theme->Version) {
            $body['theme_version'] = sanitize_text_field($theme->Version);
        }

        $optional_data = $this->get_used_elements_count();
        if (!empty($optional_data)) {
            $body['optional_data'] = $optional_data;
        }

        return $body;
    }

    /**
     * Collect plugin data,
     * Retrieve current plugin information
     *
     * @since 1.12.0
     */
    public function plugin_data() {
        if (!function_exists('get_plugin_data')) {
            include ABSPATH . '/wp-admin/includes/plugin.php';
        }
        $plugin = get_plugin_data($this->plugin_file);
        return $plugin;
    }

    /**
     * Send the data to insights.
     *
     * @since 1.12.0
     */
    public function send_data($body) {
        /**
         * Get SITE ID
         */
        $site_id_key       = "wpins_{$this->plugin_name}_site_id";
        $site_id           = get_option($site_id_key, false);
        $failed_data       = [];
        $diff_data         = [];
        $site_url          = get_bloginfo('url');
        $original_site_url = get_option("wpins_{$this->plugin_name}_original_url", false);

        if (($original_site_url === false || $original_site_url != $site_url) && version_compare($body['wpins_version'], '3.0.1', '>=')) {
            $site_id = false;
        }
        /**
         * Send Initial Data to API
         */
        if ($site_id == false && $this->item_id !== false) {
            $body['plugin_slug'] = $this->plugin_name;
            $body['url']         = $site_url;
            $body['item_id']     = $this->item_id;

            $request = $this->remote_post($body);
            if (!is_wp_error($request) && $request['response']['code'] == 200) {
                $retrieved_body = json_decode(wp_remote_retrieve_body($request), true);
                if (is_array($retrieved_body) && isset($retrieved_body['siteId'])) {
                    update_option($site_id_key, $retrieved_body['siteId']);
                    update_option("wpins_{$this->plugin_name}_original_url", $site_url);
                    update_option("wpins_{$this->plugin_name}_{$retrieved_body['siteId']}", $body);
                }
            } else {
                $failed_data = $body;
            }
        }

        $site_id_data_key        = "wpins_{$this->plugin_name}_{$site_id}";
        $site_id_data_failed_key = "wpins_{$this->plugin_name}_{$site_id}_send_failed";

        if ($site_id != false) {
            $old_sent_data = get_option($site_id_data_key, []);
            $diff_data     = $this->diff($body, $old_sent_data);
            $failed_data   = get_option($site_id_data_failed_key, []);
            if (!empty($failed_data) && $diff_data != $failed_data) {
                $failed_data = array_merge($failed_data, $diff_data);
            }
        }

        if (!empty($failed_data) && $site_id != false) {
            $failed_data['plugin_slug'] = $this->plugin_name;
            $failed_data['url']         = $site_url;
            $failed_data['site_id']     = $site_id;
            if ($original_site_url != false) {
                $failed_data['original_url'] = $original_site_url;
            }

            $request = $this->remote_post($failed_data);
            if (!is_wp_error($request)) {
                delete_option($site_id_data_failed_key);
                $replaced_data = array_merge($old_sent_data, $failed_data);
                update_option($site_id_data_key, $replaced_data);
            }
        }

        if (!empty($diff_data) && $site_id != false && empty($failed_data)) {
            $diff_data['plugin_slug'] = $this->plugin_name;
            $diff_data['url']         = $site_url;
            $diff_data['site_id']     = $site_id;
            if ($original_site_url != false) {
                $diff_data['original_url'] = $original_site_url;
            }

            $request = $this->remote_post($diff_data);
            if (is_wp_error($request)) {
                update_option($site_id_data_failed_key, $diff_data);
            } else {
                $replaced_data = array_merge($old_sent_data, $diff_data);
                update_option($site_id_data_key, $replaced_data);
            }
        }

        $this->set_track_time();

        if (isset($request) && is_wp_error($request)) {
            return $request;
        }

        if (isset($request)) {
            return true;
        }
        return false;
    }

    /**
     * WP_REMOTE_POST method responsible for send data to the API_URL
     *
     * @param array $data
     * @param array $args
     * @return mixed
     */
    protected function remote_post($data = array(), $args = array()) {
        if (empty($data)) {
            return;
        }

        $args = wp_parse_args($args, array(
            'method'      => 'POST',
            'timeout'     => $this->request_timeout,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'body'        => $data,
            'user-agent'  => 'PUT/1.0.0; ' . get_bloginfo('url'),
        ));
        $request = wp_remote_post(esc_url(self::API_URL), $args);
        if (is_wp_error($request) || (isset($request['response'], $request['response']['code']) && $request['response']['code'] != 200)) {
            return new \WP_Error(500, 'Something went wrong.');
        }
        return $request;
    }

    /**
     * Difference between old and new data
     *
     * @param array $new_data
     * @param array $old_data
     * @return array
     */
    protected function diff($new_data, $old_data) {
        $data = [];
        if (!empty($new_data)) {
            foreach ($new_data as $key => $value) {
                if (isset($old_data[$key])) {
                    if ($old_data[$key] == $value) {
                        continue;
                    }
                }
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /**
     * Display the admin notice to users to allow them to opt in
     *
     * @since 1.12.0
     */
    public function notice() {
        /**
         * Return if notice is not set.
         */
        if (!isset($this->notice_options['notice'])) {
            return;
        }
        /**
         * Already opted in — there is nothing left to ask for.
         */
        if ($this->is_tracking_allowed()) {
            return;
        }
        /**
         * Check is allowed or blocked for notice.
         */
        $block_notice = get_option('wpins_block_notice');
        if (isset($block_notice[$this->plugin_name])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        /**
         * Too soon after install to ask. A consent request on the user's very
         * first admin page load is the loudest possible first impression, and
         * it competes with the Setup Wizard the activation redirect just put
         * them in.
         */
        if ($this->notice_after > 0 && time() < $this->notice_after) {
            return;
        }
        /**
         * Ask on ThinkRank's own screens only. The card used to render on every
         * admin screen in the site, including other plugins' settings pages.
         */
        if (!$this->is_own_screen()) {
            return;
        }

        $url_yes = add_query_arg([
            'plugin'        => $this->plugin_name,
            'plugin_action' => 'yes',
        ]);
        $url_no = add_query_arg(array(
            'plugin'        => $this->plugin_name,
            'plugin_action' => 'no',
        ));

        $url_yes = wp_nonce_url($url_yes, '_wpnonce_optin_' . $this->plugin_name);
        $url_no  = wp_nonce_url($url_no, '_wpnonce_optin_' . $this->plugin_name);

        // Shared branded notice styles (same card layout as the welcome notice).
        wp_enqueue_style(
            'thinkrank-admin-notices',
            THINKRANK_PLUGIN_URL . 'static/css/admin-notices.css',
            [],
            THINKRANK_VERSION
        );

        // Decide on notice text
        $notice_title      = $this->notice_options['notice_title'] ?? '';
        $notice_text       = $this->notice_options['notice'] . ' <a href="#" class="wpinsights-' . esc_attr($this->plugin_name) . '-collect">' . $this->notice_options['consent_button_text'] . '</a>';
        $extra_notice_text = $this->notice_options['extra_notice'] ?? '';

        $output  = '';
        $output .= '<div class="notice notice-success put-dismiss-notice thinkrank-notice">';
            $output .= '<div class="thinkrank-notice__inner">';
                $output .= '<div class="thinkrank-notice__body">';
                    if ($notice_title !== '') {
                        $output .= '<p class="thinkrank-notice__title">' . esc_html($notice_title) . '</p>';
                    }
                    $output .= '<p class="thinkrank-notice__text">' . $notice_text . '</p>';
                    $output .= '<div class="wpinsights-data" style="display: none;">';
                        $output .= '<p class="thinkrank-notice__text">' . $extra_notice_text . '</p>';
                    $output .= '</div>';
                    $output .= '<p class="thinkrank-notice__actions">';
                        $output .= '<a href="' . esc_url($url_yes) . '" class="button button-primary">' . $this->notice_options['yes'] . '</a>';
                        $output .= '<a href="' . esc_url($url_no) . '" class="thinkrank-notice__dismiss">' . $this->notice_options['no'] . '</a>';
                    $output .= '</p>';
                $output .= '</div>';
            $output .= '</div>';
            $output .= "<script type='text/javascript'>jQuery('.wpinsights-" . esc_attr($this->plugin_name) . "-collect').on('click', function(e) {e.preventDefault();jQuery('.wpinsights-data').slideToggle('fast');});</script>";
        $output .= '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $output;
    }

    /**
     * Hold the opt-in notice back until the given time.
     *
     * @since 2.8.1
     * @param int $timestamp Unix timestamp; 0 restores "ask immediately".
     * @return void
     */
    public function set_notice_after($timestamp) {
        $this->notice_after = max(0, (int) $timestamp);
    }

    /**
     * Whether the current admin screen belongs to ThinkRank.
     *
     * Matched on the screen id rather than against a list of page slugs:
     * Admin\Manager already keeps such a list and it has drifted from the
     * pages actually registered, and Pro adds screens of its own that a list
     * living in free could not know about.
     *
     * The Setup Wizard screen is excluded — it suppresses admin notices
     * wholesale and collects consent itself, so a card there would be both
     * invisible and redundant.
     *
     * @since 2.8.1
     * @return bool
     */
    private function is_own_screen() {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen instanceof \WP_Screen || empty($screen->id)) {
            return false;
        }

        $is_own = strpos($screen->id, 'thinkrank') !== false
            && strpos($screen->id, 'thinkrank_setup_wizard') === false;

        /**
         * Filter whether the usage-tracking opt-in notice may render here.
         *
         * @since 2.8.1
         * @param bool      $is_own Whether this is a ThinkRank screen.
         * @param \WP_Screen $screen Current screen.
         */
        return (bool) apply_filters('thinkrank_usage_notice_is_own_screen', $is_own, $screen);
    }

    /**
     * Set all notice options to customized notice.
     *
     * @since 1.12.0
     * @param array $options
     * @return void
     */
    public function set_notice_options($options = []) {
        $default_options = [
            'consent_button_text' => __('What we collect.', 'thinkrank'),
            'yes'                 => __('Sure, I\'d like to help', 'thinkrank'),
            'no'                  => __('No Thanks.', 'thinkrank'),
        ];
        $options              = wp_parse_args($options, $default_options);
        $this->notice_options = $options;
    }

    /**
     * Responsible for track the click from Notice.
     *
     * @return void
     */
    public function clicked() {
        if (isset($_GET['_wpnonce']) && isset($_GET['plugin']) && sanitize_text_field(wp_unslash($_GET['plugin'])) === $this->plugin_name && isset($_GET['plugin_action'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), '_wpnonce_optin_' . $this->plugin_name)) {
                return;
            }

            if (isset($_GET['tab']) && $_GET['tab'] === 'plugin-information') {
                return;
            }
            $plugin = sanitize_text_field(wp_unslash($_GET['plugin']));
            $action = sanitize_text_field(wp_unslash($_GET['plugin_action']));
            if ($action == 'yes') {
                $this->schedule_tracking();
                $this->set_is_tracking_allowed(true, $plugin);
                if ($this->do_tracking(true)) {
                    $this->update_block_notice($plugin);
                }
                /**
                 * Redirect User To the Current URL, but without set query arguments.
                 */
                wp_safe_redirect($this->redirect_to());
            } else {
                $this->set_is_tracking_allowed(false, $plugin);
                $this->update_block_notice($plugin);
            }
        }
    }

    /**
     * Programmatically opt the site in to tracking.
     *
     * Mirrors the "yes" branch of clicked() without the request redirect, so
     * other flows (e.g. the Setup Wizard "Allow & Continue" action) can record
     * consent: schedule the cron, flag tracking allowed, send the first
     * payload and suppress the opt-in notice from then on.
     *
     * @since 1.12.0
     * @return void
     */
    public function opt_in() {
        $this->schedule_tracking();
        $this->set_is_tracking_allowed(true, $this->plugin_name);
        $this->do_tracking(true);
        $this->update_block_notice($this->plugin_name);
    }

    /**
     * Set if we should block the opt-in notice for this plugin
     *
     * @since 1.12.0
     */
    public function update_block_notice($plugin = null) {
        if (empty($plugin)) {
            $plugin = $this->plugin_name;
        }
        $block_notice = get_option('wpins_block_notice');
        if (empty($block_notice) || !is_array($block_notice)) {
            $block_notice = array($plugin => $plugin);
        } else {
            $block_notice[$plugin] = $plugin;
        }
        update_option('wpins_block_notice', $block_notice);
    }

    /**
     * Ajax action the deactivation dialog posts to.
     */
    private function feedback_action(): string {
        return 'deactivation_form_' . $this->plugin_name;
    }

    /**
     * Whether the deactivation dialog applies on this request.
     *
     * Only for someone who agreed to share usage data: the answer travels
     * with that data, to the same place. Without consent the plain WordPress
     * Deactivate link is left alone.
     *
     * @return bool
     */
    public function should_ask_on_deactivation(): bool {
        return (bool) $this->include_goodbye_form
            && current_user_can('activate_plugins')
            && $this->is_tracking_allowed();
    }

    /**
     * Load the dialog's stylesheet and script on the Plugins screen.
     *
     * @param string $hook_suffix Current admin page.
     * @return void
     */
    public function enqueue_deactivation_assets($hook_suffix): void {
        if ('plugins.php' !== $hook_suffix || !$this->should_ask_on_deactivation()) {
            return;
        }

        $version = defined('THINKRANK_VERSION') ? THINKRANK_VERSION : self::WPINS_VERSION;

        wp_enqueue_style(
            'thinkrank-deactivation-feedback',
            THINKRANK_PLUGIN_URL . 'static/css/deactivation-feedback.css',
            [],
            $version
        );
        wp_enqueue_script(
            'thinkrank-deactivation-feedback',
            THINKRANK_PLUGIN_URL . 'static/js/deactivation-feedback.js',
            [],
            $version,
            true
        );
        wp_localize_script('thinkrank-deactivation-feedback', 'thinkrankDeactivation', [
            'plugin'  => plugin_basename($this->plugin_file),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => $this->feedback_action(),
            'nonce'   => wp_create_nonce('wpins_deactivation_nonce'),
        ]);
    }

    /**
     * Ajax: store the reason the user picked, so deactivate_this_plugin()
     * sends it with the deactivation ping.
     *
     * @since 1.12.0
     * @return void
     */
    public function deactivate_reasons_form_submit() {
        check_ajax_referer('wpins_deactivation_nonce', 'security');

        // The dialog is only reachable from the Plugins screen; require the
        // same capability that screen does so the nonce is not the only control.
        if (!current_user_can('activate_plugins')) {
            wp_die('', '', ['response' => 403]);
        }

        $reason  = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : '';
        $details = isset($_POST['details']) ? sanitize_textarea_field(wp_unslash($_POST['details'])) : '';

        if (!$this->record_deactivation_feedback($reason, $details)) {
            wp_send_json_error(null, 400);
        }

        wp_send_json_success();
    }

    /**
     * Keep a deactivation reason for the deactivation ping.
     *
     * The reason is stored as its fixed English value, never the label the
     * user saw: a translated label made the same answer arrive as a dozen
     * different strings, one per language. An unknown reason is refused, and
     * nothing is kept for a site that has not agreed to share usage data.
     *
     * @param string $reason_key A key from deactivation_reasons().
     * @param string $details    Optional free text.
     * @return bool True when stored.
     */
    public function record_deactivation_feedback(string $reason_key, string $details = ''): bool {
        if (!$this->is_tracking_allowed()) {
            return false;
        }

        $reasons = $this->deactivation_reasons()['reasons'] ?? [];
        if (!isset($reasons[$reason_key]['value'])) {
            return false;
        }

        $details = trim($details);
        $details = function_exists('mb_substr') ? mb_substr($details, 0, 1000) : substr($details, 0, 1000);

        update_option('wpins_deactivation_reason_' . $this->plugin_name, (string) $reasons[$reason_key]['value'], false);
        if ($details !== '') {
            update_option('wpins_deactivation_details_' . $this->plugin_name, $details, false);
        } else {
            delete_option('wpins_deactivation_details_' . $this->plugin_name);
        }

        return true;
    }

    /**
     * The dialog's copy and reasons.
     *
     * Each reason has a stable `value` sent to insights (English, never
     * translated), the `label` the user reads, and optionally a `detail`
     * field: `input` or `textarea`, with its own label.
     *
     * @since 1.12.0
     * @return array{title: string, intro: string, note: string, reasons: array<string, array>}
     */
    public function deactivation_reasons(): array {
        $form = [
            'title'   => __('Before you go, what happened?', 'thinkrank'),
            'intro'   => __('Pick the reason that fits best. It goes straight to the team that builds ThinkRank and takes a few seconds.', 'thinkrank'),
            'note'    => __('Your SEO settings and data stay on your site while ThinkRank is off. Turn it back on any time and everything is where you left it.', 'thinkrank'),
            'reasons' => [
                'temporary'   => [
                    'value' => 'Temporary deactivation',
                    'label' => __('I am only switching it off for a moment, to troubleshoot or test', 'thinkrank'),
                ],
                'switching'   => [
                    'value'  => 'Switching to another SEO plugin',
                    'label'  => __('I am moving to another SEO plugin', 'thinkrank'),
                    'detail' => [
                        'type'  => 'input',
                        'label' => __('Which one? (optional)', 'thinkrank'),
                    ],
                ],
                'broken'      => [
                    'value'  => 'Something did not work',
                    'label'  => __('Something did not work or caused a problem', 'thinkrank'),
                    'detail' => [
                        'type'  => 'textarea',
                        'label' => __('What happened? A sentence or two helps us fix it.', 'thinkrank'),
                    ],
                    'help'   => [
                        'text' => __('Would you rather have it fixed? Our support team can help.', 'thinkrank'),
                        'link' => __('Contact support', 'thinkrank'),
                        'url'  => 'https://wpdeveloper.com/support/new-ticket/',
                    ],
                ],
                'missing'     => [
                    'value'  => 'Missing a feature',
                    'label'  => __('It is missing a feature I need', 'thinkrank'),
                    'detail' => [
                        'type'  => 'input',
                        'label' => __('Which feature? (optional)', 'thinkrank'),
                    ],
                ],
                'complicated' => [
                    'value'  => 'Too hard to use',
                    'label'  => __('It was harder to use than I expected', 'thinkrank'),
                    'detail' => [
                        'type'  => 'textarea',
                        'label' => __('What got in the way? (optional)', 'thinkrank'),
                    ],
                ],
                'not_needed'  => [
                    'value' => 'No longer needed',
                    'label' => __('This site does not need an SEO plugin anymore', 'thinkrank'),
                ],
                'other'       => [
                    'value'  => 'Other',
                    'label'  => __('Something else', 'thinkrank'),
                    'detail' => [
                        'type'  => 'textarea',
                        'label' => __('Tell us a little more (optional)', 'thinkrank'),
                    ],
                ],
            ],
        ];

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the SDK's long-standing hook name.
        return apply_filters('wpins_form_text_' . $this->plugin_name, $form);
    }

    /**
     * Print the deactivation dialog, closed, at the foot of the Plugins screen.
     *
     * A native <dialog>: the browser traps focus, closes it on Escape and
     * draws the backdrop. Styled with WordPress's own buttons and form
     * controls; static/css/deactivation-feedback.css scopes everything else
     * to the dialog, so nothing leaks onto the rest of the screen.
     *
     * @since 1.12.0
     * @return void
     */
    public function deactivate_reasons_form() {
        if (!$this->should_ask_on_deactivation()) {
            return;
        }

        $form = $this->deactivation_reasons();
        $id   = 'thinkrank-deactivation';
        ?>
        <dialog id="<?php echo esc_attr($id); ?>" class="thinkrank-deactivation" aria-labelledby="<?php echo esc_attr($id); ?>-title" aria-describedby="<?php echo esc_attr($id); ?>-intro">
            <form method="dialog" class="thinkrank-deactivation__form">
                <div class="thinkrank-deactivation__header">
                    <h2 id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($form['title']); ?></h2>
                    <button type="button" class="thinkrank-deactivation__close" data-thinkrank-action="cancel">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e('Close and keep ThinkRank active', 'thinkrank'); ?></span>
                    </button>
                </div>

                <div class="thinkrank-deactivation__body">
                    <p id="<?php echo esc_attr($id); ?>-intro" class="thinkrank-deactivation__intro"><?php echo esc_html($form['intro']); ?></p>

                    <fieldset class="thinkrank-deactivation__reasons">
                        <legend class="screen-reader-text"><?php esc_html_e('Reason for deactivating', 'thinkrank'); ?></legend>
                        <?php foreach ($form['reasons'] as $key => $reason) :
                            $key       = sanitize_key((string) $key);
                            $detail_id = $id . '-detail-' . $key;
                            ?>
                            <div class="thinkrank-deactivation__reason">
                                <label>
                                    <input type="radio" name="reason" value="<?php echo esc_attr($key); ?>"<?php echo !empty($reason['detail']) ? ' aria-controls="' . esc_attr($detail_id) . '"' : ''; ?>>
                                    <?php echo esc_html((string) ($reason['label'] ?? '')); ?>
                                </label>
                                <?php if (!empty($reason['detail']) || !empty($reason['help'])) : ?>
                                    <div class="thinkrank-deactivation__detail" id="<?php echo esc_attr($detail_id); ?>" data-thinkrank-reason="<?php echo esc_attr($key); ?>" hidden>
                                        <?php if (!empty($reason['detail'])) : ?>
                                            <label class="screen-reader-text" for="<?php echo esc_attr($detail_id); ?>-field"><?php echo esc_html((string) $reason['detail']['label']); ?></label>
                                            <?php if (($reason['detail']['type'] ?? 'input') === 'textarea') : ?>
                                                <textarea id="<?php echo esc_attr($detail_id); ?>-field" class="large-text" rows="3" maxlength="1000" placeholder="<?php echo esc_attr((string) $reason['detail']['label']); ?>" disabled></textarea>
                                            <?php else : ?>
                                                <input type="text" id="<?php echo esc_attr($detail_id); ?>-field" class="regular-text" maxlength="200" placeholder="<?php echo esc_attr((string) $reason['detail']['label']); ?>" disabled>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (!empty($reason['help'])) : ?>
                                            <p class="thinkrank-deactivation__help">
                                                <?php echo esc_html((string) $reason['help']['text']); ?>
                                                <a href="<?php echo esc_url((string) $reason['help']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $reason['help']['link']); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'thinkrank'); ?></span></a>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>

                    <p class="thinkrank-deactivation__note">
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        <?php echo esc_html($form['note']); ?>
                    </p>
                    <p class="thinkrank-deactivation__error" role="alert" hidden><?php esc_html_e('Your answer could not be sent. ThinkRank will still be deactivated.', 'thinkrank'); ?></p>
                </div>

                <div class="thinkrank-deactivation__footer">
                    <a href="#" class="thinkrank-deactivation__skip" data-thinkrank-action="skip"><?php esc_html_e('Skip and deactivate', 'thinkrank'); ?></a>
                    <span class="thinkrank-deactivation__actions">
                        <span class="spinner" aria-hidden="true"></span>
                        <button type="button" class="button" data-thinkrank-action="cancel"><?php esc_html_e('Cancel', 'thinkrank'); ?></button>
                        <button type="submit" class="button button-primary" data-thinkrank-action="submit" disabled><?php esc_html_e('Submit and deactivate', 'thinkrank'); ?></button>
                    </span>
                </div>
            </form>
        </dialog>
        <?php
    }

    /**
     * Get Used Elements Count
     *
     * Optional data payload describing which ThinkRank features are enabled
     * on the site. Reported as a name => count map (1 = enabled) so the
     * insights backend can aggregate feature adoption.
     *
     * @return array
     *
     * @since 1.12.0
     */
    public static function get_used_elements_count() {
        $used = [];

        // AI provider in use.
        $ai_provider = get_option('thinkrank_ai_provider', '');
        if (!empty($ai_provider)) {
            $used['ai_provider_' . sanitize_key($ai_provider)] = 1;
        }

        // Boolean feature flags stored as standalone options.
        $flag_options = [
            'thinkrank_auto_optimize'   => 'auto_optimize',
            'thinkrank_enable_logging'  => 'logging',
        ];
        foreach ($flag_options as $option_name => $label) {
            if (!empty(get_option($option_name))) {
                $used[$label] = 1;
            }
        }

        // Feature settings groups: report when configured/enabled.
        $feature_settings = [
            'thinkrank_instant_indexing_settings' => 'instant_indexing',
            'thinkrank_author_archives_settings'  => 'author_archives',
            'thinkrank_image_seo_settings'        => 'image_seo',
        ];
        foreach ($feature_settings as $option_name => $label) {
            $settings = get_option($option_name, []);
            if (is_array($settings) && !empty($settings['enabled'])) {
                $used[$label] = 1;
            }
        }

        // Indexed content volume buckets (lightweight published-post count).
        $published = (int) wp_count_posts('post')->publish;
        if ($published > 0) {
            $used['published_posts'] = $published;
        }

        return $used;
    }
}
