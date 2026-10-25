<?php
/**
 * Language-specific text rules for the SEO score and the keyword helpers.
 *
 * Readability, the title's power and sentiment words and the stop-word lists
 * were all written for English (#1298). A French post was scored with the
 * English Flesch formula and English syllable rules, its title could never
 * carry a "power word", and "le" and "les" were its top keywords. This is the
 * one place those rules are kept per language, so the calculator, the AI title
 * improver and the keyword extractors all agree, and a site can add to them.
 *
 * English stays exactly as it was. French has its own rules. Every other
 * language gets the English readability formula and word lists, as before, and
 * no stop words rather than English ones.
 *
 * @package ThinkRank
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

if (!defined('ABSPATH')) {
    exit;
}

final class Text_Language {

    /**
     * Vowels, by language, for counting syllables. "y" is a vowel in both.
     */
    private const VOWELS = [
        'en' => 'aeiouy',
        'fr' => 'aeiouyàâäéèêëîïôöùûüÿœæ',
    ];

    private const POWER_WORDS = [
        'en' => [
            'ultimate', 'essential', 'complete', 'proven', 'guide', 'best', 'top',
            'free', 'easy', 'simple', 'quick', 'fast', 'powerful', 'secret', 'expert',
            'effective', 'amazing', 'incredible', 'exclusive', 'definitive', 'step-by-step',
        ],
        'fr' => [
            'ultime', 'essentiel', 'essentielle', 'complet', 'complète', 'prouvé', 'guide',
            'meilleur', 'meilleure', 'meilleurs', 'meilleures', 'gratuit', 'gratuite',
            'facile', 'simple', 'rapide', 'puissant', 'puissante', 'secret', 'expert',
            'efficace', 'incroyable', 'exclusif', 'exclusive', 'définitif', 'définitive',
            'étape par étape', 'astuces', 'conseils',
        ],
    ];

    private const SENTIMENT_WORDS = [
        'en' => [
            // Positive
            'great', 'good', 'better', 'awesome', 'love', 'win', 'boost', 'improve',
            'success', 'smart', 'brilliant', 'perfect', 'happy', 'beautiful',
            // Negative (drives clicks too)
            'avoid', 'mistake', 'worst', 'stop', 'never', 'bad', 'wrong', 'fail',
            'danger', 'warning', 'painful', 'ugly',
        ],
        'fr' => [
            // Positive
            'excellent', 'excellente', 'parfait', 'parfaite', 'génial', 'géniale',
            'réussir', 'réussite', 'améliorer', 'booster', 'bon', 'bonne', 'mieux',
            'formidable', 'heureux', 'heureuse', 'beau', 'belle', 'intelligent', 'intelligente',
            // Negative
            'éviter', 'erreur', 'erreurs', 'pire', 'arrêtez', 'jamais', 'mauvais', 'mauvaise',
            'faux', 'fausse', 'échec', 'échouer', 'danger', 'attention', 'douloureux',
        ],
    ];

    private const STOP_WORDS = [
        'en' => [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
            'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does',
            'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'this',
            'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him',
            'her', 'us', 'them',
        ],
        'fr' => [
            'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'd', 'et', 'ou', 'mais',
            'en', 'sur', 'à', 'au', 'aux', 'pour', 'avec', 'par', 'dans', 'ce', 'cet', 'cette',
            'ces', 'il', 'elle', 'ils', 'elles', 'je', 'j', 'tu', 'nous', 'vous', 'on', 'est',
            'sont', 'était', 'été', 'être', 'a', 'ont', 'avoir', 'que', 'qu', 'qui', 'quoi',
            'dont', 'ne', 'n', 'pas', 'plus', 'se', 's', 'sa', 'son', 'ses', 'leur', 'leurs',
            'y', 'c', 'ça', 'cela', 'comme', 'tout', 'tous', 'toute', 'toutes',
        ],
    ];

    /**
     * The language of a locale: "fr" for "fr_FR", "fr-CA" or "fr".
     *
     * @param string $locale A WordPress locale, or '' for the site's.
     * @return string Lowercase language code; "en" when nothing is known.
     */
    public static function code(string $locale): string {
        $locale = str_replace('-', '_', trim($locale));
        if ('' === $locale) {
            $locale = (string) get_locale();
        }

        $code = strtolower(explode('_', $locale)[0]);

        return '' === $code ? 'en' : $code;
    }

    /**
     * Lowercase in any script. strtolower() only folds ASCII, so "Éducation"
     * kept its É and never matched "éducation" (#1298).
     *
     * @param string $text Text.
     * @return string
     */
    public static function lower(string $text): string {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /**
     * The words of a text, lowercased, in any script.
     *
     * str_word_count() knows only a-z, so "éducation" came back as "ducation"
     * and "à" vanished. Letters and digits of any script stay together; an
     * apostrophe inside a word stays ("don't"), and a French elision is cut
     * off ("l'école" is the word "école"). A token without a letter is not a
     * word.
     *
     * @param string $text Text.
     * @return string[]
     */
    public static function words(string $text): array {
        $tokens = preg_split('/[^\p{L}\p{N}\'’-]+/u', self::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words  = [];

        foreach ($tokens as $token) {
            $token = trim($token, "'’-");
            $token = (string) preg_replace('/^(?:l|d|j|n|s|c|m|t|qu)[\'’](?=\p{L})/u', '', $token);

            if ('' !== $token && preg_match('/\p{L}/u', $token)) {
                $words[] = $token;
            }
        }

        return $words;
    }

    /**
     * Approximate syllable count of a text, by the rules of its language.
     *
     * English: a-z letters only, each vowel group is a syllable, a trailing
     * silent e after a consonant is not ("make", "time"), consonant-le is
     * ("table"). Without the silent-e rule the counter inflated syllables per
     * word by ~0.2-0.3 and drove raw Flesch negative (#407).
     *
     * French: letters of any accent, each vowel group (accented vowels
     * included) is a syllable, and a final e or es after a consonant is mute
     * ("porte", "portes", "école"), as in speech, which is what Kandel and
     * Moles measured.
     *
     * The editor's estimateSyllables() in contentAnalysis.js applies the same
     * rules, so the live label and the stored score agree.
     *
     * @param string $text   Reading text (no tags).
     * @param string $locale A WordPress locale, or '' for the site's.
     * @return int
     */
    public static function count_syllables(string $text, string $locale = ''): int {
        $code   = self::code($locale);
        $vowels = self::VOWELS[$code] ?? self::VOWELS['en'];
        $french = 'fr' === $code;

        $words     = preg_split('/\s+/', trim(self::lower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $syllables = 0;

        foreach ($words as $word) {
            $word = $french
                ? (string) preg_replace('/[^\p{L}]/u', '', $word)
                : (string) preg_replace('/[^a-z]/', '', $word);
            if ('' === $word) {
                continue;
            }

            $groups = (int) preg_match_all('/[' . $vowels . ']+/u', $word);

            if ($french) {
                if ($groups > 1 && preg_match('/[^' . $vowels . ']es?$/u', $word)) {
                    $groups--;
                }
            } elseif ($groups > 1 && preg_match('/[^aeiouy]e$/', $word) && 'le' !== substr($word, -2)) {
                $groups--;
            }

            $syllables += max(1, $groups);
        }

        return $syllables;
    }

    /**
     * Reading ease of a text from its counts, on the 0-100 scale of its language.
     *
     * English, and any language without a formula of its own: Flesch Reading
     * Ease. French: Kandel and Moles' adaptation of it (1958), which weighs
     * syllables less because French words carry more of them.
     *
     * Not clamped here: the caller clamps to 0-100 after the filter.
     *
     * @param int    $words     Word count.
     * @param int    $sentences Sentence count.
     * @param int    $syllables Syllable count.
     * @param string $locale    A WordPress locale, or '' for the site's.
     * @return float
     */
    public static function reading_ease(int $words, int $sentences, int $syllables, string $locale = ''): float {
        if ($words <= 0 || $sentences <= 0) {
            return 0.0;
        }

        $code = self::code($locale);

        if ('fr' === $code) {
            $score = 207 - (1.015 * ($words / $sentences)) - (73.6 * ($syllables / $words));
        } else {
            $score = 206.835 - (1.015 * ($words / $sentences)) - (84.6 * ($syllables / $words));
        }

        /**
         * Filter a text's reading-ease score before it is clamped to 0-100.
         *
         * For a language whose formula differs from Flesch (English and the
         * default) and Kandel-Moles (French).
         *
         * @since 2.15.0
         *
         * @param float  $score  The score.
         * @param string $locale The text's locale, e.g. "de_DE".
         * @param array  $counts {words, sentences, syllables}.
         */
        return (float) apply_filters('thinkrank_readability_score', $score, $locale, [
            'words'     => $words,
            'sentences' => $sentences,
            'syllables' => $syllables,
        ]);
    }

    /**
     * The power words the title check rewards, in the title's language.
     *
     * @param string $locale A WordPress locale, or '' for the site's.
     * @return string[] Lowercase.
     */
    public static function title_power_words(string $locale = ''): array {
        $words = self::POWER_WORDS[self::code($locale)] ?? self::POWER_WORDS['en'];

        /**
         * Filter the power words the title check rewards.
         *
         * @since 2.15.0
         *
         * @param string[] $words  Lowercase words.
         * @param string   $locale The title's locale, e.g. "fr_FR".
         */
        return self::lowercase_list(apply_filters('thinkrank_title_power_words', $words, $locale));
    }

    /**
     * The sentiment words the title check rewards, in the title's language.
     *
     * @param string $locale A WordPress locale, or '' for the site's.
     * @return string[] Lowercase.
     */
    public static function title_sentiment_words(string $locale = ''): array {
        $words = self::SENTIMENT_WORDS[self::code($locale)] ?? self::SENTIMENT_WORDS['en'];

        /**
         * Filter the emotional/sentiment words the title check rewards.
         *
         * @since 2.15.0
         *
         * @param string[] $words  Lowercase words.
         * @param string   $locale The title's locale, e.g. "fr_FR".
         */
        return self::lowercase_list(apply_filters('thinkrank_title_sentiment_words', $words, $locale));
    }

    /**
     * The stop words left out of keyword extraction, in the text's language.
     *
     * A language without a list gets none, so its real words are kept rather
     * than having English function words removed from them.
     *
     * @param string $locale A WordPress locale, or '' for the site's.
     * @return string[] Lowercase.
     */
    public static function stop_words(string $locale = ''): array {
        $words = self::STOP_WORDS[self::code($locale)] ?? [];

        /**
         * Filter the stop words left out of keyword extraction.
         *
         * @since 2.15.0
         *
         * @param string[] $words  Lowercase words; empty for a language without a list.
         * @param string   $locale The text's locale, e.g. "de_DE".
         */
        return self::lowercase_list(apply_filters('thinkrank_stop_words', $words, $locale));
    }

    /**
     * A filtered list as lowercase non-empty strings.
     *
     * @param mixed $words Whatever the filter returned.
     * @return string[]
     */
    private static function lowercase_list($words): array {
        if (!is_array($words)) {
            return [];
        }

        $clean = [];
        foreach ($words as $word) {
            if (is_string($word) && '' !== trim($word)) {
                $clean[] = self::lower(trim($word));
            }
        }

        return array_values(array_unique($clean));
    }
}
