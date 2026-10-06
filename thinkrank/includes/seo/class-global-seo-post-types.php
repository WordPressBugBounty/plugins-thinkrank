<?php
/**
 * Global SEO Post Type Policy
 *
 * Single source of truth for which post types are valid Global SEO targets,
 * shared by the admin post-type discovery (which populates the UI), the REST
 * validator, and the MCP ability validator so all three agree. Previously the
 * UI hid ineligible types while the write paths accepted any public type,
 * letting direct REST/ability calls persist settings for types the UI
 * classifies as unsuitable.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.20.1
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global SEO post-type eligibility policy.
 *
 * @since 1.20.1
 */
class Global_SEO_Post_Types {

    /**
     * Whether a post type may receive Global SEO settings.
     *
     * Policy: the type must be public; a non-built-in type must also be
     * front-end viewable (excludes builder/utility CPTs that register
     * `public => true` only for previews); WordPress must actually render a page
     * for it; and it must not be on the filterable
     * `thinkrank_global_seo_excluded_post_types` deny list.
     *
     * @param \WP_Post_Type|string $post_type Post type object or name.
     * @return bool
     */
    public static function is_allowed($post_type): bool {
        $object = is_string($post_type) ? get_post_type_object($post_type) : $post_type;

        if (!$object instanceof \WP_Post_Type || empty($object->public)) {
            return false;
        }

        // Builder/utility CPTs that register public => true for preview purposes
        // but aren't front-end viewable content (e.g. Elementor's "Floating
        // Elements"). Built-in types (post/page) are always kept.
        if ($object->_builtin === false && !is_post_type_viewable($object)) {
            return false;
        }

        // Attachment is built-in and public, so the rule above keeps it, but on
        // a site that does not serve attachment pages there is no document for
        // any of these settings to reach (#843).
        if ('attachment' === $object->name && !self::renders_a_page($object)) {
            return false;
        }

        return !in_array($object->name, self::excluded_post_types($object), true);
    }

    /**
     * Whether WordPress renders an HTML page for this post type on this site.
     *
     * Only `attachment` can answer no. Core has redirected attachment URLs
     * straight to the file since WordPress 6.4, and does so by default on a new
     * install: the request 301s to the uploads path, so there is no <head> for a
     * title, a robots meta, a canonical or JSON-LD to appear in. Every Global
     * SEO control for the type is therefore inert, while the screen presents
     * itself exactly like the ones that work.
     *
     * Read from the option core itself consults rather than hardcoded, and kept
     * in one place so the admin nav, the REST validator and the ability
     * validators cannot disagree about it. A site with attachment pages enabled
     * is unaffected and keeps every setting it has.
     *
     * Deliberately not applied to any other type: `is_post_type_viewable()`
     * already covers the general case, and this is a core quirk specific to
     * attachments.
     *
     * @since 2.14.0
     *
     * @param \WP_Post_Type|string $post_type Post type object or name.
     * @return bool
     */
    public static function renders_a_page($post_type): bool {
        $object = is_string($post_type) ? get_post_type_object($post_type) : $post_type;

        if (!$object instanceof \WP_Post_Type) {
            return false;
        }

        if ('attachment' !== $object->name) {
            return true;
        }

        // The same test core's own redirect makes, in wp-includes/canonical.php:
        //     if ( is_attachment() && ! get_option( 'wp_attachment_pages_enabled' ) )
        // so this agrees with what actually happens to the request. Core has no
        // accessor for it; several core files read the option directly, and two
        // of them compare against '1' rather than testing truthiness. The
        // redirect uses the truthy form, and the redirect is what decides
        // whether a document exists, so that is the form matched here.
        //
        // The default is false because core's is: schema.php seeds a fresh
        // install with 0, while upgrade.php sets 1 for a site upgrading from
        // before 6.4, which keeps its attachment pages and is unaffected.
        return (bool) get_option('wp_attachment_pages_enabled', false);
    }

    /**
     * Every post type that passes {@see self::is_allowed()}.
     *
     * The sitewide half of duplicate snippet detection needs the whole list
     * rather than one name, because a duplicate title is a duplicate whichever
     * post type the other page happens to be (#564).
     *
     * @since 2.10.0
     *
     * @return string[] Post type names.
     */
    public static function allowed(): array {
        $allowed = [];

        foreach (get_post_types(['public' => true], 'objects') as $object) {
            if (self::is_allowed($object)) {
                $allowed[] = $object->name;
            }
        }

        return $allowed;
    }

    /**
     * Builder and utility CPTs that are not optimizable content.
     *
     * These register `public => true` for preview purposes but are layout
     * fragments, not pages anyone gives a title and a meta description.
     *
     * Shared rather than duplicated: the per-post SEO metabox discovers post
     * types by the same public/`show_ui` rule and used to keep its own
     * exclusion list, which named only WordPress internals. The two policies
     * disagreed — a builder template CPT was denied Global SEO while still
     * getting a full metabox — so both now read this one list (#621).
     *
     * @since 2.2.1
     *
     * Deliberately untyped: this is passed straight to a public filter whose
     * existing contract accepts whatever the caller has (a `WP_Post_Type`, or
     * null when the list is wanted without a subject).
     *
     * @param mixed $post_type_object Post type being judged, passed to the filter.
     * @return string[] Post type names to exclude.
     */
    public static function excluded_post_types($post_type_object = null): array {
        // Filterable so integrators can tune it without patching core. The
        // post-type object is passed as the second argument (matches the admin
        // discovery filter usage).
        $excluded = apply_filters('thinkrank_global_seo_excluded_post_types', [
            'elementor_library', 'oceanwp_library', 'ae_global_templates',
            'e-floating-buttons', 'elementor_component',
            // Divi: the library plus every Theme Builder template type.
            'et_pb_layout', 'et_theme_builder', 'et_template',
            'et_header_layout', 'et_body_layout', 'et_footer_layout',
            // Beaver Builder: saved templates (rows, columns, modules) and
            // Beaver Themer's layouts. Same clutter Divi's Theme Builder
            // templates caused before they were listed here (#449).
            'fl-builder-template', 'fl-theme-layout',
            // Bricks: saved templates plus the header/footer/section layouts
            // its Theme Builder stores as the same CPT (#257).
            'bricks_template',
        ], $post_type_object);

        return array_values(array_filter((array) $excluded, 'is_string'));
    }
}
