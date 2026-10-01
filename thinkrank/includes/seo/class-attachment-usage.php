<?php

/**
 * Finds the posts an attachment appears on.
 *
 * WordPress records where a file lives, never where it is shown. `post_parent`
 * only says which screen the file was uploaded from, so it is wrong as often as
 * it is right, and page builders keep their image references in their own
 * stored trees rather than in `post_content`. Answering "which pages render
 * this image?" therefore means looking in four different places (#763).
 *
 * This is deliberately a search rather than an index. The question is asked
 * after an alt-text write, a rare event, and an index of image usage would have
 * to be invalidated by every post save, every builder save and every media
 * replacement to stay honest. A bounded search run a few times a day is the
 * cheaper side of that trade.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.12.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves attachment IDs to the posts that display them.
 *
 * @since 2.12.0
 */
class Attachment_Usage {

    /**
     * Most posts reported for one lookup.
     *
     * The caller uses the answer to delete post meta and purge URLs, so an
     * unbounded result turns one alt-text write into thousands of writes. A
     * logo used site-wide is exactly that case, and for it the right answer is
     * a full cache clear by hand, not a slow request that half finishes.
     *
     * @since 2.12.0
     * @var int
     */
    public const MAX_POSTS = 200;

    /**
     * Post statuses whose rendered output can be sitting in a cache.
     *
     * Elementor only stores its element cache on a front-end, non-preview
     * request, and page caches only keep what a visitor could fetch, so a
     * draft has nothing cached to drop.
     *
     * @since 2.12.0
     * @var string[]
     */
    private const CACHEABLE_STATUSES = ['publish', 'private'];

    /**
     * Posts that reference any of the given attachments.
     *
     * @since 2.12.0
     * @param int[] $attachment_ids Attachment IDs to look for.
     * @param int   $limit          Maximum posts to return. Defaults to MAX_POSTS.
     * @return int[] Post IDs, ascending, without duplicates.
     */
    public static function posts_using(array $attachment_ids, int $limit = self::MAX_POSTS): array {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $attachment_ids))));

        if ([] === $ids || !isset($wpdb)) {
            return [];
        }

        $limit  = max(1, $limit);
        $found  = self::featured_image_posts($ids, $limit);
        $found += self::flip(self::meta_reference_posts($ids, $limit));
        $found += self::flip(self::content_reference_posts($ids, $limit));

        $posts = array_keys($found);
        sort($posts, SORT_NUMERIC);

        return array_slice($posts, 0, $limit);
    }

    /**
     * Posts using one of the attachments as their featured image.
     *
     * The only exact match of the three: `_thumbnail_id` holds the bare ID.
     *
     * @param int[] $ids   Attachment IDs.
     * @param int   $limit Row cap.
     * @return array<int, bool> Post IDs as keys.
     */
    private static function featured_image_posts(array $ids, int $limit): array {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $statuses     = self::status_placeholders();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                   FROM {$wpdb->postmeta} pm
                   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key = '_thumbnail_id'
                    AND pm.meta_value IN ({$placeholders})
                    AND p.post_status IN ({$statuses})
                  LIMIT %d",
                array_merge($ids, self::CACHEABLE_STATUSES, [$limit])
            )
        );
        // phpcs:enable

        return self::flip(array_map('intval', (array) $rows));
    }

    /**
     * Posts whose builder tree mentions one of the attachments.
     *
     * Every builder stores its tree as JSON or as serialised PHP, so the ID
     * cannot be matched exactly in SQL. The query narrows on `meta_key`, which
     * is indexed, and PHP then confirms each candidate.
     *
     * @param int[] $ids   Attachment IDs.
     * @param int   $limit Row cap.
     * @return int[] Post IDs.
     */
    private static function meta_reference_posts(array $ids, int $limit): array {
        global $wpdb;

        $keys = Builder_Content::builder_meta_keys();

        if ([] === $keys) {
            return [];
        }

        $key_placeholders = implode(',', array_fill(0, count($keys), '%s'));

        $like_clauses = [];
        $like_values  = [];
        foreach ($ids as $id) {
            $like_clauses[] = 'pm.meta_value LIKE %s';
            $like_values[]  = '%' . $wpdb->esc_like((string) $id) . '%';
        }

        // Candidates only: the LIKE matches 531 inside 5310 and inside any
        // unrelated number. verify() below is what decides.
        $like_sql = implode(' OR ', $like_clauses);
        $statuses = self::status_placeholders();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value
                   FROM {$wpdb->postmeta} pm
                   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key IN ({$key_placeholders})
                    AND ({$like_sql})
                    AND p.post_status IN ({$statuses})
                  LIMIT %d",
                array_merge($keys, $like_values, self::CACHEABLE_STATUSES, [$limit * 4])
            )
        );
        // phpcs:enable

        $posts = [];
        foreach ((array) $rows as $row) {
            if (self::mentions_any($ids, (string) $row->meta_value)) {
                $posts[] = (int) $row->post_id;
            }
        }

        return $posts;
    }

    /**
     * Posts whose `post_content` references one of the attachments.
     *
     * Covers the block and classic editors, whose image markup carries the ID
     * in a `wp-image-<id>` class, a block attribute or a gallery shortcode.
     *
     * @param int[] $ids   Attachment IDs.
     * @param int   $limit Row cap.
     * @return int[] Post IDs.
     */
    private static function content_reference_posts(array $ids, int $limit): array {
        global $wpdb;

        $like_clauses = [];
        $like_values  = [];
        foreach ($ids as $id) {
            $like_clauses[] = 'p.post_content LIKE %s';
            $like_values[]  = '%' . $wpdb->esc_like((string) $id) . '%';
        }

        $like_sql = implode(' OR ', $like_clauses);
        $statuses = self::status_placeholders();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_content
                   FROM {$wpdb->posts} p
                  WHERE p.post_type != 'attachment'
                    AND p.post_status IN ({$statuses})
                    AND ({$like_sql})
                  LIMIT %d",
                array_merge(self::CACHEABLE_STATUSES, $like_values, [$limit * 4])
            )
        );
        // phpcs:enable

        $posts = [];
        foreach ((array) $rows as $row) {
            if (self::content_mentions_any($ids, (string) $row->post_content)) {
                $posts[] = (int) $row->ID;
            }
        }

        return $posts;
    }

    /**
     * `%s` placeholders for CACHEABLE_STATUSES, for an IN () clause.
     *
     * @return string
     */
    private static function status_placeholders(): string {
        return implode(',', array_fill(0, count(self::CACHEABLE_STATUSES), '%s'));
    }

    /**
     * Whether a builder tree really refers to one of the attachments.
     *
     * A number surrounded by digits is a different number, which is the one
     * false positive worth ruling out. Beyond that this stays loose on purpose:
     * an ID that appears as some other setting's value costs one extra cache
     * purge, while a missed reference leaves a visitor looking at stale markup,
     * which is the bug being fixed.
     *
     * @param int[]  $ids   Attachment IDs.
     * @param string $value Stored builder tree.
     * @return bool
     */
    private static function mentions_any(array $ids, string $value): bool {
        foreach ($ids as $id) {
            if (1 === preg_match('/(?<!\d)' . $id . '(?!\d)/', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `post_content` really refers to one of the attachments.
     *
     * Stricter than the builder check, because editor markup names the ID in a
     * small set of known shapes and a bare number in prose is a real risk.
     *
     * @param int[]  $ids     Attachment IDs.
     * @param string $content Post content.
     * @return bool
     */
    private static function content_mentions_any(array $ids, string $content): bool {
        foreach ($ids as $id) {
            $patterns = [
                '/wp-image-' . $id . '(?!\d)/',            // Core image markup.
                '/"id"\s*:\s*' . $id . '(?!\d)/',          // Block attributes.
                '/\battachment[_-]?id["\']?\s*[:=]\s*["\']?' . $id . '(?!\d)/i',
                '/ids\s*=\s*["\'][\d, ]*(?<!\d)' . $id . '(?!\d)/', // [gallery ids="…"].
            ];

            foreach ($patterns as $pattern) {
                if (1 === preg_match($pattern, $content)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Turn a list of IDs into a set keyed by ID.
     *
     * @param int[] $ids IDs.
     * @return array<int, bool>
     */
    private static function flip(array $ids): array {
        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }
}
