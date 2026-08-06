<?php

/**
 * Plugin Name: ThinkRank
 * Plugin URI: https://thinkrank.ai/
 * Description: AI-native SEO plugin for WordPress. Automate and enhance your SEO with cutting-edge AI while maintaining editorial control.
 * Version: 1.27.0
 * Author: WPDeveloper
 * Author URI: https://wpdeveloper.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: thinkrank
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * 
 * @package ThinkRank
 * @version 1.27.0
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('THINKRANK_VERSION', '1.27.0');
define('THINKRANK_PLUGIN_FILE', __FILE__);
define('THINKRANK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('THINKRANK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('THINKRANK_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Minimum requirements check
if (version_compare(PHP_VERSION, '8.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('ThinkRank requires PHP 8.0 or higher. Please upgrade your PHP version.', 'thinkrank');
        echo '</p></div>';
    });
    return;
}

if (version_compare(get_bloginfo('version'), '6.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('ThinkRank requires WordPress 6.0 or higher. Please upgrade your WordPress installation.', 'thinkrank');
        echo '</p></div>';
    });
    return;
}

// Autoloader
require_once THINKRANK_PLUGIN_DIR . 'includes/class-autoloader.php';

/**
 * Bundled AI Building Blocks (Abilities API + MCP Adapter).
 *
 * Loaded through the Jetpack Autoloader so that if the same libraries are also
 * shipped by another plugin — or land in WordPress core — the newest copy wins
 * and loads once, with no fatal class collisions. This lets ThinkRank serve its
 * MCP endpoint out of the box, without requiring the standalone MCP Adapter and
 * Abilities API plugins. See docs/mcp-server.md for the update procedure.
 */
$thinkrank_mcp_runtime = THINKRANK_PLUGIN_DIR . 'dependencies/vendor/autoload_packages.php';
if (is_readable($thinkrank_mcp_runtime)) {
    require_once $thinkrank_mcp_runtime;
}
unset($thinkrank_mcp_runtime);

/**
 * Main ThinkRank Plugin Class
 * 
 * Follows Single Responsibility Principle - only handles plugin initialization
 * 
 * @since 1.0.0
 */
final class ThinkRank {

    /**
     * Plugin instance (Singleton Pattern)
     * 
     * @var ThinkRank|null
     */
    private static ?ThinkRank $instance = null;

    /**
     * Plugin components
     * 
     * @var array
     */
    private array $components = [];

    /**
     * Get plugin instance (Singleton Pattern)
     * 
     * @return ThinkRank
     */
    public static function get_instance(): ThinkRank {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->init_autoloader();
        $this->init_hooks();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {
    }

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }

    /**
     * Initialize autoloader
     * 
     * @return void
     */
    private function init_autoloader(): void {
        ThinkRank\Core\Autoloader::register();
    }

    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    private function init_hooks(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'init']);
        add_filter('plugin_action_links_' . THINKRANK_PLUGIN_BASENAME, [$this, 'add_action_links']);
    }

    /**
     * Add a "Dashboard" link to the plugin action links on the Plugins page.
     *
     * @param array $links Existing plugin action links.
     * @return array
     */
    public function add_action_links(array $links): array {
        $dashboard_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=thinkrank')),
            esc_html__('Dashboard', 'thinkrank')
        );

        array_unshift($links, $dashboard_link);

        return $links;
    }

    /**
     * Initialize plugin components
     * 
     * @return void
     */
    public function init(): void {
        try {
            $this->maybe_update_database();
            $this->register_sitemap_cron_listeners();
            $this->load_components();
            $this->init_components();
            $this->load_template_functions();

            do_action('thinkrank_loaded');
        } catch (\Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Register the sitemap regeneration WP-Cron listeners.
     *
     * Runs on plugins_loaded (via init()), so the callbacks exist on every
     * request — including WP-Cron, which never fires rest_api_init and therefore
     * never builds the Sitemap REST endpoint (whose constructor would otherwise
     * be the only place the scheduled regeneration hooks get a listener). The
     * generator is built lazily inside the callback so this stays cheap on the
     * vast majority of requests where no regeneration is due.
     *
     * @return void
     */
    private function register_sitemap_cron_listeners(): void {
        add_action('thinkrank_regenerate_sitemap', static function () {
            (new ThinkRank\SEO\Sitemap_Generator())->auto_regenerate_sitemap();
        });
        add_action('thinkrank_regenerate_sitemap_settings', static function () {
            (new ThinkRank\SEO\Sitemap_Generator())->regenerate_sitemap_from_settings();
        });
    }

    /**
     * Check if database schema needs updating and run migrations
     * 
     * Standard WordPress pattern: compare stored db_version against current,
     * run dbDelta if stale. This handles schema changes (new tables, new columns)
     * without requiring plugin deactivation/reactivation.
     *
     * @since 1.10.0
     * @return void
     */
    private function maybe_update_database(): void {
        $schema = new ThinkRank\Database\Database_Schema();
        if ($schema->needs_update()) {
            $schema->create_tables();
        }
    }

    /**
     * Load plugin components (Dependency Injection Container pattern)
     * 
     * @return void
     */
    private function load_components(): void {
        $this->components = [
            'database' => new ThinkRank\Core\Database(),
            'settings' => new ThinkRank\Core\Settings(),
            'role_manager' => new ThinkRank\Core\Role_Manager(),
            'security_headers' => new ThinkRank\Core\Security_Headers(),
            'asset_optimizer' => new ThinkRank\Core\Asset_Optimizer(),
            'usage_tracker' => new ThinkRank\Core\Usage_Tracker_Manager(),
            'api' => new ThinkRank\API\Manager(),
            'admin' => new ThinkRank\Admin\Manager(),
            'blocks' => new ThinkRank\Editor\Blocks_Manager(),
            'elementor' => new ThinkRank\Editor\Elementor_Manager(),
            'ai' => new ThinkRank\AI\Manager(),
            'frontend_seo' => new ThinkRank\Frontend\SEO_Manager(),
            'seo_notice' => new ThinkRank\Admin\SEO_Notice(),
            'performance_collector' => new ThinkRank\SEO\Performance_Data_Collector(),
            'instant_indexing' => new ThinkRank\SEO\Instant_Indexing_Manager(),
            'author_archives' => new ThinkRank\SEO\Author_Archives_Manager(),
            'email_report' => new ThinkRank\SEO\Email_Report_Manager(),
            'google_oauth' => new ThinkRank\Integrations\Google_OAuth_Proxy(),
            'multilingual' => new ThinkRank\Integrations\Multilingual_Manager(),
            'analytics' => new ThinkRank\SEO\Analytics_Manager(),
            'abilities' => new ThinkRank\Abilities\Abilities_Registrar(),
            'mcp' => new ThinkRank\Mcp\Mcp_Manager(),
        ];
    }

    /**
     * Initialize all components
     *
     * @return void
     */
    private function init_components(): void {
        foreach ($this->components as $component) {
            if (method_exists($component, 'init')) {
                $component->init();
            }
        }
    }

    /**
     * Load template functions for themes
     *
     * @return void
     */
    private function load_template_functions(): void {
        require_once THINKRANK_PLUGIN_DIR . 'includes/frontend/template-functions.php';
    }

    /**
     * Get component instance
     *
     * @param string $component Component name
     * @return object|null
     */
    public function get_component(string $component): ?object {
        return $this->components[$component] ?? null;
    }

    /**
     * Shared Analytics Manager instance (lazy)
     *
     * @var ThinkRank\SEO\Analytics_Manager|null
     */
    private ?ThinkRank\SEO\Analytics_Manager $analytics_manager = null;

    /**
     * Get the shared Analytics Manager, with Google clients initialized.
     *
     * Consumers (including the Pro plugin, which probes for this accessor)
     * should use this instead of constructing their own Analytics_Manager:
     * each fresh construction re-reads/decrypts settings and re-initializes
     * the Google API clients.
     *
     * @since 1.18.0
     * @return ThinkRank\SEO\Analytics_Manager
     */
    public function get_analytics_manager(): ThinkRank\SEO\Analytics_Manager {
        if ($this->analytics_manager === null) {
            // Reuse the registered component. Constructing a second instance
            // here would work, but only the registered one has had init() run
            // on it, so the token-refresh cron would be scheduled against a
            // different object than the one callers actually use.
            $component = $this->components['analytics'] ?? null;

            $this->analytics_manager = $component instanceof ThinkRank\SEO\Analytics_Manager
                ? $component
                : new ThinkRank\SEO\Analytics_Manager();

            // init() defers initialize_clients() to the `init` hook; callers
            // that arrive earlier still need working clients.
            $this->analytics_manager->initialize_clients();
        }
        return $this->analytics_manager;
    }

    /**
     * Plugin activation
     * 
     * @return void
     */
    public function activate(): void {
        try {
            $activator = new ThinkRank\Core\Activator();
            $activator->activate();

            // Flush rewrite rules
            flush_rewrite_rules();
        } catch (\Exception $e) {
            $this->handle_error($e);
            wp_die(
                esc_html__('ThinkRank activation failed. Please check your server logs.', 'thinkrank'),
                esc_html__('Plugin Activation Error', 'thinkrank'),
                ['back_link' => true]
            );
        }
    }

    /**
     * Plugin deactivation
     * 
     * @return void
     */
    public function deactivate(): void {
        try {
            $deactivator = new ThinkRank\Core\Deactivator();
            $deactivator->deactivate();

            // Flush rewrite rules
            flush_rewrite_rules();
        } catch (\Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Handle errors consistently
     * 
     * Logs errors and surfaces them via _doing_it_wrong() when
     * WP_DEBUG is enabled, helping developers diagnose issues.
     * 
     * @param \Exception $e Exception to handle
     * @return void
     */
    private function handle_error(\Exception $e): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('ThinkRank: ' . $e->getMessage());
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            _doing_it_wrong(
                __METHOD__,
                esc_html($e->getMessage()),
                esc_html(THINKRANK_VERSION)
            );
        }
    }

    /**
     * Get plugin version
     * 
     * @return string
     */
    public function get_version(): string {
        return THINKRANK_VERSION;
    }

    /**
     * Get plugin directory path
     * 
     * @return string
     */
    public function get_plugin_dir(): string {
        return THINKRANK_PLUGIN_DIR;
    }

    /**
     * Get plugin URL
     * 
     * @return string
     */
    public function get_plugin_url(): string {
        return THINKRANK_PLUGIN_URL;
    }
}

/**
 * Initialize the plugin
 * 
 * @return ThinkRank
 */
function thinkrank(): ThinkRank {
    return ThinkRank::get_instance();
}

// Start the plugin
thinkrank();
