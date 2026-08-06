<?php
/**
 * Claude API Client
 * 
 * Handles communication with Anthropic Claude API
 * 
 * @package ThinkRank\AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claude Client Class
 * 
 * Single Responsibility: Handle Claude API communication
 * 
 * @since 1.0.0
 */
class Claude_Client {
    
    /**
     * Claude API base URL
     */
    private const API_BASE_URL = 'https://api.anthropic.com/v1';
    
    /**
     * API key
     * 
     * @var string
     */
    private string $api_key;
    
    /**
     * Default model
     * 
     * @var string
     */
    private string $model;
    
    /**
     * Request timeout in seconds
     *
     * @var int
     */
    private int $timeout;

    /**
     * Prompt Builder instance
     *
     * @since 1.0.0
     * @var Prompt_Builder|null
     */
    private ?Prompt_Builder $prompt_builder = null;
    
    /**
     * Constructor
     * 
     * @param string $api_key Claude API key
     * @param string $model Default model to use
     * @param int $timeout Request timeout
     */
    public function __construct(string $api_key, string $model = 'claude-sonnet-5', int $timeout = 30) {
        $this->api_key = $api_key;
        $this->model = self::normalize_model($model);
        $this->timeout = $timeout;
    }

    /**
     * Get Prompt Builder instance
     *
     * @since 1.0.0
     *
     * @return Prompt_Builder Prompt Builder instance
     */
    private function get_prompt_builder(): Prompt_Builder {
        if (!$this->prompt_builder) {
            // Ensure Prompt Builder is loaded
            if (!class_exists('ThinkRank\\AI\\Prompt_Builder')) {
                require_once THINKRANK_PLUGIN_DIR . 'includes/ai/class-prompt-builder.php';
            }
            $this->prompt_builder = new Prompt_Builder();
        }
        return $this->prompt_builder;
    }

    /**
     * Generate completion using Claude
     * 
     * @param string $prompt The prompt to send
     * @param array $options Additional options
     * @return array Response data
     * @throws \Exception If API request fails
     */
    public function generate_completion(string $prompt, array $options = []): array {
        $default_options = [
            'model' => $this->model,
            'max_tokens' => 1000,
            'temperature' => 0.7,
        ];
        
        $options = array_merge($default_options, $options);

        // Callers may override the model via $options; self-heal retired IDs here too.
        $options['model'] = self::normalize_model((string) $options['model']);

        $body = [
            'model' => $options['model'],
            'max_tokens' => $options['max_tokens'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ]
            ],
        ];

        // Newer Claude models (Opus 4.7/4.8, Sonnet 5, Fable 5) reject non-default
        // sampling params with a 400. Only send `temperature` to models that accept it.
        if (!$this->model_rejects_sampling_params($options['model'])) {
            $body['temperature'] = $options['temperature'];
        }

        return $this->make_request('messages', $body);
    }

    /**
     * Remap retired / unavailable Claude model IDs to the current default.
     *
     * Existing installs may have a stored `claude_model` that Anthropic has since
     * retired (all `claude-3-*`) or deprecated to the point of returning 404
     * (the `claude-*-4-0` / dated 4.0 aliases). Those IDs are self-healed to the
     * recommended default so saved settings don't break API calls. A model not in
     * this list — including a valid current model or a user-entered custom ID — is
     * returned unchanged.
     *
     * @param string $model Model ID from settings
     * @return string A usable model ID
     */
    public static function normalize_model(string $model): string {
        $retired = [
            'claude-3-7-sonnet-latest', 'claude-3-7-sonnet-20250219',
            'claude-3-5-sonnet-latest', 'claude-3-5-sonnet-20241022', 'claude-3-5-sonnet-20240620',
            'claude-3-5-haiku-latest', 'claude-3-5-haiku-20241022',
            'claude-3-opus-latest', 'claude-3-opus-20240229',
            'claude-3-sonnet-20240229', 'claude-3-haiku-20240307',
            'claude-sonnet-4-0', 'claude-sonnet-4-20250514',
            'claude-opus-4-0', 'claude-opus-4-20250514',
        ];

        return in_array($model, $retired, true) ? 'claude-sonnet-5' : $model;
    }

    /**
     * Whether the given model rejects sampling params (temperature/top_p/top_k).
     *
     * Anthropic removed these on Opus 4.7+, Sonnet 5, and Fable 5 — including any
     * date-suffixed or "-latest" alias of them — so they must be omitted from the
     * request body or the API returns a 400.
     *
     * @param string $model Model ID
     * @return bool
     */
    private function model_rejects_sampling_params(string $model): bool {
        foreach (['claude-opus-4-7', 'claude-opus-4-8', 'claude-sonnet-5', 'claude-fable-5', 'claude-mythos-5'] as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Generate SEO metadata
     * 
     * @param string $content Content to analyze
     * @param array $options Generation options
     * @return array Generated metadata
     * @throws \Exception If generation fails
     */
    public function generate_seo_metadata(string $content, array $options = []): array {
        $target_keyword = $options['target_keyword'] ?? '';
        $content_type = $options['content_type'] ?? 'blog_post';
        $tone = $options['tone'] ?? 'professional';

        $prompt_builder = $this->get_prompt_builder();
        $language = is_string($options['language'] ?? null) ? $options['language'] : '';
        $prompt = $prompt_builder->build_seo_prompt($content, $target_keyword, $content_type, $tone, 'claude', $language);
        
        $response = $this->generate_completion($prompt, [
            'max_tokens' => 500,
            'temperature' => 0.3,
        ]);
        
        return $this->parse_seo_response($response);
    }

    /**
     * Analyze content for SEO optimization
     *
     * @param string $content Content to analyze
     * @param array $metadata Existing metadata
     * @return array Analysis results
     * @throws \Exception If analysis fails
     */
    public function analyze_content(string $content, array $metadata = []): array {
        $prompt_builder = $this->get_prompt_builder();
        $prompt = $prompt_builder->build_analysis_prompt($content, $metadata, 'claude');

        $response = $this->generate_completion($prompt, [
            'max_tokens' => 800,
            'temperature' => 0.3,
        ]);

        return $this->parse_analysis_response($response);
    }

    /**
     * Get current model
     *
     * @return string Current model name
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Test API connection
     *
     * @return bool True if connection successful
     */
    public function test_connection(): bool {
        try {
            // Claude doesn't have a models endpoint, so we'll test with a simple message
            $response = $this->generate_completion('Hello', ['max_tokens' => 10]);
            return isset($response['content']) && is_array($response['content']);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Make API request to Claude
     * 
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @return array Response data
     * @throws \Exception If request fails
     */
    private function make_request(string $endpoint, array $body = []): array {
        $url = self::API_BASE_URL . '/' . ltrim($endpoint, '/');
        
        $args = [
            'timeout' => $this->timeout,
            'headers' => [
                'x-api-key' => $this->api_key,
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01',
                'User-Agent' => 'ThinkRank/' . THINKRANK_VERSION,
            ],
            'method' => 'POST',
            'body' => wp_json_encode($body),
        ];

        $response = $this->request_with_retry($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . esc_html($response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code >= 400) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Unknown API error';
            throw new \Exception(sprintf('Claude API error (%d): %s', (int) $status_code, esc_html($error_message)));
        }

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from Claude API');
        }

        // A valid-but-scalar body (null/number/string from a proxy/gateway on a
        // 2xx) would violate this method's : array return type; reject it here so
        // it surfaces as a catchable \Exception, not an uncatchable TypeError.
        if (!is_array($data)) {
            throw new \Exception('Unexpected non-array response from Claude API');
        }

        return $data;
    }

    /**
     * Perform an HTTP request, retrying transient failures (429 / 5xx / network)
     * per the plugin's retry settings, honoring a Retry-After header when given.
     *
     * @param string $url  Request URL
     * @param array  $args wp_remote_request arguments
     * @return array|\WP_Error Final response (or last error after retries)
     */
    private function request_with_retry(string $url, array $args) {
        $settings = \ThinkRank\Core\Settings::instance();
        $retry_enabled = (bool) $settings->get('retry_failed_requests', true);
        $max_attempts = $retry_enabled ? max(1, (int) $settings->get('retry_attempts', 3)) : 1;

        $response = null;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            // Keep PHP alive for the whole blocking call (see method docblock).
            $this->raise_request_time_limit();

            $response = wp_remote_request($url, $args);

            $is_transient = false;
            $retry_after = 0;
            if (is_wp_error($response)) {
                $is_transient = true;
            } else {
                $status = wp_remote_retrieve_response_code($response);
                if (429 === $status || $status >= 500) {
                    $is_transient = true;
                    $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                }
            }

            if (!$is_transient || $attempt === $max_attempts) {
                break;
            }

            $delay = $retry_after > 0 ? min($retry_after, 30) : min(2 ** ($attempt - 1), 8);
            sleep($delay);
        }

        return $response;
    }

    /**
     * Give PHP enough execution time to outlive a blocking AI HTTP request.
     *
     * The provider call blocks for up to $this->timeout seconds, but the web
     * SAPI's default max_execution_time (commonly 30s) is shorter — so PHP
     * fatally terminates the script mid-request (inside the cURL transport),
     * which the web server surfaces as a 502 Bad Gateway. Resetting the limit
     * before each attempt keeps the script alive for the full call; PHP-FPM's
     * request_terminate_timeout still caps the absolute maximum. No-op when
     * set_time_limit() is disabled (e.g. via disable_functions or safe mode).
     *
     * @return void
     */
    private function raise_request_time_limit(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit($this->timeout + 45); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit() warns when disabled by host policy; the guard is intentional.
        }
    }

    /**
     * Parse SEO response from Claude
     * 
     * @param array $response Claude response
     * @return array Parsed metadata
     * @throws \Exception If parsing fails
     */
    private function parse_seo_response(array $response): array {
        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid response format from Claude');
        }
        
        $content = $response['content'][0]['text'];
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Try to extract JSON from the response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        
        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Claude response');
        }
        
        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $metadata = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON from Claude response');
        }
        
        // Validate required fields
        $required_fields = ['title', 'description', 'focus_keyword'];
        foreach ($required_fields as $field) {
            if (!isset($metadata[$field])) {
                throw new \Exception(sprintf('Missing required field: %s', esc_html($field)));
            }
        }
        
        return [
            'title' => sanitize_text_field($metadata['title']),
            'description' => sanitize_text_field($metadata['description']),
            'focus_keyword' => sanitize_text_field($metadata['focus_keyword']),
            'suggestions' => array_map('sanitize_text_field', $metadata['suggestions'] ?? []),
            'generated_at' => current_time('mysql'),
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse analysis response from Claude
     *
     * @param array $response Claude API response
     * @return array Parsed analysis data
     * @throws \Exception If parsing fails
     */
    private function parse_analysis_response(array $response): array {
        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid response format from Claude');
        }

        $content = trim($response['content'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $analysis = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response: ' . esc_html(json_last_error_msg()));
        }

        // Validate and sanitize response
        return [
            'seo_score' => min(100, max(0, (int) ($analysis['seo_score'] ?? 0))),
            'content_analysis' => [
                'word_count' => (int) ($analysis['content_analysis']['word_count'] ?? 0),
                'readability' => sanitize_text_field($analysis['content_analysis']['readability'] ?? 'unknown'),
                'keyword_density' => sanitize_text_field($analysis['content_analysis']['keyword_density'] ?? 'unknown'),
                'structure' => sanitize_text_field($analysis['content_analysis']['structure'] ?? 'unknown'),
            ],
            'suggestions' => array_map('sanitize_text_field', $analysis['suggestions'] ?? []),
            'strengths' => array_map('sanitize_text_field', $analysis['strengths'] ?? []),
            'weaknesses' => array_map('sanitize_text_field', $analysis['weaknesses'] ?? []),
            'analyzed_at' => current_time('mysql'),
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Optimize site identity using Claude
     *
     * @since 1.0.0
     *
     * @param array $site_data Site data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_site_identity(array $site_data, array $options = []): array {
        $business_type = $options['business_type'] ?? 'website';
        $target_audience = $options['target_audience'] ?? 'general';
        $tone = $options['tone'] ?? 'professional';

        $prompt_builder = $this->get_prompt_builder();
        $prompt = $prompt_builder->build_site_identity_prompt($site_data, $business_type, $target_audience, $tone, 'claude');

        $response = $this->make_request('messages', [
            'model' => $this->model,
            'max_tokens' => 600,
            'temperature' => 0.4,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ]);

        return $this->parse_site_identity_response($response);
    }

    /**
     * Parse site identity optimization response
     *
     * @param array $response Claude API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_site_identity_response(array $response): array {
        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid response format from Claude');
        }

        $content = trim($response['content'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $optimization = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response: ' . esc_html(json_last_error_msg()));
        }

        // Validate and sanitize response
        return [
            'optimized_data' => [
                'site_name' => sanitize_text_field($optimization['optimized_data']['site_name'] ?? ''),
                'site_description' => sanitize_text_field($optimization['optimized_data']['site_description'] ?? ''),
                'tagline' => sanitize_text_field($optimization['optimized_data']['tagline'] ?? ''),
                'default_meta_description' => sanitize_text_field($optimization['optimized_data']['default_meta_description'] ?? ''),
            ],
            'analysis' => sanitize_textarea_field($optimization['analysis'] ?? ''),
            'suggestions' => array_map('sanitize_text_field', $optimization['suggestions'] ?? []),
            'score' => min(100, max(0, (int) ($optimization['score'] ?? 0))),
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Optimize homepage meta content using AI (copying Site Identity pattern exactly)
     *
     * @since 1.0.0
     *
     * @param array $content_data Meta content data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_homepage_meta(array $content_data, array $options = []): array {
        $business_type = $options['business_type'] ?? 'website';
        $target_audience = $options['target_audience'] ?? 'general';
        $tone = $options['tone'] ?? 'professional';
        $context = $options['context'] ?? [];

        $prompt_builder = $this->get_prompt_builder();
        $prompt = $prompt_builder->build_homepage_meta_prompt($content_data, $business_type, $target_audience, $tone, $context, 'claude');

        $response = $this->make_request('messages', [
            'model' => $this->model,
            'max_tokens' => 600,
            'temperature' => 0.4,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ]);

        return $this->parse_homepage_meta_response($response);
    }

    /**
     * Optimize homepage hero content using AI (copying Site Identity pattern exactly)
     *
     * @since 1.0.0
     *
     * @param array $hero_data Hero content data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_homepage_hero(array $hero_data, array $options = []): array {
        $business_type = $options['business_type'] ?? 'website';
        $target_audience = $options['target_audience'] ?? 'general';
        $tone = $options['tone'] ?? 'professional';
        $context = $options['context'] ?? [];

        $prompt_builder = $this->get_prompt_builder();
        $prompt = $prompt_builder->build_homepage_hero_prompt($hero_data, $business_type, $target_audience, $tone, $context, 'claude');

        $response = $this->make_request('messages', [
            'model' => $this->model,
            'max_tokens' => 600,
            'temperature' => 0.4,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ]);

        return $this->parse_homepage_hero_response($response);
    }

    /**
     * Optimize LLMs.txt content using Claude
     *
     * @since 1.0.0
     *
     * @param array $website_data Website data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_llms_txt(array $website_data, array $options = []): array {
        // Use shared prompt builder for consistent prompts across all AI providers
        $prompt_builder = $this->get_prompt_builder();
        $prompt = $prompt_builder->build_llms_txt_prompt($website_data, $options, 'claude');

        $response = $this->make_request('messages', [
            'model' => $this->model,
            'max_tokens' => 2000, // Increased for consistency with other providers
            'temperature' => 0.4,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ]);

        return $this->parse_llms_txt_response($response);
    }



    /**
     * Parse LLMs.txt optimization response
     *
     * @param array $response Claude API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_llms_txt_response(array $response): array {
        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid response format from Claude');
        }

        $content = trim($response['content'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $optimization = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in response: ' . esc_html(json_last_error_msg()));
        }

        // Validate and sanitize response
        return [
            'optimized_data' => [
                'site_name' => sanitize_text_field($optimization['optimized_data']['site_name'] ?? ''),
                'project_overview' => sanitize_textarea_field($optimization['optimized_data']['project_overview'] ?? ''),
                'key_features' => sanitize_textarea_field($optimization['optimized_data']['key_features'] ?? ''),
                'architecture' => sanitize_textarea_field($optimization['optimized_data']['architecture'] ?? ''),
                'development_guidelines' => sanitize_textarea_field($optimization['optimized_data']['development_guidelines'] ?? ''),
                'ai_context' => sanitize_textarea_field($optimization['optimized_data']['ai_context'] ?? ''),
            ],
            'suggestions' => array_map('sanitize_text_field', $optimization['suggestions'] ?? []),
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse homepage meta optimization response
     *
     * @param array $response Claude API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_meta_response(array $response): array {
        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid response format from Claude');
        }

        $content = trim($response['content'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $optimization = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response: ' . esc_html(json_last_error_msg()));
        }

        // Validate and sanitize response
        return [
            'optimized_data' => [
                'title' => sanitize_text_field($optimization['optimized_data']['title'] ?? ''),
                'meta_description' => sanitize_text_field($optimization['optimized_data']['meta_description'] ?? ''),
            ],
            'analysis' => sanitize_textarea_field($optimization['analysis'] ?? ''),
            'suggestions' => array_map('sanitize_text_field', $optimization['suggestions'] ?? []),
            'score' => min(100, max(0, (int) ($optimization['score'] ?? 0))),
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse homepage hero optimization response
     *
     * @param array $response Claude API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_hero_response(array $response): array {
        if (!isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid response format from Claude');
        }

        $content = trim($response['content'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $optimization = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response: ' . esc_html(json_last_error_msg()));
        }

        // Validate and sanitize response
        return [
            'optimized_data' => [
                'hero_title' => sanitize_text_field($optimization['optimized_data']['hero_title'] ?? ''),
                'hero_subtitle' => sanitize_text_field($optimization['optimized_data']['hero_subtitle'] ?? ''),
                'hero_cta_text' => sanitize_text_field($optimization['optimized_data']['hero_cta_text'] ?? '')
            ],
            'analysis' => sanitize_textarea_field($optimization['analysis'] ?? ''),
            'suggestions' => array_map('sanitize_text_field', $optimization['suggestions'] ?? []),
            'score' => min(100, max(0, (int) ($optimization['score'] ?? 0))),
            'tokens_used' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }
}
