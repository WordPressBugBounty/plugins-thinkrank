<?php
/**
 * Get global settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank global SEO settings.
 *
 * ThinkRank stores global SEO as per-post-type templates in the
 * `thinkrank_global_seo_settings` option, so this ability optionally filters
 * by a single post type.
 */
class Get_Global_Settings extends Ability_Base {
	/**
	 * Option storing per-post-type global SEO templates.
	 */
	private const OPTION_NAME = 'thinkrank_global_seo_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-global-settings';
		$this->label       = __( 'Get ThinkRank Global Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank global SEO settings. ThinkRank stores these as per-post-type templates; pass a post type to filter.', 'thinkrank' );
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
			'properties'           => [
				'post_type' => [
					'type'        => 'string',
					'description' => __( 'Optional post type slug to return settings for. Omit to return all post-type templates.', 'thinkrank' ),
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
				'post_type' => [ 'type' => 'string' ],
				'settings'  => [ 'type' => 'object' ],
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
		$all = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		if ( '' !== $post_type ) {
			$settings = isset( $all[ $post_type ] ) && is_array( $all[ $post_type ] ) ? $all[ $post_type ] : [];

			return [
				'post_type' => $post_type,
				'settings'  => $settings,
			];
		}

		return [
			'settings' => $all,
		];
	}
}
