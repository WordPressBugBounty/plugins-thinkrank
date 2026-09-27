<?php
/**
 * Web Root Writability Notice
 *
 * Tells the site owner when the WordPress root cannot be written to and a
 * feature is explicitly set to publish files there, which is the one case that
 * stops it from being delivered (#756).
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
     * Features that genuinely stop working when the root cannot be written.
     *
     * Derived from what is actually unavailable rather than from a fixed list.
     * The fixed list named robots.txt, llms.txt and the Instant Indexing key
     * file, and on a read-only root all three still work — each has a PHP path
     * that answers the request:
     *
     *  - robots.txt through the `robots_txt` filter. Core only runs do_robots()
     *    when no physical file exists, so an unwritable root is precisely the
     *    case where the filter does answer. The file is an optional extra.
     *  - the Instant Indexing key through `maybe_serve_key_file()` on
     *    `parse_request` when no file exists (#243 / #247).
     *  - llms.txt through `serve_llms_txt()`, whose `auto` mode now resolves to
     *    dynamic on an unwritable root (#756).
     *
     * That mattered because on a managed host such as Flywheel, ABSPATH is the
     * locked core folder (`/www/.wordpress/`) while the document root (`/www`)
     * is writable — so the notice fired permanently, naming three features that
     * were working, and told the user to ask the host to change something the
     * host locks by design.
     *
     * What remains genuinely broken is a feature explicitly set to write files
     * on a root that cannot be written. `auto` never lands there any more.
     *
     * Kept in one place so the notice and the Site Health test cannot drift.
     *
     * @since 2.9.0
     * @since 2.10.0 Reports only what is actually unavailable (#756).
     *
     * @return string[] Human labels of features that cannot be delivered.
     */
    private static function affected_features(): array {
        return self::labels_for(self::feature_states()['affected']);
    }

    /**
     * Features that keep working on the unwritable root, for the reassurance.
     *
     * The complement of {@see self::affected_features()}, derived rather than
     * written out. The sentence used to be a fixed string naming robots.txt,
     * llms.txt and the Instant Indexing key, so with llms.txt forced to write
     * files the notice said "ThinkRank cannot publish llms.txt" and, one line
     * later, that llms.txt was unaffected and kept working.
     *
     * A feature that is switched off is in neither list: it publishes nothing,
     * so it is not failing, but "ThinkRank serves it from WordPress" would not
     * be true of it either.
     *
     * @since 2.10.0
     *
     * @return string[] Human labels of features still being delivered.
     */
    private static function unaffected_features(): array {
        return self::labels_for(self::feature_states()['unaffected']);
    }

    /**
     * Every feature that is switched on, whatever its delivery.
     *
     * For the writable-root pass, where nothing is failing and the question is
     * only what the folder is used for.
     *
     * @since 2.10.0
     *
     * @return string[] Human labels.
     */
    private static function enabled_features(): array {
        $states = self::feature_states();

        return self::labels_for(array_merge($states['affected'], $states['unaffected']));
    }

    /**
     * Join feature labels into a readable, localised list.
     *
     * wp_sprintf_l() rather than implode(): the lists are now built at run
     * time, and "robots.txt, llms.txt, the Instant Indexing key" with no
     * conjunction read as a sentence that had been cut short.
     *
     * @since 2.10.0
     *
     * @param string[] $labels Human labels.
     * @return string
     */
    private static function list_text(array $labels): string {
        return wp_sprintf_l('%l', $labels);
    }

    /**
     * Human labels for a set of feature keys, in feature_labels() order.
     *
     * @since 2.10.0
     *
     * @param string[] $keys Feature keys.
     * @return string[]
     */
    private static function labels_for(array $keys): array {
        return array_values(array_intersect_key(self::feature_labels(), array_flip($keys)));
    }

    /**
     * Every file-backed feature this class reports on, keyed for the lists.
     *
     * The one place the feature names are written, so the failure sentence,
     * the reassurance and the Site Health pass text cannot name different sets.
     *
     * @since 2.10.0
     *
     * @return array<string,string> Feature key => human label.
     */
    private static function feature_labels(): array {
        return [
            'robots'   => __('robots.txt', 'thinkrank'),
            'llms'     => __('llms.txt', 'thinkrank'),
            'indexnow' => __('the Instant Indexing key', 'thinkrank'),
            'sitemap'  => __('the XML sitemap', 'thinkrank'),
        ];
    }

    /**
     * Sort every feature into affected, unaffected, or switched off.
     *
     * robots.txt and the Instant Indexing key always have a PHP path, so they
     * are never affected (see {@see self::affected_features()}) and have no
     * delivery setting that could make them so.
     *
     * @since 2.10.0
     *
     * @return array{affected: string[], unaffected: string[]} Feature keys.
     */
    private static function feature_states(): array {
        $states = [
            'affected'   => [],
            'unaffected' => ['robots', 'indexnow'],
        ];

        $llms = self::delivery_state('ThinkRank\\SEO\\LLMs_Txt_Manager');
        if (null !== $llms) {
            $states[$llms][] = 'llms';
        }

        $sitemap = self::sitemap_state();
        if (null !== $sitemap) {
            $states[$sitemap][] = 'sitemap';
        }

        return $states;
    }

    /**
     * Is this manager's delivery explicitly set to write files?
     *
     * Only an explicit `static` counts. `auto` resolving to static means the
     * root IS writable, in which case none of this applies.
     *
     * @since 2.10.0
     * @since 2.10.0 Returns the feature's state rather than a bool, so a
     *               switched-off feature can be left out of both lists.
     *
     * @param string $manager_class Fully-qualified manager class name.
     * @return string|null 'affected', 'unaffected', or null when switched off.
     */
    private static function delivery_state(string $manager_class): ?string {
        if (!class_exists($manager_class)) {
            return null;
        }

        $manager  = new $manager_class();
        $settings = $manager->get_settings('site');

        if (empty($settings['enabled'])) {
            // A feature that is switched off publishes nothing, so it cannot be
            // failing to publish.
            return null;
        }

        return 'static' === (string) ($settings['delivery_mode'] ?? 'auto') ? 'affected' : 'unaffected';
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
     * @since 2.10.0 Returns the state rather than a bool; see delivery_state().
     *
     * @return string|null 'affected', 'unaffected', or null when switched off.
     */
    private static function sitemap_state(): ?string {
        if (!class_exists('ThinkRank\\SEO\\Sitemap_Generator')) {
            return null;
        }

        $sitemap = new \ThinkRank\SEO\Sitemap_Generator(false);

        // Same rule delivery_state() applies to llms.txt: a feature that is
        // switched off publishes nothing, so it cannot be failing to publish.
        // Without this a site with the sitemap disabled and a stale
        // `delivery_mode` of `static` gets the permanent notice back, which is
        // the bug this class was rewritten to stop (#756).
        if (empty($sitemap->get_settings('site')['enabled'])) {
            return null;
        }

        return 'dynamic' === $sitemap->resolve_delivery_mode() ? 'unaffected' : 'affected';
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

        // An unwritable root is not itself a problem. Every file feature has a
        // PHP path, and `auto` uses it, so there is nothing to report unless a
        // feature is explicitly set to write files. Warning regardless is what
        // made this permanent on hosts that lock the core folder by design and
        // will not be unlocking it (#756).
        if (empty(self::affected_features())) {
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
                            /* translators: 1: list of affected features, 2: absolute path to the WordPress root. */
                            esc_html__('ThinkRank cannot publish %1$s. Its delivery is set to write files, and the folder %2$s is not writable by PHP. Set delivery to Automatic and ThinkRank will serve it directly. Asking your host to make the folder writable also works, though some managed hosts lock it deliberately.', 'thinkrank'),
                            esc_html(self::list_text(self::affected_features())),
                            '<code>' . esc_html(untrailingslashit(ABSPATH)) . '</code>'
                        );
                        ?>
                    </p>
                    <p class="thinkrank-notice__text">
                        <?php
                        printf(
                            /* translators: %s: list of features that keep working. */
                            esc_html__('Everything else is unaffected. ThinkRank serves %s from WordPress when there is no file to read, so those keep working on a read-only folder.', 'thinkrank'),
                            esc_html(self::list_text(self::unaffected_features()))
                        );
                        ?>
                    </p>
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
            'description' => '<p>' . sprintf(
                /* translators: %s: list of features that can publish files. */
                esc_html__('ThinkRank can write to the WordPress root, so %s can be published as files.', 'thinkrank'),
                esc_html(self::list_text(self::enabled_features()))
            ) . '</p>',
            'actions'     => '',
            'test'        => self::HEALTH_TEST,
        ];

        if (self::root_is_writable()) {
            return $result;
        }

        // The root is read-only, but that alone is not a fault: every file
        // feature has a PHP path and `auto` uses it. Report a pass, and say so,
        // rather than a permanent "recommended" on hosts that lock the folder
        // by design (#756).
        if (empty(self::affected_features())) {
            $result['label']       = __('ThinkRank serves its files from WordPress', 'thinkrank');
            $result['description'] = '<p>' . sprintf(
                /* translators: 1: absolute path to the WordPress root, 2: list of features served from WordPress. */
                esc_html__('The folder %1$s is not writable by PHP, which is normal on managed hosts that keep the WordPress core folder read-only. Nothing is affected: ThinkRank serves %2$s directly from WordPress when it cannot write them to disk.', 'thinkrank'),
                '<code>' . esc_html(untrailingslashit(ABSPATH)) . '</code>',
                esc_html(self::list_text(self::unaffected_features()))
            ) . '</p>';

            return $result;
        }

        $result['status'] = 'recommended';
        $result['label']  = __('ThinkRank cannot publish files to your WordPress folder', 'thinkrank');

        $description = '<p>' . sprintf(
            /* translators: 1: list of affected features, 2: absolute path to the WordPress root. */
            esc_html__('ThinkRank cannot publish %1$s. Its delivery is set to write files, and the folder %2$s is not writable by PHP.', 'thinkrank'),
            esc_html(self::list_text(self::affected_features())),
            '<code>' . esc_html(untrailingslashit(ABSPATH)) . '</code>'
        ) . '</p>';

        // The feature-level remedy comes first: it is the one the user can
        // actually apply. Some managed hosts lock this folder deliberately, so
        // "ask your host" is the fallback, not the headline (#756).
        $description .= '<p>' . esc_html__('Set delivery to Automatic and ThinkRank will serve it directly from WordPress, with no file to write. Making the folder writable also works, though some managed hosts keep it read-only by design.', 'thinkrank') . '</p>';

        // Built from what is actually still working rather than written out:
        // the fixed sentence named llms.txt as unaffected directly under a
        // paragraph saying llms.txt could not be published.
        $description .= '<p>' . sprintf(
            /* translators: %s: list of features that keep working. */
            esc_html__('Everything else is unaffected: ThinkRank serves %s from WordPress when there is no file to read.', 'thinkrank'),
            esc_html(self::list_text(self::unaffected_features()))
        ) . '</p>';

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
