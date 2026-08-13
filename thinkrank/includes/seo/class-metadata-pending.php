<?php
/**
 * "Metadata is about to be written" marker.
 *
 * The editor SEO panel has no push channel, so historically it polled
 * `/metadata/{id}` blind for ~45s on every editor open, waiting for a
 * background writer (Auto AI on publish, imports) that in the overwhelming
 * majority of sessions was never coming. That cost every user 11+ requests per
 * open and, on slow hosts, piled up into 503s (#329).
 *
 * This flips the panel from "poll and hope" to "poll only when something is
 * genuinely in flight". A writer marks the post before it queues work and
 * clears it when done; the metadata endpoint reports the flag; the panel polls
 * only while it is set.
 *
 * Two markers, because the writers come in two shapes:
 *
 * - Per post (`mark()`), for a write announced ahead of time for one known
 *   post — Auto AI, which queues a cron run on publish.
 * - Site wide (`mark_bulk()`), for a job writing across many posts as it goes —
 *   the SEO importer. Marking each post individually would cost one transient
 *   per post for a window of microseconds (the write is synchronous), which
 *   buys nothing; the job announces itself once for as long as it runs.
 *
 * A transient rather than post meta deliberately: this is ephemeral run state,
 * it must not pollute the post's meta, and it must expire on its own if a cron
 * run dies before clearing it.
 *
 * @package ThinkRank\SEO
 * @since 1.30.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks posts with a background metadata write in flight.
 */
class Metadata_Pending {

    /**
     * Transient key prefix.
     */
    private const PREFIX = 'thinkrank_meta_pending_';

    /**
     * Self-expiry, so a cron run that dies before clearing cannot leave a post
     * polling forever. Comfortably longer than the ~15s Auto AI delay plus the
     * AI call itself.
     */
    private const TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Transient key for a site-wide job (see the class docblock).
     */
    private const BULK_KEY = 'thinkrank_meta_pending_bulk';

    /**
     * Kept short: every editor open polls while it is set, so a job that dies
     * between chunks must stop costing requests quickly. Each chunk re-marks,
     * which is what keeps a running job's window open.
     */
    private const BULK_TTL = 2 * MINUTE_IN_SECONDS;

    /**
     * Mark a post as having a background metadata write in flight.
     *
     * Call this *before* queueing the work, so the flag is already visible to
     * an editor that reloads immediately after publish.
     *
     * @param int $post_id Post being written to.
     * @return void
     */
    public static function mark(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        set_transient(self::PREFIX . $post_id, 1, self::TTL);
    }

    /**
     * Clear the marker once the write has finished (or definitively failed).
     *
     * @param int $post_id Post that was being written to.
     * @return void
     */
    public static function clear(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        delete_transient(self::PREFIX . $post_id);
    }

    /**
     * Mark a site-wide metadata write job as running.
     *
     * Re-marking is how a chunked job holds its window open, so call this at
     * the start of every chunk that writes, not just the first.
     *
     * @return void
     */
    public static function mark_bulk(): void {
        set_transient(self::BULK_KEY, 1, self::BULK_TTL);
    }

    /**
     * Clear the site-wide job marker once the job has finished.
     *
     * @return void
     */
    public static function clear_bulk(): void {
        delete_transient(self::BULK_KEY);
    }

    /**
     * Whether a background metadata write is in flight for this post.
     *
     * True while either this post has its own marker or a site-wide job is
     * running. Filterable so features that own their own queue (Pro) can
     * report pending state without going through `mark()`.
     *
     * @param int $post_id Post to check.
     * @return bool
     */
    public static function is_pending(int $post_id): bool {
        $pending = false !== get_transient(self::PREFIX . $post_id)
            || false !== get_transient(self::BULK_KEY);

        /**
         * Filter whether a background metadata write is pending for a post.
         *
         * Returning true keeps the editor SEO panel polling for the value;
         * returning false stops it. Keep any implementation cheap — this runs
         * on every metadata request.
         *
         * @since 1.30.0
         *
         * @param bool $pending Whether a write is in flight.
         * @param int  $post_id Post being checked.
         */
        return (bool) apply_filters('thinkrank_metadata_pending', $pending, $post_id);
    }
}
