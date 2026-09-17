<?php
/**
 * Shared length handling for rendered SEO descriptions.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 2.7.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trims SEO text to the length search engines display.
 *
 * Every path that renders a description must measure and cut through here
 * rather than carrying its own copy, because the obvious spelling of this is
 * wrong in two independent ways and both are invisible in English:
 *
 * - `strlen()` counts BYTES. UTF-8 Thai and CJK run three bytes per character,
 *   so a 54-character description already trips a 160-"character" gate it is
 *   nowhere near.
 * - `wp_trim_words()` does not always count words. WordPress reads the unit
 *   from a per-locale gettext string, and `th`, `ja` and `zh_*` set it to
 *   `characters_excluding_spaces` — so `wp_trim_words($text, 25)` keeps 25
 *   words in English and 25 *characters* in Thai.
 *
 * Together they cut Thai meta descriptions to roughly 25 characters while the
 * same stored value rendered in full in og:description, which reached the page
 * by a different path (#687).
 *
 * @since 2.7.0
 */
class Seo_Text {

    /**
     * Characters search engines display for a meta description.
     *
     * @since 2.7.0
     * @var int
     */
    public const MAX_LENGTH = 160;

    /**
     * Characters search engines display for a title.
     *
     * @since 2.7.0
     * @var int
     */
    public const TITLE_MAX_LENGTH = 60;

    /**
     * Whether this locale's word-count unit is actually words.
     *
     * WordPress reads the unit from a per-locale gettext string, and `th`,
     * `ja` and `zh_*` set it to `characters_excluding_spaces`. Anything that
     * passes a *word* cap to wp_trim_words() therefore has to ask first, or it
     * silently becomes a character cap roughly six times tighter (#687).
     *
     * wp_get_word_count_type() only exists from WP 6.2; the plugin supports
     * 6.0, so fall back to the same gettext string core reads.
     *
     * @since 2.7.0
     * @return bool
     */
    public static function locale_counts_words(): bool {
        if (function_exists('wp_get_word_count_type')) {
            return 'words' === wp_get_word_count_type();
        }

        // Core's own string, in core's text domain — this is the value
        // WP_Locale::get_word_count_type() returns from 6.2 onward. Reading it
        // from 'thinkrank' would look up a translation we do not ship and
        // always answer 'words', quietly disabling the check.
        // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- deliberately core's string.
        return 'words' === _x('words', 'Word count type. Do not translate!', 'default');
    }

    /**
     * Trim a description to a character budget, multibyte-safe.
     *
     * Cuts on a word boundary when one is available inside the budget, so the
     * result does not end mid-word; falls back to a hard character cut for
     * scripts that do not use spaces (CJK, Thai), where a word-boundary search
     * would find nothing and return the string untouched.
     *
     * Lifted from Author_Archives_Manager, which has carried the only correct
     * copy since 2.2.0 while four other paths kept the broken pattern.
     *
     * @since 2.7.0
     *
     * @param string $description Description text.
     * @param int    $limit       Maximum length in characters, ellipsis included.
     * @return string
     */
    public static function trim_to_length(string $description, int $limit = self::MAX_LENGTH): string {
        if ($limit <= 0) {
            return '';
        }

        if (mb_strlen($description) <= $limit) {
            return $description;
        }

        // Reserve one character for the ellipsis.
        $budget   = $limit - 1;
        $cut      = mb_substr($description, 0, $budget);
        $last_gap = mb_strrpos($cut, ' ');

        // Only honour a word boundary that is not absurdly early — otherwise a
        // long unbroken token would collapse the description to a few chars.
        if (false !== $last_gap && $last_gap > (int) ($budget * 0.6)) {
            $cut = mb_substr($cut, 0, $last_gap);
        }

        return rtrim($cut) . '…';
    }

    /**
     * Apply a WORD cap that stays a word cap.
     *
     * wp_trim_words() reads its unit from the locale, so `$words` silently
     * becomes a *character* cap on th/ja/zh_* — roughly six times tighter than
     * intended. Callers that mean "about N words" go through here: word-counting
     * locales keep wp_trim_words() byte for byte, and the rest get a character
     * budget instead of a mangled one (#687).
     *
     * @since 2.7.0
     *
     * @param string $text     Text to trim.
     * @param int    $words    Word cap, honoured only where words are the unit.
     * @param string $more     Appended by wp_trim_words() when it trims.
     * @param int    $fallback Character budget used where words are not the unit.
     * @return string
     */
    public static function trim_words(
        string $text,
        int $words,
        string $more = '...',
        int $fallback = self::MAX_LENGTH
    ): string {
        if (!self::locale_counts_words()) {
            return self::trim_to_length($text, $fallback);
        }

        return wp_trim_words($text, $words, $more);
    }
}
