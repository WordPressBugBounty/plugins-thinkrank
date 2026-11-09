<?php
/**
 * Get SEO score history ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports a post's stored SEO scores over time (#834).
 *
 * `get-seo-score` answers "what does this post score now" and `get-post-seo`
 * carries the latest stored row, so the one question neither could answer was
 * whether an edit helped. The scores are already persisted by the editor and by
 * `bulk-analyze-and-save`; nothing could read the series back.
 *
 * Read-only and never scores anything: it returns rows that are already there.
 * A post that has never been analysed returns an empty list rather than
 * triggering a scoring pass, because an agent asking for history is asking what
 * happened, not for something to happen.
 */
class Get_Seo_Score_History extends Ability_Base {
	/**
	 * Most rows one call may return.
	 *
	 * Matches the `maximum` on `/seo-score/history`, so the ability and the
	 * screen cannot disagree about how much history exists.
	 */
	private const MAX_LIMIT = 50;

	/**
	 * Rows returned when the caller does not say.
	 */
	private const DEFAULT_LIMIT = 10;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-score-history';
		$this->label       = __( 'Get ThinkRank SEO Score History', 'thinkrank' );
		$this->description = __( 'Read the SEO scores already stored for one post, newest first, to see whether a change helped or hurt. This reports history only and never scores anything: use get-seo-score to calculate the score as it stands now, and bulk-analyze-and-save to score and store several posts. A post that has never been analyzed returns an empty list.', 'thinkrank' );
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
			'priority'      => 0.5,
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
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post ID from list-content-items.', 'thinkrank' ),
				],
				'limit'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
					'default'     => self::DEFAULT_LIMIT,
					'description' => __( 'How many stored scores to return, newest first.', 'thinkrank' ),
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
				'post_id' => [ 'type' => 'integer' ],
				'total'   => [
					'type'        => 'integer',
					'description' => __( 'Scores returned. Zero means the post has never been analyzed, not that it scores zero.', 'thinkrank' ),
				],
				'scores'  => [
					'type'        => 'array',
					'description' => __( 'Stored scores, newest first.', 'thinkrank' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'overall_score'   => [ 'type' => 'integer' ],
							'created_at'      => [
								'type'        => 'string',
								'description' => __( 'When the score was stored, in site time.', 'thinkrank' ),
							],
							'score_breakdown' => [
								'type'        => 'object',
								'description' => __( 'Per-check contributions behind the overall score.', 'thinkrank' ),
							],
							'suggestions'     => [
								'type'        => 'array',
								'description' => __( 'The suggestions recorded with that score.', 'thinkrank' ),
							],
						],
					],
				],
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
		$input   = (array) $input;
		$post_id = (int) ( $input['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'thinkrank_invalid_post_id',
				__( 'A valid post ID is required. Use list-content-items to find one.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error(
				'thinkrank_post_not_found',
				__( 'No post was found for the provided ID.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		$history = ( new SEOScoreCalculator( new Database() ) )->get_score_history( $post_id, $limit );
		$history = is_array( $history ) ? $history : [];

		$scores = [];

		foreach ( $history as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$scores[] = [
				'overall_score'   => (int) ( $row['overall_score'] ?? 0 ),
				'created_at'      => (string) ( $row['created_at'] ?? '' ),
				// Already decoded by the calculator. Cast so an empty breakdown
				// serialises as {} rather than [], which is what the output
				// schema promises.
				'score_breakdown' => (object) ( is_array( $row['score_breakdown'] ?? null ) ? $row['score_breakdown'] : [] ),
				'suggestions'     => is_array( $row['suggestions'] ?? null ) ? array_values( $row['suggestions'] ) : [],
			];
		}

		return [
			'post_id' => $post_id,
			'total'   => count( $scores ),
			'scores'  => $scores,
		];
	}
}
