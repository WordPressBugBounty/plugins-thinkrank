<?php
/**
 * Update instant indexing settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank instant indexing (IndexNow) settings.
 *
 * Only the enabled flag and the auto-submit post types are writable here. The
 * IndexNow API key is auto-generated and is never modified by this ability.
 */
class Update_Instant_Indexing_Settings extends Ability_Base {
	/**
	 * Option name that stores the instant indexing settings.
	 */
	private const OPTION = 'thinkrank_instant_indexing_settings';

	/**
	 * Default instant indexing settings.
	 */
	private const DEFAULTS = [
		'enabled'                => false,
		'auto_submit_post_types' => [ 'post', 'page' ],
		'api_key'                => '',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-instant-indexing-settings';
		$this->label       = __( 'Update ThinkRank Instant Indexing Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank instant indexing (IndexNow) settings: the enabled flag and the auto-submit post types. The API key is auto-generated and cannot be set here.', 'thinkrank' );
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
					'description' => __( 'Instant indexing settings to update.', 'thinkrank' ),
					'properties'  => [
						'enabled'                => [ 'type' => 'boolean' ],
						'auto_submit_post_types' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
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
				'thinkrank_missing_instant_indexing_settings_payload',
				__( 'An instant indexing settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$merged = wp_parse_args( (array) get_option( self::OPTION, [] ), self::DEFAULTS );
		$found  = false;

		if ( array_key_exists( 'enabled', $settings ) ) {
			$merged['enabled'] = (bool) $settings['enabled'];
			$found             = true;
		}

		if ( array_key_exists( 'auto_submit_post_types', $settings ) && is_array( $settings['auto_submit_post_types'] ) ) {
			$merged['auto_submit_post_types'] = array_values( array_map( 'sanitize_key', $settings['auto_submit_post_types'] ) );
			$found                            = true;
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_instant_indexing_setting_keys',
				__( 'No valid instant indexing setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = update_option( self::OPTION, $merged );

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Instant indexing settings updated.', 'thinkrank' )
				: __( 'Instant indexing settings were unchanged.', 'thinkrank' ),
		];
	}
}
