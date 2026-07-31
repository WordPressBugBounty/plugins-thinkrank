<?php
/**
 * Asset Optimizer Class
 *
 * Simple asset optimization for better performance.
 * Implements preloading and basic optimization strategies.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset Optimizer Class
 *
 * @since 1.0.0
 */
class Asset_Optimizer {

    /**
     * Initialize asset optimization
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('admin_init', [$this, 'init_admin_optimizations']);
    }

    /**
     * Initialize admin-specific optimizations
     *
     * @since 1.0.0
     */
    public function init_admin_optimizations(): void {
        // Only optimize on ThinkRank admin pages
        if (!$this->is_thinkrank_admin_page()) {
            return;
        }

        add_action('admin_head', [$this, 'add_asset_preloading'], 1);
        add_action('admin_head', [$this, 'add_performance_hints'], 2);
    }

    /**
     * Check if current page is a ThinkRank admin page
     *
     * @since 1.0.0
     *
     * @return bool True if ThinkRank admin page
     */
    private function is_thinkrank_admin_page(): bool {
        $screen = get_current_screen();
        
        if (!$screen) {
            return false;
        }

        // Check for ThinkRank admin pages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page parameter for screen detection, not processing form data.
        return strpos($screen->id, 'thinkrank') !== false ||
               strpos($screen->base, 'thinkrank') !== false ||
               (isset($_GET['page']) && strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'thinkrank') !== false); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Add asset preloading headers
     *
     * @since 1.0.0
     */
    public function add_asset_preloading(): void {
        $plugin_url = plugin_dir_url(THINKRANK_PLUGIN_FILE);
        
        $assets = [
            // Critical CSS
            [
                'url' => $plugin_url . 'assets/admin.css',
                'type' => 'style'
            ],
            // Critical JS
            [
                'url' => $plugin_url . 'assets/admin.js',
                'type' => 'script'
            ]
        ];

        foreach ($assets as $asset) {
            // Check if file exists before preloading
            $file_path = str_replace($plugin_url, THINKRANK_PLUGIN_DIR, $asset['url']);
            if (file_exists($file_path)) {
                printf(
                    '<link rel="preload" href="%s" as="%s"%s>' . "\n",
                    esc_url($asset['url']),
                    esc_attr($asset['type']),
                    $asset['type'] === 'style' ? ' type="text/css"' : ''
                );
            }
        }
    }

    /**
     * Add performance hints
     *
     * @since 1.0.0
     */
    public function add_performance_hints(): void {
        // DNS prefetch for external APIs (if configured)
        $external_domains = [
            'api.openai.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com'
        ];

        foreach ($external_domains as $domain) {
            printf('<link rel="dns-prefetch" href="//%s">' . "\n", esc_attr($domain));
        }

        // Remove redundant viewport meta tag: WordPress admin already sets this.
        // Keeping only DNS prefetch hints here.
    }

    /**
     * Get asset optimization stats
     *
     * @since 1.0.0
     *
     * @return array Optimization statistics
     */
    public function get_stats(): array {
        return [
            'preloading_enabled' => $this->is_thinkrank_admin_page(),
            'dns_prefetch_domains' => 3,
            'optimized_pages' => ['thinkrank admin pages']
        ];
    }
}
