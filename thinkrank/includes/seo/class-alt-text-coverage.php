<?php

/**
 * Alt text coverage over the Media Library
 *
 * Counts images whose stored alt text passes Alt_Text_Quality::is_descriptive(),
 * so the analyzer and the Image SEO stats give the same verdict as the fill.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.17.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Alt text coverage over the Media Library.
 *
 * The SQL condition alone only drops values with no letter and exact stock
 * words, so "IMG_1234", "image.", "image (1)" and "jioqIZVKAg" counted as
 * covered while the fill called them filler (#1207). SQL still does the cheap
 * part and narrows the rows; PHP makes the final call with the same check the
 * fill uses, so the two can no longer disagree.
 *
 * @since 2.17.0
 */
final class Alt_Text_Coverage {

    /**
     * Alt rows read per query while counting.
     *
     * @var int
     */
    private const ROW_CHUNK = 2000;

    /**
     * Images read per query while looking for uncovered ones.
     *
     * @var int
     */
    private const ID_CHUNK = 500;

    /**
     * Number of images (not trashed) with at least one descriptive alt value.
     *
     * @since 2.17.0
     * @param \wpdb $wpdb Database.
     * @return int
     */
    public static function count_covered(\wpdb $wpdb): int {
        $descriptive = Alt_Text_Quality::sql_descriptive_condition('pm.meta_value');
        $covered     = [];
        $after       = 0;

        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset-paged read on the postmeta primary key; the condition is a fixed literal; callers cache the result
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.meta_id, pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = '_wp_attachment_image_alt'
                       AND pm.meta_id > %d
                       AND {$descriptive}
                       AND p.post_type = 'attachment'
                       AND p.post_mime_type LIKE %s
                       AND p.post_status != 'trash'
                     ORDER BY pm.meta_id ASC
                     LIMIT %d",
                    $after,
                    $wpdb->esc_like('image/') . '%',
                    self::ROW_CHUNK
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            foreach ($rows as $row) {
                $after = max($after, (int) $row->meta_id);
                $id    = (int) $row->post_id;

                if (!isset($covered[$id]) && Alt_Text_Quality::is_descriptive((string) $row->meta_value)) {
                    $covered[$id] = true;
                }
            }

            $more = count($rows) === self::ROW_CHUNK;
        } while ($more);

        return count($covered);
    }

    /**
     * The most recent images (not trashed) with no descriptive alt value.
     *
     * @since 2.17.0
     * @param \wpdb $wpdb  Database.
     * @param int   $limit Most IDs to return.
     * @return int[]
     */
    public static function uncovered_ids(\wpdb $wpdb, int $limit): array {
        $found  = [];
        $offset = 0;

        if ($limit < 1) {
            return $found;
        }

        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded id page for a cached analysis; no core API filters on alt text quality
            $ids = array_map(
                'intval',
                (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT p.ID FROM {$wpdb->posts} p
                         WHERE p.post_type = 'attachment'
                           AND p.post_mime_type LIKE %s
                           AND p.post_status != 'trash'
                         ORDER BY p.post_date DESC, p.ID DESC
                         LIMIT %d OFFSET %d",
                        $wpdb->esc_like('image/') . '%',
                        self::ID_CHUNK,
                        $offset
                    )
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            $covered = self::covered_among($wpdb, $ids);

            foreach ($ids as $id) {
                if (isset($covered[$id])) {
                    continue;
                }

                $found[] = $id;

                if (count($found) >= $limit) {
                    return $found;
                }
            }

            $read    = count($ids);
            $offset += $read;
        } while (self::ID_CHUNK === $read);

        return $found;
    }

    /**
     * Which of the given images have a descriptive alt value.
     *
     * @param \wpdb $wpdb Database.
     * @param int[] $ids  Attachment IDs.
     * @return array<int, true> Covered IDs as keys.
     */
    private static function covered_among(\wpdb $wpdb, array $ids): array {
        if ([] === $ids) {
            return [];
        }

        $descriptive  = Alt_Text_Quality::sql_descriptive_condition('pm.meta_value');
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one bounded IN list per page; the placeholders and condition are fixed literals
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_wp_attachment_image_alt'
                   AND pm.post_id IN ({$placeholders})
                   AND {$descriptive}",
                $ids
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $covered = [];
        foreach ($rows as $row) {
            if (Alt_Text_Quality::is_descriptive((string) $row->meta_value)) {
                $covered[(int) $row->post_id] = true;
            }
        }

        return $covered;
    }
}
