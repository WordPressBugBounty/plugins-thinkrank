<?php

/**
 * WordPress Dashboard Widget
 *
 * Registers the "ThinkRank Website Insights" widget on the wp-admin Dashboard
 * (index.php). Before a Google account is connected it shows an empty state
 * with a "Connect to Google Search Console" call to action; once connected it
 * renders live Search Console performance metrics.
 *
 * The widget is a small standalone React island (the `dashboard-widget` webpack
 * entry) mounted into `#thinkrank-dashboard-widget`. It talks to the existing
 * `/thinkrank/v1/seo-analytics/*` REST routes and reuses the shared Google
 * OAuth proxy connect URL — no Google credentials ever touch the browser.
 *
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\Core\Settings;
use ThinkRank\Core\Plan_Config;
use ThinkRank\Integrations\Google_OAuth_Proxy;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard Widget controller.
 *
 * Single Responsibility: register and render the ThinkRank dashboard widget.
 *
 * @since 1.0.0
 */
class Dashboard_Widget {

    /**
     * Widget DOM/registration id.
     *
     * @var string
     */
    private const WIDGET_ID = 'thinkrank_website_insights';

    /**
     * Capability required to see the widget.
     *
     * Connecting a Google account requires `manage_options` (see
     * Google_OAuth_Proxy::handle_connect), so gate the whole widget on the
     * same capability rather than showing a connect button that would 403.
     *
     * @var string
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Register the dashboard widget.
     *
     * @return void
     */
    public function register_widget(): void {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('ThinkRank Website Insights', 'thinkrank'),
            [$this, 'render']
        );
    }

    /**
     * Render the widget container.
     *
     * The React app mounts here; the fallback text only shows if the bundle
     * fails to load or JavaScript is disabled.
     *
     * @return void
     */
    public function render(): void {
        echo '<div id="thinkrank-dashboard-widget" class="thinkrank-dashboard-widget">';
        echo '<p class="thinkrank-dashboard-widget__fallback">'
            . esc_html__('Loading ThinkRank insights…', 'thinkrank')
            . '</p>';
        echo '</div>';
    }

    /**
     * Enqueue the widget bundle on the Dashboard screen only.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public function enqueue_assets(string $hook_suffix): void {
        // Core Dashboard screen only, and only for users who can see the widget.
        if ($hook_suffix !== 'index.php' || !current_user_can(self::CAPABILITY)) {
            return;
        }

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/dashboard-widget.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
            'version'      => THINKRANK_VERSION,
        ];

        wp_enqueue_script(
            'thinkrank-dashboard-widget',
            THINKRANK_PLUGIN_URL . 'assets/dashboard-widget.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'thinkrank-dashboard-widget',
            THINKRANK_PLUGIN_URL . 'assets/dashboard-widget.css',
            ['wp-components'],
            $asset['version']
        );

        $settings = Settings::instance();

        wp_localize_script('thinkrank-dashboard-widget', 'thinkrankDashboardWidget', [
            'apiUrl'      => rest_url('thinkrank/v1/'),
            'restNonce'   => wp_create_nonce('wp_rest'),
            'connected'   => (bool) $settings->get('google_account_connected', false),
            'isPro'       => Plan_Config::is_pro(),
            // Pro upsell target for the teaser bar (filterable so campaigns can
            // point it at a specific landing page).
            'upgradeUrl'  => apply_filters(
                'thinkrank_dashboard_widget_upgrade_url',
                'https://thinkrank.ai/#pricing'
            ),
            // The "Connect to Google Search Console" button starts the OAuth
            // flow directly. JS only ever gets a nonce-signed admin-post URL —
            // the consent URL, client id, and scopes are assembled by the proxy,
            // never in the browser. The flow returns to the Dashboard when done.
            'connectUrl'  => Google_OAuth_Proxy::get_connect_url(admin_url('index.php')),
            // "View More" (connected state) deep-links to Analytics › Dashboard
            // inside the Essential SEO SPA (nav_section/nav_item drive the tab).
            'settingsUrl' => admin_url('admin.php?page=thinkrank-essential-seo&nav_section=analytics&nav_item=dashboard'),
            // Where the Search Console property is picked — the fix for the most
            // common widget error (connected account, no property selected).
            'propertyUrl' => admin_url('admin.php?page=thinkrank-essential-seo&nav_section=integrations&nav_item=google-services'),
        ]);
    }
}
