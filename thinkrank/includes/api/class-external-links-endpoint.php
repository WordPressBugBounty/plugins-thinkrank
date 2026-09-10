<?php

/**
 * External Links API Endpoints Class
 *
 * REST API endpoints for the outbound-link rewriting settings (#659).
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.5.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\External_Links_Manager;
use ThinkRank\API\Traits\CSRF_Protection;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * External Links API Endpoints Class
 *
 * @since 2.5.0
 */
class External_Links_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * External Links Manager instance
     *
     * @since 2.5.0
     * @var External_Links_Manager
     */
    private External_Links_Manager $manager;

    /**
     * API namespace
     *
     * @since 2.5.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @since 2.5.0
     * @var string
     */
    protected $rest_base = 'external-links';

    /**
     * Constructor
     *
     * @since 2.5.0
     */
    public function __construct() {
        if (!class_exists('ThinkRank\\SEO\\External_Links_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-external-links-manager.php';
        }

        $this->manager = new External_Links_Manager();
    }

    /**
     * Register API routes
     *
     * @since 2.5.0
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => $this->get_settings_args(),
                ],
            ]
        );
    }

    /**
     * Check if user has required permissions
     *
     * @since 2.5.0
     * @return bool
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_external_links');
    }

    /**
     * Read the site-wide external link settings
     *
     * @since 2.5.0
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = $this->manager->get_settings('site');

        // The list is stored as hosts; the UI edits it as lines of text.
        $settings['external_link_exceptions'] = array_values((array) ($settings['external_link_exceptions'] ?? []));

        return new WP_REST_Response($settings, 200);
    }

    /**
     * Persist the site-wide external link settings
     *
     * @since 2.5.0
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        $allowed_keys = array_keys($this->manager->get_settings_schema('site'));
        $settings     = array_intersect_key($request->get_params(), array_flip($allowed_keys));

        // Normalize on the way in, so a domain typed as a full URL, with
        // "www.", or with a trailing path still matches at render time —
        // and the stored value is the same shape the matcher compares.
        if (isset($settings['external_link_exceptions'])) {
            $settings['external_link_exceptions'] = $this->manager->normalize_exceptions(
                $settings['external_link_exceptions']
            );
        }

        $success = $this->manager->save_settings('site', 0, $settings);

        if ($success) {
            return new WP_REST_Response([
                'success'  => true,
                'message'  => __('Settings updated successfully', 'thinkrank'),
                'settings' => $this->manager->get_settings('site'),
            ], 200);
        }

        $error = $this->manager->get_last_save_error();

        return new WP_REST_Response([
            'success' => false,
            'message' => $error !== '' ? $error : __('Failed to update settings', 'thinkrank'),
        ], 500);
    }

    /**
     * REST args derived from the settings schema
     *
     * @since 2.5.0
     * @return array
     */
    private function get_settings_args(): array {
        return [
            'nofollow_external' => [
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'open_external_in_new_tab' => [
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            // No sanitize_callback on the array: core falls back to
            // rest_parse_request_arg, which sanitizes against the shape below
            // instead of flattening the list to the string "Array".
            'external_link_exceptions' => [
                'type'     => 'array',
                'required' => false,
                'items'    => ['type' => 'string'],
            ],
        ];
    }
}
