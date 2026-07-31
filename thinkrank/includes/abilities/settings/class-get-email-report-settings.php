<?php
/**
 * Get email report settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Core\Plan_Config;
use ThinkRank\SEO\Email_Report_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank Email Reporting settings.
 *
 * Mirrors `GET /thinkrank/v1/email-report/config`: returns the per-site report
 * config (enable toggle, frequency, recipients, branding/content fields), the
 * plan capability map (which fields are editable on the current plan), the next
 * scheduled run, the available report sections, and the tokens usable in text
 * fields. Read-only.
 */
class Get_Email_Report_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-email-report-settings';
		$this->label       = __( 'Get ThinkRank Email Report Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank Email Reporting settings: enable toggle, frequency, recipients, branding/content fields, the plan capability map, the next scheduled send, the available report sections, and the tokens usable in text fields.', 'thinkrank' );
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
				'config'       => [
					'type'                 => 'object',
					'description'          => __( 'The current per-site Email Reporting config.', 'thinkrank' ),
					'additionalProperties' => true,
				],
				'capabilities' => [
					'type'                 => 'object',
					'description'          => __( 'Which fields the current plan allows editing.', 'thinkrank' ),
					'additionalProperties' => true,
				],
				'next_run'     => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'ISO-8601 timestamp of the next scheduled report, or null when disabled.', 'thinkrank' ),
				],
				'sections'     => [
					'type'        => 'array',
					'description' => __( 'The available report sections and their labels.', 'thinkrank' ),
				],
				'tokens'       => [
					'type'                 => 'object',
					'description'          => __( 'Tokens usable in subject/intro/footer text.', 'thinkrank' ),
					'additionalProperties' => true,
				],
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
		$manager = $this->resolve_manager();

		if ( ! $manager instanceof Email_Report_Manager ) {
			return new \WP_Error(
				'thinkrank_email_report_unavailable',
				__( 'Email Report Manager is unavailable.', 'thinkrank' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! function_exists( 'thinkrank_get_email_report_tokens' ) ) {
			require_once THINKRANK_PLUGIN_DIR . 'includes/config/email-report-settings-config.php';
		}

		return [
			'config'       => $manager->config()->get(),
			'capabilities' => Plan_Config::email_report(),
			'next_run'     => $manager->scheduler()->next_run_iso(),
			'sections'     => $manager->registry()->describe_for_ui(),
			'tokens'       => thinkrank_get_email_report_tokens(),
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
