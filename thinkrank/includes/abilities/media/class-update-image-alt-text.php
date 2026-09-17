<?php
/**
 * Update image alt text ability.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Writes one image's alt text.
 *
 * Two modes, because the two useful answers to "what should this say?" come
 * from different places: `alt_text` writes the words the caller chose, and
 * `generate` asks Image SEO to build one from the site's alt_format template,
 * which is the same value the front-end filter would have injected.
 *
 * Non-destructive by default: an image that already has hand-written alt text
 * is left alone unless `overwrite` is set, so an agent sweeping the library
 * cannot quietly replace an author's words with a template.
 */
class Update_Image_Alt_Text extends Image_Alt_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-image-alt-text';
		$this->label       = __( 'Update ThinkRank Image Alt Text', 'thinkrank' );
		$this->description = __( 'Set one image\'s alt text in the Media Library. Pass alt_text to write specific words, or generate: true to build one from the site\'s alt text template. Existing alt text is kept unless overwrite is true, so a sweep never replaces what an author wrote. Returns the stored value. Call get-image-alt-text to read the current value first, list-images to find ids, or fill-missing-alt-text to do the whole library at once.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 0.8,
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
			'required'             => [ 'attachment_id' ],
			'properties'           => [
				'attachment_id' => [
					'type'        => 'integer',
					'description' => __( 'Attachment ID from list-images.', 'thinkrank' ),
				],
				'alt_text'      => [
					'type'        => 'string',
					'description' => __( 'The alt text to store. Describe the image; do not repeat the file name. Omit when using generate.', 'thinkrank' ),
				],
				'generate'      => [
					'type'        => 'boolean',
					'description' => __( 'Build the alt text from the site\'s alt_format template instead of supplying it. Ignored when alt_text is given.', 'thinkrank' ),
				],
				'overwrite'     => [
					'type'        => 'boolean',
					'description' => __( 'Replace alt text this image already has. Default false, which skips such images and reports skipped: true.', 'thinkrank' ),
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
			'properties' => array_merge(
				[
					'success' => [ 'type' => 'boolean' ],
					'skipped' => [
						'type'        => 'boolean',
						'description' => __( 'True when the image already had alt text and overwrite was not set. Nothing was changed.', 'thinkrank' ),
					],
				],
				$this->image_properties()
			),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$input         = (array) $input;
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

		$rejection = $this->reject_if_not_an_image( $attachment_id );
		if ( null !== $rejection ) {
			return $rejection;
		}

		$has_text = array_key_exists( 'alt_text', $input );
		$generate = ! empty( $input['generate'] );

		if ( ! $has_text && ! $generate ) {
			return new \WP_Error(
				'thinkrank_no_alt_text_provided',
				__( 'Provide alt_text, or set generate to true to build it from the site template.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$overwrite = ! empty( $input['overwrite'] );

		if ( ! $overwrite && '' !== trim( $this->stored_alt( $attachment_id ) ) ) {
			return array_merge(
				[
					'success' => true,
					'skipped' => true,
				],
				$this->describe_image( $attachment_id )
			);
		}

		if ( $has_text ) {
			// sanitize_text_field(), matching what the Media Library itself
			// stores: alt text is a plain-text attribute, and markup in it is
			// escaped into visible noise rather than rendered.
			update_post_meta( $attachment_id, self::ALT_META_KEY, sanitize_text_field( (string) $input['alt_text'] ) );
		} elseif ( ! $this->manager()->fill_attachment_alt( $attachment_id, true ) ) {
			return new \WP_Error(
				'thinkrank_alt_generation_failed',
				__( 'The alt text template produced no value for this image. Set alt_text explicitly, or review the template under Essential SEO → Image SEO.', 'thinkrank' ),
				[ 'status' => 422 ]
			);
		}

		return array_merge(
			[
				'success' => true,
				'skipped' => false,
			],
			$this->describe_image( $attachment_id )
		);
	}
}
