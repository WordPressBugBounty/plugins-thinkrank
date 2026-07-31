<?php

/**
 * Integrations API Endpoints Class
 *
 * Provides REST API endpoints for Google API integrations management
 * following the same pattern as Site Identity and Social Media endpoints.
 *
 * @package ThinkRank\API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use ThinkRank\Core\Settings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrations API Endpoints Class
 *
 * Handles Google API keys and integration settings management
 * following ThinkRank patterns from working endpoints.
 *
 * @since 1.0.0
 */
class Integrations_Endpoint extends WP_REST_Controller {

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
    protected $rest_base = 'integrations';

    /**
     * Settings instance
     *
     * @since 1.0.0
     * @var Settings
     */
    private Settings $settings;

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
        // Integrations settings management
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

        // Test Google API connections
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/test-connections',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'test_connections'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );

        // Verify GA4 tracking
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/verify-ga4-tracking',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'verify_ga4_tracking'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => [
                        'measurement_id' => [
                            'required' => true,
                            'type' => 'string',
                            // No regex delimiters — WP's REST validator wraps the
                            // pattern in its own (#...#u), so a leading/trailing
                            // slash would require literal slashes in the value.
                            'pattern' => '^G-[A-Z0-9]{10}$',
                            'sanitize_callback' => 'sanitize_text_field',
                            'description' => 'GA4 Measurement ID in format G-XXXXXXXXXX'
                        ]
                    ]
                ]
            ]
        );

        // Detect GA4 conflicts
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/detect-ga4-conflicts',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'detect_ga4_conflicts'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Get Search Console Sites
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/search-console/sites',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_search_console_sites'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );

        // Disconnect Google Account
        register_rest_route($this->namespace, '/integrations/google/disconnect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'disconnect_google_account'],
            'permission_callback' => [$this, 'check_manage_permissions'] // Changed to check_manage_permissions for consistency
        ]);

        // Note: there is no save-google-token route. Tokens are swapped
        // server-to-server in Google_OAuth_Proxy and never pass through the
        // browser, so there is nothing for the SPA to hand back.
    }

    /**
     * Get integrations settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        try {
            $settings = $this->get_integrations_settings();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings
                ],
                'message' => 'Integrations settings retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve integrations settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update integrations settings
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

            // Sanitize and save settings
            $sanitized_settings = $this->sanitize_settings($settings);
            $success = $this->save_integrations_settings($sanitized_settings);

            if ($success) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'settings' => $this->get_integrations_settings()
                    ],
                    'message' => 'Integrations settings saved successfully'
                ], 200);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to save integrations settings'
                ], 500);
            }
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to update integrations settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test Google API connections
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function test_connections(WP_REST_Request $request): WP_REST_Response {
        try {
            // Get raw, unmasked API keys directly from Settings class for testing
            $analytics_key = $this->settings->get('google_analytics_api_key');
            $search_console_key = $this->settings->get('google_search_console_api_key');
            $pagespeed_key = $this->settings->get('google_pagespeed_api_key');

            $results = [];

            // Test Google Analytics API
            if (!empty($analytics_key)) {
                $results['google_analytics'] = $this->test_google_analytics($analytics_key);
            } else {
                $results['google_analytics'] = ['status' => 'not_configured', 'message' => 'API key not configured'];
            }

            // Test Search Console API
            if (!empty($search_console_key)) {
                $results['search_console'] = $this->test_search_console($search_console_key);
            } else {
                $results['search_console'] = ['status' => 'not_configured', 'message' => 'API key not configured'];
            }

            // Test PageSpeed API (now uses OAuth access token)
            $access_token = $this->settings->get('google_access_token');
            if (!empty($access_token)) {
                $results['pagespeed'] = $this->test_pagespeed_oauth($access_token);
            } else {
                $results['pagespeed'] = ['status' => 'not_configured', 'message' => 'Google account not connected'];
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $results,
                'message' => 'Connection tests completed'
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get integrations settings from Settings class (with encryption)
     *
     * @since 1.0.0
     * @return array Settings array
     */
    private function get_integrations_settings(): array {
        $settings = [];

        // Get encrypted API keys
        $settings['google_analytics_api_key'] = $this->settings->get('google_analytics_api_key');
        $settings['google_search_console_api_key'] = $this->settings->get('google_search_console_api_key');
        $settings['google_pagespeed_api_key'] = $this->settings->get('google_pagespeed_api_key');

        // Get GA4 tracking settings (let Settings class handle defaults)
        $settings['ga4_measurement_id'] = $this->settings->get('ga4_measurement_id');
        $settings['ga4_auto_inject'] = $this->settings->get('ga4_auto_inject');
        $settings['ga4_anonymize_ip'] = $this->settings->get('ga4_anonymize_ip');
        $settings['ga4_exclude_admin'] = $this->settings->get('ga4_exclude_admin');
        $settings['ga4_tracking_verified'] = $this->settings->get('ga4_tracking_verified');
        $settings['ga4_last_verification'] = $this->settings->get('ga4_last_verification');

        // Get other integration settings (let Settings class handle defaults)
        $settings['api_timeout'] = $this->settings->get('api_timeout');
        $settings['enable_rate_limiting'] = $this->settings->get('enable_rate_limiting');
        $settings['cache_duration'] = $this->settings->get('cache_duration');
        $settings['auto_test_connections'] = $this->settings->get('auto_test_connections');
        $settings['retry_failed_requests'] = $this->settings->get('retry_failed_requests');
        $settings['google_account_connected'] = $this->settings->get('google_account_connected');

        // Mask API keys for security (like OpenAI/Claude keys)
        $settings['google_analytics_api_key'] = $this->mask_api_key($settings['google_analytics_api_key']);
        $settings['google_search_console_api_key'] = $this->mask_api_key($settings['google_search_console_api_key']);
        $settings['google_pagespeed_api_key'] = $this->mask_api_key($settings['google_pagespeed_api_key']);

        return $settings;
    }

    /**
     * Save integrations settings using Settings class (with encryption)
     *
     * @since 1.0.0
     * @param array $settings Settings to save
     * @return bool Success status
     */
    private function save_integrations_settings(array $settings): bool {
        $success = true;

        // Save each setting individually using the Settings class
        // This ensures proper encryption for API keys
        foreach ($settings as $key => $value) {
            if (!$this->settings->set($key, $value)) {
                $success = false;
                // Setting save failed - error details available through settings manager
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

        // Sanitize API keys. Skip empty values AND the masked sentinel returned
        // by get_integrations_settings (mask_api_key appends 'XXXX'); resubmitting
        // the mask must not overwrite the real stored key.
        foreach (['google_analytics_api_key', 'google_search_console_api_key', 'google_pagespeed_api_key'] as $key_field) {
            if (!empty($settings[$key_field]) && !$this->is_masked_api_key($settings[$key_field])) {
                $sanitized[$key_field] = sanitize_text_field($settings[$key_field]);
            }
        }

        // Sanitize numeric settings
        $sanitized['api_timeout'] = absint($settings['api_timeout'] ?? 30);
        $sanitized['cache_duration'] = absint($settings['cache_duration'] ?? 3600);

        // Sanitize GA4 tracking settings
        $sanitized['ga4_measurement_id'] = sanitize_text_field($settings['ga4_measurement_id'] ?? '');
        $sanitized['ga4_auto_inject'] = isset($settings['ga4_auto_inject']) ? (bool) $settings['ga4_auto_inject'] : false;
        $sanitized['ga4_anonymize_ip'] = isset($settings['ga4_anonymize_ip']) ? (bool) $settings['ga4_anonymize_ip'] : false;
        $sanitized['ga4_exclude_admin'] = isset($settings['ga4_exclude_admin']) ? (bool) $settings['ga4_exclude_admin'] : false;
        $sanitized['ga4_tracking_verified'] = isset($settings['ga4_tracking_verified']) ? (bool) $settings['ga4_tracking_verified'] : false;
        $sanitized['ga4_last_verification'] = sanitize_text_field($settings['ga4_last_verification'] ?? '');

        // Sanitize boolean settings
        $sanitized['enable_rate_limiting'] = isset($settings['enable_rate_limiting']) ? (bool) $settings['enable_rate_limiting'] : true;
        $sanitized['auto_test_connections'] = isset($settings['auto_test_connections']) ? (bool) $settings['auto_test_connections'] : true;
        $sanitized['retry_failed_requests'] = isset($settings['retry_failed_requests']) ? (bool) $settings['retry_failed_requests'] : true;

        return $sanitized;
    }

    /**
     * Mask API key for security display (XXX pattern)
     *
     * @since 1.0.0
     * @param string $api_key API key to mask
     * @return string Masked API key or empty string
     */
    private function mask_api_key(string $api_key): string {
        if (empty($api_key)) {
            return '';
        }

        // Show first 6 characters + XXXX suffix (consistent with placeholders)
        if (strlen($api_key) > 10) {
            return substr($api_key, 0, 6) . 'XXXX';
        }

        return 'XXXX';
    }

    /**
     * Whether a submitted value is the masked sentinel produced by mask_api_key
     * (so we don't persist the mask over a real key).
     *
     * @since 1.0.0
     * @param string $value Submitted value
     * @return bool
     */
    private function is_masked_api_key(string $value): bool {
        return 'XXXX' === $value || str_ends_with($value, 'XXXX');
    }

    /**
     * Test Google Analytics API connection
     *
     * Makes a real API call to Google's PageSpeed Insights API to verify
     * that the API key is valid and has proper permissions.
     *
     * @since 1.0.0
     * @param string $api_key API key to test
     * @return array Test result with status and message
     */
    private function test_google_analytics(string $api_key): array {
        // Basic format validation
        if (empty($api_key) || strlen($api_key) < 30 || !str_starts_with($api_key, 'AIza')) {
            return [
                'status' => 'error',
                'message' => 'Invalid Google API key format. Key should start with "AIza" and be at least 30 characters.'
            ];
        }

        // Make a real API call to test the key
        // Using PageSpeed Insights API as it uses the same API key and has a simple test endpoint
        $test_url = add_query_arg([
            'url' => 'https://example.com/',
            'key' => $api_key,
            'category' => 'performance',
            'strategy' => 'mobile'
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');

        // Get timeout setting from settings or use default
        $timeout = absint($this->settings->get('api_timeout') ?? 30);

        $response = wp_remote_get($test_url, [
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json'
            ],
            'sslverify' => true
        ]);

        // Handle network/connection errors
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => 'Connection failed: ' . $response->get_error_message()
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Handle HTTP errors
        if ($response_code === 400) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Bad request';

            return [
                'status' => 'error',
                'message' => 'API key validation failed: ' . $error_message
            ];
        }

        if ($response_code === 403) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Access forbidden';

            // Check if it's an API key issue
            if (stripos($error_message, 'API key') !== false || stripos($error_message, 'invalid') !== false) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid API key or insufficient permissions. Please verify your Google API key.'
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Access denied: ' . $error_message
            ];
        }

        if ($response_code === 429) {
            return [
                'status' => 'configured',
                'message' => 'API rate limit exceeded. The key is valid but you\'ve reached the quota limit.'
            ];
        }

        if ($response_code !== 200) {
            return [
                'status' => 'error',
                'message' => 'API request failed with status code: ' . $response_code
            ];
        }

        // Validate response body
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'error',
                'message' => 'Invalid API response format'
            ];
        }

        // Check if response has expected structure
        if (!isset($data['lighthouseResult']) && !isset($data['loadingExperience'])) {
            return [
                'status' => 'error',
                'message' => 'Unexpected API response structure'
            ];
        }

        // Success - API key is valid and working
        return [
            'status' => 'configured',
            'message' => 'Google API key is valid and working correctly'
        ];
    }

    /**
     * Test Google Search Console API connection
     *
     * Makes a real API call to Google's PageSpeed Insights API to verify
     * that the API key is valid and has proper permissions.
     *
     * @since 1.0.0
     * @param string $api_key API key to test
     * @return array Test result with status and message
     */
    private function test_search_console(string $api_key): array {
        // Basic format validation
        if (empty($api_key) || strlen($api_key) < 30 || !str_starts_with($api_key, 'AIza')) {
            return [
                'status' => 'error',
                'message' => 'Invalid Google API key format. Key should start with "AIza" and be at least 30 characters.'
            ];
        }

        // Make a real API call to test the key
        // Using PageSpeed Insights API as it uses the same API key format
        $test_url = add_query_arg([
            'url' => 'https://example.com/',
            'key' => $api_key,
            'category' => 'seo',
            'strategy' => 'desktop'
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');

        // Get timeout setting from settings or use default
        $timeout = absint($this->settings->get('api_timeout') ?? 30);

        $response = wp_remote_get($test_url, [
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json'
            ],
            'sslverify' => true
        ]);

        // Handle network/connection errors
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => 'Connection failed: ' . $response->get_error_message()
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Handle HTTP errors
        if ($response_code === 400) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Bad request';

            return [
                'status' => 'error',
                'message' => 'API key validation failed: ' . $error_message
            ];
        }

        if ($response_code === 403) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Access forbidden';

            // Check if it's an API key issue
            if (stripos($error_message, 'API key') !== false || stripos($error_message, 'invalid') !== false) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid API key or insufficient permissions. Please verify your Google API key.'
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Access denied: ' . $error_message
            ];
        }

        if ($response_code === 429) {
            return [
                'status' => 'configured',
                'message' => 'API rate limit exceeded. The key is valid but you\'ve reached the quota limit.'
            ];
        }

        if ($response_code !== 200) {
            return [
                'status' => 'error',
                'message' => 'API request failed with status code: ' . $response_code
            ];
        }

        // Validate response body
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'error',
                'message' => 'Invalid API response format'
            ];
        }

        // Check if response has expected structure
        if (!isset($data['lighthouseResult']) && !isset($data['loadingExperience'])) {
            return [
                'status' => 'error',
                'message' => 'Unexpected API response structure'
            ];
        }

        // Success - API key is valid and working
        return [
            'status' => 'configured',
            'message' => 'Google API key is valid and working correctly'
        ];
    }

    /**
     * Test Google PageSpeed API connection using OAuth token
     *
     * Makes a real API call to Google's PageSpeed Insights API using OAuth
     * Bearer token to verify that the account is properly connected.
     *
     * @since 1.0.0
     * @param string $access_token OAuth access token
     * @return array Test result with status and message
     */
    private function test_pagespeed_oauth(string $access_token): array {
        if (empty($access_token)) {
            return [
                'status' => 'error',
                'message' => 'Google account not connected. Please connect your Google account to use PageSpeed Insights.'
            ];
        }

        // Make a real API call to test the OAuth token with PageSpeed API
        $test_url = add_query_arg([
            'url' => 'https://example.com/',
            'category' => 'performance',
            'strategy' => 'mobile'
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');

        // Get timeout setting from settings or use default
        $timeout = absint($this->settings->get('api_timeout') ?? 30);

        $response = wp_remote_get($test_url, [
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ],
            'sslverify' => true
        ]);

        // Handle network/connection errors
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => 'Connection failed: ' . $response->get_error_message()
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Handle HTTP errors
        if ($response_code === 401) {
            return [
                'status' => 'error',
                'message' => 'Google account token is expired or invalid. Please reconnect your Google account.'
            ];
        }

        if ($response_code === 403) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Access forbidden';
            return [
                'status' => 'error',
                'message' => 'Access denied: ' . $error_message
            ];
        }

        if ($response_code === 429) {
            return [
                'status' => 'configured',
                'message' => 'API rate limit exceeded. The account is valid but you\'ve reached the quota limit.'
            ];
        }

        if ($response_code !== 200) {
            return [
                'status' => 'error',
                'message' => 'API request failed with status code: ' . $response_code
            ];
        }

        // Validate response body
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'error',
                'message' => 'Invalid API response format'
            ];
        }

        // Check if response has expected structure
        if (!isset($data['lighthouseResult']) && !isset($data['loadingExperience'])) {
            return [
                'status' => 'error',
                'message' => 'Unexpected API response structure'
            ];
        }

        // Success - OAuth token is valid and working with PageSpeed API
        return [
            'status' => 'configured',
            'message' => 'Google PageSpeed Insights connected successfully via Google OAuth'
        ];
    }

    /**
     * Disconnect Google Account
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function disconnect_google_account(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            // Best-effort revoke at Google so the refresh token (which never
            // auto-expires) can't keep querying on the admin's behalf after
            // disconnect. Failure here must not block local cleanup.
            $token_to_revoke = $this->settings->get('google_refresh_token', '')
                ?: $this->settings->get('google_access_token', '');
            if (!empty($token_to_revoke)) {
                wp_remote_post('https://oauth2.googleapis.com/revoke', [
                    'timeout' => 10,
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'body'    => ['token' => $token_to_revoke],
                ]);
            }

            // Clear all Google-related settings
            $this->settings->set('google_access_token', '');
            $this->settings->set('google_refresh_token', '');
            $this->settings->set('google_token_expires_in', '');
            $this->settings->set('google_token_created', '');
            $this->settings->set('google_account_connected', false);
            // Also clear site selection
            $this->settings->set('google_search_console_site', '');

            // A deliberate disconnect is not a forced re-authorization.
            delete_option('thinkrank_google_reconnect_required');

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Google account disconnected successfully'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'disconnect_failed',
                'Failed to disconnect Google account: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
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
                'description' => 'Integrations settings object'
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

    /**
     * Verify GA4 tracking
     * Following ThinkRank API response patterns
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function verify_ga4_tracking(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $measurement_id = $request->get_param('measurement_id');

            if (empty($measurement_id)) {
                return new WP_Error(
                    'missing_measurement_id',
                    'Measurement ID is required',
                    ['status' => 400]
                );
            }

            // Load tracking manager
            if (!class_exists('ThinkRank\\Frontend\\Google_Analytics_Tracking_Manager')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/frontend/class-google-analytics-tracking-manager.php';
            }

            $tracking_manager = new \ThinkRank\Frontend\Google_Analytics_Tracking_Manager();
            $verification_result = $tracking_manager->verify_tracking($measurement_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => $verification_result,
                'message' => 'Tracking verification completed'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'verification_failed',
                'Tracking verification failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Detect GA4 conflicts
     * Following ThinkRank API response patterns
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function detect_ga4_conflicts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            // Load tracking manager
            if (!class_exists('ThinkRank\\Frontend\\Google_Analytics_Tracking_Manager')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/frontend/class-google-analytics-tracking-manager.php';
            }

            $tracking_manager = new \ThinkRank\Frontend\Google_Analytics_Tracking_Manager();
            $conflicts = $tracking_manager->detect_existing_tracking();

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'conflicts' => $conflicts,
                    'has_conflicts' => !empty($conflicts)
                ],
                'message' => 'Conflict detection completed'
            ], 200);
        } catch (\Exception $e) {
            return new WP_Error(
                'conflict_detection_failed',
                'Conflict detection failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    /**
     * Get Search Console Sites
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_search_console_sites(WP_REST_Request $request): WP_REST_Response {
        try {
            // The verified-sites list changes rarely but costs a live Google
            // round-trip — serve from a 30-minute transient so the Google
            // Services screen doesn't hit Google on every render.
            $sites_cache_key = 'thinkrank_gsc_sites_list';
            $cached_sites = get_transient($sites_cache_key);
            if (is_array($cached_sites)) {
                return new WP_REST_Response($cached_sites, 200);
            }

            // Ensure Analytics_Manager is loaded for proactive token refresh
            if (!class_exists('ThinkRank\\SEO\\Analytics_Manager')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-analytics-manager.php';
            }

            // Proactively refresh token if expired — prevents 401 errors on initial load
            \ThinkRank\SEO\Analytics_Manager::ensure_fresh_token();

            // Get access token (now guaranteed fresh if refresh_token is available)
            $access_token = $this->settings->get('google_access_token');
            $api_key = $this->settings->get('google_search_console_api_key');

            if (empty($access_token)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Google account not connected'
                ], 401);
            }

            // Initialize Search Console Client
            if (!class_exists('ThinkRank\\Integrations\\Google_Search_Console_Client')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/integrations/class-google-search-console-client.php';
            }

            // Ensure Analytics_Manager is loaded for token refresh
            if (!class_exists('ThinkRank\\SEO\\Analytics_Manager')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-analytics-manager.php';
            }

            // We need a client that can use the access token
            $client = new \ThinkRank\Integrations\Google_Search_Console_Client(
                $api_key ?: '',
                30,
                $access_token
            );

            $max_retries = 1;
            $retry_count = 0;
            $sites_data = [];

            while ($retry_count <= $max_retries) {
                try {
                    // Fetch sites
                    $sites_data = $client->list_sites();
                    break; // Success
                } catch (\Exception $e) {
                    // Check for 401 error
                    if ($e->getCode() === 401 && $retry_count < $max_retries) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('ThinkRank: 401 detected in get_search_console_sites. Forcing token refresh...');
                        }

                        // Initialize Analytics Manager to handle token refresh
                        $analytics_manager = new \ThinkRank\SEO\Analytics_Manager();
                        $analytics_manager->refresh_access_token(true); // Force refresh

                        // Get new token
                        $new_access_token = $this->settings->get('google_access_token');
                        // Update client with new token
                        $client = new \ThinkRank\Integrations\Google_Search_Console_Client(
                            $api_key ?: '',
                            30,
                            $new_access_token
                        );

                        $retry_count++;
                        continue;
                    }

                    throw $e;
                }
            }

            $sites = [];
            if (isset($sites_data['siteEntry']) && is_array($sites_data['siteEntry'])) {
                foreach ($sites_data['siteEntry'] as $site) {
                    $sites[] = [
                        'siteUrl' => $site['siteUrl'] ?? '',
                        'permissionLevel' => $site['permissionLevel'] ?? 'siteOwner'
                    ];
                }
            }

            $payload = [
                'success' => true,
                'data' => [
                    'sites' => $sites
                ],
                'message' => 'Search Console sites retrieved successfully'
            ];

            // Cache successes only — errors must stay retryable.
            if (!empty($sites)) {
                set_transient($sites_cache_key, $payload, 30 * MINUTE_IN_SECONDS);
            }

            return new WP_REST_Response($payload, 200);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 500;

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve Search Console sites: ' . $e->getMessage()
            ], $status);
        }
    }
}
