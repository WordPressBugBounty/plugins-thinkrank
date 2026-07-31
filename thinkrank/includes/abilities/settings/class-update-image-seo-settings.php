<?php
/**
 * Update image SEO settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Image_SEO_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank image SEO settings.
 *
 * Controls automatic alt/title injection and the token-based formats used when
 * generating those attributes, persisted through the Image_SEO_Manager.
 */
class Update_Image_Seo_Settings extends Ability_Base {
	/**
	 * Boolean-typed image SEO keys.
	 */
	private const BOOL_KEYS = [
		'add_missing_alt',
		'add_missing_title',
		'save_alt_to_media',
		'auto_fill_on_upload',
		'media_alt_overwrite',
	];

	/**
	 * String-typed image SEO keys.
	 */
	private const STRING_KEYS = [
		'alt_format',
		'title_format',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-image-seo-settings';
		$this->label       = __( 'Update ThinkRank Image SEO Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank image SEO settings, which control automatic alt/title injection, the token-based formats used to generate them, and whether generated alt text is persisted to the Media Library.', 'thinkrank' );
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
					'description' => __( 'Image SEO settings to update.', 'thinkrank' ),
					'properties'  => [
						'add_missing_alt'     => [ 'type' => 'boolean' ],
						'alt_format'          => [ 'type' => 'string' ],
						'add_missing_title'   => [ 'type' => 'boolean' ],
						'title_format'        => [ 'type' => 'string' ],
						'save_alt_to_media'   => [ 'type' => 'boolean' ],
						'auto_fill_on_upload' => [ 'type' => 'boolean' ],
						'media_alt_overwrite' => [ 'type' => 'boolean' ],
					],
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
				'thinkrank_missing_image_seo_settings_payload',
				__( 'An image SEO settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$mgr    = new Image_SEO_Manager();
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
				'thinkrank_no_valid_image_seo_setting_keys',
				__( 'No valid image SEO setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $mgr->save_settings( 'site', null, $merged );

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Image SEO settings updated.', 'thinkrank' )
				: __( 'Failed to update image SEO settings.', 'thinkrank' ),
		];
	}
}
