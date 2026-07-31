<?php
/**
 * Get term SEO ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank SEO data for a taxonomy term.
 */
class Get_Term_Seo extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-term-seo';
		$this->label       = __( 'Get ThinkRank Term SEO', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank SEO metadata for a taxonomy term.', 'thinkrank' );
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
				'term_id' => [
					'type'        => 'integer',
					'description' => __( 'The target term ID.', 'thinkrank' ),
				],
			],
			'required'             => [ 'term_id' ],
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
				'term_id'  => [ 'type' => 'integer' ],
				'taxonomy' => [ 'type' => 'string' ],
				'data'     => [ 'type' => 'object' ],
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
		$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;

		if ( ! $term_id ) {
			return new \WP_Error(
				'thinkrank_invalid_term_target',
				__( 'A valid term ID is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error(
				'thinkrank_term_not_found',
				__( 'The provided term ID does not exist.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		$data = [
			'title'                => (string) get_term_meta( $term_id, '_thinkrank_seo_title', true ),
			'description'          => (string) get_term_meta( $term_id, '_thinkrank_meta_description', true ),
			'canonical_url'        => (string) get_term_meta( $term_id, '_thinkrank_canonical_url', true ),
			'focus_keyword'        => (string) get_term_meta( $term_id, '_thinkrank_focus_keyword', true ),
			'robots_meta_enabled'  => get_term_meta( $term_id, '_thinkrank_robots_meta_enabled', true ),
			'robots_meta'          => $this->decode_json_field( get_term_meta( $term_id, '_thinkrank_robots_meta', true ) ),
			'advanced_robots_meta' => $this->decode_json_field( get_term_meta( $term_id, '_thinkrank_advanced_robots_meta', true ) ),
			'og_title'             => (string) get_term_meta( $term_id, '_thinkrank_og_title', true ),
			'og_description'       => (string) get_term_meta( $term_id, '_thinkrank_og_description', true ),
			'og_image'             => (string) get_term_meta( $term_id, '_thinkrank_og_image', true ),
			'twitter_title'        => (string) get_term_meta( $term_id, '_thinkrank_twitter_title', true ),
			'twitter_description'  => (string) get_term_meta( $term_id, '_thinkrank_twitter_description', true ),
			'twitter_image'        => (string) get_term_meta( $term_id, '_thinkrank_twitter_image', true ),
		];

		return [
			'term_id'  => $term_id,
			'taxonomy' => (string) $term->taxonomy,
			'data'     => $data,
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
