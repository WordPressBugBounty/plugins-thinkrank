<?php

/**
 * Focus Keyword Usage API Endpoint
 *
 * Reports whether a focus keyword is already used on other posts, powering the
 * "You have already used this Focus Keyword" analysis status (Rank Math parity).
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.15.x
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
 * Focus Keyword Usage API Endpoint.
 *
 * @since 1.15.x
 */
class Focus_Keyword_Usage_Endpoint extends WP_REST_Controller {

    /**
     * API namespace.
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base.
     *
     * @var string
     */
    protected $rest_base = 'focus-keyword-usage';

    /**
     * Register API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_usage'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'post_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                            'validate_callback' => static function ($param) {
                                return is_numeric($param);
                            },
                        ],
                        'keywords' => [
                            'required' => true,
                            'type'     => 'string',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Return per-keyword usage counts across other posts.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_usage(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('post_id');

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error(
                'thinkrank_forbidden',
                __('You are not allowed to edit this post.', 'thinkrank'),
                ['status' => 403]
            );
        }

        // The `keywords` param is a JSON array (falling back to comma-separated).
        $raw = (string) $request->get_param('keywords');
        $keywords = json_decode($raw, true);
        if (!is_array($keywords)) {
            $keywords = explode(',', $raw);
        }

        $post_type = get_post_type($post_id) ?: 'post';
        $seen      = [];
        $results   = [];

        foreach ($keywords as $keyword) {
            $keyword = is_string($keyword) ? trim($keyword) : '';
            $key     = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);
            if ($keyword === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $usage = $this->count_keyword_usage($keyword, $post_id, $post_type);
            $results[] = [
                'keyword' => $keyword,
                'count'   => $usage['count'],
                'posts'   => $usage['posts'],
            ];
        }

        return new WP_REST_Response([
            'success'  => true,
            'keywords' => $results,
        ], 200);
    }

    /**
     * Count other posts (of the same type) that use a keyword as a focus keyword.
     *
     * Matches both the legacy scalar primary keyword and the keyword appearing
     * anywhere in the serialized focus-keyword array.
     *
     * @param string $keyword    Keyword to look up.
     * @param int    $exclude_id Current post to exclude.
     * @param string $post_type  Post type to scope the search to.
     * @return array{count:int,posts:array<int,array{id:int,title:string,edit:string}>}
     */
    private function count_keyword_usage(string $keyword, int $exclude_id, string $post_type): array {
        $query = new WP_Query([
            'post_type'           => $post_type,
            'post_status'         => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page'      => 6,
            'post__not_in'        => [$exclude_id],
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional, small admin-only lookup.
            'meta_query'          => [
                'relation' => 'OR',
                [
                    // Legacy scalar primary keyword — exact match.
                    'key'     => '_thinkrank_focus_keyword',
                    'value'   => $keyword,
                    'compare' => '=',
                ],
                [
                    // Serialized array entry: s:LEN:"keyword"; → contains :"keyword";
                    'key'     => '_thinkrank_focus_keywords',
                    'value'   => ':"' . $keyword . '";',
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $posts = [];
        foreach (array_slice($query->posts, 0, 5) as $id) {
            $id = (int) $id;
            $posts[] = [
                'id'    => $id,
                'title' => html_entity_decode(get_the_title($id), ENT_QUOTES),
                'edit'  => (string) get_edit_post_link($id, 'raw'),
            ];
        }

        return [
            'count' => (int) $query->found_posts,
            'posts' => $posts,
        ];
    }

    /**
     * Permission check — must be able to edit the target post.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public function check_permissions(WP_REST_Request $request): bool {
        $post_id = (int) $request->get_param('post_id');
        if ($post_id > 0) {
            return current_user_can('edit_post', $post_id);
        }
        return current_user_can('edit_posts');
    }
}
