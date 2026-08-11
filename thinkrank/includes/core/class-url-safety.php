<?php
/**
 * Shared SSRF guard for server-side fetches of user-supplied URLs.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 1.29.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

use WP_Error;
use WP_Http;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL safety checks for outbound requests.
 *
 * Every feature that fetches a URL the user supplied (schema import, competitor
 * analysis, …) must go through here rather than carrying its own copy of the
 * block list — divergent copies are how a range like CGNAT ends up blocked in
 * one place and reachable in another.
 *
 * @since 1.29.0
 */
class Url_Safety {

    /**
     * Maximum redirect hops followed by {@see self::safe_remote_get()}.
     */
    private const MAX_REDIRECTS = 5;

    /**
     * Reserved IPv4 blocks PHP's FILTER_FLAG_NO_RES_RANGE does not cover.
     *
     * @var string[]
     */
    private const EXTRA_RESERVED_BLOCKS = [
        '100.64.0.0/10',   // Shared address space / CGNAT (RFC 6598).
        '192.0.0.0/24',    // IETF protocol assignments (RFC 6890).
        '198.18.0.0/15',   // Benchmarking (RFC 2544).
        '192.88.99.0/24',  // 6to4 relay anycast (RFC 7526).
    ];

    /**
     * Whether an IP address is a routable public address.
     *
     * Combines PHP's private/reserved-range filters with explicit blocks for the
     * reserved IPv4 ranges FILTER_FLAG_NO_RES_RANGE misses — most importantly
     * 100.64.0.0/10 (CGNAT), which some clouds route metadata over.
     *
     * @since 1.29.0
     *
     * @param string $ip IP address (v4 or v6).
     * @return bool True when the address is public.
     */
    public static function is_public_ip(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (self::EXTRA_RESERVED_BLOCKS as $cidr) {
            if (self::ipv4_in_cidr($ip, $cidr)) {
                return false;
            }
        }

        // An IPv6 address that embeds an IPv4 target (IPv4-mapped ::ffff:a.b.c.d,
        // deprecated IPv4-compatible ::a.b.c.d, or NAT64 64:ff9b::a.b.c.d) routes
        // to that IPv4 address, but PHP's range filters above judge only the IPv6
        // form and let it through. Re-run the full check on the embedded IPv4 so
        // e.g. ::ffff:169.254.169.254 is blocked exactly like 169.254.169.254.
        $embedded = self::embedded_ipv4($ip);
        if (null !== $embedded && !self::is_public_ip($embedded)) {
            return false;
        }

        return true;
    }

    /**
     * Extract the embedded IPv4 target from an IPv4-in-IPv6 address.
     *
     * Covers the three forms that actually carry a routable IPv4 destination:
     * IPv4-mapped (::ffff:0:0/96), deprecated IPv4-compatible (::/96, excluding
     * the :: and ::1 specials), and NAT64 (64:ff9b::/96).
     *
     * @param string $ip IP address (v4 or v6).
     * @return string|null Dotted-quad IPv4 when one is embedded, otherwise null.
     */
    private static function embedded_ipv4(string $ip): ?string {
        if (strpos($ip, ':') === false) {
            return null; // Plain IPv4.
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on a malformed literal, which is a normal reject-path here, not an error.
        $packed = @inet_pton($ip);
        if (false === $packed || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);
        $mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";       // ::ffff:0:0/96
        $nat64  = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";       // 64:ff9b::/96
        $compat = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";       // ::/96 (IPv4-compatible)

        // A 4-byte packed string renders as a dotted-quad IPv4 via inet_ntop.
        if ($prefix === $mapped || $prefix === $nat64) {
            return (string) inet_ntop(substr($packed, 12));
        }

        if ($prefix === $compat) {
            $v4 = substr($packed, 12);
            // Skip :: (unspecified) and ::1 (loopback); the IPv6 range filter
            // already rejects those, and they carry no meaningful IPv4 target.
            if ($v4 === "\x00\x00\x00\x00" || $v4 === "\x00\x00\x00\x01") {
                return null;
            }
            return (string) inet_ntop($v4);
        }

        return null;
    }

    /**
     * Validate that a URL is an http(s) URL whose host resolves only to public
     * addresses.
     *
     * @since 1.29.0
     *
     * @param string $url URL to validate.
     * @return true|WP_Error True when safe to fetch, WP_Error otherwise.
     */
    public static function validate_public_url(string $url) {
        $parts = wp_parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return new WP_Error('invalid_url', 'Only http and https URLs can be fetched.', ['status' => 400]);
        }

        // Strip IPv6 literal brackets, if present.
        $host = trim($parts['host'], '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve_host_ips($host);
        if (empty($ips)) {
            return new WP_Error('invalid_url', 'The URL host could not be resolved.', ['status' => 400]);
        }

        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
            }
        }

        return true;
    }

    /**
     * Whether a URL is safe to fetch.
     *
     * Boolean convenience wrapper around {@see self::validate_public_url()} for
     * call sites that only branch on safe/unsafe.
     *
     * @since 1.29.0
     *
     * @param string $url URL to check.
     * @return bool True when safe to fetch.
     */
    public static function is_safe_public_url(string $url): bool {
        return true === self::validate_public_url($url);
    }

    /**
     * Fetch a URL, re-validating the host against the block list on every
     * redirect hop.
     *
     * wp_safe_remote_get() re-validates redirect targets with
     * wp_http_validate_url(), which shares the link-local/CGNAT blind spot, so
     * redirects are followed manually (`redirection => 0`) and each hop is
     * checked before it is requested. That also closes the DNS-rebinding window
     * a single pre-flight check would leave open across hops.
     *
     * @since 1.29.0
     *
     * @param string $url  URL to fetch.
     * @param array  $args Optional. wp_safe_remote_get() arguments.
     * @return array|WP_Error Response array on success, WP_Error otherwise.
     */
    public static function safe_remote_get(string $url, array $args = []) {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!wp_http_validate_url($url)) {
                return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
            }

            $host_check = self::validate_public_url($url);
            if (is_wp_error($host_check)) {
                return $host_check;
            }

            $response = wp_safe_remote_get($url, array_merge($args, ['redirection' => 0]));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 300 || $code >= 400) {
                return $response;
            }

            $location = trim((string) wp_remote_retrieve_header($response, 'location'));
            if ('' === $location) {
                return $response; // Redirect without a target — treat as final.
            }

            // Resolve a relative Location against the current URL.
            $url = (string) WP_Http::make_absolute_url($location, $url);
            if ('' === $url) {
                return new WP_Error('invalid_url', 'The URL is not allowed.', ['status' => 400]);
            }
        }

        return new WP_Error('too_many_redirects', 'The URL redirected too many times.', ['status' => 400]);
    }

    /**
     * Whether an IPv4 address falls within a CIDR block.
     *
     * @param string $ip   IPv4 address.
     * @param string $cidr CIDR block (e.g. 100.64.0.0/10).
     * @return bool
     */
    private static function ipv4_in_cidr(string $ip, string $cidr): bool {
        if (strpos($ip, ':') !== false) {
            return false; // IPv6 is not covered by these IPv4 blocks.
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if (false === $ip_long || false === $subnet_long) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    /**
     * Resolve a hostname to its IPv4 + IPv6 addresses.
     *
     * @param string $host Hostname.
     * @return string[] Resolved IP addresses (may be empty).
     */
    private static function resolve_host_ips(string $host): array {
        $ips = [];

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() emits a warning on NXDOMAIN, which is a normal answer here, not an error.
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];       // A record.
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];     // AAAA record.
                }
            }
        }

        // Fallback where dns_get_record is unavailable or returns nothing.
        if (empty($ips)) {
            $resolved = gethostbyname($host);
            if ($resolved && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }
}
