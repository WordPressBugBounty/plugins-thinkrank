<?php

/**
 * Gutenberg Blocks Manager
 *
 * Registers ThinkRank's editor blocks: enqueues their editor + front-end
 * assets and injects block-level structured data.
 *
 * @package ThinkRank
 * @subpackage Editor
 * @since 1.15.x
 */

declare(strict_types=1);

namespace ThinkRank\Editor;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Blocks Manager.
 *
 * @since 1.15.x
 */
class Blocks_Manager {

    /**
     * FAQ block name.
     */
    private const FAQ_BLOCK = 'thinkrank/faq';

    /**
     * HowTo block name.
     */
    private const HOWTO_BLOCK = 'thinkrank/howto';

    /**
     * TOC block name.
     */
    private const TOC_BLOCK = 'thinkrank/toc';

    /**
     * Block name → webpack asset handle. Each entry builds
     * assets/{handle}.js / .css / .asset.php.
     *
     * @var array<string,string>
     */
    private const BLOCK_ASSETS = [
        self::FAQ_BLOCK   => 'faq-block',
        self::HOWTO_BLOCK => 'howto-block',
        self::TOC_BLOCK   => 'toc-block',
    ];

    /**
     * Wire up hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('enqueue_block_assets', [$this, 'enqueue_block_styles']);
        add_filter('render_block', [$this, 'inject_block_schema'], 10, 2);
    }

    /**
     * Enqueue each block's editor script.
     *
     * @return void
     */
    public function enqueue_editor_assets(): void {
        foreach (self::BLOCK_ASSETS as $handle) {
            $asset_path = THINKRANK_PLUGIN_DIR . "assets/{$handle}.asset.php";
            $asset = file_exists($asset_path)
                ? include $asset_path
                : ['dependencies' => ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'], 'version' => THINKRANK_VERSION];

            wp_enqueue_script(
                "thinkrank-{$handle}",
                THINKRANK_PLUGIN_URL . "assets/{$handle}.js",
                $asset['dependencies'] ?? [],
                $asset['version'] ?? THINKRANK_VERSION,
                true
            );

            wp_set_script_translations("thinkrank-{$handle}", 'thinkrank');
        }
    }

    /**
     * Enqueue block stylesheets where blocks render.
     *
     * Hooked to enqueue_block_assets — not enqueue_block_editor_assets — so
     * core mirrors them into the iframed editor canvas instead of only the
     * editor's outer document. On the front end each loads only when its
     * block is present.
     *
     * @return void
     */
    public function enqueue_block_styles(): void {
        foreach (self::BLOCK_ASSETS as $block_name => $handle) {
            if (!is_admin() && (!function_exists('has_block') || !has_block($block_name))) {
                continue;
            }

            $css = THINKRANK_PLUGIN_DIR . "assets/{$handle}.css";
            if (!file_exists($css)) {
                continue;
            }

            $asset_path = THINKRANK_PLUGIN_DIR . "assets/{$handle}.asset.php";
            $asset = file_exists($asset_path) ? include $asset_path : [];

            wp_enqueue_style(
                "thinkrank-{$handle}",
                THINKRANK_PLUGIN_URL . "assets/{$handle}.css",
                [],
                $asset['version'] ?? THINKRANK_VERSION
            );
        }
    }

    /**
     * Append block-level JSON-LD after a ThinkRank block's rendered output.
     *
     * Done server-side (not in the blocks' save output) so the schema is not
     * stripped by KSES for users without unfiltered_html.
     *
     * @param string $block_content Rendered block HTML.
     * @param array  $block         Parsed block (name + attrs).
     * @return string
     */
    public function inject_block_schema(string $block_content, array $block): string {
        $name  = $block['blockName'] ?? '';
        $attrs = $block['attrs'] ?? [];

        if (!isset(self::BLOCK_ASSETS[$name])) {
            return $block_content;
        }

        // Schema output is on by default; only skip when explicitly disabled.
        if (array_key_exists('outputSchema', $attrs) && false === $attrs['outputSchema']) {
            return $block_content;
        }

        $schema = match ($name) {
            self::FAQ_BLOCK   => $this->build_faq_schema($attrs),
            self::HOWTO_BLOCK => $this->build_howto_schema($attrs),
            self::TOC_BLOCK   => $this->build_toc_schema($attrs),
            default           => null,
        };

        if (null === $schema) {
            return $block_content;
        }

        $json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (false === $json) {
            return $block_content;
        }

        return $block_content . "\n" . '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * FAQPage schema from FAQ block attributes.
     *
     * @param array $attrs Block attributes.
     * @return array|null Schema array, or null when there is nothing to emit.
     */
    private function build_faq_schema(array $attrs): ?array {
        $faqs = $attrs['faqs'] ?? [];
        if (!is_array($faqs) || empty($faqs)) {
            return null;
        }

        $entities = [];
        foreach ($faqs as $faq) {
            $question = isset($faq['question']) ? trim(wp_strip_all_tags((string) $faq['question'])) : '';
            $answer   = isset($faq['answer']) ? trim((string) $faq['answer']) : '';
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
            return null;
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * HowTo schema from HowTo block attributes.
     *
     * Property shape follows Google's HowTo guidelines (and matches what
     * RankMath emits): name, description, totalTime as ISO 8601, and
     * HowToStep entries with name/text/image.
     *
     * @param array $attrs Block attributes.
     * @return array|null Schema array, or null when there is nothing to emit.
     */
    private function build_howto_schema(array $attrs): ?array {
        $steps = $attrs['steps'] ?? [];
        if (!is_array($steps) || empty($steps)) {
            return null;
        }

        $step_entities = [];
        foreach ($steps as $step) {
            $title = isset($step['title']) ? trim(wp_strip_all_tags((string) $step['title'])) : '';
            $text  = isset($step['text']) ? trim(wp_strip_all_tags((string) $step['text'])) : '';
            if ($title === '' && $text === '') {
                continue;
            }

            $entity = ['@type' => 'HowToStep'];
            if ($title !== '' && $text !== '') {
                $entity['name'] = $title;
                $entity['text'] = $text;
            } else {
                // Google requires text; fall back to whichever field is set.
                $entity['text'] = $text !== '' ? $text : $title;
            }

            $image_url = isset($step['imageUrl']) ? esc_url_raw((string) $step['imageUrl']) : '';
            if ($image_url !== '') {
                $entity['image'] = [
                    '@type' => 'ImageObject',
                    'url'   => $image_url,
                ];
            }

            $step_entities[] = $entity;
        }

        if (empty($step_entities)) {
            return null;
        }

        $heading = isset($attrs['heading']) ? trim(wp_strip_all_tags((string) $attrs['heading'])) : '';
        $name    = $heading !== '' ? $heading : get_the_title();

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => $name,
            'step'     => $step_entities,
        ];

        $description = isset($attrs['description']) ? trim(wp_strip_all_tags((string) $attrs['description'])) : '';
        if ($description !== '') {
            $schema['description'] = $description;
        }

        // ISO 8601 duration, e.g. P1DT2H30M — only when a duration was set.
        $days    = max(0, (int) ($attrs['totalDays'] ?? 0));
        $hours   = max(0, (int) ($attrs['totalHours'] ?? 0));
        $minutes = max(0, (int) ($attrs['totalMinutes'] ?? 0));
        if ($days + $hours + $minutes > 0) {
            $schema['totalTime'] = sprintf('P%dDT%dH%dM', $days, $hours, $minutes);
        }

        return $schema;
    }

    /**
     * SiteNavigationElement schema from TOC block attributes (one element per
     * listed section — the shape RankMath's TOC block emits).
     *
     * @param array $attrs Block attributes.
     * @return array|null Schema array, or null when there is nothing to emit.
     */
    private function build_toc_schema(array $attrs): ?array {
        $headings = $attrs['headings'] ?? [];
        if (!is_array($headings) || empty($headings)) {
            return null;
        }

        $permalink = get_permalink();
        if (!is_string($permalink)) {
            $permalink = '';
        }

        $elements = [];
        foreach ($headings as $item) {
            $content = isset($item['content']) ? trim(wp_strip_all_tags((string) $item['content'])) : '';
            $anchor  = isset($item['anchor']) ? trim((string) $item['anchor']) : '';
            if ($content === '' || $anchor === '') {
                continue;
            }

            $elements[] = [
                '@type' => 'SiteNavigationElement',
                'name'  => $content,
                'url'   => $permalink . '#' . $anchor,
            ];
        }

        if (empty($elements)) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $elements,
        ];
    }
}
