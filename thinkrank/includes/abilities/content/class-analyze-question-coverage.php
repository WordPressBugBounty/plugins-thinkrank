<?php
/**
 * Analyze question coverage ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\Question_Coverage_Analyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs Question Coverage on a post (#723). Spends AI tokens on the site's own
 * provider key, and says so, so an agent looping over posts knows the cost.
 */
class Analyze_Question_Coverage extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/analyze-question-coverage';
		$this->label       = __( 'Analyze Question Coverage', 'thinkrank' );
		$this->description = __( 'Check which questions people search around a post\'s focus keyword the post answers. Builds 8 to 15 sub-questions across facets (definition, how-to, comparison, cost, best for, problems, local, freshness), adds up to 10 real queries the page gets Search Console impressions for when Search Console is connected, then judges each against the post\'s sections: answered (with the answering heading), partial, or missing (with where a new section would fit). SPENDS AI TOKENS on the site\'s configured provider; set dry_run to get the token estimate first. Questions are cached per keyword and language for 30 days, so re-running after an edit only re-judges the content. Stores the result on the post; read it later with get-question-coverage. Requires a focus keyword (set it with update-post-seo).', 'thinkrank' );
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
			'priority'      => 2.0,
			'openWorldHint' => true,
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
				'post_id'           => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The post to analyze. Its saved content is judged.', 'thinkrank' ),
				],
				'refresh_questions' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Generate a new question set even when one is cached for this keyword.', 'thinkrank' ),
				],
				'dry_run'           => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Return the estimated token cost without calling the AI.', 'thinkrank' ),
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
			'type'                 => 'object',
			'description'          => __( 'The coverage result (see get-question-coverage), plus gsc {status: ok, no_rows, not_connected, not_published or error; count}, cache {questions, coverage: hit or miss} and tokens_used. With dry_run: keyword, sections, questions_cached and tokens.', 'thinkrank' ),
			'additionalProperties' => true,
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'thinkrank_invalid_post_id', __( 'A valid post ID is required.', 'thinkrank' ), [ 'status' => 400 ] );
		}

		$analyzer = new Question_Coverage_Analyzer();
		$options  = [ 'refresh_questions' => ! empty( $input['refresh_questions'] ) ];

		return ! empty( $input['dry_run'] )
			? $analyzer->estimate( $post_id, $options )
			: $analyzer->analyze( $post_id, $options );
	}
}
