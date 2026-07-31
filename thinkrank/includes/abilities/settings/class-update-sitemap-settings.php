<?php
/**
 * Update sitemap settings ability.
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
 * Updates ThinkRank XML sitemap settings.
 *
 * Sitemap settings are persisted through the Sitemap_Generator SEO manager.
 */
class Update_Sitemap_Settings extends Ability_Base {
	/**
	 * Boolean-typed sitemap keys.
	 */
	private const BOOL_KEYS = [
		'enabled',
		'include_images',
		'include_posts',
		'include_pages',
		'include_categories',
		'include_tags',
	];

	/**
	 * String-typed sitemap keys.
	 */
	private const STRING_KEYS = [
		'exclude_posts',
		'exclude_terms',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-sitemap-settings';
		$this->label       = __( 'Update ThinkRank Sitemap Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank XML sitemap settings, including per-type inclusion toggles and exclusion lists.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => true,
			'idempotent'    => true,
			'priority'      => 2.0,
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
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Sitemap settings to update.', 'thinkrank' ),
					'properties'  => [
						'enabled'            => [ 'type' => 'boolean' ],
						'include_images'     => [ 'type' => 'boolean' ],
						'include_posts'      => [ 'type' => 'boolean' ],
						'include_pages'      => [ 'type' => 'boolean' ],
						'include_categories' => [ 'type' => 'boolean' ],
						'include_tags'       => [ 'type' => 'boolean' ],
						'exclude_posts'      => [ 'type' => 'string' ],
						'exclude_terms'      => [ 'type' => 'string' ],
					],
				],
			],
			'required'             => [ 'settings' ],
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
				'success' => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
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
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'thinkrank_missing_sitemap_settings_payload',
				__( 'A sitemap settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$gen    = new Sitemap_Generator();
		$merged = $gen->get_settings( 'site', null );
		$found  = false;

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (bool) $settings[ $key ];
				$found          = true;
			}
		}

		foreach ( self::STRING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
				$found          = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_sitemap_setting_keys',
				__( 'No valid sitemap setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $gen->save_settings( 'site', null, $merged );

		// Rebuild the served sitemap so the change takes effect instead of going
		// stale until an unrelated content edit (debounced).
		if ( $result ) {
			$gen->schedule_regeneration();
		}

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Sitemap settings updated.', 'thinkrank' )
				: __( 'Failed to update sitemap settings.', 'thinkrank' ),
		];
	}
}
