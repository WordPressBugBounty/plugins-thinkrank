<?php
/**
 * Restore post content ability.
 *
 * @package ThinkRank\Abilities\Content
 * @since 2.18.0
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Post_Content_Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Undoes one update-post-content edit from the revision it kept.
 *
 * Narrower than the Revisions screen on purpose. It restores only a revision
 * an edit through the connector made, and only while the post still holds that
 * edit's result: anything saved since is newer work, and an undo that put the
 * older body over it would be a second loss, not a fix. The restore keeps a
 * revision of its own, so it can be undone the same way.
 */
class Restore_Post_Content extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/restore-post-content';
		$this->label       = __( 'Restore ThinkRank Post Content', 'thinkrank' );
		$this->description = __( 'Undo one update-post-content edit by putting back the body from the revision_id that edit returned. This changes a live page. Ask the person before restoring. Call get-post-content first and pass its version as expected_version. Refused when the post changed after the edit (newer work is never overwritten; restore older states from the Revisions screen in wp-admin), and for any revision the connector did not make. Only the body is restored; the title, slug, status and SEO fields are not touched. Returns a revision_id that undoes the restore.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Not destructive (#675): it keeps a revision of the content it
			// replaces, and can itself be undone.
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
			'required'             => [ 'post_id', 'revision_id', 'expected_version' ],
			'properties'           => [
				'post_id'          => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'revision_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The revision_id update-post-content (or an earlier restore) returned.', 'thinkrank' ),
				],
				'expected_version' => [
					'type'        => 'string',
					'pattern'     => '^[a-f0-9]{64}$',
					'description' => __( 'The version get-post-content returned just now.', 'thinkrank' ),
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
				'status'            => [
					'type' => 'string',
					'enum' => [ 'restored' ],
				],
				'post_id'           => [ 'type' => 'integer' ],
				'version'           => [ 'type' => 'string' ],
				'revision_id'       => [
					'type'        => 'integer',
					'description' => __( 'The revision holding the content as it was before this restore. Pass it to restore-post-content to undo the restore.', 'thinkrank' ),
				],
				'restored_from'     => [ 'type' => 'integer' ],
				'stored_as_planned' => [ 'type' => 'boolean' ],
				'word_count'        => [ 'type' => 'integer' ],
				'seo_score'         => [
					'type'       => [ 'object', 'null' ],
					'properties' => [
						'overall_score' => [ 'type' => 'number' ],
						'grade'         => [ 'type' => 'string' ],
					],
				],
				'warnings'          => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
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
		$post  = Post_Content_Editor::resolve_post( (int) ( $input['post_id'] ?? 0 ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$editable = Post_Content_Editor::editable( $post );

		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$post_id = (int) $post->ID;
		$version = Post_Content_Editor::version( $post );

		if ( ! hash_equals( $version, (string) ( $input['expected_version'] ?? '' ) ) ) {
			return new \WP_Error(
				'thinkrank_content_version_conflict',
				__( 'The post changed after it was read. Nothing was restored. Call get-post-content again.', 'thinkrank' ),
				[
					'status'  => 409,
					'version' => $version,
				]
			);
		}

		$undo = Post_Content_Editor::undo_revision( $post_id, (int) ( $input['revision_id'] ?? 0 ) );

		if ( is_wp_error( $undo ) ) {
			return $undo;
		}

		if ( ! hash_equals( $undo['after'], Post_Content_Editor::content_hash( (string) $post->post_content ) ) ) {
			return new \WP_Error(
				'thinkrank_content_changed_since_edit',
				__( 'The post was changed after that edit, so restoring would overwrite newer work. Nothing was restored. Restore an older state from the Revisions screen in wp-admin if that is what the person wants.', 'thinkrank' ),
				[ 'status' => 409 ]
			);
		}

		$restored = (string) $undo['revision']->post_content;
		$stored   = Post_Content_Editor::stored_form( $post_id, $restored );
		$after    = Post_Content_Editor::content_hash( $stored );

		$revision_id = Post_Content_Editor::keep_revision( $post, $after );

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$saved = Post_Content_Editor::save( $post, $restored );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$as_planned = hash_equals( $after, Post_Content_Editor::content_hash( (string) $saved->post_content ) );
		$warnings   = Post_Content_Editor::purge( $post_id );

		if ( ! $as_planned ) {
			Post_Content_Editor::mark_revision( $revision_id, Post_Content_Editor::content_hash( (string) $saved->post_content ) );
			$warnings[] = __( 'WordPress stored the restored content differently from the revision (a save filter changed it). Read it with get-post-content.', 'thinkrank' );
		}

		return [
			'status'            => 'restored',
			'post_id'           => $post_id,
			'version'           => Post_Content_Editor::version( $saved ),
			'revision_id'       => $revision_id,
			'restored_from'     => (int) $undo['revision']->ID,
			'stored_as_planned' => $as_planned,
			'word_count'        => Post_Content_Editor::word_count( $saved ),
			'seo_score'         => Post_Content_Editor::score( $saved ),
			'warnings'          => $warnings,
		];
	}
}
