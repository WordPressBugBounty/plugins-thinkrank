<?php
/**
 * Update site identity settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Site_Identity_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank site identity settings.
 *
 * Only the non-robots identity keys are writable here. The robots.txt keys
 * (robots_txt_enabled, allow_search_engines, robots_txt_content) are never
 * touched so this ability cannot clobber the robots.txt configuration.
 */
class Update_Site_Identity_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-site-identity-settings';
		$this->label       = __( 'Update ThinkRank Site Identity Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank site identity settings: the homepage/category/tag/author/search/archive title templates, site name and description, breadcrumb configuration, brand imagery, homepage hero, and the business details behind LocalBusiness schema. Robots.txt contents and schema toggles are never modified by this ability. Read the current values with get-site-identity-settings first; only the keys you pass are changed.', 'thinkrank' );
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
	 * Build the JSON schema properties for site identity settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_properties() {
		return Settings_Key_Map::site_identity();
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
					'description' => __( 'Site identity settings to update.', 'thinkrank' ),
					'properties'  => self::schema_properties(),
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
				'thinkrank_missing_site_identity_settings_payload',
				__( 'A site identity settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$mgr    = new Site_Identity_Manager();
		$merged = $mgr->get_settings( 'site', null );
		$patch  = Settings_Key_Map::coerce( Settings_Key_Map::site_identity(), $settings );
		$merged = array_merge( $merged, $patch );

		if ( empty( $patch ) ) {
			return new \WP_Error(
				'thinkrank_no_valid_site_identity_setting_keys',
				__( 'No valid site identity setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $mgr->save_settings( 'site', null, $merged );

		if ( $result ) {
			return [
				'success' => true,
				'message' => __( 'Site identity settings updated.', 'thinkrank' ),
			];
		}

		// The manager recorded why the save failed; pass it on rather than
		// leaving the caller with a fixed string it cannot act on.
		$reason = $mgr->get_last_save_error();

		return [
			'success' => false,
			'message' => '' !== $reason
				/* translators: %s: reason the save failed. */
				? sprintf( __( 'Failed to update site identity settings: %s', 'thinkrank' ), $reason )
				: __( 'Failed to update site identity settings.', 'thinkrank' ),
		];
	}
}
