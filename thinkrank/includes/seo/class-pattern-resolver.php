<?php
/**
 * Variable-tag pattern resolver.
 *
 * Resolves the Global / Bulk SEO variable-tag patterns (e.g.
 * "%title% %sep% %sitename%") into concrete values for a specific post,
 * independent of the main query / loop. Used to preview, inside the post
 * editor, the value the frontend will output when a per-post SEO field is left
 * empty — the frontend already falls back to these same patterns.
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves Global SEO patterns for an explicit post.
 *
 * @since 1.0.0
 */
class Pattern_Resolver {

    /**
     * Option holding the per-post-type Global SEO patterns.
     */
    private const OPTION_NAME = 'thinkrank_global_seo_settings';

    /**
     * Default title pattern (mirrors the Global SEO endpoint default).
     */
    private const DEFAULT_TITLE = '%title% %sep% %sitename%';

    /**
     * Default description pattern (mirrors the Global SEO endpoint default).
     */
    private const DEFAULT_DESCRIPTION = '%excerpt%';

    /**
     * Post meta key holding the per-post SEO title.
     */
    private const META_TITLE = '_thinkrank_seo_title';

    /**
     * Post meta key holding the per-post meta description.
     */
    private const META_DESCRIPTION = '_thinkrank_meta_description';

    /**
     * Resolve the SEO title pattern for a post.
     *
     * @param int $post_id Post ID.
     * @return string Resolved title, or '' when it resolves to nothing.
     */
    public static function title(int $post_id): string {
        $template = self::template_for($post_id, 'title', self::DEFAULT_TITLE);
        return self::resolve_value($template, $post_id);
    }

    /**
     * Resolve any variable-tag string against a post's values.
     *
     * Replaces tokens (e.g. "%title% %sep% %sitename%") with the post's actual
     * values. A literal string containing no tokens passes through unchanged, so
     * this is safe to run over per-post SEO fields that may or may not hold a
     * pattern.
     *
     * @param string $value   Raw string, possibly containing variable tags.
     * @param int    $post_id Post ID.
     * @return string Resolved string.
     */
    public static function resolve_value(string $value, int $post_id): string {
        if (strpos($value, '%') === false) {
            return $value;
        }
        return self::process($value, self::placeholders_for($post_id));
    }

    /**
     * Sanitize a variable-tag template for storage.
     *
     * The write-side counterpart of resolve_value(): every template that
     * reaches this class has to survive the trip into the database first.
     *
     * sanitize_text_field() cannot be used for that. Core's
     * _sanitize_text_fields() strips percent-encoded characters, looping
     * `preg_replace( '/%[a-f0-9]{2}/i', ... )` until nothing matches, so any
     * token whose first two characters are hex digits is eaten on save:
     * %date% is stored as "te%" and %category% as "tegory%" (#521). They are
     * the only two tags in the language that collide, which is why the
     * corruption looked arbitrary — %title%, %sitename%, %sep%, %excerpt%,
     * %modified% and %author% all pass through core untouched.
     *
     * This mirrors what core does either side of that percent loop — invalid
     * UTF-8 dropped, tags stripped, control characters removed, whitespace
     * collapsed — and simply omits the loop itself.
     *
     * @since 2.1.1
     *
     * @param string $value         Raw template as submitted.
     * @param bool   $keep_newlines Preserve newlines, as sanitize_textarea_field() does.
     * @return string Sanitized template with its %tokens% intact.
     */
    public static function sanitize_template(string $value, bool $keep_newlines = false): string {
        $filtered = wp_check_invalid_utf8($value);

        if (strpos($filtered, '<') !== false) {
            $filtered = wp_pre_kses_less_than($filtered);
            // Tags out, the text between them kept.
            $filtered = wp_strip_all_tags($filtered, false);
            $filtered = str_replace("<\n", "&lt;\n", $filtered);
        }

        // C0 controls and DEL, less the tab/newline/carriage-return handled below.
        $filtered = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $filtered);

        if (!$keep_newlines) {
            $filtered = (string) preg_replace('/[\r\n\t ]+/', ' ', $filtered);
        }

        return trim($filtered);
    }

    /**
     * Sanitize a variable-tag template that may span multiple lines.
     *
     * The sanitize_textarea_field() counterpart of sanitize_template().
     *
     * @since 2.1.1
     *
     * @param string $value Raw template as submitted.
     * @return string Sanitized template with its %tokens% and newlines intact.
     */
    public static function sanitize_template_textarea(string $value): string {
        return self::sanitize_template($value, true);
    }

    /**
     * Derive a description from raw post content.
     *
     * wp_strip_all_tags() removes HTML but not shortcodes, so a page built with
     * them published its shortcode source as the description — `[woocommerce_cart]`
     * as the meta description, og:description and twitter:description of the
     * cart page. Core's own wp_trim_excerpt() runs strip_shortcodes() and
     * excerpt_remove_blocks() first; this path did neither, which is why the
     * two disagreed about the same post (#387).
     *
     * @since 2.0.1
     *
     * @param string $content Raw post content.
     * @param int    $words   Word cap.
     * @return string Derived description, or '' when nothing survives.
     */
    public static function derive_excerpt(string $content, int $words = 25): string {
        if ('' === trim($content)) {
            return '';
        }

        $text = excerpt_remove_blocks($content);
        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text);

        return trim(wp_trim_words($text, $words, '...'));
    }

    /**
     * Resolve any variable-tag string against a term's values.
     *
     * The term counterpart of resolve_value(). Term SEO fields reach the
     * frontend from three writers — the term UI, the abilities API and the
     * Yoast/RankMath/AIOSEO/SEOPress importer — and the importers already
     * substitute their own term tokens (%%term_title%%, %term%) with the term
     * name at export time, so what lands here is either literal text or
     * ThinkRank's own tags.
     *
     * @since 2.0.1
     *
     * @param string $value   Raw string, possibly containing variable tags.
     * @param int    $term_id Term ID.
     * @return string Resolved string.
     */
    public static function resolve_term_value(string $value, int $term_id): string {
        if (strpos($value, '%') === false) {
            return $value;
        }
        return self::process($value, self::placeholders_for_term($term_id));
    }

    /**
     * Token => value map for a term.
     *
     * The post-only tokens resolve to an empty string rather than being left
     * unreplaced: they have no meaning on an archive, and process() collapses
     * the separators an empty token leaves behind. A raw "%author%" in the
     * rendered title would be worse than nothing.
     *
     * @since 2.0.1
     *
     * @param int $term_id Term ID.
     * @return array<string,string> Placeholder map.
     */
    private static function placeholders_for_term(int $term_id): array {
        $term = get_term($term_id);

        $name        = ($term && !is_wp_error($term)) ? $term->name : '';
        $description = ($term && !is_wp_error($term)) ? (string) $term->description : '';

        return [
            '%title%'     => $name,
            '%term%'      => $name,
            '%sitename%'  => get_bloginfo('name'),
            '%sep%'       => self::separator(),
            '%excerpt%'   => $description !== ''
                ? wp_trim_words(wp_strip_all_tags($description), 25, '...')
                : '',
            '%date%'      => '',
            '%modified%'  => '',
            '%author%'    => '',
            '%category%'  => '',
        ];
    }

    /**
     * Token => value map for a post, keyed WITHOUT the surrounding percents
     * (e.g. 'title' => 'My Post'). Used by the editor for live client-side
     * preview of a pattern as the user types.
     *
     * @param int $post_id Post ID.
     * @return array<string,string> Variable map.
     */
    public static function variables(int $post_id): array {
        $map = [];
        foreach (self::placeholders_for($post_id) as $token => $value) {
            $map[trim($token, '%')] = $value;
        }
        return $map;
    }

    /**
     * Resolve the meta description pattern for a post.
     *
     * Trimmed to the same ~160-char ceiling the frontend applies on output.
     *
     * @param int $post_id Post ID.
     * @return string Resolved description, or '' when it resolves to nothing.
     */
    public static function description(int $post_id): string {
        $template = self::template_for($post_id, 'description', self::DEFAULT_DESCRIPTION);
        $description = self::resolve_value($template, $post_id);

        if (strlen($description) > 160) {
            $description = wp_trim_words($description, 25, '...');
        }

        return $description;
    }

    /**
     * Effective SEO title for a post: the per-post custom value (with any
     * variable tags resolved) when set, otherwise the rendered Global/Bulk
     * title pattern. This is the value the frontend actually outputs.
     *
     * Scoring MUST use this rather than the raw `_thinkrank_seo_title` meta —
     * an empty meta means "inherit the global pattern", not "no title", so the
     * raw value would make an inherited-title post score as if it had none.
     *
     * @param int $post_id Post ID.
     * @return string Effective title.
     */
    public static function effective_title(int $post_id): string {
        return self::effective_value(
            (string) get_post_meta($post_id, self::META_TITLE, true),
            $post_id,
            'title'
        );
    }

    /**
     * Effective meta description for a post: the per-post custom value (with any
     * variable tags resolved) when set, otherwise the rendered Global/Bulk
     * description pattern. Counterpart to {@see self::effective_title()}.
     *
     * @param int $post_id Post ID.
     * @return string Effective description.
     */
    public static function effective_description(int $post_id): string {
        return self::effective_value(
            (string) get_post_meta($post_id, self::META_DESCRIPTION, true),
            $post_id,
            'description'
        );
    }

    /**
     * Resolve a raw per-post field to its effective value.
     *
     * When the raw value is non-empty its variable tags are resolved; when it is
     * empty the field falls back to the rendered Global/Bulk pattern. Exposed so
     * callers that already hold a raw value (e.g. the SEO score endpoint scoring
     * unsaved editor input) can route through the same fallback logic.
     *
     * @param string $raw     Raw per-post field value (may hold variable tags).
     * @param int    $post_id Post ID.
     * @param string $field   Which pattern to fall back to: 'title' or 'description'.
     * @return string Effective value.
     */
    public static function effective_value(string $raw, int $post_id, string $field): string {
        if ($raw !== '') {
            return self::resolve_value($raw, $post_id);
        }

        return 'description' === $field
            ? self::description($post_id)
            : self::title($post_id);
    }

    /**
     * Build the full set of pattern previews for the post editor.
     *
     * Social fields mirror the frontend fallback: an empty og/twitter title
     * resolves to the SEO title, and an empty og/twitter description to the
     * meta description.
     *
     * @param int $post_id Post ID.
     * @return array<string,string> Resolved previews keyed by metabox field.
     */
    public static function previews(int $post_id): array {
        $title = self::title($post_id);
        $description = self::description($post_id);

        return [
            'seo_title' => $title,
            'meta_description' => $description,
            'og_title' => $title,
            'og_description' => $description,
            'twitter_title' => $title,
            'twitter_description' => $description,
        ];
    }

    /**
     * Get the configured pattern for a post type, falling back to a default.
     *
     * @param int    $post_id Post ID.
     * @param string $key     Setting key ('title' or 'description').
     * @param string $fallback Default pattern.
     * @return string Pattern template.
     */
    private static function template_for(int $post_id, string $key, string $fallback): string {
        $post_type = get_post_type($post_id) ?: 'post';
        $all = get_option(self::OPTION_NAME, []);
        $template = $all[$post_type][$key] ?? '';

        return is_string($template) && $template !== '' ? $template : $fallback;
    }

    /**
     * Build placeholder values for an explicit post (no loop dependency).
     *
     * @param int $post_id Post ID.
     * @return array<string,string> Placeholder map.
     */
    private static function placeholders_for(int $post_id): array {
        $post = get_post($post_id);

        $excerpt = '';
        if ($post) {
            $excerpt = !empty($post->post_excerpt)
                ? $post->post_excerpt
                : self::derive_excerpt((string) $post->post_content);
        }

        $author_id = (int) get_post_field('post_author', $post_id);

        $category = '';
        if (get_post_type($post_id) === 'post') {
            $categories = get_the_category($post_id);
            $category = !empty($categories) ? $categories[0]->name : '';
        }

        return [
            '%title%' => get_the_title($post_id),
            '%sitename%' => get_bloginfo('name'),
            '%sep%' => self::separator(),
            '%excerpt%' => $excerpt,
            '%date%' => get_the_date('', $post_id),
            '%modified%' => get_the_modified_date('', $post_id),
            '%author%' => $author_id ? get_the_author_meta('display_name', $author_id) : '',
            '%category%' => $category,
        ];
    }

    /**
     * Active title separator symbol.
     *
     * @return string Separator.
     */
    private static function separator(): string {
        if (class_exists('\ThinkRank\SEO\Site_Identity_Manager')) {
            return Site_Identity_Manager::get_active_separator_symbol();
        }
        return '-';
    }

    /**
     * Replace placeholders and tidy the result (mirrors the frontend cleanup).
     *
     * @param string               $template     Pattern template.
     * @param array<string,string> $placeholders Placeholder map.
     * @return string Resolved string.
     */
    private static function process(string $template, array $placeholders): string {
        $value = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Collapse whitespace.
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);

        // Collapse doubled separators left by empty tokens (e.g. "| |" -> "|").
        $separator = $placeholders['%sep%'] ?? '|';
        $separator_pattern = preg_quote($separator, '/');
        $value = preg_replace(
            '/\s*' . $separator_pattern . '\s*' . $separator_pattern . '\s*/',
            ' ' . $separator . ' ',
            $value
        );

        // Strip leading/trailing separators and whitespace.
        return trim($value, " \t\n\r\0\x0B" . $separator);
    }
}
