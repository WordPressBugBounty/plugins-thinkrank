<?php
/**
 * Static OAuth discovery files — the /.well-known/ escape hatch.
 *
 * Some hosts (SiteGround shared hosting confirmed, #374) resolve every
 * request under the site root's /.well-known/ directory at their Nginx edge
 * as a physical path. No rewrite, no Apache, no WordPress — a missing file is
 * a server-level 404, and no amount of permalink flushing can fix it because
 * the request never reaches PHP.
 *
 * The primary mitigation is that the 401 challenge now advertises a
 * REST-served metadata URL (Mcp_OAuth::resource_metadata_url), which such
 * hosts do pass through. But a client that ignores the challenge pointer and
 * derives the RFC 9728 / RFC 8414 path-insert URLs itself still fetches
 * /.well-known/oauth-*\/thinkrank/mcp — so this class turns the host's own
 * behaviour into the fix: if Nginx insists on serving physical files from
 * /.well-known/, we write the discovery documents AS physical files.
 *
 * Publishing is best-effort and deliberately conservative:
 *   - only for a site installed at the domain root (on a subdirectory
 *     install the domain's /.well-known/ belongs to a different tree);
 *   - only invoked from the self-test, and only after it has measured that
 *     the dynamic route is dead (on healthy hosts the rewrites keep serving
 *     and no files are written, so metadata can never go stale there);
 *   - content is refreshed on every publish, so each self-test run keeps the
 *     files current with home_url()/settings changes.
 *
 * Known limitation: the files are extensionless (the URL path has no .json),
 * so an edge server may send them without an application/json Content-Type.
 * Every client observed so far parses the body regardless, and a 200 with a
 * loose Content-Type strictly beats the 404 it replaces.
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Writes/removes physical /.well-known/ OAuth discovery documents.
 */
final class Mcp_Static_Discovery {

	/**
	 * The path-insert discovery files, relative to the site root.
	 *
	 * Only the path-suffixed forms: the bare root forms would need
	 * `oauth-protected-resource` to be a file AND a directory at once, and
	 * spec-compliant clients derive the suffixed form from our path-based
	 * issuer anyway.
	 *
	 * @return array<string,array<string,mixed>> relative path => document.
	 */
	private static function files(): array {
		$suffix = Mcp_Pairing::SITE_ENDPOINT_PATH; // thinkrank/mcp.
		return [
			'.well-known/oauth-protected-resource/' . $suffix => Mcp_OAuth::protected_resource_metadata(),
			'.well-known/oauth-authorization-server/' . $suffix => Mcp_OAuth::authorization_server_metadata(),
		];
	}

	/**
	 * Whether static publishing is even applicable here.
	 *
	 * @return bool
	 */
	public static function applicable(): bool {
		// Subdirectory install: the domain root (where /.well-known/ lives)
		// is not ours to write into.
		return '/' === ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '/' );
	}

	/**
	 * Write the discovery documents as physical files. Returns true only when
	 * every file exists with current content afterwards.
	 *
	 * @param string|null $base Base directory (defaults to ABSPATH); a
	 *                          parameter so tests can point it at a sandbox.
	 * @return bool
	 */
	public static function publish( ?string $base = null ): bool {
		if ( null === $base && ! self::applicable() ) {
			return false;
		}
		$base = trailingslashit( $base ?? ABSPATH );

		$all_current = true;
		foreach ( self::files() as $relative => $document ) {
			$path = $base . $relative;
			$json = (string) wp_json_encode( $document );

			// Already current — don't touch the filesystem.
			if ( is_file( $path ) && (string) file_get_contents( $path ) === $json ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file freshness check.
				continue;
			}

			if ( ! wp_mkdir_p( dirname( $path ) ) ) {
				$all_current = false;
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small static file at a fixed path; WP_Filesystem adds credential prompts this non-interactive path cannot answer.
			if ( false === file_put_contents( $path, $json ) ) {
				$all_current = false;
			}
		}

		return $all_current;
	}

	/**
	 * Remove the published files (and their directories when empty). Called
	 * on plugin deactivation so a static copy cannot keep advertising an
	 * OAuth server that is no longer running.
	 *
	 * @param string|null $base Base directory (defaults to ABSPATH).
	 * @return void
	 */
	public static function remove( ?string $base = null ): void {
		$base = trailingslashit( $base ?? ABSPATH );

		foreach ( array_keys( self::files() ) as $relative ) {
			$path = $base . $relative;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			// Prune now-empty directories up to .well-known itself, but never
			// .well-known — other software (ACME, Apple Pay) shares it.
			$dir  = dirname( $path );
			$stop = untrailingslashit( $base . '.well-known' );
			while ( $dir !== $stop && is_dir( $dir ) && self::dir_is_empty( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing only directories this class created, verified empty.
				rmdir( $dir );
				$dir = dirname( $dir );
			}
		}
	}

	/**
	 * Whether a directory contains nothing.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private static function dir_is_empty( string $dir ): bool {
		$entries = scandir( $dir );
		return is_array( $entries ) && count( $entries ) <= 2; // Only . and ..
	}
}
