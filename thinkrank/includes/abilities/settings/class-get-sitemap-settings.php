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
		$this->description = __( 'Retrieve ThinkRank XML sitemap settings, which use per-type inclusion toggles plus exclusion lists.', 'thinkrank' );
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
				'enabled'            => [ 'type' => 'boolean' ],
				'include_images'     => [ 'type' => 'boolean' ],
				'include_posts'      => [ 'type' => 'boolean' ],
				'include_pages'      => [ 'type' => 'boolean' ],
				'include_categories' => [ 'type' => 'boolean' ],
				'include_tags'       => [ 'type' => 'boolean' ],
				'exclude_posts'      => [ 'type' => 'string' ],
				'exclude_terms'      => [ 'type' => 'string' ],
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
		$gen = new Sitemap_Generator();
		$s   = $gen->get_settings( 'site', null );

		return [
			'enabled'            => (bool) ( $s['enabled'] ?? true ),
			'include_images'     => (bool) ( $s['include_images'] ?? true ),
			'include_posts'      => (bool) ( $s['include_posts'] ?? true ),
			'include_pages'      => (bool) ( $s['include_pages'] ?? true ),
			'include_categories' => (bool) ( $s['include_categories'] ?? true ),
			'include_tags'       => (bool) ( $s['include_tags'] ?? true ),
			'exclude_posts'      => (string) ( $s['exclude_posts'] ?? '' ),
			'exclude_terms'      => (string) ( $s['exclude_terms'] ?? '' ),
		];
	}
}
