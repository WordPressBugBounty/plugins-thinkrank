<?php
/**
 * Storage for the SEO Timeline.
 *
 * @package ThinkRank
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Timeline;

use ThinkRank\Database\Database_Schema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes `{prefix}thinkrank_timeline_events`.
 *
 * Everything that decides *what* is worth recording lives in Event_Recorder;
 * this only decides how it is stored, read back and eventually dropped.
 *
 * @since 2.22.0
 */
class Event_Repository {

    /**
     * Severity levels, least to most urgent.
     *
     * @since 2.22.0
     * @var array<int,string>
     */
    public const SEVERITIES = ['info', 'warning', 'critical'];

    /**
     * Days of history the free plugin keeps.
     *
     * @since 2.22.0
     * @var int
     */
    public const FREE_RETENTION_DAYS = 30;

    /**
     * Hard cap on how many rows one read can return.
     *
     * @since 2.22.0
     * @var int
     */
    public const MAX_LIMIT = 200;

    /**
     * Transient guarding the self-heal, so a genuinely broken install retries
     * hourly rather than on every request.
     *
     * @since 2.22.0
     * @var string
     */
    private const HEAL_TRANSIENT = 'thinkrank_timeline_table_checked';

    /**
     * The table name.
     *
     * @since 2.22.0
     *
     * @return string
     */
    public static function table(): string {
        global $wpdb;

        return $wpdb->prefix . 'thinkrank_timeline_events';
    }

    /**
     * How many days of history to keep.
     *
     * @since 2.22.0
     *
     * @return int
     */
    public static function retention_days(): int {
        /**
         * Filters how many days of Timeline history are kept.
         *
         * Pro raises this to 16 months, which is what Search Console keeps, so
         * a drop can still be explained when the data explaining it is read.
         *
         * @since 2.22.0
         *
         * @param int $days Days of history.
         */
        $days = (int) apply_filters('thinkrank_timeline_retention_days', self::FREE_RETENTION_DAYS);

        return max(1, $days);
    }

    /**
     * Record one event.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $event Event fields.
     * @return int Inserted row id, or 0 when nothing was written.
     */
    public static function insert(array $event): int {
        if (!self::table_ready()) {
            return 0;
        }

        global $wpdb;

        $row = self::normalize($event);

        if ('' === $row['event_type']) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- append-only log of our own.
        $written = $wpdb->insert(
            self::table(),
            [
                'occurred_at' => $row['occurred_at'],
                'event_type'  => $row['event_type'],
                'severity'    => $row['severity'],
                'object_type' => $row['object_type'],
                'object_id'   => $row['object_id'],
                'actor'       => $row['actor'],
                'summary'     => $row['summary'],
                'meta'        => $row['meta'],
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return $written ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Coerce an event into what the columns accept.
     *
     * Public and pure so the shape can be tested without a database.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $event Raw event.
     * @return array<string,mixed>
     */
    public static function normalize(array $event): array {
        $severity = is_string($event['severity'] ?? null) ? strtolower($event['severity']) : 'info';

        $meta = $event['meta'] ?? null;
        if (is_array($meta) && !empty($meta)) {
            $meta = wp_json_encode($meta);
        } elseif (!is_string($meta) || '' === $meta) {
            $meta = null;
        }

        return [
            'occurred_at' => is_string($event['occurred_at'] ?? null) && '' !== $event['occurred_at']
                ? $event['occurred_at']
                : current_time('mysql', true),
            'event_type'  => self::clip(sanitize_key((string) ($event['event_type'] ?? '')), 60),
            'severity'    => in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
            'object_type' => self::clip(sanitize_key((string) ($event['object_type'] ?? '')), 40),
            'object_id'   => max(0, (int) ($event['object_id'] ?? 0)),
            // Every column here counts characters (varchar), so clip() cuts
            // a multibyte display name or summary between characters. A byte
            // cut through one leaves invalid UTF-8, which wpdb refuses to
            // insert, and the whole event would be lost.
            'actor'       => self::clip(sanitize_text_field((string) ($event['actor'] ?? '')), 100),
            'summary'     => self::clip(sanitize_text_field((string) ($event['summary'] ?? '')), 255),
            'meta'        => $meta,
        ];
    }

    /**
     * Cut a string to a byte-safe character length.
     *
     * @since 2.22.0
     *
     * @param string $value  Value.
     * @param int    $length Maximum characters.
     * @return string
     */
    public static function clip(string $value, int $length): string {
        // No byte-cut fallback: splitting a character is exactly what this
        // exists to avoid, and core's compat.php polyfills mb_substr() when
        // mbstring is missing, so it is always there.
        return mb_substr($value, 0, $length);
    }

    /**
     * Read events, newest first.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $args from, to, type, severity, object_type,
     *                                  object_id, limit, offset.
     * @return array<int,array<string,mixed>>
     */
    public static function query(array $args = []): array {
        if (!self::table_ready()) {
            return [];
        }

        global $wpdb;

        [$where, $params] = self::build_where($args);

        $limit  = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $table = self::table();

        // The sniff cannot count placeholders it did not see written out, and
        // build_where() assembles them: the clause contributes one %s or %d per
        // entry in $params, and LIMIT/OFFSET add the two appended below.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is $wpdb->prefix plus a literal; every value is a placeholder.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, occurred_at, event_type, severity, object_type, object_id, actor, summary, meta
                 FROM {$table} {$where}
                 ORDER BY occurred_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                array_merge($params, [$limit, $offset])
            ),
            ARRAY_A
        );
        // phpcs:enable

        return array_map([self::class, 'hydrate'], (array) $rows);
    }

    /**
     * Count events matching the same filters.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $args Filters.
     * @return int
     */
    public static function count(array $args = []): int {
        if (!self::table_ready()) {
            return 0;
        }

        global $wpdb;

        [$where, $params] = self::build_where($args);
        $table = self::table();

        // A no-filter count has nothing to bind, and $wpdb->prepare() with an
        // empty argument list is a notice in WordPress 6.2 and later. So the
        // two shapes are written out rather than branching on a $sql variable,
        // which is also what lets the sniff see each query whole.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $count = empty($params)
            ? $wpdb->get_var("SELECT COUNT(*) FROM {$table}")
            : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", $params));
        // phpcs:enable

        return (int) $count;
    }

    /**
     * Build the WHERE clause and its parameters.
     *
     * Public and pure: the clause is where an injection would live, and it can
     * be asserted directly rather than through a database.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $args Filters.
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function build_where(array $args): array {
        $clauses = [];
        $params  = [];

        if (!empty($args['from'])) {
            $clauses[] = 'occurred_at >= %s';
            $params[]  = (string) $args['from'];
        }

        if (!empty($args['to'])) {
            $clauses[] = 'occurred_at <= %s';
            $params[]  = (string) $args['to'];
        }

        $types = array_values(array_filter(array_map('sanitize_key', (array) ($args['type'] ?? []))));
        if (!empty($types)) {
            $clauses[] = 'event_type IN (' . implode(', ', array_fill(0, count($types), '%s')) . ')';
            $params    = array_merge($params, $types);
        }

        $severities = array_values(array_intersect(
            array_map('strtolower', array_map('strval', (array) ($args['severity'] ?? []))),
            self::SEVERITIES
        ));
        if (!empty($severities)) {
            $clauses[] = 'severity IN (' . implode(', ', array_fill(0, count($severities), '%s')) . ')';
            $params    = array_merge($params, $severities);
        }

        if (!empty($args['object_type'])) {
            $clauses[] = 'object_type = %s';
            $params[]  = sanitize_key((string) $args['object_type']);
        }

        if (!empty($args['object_id'])) {
            $clauses[] = 'object_id = %d';
            $params[]  = (int) $args['object_id'];
        }

        return [empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Turn a stored row into what the API returns.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $row Stored row.
     * @return array<string,mixed>
     */
    public static function hydrate(array $row): array {
        $meta = $row['meta'] ?? null;

        if (is_string($meta) && '' !== $meta) {
            $decoded = json_decode($meta, true);
            $meta    = is_array($decoded) ? $decoded : null;
        } else {
            $meta = null;
        }

        return [
            'id'          => (int) ($row['id'] ?? 0),
            'occurred_at' => (string) ($row['occurred_at'] ?? ''),
            'event_type'  => (string) ($row['event_type'] ?? ''),
            'severity'    => (string) ($row['severity'] ?? 'info'),
            'object_type' => (string) ($row['object_type'] ?? ''),
            'object_id'   => (int) ($row['object_id'] ?? 0),
            'actor'       => (string) ($row['actor'] ?? ''),
            'summary'     => (string) ($row['summary'] ?? ''),
            'meta'        => $meta,
        ];
    }

    /**
     * Drop events past the retention window.
     *
     * @since 2.22.0
     *
     * @return int Rows removed.
     */
    public static function prune(): int {
        if (!self::table_ready()) {
            return 0;
        }

        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::retention_days() * DAY_IN_SECONDS));
        $table  = self::table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE occurred_at < %s", $cutoff)
        );
        // phpcs:enable

        return (int) $deleted;
    }

    /**
     * Whether the table is there, creating it once an hour if it is not.
     *
     * A new table reaches an existing site only through reactivation: nothing
     * calls Database_Schema::needs_update(), despite its docblock. Rather than
     * shipping a feature that is silently dead until someone deactivates and
     * reactivates the plugin, a missing table triggers one dbDelta run, which
     * creates only what is absent and is safe to repeat. The transient means a
     * genuinely broken install retries hourly instead of on every request.
     *
     * The wider gap is worth its own fix; this makes the Timeline survive it.
     *
     * @since 2.22.0
     *
     * @return bool
     */
    public static function table_ready(): bool {
        global $wpdb;

        $table = self::table();

        $cached = get_transient(self::HEAL_TRANSIENT);

        if ($cached === $table) {
            return true;
        }

        // 'missing' is remembered too. Without that branch a site whose table
        // genuinely cannot be created would run SHOW TABLES and a dbDelta pass
        // on every request, which is the opposite of what the transient is for.
        if ('missing' === $cached) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, result cached below.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        if (!$exists && class_exists(Database_Schema::class)) {
            (new Database_Schema())->create_tables();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- re-probe after creation.
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }

        set_transient(self::HEAL_TRANSIENT, $exists ? $table : 'missing', HOUR_IN_SECONDS);

        return $exists;
    }
}
