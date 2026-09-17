<?php
/**
 * Purge caches ability.
 *
 * @package ThinkRank\Abilities\Maintenance
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Maintenance;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Clears ThinkRank's own caches.
 *
 * ThinkRank caches Google and analytics responses, schema output and the site
 * audit, so "it is stale, clear it" is a real question — and it had no
 * supported answer at all: no ability, and no admin control either (#676).
 *
 * The recurrence is the argument for it rather than any single report. #629 was
 * sitemap regeneration silently deferred to cron, and #235 was the Search
 * Console property list cached for thirty minutes with no way to invalidate it;
 * both would have been self-service with this.
 *
 * Scoped rather than a single blunt flush, so clearing a stale Search Console
 * list does not also throw away an hour-old site audit that costs real work to
 * rebuild.
 */
class Purge_Caches extends Ability_Base {

	/**
	 * Scope => human label, and the order they are reported in.
	 *
	 * @var array<string, string>
	 */
	private const SCOPES = [
		'analyzer'     => 'Site SEO Analyzer audit',
		'schema'       => 'Schema output cache',
		'ai'           => 'AI response cache',
		'integrations' => 'Google integration responses',
		'content_type' => 'Content Type Matrix resolution',
		'transients'   => 'ThinkRank transients',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/purge-caches';
		$this->label       = __( 'Purge ThinkRank Caches', 'thinkrank' );
		$this->description = __( 'Clear ThinkRank\'s cached data when it is showing something stale — the site audit, schema output, AI responses, Google integration responses, or all of them. Pass scopes to clear only what you need; omit it to clear everything. Nothing is deleted except caches: settings and content are untouched, and each cache rebuilds on the next request. After clearing the analyzer scope, use run-seo-analyzer to rebuild the audit immediately rather than waiting for the next request.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Caches only. Nothing a user authored is lost, and everything
			// cleared is rebuilt on demand — the cost is latency, not data.
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 0.5,
			'openWorldHint' => false,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'scopes' => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => array_keys( self::SCOPES ),
					],
					'description' => __( 'Which caches to clear. Omit to clear all of them.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'cleared' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Scopes that were cleared.', 'thinkrank' ),
				],
				'skipped' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Scopes whose subsystem is not present on this install, so there was nothing to clear.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$input     = (array) $input;
		$requested = isset( $input['scopes'] ) && is_array( $input['scopes'] ) && ! empty( $input['scopes'] )
			? array_values( array_intersect( array_keys( self::SCOPES ), array_map( 'strval', $input['scopes'] ) ) )
			: array_keys( self::SCOPES );

		$cleared = [];
		$skipped = [];

		foreach ( $requested as $scope ) {
			if ( $this->purge( $scope ) ) {
				$cleared[] = $scope;
			} else {
				$skipped[] = $scope;
			}
		}

		return [
			'success' => true,
			'cleared' => $cleared,
			'skipped' => $skipped,
		];
	}

	/**
	 * Clear one scope.
	 *
	 * @param string $scope Scope key.
	 * @return bool True when the subsystem existed and was cleared.
	 */
	private function purge( string $scope ): bool {
		switch ( $scope ) {
			case 'analyzer':
				if ( ! class_exists( 'ThinkRank\\SEO\\SEO_Analyzer' ) ) {
					return false;
				}
				( new \ThinkRank\SEO\SEO_Analyzer() )->flush_cache();
				return true;

			case 'schema':
				if ( ! class_exists( 'ThinkRank\\SEO\\Schema_Cache_Manager' ) ) {
					return false;
				}
				( new \ThinkRank\SEO\Schema_Cache_Manager() )->clear_all();
				return true;

			case 'ai':
				if ( ! class_exists( 'ThinkRank\\AI\\Cache_Manager' ) ) {
					return false;
				}
				( new \ThinkRank\AI\Cache_Manager() )->clear_all();
				return true;

			case 'integrations':
				if ( ! method_exists( 'ThinkRank\\API\\Integrations_Endpoint', 'purge_search_console_sites_cache' ) ) {
					return false;
				}
				\ThinkRank\API\Integrations_Endpoint::purge_search_console_sites_cache();
				return true;

			case 'content_type':
				if ( ! method_exists( 'ThinkRank\\SEO\\Content_Type_Settings', 'flush_cache' ) ) {
					return false;
				}
				\ThinkRank\SEO\Content_Type_Settings::flush_cache();
				return true;

			case 'transients':
				$this->purge_transients();
				return true;
		}

		return false;
	}

	/**
	 * Delete ThinkRank's prefixed transients.
	 *
	 * Mirrors the sweep Deactivator runs, so the two cannot disagree about what
	 * counts as a ThinkRank transient.
	 *
	 * @return void
	 */
	private function purge_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting the cache rows themselves; there is no core API for a prefix sweep.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				  WHERE option_name LIKE %s
					 OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_thinkrank_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_thinkrank_' ) . '%'
			)
		);

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'thinkrank' );
		}
	}
}
