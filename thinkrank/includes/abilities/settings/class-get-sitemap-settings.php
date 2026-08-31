<?php
/**
 * Get sitemap settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Sitemap_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank XML sitemap settings.
 *
 * Sitemap settings are stored via the Sitemap_Generator SEO manager and use
 * per-type inclusion toggles plus exclusion lists.
 */
class Get_Sitemap_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-sitemap-settings';
		$this->label       = __( 'Get ThinkRank Sitemap Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank XML sitemap settings: per-type inclusion toggles, exclusion lists, and the index/splitting, styling, filename and search-engine ping options. Use update-sitemap-settings to change them.', 'thinkrank' );
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
			'properties' => Settings_Key_Map::sitemap(),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$gen = new Sitemap_Generator();
		$s   = $gen->get_settings( 'site', null );

		return Settings_Key_Map::read( Settings_Key_Map::sitemap(), $s );
	}
}
