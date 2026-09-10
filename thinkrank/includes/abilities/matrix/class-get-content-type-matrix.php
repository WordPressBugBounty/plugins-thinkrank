<?php
/**
 * Get content type matrix ability.
 *
 * @package ThinkRank\Abilities\Matrix
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Matrix;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Content_Type_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the per-content-type SEO feature matrix.
 *
 * Mirrors `GET /thinkrank/v1/global-seo/matrix`: every entity the site has
 * (post types, taxonomies, the blog index, author and date archives, search and
 * 404) against the features that can be switched for it.
 *
 * This ability is also how a caller discovers valid entity keys, which are not
 * guessable: a post type is stored under its bare slug, while the other
 * entities carry a prefix that cannot collide with one — `taxonomy:category`,
 * `archive:author`, `special:404`. Read this before calling
 * update-content-type-matrix.
 *
 * Every feature is three-state and defaults to `inherit`, which resolves to the
 * site-wide value returned under `globals`. That is why an untouched site emits
 * exactly what it emitted before the matrix existed.
 */
class Get_Content_Type_Matrix extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-content-type-matrix';
		$this->label       = __( 'Get ThinkRank Content Type Matrix', 'thinkrank' );
		$this->description = __( 'Read the per-content-type SEO feature matrix: for each post type, taxonomy, archive and special page, whether metas, schema, Open Graph, Twitter cards and analytics are inherited, forced on, or forced off, plus its robots directives and sitemap inclusion. Also returns the site-wide value each "inherit" resolves to. Use this to discover valid entity keys, then update-content-type-matrix to change one.', 'thinkrank' );
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
			'properties' => [
				'entities' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'key'              => [
								'type'        => 'string',
								'description' => __( 'Entity key. A post type is its bare slug; everything else is prefixed: taxonomy:, archive:, special:.', 'thinkrank' ),
							],
							'label'            => [ 'type' => 'string' ],
							'group'            => [
								'type' => 'string',
								'enum' => [ 'post_type', 'taxonomy', 'archive', 'special' ],
							],
							'features'         => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'description' => __( 'Which features this entity exposes. Search and 404 carry no schema, for example.', 'thinkrank' ),
							],
							'values'           => [
								'type'                 => 'object',
								'additionalProperties' => [
									'type' => 'string',
									'enum' => Content_Type_Settings::STATES,
								],
							],
							'robots_meta'      => [
								'type'                 => 'object',
								'additionalProperties' => true,
							],
							'sitemap_include'  => [ 'type' => [ 'boolean', 'null' ] ],
							'supports_sitemap' => [ 'type' => 'boolean' ],
						],
					],
				],
				'globals'  => [
					'type'                 => 'object',
					'additionalProperties' => [ 'type' => 'boolean' ],
					'description'          => __( 'The site-wide value each feature\'s "inherit" currently resolves to.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/thinkrank/v1/global-seo/matrix' ) );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = $response->get_data();

		return [
			'entities' => $data['data']['entities'] ?? [],
			'globals'  => $data['data']['globals'] ?? [],
		];
	}
}
