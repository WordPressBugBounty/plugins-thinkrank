<?php
/**
 * Abilities registrar.
 *
 * @package ThinkRank\Abilities
 */

declare(strict_types=1);

namespace ThinkRank\Abilities;

use ThinkRank\Abilities\Analysis\Get_Post_Seo_Checks;
use ThinkRank\Abilities\Analysis\Get_Term_Seo_Checks;
use ThinkRank\Abilities\Content\Get_Post_Seo;
use ThinkRank\Abilities\Content\Get_Term_Seo;
use ThinkRank\Abilities\Content\List_Content_Items;
use ThinkRank\Abilities\Content\List_Content_Types;
use ThinkRank\Abilities\Content\Update_Post_Seo;
use ThinkRank\Abilities\Content\Update_Term_Seo;
use ThinkRank\Abilities\Content\Generate_Content_Brief;
use ThinkRank\Abilities\Content\Get_Pillar_Content;
use ThinkRank\Abilities\Content\Update_Pillar_Content;
use ThinkRank\Abilities\Import\Detect_Import_Sources;
use ThinkRank\Abilities\Import\Get_Import_Status;
use ThinkRank\Abilities\Import\Run_Seo_Import;
use ThinkRank\Abilities\Import\Preview_Seo_Import;
use ThinkRank\Abilities\Settings\Get_Global_Settings;
use ThinkRank\Abilities\Settings\Get_Robots_Txt;
use ThinkRank\Abilities\Settings\Get_Sitemap_Settings;
use ThinkRank\Abilities\Settings\Update_Global_Settings;
use ThinkRank\Abilities\Settings\Update_Robots_Txt;
use ThinkRank\Abilities\Settings\Update_Sitemap_Settings;
use ThinkRank\Abilities\Settings\Get_Schema_Settings;
use ThinkRank\Abilities\Settings\Update_Schema_Settings;
use ThinkRank\Abilities\Settings\Get_Site_Identity_Settings;
use ThinkRank\Abilities\Settings\Update_Site_Identity_Settings;
use ThinkRank\Abilities\Settings\Get_Social_Meta_Settings;
use ThinkRank\Abilities\Settings\Update_Social_Meta_Settings;
use ThinkRank\Abilities\Settings\Get_Image_Seo_Settings;
use ThinkRank\Abilities\Settings\Update_Image_Seo_Settings;
use ThinkRank\Abilities\Settings\Get_Llms_Txt_Settings;
use ThinkRank\Abilities\Settings\Update_Llms_Txt_Settings;
use ThinkRank\Abilities\Settings\Get_Robots_Meta_Settings;
use ThinkRank\Abilities\Settings\Update_Robots_Meta_Settings;
use ThinkRank\Abilities\Settings\Get_Instant_Indexing_Settings;
use ThinkRank\Abilities\Settings\Update_Instant_Indexing_Settings;
use ThinkRank\Abilities\Settings\Get_Author_Archives_Settings;
use ThinkRank\Abilities\Settings\Update_Author_Archives_Settings;
use ThinkRank\Abilities\Settings\Get_Email_Report_Settings;
use ThinkRank\Abilities\Settings\Update_Email_Report_Settings;
use ThinkRank\Abilities\Settings\Send_Email_Report_Test;
use ThinkRank\Abilities\Settings\Get_Social_Platforms_Settings;
use ThinkRank\Abilities\Settings\Update_Social_Platforms_Settings;
use ThinkRank\Abilities\Indexing\Submit_Urls_To_Index;
use ThinkRank\Abilities\Indexing\Get_Instant_Indexing_History;
use ThinkRank\Abilities\Analysis\Get_Llms_Txt_Status;
use ThinkRank\Abilities\Analysis\Generate_Llms_Txt;
use ThinkRank\Abilities\Analysis\Publish_Llms_Txt;
use ThinkRank\Abilities\Analysis\Get_Seo_Analytics_Data;
use ThinkRank\Abilities\Analysis\Get_Seo_Insights;
use ThinkRank\Abilities\Analysis\Get_Seo_Opportunities;
use ThinkRank\Abilities\Analysis\Get_Seo_Score;
use ThinkRank\Abilities\Analysis\Get_Seo_Analyzer;
use ThinkRank\Abilities\Analysis\Run_Seo_Analyzer;
use ThinkRank\Abilities\Analysis\Get_Performance_Data;
use ThinkRank\Abilities\Analysis\Get_Integrations_Status;
use ThinkRank\Abilities\Analysis\Get_Connection_Status;
use ThinkRank\Abilities\Analysis\Bulk_Analyze_And_Save;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers ThinkRank abilities with the WordPress Abilities API.
 *
 * Abilities are **always** registered when the Abilities API is available —
 * registration is decoupled from the `enable_mcp` toggle (see #187). Each
 * ability is a permission-checked read/write surface (its own
 * `current_user_can()` callback runs on every call), so registration alone
 * exposes nothing; it only makes ThinkRank discoverable to generic WordPress
 * Abilities clients (e.g. an external connector) the same way WordPress core
 * abilities are. The `enable_mcp` toggle gates only ThinkRank's own MCP server
 * ({@see \ThinkRank\Mcp\Mcp_Manager}) — the endpoint, discovery documents, and
 * OAuth surface. The MCP server reads this abilities registry as its tool
 * catalog. The Abilities API itself ships in `dependencies/` and no-ops
 * gracefully when missing.
 *
 * A kill switch remains available via the `thinkrank_abilities_api_enabled`
 * filter (defaults to `true`; see {@see Ability_Base::abilities_enabled()}).
 */
class Abilities_Registrar {

	/**
	 * Ability-name prefixes that mark an ability as ThinkRank's. The free
	 * plugin owns `thinkrank/`; Pro registers under `thinkrank-pro/` via the
	 * `thinkrank_register_abilities` filter.
	 */
	public const ABILITY_PREFIXES = [ 'thinkrank/', 'thinkrank-pro/' ];

	/**
	 * Whether the registration replay (see {@see self::ensure_registered()})
	 * has already run this request. One attempt only — a replay that produced
	 * nothing will not produce anything on the second try either, and the MCP
	 * server asks for the tool list more than once per request.
	 *
	 * @var bool
	 */
	private static $replayed = false;

	/**
	 * Initialize the registrar (called by the plugin's component container).
	 *
	 * Deliberately does NOT bail on a missing `wp_register_ability`: the
	 * Abilities API is a set of GLOBAL functions loaded under
	 * `function_exists` guards, so which copy owns them — ours in
	 * `dependencies/`, another plugin's, or core's — is decided by load order,
	 * not by us. A copy that lands after `plugins_loaded` would have made this
	 * an early return and left ThinkRank permanently unregistered (see #241).
	 * The callbacks themselves are guarded instead, so hooking unconditionally
	 * is free when no Abilities API ever shows up.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Guarantee ThinkRank's abilities are in the registry, replaying
	 * registration once if they are not.
	 *
	 * `wp_abilities_api_init` fires exactly once, from the lazy registry
	 * singleton of whichever Abilities API copy owns the globals. If a foreign
	 * copy owns them and fires its init under a different name or at a moment
	 * when our hook is not attached yet, our callback never runs: the registry
	 * is populated by everyone else and `tools/list` answers with an empty
	 * array while auth, discovery and `initialize` all report success (#241).
	 *
	 * Calling `wp_get_abilities()` here forces that lazy init, so by the time
	 * we decide to replay, `wp_abilities_api_init` has fired and
	 * `wp_register_ability()` will accept our registrations. Registration is
	 * idempotent (each ability is skipped when `wp_has_ability()` already
	 * knows it), so a replay after a *successful* hook run is a no-op anyway.
	 *
	 * @param callable|null $replay Optional replay routine, for tests. Default
	 *                              is this class's own category + abilities
	 *                              registration.
	 * @return int Number of ThinkRank abilities registered afterwards.
	 */
	public static function ensure_registered( ?callable $replay = null ): int {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		// Forces the registry's lazy init (and with it `wp_abilities_api_init`).
		$count = self::count_registered();
		if ( $count > 0 || self::$replayed ) {
			return $count;
		}

		// Before the registry has initialized, `wp_register_ability()` refuses
		// the registration and calls `_doing_it_wrong()`. Nothing to replay yet.
		if ( ! function_exists( 'did_action' ) || ! did_action( 'wp_abilities_api_init' ) ) {
			return $count;
		}

		self::$replayed = true;

		if ( ! Ability_Base::abilities_enabled() ) {
			return 0;
		}

		if ( null === $replay ) {
			$registrar = new self();
			$replay    = static function () use ( $registrar ) {
				$registrar->register_category();
				$registrar->register_abilities();
			};
		}

		$replay();

		$count = self::count_registered();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic.
				'[TR-MCP] ThinkRank abilities were missing from the registry; replayed registration. ' . self::summary()
			);
		}

		return $count;
	}

	/**
	 * How many ThinkRank abilities the registry currently holds.
	 *
	 * @return int
	 */
	public static function count_registered(): int {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		$count = 0;
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			foreach ( self::ABILITY_PREFIXES as $prefix ) {
				if ( 0 === strpos( (string) $ability->get_name(), $prefix ) ) {
					++$count;
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Which file defines the global Abilities API functions for this request.
	 * A path outside ThinkRank's `dependencies/` means a foreign copy owns the
	 * registry — the precondition for #241.
	 *
	 * @return string Absolute path, or '' when the API is absent/unresolvable.
	 */
	public static function owner_path(): string {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return '';
		}

		try {
			$reflection = new \ReflectionFunction( 'wp_get_abilities' );
			return (string) $reflection->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
	}

	/**
	 * Diagnostic snapshot of the Abilities API as this request sees it. Feeds
	 * the MCP self-test and the debug log so "no tools registered" is
	 * distinguishable from "tools filtered out" without shell access.
	 *
	 * @return array{api_available:bool, owner:string, foreign:bool, hook_fired:bool, total:int, thinkrank:int, replayed:bool}
	 */
	public static function diagnostics(): array {
		$available = function_exists( 'wp_get_abilities' );
		$owner     = self::owner_path();
		$bundled   = defined( 'THINKRANK_PLUGIN_DIR' ) ? THINKRANK_PLUGIN_DIR : '';

		// Read the registry BEFORE `hook_fired`: `wp_get_abilities()` forces the
		// lazy singleton's init (which fires `wp_abilities_api_init`). Reading
		// `did_action()` first would report `hook_fired => false` in the same
		// snapshot that already counts registered abilities — an internally
		// inconsistent line for the exact support scenario this feeds.
		$total     = $available ? count( wp_get_abilities() ) : 0;
		$thinkrank = self::count_registered();

		return [
			'api_available' => $available,
			'owner'         => $owner,
			'foreign'       => ( '' !== $owner && '' !== $bundled && 0 !== strpos( $owner, $bundled ) ),
			'hook_fired'    => function_exists( 'did_action' ) ? (bool) did_action( 'wp_abilities_api_init' ) : false,
			'total'         => $total,
			'thinkrank'     => $thinkrank,
			'replayed'      => self::$replayed,
		];
	}

	/**
	 * One-line, human-readable form of {@see self::diagnostics()}.
	 *
	 * @return string
	 */
	public static function summary(): string {
		$d = self::diagnostics();

		return sprintf(
			'Abilities API: %s; owner: %s%s; abilities total: %d, thinkrank: %d; init fired: %s; replayed: %s',
			$d['api_available'] ? 'present' : 'missing',
			'' !== $d['owner'] ? $d['owner'] : 'unknown',
			$d['foreign'] ? ' (foreign copy — not ThinkRank\'s bundled runtime)' : '',
			$d['total'],
			$d['thinkrank'],
			$d['hook_fired'] ? 'yes' : 'no',
			$d['replayed'] ? 'yes' : 'no'
		);
	}

	/**
	 * Register the ThinkRank ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'thinkrank' ) ) {
			return;
		}

		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'thinkrank',
				[
					'label'       => __( 'ThinkRank', 'thinkrank' ),
					'description' => __( 'SEO settings, metadata, and analysis abilities powered by ThinkRank.', 'thinkrank' ),
				]
			);
		}
	}

	/**
	 * Register ThinkRank abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! Ability_Base::abilities_enabled() ) {
			return;
		}

		$abilities = [
			new List_Content_Types(),
			new List_Content_Items(),
			new Get_Global_Settings(),
			new Update_Global_Settings(),
			new Get_Post_Seo(),
			new Update_Post_Seo(),
			new Get_Term_Seo(),
			new Update_Term_Seo(),
			new Get_Post_Seo_Checks(),
			new Get_Term_Seo_Checks(),
			new Get_Robots_Txt(),
			new Update_Robots_Txt(),
			new Get_Sitemap_Settings(),
			new Update_Sitemap_Settings(),
			new Get_Schema_Settings(),
			new Update_Schema_Settings(),
			new Get_Site_Identity_Settings(),
			new Update_Site_Identity_Settings(),
			new Get_Social_Meta_Settings(),
			new Update_Social_Meta_Settings(),
			new Get_Image_Seo_Settings(),
			new Update_Image_Seo_Settings(),
			new Get_Llms_Txt_Settings(),
			new Update_Llms_Txt_Settings(),
			new Get_Robots_Meta_Settings(),
			new Update_Robots_Meta_Settings(),
			new Get_Instant_Indexing_Settings(),
			new Update_Instant_Indexing_Settings(),
			new Get_Author_Archives_Settings(),
			new Update_Author_Archives_Settings(),
			new Get_Email_Report_Settings(),
			new Update_Email_Report_Settings(),
			new Send_Email_Report_Test(),
			new Get_Social_Platforms_Settings(),
			new Update_Social_Platforms_Settings(),
			new Submit_Urls_To_Index(),
			new Get_Instant_Indexing_History(),
			new Get_Llms_Txt_Status(),
			new Generate_Llms_Txt(),
			new Publish_Llms_Txt(),
			new Get_Seo_Analytics_Data(),
			new Get_Seo_Insights(),
			new Get_Seo_Opportunities(),
			new Get_Seo_Score(),
			new Get_Seo_Analyzer(),
			new Run_Seo_Analyzer(),
			new Get_Performance_Data(),
			new Get_Integrations_Status(),
			new Get_Connection_Status(),
			new Bulk_Analyze_And_Save(),
			new Generate_Content_Brief(),
			new Get_Pillar_Content(),
			new Update_Pillar_Content(),
			new Detect_Import_Sources(),
			new Get_Import_Status(),
			new Run_Seo_Import(),
			new Preview_Seo_Import(),
		];

		$abilities = apply_filters( 'thinkrank_register_abilities', $abilities );

		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof Ability_Base ) {
				continue;
			}

			if ( ! $ability->meets_capability_policy() || ! $ability->is_enabled() ) {
				continue;
			}

			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability->get_id() ) ) {
				continue;
			}

			$ability->register();
		}
	}
}
