<?php

/**
 * Snapshot Store
 *
 * Static CRUD for chunked wp_options snapshot data.
 * All options use autoload=false to prevent loading on every page view.
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
 * Snapshot Store Class
 *
 * Manages chunked read/write of snapshot data in wp_options.
 *
 * @since 2.0.0
 */
class Snapshot_Store {

    /**
     * Option key prefix for all snapshot data
     */
    private const OPTION_PREFIX = 'thinkrank_snapshot_';

    /**
     * Write a chunk of snapshot data
     *
     * @param string $plugin Plugin slug (e.g., 'yoast')
     * @param string $type Data type (e.g., 'postmeta')
     * @param int $page Chunk/page number
     * @param array $records Array of normalized records
     * @return void
     */
    public static function write_chunk(string $plugin, string $type, int $page, array $records): void {
        $option_key = self::OPTION_PREFIX . "{$plugin}_{$type}_chunk_{$page}";
        update_option($option_key, $records, false);
    }

    /**
     * Write or update the manifest for a plugin snapshot
     *
     * @param string $plugin Plugin slug
     * @param array $manifest Manifest data
     * @return void
     */
    public static function write_manifest(string $plugin, array $manifest): void {
        $option_key = self::OPTION_PREFIX . "{$plugin}_manifest";
        update_option($option_key, $manifest, false);
    }

    /**
     * Read a chunk of snapshot data
     *
     * @param string $plugin Plugin slug
     * @param string $type Data type
     * @param int $page Chunk/page number
     * @return array|null Chunk data or null if not found
     */
    public static function read_chunk(string $plugin, string $type, int $page): ?array {
        $option_key = self::OPTION_PREFIX . "{$plugin}_{$type}_chunk_{$page}";
        $data = get_option($option_key, null);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the manifest for a plugin snapshot
     *
     * @param string $plugin Plugin slug
     * @return array|null Manifest data or null if not found
     */
    public static function get_manifest(string $plugin): ?array {
        $option_key = self::OPTION_PREFIX . "{$plugin}_manifest";
        $data = get_option($option_key, null);
        return is_array($data) ? $data : null;
    }

    /**
     * Get all available snapshots with their manifests
     *
     * @return array Array of manifests keyed by plugin slug
     */
    public static function get_available_snapshots(): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(self::OPTION_PREFIX) . '%_manifest'
            ),
            ARRAY_A
        );

        $snapshots = [];
        foreach ($results as $row) {
            // Extract plugin slug from option name: thinkrank_snapshot_{plugin}_manifest
            $option_name = $row['option_name'];
            $plugin = str_replace([self::OPTION_PREFIX, '_manifest'], '', $option_name);

            $manifest = maybe_unserialize($row['option_value']);
            if (is_array($manifest)) {
                $snapshots[$plugin] = $manifest;
            }
        }

        return $snapshots;
    }

    /**
     * Delete all snapshot data for a plugin
     *
     * @param string $plugin Plugin slug
     * @return int Number of options deleted
     */
    public static function delete_snapshot(string $plugin): int {
        global $wpdb;

        // Find all options for this plugin's snapshot
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(self::OPTION_PREFIX . $plugin . '_') . '%'
            )
        );

        $deleted = 0;
        foreach ($option_names as $option_name) {
            if (delete_option($option_name)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
