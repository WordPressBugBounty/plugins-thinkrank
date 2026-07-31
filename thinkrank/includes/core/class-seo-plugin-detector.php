<?php
/**
 * SEO Plugin Detector
 * 
 * Simple detection of popular SEO plugins
 * 
 * @package ThinkRank\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Plugin Detector Class
 * 
 * Single Responsibility: Detect popular SEO plugins
 * 
 * @since 1.0.0
 */
class SEO_Plugin_Detector {
    
    /**
     * Known SEO plugins
     * 
     * @var array
     */
    private const KNOWN_PLUGINS = [
        'yoast' => [
            'name' => 'Yoast SEO',
            'class' => 'WPSEO',
            'function' => 'wpseo_init',
            'file' => 'wordpress-seo/wp-seo.php',
            'extra_files' => ['wordpress-seo-premium/wp-seo-premium.php'],
            'importable' => true,
        ],
        'rankmath' => [
            'name' => 'Rank Math',
            'class' => 'RankMath',
            'function' => 'rank_math',
            'file' => 'seo-by-rank-math/rank-math.php',
            'extra_files' => ['seo-by-rank-math-pro/rank-math-pro.php'],
            'importable' => true,
        ],
        'seopress' => [
            'name' => 'SEOPress',
            'class' => 'SEOPress',
            'function' => 'seopress_init',
            'file' => 'wp-seopress/seopress.php',
            'extra_files' => ['wp-seopress-pro/seopress-pro.php'],
            'importable' => true,
        ],
        'aioseo' => [
            'name' => 'All in One SEO',
            'class' => 'AIOSEO',
            'function' => 'aioseo',
            'file' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'extra_files' => ['all-in-one-seo-pack-pro/all_in_one_seo_pack.php'],
            'importable' => true,
        ],
        'theseoframework' => [
            'name' => 'The SEO Framework',
            'class' => 'The_SEO_Framework\\Load',
            'function' => 'the_seo_framework',
            'file' => 'autodescription/autodescription.php',
            'extra_files' => [],
            'importable' => false,
        ],
    ];
    
    /**
     * Detected plugins cache
     * 
     * @var array|null
     */
    private static ?array $detected_plugins = null;
    
    /**
     * Detect active SEO plugins
     *
     * @return array Detected plugins
     */
    public static function detect_plugins(): array {
        if (self::$detected_plugins !== null) {
            return self::$detected_plugins;
        }

        self::$detected_plugins = [];

        foreach (self::KNOWN_PLUGINS as $slug => $plugin) {
            if (self::is_plugin_active($plugin)) {
                self::$detected_plugins[$slug] = $plugin;
            }
        }

        return self::$detected_plugins;
    }
    
    /**
     * Check if any SEO plugins are active
     * 
     * @return bool True if SEO plugins detected
     */
    public static function has_seo_plugins(): bool {
        return !empty(self::detect_plugins());
    }
    
    /**
     * Get detected plugin names
     * 
     * @return array Plugin names
     */
    public static function get_plugin_names(): array {
        $detected = self::detect_plugins();
        return array_column($detected, 'name');
    }
    
    /**
     * Check if a specific plugin is active
     * 
     * @param string $slug Plugin slug
     * @return bool True if plugin is active
     */
    public static function is_plugin_detected(string $slug): bool {
        $detected = self::detect_plugins();
        return isset($detected[$slug]);
    }
    
    /**
     * Check if a plugin is active
     * 
     * @param array $plugin Plugin configuration
     * @return bool True if plugin is active
     */
    private static function is_plugin_active(array $plugin): bool {
        // Check by class
        if (!empty($plugin['class']) && class_exists($plugin['class'])) {
            return true;
        }
        
        // Check by function
        if (!empty($plugin['function']) && function_exists($plugin['function'])) {
            return true;
        }
        
        // Check by plugin file
        if (!empty($plugin['file']) && function_exists('is_plugin_active') && is_plugin_active($plugin['file'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get admin notice message
     *
     * @return string Notice message
     */
    public static function get_notice_message(): string {
        if (!self::has_seo_plugins()) {
            return '';
        }

        return __('Running more than one SEO plugin can create conflicts — duplicate meta tags, sitemaps, and schema markup that may hurt your search rankings. To avoid any SEO-related conflicts, we recommend deactivating third-party SEO plugins.', 'thinkrank');
    }

    /**
     * Whether any detected plugin's SEO data can be imported into ThinkRank.
     *
     * @return bool True if at least one detected plugin has an importer
     */
    public static function has_importable_plugin(): bool {
        foreach (self::detect_plugins() as $plugin) {
            if (!empty($plugin['importable'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the human-readable name for a known plugin slug.
     *
     * Falls back to the slug itself when unknown, so callers always have a
     * displayable label.
     *
     * @param string $slug Plugin slug from KNOWN_PLUGINS
     * @return string Plugin name (or the slug if unknown)
     */
    public static function get_plugin_name(string $slug): string {
        return self::KNOWN_PLUGINS[$slug]['name'] ?? $slug;
    }

    /**
     * Get the active plugin files to deactivate for a detected plugin.
     *
     * Resolves the plugin file(s) — including premium companions such as
     * Rank Math Pro — server-side from the known-plugins map, so callers
     * never pass arbitrary plugin paths to deactivate_plugins().
     *
     * @param string $slug Plugin slug from KNOWN_PLUGINS
     * @return array Active plugin file paths (may be empty)
     */
    public static function get_deactivatable_files(string $slug): array {
        if (!isset(self::KNOWN_PLUGINS[$slug]) || !self::is_plugin_detected($slug)) {
            return [];
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin = self::KNOWN_PLUGINS[$slug];
        $files = array_merge([$plugin['file']], $plugin['extra_files'] ?? []);

        return array_values(array_filter($files, 'is_plugin_active'));
    }
    
    /**
     * Should show admin notice
     *
     * @return bool True if should show notice
     */
    public static function should_show_notice(): bool {
        // Only show if SEO plugins detected
        if (!self::has_seo_plugins()) {
            return false;
        }

        // Show on admin pages (not just ThinkRank pages)
        if (!is_admin()) {
            return false;
        }

        // Check if user dismissed the notice
        $dismissed = get_user_meta(get_current_user_id(), 'thinkrank_seo_notice_dismissed', true);
        if ($dismissed) {
            return false;
        }

        return true;
    }
    
    /**
     * Dismiss notice for current user
     * 
     * @return void
     */
    public static function dismiss_notice(): void {
        update_user_meta(get_current_user_id(), 'thinkrank_seo_notice_dismissed', true);
    }
    
    /**
     * Reset notice dismissal (for testing)
     * 
     * @return void
     */
    public static function reset_notice(): void {
        delete_user_meta(get_current_user_id(), 'thinkrank_seo_notice_dismissed');
    }
}
