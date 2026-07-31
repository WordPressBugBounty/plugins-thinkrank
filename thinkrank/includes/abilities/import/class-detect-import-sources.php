<?php
/**
 * Detect import sources ability.
 *
 * @package ThinkRank\Abilities\Import
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Import;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Admin\Importers\Import_Detector;
use ThinkRank\Admin\Importers\Snapshot_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Detects installed SEO plugins whose data can be imported into ThinkRank.
 *
 * Mirrors `GET /thinkrank/v1/import/detect`: returns the active source plugins
 * (Yoast, RankMath, SEOPress, AIOSEO) with per-type available counts, alongside
 * any snapshots already captured.
 */
class Detect_Import_Sources extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/detect-import-sources';
		$this->label       = __( 'Detect SEO Import Sources', 'thinkrank' );
		$this->description = __( 'Detect installed SEO plugins (Yoast, RankMath, SEOPress, AIOSEO) whose metadata can be imported into ThinkRank, with per-type available counts, plus any snapshots already captured.', 'thinkrank' );
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
				'detected'  => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'snapshots' => [
					'type'                 => 'object',
					'additionalProperties' => true,
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
		$detector = new Import_Detector();

		return [
			'detected'  => $detector->detect(),
			'snapshots' => Snapshot_Store::get_available_snapshots(),
		];
	}
}
