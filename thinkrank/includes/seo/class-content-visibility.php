<?php
/**
 * Per-post exclusion from this site's own search results and archive listings.
 *
 * @package ThinkRank
 * @since   2.7.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps chosen posts out of on-site search and archive listings.
 *
 * **This is not noindex**, and the distinction is the whole feature. Per-post
 * noindex already exists in the metabox and tells search engines what to do.
 * This tells *this site* what to do: a thank-you page, an outdated announcement
 * kept only for its links, a landing page that should never turn up when a
 * visitor searches the site. The post stays published and reachable at its own
 * URL; it simply stops appearing in lists.
 *
 * Two separate switches, because the two cases genuinely differ. A thank-you
 * page should be out of both. An old announcement kept for link equity might
 * reasonably stay findable by search while dropping out of the category feed.
 *
 * Also not to be confused with the sitemap's `exclude_posts`, which is a
 * site-wide comma-separated list controlling what gets published in
 * `sitemap.xml`. Same word, unrelated mechanism, and a reason the UI wording
 * here avoids "exclude" entirely.
 *
 * On performance: the excluded set is fetched once per request per kind and
 * cached, so a listing page costs one extra query at most and usually none.
 * Asking `post__not_in` to carry a meta query per request would put the cost on
 * every archive view of every site, whether or not anything is excluded.
 *
 * @since 2.7.0
 */
class Content_Visibility {

    /**
     * Meta key: keep this post out of on-site search results.
     *
     * @since 2.7.0
     * @var string
     */
    public const SEARCH_META = '_thinkrank_exclude_from_search';

    /**
     * Meta key: keep this post out of archive listings.
     *
     * @since 2.7.0
     * @var string
     */
    public const ARCHIVE_META = '_thinkrank_exclude_from_archives';

    /**
     * Cache key for the excluded id sets.
     *
     * @since 2.7.0
     * @var string
     */
    private const CACHE_KEY = 'thinkrank_hidden_post_ids';

    /**
     * Cache group. Non-persistent unless the site runs an object cache, which
     * is the right trade: the set is cheap to rebuild and must never go stale.
     *
     * @since 2.7.0
     * @var string
     */
    private const CACHE_GROUP = 'thinkrank';

    /**
     * Request-level memo of the id sets.
     *
     * @since 2.7.0
     * @var array<string, int[]>|null
     */
    private static $ids = null;

    /**
     * Register the query filter and the cache invalidation.
     *
     * @since 2.7.0
     * @return void
     */
    public function init(): void {
        add_action('pre_get_posts', [$this, 'exclude_hidden_posts']);

        // Any of these can change which posts are hidden.
        add_action('save_post', [self::class, 'flush']);
        add_action('deleted_post', [self::class, 'flush']);
        add_action('trashed_post', [self::class, 'flush']);
        add_action('untrashed_post', [self::class, 'flush']);
        add_action('added_post_meta', [self::class, 'flush']);
        add_action('updated_post_meta', [self::class, 'flush']);
        add_action('deleted_post_meta', [self::class, 'flush']);

        if (is_admin()) {
            add_filter('display_post_states', [$this, 'post_states'], 10, 2);
        }
    }

    /**
     * Drop hidden posts from a listing.
     *
     * @since 2.7.0
     * @param \WP_Query $query The query about to run.
     * @return void
     */
    public function exclude_hidden_posts($query): void {
        if (!$query instanceof \WP_Query) {
            return;
        }

        $kind = $this->kind_for($query);

        if (null === $kind) {
            return;
        }

        /**
         * Filter whether a query has hidden posts removed from it.
         *
         * The escape hatch for a listing that should show everything — a
         * "recently updated" block an editor curates by hand, say.
         *
         * @since 2.7.0
         *
         * @param bool      $apply Whether to apply the exclusion.
         * @param string    $kind  'search' or 'archive'.
         * @param \WP_Query $query The query about to run.
         */
        if (!apply_filters('thinkrank_hide_posts_from_query', true, $kind, $query)) {
            return;
        }

        $hidden = self::hidden_ids($kind);

        if ([] === $hidden) {
            return;
        }

        // Merged rather than assigned: another plugin may already be excluding
        // posts from this query, and overwriting its list would quietly undo it.
        //
        // Not a bare `(array)` cast: WP_Query::get() answers '' for a variable
        // that was never set, and casting that gives [''] rather than [], which
        // would put an empty string into post__not_in for every listing on the
        // site.
        $existing = $query->get('post__not_in');
        $existing = is_array($existing) ? $existing : [];

        $query->set('post__not_in', array_values(array_unique(array_merge($existing, $hidden))));
    }

    /**
     * Which exclusion, if any, applies to this query.
     *
     * @since 2.7.0
     * @param \WP_Query $query The query about to run.
     * @return string|null 'search', 'archive', or null to leave it alone.
     */
    private function kind_for(\WP_Query $query): ?string {
        // Admin listings must show everything, or an author cannot find the
        // post they hid. Feeds, REST and the sitemap are left alone too: this
        // is about what a visitor browsing the site sees, and a post silently
        // missing from a feed or a sitemap is a different decision nobody made.
        if (is_admin()) {
            return null;
        }

        if ($query->is_feed() || $query->is_singular() || $query->is_404()) {
            return null;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return null;
        }

        // Never outside a page request. The sitemap generator, WP-CLI and cron
        // all run WP_Query for public content with no listing context at all,
        // and an early version of this hid posts from the sitemap and from
        // `wp post list` because of it.
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }

        // Page builders render the listing from their own query rather than the
        // main one — measured on a Bricks archive, the posts on screen come
        // from `Bricks\Query->run`, not from `wp`. Restricting this to the main
        // query left the feature doing nothing visible on any such site, which
        // is a large share of them.
        //
        // A secondary query therefore counts, but only under two conditions
        // that together separate a listing from everything else a page runs.
        // `template_redirect` is the important one: a bare `new WP_Query()`
        // reports `is_home()` as true by default, so the flags alone cannot
        // tell a builder's front-page loop from the sitemap's query — but the
        // sitemap, cron and CLI never reach template rendering, and a builder's
        // loop only runs during it.
        if (!$query->is_main_query()) {
            if (!did_action('template_redirect')) {
                return null;
            }

            if (!self::queries_public_content($query)) {
                return null;
            }
        }

        if ($query->is_search()) {
            return 'search';
        }

        // The blog listing counts: it is the archive of everything, and a post
        // hidden from category and author pages but still on the front page
        // would be a confusing half-measure.
        if ($query->is_home() || $query->is_archive()) {
            return 'archive';
        }

        return null;
    }

    /**
     * Whether a secondary query is asking for the site's own public content.
     *
     * The page a visitor loads runs a dozen queries that are not listings at
     * all: ACF field groups, Bricks templates and webfonts, block template
     * parts, global styles. Every one of them names an internal post type, and
     * every one would be pointlessly rewritten by a blanket rule. Asking what
     * the query is *for* separates them cleanly, and does it by a property the
     * query states about itself rather than by a list of plugin class names
     * that would need a new entry per builder.
     *
     * @since 2.7.0
     * @param \WP_Query $query The query about to run.
     * @return bool
     */
    private static function queries_public_content(\WP_Query $query): bool {
        $types = $query->get('post_type');

        // Unset or 'any' means WordPress's own default, which is public
        // content. An explicit list has to be entirely public to qualify.
        if (empty($types) || 'any' === $types) {
            return true;
        }

        $public = get_post_types(['public' => true]);

        foreach ((array) $types as $type) {
            if (!in_array($type, $public, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Post ids hidden from one kind of listing.
     *
     * @since 2.7.0
     * @param string $kind 'search' or 'archive'.
     * @return int[]
     */
    public static function hidden_ids(string $kind): array {
        if (null === self::$ids) {
            $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);

            self::$ids = is_array($cached) ? $cached : self::build();
        }

        return self::$ids[$kind] ?? [];
    }

    /**
     * Read both sets from the database and cache them.
     *
     * One query for both keys rather than one each: the two sets overlap
     * heavily in practice, since most posts hidden from one are hidden from
     * both.
     *
     * @since 2.7.0
     * @return array<string, int[]>
     */
    private static function build(): array {
        global $wpdb;

        $sets = ['search' => [], 'archive' => []];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- The result is what is being cached, immediately below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key FROM {$wpdb->postmeta}
                 WHERE meta_key IN (%s, %s) AND meta_value = '1'",
                self::SEARCH_META,
                self::ARCHIVE_META
            )
        );

        foreach ((array) $rows as $row) {
            $kind = (self::SEARCH_META === $row->meta_key) ? 'search' : 'archive';

            $sets[$kind][] = (int) $row->post_id;
        }

        wp_cache_set(self::CACHE_KEY, $sets, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $sets;
    }

    /**
     * Forget the cached sets.
     *
     * @since 2.7.0
     * @return void
     */
    public static function flush(): void {
        self::$ids = null;

        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }

    /**
     * Whether a post is hidden from a kind of listing.
     *
     * @since 2.7.0
     * @param int    $post_id Post to check.
     * @param string $kind    'search' or 'archive'.
     * @return bool
     */
    public static function is_hidden(int $post_id, string $kind): bool {
        return in_array($post_id, self::hidden_ids($kind), true);
    }

    /**
     * Label a hidden post in the admin list table.
     *
     * Without this an author has no way to tell, from the list, why a post is
     * missing from the site. The setting is two clicks deep in a metabox tab.
     *
     * @since 2.7.0
     *
     * @param array    $states Existing post states.
     * @param \WP_Post $post   The post being listed.
     * @return array
     */
    public function post_states($states, $post): array {
        if (!is_array($states) || !$post instanceof \WP_Post) {
            return (array) $states;
        }

        $search  = self::is_hidden((int) $post->ID, 'search');
        $archive = self::is_hidden((int) $post->ID, 'archive');

        if ($search && $archive) {
            $states['thinkrank_hidden'] = __('Hidden from search and archives', 'thinkrank');
        } elseif ($search) {
            $states['thinkrank_hidden_search'] = __('Hidden from site search', 'thinkrank');
        } elseif ($archive) {
            $states['thinkrank_hidden_archive'] = __('Hidden from archives', 'thinkrank');
        }

        return $states;
    }
}
