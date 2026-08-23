<?php
/**
 * Template Functions for ThinkRank SEO
 * 
 * Helper functions for themes to display ThinkRank SEO elements
 * 
 * @package ThinkRank\Frontend
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display ThinkRank breadcrumbs
 * 
 * Usage in theme templates:
 * <?php thinkrank_breadcrumbs(); ?>
 * 
 * @since 1.0.0
 * 
 * @param array $args Optional. Arguments to customize breadcrumb display
 * @return void
 */
function thinkrank_breadcrumbs($args = []) {
    /**
     * Fires to display ThinkRank breadcrumbs
     * 
     * @since 1.0.0
     * 
     * @param array $args Breadcrumb display arguments
     */
    do_action('thinkrank_breadcrumbs', $args);
}

/**
 * Get ThinkRank breadcrumbs data without displaying
 *
 * @since 1.0.0
 *
 * @return array|null Breadcrumb data or null if not available
 */
function thinkrank_get_breadcrumbs() {
    // Check if ThinkRank is loaded
    if (!function_exists('thinkrank')) {
        return null;
    }

    $plugin = thinkrank();
    if (!$plugin) {
        return null;
    }

    $seo_manager = $plugin->get_component('frontend_seo');

    if (!$seo_manager || !method_exists($seo_manager, 'get_current_breadcrumbs')) {
        return null;
    }

    return $seo_manager->get_current_breadcrumbs();
}

/**
 * Display the ThinkRank hero section (Site Identity → Hero & Branding)
 *
 * Usage in theme templates:
 * <?php thinkrank_hero(); ?>
 *
 * Also available as the [thinkrank_hero] shortcode for use in content.
 *
 * @since 1.19.0
 *
 * @param array $args Optional. Reserved for future display arguments.
 * @return void
 */
function thinkrank_hero($args = []) {
    /**
     * Fires to display the ThinkRank hero section.
     *
     * @since 1.19.0
     *
     * @param array $args Hero display arguments.
     */
    do_action('thinkrank_hero', $args);
}

/**
 * Get the ThinkRank hero section data without displaying it
 *
 * @since 1.19.0
 *
 * @return array|null Hero data (title, subtitle, cta_text, cta_url,
 *                    background_image, html) or null if not available
 */
function thinkrank_get_hero() {
    // Check if ThinkRank is loaded
    if (!function_exists('thinkrank')) {
        return null;
    }

    $plugin = thinkrank();
    if (!$plugin) {
        return null;
    }

    $seo_manager = $plugin->get_component('frontend_seo');

    if (!$seo_manager || !method_exists($seo_manager, 'get_current_hero')) {
        return null;
    }

    return $seo_manager->get_current_hero();
}

/**
 * Check if ThinkRank SEO is handling the current page
 *
 * @since 1.0.0
 *
 * @return bool True if ThinkRank is handling SEO for current page
 */
function thinkrank_is_seo_active() {
    // Check if ThinkRank is loaded
    if (!function_exists('thinkrank')) {
        return false;
    }

    $plugin = thinkrank();
    if (!$plugin) {
        return false;
    }

    $seo_manager = $plugin->get_component('frontend_seo');

    if (!$seo_manager || !method_exists($seo_manager, 'has_seo_data')) {
        return false;
    }

    return $seo_manager->has_seo_data();
}

/**
 * Get current page SEO metadata
 *
 * @since 1.0.0
 *
 * @return array Current SEO metadata
 */
function thinkrank_get_seo_metadata() {
    // Check if ThinkRank is loaded
    if (!function_exists('thinkrank')) {
        return [];
    }

    $plugin = thinkrank();
    if (!$plugin) {
        return [];
    }

    $seo_manager = $plugin->get_component('frontend_seo');

    if (!$seo_manager || !method_exists($seo_manager, 'get_current_metadata')) {
        return [];
    }

    return $seo_manager->get_current_metadata();
}

/**
 * Get breadcrumb inline CSS
 * 
 * @since 1.0.0
 * 
 * @return string CSS styles for breadcrumbs
 */
function thinkrank_get_breadcrumb_css(): string {
    return '
    .thinkrank-breadcrumbs {
        margin: 1rem 0;
        font-size: 0.9rem;
    }
    .thinkrank-breadcrumbs .breadcrumb-prefix {
        font-weight: 600;
        margin-right: 0.5rem;
    }
    .thinkrank-breadcrumbs .breadcrumb-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
    }
    .thinkrank-breadcrumbs .breadcrumb-item {
        margin: 0;
        padding: 0;
        display: flex;
        align-items: center;
    }
    .thinkrank-breadcrumbs .breadcrumb-item a {
        color: #0073aa;
        text-decoration: none;
    }
    .thinkrank-breadcrumbs .breadcrumb-item a:hover {
        text-decoration: underline;
    }
    .thinkrank-breadcrumbs .breadcrumb-item.current span {
        color: #666;
        font-weight: 500;
    }
    .thinkrank-breadcrumbs .breadcrumb-separator {
        margin: 0 0.5rem;
        color: #999;
    }';
}

// Enqueue breadcrumb styles properly if breadcrumbs are enabled
add_action('wp_enqueue_scripts', function() {
    // Check if ThinkRank is loaded
    if (!function_exists('thinkrank')) {
        return;
    }

    $plugin = thinkrank();
    if (!$plugin) {
        return;
    }

    $seo_manager = $plugin->get_component('frontend_seo');
    if (!$seo_manager) {
        return;
    }

    // Both checks below read the site_identity settings category. Loading the
    // full Site_Identity_Manager (115KB) on every front-end page to get them
    // would be worse, but the two ad-hoc queries that replaced it did not share
    // the object cache the manager populates on `wp` — so the category was
    // fetched twice per request, uncached, on top of the manager's own read
    // (#402).
    //
    // Read that cache entry directly. Abstract_SEO_Manager caches the merged
    // settings under this key on every get_settings('site') call, and `wp` runs
    // before wp_enqueue_scripts, so on a normal front-end render this is a hit
    // and costs nothing. The fallback is one query for the whole category
    // rather than two for parts of it.
    $identity = wp_cache_get('seo_settings_site_identity_site_0', 'thinkrank_seo');

    if (!is_array($identity)) {
        global $wpdb;
        $table = $wpdb->prefix . 'thinkrank_seo_settings';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lightweight frontend check avoids loading heavy manager class
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses WordPress prefix, safe to interpolate
                "SELECT setting_key, setting_value FROM `{$table}` WHERE context_type = %s AND context_id = %d AND setting_category = %s AND is_active = 1",
                'site',
                0,
                'site_identity'
            ),
            ARRAY_A
        );

        $identity = [];
        foreach ((array) $rows as $row) {
            $identity[$row['setting_key']] = $row['setting_value'];
        }

        // Deliberately not written back to the object cache: the manager caches
        // the values *merged with its defaults* under this key, and seeding it
        // with raw rows would hand every later reader a partial record.
    }

    $identity_value = static function (string $key) use ($identity): string {
        return isset($identity[$key]) ? trim((string) $identity[$key]) : '';
    };

    if (!empty($identity['breadcrumbs_enabled'])) {
        wp_enqueue_style(
            'thinkrank-breadcrumbs',
            THINKRANK_PLUGIN_URL . 'static/css/breadcrumbs.css',
            [],
            THINKRANK_VERSION
        );
    }

    // Hero check — only load hero CSS when the hero will actually render.
    // Mirrors the render gate in SEO_Manager::generate_hero_html(): a title, a
    // subtitle, or a COMPLETE CTA (both text and URL). A CTA with text but no
    // URL renders nothing, so it must not pull in the stylesheet.
    $hero_renders = '' !== $identity_value('hero_title')
        || '' !== $identity_value('hero_subtitle')
        || ('' !== $identity_value('hero_cta_text') && '' !== $identity_value('hero_cta_url'));

    if ($hero_renders) {
        wp_enqueue_style(
            'thinkrank-hero',
            THINKRANK_PLUGIN_URL . 'static/css/hero.css',
            [],
            THINKRANK_VERSION
        );
    }
});
