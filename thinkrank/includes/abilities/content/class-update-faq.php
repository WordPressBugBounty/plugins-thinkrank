<?php
/**
 * Update FAQ ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Admin\Importers\Block_Converter;
use ThinkRank\Frontend\Schema_Graph;
use ThinkRank\SEO\FAQ_Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Writes FAQ questions into a post as a visible `thinkrank/faq` block.
 *
 * The whole design follows from one rule: Google's structured data policy says
 * "don't mark up content that is not visible to readers of the page", and a
 * FAQPage describing questions nobody can read is a manual action, not a
 * missed opportunity. So this writes the block a human would have inserted —
 * a real `<details>` accordion in `post_content` — and lets the schema graph
 * pick it up the way it already picks up a hand-built one. There is no
 * schema-only path, deliberately.
 *
 * That rule is also why a builder-rendered post is refused rather than
 * written. Elementor, Bricks and Beaver replace or discard `post_content`, so a
 * block stored there would be invisible on the page and the write would produce
 * exactly the violation above. Those surfaces have their own FAQ modules, which
 * `get-faq` reads and a human edits in the builder.
 *
 * Oxygen and Breakdance replace `post_content` just as thoroughly and have no
 * ThinkRank FAQ module, and until #831 the detection did not know them at all:
 * the refusal never fired, the block was stored, and `will_emit_faqpage()` then
 * published a FAQPage for questions that are on no page. They are refused for
 * the same reason as the other three, with a message that sends the author to
 * the builder's own accordion rather than to a ThinkRank module that does not
 * exist. `FAQ_Content` now reads that accordion, so the refusal is no longer a
 * dead end: the questions it holds are reported, and the author edits them where
 * they live.
 *
 * Two properties of the write matter:
 *
 * - **Byte-level editing.** Blocks are located with core's tokenizer and
 *   replaced by byte range, so every other block in the post is left exactly as
 *   its author saved it. A parse_blocks()/serialize_blocks() round trip would
 *   quietly normalise markup across the whole post.
 * - **Markup parity.** The block is serialized through Block_Converter, which
 *   is a maintained mirror of `src/blocks/faq-block/save.js` with a test
 *   pinning it. Gutenberg validates a block by re-running save() and comparing
 *   byte for byte, so markup that is merely equivalent opens as "this block
 *   contains unexpected or invalid content".
 */
class Update_FAQ extends FAQ_Ability_Base {

	/**
	 * Most questions one call may write.
	 *
	 * An FAQ block is a reading experience, not a dump; past a few dozen
	 * questions the page is a worse answer than a structured article, and the
	 * cap keeps one call from replacing a post's body with a wall of accordions.
	 */
	private const MAX_ITEMS = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-faq';
		$this->label       = __( 'Update ThinkRank FAQ', 'thinkrank' );
		$this->description = __( 'Add FAQ questions and answers to a post as a visible ThinkRank FAQ block, which also produces FAQPage schema. Use mode "append" to add a block alongside anything already there, or "replace" to remove every ThinkRank FAQ block on the post first. Only posts built with the block editor can be written: a post rendered by Elementor, Bricks, Beaver, Oxygen or Breakdance is refused with unsupported_builder, because a block stored in its content would never be shown and marking up invisible content breaks Google\'s structured data policy. Call get-faq first to see which builder a post uses and what it already asks. Note that Google shows FAQ rich results only for well-known, authoritative government and health websites, so for most sites the value is being quotable by answer engines rather than a rich result.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Not destructive by the house rule (#675): the write goes through
			// wp_update_post(), so core stores a revision of the content as it
			// was, and this ability returns its id. "replace" does delete FAQ
			// blocks, but nothing is lost that one revision restore does not
			// bring back — and an approval prompt on every FAQ edit is what
			// made the connector unusable the last time this word was used for
			// "writes something".
			'destructive'   => false,
			'idempotent'    => false,
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
			'required'             => [ 'post_id', 'items' ],
			'properties'           => [
				'post_id' => [
					'type'        => 'integer',
					'description' => __( 'Post ID from list-content-items.', 'thinkrank' ),
				],
				'mode'    => [
					'type'        => 'string',
					'enum'        => [ 'append', 'replace' ],
					'default'     => 'append',
					'description' => __( 'append adds a new FAQ block and leaves existing ones alone. replace removes every ThinkRank FAQ block on the post first, discarding any styling those blocks carried.', 'thinkrank' ),
				],
				'items'   => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS,
					'description' => __( 'The questions to write, in the order they should appear.', 'thinkrank' ),
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'question', 'answer' ],
						'properties'           => [
							'question' => [
								'type'        => 'string',
								'description' => __( 'The question, as a reader would ask it. Plain text; any markup is stripped.', 'thinkrank' ),
							],
							'answer'   => [
								'type'        => 'string',
								'description' => __( 'The answer. Inline HTML is allowed and rendered; anything KSES rejects is removed.', 'thinkrank' ),
							],
							'image_id' => [
								'type'        => 'integer',
								'description' => __( 'Optional attachment ID to show with this answer. Use list-images to find one.', 'thinkrank' ),
							],
						],
					],
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
				'success'         => [ 'type' => 'boolean' ],
				'post_id'         => [ 'type' => 'integer' ],
				'mode'            => [ 'type' => 'string' ],
				'blocks_removed'  => [
					'type'        => 'integer',
					'description' => __( 'ThinkRank FAQ blocks deleted from the post. Always 0 in append mode.', 'thinkrank' ),
				],
				'revision_id'     => [
					'type'        => 'integer',
					'description' => __( 'The revision holding the content as it was before this write, or 0 when the post type stores no revisions.', 'thinkrank' ),
				],
				'faqpage_emitted' => [ 'type' => 'boolean' ],
				'total'           => [
					'type'        => 'integer',
					'description' => __( 'Questions on the post after the write, across every surface.', 'thinkrank' ),
				],
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

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error(
				'thinkrank_cannot_edit_post',
				__( 'You are not allowed to edit this post.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		$builder = FAQ_Content::builder( (int) $post->ID );
		if ( '' !== $builder ) {
			return new \WP_Error(
				'thinkrank_unsupported_builder',
				sprintf(
					/* translators: %s: why the post cannot be written, naming the builder. */
					__( '%s Marking up content a reader cannot see breaks Google\'s structured data policy.', 'thinkrank' ),
					$this->writable_reason( $builder )
				),
				[
					'status'             => 422,
					'builder'            => $builder,
					'builder_has_reader' => FAQ_Content::builder_is_readable( $builder ),
				]
			);
		}

		$rows = $this->rows_from_input( $input['items'] ?? [] );

		if ( [] === $rows ) {
			return new \WP_Error(
				'thinkrank_no_faq_items',
				__( 'Every item was missing a question or an answer. An FAQ entry needs both.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$block = Block_Converter::serialize_faq_block( $rows );

		if ( '' === $block ) {
			return new \WP_Error(
				'thinkrank_no_faq_items',
				__( 'The items produced no renderable FAQ block.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$mode    = 'replace' === ( $input['mode'] ?? 'append' ) ? 'replace' : 'append';
		$content = (string) $post->post_content;
		$removed = 0;

		if ( 'replace' === $mode ) {
			$stripped = $this->strip_faq_blocks( $content );

			if ( is_string( $stripped ) ) {
				return new \WP_Error(
					'thinkrank_unparsable_content',
					sprintf(
						/* translators: %s: reason the content could not be edited. */
						__( 'This post\'s content could not be edited safely: %s Fix the block markup in the editor and try again.', 'thinkrank' ),
						$stripped
					),
					[ 'status' => 422 ]
				);
			}

			$content = $stripped['content'];
			$removed = $stripped['removed'];
		}

		$content = '' === trim( $content ) ? $block : rtrim( $content ) . "\n\n" . $block;

		$revision_id = $this->latest_revision_id( (int) $post->ID );

		$updated = wp_update_post(
			[
				'ID' => (int) $post->ID,
				// Slashed, because wp_update_post() unslashes what it is given.
				// The block's attribute JSON escapes `<` as `\u003c`, so an
				// unslashed write stored `u003c` and the block's own attributes
				// no longer matched its markup: any HTML in an answer was lost
				// on the next save, and the block opened as invalid content.
				'post_content' => wp_slash( $content ),
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->purge_caches( (int) $post->ID );

		$fresh = get_post( (int) $post->ID );
		$items = $fresh instanceof \WP_Post ? FAQ_Content::items( $fresh ) : [];

		return [
			'success'         => true,
			'post_id'         => (int) $post->ID,
			'mode'            => $mode,
			'blocks_removed'  => $removed,
			'revision_id'     => $this->revision_since( (int) $post->ID, $revision_id ),
			'faqpage_emitted' => $fresh instanceof \WP_Post ? Schema_Graph::will_emit_faqpage( $fresh ) : false,
			'total'           => count( $items ),
			'items'           => $items,
		];
	}

	/**
	 * Turn the input items into block repeater rows.
	 *
	 * Rows carry every key the block registration declares, because that is what
	 * the editor stores and what it will write back on the next save.
	 *
	 * @param mixed $items Input items.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_from_input( $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$rows = [];

		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// The question renders inside <summary> and is read as plain text by
			// the schema builder, so markup in it is noise at best.
			$question = trim( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			$answer   = trim( wp_kses_post( (string) ( $item['answer'] ?? '' ) ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$image_id  = (int) ( $item['image_id'] ?? 0 );
			$image_url = '';
			$image_alt = '';

			// A deleted or non-image attachment resolves to nothing rather than
			// writing a broken <img> into the post.
			if ( $image_id > 0 && wp_attachment_is_image( $image_id ) ) {
				$image_url = (string) wp_get_attachment_url( $image_id );
				$image_alt = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			}

			$rows[] = [
				'question' => $question,
				'answer'   => $answer,
				'imageId'  => '' === $image_url ? 0 : $image_id,
				'imageUrl' => $image_url,
				'imageAlt' => $image_alt,
			];
		}

		return $rows;
	}

	/**
	 * Remove every `thinkrank/faq` block from content, by byte range.
	 *
	 * @param string $content Post content.
	 * @return array{content: string, removed: int}|string The new content, or why it could not be edited.
	 */
	private function strip_faq_blocks( string $content ) {
		$spans = Block_Converter::find_faq_blocks( $content );

		if ( is_string( $spans ) ) {
			return $spans;
		}

		if ( [] === $spans ) {
			return [
				'content' => $content,
				'removed' => 0,
			];
		}

		$out    = '';
		$cursor = 0;

		foreach ( $spans as $span ) {
			$out    .= substr( $content, $cursor, $span['start'] - $cursor );
			$cursor  = $span['end'];
		}

		return [
			'content' => $out . substr( $content, $cursor ),
			'removed' => count( $spans ),
		];
	}

	/**
	 * The newest revision ID for a post, or 0 when it keeps none.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function latest_revision_id( int $post_id ): int {
		$revisions = wp_get_post_revisions( $post_id, [ 'numberposts' => 1 ] );

		if ( ! is_array( $revisions ) || [] === $revisions ) {
			return 0;
		}

		$first = reset( $revisions );

		return $first instanceof \WP_Post ? (int) $first->ID : 0;
	}

	/**
	 * The revision core stored for this write, if it stored one.
	 *
	 * Reported rather than assumed: a post type without revision support, or a
	 * site that has filtered them off, silently keeps none, and telling the
	 * caller a revision exists when it does not is how "just roll it back"
	 * becomes bad advice.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $previous Newest revision ID before the write.
	 * @return int The new revision ID, or 0 when none was created.
	 */
	private function revision_since( int $post_id, int $previous ): int {
		$latest = $this->latest_revision_id( $post_id );

		return $latest === $previous ? 0 : $latest;
	}

	/**
	 * Drop cached renderings of the post that just changed.
	 *
	 * Guarded because Cache_Purger lands with #763; without it the write still
	 * happens and only the page cache lags, which is the behaviour every other
	 * content write on this site already has.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function purge_caches( int $post_id ): void {
		if ( class_exists( 'ThinkRank\\SEO\\Cache_Purger' ) ) {
			\ThinkRank\SEO\Cache_Purger::purge_posts( [ $post_id ] );

			return;
		}

		clean_post_cache( $post_id );
	}
}
