<?php

/**
 * Google Analytics Client Class
 *
 * Handles communication with Google Analytics API for website analytics data
 * retrieval and connection testing. Extends the base Google API client with
 * Analytics-specific functionality and rate limiting.
 *
 * @package ThinkRank\Integrations
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Analytics Client Class
 *
 * Single Responsibility: Handle Google Analytics API communication
 * Following ThinkRank HTTP client patterns from Claude_Client and OpenAI_Client
 *
 * @since 1.0.0
 */
class Google_Analytics_Client extends Google_API_Base_Client {

    /**
     * Google Analytics Data API base URL (GA4)
     */
    private const API_BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

    /**
     * Rate limit transient key prefix
     * Following ThinkRank option naming patterns
     */
    private const RATE_LIMIT_KEY = 'thinkrank_analytics_rate_limit';

    /**
     * Maximum requests per day (Google standard quota)
     */
    private const MAX_REQUESTS_PER_DAY = 50000;

    /**
     * Google Analytics Property ID (GA4)
     *
     * @var string
     */
    private string $property_id;

    /**
     * Constructor
     *
     * @param string $api_key Google Analytics API key
     * @param string $property_id GA4 Property ID (properties/XXXXXXXXX)
     * @param int $timeout Request timeout in seconds
     * @param string|null $access_token OAuth Access Token (optional)
     */
    public function __construct(string $api_key, string $property_id, int $timeout = 30, ?string $access_token = null) {
        parent::__construct($api_key, $timeout, $access_token);
        $this->property_id = $property_id;
    }

    /**
     * Test API connection
     * Following ThinkRank test_connection patterns from AI clients
     *
     * @return array Connection test results
     */
    public function test_connection(): array {
        try {
            // Test with a simple metadata request
            $result = $this->get_metadata();

            return [
                'success' => true,
                'message' => 'Google Analytics Data API connection successful',
                'property_id' => $this->property_id,
                'dimensions_count' => count($result['dimensions'] ?? []),
                'metrics_count' => count($result['metrics'] ?? [])
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get metadata for available dimensions and metrics
     *
     * @return array Metadata information
     * @throws \Exception If API request fails
     */
    public function get_metadata(): array {
        $endpoint = "/{$this->property_id}/metadata";
        $params = [];

        $full_url = self::API_BASE_URL . $endpoint;

        // Only append API key if no access token is present
        if (!empty($this->api_key) && empty($this->access_token)) {
            $params['key'] = $this->api_key;
        }

        return $this->make_request($full_url, $params, 'GET');
    }

    /**
     * Run analytics report for specified metrics and date range
     *
     * @param string $date_range Date range ('7d', '30d', '90d')
     * @param array $metrics Metrics to retrieve (GA4 metric names)
     * @param array $dimensions Dimensions to group by
     * @return array Analytics report data
     * @throws \Exception If API request fails
     */
    public function run_report(string $date_range = '30d', array $metrics = ['sessions'], array $dimensions = []): array {
        $endpoint = "/{$this->property_id}:runReport";

        // Convert date range to start/end dates
        $end_date = gmdate('Y-m-d');
        $days = (int) str_replace('d', '', $date_range);
        $start_date = gmdate('Y-m-d', strtotime("-{$days} days"));

        $request_body = [
            'dateRanges' => [
                [
                    'startDate' => $start_date,
                    'endDate' => $end_date
                ]
            ],
            'metrics' => array_map(function ($metric) {
                return ['name' => $metric];
            }, $metrics)
        ];

        // Add dimensions if provided
        if (!empty($dimensions)) {
            $request_body['dimensions'] = array_map(function ($dimension) {
                return ['name' => $dimension];
            }, $dimensions);
        }

        $full_url = self::API_BASE_URL . $endpoint;

        // The API key is sent by make_request() in the x-goog-api-key header — never
        // in the query string, which is captured by proxy/access logs.
        return $this->make_request($full_url, $request_body, 'POST');
    }

    /**
     * Get website traffic data using GA4 metrics
     *
     * @param string $date_range Date range for data
     * @return array Traffic data
     * @throws \Exception If API request fails
     */
    public function get_traffic_data(string $date_range = '30d'): array {
        $metrics = [
            'sessions',
            'screenPageViews',
            'bounceRate',
            'averageSessionDuration',
            'activeUsers'
        ];

        $result = $this->run_report($date_range, $metrics);

        // Parse the response and extract metric values
        $rows = $result['rows'] ?? [];
        $metric_values = [];

        if (!empty($rows)) {
            $metric_values = $rows[0]['metricValues'] ?? [];
        }

        return [
            'sessions' => (int) ($metric_values[0]['value'] ?? 0),
            'pageviews' => (int) ($metric_values[1]['value'] ?? 0),
            'bounce_rate' => (float) ($metric_values[2]['value'] ?? 0),
            'avg_session_duration' => (float) ($metric_values[3]['value'] ?? 0),
            'active_users' => (int) ($metric_values[4]['value'] ?? 0),
            'date_range' => $date_range,
            'property_id' => $this->property_id
        ];
    }

    /**
     * Get top pages data using GA4 dimensions and metrics
     *
     * @param int $limit Number of pages to retrieve
     * @param string $date_range Date range for data
     * @return array Top pages data
     * @throws \Exception If API request fails
     */
    public function get_top_pages(int $limit = 10, string $date_range = '30d'): array {
        $metrics = ['screenPageViews', 'sessions'];
        $dimensions = ['pagePath', 'pageTitle'];

        $result = $this->run_report($date_range, $metrics, $dimensions);

        $pages = [];
        $rows = $result['rows'] ?? [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            $dimension_values = $row['dimensionValues'] ?? [];
            $metric_values = $row['metricValues'] ?? [];

            $pages[] = [
                'path' => $dimension_values[0]['value'] ?? '',
                'title' => $dimension_values[1]['value'] ?? '',
                'pageviews' => (int) ($metric_values[0]['value'] ?? 0),
                'sessions' => (int) ($metric_values[1]['value'] ?? 0)
            ];
        }

        return [
            'pages' => $pages,
            'limit' => $limit,
            'date_range' => $date_range,
            'total_pages' => count($rows)
        ];
    }

    /**
     * Get organic search traffic data for SEO analytics
     *
     * @param string $date_range Date range for data
     * @return array Organic traffic data
     * @throws \Exception If API request fails
     */
    public function get_organic_traffic(string $date_range = '30d'): array {
        $metrics = ['sessions', 'screenPageViews', 'activeUsers'];
        $dimensions = ['sessionDefaultChannelGrouping'];

        $result = $this->run_report($date_range, $metrics, $dimensions);

        $organic_data = [
            'sessions' => 0,
            'pageviews' => 0,
            'users' => 0
        ];

        $rows = $result['rows'] ?? [];

        foreach ($rows as $row) {
            $dimension_values = $row['dimensionValues'] ?? [];
            $metric_values = $row['metricValues'] ?? [];

            $channel = $dimension_values[0]['value'] ?? '';

            // Filter for organic search traffic
            if (strtolower($channel) === 'organic search') {
                $organic_data['sessions'] = (int) ($metric_values[0]['value'] ?? 0);
                $organic_data['pageviews'] = (int) ($metric_values[1]['value'] ?? 0);
                $organic_data['users'] = (int) ($metric_values[2]['value'] ?? 0);
                break;
            }
        }

        return [
            'organic_traffic' => $organic_data,
            'date_range' => $date_range,
            'property_id' => $this->property_id
        ];
    }

    /**
     * Get rate limit configuration
     * Following ThinkRank rate limiting patterns
     *
     * @return array Rate limit configuration
     */
    protected function get_rate_limits(): array {
        return [
            'max_requests_per_day' => self::MAX_REQUESTS_PER_DAY,
            'reset_time' => get_transient(self::RATE_LIMIT_KEY . '_reset') ?: strtotime('tomorrow')
        ];
    }

    /**
     * Get rate limit transient key
     * Following ThinkRank option naming patterns
     *
     * @return string Rate limit key
     */
    protected function get_rate_limit_key(): string {
        return self::RATE_LIMIT_KEY;
    }

    /**
     * Get rate limit error message
     *
     * @return string Error message
     */
    protected function get_rate_limit_error_message(): string {
        return 'Google Analytics API rate limit exceeded. Try again tomorrow.';
    }

    /**
     * Get measurement ID
     *
     * @return string Measurement ID
     */
    public function get_measurement_id(): string {
        return $this->property_id;
    }

    /**
     * Get property ID
     *
     * @return string Property ID
     */
    public function get_property_id(): string {
        return $this->property_id;
    }
}
