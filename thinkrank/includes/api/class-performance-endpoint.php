<?php
/**
 * Performance API Endpoint
 *
 * REST API endpoints for Core Web Vitals monitoring and performance insights.
 * Connects the frontend Performance tab to the existing Performance Monitoring Manager.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

namespace ThinkRank\API;

use ThinkRank\SEO\Performance_Monitoring_Manager;
use ThinkRank\SEO\Performance_Data_Collector;
use ThinkRank\SEO\Analytics_Manager;
use ThinkRank\API\Traits\API_Cache;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load API Cache trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-api-cache.php';

/**
 * Performance API Endpoint Class
 *
 * @since 1.0.0
 */
class Performance_Endpoint extends WP_REST_Controller {

    use API_Cache;

    /**
     * REST API namespace
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * REST API base
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'performance';

    /**
     * Performance Monitoring Manager instance
     *
     * @since 1.0.0
     * @var Performance_Monitoring_Manager|null
     */
    private ?Performance_Monitoring_Manager $performance_manager = null;

    /**
     * Performance Data Collector instance (lazy)
     *
     * @since 1.0.0
     * @var Performance_Data_Collector|null
     */
    private ?Performance_Data_Collector $data_collector = null;

    /**
     * Constructor
     *
     * Endpoint objects are constructed on every REST request (any namespace),
     * so the manager chain is built lazily — only when one of this endpoint's
     * routes actually executes.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Configure caching for performance endpoints
        $this->set_cache_prefix('thinkrank_performance_');
        $this->set_cache_duration(300); // 5 minutes for performance data
    }

    /**
     * Get the Performance Monitoring Manager (lazy)
     *
     * @return Performance_Monitoring_Manager
     */
    private function get_performance_manager(): Performance_Monitoring_Manager {
        if ($this->performance_manager === null) {
            $this->performance_manager = new Performance_Monitoring_Manager();
        }
        return $this->performance_manager;
    }

    /**
     * Get the Performance Data Collector (lazy)
     *
     * @return Performance_Data_Collector
     */
    private function get_data_collector(): Performance_Data_Collector {
        if ($this->data_collector === null) {
            $this->data_collector = new Performance_Data_Collector();
        }
        return $this->data_collector;
    }

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     */
    public function register_routes() {
        // Monitor endpoint - Get current performance data
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/monitor',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_performance_data'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'device_type' => [
                            'default' => 'mobile',
                            'type' => 'string',
                            'enum' => ['mobile', 'desktop'],
                            'sanitize_callback' => 'sanitize_key'
                        ]
                    ]
                ]
            ]
        );



        // Recommendations endpoint
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/recommendations',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_recommendations'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Historical data endpoint
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/history',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_historical_data'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_historical_data_args()
                ]
            ]
        );

        // Opportunities endpoint
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/opportunities',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_opportunities'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'device_type' => [
                            'default' => 'mobile',
                            'type' => 'string',
                            'enum' => ['mobile', 'desktop'],
                            'sanitize_callback' => 'sanitize_key'
                        ]
                    ]
                ]
            ]
        );

        // Diagnostics endpoint
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/diagnostics',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_diagnostics'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'device_type' => [
                            'default' => 'mobile',
                            'type' => 'string',
                            'enum' => ['mobile', 'desktop'],
                            'sanitize_callback' => 'sanitize_key'
                        ]
                    ]
                ]
            ]
        );

        // Data collection endpoint
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/collect',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'collect_performance_data'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );


    }

    /**
     * Get comprehensive performance data
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_performance_data(WP_REST_Request $request) {
        try {
            // Get device type from request
            $device_type = $request->get_param('device_type') ?? 'mobile';

            $cache_params  = ['device_type' => $device_type];
            // Core Web Vitals are site-wide, not user-specific — a per-user
            // cache key would duplicate the entry (and the cold-path work)
            // for every admin user.
            $user_id       = null;
            $cache_enabled = $this->is_caching_enabled();

            // Serve a fresh cached response when available.
            if ($cache_enabled) {
                $cached = $this->get_cached_response('performance_data', $cache_params, $user_id);
                if ($cached !== null) {
                    return new WP_REST_Response(
                        array_merge($cached['data'], [
                            'cached'    => true,
                            'cached_at' => $cached['cached_at'],
                        ]),
                        200
                    );
                }
            }

            // Serve from the existing cache / collected DB data first; only the
            // background collector performs a live PageSpeed audit. A cold cache
            // no longer blocks the request on a 10-40s inline Lighthouse run.
            $response_data = $this->get_performance_manager()->get_performance_snapshot($device_type);

            // Don't pin a transient "collecting" state in the response cache — the
            // background collection must be re-checked on the next request.
            if ($cache_enabled && ($response_data['data']['status'] ?? '') !== 'collecting') {
                $this->set_cached_response('performance_data', $response_data, $cache_params, null, $user_id);
            }

            return new WP_REST_Response(array_merge($response_data, ['cached' => false]), 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'performance_data_failed',
                'Failed to retrieve performance data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }





    /**
     * Get performance recommendations
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_recommendations(WP_REST_Request $request) {
        try {
            $recommendations = $this->get_performance_manager()->get_performance_recommendations();

            return new WP_REST_Response([
                'success' => true,
                'data' => $recommendations,
                'message' => __('Performance recommendations retrieved successfully', 'thinkrank')
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'recommendations_failed',
                'Failed to retrieve recommendations: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get historical performance data
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_historical_data(WP_REST_Request $request) {
        try {
            $days = $request->get_param('days') ?? 30;
            $metric = $request->get_param('metric') ?? 'all';

            $historical_data = $this->get_performance_manager()->get_historical_data($days, $metric);

            return new WP_REST_Response([
                'success' => true,
                'data' => $historical_data,
                'message' => __('Historical data retrieved successfully', 'thinkrank')
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'historical_data_failed',
                'Failed to retrieve historical data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get performance opportunities
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_opportunities(WP_REST_Request $request) {
        try {
            // Get device type from request
            $device_type = $request->get_param('device_type') ?? 'mobile';

            $opportunities = $this->get_performance_manager()->get_performance_opportunities('', $device_type);

            return new WP_REST_Response([
                'success' => true,
                'data' => $opportunities,
                'device_type' => $device_type,
                'message' => __('Performance opportunities retrieved successfully', 'thinkrank')
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'opportunities_failed',
                'Failed to retrieve performance opportunities: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get performance diagnostics
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_diagnostics(WP_REST_Request $request) {
        try {
            // Get device type from request
            $device_type = $request->get_param('device_type') ?? 'mobile';

            $diagnostics = $this->get_performance_manager()->get_performance_diagnostics('', $device_type);

            return new WP_REST_Response([
                'success' => true,
                'data' => $diagnostics,
                'device_type' => $device_type,
                'message' => __('Performance diagnostics retrieved successfully', 'thinkrank')
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'diagnostics_failed',
                'Failed to retrieve performance diagnostics: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Manually collect performance data
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function collect_performance_data(WP_REST_Request $request) {
        try {
            $results = $this->get_data_collector()->manual_collect();

            return new WP_REST_Response([
                'success' => $results['success'],
                'data' => $results,
                'message' => $results['message']
            ], $results['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return new WP_Error(
                'data_collection_failed',
                'Failed to collect performance data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }



    /**
     * Check read permissions
     *
     * @since 1.0.0
     *
     * @return bool True if user can read
     */
    public function check_read_permissions(): bool {
        // Performance data + settings are not subscriber-visible — require the
        // same Performance management capability as the write routes.
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_performance');
    }

    /**
     * Check manage permissions
     *
     * @since 1.0.0
     *
     * @return bool True if user can manage options
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_performance');
    }

    /**
     * Get arguments for historical data endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_historical_data_args(): array {
        return [
            'days' => [
                'required' => false,
                'type' => 'integer',
                'default' => 30,
                'minimum' => 1,
                'maximum' => 365,
                'description' => 'Number of days of historical data to retrieve'
            ],
            'metric' => [
                'required' => false,
                'type' => 'string',
                'default' => 'all',
                'enum' => ['all', 'lcp', 'fid', 'cls', 'inp', 'score'],
                'description' => 'Specific metric to retrieve'
            ]
        ];
    }
}
