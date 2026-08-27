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
        // Dashicons, for the editor canvas only.
        //
        // Our block UIs use icon-only <Button icon="..."> controls, which
        // render as <span class="dashicons dashicons-...">, so without the
        // font they are present and clickable but have no glyph — the FAQ
        // block's whole per-item action row (add image, move up/down,
        // duplicate, remove) was invisible (#417).
        //
        // Since WP 6.3 the post editor canvas is an iframe, and core mirrors
        // only styles enqueued on THIS hook into it. dashicons is registered
        // by core but never enqueued for that context, and wp-components does
        // not pull it in — enqueueing it on admin_enqueue_scripts or
        // enqueue_block_editor_assets loads it into the parent document,
        // where our buttons are not.
        if (is_admin()) {
            wp_enqueue_style('dashicons');
        }

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

        if (self::FAQ_BLOCK === $name) {
            // Saved markup carries a bare <img src>, because save.js output is
            // what the block validates against and cannot be changed without
            // invalidating every FAQ block already in the wild. Upgrading it
            // here gives srcset/sizes and intrinsic dimensions from the stored
            // attachment id, and drops the image entirely when the attachment
            // has since been deleted (#418).
            $block_content = $this->upgrade_faq_images($block_content, $attrs);
        }

        switch ($name) {
            case self::FAQ_BLOCK:
                // Only the post being viewed may claim to be an FAQPage. On an
                // archive or the blog home the graph's collection pass skips
                // (it is not is_singular()), so absorption never happens and
                // every listed post carrying an FAQ block used to emit its own
                // standalone FAQPage beside a head that already declares
                // CollectionPage — N FAQPage scripts on one URL.
                if (!$this->is_faq_schema_context()) {
                    return $block_content;
                }
                // The request's schema graph already merged this block's questions
                // into its single FAQPage, so emitting here would recreate the
                // duplicate FAQPage the graph exists to prevent (#355).
                if ($this->faq_absorbed_by_graph()) {
                    return $block_content;
                }
                $schema = $this->build_faq_schema($attrs);
                break;
            case self::HOWTO_BLOCK:
                $schema = $this->build_howto_schema($attrs);
                break;
            case self::TOC_BLOCK:
                $schema = $this->build_toc_schema($attrs);
                break;
            default:
                $schema = null;
        }

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
     * Resolve a FAQ item's image to what should actually be rendered.
     *
     * `imageId` was stored from the start but never read — every path used the
     * raw `imageUrl`, so there was no srcset, no intrinsic dimensions (opening
     * an accordion item shifted everything below it), and an attachment
     * deleted from the library left a broken <img> in both the page and the
     * FAQPage JSON-LD (#418).
     *
     * Returns null when there is no image, or when the id names an attachment
     * that no longer exists — which is what makes deletion degrade gracefully
     * instead of publishing a dead URL.
     *
     * @since 2.1.0
     *
     * @param array<string,mixed> $item FAQ item attributes.
     * @return array{id:int,url:string,alt:string,width:int,height:int}|null
     */
    private static function resolve_faq_image(array $item): ?array {
        $id  = isset($item['imageId']) ? (int) $item['imageId'] : 0;
        $url = isset($item['imageUrl']) ? (string) $item['imageUrl'] : '';
        $alt = isset($item['imageAlt']) ? (string) $item['imageAlt'] : '';

        if ($id > 0) {
            $src = wp_get_attachment_image_src($id, 'large');

            if (!is_array($src) || empty($src[0])) {
                // The attachment is gone. A stored imageUrl pointing at it is
                // a dead link, so publish nothing rather than something broken.
                return null;
            }

            if ($alt === '') {
                $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            }

            return [
                'id'     => $id,
                'url'    => (string) $src[0],
                'alt'    => $alt,
                'width'  => (int) ($src[1] ?? 0),
                'height' => (int) ($src[2] ?? 0),
            ];
        }

        if ($url === '') {
            return null;
        }

        // Pre-#418 items, and anything inserted by URL: no id to resolve, so
        // the stored URL is all there is.
        return [
            'id'     => 0,
            'url'    => $url,
            'alt'    => $alt,
            'width'  => 0,
            'height' => 0,
        ];
    }

    /**
     * The <img> appended to an answer's schema text.
     *
     * A per-item image travels inside the answer HTML rather than as a
     * separate ImageObject node. Note this is no longer about Google rich
     * results: FAQ rich results were removed from Search in May 2026 and the
     * supporting documentation retired the following month. The markup is
     * still consumed by other search engines and by LLM crawlers reading the
     * page's structured data, which is why it stays (#418).
     *
     * @since 2.1.0
     *
     * @param array<string,mixed> $item FAQ item attributes.
     * @return string Leading-space-prefixed <img>, or '' when there is none.
     */
    public static function faq_image_markup(array $item): string {
        $image = self::resolve_faq_image($item);

        if (null === $image) {
            return '';
        }

        $markup = ' <img src="' . esc_url($image['url']) . '" alt="' . esc_attr($image['alt']) . '"';

        // Intrinsic dimensions, so a consumer laying the answer out does not
        // have to guess and reflow.
        if ($image['width'] > 0 && $image['height'] > 0) {
            $markup .= ' width="' . $image['width'] . '" height="' . $image['height'] . '"';
        }

        return $markup . ' />';
    }

    /**
     * Re-render the saved FAQ images through the media library.
     *
     * save.js emits a bare <img src>. That output is what the block validates
     * against, so it cannot change without invalidating every FAQ block
     * already saved — the one property the #380 redesign was careful to keep.
     * Rewriting at render time gets srcset/sizes and width/height without
     * touching a single stored post.
     *
     * @since 2.1.0
     *
     * @param string              $content Rendered block HTML.
     * @param array<string,mixed> $attrs   Block attributes.
     * @return string
     */
    private function upgrade_faq_images(string $content, array $attrs): string {
        if (false === strpos($content, 'thinkrank-faq__image')) {
            return $content;
        }

        $faqs = isset($attrs['faqs']) && is_array($attrs['faqs']) ? $attrs['faqs'] : [];
        if (empty($faqs)) {
            return $content;
        }

        // Keyed by the src the saved markup carries, which is what ties a
        // rendered <img> back to the item it came from.
        $by_url = [];
        foreach ($faqs as $item) {
            if (!is_array($item) || empty($item['imageUrl'])) {
                continue;
            }
            $by_url[(string) $item['imageUrl']] = $item;
        }

        if (empty($by_url)) {
            return $content;
        }

        return (string) preg_replace_callback(
            '#<img\b[^>]*\bclass="[^"]*thinkrank-faq__image[^"]*"[^>]*>#i',
            static function (array $found) use ($by_url): string {
                if (!preg_match('#\bsrc="([^"]*)"#i', $found[0], $src)) {
                    return $found[0];
                }

                $stored = html_entity_decode($src[1], ENT_QUOTES, 'UTF-8');
                if (!isset($by_url[$stored])) {
                    return $found[0];
                }

                $image = self::resolve_faq_image($by_url[$stored]);

                // Attachment deleted since: drop the <img> rather than serve
                // a broken one.
                if (null === $image) {
                    return '';
                }

                // No id to resolve (pre-#418 item, or inserted by URL) — the
                // saved markup is already the best available.
                if ($image['id'] <= 0) {
                    return $found[0];
                }

                $rendered = wp_get_attachment_image(
                    $image['id'],
                    'large',
                    false,
                    [
                        'class' => 'thinkrank-faq__image',
                        'alt'   => $image['alt'],
                    ]
                );

                return '' !== $rendered ? $rendered : $found[0];
            },
            $content
        );
    }

    /**
     * Whether this render may emit a page-level FAQPage.
     *
     * True only while rendering the singular post that is actually being
     * viewed. A listing (archive, blog home, search) renders many posts under
     * one URL, and an FAQPage there would describe a document that does not
     * exist. Outside a front-end query — the editor, a REST render — there is no
     * page to describe either.
     *
     * @since 2.0.1
     * @return bool
     */
    private function is_faq_schema_context(): bool {
        if (!function_exists('is_singular') || !is_singular()) {
            return false;
        }

        $queried_id = (int) get_queried_object_id();
        $current_id = (int) get_the_ID();

        // A secondary loop inside a singular template can render other posts;
        // their FAQ content is not this URL's FAQ content.
        return $queried_id > 0 && $queried_id === $current_id;
    }

    /**
     * Whether the schema graph already absorbed this page's FAQ content.
     *
     * Falls back to false whenever the graph never ran, so the block keeps its
     * original standalone behaviour outside a normal front-end render.
     *
     * @since 1.32.0
     * @return bool
     */
    private function faq_absorbed_by_graph(): bool {
        if (!class_exists('ThinkRank\\Frontend\\Schema_Graph')) {
            return false;
        }

        return \ThinkRank\Frontend\Schema_Graph::instance()->absorbed_content_faq();
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

            $text = wp_kses_post($answer);
            $text .= self::faq_image_markup($faq);

            $entities[] = [
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $text,
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
