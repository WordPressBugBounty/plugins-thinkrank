<?php
/**
 * Bulk fill missing alt text ability.
 *
 * @package ThinkRank\Abilities\Media
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs the Media Library bulk alt-text fill.
 *
 * The same routine the "Fill missing alt text" button in Image SEO calls, which
 * until now existed only as a wp-admin button — so an agent could see the
 * coverage gap and had no way to close it (#676).
 *
 * Batched rather than one-shot, because the AI alt source is a paid provider
 * call per image: the manager clamps the batch itself and returns `remaining`
 * and `next_offset`, so a caller loops until `done`.
 */
class Fill_Missing_Alt_Text extends Image_Alt_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/fill-missing-alt-text';
		$this->label       = __( 'Fill Missing ThinkRank Image Alt Text', 'thinkrank' );
		$this->description = __( 'Fill in missing alt text across the Media Library using the site\'s alt text template, in batches. Returns how many images were updated plus remaining and next_offset; call again with that offset until done is true. Images that already have alt text are skipped unless overwrite is true. Call list-images with alt_status \'missing\' first to see how many need filling, and update-image-alt-text when you want to write one image yourself.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Writes alt text where there was none; the default leaves existing
			// values alone, so nothing an author wrote is lost. `overwrite`
			// changes that, which is why it defaults off and is described.
			'destructive'   => false,
			// Re-running with the same offset is safe: an image filled by the
			// previous run is skipped by the next one.
			'idempotent'    => true,
			'priority'      => 0.7,
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
			'properties'           => [
				'offset'    => [
					'type'        => 'integer',
					'description' => __( 'Where to start. Pass the previous call\'s next_offset. Default 0.', 'thinkrank' ),
				],
				'limit'     => [
					'type'        => 'integer',
					'description' => __( 'Batch size, 1-200. Clamped lower when the alt source is AI, because each image is a provider call. Default 50.', 'thinkrank' ),
				],
				'overwrite' => [
					'type'        => 'boolean',
					'description' => __( 'Replace alt text images already have. Default false.', 'thinkrank' ),
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
				'total'       => [ 'type' => 'integer' ],
				'processed'   => [ 'type' => 'integer' ],
				'updated'     => [
					'type'        => 'integer',
					'description' => __( 'Images whose alt text was written by this batch.', 'thinkrank' ),
				],
				'skipped'     => [ 'type' => 'integer' ],
				'offset'      => [ 'type' => 'integer' ],
				'next_offset' => [ 'type' => 'integer' ],
				'remaining'   => [ 'type' => 'integer' ],
				'done'        => [
					'type'        => 'boolean',
					'description' => __( 'True when the whole library has been walked. Call again with next_offset while this is false.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$input = (array) $input;

		$args = [];
		foreach ( [ 'offset', 'limit' ] as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$args[ $key ] = (int) $input[ $key ];
			}
		}
		if ( array_key_exists( 'overwrite', $input ) ) {
			$args['overwrite'] = (bool) $input['overwrite'];
		}

		return $this->manager()->bulk_fill_missing_alt( $args );
	}
}
