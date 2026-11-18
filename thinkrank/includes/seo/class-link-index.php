<?php
/**
 * Persisted internal link graph.
 *
 * @package ThinkRank\SEO
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link Index
 *
 * "Which of my pages has nothing linking to it?" is a question about every
 * published page and every link on it, and #566 filed it as one of the things
 * that could not be answered without crawling the rendered site.
 *
 * It can. A crawler is needed to see markup a theme or plugin injects at render
 * time; it is not needed to see the links an author put on a page.
 * {@see Builder_Content::resolve()} already reconstructs `<a href>` from the
 * link fields Elementor, Divi, Oxygen, Beaver and Bricks keep outside
 * `post_content` — that is what URL_KEYS is for — so the same extraction that
 * feeds the word count feeds the link graph, with no HTTP and no reversal of
 * the analyzer's documented crawl-free design.
 *
 * Three decisions shape this class.
 *
 * **The graph lives in its own table, not in post meta.** The two indexes that
 * came before this one ({@see Snippet_Index}, {@see Word_Count_Index}) store a
 * value per post because every question they answer is asked of a post. This
 * one is asked backwards: an orphan is a post nothing links *to*. A list of
 * outbound targets stored per post can only be inverted by loading the whole
 * site into PHP, so the edges go in `{prefix}thinkrank_internal_links` with an
 * index on `target_id` and the inversion happens in SQL.
 *
 * **A marker entry still lives in post meta.** The table cannot distinguish "no
 * links on this page" from "not indexed yet", and the difference decides
 * whether a report is complete. The marker carries the format version, which is
 * what makes a reindex after a change to extraction a matter of bumping a
 * constant, exactly as the other two indexes do it.
 *
 * **Menus are sources too.** A page in the main navigation is linked from every
 * page on the site, and a report that called it orphaned would be noise rather
 * than a finding. Classic menu items keep their target in meta and block-theme
 * navigation keeps it in content, so both are indexed as sources alongside
 * ordinary posts, and neither is ever a candidate for being orphaned itself.
 * Only a menu the site actually renders counts, though: a menu left behind
 * unassigned, or a navigation no template uses, links nowhere a visitor can
 * be ({@see active_menu_sources()}).
 *
 * @since 2.22.0
 */
class Link_Index {

    /**
     * Marker entry: this post's outbound links are in the edge table, and the
     * format they were extracted with.
     */
    public const META_KEY = '_thinkrank_link_index';

    /**
     * Extraction format.
     *
     * Bumping it retires every entry at once, which is the point: a change to
     * what counts as a link makes every stored edge suspect, and an entry from
     * an older format has to be rebuilt rather than trusted.
     */
    public const VERSION = 2;

    /**
     * Option holding the revision counter.
     */
    private const REVISION_OPTION = 'thinkrank_link_index_revision';

    /**
     * Post types indexed as sources although they are never orphan candidates.
     *
     * `nav_menu_item` is a classic menu entry; `wp_navigation` is a block
     * theme's navigation. Both carry links to real pages, and neither is a page
     * anyone can visit.
     *
     * @var string[]
     */
    public const MENU_POST_TYPES = ['nav_menu_item', 'wp_navigation'];

    /**
     * Block delimiters whose rendered output is a list of content the block
     * does not name.
     *
     * A navigation is usually not a list of links an author wrote. Both
     * `core/page-list` (the default a block theme's navigation falls back to)
     * and its nested form render one `<a href>` per published page, decided at
     * render time. The resolver reads those links correctly, but the entry they
     * were read into goes stale when a *different* post is published, deleted
     * or unpublished, and nothing about editing that post touches the
     * navigation. Left alone, every newly published page is reported as
     * orphaned until something happens to save the menu — which is the exact
     * false finding indexing menus as sources exists to prevent.
     *
     * Each block maps to the post types whose publication changes its output,
     * so publishing a blog post does not reindex a navigation that can only
     * ever list pages.
     *
     * @var array<string,string[]>
     */
    private const DYNAMIC_SOURCE_BLOCKS = ['wp:page-list' => ['page']];

    /**
     * Meta key a classic menu item keeps its target post ID in.
     */
    private const MENU_OBJECT_ID_META = '_menu_item_object_id';

    /**
     * Meta key naming what a classic menu item points at.
     */
    private const MENU_OBJECT_TYPE_META = '_menu_item_type';

    /**
     * Posts per `IN (...)` list when retiring entries in bulk.
     */
    private const BULK_CHUNK = 500;

    /**
     * Most entries one refresh call will build, and the time it may spend.
     *
     * The same 50/3.0 as {@see Word_Count_Index}, and for the same reason: the
     * cost is dominated by resolving a builder tree, not by the link parsing
     * bolted onto it.
     */
    private const REFRESH_MAX_POSTS   = 50;
    private const REFRESH_MAX_SECONDS = 3.0;

    /**
     * Times one build goes round again when the post changes underneath it,
     * before it gives up and leaves the post for the next refresh.
     */
    private const BUILD_ATTEMPTS = 3;

    /**
     * Memo for {@see watched_meta()}.
     *
     * @var string[]|null
     */
    private static $watched_meta = null;

    /**
     * Request-local memo for {@see resolve_url()}: URL => post ID (0 = none).
     *
     * `url_to_postid()` costs at least one query, and a site-wide navigation
     * repeated in a footer means the same handful of URLs is resolved on every
     * page of the batch.
     *
     * @var array<string,int>
     */
    private static $url_memo = [];

    /**
     * Request-local memo for {@see active_menu_sources()}.
     *
     * @var array{menus:int[], navigations:int[]}|null
     */
    private static $active_menu_sources = null;

    /**
     * Register invalidation hooks. Runs on every request, because posts are
     * edited everywhere and not only on the report's screen.
     *
     * @return void
     */
    public function init(): void {
        add_action('save_post', [self::class, 'mark_post_stale'], 99, 1);

        add_action('added_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('updated_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'on_meta_change'], 10, 3);

        // A post leaving `publish` stops being a page anything can link to, and
        // stops being a candidate for the report, without any entry changing.
        add_action('transition_post_status', [self::class, 'on_status_change'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_post_deleted'], 10, 1);

        // Assigning a menu to a location, or changing which menu is assigned,
        // changes which pages are reachable from the navigation without editing
        // a single menu item.
        add_action('update_option_theme_mods_' . get_option('stylesheet'), [self::class, 'bump_revision'], 10, 0);
    }

    /**
     * Post meta whose change changes a post's outbound links.
     *
     * The builder keys come from {@see Builder_Content::builder_meta_keys()} so
     * a builder added to the resolver is invalidated by the same commit that
     * teaches the resolver to read it, exactly as the word count index does it.
     * A classic menu item's target is meta too, and it is the only content that
     * item has.
     *
     * @return string[]
     */
    public static function watched_meta(): array {
        if (null === self::$watched_meta) {
            self::$watched_meta = array_values(array_unique(array_merge(
                Builder_Content::builder_meta_keys(),
                [
                    '_bricks_page_content_2',
                    '_bricks_editor_mode',
                    self::MENU_OBJECT_ID_META,
                    self::MENU_OBJECT_TYPE_META,
                ]
            )));
        }

        return self::$watched_meta;
    }

    /**
     * Post meta changed.
     *
     * @param int|array $meta_id   Meta ID(s).
     * @param int       $object_id Post ID.
     * @param string    $meta_key  Meta key.
     * @return void
     */
    public static function on_meta_change($meta_id, $object_id, $meta_key): void {
        if (in_array($meta_key, self::watched_meta(), true)) {
            self::mark_post_stale($object_id);
        }
    }

    /**
     * A post moved between statuses.
     *
     * Leaving `publish` bumps the revision for the same reason the word count
     * index announces it: the post drops out of every query here while its
     * entry sits untouched.
     *
     * Unlike a word count, a status change also moves other posts' answers: the
     * pages this one linked to lose an inbound link. That needs no extra work —
     * the edges stay in the table but {@see inbound_sql()} only counts an edge
     * whose source is currently published, so the loss is already reflected.
     *
     * Arriving at `publish` bumps it too. The post has no entry yet, so it is
     * already pending, but the report only asks how much is pending when the
     * revision says something moved: a cached report is served without
     * scanning the posts table, and a publish that left the revision alone
     * would be invisible to it. Either direction also retires the dynamic
     * sources — a navigation built from a page list starts or stops linking to
     * this post the moment its status changes, and its stored entry was written
     * under the old answer.
     *
     * @param string        $new_status Status now.
     * @param string        $old_status Status before.
     * @param \WP_Post|null $post       Post.
     * @return void
     */
    public static function on_status_change($new_status, $old_status, $post = null): void {
        if ($new_status === $old_status || !$post instanceof \WP_Post) {
            return;
        }

        if ('publish' !== $old_status && 'publish' !== $new_status) {
            return;
        }

        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        self::retire_dynamic_sources($post->post_type);

        self::bump_revision();
    }

    /**
     * Make every source whose links are decided at render time stale.
     *
     * Called when the set of published posts changes, which is when a page list
     * starts or stops rendering a link without anyone editing the menu that
     * holds it. Bounded by how many navigations a site has, which is one or two
     * — and it is a LIKE over `wp_navigation` rows only, not over the posts
     * table.
     *
     * Classic menus are deliberately not included: a `nav_menu_item` names its
     * target, so publishing an unrelated post cannot change what it links to.
     *
     * @param string $post_type Post type whose publication changed. Only the
     *                          blocks that list that type are retired; an empty
     *                          string retires all of them.
     * @return void
     */
    public static function retire_dynamic_sources(string $post_type = ''): void {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- both IN lists are runs of placeholders built from the array sizes, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare(). The index is itself the cache.
        global $wpdb;

        $blocks = [];
        foreach (self::DYNAMIC_SOURCE_BLOCKS as $block => $lists) {
            if ('' === $post_type || in_array($post_type, $lists, true)) {
                $blocks[] = $block;
            }
        }

        if (empty($blocks)) {
            return;
        }

        $type_in = implode(', ', array_fill(0, count(self::MENU_POST_TYPES), '%s'));
        $like    = [];
        $values  = self::MENU_POST_TYPES;

        foreach ($blocks as $block) {
            $like[]   = 'p.post_content LIKE %s';
            $values[] = '%' . $wpdb->esc_like('<!-- ' . $block) . '%';
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type IN ({$type_in})
               AND p.post_status = 'publish'
               AND (" . implode(' OR ', $like) . ')',
            $values
        ));

        foreach (array_map('intval', (array) $ids) as $id) {
            self::mark_post_stale($id);
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * A post is about to be deleted for good.
     *
     * Hooked before the delete so the entry is still readable, and its edges
     * are dropped here rather than left for the next pass: a row whose source
     * no longer exists can never be rebuilt, because nothing will ever list
     * that post as pending again.
     *
     * The rows pointing *at* it go the same way, and for the same reason. They
     * are harmless to the report — every query joins a target to a live post
     * row — but nothing else will ever revisit them either, so a site that
     * churns content would accumulate them for the life of the install.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function on_post_deleted($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);

        $had_entry = null !== self::decode((string) get_post_meta($post_id, self::META_KEY, true));

        self::delete_edges([$post_id]);
        self::delete_edges_to([$post_id]);

        // A page list stops rendering a link to this post the moment it is
        // gone, so the navigation holding it is no longer current either.
        if ($post instanceof \WP_Post && 'publish' === $post->post_status) {
            self::retire_dynamic_sources($post->post_type);
        }

        if ($had_entry) {
            self::bump_revision();
        }
    }

    /**
     * Make one post's entry stale.
     *
     * The edges go with it. Leaving them would let a link the author has just
     * removed keep a page off the orphan report until the entry is rebuilt,
     * which is the one answer this index exists to get right.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function mark_post_stale($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Edges first, marker last. Marker first, a build could claim the
        // entry between the two, write the new edges, have this DELETE remove
        // them, and still find its claim to swap for a current marker.
        self::delete_edges([$post_id]);

        $removed = delete_post_meta($post_id, self::META_KEY);

        if ($removed) {
            self::bump_revision();
        }
    }

    /**
     * How many times a batch of entries has been rebuilt.
     *
     * @return int
     */
    public static function revision(): int {
        return (int) get_option(self::REVISION_OPTION, 0);
    }

    /**
     * Record that entries changed. Not autoloaded: only the report reads it,
     * and never on a front-end request.
     *
     * @return void
     */
    public static function bump_revision(): void {
        // Locked increment; see Snippet_Index::bump_revision().
        \ThinkRank\Core\Shared_State::increment_option(self::REVISION_OPTION, 0, false);
    }

    /**
     * Encode a marker entry.
     *
     * @param int $edges Outbound internal links found.
     * @return string
     */
    public static function encode(int $edges): string {
        return 'v' . self::VERSION . ':' . max(0, $edges);
    }

    /**
     * Decode a marker entry.
     *
     * @param string $value Stored value.
     * @return int|null Edge count, or null when malformed or from an older
     *                  extraction format.
     */
    public static function decode(string $value): ?int {
        $prefix = 'v' . self::VERSION . ':';

        if (0 !== strpos($value, $prefix)) {
            return null;
        }

        $count = substr($value, strlen($prefix));

        return ctype_digit($count) ? (int) $count : null;
    }

    /**
     * LIKE pattern matching a current-format entry.
     *
     * @return string
     */
    private static function current_like(): string {
        global $wpdb;

        return $wpdb->esc_like('v' . self::VERSION . ':') . '%';
    }

    /**
     * WHERE fragment matching a source (`p`, its marker joined as `m`) whose
     * entry has to be built.
     *
     * That is no marker or one from another format, and also a marker that
     * counts links the edge table does not hold. The marker lives in post meta
     * and the edges in their own table, so the two can come apart: a site
     * cloned with the core tables only, or a WXR import, which carries the
     * meta, arrives with markers and an empty table. Trusting the markers then
     * reported every published post as orphaned, with nothing pending, until
     * each was edited. A marker of zero links needs no row to be true, so only
     * a non-zero one is checked, and the check is a primary-key lookup.
     *
     * @return string SQL fragment with every value inlined through prepare().
     */
    private static function unbuilt_sql(): string {
        global $wpdb;

        $table = self::table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is $wpdb->prefix plus a literal; every value is a placeholder.
        $sql = $wpdb->prepare(
            "(m.meta_value IS NULL OR m.meta_value NOT LIKE %s
              OR (m.meta_value <> %s AND NOT EXISTS (SELECT 1 FROM `{$table}` e WHERE e.source_id = p.ID)))",
            self::current_like(),
            self::encode(0)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $sql;
    }

    /**
     * The edge table.
     *
     * @return string
     */
    public static function table(): string {
        global $wpdb;

        return $wpdb->prefix . 'thinkrank_internal_links';
    }

    /**
     * Post types indexed as sources: everything in scope, plus the menus.
     *
     * @param string[] $post_types Orphan-candidate post types.
     * @return string[]
     */
    public static function source_post_types(array $post_types): array {
        return array_values(array_unique(array_merge($post_types, self::MENU_POST_TYPES)));
    }

    /**
     * Build a bounded batch of missing entries.
     *
     * @param string[] $post_types Orphan-candidate post types.
     * @param string[] $statuses   Post statuses for those types.
     * @return int How many entries remain to build after this batch.
     */
    public static function refresh(array $post_types, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by source_scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        $scope   = self::source_scope_sql($post_types, $statuses);
        $started = microtime(true);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . $scope . "
               AND " . self::unbuilt_sql() . "
             ORDER BY p.ID DESC
             LIMIT %d",
            self::META_KEY,
            self::REFRESH_MAX_POSTS
        ));

        $ids = array_map('intval', (array) $ids);

        // Whether this batch empties the queue: it found fewer posts than it
        // was allowed to take, and built every one. Then nothing is left to
        // count, and the exact count below, a scan of every source in scope,
        // is skipped on the call that finishes the index and on the ordinary
        // one that rebuilds a single edited post. It only runs while a backlog
        // larger than one batch remains, to report progress through it.
        $drained = count($ids) < self::REFRESH_MAX_POSTS;

        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);

            $built = 0;
            foreach ($ids as $post_id) {
                // A failed build leaves the post pending; the next refresh
                // retries it.
                if (self::build($post_id)) {
                    $built++;
                }

                // Checked per post, not per chunk: one Bricks page can take
                // longer than the whole budget on its own.
                if (microtime(true) - $started > self::REFRESH_MAX_SECONDS) {
                    break;
                }
            }

            if ($built !== count($ids)) {
                $drained = false;
            }

            self::bump_revision();
        }

        return $drained ? 0 : max(0, self::pending($post_types, $statuses));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * How many sources still have no current entry.
     *
     * @param string[] $post_types Orphan-candidate post types.
     * @param string[] $statuses   Post statuses for those types.
     * @return int
     */
    public static function pending(array $post_types, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by source_scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::source_scope_sql($post_types, $statuses) . "
               AND " . self::unbuilt_sql(),
            self::META_KEY
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Compute and store one source's edges.
     *
     * The marker is what takes a post off the pending list, so it may only say
     * "current" when the edges it vouches for are the post's links as they are
     * now. The build publishes it last, and only when all of these held:
     *
     * - **It was the only build of this post.** Two refreshes (two tabs, or a
     *   poll and a cron) can reach one post at once. A per-post database lock
     *   makes the second step aside and leave the post for the next refresh.
     * - **Every edge write went through.** A failed DELETE or INSERT (a
     *   missing table, a dropped connection, a rejected row) fails the build.
     * - **The source did not move underneath it.** The post row and the meta
     *   its links are read from are fingerprinted before extraction and again
     *   after the writes. A change in between, including one written without
     *   the hooks that would invalidate the entry, sends the build round
     *   again with the new content, a few times at most.
     * - **Nothing invalidated the entry meanwhile.** The build stores a one-off
     *   claim before reading anything. It is not a current-format value, so the
     *   post stays pending, and it is swapped for the current entry in one
     *   conditional UPDATE. An invalidation (a save, a page-list navigation
     *   retired by a publish) deletes the claim, the UPDATE matches nothing,
     *   and the build goes round again.
     *
     * No transaction: the marker is the commit. A replace that fails halfway
     * leaves rows the report may read, but the post stays pending, so the
     * report says it is incomplete rather than presenting them as the answer.
     *
     * @param int $post_id Post ID.
     * @return bool Whether the entry is now current.
     */
    private static function build(int $post_id): bool {
        if (!get_post($post_id) instanceof \WP_Post) {
            return false;
        }

        $lock = self::lock($post_id);
        if (false === $lock) {
            // Another request is building this post right now.
            return false;
        }

        try {
            for ($attempt = 0; $attempt < self::BUILD_ATTEMPTS; $attempt++) {
                $result = self::build_once($post_id);

                if (null !== $result) {
                    return $result;
                }
            }

            // Still moving after every attempt: leave it pending.
            delete_post_meta($post_id, self::META_KEY);

            return false;
        } finally {
            if (true === $lock) {
                self::unlock($post_id);
            }
        }
    }

    /**
     * One attempt at a source's entry.
     *
     * @param int $post_id Post ID.
     * @return bool|null True when the entry is current, false when a write
     *                   failed or the post is gone, null when the source or
     *                   the claim moved and the build should go round again.
     */
    private static function build_once(int $post_id): ?bool {
        $claim = 'claim:' . md5(uniqid((string) $post_id, true));

        update_post_meta($post_id, self::META_KEY, $claim);

        $before = self::source_fingerprint($post_id);

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            delete_post_meta($post_id, self::META_KEY);

            return false;
        }

        $targets = self::extract_targets($post);

        if (!self::delete_edges([$post_id]) || !self::insert_edges($post_id, $targets)) {
            delete_post_meta($post_id, self::META_KEY);

            return false;
        }

        if (self::source_fingerprint($post_id) !== $before) {
            return null;
        }

        return self::settle($post_id, $claim, self::encode(count($targets))) ? true : null;
    }

    /**
     * What a source's links are read from, as the database holds it now.
     *
     * The post's object and meta caches are dropped first, so this reads the
     * tables rather than copies loaded before the build began, and the next
     * attempt extracts from the same fresh copy.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function source_fingerprint(int $post_id): string {
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');

        $post = get_post($post_id);

        $values = $post instanceof \WP_Post
            ? [$post->post_status, $post->post_content, $post->post_modified_gmt]
            : [];

        foreach (self::watched_meta() as $key) {
            $values[$key] = get_post_meta($post_id, $key, false);
        }

        // Not a security boundary: a change detector for one post's content.
        return md5(serialize($values)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashed, never stored or unserialized.
    }

    /**
     * Take the per-post build lock without waiting.
     *
     * @param int $post_id Post ID.
     * @return bool|null True when held, false when another session holds it,
     *                   null when the database has no named locks (SQLite,
     *                   some proxies), in which case the build goes ahead on
     *                   its other guards.
     */
    private static function lock(int $post_id): ?bool {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, which has no API and must not be cached.
        global $wpdb;

        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name($post_id)));

        if (null === $got || '' !== (string) $wpdb->last_error) {
            return null;
        }

        return 1 === (int) $got;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Release the per-post build lock.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    private static function unlock(int $post_id): void {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, which has no API and must not be cached.
        global $wpdb;

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($post_id)));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Lock name for one post. Named locks are server-wide, so the table
     * prefix keeps two sites on one server apart; MySQL caps the name at 64.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function lock_name(int $post_id): string {
        global $wpdb;

        return 'thinkrank_lnkidx_' . substr(md5((string) $wpdb->prefix), 0, 12) . '_' . $post_id;
    }

    /**
     * Swap a build's claim for the current entry, if the claim is still there.
     *
     * @param int    $post_id Post ID.
     * @param string $claim   Token the build stored.
     * @param string $entry   Current-format entry.
     * @return bool False when the claim was gone (the source was invalidated
     *              during the build) or the UPDATE failed.
     */
    private static function settle(int $post_id, string $claim, string $entry): bool {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a compare-and-swap on one meta row, which update_post_meta() cannot express (with a previous value it adds the row when none matches). The post's meta cache is cleared below.
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
            $entry,
            $post_id,
            self::META_KEY,
            $claim
        ));

        wp_cache_delete($post_id, 'post_meta');

        return is_int($updated) && $updated > 0;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    }

    /**
     * The posts one source links to.
     *
     * @param \WP_Post $post Source post.
     * @return int[] Target post IDs, deduplicated, never including the source.
     */
    public static function extract_targets(\WP_Post $post): array {
        $targets = 'nav_menu_item' === $post->post_type
            ? self::menu_item_target($post)
            : self::content_targets($post);

        $ids = [];
        foreach ($targets as $id) {
            $id = (int) $id;

            // A page linking to itself must not keep itself off the report.
            if ($id > 0 && $id !== (int) $post->ID) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * A classic menu item's target.
     *
     * The item has no content: `post_title` is the label and the destination is
     * meta. Only an item pointing at a post object is an internal link — a
     * custom-URL item is handled by the ordinary URL path below, and a taxonomy
     * item points at something that is not a post at all.
     *
     * @param \WP_Post $post Menu item.
     * @return int[]
     */
    private static function menu_item_target(\WP_Post $post): array {
        $type = (string) get_post_meta($post->ID, self::MENU_OBJECT_TYPE_META, true);

        if ('post_type' === $type) {
            return [(int) get_post_meta($post->ID, self::MENU_OBJECT_ID_META, true)];
        }

        if ('custom' === $type) {
            $url = (string) get_post_meta($post->ID, '_menu_item_url', true);

            return '' === $url ? [] : [self::resolve_url($url)];
        }

        return [];
    }

    /**
     * The posts a source's content links to.
     *
     * Content comes from {@see Builder_Content::resolve()} rather than
     * `post_content`, which is the whole reason this is possible without
     * fetching the rendered page: the resolver reconstructs an `<a href>` for
     * the link fields a builder stores outside the content column.
     *
     * @param \WP_Post $post Source post.
     * @return int[]
     */
    private static function content_targets(\WP_Post $post): array {
        $content = Builder_Content::resolve($post);

        if ('' === trim($content) || false === strpos($content, 'href')) {
            return [];
        }

        $ids = [];

        if (class_exists('WP_HTML_Tag_Processor')) {
            $tags = new \WP_HTML_Tag_Processor($content);

            while ($tags->next_tag('a')) {
                $href = $tags->get_attribute('href');

                if (is_string($href)) {
                    $ids[] = self::resolve_url($href);
                }
            }

            return $ids;
        }

        // WordPress 6.2 and older. Matching the attribute rather than the whole
        // tag, so a link whose label contains markup is not skipped.
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i', $content, $matches)) {
            foreach ($matches[2] as $href) {
                $ids[] = self::resolve_url(html_entity_decode($href, ENT_QUOTES));
            }
        }

        return $ids;
    }

    /**
     * The post a URL points at, or 0.
     *
     * Only an address on this site can be an internal link. A root-relative one
     * always is; an absolute one has to match the site's host, which is what
     * keeps a link to an unrelated site that happens to share a path structure
     * out of the graph.
     *
     * @param string $url Link destination.
     * @return int Post ID, or 0 when the URL is external or resolves to nothing.
     */
    public static function resolve_url(string $url): int {
        $url = trim($url);

        if ('' === $url) {
            return 0;
        }

        if (isset(self::$url_memo[$url])) {
            return self::$url_memo[$url];
        }

        self::$url_memo[$url] = self::resolve_url_uncached($url);

        return self::$url_memo[$url];
    }

    /**
     * {@see resolve_url()} without the memo.
     *
     * @param string $url Link destination.
     * @return int
     */
    private static function resolve_url_uncached(string $url): int {
        // A fragment or a query string is the same page for this purpose, and
        // `url_to_postid()` reads `?p=` and `?page_id=`, so the query is kept
        // and only the fragment is dropped.
        $hash = strpos($url, '#');
        if (false !== $hash) {
            $url = substr($url, 0, $hash);
        }

        $url = trim($url);

        // An in-page anchor, and anything that is not a web address: `mailto:`,
        // `tel:`, `javascript:`, a data URI.
        if ('' === $url || 0 === strpos($url, '#')) {
            return 0;
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return 0;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);

        if (is_string($host) && '' !== $host) {
            $home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

            // `www.` is the one difference that is never a different site, and
            // a theme writing links one way while the option says the other is
            // ordinary rather than exotic.
            if (self::bare_host($host) !== self::bare_host($home_host)) {
                return 0;
            }
        } elseif (0 !== strpos($url, '/')) {
            // Protocol-relative (`//host/path`) already has a host. Anything
            // else without one is a relative path, which cannot be resolved
            // without knowing the page it sits on.
            return 0;
        }

        $post_id = (int) url_to_postid($url);

        // A product whose base the Permalinks switch removed is served by a
        // request filter url_to_postid() never runs, so its own URL resolved
        // to nothing and every product linked by it read as an orphan (#1675).
        if (0 === $post_id && class_exists(Permalinks::class)) {
            $post_id = Permalinks::base_free_product_id(self::on_home_host($url));
        }

        return $post_id;
    }

    /**
     * A link already known to be on this site, as an absolute URL on the
     * home URL's scheme and host.
     *
     * Root-relative and protocol-relative links have no host of their own,
     * and a `www.` difference was already accepted above.
     *
     * @since 2.22.0
     *
     * @param string $url Same-site link destination.
     * @return string
     */
    private static function on_home_host(string $url): string {
        $home  = wp_parse_url(home_url('/'));
        $path  = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = wp_parse_url($url, PHP_URL_QUERY);

        $absolute = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '')
            . (isset($home['port']) ? ':' . $home['port'] : '')
            . ('' === $path ? '/' : $path);

        return is_string($query) && '' !== $query ? $absolute . '?' . $query : $absolute;
    }

    /**
     * A host without its `www.` prefix, lower-cased.
     *
     * @param string $host Host.
     * @return string
     */
    private static function bare_host(string $host): string {
        $host = strtolower($host);

        return 0 === strpos($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Forget a batch of sources, entries and edges alike.
     *
     * @param int[] $post_ids Post IDs.
     * @return void
     */
    public static function forget(array $post_ids): void {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the IN list is a run of %d built from the chunk size, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare(). The per-post meta cache is cleared below.
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return;
        }

        foreach (array_chunk($post_ids, self::BULK_CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$in})",
                array_merge([self::META_KEY], $chunk)
            ));

            foreach ($chunk as $id) {
                wp_cache_delete($id, 'post_meta');
            }
        }

        self::delete_edges($post_ids);
        self::bump_revision();
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Drop every edge leaving these sources.
     *
     * @param int[] $post_ids Source post IDs.
     * @return bool False when a DELETE failed.
     */
    private static function delete_edges(array $post_ids): bool {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the IN list is a run of %d built from the chunk size; every value is passed to prepare(). Derived rows, rebuilt on demand, so there is nothing to cache.
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return true;
        }

        $table = self::table();
        $ok    = true;

        foreach (array_chunk($post_ids, self::BULK_CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));

            // Zero rows affected is success; false is a failed statement.
            if (false === $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE source_id IN ({$in})",
                $chunk
            ))) {
                $ok = false;
            }
        }

        return $ok;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Drop every edge arriving at these targets.
     *
     * @param int[] $post_ids Target post IDs.
     * @return void
     */
    private static function delete_edges_to(array $post_ids): void {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the IN list is a run of %d built from the chunk size; every value is passed to prepare(). Derived rows, rebuilt on demand, so there is nothing to cache.
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return;
        }

        $table = self::table();

        foreach (array_chunk($post_ids, self::BULK_CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE target_id IN ({$in})",
                $chunk
            ));
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Write one source's edges.
     *
     * One statement for the whole set rather than one per link: a navigation
     * block on a large site is a hundred edges from a single source.
     *
     * @param int   $source_id Source post ID.
     * @param int[] $targets   Target post IDs.
     * @return bool False when an INSERT failed.
     */
    private static function insert_edges(int $source_id, array $targets): bool {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the VALUES list is a run of (%d, %d) built from the batch size, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare(). Derived rows, rebuilt on demand, so there is nothing to cache.
        global $wpdb;

        if (empty($targets)) {
            return true;
        }

        $table = self::table();
        $ok    = true;

        foreach (array_chunk($targets, self::BULK_CHUNK) as $chunk) {
            $values = [];
            $rows   = [];

            foreach ($chunk as $target_id) {
                $rows[]   = '(%d, %d)';
                $values[] = $source_id;
                $values[] = (int) $target_id;
            }

            // IGNORE rather than a uniqueness check per row: the primary key is
            // the pair, and the duplicate case is ordinary — a page linking to
            // the same target from a button and a paragraph.
            if (false === $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (source_id, target_id) VALUES " . implode(', ', $rows),
                $values
            ))) {
                $ok = false;
            }
        }

        return $ok;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Orphan-candidate posts with no inbound internal link, per post type.
     *
     * @param string[] $post_types Orphan-candidate post types.
     * @param string[] $statuses   Post statuses.
     * @return array<string,array{counted:int, orphans:int}>
     */
    public static function totals(array $post_types, array $statuses): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every interpolated fragment is built through prepare(). The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT p.post_type,
                    COUNT(*) AS counted,
                    SUM(" . self::inbound_sql() . " = 0) AS orphans
             FROM {$wpdb->posts} p
             WHERE " . self::candidate_scope_sql($post_types, $statuses) . "
             GROUP BY p.post_type",
            ARRAY_A
        );

        $totals = [];
        foreach ((array) $rows as $row) {
            $totals[(string) $row['post_type']] = [
                'counted' => (int) $row['counted'],
                'orphans' => (int) $row['orphans'],
            ];
        }

        return $totals;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * The orphans themselves, newest first.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @param int      $limit     Most to return.
     * @return array<int,array{post_id:int, post_title:string, permalink:string, modified:string}>
     */
    public static function orphans(string $post_type, array $statuses, int $limit): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every interpolated fragment is built through prepare(). The index is itself the cache.
        global $wpdb;

        $limit = max(1, $limit);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE " . self::candidate_scope_sql([$post_type], $statuses) . "
               AND " . self::inbound_sql() . " = 0
             ORDER BY p.post_date DESC
             LIMIT %d",
            $limit
        ));

        $ids = array_map('intval', (array) $ids);
        if (empty($ids)) {
            return [];
        }

        _prime_post_caches($ids, false, false);

        $orphans = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            // The same field names {@see Thin_Content} uses for its list. The
            // two reports answer different questions about the same posts, and
            // a caller should not have to learn two shapes to read them.
            $orphans[] = [
                'post_id'    => $id,
                'post_title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'permalink'  => (string) get_permalink($post),
                'modified'   => (string) $post->post_modified_gmt,
            ];
        }

        return $orphans;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * The graph itself, for a caller that wants the edges rather than the
     * conclusion drawn from them.
     *
     * Only edges between two currently published posts, from a source the
     * site renders: a stored edge whose source or target is unpublished is kept
     * so republishing needs no reindex, and one from a menu nobody displays is
     * kept so assigning the menu needs none either, but neither is a link
     * anyone can follow, and drawing it would show a structure the site does
     * not have.
     *
     * @param int $limit Most edges to return.
     * @return array<int,array{source:int, target:int}>
     */
    public static function edges(int $limit): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the only interpolation is the table name, built from $wpdb->prefix and a literal. The index is itself the cache.
        global $wpdb;

        $table = self::table();
        $limit = max(1, $limit);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.source_id, l.target_id
             FROM `{$table}` l
             INNER JOIN {$wpdb->posts} sp ON sp.ID = l.source_id AND sp.post_status = 'publish'
             INNER JOIN {$wpdb->posts} tp ON tp.ID = l.target_id AND tp.post_status = 'publish'
             WHERE " . self::active_source_sql() . "
             ORDER BY l.source_id, l.target_id
             LIMIT %d",
            $limit
        ), ARRAY_A);

        $edges = [];
        foreach ((array) $rows as $row) {
            $edges[] = [
                'source' => (int) $row['source_id'],
                'target' => (int) $row['target_id'],
            ];
        }

        return $edges;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Correlated count of inbound links to `p.ID` that still exist.
     *
     * A stored edge is only a link while its source is a published post: an
     * edge is left behind when a source is unpublished, precisely so that
     * republishing it does not need a reindex, and counting it would keep a
     * page off the report on the strength of a link nobody can follow.
     *
     * Menu sources are published posts of their own (`nav_menu_item` items are
     * `publish`, a `wp_navigation` is too), so being published is not enough
     * for them: an edge from a menu only counts while that menu is one the
     * site renders ({@see active_source_sql()}). A page linked from nothing but
     * an old, unassigned menu is exactly as unreachable as one linked from
     * nothing at all.
     *
     * @return string Trusted SQL.
     */
    private static function inbound_sql(): string {
        global $wpdb;

        $table = self::table();

        return "(SELECT COUNT(*) FROM `{$table}` l
                 INNER JOIN {$wpdb->posts} sp ON sp.ID = l.source_id
                 WHERE l.target_id = p.ID AND sp.post_status = 'publish'
                   AND " . self::active_source_sql() . ')';
    }

    /**
     * The menus a visitor can actually see.
     *
     * Indexing every menu as a source answered "is this page in a menu", not
     * "can a visitor reach it": a page linked only from a menu that was
     * replaced and never deleted, or from a navigation post no template uses,
     * was kept off the report while nobody could follow the link.
     *
     * Classic menus are active when a location the current theme registers,
     * or a Navigation Menu widget placed in a registered sidebar, displays
     * them. A `wp_navigation` post is active when a block template, a template
     * part one includes, or a block widget placed in a registered sidebar
     * references it by `ref`, or holds a navigation block with no `ref` at
     * all, which renders the most recently published navigation (core's
     * fallback). Patterns those include are read too, since that is where a
     * theme's header usually keeps its navigation.
     *
     * Being stored is not being displayed. Theme mods keep a location's menu
     * after a theme switch to one that has no such location, a widget dragged
     * to Inactive Widgets keeps its instance, and a block theme ships header
     * and footer variants no template includes; none of those reach a visitor.
     *
     * Edges from inactive menus stay in the table and are filtered at query
     * time, the way an unpublished source's are, so assigning a menu to a
     * location needs no reindex. The answer is read live on each report and is
     * part of the cached report's signature, which is what invalidates the
     * cache when an assignment changes.
     *
     * @return array{menus:int[], navigations:int[]} Active `nav_menu` term IDs
     *                                               and `wp_navigation` post IDs,
     *                                               sorted.
     */
    public static function active_menu_sources(): array {
        if (null !== self::$active_menu_sources) {
            return self::$active_menu_sources;
        }

        $menus = [];

        if (function_exists('get_nav_menu_locations') && function_exists('get_registered_nav_menus')) {
            $registered = (array) get_registered_nav_menus();

            foreach ((array) get_nav_menu_locations() as $location => $menu_id) {
                if (isset($registered[$location])) {
                    $menus[] = (int) $menu_id;
                }
            }
        }

        foreach (self::displayed_widgets('nav_menu') as $instance) {
            if (!empty($instance['nav_menu'])) {
                $menus[] = (int) $instance['nav_menu'];
            }
        }

        $markup = '';
        $parts  = [];

        if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('get_block_templates')) {
            // Parts are written in only where something includes them, so
            // they are kept by slug rather than read as markup of their own.
            foreach ((array) get_block_templates([], 'wp_template_part') as $part) {
                $slug = (string) ($part->slug ?? '');

                if ('' !== $slug && !isset($parts[$slug])) {
                    $parts[$slug] = (string) ($part->content ?? '');
                }
            }

            foreach ((array) get_block_templates([], 'wp_template') as $template) {
                $markup .= "\n" . (string) ($template->content ?? '');
            }
        }

        foreach (self::displayed_widgets('block') as $instance) {
            if (isset($instance['content'])) {
                $markup .= "\n" . (string) $instance['content'];
            }
        }

        $seen        = [];
        $navigations = self::referenced_navigations(self::expand_includes($markup, $parts, $seen));

        self::$active_menu_sources = [
            'menus'       => self::normalise_ids($menus),
            'navigations' => self::normalise_ids($navigations),
        ];

        return self::$active_menu_sources;
    }

    /**
     * Forget the request-local answer of {@see active_menu_sources()}.
     *
     * @return void
     */
    public static function flush_active_menu_sources(): void {
        self::$active_menu_sources = null;
    }

    /**
     * Widget instances of one type that sit in a sidebar the theme displays.
     *
     * A widget's settings live in `widget_{id_base}` whether or not it is on
     * screen: dragging one to Inactive Widgets, or switching to a theme
     * without that sidebar, leaves the instance where it was. Only the ones
     * `sidebars_widgets` places in a registered sidebar are rendered.
     *
     * @param string $id_base Widget type, `nav_menu` or `block`.
     * @return array<int,array<string,mixed>>
     */
    private static function displayed_widgets(string $id_base): array {
        $instances = get_option('widget_' . $id_base, []);
        $sidebars  = get_option('sidebars_widgets', []);

        if (!is_array($instances) || empty($instances) || !is_array($sidebars)) {
            return [];
        }

        $registered = isset($GLOBALS['wp_registered_sidebars']) && is_array($GLOBALS['wp_registered_sidebars'])
            ? $GLOBALS['wp_registered_sidebars']
            : [];

        $displayed = [];

        foreach ($sidebars as $sidebar_id => $widget_ids) {
            if ('wp_inactive_widgets' === $sidebar_id || !is_array($widget_ids) || !isset($registered[$sidebar_id])) {
                continue;
            }

            foreach ($widget_ids as $widget_id) {
                if (!preg_match('/^' . preg_quote($id_base, '/') . '-(\d+)$/', (string) $widget_id, $match)) {
                    continue;
                }

                $instance = $instances[(int) $match[1]] ?? null;
                if (is_array($instance)) {
                    $displayed[] = $instance;
                }
            }
        }

        return $displayed;
    }

    /**
     * Block markup with the template parts and patterns it includes written in.
     *
     * A block theme rarely puts its navigation in a template directly: the
     * template includes a header part, and Twenty Twenty-Five's header part is
     * `<!-- wp:pattern {"slug":…} /-->` with the navigation block inside the
     * pattern. Template parts are read from the set given, theme patterns from
     * the registry and synced patterns (`wp:block` with a `ref`) from their
     * post, recursively, each one once. A part nothing includes is never
     * written in, which is what keeps an unused header variant's navigation
     * from counting.
     *
     * @param string               $markup Block markup.
     * @param array<string,string> $parts  Template part content by slug.
     * @param array<string,bool>   $seen   Includes already written in.
     * @param int                  $depth  Nesting level.
     * @return string
     */
    private static function expand_includes(string $markup, array $parts, array &$seen, int $depth = 0): string {
        if ($depth > 5
            || (false === strpos($markup, 'wp:pattern')
                && false === strpos($markup, 'wp:block ')
                && false === strpos($markup, 'wp:template-part'))
        ) {
            return $markup;
        }

        if (!preg_match_all('#<!--\s+wp:(pattern|block|template-part)\s+(\{.*?\})\s+/?-->#s', $markup, $matches, PREG_SET_ORDER)) {
            return $markup;
        }

        $included = '';

        foreach ($matches as $match) {
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) {
                continue;
            }

            $content = '';

            if ('pattern' === $match[1] && !empty($attrs['slug']) && class_exists('WP_Block_Patterns_Registry')) {
                $key = 'pattern:' . (string) $attrs['slug'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered((string) $attrs['slug']);
                $content = is_array($pattern) ? (string) ($pattern['content'] ?? '') : '';
            } elseif ('template-part' === $match[1] && !empty($attrs['slug'])) {
                $key = 'part:' . (string) $attrs['slug'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $content = $parts[(string) $attrs['slug']] ?? '';
            } elseif ('block' === $match[1] && !empty($attrs['ref'])) {
                $key = 'block:' . (int) $attrs['ref'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $block   = get_post((int) $attrs['ref']);
                $content = $block instanceof \WP_Post && 'wp_block' === $block->post_type ? (string) $block->post_content : '';
            }

            if ('' !== $content) {
                $included .= "\n" . self::expand_includes($content, $parts, $seen, $depth + 1);
            }
        }

        return $markup . $included;
    }

    /**
     * The `wp_navigation` posts a piece of block markup renders.
     *
     * A navigation block with a `ref` renders that post. A self-closing one
     * without a `ref` renders core's fallback, the most recently published
     * navigation. One without a `ref` that holds inner blocks renders those
     * inner blocks, which live in the template rather than in any post, so it
     * makes no navigation post active.
     *
     * @param string $markup Block markup.
     * @return int[]
     */
    private static function referenced_navigations(string $markup): array {
        if (false === strpos($markup, 'wp:navigation')) {
            return [];
        }

        // `wp:navigation` followed by whitespace, so `wp:navigation-link` and
        // `wp:navigation-submenu` are not read as navigations; the closing
        // `<!-- /wp:navigation -->` never matches because of its slash.
        if (!preg_match_all('#<!--\s+wp:navigation(?:\s+(\{.*?\}))?\s+(/?)-->#s', $markup, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $ids      = [];
        $fallback = false;

        foreach ($matches as $match) {
            $attrs = isset($match[1]) && '' !== $match[1] ? json_decode($match[1], true) : [];
            $ref   = is_array($attrs) && isset($attrs['ref']) ? (int) $attrs['ref'] : 0;

            if ($ref > 0) {
                $ids[] = $ref;
            } elseif ('/' === ($match[2] ?? '')) {
                $fallback = true;
            }
        }

        if ($fallback) {
            $latest = get_posts([
                'post_type'        => 'wp_navigation',
                'post_status'      => 'publish',
                'orderby'          => 'date',
                'order'            => 'DESC',
                'posts_per_page'   => 1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => false,
            ]);

            foreach ((array) $latest as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Positive, unique, sorted integers, so the answer compares equal across
     * requests and can sit in a cache signature.
     *
     * @param array<int,int|string> $ids IDs.
     * @return int[]
     */
    private static function normalise_ids(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * WHERE fragment, on the source alias `sp`, admitting an edge's source
     * only when a visitor can follow links from it.
     *
     * Every non-menu source passes; the published-status test sits beside this
     * one. A classic menu item passes when its menu is active, read through the
     * `nav_menu` term it belongs to; a navigation post passes when it is one of
     * the active ones.
     *
     * @return string Trusted SQL.
     */
    private static function active_source_sql(): string {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every IN list is a run of placeholders built from the array size, and the table names come from $wpdb; every value is passed to prepare().
        global $wpdb;

        $active  = self::active_menu_sources();
        $type_in = implode(', ', array_fill(0, count(self::MENU_POST_TYPES), '%s'));

        $clauses = [
            $wpdb->prepare("sp.post_type NOT IN ({$type_in})", self::MENU_POST_TYPES),
        ];

        if (!empty($active['navigations'])) {
            $in        = implode(', ', array_fill(0, count($active['navigations']), '%d'));
            $clauses[] = $wpdb->prepare(
                "(sp.post_type = 'wp_navigation' AND sp.ID IN ({$in}))",
                $active['navigations']
            );
        }

        if (!empty($active['menus'])) {
            $in        = implode(', ', array_fill(0, count($active['menus']), '%d'));
            $clauses[] = $wpdb->prepare(
                "(sp.post_type = 'nav_menu_item' AND EXISTS (
                    SELECT 1 FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    WHERE tr.object_id = sp.ID AND tt.taxonomy = 'nav_menu' AND tt.term_id IN ({$in})
                ))",
                $active['menus']
            );
        }

        return '(' . implode(' OR ', $clauses) . ')';
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    /**
     * WHERE fragment for the posts that can be reported as orphans.
     *
     * The front page and the posts page are excluded by name. Nothing has to
     * link to the site's home page for a visitor to reach it, and reporting it
     * would be the first row on the report of every site that has one.
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @return string Trusted SQL.
     */
    private static function candidate_scope_sql(array $post_types, array $statuses): string {
        global $wpdb;

        $sql = self::scope_sql($post_types, $statuses);

        foreach (['page_on_front', 'page_for_posts'] as $option) {
            $id = (int) get_option($option);

            if ($id > 0) {
                $sql .= $wpdb->prepare(' AND p.ID <> %d', $id);
            }
        }

        return $sql;
    }

    /**
     * WHERE fragment for everything indexed as a source.
     *
     * Menus come in on their own statuses: a `nav_menu_item` is `publish`, and
     * a `wp_navigation` may be a draft while it is being edited, in which case
     * it is not on the site and its links are not links.
     *
     * @param string[] $post_types Orphan-candidate post types.
     * @param string[] $statuses   Statuses for those types.
     * @return string Trusted SQL.
     */
    private static function source_scope_sql(array $post_types, array $statuses): string {
        return '(' . self::scope_sql($post_types, $statuses)
            . ' OR ' . self::scope_sql(self::MENU_POST_TYPES, ['publish']) . ')';
    }

    /**
     * WHERE fragment for a set of post types and statuses.
     *
     * Password-protected posts are out for the same reason a draft is: a page
     * a visitor cannot read is not a page search engines can strand.
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @return string Trusted SQL.
     */
    private static function scope_sql(array $post_types, array $statuses): string {
        global $wpdb;

        $post_types = array_values(array_filter(array_map('strval', $post_types)));
        $statuses   = array_values(array_filter(array_map('strval', $statuses)));

        if (empty($post_types) || empty($statuses)) {
            // Matches nothing, without the caller having to special-case it.
            return '1 = 0';
        }

        $type_in = implode(', ', array_fill(0, count($post_types), '%s'));
        $stat_in = implode(', ', array_fill(0, count($statuses), '%s'));

        return $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- both IN lists are runs of %s built from the array sizes, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare().
            "(p.post_type IN ({$type_in}) AND p.post_status IN ({$stat_in}) AND p.post_password = '')",
            array_merge($post_types, $statuses)
        );
    }
}
