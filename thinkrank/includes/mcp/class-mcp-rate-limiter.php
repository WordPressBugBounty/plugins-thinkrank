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
 * "Failed" means a credential was PRESENTED and rejected. A request with no
 * Authorization header never reaches the counter — that is the first step of
 * the OAuth handshake (client asks for the RFC 9728 challenge), so counting it
 * would lock out every OAuth client during normal discovery.
 *
 * Threshold + window are overridable via the THINKRANK_MCP_MAX_FAILS /
 * THINKRANK_MCP_LOCKOUT_SECONDS constants and the `thinkrank_mcp_rate_limit`
 * filter ( [ max_fails, lockout_seconds ] ).
 *
 * A second, separate bucket counts OAuth client registrations per IP
 * (#1341). Registration is open by necessity, and each one writes to the
 * option every bearer request loads, so one address gets a handful per
 * window. Its limits come from the `thinkrank_mcp_register_rate_limit`
 * filter ( [ max_registrations, window_seconds ] ). The two buckets never
 * share a counter: registering a client does not bring an IP closer to an
 * auth lockout, and a lockout does not stop a registration.
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
	 * Transient key prefix of the registration bucket. It must not start
	 * with PREFIX, or active_lockouts() would count registrations as
	 * lockouts.
	 *
	 * @since 2.18.0
	 */
	private const REGISTER_PREFIX = 'thinkrank_mcp_reg_';

	/**
	 * Default: client registrations one IP may make per window. A real
	 * client registers once per connection, and a developer reconnecting an
	 * inspector a few times stays well under it.
	 *
	 * @since 2.18.0
	 */
	private const DEFAULT_MAX_REGISTRATIONS = 20;

	/**
	 * Default: registration window, in seconds.
	 *
	 * @since 2.18.0
	 */
	private const DEFAULT_REGISTER_WINDOW = 3600; // 1 hour.

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
	 * the client is now locked out.
	 *
	 * The window is FIXED from the first failure — recording a failure never
	 * extends it. The previous behaviour reset the transient's expiry on every
	 * increment, so a stranded client that retried every few minutes (exactly
	 * what a connector configured with a rotated-away token does, and exactly
	 * what support kept telling a customer to do) re-armed its own lockout
	 * forever. A lockout must be escapable by simply waiting out one window.
	 *
	 * @return bool True if this failure crossed into a lockout.
	 */
	public static function record_failure(): bool {
		list( $max, $window ) = self::limits();
		return self::bump( self::key(), $window ) >= $max;
	}

	/**
	 * Has the current client used up its registrations for this window?
	 * Call before registering; it records nothing.
	 *
	 * @since 2.18.0
	 * @return bool
	 */
	public static function registration_limited(): bool {
		list( $max ) = self::register_limits();
		return self::count_of( self::register_key() ) >= $max;
	}

	/**
	 * Count one registration by the current client. Call only after a client
	 * was actually stored: a refused request writes nothing, so a client
	 * correcting its metadata is not charged for the attempts.
	 *
	 * @since 2.18.0
	 * @return void
	 */
	public static function record_registration(): void {
		list( , $window ) = self::register_limits();
		self::bump( self::register_key(), $window );
	}

	/**
	 * Seconds until the current client may register again.
	 *
	 * @since 2.18.0
	 * @return int
	 */
	public static function registration_retry_after(): int {
		$entry = get_transient( self::register_key() );
		if ( is_array( $entry ) && isset( $entry['until'] ) ) {
			return max( 1, (int) $entry['until'] - time() );
		}
		return self::register_limits()[1];
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
	 * Seconds until the current client's window ends. Falls back to the full
	 * window length when no entry exists — honest now that the window is fixed,
	 * where before this always reported the full length no matter how long the
	 * client had already waited.
	 *
	 * @return int
	 */
	public static function retry_after(): int {
		$entry = get_transient( self::key() );
		if ( is_array( $entry ) && isset( $entry['until'] ) ) {
			return max( 1, (int) $entry['until'] - time() );
		}
		return self::limits()[1];
	}

	/**
	 * How many clients are currently locked out, across all IPs.
	 *
	 * Diagnostic for the self-test: a healthy loopback plus a locked-out remote
	 * client is exactly the state a stranded connector (rotated-away token,
	 * still retrying) produces, and it was invisible — support couldn't tell
	 * "server broken" from "client walled itself off".
	 *
	 * Returns null when a persistent object cache is in use — transients don't
	 * live in the options table there, so the count is unknowable and claiming
	 * zero would be a lie.
	 *
	 * @return int|null Locked-out client count, or null when unknowable.
	 */
	public static function active_lockouts(): ?int {
		if ( wp_using_ext_object_cache() ) {
			return null;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return null;
		}
		list( $max ) = self::limits();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic scan over transient rows; no core API enumerates them.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::PREFIX ) . '%'
			)
		);

		$locked = 0;
		foreach ( (array) $rows as $row ) {
			$entry = maybe_unserialize( $row );
			$count = is_array( $entry ) && isset( $entry['count'] )
				? (int) $entry['count']
				: ( is_numeric( $entry ) ? (int) $entry : 0 );
			if ( $count >= $max ) {
				$locked++;
			}
		}

		return $locked;
	}

	// -- internals --

	/**
	 * Current failed-attempt count for this client (0 when none).
	 *
	 * @return int
	 */
	private static function attempts(): int {
		return self::count_of( self::key() );
	}

	/**
	 * Count stored under a bucket key (0 when none).
	 *
	 * @param string $key Transient key.
	 * @return int
	 */
	private static function count_of( string $key ): int {
		$v = get_transient( $key );
		if ( is_array( $v ) && isset( $v['count'] ) ) {
			return (int) $v['count'];
		}
		// Legacy integer entries from before the fixed-window format.
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/**
	 * Add one to a bucket and return its new count.
	 *
	 * The window is FIXED from the first hit: a later hit keeps the original
	 * end, with the transient's TTL set to the time remaining.
	 *
	 * @param string $key    Transient key.
	 * @param int    $window Window length, in seconds, for a new bucket.
	 * @return int
	 */
	private static function bump( string $key, int $window ): int {
		$entry = get_transient( $key );

		if ( is_array( $entry ) && isset( $entry['count'], $entry['until'] ) ) {
			$entry['count']++;
			// Preserve the ORIGINAL window end: TTL = remaining time only.
			$remaining = max( 1, (int) $entry['until'] - time() );
			set_transient( $key, $entry, $remaining );
			return (int) $entry['count'];
		}

		// First hit in a window (also migrates any legacy integer entry —
		// a stale int simply restarts as a fresh window of 1).
		$entry = [
			'count' => 1,
			'until' => time() + $window,
		];
		set_transient( $key, $entry, $window );
		return 1;
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
	 * Registration-bucket transient key bound to the (hashed) client IP.
	 *
	 * @return string
	 */
	private static function register_key(): string {
		return self::REGISTER_PREFIX . md5( self::client_ip() );
	}

	/**
	 * Resolve [ max_registrations, window_seconds ] through the filter.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function register_limits(): array {
		/**
		 * Filter the MCP client-registration rate limit.
		 *
		 * @since 2.18.0
		 * @param array{0:int,1:int} $limits [ max_registrations, window_seconds ].
		 */
		$limits = (array) apply_filters( 'thinkrank_mcp_register_rate_limit', [ self::DEFAULT_MAX_REGISTRATIONS, self::DEFAULT_REGISTER_WINDOW ] );
		$max    = isset( $limits[0] ) ? max( 1, (int) $limits[0] ) : self::DEFAULT_MAX_REGISTRATIONS;
		$window = isset( $limits[1] ) ? max( 1, (int) $limits[1] ) : self::DEFAULT_REGISTER_WINDOW;
		return [ $max, $window ];
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
	 * out a victim). Behind a reverse proxy or tunnel every client shares one
	 * REMOTE_ADDR and therefore one bucket; such a site should either set
	 * REMOTE_ADDR upstream or opt in via the filter below, which is safe only
	 * when the proxy is known to overwrite the forwarded header.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used only as a rate-limit bucket key (md5'd), never output or stored raw.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		/**
		 * Filter the IP used as the MCP rate-limit bucket key.
		 *
		 * Opt-in escape hatch for sites behind a trusted reverse proxy, where
		 * REMOTE_ADDR is the proxy and every client would otherwise collapse
		 * into a single bucket. Only return a forwarded header's value when
		 * the proxy is known to overwrite it.
		 *
		 * @param string $ip Resolved REMOTE_ADDR ('' when unavailable).
		 */
		$ip = (string) apply_filters( 'thinkrank_mcp_client_ip', $ip );

		return '' !== $ip ? $ip : 'unknown';
	}
}
