<?php
/**
 * Ask page and CDN caches to drop their copy of one URL.
 *
 * @package ThinkRank\Core
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page Cache
 *
 * A cached page outlives a change to what it serves, so anything that changes
 * a page's head without saving the post — publishing llms.txt, switching a
 * Title Experiment variant (#719) — has to ask the cache layers to drop it.
 * Every call is guarded: a site running none of them simply gets the action
 * hook, which other integrations can listen to.
 *
 * Extracted from the llms.txt publisher, which did this for one URL only.
 *
 * @since 2.22.0
 */
final class Page_Cache {

    /**
     * Purge one URL from the common page caches.
     *
     * @param string $url Public URL.
     * @return void
     */
    public static function purge_url(string $url): void {
        if ('' === $url) {
            return;
        }

        /**
         * Fires when ThinkRank changed what a URL serves, so cache layers it
         * does not know about can drop their copy.
         *
         * @since 2.22.0
         *
         * @param string $url Public URL.
         */
        do_action('thinkrank_purge_url', $url);

        // LiteSpeed Cache listens on its own action. A third-party hook name
        // we fire, not ours to prefix.
        do_action('litespeed_purge_url', $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        // Nginx Helper's only action purges the whole cache, which is far too
        // much for one page whose title changed. Its purger object has a
        // per-URL method, so use that when the plugin set one up.
        global $nginx_purger;
        if (is_object($nginx_purger) && method_exists($nginx_purger, 'purge_url')) {
            $nginx_purger->purge_url($url, false);
        }

        if (function_exists('rocket_clean_files')) {
            rocket_clean_files([$url]);
        }
        if (function_exists('w3tc_flush_url')) {
            w3tc_flush_url($url);
        }
        if (function_exists('wpsc_delete_url_cache')) {
            wpsc_delete_url_cache($url);
        }
    }

    /**
     * Purge a post's page, and WordPress's own cache of the post.
     *
     * @param int $post_id Post id.
     * @return void
     */
    public static function purge_post(int $post_id): void {
        clean_post_cache($post_id);

        $url = get_permalink($post_id);
        if (is_string($url)) {
            self::purge_url($url);
        }
    }
}
