<?php
/**
 * Plugin Uninstall Script
 * 
 * Handles complete plugin removal and cleanup
 * 
 * @package ThinkRank
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * ThinkRank Uninstaller Class
 * 
 * Single Responsibility: Handle complete plugin removal
 */
class ThinkRank_Uninstaller {
    
    /**
     * Run uninstall process
     * 
     * @return void
     */
    public static function uninstall(): void {
        // Check if user wants to keep data. Default to TRUE (preserve) to match the
        // Settings class default and the documented UI behavior — only nuke data when
        // the user has explicitly enabled "Delete all data on uninstall".
        $keep_data = (bool) get_option('thinkrank_keep_data_on_uninstall', true);

        if (!$keep_data) {
            self::remove_database_tables();
            self::remove_options();
            self::remove_user_meta();
            self::remove_post_meta();
        }
        
        self::clear_caches();
        self::remove_cron_jobs();
        self::remove_capabilities();
    }
    
    /**
     * Remove custom database tables
     *
     * @return void
     */
    private static function remove_database_tables(): void {
        global $wpdb;

        $tables = [
            // AI/Core Tables (from Database class)
            $wpdb->prefix . 'thinkrank_ai_cache',
            $wpdb->prefix . 'thinkrank_ai_usage',
            $wpdb->prefix . 'thinkrank_content_briefs',
            $wpdb->prefix . 'thinkrank_seo_scores',
            $wpdb->prefix . 'thinkrank_seo_performance',
            $wpdb->prefix . 'thinkrank_instant_indexing_logs',
            // SEO Tables (from Database_Schema)
            $wpdb->prefix . 'thinkrank_seo_settings',
            $wpdb->prefix . 'thinkrank_seo_analysis',
            $wpdb->prefix . 'thinkrank_seo_keywords',
            $wpdb->prefix . 'thinkrank_seo_schema',
            $wpdb->prefix . 'thinkrank_seo_social',
            $wpdb->prefix . 'thinkrank_seo_local',
            $wpdb->prefix . 'thinkrank_email_report_logs',
            // Rank Tracker Tables
            $wpdb->prefix . 'thinkrank_rank_tracking',
            $wpdb->prefix . 'thinkrank_tracked_keywords',
            // Pro feature tables (redirections, broken links, local SEO)
            $wpdb->prefix . 'thinkrank_redirects',
            $wpdb->prefix . 'thinkrank_404_logs',
            $wpdb->prefix . 'thinkrank_broken_links',
            $wpdb->prefix . 'thinkrank_locations',
        ];

        foreach ($tables as $table) {
            // Check WordPress version for %i support (introduced in 6.2)
            if (version_compare($GLOBALS['wp_version'], '6.2', '>=')) {
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
            } else {
                // Fallback for older WordPress versions - table name is from our controlled list
                $escaped_table = esc_sql($table);
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query("DROP TABLE IF EXISTS `{$escaped_table}`");
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
            }
        }
    }
    
    /**
     * Remove plugin options
     * 
     * @return void
     */
    private static function remove_options(): void {
        // Remove all ThinkRank options using wildcard delete for completeness
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup requires direct database access to remove all plugin options
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 OR option_name LIKE %s",
                $wpdb->esc_like('thinkrank_') . '%',
                // Appsero / WP Insights telemetry options (wpins_thinkrank_*)
                $wpdb->esc_like('wpins_thinkrank_') . '%'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        // Remove transients (prefixed with _transient_, not caught by options wildcard)
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table name is safe, uninstall cleanup requires direct database access
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 OR option_name LIKE %s",
                $wpdb->esc_like('_transient_thinkrank_') . '%',
                $wpdb->esc_like('_transient_timeout_thinkrank_') . '%'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }
    
    /**
     * Remove user meta data
     *
     * @return void
     */
    private static function remove_user_meta(): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table name is safe, uninstall cleanup requires direct database access
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta}
                 WHERE meta_key LIKE %s
                 OR meta_key LIKE %s",
                $wpdb->esc_like('thinkrank_') . '%',
                $wpdb->esc_like('_thinkrank_') . '%'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }
    
    /**
     * Remove post meta data
     *
     * @return void
     */
    private static function remove_post_meta(): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table name is safe, uninstall cleanup requires direct database access
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE %s
                 OR meta_key LIKE %s",
                $wpdb->esc_like('thinkrank_') . '%',
                $wpdb->esc_like('_thinkrank_') . '%'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }
    

    /**
     * Clear all caches
     * 
     * @return void
     */
    private static function clear_caches(): void {
        // Clear ThinkRank-specific object cache group
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('thinkrank');
        }
    }
    
    /**
     * Remove scheduled cron jobs
     * 
     * @return void
     */
    private static function remove_cron_jobs(): void {
        $cron_jobs = [
            'thinkrank_cache_cleanup',
            'thinkrank_usage_analytics',
        ];
        
        foreach ($cron_jobs as $job) {
            wp_clear_scheduled_hook($job);
        }
    }
    
    /**
     * Remove custom capabilities
     * 
     * @return void
     */
    private static function remove_capabilities(): void {
        // Runs even when the user keeps their data, so the capability-sync marker
        // has to go with it. Leaving it behind would make Capability_Manager::ensure()
        // short-circuit on the next install and never re-grant thinkrank_access,
        // locking administrators out of the admin menu.
        delete_option('thinkrank_caps_version');

        // Keep in sync with ThinkRank\Core\Capability_Manager::capabilities().
        // The plugin autoloader isn't available during uninstall, so the slugs are
        // mirrored here. The trailing legacy slugs were granted by older versions
        // and are removed too so historical roles are fully cleaned.
        $capabilities = [
            'thinkrank_access',
            'thinkrank_site_identity',
            'thinkrank_analytics',
            'thinkrank_performance',
            'thinkrank_global_seo',
            'thinkrank_image_seo',
            'thinkrank_schema',
            'thinkrank_social_media',
            'thinkrank_crawling',
            'thinkrank_instant_indexing',
            'thinkrank_author_archives',
            'thinkrank_content_tools',
            'thinkrank_internal_links',
            'thinkrank_settings',
            'thinkrank_manage_roles',
            // Legacy slugs (pre-Role Manager) — remove if still present.
            'thinkrank_manage_settings',
            'thinkrank_view_analytics',
            'thinkrank_manage_credits',
            'thinkrank_use_ai_features',
        ];
        
        $roles = wp_roles();
        
        foreach ($roles->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }
    }
}

// Run the uninstaller
ThinkRank_Uninstaller::uninstall();
