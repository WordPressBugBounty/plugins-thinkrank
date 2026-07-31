<?php
/**
 * Focus Keywords helper.
 *
 * Central read/write/normalize logic for the multi-focus-keyword feature.
 * Keywords are stored as an array in `_thinkrank_focus_keywords` (up to
 * Focus_Keywords::MAX). The legacy single-value meta `_thinkrank_focus_keyword`
 * is kept in sync (= the primary/first keyword) for backward compatibility with
 * older consumers that still read a string.
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
     * Array post meta key holding the full keyword list.
     */
    public const META_KEY = '_thinkrank_focus_keywords';

    /**
     * Legacy single-string meta key (kept = primary keyword for back-compat).
     */
    public const LEGACY_META_KEY = '_thinkrank_focus_keyword';

    /**
     * Meta key holding keywords beyond the current plan's usable limit.
     *
     * Keywords past the free cap are stored here rather than discarded, so they
     * are never lost: they stay gated on free and become usable automatically
     * once ThinkRank Pro raises the limit (see get()). The 6th+ are "Pro" only.
     */
    public const OVERFLOW_META_KEY = '_thinkrank_focus_keywords_overflow';

    /**
     * Free-tier maximum number of usable focus keywords per post.
     *
     * The effective limit is plan-aware — see limit(). Pro lifts this cap.
     */
    public const MAX = 5;

    /**
     * Effective number of usable focus keywords for the current plan.
     *
     * Resolves through Plan_Config (filterable by the Pro plugin). Returns 0 for
     * "unlimited". Use this everywhere a cap is applied so the limit follows the
     * plan rather than being hard-coded.
     *
     * @return int Usable keyword limit; 0 = unlimited.
     */
    public static function limit(): int {
        if (!class_exists('\ThinkRank\Core\Plan_Config')) {
            return self::MAX;
        }
        $caps = \ThinkRank\Core\Plan_Config::focus_keywords();
        return (int) ($caps['max_keywords'] ?? self::MAX);
    }

    /**
     * Normalize arbitrary input into a clean keyword array.
     *
     * Accepts an array of strings or a comma-separated string. Trims and
     * sanitizes each value, drops empties, removes case-insensitive duplicates
     * (keeping first occurrence / original order), and caps the result at
     * `$limit`.
     *
     * @param mixed    $input Array of keywords or comma-separated string.
     * @param int|null $limit Maximum keywords to return. Null (default) uses the
     *                        plan-aware limit(). Pass 0 (or negative) to return
     *                        the full deduped list uncapped.
     * @return string[] Normalized keyword list.
     */
    public static function normalize($input, ?int $limit = null): array {
        if ($limit === null) {
            $limit = self::limit();
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
     * Get the usable focus keywords for a post (capped at the plan limit).
     *
     * Merges the stored keywords with any gated overflow, then caps at the
     * plan-aware limit(). On free this returns the first 5 (overflow stays
     * gated); on Pro the overflow keywords become usable automatically — no
     * re-import needed. Falls back to the legacy single value for back-compat.
     *
     * @param int $post_id Post ID.
     * @return string[] Usable keyword list (capped at limit()).
     */
    public static function get(int $post_id): array {
        $base = self::read_stored($post_id);
        $overflow = self::read_overflow($post_id);

        return self::normalize(array_merge($base, $overflow));
    }

    /**
     * Read the stored base keyword array (array meta, legacy fallback). Uncapped.
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
     * Read the gated overflow keywords (keywords beyond the free cap).
     *
     * @param int $post_id Post ID.
     * @return string[] Overflow keywords (deduped, uncapped).
     */
    private static function read_overflow(int $post_id): array {
        $overflow = get_post_meta($post_id, self::OVERFLOW_META_KEY, true);
        return is_array($overflow) ? self::normalize($overflow, 0) : [];
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
     * Save focus keywords edited by the user (metabox / inline edit / AI).
     *
     * The base meta always holds at most MAX keywords; anything beyond is kept
     * in the gated overflow meta. This storage boundary is FIXED at MAX (it does
     * NOT follow the plan limit) so the stored data is plan-portable: toggling
     * Pro on/off only changes how much get() reveals, never where keywords live,
     * so no keyword is ever stranded or lost.
     *
     * On Pro the input is the user's complete keyword set, so it is split into
     * base (first MAX) + overflow (rest). On free the input is only the visible
     * first MAX keywords, so it replaces the base while the gated overflow is
     * left untouched (preserved).
     *
     * @param int   $post_id Post ID.
     * @param mixed $input   Array of keywords or comma-separated string.
     * @return string[] The keyword list persisted to the base meta.
     */
    public static function save(int $post_id, $input): array {
        // Pro edits the full set: split it across base + overflow at MAX.
        if (self::is_unlimited()) {
            return self::save_with_overflow($post_id, $input)['kept'];
        }

        // Free edits only the visible (first MAX) keywords. Cap to the base
        // boundary and leave any gated overflow untouched.
        $keywords = self::normalize($input, self::MAX);

        if (empty($keywords)) {
            delete_post_meta($post_id, self::META_KEY);
            delete_post_meta($post_id, self::LEGACY_META_KEY);
            return [];
        }

        update_post_meta($post_id, self::META_KEY, $keywords);
        update_post_meta($post_id, self::LEGACY_META_KEY, $keywords[0]);

        return $keywords;
    }

    /**
     * Persist a full keyword list, splitting into usable + gated overflow.
     *
     * Used by import/migration and by Pro saves where the source may carry more
     * keywords than the free plan reveals. The split point is FIXED at MAX (not
     * the plan limit): the first MAX keywords are the base, the rest are stored
     * in the overflow meta (gated on free, auto-revealed by Pro via get()). This
     * keeps stored data plan-portable so deactivating Pro never strands or loses
     * keywords.
     *
     * @param int   $post_id Post ID.
     * @param mixed $input   Array of keywords or comma-separated string.
     * @return array{kept:string[],overflow:string[]} What was stored where.
     */
    public static function save_with_overflow(int $post_id, $input): array {
        $all = self::normalize($input, 0);

        if (empty($all)) {
            delete_post_meta($post_id, self::META_KEY);
            delete_post_meta($post_id, self::LEGACY_META_KEY);
            delete_post_meta($post_id, self::OVERFLOW_META_KEY);
            return ['kept' => [], 'overflow' => []];
        }

        $kept = array_slice($all, 0, self::MAX);
        $overflow = array_slice($all, self::MAX);

        update_post_meta($post_id, self::META_KEY, $kept);
        update_post_meta($post_id, self::LEGACY_META_KEY, $kept[0]);

        if (!empty($overflow)) {
            update_post_meta($post_id, self::OVERFLOW_META_KEY, $overflow);
        } else {
            delete_post_meta($post_id, self::OVERFLOW_META_KEY);
        }

        return ['kept' => $kept, 'overflow' => $overflow];
    }

    /**
     * Whether the current plan allows unlimited focus keywords.
     *
     * @return bool True when limit() is 0 (unlimited).
     */
    private static function is_unlimited(): bool {
        return self::limit() <= 0;
    }
}
