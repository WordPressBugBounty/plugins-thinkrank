<?php
/**
 * SEO Scoring Engine Class
 *
 * Calculates SEO health scores and performance ratings based on Google API data.
 * Provides quantified SEO assessment for overall site health, page performance,
 * and keyword opportunities with industry benchmarks.
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
 * SEO Scoring Engine Class
 *
 * Single Responsibility: Calculate SEO performance scores and ratings
 * Following ThinkRank scoring patterns from existing SEO_Score_Calculator
 *
 * @since 1.0.0
 */
class SEO_Scoring_Engine {

    /**
     * Maximum SEO health score
     *
     * @var int
     */
    private const MAX_HEALTH_SCORE = 100;

    /**
     * Score weight distribution for SEO health calculation
     *
     * @var array
     */
    private const HEALTH_SCORE_WEIGHTS = [
        'traffic_growth' => 25,
        'keyword_performance' => 25,
        'content_engagement' => 25,
        'technical_performance' => 25
    ];

    /**
     * Industry benchmark CTR values by position
     *
     * @var array
     */
    private const CTR_BENCHMARKS = [
        1 => 31.7,
        2 => 24.7,
        3 => 18.7,
        4 => 13.7,
        5 => 9.5,
        6 => 6.8,
        7 => 4.8,
        8 => 3.5,
        9 => 2.5,
        10 => 2.2
    ];

    /**
     * Calculate overall SEO health score
     *
     * @param array $analytics_data Google Analytics data
     * @param array $search_data Search Console data
     * @return array SEO health score analysis
     */
    public function calculate_seo_health_score(array $analytics_data, array $search_data): array {
        $scores = [
            'traffic_growth' => $this->score_traffic_growth($analytics_data),
            'keyword_performance' => $this->score_keyword_performance($search_data),
            'content_engagement' => $this->score_content_engagement($analytics_data),
            'technical_performance' => $this->score_technical_performance($analytics_data)
        ];

        $overall_score = $this->calculate_weighted_score($scores, self::HEALTH_SCORE_WEIGHTS);
        $grade = $this->determine_grade($overall_score);

        return [
            'overall_score' => $overall_score,
            'grade' => $grade,
            'component_scores' => $scores,
            'interpretation' => $this->generate_score_interpretation($overall_score, $grade),
            'recommendations' => $this->generate_score_recommendations($scores),
            'last_calculated' => current_time('mysql')
        ];
    }

    /**
     * Score individual page performance
     *
     * @param array $page_data Page performance data
     * @param array $benchmarks Industry benchmarks
     * @return array Page performance score
     */
    public function score_page_performance(array $page_data, array $benchmarks): array {
        $scores = [
            'traffic_contribution' => $this->score_traffic_contribution($page_data),
            'search_visibility' => $this->score_search_visibility($page_data),
            'engagement_quality' => $this->score_engagement_quality($page_data),
            'technical_health' => $this->score_technical_health($page_data)
        ];

        $overall_score = array_sum($scores) / count($scores);
        $grade = $this->determine_grade($overall_score);

        return [
            'overall_score' => round($overall_score, 1),
            'grade' => $grade,
            'component_scores' => $scores,
            'page_path' => $page_data['path'] ?? '',
            'recommendations' => $this->generate_page_recommendations($scores, $page_data)
        ];
    }

    /**
     * Score keyword opportunities
     *
     * @param array $keyword_data Keyword performance data
     * @return array Keyword opportunity scores
     */
    public function score_keyword_opportunities(array $keyword_data): array {
        $opportunities = [];
        $rows = $keyword_data['rows'] ?? [];

        foreach ($rows as $row) {
            $keyword = $row['keys'][0] ?? '';
            $clicks = $row['clicks'] ?? 0;
            $impressions = $row['impressions'] ?? 0;
            $position = $row['position'] ?? 0;
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            $opportunity_score = $this->calculate_keyword_opportunity_score($clicks, $impressions, $position, $ctr);

            if ($opportunity_score > 0) {
                $opportunities[] = [
                    'keyword' => $keyword,
                    'opportunity_score' => $opportunity_score,
                    'current_position' => round($position, 1),
                    'current_ctr' => round($ctr, 2),
                    'expected_ctr' => $this->get_expected_ctr($position),
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'potential_impact' => $this->calculate_potential_impact($impressions, $position, $ctr)
                ];
            }
        }

        // Sort by opportunity score descending
        usort($opportunities, function($a, $b) {
            return $b['opportunity_score'] <=> $a['opportunity_score'];
        });

        return [
            'opportunities' => array_slice($opportunities, 0, 20),
            'total_opportunities' => count($opportunities),
            'high_impact_count' => count(array_filter($opportunities, function($opp) {
                return $opp['opportunity_score'] >= 70;
            }))
        ];
    }

    /**
     * Generate priority matrix for opportunities
     *
     * @param array $scores Various performance scores
     * @return array Priority matrix
     */
    public function generate_priority_matrix(array $scores): array {
        $matrix = [
            'high_impact_low_effort' => [],
            'high_impact_high_effort' => [],
            'low_impact_low_effort' => [],
            'low_impact_high_effort' => []
        ];

        foreach ($scores as $item) {
            $impact = $this->determine_impact_level($item);
            $effort = $this->determine_effort_level($item);

            $category = $impact . '_impact_' . $effort . '_effort';
            if (isset($matrix[$category])) {
                $matrix[$category][] = $item;
            }
        }

        return $matrix;
    }

    /**
     * Get industry benchmarks for comparison
     *
     * @param string $industry Industry type
     * @return array Industry benchmarks
     */
    public function get_industry_benchmarks(string $industry = 'general'): array {
        // Default benchmarks - could be expanded with industry-specific data
        return [
            'average_ctr' => 2.5,
            'average_position' => 15.0,
            'bounce_rate_threshold' => 70.0,
            'session_duration_threshold' => 120, // seconds
            'pages_per_session_threshold' => 2.0,
            'core_web_vitals' => [
                'lcp_threshold' => 2.5, // seconds
                'fid_threshold' => 100, // milliseconds
                'cls_threshold' => 0.1
            ]
        ];
    }

    /**
     * Score traffic growth component
     *
     * @param array $analytics_data Analytics data
     * @return float Traffic growth score
     */
    private function score_traffic_growth(array $analytics_data): float {
        $traffic = $analytics_data['traffic'] ?? [];
        $organic_traffic = $analytics_data['organic_traffic'] ?? [];

        $sessions = $traffic['sessions'] ?? 0;
        $organic_sessions = $organic_traffic['organic_traffic']['sessions'] ?? 0;

        // Score based on organic traffic percentage and absolute numbers
        $organic_percentage = $sessions > 0 ? ($organic_sessions / $sessions) * 100 : 0;

        if ($organic_percentage >= 60) {
            return 25.0; // Excellent organic traffic share
        } elseif ($organic_percentage >= 40) {
            return 20.0; // Good organic traffic share
        } elseif ($organic_percentage >= 20) {
            return 15.0; // Fair organic traffic share
        } else {
            return 10.0; // Poor organic traffic share
        }
    }

    /**
     * Score keyword performance component
     *
     * @param array $search_data Search Console data
     * @return float Keyword performance score
     */
    private function score_keyword_performance(array $search_data): float {
        $search_performance = $search_data['search_performance'] ?? [];
        $rows = $search_performance['rows'] ?? [];

        if (empty($rows)) {
            return 0.0;
        }

        $total_clicks = 0;
        $total_impressions = 0;
        $position_sum = 0;
        $keyword_count = 0;

        foreach ($rows as $row) {
            $total_clicks += $row['clicks'] ?? 0;
            $total_impressions += $row['impressions'] ?? 0;
            $position_sum += $row['position'] ?? 0;
            $keyword_count++;
        }

        $average_position = $keyword_count > 0 ? $position_sum / $keyword_count : 0;
        $overall_ctr = $total_impressions > 0 ? ($total_clicks / $total_impressions) * 100 : 0;

        // Score based on average position and CTR
        $position_score = $this->score_average_position($average_position);
        $ctr_score = $this->score_ctr_performance($overall_ctr);

        return ($position_score + $ctr_score) / 2;
    }

    /**
     * Score content engagement component
     *
     * @param array $analytics_data Analytics data
     * @return float Content engagement score
     */
    private function score_content_engagement(array $analytics_data): float {
        $traffic = $analytics_data['traffic'] ?? [];
        
        $bounce_rate = $traffic['bounce_rate'] ?? 0;
        $avg_session_duration = $traffic['avg_session_duration'] ?? 0;

        // Score based on engagement metrics (lower bounce rate and higher session duration is better)
        $bounce_score = $this->score_bounce_rate($bounce_rate);
        $duration_score = $this->score_session_duration($avg_session_duration);

        return ($bounce_score + $duration_score) / 2;
    }

    /**
     * Score technical performance component
     *
     * @param array $analytics_data Analytics data
     * @return float Technical performance score
     */
    private function score_technical_performance(array $analytics_data): float {
        $core_web_vitals = $analytics_data['core_web_vitals'] ?? [];

        if (empty($core_web_vitals)) {
            return 15.0; // Default score when no Core Web Vitals data available
        }

        // Score based on Core Web Vitals metrics
        $lcp_score = $this->score_lcp($core_web_vitals['lcp'] ?? 0);
        $fid_score = $this->score_fid($core_web_vitals['fid'] ?? 0);
        $cls_score = $this->score_cls($core_web_vitals['cls'] ?? 0);

        return ($lcp_score + $fid_score + $cls_score) / 3;
    }

    /**
     * Calculate weighted score from component scores
     *
     * @param array $scores Component scores
     * @param array $weights Score weights
     * @return float Weighted overall score
     */
    private function calculate_weighted_score(array $scores, array $weights): float {
        $total_score = 0;
        $total_weight = 0;

        foreach ($scores as $component => $score) {
            $weight = $weights[$component] ?? 0;
            $total_score += $score * ($weight / 100);
            $total_weight += $weight;
        }

        return $total_weight > 0 ? ($total_score / $total_weight) * 100 : 0;
    }

    /**
     * Determine grade from score
     *
     * @param float $score Numeric score
     * @return string Letter grade
     */
    private function determine_grade(float $score): string {
        if ($score >= 90) {
            return 'A+';
        } elseif ($score >= 80) {
            return 'A';
        } elseif ($score >= 70) {
            return 'B';
        } elseif ($score >= 60) {
            return 'C';
        } elseif ($score >= 50) {
            return 'D';
        } else {
            return 'F';
        }
    }

    /**
     * Generate score interpretation
     *
     * @param float $score Overall score
     * @param string $grade Letter grade
     * @return string Score interpretation
     */
    private function generate_score_interpretation(float $score, string $grade): string {
        switch ($grade) {
            case 'A+':
                return 'Excellent SEO performance with strong metrics across all areas.';
            case 'A':
                return 'Very good SEO performance with minor areas for improvement.';
            case 'B':
                return 'Good SEO performance with some optimization opportunities.';
            case 'C':
                return 'Fair SEO performance with several areas needing attention.';
            case 'D':
                return 'Poor SEO performance requiring significant improvements.';
            case 'F':
                return 'Critical SEO issues requiring immediate attention.';
            default:
                return sprintf('SEO performance score: %.1f/100', $score);
        }
    }

    /**
     * Generate score-based recommendations
     *
     * @param array $scores Component scores
     * @return array Recommendations
     */
    private function generate_score_recommendations(array $scores): array {
        $recommendations = [];

        foreach ($scores as $component => $score) {
            if ($score < 15) {
                $recommendations[] = $this->get_component_recommendation($component, 'critical');
            } elseif ($score < 20) {
                $recommendations[] = $this->get_component_recommendation($component, 'high');
            } elseif ($score < 22) {
                $recommendations[] = $this->get_component_recommendation($component, 'medium');
            }
        }

        return $recommendations;
    }

    /**
     * Get component-specific recommendation
     *
     * @param string $component Component name
     * @param string $priority Priority level
     * @return array Recommendation
     */
    private function get_component_recommendation(string $component, string $priority): array {
        $recommendations = [
            'traffic_growth' => [
                'critical' => 'Focus on organic traffic growth through content optimization and keyword targeting.',
                'high' => 'Improve organic traffic share by optimizing existing content and building quality backlinks.',
                'medium' => 'Continue growing organic traffic through consistent content creation and SEO optimization.'
            ],
            'keyword_performance' => [
                'critical' => 'Urgent keyword optimization needed - focus on improving rankings for target keywords.',
                'high' => 'Optimize keyword targeting and improve content relevance for better rankings.',
                'medium' => 'Fine-tune keyword strategy and monitor ranking improvements.'
            ],
            'content_engagement' => [
                'critical' => 'Critical engagement issues - review content quality and user experience immediately.',
                'high' => 'Improve content engagement through better formatting, internal linking, and user experience.',
                'medium' => 'Enhance content engagement with multimedia elements and improved readability.'
            ],
            'technical_performance' => [
                'critical' => 'Critical technical issues affecting SEO - address Core Web Vitals and site speed immediately.',
                'high' => 'Improve technical SEO by optimizing page speed and Core Web Vitals.',
                'medium' => 'Fine-tune technical performance for better user experience and SEO.'
            ]
        ];

        return [
            'component' => $component,
            'priority' => $priority,
            'recommendation' => $recommendations[$component][$priority] ?? 'Optimize this component for better SEO performance.'
        ];
    }

    /**
     * Calculate keyword opportunity score
     *
     * @param int $clicks Current clicks
     * @param int $impressions Current impressions
     * @param float $position Current position
     * @param float $ctr Current CTR
     * @return float Opportunity score (0-100)
     */
    private function calculate_keyword_opportunity_score(int $clicks, int $impressions, float $position, float $ctr): float {
        // No opportunity if no impressions
        if ($impressions < 10) {
            return 0;
        }

        $expected_ctr = $this->get_expected_ctr($position);
        $ctr_gap = max(0, $expected_ctr - $ctr);

        // Higher score for keywords with:
        // 1. High impressions (more potential)
        // 2. Position 4-10 (page 1 potential)
        // 3. CTR below expected (optimization opportunity)

        $impression_score = min(40, $impressions / 100); // Max 40 points for impressions
        $position_score = $position <= 10 ? (11 - $position) * 3 : 0; // Max 30 points for position
        $ctr_opportunity_score = min(30, $ctr_gap * 10); // Max 30 points for CTR gap

        return min(100, $impression_score + $position_score + $ctr_opportunity_score);
    }

    /**
     * Get expected CTR for position
     *
     * @param float $position Search position
     * @return float Expected CTR percentage
     */
    private function get_expected_ctr(float $position): float {
        $pos = (int) round($position);

        if ($pos <= 10) {
            return self::CTR_BENCHMARKS[$pos] ?? 1.0;
        } elseif ($pos <= 20) {
            return 1.0;
        } else {
            return 0.5;
        }
    }

    /**
     * Calculate potential impact of optimization
     *
     * @param int $impressions Current impressions
     * @param float $position Current position
     * @param float $current_ctr Current CTR
     * @return array Potential impact analysis
     */
    private function calculate_potential_impact(int $impressions, float $position, float $current_ctr): array {
        $expected_ctr = $this->get_expected_ctr($position);
        $potential_additional_clicks = $impressions * (($expected_ctr - $current_ctr) / 100);

        return [
            'additional_clicks_potential' => max(0, round($potential_additional_clicks)),
            'ctr_improvement_potential' => max(0, round($expected_ctr - $current_ctr, 2)),
            'impact_level' => $potential_additional_clicks > 50 ? 'high' : ($potential_additional_clicks > 10 ? 'medium' : 'low')
        ];
    }

    /**
     * Score average position performance
     *
     * @param float $average_position Average search position
     * @return float Position score
     */
    private function score_average_position(float $average_position): float {
        if ($average_position <= 3) {
            return 12.5; // Excellent - top 3 positions
        } elseif ($average_position <= 10) {
            return 10.0; // Good - page 1
        } elseif ($average_position <= 20) {
            return 7.5; // Fair - page 2
        } else {
            return 5.0; // Poor - beyond page 2
        }
    }

    /**
     * Score CTR performance
     *
     * @param float $ctr Click-through rate percentage
     * @return float CTR score
     */
    private function score_ctr_performance(float $ctr): float {
        if ($ctr >= 5.0) {
            return 12.5; // Excellent CTR
        } elseif ($ctr >= 3.0) {
            return 10.0; // Good CTR
        } elseif ($ctr >= 1.5) {
            return 7.5; // Fair CTR
        } else {
            return 5.0; // Poor CTR
        }
    }

    /**
     * Score bounce rate performance
     *
     * @param float $bounce_rate Bounce rate percentage
     * @return float Bounce rate score
     */
    private function score_bounce_rate(float $bounce_rate): float {
        if ($bounce_rate <= 40) {
            return 12.5; // Excellent engagement
        } elseif ($bounce_rate <= 55) {
            return 10.0; // Good engagement
        } elseif ($bounce_rate <= 70) {
            return 7.5; // Fair engagement
        } else {
            return 5.0; // Poor engagement
        }
    }

    /**
     * Score session duration performance
     *
     * @param float $duration Average session duration in seconds
     * @return float Duration score
     */
    private function score_session_duration(float $duration): float {
        if ($duration >= 180) { // 3+ minutes
            return 12.5; // Excellent engagement
        } elseif ($duration >= 120) { // 2+ minutes
            return 10.0; // Good engagement
        } elseif ($duration >= 60) { // 1+ minute
            return 7.5; // Fair engagement
        } else {
            return 5.0; // Poor engagement
        }
    }

    /**
     * Score Largest Contentful Paint (LCP)
     *
     * @param float $lcp LCP value in seconds
     * @return float LCP score
     */
    private function score_lcp(float $lcp): float {
        if ($lcp <= 2.5) {
            return 8.33; // Good
        } elseif ($lcp <= 4.0) {
            return 6.0; // Needs improvement
        } else {
            return 3.0; // Poor
        }
    }

    /**
     * Score First Input Delay (FID)
     *
     * @param float $fid FID value in milliseconds
     * @return float FID score
     */
    private function score_fid(float $fid): float {
        if ($fid <= 100) {
            return 8.33; // Good
        } elseif ($fid <= 300) {
            return 6.0; // Needs improvement
        } else {
            return 3.0; // Poor
        }
    }

    /**
     * Score Cumulative Layout Shift (CLS)
     *
     * @param float $cls CLS value
     * @return float CLS score
     */
    private function score_cls(float $cls): float {
        if ($cls <= 0.1) {
            return 8.33; // Good
        } elseif ($cls <= 0.25) {
            return 6.0; // Needs improvement
        } else {
            return 3.0; // Poor
        }
    }

    /**
     * Score traffic contribution for a page
     *
     * @param array $page_data Page data
     * @return float Traffic contribution score
     */
    private function score_traffic_contribution(array $page_data): float {
        $pageviews = $page_data['pageviews'] ?? 0;

        if ($pageviews >= 1000) {
            return 25.0; // High traffic page
        } elseif ($pageviews >= 500) {
            return 20.0; // Medium-high traffic
        } elseif ($pageviews >= 100) {
            return 15.0; // Medium traffic
        } else {
            return 10.0; // Low traffic
        }
    }

    /**
     * Score search visibility for a page
     *
     * @param array $page_data Page data
     * @return float Search visibility score
     */
    private function score_search_visibility(array $page_data): float {
        // This would be enhanced with actual search console data for the specific page
        // For now, return a default score
        return 15.0;
    }

    /**
     * Score engagement quality for a page
     *
     * @param array $page_data Page data
     * @return float Engagement quality score
     */
    private function score_engagement_quality(array $page_data): float {
        $sessions = $page_data['sessions'] ?? 0;
        $pageviews = $page_data['pageviews'] ?? 0;

        $pages_per_session = $sessions > 0 ? $pageviews / $sessions : 1;

        if ($pages_per_session >= 3.0) {
            return 25.0; // Excellent engagement
        } elseif ($pages_per_session >= 2.0) {
            return 20.0; // Good engagement
        } elseif ($pages_per_session >= 1.5) {
            return 15.0; // Fair engagement
        } else {
            return 10.0; // Poor engagement
        }
    }

    /**
     * Score technical health for a page
     *
     * @param array $page_data Page data
     * @return float Technical health score
     */
    private function score_technical_health(array $page_data): float {
        // This would be enhanced with actual Core Web Vitals data for the specific page
        // For now, return a default score
        return 20.0;
    }

    /**
     * Generate page-specific recommendations
     *
     * @param array $scores Component scores
     * @param array $page_data Page data
     * @return array Page recommendations
     */
    private function generate_page_recommendations(array $scores, array $page_data): array {
        $recommendations = [];

        if ($scores['traffic_contribution'] < 15) {
            $recommendations[] = 'Improve content quality and SEO optimization to increase traffic';
        }

        if ($scores['engagement_quality'] < 15) {
            $recommendations[] = 'Enhance content engagement with better formatting and internal links';
        }

        if ($scores['technical_health'] < 15) {
            $recommendations[] = 'Optimize page speed and Core Web Vitals performance';
        }

        return $recommendations;
    }

    /**
     * Determine impact level for priority matrix
     *
     * @param array $item Item to evaluate
     * @return string Impact level (high/low)
     */
    private function determine_impact_level(array $item): string {
        $score = $item['opportunity_score'] ?? 0;
        return $score >= 50 ? 'high' : 'low';
    }

    /**
     * Determine effort level for priority matrix
     *
     * @param array $item Item to evaluate
     * @return string Effort level (high/low)
     */
    private function determine_effort_level(array $item): string {
        $position = $item['current_position'] ?? 100;
        // Assume lower positions require more effort to improve
        return $position <= 20 ? 'low' : 'high';
    }
}
