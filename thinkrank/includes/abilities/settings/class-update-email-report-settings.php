<?php
/**
 * Update email report settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Email_Report_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates ThinkRank Email Reporting settings.
 *
 * Mirrors `POST /thinkrank/v1/email-report/config`: accepts a partial config
 * object and persists it through Email_Report_Config, which sanitizes and
 * capability-clamps every field (Pro-only fields submitted on a free plan are
 * silently coerced to defaults rather than rejected). Returns the persisted
 * config and the next scheduled run.
 */
class Update_Email_Report_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-email-report-settings';
		$this->label       = __( 'Update ThinkRank Email Report Settings', 'thinkrank' );
		$this->description = __( 'Update ThinkRank Email Reporting settings: enable toggle, frequency (days), recipients, and — on plans that allow it — subject, branding, intro/footer text, enabled sections, and custom CSS. Partial updates are supported; fields not allowed on the current plan are ignored.', 'thinkrank' );
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
					'type'                 => 'object',
					'description'          => __( 'Email Reporting settings to update (any subset of the fields below).', 'thinkrank' ),
					'additionalProperties' => false,
					'properties'           => [
						'enabled'             => [ 'type' => 'boolean' ],
						'frequency_days'      => [
							'type'        => 'integer',
							'description' => __( 'Days between reports. Free plans are clamped to 30.', 'thinkrank' ),
						],
						'recipients'          => [
							'type'        => [ 'array', 'string', 'null' ],
							'description' => __( 'Recipient email(s), as an array or comma-separated string. Free plans are clamped to the site admin email.', 'thinkrank' ),
						],
						'subject_template'    => [ 'type' => [ 'string', 'null' ] ],
						'logo_url'            => [ 'type' => [ 'string', 'null' ] ],
						'logo_link'           => [ 'type' => [ 'string', 'null' ] ],
						'header_background'   => [ 'type' => [ 'string', 'null' ] ],
						'link_to_full_report' => [ 'type' => 'boolean' ],
						'intro_text'          => [ 'type' => [ 'string', 'null' ] ],
						'sections_enabled'    => [ 'type' => [ 'array', 'null' ] ],
						'footer_text'         => [ 'type' => [ 'string', 'null' ] ],
						'additional_css'      => [ 'type' => [ 'string', 'null' ] ],
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
				'success'  => [ 'type' => 'boolean' ],
				'message'  => [ 'type' => 'string' ],
				'config'   => [
					'type'                 => 'object',
					'description'          => __( 'The persisted config after sanitization and capability-clamping.', 'thinkrank' ),
					'additionalProperties' => true,
				],
				'next_run' => [ 'type' => [ 'string', 'null' ] ],
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
				'thinkrank_missing_email_report_settings_payload',
				__( 'An email report settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$manager = $this->resolve_manager();

		if ( ! $manager instanceof Email_Report_Manager ) {
			return new \WP_Error(
				'thinkrank_email_report_unavailable',
				__( 'Email Report Manager is unavailable.', 'thinkrank' ),
				[ 'status' => 500 ]
			);
		}

		// Email_Report_Config::save() sanitizes and capability-clamps every field.
		$saved = $manager->config()->save( $settings );

		return [
			'success'  => true,
			'message'  => __( 'Email report settings updated.', 'thinkrank' ),
			'config'   => $saved,
			'next_run' => $manager->scheduler()->next_run_iso(),
		];
	}

	/**
	 * Resolve the shared Email_Report_Manager from the plugin container.
	 *
	 * @return Email_Report_Manager|null
	 */
	private function resolve_manager() {
		if ( ! function_exists( 'thinkrank' ) ) {
			return null;
		}

		$component = thinkrank()->get_component( 'email_report' );

		return $component instanceof Email_Report_Manager ? $component : null;
	}
}
