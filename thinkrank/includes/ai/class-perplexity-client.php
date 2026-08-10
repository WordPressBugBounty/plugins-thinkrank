<?php
/**
 * Perplexity API Client (Brand Visibility probes only).
 *
 * Perplexity exposes an OpenAI-compatible Chat Completions endpoint, so the
 * request/response handling mirrors OpenAI_Client. It exists as its own client
 * for one reason: Perplexity answers are **search-grounded** — the model
 * retrieves live web results before answering — which makes it the closest
 * available proxy for what a real person sees in an AI answer engine.
 *
 * That is also why this client is deliberately NOT registered as a general
 * ThinkRank AI provider: it is tuned for "what would an assistant tell a user
 * about this brand", not for metadata generation or content briefs.
 *
 * @package ThinkRank\AI
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal Perplexity chat client used by the Brand Visibility runner.
 */
class Perplexity_Client {

    /**
     * Perplexity API base URL.
     */
    private const API_BASE_URL = 'https://api.perplexity.ai';

    /**
     * Default search-grounded model.
     */
    public const DEFAULT_MODEL = 'sonar';

    /**
     * API key.
     *
     * @var string
     */
    private string $api_key;

    /**
     * Model id.
     *
     * @var string
     */
    private string $model;

    /**
     * Request timeout, seconds.
     *
     * @var int
     */
    private int $timeout;

    /**
     * Constructor.
     *
     * @param string $api_key Perplexity API key.
     * @param string $model   Model id.
     * @param int    $timeout Timeout in seconds.
     */
    public function __construct(string $api_key, string $model = self::DEFAULT_MODEL, int $timeout = 45) {
        $this->api_key = $api_key;
        $this->model   = '' !== $model ? $model : self::DEFAULT_MODEL;
        $this->timeout = $timeout;
    }

    /**
     * Generate a completion.
     *
     * Returns the raw decoded body so Manager::request_completion() can read it
     * with the same `choices[0].message.content` path it uses for OpenAI.
     *
     * @param string $prompt  User prompt.
     * @param array  $options max_tokens, temperature.
     * @return array Decoded API response.
     * @throws \Exception On transport failure or a non-2xx response.
     */
    public function generate_completion(string $prompt, array $options = []): array {
        $body = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens'  => (int) ($options['max_tokens'] ?? 1000),
            'temperature' => (float) ($options['temperature'] ?? 0.4),
        ];

        $response = wp_remote_post(self::API_BASE_URL . '/chat/completions', [
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'ThinkRank/' . THINKRANK_VERSION,
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Perplexity request failed: ' . esc_html($response->get_error_message()));
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);

        if ($status >= 400) {
            $decoded = json_decode($raw, true);
            $message = $decoded['error']['message'] ?? ($decoded['detail'] ?? 'Unknown API error');
            throw new \Exception(sprintf('Perplexity API error (%d): %s', (int) $status, esc_html((string) $message)));
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new \Exception('Unexpected non-array response from Perplexity API');
        }

        return $data;
    }

    /**
     * Lightweight credential check.
     *
     * @return bool True when the key answers a minimal request.
     */
    public function test_connection(): bool {
        try {
            $this->generate_completion('Reply with OK.', ['max_tokens' => 5]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
