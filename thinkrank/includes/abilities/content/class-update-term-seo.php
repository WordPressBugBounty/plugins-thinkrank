<?php
/**
 * Update term SEO ability.
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
 * Updates ThinkRank SEO data for a taxonomy term.
 */
class Update_Term_Seo extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-term-seo';
		$this->label       = __( 'Update ThinkRank Term SEO', 'thinkrank' );
		$this->description = __( 'Update ThinkRank SEO metadata for a taxonomy term.', 'thinkrank' );
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
				'term_id'  => [
					'type'        => 'integer',
					'description' => __( 'The target term ID.', 'thinkrank' ),
				],
				'settings' => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'ThinkRank term SEO fields to update.', 'thinkrank' ),
					'properties'           => [
						'title'                => [ 'type' => 'string' ],
						'description'          => [ 'type' => 'string' ],
						'canonical_url'        => [ 'type' => 'string' ],
						'focus_keyword'        => [ 'type' => 'string' ],
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
			'required'             => [ 'term_id', 'settings' ],
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
				'term_id' => [ 'type' => 'integer' ],
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
		$term_id  = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( ! $term_id || empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_invalid_term_update',
				__( 'A valid term ID and settings payload are required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! get_term( $term_id ) instanceof \WP_Term ) {
			return new \WP_Error(
				'thinkrank_term_not_found',
				__( 'No term was found for the provided ID.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'thinkrank_cannot_edit_term',
				__( 'You are not allowed to edit term SEO settings.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		$touched = $this->save_term_meta( $term_id, $settings );

		if ( ! $touched ) {
			return new \WP_Error(
				'thinkrank_no_valid_term_meta_keys',
				__( 'No valid ThinkRank term SEO keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'success' => true,
			'message' => __( 'Term SEO metadata updated.', 'thinkrank' ),
			'term_id' => $term_id,
		];
	}

	/**
	 * Persist provided term SEO meta, sanitizing per field type.
	 *
	 * @param int                  $term_id  Term ID.
	 * @param array<string, mixed> $settings Normalized settings payload.
	 * @return bool Whether any meta key was written or deleted.
	 */
	private function save_term_meta( $term_id, array $settings ) {
		$touched = false;

		$text_fields = [
			'title'         => '_thinkrank_seo_title',
			'focus_keyword' => '_thinkrank_focus_keyword',
			'og_title'      => '_thinkrank_og_title',
			'twitter_title' => '_thinkrank_twitter_title',
		];
		foreach ( $text_fields as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$this->save_meta( $term_id, $meta_key, sanitize_text_field( (string) $settings[ $key ] ) );
				$touched = true;
			}
		}

		$textarea_fields = [
			'description'         => '_thinkrank_meta_description',
			'og_description'      => '_thinkrank_og_description',
			'twitter_description' => '_thinkrank_twitter_description',
		];
		foreach ( $textarea_fields as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$this->save_meta( $term_id, $meta_key, sanitize_textarea_field( (string) $settings[ $key ] ) );
				$touched = true;
			}
		}

		$url_fields = [
			'canonical_url' => '_thinkrank_canonical_url',
			'og_image'      => '_thinkrank_og_image',
			'twitter_image' => '_thinkrank_twitter_image',
		];
		foreach ( $url_fields as $key => $meta_key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$this->save_meta( $term_id, $meta_key, esc_url_raw( (string) $settings[ $key ] ) );
				$touched = true;
			}
		}

		if ( array_key_exists( 'robots_meta_enabled', $settings ) ) {
			update_term_meta( $term_id, '_thinkrank_robots_meta_enabled', (int) (bool) $settings['robots_meta_enabled'] );
			$touched = true;
		}

		if ( array_key_exists( 'robots_meta', $settings ) ) {
			update_term_meta( $term_id, '_thinkrank_robots_meta', (string) wp_json_encode( (array) $settings['robots_meta'] ) );
			$touched = true;
		}

		if ( array_key_exists( 'advanced_robots_meta', $settings ) ) {
			update_term_meta( $term_id, '_thinkrank_advanced_robots_meta', (string) wp_json_encode( (array) $settings['advanced_robots_meta'] ) );
			$touched = true;
		}

		return $touched;
	}

	/**
	 * Update a term meta value, deleting it when the sanitized value is empty.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $meta_key Meta key.
	 * @param string $value    Sanitized value.
	 * @return void
	 */
	private function save_meta( $term_id, $meta_key, $value ) {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $meta_key );
			return;
		}

		update_term_meta( $term_id, $meta_key, $value );
	}
}
