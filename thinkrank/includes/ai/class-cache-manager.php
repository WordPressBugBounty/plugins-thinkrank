<?php
/**
 * AI Cache Manager
 * 
 * Handles caching of AI responses to reduce API costs
 * 
 * @package ThinkRank\AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Manager Class
 * 
 * Single Responsibility: Manage AI response caching
 * 
 * @since 1.0.0
 */
class Cache_Manager {
    
    /**
     * Cache group for AI responses
     */
    private const CACHE_GROUP = 'thinkrank_ai';
    
    /**
     * Default cache duration in seconds
     * 
     * @var int
     */
    private int $default_duration;
    
    /**
     * Constructor
     * 
     * @param int $default_duration Default cache duration
     */
    public function __construct(int $default_duration = 3600) {
        $this->default_duration = $default_duration;
    }
    
    /**
     * Get cached response
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
     * Store response in cache
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
     * Delete cached response
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
     * Clear all AI cache
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
        $result = $wpdb->query("DELETE FROM {$table_name}");
        
        return $result !== false;
    }
    
    /**
     * Clean expired cache entries
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
                "DELETE FROM {$table_name} WHERE expires_at < %d",
                $current_time
            )
        );
        
        return (int) $result;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function get_stats(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'thinkrank_ai_cache';
        $current_time = time();
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix, cache stats require direct database access
        $total_entries = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache stats require direct database access
        $expired_entries = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix
                "SELECT COUNT(*) FROM {$table_name} WHERE expires_at < %d",
                $current_time
            )
        );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache stats require direct database access
        $cache_size = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly constructed from controlled prefix
            "SELECT SUM(LENGTH(cache_data)) FROM {$table_name}"
        );
        
        return [
            'total_entries' => (int) $total_entries,
            'active_entries' => (int) $total_entries - (int) $expired_entries,
            'expired_entries' => (int) $expired_entries,
            'cache_size_bytes' => (int) $cache_size,
            'cache_size_mb' => round((int) $cache_size / 1024 / 1024, 2),
        ];
    }
    
    /**
     * Generate cache key for content
     * 
     * @param string $content Content to generate key for
     * @param array $options Additional options affecting the key
     * @return string Cache key
     */
    public function generate_content_key(string $content, array $options = []): string {
        $key_data = [
            'content_hash' => md5($content),
            'options' => $options,
            'version' => THINKRANK_VERSION,
        ];
        
        return md5(wp_json_encode($key_data));
    }
    
    /**
     * Build cache key with prefix
     * 
     * @param string $key Original key
     * @return string Prefixed cache key
     */
    private function build_cache_key(string $key): string {
        return 'ai_response_' . $key;
    }
    
    /**
     * Get cached data from database
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
        
        $cache_data = maybe_unserialize($cached_row->cache_data);
        
        return is_array($cache_data) ? $cache_data : null;
    }
    
    /**
     * Store data in database cache
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
}
