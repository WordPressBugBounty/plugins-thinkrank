<?php
/**
 * MCP pairing lifecycle — mint / rotate / revoke the per-site connection token.
 *
 * The primary way an AI assistant connects to ThinkRank: an admin clicks
 * Connect, the plugin mints a 32-byte secret, and the user pastes either the
 * single connect URL (token embedded in the path) or the endpoint + Bearer
 * token into their AI client. The token is validated directly by Mcp_Server —
 * no hosted infrastructure is involved.
 *
 * State is stored in the `thinkrank_mcp_pairing` option:
 *   {
 *     site_token:   string (the secret the client presents),
 *     connected:    bool,
 *     connected_at: int (unix ts),
 *     scopes:       string[] (e.g. ['read','write']),
 *     user_id:      int (admin who minted the token; MCP calls run as them)
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
	 * Path segment of the pretty per-site endpoint.
	 */
	public const SITE_ENDPOINT_PATH = 'thinkrank/mcp';

	/**
	 * Default scopes granted on connect.
	 */
	private const DEFAULT_SCOPES = [ 'read', 'write' ];

	/**
	 * Throttle window (seconds) for last-used writes — one option write per
	 * minute at most, so a busy client can't hammer the option on every call.
	 */
	private const LAST_USED_THROTTLE = 60;

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
	 * The SINGLE URL the user pastes into their AI client — the pretty
	 * endpoint with the connection token embedded as a path segment.
	 * Empty string when not connected.
	 *
	 * @return string
	 */
	public static function connect_url(): string {
		$token = self::site_token();
		if ( '' === $token ) {
			return '';
		}
		return self::site_endpoint() . '/' . $token;
	}

	/**
	 * Current pairing state, defaults merged.
	 *
	 * @return array{site_token:string,token_hash:string,token_sealed:bool,connected:bool,connected_at:int,scopes:string[],user_id:int,last_used:int}
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
			'last_used'    => isset( $stored['last_used'] ) ? (int) $stored['last_used'] : 0,
		];
	}

	/**
	 * Record that the static token was just used to authenticate an MCP call.
	 * Throttled to at most one option write per minute so a busy client can't
	 * turn every request into a database write. No-op when not connected.
	 *
	 * @return void
	 */
	public static function touch_last_used(): void {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored['site_token'] ) ) {
			return;
		}
		$now  = time();
		$last = isset( $stored['last_used'] ) ? (int) $stored['last_used'] : 0;
		if ( $now - $last < self::LAST_USED_THROTTLE ) {
			return;
		}
		$stored['last_used'] = $now;
		update_option( self::OPTION, $stored, false );
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
	 * @since 2.0.1
	 *
	 * @param string $token Raw token, already verified.
	 * @return void
	 */
	private static function upgrade_legacy_storage( string $token ): void {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$stored['site_token'] = Secret_At_Rest::encrypt( $token );
		$stored['token_hash'] = self::hash( $token );

		update_option( self::OPTION, $stored, false );
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
	 * Whether the active connection is limited to read-only tools.
	 *
	 * @return bool
	 */
	public static function is_read_only(): bool {
		$scopes = self::state()['scopes'];
		return ! in_array( 'write', $scopes, true );
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
			'connect_url'       => self::connect_url(),
			'mcp_endpoint'      => self::site_endpoint(),
			'mcp_endpoint_rest' => self::site_endpoint_fallback(),
			'connected_at'      => $state['connected_at'],
			'last_used'         => $state['last_used'],
			'scopes'            => $state['scopes'],
			'read_only'         => self::is_read_only(),
			// Ready-to-paste connection recipes (header-based — token stays out
			// of the URL, so it can't leak into server/proxy logs).
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

		update_option(
			self::OPTION,
			[
				'site_token'   => Secret_At_Rest::encrypt( $token ),
				'token_hash'   => self::hash( $token ),
				'connected'    => true,
				'connected_at' => $existing ? $state['connected_at'] : time(),
				'scopes'       => $scopes,
				'user_id'      => $existing && $state['user_id'] ? $state['user_id'] : get_current_user_id(),
			],
			false
		);

		return self::public_status();
	}

	/**
	 * Rotate — mint a BRAND-NEW token, invalidating the previous one
	 * immediately. The leaked-token remedy. Optionally flips read-only.
	 *
	 * @param bool|null $read_only null = keep current scopes; true/false = set.
	 * @return array<string,mixed> Public status with the fresh token.
	 */
	public static function rotate( ?bool $read_only = null ): array {
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
			],
			false
		);

		return self::public_status();
	}

	/**
	 * Disconnect — revoke the connection token AND every OAuth grant, so
	 * Disconnect is a single kill switch for ALL MCP access.
	 *
	 * @return array<string,mixed> Public status after disconnect.
	 */
	public static function disconnect(): array {
		delete_option( self::OPTION );
		Mcp_OAuth::revoke_all();

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
