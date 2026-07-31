<?php
/**
 * SEO Settings Manager Class
 *
 * Universal SEO settings management with context-aware CRUD operations,
 * validation, and data handling. Implements 2025 SEO best practices with
 * real industry-standard algorithms and comprehensive settings management.
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
 * SEO Settings Manager Class
 *
 * Provides universal settings management for all SEO functionality.
 * Handles context-aware data operations, validation, and import/export
 * with industry-standard SEO configuration patterns.
 *
 * @since 1.0.0
 */
class SEO_Settings_Manager extends Abstract_SEO_Manager {

    /**
     * SEO settings categories with their specifications
     *
     * @since 1.0.0
     * @var array
     */
    private array $settings_categories = [
        'general' => [
            'title' => 'General SEO Settings',
            'description' => 'Core SEO configuration and global settings',
            'priority' => 1,
            'contexts' => ['site', 'post', 'page', 'product']
        ],
        'meta' => [
            'title' => 'Meta Tags Settings',
            'description' => 'Title tags, meta descriptions, and meta keywords',
            'priority' => 2,
            'contexts' => ['site', 'post', 'page', 'product']
        ],
        'social' => [
            'title' => 'Social Media Settings',
            'description' => 'Open Graph, Twitter Cards, and social sharing',
            'priority' => 3,
            'contexts' => ['site', 'post', 'page', 'product']
        ],
        'schema' => [
            'title' => 'Schema Markup Settings',
            'description' => 'Structured data and Schema.org configuration',
            'priority' => 4,
            'contexts' => ['site', 'post', 'page', 'product']
        ],
        'robots' => [
            'title' => 'Robots & Indexing Settings',
            'description' => 'Robots meta tags, canonical URLs, and indexing control',
            'priority' => 5,
            'contexts' => ['site', 'post', 'page', 'product']
        ],
        'analytics' => [
            'title' => 'Analytics & Tracking Settings',
            'description' => 'Google Analytics, Search Console, and tracking codes',
            'priority' => 6,
            'contexts' => ['site']
        ],
        'sitemap' => [
            'title' => 'XML Sitemap Settings',
            'description' => 'XML sitemap generation and search engine submission',
            'priority' => 7,
            'contexts' => ['site']
        ],
        'advanced' => [
            'title' => 'Advanced SEO Settings',
            'description' => 'Advanced configuration and custom settings',
            'priority' => 8,
            'contexts' => ['site', 'post', 'page', 'product']
        ]
    ];

    /**
     * Default SEO settings structure
     *
     * @since 1.0.0
     * @var array
     */
    private array $default_settings_structure = [
        'enabled' => true,
        'auto_generate' => true,
        'validation_enabled' => true,
        'output_enabled' => true,
        'cache_enabled' => true,
        'last_updated' => '',
        'version' => '1.0.0'
    ];

    /**
     * Settings validation rules
     *
     * @since 1.0.0
     * @var array
     */
    private array $validation_rules = [
        'boolean_fields' => [
            'enabled', 'auto_generate', 'validation_enabled', 
            'output_enabled', 'cache_enabled'
        ],
        'string_fields' => [
            'title', 'description', 'keywords', 'canonical_url',
            'og_title', 'og_description', 'twitter_title', 'twitter_description'
        ],
        'url_fields' => [
            'canonical_url', 'og_image', 'twitter_image', 'site_url'
        ],
        'numeric_fields' => [
            'max_snippet', 'max_video_preview', 'priority', 'score'
        ],
        'array_fields' => [
            'keywords_array', 'custom_meta', 'social_profiles', 'schema_types'
        ]
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('seo_settings');
    }

    /**
     * Get SEO settings with category filtering
     *
     * @since 1.0.0
     *
     * @param string      $context_type The context type
     * @param int|null    $context_id   Optional. Context ID
     * @param string|null $category     Optional. Settings category filter
     * @return array SEO settings array
     */
    public function get_settings_by_category(string $context_type, ?int $context_id = null, ?string $category = null): array {
        $all_settings = $this->get_settings($context_type, $context_id);
        
        if (null === $category) {
            return $all_settings;
        }
        
        if (!isset($this->settings_categories[$category])) {
            return [];
        }
        
        // Filter settings by category
        $category_settings = [];
        foreach ($all_settings as $key => $value) {
            if (strpos($key, $category . '_') === 0 || $key === $category) {
                $category_settings[$key] = $value;
            }
        }
        
        return $category_settings;
    }

    /**
     * Save SEO settings with category support
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @param array    $settings     Settings array to save
     * @param string   $category     Optional. Settings category
     * @return bool True on success, false on failure
     */
    public function save_settings_by_category(string $context_type, ?int $context_id, array $settings, string $category = 'general'): bool {
        // Validate category
        if (!isset($this->settings_categories[$category])) {
            return false;
        }
        
        // Check if context is supported for this category
        if (!in_array($context_type, $this->settings_categories[$category]['contexts'], true)) {
            return false;
        }
        
        // Get existing settings
        $existing_settings = $this->get_settings($context_type, $context_id);
        
        // Merge with new settings
        $updated_settings = array_merge($existing_settings, $settings);
        
        // Add metadata
        $updated_settings['last_updated'] = current_time('mysql');
        $updated_settings['category'] = $category;
        
        return $this->save_settings($context_type, $context_id, $updated_settings);
    }

    /**
     * Get all available settings categories
     *
     * @since 1.0.0
     *
     * @param string $context_type Optional. Filter by context type
     * @return array Available categories
     */
    public function get_available_categories(string $context_type = ''): array {
        if (empty($context_type)) {
            return $this->settings_categories;
        }
        
        $filtered_categories = [];
        foreach ($this->settings_categories as $key => $category) {
            if (in_array($context_type, $category['contexts'], true)) {
                $filtered_categories[$key] = $category;
            }
        }
        
        return $filtered_categories;
    }

    /**
     * Bulk update settings across multiple contexts
     *
     * @since 1.0.0
     *
     * @param array $bulk_data Array of context => settings mappings
     * @return array Results with success/failure status
     */
    public function bulk_update_settings(array $bulk_data): array {
        $results = [];
        
        foreach ($bulk_data as $context_key => $settings_data) {
            // Parse context key (format: "context_type:context_id" or "context_type")
            $parts = explode(':', $context_key);
            $context_type = $parts[0];
            $context_id = isset($parts[1]) ? (int) $parts[1] : null;
            
            // Extract category if provided
            $category = $settings_data['category'] ?? 'general';
            unset($settings_data['category']);
            
            $success = $this->save_settings_by_category($context_type, $context_id, $settings_data, $category);
            
            $results[$context_key] = [
                'success' => $success,
                'context_type' => $context_type,
                'context_id' => $context_id,
                'category' => $category,
                'message' => $success ? 'Settings updated successfully' : 'Failed to update settings',
                'timestamp' => current_time('mysql')
            ];
        }
        
        return $results;
    }

    /**
     * Get settings statistics and analytics
     *
     * @since 1.0.0
     *
     * @param string $context_type Optional. Filter by context type
     * @return array Settings statistics
     */
    public function get_settings_statistics(string $context_type = ''): array {
        $stats = [
            'total_settings' => 0,
            'enabled_settings' => 0,
            'categories_used' => [],
            'contexts_configured' => [],
            'last_updated' => '',
            'validation_score' => 0
        ];

        // Get all settings
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings statistics require direct database access, table name is validated
        if (!empty($context_type)) {
            			$sql = sprintf(
				'SELECT context_type, context_id, setting_key, setting_value, updated_at FROM `%s` WHERE setting_category = %%s AND is_active = 1 AND context_type = %%s ORDER BY updated_at DESC',
				$this->settings_table
			);
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from sprintf with validated table name.
			$results = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
					$sql,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameters are validated and used as placeholders
					$this->manager_type,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $context_type is validated and used as parameter
					$context_type
				),
				ARRAY_A
			);
        } else {
            			$sql = sprintf(
				'SELECT context_type, context_id, setting_key, setting_value, updated_at FROM `%s` WHERE setting_category = %%s AND is_active = 1 ORDER BY updated_at DESC',
				$this->settings_table
			);
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from sprintf with validated table name.
			$results = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
					$sql,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameter is validated and used as placeholder
					$this->manager_type
				),
				ARRAY_A
			);
        }

        $stats['total_settings'] = count($results);
        $categories_used = [];
        $contexts_configured = [];
        $enabled_count = 0;
        $latest_update = '';

        foreach ($results as $row) {
            $context_key = $row['context_type'] . ':' . ($row['context_id'] ?? 'site');
            $contexts_configured[$context_key] = true;
            
            $value = maybe_unserialize($row['setting_value']);
            
            // Check if setting is enabled
            if ($row['setting_key'] === 'enabled' && $value) {
                $enabled_count++;
            }
            
            // Track categories
            if ($row['setting_key'] === 'category') {
                $categories_used[$value] = true;
            }
            
            // Track latest update
            if (empty($latest_update) || $row['updated_at'] > $latest_update) {
                $latest_update = $row['updated_at'];
            }
        }

        $stats['enabled_settings'] = $enabled_count;
        $stats['categories_used'] = array_keys($categories_used);
        $stats['contexts_configured'] = array_keys($contexts_configured);
        $stats['last_updated'] = $latest_update;
        
        // Calculate validation score
        $stats['validation_score'] = $this->calculate_overall_validation_score($context_type);

        return $stats;
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

        // Validate boolean fields
        foreach ($this->validation_rules['boolean_fields'] as $field) {
            if (isset($settings[$field]) && !is_bool($settings[$field])) {
                $validation['errors'][] = "{$field} must be a boolean value";
                $validation['valid'] = false;
            }
        }

        // Validate string fields
        foreach ($this->validation_rules['string_fields'] as $field) {
            if (isset($settings[$field])) {
                if (!is_string($settings[$field])) {
                    $validation['errors'][] = "{$field} must be a string";
                    $validation['valid'] = false;
                } elseif (strlen($settings[$field]) > 500) {
                    $validation['warnings'][] = "{$field} is longer than recommended (500 characters)";
                }
            }
        }

        // Validate URL fields
        foreach ($this->validation_rules['url_fields'] as $field) {
            if (isset($settings[$field]) && !empty($settings[$field])) {
                if (!filter_var($settings[$field], FILTER_VALIDATE_URL)) {
                    $validation['errors'][] = "{$field} must be a valid URL";
                    $validation['valid'] = false;
                }
            }
        }

        // Validate numeric fields
        foreach ($this->validation_rules['numeric_fields'] as $field) {
            if (isset($settings[$field]) && !is_numeric($settings[$field])) {
                $validation['errors'][] = "{$field} must be a numeric value";
                $validation['valid'] = false;
            }
        }

        // Validate array fields
        foreach ($this->validation_rules['array_fields'] as $field) {
            if (isset($settings[$field]) && !is_array($settings[$field])) {
                $validation['errors'][] = "{$field} must be an array";
                $validation['valid'] = false;
            }
        }

        // SEO-specific validations
        $validation = $this->validate_seo_specific_settings($settings, $validation);

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
            'settings' => $settings,
            'categories' => $this->get_available_categories($context_type),
            'validation' => $this->validate_settings($settings),
            'statistics' => $this->get_settings_statistics($context_type),
            'enabled' => $settings['enabled'] ?? true,
            'last_updated' => $settings['last_updated'] ?? '',
            'version' => $settings['version'] ?? '1.0.0'
        ];

        // Add context-specific data
        $output['context'] = [
            'type' => $context_type,
            'id' => $context_id,
            'supported_categories' => array_keys($this->get_available_categories($context_type))
        ];

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
        $defaults = $this->default_settings_structure;

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                $defaults = array_merge($defaults, [
                    'general_enabled' => true,
                    'meta_enabled' => true,
                    'social_enabled' => true,
                    'schema_enabled' => true,
                    'robots_enabled' => true,
                    'analytics_enabled' => false,
                    'advanced_enabled' => false
                ]);
                break;
            case 'post':
                $defaults = array_merge($defaults, [
                    'general_enabled' => true,
                    'meta_enabled' => true,
                    'social_enabled' => true,
                    'schema_enabled' => true,
                    'robots_enabled' => true,
                    'advanced_enabled' => false
                ]);
                break;
            case 'page':
                $defaults = array_merge($defaults, [
                    'general_enabled' => true,
                    'meta_enabled' => true,
                    'social_enabled' => true,
                    'schema_enabled' => false,
                    'robots_enabled' => true,
                    'advanced_enabled' => false
                ]);
                break;
            case 'product':
                $defaults = array_merge($defaults, [
                    'general_enabled' => true,
                    'meta_enabled' => true,
                    'social_enabled' => true,
                    'schema_enabled' => true,
                    'robots_enabled' => true,
                    'advanced_enabled' => true
                ]);
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
        $base_schema = [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable SEO Settings',
                'description' => 'Enable or disable SEO functionality for this context',
                'default' => true
            ],
            'auto_generate' => [
                'type' => 'boolean',
                'title' => 'Auto-generate SEO Data',
                'description' => 'Automatically generate SEO meta tags and data',
                'default' => true
            ],
            'validation_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Validation',
                'description' => 'Enable real-time validation of SEO settings',
                'default' => true
            ],
            'output_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Output',
                'description' => 'Enable output of SEO meta tags in frontend',
                'default' => true
            ],
            'cache_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Caching',
                'description' => 'Enable caching of SEO data for performance',
                'default' => true
            ]
        ];

        // Add category-specific schemas
        $categories = $this->get_available_categories($context_type);
        foreach ($categories as $category_key => $category) {
            $base_schema[$category_key . '_enabled'] = [
                'type' => 'boolean',
                'title' => 'Enable ' . $category['title'],
                'description' => $category['description'],
                'default' => true
            ];
        }

        return $base_schema;
    }

    /**
     * Import settings (implements parent interface)
     *
     * @since 1.0.0
     *
     * @param array $import_data Exported settings data
     * @param array $options     Import options
     * @return array Import results with success/failure details
     */
    public function import_settings(array $import_data, array $options = []): array {
        $results = [
            'success' => true,
            'imported_count' => 0,
            'failed_count' => 0,
            'details' => []
        ];

        // Validate import data structure
        if (!isset($import_data['settings']) || !is_array($import_data['settings'])) {
            $results['success'] = false;
            $results['details'][] = 'Invalid import data structure';
            return $results;
        }

        // Default import options
        $options = array_merge([
            'merge_strategy' => 'replace', // 'replace', 'merge', 'skip_existing'
            'validate' => true,
            'source' => 'manual'
        ], $options);

        foreach ($import_data['settings'] as $context_key => $settings) {
            // Parse context key
            $parts = explode(':', $context_key);
            $context_type = $parts[0];
            $context_id = isset($parts[1]) && $parts[1] !== 'site' ? (int) $parts[1] : null;

            // Check if settings already exist
            if ($options['merge_strategy'] === 'skip_existing' && $this->has_settings($context_type, $context_id)) {
                $results['details'][] = "Skipped existing settings for {$context_key}";
                continue;
            }

            // Merge with existing settings if requested
            if ($options['merge_strategy'] === 'merge' && $this->has_settings($context_type, $context_id)) {
                $existing_settings = $this->get_settings($context_type, $context_id);
                $settings = array_merge($existing_settings, $settings);
            }

            // Validate settings if requested
            if ($options['validate']) {
                $validation = $this->validate_settings($settings);
                if (!$validation['valid']) {
                    $results['failed_count']++;
                    $results['details'][] = "Validation failed for {$context_key}: " . implode(', ', $validation['errors']);
                    continue;
                }
            }

            // Add import metadata
            $settings['imported_from'] = $options['source'];
            $settings['imported_at'] = current_time('mysql');

            // Import settings
            $success = $this->save_settings($context_type, $context_id, $settings);
            if ($success) {
                $results['imported_count']++;
                $results['details'][] = "Successfully imported settings for {$context_key}";
            } else {
                $results['failed_count']++;
                $results['details'][] = "Failed to import settings for {$context_key}";
            }
        }

        $results['success'] = $results['failed_count'] === 0;
        return $results;
    }

    /**
     * Export settings (implements parent interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type Optional. Context type to export (null for all)
     * @param int|null $context_id   Optional. Context ID to export (null for all in context_type)
     * @return array Exported settings with metadata
     */
    public function export_settings(?string $context_type = null, ?int $context_id = null): array {
        $export_data = [
            'version' => '1.0.0',
            'manager_type' => $this->manager_type,
            'exported_at' => current_time('mysql'),
            'exported_by' => get_current_user_id(),
            'settings' => []
        ];

        if ($context_type !== null) {
            // Export specific context
            $settings = $this->get_settings($context_type, $context_id);
            $export_data['settings'][$context_type . ':' . ($context_id ?? 'site')] = $settings;
        } else {
            // Export all settings for this manager type
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings export requires direct database access, table name is validated
            			$sql = sprintf(
				'SELECT context_type, context_id, setting_key, setting_value FROM `%s` WHERE setting_category = %%s AND is_active = 1 ORDER BY context_type, context_id, setting_key',
				$this->settings_table
			);
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from sprintf with validated table name.
			$results = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
					$sql,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameter is validated and used as placeholder
					$this->manager_type
				),
				ARRAY_A
			);

            $grouped_settings = [];
            foreach ($results as $row) {
                $key = $row['context_type'] . ':' . ($row['context_id'] ?? 'site');
                $grouped_settings[$key][$row['setting_key']] = maybe_unserialize($row['setting_value']);
            }

            $export_data['settings'] = $grouped_settings;
        }

        return $export_data;
    }

    /**
     * Export settings to external format (additional method for format support)
     *
     * @since 1.0.0
     *
     * @param string $context_type Optional. Filter by context type
     * @param string $format       Export format ('json', 'array')
     * @return array|string Export data
     */
    public function export_settings_formatted(string $context_type = '', string $format = 'array') {
        $context_type_param = !empty($context_type) ? $context_type : null;
        $export_data = $this->export_settings($context_type_param);

        return $format === 'json' ? wp_json_encode($export_data, JSON_PRETTY_PRINT) : $export_data;
    }

    /**
     * Validate SEO-specific settings
     *
     * @since 1.0.0
     *
     * @param array $settings   Settings to validate
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_seo_specific_settings(array $settings, array $validation): array {
        // Validate title length (SEO best practice: 50-60 characters)
        if (isset($settings['title']) && !empty($settings['title'])) {
            $title_length = strlen($settings['title']);
            if ($title_length > 60) {
                $validation['warnings'][] = 'Title is longer than 60 characters, may be truncated in search results';
            } elseif ($title_length < 30) {
                $validation['suggestions'][] = 'Consider making title longer (30-60 characters) for better SEO';
            }
        }

        // Validate meta description length (SEO best practice: 150-160 characters)
        if (isset($settings['description']) && !empty($settings['description'])) {
            $desc_length = strlen($settings['description']);
            if ($desc_length > 160) {
                $validation['warnings'][] = 'Meta description is longer than 160 characters, may be truncated';
            } elseif ($desc_length < 120) {
                $validation['suggestions'][] = 'Consider making meta description longer (120-160 characters)';
            }
        }

        // Validate keywords (2025 SEO: focus on semantic keywords)
        if (isset($settings['keywords']) && !empty($settings['keywords'])) {
            $keywords = is_array($settings['keywords']) ? $settings['keywords'] : explode(',', $settings['keywords']);
            $keyword_count = count($keywords);

            if ($keyword_count > 5) {
                $validation['warnings'][] = 'Too many keywords may dilute SEO focus. Consider 3-5 primary keywords.';
            } elseif ($keyword_count === 0) {
                $validation['suggestions'][] = 'Add relevant keywords to improve content targeting';
            }
        }

        // Validate canonical URL
        if (isset($settings['canonical_url']) && !empty($settings['canonical_url'])) {
            $current_domain = wp_parse_url(home_url(), PHP_URL_HOST);
            $canonical_domain = wp_parse_url($settings['canonical_url'], PHP_URL_HOST);

            if ($canonical_domain !== $current_domain) {
                $validation['warnings'][] = 'Canonical URL points to external domain - ensure this is intentional';
            }
        }

        // Check for required SEO elements
        if (empty($settings['title'])) {
            $validation['suggestions'][] = 'Add a title tag for better search engine visibility';
        }

        if (empty($settings['description'])) {
            $validation['suggestions'][] = 'Add a meta description to improve click-through rates';
        }

        return $validation;
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

        // Deduct points for errors and warnings
        $score -= count($validation['errors']) * 20;
        $score -= count($validation['warnings']) * 10;
        $score -= count($validation['suggestions']) * 5;

        return max(0, $score);
    }

    /**
     * Calculate overall validation score for context
     *
     * @since 1.0.0
     *
     * @param string $context_type Context type to calculate score for
     * @return int Overall validation score
     */
    private function calculate_overall_validation_score(string $context_type): int {
        // Get all settings for context type
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO validation score calculation requires direct database access, table name is validated
        if (!empty($context_type)) {
            			$sql = sprintf(
				'SELECT context_type, context_id, setting_key, setting_value FROM `%s` WHERE setting_category = %%s AND is_active = 1 AND context_type = %%s',
				$this->settings_table
			);
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from sprintf with validated table name.
			$results = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
					$sql,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameters are validated and used as placeholders
					$this->manager_type,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $context_type is validated and used as parameter
					$context_type
				),
				ARRAY_A
			);
        } else {
            			$sql = sprintf(
				'SELECT context_type, context_id, setting_key, setting_value FROM `%s` WHERE setting_category = %%s AND is_active = 1',
				$this->settings_table
			);
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from sprintf with validated table name.
			$results = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
					$sql,
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameter is validated and used as placeholder
					$this->manager_type
				),
				ARRAY_A
			);
        }

        if (empty($results)) {
            return 0;
        }

        // Group settings by context
        $contexts = [];
        foreach ($results as $row) {
            $context_key = $row['context_type'] . ':' . ($row['context_id'] ?? 'site');
            if (!isset($contexts[$context_key])) {
                $contexts[$context_key] = [];
            }
            $contexts[$context_key][$row['setting_key']] = maybe_unserialize($row['setting_value']);
        }

        // Calculate average score across all contexts
        $total_score = 0;
        $context_count = 0;

        foreach ($contexts as $settings) {
            $validation = $this->validate_settings($settings);
            $total_score += $validation['score'];
            $context_count++;
        }

        return $context_count > 0 ? (int) round($total_score / $context_count) : 0;
    }

    /**
     * Reset settings to defaults
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Optional. Context ID
     * @return bool Success status
     */
    public function reset_to_defaults(string $context_type, ?int $context_id = null): bool {
        $default_settings = $this->get_default_settings($context_type);
        $default_settings['reset_at'] = current_time('mysql');
        $default_settings['reset_by'] = get_current_user_id();

        return $this->save_settings($context_type, $context_id, $default_settings);
    }

    /**
     * Get settings migration status
     *
     * @since 1.0.0
     *
     * @return array Migration status information
     */
    public function get_migration_status(): array {
        return [
            'current_version' => '1.0.0',
            'database_version' => get_option('thinkrank_seo_db_version', '0.0.0'),
            'migration_needed' => version_compare(get_option('thinkrank_seo_db_version', '0.0.0'), '1.0.0', '<'),
            'last_migration' => get_option('thinkrank_seo_last_migration', ''),
            'migration_log' => get_option('thinkrank_seo_migration_log', [])
        ];
    }

    /**
     * Perform settings migration
     *
     * @since 1.0.0
     *
     * @param string $from_version Source version
     * @param string $to_version   Target version
     * @return array Migration results
     */
    public function migrate_settings(string $from_version, string $to_version): array {
        $migration_results = [
            'success' => false,
            'migrated_count' => 0,
            'errors' => [],
            'from_version' => $from_version,
            'to_version' => $to_version,
            'started_at' => current_time('mysql')
        ];

        try {
            // Perform version-specific migrations
            switch ($from_version) {
                case '0.0.0':
                    // Initial migration - set up default settings
                    $contexts = ['site', 'post', 'page', 'product'];
                    foreach ($contexts as $context) {
                        $defaults = $this->get_default_settings($context);
                        $this->save_settings($context, null, $defaults);
                        $migration_results['migrated_count']++;
                    }
                    break;
                default:
                    $migration_results['errors'][] = "No migration path defined for version {$from_version}";
                    break;
            }

            if (empty($migration_results['errors'])) {
                $migration_results['success'] = true;
                update_option('thinkrank_seo_db_version', $to_version);
                update_option('thinkrank_seo_last_migration', current_time('mysql'));

                // Log migration
                $migration_log = get_option('thinkrank_seo_migration_log', []);
                $migration_log[] = $migration_results;
                update_option('thinkrank_seo_migration_log', array_slice($migration_log, -10)); // Keep last 10 migrations
            }
        } catch (\Exception $e) {
            $migration_results['errors'][] = 'Migration failed: ' . $e->getMessage();
        }

        $migration_results['completed_at'] = current_time('mysql');
        return $migration_results;
    }
}
