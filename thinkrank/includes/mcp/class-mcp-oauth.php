<?php
/**
 * MCP OAuth 2.1 authorization server — the "paste a URL only" connect path.
 *
 * The pairing token (Mcp_Pairing) covers clients that accept a pasted Bearer
 * token; this class covers spec-compliant MCP clients (e.g. the claude.ai
 * remote-connector flow) that take only the server URL and run the OAuth 2.1
 * authorization-code + PKCE flow themselves.
 *
 * Flow: unauthenticated MCP call → 401 + WWW-Authenticate (Mcp_Server) →
 * client fetches /.well-known/oauth-protected-resource + oauth-authorization-
 * server → dynamic registration (RFC 7591) → /authorize (admin consent +
 * PKCE) → /token (code + verifier → access + refresh) → MCP calls with
 * `Authorization: Bearer <access>` validated by validate_token().
 *
 * Security contract:
 *   - PKCE S256 REQUIRED (OAuth 2.1 public clients); codes are single-use,
 *     60 s TTL, bound to client_id + redirect_uri + challenge.
 *   - /authorize gates on manage_options — only an admin can grant access.
 *   - Authorization codes and access/refresh tokens stored only as SHA-256
 *     hashes; the raw value exists solely in the response that hands it out.
 *     Constant-time comparison.
 *   - Tokens carry the read/write scope model; a read-only grant refuses
 *     every write tool, exactly like a read-only pairing token.
 *
 * State lives in the `thinkrank_mcp_oauth` option (clients, codes, tokens,
 * refresh — keyed by id or sha256 of the secret); expired entries are pruned
 * lazily on every read.
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Minimal OAuth 2.1 authorization server for the ThinkRank MCP endpoint.
 */
final class Mcp_OAuth {

	/**
	 * Option key holding all OAuth server state.
	 */
	public const OPTION = 'thinkrank_mcp_oauth';

	/**
	 * Per-client "last used" stamps, kept OUT of self::OPTION.
	 *
	 * Every authenticated MCP call used to stamp this inside the credential
	 * option, which meant ordinary tool traffic did a read-modify-write of the
	 * whole client/code/token/refresh store. A tool call overlapping a token
	 * refresh could write back its stale snapshot and erase a token the server
	 * had just minted — the client then holds an access token the server has
	 * no record of, and every later call 401s (#485).
	 *
	 * A cosmetic timestamp has no business sharing a store with credentials,
	 * so it lives in its own option. Losing a race here costs one stamp.
	 *
	 * @since 2.1.0
	 */
	public const LAST_USED_OPTION = 'thinkrank_mcp_oauth_last_used';

	/**
	 * Authorization-code lifetime (seconds). Deliberately short.
	 */
	private const CODE_TTL = 60;

	/**
	 * Access-token lifetime (seconds) — 1 hour, refreshable.
	 */
	private const ACCESS_TTL = 3600;

	/**
	 * Refresh-token lifetime (seconds) — 30 days.
	 */
	private const REFRESH_TTL = 2592000;

	/**
	 * Scopes we advertise + honor. `mcp` is the umbrella scope MCP clients request.
	 */
	private const SUPPORTED_SCOPES = [ 'mcp', 'read', 'write' ];

	/**
	 * Throttle window (seconds) for per-client last-used writes — at most one
	 * option write per minute per client, so a busy connector can't turn every
	 * MCP call into a database write.
	 */
	private const LAST_USED_THROTTLE = 60;

	/**
	 * Seconds to wait for the advisory lock before giving up and proceeding
	 * unguarded. Short: these are user-facing OAuth endpoints, and waiting is
	 * worse than the small race we are narrowing.
	 *
	 * @since 2.1.0
	 */
	private const LOCK_TIMEOUT = 3;

	/**
	 * Nesting depth of mutate() on this request, so a mutation that calls
	 * another (grant -> mint) releases the lock once, at the outermost exit.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	private static int $lock_depth = 0;

	/**
	 * How many registered clients to keep. RFC 7591 registration is open by
	 * necessity — a client must register BEFORE it can hold any credential —
	 * so without a cap anyone on the internet can grow this option without
	 * bound, and every state() read pays for it. Clients holding a live token
	 * are never evicted, so the cap only ever discards abandoned registrations.
	 */
	private const MAX_CLIENTS = 50;

	/**
	 * How long an unused client registration survives (seconds). A client that
	 * registers and never completes the flow is abandoned; real ones exchange
	 * a code within a minute.
	 */
	private const CLIENT_TTL = 86400; // 24 hours.

	// -- URLs ------------------------------------------------------------

	/**
	 * The OAuth issuer identifier. Path-based (RFC 8414 §2 allows an issuer
	 * with a path component): using the MCP endpoint URL itself means clients
	 * derive the path-suffixed well-known URLs
	 * (/.well-known/oauth-authorization-server/thinkrank/mcp), which stay
	 * specific to ThinkRank even when another plugin runs its own MCP OAuth
	 * server at the same site root.
	 *
	 * @return string
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url( '/thinkrank/mcp' ) );
	}

	/**
	 * The protected resource identifier — the MCP endpoint URL.
	 *
	 * @return string
	 */
	public static function resource(): string {
		return Mcp_Pairing::site_endpoint();
	}

	/**
	 * The browser-facing authorize page. Served OUTSIDE the REST API (via a
	 * rewrite rule) so standard cookie auth works after the wp-login
	 * round-trip — a REST route would see the cookie without a nonce and
	 * treat the admin as logged-out, looping back to login.
	 *
	 * @return string
	 */
	public static function authorize_url(): string {
		return home_url( '/thinkrank/authorize' );
	}

	/**
	 * The token endpoint URL.
	 *
	 * @return string
	 */
	public static function token_url(): string {
		return rest_url( 'thinkrank/v1/mcp/oauth/token' );
	}

	/**
	 * The dynamic client registration endpoint URL.
	 *
	 * @return string
	 */
	public static function register_url(): string {
		return rest_url( 'thinkrank/v1/mcp/oauth/register' );
	}

	/**
	 * The protected-resource metadata URL the 401 challenge advertises.
	 *
	 * REST-served, NOT the RFC 9728 path-insert form. The path-insert URL
	 * lives under the site root's /.well-known/ directory, and some hosts
	 * (SiteGround shared hosting confirmed, see #374) resolve that directory
	 * at their Nginx edge as physical files — the request 404s before
	 * WordPress runs, and the connecting client reports "server does not
	 * implement OAuth" on its very first fetch. The challenge parameter is an
	 * explicit pointer (that is what it exists for), so pointing it at a
	 * /wp-json/ URL is spec-clean and reaches WordPress on every host and
	 * permalink structure. The well-known variants stay served for clients
	 * that ignore the pointer and derive the URL themselves.
	 *
	 * @return string
	 */
	public static function resource_metadata_url(): string {
		/**
		 * Filter the resource_metadata URL advertised in the WWW-Authenticate
		 * challenge, for hosts where neither the REST route nor the
		 * /.well-known/ forms are reachable and the metadata must be served
		 * from somewhere custom (a CDN, a static file, another domain).
		 *
		 * @since 1.32.0
		 *
		 * @param string $url The advertised protected-resource metadata URL.
		 */
		return apply_filters(
			'thinkrank_mcp_resource_metadata_url',
			rest_url( 'thinkrank/v1/mcp/oauth/protected-resource' )
		);
	}

	// -- Discovery documents (RFC 8414 / RFC 9728) -----------------------

	/**
	 * RFC 9728 protected-resource metadata — tells the client which
	 * authorization server(s) protect the MCP endpoint (this site).
	 *
	 * @return array<string,mixed>
	 */
	public static function protected_resource_metadata(): array {
		return [
			'resource'                 => self::resource(),
			'authorization_servers'    => [ self::issuer() ],
			'scopes_supported'         => self::SUPPORTED_SCOPES,
			'bearer_methods_supported' => [ 'header' ],
		];
	}

	/**
	 * RFC 8414 authorization-server metadata — the endpoint map + the
	 * capabilities we actually implement.
	 *
	 * @return array<string,mixed>
	 */
	public static function authorization_server_metadata(): array {
		return [
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => self::token_url(),
			'registration_endpoint'                 => self::register_url(),
			'scopes_supported'                      => self::SUPPORTED_SCOPES,
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'      => [ 'S256' ],
			'token_endpoint_auth_methods_supported' => [ 'none' ],
		];
	}

	// -- Dynamic client registration (RFC 7591) --------------------------

	/**
	 * Register a public client. We accept the client's redirect_uris and
	 * mint a client_id (no secret — public clients rely on PKCE).
	 *
	 * @param array<string,mixed> $body Parsed JSON registration request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function register_client( array $body ) {
		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] )
			? array_values( array_filter( array_map( 'strval', $body['redirect_uris'] ), [ self::class, 'is_valid_redirect_uri' ] ) )
			: [];

		if ( empty( $redirect_uris ) ) {
			return new \WP_Error(
				'invalid_redirect_uri',
				__( 'At least one valid redirect_uri is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$name      = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : 'MCP Client';
		$client_id = 'trk_' . bin2hex( random_bytes( 16 ) );

		self::mutate(
			static function ( array &$state ) use ( $client_id, $redirect_uris, $name ): void {
				$state['clients'][ $client_id ] = [
					'redirect_uris' => $redirect_uris,
					'name'          => $name,
					'created'       => time(),
				];
				$state['clients']               = self::prune_clients( $state );
			}
		);

		return [
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'redirect_uris'              => $redirect_uris,
			'client_name'                => $name,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => [ 'authorization_code', 'refresh_token' ],
			'response_types'             => [ 'code' ],
		];
	}

	// -- Authorization endpoint ------------------------------------------

	/**
	 * Validate an /authorize request's parameters WITHOUT issuing anything.
	 * Returns a sanitized param bag on success, or WP_Error on a protocol
	 * violation. The caller decides how to surface it (redirect vs error
	 * page) based on whether redirect_uri is trustworthy.
	 *
	 * @param array<string,string> $params Query params.
	 * @return array<string,string>|\WP_Error
	 */
	public static function validate_authorize_request( array $params ) {
		$client_id     = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$response_type = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';
		$challenge     = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';
		$method        = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
		$scope         = isset( $params['scope'] ) ? (string) $params['scope'] : 'mcp';
		$state         = isset( $params['state'] ) ? (string) $params['state'] : '';

		$client = self::client( $client_id );
		if ( null === $client ) {
			return new \WP_Error( 'invalid_client', __( 'Unknown client_id.', 'thinkrank' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			// redirect_uri mismatch must NOT redirect (open-redirect guard).
			return new \WP_Error( 'invalid_redirect_uri', __( 'redirect_uri does not match a registered value.', 'thinkrank' ), [ 'status' => 400 ] );
		}
		if ( 'code' !== $response_type ) {
			return new \WP_Error(
				'unsupported_response_type',
				__( 'Only response_type=code is supported.', 'thinkrank' ),
				[
					'status'       => 400,
					'redirectable' => true,
				]
			);
		}
		// OAuth 2.1: PKCE S256 is mandatory for public clients.
		if ( 'S256' !== $method || '' === $challenge ) {
			return new \WP_Error(
				'invalid_request',
				__( 'PKCE with code_challenge_method=S256 is required.', 'thinkrank' ),
				[
					'status'       => 400,
					'redirectable' => true,
				]
			);
		}
		// The challenge reaches us verbatim now (#487), so it is checked
		// against its own character set rather than cleaned as display text.
		// RFC 7636 unreserved base64url; an S256 challenge is 43 characters,
		// the wider bound leaves room for a client that pads.
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $challenge ) ) {
			return new \WP_Error(
				'invalid_request',
				__( 'code_challenge is not a valid S256 challenge.', 'thinkrank' ),
				[
					'status'       => 400,
					'redirectable' => true,
				]
			);
		}

		return [
			'client_id'      => $client_id,
			'client_name'    => $client['name'],
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => $challenge,
			'scope'          => self::normalize_scope( $scope ),
			'state'          => $state,
		];
	}

	/**
	 * Issue an authorization code after the admin approves consent. Binds
	 * the code to the client, redirect_uri, PKCE challenge, granted scope,
	 * and the approving user. Single-use, 60 s TTL.
	 *
	 * @param array<string,string> $req     Output of validate_authorize_request().
	 * @param int                  $user_id Approving admin user id.
	 * @return string The authorization code.
	 */
	public static function issue_code( array $req, int $user_id ): string {
		$code = bin2hex( random_bytes( 32 ) );

		// Keyed by hash, like access and refresh tokens. The authorization
		// code is a bearer credential too, and this file's own contract says
		// the raw value exists solely in the response that hands it out — the
		// code was the one exception (#488). The exposure is small (60 s TTL,
		// single use, bound to client_id + redirect_uri + PKCE) but #396 made
		// exactly that argument about the pairing token and still hashed it.
		self::mutate(
			static function ( array &$state ) use ( $code, $req, $user_id ): void {
				$state['codes'][ self::hash( $code ) ] = [
					'client_id'    => $req['client_id'],
					'redirect_uri' => $req['redirect_uri'],
					'challenge'    => $req['code_challenge'],
					'scope'        => $req['scope'],
					'user_id'      => $user_id,
					'expires'      => time() + self::CODE_TTL,
				];
			}
		);

		return $code;
	}

	// -- Token endpoint --------------------------------------------------

	/**
	 * Exchange an authorization code (+ PKCE verifier) for tokens, or a
	 * refresh token for a fresh access token.
	 *
	 * @param array<string,string> $body POST body params.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function exchange_token( array $body ) {
		$grant = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			return self::grant_authorization_code( $body );
		}
		if ( 'refresh_token' === $grant ) {
			return self::grant_refresh_token( $body );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Unsupported grant_type.' );
	}

	/**
	 * authorization_code grant: verify the code + PKCE, mint tokens.
	 *
	 * @param array<string,string> $body POST body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function grant_authorization_code( array $body ) {
		$code         = isset( $body['code'] ) ? (string) $body['code'] : '';
		$client_id    = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		$redirect_uri = isset( $body['redirect_uri'] ) ? (string) $body['redirect_uri'] : '';
		$verifier     = isset( $body['code_verifier'] ) ? (string) $body['code_verifier'] : '';

		// Claim the code and remove it in one guarded read-modify-write.
		// Single-use has to mean single-use: looking it up, saving the removal,
		// and letting a concurrent writer restore its pre-removal snapshot put
		// a spent code back in the store (#485). Looked up by hash, because
		// that is how issue_code() stores it (#488).
		$entry = self::mutate(
			static function ( array &$state ) use ( $code ) {
				$chash = self::hash( $code );

				if ( '' === $code || ! isset( $state['codes'][ $chash ] ) ) {
					return null;
				}

				$claimed = $state['codes'][ $chash ];

				// Removed whether or not verification below passes.
				unset( $state['codes'][ $chash ] );

				return $claimed;
			}
		);

		if ( null === $entry ) {
			return self::oauth_error( 'invalid_grant', 'Unknown or expired authorization code.' );
		}

		if ( $entry['expires'] < time() ) {
			return self::oauth_error( 'invalid_grant', 'Authorization code expired.' );
		}
		if ( ! hash_equals( (string) $entry['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id mismatch.' );
		}
		if ( ! hash_equals( (string) $entry['redirect_uri'], $redirect_uri ) ) {
			return self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.' );
		}
		// PKCE S256: BASE64URL(SHA256(verifier)) must equal the stored challenge.
		if ( '' === $verifier || ! hash_equals( (string) $entry['challenge'], self::s256( $verifier ) ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		return self::mint_tokens( (string) $entry['client_id'], (string) $entry['scope'], (int) $entry['user_id'] );
	}

	/**
	 * refresh_token grant: rotate the refresh token, issue a fresh access
	 * token. The old refresh + its access token are revoked.
	 *
	 * @param array<string,string> $body POST body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function grant_refresh_token( array $body ) {
		$refresh   = isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '';
		$client_id = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';

		$rhash = self::hash( $refresh );

		// Look up and rotate under one guard. A mismatched client_id must not
		// consume the token, so the check happens inside the mutation.
		$claim = self::mutate(
			static function ( array &$state ) use ( $refresh, $rhash, $client_id ): array {
				if ( '' === $refresh || ! isset( $state['refresh'][ $rhash ] ) ) {
					return [ 'error' => 'Unknown refresh token.' ];
				}

				$entry = $state['refresh'][ $rhash ];

				if ( '' !== $client_id && ! hash_equals( (string) $entry['client_id'], $client_id ) ) {
					return [ 'error' => 'client_id mismatch.' ];
				}

				// Rotate: drop old refresh + its access token.
				unset( $state['refresh'][ $rhash ] );
				if ( isset( $entry['access_hash'] ) ) {
					unset( $state['tokens'][ $entry['access_hash'] ] );
				}

				return [ 'entry' => $entry ];
			}
		);

		if ( isset( $claim['error'] ) ) {
			return self::oauth_error( 'invalid_grant', (string) $claim['error'] );
		}

		$entry = $claim['entry'];

		return self::mint_tokens( (string) $entry['client_id'], (string) $entry['scope'], (int) $entry['user_id'] );
	}

	/**
	 * Mint an access + refresh token pair, store them hashed, and return
	 * the RFC 6749 token response with the raw values.
	 *
	 * @param string $client_id Client id.
	 * @param string $scope     Granted scope string.
	 * @param int    $user_id   Resource-owner user id.
	 * @return array<string,mixed>
	 */
	private static function mint_tokens( string $client_id, string $scope, int $user_id ): array {
		$access  = bin2hex( random_bytes( 32 ) );
		$refresh = bin2hex( random_bytes( 32 ) );
		$ahash   = self::hash( $access );
		$rhash   = self::hash( $refresh );

		self::mutate(
			static function ( array &$state ) use ( $ahash, $rhash, $client_id, $scope, $user_id ): void {
				$state['tokens'][ $ahash ]  = [
					'client_id' => $client_id,
					'scope'     => $scope,
					'user_id'   => $user_id,
					'expires'   => time() + self::ACCESS_TTL,
					'refresh'   => $rhash,
				];
				$state['refresh'][ $rhash ] = [
					'access_hash' => $ahash,
					'client_id'   => $client_id,
					'scope'       => $scope,
					'user_id'     => $user_id,
					'expires'     => time() + self::REFRESH_TTL,
				];
			}
		);

		return [
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		];
	}

	// -- Access-token validation (called by Mcp_Server) ------------------

	/**
	 * Validate a bearer access token presented to the MCP endpoint.
	 * Returns the token's grant record (scope, user_id, client_id) when
	 * valid + unexpired, or null. Constant-time via hashed lookup.
	 *
	 * @param string $token Raw access token from the Authorization header.
	 * @return array{client_id:string,scope:string,user_id:int}|null
	 */
	public static function validate_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		$state = self::state();
		$hash  = self::hash( $token );
		if ( ! isset( $state['tokens'][ $hash ] ) ) {
			return null;
		}
		$entry = $state['tokens'][ $hash ];
		if ( (int) $entry['expires'] < time() ) {
			return null;
		}

		// Record activity against the owning client so the "Connected AI apps"
		// list can show a last-used date. Throttled, and written to its own
		// option: this runs on every authenticated MCP call, and writing it
		// back into the credential store meant ordinary tool traffic could
		// erase a token minted by an overlapping refresh (#485).
		$client_id = (string) $entry['client_id'];
		if ( isset( $state['clients'][ $client_id ] ) && is_array( $state['clients'][ $client_id ] ) ) {
			self::touch_last_used( $client_id, array_keys( $state['clients'] ) );
		}

		return [
			'client_id' => $client_id,
			'scope'     => (string) $entry['scope'],
			'user_id'   => (int) $entry['user_id'],
		];
	}

	/**
	 * Whether a granted scope string is read-only. `mcp` is the umbrella
	 * scope that grants read+write, so only a grant that carries NEITHER
	 * `write` NOR `mcp` — i.e. `read` alone — is read-only.
	 *
	 * @param string $scope Space-separated scope string.
	 * @return bool
	 */
	public static function scope_is_read_only( string $scope ): bool {
		$parts = preg_split( '/\s+/', trim( $scope ) );
		$parts = is_array( $parts ) ? $parts : [];
		return ! in_array( 'write', $parts, true ) && ! in_array( 'mcp', $parts, true );
	}

	/**
	 * Revoke every OAuth token + client (used by disconnect).
	 *
	 * @return void
	 */
	public static function revoke_all(): void {
		delete_option( self::OPTION );
		delete_option( self::LAST_USED_OPTION );
	}

	/**
	 * The OAuth clients currently holding a live grant, for the "Connected AI
	 * apps" list. A client counts as connected while it holds an unexpired
	 * refresh token (the durable 30-day grant) or access token; a client that
	 * only registered but never completed consent is excluded. One entry per
	 * client_id, newest connection first.
	 *
	 * @return array<int,array{client_id:string,name:string,scope:string,read_only:bool,user_id:int,connected_at:int,last_used:int}>
	 */
	public static function connected_apps(): array {
		$state     = self::state();
		$last_used = self::last_used_map();

		// Collect the scope + approving user per active client. Refresh tokens
		// are the durable grant, so prefer them; fall back to access tokens.
		$active = [];
		foreach ( [ 'refresh', 'tokens' ] as $bucket ) {
			foreach ( $state[ $bucket ] as $entry ) {
				$cid = isset( $entry['client_id'] ) ? (string) $entry['client_id'] : '';
				if ( '' === $cid || isset( $active[ $cid ] ) ) {
					continue;
				}
				$active[ $cid ] = [
					'scope'   => isset( $entry['scope'] ) ? (string) $entry['scope'] : 'mcp',
					'user_id' => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0,
				];
			}
		}

		$apps = [];
		foreach ( $active as $cid => $info ) {
			$client = isset( $state['clients'][ $cid ] ) && is_array( $state['clients'][ $cid ] ) ? $state['clients'][ $cid ] : [];
			$apps[] = [
				'client_id'    => $cid,
				'name'         => isset( $client['name'] ) ? (string) $client['name'] : __( 'MCP Client', 'thinkrank' ),
				'scope'        => $info['scope'],
				'read_only'    => self::scope_is_read_only( $info['scope'] ),
				'user_id'      => $info['user_id'],
				'connected_at' => isset( $client['created'] ) ? (int) $client['created'] : 0,
				// Legacy fallback: stamps written before #485 still sit on the
				// client record, so an existing install keeps its dates.
				'last_used'    => isset( $last_used[ $cid ] )
					? (int) $last_used[ $cid ]
					: ( isset( $client['last_used'] ) ? (int) $client['last_used'] : 0 ),
			];
		}

		// Newest connection first.
		usort(
			$apps,
			static function ( array $a, array $b ): int {
				return $b['connected_at'] <=> $a['connected_at'];
			}
		);

		return $apps;
	}

	/**
	 * Revoke a single OAuth client's ACCESS — drops its access tokens, refresh
	 * tokens, and any pending codes, cutting that one app off immediately while
	 * leaving every other connection intact. It disappears from
	 * connected_apps() (which keys off live tokens), so the UI shows it gone.
	 *
	 * The client's dynamic registration (its client_id + redirect_uris) is
	 * intentionally KEPT: MCP clients such as ChatGPT cache the client_id from
	 * their first registration and reuse it on reconnect, hitting /authorize
	 * with that id rather than registering afresh. If we deleted the
	 * registration, that reconnect would fail with "Unknown client_id". Keeping
	 * it lets the app re-authorize — which still requires fresh admin consent
	 * (and mints brand-new tokens), so revocation loses nothing.
	 *
	 * @param string $client_id The client whose access to revoke.
	 * @return bool True if any live grant was removed.
	 */
	public static function revoke_client( string $client_id ): bool {
		if ( '' === $client_id ) {
			return false;
		}
		$removed = self::mutate(
			static function ( array &$state ) use ( $client_id ): bool {
				$found = false;

				foreach ( [ 'tokens', 'refresh', 'codes' ] as $bucket ) {
					foreach ( $state[ $bucket ] as $key => $entry ) {
						if ( isset( $entry['client_id'] ) && (string) $entry['client_id'] === $client_id ) {
							unset( $state[ $bucket ][ $key ] );
							$found = true;
						}
					}
				}

				return $found;
			}
		);

		if ( $removed ) {
			$map = self::last_used_map();
			unset( $map[ $client_id ] );
			update_option( self::LAST_USED_OPTION, $map, false );
		}

		return $removed;
	}

	// -- State + helpers -------------------------------------------------

	/**
	 * Load state with defaults, pruning expired codes/tokens/refresh
	 * entries on the way out so the option can't grow unbounded.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function state(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$state = [
			'clients' => isset( $stored['clients'] ) && is_array( $stored['clients'] ) ? $stored['clients'] : [],
			'codes'   => isset( $stored['codes'] ) && is_array( $stored['codes'] ) ? $stored['codes'] : [],
			'tokens'  => isset( $stored['tokens'] ) && is_array( $stored['tokens'] ) ? $stored['tokens'] : [],
			'refresh' => isset( $stored['refresh'] ) && is_array( $stored['refresh'] ) ? $stored['refresh'] : [],
		];

		$now = time();
		foreach ( $state['codes'] as $k => $v ) {
			if ( ! isset( $v['expires'] ) || $v['expires'] < $now ) {
				unset( $state['codes'][ $k ] );
			}
		}
		foreach ( $state['tokens'] as $k => $v ) {
			if ( ! isset( $v['expires'] ) || $v['expires'] < $now ) {
				unset( $state['tokens'][ $k ] );
			}
		}
		foreach ( $state['refresh'] as $k => $v ) {
			if ( isset( $v['expires'] ) && $v['expires'] < $now ) {
				unset( $state['refresh'][ $k ] );
			}
		}
		return $state;
	}

	/**
	 * Persist state (autoload off — hot-write, request-scoped option).
	 *
	 * Private on purpose: every mutation goes through mutate(), so that the
	 * state being written was read inside the same guard.
	 *
	 * @param array<string,mixed> $state State to persist.
	 * @return void
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Read-modify-write the OAuth state under a guard, re-reading inside it.
	 *
	 * Clients, codes, access tokens and refresh tokens share one option, and
	 * every mutation used to read a snapshot at the top of the request and
	 * write the whole thing back later. Two overlapping requests therefore had
	 * one silently erase the other's work — the damaging order being a tool
	 * call writing back a pre-refresh snapshot over a token pair that had just
	 * been minted, leaving the client holding an access token the server has no
	 * record of (#485).
	 *
	 * The mutator receives the state by reference and may return a value, which
	 * is handed back to the caller — so a caller can claim-and-remove (a
	 * single-use code, a rotating refresh token) without the lookup and the
	 * removal being separate writes.
	 *
	 * @param callable $mutator function ( array &$state ): mixed
	 * @return mixed Whatever the mutator returned.
	 */
	private static function mutate( callable $mutator ) {
		$locked = self::lock();

		try {
			$state  = self::state();
			$result = $mutator( $state );
			self::save( $state );
		} finally {
			if ( $locked ) {
				self::unlock();
			}
		}

		return $result;
	}

	/**
	 * Take the cross-request advisory lock guarding self::OPTION.
	 *
	 * MySQL GET_LOCK is what WordPress gives us that actually holds ACROSS
	 * processes — wp_cache_add() is per-request without a persistent object
	 * cache, which is exactly the configuration this bug bites hardest on.
	 * The name is namespaced by database + table prefix because GET_LOCK names
	 * are server-wide and shared MySQL hosts are the common case.
	 *
	 * Best-effort by design: a host where the lock cannot be taken (SQLite
	 * drop-in, a proxy that does not support session locks, contention past
	 * the timeout) proceeds unguarded, which is exactly today's behaviour
	 * rather than a new failure.
	 *
	 * @return bool Whether the lock is held.
	 */
	private static function lock(): bool {
		global $wpdb;

		// Already inside a guarded mutation on this request (grant -> mint).
		// MySQL's lock is re-entrant per session; the depth counter is what
		// keeps the release paired with the outermost acquire.
		if ( self::$lock_depth > 0 ) {
			++self::$lock_depth;
			return true;
		}

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not cacheable data.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), self::LOCK_TIMEOUT ) );

		if ( '1' !== (string) $got ) {
			return false;
		}

		self::$lock_depth = 1;

		return true;
	}

	/**
	 * Release the advisory lock taken by lock(). Only the outermost mutation
	 * actually releases it.
	 *
	 * @return void
	 */
	private static function unlock(): void {
		global $wpdb;

		if ( self::$lock_depth <= 0 ) {
			return;
		}

		--self::$lock_depth;

		if ( self::$lock_depth > 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not cacheable data.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	/**
	 * Lock name, inside MySQL's 64-character limit and unique per install.
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		global $wpdb;

		$prefix = isset( $wpdb ) && is_object( $wpdb ) ? (string) $wpdb->prefix : '';

		return 'trk_mcp_oauth_' . md5( ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $prefix );
	}

	/**
	 * Per-client last-used stamps, client_id => unix timestamp.
	 *
	 * @return array<string,int>
	 */
	private static function last_used_map(): array {
		$stored = get_option( self::LAST_USED_OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Stamp a client as having just been used, at most once per throttle
	 * window. Writes its own option, never the credential store.
	 *
	 * @param string   $client_id   Client to stamp.
	 * @param string[] $known_clients Client ids that still exist, so the map
	 *                                cannot outgrow the store it describes.
	 * @return void
	 */
	private static function touch_last_used( string $client_id, array $known_clients ): void {
		$map  = self::last_used_map();
		$now  = time();
		$last = isset( $map[ $client_id ] ) ? (int) $map[ $client_id ] : 0;

		if ( $now - $last < self::LAST_USED_THROTTLE ) {
			return;
		}

		$map[ $client_id ] = $now;

		// Drop stamps for clients that are gone (revoked, pruned, expired).
		$known = array_flip( $known_clients );
		foreach ( array_keys( $map ) as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				unset( $map[ $id ] );
			}
		}

		update_option( self::LAST_USED_OPTION, $map, false );
	}

	/**
	 * Look up a registered client.
	 *
	 * @param string $client_id Client id.
	 * @return array{redirect_uris:string[],name:string,created:int}|null
	 */
	private static function client( string $client_id ): ?array {
		if ( '' === $client_id ) {
			return null;
		}
		$clients = self::state()['clients'];
		if ( ! isset( $clients[ $client_id ] ) || ! is_array( $clients[ $client_id ] ) ) {
			return null;
		}
		$c = $clients[ $client_id ];
		return [
			'redirect_uris' => isset( $c['redirect_uris'] ) && is_array( $c['redirect_uris'] ) ? array_map( 'strval', $c['redirect_uris'] ) : [],
			'name'          => isset( $c['name'] ) ? (string) $c['name'] : 'MCP Client',
			'created'       => isset( $c['created'] ) ? (int) $c['created'] : 0,
		];
	}

	/**
	 * Bound the registered-client list. Drops abandoned registrations past
	 * CLIENT_TTL first, then — if still over MAX_CLIENTS — the oldest of what
	 * is left. A client referenced by a live code, access token, or refresh
	 * token is NEVER dropped: evicting one would break a working connection,
	 * so a site legitimately holding more than MAX_CLIENTS live grants keeps
	 * them all and the cap simply stops applying to that remainder.
	 *
	 * @param array<string,array<string,mixed>> $state Full state (clients + grant buckets).
	 * @return array<string,array<string,mixed>> The clients array to store.
	 */
	private static function prune_clients( array $state ): array {
		$clients = $state['clients'];

		$in_use = [];
		foreach ( [ 'codes', 'tokens', 'refresh' ] as $bucket ) {
			foreach ( $state[ $bucket ] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['client_id'] ) ) {
					$in_use[ (string) $entry['client_id'] ] = true;
				}
			}
		}

		$now = time();
		foreach ( $clients as $id => $client ) {
			$created = isset( $client['created'] ) ? (int) $client['created'] : 0;
			if ( ! isset( $in_use[ $id ] ) && $created + self::CLIENT_TTL < $now ) {
				unset( $clients[ $id ] );
			}
		}

		if ( count( $clients ) <= self::MAX_CLIENTS ) {
			return $clients;
		}

		// Still over the cap — evict the oldest unused registrations.
		$evictable = array_filter(
			$clients,
			static function ( $id ) use ( $in_use ) {
				return ! isset( $in_use[ $id ] );
			},
			ARRAY_FILTER_USE_KEY
		);
		uasort(
			$evictable,
			static function ( $a, $b ) {
				return ( isset( $a['created'] ) ? (int) $a['created'] : 0 ) <=> ( isset( $b['created'] ) ? (int) $b['created'] : 0 );
			}
		);
		foreach ( array_keys( $evictable ) as $id ) {
			if ( count( $clients ) <= self::MAX_CLIENTS ) {
				break;
			}
			unset( $clients[ $id ] );
		}

		return $clients;
	}

	/**
	 * SHA-256 hash used to store tokens at rest.
	 *
	 * @param string $value Raw secret.
	 * @return string
	 */
	private static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}

	/**
	 * BASE64URL(SHA256(verifier)) — the PKCE S256 transformation.
	 *
	 * @param string $verifier PKCE code verifier.
	 * @return string
	 */
	private static function s256( string $verifier ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of the PKCE challenge, mandated by RFC 7636.
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Constrain a requested scope to what we support. Defaults to `mcp`
	 * (read+write umbrella).
	 *
	 * @param string $requested Requested scope string.
	 * @return string
	 */
	private static function normalize_scope( string $requested ): string {
		$parts = preg_split( '/\s+/', trim( $requested ) );
		$parts = is_array( $parts ) ? $parts : [];
		$parts = array_values( array_intersect( $parts, self::SUPPORTED_SCOPES ) );
		if ( empty( $parts ) ) {
			return 'mcp';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Whether a redirect_uri is structurally acceptable (http(s) or a
	 * native-client custom scheme).
	 *
	 * @param string $uri Candidate redirect URI.
	 * @return bool
	 */
	private static function is_valid_redirect_uri( string $uri ): bool {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return false;
		}
		return (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $uri );
	}

	/**
	 * Build a WP_Error whose data carries an OAuth 2.0 `error` code so the
	 * token route can render the RFC 6749 error body.
	 *
	 * @param string $code    OAuth error code (invalid_grant, ...).
	 * @param string $message Human-readable description.
	 * @return \WP_Error
	 */
	private static function oauth_error( string $code, string $message ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'status'            => 400,
				'error'             => $code,
				'error_description' => $message,
			]
		);
	}
}
