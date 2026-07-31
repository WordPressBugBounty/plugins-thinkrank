<?php

/**
 * Image SEO API Endpoints Class
 *
 * REST API endpoints for image SEO management.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Image_SEO_Manager;
use ThinkRank\API\Traits\CSRF_Protection;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Image SEO API Endpoints Class
 *
 * Provides REST API endpoints for image SEO settings management.
 *
 * @since 1.0.0
 */
class Image_SEO_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * Image SEO Manager instance
     *
     * @since 1.0.0
     * @var Image_SEO_Manager
     */
    private Image_SEO_Manager $image_manager;

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
    protected $rest_base = 'image-seo';

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        if (!class_exists('ThinkRank\\SEO\\Image_SEO_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-image-seo-manager.php';
        }

        $this->image_manager = new Image_SEO_Manager();
    }

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
                    'permission_callback' => [$this, 'check_permissions']
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => $this->get_settings_args()
                ]
            ]
        );

        // Media Library alt-text coverage stats
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/media-alt/stats',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_media_alt_stats'],
                    'permission_callback' => [$this, 'check_permissions']
                ]
            ]
        );

        // Bulk-fill alt text into the Media Library (batched)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/media-alt/run',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'run_media_alt_fill'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args' => [
                        'offset' => [
                            'type' => 'integer',
                            'required' => false,
                            'default' => 0,
                            'sanitize_callback' => 'absint'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'required' => false,
                            'default' => 50,
                            'sanitize_callback' => 'absint'
                        ],
                        'overwrite' => [
                            'type' => 'boolean',
                            'required' => false,
                            'default' => false,
                            'sanitize_callback' => 'rest_sanitize_boolean'
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Check if user has required permissions
     *
     * @since 1.0.0
     * @return bool True if authorized, false otherwise
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_image_seo');
    }

    /**
     * Get image SEO settings
     *
     * @since 1.0.0
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response|WP_Error API response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = $this->image_manager->get_settings('site');
        return new WP_REST_Response($settings, 200);
    }

    /**
     * Update image SEO settings
     *
     * @since 1.0.0
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response|WP_Error API response
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        // Whitelist to known schema keys only, so framework params (_wpnonce,
        // _locale, …) and any arbitrary extra fields are never persisted as settings.
        $allowed_keys = array_keys($this->image_manager->get_settings_schema('site'));
        $settings = array_intersect_key($request->get_params(), array_flip($allowed_keys));

        $success = $this->image_manager->save_settings('site', 0, $settings);

        if ($success) {
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Settings updated successfully', 'thinkrank')
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => __('Failed to update settings', 'thinkrank')
        ], 500);
    }

    /**
     * Get Media Library alt-text coverage stats
     *
     * @since 1.19.1
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response API response
     */
    public function get_media_alt_stats(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response($this->image_manager->get_media_alt_stats(), 200);
    }

    /**
     * Run one batch of the Media Library alt-text bulk fill
     *
     * @since 1.19.1
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response API response
     */
    public function run_media_alt_fill(WP_REST_Request $request): WP_REST_Response {
        $result = $this->image_manager->bulk_fill_missing_alt([
            'offset'    => (int) $request->get_param('offset'),
            'limit'     => (int) $request->get_param('limit'),
            'overwrite' => (bool) $request->get_param('overwrite'),
        ]);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Get settings schema arguments
     *
     * @since 1.0.0
     * @return array API arguments array
     */
    private function get_settings_args(): array {
        $schema = $this->image_manager->get_settings_schema('site');
        $args = [];

        foreach ($schema as $key => $config) {
            $args[$key] = [
                'type' => $config['type'],
                'required' => false,
                'sanitize_callback' => $config['type'] === 'boolean' ? 'rest_sanitize_boolean' : 'sanitize_text_field'
            ];
        }

        return $args;
    }
}
