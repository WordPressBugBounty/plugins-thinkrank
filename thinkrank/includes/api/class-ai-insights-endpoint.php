<?php
/**
 * AI Insights API endpoints.
 *
 * Admin REST surface for the AI Insights screen (#248 P1 trio):
 *
 *   GET  /ai-insights/traffic        — AI referral/crawler dashboard summary
 *   GET  /ai-insights/brand          — saved queries + check history
 *   POST /ai-insights/brand          — save the query list
 *   POST /ai-insights/brand/run      — run checks now (paid AI calls, capped)
 *   GET  /ai-insights/auto-ai        — auto-optimization settings + last run
 *   POST /ai-insights/auto-ai        — save auto-optimization settings
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\AI\Brand_Visibility_Checker;
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
 * REST controller for AI traffic, brand visibility, and auto AI settings.
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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/brand', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_brand'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_brand_queries'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'queries' => [
                        'required'          => true,
                        'type'              => 'array',
                        'items'             => ['type' => 'string'],
                        'sanitize_callback' => static function ($value) {
                            if (!is_array($value)) {
                                return [];
                            }
                            $clean = array_values(array_filter(array_map(
                                static fn($q) => sanitize_text_field((string) $q),
                                $value
                            )));
                            // Clamp to the plan's cap, not the hard ceiling, so
                            // free can't persist queries it may not run.
                            return array_slice($clean, 0, Brand_Visibility_Checker::query_limit());
                        },
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/brand/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_brand_checks'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/brand/history/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_brand_history'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args'                => [
                'id' => [
                    'required' => true,
                    'type'     => 'integer',
                    'minimum'  => 1,
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
     * Saved brand queries + history.
     *
     * @return WP_REST_Response
     */
    public function get_brand(): WP_REST_Response {
        $checker = new Brand_Visibility_Checker();

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'queries'     => $checker->queries(),
                'history'     => $checker->history(),
                // The plan's cap, not the hard ceiling — the UI renders the
                // limit the user can actually use.
                'max_queries' => Brand_Visibility_Checker::query_limit(),
                'plan'        => \ThinkRank\Core\Plan_Config::ai_visibility(),
                'is_pro'      => \ThinkRank\Core\Plan_Config::is_pro(),
                'brand'       => (string) get_bloginfo('name'),
                'host'        => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            ],
        ], 200);
    }

    /**
     * Save the brand query list.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function save_brand_queries(WP_REST_Request $request): WP_REST_Response {
        $queries = (array) $request->get_param('queries');
        Settings::instance()->set('brand_visibility_queries', $queries);

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['queries' => $queries],
        ], 200);
    }

    /**
     * Run brand checks now.
     *
     * @return WP_REST_Response
     */
    public function run_brand_checks(): WP_REST_Response {
        try {
            $checker = new Brand_Visibility_Checker();
            $results = $checker->run();
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'results' => $results,
                'history' => (new Brand_Visibility_Checker())->history(),
            ],
        ], 200);
    }

    /**
     * Delete a single brand check-history row.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function delete_brand_history(WP_REST_Request $request): WP_REST_Response {
        $id      = (int) $request->get_param('id');
        $checker = new Brand_Visibility_Checker();
        $deleted = $checker->delete_history($id);

        return new WP_REST_Response([
            'success' => $deleted,
            'data'    => [
                'id'      => $id,
                'history' => $checker->history(),
            ],
        ], $deleted ? 200 : 404);
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
