<?php
/**
 * Get integrations status ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports the connection status of ThinkRank's Google integrations.
 *
 * Covers Google Analytics (GA4), Search Console, and PageSpeed. Returns only
 * non-sensitive status — connected/configured booleans, the public GA4
 * measurement ID, and the selected Search Console site. It deliberately never
 * returns API keys, OAuth access/refresh tokens, or any secret material.
 */
class Get_Integrations_Status extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-integrations-status';
		$this->label       = __( 'Get Integrations Status', 'thinkrank' );
		$this->description = __( 'Report the connection status of the Google Analytics, Search Console, and PageSpeed integrations (connected/configured flags, GA4 measurement ID, selected Search Console site). Never returns API keys or OAuth tokens.', 'thinkrank' );
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
				'google_account_connected' => [ 'type' => 'boolean' ],
				'google_analytics'         => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'search_console'           => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'pagespeed'                => [
					'type'                 => 'object',
					'additionalProperties' => true,
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

		// Derive "configured" from presence only — never surface the value itself.
		$ga_configured = '' !== (string) $settings->get( 'google_analytics_api_key', '' );
		$sc_configured = '' !== (string) $settings->get( 'google_search_console_api_key', '' );
		$ps_configured = '' !== (string) $settings->get( 'google_pagespeed_api_key', '' );
		$oauth_present = '' !== (string) $settings->get( 'google_access_token', '' );

		return [
			'google_account_connected' => (bool) $settings->get( 'google_account_connected', false ),
			'google_analytics'         => [
				'configured'        => $ga_configured,
				'measurement_id'    => (string) $settings->get( 'ga4_measurement_id', '' ),
				'tracking_verified' => (bool) $settings->get( 'ga4_tracking_verified', false ),
			],
			'search_console'           => [
				'configured' => $sc_configured,
				'site'       => (string) $settings->get( 'google_search_console_site', '' ),
			],
			'pagespeed'                => [
				'configured'      => $ps_configured,
				'oauth_connected' => $oauth_present,
			],
		];
	}
}
