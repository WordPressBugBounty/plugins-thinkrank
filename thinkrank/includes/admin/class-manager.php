<?php

/**
 * Admin Manager Class
 * 
 * Handles WordPress admin interface integration
 * 
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\Core\Settings;
use ThinkRank\Core\Database;
use ThinkRank\Core\Plan_Config;
use ThinkRank\Core\Capability_Manager;
use ThinkRank\Admin\Metabox_Manager;
use ThinkRank\Admin\Elementor_Metabox;
use ThinkRank\Admin\Oxygen_Metabox;
use ThinkRank\Admin\Divi_Metabox;
use ThinkRank\Admin\Bulk_Action_Manager;
use ThinkRank\Admin\Post_List_Filters;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Manager Class
 * 
 * Single Responsibility: Manage WordPress admin interface
 * 
 * @since 1.0.0
 */
class Manager {

    /**
     * Settings instance
     * 
     * @var Settings
     */
    private Settings $settings;

    /**
     * Database instance
     *
     * @var Database
     */
    private Database $database;

    /**
     * Metabox manager instance
     *
     * @var Metabox_Manager
     */
    private Metabox_Manager $metabox_manager;

    /**
     * Elementor editor metabox integration instance
     *
     * @var Elementor_Metabox
     */
    private Elementor_Metabox $elementor_metabox;

    /**
     * Oxygen / Breakdance editor metabox integration instance
     *
     * @var Oxygen_Metabox
     */
    private Oxygen_Metabox $oxygen_metabox;

    /**
     * Divi Visual Builder metabox integration instance
     *
     * @var Divi_Metabox
     */
    private Divi_Metabox $divi_metabox;

    /**
     * Post list columns instance
     *
     * @var Post_List_Columns
     */
    private Post_List_Columns $post_list_columns;

    /**
     * Focus keyword AJAX handler instance
     *
     * @var Focus_Keyword_Ajax
     */
    private Focus_Keyword_Ajax $focus_keyword_ajax;

    /**
     * Bulk action manager instance
     *
     * @var Bulk_Action_Manager
     */
    private Bulk_Action_Manager $bulk_action_manager;

    /**
     * Post list filters instance
     *
     * @var Post_List_Filters
     */
    private Post_List_Filters $post_list_filters;

    /**
     * Admin pages
     *
     * @var array
     */
    private array $pages = [];

    /**
     * Constructor
     *
     * @param Settings $settings Settings instance
     * @param Database $database Database instance
     */
    public function __construct(?Settings $settings = null, ?Database $database = null) {
        $this->settings = $settings ?? Settings::instance();
        $this->database = $database ?? new Database();
        $this->metabox_manager = new Metabox_Manager($this->settings);
        $this->elementor_metabox = new Elementor_Metabox($this->metabox_manager);
        $this->oxygen_metabox = new Oxygen_Metabox($this->metabox_manager);
        $this->divi_metabox = new Divi_Metabox($this->metabox_manager);
        $this->post_list_columns = new Post_List_Columns();
        $this->focus_keyword_ajax = new Focus_Keyword_Ajax();
        $this->bulk_action_manager = new Bulk_Action_Manager();
        $this->post_list_filters = new Post_List_Filters();
    }

    /**
     * Initialize admin interface
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'handle_admin_init']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('thinkrank_admin_notices', [$this, 'show_admin_notices']);
		add_action( 'in_admin_header', [ $this, 'remove_admin_notice' ], 99 );

        // AJAX handlers
        add_action('wp_ajax_thinkrank_dismiss_notice', [$this, 'dismiss_notice']);

        // Initialize metabox manager
        $this->metabox_manager->init();

        // Initialize Elementor editor integration (hooks no-op without Elementor)
        $this->elementor_metabox->init();

        // Initialize Oxygen / Breakdance editor integration (hooks gate on the
        // builder request, so they no-op without Oxygen)
        $this->oxygen_metabox->init();

        // Initialize Divi Visual Builder integration (hooks gate on the VB
        // request, so they no-op without Divi)
        $this->divi_metabox->init();

        // Initialize post list columns
        $this->post_list_columns->init();

        // Initialize focus keyword AJAX handler
        $this->focus_keyword_ajax->init();

        // Initialize Bulk Action Manager
        $this->bulk_action_manager->init();

        // Initialize Post List Filters
        $this->post_list_filters->init();

        // Initialize Setup Wizard (onboarding) controller
        (new Setup_Wizard())->init();

        // Initialize the wp-admin Dashboard widget (ThinkRank Website Insights)
        (new Dashboard_Widget())->init();
    }

    /**
     * Add admin menu pages
     * 
     * @return void
     */
    public function add_admin_menu(): void {
        // Main menu page
        $this->pages['dashboard'] = add_menu_page(
            __('ThinkRank', 'thinkrank'),
            __('ThinkRank', 'thinkrank'),
            Capability_Manager::ACCESS,
            'thinkrank',
            [$this, 'render_dashboard_page'],
            $this->get_menu_icon(),
            30
        );

        // Dashboard submenu (same as main)
        $this->pages['dashboard_sub'] = add_submenu_page(
            'thinkrank',
            __('Dashboard', 'thinkrank'),
            __('Dashboard', 'thinkrank'),
            Capability_Manager::ACCESS,
            'thinkrank',
            [$this, 'render_dashboard_page']
        );

        // Essential SEO page (React tabbed interface)
        $this->pages['essential_seo'] = add_submenu_page(
            'thinkrank',
            __('Essential SEO', 'thinkrank'),
            __('Essential SEO', 'thinkrank'),
            Capability_Manager::ACCESS,
            'thinkrank-essential-seo',
            [$this, 'render_essential_seo_page']
        );

        // AI Tools page — gated by the AI Tools section capability.
        $this->pages['ai_tools'] = add_submenu_page(
            'thinkrank',
            __('AI Tools', 'thinkrank'),
            __('AI Tools', 'thinkrank'),
            'thinkrank_content_tools',
            'thinkrank-ai-tools',
            [$this, 'render_ai_tools_page']
        );

        // Usages page — gated by the Analytics section capability.
        $this->pages['analytics'] = add_submenu_page(
            'thinkrank',
            __('Usages', 'thinkrank'),
            __('Usages', 'thinkrank'),
            'thinkrank_analytics',
            'thinkrank-usages',
            [$this, 'render_analytics_page']
        );

        // Settings page — gated by the Settings & API Keys section capability.
        $this->pages['settings'] = add_submenu_page(
            'thinkrank',
            __('Settings', 'thinkrank'),
            __('Settings', 'thinkrank'),
            'thinkrank_settings',
            'thinkrank-settings',
            [$this, 'render_settings_page']
        );

        // Migration page — re-run SEO data imports from other plugins after
        // setup. Hidden by default; shown only when the "Enable Migration Tools"
        // advanced setting is on. Capability matches the import REST endpoints
        // (`manage_options`) so the UI and API stay in agreement.
        if (Settings::instance()->get('enable_migration_tools', false)) {
            $this->pages['migration'] = add_submenu_page(
                'thinkrank',
                __('Migration', 'thinkrank'),
                __('Migration', 'thinkrank'),
                'manage_options',
                'thinkrank-migration',
                [$this, 'render_migration_page']
            );
        }

        // Hook for page-specific initialization
        foreach ($this->pages as $page_hook) {
            add_action("load-{$page_hook}", [$this, 'load_admin_page']);
        }
    }

    /**
     * Enqueue admin scripts and styles
     *
     * APPROACH: Manual enqueuing with disabled webpack code splitting
     * - All dependencies bundled into main admin.js (677KB)
     * - Chart.js separated into charts.js (138KB) for performance
     * - No dynamic chunks - predictable loading order
     *
     * @param string $hook_suffix Current admin page hook
     * @return void
     */
    public function enqueue_admin_scripts(string $hook_suffix): void {
        // Only load on our admin pages
        if (!in_array($hook_suffix, $this->pages, true)) {
            return;
        }

        // Get asset files for cache busting
        $admin_asset_file = THINKRANK_PLUGIN_DIR . 'assets/admin.asset.php';
        $admin_asset_data = file_exists($admin_asset_file) ? include $admin_asset_file : [
            'dependencies' => [],
            'version' => THINKRANK_VERSION,
        ];

        // Enqueue the Chart.js bundle on every ThinkRank page: the admin app
        // is a SPA, so chart pages (Usages, Essential SEO Performance) are
        // reachable from any other ThinkRank page without a reload.
        $charts_asset_file = THINKRANK_PLUGIN_DIR . 'assets/charts.asset.php';
        $should_enqueue_charts = true;

        if ($should_enqueue_charts && file_exists($charts_asset_file)) {
            $charts_asset_data = include $charts_asset_file;
            wp_enqueue_script(
                'thinkrank-charts',
                THINKRANK_PLUGIN_URL . 'assets/charts.js',
                $charts_asset_data['dependencies'],
                $charts_asset_data['version'],
                true
            );
            // Add charts as dependency for admin script to ensure registration before use
            $admin_dependencies = array_merge($admin_asset_data['dependencies'], ['thinkrank-charts']);
        } else {
            // Do not load charts on pages that don't need it
            $admin_dependencies = $admin_asset_data['dependencies'];
        }

        wp_enqueue_script(
            'thinkrank-admin',
            THINKRANK_PLUGIN_URL . 'assets/admin.js',
            $admin_dependencies,
            $admin_asset_data['version'],
            true
        );

        // Add defer attribute for better performance
        wp_script_add_data('thinkrank-admin', 'defer', true);

        // Enqueue admin styles
        wp_enqueue_style(
            'thinkrank-admin',
            THINKRANK_PLUGIN_URL . 'assets/admin.css',
            ['wp-components'],
            $admin_asset_data['version']
        );

        // Enqueue WordPress media library for MediaPicker component
        wp_enqueue_media();

        // Preload the REST responses every ThinkRank admin page requests on
        // mount (Site Kit pattern: rest_preload_api_request piped into an
        // apiFetch preloading middleware) so first paint needs zero
        // round-trips for them. Only cheap, local settings endpoints belong
        // here — never Google-backed report data.
        $preload_paths = apply_filters('thinkrank_apifetch_preload_paths', [
            '/thinkrank/v1/site-identity/settings',
            '/thinkrank/v1/site-identity/title/templates',
            '/thinkrank/v1/site-identity/breadcrumbs/types',
        ]);
        $preload_data = array_reduce($preload_paths, 'rest_preload_api_request', []);
        wp_add_inline_script(
            'thinkrank-admin',
            sprintf('window.thinkrankApiPreload = %s;', wp_json_encode((object) $preload_data)),
            'before'
        );

        // Site info saved in ThinkRank Site Identity takes precedence over
        // the WordPress defaults so previews reflect what the user saved.
        $site_identity_settings = (new \ThinkRank\SEO\Site_Identity_Manager())->get_settings('site');

        // Localize script with data
        wp_localize_script('thinkrank-admin', 'thinkrankAdmin', [
            'apiUrl' => rest_url('thinkrank/v1/'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'adminNonce' => wp_create_nonce('thinkrank_admin'),
            'currentUser' => wp_get_current_user()->ID,
            'displayName' => wp_get_current_user()->display_name,
            'capabilities' => $this->get_user_capabilities(),
            'settings' => $this->get_admin_settings(),
            'i18n' => $this->get_i18n_strings(),
            'isAdmin' => current_user_can('manage_options'),
            // Whether any AI provider API key is configured — used to gate
            // "Generate with AI" buttons in the UI
            'aiConfigured' => $this->is_ai_configured(),
            // Plugin version - directly available without API call
            'version' => THINKRANK_VERSION,
            // Site information for default values
            'siteName' => !empty($site_identity_settings['site_name']) ? $site_identity_settings['site_name'] : get_bloginfo('name'),
            'siteDescription' => !empty($site_identity_settings['site_description']) ? $site_identity_settings['site_description'] : get_bloginfo('description'),
            'siteUrl' => home_url(),
            'faviconUrl' => get_site_icon_url(64) ?: '',
            'adminEmail' => get_option('admin_email'),
            // Post types for Global SEO navigation
            'postTypes' => $this->get_public_post_types(),
            // Role Manager: capabilities the current user holds + the
            // section → capability map, so the SPA can hide sections a role
            // cannot access. Administrators receive every capability.
            'caps' => Capability_Manager::user_capabilities(),
            'sectionCaps' => Capability_Manager::section_map(),
            'canManageRoles' => Capability_Manager::current_user_can(Capability_Manager::MANAGE_ROLES),
            // Pro detection flag
            'isPro' => Plan_Config::is_pro(),
            // Data update frequency (Pro: daily, Free: every 3 days)
            'dataUpdateFrequency' => Plan_Config::is_pro() ? 'daily' : '3days',
            // Per-feature capability maps. Mirrors PHP Plan_Config so JS
            // never has to ask "is the user Pro?" — it asks "can the user X?".
            'emailReport' => Plan_Config::email_report(),
            // MCP (Model Context Protocol) connection details for the MCP page.
            'mcp' => $this->get_mcp_globals(),
            // Google OAuth: JS only ever gets a nonce-signed admin-post URL.
            // The consent URL, client ID, and scopes are assembled by the proxy,
            // so no Google app credentials reach the browser or the bundle.
            'googleOAuth' => [
                'connectUrl' => \ThinkRank\Integrations\Google_OAuth_Proxy::get_connect_url(),
                // '' when fine, otherwise why re-authorization is needed:
                // 'contract' (upgraded off the old token flow) or
                // 'credentials' (stored tokens no longer decryptable).
                'reconnectReason' => (string) get_option('thinkrank_google_reconnect_required', ''),
            ],
        ]);

    }

    /**
     * Handle admin initialization
     *
     * @return void
     */
    public function handle_admin_init(): void {
        // Show welcome screen for new installations (only if no API key configured)
        if (get_option('thinkrank_show_welcome') && !$this->has_api_key_configured()) {
            add_action('admin_notices', [$this, 'show_welcome_notice']);
        }

        // Check for plugin updates
        $this->check_plugin_updates();
    }

    /**
     * Load admin page
     * 
     * @return void
     */
    public function load_admin_page(): void {
        // Add screen options
        $this->add_screen_options();
    }

    /**
     * Render dashboard page
     *
     * @return void
     */
    public function render_dashboard_page(): void {
        $this->render_admin_page('dashboard', [
            'title' => __('ThinkRank Dashboard', 'thinkrank'),
            'description' => __('AI-powered SEO optimization for WordPress', 'thinkrank'),
        ]);
    }

    /**
     * Render Essential SEO page
     *
     * @return void
     */
    public function render_essential_seo_page(): void {
        $this->render_admin_page('essential-seo', [
            'title' => __('Essential SEO', 'thinkrank'),
            'description' => __('Configure your site-wide SEO settings with AI-powered optimization', 'thinkrank'),
        ]);
    }

    /**
     * Render AI Tools page
     *
     * @return void
     */
    public function render_ai_tools_page(): void {
        $this->render_admin_page('ai-tools', [
            'title' => __('AI Tools', 'thinkrank'),
            'description' => __('AI-powered content tools including Content Planner and Metadata Generator', 'thinkrank'),
        ]);
    }

    /**
     * Render settings page
     *
     * @return void
     */
    public function render_settings_page(): void {
        $this->render_admin_page('settings', [
            'title' => __('ThinkRank Settings', 'thinkrank'),
            'description' => __('Configure your AI SEO settings', 'thinkrank'),
        ]);
    }

    /**
     * Build the MCP connection globals passed to the admin app.
     *
     * The MCP page fetches live connection state from the
     * /thinkrank/v1/mcp/connection route; these globals only carry what the
     * page needs before that request resolves (endpoint URLs and whether the
     * bundled Abilities API — the tool catalog — is available).
     *
     * @return array<string, mixed>
     */
    private function get_mcp_globals(): array {
        // The tool catalog is the abilities registry; wp_register_ability
        // comes from the bundled Abilities API under dependencies/. When it's
        // missing (bundle not built), the MCP server has no tools to serve.
        $abilities_api_available = function_exists('wp_register_ability');

        return [
            'abilities_api_available' => $abilities_api_available,
            'mcp_endpoint' => \ThinkRank\Mcp\Mcp_Pairing::site_endpoint(),
            'mcp_endpoint_rest' => \ThinkRank\Mcp\Mcp_Pairing::site_endpoint_fallback(),
        ];
    }



    /**
     * Render usage analytics page
     *
     * @return void
     */
    public function render_analytics_page(): void {
        $this->render_admin_page('analytics', [
            'title' => __('Usage Analytics', 'thinkrank'),
            'description' => __('Track your AI usage, costs, and plugin performance analytics', 'thinkrank'),
        ]);
    }

    /**
     * Render import/export page
     *
     * @return void
     */
    public function render_import_export_page(): void {
        $this->render_admin_page('import-export', [
            'page_title' => __('Import / Export', 'thinkrank'),
        ]);
    }

    /**
     * Render the Migration page (re-run SEO data imports).
     *
     * Defense in depth: the submenu is only registered when the setting is on,
     * but re-check here so a direct hit on the page URL can't bypass the gate.
     *
     * @return void
     */
    public function render_migration_page(): void {
        if (!Settings::instance()->get('enable_migration_tools', false)) {
            wp_die(esc_html__('The Migration tools are not enabled.', 'thinkrank'));
        }
        $this->render_admin_page('migration', [
            'page_title' => __('Migration', 'thinkrank'),
        ]);
    }

    /**
     * Render admin page template
     * 
     * @param string $page Page identifier
     * @param array $data Page data
     * @return void
     */
    private function render_admin_page(string $page, array $data): void {
?>
        <div class="wrap">
            <div id="thinkrank-<?php echo esc_attr($page); ?>" class="thinkrank-admin-page"></div>
        </div>
    <?php
    }

    /**
     * Add meta boxes to post edit screens
     * 
     * @return void
     */
    public function add_meta_boxes(): void {
        $post_types = get_post_types(['public' => true]);

        foreach ($post_types as $post_type) {
            add_meta_box(
                'thinkrank-seo',
                __('ThinkRank SEO', 'thinkrank'),
                [$this, 'render_seo_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render SEO meta box
     * 
     * @param \WP_Post $post Current post object
     * @return void
     */
    public function render_seo_meta_box(\WP_Post $post): void {
        wp_nonce_field('thinkrank_meta_box', 'thinkrank_meta_box_nonce');

        echo '<div id="thinkrank-meta-box" data-post-id="' . esc_attr($post->ID) . '">';
        echo '</div>';
    }

    /**
     * Save meta box data
     * 
     * @param int $post_id Post ID
     * @return void
     */
    public function save_meta_boxes(int $post_id): void {
        // Verify nonce
        if (!isset($_POST['thinkrank_meta_box_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['thinkrank_meta_box_nonce']));
        if (!wp_verify_nonce($nonce, 'thinkrank_meta_box')) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save meta data (handled by AJAX in React components)
        // Pass only sanitized ThinkRank-related fields to action hook
        $sanitized_data = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'thinkrank_') === 0 || strpos($key, '_thinkrank_') === 0) {
                $sanitized_data[$key] = is_array($value)
                    ? array_map('sanitize_text_field', wp_unslash($value))
                    : sanitize_text_field(wp_unslash($value));
            }
        }
        do_action('thinkrank_save_post_meta', $post_id, $sanitized_data);
    }

    /**
     * Show admin notices
     * 
     * @return void
     */
    public function show_admin_notices(): void {
        // Implementation will be added in next iteration
    }

    /**
     * Show welcome notice
     * 
     * @return void
     */
    public function show_welcome_notice(): void {
        wp_enqueue_style(
            'thinkrank-admin-notices',
            THINKRANK_PLUGIN_URL . 'static/css/admin-notices.css',
            [],
            THINKRANK_VERSION
        );
    ?>
        <div class="notice notice-success is-dismissible thinkrank-notice thinkrank-welcome-notice">
            <div class="thinkrank-notice__inner">
                <div class="thinkrank-notice__body">
                    <p class="thinkrank-notice__title"><?php esc_html_e('Welcome to ThinkRank!', 'thinkrank'); ?></p>
                    <p class="thinkrank-notice__text"><?php esc_html_e('Thanks for installing ThinkRank. Add an AI provider key to unlock automatic titles, descriptions, and SEO scoring.', 'thinkrank'); ?></p>
                    <p class="thinkrank-notice__actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=thinkrank-settings')); ?>" class="button button-primary">
                            <?php esc_html_e('Configure Settings', 'thinkrank'); ?>
                        </a>
                        <a href="#" class="thinkrank-notice__dismiss thinkrank-dismiss-welcome" data-nonce="<?php echo esc_attr(wp_create_nonce('thinkrank_admin')); ?>">
                            <?php esc_html_e('Dismiss', 'thinkrank'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
<?php
        // The notice renders on every admin screen, so the dismiss handler must
        // ship with it — the thinkrank-admin bundle only loads on ThinkRank pages.
        // Persist the dismissal for both our "Dismiss" link and core's × button.
        wp_print_inline_script_tag(
            '( function () {
                document.addEventListener( "click", function ( event ) {
                    var notice = event.target.closest( ".thinkrank-welcome-notice" );
                    if ( ! notice ) {
                        return;
                    }
                    var link = event.target.closest( ".thinkrank-dismiss-welcome" );
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
                            action: "thinkrank_dismiss_notice",
                            notice_type: "welcome",
                            nonce: notice.querySelector( ".thinkrank-dismiss-welcome" ).dataset.nonce,
                        } ),
                    } );
                } );
            } )();'
        );
    }

    /**
     * Dismiss notice via AJAX
     * 
     * @return void
     */
    public function dismiss_notice(): void {
        check_ajax_referer('thinkrank_admin', 'nonce');

        $notice_type = sanitize_key($_POST['notice_type'] ?? '');

        if ($notice_type === 'welcome') {
            delete_option('thinkrank_show_welcome');
        }

        wp_die();
    }

    /**
     * Check if API key is configured
     *
     * @return bool True if at least one API key is configured
     */
    private function has_api_key_configured(): bool {
        $settings = \ThinkRank\Core\Settings::instance();

        return !empty($settings->get('openai_api_key'))
            || !empty($settings->get('claude_api_key'))
            || !empty($settings->get('gemini_api_key'))
            || !empty($settings->get('openrouter_api_key'));
    }

    /**
     * Get menu icon
     *
     * @return string Menu icon
     */
    private function get_menu_icon(): string {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g clipPath="url(#thinkrank-clip)">
                    <g filter="url(#thinkrank-shadow)">
                        <circle cx="14" cy="9.2" r="1.3" fill="#a7aaad"/>
                    </g>
                    <path d="M19.5 7v5.3c0 2.4 0 3.6-.5 4.5-.4.8-1 1.4-1.8 1.8-.9.5-2.1.5-4.5.5H7.3c-2.4 0-3.6 0-4.5-.5-.8-.4-1.4-1-1.8-1.8-.2-.3-.3-.7-.4-1.1.5-.4.9-.6 1.3-.7.5-.2.9-.2 1.4-.2.7.1 1.3.2 2 .3.7.1 1.4.2 2.1.1 1.5-.3 2.5-1 3.4-2 .5-.5.9-1 1.4-1.5.3-.3.6-.7.9-1 .3.1.6.2.9.2.9 0 1.7-.8 1.7-1.7 0-.2 0-.4-.1-.6.7-.4 1.4-.8 2.1-1.1.6-.3 1.2-.6 1.6-.8v.4zM12.5 0c2.4 0 3.6 0 4.5.5.8.4 1.4 1 1.8 1.8.4.7.5 1.6.5 3.1-.1 0-.2.1-.3.1-.5.2-1.1.5-1.8.8-.7.3-1.5.7-2.2 1.1-.3-.3-.7-.4-1.1-.4-.9 0-1.7.8-1.7 1.7 0 .3.1.6.3.9-.3.4-.6.7-.9 1-.5.6-.9 1.1-1.3 1.5-.9.9-1.8 1.5-3 1.7-.6.1-1.2.1-1.8 0-.6-.1-1.3-.3-2-.4-.6-.1-1.2-.1-1.8.1-.3.1-.7.3-1 .5 0-.6 0-1.4 0-2.3V7c0-2.4 0-3.6.5-4.5.4-.8 1-1.4 1.8-1.8C3.9 0 5.1 0 7.5 0h5zm-5.8 8.2c0-.1-.1-.1-.2 0l-.2.9c0 0 0 .1-.1.1l-.9.2c-.1 0-.1.1 0 .1l.9.2c0 0 .1 0 .1.1l.2.9c0 .1.1.1.2 0l.2-.9c0 0 0-.1.1-.1l.9-.2c.1 0 .1-.1 0-.1l-.9-.2c0 0-.1 0-.1-.1l-.2-.9zm8.8-.4c0 .1 0 .2 0 .3 0 .7-.6 1.3-1.3 1.3-.2 0-.4 0-.5-.1.1-.1.2-.2.3-.3.4-.4.9-.8 1.5-1.2zm-1.3-1c.3 0 .5.1.7.2-.6.4-1.1.8-1.5 1.2l-.1.1c-.1.1-.2.2-.3.3-.1-.2-.1-.4-.1-.6 0-.7.6-1.2 1.3-1.2zM8.6 2.5c-.1-.2-.4-.2-.4 0l-.4 1.4c0 .1-.1.1-.1.1L6.2 4.4c-.2.1-.2.4 0 .4l1.4.4c.1 0 .1.1.1.1l.4 1.4c.1.2.4.2.4 0l.4-1.4c0-.1.1-.1.1-.1l1.4-.4c.2-.1.2-.4 0-.4L8.9 4c-.1 0-.1-.1-.1-.1L8.6 2.5z" fill="#a7aaad"/>
                </g>
                <defs>
                    <filter id="thinkrank-shadow" x="11.2" y="7.2" width="5.6" height="5.6" filterUnits="userSpaceOnUse" colorInterpolationFilters="sRGB">
                        <feFlood floodOpacity="0" result="BackgroundImageFix"/>
                        <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
                        <feOffset dy="0.7"/>
                        <feGaussianBlur stdDeviation="0.7"/>
                        <feComposite in2="hardAlpha" operator="out"/>
                        <feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.15 0"/>
                        <feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow"/>
                        <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow" result="shape"/>
                    </filter>
                    <clipPath id="thinkrank-clip">
                        <rect width="20" height="20" rx="5.5" fill="white"/>
                    </clipPath>
                </defs>
            </svg>'
        );
    }

    /**
     * Get user capabilities for current user
     * 
     * @return array User capabilities
     */
    private function get_user_capabilities(): array {
        return [
            'manage_settings' => current_user_can('manage_options'),
            'view_analytics' => current_user_can('edit_posts'),

            'use_ai_features' => current_user_can('edit_posts'),
        ];
    }

    /**
     * Get admin settings for JavaScript
     * 
     * @return array Admin settings
     */
    private function get_admin_settings(): array {
        return [

            'ai_provider' => $this->settings->get('ai_provider', 'openai'),
            'cache_duration' => $this->settings->get('cache_duration', 3600),
        ];
    }

    /**
     * Whether any AI provider API key is configured
     *
     * Mirrors the check used by ThinkRank\AI\Manager.
     *
     * @return bool
     */
    private function is_ai_configured(): bool {
        foreach (['openai_api_key', 'claude_api_key', 'gemini_api_key', 'openrouter_api_key'] as $key) {
            if (!empty($this->settings->get($key, ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get internationalization strings
     *
     * @return array I18n strings
     */
    private function get_i18n_strings(): array {
        return [
            'loading' => __('Loading...', 'thinkrank'),
            'error' => __('An error occurred', 'thinkrank'),
            'success' => __('Success!', 'thinkrank'),
            'confirm' => __('Are you sure?', 'thinkrank'),
            'cancel' => __('Cancel', 'thinkrank'),
            'save' => __('Save', 'thinkrank'),
        ];
    }

    /**
     * Get all public post types for SEO configuration
     *
     * @return array Post types data
     */
    private function get_public_post_types(): array {
        // Get all public post types (both built-in and custom)
        $post_types = get_post_types([
            'public' => true
        ], 'objects');

        $post_types_data = [];

        foreach ($post_types as $post_type) {
            // Shared eligibility policy (viewable + deny list) so the UI list and
            // the REST/ability write paths agree on which types are SEO targets.
            if (!\ThinkRank\SEO\Global_SEO_Post_Types::is_allowed($post_type)) {
                continue;
            }

            $post_types_data[] = [
                'name' => $post_type->name,
                'slug' => $post_type->name,
                'label' => $post_type->label,
                'singular_name' => $post_type->labels->singular_name ?? $post_type->label,
                'plural_name' => $post_type->label,
                'public' => $post_type->public,
                'has_archive' => $post_type->has_archive,
                'hierarchical' => $post_type->hierarchical,
            ];
        }

        return $post_types_data;
    }

    /**
     * Add help tabs
     * 
     * @return void
     */
    private function add_help_tabs(): void {
        $screen = get_current_screen();

        $screen->add_help_tab([
            'id' => 'thinkrank-overview',
            'title' => __('Overview', 'thinkrank'),
            'content' => '<p>' . __('ThinkRank.ai helps you optimize your content with AI-powered SEO suggestions.', 'thinkrank') . '</p>',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'thinkrank') . '</strong></p>' .
                '<p><a href="https://thinkrank.ai/docs" target="_blank">' . __('Documentation', 'thinkrank') . '</a></p>' .
                '<p><a href="https://wpdeveloper.com/support/new-ticket/" target="_blank">' . __('Support', 'thinkrank') . '</a></p>'
        );
    }

    /**
     * Add screen options
     * 
     * @return void
     */
    private function add_screen_options(): void {
        // Screen options will be added as needed
    }

    /**
     * Check for plugin updates
     *
     * @return void
     */
    private function check_plugin_updates(): void {
        $current_version = get_option('thinkrank_version');

        if (false === $current_version) {
            // Fresh install or missing option — record version without firing the update hook.
            update_option('thinkrank_version', THINKRANK_VERSION);
            return;
        }

        if (version_compare($current_version, THINKRANK_VERSION, '<')) {
            // Handle plugin update
            do_action('thinkrank_plugin_updated', $current_version, THINKRANK_VERSION);
            update_option('thinkrank_version', THINKRANK_VERSION);
        }
    }


	public function remove_admin_notice() {
		$current_screen = get_current_screen();
		if ( in_array( $current_screen->id, [
				'toplevel_page_thinkrank',
				'thinkrank_page_thinkrank-essential-seo',
				'thinkrank_page_thinkrank-ai-tools',
				'thinkrank_page_thinkrank-settings',
				'thinkrank_page_thinkrank-usages',
				'thinkrank_page_thinkrank-license',
				'thinkrank_page_thinkrank-migration'
		] ) ) {

			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'network_admin_notices' );

			// To showing notice in EA settings page we have to use 'eael_admin_notices' action hook
			add_action( 'admin_notices', function () {
				do_action( 'thinkrank_admin_notices' );
			} );
		}
	}
}
