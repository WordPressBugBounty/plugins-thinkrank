<?php

/**
 * Beaver Builder FAQ Module
 *
 * The Beaver counterpart of the thinkrank/faq Gutenberg block, the Elementor
 * FAQ widget and the Bricks FAQ element: an inline Q&A accordion built on
 * native <details>, no JS, with FAQPage JSON-LD (#662).
 *
 * The markup and CSS classes are identical to the block's, so all four builders
 * render the same accordion from the same stylesheet.
 *
 * Schema goes through Schema_Graph, not straight to the page. The graph collects
 * this module's questions from Beaver Builder's stored layout during `wp_head` —
 * see `Schema_Graph::collect_beaver_faq()` — and merges them into the request's
 * one FAQPage. By the time this renders, that has already happened, so emitting
 * here as well would recreate the duplicate the graph exists to prevent (#355).
 * The inline block below is the fallback for requests where no graph is rendered.
 *
 * The class is deliberately in the global namespace with a `ThinkRank_` prefix
 * rather than under `ThinkRank\Editor\Beaver`: Beaver Builder resolves modules
 * by class-name string, derives paths from a `ReflectionClass` over them, and
 * every module in its own tree and its ecosystem is global. Following the host's
 * convention is worth more here than namespace tidiness.
 *
 * @package ThinkRank
 * @subpackage Editor\Beaver
 * @since 2.5.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FAQ Module.
 *
 * @since 2.5.0
 */
class ThinkRank_Beaver_FAQ_Module extends FLBuilderModule {

    /**
     * The module slug, and the settings `type` its stored nodes carry.
     *
     * Shared with `Schema_Graph`, which matches stored layout nodes on it.
     */
    public const SLUG = 'thinkrank-faq';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'name'            => __('FAQ (ThinkRank)', 'thinkrank'),
            'description'     => __('An accordion of questions and answers that emits FAQPage schema.', 'thinkrank'),
            'category'        => __('ThinkRank', 'thinkrank'),
            'slug'            => self::SLUG,
            // `dir`/`url` are passed explicitly. Beaver Builder can infer them
            // from ABSPATH, but that inference breaks when the plugin is
            // symlinked or WordPress is in a subdirectory, and the module would
            // then look for its frontend template under a path that does not
            // exist.
            'dir'             => THINKRANK_PLUGIN_DIR . 'includes/editor/beaver/faq/',
            'url'             => THINKRANK_PLUGIN_URL . 'includes/editor/beaver/faq/',
            // Re-render over AJAX while typing instead of reloading the page.
            'partial_refresh' => true,
        ]);
    }

    /**
     * Render the accordion.
     *
     * Called from `includes/frontend.php`, which is the only entry point
     * Beaver Builder offers.
     *
     * @since 2.5.0
     * @param object|array $settings Module settings.
     * @return void
     */
    public function render_content($settings): void {
        $settings = self::to_array($settings);
        $items    = self::usable_items($settings);

        if (empty($items)) {
            // A module with nothing in it still needs a box in the builder, or
            // the author cannot select what they just dropped on the canvas.
            if (self::in_builder()) {
                echo '<div class="thinkrank-faq">' . esc_html__('Add a question to get started.', 'thinkrank') . '</div>';
            }
            return;
        }

        wp_enqueue_style('thinkrank-faq-block');

        $heading_tag = self::heading_tag($settings);
        $first_open  = !empty($settings['first_open']);

        $output = '<div class="thinkrank-faq">';

        $heading = trim((string) ($settings['heading'] ?? ''));
        if ('' !== $heading) {
            $output .= sprintf(
                '<%1$s class="thinkrank-faq__heading">%2$s</%1$s>',
                esc_html($heading_tag),
                esc_html($heading)
            );
        }

        foreach ($items as $index => $faq) {
            $output .= '<details class="thinkrank-faq__item"' . ($first_open && 0 === $index ? ' open' : '') . '>';
            $output .= '<summary class="thinkrank-faq__question">'
                . esc_html((string) ($faq['question'] ?? ''))
                . '</summary>';
            $output .= '<div class="thinkrank-faq__answer">'
                . wp_kses_post((string) ($faq['answer'] ?? ''))
                . '</div>';
            $output .= '</details>';
        }

        $output .= '</div>';

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $this->maybe_render_schema($settings);
    }

    /**
     * Normalize Beaver Builder settings to an array.
     *
     * Settings arrive as a stdClass, and repeater rows inside them are objects
     * too, so a plain `(array)` cast leaves the rows untouched. Everything
     * downstream — including `Schema_Graph`, which reads the same rows straight
     * out of postmeta — wants arrays all the way down.
     *
     * @since 2.5.0
     * @param mixed $settings Raw settings.
     * @return array<string,mixed>
     */
    public static function to_array($settings): array {
        if (is_object($settings)) {
            $settings = get_object_vars($settings);
        }

        if (!is_array($settings)) {
            return [];
        }

        foreach ($settings as $key => $value) {
            if (is_object($value) || is_array($value)) {
                $settings[$key] = json_decode((string) wp_json_encode($value), true) ?? [];
            }
        }

        return $settings;
    }

    /**
     * The repeater rows worth rendering.
     *
     * Public so `Schema_Graph` reads the module exactly as it renders — one
     * definition of "a usable FAQ item" for both the page and its schema.
     *
     * @since 2.5.0
     * @param array $settings Module settings.
     * @return array<int,array>
     */
    public static function usable_items(array $settings): array {
        $faqs = is_array($settings['faqs'] ?? null) ? $settings['faqs'] : [];

        return array_values(array_filter($faqs, static function ($faq) {
            return is_array($faq) && (!empty($faq['question']) || !empty($faq['answer']));
        }));
    }

    /**
     * FAQPage question entities from this module's settings.
     *
     * Shared with the graph's collection pass so a question absorbed there and
     * one printed here can never disagree.
     *
     * @since 2.5.0
     * @param array $settings Module settings.
     * @return array<int,array>
     */
    public static function question_entities(array $settings): array {
        $entities = [];

        foreach (self::usable_items($settings) as $faq) {
            $question = trim(wp_strip_all_tags((string) ($faq['question'] ?? '')));
            $answer   = trim((string) ($faq['answer'] ?? ''));

            if ('' === $question || '' === $answer) {
                continue;
            }

            $entities[] = [
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_kses_post($answer),
                ],
            ];
        }

        return $entities;
    }

    /**
     * Whether a module's settings arm its FAQ schema.
     *
     * Beaver Builder writes an unchecked checkbox as the string `'0'`, not an
     * absent key, so `empty()` is the correct test and a cleared toggle really
     * does clear. This differs from Bricks, where clearing drops the key
     * entirely — the same-looking check has a different reason behind it in
     * each builder.
     *
     * @since 2.5.0
     * @param array $settings Module settings.
     * @return bool
     */
    public static function schema_enabled(array $settings): bool {
        return !empty($settings['output_schema']);
    }

    /**
     * Whether Beaver Builder's editor is rendering this.
     *
     * Covers both the builder page itself and the AJAX partial refreshes it
     * fires while the author types.
     *
     * @since 2.5.0
     * @return bool
     */
    private static function in_builder(): bool {
        return class_exists('\\FLBuilderModel') && (bool) \FLBuilderModel::is_builder_active();
    }

    /**
     * Emit FAQPage JSON-LD, unless somebody else already has.
     *
     * @param array $settings Module settings.
     * @return void
     */
    private function maybe_render_schema(array $settings): void {
        if (!self::schema_enabled($settings)) {
            return;
        }

        // Never in the builder canvas: the author is looking at a preview, and
        // Beaver Builder re-renders modules over AJAX as they type.
        if (self::in_builder()) {
            return;
        }

        // The graph collected this module's questions during wp_head and owns
        // the page's single FAQPage now (#355). Either way this module stays
        // quiet.
        if (class_exists('ThinkRank\\Frontend\\Schema_Graph')
            && \ThinkRank\Frontend\Schema_Graph::instance()->absorbed_content_faq()
        ) {
            return;
        }

        $entities = self::question_entities($settings);
        if (empty($entities)) {
            return;
        }

        $json = wp_json_encode([
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if (false !== $json) {
            echo '<script type="application/ld+json">' . $json . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * A heading tag from the allowed set.
     *
     * @param array $settings Module settings.
     * @return string
     */
    private static function heading_tag(array $settings): string {
        $tag = (string) ($settings['heading_tag'] ?? 'h2');

        return in_array($tag, ['h2', 'h3', 'h4', 'p'], true) ? $tag : 'h2';
    }
}

/**
 * The repeater row form. Registered separately because Beaver Builder resolves
 * a `type => form` field by id, unlike Elementor's and Bricks' inline repeaters.
 */
FLBuilder::register_settings_form('thinkrank_faq_item_form', [
    'title' => __('Question', 'thinkrank'),
    'tabs'  => [
        'general' => [
            'title'    => __('General', 'thinkrank'),
            'sections' => [
                'general' => [
                    'title'  => '',
                    'fields' => [
                        'question' => [
                            'type'        => 'text',
                            'label'       => __('Question', 'thinkrank'),
                            'connections' => ['string'],
                        ],
                        'answer'   => [
                            'type'        => 'editor',
                            'label'       => __('Answer', 'thinkrank'),
                            'media_buttons' => false,
                            'connections' => ['string'],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

FLBuilder::register_module('ThinkRank_Beaver_FAQ_Module', [
    'general' => [
        'title'    => __('Questions', 'thinkrank'),
        'sections' => [
            'content' => [
                'title'  => '',
                'fields' => [
                    'heading'     => [
                        'type'        => 'text',
                        'label'       => __('Section heading', 'thinkrank'),
                        'default'     => __('Frequently asked questions', 'thinkrank'),
                        'connections' => ['string'],
                    ],
                    'heading_tag' => [
                        'type'    => 'select',
                        'label'   => __('Heading tag', 'thinkrank'),
                        'default' => 'h2',
                        'options' => [
                            'h2' => 'H2',
                            'h3' => 'H3',
                            'h4' => 'H4',
                            'p'  => __('Paragraph', 'thinkrank'),
                        ],
                    ],
                    'faqs'        => [
                        'type'         => 'form',
                        'label'        => __('Question', 'thinkrank'),
                        'form'         => 'thinkrank_faq_item_form',
                        'preview_text' => 'question',
                        'multiple'     => true,
                    ],
                    'first_open'  => [
                        'type'    => 'select',
                        'label'   => __('Open first item by default', 'thinkrank'),
                        'default' => '1',
                        'options' => [
                            '1' => __('Yes', 'thinkrank'),
                            '0' => __('No', 'thinkrank'),
                        ],
                    ],
                ],
            ],
        ],
    ],
    'schema'  => [
        'title'    => __('Schema', 'thinkrank'),
        'sections' => [
            'schema' => [
                'title'  => '',
                'fields' => [
                    'output_schema' => [
                        'type'    => 'select',
                        'label'   => __('Output FAQ schema (JSON-LD)', 'thinkrank'),
                        'default' => '1',
                        'options' => [
                            '1' => __('Yes', 'thinkrank'),
                            '0' => __('No', 'thinkrank'),
                        ],
                        'help'    => __('Adds FAQPage structured data for rich results.', 'thinkrank'),
                    ],
                ],
            ],
        ],
    ],
]);
