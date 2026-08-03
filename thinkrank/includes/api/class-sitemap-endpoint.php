<?php
/**
 * Sitemap API Endpoints Class
 *
 * REST API endpoints for XML sitemap management including generation,
 * validation, status monitoring, and search engine submission with
 * proper authentication and comprehensive error handling.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Sitemap_Generator;
use ThinkRank\API\Traits\CSRF_Protection;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Load CSRF Protection trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-csrf-protection.php';

/**
 * Sitemap API Endpoints Class
 *
 * Provides REST API endpoints for sitemap operations including
 * XML generation, validation, status monitoring, and search engine
 * submission with proper authentication and validation.
 *
 * @since 1.0.0
 */
class Sitemap_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    /**
     * Sitemap Generator instance
     *
     * @since 1.0.0
     * @var Sitemap_Generator
     */
    private Sitemap_Generator $sitemap_generator;

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
    protected $rest_base = 'sitemap';

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->sitemap_generator = new Sitemap_Generator();
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Generate XML sitemap
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/generate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_sitemap'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_generate_args()
                ]
            ]
        );

        // Validate sitemap (read-only operation, no CSRF needed)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/validate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'validate_sitemap'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_validate_args()
                ]
            ]
        );

        // Get sitemap status
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/status',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_sitemap_status'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Submit sitemap to search engines
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/submit',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'submit_sitemap'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_submit_args()
                ]
            ]
        );

        // Ping search engines (unified endpoint for manual ping button)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ping',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'ping_search_engines'],
                    'permission_callback' => [$this, 'check_manage_permissions']
                ]
            ]
        );

        // Get sitemap statistics
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/stats',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_sitemap_stats'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Sitemap settings management (following Site Identity pattern)
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_sitemap_settings'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update_sitemap_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_settings_args()
                ]
            ]
        );

        // Get custom post types
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/custom-post-types',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_custom_post_types'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Get sitemap URLs for robots.txt integration
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/robots-urls',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_robots_sitemap_urls'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Get WooCommerce status
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/woocommerce-status',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_woocommerce_status'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Cleanup old sitemap files
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cleanup',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'cleanup_sitemap_files'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => [
                        'sitemap_urls' => [
                            'required' => false,
                            'type' => 'array',
                            'description' => 'Optional array of specific sitemap URLs to clean up. If not provided, scans filesystem automatically.'
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Record that a sitemap generation just ran.
     *
     * Persists the `last_generated` timestamp so the admin UI can distinguish
     * generated sitemaps (whose files now exist on disk) from ones that are
     * merely configured — the "View Generated Sitemaps" links stay disabled
     * until this is set.
     *
     * @return string ISO-8601 timestamp stored as the `last_generated` setting.
     */
    private function record_generation(): string {
        $timestamp = gmdate('c');
        $settings  = $this->sitemap_generator->get_settings('site');
        $settings['last_generated'] = $timestamp;
        $this->sitemap_generator->save_settings('site', null, $settings);
        return $timestamp;
    }

    /**
     * Persist a manual-generation auto-promotion into the stored settings.
     *
     * maybe_promote_to_index() may flip use_sitemap_index on and synthesize the
     * segmented sitemap_urls for the current generation. On the automatic path
     * generate_and_save() saves that resolved state; the manual generate route
     * must do the same, or the next content-/settings-triggered regeneration
     * (which reads stored settings) reverts the site to a single flat file.
     *
     * Only the two mode-defining keys are merged, so this partial generate
     * payload never clobbers unrelated saved settings.
     *
     * @param array $options Options after maybe_promote_to_index().
     * @return void
     */
    private function persist_promoted_mode(array $options): void {
        // Only ever persist an auto-promotion (single -> index). Never turn index
        // mode OFF here: a bare generate call passes an optional/partial payload
        // that may omit use_sitemap_index, and disabling index mode is a settings
        // change owned by the settings endpoint — the generate route must not
        // clobber a saved index because the toggle happened to be absent.
        if (empty($options['use_sitemap_index'])) {
            return;
        }

        $saved = $this->sitemap_generator->get_settings('site');

        // Already in index mode with the same children — nothing to persist.
        if (!empty($saved['use_sitemap_index'])
            && ($options['sitemap_urls'] ?? null) === ($saved['sitemap_urls'] ?? null)) {
            return;
        }

        $saved['use_sitemap_index'] = true;
        if (isset($options['sitemap_urls'])) {
            $saved['sitemap_urls'] = $options['sitemap_urls'];
        }
        $this->sitemap_generator->save_settings('site', null, $saved);
    }

    /**
     * Write the sitemap files when the site has none yet.
     *
     * The sitemap is served as a static file in the web root, so a site whose
     * sitemap is enabled but never generated serves nothing at /sitemap.xml —
     * WordPress core then claims that URL and redirects to wp-sitemap.xml.
     * Turning the sitemap on therefore has to produce the file, which is what
     * the Setup Wizard's "Save & Continue" relies on for its "View Sitemap"
     * link. Only fills the gap: an existing file is left to the explicit
     * "Generate" action so saving settings stays cheap on large sites.
     *
     * @since 1.17.0
     * @param string   $context_type Settings context type.
     * @param int|null $context_id   Settings context id.
     * @return string Sitemap URL, or an empty string when nothing is published.
     */
    private function ensure_sitemap_file(string $context_type, ?int $context_id, bool &$generated_now = false): string {
        $generated_now = false;

        if ($context_type !== 'site') {
            return '';
        }

        $settings = $this->sitemap_generator->get_settings($context_type, $context_id);

        if (empty($settings['enabled'])) {
            return '';
        }

        $sitemap_url = $this->sitemap_generator->get_primary_sitemap_url($settings);

        if ($this->sitemap_generator->primary_sitemap_file_exists($settings)) {
            return $sitemap_url;
        }

        // Never fail the settings save over generation: the settings are already
        // persisted, and content changes or a manual Generate will retry.
        try {
            if (!$this->sitemap_generator->generate_and_save($settings)) {
                return '';
            }
            $generated_now = true;
        } catch (\Throwable $e) {
            return '';
        }

        return $sitemap_url;
    }

    /**
     * Generate XML sitemap
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_sitemap(WP_REST_Request $request) {
        try {
            // Rate limiting: Max 3 generations per 5 minutes per user
            if (!$this->check_rate_limit()) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    'Too many sitemap generation requests. Please wait before trying again.',
                    ['status' => 429]
                );
            }

            // Concurrent generation protection
            if (!$this->acquire_generation_lock()) {
                return new WP_Error(
                    'generation_in_progress',
                    'Sitemap generation is already in progress. Please wait.',
                    ['status' => 409]
                );
            }

            $options = $request->get_param('options') ?? [];
            if (!is_array($options)) {
                $options = [];
            }
            // sitemap_urls must be an array wherever it is counted/iterated below
            // (and in the generator); drop a wrong-typed value so a malformed
            // request yields normal output instead of an uncaught TypeError.
            if (isset($options['sitemap_urls']) && !is_array($options['sitemap_urls'])) {
                unset($options['sitemap_urls']);
            }

            // Resolve index-vs-single mode from the use_sitemap_index toggle
            // (synthesizing child sitemaps when the toggle is on but none are
            // configured, and auto-promoting an oversized single file), rather
            // than deciding purely by how many sitemap_urls happen to be present.
            $options = $this->sitemap_generator->maybe_promote_to_index($options);

            // Persist the resolved index-mode decision so a later content- or
            // settings-triggered regeneration (which reads stored settings)
            // doesn't revert a manual auto-promotion back to a single flat file.
            // generate_and_save() already does this on the automatic path; the
            // manual generate route must match it.
            $this->persist_promoted_mode($options);

            // Check if an index (multiple sitemaps) is configured
            if (!empty($options['use_sitemap_index']) || (!empty($options['sitemap_urls']) && count($options['sitemap_urls']) > 1)) {
                // Generate multiple sitemaps
                $results = $this->sitemap_generator->generate_multiple_sitemaps($options);

                if (!$results['success']) {
                    return new WP_Error(
                        'sitemap_generation_failed',
                        'Failed to generate sitemaps: ' . implode(', ', $results['errors']),
                        ['status' => 500]
                    );
                }

                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'Multiple sitemaps generated successfully',
                    'data' => [
                        'sitemaps_generated' => $results['sitemaps_generated'],
                        'total_sitemaps' => count($results['sitemaps_generated']),
                        'url_count' => $results['total_urls'],
                        'last_generated' => $this->record_generation()
                    ]
                ]);
            } else {
                // Generate single sitemap (backward compatibility)
                $sitemap_xml = $this->sitemap_generator->generate_sitemap($options);

                // Save sitemap to file (optional)
                $save_to_file = $request->get_param('save_to_file') ?? true;
                $last_generated = '';
                if ($save_to_file) {
                    $filename = 'sitemap.xml';
                    if (!empty($options['sitemap_urls'][0]['url'])) {
                        $filename = basename(wp_parse_url($options['sitemap_urls'][0]['url'], PHP_URL_PATH));
                    }
                    $this->save_sitemap_file($sitemap_xml, $filename);

                    // Regenerate the standalone local business sitemap on the
                    // single-sitemap path too (parity with Rank Math).
                    $this->sitemap_generator->regenerate_local_sitemap($options);

                    // Only record generation when the files were actually
                    // written — a preview (save_to_file=false) must not enable
                    // the "View Generated Sitemaps" links.
                    $last_generated = $this->record_generation();
                }

                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'sitemap_xml' => $sitemap_xml,
                        'sitemap_url' => home_url('/sitemap.xml'),
                        'generated_at' => gmdate('c'),
                        'url_count' => $this->count_urls_in_xml($sitemap_xml),
                        'last_generated' => $last_generated
                    ],
                    'message' => 'Sitemap generated successfully'
                ]);
            }

        } catch (\Exception $e) {
            $this->release_generation_lock();
            return new WP_Error(
                'generation_failed',
                'Sitemap generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        } finally {
            $this->release_generation_lock();
        }
    }

    /**
     * Validate sitemap
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function validate_sitemap(WP_REST_Request $request) {
        try {
            $sitemap_url = $request->get_param('sitemap_url') ?? home_url('/sitemap.xml');

            // Validate sitemap URL
            if (!filter_var($sitemap_url, FILTER_VALIDATE_URL)) {
                return new WP_Error(
                    'invalid_url',
                    'Invalid sitemap URL provided',
                    ['status' => 400]
                );
            }

            // Block SSRF: this endpoint fetches the URL server-side, so reject
            // loopback/link-local/private hosts and non-http(s) schemes via
            // WordPress's own validator (same guard used in class-schema-endpoint).
            if (!wp_http_validate_url($sitemap_url)) {
                return new WP_Error(
                    'invalid_url',
                    'The sitemap URL is not allowed.',
                    ['status' => 400]
                );
            }

            // Perform validation
            $validation_result = $this->perform_sitemap_validation($sitemap_url);

            return new WP_REST_Response([
                'success' => true,
                'data' => $validation_result,
                'message' => 'Sitemap validation completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'validation_failed',
                'Sitemap validation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get sitemap status
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_sitemap_status(WP_REST_Request $request) {
        try {
            // Get sitemap output data from generator
            $status_data = $this->sitemap_generator->get_output_data('site', null);

            // Add additional status information
            $sitemap_file_path = ABSPATH . 'sitemap.xml';
            $status_data['file_exists'] = file_exists($sitemap_file_path);
            $status_data['file_size'] = $status_data['file_exists'] ? filesize($sitemap_file_path) : 0;
            $status_data['file_modified'] = $status_data['file_exists'] ? gmdate('c', filemtime($sitemap_file_path)) : null;

            return new WP_REST_Response([
                'success' => true,
                'data' => $status_data,
                'message' => 'Sitemap status retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'status_failed',
                'Failed to get sitemap status: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Submit sitemap to search engines
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function submit_sitemap(WP_REST_Request $request) {
        // Google removed its sitemap-ping endpoint in 2023 and Bing followed suit;
        // both now discover sitemaps via robots.txt on their own schedule. There
        // is nothing to submit, so this is a no-op kept only so existing clients
        // don't 404 (mirrors ping_search_engines()).
        return new WP_REST_Response([
            'success' => true,
            'data' => [],
            'message' => 'Search engines no longer accept sitemap submission; sitemaps are discovered automatically via robots.txt.',
        ], 200);
    }

    /**
     * Ping search engines about sitemap updates (unified method)
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function ping_search_engines(WP_REST_Request $request) {
        // Google removed its sitemap-ping endpoint in 2023 and Bing followed suit;
        // both now rely on the sitemap being referenced from robots.txt and pulled
        // on their own schedule. There is nothing left to ping, so this endpoint is
        // a no-op kept only so existing clients don't 404.
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Search engines no longer support sitemap ping; sitemaps are discovered automatically via robots.txt.',
            'engines' => [],
            'timestamp' => gmdate('c')
        ], 200);
    }

    /**
     * Get sitemap statistics
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_sitemap_stats(WP_REST_Request $request) {
        try {
            $settings = $this->sitemap_generator->get_settings('site');
            
            $stats = [
                'total_urls' => $this->sitemap_generator->count_sitemap_urls($settings),
                'post_count' => $settings['include_posts'] ? wp_count_posts('post')->publish : 0,
                'page_count' => $settings['include_pages'] ? wp_count_posts('page')->publish : 0,
                'category_count' => $settings['include_categories'] ? wp_count_terms('category') : 0,
                'tag_count' => $settings['include_tags'] ? wp_count_terms('post_tag') : 0,
                'last_generated' => $settings['last_generated'] ?? null,
                'sitemap_enabled' => $settings['enabled'] ?? true
            ];

            return new WP_REST_Response([
                'success' => true,
                'data' => $stats,
                'message' => 'Sitemap statistics retrieved successfully'
            ], 200);

        } catch (\Throwable $e) {
            return new WP_Error(
                'stats_failed',
                'Failed to get sitemap statistics: ' . $e->getMessage(),
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
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_crawling');
    }

    /**
     * Save sitemap to file
     *
     * @since 1.0.0
     *
     * @param string $sitemap_xml Sitemap XML content
     * @param string $filename Optional. Filename to save (defaults to 'sitemap.xml')
     * @return bool Success status
     */
    private function save_sitemap_file(string $sitemap_xml, string $filename = 'sitemap.xml'): bool {
        // Clean filename and ensure it ends with .xml
        $filename = sanitize_file_name($filename);
        if (!str_ends_with($filename, '.xml')) {
            $filename .= '.xml';
        }

        $sitemap_file_path = ABSPATH . $filename;

        // Use WordPress filesystem API
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem->put_contents($sitemap_file_path, $sitemap_xml, FS_CHMOD_FILE);
    }

    /**
     * Count URLs in sitemap XML content
     *
     * @since 1.0.0
     *
     * @param string $sitemap_xml Sitemap XML content
     * @return int URL count
     */
    private function count_urls_in_xml(string $sitemap_xml): int {
        return substr_count($sitemap_xml, '<url>');
    }

    /**
     * Perform sitemap validation
     *
     * @since 1.0.0
     *
     * @param string $sitemap_url Sitemap URL to validate
     * @return array Validation results
     */
    private function perform_sitemap_validation(string $sitemap_url): array {
        $validation_result = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'url_count' => 0,
            'file_size' => 0
        ];

        // Check if sitemap is accessible. wp_safe_remote_get() re-applies the
        // reject-unsafe-URLs / external-host filters (incl. on redirects) so an
        // internal host can't be reached even if it slipped past validation.
        $response = wp_safe_remote_get($sitemap_url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            $validation_result['valid'] = false;
            $validation_result['errors'][] = 'Sitemap is not accessible: ' . $response->get_error_message();
            return $validation_result;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $validation_result['valid'] = false;
            $validation_result['errors'][] = "Sitemap returned HTTP status code: {$status_code}";
            return $validation_result;
        }

        $sitemap_content = wp_remote_retrieve_body($response);
        $validation_result['file_size'] = strlen($sitemap_content);
        $validation_result['url_count'] = $this->count_urls_in_xml($sitemap_content);

        // Basic XML validation
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($sitemap_content);

        if (false === $xml) {
            $validation_result['valid'] = false;
            $validation_result['errors'][] = 'Invalid XML format';

            foreach (libxml_get_errors() as $error) {
                $validation_result['errors'][] = trim($error->message);
            }
        }

        // Check file size (should be under 50MB)
        if ($validation_result['file_size'] > 50 * 1024 * 1024) {
            $validation_result['warnings'][] = 'Sitemap file size exceeds 50MB limit';
        }

        // Check URL count (should be under 50,000)
        if ($validation_result['url_count'] > 50000) {
            $validation_result['warnings'][] = 'Sitemap contains more than 50,000 URLs';
        }

        return $validation_result;
    }

    /**
     * Get arguments for generate endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_generate_args(): array {
        return [
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Sitemap generation options'
            ],
            'save_to_file' => [
                'required' => false,
                'type' => 'boolean',
                'default' => true,
                'description' => 'Save sitemap to file'
            ]
        ];
    }

    /**
     * Get arguments for validate endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_validate_args(): array {
        return [
            'sitemap_url' => [
                'required' => false,
                'type' => 'string',
                'format' => 'uri',
                'default' => home_url('/sitemap.xml'),
                'description' => 'Sitemap URL to validate'
            ]
        ];
    }

    /**
     * Get arguments for submit endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_submit_args(): array {
        return [
            'search_engines' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['google', 'bing']
                ],
                'default' => ['google', 'bing'],
                'description' => 'Search engines to submit to'
            ],
            'sitemap_url' => [
                'required' => false,
                'type' => 'string',
                'format' => 'uri',
                'default' => home_url('/sitemap.xml'),
                'description' => 'Sitemap URL to submit'
            ]
        ];
    }

    /**
     * Get sitemap settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_sitemap_settings(WP_REST_Request $request) {
        try {
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id') ?? null;

            // Get settings from Sitemap_Generator
            $settings = $this->sitemap_generator->get_settings($context_type, $context_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'context_type' => $context_type,
                    'context_id' => $context_id
                ],
                'message' => 'Sitemap settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'settings_retrieval_failed',
                'Failed to retrieve sitemap settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Update sitemap settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function update_sitemap_settings(WP_REST_Request $request) {
        try {
            $settings = $request->get_param('settings') ?? [];
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id') ?? null;

            if (empty($settings)) {
                return new WP_Error(
                    'missing_settings',
                    'Settings data is required',
                    ['status' => 400]
                );
            }

            // Save settings using Sitemap_Generator
            $success = $this->sitemap_generator->save_settings($context_type, $context_id, $settings);

            if (!$success) {
                return new WP_Error(
                    'settings_save_failed',
                    'Failed to save sitemap settings',
                    ['status' => 500]
                );
            }

            $generated_now = false;
            $sitemap_url = $this->ensure_sitemap_file($context_type, $context_id, $generated_now);

            // Rebuild the served sitemap so inclusion-rule changes take effect
            // instead of waiting for a content edit (debounced against rapid
            // successive saves). Skip when ensure_sitemap_file() just built a
            // fresh file synchronously — otherwise we'd immediately schedule a
            // second full generation of the same content.
            if (!$generated_now) {
                $this->sitemap_generator->schedule_regeneration();
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'context_type' => $context_type,
                    'context_id' => $context_id,
                    'sitemap_url' => $sitemap_url
                ],
                'message' => 'Sitemap settings saved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'settings_update_failed',
                'Failed to update sitemap settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
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
                'description' => 'Sitemap settings to save'
            ],
            'context_type' => [
                'required' => false,
                'type' => 'string',
                'default' => 'site',
                'description' => 'Context type for settings'
            ],
            'context_id' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'Context ID for settings'
            ]
        ];
    }

    /**
     * Get custom post types for sitemap generation
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_custom_post_types(WP_REST_Request $request) {
        try {
            // Get all public custom post types (excluding built-in types)
            $post_types = get_post_types([
                'public' => true,
                '_builtin' => false
            ], 'objects');

            $custom_post_types = [];
            foreach ($post_types as $post_type) {
                // Skip if it's a WooCommerce product (handled separately)
                if ($post_type->name === 'product') {
                    continue;
                }

                $custom_post_types[] = [
                    'name' => $post_type->name,
                    'label' => $post_type->label,
                    'singular_name' => $post_type->labels->singular_name ?? $post_type->label,
                    'public' => $post_type->public,
                    'has_archive' => $post_type->has_archive,
                    'count' => wp_count_posts($post_type->name)->publish ?? 0
                ];
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $custom_post_types,
                'message' => 'Custom post types retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'custom_post_types_failed',
                'Failed to get custom post types: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get WooCommerce status for sitemap generation
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_woocommerce_status(WP_REST_Request $request) {
        try {
            // Check if WooCommerce is active
            $is_woocommerce_active = class_exists('WooCommerce') && function_exists('WC');

            // Check if product post type exists
            $product_post_type_exists = post_type_exists('product');

            // Check if product category taxonomy exists
            $product_cat_taxonomy_exists = taxonomy_exists('product_cat');

            $status = [
                'is_active' => $is_woocommerce_active,
                'product_post_type_exists' => $product_post_type_exists,
                'product_cat_taxonomy_exists' => $product_cat_taxonomy_exists,
                'product_count' => $product_post_type_exists ? wp_count_posts('product')->publish ?? 0 : 0,
                'product_category_count' => $product_cat_taxonomy_exists ? wp_count_terms('product_cat') : 0
            ];

            return new WP_REST_Response([
                'success' => true,
                'data' => $status,
                'message' => 'WooCommerce status retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'woocommerce_status_failed',
                'Failed to get WooCommerce status: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Clean up old sitemap files
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function cleanup_sitemap_files(WP_REST_Request $request) {
        try {
            // Scan filesystem for actual sitemap files instead of relying on configured URLs
            $cleaned_files = [];
            $failed_files = [];

            // Common sitemap file patterns to look for
            $sitemap_patterns = [
                'sitemap*.xml',
                '*sitemap*.xml'
            ];

            // Get all XML files in root directory that match sitemap patterns
            $sitemap_files = [];
            foreach ($sitemap_patterns as $pattern) {
                $files = glob(ABSPATH . $pattern);
                if ($files) {
                    $sitemap_files = array_merge($sitemap_files, $files);
                }
            }

            // Remove duplicates and filter to only sitemap-related files
            $sitemap_files = array_unique($sitemap_files);

            foreach ($sitemap_files as $file_path) {
                $filename = basename($file_path);

                // Skip if not a sitemap file (additional safety check)
                if (!$this->is_sitemap_file($filename)) {
                    continue;
                }

                // Only delete if file exists and is in root directory (security)
                $file_dir = trailingslashit(dirname($file_path));
                $root_dir = trailingslashit(ABSPATH);

                if (file_exists($file_path) && $file_dir === $root_dir) {
                    if (wp_delete_file($file_path)) {
                        $cleaned_files[] = $filename;
                    } else {
                        $failed_files[] = $filename;
                    }
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'cleaned_files' => $cleaned_files,
                    'failed_files' => $failed_files,
                    'total_cleaned' => count($cleaned_files)
                ],
                'message' => sprintf(
                    'Cleaned up %d sitemap file(s) successfully',
                    count($cleaned_files)
                )
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'cleanup_failed',
                'Failed to clean up sitemap files: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get sitemap URLs for robots.txt integration
     *
     * Returns enabled sitemap URLs from sitemap settings for automatic
     * inclusion in robots.txt file. This eliminates the need for manual
     * sitemap URL configuration in robots.txt settings.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_robots_sitemap_urls(WP_REST_Request $request): WP_REST_Response {
        try {
            // Get sitemap settings
            $settings = $this->sitemap_generator->get_settings('site');

            // If sitemap is disabled, return empty array
            if (empty($settings['enabled'])) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'sitemap_urls' => [],
                        'enabled' => false,
                        'message' => __('Sitemap generation is disabled', 'thinkrank')
                    ]
                ], 200);
            }

            // Extract enabled sitemap URLs
            $sitemap_urls = [];
            $site_url = home_url();

            if (!empty($settings['sitemap_urls']) && is_array($settings['sitemap_urls'])) {
                foreach ($settings['sitemap_urls'] as $sitemap) {
                    if (!empty($sitemap['enabled']) && !empty($sitemap['url'])) {
                        $sitemap_urls[] = [
                            'url' => $sitemap['url'],
                            'full_url' => $site_url . $sitemap['url'],
                            'type' => $sitemap['type'] ?? 'general',
                            'type_label' => $this->get_sitemap_type_label($sitemap['type'] ?? 'general')
                        ];
                    }
                }
            }

            // Fallback to default sitemap if no URLs configured
            if (empty($sitemap_urls)) {
                $sitemap_urls[] = [
                    'url' => '/sitemap.xml',
                    'full_url' => $site_url . '/sitemap.xml',
                    'type' => 'general',
                    'type_label' => __('General', 'thinkrank')
                ];
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'sitemap_urls' => $sitemap_urls,
                    'enabled' => true,
                    'count' => count($sitemap_urls)
                ]
            ], 200);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Failed to retrieve sitemap URLs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get human-readable label for sitemap type
     *
     * @since 1.0.0
     *
     * @param string $type Sitemap type
     * @return string Human-readable label
     */
    private function get_sitemap_type_label(string $type): string {
        $labels = [
            'index' => __('Index', 'thinkrank'),
            'general' => __('General', 'thinkrank'),
            'posts' => __('Posts', 'thinkrank'),
            'pages' => __('Pages', 'thinkrank'),
            'categories' => __('Categories', 'thinkrank'),
            'tags' => __('Tags', 'thinkrank'),
            'products' => __('Products', 'thinkrank'),
            'wordpress' => __('WordPress Core', 'thinkrank'),
            'custom' => __('Custom', 'thinkrank')
        ];

        return $labels[$type] ?? ucfirst($type);
    }

    /**
     * Check rate limit for sitemap generation
     *
     * @since 1.0.0
     * @return bool True if within rate limit
     */
    private function check_rate_limit(): bool {
        $user_id = get_current_user_id();
        $rate_key = "thinkrank_sitemap_rate_{$user_id}";

        $requests = get_transient($rate_key) ?: 0;

        if ($requests >= 3) { // Max 3 requests per 5 minutes
            return false;
        }

        set_transient($rate_key, $requests + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Acquire generation lock to prevent concurrent generation
     *
     * @since 1.0.0
     * @return bool True if lock acquired
     */
    private function acquire_generation_lock(): bool {
        $lock_key = 'thinkrank_sitemap_generation_lock';

        if (get_transient($lock_key)) {
            return false; // Generation already in progress
        }

        set_transient($lock_key, time(), 5 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Release generation lock
     *
     * @since 1.0.0
     * @return void
     */
    private function release_generation_lock(): void {
        delete_transient('thinkrank_sitemap_generation_lock');
    }

    /**
     * Check if a filename is a sitemap file
     *
     * @since 1.0.0
     * @param string $filename Filename to check
     * @return bool True if it's a sitemap file
     */
    private function is_sitemap_file(string $filename): bool {
        // Must be XML file
        if (!str_ends_with($filename, '.xml')) {
            return false;
        }

        // Must contain 'sitemap' in the name
        if (stripos($filename, 'sitemap') === false) {
            return false;
        }

        // Exclude WordPress core files that aren't sitemaps
        $excluded_patterns = [
            'wp-sitemap-users-',  // WordPress user sitemaps
            'wp-sitemap-taxonomies-',  // WordPress taxonomy sitemaps
        ];

        foreach ($excluded_patterns as $pattern) {
            if (stripos($filename, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }
}
