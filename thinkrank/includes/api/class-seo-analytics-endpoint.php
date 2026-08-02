<?php

/**
 * SEO Analytics API Endpoints Class
 *
 * REST API endpoints for SEO analytics data collection, Google API integration,
 * and AI-powered insights generation. Provides comprehensive API access to
 * Analytics Manager functionality with proper authentication, validation,
 * and error handling.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Analytics_Manager;
use ThinkRank\API\Traits\API_Cache;
use WP_REST_Controller;
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
 * SEO Analytics API Endpoints Class
 *
 * Provides REST API endpoints for SEO analytics operations including
 * Google API integration, dashboard data retrieval, SEO opportunities
 * analysis, and connection management with proper authentication and validation.
 *
 * @since 1.0.0
 */
class SEO_Analytics_Endpoint extends WP_REST_Controller {

    use API_Cache;

    /**
     * Analytics Manager instance
     *
     * @since 1.0.0
     * @var Analytics_Manager
     */
    private Analytics_Manager $analytics_manager;

    /**
     * API namespace
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'seo-analytics';

    /**
     * Constructor
     *
     * @since 1.0.0
     * @param Analytics_Manager|null $analytics_manager Analytics manager instance
     */
    public function __construct(?Analytics_Manager $analytics_manager = null) {
        $this->analytics_manager = $analytics_manager ?? new Analytics_Manager();

        // Configure response caching for the live Search Console passthrough
        // endpoints (search-totals, search-daily, branded, countries).
        $this->set_cache_prefix('thinkrank_seo_analytics_');
        $this->set_cache_duration(3 * HOUR_IN_SECONDS); // 3 hours
    }

    /**
     * Register API routes
     * Following ThinkRank endpoint registration patterns
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Test Google API connections
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/test-connections',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'test_connections'],
                    'permission_callback' => [$this, 'check_permissions'],
                ]
            ]
        );

        // Get dashboard data
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/dashboard',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_dashboard_data'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args' => $this->get_dashboard_args()
                ]
            ]
        );

        // Get SEO opportunities
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/opportunities',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_seo_opportunities'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args' => $this->get_opportunities_args()
                ]
            ]
        );

        // Setup Search Console verification
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/setup/search-console',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'setup_search_console'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_setup_args()
                ]
            ]
        );

        // Get indexing status
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/indexing-status',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_indexing_status'],
                    'permission_callback' => [$this, 'check_permissions'],
                ]
            ]
        );

        // Refresh cached data
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/refresh',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'refresh_data'],
                    'permission_callback' => [$this, 'check_permissions'],
                ]
            ]
        );

        // Get client status (for debugging)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/status',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_client_status'],
                    'permission_callback' => [$this, 'check_permissions'],
                ]
            ]
        );

        // ========================================
        // SEO Intelligence Enhancement Endpoints
        // ========================================

        // Get intelligent dashboard data with trends and insights
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/intelligent-dashboard',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_intelligent_dashboard'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args' => $this->get_dashboard_args()
                ]
            ]
        );

        // Get intelligent SEO opportunities with prioritization
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/intelligent-opportunities',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_intelligent_opportunities'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args' => $this->get_opportunities_args()
                ]
            ]
        );

        // Get SEO insights
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/insights',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_seo_insights'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args' => $this->get_dashboard_args()
                ]
            ]
        );

        // Get Search Console totals for custom date range
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/search-totals',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_search_totals'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args'                => [
                        'start_date' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                            'description'       => 'Start date (Y-m-d)',
                        ],
                        'end_date' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                            'description'       => 'End date (Y-m-d)',
                        ],
                    ],
                ]
            ]
        );

        // Get daily Search Console data (by date dimension) for chart rendering
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/search-daily',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_search_daily'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args'                => [
                        'date_range' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '30d',
                            'sanitize_callback' => 'sanitize_text_field',
                            'description'       => 'Date range: 7d, 30d, or 90d',
                        ],
                    ],
                ]
            ]
        );

        // Get branded vs non-branded breakdown from Search Console
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/branded',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_branded'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args'                => [
                        'date_range' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '30d',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'brand_name' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ]
            ]
        );

        // Get top countries from Search Console
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/countries',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_countries'],
                    'permission_callback' => [$this, 'check_data_permissions'],
                    'args'                => [
                        'date_range' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '30d',
                            'sanitize_callback' => 'sanitize_text_field',
                            'description'       => 'Date range: 7d, 30d, or 90d',
                        ],
                    ],
                ]
            ]
        );
    }

    /**
     * Test Google API connections
     * Following ThinkRank response patterns
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function test_connections(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $connection_results = $this->analytics_manager->test_connections();

            return new WP_REST_Response([
                'success' => true,
                'data' => $connection_results,
                'message' => 'Connection tests completed'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'connection_test_failed',
                'Connection test failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get analytics dashboard data
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_dashboard_data(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range');
            $dashboard_data = $this->analytics_manager->get_dashboard_data($date_range);

            return new WP_REST_Response([
                'success' => true,
                'data' => $dashboard_data,
                'message' => 'Dashboard data retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'dashboard_data_failed',
                'Failed to retrieve dashboard data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get SEO opportunities
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_seo_opportunities(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range');
            $opportunities = $this->analytics_manager->get_seo_opportunities($date_range);

            return new WP_REST_Response([
                'success' => true,
                'data' => $opportunities,
                'message' => 'SEO opportunities retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'opportunities_failed',
                'Failed to retrieve SEO opportunities: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Setup Search Console verification
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function setup_search_console(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $site_url = $request->get_param('site_url');
            $setup_result = $this->analytics_manager->setup_search_console_verification($site_url);

            return new WP_REST_Response([
                'success' => $setup_result['success'],
                'data' => $setup_result,
                'message' => $setup_result['message']
            ], $setup_result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return new WP_Error(
                'setup_failed',
                'Search Console setup failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get indexing status
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_indexing_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $indexing_status = $this->analytics_manager->get_indexing_status();

            return new WP_REST_Response([
                'success' => true,
                'data' => $indexing_status,
                'message' => 'Indexing status retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'indexing_status_failed',
                'Failed to retrieve indexing status: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Refresh cached analytics data
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function refresh_data(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $refresh_result = $this->analytics_manager->refresh_data();

            // Bust the cached Search Console passthrough responses (search-totals,
            // search-daily, branded, countries) so an explicit refresh re-fetches.
            $this->invalidate_cache_pattern($this->cache_prefix . '*');

            return new WP_REST_Response([
                'success' => $refresh_result['success'],
                'data' => $refresh_result,
                'message' => $refresh_result['message']
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'refresh_failed',
                'Failed to refresh data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get client status for debugging
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_client_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $client_status = $this->analytics_manager->get_client_status();

            return new WP_REST_Response([
                'success' => true,
                'data' => $client_status,
                'message' => 'Client status retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'status_failed',
                'Failed to retrieve client status: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get dashboard endpoint arguments
     * Following ThinkRank argument validation patterns
     *
     * @return array Endpoint arguments
     */
    private function get_dashboard_args(): array {
        return [
            'date_range' => [
                'type' => 'string',
                'default' => '30d',
                'enum' => ['7d', '30d', '90d'],
                'sanitize_callback' => 'sanitize_key',
                'description' => 'Date range for analytics data'
            ]
        ];
    }

    /**
     * Get opportunities endpoint arguments
     *
     * @return array Endpoint arguments
     */
    private function get_opportunities_args(): array {
        return [
            'date_range' => [
                'type' => 'string',
                'default' => '30d',
                'enum' => ['7d', '30d', '90d'],
                'sanitize_callback' => 'sanitize_key',
                'description' => 'Date range for opportunities analysis'
            ]
        ];
    }

    /**
     * Get setup endpoint arguments
     *
     * @return array Endpoint arguments
     */
    private function get_setup_args(): array {
        return [
            'site_url' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => [$this, 'validate_site_url'],
                'description' => 'Site URL to verify in Search Console'
            ]
        ];
    }

    /**
     * Validate site URL parameter
     * Following ThinkRank validation patterns
     *
     * @param string $site_url Site URL to validate
     * @return bool|WP_Error Validation result
     */
    public function validate_site_url(string $site_url): bool|WP_Error {
        if (empty($site_url)) {
            return new WP_Error(
                'invalid_site_url',
                'Site URL is required',
                ['status' => 400]
            );
        }

        if (!filter_var($site_url, FILTER_VALIDATE_URL)) {
            return new WP_Error(
                'invalid_site_url',
                'Site URL must be a valid URL',
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Get Search Console totals for a custom date range
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_search_totals(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $start_date = $request->get_param('start_date');
            $end_date   = $request->get_param('end_date');

            // Validate date format and actual calendar validity
            $start_dt = \DateTime::createFromFormat('Y-m-d', $start_date);
            $end_dt   = \DateTime::createFromFormat('Y-m-d', $end_date);
            if (
                !$start_dt || $start_dt->format('Y-m-d') !== $start_date ||
                !$end_dt   || $end_dt->format('Y-m-d')   !== $end_date
            ) {
                return new WP_Error('invalid_dates', 'Dates must be valid calendar dates in Y-m-d format', ['status' => 400]);
            }
            if ($start_dt > $end_dt) {
                return new WP_Error('invalid_dates', 'start_date must not be after end_date', ['status' => 400]);
            }

            // Use Analytics Manager to access the initialized client with decrypted credentials
            $context = $this->resolve_search_console();
            if (is_wp_error($context)) {
                return $context;
            }
            [$search_console, $site_url] = $context;

            // Cache the live GSC call (3h TTL, site-wide) keyed by the date range.
            $response = $this->cached_response(
                'search_totals',
                function () use ($search_console, $site_url, $start_date, $end_date) {
                    return [
                        'success' => true,
                        'data'    => $search_console->get_search_totals_by_dates($site_url, $start_date, $end_date),
                        'message' => 'Search totals retrieved',
                    ];
                },
                ['start_date' => $start_date, 'end_date' => $end_date]
            );

            return new WP_REST_Response($response, 200);
        } catch (\Exception $e) {
            return $this->google_error_to_wp_error($e, 'search_totals_failed');
        }
    }

    /**
     * Get daily Search Console data grouped by date for chart rendering.
     *
     * Returns rows sorted ascending by date, each containing:
     * clicks, impressions, ctr (as %), position.
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_search_daily(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range') ?: '30d';
            $days = (int) preg_replace('/[^0-9]/', '', $date_range);
            if ($days <= 0 || $days > 90) {
                $days = 30;
            }

            // Window = exactly $days back from today (inclusive of today).
            // 7d  → today-6  ... today
            // 30d → today-29 ... today
            // 90d → today-89 ... today
            $end_date   = gmdate('Y-m-d');
            $start_date = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

            $context = $this->resolve_search_console();
            if (is_wp_error($context)) {
                return $context;
            }
            [$search_console, $site_url] = $context;

            // Cache the live GSC call (3h TTL, site-wide) keyed by the date range.
            $response = $this->cached_response(
                'search_daily',
                function () use ($search_console, $site_url, $start_date, $end_date, $days) {
                    $raw_rows = $search_console->get_search_performance_by_dates(
                        $site_url,
                        $start_date,
                        $end_date,
                        $days + 5,
                        ['date']
                    );

                    // Index GSC rows by date so we can pad missing days (GSC's lag means
                    // the most recent few days often have no data yet).
                    $by_date = [];
                    foreach ($raw_rows as $row) {
                        $date = $row['keys'][0] ?? '';
                        if (!$date) {
                            continue;
                        }
                        $by_date[$date] = [
                            'clicks'      => (int) ($row['clicks'] ?? 0),
                            'impressions' => (int) ($row['impressions'] ?? 0),
                            'ctr'         => round(($row['ctr'] ?? 0) * 100, 2),
                            'position'    => round($row['position'] ?? 0, 1),
                        ];
                    }

                    // Build a contiguous N-day series from $start_date → $end_date.
                    // Days GSC has no data for (today minus 2-4 days, typically) come
                    // through as zeros so the chart x-axis always spans the full window.
                    $rows = [];
                    $cursor = strtotime($start_date);
                    $end_ts = strtotime($end_date);
                    while ($cursor <= $end_ts) {
                        $date = gmdate('Y-m-d', $cursor);
                        $rows[] = array_merge(
                            ['date' => $date],
                            $by_date[$date] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0]
                        );
                        $cursor = strtotime('+1 day', $cursor);
                    }

                    return [
                        'success' => true,
                        'data'    => [
                            'rows'       => $rows,
                            'start_date' => $start_date,
                            'end_date'   => $end_date,
                        ],
                        'message' => 'Daily search data retrieved',
                    ];
                },
                ['date_range' => $date_range, 'start_date' => $start_date, 'end_date' => $end_date]
            );

            return new WP_REST_Response($response, 200);
        } catch (\Exception $e) {
            return $this->google_error_to_wp_error($e, 'search_daily_failed');
        }
    }

    /**
     * Get branded vs non-branded query breakdown from Search Console.
     *
     * Accepts optional `brand_name` param (comma-separated keywords).
     * When omitted the brand is auto-derived from the registered domain.
     * Also returns the equivalent previous-period data so the frontend can
     * compute trend arrows without a second round-trip.
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_branded(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range') ?: '30d';
            $brand_name = $request->get_param('brand_name') ?: '';

            $context = $this->resolve_search_console();
            if (is_wp_error($context)) {
                return $context;
            }
            [$search_console, $site_url] = $context;

            // Cache the (double) live GSC call (3h TTL, site-wide) keyed by
            // date range + brand terms.
            $response = $this->cached_response(
                'branded',
                function () use ($search_console, $site_url, $date_range, $brand_name) {
                    return [
                        'success' => true,
                        'data'    => $search_console->get_branded_performance($site_url, $date_range, $brand_name),
                        'message' => 'Branded data retrieved',
                    ];
                },
                ['date_range' => $date_range, 'brand_name' => $brand_name]
            );

            return new WP_REST_Response($response, 200);
        } catch (\Exception $e) {
            return $this->google_error_to_wp_error($e, 'branded_failed');
        }
    }

    /**
     * Get top countries from Search Console (country dimension).
     *
     * Returns up to 10 countries sorted by clicks descending, each with
     * clicks, impressions, ctr, position, and a percentage share of total clicks.
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_countries(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range') ?: '30d';

            $context = $this->resolve_search_console();
            if (is_wp_error($context)) {
                return $context;
            }
            [$search_console, $site_url] = $context;

            // Cache the live GSC call (3h TTL, site-wide) keyed by the date range.
            $response = $this->cached_response(
                'countries',
                function () use ($search_console, $site_url, $date_range) {
                    return [
                        'success' => true,
                        'data'    => $search_console->get_country_performance($site_url, $date_range),
                        'message' => 'Country data retrieved',
                    ];
                },
                ['date_range' => $date_range]
            );

            return new WP_REST_Response($response, 200);
        } catch (\Exception $e) {
            return $this->google_error_to_wp_error($e, 'countries_failed');
        }
    }

    /**
     * Resolve the Search Console client + property URL for the live GSC routes.
     *
     * Both failure modes are configuration problems the site owner can fix, so
     * they return an actionable error instead of letting the request reach
     * Google and bounce back as raw API text — an unselected property, for
     * example, otherwise surfaces as
     * "Google API error (400): 'http://' is not a valid Search Console site URL".
     *
     * @since 1.0.0
     * @return array{0: \ThinkRank\Integrations\Google_Search_Console_Client, 1: string}|WP_Error
     */
    private function resolve_search_console(): array|WP_Error {
        $client = $this->analytics_manager->get_search_console_client();

        if (!$client) {
            return new WP_Error(
                'google_not_connected',
                __('Google Search Console is not connected yet. Connect your Google account to see search data here.', 'thinkrank'),
                ['status' => 400, 'reason' => 'not_connected']
            );
        }

        $site_url = trim((string) $this->analytics_manager->get_property_url());

        // Accept only the two formats Search Console recognises: a URL-prefix
        // property (https://example.com/) or a domain property
        // (sc-domain:example.com). Anything else — most often an empty setting —
        // means no verified property has been picked yet.
        if (!preg_match('#^(sc-domain:\S+|https?://\S+)$#i', $site_url)) {
            return new WP_Error(
                'no_search_console_property',
                __('No Search Console property is selected for this site. Choose your verified property to start loading search data.', 'thinkrank'),
                ['status' => 400, 'reason' => 'no_property']
            );
        }

        return [$client, $site_url];
    }

    /**
     * Turn a Google API exception into an error a site owner can act on.
     *
     * Google's own wording ("'http://' is not a valid Search Console site URL",
     * bare 401/403s) tells an admin nothing about what to fix, so map the common
     * statuses to plain-language messages plus a `reason` the UI turns into the
     * matching call to action. The raw text is preserved in `details` for
     * debugging — these routes are already admin-gated.
     *
     * @since 1.0.0
     * @param \Exception $e    Exception thrown by the Google client.
     * @param string     $code WP_Error code for the failing route.
     * @return WP_Error Actionable error.
     */
    private function google_error_to_wp_error(\Exception $e, string $code): WP_Error {
        $status = (int) $e->getCode();
        // The Google client escapes the API's message before wrapping it in the
        // exception, so decode it back for display — the UI renders `details` as
        // plain text, where entities would show up literally ("&#039;").
        $raw = html_entity_decode($e->getMessage(), ENT_QUOTES, 'UTF-8');

        if (stripos($raw, 'valid Search Console site URL') !== false || $status === 404) {
            $reason      = 'no_property';
            $message     = __('The Search Console property for this site is missing or no longer valid. Select your verified property again to restore search data.', 'thinkrank');
            $http_status = 400;
        } elseif ($status === 401) {
            $reason      = 'reconnect';
            $message     = __('Your Google connection has expired. Reconnect your Google account to load Search Console data.', 'thinkrank');
            $http_status = 401;
        } elseif ($status === 403) {
            $reason      = 'permission';
            $message     = __('Your Google account does not have access to this Search Console property. Verify ownership in Search Console, or select a property you own.', 'thinkrank');
            $http_status = 403;
        } elseif ($status === 429) {
            $reason      = 'quota';
            $message     = __('Google is rate limiting requests right now. Search data will load again shortly.', 'thinkrank');
            $http_status = 429;
        } elseif ($status >= 500) {
            $reason      = 'google_down';
            $message     = __('Google Search Console is temporarily unavailable. Please try again in a few minutes.', 'thinkrank');
            $http_status = 502;
        } else {
            $reason      = 'unknown';
            $message     = __('Search Console data could not be loaded right now. Please try again.', 'thinkrank');
            $http_status = 502;
        }

        return new WP_Error(
            $code,
            $message,
            [
                'status'  => $http_status,
                'reason'  => $reason,
                'details' => $raw,
            ]
        );
    }

    /**
     * Check permissions for API access
     * Following ThinkRank permission patterns
     *
     * @return bool Permission status
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_analytics');
    }

    /**
     * Check permissions for the Google-backed data routes
     *
     * On top of the capability check, requires the SEO Analytics feature
     * toggle to be enabled. Without this guard a disabled feature would
     * still hit the Google APIs and surface raw errors (e.g. 401s when no
     * Google account is connected). Settings read/write routes are not
     * gated so the feature can always be (re-)enabled.
     *
     * @return bool|WP_Error True when allowed, false or WP_Error otherwise
     */
    public function check_data_permissions(): bool|WP_Error {
        if (!$this->check_permissions()) {
            return false;
        }

        if (!\ThinkRank\Core\Settings::instance()->get('seo_analytics_enabled', false)) {
            return new WP_Error(
                'seo_analytics_disabled',
                __('SEO Analytics is disabled. Enable it in the SEO Analytics settings to load data.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    // ========================================
    // SEO Intelligence Enhancement Endpoints
    // ========================================

    /**
     * Get intelligent dashboard data with trends and insights
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_intelligent_dashboard(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range');

            // Cache the intelligence computation (trend analysis + generators are
            // expensive) so the AI-insights panel doesn't recompute every load.
            $response = $this->cached_response(
                'intelligent_dashboard',
                function () use ($date_range) {
                    $intelligent_data = $this->analytics_manager->get_intelligent_dashboard_data($date_range);

                    return [
                        'success'   => isset($intelligent_data['success']) ? $intelligent_data['success'] : false,
                        'data'      => $intelligent_data['data'] ?? null,
                        'message'   => $intelligent_data['message'] ?? 'Intelligent dashboard data retrieved',
                        'timestamp' => current_time('mysql'),
                    ];
                },
                ['date_range' => $date_range]
            );

            // Always return 200 for successful API calls, even when no data available.
            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'intelligent_dashboard_error',
                'Failed to retrieve intelligent dashboard data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get intelligent SEO opportunities with prioritization
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_intelligent_opportunities(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range');

            // Cache the opportunity detection so it doesn't recompute every load.
            $response = $this->cached_response(
                'intelligent_opportunities',
                function () use ($date_range) {
                    $intelligent_opportunities = $this->analytics_manager->get_intelligent_seo_opportunities($date_range);

                    return [
                        'success'   => isset($intelligent_opportunities['success']) ? $intelligent_opportunities['success'] : false,
                        'data'      => $intelligent_opportunities['data'] ?? null,
                        'message'   => $intelligent_opportunities['message'] ?? 'Intelligent opportunities retrieved',
                        'timestamp' => current_time('mysql'),
                    ];
                },
                ['date_range' => $date_range]
            );

            // Always return 200 for successful API calls, even when no data available.
            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'intelligent_opportunities_error',
                'Failed to retrieve intelligent opportunities: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get SEO insights
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_seo_insights(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $date_range = $request->get_param('date_range');

            // Endpoint-level cache for consistency with the other two intelligence
            // calls (the manager also caches insights internally).
            $response = $this->cached_response(
                'seo_insights',
                function () use ($date_range) {
                    $insights = $this->analytics_manager->get_seo_insights($date_range);

                    return [
                        'success'   => isset($insights['success']) ? $insights['success'] : false,
                        'data'      => $insights['data'] ?? null,
                        'cached'    => $insights['cached'] ?? false,
                        'message'   => $insights['message'] ?? 'SEO insights retrieved',
                        'timestamp' => current_time('mysql'),
                    ];
                },
                ['date_range' => $date_range]
            );

            // Always return 200 for successful API calls, even when no data available.
            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'seo_insights_error',
                'Failed to retrieve SEO insights: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}
