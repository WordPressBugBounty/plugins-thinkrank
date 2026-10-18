<?php
/**
 * Shared metadata snapshots for unsaved alt-text drafts.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared metadata snapshots for unsaved alt-text drafts.
 *
 * @since 2.17.0
 */
abstract class Image_Alt_Draft_Ability_Base extends Image_Alt_Ability_Base {

	/**
	 * One image per request; a list cannot accidentally expand paid work.
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'attachment_id' ],
			'properties'           => [
				'attachment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
		];
	}

	/**
	 * Opaque comparison value, not permission to change the image.
	 *
	 * @return array<string, mixed>
	 */
	protected function expected_property(): array {
		return [
			'type'        => 'string',
			'pattern'     => '^[a-f0-9]{64}$',
			'description' => __( 'Metadata fingerprint from get-image-alt-preview. Detects changes to stored alt, attachment metadata and template context; does not attest image bytes or rendered page markup.', 'thinkrank' ),
		];
	}

	/**
	 * Read a fresh snapshot, including metadata used by template and AI context.
	 *
	 * Cache eviction is necessary after a slow provider request: another request
	 * may have edited the attachment while this process retained its old values.
	 * This is a drift check, not a lock or an authorization token for later writes.
	 *
	 * @param mixed $attachment_id Requested attachment ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function draft_snapshot( $attachment_id ) {
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 ) {
			return new \WP_Error( 'thinkrank_invalid_attachment_id', __( 'attachment_id must be a positive integer.', 'thinkrank' ), [ 'status' => 400 ] );
		}
		wp_cache_delete( $attachment_id, 'posts' );
		wp_cache_delete( $attachment_id, 'post_meta' );
		$rejection = $this->reject_if_not_an_image( $attachment_id );
		if ( null !== $rejection ) {
			return $rejection;
		}
		$post   = get_post( $attachment_id );
		$parent = (int) ( $post->post_parent ?? 0 );
		if ( $parent > 0 ) {
			wp_cache_delete( $parent, 'posts' );
		}
		$image    = $this->describe_image( $attachment_id );
		$template = $this->manager()->preview_attachment_alt( $attachment_id );
		$encoded  = wp_json_encode(
			[
				'contract'    => 'image-alt-metadata-v1',
				'image'       => $image,
				'alt_rows'    => get_post_meta( $attachment_id, self::ALT_META_KEY, false ),
				'file'        => get_post_meta( $attachment_id, '_wp_attached_file', true ),
				'metadata'    => wp_get_attachment_metadata( $attachment_id ),
				'title'       => (string) ( $post->post_title ?? '' ),
				'caption'     => (string) ( $post->post_excerpt ?? '' ),
				'status'      => (string) ( $post->post_status ?? '' ),
				'modified'    => (string) ( $post->post_modified_gmt ?? '' ),
				'parent'      => $parent,
				'parent_name' => $parent > 0 ? (string) get_the_title( $parent ) : '',
				'template'    => $template,
			]
		);
		if ( ! is_string( $encoded ) ) {
			return new \WP_Error( 'thinkrank_alt_snapshot_unavailable', __( 'The image metadata could not be read for review.', 'thinkrank' ), [ 'status' => 409 ] );
		}
		return [
			'image'    => $image,
			'expected' => hash( 'sha256', $encoded ),
			'template' => $template,
		];
	}
}
