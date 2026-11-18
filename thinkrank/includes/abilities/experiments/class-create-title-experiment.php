<?php
/**
 * Create title experiment ability.
 *
 * @package ThinkRank\Abilities\Experiments
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Experiments;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Title_Experiments_Endpoint;
use ThinkRank\SEO\Experiments\Experiment_Schedule;
use ThinkRank\SEO\Experiments\Title_Experiments;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Starts a Title Experiment on one page (#719).
 */
class Create_Title_Experiment extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/create-title-experiment';
		$this->label       = __( 'Create ThinkRank Title Experiment', 'thinkrank' );
		$this->description = __( 'Start testing new SEO titles (and optionally meta descriptions) on one published page against its current ones, measured on Google Search Console clicks. From today the page serves the first variant in its <title> and meta description - with more than one (ThinkRank Pro), each is served in turn for its own window; Open Graph and other tags keep the original, and the stored SEO fields are not changed. The days before today are the original\'s window; each variant is measured after 3 days for Google to recrawl, over the same number of days (window, 7-28, default 14), and the experiment is decided 3 days after the last one when Search Console has the data - the winner is the variant that beat the original by the most. Needs Search Console connected. Only one experiment runs at a time in the free plugin. While it runs, the page\'s SEO title and description cannot be changed with update-post-seo. Pick pages with many impressions and a low click-through rate for their position - get-seo-opportunities lists candidate searches. Read progress with get-title-experiments; end early with stop-title-experiment.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not destructive: nothing stored is changed, and stopping serves the
	 * original again.
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
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'variants' ],
			'properties'           => [
				'post_id'  => [ 'type' => 'integer' ],
				'variants' => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 3,
					'description' => __( 'The titles to test against the current one, served one after another. The free plugin tests one; ThinkRank Pro up to three.', 'thinkrank' ),
					'items'       => [
						'type'       => 'object',
						'required'   => [ 'title' ],
						'properties' => [
							'title'       => [
								'type'        => 'string',
								'description' => __( 'SEO title to test, 35-60 characters.', 'thinkrank' ),
							],
							'description' => [
								'type'        => 'string',
								'description' => __( 'Meta description to test with it. Empty keeps the current one.', 'thinkrank' ),
							],
						],
					],
				],
				'window'   => [
					'type'    => 'integer',
					'minimum' => Experiment_Schedule::MIN_WINDOW,
					'maximum' => Experiment_Schedule::MAX_WINDOW,
					'default' => Experiment_Schedule::DEFAULT_WINDOW,
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
		return Get_Title_Experiments::experiment_schema();
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'thinkrank_cannot_edit_post', __( 'You are not allowed to edit this post.', 'thinkrank' ), [ 'status' => 403 ] );
		}

		$variants = array_map(
			static fn( $v ): array => [
				'title'       => (string) ( $v['title'] ?? '' ),
				'description' => (string) ( $v['description'] ?? '' ),
				'source'      => 'manual',
			],
			array_filter( (array) ( $input['variants'] ?? [] ), 'is_array' )
		);

		$created = ( new Title_Experiments() )->create(
			$post_id,
			array_values( $variants ),
			(int) ( $input['window'] ?? Experiment_Schedule::DEFAULT_WINDOW )
		);

		return is_wp_error( $created ) ? $created : ( new Title_Experiments_Endpoint() )->view( $created );
	}
}
