<?php
/**
 * Update schema settings ability.
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
 * Updates ThinkRank structured-data (schema) settings.
 *
 * Schema settings are persisted through the Schema_Management_System SEO
 * manager. When auto_deploy is enabled, saving these settings automatically
 * regenerates and redeploys the site-wide schema markup.
 */
class Update_Schema_Settings extends Ability_Base {
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
		$this->id          = 'thinkrank/update-schema-settings';
		$this->label       = __( 'Update ThinkRank Schema Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank structured-data (schema) settings. When auto_deploy is enabled, saving automatically regenerates and redeploys the site-wide schema markup.', 'thinkrank' );
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
			'properties'           => [
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Schema settings to update.', 'thinkrank' ),
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
				'thinkrank_missing_schema_settings_payload',
				__( 'A schema settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$mgr    = new Schema_Management_System();
		$merged = $mgr->get_settings( 'site', null );
		$found  = false;

		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (bool) $settings[ $key ];
				$found          = true;
			}
		}
		foreach ( self::INT_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$merged[ $key ] = (int) $settings[ $key ];
				$found          = true;
			}
		}
		foreach ( self::ARRAY_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) && is_array( $settings[ $key ] ) ) {
				$merged[ $key ] = array_values( $settings[ $key ] );
				$found          = true;
			}
		}
		foreach ( self::STRING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$value = sanitize_text_field( (string) $settings[ $key ] );

				// validation_level is an enum. An out-of-range value would make
				// the downstream all-or-nothing validator reject the entire
				// save, silently dropping the other valid fields — so skip an
				// invalid value here and let the rest of the payload save.
				if ( 'validation_level' === $key
					&& ! in_array( $value, [ 'strict', 'moderate', 'lenient' ], true ) ) {
					continue;
				}

				$merged[ $key ] = $value;
				$found          = true;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_schema_setting_keys',
				__( 'No valid schema setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = $mgr->save_settings( 'site', null, $merged );

		return [
			'success' => (bool) $result,
			'message' => (bool) $result
				? __( 'Schema settings updated.', 'thinkrank' )
				: __( 'Failed to update schema settings.', 'thinkrank' ),
		];
	}
}
