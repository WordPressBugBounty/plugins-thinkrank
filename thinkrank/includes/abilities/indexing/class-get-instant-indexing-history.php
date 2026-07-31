<?php
/**
 * Get instant indexing history ability.
 *
 * @package ThinkRank\Abilities\Indexing
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Indexing;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Instant_Indexing_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves the ThinkRank instant indexing (IndexNow) submission history.
 */
class Get_Instant_Indexing_History extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-instant-indexing-history';
		$this->label       = __( 'Get ThinkRank Instant Indexing History', 'thinkrank' );
		$this->description = __( 'Retrieve the ThinkRank instant indexing (IndexNow) submission history, including submitted URLs, status, response codes, and timestamps.', 'thinkrank' );
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
			'properties'           => [
				'limit' => [
					'type'        => 'integer',
					'description' => __( 'Maximum number of history rows to return. Use -1 for all.', 'thinkrank' ),
					'default'     => 50,
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
				'history' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'url'              => [ 'type' => 'string' ],
							'status'           => [ 'type' => 'string' ],
							'response_code'    => [ 'type' => 'integer' ],
							'response_message' => [ 'type' => 'string' ],
							'created_at'       => [ 'type' => 'string' ],
						],
					],
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
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;

		$manager = new Instant_Indexing_Manager();
		$history = $manager->get_history( $limit );

		return [
			'history' => is_array( $history ) ? array_values( $history ) : [],
		];
	}
}
