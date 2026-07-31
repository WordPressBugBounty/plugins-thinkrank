<?php
/**
 * LLMs.txt API Endpoints Class
 *
 * REST API endpoints for LLMs.txt file management including content generation,
 * AI-powered optimization, file writing, and status monitoring. Provides
 * comprehensive API access to LLMs.txt Manager and AI Manager functionality
 * with proper authentication, validation, and error handling.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\LLMs_Txt_Manager;
use ThinkRank\AI\Manager as AI_Manager;
use ThinkRank\API\Traits\CSRF_Protection;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load CSRF Protection trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-csrf-protection.php';

/**
 * LLMs.txt API Endpoints Class
 *
 * Provides REST API endpoints for LLMs.txt operations including
 * content generation, AI-powered optimization, file management,
 * and status monitoring with proper authentication and validation.
 *
 * @since 1.0.0
 */
class LLMs_Txt_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * LLMs.txt Manager instance
     *
     * @since 1.0.0
     * @var LLMs_Txt_Manager
     */
    private LLMs_Txt_Manager $llms_txt_manager;

    /**
     * API namespace
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'llms-txt';

    /**
     * AI Manager instance
     *
     * @since 1.0.0
     * @var AI_Manager|null
     */
    private ?AI_Manager $ai_manager = null;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Ensure LLMs.txt Manager is loaded
        if (!class_exists('ThinkRank\\SEO\\LLMs_Txt_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-llms-txt-manager.php';
        }

        $this->llms_txt_manager = new LLMs_Txt_Manager();
    }

    /**
     * Get AI Manager instance
     *
     * @since 1.0.0
     *
     * @return AI_Manager AI Manager instance
     * @throws \Exception If AI Manager cannot be initialized
     */
    private function get_ai_manager(): AI_Manager {
        if (!$this->ai_manager) {
            // Ensure AI Manager is loaded
            if (!class_exists('ThinkRank\\AI\\Manager')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/class-manager.php';
            }

            $this->ai_manager = new AI_Manager();
        }

        return $this->ai_manager;
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Get/Update LLMs.txt settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_permissions']
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_settings_args()
                ]
            ]
        );

        // Generate LLMs.txt file
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/generate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_llms_txt'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_generation_args()
                ]
            ]
        );

        // AI-powered LLMs.txt optimization
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ai-optimize',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'ai_optimize_llms_txt'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_ai_optimization_args()
                ]
            ]
        );

        // Get LLMs.txt file status
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/status',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_llms_txt_status'],
                    'permission_callback' => [$this, 'check_permissions']
                ]
            ]
        );

        // Validate LLMs.txt settings (read-only validation, no CSRF needed)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/validate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'validate_llms_txt_settings'],
                    // Require thinkrank_crawling like every other llms-txt route
                    // (was 'read', which let any subscriber hit this admin tool).
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_validation_args()
                ]
            ]
        );

        // Get latest optimization results
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/optimization-results',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_optimization_results'],
                    'permission_callback' => [$this, 'check_permissions']
                ]
            ]
        );

        // Get overview data (combined endpoint for performance)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/overview',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_overview_data'],
                    'permission_callback' => [$this, 'check_permissions']
                ]
            ]
        );
    }

    /**
     * Get LLMs.txt settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id');

            // Get settings from LLMs.txt Manager
            $settings = $this->llms_txt_manager->get_settings($context_type, $context_id);

            // Get settings schema for validation
            $schema = $this->llms_txt_manager->get_settings_schema($context_type);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'schema' => $schema,
                    'context_type' => $context_type,
                    'context_id' => $context_id
                ],
                'message' => 'LLMs.txt settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve LLMs.txt settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update LLMs.txt settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_settings(WP_REST_Request $request) {
        try {
            $settings = $request->get_param('settings');
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id');

            // Validate settings
            if (empty($settings) || !is_array($settings)) {
                return new WP_Error(
                    'invalid_settings',
                    'Settings must be provided as an array',
                    ['status' => 400]
                );
            }

            // Validate settings using LLMs.txt Manager
            $validation = $this->llms_txt_manager->validate_settings($settings);
            
            if (!$validation['valid']) {
                return new WP_Error(
                    'validation_failed',
                    'Settings validation failed',
                    [
                        'status' => 400,
                        'validation_errors' => $validation['errors'],
                        'validation_warnings' => $validation['warnings']
                    ]
                );
            }

            // Update settings
            $update_result = $this->llms_txt_manager->save_settings($context_type, $context_id, $settings);

            if (!$update_result) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update LLMs.txt settings',
                    ['status' => 500]
                );
            }

            // The settings persisted, but a disable-save may have failed to remove
            // the published llms.txt file. Report that explicitly so the caller
            // knows /llms.txt might still be served rather than assuming success.
            if ($this->llms_txt_manager->unpublish_failed()) {
                return new WP_REST_Response([
                    'success' => true,
                    'file_unpublished' => false,
                    'data' => [
                        'settings' => $settings,
                        'validation' => $validation
                    ],
                    'message' => 'Settings were saved, but the published llms.txt file could not be removed and may still be served. Please remove it manually.'
                ], 200);
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'validation' => $validation
                ],
                'message' => 'LLMs.txt settings updated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'update_failed',
                'Failed to update LLMs.txt settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Generate LLMs.txt file
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_llms_txt(WP_REST_Request $request) {
        try {
            // Check rate limiting
            if (!$this->check_llms_rate_limit()) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    'Too many requests. Please wait a few minutes before trying again.',
                    ['status' => 429]
                );
            }
            $user_input = $request->get_param('user_input');
            $options = $request->get_param('options') ?? [];
            $save_to_file = $request->get_param('save_to_file') ?? true;

            // Validate user input
            if (empty($user_input) || !is_array($user_input)) {
                return new WP_Error(
                    'invalid_input',
                    'User input must be provided as an array',
                    ['status' => 400]
                );
            }

            // Validate required fields
            $required_fields = ['website_description', 'key_features', 'target_audience'];
            foreach ($required_fields as $field) {
                if (empty($user_input[$field])) {
                    return new WP_Error(
                        'missing_field',
                        "Required field '{$field}' is missing or empty",
                        ['status' => 400]
                    );
                }
            }

            // Sanitize user input
            $sanitized_input = [
                'site_name' => sanitize_text_field($user_input['site_name'] ?? ''),
                'website_description' => sanitize_textarea_field($user_input['website_description']),
                'key_features' => sanitize_textarea_field($user_input['key_features']),
                'target_audience' => sanitize_text_field($user_input['target_audience']),
                'business_type' => sanitize_text_field($user_input['business_type'] ?? 'website'),
                'technical_stack' => sanitize_textarea_field($user_input['technical_stack'] ?? ''),
                'development_approach' => sanitize_textarea_field($user_input['development_approach'] ?? ''),
                'setup_instructions' => sanitize_textarea_field($user_input['setup_instructions'] ?? ''),
                'ai_context_custom' => sanitize_textarea_field($user_input['ai_context_custom'] ?? ''),
                'documentation_links' => $this->llms_txt_manager->sanitize_llms_content($user_input['documentation_links'] ?? ''),
                'technical_links' => $this->llms_txt_manager->sanitize_llms_content($user_input['technical_links'] ?? ''),
                'optional_links' => $this->llms_txt_manager->sanitize_llms_content($user_input['optional_links'] ?? ''),
                'custom_sections' => $this->llms_txt_manager->sanitize_llms_content($user_input['custom_sections'] ?? '')
            ];

            // Generate LLMs.txt using LLMs.txt Manager
            $generation_result = $this->llms_txt_manager->generate_llms_txt($sanitized_input, $options);

            if (!$generation_result['validation']['valid']) {
                return new WP_Error(
                    'generation_failed',
                    'LLMs.txt generation validation failed',
                    [
                        'status' => 400,
                        'validation_errors' => $generation_result['validation']['errors']
                    ]
                );
            }

            // Write to file if requested
            $file_write_result = ['success' => false, 'message' => 'File writing disabled'];
            if ($save_to_file && !empty($generation_result['content'])) {
                $file_write_result = $this->llms_txt_manager->write_llms_txt_to_file(
                    $generation_result['content']
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'content' => $generation_result['content'],
                    'sections' => $generation_result['sections'],
                    'metadata' => $generation_result['metadata'],
                    'validation' => $generation_result['validation'],
                    'file_info' => $generation_result['file_info'],
                    'file_write_result' => $file_write_result
                ],
                'message' => 'LLMs.txt generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'generation_failed',
                'LLMs.txt generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * AI optimize LLMs.txt content
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function ai_optimize_llms_txt(WP_REST_Request $request) {
        try {
            // Check rate limiting for AI operations
            if (!$this->check_ai_rate_limit()) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    'Too many AI requests. Please wait a few minutes before trying again.',
                    ['status' => 429]
                );
            }

            $website_data = $request->get_param('website_data');
            $options = [
                'business_type' => $request->get_param('business_type'),
                'target_audience' => $request->get_param('target_audience'),
                'tone' => $request->get_param('tone')
            ];

            // Validate website data
            if (empty($website_data) || !is_array($website_data)) {
                return new WP_Error(
                    'invalid_data',
                    'Website data must be provided as an array',
                    ['status' => 400]
                );
            }

            // Validate required website data fields
            $required_fields = ['website_description', 'key_features'];
            foreach ($required_fields as $field) {
                if (empty($website_data[$field])) {
                    return new WP_Error(
                        'missing_field',
                        "Required field '{$field}' is missing or empty",
                        ['status' => 400]
                    );
                }
            }

            // Sanitize website data
            $sanitized_website_data = [
                'website_description' => sanitize_textarea_field($website_data['website_description']),
                'key_features' => sanitize_textarea_field($website_data['key_features']),
                'target_audience' => sanitize_text_field($website_data['target_audience'] ?? ''),
                'business_type' => sanitize_text_field($website_data['business_type'] ?? 'website'),
                'technical_stack' => sanitize_textarea_field($website_data['technical_stack'] ?? ''),
                'development_approach' => sanitize_textarea_field($website_data['development_approach'] ?? '')
            ];

            // Sanitize options
            $sanitized_options = [
                'business_type' => sanitize_text_field($options['business_type'] ?? 'website'),
                'target_audience' => sanitize_text_field($options['target_audience'] ?? 'general'),
                'tone' => sanitize_text_field($options['tone'] ?? 'professional')
            ];

            // Get AI manager and perform optimization
            $ai_manager = $this->get_ai_manager();
            $optimization_results = $ai_manager->optimize_llms_txt($sanitized_website_data, $sanitized_options);

            // Store optimization results in database (following Site Identity pattern)
            $this->store_optimization_results($optimization_results);

            return new WP_REST_Response([
                'success' => true,
                'data' => $optimization_results,
                'message' => 'LLMs.txt AI optimization completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'ai_optimization_failed',
                'AI optimization failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Store optimization results in seo_analysis table (following Site Identity pattern)
     *
     * @since 1.0.0
     *
     * @param array $optimization Optimization results
     * @return void
     */
    private function store_optimization_results(array $optimization): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_analysis';

        // Only store if we have meaningful results
        if (empty($optimization['suggestions'])) {
            return;
        }

        $analysis_type = 'llms_txt_ai_optimization';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO analysis storage requires direct database access
        $wpdb->insert(
            $table_name,
            [
                'context_type' => 'site',
                'context_id' => null,
                'analysis_type' => $analysis_type,
                'analysis_data' => wp_json_encode($optimization),
                'score' => $optimization['score'] ?? 0,
                'status' => 'completed',
                'recommendations' => wp_json_encode($optimization['suggestions'] ?? []),
                'analyzed_by' => get_current_user_id()
            ],
            ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d']
        );
    }

    /**
     * Get optimization results history (Content Brief style)
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_optimization_results(WP_REST_Request $request) {
        try {
            global $wpdb;

            $table_name = $wpdb->prefix . 'thinkrank_seo_analysis';
            $limit = $request->get_param('limit') ?? 5; // Default to 5 recent results

            // Get recent LLMs.txt optimization results
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO analysis retrieval requires direct database access
            $sql = "SELECT analysis_data, recommendations, score, created_at
                     FROM {$table_name}
                     WHERE analysis_type = %s AND context_type = %s
                     ORDER BY created_at DESC
                     LIMIT %d";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analysis data retrieval requires direct database access
            $results = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is validated with WordPress prefix, SQL is properly prepared
                $wpdb->prepare($sql, 'llms_txt_ai_optimization', 'site', $limit)
            );

            if (!$results) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [],
                    'message' => 'No optimization results found'
                ], 200);
            }

            $history = [];
            foreach ($results as $result) {
                $analysis_data = json_decode($result->analysis_data, true);
                $suggestions = json_decode($result->recommendations, true);

                $history[] = [
                    'suggestions' => $suggestions,
                    'provider' => $analysis_data['provider'] ?? '',
                    'model' => $analysis_data['model'] ?? $analysis_data['ai_model'] ?? '',
                    'score' => $result->score,
                    'created_at' => $result->created_at,
                    'formatted_date' => wp_date('M j, Y g:i A', strtotime($result->created_at))
                ];
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $history,
                'message' => 'Optimization results history retrieved'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'optimization_results_failed',
                'Failed to retrieve optimization results: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get LLMs.txt file status
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_llms_txt_status(WP_REST_Request $request): WP_REST_Response {
        try {
            // Get file status using LLMs.txt Manager
            $status = $this->llms_txt_manager->get_llms_txt_status();

            return new WP_REST_Response([
                'success' => true,
                'data' => $status,
                'message' => 'LLMs.txt status retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve LLMs.txt status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate LLMs.txt settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function validate_llms_txt_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $settings = $request->get_param('settings');

            // Sanitize user input for validation
            $sanitized_input = [
                'site_name' => sanitize_text_field($settings['site_name'] ?? ''),
                'website_description' => sanitize_textarea_field($settings['website_description'] ?? ''),
                'key_features' => sanitize_textarea_field($settings['key_features'] ?? ''),
                'target_audience' => sanitize_text_field($settings['target_audience'] ?? ''),
                'business_type' => sanitize_text_field($settings['business_type'] ?? 'website'),
                'technical_stack' => sanitize_textarea_field($settings['technical_stack'] ?? ''),
                'development_approach' => sanitize_textarea_field($settings['development_approach'] ?? ''),
                'setup_instructions' => sanitize_textarea_field($settings['setup_instructions'] ?? ''),
                'documentation_links' => $this->llms_txt_manager->sanitize_llms_content($settings['documentation_links'] ?? ''),
                'technical_links' => $this->llms_txt_manager->sanitize_llms_content($settings['technical_links'] ?? ''),
                'optional_links' => $this->llms_txt_manager->sanitize_llms_content($settings['optional_links'] ?? ''),
                'custom_sections' => $this->llms_txt_manager->sanitize_llms_content($settings['custom_sections'] ?? '')
            ];

            // Validate user input using LLMs.txt Manager
            $validation = $this->llms_txt_manager->validate_llms_txt_input($sanitized_input);

            return new WP_REST_Response([
                'success' => true,
                'data' => $validation,
                'message' => 'LLMs.txt validation completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Validation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permission callbacks
     */

    /**
     * Check permissions for LLMs.txt operations (admin-only)
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_crawling');
    }
    private function get_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'LLMs.txt settings to update'
            ],
            'context_type' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['site'],
                'default' => 'site',
                'description' => 'Context type (site only)'
            ],
            'context_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Context ID (not required for site context)'
            ]
        ];
    }

    /**
     * Get arguments for generation endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_generation_args(): array {
        return [
            'user_input' => [
                'required' => true,
                'type' => 'object',
                'description' => 'User input data for LLMs.txt generation',
                'properties' => [
                    'website_description' => [
                        'type' => 'string',
                        'description' => 'Website description'
                    ],
                    'key_features' => [
                        'type' => 'string',
                        'description' => 'Key website features'
                    ],
                    'target_audience' => [
                        'type' => 'string',
                        'description' => 'Target audience'
                    ],
                    'business_type' => [
                        'type' => 'string',
                        'description' => 'Business type'
                    ],
                    'technical_stack' => [
                        'type' => 'string',
                        'description' => 'Technical stack information'
                    ]
                ]
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Generation options'
            ],
            'save_to_file' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to save generated content to file'
            ]
        ];
    }

    /**
     * Get arguments for AI optimization endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_ai_optimization_args(): array {
        return [
            'website_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Website data to optimize with AI',
                'properties' => [
                    'website_description' => [
                        'type' => 'string',
                        'description' => 'Website description to optimize'
                    ],
                    'key_features' => [
                        'type' => 'string',
                        'description' => 'Key features to optimize'
                    ],
                    'target_audience' => [
                        'type' => 'string',
                        'description' => 'Target audience information'
                    ],
                    'business_type' => [
                        'type' => 'string',
                        'description' => 'Business type'
                    ]
                ]
            ],
            'business_type' => [
                'required' => false,
                'type' => 'string',
                'default' => 'website',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'Type of business for context'
            ],
            'target_audience' => [
                'required' => false,
                'type' => 'string',
                'default' => 'general',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'Target audience for optimization'
            ],
            'tone' => [
                'required' => false,
                'type' => 'string',
                'default' => 'professional',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'Desired tone for optimization'
            ]
        ];
    }

    /**
     * Get arguments for validation endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_validation_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Settings to validate'
            ]
        ];
    }

    /**
     * Get overview data (combined endpoint for performance)
     *
     * Combines settings, file status, and optimization history into a single API call
     * to reduce initial load time and improve user experience.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_overview_data(WP_REST_Request $request): WP_REST_Response {
        try {
            // Get all data in parallel to minimize processing time
            $settings = $this->llms_txt_manager->get_settings('site');
            $file_status = $this->llms_txt_manager->get_llms_txt_status();

            // Get recent optimization history (limit to 5 for performance)
            $optimization_history = $this->get_recent_optimization_history(5);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'file_status' => $file_status,
                    'optimization_history' => $optimization_history
                ]
            ], 200);

        } catch (Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to load overview data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent optimization history (optimized version)
     *
     * @since 1.0.0
     *
     * @param int $limit Number of results to return
     * @return array Optimization history
     */
    private function get_recent_optimization_history(int $limit = 5): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_usage';

        // Optimized query with limit and specific fields only
        $sql = "SELECT
                    id,
                    created_at,
                    tokens_used,
                    metadata
                FROM {$table_name}
                WHERE action = 'llms_txt_optimization'
                ORDER BY created_at DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Usage analytics retrieval requires direct database access
        $results = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is validated with WordPress prefix, SQL is properly prepared
            $wpdb->prepare($sql, $limit), ARRAY_A);

        if (empty($results)) {
            return [];
        }

        // Process results efficiently
        return array_map(function($result) {
            $metadata = json_decode($result['metadata'], true) ?? [];
            return [
                'id' => (int) $result['id'],
                'created_at' => $result['created_at'],
                'tokens_used' => (int) $result['tokens_used'],
                'improvements_made' => $metadata['improvements_made'] ?? [],
                'suggestions' => $metadata['suggestions'] ?? []
            ];
        }, $results);
    }

    /**
     * Check rate limit for LLMs.txt generation operations
     *
     * @since 1.0.0
     * @return bool True if within rate limit
     */
    private function check_llms_rate_limit(): bool {
        $user_id = get_current_user_id();
        $rate_key = "thinkrank_llms_rate_{$user_id}";

        $requests = get_transient($rate_key) ?: 0;

        if ($requests >= 3) { // Max 3 requests per 5 minutes
            return false;
        }

        set_transient($rate_key, $requests + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Check rate limit for AI optimization operations
     *
     * @since 1.0.0
     * @return bool True if within rate limit
     */
    private function check_ai_rate_limit(): bool {
        $user_id = get_current_user_id();
        $rate_key = "thinkrank_ai_rate_{$user_id}";

        $requests = get_transient($rate_key) ?: 0;

        if ($requests >= 2) { // Max 2 AI requests per 10 minutes (more restrictive)
            return false;
        }

        set_transient($rate_key, $requests + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }
}
