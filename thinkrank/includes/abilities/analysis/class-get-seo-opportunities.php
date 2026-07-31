<?php
/**
 * Get SEO opportunities ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Analytics_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank SEO opportunities.
 *
 * Requires Google Search Console to be connected. When no Google connection is
 * present, empty data is returned (this is expected and is not an error).
 */
class Get_Seo_Opportunities extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-opportunities';
		$this->label       = __( 'Get ThinkRank SEO Opportunities', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank SEO opportunities (e.g. striking-distance keywords). Requires Google Search Console to be connected; returns empty data otherwise.', 'thinkrank' );
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
				'date_range' => [
					'type'        => 'string',
					'description' => __( 'The reporting date range.', 'thinkrank' ),
					'enum'        => [ '7d', '30d', '90d' ],
					'default'     => '30d',
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
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$date_range = isset( $input['date_range'] ) ? (string) $input['date_range'] : '30d';

		if ( ! in_array( $date_range, [ '7d', '30d', '90d' ], true ) ) {
			$date_range = '30d';
		}

		$manager = new Analytics_Manager();

		return $manager->get_seo_opportunities( $date_range );
	}
}
