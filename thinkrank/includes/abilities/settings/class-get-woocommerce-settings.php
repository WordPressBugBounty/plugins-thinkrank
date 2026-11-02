<?php
/**
 * Get WooCommerce settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the free WooCommerce settings (#1478).
 *
 * Mirrors `GET /thinkrank/v1/woocommerce/settings`. Read-only.
 */
class Get_Woocommerce_Settings extends Woocommerce_Settings_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-woocommerce-settings';
		$this->label       = __( 'Get ThinkRank WooCommerce Settings', 'thinkrank' );
		/* translators: %brand% is a literal title variable, not a printf placeholder. */
		$this->description = __( 'Read the free WooCommerce settings: which product taxonomy holds brands (for Product schema, product:brand and %brand%), whether the shop and product category/tag archives carry a product ItemList in their schema, and whether WooCommerce\'s generator meta tag is removed. Also returns whether WooCommerce is active and the taxonomies brand_taxonomy accepts. ThinkRank Pro\'s product identifier and product Open Graph settings are not part of this; Pro has its own WooCommerce SEO tool for them. Use update-woocommerce-settings to change these.', 'thinkrank' );
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
			'properties'           => self::empty_properties(),
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
			'properties' => $this->output_properties(),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		return $this->stored();
	}
}
