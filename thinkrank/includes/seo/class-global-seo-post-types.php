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
     * `public => true` only for previews); and it must not be on the filterable
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
        // Elements"). Built-in types (post/page/attachment) are always kept.
        if ($object->_builtin === false && !is_post_type_viewable($object)) {
            return false;
        }

        // Named deny list for viewable-but-unsuitable builder/utility CPTs.
        // Filterable so integrators can tune it without patching core. The
        // post-type object is passed as the second argument (matches the admin
        // discovery filter usage).
        $excluded = apply_filters('thinkrank_global_seo_excluded_post_types', [
            'elementor_library', 'oceanwp_library', 'ae_global_templates',
            'e-floating-buttons', 'elementor_component',
            // Divi: the library plus every Theme Builder template type.
            'et_pb_layout', 'et_theme_builder', 'et_template',
            'et_header_layout', 'et_body_layout', 'et_footer_layout',
        ], $object);

        return !in_array($object->name, (array) $excluded, true);
    }
}
