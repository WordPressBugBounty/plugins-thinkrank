<?php

/**
 * Bricks HowTo Element
 *
 * The Bricks counterpart of the thinkrank/howto Gutenberg block and the
 * Elementor HowTo widget: a step-by-step guide with an optional image per step
 * and a total time, emitting HowTo JSON-LD (#626).
 *
 * HowTo is not a page-level entity, so unlike FAQPage it never competes for the
 * graph's one page slot and there is nothing to arbitrate — it is printed
 * beside the element, exactly as the block and the Elementor widget do.
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
 * HowTo Element.
 *
 * @since 2.3.1
 */
class Howto_Element extends \Bricks\Element {

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
    public $name = 'thinkrank-howto';

    /**
     * Panel icon.
     *
     * @var string
     */
    public $icon = 'ti-list-ol';

    /**
     * Label shown in the element panel.
     *
     * @return string
     */
    public function get_label(): string {
        return esc_html__('HowTo (ThinkRank)', 'thinkrank');
    }

    /**
     * Panel search terms.
     *
     * @return array
     */
    public function get_keywords(): array {
        return ['how to', 'steps', 'instructions', 'guide', 'schema', 'thinkrank'];
    }

    /**
     * Load the shared block stylesheet, but only on a page using this element.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        wp_enqueue_style('thinkrank-howto-block');
    }

    /**
     * Control groups, so the panel is not one long list.
     *
     * @return void
     */
    public function set_control_groups(): void {
        $this->control_groups['duration'] = [
            'title' => esc_html__('Total time', 'thinkrank'),
            'tab'   => 'content',
        ];

        $this->control_groups['schema'] = [
            'title' => esc_html__('Schema', 'thinkrank'),
            'tab'   => 'content',
        ];
    }

    /**
     * Controls.
     *
     * @return void
     */
    public function set_controls(): void {
        $this->controls['heading'] = [
            'label'       => esc_html__('Title', 'thinkrank'),
            'type'        => 'text',
            'placeholder' => esc_html__('How to …', 'thinkrank'),
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

        $this->controls['description'] = [
            'label' => esc_html__('Description', 'thinkrank'),
            'type'  => 'textarea',
        ];

        $this->controls['steps'] = [
            'label'         => esc_html__('Steps', 'thinkrank'),
            'type'          => 'repeater',
            'titleProperty' => 'title',
            'fields'        => [
                'title' => [
                    'label' => esc_html__('Step title', 'thinkrank'),
                    'type'  => 'text',
                ],
                'text'  => [
                    'label' => esc_html__('Step description', 'thinkrank'),
                    'type'  => 'editor',
                ],
                'image' => [
                    'label' => esc_html__('Step image', 'thinkrank'),
                    'type'  => 'image',
                ],
            ],
            'default'       => [
                [
                    'title' => esc_html__('First step', 'thinkrank'),
                    'text'  => esc_html__('Describe what to do here.', 'thinkrank'),
                ],
            ],
        ];

        $this->controls['showNumbers'] = [
            'label'   => esc_html__('Numbered steps', 'thinkrank'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        foreach (self::duration_labels() as $key => $label) {
            $this->controls["total{$key}"] = [
                'group' => 'duration',
                'label' => $label,
                'type'  => 'number',
                'min'   => 0,
            ];
        }

        $this->controls['outputSchema'] = [
            'group'       => 'schema',
            'label'       => esc_html__('Output HowTo schema (JSON-LD)', 'thinkrank'),
            'type'        => 'checkbox',
            'default'     => true,
            'description' => esc_html__('Adds HowTo structured data for rich results.', 'thinkrank'),
        ];
    }

    /**
     * Render.
     *
     * @return void
     */
    public function render(): void {
        $settings = $this->settings;
        $items    = self::usable_steps($settings);

        if (empty($items)) {
            if (bricks_is_builder_call()) {
                echo '<div ' . $this->render_attributes('_root') . '>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    . esc_html__('Add a step to get started.', 'thinkrank')
                    . '</div>';
            }
            return;
        }

        $this->set_attribute('_root', 'class', 'thinkrank-howto');

        $heading_tag = self::heading_tag($settings);
        $list_tag    = empty($settings['showNumbers']) ? 'ul' : 'ol';

        $output = '<div ' . $this->render_attributes('_root') . '>';

        $heading = trim((string) ($settings['heading'] ?? ''));
        if ('' !== $heading) {
            $output .= sprintf(
                '<%1$s class="thinkrank-howto__heading">%2$s</%1$s>',
                esc_html($heading_tag),
                esc_html($this->render_dynamic_data($heading))
            );
        }

        $description = trim((string) ($settings['description'] ?? ''));
        if ('' !== $description) {
            $output .= '<p class="thinkrank-howto__description">'
                . esc_html($this->render_dynamic_data($description)) . '</p>';
        }

        $duration = self::format_duration($settings);
        if ('' !== $duration) {
            $output .= '<p class="thinkrank-howto__duration"><strong>'
                . esc_html__('Total time:', 'thinkrank') . '</strong> '
                . esc_html($duration) . '</p>';
        }

        $output .= '<' . $list_tag . ' class="thinkrank-howto__steps">';

        foreach ($items as $step) {
            $output .= '<li class="thinkrank-howto__step">';

            $title = trim((string) ($step['title'] ?? ''));
            if ('' !== $title) {
                $output .= '<div class="thinkrank-howto__step-title">'
                    . esc_html($this->render_dynamic_data($title)) . '</div>';
            }

            $image = self::step_image($step);
            if (null !== $image) {
                $output .= sprintf(
                    '<img class="thinkrank-howto__step-image" src="%s" alt="%s" />',
                    esc_url($image['url']),
                    esc_attr($image['alt'])
                );
            }

            $text = trim((string) ($step['text'] ?? ''));
            if ('' !== $text) {
                $output .= '<div class="thinkrank-howto__step-text">'
                    . wp_kses_post(\Bricks\Helpers::parse_editor_content($this->render_dynamic_data($text)))
                    . '</div>';
            }

            $output .= '</li>';
        }

        $output .= '</' . $list_tag . '></div>';

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $this->maybe_render_schema($settings, $items);
    }

    /**
     * The repeater rows worth rendering.
     *
     * @since 2.3.1
     * @param array $settings Element settings.
     * @return array<int,array>
     */
    public static function usable_steps(array $settings): array {
        $steps = is_array($settings['steps'] ?? null) ? $settings['steps'] : [];

        return array_values(array_filter($steps, static function ($step) {
            if (!is_array($step)) {
                return false;
            }

            return !empty($step['title']) || !empty($step['text']) || null !== self::step_image($step);
        }));
    }

    /**
     * A step's image, resolved through the attachment when there is one.
     *
     * Bricks stores `{ id, url, ... }` for an image control. The id is
     * authoritative: it carries the alt text, and it is how a deleted
     * attachment degrades to nothing rather than to a dead URL — the same
     * reasoning as the FAQ block's image handling (#418).
     *
     * @since 2.3.1
     * @param array $step One repeater row.
     * @return array{url:string,alt:string}|null
     */
    private static function step_image(array $step): ?array {
        $image = is_array($step['image'] ?? null) ? $step['image'] : [];
        $id    = (int) ($image['id'] ?? 0);

        if ($id > 0) {
            $url = wp_get_attachment_image_url($id, 'full');
            if (is_string($url) && '' !== $url) {
                return [
                    'url' => $url,
                    'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                ];
            }

            // The id names an attachment that is gone. A stored URL pointing
            // into the same library is gone with it.
            return null;
        }

        $url = trim((string) ($image['url'] ?? ''));

        return '' !== $url ? ['url' => $url, 'alt' => ''] : null;
    }

    /**
     * Human-readable total time, '' when unset.
     *
     * @param array $settings Element settings.
     * @return string
     */
    private static function format_duration(array $settings): string {
        [$days, $hours, $minutes] = self::duration_parts($settings);

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
     * Days, hours and minutes as non-negative ints.
     *
     * @param array $settings Element settings.
     * @return array{0:int,1:int,2:int}
     */
    private static function duration_parts(array $settings): array {
        return [
            max(0, (int) ($settings['totalDays'] ?? 0)),
            max(0, (int) ($settings['totalHours'] ?? 0)),
            max(0, (int) ($settings['totalMinutes'] ?? 0)),
        ];
    }

    /**
     * Duration control keys and their labels.
     *
     * @return array<string,string>
     */
    private static function duration_labels(): array {
        return [
            'Days'    => esc_html__('Days', 'thinkrank'),
            'Hours'   => esc_html__('Hours', 'thinkrank'),
            'Minutes' => esc_html__('Minutes', 'thinkrank'),
        ];
    }

    /**
     * Emit HowTo JSON-LD on the front end.
     *
     * @param array $settings Element settings.
     * @param array $items    Usable steps.
     * @return void
     */
    private function maybe_render_schema(array $settings, array $items): void {
        if (empty($settings['outputSchema'])) {
            return;
        }

        if (bricks_is_builder_call() || bricks_is_builder()) {
            return;
        }

        $step_entities = [];
        foreach ($items as $step) {
            $title = trim(wp_strip_all_tags((string) ($step['title'] ?? '')));
            $text  = trim(wp_strip_all_tags((string) ($step['text'] ?? '')));

            if ('' === $title && '' === $text) {
                continue;
            }

            $entity = ['@type' => 'HowToStep'];
            if ('' !== $title && '' !== $text) {
                $entity['name'] = $title;
                $entity['text'] = $text;
            } else {
                $entity['text'] = '' !== $text ? $text : $title;
            }

            $image = self::step_image($step);
            if (null !== $image) {
                $entity['image'] = [
                    '@type' => 'ImageObject',
                    'url'   => esc_url_raw($image['url']),
                ];
            }

            $step_entities[] = $entity;
        }

        if (empty($step_entities)) {
            return;
        }

        $heading = trim(wp_strip_all_tags((string) ($settings['heading'] ?? '')));
        $schema  = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => '' !== $heading ? $heading : (string) get_the_title(),
            'step'     => $step_entities,
        ];

        $description = trim(wp_strip_all_tags((string) ($settings['description'] ?? '')));
        if ('' !== $description) {
            $schema['description'] = $description;
        }

        [$days, $hours, $minutes] = self::duration_parts($settings);
        if ($days + $hours + $minutes > 0) {
            $schema['totalTime'] = sprintf('P%dDT%dH%dM', $days, $hours, $minutes);
        }

        $json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

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
