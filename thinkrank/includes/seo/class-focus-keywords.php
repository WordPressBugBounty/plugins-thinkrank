<?php
/**
 * Focus Keywords helper.
 *
 * Central read/write/normalize logic for the multi-focus-keyword feature.
 * Keywords are stored as an array in `_thinkrank_focus_keywords`, up to
 * Focus_Keywords::MAX. The legacy single-value meta `_thinkrank_focus_keyword`
 * is kept in sync (= the primary/first keyword) for backward compatibility with
 * older consumers that still read a string.
 *
 * This plugin stores MAX keywords per post and nothing beyond. An extension
 * that stores more (ThinkRank Pro) receives the full submitted list through
 * `thinkrank_focus_keywords_saved` and returns it through
 * `thinkrank_focus_keywords` (#673).
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Focus Keywords storage + normalization helper.
 *
 * @since 1.0.0
 */
class Focus_Keywords {

    /**
     * Array post meta key holding the keyword list.
     */
    public const META_KEY = '_thinkrank_focus_keywords';

    /**
     * Legacy single-string meta key (kept = primary keyword for back-compat).
     */
    public const LEGACY_META_KEY = '_thinkrank_focus_keyword';

    /**
     * Focus keywords stored per post.
     */
    public const MAX = 5;

    /**
     * Normalize arbitrary input into a clean keyword array.
     *
     * Accepts an array of strings or a comma-separated string. Trims and
     * sanitizes each value, drops empties, removes case-insensitive duplicates
     * (keeping first occurrence / original order), and caps the result at
     * `$limit`.
     *
     * @param mixed    $input Array of keywords or comma-separated string.
     * @param int|null $limit Maximum keywords to return. Null (default) is MAX.
     *                        Pass 0 (or negative) to return the full deduped
     *                        list uncapped.
     * @return string[] Normalized keyword list.
     */
    public static function normalize($input, ?int $limit = null): array {
        if ($limit === null) {
            $limit = self::MAX;
        }

        if (is_string($input)) {
            $input = explode(',', $input);
        }

        if (!is_array($input)) {
            return [];
        }

        $seen = [];
        $keywords = [];

        foreach ($input as $keyword) {
            if (is_array($keyword)) {
                continue;
            }

            $keyword = sanitize_text_field(trim((string) $keyword));
            if ($keyword === '') {
                continue;
            }

            $dedupe_key = function_exists('mb_strtolower')
                ? mb_strtolower($keyword)
                : strtolower($keyword);

            if (isset($seen[$dedupe_key])) {
                continue;
            }

            $seen[$dedupe_key] = true;
            $keywords[] = $keyword;

            if ($limit > 0 && count($keywords) >= $limit) {
                break;
            }
        }

        return $keywords;
    }

    /**
     * Get the focus keywords for a post.
     *
     * Falls back to the legacy single value for back-compat.
     *
     * @param int $post_id Post ID.
     * @return string[] Keyword list.
     */
    public static function get(int $post_id): array {
        $keywords = self::normalize(self::read_stored($post_id));

        /**
         * Filter a post's focus keywords.
         *
         * This plugin stores up to Focus_Keywords::MAX. An extension that
         * stores more returns the full list here.
         *
         * @since 2.6.0
         *
         * @param string[] $keywords Stored keywords, in order.
         * @param int      $post_id  Post ID.
         */
        $filtered = apply_filters('thinkrank_focus_keywords', $keywords, $post_id);

        return is_array($filtered) ? self::normalize($filtered, 0) : $keywords;
    }

    /**
     * Read the stored keyword array (array meta, legacy fallback). Uncapped.
     *
     * @param int $post_id Post ID.
     * @return string[] Stored keywords (deduped, uncapped).
     */
    private static function read_stored(int $post_id): array {
        $stored = get_post_meta($post_id, self::META_KEY, true);
        if (is_array($stored) && !empty($stored)) {
            return self::normalize($stored, 0);
        }

        // Backward compatibility: convert the old single value into an array.
        $legacy = get_post_meta($post_id, self::LEGACY_META_KEY, true);
        if (is_string($legacy) && $legacy !== '') {
            return self::normalize($legacy, 0);
        }

        return [];
    }

    /**
     * Get the primary (first) focus keyword for a post.
     *
     * @param int $post_id Post ID.
     * @return string Primary keyword, or '' when none set.
     */
    public static function get_primary(int $post_id): string {
        $keywords = self::get($post_id);
        return $keywords[0] ?? '';
    }

    /**
     * Save focus keywords (metabox / inline edit / AI / import).
     *
     * Stores the first MAX keywords and hands the full submitted list to
     * `thinkrank_focus_keywords_saved`.
     *
     * @param int   $post_id Post ID.
     * @param mixed $input   Array of keywords or comma-separated string.
     * @return string[] The post's keywords after the save, as get() reads them.
     */
    public static function save(int $post_id, $input): array {
        $all  = self::normalize($input, 0);
        $kept = array_slice($all, 0, self::MAX);

        if (empty($kept)) {
            delete_post_meta($post_id, self::META_KEY);
            delete_post_meta($post_id, self::LEGACY_META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, $kept);
            update_post_meta($post_id, self::LEGACY_META_KEY, $kept[0]);
        }

        /**
         * Fires after a post's focus keywords are saved.
         *
         * @since 2.6.0
         *
         * @param int      $post_id  Post ID.
         * @param string[] $keywords The full submitted list, including any
         *                           beyond Focus_Keywords::MAX.
         */
        do_action('thinkrank_focus_keywords_saved', $post_id, $all);

        return self::get($post_id);
    }
}
