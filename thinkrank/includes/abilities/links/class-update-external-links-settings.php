<?php
/**
 * Update external link settings ability.
 *
 * @package ThinkRank\Abilities\Links
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Writes the site-wide outbound-link handling settings.
 *
 * Mirrors `POST /thinkrank/v1/external-links/settings`, including its
 * normalisation: an exception typed as a full URL, or with a "www." prefix, is
 * reduced to the host the matcher compares against. The stored values are
 * returned rather than the submitted ones, so a caller can see what it actually
 * saved instead of assuming its input survived intact.
 *
 * Only the fields sent are changed; omitted fields keep their stored value.
 */
class Update_External_Links_Settings extends External_Links_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-external-links-settings';
		$this->label       = __( 'Update ThinkRank External Link Settings', 'thinkrank' );
		$this->description = __( 'Set the site-wide outbound-link settings: rel="nofollow" on external links, opening them in a new tab, and the list of excluded hosts. Only the fields you send are changed. Exceptions are normalised to bare hosts, and the stored values are returned so you can see what was saved. Call get-external-links-settings to read the current values first.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 0.8,
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
			'properties'           => $this->settings_properties(),
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
			'properties' => array_merge(
				[ 'success' => [ 'type' => 'boolean' ] ],
				$this->settings_properties()
			),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$input   = (array) $input;
		$payload = [];

		foreach ( [ 'nofollow_external', 'open_external_in_new_tab' ] as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$payload[ $key ] = (bool) $input[ $key ];
			}
		}

		if ( array_key_exists( 'external_link_exceptions', $input ) ) {
			$payload['external_link_exceptions'] = $this->manager()->normalize_exceptions(
				(array) $input['external_link_exceptions']
			);
		}

		if ( ! $payload ) {
			return new \WP_Error(
				'thinkrank_no_settings_provided',
				__( 'Provide at least one setting to change.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $this->manager()->save_settings( 'site', 0, $payload ) ) {
			$error = $this->manager()->get_last_save_error();

			return new \WP_Error(
				'thinkrank_external_links_save_failed',
				'' !== $error ? $error : __( 'Failed to update the external link settings.', 'thinkrank' ),
				[ 'status' => 500 ]
			);
		}

		$settings = $this->stored();

		return [
			'success'                  => true,
			'nofollow_external'        => (bool) ( $settings['nofollow_external'] ?? false ),
			'open_external_in_new_tab' => (bool) ( $settings['open_external_in_new_tab'] ?? false ),
			'external_link_exceptions' => $settings['external_link_exceptions'],
		];
	}
}
