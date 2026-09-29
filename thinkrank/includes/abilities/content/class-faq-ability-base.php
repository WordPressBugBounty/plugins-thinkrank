<?php
/**
 * Shared base for the FAQ abilities.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\FAQ_Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Post resolution and the shared FAQ item shape.
 *
 * A guided FAQ builder has shipped in free since 1.32.0 — the `thinkrank/faq`
 * block plus the Elementor, Bricks and Beaver modules that mirror it, merged
 * into one FAQPage by the schema graph. None of it was reachable through the
 * connector, so an agent asked to add an FAQ could only write raw custom
 * schema, which duplicates what the block already emits and marks up content
 * that is not on the page (#767).
 */
abstract class FAQ_Ability_Base extends Ability_Base {

	/**
	 * Resolve a post ID to a post the current user may read.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	protected function resolve_post( int $post_id ) {
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'thinkrank_invalid_post_id',
				__( 'A valid post ID is required. Use list-content-items to find one.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'thinkrank_post_not_found',
				__( 'No post was found for the provided ID.', 'thinkrank' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * The shape one question is reported in, shared by both abilities.
	 *
	 * @return array<string, mixed>
	 */
	protected function item_properties(): array {
		return [
			'question'       => [ 'type' => 'string' ],
			'answer'         => [
				'type'        => 'string',
				'description' => __( 'The answer as stored. May contain inline HTML, which the block renders.', 'thinkrank' ),
			],
			'source'         => [
				'type'        => 'string',
				'enum'        => [
					FAQ_Content::SOURCE_BLOCK,
					FAQ_Content::SOURCE_ELEMENTOR,
					FAQ_Content::SOURCE_BRICKS,
					FAQ_Content::SOURCE_BEAVER,
				],
				'description' => __( 'Which editor surface holds this question. Only block items can be written by update-faq.', 'thinkrank' ),
			],
			'schema_enabled' => [
				'type'        => 'boolean',
				'description' => __( 'False when that producer has its schema toggle off, so the question is on the page but contributes nothing to FAQPage.', 'thinkrank' ),
			],
			'image_id'       => [ 'type' => 'integer' ],
			'image_url'      => [ 'type' => 'string' ],
			'image_alt'      => [ 'type' => 'string' ],
		];
	}

	/**
	 * Which builder renders this post, in the shape the schema reports.
	 *
	 * @param int $post_id Post ID.
	 * @return string One of the builder names, or 'none' for the block editor.
	 */
	protected function builder_name( int $post_id ): string {
		$builder = FAQ_Content::builder( $post_id );

		return '' === $builder ? 'none' : $builder;
	}

	/**
	 * The builder names a post can report.
	 *
	 * @return string[]
	 */
	protected function builder_enum(): array {
		return [
			'none',
			FAQ_Content::SOURCE_ELEMENTOR,
			FAQ_Content::SOURCE_BRICKS,
			FAQ_Content::SOURCE_BEAVER,
		];
	}
}
