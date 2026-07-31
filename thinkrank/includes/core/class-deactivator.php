<?php
/**
 * Plugin Deactivator Class
 * 
 * Handles plugin deactivation tasks
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
 * Deactivator Class
 * 
 * Single Responsibility: Handle plugin deactivation tasks only
 * 
 * @since 1.0.0
 */
class Deactivator {
    
    /**
     * Plugin deactivation tasks
     * 
     * @return void
     */
    public function deactivate(): void {
        $this->clear_scheduled_hooks();
        $this->clear_cache();
        $this->remove_published_files();
        $this->log_deactivation();

        // Note: We don't delete user data on deactivation
        // Data is only removed on uninstall
    }

    /**
     * Remove physically published files so they stop serving once the plugin
     * is inactive (the published llms.txt would otherwise keep serving forever).
     *
     * @return void
     */
    private function remove_published_files(): void {
        if (class_exists('ThinkRank\\SEO\\LLMs_Txt_Manager')) {
            (new \ThinkRank\SEO\LLMs_Txt_Manager())->delete_llms_txt_file();
        }
    }
    
    /**
     * Clear all scheduled hooks
     * 
     * @return void
     */
    private function clear_scheduled_hooks(): void {
        $scheduled_hooks = [
            'thinkrank_cache_cleanup',
            'thinkrank_usage_analytics',
            // Email report tick (see ThinkRank\SEO\Email_Report_Scheduler::CRON_HOOK)
            // — was previously left scheduled after deactivation.
            'thinkrank_email_report_tick',
        ];
        
        // Clear all instances of our hooks
        foreach ($scheduled_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
    
    /**
     * Clear plugin cache
     * 
     * @return void
     */
    private function clear_cache(): void {
        global $wpdb;
        
        // Clear AI cache table with proper escaping
        $cache_table = $wpdb->prefix . 'thinkrank_ai_cache';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin deactivation requires direct database access to check table existence
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $cache_table)
        );
        if ($table_exists === $cache_table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix, plugin deactivation requires direct database access
            $wpdb->query("TRUNCATE TABLE {$cache_table}");
        }
        
        // Clear WordPress transients with proper escaping
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->options is a WordPress core property, plugin deactivation requires direct database access
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 OR option_name LIKE %s",
                $wpdb->esc_like('_transient_thinkrank_') . '%',
                $wpdb->esc_like('_transient_timeout_thinkrank_') . '%'
            )
        );
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('thinkrank');
        }
    }
    
    /**
     * Log deactivation for analytics
     * 
     * @return void
     */
    private function log_deactivation(): void {
        $deactivation_data = [
            'timestamp' => time(),
            'version' => THINKRANK_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ];
        
        // Store deactivation data for potential feedback
        update_option('thinkrank_last_deactivation', $deactivation_data);
        
        // Remove activation flag
        delete_option('thinkrank_activated');
    }
}
