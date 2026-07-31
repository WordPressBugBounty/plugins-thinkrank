<?php
/**
 * Get social platforms settings ability.
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
 * Retrieves ThinkRank social platform verification settings.
 *
 * Mirrors `GET /thinkrank/v1/social-platforms/settings`: returns the public
 * platform IDs (Facebook app id/admins, YouTube channel, WhatsApp business id)
 * verbatim and the sensitive site-verification codes (Pinterest, Instagram,
 * TikTok) masked to a "first four + XXXX" preview so full codes are never
 * exposed to an AI client. Read-only.
 */
class Get_Social_Platforms_Settings extends Ability_Base {
	/**
	 * Public platform IDs returned verbatim.
	 */
	private const PUBLIC_KEYS = [
		'facebook_app_id',
		'facebook_admins',
		'youtube_channel_id',
		'whatsapp_business_id',
	];

	/**
	 * Sensitive verification codes returned masked.
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
		$this->id          = 'thinkrank/get-social-platforms-settings';
		$this->label       = __( 'Get ThinkRank Social Platform Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank social platform verification settings: public platform IDs (Facebook, YouTube, WhatsApp) verbatim and sensitive site-verification codes (Pinterest, Instagram, TikTok) masked for security.', 'thinkrank' );
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
			'properties'           => [],
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
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'Social platform IDs (verbatim) and verification codes (masked).', 'thinkrank' ),
					'properties'           => [
						'facebook_app_id'             => [ 'type' => 'string' ],
						'facebook_admins'             => [ 'type' => 'string' ],
						'youtube_channel_id'          => [ 'type' => 'string' ],
						'whatsapp_business_id'        => [ 'type' => 'string' ],
						'pinterest_site_verification' => [ 'type' => 'string' ],
						'instagram_verification'      => [ 'type' => 'string' ],
						'tiktok_verification'         => [ 'type' => 'string' ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$settings = Settings::instance();
		$out      = [];

		foreach ( self::PUBLIC_KEYS as $key ) {
			$out[ $key ] = (string) $settings->get( $key, '' );
		}

		foreach ( self::SENSITIVE_KEYS as $key ) {
			$out[ $key ] = $this->mask_verification_code( (string) $settings->get( $key, '' ) );
		}

		return [ 'settings' => $out ];
	}

	/**
	 * Mask a verification code: first four chars + "XXXX", or "XXXX" when short.
	 *
	 * @param string $code Raw verification code.
	 * @return string Masked code, or empty string when unset.
	 */
	private function mask_verification_code( string $code ): string {
		if ( '' === $code ) {
			return '';
		}

		if ( strlen( $code ) > 8 ) {
			return substr( $code, 0, 4 ) . 'XXXX';
		}

		return 'XXXX';
	}
}
