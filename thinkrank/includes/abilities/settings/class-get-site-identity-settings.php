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
	 * Boolean-typed site identity keys.
	 */
	private const BOOL_KEYS = [
		'enabled',
		'breadcrumbs_enabled',
	];

	/**
	 * String-typed site identity keys.
	 */
	private const STRING_KEYS = [
		'title_template',
		'title_separator',
		'site_name',
		'site_description',
		'tagline',
		'breadcrumb_type',
		'breadcrumb_home_text',
		'breadcrumb_separator',
		'logo_url',
		'favicon_url',
		'apple_touch_icon_url',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-site-identity-settings';
		$this->label       = __( 'Get ThinkRank Site Identity Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank site identity settings: title templates, site name/description, breadcrumb configuration, and brand imagery. Robots.txt configuration is excluded.', 'thinkrank' );
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
		$props = [];

		foreach ( self::BOOL_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'boolean' ];
		}
		foreach ( self::STRING_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'string' ];
		}

		return $props;
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
			'properties'           => [],
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

		$out = [];
		foreach ( self::BOOL_KEYS as $key ) {
			$out[ $key ] = (bool) ( $s[ $key ] ?? false );
		}
		foreach ( self::STRING_KEYS as $key ) {
			$out[ $key ] = (string) ( $s[ $key ] ?? '' );
		}

		return [ 'settings' => $out ];
	}
}
