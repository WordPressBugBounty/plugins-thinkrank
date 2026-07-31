<?php
/**
 * Get schema settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Schema_Management_System;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank structured-data (schema) settings.
 *
 * Schema settings are stored via the Schema_Management_System SEO manager and
 * cover organization, website, local business, and person markup.
 */
class Get_Schema_Settings extends Ability_Base {
	/**
	 * Boolean-typed schema keys.
	 */
	private const BOOL_KEYS = [
		'enabled',
		'auto_generate_schema',
		'rich_snippets_optimization',
		'auto_deploy',
		'organization_schema',
		'knowledge_graph',
		'website_enable_search',
		'enable_breadcrumbs_schema',
		'enable_local_business',
	];

	/**
	 * Integer-typed schema keys.
	 */
	private const INT_KEYS = [
		'cache_duration',
	];

	/**
	 * Array-typed schema keys.
	 */
	private const ARRAY_KEYS = [
		'enabled_schema_types',
		'business_opening_hours',
		'person_same_as',
	];

	/**
	 * String-typed schema keys.
	 */
	private const STRING_KEYS = [
		'validation_level',
		'organization_name',
		'organization_type',
		'organization_logo',
		'organization_url',
		'organization_description',
		'organization_social_facebook',
		'organization_social_twitter',
		'organization_social_linkedin',
		'organization_social_instagram',
		'organization_social_youtube',
		'organization_social_pinterest',
		'organization_social_whatsapp',
		'organization_social_telegram',
		'organization_contact_type',
		'organization_contact_phone',
		'organization_contact_email',
		'organization_contact_hours',
		'website_name',
		'website_url',
		'website_description',
		'website_author',
		'website_search_url',
		'business_price_range',
		'business_geo_latitude',
		'business_geo_longitude',
		'person_name',
		'person_job_title',
		'person_description',
		'person_image',
		'person_url',
		'person_email',
		'person_telephone',
		'person_address',
		'person_birth_date',
		'person_nationality',
		'person_works_for',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-schema-settings';
		$this->label       = __( 'Get ThinkRank Schema Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank structured-data (schema) settings, including organization, website, local business, and person markup.', 'thinkrank' );
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
	 * Build the JSON schema properties for schema settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_properties() {
		$props = [];

		foreach ( self::BOOL_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'boolean' ];
		}
		foreach ( self::INT_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'integer' ];
		}
		foreach ( self::ARRAY_KEYS as $key ) {
			$props[ $key ] = [ 'type' => 'array' ];
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
		$mgr = new Schema_Management_System();
		$s   = $mgr->get_settings( 'site', null );

		$out = [];
		foreach ( self::BOOL_KEYS as $key ) {
			$out[ $key ] = (bool) ( $s[ $key ] ?? false );
		}
		foreach ( self::INT_KEYS as $key ) {
			$out[ $key ] = (int) ( $s[ $key ] ?? 0 );
		}
		foreach ( self::ARRAY_KEYS as $key ) {
			$out[ $key ] = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? array_values( $s[ $key ] ) : [];
		}
		foreach ( self::STRING_KEYS as $key ) {
			$out[ $key ] = (string) ( $s[ $key ] ?? '' );
		}

		return [ 'settings' => $out ];
	}
}
