<?php

/**
 * Database Schema Manager Class
 *
 * Comprehensive database schema implementation for ThinkRank SEO plugin.
 * Creates and manages all 11 ThinkRank tables with proper indexes, constraints,
 * and WordPress-compliant database operations following 2025 best practices.
 *
 * Tables managed:
 * - SEO Tables (7): Settings, Analysis, Keywords, Schema, Social, Performance, Local
 * - AI/Core Tables (4): AI Cache, AI Usage, Content Briefs, SEO Scores
 *
 * @package ThinkRank
 * @subpackage Database
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Database;

/**
 * Database Schema Manager Class
 *
 * Handles creation, management, and optimization of all SEO database tables.
 * Implements WordPress database standards with proper indexing and constraints.
 *
 * @since 1.0.0
 */
class Database_Schema {

    /**
     * WordPress database instance
     *
     * @since 1.0.0
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Database version for schema tracking
     *
     * @since 1.0.0
     * @var string
     */
    private string $db_version = '1.2.0';

    /**
     * Database table definitions with specifications
     *
     * Consolidated table definitions for all ThinkRank tables (11 total):
     * - SEO Tables (7): Core SEO functionality with context-aware structure
     * - AI/Core Tables (4): AI caching, usage tracking, content briefs, and scoring
     *
     * @since 1.0.0
     * @var array
     */
    private array $table_definitions = [
        // === SEO TABLES (7) ===
        'seo_settings' => [
            'description' => 'Universal SEO settings storage with context-aware structure',
            'primary_key' => 'setting_id',
            'indexes' => ['context_type', 'context_id', 'setting_category', 'is_active'],
            'foreign_keys' => []
        ],
        'seo_analysis' => [
            'description' => 'SEO analysis results and scoring data',
            'primary_key' => 'analysis_id',
            'indexes' => ['context_type', 'context_id', 'analysis_type', 'created_at'],
            'composite_indexes' => [
                'context_analysis_date' => ['context_type', 'analysis_type', 'created_at'],
                'context_recent' => ['context_type', 'context_id', 'created_at']
            ],
            'foreign_keys' => []
        ],
        'seo_keywords' => [
            'description' => 'Keyword tracking and optimization data',
            'primary_key' => 'keyword_id',
            'indexes' => ['context_type', 'context_id', 'keyword_type', 'keyword_hash'],
            'foreign_keys' => []
        ],
        'seo_schema' => [
            'description' => 'Schema markup storage and validation',
            'primary_key' => 'schema_id',
            'indexes' => ['context_type', 'context_id', 'schema_type', 'is_active'],
            'foreign_keys' => []
        ],
        'seo_social' => [
            'description' => 'Social media meta and optimization data',
            'primary_key' => 'social_id',
            'indexes' => ['context_type', 'context_id', 'platform', 'is_active'],
            'foreign_keys' => []
        ],
        'seo_performance' => [
            'description' => 'Performance metrics and Core Web Vitals data',
            'primary_key' => 'performance_id',
            'indexes' => ['context_type', 'context_id', 'metric_type', 'measured_at'],
            'foreign_keys' => []
        ],
        'seo_local' => [
            'description' => 'Local SEO and business data storage',
            'primary_key' => 'local_id',
            'indexes' => ['context_type', 'context_id', 'business_type', 'is_active'],
            'foreign_keys' => []
        ],

        // === AI/CORE TABLES (4) ===
        'ai_cache' => [
            'description' => 'AI response caching for performance optimization',
            'primary_key' => 'id',
            'indexes' => ['cache_key', 'expires_at', 'created_at'],
            'composite_indexes' => [
                'cache_lookup' => ['cache_key', 'expires_at'],
                'cleanup_expired' => ['expires_at', 'created_at']
            ],
            'foreign_keys' => []
        ],
        'ai_usage' => [
            'description' => 'AI usage tracking and token consumption monitoring',
            'primary_key' => 'id',
            'indexes' => ['user_id', 'action', 'provider', 'created_at'],
            'composite_indexes' => [
                'user_analytics' => ['user_id', 'created_at', 'provider'],
                'provider_action' => ['provider', 'action', 'created_at'],
                'user_provider_date' => ['user_id', 'provider', 'created_at']
            ],
            'foreign_keys' => []
        ],
        'content_briefs' => [
            'description' => 'Generated content briefs storage and management',
            'primary_key' => 'id',
            'indexes' => ['user_id', 'content_type', 'created_at'],
            'composite_indexes' => [
                'user_content_date' => ['user_id', 'content_type', 'created_at'],
                'user_recent' => ['user_id', 'created_at']
            ],
            'foreign_keys' => []
        ],
        'seo_scores' => [
            'description' => 'SEO score calculations and historical tracking',
            'primary_key' => 'id',
            'indexes' => ['post_id', 'user_id', 'overall_score', 'grade', 'calculated_at', 'created_at'],
            'composite_indexes' => [
                'post_user_date' => ['post_id', 'user_id', 'created_at'],
                'user_score_date' => ['user_id', 'overall_score', 'created_at'],
                'post_latest' => ['post_id', 'calculated_at']
            ],
            'foreign_keys' => []
        ],
        'instant_indexing_logs' => [
            'description' => 'Log of IndexNow URL submissions',
            'primary_key' => 'id',
            'indexes' => ['status', 'response_code', 'created_at'],
            'foreign_keys' => []
        ],
        'email_report_logs' => [
            'description' => 'Audit + dedupe log for scheduled SEO email reports',
            'primary_key' => 'id',
            'indexes' => ['site_id', 'status', 'sent_at', 'period_start'],
            'composite_indexes' => [
                'dedupe_key' => ['site_id', 'period_start', 'recipient_hash'],
                'site_recent' => ['site_id', 'sent_at']
            ],
            'foreign_keys' => []
        ]
    ];

    /**
     * WordPress database charset and collation
     *
     * @since 1.0.0
     * @var array
     */
    private array $db_config;

    /**
     * Table categories for better organization and maintenance
     *
     * @since 1.0.0
     * @var array
     */
    private array $table_categories = [
        'seo' => ['seo_settings', 'seo_analysis', 'seo_keywords', 'seo_schema', 'seo_social', 'seo_performance', 'seo_local', 'instant_indexing_logs'],
        'ai' => ['ai_cache', 'ai_usage'],
        'content' => ['content_briefs'],
        'scoring' => ['seo_scores'],
        'reporting' => ['email_report_logs']
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Set database configuration
        $this->db_config = [
            'charset' => $wpdb->charset ?: 'utf8mb4',
            'collate' => $wpdb->collate ?: 'utf8mb4_unicode_ci'
        ];
    }

    /**
     * Create all database tables
     *
     * @since 1.0.0
     *
     * @return array Creation results with success/failure status
     */
    public function create_tables(): array {
        $results = [
            'success' => true,
            'tables_created' => [],
            'tables_failed' => [],
            'errors' => [],
            'total_tables' => count($this->table_definitions)
        ];

        // Require WordPress upgrade functions
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        foreach ($this->table_definitions as $table_name => $definition) {
            try {
                $full_table_name = $this->get_table_name($table_name);
                $sql = $this->get_table_sql($table_name);

                // Create table using dbDelta for WordPress compatibility
                $result = dbDelta($sql);

                // Verify table creation
                if ($this->table_exists($full_table_name)) {
                    $results['tables_created'][] = $full_table_name;

                    // Create indexes
                    $this->create_table_indexes($table_name);

                    // Add constraints if needed
                    $this->add_table_constraints($table_name);
                } else {
                    $results['tables_failed'][] = $full_table_name;
                    $results['errors'][] = "Failed to create table: {$full_table_name}";
                    $results['success'] = false;
                }
            } catch (\Exception $e) {
                $results['tables_failed'][] = $this->get_table_name($table_name);
                $results['errors'][] = "Error creating {$table_name}: " . $e->getMessage();
                $results['success'] = false;
            }
        }

        // Update database version
        if ($results['success']) {
            update_option('thinkrank_seo_db_version', $this->db_version);
            update_option('thinkrank_seo_db_created', current_time('mysql'));
        }

        return $results;
    }

    /**
     * Drop all database tables
     *
     * @since 1.0.0
     *
     * @return array Deletion results
     */
    public function drop_tables(): array {
        $results = [
            'success' => true,
            'tables_dropped' => [],
            'tables_failed' => [],
            'errors' => []
        ];

        foreach (array_keys($this->table_definitions) as $table_name) {
            try {
                $full_table_name = $this->get_table_name($table_name);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin deactivation requires direct schema changes, DDL cannot be prepared, table name is validated
                $result = $this->wpdb->query("DROP TABLE IF EXISTS `{$full_table_name}`");

                if ($result !== false) {
                    $results['tables_dropped'][] = $full_table_name;
                } else {
                    $results['tables_failed'][] = $full_table_name;
                    $results['errors'][] = "Failed to drop table: {$full_table_name}";
                    $results['success'] = false;
                }
            } catch (\Exception $e) {
                $results['tables_failed'][] = $this->get_table_name($table_name);
                $results['errors'][] = "Error dropping {$table_name}: " . $e->getMessage();
                $results['success'] = false;
            }
        }

        // Clean up options
        if ($results['success']) {
            delete_option('thinkrank_seo_db_version');
            delete_option('thinkrank_seo_db_created');
        }

        return $results;
    }

    /**
     * Check if database schema needs updates
     *
     * @since 1.0.0
     *
     * @return bool True if update needed
     */
    public function needs_update(): bool {
        $current_version = get_option('thinkrank_seo_db_version', '0.0.0');
        return version_compare($current_version, $this->db_version, '<');
    }

    /**
     * The schema version this plugin build expects (the target of needs_update).
     *
     * @since 1.23.0
     *
     * @return string Expected schema version, e.g. "1.2.0".
     */
    public function get_schema_version(): string {
        return $this->db_version;
    }

    /**
     * Get database status and information
     *
     * @since 1.0.0
     *
     * @return array Database status information
     */
    public function get_database_status(): array {
        $status = [
            'version' => get_option('thinkrank_seo_db_version', 'Not installed'),
            'created_at' => get_option('thinkrank_seo_db_created', 'Unknown'),
            'tables' => [],
            'total_records' => 0,
            'database_size' => 0,
            'needs_update' => $this->needs_update()
        ];

        foreach (array_keys($this->table_definitions) as $table_name) {
            $full_table_name = $this->get_table_name($table_name);
            $table_info = $this->get_table_info($full_table_name);

            $status['tables'][$table_name] = $table_info;
            $status['total_records'] += $table_info['row_count'];
            $status['database_size'] += $table_info['data_size'];
        }

        return $status;
    }

    /**
     * Optimize all database tables
     *
     * @since 1.0.0
     *
     * @return array Optimization results
     */
    public function optimize_tables(): array {
        $results = [
            'success' => true,
            'tables_optimized' => [],
            'tables_failed' => [],
            'space_saved' => 0,
            'errors' => []
        ];

        foreach (array_keys($this->table_definitions) as $table_name) {
            try {
                $full_table_name = $this->get_table_name($table_name);

                // Get table size before optimization
                $size_before = $this->get_table_size($full_table_name);

                // Optimize table
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table optimization requires direct database access, DDL cannot be prepared, table name is validated
                $result = $this->wpdb->query("OPTIMIZE TABLE `{$full_table_name}`");

                if ($result !== false) {
                    $size_after = $this->get_table_size($full_table_name);
                    $space_saved = $size_before - $size_after;

                    $results['tables_optimized'][] = [
                        'table' => $full_table_name,
                        'space_saved' => $space_saved
                    ];
                    $results['space_saved'] += $space_saved;
                } else {
                    $results['tables_failed'][] = $full_table_name;
                    $results['errors'][] = "Failed to optimize table: {$full_table_name}";
                    $results['success'] = false;
                }
            } catch (\Exception $e) {
                $results['tables_failed'][] = $this->get_table_name($table_name);
                $results['errors'][] = "Error optimizing {$table_name}: " . $e->getMessage();
                $results['success'] = false;
            }
        }

        return $results;
    }

    /**
     * Get full table name with WordPress prefix
     *
     * @since 1.0.0
     *
     * @param string $table_name Base table name
     * @return string Full table name with prefix
     */
    private function get_table_name(string $table_name): string {
        return $this->wpdb->prefix . 'thinkrank_' . $table_name;
    }

    /**
     * Check if table exists
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @return bool True if table exists
     */
    private function table_exists(string $table_name): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema validation requires direct database access
        $result = $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );

        return $result === $table_name;
    }

    /**
     * Get SQL for creating a specific table
     *
     * @since 1.0.0
     *
     * @param string $table_name Table name
     * @return string SQL for table creation
     */
    private function get_table_sql(string $table_name): string {
        $full_table_name = $this->get_table_name($table_name);
        $charset_collate = "DEFAULT CHARACTER SET {$this->db_config['charset']} COLLATE {$this->db_config['collate']}";

        switch ($table_name) {
            // SEO Tables
            case 'seo_settings':
                return $this->get_seo_settings_table_sql($full_table_name, $charset_collate);
            case 'seo_analysis':
                return $this->get_seo_analysis_table_sql($full_table_name, $charset_collate);
            case 'seo_keywords':
                return $this->get_seo_keywords_table_sql($full_table_name, $charset_collate);
            case 'seo_schema':
                return $this->get_seo_schema_table_sql($full_table_name, $charset_collate);
            case 'seo_social':
                return $this->get_seo_social_table_sql($full_table_name, $charset_collate);
            case 'seo_performance':
                return $this->get_seo_performance_table_sql($full_table_name, $charset_collate);
            case 'seo_local':
                return $this->get_seo_local_table_sql($full_table_name, $charset_collate);

                // AI/Core Tables
            case 'ai_cache':
                return $this->get_ai_cache_table_sql($full_table_name, $charset_collate);
            case 'ai_usage':
                return $this->get_ai_usage_table_sql($full_table_name, $charset_collate);
            case 'content_briefs':
                return $this->get_content_briefs_table_sql($full_table_name, $charset_collate);
            case 'seo_scores':
                return $this->get_seo_scores_table_sql($full_table_name, $charset_collate);
            case 'instant_indexing_logs':
                return $this->get_instant_indexing_logs_table_sql($full_table_name, $charset_collate);
            case 'email_report_logs':
                return $this->get_email_report_logs_table_sql($full_table_name, $charset_collate);

            default:
                throw new \InvalidArgumentException('Unknown table: ' . esc_html($table_name));
        }
    }

    /**
     * Get SQL for SEO Settings table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_settings_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            setting_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            setting_category varchar(100) NOT NULL DEFAULT 'general',
            setting_key varchar(255) NOT NULL,
            setting_value longtext NULL,
            setting_type varchar(50) NOT NULL DEFAULT 'string',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            priority int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) unsigned NULL,
            updated_by bigint(20) unsigned NULL,
            PRIMARY KEY (setting_id),
            UNIQUE KEY unique_setting (context_type, context_id, setting_category, setting_key),
            KEY idx_context (context_type, context_id),
            KEY idx_category (setting_category),
            KEY idx_active (is_active),
            KEY idx_created (created_at),
            KEY idx_updated (updated_at),
            KEY idx_context_cat_active (context_type, context_id, setting_category, is_active)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Analysis table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_analysis_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            analysis_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            analysis_type varchar(100) NOT NULL,
            analysis_data longtext NULL,
            score int(11) NOT NULL DEFAULT 0,
            status varchar(50) NOT NULL DEFAULT 'pending',
            ai_confidence decimal(3,2) NULL,
            recommendations longtext NULL,
            validation_errors longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            analyzed_by bigint(20) unsigned NULL,
            PRIMARY KEY (analysis_id),
            KEY idx_context (context_type, context_id),
            KEY idx_type (analysis_type),
            KEY idx_status (status),
            KEY idx_score (score),
            KEY idx_created (created_at),
            KEY idx_confidence (ai_confidence)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Keywords table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_keywords_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            keyword_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            keyword_text varchar(500) NOT NULL,
            keyword_hash varchar(64) NOT NULL,
            keyword_type varchar(50) NOT NULL DEFAULT 'primary',
            search_volume int(11) NULL,
            competition_score decimal(3,2) NULL,
            difficulty_score decimal(3,2) NULL,
            density decimal(5,2) NULL,
            position int(11) NULL,
            ranking_url varchar(2048) NULL,
            is_tracking tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            tracked_by bigint(20) unsigned NULL,
            PRIMARY KEY (keyword_id),
            UNIQUE KEY unique_keyword (context_type, context_id, keyword_hash),
            KEY idx_context (context_type, context_id),
            KEY idx_type (keyword_type),
            KEY idx_hash (keyword_hash),
            KEY idx_tracking (is_tracking),
            KEY idx_active (is_active),
            KEY idx_position (position),
            KEY idx_created (created_at),
            FULLTEXT KEY ft_keyword (keyword_text)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Schema table (Optimized Version 2.0)
     *
     * @since 1.0.0
     * @updated 2.0.0 - Optimized structure with fewer columns and better indexes
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_schema_table_sql(string $table_name, string $charset_collate): string {
        // Check MySQL version for JSON column support with caching
        $schema_data_type = $this->get_mysql_json_support() ? 'JSON' : 'longtext';

        return "CREATE TABLE `{$table_name}` (
            schema_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            schema_type varchar(100) NOT NULL,
            schema_data {$schema_data_type} NOT NULL,
            validation_status varchar(50) NOT NULL DEFAULT 'pending',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (schema_id),
            KEY idx_context_active (context_type, context_id, is_active),
            KEY idx_type_active (schema_type, is_active),
            KEY idx_created (created_at),
            KEY idx_context_schema_active (context_type, schema_type, is_active, created_at DESC),
            KEY idx_validation_active (validation_status, is_active)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Social table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_social_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            social_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            platform varchar(50) NOT NULL,
            meta_type varchar(100) NOT NULL,
            meta_key varchar(255) NOT NULL,
            meta_value longtext NULL,
            image_url varchar(2048) NULL,
            image_width int(11) NULL,
            image_height int(11) NULL,
            is_optimized tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) unsigned NULL,
            PRIMARY KEY (social_id),
            UNIQUE KEY unique_social_meta (context_type, context_id, platform, meta_key),
            KEY idx_context (context_type, context_id),
            KEY idx_platform (platform),
            KEY idx_type (meta_type),
            KEY idx_optimized (is_optimized),
            KEY idx_active (is_active),
            KEY idx_created (created_at)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Performance table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_performance_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            performance_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            metric_type varchar(100) NOT NULL,
            metric_value decimal(10,4) NOT NULL,
            metric_unit varchar(50) NOT NULL DEFAULT 'score',
            threshold_good decimal(10,4) NULL,
            threshold_poor decimal(10,4) NULL,
            status varchar(50) NOT NULL DEFAULT 'unknown',
            device_type varchar(20) NOT NULL DEFAULT 'desktop',
            connection_type varchar(50) NULL,
            measured_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            measured_by varchar(100) NULL,
            PRIMARY KEY (performance_id),
            KEY idx_context (context_type, context_id),
            KEY idx_metric (metric_type),
            KEY idx_status (status),
            KEY idx_device (device_type),
            KEY idx_measured (measured_at),
            KEY idx_created (created_at),
            KEY idx_value (metric_value)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Local table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_local_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            local_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context_type varchar(50) NOT NULL DEFAULT 'site',
            context_id bigint(20) unsigned NULL,
            business_type varchar(100) NOT NULL DEFAULT 'LocalBusiness',
            business_name varchar(255) NOT NULL,
            business_address longtext NULL,
            business_phone varchar(50) NULL,
            business_email varchar(255) NULL,
            business_website varchar(2048) NULL,
            latitude decimal(10,8) NULL,
            longitude decimal(11,8) NULL,
            google_place_id varchar(255) NULL,
            google_my_business_url varchar(2048) NULL,
            business_hours longtext NULL,
            nap_consistency_score int(11) NOT NULL DEFAULT 0,
            local_seo_score int(11) NOT NULL DEFAULT 0,
            is_verified tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) unsigned NULL,
            PRIMARY KEY (local_id),
            UNIQUE KEY unique_business (context_type, context_id),
            KEY idx_context (context_type, context_id),
            KEY idx_type (business_type),
            KEY idx_location (latitude, longitude),
            KEY idx_verified (is_verified),
            KEY idx_active (is_active),
            KEY idx_nap_score (nap_consistency_score),
            KEY idx_local_score (local_seo_score),
            KEY idx_created (created_at)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for AI Cache table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_ai_cache_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            cache_data longtext NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at_idx (expires_at),
            KEY created_at_idx (created_at)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for AI Usage table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_ai_usage_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(100) NOT NULL,
            tokens_used int(11) NOT NULL DEFAULT 0,
            provider varchar(50) NOT NULL,
            post_id bigint(20) unsigned NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY action_idx (action),
            KEY created_at_idx (created_at),
            KEY provider_idx (provider)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for Content Briefs table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_content_briefs_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            target_keywords text NOT NULL,
            content_type varchar(50) NOT NULL DEFAULT 'blog_post',
            brief_data longtext NOT NULL,
            parsing_status varchar(50) NOT NULL DEFAULT 'success',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY created_at_idx (created_at),
            KEY content_type_idx (content_type),
            KEY parsing_status_idx (parsing_status)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for Instant Indexing Logs table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_instant_indexing_logs_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url varchar(2048) NOT NULL,
            status varchar(50) NOT NULL,
            response_code int(11) NULL,
            response_message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_response_code (response_code),
            KEY idx_created (created_at)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for SEO Scores table
     *
     * @since 1.0.0
     *
     * @param string $table_name    Full table name
     * @param string $charset_collate Charset and collation
     * @return string SQL for table creation
     */
    private function get_seo_scores_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            overall_score int(11) NOT NULL,
            score_breakdown longtext NOT NULL,
            suggestions longtext NOT NULL,
            grade varchar(2) NOT NULL,
            readability_score varchar(100) DEFAULT NULL,
            content_quality varchar(100) DEFAULT NULL,
            algorithm_version varchar(20) NOT NULL DEFAULT '2024.1',
            calculated_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id_idx (post_id),
            KEY user_id_idx (user_id),
            KEY created_at_idx (created_at),
            KEY overall_score_idx (overall_score)
        ) {$charset_collate};";
    }

    /**
     * Get SQL for Email Report Logs table.
     *
     * Audit + dedupe log for scheduled SEO email reports. The
     * `unique_send` constraint on (site_id, period_start, recipient_hash)
     * is what prevents a given site from being sent the same period twice
     * to the same recipient — required by the PRD.
     *
     * `recipient_hash` is a sha256 of the lowercased, sorted recipient list
     * (so [a@x, b@x] and [b@x, a@x] dedupe to the same row).
     *
     * @since 1.9.0
     *
     * @param string $table_name      Full table name.
     * @param string $charset_collate Charset and collation.
     * @return string SQL for table creation.
     */
    private function get_email_report_logs_table_sql(string $table_name, string $charset_collate): string {
        return "CREATE TABLE `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL DEFAULT 0,
            period_start datetime NOT NULL,
            period_end datetime NOT NULL,
            recipient_hash char(64) NOT NULL,
            recipient_count smallint(5) unsigned NOT NULL DEFAULT 1,
            frequency_days smallint(5) unsigned NOT NULL DEFAULT 30,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text NULL,
            sent_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_send (site_id, period_start, recipient_hash),
            KEY idx_site (site_id),
            KEY idx_status (status),
            KEY idx_sent (sent_at),
            KEY idx_period (period_start)
        ) {$charset_collate};";
    }

    /**
     * Create indexes for a specific table
     *
     * @since 1.0.0
     *
     * @param string $table_name Table name
     * @return bool Success status
     */
    private function create_table_indexes(string $table_name): bool {
        $full_table_name = $this->get_table_name($table_name);
        $definition = $this->table_definitions[$table_name] ?? [];

        $success = true;

        // Create single column indexes
        if (!empty($definition['indexes'])) {
            foreach ($definition['indexes'] as $index_name) {
                try {
                    // Check if index already exists
                    if ($this->index_exists($full_table_name, $index_name)) {
                        continue;
                    }

                    $index_sql = $this->get_index_sql($full_table_name, $index_name);
                    if ($index_sql) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Index creation requires direct schema changes, DDL cannot be prepared
                        $result = $this->wpdb->query($index_sql);
                        if (false === $result) {
                            $success = false;
                        }
                    }
                } catch (\Exception $e) {
                    $success = false;
                }
            }
        }

        // Create composite indexes for performance optimization
        if (!empty($definition['composite_indexes'])) {
            foreach ($definition['composite_indexes'] as $index_name => $columns) {
                try {
                    // Check if index already exists
                    if ($this->index_exists($full_table_name, "idx_{$index_name}")) {
                        continue;
                    }

                    $index_sql = $this->get_composite_index_sql($full_table_name, $index_name, $columns);
                    if ($index_sql) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Composite index creation requires direct schema changes, DDL cannot be prepared
                        $result = $this->wpdb->query($index_sql);
                        if (false === $result) {
                            $success = false;
                        }
                    }
                } catch (\Exception $e) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Add constraints for a specific table
     *
     * @since 1.0.0
     *
     * @param string $table_name Table name
     * @return bool Success status
     */
    private function add_table_constraints(string $table_name): bool {
        $full_table_name = $this->get_table_name($table_name);
        $definition = $this->table_definitions[$table_name] ?? [];

        if (empty($definition['foreign_keys'])) {
            return true;
        }

        $success = true;
        foreach ($definition['foreign_keys'] as $constraint) {
            try {
                $constraint_sql = $this->get_constraint_sql($full_table_name, $constraint);
                if ($constraint_sql) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Constraint creation requires direct schema changes, DDL cannot be prepared
                    $result = $this->wpdb->query($constraint_sql);
                    if (false === $result) {
                        $success = false;
                    }
                }
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get index SQL for a table
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @param string $index_name Index name
     * @return string Index SQL
     */
    private function get_index_sql(string $table_name, string $index_name): string {
        // Most indexes are already created in the table definition
        // This method is for additional indexes if needed
        return '';
    }

    /**
     * Get composite index SQL for performance optimization
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @param string $index_name Index name
     * @param array $columns Column names for composite index
     * @return string Composite index SQL
     */
    private function get_composite_index_sql(string $table_name, string $index_name, array $columns): string {
        if (empty($columns)) {
            return '';
        }

        // Escape column names
        $escaped_columns = array_map(function ($column) {
            return "`{$column}`";
        }, $columns);

        $columns_sql = implode(', ', $escaped_columns);
        $index_name_escaped = esc_sql($index_name);

        return "CREATE INDEX `idx_{$index_name_escaped}` ON `{$table_name}` ({$columns_sql})";
    }

    /**
     * Get constraint SQL for a table
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @param array  $constraint Constraint definition
     * @return string Constraint SQL
     */
    private function get_constraint_sql(string $table_name, array $constraint): string {
        // Foreign key constraints would be defined here
        // Currently not implemented as tables are designed to be independent
        return '';
    }

    /**
     * Get table information
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @return array Table information
     */
    private function get_table_info(string $table_name): array {
        $info = [
            'exists' => false,
            'row_count' => 0,
            'data_size' => 0,
            'index_size' => 0,
            'total_size' => 0,
            'created' => null,
            'updated' => null
        ];

        if (!$this->table_exists($table_name)) {
            return $info;
        }

        $info['exists'] = true;

        // Get row count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table statistics require direct database access, table name cannot be prepared, table name is validated
        $row_count = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
        $info['row_count'] = (int) $row_count;

        // Get table size information
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table size information requires direct database access
        $size_info = $this->wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                "SELECT
                    data_length as data_size,
                    index_length as index_size,
                    (data_length + index_length) as total_size,
                    create_time as created,
                    update_time as updated
                FROM information_schema.TABLES
                WHERE table_schema = %s AND table_name = %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DB_NAME is a WordPress constant, safe to use
                DB_NAME,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is validated and used as parameter
                $table_name
            ),
            ARRAY_A
        );

        if ($size_info) {
            $info['data_size'] = (int) $size_info['data_size'];
            $info['index_size'] = (int) $size_info['index_size'];
            $info['total_size'] = (int) $size_info['total_size'];
            $info['created'] = $size_info['created'];
            $info['updated'] = $size_info['updated'];
        }

        return $info;
    }

    /**
     * Get table size in bytes
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @return int Table size in bytes
     */
    private function get_table_size(string $table_name): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table size calculation requires direct database access
        $size = $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                "SELECT (data_length + index_length) as total_size
                FROM information_schema.TABLES
                WHERE table_schema = %s AND table_name = %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DB_NAME is a WordPress constant, safe to use
                DB_NAME,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is validated and used as parameter
                $table_name
            )
        );

        return (int) $size;
    }

    /**
     * Get tables by category for better organization
     *
     * @since 1.0.0
     *
     * @param string $category Category name (seo, ai, content, scoring)
     * @return array Table names in the category
     */
    public function get_tables_by_category(string $category): array {
        return $this->table_categories[$category] ?? [];
    }

    /**
     * Get all table categories
     *
     * @since 1.0.0
     *
     * @return array All table categories with their tables
     */
    public function get_table_categories(): array {
        return $this->table_categories;
    }

    /**
     * Get table count by category
     *
     * @since 1.0.0
     *
     * @return array Table counts per category
     */
    public function get_table_count_by_category(): array {
        $counts = [];
        foreach ($this->table_categories as $category => $tables) {
            $counts[$category] = count($tables);
        }
        $counts['total'] = count($this->table_definitions);
        return $counts;
    }

    /**
     * Validate table definition structure
     *
     * @since 1.0.0
     *
     * @param string $table_name Table name to validate
     * @return array Validation results
     */
    public function validate_table_definition(string $table_name): array {
        $definition = $this->table_definitions[$table_name] ?? null;

        if (!$definition) {
            return [
                'valid' => false,
                'errors' => ["Table definition not found: {$table_name}"]
            ];
        }

        $errors = [];
        $required_keys = ['description', 'primary_key', 'indexes', 'foreign_keys'];

        foreach ($required_keys as $key) {
            if (!isset($definition[$key])) {
                $errors[] = "Missing required key '{$key}' in table definition for {$table_name}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Add composite indexes to existing tables for performance optimization
     *
     * @since 1.0.0
     *
     * @return bool Success status
     */
    public function add_performance_indexes(): bool {
        $success = true;

        foreach ($this->table_definitions as $table_name => $definition) {
            if (!empty($definition['composite_indexes'])) {
                $full_table_name = $this->get_table_name($table_name);

                // Check if table exists before adding indexes
                if (!$this->table_exists($full_table_name)) {
                    continue;
                }

                foreach ($definition['composite_indexes'] as $index_name => $columns) {
                    try {
                        // Check if index already exists
                        if ($this->index_exists($full_table_name, "idx_{$index_name}")) {
                            continue;
                        }

                        $index_sql = $this->get_composite_index_sql($full_table_name, $index_name, $columns);
                        if ($index_sql) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance index creation requires direct schema changes, DDL cannot be prepared
                            $result = $this->wpdb->query($index_sql);
                            if (false === $result) {
                                $success = false;
                                // Index creation failed - logged in database operations
                            }
                        }
                    } catch (\Exception $e) {
                        $success = false;
                        // Exception during index creation - logged in database operations
                    }
                }
            }
        }

        return $success;
    }

    /**
     * Check if an index exists on a table
     *
     * @since 1.0.0
     *
     * @param string $table_name Full table name
     * @param string $index_name Index name
     * @return bool Whether index exists
     */
    private function index_exists(string $table_name, string $index_name): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Index existence check requires direct database access
        $result = $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = %s AND table_name = %s AND index_name = %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DB_NAME is a WordPress constant, safe to use
                DB_NAME,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is validated and used as parameter
                $table_name,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $index_name is validated and used as parameter
                $index_name
            )
        );

        return (int) $result > 0;
    }

    /**
     * Check if MySQL supports JSON column type with caching
     *
     * Uses WordPress's built-in database version detection and caches the result
     * to avoid repeated database queries during schema creation.
     *
     * @since 1.0.0
     * @return bool True if MySQL 5.7+ supports JSON columns
     */
    private function get_mysql_json_support(): bool {
        // Check if we have cached result
        static $json_support = null;

        if ($json_support !== null) {
            return $json_support;
        }

        // Use WordPress's built-in database version method
        global $wpdb;

        // Get MySQL version using WordPress method (safer than direct query)
        $mysql_version = $wpdb->db_version();

        // Cache the result for subsequent calls
        $json_support = version_compare($mysql_version, '5.7.0', '>=');

        return $json_support;
    }
}
