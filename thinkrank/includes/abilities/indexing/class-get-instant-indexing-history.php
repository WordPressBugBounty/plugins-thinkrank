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
		$this->description = __( 'Retrieve the ThinkRank instant indexing (IndexNow) submission history, including submitted URLs, status, response codes, and timestamps. Lists the submissions made by submit-urls-to-index.', 'thinkrank' );
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
							'response_code'    => [ 'type' => [ 'integer', 'null' ] ],
							'response_message' => [ 'type' => [ 'string', 'null' ] ],
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
			'history' => is_array( $history ) ? array_map( [ self::class, 'present_row' ], array_values( $history ) ) : [],
		];
	}

	/**
	 * One history row, with its numeric columns as numbers.
	 *
	 * $wpdb returns every column as a string, so `response_code` reached the
	 * client as `"200"` where the output schema declares an integer, and
	 * clients that validate `structuredContent` against `outputSchema` refused
	 * the call (#1090). `response_code` and `response_message` are nullable
	 * columns, so a NULL stays null rather than becoming 0 or "".
	 *
	 * @since 2.15.0
	 *
	 * @param mixed $row Row as $wpdb returned it.
	 * @return mixed The row with `id` and `response_code` cast.
	 */
	private static function present_row( $row ) {
		if ( ! is_array( $row ) ) {
			return $row;
		}

		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}

		if ( array_key_exists( 'response_code', $row ) ) {
			$row['response_code'] = null === $row['response_code'] ? null : (int) $row['response_code'];
		}

		return $row;
	}
}
