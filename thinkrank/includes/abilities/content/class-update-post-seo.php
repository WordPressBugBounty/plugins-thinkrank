<?php
/**
 * Update post SEO ability.
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
 * Updates ThinkRank SEO data for a post object.
 */
class Update_Post_Seo extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-post-seo';
		$this->label       = __( 'Update ThinkRank Post SEO', 'thinkrank' );
		$this->description = __( 'Update ThinkRank SEO metadata for a post, page, or custom post type item.', 'thinkrank' );
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
				'post_id'  => [
					'type'        => 'integer',
					'description' => __( 'The target post ID.', 'thinkrank' ),
				],
				'settings' => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'ThinkRank post SEO fields to update.', 'thinkrank' ),
					'properties'           => [
						'title'                => [ 'type' => 'string' ],
						'description'          => [ 'type' => 'string' ],
						'canonical_url'        => [ 'type' => 'string' ],
						'focus_keyword'        => [ 'type' => 'string' ],
						'focus_keywords'       => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'robots_meta_enabled'  => [ 'type' => 'boolean' ],
						'robots_meta'          => [ 'type' => 'object' ],
						'advanced_robots_meta' => [ 'type' => 'object' ],
						'og_title'             => [ 'type' => 'string' ],
						'og_description'       => [ 'type' => 'string' ],
						'og_image'             => [ 'type' => 'string' ],
						'twitter_title'        => [ 'type' => 'string' ],
						'twitter_description'  => [ 'type' => 'string' ],
						'twitter_image'        => [ 'type' => 'string' ],
					],
				],
			],
			'required'             => [ 'post_id', 'settings' ],
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
				'success' => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer' ],
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
		$post_id  = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( ! $post_id || empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_invalid_post_update',
				__( 'A valid post ID and settings payload are required.', 'thinkrank' ),
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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'thinkrank_cannot_edit_post',
				__( 'You are not allowed to edit this post.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		$fields = $this->map_fields( $settings, $post_id );

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'thinkrank_no_valid_post_meta_keys',
				__( 'No valid ThinkRank post SEO keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		( new Metabox_Manager() )->save_seo_fields( $post_id, $fields );

		return [
			'success' => true,
			'message' => __( 'Post SEO metadata updated.', 'thinkrank' ),
			'post_id' => $post_id,
		];
	}

	/**
	 * Map normalized input keys to metabox form field names.
	 *
	 * @param array<string, mixed> $settings Normalized settings payload.
	 * @param int                  $post_id  Target post ID.
	 * @return array<string, mixed>
	 */
	private function map_fields( array $settings, $post_id ) {
		$fields = [];

		$simple = [
			'title'               => 'thinkrank_seo_title',
			'description'         => 'thinkrank_meta_description',
			'canonical_url'       => 'thinkrank_canonical_url',
			'focus_keyword'       => 'thinkrank_focus_keyword',
			'og_title'            => 'thinkrank_og_title',
			'og_description'      => 'thinkrank_og_description',
			'og_image'            => 'thinkrank_og_image',
			'twitter_title'       => 'thinkrank_twitter_title',
			'twitter_description' => 'thinkrank_twitter_description',
			'twitter_image'       => 'thinkrank_twitter_image',
		];

		foreach ( $simple as $key => $field ) {
			if ( array_key_exists( $key, $settings ) ) {
				$fields[ $field ] = (string) $settings[ $key ];
			}
		}

		if ( array_key_exists( 'focus_keywords', $settings ) && is_array( $settings['focus_keywords'] ) ) {
			$fields['thinkrank_focus_keywords'] = (string) wp_json_encode( array_values( $settings['focus_keywords'] ) );
		}

		$has_robots = array_key_exists( 'robots_meta', $settings ) || array_key_exists( 'advanced_robots_meta', $settings );

		if ( array_key_exists( 'robots_meta_enabled', $settings ) ) {
			$fields['thinkrank_robots_meta_enabled'] = (int) (bool) $settings['robots_meta_enabled'];
		} elseif ( $has_robots ) {
			// The metabox only persists robots blobs when the toggle key is
			// present; default to the currently stored value (or 1).
			$existing                                = get_post_meta( $post_id, '_thinkrank_robots_meta_enabled', true );
			$fields['thinkrank_robots_meta_enabled'] = '' === $existing ? 1 : (int) (bool) $existing;
		}

		if ( array_key_exists( 'robots_meta', $settings ) ) {
			$fields['thinkrank_robots_meta'] = (string) wp_json_encode( (array) $settings['robots_meta'] );
		}

		if ( array_key_exists( 'advanced_robots_meta', $settings ) ) {
			$fields['thinkrank_advanced_robots_meta'] = (string) wp_json_encode( (array) $settings['advanced_robots_meta'] );
		}

		return $fields;
	}
}
