<?php
/**
 * The optional ThinkRank Hub connection: where the hub is, the ticket that
 * starts a connection, and the grants the hub holds.
 *
 * Connecting a site to the hub is an ordinary OAuth grant through this site's
 * own consent page: the hub registers as a client, an administrator approves
 * it, and the grant is listed and revocable like any other. What this class
 * adds is the one thing a plain grant cannot prove: that the person approving
 * it started from this site's ThinkRank Hub tab. A consent link for the hub's return
 * address is refused unless it carries a ticket minted here, by the account
 * that is approving, within the last fifteen minutes. Without that rule
 * anybody could start a connection in their own hub workspace and send the
 * owner a genuine consent page that would land the site in the wrong place.
 *
 * The ticket is stored as a digest and spent at the token exchange, so it
 * binds one approval to one code and cannot be replayed.
 *
 * Nothing here is required: the plugin works fully without the hub, and
 * connecting is never the price of a feature.
 *
 * Ported from BetterShield's `src/Mcp/Hub.php` (thinkrank#1002).
 *
 * @package ThinkRank\Mcp
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The hub address, the connect ticket and the hub's grants.
 */
final class Mcp_Hub {

	/**
	 * The owner's setting: which hub this site connects to, as `['url' => …]`.
	 */
	public const OPTION = 'thinkrank_hub';

	/**
	 * Outstanding connect tickets, by digest.
	 */
	public const TICKETS_OPTION = 'thinkrank_hub_tickets';

	/**
	 * Where a site connects when nobody has said otherwise.
	 */
	public const DEFAULT_URL = 'https://hub.thinkrank.ai';

	/**
	 * How long a ticket is good for, in seconds.
	 */
	public const TICKET_TTL = 900;

	/**
	 * How many tickets may be outstanding at once.
	 */
	private const MAX_TICKETS = 5;

	/**
	 * The access levels a connection may be started with.
	 */
	public const ACCESS_READ  = 'read';
	public const ACCESS_WRITE = 'write';

	/**
	 * Why this site refuses the hub's grant right now, in the words the
	 * state carries: a staging copy, or the MCP switch off.
	 */
	public const REFUSAL_STAGING    = 'staging';
	public const REFUSAL_CONNECTION = 'connection';

	/**
	 * The ThinkRank Hub tab, where the hub sends a person back to.
	 */
	private const HUB_SCREEN = 'admin.php?page=thinkrank-ai-tools&tab=hub';

	/**
	 * The MCP screen, where the connected apps and the activity log live.
	 */
	private const MCP_SCREEN = 'admin.php?page=thinkrank-ai-tools&tab=mcp';

	/**
	 * The setup wizard, which offers the connection as one of its steps and
	 * reopens on that step when the hub sends a person back.
	 */
	private const WIZARD_SCREEN = 'admin.php?page=thinkrank_setup_wizard';

	/**
	 * Where a connection may start, for the return address.
	 */
	public const FROM_WIZARD = 'wizard';

	// -- The address -------------------------------------------------------

	/**
	 * The hub's address, without a trailing slash.
	 *
	 * A constant in wp-config.php (or the filter) wins, so a host that manages
	 * many sites can pin it; then the owner's setting; then the default.
	 *
	 * A pinned address this cannot use is no address, '' here, and never the
	 * default: the host pinned it to keep this site away from the public hub,
	 * and quietly connecting there instead is the one outcome they ruled out.
	 *
	 * @return string
	 */
	public static function url(): string {
		if ( self::is_locked() ) {
			return (string) self::normalize_url( self::pinned() );
		}

		$stored = get_option( self::OPTION );
		$url    = is_array( $stored ) && isset( $stored['url'] ) ? self::normalize_url( (string) $stored['url'] ) : null;

		return null === $url ? self::DEFAULT_URL : $url;
	}

	/**
	 * Whether the address is fixed in configuration rather than on screen.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		return '' !== self::pinned();
	}

	/**
	 * Whether the address pinned in configuration is not one this can use.
	 *
	 * @return bool
	 */
	public static function pin_is_invalid(): bool {
		return self::is_locked() && null === self::normalize_url( self::pinned() );
	}

	/**
	 * The address configuration pins, as written there, or '' where it pins
	 * none.
	 *
	 * @return string
	 */
	private static function pinned(): string {
		/**
		 * Filters the hub address this site is pinned to.
		 *
		 * Defaults to `THINKRANK_HUB_URL` from wp-config.php. A pinned address
		 * cannot be changed on the MCP screen.
		 *
		 * @since 2.15.0
		 *
		 * @param string $pinned The address as configured, or '' for none.
		 */
		$pinned = apply_filters( 'thinkrank_hub_url_pinned', defined( 'THINKRANK_HUB_URL' ) ? (string) constant( 'THINKRANK_HUB_URL' ) : '' );

		return is_string( $pinned ) ? trim( $pinned ) : '';
	}

	/**
	 * An https address with a host and nothing a browser could be steered by.
	 *
	 * A path is allowed, because a hub may live under one; a query, a
	 * fragment and credentials are not. Nor is a host that is not a name or
	 * an address, a port outside the range, or a path that climbs with `..`.
	 *
	 * @param string $url Address as typed.
	 * @return string|null The address in its one spelling, or null when refused.
	 */
	public static function normalize_url( string $url ): ?string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return null;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( '' === $host || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$path = untrailingslashit( (string) ( $parts['path'] ?? '' ) );

		if ( ! self::host_is_valid( $host ) || ! self::path_is_valid( $path ) || ( isset( $parts['port'] ) && ( (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) ) ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return 'https://' . $host . $port . $path;
	}

	/**
	 * A name, an IPv4 address or a bracketed IPv6 one, as a browser reads it.
	 *
	 * @param string $host Lowercased host.
	 * @return bool
	 */
	private static function host_is_valid( string $host ): bool {
		if ( 1 === preg_match( '/^\[[0-9a-f:.]+\]$/i', $host ) ) {
			return true;
		}

		return 1 === preg_match( '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host );
	}

	/**
	 * No `.` or `..` segment, spelled out or encoded, and nothing unprintable.
	 *
	 * @param string $path Path without its trailing slash.
	 * @return bool
	 */
	private static function path_is_valid( string $path ): bool {
		$decoded = rawurldecode( $path );

		return 1 !== preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $decoded ) && 1 !== preg_match( '/[\s\x00-\x1f\x7f\\\\]/', $decoded );
	}

	/**
	 * What is wrong with an address normalize_url() refused, said about the
	 * part that is wrong.
	 *
	 * @param string $url Address as typed.
	 * @return string
	 */
	private static function refusal( string $url ): string {
		if ( 1 === preg_match( '#^https://[^/?\#@]+:(\d+)(?:[/?\#]|$)#i', $url, $port ) && ( (int) $port[1] < 1 || (int) $port[1] > 65535 ) ) {
			return sprintf(
				/* translators: %s: the port the address names. */
				__( 'The hub address names port %s, and a port runs from 1 to 65535.', 'thinkrank' ),
				$port[1]
			);
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return __( 'The hub address has to be an https address, such as https://hub.thinkrank.ai.', 'thinkrank' );
		}

		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( 'The hub address cannot carry a query, a fragment or a user name. Use the address on its own, such as https://hub.thinkrank.ai.', 'thinkrank' );
		}

		if ( ! self::host_is_valid( strtolower( (string) $parts['host'] ) ) ) {
			return __( 'The hub address’s host is not a name a browser can reach. It can hold letters, digits, hyphens and dots, such as hub.thinkrank.ai.', 'thinkrank' );
		}

		return __( 'The hub address’s path cannot climb with . or .. or hold spaces. Use the address the hub gives, such as https://hub.thinkrank.ai.', 'thinkrank' );
	}

	/**
	 * Save the owner's choice of hub.
	 *
	 * Refused while the address is pinned, while the address is not one this
	 * can use, and while a hub holds a grant: a hub that holds a grant keeps
	 * it whatever this setting says, so moving the address under it would
	 * leave its access working with nothing on the card to end it.
	 *
	 * @param string $url Address as typed; '' for the default.
	 * @return true|\WP_Error
	 */
	public static function set_url( string $url ) {
		if ( self::is_locked() ) {
			return new \WP_Error(
				'thinkrank_hub_locked',
				__( 'The hub address is set in this site’s configuration, so it cannot be changed here.', 'thinkrank' ),
				[ 'status' => 409 ]
			);
		}

		$normalized = '' === trim( $url ) ? self::DEFAULT_URL : self::normalize_url( $url );

		if ( null === $normalized ) {
			return new \WP_Error( 'thinkrank_hub_url_invalid', self::refusal( trim( $url ) ), [ 'status' => 400 ] );
		}

		// The guard, the write and the retire under one lock, so an exchange
		// cannot mint a grant for the old hub between the guard and the write
		// (#1144 review).
		return Mcp_OAuth::with_lock(
			static function () use ( $normalized ) {
				if ( $normalized !== self::url() && [] !== self::locked_apps() ) {
					return new \WP_Error(
						'thinkrank_hub_connected',
						__( 'This site is connected to a hub. Disconnect it first, then change the address, so the hub it is connected to does not keep its access.', 'thinkrank' ),
						[ 'status' => 409 ]
					);
				}

				update_option( self::OPTION, [ 'url' => $normalized ], false );

				// A ticket names the hub it was minted for only by being
				// outstanding, so changing the hub retires every one of them.
				self::retire_tickets();

				return true;
			}
		);
	}

	/**
	 * The hub's host, with its port where it names one other than https's own.
	 *
	 * @return string
	 */
	public static function host(): string {
		return self::host_of( self::url() );
	}

	/**
	 * The host an https address names, written the way host() writes the
	 * hub's. Empty for anything else.
	 *
	 * @param string $url An address.
	 * @return string
	 */
	public static function host_of( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}

		return self::authority( $parts );
	}

	/**
	 * Whether a return address sends a code to the hub.
	 *
	 * An exact host match over https. No wildcard and no parent domain: a
	 * subdomain of the hub is somebody else as far as this check is
	 * concerned. Spellings that reach the same server (the scheme's own port
	 * written out, a trailing dot) are the hub, or a client registered with
	 * one would be delivered the hub's code without the ticket it needs.
	 *
	 * @param string $redirect_uri A client's return address.
	 * @return bool
	 */
	public static function is_hub_redirect( string $redirect_uri ): bool {
		$parts = wp_parse_url( $redirect_uri );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		$host = self::authority( $parts );

		return '' !== $host && hash_equals( self::host(), $host );
	}

	/**
	 * Whether a return address sends a code to the public hub while this
	 * site is set to another one, or pinned to an address it cannot use.
	 *
	 * Such a client is not this site's hub, so is_hub_redirect() asks it for
	 * no ticket; and it is not an ordinary app either, since it is the hub the
	 * owner chose not to use. A ticket would not settle it: tickets do not
	 * name the hub they were minted for. So the consent page refuses it.
	 *
	 * @param string $redirect_uri A client's return address.
	 * @return bool
	 */
	public static function is_unchosen_hub_redirect( string $redirect_uri ): bool {
		$parts = wp_parse_url( $redirect_uri );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		return hash_equals( self::host_of( self::DEFAULT_URL ), self::authority( $parts ) ) && ! self::is_hub_redirect( $redirect_uri );
	}

	/**
	 * A parsed address's host and port, written one way: lowercase, one
	 * trailing dot dropped, and the port left out when it is the scheme's own.
	 *
	 * @param array<string,mixed> $parts From wp_parse_url().
	 * @return string
	 */
	private static function authority( array $parts ): string {
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( '.' === substr( $host, -1 ) ) {
			$host = substr( $host, 0, -1 );
		}

		if ( ! isset( $parts['port'] ) ) {
			return $host;
		}

		$port      = (int) $parts['port'];
		$scheme    = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$scheme_is = 'http' === $scheme ? 80 : 443;

		return $port === $scheme_is ? $host : $host . ':' . $port;
	}

	// -- Tickets -----------------------------------------------------------

	/**
	 * Mint the single-use ticket a connection starts with.
	 *
	 * Handed back once, in plaintext, to travel through the browser to the
	 * hub and back to the consent page. Only its digest is kept, with the
	 * account that minted it, the access it was started with and its expiry.
	 *
	 * @param int    $user_id The administrator starting the connection.
	 * @param string $access  ACCESS_READ or ACCESS_WRITE.
	 * @return string The ticket.
	 */
	public static function mint_ticket( int $user_id, string $access ): string {
		$ticket = bin2hex( random_bytes( 32 ) );
		$entry  = [
			'user_id' => $user_id,
			'access'  => self::ACCESS_WRITE === $access ? self::ACCESS_WRITE : self::ACCESS_READ,
			'expires' => time() + self::TICKET_TTL,
		];

		// Under the lock the exchange spends tickets in, re-reading inside
		// it: a mint that read before a spend and wrote after it would put
		// the spent ticket back, and two mints would lose one (#1144).
		Mcp_OAuth::with_lock(
			static function () use ( $ticket, $entry ): void {
				// Prepended: the sort is stable, so among tickets minted in
				// the same second the newest must already be first, or the
				// cap below would drop the ticket just handed back.
				$tickets = [ self::digest( $ticket ) => $entry ] + self::locked_tickets();

				// Newest first, so a person pressing the button repeatedly
				// keeps their latest ticket rather than their first.
				uasort(
					$tickets,
					static function ( array $a, array $b ): int {
						return $b['expires'] <=> $a['expires'];
					}
				);

				update_option( self::TICKETS_OPTION, array_slice( $tickets, 0, self::MAX_TICKETS, true ), false );
			}
		);

		return $ticket;
	}

	/**
	 * Whether a ticket is outstanding and was minted by this account.
	 *
	 * @param string $ticket  The ticket as presented.
	 * @param int    $user_id The account approving.
	 * @return bool
	 */
	public static function ticket_ok( string $ticket, int $user_id ): bool {
		return null !== self::ticket( $ticket, $user_id );
	}

	/**
	 * An outstanding ticket this account minted, or null.
	 *
	 * @param string $ticket  The ticket as presented.
	 * @param int    $user_id The account approving.
	 * @return array{user_id:int,access:string,expires:int}|null
	 */
	public static function ticket( string $ticket, int $user_id ): ?array {
		if ( '' === $ticket || $user_id <= 0 ) {
			return null;
		}

		$entry = self::live_tickets()[ self::digest( $ticket ) ] ?? null;

		return is_array( $entry ) && (int) $entry['user_id'] === $user_id ? $entry : null;
	}

	/**
	 * Spend a ticket. True only for one that was outstanding.
	 *
	 * One ticket can ride two codes (the consent form sent twice), and two
	 * exchanges could both find it outstanding before either writes it away.
	 * The token endpoint calls this inside Mcp_OAuth::mutate(), under the
	 * advisory lock that already serializes code exchanges, so the two are
	 * arbitrated there. Taking the lock again here is re-entrant; it is what
	 * keeps a spend called on its own from racing a mint (#1144).
	 *
	 * @param string $ticket The ticket as presented.
	 * @return bool
	 */
	public static function spend_ticket( string $ticket ): bool {
		if ( '' === $ticket ) {
			return false;
		}

		return (bool) Mcp_OAuth::with_lock(
			static function () use ( $ticket ): bool {
				$key     = self::digest( $ticket );
				$tickets = self::locked_tickets();

				if ( ! isset( $tickets[ $key ] ) ) {
					return false;
				}

				unset( $tickets[ $key ] );
				update_option( self::TICKETS_OPTION, $tickets, false );

				return true;
			}
		);
	}

	/**
	 * Retire every outstanding ticket, under the lock the exchange spends
	 * them in, so a mint already holding a read cannot write them back.
	 *
	 * @return void
	 */
	private static function retire_tickets(): void {
		Mcp_OAuth::with_lock(
			static function (): void {
				delete_option( self::TICKETS_OPTION );
				self::forget_cached_tickets();
			}
		);
	}

	/**
	 * Drop what this request cached about the tickets option: the row, and
	 * the "no such option" mark get_option() keeps once a read found none,
	 * which would otherwise answer the re-read under the lock without
	 * asking the database.
	 *
	 * @return void
	 */
	private static function forget_cached_tickets(): void {
		wp_cache_delete( self::TICKETS_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The outstanding tickets as stored, for a write already holding the
	 * lock. A copy this request cached before taking it may predate a spend
	 * or a mint that landed in between, so it is dropped first (#1074).
	 *
	 * @return array<string,array{user_id:int,access:string,expires:int}>
	 */
	private static function locked_tickets(): array {
		self::forget_cached_tickets();

		return self::live_tickets();
	}

	/**
	 * A ticket's digest, which is all that is ever stored.
	 *
	 * @param string $ticket The ticket.
	 * @return string
	 */
	public static function digest( string $ticket ): string {
		return hash( 'sha256', $ticket );
	}

	/**
	 * The outstanding tickets, by digest.
	 *
	 * @return array<string,array{user_id:int,access:string,expires:int}>
	 */
	private static function live_tickets(): array {
		$stored = get_option( self::TICKETS_OPTION );
		$live   = [];

		if ( ! is_array( $stored ) ) {
			return $live;
		}

		foreach ( $stored as $key => $entry ) {
			if ( is_string( $key ) && is_array( $entry ) && (int) ( $entry['expires'] ?? 0 ) > time() ) {
				$live[ $key ] = [
					'user_id' => (int) ( $entry['user_id'] ?? 0 ),
					'access'  => (string) ( $entry['access'] ?? self::ACCESS_READ ),
					'expires' => (int) $entry['expires'],
				];
			}
		}

		return $live;
	}

	// -- The connection ----------------------------------------------------

	/**
	 * Where the browser goes to start connecting: the hub's `/connect`, with
	 * this site, the ticket, the access chosen and where to come back to.
	 *
	 * @param string $ticket The ticket just minted.
	 * @param string $access ACCESS_READ or ACCESS_WRITE.
	 * @param string $from   Where the connection started: FROM_WIZARD, or
	 *                       anything else for the ThinkRank Hub tab.
	 * @return string
	 */
	public static function connect_url( string $ticket, string $access, string $from = '' ): string {
		$query = [
			'site'   => untrailingslashit( home_url( '/' ) ),
			'ticket' => $ticket,
			'access' => self::ACCESS_WRITE === $access ? self::ACCESS_WRITE : self::ACCESS_READ,
			'return' => self::return_url( $from ),
		];

		return self::url() . '/connect?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * The screen the hub sends a person back to: the setup wizard when the
	 * connection started there, the ThinkRank Hub tab otherwise. Both are on
	 * this site's own origin under wp-admin, which is the only kind of return
	 * address the hub takes, and the caller only names one of them: it never
	 * supplies an address.
	 *
	 * @param string $from Where the connection started.
	 * @return string
	 */
	public static function return_url( string $from = '' ): string {
		return admin_url( self::FROM_WIZARD === $from ? self::WIZARD_SCREEN : self::HUB_SCREEN );
	}

	/**
	 * The MCP screen, where the hub's grant is listed with the other
	 * connected apps.
	 *
	 * @return string
	 */
	public static function mcp_screen_url(): string {
		return admin_url( self::MCP_SCREEN );
	}

	/**
	 * What this site says about itself at the token exchange, so the hub can
	 * refuse a code that came back from somewhere other than where it began,
	 * and learn which versions run here. Pro's version is null without Pro.
	 * Never a license.
	 *
	 * @return array{home_url:string,blog_id:int,version:string,pro_version:string|null}
	 */
	public static function site_facts(): array {
		return [
			'home_url'    => untrailingslashit( home_url( '/' ) ),
			'blog_id'     => get_current_blog_id(),
			'version'     => defined( 'THINKRANK_VERSION' ) ? (string) constant( 'THINKRANK_VERSION' ) : '',
			'pro_version' => defined( 'THINKRANK_PRO_VERSION' ) ? (string) constant( 'THINKRANK_PRO_VERSION' ) : null,
		];
	}

	/**
	 * The live hub grants: every connected app whose grant carries the hub
	 * mark. A grant made through a hub ticket names the hub it was approved
	 * for, and stays that hub's when the address changes underneath it, so it
	 * can still be seen and ended here.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function apps(): array {
		$apps = [];

		foreach ( Mcp_OAuth::connected_apps() as $app ) {
			if ( '' !== (string) ( $app['hub'] ?? '' ) ) {
				$apps[] = $app;
			}
		}

		return $apps;
	}

	/**
	 * When the hub last read this site: the newest use of any of its grants,
	 * as a unix timestamp, or 0 when it has not read it yet.
	 *
	 * @return int
	 */
	public static function last_read(): int {
		$last = 0;

		foreach ( self::apps() as $app ) {
			$last = max( $last, (int) ( $app['last_used'] ?? 0 ) );
		}

		return $last;
	}

	/**
	 * End every hub grant this site holds, and retire every ticket.
	 *
	 * @return int How many grants were ended.
	 */
	public static function disconnect(): int {
		// One lock around the read, the revokes and the retire: an exchange
		// landing after a snapshot of the grants would mint one this never
		// ends (#1144 review).
		return (int) Mcp_OAuth::with_lock(
			static function (): int {
				$count = 0;

				foreach ( self::locked_apps() as $app ) {
					if ( Mcp_OAuth::revoke_client( (string) $app['client_id'] ) ) {
						++$count;
					}
				}

				self::retire_tickets();

				return $count;
			}
		);
	}

	/**
	 * The hub's grants as stored, for work already holding the lock. A copy
	 * cached before the lock may predate an exchange that landed in between.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function locked_apps(): array {
		wp_cache_delete( Mcp_OAuth::OPTION, 'options' );

		return self::apps();
	}

	/**
	 * Whether this copy of the site is a staging environment.
	 *
	 * A staging copy carries the live site's grants with its database. The
	 * hub's grant is paused on one rather than ended, so the hub goes on
	 * seeing one site, and the grant works again where the copy stops being
	 * marked staging. WordPress's own environment type is the mark; the
	 * filter is for a host that knows better.
	 *
	 * @return bool
	 */
	public static function is_staging(): bool {
		$staging = function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type();

		/**
		 * Filters whether this copy of the site is a staging copy, on which
		 * the hub's grant is paused without being ended.
		 *
		 * @since 2.15.0
		 *
		 * @param bool $staging Whether `wp_get_environment_type()` says staging.
		 */
		return (bool) apply_filters( 'thinkrank_hub_staging', $staging );
	}

	/**
	 * Every one of this site's own answers that refuses the hub right now,
	 * or none when a grant it holds is let in.
	 *
	 * In the order a hub call meets them: a staging copy refuses the hub's
	 * token, and the switched-off MCP server refuses every call. All of them
	 * are named, not the first: undoing one lets the hub in only when it was
	 * the only one.
	 *
	 * @return string[]
	 */
	public static function refused_by(): array {
		$refusals = [];

		if ( self::is_staging() ) {
			$refusals[] = self::REFUSAL_STAGING;
		}

		if ( ! Mcp_Manager::is_enabled() ) {
			$refusals[] = self::REFUSAL_CONNECTION;
		}

		return $refusals;
	}

	// -- The session ---------------------------------------------------------

	/**
	 * A signed-in browser session rather than a token.
	 *
	 * An application password is itself a standing credential, generated and
	 * scoped to one integration. Handing this site to the hub is an
	 * administrator's own decision, taken in front of the screen that shows
	 * what it means, so it asks for the session a person is sitting at.
	 *
	 * @return true|\WP_Error|false False when nobody is signed in.
	 */
	public static function interactive() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return new \WP_Error(
				'thinkrank_hub_interactive_only',
				__( 'The hub connection can only be changed from a signed-in browser session, not through an application password.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}
}
