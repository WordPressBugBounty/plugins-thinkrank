<?php
/**
 * Get robots meta settings ability.
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
 * Retrieves ThinkRank site-wide default robots meta settings.
 *
 * These flags form the base robots meta directives applied to the whole site,
 * before any post-type or per-post overrides.
 */
class Get_Robots_Meta_Settings extends Ability_Base {
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
		$this->id          = 'thinkrank/get-robots-meta-settings';
		$this->label       = __( 'Get ThinkRank Robots Meta Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank site-wide default robots meta directives (index, noindex, nofollow, noarchive, noimageindex, nosnippet).', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
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
			'properties'           => [],
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
				'index'        => [ 'type' => 'boolean' ],
				'noindex'      => [ 'type' => 'boolean' ],
				'nofollow'     => [ 'type' => 'boolean' ],
				'noarchive'    => [ 'type' => 'boolean' ],
				'noimageindex' => [ 'type' => 'boolean' ],
				'nosnippet'    => [ 'type' => 'boolean' ],
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
		$settings = wp_parse_args( (array) get_option( self::OPTION, [] ), self::DEFAULTS );

		$out = [];
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$out[ $key ] = (bool) ( $settings[ $key ] ?? self::DEFAULTS[ $key ] );
		}

		return $out;
	}
}
