<?php

/**
 * Rank Math FAQ / HowTo block compatibility.
 *
 * Rank Math's FAQ and HowTo blocks keep their questions and steps in the block
 * comment's attributes and generate their schema in PHP. Deactivating Rank Math
 * therefore takes the FAQPage / HowTo JSON-LD with it while leaving the block's
 * saved HTML in `post_content` — the page still shows the questions, and search
 * engines stop seeing them (#777).
 *
 * This class is the single place that knows how to read those attributes. It
 * serves two callers:
 *
 * - Block_Converter, which rewrites the blocks into ThinkRank's own FAQ / HowTo
 *   blocks as a migration step.
 * - Blocks_Manager and Schema_Graph, which use it as a *fallback* so a site that
 *   has not run (or cannot run) the migration still publishes the schema.
 *
 * The fallback deliberately does nothing while Rank Math is active: Rank Math is
 * still emitting its own FAQPage / HowTo for these blocks, and a second copy
 * from us would be a duplicate on the same URL.
 *
 * @package ThinkRank\Integrations
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rank Math Blocks Class
 *
 * @since 2.10.0
 */
class Rank_Math_Blocks {

    /**
     * Rank Math's FAQ block name.
     */
    public const FAQ_BLOCK = 'rank-math/faq-block';

    /**
     * Rank Math's HowTo block name.
     */
    public const HOWTO_BLOCK = 'rank-math/howto-block';

    /**
     * Rank Math block name => the ThinkRank block it converts to.
     *
     * @var array<string,string>
     */
    public const BLOCK_MAP = [
        self::FAQ_BLOCK   => 'thinkrank/faq',
        self::HOWTO_BLOCK => 'thinkrank/howto',
    ];

    /**
     * Rank Math main plugin files (free and Pro).
     *
     * @var string[]
     */
    private const PLUGIN_FILES = [
        'seo-by-rank-math/rank-math.php',
        'seo-by-rank-math-pro/rank-math-pro.php',
    ];

    /**
     * Whether Rank Math is currently active, in which case it still emits its
     * own schema for these blocks and ThinkRank must not add a second copy.
     *
     * @return bool
     */
    public static function is_source_active(): bool {
        if (!function_exists('is_plugin_active')) {
            if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                return false;
            }
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (self::PLUGIN_FILES as $file) {
            if (is_plugin_active($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a block name is one of Rank Math's convertible blocks.
     *
     * @param string $block_name Parsed block name.
     * @return bool
     */
    public static function is_source_block(string $block_name): bool {
        return isset(self::BLOCK_MAP[$block_name]);
    }

    /**
     * Translate a Rank Math block's attributes into the ThinkRank block's
     * attribute shape.
     *
     * @param string              $block_name Rank Math block name.
     * @param array<string,mixed> $attrs      Rank Math block attributes.
     * @return array{name:string,attrs:array<string,mixed>}|null Null when the
     *         block is not ours, or when it carries nothing worth converting.
     */
    public static function map_block(string $block_name, array $attrs): ?array {
        if (!self::is_source_block($block_name)) {
            return null;
        }

        $mapped = self::FAQ_BLOCK === $block_name
            ? self::faq_attributes($attrs)
            : self::howto_attributes($attrs);

        if (null === $mapped) {
            return null;
        }

        return [
            'name'  => self::BLOCK_MAP[$block_name],
            'attrs' => $mapped,
        ];
    }

    /**
     * The attribute payload the schema producers should treat this leftover
     * Rank Math block as, or null when it must stay silent.
     *
     * Returns null while Rank Math is active, so the fallback never doubles up
     * on schema Rank Math is already publishing.
     *
     * @param string              $block_name Rank Math block name.
     * @param array<string,mixed> $attrs      Rank Math block attributes.
     * @return array{name:string,attrs:array<string,mixed>}|null
     */
    public static function schema_fallback(string $block_name, array $attrs): ?array {
        if (self::is_source_active()) {
            return null;
        }

        return self::map_block($block_name, $attrs);
    }

    /**
     * Map Rank Math FAQ attributes to `thinkrank/faq` attributes.
     *
     * Only questions Rank Math actually rendered are carried over. Its save
     * output skips an item when `visible === false` or when either the title or
     * the content is empty, so those were never on the page and must not appear
     * in ours — carrying them would publish text the author had hidden.
     *
     * Note that Rank Math's *schema* is stricter still: it skips any falsy
     * `visible`, including a missing one, so a hand-built or imported block with
     * no `visible` key renders but produces no FAQPage entry there. We follow
     * the rendered semantics, because the visible page is what the author sees
     * and what the schema is supposed to describe.
     *
     * @param array<string,mixed> $attrs Rank Math FAQ attributes.
     * @return array<string,mixed>|null Null when no question survives.
     */
    public static function faq_attributes(array $attrs): ?array {
        $questions = isset($attrs['questions']) && is_array($attrs['questions'])
            ? $attrs['questions']
            : [];

        $faqs = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            if (array_key_exists('visible', $question) && false === $question['visible']) {
                continue;
            }

            $title   = isset($question['title']) ? (string) $question['title'] : '';
            $content = isset($question['content']) ? (string) $question['content'] : '';

            if ('' === $title || '' === $content) {
                continue;
            }

            $faqs[] = array_merge(
                [
                    'question' => $title,
                    'answer'   => $content,
                ],
                self::image_attributes($question['imageID'] ?? 0, ['imageId', 'imageUrl', 'imageAlt'])
            );
        }

        if (empty($faqs)) {
            return null;
        }

        $mapped = ['faqs' => $faqs];

        // Rank Math's default question wrapper is h3; ThinkRank's default
        // heading tag is h2 and applies to the block's own heading, not to each
        // question, so the wrapper is intentionally not carried over. The block
        // has no heading of its own to set.
        return $mapped;
    }

    /**
     * Map Rank Math HowTo attributes to `thinkrank/howto` attributes.
     *
     * Rank Math's save output skips a step only when `visible === false`, and
     * keeps steps that have just a title or just a body, so the filter here is
     * looser than the FAQ's.
     *
     * @param array<string,mixed> $attrs Rank Math HowTo attributes.
     * @return array<string,mixed>|null Null when no step survives.
     */
    public static function howto_attributes(array $attrs): ?array {
        $source_steps = isset($attrs['steps']) && is_array($attrs['steps'])
            ? $attrs['steps']
            : [];

        $steps = [];
        foreach ($source_steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            if (array_key_exists('visible', $step) && false === $step['visible']) {
                continue;
            }

            $title   = isset($step['title']) ? (string) $step['title'] : '';
            $content = isset($step['content']) ? (string) $step['content'] : '';
            $image   = self::image_attributes($step['imageID'] ?? 0, ['imageId', 'imageUrl', 'imageAlt']);

            if ('' === $title && '' === $content && 0 === $image['imageId']) {
                continue;
            }

            $steps[] = array_merge(
                [
                    'title' => $title,
                    'text'  => $content,
                ],
                $image
            );
        }

        if (empty($steps)) {
            return null;
        }

        // Key order follows the block's own attribute declaration in
        // src/blocks/howto-block/index.js (description, steps, then the
        // totals). The editor re-serializes a block comment in declaration
        // order, so building it in any other order leaves a post that is valid
        // but gets its block comment rewritten the first time someone opens and
        // saves it — a diff with no change in it.
        $mapped = [];

        $description = isset($attrs['description']) ? (string) $attrs['description'] : '';
        if ('' !== $description) {
            $mapped['description'] = $description;
        }

        $mapped['steps'] = $steps;

        // Rank Math stores the duration as three strings and only honours them
        // when hasDuration is on; ThinkRank stores three numbers and derives
        // "is there a duration" from them being non-zero.
        //
        // A negative value is clamped to 0, not made positive: absint() turned
        // "-2" into 2 days while the editor-side mapper (rank-math-mapping.js)
        // kept -2, so the same block converted two different ways depending on
        // which path reached it. duration_part() and the JS clamp must agree.
        if (!empty($attrs['hasDuration'])) {
            $mapped['totalDays']    = self::duration_part($attrs['days'] ?? 0);
            $mapped['totalHours']   = self::duration_part($attrs['hours'] ?? 0);
            $mapped['totalMinutes'] = self::duration_part($attrs['minutes'] ?? 0);
        }

        return $mapped;
    }

    /**
     * One HowTo duration field as a non-negative whole number.
     *
     * Mirrors durationPart() in src/editor/rank-math-mapping.js, which is
     * `Math.max(0, parseInt(value, 10) || 0)`: the leading integer of the
     * string, anything unreadable is 0, negatives are 0. A plain (int) cast
     * is not quite parseInt(): it reads "1e3" as 1000 and `true` as 1.
     *
     * @since 2.10.0
     *
     * @param mixed $value Rank Math duration string (or number).
     * @return int
     */
    public static function duration_part($value): int {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) $value);
        }

        if (!is_string($value) || !preg_match('/^\s*([+-]?\d+)/', $value, $m)) {
            return 0;
        }

        return max(0, (int) $m[1]);
    }

    /**
     * The HowTo block's own lead image, which ThinkRank's HowTo block has no
     * field for. Block_Converter emits it as a `core/image` block above the
     * steps rather than dropping it.
     *
     * @param array<string,mixed> $attrs Rank Math HowTo attributes.
     * @return array{id:int,url:string,alt:string,width:int,height:int}|null
     */
    public static function howto_main_image(array $attrs): ?array {
        $id = isset($attrs['imageID']) ? absint($attrs['imageID']) : 0;
        if ($id < 1) {
            return null;
        }

        return self::attachment_details($id);
    }

    /**
     * Resolve a Rank Math `imageID` into ThinkRank's id/url/alt attribute trio.
     *
     * An id naming an attachment that no longer exists resolves to "no image"
     * rather than a dead URL, matching how the FAQ block already degrades (#418).
     *
     * @param mixed    $image_id Raw Rank Math imageID.
     * @param string[] $keys     Attribute names for [id, url, alt].
     * @return array<string,mixed>
     */
    private static function image_attributes($image_id, array $keys): array {
        [$id_key, $url_key, $alt_key] = $keys;

        $empty = [$id_key => 0, $url_key => '', $alt_key => ''];

        $id = absint($image_id);
        if ($id < 1) {
            return $empty;
        }

        $details = self::attachment_details($id);
        if (null === $details) {
            return $empty;
        }

        return [
            $id_key  => $details['id'],
            $url_key => $details['url'],
            $alt_key => $details['alt'],
        ];
    }

    /**
     * Attachment url/alt/dimensions, or null when the attachment is gone.
     *
     * @param int $id Attachment id.
     * @return array{id:int,url:string,alt:string,width:int,height:int}|null
     */
    private static function attachment_details(int $id): ?array {
        if (!function_exists('wp_get_attachment_image_src')) {
            return null;
        }

        $src = wp_get_attachment_image_src($id, 'full');
        if (!is_array($src) || empty($src[0])) {
            return null;
        }

        $alt = function_exists('get_post_meta')
            ? (string) get_post_meta($id, '_wp_attachment_image_alt', true)
            : '';

        return [
            'id'     => $id,
            'url'    => (string) $src[0],
            'alt'    => $alt,
            'width'  => isset($src[1]) ? (int) $src[1] : 0,
            'height' => isset($src[2]) ? (int) $src[2] : 0,
        ];
    }
}
