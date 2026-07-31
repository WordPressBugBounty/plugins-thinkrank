<?php
/**
 * Update pillar content ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Admin\Metabox_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Flags or unflags a post as pillar content.
 *
 * Writes the `_thinkrank_pillar_content` post meta via the same metabox writer
 * the editor uses. Pillar posts surface as internal-link suggestions for
 * related posts sharing a taxonomy term.
 */
class Update_Pillar_Content extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-pillar-content';
		$this->label       = __( 'Update Pillar Content', 'thinkrank' );
		$this->description = __( 'Flag or unflag a post as pillar content. Pillar posts appear as internal-link suggestions for related posts that share a taxonomy term.', 'thinkrank' );
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
				'post_id'   => [
					'type'        => 'integer',
					'description' => __( 'The target post ID.', 'thinkrank' ),
				],
				'is_pillar' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the post should be flagged as pillar content.', 'thinkrank' ),
				],
			],
			'required'             => [ 'post_id', 'is_pillar' ],
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
				'success'   => [ 'type' => 'boolean' ],
				'message'   => [ 'type' => 'string' ],
				'post_id'   => [ 'type' => 'integer' ],
				'is_pillar' => [ 'type' => 'boolean' ],
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
				'thinkrank_post_not_found',
				__( 'A valid post ID is required.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'thinkrank_cannot_edit_post',
				__( 'You are not allowed to edit this post.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		$is_pillar = ! empty( $input['is_pillar'] );

		( new Metabox_Manager() )->save_seo_fields(
			$post_id,
			[ 'thinkrank_pillar_content' => $is_pillar ? '1' : '0' ]
		);

		return [
			'success'   => true,
			'message'   => $is_pillar
				? __( 'Post flagged as pillar content.', 'thinkrank' )
				: __( 'Post removed from pillar content.', 'thinkrank' ),
			'post_id'   => $post_id,
			'is_pillar' => $is_pillar,
		];
	}
}
