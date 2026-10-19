<?php
/**
 * Get SEO analytics data ability.
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
 * Retrieves the ThinkRank SEO analytics dashboard data.
 *
 * Requires Google Search Console to be connected (GA4 traffic comes from
 * ThinkRank Pro, through `thinkrank_analytics_dashboard_data`). When no
 * Google connection is present, empty data is returned (this is expected and is
 * not an error).
 */
class Get_Seo_Analytics_Data extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-analytics-data';
		$this->label       = __( 'Get ThinkRank SEO Analytics Data', 'thinkrank' );
		$this->description = __( 'Retrieve the ThinkRank SEO analytics dashboard data: Search Console performance and Core Web Vitals. Requires Google Search Console to be connected; returns empty data otherwise. The GA4 `traffic` block is filled only when ThinkRank Pro is active with a GA4 property selected. get-integrations-status reports what is connected.', 'thinkrank' );
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
			'properties'           => self::empty_properties(),
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

		return $manager->get_dashboard_data( $date_range );
	}
}
