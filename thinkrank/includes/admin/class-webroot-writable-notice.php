<?php
/**
 * Web Root Writability Notice
 *
 * Tells the site owner when the WordPress root cannot be written to, which is
 * what stops robots.txt, llms.txt and the Instant Indexing key file from being
 * published.
 *
 * @package ThinkRank\Admin
 * @since 2.9.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Web Root Writable Notice Class
 *
 * Single Responsibility: surface an unwritable WordPress root to the people who
 * can do something about it.
 *
 * Activation already ran this exact check and threw the answer away: the result
 * only reached `error_log()`, and only when `WP_DEBUG` was on, so on a normal
 * production site nobody was told. Every feature that publishes a file to the
 * web root then failed later with a message describing the symptom rather than
 * the cause, which is how this reached support as "sitemap generation is
 * broken" (#753).
 *
 * The condition is re-evaluated live rather than read from a flag stored at
 * activation: permissions change under a site without anyone reactivating the
 * plugin, in both directions.
 *
 * @since 2.9.0
 */
class Webroot_Writable_Notice {

    /**
     * Option flag storing the dismissal.
     *
     * Cleared whenever the root becomes writable again, so a site that breaks a
     * second time is warned a second time instead of staying silenced forever.
     *
     * @var string
     */
    public const OPT_DISMISSED = 'thinkrank_webroot_writable_dismissed';

    /**
     * Option recording what the activation-time check saw.
     *
     * Not the source of truth for the notice — {@see self::root_is_writable()}
     * is — but it lets support tell "never worked" apart from "worked until the
     * host changed something".
     *
     * @var string
     */
    public const OPT_ACTIVATION_STATE = 'thinkrank_webroot_writable_at_activation';

    /**
     * Site Health test identifier.
     *
     * @var string
     */
    private const HEALTH_TEST = 'thinkrank_webroot_writable';

    /**
     * Nonce action for the dismiss request.
     *
     * @var string
     */
    private const NONCE_ACTION = 'thinkrank_webroot_writable_notice';

    /**
     * Initialize the notice and the Site Health test.
     *
     * Hooks both `admin_notices` and `thinkrank_admin_notices` for the reason
     * {@see Search_Visibility_Notice::init()} documents: Manager
     * ::remove_admin_notice() strips every `admin_notices` callback on
     * ThinkRank's own screens and re-fires `thinkrank_admin_notices` instead.
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_notices', [$this, 'render']);
        add_action('thinkrank_admin_notices', [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_thinkrank_dismiss_webroot_writable', [$this, 'ajax_dismiss']);

        add_filter('site_status_tests', [$this, 'register_health_test']);

        // A site that was fixed should be warned again if it breaks a second
        // time, so the dismissal is cleared the moment the condition clears.
        // Only ever a delete_option() on an already-good site, so it costs
        // nothing on the path that matters.
        add_action('admin_init', [$this, 'reset_dismissal']);
    }

    /**
     * Is the WordPress root writable by the process serving this request?
     *
     * `wp_is_writable()` rather than `is_writable()`: on Windows the latter
     * reports a directory writable that a write then fails on, which is the
     * whole failure mode this notice exists to name.
     *
     * @since 2.9.0
     *
     * @return bool
     */
    public static function root_is_writable(): bool {
        return wp_is_writable(ABSPATH);
    }

    /**
     * Features that stop working when the root cannot be written.
     *
     * Kept in one place so the notice and the Site Health test cannot drift.
     *
     * @since 2.9.0
     *
     * @return string[]
     */
    private static function affected_features(): array {
        return [
            __('robots.txt', 'thinkrank'),
            __('llms.txt', 'thinkrank'),
            __('the Instant Indexing key file', 'thinkrank'),
        ];
    }

    /**
     * Is the XML sitemap genuinely unharmed by the unwritable root?
     *
     * Only when delivery resolves to dynamic. On `auto` — the default — an
     * unwritable root resolves that way by itself, so the reassurance is
     * normally true. It stops being true the moment someone explicitly picks
     * "write files", and stating it unconditionally told those users to ignore
     * a notice that was in fact reporting a broken sitemap.
     *
     * @since 2.9.0
     *
     * @return bool True when the sitemap is served from PHP and needs no file.
     */
    private static function sitemap_is_unaffected(): bool {
        if (!class_exists('ThinkRank\\SEO\\Sitemap_Generator')) {
            return true;
        }

        return 'dynamic' === (new \ThinkRank\SEO\Sitemap_Generator(false))->resolve_delivery_mode();
    }

    /**
     * Whether the notice should render on this request.
     *
     * @return bool
     */
    private function should_display(): bool {
        if (self::root_is_writable()) {
            return false;
        }

        // Only users who can act on it (or ask the host to) are shown the
        // warning.
        if (!current_user_can('manage_options')) {
            return false;
        }

        return !get_option(self::OPT_DISMISSED);
    }

    /**
     * Load the shared notice stylesheet when the notice will render.
     *
     * @return void
     */
    public function enqueue_assets(): void {
        if (!$this->should_display()) {
            return;
        }

        wp_enqueue_style(
            'thinkrank-admin-notices',
            THINKRANK_PLUGIN_URL . 'static/css/admin-notices.css',
            [],
            THINKRANK_VERSION
        );
    }

    /**
     * Render the notice.
     *
     * @return void
     */
    public function render(): void {
        if (!$this->should_display()) {
            return;
        }

        ?>
        <div class="notice notice-warning is-dismissible thinkrank-notice thinkrank-webroot-writable-notice">
            <div class="thinkrank-notice__inner">
                <div class="thinkrank-notice__body">
                    <p class="thinkrank-notice__title"><?php esc_html_e('ThinkRank cannot write to your WordPress folder', 'thinkrank'); ?></p>
                    <p class="thinkrank-notice__text">
                        <?php
                        printf(
                            /* translators: 1: absolute path to the WordPress root, 2: comma-separated list of affected features. */
                            esc_html__('The folder %1$s is not writable by PHP, so ThinkRank cannot publish %2$s. Ask your host to make the WordPress root writable by the web server user.', 'thinkrank'),
                            '<code>' . esc_html(untrailingslashit(ABSPATH)) . '</code>',
                            esc_html(implode(', ', self::affected_features()))
                        );
                        ?>
                    </p>
                    <?php if (self::sitemap_is_unaffected()) : ?>
                    <p class="thinkrank-notice__text">
                        <?php esc_html_e('Your XML sitemap is not affected: ThinkRank serves it directly when the folder is not writable.', 'thinkrank'); ?>
                    </p>
                    <?php else : ?>
                    <p class="thinkrank-notice__text">
                        <?php esc_html_e('Your XML sitemap is affected too: sitemap delivery is set to write files, and those files cannot be written. Set sitemap delivery to automatic so WordPress serves the sitemap directly, or make the folder writable.', 'thinkrank'); ?>
                    </p>
                    <?php endif; ?>
                    <p class="thinkrank-notice__actions">
                        <a href="<?php echo esc_url(admin_url('site-health.php')); ?>" class="button button-primary">
                            <?php esc_html_e('Check Site Health', 'thinkrank'); ?>
                        </a>
                        <a href="#" class="thinkrank-notice__dismiss thinkrank-dismiss-webroot-writable" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
                            <?php esc_html_e('Dismiss', 'thinkrank'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
        // Same reasoning as Search_Visibility_Notice: this renders on every
        // admin screen, so the dismiss handler ships with it rather than in the
        // thinkrank-admin bundle, which only loads on ThinkRank pages.
        wp_print_inline_script_tag(
            '( function () {
                document.addEventListener( "click", function ( event ) {
                    var notice = event.target.closest( ".thinkrank-webroot-writable-notice" );
                    if ( ! notice ) {
                        return;
                    }
                    var link = event.target.closest( ".thinkrank-dismiss-webroot-writable" );
                    if ( ! link && ! event.target.closest( ".notice-dismiss" ) ) {
                        return;
                    }
                    if ( link ) {
                        event.preventDefault();
                        notice.style.display = "none";
                    }
                    window.fetch( window.ajaxurl, {
                        method: "POST",
                        credentials: "same-origin",
                        body: new URLSearchParams( {
                            action: "thinkrank_dismiss_webroot_writable",
                            nonce: notice.querySelector( ".thinkrank-dismiss-webroot-writable" ).dataset.nonce,
                        } ),
                    } );
                } );
            } )();'
        );
    }

    /**
     * AJAX handler persisting the dismissal.
     *
     * @return void
     */
    public function ajax_dismiss(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        // The nonce proves intent, not authorization — dismissing a site-wide
        // notice writes an option, so require the same capability that renders
        // it.
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        update_option(self::OPT_DISMISSED, 1, true);

        wp_send_json_success();
    }

    /**
     * Register the Site Health test.
     *
     * Direct rather than async: the check is a single stat() call, so there is
     * nothing to gain from a second request.
     *
     * @since 2.9.0
     *
     * No return type: Site Health hands this filter whatever earlier callbacks
     * returned, and a non-array means something upstream is misbehaving.
     * Replacing it with our own array would silently drop every other plugin's
     * tests, so it is passed through exactly as received.
     *
     * @param array $tests Registered Site Health tests.
     * @return array|mixed
     */
    public function register_health_test($tests) {
        if (!is_array($tests)) {
            return $tests;
        }

        $tests['direct'][self::HEALTH_TEST] = [
            'label' => __('ThinkRank can publish files to your WordPress folder', 'thinkrank'),
            'test'  => [$this, 'run_health_test'],
        ];

        return $tests;
    }

    /**
     * Site Health test body.
     *
     * @since 2.9.0
     *
     * @return array Site Health result array.
     */
    public function run_health_test(): array {
        $result = [
            'label'       => __('ThinkRank can publish files to your WordPress folder', 'thinkrank'),
            'status'      => 'good',
            'badge'       => [
                'label' => __('SEO', 'thinkrank'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html__('ThinkRank can write to the WordPress root, so robots.txt, llms.txt and the Instant Indexing key file can be published.', 'thinkrank') . '</p>',
            'actions'     => '',
            'test'        => self::HEALTH_TEST,
        ];

        if (self::root_is_writable()) {
            return $result;
        }

        $result['status'] = 'recommended';
        $result['label']  = __('ThinkRank cannot publish files to your WordPress folder', 'thinkrank');

        $description = '<p>' . sprintf(
            /* translators: 1: absolute path to the WordPress root, 2: comma-separated list of affected features. */
            esc_html__('The folder %1$s is not writable by PHP, so ThinkRank cannot publish %2$s.', 'thinkrank'),
            '<code>' . esc_html(untrailingslashit(ABSPATH)) . '</code>',
            esc_html(implode(', ', self::affected_features()))
        ) . '</p>';

        $description .= self::sitemap_is_unaffected()
            ? '<p>' . esc_html__('Your XML sitemap is not affected. ThinkRank detects this and serves the sitemap directly instead of writing it to a file.', 'thinkrank') . '</p>'
            : '<p>' . esc_html__('Your XML sitemap is affected too. Sitemap delivery is set to write files, and those files cannot be written. Set sitemap delivery to automatic so WordPress serves the sitemap directly, or make the folder writable.', 'thinkrank') . '</p>';

        // Named explicitly because both are the usual first guesses and neither
        // has any effect here: the write fails on the root directory itself,
        // and get_filesystem_method() still reports "direct" because with no
        // context argument it tests wp-content, not the root.
        $description .= '<p>' . esc_html__('Adding FS_METHOD or FTP credentials to wp-config.php will not resolve this. Ask your host to make the WordPress root writable by the web server user.', 'thinkrank') . '</p>';

        if (get_option(self::OPT_ACTIVATION_STATE) === 'writable') {
            $description .= '<p>' . esc_html__('This folder was writable when ThinkRank was activated, so something on the hosting side changed since then.', 'thinkrank') . '</p>';
        }

        $result['description'] = $description;

        return $result;
    }

    /**
     * Clear the dismissal once the root becomes writable again.
     *
     * Called from the Site Health test and the notice path is cheap, so this
     * runs wherever the condition is evaluated rather than on a schedule.
     *
     * @since 2.9.0
     *
     * @return void
     */
    public function reset_dismissal(): void {
        if (self::root_is_writable()) {
            delete_option(self::OPT_DISMISSED);
        }
    }
}
