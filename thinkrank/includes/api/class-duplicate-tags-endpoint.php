<?php
/**
 * Duplicate Tag Guard REST endpoints.
 *
 * - GET  /thinkrank/v1/duplicate-tags          → switches and the last check (checks when there is none)
 * - POST /thinkrank/v1/duplicate-tags/scan     → check the pages again
 * - POST /thinkrank/v1/duplicate-tags/settings → change switches; returns what was stored
 *
 * @package ThinkRank\API
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Duplicate_Tags\Duplicate_Tag_Detector;
use ThinkRank\SEO\Duplicate_Tags\Guard_Settings;
use ThinkRank\SEO\Duplicate_Tags\Head_Tags;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Duplicate Tag Guard endpoints (#733). Administrators only, like the SEO
 * Analyzer: the switches change every page's output.
 *
 * @since 2.23.0
 */
class Duplicate_Tags_Endpoint {

    private const NAMESPACE = 'thinkrank/v1';

    private const REST_BASE = 'duplicate-tags';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_state'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/scan', [
            'methods'             => 'POST',
            'callback'            => [$this, 'scan'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        $families = [];
        foreach (Head_Tags::FAMILIES as $family) {
            $families[$family] = ['type' => 'boolean'];
        }

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/settings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_settings'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => $families,
        ]);
    }

    /**
     * GET /duplicate-tags.
     *
     * @return WP_REST_Response
     */
    public function get_state(): WP_REST_Response {
        return $this->respond(false);
    }

    /**
     * POST /duplicate-tags/scan.
     *
     * @return WP_REST_Response
     */
    public function scan(): WP_REST_Response {
        return $this->respond(true);
    }

    /**
     * POST /duplicate-tags/settings.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function save_settings(WP_REST_Request $request): WP_REST_Response {
        $patch = [];
        foreach (Head_Tags::FAMILIES as $family) {
            if (null !== $request->get_param($family)) {
                $patch[$family] = (bool) $request->get_param($family);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => (new Guard_Settings())->save($patch),
        ], 200);
    }

    /**
     * Administrators only.
     *
     * @return bool|WP_Error
     */
    public function check_permissions() {
        if (current_user_can('manage_options')) {
            return true;
        }

        return new WP_Error('rest_forbidden', __('You cannot manage duplicate head tags.', 'thinkrank'), ['status' => is_user_logged_in() ? 403 : 401]);
    }

    /**
     * Switches and report.
     *
     * @param bool $fresh Check the pages again.
     * @return WP_REST_Response
     */
    private function respond(bool $fresh): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'settings'    => (new Guard_Settings())->get(),
                'report'      => (new Duplicate_Tag_Detector())->scan(!$fresh),
                'kill_switch' => defined(\ThinkRank\Frontend\Duplicate_Tag_Guard::KILL_SWITCH) && constant(\ThinkRank\Frontend\Duplicate_Tag_Guard::KILL_SWITCH),
            ],
        ], 200);
    }
}
