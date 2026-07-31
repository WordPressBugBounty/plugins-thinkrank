<?php
/**
 * Get pillar content ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Pillar_Content_Endpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads pillar-content state and internal-link suggestions for a post.
 *
 * Reports whether the post is flagged as pillar content
 * (`_thinkrank_pillar_content`) and returns the same internal-link suggestions
 * as `GET /thinkrank/v1/pillar-content/suggestions` — related posts flagged as
 * pillar content that share at least one taxonomy term.
 */
class Get_Pillar_Content extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-pillar-content';
		$this->label       = __( 'Get Pillar Content', 'thinkrank' );
		$this->description = __( 'Read whether a post is flagged as pillar content and get its internal-link suggestions (related pillar posts sharing a taxonomy term).', 'thinkrank' );
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
					'description' => __( 'The post to inspect.', 'thinkrank' ),
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
				'post_id'     => [ 'type' => 'integer' ],
				'is_pillar'   => [ 'type' => 'boolean' ],
				'suggestions' => [
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error(
				'thinkrank_invalid_post_id',
				__( 'A valid post ID is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$is_pillar = '1' === (string) get_post_meta( $post_id, '_thinkrank_pillar_content', true );

		// Reuse the endpoint's suggestion query so output matches the UI exactly.
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'post_id', $post_id );

		$response    = ( new Pillar_Content_Endpoint() )->get_suggestions( $request );
		$suggestions = ( $response instanceof \WP_REST_Response ) ? (array) $response->get_data() : [];

		return [
			'post_id'     => $post_id,
			'is_pillar'   => $is_pillar,
			'suggestions' => array_values( $suggestions ),
		];
	}
}
