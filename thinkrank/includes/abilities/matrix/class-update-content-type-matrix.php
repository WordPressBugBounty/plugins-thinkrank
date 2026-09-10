<?php
/**
 * Update content type matrix ability.
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
 * Writes one entity's row of the per-content-type SEO feature matrix.
 *
 * Mirrors `POST /thinkrank/v1/global-seo/matrix`, which takes one entity at a
 * time. Only the features sent are changed.
 *
 * Two things a caller has to get right, both enforced here rather than left to
 * fail quietly:
 *
 * - The entity key is not guessable. A post type is its bare slug, everything
 *   else is prefixed (`taxonomy:category`, `archive:author`, `special:404`). An
 *   unknown key is refused with the valid ones named, instead of writing a row
 *   nothing reads.
 * - A feature the entity does not expose is dropped by the endpoint. Sending
 *   `schema_enabled` for `special:search` would otherwise report success while
 *   storing nothing, so it is refused up front.
 */
class Update_Content_Type_Matrix extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-content-type-matrix';
		$this->label       = __( 'Update ThinkRank Content Type Matrix', 'thinkrank' );
		$this->description = __( 'Set the SEO features for one content type: metas, schema, Open Graph, Twitter cards and analytics, each "inherit", "on" or "off", plus robots noindex and sitemap inclusion. Call get-content-type-matrix first for valid entity keys; a post type is its bare slug, other entities are prefixed with taxonomy:, archive: or special:.', 'thinkrank' );
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
		$properties = [
			'entity' => [
				'type'        => 'string',
				'description' => __( 'Entity key from get-content-type-matrix, e.g. "post", "taxonomy:category", "archive:author", "special:404".', 'thinkrank' ),
			],
		];

		foreach ( Content_Type_Settings::FEATURES as $feature ) {
			$properties[ $feature ] = [
				'type' => 'string',
				'enum' => Content_Type_Settings::STATES,
			];
		}

		$properties['noindex']         = [
			'type'        => 'boolean',
			'description' => __( 'Whether this entity is noindexed. Search results and the 404 page are noindexed by default.', 'thinkrank' ),
		];
		$properties['sitemap_include'] = [
			'type'        => 'boolean',
			'description' => __( 'Whether this entity belongs in the XML sitemap. Only accepted for entities the sitemap generator emits.', 'thinkrank' ),
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'entity' ],
			'properties'           => $properties,
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
				'success' => [ 'type' => 'boolean' ],
				'entity'  => [ 'type' => 'string' ],
				'data'    => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'The entity as stored after the write: resolved feature values, robots directives and sitemap inclusion.', 'thinkrank' ),
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
		$input  = (array) $input;
		$entity = (string) ( $input['entity'] ?? '' );

		$descriptor = null;
		$keys       = [];

		foreach ( Content_Type_Settings::get_entities() as $candidate ) {
			$keys[] = $candidate['key'];
			if ( $candidate['key'] === $entity ) {
				$descriptor = $candidate;
			}
		}

		if ( null === $descriptor ) {
			return new \WP_Error(
				'thinkrank_unknown_entity',
				sprintf(
					/* translators: 1: the entity key that was sent. 2: comma-separated list of valid keys. */
					__( 'Unknown entity "%1$s". Valid keys on this site: %2$s.', 'thinkrank' ),
					$entity,
					implode( ', ', $keys )
				),
				[ 'status' => 400 ]
			);
		}

		$settings = [];

		foreach ( Content_Type_Settings::FEATURES as $feature ) {
			if ( ! array_key_exists( $feature, $input ) ) {
				continue;
			}

			// The endpoint drops a feature this entity does not expose. Silently
			// accepting it would report a success that stored nothing.
			if ( ! in_array( $feature, (array) $descriptor['features'], true ) ) {
				return new \WP_Error(
					'thinkrank_feature_not_available',
					sprintf(
						/* translators: 1: feature key. 2: entity key. */
						__( '"%1$s" is not available for "%2$s".', 'thinkrank' ),
						$feature,
						$entity
					),
					[ 'status' => 400 ]
				);
			}

			$settings[ $feature ] = (string) $input[ $feature ];
		}

		if ( array_key_exists( 'noindex', $input ) ) {
			$noindex  = (bool) $input['noindex'];
			$resolved = Content_Type_Settings::resolve_robots_meta( $entity );

			// index and noindex are one decision stored as two booleans; writing
			// only half leaves the pair saying two different things.
			$resolved['noindex'] = $noindex;
			$resolved['index']   = ! $noindex;

			$settings['robots_meta_enabled'] = true;
			$settings['robots_meta']         = $resolved;
		}

		if ( array_key_exists( 'sitemap_include', $input ) ) {
			if ( empty( $descriptor['supports_sitemap'] ) ) {
				return new \WP_Error(
					'thinkrank_sitemap_not_supported',
					sprintf(
						/* translators: %s: entity key. */
						__( '"%s" is not emitted in the sitemap, so its inclusion cannot be set.', 'thinkrank' ),
						$entity
					),
					[ 'status' => 400 ]
				);
			}

			$settings['sitemap_include'] = (bool) $input['sitemap_include'];
		}

		if ( ! $settings ) {
			return new \WP_Error(
				'thinkrank_no_settings_provided',
				__( 'Provide at least one feature, noindex, or sitemap_include to change.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$request = new \WP_REST_Request( 'POST', '/thinkrank/v1/global-seo/matrix' );
		$request->set_param( 'entity', $entity );
		$request->set_param( 'settings', $settings );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = $response->get_data();

		return [
			'success' => true,
			'entity'  => $entity,
			'data'    => $data['data'] ?? [],
		];
	}
}
