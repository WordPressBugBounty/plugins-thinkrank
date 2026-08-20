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

        if ('FAQPage' === $type) {
            $this->add_faq_entities($schema['mainEntity'] ?? []);
            return;
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

        if ('FAQPage' === $this->effective_type($schema, $type)) {
            $this->add_faq_entities($schema['mainEntity'] ?? []);
            return;
        }

        $this->supporting[] = $schema;
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

        $this->collect_block_faq($post);
        $this->collect_elementor_faq($post);
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

            // Mirrors Blocks_Manager::build_faq_schema(): a per-item image is
            // carried inside the answer HTML (Yoast-style).
            $image_url = isset($pair['imageUrl']) ? esc_url((string) $pair['imageUrl']) : '';
            if ($image_url !== '') {
                $image_alt = isset($pair['imageAlt']) ? esc_attr((string) $pair['imageAlt']) : '';
                $text     .= ' <img src="' . $image_url . '" alt="' . $image_alt . '" />';
            }

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

        $website_id    = '';
        $breadcrumb_id = '';

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
     * Build the single FAQ node, if any questions were collected.
     *
     * @since 1.32.0
     * @return array|null
     */
    private function build_faq_node(): ?array {
        if (empty($this->faq_entities)) {
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
