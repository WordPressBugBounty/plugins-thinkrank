<?php
/**
 * Bulk analyze-and-save ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Admin\Importers\Snapshot_Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Analyze a set of posts and persist their SEO scores in one call (see #190).
 *
 * Before this, an agent that wanted scores for many posts had to call
 * `get-seo-score` (with `save_score`) once per post. This ability reuses the
 * import pipeline's bulk scoring loop (`Snapshot_Migrator::score_posts()`) to
 * score and save in a single request, returning per-post results plus totals.
 *
 * Either pass explicit `post_ids`, or omit them to score the most recent posts
 * of `post_type`. Already-scored posts are skipped unless `rescore` is true.
 */
class Bulk_Analyze_And_Save extends Ability_Base {
	/**
	 * Hard cap on posts processed per call, to bound execution time.
	 */
	private const MAX_POSTS = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/bulk-analyze-and-save';
		$this->label       = __( 'Bulk Analyze and Save SEO Scores', 'thinkrank' );
		$this->description = __( 'Analyze multiple posts and persist their SEO scores in one call. Pass explicit post_ids, or omit them to score the most recent posts of a post type. Already-scored posts are skipped unless rescore is true. Returns per-post results (score, saved score_id, status) and totals. Capped at 100 posts per call.', 'thinkrank' );
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
			'idempotent'    => false,
			'priority'      => 1.5,
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
				'post_ids'  => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => __( 'Explicit post IDs to score. When omitted, the most recent posts of post_type are scored.', 'thinkrank' ),
				],
				'post_type' => [
					'type'        => 'string',
					'default'     => 'post',
					'description' => __( 'Post type to score when post_ids is omitted.', 'thinkrank' ),
				],
				'limit'     => [
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => self::MAX_POSTS,
					'description' => __( 'How many posts to score when post_ids is omitted (max 100).', 'thinkrank' ),
				],
				'rescore'   => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Re-score and overwrite even posts that already have a stored score.', 'thinkrank' ),
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
				'scored'  => [ 'type' => 'integer' ],
				'skipped' => [ 'type' => 'integer' ],
				'failed'  => [ 'type' => 'integer' ],
				'total'   => [ 'type' => 'integer' ],
				'results' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
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
		$rescore = ! empty( $input['rescore'] );

		$post_ids = $this->resolve_post_ids( $input );
		if ( is_wp_error( $post_ids ) ) {
			return $post_ids;
		}

		if ( empty( $post_ids ) ) {
			return [
				'scored'  => 0,
				'skipped' => 0,
				'failed'  => 0,
				'total'   => 0,
				'results' => [],
			];
		}

		$summary = ( new Snapshot_Migrator() )->score_posts( $post_ids, $rescore );

		return [
			'scored'  => $summary['scored'],
			'skipped' => $summary['skipped'],
			'failed'  => $summary['failed'],
			'total'   => $summary['total'],
			'results' => $summary['results'],
		];
	}

	/**
	 * Resolve the list of post IDs to score, from explicit input or a query.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return int[]|\WP_Error
	 */
	private function resolve_post_ids( array $input ) {
		if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			$ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $input['post_ids'] )
					)
				)
			);

			if ( count( $ids ) > self::MAX_POSTS ) {
				return new \WP_Error(
					'thinkrank_bulk_analyze_too_many',
					sprintf(
						/* translators: %d: maximum number of posts. */
						__( 'Too many posts: pass at most %d post_ids per call.', 'thinkrank' ),
						self::MAX_POSTS
					),
					[ 'status' => 400 ]
				);
			}

			return $ids;
		}

		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'thinkrank_bulk_analyze_bad_type',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'Unknown post type: %s.', 'thinkrank' ),
					$post_type
				),
				[ 'status' => 400 ]
			);
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
		$limit = max( 1, min( self::MAX_POSTS, $limit ) );

		return get_posts(
			[
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			]
		);
	}
}
