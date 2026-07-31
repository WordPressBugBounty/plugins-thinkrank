<?php

/**
 * Bulk Action Manager Class
 *
 * Handles global bulk action registration and the separator logic.
 *
 * @package ThinkRank\Admin
 * @since 1.1.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bulk Action Manager Class
 * 
 * Manages the "ThinkRank" group in bulk actions dropdown.
 * 
 * @since 1.1.0
 */
class Bulk_Action_Manager {

    /**
     * Initialize the component
     * 
     * @since 1.1.0
     * @return void
     */
    public function init(): void {
        add_action('admin_init', [$this, 'register_hooks']);
    }

    /**
     * Register hooks
     * 
     * @since 1.1.0
     * @return void
     */
    public function register_hooks(): void {
        // We need to target all supported post types.
        // A generic approach is to get all public post types.
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_filter("bulk_actions-edit-{$post_type}", function ($bulk_actions) use ($post_type) {
                return $this->inject_bulk_actions($bulk_actions, $post_type);
            });
        }

        add_action('admin_enqueue_scripts', [$this, 'bulk_action_footer_script']);
    }

    /**
     * Inject ThinkRank bulk actions
     * 
     * @since 1.1.0
     * @param array $bulk_actions Existing bulk actions.
     * @param string $post_type Current post type.
     * @return array
     */
    public function inject_bulk_actions(array $bulk_actions, string $post_type): array {
        $new_actions = [];

        // Allow modules to add their actions
        // Filter: thinkrank_bulk_actions
        // Args: $new_actions (empty array), $post_type
        $thinkrank_actions = apply_filters('thinkrank_bulk_actions', [], $post_type);

        // If no actions were added, return original array
        if (empty($thinkrank_actions)) {
            return $bulk_actions;
        }

        // Add separator at the top of our group
        $new_actions['thinkrank_separator'] = '— ' . __('ThinkRank', 'thinkrank') . ' —';

        // Merge module actions
        $new_actions = array_merge($new_actions, $thinkrank_actions);

        // Merge our group into the main list
        // We append to the end or merge. 
        // array_merge might reindex numeric keys but bulk actions use string keys.
        return array_merge($bulk_actions, $new_actions);
    }

    /**
     * Disable the "separator" bulk action option on post-list screens.
     *
     * Registered on admin_enqueue_scripts and attached as an inline script to the
     * core jQuery handle (rather than echoing a raw <script> in admin_footer) so it
     * passes Plugin Check's inline-script rule.
     *
     * @since 1.1.0
     * @return void
     */
    public function bulk_action_footer_script(): void {
        // Only run on edit.php screens
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_add_inline_script(
            'jquery',
            'jQuery(function($){ $(\'option[value="thinkrank_separator"]\').prop(\'disabled\', true); });'
        );
    }
}
