<?php

/**
 * Plugin Activator Class
 * 
 * Handles plugin activation tasks
 * 
 * @package ThinkRank\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

use ThinkRank\Database\Database_Schema;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activator Class
 * 
 * Single Responsibility: Handle plugin activation tasks only
 * 
 * @since 1.0.0
 */
class Activator {

    /**
     * Option the uninstaller sets to record a deliberate removal.
     *
     * Pro's Free_Plugin_Installer skips its silent auto-install while this is
     * set, so activating again — the user asking for the plugin back — has to
     * clear it. Keep in sync with uninstall.php.
     */
    public const UNINSTALLED_OPTION = 'thinkrank_uninstalled';

    /**
     * Plugin activation tasks
     *
     * @return void
     * @throws \Exception If activation fails
     */
    public function activate(): void {
        delete_option(self::UNINSTALLED_OPTION);

        $this->check_requirements();
        $this->create_database_tables();
        $this->set_default_options();
        $this->setup_indexnow_key();
        $this->schedule_cron_jobs();
        $this->restore_webroot_artifacts();
        $this->set_activation_flag();

        // Grant the admin capabilities here rather than waiting for the `init`
        // hook Role_Manager registers, so the menu is reachable on the very
        // first admin request after activation.
        Capability_Manager::ensure();
    }

    /**
     * Setup IndexNow API Key
     *
     * Generates a unique 128-bit key and creates the key file in the root directory.
     *
     * @return void
     */
    private function setup_indexnow_key(): void {
        $option_name = 'thinkrank_instant_indexing_settings';
        $settings = get_option($option_name, []);

        // Check if key exists
        if (empty($settings['api_key'])) {
            try {
                // Generate 128-bit key (32 hex characters)
                // Using bin2hex(random_bytes(16)) as requested
                $key = bin2hex(random_bytes(16));

                // Save to options
                $settings['api_key'] = $key;

                // Initialize default post types if not set
                if (!isset($settings['auto_submit_post_types'])) {
                    $settings['auto_submit_post_types'] = ['post', 'page'];
                }

                update_option($option_name, $settings);

                // Create the key file in WordPress root using WP_Filesystem
                $file_path = ABSPATH . $key . '.txt';
                global $wp_filesystem;
                if (!function_exists('WP_Filesystem')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                WP_Filesystem();
                if ($wp_filesystem && $wp_filesystem->is_writable(ABSPATH)) {
                    $wp_filesystem->put_contents($file_path, $key, FS_CHMOD_FILE);
                }
            } catch (\Exception $e) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('ThinkRank: Failed to create IndexNow key file: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Check system requirements
     * 
     * @return void
     * @throws \Exception If requirements not met
     */
    private function check_requirements(): void {
        // PHP version check
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            throw new \Exception('ThinkRank requires PHP 7.4 or higher');
        }

        // WordPress version check
        if (version_compare(get_bloginfo('version'), '6.0', '<')) {
            throw new \Exception('ThinkRank requires WordPress 6.0 or higher');
        }

        // Required PHP extensions
        $required_extensions = ['curl', 'json', 'mbstring'];
        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                throw new \Exception(sprintf("Required PHP extension '%s' is not loaded", esc_html($extension)));
            }
        }

        // Check if we can write to WordPress root directory (for robots.txt, llms.txt, sitemaps)
        if (!wp_is_writable(ABSPATH)) {
            // Log warning but don't block activation — some hosts restrict ABSPATH writes
            // and file-writing features will gracefully degrade via WP_Filesystem checks
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('ThinkRank: WordPress root directory is not writable. Some features (robots.txt, llms.txt, sitemaps) may not work.');
            }
        }
    }

    /**
     * Create database tables
     *
     * Uses the consolidated Database_Schema class to create all 11 ThinkRank tables:
     * - SEO Tables (7): Settings, Analysis, Keywords, Schema, Social, Performance, Local
     * - AI/Core Tables (4): AI Cache, AI Usage, Content Briefs, SEO Scores
     *
     * @return void
     * @throws \Exception If table creation fails
     */
    private function create_database_tables(): void {
        try {
            // Use the Database_Schema class to create all 11 ThinkRank tables
            $schema = new Database_Schema();
            $results = $schema->create_tables();

            if (!$results['success']) {
                $error_message = 'Failed to create database tables: ' . implode(', ', $results['errors']);
                throw new \Exception($error_message);
            }

            // Add performance indexes for Phase 2 optimization
            // Best effort: activation continues if the performance indexes
            // cannot be created.
            $schema->add_performance_indexes();

            // Database tables created successfully

        } catch (\Exception $e) {
            throw new \Exception('Database table creation failed: ' . esc_html($e->getMessage()));
        }
    }



    /**
     * Set default plugin options
     * 
     * @return void
     */
    private function set_default_options(): void {
        $default_options = [
            'thinkrank_version' => THINKRANK_VERSION,

            'thinkrank_ai_provider' => 'openai',
            'thinkrank_cache_duration' => 3600, // 1 hour
            'thinkrank_max_requests_per_minute' => 10,
            'thinkrank_enable_logging' => true,
            'thinkrank_auto_optimize' => false,
            'thinkrank_seo_score_threshold' => 70,
        ];

        foreach ($default_options as $option_name => $option_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $option_value);
            }
        }
    }


    /**
     * Republish the web-root artifacts deactivation took away.
     *
     * Deactivation removes the published sitemap, robots.txt and llms.txt so an
     * inactive ThinkRank stops shadowing whatever the user switched to (#510).
     * That is only safe if switching the plugin back on puts them back, which is
     * what this does.
     *
     * Restores strictly what {@see Deactivator::REPUBLISH_OPTION} recorded as
     * having been removed — never "everything the settings would allow", which
     * on a fresh install would publish files the site never had.
     *
     * The sitemap goes through schedule_regeneration() rather than being built
     * inline: a full rebuild on a large site is far too slow to sit inside an
     * activation request, and the debounced hook already respects the master
     * `enabled` flag. robots.txt and llms.txt are single small writes, so they
     * happen here.
     *
     * @since 2.1.0
     *
     * @return void
     */
    private function restore_webroot_artifacts(): void {
        $republish = get_option(Deactivator::REPUBLISH_OPTION, null);

        if ($republish === null) {
            // No recorded deactivation — a first install, or an activation that
            // already consumed the record.
            return;
        }

        // Consume it first. A restore that fatals must not re-run on every
        // subsequent activation, and each entry below is independently guarded.
        delete_option(Deactivator::REPUBLISH_OPTION);

        if (!is_array($republish)) {
            return;
        }

        try {
            if (in_array('sitemap', $republish, true) && class_exists('ThinkRank\\SEO\\Sitemap_Generator')) {
                // Read-only instance: the hook-registering one would bind a
                // second set of content-change listeners to this request.
                (new \ThinkRank\SEO\Sitemap_Generator(false))->schedule_regeneration();
            }

            if (in_array('robots', $republish, true) && class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
                (new \ThinkRank\SEO\Site_Identity_Manager())->sync_robots_txt_file();
            }

            if (in_array('llms', $republish, true) && class_exists('ThinkRank\\SEO\\LLMs_Txt_Manager')) {
                $llms    = new \ThinkRank\SEO\LLMs_Txt_Manager();
                $content = $llms->get_published_content();

                // write_llms_txt_to_file() enforces the enabled toggle and the
                // delivery mode itself, so an empty document is the only case
                // worth short-circuiting here.
                if ($content !== '') {
                    $llms->write_llms_txt_to_file($content);
                }
            }
        } catch (\Throwable $e) {
            // A failed republish must not block activation — the user would be
            // left unable to switch the plugin on at all. The artifacts rebuild
            // on the next content or settings save.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('ThinkRank: failed to restore web-root artifacts: ' . $e->getMessage());
            }
        }
    }

    /**
     * Schedule cron jobs
     *
     * @return void
     */
    private function schedule_cron_jobs(): void {

        // Schedule cache cleanup
        if (!wp_next_scheduled('thinkrank_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'thinkrank_cache_cleanup');
        }

        // Schedule usage analytics
        if (!wp_next_scheduled('thinkrank_usage_analytics')) {
            wp_schedule_event(time(), 'weekly', 'thinkrank_usage_analytics');
        }
    }

    /**
     * Set activation flag for first-time setup
     *
     * @return void
     */
    private function set_activation_flag(): void {
        update_option('thinkrank_activated', true);
        update_option('thinkrank_activation_time', time());

        // Set flag for showing welcome screen
        update_option('thinkrank_show_welcome', true);

        // Trigger a one-time redirect to the Setup Wizard on the next admin load,
        // but only when the wizard has not already been completed. A short-lived
        // transient is used so it auto-expires and never fires for bulk/network
        // activations that skip the redirect window.
        if (!get_option('thinkrank_setup_wizard_completed')) {
            set_transient('thinkrank_setup_wizard_redirect', 1, 60);
        }
    }
}
