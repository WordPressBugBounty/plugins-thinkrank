<?php
/**
 * Google Gemini AI Client
 *
 * Handles communication with Google's Gemini API for AI-powered features.
 * Integrates with centralized prompt system for consistent prompts across providers.
 *
 * @package ThinkRank
 * @subpackage AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gemini AI Client Class
 *
 * Provides interface to Google Gemini API for SEO optimization,
 * content analysis, and other AI-powered features.
 *
 * @since 1.0.0
 */
class Gemini_Client {

    /**
     * API key for Gemini
     *
     * @since 1.0.0
     * @var string
     */
    private string $api_key;

    /**
     * Model to use for requests
     *
     * @since 1.0.0
     * @var string
     */
    private string $model;

    /**
     * Request timeout in seconds
     *
     * @since 1.0.0
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
     * @param string $api_key Gemini API key
     * @param string $model Default model to use
     * @param int $timeout Request timeout
     */
    public function __construct(string $api_key, string $model = 'gemini-2.5-flash', int $timeout = 30) {
        $this->api_key = $api_key;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Get current model
     *
     * @since 1.0.0
     *
     * @return string Current model name
     */
    public function get_model(): string {
        return $this->model;
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
     * Generate SEO metadata for content
     * 
     * @param string $content Content to optimize
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
        $prompt = $prompt_builder->build_seo_prompt($content, $target_keyword, $content_type, $tone, 'gemini', $language);
        
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
        $prompt = $prompt_builder->build_analysis_prompt($content, $metadata, 'gemini');

        $response = $this->generate_completion($prompt, [
            'max_tokens' => 800,
            'temperature' => 0.3,
        ]);

        return $this->parse_analysis_response($response);
    }

    /**
     * Optimize site identity
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
        $prompt = $prompt_builder->build_site_identity_prompt($site_data, $business_type, $target_audience, $tone, 'gemini');

        $response = $this->make_request('generateContent', [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => 'You are an expert SEO consultant specializing in site identity optimization. Provide actionable, specific recommendations in JSON format.']
                ]
            ],
            'generationConfig' => $this->build_generation_config([
                'maxOutputTokens' => 2000, // Increased based on actual usage (1499 tokens used)
                'temperature' => 0.4,
            ])
        ]);

        return $this->parse_site_identity_response($response);
    }

    /**
     * Optimize homepage meta content
     *
     * @since 1.0.0
     *
     * @param array $content_data Meta content data
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
        $prompt = $prompt_builder->build_homepage_meta_prompt($content_data, $business_type, $target_audience, $tone, $context, 'gemini');

        $response = $this->make_request('generateContent', [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => 'You are an expert SEO consultant specializing in homepage meta optimization. Provide actionable, specific recommendations in JSON format.']
                ]
            ],
            'generationConfig' => $this->build_generation_config([
                'maxOutputTokens' => 1200, // Higher limit for Gemini homepage meta
                'temperature' => 0.4,
            ])
        ]);

        return $this->parse_homepage_meta_response($response);
    }

    /**
     * Optimize homepage hero content
     *
     * @since 1.0.0
     *
     * @param array $hero_data Hero content data
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
        $prompt = $prompt_builder->build_homepage_hero_prompt($hero_data, $business_type, $target_audience, $tone, $context, 'gemini');

        $response = $this->make_request('generateContent', [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => 'You are an expert SEO consultant specializing in homepage hero optimization. Provide actionable, specific recommendations in JSON format.']
                ]
            ],
            'generationConfig' => $this->build_generation_config([
                'maxOutputTokens' => 1200, // Higher limit for Gemini homepage hero
                'temperature' => 0.4,
            ])
        ]);

        return $this->parse_homepage_hero_response($response);
    }

    /**
     * Optimize LLMs.txt content
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
        $prompt = $prompt_builder->build_llms_txt_prompt($website_data, $options, 'gemini');

        $response = $this->make_request('generateContent', [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => $this->build_generation_config([
                'maxOutputTokens' => 2000, // Increased for Gemini 2.5 Flash compatibility
                'temperature' => 0.4,
            ])
        ]);

        return $this->parse_llms_txt_response($response);
    }

    /**
     * Generate completion using Gemini API
     * 
     * @param string $prompt Prompt to send
     * @param array $options Generation options
     * @return array API response
     * @throws \Exception If request fails
     */
    public function generate_completion(string $prompt, array $options = []): array {
        $max_tokens = $options['max_tokens'] ?? 1000;
        $temperature = $options['temperature'] ?? 0.7;

        return $this->make_request('generateContent', [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => $this->build_generation_config([
                'maxOutputTokens' => $max_tokens,
                'temperature' => $temperature,
            ])
        ]);
    }

    /**
     * Build the generationConfig for a request, disabling "thinking" on
     * Gemini 2.5 Flash models.
     *
     * Gemini 2.5 Flash / Flash-Lite enable an internal "thinking" phase by
     * default, and those thoughts are billed against maxOutputTokens. On smaller
     * budgets — especially the free API tier used with the default
     * gemini-2.5-flash model — thinking can consume most of the budget, leaving
     * the visible answer truncated (finishReason=MAX_TOKENS) with incomplete
     * JSON. Downstream parsers then fail with "parsing failed". Setting
     * thinkingBudget to 0 disables thinking so the entire budget is spent on the
     * JSON answer.
     *
     * Only 2.5 Flash models accept thinkingBudget=0; 2.5 Pro requires a minimum
     * budget and pre-2.5 models reject thinkingConfig outright, so the override
     * is scoped to Flash models to avoid 400 errors.
     *
     * @param array $config Caller-supplied generationConfig
     * @return array generationConfig with thinking disabled where supported
     */
    private function build_generation_config(array $config): array {
        if (strpos($this->model, 'gemini-2.5-flash') === 0) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        if (isset($config['maxOutputTokens'])) {
            $config['maxOutputTokens'] = min((int) $config['maxOutputTokens'], $this->get_max_output_tokens());
        }

        return $config;
    }

    /**
     * Maximum output tokens the current model accepts
     *
     * @since 1.21.0
     *
     * @return int Output token ceiling
     */
    private function get_max_output_tokens(): int {
        // Gemini 2.5 and newer allow 64k output; 1.5/2.0 cap at 8k.
        return preg_match('/^gemini-(2\.5|3)/', $this->model) ? 65536 : 8192;
    }

    /**
     * Get recommended token limit for specific use cases
     *
     * Mirrors the OpenAI/OpenRouter clients so callers can size a request
     * without knowing which provider is active. Without this method callers
     * fall back to a 4000-token budget, which a full content brief overruns
     * (the reply is then cut off mid-JSON and fails to parse).
     *
     * @since 1.21.0
     *
     * @param string $use_case Use case (e.g., 'content_brief', 'seo_metadata', 'analysis')
     * @return int Recommended token limit
     */
    public function get_recommended_tokens(string $use_case): int {
        $recommendations = [
            // Higher budget: the brief now also returns a full article body,
            // so the reply is much longer than the structured fields alone.
            'content_brief' => 16384,
            'seo_metadata'  => 800,
            'analysis'      => 1500,
            'llms_txt'      => 2000,
            'optimization'  => 2000,
        ];

        return min($recommendations[$use_case] ?? 1000, $this->get_max_output_tokens());
    }

    /**
     * Make request to Gemini API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response data
     * @throws \Exception If request fails
     */
    private function make_request(string $endpoint, array $data): array {
        // Send the API key in the x-goog-api-key header rather than the URL
        // query string, which is logged by servers, proxies and referrers.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:{$endpoint}";

        $response = $this->request_with_retry($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->api_key,
            ],
            'method' => 'POST',
            'body' => wp_json_encode($data),
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Gemini API request failed: ' . esc_html($response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['error']['message'] ?? 'Unknown error';
            throw new \Exception('Gemini API error (' . esc_html($status_code) . '): ' . esc_html($error_message));
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from Gemini API');
        }

        // A valid-but-scalar body (null/number/string from a proxy/gateway on a
        // 2xx) would violate this method's : array return type; reject it here so
        // it surfaces as a catchable \Exception, not an uncatchable TypeError.
        if (!is_array($decoded)) {
            throw new \Exception('Unexpected non-array response from Gemini API');
        }

        // Debug: Log token usage information
        if (isset($decoded['usageMetadata'])) {
            $usage = $decoded['usageMetadata'];
            $prompt_tokens = $usage['promptTokenCount'] ?? 0;
            $total_tokens = $usage['totalTokenCount'] ?? 0;
            $output_tokens = $total_tokens - $prompt_tokens;


        }

        return $decoded;
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
     * Parse SEO response from Gemini
     *
     * @param array $response Gemini response
     * @return array Parsed metadata
     * @throws \Exception If parsing fails
     */
    private function parse_seo_response(array $response): array {


        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Invalid response format from Gemini');
        }

        $content = trim($response['candidates'][0]['content']['parts'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Gemini response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $metadata = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in Gemini response: ' . esc_html(json_last_error_msg()));
        }

        // Validate required fields
        $required_fields = ['title', 'description', 'focus_keyword'];
        foreach ($required_fields as $field) {
            if (!isset($metadata[$field])) {
                throw new \Exception('Missing required field: ' . esc_html($field));
            }
        }

        return [
            'title' => sanitize_text_field($metadata['title']),
            'description' => sanitize_textarea_field($metadata['description']),
            'focus_keyword' => sanitize_text_field($metadata['focus_keyword']),
            'suggestions' => array_map('sanitize_text_field', $metadata['suggestions'] ?? []),
            'generated_at' => current_time('mysql'),
            'provider' => 'gemini',
            'model' => $this->model,
            'tokens_used' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse analysis response from Gemini
     *
     * @param array $response Gemini response
     * @return array Parsed analysis
     * @throws \Exception If parsing fails
     */
    private function parse_analysis_response(array $response): array {
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Invalid response format from Gemini');
        }

        $content = trim($response['candidates'][0]['content']['parts'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Gemini response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $analysis = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in Gemini response: ' . esc_html(json_last_error_msg()));
        }

        return [
            'seo_score' => absint($analysis['seo_score'] ?? 0),
            'content_analysis' => $analysis['content_analysis'] ?? [],
            'suggestions' => array_map('sanitize_text_field', $analysis['suggestions'] ?? []),
            'strengths' => array_map('sanitize_text_field', $analysis['strengths'] ?? []),
            'weaknesses' => array_map('sanitize_text_field', $analysis['weaknesses'] ?? []),
            'generated_at' => current_time('mysql'),
            'provider' => 'gemini',
            'model' => $this->model,
            'tokens_used' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse site identity optimization response
     *
     * @param array $response Gemini API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_site_identity_response(array $response): array {
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            // Check if content was blocked by safety filters
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'SAFETY') {
                throw new \Exception('Content was blocked by Gemini safety filters. Please try rephrasing your request.');
            }
            // Check if response was truncated due to token limit
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'MAX_TOKENS') {
                throw new \Exception('Gemini response was truncated due to token limit. Please try a shorter request or increase token limit.');
            }
            // Check if there are no candidates
            if (!isset($response['candidates']) || empty($response['candidates'])) {
                throw new \Exception('No response candidates from Gemini. The request may have been filtered.');
            }
            // Check if content exists but parts are missing
            if (isset($response['candidates'][0]['content']) && !isset($response['candidates'][0]['content']['parts'])) {
                throw new \Exception('Gemini response missing content parts. The response may be incomplete.');
            }
            throw new \Exception('Invalid response format from Gemini');
        }

        $content = trim($response['candidates'][0]['content']['parts'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Gemini response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in Gemini response: ' . esc_html(json_last_error_msg()));
        }

        return [
            'optimized_data' => $data['optimized_data'] ?? [],
            'analysis' => sanitize_textarea_field($data['analysis'] ?? ''),
            'suggestions' => array_map('sanitize_text_field', $data['suggestions'] ?? []),
            'score' => absint($data['score'] ?? 0),
            'generated_at' => current_time('mysql'),
            'provider' => 'gemini',
            'model' => $this->model,
            'tokens_used' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse homepage meta optimization response
     *
     * @param array $response Gemini API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_meta_response(array $response): array {
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            // Check if content was blocked by safety filters
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'SAFETY') {
                throw new \Exception('Content was blocked by Gemini safety filters. Please try rephrasing your request.');
            }
            // Check if response was truncated due to token limit
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'MAX_TOKENS') {
                throw new \Exception('Gemini response was truncated due to token limit. Please try a shorter request or increase token limit.');
            }
            // Check if there are no candidates
            if (!isset($response['candidates']) || empty($response['candidates'])) {
                throw new \Exception('No response candidates from Gemini. The request may have been filtered.');
            }
            // Check if content exists but parts are missing
            if (isset($response['candidates'][0]['content']) && !isset($response['candidates'][0]['content']['parts'])) {
                throw new \Exception('Gemini response missing content parts. The response may be incomplete.');
            }
            throw new \Exception('Invalid response format from Gemini');
        }

        $content = trim($response['candidates'][0]['content']['parts'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Gemini response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in Gemini response: ' . esc_html(json_last_error_msg()));
        }

        return [
            'optimized_data' => $data['optimized_data'] ?? [],
            'analysis' => sanitize_textarea_field($data['analysis'] ?? ''),
            'suggestions' => array_map('sanitize_text_field', $data['suggestions'] ?? []),
            'score' => absint($data['score'] ?? 0),
            'generated_at' => current_time('mysql'),
            'provider' => 'gemini',
            'model' => $this->model,
            'tokens_used' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse homepage hero optimization response
     *
     * @param array $response Gemini API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_hero_response(array $response): array {
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            // Check if content was blocked by safety filters
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'SAFETY') {
                throw new \Exception('Content was blocked by Gemini safety filters. Please try rephrasing your request.');
            }
            // Check if response was truncated due to token limit
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'MAX_TOKENS') {
                throw new \Exception('Gemini response was truncated due to token limit. Please try a shorter request or increase token limit.');
            }
            // Check if there are no candidates
            if (!isset($response['candidates']) || empty($response['candidates'])) {
                throw new \Exception('No response candidates from Gemini. The request may have been filtered.');
            }
            // Check if content exists but parts are missing
            if (isset($response['candidates'][0]['content']) && !isset($response['candidates'][0]['content']['parts'])) {
                throw new \Exception('Gemini response missing content parts. The response may be incomplete.');
            }
            throw new \Exception('Invalid response format from Gemini');
        }

        $content = trim($response['candidates'][0]['content']['parts'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Gemini response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in Gemini response: ' . esc_html(json_last_error_msg()));
        }

        return [
            'optimized_data' => $data['optimized_data'] ?? [],
            'analysis' => sanitize_textarea_field($data['analysis'] ?? ''),
            'suggestions' => array_map('sanitize_text_field', $data['suggestions'] ?? []),
            'score' => absint($data['score'] ?? 0),
            'generated_at' => current_time('mysql'),
            'provider' => 'gemini',
            'model' => $this->model,
            'tokens_used' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse LLMs.txt optimization response
     *
     * @param array $response Gemini API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_llms_txt_response(array $response): array {
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            // Check if content was blocked by safety filters
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'SAFETY') {
                throw new \Exception('Content was blocked by Gemini safety filters. Please try rephrasing your request.');
            }
            // Check if response was truncated due to token limit
            if (isset($response['candidates'][0]['finishReason']) && $response['candidates'][0]['finishReason'] === 'MAX_TOKENS') {
                throw new \Exception('Gemini response was truncated due to token limit. Please try a shorter request or increase token limit.');
            }
            // Check if there are no candidates
            if (!isset($response['candidates']) || empty($response['candidates'])) {
                throw new \Exception('No response candidates from Gemini. The request may have been filtered.');
            }
            // Check if content exists but parts are missing
            if (isset($response['candidates'][0]['content']) && !isset($response['candidates'][0]['content']['parts'])) {
                throw new \Exception('Gemini response missing content parts. The response may be incomplete.');
            }
            throw new \Exception('Invalid response format from Gemini');
        }

        $content = trim($response['candidates'][0]['content']['parts'][0]['text']);
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Extract JSON from response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in Gemini response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in Gemini response: ' . esc_html(json_last_error_msg()));
        }

        // Match the structure expected by AI Manager (same as OpenAI/Claude)
        return [
            'optimized_data' => [
                'site_name' => sanitize_text_field($data['optimized_data']['site_name'] ?? ''),
                'project_overview' => sanitize_textarea_field($data['optimized_data']['project_overview'] ?? ''),
                'key_features' => sanitize_textarea_field($data['optimized_data']['key_features'] ?? ''),
                'architecture' => sanitize_textarea_field($data['optimized_data']['architecture'] ?? ''),
                'development_guidelines' => sanitize_textarea_field($data['optimized_data']['development_guidelines'] ?? ''),
                'ai_context' => sanitize_textarea_field($data['optimized_data']['ai_context'] ?? ''),
            ],
            'suggestions' => array_map('sanitize_text_field', $data['suggestions'] ?? []),
            'generated_at' => current_time('mysql'),
            'provider' => 'gemini',
            'model' => $this->model,
            'tokens_used' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }
}
