<?php
/**
 * Content Optimization Manager Class
 *
 * Advanced content optimization engine with real-time scoring, SEO templates,
 * performance tracking, and integration with AI Content Analyzer. Implements
 * 2025 SEO best practices with industry-standard optimization algorithms.
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
 * Content Optimization Manager Class
 *
 * Provides comprehensive content optimization with real-time scoring,
 * SEO templates, performance tracking, and actionable recommendations
 * integrated with AI Content Analyzer for complete optimization workflow.
 *
 * @since 1.0.0
 */
class Content_Optimization_Manager extends Abstract_SEO_Manager {

    /**
     * Content optimization scoring weights (2025 SEO standards)
     *
     * @since 1.0.0
     * @var array
     */
    private array $optimization_weights = [
        'content_quality' => 25,      // Content depth, uniqueness, value
        'keyword_optimization' => 20, // Keyword usage and distribution
        'readability' => 15,          // Reading ease and comprehension
        'structure' => 15,            // Headings, paragraphs, formatting
        'technical_seo' => 10,        // Meta tags, URLs, schema
        'user_experience' => 10,      // Engagement signals, CTR optimization
        'semantic_relevance' => 5     // Topic relevance and entity coverage
    ];

    /**
     * SEO content templates for different content types
     *
     * @since 1.0.0
     * @var array
     */
    private array $content_templates = [
        'blog_post' => [
            'min_word_count' => 300,
            'optimal_word_count' => 1500,
            'max_word_count' => 3000,
            'heading_structure' => [
                'h1_count' => 1,
                'h2_min' => 2,
                'h2_max' => 8,
                'h3_max' => 15
            ],
            'keyword_density' => [
                'primary' => [1.0, 3.0],
                'secondary' => [0.5, 2.0],
                'long_tail' => [0.1, 1.0]
            ],
            'content_elements' => [
                'introduction' => ['min_words' => 50, 'max_words' => 150],
                'conclusion' => ['min_words' => 50, 'max_words' => 200],
                'images' => ['min' => 1, 'optimal' => 3],
                'internal_links' => ['min' => 2, 'optimal' => 5],
                'external_links' => ['min' => 1, 'optimal' => 3]
            ]
        ],
        'product_page' => [
            'min_word_count' => 150,
            'optimal_word_count' => 500,
            'max_word_count' => 1000,
            'heading_structure' => [
                'h1_count' => 1,
                'h2_min' => 1,
                'h2_max' => 5,
                'h3_max' => 10
            ],
            'keyword_density' => [
                'primary' => [1.5, 4.0],
                'secondary' => [0.5, 2.5],
                'long_tail' => [0.2, 1.5]
            ],
            'content_elements' => [
                'product_description' => ['min_words' => 100, 'max_words' => 300],
                'features' => ['min_items' => 3, 'max_items' => 10],
                'images' => ['min' => 3, 'optimal' => 8],
                'reviews_section' => ['required' => true],
                'related_products' => ['min' => 3, 'optimal' => 6]
            ]
        ],
        'landing_page' => [
            'min_word_count' => 400,
            'optimal_word_count' => 1200,
            'max_word_count' => 2500,
            'heading_structure' => [
                'h1_count' => 1,
                'h2_min' => 3,
                'h2_max' => 6,
                'h3_max' => 12
            ],
            'keyword_density' => [
                'primary' => [1.5, 3.5],
                'secondary' => [0.8, 2.5],
                'long_tail' => [0.3, 1.5]
            ],
            'content_elements' => [
                'hero_section' => ['min_words' => 30, 'max_words' => 80],
                'value_proposition' => ['min_words' => 50, 'max_words' => 150],
                'benefits' => ['min_items' => 3, 'max_items' => 6],
                'social_proof' => ['required' => true],
                'call_to_action' => ['min' => 2, 'optimal' => 4]
            ]
        ],
        'category_page' => [
            'min_word_count' => 200,
            'optimal_word_count' => 800,
            'max_word_count' => 1500,
            'heading_structure' => [
                'h1_count' => 1,
                'h2_min' => 2,
                'h2_max' => 6,
                'h3_max' => 10
            ],
            'keyword_density' => [
                'primary' => [1.0, 3.0],
                'secondary' => [0.5, 2.0],
                'long_tail' => [0.2, 1.0]
            ],
            'content_elements' => [
                'category_description' => ['min_words' => 100, 'max_words' => 300],
                'subcategories' => ['min' => 2, 'optimal' => 8],
                'featured_products' => ['min' => 6, 'optimal' => 12],
                'filters' => ['required' => true],
                'breadcrumbs' => ['required' => true]
            ]
        ]
    ];

    /**
     * Performance tracking metrics
     *
     * @since 1.0.0
     * @var array
     */
    private array $performance_metrics = [
        'optimization_score' => [
            'weight' => 30,
            'target' => 85,
            'calculation' => 'weighted_average'
        ],
        'content_quality_score' => [
            'weight' => 25,
            'target' => 80,
            'calculation' => 'ai_analysis'
        ],
        'keyword_performance' => [
            'weight' => 20,
            'target' => 75,
            'calculation' => 'density_distribution'
        ],
        'readability_score' => [
            'weight' => 15,
            'target' => 70,
            'calculation' => 'flesch_kincaid'
        ],
        'technical_seo_score' => [
            'weight' => 10,
            'target' => 90,
            'calculation' => 'compliance_check'
        ]
    ];

    /**
     * Optimization recommendation priorities
     *
     * @since 1.0.0
     * @var array
     */
    private array $recommendation_priorities = [
        'critical' => [
            'score_threshold' => 40,
            'impact' => 'high',
            'urgency' => 'immediate',
            'color' => '#dc3545'
        ],
        'high' => [
            'score_threshold' => 60,
            'impact' => 'high',
            'urgency' => 'soon',
            'color' => '#fd7e14'
        ],
        'medium' => [
            'score_threshold' => 75,
            'impact' => 'medium',
            'urgency' => 'moderate',
            'color' => '#ffc107'
        ],
        'low' => [
            'score_threshold' => 85,
            'impact' => 'low',
            'urgency' => 'when_possible',
            'color' => '#28a745'
        ],
        'optimal' => [
            'score_threshold' => 100,
            'impact' => 'maintenance',
            'urgency' => 'none',
            'color' => '#20c997'
        ]
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('content_optimization_manager');
    }

    /**
     * Optimize content with comprehensive analysis and recommendations
     *
     * @since 1.0.0
     *
     * @param string $content      Content to optimize
     * @param array  $keywords     Target keywords
     * @param string $content_type Content type (blog_post, product_page, etc.)
     * @param array  $options      Optimization options
     * @return array Comprehensive optimization results
     */
    public function optimize_content(string $content, array $keywords = [], string $content_type = 'blog_post', array $options = []): array {
        $optimization = [
            'content_analysis' => [],
            'optimization_score' => 0,
            'template_compliance' => [],
            'recommendations' => [],
            'performance_metrics' => [],
            'optimization_opportunities' => [],
            'content_suggestions' => [],
            'optimization_timestamp' => current_time('mysql')
        ];

        // Get AI Content Analyzer results
        $ai_analyzer = new AI_Content_Analyzer();
        $optimization['content_analysis'] = $ai_analyzer->analyze_content($content, $keywords, $options);

        // Analyze template compliance
        $optimization['template_compliance'] = $this->analyze_template_compliance($content, $content_type, $keywords);

        // Calculate comprehensive optimization score
        $optimization['optimization_score'] = $this->calculate_comprehensive_optimization_score(
            $optimization['content_analysis'],
            $optimization['template_compliance']
        );

        // Generate performance metrics
        $optimization['performance_metrics'] = $this->calculate_performance_metrics(
            $optimization['content_analysis'],
            $optimization['template_compliance']
        );

        // Generate optimization recommendations
        $optimization['recommendations'] = $this->generate_optimization_recommendations(
            $optimization['content_analysis'],
            $optimization['template_compliance'],
            $content_type
        );

        // Identify optimization opportunities
        $optimization['optimization_opportunities'] = $this->identify_optimization_opportunities(
            $optimization['content_analysis'],
            $optimization['template_compliance'],
            $content_type
        );

        // Generate content suggestions
        $optimization['content_suggestions'] = $this->generate_content_suggestions(
            $optimization['content_analysis'],
            $content_type,
            $keywords
        );

        return $optimization;
    }

    /**
     * Get real-time optimization score for content
     *
     * @since 1.0.0
     *
     * @param string $content      Content to score
     * @param array  $keywords     Target keywords
     * @param string $content_type Content type
     * @return array Real-time scoring results
     */
    public function get_realtime_score(string $content, array $keywords = [], string $content_type = 'blog_post'): array {
        $scoring = [
            'overall_score' => 0,
            'component_scores' => [],
            'quick_wins' => [],
            'critical_issues' => [],
            'score_breakdown' => [],
            'improvement_potential' => 0
        ];

        // Quick content analysis for real-time feedback
        $quick_analysis = $this->perform_quick_analysis($content, $keywords, $content_type);

        // Calculate component scores
        $scoring['component_scores'] = $this->calculate_component_scores($quick_analysis, $content_type);

        // Calculate overall score
        $scoring['overall_score'] = $this->calculate_weighted_score($scoring['component_scores']);

        // Identify quick wins
        $scoring['quick_wins'] = $this->identify_quick_wins($quick_analysis, $content_type);

        // Identify critical issues
        $scoring['critical_issues'] = $this->identify_critical_issues($quick_analysis, $content_type);

        // Generate score breakdown
        $scoring['score_breakdown'] = $this->generate_score_breakdown($scoring['component_scores']);

        // Calculate improvement potential
        $scoring['improvement_potential'] = $this->calculate_improvement_potential($scoring['component_scores']);

        return $scoring;
    }

    /**
     * Analyze template compliance for content type
     *
     * @since 1.0.0
     *
     * @param string $content      Content to analyze
     * @param string $content_type Content type
     * @param array  $keywords     Target keywords
     * @return array Template compliance analysis
     */
    public function analyze_template_compliance(string $content, string $content_type, array $keywords = []): array {
        $template = $this->content_templates[$content_type] ?? $this->content_templates['blog_post'];

        $compliance = [
            'content_type' => $content_type,
            'template_score' => 0,
            'word_count_compliance' => [],
            'heading_compliance' => [],
            'keyword_compliance' => [],
            'element_compliance' => [],
            'compliance_percentage' => 0,
            'missing_elements' => [],
            'recommendations' => []
        ];

        // Analyze word count compliance
        $compliance['word_count_compliance'] = $this->analyze_word_count_compliance($content, $template);

        // Analyze heading structure compliance
        $compliance['heading_compliance'] = $this->analyze_heading_compliance($content, $template);

        // Analyze keyword density compliance
        if (!empty($keywords)) {
            $compliance['keyword_compliance'] = $this->analyze_keyword_compliance($content, $keywords, $template);
        }

        // Analyze content elements compliance
        $compliance['element_compliance'] = $this->analyze_element_compliance($content, $template);

        // Calculate template score
        $compliance['template_score'] = $this->calculate_template_score($compliance);

        // Calculate compliance percentage
        $compliance['compliance_percentage'] = $this->calculate_compliance_percentage($compliance);

        // Identify missing elements
        $compliance['missing_elements'] = $this->identify_missing_elements($compliance, $template);

        // Generate template recommendations
        $compliance['recommendations'] = $this->generate_template_recommendations($compliance, $template);

        return $compliance;
    }

    /**
     * Track content performance over time
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @param array    $metrics      Performance metrics
     * @return array Performance tracking results
     */
    public function track_performance(string $context_type, ?int $context_id, array $metrics): array {
        $tracking = [
            'current_metrics' => $metrics,
            'historical_data' => [],
            'performance_trends' => [],
            'improvement_rate' => 0,
            'performance_score' => 0,
            'benchmark_comparison' => [],
            'tracking_timestamp' => current_time('mysql')
        ];

        // Get historical performance data
        $tracking['historical_data'] = $this->get_historical_performance($context_type, $context_id);

        // Calculate performance trends
        $tracking['performance_trends'] = $this->calculate_performance_trends($tracking['historical_data'], $metrics);

        // Calculate improvement rate
        $tracking['improvement_rate'] = $this->calculate_improvement_rate($tracking['performance_trends']);

        // Calculate overall performance score
        $tracking['performance_score'] = $this->calculate_performance_score($metrics);

        // Compare with benchmarks
        $tracking['benchmark_comparison'] = $this->compare_with_benchmarks($metrics, $context_type);

        // Store performance data
        $this->store_performance_data($context_type, $context_id, $tracking);

        return $tracking;
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Validate optimization weights
        if (isset($settings['optimization_weights']) && is_array($settings['optimization_weights'])) {
            $total_weight = array_sum($settings['optimization_weights']);
            if ($total_weight !== 100) {
                $validation['errors'][] = 'Optimization weights must total 100%';
                $validation['valid'] = false;
            }
        }

        // Validate content templates
        if (isset($settings['content_templates']) && is_array($settings['content_templates'])) {
            foreach ($settings['content_templates'] as $type => $template) {
                if (!isset($template['min_word_count']) || !is_numeric($template['min_word_count'])) {
                    $validation['errors'][] = "Invalid min_word_count for content type: {$type}";
                    $validation['valid'] = false;
                }
            }
        }

        // Validate performance targets
        if (isset($settings['performance_targets']) && is_array($settings['performance_targets'])) {
            foreach ($settings['performance_targets'] as $metric => $target) {
                if (!is_numeric($target) || $target < 0 || $target > 100) {
                    $validation['errors'][] = "Invalid performance target for {$metric}: must be 0-100";
                    $validation['valid'] = false;
                }
            }
        }

        // Validate optimization features
        if (isset($settings['realtime_optimization']) && !is_bool($settings['realtime_optimization'])) {
            $validation['errors'][] = 'Real-time optimization setting must be boolean';
            $validation['valid'] = false;
        }

        if (isset($settings['auto_suggestions']) && !is_bool($settings['auto_suggestions'])) {
            $validation['errors'][] = 'Auto suggestions setting must be boolean';
            $validation['valid'] = false;
        }

        // Calculate validation score
        $validation['score'] = $this->calculate_validation_score($validation);

        return $validation;
    }

    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        $settings = $this->get_settings($context_type, $context_id);

        $output = [
            'optimization_dashboard' => [],
            'realtime_scoring' => [],
            'content_templates' => [],
            'performance_metrics' => [],
            'recommendations' => [],
            'enabled' => $settings['enabled'] ?? true
        ];

        if (!$output['enabled']) {
            return $output;
        }

        // Get content for optimization
        $content = $this->extract_content_for_optimization($context_type, $context_id);
        $keywords = $this->extract_target_keywords($context_type, $context_id);
        $content_type = $this->determine_content_type($context_type, $context_id);

        if (!empty($content)) {
            // Generate optimization dashboard
            $output['optimization_dashboard'] = $this->generate_optimization_dashboard($content, $keywords, $content_type);

            // Get real-time scoring
            $output['realtime_scoring'] = $this->get_realtime_score($content, $keywords, $content_type);

            // Get content templates
            $output['content_templates'] = $this->get_content_templates_for_type($content_type);

            // Get performance metrics
            $output['performance_metrics'] = $this->get_performance_metrics($context_type, $context_id);

            // Get optimization recommendations
            $optimization_results = $this->optimize_content($content, $keywords, $content_type);
            $output['recommendations'] = $optimization_results['recommendations'] ?? [];

            // Store optimization results
            $this->store_optimization_results($context_type, $context_id, $optimization_results);
        }

        return $output;
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        $defaults = [
            'enabled' => true,
            'realtime_optimization' => true,
            'auto_suggestions' => true,
            'performance_tracking' => true,
            'template_compliance_check' => true,
            'optimization_weights' => $this->optimization_weights,
            'performance_targets' => [
                'optimization_score' => 85,
                'content_quality' => 80,
                'keyword_performance' => 75,
                'readability' => 70,
                'technical_seo' => 90
            ],
            'notification_thresholds' => [
                'critical' => 40,
                'warning' => 60,
                'success' => 85
            ]
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'post':
                $defaults['default_content_type'] = 'blog_post';
                $defaults['performance_targets']['optimization_score'] = 85;
                break;
            case 'page':
                $defaults['default_content_type'] = 'landing_page';
                $defaults['performance_targets']['optimization_score'] = 90;
                break;
            case 'product':
                $defaults['default_content_type'] = 'product_page';
                $defaults['performance_targets']['optimization_score'] = 80;
                break;
            case 'category':
                $defaults['default_content_type'] = 'category_page';
                $defaults['performance_targets']['optimization_score'] = 75;
                break;
        }

        return $defaults;
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Content Optimization',
                'description' => 'Enable content optimization engine and recommendations',
                'default' => true
            ],
            'realtime_optimization' => [
                'type' => 'boolean',
                'title' => 'Real-time Optimization',
                'description' => 'Enable real-time content scoring and feedback',
                'default' => true
            ],
            'auto_suggestions' => [
                'type' => 'boolean',
                'title' => 'Auto Suggestions',
                'description' => 'Automatically generate content improvement suggestions',
                'default' => true
            ],
            'performance_tracking' => [
                'type' => 'boolean',
                'title' => 'Performance Tracking',
                'description' => 'Track content performance metrics over time',
                'default' => true
            ],
            'template_compliance_check' => [
                'type' => 'boolean',
                'title' => 'Template Compliance Check',
                'description' => 'Check content against SEO templates and best practices',
                'default' => true
            ],
            'optimization_score_target' => [
                'type' => 'integer',
                'title' => 'Optimization Score Target',
                'description' => 'Target optimization score (0-100)',
                'minimum' => 0,
                'maximum' => 100,
                'default' => 85
            ],
            'content_quality_target' => [
                'type' => 'integer',
                'title' => 'Content Quality Target',
                'description' => 'Target content quality score (0-100)',
                'minimum' => 0,
                'maximum' => 100,
                'default' => 80
            ],
            'default_content_type' => [
                'type' => 'string',
                'title' => 'Default Content Type',
                'description' => 'Default content type for optimization templates',
                'enum' => ['blog_post', 'product_page', 'landing_page', 'category_page'],
                'default' => 'blog_post'
            ]
        ];
    }

    /**
     * Calculate comprehensive optimization score
     *
     * @since 1.0.0
     *
     * @param array $content_analysis   AI content analysis results
     * @param array $template_compliance Template compliance results
     * @return int Comprehensive optimization score (0-100)
     */
    private function calculate_comprehensive_optimization_score(array $content_analysis, array $template_compliance): int {
        $scores = [];

        // Content quality score (25% weight)
        if (!empty($content_analysis['optimization_score'])) {
            $scores['content_quality'] = $content_analysis['optimization_score'] * ($this->optimization_weights['content_quality'] / 100);
        }

        // Keyword optimization score (20% weight)
        if (!empty($content_analysis['keyword_analysis']['optimization_score'])) {
            $scores['keyword_optimization'] = $content_analysis['keyword_analysis']['optimization_score'] * ($this->optimization_weights['keyword_optimization'] / 100);
        }

        // Readability score (15% weight)
        if (!empty($content_analysis['readability']['readability_score'])) {
            $scores['readability'] = $content_analysis['readability']['readability_score'] * ($this->optimization_weights['readability'] / 100);
        }

        // Structure score (15% weight)
        if (!empty($content_analysis['structure_analysis']['structure_score'])) {
            $scores['structure'] = $content_analysis['structure_analysis']['structure_score'] * ($this->optimization_weights['structure'] / 100);
        }

        // Template compliance score (10% weight)
        if (!empty($template_compliance['template_score'])) {
            $scores['technical_seo'] = $template_compliance['template_score'] * ($this->optimization_weights['technical_seo'] / 100);
        }

        // User experience score (10% weight)
        $ux_score = $this->calculate_user_experience_score($content_analysis, $template_compliance);
        $scores['user_experience'] = $ux_score * ($this->optimization_weights['user_experience'] / 100);

        // Semantic relevance score (5% weight)
        if (!empty($content_analysis['semantic_analysis']['relevance_score'])) {
            $scores['semantic_relevance'] = $content_analysis['semantic_analysis']['relevance_score'] * ($this->optimization_weights['semantic_relevance'] / 100);
        }

        return (int) round(array_sum($scores));
    }

    /**
     * Perform quick analysis for real-time feedback
     *
     * @since 1.0.0
     *
     * @param string $content      Content to analyze
     * @param array  $keywords     Target keywords
     * @param string $content_type Content type
     * @return array Quick analysis results
     */
    private function perform_quick_analysis(string $content, array $keywords, string $content_type): array {
        $analysis = [
            'word_count' => str_word_count(wp_strip_all_tags($content)),
            'character_count' => strlen(wp_strip_all_tags($content)),
            'paragraph_count' => count(preg_split('/\n\s*\n/', trim($content), -1, PREG_SPLIT_NO_EMPTY)),
            'heading_count' => preg_match_all('/<h[1-6][^>]*>/i', $content),
            'image_count' => preg_match_all('/<img[^>]*>/i', $content),
            'link_count' => preg_match_all('/<a[^>]*href/i', $content),
            'keyword_density' => [],
            'readability_estimate' => 0
        ];

        // Quick keyword density calculation
        if (!empty($keywords)) {
            $content_lower = strtolower(wp_strip_all_tags($content));
            foreach ($keywords as $keyword) {
                $keyword_lower = strtolower($keyword);
                $occurrences = substr_count($content_lower, $keyword_lower);
                $analysis['keyword_density'][$keyword] = $analysis['word_count'] > 0 ?
                    ($occurrences / $analysis['word_count']) * 100 : 0;
            }
        }

        // Quick readability estimate
        if ($analysis['word_count'] > 0 && $analysis['paragraph_count'] > 0) {
            $avg_words_per_paragraph = $analysis['word_count'] / $analysis['paragraph_count'];
            $analysis['readability_estimate'] = max(0, min(100, 100 - ($avg_words_per_paragraph * 2)));
        }

        return $analysis;
    }

    /**
     * Calculate component scores for real-time feedback
     *
     * @since 1.0.0
     *
     * @param array  $quick_analysis Quick analysis results
     * @param string $content_type   Content type
     * @return array Component scores
     */
    private function calculate_component_scores(array $quick_analysis, string $content_type): array {
        $template = $this->content_templates[$content_type] ?? $this->content_templates['blog_post'];
        $scores = [];

        // Word count score
        $word_count = $quick_analysis['word_count'];
        if ($word_count >= $template['optimal_word_count']) {
            $scores['word_count'] = 100;
        } elseif ($word_count >= $template['min_word_count']) {
            $scores['word_count'] = 50 + (($word_count - $template['min_word_count']) /
                ($template['optimal_word_count'] - $template['min_word_count'])) * 50;
        } else {
            $scores['word_count'] = ($word_count / $template['min_word_count']) * 50;
        }

        // Heading structure score
        $heading_count = $quick_analysis['heading_count'];
        $min_headings = $template['heading_structure']['h2_min'] ?? 2;
        $scores['headings'] = min(100, ($heading_count / $min_headings) * 100);

        // Keyword density score
        $keyword_scores = [];
        foreach ($quick_analysis['keyword_density'] as $keyword => $density) {
            if ($density >= 1.0 && $density <= 3.0) {
                $keyword_scores[] = 100;
            } elseif ($density >= 0.5 && $density <= 5.0) {
                $keyword_scores[] = 70;
            } else {
                $keyword_scores[] = 30;
            }
        }
        $scores['keywords'] = !empty($keyword_scores) ? array_sum($keyword_scores) / count($keyword_scores) : 50;

        // Readability score
        $scores['readability'] = $quick_analysis['readability_estimate'];

        // Content elements score
        $element_score = 0;
        if ($quick_analysis['image_count'] > 0) {
            $element_score += 25;
        }
        if ($quick_analysis['link_count'] > 0) {
            $element_score += 25;
        }
        if ($quick_analysis['paragraph_count'] >= 3) {
            $element_score += 25;
        }
        if ($quick_analysis['heading_count'] >= 2) {
            $element_score += 25;
        }
        $scores['elements'] = $element_score;

        return $scores;
    }

    /**
     * Calculate weighted score from component scores
     *
     * @since 1.0.0
     *
     * @param array $component_scores Component scores
     * @return int Weighted overall score
     */
    private function calculate_weighted_score(array $component_scores): int {
        $weights = [
            'word_count' => 20,
            'headings' => 15,
            'keywords' => 25,
            'readability' => 20,
            'elements' => 20
        ];

        $weighted_sum = 0;
        $total_weight = 0;

        foreach ($component_scores as $component => $score) {
            $weight = $weights[$component] ?? 0;
            $weighted_sum += $score * $weight;
            $total_weight += $weight;
        }

        return $total_weight > 0 ? (int) round($weighted_sum / $total_weight) : 0;
    }

    /**
     * Identify quick wins for optimization
     *
     * @since 1.0.0
     *
     * @param array  $quick_analysis Quick analysis results
     * @param string $content_type   Content type
     * @return array Quick win recommendations
     */
    private function identify_quick_wins(array $quick_analysis, string $content_type): array {
        $quick_wins = [];
        $template = $this->content_templates[$content_type] ?? $this->content_templates['blog_post'];

        // Word count quick wins
        if ($quick_analysis['word_count'] < $template['min_word_count']) {
            $needed_words = $template['min_word_count'] - $quick_analysis['word_count'];
            $quick_wins[] = [
                'type' => 'word_count',
                'priority' => 'high',
                'effort' => 'medium',
                'impact' => 'high',
                'message' => "Add {$needed_words} more words to reach minimum length",
                'action' => 'Expand content with relevant information'
            ];
        }

        // Heading quick wins
        if ($quick_analysis['heading_count'] < 2) {
            $quick_wins[] = [
                'type' => 'headings',
                'priority' => 'medium',
                'effort' => 'low',
                'impact' => 'medium',
                'message' => 'Add more heading tags to improve structure',
                'action' => 'Break content into sections with H2 and H3 tags'
            ];
        }

        // Image quick wins
        if ($quick_analysis['image_count'] === 0) {
            $quick_wins[] = [
                'type' => 'images',
                'priority' => 'medium',
                'effort' => 'low',
                'impact' => 'medium',
                'message' => 'Add images to improve engagement',
                'action' => 'Include relevant images with alt text'
            ];
        }

        // Keyword density quick wins
        foreach ($quick_analysis['keyword_density'] as $keyword => $density) {
            if ($density < 0.5) {
                $quick_wins[] = [
                    'type' => 'keyword_density',
                    'priority' => 'high',
                    'effort' => 'low',
                    'impact' => 'high',
                    'message' => "Increase usage of keyword '{$keyword}'",
                    'action' => 'Add keyword naturally throughout content'
                ];
            }
        }

        return $quick_wins;
    }

    /**
     * Identify critical issues requiring immediate attention
     *
     * @since 1.0.0
     *
     * @param array  $quick_analysis Quick analysis results
     * @param string $content_type   Content type
     * @return array Critical issues
     */
    private function identify_critical_issues(array $quick_analysis, string $content_type): array {
        $critical_issues = [];
        $template = $this->content_templates[$content_type] ?? $this->content_templates['blog_post'];

        // Critical word count issues
        if ($quick_analysis['word_count'] < ($template['min_word_count'] * 0.5)) {
            $critical_issues[] = [
                'type' => 'word_count',
                'severity' => 'critical',
                'message' => 'Content is severely under the minimum word count',
                'impact' => 'SEO performance will be significantly impacted',
                'action' => 'Substantially expand content before publishing'
            ];
        }

        // Critical keyword issues
        foreach ($quick_analysis['keyword_density'] as $keyword => $density) {
            if ($density > 5.0) {
                $critical_issues[] = [
                    'type' => 'keyword_stuffing',
                    'severity' => 'critical',
                    'message' => "Keyword '{$keyword}' may be over-optimized (keyword stuffing)",
                    'impact' => 'Search engines may penalize this content',
                    'action' => 'Reduce keyword density to 1-3%'
                ];
            }
        }

        // Critical structure issues
        if ($quick_analysis['heading_count'] === 0) {
            $critical_issues[] = [
                'type' => 'no_headings',
                'severity' => 'critical',
                'message' => 'Content has no heading structure',
                'impact' => 'Poor readability and SEO performance',
                'action' => 'Add proper heading hierarchy (H1, H2, H3)'
            ];
        }

        return $critical_issues;
    }

    /**
     * Generate score breakdown for visualization
     *
     * @since 1.0.0
     *
     * @param array $component_scores Component scores
     * @return array Score breakdown data
     */
    private function generate_score_breakdown(array $component_scores): array {
        $breakdown = [];

        foreach ($component_scores as $component => $score) {
            $breakdown[] = [
                'component' => $component,
                'score' => (int) round($score),
                'status' => $this->get_score_status($score),
                'color' => $this->get_score_color($score),
                'description' => $this->get_component_description($component)
            ];
        }

        return $breakdown;
    }

    /**
     * Calculate improvement potential
     *
     * @since 1.0.0
     *
     * @param array $component_scores Component scores
     * @return int Improvement potential percentage
     */
    private function calculate_improvement_potential(array $component_scores): int {
        $total_possible = count($component_scores) * 100;
        $current_total = array_sum($component_scores);
        $potential = $total_possible - $current_total;

        return (int) round(($potential / $total_possible) * 100);
    }

    /**
     * Extract content for optimization
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return string Content to optimize
     */
    private function extract_content_for_optimization(string $context_type, ?int $context_id): string {
        $content = '';

        switch ($context_type) {
            case 'post':
            case 'page':
            case 'product':
                if ($context_id) {
                    $post = get_post($context_id);
                    if ($post) {
                        $content = $post->post_title . ' ' . $post->post_content;
                    }
                }
                break;
            case 'site':
                // Get homepage content
                $homepage_id = get_option('page_on_front');
                if ($homepage_id && $homepage_id !== '0') {
                    $homepage = get_post($homepage_id);
                    if ($homepage) {
                        $content = $homepage->post_title . ' ' . $homepage->post_content;
                    }
                } else {
                    // Get recent posts for blog homepage
                    $recent_posts = get_posts(['numberposts' => 3]);
                    $content_parts = [];
                    foreach ($recent_posts as $post) {
                        $content_parts[] = $post->post_title . ' ' . wp_trim_words($post->post_content, 100);
                    }
                    $content = implode(' ', $content_parts);
                }
                break;
        }

        return $content;
    }

    /**
     * Extract target keywords for optimization
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return array Target keywords
     */
    private function extract_target_keywords(string $context_type, ?int $context_id): array {
        // This would typically get keywords from the database
        // For now, return some default keywords based on content
        $keywords = [];

        $content = $this->extract_content_for_optimization($context_type, $context_id);
        if (!empty($content)) {
            // Simple keyword extraction from title and content
            $words = str_word_count(strtolower(wp_strip_all_tags($content)), 1);
            $word_counts = array_count_values($words);

            // Remove common stop words
            $stop_words = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
            foreach ($stop_words as $stop_word) {
                unset($word_counts[$stop_word]);
            }

            arsort($word_counts);
            $keywords = array_slice(array_keys($word_counts), 0, 5);
        }

        return $keywords;
    }

    /**
     * Determine content type from context
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return string Content type
     */
    private function determine_content_type(string $context_type, ?int $context_id): string {
        switch ($context_type) {
            case 'post':
                return 'blog_post';
            case 'page':
                return 'landing_page';
            case 'product':
                return 'product_page';
            case 'category':
                return 'category_page';
            default:
                return 'blog_post';
        }
    }

    /**
     * Store optimization results in database
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @param array    $results      Optimization results
     * @return bool Success status
     */
    private function store_optimization_results(string $context_type, ?int $context_id, array $results): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_analysis';

        $data = [
            'context_type' => $context_type,
            'context_id' => $context_id,
            'analysis_type' => 'content_optimization',
            'analysis_data' => wp_json_encode($results),
            'score' => $results['optimization_score'] ?? 0,
            'status' => 'completed',
            'recommendations' => wp_json_encode($results['recommendations'] ?? []),
            'analyzed_by' => get_current_user_id()
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Optimization results storage requires direct database access
        $result = $wpdb->insert($table_name, $data);

        return $result !== false;
    }

    /**
     * Calculate validation score
     *
     * @since 1.0.0
     *
     * @param array $validation Validation results
     * @return int Score (0-100)
     */
    private function calculate_validation_score(array $validation): int {
        $score = 100;
        $score -= count($validation['errors']) * 20;
        $score -= count($validation['warnings']) * 10;
        $score -= count($validation['suggestions']) * 5;

        return max(0, $score);
    }

    /**
     * Get score status based on score value
     *
     * @since 1.0.0
     *
     * @param float $score Score value
     * @return string Status
     */
    private function get_score_status(float $score): string {
        if ($score >= 85) {
            return 'excellent';
        } elseif ($score >= 70) {
            return 'good';
        } elseif ($score >= 50) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    /**
     * Get score color based on score value
     *
     * @since 1.0.0
     *
     * @param float $score Score value
     * @return string Color code
     */
    private function get_score_color(float $score): string {
        if ($score >= 85) {
            return '#28a745'; // Green
        } elseif ($score >= 70) {
            return '#20c997'; // Teal
        } elseif ($score >= 50) {
            return '#ffc107'; // Yellow
        } else {
            return '#dc3545'; // Red
        }
    }

    /**
     * Get component description
     *
     * @since 1.0.0
     *
     * @param string $component Component name
     * @return string Description
     */
    private function get_component_description(string $component): string {
        $descriptions = [
            'word_count' => 'Content length and depth',
            'headings' => 'Heading structure and organization',
            'keywords' => 'Keyword optimization and density',
            'readability' => 'Reading ease and comprehension',
            'elements' => 'Content elements (images, links, etc.)'
        ];

        return $descriptions[$component] ?? 'Content optimization component';
    }

    /**
     * Simple implementations for helper methods
     * These would be enhanced with more sophisticated algorithms in production
     */

    private function analyze_word_count_compliance(string $content, array $template): array {
        $word_count = str_word_count(wp_strip_all_tags($content));
        $min_words = $template['min_word_count'];
        $optimal_words = $template['optimal_word_count'];

        $compliance_score = 0;
        if ($word_count >= $optimal_words) {
            $compliance_score = 100;
        } elseif ($word_count >= $min_words) {
            $compliance_score = 50 + (($word_count - $min_words) / ($optimal_words - $min_words)) * 50;
        } else {
            $compliance_score = ($word_count / $min_words) * 50;
        }

        return [
            'current_count' => $word_count,
            'min_required' => $min_words,
            'optimal_count' => $optimal_words,
            'compliance_score' => (int) round($compliance_score),
            'status' => $word_count >= $min_words ? 'compliant' : 'non_compliant'
        ];
    }

    private function analyze_heading_compliance(string $content, array $template): array {
        $heading_counts = [];
        for ($i = 1; $i <= 6; $i++) {
            $heading_counts["h{$i}"] = preg_match_all("/<h{$i}[^>]*>/i", $content);
        }

        $compliance_score = 100;
        $issues = [];

        // Check H1 count
        if ($heading_counts['h1'] !== 1) {
            $compliance_score -= 30;
            $issues[] = 'Should have exactly one H1 tag';
        }

        // Check H2 minimum
        $h2_min = $template['heading_structure']['h2_min'] ?? 2;
        if ($heading_counts['h2'] < $h2_min) {
            $compliance_score -= 20;
            $issues[] = "Should have at least {$h2_min} H2 tags";
        }

        return [
            'heading_counts' => $heading_counts,
            'compliance_score' => max(0, $compliance_score),
            'issues' => $issues,
            'status' => empty($issues) ? 'compliant' : 'non_compliant'
        ];
    }

    private function analyze_keyword_compliance(string $content, array $keywords, array $template): array {
        $content_lower = strtolower(wp_strip_all_tags($content));
        $word_count = str_word_count($content_lower);
        $compliance_data = [];

        foreach ($keywords as $index => $keyword) {
            $keyword_lower = strtolower($keyword);
            $occurrences = substr_count($content_lower, $keyword_lower);
            $density = $word_count > 0 ? ($occurrences / $word_count) * 100 : 0;

            $keyword_type = $index === 0 ? 'primary' : 'secondary';
            $target_range = $template['keyword_density'][$keyword_type] ?? [1.0, 3.0];

            $compliance_data[] = [
                'keyword' => $keyword,
                'type' => $keyword_type,
                'density' => round($density, 2),
                'target_range' => $target_range,
                'compliant' => $density >= $target_range[0] && $density <= $target_range[1]
            ];
        }

        return $compliance_data;
    }

    private function analyze_element_compliance(string $content, array $template): array {
        $elements = $template['content_elements'] ?? [];
        $compliance_data = [];

        // Check images
        if (isset($elements['images'])) {
            $image_count = preg_match_all('/<img[^>]*>/i', $content);
            $min_images = $elements['images']['min'] ?? 1;
            $compliance_data['images'] = [
                'current' => $image_count,
                'required' => $min_images,
                'compliant' => $image_count >= $min_images
            ];
        }

        // Check internal links
        if (isset($elements['internal_links'])) {
            $link_count = preg_match_all('/<a[^>]*href/i', $content);
            $min_links = $elements['internal_links']['min'] ?? 2;
            $compliance_data['internal_links'] = [
                'current' => $link_count,
                'required' => $min_links,
                'compliant' => $link_count >= $min_links
            ];
        }

        return $compliance_data;
    }

    private function calculate_template_score(array $compliance): int {
        $scores = [];

        if (!empty($compliance['word_count_compliance']['compliance_score'])) {
            $scores[] = $compliance['word_count_compliance']['compliance_score'];
        }

        if (!empty($compliance['heading_compliance']['compliance_score'])) {
            $scores[] = $compliance['heading_compliance']['compliance_score'];
        }

        // Add other compliance scores

        return !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : 0;
    }

    private function calculate_compliance_percentage(array $compliance): int {
        $total_checks = 0;
        $passed_checks = 0;

        // Count word count compliance
        if (!empty($compliance['word_count_compliance'])) {
            $total_checks++;
            if ($compliance['word_count_compliance']['status'] === 'compliant') {
                $passed_checks++;
            }
        }

        // Count heading compliance
        if (!empty($compliance['heading_compliance'])) {
            $total_checks++;
            if ($compliance['heading_compliance']['status'] === 'compliant') {
                $passed_checks++;
            }
        }

        return $total_checks > 0 ? (int) round(($passed_checks / $total_checks) * 100) : 0;
    }

    private function identify_missing_elements(array $compliance, array $template): array {
        $missing = [];

        // Check for missing content elements
        if (!empty($compliance['element_compliance'])) {
            foreach ($compliance['element_compliance'] as $element => $data) {
                if (!$data['compliant']) {
                    $missing[] = [
                        'element' => $element,
                        'current' => $data['current'],
                        'required' => $data['required']
                    ];
                }
            }
        }

        return $missing;
    }

    private function generate_template_recommendations(array $compliance, array $template): array {
        $recommendations = [];

        // Word count recommendations
        if (!empty($compliance['word_count_compliance']) &&
            $compliance['word_count_compliance']['status'] === 'non_compliant') {
            $needed = $compliance['word_count_compliance']['min_required'] -
                     $compliance['word_count_compliance']['current_count'];
            $recommendations[] = [
                'type' => 'word_count',
                'priority' => 'high',
                'message' => "Add {$needed} more words to meet minimum requirements",
                'action' => 'Expand content with relevant information'
            ];
        }

        // Heading recommendations
        if (!empty($compliance['heading_compliance']['issues'])) {
            foreach ($compliance['heading_compliance']['issues'] as $issue) {
                $recommendations[] = [
                    'type' => 'headings',
                    'priority' => 'medium',
                    'message' => $issue,
                    'action' => 'Improve heading structure'
                ];
            }
        }

        return $recommendations;
    }

    private function calculate_user_experience_score(array $content_analysis, array $template_compliance): int {
        $score = 100;

        // Deduct for poor readability
        if (!empty($content_analysis['readability']['readability_score'])) {
            if ($content_analysis['readability']['readability_score'] < 60) {
                $score -= 30;
            }
        }

        // Deduct for poor structure
        if (!empty($template_compliance['heading_compliance']['compliance_score'])) {
            if ($template_compliance['heading_compliance']['compliance_score'] < 70) {
                $score -= 20;
            }
        }

        return max(0, $score);
    }

    // Placeholder implementations for methods referenced but not yet implemented
    private function calculate_performance_metrics(array $content_analysis, array $template_compliance): array {
        return ['performance_score' => 80, 'metrics' => []];
    }

    private function generate_optimization_recommendations(array $content_analysis, array $template_compliance, string $content_type): array {
        $recommendations = [];

        // Merge recommendations from different sources
        if (!empty($content_analysis['recommendations'])) {
            $recommendations = array_merge($recommendations, $content_analysis['recommendations']);
        }

        if (!empty($template_compliance['recommendations'])) {
            $recommendations = array_merge($recommendations, $template_compliance['recommendations']);
        }

        return $recommendations;
    }

    private function identify_optimization_opportunities(array $content_analysis, array $template_compliance, string $content_type): array {
        return ['opportunities' => [], 'potential_impact' => 'medium'];
    }

    private function generate_content_suggestions(array $content_analysis, string $content_type, array $keywords): array {
        return ['suggestions' => [], 'content_ideas' => []];
    }

    private function generate_optimization_dashboard(string $content, array $keywords, string $content_type): array {
        return ['dashboard_data' => [], 'widgets' => []];
    }

    private function get_content_templates_for_type(string $content_type): array {
        return $this->content_templates[$content_type] ?? $this->content_templates['blog_post'];
    }

    private function get_performance_metrics(string $context_type, ?int $context_id): array {
        return ['metrics' => [], 'trends' => []];
    }

    private function get_historical_performance(string $context_type, ?int $context_id): array {
        return ['historical_data' => []];
    }

    private function calculate_performance_trends(array $historical_data, array $current_metrics): array {
        return ['trends' => []];
    }

    private function calculate_improvement_rate(array $performance_trends): float {
        return 0.0;
    }

    private function calculate_performance_score(array $metrics): int {
        return 80;
    }

    private function compare_with_benchmarks(array $metrics, string $context_type): array {
        return ['benchmark_comparison' => []];
    }

    private function store_performance_data(string $context_type, ?int $context_id, array $tracking): bool {
        return true;
    }
}
