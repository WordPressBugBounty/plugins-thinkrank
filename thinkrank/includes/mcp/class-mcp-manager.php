<?php
/**
 * MCP manager — the site side of ThinkRank's MCP integration.
 *
 * The plugin speaks the MCP protocol DIRECTLY at this site's own URL. The
 * user pastes their own site's MCP endpoint + connection token into their AI
 * client — or, for OAuth-capable clients (claude.ai remote connectors), just
 * the URL:
 *
 *     https://thissite.com/thinkrank/mcp              (pretty, via rewrite)
 *     https://thissite.com/wp-json/thinkrank/v1/mcp   (always-on fallback)
 *
 * The MCP JSON-RPC handling lives in Mcp_Server; the tool surface is the
 * abilities registry (Mcp_Tools). Auth is the per-site connection token
 * (Mcp_Pairing) or an OAuth 2.1 access token (Mcp_OAuth).
 *
 * Admin-only management routes (manage_options) drive the MCP page:
 * /mcp/connection, /mcp/connect, /mcp/rotate, /mcp/disconnect.
 *
 * The token-only MCP + OAuth routes are exempted from Role_Manager's
 * namespace-wide capability gate (they authenticate inside the handler); the
 * exemption lives in Role_Manager::gate_rest().
 *
 * A single admin toggle (`enable_mcp`) is the master switch: when off, the
 * MCP endpoint, discovery documents, and OAuth endpoints all refuse to serve.
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the MCP endpoint, OAuth discovery/authorize/token surface, and
 * the admin management routes.
 */
final class Mcp_Manager {

	/**
	 * REST namespace shared with the rest of the plugin.
	 */
	private const NS = 'thinkrank/v1';

	/**
	 * Query var flagging a pretty /thinkrank/mcp request.
	 */
	private const QUERY_VAR = 'thinkrank_mcp';

	/**
	 * Query var carrying the token when embedded in the URL path.
	 */
	private const TOKEN_QUERY_VAR = 'thinkrank_mcp_token';

	/**
	 * Query var flagging a /.well-known/ OAuth discovery request.
	 */
	private const WELLKNOWN_QUERY_VAR = 'thinkrank_mcp_wellknown';

	/**
	 * Query var flagging the browser-facing OAuth authorize page. This is
	 * served OUTSIDE the REST API on purpose: a REST route only honors cookie
	 * auth when a REST nonce accompanies it, but a browser arriving from
	 * wp-login carries the cookie with NO nonce — so is_user_logged_in()
	 * would be false there and the consent screen would loop back to login
	 * forever. A normal front-end URL (rewrite + parse_request) sees standard
	 * cookie auth, so the logged-in admin check works.
	 */
	private const AUTHORIZE_QUERY_VAR = 'thinkrank_mcp_authorize';

	/**
	 * Initialize (called by the plugin's component container).
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest' ] );

		// Pretty per-site endpoint: /thinkrank/mcp → MCP JSON-RPC handler.
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'parse_request', [ $this, 'maybe_handle_pretty_endpoint' ] );

		// The one broken state the server can't see from inside a request:
		// MCP enabled but the bundled Abilities runtime absent. Everything
		// else still works — OAuth discovers, tokens mint, clients connect —
		// and tools/list is an empty array served as success. Three layers
		// each "no-op gracefully" (the is_readable() require, the registrar,
		// the tool registry) and composed they manufacture a connector that
		// connects and offers nothing, with no signal anywhere. Say it loudly
		// where an admin will look.
		add_action( 'admin_notices', [ $this, 'warn_when_runtime_missing' ] );
	}

	/**
	 * Admin notice when MCP is enabled but the Abilities runtime is missing.
	 *
	 * That combination almost always means an incomplete package — a source
	 * archive or a zip built without `dependencies/vendor/` (the bundled
	 * Abilities API + MCP adapter). Shown to admins on every screen: the fix
	 * is reinstalling the plugin, and a user mid-support-ticket needs to see
	 * it without knowing which screen to visit.
	 *
	 * @return void
	 */
	public function warn_when_runtime_missing(): void {
		if ( ! self::is_enabled() || function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'ThinkRank MCP: AI assistants will connect but see no tools.', 'thinkrank' ),
			esc_html__( 'MCP access is enabled, but the bundled Abilities runtime (dependencies/vendor) is missing from this installation — usually a plugin package built without it. Reinstall ThinkRank from wordpress.org or an official build; until then, connected AI clients get an empty tool list.', 'thinkrank' )
		);
	}

	/**
	 * Whether the MCP integration is enabled via the admin setting.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) Settings::instance()->get( 'enable_mcp', false );
	}

	// -- Pretty endpoint: /thinkrank/mcp --

	/**
	 * Register rewrite rules for the MCP endpoint, OAuth discovery documents,
	 * and the browser-facing authorize page.
	 *
	 * @return void
	 */
	public function add_rewrite(): void {
		// Token-in-URL form: /thinkrank/mcp/<token> — a single string the user
		// pastes into their AI client (no separate token field). The bare
		// /thinkrank/mcp still works with a Bearer token.
		add_rewrite_rule(
			'^thinkrank/mcp/([a-f0-9]{64})/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::TOKEN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_rule( '^thinkrank/mcp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		// OAuth discovery documents. RFC 9728 §3.1 / RFC 8414 §3.1 place the
		// `.well-known` segment BEFORE the resource path, so our resource at
		// /thinkrank/mcp is discovered at the path-suffixed form:
		//   /.well-known/oauth-protected-resource/thinkrank/mcp
		//   /.well-known/oauth-authorization-server/thinkrank/mcp
		// The OAuth issuer is the path-based identifier home_url('/thinkrank/mcp')
		// (see Mcp_OAuth::issuer), so spec-compliant clients derive exactly
		// these URLs — and the rule stays specific to OUR path. That matters
		// for coexistence: another plugin serving its own MCP OAuth surface
		// (e.g. xSpeed) claims the generic `(?:/.*)?` root rule, and rewrite
		// rules are keyed by regex, so a shared broad rule would be silently
		// overwritten by whichever plugin registers last.
		add_rewrite_rule(
			'^\.well-known/oauth-(protected-resource|authorization-server)/thinkrank/mcp/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		// Root-form fallback for clients that only try the bare well-known
		// URL. Harmless when another plugin also registers this exact regex —
		// last registrant wins, and our clients use the path-suffixed form.
		add_rewrite_rule(
			'^\.well-known/oauth-(protected-resource|authorization-server)(?:/.*)?/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		// Suffix form: <issuer>/.well-known/... . RFC 8414 specifies the
		// path-INSERT form above, but the older OpenID Connect Discovery
		// convention appends instead, and clients built on an OIDC library
		// try that shape first (sometimes only that shape). Serving both
		// costs two rules and removes a whole class of "server does not
		// implement OAuth" failures from clients that never fall back.
		add_rewrite_rule(
			'^thinkrank/mcp/\.well-known/oauth-(protected-resource|authorization-server)/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^thinkrank/mcp/\.well-known/openid-configuration/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=authorization-server',
			'top'
		);

		// Browser-facing OAuth consent page — served OUTSIDE REST so cookie
		// auth (is_user_logged_in) works after the wp-login round-trip.
		add_rewrite_rule( '^thinkrank/authorize/?$', 'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1', 'top' );

		// Self-heal: flush once if ANY of our rules is missing from the stored
		// rewrite table, so the endpoints work without a manual permalink
		// re-save (and newly added rules trigger a re-flush on upgrade).
		$expected = [
			'^thinkrank/mcp/([a-f0-9]{64})/?$',
			'^thinkrank/mcp/?$',
			'^\.well-known/oauth-(protected-resource|authorization-server)/thinkrank/mcp/?$',
			'^thinkrank/mcp/\.well-known/oauth-(protected-resource|authorization-server)/?$',
			'^thinkrank/mcp/\.well-known/openid-configuration/?$',
			'^thinkrank/authorize/?$',
		];
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) ) {
			foreach ( $expected as $rule ) {
				if ( ! isset( $rules[ $rule ] ) ) {
					flush_rewrite_rules( false );
					break;
				}
			}
		}
	}

	/**
	 * Register our query vars.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::TOKEN_QUERY_VAR;
		$vars[] = self::WELLKNOWN_QUERY_VAR;
		$vars[] = self::AUTHORIZE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the MCP endpoint on the pretty path. Runs on parse_request so it
	 * fires before the main query, and short-circuits WP entirely.
	 *
	 * @param \WP $wp The WP request object.
	 * @return void
	 */
	public function maybe_handle_pretty_endpoint( $wp ): void {
		// OAuth discovery documents (served at the site root).
		if ( ! empty( $wp->query_vars[ self::WELLKNOWN_QUERY_VAR ] ) ) {
			if ( ! self::is_enabled() ) {
				status_header( 404 );
				exit;
			}
			$doc  = (string) $wp->query_vars[ self::WELLKNOWN_QUERY_VAR ];
			$data = 'authorization-server' === $doc
				? Mcp_OAuth::authorization_server_metadata()
				: Mcp_OAuth::protected_resource_metadata();
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			// Discovery metadata is public + cacheable.
			header( 'Cache-Control: public, max-age=3600' );
			echo wp_json_encode( $data );
			exit;
		}

		// Browser-facing OAuth consent page (cookie auth applies here).
		if ( ! empty( $wp->query_vars[ self::AUTHORIZE_QUERY_VAR ] ) ) {
			if ( ! self::is_enabled() ) {
				status_header( 404 );
				exit;
			}
			$this->handle_authorize_page();
			return;
		}

		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$request = new \WP_REST_Request( 'POST', '/' . self::NS . '/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		// Carry the auth header + raw body from the live PHP request.
		$auth = self::server_header( 'authorization' );
		if ( null !== $auth ) {
			$request->set_header( 'authorization', $auth );
		}
		// Token embedded in the URL path (/thinkrank/mcp/<token>) — surface it
		// as a Bearer header so Mcp_Server validates it the same way. A real
		// Authorization header (if also sent) takes precedence.
		$path_token = isset( $wp->query_vars[ self::TOKEN_QUERY_VAR ] )
			? (string) $wp->query_vars[ self::TOKEN_QUERY_VAR ]
			: '';
		if ( '' !== $path_token && '' === (string) $request->get_header( 'authorization' ) ) {
			$request->set_header( 'authorization', 'Bearer ' . $path_token );
		}
		$request->set_body( (string) file_get_contents( 'php://input' ) );

		$response = Mcp_Server::handle( $request );
		$this->emit_json( $response );
	}

	// -- REST registration --

	/**
	 * Register the REST routes: the MCP JSON-RPC fallback, the admin
	 * management routes, and the OAuth registration/token endpoints.
	 *
	 * @return void
	 */
	public function register_rest(): void {
		// --- MCP JSON-RPC endpoint (fallback path via wp-json) -----------
		// permission_callback is __return_true because Mcp_Server does its own
		// token auth and must reply with a JSON-RPC 401 + WWW-Authenticate,
		// not a bare WP permission failure.
		register_rest_route(
			self::NS,
			'/mcp',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_mcp' ],
				'permission_callback' => '__return_true',
			]
		);

		// --- Admin-only management routes (the MCP page) ------------------
		register_rest_route(
			self::NS,
			'/mcp/connection',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_connection' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/connect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_connect' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'read_only' => [
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
						'description' => 'Grant read-only access (no SEO metadata or settings changes).',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/rotate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_rotate' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'read_only' => [
						'type'        => 'boolean',
						'required'    => false,
						'description' => 'Optionally set read-only on the new token; omit to keep current scopes.',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_disconnect' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);

		// Live round-trip diagnostic for the MCP page (see #189). Admin-only;
		// exercises the endpoint the way an external client would.
		register_rest_route(
			self::NS,
			'/mcp/self-test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_self_test' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);

		// Connected AI apps (see #244): list the OAuth-connected clients plus a
		// single combined row for the shared static token, and revoke either.
		register_rest_route(
			self::NS,
			'/mcp/apps',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_apps' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/apps/revoke',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_revoke_app' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'client_id' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'The OAuth client_id to revoke.',
					],
				],
			]
		);

		// --- OAuth 2.1 authorization server (the "paste a URL only" path) -
		// Discovery, dynamic client registration, and the token endpoint are
		// all public (permission enforced inside): a client must reach them
		// BEFORE it holds any credential.
		register_rest_route(
			self::NS,
			'/mcp/oauth/register',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_oauth_register' ],
				'permission_callback' => '__return_true',
			]
		);
		// NOTE: /authorize is deliberately NOT a REST route — it is served as
		// a normal front-end page at /thinkrank/authorize (see
		// handle_authorize_page) so cookie auth works after wp-login.
		register_rest_route(
			self::NS,
			'/mcp/oauth/token',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_oauth_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Capability gate for the admin-only management routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -- Handlers ----------------------------------------------------------

	/**
	 * MCP JSON-RPC over the wp-json fallback path.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function rest_mcp( \WP_REST_Request $request ): \WP_REST_Response {
		$response = Mcp_Server::handle( $request );
		// Advertise the MCP protocol version on the wp-json transport too, so
		// both endpoints behave identically to a strict Streamable-HTTP client.
		$response->header( 'MCP-Protocol-Version', Mcp_Server::PROTOCOL_VERSION );
		return $response;
	}

	/**
	 * GET /mcp/connection — pairing status for the MCP page.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_connection(): \WP_REST_Response {
		$this->ensure_connected();
		return rest_ensure_response( Mcp_Pairing::public_status() );
	}

	/**
	 * Self-heal: whenever the admin views the MCP page with MCP enabled, make
	 * sure a connection token exists. New sites mint on the enable toggle (see
	 * #244), but a site that had MCP on before that behavior shipped would have
	 * no token; minting here — idempotent, admin-gated — keeps the connect
	 * recipes populated without a separate "Generate token" click.
	 *
	 * @return void
	 */
	private function ensure_connected(): void {
		if ( self::is_enabled() && ! Mcp_Pairing::is_connected() ) {
			Mcp_Pairing::connect();
		}
	}

	/**
	 * POST /mcp/connect — mint a connection token.
	 *
	 * @param \WP_REST_Request $request Carries optional read_only.
	 * @return \WP_REST_Response
	 */
	public function rest_connect( \WP_REST_Request $request ): \WP_REST_Response {
		$read_only = (bool) $request->get_param( 'read_only' );
		return rest_ensure_response( Mcp_Pairing::connect( $read_only ) );
	}

	/**
	 * POST /mcp/rotate — mint a fresh token, invalidating the old one.
	 *
	 * @param \WP_REST_Request $request Carries optional read_only.
	 * @return \WP_REST_Response
	 */
	public function rest_rotate( \WP_REST_Request $request ): \WP_REST_Response {
		$read_only = null;
		if ( null !== $request->get_param( 'read_only' ) ) {
			$read_only = (bool) $request->get_param( 'read_only' );
		}
		return rest_ensure_response( Mcp_Pairing::rotate( $read_only ) );
	}

	/**
	 * POST /mcp/disconnect — revoke the connection token + all OAuth grants.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_disconnect(): \WP_REST_Response {
		return rest_ensure_response( Mcp_Pairing::disconnect() );
	}

	/**
	 * POST /mcp/self-test — run the live round-trip diagnostic (see #189).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_self_test(): \WP_REST_Response {
		return rest_ensure_response( Mcp_Self_Test::run() );
	}

	/**
	 * GET /mcp/apps — the "Connected AI apps" list (see #244).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_apps(): \WP_REST_Response {
		$this->ensure_connected();
		return rest_ensure_response( $this->apps_payload() );
	}

	/**
	 * POST /mcp/apps/revoke — cut off a single OAuth-connected app. Returns the
	 * refreshed app list so the UI updates in one round trip. (The shared static
	 * token has no per-client identity, so it is not listed or revoked here — it
	 * is rotated from the connect card via /mcp/rotate.)
	 *
	 * @param \WP_REST_Request $request Carries target + client_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_revoke_app( \WP_REST_Request $request ) {
		$client_id = (string) $request->get_param( 'client_id' );
		if ( '' === $client_id ) {
			return new \WP_Error(
				'thinkrank_missing_client_id',
				__( 'A client_id is required to revoke an OAuth app.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}
		Mcp_OAuth::revoke_client( $client_id );

		return rest_ensure_response( $this->apps_payload() );
	}

	/**
	 * Build the "Connected AI apps" payload: the OAuth-connected clients, with
	 * the approving admin's display name resolved. Header-based (static-token)
	 * clients share one anonymous secret and so are not represented here.
	 *
	 * @return array<string,mixed>
	 */
	private function apps_payload(): array {
		$oauth_apps = [];
		foreach ( Mcp_OAuth::connected_apps() as $app ) {
			$user         = $app['user_id'] > 0 ? get_userdata( $app['user_id'] ) : false;
			$oauth_apps[] = [
				'client_id'    => $app['client_id'],
				'name'         => $app['name'],
				'read_only'    => $app['read_only'],
				'approved_by'  => $user ? $user->display_name : __( 'Unknown user', 'thinkrank' ),
				'connected_at' => $app['connected_at'],
				'last_used'    => $app['last_used'],
			];
		}

		return [
			'oauth_apps' => $oauth_apps,
		];
	}

	// -- OAuth 2.1 handlers ------------------------------------------------

	/**
	 * POST /mcp/oauth/register — RFC 7591 dynamic client registration.
	 *
	 * @param \WP_REST_Request $request JSON body with redirect_uris.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_oauth_register( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'thinkrank_mcp_disabled', __( 'MCP is disabled on this site.', 'thinkrank' ), [ 'status' => 403 ] );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$result = Mcp_OAuth::register_client( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /mcp/oauth/token — exchange a code (or refresh token) for tokens.
	 *
	 * @param \WP_REST_Request $request Form-encoded or JSON token request.
	 * @return \WP_REST_Response
	 */
	public function rest_oauth_token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::is_enabled() ) {
			$response = new \WP_REST_Response(
				[
					'error'             => 'invalid_request',
					'error_description' => 'MCP is disabled on this site.',
				],
				403
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		// Token requests are application/x-www-form-urlencoded per OAuth, but
		// accept JSON too. get_body_params() covers the form case.
		$body = $request->get_body_params();
		if ( empty( $body ) ) {
			$json = $request->get_json_params();
			$body = is_array( $json ) ? $json : [];
		}
		$body = array_map( 'strval', $body );

		$result = Mcp_OAuth::exchange_token( $body );
		if ( is_wp_error( $result ) ) {
			$data     = $result->get_error_data();
			$response = new \WP_REST_Response(
				[
					'error'             => isset( $data['error'] ) ? $data['error'] : 'invalid_request',
					'error_description' => isset( $data['error_description'] ) ? $data['error_description'] : $result->get_error_message(),
				],
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$response = new \WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	// -- OAuth authorize page ------------------------------------------------

	/**
	 * The browser-facing OAuth authorize page (served at /thinkrank/authorize
	 * via a rewrite, NOT the REST API). Reads request params from the
	 * superglobals because this is a normal front-end request where cookie
	 * auth populates is_user_logged_in().
	 *
	 * GET renders the consent screen (requires a logged-in admin; anonymous
	 * users go to wp-login and return here). POST is the nonce-checked consent
	 * submission: Approve issues a code and 302s to the client's redirect_uri;
	 * Deny 302s back with error=access_denied. Always emits its own response
	 * (HTML page or redirect) and exits.
	 *
	 * @return void
	 */
	public function handle_authorize_page(): void {
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		// Params come from GET on the consent link and POST on the form submit.
		// Nonce is verified below before any POST value is acted on.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$source = $is_post ? $_POST : $_GET;
		// phpcs:enable
		$params = [];
		foreach ( [ 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'approve', 'deny', '_thinkrank_oauth_nonce' ] as $k ) {
			$params[ $k ] = isset( $source[ $k ] ) ? sanitize_text_field( wp_unslash( $source[ $k ] ) ) : '';
		}

		// Validate the OAuth params before touching the session.
		$req = Mcp_OAuth::validate_authorize_request( $params );
		if ( is_wp_error( $req ) ) {
			$data         = $req->get_error_data();
			$redirectable = is_array( $data ) && ! empty( $data['redirectable'] );
			// Only redirect the error back when redirect_uri is verified valid;
			// otherwise show a page (never bounce to an unverified URL).
			if ( $redirectable && '' !== $params['redirect_uri'] ) {
				$this->redirect_error( $params['redirect_uri'], $req->get_error_code(), $req->get_error_message(), $params['state'] );
			}
			$this->emit_oauth_error_page( $req->get_error_message() );
		}

		// Require a logged-in admin. Anonymous → wp-login, back to this URL.
		if ( ! is_user_logged_in() ) {
			$this->redirect_to_login();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->emit_oauth_error_page(
				__( 'You must be an administrator to authorize an AI assistant to manage SEO on this site.', 'thinkrank' )
			);
		}

		// POST = consent form submitted.
		if ( $is_post ) {
			if ( ! wp_verify_nonce( $params['_thinkrank_oauth_nonce'], 'thinkrank_oauth_consent' ) ) {
				$this->emit_oauth_error_page( __( 'Security check failed. Please try connecting again.', 'thinkrank' ) );
			}
			if ( '' === $params['approve'] ) {
				$this->redirect_error( $req['redirect_uri'], 'access_denied', 'The user denied the request.', $req['state'] );
			}
			$code = Mcp_OAuth::issue_code( $req, get_current_user_id() );
			$this->redirect_success( $req['redirect_uri'], $code, $req['state'] );
		}

		// GET = render the consent screen.
		$this->emit_consent_screen( $req );
	}

	// -- OAuth browser-response helpers ------------------------------------

	/**
	 * The absolute URL of the current authorize request (for login return).
	 *
	 * @return string
	 */
	private function current_authorize_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reconstructing the current URL for a login round-trip; escaped at use.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return home_url( $uri );
	}

	/**
	 * Send an anonymous visitor to wp-login, returning to this authorize URL.
	 *
	 * @return void
	 */
	private function redirect_to_login(): void {
		wp_safe_redirect( wp_login_url( $this->current_authorize_url() ) );
		exit;
	}

	/**
	 * 302 back to the client with the authorization code (+ state).
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 * @param string $code         Authorization code.
	 * @param string $state        Client state.
	 * @return void
	 */
	private function redirect_success( string $redirect_uri, string $code, string $state ): void {
		$args = [ 'code' => $code ];
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		// Not wp_safe_redirect: redirect_uri is a client-registered off-site
		// callback, already validated against the client's registered set.
		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/**
	 * 302 back to the client with an OAuth error (+ state).
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $description  Human-readable description.
	 * @param string $state        Client state.
	 * @return void
	 */
	private function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$args = [
			'error'             => $error,
			'error_description' => $description,
		];
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/**
	 * Render the consent screen. Minimal self-contained HTML (no admin
	 * chrome — this is a client-facing OAuth page). Approve/Deny post back
	 * to the same authorize URL with a nonce.
	 *
	 * @param array<string,string> $req Validated authorize params.
	 * @return void
	 */
	private function emit_consent_screen( array $req ): void {
		$read_only    = Mcp_OAuth::scope_is_read_only( $req['scope'] );
		$access_label = $read_only ? __( 'Read-only', 'thinkrank' ) : __( 'Read & write', 'thinkrank' );
		$access_desc  = $read_only
			? __( 'Review your SEO across posts and site settings — metadata, schema, sitemaps, robots, social, and SEO scores. No changes are made.', 'thinkrank' )
			: __( 'Read and improve your SEO across posts and site settings — metadata, schema, sitemaps, robots, social, indexing, and SEO scores.', 'thinkrank' );
		$client     = '' !== $req['client_name'] ? $req['client_name'] : __( 'An AI assistant', 'thinkrank' );
		$action_url = Mcp_OAuth::authorize_url();
		$nonce      = wp_create_nonce( 'thinkrank_oauth_consent' );
		$user       = wp_get_current_user();

		// Preserve every OAuth param so the POST re-validates identically.
		$hidden = '';
		foreach ( [ 'client_id', 'redirect_uri', 'code_challenge', 'scope', 'state' ] as $k ) {
			$val     = 'scope' === $k ? $req['scope'] : ( $req[ $k ] ?? '' );
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( (string) $val ) );
		}
		// code_challenge_method + response_type are re-asserted for validation.
		$hidden .= '<input type="hidden" name="code_challenge_method" value="S256" />';
		$hidden .= '<input type="hidden" name="response_type" value="code" />';

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$logo = '<svg width="36" height="36" viewBox="0 0 29 29" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<g clip-path="url(#tr_clip0)">'
			. '<path d="M0.436523 6.61665C0.436523 3.15762 3.24063 0.353516 6.69966 0.353516H22.1734C25.6324 0.353516 28.4365 3.15762 28.4365 6.61665V22.0904C28.4365 25.5494 25.6324 28.3535 22.1734 28.3535H6.69966C3.24063 28.3535 0.436523 25.5494 0.436523 22.0904V6.61665Z" fill="url(#tr_p0)"/>'
			. '<path d="M29.1618 8.11914C29.1618 8.11914 29.1622 8.11903 29.3906 8.71865C29.6058 9.28347 29.6182 9.31627 29.6189 9.31817C29.6189 9.31817 29.6185 9.3185 29.6182 9.31862C29.6176 9.31886 29.6166 9.31924 29.6153 9.31976C29.6124 9.32086 29.6078 9.32246 29.6018 9.32477C29.5898 9.32942 29.5714 9.33652 29.5471 9.34596C29.4986 9.36487 29.4265 9.39344 29.3332 9.43073C29.1465 9.50532 28.8754 9.61538 28.5404 9.75703C27.8699 10.0405 26.9452 10.449 25.9291 10.9483C23.8797 11.9554 21.5331 13.2968 20.1165 14.6951C19.2976 15.5641 18.5831 16.4248 17.898 17.2522C17.2153 18.0766 16.555 18.8765 15.8671 19.588C14.477 21.0257 12.9316 22.1479 10.6959 22.53C9.60585 22.7163 8.53683 22.6112 7.52835 22.4516C6.48364 22.2863 5.5662 22.0772 4.59619 21.989C3.82718 21.9191 3.16045 21.9788 2.48024 22.211C1.79208 22.4458 1.05578 22.8689 0.16582 23.5725L-0.629883 22.5658C0.329559 21.8073 1.19422 21.2939 2.06576 20.9965C2.94522 20.6963 3.79701 20.6277 4.7124 20.7109C5.73306 20.8037 6.79732 21.0365 7.7291 21.184C8.69711 21.3372 9.59965 21.4156 10.4799 21.2651C12.3522 20.9451 13.6648 20.0196 14.9444 18.6962C15.5914 18.027 16.2189 17.2678 16.9095 16.4337C17.5953 15.6055 18.3371 14.7115 19.1918 13.8053L19.1994 13.7973L19.2073 13.7893C20.7824 12.2312 23.2976 10.8117 25.3631 9.79668C26.4061 9.28415 27.3538 8.86556 28.0407 8.5751C28.3843 8.4298 28.6633 8.31639 28.8569 8.239C28.9537 8.20031 29.0292 8.17052 29.0809 8.15036C29.1068 8.14028 29.1268 8.13259 29.1404 8.12734C29.1472 8.12472 29.1525 8.12281 29.1561 8.12142C29.1579 8.12073 29.1592 8.11998 29.1602 8.1196C29.1607 8.11941 29.1613 8.11925 29.1616 8.11914H29.1618Z" fill="url(#tr_p2)"/>'
			. '<path d="M22.3437 13.4975C22.3437 14.5285 21.508 15.3642 20.477 15.3642C19.4461 15.3642 18.6104 14.5285 18.6104 13.4975C18.6104 12.4666 19.4461 11.6309 20.477 11.6309C21.508 11.6309 22.3437 12.4666 22.3437 13.4975Z" fill="url(#tr_p3)"/>'
			. '<path d="M20.4766 11.2734C21.7049 11.2734 22.7009 12.2688 22.7012 13.4971C22.7012 14.7256 21.7051 15.7217 20.4766 15.7217C19.2482 15.7214 18.2529 14.7254 18.2529 13.4971C18.2532 12.2689 19.2484 11.2737 20.4766 11.2734Z" stroke="white" stroke-width="0.715555"/>'
			. '<path d="M15.6201 6.78776C15.9391 6.88104 15.9391 7.33291 15.6201 7.42619L13.5873 8.02069C13.4784 8.05254 13.3933 8.13768 13.3614 8.24656L12.7669 10.2794C12.6736 10.5984 12.2218 10.5984 12.1285 10.2794L11.534 8.24656C11.5022 8.13768 11.417 8.05254 11.3081 8.02069L9.27529 7.42619C8.95631 7.33291 8.9563 6.88104 9.27528 6.78776L11.3081 6.19326C11.417 6.16141 11.5022 6.07627 11.534 5.96739L12.1285 3.93455C12.2218 3.61557 12.6736 3.61557 12.7669 3.93455L13.3614 5.96739C13.3933 6.07627 13.4784 6.16141 13.5873 6.19326L15.6201 6.78776Z" fill="url(#tr_p4)"/>'
			. '<path d="M11.6747 13.8907C11.8298 13.9361 11.8298 14.1557 11.6747 14.201L10.4245 14.5667C10.3716 14.5822 10.3302 14.6235 10.3147 14.6765L9.94909 15.9267C9.90375 16.0817 9.68413 16.0817 9.63879 15.9267L9.27317 14.6765C9.25769 14.6235 9.21631 14.5822 9.16339 14.5667L7.91315 14.201C7.75811 14.1557 7.75811 13.9361 7.91315 13.8907L9.16339 13.5251C9.21631 13.5096 9.25769 13.4683 9.27317 13.4153L9.63879 12.1651C9.68413 12.0101 9.90375 12.0101 9.94909 12.1651L10.3147 13.4153C10.3302 13.4683 10.3716 13.5096 10.4245 13.5251L11.6747 13.8907Z" fill="url(#tr_p5)"/>'
			. '</g>'
			. '<rect x="0.353516" y="0.353516" width="28" height="28" rx="7.76216" stroke="#B6CBFF" stroke-width="0.707321"/>'
			. '<defs>'
			. '<linearGradient id="tr_p0" x1="14.4365" y1="0.353516" x2="14.4365" y2="28.3535" gradientUnits="userSpaceOnUse"><stop stop-color="#5A57FF"/><stop offset="1" stop-color="#5B98FF"/></linearGradient>'
			. '<linearGradient id="tr_p2" x1="29.2845" y1="8.77232" x2="0.650625" y2="22.9504" gradientUnits="userSpaceOnUse"><stop stop-color="#BDC1FF"/><stop offset="0.420828" stop-color="#D6E8FF"/><stop offset="1" stop-color="#7391FE"/></linearGradient>'
			. '<radialGradient id="tr_p3" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(22.1572 11.6007) rotate(134.421) scale(4.39003)"><stop offset="0.321315" stop-color="#BAFEFF"/><stop offset="1" stop-color="#011FFF"/></radialGradient>'
			. '<linearGradient id="tr_p4" x1="9.87102" y1="1.28287" x2="14.9766" y2="13.0695" gradientUnits="userSpaceOnUse"><stop stop-color="#FFE6FC"/><stop offset="0.350962" stop-color="#FFE6FC"/><stop offset="1" stop-color="#AA00F2"/></linearGradient>'
			. '<linearGradient id="tr_p5" x1="8.28563" y1="10.6367" x2="11.2743" y2="17.5362" gradientUnits="userSpaceOnUse"><stop stop-color="#FFE6FC"/><stop offset="0.350962" stop-color="#FFE6FC"/><stop offset="1" stop-color="#AA00F2"/></linearGradient>'
			. '<clipPath id="tr_clip0"><rect x="0.353516" y="0.353516" width="28" height="28" rx="7.76216" fill="white"/></clipPath>'
			. '</defs></svg>';
		$lock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Authorize AI access', 'thinkrank' ) . '</title>';
		echo '<style>'
			. ':root{color-scheme:dark}*{box-sizing:border-box}'
			. 'body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:radial-gradient(1100px 600px at 50% -15%,#20284a,#0b1020 62%);color:#e7ecf3;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}'
			. '.card{width:100%;max-width:460px;background:#141a2e;border:1px solid #263149;border-radius:20px;padding:28px;box-shadow:0 24px 60px rgba(0,0,0,.5)}'
			. '.brand{display:flex;align-items:center;gap:10px;margin-bottom:22px}'
			. '.logo{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(99,102,241,.4)}'
			. '.brand b{font-size:14px;font-weight:700;letter-spacing:.02em}'
			. 'h1{font-size:20px;font-weight:700;margin:0 0 6px}'
			. '.sub{color:#9aa6be;font-size:13.5px;margin:0 0 22px}.sub strong{color:#e7ecf3;font-weight:600}'
			. '.rows{border:1px solid #263149;border-radius:14px;overflow:hidden;margin-bottom:16px}'
			. '.row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:13px 16px;font-size:13.5px}'
			. '.row+.row,.access{border-top:1px solid #263149}'
			. '.row .k{color:#9aa6be}.row .v{font-weight:600;text-align:right;word-break:break-word}'
			. '.access{padding:14px 16px;background:rgba(99,102,241,.07)}'
			. '.access .k{color:#9aa6be;font-size:13px;margin-bottom:8px}'
			. '.badge{display:inline-flex;align-items:center;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;background:rgba(99,102,241,.18);color:#c7cbff;border:1px solid rgba(99,102,241,.4)}'
			. '.badge.ro{background:rgba(245,158,11,.15);color:#fcd9a1;border-color:rgba(245,158,11,.4)}'
			. '.access .d{color:#c3ccdd;font-size:13px;margin-top:8px}'
			. '.note{display:flex;align-items:center;gap:7px;color:#7f8aa3;font-size:12px;margin:0 0 20px}'
			. '.actions{display:flex;gap:12px}'
			. 'button{flex:1;padding:13px;border-radius:12px;border:0;font-size:14px;font-weight:600;cursor:pointer;transition:filter .15s,transform .05s}button:active{transform:translateY(1px)}'
			. '.approve{background:linear-gradient(135deg,#6366f1,#7c73ff);color:#fff;box-shadow:0 8px 20px rgba(99,102,241,.38)}.approve:hover{filter:brightness(1.07)}'
			. '.deny{background:transparent;color:#aeb8cc;border:1px solid #33405c}.deny:hover{background:rgba(255,255,255,.04)}'
			. '</style></head><body><div class="card">';

		echo '<div class="brand"><span class="logo">' . $logo . '</span><b>ThinkRank</b></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		echo '<h1>' . esc_html__( 'Connect to ThinkRank', 'thinkrank' ) . '</h1>';
		$sub = sprintf(
			/* translators: %s: AI client name, already escaped and wrapped in <strong>. */
			esc_html__( '%s wants to manage SEO on this site.', 'thinkrank' ),
			'<strong>' . esc_html( $client ) . '</strong>'
		);
		echo '<p class="sub">' . $sub . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static translation; client name esc_html'd.

		echo '<div class="rows">';
		echo '<div class="row"><span class="k">' . esc_html__( 'Site', 'thinkrank' ) . '</span><span class="v">' . esc_html( $host ) . '</span></div>';
		echo '<div class="row"><span class="k">' . esc_html__( 'Signed in as', 'thinkrank' ) . '</span><span class="v">' . esc_html( $user->user_login ) . '</span></div>';
		echo '<div class="access"><div class="k">' . esc_html__( 'Access', 'thinkrank' ) . '</div>';
		echo '<span class="badge ' . ( $read_only ? 'ro' : '' ) . '">' . esc_html( $access_label ) . '</span>';
		echo '<div class="d">' . esc_html( $access_desc ) . '</div></div>';
		echo '</div>';

		echo '<p class="note">' . $lock . '<span>' . esc_html__( 'Secured with OAuth. Revoke anytime in ThinkRank → MCP.', 'thinkrank' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon; text esc_html'd.

		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() above.
		echo '<input type="hidden" name="_thinkrank_oauth_nonce" value="' . esc_attr( $nonce ) . '" />';
		echo '<div class="actions">';
		echo '<button class="deny" name="deny" value="1">' . esc_html__( 'Deny', 'thinkrank' ) . '</button>';
		echo '<button class="approve" name="approve" value="1">' . esc_html__( 'Approve', 'thinkrank' ) . '</button>';
		echo '</div></form></div></body></html>';
		exit;
	}

	/**
	 * Render a standalone OAuth error page (no redirect).
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function emit_oauth_error_page( string $message ): void {
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Authorization error', 'thinkrank' ) . '</title>';
		echo '<style>body{font:15px/1.5 -apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
			. '.card{background:#1e293b;border:1px solid #334155;border-radius:16px;max-width:440px;padding:32px;text-align:center}</style></head><body>';
		echo '<div class="card"><h1>' . esc_html__( 'Could not authorize', 'thinkrank' ) . '</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	// -- Helpers --

	/**
	 * Read an inbound HTTP header from $_SERVER (for the pretty path).
	 *
	 * @param string $name Header name.
	 * @return string|null
	 */
	private static function server_header( string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- token compared constant-time downstream; raw header needed verbatim.
		return isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : null;
	}

	/**
	 * Emit a WP_REST_Response as a JSON HTTP response and stop.
	 *
	 * @param \WP_REST_Response $response Response to emit.
	 * @return void
	 */
	private function emit_json( \WP_REST_Response $response ): void {
		status_header( $response->get_status() );
		// MCP Streamable HTTP: advertise the protocol version we speak so a
		// strict client can pin it. We answer JSON (a spec-permitted response
		// type); we never open an SSE stream, so no session header is needed.
		header( 'MCP-Protocol-Version: ' . Mcp_Server::PROTOCOL_VERSION );
		// Forward any headers the handler set (notably WWW-Authenticate on a
		// 401, which drives the OAuth discovery flow).
		foreach ( $response->get_headers() as $name => $value ) {
			// Re-assert the status on every header: PHP special-cases
			// WWW-Authenticate and forces a 401 when no status is given,
			// which would silently mask the 429 lockout response.
			header( $name . ': ' . $value, true, $response->get_status() );
		}
		$data = $response->get_data();
		if ( null !== $data ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $data );
		}
		exit;
	}
}
