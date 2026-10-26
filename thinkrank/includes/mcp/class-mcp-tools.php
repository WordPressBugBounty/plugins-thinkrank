<?php
/**
 * MCP tool registry — exposes ThinkRank's registered abilities as MCP tools.
 *
 * Unlike a hand-written catalog, the tool surface here is the WordPress
 * Abilities API registry: every `thinkrank/*` (and Pro `thinkrank-pro/*`)
 * ability becomes one MCP tool. Its own input and output schemas are the
 * tool's `inputSchema` and `outputSchema`, its own description is the tool's
 * description and its own annotations are the tool's `annotations`, so the
 * catalog an assistant reads and the surface this plugin offers cannot drift
 * apart. Consumed by Mcp_Server for both tools/list and tools/call.
 *
 * Tool naming: MCP tool names may not contain `/`, so the category prefix
 * (`thinkrank/` or `thinkrank-pro/`) is stripped — the ability
 * `thinkrank/get-post-seo` is the tool `get-post-seo`. A bare name belongs to
 * the first ability registered under it: a second ability of the same bare
 * name is left out of the catalog, never reached by invoke(), and reported
 * once per request with _doing_it_wrong() naming both ids (#1003), so a bare
 * name resolves back to exactly one ability.
 *
 * Scope model: read or write is decided by the ability's own `readonly`
 * annotation and never by its name (#1000). An ability is a read tool when it
 * declares `readonly: true` and a write tool in every other case, the
 * unannotated one included. A prefix rule read the wrong answer off
 * `get-seo-score`, which computes a score and then saves it, and the right one
 * off `detect-import-sources` only by accident. Each tool also belongs to an
 * area (Mcp_Scopes), and a credential is granted none, read or write per area.
 * tools/list advertises only the tools the calling credential may use, and
 * tools/call refuses the rest, so an agent never plans around a tool it cannot
 * call (#717).
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

use ThinkRank\Abilities\Abilities_Registrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Bridges the Abilities API registry to the MCP tool surface.
 */
final class Mcp_Tools {

	/**
	 * Ability name prefixes that mark a ThinkRank ability exposed over MCP.
	 * The free plugin owns `thinkrank/`; ThinkRank Pro registers its abilities
	 * under `thinkrank-pro/` via the `thinkrank_register_abilities` filter.
	 */
	private const ABILITY_PREFIXES = [ 'thinkrank/', 'thinkrank-pro/' ];

	/**
	 * Tools that write when one argument is set, by that argument. Only ever
	 * adds a write: a tool that does not declare `readonly: true` is a write
	 * whatever it is sent. `get-seo-score` declares itself a write (#1000), so
	 * its entry is a guard, kept so `save_score` stays behind write access
	 * if that declaration is ever relaxed to a read (#927 review).
	 */
	private const WRITE_ARGUMENTS = [ 'get-seo-score' => 'save_score' ];

	/**
	 * Per-call read-only override. Null means "defer to the pairing token's
	 * scope". true/false is set by Mcp_Server when an OAuth access token
	 * (with its own scope) authorized the request.
	 *
	 * @var bool|null
	 */
	private static $read_only_override = null;

	/**
	 * Ids of the abilities already reported as left out for a taken name, so
	 * a collision is said once per request however often the catalog is built.
	 *
	 * @var array<string, true>
	 */
	private static $reported = [];

	/**
	 * Set the active credential's read-only state for the current request.
	 * Passing null clears the override (back to the pairing-token default).
	 *
	 * @param bool|null $read_only Whether the active credential is read-only.
	 * @return void
	 */
	public static function set_read_only_override( ?bool $read_only ): void {
		self::$read_only_override = $read_only;
	}

	/**
	 * Whether the active MCP credential is limited to read-only tools.
	 *
	 * Public because the initialize instructions tell the assistant which
	 * scope this session has, so it can read that as a granted limitation
	 * rather than retrying a tool that will keep refusing (#491).
	 *
	 * @return bool
	 */
	public static function is_read_only(): bool {
		if ( null !== self::$read_only_override ) {
			return self::$read_only_override;
		}
		if ( Mcp_Session::active() ) {
			return ! Mcp_Scopes::grants_any_write( Mcp_Session::levels() );
		}
		return Mcp_Pairing::is_read_only();
	}

	/**
	 * The tool list in MCP `tools/list` shape, built from the abilities
	 * registry.
	 *
	 * Each entry carries the ability's annotations as the protocol's hints and
	 * its output schema where it declares one. The hints are hints: the
	 * specification tells clients not to trust them, and nothing here is
	 * enforced with them. What decides what a credential may call is the scope
	 * check, which reads the same `readonly` declaration the hint is made
	 * from, so the two cannot disagree.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list(): array {
		$out    = [];
		$levels = Mcp_Session::levels();
		foreach ( self::abilities() as $name => $ability ) {
			$is_write = ! self::is_read_tool( $ability );
			if ( ! Mcp_Scopes::allows( $levels, $name, $is_write, $ability->get_name() ) ) {
				continue;
			}
			$schema = $ability->get_input_schema();
			$entry  = [
				'name'        => $name,
				'description' => $ability->get_description(),
				'inputSchema' => ! empty( $schema ) ? self::normalize_schema( $schema ) : [
					'type'       => 'object',
					'properties' => (object) [],
				],
			];

			$output = method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null;
			if ( is_array( $output ) && [] !== $output ) {
				$entry['outputSchema'] = self::normalize_schema( $output );
			}

			$entry['annotations'] = self::annotations_of( $ability );

			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Normalize a JSON Schema for MCP clients: an empty PHP `properties` array
	 * JSON-encodes as `[]`, but the schema spec (and the MCP SDK's validator)
	 * requires an OBJECT — `{}`. Recurse first so nested object schemas (e.g.
	 * a no-field `settings` sub-object) are fixed too.
	 *
	 * @param array<string,mixed> $schema JSON Schema node.
	 * @return array<string,mixed>
	 */
	private static function normalize_schema( array $schema ): array {
		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$schema[ $key ] = self::normalize_schema( $value );
			}
		}
		if ( isset( $schema['properties'] ) && [] === $schema['properties'] ) {
			$schema['properties'] = (object) [];
		}
		return $schema;
	}

	/**
	 * Invoke a tool by name with decoded arguments. The ability's own input
	 * validation and permission callback run inside WP_Ability::execute()
	 * (the authenticated credential's user is already set by Mcp_Server).
	 *
	 * @param string $name Tool name (ability name sans `thinkrank/` prefix).
	 * @param array  $args Decoded arguments.
	 * @return mixed|\WP_Error Result payload or error.
	 */
	public static function invoke( string $name, array $args ) {
		$denied = self::check_access( $name, $args );
		if ( null !== $denied ) {
			return $denied;
		}

		return self::resolve( $name )->execute( $args );
	}

	/**
	 * Resolve a bare tool name to its ability: the one registered first under
	 * that name (see abilities()), or null for a name nobody registered.
	 *
	 * Read off the registry's own list rather than asked for by name, so a
	 * tool name a client chose never reaches wp_get_ability(), which reports
	 * an unregistered name as a misuse.
	 *
	 * @param string $name Bare tool name.
	 * @return \WP_Ability|null
	 */
	public static function resolve( string $name ) {
		return self::abilities()[ $name ] ?? null;
	}

	/**
	 * Why the calling credential may not run a tool, or null when it may.
	 *
	 * Public so the activity log can tell a call that will run from one that
	 * will be refused before it snapshots anything.
	 *
	 * @param string               $name Bare tool name.
	 * @param array<string, mixed> $args Arguments the agent sent.
	 * @return \WP_Error|null
	 */
	public static function check_access( string $name, array $args = [] ): ?\WP_Error {
		$ability = self::resolve( $name );

		if ( ! $ability ) {
			return new \WP_Error(
				'thinkrank_mcp_unknown_tool',
				sprintf(
					/* translators: %s: tool name. */
					__( 'Unknown tool: %s', 'thinkrank' ),
					$name
				),
				[ 'status' => 404 ]
			);
		}

		$is_write = self::is_write_call( $name, $args );

		// A credential with no write anywhere keeps the original read-only
		// refusal, so clients and docs that know that code keep working.
		if ( $is_write && self::is_read_only() ) {
			return new \WP_Error(
				'thinkrank_mcp_read_only',
				sprintf(
					/* translators: %s: tool name. */
					__( 'This MCP connection is read-only; the "%s" tool changes state and is not permitted. Ask the site administrator to grant write access under ThinkRank > AI Tools > MCP > Connected AI apps (it applies on the next request), or reconnect with write access.', 'thinkrank' ),
					$name
				),
				[ 'status' => 403 ]
			);
		}

		if ( ! Mcp_Scopes::allows( Mcp_Session::levels(), $name, $is_write, $ability->get_name() ) ) {
			return self::scope_denied( $name, Mcp_Scopes::required_scope( $name, $is_write, $ability->get_name() ) );
		}

		// A tool whose calls change different areas (fix-seo-check, the
		// robots keys of update-site-identity-settings) also needs every
		// area this call's arguments name. The refusal names each one the
		// credential lacks, as a scope string, so one grant is enough.
		$missing = Mcp_Scopes::missing_argument_areas( Mcp_Session::levels(), $name, $args, $is_write );
		if ( [] !== $missing ) {
			$suffix = $is_write ? ':write' : ':read';
			return self::scope_denied(
				$name,
				implode(
					' ',
					array_map(
						static function ( string $area ) use ( $suffix ): string {
							return $area . $suffix;
						},
						$missing
					)
				)
			);
		}

		return null;
	}

	/**
	 * The refusal for a call outside the credential's areas.
	 *
	 * @param string $name  Bare tool name.
	 * @param string $scope The scope the call needs, such as content:write.
	 * @return \WP_Error
	 */
	private static function scope_denied( string $name, string $scope ): \WP_Error {
		return new \WP_Error(
			'thinkrank_mcp_scope_denied',
			sprintf(
				/* translators: 1: tool name, 2: scope such as content:write. */
				__( 'This MCP connection does not have the %2$s scope that the "%1$s" tool needs. It is the access the site administrator granted, not a fault: ask them to grant %2$s under ThinkRank > AI Tools > MCP > Connected AI apps. The change applies on the next request, without reconnecting.', 'thinkrank' ),
				$name,
				$scope
			),
			[
				'status' => 403,
				'scope'  => $scope,
			]
		);
	}

	/**
	 * Whether a tool changes state, as its own ability declares it.
	 *
	 * Asked by name for is_write_call(), which has only the name and the
	 * arguments of the call. The question is is_read_tool()'s, so a name
	 * nobody registered is a write: the direction a default has to fail in.
	 *
	 * @param string $name Tool name (sans prefix).
	 * @return bool
	 */
	public static function is_write_tool( string $name ): bool {
		return ! self::is_read_tool( self::resolve( $name ) );
	}

	/**
	 * Whether this call, with these arguments, changes state. The one answer
	 * both the access check and the activity log use, so a call is never let
	 * through as a read and then logged as one while it writes (#927 review).
	 *
	 * @param string               $name Tool name (sans prefix).
	 * @param array<string, mixed> $args Arguments the agent sent.
	 * @return bool
	 */
	public static function is_write_call( string $name, array $args ): bool {
		if ( self::is_write_tool( $name ) ) {
			return true;
		}
		$flag = self::WRITE_ARGUMENTS[ $name ] ?? null;
		return null !== $flag && ! empty( $args[ $flag ] );
	}

	/**
	 * Whether an ability is a read tool: its declared `readonly` is exactly
	 * true.
	 *
	 * A missing annotation, a null, a string, something that cannot be asked:
	 * all of them are a write tool. The registry's own annotation defaults are
	 * null rather than false, so an ability nobody annotated arrives here
	 * indistinguishable from one nobody thought about, and both belong behind
	 * write scope. This is the one place the read-or-write question is
	 * answered: list(), check_access() and the activity log all ask it here.
	 *
	 * @param mixed $ability A WP_Ability, or anything else.
	 * @return bool
	 */
	public static function is_read_tool( $ability ): bool {
		return true === ( self::declared_annotations( $ability )['readonly'] ?? null );
	}

	/**
	 * The protocol's tool annotations, from the ability's own declaration.
	 *
	 * `title` is the ability's label. `readOnlyHint` is what is_read_tool()
	 * answers, so the hint a client shows and the scope this server enforces
	 * are one and the same. An undeclared `destructive` is answered with the
	 * protocol's own cautious default (true for a write, false for a read)
	 * rather than with a promise the ability never made; an undeclared
	 * `idempotent` or `openWorldHint` is false.
	 *
	 * @param object $ability Ability.
	 * @return array<string, bool|string>
	 */
	private static function annotations_of( $ability ): array {
		$declared = self::declared_annotations( $ability );
		$readonly = self::is_read_tool( $ability );

		$annotations = [];

		$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
		if ( '' !== $label ) {
			$annotations['title'] = $label;
		}

		$annotations['readOnlyHint']    = $readonly;
		$annotations['destructiveHint'] = isset( $declared['destructive'] ) ? (bool) $declared['destructive'] : ! $readonly;
		$annotations['idempotentHint']  = ! empty( $declared['idempotent'] );
		$annotations['openWorldHint']   = ! empty( $declared['openWorldHint'] );

		return $annotations;
	}

	/**
	 * The annotations an ability declared, however the runtime exposes them.
	 *
	 * @param mixed $ability A WP_Ability, or anything else.
	 * @return array<string, mixed>
	 */
	private static function declared_annotations( $ability ): array {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta_item' ) ) {
			return [];
		}
		$annotations = $ability->get_meta_item( 'annotations', [] );
		return is_array( $annotations ) ? $annotations : [];
	}

	/**
	 * Every registered ThinkRank ability, keyed by bare tool name.
	 *
	 * The first ability registered under a bare name owns it. A later one
	 * (Pro's `thinkrank-pro/x` beside the free `thinkrank/x`) is left out, so
	 * the catalog names `x` once and a call to `x` reaches the ability the
	 * catalog described, and is reported once per request through
	 * _doing_it_wrong() naming both ids: the only fix is a rename on the
	 * registering side (#1003).
	 *
	 * @return array<string, \WP_Ability> Bare tool name => ability.
	 */
	private static function abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		// Our abilities reach the registry through `wp_abilities_api_init`,
		// which fires once from whichever Abilities API copy owns the global
		// functions. When a foreign copy owns them our callback can be missed
		// entirely, leaving this filter with nothing to match and the client
		// with a connected-but-empty tool list (#241). This replays the
		// registration once, and is a no-op on a healthy request.
		Abilities_Registrar::ensure_registered();

		$out = [];
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$id   = (string) $ability->get_name();
			$name = self::strip_prefix( $id );
			if ( null === $name ) {
				continue;
			}
			if ( isset( $out[ $name ] ) ) {
				self::report_collision( $name, (string) $out[ $name ]->get_name(), $id );
				continue;
			}
			$out[ $name ] = $ability;
		}
		return $out;
	}

	/**
	 * Say once per request that an ability is not offered because its bare
	 * name was already taken.
	 *
	 * A developer notice rather than a log line: the two ids are the whole
	 * message, and the fix is a rename on the side that registered second.
	 *
	 * @param string $name   Bare tool name both abilities map to.
	 * @param string $first  Id of the ability that owns the name.
	 * @param string $second Id of the ability left out.
	 * @return void
	 */
	private static function report_collision( string $name, string $first, string $second ): void {
		if ( isset( self::$reported[ $second ] ) ) {
			return;
		}
		self::$reported[ $second ] = true;

		if ( ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			esc_html(
				sprintf(
					/* translators: 1: ability id left out, 2: MCP tool name, 3: ability id that owns the name. */
					__( 'The ability %1$s is not offered over MCP: its tool name %2$s is already taken by %3$s. Register it under another name.', 'thinkrank' ),
					$second,
					$name,
					$first
				)
			),
			'2.15.0'
		);
	}

	/**
	 * Strip a recognized ThinkRank ability prefix, returning the bare tool name.
	 * Returns null when the ability is not one of ours (so callers can filter).
	 *
	 * @param string $ability_name Full ability name, e.g. `thinkrank-pro/get-redirects`.
	 * @return string|null Bare name (`get-redirects`) or null if not a ThinkRank ability.
	 */
	private static function strip_prefix( string $ability_name ): ?string {
		foreach ( self::ABILITY_PREFIXES as $prefix ) {
			if ( 0 === strpos( $ability_name, $prefix ) ) {
				return substr( $ability_name, strlen( $prefix ) );
			}
		}
		return null;
	}
}
