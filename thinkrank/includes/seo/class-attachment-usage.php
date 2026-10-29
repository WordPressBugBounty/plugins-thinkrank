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
     * The bound applies to a whole lookup, and a bulk alt fill looks up a
     * batch of up to 200 images at once, so it is reached far sooner than one
     * logo would reach it. usage() reports when it was, and the alt-text
     * purge turns that into a warning telling the user to clear by hand
     * (#926).
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
        return self::usage($attachment_ids, $limit)['posts'];
    }

    /**
     * Posts that reference the given attachments, kept per attachment.
     *
     * One lookup for the whole set, as posts_using() has always been, so a
     * bulk alt fill still costs three queries per batch rather than three per
     * image. What it adds is the answer that lookup used to throw away: which
     * attachment each post matched, so a per-image hook can be told that
     * image's own pages (#926), and whether the bound cut the answer short,
     * so the caller can say so instead of reporting a purge that stopped at
     * the 200th page.
     *
     * `truncated` is conservative. It is also raised when a candidate query
     * filled its row cap, because the rows past the cap were never read and
     * may hold more matches. A false alarm asks the user to clear a cache that
     * was already clear; a missed one leaves pages stale with no warning,
     * which is the defect being fixed.
     *
     * @since 2.15.0
     * @param int[] $attachment_ids Attachment IDs to look for.
     * @param int   $limit          Maximum posts to return. Defaults to MAX_POSTS.
     * @return array{posts: int[], by_attachment: array<int, int[]>, truncated: bool}
     *         `posts` ascending and capped at $limit; `by_attachment` maps every
     *         requested ID to the posts in `posts` that display it.
     */
    public static function usage(array $attachment_ids, int $limit = self::MAX_POSTS): array {
        global $wpdb;

        $ids    = array_values(array_unique(array_filter(array_map('intval', $attachment_ids))));
        $result = [
            'posts'         => [],
            'by_attachment' => array_fill_keys($ids, []),
            'truncated'     => false,
        ];

        if ([] === $ids || !isset($wpdb)) {
            return $result;
        }

        $limit = max(1, $limit);

        // Post ID => attachment IDs it was found to display.
        $found     = [];
        $truncated = false;

        foreach ([
            self::featured_image_posts($ids, $limit),
            self::meta_reference_posts($ids, $limit),
            self::content_reference_posts($ids, $limit),
        ] as [$matches, $capped]) {
            $truncated = $truncated || $capped;

            foreach ($matches as $post_id => $matched_ids) {
                $found[$post_id] = array_merge($found[$post_id] ?? [], $matched_ids);
            }
        }

        ksort($found, SORT_NUMERIC);

        if (count($found) > $limit) {
            $truncated = true;
            $found     = array_slice($found, 0, $limit, true);
        }

        foreach ($found as $post_id => $matched_ids) {
            foreach (array_unique($matched_ids) as $id) {
                $result['by_attachment'][$id][] = (int) $post_id;
            }
        }

        $result['posts']     = array_map('intval', array_keys($found));
        $result['truncated'] = $truncated;

        return $result;
    }

    /**
     * Posts using one of the attachments as their featured image.
     *
     * The only exact match of the three: `_thumbnail_id` holds the bare ID.
     * One row past the cap is asked for, so a full page can be told apart
     * from a cut-off one.
     *
     * @param int[] $ids   Attachment IDs.
     * @param int   $limit Row cap.
     * @return array{0: array<int, int[]>, 1: bool} Attachment IDs keyed by post ID, and whether rows were left unread.
     */
    private static function featured_image_posts(array $ids, int $limit): array {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $statuses     = self::status_placeholders();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id, pm.meta_value
                   FROM {$wpdb->postmeta} pm
                   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key = '_thumbnail_id'
                    AND pm.meta_value IN ({$placeholders})
                    AND p.post_status IN ({$statuses})
                  LIMIT %d",
                array_merge($ids, self::CACHEABLE_STATUSES, [$limit + 1])
            )
        );
        // phpcs:enable

        $rows  = (array) $rows;
        $posts = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $posts[(int) $row->post_id][] = (int) $row->meta_value;
        }

        return [$posts, count($rows) > $limit];
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
     * @return array{0: array<int, int[]>, 1: bool} Attachment IDs keyed by post ID, and whether the candidate cap was reached.
     */
    private static function meta_reference_posts(array $ids, int $limit): array {
        global $wpdb;

        $keys = Builder_Content::builder_meta_keys();

        if ([] === $keys) {
            return [[], false];
        }

        $key_placeholders = implode(',', array_fill(0, count($keys), '%s'));

        $like_clauses = [];
        $like_values  = [];
        foreach ($ids as $id) {
            $like_clauses[] = 'pm.meta_value LIKE %s';
            $like_values[]  = '%' . $wpdb->esc_like((string) $id) . '%';
        }

        // Candidates only: the LIKE matches 531 inside 5310 and inside any
        // unrelated number. mentioned_ids() below is what decides.
        $like_sql  = implode(' OR ', $like_clauses);
        $statuses  = self::status_placeholders();
        $row_limit = $limit * 4;

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
                array_merge($keys, $like_values, self::CACHEABLE_STATUSES, [$row_limit])
            )
        );
        // phpcs:enable

        $rows  = (array) $rows;
        $posts = [];
        foreach ($rows as $row) {
            $matched = self::mentioned_ids($ids, (string) $row->meta_value);
            if ([] !== $matched) {
                $posts[(int) $row->post_id] = array_merge($posts[(int) $row->post_id] ?? [], $matched);
            }
        }

        return [$posts, count($rows) >= $row_limit];
    }

    /**
     * Posts whose `post_content` references one of the attachments.
     *
     * Covers the block and classic editors, whose image markup carries the ID
     * in a `wp-image-<id>` class, a block attribute or a gallery shortcode.
     *
     * @param int[] $ids   Attachment IDs.
     * @param int   $limit Row cap.
     * @return array{0: array<int, int[]>, 1: bool} Attachment IDs keyed by post ID, and whether the candidate cap was reached.
     */
    private static function content_reference_posts(array $ids, int $limit): array {
        global $wpdb;

        $like_clauses = [];
        $like_values  = [];
        foreach ($ids as $id) {
            $like_clauses[] = 'p.post_content LIKE %s';
            $like_values[]  = '%' . $wpdb->esc_like((string) $id) . '%';
        }

        $like_sql  = implode(' OR ', $like_clauses);
        $statuses  = self::status_placeholders();
        $row_limit = $limit * 4;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_content
                   FROM {$wpdb->posts} p
                  WHERE p.post_type != 'attachment'
                    AND p.post_status IN ({$statuses})
                    AND ({$like_sql})
                  LIMIT %d",
                array_merge(self::CACHEABLE_STATUSES, $like_values, [$row_limit])
            )
        );
        // phpcs:enable

        $rows  = (array) $rows;
        $posts = [];
        foreach ($rows as $row) {
            $matched = self::content_mentioned_ids($ids, (string) $row->post_content);
            if ([] !== $matched) {
                $posts[(int) $row->ID] = $matched;
            }
        }

        return [$posts, count($rows) >= $row_limit];
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
     * @param int[]  $ids   Attachment IDs.
     * @param string $value Stored builder tree.
     * @return bool
     */
    private static function mentions_any(array $ids, string $value): bool {
        return [] !== self::mentioned_ids($ids, $value);
    }

    /**
     * The attachments a builder tree really refers to.
     *
     * A number surrounded by digits is a different number, which is the one
     * false positive worth ruling out. Beyond that this stays loose on purpose:
     * an ID that appears as some other setting's value costs one extra cache
     * purge, while a missed reference leaves a visitor looking at stale markup,
     * which is the bug being fixed.
     *
     * Every whole number in the tree is read once and intersected with the
     * set, rather than one search per ID: a batch asks about up to 200 IDs and
     * a builder tree can run to megabytes.
     *
     * @since 2.15.0
     * @param int[]  $ids   Attachment IDs.
     * @param string $value Stored builder tree.
     * @return int[] The IDs referred to, in the order given.
     */
    private static function mentioned_ids(array $ids, string $value): array {
        preg_match_all('/\d+/', $value, $numbers);

        return self::present($ids, $numbers[0]);
    }

    /**
     * Whether `post_content` really refers to one of the attachments.
     *
     * @param int[]  $ids     Attachment IDs.
     * @param string $content Post content.
     * @return bool
     */
    private static function content_mentions_any(array $ids, string $content): bool {
        return [] !== self::content_mentioned_ids($ids, $content);
    }

    /**
     * The attachments `post_content` really refers to.
     *
     * Stricter than the builder check, because editor markup names the ID in a
     * small set of known shapes and a bare number in prose is a real risk.
     * Each shape captures its whole number, so 531 never matches 5310.
     *
     * @since 2.15.0
     * @param int[]  $ids     Attachment IDs.
     * @param string $content Post content.
     * @return int[] The IDs referred to, in the order given.
     */
    private static function content_mentioned_ids(array $ids, string $content): array {
        $patterns = [
            '/wp-image-(\d+)/',                                    // Core image markup.
            '/"id"\s*:\s*(\d+)/',                                // Block attributes.
            '/\battachment[_-]?id["\']?\s*[:=]\s*["\']?(\d+)/i',
        ];

        $numbers = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                array_push($numbers, ...$matches[1]);
            }
        }

        // [gallery ids="…"]: every number in the list.
        if (preg_match_all('/ids\s*=\s*["\']([\d, ]*)/', $content, $lists)) {
            foreach ($lists[1] as $list) {
                preg_match_all('/\d+/', $list, $listed);
                array_push($numbers, ...$listed[0]);
            }
        }

        return self::present($ids, $numbers);
    }

    /**
     * The IDs that appear among some numbers read out of stored content.
     *
     * @param int[]    $ids     Attachment IDs.
     * @param string[] $numbers Whole numbers, as matched.
     * @return int[] The IDs found, in the order given.
     */
    private static function present(array $ids, array $numbers): array {
        if ([] === $numbers) {
            return [];
        }

        // Keyed by the digits as written: "0531" is not attachment 531.
        $seen = array_flip($numbers);

        return array_values(array_filter($ids, static fn(int $id): bool => isset($seen[(string) $id])));
    }
}
