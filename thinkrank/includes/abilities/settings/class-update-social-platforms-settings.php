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
use ThinkRank\SEO\Social_Meta_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank social platform verification settings.
 *
 * Mirrors `POST /thinkrank/v1/social-platforms/settings`: accepts any subset of
 * the public platform IDs and sensitive verification codes, sanitizes them, and
 * persists only the non-empty allowed keys via the Settings store (which
 * encrypts the sensitive verification codes at rest). The four search-engine
 * verification codes share the route's write contract through
 * Social_Meta_Manager::sanitize_verification_codes(): an empty value clears
 * the code and a pasted meta tag is reduced to it. Returns the resulting
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
		// Search-engine verification (#631). PUBLIC, not sensitive: each is
		// printed in the page head, so masking it would hide from an agent a
		// value it can read by fetching the page.
		'google_site_verification',
		'bing_site_verification',
		'yandex_site_verification',
		'baidu_site_verification',
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
		$this->description = __( 'Update ThinkRank social platform verification settings: public platform IDs (Facebook, YouTube, WhatsApp), search-engine verification codes (Google, Bing, Yandex, Baidu) and sensitive site-verification codes (Pinterest, Instagram, TikTok). Only non-empty provided keys are saved, except that an empty search-engine code removes it; a pasted verification meta tag is reduced to its code. Sensitive codes are stored encrypted. Read the current values with get-social-platforms-settings first.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Settings writes are recoverable: the matching get-* ability reads
			// the previous value, so nothing is lost that cannot be put back.
			// `destructive` is reserved for calls that lose data or reach
			// outside the site, and marking routine configuration with it made
			// MCP clients demand a human approval for every save — which users
			// reported as a permission bug, because the client's refusal reads
			// as "No approval received" (#675).
			'destructive'   => false,
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
						'google_site_verification'    => [ 'type' => 'string' ],
						'bing_site_verification'      => [ 'type' => 'string' ],
						'yandex_site_verification'    => [ 'type' => 'string' ],
						'baidu_site_verification'     => [ 'type' => 'string' ],
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

		// The four search-engine codes follow the REST route's contract, not
		// the rule below: a pasted tag is reduced to its code instead of being
		// sanitised into attribute soup, and an explicit empty string removes
		// the code, since otherwise an agent asked to take a verification tag
		// off the page reports success while the tag stays.
		$codes  = Social_Meta_Manager::sanitize_verification_codes( $settings );
		$errors = Social_Meta_Manager::unusable_verification_codes( $settings );

		foreach ( $codes as $key => $code ) {
			$error = Social_Meta_Manager::validate_platform_field( $key, $code );

			if ( null !== $error ) {
				$errors[ $key ] = $error;
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'thinkrank_invalid_social_platforms_settings',
				__( 'One or more social platform values are in an invalid format.', 'thinkrank' ),
				[
					'status' => 400,
					'errors' => $errors,
				]
			);
		}

		foreach ( $codes as $key => $code ) {
			$store->set( $key, $code );
			$found = true;
		}

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $settings ) || in_array( $key, Social_Meta_Manager::get_site_verification_keys(), true ) ) {
				continue;
			}

			// A pasted Pinterest tag becomes its code, as on the REST route (#1684).
			$value = Social_Meta_Manager::sanitize_platform_value( $key, (string) $settings[ $key ] );

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
