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
 * Advanced, Content, Performance, Security and GEO/AEO categories. Distinct from the
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
		$this->description = __( 'Retrieve the latest whole-site SEO audit (Site SEO Analyzer): overall 0-100 score, letter grade, summary counts, and per-check results across Basic, Advanced, Content, Performance, Security and GEO/AEO. A failing per-item check (meta descriptions, image alt text, and the content-sampled GEO checks) lists what fails it in affected_posts (id, title, type, edit_url, url), with affected_total counting every failing item when the list is capped. A check whose can_auto_fix is true can be fixed with fix-seo-check: fix_label says what the fix does, fix_warning what to tell the person first, and fix_undoable whether the change can be put back afterwards. Returns the cached audit (refreshed hourly); use run-seo-analyzer for a fresh run.', 'thinkrank' );
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
			'properties'           => self::empty_properties(),
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

		return $this->without_category_checks( $analyzer->run( false ) );
	}

	/**
	 * Drop each category's copy of its checks from the audit.
	 *
	 * The analyzer lists every check twice: under its category for the admin
	 * audit tabs, and again in the top-level `checks`, each with its `category`.
	 * An agent needs the list once, and the second copy roughly doubles an answer
	 * that the Hub refuses whole past 512 KB (#1422). Categories keep their id,
	 * label, score and counts.
	 *
	 * @param array<string, mixed> $audit Audit payload from SEO_Analyzer::run().
	 * @return array<string, mixed>
	 */
	protected function without_category_checks( $audit ) {
		if ( ! isset( $audit['categories'] ) || ! is_array( $audit['categories'] ) ) {
			return $audit;
		}

		foreach ( $audit['categories'] as $index => $category ) {
			if ( is_array( $category ) ) {
				unset( $audit['categories'][ $index ]['checks'] );
			}
		}

		return $audit;
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
