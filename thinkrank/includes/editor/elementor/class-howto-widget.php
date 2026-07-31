<?php

/**
 * Elementor HowTo Widget
 *
 * The Elementor counterpart of the thinkrank/howto Gutenberg block: a
 * step-by-step guide (optional image per step, total time) emitting HowTo
 * JSON-LD.
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
 * HowTo Widget.
 *
 * @since 1.18.0
 */
class Howto_Widget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'thinkrank-howto';
    }

    public function get_title(): string {
        return __('HowTo (ThinkRank)', 'thinkrank');
    }

    public function get_icon(): string {
        return 'eicon-number-field';
    }

    public function get_categories(): array {
        return ['thinkrank'];
    }

    public function get_keywords(): array {
        return ['how to', 'steps', 'instructions', 'schema', 'thinkrank'];
    }

    public function get_style_depends(): array {
        return ['thinkrank-howto-block'];
    }

    protected function register_controls(): void {
        $this->start_controls_section('section_content', [
            'label' => __('Guide', 'thinkrank'),
        ]);

        $this->add_control('heading', [
            'label'       => __('Title', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => __('How to …', 'thinkrank'),
            'label_block' => true,
        ]);

        $this->add_control('heading_tag', [
            'label'   => __('Heading tag', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'h2',
            'options' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => __('Paragraph', 'thinkrank')],
        ]);

        $this->add_control('description', [
            'label' => __('Description', 'thinkrank'),
            'type'  => \Elementor\Controls_Manager::TEXTAREA,
        ]);

        $repeater = new \Elementor\Repeater();
        $repeater->add_control('title', [
            'label'       => __('Step title', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'label_block' => true,
        ]);
        $repeater->add_control('text', [
            'label' => __('Step description', 'thinkrank'),
            'type'  => \Elementor\Controls_Manager::WYSIWYG,
        ]);
        $repeater->add_control('image', [
            'label' => __('Step image', 'thinkrank'),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ]);

        $this->add_control('steps', [
            'label'       => __('Steps', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'default'     => [['title' => '', 'text' => '']],
            'title_field' => '{{{ title }}}',
        ]);

        $this->add_control('show_numbers', [
            'label'   => __('Numbered steps', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_duration', [
            'label' => __('Total time', 'thinkrank'),
        ]);

        foreach (['days' => __('Days', 'thinkrank'), 'hours' => __('Hours', 'thinkrank'), 'minutes' => __('Minutes', 'thinkrank')] as $key => $label) {
            $this->add_control("total_{$key}", [
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::NUMBER,
                'min'     => 0,
                'default' => 0,
            ]);
        }

        $this->end_controls_section();

        $this->start_controls_section('section_schema', [
            'label' => __('Schema', 'thinkrank'),
        ]);

        $this->add_control('output_schema', [
            'label'       => __('Output HowTo schema (JSON-LD)', 'thinkrank'),
            'description' => __('Adds HowTo structured data for rich results.', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => 'yes',
        ]);

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();
        $steps = is_array($settings['steps'] ?? null) ? $settings['steps'] : [];
        $items = array_values(array_filter($steps, static function ($step) {
            return !empty($step['title']) || !empty($step['text']) || !empty($step['image']['url']);
        }));

        if (empty($items)) {
            return;
        }

        $heading_tag = in_array($settings['heading_tag'] ?? 'h2', ['h2', 'h3', 'h4', 'p'], true)
            ? $settings['heading_tag']
            : 'h2';
        $numbered = 'yes' === ($settings['show_numbers'] ?? 'yes');
        $list_tag = $numbered ? 'ol' : 'ul';

        echo '<div class="thinkrank-howto">';

        if (!empty($settings['heading'])) {
            printf(
                '<%1$s class="thinkrank-howto__heading">%2$s</%1$s>',
                esc_html($heading_tag), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html($settings['heading'])
            );
        }

        if (!empty($settings['description'])) {
            echo '<p class="thinkrank-howto__description">' . esc_html($settings['description']) . '</p>';
        }

        $duration = $this->format_duration($settings);
        if ('' !== $duration) {
            echo '<p class="thinkrank-howto__duration"><strong>' . esc_html__('Total time:', 'thinkrank') . '</strong> ' . esc_html($duration) . '</p>';
        }

        echo '<' . $list_tag . ' class="thinkrank-howto__steps">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        foreach ($items as $step) {
            echo '<li class="thinkrank-howto__step">';
            if (!empty($step['title'])) {
                echo '<div class="thinkrank-howto__step-title">' . esc_html((string) $step['title']) . '</div>';
            }
            if (!empty($step['image']['url'])) {
                printf(
                    '<img class="thinkrank-howto__step-image" src="%s" alt="%s" />',
                    esc_url((string) $step['image']['url']),
                    esc_attr((string) get_post_meta((int) ($step['image']['id'] ?? 0), '_wp_attachment_image_alt', true))
                );
            }
            if (!empty($step['text'])) {
                echo '<div class="thinkrank-howto__step-text">' . wp_kses_post((string) $step['text']) . '</div>';
            }
            echo '</li>';
        }
        echo '</' . $list_tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '</div>';

        $this->maybe_render_schema($settings, $items);
    }

    /**
     * Human-readable total time, '' when unset.
     *
     * @param array $settings Widget settings.
     * @return string
     */
    private function format_duration(array $settings): string {
        $days    = max(0, (int) ($settings['total_days'] ?? 0));
        $hours   = max(0, (int) ($settings['total_hours'] ?? 0));
        $minutes = max(0, (int) ($settings['total_minutes'] ?? 0));

        $parts = [];
        if ($days > 0) {
            /* translators: %d: number of days. */
            $parts[] = sprintf(_n('%d day', '%d days', $days, 'thinkrank'), $days);
        }
        if ($hours > 0) {
            /* translators: %d: number of hours. */
            $parts[] = sprintf(_n('%d hour', '%d hours', $hours, 'thinkrank'), $hours);
        }
        if ($minutes > 0) {
            /* translators: %d: number of minutes. */
            $parts[] = sprintf(_n('%d minute', '%d minutes', $minutes, 'thinkrank'), $minutes);
        }

        return implode(', ', $parts);
    }

    /**
     * Emit HowTo JSON-LD on the front end (never in the editor preview).
     *
     * @param array $settings Widget settings.
     * @param array $items    Non-empty steps.
     * @return void
     */
    private function maybe_render_schema(array $settings, array $items): void {
        if ('yes' !== ($settings['output_schema'] ?? 'yes')) {
            return;
        }

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            return;
        }

        $step_entities = [];
        foreach ($items as $step) {
            $title = trim(wp_strip_all_tags((string) ($step['title'] ?? '')));
            $text  = trim(wp_strip_all_tags((string) ($step['text'] ?? '')));
            if ($title === '' && $text === '') {
                continue;
            }

            $entity = ['@type' => 'HowToStep'];
            if ($title !== '' && $text !== '') {
                $entity['name'] = $title;
                $entity['text'] = $text;
            } else {
                $entity['text'] = $text !== '' ? $text : $title;
            }

            if (!empty($step['image']['url'])) {
                $entity['image'] = [
                    '@type' => 'ImageObject',
                    'url'   => esc_url_raw((string) $step['image']['url']),
                ];
            }

            $step_entities[] = $entity;
        }

        if (empty($step_entities)) {
            return;
        }

        $heading = trim(wp_strip_all_tags((string) ($settings['heading'] ?? '')));
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => $heading !== '' ? $heading : get_the_title(),
            'step'     => $step_entities,
        ];

        $description = trim(wp_strip_all_tags((string) ($settings['description'] ?? '')));
        if ($description !== '') {
            $schema['description'] = $description;
        }

        $days    = max(0, (int) ($settings['total_days'] ?? 0));
        $hours   = max(0, (int) ($settings['total_hours'] ?? 0));
        $minutes = max(0, (int) ($settings['total_minutes'] ?? 0));
        if ($days + $hours + $minutes > 0) {
            $schema['totalTime'] = sprintf('P%dDT%dH%dM', $days, $hours, $minutes);
        }

        $json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (false !== $json) {
            echo '<script type="application/ld+json">' . $json . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }
}
