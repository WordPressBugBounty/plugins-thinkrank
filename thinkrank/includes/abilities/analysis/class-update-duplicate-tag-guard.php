<?php
/**
 * Update Duplicate Tag Guard ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Duplicate_Tags\Guard_Settings;
use ThinkRank\SEO\Duplicate_Tags\Head_Tags;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Switches removal of duplicate head tags on or off, per tag family (#733).
 *
 * Mirrors `POST /thinkrank/v1/duplicate-tags/settings`. Not destructive: it
 * changes what pages print, not anything stored — ThinkRank's own tags are
 * kept, and switching a family off brings the other copies straight back.
 */
class Update_Duplicate_Tag_Guard extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-duplicate-tag-guard';
		$this->label       = __( 'Update ThinkRank Duplicate Tag Guard', 'thinkrank' );
		$this->description = __( 'Turn removal of duplicate head tags on or off, per tag family: title (the <title> and meta description), canonical, robots, social (Open Graph and Twitter cards) and schema (JSON-LD). With a family on, when another plugin or the theme prints a tag ThinkRank also prints, the other copy is taken out of the page and ThinkRank\'s is kept; tags ThinkRank does not print are never touched. Only the families you send change. Returns the stored switches. Check what is duplicated first with thinkrank/get-duplicate-tags, whose removable_families lists the families worth turning on.', 'thinkrank' );
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
			'minProperties'        => 1,
		] + Get_Duplicate_Tags::settings_schema();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		return Get_Duplicate_Tags::settings_schema();
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$patch = array_intersect_key( (array) $input, array_flip( Head_Tags::FAMILIES ) );

		if ( [] === $patch ) {
			return new \WP_Error(
				'thinkrank_no_settings_provided',
				sprintf(
					/* translators: %s: comma-separated tag families. */
					__( 'Send at least one of: %s.', 'thinkrank' ),
					implode( ', ', Head_Tags::FAMILIES )
				),
				[ 'status' => 400 ]
			);
		}

		return ( new Guard_Settings() )->save( array_map( 'boolval', $patch ) );
	}
}
