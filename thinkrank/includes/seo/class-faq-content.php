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
     * Oxygen, in every generation before Oxygen 6.
     *
     * A builder name rather than a SOURCE_*: ThinkRank ships no Oxygen FAQ
     * module, so nothing is ever read out of an Oxygen tree and no item can
     * carry this as its `source`. It exists because `builder()` has to be able
     * to say "Oxygen renders this post" even though the FAQ reader cannot
     * follow it there (#831).
     *
     * @since 2.14.0
     * @var string
     */
    public const BUILDER_OXYGEN = 'oxygen';

    /**
     * Breakdance, which is also what Oxygen 6 is built on.
     *
     * Named separately from Oxygen because the two are sold as different
     * products and `get-post-content` already reports them apart; a caller
     * told "Breakdance" should not have to know it is looking at Oxygen 6.
     *
     * @since 2.14.0
     * @var string
     */
    public const BUILDER_BREAKDANCE = 'breakdance';

    /**
     * Builder post meta keys whose presence alone names the builder.
     *
     * Every key here is one of {@see Builder_Content::builder_meta_keys()}, and
     * the labels match the ones `get-post-content` reports for the same keys so
     * the two abilities cannot disagree about the same page. Oxygen spans four
     * of them: Oxygen 6 is Breakdance under the hood, classic 4.x writes a JSON
     * tree beside its shortcodes, and 4.8.3 prefixed every `ct_*` key with an
     * underscore.
     *
     * `FaqAbilitiesTest` asserts that every key `Builder_Content` resolves
     * through appears either here or in {@see self::FLAGGED_BUILDER_META_KEYS},
     * so teaching `Builder_Content` about a new builder fails the build until
     * the FAQ abilities have been told what to do with it.
     *
     * @since 2.14.0
     * @var array<string, string>
     */
    private const META_BUILDERS = [
        '_breakdance_data'       => self::BUILDER_BREAKDANCE,
        '_oxygen_data'           => self::BUILDER_OXYGEN,
        '_ct_builder_json'       => self::BUILDER_OXYGEN,
        'ct_builder_json'        => self::BUILDER_OXYGEN,
        '_ct_builder_shortcodes' => self::BUILDER_OXYGEN,
        'ct_builder_shortcodes'  => self::BUILDER_OXYGEN,
    ];

    /**
     * Builder meta keys that must NOT be read as "this builder renders the post".
     *
     * Elementor and Beaver Builder both keep stored data on a post they no
     * longer render: `_elementor_data` survives switching a page back to the
     * block editor, and `_fl_builder_draft` holds changes that were never
     * published. Each has its own editor flag, which `builder()` tests instead,
     * and reading the data key would take `writable` away from a post the block
     * editor really does render.
     *
     * @since 2.14.0
     * @var string[]
     */
    private const FLAGGED_BUILDER_META_KEYS = [
        '_elementor_data',
        '_fl_builder_data',
        '_fl_builder_draft',
    ];

    /**
     * The schema setting that turns builder accordions into FAQPage content.
     *
     * @since 2.14.0
     * @var string
     */
    public const ACCORDION_SETTING = 'enable_accordion_faq_schema';

    /**
     * Resolved value of {@see self::ACCORDION_SETTING} for this request.
     *
     * @since 2.14.0
     * @var bool|null
     */
    private static ?bool $accordion_schema = null;

    /**
     * Element-name fragments that mark a subtree as an accordion.
     *
     * Matched as a fragment of the element's own name because each builder
     * spells it differently and renames it between releases: Oxygen classic has
     * `oxy_pro_accordion`, Breakdance `EssentialElements\Accordion`, and both
     * ship toggle variants. Matching the fragment survives a rename that an
     * exact list would not, and the cost of a false positive is bounded — a
     * subtree only contributes if title/body pairs are actually found in it.
     *
     * @since 2.14.0
     * @var string[]
     */
    private const ACCORDION_NAME_FRAGMENTS = ['accordion', 'toggle', 'faq'];

    /**
     * Tree keys that hold an element's own name or type.
     *
     * @since 2.14.0
     * @var string[]
     */
    private const NODE_NAME_KEYS = ['name', 'type', 'tag', 'slug', 'element', 'widgettype', 'widget_type', 'eltype'];

    /**
     * Key fragments whose string value reads as a question.
     *
     * Fragments rather than exact keys, for the same reason as the element
     * names: Oxygen prefixes a composite element's fields with its own slug, so
     * the title of an accordion item is `pro_accordion_item_title` on one
     * release and something adjacent on the next.
     *
     * @since 2.14.0
     * @var string[]
     */
    private const QUESTION_KEY_FRAGMENTS = ['question', 'title', 'heading', 'header', 'label', 'tab'];

    /**
     * Key fragments whose string value reads as an answer.
     *
     * `title` is deliberately absent and `text` deliberately present. Both
     * builders keep an item's answer in a child element whose copy is at
     * `content.content.text`, and an item that keeps the two together is read
     * the same way.
     *
     * @since 2.14.0
     * @var string[]
     */
    private const ANSWER_KEY_FRAGMENTS = ['answer', 'text', 'content', 'body', 'description', 'editor', 'html'];

    /**
     * How deep a builder tree is walked before the walk gives up.
     *
     * Builder trees are a few dozen levels at worst. A bound keeps a corrupt or
     * self-referential stored tree from exhausting the stack during a page
     * render, which is the kind of failure that takes a whole site down rather
     * than one FAQ.
     *
     * @since 2.14.0
     * @var int
     */
    private const MAX_TREE_DEPTH = 64;

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
        $groups = array_merge($groups, self::accordion_groups($post));

        return $groups;
    }

    /**
     * Whether page-builder accordions may contribute to the FAQPage.
     *
     * Off unless the site says otherwise, and that default is the whole point.
     * The other four producers are ThinkRank's own FAQ surfaces: an author who
     * dropped one on the page asked for FAQ markup, and the block even carries a
     * per-instance toggle. An Oxygen accordion carries no such intent — it may
     * hold questions, or product specifications, or a changelog — so reading one
     * as a FAQPage is the site owner's call to make. Defaulting it on would
     * change what existing sites publish on upgrade, unasked.
     *
     * Reported either way, because the abilities describe what is on a page
     * rather than what is published: with the setting off the questions are
     * still returned by `get-faq`, and `Schema_Graph` skips the group exactly as
     * it skips a block whose own schema toggle is off.
     *
     * Resolved once per request. The switch is site-wide so it cannot change
     * mid-request, and `Schema_Management_System` builds a cache manager and
     * registers listeners in its constructor, which is not something to spin up
     * per accordion.
     *
     * @since 2.14.0
     * @return bool
     */
    public static function accordion_schema_enabled(): bool {
        if (null === self::$accordion_schema) {
            self::$accordion_schema = false;

            if (class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
                $settings = (new Schema_Management_System())->get_settings('site', null);

                // Absent means off here, unlike the schema master switch:
                // this publishes markup a site was not publishing before, so
                // it has to be asked for rather than merely not refused.
                self::$accordion_schema = !empty($settings[self::ACCORDION_SETTING]);
            }
        }

        /**
         * Filter whether a builder accordion contributes to the FAQPage.
         *
         * The stored switch is site-wide, which is the right grain for "are our
         * accordions FAQs?" but the wrong one for a site where some are and
         * some are not. This runs on every call rather than being memoized with
         * the stored read, so it can answer per post.
         *
         * @since 2.14.0
         *
         * @param bool $enabled Whether accordion questions reach the FAQPage.
         */
        return (bool) apply_filters('thinkrank_faq_accordion_schema', self::$accordion_schema);
    }

    /**
     * Discard the resolved accordion switch. Test seam.
     *
     * @since 2.14.0
     * @return void
     */
    public static function flush_accordion_schema_cache(): void {
        self::$accordion_schema = null;
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
     * Oxygen and Breakdance have no such flag, so they are recognised by their
     * stored tree instead. They were missing entirely, and because '' is also
     * what the block editor returns, an Oxygen page was reported as having no
     * builder at all: `get-faq` called it writable and `update-faq` stored a
     * block Oxygen never renders, which is the one outcome both were written to
     * prevent (#831). The keys come from `Builder_Content`, which has detected
     * both since 1.23.0 for scoring, so nothing new has to be learned here.
     *
     * @since 2.10.1
     * @since 2.14.0 Detects Oxygen and Breakdance.
     * @param int $post_id Post ID.
     * @return string One of {@see self::builders()}, or '' for the block editor.
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

        return self::builder_from_meta($post_id);
    }

    /**
     * Every builder name {@see self::builder()} can report.
     *
     * Derived rather than listed, so an ability's enum cannot fall behind the
     * detection: the three flagged builders, then whatever `META_BUILDERS`
     * names, deduplicated because several keys share one builder.
     *
     * @since 2.14.0
     * @return string[]
     */
    public static function builders(): array {
        return array_values(array_unique(array_merge(
            [self::SOURCE_ELEMENTOR, self::SOURCE_BRICKS, self::SOURCE_BEAVER],
            array_values(self::META_BUILDERS)
        )));
    }

    /**
     * The builders ThinkRank can read FAQ questions out of.
     *
     * Oxygen and Breakdance are read through their own accordion rather than
     * through a ThinkRank module, so they are readable without being writable
     * and without appearing in {@see self::module_builders()}.
     *
     * @since 2.14.0
     * @return string[]
     */
    public static function readable_builders(): array {
        return array_merge(
            self::module_builders(),
            [self::BUILDER_OXYGEN, self::BUILDER_BREAKDANCE]
        );
    }

    /**
     * The builders that have a ThinkRank FAQ module of their own.
     *
     * The distinction an agent needs, and the one the first fix did not have.
     * A post built with one of these is refused by `update-faq`, but there is
     * somewhere to send its author: the ThinkRank FAQ module for that builder,
     * which carries its own schema toggle. A readable builder outside this list
     * has no module to point at, so its author is sent to the builder's own
     * accordion instead.
     *
     * @since 2.14.0
     * @return string[]
     */
    public static function module_builders(): array {
        return [self::SOURCE_ELEMENTOR, self::SOURCE_BRICKS, self::SOURCE_BEAVER];
    }

    /**
     * Whether ThinkRank can read FAQ questions out of a builder's storage.
     *
     * @since 2.14.0
     * @param string $builder Builder name, or '' for the block editor.
     * @return bool True for the block editor and every builder with a reader.
     */
    public static function builder_is_readable(string $builder): bool {
        return '' === $builder || in_array($builder, self::readable_builders(), true);
    }

    /**
     * Whether a builder has a ThinkRank FAQ module an author can be sent to.
     *
     * @since 2.14.0
     * @param string $builder Builder name, or '' for the block editor.
     * @return bool
     */
    public static function builder_has_module(string $builder): bool {
        return in_array($builder, self::module_builders(), true);
    }

    /**
     * Builder meta keys this class has an answer for. Test seam.
     *
     * The drift guard in `FaqAbilitiesTest` compares this against
     * {@see Builder_Content::builder_meta_keys()}: a key in neither set is a
     * builder whose pages would silently be reported as writable.
     *
     * @since 2.14.0
     * @return string[]
     */
    public static function classified_builder_meta_keys(): array {
        return array_merge(array_keys(self::META_BUILDERS), self::FLAGGED_BUILDER_META_KEYS);
    }

    /**
     * The builder named by a post's stored tree, for builders with no flag.
     *
     * Presence is the whole test, so the value is checked for emptiness in both
     * shapes it arrives in: Breakdance and Oxygen classic 4.x store JSON
     * strings, while a filtered or already-decoded value can be an array.
     *
     * @since 2.14.0
     * @param int $post_id Post ID.
     * @return string Builder name, or '' when no builder tree is stored.
     */
    private static function builder_from_meta(int $post_id): string {
        foreach (self::META_BUILDERS as $meta_key => $builder) {
            $stored = get_post_meta($post_id, $meta_key, true);

            if (is_array($stored)) {
                if ([] !== $stored) {
                    return $builder;
                }

                continue;
            }

            if (is_string($stored) && '' !== trim($stored)) {
                return $builder;
            }
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
     * Question/answer pairs from an Oxygen or Breakdance accordion.
     *
     * ThinkRank ships no FAQ module for either builder, so unlike the other four
     * producers there is no element of ours to look for. What there is instead is
     * the builder's own accordion, which is visible FAQ content that was being
     * reported as nothing at all: `get-faq` returned `total: 0` on a page with a
     * working FAQ on it, and the page published no FAQPage (#831).
     *
     * Read from the stored tree rather than by rendering, for the reasons
     * `Builder_Content` already documents: rendering an Oxygen page outside a
     * front-end request is slow, stateful and can fatal in admin context, while
     * the stored tree is cheap and side-effect free.
     *
     * The shapes are taken from the builders' own element definitions rather
     * than inferred: `Advanced_Accordion/element.php` and
     * `Accordion_Content/element.php` in `breakdance-elements` declare the
     * accordion as an element per item, each item holding its question at
     * `content.content.title` and its answer in its own child elements. Both
     * declare `availableIn() === ['breakdance', 'oxygen']`, so Oxygen 6 is the
     * same tree under a different product name.
     *
     * Oxygen classic keeps two copies of the same page, a JSON tree and a
     * shortcode string, and neither is reliably the richer one: a composite
     * element's copy is base64-encoded inside `ct_options` in the shortcode form,
     * while a key missing from the JSON walker loses it there. `from_oxygen_classic()`
     * resolves that by reading both and keeping whichever yielded more; this does
     * the same, keeping whichever yielded more pairs.
     *
     * @since 2.14.0
     * @param \WP_Post $post Post to read.
     * @return array<int, array{source: string, schema: bool, pairs: array}>
     */
    private static function accordion_groups(\WP_Post $post): array {
        $builder = self::builder((int) $post->ID);

        // Only the builders with no FAQ module of their own. Elementor, Bricks
        // and Beaver have one, and sweeping their trees for accordions as well
        // would publish a second FAQPage source behind the back of the module's
        // own schema toggle.
        if (!in_array($builder, [self::BUILDER_OXYGEN, self::BUILDER_BREAKDANCE], true)) {
            return [];
        }

        $pairs = self::accordion_pairs((int) $post->ID);

        /**
         * Filter the question/answer pairs read out of a builder accordion.
         *
         * The shapes below cover Oxygen classic, Oxygen 6 and Breakdance as they
         * store an accordion today. A builder release that moves its fields, or
         * a third-party accordion element, can be taught here instead of waiting
         * for the walker to learn it.
         *
         * @since 2.14.0
         *
         * @param array<int, array{question: string, answer: string}> $pairs   Pairs found.
         * @param \WP_Post                                           $post    Post being read.
         * @param string                                             $builder Builder that renders it.
         */
        $pairs = apply_filters('thinkrank_faq_builder_accordions', $pairs, $post, $builder);

        if (!is_array($pairs) || [] === $pairs) {
            return [];
        }

        return [[
            'source' => $builder,
            'schema' => self::accordion_schema_enabled(),
            'pairs'  => self::rows($pairs),
        ]];
    }

    /**
     * Accordion pairs from whichever storage this post has.
     *
     * @since 2.14.0
     * @param int $post_id Post ID.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function accordion_pairs(int $post_id): array {
        $best = [];

        foreach (self::classified_builder_meta_keys() as $meta_key) {
            if (in_array($meta_key, self::FLAGGED_BUILDER_META_KEYS, true)) {
                continue; // Elementor and Beaver, which have their own module.
            }

            $stored = get_post_meta($post_id, $meta_key, true);
            $pairs  = self::accordion_pairs_from_stored($stored);

            if (count($pairs) > count($best)) {
                $best = $pairs;
            }
        }

        return $best;
    }

    /**
     * Accordion pairs from one stored builder value, in either storage form.
     *
     * @since 2.14.0
     * @param mixed $stored Raw meta value.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function accordion_pairs_from_stored($stored): array {
        if (is_array($stored)) {
            return self::accordion_pairs_from_tree($stored);
        }

        if (!is_string($stored) || '' === trim($stored)) {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            return self::accordion_pairs_from_tree($decoded);
        }

        return self::accordion_pairs_from_shortcodes($stored);
    }

    /**
     * Walk a decoded builder tree and collect accordion pairs.
     *
     * @since 2.14.0
     * @param array<mixed> $tree Decoded tree.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function accordion_pairs_from_tree(array $tree): array {
        $pairs = [];

        $walk = static function ($node, int $depth) use (&$walk, &$pairs): void {
            if ($depth > self::MAX_TREE_DEPTH) {
                return;
            }

            $node = self::as_tree_node($node);
            if (null === $node) {
                return;
            }

            if (self::node_is_accordion($node)) {
                $pairs = array_merge($pairs, self::pairs_in_subtree($node));

                // Not descended into again: pairs_in_subtree() has already read
                // the whole thing, and a nested accordion would be collected
                // twice.
                return;
            }

            foreach ($node as $child) {
                $walk($child, $depth + 1);
            }
        };

        $walk($tree, 0);

        return $pairs;
    }

    /**
     * A tree node as an array of children, unwrapping Breakdance's inner JSON.
     *
     * Breakdance stores the whole page as a JSON *string* under one key of the
     * outer object, so a walker that only descends arrays stops at the door.
     * Decoding a string that parses as a JSON object is what lets the same
     * walker reach Oxygen 6 content, and it is bounded to strings that look like
     * JSON so an answer's prose is never parsed as a tree.
     *
     * @since 2.14.0
     * @param mixed $node Node to normalise.
     * @return array<mixed>|null Children, or null for a leaf.
     */
    private static function as_tree_node($node): ?array {
        if (is_object($node)) {
            $node = get_object_vars($node);
        }

        if (is_array($node)) {
            return $node;
        }

        if (!is_string($node)) {
            return null;
        }

        $trimmed = trim($node);

        if ('' === $trimmed || ('{' !== $trimmed[0] && '[' !== $trimmed[0])) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * An element's own name, wherever the builder keeps it.
     *
     * Two layouts, because the builders differ in where the name sits relative
     * to the children. Oxygen classic puts it on the node itself
     * (`{name: 'oxy_pro_accordion', children: [...]}`), while Breakdance wraps
     * it a level down (`{data: {type: 'EssentialElements\AdvancedAccordion'},
     * children: [...]}`), so the name is a sibling of the children rather than
     * their parent.
     *
     * Reading only the node's own keys is what broke the first version: the
     * walk matched Breakdance's `data` object, which holds the type but none of
     * the children, and so handed an accordion with every item stripped off it
     * to the pair reader. A real Breakdance page yielded nothing at all.
     *
     * @since 2.14.0
     * @param array<mixed> $node Tree node.
     * @return string Element name, or '' when the node names nothing.
     */
    private static function node_name(array $node): string {
        $name = self::name_on_node($node);

        if ('' !== $name) {
            return $name;
        }

        // Breakdance and Oxygen 6: `{id, data: {type, properties}, children}`.
        $data = self::as_tree_node($node['data'] ?? null);

        return null === $data ? '' : self::name_on_node($data);
    }

    /**
     * The element name carried by a node's own keys.
     *
     * @since 2.14.0
     * @param array<mixed> $node Tree node.
     * @return string
     */
    private static function name_on_node(array $node): string {
        foreach ($node as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (in_array(strtolower($key), self::NODE_NAME_KEYS, true)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Whether an element's name marks it as an accordion or one of its items.
     *
     * @since 2.14.0
     * @param string $name Element name.
     * @return bool
     */
    private static function name_is_accordion(string $name): bool {
        $name = strtolower($name);

        foreach (self::ACCORDION_NAME_FRAGMENTS as $fragment) {
            if (false !== strpos($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a node names itself as an accordion.
     *
     * @since 2.14.0
     * @param array<mixed> $node Tree node.
     * @return bool
     */
    private static function node_is_accordion(array $node): bool {
        return self::name_is_accordion(self::node_name($node));
    }

    /**
     * Collect question/answer pairs from inside one accordion element.
     *
     * The shape both builders actually use is an element per item rather than a
     * repeater: Breakdance nests an `AccordionContent` under the accordion,
     * carrying the question at `content.content.title`, and the answer lives in
     * that item's own child elements (a Text, a RichText, a Heading), each
     * keeping its copy at `content.content.text`. Oxygen classic arranges an
     * `oxy_pro_accordion_item` the same way. So an item's question is read from
     * the item's own properties and its answer from its children, which is what
     * keeps a nested element's unrelated `title` — a Video's own name, say —
     * from being read as the next question.
     *
     * A repeater is still handled, for a builder that stores one and for the
     * accordion whose items carry no element name of their own: when no named
     * item is found, every node is offered to the pair reader and a node
     * carrying both halves is taken whole.
     *
     * @since 2.14.0
     * @param array<mixed> $accordion Accordion element node.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function pairs_in_subtree(array $accordion): array {
        $items = self::item_nodes($accordion);

        if ([] !== $items) {
            $pairs = [];

            foreach ($items as $item) {
                $question = self::deep_fragment_value($item, self::QUESTION_KEY_FRAGMENTS, true);

                if ('' === $question) {
                    continue;
                }

                $children = self::as_tree_node($item['children'] ?? null);
                $answer   = null === $children
                    ? ''
                    : self::deep_fragment_value($children, self::ANSWER_KEY_FRAGMENTS, false);

                // An item that keeps its answer beside its question rather than
                // in a child element.
                if ('' === $answer) {
                    $answer = self::deep_fragment_value($item, self::ANSWER_KEY_FRAGMENTS, true);
                }

                if ('' !== $answer) {
                    $pairs[] = [
                        'question' => $question,
                        'answer'   => $answer,
                    ];
                }
            }

            return $pairs;
        }

        return self::repeater_pairs($accordion);
    }

    /**
     * The item elements directly describing one accordion's entries.
     *
     * An item names itself an accordion too (`AccordionContent`,
     * `oxy_pro_accordion_item`), so the accordion is told from its items by
     * being the node the search started at rather than by its name.
     *
     * @since 2.14.0
     * @param array<mixed> $accordion Accordion element node.
     * @return array<int, array<mixed>> Item nodes.
     */
    private static function item_nodes(array $accordion): array {
        $items = [];

        $walk = static function ($node, int $depth, bool $is_root) use (&$walk, &$items): void {
            if ($depth > self::MAX_TREE_DEPTH) {
                return;
            }

            $node = self::as_tree_node($node);
            if (null === $node) {
                return;
            }

            if (!$is_root && self::node_is_accordion($node)) {
                $items[] = $node;

                // Not descended into: a nested accordion inside an item is read
                // when the walk reaches it on its own, and descending here
                // would collect its items as siblings of this one's.
                return;
            }

            foreach ($node as $child) {
                $walk($child, $depth + 1, false);
            }
        };

        $walk($accordion, 0, true);

        return $items;
    }

    /**
     * Pair a repeater's rows, or zip loose questions and answers in order.
     *
     * The fallback for an accordion whose items are not elements of their own. A
     * row carrying both halves pairs directly; otherwise a question is held
     * until an answer follows it, and a second question arriving first replaces
     * the held one rather than pairing with a later answer, so an item with no
     * answer drops out instead of stealing the next item's.
     *
     * @since 2.14.0
     * @param array<mixed> $accordion Accordion element node.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function repeater_pairs(array $accordion): array {
        $pairs   = [];
        $pending = '';

        $walk = static function ($node, int $depth) use (&$walk, &$pairs, &$pending): void {
            if ($depth > self::MAX_TREE_DEPTH) {
                return;
            }

            $node = self::as_tree_node($node);
            if (null === $node) {
                return;
            }

            $question = self::first_fragment_value($node, self::QUESTION_KEY_FRAGMENTS);
            $answer   = self::first_fragment_value($node, self::ANSWER_KEY_FRAGMENTS);

            if ('' !== $question && '' !== $answer) {
                $pairs[] = [
                    'question' => $question,
                    'answer'   => $answer,
                ];
                $pending = '';

                return;
            }

            if ('' !== $question) {
                $pending = $question;
            } elseif ('' !== $answer && '' !== $pending) {
                $pairs[] = [
                    'question' => $pending,
                    'answer'   => $answer,
                ];
                $pending = '';
            }

            foreach ($node as $child) {
                $walk($child, $depth + 1);
            }
        };

        $walk($accordion, 0);

        return $pairs;
    }

    /**
     * The first matching string anywhere under a node.
     *
     * @since 2.14.0
     * @param array<mixed> $node           Tree node.
     * @param string[]     $fragments      Key fragments to match.
     * @param bool         $skip_children  Whether to stay out of `children`,
     *                                     which holds other elements rather than
     *                                     this one's own fields.
     * @return string
     */
    private static function deep_fragment_value(array $node, array $fragments, bool $skip_children): string {
        $found = '';

        $walk = static function ($current, int $depth) use (&$walk, &$found, $fragments, $skip_children): void {
            if ('' !== $found || $depth > self::MAX_TREE_DEPTH) {
                return;
            }

            $current = self::as_tree_node($current);
            if (null === $current) {
                return;
            }

            $value = self::first_fragment_value($current, $fragments);
            if ('' !== $value) {
                $found = $value;

                return;
            }

            foreach ($current as $key => $child) {
                if ($skip_children && is_string($key) && 'children' === strtolower($key)) {
                    continue;
                }

                $walk($child, $depth + 1);
            }
        };

        $walk($node, 0);

        return $found;
    }

    /**
     * The first string on a node whose key matches one of the fragments.
     *
     * An exact key wins over a fragment of one, so an `AccordionContent`
     * carrying `title` beside `title_tag` yields the question rather than the
     * heading level it is rendered at. Among fragment matches the node's own key
     * order decides, so a builder that writes `question` and `title` yields
     * whichever it wrote first rather than whichever this class prefers.
     *
     * @since 2.14.0
     * @param array<mixed> $node      Tree node.
     * @param string[]     $fragments Key fragments to match.
     * @return string
     */
    private static function first_fragment_value(array $node, array $fragments): string {
        $fallback = '';

        foreach ($node as $key => $value) {
            if (!is_string($key) || !is_string($value) || '' === trim($value)) {
                continue;
            }

            $lower = strtolower($key);

            if (in_array($lower, $fragments, true)) {
                return trim($value);
            }

            if ('' !== $fallback) {
                continue;
            }

            foreach ($fragments as $fragment) {
                if (false !== strpos($lower, $fragment)) {
                    $fallback = trim($value);

                    break;
                }
            }
        }

        return $fallback;
    }

    /**
     * Collect accordion pairs from an Oxygen classic shortcode tree.
     *
     * Parsed rather than rendered. `do_shortcode()` depends on Oxygen having
     * registered its `ct_*` handlers in the current request, which it has not
     * during bulk analysis, the post-list column, cron, REST or MCP — the same
     * trap that once had raw shortcode source counted as a page's prose (#776).
     *
     * `ct_options` is deliberately not read, which means a composite element's
     * accordion contributes nothing from this form. Oxygen base64-encodes a
     * composite element's field values inside that blob, so walking it would
     * match the encoded string as a question and publish base64 as an FAQ.
     * `Builder_Content` reached the same conclusion for word counting: the JSON
     * tree is the only readable source for a composite element, and an Oxygen
     * classic 4.x site stores one beside its shortcodes. Reporting nothing is
     * the right answer for a 3.x site that stores only shortcodes.
     *
     * @since 2.14.0
     * @param string $stored Stored shortcode string.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function accordion_pairs_from_shortcodes(string $stored): array {
        if (false === strpos($stored, '[')) {
            return [];
        }

        $fragments = implode('|', array_map('preg_quote', self::ACCORDION_NAME_FRAGMENTS));
        $pattern   = '/\[([a-z0-9_]*(?:' . $fragments . ')[a-z0-9_]*)\b([^\]]*)\](.*?)\[\/\1\]/is';

        if (!preg_match_all($pattern, $stored, $regions, PREG_SET_ORDER)) {
            return [];
        }

        $pairs = [];

        foreach ($regions as $region) {
            // An item tag is itself an accordion-named tag on most releases
            // (`oxy_pro_accordion_item`), so the outermost match is the whole
            // accordion and its items are matched again inside it.
            $inner = (string) ($region[3] ?? '');

            $pairs = array_merge($pairs, self::shortcode_items($inner));
        }

        return $pairs;
    }

    /**
     * Question/answer pairs from the item tags inside an accordion.
     *
     * @since 2.14.0
     * @param string $inner Shortcode string inside the accordion tag.
     * @return array<int, array{question: string, answer: string}>
     */
    private static function shortcode_items(string $inner): array {
        if (!preg_match_all('/\[([a-z0-9_]+)\b([^\]]*)\](.*?)\[\/\1\]/is', $inner, $items, PREG_SET_ORDER)) {
            return [];
        }

        $pairs = [];

        foreach ($items as $item) {
            $attributes = self::shortcode_attributes((string) ($item[2] ?? ''));
            $question   = self::first_fragment_value($attributes, self::QUESTION_KEY_FRAGMENTS);
            $answer     = trim(wp_strip_all_tags(self::strip_shortcode_tags((string) ($item[3] ?? ''))));

            if ('' !== $question && '' !== $answer) {
                $pairs[] = [
                    'question' => $question,
                    'answer'   => $answer,
                ];

                continue;
            }
        }

        return $pairs;
    }

    /**
     * Parse a shortcode tag's attributes into a name => value map.
     *
     * Parsed into a map rather than probed with one regex per attribute name, so
     * the same fragment matching the tree walker uses applies here too. Probing
     * by name cannot do that: `\b` does not match inside `accordion_title`,
     * because the underscore before it is a word character, so a pattern built
     * for `title` silently found nothing on the one attribute Oxygen writes.
     *
     * `shortcode_parse_atts()` is not used. It arrives with the shortcode API
     * rather than being always available, and it folds positional attributes
     * into numeric keys that would then be matched as content.
     *
     * @since 2.14.0
     * @param string $attributes Raw attribute string from a shortcode tag.
     * @return array<string, string>
     */
    private static function shortcode_attributes(string $attributes): array {
        $pattern = '/([a-z0-9_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';

        if (!preg_match_all($pattern, $attributes, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $parsed = [];

        foreach ($matches as $match) {
            $name = strtolower((string) $match[1]);

            // First wins, so a repeated attribute cannot have its value
            // replaced by a later empty one.
            if (isset($parsed[$name])) {
                continue;
            }

            $value = '' !== ($match[2] ?? '') ? $match[2] : ($match[3] ?? '');

            $parsed[$name] = trim(wp_specialchars_decode((string) $value, ENT_QUOTES));
        }

        return $parsed;
    }

    /**
     * Remove shortcode tags while keeping the text between them.
     *
     * `strip_shortcodes()` is no help: it only knows shortcodes registered in
     * the current request, and Oxygen registers none outside a front-end view.
     *
     * @since 2.14.0
     * @param string $content Shortcode string.
     * @return string
     */
    private static function strip_shortcode_tags(string $content): string {
        return (string) preg_replace('/\[\/?[a-z0-9_]+\b[^\]]*\]/i', ' ', $content);
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
