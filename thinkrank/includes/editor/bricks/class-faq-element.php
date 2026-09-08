<?php

/**
 * Bricks FAQ Element
 *
 * The Bricks counterpart of the thinkrank/faq Gutenberg block and the Elementor
 * FAQ widget: an inline Q&A accordion built on native <details>, no JS, with
 * FAQPage JSON-LD (#626).
 *
 * The markup and CSS classes are identical to the block's, so the three
 * builders render the same accordion from the same stylesheet.
 *
 * Schema goes through Schema_Graph, not straight to the page. The graph collects
 * this element's questions from Bricks' stored tree during `wp_head` — see
 * `Schema_Graph::collect_bricks_faq()` — and merges them into the request's one
 * FAQPage. By the time this renders, that has already happened, so emitting here
 * as well would recreate the duplicate the graph exists to prevent (#355). The
 * inline script below is the fallback for the requests where no graph is
 * rendered at all.
 *
 * @package ThinkRank
 * @subpackage Editor\Bricks
 * @since 2.3.1
 */

declare(strict_types=1);

namespace ThinkRank\Editor\Bricks;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FAQ Element.
 *
 * @since 2.3.1
 */
class FAQ_Element extends \Bricks\Element {

    /**
     * Builder category.
     *
     * @var string
     */
    public $category = 'thinkrank';

    /**
     * Element name, matching the Elementor widget's.
     *
     * @var string
     */
    public $name = 'thinkrank-faq';

    /**
     * Panel icon (Themify, which Bricks bundles).
     *
     * @var string
     */
    public $icon = 'ti-help-alt';

    /**
     * Label shown in the element panel.
     *
     * @return string
     */
    public function get_label(): string {
        return esc_html__('FAQ (ThinkRank)', 'thinkrank');
    }

    /**
     * Panel search terms.
     *
     * @return array
     */
    public function get_keywords(): array {
        return ['faq', 'questions', 'accordion', 'schema', 'thinkrank'];
    }

    /**
     * Load the shared block stylesheet, but only on a page using this element.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        wp_enqueue_style('thinkrank-faq-block');
    }

    /**
     * Controls.
     *
     * @return void
     */
    public function set_controls(): void {
        $this->controls['heading'] = [
            'label'   => esc_html__('Section heading', 'thinkrank'),
            'type'    => 'text',
            'default' => esc_html__('Frequently asked questions', 'thinkrank'),
        ];

        $this->controls['headingTag'] = [
            'label'   => esc_html__('Heading tag', 'thinkrank'),
            'type'    => 'select',
            'options' => [
                'h2' => 'H2',
                'h3' => 'H3',
                'h4' => 'H4',
                'p'  => esc_html__('Paragraph', 'thinkrank'),
            ],
            'default' => 'h2',
            'inline'  => true,
        ];

        $this->controls['faqs'] = [
            'label'         => esc_html__('Questions', 'thinkrank'),
            'type'          => 'repeater',
            'titleProperty' => 'question',
            'fields'        => [
                'question' => [
                    'label' => esc_html__('Question', 'thinkrank'),
                    'type'  => 'text',
                ],
                'answer'   => [
                    'label' => esc_html__('Answer', 'thinkrank'),
                    'type'  => 'editor',
                ],
            ],
            'default'       => [
                [
                    'question' => esc_html__('What is your refund policy?', 'thinkrank'),
                    'answer'   => esc_html__('Describe the answer here.', 'thinkrank'),
                ],
            ],
        ];

        $this->controls['firstOpen'] = [
            'label'   => esc_html__('Open first item by default', 'thinkrank'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['outputSchema'] = [
            'label'       => esc_html__('Output FAQ schema (JSON-LD)', 'thinkrank'),
            'type'        => 'checkbox',
            'default'     => true,
            'description' => esc_html__('Adds FAQPage structured data for rich results.', 'thinkrank'),
        ];
    }

    /**
     * Render.
     *
     * @return void
     */
    public function render(): void {
        $settings = $this->settings;
        $items    = self::usable_items($settings);

        if (empty($items)) {
            // An element with nothing in it still needs a box in the builder,
            // or the author cannot select what they just dropped on the canvas.
            if (bricks_is_builder_call()) {
                echo '<div ' . $this->render_attributes('_root') . '>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    . esc_html__('Add a question to get started.', 'thinkrank')
                    . '</div>';
            }
            return;
        }

        $this->set_attribute('_root', 'class', 'thinkrank-faq');

        $heading_tag  = self::heading_tag($settings);
        $first_open   = !empty($settings['firstOpen']);

        $output = '<div ' . $this->render_attributes('_root') . '>';

        $heading = trim((string) ($settings['heading'] ?? ''));
        if ('' !== $heading) {
            $output .= sprintf(
                '<%1$s class="thinkrank-faq__heading">%2$s</%1$s>',
                esc_html($heading_tag),
                esc_html($this->render_dynamic_data($heading))
            );
        }

        foreach ($items as $index => $faq) {
            $answer = \Bricks\Helpers::parse_editor_content(
                $this->render_dynamic_data((string) ($faq['answer'] ?? ''))
            );

            $output .= '<details class="thinkrank-faq__item"' . ($first_open && 0 === $index ? ' open' : '') . '>';
            $output .= '<summary class="thinkrank-faq__question">'
                . esc_html($this->render_dynamic_data((string) ($faq['question'] ?? '')))
                . '</summary>';
            $output .= '<div class="thinkrank-faq__answer">' . wp_kses_post($answer) . '</div>';
            $output .= '</details>';
        }

        $output .= '</div>';

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $this->maybe_render_schema($settings, $items);
    }

    /**
     * The repeater rows worth rendering.
     *
     * Public so `Schema_Graph` reads the element exactly as it renders — one
     * definition of "a usable FAQ item" for both the page and its schema.
     *
     * @since 2.3.1
     * @param array $settings Element settings.
     * @return array<int,array>
     */
    public static function usable_items(array $settings): array {
        $faqs = is_array($settings['faqs'] ?? null) ? $settings['faqs'] : [];

        return array_values(array_filter($faqs, static function ($faq) {
            return is_array($faq) && (!empty($faq['question']) || !empty($faq['answer']));
        }));
    }

    /**
     * FAQPage question entities from this element's settings.
     *
     * Shared with the graph's collection pass so a question absorbed there and
     * one printed here can never disagree.
     *
     * @since 2.3.1
     * @param array $settings Element settings.
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
     * Whether an element's settings arm its FAQ schema.
     *
     * Bricks stores a checkbox as `true` and clears it by dropping the key
     * entirely, so an absent value is "off" — the control's `'default' => true`
     * is written into the element's settings when it is created, which is why
     * every Bricks element tests these with `isset()` rather than defaulting in
     * PHP (see `animated-typing.php:185`). Defaulting to on here would make the
     * toggle unclearable.
     *
     * @since 2.3.1
     * @param array $settings Element settings.
     * @return bool
     */
    public static function schema_enabled(array $settings): bool {
        return !empty($settings['outputSchema']);
    }

    /**
     * Emit FAQPage JSON-LD, unless somebody else already has.
     *
     * @param array $settings Element settings.
     * @param array $items    Usable FAQ rows.
     * @return void
     */
    private function maybe_render_schema(array $settings, array $items): void {
        unset($items);

        if (!self::schema_enabled($settings)) {
            return;
        }

        // Never in the builder canvas: the author is looking at a preview, and
        // Bricks re-renders elements over AJAX as they type.
        if (bricks_is_builder_call() || bricks_is_builder()) {
            return;
        }

        // The graph collected this element's questions during wp_head and owns
        // the page's single FAQPage now (#355) — or a Bricks accordion owns it
        // and the graph stood down (#649). Either way this element stays quiet.
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
     * @param array $settings Element settings.
     * @return string
     */
    private static function heading_tag(array $settings): string {
        $tag = (string) ($settings['headingTag'] ?? 'h2');

        return in_array($tag, ['h2', 'h3', 'h4', 'p'], true) ? $tag : 'h2';
    }
}
