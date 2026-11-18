<?php
/**
 * Get orphan pages ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Orphan_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports published pages that nothing on the site links to (#566).
 *
 * The links are the ones a visitor can follow, resolved through the page
 * builder where one is in use and including navigation menus, so a page linked
 * only from an Elementor button or only from the main menu is not reported.
 */
class Get_Orphan_Pages extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-orphan-pages';
		$this->label       = __( 'Get ThinkRank Orphan Pages', 'thinkrank' );
		$this->description = __( 'Find published pages that no other page on the site links to, grouped by post type, newest first. An orphan page still loads and is still in the sitemap; what it lacks is any internal link pointing at it, which is why search engines crawl it rarely and rank it below pages the site recommends. Links are counted the way a visitor follows them: page builder links (Elementor, Divi, Oxygen, Beaver, Bricks) are resolved, and navigation menus count as links, so a page in the main menu is never reported. Covers published content only; the home page and the posts page are never reported. Its sibling report get-thin-content answers the other half of the same question - a page that is both orphaned and thin is the one to fix first.', 'thinkrank' );
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
					// No enum: the post types are the site's, so a list built here
					// differs between the release manifest and every site, and
					// the hub stops a tool whose schemas disagree (#1066).
					// execute() refuses a slug that is not one we report on.
					'type'        => 'string',
					'default'     => '',
					'description' => __( 'Only report this post type. Empty reports every post type that has published content.', 'thinkrank' ),
				],
				'refresh'   => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Rebuild the report instead of reading the cached one. The cache is already discarded when content or a menu changes, so this is only needed to force a rescan.', 'thinkrank' ),
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
					'description' => __( 'Pages whose links are not indexed yet. While above zero the report carries no findings, because a page looks orphaned until whatever links to it has been read. Call again to continue. Indexing resolves builder content, so it is done a batch at a time.', 'thinkrank' ),
				],
				'counted' => [
					'type'        => 'integer',
					'description' => __( 'Published pages the report covers.', 'thinkrank' ),
				],
				'orphans' => [
					'type'        => 'integer',
					'description' => __( 'Pages with no inbound internal link.', 'thinkrank' ),
				],
				'types'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'post_type' => [ 'type' => 'string' ],
							'counted'   => [ 'type' => 'integer' ],
							'orphans'   => [ 'type' => 'integer' ],
							'pages'     => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'post_id'    => [ 'type' => 'integer' ],
										'post_title' => [ 'type' => 'string' ],
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

		if ( '' !== $post_type && ! in_array( $post_type, Orphan_Pages::post_types(), true ) ) {
			return new \WP_Error(
				'thinkrank_invalid_post_type',
				__( 'That post type is not one ThinkRank reports on. Use list-content-types for valid slugs.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$report = Orphan_Pages::report( $refresh );

		$types = [];
		foreach ( $report['types'] as $type ) {
			if ( '' !== $post_type && $type['post_type'] !== $post_type ) {
				continue;
			}

			$types[] = [
				'post_type' => $type['post_type'],
				'counted'   => $type['counted'],
				'orphans'   => $type['orphans'],
				'pages'     => array_map(
					static function ( array $page ): array {
						// The screen's edit URL and capability flag stay on the
						// REST response; an agent acts through update-post-seo.
						return [
							'post_id'    => $page['post_id'],
							'post_title' => $page['post_title'],
							'permalink'  => $page['permalink'],
						];
					},
					$type['posts']
				),
			];
		}

		// Totals describe what was asked for, for the same reason the thin
		// content report does it: "41 orphaned" above four named pages is a
		// contradiction an agent cannot resolve.
		$counted = $report['counted'];
		$orphans = $report['orphans'];
		if ( '' !== $post_type ) {
			$counted = (int) array_sum( array_column( $types, 'counted' ) );
			$orphans = (int) array_sum( array_column( $types, 'orphans' ) );
		}

		return [
			'pending' => $report['pending'],
			'counted' => $counted,
			'orphans' => $orphans,
			'types'   => $types,
		];
	}
}
