<?php
/**
 * Per-taxonomy and per-post-type-archive title and description templates.
 *
 * Global SEO had title and description templates for singular content only.
 * Every custom taxonomy shared the one Site Identity "Archive Title" format,
 * no taxonomy had a default meta description, and a post type archive had
 * neither, so a site with hundreds of recipe categories could only fix them
 * one term at a time (#1425).
 *
 * Storage is the `thinkrank_global_seo_settings` option the Content Type
 * Matrix and the per-post-type screens already share:
 *
 * - a taxonomy's templates sit in its matrix row, `taxonomy:<slug>`, under
 *   `title` and `description`, the names a post type row uses for its own;
 * - a post type archive's sit in the post type's row, under `archive_title`
 *   and `archive_description`, beside the singular templates.
 *
 * Every template is empty until someone sets it, and an empty template is
 * skipped, so a site that never sets one renders exactly what it did before.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.20.1
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and resolves the archive templates.
 *
 * @since 2.20.1
 */
final class Archive_Templates {

    /**
     * Template fields, as stored in a taxonomy's row.
     *
     * @var string[]
     */
    public const FIELDS = ['title', 'description'];

    /**
     * Post type row keys holding the archive templates, by field.
     *
     * @var array<string, string>
     */
    public const POST_TYPE_ARCHIVE_KEYS = [
        'title'       => 'archive_title',
        'description' => 'archive_description',
    ];

    /**
     * A taxonomy's stored template.
     *
     * @param string $taxonomy Taxonomy slug.
     * @param string $field    'title' or 'description'.
     * @return string Template, or '' when none is set.
     */
    public static function taxonomy_template(string $taxonomy, string $field): string {
        if (!in_array($field, self::FIELDS, true) || '' === $taxonomy) {
            return '';
        }

        $row = Content_Type_Settings::get_entity_settings(Content_Type_Settings::PREFIX_TAXONOMY . $taxonomy);

        return is_string($row[$field] ?? null) ? trim($row[$field]) : '';
    }

    /**
     * A post type archive's stored template.
     *
     * @param string $post_type Post type slug.
     * @param string $field     'title' or 'description'.
     * @return string Template, or '' when none is set.
     */
    public static function post_type_archive_template(string $post_type, string $field): string {
        $key = self::POST_TYPE_ARCHIVE_KEYS[$field] ?? '';
        if ('' === $key || '' === $post_type) {
            return '';
        }

        $row = Content_Type_Settings::get_entity_settings($post_type);

        return is_string($row[$key] ?? null) ? trim($row[$key]) : '';
    }

    /**
     * The title a term archive prints from its taxonomy's template.
     *
     * @param \WP_Term $term Term.
     * @return string Title, or '' when the taxonomy has no template or it
     *                resolves to nothing.
     */
    public static function term_title(\WP_Term $term): string {
        $template = self::taxonomy_template((string) $term->taxonomy, 'title');

        return '' === $template ? '' : Pattern_Resolver::resolve_term_value($template, (int) $term->term_id);
    }

    /**
     * The meta description a term archive prints from its taxonomy's template.
     *
     * @param \WP_Term $term Term.
     * @return string Description, trimmed to length, or ''.
     */
    public static function term_description(\WP_Term $term): string {
        $template = self::taxonomy_template((string) $term->taxonomy, 'description');
        if ('' === $template) {
            return '';
        }

        return self::description_text(Pattern_Resolver::resolve_term_value($template, (int) $term->term_id));
    }

    /**
     * The title a post type archive prints from its template.
     *
     * @param string $post_type Post type slug.
     * @return string Title, or ''.
     */
    public static function post_type_archive_title(string $post_type): string {
        $template = self::post_type_archive_template($post_type, 'title');

        return '' === $template ? '' : Pattern_Resolver::resolve_post_type_archive_value($template, $post_type);
    }

    /**
     * The meta description a post type archive prints from its template.
     *
     * @param string $post_type Post type slug.
     * @return string Description, trimmed to length, or ''.
     */
    public static function post_type_archive_description(string $post_type): string {
        $template = self::post_type_archive_template($post_type, 'description');
        if ('' === $template) {
            return '';
        }

        return self::description_text(Pattern_Resolver::resolve_post_type_archive_value($template, $post_type));
    }

    /**
     * A resolved description as the meta tag prints it: plain text, cut in
     * characters rather than bytes (#687).
     *
     * @param string $resolved Resolved template.
     * @return string
     */
    private static function description_text(string $resolved): string {
        $text = \ThinkRank\Core\Seo_Text::plain_text($resolved);

        return '' === $text ? '' : \ThinkRank\Core\Seo_Text::trim_to_length($text);
    }
}
