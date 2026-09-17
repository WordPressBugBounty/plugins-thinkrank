<?php
/**
 * Get post content ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Builder_Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads a post's body content.
 *
 * update-post-seo declares `additionalProperties: false` over an SEO-only field
 * list, and nothing else read the body at all — so an agent was optimising a
 * title and description for content it could not see (#676).
 *
 * Read-only, deliberately. There is no counterpart that writes the body, and
 * that is the point rather than an omission: page-builder content does not live
 * in `post_content` — Bricks, Oxygen, Breakdance and Elementor keep their trees
 * in postmeta — so a write ability that set `post_content` would appear to
 * succeed and change nothing on a builder page, or worse, resurrect stale
 * blocks the builder had superseded. That is the failure mode #335 already
 * documents. A write surface needs to be builder-aware before it is safe, which
 * is a larger piece of work than this.
 *
 * `content` is therefore what the page actually renders, resolved through the
 * same pipeline the SEO score and meta description use, and `post_content` is
 * the raw stored column beside it so the difference is visible rather than
 * guessed at.
 */
class Get_Post_Content extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-post-content';
		$this->label       = __( 'Get ThinkRank Post Content', 'thinkrank' );
		$this->description = __( 'Read a post or page\'s body content so you can judge what it is about before changing its SEO fields. Returns the text the page actually renders — resolved from the page builder when one owns the page — plus the raw stored post_content and which builder is in use. Read-only: use update-post-seo to change SEO fields. Use list-content-items to find ids.', 'thinkrank' );
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
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'   => [
					'type'        => 'integer',
					'description' => __( 'Post or page ID from list-content-items.', 'thinkrank' ),
				],
				'max_chars' => [
					'type'        => 'integer',
					'description' => __( 'Truncate the returned body to this many characters. Omit for the whole thing.', 'thinkrank' ),
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
				'id'             => [ 'type' => 'integer' ],
				'title'          => [ 'type' => 'string' ],
				'post_type'      => [ 'type' => 'string' ],
				'status'         => [ 'type' => 'string' ],
				'url'            => [ 'type' => 'string' ],
				'edit_url'       => [ 'type' => 'string' ],
				'excerpt'        => [ 'type' => 'string' ],
				'content'        => [
					'type'        => 'string',
					'description' => __( 'Plain-text body as the page renders it, resolved from the page builder where one owns the page.', 'thinkrank' ),
				],
				'post_content'   => [
					'type'        => 'string',
					'description' => __( 'The raw stored post_content column. Empty or stale on builder pages, which is why it is reported separately.', 'thinkrank' ),
				],
				'content_source' => [
					'type'        => 'string',
					'enum'        => [ 'post_content', 'builder' ],
					'description' => __( 'Where the rendered body came from.', 'thinkrank' ),
				],
				'builder'        => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'The page builder that owns this post, or null for the block/classic editor.', 'thinkrank' ),
				],
				'word_count'     => [ 'type' => 'integer' ],
				'truncated'      => [ 'type' => 'boolean' ],
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
		$input   = (array) $input;
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'thinkrank_post_not_found',
				sprintf(
					/* translators: %d: post ID that was sent. */
					__( 'No post with id %d. Use list-content-items to find valid ids.', 'thinkrank' ),
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		// Reading a password-protected body would hand its contents to anyone
		// the connector answers, so it is withheld the way the front end
		// withholds it.
		if ( '' !== (string) $post->post_password ) {
			return new \WP_Error(
				'thinkrank_post_password_protected',
				__( 'This post is password protected, so its body is not returned.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		$resolved = trim( wp_strip_all_tags( Builder_Content::resolve( $post ) ) );
		$builder  = $this->builder_for( $post_id );

		$body      = $resolved;
		$truncated = false;

		if ( isset( $input['max_chars'] ) ) {
			$limit = max( 1, (int) $input['max_chars'] );

			if ( mb_strlen( $body ) > $limit ) {
				$body      = mb_substr( $body, 0, $limit );
				$truncated = true;
			}
		}

		return [
			'id'             => $post_id,
			'title'          => (string) get_the_title( $post ),
			'post_type'      => (string) $post->post_type,
			'status'         => (string) $post->post_status,
			'url'            => (string) get_permalink( $post ),
			'edit_url'       => (string) get_edit_post_link( $post_id, 'raw' ),
			'excerpt'        => (string) $post->post_excerpt,
			'content'        => $body,
			'post_content'   => (string) $post->post_content,
			'content_source' => null === $builder ? 'post_content' : 'builder',
			'builder'        => $builder,
			'word_count'     => '' === $resolved ? 0 : count( preg_split( '/\s+/u', $resolved ) ?: [] ),
			'truncated'      => $truncated,
		];
	}

	/**
	 * Which page builder owns this post, if any.
	 *
	 * Derived from the meta keys Builder_Content already resolves through, so
	 * the answer cannot drift from the resolution above.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Builder label, or null for the block/classic editor.
	 */
	private function builder_for( int $post_id ): ?string {
		if ( Builder_Content::bricks_supersedes_post_content( $post_id ) ) {
			return 'bricks';
		}

		// Same keys Builder_Content resolves through, in the same order. Kept
		// as a local map because this one also needs a display label per key,
		// and Oxygen spans three of them across its generations — Oxygen 6 is
		// Breakdance under the hood.
		$labels = [
			'_breakdance_data'      => 'breakdance',
			'_oxygen_data'          => 'oxygen',
			'ct_builder_shortcodes' => 'oxygen',
			'_elementor_data'       => 'elementor',
			'_fl_builder_data'      => 'beaver-builder',
		];

		foreach ( $labels as $key => $label ) {
			$stored = get_post_meta( $post_id, $key, true );

			if ( ( is_string( $stored ) && '' !== trim( $stored ) ) || ( is_array( $stored ) && ! empty( $stored ) ) ) {
				return $label;
			}
		}

		return null;
	}
}
