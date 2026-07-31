<?php
/**
 * Update global settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank global SEO settings.
 *
 * ThinkRank stores global SEO as per-post-type templates in the
 * `thinkrank_global_seo_settings` option. This ability therefore requires a
 * `post_type` and merges the provided settings into that post type's template.
 */
class Update_Global_Settings extends Ability_Base {
	/**
	 * Option storing per-post-type global SEO templates.
	 */
	private const OPTION_NAME = 'thinkrank_global_seo_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-global-settings';
		$this->label       = __( 'Update ThinkRank Global Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank global SEO settings for a specific post type. ThinkRank stores global SEO as per-post-type templates, so a post type is required.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => true,
			'idempotent'    => true,
			'priority'      => 2.0,
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
				'post_type' => [
					'type'        => 'string',
					'description' => __( 'The post type whose global SEO template to update.', 'thinkrank' ),
				],
				'settings'  => [
					'type'        => 'object',
					'description' => __( 'Key-value pairs of ThinkRank global settings to merge into the post type template.', 'thinkrank' ),
				],
			],
			'required'             => [ 'post_type', 'settings' ],
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
				'post_type' => [ 'type' => 'string' ],
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
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$settings  = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'thinkrank_invalid_post_type',
				__( 'A valid post type slug is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		// Apply the shared Global SEO target policy (public + viewable + not on
		// the deny list) so the ability rejects the same types the REST endpoint
		// and the admin UI do.
		if ( ! \ThinkRank\SEO\Global_SEO_Post_Types::is_allowed( $post_type ) ) {
			return new \WP_Error(
				'thinkrank_non_public_post_type',
				__( 'Global SEO settings are only available for valid Global SEO target post types.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_missing_settings_payload',
				__( 'A settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		// Normalize through the SAME per-field contract the REST endpoint uses so
		// both write paths coerce identically (booleans, enums, clamped numerics,
		// nested robots subkeys) and neither can persist string booleans or
		// unknown nested keys.
		$settings = \ThinkRank\API\Global_SEO_Endpoint::normalize_settings_patch( $settings );

		$all = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		// Patch contract: this ability performs a PARTIAL update. Top-level keys
		// in the payload replace the stored value; the two nested robots
		// structures (robots_meta, advanced_robots_meta) are merged subkey-wise so
		// a partial nested patch preserves sibling subkeys instead of wiping them.
		$existing = isset( $all[ $post_type ] ) && is_array( $all[ $post_type ] ) ? $all[ $post_type ] : [];
		$merged   = $existing;
		foreach ( $settings as $key => $value ) {
			if (
				in_array( $key, [ 'robots_meta', 'advanced_robots_meta' ], true )
				&& isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value )
			) {
				$merged[ $key ] = array_merge( $existing[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}
		$unchanged         = ( $merged === $existing );
		$all[ $post_type ] = $merged;

		$updated = update_option( self::OPTION_NAME, $all );

		// update_option() returns false both on a write failure and on a no-op
		// (value unchanged). Only the former is an error.
		if ( ! $updated && ! $unchanged ) {
			return new \WP_Error(
				'thinkrank_save_failed',
				__( 'Failed to save global SEO settings.', 'thinkrank' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'success'   => true,
			'message'   => __( 'Global SEO settings updated.', 'thinkrank' ),
			'post_type' => $post_type,
		];
	}
}
