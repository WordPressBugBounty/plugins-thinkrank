<?php
/**
 * Simple / Advanced mode REST endpoint.
 *
 * - POST /thinkrank/v1/ui-mode { mode: simple|advanced } → the stored mode
 *
 * @package ThinkRank\API
 * @since 2.11.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Admin\UI_Mode;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UI mode endpoint (#730).
 *
 * The current user's own preference, so anyone who can open ThinkRank may set
 * it — the Role Manager's namespace gate already requires that. The mode is
 * read from the localized `thinkrankAdmin.uiMode`, so there is no GET.
 *
 * @since 2.11.0
 */
class UI_Mode_Endpoint {

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route('thinkrank/v1', '/ui-mode', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'args'                => [
                'mode' => [
                    'type'     => 'string',
                    'enum'     => UI_Mode::MODES,
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * POST /ui-mode.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function save(WP_REST_Request $request) {
        $stored = UI_Mode::set((string) $request->get_param('mode'));

        if (null === $stored) {
            return new WP_Error('thinkrank_ui_mode_invalid', __('Unknown mode.', 'thinkrank'), ['status' => 400]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['mode' => $stored],
        ], 200);
    }
}
