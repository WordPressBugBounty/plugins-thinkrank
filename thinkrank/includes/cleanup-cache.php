<?php
/**
 * Flushing ThinkRank's object-cache groups when the plugin is removed.
 *
 * A plain prefixed function rather than a class method, because `uninstall.php`
 * runs with no autoloader and the deactivator needs the same logic. Same
 * reasoning as {@see includes/cleanup-webroot.php}: one copy, not two.
 *
 * @package ThinkRank
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('thinkrank_flush_cache_groups')) {
    /**
     * Flush every object-cache group listed in the cleanup manifest.
     *
     * Uninstall and deactivation used to flush only `thinkrank`, while the SEO
     * module settings live in `thinkrank_seo` with no expiry, so on a site with
     * a persistent object cache a delete-data uninstall followed by a reinstall
     * served the old settings straight back (#1328).
     *
     * When the cache cannot flush a single group — WordPress 6.0, which the
     * plugin still supports, or a drop-in that does not implement it — the
     * caller decides whether to fall back to flushing the whole cache. Uninstall
     * does: stale settings after a delete-data uninstall are the defect, and an
     * uninstall is a one-off. Deactivation does not: the settings are kept, so
     * there is nothing stale to serve, and emptying every other plugin's cache
     * for that would be out of proportion.
     *
     * @param string[] $groups              Cache groups to flush.
     * @param bool     $full_flush_fallback Flush the whole cache when groups
     *                                      cannot be flushed one by one.
     * @return void
     */
    function thinkrank_flush_cache_groups(array $groups, bool $full_flush_fallback): void {
        // wp_cache_flush_group() exists from 6.1, but on a drop-in without
        // group support core's version only raises _doing_it_wrong and returns
        // false, so the function's existence alone is not enough.
        $groups_supported = function_exists('wp_cache_flush_group')
            && function_exists('wp_cache_supports')
            && wp_cache_supports('flush_group');

        if ($groups_supported) {
            foreach ($groups as $group) {
                wp_cache_flush_group($group);
            }

            return;
        }

        if ($full_flush_fallback) {
            wp_cache_flush();
        }
    }
}
