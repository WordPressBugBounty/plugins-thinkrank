<?php
/**
 * Page-builder content extraction.
 *
 * SEO analysis reads `post_content`, which is only the real text on a classic
 * post. Page builders keep the words somewhere else, and every server-side
 * scoring path — bulk analysis, the post-list SEO Overview column, the MCP
 * abilities, cron reports — saw an empty page as a result:
 *
 *  - Oxygen / Breakdance leave `post_content` completely EMPTY and store the
 *    node tree in postmeta. Nothing to render, nothing to strip: the analyzer
 *    reported "No content" on pages with well over a thousand visible words.
 *  - Elementor does the same via `_elementor_data`.
 *  - Divi 5 and Gutenberg do store block markup in `post_content`, but Divi
 *    keeps module text inside the block's JSON attributes — inside an HTML
 *    comment, which tag stripping removes wholesale.
 *  - Divi 4 and other shortcode builders keep text in shortcode attributes.
 *
 * Extraction reads the builder's own stored data rather than invoking its
 * render engine. Rendering an Oxygen page outside a front-end request is slow,
 * stateful and can fatal in an admin context, whereas the stored tree is just
 * JSON — cheap, side-effect free and safe to touch during a bulk run.
 *
 * @package ThinkRank\SEO
 * @since 1.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the analyzable content of a post, whatever built it.
 */
class Builder_Content {

    /**
     * Post meta keys that hold builder data, in priority order.
     *
     * Several generations of the same builder are listed on purpose: Oxygen 6
     * is Breakdance under the hood (`_breakdance_data`), while earlier Oxygen
     * releases used `_oxygen_data` or the shortcode-based
     * `ct_builder_shortcodes`. A site can only have one of them.
     *
     * @var string[]
     */
    private const BUILDER_META_KEYS = [
        '_breakdance_data',        // Oxygen 6+ / Breakdance
        '_oxygen_data',            // Oxygen (earlier releases)
        'ct_builder_shortcodes',   // Oxygen classic
        '_elementor_data',         // Elementor
    ];

    /**
     * JSON keys whose values are user-visible text.
     *
     * Builder trees mix content with configuration, so a blind string sweep
     * would count CSS classes and option slugs as words. Matching on the key
     * keeps the word count honest.
     *
     * @var string[]
     */
    private const CONTENT_KEYS = [
        'text', 'title', 'subtitle', 'heading', 'subheading', 'content',
        'description', 'caption', 'excerpt', 'label', 'value', 'html',
        'editor', 'quote', 'answer', 'question', 'body', 'button_text',
    ];

    /**
     * Resolve the content worth analyzing for a post.
     *
     * @param \WP_Post $post Post being analyzed.
     * @return string HTML/text to analyze.
     */
    public static function resolve(\WP_Post $post): string {
        return self::resolve_markup((string) $post->post_content, $post);
    }

    /**
     * Resolve an arbitrary chunk of editor markup for the given post.
     *
     * The editor sends its live content to the scorer so an author sees their
     * unsaved edits reflected. On a builder page that live string is the raw
     * builder markup — the block editor hands over Divi's
     * `<!-- wp:divi/... -->` comments verbatim, because it cannot render
     * blocks it has no client-side registration for. Analyzed as-is it reads
     * as zero words, which is how a Divi page could show a correct saved score
     * while the live Content Analysis panel next to it still said
     * "No content".
     *
     * Running the live string through the same chain as stored content keeps
     * both paths honest, and falling through to the post's builder storage
     * covers builders (Oxygen) whose editor content is empty to begin with.
     *
     * @since 1.23.0
     *
     * @param string   $raw  Markup to analyze.
     * @param \WP_Post $post Post the markup belongs to.
     * @return string Content to analyze.
     */
    public static function resolve_markup(string $raw, \WP_Post $post): string {
        $content = self::render_post_content($raw);

        // Block markup that renders to nothing usually means the builder that
        // owns those blocks did not register them in this context — Divi 5
        // loads its module library lazily per-request, so in CLI, REST, admin
        // and block-editor requests do_blocks() yields an empty string while
        // the words sit right there in the block attributes. Read them
        // directly.
        if (self::is_blank($content)) {
            $from_blocks = self::from_block_attributes($raw);
            if (!self::is_blank($from_blocks)) {
                $content = $from_blocks;
            }
        }

        // Only reach for builder storage when the markup yielded nothing — a
        // classic post must never pay for this.
        if (self::is_blank($content)) {
            $builder = self::from_builder_meta((int) $post->ID);
            if (!self::is_blank($builder)) {
                $content = $builder;
            }
        }

        // A resolution that collapsed to nothing is worse than the raw markup.
        if (self::is_blank($content) && !self::is_blank($raw)) {
            $content = $raw;
        }

        /**
         * Filter the content ThinkRank analyzes for a post.
         *
         * Use this to teach ThinkRank about a builder it does not know, or to
         * override extraction for one it does.
         *
         * @since 1.23.0
         *
         * @param string   $content Resolved content.
         * @param \WP_Post $post    Post being analyzed.
         * @param string   $raw     Markup this resolution started from.
         */
        return (string) apply_filters('thinkrank_analyzable_content', $content, $post, $raw);
    }

    /**
     * Render blocks and shortcodes found in post_content.
     *
     * Best-effort: a third-party block that fatals must not take the whole
     * score down with it.
     *
     * @param string $raw Raw post content.
     * @return string Rendered content.
     */
    private static function render_post_content(string $raw): string {
        if ('' === trim($raw)) {
            return '';
        }

        $content = $raw;

        try {
            if (function_exists('has_blocks') && function_exists('do_blocks') && has_blocks($raw)) {
                $content = do_blocks($raw);
            }

            // Block output can itself contain shortcodes, so this runs either way.
            if (function_exists('do_shortcode') && strpos($content, '[') !== false) {
                $content = do_shortcode($content);
            }
        } catch (\Throwable $e) {
            return $raw;
        }

        return self::is_blank($content) ? $raw : $content;
    }

    /**
     * Extract text from the attributes of parsed blocks.
     *
     * @param string $raw Raw post content containing block markup.
     * @return string Collected text, or '' when nothing was found.
     */
    private static function from_block_attributes(string $raw): string {
        if (!function_exists('parse_blocks') || !function_exists('has_blocks') || !has_blocks($raw)) {
            return '';
        }

        try {
            $blocks = parse_blocks($raw);
        } catch (\Throwable $e) {
            return '';
        }

        $attrs = [];
        $collect = static function (array $list) use (&$collect, &$attrs): void {
            foreach ($list as $block) {
                if (!empty($block['attrs']) && is_array($block['attrs'])) {
                    $attrs[] = $block['attrs'];
                }
                if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                    $collect($block['innerBlocks']);
                }
            }
        };
        $collect($blocks);

        return empty($attrs) ? '' : self::text_from_tree($attrs);
    }

    /**
     * Pull text out of whichever builder stored this post.
     *
     * @param int $post_id Post ID.
     * @return string Extracted text, or '' when no builder data was found.
     */
    private static function from_builder_meta(int $post_id): string {
        foreach (self::BUILDER_META_KEYS as $key) {
            $stored = get_post_meta($post_id, $key, true);

            if (is_string($stored) && '' !== trim($stored)) {
                $decoded = json_decode($stored, true);

                // JSON node tree (Breakdance/Oxygen 6, Elementor).
                if (is_array($decoded)) {
                    $text = self::text_from_tree($decoded);
                    if (!self::is_blank($text)) {
                        return $text;
                    }
                    continue;
                }

                // Shortcode tree (Oxygen classic).
                if (strpos($stored, '[') !== false && function_exists('do_shortcode')) {
                    try {
                        $rendered = do_shortcode($stored);
                    } catch (\Throwable $e) {
                        $rendered = $stored;
                    }
                    if (!self::is_blank($rendered)) {
                        return $rendered;
                    }
                }

                continue;
            }

            // Some builders store an already-decoded array.
            if (is_array($stored)) {
                $text = self::text_from_tree($stored);
                if (!self::is_blank($text)) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Walk a builder node tree and collect the user-visible text.
     *
     * Values are joined with block-level markup so downstream heading, link and
     * image detection keeps working on the result.
     *
     * @param array $tree Decoded builder tree.
     * @return string Collected HTML.
     */
    private static function text_from_tree(array $tree): string {
        $collected = [];

        $walk = static function ($node, $key = null) use (&$walk, &$collected): void {
            if (is_array($node)) {
                foreach ($node as $child_key => $child) {
                    $walk($child, is_string($child_key) ? $child_key : $key);
                }
                return;
            }

            if (!is_string($node) || '' === trim($node)) {
                return;
            }

            $is_content_key = is_string($key)
                && in_array(strtolower($key), self::CONTENT_KEYS, true);

            // Markup is content wherever it appears; bare strings only count
            // when their key says they are content, so slugs and class names
            // stay out of the word count.
            if ($is_content_key || strpos($node, '<') !== false) {
                $collected[] = $node;
            }
        };

        $walk($tree);

        if (empty($collected)) {
            return '';
        }

        // De-duplicate: builder trees often repeat a value across responsive
        // breakpoints, which would otherwise multiply the word count.
        $collected = array_unique($collected);

        return implode("\n", $collected);
    }

    /**
     * Whether a value carries no readable text.
     *
     * @param string $value Candidate content.
     * @return bool
     */
    private static function is_blank(string $value): bool {
        return '' === trim(wp_strip_all_tags($value));
    }
}
