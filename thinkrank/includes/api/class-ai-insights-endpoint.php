<?php
/**
 * AI Insights API endpoints.
 *
 * Admin REST surface for the AI Insights screen (#248 P1):
 *
 *   GET  /ai-insights/traffic        — AI referral/crawler dashboard summary
 *
 * Automatic AI metadata on publish, the other tab on this screen, is a
 * ThinkRank Pro feature and registers its own routes there (#673). Brand
 * Visibility used to live in this section too; its v1 routes here were removed
 * in 1.30.0 (#301), and the feature itself was removed later.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Ai_Traffic_Tracker;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller for AI traffic.
 */
class Ai_Insights_Endpoint extends WP_REST_Controller {

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
    protected $rest_base = 'ai-insights';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/traffic', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_traffic'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args'                => [
                'days' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 30,
                    'minimum'  => 1,
                    'maximum'  => 180,
                ],
            ],
        ]);
    }

    /**
     * Admin permission gate (matches the other admin-only endpoints).
     *
     * @return bool
     */
    public function check_admin_permissions(): bool {
        return current_user_can('manage_options');
    }

    /**
     * AI traffic dashboard summary.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_traffic(WP_REST_Request $request): WP_REST_Response {
        $tracker = new Ai_Traffic_Tracker();

        return new WP_REST_Response([
            'success' => true,
            'data'    => $tracker->summary((int) $request->get_param('days')),
        ], 200);
    }
}
