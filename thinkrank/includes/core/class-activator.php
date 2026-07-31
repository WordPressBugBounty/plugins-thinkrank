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
     * Plugin activation tasks
     * 
     * @return void
     * @throws \Exception If activation fails
     */
    public function activate(): void {
        $this->check_requirements();
        $this->create_database_tables();
        $this->set_default_options();
        $this->setup_indexnow_key();
        $this->schedule_cron_jobs();
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
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            throw new \Exception('ThinkRank requires PHP 8.0 or higher');
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
            $index_results = $schema->add_performance_indexes();
            if (!$index_results) {
                // Performance indexes could not be created - activation continues
            }

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
