<?php
/**
 * Update author archives settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank author archives settings.
 *
 * Controls whether author archives are enabled and indexable, whether empty
 * archives are shown, and the SEO title/meta description templates.
 */
class Update_Author_Archives_Settings extends Ability_Base {
	/**
	 * Boolean settings: API alias => storage_key.
	 */
	private const BOOL_MAP = [
		'enabled'                => 'author_archives_enabled',
		'show_in_search_results' => 'author_archives_index',
		'show_empty_archives'    => 'author_archives_show_empty',
	];

	/**
	 * String settings: API alias => storage_key.
	 */
	private const STRING_MAP = [
		'title'            => 'author_archives_title',
		'meta_description' => 'author_archives_meta_desc',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-author-archives-settings';
		$this->label       = __( 'Update ThinkRank Author Archives Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank author archives settings: enable/index toggles, empty-archive visibility, and the SEO title/meta description templates.', 'thinkrank' );
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
					'description' => __( 'Author archives settings to update.', 'thinkrank' ),
					'properties'  => [
						'enabled'                => [ 'type' => 'boolean' ],
						'show_in_search_results' => [ 'type' => 'boolean' ],
						'show_empty_archives'    => [ 'type' => 'boolean' ],
						'title'                  => [ 'type' => 'string' ],
						'meta_description'       => [ 'type' => 'string' ],
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
				'thinkrank_missing_author_archives_settings_payload',
				__( 'An author archives settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$store = Settings::instance();
		$found = false;

		foreach ( self::BOOL_MAP as $alias => $storage_key ) {
			if ( array_key_exists( $alias, $settings ) ) {
				$store->set( $storage_key, (bool) $settings[ $alias ] );
				$found = true;
			}
		}
		foreach ( self::STRING_MAP as $alias => $storage_key ) {
			if ( array_key_exists( $alias, $settings ) ) {
				$store->set( $storage_key, sanitize_text_field( (string) $settings[ $alias ] ) );
				$found = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_author_archives_setting_keys',
				__( 'No valid author archives setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'success' => true,
			'message' => __( 'Author archives settings updated.', 'thinkrank' ),
		];
	}
}
