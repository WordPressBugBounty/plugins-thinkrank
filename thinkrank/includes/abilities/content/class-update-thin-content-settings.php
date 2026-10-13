<?php
/**
 * Update thin content settings ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Thin_Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sets the length below which a page counts as thin, in general and per post
 * type (#565).
 */
class Update_Thin_Content_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-thin-content-settings';
		$this->label       = __( 'Update ThinkRank Thin Content Settings', 'thinkrank' );
		$this->description = __( 'Set the length below which a published page counts as thin: a default for the site, and an override per post type for content that is short by nature, such as products or staff profiles. Send null for a post type to clear its override and return it to the default. Lengths are in words, or in characters for locales that count characters. Changing a threshold re-reads the existing counts and never recounts the site, so it takes effect immediately; call get-thin-content afterwards to see the report under the new numbers.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'    => false,
			// Changes what the report calls thin. It loses no content and no
			// stored count, and it is reversible by sending the old number
			// back, so it is not destructive: marking routine settings so
			// makes an MCP client demand approval for every call (#675).
			'destructive' => false,
			'idempotent'  => true,
			'priority'    => 0.5,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		$threshold = [
			'type'    => [ 'integer', 'null' ],
			'minimum' => Thin_Content::MIN_THRESHOLD,
			'maximum' => Thin_Content::MAX_THRESHOLD,
		];

		// The map is described by its values, never by the site's post types:
		// a per-type list differs between the release manifest (built with
		// none) and every site, and the hub stops a tool whose schemas
		// disagree (#1066). execute() refuses a key that is not a post type
		// we report on.
		$overrides = [
			'type'                 => 'object',
			'additionalProperties' => $threshold + [
				'description' => __( 'Threshold for the post type named by the key (a slug from list-content-types), or null to use the default.', 'thinkrank' ),
			],
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'default'   => [
					'type'        => 'integer',
					'minimum'     => Thin_Content::MIN_THRESHOLD,
					'maximum'     => Thin_Content::MAX_THRESHOLD,
					'description' => __( 'Threshold for every post type without an override. 300 by default, the same number the Site SEO Analyzer depth check uses.', 'thinkrank' ),
				],
				'overrides' => $overrides + [
					'description' => __( 'Per-post-type thresholds. Only the post types sent are changed; the rest keep what they have.', 'thinkrank' ),
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
				'default'   => [ 'type' => 'integer' ],
				'overrides' => [
					'type'        => 'object',
					'description' => __( 'Post types with a threshold of their own.', 'thinkrank' ),
				],
				'effective' => [
					'type'        => 'object',
					'description' => __( 'The threshold now in force for every post type in scope, override or default.', 'thinkrank' ),
				],
				'unit'      => [ 'type' => 'string' ],
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
		$payload = [];

		if ( isset( $input['default'] ) ) {
			$payload['default'] = (int) $input['default'];
		}

		if ( isset( $input['overrides'] ) && is_array( $input['overrides'] ) ) {
			$unknown = array_diff( array_map( 'strval', array_keys( $input['overrides'] ) ), Thin_Content::post_types() );
			if ( [] !== $unknown ) {
				return new \WP_Error(
					'thinkrank_invalid_post_type',
					sprintf(
						/* translators: %s: comma-separated post type slugs. */
						__( 'Not a post type ThinkRank reports on: %s. Use list-content-types for valid slugs.', 'thinkrank' ),
						implode( ', ', $unknown )
					),
					[ 'status' => 400 ]
				);
			}
			$payload['overrides'] = $input['overrides'];
		}

		if ( empty( $payload ) ) {
			return new \WP_Error(
				'thinkrank_nothing_to_update',
				__( 'Send a default, an override, or both.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$stored = Thin_Content::save_settings( $payload );

		// What is stored, not what was sent: a value the clamp reduced is
		// reported as the value it became, never as a successful save of
		// something that was not kept.
		return [
			'default'   => $stored['default'],
			'overrides' => (object) $stored['overrides'],
			'effective' => (object) Thin_Content::thresholds(),
			'unit'      => \ThinkRank\SEO\Word_Count_Index::unit(),
		];
	}
}
