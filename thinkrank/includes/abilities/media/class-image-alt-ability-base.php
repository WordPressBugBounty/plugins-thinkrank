<?php
/**
 * Shared base for the image alt-text abilities.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Image_SEO_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Common manager access and schema fragments for the alt-text abilities.
 *
 * Before these, the only image abilities were get/update-image-seo-settings,
 * which operate on the site-wide alt_format template. An agent could change the
 * rule and never see or set a single image's alt text — so "find the images
 * missing alt text and write sensible ones", the most obvious image workflow
 * there is, had no path through the connector at all (#676).
 */
abstract class Image_Alt_Ability_Base extends Ability_Base {

	/**
	 * The meta key WordPress stores alt text under.
	 */
	protected const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * Image SEO manager, built once per ability instance.
	 *
	 * @var Image_SEO_Manager|null
	 */
	private ?Image_SEO_Manager $manager = null;

	/**
	 * The settings manager for the image SEO feature.
	 *
	 * @return Image_SEO_Manager
	 */
	protected function manager(): Image_SEO_Manager {
		if ( null === $this->manager ) {
			$this->manager = new Image_SEO_Manager();
		}

		return $this->manager;
	}

	/**
	 * Read one attachment's stored alt text.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Alt text, or '' when none is stored.
	 */
	protected function stored_alt( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::ALT_META_KEY, true );
	}

	/**
	 * Reject anything that is not an image attachment.
	 *
	 * Returned rather than thrown so each ability can hand the caller the same
	 * message, and so a deleted or non-image id is a 404-shaped answer instead
	 * of a silent no-op that reads like success.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return \WP_Error|null Error when unusable, null when fine.
	 */
	protected function reject_if_not_an_image( int $attachment_id ): ?\WP_Error {
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error(
				'thinkrank_not_an_image',
				sprintf(
					/* translators: %d: attachment ID that was sent. */
					__( '%d is not an image attachment on this site. Use list-images to find valid ids.', 'thinkrank' ),
					$attachment_id
				),
				[ 'status' => 404 ]
			);
		}

		return null;
	}

	/**
	 * The shape one image is reported in, shared by every ability here.
	 *
	 * @return array<string, mixed>
	 */
	protected function image_properties(): array {
		return [
			'id'        => [ 'type' => 'integer' ],
			'title'     => [ 'type' => 'string' ],
			'filename'  => [ 'type' => 'string' ],
			'url'       => [ 'type' => 'string' ],
			'edit_url'  => [ 'type' => 'string' ],
			'alt_text'  => [ 'type' => 'string' ],
			'has_alt'   => [
				'type'        => 'boolean',
				'description' => __( 'False when the alt text is missing or whitespace only.', 'thinkrank' ),
			],
			'mime_type' => [ 'type' => 'string' ],
		];
	}

	/**
	 * Describe one attachment in the shape image_properties() declares.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	protected function describe_image( int $attachment_id ): array {
		$alt  = $this->stored_alt( $attachment_id );
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		return [
			'id'        => $attachment_id,
			'title'     => (string) get_the_title( $attachment_id ),
			'filename'  => '' !== $file ? basename( $file ) : '',
			'url'       => (string) wp_get_attachment_url( $attachment_id ),
			'edit_url'  => (string) get_edit_post_link( $attachment_id, 'raw' ),
			'alt_text'  => $alt,
			'has_alt'   => '' !== trim( $alt ),
			'mime_type' => (string) get_post_mime_type( $attachment_id ),
		];
	}

	/**
	 * Post statuses an image can carry.
	 *
	 * Mirrors Image_SEO_Manager's own list: 'inherit' alone misses the private
	 * attachments media-protection and membership plugins create, which is the
	 * same gap #322 fixed in the bulk filler.
	 *
	 * @return string[]
	 */
	protected function attachment_statuses(): array {
		return [ 'inherit', 'private', 'publish', 'draft', 'pending', 'future' ];
	}
}
