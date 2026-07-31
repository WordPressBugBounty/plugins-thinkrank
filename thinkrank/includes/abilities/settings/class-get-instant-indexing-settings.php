<?php
/**
 * Get instant indexing settings ability.
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
 * Retrieves ThinkRank instant indexing (IndexNow) settings.
 *
 * The IndexNow API key is auto-generated and never exposed in full; the read
 * only reports whether a key is currently configured.
 */
class Get_Instant_Indexing_Settings extends Ability_Base {
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
		$this->id          = 'thinkrank/get-instant-indexing-settings';
		$this->label       = __( 'Get ThinkRank Instant Indexing Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank instant indexing (IndexNow) settings. The API key is auto-generated and is not exposed; only whether a key is set is reported.', 'thinkrank' );
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
				'enabled'                => [ 'type' => 'boolean' ],
				'auto_submit_post_types' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'api_key_set'            => [ 'type' => 'boolean' ],
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

		$post_types = is_array( $settings['auto_submit_post_types'] ?? null )
			? array_values( array_map( 'sanitize_key', $settings['auto_submit_post_types'] ) )
			: [];

		return [
			'enabled'                => (bool) ( $settings['enabled'] ?? false ),
			'auto_submit_post_types' => $post_types,
			'api_key_set'            => '' !== (string) ( $settings['api_key'] ?? '' ),
		];
	}
}
