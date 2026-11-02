<?php
/**
 * Get permalink settings ability.
 *
 * @package ThinkRank\Abilities\Permalinks
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Permalinks;

use ThinkRank\API\Permalinks_Endpoint;
use ThinkRank\SEO\Permalinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the URL base switches and the bases the site uses.
 *
 * Mirrors `GET /thinkrank/v1/permalinks/settings`.
 */
class Get_Permalink_Settings extends Permalinks_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-permalink-settings';
		$this->label       = __( 'Get ThinkRank Permalink Settings', 'thinkrank' );
		$this->description = __( 'Read which URL bases ThinkRank removes: the post category base, and the WooCommerce product base, product category base and product category parent slugs. Also returns the bases the site uses and whether WooCommerce and pretty permalinks are active. Old URLs are redirected to the new ones whichever way a switch is set. Use update-permalink-settings to change them.', 'thinkrank' );
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
			'priority'      => 0.6,
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
			'properties'           => new \stdClass(),
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
				$this->settings_properties(),
				[
					'context' => [
						'type'       => 'object',
						'properties' => [
							'woocommerce_active'    => [ 'type' => 'boolean' ],
							'pretty_permalinks'     => [ 'type' => 'boolean' ],
							'product_base'          => [ 'type' => 'string' ],
							'product_category_base' => [ 'type' => 'string' ],
							'category_base'         => [ 'type' => 'string' ],
							'category_blog_prefix'  => [
								'type'        => 'string',
								'description' => __( 'What a post category keeps in front of its base-free path: "blog/" on the main site of a subdirectory network, otherwise empty.', 'thinkrank' ),
							],
							'home_url'              => [ 'type' => 'string' ],
							'categories_keeping_base' => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'description' => __( 'Post categories that keep their base because a product category, page or post holds the same path, or WordPress reserves it, such as a date archive or the REST API.', 'thinkrank' ),
							],
							'product_categories_keeping_base' => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'description' => __( 'Product categories that keep their base because a page or post holds the same path, or WordPress reserves it, such as a date archive or the REST API.', 'thinkrank' ),
							],
							'products_keeping_base' => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'description' => __( 'Slugs of products that keep their base because a page, post, category or media file holds the same slug at the site root, or WordPress reads that address as something else, such as a date archive.', 'thinkrank' ),
							],
							'products_keep_base_under_structure' => [
								'type'        => 'boolean',
								'description' => __( 'True when the permalink structure gives no product a working address at the site root, so every product keeps its base while remove_product_base is on, and products_keeping_base stays empty rather than naming them all.', 'thinkrank' ),
							],
						],
					],
				]
			),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		return Permalinks::settings() + [ 'context' => Permalinks_Endpoint::context() ];
	}
}
