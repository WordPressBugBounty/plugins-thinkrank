<?php
/**
 * AI Content Analyzer Class
 *
 * Settings storage for the 'content_analysis' category of the settings
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
 * AI Content Analyzer Class
 *
 * Holds the content analysis settings so stored rows keep loading, validating
 * and saving. The analysis methods this class used to carry (readability,
 * keyword density, structure scoring) were called only from get_output_data(),
 * which nothing called, so they never ran and were removed (#1312).
 *
 * @since 1.0.0
 */
class AI_Content_Analyzer extends Abstract_SEO_Manager {

    /**
     * Content optimization scoring weights
     *
     * Default value of the 'scoring_weights' setting.
     *
     * Semantic relevance used to sit here at 15%, fed by a block that returned
     * the same numbers for every input (#538). Removing a factor would have
     * capped the score at 85, so the remaining four were rescaled by 100/85 and
     * rounded to whole numbers, which preserves their former ratio (25:30:20:10)
     * and keeps the array at 100.
     *
     * @since 1.0.0
     * @var array
     */
    private array $scoring_weights = [
        'readability' => 29,
        'keyword_optimization' => 35,
        'content_structure' => 24,
        'technical_seo' => 12
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('ai_content_analyzer');
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

        // Validate analysis algorithms settings
        if (isset($settings['readability_enabled']) && !is_bool($settings['readability_enabled'])) {
            $validation['errors'][] = 'Readability analysis setting must be boolean';
            $validation['valid'] = false;
        }

        if (isset($settings['keyword_analysis_enabled']) && !is_bool($settings['keyword_analysis_enabled'])) {
            $validation['errors'][] = 'Keyword analysis setting must be boolean';
            $validation['valid'] = false;
        }

        if (isset($settings['semantic_analysis_enabled']) && !is_bool($settings['semantic_analysis_enabled'])) {
            $validation['errors'][] = 'Semantic analysis setting must be boolean';
            $validation['valid'] = false;
        }

        // Validate scoring weights
        if (isset($settings['scoring_weights']) && is_array($settings['scoring_weights'])) {
            $total_weight = array_sum($settings['scoring_weights']);
            // Floats read back from the row sum to 100.0, not the int 100 (#1685).
            if (abs((float) $total_weight - 100) > 0.01) {
                $validation['warnings'][] = 'Scoring weights should total 100%';
            }
        }

        // Validate keyword density thresholds
        if (isset($settings['keyword_density_threshold'])) {
            $threshold = $settings['keyword_density_threshold'];
            if (!is_numeric($threshold) || $threshold < 0 || $threshold > 10) {
                $validation['errors'][] = 'Keyword density threshold must be between 0 and 10';
                $validation['valid'] = false;
            }
        }

        // Validate readability targets
        if (isset($settings['target_reading_level'])) {
            $valid_levels = ['elementary', 'middle_school', 'high_school', 'college', 'graduate'];
            if (!in_array($settings['target_reading_level'], $valid_levels, true)) {
                $validation['errors'][] = 'Invalid target reading level specified';
                $validation['valid'] = false;
            }
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
        // manager's output: it backs the 'content_analysis' settings category
        // only. The analysis engine that used to run here was never called
        // from anywhere and was removed (#1312).
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
            'readability_enabled' => true,
            'keyword_analysis_enabled' => true,
            // Inert since #538; see get_settings_schema(). The default stays
            // true so the value this endpoint reports for a site that never
            // saved the key does not change, and nothing reads it either way.
            'semantic_analysis_enabled' => true,
            'structure_analysis_enabled' => true,
            'auto_analyze' => true,
            'target_reading_level' => 'high_school',
            'keyword_density_threshold' => 3.0,
            'min_content_length' => 300,
            'scoring_weights' => $this->scoring_weights
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'post':
                $defaults['min_content_length'] = 300;
                $defaults['target_reading_level'] = 'high_school';
                break;
            case 'page':
                $defaults['min_content_length'] = 200;
                $defaults['target_reading_level'] = 'middle_school';
                break;
            case 'product':
                $defaults['min_content_length'] = 150;
                $defaults['target_reading_level'] = 'middle_school';
                $defaults['keyword_density_threshold'] = 2.5;
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
                'title' => 'Enable AI Content Analysis',
                'description' => 'Enable AI-powered content analysis and optimization',
                'default' => true
            ],
            'readability_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Readability Analysis',
                'description' => 'Analyze content readability using Flesch-Kincaid algorithms',
                'default' => true
            ],
            'keyword_analysis_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Keyword Analysis',
                'description' => 'Analyze keyword density and optimization',
                'default' => true
            ],
            // Retired in #538, but still published so a client that stored the
            // key sees it described rather than missing. The title and
            // description say plainly that it does nothing: the topic modeling
            // and entity recognition they used to promise were a block that
            // returned the same answer for every input.
            'semantic_analysis_enabled' => [
                'type' => 'boolean',
                'title' => 'Semantic Analysis (retired)',
                'description' => 'Has no effect. The topic modeling and entity recognition this gated returned fixed values for every input and were removed. The key is still accepted so existing stored settings are not purged.',
                'default' => true
            ],
            'structure_analysis_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Structure Analysis',
                'description' => 'Analyze content structure and organization',
                'default' => true
            ],
            'target_reading_level' => [
                'type' => 'string',
                'title' => 'Target Reading Level',
                'description' => 'Target reading level for content optimization',
                'enum' => ['elementary', 'middle_school', 'high_school', 'college', 'graduate'],
                'default' => 'high_school'
            ],
            'keyword_density_threshold' => [
                'type' => 'number',
                'title' => 'Keyword Density Threshold',
                'description' => 'Maximum keyword density percentage (1-10%)',
                'minimum' => 1,
                'maximum' => 10,
                'default' => 3.0
            ],
            'min_content_length' => [
                'type' => 'integer',
                'title' => 'Minimum Content Length',
                'description' => 'Minimum word count for content analysis',
                'minimum' => 50,
                'default' => 300
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
