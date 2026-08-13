<?php
/**
 * AI Insights API endpoints.
 *
 * Admin REST surface for the AI Insights screen (#248 P1 trio):
 *
 *   GET  /ai-insights/traffic        — AI referral/crawler dashboard summary
 *   GET  /ai-insights/auto-ai        — auto-optimization settings + last run
 *   POST /ai-insights/auto-ai        — save auto-optimization settings
 *
 * Brand visibility used to live here too (`/ai-insights/brand*`). Those routes
 * were removed in 1.30.0: the v2 rebuild moved the feature to
 * `/thinkrank/v1/brand-visibility/*` with a queued runner, and the v1 routes
 * outlived their only UI consumer while still being able to start a
 * synchronous batch of paid AI probes in a single request (#301).
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Settings;
use ThinkRank\SEO\Ai_Traffic_Tracker;
use ThinkRank\SEO\Auto_Ai_Optimizer;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller for AI traffic and auto AI settings.
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/auto-ai', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_auto_ai'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_auto_ai'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'enabled'    => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                    'post_types' => [
                        'required'          => false,
                        'type'              => 'array',
                        'items'             => ['type' => 'string'],
                        'sanitize_callback' => static function ($value) {
                            if (!is_array($value)) {
                                return [];
                            }
                            // Only real, public post types survive.
                            return array_values(array_filter(
                                array_map('sanitize_key', $value),
                                static fn($t) => post_type_exists($t)
                            ));
                        },
                    ],
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

    /**
     * Auto AI settings + last run outcome.
     *
     * @return WP_REST_Response
     */
    public function get_auto_ai(): WP_REST_Response {
        $settings = Settings::instance();

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'enabled'    => (bool) $settings->get('auto_ai_meta_enabled', false),
                'post_types' => (array) $settings->get('auto_ai_meta_post_types', ['post']),
                'last_run'   => get_option(Auto_Ai_Optimizer::LAST_RUN_OPTION, null),
                // Pro-gated: the UI locks the toggle and shows the upsell.
                'available'  => \ThinkRank\Core\Plan_Config::can('auto_ai_meta', 'ai_visibility'),
            ],
        ], 200);
    }

    /**
     * Save auto AI settings.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function save_auto_ai(WP_REST_Request $request): WP_REST_Response {
        $settings = Settings::instance();

        if (null !== $request->get_param('enabled')) {
            $enabled = rest_sanitize_boolean($request->get_param('enabled'));

            // Pro capability. Turning it OFF always works — a lapsed licence
            // must never trap the setting in the on position.
            if ($enabled && !\ThinkRank\Core\Plan_Config::can('auto_ai_meta', 'ai_visibility')) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => __('Automatic AI metadata is a ThinkRank Pro feature.', 'thinkrank'),
                    'data'    => ['requires_pro' => true],
                ], 403);
            }

            $settings->set('auto_ai_meta_enabled', $enabled);
        }
        if (null !== $request->get_param('post_types')) {
            $settings->set('auto_ai_meta_post_types', (array) $request->get_param('post_types'));
        }

        return $this->get_auto_ai();
    }
}
