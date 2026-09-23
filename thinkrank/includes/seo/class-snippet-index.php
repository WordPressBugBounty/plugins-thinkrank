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
 * `{generation}:{flags}:{duplicate key}`, and every list, count and page is
 * answered by SQL over it:
 *
 * - **flags** is a bitmask of the issues that depend on the post alone.
 * - **duplicate key** is a hash of what produces the post's title
 *   ({@see Snippet_Issues::duplicate_key()}). Duplicates are found at query
 *   time by grouping on it, so fixing one of two duplicates clears the other
 *   without touching the other's entry.
 * - **generation** ties the entry to the global inputs (templates, robots
 *   settings, site name, separator). Changing any of them bumps the
 *   generation, which makes every entry stale at once without a write per post.
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
     * @param int    $generation Generation it was built under.
     * @param int    $flags      Bitmask from {@see Snippet_Issues::to_flags()}.
     * @param string $dup_key    Duplicate key ('' when there is nothing to compare).
     * @return string
     */
    public static function encode(int $generation, int $flags, string $dup_key): string {
        return $generation . ':' . $flags . ':' . ('' === $dup_key ? '-' : md5($dup_key));
    }

    /**
     * Decode an entry.
     *
     * @param string $value Stored value.
     * @return array{generation:int, flags:int, dup:string}|null Null when malformed.
     */
    public static function decode(string $value): ?array {
        $parts = explode(':', $value);
        if (3 !== count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        return [
            'generation' => (int) $parts[0],
            'flags'      => (int) $parts[1],
            'dup'        => '-' === $parts[2] ? '' : $parts[2],
        ];
    }

    /**
     * Build a bounded batch of stale entries.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @return int How many stale entries remain after this batch.
     */
    public static function refresh(string $post_type, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $generation = self::generation();
        $started    = microtime(true);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql($post_type, $statuses, 'p') . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)
             ORDER BY p.ID DESC
             LIMIT %d",
            self::META_KEY,
            $wpdb->esc_like($generation . ':') . '%',
            self::REFRESH_MAX_POSTS
        ));

        $built = 0;
        foreach (array_chunk(array_map('intval', $ids), 100) as $chunk) {
            _prime_post_caches($chunk, false, true);

            foreach ($chunk as $post_id) {
                self::build($post_id, $generation);
                $built++;
            }

            if (microtime(true) - $started > self::REFRESH_MAX_SECONDS) {
                break;
            }
        }

        return max(0, self::stale_count($post_type, $statuses) );
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
        $dup_key = Snippet_Issues::duplicate_key($snapshot['raw_title'], $post->post_title);

        update_post_meta($post_id, self::META_KEY, self::encode($generation, $flags, $dup_key));
    }

    /**
     * How many entries in scope are missing or stale.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @return int
     */
    public static function stale_count(string $post_type, array $statuses): int {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE " . self::scope_sql($post_type, $statuses, 'p') . "
               AND (m.meta_value IS NULL OR m.meta_value NOT LIKE %s)",
            self::META_KEY,
            $wpdb->esc_like(self::generation() . ':') . '%'
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
     *     issue: string,
     *     search: string,
     *     page: int,
     *     per_page: int,
     *     visibility_sql: string
     * } $args Query arguments. visibility_sql is a trusted WHERE fragment from
     *        {@see self::visibility_sql()} ('' for no restriction).
     * @return array{ids: int[], total: int, counts: array<string,int>, with_problem: int, duplicate_ids: int[]}
     */
    public static function query(array $args): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $generation = self::generation();
        $current    = $wpdb->esc_like($generation . ':') . '%';

        $flags_sql = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(m.meta_value, ':', 2), ':', -1) AS UNSIGNED)";
        $dup_sql   = "SUBSTRING_INDEX(m.meta_value, ':', -1)";

        // Duplicates are about the site, not about what this user may see: a
        // title another post already uses is a problem whether or not the
        // viewer can open that post. Only the flag is revealed, never the peer.
        $dupes_sql = $wpdb->prepare(
            "SELECT SUBSTRING_INDEX(dm.meta_value, ':', -1) AS dk
             FROM {$wpdb->posts} dp
             INNER JOIN {$wpdb->postmeta} dm ON dm.post_id = dp.ID AND dm.meta_key = %s
             WHERE " . self::scope_sql($args['post_type'], $args['statuses'], 'dp') . "
               AND dm.meta_value LIKE %s
             GROUP BY dk
             HAVING COUNT(*) > 1 AND dk <> '-'",
            self::META_KEY,
            $current
        );

        $where = self::scope_sql($args['post_type'], $args['statuses'], 'p')
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
                 LEFT JOIN ({$dupes_sql}) d ON d.dk = {$dup_sql}";

        // One pass for every chip count.
        $select = ['COUNT(*) AS total', "SUM(({$flags_sql}) > 0 OR d.dk IS NOT NULL) AS with_problem", 'SUM(d.dk IS NOT NULL) AS ' . Snippet_Issues::DUPLICATE_TITLE];
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
        } elseif ('' !== $args['issue']) {
            $bits = Snippet_Issues::flag_bits();
            $issue_sql = ' AND (' . $flags_sql . ' & ' . (int) $bits[$args['issue']] . ') > 0';
        }

        $total = '' === $args['issue'] ? (int) ($row['total'] ?? 0) : $counts[$args['issue']];
        $offset = max(0, ($args['page'] - 1) * $args['per_page']);
        $page = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, (d.dk IS NOT NULL) AS dup FROM {$from} WHERE {$where}{$issue_sql}
             ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d",
            $args['per_page'],
            $offset
        ), ARRAY_A);

        $ids = [];
        $duplicate_ids = [];
        foreach ((array) $page as $item) {
            $ids[] = (int) $item['ID'];
            if (!empty($item['dup'])) {
                $duplicate_ids[] = (int) $item['ID'];
            }
        }

        return [
            'ids'           => $ids,
            'total'         => $total,
            // Before the issue filter, so the "All" chip keeps its number.
            'total_all'     => (int) ($row['total'] ?? 0),
            'counts'        => $counts,
            'with_problem'  => (int) ($row['with_problem'] ?? 0),
            'duplicate_ids' => $duplicate_ids,
        ];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Which of these posts share their title with another post in scope.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @param int[]    $post_ids  Posts to check.
     * @return int[] The ones that are duplicates.
     */
    public static function duplicates_among(string $post_type, array $statuses, array $post_ids): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return [];
        }

        $current = $wpdb->esc_like(self::generation() . ':') . '%';
        $in = implode(',', $post_ids);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m
             WHERE m.meta_key = %s AND m.post_id IN ({$in}) AND m.meta_value LIKE %s
               AND SUBSTRING_INDEX(m.meta_value, ':', -1) <> '-'
               AND SUBSTRING_INDEX(m.meta_value, ':', -1) IN (
                   SELECT SUBSTRING_INDEX(dm.meta_value, ':', -1)
                   FROM {$wpdb->posts} dp
                   INNER JOIN {$wpdb->postmeta} dm ON dm.post_id = dp.ID AND dm.meta_key = %s
                   WHERE " . self::scope_sql($post_type, $statuses, 'dp') . " AND dm.meta_value LIKE %s
                   GROUP BY SUBSTRING_INDEX(dm.meta_value, ':', -1)
                   HAVING COUNT(*) > 1
               )",
            self::META_KEY,
            $current,
            self::META_KEY,
            $current
        ));

        return array_map('intval', (array) $rows);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
     * WHERE fragment for post type and statuses.
     *
     * @param string   $post_type Post type.
     * @param string[] $statuses  Post statuses.
     * @param string   $alias     Posts table alias.
     * @return string
     */
    private static function scope_sql(string $post_type, array $statuses, string $alias): string {
        $alias = self::alias($alias);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interpolated parts are this class's own table aliases (whitelisted in alias()) and fragments already passed through prepare(); every value is a placeholder. The index is itself the cache.
        global $wpdb;

        $statuses = array_values(array_intersect($statuses, ['publish', 'future', 'draft', 'pending', 'private']));
        if (empty($statuses)) {
            $statuses = ['publish'];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        return $wpdb->prepare(
            "{$alias}.post_type = %s AND {$alias}.post_status IN ({$placeholders})",
            array_merge([$post_type], $statuses)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
        return in_array($alias, ['p', 'dp'], true) ? $alias : 'p';
    }
}
