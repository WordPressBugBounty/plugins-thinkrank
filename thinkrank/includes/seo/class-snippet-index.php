<?php
/**
 * Persisted issue index for Bulk Snippets.
 *
 * @package ThinkRank\SEO
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Snippet Index
 *
 * Bulk Snippets filters and counts by issue, and an issue depends on each
 * post's *rendered* title and description — values that only exist once the
 * post type's template is resolved. Resolving every post on every request
 * made a page of 20 cost as much as the whole post type, which a store with
 * 20,000 products cannot afford (#727 review).
 *
 * So each post's verdict is computed once and stored in one post meta value,
 * `{generation}:{flags}:{title key}:{description key}`, and every list, count
 * and page is answered by SQL over it:
 *
 * - **flags** is a bitmask of the issues that depend on the post alone.
 * - **title key** is a hash of the title the page renders
 *   ({@see Snippet_Issues::duplicate_key()}), and **description key** a hash
 *   of the description it renders
 *   ({@see Snippet_Issues::description_duplicate_key()}). Duplicates are found
 *   at query time by grouping on them, so fixing one of two duplicates clears
 *   the other without touching the other's entry.
 * - **generation** ties the entry to the global inputs (templates, robots
 *   settings, site name, separator). Changing any of them bumps the
 *   generation, which makes every entry stale at once without a write per post.
 *
 * Grouping is **sitewide**: two pages carrying the same title are competing
 * with each other whether or not they are the same post type, which is how a
 * post and a page built from one bad template collide (#564). Only entries at
 * the current generation take part, so a post type the index has not reached
 * yet cannot invent a duplicate — coverage growing can only reveal more.
 *
 * A post's own inputs (its title, content, SEO meta, robots meta, terms) mark
 * just that post stale. Stale entries are rebuilt a bounded batch at a time by
 * {@see self::refresh()}, so no request does unbounded work.
 *
 * @since 2.8.0
 */
class Snippet_Index {

    /**
     * Post meta holding the entry.
     */
    public const META_KEY = '_thinkrank_snippet_index';

    /**
     * Option holding the current generation.
     */
    public const GENERATION_OPTION = 'thinkrank_snippet_index_generation';

    /**
     * Option counting how many times entries have been rebuilt.
     *
     * The generation answers "did a global input change". This answers "did
     * any entry change", which is what a report derived from the whole index
     * has to know: one post's title edited to match another's changes the
     * duplicate report without touching the generation. Bumped once per batch
     * rather than once per entry, so a 500-post rebuild is one extra write.
     *
     * @since 2.10.0
     */
    public const REVISION_OPTION = 'thinkrank_snippet_index_revision';

    /**
     * Version of the rules that turn a snapshot into an entry.
     *
     * Entries carry no record of how their keys were computed, so a change to
     * those rules would leave every stored entry "current" with a key the new
     * code would never produce: two posts indexed before a change and one
     * after could not group. Raising this bumps the generation once on the
     * next request, so everything is rebuilt under the new rules.
     *
     * - 2: keys compare entity-decoded text, and lengths are measured on it
     *   ({@see Snippet_Issues::duplicate_key()}).
     *
     * @since 2.10.0
     */
    public const KEY_FORMAT = 2;

    /**
     * Option recording the {@see self::KEY_FORMAT} the stored entries follow.
     *
     * @since 2.10.0
     */
    public const KEY_FORMAT_OPTION = 'thinkrank_snippet_index_key_format';

    /**
     * Taxonomy whose term names render into titles, through `%category%`.
     *
     * @since 2.10.0
     */
    private const RENDERED_TAXONOMY = 'category';

    /**
     * Term names captured before an edit, keyed by term ID, so the edit can
     * tell whether the name, the only thing a title renders, changed.
     *
     * @since 2.10.0
     *
     * @var array<int,string>
     */
    private static $term_names_before = [];

    /**
     * Most entries one refresh call will build, and the time it may spend.
     */
    private const REFRESH_MAX_POSTS   = 500;
    private const REFRESH_MAX_SECONDS = 2.0;

    /**
     * Post meta whose change changes a post's verdict.
     */
    private const WATCHED_META = [
        '_thinkrank_seo_title',
        '_thinkrank_meta_description',
        '_thinkrank_focus_keyword',
        '_thinkrank_focus_keywords',
        '_thinkrank_robots_meta',
        '_thinkrank_robots_meta_enabled',
    ];

    /**
     * Options whose change changes every post's verdict.
     */
    private const WATCHED_OPTIONS = [
        'thinkrank_global_seo_settings',
        'thinkrank_global_robot_meta_settings',
        'blogname',
        // %date% and %modified% render through get_the_date() in the site's
        // date format, and in its language.
        'date_format',
        'WPLANG',
    ];

    /**
     * Register invalidation hooks. Runs on every request, not only on the
     * Bulk Snippets screen, because edits happen everywhere else.
     *
     * @return void
     */
    public function init(): void {
        add_action('save_post', [self::class, 'mark_post_stale'], 99, 1);
        add_action('set_object_terms', [self::class, 'mark_post_stale'], 10, 1);

        add_action('added_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('updated_post_meta', [self::class, 'on_meta_change'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'on_meta_change'], 10, 3);

        add_action('updated_option', [self::class, 'on_option_change'], 10, 1);
        add_action('added_option', [self::class, 'on_option_change'], 10, 1);

        // Settings stored in ThinkRank's own table (site identity: separator,
        // site name) do not go through update_option().
        add_action('thinkrank_seo_settings_saved', [self::class, 'bump_generation'], 10, 0);

        // %author% renders the display name.
        add_action('profile_update', [self::class, 'bump_generation'], 10, 0);

        // Deleting a user and attributing their posts to someone else
        // rewrites post_author in one query, with no save_post for any post,
        // so %author% changes on every one of them unseen.
        add_action('deleted_user', [self::class, 'on_user_deleted'], 10, 2);

        // %category% renders the first category's name, and renaming a
        // category changes it on every post filed there without touching any
        // of them. Assigning or removing a category already arrives through
        // set_object_terms above, including the reassignment wp_delete_term()
        // does.
        add_action('edit_terms', [self::class, 'before_term_edit'], 10, 2);
        add_action('edited_term', [self::class, 'after_term_edit'], 10, 3);

        self::maybe_upgrade_key_format();
    }

    /**
     * Rebuild everything once when the entry rules have changed since the
     * stored entries were written. See {@see self::KEY_FORMAT}.
     *
     * The option is autoloaded, so the check on every request after the first
     * is a read from memory.
     *
     * @since 2.10.0
     *
     * @return void
     */
    public static function maybe_upgrade_key_format(): void {
        if ((int) get_option(self::KEY_FORMAT_OPTION, 1) >= self::KEY_FORMAT) {
            return;
        }

        self::bump_generation();
        update_option(self::KEY_FORMAT_OPTION, self::KEY_FORMAT, true);
    }

    /**
     * A user was deleted.
     *
     * @since 2.10.0
     *
     * @param int|mixed      $user_id  Deleted user.
     * @param int|null|mixed $reassign User their posts went to, or null when
     *                                 the posts were deleted with them.
     * @return void
     */
    public static function on_user_deleted($user_id, $reassign = null): void {
        // Without a reassignment the posts were deleted, which drops them
        // from every scope on its own.
        if (null !== $reassign && (int) $reassign > 0) {
            self::bump_generation();
        }
    }

    /**
     * Remember a category's name before it is edited.
     *
     * @since 2.10.0
     *
     * @param int|mixed    $term_id  Term ID.
     * @param string|mixed $taxonomy Taxonomy.
     * @return void
     */
    public static function before_term_edit($term_id, $taxonomy): void {
        if (self::RENDERED_TAXONOMY !== $taxonomy) {
            return;
        }

        $term = get_term((int) $term_id, self::RENDERED_TAXONOMY);
        if ($term instanceof \WP_Term) {
            self::$term_names_before[(int) $term_id] = (string) $term->name;
        }
    }

    /**
     * A term was edited: rebuild everything when a category's name changed.
     *
     * Only the name renders, so a description, slug or parent edit is left
     * alone; a rename is rare, and when it happens the posts filed under it
     * are exactly the ones whose titles moved. Marking just those would mean a
     * write per post in a category that may hold thousands, where the
     * generation is one write and the rebuild is already bounded per request.
     * Without a captured name, it is treated as renamed: a spurious rebuild
     * costs time, a missed one reports stale duplicates.
     *
     * @since 2.10.0
     *
     * @param int|mixed    $term_id  Term ID.
     * @param int|mixed    $tt_id    Term taxonomy ID.
     * @param string|mixed $taxonomy Taxonomy.
     * @return void
     */
    public static function after_term_edit($term_id, $tt_id, $taxonomy): void {
        if (self::RENDERED_TAXONOMY !== $taxonomy) {
            return;
        }

        $term_id = (int) $term_id;
        $before  = self::$term_names_before[$term_id] ?? null;
        unset(self::$term_names_before[$term_id]);

        $term = get_term($term_id, self::RENDERED_TAXONOMY);
        if (null !== $before && $term instanceof \WP_Term && (string) $term->name === $before) {
            return;
        }

        self::bump_generation();
    }

    /**
     * Current generation.
     *
     * @return int
     */
    public static function generation(): int {
        return max(1, (int) get_option(self::GENERATION_OPTION, 1));
    }

    /**
     * Make every entry stale at once.
     *
     * @return void
     */
    public static function bump_generation(): void {
        update_option(self::GENERATION_OPTION, self::generation() + 1, true);
    }

    /**
     * How many times a batch of entries has been rebuilt.
     *
     * @since 2.10.0
     *
     * @return int
     */
    public static function revision(): int {
        return (int) get_option(self::REVISION_OPTION, 0);
    }

    /**
     * Record that entries changed. Not autoloaded: only a report that spans
     * the whole index reads it, and never on a front-end request.
     *
     * @since 2.10.0
     *
     * @return void
     */
    private static function bump_revision(): void {
        update_option(self::REVISION_OPTION, self::revision() + 1, false);
    }

    /**
     * Make one post's entry stale.
     *
     * @param int|mixed $post_id Post ID.
     * @return void
     */
    public static function mark_post_stale($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        delete_post_meta($post_id, self::META_KEY);
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
        // Our own entry is written here too; reacting to it would delete what
        // was just built.
        if (in_array($meta_key, self::WATCHED_META, true)) {
            self::mark_post_stale($object_id);
        }
    }

    /**
     * Option changed.
     *
     * @param string $option Option name.
     * @return void
     */
    public static function on_option_change($option): void {
        if (in_array($option, self::WATCHED_OPTIONS, true)) {
            self::bump_generation();
        }
    }

    /**
     * Encode an entry.
     *
     * @since 2.10.0 Carries a description key as well as a title key.
     *
     * @param int    $generation      Generation it was built under.
     * @param int    $flags           Bitmask from {@see Snippet_Issues::to_flags()}.
     * @param string $title_key       Title duplicate key ('' when there is nothing to compare).
     * @param string $description_key Description duplicate key ('' when there is nothing to compare).
     * @return string
     */
    public static function encode(int $generation, int $flags, string $title_key, string $description_key): string {
        return $generation . ':' . $flags
            . ':' . ('' === $title_key ? '-' : md5($title_key))
            . ':' . ('' === $description_key ? '-' : md5($description_key));
    }

    /**
     * Decode an entry.
     *
     * Entries written before 2.10.0 have three fields rather than four. They
     * are rejected here and, because {@see self::current_like()} matches on the
     * field count too, they read as stale and are rebuilt — so no upgrade
     * routine is needed to migrate the format.
     *
     * @param string $value Stored value.
     * @return array{generation:int, flags:int, title:string, description:string}|null Null when malformed.
     */
    public static function decode(string $value): ?array {
        $parts = explode(':', $value);
        if (4 !== count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        return [
            'generation'  => (int) $parts[0],
            'flags'       => (int) $parts[1],
            'title'       => '-' === $parts[2] ? '' : $parts[2],
            'description' => '-' === $parts[3] ? '' : $parts[3],
        ];
    }

    /**
     * LIKE pattern matching an entry that is current — right generation *and*
     * right format.
     *
     * The three trailing wildcards mean three colons after the generation, so
     * a pre-2.10.0 three-field entry does not match even at the current
     * generation. That is the whole migration: it reads as stale.
     *
     * @since 2.10.0
     *
     * @return string
     */
    private static function current_like(): string {
        global $wpdb;

        return $wpdb->esc_like(self::generation() . ':') . '%:%:%';
    }

    /**
     * Build a bounded batch of stale entries for one post type.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @return int How many stale entries remain after this batch.
     */
    public static function refresh(string $post_type, array $statuses): int {
        return self::refresh_scope(self::scope_sql([$post_type], $statuses, 'p'));
    }

    /**
     * Build a bounded batch of stale entries across every post type given.
     *
     * The duplicate report is sitewide, so it needs entries for post types the
     * Bulk Snippets screen may never have been opened on. Same batch bounds as
     * {@see self::refresh()} — the caller keeps asking until it returns zero.
     *
     * @since 2.10.0
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @return int How many stale entries remain after this batch.
     */
    public static function refresh_sitewide(array $post_types, array $statuses): int {
        if (empty($post_types)) {
            return 0;
        }

        return self::refresh_scope(self::scope_sql($post_types, $statuses, 'p'));
    }

    /**
     * Build a bounded batch of the stale entries a scope covers.
     *
     * @param string $scope_sql Trusted WHERE fragment from {@see self::scope_sql()}.
     * @return int How many stale entries remain after this batch.
     */
    private static function refresh_scope(string $scope_sql): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $generation = self::generation();
        $started    = microtime(true);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . $scope_sql . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            self::META_KEY,
            self::current_like(),
            self::REFRESH_MAX_POSTS
        ));

        foreach (array_chunk(array_map('intval', $ids), 100) as $chunk) {
            _prime_post_caches($chunk, false, true);

            foreach ($chunk as $post_id) {
                self::build($post_id, $generation);
            }

            if (microtime(true) - $started > self::REFRESH_MAX_SECONDS) {
                break;
            }
        }

        if (!empty($ids)) {
            self::bump_revision();
        }

        return max(0, self::stale_count_in($scope_sql));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Build entries for specific posts now — used right after a save, so the
     * response can report the saved rows' new state without a list refresh.
     *
     * @param int[] $post_ids Post IDs.
     * @return void
     */
    public static function rebuild(array $post_ids): void {
        $generation = self::generation();
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return;
        }

        _prime_post_caches($post_ids, false, true);
        foreach ($post_ids as $post_id) {
            self::build($post_id, $generation);
        }

        self::bump_revision();
    }

    /**
     * Compute and store one entry.
     *
     * @param int $post_id    Post ID.
     * @param int $generation Generation to stamp.
     * @return void
     */
    private static function build(int $post_id, int $generation): void {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $snapshot = Snippet_Issues::snapshot($post);
        $flags = Snippet_Issues::to_flags(Snippet_Issues::evaluate($snapshot));

        update_post_meta($post_id, self::META_KEY, self::encode(
            $generation,
            $flags,
            Snippet_Issues::duplicate_key((string) $snapshot['effective_title']),
            Snippet_Issues::description_duplicate_key((string) $snapshot['effective_description'])
        ));
    }

    /**
     * How many entries for one post type are missing or stale.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @return int
     */
    public static function stale_count(string $post_type, array $statuses): int {
        return self::stale_count_in(self::scope_sql([$post_type], $statuses, 'p'));
    }

    /**
     * How many entries the given scope is missing or has stale.
     *
     * @param string $scope_sql Trusted WHERE fragment from {@see self::scope_sql()}.
     * @return int
     */
    private static function stale_count_in(string $scope_sql): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . $scope_sql . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)",
            self::META_KEY,
            self::current_like()
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Counts and one page of post IDs, answered from the index.
     *
     * Only current entries are considered; while {@see self::refresh()} still
     * has stale ones, counts are partial and the caller says so.
     *
     * @param array{
     *     post_type: string,
     *     statuses: string[],
     *     group_post_types: string[],
     *     issue: string,
     *     search: string,
     *     page: int,
     *     per_page: int,
     *     visibility_sql: string
     * } $args Query arguments. visibility_sql is a trusted WHERE fragment from
     *        {@see self::visibility_sql()} ('' for no restriction), and
     *        group_post_types are the post types duplicates are looked for
     *        across ({@see Global_SEO_Post_Types::allowed()}).
     * @return array{ids: int[], total: int, counts: array<string,int>, with_problem: int, duplicate_ids: int[], duplicate_description_ids: int[]}
     */
    public static function query(array $args): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $current = self::current_like();

        $flags_sql = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(m.meta_value, ':', 2), ':', -1) AS UNSIGNED)";
        $title_key_sql = self::key_sql('m', 'title');
        $description_key_sql = self::key_sql('m', 'description');

        // Duplicates are about the site, not about what this user may see: a
        // title another post already uses is a problem whether or not the
        // viewer can open that post. Only the flag is revealed, never the peer.
        //
        // The scope is every post type ThinkRank manages, not the one being
        // listed: a page and a post carrying the same title compete with each
        // other (#564). Post types whose entries are still stale simply do not
        // take part yet, which can hide a duplicate but never invent one.
        $group_types = self::group_post_types($args);
        $title_dupes_sql = self::duplicate_group_sql($group_types, $args['statuses'], 'title');
        $description_dupes_sql = self::duplicate_group_sql($group_types, $args['statuses'], 'description');

        $where = self::scope_sql([$args['post_type']], $args['statuses'], 'p')
            . $wpdb->prepare(' AND m.meta_value LIKE %s', $current);

        if ('' !== $args['visibility_sql']) {
            $where .= ' AND (' . $args['visibility_sql'] . ')';
        }

        if ('' !== $args['search']) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= $wpdb->prepare(' AND (p.post_title LIKE %s OR p.post_content LIKE %s)', $like, $like);
        }

        $from = "{$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '" . esc_sql(self::META_KEY) . "'
                 LEFT JOIN ({$title_dupes_sql}) d ON d.dk = {$title_key_sql}
                 LEFT JOIN ({$description_dupes_sql}) e ON e.dk = {$description_key_sql}";

        // One pass for every chip count.
        $select = [
            'COUNT(*) AS total',
            "SUM(({$flags_sql}) > 0 OR d.dk IS NOT NULL OR e.dk IS NOT NULL) AS with_problem",
            'SUM(d.dk IS NOT NULL) AS ' . Snippet_Issues::DUPLICATE_TITLE,
            'SUM(e.dk IS NOT NULL) AS ' . Snippet_Issues::DUPLICATE_DESCRIPTION,
        ];
        foreach (Snippet_Issues::flag_bits() as $issue => $bit) {
            $select[] = "SUM(({$flags_sql} & {$bit}) > 0) AS {$issue}";
        }
        $row = (array) $wpdb->get_row('SELECT ' . implode(', ', $select) . " FROM {$from} WHERE {$where}", ARRAY_A);

        $counts = [];
        foreach (Snippet_Issues::all() as $issue) {
            $counts[$issue] = (int) ($row[$issue] ?? 0);
        }

        $issue_sql = '';
        if (Snippet_Issues::DUPLICATE_TITLE === $args['issue']) {
            $issue_sql = ' AND d.dk IS NOT NULL';
        } elseif (Snippet_Issues::DUPLICATE_DESCRIPTION === $args['issue']) {
            $issue_sql = ' AND e.dk IS NOT NULL';
        } elseif ('' !== $args['issue']) {
            $bits = Snippet_Issues::flag_bits();
            $issue_sql = ' AND (' . $flags_sql . ' & ' . (int) $bits[$args['issue']] . ') > 0';
        }

        $total = '' === $args['issue'] ? (int) ($row['total'] ?? 0) : $counts[$args['issue']];
        $offset = max(0, ($args['page'] - 1) * $args['per_page']);
        $page = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, (d.dk IS NOT NULL) AS dup, (e.dk IS NOT NULL) AS dup_description
             FROM {$from} WHERE {$where}{$issue_sql}
             ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d",
            $args['per_page'],
            $offset
        ), ARRAY_A);

        $ids = [];
        $duplicate_ids = [];
        $duplicate_description_ids = [];
        foreach ((array) $page as $item) {
            $ids[] = (int) $item['ID'];
            if (!empty($item['dup'])) {
                $duplicate_ids[] = (int) $item['ID'];
            }
            if (!empty($item['dup_description'])) {
                $duplicate_description_ids[] = (int) $item['ID'];
            }
        }

        return [
            'ids'                       => $ids,
            'total'                     => $total,
            // Before the issue filter, so the "All" chip keeps its number.
            'total_all'                 => (int) ($row['total'] ?? 0),
            'counts'                    => $counts,
            'with_problem'              => (int) ($row['with_problem'] ?? 0),
            'duplicate_ids'             => $duplicate_ids,
            'duplicate_description_ids' => $duplicate_description_ids,
        ];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Which of these posts share their title, or their description, with
     * another post on the site.
     *
     * @since 2.10.0 Reports descriptions too, and groups across post types.
     *
     * @param string[] $post_types Post types duplicates are looked for across.
     * @param string[] $statuses   Post statuses.
     * @param int[]    $post_ids   Posts to check.
     * @return array{title:int[], description:int[]} The ones that are duplicates.
     */
    public static function duplicates_among(array $post_types, array $statuses, array $post_ids): array {
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (empty($post_ids) || empty($post_types)) {
            return ['title' => [], 'description' => []];
        }

        return [
            'title'       => self::duplicates_of($post_types, $statuses, $post_ids, 'title'),
            'description' => self::duplicates_of($post_types, $statuses, $post_ids, 'description'),
        ];
    }

    /**
     * Which of these posts share one kind of key with another post on the site.
     *
     * @param string[] $post_types Post types duplicates are looked for across.
     * @param string[] $statuses   Post statuses.
     * @param int[]    $post_ids   Posts to check (already integers, non-empty).
     * @param string   $which      'title' or 'description'.
     * @return int[]
     */
    private static function duplicates_of(array $post_types, array $statuses, array $post_ids, string $which): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $key_sql = self::key_sql('m', $which);
        $in = implode(',', $post_ids);
        $groups = self::duplicate_group_sql($post_types, $statuses, $which);

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m
             WHERE m.meta_key = %s AND m.post_id IN ({$in}) AND m.meta_value LIKE %s
               AND {$key_sql} <> '-'
               AND {$key_sql} IN (SELECT dk FROM ({$groups}) g)",
            self::META_KEY,
            self::current_like()
        ));

        return array_map('intval', (array) $rows);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * How many posts in scope have a current entry — the size of what the
     * duplicate report actually looked at, as opposed to what it will cover
     * once {@see self::refresh_sitewide()} has finished.
     *
     * @since 2.10.0
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @return int
     */
    public static function current_count(array $post_types, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql($post_types, $statuses, 'p') . "
               AND m.meta_value LIKE %s",
            self::META_KEY,
            self::current_like()
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * How many duplicate groups there are, and how many posts they hold.
     *
     * Separate from {@see self::duplicate_groups()} because the report lists a
     * bounded number of groups but must state the true totals above them — a
     * count taken from the listed groups would under-report the moment the
     * list is capped.
     *
     * @since 2.10.0
     *
     * @param string[] $post_types Post types to group across.
     * @param string[] $statuses   Post statuses.
     * @param string   $which      'title' or 'description'.
     * @return array{groups:int, posts:int}
     */
    public static function duplicate_totals(array $post_types, array $statuses, string $which): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return ['groups' => 0, 'posts' => 0];
        }

        $groups_sql = self::duplicate_group_sql($post_types, $statuses, $which, true);

        $row = (array) $wpdb->get_row(
            "SELECT COUNT(*) AS groups_count, COALESCE(SUM(g.members), 0) AS posts_count
             FROM ({$groups_sql}) g",
            ARRAY_A
        );

        return [
            'groups' => (int) ($row['groups_count'] ?? 0),
            'posts'  => (int) ($row['posts_count'] ?? 0),
        ];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Every key more than one post in scope shares, with the posts carrying it.
     *
     * Largest group first, so a template stamping one title on fifty pages is
     * the first thing a reader sees rather than something they page to.
     *
     * The member IDs come back through GROUP_CONCAT so this stays one query
     * rather than one per group. `total` is a real COUNT and is exact even
     * when the concatenated list was cut short by `group_concat_max_len`,
     * which is why the caller reports the count and the names separately.
     *
     * @since 2.10.0
     *
     * @param string[] $post_types  Post types to group across.
     * @param string[] $statuses    Post statuses.
     * @param string   $which       'title' or 'description'.
     * @param int      $max_members Most member IDs to return per group.
     * @param int      $max_groups  Most groups to return; 0 for all.
     * @return array<int,array{key:string, post_ids:int[], total:int}>
     */
    public static function duplicate_groups(
        array $post_types,
        array $statuses,
        string $which,
        int $max_members,
        int $max_groups = 0
    ): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        if (empty($post_types)) {
            return [];
        }

        $posts_alias = 'title' === $which ? 'dp' : 'ep';
        $meta_alias  = 'title' === $which ? 'dm' : 'em';
        $key_sql     = self::key_sql($meta_alias, $which);

        $limit_sql = $max_groups > 0 ? $wpdb->prepare(' LIMIT %d', $max_groups) : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$key_sql} AS dk,
                    COUNT(*) AS total,
                    GROUP_CONCAT({$posts_alias}.ID ORDER BY {$posts_alias}.post_date DESC) AS ids
             FROM {$wpdb->posts} {$posts_alias}
             INNER JOIN {$wpdb->postmeta} {$meta_alias}
                ON {$meta_alias}.post_id = {$posts_alias}.ID AND {$meta_alias}.meta_key = %s
             WHERE " . self::scope_sql($post_types, $statuses, $posts_alias) . "
               AND {$meta_alias}.meta_value LIKE %s
             GROUP BY dk
             HAVING COUNT(*) > 1 AND dk <> '-'
             ORDER BY total DESC, dk ASC" . $limit_sql,
            self::META_KEY,
            self::current_like()
        ), ARRAY_A);

        $groups = [];
        foreach ((array) $rows as $row) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $row['ids']))));

            $groups[] = [
                'key'      => (string) $row['dk'],
                'total'    => (int) $row['total'],
                'post_ids' => $max_members > 0 ? array_slice($ids, 0, $max_members) : $ids,
            ];
        }

        return $groups;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * The keys shared by more than one post, as a subquery selecting `dk`.
     *
     * Each kind gets its own table aliases so two of these can appear in one
     * statement.
     *
     * @since 2.10.0
     *
     * @param string[] $post_types  Post types to group across.
     * @param string[] $statuses    Post statuses.
     * @param string   $which       'title' or 'description'.
     * @param bool     $with_counts Also select the group's size as `members`.
     * @return string Trusted SQL.
     */
    private static function duplicate_group_sql(array $post_types, array $statuses, string $which, bool $with_counts = false): string {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $posts_alias = 'title' === $which ? 'dp' : 'ep';
        $meta_alias  = 'title' === $which ? 'dm' : 'em';
        $key_sql     = self::key_sql($meta_alias, $which);
        $members_sql = $with_counts ? ', COUNT(*) AS members' : '';

        return $wpdb->prepare(
            "SELECT {$key_sql} AS dk{$members_sql}
             FROM {$wpdb->posts} {$posts_alias}
             INNER JOIN {$wpdb->postmeta} {$meta_alias}
                ON {$meta_alias}.post_id = {$posts_alias}.ID AND {$meta_alias}.meta_key = %s
             WHERE " . self::scope_sql($post_types, $statuses, $posts_alias) . "
               AND {$meta_alias}.meta_value LIKE %s
             GROUP BY dk
             HAVING COUNT(*) > 1 AND dk <> '-'",
            self::META_KEY,
            self::current_like()
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * SQL reading one key out of a stored entry.
     *
     * The title key is the third colon-separated field and the description key
     * the fourth, which is also the last.
     *
     * @since 2.10.0
     *
     * @param string $alias Postmeta table alias.
     * @param string $which 'title' or 'description'.
     * @return string Trusted SQL.
     */
    private static function key_sql(string $alias, string $which): string {
        $alias = self::alias($alias);

        return 'title' === $which
            ? "SUBSTRING_INDEX(SUBSTRING_INDEX({$alias}.meta_value, ':', 3), ':', -1)"
            : "SUBSTRING_INDEX({$alias}.meta_value, ':', -1)";
    }

    /**
     * The post types a query groups duplicates across.
     *
     * Falls back to the type being listed, so a caller that has not said
     * behaves as the pre-#564 per-type grouping rather than silently grouping
     * over nothing.
     *
     * @since 2.10.0
     *
     * @param array<string,mixed> $args Query arguments.
     * @return string[]
     */
    private static function group_post_types(array $args): array {
        $types = array_values(array_filter((array) ($args['group_post_types'] ?? []), 'is_string'));

        return empty($types) ? [(string) $args['post_type']] : $types;
    }

    /**
     * WHERE fragment limiting posts to what the current user may read.
     *
     * The SQL form of map_meta_cap('read_post') for the statuses this screen
     * lists, so pagination and counts are computed over readable posts only —
     * filtering after the LIMIT would leave short pages and counts that
     * include posts the user cannot see:
     *
     * - published: readable by anyone who can reach the screen;
     * - private: the post type's read_private_posts, or the author;
     * - draft, pending, scheduled: the post type's edit_others_posts, or the author.
     *
     * @param string $post_type Post type.
     * @param string $alias     Posts table alias.
     * @return string Trusted SQL, '' when the user may read everything.
     */
    public static function visibility_sql(string $post_type, string $alias = 'p'): string {
        $alias = self::alias($alias);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $object = get_post_type_object($post_type);
        if (!$object instanceof \WP_Post_Type) {
            return '0 = 1';
        }

        $can_private = current_user_can($object->cap->read_private_posts);
        $can_others  = current_user_can($object->cap->edit_others_posts);

        if ($can_private && $can_others) {
            return '';
        }

        $author = (int) get_current_user_id();
        $clauses = ["{$alias}.post_status = 'publish'"];

        $clauses[] = $can_private
            ? "{$alias}.post_status = 'private'"
            : $wpdb->prepare("({$alias}.post_status = 'private' AND {$alias}.post_author = %d)", $author);

        $clauses[] = $can_others
            ? "{$alias}.post_status IN ('draft','pending','future')"
            : $wpdb->prepare("({$alias}.post_status IN ('draft','pending','future') AND {$alias}.post_author = %d)", $author);

        return implode(' OR ', $clauses);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * WHERE fragment for post types and statuses.
     *
     * @since 2.10.0 Takes a list of post types rather than one.
     *
     * @param string[] $post_types Post types.
     * @param string[] $statuses   Post statuses.
     * @param string   $alias      Posts table alias.
     * @return string
     */
    private static function scope_sql(array $post_types, array $statuses, string $alias): string {
        $alias = self::alias($alias);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the alias is whitelisted in alias(); both IN lists are runs of %s built from the argument counts, so the sniff cannot see the placeholders it is looking for, and every value is still passed to prepare().
        global $wpdb;

        $post_types = array_values(array_unique(array_filter((array) $post_types, 'is_string')));
        if (empty($post_types)) {
            // No post type matches nothing. Falling back to every post type
            // here would silently widen a scope the caller meant to narrow.
            return '1 = 0';
        }

        $statuses = array_values(array_intersect($statuses, ['publish', 'future', 'draft', 'pending', 'private']));
        if (empty($statuses)) {
            $statuses = ['publish'];
        }

        $types_in    = implode(',', array_fill(0, count($post_types), '%s'));
        $statuses_in = implode(',', array_fill(0, count($statuses), '%s'));

        return $wpdb->prepare(
            "{$alias}.post_type IN ({$types_in}) AND {$alias}.post_status IN ({$statuses_in})",
            array_merge($post_types, $statuses)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * A table alias this class uses, and nothing else.
     *
     * Aliases are interpolated into SQL (identifiers cannot be placeholders),
     * so only the fixed set this class writes is accepted.
     *
     * @param string $alias Requested alias.
     * @return string
     */
    private static function alias(string $alias): string {
        return in_array($alias, ['p', 'm', 'dp', 'dm', 'ep', 'em'], true) ? $alias : 'p';
    }
}
