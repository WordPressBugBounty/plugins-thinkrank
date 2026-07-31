<?php
/**
 * Get SEO score ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;
use ThinkRank\SEO\Focus_Keywords;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Calculates the ThinkRank SEO score for a single post.
 */
class Get_Seo_Score extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-score';
		$this->label       = __( 'Get ThinkRank SEO Score', 'thinkrank' );
		$this->description = __( 'Calculate the ThinkRank SEO score for a single post, analyzing its content against its configured title and meta description.', 'thinkrank' );
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
					'description' => __( 'The ID of the post to score.', 'thinkrank' ),
				],
			],
			'required'             => [ 'post_id' ],
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
				'score'   => [
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
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'thinkrank_invalid_post_id',
				__( 'A valid post ID is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$calc    = new SEOScoreCalculator( new Database() );
		$content = $calc->analyze_post_content( $post_id );

		if ( empty( $content ) ) {
			return new \WP_Error(
				'thinkrank_post_content_unavailable',
				__( 'Unable to analyze the content for this post.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		// Carry the stored focus keywords so keyword-dependent factors are
		// actually measured instead of scoring as "no focus keyword set".
		$metadata = [
			'title'          => get_post_meta( $post_id, '_thinkrank_seo_title', true ),
			'description'    => get_post_meta( $post_id, '_thinkrank_meta_description', true ),
			'focus_keywords' => Focus_Keywords::get( $post_id ),
		];

		$score = $calc->calculate_score( $content, $metadata );

		return [
			'post_id' => $post_id,
			'score'   => $score,
		];
	}
}
