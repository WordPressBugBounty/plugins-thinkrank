<?php

/**
 * Beaver Builder HowTo Module
 *
 * The Beaver counterpart of the thinkrank/howto Gutenberg block, the Elementor
 * HowTo widget and the Bricks HowTo element: ordered steps with optional images
 * and a total time, emitting HowTo JSON-LD (#662).
 *
 * Markup and CSS classes match the block's, so all four builders render from
 * the same stylesheet.
 *
 * Unlike FAQ, HowTo is not collected by `Schema_Graph`: the graph de-duplicates
 * FAQPage because a page can gather questions from several sources, and no
 * equivalent exists for HowTo. This module therefore owns its own JSON-LD, the
 * same arrangement the Bricks element uses.
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
 * HowTo Module.
 *
 * @since 2.5.0
 */
class ThinkRank_Beaver_Howto_Module extends FLBuilderModule {

    /**
     * The module slug, and the settings `type` its stored nodes carry.
     */
    public const SLUG = 'thinkrank-howto';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'name'            => __('HowTo (ThinkRank)', 'thinkrank'),
            'description'     => __('A numbered list of steps that emits HowTo schema.', 'thinkrank'),
            'category'        => __('ThinkRank', 'thinkrank'),
            'slug'            => self::SLUG,
            'dir'             => THINKRANK_PLUGIN_DIR . 'includes/editor/beaver/howto/',
            'url'             => THINKRANK_PLUGIN_URL . 'includes/editor/beaver/howto/',
            'partial_refresh' => true,
        ]);
    }

    /**
     * Render the steps.
     *
     * @since 2.5.0
     * @param object|array $settings Module settings.
     * @return void
     */
    public function render_content($settings): void {
        $settings = ThinkRank_Beaver_FAQ_Module::to_array($settings);
        $items    = self::usable_steps($settings);

        if (empty($items)) {
            if (self::in_builder()) {
                echo '<div class="thinkrank-howto">' . esc_html__('Add a step to get started.', 'thinkrank') . '</div>';
            }
            return;
        }

        wp_enqueue_style('thinkrank-howto-block');

        $heading_tag = self::heading_tag($settings);
        $list_tag    = empty($settings['show_numbers']) ? 'ul' : 'ol';

        $output = '<div class="thinkrank-howto">';

        $heading = trim((string) ($settings['heading'] ?? ''));
        if ('' !== $heading) {
            $output .= sprintf(
                '<%1$s class="thinkrank-howto__heading">%2$s</%1$s>',
                esc_html($heading_tag),
                esc_html($heading)
            );
        }

        $description = trim((string) ($settings['description'] ?? ''));
        if ('' !== $description) {
            $output .= '<p class="thinkrank-howto__description">' . esc_html($description) . '</p>';
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
                $output .= '<div class="thinkrank-howto__step-title">' . esc_html($title) . '</div>';
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
                $output .= '<div class="thinkrank-howto__step-text">' . wp_kses_post($text) . '</div>';
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
     * @since 2.5.0
     * @param array $settings Module settings.
     * @return array<int,array>
     */
    public static function usable_steps(array $settings): array {
        $steps = is_array($settings['steps'] ?? null) ? $settings['steps'] : [];

        return array_values(array_filter($steps, static function ($step) {
            return is_array($step) && (!empty($step['title']) || !empty($step['text']) || !empty($step['image']));
        }));
    }

    /**
     * A step's image, resolved to a url and alt text.
     *
     * Beaver Builder's photo field stores an attachment id, so the url is
     * looked up rather than read off the setting.
     *
     * @since 2.5.0
     * @param array $step One step row.
     * @return array{url:string,alt:string}|null
     */
    private static function step_image(array $step): ?array {
        $id = (int) ($step['image'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $url = wp_get_attachment_image_url($id, 'large');
        if (!$url) {
            return null;
        }

        return [
            'url' => (string) $url,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }

    /**
     * The duration fields as [days, hours, minutes].
     *
     * @since 2.5.0
     * @param array $settings Module settings.
     * @return array{0:int,1:int,2:int}
     */
    private static function duration_parts(array $settings): array {
        return [
            max(0, (int) ($settings['total_days'] ?? 0)),
            max(0, (int) ($settings['total_hours'] ?? 0)),
            max(0, (int) ($settings['total_minutes'] ?? 0)),
        ];
    }

    /**
     * The duration as human-readable text, or '' when nothing is set.
     *
     * @since 2.5.0
     * @param array $settings Module settings.
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

        return implode(' ', $parts);
    }

    /**
     * Whether Beaver Builder's editor is rendering this.
     *
     * @since 2.5.0
     * @return bool
     */
    private static function in_builder(): bool {
        return class_exists('\\FLBuilderModel') && (bool) \FLBuilderModel::is_builder_active();
    }

    /**
     * Emit HowTo JSON-LD on the front end.
     *
     * @param array $settings Module settings.
     * @param array $items    Usable steps.
     * @return void
     */
    private function maybe_render_schema(array $settings, array $items): void {
        if (empty($settings['output_schema']) || self::in_builder()) {
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
     * @param array $settings Module settings.
     * @return string
     */
    private static function heading_tag(array $settings): string {
        $tag = (string) ($settings['heading_tag'] ?? 'h2');

        return in_array($tag, ['h2', 'h3', 'h4', 'p'], true) ? $tag : 'h2';
    }
}

FLBuilder::register_settings_form('thinkrank_howto_step_form', [
    'title' => __('Step', 'thinkrank'),
    'tabs'  => [
        'general' => [
            'title'    => __('General', 'thinkrank'),
            'sections' => [
                'general' => [
                    'title'  => '',
                    'fields' => [
                        'title' => [
                            'type'        => 'text',
                            'label'       => __('Step title', 'thinkrank'),
                            'connections' => ['string'],
                        ],
                        'text'  => [
                            'type'          => 'editor',
                            'label'         => __('Step description', 'thinkrank'),
                            'media_buttons' => false,
                            'connections'   => ['string'],
                        ],
                        'image' => [
                            'type'        => 'photo',
                            'label'       => __('Step image', 'thinkrank'),
                            'show_remove' => true,
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

FLBuilder::register_module('ThinkRank_Beaver_Howto_Module', [
    'general'  => [
        'title'    => __('Steps', 'thinkrank'),
        'sections' => [
            'content' => [
                'title'  => '',
                'fields' => [
                    'heading'      => [
                        'type'        => 'text',
                        'label'       => __('Title', 'thinkrank'),
                        'placeholder' => __('How to …', 'thinkrank'),
                        'connections' => ['string'],
                    ],
                    'heading_tag'  => [
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
                    'description'  => [
                        'type'        => 'textarea',
                        'label'       => __('Description', 'thinkrank'),
                        'rows'        => 4,
                        'connections' => ['string'],
                    ],
                    'steps'        => [
                        'type'         => 'form',
                        'label'        => __('Step', 'thinkrank'),
                        'form'         => 'thinkrank_howto_step_form',
                        'preview_text' => 'title',
                        'multiple'     => true,
                    ],
                    'show_numbers' => [
                        'type'    => 'select',
                        'label'   => __('Numbered steps', 'thinkrank'),
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
    'duration' => [
        'title'    => __('Duration', 'thinkrank'),
        'sections' => [
            'duration' => [
                'title'  => '',
                'fields' => [
                    'total_days'    => ['type' => 'unit', 'label' => __('Days', 'thinkrank'), 'slider' => false],
                    'total_hours'   => ['type' => 'unit', 'label' => __('Hours', 'thinkrank'), 'slider' => false],
                    'total_minutes' => ['type' => 'unit', 'label' => __('Minutes', 'thinkrank'), 'slider' => false],
                ],
            ],
        ],
    ],
    'schema'   => [
        'title'    => __('Schema', 'thinkrank'),
        'sections' => [
            'schema' => [
                'title'  => '',
                'fields' => [
                    'output_schema' => [
                        'type'    => 'select',
                        'label'   => __('Output HowTo schema (JSON-LD)', 'thinkrank'),
                        'default' => '1',
                        'options' => [
                            '1' => __('Yes', 'thinkrank'),
                            '0' => __('No', 'thinkrank'),
                        ],
                        'help'    => __('Adds HowTo structured data for rich results.', 'thinkrank'),
                    ],
                ],
            ],
        ],
    ],
]);
