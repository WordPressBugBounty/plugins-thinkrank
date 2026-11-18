<?php
/**
 * Get SEO goals ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Goals\Goal;
use ThinkRank\SEO\Goals\Goal_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the SEO Goals list (#728): what to do next on this site, most
 * important first, built from what ThinkRank already knows.
 */
class Get_Seo_Goals extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-goals';
		$this->label       = __( 'Get ThinkRank SEO Goals', 'thinkrank' );
		$this->description = __( 'Read the site\'s SEO to-do list, most important first: setup still missing (Search Console, analytics, llms.txt, an unfinished import), failing SEO Analyzer checks, published pages missing a meta description or focus keyword, duplicate or badly sized titles and descriptions, thin pages, and other gaps ThinkRank has already measured. Each goal has why it matters, how to do it in steps, a severity, an effort (S/M/L), how many items it affects, and where in ThinkRank it is fixed; an action of type analyzer_fix means the SEO Analyzer screen has a one-click fix for it; an agent makes the same change with the matching update- tool, then confirms with run-seo-analyzer. Work the list top-down. Mark a goal done or ignored with update-seo-goal. Goals the owner ignored are listed separately. Nothing here makes a remote request; refresh rebuilds from current data.', 'thinkrank' );
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
				'refresh' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Rebuild the list instead of reading the cached one. The cache is already dropped when settings, posts or the analyzer run change.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * The shape of one goal, shared with update-seo-goal.
	 *
	 * @return array<string, mixed>
	 */
	public static function goal_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'string' ],
				'source'   => [ 'type' => 'string' ],
				'title'    => [ 'type' => 'string' ],
				'why'      => [ 'type' => 'string' ],
				'how'      => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'link'     => [
					'type'        => 'object',
					'description' => __( 'Where it is fixed in ThinkRank: a page slug, or an Essential SEO section and item.', 'thinkrank' ),
				],
				'severity' => [
					'type' => 'string',
					'enum' => Goal::SEVERITIES,
				],
				'effort'   => [
					'type' => 'string',
					'enum' => Goal::EFFORTS,
				],
				'count'    => [ 'type' => 'integer' ],
				'section'  => [ 'type' => 'string' ],
				'open'     => [ 'type' => 'boolean' ],
				'action'   => [ 'type' => [ 'object', 'null' ] ],
				'priority' => [ 'type' => 'number' ],
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
				'goals'      => [
					'type'        => 'array',
					'items'       => self::goal_schema(),
					'description' => __( 'Open goals, highest priority first.', 'thinkrank' ),
				],
				'total'      => [ 'type' => 'integer' ],
				'ignored'    => [
					'type'  => 'array',
					'items' => self::goal_schema(),
				],
				'checklists' => [
					'type'        => 'object',
					'description' => __( 'Per Essential SEO section: every goal that belongs to it, ticked (done) when complete.', 'thinkrank' ),
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
		return ( new Goal_Registry() )->feed( ! empty( $input['refresh'] ) );
	}
}
