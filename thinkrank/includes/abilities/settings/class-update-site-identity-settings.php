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
	 * Boolean-typed site identity keys.
	 */
	private const BOOL_KEYS = [
		'enabled',
		'breadcrumbs_enabled',
	];

	/**
	 * String-typed site identity keys.
	 */
	private const STRING_KEYS = [
		'title_template',
		'title_separator',
		'site_name',
		'site_description',
		'tagline',
		'breadcrumb_type',
		'breadcrumb_home_text',
		'breadcrumb_separator',
		'logo_url',
		'favicon_url',
		'apple_touch_icon_url',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-site-identity-settings';
		$this->label       = __( 'Update ThinkRank Site Identity Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank site identity settings: title templates, site name/description, breadcrumb configuration, and brand imagery. Robots.txt configuration is never modified by this ability.', 'thinkrank' );
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
		$props = [];

		foreach ( self::BOOL_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'boolean' ];
		}
		foreach ( self::STRING_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'string' ];
		}

		return $props;
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
		$found  = false;

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (bool) $settings[ $key ];
				$found          = true;
			}
		}
		foreach ( self::STRING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
				$found          = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_site_identity_setting_keys',
				__( 'No valid site identity setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $mgr->save_settings( 'site', null, $merged );

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Site identity settings updated.', 'thinkrank' )
				: __( 'Failed to update site identity settings.', 'thinkrank' ),
		];
	}
}
