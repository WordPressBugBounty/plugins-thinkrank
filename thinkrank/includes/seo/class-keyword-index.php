<?php
/**
 * Inverted index of focus keywords across the site.
 *
 * @package ThinkRank\SEO
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keyword Index
 *
 * Focus keywords exist only per post today (#568). The edit-time "you already
 * used this focus keyword" warning is the only conflict signal there is, and it
 * answers a different question: it asks whether *this* keyword is used
 * elsewhere, one keyword at a time, while someone is typing. Nothing can ask
 * which keywords are used more than once, or which pages target none, because
 * the keywords live in a serialized array in `_thinkrank_focus_keywords` and
 * SQL cannot group by a value inside a blob.
 *
 * So the same shape as the indexes before it, and for the same reason the link
 * graph needed a table: the question is asked backwards. One row per
 * (post, keyword) with an index on the keyword makes "used by more than one
 * post" a grouped query rather than a walk over every post on the site.
 *
 * Two decisions worth knowing about.
 *
 * **Identity is case-insensitive, because the plugin already says it is.**
 * {@see Focus_Keywords::normalize()} dedupes with `mb_strtolower()`, so
 * `Best Shoes` and `best shoes` are one keyword on a post and must be one
 * keyword across the site too. The stored hash is of the lower-cased form; the
 * readable keyword rides alongside for display.
 *
 * Doing it in the hash rather than leaning on the database is the point. The
 * edit-time warning's serialized LIKE is case-insensitive only because the
 * column's collation happens to be — on a `_bin` collation it is not, and the
 * plugin's own idea of what counts as one keyword would quietly change with
 * the database. Lower-casing before hashing makes the answer the same
 * everywhere.
 *
 * **What the warning cannot see at all is another post type.** It scopes its
 * query to the post type being edited, so a blog post and a product competing
 * for one keyword never warn, on any collation. The map is not scoped that
 * way, which on a store is where most real conflicts are.
 *
 * **The scope is the warning's scope.** Published, scheduled, draft, pending
 * and private, because those are the statuses the per-post warning already
 * counts. A draft competing with a published page for the same keyword is a
 * conflict worth knowing about before it is published, and two features
 * disagreeing about which posts count would be worse than either answer.
 *
 * @since 2.21.3
 */
class Keyword_Index {

    /**
     * Marker entry: this post's keywords are in the table, and the format they
     * were indexed with.
     */
    public const META_KEY = '_thinkrank_keyword_index';

    /**
     * Index format. Bumping it retires every entry at once.
     *
     * 2 added the source fingerprint to the marker ({@see verify()}). A `v1`
     * marker cannot be checked against the meta it was built from, so every
     * one is retired and rebuilt with the fingerprint.
     */
    public const VERSION = 2;

    /**
     * Option holding where the next {@see verify()} batch starts.
     */
    private const VERIFY_CURSOR_OPTION = 'thinkrank_keyword_index_verify_cursor';

    /**
     * Markers one {@see verify()} call checks against their post's meta.
     *
     * One indexed read of postmeta and one batched meta priming, so it is
     * cheap enough to run on every read of the map. A site with more entries
     * than this is swept a batch per read, round and round.
     */
    public const VERIFY_BATCH = 200;

    /**
     * Option holding the revision counter.
     */
    private const REVISION_OPTION = 'thinkrank_keyword_index_revision';

    /**
     * Statuses the map covers.
     *
     * The list {@see \ThinkRank\API\Focus_Keyword_Usage_Endpoint} already
     * counts, so the site-wide report and the edit-time warning cannot
     * disagree about which posts are in play.
     *
     * @var string[]
     */
    public const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /**
     * Posts per `IN (...)` list when retiring entries in bulk.
     */
    private const BULK_CHUNK = 500;

    /**
     * Most entries one refresh call will build, and the time it may spend.
     *
     * The snippet index's 500/2.0 rather than the word count index's 50/3.0:
     * this reads two meta values and writes a handful of rows. There is no
     * builder tree to resolve, which is the cost that forces the smaller batch
     * on the other two.
     */
    private const REFRESH_MAX_POSTS   = 500;
    private const REFRESH_MAX_SECONDS = 2.0;

    /**
     * Times one build may start over because the post's keywords or its claim
     * moved underneath it, before the post is left for the next refresh.
     */
    private const BUILD_ATTEMPTS = 3;

    /**
     * Register invalidation hooks. Runs on every request, because keywords are
     * edited from the metabox, the post list, the AI writer and imports.
     *
     * @return void
     */
    public function init(): void {
        // The keyword-specific signal, fired by Focus_Keywords::save() itself.
        // Hooked as well as `save_post` rather than instead of it: an import or
        // a direct meta write does not go through save().
        add_action('thinkrank_focus_keywords_saved', [self::class, 'mark_post_stale'], 10, 1);

        add_action('save_post', [self::class, 'mark_post_stale'], 99, 1);

        add_action('added_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('updated_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'on_meta_change'], 10, 3);

        add_action('transition_post_status', [self::class, 'on_status_change'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_post_deleted'], 10, 1);
    }

    /**
     * Post meta whose change changes a post's keywords.
     *
     * Both the array and the legacy scalar: an importer that writes only the
     * old single-value key still changes what the post targets, and
     * {@see Focus_Keywords::get()} reads it as a fallback.
     *
     * @return string[]
     */
    public static function watched_meta(): array {
        return [Focus_Keywords::META_KEY, Focus_Keywords::LEGACY_META_KEY];
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
     * Announced in both directions here, unlike the other indexes, because the
     * scope is five statuses rather than one: a post moving from `draft` to
     * `publish` stays in the map, and only a move across the edge of that set
     * — into the trash, or back out of it — changes the answer. Both edges
     * matter, since a keyword conflict appears when a post joins the map just
     * as surely as it disappears when one leaves.
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

        $was_in = in_array($old_status, self::STATUSES, true);
        $is_in  = in_array($new_status, self::STATUSES, true);

        if ($was_in === $is_in) {
            return;
        }

        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        self::mark_post_stale($post->ID);
        self::bump_revision();
    }

    /**
     * A post is about to be deleted for good.
     *
     * Its rows go now rather than on the next pass: a row whose post no longer
     * exists can never be rebuilt, because nothing will list that post as
     * pending again.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function on_post_deleted($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $had_entry = null !== self::decode((string) get_post_meta($post_id, self::META_KEY, true));

        self::delete_rows([$post_id]);

        if ($had_entry) {
            self::bump_revision();
        }
    }

    /**
     * Make one post's entry stale, rows and all.
     *
     * The rows go with the marker. Leaving them would let a keyword the author
     * has just removed keep reporting a conflict until the entry is rebuilt,
     * which is the one answer this index exists to get right.
     *
     * Rows first, then the marker. The other way round, a build could claim
     * the entry in the gap between the two, write the post's rows, and have
     * this DELETE remove them before it swapped its claim for a current
     * marker: a marker vouching for rows that are no longer there. Deleting
     * the marker last means any build that overlaps this call loses its
     * claim, so it can only end with the post pending.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function mark_post_stale($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        self::delete_rows([$post_id]);

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
     * The fingerprint is of the keyword meta the rows were built from
     * ({@see source_fingerprint()}), so a later read can tell whether that meta
     * still says the same thing. The hooks are the fast path; the fingerprint
     * is what catches a write they never saw.
     *
     * @param int    $keywords    Keywords indexed for the post.
     * @param string $fingerprint Fingerprint of the source meta.
     * @return string
     */
    public static function encode(int $keywords, string $fingerprint): string {
        return 'v' . self::VERSION . ':' . max(0, $keywords) . ':' . $fingerprint;
    }

    /**
     * Split a current-format marker into its count and fingerprint.
     *
     * @param string $value Stored value.
     * @return array{0:int,1:string}|null Null when malformed or from an older
     *                                    index format.
     */
    private static function parse(string $value): ?array {
        $prefix = 'v' . self::VERSION . ':';

        if (0 !== strpos($value, $prefix)) {
            return null;
        }

        $parts = explode(':', substr($value, strlen($prefix)));

        if (2 !== count($parts) || !ctype_digit($parts[0]) || 1 !== preg_match('/^[0-9a-f]{32}$/', $parts[1])) {
            return null;
        }

        return [(int) $parts[0], $parts[1]];
    }

    /**
     * Decode a marker entry.
     *
     * @param string $value Stored value.
     * @return int|null Keyword count, or null when malformed or from an older
     *                  index format.
     */
    public static function decode(string $value): ?int {
        $parsed = self::parse($value);

        return null === $parsed ? null : $parsed[0];
    }

    /**
     * The source fingerprint a marker entry was built with.
     *
     * @param string $value Stored value.
     * @return string|null Null when malformed or from an older index format.
     */
    public static function decode_fingerprint(string $value): ?string {
        $parsed = self::parse($value);

        return null === $parsed ? null : $parsed[1];
    }

    /**
     * LIKE pattern matching a current-format entry for a post with no keywords.
     *
     * @return string
     */
    private static function untargeted_like(): string {
        global $wpdb;

        return $wpdb->esc_like('v' . self::VERSION . ':0:') . '%';
    }

    /**
     * Re-check a bounded batch of entries against the meta they were built from.
     *
     * The hooks in {@see init()} only fire while ThinkRank is loaded. A keyword
     * written with the plugin switched off, by WP-CLI with `--skip-plugins`, or
     * by a direct SQL import changes the meta and leaves a current marker
     * vouching for the old rows, forever. So every read of the map takes the
     * next batch of markers, primes their posts' meta in one query, and
     * compares each post's fingerprint now with the one in its marker. A
     * mismatch is made stale like any edit, which bumps the revision, retires
     * the cached report, and has the next refresh rebuild the post.
     *
     * Bounded to {@see VERIFY_BATCH} markers per call, with a cursor that wraps,
     * so a large site is swept a batch per read rather than scanned whole.
     *
     * @since 2.21.3
     *
     * @param int $limit Most markers to check.
     * @return int How many entries were found stale and retired.
     */
    public static function verify(int $limit = self::VERIFY_BATCH): int {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one bounded read of the marker rows by their indexed key; the meta it is checked against is primed through the cache API below.
        global $wpdb;

        $limit  = max(1, $limit);
        $cursor = max(0, (int) get_option(self::VERIFY_CURSOR_OPTION, 0));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND post_id > %d
             ORDER BY post_id ASC
             LIMIT %d",
            self::META_KEY,
            $cursor,
            $limit
        ), ARRAY_A);

        $rows = is_array($rows) ? $rows : [];

        // A short batch reached the end: the next one starts from the top.
        $next = count($rows) < $limit ? 0 : (int) end($rows)['post_id'];
        if ($next !== $cursor) {
            update_option(self::VERIFY_CURSOR_OPTION, $next, false);
        }

        $markers = [];
        foreach ($rows as $row) {
            // Claims and older formats are pending already; the refresh owns them.
            $fingerprint = self::decode_fingerprint((string) $row['meta_value']);
            if (null !== $fingerprint) {
                $markers[(int) $row['post_id']] = $fingerprint;
            }
        }

        if (empty($markers)) {
            return 0;
        }

        update_meta_cache('post', array_keys($markers));

        $stale = 0;
        foreach ($markers as $post_id => $fingerprint) {
            if (self::fingerprint_of($post_id) !== $fingerprint) {
                self::mark_post_stale($post_id);
                $stale++;
            }
        }

        return $stale;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
     * The keyword table.
     *
     * @return string
     */
    public static function table(): string {
        global $wpdb;

        return $wpdb->prefix . 'thinkrank_post_keywords';
    }

    /**
     * The identity of a keyword, site-wide.
     *
     * Lower-cased before hashing, because that is the comparison
     * {@see Focus_Keywords::normalize()} already dedupes a single post's
     * keywords by. Two posts whose keywords differ only in case are targeting
     * the same keyword, and saying otherwise would make the report disagree
     * with the field the user typed them into.
     *
     * @param string $keyword Keyword as stored.
     * @return string 32-character hash.
     */
    public static function hash(string $keyword): string {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($keyword)
            : strtolower($keyword);

        // Not a security boundary: this is a grouping key for free text, and
        // md5 is the shortest fixed-width digest that fits the column.
        return md5(trim($lower));
    }

    /**
     * Build a bounded batch of missing entries.
     *
     * @param string[] $post_types Post types in scope.
     * @return int How many entries remain to build after this batch.
     */
    public static function refresh(array $post_types): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        $scope   = self::scope_sql($post_types);
        $started = microtime(true);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . $scope . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            self::META_KEY,
            self::current_like(),
            self::REFRESH_MAX_POSTS
        ));

        $ids = array_map('intval', (array) $ids);

        // Whether this batch empties the queue: it found fewer posts than it
        // was allowed to take, and built every one. Then nothing is left to
        // count, and the exact count below, a scan of every post in scope, is
        // skipped on the call that finishes the index and on the ordinary one
        // that rebuilds a single edited post.
        $drained = count($ids) < self::REFRESH_MAX_POSTS;

        if (!empty($ids)) {
            // Posts only. Each build reads its keywords fresh after claiming
            // the entry ({@see build()}), so meta primed here would be dropped
            // unread.
            _prime_post_caches($ids, false, false);

            $built = 0;

            foreach ($ids as $post_id) {
                // A failed or overtaken build leaves the post pending; the
                // next refresh retries it.
                if (self::build($post_id)) {
                    $built++;
                }

                if (microtime(true) - $started > self::REFRESH_MAX_SECONDS) {
                    break;
                }
            }

            if ($built !== count($ids)) {
                $drained = false;
            }

            self::bump_revision();
        }

        return $drained ? 0 : max(0, self::pending($post_types));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * How many posts in scope still have no current entry.
     *
     * @param string[] $post_types Post types in scope.
     * @return int
     */
    public static function pending(array $post_types): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql($post_types) . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)",
            self::META_KEY,
            self::current_like()
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Compute and store one post's rows.
     *
     * A post with no keywords still gets a marker. That is the whole reason the
     * marker exists: the table cannot tell "targets nothing" from "not indexed
     * yet", and "which pages target no keyword" is half of what the map is for.
     *
     * The marker is also what takes the post off the pending list, so it may
     * only say "current" when the rows it vouches for are the post's keywords
     * as they are now. So the build publishes it last, and only when all of
     * these held:
     *
     * - **It was the only build of this post.** Two refreshes (two tabs, or a
     *   poll and a cron) can build one post at once, and one with keywords
     *   read before a save can write its rows after the other's, under the
     *   other's claim. A per-post database lock makes the second one step
     *   aside and leave the post for the next refresh.
     * - **Every row write succeeded, and the rows are there.** A statement
     *   that fails, or reports an error through `$wpdb->last_error`, fails
     *   the build; then the post's rows are read back and compared with the
     *   keywords, which catches an INSERT IGNORE that quietly stored less.
     * - **The keywords did not move underneath it.** The stored keyword meta
     *   is fingerprinted from the database before the read and again after
     *   the writes. A change in between, including one written straight to
     *   the table without the hooks that would invalidate the entry, sends the
     *   build round again with the new keywords, a few times at most.
     * - **Nothing invalidated the entry meanwhile.** The build stores a
     *   one-off claim before reading anything, which is not a current-format
     *   value and so leaves the post pending, and swaps it for the current
     *   entry in one conditional UPDATE. An invalidation deletes the claim,
     *   the UPDATE matches nothing, and the build goes round again.
     *
     * @param int $post_id Post ID.
     * @return bool Whether the entry is now current.
     */
    private static function build(int $post_id): bool {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
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
     * One attempt at a post's entry.
     *
     * @param int $post_id Post ID.
     * @return bool|null True when the entry is current, false when a write
     *                   failed, null when the source or the claim moved and
     *                   the build should go round again.
     */
    private static function build_once(int $post_id): ?bool {
        $claim = 'claim:' . md5(uniqid((string) $post_id, true));

        update_post_meta($post_id, self::META_KEY, $claim);

        $before = self::source_fingerprint($post_id);

        // Through Focus_Keywords rather than the meta directly, so the legacy
        // scalar and the `thinkrank_focus_keywords` filter an extension may add
        // to are both honoured — the map should show what the post actually
        // targets, not what one meta key happens to hold.
        $keywords = Focus_Keywords::get($post_id);

        if (
            !self::delete_rows([$post_id])
            || !self::insert_rows($post_id, $keywords)
            || !self::rows_match($post_id, $keywords)
        ) {
            delete_post_meta($post_id, self::META_KEY);

            return false;
        }

        if (self::source_fingerprint($post_id) !== $before) {
            return null;
        }

        return self::settle($post_id, $claim, self::encode(count($keywords), $before)) ? true : null;
    }

    /**
     * The stored keyword meta, as the database holds it now.
     *
     * The post's meta cache is dropped first, so this reads the table rather
     * than a copy loaded before the build began.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function source_fingerprint(int $post_id): string {
        wp_cache_delete($post_id, 'post_meta');

        return self::fingerprint_of($post_id);
    }

    /**
     * The stored keyword meta, as the meta cache holds it.
     *
     * The same value {@see source_fingerprint()} returns, without dropping the
     * cache first, so {@see verify()} can check a batch it primed in one query.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function fingerprint_of(int $post_id): string {
        $values = [];
        foreach (self::watched_meta() as $key) {
            $values[$key] = get_post_meta($post_id, $key, false);
        }

        // Not a security boundary: a change detector for a few meta values.
        return md5(serialize($values)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashed, never stored or unserialized.
    }

    /**
     * Whether the post's rows are exactly these keywords.
     *
     * @param int      $post_id  Post ID.
     * @param string[] $keywords Keywords, as stored.
     * @return bool
     */
    private static function rows_match(int $post_id, array $keywords): bool {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is the plugin's own; the post ID is a placeholder. Read back to verify a write, so it must not come from a cache.
        global $wpdb;

        $expected = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ('' !== $keyword) {
                $expected[self::hash($keyword)] = true;
            }
        }

        $table  = self::table();
        $stored = $wpdb->get_col($wpdb->prepare(
            "SELECT keyword_hash FROM `{$table}` WHERE post_id = %d",
            $post_id
        ));

        if ('' !== (string) $wpdb->last_error || !is_array($stored)) {
            return false;
        }

        $stored = array_unique(array_map('strval', $stored));
        $wanted = array_keys($expected);

        sort($stored);
        sort($wanted);

        return $stored === $wanted;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

        return 'thinkrank_kwidx_' . substr(md5((string) $wpdb->prefix), 0, 12) . '_' . $post_id;
    }

    /**
     * Swap a build's claim for the current entry, if the claim is still there.
     *
     * @param int    $post_id Post ID.
     * @param string $claim   Token the build stored.
     * @param string $entry   Current-format entry.
     * @return bool False when the claim was gone (the post was invalidated
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
     * Drop every row for these posts.
     *
     * @param int[] $post_ids Post IDs.
     * @return bool False when a DELETE failed.
     */
    private static function delete_rows(array $post_ids): bool {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the IN list is a run of %d built from the chunk size, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare(). Derived rows, rebuilt on demand, so there is nothing to cache.
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return true;
        }

        $table = self::table();
        $ok    = true;

        foreach (array_chunk($post_ids, self::BULK_CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));

            // Zero rows affected is success; false, or an error the
            // statement reported without failing, is not.
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE post_id IN ({$in})",
                $chunk
            ));

            if (false === $deleted || '' !== (string) $wpdb->last_error) {
                $ok = false;
            }
        }

        return $ok;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Write one post's rows.
     *
     * @param int      $post_id  Post ID.
     * @param string[] $keywords Keywords, as stored.
     * @return bool False when the INSERT failed or reported an error.
     */
    private static function insert_rows(int $post_id, array $keywords): bool {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the VALUES list is a run of (%d, %s, %s) built from the batch size, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare(). Derived rows, rebuilt on demand, so there is nothing to cache.
        global $wpdb;

        if (empty($keywords)) {
            return true;
        }

        $table  = self::table();
        $rows   = [];
        $values = [];

        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ('' === $keyword) {
                continue;
            }

            $rows[]   = '(%d, %s, %s)';
            $values[] = $post_id;
            $values[] = self::hash($keyword);
            // The column is the display copy, so a keyword longer than it can
            // hold is truncated rather than refused: the hash is what the
            // grouping uses, and it is taken from the whole keyword.
            $values[] = self::clip($keyword);
        }

        if (empty($rows)) {
            return true;
        }

        // IGNORE because the pair is the primary key and a post whose keywords
        // differ only in case resolves to one row by design.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$table}` (post_id, keyword_hash, keyword) VALUES " . implode(', ', $rows),
            $values
        ));

        return false !== $inserted && '' === (string) $wpdb->last_error;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Trim a keyword to what the display column holds.
     *
     * @param string $keyword Keyword.
     * @return string
     */
    private static function clip(string $keyword): string {
        return function_exists('mb_substr')
            ? mb_substr($keyword, 0, 191)
            : substr($keyword, 0, 191);
    }

    /**
     * Keywords more than one post in scope targets.
     *
     * @param string[] $post_types Post types in scope.
     * @param int      $limit      Most keyword groups to return.
     * @param string   $visibility Trusted WHERE fragment from
     *                             {@see visibility_sql()}, '' for none.
     * @return array<int,array{keyword:string, hash:string, posts:int}>
     */
    public static function duplicates(array $post_types, int $limit, string $visibility = ''): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return [];
        }

        $table = self::table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT k.keyword_hash, MIN(k.keyword) AS keyword, COUNT(DISTINCT k.post_id) AS posts
             FROM `{$table}` k
             INNER JOIN {$wpdb->posts} p ON p.ID = k.post_id
             WHERE " . self::scope_sql($post_types, $visibility) . "
             GROUP BY k.keyword_hash
             HAVING posts > 1
             ORDER BY posts DESC, keyword ASC
             LIMIT %d",
            max(1, $limit)
        ), ARRAY_A);

        $duplicates = [];
        foreach ((array) $rows as $row) {
            $duplicates[] = [
                'keyword' => (string) $row['keyword'],
                'hash'    => (string) $row['keyword_hash'],
                'posts'   => (int) $row['posts'],
            ];
        }

        return $duplicates;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * The posts sharing each of several keywords, newest first, in one query.
     *
     * The report lists up to fifty conflict groups, and asking for each
     * group's posts separately was fifty more round trips, each with its own
     * cache priming, on every rebuild and every poll while the index fills.
     * One query fetches the pairs for every group, they are grouped here, and
     * every post that is listed is primed once.
     *
     * Each keyword is its own parenthesised SELECT with its own LIMIT, joined
     * by UNION ALL, so the database stops at `$per_key` posts per keyword
     * however many posts share it. One LIMIT over the whole IN list could only
     * be bounded by the sum of the group sizes, and a keyword two thousand
     * posts target would load two thousand rows to keep ten. This shape works
     * on MySQL 5.7 and MariaDB, which have no window functions to number the
     * rows of each group.
     *
     * @param array<string,int> $groups     Keyword hash => posts in the group.
     *                                      A group's size caps its LIMIT too.
     * @param string[]          $post_types Post types in scope.
     * @param int               $per_key    Most posts to return per keyword.
     * @param string            $visibility Trusted WHERE fragment from
     *                                      {@see visibility_sql()}, '' for none.
     * @return array<string,array<int,array{post_id:int, post_title:string, permalink:string, post_status:string}>>
     *         Keyword hash => posts. A hash with nothing in scope is absent.
     */
    public static function posts_for_many(array $groups, array $post_types, int $per_key, string $visibility = ''): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- each SELECT in the union is built through prepare(), and the scope fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $groups = array_filter(array_map('intval', $groups), static fn(int $posts): bool => $posts > 0);

        if (empty($post_types) || empty($groups)) {
            return [];
        }

        $table   = self::table();
        $scope   = self::scope_sql($post_types, $visibility);
        $per_key = max(1, $per_key);
        $selects = [];

        foreach ($groups as $hash => $size) {
            $selects[] = $wpdb->prepare(
                "(SELECT k.keyword_hash, k.post_id, p.post_date FROM `{$table}` k
                  INNER JOIN {$wpdb->posts} p ON p.ID = k.post_id
                  WHERE k.keyword_hash = %s AND " . $scope . "
                  ORDER BY p.post_date DESC, p.ID DESC
                  LIMIT %d)",
                (string) $hash,
                min($per_key, $size)
            );
        }

        // Each SELECT is ordered for its own LIMIT; the order of the union is
        // set again outside, so every group comes back newest first.
        $rows = $wpdb->get_results(
            implode(' UNION ALL ', $selects) . ' ORDER BY keyword_hash, post_date DESC, post_id DESC',
            ARRAY_A
        );

        $ids = [];

        foreach ((array) $rows as $row) {
            $hash = (string) $row['keyword_hash'];

            if (count($ids[$hash] ?? []) < $per_key) {
                $ids[$hash][] = (int) $row['post_id'];
            }
        }

        if (empty($ids)) {
            return [];
        }

        $described = [];
        foreach (self::describe(array_values(array_unique(array_merge(...array_values($ids))))) as $post) {
            $described[$post['post_id']] = $post;
        }

        $posts = [];
        foreach ($ids as $hash => $group_ids) {
            foreach ($group_ids as $id) {
                if (isset($described[$id])) {
                    $posts[$hash][] = $described[$id];
                }
            }
        }

        return $posts;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Posts in scope that have been indexed and target nothing.
     *
     * Indexed is the operative word: a post with no marker has not been looked
     * at yet, and reporting it as targeting no keyword would fill the report
     * with the whole site on the first pass.
     *
     * @param string[] $post_types Post types in scope.
     * @param int      $limit      Most posts to return.
     * @param string   $visibility Trusted WHERE fragment from
     *                             {@see visibility_sql()}, '' for none.
     * @return array<int,array{post_id:int, post_title:string, permalink:string, post_status:string}>
     */
    public static function untargeted(array $post_types, int $limit, string $visibility = ''): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return [];
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql($post_types, $visibility) . "
               AND m.meta_value LIKE %s
             ORDER BY p.post_date DESC
             LIMIT %d",
            self::META_KEY,
            self::untargeted_like(),
            max(1, $limit)
        ));

        return self::describe(array_map('intval', (array) $ids));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * The headline numbers.
     *
     * @param string[] $post_types Post types in scope.
     * @param string   $visibility Trusted WHERE fragment from
     *                             {@see visibility_sql()}, '' for none.
     * @return array{indexed:int, targeted:int, untargeted:int, keywords:int, conflicted:int}
     */
    public static function totals(array $post_types, string $visibility = ''): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the interpolated fragment is built by scope_sql() through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $empty = [
            'indexed'    => 0,
            'targeted'   => 0,
            'untargeted' => 0,
            'keywords'   => 0,
            'conflicted' => 0,
        ];

        if (empty($post_types)) {
            return $empty;
        }

        $table = self::table();
        $scope = self::scope_sql($post_types, $visibility);

        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS indexed,
                    SUM(m.meta_value LIKE %s) AS untargeted
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . $scope . "
               AND m.meta_value LIKE %s",
            self::untargeted_like(),
            self::META_KEY,
            self::current_like()
        ), ARRAY_A);

        $keywords = $wpdb->get_row(
            "SELECT COUNT(*) AS keywords, SUM(posts > 1) AS conflicted FROM (
                SELECT COUNT(DISTINCT k.post_id) AS posts
                FROM `{$table}` k
                INNER JOIN {$wpdb->posts} p ON p.ID = k.post_id
                WHERE {$scope}
                GROUP BY k.keyword_hash
             ) g",
            ARRAY_A
        );

        $indexed    = (int) ($counts['indexed'] ?? 0);
        $untargeted = (int) ($counts['untargeted'] ?? 0);

        return [
            'indexed'    => $indexed,
            'targeted'   => max(0, $indexed - $untargeted),
            'untargeted' => $untargeted,
            'keywords'   => (int) ($keywords['keywords'] ?? 0),
            'conflicted' => (int) ($keywords['conflicted'] ?? 0),
        ];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Turn post IDs into something a reader can act on.
     *
     * Carries nothing that depends on who is asking: this goes into a cached
     * report shared by every user, and an `edit_url` frozen into it would be
     * handed to whoever reads it next.
     *
     * @param int[] $ids Post IDs.
     * @return array<int,array{post_id:int, post_title:string, permalink:string, post_status:string}>
     */
    private static function describe(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        _prime_post_caches($ids, false, false);

        $described = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $described[] = [
                'post_id'     => (int) $post->ID,
                'post_title'  => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'permalink'   => (string) get_permalink($post),
                // A draft and a published page competing for one keyword are
                // not the same finding, and the reader cannot tell them apart
                // from a title.
                'post_status' => (string) $post->post_status,
            ];
        }

        return $described;
    }

    /**
     * WHERE fragment for the posts in scope.
     *
     * Password-protected posts stay in, unlike the thin content and orphan
     * reports. Those ask what a visitor can read; this asks what the site is
     * trying to rank for, and a protected page with a focus keyword is still
     * competing for it.
     *
     * @param string[] $post_types Post types.
     * @param string   $visibility Trusted WHERE fragment from
     *                             {@see visibility_sql()}, '' for none.
     * @return string Trusted SQL.
     */
    private static function scope_sql(array $post_types, string $visibility = ''): string {
        global $wpdb;

        $post_types = array_values(array_filter(array_map('strval', $post_types)));

        if (empty($post_types)) {
            // Matches nothing, without the caller having to special-case it.
            return '1 = 0';
        }

        $type_in = implode(', ', array_fill(0, count($post_types), '%s'));
        $stat_in = implode(', ', array_fill(0, count(self::STATUSES), '%s'));

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- both IN lists are runs of %s built from the array sizes, so the sniff cannot see the placeholders it looks for; every value is still passed to prepare().
            "(p.post_type IN ({$type_in}) AND p.post_status IN ({$stat_in}))",
            array_merge($post_types, self::STATUSES)
        );

        return '' === $visibility ? $sql : '(' . $sql . ' AND (' . $visibility . '))';
    }

    /**
     * WHERE fragment limiting the map to the posts the current user may read.
     *
     * The map covers private, draft, pending and scheduled posts, and the
     * section capability that opens it (`thinkrank_global_seo`) is delegable on
     * its own, so it does not prove the user may read any of them. Without
     * this, a role given the Bulk SEO section could list the titles, links and
     * target keywords of private posts it cannot open.
     *
     * Per post type, the rule {@see Snippet_Index::visibility_sql()} already
     * applies to the snippet editor on the same screen, which is the SQL form
     * of `map_meta_cap('read_post')` for exactly these statuses: published for
     * anyone, private with the type's `read_private_posts` or as author, the
     * rest with `edit_others_posts` or as author. Applied before grouping and
     * counting, so the totals, the conflict groups and the untargeted list
     * describe the same posts, and a conflict with a post the user cannot see
     * is not reported at all.
     *
     * @param string[] $post_types Post types in scope.
     * @return string Trusted SQL, '' when the user may read every post in scope.
     */
    public static function visibility_sql(array $post_types): string {
        global $wpdb;

        $restricted = false;
        $clauses    = [];

        foreach (array_values(array_filter(array_map('strval', $post_types))) as $post_type) {
            $visibility = Snippet_Index::visibility_sql($post_type, 'p');

            if ('' === $visibility) {
                $clauses[] = $wpdb->prepare('p.post_type = %s', $post_type);
                continue;
            }

            $restricted = true;
            $clauses[]  = $wpdb->prepare('(p.post_type = %s AND (', $post_type) . $visibility . '))';
        }

        return $restricted ? implode(' OR ', $clauses) : '';
    }
}
