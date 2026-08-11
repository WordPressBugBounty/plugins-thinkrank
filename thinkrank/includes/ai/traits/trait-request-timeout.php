<?php
/**
 * Request-timeout classification for AI HTTP clients.
 *
 * @package ThinkRank\AI\Traits
 */

declare(strict_types=1);

namespace ThinkRank\AI\Traits;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared timeout detection for the clients that own a request_with_retry() loop.
 *
 * OpenAI, Claude and Gemini each carried an identical copy of is_timeout_error();
 * a change to how a timeout is recognised had to be made in three places or the
 * retry behaviour would diverge between providers (issue #288). This trait is the
 * single copy they all use.
 */
trait Request_Timeout {

    /**
     * Whether a WP_Error from the WordPress HTTP API is a client-side timeout.
     *
     * A cURL timeout (error 28) surfaces as the 'http_request_failed' code with
     * a message containing "Operation timed out" / "timed out". WP_Error codes
     * are not a stable contract across HTTP transports, so match the message
     * too, and treat ONLY a recognised timeout as non-retryable — any other
     * network failure (DNS, connection refused, TLS) keeps the existing retry
     * behaviour so this cannot make an unrelated transient failure worse.
     *
     * @param \WP_Error $error The error returned by wp_remote_request().
     * @return bool True if the error represents a request timeout.
     */
    private function is_timeout_error(\WP_Error $error): bool {
        foreach ((array) $error->get_error_messages() as $message) {
            if (stripos((string) $message, 'timed out') !== false
                || stripos((string) $message, 'timeout') !== false) {
                return true;
            }
        }
        return false;
    }
}
