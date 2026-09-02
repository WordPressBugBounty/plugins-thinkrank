<?php
/**
 * Global Robot Meta API Endpoints Class
 *
 * REST API endpoints for global robots meta tags settings.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global Robot Meta API Endpoints Class
 *
 * @since 1.0.0
 */
class Global_Robot_Meta_Endpoint extends WP_REST_Controller {

    /**
     * API namespace
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'global-robot-meta';

    /**
     * Option name
     */
    private const OPTION_NAME = 'thinkrank_global_robot_meta_settings';

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_settings_args()
                ]
            ]
        );
    }

    /**
     * Get settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = get_option(self::OPTION_NAME, $this->get_default_settings());

        return new WP_REST_Response([
            'success' => true,
            'settings' => $settings,
            'message' => 'Settings retrieved successfully'
        ], 200);
    }

    /**
     * Update settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_settings(WP_REST_Request $request) {
        $settings = $request->get_param('settings');

        if (empty($settings) || !is_array($settings)) {
            return new WP_Error(
                'invalid_settings',
                'Settings must be provided as an array',
                ['status' => 400]
            );
        }

        // Merge into the existing option rather than replacing it, so sibling
        // keys written by other paths (e.g. the SEO importer's
        // noindex_date_archives / noindex_author_archives, consumed by the
        // frontend) survive a save (#134).
        $existing = get_option(self::OPTION_NAME, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $sanitized_settings = wp_parse_args($existing, $this->get_default_settings());

        // Only write the keys the caller actually sent. Writing all six on every
        // request meant a payload of {"noarchive": true} silently reset the
        // other five — and diverged from the MCP ability, which writes the same
        // option with an array_key_exists() merge (#560).
        $found = false;
        foreach (array_keys($this->get_default_settings()) as $key) {
            if (array_key_exists($key, $settings)) {
                $sanitized_settings[$key] = (bool) $settings[$key];
                $found = true;
            }
        }

        if (!$found) {
            return new WP_Error(
                'no_valid_settings',
                'No valid robots meta setting keys were provided.',
                ['status' => 400]
            );
        }

        update_option(self::OPTION_NAME, $sanitized_settings);

        return new WP_REST_Response([
            'success' => true,
            'settings' => $sanitized_settings,
            'message' => 'Settings saved successfully'
        ], 200);
    }

    /**
     * Get default settings
     *
     * @return array
     */
    private function get_default_settings(): array {
        return [
            'index' => true,
            'noindex' => false,
            'nofollow' => false,
            'noarchive' => false,
            'noimageindex' => false,
            'nosnippet' => false,
        ];
    }

    /**
     * Get settings args for validation
     *
     * @return array
     */
    private function get_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'properties' => [
                    'index' => ['type' => 'boolean'],
                    'noindex' => ['type' => 'boolean'],
                    'nofollow' => ['type' => 'boolean'],
                    'noarchive' => ['type' => 'boolean'],
                    'noimageindex' => ['type' => 'boolean'],
                    'nosnippet' => ['type' => 'boolean'],
                ]
            ]
        ];
    }

    /**
     * Check read permissions
     *
     * @return bool
     */
    public function check_read_permissions(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Check manage permissions
     *
     * @return bool
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_crawling');
    }
}
