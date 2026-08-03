<?php
/**
 * Settings Management API Endpoints Class
 *
 * REST API endpoints for centralized settings management across all SEO managers
 * including global settings CRUD, validation and schema management, import/export
 * functionality, and backup/restore operations. Provides comprehensive API access
 * to Settings Manager functionality with proper authentication and validation.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Settings_Manager;
use ThinkRank\SEO\Site_Identity_Manager;
use ThinkRank\SEO\Performance_Monitoring_Manager;
use ThinkRank\SEO\AI_Content_Analyzer;
use ThinkRank\SEO\Content_Optimization_Manager;
use ThinkRank\SEO\Schema_Management_System;
use ThinkRank\SEO\Social_Meta_Manager;
use ThinkRank\SEO\Sitemap_Generator;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings Management API Endpoints Class
 *
 * Provides REST API endpoints for centralized settings management operations
 * including global settings CRUD, validation, import/export, backup/restore,
 * and cross-manager settings coordination with proper authentication and validation.
 *
 * @since 1.0.0
 */
class Settings_Management_Endpoint extends WP_REST_Controller {

    /**
     * Settings Manager instance
     *
     * @since 1.0.0
     * @var Settings_Manager
     */
    private Settings_Manager $settings_manager;

    /**
     * Lazily constructed SEO Manager instances, keyed by category
     *
     * @since 1.0.0
     * @var array
     */
    private array $seo_managers = [];

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
    protected $rest_base = 'settings-management';

    /**
     * Supported setting categories
     *
     * @since 1.0.0
     * @var array
     */
    private array $setting_categories = [
        'site_identity' => 'Site Identity & Global SEO',
        'content_analysis' => 'AI Content Analysis',
        'content_optimization' => 'Content Optimization',
        'performance_monitoring' => 'Performance Monitoring',
        'schema_management' => 'Schema Management',
        'social_media' => 'Social Media & Open Graph',
        'sitemap' => 'XML Sitemap Management',
        'integrations' => 'External Integrations',
        'analytics_integration' => 'Analytics Integration',
        'seo_analytics' => 'SEO Analytics & Intelligence',
        'global_defaults' => 'Global Default Settings'
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->settings_manager = new Settings_Manager();
    }

    /**
     * Category → manager class map. Instances are created lazily: this
     * endpoint is constructed on every REST request (any namespace), and
     * eagerly building eight manager chains added measurable overhead to
     * unrelated requests.
     *
     * @var array<string,class-string>
     */
    private array $seo_manager_classes = [
        'site_identity' => Site_Identity_Manager::class,
        'performance_monitoring' => Performance_Monitoring_Manager::class,
        'ai_content_analyzer' => AI_Content_Analyzer::class,
        'content_optimization' => Content_Optimization_Manager::class,
        'schema_management' => Schema_Management_System::class,
        'social_media' => Social_Meta_Manager::class,
        'sitemap' => Sitemap_Generator::class,
        'analytics_integration' => Performance_Monitoring_Manager::class,
    ];

    /**
     * Whether a category has an associated SEO manager
     *
     * @param string $category Category key
     * @return bool
     */
    private function has_seo_manager(string $category): bool {
        return isset($this->seo_manager_classes[$category]);
    }

    /**
     * Get (and lazily construct) the SEO manager for a category
     *
     * @param string $category Category key
     * @return object The manager instance
     */
    private function get_seo_manager(string $category): object {
        if (!isset($this->seo_managers[$category])) {
            $class = $this->seo_manager_classes[$category];
            $this->seo_managers[$category] = new $class();
        }
        return $this->seo_managers[$category];
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Global settings management
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/global',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_global_settings'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_global_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_global_settings_args()
                ]
            ]
        );

        // Category-specific settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/category/(?P<category>[a-zA-Z0-9_-]+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_category_settings'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'category' => [
                            'required' => true,
                            'type' => 'string',
                            'enum' => array_keys($this->setting_categories)
                        ]
                    ]
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_category_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_category_settings_args()
                ]
            ]
        );

        // Settings validation and schema
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/validate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'validate_settings'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_validation_args()
                ]
            ]
        );

        // Settings schema management
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/schema',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_settings_schema'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Settings import/export
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'export_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_export_args()
                ]
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'import_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_import_args()
                ]
            ]
        );

        // Settings backup/restore
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/backup',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'create_settings_backup'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_backup_args()
                ]
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/restore',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'restore_settings_backup'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_restore_args()
                ]
            ]
        );

        // Settings reset
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/reset',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'reset_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_reset_args()
                ]
            ]
        );

        // Database maintenance operations
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/maintenance/performance-indexes',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'add_performance_indexes'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );
    }

    /**
     * Setting keys that hold secrets (encrypted at rest).
     *
     * Mirrors ThinkRank\Core\Settings::$encrypted_keys — keep in sync. These must
     * never be returned decrypted from the read/export endpoints.
     *
     * @var string[]
     */
    private const SENSITIVE_SETTING_KEYS = [
        'openai_api_key',
        'claude_api_key',
        'gemini_api_key',
        'openrouter_api_key',
        'google_analytics_api_key',
        'google_search_console_api_key',
        'google_pagespeed_api_key',
        'google_access_token',
        'google_refresh_token',
        'pinterest_site_verification',
        'instagram_verification',
        'tiktok_verification',
    ];

    /**
     * Mask a secret value for display: keeps a "has value" signal and the last
     * four characters, never the secret itself. Empty stays empty.
     *
     * @param mixed $value Raw setting value.
     * @return string Masked value.
     */
    private function mask_secret_value($value): string {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $suffix = strlen($value) > 4 ? substr($value, -4) : '';
        return '••••' . $suffix;
    }

    /**
     * Redact secrets from a category => settings map before it leaves the site.
     *
     * Read responses mask secrets (presence + last 4). Exports drop them entirely
     * so long-lived third-party credentials never land in an export file (and a
     * masked value can't corrupt the real key on re-import).
     *
     * @param array $settings   category => [key => value] map.
     * @param bool  $for_export Whether this is an export (drop) vs a read (mask).
     * @return array Redacted map.
     */
    private function redact_sensitive_settings(array $settings, bool $for_export = false): array {
        foreach ($settings as $category => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (!in_array($key, self::SENSITIVE_SETTING_KEYS, true)) {
                    continue;
                }
                if ($for_export) {
                    unset($values[$key]);
                } else {
                    $values[$key] = $this->mask_secret_value($value);
                }
            }
            $settings[$category] = $values;
        }
        return $settings;
    }

    /**
     * Get global settings across all categories
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_global_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $include_categories = $request->get_param('categories') ?? array_keys($this->setting_categories);
            $include_schema = $request->get_param('include_schema') ?? false;

            $global_settings = [];
            $settings_schema = [];

            foreach ($include_categories as $category) {
                if (!isset($this->setting_categories[$category])) {
                    continue;
                }

                // Get settings for each category using Settings Manager
                $category_settings = $this->settings_manager->get_settings($category);
                $global_settings[$category] = $category_settings;

                // Get schema if requested
                if ($include_schema && $this->has_seo_manager($category)) {
                    $settings_schema[$category] = $this->get_seo_manager($category)->get_settings_schema($category);
                }
            }

            // Get global metadata
            $metadata = [
                'total_categories' => count($this->setting_categories),
                'loaded_categories' => count($global_settings),
                'last_updated' => $this->get_last_settings_update(),
                'settings_version' => $this->get_settings_version()
            ];

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $this->redact_sensitive_settings($global_settings),
                    'schema' => $settings_schema,
                    'metadata' => $metadata,
                    'categories' => $this->setting_categories
                ],
                'message' => 'Global settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve global settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update global settings across multiple categories
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_global_settings(WP_REST_Request $request) {
        try {
            $settings = $request->get_param('settings');
            $validate_before_update = $request->get_param('validate') ?? true;

            // Validate settings structure
            if (empty($settings) || !is_array($settings)) {
                return new WP_Error(
                    'invalid_settings',
                    'Settings must be provided as an array',
                    ['status' => 400]
                );
            }

            // Each per-category value must be an array before it reaches the
            // strict array-typed manager methods; reject non-array values with a
            // 400 instead of letting them surface as an uncaught TypeError.
            foreach ($settings as $category => $category_settings) {
                if (!is_array($category_settings)) {
                    return new WP_Error(
                        'invalid_settings',
                        "Settings for category '{$category}' must be provided as an object",
                        ['status' => 400]
                    );
                }
            }

            $validation_results = [];
            $update_results = [];

            // Validate all settings before updating if requested
            if ($validate_before_update) {
                foreach ($settings as $category => $category_settings) {
                    if (!isset($this->setting_categories[$category])) {
                        continue;
                    }

                    if ($this->has_seo_manager($category)) {
                        $validation = $this->get_seo_manager($category)->validate_settings($category_settings);
                        $validation_results[$category] = $validation;

                        if (!$validation['valid']) {
                            return new WP_Error(
                                'validation_failed',
                                "Settings validation failed for category: {$category}",
                                [
                                    'status' => 400,
                                    'validation_results' => $validation_results
                                ]
                            );
                        }
                    }
                }
            }

            // Update settings for each category
            foreach ($settings as $category => $category_settings) {
                if (!isset($this->setting_categories[$category])) {
                    continue;
                }

                try {
                    // Update using Settings Manager
                    $update_success = $this->settings_manager->update_settings($category_settings, $category);

                    // Also update through specific SEO manager if available
                    if ($this->has_seo_manager($category)) {
                        $manager_update = $this->get_seo_manager($category)->save_settings('site', null, $category_settings);
                        $update_success = $update_success && $manager_update;
                    }

                    $update_results[$category] = [
                        'success' => $update_success,
                        'settings_count' => count($category_settings)
                    ];

                } catch (\Exception $e) {
                    $update_results[$category] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Update settings version and timestamp
            $this->update_settings_metadata();

            // Get updated settings
            $updated_settings = [];
            foreach (array_keys($settings) as $category) {
                if (isset($this->setting_categories[$category])) {
                    $updated_settings[$category] = $this->settings_manager->get_settings($category);
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'updated_settings' => $updated_settings,
                    'validation_results' => $validation_results,
                    'update_results' => $update_results,
                    'settings_version' => $this->get_settings_version()
                ],
                'message' => 'Global settings updated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'update_failed',
                'Global settings update failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get settings for specific category
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_category_settings(WP_REST_Request $request) {
        try {
            $category = $request->get_param('category');
            $include_schema = $request->get_param('include_schema') ?? false;

            // Validate category
            if (!isset($this->setting_categories[$category])) {
                return new WP_Error(
                    'invalid_category',
                    'Invalid settings category provided',
                    ['status' => 400]
                );
            }

            // Get category settings
            $category_settings = $this->settings_manager->get_settings($category);

            // Get schema if requested
            $schema = [];
            if ($include_schema && $this->has_seo_manager($category)) {
                $schema = $this->get_seo_manager($category)->get_settings_schema($category);
            }

            // Get category metadata
            $metadata = [
                'category' => $category,
                'category_name' => $this->setting_categories[$category],
                'settings_count' => count($category_settings),
                'last_updated' => $this->get_category_last_update($category),
                'has_manager' => $this->has_seo_manager($category)
            ];

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $category_settings,
                    'schema' => $schema,
                    'metadata' => $metadata
                ],
                'message' => "Settings for category '{$category}' retrieved successfully"
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'retrieval_failed',
                'Category settings retrieval failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Update settings for specific category
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_category_settings(WP_REST_Request $request) {
        try {
            $category = $request->get_param('category');
            $request_data = $request->get_param('settings');
            $validate_before_update = $request->get_param('validate') ?? true;

            // Extract only the actual settings data, not metadata
            if (isset($request_data['settings'])) {
                // If settings are nested under 'settings' key, use that
                $settings = $request_data['settings'];
            } else {
                // Otherwise use the data directly
                $settings = $request_data;
            }

            // Validate category
            if (!isset($this->setting_categories[$category])) {
                return new WP_Error(
                    'invalid_category',
                    'Invalid settings category provided',
                    ['status' => 400]
                );
            }

            // Validate settings
            if (empty($settings) || !is_array($settings)) {
                return new WP_Error(
                    'invalid_settings',
                    'Settings must be provided as an array',
                    ['status' => 400]
                );
            }

            $validation_result = ['valid' => true];

            // Validate settings if requested
            if ($validate_before_update && $this->has_seo_manager($category)) {
                $validation_result = $this->get_seo_manager($category)->validate_settings($settings);

                if (!$validation_result['valid']) {
                    return new WP_Error(
                        'validation_failed',
                        "Settings validation failed for category: {$category}",
                        [
                            'status' => 400,
                            'validation_errors' => $validation_result['errors'],
                            'validation_warnings' => $validation_result['warnings']
                        ]
                    );
                }
            }

            // Update settings
            $update_success = $this->settings_manager->update_settings($settings, $category);

            // Also update through specific SEO manager if available
            if ($this->has_seo_manager($category)) {
                $context_type = $request->get_param('context_type') ?? 'site';
                $context_id = $request->get_param('context_id') ?? null;
                $manager_update = $this->get_seo_manager($category)->save_settings($context_type, $context_id, $settings);
                $update_success = $update_success && $manager_update;
            }

            if (!$update_success) {
                return new WP_Error(
                    'update_failed',
                    "Failed to update settings for category: {$category}",
                    ['status' => 500]
                );
            }

            // Clear analytics cache when GSC/GA settings change so fresh data is fetched
            if ($category === 'seo_analytics') {
                foreach (['7d', '30d', '90d'] as $range) {
                    delete_transient("analytics_dashboard_v5_{$range}");
                    delete_transient("seo_opportunities_{$range}");
                    delete_transient("seo_insights_{$range}");
                }
                delete_transient('indexing_status');
            }

            // Update category metadata
            $this->update_category_metadata($category);

            // Get updated settings
            $updated_settings = $this->settings_manager->get_settings($category);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'category' => $category,
                    'updated_settings' => $updated_settings,
                    'validation_result' => $validation_result,
                    'settings_count' => count($updated_settings)
                ],
                'message' => "Settings for category '{$category}' updated successfully"
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'update_failed',
                'Category settings update failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Validate settings across categories
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function validate_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $settings = $request->get_param('settings');
            if (!is_array($settings)) {
                $settings = [];
            }
            $categories = $request->get_param('categories') ?? array_keys($this->setting_categories);

            $validation_results = [];
            $overall_valid = true;

            foreach ($categories as $category) {
                if (!isset($this->setting_categories[$category])) {
                    continue;
                }

                $category_settings = $settings[$category] ?? [];
                if (!is_array($category_settings)) {
                    $validation_results[$category] = [
                        'valid' => false,
                        'errors' => ['Settings for this category must be an object'],
                        'warnings' => [],
                        'suggestions' => [],
                    ];
                    $overall_valid = false;
                    continue;
                }

                if ($this->has_seo_manager($category)) {
                    $validation = $this->get_seo_manager($category)->validate_settings($category_settings);
                    $validation_results[$category] = $validation;

                    if (!$validation['valid']) {
                        $overall_valid = false;
                    }
                } else {
                    // Basic validation for categories without specific managers
                    $validation_results[$category] = [
                        'valid' => true,
                        'errors' => [],
                        'warnings' => [],
                        'suggestions' => []
                    ];
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'validation_results' => $validation_results,
                    'overall_valid' => $overall_valid,
                    'validated_categories' => count($validation_results),
                    'validation_timestamp' => current_time('mysql')
                ],
                'message' => 'Settings validation completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Settings validation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get settings schema for all categories
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings_schema(WP_REST_Request $request): WP_REST_Response {
        try {
            $categories = $request->get_param('categories') ?? array_keys($this->setting_categories);

            $schema_data = [];

            foreach ($categories as $category) {
                if (!isset($this->setting_categories[$category])) {
                    continue;
                }

                if ($this->has_seo_manager($category)) {
                    $schema_data[$category] = [
                        'schema' => $this->get_seo_manager($category)->get_settings_schema($category),
                        'defaults' => $this->get_seo_manager($category)->get_default_settings($category),
                        'category_name' => $this->setting_categories[$category]
                    ];
                } else {
                    $schema_data[$category] = [
                        'schema' => [],
                        'defaults' => [],
                        'category_name' => $this->setting_categories[$category]
                    ];
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'schema' => $schema_data,
                    'categories' => $this->setting_categories,
                    'schema_version' => $this->get_schema_version(),
                    'generated_at' => current_time('mysql')
                ],
                'message' => 'Settings schema retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve settings schema: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function export_settings(WP_REST_Request $request) {
        try {
            $categories = $request->get_param('categories') ?? array_keys($this->setting_categories);
            $format = $request->get_param('format') ?? 'json';
            $include_metadata = $request->get_param('include_metadata') ?? true;

            // Validate format
            if (!in_array($format, ['json', 'yaml', 'xml'], true)) {
                return new WP_Error(
                    'invalid_format',
                    'Invalid export format. Supported formats: json, yaml, xml',
                    ['status' => 400]
                );
            }

            $export_data = [];

            // Export settings for each category
            foreach ($categories as $category) {
                if (!isset($this->setting_categories[$category])) {
                    continue;
                }

                $export_data[$category] = $this->settings_manager->get_settings($category);
            }

            // Never let secrets (API keys, OAuth tokens) leave the site in an
            // export file — strip them entirely.
            $export_data = $this->redact_sensitive_settings($export_data, true);

            // Add metadata if requested
            $metadata = [];
            if ($include_metadata) {
                $metadata = [
                    'export_timestamp' => current_time('mysql'),
                    'export_version' => $this->get_settings_version(),
                    'wordpress_version' => get_bloginfo('version'),
                    'thinkrank_version' => defined('THINKRANK_VERSION') ? THINKRANK_VERSION : '',
                    'site_url' => home_url(),
                    'exported_categories' => $categories
                ];
            }

            // Format export data
            $formatted_export = $this->format_export_data($export_data, $metadata, $format);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'export_data' => $formatted_export,
                    'format' => $format,
                    'metadata' => $metadata,
                    'exported_categories' => count($export_data)
                ],
                'message' => 'Settings exported successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'export_failed',
                'Settings export failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Import settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function import_settings(WP_REST_Request $request) {
        try {
            $import_data = $request->get_param('import_data');
            $format = $request->get_param('format') ?? 'json';
            $validate_before_import = $request->get_param('validate') ?? true;
            $overwrite_existing = $request->get_param('overwrite_existing') ?? false;

            // Validate import data
            if (empty($import_data)) {
                return new WP_Error(
                    'missing_import_data',
                    'Import data is required',
                    ['status' => 400]
                );
            }

            // Parse import data based on format
            $parsed_data = $this->parse_import_data($import_data, $format);

            if (!$parsed_data) {
                return new WP_Error(
                    'invalid_import_data',
                    'Failed to parse import data',
                    ['status' => 400]
                );
            }

            if (!is_array($parsed_data)) {
                return new WP_Error(
                    'invalid_import_data',
                    'Import data must be an object of settings categories',
                    ['status' => 400]
                );
            }

            // Reject non-array per-category values before they reach the strict
            // array-typed manager methods (avoids an uncaught TypeError).
            foreach ($parsed_data as $category => $category_settings) {
                if (!is_array($category_settings)) {
                    return new WP_Error(
                        'invalid_import_data',
                        "Settings for category '{$category}' must be an object",
                        ['status' => 400]
                    );
                }
            }

            $import_results = [];
            $validation_results = [];

            // Validate imported settings if requested
            if ($validate_before_import) {
                foreach ($parsed_data as $category => $category_settings) {
                    if (!isset($this->setting_categories[$category])) {
                        continue;
                    }

                    if ($this->has_seo_manager($category)) {
                        $validation = $this->get_seo_manager($category)->validate_settings($category_settings);
                        $validation_results[$category] = $validation;

                        if (!$validation['valid']) {
                            return new WP_Error(
                                'import_validation_failed',
                                "Import validation failed for category: {$category}",
                                [
                                    'status' => 400,
                                    'validation_results' => $validation_results
                                ]
                            );
                        }
                    }
                }
            }

            // Import settings for each category
            foreach ($parsed_data as $category => $category_settings) {
                if (!isset($this->setting_categories[$category])) {
                    $import_results[$category] = [
                        'success' => false,
                        'error' => 'Invalid category'
                    ];
                    continue;
                }

                try {
                    // Check if settings exist and handle overwrite
                    $existing_settings = $this->settings_manager->get_settings($category);

                    if (!empty($existing_settings) && !$overwrite_existing) {
                        $import_results[$category] = [
                            'success' => false,
                            'error' => 'Settings exist and overwrite is disabled'
                        ];
                        continue;
                    }

                    // Import settings
                    $import_success = $this->settings_manager->update_settings($category_settings, $category);

                    // Also update through specific SEO manager if available
                    if ($this->has_seo_manager($category)) {
                        $manager_update = $this->get_seo_manager($category)->save_settings('site', null, $category_settings);
                        $import_success = $import_success && $manager_update;
                    }

                    $import_results[$category] = [
                        'success' => $import_success,
                        'settings_count' => count($category_settings)
                    ];

                } catch (\Exception $e) {
                    $import_results[$category] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Update settings metadata
            $this->update_settings_metadata();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'import_results' => $import_results,
                    'validation_results' => $validation_results,
                    'imported_categories' => count($import_results),
                    'successful_imports' => count(array_filter($import_results, function($result) {
                        return $result['success'];
                    }))
                ],
                'message' => 'Settings import completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'import_failed',
                'Settings import failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Create settings backup
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function create_settings_backup(WP_REST_Request $request) {
        try {
            $backup_name = $request->get_param('backup_name') ?? 'backup_' . gmdate('Y-m-d_H-i-s');
            $categories = $request->get_param('categories') ?? array_keys($this->setting_categories);
            $description = $request->get_param('description') ?? '';

            // Create backup data
            $backup_data = [];
            foreach ($categories as $category) {
                if (isset($this->setting_categories[$category])) {
                    $backup_data[$category] = $this->settings_manager->get_settings($category);
                }
            }

            // Create backup metadata
            $backup_metadata = [
                'backup_name' => $backup_name,
                'description' => $description,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id(),
                'categories' => $categories,
                'settings_version' => $this->get_settings_version(),
                'wordpress_version' => get_bloginfo('version')
            ];

            // Save backup
            $backup_id = $this->save_settings_backup($backup_data, $backup_metadata);

            if (!$backup_id) {
                return new WP_Error(
                    'backup_failed',
                    'Failed to create settings backup',
                    ['status' => 500]
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'backup_id' => $backup_id,
                    'backup_name' => $backup_name,
                    'backup_metadata' => $backup_metadata,
                    'backed_up_categories' => count($backup_data)
                ],
                'message' => 'Settings backup created successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'backup_failed',
                'Settings backup failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Restore settings from backup
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function restore_settings_backup(WP_REST_Request $request) {
        try {
            $backup_id = $request->get_param('backup_id');
            $categories = $request->get_param('categories') ?? null;
            $create_restore_point = $request->get_param('create_restore_point') ?? true;

            // Validate backup ID
            if (empty($backup_id)) {
                return new WP_Error(
                    'missing_backup_id',
                    'Backup ID is required',
                    ['status' => 400]
                );
            }

            // Load backup data
            $backup_data = $this->load_settings_backup($backup_id);

            if (!$backup_data) {
                return new WP_Error(
                    'backup_not_found',
                    'Backup not found or could not be loaded',
                    ['status' => 404]
                );
            }

            // Create restore point if requested. Abort if it couldn't be saved,
            // so the current configuration isn't overwritten with no rollback.
            $restore_point_id = null;
            if ($create_restore_point) {
                $restore_point_id = $this->create_restore_point();
                if ($restore_point_id === '') {
                    return new WP_Error(
                        'restore_point_failed',
                        'Could not create a restore point; aborting restore to avoid unrecoverable settings loss.',
                        ['status' => 500]
                    );
                }
            }

            $restore_results = [];

            // Determine categories to restore
            $categories_to_restore = $categories ?? array_keys($backup_data['settings']);

            // Restore settings for each category
            foreach ($categories_to_restore as $category) {
                if (!isset($backup_data['settings'][$category])) {
                    $restore_results[$category] = [
                        'success' => false,
                        'error' => 'Category not found in backup'
                    ];
                    continue;
                }

                try {
                    $category_settings = $backup_data['settings'][$category];

                    // Restore settings
                    $restore_success = $this->settings_manager->update_settings($category_settings, $category);

                    // Also update through specific SEO manager if available
                    if ($this->has_seo_manager($category)) {
                        $manager_update = $this->get_seo_manager($category)->save_settings('site', null, $category_settings);
                        $restore_success = $restore_success && $manager_update;
                    }

                    $restore_results[$category] = [
                        'success' => $restore_success,
                        'settings_count' => count($category_settings)
                    ];

                } catch (\Exception $e) {
                    $restore_results[$category] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Update settings metadata
            $this->update_settings_metadata();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'backup_id' => $backup_id,
                    'restore_point_id' => $restore_point_id,
                    'restore_results' => $restore_results,
                    'restored_categories' => count($restore_results),
                    'backup_metadata' => $backup_data['metadata']
                ],
                'message' => 'Settings restored from backup successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'restore_failed',
                'Settings restore failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Reset settings to defaults
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function reset_settings(WP_REST_Request $request) {
        try {
            $categories = $request->get_param('categories') ?? array_keys($this->setting_categories);
            $create_backup = $request->get_param('create_backup') ?? true;

            // Create backup before reset if requested. If the backup was asked
            // for but couldn't be persisted, abort rather than silently wiping
            // settings with no rollback — the whole point of the flag is safety.
            $backup_id = null;
            if ($create_backup) {
                $backup_id = $this->create_pre_reset_backup($categories);
                if ($backup_id === '') {
                    return new WP_Error(
                        'backup_failed',
                        'Could not create a pre-reset backup; aborting reset to avoid unrecoverable settings loss.',
                        ['status' => 500]
                    );
                }
            }

            $reset_results = [];

            foreach ($categories as $category) {
                if (!isset($this->setting_categories[$category])) {
                    continue;
                }

                try {
                    // Get default settings
                    $default_settings = [];
                    if ($this->has_seo_manager($category)) {
                        $default_settings = $this->get_seo_manager($category)->get_default_settings($category);
                    }

                    // Reset to defaults
                    $reset_success = $this->settings_manager->update_settings($default_settings, $category);

                    // Also reset through specific SEO manager if available
                    if ($this->has_seo_manager($category)) {
                        $manager_reset = $this->get_seo_manager($category)->save_settings('site', null, $default_settings);
                        $reset_success = $reset_success && $manager_reset;
                    }

                    $reset_results[$category] = [
                        'success' => $reset_success,
                        'default_settings_count' => count($default_settings)
                    ];

                } catch (\Exception $e) {
                    $reset_results[$category] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Update settings metadata
            $this->update_settings_metadata();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'reset_results' => $reset_results,
                    'backup_id' => $backup_id,
                    'reset_categories' => count($reset_results),
                    'reset_timestamp' => current_time('mysql')
                ],
                'message' => 'Settings reset to defaults completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'reset_failed',
                'Settings reset failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Add performance indexes to database tables
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function add_performance_indexes(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            // Import the Database_Schema class
            if (!class_exists('ThinkRank\\Database\\Database_Schema')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/database/class-database-schema.php';
            }

            $schema = new \ThinkRank\Database\Database_Schema();
            $success = $schema->add_performance_indexes();

            if ($success) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'Performance indexes added successfully',
                    'data' => [
                        'indexes_added' => true,
                        'timestamp' => current_time('mysql')
                    ]
                ], 200);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Some performance indexes could not be added. Check error logs for details.',
                    'data' => [
                        'indexes_added' => false,
                        'timestamp' => current_time('mysql')
                    ]
                ], 200);
            }

        } catch (\Exception $e) {
            return new WP_Error(
                'performance_indexes_failed',
                'Failed to add performance indexes: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Permission callbacks
     */

    /**
     * Check permissions for reading settings data
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_read_permissions(): bool {
        // Plugin SEO/AI config is not subscriber-visible — require the same
        // management capability as the write routes.
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_settings');
    }

    /**
     * Check permissions for managing settings
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_settings');
    }

    /**
     * Helper methods
     */

    /**
     * Get last settings update timestamp
     *
     * @since 1.0.0
     *
     * @return string|null Last update timestamp
     */
    private function get_last_settings_update(): ?string {
        $result = get_option('thinkrank_settings_last_updated');
        return $result !== false ? $result : null;
    }

    /**
     * Get settings version
     *
     * @since 1.0.0
     *
     * @return string Settings version
     */
    private function get_settings_version(): string {
        return get_option('thinkrank_settings_version', '1.0.0');
    }

    /**
     * Get schema version
     *
     * @since 1.0.0
     *
     * @return string Schema version
     */
    private function get_schema_version(): string {
        return get_option('thinkrank_schema_version', '1.0.0');
    }

    /**
     * Get category last update timestamp
     *
     * @since 1.0.0
     *
     * @param string $category Category name
     * @return string|null Last update timestamp
     */
    private function get_category_last_update(string $category): ?string {
        $result = get_option("thinkrank_settings_{$category}_last_updated");
        return $result !== false ? $result : null;
    }

    /**
     * Update settings metadata
     *
     * @since 1.0.0
     */
    private function update_settings_metadata(): void {
        update_option('thinkrank_settings_last_updated', current_time('mysql'));

        // Increment version
        $current_version = $this->get_settings_version();
        $version_parts = explode('.', $current_version);
        $version_parts[2] = (int)$version_parts[2] + 1;
        $new_version = implode('.', $version_parts);

        update_option('thinkrank_settings_version', $new_version);
    }

    /**
     * Update category metadata
     *
     * @since 1.0.0
     *
     * @param string $category Category name
     */
    private function update_category_metadata(string $category): void {
        update_option("thinkrank_settings_{$category}_last_updated", current_time('mysql'));
    }

    /**
     * Format export data
     *
     * @since 1.0.0
     *
     * @param array  $export_data Export data
     * @param array  $metadata    Metadata
     * @param string $format      Export format
     * @return string Formatted export data
     */
    private function format_export_data(array $export_data, array $metadata, string $format): string {
        $full_export = [
            'metadata' => $metadata,
            'settings' => $export_data
        ];

        switch ($format) {
            case 'json':
                return wp_json_encode($full_export, JSON_PRETTY_PRINT);
            case 'yaml':
                // Would implement YAML formatting
                return wp_json_encode($full_export, JSON_PRETTY_PRINT);
            case 'xml':
                // Would implement XML formatting
                return wp_json_encode($full_export, JSON_PRETTY_PRINT);
            default:
                return wp_json_encode($full_export, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Parse import data
     *
     * @since 1.0.0
     *
     * @param string $import_data Import data
     * @param string $format      Import format
     * @return array|false Parsed data or false on failure
     */
    private function parse_import_data(string $import_data, string $format) {
        switch ($format) {
            case 'json':
                $decoded = json_decode($import_data, true);
                return $decoded['settings'] ?? $decoded;
            case 'yaml':
                // Would implement YAML parsing
                $decoded = json_decode($import_data, true);
                return $decoded['settings'] ?? $decoded;
            case 'xml':
                // Would implement XML parsing
                $decoded = json_decode($import_data, true);
                return $decoded['settings'] ?? $decoded;
            default:
                return false;
        }
    }

    /**
     * Save settings backup
     *
     * @since 1.0.0
     *
     * @param array $backup_data     Backup data
     * @param array $backup_metadata Backup metadata
     * @return string|false Backup ID or false on failure
     */
    private function save_settings_backup(array $backup_data, array $backup_metadata) {
        $backup_id = uniqid('backup_', true);

        $backup_record = [
            'backup_id' => $backup_id,
            'metadata' => $backup_metadata,
            'settings' => $backup_data
        ];

        // Store as a NON-autoloaded option — each backup is a full multi-category
        // snapshot and must not be loaded into memory on every front-end/admin
        // request.
        $saved = update_option("thinkrank_backup_{$backup_id}", $backup_record, false);

        if ($saved) {
            // Add to backup index (also non-autoloaded).
            $backup_index = get_option('thinkrank_backup_index', []);
            $backup_index[$backup_id] = $backup_metadata;

            // Cap the retained set so the backups can't accumulate unbounded.
            $backup_index = $this->prune_settings_backups($backup_index);

            update_option('thinkrank_backup_index', $backup_index, false);

            return $backup_id;
        }

        return false;
    }

    /**
     * Keep only the most recent settings backups, deleting the option rows for
     * any pruned from the index (oldest first).
     *
     * @param array $backup_index backup_id => metadata map.
     * @return array Pruned index.
     */
    private function prune_settings_backups(array $backup_index): array {
        $max_backups = 10;

        if (count($backup_index) <= $max_backups) {
            return $backup_index;
        }

        // Oldest first (missing timestamps sort earliest).
        uasort($backup_index, static function ($a, $b) {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        while (count($backup_index) > $max_backups) {
            $oldest_id = array_key_first($backup_index);
            unset($backup_index[$oldest_id]);
            delete_option("thinkrank_backup_{$oldest_id}");
        }

        return $backup_index;
    }

    /**
     * Load settings backup
     *
     * @since 1.0.0
     *
     * @param string $backup_id Backup ID
     * @return array|false Backup data or false on failure
     */
    private function load_settings_backup(string $backup_id) {
        return get_option("thinkrank_backup_{$backup_id}", false);
    }

    /**
     * Argument validation methods
     */

    /**
     * Get arguments for global settings endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_global_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Global settings to update across categories'
            ],
            'validate' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to validate settings before updating'
            ]
        ];
    }

    /**
     * Get arguments for category settings endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_category_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Category settings to update'
            ],
            'validate' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to validate settings before updating'
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
            ],
            'categories' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => array_keys($this->setting_categories)
                ],
                'description' => 'Categories to validate'
            ]
        ];
    }

    /**
     * Get arguments for export endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_export_args(): array {
        return [
            'categories' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => array_keys($this->setting_categories)
                ],
                'description' => 'Categories to export'
            ],
            'format' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['json', 'yaml', 'xml'],
                'default' => 'json',
                'description' => 'Export format'
            ],
            'include_metadata' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to include metadata in export'
            ]
        ];
    }

    /**
     * Get arguments for import endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_import_args(): array {
        return [
            'import_data' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Settings data to import'
            ],
            'format' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['json', 'yaml', 'xml'],
                'default' => 'json',
                'description' => 'Import format'
            ],
            'validate' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to validate before importing'
            ],
            'overwrite_existing' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
                'description' => 'Whether to overwrite existing settings'
            ]
        ];
    }

    /**
     * Get arguments for backup endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_backup_args(): array {
        return [
            'backup_name' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Name for the backup'
            ],
            'categories' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => array_keys($this->setting_categories)
                ],
                'description' => 'Categories to backup'
            ],
            'description' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Backup description'
            ]
        ];
    }

    /**
     * Get arguments for restore endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_restore_args(): array {
        return [
            'backup_id' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Backup ID to restore from'
            ],
            'categories' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => array_keys($this->setting_categories)
                ],
                'description' => 'Categories to restore'
            ],
            'create_restore_point' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to create restore point before restoring'
            ]
        ];
    }

    /**
     * Get arguments for reset endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_reset_args(): array {
        return [
            'categories' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => array_keys($this->setting_categories)
                ],
                'description' => 'Categories to reset'
            ],
            'create_backup' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to create backup before reset'
            ]
        ];
    }

    /**
     * Snapshot the given categories' current settings into a persisted backup.
     *
     * Backs the pre-reset backup and restore-point features with real storage
     * (via save_settings_backup) instead of a fabricated id, so operators have a
     * genuine rollback snapshot before a destructive reset/restore.
     *
     * @param array  $categories Categories to snapshot.
     * @param string $label      Human-readable label for the backup.
     * @return string Backup id, or '' if the snapshot could not be persisted.
     */
    private function create_settings_snapshot(array $categories, string $label): string {
        $backup_data = [];
        foreach ($categories as $category) {
            if (isset($this->setting_categories[$category])) {
                $backup_data[$category] = $this->settings_manager->get_settings($category);
            }
        }

        $backup_metadata = [
            'backup_name'       => $label . ' ' . gmdate('Y-m-d_H-i-s'),
            'description'       => $label,
            'created_at'        => current_time('mysql'),
            'created_by'        => get_current_user_id(),
            'categories'        => $categories,
            'settings_version'  => $this->get_settings_version(),
            'wordpress_version' => get_bloginfo('version'),
            'automatic'         => true,
        ];

        $backup_id = $this->save_settings_backup($backup_data, $backup_metadata);

        return $backup_id ?: '';
    }

    /**
     * Create a full-snapshot restore point before restoring a backup.
     *
     * @return string Backup id, or '' if it could not be persisted.
     */
    private function create_restore_point(): string {
        return $this->create_settings_snapshot(
            array_keys($this->setting_categories),
            'Automatic restore point'
        );
    }

    /**
     * Create a safety backup of the given categories before a reset.
     *
     * @param array $categories Categories about to be reset.
     * @return string Backup id, or '' if it could not be persisted.
     */
    private function create_pre_reset_backup(array $categories): string {
        return $this->create_settings_snapshot($categories, 'Automatic pre-reset backup');
    }
}
