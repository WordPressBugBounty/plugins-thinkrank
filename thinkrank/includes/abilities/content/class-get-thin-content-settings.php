<?php
/**
 * Get thin content settings ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Thin_Content;
use ThinkRank\SEO\Word_Count_Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports the length below which a page counts as thin (#834).
 *
 * `update-thin-content-settings` shipped without this counterpart, which made
 * it the only write in the registry with nothing to read it back: an agent
 * could set a threshold and had no way to learn what the site was actually
 * applying. That matters more here than for most settings, because the stored
 * value is not necessarily the value sent — `Thin_Content::save_settings()`
 * clamps to MIN_THRESHOLD/MAX_THRESHOLD, and an override for a post type that
 * has since been unregistered is held in storage but kept out of the effective
 * map.
 *
 * It also slipped past `AbilityCrossReferenceTest`, which only checks that a
 * `get-`/`update-` pair that both exist name each other; a pair missing one
 * half is invisible to it.
 *
 * Reports the same payload as `GET /global-seo/thin-content/settings`, so the
 * admin screen and an agent read the same numbers, including `effective` so a
 * caller never has to reimplement "override, else default".
 */
class Get_Thin_Content_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-thin-content-settings';
		$this->label       = __( 'Get ThinkRank Thin Content Settings', 'thinkrank' );
		$this->description = __( 'Read the length below which a published page counts as thin: the site default, any per-post-type overrides, and the threshold actually in force for each post type in scope. Call this before update-thin-content-settings to see what a site applies today, and after it to confirm what was stored, since a value outside the allowed range is clamped rather than rejected. Lengths are in words, or in characters for locales that count characters. Use get-thin-content for the report of which pages are currently thin under these numbers.', 'thinkrank' );
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
			'priority'      => 0.5,
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
			'properties'           => self::empty_properties(),
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
				'default'   => [
					'type'        => 'integer',
					'description' => __( 'Threshold for every post type without an override.', 'thinkrank' ),
				],
				'overrides' => [
					'type'        => 'object',
					'description' => __( 'Post types with a threshold of their own. A post type whose plugin is currently inactive keeps its stored override but does not appear here.', 'thinkrank' ),
				],
				'effective' => [
					'type'        => 'object',
					'description' => __( 'The threshold now in force for every post type in scope, override or default. Read this rather than deriving it.', 'thinkrank' ),
				],
				'unit'      => [
					'type'        => 'string',
					'description' => __( 'What the thresholds count: words, or characters in locales that are counted that way.', 'thinkrank' ),
				],
				'limits'    => [
					'type'        => 'object',
					'description' => __( 'The range update-thin-content-settings accepts. A value outside it is clamped to the nearest end, not refused.', 'thinkrank' ),
					'properties'  => [
						'min_threshold' => [ 'type' => 'integer' ],
						'max_threshold' => [ 'type' => 'integer' ],
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
		$settings = Thin_Content::settings();

		// Mirrors Thin_Content_Endpoint::settings_payload() rather than
		// assembling its own shape, so the screen and an agent cannot come to
		// describe the same thresholds differently.
		return [
			'default'   => $settings['default'],
			'overrides' => (object) $settings['overrides'],
			'effective' => (object) Thin_Content::thresholds(),
			'unit'      => Word_Count_Index::unit(),
			'limits'    => [
				'min_threshold' => Thin_Content::MIN_THRESHOLD,
				'max_threshold' => Thin_Content::MAX_THRESHOLD,
			],
		];
	}
}
