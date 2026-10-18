<?php

/**
 * Alt text quality check
 *
 * Tells a description apart from filler a template produced out of a file
 * name: "1", "3-4", "image", "IMG 1234", "jioqIZVKAg".
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
 * Alt text quality check.
 *
 * The template fill wrote whatever `alt_format` resolved to, so a library
 * whose files were named `1.jpg`, `3-4.png` or `image.jpg` got exactly those
 * words as alt text, and the analyzer then counted them as covered (#1124).
 * A screen reader announces "one" or "three four", which is worse than the
 * empty alt it replaced.
 *
 * @since 2.17.0
 */
final class Alt_Text_Quality {

    /**
     * Whole values that describe nothing.
     *
     * Also the list the SQL coverage count excludes, so keep it to words a
     * person would never pick as a full description.
     *
     * @since 2.17.0
     * @var string[]
     */
    public const STOCK_VALUES = [
        'image', 'images', 'img', 'photo', 'photos', 'picture', 'pictures',
        'pic', 'pics', 'screenshot', 'screen shot', 'untitled', 'placeholder',
        'unnamed', 'default', 'thumbnail', 'thumb', 'file', 'download',
        'attachment', 'graphic', 'icon', 'banner', 'featured image',
        'no image', 'noimage',
    ];

    /**
     * Words that only ever come from cameras, phones, screenshot tools, stock
     * sites and the upload pipeline. A file name made of these and numbers
     * names the device or the source, not the picture.
     *
     * @since 2.17.0
     * @var string[]
     */
    private const GENERATED_WORDS = [
        'img', 'image', 'images', 'photo', 'photos', 'picture', 'pic', 'pics',
        'screenshot', 'screen', 'shot', 'untitled', 'placeholder', 'unnamed',
        'default', 'thumbnail', 'thumb', 'file', 'download', 'attachment',
        'copy', 'scaled', 'edited', 'cropped', 'rotated', 'final', 'new',
        'dsc', 'dscn', 'dscf', 'dcim', 'pxl', 'mvimg', 'vid', 'gopr', 'cimg',
        'capture', 'snapshot', 'scan', 'screely', 'cleanshot', 'whatsapp',
        'pexels', 'unsplash', 'pixabay', 'freepik', 'shutterstock', 'istock',
        'istockphoto', 'gettyimages', 'adobestock', 'depositphotos', 'canva',
        'at', 'pm', 'am', 'and', 'of', 'the',
        // Localized screenshot names that are one word, and the "at" of their
        // timestamps: "Bildschirmfoto 2024-01-01 um 10.00.00",
        // "Schermata 2024-01-01 alle 10.00.00" (#1211).
        'bildschirmfoto', 'um', 'alle', 'schermata', 'schermafbeelding', 'skärmbild',
        'skjermbilde', 'skærmbillede', 'näyttökuva', 'snímek', 'スクリーンショット',
        '스크린샷', '截图', '截屏', '屏幕截图', '螢幕擷取畫面',
        // More macOS screenshot names (#1250): "Skärmavbild" (sv),
        // "Snímek obrazovky" (cs). "Captura de ecrã" (pt-PT) is a phrase below.
        'skärmavbild', 'obrazovky', 'ecrã',
        // Localized "Untitled", as design and photo apps name a new file:
        // "Unbenannt-1", "Naamloos-1", "名称未設定-1" (#1250).
        'unbenannt', 'naamloos', 'namnlös', 'nimetön', '無題', '名称未設定', '未命名',
        // Sample-file and format names: "file_example_JPG_100kB".
        'example', 'sample', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'svg',
    ];

    /**
     * Phrases that only ever come from a tool's default file name. Removed
     * before the words are looked at, since each holds a word that means
     * something on its own ("design", "pantalla").
     *
     * @since 2.17.0
     * @var string[]
     */
    private const GENERATED_PHRASES = [
        'untitled design', 'captura de pantalla', 'captura de tela', 'captura de ecrã',
        "capture d'écran", "capture d’écran", 'capture d ecran', 'zrzut ekranu',
        'ekran görüntüsü', 'снимок экрана', 'скриншот', 'file example',
        // Localized "Untitled" (#1250): "Sin título-1", "Sans titre-1".
        'sin título', 'sin titulo', 'sans titre', 'senza titolo', 'sem título',
        'sem titulo', 'bez názvu', 'bez tytułu', 'zonder titel', 'без названия',
        '제목 없음',
        // AI image generators' download names (#1250):
        // "Gemini_Generated_Image_k3j2h1g0f9e8", "DALL·E 2024-01-01 10.00.00".
        'gemini generated image', 'dall·e', 'dall e', 'dalle',
    ];

    /**
     * Patterns that only ever come from a tool's default file name, removed
     * with the phrases. Kept to whole shapes, a word followed by the time it
     * introduces, so "om", "kl" or "las" elsewhere in a description stay.
     *
     * @since 2.17.0
     * @var string[]
     */
    private const GENERATED_PATTERNS = [
        // The "at" of a macOS screenshot timestamp, with the time after it, the
        // value's dots already spaces: "kl 10 00 00" (da/no/sv), "om" (nl),
        // "kello" (fi), "a las" (es), "às" (pt), "오전"/"오후" (ko) (#1250).
        '(?:at|um|alle|kl|klo|kello|om|a\s+las|às|ve|в|오전|오후)\s+\d{1,2}(?:\s+\d{2}){1,2}',
        // ChatGPT's download name: "ChatGPT Image Jan 1, 2024, 10_00_00 AM".
        'chatgpt\s+image\s+\p{L}{3,9}\s+\d{1,2},?\s+\d{4},?',
    ];

    /**
     * Whether a value reads as a description of an image.
     *
     * False for a value with no letters, a stock word, or one made only of
     * camera/upload words, numbers and hash-like tokens.
     *
     * @since 2.17.0
     * @param string $alt Candidate alt text.
     * @return bool
     */
    public static function is_descriptive(string $alt): bool {
        $text = trim((string) preg_replace('/[\s\-_.]+/u', ' ', $alt));

        if ('' === $text) {
            return false;
        }

        // No letter at all: "1", "3-4", "1 (1)", "2-20".
        if (1 !== preg_match('/\p{L}/u', $text)) {
            return false;
        }

        if (in_array(self::lower($text), self::STOCK_VALUES, true)) {
            return false;
        }

        $text = self::without_generated_phrases($text);

        foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
            $token = trim($token, " \t\n\r\0\x0B()[]{},;:!?'\"");

            if (self::is_meaningful_word($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQL condition true when a stored alt value might count as covered.
     *
     * The cheap subset of is_descriptive() that narrows the rows before PHP
     * makes the final call (Alt_Text_Coverage): values with no letter in them
     * ("1", "3-4") and the stock values. It must never reject a value
     * is_descriptive() accepts. Anything that is not an ASCII digit,
     * punctuation or space passes the pattern, so alt text in any script
     * still gets through to the PHP check.
     *
     * @since 2.17.0
     * @param string $column Column holding the alt text, already qualified (`pm.meta_value`).
     * @return string
     */
    public static function sql_descriptive_condition(string $column): string {
        $stock = implode(
            ', ',
            array_map(
                static function (string $value): string {
                    return "'" . $value . "'";
                },
                self::STOCK_VALUES
            )
        );

        return "{$column} != ''"
            . " AND {$column} NOT REGEXP '^[[:digit:][:punct:][:space:]]+$'"
            . " AND LOWER(TRIM({$column})) NOT IN ({$stock})";
    }

    /**
     * Whether one token carries meaning of its own.
     *
     * @param string $token A single word of the candidate.
     * @return bool
     */
    private static function is_meaningful_word(string $token): bool {
        // Two letters at least: "a", "x" and a lone digit-letter pair say
        // nothing. A Han character or a Hangul syllable is a word by itself,
        // so "猫" (cat) is a description (#1211).
        if (preg_match_all('/\p{L}/u', $token) < 2 && 1 !== preg_match('/[\p{Han}\p{Hangul}]/u', $token)) {
            return false;
        }

        if (in_array(self::lower($token), self::GENERATED_WORDS, true)) {
            return false;
        }

        return !self::looks_generated($token);
    }

    /**
     * Drop the default-file-name phrases and patterns from a value.
     *
     * @param string $text Value with separators already turned into spaces.
     * @return string
     */
    private static function without_generated_phrases(string $text): string {
        foreach (self::GENERATED_PHRASES as $phrase) {
            // The value's separators are already spaces; match the phrase the same way.
            $pattern = '/(?<![\p{L}\p{N}])' . str_replace(' ', '\s+', preg_quote($phrase, '/')) . '(?![\p{L}\p{N}])/iu';
            $text    = (string) preg_replace($pattern, ' ', $text);
        }

        foreach (self::GENERATED_PATTERNS as $shape) {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])' . $shape . '(?![\p{L}\p{N}])/iu', ' ', $text);
        }

        return trim($text);
    }

    /**
     * Whether a token looks machine-made: a hash, an id, a camera counter.
     *
     * @param string $token A single word.
     * @return bool
     */
    private static function looks_generated(string $token): bool {
        $length = function_exists('mb_strlen') ? mb_strlen($token) : strlen($token);

        // Hex hashes and upload ids: "a3f9c2e1", "5f1c0e8b9d".
        if ($length >= 8 && 1 === preg_match('/^[0-9a-f]+$/i', $token) && 1 === preg_match('/\d/', $token)) {
            return true;
        }

        // Letters and two or more digits run together: "IMG1234", "DSC01234", "a1b2c3".
        if ($length >= 6 && preg_match_all('/\d/', $token) >= 2) {
            return true;
        }

        // A size or resolution: "100kB", "2MB", "1080p", "4k".
        if (1 === preg_match('/^\d+(?:[kmg]i?b|px|p|k)$/i', $token)) {
            return true;
        }

        // Random-case ids: "jioqIZVKAg". A real word has at most one capital
        // after its first letter ("iPhone", "WordPress").
        $tail = function_exists('mb_substr') ? mb_substr($token, 1) : substr($token, 1);
        if ($length >= 8
            && 1 === preg_match('/\p{Ll}/u', $token)
            && preg_match_all('/\p{Lu}/u', (string) $tail) >= 2
        ) {
            return true;
        }

        return false;
    }

    /**
     * Lower-case a value, multibyte-safe where available.
     *
     * @param string $value Value.
     * @return string
     */
    private static function lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
