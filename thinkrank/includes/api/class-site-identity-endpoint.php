<?php
/**
 * Site Identity API Endpoints Class
 *
 * REST API endpoints for site identity management including title formats,
 * breadcrumb configuration, robots.txt generation, AI-powered site identity
 * optimization, and global SEO defaults. Provides comprehensive API access to
 * Site Identity Manager and AI Manager functionality with proper authentication,
 * validation, and error handling.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Site_Identity_Manager;
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
 * Site Identity API Endpoints Class
 *
 * Provides REST API endpoints for site identity operations including
 * title generation, breadcrumb management, robots.txt configuration,
 * AI-powered site identity optimization, and rule-based optimization
 * with proper authentication and validation.
 *
 * @since 1.0.0
 */
class Site_Identity_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * Site Identity Manager instance
     *
     * @since 1.0.0
     * @var Site_Identity_Manager
     */
    private Site_Identity_Manager $identity_manager;

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
    protected $rest_base = 'site-identity';

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
        // Ensure Site Identity Manager is loaded
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-site-identity-manager.php';
        }

        $this->identity_manager = new Site_Identity_Manager();
    }

    /**
     * Get AI Manager instance (lazy loading)
     *
     * @since 1.0.0
     * @return AI_Manager
     */
    private function get_ai_manager(): AI_Manager {
        if ($this->ai_manager === null) {
            // Try to get from main plugin container first
            $plugin_instance = \ThinkRank::get_instance();
            $this->ai_manager = $plugin_instance->get_component('ai');

            // Fallback to direct instantiation if container fails
            if ($this->ai_manager === null) {
                $this->ai_manager = new AI_Manager();
            }
        }

        return $this->ai_manager;
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Get site identity settings
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

        // Generate title with template
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/title/generate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_title'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_title_generation_args()
                ]
            ]
        );

        // Get title templates
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/title/templates',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_title_templates'],
                    'permission_callback' => [$this, 'check_permissions']
                ]
            ]
        );

        // Generate breadcrumbs
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/breadcrumbs/generate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_breadcrumbs'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_breadcrumb_generation_args()
                ]
            ]
        );

        // Get breadcrumb types
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/breadcrumbs/types',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_breadcrumb_types'],
                    'permission_callback' => [$this, 'check_permissions']
                ]
            ]
        );

        // Robots.txt management
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/robots',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_robots_txt'],
                    // Reading the robots.txt config is a Site Identity operation —
                    // gate it on the module cap, not the generic 'read' cap.
                    'permission_callback' => [$this, 'check_permissions']
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_robots_txt'],
                    // Writing robots.txt to the webroot is site-wide — require the
                    // Site Identity management capability, not just edit_posts.
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_robots_txt_args()
                ]
            ]
        );

        // Site identity optimization (rule-based)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/optimize',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'optimize_site_identity'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_optimization_args()
                ]
            ]
        );

        // AI-powered site identity optimization
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ai-optimize-info',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'ai_optimize_site_info'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_ai_optimization_args()
                ]
            ]
        );

        // AI-powered hero content optimization
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ai-optimize-hero',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'ai_optimize_hero_content'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_hero_optimization_args()
                ]
            ]
        );

        // Validate site identity settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/validate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'validate_identity_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_validation_args()
                ]
            ]
        );

    }

    /**
     * Get site identity settings
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

            // Get settings from Site Identity Manager
            $settings = $this->identity_manager->get_settings($context_type, $context_id);

            // Get settings schema for validation
            $schema = $this->identity_manager->get_settings_schema($context_type);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'schema' => $schema,
                    'context_type' => $context_type,
                    'context_id' => $context_id
                ],
                'message' => 'Site identity settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update site identity settings
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

            // Validate settings using Site Identity Manager
            $validation = $this->identity_manager->validate_settings($settings);
            
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
            $update_result = $this->identity_manager->save_settings($context_type, $context_id, $settings);

            if (!$update_result) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update site identity settings',
                    ['status' => 500]
                );
            }

            // If this save changed the robots.txt content/toggle and a physical
            // robots.txt exists, keep it in lockstep. The web server serves that
            // static file directly (bypassing the robots_txt filter), so without
            // this the file goes stale and /robots.txt shows the old content
            // while the textarea shows the new — regardless of what the frontend
            // believed about the file's existence.
            if ($context_type === 'site'
                && (array_key_exists('robots_txt_content', $settings)
                    || array_key_exists('robots_txt_enabled', $settings))
            ) {
                $this->identity_manager->sync_robots_txt_file();
            }

            // Get updated settings
            $updated_settings = $this->identity_manager->get_settings($context_type, $context_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $updated_settings,
                    'validation' => $validation,
                    'context_type' => $context_type,
                    'context_id' => $context_id
                ],
                'message' => 'Site identity settings updated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'update_failed',
                'Settings update failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Generate title using template
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_title(WP_REST_Request $request) {
        try {
            $template_name = $request->get_param('template_name') ?? 'default';
            $data = $request->get_param('data') ?? [];
            $context = $request->get_param('context') ?? 'site';

            // Generate title using Site Identity Manager
            $generated_title = $this->identity_manager->generate_title($template_name, $data, $context);

            // Get available templates for reference
            $templates = $this->get_available_title_templates();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'generated_title' => $generated_title,
                    'template_used' => $template_name,
                    'context' => $context,
                    'input_data' => $data,
                    'available_templates' => $templates
                ],
                'message' => 'Title generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'title_generation_failed',
                'Title generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get available title templates
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_title_templates(WP_REST_Request $request): WP_REST_Response {
        $templates = $this->get_available_title_templates();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'templates' => $templates,
                'total_templates' => count($templates)
            ],
            'message' => 'Title templates retrieved successfully'
        ], 200);
    }

    /**
     * Generate breadcrumbs
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_breadcrumbs(WP_REST_Request $request) {
        try {
            $breadcrumb_type = $request->get_param('breadcrumb_type') ?? 'hierarchical';
            $options = $request->get_param('options') ?? [];

            // Generate breadcrumbs using Site Identity Manager
            $breadcrumbs = $this->identity_manager->generate_breadcrumbs($breadcrumb_type, $options);

            // Get available breadcrumb types for reference
            $types = $this->get_available_breadcrumb_types();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'breadcrumbs' => $breadcrumbs,
                    'breadcrumb_type' => $breadcrumb_type,
                    'options' => $options,
                    'available_types' => $types
                ],
                'message' => 'Breadcrumbs generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'breadcrumb_generation_failed',
                'Breadcrumb generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get available breadcrumb types
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_breadcrumb_types(WP_REST_Request $request): WP_REST_Response {
        $types = $this->get_available_breadcrumb_types();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'breadcrumb_types' => $types,
                'total_types' => count($types)
            ],
            'message' => 'Breadcrumb types retrieved successfully'
        ], 200);
    }

    /**
     * Get robots.txt configuration
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_robots_txt(WP_REST_Request $request): WP_REST_Response {
        try {
            $custom_rules = $request->get_param('custom_rules') ?? [];

            // Generate robots.txt using Site Identity Manager
            $robots_data = $this->identity_manager->generate_robots_txt($custom_rules);

            // The editor shows the body that is actually being served (physical
            // file if present, else the effective content) — header-stripped so
            // the auto-generated comment/timestamp never lands in the textarea.
            $robots_data['content'] = $this->identity_manager->get_served_robots_body();

            return new WP_REST_Response([
                'success' => true,
                'data' => $robots_data,
                'message' => 'Robots.txt data retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve robots.txt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update robots.txt configuration
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_robots_txt(WP_REST_Request $request) {
        try {
            // Check rate limiting
            if (!$this->check_robots_rate_limit()) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    'Too many requests. Please wait a few minutes before trying again.',
                    ['status' => 429]
                );
            }
            $custom_rules = $request->get_param('custom_rules') ?? [];
            $enable_management = $request->get_param('enable_management') ?? true;

            // Two callers share this route:
            //  - "Generate" rebuilds the content from rules and adopts it as the
            //    stored textarea content ($regenerate = true).
            //  - A plain save that only needs the physical file re-synced to the
            //    already-stored textarea content ($regenerate = false).
            // Default to true so the historical Generate contract is unchanged.
            $regenerate = $request->get_param('regenerate');
            if ($regenerate === null) {
                $regenerate = true;
            }

            // Validate custom rules format
            if (!is_array($custom_rules)) {
                return new WP_Error(
                    'invalid_rules',
                    'Custom rules must be provided as an array',
                    ['status' => 400]
                );
            }

            $settings = ['robots_txt_enabled' => $enable_management];

            if ($regenerate) {
                // Generate and validate robots.txt from the rules.
                $robots_data = $this->identity_manager->generate_robots_txt($custom_rules);

                if (!empty($robots_data['validation']['errors'])) {
                    return new WP_Error(
                        'validation_failed',
                        'Robots.txt validation failed',
                        [
                            'status' => 400,
                            'validation_errors' => $robots_data['validation']['errors']
                        ]
                    );
                }

                // Adopt the freshly generated content as the stored source.
                $settings['custom_robots_rules'] = $custom_rules;
                $settings['robots_txt_content'] = $robots_data['content'];
            }

            $update_result = $this->identity_manager->save_settings('site', null, $settings);

            if (!$update_result) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update robots.txt settings',
                    ['status' => 500]
                );
            }

            // Effective content = the stored textarea content when set, else the
            // auto-generated body. This is exactly what the live /robots.txt
            // serves, so writing it here keeps the physical file in lockstep.
            $effective_content = $this->identity_manager->render_robots_txt();

            // Write robots.txt file to filesystem if management is enabled.
            $file_write_result = ['success' => false, 'message' => 'File writing disabled'];
            if ($enable_management && $effective_content !== '') {
                $write_to_file = $request->get_param('write_to_file') ?? true;

                if ($write_to_file) {
                    $file_write_result = $this->identity_manager->write_robots_txt(
                        $effective_content
                    );
                }
            }

            $robots_data = $robots_data ?? [];
            // Return the header-stripped body so the client textarea reflects
            // exactly what it should hold (the header is added only at render).
            $robots_data['content'] = $this->identity_manager->get_served_robots_body();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'robots_data' => $robots_data,
                    'settings_updated' => $settings,
                    'file_write_result' => $file_write_result
                ],
                'message' => $file_write_result['success']
                    ? 'Robots.txt configuration updated and file written successfully'
                    : 'Robots.txt configuration updated (file not written: ' . $file_write_result['message'] . ')'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'update_failed',
                'Robots.txt update failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Optimize site identity (rule-based)
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function optimize_site_identity(WP_REST_Request $request) {
        try {
            $identity_data = $request->get_param('identity_data');
            $options = $request->get_param('options') ?? [];

            // Validate identity data
            if (empty($identity_data) || !is_array($identity_data)) {
                return new WP_Error(
                    'invalid_data',
                    'Identity data must be provided as an array',
                    ['status' => 400]
                );
            }

            // Optimize site identity using Site Identity Manager with options
            $optimization_results = $this->identity_manager->optimize_site_identity($identity_data, $options);

            return new WP_REST_Response([
                'success' => true,
                'data' => $optimization_results,
                'message' => 'Site identity optimization completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'optimization_failed',
                'Site identity optimization failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * AI optimize site information
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function ai_optimize_site_info(WP_REST_Request $request) {
        try {
            $site_data = $request->get_param('site_data');
            $options = [
                'business_type' => $request->get_param('business_type'),
                'target_audience' => $request->get_param('target_audience'),
                'tone' => $request->get_param('tone')
            ];

            // Validate site data
            if (empty($site_data) || !is_array($site_data)) {
                return new WP_Error(
                    'invalid_data',
                    'Site data must be provided as an array',
                    ['status' => 400]
                );
            }

            // Only the site name is required. An empty description or tagline
            // is a valid state — and exactly when AI help is most useful — so
            // let the optimizer generate them instead of rejecting the request.
            if (empty($site_data['site_name'])) {
                return new WP_Error(
                    'missing_field',
                    __('Site name is required to run AI optimization.', 'thinkrank'),
                    ['status' => 400]
                );
            }

            // Sanitize site data (description/tagline may be empty by design)
            $sanitized_site_data = [
                'site_name' => sanitize_text_field($site_data['site_name']),
                'site_description' => sanitize_textarea_field($site_data['site_description'] ?? ''),
                'tagline' => sanitize_text_field($site_data['tagline'] ?? ''),
                'default_meta_description' => sanitize_textarea_field($site_data['default_meta_description'] ?? '')
            ];

            // Sanitize options
            $sanitized_options = [
                'business_type' => sanitize_text_field($options['business_type'] ?? 'website'),
                'target_audience' => sanitize_text_field($options['target_audience'] ?? 'general'),
                'tone' => sanitize_text_field($options['tone'] ?? 'professional')
            ];

            // Get AI manager and perform optimization
            $ai_manager = $this->get_ai_manager();
            $optimization_results = $ai_manager->optimize_site_identity($sanitized_site_data, $sanitized_options);

            return new WP_REST_Response([
                'success' => true,
                'data' => $optimization_results,
                'message' => 'Site identity AI optimization completed'
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
     * AI-powered hero content optimization
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function ai_optimize_hero_content(WP_REST_Request $request) {
        try {
            $hero_data = $request->get_param('hero_data');
            $context = $request->get_param('context') ?? [];
            $options = [
                'business_type' => $request->get_param('business_type'),
                'target_audience' => $request->get_param('target_audience'),
                'tone' => $request->get_param('tone')
            ];

            // Validate hero data
            if (empty($hero_data) || !is_array($hero_data)) {
                return new WP_Error(
                    'invalid_data',
                    'Hero data must be provided as an array',
                    ['status' => 400]
                );
            }

            // Sanitize hero data
            $sanitized_hero_data = [
                'hero_title' => sanitize_text_field($hero_data['hero_title'] ?? ''),
                'hero_subtitle' => sanitize_textarea_field($hero_data['hero_subtitle'] ?? ''),
                'hero_cta_text' => sanitize_text_field($hero_data['hero_cta_text'] ?? ''),
                'hero_cta_url' => esc_url_raw($hero_data['hero_cta_url'] ?? '')
            ];

            // Sanitize context data
            $sanitized_context = [
                'site_name' => sanitize_text_field($context['site_name'] ?? ''),
                'site_url' => esc_url_raw($context['site_url'] ?? ''),
                'business_type' => sanitize_text_field($context['business_type'] ?? ''),
                'site_description' => sanitize_textarea_field($context['site_description'] ?? '')
            ];

            // Sanitize options
            $sanitized_options = [
                'business_type' => sanitize_text_field($options['business_type'] ?? 'website'),
                'target_audience' => sanitize_text_field($options['target_audience'] ?? 'general'),
                'tone' => sanitize_text_field($options['tone'] ?? 'professional'),
                'context' => $sanitized_context
            ];

            // Get AI manager and perform optimization
            $ai_manager = $this->get_ai_manager();
            $optimization_results = $ai_manager->optimize_homepage_hero($sanitized_hero_data, $sanitized_options);

            return new WP_REST_Response([
                'success' => true,
                'data' => $optimization_results,
                'message' => 'Hero content AI optimization completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'ai_optimization_failed',
                'Hero AI optimization failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Validate site identity settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function validate_identity_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $settings = $request->get_param('settings');
            $tab_context = $request->get_param('tab_context') ?? '';

            // Validate settings using Site Identity Manager with tab context
            $validation = $this->identity_manager->validate_settings($settings ?? [], $tab_context);

            return new WP_REST_Response([
                'success' => true,
                'data' => $validation,
                'message' => 'Settings validation completed'
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
     * Check permissions for site identity operations (admin-only)
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_site_identity');
    }
    private function get_available_title_templates(): array {
        return [
            'default' => [
                'name' => 'Default',
                'template' => '%title% %separator% %sitename%',
                'description' => 'Standard title format with site name'
            ]
        ];
    }

    /**
     * Get available breadcrumb types
     *
     * @since 1.0.0
     *
     * @return array Breadcrumb types
     */
    private function get_available_breadcrumb_types(): array {
        return [
            'hierarchical' => [
                'name' => 'Hierarchical',
                'description' => 'Based on page hierarchy and categories'
            ]
        ];
    }

    /**
     * Argument validation methods
     */

    /**
     * Get arguments for settings endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Site identity settings to update'
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
     * Get arguments for title generation endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_title_generation_args(): array {
        return [
            'template_name' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['default', 'simple', 'reverse', 'category', 'author'],
                'default' => 'default',
                'description' => 'Title template to use'
            ],
            'data' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Data for placeholder replacement'
            ],
            'context' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['site'],
                'default' => 'site',
                'description' => 'Context (site only)'
            ]
        ];
    }

    /**
     * Get arguments for breadcrumb generation endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_breadcrumb_generation_args(): array {
        return [
            'breadcrumb_type' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['hierarchical', 'taxonomy', 'path', 'custom'],
                'default' => 'hierarchical',
                'description' => 'Type of breadcrumb navigation to generate'
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Additional options for breadcrumb generation'
            ]
        ];
    }

    /**
     * Get arguments for robots.txt endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_robots_txt_args(): array {
        return [
            'custom_rules' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'object'
                ],
                'description' => 'Custom robots.txt rules'
            ],
            'enable_management' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Enable automatic robots.txt management'
            ],
            'regenerate' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Rebuild content from rules (Generate). When false, only re-sync the physical file to the stored content.'
            ]
        ];
    }

    /**
     * Get arguments for optimization endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_optimization_args(): array {
        return [
            'identity_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Site identity data to optimize'
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
            'site_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Site identity data to optimize with AI',
                'properties' => [
                    'site_name' => [
                        'type' => 'string',
                        'description' => 'Site name to optimize'
                    ],
                    'site_description' => [
                        'type' => 'string',
                        'description' => 'Site description to optimize'
                    ],
                    'tagline' => [
                        'type' => 'string',
                        'description' => 'Site tagline to optimize'
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
     * Get arguments for hero AI optimization endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_hero_optimization_args(): array {
        return [
            'hero_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Hero content data to optimize with AI',
                'properties' => [
                    'hero_title' => [
                        'type' => 'string',
                        'description' => 'Hero section title'
                    ],
                    'hero_subtitle' => [
                        'type' => 'string',
                        'description' => 'Hero section subtitle'
                    ],
                    'hero_cta_text' => [
                        'type' => 'string',
                        'description' => 'Call-to-action button text'
                    ],
                    'hero_cta_url' => [
                        'type' => 'string',
                        'description' => 'Call-to-action button URL'
                    ]
                ]
            ],
            'context' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Additional context for optimization',
                'properties' => [
                    'site_name' => [
                        'type' => 'string',
                        'description' => 'Site name for context'
                    ],
                    'site_url' => [
                        'type' => 'string',
                        'description' => 'Site URL for context'
                    ],
                    'business_type' => [
                        'type' => 'string',
                        'description' => 'Business type for context'
                    ],
                    'site_description' => [
                        'type' => 'string',
                        'description' => 'Site description for context'
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
     * Check rate limit for robots.txt operations
     *
     * @since 1.0.0
     * @return bool True if within rate limit
     */
    private function check_robots_rate_limit(): bool {
        $user_id = get_current_user_id();
        $rate_key = "thinkrank_robots_rate_{$user_id}";

        $requests = get_transient($rate_key) ?: 0;

        if ($requests >= 20) { // Max 20 requests per 5 minutes
            return false;
        }

        set_transient($rate_key, $requests + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }
}
