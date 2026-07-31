<?php
/**
 * Google Analytics Tracking Manager Class
 *
 * Handles GA4 tracking code injection, verification, and conflict detection.
 * Follows ThinkRank patterns for frontend integration and security standards.
 *
 * @package ThinkRank\Frontend
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Frontend;

use ThinkRank\Core\Settings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Analytics Tracking Manager Class
 *
 * Single Responsibility: Manage GA4 tracking code injection and verification
 * Following ThinkRank frontend patterns from SEO_Manager
 *
 * @since 1.0.0
 */
class Google_Analytics_Tracking_Manager {

    /**
     * Settings instance
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->settings = Settings::instance();
        $this->init();
    }

    /**
     * Initialize tracking manager
     * Following ThinkRank initialization patterns
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        // Always add the hook, but check conditions during injection
        add_action('wp_head', [$this, 'inject_ga4_tracking'], 1);
    }

    /**
     * Determine if tracking should be injected
     * Following ThinkRank conditional logic patterns
     *
     * @since 1.0.0
     * @return bool Whether to inject tracking
     */
    private function should_inject_tracking(): bool {
        // Don't inject in admin area
        if (is_admin()) {
            return false;
        }

        $measurement_id = $this->get_setting('ga4_measurement_id');
        $auto_inject = $this->get_setting('ga4_auto_inject');

        // Basic requirements
        if (empty($measurement_id) || !$auto_inject) {
            return false;
        }

        // Exclude admin users if configured
        $exclude_admin = (bool) $this->get_setting('ga4_exclude_admin');
        if ($exclude_admin && current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    /**
     * Inject GA4 tracking code
     * Following WordPress script enqueuing standards
     *
     * @since 1.0.0
     * @return void
     */
    public function inject_ga4_tracking(): void {
        // Check if we should inject tracking at runtime
        if (!$this->should_inject_tracking()) {
            return;
        }

        $measurement_id = $this->get_setting('ga4_measurement_id');

        if (empty($measurement_id)) {
            return;
        }

        // Sanitize and validate measurement ID
        $measurement_id = sanitize_text_field($measurement_id);

        if (!preg_match('/^G-[A-Z0-9]{10}$/i', $measurement_id)) {
            echo "<!-- Invalid GA4 Measurement ID format -->\n";
            return;
        }

        $this->enqueue_ga4_scripts($measurement_id);
    }

    /**
     * Enqueue GA4 tracking scripts using WordPress standards
     * Following WordPress script enqueuing patterns
     *
     * @since 1.0.0
     * @param string $measurement_id GA4 Measurement ID
     * @return void
     */
    private function enqueue_ga4_scripts(string $measurement_id): void {
        $anonymize_ip = (bool) $this->get_setting('ga4_anonymize_ip');

        // Enqueue external Google Analytics script
        wp_enqueue_script(
            'google-analytics-gtag',
            "https://www.googletagmanager.com/gtag/js?id=" . esc_attr($measurement_id),
            [],
            null, // Use null for external scripts to avoid version query parameter
            false // Load in head for proper GA4 initialization
        );

        // Add async attribute to the external script
        add_filter('script_loader_tag', [$this, 'add_async_attribute'], 10, 2);

        // Generate inline configuration script
        $config_options = [];
        if ($anonymize_ip) {
            $config_options[] = "'anonymize_ip': true";
        }
        $config_string = !empty($config_options) ? ', {' . implode(', ', $config_options) . '}' : '';

        $inline_script = "
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '" . esc_js($measurement_id) . "'" . $config_string . ");
";

        // Add inline script after the external script
        wp_add_inline_script('google-analytics-gtag', $inline_script);

        // Add HTML comment for identification
        add_action('wp_head', function() use ($measurement_id) {
            echo "<!-- ThinkRank SEO: Google Analytics 4 Tracking (Measurement ID: " . esc_attr($measurement_id) . ") -->\n";
        }, 0);
    }

    /**
     * Add async attribute to Google Analytics script
     * Following WordPress script attribute patterns
     *
     * @since 1.0.0
     * @param string $tag Script tag HTML
     * @param string $handle Script handle
     * @return string Modified script tag
     */
    public function add_async_attribute(string $tag, string $handle): string {
        if ('google-analytics-gtag' === $handle && strpos($tag, ' async') === false) {
            return preg_replace('/(<script\b)/i', '$1 async', $tag, 1);
        }
        return $tag;
    }









    /**
     * Verify tracking is working
     * Following ThinkRank verification patterns
     *
     * @since 1.0.0
     * @param string $measurement_id GA4 Measurement ID to verify
     * @return array Verification results
     */
    public function verify_tracking(string $measurement_id): array {
        // Sanitize and validate measurement ID
        $measurement_id = sanitize_text_field($measurement_id);

        if (!preg_match('/^G-[A-Z0-9]{10}$/i', $measurement_id)) {
            return [
                'success' => false,
                'message' => __('Invalid GA4 Measurement ID format. Expected format: G-XXXXXXXXXX', 'thinkrank')
            ];
        }

        // Check homepage for tracking code
        $home_url = home_url();
        $response = wp_remote_get($home_url, [
            'timeout' => 15,
            'user-agent' => 'ThinkRank/' . THINKRANK_VERSION . ' Verification Bot'
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: Error message from WordPress HTTP request */
                    __('Could not verify tracking: %s', 'thinkrank'),
                    $response->get_error_message()
                )
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d: HTTP status code number (e.g., 404, 500) */
                    __('Could not verify tracking: HTTP %d response', 'thinkrank'),
                    $status_code
                )
            ];
        }

        // Check for GA4 script presence
        $has_measurement_id = strpos($body, $measurement_id) !== false;
        $has_gtag = strpos($body, 'gtag') !== false;
        $has_ga4_script = strpos($body, 'googletagmanager.com/gtag/js') !== false;

        if ($has_measurement_id && $has_gtag && $has_ga4_script) {
            // Update verification status
            $this->settings->set('ga4_tracking_verified', true);
            $this->settings->set('ga4_last_verification', current_time('mysql'));

            return [
                'success' => true,
                'message' => __('✅ GA4 tracking code detected and working correctly!', 'thinkrank')
            ];
        }

        // Provide specific feedback
        if (!$has_ga4_script) {
            return [
                'success' => false,
                'message' => __('⚠️ GA4 script not detected. Please check your configuration or enable auto-inject.', 'thinkrank')
            ];
        }

        if (!$has_measurement_id) {
            return [
                'success' => false,
                'message' => __('⚠️ Measurement ID not found in tracking code. Please verify your Measurement ID.', 'thinkrank')
            ];
        }

        return [
            'success' => false,
            'message' => __('⚠️ GA4 tracking code not properly configured. Please check your setup.', 'thinkrank')
        ];
    }

    /**
     * Detect existing GA4 implementations
     * Following ThinkRank conflict detection patterns
     *
     * @since 1.0.0
     * @return array Array of detected conflicts
     */
    public function detect_existing_tracking(): array {
        $conflicts = [];

        // Check for common GA4 plugins
        $ga4_plugins = [
            'google-analytics-for-wordpress/googleanalytics.php' => 'MonsterInsights',
            'ga-google-analytics/ga-google-analytics.php' => 'GA Google Analytics',
            'google-analytics-dashboard-for-wp/gadwp.php' => 'ExactMetrics',
            'gtag/gtag.php' => 'Gtag Plugin',
            'google-site-kit/google-site-kit.php' => 'Site Kit by Google'
        ];

        foreach ($ga4_plugins as $plugin_file => $plugin_name) {
            if (is_plugin_active($plugin_file)) {
                $conflicts[] = [
                    'type' => 'plugin',
                    'name' => $plugin_name,
                    'file' => $plugin_file,
                    'recommendation' => 'disable_auto_inject'
                ];
            }
        }

        // Check for manual GA4 in active theme
        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        $theme_files = [
            get_template_directory() . '/header.php',
            get_template_directory() . '/functions.php'
        ];

        foreach ($theme_files as $file) {
            if ($wp_filesystem && $wp_filesystem->exists($file) && $wp_filesystem->is_readable($file)) {
                $content = $wp_filesystem->get_contents($file);
                if ($content && (strpos($content, 'gtag') !== false || strpos($content, 'G-') !== false)) {
                    $conflicts[] = [
                        'type' => 'theme',
                        'name' => get_template(),
                        'file' => basename($file),
                        'recommendation' => 'verify_manual'
                    ];
                }
            }
        }

        return $conflicts;
    }



    /**
     * Get setting value with proper fallback
     * Following ThinkRank settings access patterns
     *
     * @since 1.0.0
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    private function get_setting(string $key, $default = '') {
        return $this->settings->get($key, $default);
    }
}
