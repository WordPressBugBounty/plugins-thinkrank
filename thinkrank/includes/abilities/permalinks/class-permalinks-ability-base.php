<?php
/**
 * Shared base for the permalink abilities.
 *
 * @package ThinkRank\Abilities\Permalinks
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Permalinks;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Permalinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Schema fragments for the URL base switches.
 *
 * Mirrors {@see \ThinkRank\API\Permalinks_Endpoint}; the switches themselves
 * live in {@see Permalinks}.
 */
abstract class Permalinks_Ability_Base extends Ability_Base {

	/**
	 * Schema for the four switches, keyed as {@see Permalinks::DEFAULTS}.
	 *
	 * @return array<string, mixed>
	 */
	protected function settings_properties(): array {
		$descriptions = [
			'remove_category_base'            => __( 'Publish post categories without their base: /category/news/ becomes /news/.', 'thinkrank' ),
			'remove_product_base'             => __( 'Publish WooCommerce products without their base: /product/blue-shirt/ becomes /blue-shirt/. A base with %product_cat% keeps the category path.', 'thinkrank' ),
			'remove_product_category_base'    => __( 'Publish WooCommerce product categories without their base: /product-category/jerseys/ becomes /jerseys/.', 'thinkrank' ),
			'remove_product_category_parents' => __( 'Publish WooCommerce product categories with their own slug only: /product-category/kits/jerseys/ becomes /product-category/jerseys/.', 'thinkrank' ),
		];

		$properties = [];
		foreach ( array_keys( Permalinks::DEFAULTS ) as $key ) {
			$properties[ $key ] = [
				'type'        => 'boolean',
				'description' => $descriptions[ $key ],
			];
		}

		return $properties;
	}
}
