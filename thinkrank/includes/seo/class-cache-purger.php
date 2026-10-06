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
     * A cache this class can drop one URL from.
     *
     * @since 2.14.0
     * @var string
     */
    public const PURGE_URL = 'url';

    /**
     * A cache whose narrowest purge is one post.
     *
     * Three of the integrations below expose a per-post hook and no per-URL
     * one. They are purged from `purge_posts()`, which has the post ID, and
     * cannot be reached by `purge_urls()` alone: an llms.txt document has no
     * post behind it. That difference is what `$target` reports on, rather
     * than a flat "purgeable" that would be true on one path and false on the
     * other while saying the same thing to both.
     *
     * @since 2.14.0
     * @var string
     */
    public const PURGE_POST = 'post';

    /**
     * A cache that cannot be purged from here at all.
     *
     * @since 2.14.0
     * @var string
     */
    public const PURGE_NONE = 'none';

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
     * Caching plugins ThinkRank knows about, and how narrowly it can purge
     * each one.
     *
     * Detection is a presence check on the plugin's own constant or class, not
     * on its purge function: a plugin that loads after this one would report as
     * absent, and the plugin that matters most for the warning is the one that
     * exposes no purge API at all.
     *
     * Every entry here was read from the plugin's own source at the version
     * named, not from its documentation. Five of them were marked unpurgeable
     * on the strength of the documentation alone and were wrong (#883).
     *
     * @since 2.12.0
     * @return array<string, array{detect: callable, purge: string}>
     */
    private static function known_caches(): array {
        return [
            'WP Rocket'            => [
                'detect' => static fn(): bool => defined('WP_ROCKET_VERSION'),
                'purge'  => self::PURGE_URL,
            ],
            'W3 Total Cache'       => [
                'detect' => static fn(): bool => defined('W3TC'),
                'purge'  => self::PURGE_URL,
            ],
            'WP Super Cache'       => [
                'detect' => static fn(): bool => defined('WPCACHEHOME'),
                'purge'  => self::PURGE_URL,
            ],
            'LiteSpeed Cache'      => [
                'detect' => static fn(): bool => defined('LSCWP_V'),
                'purge'  => self::PURGE_URL,
            ],
            'Nginx Helper'         => [
                'detect' => static fn(): bool => class_exists('Nginx_Helper'),
                'purge'  => self::PURGE_URL,
            ],
            // Cache Enabler 1.8.17 registers both of its clear hooks in
            // Cache_Enabler::init(): `cache_enabler_clear_page_cache_by_url`
            // runs clear_page_cache_by_url(). The hook is the published API and
            // survives the 1.8.0 deprecation of clear_page_cache_by_post_id(),
            // so this integrates against the hook rather than the class.
            'Cache Enabler'        => [
                'detect' => static fn(): bool => class_exists('Cache_Enabler'),
                'purge'  => self::PURGE_URL,
            ],
            // SiteGround Optimizer 7.8.3 exposes
            // Supercacher::purge_cache_request($url) as a public static. It has
            // no published hook, so the method is called directly, guarded.
            'SiteGround Optimizer' => [
                'detect' => static fn(): bool => class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher'),
                'purge'  => self::PURGE_URL,
            ],
            // The three below purge a post, not a URL. See PURGE_POST.
            //
            // WP Fastest Cache 1.5.2 listens on `wpfc_clear_post_cache_by_id`
            // with singleDeleteCache($comment_id, $post_id, $clear_parents),
            // so the post ID is the second argument and the first is unused.
            'WP Fastest Cache'     => [
                'detect' => static fn(): bool => defined('WPFC_MAIN_PATH') || class_exists('WpFastestCache'),
                'purge'  => self::PURGE_POST,
            ],
            // Hummingbird 3.21.2 listens on `wphb_clear_page_cache` with
            // clear_cache_action($post_id), which purges that post when given
            // an ID and the whole cache when given nothing. It is always given
            // an ID here.
            'Hummingbird'          => [
                'detect' => static fn(): bool => defined('WPHB_VERSION'),
                'purge'  => self::PURGE_POST,
            ],
            // Breeze 2.6.0 listens on `purge_post_cache` with
            // purge_post_cache($post_id). The hook name carries no vendor
            // prefix, which is Breeze's choice and not something this can fix;
            // it is fired with a post ID, which is the only shape Breeze reads.
            'Breeze'               => [
                'detect' => static fn(): bool => defined('BREEZE_VERSION'),
                'purge'  => self::PURGE_POST,
            ],
            // Cloudflare stays unpurgeable, and is the only one that does.
            // Its plugin purges on its own post events through
            // Hooks::purgeCacheByRelevantURLs(), an internal method with no
            // published hook in front of it, so there is nothing to call that
            // is not reaching into its internals.
            'Cloudflare'           => [
                'detect' => static fn(): bool => class_exists('CF\\WordPress\\Hooks'),
                'purge'  => self::PURGE_NONE,
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
            do_action('cache_enabler_clear_page_cache_by_url', $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

            if (function_exists('w3tc_flush_url')) {
                w3tc_flush_url($url);
            }
            if (function_exists('wpsc_delete_url_cache')) {
                wpsc_delete_url_cache($url);
            }
            // SiteGround publishes no hook for this, so the static is called
            // directly. It returns early on its own when the site is not on
            // SiteGround and file caching is off, so calling it unconditionally
            // costs nothing on a site that merely has the plugin installed.
            if (is_callable(['SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache_request'])) {
                \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache_request($url);
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

            // Caches whose narrowest purge is a post, not a URL. Fired here
            // rather than in purge_urls() because this is the only path that
            // has a post ID; see PURGE_POST. Each is a no-op when the plugin
            // that listens for it is absent.
            // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook names ThinkRank fires, not ours to prefix.
            do_action('wpfc_clear_post_cache_by_id', false, $post_id);
            do_action('wphb_clear_page_cache', $post_id);
            do_action('purge_post_cache', $post_id);
            // phpcs:enable

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
     * @since 2.14.0 Takes the kind of purge being reported on.
     *
     * @param string $target What the caller purged: self::PURGE_POST when it
     *                       had a post ID (the default, and what every caller
     *                       in the plugin passes today), or self::PURGE_URL for
     *                       a URL with no post behind it, such as llms.txt. A
     *                       post-granularity cache is unreachable in the second
     *                       case and is listed there and not in the first.
     * @return string[] Plugin names, in the order they are declared.
     */
    public static function unpurgeable_caches(string $target = self::PURGE_POST): array {
        $names = [];

        foreach (self::known_caches() as $name => $cache) {
            $purge = $cache['purge'];

            if (self::PURGE_URL === $purge) {
                continue;
            }

            // A per-post cache is purged on the post path and missed on the
            // URL path, so only the URL path warns about it.
            if (self::PURGE_POST === $purge && self::PURGE_POST === $target) {
                continue;
            }

            if (($cache['detect'])()) {
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
    public static function warnings(string $target = self::PURGE_POST): array {
        $unpurgeable = self::unpurgeable_caches($target);

        if ([] === $unpurgeable) {
            return [];
        }

        // Two plugins read "A, B is active and offers" in the single-string
        // version this replaces.
        return [
            sprintf(
                /* translators: %s: comma-separated list of caching plugin names. */
                _n(
                    '%s is active and offers no way to clear a single page from here. Clear its cache to see the change on the front end.',
                    '%s are active and offer no way to clear a single page from here. Clear their caches to see the change on the front end.',
                    count($unpurgeable),
                    'thinkrank'
                ),
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
