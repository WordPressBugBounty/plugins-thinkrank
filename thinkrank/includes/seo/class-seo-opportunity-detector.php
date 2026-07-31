<?php
/**
 * SEO Opportunity Detector Class
 *
 * Automatically identifies and prioritizes actionable SEO opportunities from
 * Google API data. Detects quick wins, content gaps, and performance issues
 * to help users focus on high-impact optimization tasks.
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
 * SEO Opportunity Detector Class
 *
 * Single Responsibility: Detect and prioritize SEO optimization opportunities
 * Following ThinkRank detector patterns from existing codebase
 *
 * @since 1.0.0
 */
class SEO_Opportunity_Detector {

    /**
     * Quick win thresholds for opportunity detection
     *
     * @var array
     */
    private const QUICK_WIN_THRESHOLDS = [
        'position_range' => [4, 10], // Positions 4-10 for page 1 potential
        'min_impressions' => 100,    // Minimum impressions for consideration
        'min_clicks' => 5,           // Minimum clicks for relevance
        'ctr_improvement_potential' => 2.0 // Minimum CTR improvement potential (%)
    ];

    /**
     * Performance issue thresholds
     *
     * @var array
     */
    private const PERFORMANCE_THRESHOLDS = [
        'ranking_drop' => 5,         // Position drop threshold
        'traffic_decline' => 20,     // Traffic decline percentage
        'ctr_decline' => 15,         // CTR decline percentage
        'impression_decline' => 25   // Impression decline percentage
    ];

    /**
     * Content opportunity thresholds
     *
     * @var array
     */
    private const CONTENT_THRESHOLDS = [
        'keyword_gap_impressions' => 500,  // Minimum impressions for keyword gaps
        'content_refresh_age' => 180,      // Days since last update
        'low_engagement_bounce' => 70      // Bounce rate threshold
    ];

    /**
     * Detect quick win opportunities
     *
     * @param array $search_console_data Search Console data
     * @param array $analytics_data Analytics data
     * @return array Quick win opportunities
     */
    public function detect_quick_wins(array $search_console_data, array $analytics_data): array {
        $quick_wins = [];
        $rows = $search_console_data['rows'] ?? [];

        foreach ($rows as $row) {
            $keyword = $row['keys'][0] ?? '';
            $clicks = $row['clicks'] ?? 0;
            $impressions = $row['impressions'] ?? 0;
            $position = $row['position'] ?? 0;
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            $opportunity = $this->evaluate_quick_win_potential($keyword, $clicks, $impressions, $position, $ctr);
            
            if ($opportunity['is_quick_win']) {
                $quick_wins[] = $opportunity;
            }
        }

        // Sort by impact score descending
        usort($quick_wins, function($a, $b) {
            return $b['impact_score'] <=> $a['impact_score'];
        });

        return [
            'opportunities' => array_slice($quick_wins, 0, 15),
            'total_count' => count($quick_wins),
            'potential_additional_clicks' => array_sum(array_column($quick_wins, 'potential_clicks')),
            'summary' => $this->generate_quick_wins_summary($quick_wins)
        ];
    }

    /**
     * Identify content opportunities
     *
     * @param array $keyword_data Keyword performance data
     * @param array $content_data Content performance data
     * @return array Content opportunities
     */
    public function identify_content_opportunities(array $keyword_data, array $content_data): array {
        $opportunities = [
            'keyword_gaps' => $this->identify_keyword_gaps($keyword_data),
            'content_refresh' => $this->identify_content_refresh_opportunities($content_data),
            'seasonal_content' => $this->identify_seasonal_opportunities($keyword_data),
            'content_expansion' => $this->identify_content_expansion_opportunities($keyword_data, $content_data)
        ];

        return [
            'opportunities' => $opportunities,
            'priority_actions' => $this->prioritize_content_opportunities($opportunities),
            'summary' => $this->generate_content_opportunities_summary($opportunities)
        ];
    }

    /**
     * Find performance issues requiring attention
     *
     * @param array $trend_data Trend analysis data
     * @param array $thresholds Custom thresholds
     * @return array Performance issues
     */
    public function find_performance_issues(array $trend_data, array $thresholds = []): array {
        $thresholds = array_merge(self::PERFORMANCE_THRESHOLDS, $thresholds);
        $issues = [];

        // Analyze traffic performance issues
        $traffic_issues = $this->detect_traffic_issues($trend_data, $thresholds);
        if (!empty($traffic_issues)) {
            $issues['traffic'] = $traffic_issues;
        }

        // Analyze keyword performance issues
        $keyword_issues = $this->detect_keyword_issues($trend_data, $thresholds);
        if (!empty($keyword_issues)) {
            $issues['keywords'] = $keyword_issues;
        }

        // Analyze technical performance issues
        $technical_issues = $this->detect_technical_issues($trend_data, $thresholds);
        if (!empty($technical_issues)) {
            $issues['technical'] = $technical_issues;
        }

        return [
            'issues' => $issues,
            'severity_breakdown' => $this->categorize_issues_by_severity($issues),
            'recommended_actions' => $this->generate_issue_recommendations($issues),
            'summary' => $this->generate_performance_issues_summary($issues)
        ];
    }

    /**
     * Prioritize all opportunities using impact/effort matrix
     *
     * @param array $opportunities All detected opportunities
     * @return array Prioritized opportunities
     */
    public function prioritize_opportunities(array $opportunities): array {
        $prioritized = [];

        foreach ($opportunities as $category => $category_opportunities) {
            if (is_array($category_opportunities)) {
                foreach ($category_opportunities as $opportunity) {
                    if (!is_array($opportunity)) {
                        continue;
                    }
                    $priority_data = $this->calculate_priority_score($opportunity);
                    $prioritized[] = array_merge($opportunity, [
                        'category' => $category,
                        'priority_score' => $priority_data['score'],
                        'priority_level' => $priority_data['level'],
                        'estimated_effort' => $priority_data['effort'],
                        'estimated_impact' => $priority_data['impact']
                    ]);
                }
            }
        }

        // Sort by priority score descending
        usort($prioritized, function($a, $b) {
            return $b['priority_score'] <=> $a['priority_score'];
        });

        return [
            'prioritized_opportunities' => $prioritized,
            'high_priority_count' => count(array_filter($prioritized, function($opp) {
                return $opp['priority_level'] === 'high';
            })),
            'quick_wins_count' => count(array_filter($prioritized, function($opp) {
                return $opp['estimated_effort'] === 'low' && $opp['estimated_impact'] === 'high';
            }))
        ];
    }

    /**
     * Calculate impact/effort matrix for opportunities
     *
     * @param array $opportunities Opportunities to analyze
     * @return array Impact/effort matrix
     */
    public function calculate_impact_effort_matrix(array $opportunities): array {
        $matrix = [
            'high_impact_low_effort' => [],    // Quick wins
            'high_impact_high_effort' => [],   // Major projects
            'low_impact_low_effort' => [],     // Fill-ins
            'low_impact_high_effort' => []     // Avoid
        ];

        foreach ($opportunities as $opportunity) {
            $impact = $this->determine_impact_level($opportunity);
            $effort = $this->determine_effort_level($opportunity);
            
            $category = $impact . '_impact_' . $effort . '_effort';
            if (isset($matrix[$category])) {
                $matrix[$category][] = $opportunity;
            }
        }

        return [
            'matrix' => $matrix,
            'recommendations' => [
                'focus_on' => 'high_impact_low_effort', // Quick wins first
                'plan_for' => 'high_impact_high_effort', // Major projects second
                'consider' => 'low_impact_low_effort',   // Fill-ins when time permits
                'avoid' => 'low_impact_high_effort'      // Not worth the effort
            ],
            'summary' => $this->generate_matrix_summary($matrix)
        ];
    }

    /**
     * Evaluate quick win potential for a keyword
     *
     * @param string $keyword Keyword
     * @param int $clicks Current clicks
     * @param int $impressions Current impressions
     * @param float $position Current position
     * @param float $ctr Current CTR
     * @return array Quick win evaluation
     */
    private function evaluate_quick_win_potential(string $keyword, int $clicks, int $impressions, float $position, float $ctr): array {
        $thresholds = self::QUICK_WIN_THRESHOLDS;
        
        // Check if keyword meets quick win criteria
        $is_in_position_range = $position >= $thresholds['position_range'][0] && $position <= $thresholds['position_range'][1];
        $has_sufficient_impressions = $impressions >= $thresholds['min_impressions'];
        $has_sufficient_clicks = $clicks >= $thresholds['min_clicks'];
        
        // Calculate potential improvement
        $expected_ctr = $this->get_expected_ctr_for_position($position - 1); // If moved up 1 position
        $ctr_improvement_potential = max(0, $expected_ctr - $ctr);
        $has_ctr_potential = $ctr_improvement_potential >= $thresholds['ctr_improvement_potential'];
        
        $is_quick_win = $is_in_position_range && $has_sufficient_impressions && $has_sufficient_clicks && $has_ctr_potential;
        
        // Calculate impact score
        $impact_score = $this->calculate_quick_win_impact_score($impressions, $position, $ctr_improvement_potential);
        
        // Calculate potential additional clicks
        $potential_clicks = round($impressions * ($ctr_improvement_potential / 100));

        return [
            'keyword' => $keyword,
            'is_quick_win' => $is_quick_win,
            'current_position' => round($position, 1),
            'current_clicks' => $clicks,
            'current_impressions' => $impressions,
            'current_ctr' => round($ctr, 2),
            'potential_position' => round($position - 1, 1),
            'expected_ctr' => round($expected_ctr, 2),
            'ctr_improvement_potential' => round($ctr_improvement_potential, 2),
            'potential_clicks' => $potential_clicks,
            'impact_score' => $impact_score,
            'opportunity_type' => 'quick_win',
            'recommended_action' => $this->get_quick_win_recommendation($position, $ctr, $ctr_improvement_potential)
        ];
    }

    /**
     * Get expected CTR for a given position
     *
     * @param float $position Search position
     * @return float Expected CTR percentage
     */
    private function get_expected_ctr_for_position(float $position): float {
        // Industry benchmark CTRs by position
        $ctr_benchmarks = [
            1 => 31.7, 2 => 24.7, 3 => 18.7, 4 => 13.7, 5 => 9.5,
            6 => 6.8, 7 => 4.8, 8 => 3.5, 9 => 2.5, 10 => 2.2
        ];

        $pos = (int) round($position);
        
        if ($pos <= 10) {
            return $ctr_benchmarks[$pos] ?? 1.0;
        } elseif ($pos <= 20) {
            return 1.0;
        } else {
            return 0.5;
        }
    }

    /**
     * Calculate quick win impact score
     *
     * @param int $impressions Current impressions
     * @param float $position Current position
     * @param float $ctr_potential CTR improvement potential
     * @return float Impact score (0-100)
     */
    private function calculate_quick_win_impact_score(int $impressions, float $position, float $ctr_potential): float {
        // Weight factors
        $impression_weight = 0.4;
        $position_weight = 0.3;
        $ctr_weight = 0.3;

        // Normalize scores (0-100)
        $impression_score = min(100, ($impressions / 1000) * 100); // Max score at 1000 impressions
        $position_score = max(0, (11 - $position) * 10); // Better positions get higher scores
        $ctr_score = min(100, $ctr_potential * 10); // Max score at 10% CTR potential

        return ($impression_score * $impression_weight) +
               ($position_score * $position_weight) +
               ($ctr_score * $ctr_weight);
    }

    /**
     * Get quick win recommendation
     *
     * @param float $position Current position
     * @param float $ctr Current CTR
     * @param float $ctr_potential CTR improvement potential
     * @return string Recommendation
     */
    private function get_quick_win_recommendation(float $position, float $ctr, float $ctr_potential): string {
        if ($position <= 5 && $ctr_potential > 3) {
            return 'Optimize title and meta description to improve CTR for this high-ranking keyword';
        } elseif ($position <= 10 && $ctr_potential > 2) {
            return 'Focus on content optimization and internal linking to push to page 1';
        } else {
            return 'Improve content relevance and user engagement signals';
        }
    }

    /**
     * Generate quick wins summary
     *
     * @param array $quick_wins Quick win opportunities
     * @return string Summary
     */
    private function generate_quick_wins_summary(array $quick_wins): string {
        $count = count($quick_wins);
        $total_potential = array_sum(array_column($quick_wins, 'potential_clicks'));

        if ($count === 0) {
            return 'No immediate quick win opportunities detected. Focus on long-term SEO improvements.';
        }

        return sprintf(
            'Found %d quick win opportunities with potential for %d additional clicks per month.',
            $count,
            $total_potential
        );
    }

    /**
     * Identify keyword gaps
     *
     * @param array $keyword_data Keyword data
     * @return array Keyword gap opportunities
     */
    private function identify_keyword_gaps(array $keyword_data): array {
        $gaps = [];
        $rows = $keyword_data['rows'] ?? [];

        foreach ($rows as $row) {
            $keyword = $row['keys'][0] ?? '';
            $impressions = $row['impressions'] ?? 0;
            $clicks = $row['clicks'] ?? 0;
            $position = $row['position'] ?? 0;

            // Identify keywords with high impressions but poor performance
            if ($impressions >= self::CONTENT_THRESHOLDS['keyword_gap_impressions'] && $position > 20) {
                $gaps[] = [
                    'keyword' => $keyword,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'position' => round($position, 1),
                    'opportunity_type' => 'keyword_gap',
                    'recommended_action' => 'Create dedicated content targeting this high-volume keyword'
                ];
            }
        }

        return array_slice($gaps, 0, 10);
    }

    /**
     * Identify content refresh opportunities
     *
     * @param array $content_data Content performance data
     * @return array Content refresh opportunities
     */
    private function identify_content_refresh_opportunities(array $content_data): array {
        $refresh_opportunities = [];
        $pages = $content_data['top_pages']['pages'] ?? [];

        foreach ($pages as $page) {
            $path = $page['path'] ?? '';
            $pageviews = $page['pageviews'] ?? 0;

            // Identify pages with declining performance (would need historical data)
            // For now, identify pages with moderate traffic that could be improved
            if ($pageviews > 50 && $pageviews < 500) {
                $refresh_opportunities[] = [
                    'page_path' => $path,
                    'current_pageviews' => $pageviews,
                    'opportunity_type' => 'content_refresh',
                    'recommended_action' => 'Update content with fresh information and optimize for current keywords'
                ];
            }
        }

        return array_slice($refresh_opportunities, 0, 5);
    }

    /**
     * Identify seasonal opportunities
     *
     * @param array $keyword_data Keyword data
     * @return array Seasonal opportunities
     */
    private function identify_seasonal_opportunities(array $keyword_data): array {
        // Placeholder for seasonal analysis
        // Would analyze historical patterns to identify seasonal keywords
        return [
            [
                'opportunity_type' => 'seasonal_content',
                'season' => 'upcoming',
                'recommended_action' => 'Prepare seasonal content based on historical performance patterns'
            ]
        ];
    }

    /**
     * Identify content expansion opportunities
     *
     * @param array $keyword_data Keyword data
     * @param array $content_data Content data
     * @return array Content expansion opportunities
     */
    private function identify_content_expansion_opportunities(array $keyword_data, array $content_data): array {
        $expansion_opportunities = [];
        $rows = $keyword_data['rows'] ?? [];

        // Look for keyword clusters that could benefit from comprehensive content
        $keyword_clusters = $this->identify_keyword_clusters($rows);

        foreach ($keyword_clusters as $cluster) {
            if (count($cluster['keywords']) >= 3) {
                $expansion_opportunities[] = [
                    'cluster_topic' => $cluster['topic'],
                    'keyword_count' => count($cluster['keywords']),
                    'total_impressions' => $cluster['total_impressions'],
                    'opportunity_type' => 'content_expansion',
                    'recommended_action' => 'Create comprehensive content covering this keyword cluster'
                ];
            }
        }

        return array_slice($expansion_opportunities, 0, 3);
    }

    /**
     * Identify keyword clusters
     *
     * @param array $rows Keyword rows
     * @return array Keyword clusters
     */
    private function identify_keyword_clusters(array $rows): array {
        // Simplified clustering based on common words
        $clusters = [];

        foreach ($rows as $row) {
            $keyword = $row['keys'][0] ?? '';
            $impressions = $row['impressions'] ?? 0;

            // Extract main topic (first word for simplification)
            $words = explode(' ', $keyword);
            $main_topic = $words[0] ?? 'general';

            if (!isset($clusters[$main_topic])) {
                $clusters[$main_topic] = [
                    'topic' => $main_topic,
                    'keywords' => [],
                    'total_impressions' => 0
                ];
            }

            $clusters[$main_topic]['keywords'][] = $keyword;
            $clusters[$main_topic]['total_impressions'] += $impressions;
        }

        return array_values($clusters);
    }

    /**
     * Prioritize content opportunities
     *
     * @param array $opportunities Content opportunities
     * @return array Priority actions
     */
    private function prioritize_content_opportunities(array $opportunities): array {
        $priority_actions = [];

        // Prioritize keyword gaps (high impact)
        if (!empty($opportunities['keyword_gaps'])) {
            $priority_actions[] = [
                'action' => 'Address keyword gaps',
                'priority' => 'high',
                'count' => count($opportunities['keyword_gaps'])
            ];
        }

        // Content refresh (medium impact)
        if (!empty($opportunities['content_refresh'])) {
            $priority_actions[] = [
                'action' => 'Refresh existing content',
                'priority' => 'medium',
                'count' => count($opportunities['content_refresh'])
            ];
        }

        return $priority_actions;
    }

    /**
     * Generate content opportunities summary
     *
     * @param array $opportunities Content opportunities
     * @return string Summary
     */
    private function generate_content_opportunities_summary(array $opportunities): string {
        $total_opportunities = array_sum(array_map('count', array_filter($opportunities, 'is_array')));

        if ($total_opportunities === 0) {
            return 'No significant content opportunities detected. Current content strategy appears optimized.';
        }

        return sprintf(
            'Identified %d content opportunities across keyword gaps, content refresh, and expansion areas.',
            $total_opportunities
        );
    }

    /**
     * Detect traffic performance issues
     *
     * @param array $trend_data Trend data
     * @param array $thresholds Thresholds
     * @return array Traffic issues
     */
    private function detect_traffic_issues(array $trend_data, array $thresholds): array {
        $issues = [];
        $traffic_trends = $trend_data['sessions'] ?? [];

        if (isset($traffic_trends['percentage_change'])) {
            $change = $traffic_trends['percentage_change'];

            if ($change < -$thresholds['traffic_decline']) {
                $issues[] = [
                    'type' => 'traffic_decline',
                    'severity' => $change < -40 ? 'critical' : 'high',
                    'description' => sprintf('Traffic declined by %.1f%%', abs($change)),
                    'recommended_action' => 'Investigate traffic sources and optimize underperforming content'
                ];
            }
        }

        return $issues;
    }

    /**
     * Detect keyword performance issues
     *
     * @param array $trend_data Trend data
     * @param array $thresholds Thresholds
     * @return array Keyword issues
     */
    private function detect_keyword_issues(array $trend_data, array $thresholds): array {
        $issues = [];

        // This would analyze keyword trend data for declining performance
        // For now, return placeholder structure
        return $issues;
    }

    /**
     * Detect technical performance issues
     *
     * @param array $trend_data Trend data
     * @param array $thresholds Thresholds
     * @return array Technical issues
     */
    private function detect_technical_issues(array $trend_data, array $thresholds): array {
        $issues = [];

        // This would analyze Core Web Vitals and technical metrics
        // For now, return placeholder structure
        return $issues;
    }

    /**
     * Categorize issues by severity
     *
     * @param array $issues All issues
     * @return array Severity breakdown
     */
    private function categorize_issues_by_severity(array $issues): array {
        $severity_breakdown = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0
        ];

        foreach ($issues as $category => $category_issues) {
            foreach ($category_issues as $issue) {
                $severity = $issue['severity'] ?? 'medium';
                if (isset($severity_breakdown[$severity])) {
                    $severity_breakdown[$severity]++;
                }
            }
        }

        return $severity_breakdown;
    }

    /**
     * Generate issue recommendations
     *
     * @param array $issues All issues
     * @return array Recommendations
     */
    private function generate_issue_recommendations(array $issues): array {
        $recommendations = [];

        foreach ($issues as $category => $category_issues) {
            foreach ($category_issues as $issue) {
                if (isset($issue['recommended_action'])) {
                    $recommendations[] = [
                        'category' => $category,
                        'severity' => $issue['severity'] ?? 'medium',
                        'action' => $issue['recommended_action']
                    ];
                }
            }
        }

        // Sort by severity (critical first)
        $severity_order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($recommendations, function($a, $b) use ($severity_order) {
            return $severity_order[$a['severity']] <=> $severity_order[$b['severity']];
        });

        return $recommendations;
    }

    /**
     * Generate performance issues summary
     *
     * @param array $issues All issues
     * @return string Summary
     */
    private function generate_performance_issues_summary(array $issues): string {
        $total_issues = 0;
        foreach ($issues as $category_issues) {
            $total_issues += count($category_issues);
        }

        if ($total_issues === 0) {
            return 'No significant performance issues detected. SEO performance appears stable.';
        }

        return sprintf(
            'Detected %d performance issues requiring attention across traffic, keywords, and technical areas.',
            $total_issues
        );
    }

    /**
     * Calculate priority score for an opportunity
     *
     * @param array $opportunity Opportunity data
     * @return array Priority data
     */
    private function calculate_priority_score(array $opportunity): array {
        $impact = $this->determine_impact_level($opportunity);
        $effort = $this->determine_effort_level($opportunity);

        // Calculate numeric priority score
        $impact_score = $impact === 'high' ? 10 : 5;
        $effort_score = $effort === 'low' ? 10 : 5;
        $priority_score = $impact_score + $effort_score;

        // Determine priority level
        if ($impact === 'high' && $effort === 'low') {
            $level = 'high';
        } elseif ($impact === 'high' && $effort === 'high') {
            $level = 'medium';
        } else {
            $level = 'low';
        }

        return [
            'score' => $priority_score,
            'level' => $level,
            'impact' => $impact,
            'effort' => $effort
        ];
    }

    /**
     * Determine impact level
     *
     * @param array $opportunity Opportunity data
     * @return string Impact level
     */
    private function determine_impact_level(array $opportunity): string {
        $impact_score = $opportunity['impact_score'] ?? 0;
        $potential_clicks = $opportunity['potential_clicks'] ?? 0;
        $impressions = $opportunity['current_impressions'] ?? $opportunity['impressions'] ?? 0;

        if ($impact_score >= 70 || $potential_clicks >= 50 || $impressions >= 1000) {
            return 'high';
        } else {
            return 'low';
        }
    }

    /**
     * Determine effort level
     *
     * @param array $opportunity Opportunity data
     * @return string Effort level
     */
    private function determine_effort_level(array $opportunity): string {
        $position = $opportunity['current_position'] ?? $opportunity['position'] ?? 100;
        $opportunity_type = $opportunity['opportunity_type'] ?? '';

        // Quick wins and CTR optimizations are low effort
        if ($opportunity_type === 'quick_win' || $position <= 10) {
            return 'low';
        } else {
            return 'high';
        }
    }

    /**
     * Generate matrix summary
     *
     * @param array $matrix Impact/effort matrix
     * @return string Summary
     */
    private function generate_matrix_summary(array $matrix): string {
        $quick_wins = count($matrix['high_impact_low_effort']);
        $major_projects = count($matrix['high_impact_high_effort']);

        if ($quick_wins > 0) {
            return sprintf(
                'Focus on %d quick wins first, then plan for %d major projects.',
                $quick_wins,
                $major_projects
            );
        } elseif ($major_projects > 0) {
            return sprintf(
                'No quick wins available. Focus on planning %d major projects.',
                $major_projects
            );
        } else {
            return 'Limited optimization opportunities detected. Focus on content creation and link building.';
        }
    }
}
