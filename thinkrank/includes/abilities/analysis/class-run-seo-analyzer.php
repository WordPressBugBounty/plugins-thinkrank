<?php
/**
 * Run Site SEO Analyzer audit ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\SEO\SEO_Analyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs a fresh whole-site SEO audit (Site SEO Analyzer).
 *
 * Mirrors `POST /thinkrank/v1/seo-analyzer/run`: forces a fresh crawl-free
 * audit (bypassing and refreshing the hourly cache) and returns the same
 * payload shape as `get-seo-analyzer`. Idempotent — running it repeatedly
 * re-computes the current site state without side effects on content.
 */
class Run_Seo_Analyzer extends Get_Seo_Analyzer {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/run-seo-analyzer';
		$this->label       = __( 'Run Site SEO Analyzer Audit', 'thinkrank' );
		$this->description = __( 'Run a fresh whole-site SEO audit (Site SEO Analyzer), bypassing and refreshing the hourly cache. Returns the same payload as get-seo-analyzer: overall 0-100 score, grade, summary counts, and per-check results across Basic, Advanced, Content, Performance, and Security.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$analyzer = new SEO_Analyzer();

		return $analyzer->run( true );
	}
}
