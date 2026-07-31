<?php
/**
 * Get Site SEO Analyzer audit ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\SEO_Analyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Returns the latest cached whole-site SEO audit (Site SEO Analyzer).
 *
 * Mirrors `GET /thinkrank/v1/seo-analyzer`: a crawl-free 0-100 audit with an
 * overall score, letter grade, and per-check results across the Basic,
 * Advanced, Content, Performance, and Security categories. Distinct from the
 * per-post `get-seo-score` ability. Returns the cached result when available
 * (refreshed hourly); use `run-seo-analyzer` to force a fresh audit.
 */
class Get_Seo_Analyzer extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-analyzer';
		$this->label       = __( 'Get Site SEO Analyzer Audit', 'thinkrank' );
		$this->description = __( 'Retrieve the latest whole-site SEO audit (Site SEO Analyzer): overall 0-100 score, letter grade, summary counts, and per-check results across Basic, Advanced, Content, Performance, and Security. Returns the cached audit (refreshed hourly); use run-seo-analyzer for a fresh run.', 'thinkrank' );
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
		return $this->audit_output_schema();
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$analyzer = new SEO_Analyzer();

		return $analyzer->run( false );
	}

	/**
	 * Shared output schema for the audit payload.
	 *
	 * @return array<string, mixed>
	 */
	protected function audit_output_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'overall_score' => [ 'type' => 'integer' ],
				'grade'         => [ 'type' => 'string' ],
				'summary'       => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'categories'    => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
				'checks'        => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
				'generated_at'  => [ 'type' => 'string' ],
			],
		];
	}
}
