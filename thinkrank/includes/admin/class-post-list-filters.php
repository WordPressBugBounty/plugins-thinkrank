<?php

/**
 * Post List Filters Class
 * 
 * Handles custom filters in WordPress admin post list tables
 * 
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post List Filters Class
 * 
 * Single Responsibility: Manage custom filters in post list tables
 * 
 * @since 1.0.0
 */
class Post_List_Filters {

    /**
     * Initialize post list filters
     * 
     * @return void
     */
    public function init(): void {
        add_action('admin_init', [$this, 'register_views']);
        add_action('pre_get_posts', [$this, 'handle_filter']);
    }

    /**
     * Register views hook for supported post types
     * 
     * @return void
     */
    public function register_views(): void {
        $post_types = $this->get_supported_post_types();

        foreach ($post_types as $post_type) {
            add_filter("views_edit-{$post_type}", [$this, 'add_pillar_content_filter_link']);
        }
    }

    /**
     * Get supported post types
     * 
     * @return array Array of post type names
     */
    /**
     * Get supported post types
     * 
     * @return array Array of post type names
     */
    private function get_supported_post_types(): array {
        $post_types = get_post_types(['public' => true], 'names');

        // Remove unnecessary post types
        unset($post_types['attachment']);
        unset($post_types['elementor_library']);
        unset($post_types['oceanwp_library']);
        unset($post_types['ae_global_templates']);
        // Divi library + Theme Builder templates.
        unset($post_types['et_pb_layout']);
        unset($post_types['et_theme_builder']);
        unset($post_types['et_template']);
        unset($post_types['et_header_layout']);
        unset($post_types['et_body_layout']);
        unset($post_types['et_footer_layout']);

        // Filter based on Global SEO settings
        $settings = get_option('thinkrank_global_seo_settings', []);

        foreach ($post_types as $post_type) {
            // Check if link suggestions is enabled
            // Default is true for everything except product which is false
            $default = ($post_type === 'product') ? false : true;

            if (isset($settings[$post_type]['link_suggestions'])) {
                $enabled = (bool) $settings[$post_type]['link_suggestions'];
            } else {
                $enabled = $default;
            }

            if (!$enabled) {
                unset($post_types[$post_type]);
            }
        }

        /**
         * Filter supported post types for ThinkRank Pillar Content filter
         * 
         * @param array $post_types Array of post type names
         */
        return apply_filters('thinkrank_pillar_content_filter_post_types', $post_types);
    }

    /**
     * Add Pillar Content filter link
     * 
     * @param array $views Existing views
     * @return array Modified views
     */
    public function add_pillar_content_filter_link(array $views): array {
        global $typenow;

        // Count posts with Pillar Content enabled. We only need the total, so cap
        // the result set at a single row and read found_posts — no reason to pull
        // every matching ID into memory on each post-list render.
        $args = [
            'post_type' => $typenow,
            'meta_key' => '_thinkrank_pillar_content',
            'meta_value' => '1',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $query = new \WP_Query($args);
        $count = $query->found_posts;

        // Current status
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL filter parameter, not processing form data.
        $current = isset($_GET['thinkrank_filter']) && sanitize_key(wp_unslash($_GET['thinkrank_filter'])) === 'pillar_content';
        $class = $current ? 'class="current"' : '';

        // Build URL
        $url = add_query_arg([
            'post_type' => $typenow,
            'thinkrank_filter' => 'pillar_content',
        ], admin_url('edit.php'));

        $views['thinkrank_pillar_content'] = sprintf(
            '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
            esc_url($url),
            $class,
            __('Pillar Content', 'thinkrank'),
            $count
        );

        return $views;
    }

    /**
     * Handle query modification for filter
     * 
     * @param \WP_Query $query WordPress query object
     * @return void
     */
    public function handle_filter(\WP_Query $query): void {
        // Only run on admin main query
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        // Check screen base
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit') {
            return;
        }

        // Check if our filter is active
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL filter parameter, not processing form data.
        if (!isset($_GET['thinkrank_filter']) || sanitize_key(wp_unslash($_GET['thinkrank_filter'])) !== 'pillar_content') {
            return;
        }

        // Add meta query
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = [];
        }

        $meta_query[] = [
            'key' => '_thinkrank_pillar_content',
            'value' => '1',
            'compare' => '=',
        ];

        $query->set('meta_query', $meta_query);
    }
}
