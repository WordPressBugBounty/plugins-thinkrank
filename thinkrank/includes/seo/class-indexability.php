<?php
/**
 * Indexability
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.14.1
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Shared_State;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a post or term is a destination worth submitting to a search engine.
 *
 * The robots tag a page prints was the only place that decided indexability.
 * Every URL feed re-decided it partially or not at all: the sitemap honoured a
 * per-post noindex but not a noindexed post type or taxonomy, and listed URLs
 * ThinkRank itself redirects; IndexNow checked nothing beyond the post type
 * (#911). Each of those contradicts a signal the site sends elsewhere, which
 * Search Console reports as "Submitted URL marked noindex" and "Page with
 * redirect".
 *
 * So this class answers the question once, from the same inputs the robots tag
 * reads, and every feed asks it. It is public so ThinkRank Pro's feeds (news
 * and video sitemaps, llms-full.txt, internal-link targets) can use it too.
 *
 * Four things make an object a non-destination:
 * - the effective robots decision is noindex, resolved through the cascade
 *   {@see \ThinkRank\Frontend\SEO_Manager} uses for the robots tag: site-wide
 *   directives, then the content type's row in the matrix, then the object's
 *   own override, each merged over the last;
 * - the post has a password;
 * - the object redirects elsewhere, either through its own "Redirect this to"
 *   field ({@see Object_Redirect}) or because a redirect provider answers the
 *   `thinkrank_url_redirects` filter for its URL;
 * - its canonical override names another URL, so the page itself tells search
 *   engines to index that one instead.
 *
 * @since 2.14.1
 */
final class Indexability {

    /**
     * Site-wide robots directives (the base of the cascade).
     */
    private const GLOBAL_ROBOTS_OPTION = 'thinkrank_global_robot_meta_settings';

    /**
     * Robots keys a per-object override may set, as the robots tag reads them.
     */
    private const OVERRIDE_KEYS = ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'];

    /**
     * Reasons an object is not indexable.
     */
    public const REASON_NOINDEX  = 'noindex';
    public const REASON_PASSWORD = 'password';
    public const REASON_REDIRECT = 'redirect';
    public const REASON_CANONICAL = 'canonical';

    /**
     * Meta key holding a post's or term's canonical override.
     */
    private const CANONICAL_META = '_thinkrank_canonical_url';

    /**
     * Option counting changes to any input of the decision.
     *
     * The thin content and duplicate snippet reports leave non-indexable posts
     * out (#1013) and cache their answer. Their indexes only move when a post's
     * text does, so noindexing or redirecting a post would leave it listed
     * until something unrelated changed. This counter goes into each report's
     * cache signature instead.
     *
     * @since 2.15.0
     */
    public const REVISION_OPTION = 'thinkrank_indexability_revision';

    /**
     * Option counting changes to the site-wide inputs of the decision.
     *
     * Stamped on every stored verdict ({@see self::VERDICT_META}): bumping it
     * makes all of them stale at once, which is what a change to the global
     * robots settings, the content type matrix or a redirect rule needs. A
     * change to one post only drops that post's verdict.
     *
     * @since 2.15.0
     */
    public const GENERATION_OPTION = 'thinkrank_indexability_generation';

    /**
     * Post meta holding the stored verdict, as `{generation}:{0|1}`.
     *
     * The reports judge a post once and then count and list from SQL, joining
     * on this, so a rebuild never has to load and judge every thin post or
     * every duplicate-group member in one request (review on #1017).
     *
     * @since 2.15.0
     */
    public const VERDICT_META = '_thinkrank_indexable';

    /**
     * Bounds on one batch of verdicts. The decision can reach a redirect
     * provider per URL, so a batch is capped by count and by time, the same
     * way the word count and snippet indexes fill.
     */
    private const REFRESH_MAX_POSTS   = 50;
    private const REFRESH_MAX_SECONDS = 3.0;

    /**
     * Post meta read by the decision.
     */
    private const WATCHED_META = ['_thinkrank_robots_meta', '_thinkrank_robots_meta_enabled'];

    /**
     * Register the hooks that keep stored verdicts and the revision current.
     * Runs on every request, because robots settings and redirects change from
     * many screens.
     *
     * @since 2.15.0
     *
     * @return void
     */
    public function init(): void {
        foreach ([self::GLOBAL_ROBOTS_OPTION, Content_Type_Settings::OPTION_NAME] as $option) {
            add_action('update_option_' . $option, [self::class, 'bump_generation'], 10, 0);
            add_action('add_option_' . $option, [self::class, 'bump_generation'], 10, 0);
        }

        add_action('added_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('updated_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'on_meta_change'], 10, 3);

        // A password, a status or a slug the redirect provider matches on all
        // change with the post itself.
        add_action('save_post', [self::class, 'forget_post'], 10, 1);

        // A redirect lives with its provider, not here: free brokers the
        // per-object field, and ThinkRank Pro announces any rule change.
        add_action('thinkrank_object_redirect_saved', [self::class, 'on_object_redirect_saved'], 10, 2);
        add_action('thinkrank_pro_redirects_changed', [self::class, 'bump_generation'], 10, 0);
    }

    /**
     * Drop a post's verdict when one of its robots meta keys changes.
     *
     * @since 2.15.0
     *
     * @param mixed $meta_id   Meta ID, or IDs on delete.
     * @param mixed $object_id Post ID.
     * @param mixed $meta_key  Meta key.
     * @return void
     */
    public static function on_meta_change($meta_id, $object_id, $meta_key): void {
        if (in_array($meta_key, self::WATCHED_META, true)) {
            self::drop_verdict($object_id);
            self::bump_revision();
        }
    }

    /**
     * Drop a post's verdict when its own "Redirect this to" field is saved.
     *
     * @since 2.15.0
     *
     * @param mixed $object_type 'post' or 'term'.
     * @param mixed $object_id   Object ID.
     * @return void
     */
    public static function on_object_redirect_saved($object_type, $object_id): void {
        if ('post' === $object_type) {
            self::drop_verdict($object_id);
        }

        self::bump_revision();
    }

    /**
     * Drop one post's stored verdict, so the next report batch judges it again.
     *
     * @since 2.15.0
     *
     * @param mixed $post_id Post ID.
     * @return void
     */
    public static function forget_post($post_id): void {
        // Only a verdict that really went away changes a report.
        if (self::drop_verdict($post_id)) {
            self::bump_revision();
        }
    }

    /**
     * Delete one post's stored verdict.
     *
     * @param mixed $post_id Post ID.
     * @return bool Whether there was one to delete.
     */
    private static function drop_verdict($post_id): bool {
        $post_id = (int) $post_id;

        return $post_id > 0 && delete_post_meta($post_id, self::VERDICT_META);
    }

    /**
     * How many times an input of the decision has changed.
     *
     * @since 2.15.0
     *
     * @return int
     */
    public static function revision(): int {
        return (int) get_option(self::REVISION_OPTION, 0);
    }

    /**
     * Record that an input of the decision changed.
     *
     * @since 2.15.0
     *
     * @return void
     */
    public static function bump_revision(): void {
        Shared_State::increment_option(self::REVISION_OPTION, 0, false);
    }

    /**
     * Generation stamped on stored verdicts.
     *
     * @since 2.15.0
     *
     * @return int
     */
    public static function generation(): int {
        return (int) get_option(self::GENERATION_OPTION, 0);
    }

    /**
     * Make every stored verdict stale: a site-wide input changed.
     *
     * @since 2.15.0
     *
     * @return void
     */
    public static function bump_generation(): void {
        Shared_State::increment_option(self::GENERATION_OPTION, 0, false);
        self::bump_revision();
    }

    /**
     * Judge a bounded batch of the posts that have no current verdict.
     *
     * Each caller passes the posts it reports on, as subqueries selecting
     * `ID`, so only thin posts and duplicate-group members are ever judged,
     * never the whole site. The caller adds the return value to its own
     * `pending` and keeps asking until it reaches zero, as it already does for
     * the word count and snippet indexes.
     *
     * @since 2.15.0
     *
     * @param string[] $candidate_sqls Trusted, already prepared subqueries selecting `ID`.
     * @return int How many candidates still have no current verdict.
     */
    public static function refresh_verdicts(array $candidate_sqls): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the candidates are subqueries the index classes built through prepare(), and every other fragment is prepared on its own; they are only concatenated, because the candidates carry LIKE patterns whose literal % a second prepare() would read as placeholders. The stored verdicts are themselves the cache.
        global $wpdb;

        $candidate_sqls = array_values(array_filter($candidate_sqls, static function ($sql): bool {
            return is_string($sql) && '' !== $sql;
        }));
        if (empty($candidate_sqls)) {
            return 0;
        }

        $stale_sql = self::stale_candidates_sql($candidate_sqls);
        $started   = microtime(true);

        $ids = array_map('intval', (array) $wpdb->get_col(
            "SELECT c.ID {$stale_sql} ORDER BY c.ID DESC" . $wpdb->prepare(' LIMIT %d', self::REFRESH_MAX_POSTS)
        ));

        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);

            $generation = self::generation();
            foreach ($ids as $post_id) {
                $post = get_post($post_id);
                if ($post instanceof \WP_Post) {
                    update_post_meta(
                        $post_id,
                        self::VERDICT_META,
                        $generation . ':' . (self::is_indexable_post($post) ? '1' : '0')
                    );
                }

                // Checked per post: one redirect lookup can be slow.
                if (microtime(true) - $started > self::REFRESH_MAX_SECONDS) {
                    break;
                }
            }
        }

        return max(0, (int) $wpdb->get_var("SELECT COUNT(*) {$stale_sql}"));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * FROM and WHERE selecting the candidates without a current verdict.
     *
     * @param string[] $candidate_sqls Trusted subqueries selecting `ID`.
     * @return string Trusted SQL.
     */
    private static function stale_candidates_sql(array $candidate_sqls): string {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- see refresh_verdicts().
        global $wpdb;

        $union = implode(' UNION ', $candidate_sqls);

        return "FROM ({$union}) c
             LEFT JOIN {$wpdb->postmeta} v ON v.post_id = c.ID AND " . $wpdb->prepare('v.meta_key = %s', self::VERDICT_META) . '
             WHERE (v.meta_value IS NULL OR ' . $wpdb->prepare('v.meta_value NOT LIKE %s', self::current_verdict_like()) . ')';
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * INNER JOIN keeping only posts whose current stored verdict is indexable.
     *
     * A post with no verdict yet, or a stale one, is left out until a batch
     * judges it; the report says it is still pending meanwhile.
     *
     * @since 2.15.0
     *
     * @param string $posts_alias Alias of the posts table in the caller's query.
     * @param string $alias       Alias to give the joined meta row.
     * @return string Trusted SQL.
     */
    public static function indexable_join_sql(string $posts_alias, string $alias): string {
        global $wpdb;

        $posts_alias = in_array($posts_alias, ['p', 'dp', 'ep'], true) ? $posts_alias : 'p';
        $alias       = in_array($alias, ['v', 'dv', 'ev'], true) ? $alias : 'v';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both aliases are whitelisted above; the values are placeholders.
        return $wpdb->prepare(
            " INNER JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = {$posts_alias}.ID AND {$alias}.meta_key = %s AND {$alias}.meta_value = %s",
            self::VERDICT_META,
            self::generation() . ':1'
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * LIKE pattern matching any verdict of the current generation.
     *
     * @return string
     */
    private static function current_verdict_like(): string {
        global $wpdb;

        return $wpdb->esc_like(self::generation() . ':') . '%';
    }

    /**
     * Whether a post or term may be submitted to search engines.
     *
     * @since 2.14.1
     *
     * @param \WP_Post|\WP_Term $item Post or term.
     * @return bool
     */
    public static function is_indexable_destination($item): bool {
        if ($item instanceof \WP_Post) {
            return self::is_indexable_post($item);
        }

        if ($item instanceof \WP_Term) {
            return self::is_indexable_term($item);
        }

        return false;
    }

    /**
     * Whether a post may be submitted to search engines.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_indexable_post(\WP_Post $post): bool {
        return self::post_exclusion_reason($post) === null;
    }

    /**
     * Whether a term archive may be submitted to search engines.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_indexable_term(\WP_Term $term): bool {
        return self::term_exclusion_reason($term) === null;
    }

    /**
     * Why a post is not indexable, or null when it is.
     *
     * Cheapest check first: the password is on the object, the robots cascade
     * reads options and primed meta, and a redirect lookup may query the
     * redirect provider's table.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return string|null One of the REASON_* constants, or null.
     */
    public static function post_exclusion_reason(\WP_Post $post): ?string {
        if (self::is_password_protected($post)) {
            return self::REASON_PASSWORD;
        }

        if (self::is_post_noindexed($post)) {
            return self::REASON_NOINDEX;
        }

        if (self::is_post_redirected($post)) {
            return self::REASON_REDIRECT;
        }

        if (self::is_post_canonicalized_away($post)) {
            return self::REASON_CANONICAL;
        }

        return null;
    }

    /**
     * Why a term is not indexable, or null when it is.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return string|null One of the REASON_* constants, or null.
     */
    public static function term_exclusion_reason(\WP_Term $term): ?string {
        if (self::is_term_noindexed($term)) {
            return self::REASON_NOINDEX;
        }

        if (self::is_term_redirected($term)) {
            return self::REASON_REDIRECT;
        }

        if (self::is_term_canonicalized_away($term)) {
            return self::REASON_CANONICAL;
        }

        return null;
    }

    /**
     * Whether the post has a password.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_password_protected(\WP_Post $post): bool {
        return (string) $post->post_password !== '';
    }

    /**
     * Whether the robots tag on this post's page says noindex.
     *
     * Site-wide directives, then the post type's own (when its robots switch
     * is on; the "Blog posts index" row for the static posts page), then the
     * post's override. A post-level `noindex: false` beats a
     * noindexed type, exactly as it does in the tag.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_post_noindexed(\WP_Post $post): bool {
        // The static posts page is served as the blog listing, so its tag reads
        // the "Blog posts index" row, not the page row. Reading the page row
        // here listed a posts page the tag noindexed (#1321).
        $entity_key = self::is_static_posts_page($post)
            ? Content_Type_Settings::ENTITY_BLOG_INDEX
            : (string) $post->post_type;

        $settings = self::merge_entity_robots(self::global_robots(), $entity_key);

        $settings = self::merge_override(
            $settings,
            (bool) get_post_meta($post->ID, '_thinkrank_robots_meta_enabled', true),
            get_post_meta($post->ID, '_thinkrank_robots_meta', true)
        );

        return !empty($settings['noindex']);
    }

    /**
     * Whether the robots tag on the blog posts index says noindex.
     *
     * The listing has no object of its own to carry an override: the tag reads
     * the site-wide directives, then the "Blog posts index" row. On a site that
     * shows its latest posts this is the homepage.
     *
     * @since 2.18.1
     *
     * @return bool
     */
    public static function is_blog_index_noindexed(): bool {
        $settings = self::merge_entity_robots(self::global_robots(), Content_Type_Settings::ENTITY_BLOG_INDEX);

        return !empty($settings['noindex']);
    }

    /**
     * Whether the post is the page WordPress serves as the blog posts index.
     *
     * @since 2.19.0
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private static function is_static_posts_page(\WP_Post $post): bool {
        if ($post->post_type !== 'page' || get_option('show_on_front') !== 'page') {
            return false;
        }

        $posts_page = (int) get_option('page_for_posts');

        return $posts_page > 0 && $posts_page === (int) $post->ID;
    }

    /**
     * Whether the robots tag on this term's archive says noindex.
     *
     * Site-wide directives, then the taxonomy's matrix row, then the term's
     * override.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_term_noindexed(\WP_Term $term): bool {
        $settings = self::merge_entity_robots(
            self::global_robots(),
            Content_Type_Settings::PREFIX_TAXONOMY . $term->taxonomy
        );

        $settings = self::merge_override(
            $settings,
            (bool) get_term_meta($term->term_id, '_thinkrank_robots_meta_enabled', true),
            get_term_meta($term->term_id, '_thinkrank_robots_meta', true)
        );

        return !empty($settings['noindex']);
    }

    /**
     * Whether a request for this post is redirected elsewhere.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_post_redirected(\WP_Post $post): bool {
        if (Object_Redirect::get('post', (int) $post->ID)['url'] !== '') {
            return true;
        }

        $url = get_permalink($post);

        return is_string($url) && $url !== '' && self::url_is_redirected($url, $post);
    }

    /**
     * Whether a request for this term's archive is redirected elsewhere.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_term_redirected(\WP_Term $term): bool {
        if (Object_Redirect::get('term', (int) $term->term_id)['url'] !== '') {
            return true;
        }

        $url = get_term_link($term);

        return is_string($url) && $url !== '' && self::url_is_redirected($url, $term);
    }

    /**
     * Whether the post's canonical override points at another URL.
     *
     * The canonical tag prints the override as typed, so a post canonicalised
     * to another page asks search engines to index that page instead. Listing
     * it in the sitemap contradicted the page (#1320). A canonical that is the
     * post's own permalink, in any scheme or with or without the trailing
     * slash, keeps it.
     *
     * @since 2.18.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_post_canonicalized_away(\WP_Post $post): bool {
        $canonical = Absolute_Url::from((string) get_post_meta($post->ID, self::CANONICAL_META, true));
        if ($canonical === '') {
            return false;
        }

        $permalink = get_permalink($post);

        return is_string($permalink) && $permalink !== '' && !self::is_same_url($canonical, $permalink);
    }

    /**
     * Whether the term's canonical override points at another URL.
     *
     * Mirrors the archive canonical, which ignores an override that is just
     * the archive's own link.
     *
     * @since 2.18.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_term_canonicalized_away(\WP_Term $term): bool {
        $canonical = Absolute_Url::from((string) get_term_meta($term->term_id, self::CANONICAL_META, true));
        if ($canonical === '') {
            return false;
        }

        $link = get_term_link($term);

        return is_string($link) && $link !== '' && !self::is_same_url($canonical, $link);
    }

    /**
     * Whether two URLs name the same page: host, port, path with or without
     * the trailing slash, and query. The scheme is ignored, since the
     * canonical tag normalises it to the site's own.
     *
     * @param string $a URL.
     * @param string $b URL.
     * @return bool
     */
    private static function is_same_url(string $a, string $b): bool {
        $key = static function (string $url): ?string {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return null;
            }

            return strtolower((string) $parts['host'])
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . trailingslashit((string) ($parts['path'] ?? '/'))
                . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        };

        $key_a = $key($a);

        return $key_a !== null && $key_a === $key($b);
    }

    /**
     * Ask the redirect provider whether a URL is redirected.
     *
     * @param string            $url    The object's URL.
     * @param \WP_Post|\WP_Term $item The object it belongs to.
     * @return bool
     */
    private static function url_is_redirected(string $url, $item): bool {
        /**
         * Filter whether a URL is redirected by a rule that is not tied to an object.
         *
         * Per-object redirects are already read through `thinkrank_object_redirect`.
         * This covers the rest: a rule whose source path happens to be a post's
         * or term's URL. Nothing answers it in the free plugin; a redirect
         * provider such as ThinkRank Pro does, from its own rule store.
         *
         * @since 2.14.1
         *
         * @param bool              $redirected Whether the URL redirects. Default false.
         * @param string            $url        The post's permalink or the term's archive link.
         * @param \WP_Post|\WP_Term $item     The object the URL belongs to.
         */
        return (bool) apply_filters('thinkrank_url_redirects', false, $url, $item);
    }

    /**
     * Site-wide robots directives with the same defaults the robots tag uses.
     *
     * @return array
     */
    private static function global_robots(): array {
        $stored = get_option(self::GLOBAL_ROBOTS_OPTION, []);

        return array_merge(
            ['index' => true, 'noindex' => false],
            is_array($stored) ? $stored : []
        );
    }

    /**
     * Merge a matrix row's robots directives when its robots switch is on.
     *
     * @param array  $settings   Directives so far.
     * @param string $entity_key Content_Type_Settings entity key.
     * @return array
     */
    private static function merge_entity_robots(array $settings, string $entity_key): array {
        $entity = Content_Type_Settings::get_entity_settings($entity_key);

        if (!empty($entity['robots_meta_enabled']) && is_array($entity['robots_meta'] ?? null)) {
            $settings = array_merge($settings, $entity['robots_meta']);
        }

        return $settings;
    }

    /**
     * Merge an object's stored robots override when it is switched on.
     *
     * @param array $settings Directives so far.
     * @param bool  $enabled  The object's `_thinkrank_robots_meta_enabled`.
     * @param mixed $raw      The object's `_thinkrank_robots_meta` JSON.
     * @return array
     */
    private static function merge_override(array $settings, bool $enabled, $raw): array {
        if (!$enabled || !is_string($raw) || $raw === '') {
            return $settings;
        }

        $override = json_decode($raw, true);
        if (!is_array($override)) {
            return $settings;
        }

        return array_merge($settings, array_intersect_key($override, array_flip(self::OVERRIDE_KEYS)));
    }
}
