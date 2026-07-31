<?php
/**
 * CSRF Protection Trait
 *
 * Provides Cross-Site Request Forgery (CSRF) protection for API endpoints
 * by implementing WordPress nonce verification. This trait ensures that all
 * state-changing operations require valid nonce tokens to prevent unauthorized
 * requests from malicious websites.
 *
 * @package ThinkRank
 * @subpackage API\Traits
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API\Traits;

use WP_REST_Request;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSRF Protection Trait
 *
 * Implements WordPress nonce-based CSRF protection for REST API endpoints.
 * This trait provides methods to verify request authenticity and prevent
 * cross-site request forgery attacks.
 *
 * @since 1.0.0
 */
trait CSRF_Protection {

    /**
     * Verify request nonce for CSRF protection
     *
     * Checks for valid WordPress nonce in either the X-WP-Nonce header
     * (preferred for REST API) or as a request parameter fallback.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Whether nonce is valid
     */
    private function verify_request_nonce(WP_REST_Request $request): bool {
        // Get nonce from header (preferred method for REST API)
        $nonce = $request->get_header('X-WP-Nonce');

        // Fallback to parameter if header not present
        if (!$nonce) {
            $nonce = $request->get_param('_wpnonce');
        }

        // Verify nonce against WordPress REST API nonce action
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        return true;
    }

    /**
     * Check CSRF permissions for state-changing operations
     *
     * Combines user capability checks with CSRF protection to ensure
     * both authorization and request authenticity.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error Permission status or error
     */
    public function check_csrf_permissions(WP_REST_Request $request) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('You must be logged in to perform this action.', 'thinkrank'),
                ['status' => 401]
            );
        }

        // Check user capability
        if (!current_user_can('edit_posts')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to perform this action.', 'thinkrank'),
                ['status' => 403]
            );
        }

        // Verify CSRF protection
        if (!$this->verify_request_nonce($request)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid security token. Please refresh the page and try again.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check CSRF permissions for management operations
     *
     * Higher privilege check for operations that require content management
     * capabilities, such as publishing or managing site-wide settings.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error Permission status or error
     */
    public function check_manage_csrf_permissions(WP_REST_Request $request) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('You must be logged in to perform this action.', 'thinkrank'),
                ['status' => 401]
            );
        }

        // Check higher privilege for management operations
        if (!current_user_can('publish_posts')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to manage this resource.', 'thinkrank'),
                ['status' => 403]
            );
        }

        // Verify CSRF protection
        if (!$this->verify_request_nonce($request)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid security token. Please refresh the page and try again.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check read-only permissions (no CSRF required)
     *
     * For GET operations that don't change state, CSRF protection
     * is not required, but basic authentication is still needed.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error Permission status or error
     */
    public function check_read_permissions(WP_REST_Request $request) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('You must be logged in to access this resource.', 'thinkrank'),
                ['status' => 401]
            );
        }

        // Basic read capability
        if (!current_user_can('read')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this resource.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }
}
