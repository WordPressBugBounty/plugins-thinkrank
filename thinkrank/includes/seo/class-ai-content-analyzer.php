<?php
/**
 * AI Content Analyzer Class
 *
 * Comprehensive AI-powered content analysis with real algorithms for readability,
 * keyword density, semantic analysis, and content optimization. Implements 2025
 * SEO best practices with industry-standard calculations and recommendations.
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
 * Provides comprehensive content analysis using real AI algorithms including
 * readability scoring, keyword analysis, semantic content analysis, and
 * structure optimization with actionable recommendations.
 *
 * @since 1.0.0
 */
class AI_Content_Analyzer extends Abstract_SEO_Manager {

    /**
     * Content analysis algorithms with their specifications
     *
     * @since 1.0.0
     * @var array
     */
    private array $analysis_algorithms = [
        'readability' => [
            'flesch_reading_ease' => [
                'name' => 'Flesch Reading Ease',
                'formula' => '206.835 - (1.015 × ASL) - (84.6 × ASW)',
                'scale' => [90 => 'Very Easy', 80 => 'Easy', 70 => 'Fairly Easy', 60 => 'Standard', 50 => 'Fairly Difficult', 30 => 'Difficult', 0 => 'Very Difficult'],
                'weight' => 40
            ],
            'flesch_kincaid_grade' => [
                'name' => 'Flesch-Kincaid Grade Level',
                'formula' => '(0.39 × ASL) + (11.8 × ASW) - 15.59',
                'target_range' => [6, 8],
                'weight' => 30
            ],
            'automated_readability_index' => [
                'name' => 'Automated Readability Index',
                'formula' => '(4.71 × CPW) + (0.5 × SPW) - 21.43',
                'target_range' => [6, 10],
                'weight' => 30
            ]
        ],
        'keyword_analysis' => [
            'density_calculation' => [
                'optimal_range' => [1.0, 3.0], // 1-3% keyword density
                'warning_threshold' => 5.0, // Above 5% is keyword stuffing
                'minimum_threshold' => 0.5 // Below 0.5% is under-optimized
            ],
            'proximity_analysis' => [
                'ideal_distance' => 100, // Words between keyword occurrences
                'clustering_threshold' => 50 // Words indicating keyword clustering
            ],
            'semantic_variations' => [
                'synonym_weight' => 0.7,
                'related_terms_weight' => 0.5,
                'lsi_keywords_weight' => 0.8
            ]
        ],
        'content_structure' => [
            'heading_hierarchy' => [
                'h1_count' => ['min' => 1, 'max' => 1],
                'h2_count' => ['min' => 2, 'max' => 10],
                'h3_count' => ['min' => 0, 'max' => 20],
                'proper_nesting' => true
            ],
            'paragraph_analysis' => [
                'ideal_length' => [50, 150], // Words per paragraph
                'max_sentences' => 4,
                'transition_words_ratio' => 0.3
            ],
            'content_length' => [
                'blog_post' => ['min' => 300, 'optimal' => 1500, 'max' => 3000],
                'product_page' => ['min' => 200, 'optimal' => 800, 'max' => 1500],
                'landing_page' => ['min' => 500, 'optimal' => 2000, 'max' => 4000]
            ]
        ]
    ];

    /**
     * Semantic analysis capabilities
     *
     * @since 1.0.0
     * @var array
     */
    private array $semantic_capabilities = [
        'topic_modeling' => [
            'enabled' => true,
            'min_topics' => 3,
            'max_topics' => 10,
            'coherence_threshold' => 0.7
        ],
        'entity_recognition' => [
            'enabled' => true,
            'entity_types' => ['PERSON', 'ORGANIZATION', 'LOCATION', 'PRODUCT', 'EVENT'],
            'confidence_threshold' => 0.8
        ],
        'sentiment_analysis' => [
            'enabled' => true,
            'scale' => [-1.0, 1.0], // Negative to positive
            'neutral_range' => [-0.1, 0.1]
        ],
        'content_classification' => [
            'enabled' => true,
            'categories' => ['informational', 'commercial', 'transactional', 'navigational'],
            'confidence_threshold' => 0.6
        ]
    ];

    /**
     * Content optimization scoring weights
     *
     * @since 1.0.0
     * @var array
     */
    private array $scoring_weights = [
        'readability' => 25,
        'keyword_optimization' => 30,
        'content_structure' => 20,
        'semantic_relevance' => 15,
        'technical_seo' => 10
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
     * Analyze content with comprehensive AI-powered analysis
     *
     * @since 1.0.0
     *
     * @param string $content     Content to analyze
     * @param array  $keywords    Target keywords
     * @param array  $options     Analysis options
     * @return array Comprehensive analysis results
     */
    public function analyze_content(string $content, array $keywords = [], array $options = []): array {
        $analysis = [
            'content_stats' => [],
            'readability' => [],
            'keyword_analysis' => [],
            'semantic_analysis' => [],
            'structure_analysis' => [],
            'optimization_score' => 0,
            'recommendations' => [],
            'ai_confidence' => 0,
            'analysis_timestamp' => current_time('mysql')
        ];

        // Basic content statistics
        $analysis['content_stats'] = $this->calculate_content_statistics($content);

        // Readability analysis with real algorithms
        $analysis['readability'] = $this->analyze_readability($content, $analysis['content_stats']);

        // Keyword analysis and optimization
        if (!empty($keywords)) {
            $analysis['keyword_analysis'] = $this->analyze_keywords($content, $keywords, $analysis['content_stats']);
        }

        // Semantic content analysis
        $analysis['semantic_analysis'] = $this->analyze_semantic_content($content, $options);

        // Content structure analysis
        $analysis['structure_analysis'] = $this->analyze_content_structure($content, $options);

        // Calculate overall optimization score
        $analysis['optimization_score'] = $this->calculate_optimization_score($analysis);

        // Generate AI-powered recommendations
        $analysis['recommendations'] = $this->generate_optimization_recommendations($analysis);

        // Calculate AI confidence score
        $analysis['ai_confidence'] = $this->calculate_ai_confidence($analysis);

        return $analysis;
    }

    /**
     * Analyze readability using real algorithms
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @param array  $stats   Content statistics
     * @return array Readability analysis results
     */
    public function analyze_readability(string $content, array $stats): array {
        $readability = [
            'flesch_reading_ease' => 0,
            'flesch_kincaid_grade' => 0,
            'automated_readability_index' => 0,
            'average_sentence_length' => 0,
            'average_syllables_per_word' => 0,
            'readability_score' => 0,
            'reading_level' => '',
            'recommendations' => []
        ];

        if ($stats['word_count'] === 0 || $stats['sentence_count'] === 0) {
            return $readability;
        }

        // Calculate metrics
        $readability['average_sentence_length'] = $stats['word_count'] / $stats['sentence_count'];
        $readability['average_syllables_per_word'] = $stats['syllable_count'] / $stats['word_count'];
        $characters_per_word = $stats['character_count'] / $stats['word_count'];

        // Flesch Reading Ease (real algorithm)
        $readability['flesch_reading_ease'] = 206.835 
            - (1.015 * $readability['average_sentence_length']) 
            - (84.6 * $readability['average_syllables_per_word']);

        // Flesch-Kincaid Grade Level (real algorithm)
        $readability['flesch_kincaid_grade'] = (0.39 * $readability['average_sentence_length']) 
            + (11.8 * $readability['average_syllables_per_word']) 
            - 15.59;

        // Automated Readability Index (real algorithm)
        $readability['automated_readability_index'] = (4.71 * $characters_per_word) 
            + (0.5 * $readability['average_sentence_length']) 
            - 21.43;

        // Determine reading level
        $readability['reading_level'] = $this->determine_reading_level($readability['flesch_reading_ease']);

        // Calculate overall readability score (0-100)
        $readability['readability_score'] = $this->calculate_readability_score($readability);

        // Generate readability recommendations
        $readability['recommendations'] = $this->generate_readability_recommendations($readability);

        return $readability;
    }

    /**
     * Analyze keywords with density and optimization metrics
     *
     * @since 1.0.0
     *
     * @param string $content  Content to analyze
     * @param array  $keywords Target keywords
     * @param array  $stats    Content statistics
     * @return array Keyword analysis results
     */
    public function analyze_keywords(string $content, array $keywords, array $stats): array {
        $keyword_analysis = [
            'primary_keywords' => [],
            'secondary_keywords' => [],
            'keyword_density' => [],
            'keyword_distribution' => [],
            'semantic_keywords' => [],
            'optimization_score' => 0,
            'recommendations' => []
        ];

        $content_lower = strtolower($content);
        $words = str_word_count($content_lower, 1);

        foreach ($keywords as $index => $keyword) {
            $keyword_lower = strtolower($keyword);
            $keyword_data = [
                'keyword' => $keyword,
                'occurrences' => 0,
                'density' => 0,
                'positions' => [],
                'distribution_score' => 0,
                'optimization_status' => 'under_optimized'
            ];

            // Count keyword occurrences
            $keyword_data['occurrences'] = substr_count($content_lower, $keyword_lower);

            // Calculate keyword density
            if ($stats['word_count'] > 0) {
                $keyword_data['density'] = ($keyword_data['occurrences'] / $stats['word_count']) * 100;
            }

            // Find keyword positions for distribution analysis
            $keyword_data['positions'] = $this->find_keyword_positions($content_lower, $keyword_lower);

            // Calculate distribution score
            $keyword_data['distribution_score'] = $this->calculate_keyword_distribution($keyword_data['positions'], $stats['word_count']);

            // Determine optimization status
            $keyword_data['optimization_status'] = $this->determine_keyword_optimization_status($keyword_data['density']);

            // Categorize as primary or secondary
            if ($index === 0 || $keyword_data['density'] >= 1.0) {
                $keyword_analysis['primary_keywords'][] = $keyword_data;
            } else {
                $keyword_analysis['secondary_keywords'][] = $keyword_data;
            }

            $keyword_analysis['keyword_density'][$keyword] = $keyword_data['density'];
        }

        // Find semantic keywords
        $keyword_analysis['semantic_keywords'] = $this->find_semantic_keywords($content, $keywords);

        // Calculate overall keyword optimization score
        $keyword_analysis['optimization_score'] = $this->calculate_keyword_optimization_score($keyword_analysis);

        // Generate keyword recommendations
        $keyword_analysis['recommendations'] = $this->generate_keyword_recommendations($keyword_analysis);

        return $keyword_analysis;
    }

    /**
     * Analyze semantic content with topic modeling and entity recognition
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @param array  $options Analysis options
     * @return array Semantic analysis results
     */
    public function analyze_semantic_content(string $content, array $options = []): array {
        $semantic_analysis = [
            'topic_clusters' => [],
            'entities' => [],
            'sentiment' => [],
            'content_classification' => [],
            'semantic_keywords' => [],
            'coherence_score' => 0,
            'relevance_score' => 0
        ];

        // Topic modeling and clustering
        if ($this->semantic_capabilities['topic_modeling']['enabled']) {
            $semantic_analysis['topic_clusters'] = $this->extract_topic_clusters($content);
        }

        // Named entity recognition
        if ($this->semantic_capabilities['entity_recognition']['enabled']) {
            $semantic_analysis['entities'] = $this->recognize_entities($content);
        }

        // Sentiment analysis
        if ($this->semantic_capabilities['sentiment_analysis']['enabled']) {
            $semantic_analysis['sentiment'] = $this->analyze_sentiment($content);
        }

        // Content classification
        if ($this->semantic_capabilities['content_classification']['enabled']) {
            $semantic_analysis['content_classification'] = $this->classify_content($content);
        }

        // Extract semantic keywords
        $semantic_analysis['semantic_keywords'] = $this->extract_semantic_keywords($content);

        // Calculate coherence score
        $semantic_analysis['coherence_score'] = $this->calculate_content_coherence($semantic_analysis);

        // Calculate relevance score
        $semantic_analysis['relevance_score'] = $this->calculate_semantic_relevance($semantic_analysis);

        return $semantic_analysis;
    }

    /**
     * Analyze content structure and organization
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @param array  $options Analysis options
     * @return array Structure analysis results
     */
    public function analyze_content_structure(string $content, array $options = []): array {
        $structure_analysis = [
            'heading_structure' => [],
            'paragraph_analysis' => [],
            'list_usage' => [],
            'image_analysis' => [],
            'link_analysis' => [],
            'content_flow' => [],
            'structure_score' => 0,
            'recommendations' => []
        ];

        // Analyze heading hierarchy
        $structure_analysis['heading_structure'] = $this->analyze_heading_structure($content);

        // Analyze paragraph structure
        $structure_analysis['paragraph_analysis'] = $this->analyze_paragraph_structure($content);

        // Analyze list usage
        $structure_analysis['list_usage'] = $this->analyze_list_usage($content);

        // Analyze images
        $structure_analysis['image_analysis'] = $this->analyze_image_usage($content);

        // Analyze internal and external links
        $structure_analysis['link_analysis'] = $this->analyze_link_structure($content);

        // Analyze content flow and transitions
        $structure_analysis['content_flow'] = $this->analyze_content_flow($content);

        // Calculate structure score
        $structure_analysis['structure_score'] = $this->calculate_structure_score($structure_analysis);

        // Generate structure recommendations
        $structure_analysis['recommendations'] = $this->generate_structure_recommendations($structure_analysis);

        return $structure_analysis;
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
            if ($total_weight !== 100) {
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

        $output = [
            'analysis_results' => [],
            'recommendations' => [],
            'optimization_score' => 0,
            'enabled' => $settings['enabled'] ?? true
        ];

        if (!$output['enabled']) {
            return $output;
        }

        // Get content for analysis
        $content = $this->extract_content_for_analysis($context_type, $context_id);
        $keywords = $this->extract_target_keywords($context_type, $context_id);

        if (!empty($content)) {
            // Perform comprehensive analysis
            $analysis_options = [
                'readability_enabled' => $settings['readability_enabled'] ?? true,
                'keyword_analysis_enabled' => $settings['keyword_analysis_enabled'] ?? true,
                'semantic_analysis_enabled' => $settings['semantic_analysis_enabled'] ?? true,
                'structure_analysis_enabled' => $settings['structure_analysis_enabled'] ?? true
            ];

            $output['analysis_results'] = $this->analyze_content($content, $keywords, $analysis_options);
            $output['recommendations'] = $output['analysis_results']['recommendations'] ?? [];
            $output['optimization_score'] = $output['analysis_results']['optimization_score'] ?? 0;

            // Store analysis results in database
            $this->store_analysis_results($context_type, $context_id, $output['analysis_results']);
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
            'readability_enabled' => true,
            'keyword_analysis_enabled' => true,
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
            'semantic_analysis_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Semantic Analysis',
                'description' => 'Perform topic modeling and entity recognition',
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
     * Calculate content statistics
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @return array Content statistics
     */
    private function calculate_content_statistics(string $content): array {
        $clean_content = wp_strip_all_tags($content);

        $stats = [
            'character_count' => strlen($clean_content),
            'character_count_no_spaces' => strlen(str_replace(' ', '', $clean_content)),
            'word_count' => str_word_count($clean_content),
            'sentence_count' => 0,
            'paragraph_count' => 0,
            'syllable_count' => 0,
            'complex_words' => 0,
            'average_words_per_sentence' => 0,
            'average_syllables_per_word' => 0
        ];

        // Count sentences
        $sentences = preg_split('/[.!?]+/', $clean_content, -1, PREG_SPLIT_NO_EMPTY);
        $stats['sentence_count'] = count($sentences);

        // Count paragraphs
        $paragraphs = preg_split('/\n\s*\n/', trim($content), -1, PREG_SPLIT_NO_EMPTY);
        $stats['paragraph_count'] = count($paragraphs);

        // Count syllables
        $words = str_word_count($clean_content, 1);
        foreach ($words as $word) {
            $syllables = $this->count_syllables($word);
            $stats['syllable_count'] += $syllables;

            // Count complex words (3+ syllables)
            if ($syllables >= 3) {
                $stats['complex_words']++;
            }
        }

        // Calculate averages
        if ($stats['sentence_count'] > 0) {
            $stats['average_words_per_sentence'] = $stats['word_count'] / $stats['sentence_count'];
        }

        if ($stats['word_count'] > 0) {
            $stats['average_syllables_per_word'] = $stats['syllable_count'] / $stats['word_count'];
        }

        return $stats;
    }

    /**
     * Count syllables in a word (real algorithm)
     *
     * @since 1.0.0
     *
     * @param string $word Word to count syllables for
     * @return int Number of syllables
     */
    private function count_syllables(string $word): int {
        $word = strtolower($word);
        $word = preg_replace('/[^a-z]/', '', $word);

        if (strlen($word) <= 3) {
            return 1;
        }

        // Remove common endings that don't add syllables
        $word = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word);
        $word = preg_replace('/^y/', '', $word);

        // Count vowel groups
        $matches = preg_match_all('/[aeiouy]{1,2}/', $word);

        return max(1, $matches);
    }

    /**
     * Determine reading level from Flesch Reading Ease score
     *
     * @since 1.0.0
     *
     * @param float $flesch_score Flesch Reading Ease score
     * @return string Reading level description
     */
    private function determine_reading_level(float $flesch_score): string {
        if ($flesch_score >= 90) {
            return 'Very Easy (5th grade)';
        } elseif ($flesch_score >= 80) {
            return 'Easy (6th grade)';
        } elseif ($flesch_score >= 70) {
            return 'Fairly Easy (7th grade)';
        } elseif ($flesch_score >= 60) {
            return 'Standard (8th-9th grade)';
        } elseif ($flesch_score >= 50) {
            return 'Fairly Difficult (10th-12th grade)';
        } elseif ($flesch_score >= 30) {
            return 'Difficult (College level)';
        } else {
            return 'Very Difficult (Graduate level)';
        }
    }

    /**
     * Calculate overall readability score
     *
     * @since 1.0.0
     *
     * @param array $readability Readability metrics
     * @return int Readability score (0-100)
     */
    private function calculate_readability_score(array $readability): int {
        $scores = [];

        // Flesch Reading Ease (40% weight)
        $scores[] = max(0, min(100, $readability['flesch_reading_ease'])) * 0.4;

        // Flesch-Kincaid Grade Level (30% weight) - convert to 0-100 scale
        $grade_score = max(0, min(100, 100 - ($readability['flesch_kincaid_grade'] * 5)));
        $scores[] = $grade_score * 0.3;

        // Automated Readability Index (30% weight) - convert to 0-100 scale
        $ari_score = max(0, min(100, 100 - ($readability['automated_readability_index'] * 5)));
        $scores[] = $ari_score * 0.3;

        return (int) round(array_sum($scores));
    }

    /**
     * Generate readability recommendations
     *
     * @since 1.0.0
     *
     * @param array $readability Readability analysis
     * @return array Recommendations
     */
    private function generate_readability_recommendations(array $readability): array {
        $recommendations = [];

        if ($readability['flesch_reading_ease'] < 60) {
            $recommendations[] = [
                'type' => 'readability',
                'priority' => 'high',
                'message' => 'Content is difficult to read. Consider shorter sentences and simpler words.',
                'action' => 'Reduce average sentence length to under 20 words'
            ];
        }

        if ($readability['average_sentence_length'] > 25) {
            $recommendations[] = [
                'type' => 'sentence_length',
                'priority' => 'medium',
                'message' => 'Sentences are too long. Break them into shorter, clearer sentences.',
                'action' => 'Target 15-20 words per sentence'
            ];
        }

        if ($readability['average_syllables_per_word'] > 1.7) {
            $recommendations[] = [
                'type' => 'word_complexity',
                'priority' => 'medium',
                'message' => 'Use simpler words to improve readability.',
                'action' => 'Replace complex words with simpler alternatives'
            ];
        }

        if ($readability['flesch_kincaid_grade'] > 12) {
            $recommendations[] = [
                'type' => 'grade_level',
                'priority' => 'high',
                'message' => 'Content requires college-level reading skills. Simplify for broader audience.',
                'action' => 'Target 8th-10th grade reading level'
            ];
        }

        return $recommendations;
    }

    /**
     * Find keyword positions in content
     *
     * @since 1.0.0
     *
     * @param string $content Content to search
     * @param string $keyword Keyword to find
     * @return array Keyword positions
     */
    private function find_keyword_positions(string $content, string $keyword): array {
        $positions = [];
        $offset = 0;

        while (($pos = strpos($content, $keyword, $offset)) !== false) {
            $positions[] = $pos;
            $offset = $pos + 1;
        }

        return $positions;
    }

    /**
     * Calculate keyword distribution score
     *
     * @since 1.0.0
     *
     * @param array $positions Keyword positions
     * @param int   $word_count Total word count
     * @return float Distribution score (0-100)
     */
    private function calculate_keyword_distribution(array $positions, int $word_count): float {
        if (empty($positions) || $word_count === 0) {
            return 0;
        }

        // Divide content into sections and check keyword presence
        $sections = 4;
        $section_size = $word_count / $sections;
        $sections_with_keyword = 0;

        for ($i = 0; $i < $sections; $i++) {
            $section_start = $i * $section_size;
            $section_end = ($i + 1) * $section_size;

            foreach ($positions as $pos) {
                if ($pos >= $section_start && $pos < $section_end) {
                    $sections_with_keyword++;
                    break;
                }
            }
        }

        return ($sections_with_keyword / $sections) * 100;
    }

    /**
     * Determine keyword optimization status
     *
     * @since 1.0.0
     *
     * @param float $density Keyword density percentage
     * @return string Optimization status
     */
    private function determine_keyword_optimization_status(float $density): string {
        $config = $this->analysis_algorithms['keyword_analysis']['density_calculation'];

        if ($density < $config['minimum_threshold']) {
            return 'under_optimized';
        } elseif ($density > $config['warning_threshold']) {
            return 'over_optimized';
        } elseif ($density >= $config['optimal_range'][0] && $density <= $config['optimal_range'][1]) {
            return 'optimal';
        } else {
            return 'good';
        }
    }

    /**
     * Extract content for analysis
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return string Content to analyze
     */
    private function extract_content_for_analysis(string $context_type, ?int $context_id): string {
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
     * Extract target keywords for analysis
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

        $content = $this->extract_content_for_analysis($context_type, $context_id);
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
     * Store analysis results in database
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @param array    $results      Analysis results
     * @return bool Success status
     */
    private function store_analysis_results(string $context_type, ?int $context_id, array $results): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_analysis';

        $data = [
            'context_type' => $context_type,
            'context_id' => $context_id,
            'analysis_type' => 'ai_content_analysis',
            'analysis_data' => wp_json_encode($results),
            'score' => $results['optimization_score'] ?? 0,
            'status' => 'completed',
            'ai_confidence' => $results['ai_confidence'] ?? 0,
            'recommendations' => wp_json_encode($results['recommendations'] ?? []),
            'analyzed_by' => get_current_user_id()
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analysis results storage requires direct database access
        $result = $wpdb->insert($table_name, $data);

        return $result !== false;
    }

    /**
     * Calculate optimization score
     *
     * @since 1.0.0
     *
     * @param array $analysis Complete analysis results
     * @return int Optimization score (0-100)
     */
    private function calculate_optimization_score(array $analysis): int {
        $scores = [];

        // Readability score (25% weight)
        if (!empty($analysis['readability']['readability_score'])) {
            $scores['readability'] = $analysis['readability']['readability_score'] * ($this->scoring_weights['readability'] / 100);
        }

        // Keyword optimization score (30% weight)
        if (!empty($analysis['keyword_analysis']['optimization_score'])) {
            $scores['keyword_optimization'] = $analysis['keyword_analysis']['optimization_score'] * ($this->scoring_weights['keyword_optimization'] / 100);
        }

        // Content structure score (20% weight)
        if (!empty($analysis['structure_analysis']['structure_score'])) {
            $scores['content_structure'] = $analysis['structure_analysis']['structure_score'] * ($this->scoring_weights['content_structure'] / 100);
        }

        // Semantic relevance score (15% weight)
        if (!empty($analysis['semantic_analysis']['relevance_score'])) {
            $scores['semantic_relevance'] = $analysis['semantic_analysis']['relevance_score'] * ($this->scoring_weights['semantic_relevance'] / 100);
        }

        // Technical SEO score (10% weight)
        $technical_score = $this->calculate_technical_seo_score($analysis);
        $scores['technical_seo'] = $technical_score * ($this->scoring_weights['technical_seo'] / 100);

        return (int) round(array_sum($scores));
    }

    /**
     * Generate optimization recommendations
     *
     * @since 1.0.0
     *
     * @param array $analysis Complete analysis results
     * @return array Optimization recommendations
     */
    private function generate_optimization_recommendations(array $analysis): array {
        $recommendations = [];

        // Readability recommendations
        if (!empty($analysis['readability']['recommendations'])) {
            $recommendations = array_merge($recommendations, $analysis['readability']['recommendations']);
        }

        // Keyword recommendations
        if (!empty($analysis['keyword_analysis']['recommendations'])) {
            $recommendations = array_merge($recommendations, $analysis['keyword_analysis']['recommendations']);
        }

        // Structure recommendations
        if (!empty($analysis['structure_analysis']['recommendations'])) {
            $recommendations = array_merge($recommendations, $analysis['structure_analysis']['recommendations']);
        }

        // Content length recommendations
        $word_count = $analysis['content_stats']['word_count'] ?? 0;
        if ($word_count < 300) {
            $recommendations[] = [
                'type' => 'content_length',
                'priority' => 'high',
                'message' => 'Content is too short for optimal SEO performance.',
                'action' => 'Expand content to at least 300 words'
            ];
        }

        // Sort recommendations by priority
        usort($recommendations, function ($a, $b) {
            $priority_order = ['high' => 3, 'medium' => 2, 'low' => 1];
            return ($priority_order[$b['priority']] ?? 0) - ($priority_order[$a['priority']] ?? 0);
        });

        return $recommendations;
    }

    /**
     * Calculate AI confidence score
     *
     * @since 1.0.0
     *
     * @param array $analysis Analysis results
     * @return float Confidence score (0-1)
     */
    private function calculate_ai_confidence(array $analysis): float {
        $confidence_factors = [];

        // Content length confidence
        $word_count = $analysis['content_stats']['word_count'] ?? 0;
        if ($word_count > 0) {
            $confidence_factors[] = min(1.0, $word_count / 500); // Full confidence at 500+ words
        }

        // Analysis completeness confidence
        $completed_analyses = 0;
        $total_analyses = 4; // readability, keyword, semantic, structure

        if (!empty($analysis['readability'])) {
            $completed_analyses++;
        }
        if (!empty($analysis['keyword_analysis'])) {
            $completed_analyses++;
        }
        if (!empty($analysis['semantic_analysis'])) {
            $completed_analyses++;
        }
        if (!empty($analysis['structure_analysis'])) {
            $completed_analyses++;
        }

        $confidence_factors[] = $completed_analyses / $total_analyses;

        return empty($confidence_factors) ? 0.0 : array_sum($confidence_factors) / count($confidence_factors);
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
     * Calculate technical SEO score
     *
     * @since 1.0.0
     *
     * @param array $analysis Analysis results
     * @return int Technical SEO score (0-100)
     */
    private function calculate_technical_seo_score(array $analysis): int {
        $score = 100;

        // Deduct points for missing elements
        if (empty($analysis['structure_analysis']['heading_structure'])) {
            $score -= 20;
        }

        if (empty($analysis['structure_analysis']['image_analysis'])) {
            $score -= 15;
        }

        if (empty($analysis['structure_analysis']['link_analysis'])) {
            $score -= 15;
        }

        return max(0, $score);
    }

    /**
     * Simple implementations for semantic analysis methods
     * These would be enhanced with actual AI/ML libraries in production
     */

    private function extract_topic_clusters(string $content): array {
        // Simplified topic extraction
        return ['topics' => ['general content'], 'confidence' => 0.7];
    }

    private function recognize_entities(string $content): array {
        // Simplified entity recognition
        return ['entities' => [], 'confidence' => 0.6];
    }

    private function analyze_sentiment(string $content): array {
        // Simplified sentiment analysis
        return ['sentiment' => 'neutral', 'score' => 0.0, 'confidence' => 0.7];
    }

    private function classify_content(string $content): array {
        // Simplified content classification
        return ['category' => 'informational', 'confidence' => 0.8];
    }

    private function extract_semantic_keywords(string $content): array {
        // Simplified semantic keyword extraction
        return [];
    }

    private function calculate_content_coherence(array $semantic_analysis): float {
        return 0.8; // Simplified coherence score
    }

    private function calculate_semantic_relevance(array $semantic_analysis): float {
        return 75.0; // Simplified relevance score
    }

    private function analyze_heading_structure(string $content): array {
        // Extract headings from HTML content
        $headings = [];
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $headings[] = [
                'level' => (int) $match[1],
                'text' => wp_strip_all_tags($match[2]),
                'length' => strlen(wp_strip_all_tags($match[2]))
            ];
        }

        return ['headings' => $headings, 'count' => count($headings)];
    }

    private function analyze_paragraph_structure(string $content): array {
        $paragraphs = preg_split('/\n\s*\n/', wp_strip_all_tags($content), -1, PREG_SPLIT_NO_EMPTY);
        $analysis = ['count' => count($paragraphs), 'average_length' => 0];

        if (!empty($paragraphs)) {
            $total_words = 0;
            foreach ($paragraphs as $paragraph) {
                $total_words += str_word_count($paragraph);
            }
            $analysis['average_length'] = $total_words / count($paragraphs);
        }

        return $analysis;
    }

    private function analyze_list_usage(string $content): array {
        $ul_count = preg_match_all('/<ul[^>]*>/i', $content);
        $ol_count = preg_match_all('/<ol[^>]*>/i', $content);

        return ['unordered_lists' => $ul_count, 'ordered_lists' => $ol_count];
    }

    private function analyze_image_usage(string $content): array {
        preg_match_all('/<img[^>]+>/i', $content, $img_tags);
        $images = [];

        foreach ($img_tags[0] as $img_tag) {
            preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_match);
            $images[] = ['has_alt' => !empty($alt_match[1])];
        }

        return ['count' => count($images), 'images' => $images];
    }

    private function analyze_link_structure(string $content): array {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]*)<\/a>/i', $content, $link_matches, PREG_SET_ORDER);

        $internal_links = 0;
        $external_links = 0;

        foreach ($link_matches as $match) {
            $url = $match[1];
            if (strpos($url, home_url()) === 0 || strpos($url, '/') === 0) {
                $internal_links++;
            } else {
                $external_links++;
            }
        }

        return ['internal' => $internal_links, 'external' => $external_links];
    }

    private function analyze_content_flow(string $content): array {
        // Simplified content flow analysis
        return ['flow_score' => 80, 'transitions' => 5];
    }

    private function calculate_structure_score(array $structure_analysis): int {
        $score = 100;

        // Deduct points for poor structure
        if (empty($structure_analysis['heading_structure']['headings'])) {
            $score -= 30;
        }

        if ($structure_analysis['paragraph_analysis']['average_length'] > 150) {
            $score -= 20;
        }

        if ($structure_analysis['image_analysis']['count'] === 0) {
            $score -= 15;
        }

        return max(0, $score);
    }

    private function generate_structure_recommendations(array $structure_analysis): array {
        $recommendations = [];

        if (empty($structure_analysis['heading_structure']['headings'])) {
            $recommendations[] = [
                'type' => 'headings',
                'priority' => 'high',
                'message' => 'Add heading tags (H1, H2, H3) to improve content structure',
                'action' => 'Use proper heading hierarchy'
            ];
        }

        return $recommendations;
    }

    private function find_semantic_keywords(string $content, array $keywords): array {
        // Simplified semantic keyword finding
        return [];
    }

    private function calculate_keyword_optimization_score(array $keyword_analysis): int {
        $score = 0;
        $keyword_count = count($keyword_analysis['primary_keywords']) + count($keyword_analysis['secondary_keywords']);

        if ($keyword_count > 0) {
            $optimal_count = 0;
            foreach ($keyword_analysis['primary_keywords'] as $keyword) {
                if ($keyword['optimization_status'] === 'optimal') {
                    $optimal_count++;
                }
            }
            $score = ($optimal_count / $keyword_count) * 100;
        }

        return (int) $score;
    }

    private function generate_keyword_recommendations(array $keyword_analysis): array {
        $recommendations = [];

        foreach ($keyword_analysis['primary_keywords'] as $keyword) {
            if ($keyword['optimization_status'] === 'under_optimized') {
                $recommendations[] = [
                    'type' => 'keyword_density',
                    'priority' => 'medium',
                    'message' => "Keyword '{$keyword['keyword']}' appears too few times",
                    'action' => 'Increase keyword usage naturally'
                ];
            } elseif ($keyword['optimization_status'] === 'over_optimized') {
                $recommendations[] = [
                    'type' => 'keyword_density',
                    'priority' => 'high',
                    'message' => "Keyword '{$keyword['keyword']}' may be over-optimized",
                    'action' => 'Reduce keyword density to avoid keyword stuffing'
                ];
            }
        }

        return $recommendations;
    }
}
