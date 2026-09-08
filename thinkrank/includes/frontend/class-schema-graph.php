<?php
/**
 * Schema Graph Collector
 *
 * Single assembly point for every piece of JSON-LD ThinkRank emits on a request.
 *
 * Four subsystems used to write structured data independently — the Schema
 * Manager (deployed per-post rows), the post-type-wide Global SEO output, the
 * Gutenberg FAQ block and the Elementor FAQ widget. Each echoed its own
 * <script> tag, so one URL could carry several page-level entities that never
 * referenced each other, including two FAQPage entities with different
 * questions (#355).
 *
 * Producers now register here instead of echoing. One late wp_head pass picks
 * the page-level entity by source precedence — dropping the losing source, but
 * keeping entities deployed alongside the winner — merges every FAQ source into
 * one FAQPage, assigns stable @id values, links the nodes together and emits a
 * single @graph.
 *
 * @package ThinkRank\Frontend
 * @subpackage SEO
 * @since 1.32.0
 */

declare(strict_types=1);

namespace ThinkRank\Frontend;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects and emits ThinkRank's structured data as one linked @graph.
 *
 * @since 1.32.0
 */
class Schema_Graph {

    /**
     * Schema context URL.
     */
    private const SCHEMA_CONTEXT = 'https://schema.org';

    /**
     * Entity types that describe the site rather than the current page.
     *
     * These get a home-scoped @id so the same entity keeps one identity on
     * every URL. WebSite and Organization are handled explicitly alongside
     * these because they also seed isPartOf/publisher links (#471).
     *
     * @since 1.16.0
     * @var string[]
     */
    private const SITE_LEVEL_TYPES = ['LocalBusiness', 'Person'];

    /**
     * Which source wins when several subsystems describe the page.
     *
     * Lower wins. Per-post schema deployed from the editor's Schema tab is a
     * deliberate per-post decision, so it outranks the post-type-wide default.
     *
     * @var array<string,int>
     */
    private const PRIMARY_PRECEDENCE = [
        'schema_manager' => 10,
        'global_seo'     => 20,
    ];

    /**
     * Types that can legitimately be *the* entity a URL is about.
     *
     * Anything outside this set — Organization, Person, WebSite, LocalBusiness,
     * or a type a future release starts deploying — is emitted as a supporting
     * node instead of competing. Deliberately an allowlist: an unrecognised type
     * demoted to supporting merely adds a node, whereas letting a non-page-level
     * type win the slot deletes the page's real entity.
     *
     * @var array<int,string>
     */
    private const PAGE_LEVEL_TYPES = [
        'Article', 'BlogPosting', 'NewsArticle', 'ScholarlyArticle', 'TechArticle',
        'TechnicalArticle', 'Report', 'WebPage', 'AboutPage', 'ContactPage',
        'ProfilePage', 'ItemPage', 'FAQPage', 'QAPage', 'CollectionPage',
        'Product', 'Event', 'Recipe', 'Course', 'JobPosting', 'SoftwareApplication',
        'Book', 'Movie', 'Service', 'ImageObject', 'VideoObject',
    ];

    /**
     * Gutenberg FAQ block name.
     */
    private const FAQ_BLOCK = 'thinkrank/faq';

    /**
     * Elementor FAQ widget name.
     */
    private const FAQ_WIDGET = 'thinkrank-faq';

    /**
     * Bricks FAQ element name.
     *
     * @since 2.3.1
     */
    private const FAQ_BRICKS_ELEMENT = 'thinkrank-faq';

    /**
     * Third-party Elementor widgets that publish their own FAQPage.
     *
     * Maps widgetType to the setting whose 'yes' arms that widget's FAQ schema,
     * so an accordion used purely as an accordion never suppresses ours.
     *
     * @since 2.1.0
     * @var array<string,string>
     */
    private const FOREIGN_FAQ_WIDGETS = [
        // Essential Addons for Elementor — Advanced Accordion.
        'eael-adv-accordion' => 'eael_adv_accordion_faq_schema_show',
    ];

    /**
     * Bricks elements that publish their own FAQPage.
     *
     * Bricks is a theme, not a plugin, and its accordions are core elements
     * rather than a third-party add-on — so unlike FOREIGN_FAQ_WIDGETS this is
     * a plain list: they share one gate, the `faqSchema` setting, and the
     * per-element part of the check is whether the element has usable items
     * (see bricks_element_publishes_faq()).
     *
     * @since 2.3.1
     * @var string[]
     */
    private const FOREIGN_FAQ_BRICKS_ELEMENTS = ['accordion', 'accordion-nested'];

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Competing page-level entities: ['rank' => int, 'schema' => array, 'type' => string].
     *
     * @var array<int,array>
     */
    private array $primary_candidates = [];

    /**
     * Non-competing nodes (Organization, WebSite, BreadcrumbList, HowTo, …).
     *
     * @var array<int,array>
     */
    private array $supporting = [];

    /**
     * Merged FAQ questions, keyed by normalized question text.
     *
     * @var array<string,array>
     */
    private array $faq_entities = [];

    /**
     * Memoized answer to "should this request emit a FAQPage at all?".
     *
     * @since 2.1.0
     * @var bool|null
     */
    private ?bool $emit_faqpage = null;

    /**
     * Whether FAQ content was taken from the rendered post body (block/widget),
     * meaning those producers must not emit their own duplicate script.
     *
     * @var bool
     */
    private bool $absorbed_content_faq = false;

    /**
     * Guards against collecting the post's FAQ content more than once.
     *
     * @var bool
     */
    private bool $faq_collected = false;

    /**
     * Whether a producer has committed to rendering this graph on the request.
     *
     * Lazy FAQ collection is gated on it: absorbing a block's questions into a
     * graph that will never be emitted would silence the block and publish
     * nothing in its place.
     *
     * @var bool
     */
    private bool $render_scheduled = false;

    /**
     * Guards against a second render on the same request.
     *
     * @var bool
     */
    private bool $rendered = false;

    /**
     * Get the shared instance.
     *
     * @since 1.32.0
     * @return self
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Discard the shared instance. Test seam.
     *
     * @since 1.32.0
     * @return void
     */
    public static function reset(): void {
        self::$instance = null;
    }

    /**
     * Register a candidate for the page's single page-level entity.
     *
     * A FAQPage is never a candidate in its own right — its questions are merged
     * into the one FAQ node instead, so a deployed FAQPage and an FAQ block can
     * never become two competing FAQPage entities.
     *
     * @since 1.32.0
     * @param array  $schema Schema array.
     * @param string $type   Schema @type.
     * @param string $source Producer key from PRIMARY_PRECEDENCE.
     * @return void
     */
    public function add_primary(array $schema, string $type, string $source): void {
        if (empty($schema)) {
            return;
        }

        $type = $this->effective_type($schema, $type);

        if ('FAQPage' === $type && $this->should_emit_faqpage()) {
            $this->add_faq_entities($schema['mainEntity'] ?? []);
            return;
        }

        // A third party owns the page's FAQPage, so ours must not be emitted
        // (#494). Demote rather than drop: a FAQPage is still the page, and
        // returning here would leave the URL with no page-level entity at all.
        if ('FAQPage' === $type) {
            $schema['@type'] = 'WebPage';
            unset($schema['mainEntity']);
            $type = 'WebPage';
        }

        // A per-post deployment can be something that isn't what the page is
        // about (an Organization, say). Letting it win the slot would drop the
        // page's real entity, so it joins the graph as a supporting node.
        if (!in_array($type, self::PAGE_LEVEL_TYPES, true)) {
            $this->supporting[] = $schema;
            return;
        }

        $this->primary_candidates[] = [
            'rank'   => self::PRIMARY_PRECEDENCE[$source] ?? PHP_INT_MAX,
            'schema' => $schema,
            'type'   => $type,
        ];
    }

    /**
     * Resolve what a schema actually is, not what it was configured as.
     *
     * The two differ whenever a generator falls back — a post type configured
     * as FAQPage emits a WebPage when the page has no genuine Q&A. Trusting the
     * configured label there would route a WebPage into FAQ merging and drop it.
     *
     * @since 1.32.0
     * @param array  $schema    Schema array.
     * @param string $declared  Type the producer declared.
     * @return string
     */
    private function effective_type(array $schema, string $declared): string {
        $actual = $schema['@type'] ?? '';

        return (is_string($actual) && $actual !== '') ? $actual : $declared;
    }

    /**
     * Register a node that does not compete for the page-level slot.
     *
     * @since 1.32.0
     * @param array  $schema Schema array.
     * @param string $type   Schema @type.
     * @return void
     */
    public function add_supporting(array $schema, string $type): void {
        if (empty($schema)) {
            return;
        }

        $effective_type = $this->effective_type($schema, $type);

        // A supporting FAQPage never survives as its own node: its questions
        // merge into the graph's single FAQ node, or are dropped when a third
        // party already owns the page's FAQPage (#494). Unlike the primary
        // slot there is nothing to preserve here, so demotion would only add a
        // second page-level entity beside the real one.
        if ('FAQPage' === $effective_type) {
            if ($this->should_emit_faqpage()) {
                $this->add_faq_entities($schema['mainEntity'] ?? []);
            }
            return;
        }

        // One breadcrumb trail per page. A deployed BreadcrumbList lands here
        // and output_breadcrumb_schema() adds a second on its own wp_head hook,
        // so pages ended up with #breadcrumb and #breadcrumb-2 — two conflicting
        // trails, with the primary node linking to only one of them (#471).
        // First writer wins.
        if ('BreadcrumbList' === $effective_type && $this->has_supporting_type('BreadcrumbList')) {
            return;
        }

        $this->supporting[] = $schema;
    }

    /**
     * Whether a supporting node of the given type has already been collected.
     *
     * @since 1.16.0
     *
     * @param string $type Schema type.
     * @return bool
     */
    private function has_supporting_type(string $type): bool {
        foreach ($this->supporting as $node) {
            if (($node['@type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge FAQ questions into the single FAQ node, deduped by question text.
     *
     * @since 1.32.0
     * @param mixed $entities Candidate Question entities.
     * @return void
     */
    public function add_faq_entities($entities): void {
        if (!is_array($entities)) {
            return;
        }

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $question = isset($entity['name']) ? trim((string) $entity['name']) : '';
            $answer   = isset($entity['acceptedAnswer']['text'])
                ? trim((string) $entity['acceptedAnswer']['text'])
                : '';

            if ($question === '' || $answer === '') {
                continue;
            }

            $key = strtolower(preg_replace('/\s+/', ' ', $question) ?? $question);

            // First writer wins, so the deliberate per-post deployment keeps its
            // wording when the same question also appears in a block.
            if (!isset($this->faq_entities[$key])) {
                $this->faq_entities[$key] = $entity;
            }
        }
    }

    /**
     * Whether FAQ content from the post body has been absorbed into the graph.
     *
     * The FAQ block and Elementor widget call this to decide whether to skip
     * their own inline JSON-LD. False (nothing absorbed, or the graph never ran)
     * leaves their original behaviour untouched.
     *
     * @since 1.32.0
     * @return bool
     */
    public function absorbed_content_faq(): bool {
        $this->maybe_collect_post_faq();

        return $this->absorbed_content_faq;
    }

    /**
     * Announce that this graph will be rendered on the current request.
     *
     * Called where the render hook is registered, so the graph can tell "I am
     * about to be emitted" from "nothing will output me" without inspecting
     * hooks it does not own.
     *
     * @since 1.32.0
     * @return void
     */
    public function schedule_render(): void {
        $this->render_scheduled = true;
    }

    /**
     * Collect the queried post's FAQ content if nothing has yet.
     *
     * Block themes render the whole template — post content included — from
     * `get_the_block_template_html()`, and on some flows that happens before
     * `wp_head` fires. The FAQ block therefore asked whether it had been
     * absorbed while the graph's own collection pass was still pending, read
     * false, and emitted a second FAQPage beside the graph's. Collecting on
     * first ask makes the answer independent of which side runs first; the
     * result is identical either way, because collection reads `post_content`
     * rather than anything the render produces.
     *
     * @since 1.32.0
     * @return void
     */
    private function maybe_collect_post_faq(): void {
        if ($this->faq_collected || $this->rendered || !$this->render_scheduled) {
            return;
        }

        if (!function_exists('is_singular') || !is_singular()) {
            return;
        }

        $post = get_post();
        if ($post instanceof \WP_Post) {
            $this->collect_post_faq($post);
        }
    }

    /**
     * Pull FAQ content out of a post's blocks and Elementor data.
     *
     * Runs during wp_head, before the body renders, so the block and widget can
     * see that their content is already accounted for.
     *
     * @since 1.32.0
     * @param \WP_Post $post Post being viewed.
     * @return void
     */
    public function collect_post_faq(\WP_Post $post): void {
        if ($this->faq_collected) {
            return;
        }

        $this->faq_collected = true;

        // Reading post_content directly bypasses the gate the render path gets
        // for free: behind a password form the FAQ block never renders, so it
        // never emitted schema. Without this check the graph would publish the
        // questions and answers of protected content to anyone.
        if (function_exists('post_password_required') && post_password_required($post)) {
            return;
        }

        // The same gate, for the same reason, with a different cause: a Bricks
        // page throws `post_content` away, so a FAQ block left there when the
        // page was switched over never renders. Publishing its questions would
        // put schema on the page for content no visitor can see — which Google
        // treats as a violation, not merely a duplicate (#650).
        if (!$this->bricks_supersedes_post_content((int) $post->ID)) {
            $this->collect_block_faq($post);
        }

        $this->collect_elementor_faq($post);
        $this->collect_bricks_faq($post);
    }

    /**
     * Whether Bricks renders this post and discards its `post_content`.
     *
     * @since 2.3.1
     * @param int $post_id Post being viewed.
     * @return bool
     */
    private function bricks_supersedes_post_content(int $post_id): bool {
        if (!class_exists('ThinkRank\\SEO\\Builder_Content')) {
            $file = THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
            if (!file_exists($file)) {
                return false;
            }
            require_once $file;
        }

        return \ThinkRank\SEO\Builder_Content::bricks_supersedes_post_content($post_id);
    }

    /**
     * Record that a body FAQ producer's content is represented in the graph.
     *
     * Deliberately not keyed on the entity count growing: when a block asks the
     * same question as the per-post deployment, dedup means nothing is added,
     * but the block's content *is* covered and it must still stay quiet.
     *
     * @since 1.32.0
     * @param array $entities Questions found on that producer.
     * @return void
     */
    private function absorb_content_faq(array $entities): void {
        if (empty($entities)) {
            return;
        }

        $this->add_faq_entities($entities);
        $this->absorbed_content_faq = true;
    }

    /**
     * Collect FAQ questions from thinkrank/faq blocks, including nested ones.
     *
     * @since 1.32.0
     * @param \WP_Post $post Post being viewed.
     * @return void
     */
    private function collect_block_faq(\WP_Post $post): void {
        if (!function_exists('parse_blocks') || !has_blocks($post->post_content)) {
            return;
        }

        $this->walk_blocks(parse_blocks($post->post_content));
    }

    /**
     * Recurse a parsed block tree collecting FAQ entries.
     *
     * @since 1.32.0
     * @param array $blocks Parsed blocks.
     * @return void
     */
    private function walk_blocks(array $blocks): void {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['blockName'] ?? '') === self::FAQ_BLOCK) {
                $attrs = $block['attrs'] ?? [];

                // Mirrors Blocks_Manager: schema is on unless explicitly disabled.
                $disabled = array_key_exists('outputSchema', $attrs) && false === $attrs['outputSchema'];

                if (!$disabled) {
                    $this->absorb_content_faq($this->questions_from_pairs($attrs['faqs'] ?? []));
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->walk_blocks($block['innerBlocks']);
            }
        }
    }

    /**
     * Collect FAQ questions from Elementor FAQ widgets.
     *
     * @since 1.32.0
     * @param \WP_Post $post Post being viewed.
     * @return void
     */
    private function collect_elementor_faq(\WP_Post $post): void {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (empty($raw) || !is_string($raw)) {
            return;
        }

        $elements = json_decode($raw, true);
        if (!is_array($elements)) {
            return;
        }

        $this->walk_elementor($elements);
    }

    /**
     * Collect FAQ questions from Bricks FAQ elements.
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
     * @since 2.3.1
     * @param \WP_Post $post Post being viewed.
     * @return void
     */
    private function collect_bricks_faq(\WP_Post $post): void {
        $this->walk_bricks($this->bricks_tree((int) $post->ID));
    }

    /**
     * Collect FAQ entries from a resolved Bricks tree.
     *
     * The tree is flat, so no recursion: `Builder_Content::bricks_tree()`
     * splices component definitions into the same list.
     *
     * The element's own settings are read here rather than through
     * `FAQ_Element`, whose class extends `Bricks\Element` and so cannot even be
     * loaded when the theme is inactive — which is exactly the case that still
     * has a stored tree, on a site that has since switched themes. The repeater
     * uses the same `question` / `answer` keys as the block, so the shared
     * builder below already understands it.
     *
     * @since 2.3.1
     * @param array $elements Bricks elements.
     * @return void
     */
    private function walk_bricks(array $elements): void {
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['name'] ?? '') !== self::FAQ_BRICKS_ELEMENT) {
                continue;
            }

            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

            // Mirrors FAQ_Element: a cleared Bricks checkbox loses its key.
            if (empty($settings['outputSchema'])) {
                continue;
            }

            $this->absorb_content_faq($this->questions_from_pairs($settings['faqs'] ?? []));
        }
    }

    /**
     * Recurse an Elementor element tree collecting FAQ entries.
     *
     * @since 1.32.0
     * @param array $elements Elementor elements.
     * @return void
     */
    private function walk_elementor(array $elements): void {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (($element['widgetType'] ?? '') === self::FAQ_WIDGET) {
                $settings = $element['settings'] ?? [];

                // Mirrors FAQ_Widget: schema unless the toggle is off.
                if ('yes' === ($settings['output_schema'] ?? 'yes')) {
                    $this->absorb_content_faq($this->questions_from_pairs($settings['faqs'] ?? []));
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->walk_elementor($element['elements']);
            }
        }
    }

    /**
     * Turn stored question/answer pairs into Question entities.
     *
     * @since 1.32.0
     * @param mixed $pairs Repeater rows with question/answer keys.
     * @return array
     */
    private function questions_from_pairs($pairs): array {
        if (!is_array($pairs)) {
            return [];
        }

        $entities = [];

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $question = isset($pair['question']) ? trim(wp_strip_all_tags((string) $pair['question'])) : '';
            $answer   = isset($pair['answer']) ? trim((string) $pair['answer']) : '';

            if ($question === '' || $answer === '') {
                continue;
            }

            $text = wp_kses_post($answer);

            // Mirrors Blocks_Manager::build_faq_schema() by calling the same
            // builder, so the two paths cannot drift — the per-item image is
            // resolved from its attachment id, carries intrinsic dimensions,
            // and disappears if the media was deleted (#418).
            $text .= \ThinkRank\Editor\Blocks_Manager::faq_image_markup(is_array($pair) ? $pair : []);

            $entities[] = [
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $text,
                ],
            ];
        }

        return $entities;
    }

    /**
     * Whether anything has been registered.
     *
     * @since 1.32.0
     * @return bool
     */
    public function has_nodes(): bool {
        return !empty($this->primary_candidates) || !empty($this->supporting) || !empty($this->faq_entities);
    }

    /**
     * Assemble and emit the graph. Safe to call more than once.
     *
     * @since 1.32.0
     * @return void
     */
    public function render(): void {
        if ($this->rendered || !$this->has_nodes()) {
            return;
        }

        // A 404 response represents no content, so there is nothing for
        // structured data to describe. The page-level producers already skip
        // this context, but the site-identity entity does not, so without this
        // guard every miss — including crawlers probing URLs that never existed
        // — emits a Person carrying email, telephone and birthDate (#481).
        if (is_404()) {
            return;
        }

        $this->rendered = true;

        $graph = $this->build_graph();

        /**
         * Filter the assembled schema graph before output.
         *
         * Receives every node ThinkRank is about to emit, already deduped and
         * linked, so add-ons can append or adjust nodes in one place.
         *
         * @since 1.32.0
         *
         * @param array $graph List of schema nodes ([] suppresses output).
         */
        $graph = apply_filters('thinkrank_schema_graph', $graph);

        // Drop empty properties across every node. An empty string is worse
        // than an absent one — "headline": "" fails Article validation harder
        // than omitting it — and Schema_Builder::clean_schema_array(), which was
        // written for exactly this, is never reached from the render path
        // (#471). Runs after the filter so add-on nodes are cleaned too.
        $graph = array_values(array_filter(array_map([$this, 'prune_empty_values'], $graph)));

        if (empty($graph)) {
            return;
        }

        $json = wp_json_encode(
            ['@context' => self::SCHEMA_CONTEXT, '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if (false === $json) {
            return;
        }

        echo "<!-- ThinkRank Schema Graph -->\n";
        echo '<script type="application/ld+json">' . "\n";
        echo $json . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_* cannot break out of the script block.
        echo '</script>' . "\n";
        echo "<!-- /ThinkRank Schema Graph -->\n";
    }

    /**
     * Replace an inline entity with an @id reference to an equivalent node.
     *
     * Matches on name so a post author is never silently collapsed into the
     * site's Person entity, and vice versa (#471).
     *
     * @since 1.16.0
     *
     * @param mixed $inline     The inline entity from the primary node.
     * @param array $candidates Nodes already in the graph, each with an @id.
     * @return array|null ['@id' => …] when a match is found, null otherwise.
     */
    private function link_to_node($inline, array $candidates): ?array {
        if (!is_array($inline) || empty($candidates)) {
            return null;
        }

        // Already a reference.
        if (isset($inline['@id']) && !isset($inline['name'])) {
            return null;
        }

        $inline_name = isset($inline['name']) ? trim((string) $inline['name']) : '';

        if ('' === $inline_name) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $candidate_name = isset($candidate['name']) ? trim((string) $candidate['name']) : '';

            if ('' !== $candidate_name
                && 0 === strcasecmp($candidate_name, $inline_name)
                && !empty($candidate['@id'])
            ) {
                return ['@id' => $candidate['@id']];
            }
        }

        return null;
    }

    /**
     * Recursively drop empty properties from a schema node.
     *
     * Removes '', [], and null. Deliberately keeps numeric 0, boolean false and
     * the structural keys, which are all meaningful values.
     *
     * @since 1.16.0
     *
     * @param mixed $value Node or property value.
     * @return mixed Cleaned value.
     */
    private function prune_empty_values($value) {
        if (!is_array($value)) {
            return $value;
        }

        $cleaned = [];

        foreach ($value as $key => $item) {
            // Never prune the keys that give a node its identity.
            if (in_array($key, ['@context', '@type', '@id'], true)) {
                $cleaned[$key] = $item;
                continue;
            }

            if (is_array($item)) {
                $item = $this->prune_empty_values($item);

                if ([] === $item) {
                    continue;
                }

                $cleaned[$key] = $item;
                continue;
            }

            if (null === $item || '' === $item) {
                continue;
            }

            $cleaned[$key] = $item;
        }

        return $cleaned;
    }

    /**
     * Build the linked node list.
     *
     * @since 1.32.0
     * @return array
     */
    private function build_graph(): array {
        $selection = $this->select_primary_set();
        $primary   = $selection['winner'];
        $siblings  = $selection['siblings'];
        $faq       = $this->build_faq_node();
        $base      = $this->base_url($primary);

        // With no other page-level entity, the FAQ node is the page.
        if (null === $primary && null !== $faq) {
            $primary = ['schema' => $faq, 'type' => 'FAQPage'];
            $faq     = null;
        }

        $nodes       = [];
        $primary_id  = '';
        $used_ids    = [];

        if (null !== $primary) {
            $node = $primary['schema'];

            // Key the @id off the node's resolved @type, not the configured one,
            // so an "Article" setting that renders BlogPosting reads #blogposting.
            $resolved_type = $this->effective_type($node, $primary['type']);

            $node = $this->assign_id($node, $base . '#' . strtolower($resolved_type), $used_ids);
            $primary_id  = $node['@id'];
            $nodes['primary'] = $node;
        }

        // Entities deployed alongside the winner (Pro's Multi-Schema lets a post
        // carry an Article *and* a Recipe). They lost the page slot but were
        // deliberately deployed, so they stay in the graph linked to the primary
        // rather than being dropped.
        foreach ($siblings as $index => $sibling) {
            $node = $sibling['schema'];

            $node = $this->assign_id(
                $node,
                $base . '#' . strtolower($this->effective_type($node, $sibling['type'])),
                $used_ids
            );

            if ($primary_id !== '' && $node['@id'] !== $primary_id) {
                $node['isPartOf']         = $node['isPartOf'] ?? ['@id' => $primary_id];
                $node['mainEntityOfPage'] = $node['mainEntityOfPage'] ?? ['@id' => $primary_id];
            }

            $nodes['sibling_' . $index] = $node;
        }

        if (null !== $faq) {
            $faq = $this->assign_id($faq, $base . '#faq', $used_ids);

            if ($primary_id !== '') {
                $faq['isPartOf']         = ['@id' => $primary_id];
                $faq['mainEntityOfPage'] = ['@id' => $primary_id];
            }

            $nodes['faq'] = $faq;
        }

        $website_id         = '';
        $breadcrumb_id      = '';
        $organization_nodes = [];
        $person_nodes       = [];

        foreach ($this->supporting as $index => $node) {
            $type = $node['@type'] ?? '';

            if ('BreadcrumbList' === $type) {
                $node = $this->assign_id($node, $base . '#breadcrumb', $used_ids);
                $breadcrumb_id = $node['@id'];
            } elseif ('WebSite' === $type) {
                $node = $this->assign_id($node, home_url('/#website'), $used_ids);
                $website_id = $node['@id'];
            } elseif ('Organization' === $type) {
                $node = $this->assign_id($node, home_url('/#organization'), $used_ids);
                $organization_nodes[] = $node;
            } elseif (in_array($type, self::SITE_LEVEL_TYPES, true)) {
                // Site-level entities describe the site, not the page, so their
                // @id must be stable across URLs. Falling through to the
                // page-scoped branch minted a fresh identity on every URL, so
                // one business became N entities in a crawler's graph and
                // nothing could reference it by @id (#471).
                // One entity, emitted once. The site identity and a per-post
                // deployment describe the same person or business, so both
                // arrive here claiming the same @id. assign_id() would resolve
                // that collision by minting "#person-2", turning a duplicate
                // into two competing entities that split the identity a
                // knowledge graph is meant to consolidate (#479).
                $duplicate_key = $this->find_same_entity($nodes, $type, $node);

                if (null !== $duplicate_key) {
                    $nodes[$duplicate_key] = $this->merge_entity($nodes[$duplicate_key], $node);
                    continue;
                }

                $node = $this->assign_id($node, home_url('/#' . strtolower($type)), $used_ids);

                if ('Person' === $type) {
                    $person_nodes[] = $node;
                }
            } elseif (is_string($type) && $type !== '') {
                $node = $this->assign_id($node, $base . '#' . strtolower($type), $used_ids);
            }

            $nodes['supporting_' . $index] = $node;
        }

        // Link the page entity to the site and its breadcrumb trail.
        if (isset($nodes['primary'])) {
            if ($website_id !== '' && !isset($nodes['primary']['isPartOf'])) {
                $nodes['primary']['isPartOf'] = ['@id' => $website_id];
            }
            if ($breadcrumb_id !== '' && !isset($nodes['primary']['breadcrumb'])) {
                $nodes['primary']['breadcrumb'] = ['@id' => $breadcrumb_id];
            }

            // Point publisher/author at the full nodes already in the graph.
            // They were emitted inline with no @id, so the graph described the
            // same publisher twice — and the richer node, the one carrying the
            // logo Google needs for Article, was not the one publisher
            // referenced (#471).
            //
            // Only collapse when the inline object names the SAME entity. A post
            // author and the site's Person entity are frequently different
            // people, so matching on position rather than identity would
            // misattribute authorship.
            if (isset($nodes['primary']['publisher'])) {
                $linked = $this->link_to_node($nodes['primary']['publisher'], $organization_nodes);
                if (null !== $linked) {
                    $nodes['primary']['publisher'] = $linked;
                }
            }

            if (isset($nodes['primary']['author'])) {
                $linked = $this->link_to_node($nodes['primary']['author'], $person_nodes);
                if (null !== $linked) {
                    $nodes['primary']['author'] = $linked;
                }
            }
        }

        // The graph carries @context once; per-node copies are redundant.
        foreach ($nodes as $key => $node) {
            unset($node['@context']);
            $nodes[$key] = $node;
        }

        return array_values($nodes);
    }

    /**
     * Pick the page-level entity, plus any deployed alongside it.
     *
     * Precedence arbitrates between *sources*, not between entities: a per-post
     * deployment beats the post-type-wide default, and the losing source is
     * dropped so one URL stops claiming to be several unrelated things (#355).
     *
     * Within the winning source every entity is kept. Deploying more than one
     * page-level schema on a post is exactly what Pro's Multi-Schema feature
     * exists to do (an Article that is also a Recipe), and silently discarding
     * the extras would delete markup the user deliberately published.
     *
     * @since 1.32.0
     * @return array{winner: array|null, siblings: array<int,array>}
     */
    private function select_primary_set(): array {
        if (empty($this->primary_candidates)) {
            return ['winner' => null, 'siblings' => []];
        }

        $best = PHP_INT_MAX;
        foreach ($this->primary_candidates as $candidate) {
            if ($candidate['rank'] < $best) {
                $best = $candidate['rank'];
            }
        }

        $kept = [];
        foreach ($this->primary_candidates as $candidate) {
            if ($candidate['rank'] === $best) {
                $kept[] = $candidate;
            }
        }

        return ['winner' => array_shift($kept), 'siblings' => array_values($kept)];
    }

    /**
     * Find an already-placed node describing the same entity as $node.
     *
     * Identity is `email` when both carry one — two people can share a name,
     * but not a mailbox — and a case-insensitive `name` match otherwise. A node
     * with neither never matches, so an unidentifiable entity is kept rather
     * than folded into an unrelated one.
     *
     * @since 2.0.2
     *
     * @param array  $nodes Nodes placed so far, keyed.
     * @param string $type  Schema type to match within.
     * @param array  $node  Candidate node.
     * @return string|null Key of the matching node, or null.
     */
    private function find_same_entity(array $nodes, string $type, array $node): ?string {
        $email = isset($node['email']) ? strtolower(trim((string) $node['email'])) : '';
        $name  = isset($node['name']) ? trim((string) $node['name']) : '';

        if ('' === $email && '' === $name) {
            return null;
        }

        foreach ($nodes as $key => $placed) {
            if (($placed['@type'] ?? '') !== $type) {
                continue;
            }

            $placed_email = isset($placed['email']) ? strtolower(trim((string) $placed['email'])) : '';

            if ('' !== $email && '' !== $placed_email) {
                if ($email === $placed_email) {
                    return (string) $key;
                }
                continue;
            }

            $placed_name = isset($placed['name']) ? trim((string) $placed['name']) : '';

            if ('' !== $name && '' !== $placed_name && 0 === strcasecmp($name, $placed_name)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Fold a duplicate entity into the node already in the graph.
     *
     * Fills gaps only: a property the placed node already carries wins, so the
     * node that claimed the identity first keeps it, @id included. The
     * duplicate can still contribute properties the first copy lacked, which is
     * the point — between them they describe the entity more completely than
     * either does alone.
     *
     * @since 2.0.2
     *
     * @param array $placed    Node already in the graph.
     * @param array $duplicate Node describing the same entity.
     * @return array Merged node.
     */
    private function merge_entity(array $placed, array $duplicate): array {
        foreach ($duplicate as $key => $value) {
            if ('@id' === $key || '@type' === $key || '@context' === $key) {
                continue;
            }

            if (!isset($placed[$key]) || '' === $placed[$key] || [] === $placed[$key]) {
                $placed[$key] = $value;
            }
        }

        return $placed;
    }

    /**
     * Give a node a unique @id, keeping one it already carries.
     *
     * Two entities of the same type on one page (two deployed Articles, say)
     * would otherwise mint the same @id, which makes the graph ambiguous about
     * which node a reference points at.
     *
     * @since 1.32.0
     * @param array  $node     Node to stamp.
     * @param string $fallback @id to use when the node has none.
     * @param array  $used     Already-issued @id values, updated by reference.
     * @return array
     */
    private function assign_id(array $node, string $fallback, array &$used): array {
        $id = (isset($node['@id']) && is_string($node['@id']) && $node['@id'] !== '')
            ? $node['@id']
            : $fallback;

        if (isset($used[$id])) {
            $suffix = 2;
            while (isset($used[$id . '-' . $suffix])) {
                $suffix++;
            }
            $id .= '-' . $suffix;
        }

        $used[$id]   = true;
        $node['@id'] = $id;

        return $node;
    }

    /**
     * Whether ThinkRank should emit a FAQPage on this request.
     *
     * ThinkRank emitted its FAQPage unconditionally, so a URL whose FAQ was
     * already published by another plugin carried two FAQPage entities — each
     * valid on its own, together ambiguous about which one describes the page
     * (#494).
     *
     * The answer cannot be read off the rendered page. Third-party FAQ schema
     * is typically printed in `wp_footer` from data its widget only gathers
     * while the body renders, which is long after this graph goes out in
     * `wp_head`; at the moment of the decision the foreign FAQPage does not
     * exist yet, in the buffer or anywhere else. Detection therefore inspects
     * the stored post content, the same way collect_elementor_faq() finds
     * ThinkRank's own widget.
     *
     * @since 2.1.0
     * @return bool
     */
    private function should_emit_faqpage(): bool {
        if (null !== $this->emit_faqpage) {
            return $this->emit_faqpage;
        }

        $post = (function_exists('is_singular') && is_singular()) ? get_post() : null;
        if (!$post instanceof \WP_Post) {
            $post = null;
        }

        $emit = !$this->has_foreign_faq_source($post);

        /**
         * Filter whether ThinkRank emits its FAQPage entity.
         *
         * Return false from a plugin that publishes its own FAQPage on the same
         * URL and ThinkRank drops its FAQ node, leaving the page one
         * unambiguous FAQPage. ThinkRank already defaults this to false for the
         * FAQ sources it recognises, so the filter is for the ones it does not
         * — or for forcing its FAQPage back on.
         *
         * @since 2.1.0
         *
         * @param bool          $emit Whether to emit the FAQPage node.
         * @param \WP_Post|null $post Post being viewed, or null when not singular.
         */
        $this->emit_faqpage = (bool) apply_filters('thinkrank_emit_faqpage', $emit, $post);

        return $this->emit_faqpage;
    }

    /**
     * Whether another plugin publishes a FAQPage for this post.
     *
     * @since 2.1.0
     * @param \WP_Post|null $post Post being viewed.
     * @return bool
     */
    private function has_foreign_faq_source(?\WP_Post $post): bool {
        if (!$post instanceof \WP_Post) {
            return false;
        }

        return $this->has_foreign_elementor_faq($post) || $this->has_foreign_bricks_faq($post);
    }

    /**
     * Whether an Elementor widget on this post publishes a FAQPage.
     *
     * @since 2.1.0
     * @param \WP_Post $post Post being viewed.
     * @return bool
     */
    private function has_foreign_elementor_faq(\WP_Post $post): bool {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (empty($raw) || !is_string($raw)) {
            return false;
        }

        $elements = json_decode($raw, true);

        return is_array($elements) && $this->elements_have_foreign_faq($elements);
    }

    /**
     * Whether a Bricks element on this post publishes a FAQPage.
     *
     * Bricks' accordions emit their FAQPage from the body render, so — exactly
     * as with EA's accordion — the stored tree is the only signal available at
     * `wp_head`, where this decision has to be made.
     *
     * The tree comes from Builder_Content rather than a direct meta read: a
     * Bricks page's content can live on a content template, be assembled from
     * components, or be stored but not rendered because the post was switched
     * back to the block editor. Reading the meta key here would get all three
     * wrong (#649).
     *
     * @since 2.3.1
     * @param \WP_Post $post Post being viewed.
     * @return bool
     */
    private function has_foreign_bricks_faq(\WP_Post $post): bool {
        foreach ($this->bricks_tree((int) $post->ID) as $element) {
            if (is_array($element) && $this->bricks_element_publishes_faq($element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one Bricks element will put a FAQPage on the page.
     *
     * Mirrors Bricks' own emission condition rather than trusting the toggle:
     * `accordion` records a question only for an item that has BOTH a title and
     * content, so an armed but empty accordion publishes nothing and must not
     * cost the page ThinkRank's FAQ node. `accordion-nested` builds its items
     * from child elements instead of a repeater, so having children is the
     * equivalent test there.
     *
     * @since 2.3.1
     * @param array $element One Bricks element.
     * @return bool
     */
    private function bricks_element_publishes_faq(array $element): bool {
        $name = is_string($element['name'] ?? null) ? $element['name'] : '';
        if (!in_array($name, self::FOREIGN_FAQ_BRICKS_ELEMENTS, true)) {
            return false;
        }

        $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

        // Bricks writes a checkbox as `true`, and clears it by removing the key.
        if (empty($settings['faqSchema'])) {
            return false;
        }

        if ('accordion-nested' === $name) {
            return !empty($element['children']) && is_array($element['children']);
        }

        $items = is_array($settings['accordions'] ?? null) ? $settings['accordions'] : [];

        foreach ($items as $item) {
            if (is_array($item)
                && '' !== trim((string) ($item['title'] ?? ''))
                && '' !== trim((string) ($item['content'] ?? ''))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Bricks element tree that renders for a post.
     *
     * @since 2.3.1
     * @param int $post_id Post being viewed.
     * @return array<int,mixed>
     */
    private function bricks_tree(int $post_id): array {
        if (!class_exists('ThinkRank\\SEO\\Builder_Content')) {
            $file = THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
            if (!file_exists($file)) {
                return [];
            }
            require_once $file;
        }

        return \ThinkRank\SEO\Builder_Content::bricks_tree($post_id);
    }

    /**
     * Recurse an Elementor element tree looking for a third-party FAQ producer.
     *
     * @since 2.1.0
     * @param array $elements Elementor elements.
     * @return bool
     */
    private function elements_have_foreign_faq(array $elements): bool {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            // Stored JSON, so nothing guarantees the shape: a non-string
            // widgetType would be an illegal array offset, not a miss.
            $widget   = is_string($element['widgetType'] ?? null) ? $element['widgetType'] : '';
            $gate     = self::FOREIGN_FAQ_WIDGETS[$widget] ?? '';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

            if ($gate !== '' && 'yes' === ($settings[$gate] ?? '')) {
                return true;
            }

            if (!empty($element['elements']) && is_array($element['elements'])
                && $this->elements_have_foreign_faq($element['elements'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the single FAQ node, if any questions were collected.
     *
     * Gated on should_emit_faqpage(): every FAQ source in the plugin — the
     * block, the Elementor widget, a deployed row and the post-type default —
     * funnels through here, so this is the one place that can hold the whole
     * plugin's FAQPage back (#494).
     *
     * @since 1.32.0
     * @return array|null
     */
    private function build_faq_node(): ?array {
        if (empty($this->faq_entities) || !$this->should_emit_faqpage()) {
            return null;
        }

        return [
            '@type'      => 'FAQPage',
            'mainEntity' => array_values($this->faq_entities),
        ];
    }

    /**
     * Base URL for @id values.
     *
     * @since 1.32.0
     * @return string
     */
    private function base_url(?array $primary): string {
        if (is_singular()) {
            $permalink = get_permalink();
            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        // Archives are not singular, so fall back to the URL the page entity
        // already resolved for itself. Without this every archive would mint the
        // same "<home>#collectionpage" @id and two categories would collide.
        $url = $primary['schema']['url'] ?? null;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        return home_url('/');
    }
}
