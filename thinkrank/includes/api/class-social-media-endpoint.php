<?php
/**
 * Social Media API Endpoints Class
 *
 * REST API endpoints for social media meta management including Open Graph,
 * Twitter Cards, social media preview, and image optimization with proper
 * authentication and comprehensive error handling.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Social_Meta_Manager;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Social Media API Endpoints Class
 *
 * Provides REST API endpoints for social media operations including
 * Open Graph generation, Twitter Cards, social media preview, and
 * image optimization with proper authentication and validation.
 *
 * @since 1.0.0
 */
class Social_Media_Endpoint extends WP_REST_Controller {

    /**
     * Social Meta Manager instance
     *
     * @since 1.0.0
     * @var Social_Meta_Manager
     */
    private Social_Meta_Manager $social_manager;

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
    protected $rest_base = 'social-media';

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->social_manager = new Social_Meta_Manager();
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Social media settings management
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

        // Social media settings validation
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/validate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'validate_settings'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_settings_args()
                ]
            ]
        );

        // Generate social media preview
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/preview',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_preview'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_preview_args()
                ]
            ]
        );

        // Optimize image for social platforms
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/optimize-image',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'optimize_image'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_optimize_image_args()
                ]
            ]
        );

        // Get social meta for context
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<context_type>[a-zA-Z]+)/(?P<context_id>\d+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_social_meta'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_context_args()
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'save_social_meta'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => array_merge($this->get_context_args(), $this->get_social_meta_args())
                ]
            ]
        );

        // Generate Open Graph tags
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/generate-og',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_og_tags'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_generate_tags_args()
                ]
            ]
        );

        // Generate Twitter Card tags
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/generate-twitter',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_twitter_tags'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_generate_tags_args()
                ]
            ]
        );
    }

    /**
     * Get social media settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id');

            // Get settings from Social Meta Manager
            $settings = $this->social_manager->get_settings($context_type, $context_id);

            // Get settings schema for validation
            $schema = $this->social_manager->get_settings_schema($context_type);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'schema' => $schema,
                    'context_type' => $context_type,
                    'context_id' => $context_id
                ],
                'message' => 'Social media settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update social media settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_settings(WP_REST_Request $request) {
        try {
            $settings = $request->get_param('settings');
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id');
            $validation_context = $request->get_param('validation_context') ?? 'all';

            if (empty($settings)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Settings data is required'
                ], 400);
            }

            // Drop unrecognized keys so arbitrary client-supplied keys aren't
            // persisted (storage bloat / settings drift). The known set is the
            // context's default settings, exposed through a filter for add-ons.
            $known = array_keys($this->social_manager->get_default_settings($context_type));
            $known = apply_filters('thinkrank_social_known_setting_keys', $known, $context_type);
            $settings = array_intersect_key($settings, array_flip($known));
            if (empty($settings)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'No recognized social settings were provided'
                ], 400);
            }

            // Validate settings with context
            $validation = $this->social_manager->validate_settings($settings, $validation_context);
            if (!$validation['valid']) {
                return new WP_Error(
                    'validation_failed',
                    'Settings validation failed',
                    [
                        'status' => 400,
                        'validation_errors' => $validation['errors'],
                        'validation_warnings' => $validation['warnings']
                    ]
                );
            }

            // Save settings
            $result = $this->social_manager->save_settings($context_type, $context_id, $settings);

            if ($result) {
                // Get updated settings
                $updated_settings = $this->social_manager->get_settings($context_type, $context_id);

                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'settings' => $updated_settings,
                        'validation' => $validation,
                        'context_type' => $context_type,
                        'context_id' => $context_id
                    ],
                    'message' => 'Social media settings updated successfully'
                ], 200);
            } else {
                throw new \Exception('Failed to save settings');
            }

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Settings update failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate social media settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function validate_settings(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $settings = $request->get_param('settings') ?? [];
            $context_type = $request->get_param('context_type') ?? 'site';
            $validation_context = $request->get_param('validation_context') ?? 'all';

            // Validate settings using the enhanced Social Meta Manager with tab-specific context
            $validation = $this->social_manager->validate_settings($settings, $validation_context);

            return new WP_REST_Response([
                'success' => true,
                'data' => $validation
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'validation_error',
                'Failed to validate social media settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Generate social media preview
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_preview(WP_REST_Request $request) {
        try {
            $data = $request->get_param('data') ?? [];
            $platform = $request->get_param('platform') ?? 'facebook';

            // Validate platform
            $supported_platforms = ['facebook', 'twitter', 'linkedin', 'pinterest'];
            if (!in_array($platform, $supported_platforms, true)) {
                return new WP_Error(
                    'invalid_platform',
                    'Unsupported platform for preview generation',
                    ['status' => 400]
                );
            }

            // Generate preview
            $preview_data = $this->social_manager->preview_social_post($data, $platform);

            return new WP_REST_Response([
                'success' => true,
                'data' => $preview_data,
                'message' => 'Social media preview generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'preview_failed',
                'Social media preview generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Optimize image for social platforms
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function optimize_image(WP_REST_Request $request) {
        try {
            $image_url = $request->get_param('image_url');
            $platform = $request->get_param('platform') ?? 'facebook';

            // Validate image URL
            if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                return new WP_Error(
                    'invalid_image_url',
                    'Invalid image URL provided',
                    ['status' => 400]
                );
            }

            // Optimize image
            $optimized_image = $this->social_manager->optimize_social_image($image_url, $platform);

            return new WP_REST_Response([
                'success' => true,
                'data' => $optimized_image,
                'message' => 'Image optimized successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'optimization_failed',
                'Image optimization failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get social meta for context
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_social_meta(WP_REST_Request $request) {
        try {
            $context_type = $request->get_param('context_type');
            $context_id = (int) $request->get_param('context_id');

            // Validate context
            if (!$this->validate_context($context_type, $context_id)) {
                return new WP_Error(
                    'invalid_context',
                    'Invalid context type or ID provided',
                    ['status' => 400]
                );
            }

            // Get social meta data
            $social_meta = $this->social_manager->get_output_data($context_type, $context_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => $social_meta,
                'message' => 'Social meta retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'retrieval_failed',
                'Social meta retrieval failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Save social meta for context
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function save_social_meta(WP_REST_Request $request) {
        try {
            $context_type = $request->get_param('context_type');
            $context_id = (int) $request->get_param('context_id');
            $social_data = $request->get_param('social_data') ?? [];

            // Validate context
            if (!$this->validate_context($context_type, $context_id)) {
                return new WP_Error(
                    'invalid_context',
                    'Invalid context type or ID provided',
                    ['status' => 400]
                );
            }

            // SECURITY: This route writes per-post social overrides, so require
            // object-level edit permission — the global thinkrank_social_media
            // capability alone would allow writing to any post (IDOR).
            if (!current_user_can('edit_post', $context_id)) {
                return new WP_Error(
                    'rest_forbidden',
                    'You are not allowed to edit this content.',
                    ['status' => 403]
                );
            }

            // Save social meta data (manager signature is
            // save_settings(context_type, context_id, settings)).
            $result = $this->social_manager->save_settings($context_type, $context_id, $social_data);

            if ($result) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => $result,
                    'message' => 'Social meta saved successfully'
                ], 200);
            } else {
                throw new \Exception('Failed to save social meta data');
            }

        } catch (\Throwable $e) {
            // Catch \Throwable (not just \Exception) so a future TypeError
            // degrades to a JSON error instead of a fatal.
            return new WP_Error(
                'save_failed',
                'Social meta save failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Generate Open Graph tags
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_og_tags(WP_REST_Request $request) {
        try {
            $data = $request->get_param('data') ?? [];
            $context = $request->get_param('context') ?? 'site';
            $platform = $request->get_param('platform') ?? 'facebook';

            // Generate Open Graph tags
            $og_tags = $this->social_manager->generate_og_tags($data, $context, $platform);

            return new WP_REST_Response([
                'success' => true,
                'data' => $og_tags,
                'message' => 'Open Graph tags generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'og_generation_failed',
                'Open Graph generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Generate Twitter Card tags
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_twitter_tags(WP_REST_Request $request) {
        try {
            $data = $request->get_param('data') ?? [];
            $context = $request->get_param('context') ?? 'site';

            // Generate Twitter Card tags
            $twitter_tags = $this->social_manager->generate_twitter_tags($data, $context);

            return new WP_REST_Response([
                'success' => true,
                'data' => $twitter_tags,
                'message' => 'Twitter Card tags generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'twitter_generation_failed',
                'Twitter Card generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Check read permissions
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_read_permissions(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Check manage permissions
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_social_media');
    }

    /**
     * Validate context type and ID
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return bool Validation status
     */
    private function validate_context(string $context_type, ?int $context_id): bool {
        $valid_types = ['site', 'post', 'page', 'product'];

        if (!in_array($context_type, $valid_types, true)) {
            return false;
        }

        if ($context_type !== 'site' && (!$context_id || $context_id <= 0)) {
            return false;
        }

        if ($context_id && !get_post($context_id)) {
            return false;
        }

        return true;
    }

    /**
     * Get arguments for preview endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_preview_args(): array {
        return [
            'data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Content data for preview generation'
            ],
            'platform' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['facebook', 'twitter', 'linkedin', 'pinterest'],
                'default' => 'facebook',
                'description' => 'Target platform for preview'
            ]
        ];
    }

    /**
     * Get arguments for image optimization endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_optimize_image_args(): array {
        return [
            'image_url' => [
                'required' => true,
                'type' => 'string',
                'format' => 'uri',
                'description' => 'Image URL to optimize'
            ],
            'platform' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['facebook', 'twitter', 'linkedin', 'pinterest'],
                'default' => 'facebook',
                'description' => 'Target platform for optimization'
            ]
        ];
    }

    /**
     * Get arguments for context endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_context_args(): array {
        return [
            'context_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['site', 'post', 'page', 'product'],
                'description' => 'Context type'
            ],
            'context_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Context ID'
            ]
        ];
    }

    /**
     * Get arguments for social meta save endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_social_meta_args(): array {
        return [
            'social_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Social media meta data to save'
            ]
        ];
    }

    /**
     * Get arguments for tag generation endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_generate_tags_args(): array {
        return [
            'data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Content data for tag generation'
            ],
            'context' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['site', 'post', 'page', 'product'],
                'default' => 'site',
                'description' => 'Context type'
            ],
            'platform' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['facebook', 'twitter', 'linkedin', 'pinterest'],
                'default' => 'facebook',
                'description' => 'Target platform (for Open Graph only)'
            ]
        ];
    }

    /**
     * Get arguments for settings endpoints
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Social media settings to save'
            ],
            'context_type' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['site', 'post', 'page', 'product'],
                'default' => 'site',
                'description' => 'Context type'
            ],
            'context_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Context ID (required for non-site contexts)'
            ],
            'validation_context' => [
                'required' => false,
                'type' => 'string',
                'enum' => ['all', 'open-graph', 'twitter-cards', 'platforms', 'preview'],
                'default' => 'all',
                'description' => 'Validation context for focused validation'
            ]
        ];
    }
}
