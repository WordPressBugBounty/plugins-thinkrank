<?php
/**
 * Get llms.txt settings ability.
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
 * Retrieves ThinkRank llms.txt settings.
 *
 * These settings feed the AI-oriented llms.txt file describing the site to
 * language models, stored via the LLMs_Txt_Manager SEO manager.
 */
class Get_Llms_Txt_Settings extends Ability_Base {
	/**
	 * Boolean-typed llms.txt keys.
	 */
	private const BOOL_KEYS = [
		'enabled',
		'auto_generate',
	];

	/**
	 * String-typed llms.txt keys.
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
		$this->id          = 'thinkrank/get-llms-txt-settings';
		$this->label       = __( 'Get ThinkRank llms.txt Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank llms.txt settings, which describe the site to AI language models via the llms.txt file.', 'thinkrank' );
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
		$mgr = new LLMs_Txt_Manager();
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
