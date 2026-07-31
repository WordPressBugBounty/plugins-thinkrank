<?php
/**
 * Update llms.txt settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\LLMs_Txt_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank llms.txt settings.
 *
 * These settings feed the AI-oriented llms.txt file describing the site to
 * language models, persisted through the LLMs_Txt_Manager SEO manager.
 */
class Update_Llms_Txt_Settings extends Ability_Base {
	/**
	 * Boolean-typed llms.txt keys.
	 */
	private const BOOL_KEYS = [
		'enabled',
		'auto_generate',
	];

	/**
	 * String-typed llms.txt keys (multi-line content preserved).
	 */
	private const STRING_KEYS = [
		'site_name',
		'website_description',
		'key_features',
		'target_audience',
		'business_type',
		'technical_stack',
		'development_approach',
		'setup_instructions',
		'ai_context_custom',
		'documentation_links',
		'technical_links',
		'optional_links',
		'custom_sections',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-llms-txt-settings';
		$this->label       = __( 'Update ThinkRank llms.txt Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank llms.txt settings, which describe the site to AI language models via the llms.txt file.', 'thinkrank' );
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
	 * Build the JSON schema properties for llms.txt settings.
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
			'properties'           => [
				'settings' => [
					'type'        => 'object',
					'description' => __( 'llms.txt settings to update.', 'thinkrank' ),
					'properties'  => self::schema_properties(),
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
				'thinkrank_missing_llms_txt_settings_payload',
				__( 'A llms.txt settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$mgr    = new LLMs_Txt_Manager();
		$merged = $mgr->get_settings( 'site', null );
		$found  = false;

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (bool) $settings[ $key ];
				$found          = true;
			}
		}
		foreach ( self::STRING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = sanitize_textarea_field( (string) $settings[ $key ] );
				$found          = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_llms_txt_setting_keys',
				__( 'No valid llms.txt setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $mgr->save_settings( 'site', null, $merged );

		// A disable-save may persist the settings yet fail to remove the published
		// file, so surface that partial failure instead of reporting plain success.
		if ( $result && $mgr->unpublish_failed() ) {
			return [
				'success'          => true,
				'file_unpublished' => false,
				'message'          => __( 'Settings were saved, but the published llms.txt file could not be removed and may still be served. Please remove it manually.', 'thinkrank' ),
			];
		}

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'llms.txt settings updated.', 'thinkrank' )
				: __( 'Failed to update llms.txt settings.', 'thinkrank' ),
		];
	}
}
