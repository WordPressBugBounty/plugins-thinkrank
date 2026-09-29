<?php
/**
 * Get FAQ ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Frontend\Schema_Graph;
use ThinkRank\SEO\FAQ_Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports the FAQ questions on one post, and whether they reach search engines.
 *
 * Three separate facts, because they come apart in practice and an agent that
 * conflates them gives bad advice: the questions that are on the page, which
 * editor surface holds them (only the block can be written back), and whether
 * ThinkRank is actually publishing a FAQPage for the post. The third can be
 * false while the first two are healthy, because the content type has Schema
 * switched off, or because another plugin on the page already publishes its own
 * FAQPage and ThinkRank stands down rather than emit a second one.
 */
class Get_FAQ extends FAQ_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-faq';
		$this->label       = __( 'Get ThinkRank FAQ', 'thinkrank' );
		$this->description = __( 'Read the FAQ questions and answers on one post, where they are stored (the ThinkRank FAQ block, or the Elementor, Bricks or Beaver module), and whether ThinkRank is publishing FAQPage schema for the post. Call this before update-faq: it reports which builder renders the post, and update-faq can only write posts built with the block editor. Note that Google shows FAQ rich results only for well-known, authoritative government and health websites, so for most sites the value of an FAQ is being quotable by answer engines, not a rich result.', 'thinkrank' );
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
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id' => [
					'type'        => 'integer',
					'description' => __( 'Post ID from list-content-items.', 'thinkrank' ),
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
				'post_id'         => [ 'type' => 'integer' ],
				'post_title'      => [ 'type' => 'string' ],
				'post_type'       => [ 'type' => 'string' ],
				'permalink'       => [ 'type' => 'string' ],
				'edit_url'        => [ 'type' => 'string' ],
				'builder'         => [
					'type'        => 'string',
					'enum'        => $this->builder_enum(),
					'description' => __( 'Which page builder renders this post. Anything but "none" means update-faq will refuse it.', 'thinkrank' ),
				],
				'writable'        => [
					'type'        => 'boolean',
					'description' => __( 'True when update-faq can write this post. False for builder-rendered posts, where a block would be stored but never shown.', 'thinkrank' ),
				],
				'faqpage_emitted' => [
					'type'        => 'boolean',
					'description' => __( 'Whether ThinkRank currently publishes FAQPage schema for this post. False with questions present means the post is password protected, the content type has Schema off, a producer has its schema toggle off, or another plugin already publishes its own FAQPage here.', 'thinkrank' ),
				],
				'total'           => [ 'type' => 'integer' ],
				'items'           => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => $this->item_properties(),
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
		$input = (array) $input;
		$post  = $this->resolve_post( (int) ( $input['post_id'] ?? 0 ) );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$items   = FAQ_Content::items( $post );
		$builder = $this->builder_name( (int) $post->ID );

		return [
			'post_id'         => (int) $post->ID,
			'post_title'      => (string) get_the_title( $post ),
			'post_type'       => (string) $post->post_type,
			'permalink'       => (string) get_permalink( $post ),
			'edit_url'        => (string) get_edit_post_link( $post->ID, 'raw' ),
			'builder'         => $builder,
			'writable'        => 'none' === $builder,
			'faqpage_emitted' => Schema_Graph::will_emit_faqpage( $post ),
			'total'           => count( $items ),
			'items'           => $items,
		];
	}
}
