<?php

/**
 * Object Redirect
 *
 * @package ThinkRank\SEO
 * @since 2.7.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Object Redirect
 *
 * The free-side contract behind the "Redirect this to" field on the post and
 * term SEO surfaces: one destination URL and one status code per object.
 *
 * Free owns the field; it does not own the data. Redirects are a Pro feature,
 * and a redirect stored here as post meta would be a second source of truth
 * next to the Pro rules table — the edit screen would keep showing a redirect
 * that a manager deletion had already stopped serving, and the 404 monitor
 * would never see it. So this class only brokers: it reads through
 * `thinkrank_object_redirect` and writes through
 * `thinkrank_object_redirect_save`, and with Pro inactive nothing answers
 * either, which is what makes the field an honest upsell rather than an input
 * that quietly discards what is typed into it.
 *
 * @since 2.7.0
 */
class Object_Redirect {

    /**
     * Status codes the field offers.
     *
     * 301 is the default because the field's purpose — a retired page pointing
     * at its replacement — is a permanent move.
     */
    public const TYPES = [301, 302, 307];

    /**
     * Default status code.
     */
    public const DEFAULT_TYPE = 301;

    /**
     * Whether a redirect provider is active.
     *
     * @return bool
     */
    public static function is_supported(): bool {
        /**
         * Filter whether per-object redirects can be stored.
         *
         * Answered by ThinkRank Pro when the Redirections feature is running.
         *
         * @since 2.7.0
         *
         * @param bool $supported Whether a provider is available.
         */
        return (bool) apply_filters('thinkrank_object_redirect_supported', false);
    }

    /**
     * The redirect currently set on an object.
     *
     * @since 2.7.0
     *
     * @param string $object_type 'post' or 'term'.
     * @param int    $object_id   Object ID.
     * @return array{url:string,type:int} Empty url when there is no redirect.
     */
    public static function get(string $object_type, int $object_id): array {
        $empty = ['url' => '', 'type' => self::DEFAULT_TYPE];

        if (!self::is_supported()) {
            return $empty;
        }

        /**
         * Filter the stored redirect for an object.
         *
         * @since 2.7.0
         *
         * @param array|null $redirect    {url, type}, or null when none is set.
         * @param string     $object_type 'post' or 'term'.
         * @param int        $object_id   Object ID.
         */
        $redirect = apply_filters('thinkrank_object_redirect', null, $object_type, $object_id);

        if (!is_array($redirect) || empty($redirect['url'])) {
            return $empty;
        }

        return [
            'url'  => (string) $redirect['url'],
            'type' => self::normalize_type($redirect['type'] ?? self::DEFAULT_TYPE),
        ];
    }

    /**
     * Set or clear an object's redirect.
     *
     * @since 2.7.0
     *
     * @param string $object_type 'post' or 'term'.
     * @param int    $object_id   Object ID.
     * @param string $url         Destination; '' clears the redirect.
     * @param mixed  $type        Status code.
     * @return true|\WP_Error
     */
    public static function save(string $object_type, int $object_id, string $url, $type = self::DEFAULT_TYPE) {
        if (!self::is_supported()) {
            return new \WP_Error(
                'thinkrank_redirect_unsupported',
                __('Redirects require ThinkRank Pro with the Redirections feature active.', 'thinkrank')
            );
        }

        $url = trim($url);

        // Validate what was typed, then sanitize — not the other way round.
        // esc_url_raw() answers a scheme it will not allow with an empty
        // string, and an empty destination already means "remove the
        // redirect", so validating the sanitized value turned `javascript:`
        // into a silent clear of whatever redirect the object already had.
        if ('' !== $url) {
            if (!self::is_valid_url($url)) {
                return self::invalid_url_error();
            }

            $url = self::sanitize_url($url);

            if ('' === $url) {
                return self::invalid_url_error();
            }
        }

        /**
         * Store an object's redirect.
         *
         * Implementations return true, or a WP_Error explaining why the
         * redirect could not be stored. Returning null (nothing listening)
         * is treated as a failure by the caller below.
         *
         * @since 2.7.0
         *
         * @param mixed  $result      Result so far.
         * @param string $object_type 'post' or 'term'.
         * @param int    $object_id   Object ID.
         * @param string $url         Destination; '' clears the redirect.
         * @param int    $type        Status code.
         */
        $result = apply_filters(
            'thinkrank_object_redirect_save',
            null,
            $object_type,
            $object_id,
            $url,
            self::normalize_type($type)
        );

        if (is_wp_error($result)) {
            return $result;
        }

        if (true !== $result) {
            return new \WP_Error(
                'thinkrank_redirect_not_stored',
                __('The redirect could not be stored.', 'thinkrank')
            );
        }

        return true;
    }

    /**
     * Clamp a submitted status code to one the field offers.
     *
     * @param mixed $type Submitted code.
     * @return int
     */
    public static function normalize_type($type): int {
        $type = (int) $type;

        return in_array($type, self::TYPES, true) ? $type : self::DEFAULT_TYPE;
    }

    /**
     * The one message for every unusable destination.
     *
     * @return \WP_Error
     */
    private static function invalid_url_error(): \WP_Error {
        return new \WP_Error(
            'thinkrank_redirect_invalid_url',
            __('Enter a full URL starting with http:// or https://, or a path starting with /.', 'thinkrank')
        );
    }

    /**
     * Sanitize an already-validated destination.
     *
     * @param string $url Validated value.
     * @return string
     */
    private static function sanitize_url(string $url): string {
        return esc_url_raw($url, ['http', 'https']);
    }

    /**
     * Whether a destination is one we are willing to redirect to.
     *
     * @param string $url Trimmed value as entered.
     * @return bool
     */
    private static function is_valid_url(string $url): bool {
        // A site-relative path is the common case for an internal move.
        // "//evil.com" is a protocol-relative absolute URL wearing a path's
        // clothes, so a leading slash alone is not enough.
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && '' !== (string) wp_parse_url($url, PHP_URL_HOST);
    }
}
