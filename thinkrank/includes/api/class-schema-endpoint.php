<?php
/**
 * Schema API Endpoints Class
 *
 * REST API endpoints for schema markup generation, validation, and management.
 * Provides comprehensive API access to Schema Management System functionality
 * with proper authentication, validation, and error handling.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use ThinkRank\SEO\Schema_Management_System;
use ThinkRank\SEO\Schema_Input_Validator;
use ThinkRank\API\Traits\Rate_Limiter;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Load Rate Limiter trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-rate-limiter.php';

/**
 * Schema API Endpoints Class
 *
 * Provides REST API endpoints for schema markup operations including
 * generation, validation, deployment, and performance tracking with
 * proper authentication and comprehensive error handling.
 *
 * @since 1.0.0
 */
class Schema_Endpoint extends WP_REST_Controller {

    use Rate_Limiter;

    /**
     * Maximum number of items a single /bulk request may process synchronously.
     * Larger workloads should be paged or queued rather than run in one request.
     *
     * @since 1.20.1
     * @var int
     */
    private const MAX_BULK_ITEMS = 50;

    /**
     * Schema Management System instance
     *
     * @since 1.0.0
     * @var Schema_Management_System
     */
    private Schema_Management_System $schema_manager;

    /**
     * Schema Input Validator instance
     *
     * @since 1.0.0
     * @var Schema_Input_Validator
     */
    private Schema_Input_Validator $input_validator;

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
    protected $rest_base = 'schema';

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->schema_manager = new Schema_Management_System();
        $this->input_validator = new Schema_Input_Validator();
    }

    /**
     * Register API routes
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        // Generate schema markup
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/generate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'generate_schema'],
                    'permission_callback' => [$this, 'check_generate_permissions'],
                    'args' => $this->get_generate_schema_args()
                ]
            ]
        );

        // Validate schema markup
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/validate',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'validate_schema'],
                    'permission_callback' => [$this, 'check_validate_permissions'],
                    'args' => $this->get_validate_schema_args()
                ]
            ]
        );

        // Deploy schema markup
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/deploy',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'deploy_schema'],
                    'permission_callback' => [$this, 'check_deploy_permissions'],
                    'args' => $this->get_deploy_schema_args()
                ]
            ]
        );

        // Get schema types
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/types',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_schema_types'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Get deployed schemas
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/deployed',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_deployed_schemas'],
                    'permission_callback' => [$this, 'check_read_permissions']
                ]
            ]
        );

        // Get schema for context
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<context_type>[a-zA-Z0-9_-]+)/(?P<context_id>\d+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_context_schema'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'context_type' => [
                            'required' => true,
                            'type' => 'string',
                            'enum' => ['site', 'post', 'page', 'product']
                        ],
                        'context_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'minimum' => 1
                        ]
                    ]
                ]
            ]
        );

        // Optimize rich snippets
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/optimize',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'optimize_rich_snippets'],
                    'permission_callback' => [$this, 'check_optimize_permissions'],
                    'args' => $this->get_optimize_schema_args()
                ]
            ]
        );

        // Track schema performance
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/performance/(?P<context_type>[a-zA-Z0-9_-]+)/(?P<context_id>\d+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_schema_performance'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => [
                        'context_type' => [
                            'required' => true,
                            'type' => 'string',
                            'enum' => ['site', 'post', 'page', 'product']
                        ],
                        'context_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'minimum' => 1
                        ]
                    ]
                ]
            ]
        );

        // Get schema preview
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/preview',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'get_schema_preview'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args' => $this->get_preview_schema_args()
                ]
            ]
        );

        // Bulk operations
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'bulk_operations'],
                    'permission_callback' => [$this, 'check_bulk_permissions'],
                    'args' => $this->get_bulk_operations_args()
                ]
            ]
        );

        // Schema settings management
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
                    'callback' => [$this, 'save_settings'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => $this->get_settings_args()
                ]
            ]
        );

        // Import schema from URL
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/import',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'import_schema_from_url'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args' => [
                        'url' => [
                            'required' => true,
                            'type' => 'string',
                            'format' => 'uri'
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Import schema from URL
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function import_schema_from_url(WP_REST_Request $request) {
        try {
            $url = esc_url_raw($request->get_param('url'));

            if (empty($url)) {
                return new WP_Error(
                    'invalid_url',
                    'A valid URL is required',
                    ['status' => 400]
                );
            }

            // Block SSRF: reject non-http(s)/malformed URLs and any host that
            // resolves to a private, loopback, link-local, or otherwise reserved
            // IP range — including the link-local 169.254.0.0/16 (cloud metadata,
            // e.g. 169.254.169.254) and 100.64.0.0/10 (CGNAT) ranges that
            // wp_http_validate_url() does NOT block — re-validated on every
            // redirect hop. See fetch_import_url() / reject_disallowed_fetch_host().
            $response = $this->fetch_import_url($url);

            if (is_wp_error($response)) {
                // Preserve the SSRF/redirect block responses (they already carry a
                // 4xx status); wrap transport-level failures as a 500.
                $error_data = $response->get_error_data();
                if (is_array($error_data) && isset($error_data['status'])) {
                    return $response;
                }
                return new WP_Error(
                    'fetch_failed',
                    'Failed to fetch data from URL: ' . $response->get_error_message(),
                    ['status' => 500]
                );
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                return new WP_Error(
                    'fetch_error',
                    'Failed to fetch data from URL (HTTP ' . $response_code . ')',
                    ['status' => 400]
                );
            }

            $body = wp_remote_retrieve_body($response);

            if (empty($body)) {
                return new WP_Error(
                    'empty_response',
                    'Returned content is empty',
                    ['status' => 400]
                );
            }

            // Suppress DOM errors for malformed HTML
            libxml_use_internal_errors(true);

            $dom = new \DOMDocument();
            // Prepend an XML encoding hint so DOMDocument parses UTF-8 correctly.
            // Avoids the deprecated mb_convert_encoding($body, 'HTML-ENTITIES') call,
            // which emits deprecation notices on PHP 8.2+.
            $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING);

            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            $scripts = $xpath->query('//script[@type="application/ld+json"]');

            $found_schemas = [];

            if ($scripts->length > 0) {
                foreach ($scripts as $script) {
                    $json = trim($script->nodeValue);
                    $data = json_decode($json, true);

                    if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                        // Strictly set @context to https://schema.org
                        $data['@context'] = 'https://schema.org';
                        $found_schemas[] = $data;
                    }
                }
            }

            if (empty($found_schemas)) {
                return new WP_Error(
                    'no_schema_found',
                    'No valid JSON-LD schema markup found on this page',
                    ['status' => 404]
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $found_schemas[0], 
                'all_found' => $found_schemas,
                'message' => 'Schema imported successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'import_failed',
                'Schema import failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Fetch a remote URL for schema import, re-validating the host against the
     * SSRF block list on every redirect hop.
     *
     * wp_safe_remote_get() re-validates redirect targets with
     * wp_http_validate_url(), which shares the link-local/CGNAT blind spot, so
     * redirects are followed manually (redirection => 0) and each hop is checked
     * with {@see self::reject_disallowed_fetch_host()}.
     *
     * @param string $url URL to fetch.
     * @return array|\WP_Error Response array on success, WP_Error otherwise.
     */
    private function fetch_import_url(string $url) {
        $max_redirects = 5;

        for ($hop = 0; $hop <= $max_redirects; $hop++) {
            if (!wp_http_validate_url($url)) {
                return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
            }

            $host_check = $this->reject_disallowed_fetch_host($url);
            if (is_wp_error($host_check)) {
                return $host_check;
            }

            $response = wp_safe_remote_get($url, [
                'timeout'     => 15,
                'redirection' => 0, // Follow manually so every hop is re-validated.
                'user-agent'  => 'ThinkRank/1.0.0 (WordPress Schema Plugin)',
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                $location = trim((string) wp_remote_retrieve_header($response, 'location'));
                if ('' === $location) {
                    return $response; // Redirect without a target — treat as final.
                }
                // Resolve a relative Location against the current URL.
                $url = (string) \WP_Http::make_absolute_url($location, $url);
                if ('' === $url) {
                    return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
                }
                continue;
            }

            return $response;
        }

        return new WP_Error('too_many_redirects', 'The URL redirected too many times.', ['status' => 400]);
    }

    /**
     * Reject URLs whose host resolves to a private, loopback, link-local, or
     * otherwise reserved IP range.
     *
     * WordPress's wp_http_validate_url() blocks loopback and RFC1918 ranges but
     * NOT the link-local 169.254.0.0/16 (cloud metadata, e.g. 169.254.169.254)
     * or 100.64.0.0/10 (CGNAT) ranges. FILTER_FLAG_NO_RES_RANGE covers those
     * reserved ranges, and FILTER_FLAG_NO_PRIV_RANGE covers the private ranges.
     *
     * @param string $url URL to check.
     * @return true|\WP_Error True when every resolved address is public.
     */
    private function reject_disallowed_fetch_host(string $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
        }

        // Strip IPv6 literal brackets, if present.
        $host = trim($host, '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve_host_ips($host);
        if (empty($ips)) {
            return new WP_Error('invalid_url', 'The URL host could not be resolved.', ['status' => 400]);
        }

        foreach ($ips as $ip) {
            if (!$this->is_public_ip($ip)) {
                return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
            }
        }

        return true;
    }

    /**
     * Whether an IP address is a routable public address.
     *
     * Combines PHP's private/reserved-range filters with explicit blocks for
     * reserved IPv4 ranges PHP's FILTER_FLAG_NO_RES_RANGE does not cover — most
     * importantly 100.64.0.0/10 (CGNAT), which some clouds use for metadata.
     *
     * @param string $ip IP address (v4 or v6).
     * @return bool
     */
    private function is_public_ip(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Reserved IPv4 blocks PHP's NO_RES_RANGE misses.
        $extra_blocks = [
            '100.64.0.0/10',   // Shared address space / CGNAT (RFC 6598).
            '192.0.0.0/24',    // IETF protocol assignments (RFC 6890).
            '198.18.0.0/15',   // Benchmarking (RFC 2544).
            '192.88.99.0/24',  // 6to4 relay anycast (RFC 7526).
        ];
        foreach ($extra_blocks as $cidr) {
            if ($this->ipv4_in_cidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an IPv4 address falls within a CIDR block.
     *
     * @param string $ip   IPv4 address.
     * @param string $cidr CIDR block (e.g. 100.64.0.0/10).
     * @return bool
     */
    private function ipv4_in_cidr(string $ip, string $cidr): bool {
        if (strpos($ip, ':') !== false) {
            return false; // IPv6 is not covered by these IPv4 blocks.
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if (false === $ip_long || false === $subnet_long) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    /**
     * Resolve a hostname to its IPv4 + IPv6 addresses.
     *
     * @param string $host Hostname.
     * @return string[] Resolved IP addresses (may be empty).
     */
    private function resolve_host_ips(string $host): array {
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];       // A record.
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];     // AAAA record.
                }
            }
        }

        // Fallback where dns_get_record is unavailable or returns nothing.
        if (empty($ips)) {
            $resolved = gethostbyname($host);
            if ($resolved && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    /**
     * Generate schema markup
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function generate_schema(WP_REST_Request $request) {
        try {
            $user_id = get_current_user_id();

            // SECURITY: Check rate limits first
            $rate_limit_check = $this->check_rate_limit('generate_schema', $user_id);
            if (is_wp_error($rate_limit_check)) {
                return $rate_limit_check;
            }

            // SECURITY: Validate user permissions and rate limiting
            $permission_check = $this->input_validator->validate_user_permissions('generate', $user_id);
            if (!$permission_check['valid']) {
                return new WP_Error(
                    'permission_denied',
                    implode(', ', $permission_check['errors']),
                    ['status' => 403]
                );
            }

            // SECURITY: Validate and sanitize context parameters with ownership checks
            $context_type = $request->get_param('context_type');
            $context_id = $request->get_param('context_id');
            $context_validation = $this->input_validator->validate_context_parameters($context_type, $context_id, $user_id);

            if (!$context_validation['valid']) {
                return new WP_Error(
                    'invalid_context',
                    implode(', ', $context_validation['errors']),
                    ['status' => 400]
                );
            }

            $context_type = $context_validation['sanitized_data']['context_type'];
            $context_id = $context_validation['sanitized_data']['context_id'];

            // SECURITY: Validate and sanitize schema types
            $schema_types = $request->get_param('schema_types') ?? [];
            if (empty($schema_types) || !is_array($schema_types)) {
                return new WP_Error(
                    'missing_schema_types',
                    'Schema types array is required',
                    ['status' => 400]
                );
            }

            // Sanitize schema types
            $sanitized_schema_types = [];
            foreach ($schema_types as $type) {
                $sanitized_type = sanitize_text_field($type);
                if (!empty($sanitized_type)) {
                    $sanitized_schema_types[] = $sanitized_type;
                }
            }

            if (empty($sanitized_schema_types)) {
                return new WP_Error(
                    'invalid_schema_types',
                    'No valid schema types provided',
                    ['status' => 400]
                );
            }

            // SECURITY: Sanitize options
            $options = $this->input_validator->sanitize_options($request->get_param('options') ?? []);

            // SECURITY: Sanitize content_data if provided
            $content_data = $request->get_param('content_data');
            if ($content_data && is_array($content_data)) {
                $content_data = [
                    'title' => isset($content_data['title']) ? sanitize_text_field($content_data['title']) : '',
                    'description' => isset($content_data['description']) ? sanitize_textarea_field($content_data['description']) : '',
                    'content' => isset($content_data['content']) ? wp_kses_post($content_data['content']) : '',
                    'word_count' => isset($content_data['word_count']) ? (int) $content_data['word_count'] : 0,
                    'focus_keyword' => isset($content_data['focus_keyword']) ? sanitize_text_field($content_data['focus_keyword']) : '',
                    'post_type' => isset($content_data['post_type']) ? sanitize_text_field($content_data['post_type']) : '',
                    'post_url' => isset($content_data['post_url']) ? esc_url_raw($content_data['post_url']) : ''
                ];

                // Add content_data to options so schema manager can use it
                $options['content_data'] = $content_data;
            }

            // SECURITY: Sanitize schema_form_data if provided
            $schema_form_data = $request->get_param('schema_form_data');
            if ($schema_form_data && is_array($schema_form_data)) {
                // Sanitize all form fields
                $sanitized_form_data = [];
                foreach ($schema_form_data as $key => $value) {
                    if (is_string($value)) {
                        $sanitized_form_data[sanitize_key($key)] = sanitize_text_field($value);
                    } elseif (is_array($value)) {
                        // Handle array values (like features, steps, etc.)
                        $sanitized_form_data[sanitize_key($key)] = array_map('sanitize_text_field', $value);
                    } elseif (is_numeric($value)) {
                        $sanitized_form_data[sanitize_key($key)] = floatval($value);
                    }
                }

                // Add schema_form_data to options so schema manager can use it
                $options['schema_form_data'] = $sanitized_form_data;
            }

            // Generate schema markup with sanitized inputs
            $generation_results = $this->schema_manager->generate_schema_markup(
                $context_type,
                $context_id,
                $sanitized_schema_types,
                $options
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $generation_results,
                'message' => 'Schema markup generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'generation_failed',
                'Schema generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Validate schema markup
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function validate_schema(WP_REST_Request $request) {
        try {
            $user_id = get_current_user_id();

            // SECURITY: Validate user permissions and rate limiting
            $permission_check = $this->input_validator->validate_user_permissions('validate', $user_id);
            if (!$permission_check['valid']) {
                return new WP_Error(
                    'permission_denied',
                    implode(', ', $permission_check['errors']),
                    ['status' => 403]
                );
            }

            $schema_data = $request->get_param('schema_data');
            $schema_type = $request->get_param('schema_type');
            $options = $request->get_param('options') ?? [];

            // SECURITY: Validate input parameters
            if (empty($schema_data) || empty($schema_type)) {
                return new WP_Error(
                    'missing_parameters',
                    'Schema data and type are required',
                    ['status' => 400]
                );
            }

            // SECURITY: Sanitize schema type
            $schema_type = sanitize_text_field($schema_type);

            // SECURITY: Validate and sanitize schema data using input validator
            if (!is_array($schema_data)) {
                return new WP_Error(
                    'invalid_schema_data',
                    'Schema data must be an array/object',
                    ['status' => 400]
                );
            }

            $input_validation = $this->input_validator->validate_schema_data($schema_data, $schema_type);
            if (!$input_validation['valid']) {
                return new WP_Error(
                    'schema_validation_failed',
                    'Schema data validation failed: ' . implode(', ', $input_validation['errors']),
                    [
                        'status' => 400,
                        'validation_errors' => $input_validation['errors'],
                        'validation_warnings' => $input_validation['warnings']
                    ]
                );
            }

            // Use sanitized data for validation
            $sanitized_schema_data = $input_validation['sanitized_data'];

            // SECURITY: Sanitize options
            $options = $this->input_validator->sanitize_options($options);

            // Validate schema markup with sanitized data
            $validation_results = $this->schema_manager->validate_schema_markup(
                $sanitized_schema_data,
                $schema_type,
                $options
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $validation_results,
                'message' => 'Schema validation completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'validation_failed',
                'Schema validation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Deploy schema markup
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function deploy_schema(WP_REST_Request $request) {
        try {
            $user_id = get_current_user_id();

            // SECURITY: Validate user permissions and rate limiting
            $permission_check = $this->input_validator->validate_user_permissions('deploy', $user_id);
            if (!$permission_check['valid']) {
                return new WP_Error(
                    'permission_denied',
                    implode(', ', $permission_check['errors']),
                    ['status' => 403]
                );
            }

            // SECURITY: Validate and sanitize context parameters with ownership checks
            $context_type = $request->get_param('context_type');
            $context_id = $request->get_param('context_id');
            $context_validation = $this->input_validator->validate_context_parameters($context_type, $context_id, $user_id);

            if (!$context_validation['valid']) {
                return new WP_Error(
                    'invalid_context',
                    implode(', ', $context_validation['errors']),
                    ['status' => 400]
                );
            }

            $context_type = $context_validation['sanitized_data']['context_type'];
            $context_id = $context_validation['sanitized_data']['context_id'];

            // SECURITY: Validate schema data
            $schema_data = $request->get_param('schema_data');
            if (empty($schema_data) || !is_array($schema_data)) {
                return new WP_Error(
                    'invalid_schema_data',
                    'Valid schema data array is required',
                    ['status' => 400]
                );
            }

            // SECURITY: Validate each schema in the data
            $sanitized_schema_data = [];
            foreach ($schema_data as $schema_key => $schema_content) {
                $schema_key = sanitize_text_field($schema_key);

                if (!is_array($schema_content)) {
                    return new WP_Error(
                        'invalid_schema_content',
                        "Schema content for {$schema_key} must be an array",
                        ['status' => 400]
                    );
                }

                // Ensure schema has required structure fields before validation
                // Use @type from schema content if available, otherwise fall back to key
                $schema_type = isset($schema_content['@type']) ? sanitize_text_field($schema_content['@type']) : $schema_key;
                
                if (!isset($schema_content['@type'])) {
                    $schema_content['@type'] = $schema_type;
                }
                if (!isset($schema_content['@context'])) {
                    $schema_content['@context'] = 'https://schema.org';
                }

                // Validate using the actual schema type, not the key
                $input_validation = $this->input_validator->validate_schema_data($schema_content, $schema_type);
                if (!$input_validation['valid']) {
                    return new WP_Error(
                        'schema_validation_failed',
                        "Schema validation failed for {$schema_type}: " . implode(', ', $input_validation['errors']),
                        [
                            'status' => 400,
                            'schema_type' => $schema_type,
                            'validation_errors' => $input_validation['errors']
                        ]
                    );
                }

                // Store using the key (which may be unique like "Article-1")
                $sanitized_schema_data[$schema_key] = $input_validation['sanitized_data'];
            }

            // SECURITY: Sanitize options
            $options = $this->input_validator->sanitize_options($request->get_param('options') ?? []);

            // Deploy schema markup with sanitized data
            $deployment_results = $this->schema_manager->deploy_schema_markup(
                $context_type,
                $context_id,
                $sanitized_schema_data,
                $options
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $deployment_results,
                'message' => 'Schema markup deployed successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'deployment_failed',
                'Schema deployment failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get available schema types
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_schema_types(WP_REST_Request $request): WP_REST_Response {
        // Get context parameter to determine which schema types to return
        $context = $request->get_param('context') ?? 'site';

        // Site-level schema types only (post/page schemas handled by metabox)
        $site_schema_types = [
            'Organization' => [
                'name' => 'Organization',
                'description' => 'Company or organization information (site-wide)',
                'context_types' => ['site'],
                'priority' => 'high'
            ],
            'LocalBusiness' => [
                'name' => 'LocalBusiness',
                'description' => 'Local businesses and service providers (site-wide)',
                'context_types' => ['site'],
                'priority' => 'high'
            ],
            'Person' => [
                'name' => 'Person',
                'description' => 'Individual person or author information (site-wide)',
                'context_types' => ['site'],
                'priority' => 'medium'
            ],
            'WebSite' => [
                'name' => 'WebSite',
                'description' => 'Website-level information and search functionality',
                'context_types' => ['site'],
                'priority' => 'high'
            ]
        ];

        // All schema types for metabox context
        $all_schema_types = [
            'Article' => [
                'name' => 'Article',
                'description' => 'News articles, blog posts, and editorial content',
                'context_types' => ['post', 'page'],
                'priority' => 'high'
            ],
            'BlogPosting' => [
                'name' => 'BlogPosting',
                'description' => 'Blog posts and personal articles',
                'context_types' => ['post', 'page'],
                'priority' => 'high'
            ],
            'TechnicalArticle' => [
                'name' => 'TechnicalArticle',
                'description' => 'Technical documentation and tutorials',
                'context_types' => ['post', 'page'],
                'priority' => 'high'
            ],
            'NewsArticle' => [
                'name' => 'NewsArticle',
                'description' => 'News articles and press releases',
                'context_types' => ['post', 'page'],
                'priority' => 'high'
            ],
            'ScholarlyArticle' => [
                'name' => 'ScholarlyArticle',
                'description' => 'Academic and research articles',
                'context_types' => ['post', 'page'],
                'priority' => 'high'
            ],
            'Report' => [
                'name' => 'Report',
                'description' => 'Reports and analytical content',
                'context_types' => ['post', 'page'],
                'priority' => 'medium'
            ],
            'HowTo' => [
                'name' => 'HowTo',
                'description' => 'Step-by-step instructions and tutorials',
                'context_types' => ['post', 'page'],
                'priority' => 'medium'
            ],
            'FAQPage' => [
                'name' => 'FAQPage',
                'description' => 'Frequently Asked Questions pages',
                'context_types' => ['page', 'post'],
                'priority' => 'high'
            ],
            'Event' => [
                'name' => 'Event',
                'description' => 'Events, conferences, and gatherings',
                'context_types' => ['post', 'page'],
                'priority' => 'medium'
            ],
            'Product' => [
                'name' => 'Product',
                'description' => 'Products for e-commerce and retail',
                'context_types' => ['product', 'post', 'page'],
                'priority' => 'critical'
            ],
            'SoftwareApplication' => [
                'name' => 'SoftwareApplication',
                'description' => 'Software applications and web apps',
                'context_types' => ['post', 'page'],
                'priority' => 'high'
            ]
        ] + $site_schema_types;

        // Return appropriate schema types based on context
        $schema_types = ($context === 'metabox') ? $all_schema_types : $site_schema_types;

        return new WP_REST_Response([
            'success' => true,
            'data' => $schema_types,
            'message' => 'Schema types retrieved successfully'
        ], 200);
    }

    /**
     * Get deployed schemas
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_deployed_schemas(WP_REST_Request $request) {
        try {
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id');

            // Convert context_id to int if it's a valid numeric string, otherwise null
            if ($context_id !== null && is_numeric($context_id)) {
                $context_id = (int) $context_id;
            } else {
                $context_id = null;
            }

            $deployed_schemas = $this->schema_manager->get_deployed_schemas($context_type, $context_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => $deployed_schemas,
                'message' => 'Deployed schemas retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'deployed_schemas_failed',
                'Failed to retrieve deployed schemas: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get schema for specific context
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_context_schema(WP_REST_Request $request) {
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

            // Get schema output data
            $schema_data = $this->schema_manager->get_output_data($context_type, $context_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => $schema_data,
                'message' => 'Context schema retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'retrieval_failed',
                'Schema retrieval failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Optimize rich snippets
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function optimize_rich_snippets(WP_REST_Request $request) {
        try {
            $user_id = get_current_user_id();

            // SECURITY: Validate user permissions and rate limiting
            $permission_check = $this->input_validator->validate_user_permissions('optimize', $user_id);
            if (!$permission_check['valid']) {
                return new WP_Error(
                    'permission_denied',
                    implode(', ', $permission_check['errors']),
                    ['status' => 403]
                );
            }

            $schema_data = $request->get_param('schema_data');
            $schema_type = $request->get_param('schema_type');
            $options = $request->get_param('options') ?? [];

            // Validate input
            if (empty($schema_data) || empty($schema_type)) {
                return new WP_Error(
                    'missing_parameters',
                    'Schema data and type are required',
                    ['status' => 400]
                );
            }

            // Optimize rich snippets
            $optimization_results = $this->schema_manager->optimize_rich_snippets(
                $schema_data,
                $schema_type,
                $options
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $optimization_results,
                'message' => 'Rich snippets optimization completed'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'optimization_failed',
                'Rich snippets optimization failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get schema performance data
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_schema_performance(WP_REST_Request $request) {
        try {
            $context_type = $request->get_param('context_type');
            $context_id = (int) $request->get_param('context_id');
            $options = $request->get_param('options') ?? [];

            // Validate context
            if (!$this->validate_context($context_type, $context_id)) {
                return new WP_Error(
                    'invalid_context',
                    'Invalid context type or ID provided',
                    ['status' => 400]
                );
            }

            // Track schema performance
            $performance_data = $this->schema_manager->track_schema_performance(
                $context_type,
                $context_id,
                $options
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $performance_data,
                'message' => 'Schema performance data retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'performance_tracking_failed',
                'Schema performance tracking failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get schema preview
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_schema_preview(WP_REST_Request $request) {
        try {
            $schema_data = $request->get_param('schema_data');
            $schema_type = $request->get_param('schema_type');

            // Validate input
            if (empty($schema_data) || empty($schema_type)) {
                return new WP_Error(
                    'missing_parameters',
                    'Schema data and type are required',
                    ['status' => 400]
                );
            }

            // Generate preview
            $preview_data = $this->generate_preview($schema_data, $schema_type);

            return new WP_REST_Response([
                'success' => true,
                'data' => $preview_data,
                'message' => 'Schema preview generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'preview_failed',
                'Schema preview generation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Bulk operations for schema management
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function bulk_operations(WP_REST_Request $request) {
        try {
            $user_id = get_current_user_id();

            // SECURITY: Validate user permissions and rate limiting
            $permission_check = $this->input_validator->validate_user_permissions('bulk_operations', $user_id);
            if (!$permission_check['valid']) {
                return new WP_Error(
                    'permission_denied',
                    implode(', ', $permission_check['errors']),
                    ['status' => 403]
                );
            }

            $operation = $request->get_param('operation');
            $items = $request->get_param('items') ?? [];
            $options = $request->get_param('options') ?? [];

            // Validate input
            if (empty($operation) || empty($items)) {
                return new WP_Error(
                    'missing_parameters',
                    'Operation and items are required',
                    ['status' => 400]
                );
            }

            // Defensive recheck of the item cap (the REST arg maxItems already
            // enforces it, but never process an unbounded batch even if that
            // schema is bypassed).
            if (count($items) > self::MAX_BULK_ITEMS) {
                return new WP_Error(
                    'too_many_items',
                    sprintf('Bulk operations are limited to %d items per request.', self::MAX_BULK_ITEMS),
                    ['status' => 400]
                );
            }

            $results = [];
            $errors = [];

            foreach ($items as $item) {
                try {
                    if (!is_array($item)) {
                        throw new \Exception('Invalid bulk item');
                    }

                    // SECURITY: apply the same per-item context-ownership and
                    // schema validation the single-item routes enforce, and carry
                    // the validators' NORMALIZED output forward to dispatch. The
                    // bulk path previously dispatched raw context_id / schema_data
                    // with no ownership (IDOR) or size/depth/type checks, and even
                    // after validating still passed the raw item fields on.
                    $item_context_type = isset($item['context_type']) ? (string) $item['context_type'] : '';
                    $item_context_id   = isset($item['context_id']) ? (int) $item['context_id'] : null;

                    // Sanitized values actually dispatched (default to the raw
                    // context for the validate operation, which has no context).
                    $context_type = $item_context_type;
                    $context_id   = $item_context_id;

                    if ($operation === 'generate' || $operation === 'deploy') {
                        $context_check = $this->input_validator->validate_context_parameters(
                            $item_context_type,
                            $item_context_id,
                            $user_id
                        );
                        if (!$context_check['valid']) {
                            throw new \Exception(implode(', ', $context_check['errors']));
                        }
                        // Use the sanitized context, matching the single routes.
                        $context_type = $context_check['sanitized_data']['context_type'];
                        $context_id   = $context_check['sanitized_data']['context_id'];
                    }

                    // Sanitize shared options once per item, as the single routes do.
                    $item_options = $this->input_validator->sanitize_options($options);

                    switch ($operation) {
                        case 'generate':
                            // Sanitize schema types like the single generate route.
                            $raw_types = (isset($item['schema_types']) && is_array($item['schema_types']))
                                ? $item['schema_types']
                                : [];
                            $schema_types = [];
                            foreach ($raw_types as $type) {
                                $type = sanitize_text_field((string) $type);
                                if ($type !== '') {
                                    $schema_types[] = $type;
                                }
                            }
                            if (empty($schema_types)) {
                                throw new \Exception('schema_types is required');
                            }
                            $result = $this->schema_manager->generate_schema_markup(
                                $context_type,
                                $context_id,
                                $schema_types,
                                $item_options
                            );
                            break;
                        case 'validate':
                            if (!isset($item['schema_data']) || !is_array($item['schema_data'])) {
                                throw new \Exception('schema_data is required');
                            }
                            $schema_type = '';
                            if (isset($item['schema_type']) && is_string($item['schema_type'])) {
                                $schema_type = sanitize_text_field($item['schema_type']);
                            } elseif (isset($item['schema_data']['@type']) && is_string($item['schema_data']['@type'])) {
                                $schema_type = sanitize_text_field($item['schema_data']['@type']);
                            }
                            $data_check = $this->input_validator->validate_schema_data($item['schema_data'], $schema_type);
                            if (!$data_check['valid']) {
                                throw new \Exception(implode(', ', $data_check['errors']));
                            }
                            // Validate the SANITIZED data, not the raw payload.
                            $result = $this->schema_manager->validate_schema_markup(
                                $data_check['sanitized_data'],
                                $schema_type,
                                $item_options
                            );
                            break;
                        case 'deploy':
                            if (!isset($item['schema_data']) || !is_array($item['schema_data'])) {
                                throw new \Exception('schema_data is required');
                            }
                            // Mirror the single deploy route: validate EACH schema
                            // entry in the collection (type resolution + default
                            // @type/@context) and build a sanitized collection,
                            // rather than validating the whole map as one schema.
                            $sanitized_schema_data = [];
                            foreach ($item['schema_data'] as $schema_key => $schema_content) {
                                $schema_key = sanitize_text_field((string) $schema_key);
                                if (!is_array($schema_content)) {
                                    throw new \Exception("Schema content for {$schema_key} must be an array");
                                }
                                $schema_type = isset($schema_content['@type'])
                                    ? sanitize_text_field($schema_content['@type'])
                                    : $schema_key;
                                if (!isset($schema_content['@type'])) {
                                    $schema_content['@type'] = $schema_type;
                                }
                                if (!isset($schema_content['@context'])) {
                                    $schema_content['@context'] = 'https://schema.org';
                                }
                                $data_check = $this->input_validator->validate_schema_data($schema_content, $schema_type);
                                if (!$data_check['valid']) {
                                    throw new \Exception("Schema validation failed for {$schema_type}: " . implode(', ', $data_check['errors']));
                                }
                                $sanitized_schema_data[$schema_key] = $data_check['sanitized_data'];
                            }
                            $result = $this->schema_manager->deploy_schema_markup(
                                $context_type,
                                $context_id,
                                $sanitized_schema_data,
                                $item_options
                            );
                            break;
                        default:
                            throw new \Exception("Unsupported operation: {$operation}");
                    }

                    $results[] = [
                        'item' => $item,
                        'success' => true,
                        'data' => $result
                    ];

                } catch (\Exception $e) {
                    $errors[] = [
                        'item' => $item,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return new WP_REST_Response([
                'success' => empty($errors),
                'data' => [
                    'results' => $results,
                    'errors' => $errors,
                    'total_processed' => count($items),
                    'successful' => count($results),
                    'failed' => count($errors)
                ],
                'message' => "Bulk {$operation} operation completed"
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'bulk_operation_failed',
                'Bulk operation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Permission callbacks
     */

    /**
     * Check permissions for schema generation with CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_generate_permissions(WP_REST_Request $request): bool {
        // Check user capability
        if (!current_user_can('edit_posts')) {
            return false;
        }

        // SECURITY: Verify nonce for CSRF protection
        return $this->verify_request_nonce($request);
    }

    /**
     * Check permissions for schema validation with CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_validate_permissions(WP_REST_Request $request): bool {
        // Check user capability
        if (!current_user_can('edit_posts')) {
            return false;
        }

        // SECURITY: Verify nonce for CSRF protection
        return $this->verify_request_nonce($request);
    }

    /**
     * Check permissions for schema deployment with CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_deploy_permissions(WP_REST_Request $request): bool {
        // Check user capability
        if (!current_user_can('publish_posts')) {
            return false;
        }

        // SECURITY: Verify nonce for CSRF protection
        return $this->verify_request_nonce($request);
    }

    /**
     * Check permissions for reading schema data
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_read_permissions(WP_REST_Request $request): bool {
        // Schema config + deployed JSON-LD are not subscriber-visible — require
        // the same Schema management capability as the write routes.
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_schema');
    }

    /**
     * Check permissions for schema optimization with CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_optimize_permissions(WP_REST_Request $request): bool {
        // Check user capability
        if (!current_user_can('edit_posts')) {
            return false;
        }

        // SECURITY: Verify nonce for CSRF protection
        return $this->verify_request_nonce($request);
    }

    /**
     * Check permissions for bulk operations with CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_bulk_permissions(WP_REST_Request $request): bool {
        // Check user capability
        if (!\ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_schema')) {
            return false;
        }

        // SECURITY: Verify nonce for CSRF protection
        return $this->verify_request_nonce($request);
    }

    /**
     * Check permissions for managing schema settings with CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Permission status
     */
    public function check_manage_permissions(WP_REST_Request $request): bool {
        // Check user capability
        if (!\ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_schema')) {
            return false;
        }

        // SECURITY: Verify nonce for CSRF protection (only for POST requests)
        if ($request->get_method() === 'POST') {
            return $this->verify_request_nonce($request);
        }

        return true;
    }

    /**
     * Helper methods
     */

    /**
     * Verify request nonce for CSRF protection
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return bool Whether nonce is valid
     */
    private function verify_request_nonce(WP_REST_Request $request): bool {
        // Get nonce from header (preferred method for REST API)
        $nonce = $request->get_header('X-WP-Nonce');

        // Fallback to parameter if header not present
        if (!$nonce) {
            $nonce = $request->get_param('_wpnonce');
        }

        // Verify nonce
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        return true;
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
     * Generate schema preview
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type
     * @return array Preview data
     */
    private function generate_preview(array $schema_data, string $schema_type): array {
        return [
            'rich_snippets' => [
                $schema_type => $this->format_rich_snippet_preview($schema_data, $schema_type)
            ],
            'json_ld' => wp_json_encode($schema_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'validation_status' => 'pending'
        ];
    }

    /**
     * Format rich snippet preview for specific schema type
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type
     * @return array Formatted preview data
     */
    private function format_rich_snippet_preview(array $schema_data, string $schema_type): array {
        switch ($schema_type) {
            case 'Organization':
                return [
                    'title' => $schema_data['name'] ?? 'Organization Name',
                    'url' => $schema_data['url'] ?? home_url(),
                    'description' => $schema_data['description'] ?? 'Organization description',
                    'additional_info' => $this->format_organization_info($schema_data)
                ];

            case 'LocalBusiness':
                return [
                    'title' => $schema_data['name'] ?? 'Business Name',
                    'url' => $schema_data['url'] ?? home_url(),
                    'description' => $schema_data['description'] ?? 'Business description',
                    'additional_info' => $this->format_local_business_info($schema_data)
                ];

            case 'Article':
                return [
                    'title' => $schema_data['headline'] ?? $schema_data['name'] ?? 'Article Title',
                    'url' => $schema_data['url'] ?? home_url(),
                    'description' => $schema_data['description'] ?? 'Article description',
                    'additional_info' => $this->format_article_info($schema_data)
                ];

            default:
                return [
                    'title' => $schema_data['headline'] ?? $schema_data['name'] ?? 'Title',
                    'url' => $schema_data['url'] ?? home_url(),
                    'description' => $schema_data['description'] ?? 'Description',
                    'additional_info' => ''
                ];
        }
    }
    private function format_organization_info(array $schema_data): string {
        $info = [];

        if (!empty($schema_data['contactPoint']['telephone'])) {
            $info[] = '📞 ' . $schema_data['contactPoint']['telephone'];
        }

        if (!empty($schema_data['contactPoint']['email'])) {
            $info[] = '✉️ ' . $schema_data['contactPoint']['email'];
        }

        if (!empty($schema_data['address']['streetAddress'])) {
            $info[] = '📍 ' . $schema_data['address']['streetAddress'];
        }

        return implode(' • ', $info);
    }

    /**
     * Format local business additional info
     *
     * @since 1.0.0
     *
     * @param array $schema_data Schema data
     * @return string Formatted info
     */
    private function format_local_business_info(array $schema_data): string {
        $info = [];

        // Address
        if (!empty($schema_data['address'])) {
            $address = $schema_data['address'];
            $address_parts = [];

            if (!empty($address['streetAddress'])) {
                $address_parts[] = $address['streetAddress'];
            }
            if (!empty($address['addressLocality'])) {
                $address_parts[] = $address['addressLocality'];
            }

            if (!empty($address_parts)) {
                $info[] = '📍 ' . implode(', ', $address_parts);
            }
        }

        // Phone
        if (!empty($schema_data['telephone'])) {
            $info[] = '📞 ' . $schema_data['telephone'];
        }

        // Opening hours
        if (!empty($schema_data['openingHours'])) {
            $hours = is_array($schema_data['openingHours'])
                ? implode(', ', $schema_data['openingHours'])
                : $schema_data['openingHours'];
            $info[] = '🕒 ' . $hours;
        }

        return implode(' • ', $info);
    }

    /**
     * Format article additional info
     *
     * @since 1.0.0
     *
     * @param array $schema_data Schema data
     * @return string Formatted info
     */
    private function format_article_info(array $schema_data): string {
        $info = [];

        if (!empty($schema_data['author']['name'])) {
            $info[] = '👤 By ' . $schema_data['author']['name'];
        }

        if (!empty($schema_data['datePublished'])) {
            $info[] = '📅 ' . gmdate('M j, Y', strtotime($schema_data['datePublished']));
        }

        if (!empty($schema_data['publisher']['name'])) {
            $info[] = '🏢 ' . $schema_data['publisher']['name'];
        }

        return implode(' • ', $info);
    }

    /**
     * Argument validation methods
     */

    /**
     * Get arguments for schema generation endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_generate_schema_args(): array {
        return [
            'context_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['site', 'post', 'page', 'product'],
                'description' => 'Context type for schema generation'
            ],
            'context_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Context ID (not required for site context)'
            ],
            'schema_types' => [
                'required' => false,
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => [
                        'Article', 'BlogPosting', 'TechnicalArticle', 'NewsArticle',
                        'ScholarlyArticle', 'Report', 'Product', 'Organization',
                        'LocalBusiness', 'Person', 'WebSite', 'FAQPage',
                        'Event', 'HowTo', 'SoftwareApplication'
                    ]
                ],
                'description' => 'Schema types to generate'
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Additional generation options'
            ],
            'content_data' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Custom content data to use for schema generation (overrides post data)',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'focus_keyword' => ['type' => 'string'],
                    'post_type' => ['type' => 'string'],
                    'post_url' => ['type' => 'string']
                ]
            ]
        ];
    }

    /**
     * Get arguments for schema validation endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_validate_schema_args(): array {
        return [
            'schema_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Schema data to validate'
            ],
            'schema_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => [
                    'Article', 'BlogPosting', 'TechnicalArticle', 'NewsArticle',
                    'ScholarlyArticle', 'Report', 'Product', 'Organization',
                    'LocalBusiness', 'Person', 'WebSite', 'WebPage', 'FAQPage',
                    'SoftwareApplication', 'Event', 'Recipe', 'HowTo'
                ],
                'description' => 'Schema type'
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Validation options'
            ]
        ];
    }

    /**
     * Get arguments for schema deployment endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_deploy_schema_args(): array {
        return [
            'context_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['site', 'post', 'page', 'product'],
                'description' => 'Context type for deployment'
            ],
            'context_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Context ID (not required for site context)'
            ],
            'schema_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Schema data to deploy'
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Deployment options'
            ]
        ];
    }

    /**
     * Get arguments for schema optimization endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_optimize_schema_args(): array {
        return [
            'schema_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Schema data to optimize'
            ],
            'schema_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => [
                    'Article', 'BlogPosting', 'Product', 'Organization', 'LocalBusiness',
                    'Person', 'WebSite', 'WebPage', 'FAQPage', 'SoftwareApplication',
                    'BreadcrumbList', 'Event', 'Recipe', 'HowTo'
                ],
                'description' => 'Schema type'
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Optimization options'
            ]
        ];
    }

    /**
     * Get arguments for schema preview endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_preview_schema_args(): array {
        return [
            'schema_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Schema data to preview'
            ],
            'schema_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => [
                    'Article', 'BlogPosting', 'Product', 'Organization', 'LocalBusiness',
                    'Person', 'WebSite', 'WebPage', 'FAQPage', 'SoftwareApplication',
                    'BreadcrumbList', 'Event', 'Recipe', 'HowTo'
                ],
                'description' => 'Schema type'
            ]
        ];
    }

    /**
     * Get arguments for bulk operations endpoint
     *
     * @since 1.0.0
     *
     * @return array Arguments array
     */
    private function get_bulk_operations_args(): array {
        return [
            'operation' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['generate', 'validate', 'deploy'],
                'description' => 'Bulk operation type'
            ],
            'items' => [
                'required' => true,
                'type' => 'array',
                'items' => [
                    'type' => 'object'
                ],
                // Bound aggregate request work: every item can trigger context
                // lookups, recursive schema validation, generation, and
                // deployment, so cap the count at the REST layer.
                'maxItems' => self::MAX_BULK_ITEMS,
                'description' => 'Items to process in bulk (max ' . self::MAX_BULK_ITEMS . ')'
            ],
            'options' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Bulk operation options'
            ]
        ];
    }

    /**
     * Get schema settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_settings(WP_REST_Request $request) {
        try {
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id') ?? null;

            // Get settings from schema manager
            $settings = $this->schema_manager->get_settings($context_type, $context_id);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'context_type' => $context_type,
                    'context_id' => $context_id
                ],
                'message' => 'Schema settings retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'settings_fetch_failed',
                'Failed to retrieve schema settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Save schema settings
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function save_settings(WP_REST_Request $request) {
        try {
            $settings = $request->get_param('settings');
            $context_type = $request->get_param('context_type') ?? 'site';
            $context_id = $request->get_param('context_id') ?? null;

            // Validate input parameters
            if (empty($settings) || !is_array($settings)) {
                return new WP_Error(
                    'invalid_settings',
                    'Settings parameter is required and must be an array',
                    ['status' => 400]
                );
            }

            // SECURITY: For non-site contexts (post/page/product), verify the
            // caller can edit that specific object — same ownership gate the
            // generate/deploy routes use. Site context stays governed by the
            // thinkrank_schema capability via the Role Manager gate.
            $context_type = sanitize_key((string) $context_type);
            if ($context_type !== 'site') {
                $context_validation = $this->input_validator->validate_context_parameters(
                    $context_type,
                    $context_id !== null ? absint($context_id) : null,
                    get_current_user_id()
                );
                if (!$context_validation['valid']) {
                    return new WP_Error(
                        'invalid_context',
                        implode(', ', $context_validation['errors']),
                        ['status' => 403]
                    );
                }
                $context_type = $context_validation['sanitized_data']['context_type'];
                $context_id   = $context_validation['sanitized_data']['context_id'];
            }

            // Drop unrecognized keys so arbitrary client-supplied keys aren't
            // persisted as settings rows (storage bloat / settings drift).
            $settings = $this->filter_known_setting_keys($settings, $context_type);
            if (empty($settings)) {
                return new WP_Error(
                    'invalid_settings',
                    'No recognized schema settings were provided',
                    ['status' => 400]
                );
            }

            // Get validation results for detailed error reporting
            $validation = $this->schema_manager->validate_settings($settings);

            if (!$validation['valid']) {
                // Schema settings validation failed - details available in validation response

                return new WP_Error(
                    'validation_failed',
                    'Schema settings validation failed',
                    [
                        'status' => 400,
                        'validation_errors' => $validation['errors'],
                        'validation_warnings' => $validation['warnings'] ?? [],
                        'validation_suggestions' => $validation['suggestions'] ?? []
                    ]
                );
            }

            // Save settings using schema manager
            $success = $this->schema_manager->save_settings($context_type, $context_id, $settings);

            if (!$success) {
                // Schema settings save failed - database operation unsuccessful

                return new WP_Error(
                    'settings_save_failed',
                    'Failed to save schema settings to database',
                    ['status' => 500]
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'context_type' => $context_type,
                    'context_id' => $context_id,
                    'validation' => $validation
                ],
                'message' => 'Schema settings saved successfully'
            ], 200);

        } catch (\Exception $e) {
            // Schema settings save exception - error details in response

            return new WP_Error(
                'settings_update_failed',
                'Failed to update schema settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Restrict a settings payload to recognized keys.
     *
     * The known set is the context's default settings plus a few keys that are
     * legitimately stored/consumed elsewhere (site-identity/local-SEO fields and
     * the schema settings schema) but not seeded into the defaults. Filterable
     * so Pro/integrations can register additional keys.
     *
     * @param array  $settings     Incoming settings.
     * @param string $context_type Context type (site/post/page/product).
     * @return array Settings limited to known keys.
     */
    private function filter_known_setting_keys(array $settings, string $context_type): array {
        $known = array_keys(\ThinkRank\Config\Schema_Settings_Config::get_default_settings($context_type));
        // Keys stored/consumed by adjacent features that share the settings
        // store but aren't part of the schema defaults.
        $known = array_merge($known, array_keys(\ThinkRank\Config\Schema_Settings_Config::get_settings_schema($context_type)), [
            'business_name', 'site_name', 'logo_url', 'performance_tracking',
        ]);
        $known = apply_filters('thinkrank_schema_known_setting_keys', $known, $context_type);

        return array_intersect_key($settings, array_flip($known));
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
                'description' => 'Schema settings object'
            ],
            'context_type' => [
                'required' => false,
                'type' => 'string',
                'default' => 'site',
                'enum' => ['site', 'post', 'page', 'product'],
                'description' => 'Context type for settings'
            ],
            'context_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Context ID for settings'
            ]
        ];
    }
}
