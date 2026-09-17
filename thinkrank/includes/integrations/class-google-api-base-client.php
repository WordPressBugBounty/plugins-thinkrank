<?php

/**
 * Google API Base Client Class
 *
 * Abstract base class for Google API integrations providing common functionality
 * for HTTP requests, rate limiting, error handling, and response processing.
 * Follows ThinkRank patterns established by Claude_Client and OpenAI_Client.
 *
 * @package ThinkRank\Integrations
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google API Base Client Class
 *
 * Single Responsibility: Provide common Google API functionality
 * Following ThinkRank HTTP client patterns from Claude_Client and OpenAI_Client
 *
 * @since 1.0.0
 */
abstract class Google_API_Base_Client {

    /**
     * API key
     *
     * @var string
     */
    protected string $api_key;

    /**
     * OAuth Access Token
     *
     * @var string|null
     */
    protected ?string $access_token = null;

    /**
     * Request timeout in seconds
     *
     * @var int
     */
    protected int $timeout;

    /**
     * Rate limit configuration
     *
     * @var array
     */
    protected array $rate_limits;

    /**
     * Constructor
     *
     * @param string $api_key Google API key
     * @param int $timeout Request timeout in seconds
     * @param string|null $access_token OAuth Access Token (optional)
     */
    public function __construct(string $api_key, int $timeout = 20, ?string $access_token = null) {
        $this->api_key = $api_key;
        $this->timeout = $timeout;
        $this->access_token = $access_token;
        $this->rate_limits = $this->get_rate_limits();
    }

    /**
     * Make HTTP request to Google API
     * Following Claude_Client and OpenAI_Client patterns
     *
     * @param string $url Full API URL
     * @param array $params Request parameters
     * @param string $method HTTP method
     * @return array Response data
     * @throws \Exception If request fails
     */
    protected function make_request(string $url, array $params = [], string $method = 'GET'): array {
        // Check rate limiting before making request
        $this->check_rate_limit();

        $args = [
            'timeout' => $this->timeout,
            'headers' => [
                'User-Agent' => 'ThinkRank/' . THINKRANK_VERSION,
            ],
            'method' => $method
        ];

        // Add OAuth Authorization header if token exists; otherwise fall back to
        // the API key sent in the x-goog-api-key HEADER (never the query string,
        // which is logged by servers, proxies and referrers).
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        } elseif (!empty($this->api_key)) {
            $args['headers']['x-goog-api-key'] = $this->api_key;
        }

        // Defensive: never let a key travel in the query string.
        unset($params['key']);

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        } elseif ($method === 'POST') {
            $args['body'] = wp_json_encode($params);
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Not esc_html()'d: an exception message is data, not output. It is
            // JSON-encoded to the REST layer and rendered as text by React, so
            // escaping here only smuggled entities into what the user reads —
            // Google's own wording is full of quotes, and the Performance panels
            // displayed them as "quota metric &#039;Queries&#039;".
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is JSON data for the REST layer, escaped at render time by React.
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code >= 400) {
            $error_data = json_decode($response_body, true);
            $error_data = is_array($error_data) ? $error_data : [];
            $error_message = $error_data['error']['message'] ?? 'Unknown API error';

            // A scope failure reads as "Request had insufficient authentication
            // scopes." — Google-internal wording that told the user nothing and
            // reached the MCP client verbatim (#674). Say what to do instead.
            $actionable = self::actionable_auth_message((int) $status_code, $error_data);

            if (null !== $actionable) {
                // Google's own sentence is kept after the instruction: support
                // needs the upstream wording to tell a scope failure from a
                // revoked grant, and the user needs the instruction first.
                // Built on its own line so the phpcs:ignore below sits on the
                // `throw` itself. The annotation only suppresses the next line,
                // and on a multi-line throw the reported violation is the
                // argument line, not the `throw` — so the ignore missed it and
                // Plugin Check failed on a sniff the repo standard does not run.
                $message = sprintf('%s (Google said: %s)', $actionable, $error_message);

                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Same as above: Google's wording is data, not markup; escaping it leaks entities into the UI.
                throw new \Exception($message, (int) $status_code);
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Same as above: Google's wording is data, not markup; escaping it leaks entities into the UI.
            throw new \Exception(sprintf('Google API error (%d): %s', (int) $status_code, $error_message), (int) $status_code);
        }

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from Google API');
        }

        // A valid-but-scalar body (null/number/string from a proxy/WAF/CDN on a
        // 2xx) would violate this method's : array return type; reject it here so
        // it surfaces as a catchable \Exception, not an uncatchable TypeError.
        if (!is_array($data)) {
            throw new \Exception('Unexpected non-array response from Google API');
        }

        // Update rate limit tracking after successful request
        $this->update_rate_limit();

        return $data;
    }

    /**
     * Test API connection
     * Must be implemented by concrete classes
     *
     * @return array Connection test results
     */
    abstract public function test_connection(): array;

    /**
     * Get rate limit configuration
     * Must be implemented by concrete classes
     *
     * @return array Rate limit configuration
     */
    abstract protected function get_rate_limits(): array;

    /**
     * Check if request is within rate limits
     * Following ThinkRank rate limiting patterns from existing classes
     *
     * @throws \Exception If rate limit exceeded
     */
    protected function check_rate_limit(): void {
        $rate_limit_key = $this->get_rate_limit_key();
        $current_time = time();

        // Reset counter if it's a new day
        if ($current_time >= $this->rate_limits['reset_time']) {
            $this->reset_rate_limit_counter();
        }

        $current_count = get_transient($rate_limit_key . '_count') ?: 0;

        if ($current_count >= $this->rate_limits['max_requests_per_day']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plugin-authored message, rendered as text by the admin app.
            throw new \Exception($this->get_rate_limit_error_message());
        }
    }

    /**
     * Update rate limit counter after successful request
     * Following ThinkRank transient patterns
     */
    protected function update_rate_limit(): void {
        $rate_limit_key = $this->get_rate_limit_key();
        $current_count = get_transient($rate_limit_key . '_count') ?: 0;
        $new_count = $current_count + 1;

        // Set transient to expire at end of day
        $seconds_until_tomorrow = strtotime('tomorrow') - time();
        set_transient($rate_limit_key . '_count', $new_count, $seconds_until_tomorrow);
    }

    /**
     * Reset rate limit counter for new day
     */
    private function reset_rate_limit_counter(): void {
        $rate_limit_key = $this->get_rate_limit_key();
        delete_transient($rate_limit_key . '_count');
        delete_transient($rate_limit_key . '_reset');

        // Set new reset time for tomorrow
        $seconds_until_tomorrow = strtotime('tomorrow') - time();
        set_transient($rate_limit_key . '_reset', strtotime('tomorrow'), $seconds_until_tomorrow);
        $this->rate_limits['reset_time'] = strtotime('tomorrow');
    }

    /**
     * Get rate limit transient key
     * Following ThinkRank option naming patterns
     *
     * @return string Rate limit key
     */
    abstract protected function get_rate_limit_key(): string;

    /**
     * Turn an authorization failure into an instruction, or null to pass through.
     *
     * Google answers a missing scope with "Request had insufficient
     * authentication scopes." — accurate, and useless to the person reading it.
     * It reached the MCP client and the Performance panels verbatim, with
     * nothing to say that reconnecting the Google account is the fix (#674).
     *
     * The distinction that matters is refresh versus re-consent. A 401 is a
     * stale access token and Analytics_Manager already refreshes and retries it
     * silently. A scope 403 is not retryable: refreshing returns a token with
     * the same scopes, so only granting consent again can change the outcome —
     * which is why this says "reconnect", not "try again".
     *
     * Returns null for every other failure, so quota, rate-limit and genuine
     * permission errors keep Google's wording, which is informative for them.
     *
     * @since 2.7.0
     *
     * @param int   $status_code HTTP status.
     * @param array $error_data  Decoded error body.
     * @return string|null Instruction to lead with, or null to pass through.
     */
    protected static function actionable_auth_message(int $status_code, array $error_data): ?string {
        $error   = is_array($error_data['error'] ?? null) ? $error_data['error'] : [];
        $message = (string) ($error['message'] ?? '');

        // google.rpc.ErrorInfo, which is what the newer APIs return.
        $reasons = [];
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && isset($detail['reason'])) {
                $reasons[] = (string) $detail['reason'];
            }
        }

        // The older errors[] shape, still used by Search Console.
        foreach ((array) ($error['errors'] ?? []) as $legacy) {
            if (is_array($legacy) && isset($legacy['reason'])) {
                $reasons[] = (string) $legacy['reason'];
            }
        }

        $scope_failure = 403 === $status_code
            && (
                in_array('ACCESS_TOKEN_SCOPE_INSUFFICIENT', $reasons, true)
                || in_array('insufficientPermissions', $reasons, true)
                || 1 === preg_match('/insufficient (authentication scopes|permission)/i', $message)
            );

        if ($scope_failure) {
            return __(
                'The connected Google account is missing a permission this feature needs. Reconnect it under Essential SEO > Integrations and approve every permission Google asks for. Refreshing or retrying will not help, because the existing grant cannot gain a permission it was never given.',
                'thinkrank'
            );
        }

        // A revoked or withdrawn grant. Analytics_Manager treats invalid_grant
        // as terminal already; this is the same condition seen from the API
        // side, where the refresh-and-retry loop has nothing left to try.
        $revoked = in_array($status_code, [401, 403], true)
            && (
                in_array('ACCESS_TOKEN_EXPIRED', $reasons, true)
                || 1 === preg_match('/invalid[_ ]grant|token has been expired or revoked/i', $message)
            );

        if ($revoked) {
            return __(
                'The Google connection is no longer valid: access was revoked, or the grant expired. Reconnect the account under Essential SEO > Integrations.',
                'thinkrank'
            );
        }

        return null;
    }

    /**
     * Get rate limit error message
     * Must be implemented by concrete classes
     *
     * @return string Error message
     */
    abstract protected function get_rate_limit_error_message(): string;
}
