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

use ThinkRank\Core\Settings;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load CSRF Protection trait.
//
// This class does not compose the trait — its routes are guarded by
// check_permissions() plus WordPress' own X-WP-Nonce enforcement for cookie
// auth — but the require stays: of the 13 endpoints that call trait methods
// only 3 require the file themselves, so the other 10 depend on whichever
// endpoint loaded first having pulled it in. Untangling that belongs in an
// API-wide autoload pass, not here.
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-csrf-protection.php';

/**
 * Author Archives API Endpoint Class
 *
 * @since 1.0.0
 */
class Author_Archives_Endpoint extends WP_REST_Controller {

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
     * Boolean API field => Settings storage key.
     *
     * @since 1.29.1
     * @var array<string, string>
     */
    private const BOOL_SETTINGS = [
        'enabled'                => 'author_archives_enabled',
        'show_in_search_results' => 'author_archives_index',
        'show_empty_archives'    => 'author_archives_show_empty',
    ];

    /**
     * String API field => Settings storage key.
     *
     * @since 1.29.1
     * @var array<string, string>
     */
    private const STRING_SETTINGS = [
        'title'            => 'author_archives_title',
        'meta_description' => 'author_archives_meta_desc',
    ];

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
            return new WP_REST_Response([
                'success' => true,
                'data' => $this->get_current_settings(),
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
     * Read the stored settings in API field shape.
     *
     * Shared by GET and by the POST response so a save always answers with the
     * same values a subsequent read would return.
     *
     * @since 1.29.1
     * @return array<string, bool|string>
     */
    private function get_current_settings(): array {
        $settings = Settings::instance();

        return [
            'enabled' => (bool) $settings->get('author_archives_enabled', true),
            'show_in_search_results' => (bool) $settings->get('author_archives_index', true),
            'show_empty_archives' => (bool) $settings->get('author_archives_show_empty', false),
            'title' => (string) $settings->get('author_archives_title', Settings::DEFAULT_AUTHOR_ARCHIVES_TITLE),
            'meta_description' => (string) $settings->get('author_archives_meta_desc', Settings::DEFAULT_AUTHOR_ARCHIVES_META_DESC),
        ];
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

            // Reject unrecognised fields rather than reporting them as saved.
            // A typo'd key used to come back inside a "saved successfully"
            // envelope while nothing was written.
            $unknown = array_diff(
                array_keys($params),
                array_keys(self::BOOL_SETTINGS),
                array_keys(self::STRING_SETTINGS)
            );
            if (!empty($unknown)) {
                return new WP_Error(
                    'invalid_params',
                    'Unknown setting keys: ' . implode(', ', $unknown),
                    ['status' => 400]
                );
            }

            $settings = Settings::instance();
            $failed = [];

            // Accumulate failures instead of overwriting $success on each
            // field — the old code let the last field's result decide the
            // whole response, so an earlier failure reported as a 200.
            foreach (self::BOOL_SETTINGS as $field => $key) {
                if (isset($params[$field]) && !$settings->set($key, (bool) $params[$field])) {
                    $failed[] = $field;
                }
            }
            foreach (self::STRING_SETTINGS as $field => $key) {
                if (isset($params[$field]) && !$settings->set($key, sanitize_text_field((string) $params[$field]))) {
                    $failed[] = $field;
                }
            }

            if (!empty($failed)) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update settings: ' . implode(', ', $failed),
                    ['status' => 500, 'failed_keys' => $failed]
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Settings saved successfully',
                // Report what is actually stored, not an echo of the request.
                // Returning $params advertised unsanitized input as saved state.
                'data' => $this->get_current_settings(),
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
