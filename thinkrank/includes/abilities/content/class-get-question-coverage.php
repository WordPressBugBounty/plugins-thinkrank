<?php
/**
 * Get question coverage ability.
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
 * Reads a post's last Question Coverage result (#723). Spends nothing.
 */
class Get_Question_Coverage extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-question-coverage';
		$this->label       = __( 'Get Question Coverage', 'thinkrank' );
		$this->description = __( 'Read the last Question Coverage result stored on a post: the questions people search around its focus keyword, and for each one whether the post answers it (answered, partial or missing), which section answers it, and where a missing answer would fit. Questions with source search_console are real queries the page already gets Search Console impressions for. Spends no AI tokens. Returns result null when coverage was never run; run analyze-question-coverage to compute it, and get-post-content to read the sections it refers to.', 'thinkrank' );
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
					'minimum'     => 1,
					'description' => __( 'The post to read.', 'thinkrank' ),
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
				'result'  => [
					'type'                 => [ 'object', 'null' ],
					'description'          => __( 'keyword, generated_at, counts {answered, partial, missing, total}, questions [{id, question, source (ai or search_console), facet, status, section, after, reason, impressions}], sections [{index, heading, level}]. section is the answering heading; "" means the introduction.', 'thinkrank' ),
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'thinkrank_invalid_post_id', __( 'A valid post ID is required.', 'thinkrank' ), [ 'status' => 400 ] );
		}

		return [
			'post_id' => $post_id,
			'result'  => Question_Coverage_Analyzer::stored( $post_id ),
		];
	}
}
