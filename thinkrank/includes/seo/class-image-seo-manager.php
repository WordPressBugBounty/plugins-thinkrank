<?php

/**
 * Image SEO Manager Class
 *
 * Manages image-specific SEO settings including automatic
 * ALT and TITLE attribute management.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Image SEO Manager Class
 *
 * Handles image attribute optimization and settings management.
 *
 * @since 1.0.0
 */
class Image_SEO_Manager extends Abstract_SEO_Manager {

    /**
     * Memoized site separator symbol.
     *
     * Resolved once per request rather than on every image processed during
     * the_content, since the separator is a site-wide option.
     *
     * @since 1.16.0
     * @var string|null
     */
    private ?string $separator = null;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('image_seo');
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        $boolean_fields = ['add_missing_alt', 'add_missing_title', 'save_alt_to_media', 'auto_fill_on_upload', 'media_alt_overwrite'];
        foreach ($boolean_fields as $field) {
            if (isset($settings[$field]) && !is_bool($settings[$field])) {
                $validation['errors'][] = sprintf('%s must be a boolean value', $field);
                $validation['valid'] = false;
            }
        }

        $string_fields = ['alt_format', 'title_format'];
        foreach ($string_fields as $field) {
            if (isset($settings[$field]) && !is_string($settings[$field])) {
                $validation['errors'][] = sprintf('%s must be a string', $field);
                $validation['valid'] = false;
            }
        }

        return $validation;
    }

    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        return $this->get_settings($context_type, $context_id);
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        return [
            'add_missing_alt' => false,
            'alt_format' => '%filename%',
            'add_missing_title' => false,
            'title_format' => '%title% %separator% %sitename%',
            // Media Library alt persistence (writes _wp_attachment_image_alt)
            'save_alt_to_media' => false,
            'auto_fill_on_upload' => false,
            'media_alt_overwrite' => false,
        ];
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'add_missing_alt' => [
                'type' => 'boolean',
                'title' => __('Add Missing Alt Attributes', 'thinkrank'),
                'description' => __('Automatically add ALT attributes to images if they are missing.', 'thinkrank'),
                'default' => false
            ],
            'alt_format' => [
                'type' => 'string',
                'title' => __('Alt attribute format', 'thinkrank'),
                'description' => __('The format to use for automatically generated ALT attributes.', 'thinkrank'),
                'default' => '%filename%'
            ],
            'add_missing_title' => [
                'type' => 'boolean',
                'title' => __('Add Missing Title Attributes', 'thinkrank'),
                'description' => __('Automatically add TITLE attributes to images if they are missing.', 'thinkrank'),
                'default' => false
            ],
            'title_format' => [
                'type' => 'string',
                'title' => __('Title attribute format', 'thinkrank'),
                'description' => __('The format to use for automatically generated TITLE attributes.', 'thinkrank'),
                'default' => '%title% %separator% %sitename%'
            ],
            'save_alt_to_media' => [
                'type' => 'boolean',
                'title' => __('Save alt text to the Media Library', 'thinkrank'),
                'description' => __('Persist generated alt text onto the attachment record so it works everywhere, not just in rendered content.', 'thinkrank'),
                'default' => false
            ],
            'auto_fill_on_upload' => [
                'type' => 'boolean',
                'title' => __('Fill alt text on upload', 'thinkrank'),
                'description' => __('When a new image is uploaded, automatically save generated alt text to the Media Library.', 'thinkrank'),
                'default' => false
            ],
            'media_alt_overwrite' => [
                'type' => 'boolean',
                'title' => __('Overwrite existing alt text', 'thinkrank'),
                'description' => __('Replace alt text that is already set, instead of only filling images that are missing it.', 'thinkrank'),
                'default' => false
            ]
        ];
    }

    /**
     * Process content and inject missing image attributes
     *
     * @since 1.0.0
     * @param string $content The content to process
     * @return string Processed content
     */
    public function process_content(string $content, $post_id = null): string {
        $settings = $this->get_settings('site');

        if (empty($settings['add_missing_alt']) && empty($settings['add_missing_title'])) {
            return $content;
        }

        static $count = 0;
        $post_id ??= get_the_ID();
        $id_to_pass = is_int($post_id) ? $post_id : 0;

        // Use regex for high performance, but careful with HTML structure
        return preg_replace_callback('/<img([^>]+)>/i', function ($matches) use ($settings, &$count, $id_to_pass) {
            $count++;
            $img_tag = $matches[0];
            $attributes_str = $matches[1];

            // Parse attributes (keys lower-cased; quoted and unquoted values supported)
            $attributes = $this->parse_attributes($attributes_str);

            $alt_missing   = !empty($settings['add_missing_alt']) && trim((string) ($attributes['alt'] ?? '')) === '';
            $title_missing = !empty($settings['add_missing_title']) && trim((string) ($attributes['title'] ?? '')) === '';

            // Nothing to inject on this image — skip before any source/attachment work.
            if (!$alt_missing && !$title_missing) {
                return $img_tag;
            }

            // Resolve the real image source. Lazy-load markup keeps the true URL in a
            // data-* attribute while `src` is empty or a placeholder/data-URI.
            $src = $this->resolve_image_src($attributes);

            // No resolvable source (spacers, tracking pixels, pure placeholders) — nothing
            // meaningful to describe, and nothing to derive %filename% from either.
            if ($src === '') {
                return $img_tag;
            }

            // Resolve the attachment ID only when a format that we're about to apply
            // actually references attachment metadata (%image_title% / %image_caption%).
            // attachment_url_to_postid() is a DB query, so avoid it for the common
            // filename-based formats and for images that need no injection.
            $needs_attachment =
                ($alt_missing && $this->format_uses_attachment($settings['alt_format'] ?? '')) ||
                ($title_missing && $this->format_uses_attachment($settings['title_format'] ?? ''));
            $attachment_id = $needs_attachment ? $this->url_to_attachment_id($src) : 0;

            // Handle ALT attribute
            if ($alt_missing) {
                $alt_val = $this->generate_attribute_value($settings['alt_format'] ?? '', $attachment_id, $id_to_pass, $count, $src);
                if ($alt_val !== '') {
                    $img_tag = $this->inject_attribute($img_tag, 'alt', $alt_val, isset($attributes['alt']));
                }
            }

            // Handle TITLE attribute
            if ($title_missing) {
                $title_val = $this->generate_attribute_value($settings['title_format'] ?? '', $attachment_id, $id_to_pass, $count, $src);
                if ($title_val !== '') {
                    $img_tag = $this->inject_attribute($img_tag, 'title', $title_val, isset($attributes['title']));
                }
            }

            return $img_tag;
        }, $content);
    }

    /**
     * Parse an <img> attribute string into a lower-cased key => value map.
     *
     * Handles double-quoted, single-quoted and unquoted attribute values so that
     * existing attributes (e.g. an unquoted `alt=Something`) are correctly detected
     * and not duplicated. Attribute names are normalised to lower-case so uppercase
     * markup (`SRC=`, `ALT=`) is recognised.
     *
     * @since 1.19.1
     * @param string $attributes_str The raw attribute portion of the tag.
     * @return array<string,string> Lower-cased attribute name => value.
     */
    private function parse_attributes(string $attributes_str): array {
        $matched = preg_match_all(
            '/([a-zA-Z][a-zA-Z0-9:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/',
            $attributes_str,
            $matches,
            PREG_SET_ORDER
        );

        if (!$matched) {
            return [];
        }

        $attributes = [];
        foreach ($matches as $m) {
            $key = strtolower($m[1]);

            if (isset($m[2]) && $m[2] !== '') {
                $value = $m[2];
            } elseif (isset($m[3]) && $m[3] !== '') {
                $value = $m[3];
            } elseif (isset($m[4]) && $m[4] !== '') {
                $value = $m[4];
            } else {
                $value = '';
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    /**
     * Resolve a usable image source from the parsed attributes.
     *
     * Prefers `src`, but falls back to common lazy-load attributes when `src` is
     * empty or a `data:` URI placeholder, so generated alt/title reflect the real
     * image rather than a base64 blob.
     *
     * @since 1.19.1
     * @param array<string,string> $attributes Parsed attributes.
     * @return string The resolved source URL, or '' if none is usable.
     */
    private function resolve_image_src(array $attributes): string {
        $candidates = ['src', 'data-src', 'data-lazy-src', 'data-original', 'data-lazy'];

        foreach ($candidates as $attr) {
            $value = trim((string) ($attributes[$attr] ?? ''));

            if ($value === '' || stripos($value, 'data:') === 0) {
                continue;
            }

            return $value;
        }

        return '';
    }

    /**
     * Whether a format string references attachment-only metadata tokens.
     *
     * Used to decide if an attachment lookup (a DB query) is actually needed;
     * filename/site/title/count tokens do not require the attachment record.
     *
     * @since 1.19.1
     * @param string $format The format string.
     * @return bool
     */
    private function format_uses_attachment(string $format): bool {
        return strpos($format, '%image_title%') !== false
            || strpos($format, '%image_caption%') !== false;
    }

    /**
     * Resolve an attachment ID from a source URL, memoized per request.
     *
     * `attachment_url_to_postid()` issues its own DB query, so repeated identical
     * URLs on a page (galleries, duplicated images) are cached here.
     *
     * @since 1.19.1
     * @param string $src Source URL.
     * @return int Attachment ID, or 0 if not a media-library image.
     */
    private function url_to_attachment_id(string $src): int {
        if ($src === '') {
            return 0;
        }

        static $cache = [];
        if (!array_key_exists($src, $cache)) {
            $cache[$src] = attachment_url_to_postid($src);
        }

        return $cache[$src];
    }

    /**
     * Inject (or replace an empty) alt/title attribute on a single <img> tag.
     *
     * When replacing, the pattern is anchored to a whitespace/tag boundary and
     * limited to one occurrence so it can never clobber a `data-alt`/`data-title`
     * (or any `*-alt`/`*-title`) attribute. A callback is used for the replacement
     * so `$` / `\` in the value are never treated as backreferences. Insertion is
     * case-insensitive on the tag opener so uppercase `<IMG>` is handled.
     *
     * @since 1.19.1
     * @param string $img_tag The full <img> tag.
     * @param string $name    Attribute name ('alt' or 'title').
     * @param string $value   Unescaped attribute value.
     * @param bool   $replace Whether an (empty) attribute already exists to replace.
     * @return string The modified tag.
     */
    private function inject_attribute(string $img_tag, string $name, string $value, bool $replace): string {
        $attr = $name . '="' . esc_attr($value) . '"';

        if ($replace) {
            return preg_replace_callback(
                '/(^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])[^"\']*\2/i',
                static function ($m) use ($attr) {
                    return $m[1] . $attr;
                },
                $img_tag,
                1
            );
        }

        return preg_replace_callback(
            '/<img\b/i',
            static function ($m) use ($attr) {
                return $m[0] . ' ' . $attr;
            },
            $img_tag,
            1
        );
    }

    /**
     * Generate attribute value based on format and context
     *
     * @since 1.0.0
     * @param string $format        The format string
     * @param int    $attachment_id Attachment ID
     * @param int    $post_id       Current Post ID
     * @param int    $count         Image counter
     * @param string $src           Image source URL
     * @return string Generated value
     */
    private function generate_attribute_value(string $format, int $attachment_id, int $post_id, int $count, string $src): string {
        $replacements = [
            '%site_title%'    => get_bloginfo('name'),
            '%sitename%'      => get_bloginfo('name'),
            '%title%'         => $post_id > 0 ? get_the_title($post_id) : get_bloginfo('name'),
            '%count%'         => (string) $count,
            '%filename%'      => '',
            '%image_title%'   => '',
            '%image_caption%' => '',
        ];

        // Get filename from src
        if ($src) {
            $filename = pathinfo($src, PATHINFO_FILENAME);
            $replacements['%filename%'] = str_replace(['-', '_'], ' ', $filename);
        }

        // Get attachment data if ID exists
        if ($attachment_id) {
            $attachment = get_post($attachment_id);
            if ($attachment) {
                $replacements['%image_title%'] = $attachment->post_title;
                $replacements['%image_caption%'] = $attachment->post_excerpt;
            }
        }

        // Apply replacements for every token except the separator.
        $value = str_replace(array_keys($replacements), array_values($replacements), $format);

        // Split on the separator tokens, drop segments that resolved to empty, then
        // re-join with the separator symbol. This prevents orphaned/leading/trailing
        // separators such as "| Site Name" when a token (e.g. %filename%) is empty.
        $segments = preg_split('/%sep(?:arator)?%/', $value);
        $segments = array_filter(
            array_map('trim', $segments),
            static function ($segment) {
                return $segment !== '';
            }
        );
        $value = implode(' ' . $this->get_separator() . ' ', $segments);

        // Clean up double spaces if any
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * Get site separator
     *
     * @since 1.0.0
     * @return string
     */
    private function get_separator(): string {
        if ($this->separator === null) {
            $this->separator = Site_Identity_Manager::get_active_separator_symbol();
        }
        return $this->separator;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Media Library alt-text persistence (writes _wp_attachment_image_alt)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Generate and save alt text onto a single attachment's Media Library record.
     *
     * Uses the same `alt_format` token pipeline as output injection, so the value
     * matches what the front-end filter would have produced. In this context
     * `%title%` and `%image_title%` resolve to the attachment's own title.
     *
     * @since 1.19.1
     * @param int  $attachment_id The attachment ID.
     * @param bool $overwrite     When false, images that already have alt text are left untouched.
     * @return bool True when the attachment now has the generated alt text; false when skipped or on failure.
     */
    public function fill_attachment_alt(int $attachment_id, bool $overwrite = false): bool {
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }

        $existing = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

        // Non-destructive by default: never clobber hand-written alt text.
        if (!$overwrite && trim($existing) !== '') {
            return false;
        }

        $settings = $this->get_settings('site');
        $format   = $settings['alt_format'] ?? '%filename%';
        $src      = (string) wp_get_attachment_url($attachment_id);

        // Pass the attachment ID as the post context so %title% falls back to the
        // attachment's own title (there is no surrounding post here).
        $value = sanitize_text_field(
            $this->generate_attribute_value($format, $attachment_id, $attachment_id, 0, $src)
        );

        if ($value === '') {
            return false;
        }

        if ($existing === $value) {
            // Already correct — treat as success without a redundant write.
            return true;
        }

        return update_post_meta($attachment_id, '_wp_attachment_image_alt', $value) !== false;
    }

    /**
     * Fill alt text across the Media Library in a single batch.
     *
     * Iterates images by ascending ID using offset/limit so callers can page
     * through large libraries without exhausting memory or hitting timeouts.
     *
     * @since 1.19.1
     * @param array $args {
     *     @type int  $offset    Starting offset into the image set. Default 0.
     *     @type int  $limit     Batch size (clamped 1–200). Default 50.
     *     @type bool $overwrite Overwrite existing alt text. Default false.
     * }
     * @return array {
     *     @type int  $total       Total images in the library.
     *     @type int  $processed   Images looked at in this batch.
     *     @type int  $updated     Images whose alt text was written.
     *     @type int  $skipped     Images left unchanged (already had alt / no value).
     *     @type int  $offset      The offset this batch started at.
     *     @type int  $next_offset The offset to pass for the next batch.
     *     @type int  $remaining   Images still to process after this batch.
     *     @type bool $done        True when the whole library has been processed.
     * }
     */
    public function bulk_fill_missing_alt(array $args = []): array {
        $offset    = max(0, (int) ($args['offset'] ?? 0));
        $limit     = min(200, max(1, (int) ($args['limit'] ?? 50)));
        $overwrite = !empty($args['overwrite']);

        $total = $this->count_images();

        $ids = get_posts([
            'post_type'        => 'attachment',
            'post_mime_type'   => 'image',
            'post_status'      => 'inherit',
            'numberposts'      => $limit,
            'offset'           => $offset,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);

        $updated   = 0;
        $skipped   = 0;
        $processed = 0;

        foreach ($ids as $id) {
            $processed++;
            if ($this->fill_attachment_alt((int) $id, $overwrite)) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        $next_offset = $offset + count($ids);
        $remaining   = max(0, $total - $next_offset);

        // Bulk writes change the Site SEO Analyzer's "images have alt text" coverage.
        if ($updated > 0) {
            $this->flush_analyzer_cache();
        }

        return [
            'total'       => $total,
            'processed'   => $processed,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'offset'      => $offset,
            'next_offset' => $next_offset,
            'remaining'   => $remaining,
            'done'        => $next_offset >= $total,
        ];
    }

    /**
     * Media Library alt-text coverage stats for the settings UI.
     *
     * @since 1.19.1
     * @return array{total:int,with_alt:int,missing:int}
     */
    public function get_media_alt_stats(): array {
        $total    = $this->count_images();
        $with_alt = $this->count_images_with_alt();

        return [
            'total'    => $total,
            'with_alt' => $with_alt,
            'missing'  => max(0, $total - $with_alt),
        ];
    }

    /**
     * Auto-fill hook target — save alt text for a freshly uploaded image.
     *
     * Gated by the `save_alt_to_media` + `auto_fill_on_upload` settings so it is a
     * no-op unless the feature is enabled. Respects the overwrite preference.
     *
     * @since 1.19.1
     * @param int $attachment_id The newly created attachment ID.
     * @return void
     */
    public function maybe_auto_fill_on_upload(int $attachment_id): void {
        $settings = $this->get_settings('site');

        if (empty($settings['save_alt_to_media']) || empty($settings['auto_fill_on_upload'])) {
            return;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }

        $this->fill_attachment_alt($attachment_id, !empty($settings['media_alt_overwrite']));
    }

    /**
     * Total number of image attachments in the library.
     *
     * @since 1.19.1
     * @return int
     */
    private function count_images(): int {
        $counts = wp_count_attachments();
        $total  = 0;

        foreach ((array) $counts as $mime => $count) {
            if (strpos((string) $mime, 'image/') === 0) {
                $total += (int) $count;
            }
        }

        return $total;
    }

    /**
     * Number of image attachments that already have non-empty alt text.
     *
     * Mirrors the query used by the Site SEO Analyzer's alt-text check so the two
     * features report consistent coverage.
     *
     * @since 1.19.1
     * @return int
     */
    private function count_images_with_alt(): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed COUNT via postmeta meta_key index; short-lived admin action
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$this->wpdb->posts} p
             INNER JOIN {$this->wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = '_wp_attachment_image_alt'
               AND pm.meta_value != ''
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
        );
    }

    /**
     * Bust the Site SEO Analyzer's cached result so its alt-text coverage refreshes.
     *
     * Uses the analyzer's transient key directly to avoid instantiating it here.
     *
     * @since 1.19.1
     * @return void
     */
    private function flush_analyzer_cache(): void {
        // Matches SEO_Analyzer::CACHE_KEY.
        delete_transient('thinkrank_site_seo_analysis');
    }
}
