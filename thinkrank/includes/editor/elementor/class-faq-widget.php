<?php

/**
 * Elementor FAQ Widget
 *
 * The Elementor counterpart of the thinkrank/faq Gutenberg block: an inline
 * Q&A accordion (native <details>, no JS) emitting FAQPage JSON-LD.
 *
 * @package ThinkRank
 * @subpackage Editor\Elementor
 * @since 1.18.0
 */

declare(strict_types=1);

namespace ThinkRank\Editor\Elementor;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FAQ Widget.
 *
 * @since 1.18.0
 */
class FAQ_Widget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'thinkrank-faq';
    }

    public function get_title(): string {
        return __('FAQ (ThinkRank)', 'thinkrank');
    }

    public function get_icon(): string {
        return 'eicon-help-o';
    }

    public function get_categories(): array {
        return ['thinkrank'];
    }

    public function get_keywords(): array {
        return ['faq', 'questions', 'accordion', 'schema', 'thinkrank'];
    }

    public function get_style_depends(): array {
        return ['thinkrank-faq-block'];
    }

    protected function register_controls(): void {
        $this->start_controls_section('section_content', [
            'label' => __('FAQ', 'thinkrank'),
        ]);

        $this->add_control('heading', [
            'label'       => __('Section heading', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => __('Frequently asked questions', 'thinkrank'),
            'label_block' => true,
        ]);

        $this->add_control('heading_tag', [
            'label'   => __('Heading tag', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'h2',
            'options' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => __('Paragraph', 'thinkrank')],
        ]);

        $repeater = new \Elementor\Repeater();
        $repeater->add_control('question', [
            'label'       => __('Question', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'label_block' => true,
        ]);
        $repeater->add_control('answer', [
            'label' => __('Answer', 'thinkrank'),
            'type'  => \Elementor\Controls_Manager::WYSIWYG,
        ]);

        $this->add_control('faqs', [
            'label'       => __('Questions', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'default'     => [['question' => '', 'answer' => '']],
            'title_field' => '{{{ question }}}',
        ]);

        $this->add_control('first_open', [
            'label'   => __('Open first item by default', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('output_schema', [
            'label'       => __('Output FAQ schema (JSON-LD)', 'thinkrank'),
            'description' => __('Adds FAQPage structured data for rich results.', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
        ]);

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();
        $faqs = is_array($settings['faqs'] ?? null) ? $settings['faqs'] : [];
        $items = array_values(array_filter($faqs, static function ($faq) {
            return !empty($faq['question']) || !empty($faq['answer']);
        }));

        if (empty($items)) {
            return;
        }

        $heading_tag = in_array($settings['heading_tag'] ?? 'h2', ['h2', 'h3', 'h4', 'p'], true)
            ? $settings['heading_tag']
            : 'h2';

        echo '<div class="thinkrank-faq">';

        if (!empty($settings['heading'])) {
            printf(
                '<%1$s class="thinkrank-faq__heading">%2$s</%1$s>',
                esc_html($heading_tag), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html($settings['heading'])
            );
        }

        foreach ($items as $index => $faq) {
            $open = ('yes' === ($settings['first_open'] ?? '')) && 0 === $index ? ' open' : '';
            echo '<details class="thinkrank-faq__item"' . $open . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<summary class="thinkrank-faq__question">' . esc_html((string) ($faq['question'] ?? '')) . '</summary>';
            echo '<div class="thinkrank-faq__answer">' . wp_kses_post((string) ($faq['answer'] ?? '')) . '</div>';
            echo '</details>';
        }

        echo '</div>';

        $this->maybe_render_schema($settings, $items);
    }

    /**
     * Emit FAQPage JSON-LD on the front end (never in the editor preview).
     *
     * @param array $settings Widget settings.
     * @param array $items    Non-empty FAQ items.
     * @return void
     */
    private function maybe_render_schema(array $settings, array $items): void {
        if ('yes' !== ($settings['output_schema'] ?? 'yes')) {
            return;
        }

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            return;
        }

        $entities = [];
        foreach ($items as $faq) {
            $question = trim(wp_strip_all_tags((string) ($faq['question'] ?? '')));
            $answer   = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
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
}
