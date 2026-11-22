<?php
/**
 * Check page head ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\SEO\Regression\Regression_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs a Regression Guard check now (#720): loads the page the way a
 * logged-out visitor does and compares it with the previous check.
 *
 * Mirrors `POST /thinkrank/v1/regression-guard/check` and returns the same
 * shape as get-page-head-snapshot.
 */
class Check_Page_Head extends Get_Page_Head_Snapshot {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/check-page-head';
		$this->label       = __( 'Check ThinkRank Page Head', 'thinkrank' );
		$this->description = __( 'Load a page now, the way a logged-out visitor and Google get it, and compare its SEO output with the previous Regression Guard check. Use it before and after a plugin, theme or core update, or a theme switch, to see whether the update changed what the page serves: a noindex that appeared, a canonical that moved, a structured data type that vanished. Returns the same shape as get-page-head-snapshot, and the new check becomes the baseline for the next one. The first check of a page has nothing to compare with, so result is null; check again after the change. Only published, public content can be checked. Use post_id 0 for the homepage; find other ids with list-content-items. If error is set the page could not be loaded - often a host that blocks the site from requesting itself - and the previous check is kept.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not readonly: it replaces the stored baseline. Not destructive either —
	 * the only thing replaced is the previous snapshot, and the result that
	 * replaces it carries every difference between the two.
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => false,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		return Regression_Guard::check( absint( $input['post_id'] ?? 0 ) );
	}
}
