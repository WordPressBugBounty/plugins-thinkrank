<?php
/**
 * Update robots meta settings ability.
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
 * Updates ThinkRank site-wide default robots meta settings.
 *
 * These flags form the base robots meta directives applied to the whole site,
 * before any post-type or per-post overrides.
 */
class Update_Robots_Meta_Settings extends Ability_Base {
	/**
	 * Option name that stores the global robots meta settings.
	 */
	private const OPTION = 'thinkrank_global_robot_meta_settings';

	/**
	 * Default robots meta directives.
	 */
	private const DEFAULTS = [
		'index'        => true,
		'noindex'      => false,
		'nofollow'     => false,
		'noarchive'    => false,
		'noimageindex' => false,
		'nosnippet'    => false,
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-robots-meta-settings';
		$this->label       = __( 'Update ThinkRank Robots Meta Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank site-wide default robots meta directives (index, noindex, nofollow, noarchive, noimageindex, nosnippet).', 'thinkrank' );
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
					'description' => __( 'Robots meta directives to update.', 'thinkrank' ),
					'properties'  => [
						'index'        => [ 'type' => 'boolean' ],
						'noindex'      => [ 'type' => 'boolean' ],
						'nofollow'     => [ 'type' => 'boolean' ],
						'noarchive'    => [ 'type' => 'boolean' ],
						'noimageindex' => [ 'type' => 'boolean' ],
						'nosnippet'    => [ 'type' => 'boolean' ],
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
				'thinkrank_missing_robots_meta_settings_payload',
				__( 'A robots meta settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$merged = wp_parse_args( (array) get_option( self::OPTION, [] ), self::DEFAULTS );
		$found  = false;

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (bool) $settings[ $key ];
				$found          = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_robots_meta_setting_keys',
				__( 'No valid robots meta setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = update_option( self::OPTION, $merged );

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Robots meta settings updated.', 'thinkrank' )
				: __( 'Robots meta settings were unchanged.', 'thinkrank' ),
		];
	}
}
