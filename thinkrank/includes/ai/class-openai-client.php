<?php
/**
 * OpenAI API Client
 * 
 * Handles communication with OpenAI API
 * 
 * @package ThinkRank\AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\AI\Traits\Request_Timeout;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/traits/trait-request-timeout.php';
require_once __DIR__ . '/class-endpoint-url-validator.php';

/**
 * OpenAI Client Class
 *
 * Single Responsibility: Handle OpenAI API communication
 *
 * @since 1.0.0
 */
class OpenAI_Client {

    use Request_Timeout;

    
    /**
     * OpenAI's own API base URL — the default when no other is given.
     */
    public const API_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Base URL every request is built on.
     *
     * Not always OpenAI's: the `openai_compatible` provider (#721) points this
     * at any server speaking the Chat Completions API — Ollama, LM Studio,
     * vLLM, Azure OpenAI, a company gateway — so the rest of this client, the
     * retry policy and the response parsing are shared verbatim.
     *
     * @since 2.8.0
     * @var string
     */
    private string $base_url;
    
    /**
     * Ceiling for a response body from a user-named endpoint.
     *
     * Mirrors Endpoint_URL_Validator::MAX_RESPONSE_BYTES; kept here too so the
     * truncation message and the transport limit cannot drift apart.
     */
    private const MAX_RESPONSE_BYTES = 2097152; // 2 MB.

    /**
     * Output-token ceiling for an unrecognised model on a user-named endpoint.
     *
     * See get_max_completion_tokens().
     */
    private const COMPATIBLE_DEFAULT_MAX_TOKENS = 8192;

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
     * Ask a custom endpoint to constrain JSON answers with response_format.
     *
     * Off by default because not every compatible server takes it (LM Studio
     * accepts only json_schema). See set_json_mode().
     *
     * @since 2.8.0
     * @var bool
     */
    private bool $json_mode = false;

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
     * @param string $api_key  OpenAI API key (may be empty for a local server that wants none).
     * @param string $model    Default model to use
     * @param int    $timeout  Request timeout
     * @param string $base_url API base URL without a trailing slash; defaults to OpenAI's.
     */
    public function __construct(string $api_key, string $model = \ThinkRank\Core\Settings::DEFAULT_OPENAI_MODEL, int $timeout = 30, string $base_url = self::API_BASE_URL) {
        $this->api_key = $api_key;
        $this->model = $model;
        $this->timeout = $timeout;
        $base_url = rtrim(trim($base_url), '/');
        $this->base_url = '' !== $base_url ? $base_url : self::API_BASE_URL;
    }

    /**
     * Turn on response_format: json_object for calls that want a JSON object.
     *
     * ThinkRank otherwise enforces JSON through the prompt alone, and a small
     * local model then writes an unescaped quote inside a string (DeepSeek-R1
     * 7B quoting a phrase in the brief's HTML body) and the whole brief fails
     * to parse. Ollama, vLLM and llama.cpp turn this field into a grammar, so
     * the reply cannot be malformed JSON. Only calls passing the `json_object`
     * option get it: a plain-text caller would be forced into JSON too.
     *
     * @since 2.8.0
     *
     * @param bool $enabled Whether the endpoint accepts response_format json_object.
     * @return void
     */
    public function set_json_mode(bool $enabled): void {
        $this->json_mode = $enabled;
    }

    /**
     * Get the base URL this client talks to.
     *
     * @since 2.8.0
     *
     * @return string Base URL without a trailing slash.
     */
    public function get_base_url(): string {
        return $this->base_url;
    }

    /**
     * Is this client pointed at a server other than OpenAI's?
     *
     * Used for error copy: naming "OpenAI" in a failure from someone's local
     * Ollama box sends them to the wrong place to debug it.
     *
     * @since 2.8.0
     *
     * @return bool
     */
    private function is_custom_endpoint(): bool {
        return self::API_BASE_URL !== $this->base_url;
    }

    /**
     * Name to use for this endpoint in user-facing messages.
     *
     * @since 2.8.0
     *
     * @return string
     */
    private function get_endpoint_label(): string {
        if (!$this->is_custom_endpoint()) {
            return 'OpenAI';
        }

        $host = wp_parse_url($this->base_url, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? $host : __('the AI endpoint', 'thinkrank');
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
     * Generate completion using OpenAI
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

        $body = $this->build_chat_completion_body($prompt, $options);

        return $this->make_request('chat/completions', $body);
    }

    /**
     * Build the chat/completions request body for the given (merged) options.
     *
     * Extracted so the per-model-family parameter handling is unit-testable:
     * reasoning models take max_completion_tokens (and only the GPT-5 family
     * accepts reasoning_effort — o1/o3 reject it), while standard models take
     * temperature/top_p/penalties/max_tokens. Keeping this in one place stops a
     * future refactor from silently regressing the GPT-5-only guard (issue #286).
     *
     * @param string $prompt  User prompt.
     * @param array  $options Merged options (must include model, max_tokens, and
     *                        the sampling defaults; reasoning_effort and
     *                        json_object optional).
     * @return array Request body for the chat/completions endpoint.
     */
    private function build_chat_completion_body(string $prompt, array $options): array {
        $body = [
            'model' => $options['model'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ]
            ],
        ];

        // Get safe token limit for this model
        $safe_tokens = $this->get_safe_token_limit($options['model'], $options['max_tokens']);

        // Add parameters based on model type
        if ($this->is_reasoning_model($options['model'])) {
            // Reasoning models (o1/o3) have fixed parameters and restricted support
            // temperature, top_p, frequency_penalty, presence_penalty are not supported
            $body['max_completion_tokens'] = $safe_tokens;

            // GPT-5 models accept reasoning_effort ('minimal'…'high'). Callers
            // wanting a quick answer pass a low level so hidden reasoning
            // can't consume the whole completion budget and return empty
            // text. Only the GPT-5
            // family gets it: o1 rejects the parameter outright.
            if (isset($options['reasoning_effort']) && str_starts_with($options['model'], 'gpt-5')) {
                $body['reasoning_effort'] = (string) $options['reasoning_effort'];
            }
        } else {
            // Standard models support all parameters
            $body['temperature'] = $options['temperature'];
            $body['top_p'] = $options['top_p'];
            $body['frequency_penalty'] = $options['frequency_penalty'];
            $body['presence_penalty'] = $options['presence_penalty'];
            $body['max_tokens'] = $safe_tokens;
        }

        if ($this->json_mode && $this->is_custom_endpoint() && !empty($options['json_object'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $body;
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
        $prompt = $prompt_builder->build_seo_prompt($content, $target_keyword, $content_type, $tone, 'openai', $language);
        
        $response = $this->generate_completion($prompt, [
            'max_tokens' => $this->get_recommended_tokens('seo_metadata'),
            'temperature' => 0.3, // Lower temperature for more consistent SEO output
            'json_object' => true,
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
        $prompt = $prompt_builder->build_analysis_prompt($content, $metadata, 'openai');

        $response = $this->generate_completion($prompt, [
            'max_tokens' => $this->get_recommended_tokens('analysis'),
            'temperature' => 0.3, // Lower temperature for more consistent analysis
            'json_object' => true,
        ]);

        return $this->parse_analysis_response($response);
    }
    private function is_reasoning_model(string $model): bool {
        // Models that require max_completion_tokens and restrict parameters (no temperature/top_p)
        // Includes OpenAI o1/o3 series and GPT-5 family
        $reasoning_models = [
            'o1-preview',
            'o1-mini',
            'o3-mini',
            'o3-2024-12-17',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
        ];

        // Check for exact matches or model prefixes
        foreach ($reasoning_models as $reasoning_model) {
            if ($model === $reasoning_model || strpos($model, $reasoning_model) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get maximum completion tokens for a model
     *
     * @param string $model Model name
     * @return int Maximum completion tokens
     */
    private function get_max_completion_tokens(string $model): int {
        // Model-specific token limits (completion tokens, not context)
        $token_limits = [
            // GPT-5 series (optimized for reasoning + content tokens based on usage data)
            'gpt-5' => 20480,        // 20K tokens for full GPT-5
            'gpt-5-mini' => 15360,   // 15K tokens for mini variant (increased)
            'gpt-5-nano' => 12288,   // 12K tokens for nano (increased from 10K for better buffer)

            // GPT-4o series
            'gpt-4o' => 4096,
            'gpt-4o-2024-08-06' => 4096,
            'gpt-4o-2024-05-13' => 4096,
            'gpt-4o-mini' => 16384,
            'gpt-4o-mini-2024-07-18' => 16384,

            // o1/o3 reasoning models (higher limits)
            'o1-preview' => 32768,
            'o1-mini' => 65536,
            'o3-mini' => 65536,
            'o3-2024-12-17' => 65536,
        ];

        // Check for exact match first
        if (isset($token_limits[$model])) {
            return $token_limits[$model];
        }

        // Check for partial matches (for versioned models)
        foreach ($token_limits as $known_model => $limit) {
            if (strpos($model, $known_model) === 0) {
                return $limit;
            }
        }

        // A model we have never heard of on OpenAI's own API gets a
        // conservative ceiling. On a user-named server nearly every model is
        // unknown, and 4096 left a brief ~2.9K tokens after length scaling,
        // which a reasoning model (DeepSeek-R1, Qwen3) spends half of thinking
        // before the JSON starts. max_tokens is a cap, not a reservation, so a
        // higher one costs nothing on a model that finishes early.
        if ($this->is_custom_endpoint()) {
            /**
             * Filters the output-token ceiling for an unrecognised model on an
             * OpenAI-compatible endpoint.
             *
             * Lower it for a server that rejects max_tokens beyond its context
             * window (vLLM does); raise it for a large-context local model.
             *
             * @since 2.8.0
             *
             * @param int    $limit Ceiling in tokens. Default 8192.
             * @param string $model Model id sent to the endpoint.
             */
            $limit = (int) apply_filters('thinkrank_openai_compatible_max_output_tokens', self::COMPATIBLE_DEFAULT_MAX_TOKENS, $model);
            return $limit > 0 ? $limit : self::COMPATIBLE_DEFAULT_MAX_TOKENS;
        }

        // Default fallback for unknown models
        return 4096;
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

        // For reasoning models (GPT-5, o1, o3), we need much higher token limits
        // because they use reasoning tokens + content tokens
        if ($this->is_reasoning_model($this->model)) {
            $reasoning_recommendations = [
                'content_brief' => 0.95,  // 95% of max tokens (reasoning + content)
                'seo_metadata' => 0.8,    // 80% for reasoning models (safe buffer)
                'analysis' => 0.85,       // 85% for reasoning models (safe buffer)
                'llms_txt' => 0.8,        // 80% for reasoning models (safe buffer)
                'optimization' => 0.9,    // 90% for reasoning models (based on 5.9K usage + 50% buffer)
            ];
            $percentage = $reasoning_recommendations[$use_case] ?? 0.3;
        } else {
            // Standard models - original percentages
            $standard_recommendations = [
                'content_brief' => 0.9,   // 90% of max tokens for comprehensive briefs
                'seo_metadata' => 0.15,   // 15% of max tokens for metadata
                'analysis' => 0.25,       // 25% of max tokens for analysis
                'llms_txt' => 0.5,        // 50% of max tokens for llms.txt
                'optimization' => 0.15,   // 15% of max tokens for optimization
            ];
            $percentage = $standard_recommendations[$use_case] ?? 0.15;
        }

        return (int) ($max_tokens * $percentage);
    }

    /**
     * Build request body for chat completions with model-specific parameters
     *
     * @param string $user_prompt User prompt
     * @param string|null $system_prompt System prompt (ignored for reasoning models)
     * @param int $max_tokens Maximum tokens
     * @param float $temperature Temperature (ignored for reasoning models)
     * @return array Request body
     */
    private function build_chat_request(string $user_prompt, ?string $system_prompt = null, int $max_tokens = 600, float $temperature = 0.4): array {
        $body = [
            'model' => $this->model,
        ];

        // Get safe token limit for this model
        $safe_tokens = $this->get_safe_token_limit($this->model, $max_tokens);

        // Build messages based on model type
        if ($this->is_reasoning_model($this->model)) {
            // Reasoning/GPT‑5 family: use max_completion_tokens and omit temperature
            $content = $user_prompt;
            // Some GPT‑5 responses may expect array content parts; we'll send string
            $body['messages'] = [
                [
                    'role' => 'user',
                    'content' => $content
                ]
            ];
            $body['max_completion_tokens'] = $safe_tokens;
        } else {
            // Standard models support system messages and temperature
            $messages = [];
            if ($system_prompt) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $system_prompt
                ];
            }
            $messages[] = [
                'role' => 'user',
                'content' => $user_prompt
            ];

            $body['messages'] = $messages;
            $body['temperature'] = $temperature;
            $body['max_tokens'] = $safe_tokens;
        }

        return $body;
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
            $response = $this->make_request('models');
            return isset($response['data']) && is_array($response['data']);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Make API request to OpenAI
     * 
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @return array Response data
     * @throws \Exception If request fails
     */
    private function make_request(string $endpoint, array $body = []): array {
        // The user's daily ceiling and kill switch are enforced here, at the
        // one place every outbound OpenAI call passes through, so no feature
        // path can bypass them by forgetting to ask first (#448).
        Spend_Guard::guard();
        Spend_Guard::record();

        // Not string concatenation: a base URL may carry a query string (Azure
        // requires ?api-version=…), and the route has to land before it (#721).
        $url = Endpoint_URL_Validator::route($this->base_url, $endpoint);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'ThinkRank/' . THINKRANK_VERSION,
        ];

        // A local Ollama or LM Studio server wants no key at all; sending
        // "Bearer " with nothing after it makes some gateways 401.
        if ('' !== $this->api_key) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
            // Azure OpenAI reads the key from its own header and ignores
            // Authorization. Sending both costs nothing and makes an Azure
            // deployment URL work without a separate provider.
            $headers['api-key'] = $this->api_key;
        }

        $args = [
            'timeout' => $this->timeout,
            'headers' => $headers,
            // Only for an endpoint we do not control: a ceiling generous enough
            // for the largest thing we ask for (a content brief as JSON), so a
            // server that ignores its token limit still cannot spend the
            // worker's memory. Truncation is reported below, not parsed (#721).
            'limit_response_size' => $this->is_custom_endpoint() ? self::MAX_RESPONSE_BYTES : null,
            // The key travels in these headers. A redirect to another host
            // would hand it to whoever controls that host, so never follow one
            // (#721) — a moved endpoint is the administrator's URL to fix.
            'redirection' => 0,
        ];
        
        if (null === $args['limit_response_size']) {
            unset($args['limit_response_size']);
        }

        $args['method'] = empty($body) ? 'GET' : 'POST';
        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = $this->request_with_retry($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . esc_html($response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // JSON mode is the administrator's claim that this server takes
        // response_format. A server that does not (LM Studio wants json_schema)
        // answers 400 and names the field; drop it and ask once more, so a
        // wrong toggle costs a round trip rather than the feature. The rest of
        // this request's calls skip the field instead of failing first.
        if ($this->should_retry_without_response_format($body, (int) $status_code, (string) $response_body)) {
            $this->json_mode = false;
            unset($body['response_format']);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic, WP_DEBUG only.
                error_log('[ThinkRank] ' . $this->get_endpoint_label() . ' rejected response_format; retrying without JSON mode.');
            }

            $args['body'] = wp_json_encode($body);
            $response = $this->request_with_retry($url, $args);

            if (is_wp_error($response)) {
                throw new \Exception('API request failed: ' . esc_html($response->get_error_message()));
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
        }

        // A body that reached the ceiling was cut mid-JSON. Say so, rather than
        // letting it fail as "invalid JSON" — the cause and the fix are
        // different things.
        if ($this->is_custom_endpoint() && strlen($response_body) >= self::MAX_RESPONSE_BYTES) {
            throw new \Exception(sprintf(
                /* translators: %s: endpoint name or host. */
                esc_html__('%s sent more than ThinkRank will read (2 MB). The endpoint is misconfigured or is not answering with a chat completion.', 'thinkrank'),
                esc_html($this->get_endpoint_label())
            ));
        }

        if ($status_code >= 400) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? ($error_data['message'] ?? 'Unknown API error');
            throw new \Exception(sprintf(
                /* translators: 1: endpoint name or host, 2: HTTP status code, 3: error message from the server. */
                esc_html__('%1$s API error (%2$d): %3$s', 'thinkrank'),
                esc_html($this->get_endpoint_label()),
                (int) $status_code,
                esc_html((string) $error_message)
            ));
        }
        
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(sprintf(
                /* translators: %s: endpoint name or host. */
                esc_html__('Invalid JSON response from %s', 'thinkrank'),
                esc_html($this->get_endpoint_label())
            ));
        }

        // A local reasoning model (deepseek-r1, a qwen3 thinking build) spends
        // its token budget on hidden reasoning before writing anything, and
        // Ollama and vLLM bill that against max_tokens — so the budget runs out
        // mid-thought and the reply comes back 200 with empty content and
        // finish_reason "length". Every generator then fails with "no valid
        // JSON", which blames the model for a budget problem. Ask once more
        // with reasoning turned off, which those servers accept as
        // reasoning_effort: none (#721).
        if (is_array($data) && $this->should_retry_without_reasoning($endpoint, $body, $data)) {
            $retry_body = $body;
            $retry_body['reasoning_effort'] = 'none';

            $retry_args = $args;
            $retry_args['body'] = wp_json_encode($retry_body);
            $retry = $this->request_with_retry($url, $retry_args);

            if (!is_wp_error($retry) && wp_remote_retrieve_response_code($retry) < 400) {
                $retry_data = json_decode(wp_remote_retrieve_body($retry), true);
                // Keep the first answer when the retry is no better — a server
                // that ignores the parameter answers exactly the same way, and
                // the error below is then the honest one.
                if (is_array($retry_data) && '' !== trim((string) ($retry_data['choices'][0]['message']['content'] ?? ''))) {
                    return $retry_data;
                }
            }

            throw new \Exception(sprintf(
                /* translators: %s: endpoint name or host. */
                esc_html__('%s answered with no text: the model spent its whole token budget on hidden reasoning. Use a non-reasoning model, or raise the token budget for this endpoint.', 'thinkrank'),
                esc_html($this->get_endpoint_label())
            ));
        }

        // A valid-but-scalar body (null/number/string from a proxy/gateway on a
        // 2xx) would violate this method's : array return type; reject it here so
        // it surfaces as a catchable \Exception, not an uncatchable TypeError.
        if (!is_array($data)) {
            throw new \Exception(sprintf(
                /* translators: %s: endpoint name or host. */
                esc_html__('Unexpected non-array response from %s', 'thinkrank'),
                esc_html($this->get_endpoint_label())
            ));
        }

        return $data;
    }

    /**
     * Did the server refuse the request because of response_format?
     *
     * Only when we sent the field, the server answered 400 or 422 (vLLM and
     * FastAPI-based servers use 422 for a schema error), and the error text
     * names the field or a JSON-mode type. Any other 400 (wrong model id, a
     * prompt over the context window) is a real error, and resending without
     * the field would only repeat it.
     *
     * @since 2.8.0
     *
     * @param array  $body          Request body that was sent.
     * @param int    $status_code   HTTP status of the answer.
     * @param string $response_body Raw answer body.
     * @return bool
     */
    private function should_retry_without_response_format(array $body, int $status_code, string $response_body): bool {
        if (!isset($body['response_format']) || !in_array($status_code, [400, 422], true)) {
            return false;
        }

        $error = strtolower($response_body);
        foreach (['response_format', 'json_object', 'json_schema'] as $needle) {
            if (false !== strpos($error, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Did a chat completion come back empty because the model was still thinking?
     *
     * Three things have to be true: this is a chat completion against a custom
     * endpoint (OpenAI's own reasoning models manage their own budget through
     * max_completion_tokens), the content is empty, and the server stopped on
     * "length" or reported reasoning it never got to use.
     *
     * @since 2.8.0
     *
     * @param string $endpoint Endpoint path that was called.
     * @param array  $body     Request body that was sent.
     * @param array  $data     Decoded response.
     * @return bool
     */
    private function should_retry_without_reasoning(string $endpoint, array $body, array $data): bool {
        if (!$this->is_custom_endpoint() || 'chat/completions' !== ltrim($endpoint, '/')) {
            return false;
        }

        // Already asked without reasoning — a second identical attempt would
        // only cost the user another slow generation.
        if (isset($body['reasoning_effort'])) {
            return false;
        }

        $message = is_array($data['choices'][0]['message'] ?? null) ? $data['choices'][0]['message'] : null;
        if (null === $message || '' !== trim((string) ($message['content'] ?? ''))) {
            return false;
        }

        $finish = (string) ($data['choices'][0]['finish_reason'] ?? '');
        $reasoning = trim((string) ($message['reasoning'] ?? ($message['reasoning_content'] ?? '')));

        return 'length' === $finish || '' !== $reasoning;
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

            // A custom endpoint is a host the site owner named, not one of
            // ours: check where it actually resolves, pin the connection there
            // and cap the body before any of it is buffered (#721).
            $response = $this->is_custom_endpoint()
                ? Endpoint_URL_Validator::guarded_request($url, $args)
                : wp_remote_request($url, $args);

            $is_transient = false;
            $retry_after = 0;
            if (is_wp_error($response)) {
                // A client-side timeout means the work genuinely needs longer
                // than the budget we allowed; re-running the identical prompt,
                // model and budget just times out again and multiplies the
                // wait (issue #288). Do not retry a timeout. Other WP_Error
                // results — DNS, connection refused, TLS — stay retryable.
                $is_transient = !$this->is_timeout_error($response);
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

            // Honor Retry-After, else exponential backoff (1s, 2s, 4s…), capped.
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
            // Cover the request timeout plus a small buffer for connection
            // setup and response handling.
            @set_time_limit($this->timeout + 45); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit() warns when disabled by host policy; the guard is intentional.
        }
    }

    /**
     * Parse SEO response from OpenAI
     * 
     * @param array $response OpenAI response
     * @return array Parsed metadata
     * @throws \Exception If parsing fails
     */
    private function parse_seo_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenAI');
        }

        $content = $response['choices'][0]['message']['content'];
        $ai_text = $content; // Store the raw AI-generated text (Content Brief pattern)

        // Try to extract JSON from the response
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if (false === $json_start || false === $json_end) {
            throw new \Exception('No valid JSON found in OpenAI response');
        }
        
        $json_content = substr($content, $json_start, $json_end - $json_start + 1);
        $metadata = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON from OpenAI response');
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
     * Parse analysis response from OpenAI
     *
     * @param array $response OpenAI API response
     * @return array Parsed analysis data
     * @throws \Exception If parsing fails
     */
    private function parse_analysis_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenAI');
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
     * Optimize site identity using OpenAI
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
        $prompt = $prompt_builder->build_site_identity_prompt($site_data, $business_type, $target_audience, $tone, 'openai');

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
     * @param array $response OpenAI API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_site_identity_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenAI');
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
        $prompt = $prompt_builder->build_homepage_meta_prompt($content_data, $business_type, $target_audience, $tone, $context, 'openai');

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
        $prompt = $prompt_builder->build_homepage_hero_prompt($hero_data, $business_type, $target_audience, $tone, $context, 'openai');

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
     * Optimize LLMs.txt content using OpenAI
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
        $prompt = $prompt_builder->build_llms_txt_prompt($website_data, $options, 'openai');

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
     * @param array $response OpenAI API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_llms_txt_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenAI');
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
     * @param array $response OpenAI API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_meta_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenAI');
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
     * @param array $response OpenAI API response
     * @return array Parsed optimization data
     * @throws \Exception If parsing fails
     */
    private function parse_homepage_hero_response(array $response): array {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response format from OpenAI');
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
