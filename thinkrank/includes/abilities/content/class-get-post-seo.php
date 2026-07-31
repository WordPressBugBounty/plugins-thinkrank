<?php
/**
 * Get post SEO ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Admin\Metabox_Manager;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank SEO data for a post object.
 */
class Get_Post_Seo extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-post-seo';
		$this->label       = __( 'Get ThinkRank Post SEO', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank SEO metadata for a post, page, or custom post type item.', 'thinkrank' );
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
					'description' => __( 'The target post ID.', 'thinkrank' ),
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
				'post_id'   => [ 'type' => 'integer' ],
				'post_type' => [ 'type' => 'string' ],
				'data'      => [ 'type' => 'object' ],
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

		if ( ! $post_id ) {
			return new \WP_Error(
				'thinkrank_invalid_post_target',
				__( 'A valid post ID is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'thinkrank_post_not_found',
				__( 'The provided post ID does not exist.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		$data = ( new Metabox_Manager() )->get_post_metadata( $post_id );

		$data['robots_meta']          = $this->decode_json_field( $data['robots_meta'] ?? '' );
		$data['advanced_robots_meta'] = $this->decode_json_field( $data['advanced_robots_meta'] ?? '' );

		// Report the score from the scores table, which is where every scoring
		// run persists. The `_thinkrank_seo_score` meta the metabox reads is
		// written only by the AI analyze flow and reset on each save, so on its
		// own it reports a misleading 0 for posts that have really been scored.
		$latest = ( new SEOScoreCalculator( new Database() ) )->get_latest_score( $post_id );
		if ( is_array( $latest ) && isset( $latest['overall_score'] ) ) {
			$data['seo_score']    = (int) $latest['overall_score'];
			$data['seo_grade']    = (string) ( $latest['grade'] ?? '' );
			$data['generated_at'] = (string) ( $latest['calculated_at'] ?? $latest['created_at'] ?? '' );
		}

		return [
			'post_id'   => $post_id,
			'post_type' => (string) $post->post_type,
			'data'      => $data,
		];
	}

	/**
	 * Decode a JSON-encoded meta field into an array.
	 *
	 * @param mixed $value Raw stored value.
	 * @return array<string, mixed>
	 */
	private function decode_json_field( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
