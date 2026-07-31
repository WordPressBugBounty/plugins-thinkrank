<?php

/**
 * Setup Wizard Controller
 *
 * Registers the onboarding Setup Wizard admin page, handles the post-activation
 * redirect, the conditional "resume" submenu, and the React app enqueue.
 *
 * The wizard is a full-screen standalone React experience (its own webpack entry
 * `setup-wizard`) rendered into `#thinkrank-setup-wizard`. Reaching the final step
 * marks the wizard completed, after which the page is no longer accessible.
 *
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\Core\Settings;
use ThinkRank\Admin\Importers\Import_Detector;
use ThinkRank\SEO\Sitemap_Generator;
use ThinkRank\Integrations\Google_OAuth_Proxy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup Wizard Controller
 *
 * Single Responsibility: wire up the Setup Wizard admin page and its lifecycle.
 *
 * @since 1.0.0
 */
class Setup_Wizard {

    /**
     * Admin page slug.
     */
    public const PAGE_SLUG = 'thinkrank_setup_wizard';

    /**
     * Option flag: wizard completed.
     */
    public const OPT_COMPLETED = 'thinkrank_setup_wizard_completed';

    /**
     * Option: last viewed step (1-based), used to resume.
     */
    public const OPT_STEP = 'thinkrank_setup_wizard_step';

    /**
     * Option: list of source-plugin slugs already migrated from inside the
     * wizard. Used to disable the Import button and show an "Imported" status so
     * the same plugin can't be imported twice during onboarding. This is wizard-
     * only — the standalone Migration page ignores it and always allows re-runs.
     *
     * @var string
     */
    public const OPT_MIGRATED_PLUGINS = 'thinkrank_setup_wizard_migrated_plugins';

    /**
     * Option flag: the user clicked "Get Started" on the Start step.
     *
     * Once set, the Start step is permanently dismissed — future visits resume
     * from saved progress (or Migration if progress was wiped), never Start.
     * Clicking "Skip" on the Start step deliberately does NOT set this, so the
     * Start step can be shown again on subsequent visits.
     */
    public const OPT_STARTED = 'thinkrank_setup_wizard_started';

    /**
     * 1-based index of the Start step.
     */
    public const STEP_START = 1;

    /**
     * 1-based index of the Migration step — the earliest step shown once the
     * Start step has been dismissed via "Get Started".
     */
    public const STEP_MIGRATION = 2;

    /**
     * Total number of wizard steps.
     */
    public const TOTAL_STEPS = 8;

    /**
     * Ordered step slugs (must match the React STEPS config).
     *
     * @var string[]
     */
    private const STEPS = ['start', 'migration', 'site-setup', 'mcp', 'analytics', 'ecosystem', 'help', 'ready'];

    /**
     * Captured page hook suffix for the hidden wizard page.
     *
     * @var string
     */
    private string $page_hook = '';

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init(): void {
        // Priority 20 so the parent `thinkrank` menu (registered at default 10) exists.
        add_action('admin_menu', [$this, 'register_menu'], 20);
        // Late on admin_menu (priority 999) so it runs after register_menu but before
        // WordPress' page-access check (which happens when wp-admin/menu.php finishes,
        // before admin_init) — letting completed users bounce cleanly to the dashboard.
        add_action('admin_menu', [$this, 'maybe_redirect_completed'], 999);
        add_action('admin_init', [$this, 'maybe_redirect_on_activation']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('admin_body_class', [$this, 'add_body_class']);
        // Import detection is cached for an hour; activating or deactivating a
        // source SEO plugin changes what's migratable, so flush the cache to
        // keep the Migration step's visibility (and its rows) in sync.
        add_action('activated_plugin', [$this, 'flush_import_detection_cache']);
        add_action('deactivated_plugin', [$this, 'flush_import_detection_cache']);
        // Keep the onboarding screen clean — suppress unrelated admin notices.
        add_action('in_admin_header', [$this, 'suppress_admin_notices'], 1);
    }

    /**
     * Remove unrelated admin notices on the wizard screen for a clean onboarding.
     *
     * @return void
     */
    public function suppress_admin_notices(): void {
        if (!$this->is_wizard_screen()) {
            return;
        }
        remove_all_actions('user_admin_notices');
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    /**
     * Whether the wizard has been completed.
     *
     * @return bool
     */
    private function is_completed(): bool {
        return (bool) get_option(self::OPT_COMPLETED, false);
    }

    /**
     * Whether the user has dismissed the Start step via "Get Started".
     *
     * @return bool
     */
    private function is_started(): bool {
        return (bool) get_option(self::OPT_STARTED, false);
    }

    /**
     * Resolve the step the wizard should open on.
     *
     * Before "Get Started" the Start step is shown. Once "Get Started" has been
     * used it is permanently dismissed: the wizard resumes from the saved step,
     * clamped so it can never fall back to Start — even if the progress option
     * was manually deleted, in which case it begins at Migration.
     *
     * @param bool $started Whether the Start step has been dismissed.
     * @return int 1-based step index.
     */
    public static function resolve_current_step(bool $started): int {
        if (!$started) {
            return self::STEP_START;
        }

        $step = (int) get_option(self::OPT_STEP, self::STEP_MIGRATION);

        return max(self::STEP_MIGRATION, min(self::TOTAL_STEPS, $step));
    }

    /**
     * Register the wizard page.
     *
     * The page is registered once under the ThinkRank menu (stable page hook).
     * While the wizard is incomplete it appears as a visible "Setup Wizard"
     * submenu so the user can resume. Once completed, the menu entry is removed
     * but the page stays registered so direct hits still redirect away cleanly.
     *
     * @return void
     */
    public function register_menu(): void {
        $this->page_hook = (string) add_submenu_page(
            'thinkrank',
            __('Setup Wizard', 'thinkrank'),
            __('Setup Wizard', 'thinkrank'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );

        // Hide the menu entry once completed (page remains addressable).
        if ($this->is_completed()) {
            remove_submenu_page('thinkrank', self::PAGE_SLUG);
        }
    }

    /**
     * One-time redirect to the wizard right after activation.
     *
     * @return void
     */
    public function maybe_redirect_on_activation(): void {
        if (!get_transient('thinkrank_setup_wizard_redirect')) {
            return;
        }

        // Consume the flag regardless of whether we redirect.
        delete_transient('thinkrank_setup_wizard_redirect');

        // Never redirect during AJAX, bulk/network activation, for non-admins,
        // or once the wizard is already complete.
        if (wp_doing_ajax() || !current_user_can('manage_options') || $this->is_completed()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only guard against the bulk-activation screen
        if (isset($_GET['activate-multi'])) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Once completed, bounce any direct hit on the wizard page to the dashboard.
     *
     * Runs late on admin_menu (before WordPress' page-access check in menu.php),
     * so it works even though the menu entry has been removed.
     *
     * @return void
     */
    public function maybe_redirect_completed(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation guard, no state change
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }
        if ($this->is_completed() && current_user_can('manage_options')) {
            wp_safe_redirect(admin_url('admin.php?page=thinkrank'));
            exit;
        }
    }

    /**
     * Render the wizard page container.
     *
     * Guards run here too (defense in depth) because the page is directly
     * addressable by URL.
     *
     * @return void
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'thinkrank'));
        }

        // Once completed the wizard is no longer accessible.
        if ($this->is_completed()) {
            wp_safe_redirect(admin_url('admin.php?page=thinkrank'));
            exit;
        }
        ?>
        <div id="thinkrank-setup-wizard" class="thinkrank-wizard-root">
            <div class="thinkrank-wizard-loading">
                <img src="<?php echo esc_url(THINKRANK_PLUGIN_URL . 'static/svg/spinner.svg'); ?>" alt="" aria-hidden="true" class="thinkrank-svg-spinner thinkrank-animate-spin" style="width:48px;height:48px;">
                <p><?php esc_html_e('Loading Setup Wizard…', 'thinkrank'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Add a body class on the wizard page so styles can take over the screen.
     *
     * @param string $classes Existing body classes.
     * @return string
     */
    public function add_body_class(string $classes): string {
        if ($this->is_wizard_screen()) {
            $classes .= ' thinkrank-setup-wizard-active';
        }
        return $classes;
    }

    /**
     * Enqueue the wizard bundle (separate from the main admin bundle).
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public function enqueue(string $hook_suffix): void {
        if ($hook_suffix !== $this->page_hook || $this->page_hook === '') {
            return;
        }

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/setup-wizard.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
            'version'      => THINKRANK_VERSION,
        ];

        wp_enqueue_script(
            'thinkrank-setup-wizard',
            THINKRANK_PLUGIN_URL . 'assets/setup-wizard.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'thinkrank-setup-wizard',
            THINKRANK_PLUGIN_URL . 'assets/setup-wizard.css',
            ['wp-components'],
            $asset['version']
        );

        // Media library for the logo / social image pickers (step 3).
        wp_enqueue_media();

        $started      = $this->is_started();
        $current_step = self::resolve_current_step($started);

        // The Migration step is only shown when there is at least one active
        // source plugin with data to migrate. Detection is transient-cached, so
        // this is cheap. Passing it up-front lets the stepper renumber the
        // visible steps deterministically on first paint (no async reflow).
        $migration_available = !empty((new Import_Detector())->detect());

        // Plugins already migrated inside the wizard — persisted so a completed
        // import still shows as "Imported" (button disabled) after a refresh or
        // when the user navigates back to the Migration step.
        $migrated_plugins = get_option(self::OPT_MIGRATED_PLUGINS, []);
        $migrated_plugins = is_array($migrated_plugins) ? array_values($migrated_plugins) : [];

        wp_localize_script('thinkrank-setup-wizard', 'thinkrankWizard', [
            'apiUrl'         => rest_url('thinkrank/v1/'),
            'restNonce'      => wp_create_nonce('wp_rest'),
            'currentStep'    => $current_step,
            'started'        => $started,
            'migrationAvailable' => $migration_available,
            'migratedPlugins'    => $migrated_plugins,
            'totalSteps'     => self::TOTAL_STEPS,
            'steps'          => self::STEPS,
            'completed'      => $this->is_completed(),
            'dashboardUrl'   => admin_url('admin.php?page=thinkrank'),
            'completeUrl'    => admin_url('admin.php?page=thinkrank'),
            'sitemapUrl'     => (new Sitemap_Generator())->get_primary_sitemap_url(),
            'googleConnected' => (bool) Settings::instance()->get('google_account_connected', false),
            // The wizard is its own entry point and never gets `thinkrankAdmin`,
            // so the shared ConnectGoogleButton would find no connect URL here
            // and refuse to start the flow. The return URL points back at the
            // wizard (the persisted step brings the user to Analytics again)
            // instead of the Google Services screen, which would abandon setup.
            'googleOAuth' => [
                'connectUrl' => Google_OAuth_Proxy::get_connect_url(
                    admin_url('admin.php?page=' . self::PAGE_SLUG)
                ),
                'reconnectReason' => (string) get_option('thinkrank_google_reconnect_required', ''),
            ],
            'activeEcosystem' => $this->get_active_ecosystem_slugs(),
            'siteName'       => get_bloginfo('name'),
            'siteUrl'        => home_url(),
        ]);
    }

    /**
     * Wizard slugs whose mapped WordPress.org plugin is installed AND active.
     *
     * The Ecosystem step uses this to auto-check the plugins the user already
     * runs, so the checkbox state reflects real activation status on first paint.
     * The wizard-slug → wp.org-slug map is owned by the endpoint that performs
     * the installs, so both sides stay in sync from a single source.
     *
     * @return string[] Wizard slugs (keys of the ecosystem map) currently active.
     */
    private function get_active_ecosystem_slugs(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $installed = get_plugins();
        $active    = [];

        foreach (\ThinkRank\API\Setup_Wizard_Endpoint::ECOSYSTEM_PLUGINS as $wizard_slug => $wporg_slug) {
            foreach (array_keys($installed) as $plugin_file) {
                if (strpos((string) $plugin_file, $wporg_slug . '/') === 0
                    && is_plugin_active($plugin_file)
                ) {
                    $active[] = $wizard_slug;
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * Flush the import-detection cache when a plugin is (de)activated.
     *
     * @return void
     */
    public function flush_import_detection_cache(): void {
        (new Import_Detector())->clear_cache();
    }

    /**
     * Whether the current screen is the wizard page.
     *
     * @return bool
     */
    private function is_wizard_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && $this->page_hook !== '' && $screen->id === $this->page_hook;
    }
}
