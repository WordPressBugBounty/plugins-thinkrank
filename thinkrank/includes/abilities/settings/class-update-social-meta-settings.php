<?php
/**
 * Update social meta settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Social_Meta_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank social meta settings.
 *
 * Covers Open Graph, Twitter Cards, and per-network verification/handle
 * configuration persisted through the Social_Meta_Manager SEO manager.
 */
class Update_Social_Meta_Settings extends Ability_Base {
	/**
	 * Boolean-typed social meta keys.
	 */
	private const BOOL_KEYS = [
		'enable_open_graph',
		'enable_twitter_cards',
		'enable_linkedin',
		'enable_pinterest',
		'enable_instagram',
		'enable_tiktok',
		'enable_youtube',
		'enable_whatsapp',
		'auto_generate_descriptions',
		'fallback_to_excerpt',
		'strip_html_tags',
		'image_optimization',
		'auto_generate',
	];

	/**
	 * Integer-typed social meta keys.
	 */
	private const INT_KEYS = [
		'og_image_width',
		'og_image_height',
		'max_description_length',
	];

	/**
	 * String-typed social meta keys.
	 */
	private const STRING_KEYS = [
		'og_site_name',
		'og_description',
		'og_type',
		'og_locale',
		'default_og_image',
		'twitter_username',
		'twitter_creator',
		'twitter_card_type',
		'default_twitter_image',
		'twitter_site',
		'default_image',
		'facebook_app_id',
		'facebook_admins',
		'pinterest_site_verification',
		'instagram_verification',
		'tiktok_verification',
		'youtube_channel_id',
		'whatsapp_business_id',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-social-meta-settings';
		$this->label       = __( 'Update ThinkRank Social Meta Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank social meta settings, covering Open Graph, Twitter Cards, and per-network verification and handle configuration.', 'thinkrank' );
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
	 * Build the JSON schema properties for social meta settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_properties() {
		$props = [];

		foreach ( self::BOOL_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'boolean' ];
		}
		foreach ( self::INT_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'integer' ];
		}
		foreach ( self::STRING_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'string' ];
		}

		return $props;
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
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Social meta settings to update.', 'thinkrank' ),
					'properties'  => self::schema_properties(),
				],
			],
			'required'             => [ 'settings' ],
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
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_missing_social_meta_settings_payload',
				__( 'A social meta settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$mgr    = new Social_Meta_Manager();
		$merged = $mgr->get_settings( 'site', null );
		$found  = false;

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (bool) $settings[ $key ];
				$found          = true;
			}
		}
		foreach ( self::INT_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (int) $settings[ $key ];
				$found          = true;
			}
		}
		foreach ( self::STRING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
				$found          = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_social_meta_setting_keys',
				__( 'No valid social meta setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $mgr->save_settings( 'site', null, $merged );

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Social meta settings updated.', 'thinkrank' )
				: __( 'Failed to update social meta settings.', 'thinkrank' ),
		];
	}
}
