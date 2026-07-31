<?php
/**
 * API Cache Trait
 *
 * Provides consistent caching functionality for API endpoints to improve performance.
 * Implements WordPress transient caching with automatic cache key generation,
 * expiration handling, and cache invalidation patterns.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API\Traits;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Cache Trait
 *
 * Provides caching functionality for API endpoints using WordPress transients.
 * Includes automatic cache key generation, TTL management, and cache invalidation.
 *
 * @since 1.0.0
 */
trait API_Cache {

    /**
     * Default cache duration in seconds (15 minutes)
     *
     * @since 1.0.0
     * @var int
     */
    private int $default_cache_duration = 900;

    /**
     * Cache key prefix for this endpoint
     *
     * @since 1.0.0
     * @var string
     */
    private string $cache_prefix = 'thinkrank_api_';

    /**
     * Get cached response
     *
     * @since 1.0.0
     *
     * @param string $endpoint Endpoint identifier
     * @param array $params Request parameters for cache key generation
     * @param int|null $user_id User ID for user-specific caching
     * @return array|null Cached data or null if not found/expired
     */
    protected function get_cached_response(string $endpoint, array $params = [], ?int $user_id = null): ?array {
        $cache_key = $this->generate_cache_key($endpoint, $params, $user_id);
        
        $cached_data = get_transient($cache_key);
        
        if (false === $cached_data) {
            return null;
        }

        // Verify cache structure and expiration
        if (!is_array($cached_data) || !isset($cached_data['data'], $cached_data['cached_at'])) {
            delete_transient($cache_key);
            return null;
        }

        return $cached_data;
    }

    /**
     * Set cached response
     *
     * @since 1.0.0
     *
     * @param string $endpoint Endpoint identifier
     * @param array $data Data to cache
     * @param array $params Request parameters for cache key generation
     * @param int|null $duration Cache duration in seconds (null for default)
     * @param int|null $user_id User ID for user-specific caching
     * @return bool True on success
     */
    protected function set_cached_response(
        string $endpoint, 
        array $data, 
        array $params = [], 
        ?int $duration = null, 
        ?int $user_id = null
    ): bool {
        $cache_key = $this->generate_cache_key($endpoint, $params, $user_id);
        $duration = $duration ?? $this->default_cache_duration;

        $current_time = time();
        $cache_data = [
            'data' => $data,
            'cached_at' => current_time('mysql'),
            'expires_at' => gmdate('Y-m-d H:i:s', $current_time + $duration),
            'cache_key' => $cache_key,
            'endpoint' => $endpoint,
            'user_id' => $user_id
        ];

        return set_transient($cache_key, $cache_data, $duration);
    }

    /**
     * Delete cached response
     *
     * @since 1.0.0
     *
     * @param string $endpoint Endpoint identifier
     * @param array $params Request parameters for cache key generation
     * @param int|null $user_id User ID for user-specific caching
     * @return bool True on success
     */
    protected function delete_cached_response(string $endpoint, array $params = [], ?int $user_id = null): bool {
        $cache_key = $this->generate_cache_key($endpoint, $params, $user_id);
        return delete_transient($cache_key);
    }

    /**
     * Invalidate cache by pattern
     *
     * @since 1.0.0
     *
     * @param string $pattern Cache key pattern (supports wildcards)
     * @return int Number of cache entries deleted
     */
    protected function invalidate_cache_pattern(string $pattern): int {
        global $wpdb;

        $deleted = 0;
        $pattern = str_replace('*', '%', $pattern);
        
        // Get matching transient keys
        $transient_pattern = "_transient_{$pattern}";
        $cache_prefix_pattern = "_transient_{$this->cache_prefix}%";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache invalidation requires direct database access
        $transients = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- wpdb->options is WordPress core table
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND option_name LIKE %s",
                $transient_pattern,
                $cache_prefix_pattern
            )
        );

        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient);
            if (delete_transient($key)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get cache statistics
     *
     * @since 1.0.0
     *
     * @return array Cache statistics
     */
    protected function get_cache_stats(): array {
        global $wpdb;

        // Count total cache entries for this endpoint
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache statistics require direct database access
        $total_entries = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                "_transient_{$this->cache_prefix}%"
            )
        );

        // Get cache size (approximate)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache size calculation requires direct database access
        $cache_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                "_transient_{$this->cache_prefix}%"
            )
        );

        return [
            'total_entries' => (int) $total_entries,
            'cache_size_bytes' => (int) $cache_size,
            'cache_size_mb' => round((int) $cache_size / 1024 / 1024, 2),
            'default_duration' => $this->default_cache_duration,
            'cache_prefix' => $this->cache_prefix
        ];
    }

    /**
     * Generate cache key
     *
     * @since 1.0.0
     *
     * @param string $endpoint Endpoint identifier
     * @param array $params Request parameters
     * @param int|null $user_id User ID for user-specific caching
     * @return string Generated cache key
     */
    private function generate_cache_key(string $endpoint, array $params = [], ?int $user_id = null): string {
        // Sort parameters for consistent key generation
        ksort($params);
        
        // Build key components
        $key_parts = [
            $this->cache_prefix,
            $endpoint,
            md5(serialize($params))
        ];

        if ($user_id !== null) {
            $key_parts[] = "user_{$user_id}";
        }

        return implode('_', $key_parts);
    }

    /**
     * Set cache duration for this endpoint
     *
     * @since 1.0.0
     *
     * @param int $duration Cache duration in seconds
     * @return void
     */
    protected function set_cache_duration(int $duration): void {
        $this->default_cache_duration = $duration;
    }

    /**
     * Set cache prefix for this endpoint
     *
     * @since 1.0.0
     *
     * @param string $prefix Cache key prefix
     * @return void
     */
    protected function set_cache_prefix(string $prefix): void {
        $this->cache_prefix = rtrim($prefix, '_') . '_';
    }

    /**
     * Check if caching is enabled
     *
     * @since 1.0.0
     *
     * @return bool True if caching is enabled
     */
    protected function is_caching_enabled(): bool {
        // Allow disabling cache via constant or filter
        if (defined('THINKRANK_DISABLE_API_CACHE') && THINKRANK_DISABLE_API_CACHE) {
            return false;
        }

        return apply_filters('thinkrank_api_cache_enabled', true);
    }

    /**
     * Wrap endpoint response with caching
     *
     * @since 1.0.0
     *
     * @param string $endpoint Endpoint identifier
     * @param callable $callback Callback to generate response
     * @param array $params Request parameters
     * @param int|null $duration Cache duration
     * @param int|null $user_id User ID
     * @return array Response data with cache metadata
     */
    protected function cached_response(
        string $endpoint,
        callable $callback,
        array $params = [],
        ?int $duration = null,
        ?int $user_id = null
    ): array {
        if (!$this->is_caching_enabled()) {
            $response = call_user_func($callback);
            return array_merge($response, ['cached' => false]);
        }

        // Try to get cached response
        $cached = $this->get_cached_response($endpoint, $params, $user_id);
        
        if ($cached !== null) {
            return array_merge($cached['data'], [
                'cached' => true,
                'cached_at' => $cached['cached_at']
            ]);
        }

        // Generate fresh response
        $response = call_user_func($callback);

        // Cache the response — but never cache a failure payload. Caching
        // `success => false` / error responses pins a transient upstream
        // failure (e.g. a momentary Google API error) for the full TTL, which
        // shows up to users as screens that stay empty long after the
        // underlying issue resolved.
        if ($this->is_cacheable_response($response)) {
            $this->set_cached_response($endpoint, $response, $params, $duration, $user_id);
        }

        return array_merge($response, ['cached' => false]);
    }

    /**
     * Whether a response payload represents a success that is safe to cache.
     *
     * @since 1.0.0
     *
     * @param array $response Response payload from the endpoint callback
     * @return bool True when the payload should be cached
     */
    private function is_cacheable_response(array $response): bool {
        if (array_key_exists('success', $response) && $response['success'] === false) {
            return false;
        }

        if (!empty($response['error'])) {
            return false;
        }

        return true;
    }
}
