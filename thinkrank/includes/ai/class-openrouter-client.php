<?php
/**
 * OpenRouter API Client
 *
 * Handles communication with the OpenRouter API. OpenRouter exposes an
 * OpenAI-compatible Chat Completions endpoint that proxies many underlying
 * models (OpenAI, Anthropic, Google, Meta, DeepSeek, …) behind a single key,
 * so this client mirrors the OpenAI_Client request/response handling.
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
 * OpenRouter Client Class
 *
 * Single Responsibility: Handle OpenRouter API communication
 *
 * @since 1.0.0
 */
class OpenRouter_Client {

    /**
     * OpenRouter API base URL
     */
    private const API_BASE_URL = 'https://openrouter.ai/api/v1';

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
     * @param string $api_key OpenRouter API key
     * @param string $model Default model to use
     * @param int $timeout Request timeout
     */
    public function __construct(string $api_key, string $model = 'openai/gpt-4o-mini', int $timeout = 30) {
        $this->api_key = $api_key;
        $this->model = $model;
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
     * Get current provider key.
     *
     * @return string Provider identifier.
     */
    public function get_provider(): string {
        return 'openrouter';
    }

    /**
     * Generate completion using OpenRouter
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
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ];

        $options = array_merge($default_options, $options);

        // Cap the request to a safe ceiling so an arbitrary downstream model is
        // never asked for more than it can return.
        $safe_tokens = $this->get_safe_token_limit($options['model'], $options['max_tokens']);

        $body = [
            'model' => $options['model'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ]
            ],
            'temperature' => $options['temperature'],
            'top_p' => $options['top_p'],
            'frequency_penalty' => $options['frequency_penalty'],
            'presence_penalty' => $options['presence_penalty'],
            'max_tokens' => $safe_tokens,
        ];

        return $this->make_request('chat/completions', $body);
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
        $prompt = $prompt_builder->build_seo_prompt($content, $target_keyword, $content_type, $tone, 'openrouter', $language);

        $response = $this->generate_completion($prompt, [
            'max_tokens' => $this->get_recommended_tokens('seo_metadata'),
            'temperature' => 0.3, // Lower temperature for more consistent SEO output
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
        $prompt = $prompt_builder->build_analysis_prompt($content, $metadata, 'openrouter');

        $response = $this->generate_completion($prompt, [
            'max_tokens' => $this->get_recommended_tokens('analysis'),
            'temperature' => 0.3, // Lower temperature for more consistent analysis
        ]);

        return $this->parse_analysis_response($response);
    }

    /**
     * Get maximum completion tokens for a model.
     *
     * OpenRouter normalises `max_tokens` across very different underlying
     * models, so we apply a single conservative ceiling rather than per-model
     * limits.
     *
     * @param string $model Model name
     * @return int Maximum completion tokens
     */
    private function get_max_completion_tokens(string $model): int {
        return 8192;
    }

    /**
     * Get safe token limit for a request
     *
     * @param string $model Model name
     * @param int $requested_tokens Requested token count
     * @return int Safe token count (capped at model limit)
     */
    public function get_safe_token_limit(string $model, int $requested_tokens): int {
        $max_tokens = $this->get_max_completion_tokens($model);
        return min($requested_tokens, $max_tokens);
    }

    /**
     * Get recommended token limit for specific use cases
     *
     * @param string $use_case Use case (e.g., 'content_brief', 'seo_metadata', 'analysis')
     * @return int Recommended token limit
     */
    public function get_recommended_tokens(string $use_case): int {
        $max_tokens = $this->get_max_completion_tokens($this->model);

        $recommendations = [
            'content_brief' => 0.9,   // 90% of max tokens for comprehensive briefs
            'seo_metadata' => 0.2,    // 20% of max tokens for metadata
            'analysis' => 0.3,        // 30% of max tokens for analysis
            'llms_txt' => 0.5,        // 50% of max tokens for llms.txt
            'optimization' => 0.2,    // 20% of max tokens for optimization
        ];
        $percentage = $recommendations[$use_case] ?? 0.2;

        return (int) ($max_tokens * $percentage);
    }

    /**
     * Build request body for chat completions.
     *
     * @param string $user_prompt User prompt
     * @param string|null $system_prompt Optional system prompt
     * @param int $max_tokens Maximum tokens
     * @param float $temperature Temperature
     * @return array Request body
     */
    private function build_chat_request(string $user_prompt, ?string $system_prompt = null, int $max_tokens = 600, float $temperature = 0.4): array {
        $messages = [];
        if ($system_prompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $user_prompt,
        ];

        return [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $this->get_safe_token_limit($this->model, $max_tokens),
        ];
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
            // The key endpoint validates the credential and returns its metadata.
            $response = $this->make_request('key');
            return isset($response['data']) && is_array($response['data']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Make API request to OpenRouter
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
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => 'ThinkRank/' . THINKRANK_VERSION,
                // Optional attribution headers used by OpenRouter for ranking.
                'HTTP-Referer' => home_url('/'),
                'X-Title' => 'ThinkRank',
            ],
        ];

        if (!empty($body)) {
            $args['method'] = 'POST';
            $args['body'] = wp_json_encode($body);
        }

        // Keep PHP alive for the whole blocking call (see method docblock).
        $this->raise_request_time_limit();

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . esc_html($response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code >= 400) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Unknown API error';
            throw new \Exception(sprintf('OpenRouter API error (%d): %s', (int) $status_code, esc_html($error_message)));
        }

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from OpenRouter API');
        }

        // A valid-but-scalar body (null/number/string from a proxy/gateway on a
        // 2xx) would violate this method's : array return type; reject it here so
        // it surfaces as a catchable \Exception, not an uncatchable TypeError.
        if (!is_array($data)) {
            throw new \Exception('Unexpected non-array response from OpenRouter API');
        }

        return $data;
    }

    /**
     * Give PHP enough execution time to outlive a blocking AI HTTP request.
     *
     * The provider call blocks for up to $this->timeout seconds, but the web
     * SAPI's default max_execution_time (commonly 30s) is shorter — so PHP
     * fatally terminates the script mid-request (inside the cURL transport),
     * which the web server surfaces as a 502 Bad Gateway. Resetting the limit
     * before the call keeps the script alive for the full request; PHP-FPM's
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
     * Parse SEO response from OpenRouter
     *
     * @param array $response OpenRouter response
     * @return array Parsed metadata
     * @throws \Exception If parsing fails
     */
    private function parse_seo_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenRouter');
        }

        $content = $response['choices'][0]['message']['content'];
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Try to extract JSON from the response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in OpenRouter response');
        }

        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $metadata = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON from OpenRouter response');
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
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse analysis response from OpenRouter
     *
     * @param array $response OpenRouter API response
     * @return array Parsed analysis data
     * @throws \Exception If parsing fails
     */
    private function parse_analysis_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenRouter');
        }

        $content = trim($response['choices'][0]['message']['content']);
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
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Optimize site identity using OpenRouter
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
        $prompt = $prompt_builder->build_site_identity_prompt($site_data, $business_type, $target_audience, $tone, 'openrouter');

        $body = $this->build_chat_request(
            $prompt,
            'You are an expert SEO consultant specializing in site identity optimization. Provide actionable, specific recommendations in JSON format.',
            $this->get_recommended_tokens('optimization'),
            0.4
        );

        $response = $this->make_request('chat/completions', $body);

        return $this->parse_site_identity_response($response);
    }

    /**
     * Parse site identity optimization response
     *
     * @param array $response OpenRouter API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_site_identity_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenRouter');
        }

        $content = trim($response['choices'][0]['message']['content']);
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
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Optimize homepage meta content using OpenRouter
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
        $prompt = $prompt_builder->build_homepage_meta_prompt($content_data, $business_type, $target_audience, $tone, $context, 'openrouter');

        $body = $this->build_chat_request(
            $prompt,
            'You are an expert SEO consultant specializing in homepage meta optimization. Provide actionable, specific recommendations in JSON format.',
            $this->get_recommended_tokens('optimization'),
            0.4
        );

        $response = $this->make_request('chat/completions', $body);

        return $this->parse_homepage_meta_response($response);
    }

    /**
     * Optimize homepage hero content using OpenRouter
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
        $prompt = $prompt_builder->build_homepage_hero_prompt($hero_data, $business_type, $target_audience, $tone, $context, 'openrouter');

        $body = $this->build_chat_request(
            $prompt,
            'You are an expert conversion optimization specialist specializing in homepage hero sections. Provide actionable, specific recommendations in JSON format.',
            $this->get_recommended_tokens('optimization'),
            0.4
        );

        $response = $this->make_request('chat/completions', $body);

        return $this->parse_homepage_hero_response($response);
    }

    /**
     * Optimize LLMs.txt content using OpenRouter
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
        $prompt = $prompt_builder->build_llms_txt_prompt($website_data, $options, 'openrouter');

        $body = $this->build_chat_request(
            $prompt,
            'You are an expert technical writer specializing in creating llms.txt files for AI assistants. Provide structured, comprehensive content in JSON format.',
            $this->get_recommended_tokens('llms_txt'),
            0.4
        );

        $response = $this->make_request('chat/completions', $body);

        return $this->parse_llms_txt_response($response);
    }

    /**
     * Parse LLMs.txt optimization response
     *
     * @param array $response OpenRouter API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_llms_txt_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenRouter');
        }

        $content = trim($response['choices'][0]['message']['content']);
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
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse homepage meta optimization response
     *
     * @param array $response OpenRouter API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_meta_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenRouter');
        }

        $content = trim($response['choices'][0]['message']['content']);
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
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }

    /**
     * Parse homepage hero optimization response
     *
     * @param array $response OpenRouter API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_hero_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenRouter');
        }

        $content = trim($response['choices'][0]['message']['content']);
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
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            '_ai_text' => $ai_text, // Store the raw AI-generated text (Content Brief pattern)
        ];
    }
}
