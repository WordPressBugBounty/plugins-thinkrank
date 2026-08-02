<?php

/**
 * Global SEO API Endpoints Class
 *
 * REST API endpoints for managing global SEO settings across different WordPress post types.
 * Provides functionality to save and retrieve SEO settings including title formats,
 * meta descriptions, schema types, and article types for all registered post types.
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
 * Global SEO API Endpoints Class
 *
 * Provides REST API endpoints for managing global SEO settings for different post types
 * including title formats, meta descriptions, schema types, and article types.
 *
 * @since 1.0.0
 */
class Global_SEO_Endpoint extends WP_REST_Controller {

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
    protected $rest_base = 'global-seo';

    /**
     * WordPress option name for storing global SEO settings
     *
     * @since 1.0.0
     * @var string
     */
    private const OPTION_NAME = 'thinkrank_global_seo_settings';

    /**
     * Allowed values for enumerated settings fields. Shared with the MCP
     * `update-global-settings` ability so every write path validates identically.
     *
     * @since 1.20.1
     */
    public const ALLOWED_SCHEMA_TYPES  = ['Article', 'WebPage', 'Media', 'Product'];
    public const ALLOWED_ARTICLE_TYPES = ['', 'Article', 'NewsArticle', 'BlogPosting'];
    public const ALLOWED_MEDIA_TYPES   = ['', 'ImageObject', 'VideoObject'];
    public const ALLOWED_IMAGE_PREVIEW = ['none', 'standard', 'large'];

    /**
     * Default settings structure for post types
     *
     * @since 1.0.0
     * @var array
     */
    private const DEFAULT_SETTINGS = [
        'title' => '%title% %sep% %sitename%',
        'description' => '%excerpt%',
        'schema_type' => 'WebPage',
        'article_type' => '',
        'media_type' => '',
        'link_suggestions' => true,
        'robots_meta' => [
            'index' => true,
            'noindex' => false,
            'nofollow' => false,
            'noarchive' => false,
            'noimageindex' => false,
            'nosnippet' => false
        ],
        'robots_meta_enabled' => false,
        'advanced_robots_meta' => [
            'snippet_enabled' => true,
            'max_snippet' => -1,
            'video_preview_enabled' => true,
            'max_video_preview' => -1,
            'image_preview_enabled' => true,
            'max_image_preview' => 'large'
        ]
    ];

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Get/Save global SEO settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_settings_query_args()
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'save_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_save_settings_args()
                ]
            ]
        );

        // Get all global SEO settings (all post types)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings/all',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_all_settings'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Reset settings for a specific post type
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings/reset',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'reset_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => [
                        'post_type' => [
                            'required' => true,
                            'type' => 'string',
                            'description' => 'Post type to reset settings for',
                            'sanitize_callback' => 'sanitize_key'
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Get global SEO settings for a specific post type
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_settings(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');

        // Validate post type
        $validation = $this->validate_post_type($post_type);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Get all settings
        $all_settings = get_option(self::OPTION_NAME, []);

        // Merge any saved values for this post type over the per-post-type
        // defaults. A plain `?? defaults` fallback is all-or-nothing: once a
        // partial record exists for the post type (e.g. one written without a
        // description template, or by an importer/another feature), every field
        // it omits — including the default `%excerpt%` description — would come
        // back blank in the settings UI. Merging keeps saved values authoritative
        // while restoring defaults for any keys the saved record doesn't set.
        $saved    = is_array($all_settings[$post_type] ?? null) ? $all_settings[$post_type] : [];
        $settings = array_merge($this->get_default_settings($post_type), $saved);

        return new WP_REST_Response([
            'success' => true,
            'data' => $settings,
            'post_type' => $post_type,
            'message' => sprintf('Settings retrieved successfully for post type: %s', $post_type)
        ], 200);
    }

    /**
     * Save global SEO settings for a specific post type
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function save_settings(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $settings = $request->get_param('settings');

        // Validate post type
        $validation = $this->validate_post_type($post_type);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Validate settings structure
        if (empty($settings) || !is_array($settings)) {
            return new WP_Error(
                'invalid_settings',
                'Settings must be provided as an array',
                ['status' => 400]
            );
        }

        // Sanitize settings
        $sanitized_settings = $this->sanitize_settings($settings);

        // Get all existing settings
        $all_settings = get_option(self::OPTION_NAME, []);

        // Capture the previously-stored value so a genuine write failure can be
        // told apart from a no-op save (payload identical to what's stored).
        $previous = $all_settings[$post_type] ?? null;

        // Update settings for this post type
        $all_settings[$post_type] = $sanitized_settings;

        // Save to database
        $updated = update_option(self::OPTION_NAME, $all_settings);

        if ($updated || $previous === $sanitized_settings) {
            return new WP_REST_Response([
                'success' => true,
                'data' => $sanitized_settings,
                'post_type' => $post_type,
                'message' => sprintf('Settings saved successfully for post type: %s', $post_type)
            ], 200);
        }

        return new WP_Error(
            'save_failed',
            'Failed to save settings',
            ['status' => 500]
        );
    }

    /**
     * Get all global SEO settings for all post types
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_all_settings(WP_REST_Request $request): WP_REST_Response {
        $all_settings = get_option(self::OPTION_NAME, []);

        return new WP_REST_Response([
            'success' => true,
            'data' => $all_settings,
            'count' => count($all_settings),
            'message' => 'All global SEO settings retrieved successfully'
        ], 200);
    }

    /**
     * Reset settings for a specific post type to defaults
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function reset_settings(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');

        // Validate post type
        $validation = $this->validate_post_type($post_type);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Get all settings
        $all_settings = get_option(self::OPTION_NAME, []);

        // Remove settings for this post type (will fall back to defaults)
        unset($all_settings[$post_type]);

        // Save updated settings
        update_option(self::OPTION_NAME, $all_settings);

        // Get default settings
        $default_settings = $this->get_default_settings($post_type);

        return new WP_REST_Response([
            'success' => true,
            'data' => $default_settings,
            'post_type' => $post_type,
            'message' => sprintf('Settings reset to defaults for post type: %s', $post_type)
        ], 200);
    }




    /**
     * Validate post type
     *
     * @since 1.0.0
     *
     * @param string $post_type Post type to validate
     * @return true|WP_Error True if valid, WP_Error otherwise
     */
    private function validate_post_type(string $post_type) {
        if (empty($post_type)) {
            return new WP_Error(
                'missing_post_type',
                'Post type parameter is required',
                ['status' => 400]
            );
        }

        // Check if post type exists
        if (!post_type_exists($post_type)) {
            return new WP_Error(
                'invalid_post_type',
                sprintf('Post type "%s" does not exist', $post_type),
                ['status' => 400]
            );
        }

        // Apply the shared Global SEO target policy (public + viewable + not on
        // the deny list) so REST rejects the same types the admin UI hides.
        if (!\ThinkRank\SEO\Global_SEO_Post_Types::is_allowed($post_type)) {
            return new WP_Error(
                'non_public_post_type',
                sprintf('Post type "%s" is not a valid Global SEO target', $post_type),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Get default settings for a post type
     *
     * @since 1.0.0
     *
     * @param string $post_type Post type
     * @return array Default settings
     */
    private function get_default_settings(string $post_type): array {
        $defaults = self::DEFAULT_SETTINGS;

        // Customize defaults based on post type
        switch ($post_type) {
            case 'post':
                $defaults['schema_type'] = 'Article';
                $defaults['article_type'] = 'BlogPosting';
                $defaults['media_type'] = '';
                $defaults['link_suggestions'] = true;
                break;

            case 'page':
                $defaults['schema_type'] = 'WebPage';
                $defaults['article_type'] = '';
                $defaults['media_type'] = '';
                $defaults['link_suggestions'] = true;
                break;

            case 'attachment':
                $defaults['schema_type'] = 'Media';
                $defaults['article_type'] = '';
                $defaults['media_type'] = 'ImageObject';
                $defaults['title'] = '%title% %sep% %sitename%';
                $defaults['description'] = '%caption%';
                break;

            case 'product':
                $defaults['schema_type'] = 'Product';
                $defaults['article_type'] = '';
                $defaults['media_type'] = '';
                $defaults['link_suggestions'] = false;
                break;

            default:
                // For custom post types, use generic defaults
                $defaults['schema_type'] = 'WebPage';
                $defaults['article_type'] = '';
                $defaults['media_type'] = '';
                $defaults['link_suggestions'] = true;
                break;
        }

        return $defaults;
    }

    /**
     * Sanitize settings array
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to sanitize
     * @return array Sanitized settings
     */
    private function sanitize_settings(array $settings): array {
        // Start from the shared per-field normalizer (drops unknown keys, coerces
        // types/enums). The REST save replaces the whole object, so the three
        // robots structures are then emitted complete — every subkey present —
        // by overlaying the normalized values onto full defaults.
        $sanitized = self::normalize_settings_patch($settings);

        $sanitized['robots_meta'] = array_merge([
            'index' => true,
            'noindex' => false,
            'nofollow' => false,
            'noarchive' => false,
            'noimageindex' => false,
            'nosnippet' => false,
        ], $sanitized['robots_meta'] ?? []);

        $sanitized['robots_meta_enabled'] = $sanitized['robots_meta_enabled'] ?? false;

        $sanitized['advanced_robots_meta'] = array_merge([
            'snippet_enabled' => true,
            'max_snippet' => -1,
            'video_preview_enabled' => true,
            'max_video_preview' => -1,
            'image_preview_enabled' => true,
            'max_image_preview' => 'large',
        ], $sanitized['advanced_robots_meta'] ?? []);

        return $sanitized;
    }

    /**
     * Normalize a PARTIAL global-SEO settings patch.
     *
     * Keeps only recognized keys and coerces each SUPPLIED value to its
     * canonical type — booleans, enum allow-lists, clamped numerics, sanitized
     * text, and nested robots structures containing only their known subkeys.
     * Absent keys are NOT filled with defaults.
     *
     * This is the single per-field contract shared by both write paths so they
     * can no longer diverge: the REST endpoint layers full defaults on top (a
     * whole-object replace), while the MCP ability merges the returned patch into
     * the stored template (a partial update). Sharing it fixes the ability
     * previously retaining string booleans and unknown nested keys.
     *
     * @since 1.20.1
     * @param array $settings Raw settings (full or partial).
     * @return array Normalized subset containing only supplied, recognized keys.
     */
    public static function normalize_settings_patch(array $settings): array {
        $out = [];

        if (isset($settings['title'])) {
            $out['title'] = sanitize_text_field((string) $settings['title']);
        }
        if (isset($settings['description'])) {
            $out['description'] = sanitize_text_field((string) $settings['description']);
        }
        if (isset($settings['schema_type'])) {
            $value = sanitize_text_field((string) $settings['schema_type']);
            $out['schema_type'] = in_array($value, self::ALLOWED_SCHEMA_TYPES, true) ? $value : 'WebPage';
        }
        if (isset($settings['article_type'])) {
            $value = sanitize_text_field((string) $settings['article_type']);
            $out['article_type'] = in_array($value, self::ALLOWED_ARTICLE_TYPES, true) ? $value : '';
        }
        if (isset($settings['media_type'])) {
            $value = sanitize_text_field((string) $settings['media_type']);
            $out['media_type'] = in_array($value, self::ALLOWED_MEDIA_TYPES, true) ? $value : '';
        }
        if (isset($settings['link_suggestions'])) {
            $out['link_suggestions'] = (bool) $settings['link_suggestions'];
        }
        if (isset($settings['robots_meta_enabled'])) {
            $out['robots_meta_enabled'] = (bool) $settings['robots_meta_enabled'];
        }

        // Nested robots_meta: only the recognized boolean subkeys that were
        // actually supplied (unknown nested keys are dropped, values coerced).
        if (isset($settings['robots_meta']) && is_array($settings['robots_meta'])) {
            $robots = [];
            foreach (['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'] as $key) {
                if (isset($settings['robots_meta'][$key])) {
                    $robots[$key] = (bool) $settings['robots_meta'][$key];
                }
            }
            $out['robots_meta'] = $robots;
        }

        // Nested advanced_robots_meta: recognized booleans, clamped numerics, and
        // the image-preview enum — again only for supplied subkeys.
        if (isset($settings['advanced_robots_meta']) && is_array($settings['advanced_robots_meta'])) {
            $adv_in = $settings['advanced_robots_meta'];
            $adv = [];
            foreach (['snippet_enabled', 'video_preview_enabled', 'image_preview_enabled'] as $key) {
                if (isset($adv_in[$key])) {
                    $adv[$key] = (bool) $adv_in[$key];
                }
            }
            if (isset($adv_in['max_snippet'])) {
                $adv['max_snippet'] = max(-1, (int) $adv_in['max_snippet']);
            }
            if (isset($adv_in['max_video_preview'])) {
                $adv['max_video_preview'] = max(-1, (int) $adv_in['max_video_preview']);
            }
            if (isset($adv_in['max_image_preview'])) {
                $adv['max_image_preview'] = in_array($adv_in['max_image_preview'], self::ALLOWED_IMAGE_PREVIEW, true)
                    ? $adv_in['max_image_preview']
                    : 'large';
            }
            $out['advanced_robots_meta'] = $adv;
        }

        return $out;
    }

    /**
     * Get query arguments for GET settings endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_settings_query_args(): array {
        return [
            'post_type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Post type to retrieve settings for',
                'sanitize_callback' => 'sanitize_key'
            ]
        ];
    }

    /**
     * Get arguments for POST save settings endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_save_settings_args(): array {
        return [
            'post_type' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Post type to save settings for',
                'sanitize_callback' => 'sanitize_key'
            ],
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Settings object containing title, description, schema_type, article_type, and media_type',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Title format with variables like %title%, %sitename%, %sep%'
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Description format with variables like %excerpt%'
                    ],
                    'schema_type' => [
                        'type' => 'string',
                        'description' => 'Schema.org type (e.g., Article, WebPage, Media, Product)'
                    ],
                    'article_type' => [
                        'type' => 'string',
                        'description' => 'Article type (e.g., BlogPosting, NewsArticle) - used when schema_type is Article'
                    ],
                    'media_type' => [
                        'type' => 'string',
                        'description' => 'Media type (e.g., ImageObject, VideoObject) - used when schema_type is Media'
                    ],
                    'link_suggestions' => [
                        'type' => 'boolean',
                        'description' => 'Enable link suggestions and pillar content feature'
                    ],
                    'robots_meta' => [
                        'type' => 'object',
                        'description' => 'Robots meta settings',
                        'properties' => [
                            'index' => ['type' => 'boolean'],
                            'noindex' => ['type' => 'boolean'],
                            'nofollow' => ['type' => 'boolean'],
                            'noarchive' => ['type' => 'boolean'],
                            'noimageindex' => ['type' => 'boolean'],
                            'nosnippet' => ['type' => 'boolean']
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Check read permissions
     *
     * @since 1.0.0
     *
     * @return bool True if user has read permissions
     */
    public function check_read_permissions(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Check manage permissions
     *
     * @since 1.0.0
     *
     * @return bool True if user has manage permissions
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_global_seo');
    }
}
