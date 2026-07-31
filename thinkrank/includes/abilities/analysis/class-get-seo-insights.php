<?php
/**
 * Get SEO insights ability.
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
 * Retrieves ThinkRank AI-generated SEO insights.
 *
 * Requires a Google connection and, for AI insights, ThinkRank Pro. When those
 * are unavailable the manager returns a message such as
 * { success: false, message: 'requires ThinkRank Pro' }, which is passed
 * through unchanged.
 */
class Get_Seo_Insights extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-insights';
		$this->label       = __( 'Get ThinkRank SEO Insights', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank AI-generated SEO insights. Requires a Google connection and, for AI insights, ThinkRank Pro; otherwise a "requires ThinkRank Pro" message is returned.', 'thinkrank' );
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

		return $manager->get_seo_insights( $date_range );
	}
}
