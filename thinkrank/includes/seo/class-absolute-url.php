<?php
/**
 * Absolute URLs for SEO overrides.
 *
 * @package ThinkRank\SEO
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Turns a stored SEO URL (canonical, social image) into an absolute one.
 *
 * esc_url_raw() keeps root-relative and protocol-relative values, so
 * `/relative/path/` was stored as typed and printed as
 * `<link rel="canonical" href="/relative/path/" />`. A canonical has to be
 * absolute, and so does an og:image. The term and post edit forms block it
 * with `type="url"`, but the abilities and the importers do not (#1220).
 *
 * @since 2.17.0
 */
final class Absolute_Url {

	/**
	 * The absolute form of a URL, or an empty string when it has none.
	 *
	 * - Absolute URLs are returned as esc_url_raw() leaves them.
	 * - Protocol-relative URLs (`//cdn.example.com/a.jpg`) take the site's
	 *   scheme.
	 * - Root-relative URLs (`/relative/path/`) are resolved against the
	 *   site's scheme and host, as a browser resolves them.
	 * - Anything else without a host (`#fragment`, `?query`, `mailto:`) is
	 *   not a page or image address and is dropped.
	 * - The result is kept only when it is an http or https URL with a host.
	 *   wp_parse_url() reads `tel:123` as host `tel`, and `//` or
	 *   `///evil.com/x` resolve to `https://` and `https:///evil.com/x`, none
	 *   of which is a page or image address (#1254).
	 *
	 * @since 2.17.0
	 *
	 * @param string $url Raw or stored URL.
	 * @return string Absolute URL, or ''.
	 */
	public static function from( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return '';
		}

		$home   = wp_parse_url( home_url( '/' ) );
		$scheme = is_array( $home ) && ! empty( $home['scheme'] ) ? (string) $home['scheme'] : 'https';

		if ( 0 === strpos( $url, '//' ) ) {
			return self::web_url( esc_url_raw( $scheme . ':' . $url ) );
		}

		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			return self::web_url( $url );
		}

		if ( 0 !== strpos( $url, '/' ) || ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}

		$origin = $scheme . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );

		return self::web_url( esc_url_raw( $origin . $url ) );
	}

	/**
	 * The URL when it is an http or https address with a host, otherwise ''.
	 *
	 * Checked on the resolved value, so a protocol-relative or root-relative
	 * input is judged by what it became.
	 *
	 * @since 2.17.0
	 *
	 * @param string $url Resolved URL.
	 * @return string The URL, or ''.
	 */
	private static function web_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( (string) $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return '';
		}

		return $url;
	}
}
