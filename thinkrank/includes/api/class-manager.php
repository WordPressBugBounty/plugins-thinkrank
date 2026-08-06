<?php

/**
 * API Manager Class
 *
 * Handles REST API endpoints registration and management
 *
 * @package ThinkRank\API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

// Import endpoint classes
use ThinkRank\API\Site_Identity_Endpoint;
use ThinkRank\API\Performance_Endpoint;
use ThinkRank\API\Schema_Endpoint;
use ThinkRank\API\Settings_Management_Endpoint;
use ThinkRank\API\Content_Brief_Endpoint;
use ThinkRank\API\Social_Media_Endpoint;
use ThinkRank\API\Sitemap_Endpoint;
use ThinkRank\API\SEO_Analytics_Endpoint;
use ThinkRank\API\Usage_Analytics_Endpoint;
use ThinkRank\API\Integrations_Endpoint;
use ThinkRank\API\Social_Platforms_Endpoint;
use ThinkRank\API\LLMs_Txt_Endpoint;
use ThinkRank\API\Global_SEO_Endpoint;
use ThinkRank\API\Image_SEO_Endpoint;
use ThinkRank\API\Instant_Indexing_Endpoint;
use ThinkRank\API\Pillar_Content_Endpoint;
use ThinkRank\API\Global_Robot_Meta_Endpoint;
use ThinkRank\API\Author_Archives_Endpoint;
use ThinkRank\API\Email_Report_Endpoint;
use ThinkRank\Admin\Importers\Import_Controller;
use ThinkRank\API\Setup_Wizard_Endpoint;


// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Manager Class
 *
 * Single Responsibility: Manage REST API endpoints
 *
 * @since 1.0.0
 */
class Manager {

    /**
     * API namespace
     *
     * @var string
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Maximum accepted length (characters) for AI `content` payloads. Enforced
     * at the REST boundary so oversized input can't drive expensive prompt
     * building, cache hashing, and AI requests/retries. Mirrors the frontend's
     * 5000-character trim.
     */
    private const AI_CONTENT_MAX_LENGTH = 5000;

    /**
     * Sanitize and hard-cap an AI `content` request parameter.
     *
     * Used as the `sanitize_callback` for every AI endpoint's `content` arg so
     * the server enforces its own maximum regardless of what a direct REST
     * caller sends.
     *
     * @param mixed $value Raw request value.
     * @return string Sanitized content, truncated to AI_CONTENT_MAX_LENGTH.
     */
    public function sanitize_ai_content($value): string {
        return mb_substr(sanitize_textarea_field((string) $value), 0, self::AI_CONTENT_MAX_LENGTH);
    }

    /**
     * Initialize API manager
     *
     * @return void
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('rest_api_init', [$this, 'register_endpoint_classes']);
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes(): void {
        // Core endpoints
        register_rest_route(self::NAMESPACE, '/capabilities', [
            'methods' => 'GET',
            'callback' => [$this, 'get_capabilities'],
            'permission_callback' => [$this, 'check_basic_permissions'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugin-info', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugin_info'],
            'permission_callback' => [$this, 'check_basic_permissions'],
        ]);

        register_rest_route(self::NAMESPACE, '/system-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_system_status'],
            'permission_callback' => [$this, 'check_basic_permissions'],
        ]);

        // Integration health check for MCP/Abilities clients (see #188). This
        // route is intentionally gated only by the admin capability, NOT by the
        // `enable_mcp` toggle, so it stays reachable as a diagnostic even when
        // the MCP server is off or abilities failed to register.
        register_rest_route(self::NAMESPACE, '/connection-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_connection_status'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        // Settings endpoints
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [$this, 'check_settings_permissions'],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'save_settings'],
            'permission_callback' => [$this, 'check_settings_permissions'],
            'args' => [
                'ai_provider' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'openai_api_key' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'openai_model' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'claude_api_key' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'claude_model' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'gemini_api_key' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'gemini_model' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'openrouter_api_key' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'openrouter_model' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'keep_data_on_uninstall' => [
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
                'enable_mcp' => [
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
                'enable_migration_tools' => [
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);



        // Metadata endpoints
        register_rest_route(self::NAMESPACE, '/metadata/(?P<post_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_metadata'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'post_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // AI endpoints
        register_rest_route(self::NAMESPACE, '/ai/generate-metadata', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_ai_metadata'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tone' => [
                    'type' => 'string',
                    'default' => 'professional',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/improve-title', [
            'methods' => 'POST',
            'callback' => [$this, 'improve_ai_title'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'current_title' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tone' => [
                    'type' => 'string',
                    'default' => 'professional',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'suggestion' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/improve-meta-description', [
            'methods' => 'POST',
            'callback' => [$this, 'improve_ai_meta_description'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'current_description' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tone' => [
                    'type' => 'string',
                    'default' => 'professional',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'suggestion' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/explain-suggestion', [
            'methods' => 'POST',
            'callback' => [$this, 'explain_ai_suggestion'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'suggestion' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'title' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/add-dofollow-link', [
            'methods' => 'POST',
            'callback' => [$this, 'add_ai_dofollow_link'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/add-keyword-paragraph', [
            'methods' => 'POST',
            'callback' => [$this, 'add_ai_keyword_paragraph'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tone' => [
                    'type' => 'string',
                    'default' => 'professional',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'word_count' => [
                    'type' => 'integer',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
                'keyword_count' => [
                    'type' => 'integer',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/schema/enable-for-post', [
            'methods' => 'POST',
            'callback' => [$this, 'enable_schema_for_post'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'post_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/test-connection', [
            'methods' => 'POST',
            'callback' => [$this, 'test_ai_connection'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'api_key' => [
                    'type' => 'string',
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'provider' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'openai',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/providers', [
            'methods' => 'GET',
            'callback' => [$this, 'get_ai_providers'],
            'permission_callback' => [$this, 'check_basic_permissions'],
        ]);

        // Register content brief endpoints
        $content_brief_endpoint = new \ThinkRank\API\Content_Brief_Endpoint();
        $content_brief_endpoint->register_routes();

        // Register SEO score endpoints
        try {
            $database = new \ThinkRank\Core\Database();
            $seo_calculator = new \ThinkRank\AI\SEOScoreCalculator($database);
            $seo_score_endpoint = new \ThinkRank\API\SEOScoreEndpoint($seo_calculator);
            $seo_score_endpoint->register_routes();
        } catch (\Exception $e) {
            // SEO Score endpoint registration failed
        }

        // Register Usage Analytics endpoints
        try {
            $usage_analytics_endpoint = new \ThinkRank\API\Usage_Analytics_Endpoint();
            $usage_analytics_endpoint->register_routes();
        } catch (\Exception $e) {
            // Usage Analytics endpoint registration failed
        }

        // Register Site SEO Analyzer endpoint
        try {
            $seo_analyzer_endpoint = new \ThinkRank\API\SEO_Analyzer_Endpoint();
            $seo_analyzer_endpoint->register_routes();
        } catch (\Exception $e) {
            // Site SEO Analyzer endpoint registration failed
        }

        // Register SEO Analytics endpoints
        try {
            $seo_analytics_endpoint = new \ThinkRank\API\SEO_Analytics_Endpoint();
            $seo_analytics_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register SEO Analytics endpoint
        }

        // Register Instant Indexing endpoints
        try {
            $instant_indexing_endpoint = new \ThinkRank\API\Instant_Indexing_Endpoint();
            $instant_indexing_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Instant Indexing endpoint
        }

        // Register Pillar Content endpoints
        try {
            $pillar_content_endpoint = new \ThinkRank\API\Pillar_Content_Endpoint();
            $pillar_content_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Pillar Content endpoint
        }

        // Register Focus Keyword Usage endpoint ("already used" status).
        try {
            $focus_keyword_usage_endpoint = new \ThinkRank\API\Focus_Keyword_Usage_Endpoint();
            $focus_keyword_usage_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Focus Keyword Usage endpoint
        }
        // Register Global Robot Meta endpoints
        try {
            $global_robot_meta_endpoint = new \ThinkRank\API\Global_Robot_Meta_Endpoint();
            $global_robot_meta_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Global Robot Meta endpoint
        }

        // Register Author Archives endpoints
        try {
            $author_archives_endpoint = new \ThinkRank\API\Author_Archives_Endpoint();
            $author_archives_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Author Archives endpoint
        }

        // Register Role Manager endpoint
        try {
            $role_manager_endpoint = new \ThinkRank\API\Role_Manager_Endpoint();
            $role_manager_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Role Manager endpoint
        }

        // Register Email Report endpoints
        try {
            $email_report_endpoint = new \ThinkRank\API\Email_Report_Endpoint();
            $email_report_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Email Report endpoint
        }


        register_rest_route(self::NAMESPACE, '/ai/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_ai_status'],
            'permission_callback' => [$this, 'check_basic_permissions'],
        ]);

        register_rest_route(self::NAMESPACE, '/ai/analyze-content', [
            'methods' => 'POST',
            'callback' => [$this, 'analyze_content'],
            'permission_callback' => [$this, 'check_basic_permissions'],
            'args' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => [$this, 'sanitize_ai_content'],
                ],
                'metadata' => [
                    'type' => 'object',
                    'required' => false,
                    'sanitize_callback' => [$this, 'sanitize_metadata_object'],
                ],
                'post_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Check basic permissions (for logged-in users)
     *
     * @param \WP_REST_Request $request Request object
     * @return bool|WP_Error Permission status
     */
    public function check_basic_permissions(\WP_REST_Request $request) {
        // Allow access for logged-in users who can edit posts
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_forbidden',
                __('You must be logged in to access this endpoint.', 'thinkrank'),
                ['status' => 401]
            );
        }

        if (!current_user_can('edit_posts')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this endpoint.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }


    /**
     * Simple transient-based rate limiter
     *
     * @param string $bucket_id Unique bucket per user/IP and route
     * @param int $limit Max requests per minute
     * @return bool|\WP_Error True if allowed, or WP_Error when rate limited
     */
    private function enforce_rate_limit(string $bucket_id, int $limit) {
        $settings = \ThinkRank\Core\Settings::instance();
        $enabled = (bool) $settings->get('enable_rate_limiting', true);
        if (!$enabled) {
            return true;
        }
        $now = time();
        $window = 60;
        $key = 'thinkrank_rl_' . md5($bucket_id);
        $bucket = get_transient($key);
        if (!is_array($bucket)) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if ($now - ($bucket['start'] ?? 0) >= $window) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if (($bucket['count'] ?? 0) >= max(1, $limit)) {
            return new \WP_Error('rate_limited', __('Rate limit exceeded. Please wait a moment and try again.', 'thinkrank'), ['status' => 429]);
        }
        $bucket['count']++;
        set_transient($key, $bucket, $window);
        return true;
    }

    /**
     * Check admin permissions (for settings)
     *
     * @param \WP_REST_Request $request Request object
     * @return bool|WP_Error Permission status
     */
    public function check_admin_permissions(\WP_REST_Request $request) {
        // Allow access for administrators only
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_forbidden',
                __('You must be logged in to access this endpoint.', 'thinkrank'),
                ['status' => 401]
            );
        }

        if (!current_user_can('manage_options')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to manage settings.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check Settings section permissions.
     *
     * Delegable via Role Manager: passes for administrators (bypass) and for
     * any role granted the `thinkrank_settings` capability. Used by the core
     * /settings routes so the "Settings & API Keys" area can be delegated.
     *
     * @param \WP_REST_Request $request Request object
     * @return bool|\WP_Error Permission status
     */
    public function check_settings_permissions(\WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_forbidden',
                __('You must be logged in to access this endpoint.', 'thinkrank'),
                ['status' => 401]
            );
        }

        if (!\ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_settings')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to manage ThinkRank settings.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Get user capabilities
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_capabilities(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'manage_settings' => current_user_can('manage_options'),
            'view_analytics' => current_user_can('edit_posts'),

            'use_ai_features' => current_user_can('edit_posts'),
        ]);
    }

    /**
     * Get plugin information
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_plugin_info(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'version' => THINKRANK_VERSION,
            'name' => 'ThinkRank',
            'description' => 'AI-native SEO plugin for WordPress',
        ]);
    }

    /**
     * Get system status
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_system_status(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'status' => 'healthy',
            'issues' => [],
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
        ]);
    }

    /**
     * Get ThinkRank integration health for MCP/Abilities clients (see #188).
     *
     * Admin-gated diagnostic; never returns secret material. Delegates to the
     * shared reporter so the ability and this route stay in lock-step.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_connection_status(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(\ThinkRank\Diagnostics\Connection_Status::report());
    }



    /**
     * Get settings
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_settings(\WP_REST_Request $request): \WP_REST_Response {
        // Use Settings class for consistent access (handles decryption automatically)
        $settings_instance = \ThinkRank\Core\Settings::instance();

        $settings = [
            'ai_provider' => $settings_instance->get('ai_provider', 'openai'),
            'openai_api_key' => $settings_instance->get('openai_api_key', ''),
            'openai_model' => $settings_instance->get('openai_model', 'gpt-5-nano'),
            'claude_api_key' => $settings_instance->get('claude_api_key', ''),
            'claude_model' => $settings_instance->get('claude_model', 'claude-sonnet-5'),
            'gemini_api_key' => $settings_instance->get('gemini_api_key', ''),
            'gemini_model' => $settings_instance->get('gemini_model', 'gemini-2.5-flash'),
            'openrouter_api_key' => $settings_instance->get('openrouter_api_key', ''),
            'openrouter_model' => $settings_instance->get('openrouter_model', 'openai/gpt-4o-mini'),
            'max_tokens' => $settings_instance->get('max_tokens', 1000),
            'temperature' => $settings_instance->get('temperature', 0.7),
            'cache_duration' => $settings_instance->get('cache_duration', 3600),
            'keep_data_on_uninstall' => (bool) $settings_instance->get('keep_data_on_uninstall', true),
            'enable_migration_tools' => (bool) $settings_instance->get('enable_migration_tools', false),
            'google_account_connected' => (bool) $settings_instance->get('google_account_connected', false),
            'enable_mcp' => (bool) $settings_instance->get('enable_mcp', false),
        ];



        // Don't send full API keys to frontend for security - mask them,
        // revealing the first 5 and last 3 chars so the saved key is recognizable.
        if (!empty($settings['openai_api_key'])) {
            $settings['openai_api_key'] = $this->mask_ai_api_key($settings['openai_api_key']);
        }
        if (!empty($settings['claude_api_key'])) {
            $settings['claude_api_key'] = $this->mask_ai_api_key($settings['claude_api_key']);
        }
        if (!empty($settings['gemini_api_key'])) {
            $settings['gemini_api_key'] = $this->mask_ai_api_key($settings['gemini_api_key']);
        }
        if (!empty($settings['openrouter_api_key'])) {
            $settings['openrouter_api_key'] = $this->mask_ai_api_key($settings['openrouter_api_key']);
        }

        return new \WP_REST_Response($settings);
    }

    /**
     * Mask an AI provider API key for display.
     *
     * Reveals the first 5 and last 3 characters with a bullet run in between
     * (e.g. "sk-pr••••••••abc"). Keys of 8 chars or fewer are fully masked so
     * head + tail can't reconstruct the whole value. The "••••••••" sentinel is
     * what save_settings() looks for to skip re-saving a resubmitted mask.
     *
     * @param string $key Raw API key.
     * @return string Masked key safe to send to the frontend.
     */
    private function mask_ai_api_key(string $key): string {
        if (strlen($key) <= 8) {
            return '••••••••';
        }

        return substr($key, 0, 5) . '••••••••' . substr($key, -3);
    }

    /**
     * Save settings
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function save_settings(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_params();

        // Get Settings instance for proper encryption handling
        $settings = \ThinkRank\Core\Settings::instance();

        // Capture the pre-save MCP state so we can detect an on/off transition
        // below and mint/revoke the connection token to match (see #244).
        $mcp_was_enabled = (bool) $settings->get('enable_mcp', false);

        // Map frontend parameter names to setting keys
        $settings_map = [
            'ai_provider' => 'ai_provider',
            'openai_api_key' => 'openai_api_key',
            'openai_model' => 'openai_model',
            'claude_api_key' => 'claude_api_key',
            'claude_model' => 'claude_model',
            'gemini_api_key' => 'gemini_api_key',
            'gemini_model' => 'gemini_model',
            'openrouter_api_key' => 'openrouter_api_key',
            'openrouter_model' => 'openrouter_model',
            'max_tokens' => 'max_tokens',
            'temperature' => 'temperature',
            'cache_duration' => 'cache_duration',
            'keep_data_on_uninstall' => 'keep_data_on_uninstall',
            'enable_mcp' => 'enable_mcp',
            'enable_migration_tools' => 'enable_migration_tools',
        ];

        // Processing settings save request

        foreach ($settings_map as $param_key => $setting_key) {
            if (isset($params[$param_key])) {
                $value = $params[$param_key];

                // Handle API keys specially - check for masked values
                if (in_array($param_key, ['openai_api_key', 'claude_api_key', 'gemini_api_key', 'openrouter_api_key'])) {
                    // Don't update if the value carries the mask sentinel (the
                    // preview now keeps real head/tail chars around it, so match
                    // anywhere rather than only at the start). Empty still clears.
                    if (strpos($value, '••••••••') !== false) {
                        continue;
                    }
                }

                // Use Settings class for all operations (handles encryption automatically)
                if (!$settings->set($setting_key, $value)) {
                    // Settings save failed, continue with other settings
                }
            }
        }

        // MCP is a single master switch (see #244): enabling it auto-mints a
        // read/write connection token so the connect recipes are ready without
        // a separate "Generate token" step.
        //
        // Disabling is a PAUSE, not a wipe: the switch alone already denies all
        // access (Mcp_Server 403s and the OAuth/discovery endpoints refuse while
        // off), so stored tokens and OAuth grants are inert. We keep them so
        // re-enabling restores every previously connected app with no
        // re-approval. Explicit revocation stays available per-app (the
        // Connected AI apps trash button) and for the shared token (Reset
        // token / rotate). Only act on an actual on->off->on transition so
        // saving unrelated settings never touches the connection.
        if (isset($params['enable_mcp'])) {
            $mcp_now_enabled = (bool) $settings->get('enable_mcp', false);
            if ($mcp_now_enabled && !$mcp_was_enabled) {
                \ThinkRank\Mcp\Mcp_Pairing::connect();
            }
        }

        // Auto-dismiss welcome notice if API key was saved
        $this->maybe_dismiss_welcome_notice($params);

        // Force AI Manager to re-initialize client with new settings
        if (isset($params['ai_provider']) || isset($params['openai_api_key']) || isset($params['claude_api_key']) || isset($params['gemini_api_key']) || isset($params['openrouter_api_key'])) {
            // Clear any cached AI Manager instances to force re-initialization
            wp_cache_delete('thinkrank_ai_manager', 'thinkrank');

            // If we have an AI Manager instance, force it to re-initialize
            try {
                $ai_manager = new \ThinkRank\AI\Manager($settings);
                $ai_manager->reinitialize_client();
            } catch (\Exception $e) {
                // Ignore initialization errors at this point
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Settings saved successfully', 'thinkrank'),
            'settings' => $this->get_settings($request)->get_data(),
        ]);
    }

    /**
     * Maybe dismiss welcome notice if API key was saved
     *
     * @param array $params Request parameters
     * @return void
     */
    private function maybe_dismiss_welcome_notice(array $params): void {
        // Check if an API key was saved (not cleared)
        $api_key_saved = false;

        if (!empty($params['openai_api_key']) && $params['openai_api_key'] !== '') {
            $api_key_saved = true;
        }

        if (!empty($params['claude_api_key']) && $params['claude_api_key'] !== '') {
            $api_key_saved = true;
        }

        // Auto-dismiss welcome notice if API key was configured
        if ($api_key_saved && get_option('thinkrank_show_welcome')) {
            delete_option('thinkrank_show_welcome');
        }
    }

    /**
     * Get metadata for post
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_metadata(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = (int) $request->get_param('post_id');

        // Object-level guard: only expose a post's stored SEO meta to a user who
        // can edit that specific post (the section capability gate handles the
        // AI Tools toggle; this adds per-post ownership).
        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_REST_Response(['message' => 'You are not allowed to view this metadata.'], 403);
        }

        // Get existing metadata
        $metadata = [
            'title' => get_post_meta($post_id, '_thinkrank_title', true),
            'description' => get_post_meta($post_id, '_thinkrank_description', true),
            'keywords' => get_post_meta($post_id, '_thinkrank_keywords', true),
            'seo_score' => get_post_meta($post_id, '_thinkrank_seo_score', true) ?: 0,
            'last_generated' => get_post_meta($post_id, '_thinkrank_last_generated', true),
        ];

        return new \WP_REST_Response($metadata);
    }

    /**
     * Generate AI-powered SEO metadata
     *
     * @param \WP_REST_Request $request Request object containing content and generation options
     * @return \WP_REST_Response Response object with generated metadata or error message
     * @throws \Exception When AI metadata generation fails or AI client is unavailable
     */
    public function generate_ai_metadata(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $options = [
            'target_keyword' => $request->get_param('target_keyword'),
            'content_type' => $request->get_param('content_type'),
            'tone' => $request->get_param('tone'),
            // Instruct the model to write in the post/site language instead of
            // defaulting to English on non-English sites (issue #234).
            'language' => \ThinkRank\AI\Language_Resolver::resolve((int) $request->get_param('post_id')),
        ];

        // Rate limiting: per user/IP per route
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_generate|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            // Get AI manager instance
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            // Run through the generator so title/description are capped to the
            // configured limits and the character counts are returned.
            $generator = new \ThinkRank\AI\Metadata_Generator($ai_manager);
            $metadata = $generator->generate_for_content($content, $options);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $metadata,
                'message' => __('SEO metadata generated successfully', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Generate and return an improved SEO title for an "Apply" suggestion action.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response with the improved title under data.title.
     * @throws \Exception When title improvement fails or the AI client is unavailable.
     */
    public function improve_ai_title(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $options = [
            'current_title' => $request->get_param('current_title'),
            'target_keyword' => $request->get_param('target_keyword'),
            'content_type' => $request->get_param('content_type'),
            'tone' => $request->get_param('tone'),
            'suggestion' => $request->get_param('suggestion'),
            'language' => \ThinkRank\AI\Language_Resolver::resolve((int) $request->get_param('post_id')),
        ];

        // Rate limiting: shares the AI generation bucket.
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_generate|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            $result = $ai_manager->improve_seo_title($content, $options);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $result,
                'message' => __('SEO title improved successfully', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Generate and return an improved meta description for an "Apply" action.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response with the description under data.description.
     * @throws \Exception When generation fails or the AI client is unavailable.
     */
    public function improve_ai_meta_description(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $options = [
            'current_description' => $request->get_param('current_description'),
            'target_keyword' => $request->get_param('target_keyword'),
            'content_type' => $request->get_param('content_type'),
            'tone' => $request->get_param('tone'),
            'suggestion' => $request->get_param('suggestion'),
            'language' => \ThinkRank\AI\Language_Resolver::resolve((int) $request->get_param('post_id')),
        ];

        // Rate limiting: shares the AI generation bucket.
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_generate|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            $result = $ai_manager->improve_meta_description($content, $options);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $result,
                'message' => __('Meta description generated successfully', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Explain a single SEO suggestion in plain, post-specific language.
     *
     * Read-only copilot action: returns a short AI explanation of why the
     * suggestion matters for this post and how to resolve it. Does not modify
     * any content.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response with the explanation under data.explanation.
     * @throws \Exception When generation fails or the AI client is unavailable.
     */
    public function explain_ai_suggestion(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $options = [
            'suggestion' => $request->get_param('suggestion'),
            'title' => $request->get_param('title'),
            'target_keyword' => $request->get_param('target_keyword'),
            'content_type' => $request->get_param('content_type'),
        ];

        // Rate limiting: shares the AI generation bucket.
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_generate|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            $result = $ai_manager->explain_seo_suggestion($content, $options);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $result,
                'message' => __('Explanation generated successfully', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Generate a content fragment with one authoritative external dofollow link.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response with the HTML fragment under data.html.
     * @throws \Exception When generation fails or the AI client is unavailable.
     */
    public function add_ai_dofollow_link(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $options = [
            'target_keyword' => $request->get_param('target_keyword'),
            'content_type' => $request->get_param('content_type'),
        ];

        // Rate limiting: shares the AI generation bucket.
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_generate|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            $result = $ai_manager->generate_dofollow_link($content, $options);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $result,
                'message' => __('Added an authoritative source link', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Generate a keyword-rich paragraph to lift keyword density into band.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response with the HTML fragment under data.html.
     * @throws \Exception When generation fails or the AI client is unavailable.
     */
    public function add_ai_keyword_paragraph(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $options = [
            'target_keyword' => $request->get_param('target_keyword'),
            'content_type' => $request->get_param('content_type'),
            'tone' => $request->get_param('tone'),
            'word_count' => $request->get_param('word_count'),
            'keyword_count' => $request->get_param('keyword_count'),
        ];

        // Rate limiting: shares the AI generation bucket.
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_generate|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            $result = $ai_manager->generate_keyword_paragraph($content, $options);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $result,
                'message' => __('Added a keyword-focused paragraph', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Enable ThinkRank's Global SEO schema output for a post's post type.
     *
     * Sets a sensible default schema type (Article for posts, WebPage for pages)
     * when none is configured yet, so ThinkRank emits JSON-LD for the post. This
     * resolves the "add structured data" suggestion, which the scorer now credits
     * when ThinkRank schema is active.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response describing the enabled schema type.
     */
    public function enable_schema_for_post(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = (int) $request->get_param('post_id');
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Post not found.', 'thinkrank'),
            ], 404);
        }

        $post_type = $post->post_type;
        $settings = get_option('thinkrank_global_seo_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        if (!isset($settings[$post_type]) || !is_array($settings[$post_type])) {
            $settings[$post_type] = [];
        }

        $already_enabled = !empty($settings[$post_type]['schema_type']);
        if (!$already_enabled) {
            if ($post_type === 'page') {
                $settings[$post_type]['schema_type'] = 'WebPage';
            } else {
                $settings[$post_type]['schema_type'] = 'Article';
                if (empty($settings[$post_type]['article_type'])) {
                    $settings[$post_type]['article_type'] = 'BlogPosting';
                }
            }
            update_option('thinkrank_global_seo_settings', $settings);
        }

        $schema_type = $settings[$post_type]['schema_type'];

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'schema_type' => $schema_type,
                'post_type' => $post_type,
                'already_enabled' => $already_enabled,
            ],
            'message' => $already_enabled
                ? __('Schema was already enabled for this post type.', 'thinkrank')
                /* translators: %s: schema type. */
                : sprintf(__('Enabled %s schema for this post type.', 'thinkrank'), $schema_type),
        ]);
    }

    /**
     * Test AI connection for specified provider
     *
     * @param \WP_REST_Request $request Request object containing api_key and provider parameters
     * @return \WP_REST_Response Response object with connection test results
     * @throws \Exception When API connection test encounters unexpected errors
     */
    public function test_ai_connection(\WP_REST_Request $request): \WP_REST_Response {
        // Rate limiting: per user/IP per route
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_test|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            $api_key = $request->get_param('api_key');
            $provider = $request->get_param('provider') ?: 'openai';

            // If no API key provided in request, try to get from saved settings
            if (empty($api_key)) {
                $settings = \ThinkRank\Core\Settings::instance();
                if ($provider === 'openai') {
                    $api_key = (string) $settings->get('openai_api_key', '');
                } elseif ($provider === 'claude') {
                    $api_key = (string) $settings->get('claude_api_key', '');
                } elseif ($provider === 'openrouter') {
                    $api_key = (string) $settings->get('openrouter_api_key', '');
                } else {
                    $api_key = (string) $settings->get('gemini_api_key', '');
                }

                if (empty($api_key)) {
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => __('No API key provided or saved for the selected provider.', 'thinkrank'),
                    ], 400);
                }
            }

            // Test the connection with a simple API call
            if ($provider === 'openai') {
                $result = $this->test_openai_connection($api_key);
            } elseif ($provider === 'claude') {
                $result = $this->test_claude_connection($api_key);
            } elseif ($provider === 'openrouter') {
                $result = $this->test_openrouter_connection($api_key);
            } else {
                $result = $this->test_gemini_connection($api_key);
            }

            return new \WP_REST_Response($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test OpenAI API connection
     *
     * @param string $api_key API key to test
     * @return array Test result
     */
    private function test_openai_connection(string $api_key): array {
        $url = 'https://api.openai.com/v1/models';

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to OpenAI API: ', 'thinkrank') . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            $data = json_decode($body, true);
            if (isset($data['data']) && is_array($data['data'])) {
                return [
                    'success' => true,
                    'message' => __('OpenAI API connection successful!', 'thinkrank'),
                    'models_count' => count($data['data']),
                ];
            }
        }

        // Handle error response
        $error_data = json_decode($body, true);
        $error_message = $error_data['error']['message'] ?? __('Unknown API error', 'thinkrank');

        return [
            'success' => false,
            'message' => __('OpenAI API Error: ', 'thinkrank') . $error_message,
        ];
    }

    /**
     * Test OpenRouter API connection
     *
     * @param string $api_key API key to test
     * @return array Test result
     */
    private function test_openrouter_connection(string $api_key): array {
        // Validate the key format first (OpenRouter keys start with "sk-or-").
        if (!str_starts_with($api_key, 'sk-or-')) {
            return [
                'success' => false,
                'message' => __('Invalid OpenRouter API key format. Should start with "sk-or-"', 'thinkrank'),
            ];
        }

        // The key endpoint validates the credential and returns its metadata.
        $url = 'https://openrouter.ai/api/v1/key';

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url('/'),
                'X-Title' => 'ThinkRank',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to OpenRouter API: ', 'thinkrank') . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            $data = json_decode($body, true);
            if (isset($data['data']) && is_array($data['data'])) {
                return [
                    'success' => true,
                    'message' => __('OpenRouter API connection successful!', 'thinkrank'),
                ];
            }
        }

        // Handle error response
        $error_data = json_decode($body, true);
        $error_message = $error_data['error']['message'] ?? __('Unknown API error', 'thinkrank');

        return [
            'success' => false,
            'message' => __('OpenRouter API Error: ', 'thinkrank') . $error_message,
        ];
    }

    /**
     * Test Claude API connection
     *
     * @param string $api_key API key to test
     * @return array Test result
     */
    private function test_claude_connection(string $api_key): array {
        // First validate the key format
        if (!str_starts_with($api_key, 'sk-ant-')) {
            return [
                'success' => false,
                'message' => __('Invalid Claude API key format. Should start with "sk-ant-"', 'thinkrank'),
            ];
        }

        // Test with a simple API call
        $url = 'https://api.anthropic.com/v1/messages';

        // Get the configured Claude model, with fallback to a current model.
        // Self-heal retired/unavailable IDs saved by earlier versions.
        $claude_model = \ThinkRank\Core\Settings::instance()->get('claude_model', 'claude-sonnet-5');
        $claude_model = \ThinkRank\AI\Claude_Client::normalize_model($claude_model);

        $body = [
            'model' => $claude_model,
            'max_tokens' => 10,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hello'
                ]
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'x-api-key' => $api_key,
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to Claude API: ', 'thinkrank') . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            return [
                'success' => true,
                'message' => __('Claude API connection successful!', 'thinkrank'),
            ];
        } else {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? __('Unknown API error', 'thinkrank');

            return [
                'success' => false,
                /* translators: %1$d: HTTP status code, %2$s: error message from Claude API */
                'message' => sprintf(__('Claude API error (%1$d): %2$s', 'thinkrank'), $status_code, $error_message),
            ];
        }
    }

    /**
     * Test Gemini API connection
     *
     * @param string $api_key API key to test
     * @return array Test result
     */
    private function test_gemini_connection(string $api_key): array {
        // Test with a simple API call
        $gemini_model = \ThinkRank\Core\Settings::instance()->get('gemini_model', 'gemini-2.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$gemini_model}:generateContent?key={$api_key}";

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Hello']
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 10,
                'temperature' => 0.1,
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to Gemini API: ', 'thinkrank') . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            return [
                'success' => true,
                'message' => __('Gemini API connection successful!', 'thinkrank'),
            ];
        } else {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? __('Unknown API error', 'thinkrank');

            return [
                'success' => false,
                /* translators: %1$d: HTTP status code, %2$s: error message from Gemini API */
                'message' => sprintf(__('Gemini API error (%1$d): %2$s', 'thinkrank'), $status_code, $error_message),
            ];
        }
    }

    /**
     * Get AI providers
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_ai_providers(\WP_REST_Request $request): \WP_REST_Response {
        $ai_manager = new \ThinkRank\AI\Manager();
        $providers = $ai_manager->get_available_providers();

        return new \WP_REST_Response($providers);
    }

    /**
     * Get AI status
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function get_ai_status(\WP_REST_Request $request): \WP_REST_Response {
        $ai_manager = new \ThinkRank\AI\Manager();
        $status = $ai_manager->get_provider_status();

        return new \WP_REST_Response($status);
    }

    /**
     * Analyze content for SEO optimization
     *
     * @param \WP_REST_Request $request Request object containing content, metadata, and optional post_id
     * @return \WP_REST_Response Response object with analysis results or error message
     * @throws \Exception When AI analysis fails or AI client initialization fails
     */
    public function analyze_content(\WP_REST_Request $request): \WP_REST_Response {
        $content = $request->get_param('content');
        $metadata = $request->get_param('metadata') ?: [];
        $post_id = $request->get_param('post_id');

        // Rate limiting: per user/IP per route
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $bucket_id = 'ai_analyze|' . ($user_id ?: $ip);
        $limit = (int) \ThinkRank\Core\Settings::instance()->get('max_requests_per_minute', 10);
        $allowed = $this->enforce_rate_limit($bucket_id, $limit);
        if (is_wp_error($allowed)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $allowed->get_error_message(),
            ], $allowed->get_error_data()['status'] ?? 429);
        }

        try {
            // Get AI manager instance
            $ai_manager = new \ThinkRank\AI\Manager();
            $ai_manager->initialize_client();

            // Perform content analysis
            $analysis = $ai_manager->analyze_content($content, $metadata);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $analysis,
                'message' => __('Content analyzed successfully', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Sanitize metadata object for API endpoints
     *
     * @param mixed $metadata Metadata to sanitize
     * @return array Sanitized metadata array
     */
    public function sanitize_metadata_object($metadata): array {
        if (!is_array($metadata)) {
            return [];
        }

        $sanitized = [];
        foreach ($metadata as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (is_string($value)) {
                $sanitized[$sanitized_key] = sanitize_text_field($value);
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$sanitized_key] = array_map('sanitize_text_field', $value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            }
            // Skip other data types for security
        }

        return $sanitized;
    }

    /**
     * Register endpoint classes
     *
     * @return void
     */
    public function register_endpoint_classes(): void {
        // Register endpoint classes that exist
        try {
            $site_identity_endpoint = new Site_Identity_Endpoint();
            $site_identity_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Site Identity endpoint
        }

        try {
            $performance_endpoint = new Performance_Endpoint();
            $performance_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Performance endpoint
        }

        try {
            $schema_endpoint = new Schema_Endpoint();
            $schema_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Schema endpoint
        }

        try {
            $settings_endpoint = new Settings_Management_Endpoint();
            $settings_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Settings Management endpoint
        }

        try {
            $integrations_endpoint = new Integrations_Endpoint();
            $integrations_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Integrations endpoint
        }

        try {
            $social_platforms_endpoint = new Social_Platforms_Endpoint();
            $social_platforms_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Social Platforms endpoint
        }

        try {
            $content_brief_endpoint = new Content_Brief_Endpoint();
            $content_brief_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Content Brief endpoint
        }

        try {
            $social_media_endpoint = new Social_Media_Endpoint();
            $social_media_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Social Media endpoint
        }

        try {
            $sitemap_endpoint = new Sitemap_Endpoint();
            $sitemap_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Sitemap endpoint
        }

        try {
            $llms_txt_endpoint = new LLMs_Txt_Endpoint();
            $llms_txt_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register LLMs.txt endpoint
        }

        try {
            $global_seo_endpoint = new Global_SEO_Endpoint();
            $global_seo_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Global SEO endpoint
        }

        try {
            $image_seo_endpoint = new Image_SEO_Endpoint();
            $image_seo_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Image SEO endpoint
        }

        try {
            $import_controller = new Import_Controller();
            $import_controller->register_routes();
        } catch (\Exception $e) {
            // Failed to register Import endpoint
        }

        try {
            $setup_wizard_endpoint = new Setup_Wizard_Endpoint();
            $setup_wizard_endpoint->register_routes();
        } catch (\Exception $e) {
            // Failed to register Setup Wizard endpoint
        }
    }
}
