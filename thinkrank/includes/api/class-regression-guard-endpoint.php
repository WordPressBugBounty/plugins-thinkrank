<?php
/**
 * Regression Guard REST endpoints.
 *
 * - GET  /thinkrank/v1/regression-guard?post_id=  → the stored check (0: homepage)
 * - POST /thinkrank/v1/regression-guard/check     → check the page now
 * - GET  /thinkrank/v1/regression-guard/pages     → posts that have been checked
 *
 * @package ThinkRank\API
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Regression\Regression_Guard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Regression Guard endpoints (#720).
 *
 * Takes a post id, never a URL: the check requests the page from the server
 * itself, and a URL parameter would let a caller point that request anywhere.
 * The id resolves to the post's own permalink, or to the homepage for 0.
 *
 * @since 2.23.0
 */
class Regression_Guard_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     */
    private const REST_BASE = 'regression-guard';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        $post_id = [
            'type'              => 'integer',
            'default'           => Regression_Guard::HOME,
            'minimum'           => 0,
            'sanitize_callback' => 'absint',
        ];

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_check'],
            'permission_callback' => [$this, 'check_target_permissions'],
            'args'                => ['post_id' => $post_id],
        ]);

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/check', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_check'],
            'permission_callback' => [$this, 'check_target_permissions'],
            'args'                => ['post_id' => $post_id],
        ]);

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/pages', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_pages'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);
    }

    /**
     * The stored check for a page.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_check(WP_REST_Request $request) {
        return $this->respond(Regression_Guard::get((int) $request->get_param('post_id')));
    }

    /**
     * Check a page now.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function run_check(WP_REST_Request $request) {
        return $this->respond(Regression_Guard::check((int) $request->get_param('post_id')));
    }

    /**
     * Posts that have been checked.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function list_pages(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => Regression_Guard::checked_posts(),
        ], 200);
    }

    /**
     * A post can be checked by anyone who can edit it — the editor panel is
     * where a writer sees what their page serves. The homepage is sitewide, so
     * it takes an administrator, like the rest of the SEO Analyzer.
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function check_target_permissions(WP_REST_Request $request) {
        $post_id = absint($request->get_param('post_id'));

        if (Regression_Guard::HOME === $post_id) {
            return $this->check_admin_permissions($request);
        }

        if (!is_user_logged_in()) {
            return $this->forbidden(401);
        }

        return current_user_can('edit_post', $post_id) ? true : $this->forbidden(403);
    }

    /**
     * Administrators only.
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function check_admin_permissions(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return $this->forbidden(401);
        }

        return current_user_can('manage_options') ? true : $this->forbidden(403);
    }

    /**
     * Wrap a view in the standard envelope.
     *
     * @param array<string, mixed>|WP_Error $view View or error.
     * @return WP_REST_Response|WP_Error
     */
    private function respond($view) {
        if (is_wp_error($view)) {
            return $view;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $view,
        ], 200);
    }

    /**
     * Permission error.
     *
     * @param int $status HTTP status.
     * @return WP_Error
     */
    private function forbidden(int $status): WP_Error {
        return new WP_Error(
            'rest_forbidden',
            __('You do not have permission to check this page.', 'thinkrank'),
            ['status' => $status]
        );
    }
}
