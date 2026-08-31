<?php
/**
 * What removing ThinkRank has to clean up.
 *
 * Deactivation and uninstall both need the cron-hook and capability lists, and
 * uninstall runs with no autoloader — `WP_UNINSTALL_PLUGIN` loads `uninstall.php`
 * on its own, without the plugin. So the lists live here as a plain array this
 * file returns, which both paths `require`, rather than as three hand-maintained
 * copies that drift (#389, #399).
 *
 * Deliberately nothing in the `thinkrank_pro_` namespace: ThinkRank Pro creates
 * those hooks and ships its own uninstaller, and removing only the free plugin
 * must not tear down a still-active Pro install (#384).
 *
 * @package ThinkRank
 * @since 2.0.1
 */

declare(strict_types=1);

// Prevent direct access. Reached only via require() from the deactivator and
// uninstall.php, both of which run with WordPress loaded, so this costs the
// callers nothing — it was the one tracked file in the plugin without the
// guard, which Plugin Check flags as direct file access (#517).
if (!defined('ABSPATH')) {
    exit;
}

return [
    /*
     * Every hook the free plugin schedules. Clearing one that was never
     * scheduled is a no-op, so a hook that only some configurations reach is
     * still safe to list — and cheaper than discovering it survived.
     *
     * Keep in sync with the wp_schedule_event() / wp_schedule_single_event()
     * call sites; CleanupManifestTest fails when a new one appears.
     */
    'cron_hooks' => [
        'thinkrank_cache_cleanup',
        'thinkrank_daily_cleanup',
        'thinkrank_usage_analytics',
        'thinkrank_put_do_daily_action',
        // Google token refresh. Scheduled on the custom `thinkrank_45min`
        // recurrence, whose cron_schedules filter disappears with the plugin —
        // so a survivor here is a recurring event WordPress can no longer
        // resolve an interval for.
        'thinkrank_google_token_refresh',
        'thinkrank_collect_performance_data',
        'thinkrank_regenerate_sitemap',
        'thinkrank_regenerate_sitemap_settings',
        'thinkrank_ai_traffic_prune',
        'thinkrank_auto_ai_optimize',
        'thinkrank_instant_indexing_reconcile',
        'thinkrank_instant_indexing_submit',
        'thinkrank_email_report_tick',
        'thinkrank_bv_tick',
        'thinkrank_bv_watchdog',
    ],

    /*
     * Every capability the plugin grants, including the pre-Role-Manager slugs
     * older versions handed out, so a historical role is cleaned too.
     *
     * Keep in sync with ThinkRank\Core\Capability_Manager::capabilities();
     * CleanupManifestTest pins the two together.
     */
    'capabilities' => [
        'thinkrank_access',
        'thinkrank_site_identity',
        'thinkrank_analytics',
        'thinkrank_performance',
        'thinkrank_global_seo',
        'thinkrank_image_seo',
        'thinkrank_schema',
        'thinkrank_social_media',
        'thinkrank_crawling',
        'thinkrank_instant_indexing',
        'thinkrank_author_archives',
        'thinkrank_content_tools',
        'thinkrank_ai_insights',
        'thinkrank_internal_links',
        'thinkrank_redirections',
        'thinkrank_broken_links',
        'thinkrank_woocommerce',
        'thinkrank_settings',
        'thinkrank_manage_roles',
        // Legacy slugs (pre-Role Manager).
        'thinkrank_manage_settings',
        'thinkrank_view_analytics',
        'thinkrank_manage_credits',
        'thinkrank_use_ai_features',
    ],
];
