<?php
/**
 * Rate Limiter Trait
 *
 * Provides basic rate limiting functionality for API endpoints
 * to prevent abuse and DoS attacks.
 *
 * @package ThinkRank
 * @subpackage API\Traits
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API\Traits;

use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rate Limiter Trait
 *
 * Simple rate limiting implementation using WordPress transients
 * for API endpoint protection.
 *
 * @since 1.0.0
 */
trait Rate_Limiter {

    /**
     * Rate limit per minute
     *
     * @since 1.0.0
     * @var int
     */
    private int $rate_limit_per_minute = 60;

    /**
     * Rate limit per hour
     *
     * @since 1.0.0
     * @var int
     */
    private int $rate_limit_per_hour = 1000;

    /**
     * Check if request is within rate limits
     *
     * @since 1.0.0
     *
     * @param string $endpoint_name Endpoint identifier
     * @param int    $user_id       User ID (0 for anonymous)
     * @return true|WP_Error True if within limits, WP_Error if exceeded
     */
    protected function check_rate_limit(string $endpoint_name, int $user_id = 0): bool|WP_Error {
        // Get user identifier (IP for anonymous, user ID for authenticated)
        $identifier = $user_id > 0 ? "user_{$user_id}" : $this->get_client_ip();
        
        // Check minute-based rate limit
        $minute_key = "thinkrank_rate_limit_{$endpoint_name}_{$identifier}_" . floor(time() / 60);
        $minute_attempts = get_transient($minute_key) ?: 0;
        
        if ($minute_attempts >= $this->rate_limit_per_minute) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests per minute. Please slow down.',
                ['status' => 429]
            );
        }
        
        // Check hour-based rate limit
        $hour_key = "thinkrank_rate_limit_{$endpoint_name}_{$identifier}_" . floor(time() / 3600);
        $hour_attempts = get_transient($hour_key) ?: 0;
        
        if ($hour_attempts >= $this->rate_limit_per_hour) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests per hour. Please try again later.',
                ['status' => 429]
            );
        }
        
        // Increment counters
        set_transient($minute_key, $minute_attempts + 1, 60);
        set_transient($hour_key, $hour_attempts + 1, 3600);
        
        return true;
    }

    /**
     * Get client IP address
     *
     * @since 1.0.0
     *
     * @return string Client IP address
     */
    private function get_client_ip(): string {
        // Forwarded headers are client-controlled: trusting them by default
        // lets a caller mint a fresh rate-limit bucket per request. Only
        // consult them when the site opts in because a trusted proxy/CDN
        // sits in front and REMOTE_ADDR is the proxy, not the client.
        $trusted_headers = apply_filters('thinkrank_trusted_ip_headers', []);

        foreach ((array) $trusted_headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));

            // X-Forwarded-For can contain a comma-separated chain.
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    }

    /**
     * Get rate limit status for a user/endpoint
     *
     * @since 1.0.0
     *
     * @param string $endpoint_name Endpoint identifier
     * @param int    $user_id       User ID (0 for anonymous)
     * @return array Rate limit status
     */
    protected function get_rate_limit_status(string $endpoint_name, int $user_id = 0): array {
        $identifier = $user_id > 0 ? "user_{$user_id}" : $this->get_client_ip();
        
        $minute_key = "thinkrank_rate_limit_{$endpoint_name}_{$identifier}_" . floor(time() / 60);
        $hour_key = "thinkrank_rate_limit_{$endpoint_name}_{$identifier}_" . floor(time() / 3600);
        
        $minute_attempts = get_transient($minute_key) ?: 0;
        $hour_attempts = get_transient($hour_key) ?: 0;
        
        return [
            'minute_attempts' => $minute_attempts,
            'minute_limit' => $this->rate_limit_per_minute,
            'minute_remaining' => max(0, $this->rate_limit_per_minute - $minute_attempts),
            'hour_attempts' => $hour_attempts,
            'hour_limit' => $this->rate_limit_per_hour,
            'hour_remaining' => max(0, $this->rate_limit_per_hour - $hour_attempts),
            'reset_time' => (floor(time() / 60) + 1) * 60 // Next minute
        ];
    }
}
