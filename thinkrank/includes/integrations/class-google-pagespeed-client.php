<?php
/**
 * Google PageSpeed Insights Client Class
 *
 * Handles communication with Google PageSpeed Insights API for Core Web Vitals
 * and performance data retrieval. Extends the base Google API client with
 * PageSpeed-specific functionality and rate limiting.
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
 * Google PageSpeed Insights Client Class
 *
 * Single Responsibility: Handle PageSpeed Insights API communication
 * Following ThinkRank HTTP client patterns from Claude_Client and OpenAI_Client
 *
 * @since 1.0.0
 */
class Google_PageSpeed_Client extends Google_API_Base_Client {

    /**
     * PageSpeed Insights API base URL
     */
    private const API_BASE_URL = 'https://www.googleapis.com/pagespeedonline/v5';

    /**
     * Rate limit transient key prefix
     * Following ThinkRank option naming patterns
     */
    private const RATE_LIMIT_KEY = 'thinkrank_pagespeed_rate_limit';

    /**
     * Maximum requests per day (Google free tier limit)
     */
    private const MAX_REQUESTS_PER_DAY = 25000;

    /**
     * How long a parsed PageSpeed snapshot is reused before a new live run (seconds)
     */
    private const SNAPSHOT_TTL = 600;

    /**
     * How long a failed PageSpeed run is remembered before retrying (seconds)
     *
     * Lighthouse runs are slow (10-60s); without this, a failing URL (e.g. a
     * non-public localhost) would block every admin request for the full HTTP
     * timeout.
     */
    private const FAILURE_TTL = 300;

    /**
     * Per-request memo of parsed snapshots, keyed by url|strategy
     *
     * @var array<string,array>
     */
    private static array $snapshot_memo = [];

    /**
     * Default HTTP timeout for PageSpeed runs (seconds).
     *
     * Real Lighthouse runs routinely take 15-45s; the previous 20s timeout
     * aborted a large share of otherwise-successful runs.
     */
    public const DEFAULT_TIMEOUT = 45;

    /**
     * Build a client authenticated the way the PageSpeed API expects.
     *
     * Auth order (RankMath uses the same model, minus the key):
     *  1. Site-owned API key — dedicated per-project quota, always reliable.
     *  2. The user's Google OAuth token — works at low volume; quota is
     *     shared across the OAuth project, so ThinkRank must stay frugal
     *     (see the 7-day refresh gate in Performance_Data_Collector).
     *  3. Keyless — Google's shared anonymous pool; last resort.
     *
     * @param int|null $timeout HTTP timeout in seconds (default self::DEFAULT_TIMEOUT)
     * @return self
     */
    public static function for_site(?int $timeout = null): self {
        $api_key      = '';
        $access_token = '';
        if (class_exists('\\ThinkRank\\Core\\Settings')) {
            $settings     = new \ThinkRank\Core\Settings();
            $api_key      = (string) $settings->get('google_pagespeed_api_key', '');
            $access_token = (string) $settings->get('google_access_token', '');
        }

        if ($api_key !== '') {
            // A dedicated key wins: pass no token so quota bills the key's project.
            return new self($api_key, $timeout ?? self::DEFAULT_TIMEOUT, null);
        }

        return new self('', $timeout ?? self::DEFAULT_TIMEOUT, $access_token !== '' ? $access_token : null);
    }

    /**
     * Run PageSpeed test for a URL
     *
     * @param string $url URL to test
     * @param string $strategy Device strategy ('mobile' or 'desktop')
     * @param array $categories Categories to test (default: ['performance'])
     * @return array PageSpeed test results
     * @throws \Exception If API request fails
     */
    public function run_pagespeed_test(string $url, string $strategy = 'mobile', array $categories = ['performance']): array {
        $endpoint = '/runPagespeed';
        $params = [
            'url' => $url,
            'strategy' => $strategy,
            // http_build_query would serialize an array as category[0]=…,
            // which the PSI API ignores; a single category must be a scalar.
            'category' => count($categories) === 1 ? $categories[0] : $categories,
        ];

        $full_url = self::API_BASE_URL . $endpoint;
        return $this->make_request($full_url, $params, 'GET');
    }

    /**
     * Get a parsed PageSpeed snapshot for a URL, from cache when possible.
     *
     * One live Lighthouse run produces Core Web Vitals, opportunities,
     * diagnostics and the performance score together; callers that previously
     * triggered separate runs for each now share a single cached result.
     * Failures are remembered briefly (FAILURE_TTL) so a broken URL doesn't
     * re-block every request for the full HTTP timeout.
     *
     * @param string $url URL to analyze
     * @param string $strategy Device strategy ('mobile' or 'desktop')
     * @return array{core_web_vitals:array,opportunities:array,diagnostics:array,performance_score:float,fetched_at:int}
     * @throws \Exception If the API request fails (including remembered recent failures)
     */
    public function get_pagespeed_snapshot(string $url, string $strategy = 'mobile'): array {
        $memo_key = $url . '|' . $strategy;
        if (isset(self::$snapshot_memo[$memo_key])) {
            return self::$snapshot_memo[$memo_key];
        }

        $hash = md5($memo_key);
        $cached = get_transient('thinkrank_psi_snapshot_' . $hash);
        if (is_array($cached)) {
            self::$snapshot_memo[$memo_key] = $cached;
            return $cached;
        }

        $recent_failure = get_transient('thinkrank_psi_failure_' . $hash);
        if (is_string($recent_failure) && $recent_failure !== '') {
            throw new \Exception($recent_failure); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        try {
            $result = $this->run_pagespeed_test($url, $strategy, ['performance']);
        } catch (\Exception $e) {
            set_transient('thinkrank_psi_failure_' . $hash, $e->getMessage(), self::FAILURE_TTL);
            throw $e;
        }

        $snapshot = [
            'core_web_vitals'    => $this->parse_core_web_vitals($result),
            'opportunities'      => $this->parse_opportunities($result),
            'diagnostics'        => $this->parse_diagnostics($result),
            'performance_score'  => (float) (($result['lighthouseResult']['categories']['performance']['score'] ?? 0) * 100),
            'loading_experience' => $result['loadingExperience'] ?? [],
            'fetched_at'         => time(),
        ];

        set_transient('thinkrank_psi_snapshot_' . $hash, $snapshot, self::SNAPSHOT_TTL);
        self::$snapshot_memo[$memo_key] = $snapshot;

        return $snapshot;
    }

    /**
     * Test API connection
     * Following ThinkRank test_connection patterns from AI clients
     *
     * @return array Connection test results
     */
    public function test_connection(): array {
        try {
            $test_url = home_url();
            $result = $this->run_pagespeed_test($test_url, 'mobile', ['performance']);

            $performance_score = 0;
            if (isset($result['lighthouseResult']['categories']['performance']['score'])) {
                $performance_score = $result['lighthouseResult']['categories']['performance']['score'] * 100;
            }

            return [
                'success' => true,
                'message' => 'PageSpeed Insights API connection successful',
                'test_url' => $test_url,
                'performance_score' => $performance_score
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Core Web Vitals data for a URL
     *
     * @param string $url URL to analyze
     * @param string $strategy Device strategy ('mobile' or 'desktop')
     * @return array Core Web Vitals data
     * @throws \Exception If API request fails
     */
    public function get_core_web_vitals(string $url, string $strategy = 'mobile'): array {
        return $this->get_pagespeed_snapshot($url, $strategy)['core_web_vitals'];
    }

    /**
     * Get performance opportunities for a URL
     *
     * @param string $url URL to test
     * @param string $strategy Testing strategy (mobile/desktop)
     * @return array Performance opportunities
     * @throws \Exception If API request fails
     */
    public function get_opportunities(string $url, string $strategy = 'mobile'): array {
        return $this->get_pagespeed_snapshot($url, $strategy)['opportunities'];
    }

    /**
     * Get diagnostic information for a URL
     *
     * @param string $url URL to test
     * @param string $strategy Testing strategy (mobile/desktop)
     * @return array Diagnostic information
     * @throws \Exception If API request fails
     */
    public function get_diagnostics(string $url, string $strategy = 'mobile'): array {
        return $this->get_pagespeed_snapshot($url, $strategy)['diagnostics'];
    }

    /**
     * Parse Core Web Vitals from PageSpeed response
     *
     * @param array $pagespeed_data Raw PageSpeed API response
     * @return array Parsed Core Web Vitals data
     */
    private function parse_core_web_vitals(array $pagespeed_data): array {
        $audits = $pagespeed_data['lighthouseResult']['audits'] ?? [];

        return [
            'lcp' => [
                'name' => 'Largest Contentful Paint',
                'value' => round((($audits['largest-contentful-paint']['numericValue'] ?? 0) / 1000), 4),
                'score' => ($audits['largest-contentful-paint']['score'] ?? 0) * 100,
                'unit' => 's',
                'good_threshold' => 2.5,
                'needs_improvement_threshold' => 4.0,
                'description' => 'Time until the largest content element is rendered'
            ],
            'fid' => [
                'name' => 'First Input Delay',
                'value' => round(($audits['max-potential-fid']['numericValue'] ?? 0), 4),
                'score' => ($audits['max-potential-fid']['score'] ?? 0) * 100,
                'unit' => 'ms',
                'good_threshold' => 100,
                'needs_improvement_threshold' => 300,
                'description' => 'Time from first user interaction to browser response'
            ],
            'cls' => [
                'name' => 'Cumulative Layout Shift',
                'value' => round(($audits['cumulative-layout-shift']['numericValue'] ?? 0), 4),
                'score' => ($audits['cumulative-layout-shift']['score'] ?? 0) * 100,
                'unit' => '',
                'good_threshold' => 0.1,
                'needs_improvement_threshold' => 0.25,
                'description' => 'Measure of visual stability during page load'
            ],
            'fcp' => [
                'name' => 'First Contentful Paint',
                'value' => round((($audits['first-contentful-paint']['numericValue'] ?? 0) / 1000), 4),
                'score' => ($audits['first-contentful-paint']['score'] ?? 0) * 100,
                'unit' => 's',
                'good_threshold' => 1.8,
                'needs_improvement_threshold' => 3.0,
                'description' => 'Time until the first content is painted on screen'
            ]
        ];
    }

    /**
     * Parse performance opportunities from PageSpeed data
     *
     * @param array $pagespeed_data Raw PageSpeed API response
     * @return array Parsed opportunities data
     */
    private function parse_opportunities(array $pagespeed_data): array {
        $audits = $pagespeed_data['lighthouseResult']['audits'] ?? [];
        $opportunities = [];

        // Define opportunity audits that provide savings estimates
        $opportunity_audits = [
            'render-blocking-resources' => 'Eliminate render-blocking resources',
            'unused-css-rules' => 'Remove unused CSS',
            'unused-javascript' => 'Remove unused JavaScript',
            'modern-image-formats' => 'Serve images in next-gen formats',
            'offscreen-images' => 'Defer offscreen images',
            'unminified-css' => 'Minify CSS',
            'unminified-javascript' => 'Minify JavaScript',
            'efficient-animated-content' => 'Use video formats for animated content',
            'duplicated-javascript' => 'Remove duplicate modules in JavaScript bundles',
            'legacy-javascript' => 'Avoid serving legacy JavaScript to modern browsers'
        ];

        foreach ($opportunity_audits as $audit_id => $title) {
            if (isset($audits[$audit_id]) && isset($audits[$audit_id]['details'])) {
                $audit = $audits[$audit_id];
                $savings = $audit['details']['overallSavingsMs'] ?? 0;

                if ($savings > 0) {
                    $opportunities[] = [
                        'id' => $audit_id,
                        'title' => $title,
                        'description' => $audit['description'] ?? '',
                        'estimated_savings' => $savings,
                        'score' => ($audit['score'] ?? 0) * 100,
                        'details' => $audit['details'] ?? [],
                        'difficulty' => $this->get_difficulty_level($audit_id)
                    ];
                }
            }
        }

        // Sort by estimated savings (highest first)
        usort($opportunities, function($a, $b) {
            return $b['estimated_savings'] - $a['estimated_savings'];
        });

        return $opportunities;
    }

    /**
     * Parse diagnostic information from PageSpeed data
     *
     * @param array $pagespeed_data Raw PageSpeed API response
     * @return array Parsed diagnostics data
     */
    private function parse_diagnostics(array $pagespeed_data): array {
        $audits = $pagespeed_data['lighthouseResult']['audits'] ?? [];
        $diagnostics = [];

        // Define diagnostic audits
        $diagnostic_audits = [
            'first-contentful-paint' => ['title' => 'First Contentful Paint', 'impact' => 'Performance'],
            'largest-contentful-paint' => ['title' => 'Largest Contentful Paint', 'impact' => 'LCP'],
            'first-meaningful-paint' => ['title' => 'First Meaningful Paint', 'impact' => 'Performance'],
            'speed-index' => ['title' => 'Speed Index', 'impact' => 'Performance'],
            'total-blocking-time' => ['title' => 'Total Blocking Time', 'impact' => 'Performance'],
            'max-potential-fid' => ['title' => 'Max Potential First Input Delay', 'impact' => 'FID'],
            'cumulative-layout-shift' => ['title' => 'Cumulative Layout Shift', 'impact' => 'CLS'],
            'server-response-time' => ['title' => 'Initial server response time was short', 'impact' => 'Performance'],
            'interactive' => ['title' => 'Time to Interactive', 'impact' => 'Performance'],
            'user-timings' => ['title' => 'User Timing marks and measures', 'impact' => 'Performance'],
            'critical-request-chains' => ['title' => 'Avoid chaining critical requests', 'impact' => 'Performance'],
            'redirects' => ['title' => 'Avoid multiple page redirects', 'impact' => 'Performance'],
            'installable-manifest' => ['title' => 'Web app manifest meets the installability requirements', 'impact' => 'PWA'],
            'apple-touch-icon' => ['title' => 'Provides a valid apple-touch-icon', 'impact' => 'PWA'],
            'splash-screen' => ['title' => 'Configured for a custom splash screen', 'impact' => 'PWA'],
            'themed-omnibox' => ['title' => 'Sets a theme color for the address bar', 'impact' => 'PWA'],
            'content-width' => ['title' => 'Content is sized correctly for the viewport', 'impact' => 'Mobile'],
            'image-aspect-ratio' => ['title' => 'Displays images with correct aspect ratio', 'impact' => 'Layout'],
            'image-size-responsive' => ['title' => 'Serves images with appropriate resolution', 'impact' => 'Performance'],
            'preload-fonts' => ['title' => 'Fonts with font-display: optional are preloaded', 'impact' => 'Performance'],
            'font-display' => ['title' => 'All text remains visible during webfont loads', 'impact' => 'Performance']
        ];

        foreach ($diagnostic_audits as $audit_id => $config) {
            if (isset($audits[$audit_id])) {
                $audit = $audits[$audit_id];
                $score = $audit['score'] ?? null;

                $status = 'info';
                if ($score !== null) {
                    if ($score >= 0.9) {
                        $status = 'passed';
                    } elseif ($score >= 0.5) {
                        $status = 'warning';
                    } else {
                        $status = 'failed';
                    }
                }

                $diagnostics[] = [
                    'id' => $audit_id,
                    'title' => $config['title'],
                    'description' => $audit['description'] ?? '',
                    'status' => $status,
                    'score' => $score ? ($score * 100) : null,
                    'impact' => $config['impact'],
                    'details' => $audit['details'] ?? [],
                    'display_value' => $audit['displayValue'] ?? null
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * Get difficulty level for optimization opportunities
     *
     * @param string $audit_id Audit identifier
     * @return string Difficulty level
     */
    private function get_difficulty_level(string $audit_id): string {
        $difficulty_map = [
            'unminified-css' => 'Easy',
            'unminified-javascript' => 'Easy',
            'modern-image-formats' => 'Easy',
            'offscreen-images' => 'Medium',
            'unused-css-rules' => 'Hard',
            'unused-javascript' => 'Hard',
            'render-blocking-resources' => 'Medium',
            'efficient-animated-content' => 'Medium',
            'duplicated-javascript' => 'Hard',
            'legacy-javascript' => 'Medium'
        ];

        return $difficulty_map[$audit_id] ?? 'Medium';
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
        return 'PageSpeed Insights API rate limit exceeded. Try again tomorrow.';
    }
}
