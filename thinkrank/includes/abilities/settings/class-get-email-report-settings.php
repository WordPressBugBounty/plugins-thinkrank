<?php
/**
 * Get email report settings ability.
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
 * Retrieves ThinkRank Email Reporting settings.
 *
 * Mirrors `GET /thinkrank/v1/email-report/config`: returns the resolved report
 * config (on/off, frequency, recipients, enabled sections), the next scheduled
 * run and the section catalog. Read-only.
 */
class Get_Email_Report_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-email-report-settings';
		$this->label       = __( 'Get ThinkRank Email Report Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank Email Reporting settings: whether the scheduled SEO report is on, how often it is sent (days), who receives it, which sections it includes, the next scheduled send, and the section catalog. Use update-email-report-settings to switch it on or off, and send-email-report-test to send one now.', 'thinkrank' );
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
			'properties'           => self::empty_properties(),
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
				'config'   => [
					'type'                 => 'object',
					'description'          => __( 'The resolved Email Reporting config: enabled, frequency_days, recipients, sections_enabled and the schedule timestamps.', 'thinkrank' ),
					'additionalProperties' => true,
				],
				'next_run' => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'ISO-8601 timestamp of the next scheduled report, or null when disabled.', 'thinkrank' ),
				],
				'sections' => [
					'type'        => 'array',
					'description' => __( 'The available report sections and their labels.', 'thinkrank' ),
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

		return [
			'config'   => $manager->config()->get(),
			'next_run' => $manager->scheduler()->next_run_iso(),
			'sections' => $manager->registry()->describe_for_ui(),
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
