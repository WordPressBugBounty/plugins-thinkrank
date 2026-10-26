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
	 * Whether this request is an MCP call being served.
	 *
	 * The pretty /thinkrank/mcp route is dispatched from `parse_request`, so it
	 * is neither `is_admin()` nor `REST_REQUEST`. Anything deciding whether a
	 * request may take on deferred work needs to be able to see it (#764).
	 *
	 * @since 2.10.0
	 * @var bool
	 */
	private static $serving_request = false;

	/**
	 * Whether an MCP call is being served on this request.
	 *
	 * Pair with `is_user_logged_in()` to mean "an authenticated MCP call":
	 * Mcp_Server sets the current user only once a credential has validated, so
	 * an unauthenticated POST never looks like one.
	 *
	 * @since 2.10.0
	 * @return bool
	 */
	public static function is_serving_request(): bool {
		return self::$serving_request;
	}

	/**
	 * Query var flagging a /.well-known/ OAuth discovery request.
	 */
	private const WELLKNOWN_QUERY_VAR = 'thinkrank_mcp_wellknown';

	/**
	 * Query var carrying the resource path a root-form discovery request asked
	 * about, so the handler can tell whether that resource is ours (#516).
	 */
	private const WELLKNOWN_RESOURCE_QUERY_VAR = 'thinkrank_mcp_wellknown_resource';

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
	 * Headers every OAuth page this class prints is sent with.
	 *
	 * The authorize URL is a front-end page, so neither core's
	 * send_frame_options_header() (admin and login only) nor Security_Headers
	 * (admin_init and REST only) runs for it. A consent page that can be
	 * framed is a consent page that can be clickjacked: one Approve click from
	 * a signed-in administrator, under a decoy, issues a code to whoever
	 * registered the client. Both the legacy header and the CSP directive are
	 * sent, since browsers that honour frame-ancestors ignore X-Frame-Options
	 * and older ones only know the latter.
	 *
	 * @since 2.18.0
	 */
	public const OAUTH_PAGE_HEADERS = [
		'Content-Type'            => 'text/html; charset=utf-8',
		'Cache-Control'           => 'no-store',
		'X-Frame-Options'         => 'DENY',
		'Content-Security-Policy' => "frame-ancestors 'none'",
	];

	/**
	 * Initialize (called by the plugin's component container).
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest' ] );

		// Published /.well-known/ files are served ahead of WordPress, so a
		// site URL change leaves them advertising the old domain's issuer with
		// nothing to correct them. Registered unconditionally: a stale
		// document is harmful whether or not MCP is currently enabled (#486).
		Mcp_Static_Discovery::init();

		// Pretty per-site endpoint: /thinkrank/mcp → MCP JSON-RPC handler.
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'parse_request', [ $this, 'maybe_handle_pretty_endpoint' ] );

		// The wp-json fallback holds the same body cap as the pretty path.
		// Core reads the whole body in serve_request() and decodes it as JSON
		// before the route callback, so the cap has to act ahead of both:
		// bound the read before rest_api_loaded() (priority 10) starts the
		// server, and refuse before dispatch() parses (#1097).
		add_action( 'parse_request', [ $this, 'cap_rest_body_read' ], 9 );
		add_filter( 'rest_pre_dispatch', [ $this, 'refuse_oversized_rest_body' ], 10, 3 );
		// The route is POST only, so core answered any other method with a
		// 404 rest_no_route; answer it as the pretty endpoint does (#1349).
		add_filter( 'rest_pre_dispatch', [ $this, 'refuse_other_rest_methods' ], 10, 3 );

		// The one broken state the server can't see from inside a request:
		// MCP enabled but the bundled Abilities runtime absent. Everything
		// else still works — OAuth discovers, tokens mint, clients connect —
		// and tools/list is an empty array served as success. Three layers
		// each "no-op gracefully" (the is_readable() require, the registrar,
		// the tool registry) and composed they manufacture a connector that
		// connects and offers nothing, with no signal anywhere. Say it loudly
		// where an admin will look.
		add_action( 'admin_notices', [ $this, 'warn_when_runtime_missing' ] );

		// Daily retention prune for the activity log (#717).
		Mcp_Audit::init();
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
			esc_html__( 'MCP access is enabled, but the bundled Abilities runtime (dependencies/vendor) is missing from this installation, usually a plugin package built without it. Reinstall ThinkRank from wordpress.org or an official build; until then, connected AI clients get an empty tool list.', 'thinkrank' )
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

	/**
	 * Turn the MCP switch on through the setting's own setter, as the
	 * settings route does, minting the connection token on the same
	 * transition (#244) so the connect recipes are ready.
	 *
	 * Connecting to ThinkRank Hub calls this (thinkrank#1002). There is
	 * deliberately no counterpart that turns the switch off from the hub: the
	 * owner's other assistants may rely on it, and ending the hub's grant is
	 * what Disconnect does.
	 *
	 * @since 2.15.0
	 *
	 * @return void
	 */
	public static function enable(): void {
		if ( self::is_enabled() ) {
			return;
		}

		Settings::instance()->set( 'enable_mcp', true );
		Mcp_Pairing::connect();
	}

	// -- Pretty endpoint: /thinkrank/mcp --

	/**
	 * Register rewrite rules for the MCP endpoint, OAuth discovery documents,
	 * and the browser-facing authorize page.
	 *
	 * @return void
	 */
	public function add_rewrite(): void {
		$rules = self::rewrite_rules();

		foreach ( $rules as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}

		// Self-heal: flush once if ANY of our rules is missing from the stored
		// rewrite table, so the endpoints work without a manual permalink
		// re-save (and newly added rules trigger a re-flush on upgrade).
		//
		// Checked against the same array that was just registered, never
		// against a second hand-maintained list. The two drifted apart once
		// already: #775's first pass changed the discovery regex and left the
		// superseded one in the list, so a rule that is never registered was
		// permanently "missing" and every front-end request rebuilt the whole
		// rewrite table. Measured at 4 regenerations across 3 page loads
		// against 0 before the change — silent, because a rebuilt table is
		// still a correct one.
		//
		// It also has to notice the opposite: a rule of ours that is stored
		// but no longer wanted. The bare discovery rule is registered only
		// while thinkrank_mcp_serve_root_discovery is on, and "missing" alone
		// never fires when it is switched off again, because every rule still
		// registered is present. The stale rule then kept claiming the bare
		// URL from other MCP plugins (#775) until someone re-saved
		// permalinks. The unwanted set is derived from the same table built
		// with every optional rule on, not listed, so it cannot drift either.
		// A flush rebuilds the table from what this request registered, so
		// the stale rule is gone afterwards and the check settles instead of
		// flushing on every request.
		$stored = get_option( 'rewrite_rules' );
		if ( is_array( $stored ) ) {
			$unwanted = array_diff_key( self::rewrite_rules( true ), $rules ) + self::retired_rules();
			$stale    = array_intersect_key( $unwanted, $stored );
			$missing  = array_diff_key( $rules, $stored );

			if ( ! empty( $missing ) || ! empty( $stale ) ) {
				flush_rewrite_rules( false );
			}
		}
	}

	/**
	 * Rules this plugin used to register and must not survive in a stored
	 * rewrite table.
	 *
	 * The token-in-path form, `/thinkrank/mcp/<token>`, is gone (#1001,
	 * BetterShield A4): a credential in a URL lands in access logs, proxy and
	 * CDN logs, `Referer` headers and browser history, and the bearer header
	 * is the only way in now. A site upgraded in place still holds the rule
	 * in its stored table until something flushes it, and the self-heal in
	 * add_rewrite() does that once, the first time it sees one of these.
	 * Afterwards the address matches no rule of ours and answers 404 like any
	 * unknown path.
	 *
	 * @since 2.15.0
	 *
	 * @return array<string,string> Regex => the query it used to map to.
	 */
	private static function retired_rules(): array {
		return [
			'^thinkrank/mcp/([a-f0-9]{64})/?$' => 'index.php?' . self::QUERY_VAR . '=1&thinkrank_mcp_token=$matches[1]',
		];
	}

	/**
	 * Every rewrite rule this plugin owns, as regex => query string.
	 *
	 * Single source of truth for registration AND for the self-heal check, so
	 * the two cannot describe different sets of rules. All of them register at
	 * `'top'`; a `'bottom'` rule would never be reached, because core's own
	 * pagename rule matches almost any path ahead of it.
	 *
	 * @since 2.10.0
	 *
	 * @param bool|null $root_discovery Include the opt-in bare discovery rule.
	 *                                  Null (the default) follows
	 *                                  {@see self::serves_root_discovery()};
	 *                                  true is how add_rewrite() learns which
	 *                                  optional rules exist, to retire one
	 *                                  that was switched off.
	 * @return array<string,string> Regex => query string.
	 */
	private static function rewrite_rules( ?bool $root_discovery = null ): array {
		$endpoint = preg_quote( trim( Mcp_Pairing::SITE_ENDPOINT_PATH, '/' ), '/' );

		$rules = [
			// The endpoint. The bearer header is the only way in: the
			// token-in-path form (/thinkrank/mcp/<token>) is retired, see
			// retired_rules().
			'^thinkrank/mcp/?$' => 'index.php?' . self::QUERY_VAR . '=1',

			// OAuth discovery documents. RFC 9728 §3.1 / RFC 8414 §3.1 place
			// the `.well-known` segment BEFORE the resource path, so our
			// resource at /thinkrank/mcp is discovered at:
			//   /.well-known/oauth-protected-resource/thinkrank/mcp
			//   /.well-known/oauth-authorization-server/thinkrank/mcp
			// The OAuth issuer is the path-based identifier
			// home_url('/thinkrank/mcp') (see Mcp_OAuth::issuer), so
			// spec-compliant clients derive exactly these URLs.
			'^\.well-known/oauth-(protected-resource|authorization-server)/thinkrank/mcp/?$' =>
				'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',

			// The same form with a site prefix in front of our endpoint path,
			// which is how subdirectory multisite discovers /ca/thinkrank/mcp
			// (#516). The trailing path is CAPTURED rather than discarded and
			// the pattern only accepts a path ENDING in our own endpoint: an
			// earlier version matched `(/.*)?` — anything at all — and so
			// claimed every neighbouring MCP plugin's RFC 9728 discovery URL
			// alongside our own (#775).
			//
			// Declining inside the handler does not undo that, and this is the
			// part worth being precise about: rewrite matching happens once, in
			// WP::parse_request(), before the `parse_request` action our
			// handler runs on. By then this rule has already won and the owning
			// plugin's rule has not matched, so its query var is never set and
			// its handler never fires. Returning instead of exiting would leave
			// the request to die in the main query — a 404 either way, exactly
			// as broken for the neighbour as serving them our document was. The
			// rule itself has to stop matching, so normal rewrite resolution
			// reaches the plugin that owns the URL.
			//
			// The handler still validates what this does match: a prefix that
			// is not a real site on this network is declined there.
			'^\.well-known/oauth-(protected-resource|authorization-server)((?:/.*)?/' . $endpoint . ')/?$' =>
				'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]&' . self::WELLKNOWN_RESOURCE_QUERY_VAR . '=$matches[2]',

			// Suffix form: <issuer>/.well-known/... . RFC 8414 specifies the
			// path-INSERT form above, but the older OpenID Connect Discovery
			// convention appends instead, and clients built on an OIDC library
			// try that shape first (sometimes only that shape). Both of these
			// live under our own endpoint path, so neither can collide with
			// another plugin's discovery URLs.
			'^thinkrank/mcp/\.well-known/oauth-(protected-resource|authorization-server)/?$' =>
				'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'^thinkrank/mcp/\.well-known/openid-configuration/?$' =>
				'index.php?' . self::WELLKNOWN_QUERY_VAR . '=authorization-server',

			// Browser-facing OAuth consent page — served OUTSIDE REST so cookie
			// auth (is_user_logged_in) works after the wp-login round-trip.
			'^thinkrank/authorize/?$' => 'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1',
		];

		if ( $root_discovery ?? self::serves_root_discovery() ) {
			$rules['^\.well-known/oauth-(protected-resource|authorization-server)/?$'] =
				'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]';
		}

		return $rules;
	}

	/**
	 * Whether to answer the BARE `/.well-known/oauth-*` URLs, with no resource
	 * path after the document name. Off by default.
	 *
	 * That URL is not ours to answer, in two separate senses.
	 *
	 * It is not ours by the specs. RFC 9728 §3.1 builds a resource's metadata
	 * URL by inserting `.well-known` before the resource's path, so the bare
	 * form is the metadata URL for the resource `https://example.com` — the
	 * site root. Ours is `https://example.com/thinkrank/mcp`. §3.3 requires the
	 * `resource` in the document to equal the identifier it was fetched for, so
	 * answering there returns a document that contradicts its own URL. RFC 8414
	 * §3.3 says the same of `issuer`. A client that validates is entitled to
	 * reject it; the only clients it ever helped were those that do not.
	 *
	 * And it is not ours on a site with a second MCP plugin. Registered at
	 * `'top'`, our rule did not merely enter the ordering race for that URL, it
	 * went to the front of it — so a neighbour's client reading the bare form
	 * got OUR resource identifier while connecting somewhere else, which is the
	 * same RFC 9728 mismatch #775 was filed about, reached by the other URL.
	 * Because the winner depends on registration and flush order, it also
	 * reproduced intermittently: the same site could behave differently after
	 * an unrelated flush.
	 *
	 * Nothing of ours needs it. The 401 challenge advertises
	 * {@see Mcp_OAuth::resource_metadata_url()}, a route in our own REST
	 * namespace that no other plugin can claim; the path-insert and
	 * OIDC-suffix forms above cover spec-compliant and OIDC-library clients;
	 * {@see Mcp_Self_Test} probes none of the bare URLs; and
	 * {@see Mcp_Static_Discovery} publishes only the path-suffixed files.
	 *
	 * What it did cover is a client that derives the metadata URL from the host
	 * alone, dropping the path. On a site where ThinkRank is the only MCP
	 * plugin that client worked, and this filter is how such a site keeps it
	 * working. It is opt-in because switching it on is a claim over a shared
	 * URL, and only the site owner knows whether anything else wants it.
	 *
	 * @since 2.10.0
	 *
	 * @return bool
	 */
	public static function serves_root_discovery(): bool {
		/**
		 * Filter whether ThinkRank answers the bare `/.well-known/oauth-*`
		 * discovery URLs, which carry no resource path.
		 *
		 * Off by default: the URL identifies the site root rather than our MCP
		 * endpoint, and claiming it breaks any other MCP plugin on the site.
		 * Switch it on only where ThinkRank is the sole MCP provider and a
		 * client derives the metadata URL from the host without the path.
		 *
		 * @since 2.10.0
		 *
		 * @param bool $serve Whether to register the bare discovery rules.
		 */
		return (bool) apply_filters( 'thinkrank_mcp_serve_root_discovery', false );
	}

	/**
	 * Register our query vars.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::WELLKNOWN_QUERY_VAR;
		$vars[] = self::WELLKNOWN_RESOURCE_QUERY_VAR;
		$vars[] = self::AUTHORIZE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Resolve a root-form discovery request to the site that owns the resource.
	 *
	 * The path-suffixed and issuer-suffixed rules are already pinned to
	 * `thinkrank/mcp`, so only the broad root-form rule can arrive here naming
	 * something else. Three outcomes:
	 *
	 *  - `0` — serve from the current site. That covers the bare form
	 *    (`/.well-known/oauth-authorization-server`, the whole reason the
	 *    fallback rule exists) and this site's own endpoint path.
	 *  - a blog id — a subdirectory multisite request for another site's
	 *    resource. On subdirectory multisite everything under the network root
	 *    is served by the MAIN site, so a client discovering
	 *    `/ca/thinkrank/mcp` lands here; the document has to be built from the
	 *    `/ca/` site or every endpoint in it loses the prefix.
	 *  - `null` — not ours. Another plugin's resource, an unknown site path, or
	 *    a path that merely contains ours.
	 *
	 * @since 2.9.0
	 *
	 * @param \WP $wp The WP request object.
	 * @return int|null Blog id to serve from, 0 for the current site, null to decline.
	 */
	private static function resolve_wellknown_target( $wp ): ?int {
		$requested = isset( $wp->query_vars[ self::WELLKNOWN_RESOURCE_QUERY_VAR ] )
			? trim( (string) $wp->query_vars[ self::WELLKNOWN_RESOURCE_QUERY_VAR ], '/' )
			: '';

		$ours = trim( Mcp_Pairing::SITE_ENDPOINT_PATH, '/' );

		if ( '' === $requested || $requested === $ours ) {
			return 0;
		}

		if ( ! is_multisite() || ! function_exists( 'get_site_by_path' ) ) {
			return null;
		}

		// Whatever precedes our endpoint path is the candidate site path:
		// `ca/thinkrank/mcp` -> `/ca/`. Matching the tail as a whole path
		// segment, not a substring, so `thinkrank/mcp-other` cannot qualify.
		$candidate = '/' . $requested;
		$suffix    = '/' . $ours;

		if ( substr( $candidate, - strlen( $suffix ) ) !== $suffix ) {
			return null;
		}

		$site_path = substr( $candidate, 0, - strlen( $ours ) );
		$domain    = self::request_domain();

		if ( '' === $domain || '' === $site_path ) {
			return null;
		}

		$site = get_site_by_path( $domain, $site_path );

		if ( ! $site ) {
			return null;
		}

		// get_site_by_path() walks the path segments and falls back to the
		// network's root site when none match, so an unknown prefix comes back
		// as the MAIN site rather than as nothing. Taking that at face value
		// reinstates the exact bug for every path that is not a real subsite:
		// /nope/thinkrank/mcp would be answered with the main site's document.
		// Require the match to be the path that was actually asked for.
		if ( untrailingslashit( (string) $site->path ) !== untrailingslashit( $site_path ) ) {
			return null;
		}

		// get_sites() applies no status filter, so a site the network has taken
		// out of service resolves like any other. Advertising an authorization
		// server for one would point a client at an endpoint that cannot serve
		// it. `public` is deliberately NOT checked: on multisite that flag is
		// search-engine visibility, not availability, and a site can reasonably
		// be hidden from search while still running MCP.
		if ( ! empty( $site->archived ) || ! empty( $site->deleted ) || ! empty( $site->spam ) ) {
			return null;
		}

		return (int) $site->blog_id === get_current_blog_id() ? 0 : (int) $site->blog_id;
	}

	/**
	 * Host for a `get_site_by_path()` lookup.
	 *
	 * Mirrors what WordPress itself stores in `wp_blogs`: core strips only the
	 * default ports when it resolves the current site, so a development network
	 * running on a non-default port keeps it, and stripping every port here
	 * would fail to match those rows.
	 *
	 * @since 2.9.0
	 *
	 * @return string Host, or an empty string when the request carries none.
	 */
	private static function request_domain(): string {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );

		if ( ':80' === substr( $host, -3 ) ) {
			return substr( $host, 0, -3 );
		}

		if ( ':443' === substr( $host, -4 ) ) {
			return substr( $host, 0, -4 );
		}

		return $host;
	}

	/**
	 * Build a discovery document from the site that owns the resource.
	 *
	 * The switch is what makes the returned endpoints carry the subsite prefix,
	 * since every URL in the document comes from `home_url()` / `rest_url()`.
	 * Settings memoizes per setting name with no notion of which site it read
	 * from; it clears itself on `switch_blog` (see Settings::init), which is
	 * what stops the MCP-enabled check below answering for the previous site.
	 *
	 * Returns null when the owning site has MCP turned off: a site that is not
	 * serving MCP must not advertise an authorization server for it.
	 *
	 * @since 2.9.0
	 *
	 * @param int    $blog_id Blog to build from, 0 for the current site.
	 * @param string $doc     Document type from the rewrite.
	 * @return array<string,mixed>|null
	 */
	private static function discovery_document_for( int $blog_id, string $doc ): ?array {
		$switched = false;

		if ( $blog_id > 0 ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$data = null;

		try {
			if ( self::is_enabled() ) {
				$data = 'authorization-server' === $doc
					? Mcp_OAuth::authorization_server_metadata()
					: Mcp_OAuth::protected_resource_metadata();
			}
		} finally {
			// A throw between the switch and the restore would leave the rest
			// of the request, including shutdown hooks, running against the
			// wrong site. Cheap to make impossible.
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return $data;
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
			// The path after the document type names the RESOURCE being
			// discovered, and it used to be discarded (#516). Resolve it to
			// the site that actually owns it, which on subdirectory multisite
			// is how /ca/thinkrank/mcp stops being answered by the main site
			// with endpoints that have no /ca/ in them.
			$target = self::resolve_wellknown_target( $wp );

			if ( null === $target ) {
				status_header( 404 );
				exit;
			}

			$data = self::discovery_document_for(
				$target,
				(string) $wp->query_vars[ self::WELLKNOWN_QUERY_VAR ]
			);

			if ( null === $data ) {
				status_header( 404 );
				exit;
			}

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

		// Mark the request so work that only front-end traffic must not pay for
		// can tell this apart from an anonymous page view. An MCP call is a
		// deliberate admin-equivalent action, but it arrives on the pretty
		// /thinkrank/mcp route, so it is neither is_admin() nor REST_REQUEST
		// and was invisible to those checks (#764).
		self::$serving_request = true;

		// The real method, so a GET or DELETE is answered 405 rather than
		// read as a POST with an empty body (#1349).
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'POST';
		$request = new \WP_REST_Request( '' !== $method ? $method : 'POST', '/' . self::NS . '/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		// Carry the auth header + raw body from the live PHP request. The
		// header is the only place a credential is read from: the token
		// that used to ride in the URL path is not (#1001).
		$auth = self::server_header( 'authorization' );
		if ( null !== $auth ) {
			$request->set_header( 'authorization', $auth );
		}
		// One byte past the cap, so the handler can tell a body at the limit
		// from one over it and refuse the latter, rather than silently
		// truncating a large request into a malformed small one (#1001).
		$request->set_body( (string) file_get_contents( 'php://input', false, null, 0, Mcp_Server::MAX_BODY_BYTES + 1 ) );

		$response = Mcp_Server::handle( $request );
		$this->emit_json( $response );
	}

	/**
	 * Whether a REST route is the MCP JSON-RPC fallback.
	 *
	 * Case-insensitive and slash-tolerant, as core's own route match is, so
	 * every spelling that reaches rest_mcp() is caught.
	 *
	 * @since 2.15.0
	 *
	 * @param string $route REST route, e.g. `/thinkrank/v1/mcp`.
	 * @return bool
	 */
	public static function is_mcp_rest_route( string $route ): bool {
		return 0 === strcasecmp( untrailingslashit( $route ), '/' . self::NS . '/mcp' );
	}

	/**
	 * Bound how much of an MCP wp-json request body core reads.
	 *
	 * WP_REST_Server::serve_request() takes the body from get_raw_data(),
	 * which reads all of php://input unless $HTTP_RAW_POST_DATA is already
	 * set. Setting it here, from a read one byte past the cap, keeps core
	 * from reading more than the pretty endpoint does. It works from what
	 * arrives rather than from Content-Length, so a chunked body is bounded
	 * too, and refuse_oversized_rest_body() then answers the 413.
	 *
	 * Runs on `parse_request` ahead of rest_api_loaded(), only when this is
	 * a wp-json request for the MCP route.
	 *
	 * @since 2.15.0
	 *
	 * @param \WP $wp The WP request object.
	 * @return void
	 */
	public function cap_rest_body_read( $wp ): void {
		$route = $wp->query_vars['rest_route'] ?? '';
		if ( ! is_string( $route ) || ! self::is_mcp_rest_route( $route ) ) {
			return;
		}

		// Already set means something read the body first; core uses that.
		if ( isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: get_raw_data() reads this before php://input, and PHP 7+ never sets it.
		$GLOBALS['HTTP_RAW_POST_DATA'] = (string) file_get_contents( 'php://input', false, null, 0, Mcp_Server::MAX_BODY_BYTES + 1 );
	}

	/**
	 * Refuse an MCP wp-json request before core parses its body.
	 *
	 * Core decodes a JSON body in dispatch(), after this filter and before
	 * the route callback. An oversized body that was not valid JSON got
	 * core's 400 rest_invalid_json, and a valid one was decoded in full, only
	 * then to be refused. This answers what the pretty endpoint answers.
	 *
	 * @since 2.15.0
	 *
	 * @param mixed            $result  A response already chosen, or null.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed The refusal, or $result unchanged.
	 */
	public function refuse_oversized_rest_body( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( 'POST' !== $request->get_method() || ! self::is_mcp_rest_route( (string) $request->get_route() ) ) {
			return $result;
		}

		$refused = Mcp_Server::preflight( $request );
		if ( null === $refused ) {
			return $result;
		}

		$refused->header( 'MCP-Protocol-Version', Mcp_Server::PROTOCOL_VERSION );
		return $refused;
	}

	/**
	 * Answer a non-POST request to the MCP wp-json route as the pretty
	 * endpoint does.
	 *
	 * The route is registered for POST only, so core answered a GET (an SDK
	 * opening an SSE stream) or a DELETE (ending a session) with a 404
	 * rest_no_route. Mcp_Server::handle() checks the credential first, so an
	 * unauthenticated call still gets the 401 challenge, and then answers
	 * 405 with `Allow: POST`. Answered here, ahead of route matching, so
	 * core's own Allow header for the route does not replace that one.
	 * OPTIONS is left to core.
	 *
	 * @since 2.18.1
	 *
	 * @param mixed            $result  A response already chosen, or null.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed The answer, or $result unchanged.
	 */
	public function refuse_other_rest_methods( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$method = strtoupper( (string) $request->get_method() );

		if ( 'POST' === $method || 'OPTIONS' === $method || ! self::is_mcp_rest_route( (string) $request->get_route() ) ) {
			return $result;
		}

		return $this->rest_mcp( $request );
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

		// --- Governance (#717): activity log, revert, scopes, expiry ------
		register_rest_route(
			self::NS,
			'/mcp/activity',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_activity' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => self::activity_args() + [
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/activity/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_activity_export' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => self::activity_args(),
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/activity/(?P<id>\\d+)/revert',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_activity_revert' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'force' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Revert even though the value changed after the agent wrote it.',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/governance',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'rest_governance' ],
					'permission_callback' => [ $this, 'admin_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'rest_save_governance' ],
					'permission_callback' => [ $this, 'admin_permission' ],
					'args'                => [
						'retention_days' => [
							'type' => 'integer',
							'enum' => Mcp_Governance::RETENTION_CHOICES,
						],
						'token_ttl_days' => [
							'type' => 'integer',
							'enum' => Mcp_Governance::TOKEN_TTL_CHOICES,
						],
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/apps/scopes',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_set_scopes' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'client_id' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'OAuth client_id, or "static" for the connection token.',
					],
					'levels'    => [
						'type'                 => 'object',
						'required'             => true,
						'properties'           => array_fill_keys(
							Mcp_Scopes::AREAS,
							[
								'type' => 'string',
								'enum' => [ 'none', 'read', 'write' ],
							]
						),
						'additionalProperties' => false,
					],
					// Saved with the areas so the modal never half-applies.
					'expiry_days' => [
						'type'        => 'integer',
						'enum'        => Mcp_Governance::TOKEN_TTL_CHOICES,
						'description' => 'Connection token only: days until it expires, counted from now; 0 = never. Omit to keep the current expiry.',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/token-expiry',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_token_expiry' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'days' => [
						'type'     => 'integer',
						'required' => true,
						'enum'     => Mcp_Governance::TOKEN_TTL_CHOICES,
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/revoke-all',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_revoke_all' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/doctor',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_doctor' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);

		// --- OAuth 2.1 authorization server (the "paste a URL only" path) -
		// Discovery, dynamic client registration, and the token endpoint are
		// all public (permission enforced inside): a client must reach them
		// BEFORE it holds any credential.
		//
		// The discovery documents are ALSO served here, not only at the
		// /.well-known/ rewrites: hosts that resolve root /.well-known/ at
		// their proxy edge (SiteGround) never let those requests reach
		// WordPress, while /wp-json/ always arrives. The 401 challenge
		// advertises this route (Mcp_OAuth::resource_metadata_url), so the
		// flow survives on such hosts.
		register_rest_route(
			self::NS,
			'/mcp/oauth/protected-resource',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_oauth_discovery_resource' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/oauth/authorization-server',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_oauth_discovery_server' ],
				'permission_callback' => '__return_true',
			]
		);
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
	 * Not once the owner has ended the token on purpose (Disconnect, or
	 * Revoke all on a site that had none): a GET right after that used to
	 * hand them a new read+write token nobody asked for (#1075). They create
	 * one again from the screen, which clears the flag.
	 *
	 * The checks here only skip the lock on the common path; self_heal()
	 * makes the decision again under it.
	 *
	 * @since 2.17.1 Skipped once the owner declined a token.
	 *
	 * @return void
	 */
	private function ensure_connected(): void {
		if ( self::is_enabled() && ! Mcp_Pairing::is_connected() && ! Mcp_Pairing::is_declined() ) {
			Mcp_Pairing::self_heal();
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
		// The button's run is kept too, so the verdict the screen shows on
		// its next load is this one rather than an older automatic probe.
		return rest_ensure_response( Mcp_Self_Test::run_and_cache() );
	}

	/**
	 * GET /mcp/doctor — the verdict the MCP screen shows on load: the last
	 * one while it is fresh, otherwise a quick automatic run.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_doctor(): \WP_REST_Response {
		return rest_ensure_response( Mcp_Self_Test::doctor() );
	}

	/**
	 * What the consent screen says about changing the grant later, under
	 * ThinkRank > AI Tools > MCP. ThinkRank Hub's grant can be narrowed there
	 * and never widened back (Connected AI apps), so for the hub the screen
	 * says narrow; any other client's can be changed either way (#1294).
	 *
	 * @since 2.17.2
	 *
	 * @param bool $for_hub Whether the request is ThinkRank Hub's.
	 * @return string
	 */
	public static function consent_later_note( bool $for_hub ): string {
		return $for_hub
			? __( 'Narrow it per area below. You can narrow it further later under ThinkRank > AI Tools > MCP.', 'thinkrank' )
			: __( 'Narrow it per area below. You can change it later under ThinkRank > AI Tools > MCP without reconnecting.', 'thinkrank' );
	}

	/**
	 * Areas an owner can grant on this site. The Pro area is only offered
	 * while ThinkRank Pro is active; a grant stored for it stays as it is.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	private static function visible_areas(): array {
		$areas = Mcp_Scopes::area_labels();
		if ( ! defined( 'THINKRANK_PRO_VERSION' ) ) {
			unset( $areas['pro'] );
		}
		return $areas;
	}

	/**
	 * Filter arguments shared by the activity list and its export.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function activity_args(): array {
		return [
			'client'       => [
				'type'    => 'string',
				'default' => '',
			],
			'tool'         => [
				'type'    => 'string',
				'default' => '',
			],
			'object'       => [
				'type'    => 'string',
				'default' => '',
			],
			'result'       => [
				'type'    => 'string',
				'enum'    => [ '', 'ok', 'error', 'denied' ],
				'default' => '',
			],
			'q'            => [
				'type'    => 'string',
				'default' => '',
			],
			'changes_only' => [
				'type'    => 'boolean',
				'default' => false,
			],
		];
	}

	/**
	 * Filters from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private static function activity_filters( \WP_REST_Request $request ): array {
		return [
			'client'       => sanitize_text_field( (string) $request->get_param( 'client' ) ),
			'tool'         => sanitize_key( (string) $request->get_param( 'tool' ) ),
			'object'       => sanitize_text_field( (string) $request->get_param( 'object' ) ),
			'result'       => (string) $request->get_param( 'result' ),
			'q'            => sanitize_text_field( (string) $request->get_param( 'q' ) ),
			'changes_only' => (bool) $request->get_param( 'changes_only' ),
		];
	}

	/**
	 * GET /mcp/activity — a page of the activity log plus its filter facets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_activity( \WP_REST_Request $request ): \WP_REST_Response {
		$page = Mcp_Audit::query(
			self::activity_filters( $request ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);

		return rest_ensure_response(
			$page + Mcp_Audit::facets() + [
				'available'      => Mcp_Audit::available(),
				'retention_days' => Mcp_Governance::settings()['retention_days'],
			]
		);
	}

	/**
	 * GET /mcp/activity/export — the filtered log as a CSV download,
	 * streamed a chunk at a time rather than built as one string and wrapped
	 * in JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function rest_activity_export( \WP_REST_Request $request ): void {
		$filename = sprintf( 'thinkrank-mcp-activity-%s.csv', gmdate( 'Y-m-d' ) );

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the response body, not a file.
		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			Mcp_Audit::stream_csv( self::activity_filters( $request ), $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the response body, not a file.
			fclose( $out );
		}
		exit;
	}

	/**
	 * POST /mcp/activity/{id}/revert — undo one agent write.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_activity_revert( \WP_REST_Request $request ) {
		$result = Mcp_Audit::revert( (int) $request->get_param( 'id' ), (bool) $request->get_param( 'force' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /mcp/governance — retention and token lifetime.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_governance(): \WP_REST_Response {
		return rest_ensure_response( Mcp_Governance::settings() );
	}

	/**
	 * POST /mcp/governance — save retention and/or token lifetime.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_save_governance( \WP_REST_Request $request ): \WP_REST_Response {
		$patch = [];
		foreach ( [ 'retention_days', 'token_ttl_days' ] as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$patch[ $key ] = (int) $request->get_param( $key );
			}
		}
		return rest_ensure_response( Mcp_Governance::save( $patch ) );
	}

	/**
	 * POST /mcp/apps/scopes — change what one connection may do, and for the
	 * connection token its expiry, in one request so neither half lands
	 * without the other. Applies on the connection's next request; nobody
	 * reconnects.
	 *
	 * A ThinkRank Hub grant is only narrowed here, never widened: giving the
	 * hub more goes back through the Hub card and the consent page
	 * (thinkrank#1088). Mcp_OAuth::set_client_scope() decides,
	 * under its lock, and its 403 is returned as it is.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_set_scopes( \WP_REST_Request $request ) {
		$client_id = (string) $request->get_param( 'client_id' );
		$levels    = (array) $request->get_param( 'levels' );

		if ( Mcp_Session::STATIC_TOKEN === $client_id ) {
			Mcp_Pairing::set_levels( $levels );
			if ( null !== $request->get_param( 'expiry_days' ) ) {
				Mcp_Pairing::set_expiry( (int) $request->get_param( 'expiry_days' ) );
			}
			return rest_ensure_response( $this->apps_payload() );
		}

		$changed = Mcp_OAuth::set_client_scope( $client_id, $levels );

		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		if ( ! $changed ) {
			return new \WP_Error(
				'thinkrank_mcp_unknown_client',
				__( 'That connection no longer has access, so there is nothing to change.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( $this->apps_payload() );
	}

	/**
	 * POST /mcp/token-expiry — set when the connection token expires,
	 * counted from now, without changing the token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_token_expiry( \WP_REST_Request $request ): \WP_REST_Response {
		Mcp_Pairing::set_expiry( (int) $request->get_param( 'days' ) );
		return rest_ensure_response( $this->apps_payload() );
	}

	/**
	 * POST /mcp/revoke-all — the day an owner suspects a leak: every OAuth
	 * app loses access and the connection token is replaced, in one step.
	 * MCP stays switched on, so the owner can reconnect the clients they
	 * trust straight away. A site with no connection token is left without
	 * one, and the screen does not mint one on its next load (#1075).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_revoke_all(): \WP_REST_Response {
		Mcp_OAuth::revoke_all_grants();
		Mcp_Pairing::rotate_existing();

		return rest_ensure_response(
			[
				'connection' => Mcp_Pairing::public_status(),
				'apps'       => $this->apps_payload(),
			]
		);
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
		$stats      = Mcp_Audit::client_stats( 30 );
		$oauth_apps = [];
		foreach ( Mcp_OAuth::connected_apps() as $app ) {
			$user         = $app['user_id'] > 0 ? get_userdata( $app['user_id'] ) : false;
			$oauth_apps[] = [
				'client_id'    => $app['client_id'],
				'name'         => $app['name'],
				'read_only'    => $app['read_only'],
				'levels'       => Mcp_Scopes::level_names( $app['levels'] ),
				'approved_by'  => $user ? $user->display_name : __( 'Unknown user', 'thinkrank' ),
				'connected_at' => $app['connected_at'],
				'last_used'    => $app['last_used'],
				'expires_at'   => $app['expires_at'],
				'activity'     => $stats[ $app['client_id'] ] ?? null,
				// A hub grant is only narrowed from Edit access, so the
				// modal offers it nothing above what it holds (#1088).
				'hub'          => '' !== (string) $app['hub'],
			];
		}

		// The shared connection token, so its scopes, expiry and activity sit
		// next to the OAuth apps. Every header-based client (Claude Code,
		// Cursor, VS Code) uses this one credential.
		$static = null;
		if ( Mcp_Pairing::is_connected() ) {
			$status = Mcp_Pairing::public_status();
			$state  = Mcp_Pairing::state();
			$owner  = $state['user_id'] > 0 ? get_userdata( $state['user_id'] ) : false;
			$static = [
				'client_id'    => Mcp_Session::STATIC_TOKEN,
				'levels'       => $status['levels'],
				'read_only'    => $status['read_only'],
				'approved_by'  => $owner ? $owner->display_name : __( 'Unknown user', 'thinkrank' ),
				'connected_at' => $status['connected_at'],
				'last_used'    => $status['last_used'],
				'expires_at'   => $status['expires_at'],
				'expiry_set'   => $status['expiry_set'],
				'expired'      => $status['expired'],
				'expires_soon' => $status['expires_soon'],
				'activity'     => $stats[ Mcp_Session::STATIC_TOKEN ] ?? null,
			];
		}

		$areas = [];
		foreach ( self::visible_areas() as $key => $area ) {
			$areas[] = [ 'key' => $key ] + $area;
		}

		return [
			'oauth_apps'   => $oauth_apps,
			'static_token' => $static,
			'areas'        => $areas,
			'governance'   => Mcp_Governance::settings(),
		];
	}

	// -- OAuth 2.1 handlers ------------------------------------------------

	/**
	 * GET /mcp/oauth/protected-resource — RFC 9728 metadata via REST.
	 *
	 * `?for=rest` is the document the REST fallback's challenge points at,
	 * naming the fallback as the resource (#1113). Without it, the pretty
	 * endpoint's document, as before.
	 *
	 * @since 2.17.1 Answers `?for=rest` with the fallback's document.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_oauth_discovery_resource( \WP_REST_Request $request ) {
		$address = Mcp_OAuth::FOR_REST === $request->get_param( 'for' ) ? Mcp_OAuth::FOR_REST : '';

		return $this->oauth_discovery_response( Mcp_OAuth::protected_resource_metadata( $address ) );
	}

	/**
	 * GET /mcp/oauth/authorization-server — RFC 8414 metadata via REST.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_oauth_discovery_server() {
		return $this->oauth_discovery_response( Mcp_OAuth::authorization_server_metadata() );
	}

	/**
	 * Shape one discovery document response: public, cacheable, and 404 when
	 * MCP is off — matching the /.well-known/ rewrites exactly, so a client
	 * sees the same truth regardless of which serving path reached it.
	 *
	 * @param array<string,mixed> $document Discovery metadata.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function oauth_discovery_response( array $document ) {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'thinkrank_mcp_disabled', __( 'MCP is disabled on this site.', 'thinkrank' ), [ 'status' => 404 ] );
		}
		$response = new \WP_REST_Response( $document, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	/**
	 * POST /mcp/oauth/register — RFC 7591 dynamic client registration.
	 *
	 * Open to anyone, so each IP gets a bounded number of registrations per
	 * window (#1341), counted only when a client is actually stored. A
	 * refusal is the RFC 7591 §3.2.2 body (`error`, `error_description`).
	 *
	 * @param \WP_REST_Request $request JSON body with redirect_uris.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_oauth_register( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'thinkrank_mcp_disabled', __( 'MCP is disabled on this site.', 'thinkrank' ), [ 'status' => 403 ] );
		}
		if ( Mcp_Rate_Limiter::registration_limited() ) {
			$response = new \WP_REST_Response(
				[
					'error'             => 'too_many_requests',
					'error_description' => __( 'Too many client registrations from this address. Try again later.', 'thinkrank' ),
				],
				429
			);
			$response->header( 'Retry-After', (string) Mcp_Rate_Limiter::registration_retry_after() );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$result = Mcp_OAuth::register_client( $body );
		if ( is_wp_error( $result ) ) {
			$data     = $result->get_error_data();
			$response = new \WP_REST_Response(
				[
					'error'             => isset( $data['error'] ) ? $data['error'] : $result->get_error_code(),
					'error_description' => isset( $data['error_description'] ) ? $data['error_description'] : $result->get_error_message(),
				],
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		Mcp_Rate_Limiter::record_registration();
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a literal after strtoupper(); nothing is stored or echoed.
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		// Params come from GET on the consent link and POST on the form submit.
		// Nonce is verified in consent_outcome() before any POST value is acted on.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read verbatim by oauth_param(); see its docblock for why, and where each value is validated or escaped instead.
		$source = $is_post ? $_POST : $_GET;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$params = [];
		foreach ( [ 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource', 'hub_ticket', 'approve', 'deny', '_thinkrank_oauth_nonce' ] as $k ) {
			$params[ $k ] = self::oauth_param( $source, $k );
		}
		// Per-area choices from the consent form. Each is one of three fixed
		// words, so anything else simply reads as "none".
		$granted = [];
		foreach ( Mcp_Scopes::AREAS as $area ) {
			$granted[ $area ] = Mcp_Scopes::level_from( self::oauth_param( $source, 'area_' . $area ) );
		}
		$has_areas = self::consent_has_areas( $source );

		$outcome = self::consent_outcome( $params, $granted, $has_areas, $is_post );

		switch ( $outcome['action'] ) {
			case 'redirect_error':
				$this->redirect_error( (string) $outcome['redirect_uri'], (string) $outcome['error'], (string) $outcome['description'], (string) $outcome['state'] );
				return;

			case 'refuse':
				$this->emit_oauth_error_page( (string) $outcome['message'], (int) $outcome['status'] );
				return;

			case 'sign_in':
				// Anonymous → wp-login, back to this URL.
				$this->redirect_to_login();
				return;

			case 'deny':
				$this->redirect_error( (string) $outcome['request']['redirect_uri'], 'access_denied', (string) $outcome['description'], (string) $outcome['request']['state'] );
				return;

			case 'approve':
				$code = Mcp_OAuth::issue_code( $outcome['request'], get_current_user_id() );
				$this->redirect_success( (string) $outcome['request']['redirect_uri'], $code, (string) $outcome['request']['state'] );
				return;

			default:
				$this->emit_consent_screen( $outcome['request'] );
		}
	}

	/**
	 * What the consent page should do, decided without doing any of it.
	 *
	 * Separated from the page so the decision can be exercised: the page
	 * itself ends the request in every branch, which is right for a page and
	 * leaves nothing for a test to hold. Nothing here writes, redirects or
	 * prints; the caller does all three. The order of the checks is the
	 * whole of what this returns, and the order is the security property.
	 *
	 * For a client whose return address is ThinkRank Hub's, a valid ticket
	 * minted by the signed-in administrator is required before anything is
	 * offered: without it, anybody could start a connection in their own hub
	 * workspace and send the owner a consent page that is genuine in every
	 * word. A ticket started for reading narrows the grant to read whatever
	 * the client asks (thinkrank#1002).
	 *
	 * @since 2.15.0
	 *
	 * @param array<string,string> $params    The request's own parameters, read verbatim.
	 * @param array<string,int>    $granted   Per-area levels from the form.
	 * @param bool                 $has_areas Whether the form carried per-area choices.
	 * @param bool                 $is_post   Whether the form was submitted.
	 * @return array<string,mixed> `action` plus what that action needs.
	 */
	public static function consent_outcome( array $params, array $granted, bool $has_areas, bool $is_post ): array {
		$req = Mcp_OAuth::validate_authorize_request( $params );

		if ( is_wp_error( $req ) ) {
			$data = $req->get_error_data();

			// Only redirect the error back when redirect_uri is verified valid;
			// otherwise show a page (never bounce to an unverified URL).
			if ( is_array( $data ) && ! empty( $data['redirectable'] ) && '' !== (string) ( $params['redirect_uri'] ?? '' ) ) {
				return [
					'action'       => 'redirect_error',
					'error'        => $req->get_error_code(),
					'description'  => $req->get_error_message(),
					'redirect_uri' => (string) $params['redirect_uri'],
					'state'        => (string) ( $params['state'] ?? '' ),
				];
			}

			return [
				'action'  => 'refuse',
				'message' => $req->get_error_message(),
				'status'  => 400,
			];
		}

		if ( ! is_user_logged_in() ) {
			return [ 'action' => 'sign_in' ];
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return [
				'action'  => 'refuse',
				'message' => __( 'You must be an administrator to authorize an AI assistant to manage SEO on this site.', 'thinkrank' ),
				'status'  => 403,
			];
		}

		// A code for the hub's return address is issued only against a
		// ticket this account minted from the MCP screen.
		$hub_access = '';

		// The public hub, while this site is set to another hub or pinned to
		// an address it cannot use, is neither the hub nor an ordinary app.
		if ( Mcp_Hub::is_unchosen_hub_redirect( (string) $req['redirect_uri'] ) ) {
			return [
				'action'  => 'refuse',
				'message' => Mcp_Hub::pin_is_invalid()
					? __( 'The hub address set in wp-config.php (THINKRANK_HUB_URL) is not an https address this site can use, so it will not connect to any hub until that is corrected.', 'thinkrank' )
					: __( 'This site is set to connect to a different ThinkRank Hub, so it cannot be connected to this one.', 'thinkrank' ),
				'status'  => 403,
			];
		}

		if ( Mcp_Hub::is_hub_redirect( (string) $req['redirect_uri'] ) ) {
			$ticket = Mcp_Hub::ticket( (string) ( $params['hub_ticket'] ?? '' ), get_current_user_id() );

			if ( null === $ticket ) {
				return [
					'action'  => 'refuse',
					'message' => __( 'Connecting this site to ThinkRank Hub starts from this site’s ThinkRank Hub tab, under ThinkRank > AI Tools > ThinkRank Hub, while signed in as the administrator approving it. Start there and the hub brings you back to this page.', 'thinkrank' ),
					'status'  => 403,
				];
			}

			$req['hub_ticket'] = (string) $params['hub_ticket'];
			$hub_access        = (string) $ticket['access'];

			// A connection started as read only stays read only whatever the
			// client asks for.
			if ( Mcp_Hub::ACCESS_READ === $hub_access ) {
				$req['scope'] = 'read';
			}
		}

		$req['hub_access'] = $hub_access;
		$req['may_change'] = Mcp_Hub::ACCESS_READ !== $hub_access;

		if ( ! $is_post ) {
			return [
				'action'  => 'ask',
				'request' => $req,
			];
		}

		if ( ! wp_verify_nonce( (string) ( $params['_thinkrank_oauth_nonce'] ?? '' ), 'thinkrank_oauth_consent' ) ) {
			return [
				'action'  => 'refuse',
				'message' => __( 'Security check failed. Please try connecting again.', 'thinkrank' ),
				'status'  => 403,
			];
		}

		if ( '' === (string) ( $params['approve'] ?? '' ) ) {
			return [
				'action'      => 'deny',
				'request'     => $req,
				'description' => 'The user denied the request.',
			];
		}

		// The admin's choices on the consent screen are the grant, not the
		// scope the client asked for: an area the form didn't post a choice
		// for grants nothing. The screen offers Pro's whenever the client asks
		// for it, active or not (consent_areas(), #1065). A form posted
		// without any choices (an older cached page) keeps the requested scope.
		$levels = $has_areas
			? Mcp_Scopes::normalize_levels( $granted )
			: Mcp_Scopes::levels( Mcp_Scopes::parse( (string) $req['scope'] ) );

		if ( ! $req['may_change'] ) {
			foreach ( $levels as $area => $level ) {
				$levels[ $area ] = min( $level, Mcp_Scopes::READ );
			}
		}

		if ( $has_areas && ! Mcp_Scopes::grants_any_write( $levels ) && ! in_array( Mcp_Scopes::READ, $levels, true ) ) {
			return [
				'action'      => 'deny',
				'request'     => $req,
				'description' => 'No access was granted.',
			];
		}

		// What was actually granted. A hub grant is stored as scope tokens
		// rather than the `mcp` umbrella, because the hub reads `write` from
		// the scope it is handed back.
		if ( '' !== $hub_access ) {
			$req['scope'] = Mcp_OAuth::hub_scope_for_levels( $levels );
		} elseif ( $has_areas ) {
			$req['scope'] = Mcp_OAuth::scope_for_levels( $levels );
		}

		return [
			'action'  => 'approve',
			'request' => $req,
		];
	}

	/**
	 * The areas the consent screen offers a choice for: those the site runs,
	 * and Pro's whenever the client asks for it, even while Pro is inactive,
	 * so an app approved before Pro is activated has Pro's level chosen by
	 * the administrator rather than left at none (#1065). Pro's row then
	 * says it applies once Pro is active. An area the screen doesn't offer
	 * is posted no choice, and grants nothing.
	 *
	 * @since 2.15.0
	 *
	 * @param array<string,int> $requested Per-area levels of the scope the client asked for.
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function consent_areas( array $requested ): array {
		$areas = self::visible_areas();

		if ( ! isset( $areas['pro'] ) && Mcp_Scopes::NONE !== ( $requested['pro'] ?? Mcp_Scopes::NONE ) ) {
			$pro                = Mcp_Scopes::area_labels()['pro'];
			$pro['description'] = trim( $pro['description'] . ' ' . __( 'ThinkRank Pro isn’t active on this site yet: this applies once it is.', 'thinkrank' ) );
			$areas['pro']       = $pro;
		}

		return $areas;
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
		// Not wp_safe_redirect: redirect_uri is a client-registered off-site
		// callback, already validated against the client's registered set.
		wp_redirect( self::callback_url( $redirect_uri, [ 'code' => $code ], $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
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
		wp_redirect( self::callback_url( $redirect_uri, $args, $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/**
	 * The address the client is sent back to, built and not sent.
	 *
	 * One builder for the success and the error path, and every value is
	 * percent-encoded on the way in. The success path used to hand its
	 * arguments to add_query_arg() raw, which builds its query with encoding
	 * switched off: a `state` carrying an ampersand appended a second
	 * parameter to the callback, and a second `code` on the callback is a
	 * code the client did not ask for, arriving beside the one it did
	 * (#1001, BetterShield A7).
	 *
	 * Appended by hand rather than through add_query_arg(), which re-parses
	 * the address: RFC 6749 §4.1.2 adds parameters to the query component of
	 * the registered redirect URI and keeps what is already there, and the
	 * registered address was matched byte for byte, so it goes back out byte
	 * for byte. Public and pure so it can be tested; the two redirects above
	 * end the request.
	 *
	 * @since 2.15.0
	 *
	 * @param string               $redirect_uri Validated client redirect URI.
	 * @param array<string,string> $args         Query parameters to add, raw.
	 * @param string               $state        Client state, added when not empty.
	 * @return string
	 */
	public static function callback_url( string $redirect_uri, array $args, string $state ): string {
		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		$pairs = [];
		foreach ( $args as $key => $value ) {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		// A fragment has no place on a redirect URI (RFC 6749 §3.1.2), but
		// one that carries it is not refused at registration yet, and the
		// query belongs before it rather than inside it.
		$fragment = '';
		$hash     = strpos( $redirect_uri, '#' );
		if ( false !== $hash ) {
			$fragment     = substr( $redirect_uri, $hash );
			$redirect_uri = substr( $redirect_uri, 0, $hash );
		}

		$separator = false === strpos( $redirect_uri, '?' ) ? '?' : '&';

		return $redirect_uri . $separator . implode( '&', $pairs ) . $fragment;
	}

	/**
	 * Whether a consent POST carried the per-area choices.
	 *
	 * @param array<string,mixed> $source POST data.
	 * @return bool
	 */
	private static function consent_has_areas( array $source ): bool {
		foreach ( Mcp_Scopes::AREAS as $area ) {
			if ( isset( $source[ 'area_' . $area ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the consent screen. Minimal self-contained HTML (no admin
	 * chrome — this is a client-facing OAuth page). Approve/Deny post back
	 * to the same authorize URL with a nonce.
	 *
	 * @param array<string,mixed> $req Validated authorize params.
	 * @return void
	 */
	private function emit_consent_screen( array $req ): void {
		$read_only    = Mcp_OAuth::scope_is_read_only( $req['scope'] );
		$access_label = $read_only ? __( 'Requested: read-only', 'thinkrank' ) : __( 'Requested: read & write', 'thinkrank' );
		$access_desc  = $read_only
			? __( 'Review your SEO across posts and site settings metadata, schema, sitemaps, robots, social, and SEO scores. No changes are made.', 'thinkrank' )
			: __( 'Read and improve your SEO across posts and site settings metadata, schema, sitemaps, robots, social, indexing, and SEO scores.', 'thinkrank' );
		$client     = '' !== $req['client_name'] ? $req['client_name'] : __( 'An AI assistant', 'thinkrank' );
		$action_url = Mcp_OAuth::authorize_url();
		$nonce      = wp_create_nonce( 'thinkrank_oauth_consent' );
		$user       = wp_get_current_user();
		// The hub's connection: started on the MCP screen with the access the
		// ticket carries, so a read-only one offers no write anywhere.
		$for_hub    = '' !== (string) ( $req['hub_access'] ?? '' );
		$may_change = ! empty( $req['may_change'] );

		// Preserve every OAuth param so the POST re-validates identically.
		$hidden = '';
		foreach ( [ 'client_id', 'redirect_uri', 'code_challenge', 'scope', 'state', 'resource', 'hub_ticket' ] as $k ) {
			$val     = 'scope' === $k ? $req['scope'] : ( $req[ $k ] ?? '' );
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( (string) $val ) );
		}
		// code_challenge_method + response_type are re-asserted for validation.
		$hidden .= '<input type="hidden" name="code_challenge_method" value="S256" />';
		$hidden .= '<input type="hidden" name="response_type" value="code" />';

		status_header( 200 );
		self::send_oauth_page_headers();

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
			. '.areas .area{cursor:default}.areas .k{display:flex;flex-direction:column;gap:2px}.areas .k b{color:#e7ecf3;font-weight:600}.areas .k small{color:#7f8aa3;font-size:12px}'
			. '.areas select{-webkit-appearance:none;appearance:none;background:#0f1426 url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%239aa6be\' stroke-width=\'2.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpath d=\'m6 9 6 6 6-6\'/%3E%3C/svg%3E") no-repeat right 10px center;color:#e7ecf3;border:1px solid #33405c;border-radius:8px;padding:6px 32px 6px 10px;font-size:13px;min-width:132px;cursor:pointer}'
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
		if ( $for_hub ) {
			// Where the code goes: the hub this site's MCP screen names.
			echo '<div class="row"><span class="k">' . esc_html__( 'Sends you back to', 'thinkrank' ) . '</span><span class="v">' . esc_html( Mcp_Hub::host_of( (string) $req['redirect_uri'] ) ) . '</span></div>';
		}
		echo '<div class="access"><div class="k">' . esc_html__( 'Access', 'thinkrank' ) . '</div>';
		echo '<span class="badge ' . ( $read_only ? 'ro' : '' ) . '">' . esc_html( $access_label ) . '</span>';
		echo '<div class="d">' . esc_html( $access_desc ) . ' ' . esc_html( self::consent_later_note( $for_hub ) ) . '</div></div>';
		echo '</div>';

		// Per-area access, preselected from what the client asked for. The
		// admin can narrow it (or widen it) before approving, and change it
		// later under Connected AI apps without the client reconnecting. A
		// hub connection started for reading only offers no write anywhere.
		$requested = Mcp_Scopes::levels( Mcp_Scopes::parse( $req['scope'] ) );
		$choices   = [
			'none'  => __( 'No access', 'thinkrank' ),
			'read'  => __( 'Read', 'thinkrank' ),
			'write' => __( 'Read & Write', 'thinkrank' ),
		];
		if ( ! $may_change ) {
			unset( $choices['write'] );
		}
		$names = [ 'none', 'read', 'write' ];
		echo '<div class="rows areas">';
		foreach ( self::consent_areas( $requested ) as $area => $info ) {
			$current = $names[ $requested[ $area ] ?? Mcp_Scopes::NONE ];
			echo '<label class="row area"><span class="k"><b>' . esc_html( $info['label'] ) . '</b><small>' . esc_html( $info['description'] ) . '</small></span>';
			echo '<select form="tr-consent" name="' . esc_attr( 'area_' . $area ) . '">';
			foreach ( $choices as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label>';
		}
		echo '</div>';

		echo '<p class="note">' . $lock . '<span>' . esc_html__( 'Secured with OAuth. Revoke it any time under ThinkRank > AI Tools > MCP.', 'thinkrank' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon; text esc_html'd.

		echo '<form id="tr-consent" method="post" action="' . esc_url( $action_url ) . '">';
		echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() above.
		echo '<input type="hidden" name="_thinkrank_oauth_nonce" value="' . esc_attr( $nonce ) . '" />';
		echo '<div class="actions">';
		echo '<button class="deny" name="deny" value="1">' . esc_html__( 'Deny', 'thinkrank' ) . '</button>';
		echo '<button class="approve" name="approve" value="1">' . esc_html__( 'Approve', 'thinkrank' ) . '</button>';
		echo '</div></form></div></body></html>';
		exit;
	}

	/**
	 * Send OAUTH_PAGE_HEADERS, replacing any earlier value of each.
	 *
	 * @since 2.18.0
	 * @return void
	 */
	private static function send_oauth_page_headers(): void {
		foreach ( self::OAUTH_PAGE_HEADERS as $name => $value ) {
			header( $name . ': ' . $value, true );
		}
	}

	/**
	 * Render a standalone OAuth error page (no redirect).
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status: 400 for a malformed request, 403 for a refusal.
	 * @return void
	 */
	private function emit_oauth_error_page( string $message, int $status = 400 ): void {
		status_header( $status );
		self::send_oauth_page_headers();
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Authorization error', 'thinkrank' ) . '</title>';
		echo '<style>body{font:15px/1.5 -apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
			. '.card{background:#1e293b;border:1px solid #334155;border-radius:16px;max-width:440px;padding:32px;text-align:center}</style></head><body>';
		echo '<div class="card"><h1>' . esc_html__( 'Could not authorize', 'thinkrank' ) . '</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	// -- Helpers --

	/**
	 * Read one /authorize parameter verbatim.
	 *
	 * Deliberately NOT sanitize_text_field(). That function exists to make
	 * untrusted text safe to store and display, and part of what it does is
	 * strip %XX sequences as an anti-obfuscation measure. Applied to an OAuth
	 * protocol value it quietly changes the value's meaning.
	 *
	 * The concrete failure: registration stores redirect_uris raw from a JSON
	 * body, but at /authorize the same URI arrives as a query parameter, so
	 * PHP has already URL-decoded it — and sanitising then removed the percent
	 * sequences. A client registered with `.../cb?next=%2Fdashboard` was
	 * compared as `.../cb?next=dashboard`, failed the strict match, and was
	 * told `invalid_redirect_uri` for sending exactly what it registered
	 * (#487). `state` has the same problem: it is opaque to us and must
	 * round-trip byte for byte, or the client aborts its own callback.
	 *
	 * Protocol identifiers want validation and rejection, not cleaning. Every
	 * value read here is constrained somewhere better suited to it:
	 *   - redirect_uri  strict in_array() against the client's registered set
	 *   - client_id     must resolve to a registered client
	 *   - response_type must equal 'code'
	 *   - code_challenge_method must equal 'S256'
	 *   - code_challenge validated against the RFC 7636 character set
	 *   - scope         intersected with SUPPORTED_SCOPES
	 *   - state         opaque; escaped at output (esc_attr / rawurlencode)
	 *   - resource      must name this endpoint, or the request is invalid_target
	 *   - approve/deny  tested for emptiness only
	 *   - the nonce     passed to wp_verify_nonce()
	 *
	 * An array value (`?state[]=x`) reads as absent rather than becoming the
	 * string "Array".
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $source $_GET or $_POST.
	 * @param string              $key    Parameter name.
	 * @return string
	 */
	private static function oauth_param( array $source, string $key ): string {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $source[ $key ] );
	}

	/**
	 * Read an inbound HTTP header from $_SERVER (for the pretty path).
	 *
	 * Mirrors WP_REST_Server::get_headers(): on Apache with CGI/FastCGI/suPHP the
	 * Authorization header never lands in HTTP_AUTHORIZATION. WordPress's own
	 * .htaccess passthrough re-publishes it as REDIRECT_HTTP_AUTHORIZATION, and a
	 * few Apache module setups populate neither key but do answer getallheaders().
	 * The REST route gets this handling from core; the pretty route builds its own
	 * WP_REST_Request, so it has to do the same here or it 401s on those hosts.
	 *
	 * @param string $name Header name.
	 * @return string|null
	 */
	private static function server_header( string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- token compared constant-time downstream; raw header needed verbatim.
		if ( isset( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- as above.
			return wp_unslash( $_SERVER[ $key ] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- as above.
		if ( isset( $_SERVER[ 'REDIRECT_' . $key ] ) && '' !== $_SERVER[ 'REDIRECT_' . $key ] ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- as above.
			return wp_unslash( $_SERVER[ 'REDIRECT_' . $key ] );
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header => $value ) {
					if ( 0 === strcasecmp( (string) $header, $name ) && '' !== (string) $value ) {
						return (string) $value;
					}
				}
			}
		}

		return null;
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
		// Never cached. The pretty endpoint can carry the pairing token in its
		// path, so a shared cache or proxy holding a response keyed on that URL
		// would keep an admin-equivalent credential in its store (#396).
		header( 'Cache-Control: no-store, private' );
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
