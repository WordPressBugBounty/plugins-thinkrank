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

		// "Configured" must mean "this integration can actually make a call",
		// derived from the SAME conditions the client-initialization code uses.
		//
		// It previously keyed off three manual `*_api_key` options that no
		// current code path writes — the OAuth connect flow stores tokens only
		// (Google_OAuth_Proxy) and the property pickers store property ids — so
		// an OAuth-connected site actively serving GA4/GSC/PSI data reported
		// `configured: false` for all three while the same payload reported
		// `google_account_connected: true`. The report contradicted itself on
		// the one thing it exists to answer, which is worse than useless to an
		// agent deciding whether an integration is available.
		//
		// The keys are retained as OR-branches: they are optional for data
		// retrieval but still honored when present (see
		// Google_PageSpeed_Client::for_site()).
		$oauth_present = '' !== (string) $settings->get( 'google_access_token', '' );

		// GA4: Analytics_Manager builds the Data API client only when a token
		// AND a selected property both exist — mirror that exactly.
		$ga_property   = (string) $settings->get( 'seo_analytics_google_analytics_property_id', '' );
		$ga_configured = ( $oauth_present && '' !== $ga_property )
			|| '' !== (string) $settings->get( 'google_analytics_api_key', '' );

		// Search Console: the client authenticates with the token; the API key
		// is passed as '' on an OAuth site. A property is not required to
		// construct it (Analytics_Manager falls back to the site URL).
		$sc_property   = (string) $settings->get( 'search_console_property', '' );
		$sc_configured = $oauth_present
			|| '' !== (string) $settings->get( 'google_search_console_api_key', '' );

		// PageSpeed: for_site() prefers a dedicated key, else the OAuth token,
		// else runs keyless on the per-IP quota. Report credentialed state.
		$ps_key        = (string) $settings->get( 'google_pagespeed_api_key', '' );
		$ps_configured = '' !== $ps_key || $oauth_present;

		// ThinkRank's own tag-injection state (see the google_analytics block).
		$ga4_measurement_id = (string) $settings->get( 'ga4_measurement_id', '' );
		$ga4_verified       = (bool) $settings->get( 'ga4_tracking_verified', false );

		return [
			'google_account_connected' => (bool) $settings->get( 'google_account_connected', false ),
			'google_analytics'         => [
				'configured'        => $ga_configured,
				// The selected GA4 property, in the Admin API's
				// "properties/XXXXXXXX" form. Empty means no property picked,
				// which is the usual reason GA is connected but unusable.
				'property_id'       => $ga_property,

				// The two fields below describe ThinkRank's OWN optional
				// tag-injection feature — NOT whether the site has a working
				// GA4 tag. On an OAuth-connected site that never used
				// injection they are legitimately empty/false, and a client
				// read that as "GA4 tracking is broken" on a site whose tag
				// was working fine (#250). They are nested and named for what
				// they actually are so the payload cannot be misread; the
				// question "can ThinkRank read Analytics data?" is answered by
				// `configured` above.
				//
				// Kept at the top level as well, deprecated, so an existing
				// consumer does not break on this release.
				'tag_injection'     => [
					'measurement_id'      => $ga4_measurement_id,
					'auto_inject_enabled' => (bool) $settings->get( 'ga4_auto_inject', false ),
					'last_check_passed'   => $ga4_verified,
					'last_checked'        => (string) $settings->get( 'ga4_last_verification', '' ),
				],

				/** @deprecated 1.27.0 Use tag_injection.measurement_id. */
				'measurement_id'    => $ga4_measurement_id,
				/** @deprecated 1.27.0 Use tag_injection.last_check_passed. */
				'tracking_verified' => $ga4_verified,
			],
			'search_console'           => [
				'configured' => $sc_configured,
				// Was reading `google_search_console_site` — a key with no
				// writer anywhere in the plugin (only two disconnect paths
				// clear it), so this was unconditionally ''. The property
				// picker writes `search_console_property`.
				'site'       => $sc_property,
			],
			'pagespeed'                => [
				'configured'      => $ps_configured,
				'oauth_connected' => $oauth_present,
				// Distinguishes "own key, own quota" from "shared OAuth quota"
				// — the difference between reliable and 429-prone PSI runs.
				'has_api_key'     => '' !== $ps_key,
			],
		];
	}
}
