<?php

/**
 * Setup Wizard API Endpoint
 *
 * Thin state-only endpoint for the onboarding Setup Wizard. The actual SEO
 * settings saved by each step reuse the existing per-feature endpoints
 * (site-identity, sitemap, schema). This endpoint only tracks wizard progress
 * and completion.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\API\Traits\CSRF_Protection;
use ThinkRank\Admin\Setup_Wizard;
use ThinkRank\Core\SEO_Plugin_Detector;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Load CSRF Protection trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-csrf-protection.php';

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup Wizard API Endpoint Class
 *
 * @since 1.0.0
 */
class Setup_Wizard_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * API namespace.
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base.
     *
     * @var string
     */
    protected $rest_base = 'setup-wizard';

    /**
     * Allowlist mapping wizard plugin slugs to their WordPress.org directory slugs.
     *
     * Only plugins in this map can be installed by the Ecosystem step. User input
     * is never used to install an arbitrary slug.
     *
     * @var array<string, string>
     */
    const ECOSYSTEM_PLUGINS = [
        'schedulepress'    => 'wp-scheduled-posts',
        'betterdocs'       => 'betterdocs',
        'templately'       => 'templately',
        'essential-addons' => 'essential-addons-for-elementor-lite',
        'essential-blocks' => 'essential-blocks',
        'notificationx'    => 'notificationx',
        'better-payment'   => 'better-payment',
        'easyjobs'         => 'easyjobs',
        'betterlinks'      => 'betterlinks',
        'embedpress'       => 'embedpress',
    ];

    /**
     * Source-plugin slugs the wizard can record as migrated. Mirrors
     * Import_Controller::ALLOWED_PLUGINS so an arbitrary slug can never be stored.
     *
     * @var string[]
     */
    const MIGRATABLE_PLUGINS = ['yoast', 'rankmath', 'seopress', 'aioseo'];

    /**
     * Register API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/state',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_state'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migrated-site-data',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_migrated_site_data'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migrated-plugins',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'mark_plugin_migrated'],
                    'permission_callback' => [$this, 'check_admin_csrf_permissions'],
                    'args'                => [
                        'plugin' => [
                            'required'          => true,
                            'type'              => 'string',
                            'enum'              => self::MIGRATABLE_PLUGINS,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/deactivate-plugin',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'deactivate_migrated_plugin'],
                    'permission_callback' => [$this, 'check_deactivate_permissions'],
                    'args'                => [
                        'plugin' => [
                            'required'          => true,
                            'type'              => 'string',
                            'enum'              => self::MIGRATABLE_PLUGINS,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/step',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_step'],
                    'permission_callback' => [$this, 'check_admin_csrf_permissions'],
                    'args'                => [
                        'step' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'minimum'           => 1,
                            'maximum'           => Setup_Wizard::TOTAL_STEPS,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/install-plugins',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'install_plugins'],
                    'permission_callback' => [$this, 'check_install_permissions'],
                    'args'                => [
                        'slugs' => [
                            'required'    => true,
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/consent',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'grant_tracking_consent'],
                    'permission_callback' => [$this, 'check_admin_csrf_permissions'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/complete',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'complete'],
                    'permission_callback' => [$this, 'check_admin_csrf_permissions'],
                ],
            ]
        );
    }

    /**
     * Get wizard state.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_state(WP_REST_Request $request): WP_REST_Response {
        $started = (bool) get_option(Setup_Wizard::OPT_STARTED, false);

        return new WP_REST_Response([
            'success'      => true,
            'completed'    => (bool) get_option(Setup_Wizard::OPT_COMPLETED, false),
            'started'      => $started,
            'current_step' => Setup_Wizard::resolve_current_step($started),
            'total_steps'  => Setup_Wizard::TOTAL_STEPS,
        ], 200);
    }

    /**
     * Return SEO data migrated from another plugin, mapped to the Site Setup
     * step's form fields.
     *
     * The Migration step's import writes into ThinkRank's option-based stores
     * (e.g. {@see thinkrank_site_identity_settings}); this surfaces those values
     * so the Site Setup step can pre-fill them. Only keys with a migrated value
     * are returned, so the frontend never overrides a field with an empty value.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_migrated_site_data(WP_REST_Request $request): WP_REST_Response {
        $identity = get_option('thinkrank_site_identity_settings', []);
        $identity = is_array($identity) ? $identity : [];

        $settings = [];

        // The importer stores the brand/site name under `organization_name` and
        // the logo under `organization_logo`; map them onto the Site Setup form.
        if (!empty($identity['organization_name'])) {
            $settings['site_name'] = (string) $identity['organization_name'];
        }
        if (!empty($identity['organization_logo'])) {
            $settings['logo_url'] = (string) $identity['organization_logo'];
        }

        return new WP_REST_Response([
            'success'  => true,
            'settings' => $settings,
        ], 200);
    }

    /**
     * Record that a source plugin has been migrated from inside the wizard.
     *
     * Wizard-only: the standalone Migration page never calls this and is
     * unaffected, so a user can still deliberately re-run a migration there. The
     * stored list disables the Import button (and shows "Imported") for that
     * plugin so it can't be imported twice during onboarding. Idempotent.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function mark_plugin_migrated(WP_REST_Request $request): WP_REST_Response {
        $plugin = sanitize_key((string) $request->get_param('plugin'));

        $migrated = get_option(Setup_Wizard::OPT_MIGRATED_PLUGINS, []);
        $migrated = is_array($migrated) ? $migrated : [];

        if (!in_array($plugin, $migrated, true)) {
            $migrated[] = $plugin;
            update_option(Setup_Wizard::OPT_MIGRATED_PLUGINS, array_values($migrated));
        }

        return new WP_REST_Response([
            'success'          => true,
            'migrated_plugins' => array_values($migrated),
        ], 200);
    }

    /**
     * Deactivate a source SEO plugin whose data was migrated inside the wizard.
     *
     * Wizard-only: the standalone Migration page never calls this, so importing
     * there never deactivates anything. Invoked when the user leaves the
     * Migration step via "Continue" so a source plugin isn't left running
     * alongside ThinkRank (duplicate meta/sitemaps/schema cause conflicts).
     *
     * The plugin file(s) — including premium companions — are resolved
     * server-side from the slug via {@see SEO_Plugin_Detector}, so no arbitrary
     * plugin path is ever passed to deactivate_plugins(). Best-effort: an
     * already-inactive plugin is a no-op success; a plugin that resists
     * deactivation returns success=false so the wizard can warn but still
     * advance.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function deactivate_migrated_plugin(WP_REST_Request $request): WP_REST_Response {
        $plugin = sanitize_key((string) $request->get_param('plugin'));
        $name   = SEO_Plugin_Detector::get_plugin_name($plugin);
        $files  = SEO_Plugin_Detector::get_deactivatable_files($plugin);

        // Nothing active to deactivate (already off or never installed) — treat
        // as a successful no-op so the wizard doesn't warn needlessly.
        if (empty($files)) {
            return new WP_REST_Response([
                'success'     => true,
                'deactivated' => false,
                'plugin'      => $plugin,
                'name'        => $name,
            ], 200);
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($files);

        // Confirm the plugin actually went inactive; if something re-activated it
        // (must-use loader, another plugin), report the failure so the UI can
        // tell the user to deactivate it manually.
        $still_active = array_values(array_filter($files, 'is_plugin_active'));
        if (!empty($still_active)) {
            return new WP_REST_Response([
                'success'     => false,
                'deactivated' => false,
                'plugin'      => $plugin,
                'name'        => $name,
                'message'     => __('The plugin could not be deactivated automatically.', 'thinkrank'),
            ], 200);
        }

        return new WP_REST_Response([
            'success'     => true,
            'deactivated' => true,
            'plugin'      => $plugin,
            'name'        => $name,
        ], 200);
    }

    /**
     * Persist the current step (used to resume the wizard later).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function save_step(WP_REST_Request $request): WP_REST_Response {
        $step = (int) $request->get_param('step');
        $step = max(1, min(Setup_Wizard::TOTAL_STEPS, $step));

        update_option(Setup_Wizard::OPT_STEP, $step);

        return new WP_REST_Response([
            'success'      => true,
            'current_step' => $step,
        ], 200);
    }

    /**
     * Record usage-tracking consent.
     *
     * Triggered when the user clicks "Get Started" on the first wizard step.
     * Delegates to the usage tracker manager which flags tracking allowed,
     * schedules the cron and suppresses the opt-in notice. Idempotent.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function grant_tracking_consent(WP_REST_Request $request): WP_REST_Response {
        // "Get Started" permanently dismisses the Start step. Persist this before
        // anything else so a direct URL hit or refresh can never return to Start.
        update_option(Setup_Wizard::OPT_STARTED, true);

        $manager = function_exists('thinkrank') ? thinkrank()->get_component('usage_tracker') : null;

        if ($manager instanceof \ThinkRank\Core\Usage_Tracker_Manager) {
            $manager->grant_consent();
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Mark the wizard as completed.
     *
     * Idempotent: safe to call multiple times.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function complete(WP_REST_Request $request): WP_REST_Response {
        update_option(Setup_Wizard::OPT_COMPLETED, true);
        delete_option(Setup_Wizard::OPT_STEP);

        return new WP_REST_Response([
            'success'     => true,
            'redirect'    => admin_url('admin.php?page=thinkrank'),
            'sitemap_url' => $this->get_sitemap_url(),
        ], 200);
    }

    /**
     * URL of the sitemap the site currently publishes, if any.
     *
     * Resolved here rather than reused from the page-load config because the
     * wizard is a single page load: a migration on the Migration step can switch
     * the site to an index sitemap, which changes the filename. Empty when the
     * sitemap is disabled or no file was written, so the final step can hide the
     * "View Sitemap" link instead of pointing at a URL WordPress core answers
     * with its own wp-sitemap.xml.
     *
     * @return string Sitemap URL, or an empty string when none is published.
     */
    private function get_sitemap_url(): string {
        if (!class_exists('ThinkRank\\SEO\\Sitemap_Generator')) {
            return '';
        }

        $generator = new \ThinkRank\SEO\Sitemap_Generator();
        $settings  = $generator->get_settings('site');

        if (empty($settings['enabled']) || !$generator->primary_sitemap_file_exists($settings)) {
            return '';
        }

        return $generator->get_primary_sitemap_url($settings);
    }

    /**
     * Install (and activate) the selected ecosystem plugins from WordPress.org.
     *
     * Best-effort: each plugin is attempted independently and its outcome is
     * reported back. A single failure never aborts the others, so the wizard
     * can always advance.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function install_plugins(WP_REST_Request $request): WP_REST_Response {
        $requested = (array) $request->get_param('slugs');
        $results   = [];

        foreach ($requested as $wizard_slug) {
            $wizard_slug = sanitize_key((string) $wizard_slug);

            // Allowlist guard: silently drop anything we do not recognise.
            if (!isset(self::ECOSYSTEM_PLUGINS[$wizard_slug])) {
                continue;
            }

            $results[$wizard_slug] = $this->install_one_plugin(self::ECOSYSTEM_PLUGINS[$wizard_slug]);
        }

        return new WP_REST_Response([
            'success' => true,
            'results' => $results,
        ], 200);
    }

    /**
     * Install and activate a single WordPress.org plugin by its directory slug.
     *
     * @param string $wporg_slug WordPress.org plugin directory slug.
     * @return array{status: string, message?: string} Outcome for this plugin.
     */
    private function install_one_plugin(string $wporg_slug): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Already installed? Find its main file and only activate if needed.
        $plugin_file = $this->find_installed_plugin_file($wporg_slug);

        if (null === $plugin_file) {
            $api = plugins_api('plugin_information', [
                'slug'   => $wporg_slug,
                'fields' => ['sections' => false],
            ]);

            if (is_wp_error($api) || empty($api->download_link)) {
                return [
                    'status'  => 'error',
                    'message' => is_wp_error($api)
                        ? $api->get_error_message()
                        : __('Plugin not found on WordPress.org.', 'thinkrank'),
                ];
            }

            $skin     = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            $result   = $upgrader->install($api->download_link);

            if (is_wp_error($result)) {
                return ['status' => 'error', 'message' => $result->get_error_message()];
            }

            if (is_wp_error($skin->result)) {
                return ['status' => 'error', 'message' => $skin->result->get_error_message()];
            }

            if (!$result) {
                return [
                    'status'  => 'error',
                    'message' => __('Plugin could not be installed (filesystem permissions?).', 'thinkrank'),
                ];
            }

            $plugin_file = $upgrader->plugin_info();
        }

        if (empty($plugin_file)) {
            return ['status' => 'error', 'message' => __('Could not locate the installed plugin.', 'thinkrank')];
        }

        if (is_plugin_active($plugin_file)) {
            return ['status' => 'already_active'];
        }

        $activated = activate_plugin($plugin_file);

        if (is_wp_error($activated)) {
            return ['status' => 'installed', 'message' => $activated->get_error_message()];
        }

        return ['status' => 'activated'];
    }

    /**
     * Locate the main plugin file of an already-installed plugin by directory slug.
     *
     * @param string $wporg_slug WordPress.org plugin directory slug.
     * @return string|null Plugin file (e.g. "embedpress/embedpress.php") or null.
     */
    private function find_installed_plugin_file(string $wporg_slug): ?string {
        $installed = get_plugins();

        foreach (array_keys($installed) as $plugin_file) {
            if (strpos((string) $plugin_file, $wporg_slug . '/') === 0) {
                return $plugin_file;
            }
        }

        return null;
    }

    /**
     * Permission check for plugin installation.
     *
     * Requires both install and activate capabilities plus a valid nonce.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error
     */
    public function check_install_permissions(WP_REST_Request $request) {
        if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to install plugins.', 'thinkrank'),
                ['status' => 403]
            );
        }

        if (!$this->verify_request_nonce($request)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid security token. Please refresh the page and try again.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Permission check for deactivating a migrated source plugin.
     *
     * Requires the deactivate_plugins capability plus a valid nonce.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error
     */
    public function check_deactivate_permissions(WP_REST_Request $request) {
        if (!current_user_can('deactivate_plugins')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to deactivate plugins.', 'thinkrank'),
                ['status' => 403]
            );
        }

        if (!$this->verify_request_nonce($request)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid security token. Please refresh the page and try again.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Permission check for read operations.
     *
     * @return bool|WP_Error
     */
    public function check_permissions() {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this endpoint.', 'thinkrank'),
                ['status' => 403]
            );
        }
        return true;
    }

    /**
     * Permission check for state-changing wizard operations.
     *
     * These routes flip admin onboarding state and telemetry/tracking consent,
     * so they require the admin capability (matching the GET state routes) in
     * addition to CSRF verification — the shared edit_posts-level CSRF check let
     * lower roles (e.g. Author) toggle consent and onboarding state.
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_admin_csrf_permissions(WP_REST_Request $request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to perform this action.', 'thinkrank'),
                ['status' => 403]
            );
        }

        if (!$this->verify_request_nonce($request)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid security token. Please refresh the page and try again.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }
}
