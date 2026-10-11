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
     * The text a reader sees for a resolved title or description.
     *
     * Pattern_Resolver hands back what the page will print, and that is still
     * HTML: get_the_title() runs wptexturize, so "Foo & Bar" arrives as
     * `Foo &#038; Bar`, while the same words typed into the SEO title field
     * arrive as `Foo &amp; Bar` or a bare `&`. All three render as one `<title>`.
     * Anything that compares, measures or displays the value as text has to
     * look at it after the browser would have decoded it, or it groups two
     * identical titles apart, counts `&#038;` as six characters, and shows the
     * entity to the user.
     *
     * @since 2.10.0
     *
     * @param string $value Resolved title or description.
     * @return string The same value with HTML entities decoded.
     */
    public static function as_displayed(string $value): string {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip leading and trailing whitespace and title separators.
     *
     * The separator is removed as a whole string, never byte by byte.
     * `trim($title, " \t..." . $separator)` reads its second argument as a
     * list of single bytes, so with the Bullet separator (`•`, E2 80 A2) it
     * also stripped any E2, 80 or A2 byte at either end of the title: the
     * middle of €, ✅, curly quotes and many Cyrillic letters. The broken
     * UTF-8 left behind made `<title>` invalid and `esc_attr()` blank out
     * og:title and twitter:title (#972).
     *
     * @since 2.15.0
     *
     * @param string $value     Title or description.
     * @param string $separator The active separator, possibly multibyte.
     * @return string
     */
    public static function trim_separator(string $value, string $separator): string {
        $alternatives = '[\s\x00]';
        if ('' !== $separator) {
            $alternatives .= '|' . preg_quote($separator, '/');
        }

        $trimmed = preg_replace('/^(?:' . $alternatives . ')+|(?:' . $alternatives . ')+$/u', '', $value);

        // Invalid UTF-8 makes a /u pattern fail. Fall back to whitespace only,
        // which cannot cut a character in half.
        return null === $trimmed ? trim($value) : $trimmed;
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
     * Make text fit to appear in JSON-LD.
     *
     * JSON-LD is not HTML, so an HTML entity in it is not decoded by anything
     * downstream: `&amp;` was published to answer engines literally. And the
     * excerpt path appends core's trimming marker, so descriptions arrived
     * ending in `[…]`, a truncation artefact presented as the page's own
     * summary.
     *
     * Lives here rather than in the schema class that introduced it (#766)
     * because two producers build description nodes: the automatic
     * Global_SEO_Schema_Output and the Schema Manager's Schema_Builder. Only
     * the first normalised, so a deployed node, which outranks the automatic
     * one, published the raw entity again.
     *
     * @since 2.10.0
     *
     * @param string $text Raw text.
     * @return string
     */
    public static function normalize_schema_text(string $text): string {
        if ('' === trim($text)) {
            return '';
        }

        $text = self::decode_schema_entities(wp_strip_all_tags($text));

        // Core's excerpt marker, in both its entity and literal forms, with or
        // without the surrounding brackets it is normally wrapped in.
        $text = (string) preg_replace(
            '/\s*(\[\s*(\x{2026}|\.\.\.)\s*\]|\x{2026})\s*$/u',
            '',
            $text
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Make a title or name fit to appear in JSON-LD.
     *
     * get_the_title() runs wptexturize and keeps any markup in the title, and
     * WordPress stores term names and the site name esc_html()'d, so a page
     * called "R&D at Tom's" reached the graph as `R&#038;D at Tom&#8217;s` and
     * a category "Cats & Dogs" as `Cats &amp; Dogs`. Nothing downstream
     * decodes JSON-LD, so those were published literally (#976).
     *
     * normalize_schema_text() without its excerpt-marker strip: that marker
     * is an artefact of a derived description, while a title that ends in an
     * ellipsis was written that way and keeps it. A decoded `<` stays safe
     * because the graph is encoded with JSON_HEX_TAG (#118).
     *
     * @since 2.15.0
     *
     * @param string $text Raw title or name.
     * @return string Plain text with tags removed and entities decoded.
     */
    public static function normalize_schema_name(string $text): string {
        if ('' === trim($text)) {
            return '';
        }

        $text = self::decode_schema_entities(wp_strip_all_tags($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Decode HTML entities in text bound for JSON-LD, and nothing else.
     *
     * The narrow half of normalize_schema_text(), for values that must keep
     * their exact shape otherwise: a stored snapshot already truncated with an
     * ellipsis would lose it to the excerpt-marker strip.
     *
     * @since 2.10.0
     *
     * @param string $text Text that may carry HTML entities.
     * @return string
     */
    public static function decode_schema_entities(string $text): string {
        // Twice: a description that has been through an escaping pass already
        // (core stores `&amp;amp;` for a literal `&amp;` in some paths) would
        // otherwise still carry an entity after one decode. Decoding an
        // already-plain string is a no-op, so this is safe to repeat.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
