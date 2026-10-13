<?php
/**
 * MCP session — who is calling, for the length of one request.
 *
 * Mcp_Server::authorize() resolves the Bearer credential once; everything that
 * runs afterwards (scope checks, the activity log, the session instructions)
 * needs to know which credential that was. Held here, request-scoped, rather
 * than threaded through every call.
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
 * The authenticated MCP caller for the current request.
 */
final class Mcp_Session {

	/**
	 * Credential kinds.
	 */
	public const STATIC_TOKEN = 'static';
	public const OAUTH        = 'oauth';

	/**
	 * Active credential, or null outside an authenticated MCP request.
	 *
	 * @var array{kind:string,client_id:string,client_name:string,user_id:int,levels:array<string,int>}|null
	 */
	private static $current = null;

	/**
	 * Record the credential that authenticated this request.
	 *
	 * @param string   $kind        STATIC_TOKEN or OAUTH.
	 * @param string   $client_id   OAuth client id; '' for the static token.
	 * @param string   $client_name Display name.
	 * @param int      $user_id     WordPress user the request runs as.
	 * @param string[] $scopes      Granted scope tokens.
	 * @return void
	 */
	public static function start( string $kind, string $client_id, string $client_name, int $user_id, array $scopes ): void {
		self::$current = [
			'kind'        => $kind,
			'client_id'   => $client_id,
			'client_name' => $client_name,
			'user_id'     => $user_id,
			'levels'      => Mcp_Scopes::levels( $scopes ),
		];
	}

	/**
	 * Forget the credential (tests, and between batched requests that never
	 * re-authenticate — there are none today, but a stale caller must never
	 * leak into a later request in the same process).
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$current = null;
	}

	/**
	 * Whether an authenticated MCP caller is active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return null !== self::$current;
	}

	/**
	 * The active credential's area levels. Outside an MCP request every area
	 * is open: the scope model governs MCP credentials, not admins.
	 *
	 * @return array<string,int>
	 */
	public static function levels(): array {
		return null === self::$current
			? array_fill_keys( Mcp_Scopes::AREAS, Mcp_Scopes::WRITE )
			: self::$current['levels'];
	}

	/**
	 * Credential kind, '' when none.
	 *
	 * @return string
	 */
	public static function kind(): string {
		return null === self::$current ? '' : self::$current['kind'];
	}

	/**
	 * OAuth client id, '' for the static token or no session.
	 *
	 * @return string
	 */
	public static function client_id(): string {
		return null === self::$current ? '' : self::$current['client_id'];
	}

	/**
	 * Key identifying the caller in the activity log: the OAuth client id, or
	 * `static` for the shared connection token.
	 *
	 * @return string
	 */
	public static function client_key(): string {
		if ( null === self::$current ) {
			return '';
		}
		return self::OAUTH === self::$current['kind'] ? self::$current['client_id'] : self::STATIC_TOKEN;
	}

	/**
	 * Display name of the caller.
	 *
	 * @return string
	 */
	public static function client_name(): string {
		return null === self::$current ? '' : self::$current['client_name'];
	}

	/**
	 * User the request runs as.
	 *
	 * @return int
	 */
	public static function user_id(): int {
		return null === self::$current ? 0 : self::$current['user_id'];
	}
}
