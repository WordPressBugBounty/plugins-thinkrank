<?php

/**
 * Google Search Console Client Class
 *
 * Handles communication with Google Search Console API for search performance
 * data retrieval and site verification. Extends the base Google API client with
 * Search Console-specific functionality and rate limiting.
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
 * Google Search Console Client Class
 *
 * Single Responsibility: Handle Google Search Console API communication
 * Following ThinkRank HTTP client patterns from Claude_Client and OpenAI_Client
 *
 * @since 1.0.0
 */
class Google_Search_Console_Client extends Google_API_Base_Client {

    /**
     * Google Search Console API base URL
     */
    private const API_BASE_URL = 'https://www.googleapis.com/webmasters/v3';

    /**
     * Rate limit transient key prefix
     * Following ThinkRank option naming patterns
     */
    private const RATE_LIMIT_KEY = 'thinkrank_gsc_rate_limit';

    /**
     * Maximum requests per day (Google standard quota)
     */
    private const MAX_REQUESTS_PER_DAY = 2000;

    /**
     * Test API connection
     * Following ThinkRank test_connection patterns from AI clients
     *
     * @return array Connection test results
     */
    public function test_connection(): array {
        try {
            $result = $this->list_sites();

            return [
                'success' => true,
                'message' => 'Google Search Console API connection successful',
                'sites_count' => count($result['siteEntry'] ?? [])
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * List verified sites in Search Console
     *
     * @return array List of verified sites
     * @throws \Exception If API request fails
     */
    public function list_sites(): array {
        $endpoint = '/sites';
        // The API key (when no OAuth token) is sent via the x-goog-api-key
        // header by the base client — not the query string.
        $full_url = self::API_BASE_URL . $endpoint;
        return $this->make_request($full_url, [], 'GET');
    }

    /**
     * Verify site ownership in Search Console
     *
     * @param string $site_url Site URL to verify
     * @param string $verification_method Verification method used
     * @return array Verification results
     */
    public function verify_site(string $site_url, string $verification_method = 'meta'): array {
        try {
            // Check if the site is already verified by listing sites
            $sites = $this->list_sites();
            $site_verified = false;

            foreach ($sites['siteEntry'] ?? [] as $site) {
                if ($site['siteUrl'] === $site_url) {
                    $site_verified = true;
                    break;
                }
            }

            return [
                'success' => $site_verified,
                'message' => $site_verified ? 'Site is verified in Search Console' : 'Site not found in Search Console',
                'site_url' => $site_url,
                'verification_method' => $verification_method
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get search performance data with flexible dimensions
     *
     * @param string $site_url Site URL to get data for
     * @param string $date_range Date range ('7d', '30d', '90d')
     * @param array $dimensions Dimensions to group by (query, page, country, device, searchAppearance)
     * @param int $row_limit Maximum number of rows to return
     * @return array Search performance data
     * @throws \Exception If API request fails
     */
    public function get_search_performance(string $site_url, string $date_range = '30d', array $dimensions = ['query'], int $row_limit = 1000): array {
        // GSC data for the current day is never complete; use yesterday as the end date
        // so the N-day window matches exactly what the GSC dashboard shows.
        $days       = (int) str_replace('d', '', $date_range);
        $end_date   = gmdate('Y-m-d', strtotime('-2 days'));
        $start_date = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($end_date)));

        $endpoint = '/sites/' . urlencode($site_url) . '/searchAnalytics/query';

        $request_body = [
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => $dimensions,
            'rowLimit' => $row_limit,
            'dataState' => 'all',
        ];

        $full_url = self::API_BASE_URL . $endpoint;
        return $this->make_request($full_url, $request_body, 'POST');
    }

    /**
     * Get aggregated search totals (clicks, impressions, ctr, position)
     *
     * @param string $site_url Site URL to get data for
     * @param string $date_range Date range ('7d', '30d', '90d')
     * @return array Aggregated totals
     * @throws \Exception If API request fails
     */
    public function get_search_totals(string $site_url, string $date_range = '30d'): array {
        // GSC data has a 2-day delay; use D-2 as end_date to match the GSC dashboard.
        $days       = (int) str_replace('d', '', $date_range);
        $end_date   = gmdate('Y-m-d', strtotime('-2 days'));
        $start_date = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($end_date)));

        $endpoint = '/sites/' . urlencode($site_url) . '/searchAnalytics/query';

        // diverse from get_search_performance: no dimensions, just totals
        $request_body = [
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => [], // Empty dimensions for aggregation
            'rowLimit' => 1, // We only need the totals, but API might require at least 1
            'dataState' => 'all',
        ];

        $full_url = self::API_BASE_URL . $endpoint;
        $response = $this->make_request($full_url, $request_body, 'POST');

        // The API returns rows even if we don't ask for dimensions? 
        // Actually, without dimensions, it returns one row with aggregated values if successful.
        // Or sometimes it returns just the aggregates if available. 
        // Let's inspect the response format for GSC API v3. 
        // "If no dimensions are requested, the response will contain a single row with the aggregated values."

        if (!empty($response['rows'])) {
            $row = $response['rows'][0];
            return [
                'clicks' => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1)
            ];
        }

        return [
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'position' => 0
        ];
    }

    /**
     * Get aggregated search totals for explicit start/end dates
     *
     * @param string $site_url  Site URL to get data for
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date   End date (Y-m-d)
     * @return array Aggregated totals
     * @throws \Exception If API request fails
     */
    public function get_search_totals_by_dates(string $site_url, string $start_date, string $end_date): array {
        $endpoint = '/sites/' . urlencode($site_url) . '/searchAnalytics/query';

        $request_body = [
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => [],
            'rowLimit'   => 1,
            'dataState'  => 'all',
        ];

        $full_url = self::API_BASE_URL . $endpoint;
        $response = $this->make_request($full_url, $request_body, 'POST');

        if (!empty($response['rows'])) {
            $row = $response['rows'][0];
            return [
                'clicks'      => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr'         => round(($row['ctr'] ?? 0) * 100, 2),
                'position'    => round($row['position'] ?? 0, 1),
            ];
        }

        return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
    }

    /**
     * Get query-level search performance for explicit start/end dates
     *
     * @param string $site_url   Site URL to get data for
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date   End date (Y-m-d)
     * @param int    $row_limit  Maximum rows to return
     * @return array Raw rows from GSC API
     * @throws \Exception If API request fails
     */
    public function get_search_performance_by_dates(string $site_url, string $start_date, string $end_date, int $row_limit = 500, array $dimensions = ['query']): array {
        $endpoint = '/sites/' . urlencode($site_url) . '/searchAnalytics/query';

        $request_body = [
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => $dimensions,
            'rowLimit'   => $row_limit,
            // 'all' includes both finalised data and fresh (still-processing)
            // data — matches what the Search Console web UI displays, so the
            // last 2-4 days aren't missing.
            'dataState'  => 'all',
        ];

        $full_url = self::API_BASE_URL . $endpoint;
        $response = $this->make_request($full_url, $request_body, 'POST');

        return $response['rows'] ?? [];
    }

    /**
     * Get top search queries
     *
     * @param string $site_url Site URL to get data for
     * @param int $limit Number of queries to retrieve
     * @return array Top search queries
     * @throws \Exception If API request fails
     */
    public function get_top_queries(string $site_url, int $limit = 10): array {
        try {
            $performance_data = $this->get_search_performance($site_url, '30d');
            $queries = [];

            foreach ($performance_data['rows'] ?? [] as $row) {
                if (count($queries) >= $limit) {
                    break;
                }

                $queries[] = [
                    'query' => $row['keys'][0] ?? '',
                    'clicks' => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr' => $row['ctr'] ?? 0,
                    'position' => $row['position'] ?? 0
                ];
            }

            return [
                'queries' => $queries,
                'site_url' => $site_url,
                'limit' => $limit
            ];
        } catch (\Exception $e) {
            return [
                'queries' => [],
                'site_url' => $site_url,
                'limit' => $limit,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get page performance data for SEO analytics
     *
     * @param string $site_url Site URL to get data for
     * @param string $date_range Date range for data
     * @param int $limit Number of pages to retrieve
     * @return array Page performance data
     * @throws \Exception If API request fails
     */
    public function get_page_performance(string $site_url, string $date_range = '30d', int $limit = 25): array {
        $result = $this->get_search_performance($site_url, $date_range, ['page'], $limit);

        $pages = [];
        $rows = $result['rows'] ?? [];

        foreach ($rows as $row) {
            $pages[] = [
                'page' => $row['keys'][0] ?? '',
                'clicks' => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2), // Convert to percentage
                'position' => round($row['position'] ?? 0, 1)
            ];
        }

        return [
            'pages' => $pages,
            'site_url' => $site_url,
            'date_range' => $date_range,
            'total_pages' => count($pages)
        ];
    }

    /**
     * Get device performance breakdown for mobile SEO insights
     *
     * @param string $site_url Site URL to get data for
     * @param string $date_range Date range for data
     * @return array Device performance data
     * @throws \Exception If API request fails
     */
    public function get_device_performance(string $site_url, string $date_range = '30d'): array {
        $result = $this->get_search_performance($site_url, $date_range, ['device'], 10);

        $devices = [];
        $rows = $result['rows'] ?? [];

        foreach ($rows as $row) {
            $device = $row['keys'][0] ?? '';
            $devices[$device] = [
                'clicks' => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1)
            ];
        }

        return [
            'devices' => $devices,
            'site_url' => $site_url,
            'date_range' => $date_range
        ];
    }

    /**
     * Get search appearance data for rich results tracking
     *
     * @param string $site_url Site URL to get data for
     * @param string $date_range Date range for data
     * @return array Search appearance data
     * @throws \Exception If API request fails
     */
    public function get_search_appearance(string $site_url, string $date_range = '30d'): array {
        $result = $this->get_search_performance($site_url, $date_range, ['searchAppearance'], 20);

        $appearances = [];
        $rows = $result['rows'] ?? [];

        foreach ($rows as $row) {
            $appearance = $row['keys'][0] ?? '';
            $appearances[$appearance] = [
                'clicks' => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1)
            ];
        }

        return [
            'appearances' => $appearances,
            'site_url' => $site_url,
            'date_range' => $date_range
        ];
    }

    /**
     * Get site indexing status and coverage data
     *
     * @param string $site_url Site URL to check
     * @return array Indexing status and coverage data
     * @throws \Exception If API request fails
     */
    public function get_indexing_status(string $site_url): array {
        try {
            // Get overall search performance to estimate indexed pages
            $performance = $this->get_search_performance($site_url, '30d', ['page'], 1000);
            $indexed_pages = count($performance['rows'] ?? []);

            // Get basic site info
            $sites = $this->list_sites();
            $site_info = null;

            foreach ($sites['siteEntry'] ?? [] as $site) {
                if ($site['siteUrl'] === $site_url) {
                    $site_info = $site;
                    break;
                }
            }

            return [
                'site_url' => $site_url,
                'is_verified' => !is_null($site_info),
                'indexed_pages_estimate' => $indexed_pages,
                'permission_level' => $site_info['permissionLevel'] ?? 'none',
                'last_updated' => gmdate('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            return [
                'site_url' => $site_url,
                'is_verified' => false,
                'indexed_pages_estimate' => 0,
                'permission_level' => 'none',
                'error' => $e->getMessage(),
                'last_updated' => gmdate('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Get keyword opportunities for SEO insights
     * Identifies queries with high impressions but low CTR or position
     *
     * @param string $site_url Site URL to analyze
     * @param string $date_range Date range for analysis
     * @param int $min_impressions Minimum impressions threshold
     * @return array Keyword opportunities
     * @throws \Exception If API request fails
     */
    public function get_keyword_opportunities(string $site_url, string $date_range = '30d', int $min_impressions = 100): array {
        $result = $this->get_search_performance($site_url, $date_range, ['query'], 500);

        $opportunities = [];
        $rows = $result['rows'] ?? [];

        foreach ($rows as $row) {
            $impressions = $row['impressions'] ?? 0;
            $ctr = $row['ctr'] ?? 0;
            $position = $row['position'] ?? 0;
            $clicks = $row['clicks'] ?? 0;

            // Identify opportunities: high impressions, low CTR, or position 4-10
            if ($impressions >= $min_impressions) {
                $opportunity_score = 0;
                $opportunity_reasons = [];

                // Low CTR opportunity
                if ($ctr < 0.05 && $position <= 10) { // Less than 5% CTR in top 10
                    $opportunity_score += 30;
                    $opportunity_reasons[] = 'Low CTR for top 10 position';
                }

                // Position 4-10 opportunity (could reach top 3)
                if ($position >= 4 && $position <= 10) {
                    $opportunity_score += 40;
                    $opportunity_reasons[] = 'Ranking 4-10, potential for top 3';
                }

                // High impressions, low clicks
                if ($impressions > 500 && $clicks < 25) {
                    $opportunity_score += 20;
                    $opportunity_reasons[] = 'High impressions but low clicks';
                }

                if ($opportunity_score > 0) {
                    $opportunities[] = [
                        'query' => $row['keys'][0] ?? '',
                        'clicks' => $clicks,
                        'impressions' => $impressions,
                        'ctr' => round($ctr * 100, 2),
                        'position' => round($position, 1),
                        'opportunity_score' => $opportunity_score,
                        'reasons' => $opportunity_reasons
                    ];
                }
            }
        }

        // Sort by opportunity score (highest first)
        usort($opportunities, function ($a, $b) {
            return $b['opportunity_score'] <=> $a['opportunity_score'];
        });

        return [
            'opportunities' => array_slice($opportunities, 0, 50), // Top 50 opportunities
            'site_url' => $site_url,
            'date_range' => $date_range,
            'total_opportunities' => count($opportunities)
        ];
    }

    /**
     * Return branded vs non-branded click/impression split that matches the GSC platform.
     *
     * Uses two server-side aggregate calls per period (empty dimensions + dimensionFilterGroups)
     * so the totals are exact — not limited by the 1 000-row query cap:
     *
     *   • Call A: no filter          → real site total clicks
     *   • Call B: query contains brand → branded clicks
     *   • Non-branded = A − B
     *
     * Also fetches the equivalent previous period so the frontend can render trend arrows.
     *
     * @param string $site_url   Registered GSC property URL
     * @param string $date_range '7d' | '30d' | '90d'
     * @param string $brand_name Comma-separated brand keywords. Auto-derived from domain when empty.
     * @return array {
     *   branded, non_branded, previous: { branded, non_branded },
     *   brand_terms, total_clicks, site_url, date_range
     * }
     */
    public function get_branded_performance(string $site_url, string $date_range = '30d', string $brand_name = ''): array {
        $days  = max(1, (int) str_replace('d', '', $date_range));
        $end   = gmdate('Y-m-d', strtotime('-2 days'));
        $start = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($end)));

        $prev_end   = gmdate('Y-m-d', strtotime('-1 day', strtotime($start)));
        $prev_start = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($prev_end)));

        // Auto-derive brand from domain when not provided.
        // Handles both URL-prefix (https://example.com) and domain (sc-domain:example.com) formats.
        if (empty($brand_name)) {
            $stripped   = preg_replace('#^sc-domain:#i', '', $site_url);
            $host       = wp_parse_url($stripped, PHP_URL_HOST) ?? wp_parse_url('https://' . $stripped, PHP_URL_HOST) ?? $stripped;
            $host       = preg_replace('/^www\./i', '', (string) $host);
            $brand_name = strtolower(explode('.', $host)[0]);
        }
        $terms = array_values(array_filter(array_map('trim', explode(',', strtolower($brand_name)))));

        // For hyphenated brands (e.g. "essential-blocks") also match the space variant
        // ("essential blocks") since users type both forms in Google searches.
        $extra = [];
        foreach ($terms as $t) {
            if (str_contains($t, '-')) {
                $spaced = str_replace('-', ' ', $t);
                if (!in_array($spaced, $terms, true)) {
                    $extra[] = $spaced;
                }
            }
        }
        $terms = array_values(array_merge($terms, $extra));

        $split_cur  = $this->gsc_split_by_brand($site_url, $start,      $end,      $terms);
        $split_prev = $this->gsc_split_by_brand($site_url, $prev_start, $prev_end, $terms);

        return [
            'branded'      => $split_cur['branded'],
            'non_branded'  => $split_cur['non_branded'],
            'previous'     => [
                'branded'     => $split_prev['branded'],
                'non_branded' => $split_prev['non_branded'],
            ],
            'brand_terms'  => $terms,
            'total_clicks' => $split_cur['total_clicks'],
            'site_url'     => $site_url,
            'date_range'   => $date_range,
        ];
    }

    /**
     * Fetch all web query rows for a date window and split into branded / non-branded
     * using a single API call. Both totals come from the same data set so the
     * percentages always add up to 100 %.
     *
     * @param string   $site_url    GSC property URL
     * @param string   $start       Start date (Y-m-d)
     * @param string   $end         End date (Y-m-d)
     * @param string[] $brand_terms Brand keywords to match (substring, case-insensitive)
     * @return array { branded: {...}, non_branded: {...}, total_clicks: int }
     */
    private function gsc_split_by_brand(string $site_url, string $start, string $end, array $brand_terms): array {
        $url = self::API_BASE_URL . '/sites/' . urlencode($site_url) . '/searchAnalytics/query';

        $page_size       = 25000;
        $start_row       = 0;
        $total_clicks    = 0;
        $branded_clicks  = 0;
        $total_impr      = 0;
        $branded_impr    = 0;

        // Safety cap so a runaway query can never loop unbounded. With a 25k
        // page size this stops after ~100k rows (4 pages), which is far beyond
        // the query volume of any real site for a single date window.
        $max_pages     = 4;
        $pages_fetched = 0;

        do {
            $response = $this->make_request($url, [
                'startDate'  => $start,
                'endDate'    => $end,
                'type'       => 'web',
                'dimensions' => ['query'],
                'rowLimit'   => $page_size,
                'startRow'   => $start_row,
                'dataState'  => 'all',
            ], 'POST');

            $rows = $response['rows'] ?? [];
            foreach ($rows as $row) {
                $query   = strtolower($row['keys'][0] ?? '');
                $clicks  = (int) ($row['clicks']      ?? 0);
                $impr    = (int) ($row['impressions'] ?? 0);

                $total_clicks += $clicks;
                $total_impr   += $impr;

                foreach ($brand_terms as $term) {
                    if (str_contains($query, $term)) {
                        $branded_clicks += $clicks;
                        $branded_impr   += $impr;
                        break;
                    }
                }
            }

            $fetched    = count($rows);
            $start_row += $fetched;
            $pages_fetched++;
        } while ($fetched === $page_size && $pages_fetched < $max_pages);

        $non_branded_clicks = max(0, $total_clicks - $branded_clicks);
        $non_branded_impr   = max(0, $total_impr   - $branded_impr);

        return [
            'branded' => [
                'clicks'      => $branded_clicks,
                'impressions' => $branded_impr,
                'percentage'  => $total_clicks > 0 ? round($branded_clicks / $total_clicks * 100) : 0,
            ],
            'non_branded' => [
                'clicks'      => $non_branded_clicks,
                'impressions' => $non_branded_impr,
                'percentage'  => $total_clicks > 0 ? round($non_branded_clicks / $total_clicks * 100) : 0,
            ],
            'total_clicks' => $total_clicks,
        ];
    }

    /**
     * Get top countries by clicks from Search Console.
     *
     * Queries with the `country` dimension and returns rows sorted by clicks
     * descending, each enriched with a percentage share of the total clicks.
     *
     * @param string $site_url   Site URL to query
     * @param string $date_range Date range ('7d', '30d', '90d')
     * @param int    $row_limit  Maximum countries to return (default 10)
     * @return array { countries: array, total_clicks: int, site_url: string, date_range: string }
     */
    public function get_country_performance(string $site_url, string $date_range = '30d', int $row_limit = 10): array {
        $result = $this->get_search_performance($site_url, $date_range, ['country'], $row_limit);
        $rows   = $result['rows'] ?? [];

        $total_clicks = 0;
        foreach ($rows as $row) {
            $total_clicks += (int) ($row['clicks'] ?? 0);
        }

        $countries = [];
        foreach ($rows as $row) {
            $clicks = (int) ($row['clicks'] ?? 0);
            $countries[] = [
                'country'     => strtolower($row['keys'][0] ?? ''),
                'clicks'      => $clicks,
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => round(($row['ctr'] ?? 0) * 100, 2),
                'position'    => round($row['position'] ?? 0, 1),
                'percentage'  => $total_clicks > 0 ? round(($clicks / $total_clicks) * 100) : 0,
            ];
        }

        return [
            'countries'    => $countries,
            'total_clicks' => $total_clicks,
            'site_url'     => $site_url,
            'date_range'   => $date_range,
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
        return 'Google Search Console API rate limit exceeded. Try again tomorrow.';
    }
}
