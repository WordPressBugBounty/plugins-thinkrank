<?php
/**
 * SEO Goals REST endpoints.
 *
 * - GET  /thinkrank/v1/goals            → the ranked list, ignored goals, section checklists
 * - POST /thinkrank/v1/goals/mark       → mark a goal done or ignored, or restore it
 *
 * @package ThinkRank\API
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Goals\Goal_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Goals endpoints (#728).
 *
 * The mark route takes the goal id in the body rather than the path: ids are
 * `source:slug`, and a colon in a route segment is one more thing a proxy or
 * a regex can get wrong.
 *
 * @since 2.22.0
 */
class Goals_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Registry.
     *
     * @var Goal_Registry
     */
    private Goal_Registry $registry;

    /**
     * @param Goal_Registry|null $registry Registry (injected in tests).
     */
    public function __construct(?Goal_Registry $registry = null) {
        $this->registry = $registry ?? new Goal_Registry();
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/goals', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_goals'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'refresh' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/goals/mark', [
            'methods'             => 'POST',
            'callback'            => [$this, 'mark_goal'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'id'     => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'status' => [
                    'type'              => 'string',
                    'required'          => true,
                    'enum'              => Goal_Registry::STATUSES,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);
    }

    /**
     * GET /goals.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_goals(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => $this->registry->feed((bool) $request->get_param('refresh')),
        ], 200);
    }

    /**
     * POST /goals/mark — answers with the whole feed, so the screen redraws
     * from what the server now holds rather than guessing.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function mark_goal(WP_REST_Request $request) {
        $marked = $this->registry->mark((string) $request->get_param('id'), (string) $request->get_param('status'));
        if (is_wp_error($marked)) {
            return $marked;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $this->registry->feed(),
        ], 200);
    }

    /**
     * Goals cover sitewide settings and every section, so they are for
     * administrators, like the SEO Analyzer they sit under.
     *
     * @return bool|WP_Error
     */
    public function check_permissions() {
        if (current_user_can('manage_options')) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __('You do not have permission to view SEO goals.', 'thinkrank'),
            ['status' => is_user_logged_in() ? 403 : 401]
        );
    }
}
