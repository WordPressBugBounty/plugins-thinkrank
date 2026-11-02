<?php
/**
 * Update permalink settings ability.
 *
 * @package ThinkRank\Abilities\Permalinks
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Permalinks;

use ThinkRank\SEO\Permalinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Writes the URL base switches.
 *
 * Mirrors `POST /thinkrank/v1/permalinks/settings`. Only the switches sent
 * change; the stored values are returned.
 */
class Update_Permalink_Settings extends Permalinks_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-permalink-settings';
		$this->label       = __( 'Update ThinkRank Permalink Settings', 'thinkrank' );
		$this->description = __( 'Turn URL base removal on or off: the post category base, and the WooCommerce product base, product category base and product category parent slugs. This changes the public URL of every affected page; the old URLs are answered with a 301 to the new ones. Only the switches you send change, and the stored values are returned. Call get-permalink-settings first.', 'thinkrank' );
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
			'priority'      => 0.5,
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
				$this->settings_properties(),
				[
					'cache_warnings' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => __( 'Page caches that still serve the old pages and must be cleared by hand. Empty when every cache was cleared.', 'thinkrank' ),
					],
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
				]
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
		$payload = array_intersect_key( (array) $input, Permalinks::DEFAULTS );

		if ( ! $payload ) {
			return new \WP_Error(
				'thinkrank_no_settings_provided',
				__( 'Provide at least one setting to change.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$settings = Permalinks::save( $payload );

		// Page caches still hold the old pages under the old URLs; what could
		// not be cleared is passed on so the caller can tell the user.
		return [ 'success' => true ] + $settings + [
			'cache_warnings'          => Permalinks::purge_changed_urls(),
			'categories_keeping_base' => Permalinks::categories_keeping_base(),
			'product_categories_keeping_base' => Permalinks::product_categories_keeping_base(),
			'products_keeping_base'   => Permalinks::products_keeping_base(),
			'products_keep_base_under_structure' => Permalinks::products_keep_base_under_structure(),
		];
	}
}
