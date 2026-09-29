<?php

/**
 * Where a post's FAQ question/answer pairs actually live.
 *
 * Four surfaces can hold them — the `thinkrank/faq` block, and the Elementor
 * widget, Bricks element and Beaver module that mirror it — and each stores its
 * repeater in a different place, in a different shape, behind a differently
 * spelled schema toggle. `Schema_Graph` grew a walker per surface so it could
 * merge them into one FAQPage.
 *
 * Nothing else could reach them. Adding the `get-faq` / `update-faq` abilities
 * (#767) meant either a second copy of all four walkers, which is exactly the
 * drift the FAQ surfaces have already produced twice, or one reader both sides
 * share. This is that reader: `Schema_Graph` asks it where the pairs are and
 * turns them into Question entities, and the abilities ask it the same question
 * and report them to an agent.
 *
 * It answers what is *stored*, not what is *published*. The schema toggle is
 * reported rather than applied, because a block with schema switched off is
 * still visible FAQ content that a caller needs to know about; the password
 * gate is left to `Schema_Graph`, because an editor asking what is on a post is
 * not the same question as what a visitor may be shown.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.10.1
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads FAQ pairs out of every surface that can hold them.
 *
 * @since 2.10.1
 */
class FAQ_Content {

    /**
     * The Gutenberg block.
     *
     * @var string
     */
    public const SOURCE_BLOCK = 'block';

    /**
     * The Elementor widget.
     *
     * @var string
     */
    public const SOURCE_ELEMENTOR = 'elementor';

    /**
     * The Bricks element.
     *
     * @var string
     */
    public const SOURCE_BRICKS = 'bricks';

    /**
     * The Beaver Builder module.
     *
     * @var string
     */
    public const SOURCE_BEAVER = 'beaver';

    /**
     * Gutenberg FAQ block name.
     *
     * @var string
     */
    public const FAQ_BLOCK = 'thinkrank/faq';

    /**
     * Elementor FAQ widget name.
     *
     * @var string
     */
    public const FAQ_WIDGET = 'thinkrank-faq';

    /**
     * Bricks FAQ element name.
     *
     * @var string
     */
    public const FAQ_BRICKS_ELEMENT = 'thinkrank-faq';

    /**
     * The Beaver Builder FAQ module's slug, as stored in its layout nodes.
     *
     * Matches `ThinkRank_Beaver_FAQ_Module::SLUG`. Duplicated as a literal
     * rather than referenced, because that class extends `FLBuilderModule` and
     * so cannot be loaded at all when Beaver Builder is inactive — which is
     * exactly the site that still has a stored layout, after a builder switch.
     *
     * @var string
     */
    public const FAQ_BEAVER_MODULE = 'thinkrank-faq';

    /**
     * Every FAQ producer found on a post, in collection order.
     *
     * @since 2.10.1
     * @param \WP_Post $post Post to read.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    public static function groups(\WP_Post $post): array {
        $groups = [];

        // A Bricks page throws `post_content` away, so a FAQ block left there
        // when the page was switched over never renders. Reporting its
        // questions would describe content no visitor can see, which Google
        // treats as a violation rather than merely a duplicate (#650).
        self::load_builder_content();

        if (!Builder_Content::bricks_supersedes_post_content((int) $post->ID)) {
            $groups = array_merge($groups, self::block_groups($post));
        }

        $groups = array_merge($groups, self::elementor_groups($post));
        $groups = array_merge($groups, self::bricks_groups($post));
        $groups = array_merge($groups, self::beaver_groups($post));

        return $groups;
    }

    /**
     * Every question/answer pair on a post, flattened and normalised.
     *
     * Rows with no question or no answer are dropped: they are a half-filled
     * repeater row in the editor, not an FAQ entry, and reporting them as one
     * would have an agent "fixing" content the author is still writing.
     *
     * @since 2.10.1
     * @param \WP_Post $post Post to read.
     * @return array<int, array{question: string, answer: string, source: string, schema_enabled: bool, image_id: int, image_url: string, image_alt: string}>
     */
    public static function items(\WP_Post $post): array {
        $items = [];

        foreach (self::groups($post) as $group) {
            foreach (self::rows($group['pairs']) as $row) {
                $question = trim(wp_strip_all_tags((string) ($row['question'] ?? '')));
                $answer   = trim((string) ($row['answer'] ?? ''));

                if ('' === $question || '' === $answer) {
                    continue;
                }

                $items[] = [
                    'question'       => $question,
                    'answer'         => $answer,
                    'source'         => $group['source'],
                    'schema_enabled' => $group['schema'],
                    'image_id'       => (int) ($row['imageId'] ?? $row['image_id'] ?? 0),
                    'image_url'      => (string) ($row['imageUrl'] ?? $row['image_url'] ?? ''),
                    'image_alt'      => (string) ($row['imageAlt'] ?? $row['image_alt'] ?? ''),
                ];
            }
        }

        return $items;
    }

    /**
     * Which page builder renders this post, if any.
     *
     * Each test is the builder's own: Elementor stores `builder` in
     * `_elementor_edit_mode` for a page it owns, Beaver Builder flags
     * `_fl_builder_enabled`, and Bricks is asked through the resolver that
     * already knows when it supersedes `post_content`.
     *
     * @since 2.10.1
     * @param int $post_id Post ID.
     * @return string One of the SOURCE_* builder names, or '' for the block editor.
     */
    public static function builder(int $post_id): string {
        self::load_builder_content();

        if ('builder' === (string) get_post_meta($post_id, '_elementor_edit_mode', true)) {
            return self::SOURCE_ELEMENTOR;
        }

        if (Builder_Content::bricks_supersedes_post_content($post_id)) {
            return self::SOURCE_BRICKS;
        }

        if (!empty(get_post_meta($post_id, '_fl_builder_enabled', true))) {
            return self::SOURCE_BEAVER;
        }

        return '';
    }

    /**
     * Load Builder_Content, which resolves the Bricks half of the answer.
     *
     * Required rather than autoloaded for the same reason Schema_Graph used to
     * require it: this runs in contexts where the plugin autoloader is not
     * guaranteed to be registered.
     *
     * @return void
     */
    private static function load_builder_content(): void {
        if (class_exists('ThinkRank\\SEO\\Builder_Content')) {
            return;
        }

        $file = THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * FAQ blocks in a post's content, including nested ones.
     *
     * @param \WP_Post $post Post to read.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function block_groups(\WP_Post $post): array {
        if (!function_exists('parse_blocks') || !has_blocks($post->post_content)) {
            return [];
        }

        return self::walk_blocks(parse_blocks($post->post_content));
    }

    /**
     * Recurse a parsed block tree.
     *
     * @param array $blocks Parsed blocks.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function walk_blocks(array $blocks): array {
        $groups = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = (string) ($block['blockName'] ?? '');
            $attrs      = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            // A leftover Rank Math FAQ block is absorbed as if it were ours, so
            // an unmigrated post contributes its questions to the single
            // FAQPage rather than to nothing at all (#777). The fallback stays
            // silent while Rank Math is active and still emitting its own.
            if (\ThinkRank\Integrations\Rank_Math_Blocks::is_source_block($block_name)) {
                $fallback = \ThinkRank\Integrations\Rank_Math_Blocks::schema_fallback($block_name, $attrs);
                if (null !== $fallback) {
                    $block_name = $fallback['name'];
                    $attrs      = $fallback['attrs'];
                }
            }

            if ($block_name === self::FAQ_BLOCK) {
                $groups[] = [
                    'source' => self::SOURCE_BLOCK,
                    // Mirrors Blocks_Manager: schema is on unless explicitly disabled.
                    'schema' => !(array_key_exists('outputSchema', $attrs) && false === $attrs['outputSchema']),
                    'pairs'  => self::rows($attrs['faqs'] ?? []),
                ];
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $groups = array_merge($groups, self::walk_blocks($block['innerBlocks']));
            }
        }

        return $groups;
    }

    /**
     * FAQ widgets in a post's Elementor tree.
     *
     * @param \WP_Post $post Post to read.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function elementor_groups(\WP_Post $post): array {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (empty($raw) || !is_string($raw)) {
            return [];
        }

        $elements = json_decode($raw, true);
        if (!is_array($elements)) {
            return [];
        }

        return self::walk_elementor($elements);
    }

    /**
     * Recurse an Elementor element tree.
     *
     * @param array $elements Elementor elements.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function walk_elementor(array $elements): array {
        $groups = [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (($element['widgetType'] ?? '') === self::FAQ_WIDGET) {
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

                $groups[] = [
                    'source' => self::SOURCE_ELEMENTOR,
                    // Mirrors FAQ_Widget: schema unless the toggle is off.
                    'schema' => 'yes' === ($settings['output_schema'] ?? 'yes'),
                    'pairs'  => self::rows($settings['faqs'] ?? []),
                ];
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $groups = array_merge($groups, self::walk_elementor($element['elements']));
            }
        }

        return $groups;
    }

    /**
     * FAQ elements in a post's Bricks tree.
     *
     * Reads the tree Bricks will actually render — resolved through
     * `Builder_Content`, so a page whose content lives on a content template or
     * inside a component is covered, and one switched back to the block editor
     * is not.
     *
     * Unlike the block, this is not gated on Bricks owning `post_content`: a
     * Bricks element is on the page whenever Bricks renders the page, which is
     * exactly what resolving the tree already establishes (#626).
     *
     * The element's own settings are read here rather than through
     * `FAQ_Element`, whose class extends `Bricks\Element` and so cannot even be
     * loaded when the theme is inactive — which is exactly the case that still
     * has a stored tree, on a site that has since switched themes.
     *
     * The tree is flat, so no recursion: `Builder_Content::bricks_tree()`
     * splices component definitions into the same list.
     *
     * @param \WP_Post $post Post to read.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function bricks_groups(\WP_Post $post): array {
        $groups = [];

        foreach (Builder_Content::bricks_tree((int) $post->ID) as $element) {
            if (!is_array($element) || ($element['name'] ?? '') !== self::FAQ_BRICKS_ELEMENT) {
                continue;
            }

            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

            $groups[] = [
                'source' => self::SOURCE_BRICKS,
                // Mirrors FAQ_Element: a cleared Bricks checkbox loses its key.
                'schema' => !empty($settings['outputSchema']),
                'pairs'  => self::rows($settings['faqs'] ?? []),
            ];
        }

        return $groups;
    }

    /**
     * FAQ modules in a post's Beaver Builder layout.
     *
     * Beaver Builder keeps its layout in postmeta as a map of node objects and
     * leaves `post_content` alone, so — unlike Bricks — there is no
     * "supersedes post_content" gate to apply: a block FAQ left in the body and
     * a module FAQ in the layout can both genuinely be on the page, and both
     * belong in the one FAQPage.
     *
     * The published layout is preferred over the draft for the same reason the
     * rest of the plugin prefers it: a draft holds edits no visitor has been
     * served yet, and schema must describe the page as delivered.
     *
     * @param \WP_Post $post Post to read.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function beaver_groups(\WP_Post $post): array {
        $layout = get_post_meta($post->ID, '_fl_builder_data', true);

        if (!is_array($layout) || empty($layout)) {
            return [];
        }

        $groups = [];

        foreach ($layout as $node) {
            $settings = is_object($node) ? ($node->settings ?? null) : ($node['settings'] ?? null);
            $settings = is_object($settings) ? get_object_vars($settings) : $settings;

            if (!is_array($settings) || ($settings['type'] ?? '') !== self::FAQ_BEAVER_MODULE) {
                continue;
            }

            $groups[] = [
                'source' => self::SOURCE_BEAVER,
                // Mirrors ThinkRank_Beaver_FAQ_Module::schema_enabled(): Beaver
                // Builder stores a cleared toggle as the string '0'.
                'schema' => !empty($settings['output_schema']),
                'pairs'  => self::rows($settings['faqs'] ?? []),
            ];
        }

        return $groups;
    }

    /**
     * Normalise a repeater to a list of arrays.
     *
     * Beaver Builder stores its rows as stdClass, everything else as arrays.
     *
     * @param mixed $rows Stored repeater.
     * @return array<int, array<string, mixed>>
     */
    private static function rows($rows): array {
        if (!is_array($rows)) {
            return [];
        }

        $normalised = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }

            if (is_array($row)) {
                $normalised[] = $row;
            }
        }

        return $normalised;
    }
}
