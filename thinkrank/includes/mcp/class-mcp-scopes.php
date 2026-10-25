<?php
/**
 * MCP scopes — what a credential may read and change, per area.
 *
 * The original model was a single read/write pair: a credential could either
 * change everything an ability touches or nothing. That is too coarse for the
 * question an owner actually asks before connecting an agent ("may it edit my
 * posts' SEO but leave the sitemap and robots.txt alone?"), so access is split
 * into areas, each granted `none`, `read` or `write`:
 *
 *   content    post and term SEO, post bodies and slugs, alt text, FAQ,
 *              pillar flags, content reads
 *   settings   global defaults, site identity, schema, social, sitemap, imports
 *   indexing   robots.txt, IndexNow, llms.txt
 *   analytics  scores, audits, Search Console data, the MCP activity log
 *   pro        every ThinkRank Pro module (Pro may split this further through
 *              the `thinkrank_mcp_tool_area` filter)
 *
 * Scope strings keep the legacy forms working. `read` grants every area read,
 * `write` (and the OAuth umbrella `mcp`) every area write, so a credential
 * minted before areas existed behaves exactly as it did. Per-area grants are
 * `content:read`, `settings:write` and so on; write implies read.
 *
 * Pure: no WordPress state is read here except through the one filter, so the
 * whole model is unit-testable.
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
 * Area scope model for the ThinkRank MCP server.
 */
final class Mcp_Scopes {

	/**
	 * Access levels, ordered so a numeric compare answers "at least".
	 */
	public const NONE  = 0;
	public const READ  = 1;
	public const WRITE = 2;

	/**
	 * Every area a credential can be granted, in display order.
	 */
	public const AREAS = [ 'content', 'settings', 'indexing', 'analytics', 'pro' ];

	/**
	 * Tools any authenticated credential may call regardless of its areas.
	 *
	 * The session instructions tell every assistant to start with these, and
	 * neither reveals anything an area guards: get-connection-status reports
	 * plugin health, list-content-types the registered post types.
	 */
	private const ALWAYS_ALLOWED = [ 'get-connection-status', 'list-content-types' ];

	/**
	 * Area of every free tool, by bare tool name.
	 *
	 * Deliberately explicit rather than derived from the ability's directory:
	 * an area decides who can change what, so a new tool must be placed on
	 * purpose. McpScopesTest fails when a registered free ability is missing
	 * from this map, and an unmapped tool falls back to `settings` in the
	 * meantime rather than becoming callable by everyone.
	 */
	private const TOOL_AREAS = [
		// Content: per-post and per-term SEO, media, content reads.
		'list-content-items'               => 'content',
		'get-post-seo'                     => 'content',
		'update-post-seo'                  => 'content',
		'get-term-seo'                     => 'content',
		'update-term-seo'                  => 'content',
		'get-post-content'                 => 'content',
		'update-post-content'              => 'content',
		'restore-post-content'             => 'content',
		'update-post-slug'                 => 'content',
		'list-snippet-issues'              => 'content',
		'get-duplicate-snippets'           => 'content',
		'get-thin-content'                 => 'content',
		'list-images'                      => 'content',
		'get-image-alt-text'               => 'content',
		'get-image-alt-preview'            => 'content',
		'generate-image-alt-draft'          => 'content',
		'apply-image-alt-text'              => 'content',
		'restore-image-alt-text'            => 'content',
		'update-image-alt-text'            => 'content',
		'fill-missing-alt-text'            => 'content',
		'get-faq'                          => 'content',
		'update-faq'                       => 'content',
		'get-pillar-content'               => 'content',
		'update-pillar-content'            => 'content',
		'generate-content-brief'           => 'content',
		'bulk-analyze-and-save'            => 'content',

		// Settings: site-wide configuration.
		'get-global-settings'              => 'settings',
		'update-global-settings'           => 'settings',
		'get-sitemap-settings'             => 'settings',
		'update-sitemap-settings'          => 'settings',
		'get-schema-settings'              => 'settings',
		'update-schema-settings'           => 'settings',
		'get-site-identity-settings'       => 'settings',
		'update-site-identity-settings'    => 'settings',
		'get-social-meta-settings'         => 'settings',
		'update-social-meta-settings'      => 'settings',
		'get-social-platforms-settings'    => 'settings',
		'update-social-platforms-settings' => 'settings',
		'get-image-seo-settings'           => 'settings',
		'update-image-seo-settings'        => 'settings',
		'get-robots-meta-settings'         => 'settings',
		'update-robots-meta-settings'      => 'settings',
		'get-external-links-settings'      => 'settings',
		'update-external-links-settings'   => 'settings',
		'get-content-type-matrix'          => 'settings',
		'update-content-type-matrix'       => 'settings',
		'update-thin-content-settings'     => 'settings',
		'get-ai-budget'                    => 'settings',
		'update-ai-budget'                 => 'settings',
		'get-author-archives-settings'     => 'settings',
		'update-author-archives-settings'  => 'settings',
		'get-email-report-settings'        => 'settings',
		'update-email-report-settings'     => 'settings',
		'send-email-report-test'           => 'settings',
		'purge-caches'                     => 'settings',
		'detect-import-sources'            => 'settings',
		'get-import-status'                => 'settings',
		'preview-seo-import'               => 'settings',
		'run-seo-import'                   => 'settings',
		// The analyzer's one-click fixes are settings changes. Each call also
		// needs the area of the fix it names: see ARGUMENT_AREAS.
		'fix-seo-check'                    => 'settings',

		// Indexing: what crawlers and search engines are told.
		'get-robots-txt'                   => 'indexing',
		'update-robots-txt'                => 'indexing',
		'get-instant-indexing-settings'    => 'indexing',
		'update-instant-indexing-settings' => 'indexing',
		'submit-urls-to-index'             => 'indexing',
		'get-instant-indexing-history'     => 'indexing',
		'get-llms-txt-settings'            => 'indexing',
		'update-llms-txt-settings'         => 'indexing',
		'get-llms-txt-status'              => 'indexing',
		'generate-llms-txt'                => 'indexing',
		'publish-llms-txt'                 => 'indexing',

		// Analytics: reports, scores, audits and the activity log.
		'get-seo-analytics-data'           => 'analytics',
		'get-seo-opportunities'            => 'analytics',
		'get-seo-score'                    => 'analytics',
		'get-seo-analyzer'                 => 'analytics',
		'run-seo-analyzer'                 => 'analytics',
		'get-performance-data'             => 'analytics',
		'get-integrations-status'          => 'analytics',
		'get-post-seo-checks'              => 'analytics',
		'get-term-seo-checks'              => 'analytics',
		'get-mcp-activity'                 => 'analytics',
	];

	/**
	 * Tools that change a different area depending on their arguments.
	 *
	 * Two shapes of rule. `areas` keys on the value of one argument:
	 * `fix-seo-check` applies whichever one-click fix its `check_id` names,
	 * and the fixes land in different areas, in the words of area_labels():
	 * image alt text is content; search visibility, the AI crawler rules in
	 * robots.txt and llms.txt are indexing; the sitemap and schema are
	 * settings. A value the map does not name is held to `settings`, as an
	 * unmapped tool is.
	 *
	 * `keys` keys on which keys one object argument carries:
	 * `update-site-identity-settings` is a settings tool, but three of the
	 * keys it accepts are what the Indexing area is for — `allow_search_engines`
	 * decides whether robots.txt blocks every crawler, and `robots_txt_enabled`
	 * and `ai_crawler_rules` rewrite or delete the physical robots.txt file
	 * through sync_robots_txt_file(). A connection approved for Settings only
	 * could do through this tool what it is refused through update-robots-txt
	 * (#1139). Keys the rule does not name need nothing beyond the tool's own
	 * area: the Indexing area is the crawl surface — robots.txt, IndexNow,
	 * llms.txt — so `feed_noindex` (a noindex directive, like the Robots Meta
	 * settings, which are a settings tool), `query_protection` and
	 * `canonical_scheme` (URL hygiene) stay with the tool's own area on
	 * purpose. A key is counted by presence, not value: coerce() casts a
	 * null to the type's zero, so `allow_search_engines: null` is a write
	 * that blocks every crawler.
	 *
	 * The tool is listed by its own area in TOOL_AREAS, and a call also needs
	 * every area its arguments name, so a connection narrowed to settings
	 * cannot fill alt text, publish llms.txt or unblock crawlers through one.
	 * McpScopesTest holds every key named here to a property of the
	 * ability's schema, so a renamed key cannot leave the gate behind.
	 *
	 * @since 2.15.0
	 */
	private const ARGUMENT_AREAS = [
		'fix-seo-check'                 => [
			'argument' => 'check_id',
			'areas'    => [
				'search_visibility' => 'indexing',
				'xml_sitemap'       => 'settings',
				'schema'            => 'settings',
				'image_alt_text'    => 'content',
				'ai_crawler_access' => 'indexing',
				'llms_txt'          => 'indexing',
			],
		],
		'update-site-identity-settings' => [
			'argument' => 'settings',
			'keys'     => [
				'allow_search_engines' => 'indexing',
				'robots_txt_enabled'   => 'indexing',
				'ai_crawler_rules'     => 'indexing',
			],
		],
	];

	/**
	 * The areas one call changes besides its tool's own, from its arguments.
	 * Empty when the tool's area depends on none of them. Each area once, in
	 * the order the rule names them.
	 *
	 * @since 2.15.0
	 *
	 * @param string              $tool Bare tool name.
	 * @param array<string,mixed> $args Arguments the agent sent.
	 * @return string[]
	 */
	public static function argument_areas_of( string $tool, array $args ): array {
		if ( ! isset( self::ARGUMENT_AREAS[ $tool ] ) ) {
			return [];
		}

		$rule  = self::ARGUMENT_AREAS[ $tool ];
		$value = $args[ $rule['argument'] ] ?? null;

		if ( isset( $rule['keys'] ) ) {
			$areas = [];
			foreach ( $rule['keys'] as $key => $area ) {
				if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
					$areas[ $area ] = true;
				}
			}
			return array_keys( $areas );
		}

		$value = is_scalar( $value ) ? (string) $value : '';

		return [ $rule['areas'][ $value ] ?? 'settings' ];
	}

	/**
	 * The areas a call's arguments name that the credential does not hold
	 * at the level the call needs; empty when it holds them all. These are
	 * the scopes a refusal tells the agent to ask for, all at once, so a
	 * call naming two areas is not refused twice.
	 *
	 * @since 2.15.0
	 *
	 * @param array<string,int>   $levels   Area => level.
	 * @param string              $tool     Bare tool name.
	 * @param array<string,mixed> $args     Arguments the agent sent.
	 * @param bool                $is_write Whether the call changes state.
	 * @return string[]
	 */
	public static function missing_argument_areas( array $levels, string $tool, array $args, bool $is_write ): array {
		$need    = $is_write ? self::WRITE : self::READ;
		$missing = [];
		foreach ( self::argument_areas_of( $tool, $args ) as $area ) {
			if ( ( $levels[ $area ] ?? self::NONE ) < $need ) {
				$missing[] = $area;
			}
		}
		return $missing;
	}

	/**
	 * Whether a credential holds every area one call changes besides its
	 * tool's own (see argument_areas_of()).
	 *
	 * @since 2.15.0
	 *
	 * @param array<string,int>   $levels   Area => level.
	 * @param string              $tool     Bare tool name.
	 * @param array<string,mixed> $args     Arguments the agent sent.
	 * @param bool                $is_write Whether the call changes state.
	 * @return bool
	 */
	public static function allows_arguments( array $levels, string $tool, array $args, bool $is_write ): bool {
		return [] === self::missing_argument_areas( $levels, $tool, $args, $is_write );
	}

	/**
	 * The free tools whose area also depends on an argument, for the
	 * coverage test and the docs.
	 *
	 * @since 2.15.0
	 *
	 * @return array<string,array{argument:string,areas?:array<string,string>,keys?:array<string,string>}>
	 */
	public static function argument_areas(): array {
		return self::ARGUMENT_AREAS;
	}

	/**
	 * Every scope token the OAuth server advertises and accepts.
	 *
	 * @return string[]
	 */
	public static function supported_tokens(): array {
		$tokens = [ 'mcp', 'read', 'write' ];
		foreach ( self::AREAS as $area ) {
			$tokens[] = $area . ':read';
			$tokens[] = $area . ':write';
		}
		return $tokens;
	}

	/**
	 * Resolve a list of scope tokens to a level per area.
	 *
	 * Unknown tokens are ignored rather than rejected, so a client asking for
	 * a scope this version does not know still gets what it does know.
	 *
	 * @param string[] $tokens Scope tokens.
	 * @return array<string,int> Area => level.
	 */
	public static function levels( array $tokens ): array {
		$levels = array_fill_keys( self::AREAS, self::NONE );

		foreach ( $tokens as $token ) {
			$token = strtolower( trim( (string) $token ) );

			if ( 'mcp' === $token || 'write' === $token ) {
				return array_fill_keys( self::AREAS, self::WRITE );
			}

			if ( 'read' === $token ) {
				foreach ( $levels as $area => $level ) {
					$levels[ $area ] = max( $level, self::READ );
				}
				continue;
			}

			$parts = explode( ':', $token );
			if ( 2 !== count( $parts ) || ! array_key_exists( $parts[0], $levels ) ) {
				continue;
			}

			$wanted = 'write' === $parts[1] ? self::WRITE : ( 'read' === $parts[1] ? self::READ : self::NONE );
			$levels[ $parts[0] ] = max( $levels[ $parts[0] ], $wanted );
		}

		return $levels;
	}

	/**
	 * Split a space-separated OAuth scope string into tokens.
	 *
	 * @param string $scope Scope string.
	 * @return string[]
	 */
	public static function parse( string $scope ): array {
		$parts = preg_split( '/\s+/', trim( $scope ) );
		return array_values( array_filter( is_array( $parts ) ? $parts : [], 'strlen' ) );
	}

	/**
	 * The canonical token list for a level map.
	 *
	 * Full access and read-everything are written in their legacy forms, so a
	 * grant nobody narrowed stores exactly what it stored before areas existed
	 * and every existing reader of the stored value keeps working.
	 *
	 * @param array<string,int> $levels Area => level.
	 * @return string[]
	 */
	public static function tokens( array $levels ): array {
		$levels = self::normalize_levels( $levels );

		if ( ! in_array( self::NONE, $levels, true ) && ! in_array( self::READ, $levels, true ) ) {
			return [ 'read', 'write' ];
		}
		if ( ! in_array( self::NONE, $levels, true ) && ! in_array( self::WRITE, $levels, true ) ) {
			return [ 'read' ];
		}

		$tokens = [];
		foreach ( $levels as $area => $level ) {
			if ( self::WRITE === $level ) {
				$tokens[] = $area . ':write';
			} elseif ( self::READ === $level ) {
				$tokens[] = $area . ':read';
			}
		}
		return $tokens;
	}

	/**
	 * Fill missing areas with NONE and clamp levels, so callers can pass a
	 * partial map straight from a request.
	 *
	 * @param array<string,mixed> $levels Area => level (or level name).
	 * @return array<string,int>
	 */
	public static function normalize_levels( array $levels ): array {
		$out = array_fill_keys( self::AREAS, self::NONE );
		foreach ( $out as $area => $unused ) {
			if ( ! array_key_exists( $area, $levels ) ) {
				continue;
			}
			$out[ $area ] = self::level_from( $levels[ $area ] );
		}
		return $out;
	}

	/**
	 * Accept a level as an int or as its name ('none' | 'read' | 'write').
	 *
	 * @param mixed $value Level.
	 * @return int
	 */
	public static function level_from( $value ): int {
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			$map = [
				'none'  => self::NONE,
				'read'  => self::READ,
				'write' => self::WRITE,
			];
			return $map[ strtolower( $value ) ] ?? self::NONE;
		}
		return max( self::NONE, min( self::WRITE, (int) $value ) );
	}

	/**
	 * Level names, for REST payloads.
	 *
	 * @param array<string,int> $levels Area => level.
	 * @return array<string,string>
	 */
	public static function level_names( array $levels ): array {
		$names = [ 'none', 'read', 'write' ];
		$out   = [];
		foreach ( self::normalize_levels( $levels ) as $area => $level ) {
			$out[ $area ] = $names[ $level ];
		}
		return $out;
	}

	/**
	 * Whether any area grants write.
	 *
	 * @param array<string,int> $levels Area => level.
	 * @return bool
	 */
	public static function grants_any_write( array $levels ): bool {
		return in_array( self::WRITE, self::normalize_levels( $levels ), true );
	}

	/**
	 * Whether every area grants write (the unrestricted credential).
	 *
	 * @param array<string,int> $levels Area => level.
	 * @return bool
	 */
	public static function grants_everything( array $levels ): bool {
		return ! in_array( self::NONE, self::normalize_levels( $levels ), true )
			&& ! in_array( self::READ, self::normalize_levels( $levels ), true );
	}

	/**
	 * The area a tool belongs to, or '' for a tool every credential may call.
	 *
	 * @param string $tool         Bare tool name (no `thinkrank/` prefix).
	 * @param string $ability_name Full ability name, when known.
	 * @return string
	 */
	public static function area_of( string $tool, string $ability_name = '' ): string {
		if ( in_array( $tool, self::ALWAYS_ALLOWED, true ) ) {
			return '';
		}

		if ( isset( self::TOOL_AREAS[ $tool ] ) ) {
			$area = self::TOOL_AREAS[ $tool ];
		} elseif ( 0 === strpos( $ability_name, 'thinkrank-pro/' ) ) {
			$area = 'pro';
		} else {
			// Unmapped free tool: guarded as a settings change, never left open.
			$area = 'settings';
		}

		/**
		 * Filters the scope area an MCP tool belongs to.
		 *
		 * ThinkRank Pro can move a module's tools into another area here.
		 * Returning an area that does not exist leaves the tool callable by
		 * no one, which is the safe failure. An empty string is ignored:
		 * only the built-in orientation tools are open to every credential.
		 *
		 * @since 2.15.0
		 *
		 * @param string $area         Area: content, settings, indexing, analytics or pro.
		 * @param string $tool         Bare tool name.
		 * @param string $ability_name Full ability name.
		 */
		$filtered = function_exists( 'apply_filters' )
			? apply_filters( 'thinkrank_mcp_tool_area', $area, $tool, $ability_name )
			: $area;

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $area;
	}

	/**
	 * The free tool map, for the coverage test and the docs.
	 *
	 * @return array<string,string>
	 */
	public static function tool_areas(): array {
		return self::TOOL_AREAS;
	}

	/**
	 * The scope a call needs, as a token (`content:write`), or '' when the
	 * tool is always allowed.
	 *
	 * @param string $tool         Bare tool name.
	 * @param bool   $is_write     Whether the tool changes state.
	 * @param string $ability_name Full ability name.
	 * @return string
	 */
	public static function required_scope( string $tool, bool $is_write, string $ability_name = '' ): string {
		$area = self::area_of( $tool, $ability_name );
		return '' === $area ? '' : $area . ( $is_write ? ':write' : ':read' );
	}

	/**
	 * Whether a credential with these levels may call a tool.
	 *
	 * @param array<string,int> $levels       Area => level.
	 * @param string            $tool         Bare tool name.
	 * @param bool              $is_write     Whether the tool changes state.
	 * @param string            $ability_name Full ability name.
	 * @return bool
	 */
	public static function allows( array $levels, string $tool, bool $is_write, string $ability_name = '' ): bool {
		$area = self::area_of( $tool, $ability_name );
		if ( '' === $area ) {
			return true;
		}
		$have = $levels[ $area ] ?? self::NONE;
		return $have >= ( $is_write ? self::WRITE : self::READ );
	}

	/**
	 * Human labels for each area, for the admin screen and consent page.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function area_labels(): array {
		return [
			'content'   => [
				'label'       => __( 'Content', 'thinkrank' ),
				'description' => __( 'Post and term SEO, post bodies and URL slugs, image alt text, FAQ and pillar content.', 'thinkrank' ),
			],
			'settings'  => [
				'label'       => __( 'Settings', 'thinkrank' ),
				'description' => __( 'Global defaults, site identity, schema, social, sitemaps and imports.', 'thinkrank' ),
			],
			'indexing'  => [
				'label'       => __( 'Indexing', 'thinkrank' ),
				'description' => __( 'robots.txt, IndexNow submissions and llms.txt.', 'thinkrank' ),
			],
			'analytics' => [
				'label'       => __( 'Analytics', 'thinkrank' ),
				'description' => __( 'SEO scores, audits, Search Console data and the MCP activity log.', 'thinkrank' ),
			],
			'pro'       => [
				'label'       => __( 'Pro modules', 'thinkrank' ),
				'description' => __( 'Redirects, locations, rank tracking and the other ThinkRank Pro tools.', 'thinkrank' ),
			],
		];
	}
}
