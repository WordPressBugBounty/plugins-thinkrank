<?php

/**
 * Cache purging for content ThinkRank rewrites underneath a cache.
 *
 * ThinkRank writes values that other software has already rendered into HTML
 * and stored. Alt text is the clearest case: `update-image-alt-text` writes
 * `_wp_attachment_image_alt` and returns success, while the page a visitor
 * loads still carries the old words because Elementor kept the rendered widget
 * in post meta and a page cache kept the whole document. The write is correct
 * and invisible, which is the worst possible combination for an agent that
 * reports back to a user (#763).
 *
 * Two layers are dealt with here:
 *
 *  - **Builder output caches.** Elementor stores rendered elements per document
 *    in `_elementor_element_cache` and reuses them for up to 24 hours. Element
 *    caching is on unless the site turns it off: `Document::print_elements()`
 *    treats any value of `elementor_element_cache_ttl` except the literal
 *    `disable` as enabled, and the option does not exist on a default install.
 *    Elementor's own public API for this is `files_manager->clear_cache()`,
 *    which also deletes every generated CSS file on the site. Deleting the one
 *    meta key on the affected documents is the narrow equivalent, and it is
 *    what Elementor's protected `Document::delete_cache()` does.
 *  - **Page and CDN caches.** The same set `LLMs_Txt_Manager` has purged since
 *    2.1.0, moved here so more than one feature can reach it.
 *
 * What cannot be purged is reported rather than ignored. A site behind Varnish,
 * a CDN, or a caching plugin with no per-URL API will keep serving the old
 * markup no matter what this class does, and the caller needs to be told that
 * so it can tell the user to clear it by hand instead of insisting the change
 * has gone live.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.12.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Drops cached copies of pages ThinkRank has just changed.
 *
 * @since 2.12.0
 */
class Cache_Purger {

    /**
     * Elementor's per-document rendered-element cache.
     *
     * Mirrors `Elementor\Core\Base\Document::CACHE_META_KEY`. The literal is
     * used rather than the constant because Elementor may not be loaded, and
     * because reaching into another plugin's class for a meta key is a harder
     * dependency than repeating the string with this note attached.
     *
     * @since 2.12.0
     * @var string
     */
    public const ELEMENTOR_CACHE_META_KEY = '_elementor_element_cache';

    /**
     * Post IDs already purged during this request.
     *
     * A library-wide alt fill touches hundreds of attachments that between them
     * appear on a handful of pages, so without this the same document is
     * cleared once per image. Per-request only: nothing here needs to survive
     * into the next one, and a stale memory of "already purged" across requests
     * would be a correctness bug rather than an optimisation.
     *
     * @since 2.12.0
     * @var array<int, bool>
     */
    private static array $purged = [];

    /**
     * Caching plugins ThinkRank knows about, and whether it can purge them.
     *
     * Detection is a presence check on the plugin's own constant or class, not
     * on its purge function: a plugin that loads after this one would report as
     * absent, and the plugins that matter most for the warning are the ones
     * that expose no purge function at all.
     *
     * @since 2.12.0
     * @return array<string, array{detect: callable, purgeable: bool}>
     */
    private static function known_caches(): array {
        return [
            'WP Rocket'            => [
                'detect'    => static fn(): bool => defined('WP_ROCKET_VERSION'),
                'purgeable' => true,
            ],
            'W3 Total Cache'       => [
                'detect'    => static fn(): bool => defined('W3TC'),
                'purgeable' => true,
            ],
            'WP Super Cache'       => [
                'detect'    => static fn(): bool => defined('WPCACHEHOME'),
                'purgeable' => true,
            ],
            'LiteSpeed Cache'      => [
                'detect'    => static fn(): bool => defined('LSCWP_V'),
                'purgeable' => true,
            ],
            'Nginx Helper'         => [
                'detect'    => static fn(): bool => class_exists('Nginx_Helper'),
                'purgeable' => true,
            ],
            // Everything below is detected but not purgeable from here. Each
            // one either exposes no per-URL API or needs credentials ThinkRank
            // does not hold, so the honest answer is a warning to the caller.
            'WP Fastest Cache'     => [
                'detect'    => static fn(): bool => defined('WPFC_MAIN_PATH') || class_exists('WpFastestCache'),
                'purgeable' => false,
            ],
            'SiteGround Optimizer' => [
                'detect'    => static fn(): bool => class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher'),
                'purgeable' => false,
            ],
            'Cache Enabler'        => [
                'detect'    => static fn(): bool => class_exists('Cache_Enabler'),
                'purgeable' => false,
            ],
            'Breeze'               => [
                'detect'    => static fn(): bool => defined('BREEZE_VERSION'),
                'purgeable' => false,
            ],
            'Hummingbird'          => [
                'detect'    => static fn(): bool => defined('WPHB_VERSION'),
                'purgeable' => false,
            ],
            'Cloudflare'           => [
                'detect'    => static fn(): bool => class_exists('CF\\WordPress\\Hooks'),
                'purgeable' => false,
            ],
        ];
    }

    /**
     * Ask every cache layer to drop its copy of the given URLs.
     *
     * Every integration is guarded, so a site running none of them simply gets
     * the action hook, which other integrations can listen on.
     *
     * @since 2.12.0
     * @param string[] $urls Absolute URLs to purge.
     * @return void
     */
    public static function purge_urls(array $urls): void {
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));

        if ([] === $urls) {
            return;
        }

        /**
         * Fires when ThinkRank changes what a URL serves.
         *
         * Cache layers ThinkRank does not know about can listen here and drop
         * their copy.
         *
         * @since 2.12.0
         *
         * @param string[] $urls Absolute URLs whose content has changed.
         */
        do_action('thinkrank_purge_urls', $urls);

        // Each integration is guarded by its own API rather than by the
        // detector above, so a plugin whose constant this file guesses wrong
        // still gets purged. The detector decides only what to warn about.
        // These are third-party hook names ThinkRank fires, not ours to prefix.
        foreach ($urls as $url) {
            do_action('litespeed_purge_url', $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

            if (function_exists('w3tc_flush_url')) {
                w3tc_flush_url($url);
            }
            if (function_exists('wpsc_delete_url_cache')) {
                wpsc_delete_url_cache($url);
            }
        }

        // Nginx Helper only exposes a purge-everything hook, and WP Rocket
        // takes the whole list at once, so both run once per call.
        do_action('rt_nginx_helper_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        if (function_exists('rocket_clean_files')) {
            rocket_clean_files($urls);
        }
    }

    /**
     * Drop cached renderings of specific posts, then their public URLs.
     *
     * @since 2.12.0
     * @param int[] $post_ids Posts whose rendered output is now out of date.
     * @return int[] The post IDs actually purged in this request.
     */
    public static function purge_posts(array $post_ids): array {
        $purged = [];
        $urls   = [];

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;

            if ($post_id <= 0 || isset(self::$purged[$post_id])) {
                continue;
            }

            self::$purged[$post_id] = true;
            $purged[]               = $post_id;

            // Elementor rebuilds the document on the next front-end request
            // once the meta is gone. Deleting it is safe on a post Elementor
            // never touched: the key simply is not there.
            delete_post_meta($post_id, self::ELEMENTOR_CACHE_META_KEY);

            clean_post_cache($post_id);

            $permalink = get_permalink($post_id);
            if (is_string($permalink) && '' !== $permalink) {
                $urls[] = $permalink;
            }
        }

        if ([] !== $urls) {
            self::purge_urls($urls);
        }

        return $purged;
    }

    /**
     * Caching layers that are installed but cannot be purged from here.
     *
     * @since 2.12.0
     * @return string[] Plugin names, in the order they are declared.
     */
    public static function unpurgeable_caches(): array {
        $names = [];

        foreach (self::known_caches() as $name => $cache) {
            if (!$cache['purgeable'] && ($cache['detect'])()) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Sentences describing what a caller still has to clear by hand.
     *
     * Returned to the MCP abilities so an agent can pass the caveat on instead
     * of reporting an unqualified success the visitor cannot see yet.
     *
     * @since 2.12.0
     * @return string[] Empty when everything detected was purged.
     */
    public static function warnings(): array {
        $unpurgeable = self::unpurgeable_caches();

        if ([] === $unpurgeable) {
            return [];
        }

        return [
            sprintf(
                /* translators: %s: comma-separated list of caching plugin names. */
                __('%s is active and offers no way to clear a single page from here. Clear its cache to see the change on the front end.', 'thinkrank'),
                implode(', ', $unpurgeable)
            ),
        ];
    }

    /**
     * Forget which posts were purged this request.
     *
     * Only the test suite needs this: a single web request never purges the
     * same post twice on purpose.
     *
     * @since 2.12.0
     * @return void
     */
    public static function reset(): void {
        self::$purged = [];
    }
}
