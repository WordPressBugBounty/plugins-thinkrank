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
		$this->description = __( 'Fill in missing alt text across the Media Library, in batches, from the site\'s alt text template or, with alt_source ai, by having the AI provider describe each image. AI costs one paid request per image and batches are capped at 10: ask the user before using it, and watch ai_budget in the result to stop before the daily limit. An image the AI cannot describe is left as it was and listed in failures, never filled from the template. Template filler is skipped and counted in skipped_low_quality. With dry_run true, only template values are previewed: nothing is written and no AI request is made. An explicit AI source cannot use dry_run. Returns how many images were updated plus remaining and next_offset; call again with that offset until done is true. Images that already have alt text are skipped unless overwrite is true. Call list-images with alt_status \'missing\' first to see how many need filling, and update-image-alt-text when you want to write one image yourself. Caches holding the old alt text are cleared where possible; check cache.warnings and pass on anything the user still has to clear by hand.', 'thinkrank' );
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
				'dry_run'   => [
					'type'        => 'boolean',
					'description' => __( 'Preview template candidates only in proposed, without writing or making any AI request. This does not preview AI output; alt_source ai with dry_run is refused. Explicitly choose template on a later write and recheck the selection first. Default false.', 'thinkrank' ),
				],
				'alt_source' => $this->alt_source_property(),
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
				'skipped_low_quality' => [
					'type'        => 'integer',
					'description' => __( 'Images left unchanged because the template would only have given them filler, such as a number or "image". They need alt text written by hand or by the AI source.', 'thinkrank' ),
				],
				'skipped_no_permission' => [
					'type'        => 'integer',
					'description' => __( 'Images left unchanged because the current user is not allowed to edit them.', 'thinkrank' ),
				],
				'dry_run'     => [
					'type'        => 'boolean',
					'description' => __( 'True when this was a preview and nothing was written.', 'thinkrank' ),
				],
				'proposed'    => [
					'type'        => 'array',
					'description' => __( 'On a dry run, each image the batch would write, with its alt text before and after.', 'thinkrank' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'integer' ],
							'before' => [ 'type' => 'string' ],
							'after'  => [ 'type' => 'string' ],
						],
					],
				],
				'offset'      => [ 'type' => 'integer' ],
				'next_offset' => [ 'type' => 'integer' ],
				'remaining'   => [ 'type' => 'integer' ],
				'done'        => [
					'type'        => 'boolean',
					'description' => __( 'True when the whole library has been walked. Call again with next_offset while this is false.', 'thinkrank' ),
				],
				'changes'     => [
					'type'        => 'array',
					'description' => __( 'Each image this batch wrote, with its alt text before and after. The site owner can undo the batch image by image from the MCP activity log.', 'thinkrank' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'integer' ],
							'before' => [ 'type' => 'string' ],
							'after'  => [ 'type' => 'string' ],
							'source' => [
								'type'        => 'string',
								'enum'        => [ 'ai', 'template' ],
								'description' => __( 'Only on an AI batch: where this value came from. template means the AI provider could not describe the image and the template was used instead.', 'thinkrank' ),
							],
						],
					],
				],
				'template_fallbacks' => [
					'type'        => 'integer',
					'description' => __( 'Only on an AI batch: changes written from the template because the AI provider could not describe the image.', 'thinkrank' ),
				],
				'ai_unavailable' => [
					'type'        => 'string',
					'description' => __( 'Only when the saved alt source is ai but AI cannot run: the reason. The batch ran from the template and alt_source says template.', 'thinkrank' ),
				],
				'cache'       => $this->cache_property(),
				'alt_source'  => [
					'type'        => 'string',
					'description' => __( 'The source this batch used: template or ai.', 'thinkrank' ),
				],
				'ai_budget'   => $this->ai_budget_property(),
				'failed'      => [
					'type'        => 'integer',
					'description' => __( 'Only when this call asked for alt_source ai: images the AI provider could not describe. Their alt text is left as it was, never replaced with template text.', 'thinkrank' ),
				],
				'failures'    => [
					'type'        => 'array',
					'description' => __( 'Each image counted in failed, with the reason the AI provider gave.', 'thinkrank' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'integer' ],
							'reason' => [ 'type' => 'string' ],
						],
					],
				],
				'ai_failed'   => [
					'type'        => 'integer',
					'description' => __( 'When the alt text source is AI: images the AI could not describe in this batch. Those kept their alt text, or got the template when they had none. Tell the user, with ai_error.', 'thinkrank' ),
				],
				'ai_error'    => [
					'type'        => 'string',
					'description' => __( 'The first AI failure in this batch, such as an API error or refusal, or an empty string.', 'thinkrank' ),
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

		$args = [];
		foreach ( [ 'offset', 'limit' ] as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$args[ $key ] = (int) $input[ $key ];
			}
		}
		foreach ( [ 'overwrite', 'dry_run' ] as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$args[ $key ] = (bool) $input[ $key ];
			}
		}

		$source = $this->requested_alt_source( $input );
		if ( ! empty( $args['dry_run'] ) && 'ai' === $source ) {
			return new \WP_Error(
				'thinkrank_ai_alt_preview_unavailable',
				__( 'dry_run previews template candidates only. It cannot preview AI output or spend AI requests.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}
		$rejection = $this->reject_unknown_or_unavailable_alt_source( $source );
		if ( null !== $rejection ) {
			return $rejection;
		}
		if ( null !== $source ) {
			$args['alt_source'] = $source;
		}

		return $this->manager()->bulk_fill_missing_alt( $args );
	}
}
