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
     * Which web-root artifacts deactivation removed, for activation to restore.
     *
     * Holds a subset of ['sitemap', 'robots', 'llms']. Read and deleted by
     * {@see Activator::restore_webroot_artifacts()}. Keep in sync with
     * Activator::REPUBLISH_OPTION.
     *
     * @since 2.1.0
     */
    public const REPUBLISH_OPTION = 'thinkrank_webroot_republish';

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
     * is inactive.
     *
     * ThinkRank publishes its sitemap, robots.txt and llms.txt as real files in
     * the web root rather than serving them through rewrite rules. A leftover
     * file is served by the web server before WordPress boots, so it does not
     * merely go stale — it shadows the route of whatever the user switched to
     * (#510). Deactivating to trial another SEO plugin is the common way people
     * hit that, which is why removal belongs here and not only in uninstall.
     *
     * The stored settings and documents are deliberately untouched: only the
     * artifacts go. {@see Activator::restore_webroot_artifacts()} republishes
     * them from those settings when the plugin is switched back on.
     *
     * @since 2.1.0 Also removes the sitemap files and a generated robots.txt,
     *              and keeps the llms.txt document instead of discarding it.
     *
     * @return void
     */
    private function remove_published_files(): void {
        require_once THINKRANK_PLUGIN_DIR . 'includes/cleanup-webroot.php';

        // Record what was actually published so reactivation restores exactly
        // that, and nothing else. Republishing from settings alone would write
        // files a site never had — every artifact defaults to enabled, so a
        // fresh install would start emitting a robots.txt it had not asked for.
        $republish = [];

        if (thinkrank_webroot_delete_sitemaps(thinkrank_webroot_read_sitemap_settings())['deleted']) {
            $republish[] = 'sitemap';
        }

        if (thinkrank_webroot_delete_robots_txt()['deleted']) {
            $republish[] = 'robots';
        }

        if (class_exists('ThinkRank\\SEO\\LLMs_Txt_Manager')) {
            // Checked up front: unpublish_static_file() reports success for a
            // file that was never there, which would mark a site that never
            // published llms.txt for republishing.
            $was_published = file_exists(ABSPATH . 'llms.txt');

            // unpublish_static_file(), not delete_llms_txt_file(): the served
            // file goes, the user's document stays for reactivation.
            if ((new \ThinkRank\SEO\LLMs_Txt_Manager())->unpublish_static_file() && $was_published) {
                $republish[] = 'llms';
            }
        }

        // Autoload off: this is read once, on the next activation.
        update_option(self::REPUBLISH_OPTION, $republish, false);
        // Static /.well-known/ OAuth discovery files (published by the MCP
        // self-test on hosts whose proxy intercepts that directory) — a
        // static copy must not keep advertising a server that is now off.
        if (class_exists('ThinkRank\\Mcp\\Mcp_Static_Discovery')) {
            \ThinkRank\Mcp\Mcp_Static_Discovery::remove();
        }
    }
    
    /**
     * Clear all scheduled hooks
     * 
     * @return void
     */
    private function clear_scheduled_hooks(): void {
        // Read from the shared manifest rather than a local copy. Deactivation
        // cleared 5 of the 15 hooks the plugin schedules and uninstall cleared
        // 2, so a removal left recurring events behind — including
        // thinkrank_google_token_refresh on the custom thinkrank_45min
        // recurrence, whose interval no longer resolves once the plugin's
        // cron_schedules filter is gone (#389).
        $manifest = require THINKRANK_PLUGIN_DIR . 'includes/cleanup-manifest.php';

        foreach ($manifest['cron_hooks'] as $hook) {
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
