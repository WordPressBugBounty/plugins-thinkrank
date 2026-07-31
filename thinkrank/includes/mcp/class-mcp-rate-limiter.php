<?php
/**
 * MCP rate limiter — a per-IP lockout on FAILED token authentication.
 *
 * The MCP connection token is a 256-bit secret, so online brute-forcing is
 * already infeasible. This limiter stops the cheaper abuse: a flood of
 * bad-token requests burning CPU + filling logs, and gives a rotated token's
 * stale clients a hard wall. Defence-in-depth, not the primary control.
 *
 * Model: count consecutive FAILED attempts per client IP in a rolling window
 * (transient-backed). At/after the threshold the IP is locked out for the
 * window; a SUCCESSFUL auth clears the counter immediately.
 *
 * Threshold + window are overridable via the THINKRANK_MCP_MAX_FAILS /
 * THINKRANK_MCP_LOCKOUT_SECONDS constants and the `thinkrank_mcp_rate_limit`
 * filter ( [ max_fails, lockout_seconds ] ).
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Per-IP failed-auth lockout for the MCP endpoint.
 */
final class Mcp_Rate_Limiter {

	/**
	 * Transient key prefix; the client-IP hash is appended.
	 */
	private const PREFIX = 'thinkrank_mcp_rl_';

	/**
	 * Default: lock out after this many failed attempts.
	 */
	private const DEFAULT_MAX_FAILS = 10;

	/**
	 * Default: lockout / rolling-window length, in seconds.
	 */
	private const DEFAULT_LOCKOUT = 900; // 15 minutes.

	/**
	 * Is the current client currently locked out? Call BEFORE comparing the
	 * token so a locked IP never even reaches the (constant-time) compare.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		list( $max ) = self::limits();
		return self::attempts() >= $max;
	}

	/**
	 * Record a failed auth attempt for the current client and return whether
	 * the client is now locked out. Extends the rolling window on each fail.
	 *
	 * @return bool True if this failure crossed into a lockout.
	 */
	public static function record_failure(): bool {
		list( $max, $window ) = self::limits();
		$count                = self::attempts() + 1;
		set_transient( self::key(), $count, $window );
		return $count >= $max;
	}

	/**
	 * Clear the counter for the current client — call on a SUCCESSFUL auth.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_transient( self::key() );
	}

	/**
	 * Seconds a locked client must wait (approximate; the window length).
	 *
	 * @return int
	 */
	public static function retry_after(): int {
		return self::limits()[1];
	}

	// -- internals --

	/**
	 * Current failed-attempt count for this client (0 when none).
	 *
	 * @return int
	 */
	private static function attempts(): int {
		$v = get_transient( self::key() );
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/**
	 * Transient key bound to the (hashed) client IP.
	 *
	 * @return string
	 */
	private static function key(): string {
		return self::PREFIX . md5( self::client_ip() );
	}

	/**
	 * Resolve [ max_fails, lockout_seconds ] from constants, then filter.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function limits(): array {
		$max    = defined( 'THINKRANK_MCP_MAX_FAILS' ) ? (int) \THINKRANK_MCP_MAX_FAILS : self::DEFAULT_MAX_FAILS;
		$window = defined( 'THINKRANK_MCP_LOCKOUT_SECONDS' ) ? (int) \THINKRANK_MCP_LOCKOUT_SECONDS : self::DEFAULT_LOCKOUT;

		/**
		 * Filter the MCP failed-auth rate limit.
		 *
		 * @param array{0:int,1:int} $limits [ max_fails, lockout_seconds ].
		 */
		$limits = (array) apply_filters( 'thinkrank_mcp_rate_limit', [ $max, $window ] );
		$max    = isset( $limits[0] ) ? max( 1, (int) $limits[0] ) : self::DEFAULT_MAX_FAILS;
		$window = isset( $limits[1] ) ? max( 1, (int) $limits[1] ) : self::DEFAULT_LOCKOUT;
		return [ $max, $window ];
	}

	/**
	 * Best-effort client IP. REMOTE_ADDR only — we deliberately do NOT trust
	 * X-Forwarded-For (spoofable → an attacker could dodge the limit or lock
	 * out a victim). Behind a known proxy the site should set REMOTE_ADDR
	 * upstream.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used only as a rate-limit bucket key (md5'd), never output or stored raw.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return '' !== $ip ? $ip : 'unknown';
	}
}
