<?php
/**
 * Get performance data ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Performance_Monitoring_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank's Core Web Vitals / performance snapshot for the site.
 *
 * Mirrors `GET /thinkrank/v1/performance/monitor`: returns the collected Core
 * Web Vitals (LCP, FID, CLS, FCP), a 0-100 performance score and grade, and the
 * SEO correlation. Requires a connected Google account for real data; when not
 * connected the payload still returns with a `core_web_vitals.error` marker and
 * zeroed metrics.
 */
class Get_Performance_Data extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-performance-data';
		$this->label       = __( 'Get Performance Data', 'thinkrank' );
		$this->description = __( 'Retrieve the site Core Web Vitals / performance snapshot (LCP, FID, CLS, FCP), performance score and grade, and SEO correlation. Requires a connected Google account for real data; otherwise returns an empty snapshot with a not-connected marker.', 'thinkrank' );
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
				'device_type' => [
					'type'        => 'string',
					'description' => __( 'The device profile to report.', 'thinkrank' ),
					'enum'        => [ 'mobile', 'desktop' ],
					'default'     => 'mobile',
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
			'properties'           => [
				'success' => [ 'type' => 'boolean' ],
				'data'    => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
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
		$device_type = isset( $input['device_type'] ) ? sanitize_key( (string) $input['device_type'] ) : 'mobile';

		if ( ! in_array( $device_type, [ 'mobile', 'desktop' ], true ) ) {
			$device_type = 'mobile';
		}

		try {
			$manager = new Performance_Monitoring_Manager();

			return $manager->get_performance_snapshot( $device_type );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'thinkrank_performance_data_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}
}
