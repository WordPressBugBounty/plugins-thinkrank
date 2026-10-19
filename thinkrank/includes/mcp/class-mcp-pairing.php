<?php
/**
 * MCP pairing lifecycle — mint / rotate / revoke the per-site connection token.
 *
 * The primary way an AI assistant connects to ThinkRank: an admin clicks
 * Connect, the plugin mints a 32-byte secret, and the user pastes the
 * endpoint + Bearer token into their AI client. The token travels in the
 * Authorization header and nowhere else — never in a URL, where it would
 * land in access logs, proxy logs, Referer headers and browser history
 * (#1001). It is validated directly by Mcp_Server; no hosted infrastructure
 * is involved.
 *
 * State is stored in the `thinkrank_mcp_pairing` option:
 *   {
 *     site_token:   string (the secret the client presents),
 *     connected:    bool,
 *     connected_at: int (unix ts),
 *     scopes:       string[] (e.g. ['read','write'], or per-area
 *                   tokens such as ['content:write','analytics:read']),
 *     user_id:      int (admin who minted the token; MCP calls run as them),
 *     expires_at:   int (unix ts; 0 = never. Absent on tokens minted before
 *                   expiry existed, which therefore never expire — #717)
 *   }
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

use ThinkRank\Core\Secret_At_Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Connection-token lifecycle for the ThinkRank MCP server.
 */
final class Mcp_Pairing {

	/**
	 * Option key holding all MCP pairing state.
	 */
	public const OPTION = 'thinkrank_mcp_pairing';

	/**
	 * When the static token last authenticated a call. Kept apart from OPTION
	 * for the reason Mcp_OAuth::LAST_USED_OPTION is (#485): it is written by
	 * agent traffic, and writing the credential option from there put back a
	 * copy read before an admin's rotate, scope edit or expiry change.
	 */
	public const LAST_USED_OPTION = 'thinkrank_mcp_pairing_last_used';

	/**
	 * Set when the owner ended the connection token on purpose (Disconnect,
	 * or Revoke all on a site that had none), so the MCP screen's self-heal
	 * does not mint a new one behind their back (#1075). An explicit connect
	 * or rotate clears it.
	 *
	 * @since 2.17.1
	 */
	public const DECLINED_OPTION = 'thinkrank_mcp_pairing_declined';

	/**
	 * Path segment of the pretty per-site endpoint.
	 */
	public const SITE_ENDPOINT_PATH = 'thinkrank/mcp';

	/**
	 * Default scopes granted on connect.
	 */
	private const DEFAULT_SCOPES = [ 'read', 'write' ];

	/**
	 * Stored for a token granted no area at all. An empty list reads as
	 * "never set" to connect() and rotate(), which would mint read+write;
	 * Mcp_Scopes::levels() ignores this token, so it grants nothing.
	 */
	private const NO_ACCESS_SCOPES = [ 'none' ];

	/**
	 * Throttle window (seconds) for last-used writes — one option write per
	 * minute at most, so a busy client can't hammer the option on every call.
	 */
	private const LAST_USED_THROTTLE = 60;

	/**
	 * How far ahead "expires soon" is announced, in seconds.
	 */
	public const EXPIRY_WARNING = 7 * DAY_IN_SECONDS;

	/**
	 * The PRIMARY endpoint the user pastes into their AI client — this
	 * site's own MCP URL.
	 *
	 * @return string
	 */
	public static function site_endpoint(): string {
		return home_url( '/' . self::SITE_ENDPOINT_PATH );
	}

	/**
	 * Always-on fallback endpoint via the REST namespace, for hosts where
	 * the pretty rewrite can't be served (e.g. plain permalinks).
	 *
	 * @return string
	 */
	public static function site_endpoint_fallback(): string {
		return rest_url( 'thinkrank/v1/mcp' );
	}

	/**
	 * Current pairing state, defaults merged.
	 *
	 * @return array{site_token:string,token_hash:string,token_sealed:bool,connected:bool,connected_at:int,scopes:string[],user_id:int,last_used:int,expires_at:int,expiry_set:bool}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$raw   = isset( $stored['site_token'] ) ? (string) $stored['site_token'] : '';
		$plain = '' === $raw ? '' : Secret_At_Rest::decrypt( $raw );

		// Sealed: something IS stored, but this site can no longer open it —
		// the auth salt rotated, or sodium went away under us (decrypt() hands
		// the envelope back unchanged in that case). Either way there is no
		// displayable credential, and the envelope must never be passed off as
		// one: it would be copied into a client and 401 forever.
		$sealed = '' !== $raw && ( '' === $plain || Secret_At_Rest::is_encrypted( $plain ) );

		return [
			// Decrypted for display and for the self-test's own probe. Stored
			// encrypted (#396) — a database read on its own no longer yields a
			// usable admin-equivalent credential.
			'site_token'   => $sealed ? '' : $plain,
			// Whether a stored token exists that cannot be shown here. Callers
			// use this to tell "never connected" apart from "connected, but
			// this site cannot display the token any more".
			'token_sealed' => $sealed,
			// What authorize() compares against. Held separately so a token
			// whose ciphertext can no longer be opened — the auth salt was
			// rotated, the site was migrated without wp-config — keeps
			// authenticating the clients already configured with it, instead of
			// silently locking them out.
			'token_hash'   => isset( $stored['token_hash'] ) ? (string) $stored['token_hash'] : '',
			'connected'    => ! empty( $stored['connected'] ),
			'connected_at' => isset( $stored['connected_at'] ) ? (int) $stored['connected_at'] : 0,
			'scopes'       => isset( $stored['scopes'] ) && is_array( $stored['scopes'] )
				? array_values( array_map( 'strval', $stored['scopes'] ) )
				: [],
			'user_id'      => isset( $stored['user_id'] ) ? (int) $stored['user_id'] : 0,
			// Falls back to a stamp written onto the credential option before
			// it moved to its own.
			'last_used'    => (int) get_option( self::LAST_USED_OPTION, isset( $stored['last_used'] ) ? (int) $stored['last_used'] : 0 ),
			// 0 = never. A token minted before expiry existed carries no key
			// at all and keeps working; `expiry_set` lets the screen say so.
			'expires_at'   => isset( $stored['expires_at'] ) ? (int) $stored['expires_at'] : 0,
			'expiry_set'   => array_key_exists( 'expires_at', $stored ),
		];
	}

	/**
	 * Whether the stored token has passed its expiry.
	 *
	 * @return bool
	 */
	public static function is_expired(): bool {
		$expires = self::state()['expires_at'];
		return $expires > 0 && time() >= $expires;
	}

	/**
	 * Whether a presented token is the pairing token but has expired, so the
	 * 401 can say "expired" instead of "invalid" and the owner knows to
	 * rotate rather than hunt for a typo.
	 *
	 * @param string $presented Token presented by the client.
	 * @return bool
	 */
	public static function presented_is_expired( string $presented ): bool {
		$state = self::state();
		return '' !== $presented
			&& '' !== $state['token_hash']
			&& hash_equals( $state['token_hash'], self::hash( $presented ) )
			&& self::is_expired();
	}

	/**
	 * The granted scope tokens.
	 *
	 * @return string[]
	 */
	public static function scopes(): array {
		return self::state()['scopes'];
	}

	/**
	 * Replace the granted scopes. Takes effect on the next request: every
	 * request reads the stored scopes, so no client has to reconnect.
	 *
	 * @param array<string,mixed> $levels Area => level.
	 * @return array<string,mixed> Public status.
	 */
	public static function set_levels( array $levels ): array {
		self::locked(
			static function () use ( $levels ): void {
				$stored = get_option( self::OPTION, [] );
				if ( is_array( $stored ) && ! empty( $stored['connected'] ) ) {
					$tokens           = Mcp_Scopes::tokens( Mcp_Scopes::normalize_levels( $levels ) );
					$stored['scopes'] = [] === $tokens ? self::NO_ACCESS_SCOPES : $tokens;
					update_option( self::OPTION, $stored, false );
				}
			}
		);
		return self::public_status();
	}

	/**
	 * Set the token's expiry, counted from now, without rotating it.
	 *
	 * @param int $days Lifetime in days (Mcp_Governance::TOKEN_TTL_CHOICES); 0 = never.
	 * @return array<string,mixed> Public status.
	 */
	public static function set_expiry( int $days ): array {
		self::locked(
			static function () use ( $days ): void {
				$stored = get_option( self::OPTION, [] );
				if ( is_array( $stored ) && ! empty( $stored['connected'] ) ) {
					$stored['expires_at'] = Mcp_Governance::expiry_for( $days );
					update_option( self::OPTION, $stored, false );
				}
			}
		);
		return self::public_status();
	}

	/**
	 * Run a read-modify-write of self::OPTION under the MCP option lock,
	 * reading the option fresh: an edit of the token's access that read it
	 * before a rotate wrote it would otherwise put the old token back.
	 *
	 * @param callable $work Work to run while the lock is held.
	 * @return mixed What $work returns.
	 */
	private static function locked( callable $work ) {
		return Mcp_OAuth::with_lock(
			static function () use ( $work ) {
				// This request may hold a copy read before the lock.
				wp_cache_delete( self::OPTION, 'options' );
				return $work();
			}
		);
	}

	/**
	 * Record that the static token was just used to authenticate an MCP call.
	 * Throttled to at most one option write per minute so a busy client can't
	 * turn every request into a database write. No-op when not connected.
	 * Writes only LAST_USED_OPTION, never the credential option.
	 *
	 * @return void
	 */
	public static function touch_last_used(): void {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored['site_token'] ) ) {
			return;
		}
		$now = time();
		if ( $now - (int) get_option( self::LAST_USED_OPTION, 0 ) < self::LAST_USED_THROTTLE ) {
			return;
		}
		update_option( self::LAST_USED_OPTION, $now, false );
	}

	/**
	 * SHA-256 used to store the pairing token's verifier at rest.
	 *
	 * Mirrors Mcp_OAuth::hash(), which has always stored access and refresh
	 * tokens this way. The pairing token was the one exception (#396).
	 *
	 * @since 2.0.1
	 *
	 * @param string $value Raw token.
	 * @return string
	 */
	private static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}

	/**
	 * Whether a presented token is the pairing token.
	 *
	 * Compared against the stored hash. A row written before this change holds
	 * a plaintext token and no hash, so it is verified against the plaintext
	 * once and then upgraded in place — an existing pairing keeps working and
	 * no one has to re-pair.
	 *
	 * @since 2.0.1
	 *
	 * @param string $presented Token presented by the client.
	 * @return bool
	 */
	public static function verify_token( string $presented ): bool {
		if ( '' === $presented ) {
			return false;
		}

		$state = self::state();

		// An expired token is refused like any other wrong token: the hash
		// may match, but the credential is no longer valid (#717).
		if ( $state['expires_at'] > 0 && time() >= $state['expires_at'] ) {
			return false;
		}

		if ( '' !== $state['token_hash'] ) {
			return hash_equals( $state['token_hash'], self::hash( $presented ) );
		}

		// Legacy row: plaintext, no hash.
		if ( '' === $state['site_token'] || ! hash_equals( $state['site_token'], $presented ) ) {
			return false;
		}

		self::upgrade_legacy_storage( $presented );

		return true;
	}

	/**
	 * Re-store a legacy plaintext token encrypted, with its hash.
	 *
	 * Under the option lock, and only while the row still holds this legacy
	 * token: a Disconnect or rotate that landed after the token was verified
	 * would otherwise have the old token written back (#1078).
	 *
	 * @since 2.0.1
	 * @since 2.17.1 Holds the option lock and re-checks the row inside it.
	 *
	 * @param string $token Raw token, already verified.
	 * @return void
	 */
	private static function upgrade_legacy_storage( string $token ): void {
		self::locked(
			static function () use ( $token ): void {
				$stored = get_option( self::OPTION, [] );

				if ( ! is_array( $stored ) ) {
					return;
				}

				$state = self::state();
				if ( '' !== $state['token_hash'] || '' === $state['site_token'] || ! hash_equals( $state['site_token'], $token ) ) {
					return;
				}

				$stored['site_token'] = Secret_At_Rest::encrypt( $token );
				$stored['token_hash'] = self::hash( $token );

				update_option( self::OPTION, $stored, false );
			}
		);
	}

	/**
	 * The stored site token (secret). Empty string when not connected.
	 *
	 * @return string
	 */
	public static function site_token(): string {
		return self::state()['site_token'];
	}

	/**
	 * The admin user the connection runs as (the token's minter).
	 *
	 * @return int
	 */
	public static function user_id(): int {
		return self::state()['user_id'];
	}

	/**
	 * Whether an MCP connection token is currently active for this site.
	 *
	 * Deliberately reads the hash, not the decrypted token. Those are not the
	 * same question: after an auth salt rotation the ciphertext will not open,
	 * so `site_token` is '' — but `token_hash` still verifies the credential
	 * every configured client is holding, and verify_token() still accepts it.
	 * Answering "not connected" there made ensure_connected() mint a fresh
	 * token over the hash, which was the only surviving copy of the live one.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$state = self::state();
		return $state['connected'] && ( '' !== $state['token_hash'] || '' !== $state['site_token'] );
	}

	/**
	 * Whether the owner ended the connection token on purpose and has not
	 * created one since (#1075).
	 *
	 * @since 2.17.1
	 *
	 * @return bool
	 */
	public static function is_declined(): bool {
		return (bool) get_option( self::DECLINED_OPTION, false );
	}

	/**
	 * Record that the owner wants no connection token until they create one.
	 *
	 * @since 2.17.1
	 *
	 * @return void
	 */
	public static function decline(): void {
		update_option( self::DECLINED_OPTION, 1, false );
	}

	/**
	 * The MCP screen's self-heal: mint a token for a site that has MCP on
	 * and none, unless the owner declined one.
	 *
	 * Both conditions are read again under the option lock. A screen load
	 * that checked them before a Disconnect or Revoke all finished would
	 * otherwise go on to connect(), which clears the flag as an explicit
	 * choice, and mint the token the owner had just ended (#1075).
	 *
	 * @since 2.17.1
	 *
	 * @return void
	 */
	public static function self_heal(): void {
		self::locked(
			static function (): void {
				self::forget_cached( self::DECLINED_OPTION );
				self::forget_cached( self::OPTION );

				if ( self::is_declined() || self::is_connected() ) {
					return;
				}

				self::connect_locked( false );
			}
		);
	}

	/**
	 * Drop this request's cached copy of an option, including a cached
	 * "does not exist", so the next read goes to the database.
	 *
	 * @since 2.17.1
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private static function forget_cached( string $option ): void {
		wp_cache_delete( $option, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Whether the active connection is limited to read-only tools.
	 *
	 * @return bool
	 */
	public static function is_read_only(): bool {
		return ! Mcp_Scopes::grants_any_write( Mcp_Scopes::levels( self::state()['scopes'] ) );
	}

	/**
	 * Sanitized snapshot for the MCP admin page.
	 *
	 * @return array<string,mixed>
	 */
	public static function public_status(): array {
		$state = self::state();
		return [
			'connected'         => self::is_connected(),
			'connection_token'  => $state['site_token'],
			// Connected, but the token cannot be displayed on this site any
			// more. The screen offers a rotate instead of a blank recipe.
			'token_sealed'      => $state['token_sealed'],
			'mcp_endpoint'      => self::site_endpoint(),
			'mcp_endpoint_rest' => self::site_endpoint_fallback(),
			'connected_at'      => $state['connected_at'],
			'last_used'         => $state['last_used'],
			'scopes'            => $state['scopes'],
			'levels'            => Mcp_Scopes::level_names( Mcp_Scopes::levels( $state['scopes'] ) ),
			'read_only'         => self::is_read_only(),
			'expires_at'        => $state['expires_at'],
			'expiry_set'        => $state['expiry_set'],
			'expired'           => self::is_expired(),
			'expires_soon'      => $state['expires_at'] > 0 && ! self::is_expired() && $state['expires_at'] - time() <= self::EXPIRY_WARNING,
			// Ready-to-paste connection recipes, header-based: the token is
			// never part of a URL this returns, so it can't leak into
			// server/proxy logs (#1001).
			'config'            => self::config_snippets(),
			// A drop-in instruction the user can paste into their AI client so
			// it sets the connection up itself.
			'ai_prompt'         => self::ai_prompt(),
		];
	}

	/**
	 * Ready-to-paste connection recipes for the dashboard. All header-based
	 * (Authorization: Bearer) so the secret stays out of URLs and logs.
	 * Empty strings when not connected.
	 *
	 * @return array{cli:string,json:string}
	 */
	public static function config_snippets(): array {
		$token = self::site_token();
		if ( '' === $token ) {
			return [
				'cli'  => '',
				'json' => '',
			];
		}
		$endpoint = self::site_endpoint();

		// Claude Code one-liner. The CLI requires the positional NAME and URL
		// BEFORE any flags (`claude mcp add <name> <url> --flags`).
		$cli = sprintf(
			'claude mcp add thinkrank %s --transport http --header "Authorization: Bearer %s"',
			$endpoint,
			$token
		);

		// Portable mcpServers JSON block (Claude Desktop / other clients).
		$json = wp_json_encode(
			[
				'mcpServers' => [
					'thinkrank' => [
						'url'     => $endpoint,
						'headers' => [
							'Authorization' => 'Bearer ' . $token,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return [
			'cli'  => $cli,
			'json' => is_string( $json ) ? $json : '',
		];
	}

	/**
	 * A copy-paste instruction the user hands to their AI assistant so IT
	 * sets up the ThinkRank MCP connection for them. Empty when not connected.
	 *
	 * @return string
	 */
	public static function ai_prompt(): string {
		if ( ! self::is_connected() ) {
			return '';
		}
		$token    = self::site_token();
		$endpoint = self::site_endpoint();
		$access   = self::is_read_only()
			? 'read-only (inspect SEO data and settings only)'
			: 'read-write (can update SEO metadata and settings)';

		$lines = [
			'Add the following remote MCP server to your connections so you can manage SEO on my WordPress site, then use it.',
			'',
			'Server name: ThinkRank',
			'Server URL: ' . $endpoint,
			'Transport: streamable HTTP',
			'Authentication: Bearer token (in the Authorization header)',
			'API key: ' . $token,
			'Access level: ' . $access,
			'',
			'If you use the Claude Code CLI, this is the exact command (name and URL come BEFORE the flags):',
			'  ' . self::config_snippets()['cli'],
			'',
			'Add it now, confirm it is connected by calling its "list-content-types" tool, and tell me what content types you can see.',
		];
		$prompt = implode( "\n", $lines );

		/**
		 * Filter the copy-paste AI setup prompt shown in the MCP page.
		 *
		 * @param string $prompt    The default prompt text.
		 * @param bool   $read_only Whether the connection is read-only.
		 */
		return (string) apply_filters( 'thinkrank_mcp_ai_prompt', $prompt, self::is_read_only() );
	}

	/**
	 * Connect — mint a connection token for this site's MCP endpoint.
	 *
	 * Idempotent: re-connecting keeps the existing token (and its scopes) so
	 * a paired client isn't silently broken. Use rotate() to change either.
	 *
	 * @param bool $read_only Grant only the `read` scope on a NEW token.
	 * @return array<string,mixed> Public status.
	 */
	public static function connect( bool $read_only = false ): array {
		return self::locked(
			static function () use ( $read_only ): array {
				return self::connect_locked( $read_only );
			}
		);
	}

	/**
	 * connect(), with the option lock held.
	 *
	 * @param bool $read_only Grant only the `read` scope on a NEW token.
	 * @return array<string,mixed> Public status.
	 */
	private static function connect_locked( bool $read_only ): array {
		// Asking for a token is the owner choosing one again.
		delete_option( self::DECLINED_OPTION );

		$state = self::state();
		// The hash is what decides "is there a pairing", not the decrypted
		// token: after an auth salt rotation the ciphertext will not open, but
		// the credential every configured client holds still authenticates
		// against the hash.
		$existing = '' !== $state['token_hash'] || '' !== $state['site_token'];
		$scopes   = $existing && ! empty( $state['scopes'] )
			? $state['scopes']
			: self::scopes_for( $read_only );

		if ( $existing && $state['token_sealed'] ) {
			// Keeping a pairing this site can no longer read. Falling through
			// would re-encrypt $state['site_token'] — which is '' here — and
			// write hash('') over token_hash, destroying the last copy of a
			// live credential and silently resetting its scopes and owner.
			// Touch only the metadata; rotate() is the deliberate re-mint.
			$stored              = get_option( self::OPTION, [] );
			$stored              = is_array( $stored ) ? $stored : [];
			$stored['connected'] = true;
			$stored['scopes']    = $scopes;
			if ( empty( $stored['user_id'] ) ) {
				$stored['user_id'] = get_current_user_id();
			}

			update_option( self::OPTION, $stored, false );

			return self::public_status();
		}

		$token = $existing ? $state['site_token'] : self::mint_token();

		$record = [
			'site_token'   => Secret_At_Rest::encrypt( $token ),
			'token_hash'   => self::hash( $token ),
			'connected'    => true,
			'connected_at' => $existing ? $state['connected_at'] : time(),
			'scopes'       => $scopes,
			'user_id'      => $existing && $state['user_id'] ? $state['user_id'] : get_current_user_id(),
		];

		// A new token gets the configured lifetime. An existing one keeps
		// whatever it had, including no expiry at all: re-connecting must not
		// start a clock on a token clients already rely on.
		if ( ! $existing ) {
			$record['expires_at'] = Mcp_Governance::expiry_for();
		} elseif ( $state['expiry_set'] ) {
			$record['expires_at'] = $state['expires_at'];
		}

		update_option( self::OPTION, $record, false );

		return self::public_status();
	}

	/**
	 * Rotate the token only when one exists. Revoke all uses this so a site
	 * that only ever connected over OAuth is not handed a read+write
	 * connection token it never had.
	 *
	 * @since 2.15.0
	 * @return array<string,mixed>|null Public status with the fresh token, or null when there was none.
	 */
	public static function rotate_existing(): ?array {
		if ( self::is_connected() ) {
			return self::rotate();
		}

		// Nothing to replace. Revoke all is the owner saying no access, so
		// the MCP screen must not mint one on its next load (#1075).
		self::decline();

		return null;
	}

	/**
	 * Rotate — mint a BRAND-NEW token, invalidating the previous one
	 * immediately. The leaked-token remedy. Optionally flips read-only.
	 *
	 * @param bool|null $read_only null = keep current scopes; true/false = set.
	 * @param int|null  $ttl_days  Lifetime of the new token in days; null = the
	 *                             configured default, 0 = never.
	 * @return array<string,mixed> Public status with the fresh token.
	 */
	public static function rotate( ?bool $read_only = null, ?int $ttl_days = null ): array {
		return self::locked(
			static function () use ( $read_only, $ttl_days ): array {
				return self::rotate_locked( $read_only, $ttl_days );
			}
		);
	}

	/**
	 * rotate(), with the option lock held.
	 *
	 * @param bool|null $read_only null = keep current scopes; true/false = set.
	 * @param int|null  $ttl_days  Lifetime in days; null = default, 0 = never.
	 * @return array<string,mixed> Public status with the fresh token.
	 */
	private static function rotate_locked( ?bool $read_only, ?int $ttl_days ): array {
		$state  = self::state();
		$scopes = null === $read_only
			? ( ! empty( $state['scopes'] ) ? $state['scopes'] : self::DEFAULT_SCOPES )
			: self::scopes_for( $read_only );

		$token = self::mint_token();

		update_option(
			self::OPTION,
			[
				'site_token'   => Secret_At_Rest::encrypt( $token ),
				'token_hash'   => self::hash( $token ),
				'connected'    => true,
				'connected_at' => time(),
				'scopes'       => $scopes,
				'user_id'      => get_current_user_id() ? get_current_user_id() : $state['user_id'],
				'expires_at'   => Mcp_Governance::expiry_for( $ttl_days ),
			],
			false
		);

		// A new token has not been used yet.
		delete_option( self::LAST_USED_OPTION );
		delete_option( self::DECLINED_OPTION );

		return self::public_status();
	}

	/**
	 * Disconnect — revoke the connection token AND every OAuth grant, so
	 * Disconnect is a single kill switch for ALL MCP access.
	 *
	 * Under the option lock, as every other write of self::OPTION is: an
	 * access or expiry edit that already read the record would otherwise
	 * write it back after the delete, and the token would keep working
	 * (#1078). The lock is re-entrant, so revoke_all() takes it again inside.
	 *
	 * @since 2.17.1 Holds the option lock.
	 *
	 * @return array<string,mixed> Public status after disconnect.
	 */
	public static function disconnect(): array {
		// Disconnect is the kill switch: no token comes back until the owner
		// creates one (#1075). Declined before the delete and under the same
		// lock as every other write of the option (#1078), so a screen load
		// landing in between sees either the token or the flag, never neither.
		self::locked(
			static function (): void {
				self::decline();
				delete_option( self::OPTION );
				delete_option( self::LAST_USED_OPTION );
				Mcp_OAuth::revoke_all();
			}
		);

		return self::public_status();
	}

	/**
	 * Map a read-only flag to the granted scope list.
	 *
	 * @param bool $read_only Whether to grant read-only access.
	 * @return string[]
	 */
	private static function scopes_for( bool $read_only ): array {
		return $read_only ? [ 'read' ] : self::DEFAULT_SCOPES;
	}

	/**
	 * Mint a 32-byte random token (64 hex chars).
	 *
	 * @return string
	 */
	private static function mint_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
