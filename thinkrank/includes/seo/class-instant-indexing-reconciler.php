<?php
/**
 * Instant Indexing Reconciler Class
 *
 * Scheduled reconciliation between what has actually been published and what
 * IndexNow has been told about, plus retries for the gaps it finds.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.31.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instant Indexing Reconciler Class
 *
 * The automatic submission path is best-effort: it hangs off
 * transition_post_status and defers the outbound call to a single WP-Cron event.
 * Several ordinary situations therefore leave a published URL newer than
 * anything IndexNow was told about — a direct `$wpdb->update()` fires no hooks
 * at all, two saves inside the 15-second dedupe window collapse into one
 * submission, a missed or dropped cron event disappears without trace, a failed
 * submission is never retried, and anything edited while the feature was off is
 * simply never sent.
 *
 * Rather than chase each of those individually, this reconciler compares state:
 * every published URL's post_modified against the newest *successful* submission
 * for that URL. That is mechanism-agnostic, so it closes gaps caused by causes
 * nobody has thought of yet.
 *
 * Work is bounded. A cron run walks a batch of posts from a stored cursor and
 * wraps around, so a large site reconciles over successive runs instead of
 * trying to hold every permalink in memory at once.
 *
 * @since 1.31.0
 */
class Instant_Indexing_Reconciler {

    /**
     * Recurring reconciliation event.
     */
    public const CRON_HOOK = 'thinkrank_instant_indexing_reconcile';

    /**
     * Option holding the batch cursor (offset into the ordered post list).
     */
    private const CURSOR_OPTION = 'thinkrank_instant_indexing_reconcile_cursor';

    /**
     * Posts examined per cron run.
     */
    public const BATCH_SIZE = 200;

    /**
     * Default ceiling on posts examined when building a report on demand.
     */
    public const REPORT_LIMIT = 500;

    /**
     * How long to leave a failing URL alone before trying it again.
     */
    public const RETRY_COOLDOWN = 6 * HOUR_IN_SECONDS;

    /**
     * Window over which repeated failures are counted for the park rule.
     */
    public const FAILURE_WINDOW = 7 * DAY_IN_SECONDS;

    /**
     * Consecutive recent failures after which a URL stops being retried.
     *
     * Without this a permanently broken URL — 404, wrong host, revoked key —
     * would consume the retry budget on every run forever and crowd out URLs
     * that could actually succeed.
     */
    public const MAX_FAILURES_BEFORE_PARK = 5;

    /**
     * Coverage states.
     */
    public const STATE_CURRENT = 'current';
    public const STATE_STALE = 'stale';
    public const STATE_FAILED = 'failed';
    public const STATE_NEVER = 'never_submitted';

    /**
     * Instant Indexing Manager instance.
     *
     * @var Instant_Indexing_Manager|null
     */
    private ?Instant_Indexing_Manager $manager = null;

    /**
     * Settings option name, shared with the manager.
     *
     * @var string
     */
    private string $option_name = 'thinkrank_instant_indexing_settings';

    /**
     * Register the cron listener and make sure the event exists.
     *
     * @since 1.31.0
     * @return void
     */
    public function init(): void {
        add_action(self::CRON_HOOK, [$this, 'reconcile']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Offset the first run so a freshly activated site isn't reconciling
            // during the activation request itself.
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Decide which coverage bucket a URL falls into.
     *
     * Pure: every input is passed in, so the rules can be tested without a
     * database. All timestamps are site-local `Y-m-d H:i:s` strings, matching
     * both `post_modified` and the log table's `created_at`; lexicographic
     * comparison is correct for that format.
     *
     * @since 1.31.0
     *
     * @param string      $post_modified Post's last modification time.
     * @param string|null $last_success  Newest successful submission, or null.
     * @param string|null $last_attempt  Newest submission of any status, or null.
     * @return string One of the STATE_* constants.
     */
    public static function classify(string $post_modified, ?string $last_success, ?string $last_attempt): string {
        // Nothing was ever sent for this URL.
        if (null === $last_attempt || '' === $last_attempt) {
            return self::STATE_NEVER;
        }

        // A success at or after the last edit means search engines know about
        // the current content — even if a later attempt happened to fail.
        if (null !== $last_success && '' !== $last_success && $last_success >= $post_modified) {
            return self::STATE_CURRENT;
        }

        // The most recent attempt failed (the newest row is not the successful
        // one), and nothing since the edit succeeded.
        if ($last_success !== $last_attempt) {
            return self::STATE_FAILED;
        }

        // Submitted successfully, but the post has been edited since.
        return self::STATE_STALE;
    }

    /**
     * Whether a URL in a given state should be resubmitted on this run.
     *
     * Pure, for the same reason as classify().
     *
     * @since 1.31.0
     *
     * @param string      $state           A STATE_* constant.
     * @param string|null $last_attempt    Newest submission of any status, or null.
     * @param int         $recent_failures Failures inside FAILURE_WINDOW.
     * @param int         $now             Current timestamp.
     * @return bool True when the URL should be retried now.
     */
    public static function should_retry(string $state, ?string $last_attempt, int $recent_failures, int $now): bool {
        if (self::STATE_CURRENT === $state) {
            return false;
        }

        if (self::STATE_FAILED === $state) {
            // Park a URL that keeps failing so it can't monopolise the budget.
            if ($recent_failures >= self::MAX_FAILURES_BEFORE_PARK) {
                return false;
            }

            // Back off between attempts rather than hammering on every run.
            if (null !== $last_attempt && '' !== $last_attempt) {
                $attempted_at = strtotime($last_attempt);
                if (false !== $attempted_at && ($now - $attempted_at) < self::RETRY_COOLDOWN) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Build a coverage report over the published, auto-submitted content.
     *
     * @since 1.31.0
     *
     * @param int $limit    Maximum posts to examine.
     * @param int $offset   Offset into the ordered post list.
     * @param int $examples Maximum example URLs retained per bucket.
     * @return array{
     *     counts: array<string,int>, total: int, examined: int, offset: int,
     *     urls: array<string,array<int,array<string,string>>>, truncated: bool,
     *     enabled: bool, generated_at: string
     * }
     */
    public function build_report(int $limit = self::REPORT_LIMIT, int $offset = 0, int $examples = 25): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $counts = [
            self::STATE_CURRENT => 0,
            self::STATE_STALE => 0,
            self::STATE_FAILED => 0,
            self::STATE_NEVER => 0,
        ];
        $urls = [
            self::STATE_CURRENT => [],
            self::STATE_STALE => [],
            self::STATE_FAILED => [],
            self::STATE_NEVER => [],
        ];

        $total = $this->count_tracked_posts();
        $rows = $this->get_tracked_posts($limit, $offset);
        $log = $this->get_submission_index();

        foreach ($rows as $row) {
            $url = get_permalink((int) $row['ID']);
            if (!$url) {
                continue;
            }

            $entry = $log[$url] ?? null;
            $state = self::classify(
                (string) $row['post_modified'],
                $entry['last_success'] ?? null,
                $entry['last_attempt'] ?? null
            );

            $counts[$state]++;

            if (count($urls[$state]) < $examples) {
                $urls[$state][] = [
                    'url' => $url,
                    'post_id' => (int) $row['ID'],
                    'post_modified' => (string) $row['post_modified'],
                    'last_success' => $entry['last_success'] ?? '',
                    'last_attempt' => $entry['last_attempt'] ?? '',
                ];
            }
        }

        return [
            'counts' => $counts,
            'total' => $total,
            'examined' => count($rows),
            'offset' => $offset,
            'urls' => $urls,
            // Signals that the counts describe a window, not the whole site, so
            // the UI never presents a partial pass as a full audit.
            'truncated' => ($offset + count($rows)) < $total,
            'enabled' => $this->is_enabled(),
            'generated_at' => current_time('mysql'),
        ];
    }

    /**
     * Scheduled reconciliation pass: find gaps in one batch and resubmit them.
     *
     * @since 1.31.0
     *
     * @param bool $dry_run When true, classify and select but submit nothing.
     * @return array Summary of the pass.
     */
    public function reconcile(bool $dry_run = false): array {
        $summary = [
            'ran' => false,
            'reason' => '',
            'examined' => 0,
            'counts' => [],
            'retried' => 0,
            'retried_urls' => [],
            'submission' => null,
            'next_offset' => 0,
        ];

        if (!$this->is_enabled()) {
            $summary['reason'] = 'Instant Indexing is disabled';
            return $summary;
        }

        $total = $this->count_tracked_posts();
        if (0 === $total) {
            $summary['ran'] = true;
            $summary['reason'] = 'No published content to reconcile';
            return $summary;
        }

        // Walk the site in batches across successive runs, wrapping at the end.
        $offset = (int) get_option(self::CURSOR_OPTION, 0);
        if ($offset >= $total) {
            $offset = 0;
        }

        $report = $this->build_report(self::BATCH_SIZE, $offset, self::BATCH_SIZE);
        $log = $this->get_submission_index();
        $now = time();

        $retry = [];
        foreach ([self::STATE_NEVER, self::STATE_STALE, self::STATE_FAILED] as $state) {
            foreach ($report['urls'][$state] as $item) {
                $entry = $log[$item['url']] ?? null;

                if (!self::should_retry($state, $entry['last_attempt'] ?? null, (int) ($entry['recent_failures'] ?? 0), $now)) {
                    continue;
                }

                $retry[] = $item['url'];
            }
        }

        // Respect the same per-submission ceiling every other path uses; the
        // remainder is picked up by the next run.
        $retry = array_slice(array_values(array_unique($retry)), 0, Instant_Indexing_Manager::MAX_URLS_PER_SUBMISSION);

        $summary['ran'] = true;
        $summary['examined'] = $report['examined'];
        $summary['counts'] = $report['counts'];
        $summary['retried'] = count($retry);
        $summary['retried_urls'] = $retry;

        if (!$dry_run) {
            if (!empty($retry)) {
                $summary['submission'] = $this->get_manager()->submit_urls($retry);
            }

            $next = $offset + $report['examined'];
            if ($next >= $total || 0 === $report['examined']) {
                $next = 0;
            }
            update_option(self::CURSOR_OPTION, $next, false);
            $summary['next_offset'] = $next;
        }

        return $summary;
    }

    /**
     * Newest successful / newest overall submission per URL, plus recent failures.
     *
     * One aggregate query rather than a lookup per URL — the log has no usable
     * equality index on the full 2048-char url column, so per-URL queries would
     * scan the table once per post.
     *
     * @since 1.31.0
     * @return array<string,array{last_success:?string,last_attempt:?string,recent_failures:int}>
     */
    private function get_submission_index(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'thinkrank_instant_indexing_logs';
        $window_start = gmdate('Y-m-d H:i:s', (int) (current_time('timestamp') - self::FAILURE_WINDOW)); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- log rows are written with current_time('mysql'), so the window must be computed in the same site-local frame.

        // Aggregate over a plugin-owned log table. The only interpolated value is
        // the table name, built from $wpdb->prefix; the date bound is prepared.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT url,
                        MAX(CASE WHEN status = 'success' THEN created_at END) AS last_success,
                        MAX(created_at) AS last_attempt,
                        SUM(CASE WHEN status <> 'success' AND created_at >= %s THEN 1 ELSE 0 END) AS recent_failures
                 FROM `{$table}`
                 GROUP BY url",
                $window_start
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $index = [];
        foreach ((array) $rows as $row) {
            $index[(string) $row['url']] = [
                'last_success' => null !== $row['last_success'] ? (string) $row['last_success'] : null,
                'last_attempt' => null !== $row['last_attempt'] ? (string) $row['last_attempt'] : null,
                'recent_failures' => (int) $row['recent_failures'],
            ];
        }

        return $index;
    }

    /**
     * Published posts of the auto-submitted types, ordered stably for cursoring.
     *
     * @since 1.31.0
     *
     * @param int $limit  Maximum rows.
     * @param int $offset Offset into the ordered list.
     * @return array<int,array{ID:string,post_modified:string}>
     */
    private function get_tracked_posts(int $limit, int $offset): array {
        global $wpdb;

        $types = $this->get_tracked_post_types();
        if (empty($types)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $params = array_merge($types, [$limit, $offset]);

        // ID + timestamp only, over the indexed post_type/post_status pair;
        // WP_Query would hydrate every post object for no benefit. The only
        // interpolation is the %s placeholder list, built from a count.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_modified
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ({$placeholders})
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $rows = (array) $rows;

        // Prime the cache in one query so the get_permalink() calls that follow
        // don't each fetch their post row separately.
        if (!empty($rows)) {
            _prime_post_caches(array_map(static fn($r) => (int) $r['ID'], $rows), false, false);
        }

        return $rows;
    }

    /**
     * Total published posts in the auto-submitted types.
     *
     * @since 1.31.0
     * @return int
     */
    private function count_tracked_posts(): int {
        global $wpdb;

        $types = $this->get_tracked_post_types();
        if (empty($types)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($types), '%s'));

        // Indexed COUNT over post_type/post_status; the only interpolation is the
        // %s placeholder list, built from a count.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ({$placeholders})",
                $types
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Post types configured for automatic submission.
     *
     * @since 1.31.0
     * @return array<int,string>
     */
    private function get_tracked_post_types(): array {
        $settings = get_option($this->option_name, []);
        $types = $settings['auto_submit_post_types'] ?? [];

        return array_values(array_filter(array_map('strval', (array) $types)));
    }

    /**
     * Whether Instant Indexing is switched on.
     *
     * @since 1.31.0
     * @return bool
     */
    private function is_enabled(): bool {
        $settings = get_option($this->option_name, []);

        return !empty($settings['enabled']);
    }

    /**
     * Manager used for the outbound submission, built on first use.
     *
     * @since 1.31.0
     * @return Instant_Indexing_Manager
     */
    private function get_manager(): Instant_Indexing_Manager {
        if (null === $this->manager) {
            $this->manager = new Instant_Indexing_Manager();
        }

        return $this->manager;
    }
}
