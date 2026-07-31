<?php

/**
 * Author Archives API Endpoint Class
 *
 * REST API endpoints for author archives management
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\API\Traits\CSRF_Protection;
use ThinkRank\Core\Settings;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load CSRF Protection trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-csrf-protection.php';

/**
 * Author Archives API Endpoint Class
 *
 * @since 1.0.0
 */
class Author_Archives_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * API namespace
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @var string
     */
    protected $rest_base = 'author-archives';

    /**
     * Register API routes
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => [
                        'settings' => [
                            'required' => true,
                            'type' => 'object',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Get author archives settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    /**
     * Get author archives settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_settings(WP_REST_Request $request) {
        try {
            $settings = Settings::instance();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'enabled' => $settings->get('author_archives_enabled', true),
                    'show_in_search_results' => $settings->get('author_archives_index', true),
                    'show_empty_archives' => $settings->get('author_archives_show_empty', false),
                    'title' => $settings->get('author_archives_title', '%author_name% – %site_title% %page%'),
                    'meta_description' => $settings->get('author_archives_meta_desc', 'Articles written by %author_name% on %site_title%')
                ],
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'retrieval_failed',
                'Failed to retrieve settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Update author archives settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function update_settings(WP_REST_Request $request) {
        try {
            $params = $request->get_param('settings');

            if (!is_array($params)) {
                return new WP_Error(
                    'invalid_params',
                    'Invalid settings format',
                    ['status' => 400]
                );
            }

            $settings = Settings::instance();
            $success = true;

            // Map and save settings
            if (isset($params['enabled'])) {
                $success = $settings->set('author_archives_enabled', (bool) $params['enabled']);
            }
            if (isset($params['show_in_search_results'])) {
                $success = $settings->set('author_archives_index', (bool) $params['show_in_search_results']);
            }
            if (isset($params['show_empty_archives'])) {
                $success = $settings->set('author_archives_show_empty', (bool) $params['show_empty_archives']);
            }
            if (isset($params['title'])) {
                $success = $settings->set('author_archives_title', sanitize_text_field($params['title']));
            }
            if (isset($params['meta_description'])) {
                $success = $settings->set('author_archives_meta_desc', sanitize_text_field($params['meta_description']));
            }

            if (!$success) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update settings',
                    ['status' => 500]
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Settings saved successfully',
                'data' => $params // Return back what was sent for UI consistency
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'update_failed',
                'Failed to update settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Check permissions
     *
     * @return bool|WP_Error
     */
    public function check_permissions() {
        if (!\ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_author_archives')) {
            return new WP_Error(
                'rest_forbidden',
                'You do not have permission to access this endpoint.',
                ['status' => 403]
            );
        }
        return true;
    }
}
