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
     * Option that records a deliberate uninstall.
     *
     * Keep in sync with ThinkRank\Core\Activator::UNINSTALLED_OPTION and with
     * ThinkRank Pro's Free_Plugin_Installer.
     */
    private const UNINSTALLED_OPTION = 'thinkrank_uninstalled';

    /**
     * Run uninstall process
     * 
     * @return void
     */
    public static function uninstall(): void {
        // Everything below resolves through $wpdb->prefix and $wpdb->options,
        // i.e. the current blog. On a network install WordPress runs this once,
        // so every other site kept its tables, its options and its stored
        // credentials — API keys and Google refresh tokens included (#399).
        if (is_multisite()) {
            $site_ids = get_sites([
                'fields'                 => 'ids',
                'number'                 => 0,
                'update_site_meta_cache' => false,
            ]);

            foreach ($site_ids as $site_id) {
                switch_to_blog((int) $site_id);
                self::uninstall_site();
                restore_current_blog();
            }

            return;
        }

        self::uninstall_site();
    }

    /**
     * Remove everything ThinkRank created on the current blog.
     *
     * @return void
     */
    private static function uninstall_site(): void {
        // Check if user wants to keep data. Default to TRUE (preserve) to match the
        // Settings class default and the documented UI behavior — only nuke data when
        // the user has explicitly enabled "Delete all data on uninstall".
        $keep_data = (bool) get_option('thinkrank_keep_data_on_uninstall', true);

        if (!$keep_data) {
            self::remove_database_tables();
            self::remove_options();
            self::remove_user_meta();
            self::remove_post_meta();
            self::remove_term_meta();
        }
        
        self::clear_caches();
        self::remove_cron_jobs();
        self::remove_capabilities();
        self::mark_uninstalled();
    }

    /**
     * The shared cleanup manifest.
     *
     * There is no autoloader here — WP_UNINSTALL_PLUGIN loads this file without
     * the plugin — so the cron-hook and capability lists come from a plain
     * array file that the deactivator reads too.
     *
     * @return array{cron_hooks: string[], capabilities: string[]}
     */
    private static function manifest(): array {
        return require __DIR__ . '/includes/cleanup-manifest.php';
    }

    /**
     * Record that the user deliberately uninstalled the plugin.
     *
     * ThinkRank Pro auto-installs and activates the free plugin whenever it
     * finds it missing, which silently re-ran the activator and recreated every
     * table this uninstall had just dropped. Pro reads this marker and stops
     * auto-installing, falling back to its "Install ThinkRank" notice, so a
     * deliberate removal stays removed until the user asks for it back.
     *
     * Written last, after remove_options() has wiped the `thinkrank_` namespace,
     * and cleared again by Activator on the next activation.
     *
     * @return void
     */
    private static function mark_uninstalled(): void {
        delete_option(self::UNINSTALLED_OPTION);
        add_option(self::UNINSTALLED_OPTION, time(), '', 'no');
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
            // AI Visibility Tables. These were registered in Database_Schema but
            // never listed here, so an uninstall left them behind — bv_tasks in
            // particular holds the full text of every AI answer (#302).
            $wpdb->prefix . 'thinkrank_ai_traffic',
            $wpdb->prefix . 'thinkrank_brand_visibility_checks',
            $wpdb->prefix . 'thinkrank_bv_runs',
            $wpdb->prefix . 'thinkrank_bv_tasks',
            // Rank Tracker, Redirections, Broken Links and Local SEO tables are
            // created and dropped by ThinkRank Pro's own uninstaller. Free used to
            // drop them here, which destroyed a still-active Pro install's data
            // when only the free plugin was removed (#384).
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
        // Remove all ThinkRank options using wildcard delete for completeness.
        //
        // `thinkrank_%` also matches `thinkrank_pro_%`, so this used to delete a
        // still-active Pro install's license key and every module setting (#384).
        // Pro ships its own uninstaller and owns that namespace exclusively, so
        // every pattern here excludes it.
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup requires direct database access to remove all plugin options
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE (option_name LIKE %s AND option_name NOT LIKE %s)
                 OR option_name LIKE %s",
                $wpdb->esc_like('thinkrank_') . '%',
                $wpdb->esc_like('thinkrank_pro_') . '%',
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
                 WHERE (option_name LIKE %s AND option_name NOT LIKE %s)
                 OR (option_name LIKE %s AND option_name NOT LIKE %s)",
                $wpdb->esc_like('_transient_thinkrank_') . '%',
                $wpdb->esc_like('_transient_thinkrank_pro_') . '%',
                $wpdb->esc_like('_transient_timeout_thinkrank_') . '%',
                $wpdb->esc_like('_transient_timeout_thinkrank_pro_') . '%'
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
     * Remove term meta data
     *
     * The plugin writes term meta from the term UI, the abilities API
     * (Update_Term_Seo) and the importer (Snapshot_Migrator) — SEO title,
     * meta description, the robots payload and the `_thinkrank_imported_from`
     * marker — and there was no counterpart to remove_post_meta(), so all of
     * it outlived the plugin (#399).
     *
     * @return void
     */
    private static function remove_term_meta(): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table name is safe, uninstall cleanup requires direct database access
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->termmeta}
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
        // Was 2 hooks against the 15 the plugin schedules, and weaker than the
        // deactivator's 5 — so uninstalling without deactivating first left
        // even more behind (#389).
        foreach (self::manifest()['cron_hooks'] as $job) {
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

        // From the shared manifest. The hand-mirrored copy that used to live
        // here had drifted from Capability_Manager::capabilities() by four
        // slugs — thinkrank_ai_insights, thinkrank_redirections,
        // thinkrank_broken_links and thinkrank_woocommerce were still granted
        // to every role after an uninstall (#399). The manifest also carries
        // the pre-Role-Manager slugs so historical roles are fully cleaned.
        $capabilities = self::manifest()['capabilities'];

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
