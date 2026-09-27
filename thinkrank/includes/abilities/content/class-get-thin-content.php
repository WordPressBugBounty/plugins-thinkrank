<?php
/**
 * Get thin content ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Thin_Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports published pages whose body is shorter than the threshold for their
 * post type (#565).
 *
 * The count is the length a visitor reads, resolved through the page builder
 * where one is in use, so an Elementor or Bricks page reports its real length
 * rather than the empty `post_content` column an agent would otherwise see.
 */
class Get_Thin_Content extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-thin-content';
		$this->label       = __( 'Get ThinkRank Thin Content', 'thinkrank' );
		$this->description = __( 'Find published pages with too little content, grouped by post type, thinnest first. The length is what a visitor reads: page builder content (Elementor, Divi, Oxygen, Beaver, Bricks) is resolved, so a builder page is not reported as empty. Each post type has its own threshold, since a short product page is not a short blog post; change one with update-thin-content-settings. Covers published content only. Lengths are in words, or in characters for locales that count characters - read the unit field rather than assuming.', 'thinkrank' );
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
			'properties'           => [
				'post_type' => [
					'type'        => 'string',
					'enum'        => array_merge( [ '' ], Thin_Content::post_types() ),
					'default'     => '',
					'description' => __( 'Only report this post type. Empty reports every post type that has published content.', 'thinkrank' ),
				],
				'refresh'   => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Rebuild the report instead of reading the cached one. The cache is already discarded when content or a threshold changes, so this is only needed to force a rescan.', 'thinkrank' ),
				],
			],
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
				'pending' => [
					'type'        => 'integer',
					'description' => __( 'Pages not counted yet. When above zero the numbers are a floor, not the answer; call again to continue. Counting resolves builder content, so it is done a batch at a time.', 'thinkrank' ),
				],
				'counted' => [
					'type'        => 'integer',
					'description' => __( 'Published pages the report covers.', 'thinkrank' ),
				],
				'thin'    => [
					'type'        => 'integer',
					'description' => __( 'Pages under their post type threshold.', 'thinkrank' ),
				],
				'unit'    => [
					'type'        => 'string',
					'description' => __( 'What the lengths are counted in: words, or characters on locales that count characters.', 'thinkrank' ),
				],
				'types'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'post_type' => [ 'type' => 'string' ],
							'threshold' => [ 'type' => 'integer' ],
							'counted'   => [ 'type' => 'integer' ],
							'thin'      => [ 'type' => 'integer' ],
							'pages'     => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'post_id'    => [ 'type' => 'integer' ],
										'post_title' => [ 'type' => 'string' ],
										'count'      => [ 'type' => 'integer' ],
										'permalink'  => [ 'type' => 'string' ],
									],
								],
							],
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
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$refresh   = ! empty( $input['refresh'] );

		if ( '' !== $post_type && ! in_array( $post_type, Thin_Content::post_types(), true ) ) {
			return new \WP_Error(
				'thinkrank_invalid_post_type',
				__( 'That post type is not one ThinkRank reports on. Use list-content-types for valid slugs.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$report = Thin_Content::report( $refresh );

		$types = [];
		foreach ( $report['types'] as $type ) {
			if ( '' !== $post_type && $type['post_type'] !== $post_type ) {
				continue;
			}

			$types[] = [
				'post_type' => $type['post_type'],
				'threshold' => $type['threshold'],
				'counted'   => $type['counted'],
				'thin'      => $type['thin'],
				'pages'     => array_map(
					static function ( array $page ): array {
						// The screen's edit URL and capability flag stay on the
						// REST response; an agent acts through update-post-seo.
						return [
							'post_id'    => $page['post_id'],
							'post_title' => $page['post_title'],
							'count'      => $page['count'],
							'permalink'  => $page['permalink'],
						];
					},
					$type['posts']
				),
			];
		}

		// Totals describe what was asked for. Reporting the sitewide numbers
		// beside a list filtered to one post type reads as "41 thin" above
		// four named pages, which is a contradiction an agent cannot resolve.
		$counted = $report['counted'];
		$thin    = $report['thin'];
		if ( '' !== $post_type ) {
			$counted = (int) array_sum( array_column( $types, 'counted' ) );
			$thin    = (int) array_sum( array_column( $types, 'thin' ) );
		}

		return [
			'pending' => $report['pending'],
			'counted' => $counted,
			'thin'    => $thin,
			'unit'    => $report['unit'],
			'types'   => $types,
		];
	}
}
