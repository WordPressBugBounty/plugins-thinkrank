<?php
/**
 * Content analysis REST routes: Question Coverage (#723).
 *
 * - GET  /content-analysis/question-coverage?post_id=  the last stored result
 * - POST /content-analysis/question-coverage           run it (or `dry_run`
 *                                                      for the token estimate)
 * - POST /content-analysis/question-coverage/draft     a draft section for one
 *                                                      question, never written
 *                                                      to the post
 *
 * Gated like the other per-post AI tools: the caller must be able to edit
 * that post, and the Role Manager maps the `content-analysis` prefix to
 * thinkrank_content_tools.
 *
 * @package ThinkRank\API
 * @since 2.21.2
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\AI\Question_Coverage_Analyzer;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Question Coverage endpoint.
 */
class Content_Analysis_Endpoint {

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        $post_id = [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ];

        register_rest_route('thinkrank/v1', '/content-analysis/question-coverage', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_stored'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => ['post_id' => $post_id],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'run'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_id'           => $post_id,
                    'content'           => [
                        'type'        => 'string',
                        'description' => 'Live editor content to judge instead of the saved post.',
                    ],
                    'keyword'           => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'refresh_questions' => [
                        'type'    => 'boolean',
                        'default' => false,
                    ],
                    'dry_run'           => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Return the token estimate without calling the AI.',
                    ],
                ],
            ],
        ]);

        register_rest_route('thinkrank/v1', '/content-analysis/question-coverage/draft', [
            'methods'             => 'POST',
            'callback'            => [$this, 'draft'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'post_id'  => $post_id,
                'question' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content'  => [
                    'type' => 'string',
                ],
            ],
        ]);
    }

    /**
     * The caller must be able to edit this post.
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public function check_permissions(WP_REST_Request $request): bool {
        $post_id = (int) $request->get_param('post_id');
        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    /**
     * GET: the last result for the post, or null.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_stored(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'result' => Question_Coverage_Analyzer::stored((int) $request->get_param('post_id')),
        ], 200);
    }

    /**
     * POST: run, or estimate.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|\WP_Error
     */
    public function run(WP_REST_Request $request) {
        $analyzer = new Question_Coverage_Analyzer();
        $options  = [
            'content'           => (string) $request->get_param('content'),
            'keyword'           => (string) $request->get_param('keyword'),
            'refresh_questions' => (bool) $request->get_param('refresh_questions'),
        ];
        $post_id  = (int) $request->get_param('post_id');

        $result = $request->get_param('dry_run')
            ? $analyzer->estimate($post_id, $options)
            : $analyzer->analyze($post_id, $options);

        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }

    /**
     * POST: draft a section for one question.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|\WP_Error
     */
    public function draft(WP_REST_Request $request) {
        $result = (new Question_Coverage_Analyzer())->draft_section(
            (int) $request->get_param('post_id'),
            (string) $request->get_param('question'),
            (string) $request->get_param('content')
        );

        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }
}
