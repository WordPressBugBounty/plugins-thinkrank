<?php

/**
 * Pillar Content API Endpoint Class
 *
 * REST API endpoints for fetching pillar content suggestions.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Query;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pillar Content API Endpoint Class
 * 
 * @since 1.0.0
 */
class Pillar_Content_Endpoint extends WP_REST_Controller {

    /**
     * API namespace
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @var string
     */
    protected $rest_base = 'pillar-content';

    /**
     * Register API routes
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/suggestions',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_suggestions'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'validate_callback' => function ($param, $request, $key) {
                                return is_numeric($param);
                            },
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ]
            ]
        );
    }

    /**
     * Get pillar content suggestions
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_suggestions(WP_REST_Request $request) {
        $post_id = $request->get_param('post_id');

        if (!$post_id) {
            return new WP_Error(
                'missing_post_id',
                __('Post ID is required', 'thinkrank'),
                ['status' => 400]
            );
        }

        $post_type = get_post_type($post_id);
        if (!$post_type) {
            return new WP_Error(
                'invalid_post_id',
                __('Invalid Post ID', 'thinkrank'),
                ['status' => 400]
            );
        }

        // Check global settings for this post type
        $all_settings = get_option('thinkrank_global_seo_settings', []);
        $link_suggestions_enabled = true; // Default to true

        if (isset($all_settings[$post_type]['link_suggestions'])) {
            $link_suggestions_enabled = (bool) $all_settings[$post_type]['link_suggestions'];
        }

        if (!$link_suggestions_enabled) {
            return new WP_REST_Response([], 200);
        }

        $taxonomies = get_object_taxonomies($post_type);
        $tax_query = [];

        if (!empty($taxonomies)) {
            $tax_query['relation'] = 'OR';

            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    $term_ids = wp_list_pluck($terms, 'term_id');
                    if (!empty($term_ids)) {
                        $tax_query[] = [
                            'taxonomy' => $taxonomy,
                            'field'    => 'term_id',
                            'terms'    => $term_ids,
                        ];
                        $has_terms = true;
                    }
                }
            }
        }

        $args = [
            'post_type'      => $post_type,
            'posts_per_page' => 5,
            'post__not_in'   => [$post_id],
            'meta_query'     => [
                [
                    'key'     => '_thinkrank_pillar_content',
                    'value'   => '1',
                    'compare' => '=',
                ]
            ],
            'tax_query'      => $tax_query,
            'fields'         => 'ids'
        ];

        $query = new WP_Query($args);
        $suggestions = [];

        if ($query->have_posts()) {
            foreach ($query->posts as $p_id) {
                $suggestions[] = [
                    'id'        => $p_id,
                    'title'     => get_the_title($p_id),
                    'permalink' => get_permalink($p_id),
                    'type'      => get_post_type($p_id)
                ];
            }
        }

        return new WP_REST_Response($suggestions, 200);
    }

    /**
     * Check if user can read posts
     * 
     * @return bool
     */
    public function check_read_permissions() {
        return current_user_can('edit_posts');
    }
}
