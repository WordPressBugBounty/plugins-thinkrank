<?php
/**
 * SEO Trend Analyzer Class
 *
 * Analyzes SEO performance trends from Google API data to identify patterns,
 * changes, and growth opportunities. Processes traffic, keyword, and content
 * performance data to generate intelligent trend insights.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Trend Analyzer Class
 *
 * Single Responsibility: Analyze SEO performance trends and patterns
 * Following ThinkRank analyzer patterns from existing codebase
 *
 * @since 1.0.0
 */
class SEO_Trend_Analyzer {

    /**
     * Minimum data points required for trend analysis
     *
     * @var int
     */
    private const MIN_DATA_POINTS = 2;

    /**
     * Significant change threshold percentage
     *
     * @var float
     */
    private const SIGNIFICANT_CHANGE_THRESHOLD = 5.0;

    /**
     * Analyze traffic trends from Google Analytics data
     *
     * @param array $current_data Current period traffic data
     * @param array $historical_data Previous period traffic data
     * @return array Traffic trend analysis
     */
    public function analyze_traffic_trends(array $current_data, array $historical_data): array {
        $trends = [
            'sessions' => $this->calculate_trend_metrics(
                $historical_data['sessions'] ?? 0,
                $current_data['sessions'] ?? 0,
                'Sessions'
            ),
            'pageviews' => $this->calculate_trend_metrics(
                $historical_data['pageviews'] ?? 0,
                $current_data['pageviews'] ?? 0,
                'Pageviews'
            ),
            'organic_traffic' => $this->analyze_organic_traffic_trend($current_data, $historical_data),
            'bounce_rate' => $this->calculate_trend_metrics(
                $historical_data['bounce_rate'] ?? 0,
                $current_data['bounce_rate'] ?? 0,
                'Bounce Rate',
                true // Lower is better for bounce rate
            ),
            'avg_session_duration' => $this->calculate_trend_metrics(
                $historical_data['avg_session_duration'] ?? 0,
                $current_data['avg_session_duration'] ?? 0,
                'Average Session Duration'
            ),
            'summary' => $this->generate_traffic_trend_summary($current_data, $historical_data)
        ];

        return $trends;
    }

    /**
     * Analyze keyword performance trends from Search Console data
     *
     * @param array $search_console_data Search Console performance data
     * @param string $date_range Date range for comparison
     * @return array Keyword trend analysis
     */
    public function analyze_keyword_trends(array $search_console_data, string $date_range): array {
        $trends = [
            'total_clicks' => $this->extract_total_metrics($search_console_data, 'clicks'),
            'total_impressions' => $this->extract_total_metrics($search_console_data, 'impressions'),
            'average_ctr' => $this->calculate_average_ctr($search_console_data),
            'average_position' => $this->calculate_average_position($search_console_data),
            'top_gaining_keywords' => $this->identify_gaining_keywords($search_console_data),
            'top_losing_keywords' => $this->identify_losing_keywords($search_console_data),
            'new_keywords' => $this->identify_new_keywords($search_console_data),
            'summary' => $this->generate_keyword_trend_summary($search_console_data)
        ];

        return $trends;
    }

    /**
     * Analyze content performance trends
     *
     * @param array $analytics_data Google Analytics data
     * @param array $search_data Search Console data
     * @return array Content trend analysis
     */
    public function analyze_content_trends(array $analytics_data, array $search_data): array {
        $trends = [
            'top_gaining_pages' => $this->identify_gaining_pages($analytics_data, $search_data),
            'top_losing_pages' => $this->identify_losing_pages($analytics_data, $search_data),
            'mobile_vs_desktop' => $this->analyze_device_trends($search_data),
            'content_type_performance' => $this->analyze_content_type_performance($analytics_data),
            'summary' => $this->generate_content_trend_summary($analytics_data, $search_data)
        ];

        return $trends;
    }

    /**
     * Calculate growth rates between current and previous periods
     *
     * @param array $current Current period data
     * @param array $previous Previous period data
     * @return array Growth rate calculations
     */
    public function calculate_growth_rates(array $current, array $previous): array {
        $growth_rates = [];

        foreach ($current as $metric => $current_value) {
            $previous_value = $previous[$metric] ?? 0;
            $growth_rates[$metric] = $this->calculate_percentage_change($previous_value, $current_value);
        }

        return $growth_rates;
    }

    /**
     * Detect seasonal patterns in historical data
     *
     * @param array $historical_data Historical performance data
     * @return array Seasonal pattern analysis
     */
    public function detect_seasonal_patterns(array $historical_data): array {
        if (count($historical_data) < 12) {
            return [
                'patterns_detected' => false,
                'message' => 'Insufficient data for seasonal analysis (minimum 12 data points required)'
            ];
        }

        $patterns = [
            'patterns_detected' => true,
            'monthly_trends' => $this->analyze_monthly_patterns($historical_data),
            'weekly_trends' => $this->analyze_weekly_patterns($historical_data),
            'recommendations' => $this->generate_seasonal_recommendations($historical_data)
        ];

        return $patterns;
    }

    /**
     * Calculate trend metrics for a specific metric
     *
     * @param float $previous Previous period value
     * @param float $current Current period value
     * @param string $metric_name Human-readable metric name
     * @param bool $lower_is_better Whether lower values are better (e.g., bounce rate)
     * @return array Trend metrics
     */
    private function calculate_trend_metrics(float $previous, float $current, string $metric_name, bool $lower_is_better = false): array {
        $change = $current - $previous;
        $percentage_change = $this->calculate_percentage_change($previous, $current);
        
        $trend_direction = $this->determine_trend_direction($percentage_change, $lower_is_better);
        $significance = $this->determine_significance($percentage_change);

        return [
            'previous' => $previous,
            'current' => $current,
            'change' => $change,
            'percentage_change' => $percentage_change,
            'trend_direction' => $trend_direction,
            'significance' => $significance,
            'metric_name' => $metric_name,
            'interpretation' => $this->generate_trend_interpretation($metric_name, $percentage_change, $trend_direction)
        ];
    }

    /**
     * Calculate percentage change between two values
     *
     * @param float $previous Previous value
     * @param float $current Current value
     * @return float Percentage change
     */
    private function calculate_percentage_change(float $previous, float $current): float {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Determine trend direction
     *
     * @param float $percentage_change Percentage change value
     * @param bool $lower_is_better Whether lower values are better
     * @return string Trend direction (up, down, stable)
     */
    private function determine_trend_direction(float $percentage_change, bool $lower_is_better = false): string {
        if (abs($percentage_change) < self::SIGNIFICANT_CHANGE_THRESHOLD) {
            return 'stable';
        }

        if ($lower_is_better) {
            return $percentage_change > 0 ? 'down' : 'up';
        }

        return $percentage_change > 0 ? 'up' : 'down';
    }

    /**
     * Determine significance of change
     *
     * @param float $percentage_change Percentage change value
     * @return string Significance level (high, medium, low)
     */
    private function determine_significance(float $percentage_change): string {
        $abs_change = abs($percentage_change);

        if ($abs_change >= 20) {
            return 'high';
        } elseif ($abs_change >= 10) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Generate trend interpretation text
     *
     * @param string $metric_name Metric name
     * @param float $percentage_change Percentage change
     * @param string $trend_direction Trend direction
     * @return string Human-readable interpretation
     */
    private function generate_trend_interpretation(string $metric_name, float $percentage_change, string $trend_direction): string {
        $abs_change = abs($percentage_change);

        switch ($trend_direction) {
            case 'up':
                return sprintf('%s increased by %.1f%%', $metric_name, $abs_change);
            case 'down':
                return sprintf('%s decreased by %.1f%%', $metric_name, $abs_change);
            case 'stable':
                return sprintf('%s remained stable (%.1f%% change)', $metric_name, $percentage_change);
            default:
                return sprintf('%s changed by %.1f%%', $metric_name, $percentage_change);
        }
    }

    /**
     * Analyze organic traffic trend specifically
     *
     * @param array $current_data Current period data
     * @param array $historical_data Historical period data
     * @return array Organic traffic trend analysis
     */
    private function analyze_organic_traffic_trend(array $current_data, array $historical_data): array {
        $current_organic = $current_data['organic_traffic']['organic_traffic'] ?? [];
        $historical_organic = $historical_data['organic_traffic']['organic_traffic'] ?? [];

        return $this->calculate_trend_metrics(
            $historical_organic['sessions'] ?? 0,
            $current_organic['sessions'] ?? 0,
            'Organic Traffic Sessions'
        );
    }

    /**
     * Extract total metrics from Search Console data
     *
     * @param array $search_console_data Search Console data
     * @param string $metric Metric to extract (clicks, impressions)
     * @return array Total metrics with trend data
     */
    private function extract_total_metrics(array $search_console_data, string $metric): array {
        $total = 0;
        $rows = $search_console_data['rows'] ?? [];

        foreach ($rows as $row) {
            $total += $row[$metric] ?? 0;
        }

        return [
            'total' => $total,
            'metric' => $metric,
            'data_points' => count($rows)
        ];
    }

    /**
     * Calculate average CTR from Search Console data
     *
     * @param array $search_console_data Search Console data
     * @return array Average CTR analysis
     */
    private function calculate_average_ctr(array $search_console_data): array {
        $rows = $search_console_data['rows'] ?? [];
        $total_clicks = 0;
        $total_impressions = 0;

        foreach ($rows as $row) {
            $total_clicks += $row['clicks'] ?? 0;
            $total_impressions += $row['impressions'] ?? 0;
        }

        $average_ctr = $total_impressions > 0 ? ($total_clicks / $total_impressions) * 100 : 0;

        return [
            'average_ctr' => round($average_ctr, 2),
            'total_clicks' => $total_clicks,
            'total_impressions' => $total_impressions
        ];
    }

    /**
     * Calculate average position from Search Console data
     *
     * @param array $search_console_data Search Console data
     * @return array Average position analysis
     */
    private function calculate_average_position(array $search_console_data): array {
        $rows = $search_console_data['rows'] ?? [];
        $total_position = 0;
        $count = 0;

        foreach ($rows as $row) {
            if (isset($row['position']) && $row['position'] > 0) {
                $total_position += $row['position'];
                $count++;
            }
        }

        $average_position = $count > 0 ? $total_position / $count : 0;

        return [
            'average_position' => round($average_position, 1),
            'keywords_tracked' => $count
        ];
    }

    /**
     * Identify gaining keywords (improved performance)
     *
     * @param array $search_console_data Search Console data
     * @return array Top gaining keywords
     */
    private function identify_gaining_keywords(array $search_console_data): array {
        $rows = $search_console_data['rows'] ?? [];
        $gaining_keywords = [];

        foreach ($rows as $row) {
            $clicks = $row['clicks'] ?? 0;
            $impressions = $row['impressions'] ?? 0;
            $position = $row['position'] ?? 0;

            // Consider keywords with good performance metrics as "gaining"
            if ($clicks > 10 && $position <= 10) {
                $gaining_keywords[] = [
                    'keyword' => $row['keys'][0] ?? '',
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => round($position, 1),
                    'ctr' => round(($clicks / max($impressions, 1)) * 100, 2)
                ];
            }
        }

        // Sort by clicks descending
        usort($gaining_keywords, function($a, $b) {
            return $b['clicks'] <=> $a['clicks'];
        });

        return array_slice($gaining_keywords, 0, 10);
    }

    /**
     * Identify losing keywords (declined performance)
     *
     * @param array $search_console_data Search Console data
     * @return array Top losing keywords
     */
    private function identify_losing_keywords(array $search_console_data): array {
        $rows = $search_console_data['rows'] ?? [];
        $losing_keywords = [];

        foreach ($rows as $row) {
            $clicks = $row['clicks'] ?? 0;
            $impressions = $row['impressions'] ?? 0;
            $position = $row['position'] ?? 0;

            // Consider keywords with poor performance as "losing"
            if ($impressions > 100 && $position > 20) {
                $losing_keywords[] = [
                    'keyword' => $row['keys'][0] ?? '',
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => round($position, 1),
                    'ctr' => round(($clicks / max($impressions, 1)) * 100, 2)
                ];
            }
        }

        // Sort by position descending (worst positions first)
        usort($losing_keywords, function($a, $b) {
            return $b['position'] <=> $a['position'];
        });

        return array_slice($losing_keywords, 0, 10);
    }

    /**
     * Identify new keywords appearing in results
     *
     * @param array $search_console_data Search Console data
     * @return array New keywords analysis
     */
    private function identify_new_keywords(array $search_console_data): array {
        $rows = $search_console_data['rows'] ?? [];
        $new_keywords = [];

        foreach ($rows as $row) {
            $clicks = $row['clicks'] ?? 0;
            $impressions = $row['impressions'] ?? 0;

            // Consider keywords with recent activity as potentially "new"
            if ($clicks > 0 && $impressions > 50) {
                $new_keywords[] = [
                    'keyword' => $row['keys'][0] ?? '',
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'position' => round($row['position'] ?? 0, 1)
                ];
            }
        }

        // Sort by impressions descending
        usort($new_keywords, function($a, $b) {
            return $b['impressions'] <=> $a['impressions'];
        });

        return array_slice($new_keywords, 0, 5);
    }

    /**
     * Generate traffic trend summary
     *
     * @param array $current_data Current period data
     * @param array $historical_data Historical period data
     * @return string Traffic trend summary
     */
    private function generate_traffic_trend_summary(array $current_data, array $historical_data): string {
        $current_sessions = $current_data['sessions'] ?? 0;
        $historical_sessions = $historical_data['sessions'] ?? 0;
        $change = $this->calculate_percentage_change($historical_sessions, $current_sessions);

        if (abs($change) < self::SIGNIFICANT_CHANGE_THRESHOLD) {
            return 'Traffic remained stable with minimal changes across key metrics.';
        }

        $direction = $change > 0 ? 'increased' : 'decreased';
        return sprintf('Traffic %s by %.1f%% compared to the previous period.', $direction, abs($change));
    }

    /**
     * Generate keyword trend summary
     *
     * @param array $search_console_data Search Console data
     * @return string Keyword trend summary
     */
    private function generate_keyword_trend_summary(array $search_console_data): string {
        $total_clicks = $this->extract_total_metrics($search_console_data, 'clicks')['total'];
        $average_position = $this->calculate_average_position($search_console_data)['average_position'];

        if ($total_clicks > 1000) {
            $performance = 'strong';
        } elseif ($total_clicks > 100) {
            $performance = 'moderate';
        } else {
            $performance = 'limited';
        }

        return sprintf('Keyword performance shows %s activity with %d total clicks and average position of %.1f.',
            $performance, $total_clicks, $average_position);
    }

    /**
     * Generate content trend summary
     *
     * @param array $analytics_data Analytics data
     * @param array $search_data Search Console data
     * @return string Content trend summary
     */
    private function generate_content_trend_summary(array $analytics_data, array $search_data): string {
        $top_pages = $analytics_data['top_pages']['pages'] ?? [];
        $page_count = count($top_pages);

        if ($page_count > 10) {
            return sprintf('Content performance is diverse with %d pages driving significant traffic.', $page_count);
        } elseif ($page_count > 5) {
            return sprintf('Content performance is concentrated among %d key pages.', $page_count);
        } else {
            return 'Content performance is limited to a few key pages, indicating opportunity for expansion.';
        }
    }

    /**
     * Identify gaining pages
     *
     * @param array $analytics_data Analytics data
     * @param array $search_data Search Console data
     * @return array Gaining pages analysis
     */
    private function identify_gaining_pages(array $analytics_data, array $search_data): array {
        $pages = $analytics_data['top_pages']['pages'] ?? [];
        $gaining_pages = [];

        foreach ($pages as $page) {
            if (($page['pageviews'] ?? 0) > 100) {
                $gaining_pages[] = [
                    'path' => $page['path'] ?? '',
                    'title' => $page['title'] ?? '',
                    'pageviews' => $page['pageviews'] ?? 0,
                    'sessions' => $page['sessions'] ?? 0
                ];
            }
        }

        return array_slice($gaining_pages, 0, 5);
    }

    /**
     * Identify losing pages
     *
     * @param array $analytics_data Analytics data
     * @param array $search_data Search Console data
     * @return array Losing pages analysis
     */
    private function identify_losing_pages(array $analytics_data, array $search_data): array {
        // For now, return empty array as we need historical comparison data
        // This would be enhanced with actual historical page performance data
        return [];
    }

    /**
     * Analyze device performance trends
     *
     * @param array $search_data Search Console data
     * @return array Device trends analysis
     */
    private function analyze_device_trends(array $search_data): array {
        $device_insights = $search_data['device_insights'] ?? [];
        $devices = $device_insights['devices'] ?? [];

        $mobile_performance = $devices['mobile'] ?? [];
        $desktop_performance = $devices['desktop'] ?? [];

        return [
            'mobile' => [
                'clicks' => $mobile_performance['clicks'] ?? 0,
                'impressions' => $mobile_performance['impressions'] ?? 0,
                'ctr' => $mobile_performance['ctr'] ?? 0
            ],
            'desktop' => [
                'clicks' => $desktop_performance['clicks'] ?? 0,
                'impressions' => $desktop_performance['impressions'] ?? 0,
                'ctr' => $desktop_performance['ctr'] ?? 0
            ],
            'mobile_dominance' => ($mobile_performance['clicks'] ?? 0) > ($desktop_performance['clicks'] ?? 0)
        ];
    }

    /**
     * Analyze content type performance
     *
     * @param array $analytics_data Analytics data
     * @return array Content type performance analysis
     */
    private function analyze_content_type_performance(array $analytics_data): array {
        $pages = $analytics_data['top_pages']['pages'] ?? [];
        $content_types = [
            'blog' => 0,
            'product' => 0,
            'page' => 0,
            'other' => 0
        ];

        foreach ($pages as $page) {
            $path = $page['path'] ?? '';
            $pageviews = $page['pageviews'] ?? 0;

            if (strpos($path, '/blog/') !== false || strpos($path, '/post/') !== false) {
                $content_types['blog'] += $pageviews;
            } elseif (strpos($path, '/product/') !== false || strpos($path, '/shop/') !== false) {
                $content_types['product'] += $pageviews;
            } elseif (strpos($path, '/page/') !== false) {
                $content_types['page'] += $pageviews;
            } else {
                $content_types['other'] += $pageviews;
            }
        }

        return $content_types;
    }

    /**
     * Analyze monthly patterns in historical data
     *
     * @param array $historical_data Historical data
     * @return array Monthly pattern analysis
     */
    private function analyze_monthly_patterns(array $historical_data): array {
        // Placeholder for monthly pattern analysis
        // Would analyze seasonal trends by month
        return [
            'peak_months' => [],
            'low_months' => [],
            'seasonal_factor' => 1.0
        ];
    }

    /**
     * Analyze weekly patterns in historical data
     *
     * @param array $historical_data Historical data
     * @return array Weekly pattern analysis
     */
    private function analyze_weekly_patterns(array $historical_data): array {
        // Placeholder for weekly pattern analysis
        // Would analyze day-of-week performance patterns
        return [
            'peak_days' => [],
            'low_days' => [],
            'weekend_factor' => 1.0
        ];
    }

    /**
     * Generate seasonal recommendations
     *
     * @param array $historical_data Historical data
     * @return array Seasonal recommendations
     */
    private function generate_seasonal_recommendations(array $historical_data): array {
        return [
            'content_timing' => 'Optimize content publishing for peak performance periods',
            'seasonal_content' => 'Prepare seasonal content in advance of peak periods',
            'resource_allocation' => 'Allocate marketing resources during high-performance periods'
        ];
    }
}
