<?php

/**
 * Instant Indexing API Endpoints Class
 *
 * REST API endpoints for Instant Indexing management including
 * IndexNow settings, post type selection, and API key management.
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

/**
 * Instant Indexing API Endpoints Class
 *
 * Provides REST API endpoints for Instant Indexing operations.
 *
 * @since 1.0.0
 */
class Instant_Indexing_Endpoint extends WP_REST_Controller {

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
    protected $rest_base = 'instant-indexing';

    /**
     * Settings option name
     *
     * @since 1.0.0
     * @var string
     */
    private $option_name = 'thinkrank_instant_indexing_settings';

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Get settings
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

        // Get viewable post types
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/post-types',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_post_types'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Regenerate API Key
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/regenerate-key',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'regenerate_api_key'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );
        // Submit URLs manually
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/submit',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'submit_urls_to_api'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => [
                        'urls' => [
                            'required' => true,
                            'type' => 'string', // Textarea content
                            'description' => 'List of URLs to submit'
                        ]
                    ]
                ]
            ]
        );

        // Verify the advertised key file is actually reachable (see #247).
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/verify-key',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'verify_key'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Get submission history
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/history',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_submission_history'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'limit' => [
                            'required' => false,
                            'type' => 'integer',
                            'default' => -1
                        ]
                    ]
                ],
                [
                    'methods' => 'DELETE',
                    'callback' => [$this, 'clear_submission_history'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );
    }

    /**
     * Get settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = get_option($this->option_name, []);

        $defaults = [
            'enabled' => false,
            'auto_submit_post_types' => ['post', 'page'],
            'api_key' => ''
        ];

        $settings = wp_parse_args($settings, $defaults);

        // Ensure api_key is always present
        if (empty($settings['api_key'])) {
            $settings['api_key'] = $this->generate_api_key();
            $this->manage_key_file($settings['api_key']);
            update_option($this->option_name, $settings);
        } else {
            // Verify file exists for existing key, create if missing
            $file_path = ABSPATH . $settings['api_key'] . '.txt';
            if (!file_exists($file_path)) {
                $this->manage_key_file($settings['api_key']);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $settings
        ], 200);
    }

    /**
     * Update settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_settings(WP_REST_Request $request) {
        $params = $request->get_json_params();

        if (empty($params)) {
            $params = $request->get_params(); // Fallback if content-type is not JSON
        }

        // Sanitize Post Types
        $post_types = isset($params['auto_submit_post_types']) ? (array) $params['auto_submit_post_types'] : [];
        $sanitized_post_types = array_map('sanitize_text_field', $post_types);

        // We generally don't let user update API Key directly via update_settings, 
        // they should use regenerate, but if we need to support manual entry:
        $current_settings = get_option($this->option_name, []);
        $new_settings = array_merge($current_settings, [
            'auto_submit_post_types' => $sanitized_post_types
        ]);

        // Save enabled state
        if (isset($params['enabled'])) {
            $new_settings['enabled'] = rest_sanitize_boolean($params['enabled']);
        }

        // If API key is provided and different (rare case), sanitize and validate
        // it. The key is used to build a file path under ABSPATH, so it must be a
        // plain hex token — reject anything else (e.g. path-traversal sequences).
        if (isset($params['api_key'])) {
            $candidate_key = sanitize_text_field($params['api_key']);
            if (!preg_match('/^[a-f0-9]{8,64}$/', $candidate_key)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => __('Invalid API key format. It must be 8–64 hexadecimal characters.', 'thinkrank'),
                ], 400);
            }
            $new_settings['api_key'] = $candidate_key;
        }

        update_option($this->option_name, $new_settings);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Settings updated successfully', 'thinkrank'),
            'data' => $new_settings
        ], 200);
    }

    /**
     * Get viewable post types
     * 
     * Uses custom args as per requirements.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_post_types(WP_REST_Request $request): WP_REST_Response {
        $args = [
            'public' => true,
        ];

        $post_types = get_post_types($args, "objects");
        $post_types = array_filter($post_types, 'is_post_type_viewable');

        $data = [];
        foreach ($post_types as $post_type) {
            $data[] = [
                'slug' => $post_type->name,
                'name' => $post_type->label,
                'singular_name' => $post_type->labels->singular_name
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Regenerate API Key
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function regenerate_api_key(WP_REST_Request $request): WP_REST_Response {
        $settings = get_option($this->option_name, []);
        $old_key = $settings['api_key'] ?? null;

        $new_key = $this->generate_api_key();

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['api_key'] = $new_key;
        update_option($this->option_name, $settings);

        // Update key files (create new, delete old)
        $this->manage_key_file($new_key, $old_key);

        return new WP_REST_Response([
            'success' => true,
            'key' => $new_key,
            'message' => __('API Key regenerated successfully', 'thinkrank')
        ], 200);
    }

    /**
     * Manage API Key File (Create new, delete old)
     * 
     * @param string $new_key New API Key
     * @param string|null $old_key Old API Key to delete
     * @return void
     */
    private function manage_key_file(string $new_key, ?string $old_key = null): void {
        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        if (!$wp_filesystem) {
            return;
        }

        // Defense-in-depth: the key becomes a filename under ABSPATH, so never
        // touch the filesystem with anything that isn't a plain hex token. Guards
        // against a traversal payload (e.g. ../../ads) reaching put_contents/delete.
        $is_valid_key = static function (string $key): bool {
            return (bool) preg_match('/^[a-f0-9]{8,64}$/', $key);
        };

        // Create new file
        if (!empty($new_key) && $is_valid_key($new_key)) {
            $file_path = ABSPATH . $new_key . '.txt';
            if ($wp_filesystem->is_writable(ABSPATH)) {
                $wp_filesystem->put_contents($file_path, $new_key, FS_CHMOD_FILE);
            }
        }

        // Delete old file
        if (!empty($old_key) && $old_key !== $new_key && $is_valid_key($old_key)) {
            $old_file_path = ABSPATH . $old_key . '.txt';
            if ($wp_filesystem->exists($old_file_path)) {
                $wp_filesystem->delete($old_file_path);
            }
        }
    }

    /**
     * Generate a random API key (32 chars hex)
     *
     * @return string
     */
    private function generate_api_key(): string {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            // Fallback if random_bytes fails
            return md5(uniqid((string) wp_rand(), true));
        }
    }

    /**
     * Submit URLs manually
     *
     * @since 1.1.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function submit_urls_to_api(WP_REST_Request $request): WP_REST_Response {
        $urls_param = $request->get_param('urls');
        $urls = array_filter(array_map('trim', explode("\n", $urls_param)));

        if (empty($urls)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('No valid URLs provided', 'thinkrank')
            ], 400);
        }

        // Limit to 100 for manual submission safety
        if (count($urls) > 100) {
            $urls = array_slice($urls, 0, 100);
        }

        $manager = new \ThinkRank\SEO\Instant_Indexing_Manager();
        $result = $manager->submit_urls($urls);

        // Report the count the manager actually submitted (after same-host
        // filtering and its cap), not the raw input size — otherwise a mix of
        // foreign URLs would overstate how many were sent to IndexNow.
        return new WP_REST_Response([
            'success' => $result['success'],
            'message' => $result['message'],
            'count' => (int) ($result['submitted_count'] ?? 0)
        ], 200);
    }

    /**
     * Verify the advertised IndexNow key file is reachable and returns the key.
     *
     * Runs a one-shot loopback fetch of keyLocation so an unreachable-key
     * configuration (read-only root + Plain permalinks, a CDN edge rule, etc.)
     * surfaces on the settings screen instead of as a silent 403 at first
     * submission (see #247).
     *
     * @since 1.28.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function verify_key(WP_REST_Request $request): WP_REST_Response {
        $manager = new \ThinkRank\SEO\Instant_Indexing_Manager();

        return new WP_REST_Response([
            'success' => true,
            'data' => $manager->verify_key_reachable(),
        ], 200);
    }

    /**
     * Get submission history
     *
     * @since 1.1.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_submission_history(WP_REST_Request $request): WP_REST_Response {
        $manager = new \ThinkRank\SEO\Instant_Indexing_Manager();

        // Prefer server-side pagination (page/per_page). Fall back to the legacy
        // limit param for older callers.
        $page = (int) ($request->get_param('page') ?: 0);
        $per_page = (int) ($request->get_param('per_page') ?: 0);

        if ($page > 0 || $per_page > 0) {
            $result = $manager->get_history_page($page > 0 ? $page : 1, $per_page > 0 ? $per_page : 10);
            return new WP_REST_Response([
                'success' => true,
                'data' => $result['items'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'total_pages' => (int) ceil($result['total'] / $result['per_page']),
                ],
            ], 200);
        }

        $limit = $request->get_param('limit') ?: -1;
        $history = $manager->get_history((int) $limit);

        return new WP_REST_Response([
            'success' => true,
            'data' => $history
        ], 200);
    }

    /**
     * Clear submission history
     *
     * @since 1.1.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function clear_submission_history(WP_REST_Request $request): WP_REST_Response {
        $manager = new \ThinkRank\SEO\Instant_Indexing_Manager();
        $result = $manager->clear_history();

        if ($result) {
            return new WP_REST_Response([
                'success' => true,
                'message' => __('History cleared successfully', 'thinkrank')
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => __('Failed to clear history', 'thinkrank')
        ], 500);
    }

    /**
     * Check read permissions
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_read_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_instant_indexing');
    }

    /**
     * Check manage permissions
     *
     * @since 1.0.0
     *
     * @return bool Permission status
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_instant_indexing');
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
            'auto_submit_post_types' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string'
                ],
                'description' => 'List of post types to auto-submit'
            ],
            'api_key' => [
                'required' => false,
                'type' => 'string',
                'description' => 'IndexNow API Key'
            ],
            'enabled' => [
                'required' => false,
                'type' => 'boolean',
                'description' => 'Enable or disable Instant Indexing'
            ]
        ];
    }
}
