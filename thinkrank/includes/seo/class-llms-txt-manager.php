<?php
/**
 * LLMs.txt Manager Class
 *
 * Comprehensive LLMs.txt file management with AI-powered content generation,
 * file writing, validation, and status monitoring. Implements structured
 * information format for AI assistants and LLMs to better understand websites.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Ensure dependencies are loaded
if (!class_exists('ThinkRank\\SEO\\Abstract_SEO_Manager')) {
    require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-abstract-seo-manager.php';
}

if (!interface_exists('ThinkRank\\SEO\\Interfaces\\SEO_Manager_Interface')) {
    require_once THINKRANK_PLUGIN_DIR . 'includes/seo/interfaces/class-seo-manager-interface.php';
}

/**
 * LLMs.txt Manager Class
 *
 * Manages LLMs.txt file generation, validation, and serving with AI-powered
 * content creation based on website information and user input.
 *
 * @since 1.0.0
 */
class LLMs_Txt_Manager extends Abstract_SEO_Manager {

    /**
     * WordPress filesystem instance
     *
     * @since 1.0.0
     * @var \WP_Filesystem_Base|null
     */
    private $filesystem = null;

    /**
     * Whether the most recent save_settings() persisted a disable but failed to
     * remove the published llms.txt file (so it may still be served). Callers
     * check this via {@see unpublish_failed()} to surface a partial failure.
     *
     * @var bool
     */
    private bool $last_unpublish_failed = false;

    /**
     * LLMs.txt content sections configuration
     *
     * @since 1.0.0
     * @var array
     */
    private array $content_sections = [
        'project_overview' => [
            'title' => 'Project Overview',
            'required' => true,
            'description' => 'High-level description of the website/project purpose',
            'max_length' => 500
        ],
        'key_features' => [
            'title' => 'Key Features',
            'required' => true,
            'description' => 'Main features and functionality of the website',
            'max_length' => 300
        ],
        'architecture' => [
            'title' => 'Architecture & Components',
            'required' => false,
            'description' => 'Technical architecture and key components',
            'max_length' => 400
        ],
        'development_guidelines' => [
            'title' => 'Development Guidelines',
            'required' => false,
            'description' => 'Coding standards and development practices',
            'max_length' => 300
        ],
        'setup_instructions' => [
            'title' => 'Setup Instructions',
            'required' => false,
            'description' => 'How to get the project running',
            'max_length' => 400
        ],
        'ai_context' => [
            'title' => 'Context for AI Assistants',
            'required' => true,
            'description' => 'Specific information to help AI understand the project',
            'max_length' => 300
        ]
    ];

    /**
     * Maximum file size for LLMs.txt files (1MB)
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_FILE_SIZE = 1048576; // 1MB in bytes

    /**
     * Business type templates for content generation
     *
     * @since 1.0.0
     * @var array
     */
    private array $business_types = [
        'website' => 'website',
        'blog' => 'Personal or professional blog',
        'business' => 'Business/corporate website',
        'ecommerce' => 'E-commerce/online store',
        'portfolio' => 'Portfolio/showcase website',
        'nonprofit' => 'Non-profit organization',
        'educational' => 'Educational institution',
        'news' => 'News/media website',
        'community' => 'Community/forum website',
        'saas' => 'Software as a Service',
        'agency' => 'Agency/service provider',
        'other' => 'Other type of website'
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('llms_txt');
    }

    /**
     * Initialize WordPress filesystem
     *
     * @since 1.0.0
     * @return bool True if filesystem is initialized, false otherwise
     */
    private function init_filesystem(): bool {
        if ($this->filesystem !== null) {
            return true;
        }

        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $credentials = request_filesystem_credentials('', '', false, false, null);
        if (!WP_Filesystem($credentials)) {
            return false;
        }

        $this->filesystem = $wp_filesystem;
        return true;
    }

    /**
     * Check if directory is writable using WP_Filesystem
     *
     * @since 1.0.0
     * @param string $path Directory path to check
     * @return bool True if writable, false otherwise
     */
    private function is_directory_writable(string $path): bool {
        if (!$this->init_filesystem()) {
            return false;
        }

        return $this->filesystem->is_writable($path);
    }

    /**
     * Check if file is writable using WP_Filesystem
     *
     * @since 1.0.0
     * @param string $file File path to check
     * @return bool True if writable, false otherwise
     */
    private function is_file_writable(string $file): bool {
        if (!$this->init_filesystem()) {
            return false;
        }

        return $this->filesystem->is_writable($file);
    }
    public function generate_llms_txt(array $user_input, array $options = []): array {
        $llms_data = [
            'content' => '',
            'sections' => [],
            'metadata' => [],
            'validation' => [],
            'file_info' => []
        ];

        // Get current settings
        $settings = $this->get_settings('site');

        // Merge saved settings underneath the provided input so that empty or
        // partial $user_input falls back to the persisted configuration.
        // Explicitly provided (non-empty) values win; blank ones are filled from
        // saved settings. This lets callers generate from saved settings by
        // passing an empty payload (e.g. the generate-llms-txt MCP ability),
        // matching the documented behavior.
        $provided = array_filter(
            $user_input,
            static function ($value) {
                if (is_string($value)) {
                    return '' !== trim($value);
                }
                return null !== $value && [] !== $value;
            }
        );
        $user_input = array_merge($settings, $provided);

        // Check file status
        $llms_file = ABSPATH . 'llms.txt';
        $llms_data['file_info'] = [
            'file_exists' => file_exists($llms_file),
            'writable' => $this->is_directory_writable(dirname($llms_file)),
            'file_path' => $llms_file,
            'last_modified' => file_exists($llms_file) ? filemtime($llms_file) : null
        ];

        // Validate user input
        $validation = $this->validate_user_input($user_input);
        $llms_data['validation'] = $validation;

        if (!$validation['valid']) {
            return $llms_data;
        }

        // Generate content sections
        $llms_data['sections'] = $this->build_content_sections($user_input, $settings);
        
        // Build final LLMs.txt content
        $site_name = $user_input['site_name'] ?? $settings['site_name'] ?? get_bloginfo('name');
        $llms_data['content'] = $this->build_llms_txt_content($llms_data['sections'], $site_name);
        
        // Add metadata
        $llms_data['metadata'] = [
            'generated_at' => gmdate('c'),
            'website_url' => home_url(),
            'generator' => 'ThinkRank SEO Plugin',
            'content_length' => strlen($llms_data['content']),
            'sections_count' => count($llms_data['sections'])
        ];

        return $llms_data;
    }

    /**
     * Persist settings, unpublishing the physical file when the feature is
     * disabled so a disable actually stops serving /llms.txt.
     *
     * @param string   $context_type Context type.
     * @param int|null $context_id   Context ID.
     * @param array    $settings     Settings to save.
     * @return bool
     */
    public function save_settings(string $context_type, ?int $context_id, array $settings): bool {
        $this->last_unpublish_failed = false;
        $result = parent::save_settings($context_type, $context_id, $settings);

        // When a save explicitly disables the feature, delete the published file.
        if ($result && array_key_exists('enabled', $settings) && empty($settings['enabled'])) {
            if (!$this->delete_llms_txt_file()) {
                // The settings were persisted, but the physical file could not be
                // removed, so /llms.txt may still be served. Record it so callers
                // report a partial failure instead of an unqualified success.
                $this->last_unpublish_failed = true;
            }
        }

        return $result;
    }

    /**
     * Whether the last save_settings() disabled the feature but could not remove
     * the published llms.txt file (which may therefore still be served).
     *
     * @return bool
     */
    public function unpublish_failed(): bool {
        return $this->last_unpublish_failed;
    }

    /**
     * Remove the published llms.txt file and bust the status transient.
     *
     * @return bool True if the file is absent or was removed.
     */
    public function delete_llms_txt_file(): bool {
        delete_transient('thinkrank_llms_file_status');

        $llms_file = ABSPATH . 'llms.txt';
        if (!file_exists($llms_file)) {
            return true;
        }
        if (!$this->init_filesystem()) {
            return false;
        }
        return (bool) $this->filesystem->delete($llms_file);
    }

    /**
     * Write LLMs.txt content to filesystem
     *
     * @since 1.0.0
     *
     * @param string $content LLMs.txt content to write
     * @return array Write operation result
     */
    public function write_llms_txt_to_file(string $content): array {
        $result = [
            'success' => false,
            'message' => '',
            'file_path' => '',
            'permissions' => []
        ];

        // Refuse to publish when the feature is disabled. The React UI hides the
        // publish button, but the REST endpoint and the MCP publish ability call
        // this directly, so enforce the toggle here at the single write choke point.
        $settings = $this->get_settings('site');
        if (empty($settings['enabled'])) {
            $result['message'] = 'LLMs.txt is disabled. Enable it before publishing.';
            return $result;
        }

        $llms_file = ABSPATH . 'llms.txt';

        // Security: Validate file path to prevent path traversal attacks
        $real_llms_file = realpath(dirname($llms_file)) . DIRECTORY_SEPARATOR . basename($llms_file);
        $allowed_dir = realpath(ABSPATH);

        if (!$allowed_dir || strpos(dirname($real_llms_file), $allowed_dir) !== 0) {
            $result['message'] = 'Invalid file path detected for security reasons.';
            return $result;
        }

        $result['file_path'] = $llms_file;

        // Check directory permissions
        $result['permissions'] = [
            'directory_writable' => $this->is_directory_writable(ABSPATH),
            'file_exists' => file_exists($llms_file),
            'file_writable' => file_exists($llms_file) ? $this->is_file_writable($llms_file) : null
        ];

        // Check if we can write to the directory
        if (!$result['permissions']['directory_writable']) {
            $result['message'] = 'WordPress root directory is not writable. Please check file permissions.';
            return $result;
        }

        // Check if existing file is writable (if it exists)
        if ($result['permissions']['file_exists'] && !$result['permissions']['file_writable']) {
            $result['message'] = 'Existing llms.txt file is not writable. Please check file permissions.';
            return $result;
        }

            // Write new content using WP_Filesystem
            if (!$this->init_filesystem()) {
                $result['message'] = 'Could not initialize WordPress filesystem.';
                return $result;
            }

            $write_success = $this->filesystem->put_contents($llms_file, $content, FS_CHMOD_FILE);

            if ($write_success) {
                $result['success'] = true;
                $result['message'] = 'LLMs.txt file written successfully.';
                $result['bytes_written'] = strlen($content);

                // Invalidate file status cache since file has changed
                delete_transient('thinkrank_llms_file_status');
            } else {
                $result['message'] = 'Failed to write llms.txt file.';
            }

        return $result;
    }

    /**
     * Get LLMs.txt file status and information
     *
     * @since 1.0.0
     *
     * @return array File status information
     */
    public function get_llms_txt_status(bool $force_refresh = false): array {
        // Check cache first (5 minute cache for performance)
        $cache_key = 'thinkrank_llms_file_status';

        if (!$force_refresh) {
            $cached_status = get_transient($cache_key);
            if ($cached_status !== false) {
                return $cached_status;
            }
        }

        $llms_file = ABSPATH . 'llms.txt';

        $status = [
            'file_exists' => file_exists($llms_file),
            'file_path' => $llms_file,
            'file_url' => home_url('/llms.txt'),
            'writable' => $this->is_directory_writable(dirname($llms_file)),
            'last_modified' => null,
            'file_size' => null,
            'content_preview' => ''
        ];

        if ($status['file_exists']) {
            $status['last_modified'] = filemtime($llms_file);
            $status['file_size'] = filesize($llms_file);

            // Get content preview (first 200 characters) with size safety
            $read_result = $this->safe_file_read($llms_file);
            if ($read_result['success']) {
                $status['content_preview'] = substr($read_result['content'], 0, 200);
                if (strlen($read_result['content']) > 200) {
                    $status['content_preview'] .= '...';
                }
            } else {
                $status['content_preview'] = 'Error: ' . $read_result['error'];
                $status['read_error'] = $read_result['error'];
            }
        }

        // Cache the result for 5 minutes to improve performance
        set_transient($cache_key, $status, 5 * MINUTE_IN_SECONDS);

        return $status;
    }

    /**
     * Validate LLMs.txt content
     *
     * @since 1.0.0
     *
     * @param string $content LLMs.txt content to validate
     * @return array Validation results
     */
    public function validate_llms_txt_content(string $content): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Check if content is empty
        if (empty(trim($content))) {
            $validation['errors'][] = 'LLMs.txt content cannot be empty';
            $validation['valid'] = false;
            $validation['score'] = 0;
            return $validation;
        }

        // Check content length
        $content_length = strlen($content);
        if ($content_length < 100) {
            $validation['warnings'][] = 'LLMs.txt content is very short, consider adding more details';
            $validation['score'] -= 20;
        } elseif ($content_length > 10000) {
            $validation['warnings'][] = 'LLMs.txt content is very long, consider condensing key information';
            $validation['score'] -= 10;
        }

        // Check for required sections
        $required_sections = ['Project Overview', 'Key Features', 'Context for AI Assistants'];
        foreach ($required_sections as $section) {
            if (stripos($content, $section) === false) {
                $validation['warnings'][] = "Missing recommended section: {$section}";
                $validation['score'] -= 15;
            }
        }

        // Check for proper structure
        if (!preg_match('/^#\s+/', $content)) {
            $validation['suggestions'][] = 'Consider starting with a main heading (# Project Name)';
            $validation['score'] -= 5;
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        return $validation;
    }

    /**
     * Validate user input for LLMs.txt generation
     *
     * @since 1.0.0
     *
     * @param array $user_input User-provided data
     * @return array Validation results
     */
    private function validate_user_input(array $user_input): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Check required fields
        $required_fields = [
            'website_description' => 'Website Description',
            'key_features' => 'Key Features',
            'target_audience' => 'Target Audience'
        ];

        foreach ($required_fields as $field => $label) {
            if (empty($user_input[$field])) {
                $validation['errors'][] = "{$label} is required for quality LLMs.txt generation";
                $validation['valid'] = false;
                $validation['score'] -= 25;
            } else {
                $validation['suggestions'][] = "✓ {$label} is properly configured";
            }
        }

        // Validate link formats in structured sections
        $this->validate_link_sections($user_input, $validation);

        // Check content quality
        $this->validate_content_quality($user_input, $validation);

        // Check optional enhancements
        $this->validate_optional_enhancements($user_input, $validation);

        // Validate website description
        if (!empty($user_input['website_description'])) {
            $desc_length = strlen($user_input['website_description']);
            if ($desc_length < 50) {
                $validation['warnings'][] = 'Website description is quite short, consider adding more details';
                $validation['score'] -= 10;
            } elseif ($desc_length > 1000) {
                $validation['warnings'][] = 'Website description is very long, consider condensing key points';
                $validation['score'] -= 5;
            }
        }

        // Validate business type
        if (!empty($user_input['business_type']) && !isset($this->business_types[$user_input['business_type']])) {
            $validation['warnings'][] = 'Unknown business type specified';
            $validation['score'] -= 5;
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        return $validation;
    }

    /**
     * Validate LLMs.txt input (public method for API)
     *
     * @since 1.0.0
     *
     * @param array $user_input User-provided data
     * @return array Validation results
     */
    public function validate_llms_txt_input(array $user_input): array {
        return $this->validate_user_input($user_input);
    }

    /**
     * Override parent sanitize_settings to preserve line breaks in link fields
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to sanitize
     * @return array Sanitized settings
     */
    protected function sanitize_settings(array $settings): array {
        $sanitized = [];

        // Fields that should preserve line breaks
        $preserve_linebreaks = [
            'documentation_links',
            'technical_links',
            'optional_links',
            'custom_sections',
            'key_features',
            'website_description',
            'technical_stack',
            'development_approach',
            'setup_instructions',
            'ai_context_custom'
        ];

        foreach ($settings as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (is_string($value)) {
                if (in_array($key, $preserve_linebreaks, true)) {
                    // Use our custom sanitization that preserves line breaks
                    if (in_array($key, ['documentation_links', 'technical_links', 'optional_links', 'custom_sections'], true)) {
                        $sanitized[$sanitized_key] = $this->sanitize_llms_content($value);
                    } else {
                        // For textarea fields, use sanitize_textarea_field which preserves line breaks
                        $sanitized[$sanitized_key] = sanitize_textarea_field($value);
                    }
                } else {
                    // For regular text fields, use sanitize_text_field
                    $sanitized[$sanitized_key] = sanitize_text_field($value);
                }
            } elseif (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_array_recursive($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize array values (preserving line breaks where needed)
     *
     * @since 1.0.0
     *
     * @param array $array Array to sanitize
     * @return array Sanitized array
     */
    private function sanitize_array_recursive(array $array): array {
        $sanitized = [];

        foreach ($array as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (is_string($value)) {
                $sanitized[$sanitized_key] = sanitize_textarea_field($value);
            } elseif (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_array_recursive($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Validate link formats in structured sections
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array &$validation Validation results (passed by reference)
     */
    private function validate_link_sections(array $user_input, array &$validation): void {
        $link_sections = [
            'documentation_links' => 'Documentation Links',
            'technical_links' => 'Technical Links',
            'optional_links' => 'Optional Links'
        ];

        foreach ($link_sections as $field => $label) {
            if (!empty($user_input[$field])) {
                $links = explode("\n", $user_input[$field]);
                $valid_links = 0;
                $total_links = 0;

                foreach ($links as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, '-')) {
                        continue;
                    }

                    $total_links++;

                    // Check for proper markdown link format: - [Title](URL): Description
                    if (preg_match('/^-\s*\[([^\]]+)\]\(([^)]+)\):\s*(.+)$/', $line, $matches)) {
                        $title = trim($matches[1]);
                        $url = trim($matches[2]);
                        $description = trim($matches[3]);

                        if (!empty($title) && !empty($url) && !empty($description)) {
                            if (filter_var($url, FILTER_VALIDATE_URL)) {
                                $valid_links++;
                            } else {
                                $validation['warnings'][] = "Invalid URL in {$label}: {$url}";
                                $validation['score'] -= 5;
                            }
                        } else {
                            $validation['warnings'][] = "Incomplete link format in {$label}: missing title, URL, or description";
                            $validation['score'] -= 5;
                        }
                    } else {
                        $validation['warnings'][] = "Invalid link format in {$label}. Use: - [Title](URL): Description";
                        $validation['score'] -= 5;
                    }
                }

                if ($total_links > 0) {
                    if ($valid_links === $total_links) {
                        $validation['suggestions'][] = "✓ All {$label} are properly formatted";
                    } else {
                        $validation['warnings'][] = "{$label}: {$valid_links}/{$total_links} links are properly formatted";
                    }
                }
            }
        }
    }

    /**
     * Validate content quality
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array &$validation Validation results (passed by reference)
     */
    private function validate_content_quality(array $user_input, array &$validation): void {
        // Check website description quality
        if (!empty($user_input['website_description'])) {
            $desc_length = strlen($user_input['website_description']);
            if ($desc_length < 50) {
                $validation['warnings'][] = 'Website description is quite short. Consider adding more detail for better AI understanding';
                $validation['score'] -= 10;
            } elseif ($desc_length > 500) {
                $validation['warnings'][] = 'Website description is very long. Consider making it more concise';
                $validation['score'] -= 5;
            } else {
                $validation['suggestions'][] = '✓ Website description length is optimal';
            }
        }

        // Check key features quality
        if (!empty($user_input['key_features'])) {
            $features = explode("\n", $user_input['key_features']);
            $feature_count = count(array_filter($features, 'trim'));

            if ($feature_count < 3) {
                $validation['warnings'][] = 'Consider adding more key features (3-8 recommended) for comprehensive AI understanding';
                $validation['score'] -= 10;
            } elseif ($feature_count > 10) {
                $validation['warnings'][] = 'Many key features listed. Consider focusing on the most important ones';
                $validation['score'] -= 5;
            } else {
                $validation['suggestions'][] = "✓ Good number of key features ({$feature_count})";
            }
        }
    }

    /**
     * Validate optional enhancements
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array &$validation Validation results (passed by reference)
     */
    private function validate_optional_enhancements(array $user_input, array &$validation): void {
        $enhancement_score = 0;

        // Check for technical stack
        if (!empty($user_input['technical_stack'])) {
            $validation['suggestions'][] = '✓ Technical stack information provided';
            $enhancement_score += 5;
        } else {
            $validation['suggestions'][] = 'Consider adding technical stack information for developer context';
        }

        // Check for development approach
        if (!empty($user_input['development_approach'])) {
            $validation['suggestions'][] = '✓ Development approach documented';
            $enhancement_score += 5;
        } else {
            $validation['suggestions'][] = 'Consider documenting development approach for better AI assistance';
        }

        // Check for setup instructions
        if (!empty($user_input['setup_instructions'])) {
            $validation['suggestions'][] = '✓ Setup instructions provided';
            $enhancement_score += 5;
        } else {
            $validation['suggestions'][] = 'Consider adding setup instructions for new developers';
        }

        // Check for custom sections
        if (!empty($user_input['custom_sections'])) {
            $validation['suggestions'][] = '✓ Custom sections enhance documentation';
            $enhancement_score += 5;
        }

        // Bonus points for comprehensive documentation
        if ($enhancement_score >= 15) {
            $validation['suggestions'][] = '✓ Comprehensive LLMs.txt documentation - excellent for AI assistance!';
        }
    }

    /**
     * Build content sections from user input
     *
     * @since 1.0.0
     *
     * @param array $user_input User-provided data
     * @param array $settings Current settings
     * @return array Built content sections
     */
    private function build_content_sections(array $user_input, array $settings): array {
        $sections = [];

        // Blockquote summary (required by spec)
        $sections['summary'] = [
            'title' => '', // No title for blockquote
            'content' => $this->build_summary_blockquote($user_input, $settings)
        ];

        // Additional details (optional descriptive content)
        if (!empty($user_input['website_description'])) {
            $sections['details'] = [
                'title' => '', // No title for details
                'content' => $this->build_additional_details($user_input, $settings)
            ];
        }

        // Development Approach section (if provided)
        if (!empty($user_input['development_approach'])) {
            $sections['development_approach'] = [
                'title' => 'Development Approach',
                'content' => sanitize_textarea_field($user_input['development_approach'])
            ];
        }

        // Setup Instructions section (if provided)
        if (!empty($user_input['setup_instructions'])) {
            $sections['setup_instructions'] = [
                'title' => 'Setup Instructions',
                'content' => sanitize_textarea_field($user_input['setup_instructions'])
            ];
        }

        // User-controlled structured sections (always include with defaults if empty)
        $documentation_content = !empty($user_input['documentation_links'])
            ? $this->sanitize_llms_content($user_input['documentation_links'])
            : $this->get_default_documentation_links();

        $sections['documentation'] = [
            'title' => 'Documentation',
            'content' => $documentation_content
        ];

        // Technical section (only if user provided content or technical details exist)
        if (!empty($user_input['technical_links']) || !empty($user_input['technical_stack']) || !empty($user_input['development_approach'])) {
            $technical_content = !empty($user_input['technical_links'])
                ? $this->sanitize_llms_content($user_input['technical_links'])
                : $this->get_default_technical_links($user_input);

            $sections['technical'] = [
                'title' => 'Technical Details',
                'content' => $technical_content
            ];
        }

        // Optional section (only if user provided content)
        if (!empty($user_input['optional_links'])) {
            $sections['optional'] = [
                'title' => 'Optional',
                'content' => $this->sanitize_llms_content($user_input['optional_links'])
            ];
        }

        // Custom sections (user-defined markdown)
        if (!empty($user_input['custom_sections'])) {
            $sections['custom'] = [
                'title' => '', // No title since user provides their own H2 headers
                'content' => $this->sanitize_llms_content($user_input['custom_sections'])
            ];
        }

        return $sections;
    }

    /**
     * Sanitize LLMs.txt content while preserving line breaks
     *
     * @since 1.0.0
     *
     * @param string $content Raw content to sanitize
     * @return string Sanitized content with preserved line breaks
     */
    public function sanitize_llms_content(string $content): string {
        // Remove any potential script tags and dangerous content
        $content = wp_kses($content, [
            'a' => ['href' => [], 'title' => []],
            'strong' => [],
            'em' => [],
            'code' => [],
            'pre' => []
        ]);

        // wp_kses only guards HTML href attributes, not markdown link syntax
        // [text](url). Neutralize dangerous schemes (javascript:/data:/vbscript:)
        // in markdown link targets so they don't survive into the published file
        // for downstream consumers that render it as markdown/HTML.
        $content = preg_replace_callback('/\]\(([^)]*)\)/', static function ($m) {
            if (preg_match('#^\s*(?:javascript|data|vbscript):#i', $m[1])) {
                return '](#)';
            }
            return $m[0];
        }, $content);

        // Normalize line endings and preserve line breaks
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Remove excessive whitespace but preserve intentional line breaks
        $content = preg_replace('/[ \t]+/', ' ', $content); // Multiple spaces/tabs to single space
        $content = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content); // Multiple empty lines to double

        return trim($content);
    }

    /**
     * Safely read file content with size limits
     *
     * @since 1.0.0
     *
     * @param string $file_path Path to file to read
     * @param int|null $max_size Maximum file size to read (null for class default)
     * @return array Result with success status, content, and any errors
     */
    private function safe_file_read(string $file_path, ?int $max_size = null): array {
        $result = [
            'success' => false,
            'content' => '',
            'error' => '',
            'file_size' => 0
        ];

        if (!file_exists($file_path)) {
            $result['error'] = 'File does not exist';
            return $result;
        }

        $file_size = filesize($file_path);
        $result['file_size'] = $file_size;

        $max_allowed = $max_size ?? self::MAX_FILE_SIZE;

        if ($file_size > $max_allowed) {
            $result['error'] = sprintf(
                'File size (%s) exceeds maximum allowed size (%s)',
                size_format($file_size),
                size_format($max_allowed)
            );
            return $result;
        }

        if (!$this->init_filesystem()) {
            $result['error'] = 'Could not initialize WordPress filesystem';
            return $result;
        }

        $content = $this->filesystem->get_contents($file_path);
        if (false === $content) {
            $result['error'] = 'Failed to read file content';
            return $result;
        }

        $result['success'] = true;
        $result['content'] = $content;
        return $result;
    }
    private function build_llms_txt_content(array $sections, string $site_name = ''): string {
        // Sanitize inside the manager rather than trusting callers — the MCP
        // abilities pass site_name through unsanitized.
        $site_name = sanitize_text_field($site_name ?: get_bloginfo('name'));
        $content = "# {$site_name}\n\n";

        foreach ($sections as $section_key => $section_data) {
            // Only add H2 header if title is not empty
            if (!empty($section_data['title'])) {
                $content .= "## {$section_data['title']}\n\n";
            }
            $content .= $section_data['content'] . "\n\n";
        }

        // Add generation timestamp
        $content .= "---\n";
        $content .= "Generated by ThinkRank SEO Plugin on " . gmdate('Y-m-d H:i:s') . " UTC\n";

        return $content;
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Validate enabled setting
        if (!isset($settings['enabled'])) {
            $validation['errors'][] = 'Enabled setting is required';
            $validation['valid'] = false;
            $validation['score'] -= 25;
        }

        // Validate website description
        if (isset($settings['website_description'])) {
            if (empty($settings['website_description'])) {
                $validation['warnings'][] = 'Website description is empty, consider adding a description';
                $validation['score'] -= 15;
            } elseif (strlen($settings['website_description']) < 50) {
                $validation['suggestions'][] = 'Website description is quite short, consider adding more details';
                $validation['score'] -= 5;
            }
        }

        // Validate key features
        if (isset($settings['key_features'])) {
            if (empty($settings['key_features'])) {
                $validation['warnings'][] = 'Key features are empty, consider listing main website features';
                $validation['score'] -= 15;
            }
        }

        // Validate target audience
        if (isset($settings['target_audience'])) {
            if (empty($settings['target_audience'])) {
                $validation['suggestions'][] = 'Target audience is not specified, consider defining your audience';
                $validation['score'] -= 5;
            }
        }

        // Check file permissions if enabled
        if (!empty($settings['enabled'])) {
            if (!$this->is_directory_writable(ABSPATH)) {
                $validation['warnings'][] = 'WordPress root directory is not writable, llms.txt cannot be automatically managed';
                $validation['score'] -= 10;
            }
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        return $validation;
    }

    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        $settings = $this->get_settings($context_type, $context_id);

        $output = [
            'llms_txt_content' => '',
            'file_status' => [],
            'metadata' => [],
            'enabled' => $settings['enabled'] ?? true
        ];

        if (!$output['enabled']) {
            return $output;
        }

        // Get file status
        $output['file_status'] = $this->get_llms_txt_status();

        // If file exists, get current content safely
        if ($output['file_status']['file_exists']) {
            $llms_file = ABSPATH . 'llms.txt';
            $read_result = $this->safe_file_read($llms_file);
            if ($read_result['success']) {
                $output['llms_txt_content'] = $read_result['content'];
            } else {
                $output['llms_txt_content'] = '';
                $output['file_read_error'] = $read_result['error'];
            }
        }

        // Add metadata
        $output['metadata'] = [
            'last_generated' => $settings['last_generated'] ?? null,
            'generator_version' => THINKRANK_VERSION ?? '1.0.0',
            'website_url' => home_url()
        ];

        return $output;
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        $defaults = [
            'enabled' => true,
            'site_name' => get_bloginfo('name'),
            'website_description' => get_bloginfo('description'),
            'key_features' => '',
            'target_audience' => 'general',
            'business_type' => 'website',
            'technical_stack' => 'WordPress',
            'development_approach' => '',
            'setup_instructions' => '',
            'ai_context_custom' => '',
            'auto_generate' => false,
            'last_generated' => null,
            // Structured sections for llms.txt spec compliance
            'documentation_links' => '',
            'technical_links' => '',
            'optional_links' => '',
            'custom_sections' => ''
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                // Site-wide defaults are already set above
                break;
            default:
                // Use site defaults for other contexts
                break;
        }

        return $defaults;
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable LLMs.txt',
                'description' => 'Enable LLMs.txt file generation and management',
                'default' => true
            ],
            'site_name' => [
                'type' => 'string',
                'title' => 'Website Title',
                'description' => 'The name of your website as it will appear in the LLMs.txt file',
                'default' => get_bloginfo('name'),
                'maxLength' => 60
            ],
            'website_description' => [
                'type' => 'string',
                'title' => 'Website Description',
                'description' => 'Comprehensive description of your website and its purpose',
                'default' => get_bloginfo('description'),
                'maxLength' => 1000
            ],
            'key_features' => [
                'type' => 'string',
                'title' => 'Key Features',
                'description' => 'Main features and functionality of your website',
                'default' => '',
                'maxLength' => 500
            ],
            'target_audience' => [
                'type' => 'string',
                'title' => 'Target Audience',
                'description' => 'Primary audience for your website',
                'default' => 'general',
                'maxLength' => 200
            ],
            'business_type' => [
                'type' => 'string',
                'title' => 'Business Type',
                'description' => 'Type of website or business',
                'enum' => array_keys($this->business_types),
                'default' => 'website'
            ],
            'technical_stack' => [
                'type' => 'string',
                'title' => 'Technical Stack',
                'description' => 'Technologies and frameworks used',
                'default' => 'WordPress',
                'maxLength' => 300
            ],
            'development_approach' => [
                'type' => 'string',
                'title' => 'Development Approach',
                'description' => 'Development methodology and practices',
                'default' => '',
                'maxLength' => 400
            ],
            'setup_instructions' => [
                'type' => 'string',
                'title' => 'Setup Instructions',
                'description' => 'Instructions for setting up or working with the project',
                'default' => '',
                'maxLength' => 500
            ],
            'ai_context_custom' => [
                'type' => 'string',
                'title' => 'Additional AI Context',
                'description' => 'Custom context information for AI assistants',
                'default' => '',
                'maxLength' => 400
            ],
            'auto_generate' => [
                'type' => 'boolean',
                'title' => 'Auto-generate',
                'description' => 'Automatically regenerate llms.txt when settings change',
                'default' => false
            ],
            'last_generated' => [
                'type' => 'string',
                'title' => 'Last Generated',
                'description' => 'Timestamp of last generation',
                'format' => 'date-time',
                'readonly' => true
            ],
            'documentation_links' => [
                'type' => 'string',
                'title' => 'Documentation Links',
                'description' => 'Links to documentation, guides, and important pages',
                'default' => '',
                'maxLength' => 2000
            ],
            'technical_links' => [
                'type' => 'string',
                'title' => 'Technical Links',
                'description' => 'Links to technical resources, code repositories, and development info',
                'default' => '',
                'maxLength' => 2000
            ],
            'optional_links' => [
                'type' => 'string',
                'title' => 'Optional Links',
                'description' => 'Secondary resources that can be skipped for shorter context',
                'default' => '',
                'maxLength' => 2000
            ],
            'custom_sections' => [
                'type' => 'string',
                'title' => 'Custom Sections',
                'description' => 'Additional custom sections in markdown format',
                'default' => '',
                'maxLength' => 3000
            ]
        ];
    }
    private function build_summary_blockquote(array $user_input, array $settings): string {
        $description = sanitize_textarea_field($user_input['website_description'] ?? '');

        if (empty($description)) {
            $site_name = sanitize_text_field($user_input['site_name'] ?? $settings['site_name'] ?? get_bloginfo('name'));
            $business_type = $user_input['business_type'] ?? 'website';
            $description = "{$site_name} is a {$this->business_types[$business_type]} providing valuable resources and information.";
        }

        // Format as blockquote (required by spec)
        return "> " . $description . "\n\n";
    }

    /**
     * Build additional details section
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @param array $settings   Current settings
     * @return string Additional details content
     */
    private function build_additional_details(array $user_input, array $settings): string {
        $content = '';
        $target_audience = sanitize_text_field($user_input['target_audience'] ?? '');
        $key_features = sanitize_textarea_field($user_input['key_features'] ?? '');

        if (!empty($target_audience)) {
            $content .= "**Target Audience:** {$target_audience}\n\n";
        }

        if (!empty($key_features)) {
            $content .= "**Key Features:**\n";
            // The UI field is a multi-line textarea and validation counts by
            // newline, so split on newlines (and still tolerate commas) rather
            // than commas only — otherwise newline-separated input collapses
            // into one broken bullet.
            $features = preg_split('/[\r\n,]+/', $key_features);
            foreach ($features as $feature) {
                $feature = trim($feature);
                if (!empty($feature)) {
                    $content .= "- " . $feature . "\n";
                }
            }
            $content .= "\n";
        }

        return $content;
    }
    private function get_default_documentation_links(): string {
        $website_url = home_url();
        $content = '';

        // Add basic WordPress links
        $content .= "- [Website Home]({$website_url}): Main website homepage\n";
        $content .= "- [Sitemap]({$website_url}/sitemap.xml): Complete site structure\n";

        return $content;
    }

    /**
     * Get default technical links based on user input
     *
     * @since 1.0.0
     *
     * @param array $user_input User input data
     * @return string Default technical links
     */
    private function get_default_technical_links(array $user_input): string {
        $website_url = home_url();
        $content = '';

        if (!empty($user_input['technical_stack'])) {
            $stack = sanitize_text_field($user_input['technical_stack']);
            $content .= "- [Technical Stack]({$website_url}): Built with {$stack}\n";
        }

        if (!empty($user_input['development_approach'])) {
            $approach_summary = wp_trim_words($user_input['development_approach'], 10);
            $content .= "- [Development Guidelines]({$website_url}): {$approach_summary}\n";
        }

        // Add robots.txt reference
        $content .= "- [Robots.txt]({$website_url}/robots.txt): Site crawling guidelines\n";

        return $content;
    }
}
