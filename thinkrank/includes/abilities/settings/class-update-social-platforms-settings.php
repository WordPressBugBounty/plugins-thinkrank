<?php
/**
 * Update social platforms settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank social platform verification settings.
 *
 * Mirrors `POST /thinkrank/v1/social-platforms/settings`: accepts any subset of
 * the public platform IDs and sensitive verification codes, sanitizes them, and
 * persists only the non-empty allowed keys via the Settings store (which
 * encrypts the sensitive verification codes at rest). Returns the resulting
 * settings with sensitive codes masked.
 */
class Update_Social_Platforms_Settings extends Ability_Base {
	/**
	 * Public platform IDs.
	 */
	private const PUBLIC_KEYS = [
		'facebook_app_id',
		'facebook_admins',
		'youtube_channel_id',
		'whatsapp_business_id',
	];

	/**
	 * Sensitive verification codes (encrypted at rest by the Settings store).
	 */
	private const SENSITIVE_KEYS = [
		'pinterest_site_verification',
		'instagram_verification',
		'tiktok_verification',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-social-platforms-settings';
		$this->label       = __( 'Update ThinkRank Social Platform Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank social platform verification settings: public platform IDs (Facebook, YouTube, WhatsApp) and sensitive site-verification codes (Pinterest, Instagram, TikTok). Only non-empty provided keys are saved; sensitive codes are stored encrypted.', 'thinkrank' );
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
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'Social platform settings to update (any subset of the fields below).', 'thinkrank' ),
					'additionalProperties' => false,
					'properties'           => [
						'facebook_app_id'             => [ 'type' => 'string' ],
						'facebook_admins'             => [ 'type' => 'string' ],
						'youtube_channel_id'          => [ 'type' => 'string' ],
						'whatsapp_business_id'        => [ 'type' => 'string' ],
						'pinterest_site_verification' => [ 'type' => 'string' ],
						'instagram_verification'      => [ 'type' => 'string' ],
						'tiktok_verification'         => [ 'type' => 'string' ],
					],
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
				'success'  => [ 'type' => 'boolean' ],
				'message'  => [ 'type' => 'string' ],
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'The resulting settings, with sensitive codes masked.', 'thinkrank' ),
					'additionalProperties' => true,
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
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_missing_social_platforms_settings_payload',
				__( 'A social platform settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$store   = Settings::instance();
		$allowed = array_merge( self::PUBLIC_KEYS, self::SENSITIVE_KEYS );
		$found   = false;

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $settings[ $key ] );

			// Mirror the REST endpoint: only persist non-empty values so a
			// masked/blank round-trip never wipes an existing code.
			if ( '' === $value ) {
				continue;
			}

			$store->set( $key, $value );
			$found = true;
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_social_platforms_setting_keys',
				__( 'No valid, non-empty social platform setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'success'  => true,
			'message'  => __( 'Social platform settings updated.', 'thinkrank' ),
			'settings' => $this->read_masked( $store ),
		];
	}

	/**
	 * Read current settings back with sensitive codes masked.
	 *
	 * @param Settings $store Settings store.
	 * @return array<string, string>
	 */
	private function read_masked( Settings $store ): array {
		$out = [];

		foreach ( self::PUBLIC_KEYS as $key ) {
			$out[ $key ] = (string) $store->get( $key, '' );
		}

		foreach ( self::SENSITIVE_KEYS as $key ) {
			$code = (string) $store->get( $key, '' );

			if ( '' === $code ) {
				$out[ $key ] = '';
			} elseif ( strlen( $code ) > 8 ) {
				$out[ $key ] = substr( $code, 0, 4 ) . 'XXXX';
			} else {
				$out[ $key ] = 'XXXX';
			}
		}

		return $out;
	}
}
