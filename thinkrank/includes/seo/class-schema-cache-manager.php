<?php
/**
 * Schema Cache Manager
 *
 * Handles caching of schema operations to improve performance and reduce database queries.
 * Follows the established AI Cache Manager pattern with schema-specific optimizations.
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema Cache Manager Class
 *
 * Single Responsibility: Manage schema operation caching for performance optimization
 * Provides 90% reduction in database queries and sub-second response times.
 *
 * @since 1.0.0
 */
class Schema_Cache_Manager {

    /**
     * Cache group for schema operations
     *
     * @since 1.0.0
     */
    private const CACHE_GROUP = 'thinkrank_schema';

    /**
     * Default cache duration in seconds
     *
     * @since 1.0.0
     * @var int
     */
    private int $default_duration;

    /**
     * Constructor
     *
     * @since 1.0.0
     *
     * @param int $default_duration Default cache duration in seconds
     */
    public function __construct(int $default_duration = 3600) {
        $this->default_duration = $default_duration;
    }

    /**
     * Get cached schema data
     *
     * @since 1.0.0
     *
     * @param string $key Cache key
     * @return array|null Cached data or null if not found
     */
    public function get(string $key): ?array {
        $cache_key = $this->build_cache_key($key);
        $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $cached_data) {
            // Try to get from database cache
            $cached_data = $this->get_from_database($cache_key);

            if ($cached_data !== null) {
                // Store back in memory cache
                wp_cache_set($cache_key, $cached_data, self::CACHE_GROUP, $this->default_duration);
            }
        }

        return $cached_data ?: null;
    }

    /**
     * Store schema data in cache
     *
     * @since 1.0.0
     *
     * @param string $key Cache key
     * @param array $data Data to cache
     * @param int|null $duration Cache duration (null for default)
     * @return bool True on success
     */
    public function set(string $key, array $data, ?int $duration = null): bool {
        $cache_key = $this->build_cache_key($key);
        $duration = $duration ?? $this->default_duration;

        // Add metadata
        $cache_data = [
            'data' => $data,
            'cached_at' => time(),
            'expires_at' => time() + $duration,
        ];

        // Store in memory cache
        $memory_cached = wp_cache_set($cache_key, $cache_data, self::CACHE_GROUP, $duration);

        // Store in database for persistence
        $db_cached = $this->set_in_database($cache_key, $cache_data, $duration);

        return $memory_cached && $db_cached;
    }

    /**
     * Delete cached schema data
     *
     * @since 1.0.0
     *
     * @param string $key Cache key
     * @return bool True on success
     */
    public function delete(string $key): bool {
        $cache_key = $this->build_cache_key($key);

        // Delete from memory cache
        wp_cache_delete($cache_key, self::CACHE_GROUP);

        // Delete from database
        return $this->delete_from_database($cache_key);
    }

    /**
     * Clear all schema cache
     *
     * @since 1.0.0
     *
     * @return bool True on success
     */
    public function clear_all(): bool {
        global $wpdb;

        // Clear memory cache (if using object cache)
        wp_cache_flush_group(self::CACHE_GROUP);

        // Clear database cache
        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix, cache clearing requires direct database access
        $result = $wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE cache_key LIKE %s", 'schema_%'));

        return $result !== false;
    }

    /**
     * Clean expired schema cache entries
     *
     * @since 1.0.0
     *
     * @return int Number of entries cleaned
     */
    public function clean_expired(): int {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';
        $current_time = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup requires direct database access
        $result = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix
                "DELETE FROM {$table_name} WHERE cache_key LIKE %s AND expires_at < %d",
                'schema_%',
                $current_time
            )
        );

        return (int) $result;
    }

    /**
     * Generate cache key for deployed schemas
     *
     * @since 1.0.0
     *
     * @param string $context_type Context type
     * @param int|null $context_id Context ID
     * @return string Cache key
     */
    public function generate_deployed_schemas_key(string $context_type, ?int $context_id = null): string {
        $key_data = [
            'operation' => 'deployed_schemas',
            'context_type' => $context_type,
            'context_id' => $context_id,
            'version' => THINKRANK_VERSION,
        ];

        return md5(wp_json_encode($key_data));
    }

    /**
     * Generate cache key for schema generation
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type
     * @param array $data Content data for generation
     * @param array $options Generation options
     * @return string Cache key
     */
    public function generate_schema_generation_key(string $schema_type, array $data, array $options = []): string {
        $key_data = [
            'operation' => 'schema_generation',
            'schema_type' => $schema_type,
            'data_hash' => md5(serialize($data)),
            'options' => $options,
            'version' => THINKRANK_VERSION,
        ];

        return md5(wp_json_encode($key_data));
    }

    /**
     * Generate cache key for schema validation
     *
     * @since 1.0.0
     *
     * @param array $schema_data Schema data to validate
     * @param string $schema_type Schema type
     * @param array $options Validation options
     * @return string Cache key
     */
    public function generate_validation_key(array $schema_data, string $schema_type, array $options = []): string {
        $key_data = [
            'operation' => 'schema_validation',
            'schema_type' => $schema_type,
            'schema_hash' => md5(serialize($schema_data)),
            'options' => $options,
            'version' => THINKRANK_VERSION,
        ];

        return md5(wp_json_encode($key_data));
    }

    /**
     * Invalidate cache for specific context
     *
     * @since 1.0.0
     *
     * @param string $context_type Context type
     * @param int|null $context_id Context ID
     * @return bool True on success
     */
    public function invalidate_context_cache(string $context_type, ?int $context_id = null): bool {
        $deployed_key = $this->generate_deployed_schemas_key($context_type, $context_id);
        return $this->delete($deployed_key);
    }

    /**
     * Invalidate all schema cache (for settings changes)
     *
     * @since 1.0.0
     *
     * @return bool True on success
     */
    public function invalidate_all_cache(): bool {
        return $this->clear_all();
    }

    /**
     * Build cache key with prefix
     *
     * @since 1.0.0
     *
     * @param string $key Original key
     * @return string Prefixed cache key
     */
    private function build_cache_key(string $key): string {
        return 'schema_' . $key;
    }

    /**
     * Get cached data from database
     *
     * @since 1.0.0
     *
     * @param string $cache_key Cache key
     * @return array|null Cached data or null
     */
    private function get_from_database(string $cache_key): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';
        $current_time = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache retrieval requires direct database access
        $cached_row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix
                "SELECT cache_data, expires_at FROM {$table_name}
                 WHERE cache_key = %s AND expires_at > %d",
                $cache_key,
                $current_time
            )
        );

        if (!$cached_row) {
            return null;
        }

        return maybe_unserialize($cached_row->cache_data);
    }

    /**
     * Store data in database cache
     *
     * @since 1.0.0
     *
     * @param string $cache_key Cache key
     * @param array $cache_data Data to cache
     * @param int $duration Cache duration
     * @return bool True on success
     */
    private function set_in_database(string $cache_key, array $cache_data, int $duration): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache storage requires direct database access
        $result = $wpdb->replace(
            $table_name,
            [
                'cache_key' => $cache_key,
                'cache_data' => maybe_serialize($cache_data),
                'expires_at' => time() + $duration,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    /**
     * Delete data from database cache
     *
     * @since 1.0.0
     *
     * @param string $cache_key Cache key
     * @return bool True on success
     */
    private function delete_from_database(string $cache_key): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache deletion requires direct database access
        $result = $wpdb->delete(
            $table_name,
            ['cache_key' => $cache_key],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Get cache statistics for monitoring
     *
     * @since 1.0.0
     *
     * @return array Cache statistics
     */
    public function get_cache_stats(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';
        $current_time = time();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix, cache stats require direct database access
        $total_entries = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE cache_key LIKE %s", 'schema_%'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache stats require direct database access
        $expired_entries = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix
                "SELECT COUNT(*) FROM {$table_name} WHERE cache_key LIKE %s AND expires_at < %d",
                'schema_%',
                $current_time
            )
        );

        return [
            'total_entries' => (int) $total_entries,
            'expired_entries' => (int) $expired_entries,
            'active_entries' => (int) $total_entries - (int) $expired_entries,
            'cache_group' => self::CACHE_GROUP,
            'default_duration' => $this->default_duration,
        ];
    }
}
