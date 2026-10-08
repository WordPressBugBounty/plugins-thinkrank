<?php
/**
 * IRI-aware URL syntax validation.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 2.14.2
 */

declare(strict_types=1);

namespace ThinkRank\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates URLs the way the web uses them, non-ASCII characters included.
 *
 * PHP's FILTER_VALIDATE_URL implements RFC 2396 and refuses any byte outside
 * ASCII. WordPress keeps non-Latin characters in uploaded filenames and does
 * not percent-encode attachment URLs, so on a site with a Bengali, Arabic or
 * Chinese media library the raw filter refused real images and logos (#924).
 * Those URLs are valid IRIs; browsers, search engines and schema.org accept
 * them.
 *
 * Every content-URL syntax check in ThinkRank (and ThinkRank Pro) goes through
 * here instead of calling filter_var() directly. The check maps the IRI to its
 * URI form first (IDN host to punycode, every other non-ASCII byte to its %XX
 * escape) and then runs the unchanged PHP filter, so anything that was invalid
 * before for an ASCII reason (a literal space, a control character, a missing
 * scheme) is still invalid.
 *
 * This is a syntax check only. It is not an SSRF guard; server-side fetches of
 * user-supplied URLs still belong to {@see Url_Safety}.
 *
 * @since 2.14.2
 */
final class Url_Validator {

    /**
     * Map an IRI to its ASCII URI form.
     *
     * An internationalised host becomes punycode when the intl extension is
     * available (left untouched otherwise, which then fails validation exactly
     * as it did before). Every byte above 0x7F elsewhere becomes its %XX
     * escape, which is the RFC 3987 mapping for UTF-8 input. ASCII bytes are
     * never touched, so an already-encoded URL comes back unchanged and is not
     * double-encoded.
     *
     * Also the form to write where a protocol demands an escaped URL, such as
     * the image sitemap's <image:loc>.
     *
     * @since 2.14.2
     *
     * @param string $url URL or IRI.
     * @return string ASCII URL.
     */
    public static function to_ascii(string $url): string {
        if (!preg_match('/[\x80-\xFF]/', $url)) {
            return $url;
        }

        // scheme://[userinfo@]host[:port] — convert only the host to punycode.
        if (preg_match('#^([a-z][a-z0-9+.\-]*://)([^/?\#]*)(.*)$#is', $url, $m)) {
            $authority = $m[2];
            $userinfo = '';
            $at = strrpos($authority, '@');
            if (false !== $at) {
                $userinfo = substr($authority, 0, $at + 1);
                $authority = substr($authority, $at + 1);
            }

            $port = '';
            if (preg_match('/^(.*?)(:\d*)$/s', $authority, $hp)) {
                $authority = $hp[1];
                $port = $hp[2];
            }

            $host = $authority;
            if (preg_match('/[\x80-\xFF]/', $host) && function_exists('idn_to_ascii')) {
                $flags = defined('IDNA_NONTRANSITIONAL_TO_ASCII') ? IDNA_NONTRANSITIONAL_TO_ASCII : 0;
                $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
                $ascii_host = idn_to_ascii($host, $flags, $variant);
                if (is_string($ascii_host) && '' !== $ascii_host) {
                    $host = $ascii_host;
                }
            }

            $url = $m[1] . $userinfo . $host . $port . $m[3];
        }

        return (string) preg_replace_callback(
            '/[\x80-\xFF]+/',
            static function (array $bytes): string {
                return rawurlencode($bytes[0]);
            },
            $url
        );
    }

    /**
     * Whether a value is a syntactically valid absolute URL, IRIs included.
     *
     * Drop-in replacement for `filter_var($url, FILTER_VALIDATE_URL)`: same
     * verdict for every ASCII input, and no scheme restriction. Use
     * {@see self::is_http_url()} where only web URLs are acceptable.
     *
     * @since 2.14.2
     *
     * @param mixed $url Candidate value.
     * @return bool
     */
    public static function is_valid($url): bool {
        if (!is_string($url) || '' === $url) {
            return false;
        }

        return false !== filter_var(self::to_ascii($url), FILTER_VALIDATE_URL);
    }

    /**
     * Whether a value is a valid absolute http or https URL, IRIs included.
     *
     * Rejects javascript:, data:, mailto: and every other scheme.
     *
     * @since 2.14.2
     *
     * @param mixed $url Candidate value.
     * @return bool
     */
    public static function is_http_url($url): bool {
        if (!self::is_valid($url)) {
            return false;
        }

        $scheme = wp_parse_url((string) $url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * Whether a value is an http(s) URL or a path on this site.
     *
     * For settings that are written into a src or href and may reasonably
     * hold a root-relative path ("/wp-content/uploads/icon.png"). A path must
     * start with a single "/": "//host" and "/\host" are protocol-relative to
     * a browser and would load from another site.
     *
     * @since 2.14.2
     *
     * @param mixed $url Candidate value.
     * @return bool
     */
    public static function is_http_url_or_path($url): bool {
        if (self::is_http_url($url)) {
            return true;
        }

        return is_string($url)
            && 1 === preg_match('#^/(?![/\\\\])#', $url)
            && 1 !== preg_match('/[\s\x00-\x1F\x7F]/', $url);
    }

    /**
     * A Search Console domain property in the form the API uses, or null.
     *
     * A domain property is "sc-domain:" followed by a bare hostname: no
     * scheme, port, path or userinfo. An internationalised name is accepted
     * and returned as punycode, lowercased, so "sc-domain:Bücher.example" and
     * "sc-domain:xn--bcher-kva.example" are one property. The name needs at
     * least two labels, each 1 to 63 letters, digits or hyphens that neither
     * starts nor ends with a hyphen.
     *
     * @since 2.14.2
     *
     * @param mixed $value Candidate value.
     * @return string|null "sc-domain:<ascii host>", or null when it is not one.
     */
    public static function search_console_domain_property($value): ?string {
        if (!is_string($value) || 0 !== stripos($value, 'sc-domain:')) {
            return null;
        }

        $host = substr($value, strlen('sc-domain:'));

        if (preg_match('/[\x80-\xFF]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $flags   = defined('IDNA_NONTRANSITIONAL_TO_ASCII') ? IDNA_NONTRANSITIONAL_TO_ASCII : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii   = idn_to_ascii($host, $flags, $variant);
            if (!is_string($ascii) || '' === $ascii) {
                return null;
            }
            $host = $ascii;
        }

        $host = strtolower($host);

        if (strlen($host) > 253) {
            return null;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (1 !== preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return null;
            }
        }

        return 'sc-domain:' . $host;
    }
}
