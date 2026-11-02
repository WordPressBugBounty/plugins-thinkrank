<?php
/**
 * Shared base for the WooCommerce settings abilities.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Integrations\WooCommerce_Settings;
use ThinkRank\Integrations\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Schema fragments and the stored-value read for the free WooCommerce settings.
 *
 * Mirrors {@see \ThinkRank\API\WooCommerce_Endpoint}. ThinkRank Pro's own
 * WooCommerce SEO abilities cover its product identifier and product Open
 * Graph settings; these cover the free ones (#1478).
 */
abstract class Woocommerce_Settings_Ability_Base extends Ability_Base {

	/**
	 * Accepted brand taxonomy values: '' for Automatic, every taxonomy
	 * registered on products that can hold a brand, and what is stored.
	 *
	 * The stored value may name a taxonomy whose plugin has gone. It stays
	 * accepted, as in {@see WooCommerce_Settings::validate()}, so what
	 * get-woocommerce-settings returned can be sent back (#1530).
	 *
	 * @return string[]
	 */
	protected function brand_taxonomy_enum(): array {
		return array_values(
			array_unique(
				array_merge(
					[ '', WooCommerce_Settings::brand_taxonomy() ],
					array_map( 'strval', array_keys( WooCommerce_Settings::brand_taxonomy_choices() ) )
				)
			)
		);
	}

	/**
	 * Schema for the three settings.
	 *
	 * @return array<string, mixed>
	 */
	protected function settings_properties(): array {
		return [
			'brand_taxonomy'      => [
				'type'        => 'string',
				'enum'        => $this->brand_taxonomy_enum(),
				/* translators: %brand% is a literal title variable, not a printf placeholder. */
				'description' => __( 'The product taxonomy that holds brands, used for the Product schema brand, the product:brand Open Graph tag and the %brand% title token. An empty string is Automatic: product_brand, pwb-brand, yith_product_brand, then berocket_brand, whichever has a term first.', 'thinkrank' ),
			],
			'shop_archive_schema' => [
				'type'        => 'boolean',
				'description' => __( 'Whether the shop page and product category and tag archives carry an ItemList of the products on the page (name, URL, image, offer) in their CollectionPage schema. Off is the equivalent of Rank Math\'s "Remove Schema Markup on Shop Archives".', 'thinkrank' ),
			],
			'remove_generator'    => [
				'type'        => 'boolean',
				'description' => __( 'Whether to remove the <meta name="generator" content="WooCommerce …"> tag WooCommerce prints in the page head.', 'thinkrank' ),
			],
		];
	}

	/**
	 * Output schema: the settings plus what the site offers.
	 *
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		$settings = $this->settings_properties();

		// No enum on the way out: a stored taxonomy whose brand plugin was
		// deactivated since is still what is stored (and falls back to
		// Automatic at render time), and an enum would fail the whole call.
		unset( $settings['brand_taxonomy']['enum'] );

		return array_merge(
			$settings,
			[
				'woocommerce_active'         => [
					'type'        => 'boolean',
					'description' => __( 'Whether WooCommerce is active. The settings are stored either way but only take effect on a WooCommerce site.', 'thinkrank' ),
				],
				'available_brand_taxonomies' => [
					'type'        => 'array',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'value' => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
					'description' => __( 'The taxonomies registered on products that brand_taxonomy accepts.', 'thinkrank' ),
				],
			]
		);
	}

	/**
	 * What is stored, in the output shape.
	 *
	 * @return array<string, mixed>
	 */
	protected function stored(): array {
		$taxonomies = [];
		foreach ( WooCommerce_Settings::brand_taxonomy_choices() as $name => $label ) {
			$taxonomies[] = [
				'value' => (string) $name,
				'label' => (string) $label,
			];
		}

		return array_merge(
			WooCommerce_Settings::get(),
			[
				'woocommerce_active'         => Woocommerce::is_active(),
				'available_brand_taxonomies' => $taxonomies,
			]
		);
	}
}
