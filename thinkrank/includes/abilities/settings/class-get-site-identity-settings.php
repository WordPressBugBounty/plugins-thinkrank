<?php
/**
 * Get site identity settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Site_Identity_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank site identity settings.
 *
 * Covers title templates, site name/description, breadcrumb configuration, and
 * brand imagery. The robots.txt keys managed by this manager are intentionally
 * excluded here; use the dedicated robots.txt ability for those.
 */
class Get_Site_Identity_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-site-identity-settings';
		$this->label       = __( 'Get ThinkRank Site Identity Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank site identity settings: the homepage/category/tag/author/search/archive title templates, site name and description, breadcrumb configuration, brand imagery, homepage hero, and the business details behind LocalBusiness schema. Robots.txt contents and schema toggles have their own abilities. Use update-site-identity-settings to change them.', 'thinkrank' );
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
	 * Build the JSON schema properties for site identity settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_properties() {
		return Settings_Key_Map::site_identity();
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
				'settings' => [
					'type'       => 'object',
					'properties' => self::schema_properties(),
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
		$mgr = new Site_Identity_Manager();
		$s   = $mgr->get_settings( 'site', null );

		return [ 'settings' => Settings_Key_Map::read( Settings_Key_Map::site_identity(), $s ) ];
	}
}
