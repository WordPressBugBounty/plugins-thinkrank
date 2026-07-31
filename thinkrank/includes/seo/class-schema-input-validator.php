<?php
/**
 * Schema Input Validator Class
 *
 * Provides comprehensive input validation and sanitization for schema data
 * including JSON schema validation, XSS protection, and data integrity checks.
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema Input Validator Class
 *
 * Handles validation and sanitization of all schema-related inputs
 * with comprehensive security measures and data integrity checks.
 *
 * @since 1.0.0
 */
class Schema_Input_Validator {

    /**
     * Allowed schema types with their validation rules
     *
     * @since 1.0.0
     * @var array
     */
    private array $allowed_schema_types = [
        'Article' => [
            'required_fields' => ['@type', 'headline', 'author'],
            'optional_fields' => ['description', 'datePublished', 'dateModified', 'image', 'url'],
            'max_length' => ['headline' => 110, 'description' => 160]
        ],
        'BlogPosting' => [
            'required_fields' => ['@type', 'headline', 'author'],
            'optional_fields' => ['description', 'datePublished', 'dateModified', 'image', 'url'],
            'max_length' => ['headline' => 110, 'description' => 160]
        ],
        'TechnicalArticle' => [
            'required_fields' => ['@type', 'headline', 'author'],
            'optional_fields' => ['description', 'datePublished', 'dateModified', 'image', 'url', 'dependencies', 'proficiencyLevel'],
            'max_length' => ['headline' => 110, 'description' => 160]
        ],
        'NewsArticle' => [
            'required_fields' => ['@type', 'headline', 'author'],
            'optional_fields' => ['description', 'datePublished', 'dateModified', 'image', 'url', 'dateline'],
            'max_length' => ['headline' => 110, 'description' => 160]
        ],
        'ScholarlyArticle' => [
            'required_fields' => ['@type', 'headline', 'author'],
            'optional_fields' => ['description', 'datePublished', 'dateModified', 'image', 'url', 'citation', 'abstract'],
            'max_length' => ['headline' => 110, 'description' => 160]
        ],
        'Report' => [
            'required_fields' => ['@type', 'headline', 'author'],
            'optional_fields' => ['description', 'datePublished', 'dateModified', 'image', 'url'],
            'max_length' => ['headline' => 110, 'description' => 160]
        ],
        'Organization' => [
            'required_fields' => ['@type', 'name'],
            'optional_fields' => ['description', 'url', 'logo', 'address', 'contactPoint'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'LocalBusiness' => [
            'required_fields' => ['@type', 'name', 'address'],
            'optional_fields' => ['description', 'url', 'telephone', 'openingHours'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'Product' => [
            'required_fields' => ['@type', 'name'],
            'optional_fields' => ['description', 'image', 'brand', 'offers'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'WebSite' => [
            'required_fields' => ['@type', 'name', 'url'],
            'optional_fields' => ['description', 'potentialAction'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'FAQPage' => [
            'required_fields' => ['@type', 'mainEntity'],
            'optional_fields' => ['name', 'description'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'SoftwareApplication' => [
            'required_fields' => ['@type', 'name'],
            'optional_fields' => ['description', 'applicationCategory', 'operatingSystem'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'Event' => [
            'required_fields' => ['@type', 'name', 'startDate'],
            'optional_fields' => ['description', 'location', 'organizer', 'endDate', 'eventStatus', 'eventAttendanceMode', 'url'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'Person' => [
            'required_fields' => ['@type', 'name'],
            'optional_fields' => ['description', 'url', 'image', 'jobTitle'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'HowTo' => [
            'required_fields' => ['@type', 'name'],
            'optional_fields' => ['description', 'totalTime', 'prepTime', 'difficulty', 'estimatedCost', 'supply', 'tool', 'step', 'yield', 'image', 'video'],
            'max_length' => ['name' => 100, 'description' => 160]
        ],
        'BreadcrumbList' => [
	        'required_fields' => ['@type', 'itemListElement'],
	        'optional_fields' => ['name', 'description', 'numberOfItems'],
	        'max_length' => ['name' => 100, 'description' => 160]
        ],
        'VideoObject' => [
            'required_fields' => ['@type', 'name', 'thumbnailUrl', 'uploadDate'],
            'optional_fields' => ['description', 'contentUrl', 'embedUrl', 'duration', 'url'],
            'max_length' => ['name' => 110, 'description' => 160]
        ]
    ];

    /**
     * Dangerous HTML tags and attributes to strip
     *
     * @since 1.0.0
     * @var array
     */
    private array $dangerous_tags = [
        'script', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'link', 'meta', 'style', 'base', 'frame', 'frameset'
    ];

    /**
     * Allowed URL protocols
     *
     * @since 1.0.0
     * @var array
     */
    private array $allowed_protocols = ['http', 'https', 'mailto', 'tel'];

    /**
     * Maximum allowed JSON depth to prevent JSON bomb attacks
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_JSON_DEPTH = 10;

    /**
     * Maximum payload size in bytes (500KB)
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_PAYLOAD_SIZE = 512000;

    /**
     * Maximum array size (number of elements)
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_ARRAY_SIZE = 100;

    /**
     * Maximum string length for any single field
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_STRING_LENGTH = 10000;

    /**
     * Validate and sanitize schema data
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Raw schema data
     * @param string $schema_type Schema type
     * @return array Validation result with sanitized data
     */
    public function validate_schema_data(array $schema_data, string $schema_type): array {
        $result = [
            'valid' => false,
            'sanitized_data' => [],
            'errors' => [],
            'warnings' => []
        ];

        try {
            // 1. Validate payload size to prevent DoS attacks
            $size_validation = $this->validate_payload_size($schema_data);
            if (!$size_validation['valid']) {
                $result['errors'] = array_merge($result['errors'], $size_validation['errors']);
                return $result;
            }

            // 2. Validate schema type
            if (!$this->is_valid_schema_type($schema_type)) {
                $result['errors'][] = "Invalid schema type: {$schema_type}";
                return $result;
            }

            // 3. Validate JSON structure and depth
            $structure_validation = $this->validate_json_structure($schema_data, $schema_type);
            if (!$structure_validation['valid']) {
                $result['errors'] = array_merge($result['errors'], $structure_validation['errors']);
                return $result;
            }

            // 3.1. Validate JSON depth to prevent JSON bomb attacks
            if (!$this->validate_json_depth($schema_data)) {
                $result['errors'][] = 'Schema data exceeds maximum allowed depth (' . self::MAX_JSON_DEPTH . ' levels)';
                return $result;
            }

            // 4. Sanitize all input data
            $sanitized_data = $this->sanitize_schema_data($schema_data);

            // 5. Validate required fields
            $field_validation = $this->validate_required_fields($sanitized_data, $schema_type);
            if (!$field_validation['valid']) {
                $result['errors'] = array_merge($result['errors'], $field_validation['errors']);
            }

            // 6. Validate data types and formats
            $format_validation = $this->validate_data_formats($sanitized_data, $schema_type);
            if (!$format_validation['valid']) {
                $result['errors'] = array_merge($result['errors'], $format_validation['errors']);
            }
            $result['warnings'] = array_merge($result['warnings'], $format_validation['warnings']);

            // 7. Validate content length limits
            $length_validation = $this->validate_content_lengths($sanitized_data, $schema_type);
            if (!$length_validation['valid']) {
                $result['warnings'] = array_merge($result['warnings'], $length_validation['warnings']);
            }

            $result['valid'] = empty($result['errors']);
            $result['sanitized_data'] = $sanitized_data;

        } catch (\Exception $e) {
            $result['errors'][] = 'Schema validation failed: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Validate payload size to prevent DoS attacks
     *
     * @since 1.0.0
     *
     * @param array $schema_data Schema data to validate
     * @return array Validation result
     */
    private function validate_payload_size(array $schema_data): array {
        $result = ['valid' => true, 'errors' => []];

        // Calculate approximate payload size
        $payload_size = strlen(wp_json_encode($schema_data));

        if ($payload_size > self::MAX_PAYLOAD_SIZE) {
            $result['errors'][] = sprintf(
                'Payload size (%s) exceeds maximum allowed size (%s)',
                size_format($payload_size),
                size_format(self::MAX_PAYLOAD_SIZE)
            );
            $result['valid'] = false;
        }

        // Validate array sizes and string lengths recursively
        $structure_validation = $this->validate_data_structure($schema_data);
        if (!$structure_validation['valid']) {
            $result['errors'] = array_merge($result['errors'], $structure_validation['errors']);
            $result['valid'] = false;
        }

        return $result;
    }

    /**
     * Validate data structure (arrays and strings)
     *
     * @since 1.0.0
     *
     * @param mixed  $data Data to validate
     * @param string $path Current path for error reporting
     * @return array Validation result
     */
    private function validate_data_structure($data, string $path = ''): array {
        $result = ['valid' => true, 'errors' => []];

        if (is_array($data)) {
            // Check array size
            if (count($data) > self::MAX_ARRAY_SIZE) {
                $result['errors'][] = sprintf(
                    'Array at path "%s" contains %d elements, maximum allowed is %d',
                    $path ?: 'root',
                    count($data),
                    self::MAX_ARRAY_SIZE
                );
                $result['valid'] = false;
            }

            // Recursively validate nested data
            foreach ($data as $key => $value) {
                $current_path = $path ? "{$path}.{$key}" : $key;
                $nested_validation = $this->validate_data_structure($value, $current_path);
                if (!$nested_validation['valid']) {
                    $result['errors'] = array_merge($result['errors'], $nested_validation['errors']);
                    $result['valid'] = false;
                }
            }
        } elseif (is_string($data)) {
            // Check string length
            if (strlen($data) > self::MAX_STRING_LENGTH) {
                $result['errors'][] = sprintf(
                    'String at path "%s" is %d characters, maximum allowed is %d',
                    $path ?: 'value',
                    strlen($data),
                    self::MAX_STRING_LENGTH
                );
                $result['valid'] = false;
            }
        }

        return $result;
    }

    /**
     * Validate schema type
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type to validate
     * @return bool Validation result
     */
    private function is_valid_schema_type(string $schema_type): bool {
        return isset($this->allowed_schema_types[$schema_type]);
    }

    /**
     * Validate JSON structure
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type
     * @return array Validation result
     */
    private function validate_json_structure(array $schema_data, string $schema_type): array {
        $result = ['valid' => true, 'errors' => []];

        // Check for required @context
        if (!isset($schema_data['@context'])) {
            $result['errors'][] = 'Missing required @context field';
            $result['valid'] = false;
        } elseif ($schema_data['@context'] !== 'https://schema.org') {
            $result['errors'][] = 'Invalid @context value. Must be "https://schema.org"';
            $result['valid'] = false;
        }

        // Check for required @type
        if (!isset($schema_data['@type'])) {
            $result['errors'][] = 'Missing required @type field';
            $result['valid'] = false;
        } elseif ($schema_data['@type'] !== $schema_type) {
            $result['errors'][] = "Schema @type '{$schema_data['@type']}' does not match expected type '{$schema_type}'";
            $result['valid'] = false;
        }

        return $result;
    }

    /**
     * Sanitize schema data recursively
     *
     * @since 1.0.0
     *
     * @param mixed  $data      Data to sanitize
     * @param string $field_key Current field key for context-aware sanitization
     * @return mixed Sanitized data
     */
    private function sanitize_schema_data($data, string $field_key = '') {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitized_key = $this->sanitize_key($key);
                $sanitized[$sanitized_key] = $this->sanitize_schema_data($value, $sanitized_key);
            }
            return $sanitized;
        }

        if (is_string($data)) {
            return $this->sanitize_string_value($data, $field_key);
        }

        if (is_numeric($data)) {
            return $this->sanitize_numeric_value($data);
        }

        if (is_bool($data)) {
            return $data;
        }

        // For other types, convert to string and sanitize
        return $this->sanitize_string_value((string) $data, $field_key);
    }

    /**
     * Sanitize array key
     *
     * @since 1.0.0
     *
     * @param string $key Array key
     * @return string Sanitized key
     */
    private function sanitize_key($key): string {
        // Ensure key is a string first
        if (!is_string($key)) {
            return (string) $key;
        }

        // For schema data, preserve the original key names to maintain case sensitivity
        // Schema.org properties are case-sensitive (e.g., startDate, not startdate)
        // Only do basic validation without changing the case
        if (preg_match('/^[a-zA-Z@][a-zA-Z0-9@_-]*$/', $key)) {
            return $key; // Return as-is if it's a valid schema property name
        }

        // Fallback to WordPress sanitization for invalid keys
        return sanitize_key($key);
    }

    /**
     * Sanitize string value with context-aware sanitization
     *
     * @since 1.0.0
     *
     * @param string $value String value
     * @param string $field_name Field name for context-aware sanitization
     * @return string Sanitized value
     */
    private function sanitize_string_value(string $value, string $field_name = ''): string {
        // Handle URLs differently to preserve valid URL structure
        if (in_array($field_name, ['url', 'sameAs', 'logo', 'image', 'mainEntityOfPage'])) {
            return esc_url_raw($value);
        }

        // Handle email fields
        if (in_array($field_name, ['email'])) {
            return sanitize_email($value);
        }

        // Handle description fields that may contain basic HTML
        if (in_array($field_name, ['description', 'text', 'articleBody'])) {
            // Allow basic HTML but strip dangerous tags
            $allowed_html = [
                'p' => [],
                'br' => [],
                'strong' => [],
                'em' => [],
                'b' => [],
                'i' => []
            ];
            $value = wp_kses($value, $allowed_html);
        } else {
            // For other fields, remove all HTML tags
            $value = wp_strip_all_tags($value);
        }

        // Sanitize for database storage. Note: escaping is intentionally NOT done
        // here. This value is stored and later emitted as JSON-LD inside a
        // <script type="application/ld+json"> block, where wp_json_encode() is the
        // correct encoder. Running esc_html() on input would persist HTML entities
        // (e.g. "Ben & Jerry's" -> "Ben &amp; Jerry&#039;s") into the structured
        // data. Escape at the output boundary, not at storage.
        $value = sanitize_text_field($value);

        return trim($value);
    }

    /**
     * Sanitize numeric value
     *
     * @since 1.0.0
     *
     * @param mixed $value Numeric value
     * @return float|int Sanitized numeric value
     */
    private function sanitize_numeric_value($value) {
        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }
        
        return (float) $value;
    }

    /**
     * Validate required fields
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type
     * @return array Validation result
     */
    private function validate_required_fields(array $schema_data, string $schema_type): array {
        $result = ['valid' => true, 'errors' => []];
        $rules = $this->allowed_schema_types[$schema_type];

        foreach ($rules['required_fields'] as $field) {
            if (!isset($schema_data[$field]) || empty($schema_data[$field])) {
                $result['errors'][] = "Missing required field: {$field}";
                $result['valid'] = false;
            }
        }
        return $result;
    }

    /**
     * Validate data formats
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type
     * @return array Validation result
     */
    private function validate_data_formats(array $schema_data, string $schema_type): array {
        $result = ['valid' => true, 'errors' => [], 'warnings' => []];

        foreach ($schema_data as $field => $value) {
            if (is_string($value)) {
                // Validate URLs
                if (in_array($field, ['url', 'sameAs', 'logo', 'image']) && !empty($value)) {
                    if (!$this->is_valid_url($value)) {
                        $result['errors'][] = "Invalid URL format for field: {$field}";
                        $result['valid'] = false;
                    }
                }

                // Validate email addresses
                if (in_array($field, ['email']) && !empty($value)) {
                    if (!is_email($value)) {
                        $result['errors'][] = "Invalid email format for field: {$field}";
                        $result['valid'] = false;
                    }
                }

                // Validate dates
                if (in_array($field, ['datePublished', 'dateModified']) && !empty($value)) {
                    if (!$this->is_valid_date($value)) {
                        $result['warnings'][] = "Invalid date format for field: {$field}. Use ISO 8601 format.";
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Validate content lengths
     *
     * @since 1.0.0
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type
     * @return array Validation result
     */
    private function validate_content_lengths(array $schema_data, string $schema_type): array {
        $result = ['valid' => true, 'warnings' => []];
        $rules = $this->allowed_schema_types[$schema_type];

        if (isset($rules['max_length'])) {
            foreach ($rules['max_length'] as $field => $max_length) {
                if (isset($schema_data[$field]) && is_string($schema_data[$field])) {
                    $length = strlen($schema_data[$field]);
                    if ($length > $max_length) {
                        $result['warnings'][] = "Field '{$field}' exceeds recommended length of {$max_length} characters (current: {$length})";
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Validate URL format and protocol
     *
     * @since 1.0.0
     *
     * @param string $url URL to validate
     * @return bool Validation result
     */
    private function is_valid_url(string $url): bool {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Check allowed protocols
        $parsed = wp_parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], $this->allowed_protocols)) {
            return false;
        }

        return true;
    }

    /**
     * Validate date format
     *
     * @since 1.0.0
     *
     * @param string $date Date to validate
     * @return bool Validation result
     */
    private function is_valid_date(string $date): bool {
        // Check ISO 8601 format
        $formats = [
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y-m-d'
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $date);
            if ($parsed && $parsed->format($format) === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check rate limiting for user
     *
     * @since 1.0.0
     *
     * @param int    $user_id User ID
     * @param string $action  Action type
     * @param int    $limit   Rate limit (requests per hour)
     * @return bool Whether request is allowed
     */
    public function check_rate_limit(int $user_id, string $action, int $limit = 100): bool {
        // Persist the window in a transient (object cache / options) so the limit
        // is enforced ACROSS requests. A per-request static array — as used
        // previously — always starts empty on a fresh PHP process and therefore
        // never throttled anything.
        $key = 'thinkrank_schema_rl_' . $user_id . '_' . sanitize_key($action);

        // Serialize the read-modify-write with a MySQL named lock so concurrent
        // requests can't each read the same timestamp list, individually pass the
        // limit check, and overwrite one another — which would let bursts slip
        // past the configured limit. GET_LOCK is DB-level, so it serializes the
        // critical section regardless of where the transient is stored.
        global $wpdb;
        $lock_name = substr('tr_schema_rl_' . md5($key), 0, 64);
        $have_lock = ($wpdb instanceof \wpdb)
            ? (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 3)) === 1
            : false;

        try {
            $current_time = time();
            $window_start = $current_time - HOUR_IN_SECONDS; // 1 hour window

            $timestamps = get_transient($key);
            if (!is_array($timestamps)) {
                $timestamps = [];
            }

            // Drop entries outside the window.
            $timestamps = array_values(array_filter(
                $timestamps,
                static function ($timestamp) use ($window_start) {
                    return (int) $timestamp > $window_start;
                }
            ));

            // Check if limit exceeded.
            if (count($timestamps) >= $limit) {
                set_transient($key, $timestamps, HOUR_IN_SECONDS);
                return false;
            }

            // Record this request.
            $timestamps[] = $current_time;
            set_transient($key, $timestamps, HOUR_IN_SECONDS);

            return true;
        } finally {
            if ($have_lock) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
        }
    }

    /**
     * Validate user permissions for schema operations
     *
     * @since 1.0.0
     *
     * @param string $operation Operation type
     * @param int    $user_id   User ID
     * @return array Validation result
     */
    public function validate_user_permissions(string $operation, int $user_id): array {
        $result = ['valid' => false, 'errors' => []];

        // Check if user exists and is logged in
        if (!$user_id || !get_userdata($user_id)) {
            $result['errors'][] = 'Invalid user or user not logged in';
            return $result;
        }

        // Check operation-specific permissions
        switch ($operation) {
            case 'generate':
            case 'validate':
            case 'optimize':
                if (!user_can($user_id, 'edit_posts')) {
                    $result['errors'][] = 'Insufficient permissions for schema generation/validation';
                    return $result;
                }
                break;

            case 'deploy':
                if (!user_can($user_id, 'publish_posts')) {
                    $result['errors'][] = 'Insufficient permissions for schema deployment';
                    return $result;
                }
                break;

            case 'manage_settings':
            case 'bulk_operations':
                if (!user_can($user_id, 'manage_options')) {
                    $result['errors'][] = 'Insufficient permissions for schema management';
                    return $result;
                }
                break;

            default:
                $result['errors'][] = "Unknown operation: {$operation}";
                return $result;
        }

        // Check rate limiting
        $rate_limits = [
            'generate' => 50,   // 50 generations per hour
            'validate' => 100,  // 100 validations per hour
            'deploy' => 20,     // 20 deployments per hour
            'optimize' => 30,   // 30 optimizations per hour
            'bulk_operations' => 5  // 5 bulk operations per hour
        ];

        $limit = $rate_limits[$operation] ?? 100;
        if (!$this->check_rate_limit($user_id, $operation, $limit)) {
            $result['errors'][] = "Rate limit exceeded for {$operation}. Please try again later.";
            return $result;
        }

        $result['valid'] = true;
        return $result;
    }

    /**
     * Sanitize and validate context parameters with ownership checks
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @param int|null $user_id      User ID for ownership validation
     * @return array Validation result
     */
    public function validate_context_parameters(string $context_type, ?int $context_id, ?int $user_id = null): array {
        $result = ['valid' => false, 'errors' => [], 'sanitized_data' => []];

        // Sanitize context type
        $context_type = sanitize_key($context_type);
        $allowed_types = ['site', 'post', 'page', 'product'];

        if (!in_array($context_type, $allowed_types, true)) {
            $result['errors'][] = "Invalid context type: {$context_type}";
            return $result;
        }

        // Validate context ID and ownership
        if ($context_type !== 'site') {
            if (!$context_id || $context_id <= 0) {
                $result['errors'][] = 'Context ID is required for non-site contexts';
                return $result;
            }

            $context_id = absint($context_id);
            $post = get_post($context_id);

            if (!$post) {
                $result['errors'][] = "Invalid context ID: {$context_id}";
                return $result;
            }

            // SECURITY: Check context ownership
            if ($user_id && !$this->validate_context_ownership($post, $user_id)) {
                $result['errors'][] = "Access denied: You don't have permission to modify this {$context_type}";
                return $result;
            }
        } else {
            $context_id = null; // Site context doesn't use ID

            // SECURITY: Check site-level permissions for site context
            if ($user_id && !current_user_can('manage_options')) {
                $result['errors'][] = 'Access denied: You need administrator privileges for site-level schema operations';
                return $result;
            }
        }

        $result['valid'] = true;
        $result['sanitized_data'] = [
            'context_type' => $context_type,
            'context_id' => $context_id
        ];

        return $result;
    }

    /**
     * Validate context ownership
     *
     * @since 1.0.0
     *
     * @param \WP_Post $post    Post object
     * @param int      $user_id User ID
     * @return bool Whether user has permission
     */
    private function validate_context_ownership(\WP_Post $post, int $user_id): bool {
        // Check if user can edit this specific post
        if (current_user_can('edit_post', $post->ID)) {
            return true;
        }

        // Check if user is the post author
        if ($post->post_author == $user_id) {
            return true;
        }

        // Check if user has general edit capabilities for this post type
        $post_type_object = get_post_type_object($post->post_type);
        if ($post_type_object && current_user_can($post_type_object->cap->edit_posts)) {
            return true;
        }

        return false;
    }

    /**
     * Validate JSON depth to prevent JSON bomb attacks
     *
     * @since 1.0.0
     *
     * @param mixed $data   Data to validate
     * @param int   $depth  Current depth level
     * @return bool Whether depth is within limits
     */
    private function validate_json_depth($data, int $depth = 0): bool {
        if ($depth > self::MAX_JSON_DEPTH) {
            return false;
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                if (!$this->validate_json_depth($value, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate and sanitize options array
     *
     * @since 1.0.0
     *
     * @param array $options Options array
     * @return array Sanitized options
     */
    public function sanitize_options(array $options): array {
        $sanitized = [];
        $allowed_options = [
            'deployment_method' => ['json_ld', 'microdata', 'rdfa'],
            'validation_level' => ['strict', 'moderate', 'basic'],
            'include_meta' => 'boolean',
            'minify_output' => 'boolean',
            'cache_duration' => 'integer'
        ];

        foreach ($options as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (!isset($allowed_options[$sanitized_key])) {
                continue; // Skip unknown options
            }

            $rule = $allowed_options[$sanitized_key];

            if (is_array($rule)) {
                // Enum validation
                if (in_array($value, $rule, true)) {
                    $sanitized[$sanitized_key] = $value;
                }
            } elseif ($rule === 'boolean') {
                $sanitized[$sanitized_key] = (bool) $value;
            } elseif ($rule === 'integer') {
                $sanitized[$sanitized_key] = absint($value);
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }
}
