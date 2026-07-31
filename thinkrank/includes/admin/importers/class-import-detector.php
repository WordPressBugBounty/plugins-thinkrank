<?php

/**
 * Import Detector
 *
 * Database-level detection of SEO plugins. Works even when source plugins
 * are deactivated by querying meta tables and custom tables directly.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import Detector Class
 *
 * @since 2.0.0
 */
class Import_Detector {

    /**
     * Transient key for caching detection results
     */
    private const CACHE_KEY = 'thinkrank_import_detection';

    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Plugin detection configurations
     */
    private const PLUGINS = [
        'yoast' => [
            'name'         => 'Yoast SEO',
            'meta_prefix'  => '_yoast_wpseo_',
            'option_keys'  => ['wpseo', 'wpseo_titles', 'wpseo_social'],
            'plugin_files' => ['wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php'],
        ],
        'rankmath' => [
            'name'         => 'Rank Math',
            'meta_prefix'  => 'rank_math_',
            'option_keys'  => ['rank-math-options-general', 'rank-math-options-titles'],
            'plugin_files' => ['seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php'],
        ],
        'seopress' => [
            'name'         => 'SEOPress',
            'meta_prefix'  => '_seopress_',
            // SEOPress option names all carry the `_option_name` suffix.
            'option_keys'  => ['seopress_titles_option_name', 'seopress_social_option_name', 'seopress_advanced_option_name'],
            'plugin_files' => ['wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php'],
        ],
        'aioseo' => [
            'name'         => 'All in One SEO',
            'meta_prefix'  => '',
            'option_keys'  => ['aioseo_options'],
            'plugin_files' => ['all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php'],
        ],
    ];

    /**
     * Detect all source plugins present in the database
     *
     * @param bool $use_cache Whether to use cached results
     * @return array Detected plugins with item counts
     */
    public function detect(bool $use_cache = true): array {
        if ($use_cache) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $detected = [];

        foreach (self::PLUGINS as $slug => $config) {
            $result = $this->detect_plugin($slug, $config);
            if ($result !== null) {
                $detected[$slug] = $result;
            }
        }

        set_transient(self::CACHE_KEY, $detected, self::CACHE_TTL);

        return $detected;
    }

    /**
     * Clear the detection cache
     *
     * @return void
     */
    public function clear_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Detect a single plugin's data presence
     *
     * @param string $slug Plugin slug
     * @param array $config Plugin configuration
     * @return array|null Detection result or null if not found
     */
    private function detect_plugin(string $slug, array $config): ?array {
        global $wpdb;

        // Only surface source plugins that are currently installed AND active.
        // Migrating from a plugin the user no longer runs isn't actionable, so
        // leftover data from a deactivated/uninstalled plugin is intentionally
        // excluded from the migration screen.
        if (!$this->is_source_active($config['plugin_files'] ?? [])) {
            return null;
        }

        $counts = [];
        $total = 0;

        if ($slug === 'aioseo') {
            // AIOSEO uses a custom table
            $counts = $this->detect_aioseo();
        } else {
            // Standard postmeta-based plugins
            $prefix = $config['meta_prefix'];

            // Count posts with this plugin's meta
            $post_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like($prefix) . '%'
                )
            );

            if ($post_count > 0) {
                $counts['postmeta'] = $post_count;
            }

            // Count terms with this plugin's meta
            $term_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like($prefix) . '%'
                )
            );

            if ($term_count > 0) {
                $counts['termmeta'] = $term_count;
            }
        }

        // Redirections / 404 logs. The UI derives its type checkboxes from
        // these counts, so a type missing here is invisible to the user even
        // when the exporter supports it (Rank Math Pro's redirects + 404
        // Monitor and Yoast Premium's redirects were never offered).
        $counts = array_merge($counts, $this->detect_redirect_data($slug));

        // Check for settings
        $has_settings = false;
        foreach ($config['option_keys'] as $option_key) {
            if (get_option($option_key, null) !== null) {
                $has_settings = true;
                break;
            }
        }

        if ($has_settings) {
            $counts['settings'] = 1;
        }

        // Only return if we found something
        if (empty($counts)) {
            return null;
        }

        foreach ($counts as $count) {
            $total += $count;
        }

        return [
            'plugin'      => $slug,
            'plugin_name' => $config['name'],
            'counts'      => $counts,
            'total'       => $total,
        ];
    }

    /**
     * Whether any of a source plugin's known main files is active.
     *
     * Checks both single-site and network activation. A plugin that is merely
     * installed but not active returns false.
     *
     * @param string[] $plugin_files Candidate plugin main files (free + pro).
     * @return bool
     */
    private function is_source_active(array $plugin_files): bool {
        if (empty($plugin_files)) {
            return false;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($plugin_files as $plugin_file) {
            if (is_plugin_active($plugin_file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count a source plugin's redirect / 404-log stores, matching what its
     * exporter reads.
     *
     * @param string $slug Plugin slug
     * @return array Partial counts (redirections / 404_logs keys only)
     */
    private function detect_redirect_data(string $slug): array {
        global $wpdb;

        $counts = [];

        $count_table = static function (string $suffix) use ($wpdb): int {
            $table = $wpdb->prefix . $suffix;
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                return 0;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        };

        if ($slug === 'rankmath') {
            $redirects = $count_table('rank_math_redirections');
            if ($redirects > 0) {
                $counts['redirections'] = $redirects;
            }
            $logs = $count_table('rank_math_404_logs');
            if ($logs > 0) {
                $counts['404_logs'] = $logs;
            }
        } elseif ($slug === 'yoast') {
            $redirects = $count_table('yoast_seo_redirects');
            if ($redirects > 0) {
                $counts['redirections'] = $redirects;
            }
        } elseif ($slug === 'seopress') {
            $redirects = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'seopress_404'"
            );
            if ($redirects > 0) {
                $counts['redirections'] = $redirects;
            }
        }

        return $counts;
    }

    /**
     * Detect AIOSEO custom table data
     *
     * @return array Counts array
     */
    private function detect_aioseo(): array {
        global $wpdb;

        $counts = [];
        $table_name = $wpdb->prefix . 'aioseo_posts';

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );

        if ($table_exists) {
            $post_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            if ($post_count > 0) {
                $counts['postmeta'] = $post_count;
            }
        }

        // Check for redirections table
        $redirects_table = $wpdb->prefix . 'aioseo_redirects';
        $redirects_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $redirects_table)
        );

        if ($redirects_exists) {
            $redirect_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$redirects_table}");
            if ($redirect_count > 0) {
                $counts['redirections'] = $redirect_count;
            }
        }

        return $counts;
    }
}
