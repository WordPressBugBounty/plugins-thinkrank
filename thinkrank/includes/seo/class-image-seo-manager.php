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
     * Accepted values for the `alt_source` setting.
     *
     * Single source of truth for the schema's enum, the REST arg constraint and
     * validate_settings(), so the three cannot disagree about what is legal.
     *
     * @since 1.29.1
     * @var string[]
     */
    public const ALT_SOURCES = ['template', 'ai'];

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
     * Attachment IDs whose cache purge is being held back, or null when not.
     *
     * A bulk fill writes alt text onto hundreds of attachments that between
     * them appear on a handful of pages. Resolving usage per attachment would
     * run one unindexed postmeta scan per image; holding the IDs and resolving
     * the whole batch in one query at the end is the same answer for a
     * fraction of the work.
     *
     * @since 2.12.0
     * @var int[]|null
     */
    private ?array $deferred_alt_purge = null;

    /**
     * What a bulk fill changed, attachment ID => [before, after], or null
     * outside a bulk run. Returned as `changes` so the MCP activity log can
     * show each write and undo it image by image (#717).
     *
     * @since 2.15.0
     * @var array<int,array{before:string,after:string}>|null
     */
    private ?array $bulk_alt_changes = null;

    /**
     * Why AI alt text failed for each image in a bulk run, attachment ID =>
     * message, or null outside a bulk run. Returned as `ai_failed` and
     * `ai_error` so a run that fell back to the template says so (#1039).
     *
     * @since 2.19.0
     * @var array<int,string>|null
     */
    private ?array $bulk_ai_errors = null;

    /**
     * Images a bulk fill left alone because the template produced filler,
     * or null outside a bulk run.
     *
     * @since 2.17.0
     * @var int[]|null
     */
    private ?array $bulk_low_quality = null;

    /**
     * True while a bulk fill is only previewing: values are worked out and
     * listed, nothing is written and no AI call is made.
     *
     * @since 2.17.0
     * @var bool
     */
    private bool $bulk_dry_run = false;

    /**
     * Why the most recent fill_attachment_alt() call wrote nothing, or ''.
     *
     * @since 2.17.0
     * @var string
     */
    private string $last_fill_skip_reason = '';


    /**
     * Alt source for the current call, overriding the saved `alt_source`,
     * or null to use the setting.
     *
     * Lets an ability ask for AI alt text on one call without changing the
     * site's default (#1123).
     *
     * @since 2.17.0
     * @var string|null
     */
    private ?string $alt_source_override = null;

    /**
     * Why the last explicit AI alt request wrote nothing, or '' when it did.
     *
     * @since 2.17.0
     * @var string
     */
    private string $last_ai_alt_error = '';

    /**
     * Images an explicit AI batch could not describe, keyed by attachment ID
     * with the reason, or null outside bulk_fill_missing_alt().
     *
     * @since 2.17.0
     * @var array<int, string>|null
     */
    private ?array $bulk_ai_failures = null;

    /**
     * What the most recent purge_alt_caches() call did.
     *
     * Read by the MCP abilities, which have to report the cache outcome
     * alongside the write and cannot see inside fill_attachment_alt().
     *
     * @since 2.12.0
     * @var array{posts: int[], warnings: string[]}
     */
    private array $last_alt_cache_purge = ['posts' => [], 'warnings' => []];

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

        // The schema declares alt_source as an enum but nothing used to check
        // it, so any string persisted. The consumer falls back to the template
        // path on an unknown value, which hid the drift rather than surfacing
        // it — the settings screen just had no option to select (#323).
        if (isset($settings['alt_source']) && !in_array($settings['alt_source'], self::ALT_SOURCES, true)) {
            $validation['errors'][] = sprintf(
                'alt_source must be one of: %s',
                implode(', ', self::ALT_SOURCES)
            );
            $validation['valid'] = false;
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
    /**
     * Images per batch when alt text comes from the vision model.
     *
     * Each one is a paid call of a few seconds; 10 keeps a batch inside a
     * normal PHP timeout and keeps the spend per click predictable.
     *
     * @since 1.28.0
     * @var int
     */
    private const AI_BATCH_LIMIT = 10;

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
            // 'template' rewrites the filename; 'ai' looks at the picture.
            // Defaults to template because AI costs the user money per image.
            'alt_source' => 'template',
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
            'alt_source' => [
                'type' => 'string',
                'title' => __('Alt text source', 'thinkrank'),
                'description' => __('“Template” builds alt text from the filename and title. “AI” looks at the image itself and describes what is in it. This uses your AI provider key and costs one call per image.', 'thinkrank'),
                'default' => 'template',
                'enum' => self::ALT_SOURCES
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

            // The block editor writes alt="" on every image saved without alt
            // text, so an empty alt is usually "not written yet" and stays
            // fillable. An image marked decorative with role="presentation",
            // role="none" or aria-hidden="true" is left as it is (#1206),
            // title included: screen readers and hover tooltips expose a
            // title too, so it would undo the decorative marking (#1252).
            $decorative    = $this->is_marked_decorative($attributes);
            $has_alt       = $this->has_attribute($attributes_str, 'alt');
            $alt_missing   = !empty($settings['add_missing_alt'])
                && trim((string) ($attributes['alt'] ?? '')) === ''
                && !$decorative;
            $title_missing = !empty($settings['add_missing_title'])
                && trim((string) ($attributes['title'] ?? '')) === ''
                && !$decorative;

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

            // Resolve the attachment ID only when it is going to be read: an empty
            // alt (its Media Library alt comes first, below), or a title format
            // that references attachment metadata (%image_title% / %image_caption%).
            // The lookup reads the editor's wp-image-{ID} class first and caches
            // the rest (#847), and images that need no injection never reach it.
            $needs_attachment =
                $alt_missing ||
                ($title_missing && $this->format_uses_attachment($settings['title_format'] ?? ''));
            $attachment_id = $needs_attachment
                ? $this->url_to_attachment_id($src, (string) ($attributes['class'] ?? ''))
                : 0;

            // Handle ALT attribute
            if ($alt_missing) {
                // The editor copies the alt into the post when the image is
                // inserted, so an image inserted before it had alt text keeps
                // alt="" in the content. Alt text added to the Media Library
                // afterwards (by ThinkRank's own fill, or by another plugin) is
                // the real description; the template is only the fallback. Using
                // the template first put "IMG 1234" on the page for an image
                // whose library alt was a proper sentence (#1059). Stored filler
                // such as "1" or "image" counts as missing, as in the fill (#1209).
                $alt_val = sanitize_text_field($this->media_library_alt($attachment_id));
                if ($alt_val === '' || !Alt_Text_Quality::is_descriptive($alt_val)) {
                    $alt_format = (string) ($settings['alt_format'] ?? '');
                    // Sanitized like the Media Library value and the gate's own
                    // input, so the gate judges exactly the text that is injected
                    // (#1251) and a post title's markup never lands in the alt.
                    $alt_val = sanitize_text_field($this->generate_attribute_value($alt_format, $attachment_id, $id_to_pass, $count, $src));

                    // The same gate the Media Library fill uses: "1", "IMG 1234"
                    // or "image" read out by a screen reader is worse than no alt,
                    // so filler is not injected either (#1206, #1124).
                    // %title% is the post title here, the same on every image of
                    // the page, so it cannot vouch for any one of them (#1249).
                    if (!$this->template_alt_is_descriptive($alt_format, $attachment_id, $id_to_pass, $count, $src, false)) {
                        $alt_val = '';
                    }
                }
                if ($alt_val !== '') {
                    $img_tag = $this->inject_attribute($img_tag, 'alt', $alt_val, $has_alt);
                }
            }

            // Handle TITLE attribute
            if ($title_missing) {
                $title_val = sanitize_text_field($this->generate_attribute_value($settings['title_format'] ?? '', $attachment_id, $id_to_pass, $count, $src));
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
     * Whether an <img> attribute string carries an attribute, with or without a value.
     *
     * parse_attributes() only sees `name=value` pairs, so a bare `alt` (valid
     * HTML for an empty alt) would otherwise look absent and get a second alt
     * attribute instead of having its own replaced.
     *
     * @since 2.17.0
     * @param string $attributes_str The raw attribute portion of the tag.
     * @param string $name           Attribute name.
     * @return bool
     */
    private function has_attribute(string $attributes_str, string $name): bool {
        // Drop quoted values first so text inside another attribute ("data-x=' alt'")
        // cannot be mistaken for the attribute itself.
        $names_only = (string) preg_replace('/=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/', '=', $attributes_str);

        return 1 === preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?=[\s=\/]|$)/i', $names_only);
    }

    /**
     * Whether the markup says outright that an image is decorative.
     *
     * @since 2.17.0
     * @param array<string,string> $attributes Parsed attributes.
     * @return bool
     */
    private function is_marked_decorative(array $attributes): bool {
        $role = strtolower(trim((string) ($attributes['role'] ?? '')));

        return in_array($role, ['presentation', 'none'], true)
            || 'true' === strtolower(trim((string) ($attributes['aria-hidden'] ?? '')));
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
     * Resolve an attachment ID from a source URL.
     *
     * Body images are almost always inserted at a generated size, which
     * `attachment_url_to_postid()` cannot match, so %image_title% and
     * %image_caption% resolved to nothing for them. Attachment_Lookup reads the
     * `wp-image-{ID}` class the editor wrote first, and caches whatever still
     * has to be asked of the database (#847).
     *
     * @since 1.19.1
     * @param string $src     Source URL.
     * @param string $classes The image's class attribute, for its wp-image-{ID}.
     * @return int Attachment ID, or 0 if not a media-library image.
     */
    private function url_to_attachment_id(string $src, string $classes = ''): int {
        return Attachment_Lookup::id_from_url($src, Attachment_Lookup::hint_from_markup($classes));
    }

    /**
     * The alt text saved on an attachment in the Media Library.
     *
     * @since 2.19.0
     * @param int $attachment_id Attachment ID, or 0 when the image is not in the library.
     * @return string Trimmed alt text, or '' when there is none.
     */
    private function media_library_alt(int $attachment_id): string {
        if ($attachment_id <= 0) {
            return '';
        }

        return trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
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
            // Walk the attributes one whole name[=value] at a time, so a word
            // inside another value (title="no alt here") is never matched and a
            // bare `alt` with no value is replaced the same way as alt="".
            $done = false;

            return (string) preg_replace_callback(
                '/(\s)([a-zA-Z][a-zA-Z0-9:-]*)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/',
                static function ($m) use ($attr, $name, &$done) {
                    if ($done || strtolower($m[2]) !== $name) {
                        return $m[0];
                    }
                    $done = true;

                    return $m[1] . $attr;
                },
                $img_tag
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
            // The Site Identity Site Name, as in every other template (#1331).
            '%site_title%'    => Site_Identity_Manager::site_name(),
            '%sitename%'      => Site_Identity_Manager::site_name(),
            // Empty, not the site name. The segment collapsing below drops an
            // unresolved token together with its separator, and the default
            // title_format already ends in %sitename% — substituting the site
            // name here printed it twice ("Site Name | Site Name") on every
            // image processed outside the loop (widgets, page builders, FSE).
            '%title%'         => $post_id > 0 ? get_the_title($post_id) : '',
            '%count%'         => (string) $count,
            '%filename%'      => '',
            '%image_title%'   => '',
            '%image_caption%' => '',
        ];

        // Get filename from src
        if ($src) {
            $replacements['%filename%'] = str_replace(['-', '_'], ' ', $this->filename_from_src($src));
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
     * The file name a person gave an image, read from its URL.
     *
     * The rendered `src` is a URL, not a file name: it can be percent-encoded
     * (`my%20red%20car.jpg`), carry a query string, and point at a size or
     * copy WordPress made, whose name ends in `-150x150`, `-scaled`,
     * `-rotated` or the `-e{timestamp}` of an edit. Those suffixes ended up
     * in the alt text as "purple tulip field 150x150" (#1251).
     *
     * @since 2.17.0
     * @param string $src Image source URL.
     * @return string File name without extension, decoded and without WordPress's suffixes.
     */
    private function filename_from_src(string $src): string {
        $path     = (string) wp_parse_url($src, PHP_URL_PATH);
        $filename = rawurldecode(pathinfo('' !== $path ? $path : $src, PATHINFO_FILENAME));

        // One pass per suffix, outermost first: "photo-scaled-e1700000000000".
        $stripped = (string) preg_replace('/(?:-(?:\d+x\d+|scaled|rotated|e\d{13}))+$/i', '', $filename);

        // A name that is nothing but a suffix ("150x150.jpg") keeps it.
        return '' !== $stripped ? $stripped : $filename;
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
     * Render a Media Library template candidate without AI or metadata writes.
     *
     * @since 2.17.0
     * @param int         $attachment_id Image attachment ID.
     * @param string|null $format Already-read format, or null to read settings.
     * @return array{format: string, proposed: string, status: string}
     */
    public function preview_attachment_alt(int $attachment_id, ?string $format = null): array {
        if (null === $format) {
            // A draft may finish after another request changed these settings.
            wp_cache_delete($this->get_cache_key('site', null), 'thinkrank_seo');
            wp_cache_delete($this->get_stored_cache_key('site', null), 'thinkrank_seo');
            $this->separator = null;
            $settings = $this->get_settings('site');
            $format = (string) ($settings['alt_format'] ?? '%filename%');
        }
        $value = sanitize_text_field($this->generate_attribute_value(
            $format,
            $attachment_id,
            $attachment_id,
            0,
            (string) wp_get_attachment_url($attachment_id)
        ));

        $status = 'empty';
        if ('' !== $value) {
            $status = $this->template_alt_is_descriptive($format, $attachment_id, $attachment_id, 0, (string) wp_get_attachment_url($attachment_id), true)
                ? 'candidate'
                : 'low_quality';
        }

        return [
            'format' => $format,
            'proposed' => $value,
            'status' => $status,
        ];
    }

    /**
     * Whether the per-image part of a template says anything about the image.
     *
     * Only %filename%, %title%, %image_title% and %image_caption% change from
     * one image to the next. The site name, the counter, the separator and any
     * literal text in the format are the same on every image, so gating the
     * whole string let "1 - Site Name" through on the strength of the site
     * name alone (#1205). A format with no per-image token gives every image
     * the same alt text, which describes none of them.
     *
     * %title% is per-image only in the Media Library, where it resolves to the
     * attachment's own title. In rendered content it is the post title, the
     * same on every image of the page, so there it is a fixed part like the
     * site name and "1 - Post Title" is filler (#1249).
     *
     * @since 2.17.0
     * @param string $format             The alt format.
     * @param int    $attachment_id      Attachment ID, or 0.
     * @param int    $post_id            Post the image is shown in, or the attachment itself.
     * @param int    $count              Image counter.
     * @param string $src                Image source URL.
     * @param bool   $title_is_per_image Whether %title% is the image's own title (Media Library)
     *                                   rather than the title of the post it is shown in.
     * @return bool
     */
    private function template_alt_is_descriptive(string $format, int $attachment_id, int $post_id, int $count, string $src, bool $title_is_per_image): bool {
        $per_image = $title_is_per_image
            ? '/%(?:filename|title|image_title|image_caption)%/'
            : '/%(?:filename|image_title|image_caption)%/';

        if (!preg_match_all($per_image, $format, $tokens)) {
            return false;
        }

        $variable = sanitize_text_field($this->generate_attribute_value(
            implode(' ', $tokens[0]),
            $attachment_id,
            $post_id,
            $count,
            $src
        ));

        return Alt_Text_Quality::is_descriptive($variable);
    }

    /**
     * Make at most one vision request, without saving or falling back to a template.
     *
     * Callers must obtain cost consent before entering this method. Provider
     * errors are deliberately not returned: their messages can contain secrets.
     *
     * @since 2.17.0
     * @param int $attachment_id Image attachment ID.
     * @return string|\WP_Error Unsaved description, or a safe error.
     */
    public function draft_attachment_alt(int $attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return new \WP_Error('thinkrank_not_an_image', __('The image is no longer available.', 'thinkrank'), ['status' => 404]);
        }
        $reason = $this->ai_alt_unavailable_reason();
        if (null !== $reason) {
            return new \WP_Error('thinkrank_ai_alt_unavailable', $reason, ['status' => 409]);
        }
        $this->last_ai_alt_error = '';
        try {
            $value = sanitize_text_field($this->generate_ai_alt($attachment_id));
        } catch (\Throwable $error) {
            $value = '';
        }
        if ('' !== trim($value) && !Alt_Text_Quality::is_descriptive($value)) {
            return new \WP_Error(
                'thinkrank_ai_alt_draft_low_quality',
                __('The AI provider returned filler, such as a number or "image", instead of a description. No alt text was saved. A provider request may have been charged; check your budget before trying again.', 'thinkrank'),
                ['status' => 502]
            );
        }
        if ('' === trim($value)) {
            return new \WP_Error(
                'thinkrank_ai_alt_draft_failed',
                __('The AI provider could not produce a draft. No alt text was saved. A provider request may have been charged; check your budget before trying again.', 'thinkrank'),
                ['status' => 502]
            );
        }
        return $value;
    }

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
     * @param bool $purge         Clear the caches that already rendered this image. Pass false only
     *                            when the attachment cannot yet appear on any page.
     * @return bool True when the attachment now has the generated alt text; false when skipped or on failure.
     */
    public function fill_attachment_alt(int $attachment_id, bool $overwrite = false, bool $purge = true): bool {
        $this->last_fill_skip_reason = '';
        $this->last_ai_alt_error = '';

        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }

        $existing = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

        // Non-destructive by default: never clobber hand-written alt text.
        // Stored filler such as "1" or "image" is not a description anyone
        // meant to keep, and the coverage stats and the analyzer already
        // count it as missing. Skipping it left those images out of every
        // fill, so the one-click fix failed on each run (#1209).
        if (!$overwrite && trim($existing) !== '' && Alt_Text_Quality::is_descriptive($existing)) {
            return false;
        }

        $settings = $this->get_settings('site');
        $format   = $settings['alt_format'] ?? '%filename%';

        $value         = '';
        $from_ai       = false;
        $keep_existing = false;

        // AI describes the picture; the template can only rewrite its filename.
        // With AI chosen by the saved setting, any failure falls back to the
        // template so a provider outage degrades to the old behaviour instead
        // of leaving images bare. A call that asked for AI itself gets no
        // fallback: the template text is the filler it asked AI to avoid, and
        // with overwrite on it would replace a real description (#1185 review).
        // Either way the failure is recorded so the run reports it, and alt
        // text the image already has is never replaced by the template: with
        // overwrite on, that turned a failed AI call into lost hand-written
        // alt text (#1039).
        if (!$this->bulk_dry_run && 'ai' === $this->resolve_alt_source($settings)) {
            $value   = $this->ai_alt_or_empty($this->generate_ai_alt($attachment_id));
            $from_ai = '' !== $value;

            if ('' === $value) {
                if ('' === $this->last_ai_alt_error) {
                    $this->last_ai_alt_error = __('The AI provider returned no description for this image.', 'thinkrank');
                }

                if (null !== $this->bulk_ai_errors) {
                    $this->bulk_ai_errors[$attachment_id] = $this->last_ai_alt_error;
                }

                if ('ai' === $this->alt_source_override) {
                    if (null !== $this->bulk_ai_failures) {
                        $this->bulk_ai_failures[$attachment_id] = $this->last_ai_alt_error;
                    }

                    return false;
                }

                $keep_existing = trim($existing) !== '' && Alt_Text_Quality::is_descriptive($existing);
            }
        }

        if ('' === $value) {
            // Pass the attachment ID as the post context so %title% falls back to the
            // attachment's own title (there is no surrounding post here).
            $preview = $this->preview_attachment_alt($attachment_id, $format);
            $value = $preview['proposed'];

            // The template only rewrites a file name or a title. On a library
            // of `1.jpg`, `3-4.png` and `image.jpg` that is "1", "3 4" and
            // "image", which a screen reader reads out and the analyzer
            // counted as covered. An empty alt is the better of the two, so
            // leave the image for a person or the AI source (#1124).
            if ('low_quality' === $preview['status']) {
                $this->last_fill_skip_reason = 'low_quality';
                if (null !== $this->bulk_low_quality) {
                    $this->bulk_low_quality[] = $attachment_id;
                }

                return false;
            }

            // The saved AI source failed on an image that already has a real
            // description: the template must not replace it (#1039). The AI's
            // reason stays in last_ai_alt_error for the caller to report.
            if ($keep_existing) {
                return false;
            }

            // A template fallback is about to be written, so this call did
            // not fail; the reason is still counted in a bulk run's ai_failed.
            if ('' !== $value) {
                $this->last_ai_alt_error = '';
            }
        }

        if ($value === '') {
            return false;
        }

        if ($existing === $value) {
            // Already correct — treat as success without a redundant write.
            return true;
        }

        if ($this->bulk_dry_run) {
            if (null !== $this->bulk_alt_changes) {
                $this->bulk_alt_changes[$attachment_id] = ['before' => $existing, 'after' => $value];
            }

            return false;
        }

        // WordPress unslashes meta values; the saved text must match its template/AI candidate.
        $written = update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_slash($value)) !== false;

        if ($written) {
            if (null !== $this->bulk_alt_changes) {
                $this->bulk_alt_changes[$attachment_id] = [
                    'before' => $existing,
                    'after'  => $value,
                    'source' => $from_ai ? 'ai' : 'template',
                ];
            }
            $this->record_alt_write($attachment_id, $purge);
        }

        return $written;
    }

    /**
     * fill_attachment_alt() with the alt source chosen for this call only.
     *
     * The saved `alt_source` stays as it is, so an agent can ask for AI alt
     * text on one image without switching the whole site over (#1123).
     *
     * @since 2.17.0
     * @param int    $attachment_id The attachment ID.
     * @param bool   $overwrite     Replace alt text the image already has.
     * @param string $alt_source    'template' or 'ai'.
     * @return bool As fill_attachment_alt().
     */
    public function fill_attachment_alt_from(int $attachment_id, bool $overwrite, string $alt_source): bool {
        $previous                  = $this->alt_source_override;
        $this->alt_source_override = $this->normalize_alt_source($alt_source);

        try {
            return $this->fill_attachment_alt($attachment_id, $overwrite);
        } finally {
            $this->alt_source_override = $previous;
        }
    }

    /**
     * Note that an attachment's alt text changed, and clear what rendered it.
     *
     * @since 2.12.0
     * @param int  $attachment_id Attachment whose alt text was just written.
     * @param bool $purge         False when nothing can be displaying this attachment yet.
     * @return void
     */
    protected function record_alt_write(int $attachment_id, bool $purge = true): void {
        // Recorded during a bulk run whatever $purge says: the batch resolves
        // the whole list at the end, and bulk_fill_missing_alt() counts its
        // writes from this list.
        if (null !== $this->deferred_alt_purge) {
            $this->deferred_alt_purge[] = $attachment_id;

            return;
        }

        // A fresh upload cannot be on any page, so there is nothing to clear
        // and nothing for thinkrank_image_alt_updated to report. Listeners
        // that care about new files already have core's add_attachment.
        if (!$purge) {
            return;
        }

        $this->purge_alt_caches([$attachment_id]);
    }

    /**
     * Drop cached renderings of every page that shows these attachments.
     *
     * Alt text is written to post meta but read out of HTML that other
     * software has already rendered and stored, so the write alone changes
     * nothing a visitor sees. Elementor keeps rendered widgets for 24 hours by
     * default and page caches keep whole documents, which is how an agent came
     * to report a successful alt-text write against a page still showing the
     * old words (#763).
     *
     * @since 2.12.0
     * @param int[] $attachment_ids Attachments whose alt text changed.
     * @return array{posts: int[], warnings: string[]} Posts purged, and caches left for the user to clear.
     */
    public function purge_alt_caches(array $attachment_ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachment_ids))));

        if ([] === $ids) {
            $this->last_alt_cache_purge = ['posts' => [], 'warnings' => []];

            return $this->last_alt_cache_purge;
        }

        $posts  = Attachment_Usage::posts_using($ids);
        $purged = Cache_Purger::purge_posts($posts);

        foreach ($ids as $id) {
            /**
             * Fires after ThinkRank writes an image's alt text and clears the
             * caches it knows about.
             *
             * Builders and cache layers ThinkRank does not handle can listen
             * here to drop their own rendering of the affected posts.
             *
             * @since 2.12.0
             *
             * @param int   $attachment_id The attachment whose alt text changed.
             * @param int[] $post_ids      Posts found to display that attachment.
             */
            do_action('thinkrank_image_alt_updated', $id, $posts);
        }

        $this->last_alt_cache_purge = [
            'posts'    => $purged,
            'warnings' => Cache_Purger::warnings(),
        ];

        return $this->last_alt_cache_purge;
    }

    /**
     * The outcome of the most recent alt-text cache purge in this request.
     *
     * @since 2.12.0
     * @return array{posts: int[], warnings: string[]}
     */
    public function last_alt_cache_purge(): array {
        return $this->last_alt_cache_purge;
    }

    /**
     * Why the most recent fill_attachment_alt() call wrote nothing.
     *
     * 'low_quality' when the template produced a value that describes
     * nothing ("1", "image", "IMG 1234"); '' otherwise.
     *
     * @since 2.17.0
     * @return string
     */
    public function last_fill_skip_reason(): string {
        return $this->last_fill_skip_reason;
    }

    /**
     * The alt source in force: this call's override, else the saved setting.
     *
     * @since 2.17.0
     * @param array<string, mixed> $settings Saved image SEO settings.
     * @return string 'template' or 'ai'.
     */
    private function resolve_alt_source(array $settings): string {
        return $this->alt_source_override
            ?? $this->normalize_alt_source((string) ($settings['alt_source'] ?? 'template'));
    }

    /**
     * Coerce a value to one of ALT_SOURCES, defaulting to the template.
     *
     * @since 2.17.0
     * @param string $source Requested source.
     * @return string
     */
    private function normalize_alt_source(string $source): string {
        return in_array($source, self::ALT_SOURCES, true) ? $source : 'template';
    }

    /**
     * Why AI alt text cannot be produced right now, or null when it can.
     *
     * An explicit request for AI alt text must not quietly come back as
     * template text: that is the filler the caller asked AI to avoid. The
     * abilities check this first and refuse with the reason.
     *
     * @since 2.17.0
     * @return string|null User-facing reason, or null when AI is ready.
     */
    public function ai_alt_unavailable_reason(): ?string {
        $blocked = \ThinkRank\AI\Spend_Guard::blocked_reason();
        if (null !== $blocked) {
            return \ThinkRank\AI\Spend_Guard::message_for($blocked);
        }

        try {
            $available = (new \ThinkRank\AI\Vision_Client())->is_available();
        } catch (\Throwable $e) {
            $available = false;
        }

        if (!$available) {
            return __('AI alt text needs an AI provider that can read images (OpenAI, Anthropic or Gemini, or an OpenAI-compatible model marked as able to describe images) with its API key set under Settings > AI Provider.', 'thinkrank');
        }

        return null;
    }

    /**
     * Why switching the saved alt source to AI must be refused, or null.
     *
     * Saving `ai` with no provider that can read images left every later
     * fill on the template while reporting AI (#1208). Only a switch is
     * refused: a payload that resends the source already stored is let
     * through, so it cannot block saving the other settings beside it.
     *
     * @since 2.17.0
     * @param array<string, mixed> $settings Settings about to be saved.
     * @return string|null User-facing reason, or null when the save may go ahead.
     */
    public function ai_alt_source_save_error(array $settings): ?string {
        if ('ai' !== ($settings['alt_source'] ?? null)) {
            return null;
        }

        $stored = $this->get_settings('site');
        if ('ai' === ($stored['alt_source'] ?? null)) {
            return null;
        }

        return $this->ai_alt_unavailable_reason();
    }

    /**
     * Why the last explicit AI alt request wrote nothing.
     *
     * Set when a call that asked for AI itself (fill_attachment_alt_from(),
     * or a batch with `alt_source`) could not get a description, and cleared
     * at the start of every fill. A source taken from the saved setting falls
     * back to the template instead and leaves this empty, unless the image
     * already had a real description and kept it, in which case the AI's
     * reason is kept here (#1039).
     *
     * @since 2.17.0
     * @return string The reason, or '' when the last fill did not fail that way.
     */
    public function last_ai_alt_error(): string {
        return $this->last_ai_alt_error;
    }

    /**
     * The AI budget after a call, with how many requests the call itself used.
     *
     * @since 2.17.0
     * @param int $used_before Requests used today before the call began.
     * @return array<string, mixed>
     */
    public function ai_budget_report(int $used_before): array {
        $status = \ThinkRank\AI\Spend_Guard::status();

        return [
            'requests_used'   => max(0, (int) $status['used_today'] - $used_before),
            'used_today'      => (int) $status['used_today'],
            'daily_limit'     => (int) $status['daily_limit'],
            'remaining_today' => $status['remaining_today'],
            'blocked'         => (bool) $status['blocked'],
            'message'         => (string) $status['message'],
        ];
    }

    /**
     * An AI description, or '' when it is filler.
     *
     * A provider can answer "image" or a file name as readily as a template
     * can, and the AI path skipped the quality gate, so an explicit AI batch
     * wrote "image" to every image it was given (#1211). Filler is treated as
     * a failed description: the reason is recorded for the caller to report,
     * and the usual AI-failure handling (a failure, or the template fallback
     * for the saved setting) takes over.
     *
     * @since 2.17.0
     * @param string $value What generate_ai_alt() returned.
     * @return string
     */
    private function ai_alt_or_empty(string $value): string {
        if ('' === $value || Alt_Text_Quality::is_descriptive($value)) {
            return $value;
        }

        $this->last_ai_alt_error = sprintf(
            /* translators: %s: the text the AI provider returned. */
            __('The AI provider returned "%s", which does not describe the image.', 'thinkrank'),
            $value
        );

        return '';
    }

    /**
     * Describe an attachment with the vision model.
     *
     * Never throws: alt text generation runs in batches over a whole media
     * library, and one unreadable image or a rate-limit blip must not abort
     * the run. Returns '' and records the reason in $last_ai_alt_error, so
     * the caller can fall back to the template or report the failure rather
     * than count a silent fallback as a success (#1039).
     *
     * @since 1.28.0
     * @since 2.17.0 Protected, and records why it returned ''.
     * @param int $attachment_id Attachment to describe.
     * @return string Alt text, or '' when unavailable.
     */
    protected function generate_ai_alt(int $attachment_id): string {
        $this->last_ai_alt_error = '';

        try {
            $vision = new \ThinkRank\AI\Vision_Client();

            if (!$vision->is_available()) {
                $this->last_ai_alt_error = __('AI alt text is on, but the selected AI provider cannot describe images or has no API key.', 'thinkrank');

                return '';
            }

            // The parent post's title disambiguates images that are visually
            // ambiguous on their own (a generic chart, a product on white).
            $context = '';
            $parent  = (int) get_post_field('post_parent', $attachment_id);
            if ($parent > 0) {
                $context = (string) get_the_title($parent);
            }

            $alt = sanitize_text_field($vision->describe_attachment($attachment_id, $context));
            if ('' === $alt) {
                $this->last_ai_alt_error = __('The AI returned no description for this image.', 'thinkrank');
            }

            return $alt;
        } catch (\Throwable $e) {
            $this->last_ai_alt_error = wp_strip_all_tags($e->getMessage());

            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
                error_log(sprintf('[ThinkRank] AI alt text failed for attachment %d: %s', $attachment_id, $this->last_ai_alt_error));
            }

            return '';
        }
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
     *     @type bool $dry_run   Work out the values and return them as `proposed`
     *                           without writing anything or calling the AI. Default false.
     *     @type string $alt_source 'template' or 'ai' for this batch only. Default: the saved setting.
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
     *     @type int  $skipped_low_quality Images the template would only have given
     *                                     filler ("1", "image"), left without alt text.
     *     @type int  $skipped_no_permission Images the current user cannot edit, left unchanged.
     *     @type string $alt_source The source this batch used.
     *     @type int    $failed     Only when the batch asked for AI: images it could not describe, left unchanged.
     *     @type array  $failures   Only when the batch asked for AI: each of those images, with the reason.
     *     @type array  $ai_budget  Only when the source is AI: requests this batch used and what is left today.
     *     @type int    $template_fallbacks Only when the source is AI: changes written from the template
     *                                      because the provider could not describe the image.
     *     @type string $ai_unavailable Only when the saved source is AI but AI cannot run: why, and the
     *                                  batch ran as a template batch.
     *     @type int    $ai_failed AI mode: images the vision call failed for in this batch.
     *     @type string $ai_error  AI mode: the first failure's message, or ''.
     * }
     */
    public function bulk_fill_missing_alt(array $args = []): array {
        $previous_override = $this->alt_source_override;
        if (isset($args['alt_source']) && is_string($args['alt_source'])) {
            $this->alt_source_override = $this->normalize_alt_source($args['alt_source']);
        }
        // Dry runs preview only template output, even on a site whose saved source is AI.
        if (!empty($args['dry_run'])) {
            $this->alt_source_override = 'template';
        }
        try {
            return $this->run_alt_batch($args);
        } finally {
            $this->alt_source_override = $previous_override;
        }
    }

    /**
     * Run one bounded batch under its temporary source selection.
     *
     * @param array<string, mixed> $args Batch inputs.
     * @return array<string, mixed>
     */
    private function run_alt_batch(array $args): array {
        $offset    = max(0, (int) ($args['offset'] ?? 0));
        $limit     = min(200, max(1, (int) ($args['limit'] ?? 50)));
        $overwrite = !empty($args['overwrite']);
        $dry_run   = !empty($args['dry_run']);

        $source = $this->resolve_alt_source($this->get_settings('site'));

        // AI chosen by the saved setting, with no provider that can read
        // images (or the AI budget paused): every image would fall back to
        // the template anyway. Run the batch as a template batch and say so,
        // instead of reporting AI, an AI budget and the AI batch cap for text
        // the template wrote (#1208). A batch that asked for AI itself is
        // refused by the abilities before it gets here.
        $ai_unavailable = null;
        if (!$dry_run && 'ai' === $source && null === $this->alt_source_override) {
            $ai_unavailable = $this->ai_alt_unavailable_reason();
            if (null !== $ai_unavailable) {
                // bulk_fill_missing_alt() restores the override afterwards.
                $this->alt_source_override = 'template';
                $source                    = 'template';
            }
        }

        $used_before = 'ai' === $source ? \ThinkRank\AI\Spend_Guard::used_today() : 0;

        // In AI mode every image is a paid provider call that takes seconds,
        // so a 200-image batch would both surprise the user's bill and blow
        // past max_execution_time. Cap the batch and let the caller page —
        // `remaining` already drives that loop.
        if (!$dry_run && 'ai' === $source) {
            $limit = min($limit, self::AI_BATCH_LIMIT);
        }

        $total = $this->count_images();

        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            // Must cover the same set count_images() counts, or the pager can
            // never reach the total. 'inherit' alone excluded private-status
            // attachments — which media-protection and membership plugins do
            // create — while count_images() still counted them (#322).
            'post_status'      => ['inherit', 'private', 'publish', 'draft', 'pending', 'future'],
            'numberposts'      => $limit,
            'offset'           => $offset,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The pager must walk the same unfiltered set count_images() counts, or it can never reach the total (#322).
            'suppress_filters' => true,
        ]);

        $processed = 0;

        // Hold every purge until the batch is done: see $deferred_alt_purge.
        $this->deferred_alt_purge = [];
        $this->bulk_alt_changes   = [];
        $this->bulk_low_quality   = [];
        $this->bulk_dry_run       = $dry_run;
        $this->bulk_ai_failures   = [];
        $this->bulk_ai_errors     = [];

        // The REST route only asks for thinkrank_image_seo, which a site
        // owner can grant to an author. Without a per-image check that author
        // could rewrite alt text on everyone's media, overwrite included
        // (#1210). Only a request with a logged-in user is checked: the upload
        // hook, cron and WP-CLI without --user act for the site itself.
        $check_edit    = get_current_user_id() > 0;
        $not_permitted = 0;

        try {
            foreach ($ids as $id) {
                $processed++;

                if ($check_edit && !current_user_can('edit_post', (int) $id)) {
                    $not_permitted++;
                    continue;
                }

                $this->fill_attachment_alt((int) $id, $overwrite);
            }
        } finally {
            $written                   = array_values(array_unique($this->deferred_alt_purge ?? []));
            $changes                   = $this->bulk_alt_changes ?? [];
            $low_quality               = count(array_unique($this->bulk_low_quality ?? []));
            $failures                  = $this->bulk_ai_failures ?? [];
            $ai_errors                 = $this->bulk_ai_errors ?? [];
            $this->deferred_alt_purge  = null;
            $this->bulk_alt_changes    = null;
            $this->bulk_ai_failures    = null;
            $this->bulk_ai_errors      = null;
            $this->bulk_low_quality    = null;
            $this->bulk_dry_run        = false;
            $cache                     = $this->purge_alt_caches($written);
        }

        // An AI batch names the source of every change: an image the provider
        // could not describe falls back to the template (#1208).
        $with_source = 'ai' === $source;
        $change_rows = array_map(
            static function (int $id, array $change) use ($with_source): array {
                $row = ['id' => $id, 'before' => $change['before'], 'after' => $change['after']];
                if ($with_source) {
                    $row['source'] = $change['source'] ?? 'template';
                }

                return $row;
            },
            array_keys($changes),
            array_values($changes)
        );

        // A dry run previews template candidates only. It is not a preview of
        // the saved AI source, and its offsets are not an exact-write selection.
        if ($dry_run) {
            $next_offset = $offset + count($ids);

            return [
                'dry_run'             => true,
                'alt_source'          => 'template',
                'total'               => $total,
                'processed'           => $processed,
                'updated'             => 0,
                'skipped'             => max(0, $processed - count($change_rows)),
                'skipped_low_quality' => $low_quality,
                'skipped_no_permission' => $not_permitted,
                'offset'              => $offset,
                'next_offset'         => $next_offset,
                'remaining'           => empty($ids) ? 0 : max(0, $total - $next_offset),
                'done'                => empty($ids) || $next_offset >= $total,
                'proposed'            => $change_rows,
                'changes'             => [],
                'cache'               => ['posts_purged' => 0, 'warnings' => []],
            ];
        }

        // Counted from the writes that actually happened, not from what
        // fill_attachment_alt() returned. It answers true for an image whose
        // stored alt already equals the generated value, which is the right
        // answer to "does this image have its alt text?" and the wrong one to
        // "how many did you change" — a re-run over a correct library reported
        // every image as updated while writing nothing. `updated` and
        // `skipped` have documented the write, not the return value, since
        // this method was added.
        $updated = count($written);
        $failed  = count($failures);
        $skipped = max(0, $processed - $updated - $failed);

        $next_offset = $offset + count($ids);

        // An empty batch means there is nothing left to walk, whatever the
        // total claims. Deriving `done` from the count alone let any drift
        // between the two queries strand the caller on a batch that could
        // never advance the offset, and the admin UI answers that by
        // re-requesting up to 10,000 times.
        $exhausted = empty($ids);
        $remaining = $exhausted ? 0 : max(0, $total - $next_offset);

        // Bulk writes change the Site SEO Analyzer's "images have alt text" coverage.
        if ($updated > 0) {
            $this->flush_analyzer_cache();
        }

        $report = [
            'total'       => $total,
            'processed'   => $processed,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'offset'      => $offset,
            'next_offset' => $next_offset,
            'remaining'   => $remaining,
            'done'        => $exhausted || $next_offset >= $total,
            'skipped_low_quality' => $low_quality,
            'skipped_no_permission' => $not_permitted,
            'changes'     => $change_rows,
            'cache'       => [
                'posts_purged' => count($cache['posts']),
                'warnings'     => $cache['warnings'],
            ],
            // AI mode only: images the vision call failed for. They kept the
            // alt text they had, or got the template when they had none.
            'ai_failed'   => count($ai_errors),
            'ai_error'    => (string) (array_values(array_filter($ai_errors))[0] ?? ''),
            'alt_source'  => $source,
        ];

        if (null !== $ai_unavailable) {
            $report['ai_unavailable'] = $ai_unavailable;
        }

        // Only a batch that asked for AI itself records failures; one run
        // from the saved setting falls back to the template instead. Those
        // images keep the alt text they had.
        if ([] !== $failures || 'ai' === ($args['alt_source'] ?? null)) {
            $report['failed']   = $failed;
            $report['failures'] = array_map(
                static function (int $id, string $reason): array {
                    return ['id' => $id, 'reason' => $reason];
                },
                array_keys($failures),
                array_values($failures)
            );
        }

        // Each AI image is a paid request. Report what this batch spent and
        // what is left today, so an agent paging through a library can stop
        // before the budget runs out rather than after (#1123).
        if ('ai' === $source) {
            $report['ai_budget']          = $this->ai_budget_report($used_before);
            $report['template_fallbacks'] = count(array_filter(
                $change_rows,
                static function (array $row): bool {
                    return 'template' === $row['source'];
                }
            ));
        }

        return $report;
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

        // No cache purge: this fires on add_attachment, so the file was created
        // seconds ago and no page can be displaying it yet. Skipping the lookup
        // keeps a bulk media import off two unindexed postmeta scans per file.
        $this->fill_attachment_alt($attachment_id, !empty($settings['media_alt_overwrite']), false);
    }

    /**
     * Total number of image attachments in the library.
     *
     * Counted with an explicit `post_status != 'trash'` rather than through
     * wp_count_attachments(). The helper applies that filter internally, which
     * looked equivalent — but it left the two halves of get_media_alt_stats()
     * with different notions of which images exist, and only one of them said
     * so out loud. Spelling the filter out here keeps this query and
     * count_images_with_alt() visibly in step (#321).
     *
     * @since 1.19.1
     * @return int
     */
    private function count_images(): int {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- indexed COUNT; short-lived admin action
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'
               AND post_status != 'trash'"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Number of image attachments that already have descriptive alt text.
     *
     * Filler such as "1", "3-4", "image" or "IMG_1234" does not count
     * (#1124, #1207): see Alt_Text_Coverage::count_covered().
     *
     * Carries the same `post_status != 'trash'` filter as count_images(), so a
     * trashed image can never be counted as covered against a total it is not
     * part of. Matches the Site SEO Analyzer's alt-text check, which applies
     * the same filter to both of its counts.
     *
     * @since 1.19.1
     * @return int
     */
    private function count_images_with_alt(): int {
        return Alt_Text_Coverage::count_covered($this->wpdb);
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
        // Ask the analyzer rather than duplicating its transient key here — the
        // literal drifted out of sync the moment anyone renamed it.
        if (class_exists('ThinkRank\\SEO\\SEO_Analyzer')) {
            (new SEO_Analyzer())->flush_cache();
        }
    }
}
