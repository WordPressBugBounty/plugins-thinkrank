<?php
/**
 * Get title experiments ability.
 *
 * @package ThinkRank\Abilities\Experiments
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Experiments;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Title_Experiments_Endpoint;
use ThinkRank\SEO\Experiments\Experiment_Verdict;
use ThinkRank\SEO\Experiments\Title_Experiments;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads Title Experiments (#719): what is running and what finished.
 */
class Get_Title_Experiments extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-title-experiments';
		$this->label       = __( 'Get ThinkRank Title Experiments', 'thinkrank' );
		$this->description = __( 'Read ThinkRank title experiments: tests of a new SEO title (and optionally description) on one page against the current one, measured on real Google Search Console clicks. Returns running experiments with their dates, and recently finished ones with the outcome - variant_wins (with the winning variant), original_wins, tie, or inconclusive with a reason (too_few_impressions under 200 in a window, position_moved by more than 2 places, no_baseline, measurement_failed) - plus the click, impression and position numbers behind it and the lift after adjusting for site-wide CTR. Pass post_id for one page. Start one with create-title-experiment; end one with stop-title-experiment. A lift is more clicks at the same position, not a ranking change.', 'thinkrank' );
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
				'post_id' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'One page; 0 lists every running and recently finished experiment.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * The shape of one experiment, shared with the other experiment tools.
	 *
	 * @return array<string, mixed>
	 */
	public static function experiment_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'    => [ 'type' => 'integer' ],
				'post_title' => [ 'type' => 'string' ],
				'url'        => [ 'type' => 'string' ],
				'status'     => [
					'type' => 'string',
					'enum' => Title_Experiments::STATUSES,
				],
				'plan'       => [
					'type'        => 'object',
					'description' => __( 'UTC dates: the original window, the variant window, and the day it is decided.', 'thinkrank' ),
				],
				'original'   => [ 'type' => 'object' ],
				'variants'   => [
					'type'        => 'array',
					'items'       => [ 'type' => 'object' ],
					'description' => __( 'The titles tested, in the order they were served.', 'thinkrank' ),
				],
				'result'     => [
					'type'       => [ 'object', 'null' ],
					'properties' => [
						'outcome' => [
							'type' => 'string',
							'enum' => Experiment_Verdict::OUTCOMES,
						],
						'reason'  => [ 'type' => 'string' ],
						'lift'    => [ 'type' => [ 'number', 'null' ] ],
						'winner'  => [
							'type'        => [ 'integer', 'null' ],
							'description' => __( 'Index in variants of the winning title, or null when the original stays.', 'thinkrank' ),
						],
					],
				],
				'applied'    => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Index of the variant written into the page, once kept.', 'thinkrank' ),
				],
				'stopped_reason' => [
					'type'        => 'string',
					'enum'        => Title_Experiments::STOP_REASONS,
					'description' => __( 'Set when ThinkRank ended the test itself: the page was unpublished, its URL changed, or it can no longer serve the title (noindex, SEO titles switched off, the posts or Shop page). Absent when the owner stopped it.', 'thinkrank' ),
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
			'type'       => 'object',
			'properties' => [
				'active'        => [
					'type'  => 'array',
					'items' => self::experiment_schema(),
				],
				'recent'        => [
					'type'  => 'array',
					'items' => self::experiment_schema(),
				],
				'experiment'    => [ 'type' => [ 'object', 'null' ] ],
				'gsc_connected' => [ 'type' => 'boolean' ],
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
		$endpoint = new Title_Experiments_Endpoint();
		$post_id  = absint( $input['post_id'] ?? 0 );

		if ( $post_id > 0 ) {
			$record = ( new Title_Experiments() )->get( $post_id );
			return [
				'experiment'    => null === $record ? null : $endpoint->view( $record ),
				'gsc_connected' => ( new \ThinkRank\SEO\Experiments\Experiment_Measurer() )->available(),
			];
		}

		return (array) $endpoint->list_experiments()->get_data()['data'];
	}
}
