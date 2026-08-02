<?php
/**
 * Social Platforms API Endpoints Class
 *
 * Provides REST API endpoints for social platform verification codes and IDs
 * with selective encryption for sensitive verification codes.
 *
 * @package ThinkRank\API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use ThinkRank\Core\Settings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Social Platforms API Endpoints Class
 *
 * Handles social platform verification codes and IDs with selective encryption.
 * Encrypts sensitive verification codes while keeping public IDs visible.
 *
 * @since 1.0.0
 */
class Social_Platforms_Endpoint extends WP_REST_Controller {

    /**
     * API namespace
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * REST base
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'social-platforms';

    /**
     * Settings instance
     *
     * @since 1.0.0
     * @var Settings
     */
    private Settings $settings;

    /**
     * Sensitive keys that should be encrypted
     *
     * @since 1.0.0
     * @var array
     */
    private array $sensitive_keys = [
        'pinterest_site_verification',
        'instagram_verification',
        'tiktok_verification'
    ];

    /**
     * Public keys that remain visible
     *
     * @since 1.0.0
     * @var array
     */
    private array $public_keys = [
        'facebook_app_id',
        'facebook_admins',
        'youtube_channel_id',
        'whatsapp_business_id'
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->settings = Settings::instance();
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Social platform settings management
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
     * Get social platform settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $settings = $this->get_social_platform_settings();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings
                ],
                'message' => 'Social platform settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve social platform settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update social platform settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $settings = $request->get_param('settings');
            
            if (empty($settings) || !is_array($settings)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Invalid settings data provided'
                ], 400);
            }

            // Sanitize input, then enforce the shared format rules before saving.
            // Sanitization only strips unsafe characters; without this, malformed
            // verification codes / IDs would be stored and later emitted verbatim.
            $sanitized_settings = $this->sanitize_settings($settings);

            $validation_errors = $this->validate_settings_format($sanitized_settings);
            if (!empty($validation_errors)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'One or more social platform values are in an invalid format',
                    'errors'  => $validation_errors
                ], 400);
            }

            $success = $this->save_social_platform_settings($sanitized_settings);

            if ($success) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'settings' => $this->get_social_platform_settings()
                    ],
                    'message' => 'Social platform settings saved successfully'
                ], 200);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to save social platform settings'
                ], 500);
            }

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to update social platform settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get social platform settings from Settings class
     *
     * @since 1.0.0
     * @return array Settings array
     */
    private function get_social_platform_settings(): array {
        $settings = [];
        
        // Get public IDs (visible)
        foreach ($this->public_keys as $key) {
            $settings[$key] = $this->settings->get($key, '');
        }
        
        // Get sensitive verification codes (encrypted, masked for display)
        foreach ($this->sensitive_keys as $key) {
            $value = $this->settings->get($key, '');
            $settings[$key] = $this->mask_verification_code($value);
        }
        
        return $settings;
    }

    /**
     * Save social platform settings using Settings class
     *
     * @since 1.0.0
     * @param array $settings Settings to save
     * @return bool Success status
     */
    private function save_social_platform_settings(array $settings): bool {
        $success = true;
        
        // Save each setting individually using the Settings class
        // This ensures proper encryption for sensitive verification codes
        foreach ($settings as $key => $value) {
            // Only save if the key is in our allowed lists and has a value
            if (in_array($key, array_merge($this->public_keys, $this->sensitive_keys)) && !empty($value)) {
                if (!$this->settings->set($key, $value)) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }

    /**
     * Sanitize settings data
     *
     * @since 1.0.0
     * @param array $settings Raw settings
     * @return array Sanitized settings
     */
    private function sanitize_settings(array $settings): array {
        $sanitized = [];

        // Sanitize public IDs (only if not empty)
        foreach ($this->public_keys as $key) {
            if (!empty($settings[$key])) {
                $sanitized[$key] = sanitize_text_field($settings[$key]);
            }
        }

        // Sanitize sensitive verification codes (only if not empty)
        foreach ($this->sensitive_keys as $key) {
            if (!empty($settings[$key])) {
                $sanitized[$key] = sanitize_text_field($settings[$key]);
            }
        }

        return $sanitized;
    }

    /**
     * Validate sanitized settings against the shared social platform format rules.
     *
     * Reuses Social_Meta_Manager's per-field rules (single source of truth) so the
     * REST save path rejects malformed verification codes / IDs instead of storing
     * them and letting them render as broken verification meta tags.
     *
     * @since 1.14.0
     *
     * @param array $settings Sanitized settings.
     * @return array<string, string> Map of field key => error message; empty when all valid.
     */
    private function validate_settings_format(array $settings): array {
        $errors = [];

        foreach ($settings as $key => $value) {
            $error = \ThinkRank\SEO\Social_Meta_Manager::validate_platform_field($key, $value);
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        return $errors;
    }

    /**
     * Mask verification code for security display (XXX pattern)
     *
     * @since 1.0.0
     * @param string $code Verification code to mask
     * @return string Masked code or empty string
     */
    private function mask_verification_code(string $code): string {
        if (empty($code)) {
            return '';
        }

        // Show first 4 characters + XXXX suffix (like placeholders)
        if (strlen($code) > 8) {
            return substr($code, 0, 4) . 'XXXX';
        }

        return 'XXXX';
    }

    /**
     * Get settings arguments for REST API
     *
     * @since 1.0.0
     * @return array Settings arguments
     */
    private function get_settings_args(): array {
        return [
            'settings' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Social platform settings object'
            ]
        ];
    }

    /**
     * Check read permissions
     *
     * @since 1.0.0
     * @return bool Permission status
     */
    public function check_read_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_settings');
    }

    /**
     * Check manage permissions
     *
     * @since 1.0.0
     * @return bool Permission status
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_settings');
    }
}
