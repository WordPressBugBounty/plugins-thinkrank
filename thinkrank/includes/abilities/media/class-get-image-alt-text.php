<?php
/**
 * Get image alt text ability.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads one image's stored alt text. Read-only.
 */
class Get_Image_Alt_Text extends Image_Alt_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-image-alt-text';
		$this->label       = __( 'Get ThinkRank Image Alt Text', 'thinkrank' );
		$this->description = __( 'Read one image\'s alt text from the Media Library, along with its title, filename and URL. Use list-images to find ids, and update-image-alt-text to change the value.', 'thinkrank' );
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
			'required'             => [ 'attachment_id' ],
			'properties'           => [
				'attachment_id' => [
					'type'        => 'integer',
					'description' => __( 'Attachment ID from list-images.', 'thinkrank' ),
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
			'properties' => $this->image_properties(),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$attachment_id = (int) ( ( (array) $input )['attachment_id'] ?? 0 );

		$rejection = $this->reject_if_not_an_image( $attachment_id );
		if ( null !== $rejection ) {
			return $rejection;
		}

		return $this->describe_image( $attachment_id );
	}
}
