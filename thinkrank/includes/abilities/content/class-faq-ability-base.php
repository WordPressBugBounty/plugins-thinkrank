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
				'enum'        => array_merge(
					[ FAQ_Content::SOURCE_BLOCK ],
					FAQ_Content::builders()
				),
				'description' => __( 'Which editor surface holds this question. Only block items can be written by update-faq. An oxygen or breakdance item comes from that builder\'s own accordion rather than a ThinkRank module, so it contributes to FAQPage only while the accordion setting is on.', 'thinkrank' ),
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
	 * A builder name in the shape the output schema reports.
	 *
	 * Takes the detected value rather than a post ID so a caller detects once
	 * and reports, instead of asking twice and risking two answers.
	 *
	 * @since 2.14.0 Takes the builder, not the post ID.
	 * @param string $builder Builder name from {@see FAQ_Content::builder()}.
	 * @return string One of the builder names, or 'none' for the block editor.
	 */
	protected function builder_name( string $builder ): string {
		return '' === $builder ? 'none' : $builder;
	}

	/**
	 * The builder names a post can report.
	 *
	 * Derived from `FAQ_Content` rather than listed here. The list was written
	 * out by hand and then fell behind the detection it describes, so Oxygen had
	 * no member to report even once it was detected (#831).
	 *
	 * @return string[]
	 */
	protected function builder_enum(): array {
		return array_merge( [ 'none' ], FAQ_Content::builders() );
	}

	/**
	 * A readable name for a builder, for the messages an agent reads.
	 *
	 * @since 2.14.0
	 * @param string $builder Builder key.
	 * @return string
	 */
	protected function builder_label( string $builder ): string {
		$labels = [
			FAQ_Content::SOURCE_ELEMENTOR   => __( 'Elementor', 'thinkrank' ),
			FAQ_Content::SOURCE_BRICKS      => __( 'Bricks', 'thinkrank' ),
			FAQ_Content::SOURCE_BEAVER      => __( 'Beaver Builder', 'thinkrank' ),
			FAQ_Content::BUILDER_OXYGEN     => __( 'Oxygen', 'thinkrank' ),
			FAQ_Content::BUILDER_BREAKDANCE => __( 'Breakdance', 'thinkrank' ),
		];

		return $labels[ $builder ] ?? $builder;
	}

	/**
	 * Why `update-faq` will or will not write this post.
	 *
	 * Four answers, because an agent that cannot tell them apart gives bad
	 * advice. The block editor renders `post_content`, so the write is safe. A
	 * builder with a ThinkRank FAQ module renders something else, and the
	 * questions belong in that module. Oxygen and Breakdance have no module, but
	 * their own accordion is read, so the questions are real and editable in the
	 * builder — and whether they reach the FAQPage is a site setting rather than
	 * a per-element toggle, which the caller has to be told. A builder that is
	 * recognised and not readable at all is the fourth, where a question count of
	 * zero means "nothing readable here" rather than "no FAQ" (#831).
	 *
	 * @since 2.14.0
	 * @param string $builder Builder name, or '' for the block editor.
	 * @return string
	 */
	protected function writable_reason( string $builder ): string {
		if ( '' === $builder ) {
			return __( 'The block editor renders this post, so update-faq can write a FAQ block into its content.', 'thinkrank' );
		}

		if ( FAQ_Content::builder_has_module( $builder ) ) {
			return sprintf(
				/* translators: %s: page builder name. */
				__( '%s renders this post and replaces its content, so a FAQ block written here would never be shown. Add the questions with the ThinkRank FAQ module for that builder; get-faq reads the questions already stored there, and they reach the FAQPage.', 'thinkrank' ),
				$this->builder_label( $builder )
			);
		}

		if ( FAQ_Content::builder_is_readable( $builder ) ) {
			return sprintf(
				/* translators: %s: page builder name. */
				__( '%s renders this post and replaces its content, so a FAQ block written here would never be shown, and ThinkRank has no FAQ module for it. Its own accordion is read instead, and the questions above are what that accordion holds. Edit them in the builder. They reach the FAQPage only while Publish FAQ schema from page-builder accordions is on in Schema Settings, which is off by default.', 'thinkrank' ),
				$this->builder_label( $builder )
			);
		}

		return sprintf(
			/* translators: %s: page builder name. */
			__( '%s renders this post and replaces its content, so a FAQ block written here would never be shown. ThinkRank has no FAQ module for it and cannot read its stored questions either, so this post is recognised but unsupported: add the questions with the builder\'s own accordion, and read a question count of zero as "not readable" rather than "no FAQ".', 'thinkrank' ),
			$this->builder_label( $builder )
		);
	}
}
