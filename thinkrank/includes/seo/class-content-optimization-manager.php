<?php
/**
 * Content Optimization Manager Class
 *
 * Settings storage for the 'content_optimization' category of the settings
 * management endpoint: defaults, schema and validation.
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
 * Holds the content optimization settings so stored rows keep loading,
 * validating and saving. The optimization engine this class used to carry
 * (real-time scoring, template compliance, performance tracking) was called
 * only from get_output_data(), which nothing called, so it never ran and was
 * removed (#1312).
 *
 * @since 1.0.0
 */
class Content_Optimization_Manager extends Abstract_SEO_Manager {

    /**
     * Content optimization scoring weights (2025 SEO standards)
     *
     * Default value of the 'optimization_weights' setting.
     *
     * Semantic relevance used to sit here at 5%, fed by a block that returned
     * the same numbers for every input (#538). Removing a factor would have
     * capped the score at 95, so the remaining six were rescaled by 100/95 and
     * rounded to whole numbers, which preserves their former ratio
     * (25:20:15:15:10:10) and keeps the array at 100.
     *
     * Rounding left technical_seo and user_experience, which were equal at 10,
     * one point apart at 11 and 10. That is an artefact of landing the spare
     * point somewhere, not a judgement that technical SEO matters more.
     *
     * @since 1.0.0
     * @var array
     */
    private array $optimization_weights = [
        'content_quality' => 26,      // Content depth, uniqueness, value
        'keyword_optimization' => 21, // Keyword usage and distribution
        'readability' => 16,          // Reading ease and comprehension
        'structure' => 16,            // Headings, paragraphs, formatting
        'technical_seo' => 11,        // Meta tags, URLs, schema
        'user_experience' => 10       // Engagement signals, CTR optimization
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
            // A tolerance, not !==: weights read back as floats (d:26 in the
            // row) sum to 100.0, which never equals the int 100 (#1685).
            if (abs((float) $total_weight - 100) > 0.01) {
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

        // The interface requires this method, but nothing renders this
        // manager's output: it backs the 'content_optimization' settings
        // category only. The optimization engine that used to run here was
        // never called from anywhere and was removed (#1312).
        return [
            'enabled' => $settings['enabled'] ?? true
        ];
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
}
