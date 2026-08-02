<?php
/**
 * Connection status reporter.
 *
 * @package ThinkRank\Diagnostics
 */

declare(strict_types=1);

namespace ThinkRank\Diagnostics;

use ThinkRank\Core\Capability_Manager;
use ThinkRank\Core\Plan_Config;
use ThinkRank\Database\Database_Schema;
use ThinkRank\Mcp\Mcp_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds a read-only integration health report for ThinkRank.
 *
 * A single source of truth consumed by the `thinkrank/get-connection-status`
 * ability and the `GET /thinkrank/v1/connection-status` REST route. It reports
 * whatever an external MCP/Abilities client needs to distinguish a certificate
 * problem from an authentication problem from a permission problem from an
 * ability-registration problem — without reading plugin source or DB tables by
 * hand (see #188).
 *
 * The report is side-effect free: it makes no outbound HTTP request and never
 * returns secret material (API keys, OAuth tokens, connection tokens). Actively
 * exercising the round trip (a live "Test Connection") belongs to the
 * integration screen tracked in #189.
 */
class Connection_Status {

	/**
	 * Build the full connection-status report.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		return [
			'plugin'       => self::plugin_info(),
			'abilities'    => self::abilities_info(),
			'mcp_server'   => self::mcp_info(),
			'user'         => self::user_info(),
			'urls'         => self::analyze_schemes( home_url(), site_url(), rest_url() ),
			'database'     => self::database_info(),
			'scoring'      => self::scoring_info(),
		];
	}

	/**
	 * ThinkRank + ThinkRank Pro versions and whether Pro is active.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_info(): array {
		return [
			'thinkrank_version' => defined( 'THINKRANK_VERSION' ) ? THINKRANK_VERSION : null,
			'pro_active'        => Plan_Config::is_pro(),
			'pro_version'       => defined( 'THINKRANK_PRO_VERSION' ) ? THINKRANK_PRO_VERSION : null,
		];
	}

	/**
	 * Abilities API availability + which ThinkRank abilities are registered.
	 *
	 * @return array<string, mixed>
	 */
	private static function abilities_info(): array {
		$api_available = function_exists( 'wp_register_ability' ) && function_exists( 'wp_get_abilities' );
		$names         = [];

		if ( $api_available ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
					continue;
				}
				$name = (string) $ability->get_name();
				if ( 0 === strpos( $name, 'thinkrank/' ) || 0 === strpos( $name, 'thinkrank-pro/' ) ) {
					$names[] = $name;
				}
			}
			sort( $names );
		}

		return [
			'api_available'   => $api_available,
			'registered'      => count( $names ) > 0,
			'thinkrank_count' => count( $names ),
			'names'           => $names,
		];
	}

	/**
	 * MCP server toggle + the endpoints a client would connect to.
	 *
	 * @return array<string, mixed>
	 */
	private static function mcp_info(): array {
		return [
			'enabled'      => class_exists( Mcp_Manager::class ) ? Mcp_Manager::is_enabled() : false,
			'endpoint'     => home_url( '/thinkrank/mcp' ),
			'rest_fallback' => rest_url( 'thinkrank/v1/mcp' ),
		];
	}

	/**
	 * Current user + the ThinkRank capabilities they hold.
	 *
	 * @return array<string, mixed>
	 */
	private static function user_info(): array {
		$user = wp_get_current_user();

		return [
			'id'                     => get_current_user_id(),
			'login'                  => ( $user && $user->exists() ) ? $user->user_login : '',
			'is_admin'               => current_user_can( 'manage_options' ),
			'thinkrank_capabilities' => class_exists( Capability_Manager::class )
				? Capability_Manager::user_capabilities()
				: [],
		];
	}

	/**
	 * ThinkRank custom-table status: version, whether an update is pending,
	 * and per-table existence.
	 *
	 * @return array<string, mixed>
	 */
	private static function database_info(): array {
		if ( ! class_exists( Database_Schema::class ) ) {
			return [
				'installed_version' => null,
				'expected_version'  => null,
				'up_to_date'        => false,
				'tables'            => [],
			];
		}

		$schema = new Database_Schema();
		$status = $schema->get_database_status();

		$tables = [];
		foreach ( (array) ( $status['tables'] ?? [] ) as $name => $info ) {
			$tables[ $name ] = ! empty( $info['exists'] );
		}

		return [
			'installed_version' => $status['version'] ?? null,
			'expected_version'  => $schema->get_schema_version(),
			'up_to_date'        => empty( $status['needs_update'] ),
			'tables'            => $tables,
		];
	}

	/**
	 * Whether scoring (and saved-score storage) is available: the scoring
	 * ability is registered and the analysis table exists.
	 *
	 * @return array<string, bool>
	 */
	private static function scoring_info(): array {
		$ability_available = function_exists( 'wp_has_ability' )
			? (bool) wp_has_ability( 'thinkrank/get-seo-score' )
			: false;

		$table_exists = false;
		if ( class_exists( Database_Schema::class ) ) {
			$status       = ( new Database_Schema() )->get_database_status();
			$table_exists = ! empty( $status['tables']['seo_analysis']['exists'] );
		}

		return [
			'ability_available'     => $ability_available,
			'analysis_table_exists' => $table_exists,
		];
	}

	/**
	 * Compare the URL schemes WordPress uses. A `home`/`siteurl` scheme
	 * mismatch (or a REST URL whose scheme differs from `home`) is the classic
	 * cause of an MCP client's requests bouncing between HTTP and HTTPS.
	 *
	 * Pure function of its inputs (native `parse_url` only) so it is unit
	 * testable without a WordPress runtime.
	 *
	 * @param string $home_url The `home` URL.
	 * @param string $site_url The `siteurl` URL.
	 * @param string $rest_url The REST API base URL.
	 * @return array<string, mixed>
	 */
	public static function analyze_schemes( string $home_url, string $site_url, string $rest_url ): array {
		$home = strtolower( (string) parse_url( $home_url, PHP_URL_SCHEME ) );
		$site = strtolower( (string) parse_url( $site_url, PHP_URL_SCHEME ) );
		$rest = strtolower( (string) parse_url( $rest_url, PHP_URL_SCHEME ) );

		return [
			'home'               => $home_url,
			'siteurl'            => $site_url,
			'rest_url'           => $rest_url,
			'home_scheme'        => $home,
			'siteurl_scheme'     => $site,
			'rest_scheme'        => $rest,
			'is_https'           => 'https' === $home,
			'home_siteurl_match' => '' !== $home && $home === $site,
			'rest_matches_home'  => '' !== $rest && $rest === $home,
		];
	}
}
