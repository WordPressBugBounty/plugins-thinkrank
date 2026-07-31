<?php

/**
 * Database Manager Class
 * 
 * Handles database operations and provides repository pattern
 * 
 * @package ThinkRank\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Class
 * 
 * Single Responsibility: Database operations and query management
 * Repository Pattern: Abstraction layer for data access
 * 
 * @since 1.0.0
 */
class Database {

    /**
     * WordPress database instance
     * 
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Table names
     * 
     * @var array
     */
    private array $tables;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->tables = [
            'ai_cache' => $wpdb->prefix . 'thinkrank_ai_cache',
            'ai_usage' => $wpdb->prefix . 'thinkrank_ai_usage',
            'content_briefs' => $wpdb->prefix . 'thinkrank_content_briefs',
            'seo_scores' => $wpdb->prefix . 'thinkrank_seo_scores',
            'seo_performance' => $wpdb->prefix . 'thinkrank_seo_performance',
            'instant_indexing_logs' => $wpdb->prefix . 'thinkrank_instant_indexing_logs',
        ];
    }

    /**
     * Initialize database operations
     *
     * @return void
     */
    public function init(): void {
        // Add any initialization hooks here
        add_action('thinkrank_cache_cleanup', [$this, 'cleanup_expired_cache']);

        // Hook the missing usage analytics cron handler
        add_action('thinkrank_usage_analytics', [$this, 'process_weekly_analytics']);
    }

    /**
     * Get table name
     * 
     * @param string $table Table identifier
     * @return string Full table name
     * @throws \InvalidArgumentException If table doesn't exist
     */
    public function get_table(string $table): string {
        if (!isset($this->tables[$table])) {
            throw new \InvalidArgumentException(sprintf("Table '%s' not found", esc_html($table)));
        }

        return $this->tables[$table];
    }

    /**
     * Execute prepared query safely
     *
     * @param string $query SQL query with placeholders
     * @param array $args Query arguments
     * @return mixed Query result
     */
    public function query(string $query, array $args = []) {
        if (!empty($args)) {
            // Prepare the query first, then execute
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared in the line below
            $prepared_query = $this->wpdb->prepare($query, $args);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Using prepared query from above
            return $this->wpdb->query($prepared_query);
        }

        // For queries without parameters, execute directly (safe for static queries)
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input in static queries
        return $this->wpdb->query($query);
    }

    /**
     * Get single row
     *
     * @param string $query SQL query with placeholders
     * @param array $args Query arguments
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N)
     * @return mixed Single row result
     */
    public function get_row(string $query, array $args = [], string $output = OBJECT) {
        if (!empty($args)) {
            // Prepare the query first, then execute
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared in the line below
            $prepared_query = $this->wpdb->prepare($query, $args);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Using prepared query from above
            return $this->wpdb->get_row($prepared_query, $output);
        }

        // For queries without parameters, execute directly (safe for static queries)
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input in static queries
        return $this->wpdb->get_row($query, $output);
    }

    /**
     * Get multiple rows
     *
     * @param string $query SQL query with placeholders
     * @param array $args Query arguments
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N)
     * @return array Multiple rows result
     */
    public function get_results(string $query, array $args = [], string $output = OBJECT): array {
        if (!empty($args)) {
            // Prepare the query first, then execute
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared in the line below
            $prepared_query = $this->wpdb->prepare($query, $args);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Using prepared query from above
            $results = $this->wpdb->get_results($prepared_query, $output);
        } else {
            // For queries without parameters, execute directly (safe for static queries)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input in static queries
            $results = $this->wpdb->get_results($query, $output);
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Get single variable
     *
     * @param string $query SQL query with placeholders
     * @param array $args Query arguments
     * @return mixed Single variable result
     */
    public function get_var(string $query, array $args = []) {
        if (!empty($args)) {
            // Prepare the query first, then execute
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared in the line below
            $prepared_query = $this->wpdb->prepare($query, $args);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Using prepared query from above
            return $this->wpdb->get_var($prepared_query);
        }

        // For queries without parameters, execute directly (safe for static queries)
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input in static queries
        return $this->wpdb->get_var($query);
    }

    /**
     * Insert data into table
     * 
     * @param string $table Table identifier
     * @param array $data Data to insert
     * @param array $format Data format (optional)
     * @return int|false Insert ID or false on failure
     */
    public function insert(string $table, array $data, array $format = []) {
        $table_name = $this->get_table($table);

        $result = $this->wpdb->insert($table_name, $data, $format);

        if (false === $result) {
            $this->log_error('Insert failed', [
                'table' => $table,
                'data' => $data,
                'error' => $this->wpdb->last_error
            ]);
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Update data in table
     * 
     * @param string $table Table identifier
     * @param array $data Data to update
     * @param array $where Where conditions
     * @param array $format Data format (optional)
     * @param array $where_format Where format (optional)
     * @return int|false Number of rows updated or false on failure
     */
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $table_name = $this->get_table($table);

        $result = $this->wpdb->update($table_name, $data, $where, $format, $where_format);

        if (false === $result) {
            $this->log_error('Update failed', [
                'table' => $table,
                'data' => $data,
                'where' => $where,
                'error' => $this->wpdb->last_error
            ]);
        }

        return $result;
    }

    /**
     * Delete data from table
     * 
     * @param string $table Table identifier
     * @param array $where Where conditions
     * @param array $where_format Where format (optional)
     * @return int|false Number of rows deleted or false on failure
     */
    public function delete(string $table, array $where, array $where_format = []) {
        $table_name = $this->get_table($table);

        $result = $this->wpdb->delete($table_name, $where, $where_format);

        if (false === $result) {
            $this->log_error('Delete failed', [
                'table' => $table,
                'where' => $where,
                'error' => $this->wpdb->last_error
            ]);
        }

        return $result;
    }

    /**
     * Start database transaction
     *
     * @return void
     */
    public function start_transaction(): void {
        // Transaction commands don't need preparation as they contain no user input
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Commit database transaction
     *
     * @return void
     */
    public function commit(): void {
        // Transaction commands don't need preparation as they contain no user input
        $this->wpdb->query('COMMIT');
    }

    /**
     * Rollback database transaction
     *
     * @return void
     */
    public function rollback(): void {
        // Transaction commands don't need preparation as they contain no user input
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * Get last database error
     * 
     * @return string Last error message
     */
    public function get_last_error(): string {
        return $this->wpdb->last_error;
    }

    /**
     * Clean up expired cache entries
     *
     * @return void
     */
    public function cleanup_expired_cache(): void {
        $cache_table = $this->get_table('ai_cache');

        // Check WordPress version for %i support (introduced in 6.2)
        if (version_compare($GLOBALS['wp_version'], '6.2', '>=')) {
            // Use %i placeholder for table identifier (WordPress 6.2+)
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
            $deleted = $this->wpdb->query(
                $this->wpdb->prepare(
                    // expires_at is a bigint unix timestamp (see Cache_Manager),
                    // so compare against time(), not a MySQL datetime string.
                    'DELETE FROM %i WHERE expires_at < %d',
                    $cache_table,
                    time()
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
        } else {
            // Fallback for older WordPress versions - table name is escaped and safe
            $escaped_table = esc_sql($cache_table);
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
            $deleted = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM `{$escaped_table}` WHERE expires_at < %d",
                    time()
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        }

        if ($deleted > 0) {
            $this->log_error(
                sprintf('Cleaned up %d expired cache entries', $deleted),
                ['type' => 'info']
            );
        }
    }

    /**
     * Get database statistics
     * 
     * @return array Database statistics
     */
    public function get_stats(): array {
        $stats = [];

        foreach ($this->tables as $key => $table) {
            // Check WordPress version for %i support (introduced in 6.2)
            if (version_compare($GLOBALS['wp_version'], '6.2', '>=')) {
                // Use %i placeholder for table identifier (WordPress 6.2+)
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
                $count = $this->wpdb->get_var(
                    $this->wpdb->prepare('SELECT COUNT(*) FROM %i', $table)
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
            } else {
                // Fallback for older WordPress versions - table name is from our controlled list
                $escaped_table = esc_sql($table);
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly escaped and from controlled source
                $count = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$escaped_table}`");
            }
            $stats[$key] = (int) $count;
        }

        return $stats;
    }

    /**
     * Log database errors
     *
     * @param string $message Error message
     * @param array $context Error context
     * @return void
     */
    private function log_error(string $message, array $context = []): void {
        // Only log errors if both WP_DEBUG and custom debug flag are enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!defined('THINKRANK_DEBUG_LOGGING') || !THINKRANK_DEBUG_LOGGING) {
            return;
        }

        // Use WordPress error logging function only in debug mode
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('ThinkRank Database Error: ' . $message . ' - ' . wp_json_encode($context));
    }

    /**
     * Log debug information
     *
     * @param string $message Debug message
     * @param array $context Debug context
     * @return void
     */
    private function log_debug(string $message, array $context = []): void {
        // Only log debug info if both WP_DEBUG and custom debug flag are enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!defined('THINKRANK_DEBUG_LOGGING') || !THINKRANK_DEBUG_LOGGING) {
            return;
        }

        // Use WordPress error logging function only in debug mode
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('ThinkRank Database Debug: ' . $message . ' - ' . wp_json_encode($context));
    }

    /**
     * Process weekly analytics (handles thinkrank_usage_analytics cron job)
     *
     * @return void
     */
    public function process_weekly_analytics(): void {
        try {
            // 1. Clean old data from analytics tables
            $cleanup_results = $this->cleanup_old_analytics_data();

            // 2. Clear analytics cache to force fresh calculations
            $cache_results = $this->clear_analytics_cache();

            // Log successful processing
            $this->log_debug('Weekly analytics processing completed', [
                'cleanup_results' => $cleanup_results,
                'cache_results' => $cache_results,
                'processed_at' => current_time('mysql')
            ]);
        } catch (\Exception $e) {
            // Log error but don't throw to prevent cron job failures
            $this->log_debug('Weekly analytics processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Clean old data from analytics tables
     *
     * @return array Cleanup results
     */
    private function cleanup_old_analytics_data(): array {
        $results = [];

        // Define retention periods (in days)
        $retention_config = [
            'ai_usage' => 365,               // 1 year
            'seo_scores' => 180,             // 6 months
            'content_briefs' => 90,          // 3 months
            'seo_performance' => 90,         // 3 months (performance data grows fast)
            'instant_indexing_logs' => 90    // 3 months (one row per URL per submit)
        ];

        foreach ($retention_config as $table_key => $retention_days) {
            try {
                $deleted_count = $this->cleanup_table_data($table_key, $retention_days);
                $results[$table_key] = [
                    'deleted_records' => $deleted_count,
                    'retention_days' => $retention_days,
                    'success' => true
                ];
            } catch (\Exception $e) {
                $results[$table_key] = [
                    'deleted_records' => 0,
                    'retention_days' => $retention_days,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Also call the existing performance data cleanup method
        try {
            $performance_collector = new \ThinkRank\SEO\Performance_Data_Collector();
            $performance_deleted = $performance_collector->cleanup_old_data(30); // Keep 30 days of detailed performance data
            $results['performance_detailed'] = [
                'deleted_records' => $performance_deleted,
                'retention_days' => 30,
                'success' => true
            ];
        } catch (\Exception $e) {
            $results['performance_detailed'] = [
                'deleted_records' => 0,
                'retention_days' => 30,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        return $results;
    }

    /**
     * Clean data from specific table
     *
     * @param string $table_key Table identifier
     * @param int $retention_days Number of days to keep
     * @return int Number of deleted records
     */
    private function cleanup_table_data(string $table_key, int $retention_days): int {
        $table_name = $this->get_table($table_key);
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // Get the appropriate date column for each table
        $date_columns = [
            'ai_usage' => 'created_at',
            'seo_scores' => 'calculated_at',
            'content_briefs' => 'created_at',
            'seo_performance' => 'measured_at'
        ];

        $date_column = $date_columns[$table_key] ?? 'created_at';

        // Delete old records
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics cleanup requires direct database access, table and column names are validated internally
        $deleted = $this->wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names are validated internally
                "DELETE FROM `{$table_name}` WHERE `{$date_column}` < %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $cutoff_date is validated and used as parameter
                $cutoff_date
            )
        );

        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Clear analytics-related cache
     *
     * @return array Cache clearing results
     */
    private function clear_analytics_cache(): array {
        $cache_keys = [
            'analytics_overview_7d',
            'analytics_overview_30d',
            'analytics_overview_90d',
            'usage_breakdown_weekly',
            'usage_breakdown_monthly',
            'cost_analysis_7d',
            'cost_analysis_30d',
            'cost_analysis_90d'
        ];

        $cleared_count = 0;

        foreach ($cache_keys as $key) {
            if (delete_transient($key)) {
                $cleared_count++;
            }
        }

        // Also clear any user-specific analytics cache
        $analytics_like = $this->wpdb->esc_like('_transient_thinkrank_analytics_') . '%';
        $timeout_like = $this->wpdb->esc_like('_transient_timeout_thinkrank_analytics_') . '%';
        $options_table = $this->wpdb->options;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $options_table is from $wpdb->options, a WordPress core table name.
        $prepared_sql = $this->wpdb->prepare(
            "DELETE FROM {$options_table} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $analytics_like,
            $timeout_like
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above.
        $user_cache_deleted = $this->wpdb->query( $prepared_sql );

        return [
            'transients_cleared' => $cleared_count,
            'user_cache_cleared' => $user_cache_deleted !== false ? (int) $user_cache_deleted : 0,
            'total_cleared' => $cleared_count + ($user_cache_deleted !== false ? (int) $user_cache_deleted : 0)
        ];
    }

    /**
     * Get table sizes for monitoring
     *
     * @since 1.0.0
     *
     * @return array Table sizes in MB
     */
    public function get_table_sizes(): array {
        $sizes = [];
        $tables = [
            'ai_usage',
            'seo_scores',
            'content_briefs',
            'seo_performance'
        ];

        foreach ($tables as $table_key) {
            $table_name = $this->get_table($table_key);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared with placeholders below.
            $prepared_sql = $this->wpdb->prepare(
                "SELECT
                    table_name,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                    table_rows
                 FROM information_schema.TABLES
                 WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above.
            $size_result = $this->wpdb->get_row( $prepared_sql );

            if ($size_result) {
                $sizes[$table_key] = [
                    'size_mb' => (float) $size_result->size_mb,
                    'rows' => (int) $size_result->table_rows
                ];
            }
        }

        return $sizes;
    }
}
