<?php
/**
 * Update sitemap settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Sitemap_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank XML sitemap settings.
 *
 * Sitemap settings are persisted through the Sitemap_Generator SEO manager.
 */
class Update_Sitemap_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-sitemap-settings';
		$this->label       = __( 'Update ThinkRank Sitemap Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank XML sitemap settings: per-type inclusion toggles, exclusion lists, and the index/splitting, styling, filename and search-engine ping options. Read the current values with get-sitemap-settings first; only the keys you pass are changed.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => true,
			'idempotent'    => true,
			'priority'      => 2.0,
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
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Sitemap settings to update.', 'thinkrank' ),
					'properties'  => Settings_Key_Map::sitemap(),
				],
			],
			'required'             => [ 'settings' ],
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
				'message' => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_missing_sitemap_settings_payload',
				__( 'A sitemap settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$gen    = new Sitemap_Generator();
		$merged = $gen->get_settings( 'site', null );
		$patch  = Settings_Key_Map::coerce( Settings_Key_Map::sitemap(), $settings );
		$merged = array_merge( $merged, $patch );

		if ( empty( $patch ) ) {
			return new \WP_Error(
				'thinkrank_no_valid_sitemap_setting_keys',
				__( 'No valid sitemap setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $gen->save_settings( 'site', null, $merged );

		// Rebuild the served sitemap so the change takes effect instead of going
		// stale until an unrelated content edit (debounced).
		if ( $result ) {
			$gen->schedule_regeneration();
		}

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Sitemap settings updated.', 'thinkrank' )
				: __( 'Failed to update sitemap settings.', 'thinkrank' ),
		];
	}
}
