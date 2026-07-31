<?php
/**
 * SEO Insight Generator Class
 *
 * Converts complex SEO data into clear, actionable insights and recommendations.
 * Generates human-readable intelligence from traffic, keyword, content, and
 * technical performance data to help users understand their SEO performance.
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
 * SEO Insight Generator Class
 *
 * Single Responsibility: Generate actionable SEO insights from data
 * Following ThinkRank insight patterns from existing codebase
 *
 * @since 1.0.0
 */
class SEO_Insight_Generator {

    /**
     * Insight significance thresholds
     *
     * @var array
     */
    private const SIGNIFICANCE_THRESHOLDS = [
        'high' => 20.0,    // 20%+ change is highly significant
        'medium' => 10.0,  // 10%+ change is moderately significant
        'low' => 5.0       // 5%+ change is somewhat significant
    ];

    /**
     * Performance benchmarks for context
     *
     * @var array
     */
    private const PERFORMANCE_BENCHMARKS = [
        'excellent_ctr' => 5.0,
        'good_ctr' => 3.0,
        'average_ctr' => 2.0,
        'excellent_position' => 3.0,
        'good_position' => 10.0,
        'page_one_position' => 10.0
    ];

    /**
     * Generate traffic intelligence insights
     *
     * @param array $trend_data Traffic trend data
     * @param array $context Additional context data
     * @return array Traffic insights
     */
    public function generate_traffic_insights(array $trend_data, array $context = []): array {
        $insights = [];

        // Analyze overall traffic trends
        $sessions_trend = $trend_data['sessions'] ?? [];
        if (!empty($sessions_trend)) {
            $insights[] = $this->create_traffic_trend_insight($sessions_trend);
        }

        // Analyze organic traffic specifically
        $organic_trend = $trend_data['organic_traffic'] ?? [];
        if (!empty($organic_trend)) {
            $insights[] = $this->create_organic_traffic_insight($organic_trend);
        }

        // Analyze engagement metrics
        $bounce_rate_trend = $trend_data['bounce_rate'] ?? [];
        if (!empty($bounce_rate_trend)) {
            $insights[] = $this->create_engagement_insight($bounce_rate_trend);
        }

        return [
            'insights' => array_filter($insights),
            'summary' => $this->generate_traffic_insights_summary($insights),
            'priority_actions' => $this->extract_priority_actions($insights)
        ];
    }

    /**
     * Generate keyword intelligence insights
     *
     * @param array $keyword_trends Keyword trend data
     * @param array $benchmarks Industry benchmarks
     * @return array Keyword insights
     */
    public function generate_keyword_insights(array $keyword_trends, array $benchmarks = []): array {
        $insights = [];

        // Analyze keyword performance trends
        if (!empty($keyword_trends['top_gaining_keywords'])) {
            $insights[] = $this->create_gaining_keywords_insight($keyword_trends['top_gaining_keywords']);
        }

        if (!empty($keyword_trends['top_losing_keywords'])) {
            $insights[] = $this->create_losing_keywords_insight($keyword_trends['top_losing_keywords']);
        }

        // Analyze overall keyword metrics
        $average_position = $keyword_trends['average_position'] ?? [];
        if (!empty($average_position)) {
            $insights[] = $this->create_position_performance_insight($average_position);
        }

        $average_ctr = $keyword_trends['average_ctr'] ?? [];
        if (!empty($average_ctr)) {
            $insights[] = $this->create_ctr_performance_insight($average_ctr);
        }

        return [
            'insights' => array_filter($insights),
            'summary' => $this->generate_keyword_insights_summary($insights),
            'optimization_opportunities' => $this->identify_keyword_optimization_opportunities($keyword_trends)
        ];
    }

    /**
     * Generate content performance intelligence
     *
     * @param array $content_performance Content performance data
     * @return array Content insights
     */
    public function generate_content_insights(array $content_performance): array {
        $insights = [];

        // Analyze top performing content
        $gaining_pages = $content_performance['top_gaining_pages'] ?? [];
        if (!empty($gaining_pages)) {
            $insights[] = $this->create_top_content_insight($gaining_pages);
        }

        // Analyze device performance
        $device_trends = $content_performance['mobile_vs_desktop'] ?? [];
        if (!empty($device_trends)) {
            $insights[] = $this->create_device_performance_insight($device_trends);
        }

        // Analyze content type performance
        $content_types = $content_performance['content_type_performance'] ?? [];
        if (!empty($content_types)) {
            $insights[] = $this->create_content_type_insight($content_types);
        }

        return [
            'insights' => array_filter($insights),
            'summary' => $this->generate_content_insights_summary($insights),
            'content_strategy_recommendations' => $this->generate_content_strategy_recommendations($content_performance)
        ];
    }

    /**
     * Generate technical SEO intelligence
     *
     * @param array $performance_data Technical performance data
     * @return array Technical insights
     */
    public function generate_technical_insights(array $performance_data): array {
        $insights = [];

        // Analyze Core Web Vitals
        $core_web_vitals = $performance_data['core_web_vitals'] ?? [];
        if (!empty($core_web_vitals)) {
            $insights[] = $this->create_core_web_vitals_insight($core_web_vitals);
        }

        // Analyze page speed trends
        if (isset($performance_data['page_speed_trends'])) {
            $insights[] = $this->create_page_speed_insight($performance_data['page_speed_trends']);
        }

        return [
            'insights' => array_filter($insights),
            'summary' => $this->generate_technical_insights_summary($insights),
            'technical_recommendations' => $this->generate_technical_recommendations($performance_data)
        ];
    }

    /**
     * Format insights for display
     *
     * @param array $insights Raw insights data
     * @return array Formatted insights
     */
    public function format_insights_for_display(array $insights): array {
        $formatted = [];

        foreach ($insights as $insight) {
            $formatted[] = [
                'id' => $this->generate_insight_id($insight),
                'type' => $insight['type'] ?? 'general',
                'title' => $insight['title'] ?? 'SEO Insight',
                'description' => $insight['description'] ?? '',
                'impact' => $insight['impact'] ?? 'medium',
                'confidence' => $insight['confidence'] ?? 0.8,
                'action_required' => $insight['action_required'] ?? false,
                'recommended_action' => $insight['recommended_action'] ?? '',
                'data_points' => $insight['data_points'] ?? [],
                'trend_direction' => $insight['trend_direction'] ?? 'stable',
                'significance' => $insight['significance'] ?? 'medium',
                'timestamp' => current_time('mysql')
            ];
        }

        return $formatted;
    }

    /**
     * Prioritize insights by impact
     *
     * @param array $insights All insights
     * @return array Prioritized insights
     */
    public function prioritize_insights_by_impact(array $insights): array {
        // Define impact priority order
        $impact_priority = ['high' => 3, 'medium' => 2, 'low' => 1];

        usort($insights, function($a, $b) use ($impact_priority) {
            $impact_a = $impact_priority[$a['impact'] ?? 'medium'] ?? 2;
            $impact_b = $impact_priority[$b['impact'] ?? 'medium'] ?? 2;
            
            if ($impact_a === $impact_b) {
                // Secondary sort by confidence
                $confidence_a = $a['confidence'] ?? 0.5;
                $confidence_b = $b['confidence'] ?? 0.5;
                return $confidence_b <=> $confidence_a;
            }
            
            return $impact_b <=> $impact_a;
        });

        return [
            'prioritized_insights' => $insights,
            'high_impact_count' => count(array_filter($insights, function($insight) {
                return ($insight['impact'] ?? 'medium') === 'high';
            })),
            'action_required_count' => count(array_filter($insights, function($insight) {
                return $insight['action_required'] ?? false;
            }))
        ];
    }

    /**
     * Create traffic trend insight
     *
     * @param array $sessions_trend Sessions trend data
     * @return array Traffic trend insight
     */
    private function create_traffic_trend_insight(array $sessions_trend): array {
        $change = $sessions_trend['percentage_change'] ?? 0;
        $direction = $sessions_trend['trend_direction'] ?? 'stable';
        $current = $sessions_trend['current'] ?? 0;
        $previous = $sessions_trend['previous'] ?? 0;

        $significance = $this->determine_significance(abs($change));
        $impact = $significance === 'high' ? 'high' : 'medium';

        if ($direction === 'up') {
            $title = 'Traffic Growth Detected';
            $description = sprintf(
                'Website traffic increased by %.1f%% (%d to %d sessions), indicating positive SEO momentum.',
                abs($change),
                (int) $previous,
                (int) $current
            );
            $action_required = false;
            $recommended_action = 'Continue current SEO strategies and monitor for sustained growth.';
        } elseif ($direction === 'down') {
            $title = 'Traffic Decline Alert';
            $description = sprintf(
                'Website traffic decreased by %.1f%% (%d to %d sessions), requiring investigation.',
                abs($change),
                (int) $previous,
                (int) $current
            );
            $action_required = true;
            $recommended_action = 'Investigate traffic sources and optimize underperforming content.';
        } else {
            $title = 'Stable Traffic Performance';
            $description = sprintf(
                'Website traffic remained stable with %.1f%% change (%d sessions).',
                $change,
                (int) $current
            );
            $action_required = false;
            $recommended_action = 'Focus on growth strategies to increase traffic volume.';
        }

        return [
            'type' => 'traffic_trend',
            'title' => $title,
            'description' => $description,
            'impact' => $impact,
            'confidence' => 0.9,
            'action_required' => $action_required,
            'recommended_action' => $recommended_action,
            'trend_direction' => $direction,
            'significance' => $significance,
            'data_points' => [
                'current_sessions' => (int) $current,
                'previous_sessions' => (int) $previous,
                'percentage_change' => round($change, 1)
            ]
        ];
    }

    /**
     * Create organic traffic insight
     *
     * @param array $organic_trend Organic traffic trend data
     * @return array Organic traffic insight
     */
    private function create_organic_traffic_insight(array $organic_trend): array {
        $change = $organic_trend['percentage_change'] ?? 0;
        $current = $organic_trend['current'] ?? 0;
        $significance = $this->determine_significance(abs($change));

        return [
            'type' => 'organic_traffic',
            'title' => 'Organic Traffic Performance',
            'description' => sprintf(
                'Organic search traffic %s by %.1f%%, representing %d sessions from search engines.',
                $change > 0 ? 'increased' : 'decreased',
                abs($change),
                (int) $current
            ),
            'impact' => $significance === 'high' ? 'high' : 'medium',
            'confidence' => 0.85,
            'trend_direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
            'significance' => $significance
        ];
    }

    /**
     * Create engagement insight
     *
     * @param array $bounce_rate_trend Bounce rate trend data
     * @return array Engagement insight
     */
    private function create_engagement_insight(array $bounce_rate_trend): array {
        $change = $bounce_rate_trend['percentage_change'] ?? 0;
        $current = $bounce_rate_trend['current'] ?? 0;

        // For bounce rate, lower is better
        $is_improving = $change < 0;
        $title = $is_improving ? 'User Engagement Improving' : 'User Engagement Declining';

        return [
            'type' => 'engagement',
            'title' => $title,
            'description' => sprintf(
                'Bounce rate %s by %.1f%% to %.1f%%, %s user engagement quality.',
                $change > 0 ? 'increased' : 'decreased',
                abs($change),
                $current,
                $is_improving ? 'indicating improved' : 'suggesting declining'
            ),
            'impact' => 'medium',
            'confidence' => 0.8,
            'action_required' => !$is_improving && abs($change) > 10,
            'recommended_action' => $is_improving ?
                'Continue optimizing content for user engagement.' :
                'Improve content quality and page loading speed to reduce bounce rate.'
        ];
    }

    /**
     * Create gaining keywords insight
     *
     * @param array $gaining_keywords Top gaining keywords
     * @return array Gaining keywords insight
     */
    private function create_gaining_keywords_insight(array $gaining_keywords): array {
        $count = count($gaining_keywords);
        $top_keyword = $gaining_keywords[0] ?? [];

        return [
            'type' => 'keyword_gains',
            'title' => 'Keyword Performance Gains',
            'description' => sprintf(
                '%d keywords showing strong performance, led by "%s" with %d clicks and position %.1f.',
                $count,
                $top_keyword['keyword'] ?? 'N/A',
                $top_keyword['clicks'] ?? 0,
                $top_keyword['position'] ?? 0
            ),
            'impact' => 'high',
            'confidence' => 0.9,
            'action_required' => false,
            'recommended_action' => 'Optimize content around these performing keywords to maintain momentum.'
        ];
    }

    /**
     * Create losing keywords insight
     *
     * @param array $losing_keywords Top losing keywords
     * @return array Losing keywords insight
     */
    private function create_losing_keywords_insight(array $losing_keywords): array {
        $count = count($losing_keywords);
        $worst_keyword = $losing_keywords[0] ?? [];

        return [
            'type' => 'keyword_losses',
            'title' => 'Keyword Performance Issues',
            'description' => sprintf(
                '%d keywords underperforming, including "%s" at position %.1f with %d impressions but only %d clicks.',
                $count,
                $worst_keyword['keyword'] ?? 'N/A',
                $worst_keyword['position'] ?? 0,
                $worst_keyword['impressions'] ?? 0,
                $worst_keyword['clicks'] ?? 0
            ),
            'impact' => 'high',
            'confidence' => 0.85,
            'action_required' => true,
            'recommended_action' => 'Optimize content and meta descriptions for these underperforming keywords.'
        ];
    }

    /**
     * Create position performance insight
     *
     * @param array $average_position Average position data
     * @return array Position insight
     */
    private function create_position_performance_insight(array $average_position): array {
        $position = $average_position['average_position'] ?? 0;
        $keyword_count = $average_position['keywords_tracked'] ?? 0;

        if ($position <= self::PERFORMANCE_BENCHMARKS['excellent_position']) {
            $performance = 'excellent';
            $impact = 'low'; // Already performing well
        } elseif ($position <= self::PERFORMANCE_BENCHMARKS['good_position']) {
            $performance = 'good';
            $impact = 'medium';
        } else {
            $performance = 'needs improvement';
            $impact = 'high';
        }

        return [
            'type' => 'position_performance',
            'title' => 'Search Position Analysis',
            'description' => sprintf(
                'Average search position is %.1f across %d tracked keywords, indicating %s ranking performance.',
                $position,
                $keyword_count,
                $performance
            ),
            'impact' => $impact,
            'confidence' => 0.8,
            'action_required' => $performance === 'needs improvement',
            'recommended_action' => $performance === 'needs improvement' ?
                'Focus on improving content quality and building authority for better rankings.' :
                'Maintain current SEO strategies to preserve ranking positions.'
        ];
    }

    /**
     * Create CTR performance insight
     *
     * @param array $average_ctr Average CTR data
     * @return array CTR insight
     */
    private function create_ctr_performance_insight(array $average_ctr): array {
        $ctr = $average_ctr['average_ctr'] ?? 0;
        $clicks = $average_ctr['total_clicks'] ?? 0;
        $impressions = $average_ctr['total_impressions'] ?? 0;

        if ($ctr >= self::PERFORMANCE_BENCHMARKS['excellent_ctr']) {
            $performance = 'excellent';
            $impact = 'low';
        } elseif ($ctr >= self::PERFORMANCE_BENCHMARKS['good_ctr']) {
            $performance = 'good';
            $impact = 'medium';
        } else {
            $performance = 'needs improvement';
            $impact = 'high';
        }

        return [
            'type' => 'ctr_performance',
            'title' => 'Click-Through Rate Analysis',
            'description' => sprintf(
                'Average CTR is %.2f%% (%d clicks from %d impressions), indicating %s title and meta optimization.',
                $ctr,
                $clicks,
                $impressions,
                $performance
            ),
            'impact' => $impact,
            'confidence' => 0.85,
            'action_required' => $performance === 'needs improvement',
            'recommended_action' => $performance === 'needs improvement' ?
                'Optimize titles and meta descriptions to improve click-through rates.' :
                'Continue monitoring CTR performance and test title variations.'
        ];
    }

    /**
     * Determine significance level
     *
     * @param float $change Percentage change
     * @return string Significance level
     */
    private function determine_significance(float $change): string {
        if ($change >= self::SIGNIFICANCE_THRESHOLDS['high']) {
            return 'high';
        } elseif ($change >= self::SIGNIFICANCE_THRESHOLDS['medium']) {
            return 'medium';
        } elseif ($change >= self::SIGNIFICANCE_THRESHOLDS['low']) {
            return 'low';
        } else {
            return 'minimal';
        }
    }

    /**
     * Generate insight ID
     *
     * @param array $insight Insight data
     * @return string Unique insight ID
     */
    private function generate_insight_id(array $insight): string {
        $type = $insight['type'] ?? 'general';
        $timestamp = time();
        return sprintf('%s_%s', $type, $timestamp);
    }

    /**
     * Generate traffic insights summary
     *
     * @param array $insights Traffic insights
     * @return string Summary
     */
    private function generate_traffic_insights_summary(array $insights): string {
        $count = count($insights);
        $action_required = count(array_filter($insights, function($insight) {
            return $insight['action_required'] ?? false;
        }));

        if ($action_required > 0) {
            return sprintf('Generated %d traffic insights, %d requiring immediate attention.', $count, $action_required);
        } else {
            return sprintf('Generated %d traffic insights showing stable performance.', $count);
        }
    }

    /**
     * Extract priority actions from insights
     *
     * @param array $insights All insights
     * @return array Priority actions
     */
    private function extract_priority_actions(array $insights): array {
        $actions = [];

        foreach ($insights as $insight) {
            if ($insight['action_required'] ?? false) {
                $actions[] = [
                    'action' => $insight['recommended_action'] ?? 'Review performance data',
                    'priority' => $insight['impact'] ?? 'medium',
                    'type' => $insight['type'] ?? 'general'
                ];
            }
        }

        return $actions;
    }

    /**
     * Generate placeholder methods for remaining functionality
     */
    private function generate_keyword_insights_summary(array $insights): string {
        return sprintf('Generated %d keyword insights.', count($insights));
    }

    private function identify_keyword_optimization_opportunities(array $keyword_trends): array {
        return ['opportunities' => []];
    }

    private function create_top_content_insight(array $gaining_pages): array {
        return ['type' => 'content_performance', 'title' => 'Top Content Performance'];
    }

    private function create_device_performance_insight(array $device_trends): array {
        return ['type' => 'device_performance', 'title' => 'Device Performance Analysis'];
    }

    private function create_content_type_insight(array $content_types): array {
        return ['type' => 'content_type', 'title' => 'Content Type Performance'];
    }

    private function generate_content_insights_summary(array $insights): string {
        return sprintf('Generated %d content insights.', count($insights));
    }

    private function generate_content_strategy_recommendations(array $content_performance): array {
        return ['recommendations' => []];
    }

    private function create_core_web_vitals_insight(array $core_web_vitals): array {
        return ['type' => 'core_web_vitals', 'title' => 'Core Web Vitals Analysis'];
    }

    private function create_page_speed_insight(array $page_speed_trends): array {
        return ['type' => 'page_speed', 'title' => 'Page Speed Performance'];
    }

    private function generate_technical_insights_summary(array $insights): string {
        return sprintf('Generated %d technical insights.', count($insights));
    }

    private function generate_technical_recommendations(array $performance_data): array {
        return ['recommendations' => []];
    }
}
