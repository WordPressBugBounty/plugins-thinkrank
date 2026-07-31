<?php
/**
 * Security Headers Manager
 *
 * Manages security headers for the ThinkRank plugin to prevent
 * XSS, clickjacking, and other security vulnerabilities.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security Headers Manager Class
 *
 * Implements basic security headers for admin pages and API endpoints
 * to enhance security posture.
 *
 * @since 1.0.0
 */
class Security_Headers {

    /**
     * Initialize security headers
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('admin_init', [$this, 'add_admin_security_headers']);
        add_action('rest_api_init', [$this, 'add_api_security_headers']);
    }

    /**
     * Add security headers for admin pages
     *
     * @since 1.0.0
     */
    public function add_admin_security_headers(): void {
        // Only add headers on ThinkRank admin pages
        if (!$this->is_thinkrank_admin_page()) {
            return;
        }

        // Prevent clickjacking
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // Basic CSP for admin pages
            $csp = $this->get_admin_csp_policy();
            header("Content-Security-Policy: {$csp}");
        }
    }

    /**
     * Add security headers for API endpoints
     *
     * @since 1.0.0
     */
    public function add_api_security_headers(): void {
        add_filter('rest_pre_serve_request', [$this, 'add_rest_security_headers'], 10, 4);
    }

    /**
     * Add security headers to REST API responses
     *
     * @since 1.0.0
     *
     * @param bool             $served  Whether the request has already been served
     * @param WP_HTTP_Response $result  Result to send to the client
     * @param WP_REST_Request  $request Request used to generate the response
     * @param WP_REST_Server   $server  Server instance
     * @return bool
     */
    public function add_rest_security_headers($served, $result, $request, $server): bool {
        // Only add headers to ThinkRank API endpoints
        if (!$this->is_thinkrank_api_request($request)) {
            return $served;
        }

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin');
            
            // CORS headers for API
            $this->add_cors_headers();
        }

        return $served;
    }

    /**
     * Check if current page is a ThinkRank admin page
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function is_thinkrank_admin_page(): bool {
        if (!is_admin()) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        // Check if it's a ThinkRank admin page
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page parameter for screen detection, not processing form data.
        return strpos($screen->id, 'thinkrank') !== false || 
               strpos($screen->base, 'thinkrank') !== false ||
               (isset($_GET['page']) && strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'thinkrank') !== false); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Check if request is to a ThinkRank API endpoint
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool
     */
    private function is_thinkrank_api_request($request): bool {
        $route = $request->get_route();
        return strpos($route, '/thinkrank/') === 0;
    }

    /**
     * Get Content Security Policy for admin pages
     *
     * @since 1.0.0
     *
     * @return string CSP policy string
     */
    private function get_admin_csp_policy(): string {
        $site_url = get_site_url();
        $admin_url = admin_url();
        
        $policies = [
            "default-src 'self'",
            // 'unsafe-eval' dropped — the React admin bundle doesn't need it. We
            // still allow 'unsafe-inline' because WordPress core emits unnonced
            // inline admin scripts; moving the SPA to nonces/hashes is tracked
            // separately and can't be done without breaking core admin output.
            "script-src 'self' 'unsafe-inline' {$site_url} {$admin_url}",
            "style-src 'self' 'unsafe-inline' {$site_url} {$admin_url}",
            "img-src 'self' data: {$site_url}",
            "font-src 'self' {$site_url}",
            "connect-src 'self' {$site_url} https://api.openai.com https://api.anthropic.com https://generativelanguage.googleapis.com",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'"
        ];

        return implode('; ', $policies);
    }

    /**
     * Add CORS headers for API endpoints
     *
     * @since 1.0.0
     */
    private function add_cors_headers(): void {
        $origin = get_site_url();
        
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // 24 hours
    }

    /**
     * Get security headers status
     *
     * @since 1.0.0
     *
     * @return array Security headers status
     */
    public function get_security_status(): array {
        return [
            'headers_enabled' => true,
            'csp_enabled' => true,
            'cors_configured' => true,
            'xss_protection' => true,
            'clickjacking_protection' => true,
            'content_type_protection' => true
        ];
    }
}
