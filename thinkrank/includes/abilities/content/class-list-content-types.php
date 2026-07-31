<?php
/**
 * List content types ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lists the public post types and taxonomies available to ThinkRank.
 */
class List_Content_Types extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/list-content-types';
		$this->label       = __( 'List ThinkRank Content Types', 'thinkrank' );
		$this->description = __( 'List the public post types and taxonomies that ThinkRank can optimize.', 'thinkrank' );
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
				'post_types' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'  => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
				'taxonomies' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'  => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
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
		$post_types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type ) {
			$post_types[] = [
				'slug'  => (string) $post_type->name,
				'label' => (string) ( $post_type->labels->singular_name ?? $post_type->label ),
			];
		}

		$taxonomies = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
			$taxonomies[] = [
				'slug'  => (string) $taxonomy->name,
				'label' => (string) ( $taxonomy->labels->singular_name ?? $taxonomy->label ),
			];
		}

		return [
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
		];
	}
}
