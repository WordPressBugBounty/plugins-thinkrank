<?php

/**
 * Centralized Settings Manager Class
 *
 * Coordinates settings management across all ThinkRank components including
 * core plugin settings, SEO-specific settings, and cross-manager coordination.
 * Provides unified interface for the Settings Management API Endpoints with
 * proper validation, import/export, and backup/restore functionality.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

use ThinkRank\Core\Settings;
use ThinkRank\SEO\SEO_Settings_Manager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized Settings Manager Class
 *
 * Provides unified settings management interface that coordinates between
 * core plugin settings and SEO-specific settings. Handles cross-manager
 * validation, import/export, backup/restore, and conflict resolution.
 *
 * @since 1.0.0
 */
class Settings_Manager {

    /**
     * Core Settings instance
     *
     * @since 1.0.0
     * @var Settings
     */
    private Settings $core_settings;

    /**
     * SEO Settings Manager instance
     *
     * @since 1.0.0
     * @var SEO_Settings_Manager
     */
    private SEO_Settings_Manager $seo_settings;

    /**
     * Settings categories mapping
     *
     * @since 1.0.0
     * @var array
     */
    private array $settings_categories = [
        'core' => [
            'name' => 'Core Plugin Settings',
            'manager' => 'core',
            'keys' => [
                'ai_provider',
                'openai_api_key',
                'openai_model',
                'claude_api_key',
                'claude_model',
                'gemini_api_key',
                'gemini_model',
                'openrouter_api_key',
                'openrouter_model',
                'max_tokens',
                'temperature',
                'cache_duration',
                'max_requests_per_minute',
                'enable_logging',
                'debug_mode',
                'api_timeout',
                'retry_attempts',
                'rate_limit_enabled',
                'data_retention_days',
                'anonymize_logs',
                'share_usage_data',
                'keep_data_on_uninstall'
            ]
        ],
        'seo' => [
            'name' => 'SEO Settings',
            'manager' => 'seo',
            'keys' => [
                'auto_optimize',
                'seo_score_threshold',
                'enable_meta_generation',
                'enable_schema_markup'
            ]
        ],
        'ui' => [
            'name' => 'User Interface Settings',
            'manager' => 'core',
            'keys' => [
                'show_welcome_message',
                'dashboard_widgets',
                'editor_panel_position'
            ]
        ],
        'basic_integrations' => [
            'name' => 'Basic Integration Settings',
            'manager' => 'core',
            'keys' => [
                'google_analytics_id',
                'search_console_property'
            ]
        ],
        'social_media' => [
            'name' => 'Social Media & Open Graph',
            'manager' => 'seo',
            'keys' => [
                'enabled',
                'enable_open_graph',
                'og_site_name',
                'og_description',
                'og_type',
                'og_locale',
                'default_og_image',
                'og_image_width',
                'og_image_height',
                'enable_twitter_cards',
                'twitter_username',
                'twitter_card_type',
                'default_twitter_image',
                'facebook_app_id',
                'facebook_admins',
                'enable_linkedin',
                'enable_pinterest',
                'pinterest_site_verification',
                'enable_instagram',
                'instagram_verification',
                'enable_tiktok',
                'tiktok_verification',
                'enable_youtube',
                'youtube_channel_id',
                'enable_whatsapp',
                'whatsapp_business_id',
                'auto_generate_descriptions',
                'fallback_to_excerpt',
                'strip_html_tags',
                'max_description_length'
            ]
        ],
        'sitemap' => [
            'name' => 'XML Sitemap Management',
            'manager' => 'seo',
            'keys' => [
                'enabled',
                'include_posts',
                'include_pages',
                'include_categories',
                'include_tags',
                'auto_generate',
                'ping_search_engines',
                'last_generated',
                'exclude_posts',
                'exclude_terms',
                'exclude_password_protected',
                'exclude_private_posts',
                'enable_styling',
                'custom_url_pattern',
                'links_per_sitemap',
                'include_images',
                'include_featured_images',
                'use_sitemap_index'
            ]
        ],
        'site_identity' => [
            'name' => 'Site Identity & Global SEO',
            'manager' => 'seo',
            'keys' => []
        ],
        'content_analysis' => [
            'name' => 'AI Content Analysis',
            'manager' => 'seo',
            'keys' => []
        ],
        'content_optimization' => [
            'name' => 'Content Optimization',
            'manager' => 'seo',
            'keys' => []
        ],
        'performance_monitoring' => [
            'name' => 'Performance Monitoring',
            'manager' => 'seo',
            'keys' => []
        ],
        'schema_management' => [
            'name' => 'Schema Management',
            'manager' => 'seo',
            'keys' => []
        ],
        'integrations' => [
            'name' => 'Integrations',
            'manager' => 'core',
            'keys' => [
                // Google API Keys
                'google_analytics_api_key',
                'google_search_console_api_key',
                'google_pagespeed_api_key',
                // Google OAuth Tokens
                'google_access_token',
                'google_refresh_token',
                'google_token_expires_in',
                'google_token_created',
                'google_account_connected',
                // API Configuration
                'api_timeout',
                'enable_rate_limiting',
                'cache_duration',
                // Connection settings
                'auto_test_connections',
                'retry_failed_requests'
            ]
        ],
        'seo_analytics' => [
            'name' => 'SEO Analytics & Intelligence',
            'manager' => 'core',
            'keys' => [
                // Core settings
                'seo_analytics_enabled',
                'seo_analytics_setup_completed',

                // Google Analytics configuration
                'seo_analytics_google_analytics_property_id',
                'ga_analytics_account_id',
                'ga_analytics_data_stream_id',

                // GA4 Tracking Code Injection (Pro)
                'ga4_auto_inject',
                'ga4_measurement_id',

                // Search Console configuration
                'search_console_property',

                // AI features
                'seo_analytics_enable_ai_insights',
                'seo_analytics_enable_automated_alerts',
                'seo_analytics_enable_predictive_analysis',

                // Monitoring settings
                'seo_analytics_monitoring_frequency',
                'seo_analytics_alert_thresholds',
                'seo_analytics_report_schedule',

                // Data retention
                'seo_analytics_data_retention_days',
                'seo_analytics_cache_analytics_data'
            ]
        ],

    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->core_settings = Settings::instance();
        $this->seo_settings = new SEO_Settings_Manager();
    }

    /**
     * Get settings for a specific category
     *
     * @since 1.0.0
     *
     * @param string $category Settings category
     * @param string $context_type Optional. Context type for SEO settings
     * @param int|null $context_id Optional. Context ID for SEO settings
     * @return array Settings array
     */
    public function get_settings(string $category, string $context_type = 'site', ?int $context_id = null): array {
        if (!isset($this->settings_categories[$category])) {
            return [];
        }

        $category_config = $this->settings_categories[$category];

        if ($category_config['manager'] === 'core') {
            return $this->get_core_settings_by_category($category);
        } else {
            return $this->get_seo_settings_by_category($category, $context_type, $context_id);
        }
    }

    /**
     * Update settings for a specific category
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to update
     * @param string $category Settings category
     * @param string $context_type Optional. Context type for SEO settings
     * @param int|null $context_id Optional. Context ID for SEO settings
     * @return bool Success status
     */
    public function update_settings(array $settings, string $category, string $context_type = 'site', ?int $context_id = null): bool {
        if (!isset($this->settings_categories[$category])) {
            return false;
        }

        $category_config = $this->settings_categories[$category];

        if ($category_config['manager'] === 'core') {
            return $this->update_core_settings_by_category($settings, $category);
        } else {
            return $this->update_seo_settings_by_category($settings, $category, $context_type, $context_id);
        }
    }

    /**
     * Get all settings across categories
     *
     * @since 1.0.0
     *
     * @param array $categories Optional. Specific categories to retrieve
     * @return array All settings organized by category
     */
    public function get_all_settings(array $categories = []): array {
        $all_settings = [];
        $target_categories = empty($categories) ? array_keys($this->settings_categories) : $categories;

        foreach ($target_categories as $category) {
            if (isset($this->settings_categories[$category])) {
                $all_settings[$category] = $this->get_settings($category);
            }
        }

        return $all_settings;
    }

    /**
     * Update multiple categories of settings
     *
     * @since 1.0.0
     *
     * @param array $settings_by_category Settings organized by category
     * @return array Update results for each category
     */
    public function update_multiple_settings(array $settings_by_category): array {
        $results = [];

        foreach ($settings_by_category as $category => $settings) {
            try {
                $success = $this->update_settings($settings, $category);
                $results[$category] = [
                    'success' => $success,
                    'settings_count' => count($settings)
                ];
            } catch (\Exception $e) {
                $results[$category] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Validate settings across categories
     *
     * @since 1.0.0
     *
     * @param array $settings_by_category Settings organized by category
     * @return array Validation results for each category
     */
    public function validate_settings(array $settings_by_category): array {
        $validation_results = [];

        foreach ($settings_by_category as $category => $settings) {
            if (!isset($this->settings_categories[$category])) {
                $validation_results[$category] = [
                    'valid' => false,
                    'errors' => ['Invalid category'],
                    'warnings' => []
                ];
                continue;
            }

            $category_config = $this->settings_categories[$category];

            if ($category_config['manager'] === 'core') {
                $validation_results[$category] = $this->validate_core_settings($settings, $category);
            } else {
                $validation_results[$category] = $this->validate_seo_settings($settings, $category);
            }
        }

        return $validation_results;
    }

    /**
     * Get settings categories information
     *
     * @since 1.0.0
     *
     * @return array Categories information
     */
    public function get_categories(): array {
        $categories = [];

        foreach ($this->settings_categories as $key => $config) {
            $categories[$key] = [
                'name' => $config['name'],
                'manager' => $config['manager'],
                'key_count' => count($config['keys'])
            ];
        }

        return $categories;
    }

    /**
     * Export settings
     *
     * @since 1.0.0
     *
     * @param array $categories Optional. Categories to export
     * @return array Export data with metadata
     */
    public function export_settings(array $categories = []): array {
        $export_data = [
            'metadata' => [
                'export_timestamp' => current_time('mysql'),
                'plugin_version' => defined('THINKRANK_VERSION') ? THINKRANK_VERSION : '1.0.0',
                'wordpress_version' => get_bloginfo('version'),
                'site_url' => home_url(),
                'exported_categories' => empty($categories) ? array_keys($this->settings_categories) : $categories
            ],
            'settings' => $this->get_all_settings($categories)
        ];

        return $export_data;
    }

    /**
     * Import settings
     *
     * @since 1.0.0
     *
     * @param array $import_data Import data with settings
     * @param bool $validate_before_import Whether to validate before importing
     * @return array Import results
     */
    public function import_settings(array $import_data, bool $validate_before_import = true): array {
        $settings = $import_data['settings'] ?? $import_data;
        $import_results = [];

        // Validate if requested
        if ($validate_before_import) {
            $validation_results = $this->validate_settings($settings);

            foreach ($validation_results as $category => $validation) {
                if (!$validation['valid']) {
                    $import_results[$category] = [
                        'success' => false,
                        'error' => 'Validation failed',
                        'validation_errors' => $validation['errors']
                    ];
                    continue;
                }
            }
        }

        // Import settings for each category
        foreach ($settings as $category => $category_settings) {
            if (isset($import_results[$category])) {
                continue; // Skip if validation failed
            }

            try {
                $success = $this->update_settings($category_settings, $category);
                $import_results[$category] = [
                    'success' => $success,
                    'settings_count' => count($category_settings)
                ];
            } catch (\Exception $e) {
                $import_results[$category] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $import_results;
    }

    /**
     * Reset settings to defaults
     *
     * @since 1.0.0
     *
     * @param array $categories Optional. Categories to reset
     * @return array Reset results
     */
    public function reset_settings(array $categories = []): array {
        $target_categories = empty($categories) ? array_keys($this->settings_categories) : $categories;
        $reset_results = [];

        foreach ($target_categories as $category) {
            if (!isset($this->settings_categories[$category])) {
                continue;
            }

            try {
                $category_config = $this->settings_categories[$category];

                if ($category_config['manager'] === 'core') {
                    $success = $this->reset_core_settings($category);
                } else {
                    $success = $this->reset_seo_settings($category);
                }

                $reset_results[$category] = [
                    'success' => $success,
                    'reset_to_defaults' => true
                ];
            } catch (\Exception $e) {
                $reset_results[$category] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $reset_results;
    }

    /**
     * Get settings statistics
     *
     * @since 1.0.0
     *
     * @return array Settings statistics
     */
    public function get_settings_statistics(): array {
        $stats = [
            'total_categories' => count($this->settings_categories),
            'core_categories' => 0,
            'seo_categories' => 0,
            'total_settings' => 0,
            'last_updated' => get_option('thinkrank_settings_last_updated'),
            'version' => get_option('thinkrank_settings_version', '1.0.0')
        ];

        foreach ($this->settings_categories as $category => $config) {
            if ($config['manager'] === 'core') {
                $stats['core_categories']++;
            } else {
                $stats['seo_categories']++;
            }

            $category_settings = $this->get_settings($category);
            $stats['total_settings'] += count($category_settings);
        }

        return $stats;
    }

    /**
     * Helper methods for core settings management
     */

    /**
     * Get core settings by category
     *
     * @since 1.0.0
     *
     * @param string $category Category name
     * @return array Core settings for category
     */
    private function get_core_settings_by_category(string $category): array {
        $category_config = $this->settings_categories[$category];
        $settings = [];

        foreach ($category_config['keys'] as $key) {
            $settings[$key] = $this->core_settings->get($key);
        }

        return $settings;
    }

    /**
     * Update core settings by category
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to update
     * @param string $category Category name
     * @return bool Success status
     */
    private function update_core_settings_by_category(array $settings, string $category): bool {
        $category_config = $this->settings_categories[$category];
        $success_count = 0;
        $total_count = 0;

        foreach ($settings as $key => $value) {
            if (in_array($key, $category_config['keys'], true)) {
                $total_count++;

                if ($this->core_settings->set($key, $value)) {
                    $success_count++;
                }
            }
        }

        // Consider successful if at least 70% of settings were saved
        $success_rate = $total_count > 0 ? ($success_count / $total_count) : 0;
        $success = $success_rate >= 0.7;

        if ($success) {
            update_option('thinkrank_settings_last_updated', current_time('mysql'));
        }

        return $success;
    }

    /**
     * Get SEO settings by category
     *
     * @since 1.0.0
     *
     * @param string $category Category name
     * @param string $context_type Context type
     * @param int|null $context_id Context ID
     * @return array SEO settings for category
     */
    private function get_seo_settings_by_category(string $category, string $context_type, ?int $context_id): array {
        // For SEO categories, delegate to SEO Settings Manager
        return $this->seo_settings->get_settings_by_category($context_type, $context_id, $category);
    }

    /**
     * Update SEO settings by category
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to update
     * @param string $category Category name
     * @param string $context_type Context type
     * @param int|null $context_id Context ID
     * @return bool Success status
     */
    private function update_seo_settings_by_category(array $settings, string $category, string $context_type, ?int $context_id): bool {
        return $this->seo_settings->save_settings_by_category($context_type, $context_id, $settings, $category);
    }

    /**
     * Validate core settings
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to validate
     * @param string $category Category name
     * @return array Validation results
     */
    private function validate_core_settings(array $settings, string $category): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        $category_config = $this->settings_categories[$category];

        foreach ($settings as $key => $value) {
            if (!in_array($key, $category_config['keys'], true)) {
                $validation['warnings'][] = "Unknown setting key: {$key}";
                continue;
            }

            // Validate specific core settings
            $field_validation = $this->validate_core_setting($key, $value);
            if (!$field_validation['valid']) {
                $validation['valid'] = false;
                $validation['errors'] = array_merge($validation['errors'], $field_validation['errors']);
            }
        }

        return $validation;
    }

    /**
     * Validate SEO settings
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to validate
     * @param string $category Category name
     * @return array Validation results
     */
    private function validate_seo_settings(array $settings, string $category): array {
        // Delegate to SEO Settings Manager validation
        return $this->seo_settings->validate_settings($settings);
    }

    /**
     * Validate individual core setting
     *
     * @since 1.0.0
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return array Validation result
     */
    private function validate_core_setting(string $key, $value): array {
        $validation = ['valid' => true, 'errors' => []];

        switch ($key) {
            case 'openai_api_key':
            case 'claude_api_key':
            case 'openrouter_api_key':
                if (!empty($value) && !is_string($value)) {
                    $validation['valid'] = false;
                    $validation['errors'][] = "{$key} must be a string";
                }
                break;

            case 'max_tokens':
            case 'cache_duration':
            case 'max_requests_per_minute':
            case 'seo_score_threshold':
            case 'api_timeout':
            case 'retry_attempts':
            case 'data_retention_days':
                if (!is_numeric($value) || $value < 0) {
                    $validation['valid'] = false;
                    $validation['errors'][] = "{$key} must be a positive number";
                }
                break;

            case 'temperature':
                if (!is_numeric($value) || $value < 0 || $value > 2) {
                    $validation['valid'] = false;
                    $validation['errors'][] = "temperature must be between 0 and 2";
                }
                break;

            case 'ai_provider':
                if (!in_array($value, ['openai', 'claude', 'gemini', 'openrouter'], true)) {
                    $validation['valid'] = false;
                    $validation['errors'][] = "ai_provider must be 'openai', 'claude', 'gemini', or 'openrouter'";
                }
                break;

            case 'dashboard_widgets':
                if (!is_array($value)) {
                    $validation['valid'] = false;
                    $validation['errors'][] = "dashboard_widgets must be an array";
                }
                break;
        }

        return $validation;
    }

    /**
     * Reset core settings for category
     *
     * @since 1.0.0
     *
     * @param string $category Category name
     * @return bool Success status
     */
    private function reset_core_settings(string $category): bool {
        $category_config = $this->settings_categories[$category];
        $success = true;

        foreach ($category_config['keys'] as $key) {
            if (!$this->core_settings->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Reset SEO settings for category
     *
     * @since 1.0.0
     *
     * @param string $category Category name
     * @return bool Success status
     */
    private function reset_seo_settings(string $category): bool {
        try {
            // Map the settings category to the SEO context type
            return $this->seo_settings->reset_to_defaults('site');
        } catch (\Exception $e) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
                error_log('ThinkRank: Failed to reset SEO settings for category "' . $category . '": ' . $e->getMessage());
            }
            return false;
        }
    }
}
