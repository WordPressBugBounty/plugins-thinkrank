<?php
/**
 * AI Target Language Resolver
 *
 * Resolves the human-readable language ThinkRank should instruct the AI to
 * write metadata / briefs in. Without this, the generation prompts carried no
 * language directive and lighter models (e.g. GPT-5-mini) defaulted to English
 * on non-English sites — emitting English or mixed-language titles and
 * descriptions on, say, a Spanish news site. See GitHub issue #234.
 *
 * Resolution order:
 *   1. The post's own language when a multilingual plugin (Polylang / WPML) is
 *      active and a post id is supplied — the accurate signal on sites whose
 *      content language differs per post.
 *   2. The site locale, get_locale().
 *   3. The `thinkrank_ai_target_language` filter, for explicit overrides on
 *      sites whose content language differs from the WordPress locale.
 *
 * @package ThinkRank\AI
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the AI output language for a given post/site.
 *
 * @since 1.27.0
 */
class Language_Resolver {

    /**
     * Primary-subtag → English language name map for the locales WordPress
     * ships translations for. Used as a dependency-free fallback; when the
     * intl extension is present we prefer its richer display names.
     *
     * @var array<string,string>
     */
    private const LANGUAGE_NAMES = [
        'en' => 'English',    'es' => 'Spanish',    'fr' => 'French',
        'de' => 'German',     'it' => 'Italian',    'pt' => 'Portuguese',
        'nl' => 'Dutch',      'ru' => 'Russian',    'pl' => 'Polish',
        'sv' => 'Swedish',    'da' => 'Danish',     'nb' => 'Norwegian',
        'nn' => 'Norwegian',  'fi' => 'Finnish',    'cs' => 'Czech',
        'sk' => 'Slovak',     'ro' => 'Romanian',   'hu' => 'Hungarian',
        'el' => 'Greek',      'tr' => 'Turkish',    'ar' => 'Arabic',
        'he' => 'Hebrew',     'fa' => 'Persian',    'hi' => 'Hindi',
        'bn' => 'Bengali',    'ur' => 'Urdu',       'th' => 'Thai',
        'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay',
        'ja' => 'Japanese',   'ko' => 'Korean',     'zh' => 'Chinese',
        'uk' => 'Ukrainian',  'bg' => 'Bulgarian',  'hr' => 'Croatian',
        'sr' => 'Serbian',    'sl' => 'Slovenian',  'lt' => 'Lithuanian',
        'lv' => 'Latvian',    'et' => 'Estonian',   'ca' => 'Catalan',
        'gl' => 'Galician',   'eu' => 'Basque',     'af' => 'Afrikaans',
        'sw' => 'Swahili',    'tl' => 'Filipino',   'is' => 'Icelandic',
    ];

    /**
     * Resolve the human-readable target language name for a post/site.
     *
     * @since 1.27.0
     *
     * @param int $post_id Post being optimized, or 0 for a site-level target.
     * @return string Human-readable language name, e.g. "Spanish".
     */
    public static function resolve(int $post_id = 0): string {
        $locale   = self::resolve_locale($post_id);
        $language = self::locale_to_name($locale);

        /**
         * Filter the language ThinkRank instructs the AI to write output in.
         *
         * Return a plain language name ("Spanish", "Brazilian Portuguese").
         * An empty string disables the language directive entirely, restoring
         * the pre-1.27.0 behavior of letting the model infer the language.
         *
         * @since 1.27.0
         *
         * @param string $language Human-readable language name.
         * @param int    $post_id  Post being optimized (0 when site-level).
         * @param string $locale   Resolved locale, e.g. "es_ES".
         */
        return (string) apply_filters('thinkrank_ai_target_language', $language, $post_id, $locale);
    }

    /**
     * Resolve the effective locale for a post/site.
     *
     * @since 1.27.0
     *
     * @param int $post_id Post id, or 0 for the site locale.
     * @return string A WordPress locale, e.g. "es_ES".
     */
    public static function resolve_locale(int $post_id = 0): string {
        if ($post_id > 0) {
            // Polylang: gives the post's own language locale directly.
            if (function_exists('pll_get_post_language')) {
                $loc = pll_get_post_language($post_id, 'locale');
                if (is_string($loc) && $loc !== '') {
                    return $loc;
                }
            }

            // WPML: post language details carry a locale (and always a code).
            if (has_filter('wpml_post_language_details')) {
                $details = apply_filters('wpml_post_language_details', null, $post_id);
                if (is_array($details)) {
                    if (!empty($details['locale']) && is_string($details['locale'])) {
                        return $details['locale'];
                    }
                    if (!empty($details['language_code']) && is_string($details['language_code'])) {
                        // Only a bare code (e.g. "es"); locale_to_name reads the
                        // primary subtag anyway, so passing the code is fine.
                        return $details['language_code'];
                    }
                }
            }
        }

        return get_locale();
    }

    /**
     * Convert a locale (or bare language code) to an English language name.
     *
     * Falls back to the intl extension when present, then to the built-in map,
     * then to the raw locale so the directive is never blank for a real locale.
     *
     * @since 1.27.0
     *
     * @param string $locale Locale or language code, e.g. "es_ES" or "es".
     * @return string Language name, e.g. "Spanish".
     */
    public static function locale_to_name(string $locale): string {
        $locale = str_replace('-', '_', trim($locale));
        if ($locale === '') {
            return '';
        }

        $subtag = strtolower(explode('_', $locale)[0]);

        if (class_exists('\Locale')) {
            $name = \Locale::getDisplayLanguage($locale, 'en');
            // getDisplayLanguage echoes the input back when it can't resolve;
            // treat that as a miss and fall through to the map.
            if (is_string($name) && $name !== '' && strtolower($name) !== strtolower($locale) && strtolower($name) !== $subtag) {
                return $name;
            }
        }

        return self::LANGUAGE_NAMES[$subtag] ?? $locale;
    }
}
