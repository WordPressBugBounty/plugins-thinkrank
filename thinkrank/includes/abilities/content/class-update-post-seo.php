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
use ThinkRank\SEO\Object_Redirect;

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
		$this->description = __( 'Update ThinkRank SEO metadata for a post, page, or custom post type item. Read the current values with get-post-seo first; only the fields you pass are changed, and get-post-seo-checks reports what would improve.', 'thinkrank' );
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
						'redirect_url'         => [
							'type'        => 'string',
							'description' => __( 'Send visitors from this post to this URL. Empty string removes the redirect. Requires ThinkRank Pro.', 'thinkrank' ),
						],
						'redirect_type'        => [
							'type'        => 'integer',
							'enum'        => Object_Redirect::TYPES,
							'description' => __( 'Redirect status code. Defaults to 301.', 'thinkrank' ),
						],
						'focus_keyword'        => [ 'type' => 'string' ],
						'focus_keywords'       => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'robots_meta_enabled'  => [ 'type' => 'boolean' ],
						'robots_meta'          => [ 'type' => 'object' ],
						'advanced_robots_meta' => [ 'type' => 'object' ],
						'exclude_from_search'  => [
							'type'        => 'boolean',
							'description' => __( 'Keep this post out of this site\'s own search results. Not noindex: search engines are unaffected, and the post keeps its own URL. Use robots_meta for search engines.', 'thinkrank' ),
						],
						'exclude_from_archives' => [
							'type'        => 'boolean',
							'description' => __( 'Keep this post out of category, tag, author, date and blog listings on this site. Not noindex: search engines are unaffected, and the post keeps its own URL, its feed entry and its sitemap entry.', 'thinkrank' ),
						],
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

		$manager = new Metabox_Manager();
		$manager->save_seo_fields( $post_id, $fields );

		// Everything else is stored unconditionally; the redirect can be
		// refused (no Pro, plain permalinks, a destination that is this post's
		// own URL). Reporting success for a redirect that was not written would
		// leave the caller believing the site now redirects when it does not.
		$redirect_error = $manager->get_last_redirect_error();
		if ( null !== $redirect_error ) {
			return new \WP_Error(
				$redirect_error->get_error_code(),
				$redirect_error->get_error_message(),
				[ 'status' => 400 ]
			);
		}

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
			'redirect_url'        => 'thinkrank_redirect_url',
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

		// Only meaningful alongside a destination: sending a code on its own
		// would be read by save_object_redirect() as "no redirect submitted"
		// and dropped, so requiring the pair keeps the payload honest.
		if ( array_key_exists( 'redirect_type', $settings ) && array_key_exists( 'redirect_url', $settings ) ) {
			$fields['thinkrank_redirect_type'] = Object_Redirect::normalize_type( $settings['redirect_type'] );
		}

		if ( array_key_exists( 'focus_keywords', $settings ) && is_array( $settings['focus_keywords'] ) ) {
			$fields['thinkrank_focus_keywords'] = (string) wp_json_encode( array_values( $settings['focus_keywords'] ) );
		}

		$has_robots = array_key_exists( 'robots_meta', $settings ) || array_key_exists( 'advanced_robots_meta', $settings );

		if ( array_key_exists( 'robots_meta_enabled', $settings ) ) {
			// Sanitized for the same reason as the visibility flags below: the
			// string "false" passes the schema and is truthy in PHP.
			$fields['thinkrank_robots_meta_enabled'] = (int) rest_sanitize_boolean( $settings['robots_meta_enabled'] );
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

		// On-site visibility (#633). get-post-seo already reports both, because
		// it returns get_post_metadata() wholesale — so without these an agent
		// could read a field it had no way to change, which is the worst shape
		// a tool pair can have.
		//
		// The value goes through Metabox_Manager::save_visibility_meta(), which
		// stores 1 on a truthy submission and DELETES the meta on a falsy one,
		// so '' is how a flag is cleared. Passing the boolean straight through
		// would work too; the string keeps this in the same shape as every
		// other field in this map.
		//
		// rest_sanitize_boolean() rather than PHP truthiness: WP_Ability only
		// VALIDATES the input against the schema, it never sanitizes it, and
		// rest_is_boolean() accepts the strings 'true'/'false'/'1'/'0' for a
		// boolean property. So a caller that sends "false" — which the schema
		// accepts — reaches this line with a truthy string and would have the
		// flag SET, the opposite of what it asked for, reported as a success.
		foreach (
			[
				'exclude_from_search'   => 'thinkrank_exclude_from_search',
				'exclude_from_archives' => 'thinkrank_exclude_from_archives',
			] as $key => $field
		) {
			if ( array_key_exists( $key, $settings ) ) {
				$fields[ $field ] = rest_sanitize_boolean( $settings[ $key ] ) ? '1' : '';
			}
		}

		return $fields;
	}
}
