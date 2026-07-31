<?php

/**
 * Google Search Analytics Client
 *
 * Client library for interacting with Google Search Console Search Analytics API.
 * Extends the specific Google API Base Client for standardized request handling.
 *
 * @package ThinkRank
 * @subpackage Integrations
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Search Analytics Client Class
 *
 * specific client for fetching search analytics data from Google Search Console.
 *
 * @since 1.0.0
 */
class Google_Search_Analytics_Client extends Google_API_Base_Client {

    /**
     * API Base URL
     *
     * @var string
     */
    protected const API_BASE_URL = 'https://www.googleapis.com/webmasters/v3';

    /**
     * Get search analytics data
     *
     * @param string $site_url   The URL of the property in search console.
     * @param string $start_date Start date in YYYY-MM-DD format.
     * @param string $end_date   End date in YYYY-MM-DD format.
     * @param array  $dimensions Dimensions to group by (e.g., 'query', 'page', 'country', 'device').
     * @param int    $row_limit  Maximum number of rows to return.
     * @return array Analytical data including rows and totals.
     * @throws \Exception If the API request fails.
     */
    public function get_search_analytics_data(string $site_url, string $start_date, string $end_date, array $dimensions = [], int $row_limit = 1000): array {
        $endpoint = '/sites/' . urlencode($site_url) . '/searchAnalytics/query';

        $request_body = [
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => $dimensions,
            'rowLimit'   => $row_limit,
            // Include fresh (still-processing) data so the most recent days
            // aren't missing — matches the GSC web UI default.
            'dataState'  => 'all',
        ];

        // Construct full URL. The API key is sent by make_request() in the
        // x-goog-api-key header, not the query string (which proxies/logs capture).
        $full_url = self::API_BASE_URL . $endpoint;

        $response = $this->make_request($full_url, $request_body, 'POST');


        return [
            'rows'             => $response['rows'] ?? [],
            'responseAggregationType' => $response['responseAggregationType'] ?? null,
        ];
    }

    /**
     * Test the connection to the API
     *
     * @return array Connection test result
     */
    public function test_connection(): array {
        try {
            // We can't easily test without a site URL, but we can check if credentials are present
            if (empty($this->api_key) && empty($this->access_token)) {
                throw new \Exception('No API key or Access Token provided.');
            }

            return [
                'success' => true,
                'message' => 'Client initialized with credentials.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get rate limits for this API
     *
     * @return array Rate limit configuration
     */
    protected function get_rate_limits(): array {
        return [
            // Shares Google Search Console's 2000 requests/day quota — this client
            // hits the same GSC endpoint as Google_Search_Console_Client, so it must
            // count against the same budget instead of running effectively unlimited.
            'max_requests_per_day' => 2000,
            'reset_time'           => get_transient($this->get_rate_limit_key() . '_reset') ?: strtotime('tomorrow'),
        ];
    }

    /**
     * Get the rate limit key for creating unique transients
     *
     * Uses the same plugin-prefixed key as Google_Search_Console_Client so both
     * clients share (and jointly respect) the single GSC daily quota.
     *
     * @return string Rate limit key
     */
    protected function get_rate_limit_key(): string {
        return 'thinkrank_gsc_rate_limit';
    }

    /**
     * Get the error message to display when rate limit is exceeded
     *
     * @return string Error message
     */
    protected function get_rate_limit_error_message(): string {
        return 'Google Search Console API rate limit exceeded. Please try again later.';
    }
}
