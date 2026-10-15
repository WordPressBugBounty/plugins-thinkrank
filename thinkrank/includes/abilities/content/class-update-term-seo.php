<?php
/**
 * Update term SEO ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Object_Redirect;
use ThinkRank\SEO\Term_Seo_Meta;

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
		$this->description = __( 'Update ThinkRank SEO metadata for a taxonomy term. Read the current values with get-term-seo first; only the fields you pass are changed.', 'thinkrank' );
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
						'redirect_url'         => [
							'type'        => 'string',
							'description' => __( 'Send visitors from this term archive to this URL. Empty string removes the redirect. Requires ThinkRank Pro.', 'thinkrank' ),
						],
						'redirect_type'        => [
							'type'        => 'integer',
							'enum'        => Object_Redirect::TYPES,
							'description' => __( 'Redirect status code. Defaults to 301.', 'thinkrank' ),
						],
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
				'data'    => [
					'type'        => 'object',
					'description' => __( 'The term SEO fields as stored after the update, which may differ from what was sent once sanitized.', 'thinkrank' ),
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

		$touched = Term_Seo_Meta::write( $term_id, $settings );

		// The redirect is not term meta — Pro's rules table holds it — so it is
		// saved separately, and unlike the meta fields it can be refused.
		$redirect_touched = array_key_exists( 'redirect_url', $settings );
		if ( $redirect_touched ) {
			$redirect_result = Object_Redirect::save(
				'term',
				$term_id,
				(string) $settings['redirect_url'],
				$settings['redirect_type'] ?? Object_Redirect::DEFAULT_TYPE
			);

			if ( is_wp_error( $redirect_result ) ) {
				return new \WP_Error(
					$redirect_result->get_error_code(),
					$redirect_result->get_error_message(),
					[ 'status' => 400 ]
				);
			}
		}

		if ( ! $touched && ! $redirect_touched ) {
			return new \WP_Error(
				'thinkrank_no_valid_term_meta_keys',
				__( 'No valid ThinkRank term SEO keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$redirect = Object_Redirect::get( 'term', $term_id );

		return [
			'success' => true,
			'message' => __( 'Term SEO metadata updated.', 'thinkrank' ),
			'term_id' => $term_id,
			// What was stored, not what was sent: sanitizing can reduce a value.
			'data'    => Term_Seo_Meta::read( $term_id ) + [
				'redirect_url'  => $redirect['url'],
				'redirect_type' => $redirect['type'],
			],
		];
	}
}
