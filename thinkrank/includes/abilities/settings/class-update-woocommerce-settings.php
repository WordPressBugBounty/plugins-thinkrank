<?php
/**
 * Update WooCommerce settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Integrations\WooCommerce_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Writes the free WooCommerce settings (#1478).
 *
 * Mirrors `POST /thinkrank/v1/woocommerce/settings`: only the fields sent
 * change, an unknown brand taxonomy is refused rather than stored, and what is
 * stored afterwards is returned.
 */
class Update_Woocommerce_Settings extends Woocommerce_Settings_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-woocommerce-settings';
		$this->label       = __( 'Update ThinkRank WooCommerce Settings', 'thinkrank' );
		$this->description = __( 'Set the free WooCommerce settings: brand_taxonomy (the product taxonomy that holds brands, or an empty string for Automatic), shop_archive_schema (the product ItemList on the shop and product category/tag archives) and remove_generator (drop WooCommerce\'s generator meta tag). Only the fields you send are changed, and the stored values are returned. A brand_taxonomy that is not registered on products is refused, unless it is the value already stored; call get-woocommerce-settings for the accepted ones. ThinkRank Pro\'s product identifier and product Open Graph settings are not changed here; Pro has its own WooCommerce SEO tool for them.', 'thinkrank' );
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
				$this->output_properties()
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
		$payload = array_intersect_key( (array) $input, WooCommerce_Settings::defaults() );

		if ( ! $payload ) {
			return new \WP_Error(
				'thinkrank_no_settings_provided',
				__( 'Provide at least one setting to change.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$error = WooCommerce_Settings::validate( $payload );
		if ( null !== $error ) {
			return $error;
		}

		WooCommerce_Settings::save( $payload );

		return array_merge( [ 'success' => true ], $this->stored() );
	}
}
