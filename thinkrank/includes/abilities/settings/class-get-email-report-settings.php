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
 * config (on/off, frequency, recipients, enabled sections, why the last
 * scheduled run sent nothing), the next scheduled run, the section catalog
 * and the readiness of the data sources. Read-only.
 */
class Get_Email_Report_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-email-report-settings';
		$this->label       = __( 'Get ThinkRank Email Report Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank Email Reporting settings: whether the scheduled SEO report is on, how often it is sent (days), who receives it, which sections it includes, the next scheduled send, the section catalog, and readiness — whether Google Search Console is connected (required; scheduled reports are paused and config.last_skip says why while it is not), and whether Google Analytics 4 and AI-assistant traffic data are available to add their sections. Use update-email-report-settings to switch it on or off, and send-email-report-test to send one now.', 'thinkrank' );
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
				'config'    => [
					'type'                 => 'object',
					'description'          => __( 'The resolved Email Reporting config: enabled, frequency_days, recipients, sections_enabled, the schedule timestamps, and last_skip ({reason, at} or null) — why the last scheduled run sent nothing: search_console_not_connected or no_data.', 'thinkrank' ),
					'additionalProperties' => true,
				],
				'readiness' => [
					'type'        => 'object',
					'description' => __( 'Data-source readiness: ready (bool — a report can be built), search_console (bool, required), analytics (bool — Google Analytics 4 connected, adds the site-traffic section), ai_traffic (bool — AI-assistant referrals recorded, adds that section), reason (string|null — search_console_not_connected when not ready).', 'thinkrank' ),
					'properties'  => [
						'ready'          => [ 'type' => 'boolean' ],
						'search_console' => [ 'type' => 'boolean' ],
						'analytics'      => [ 'type' => 'boolean' ],
						'ai_traffic'     => [ 'type' => 'boolean' ],
						'reason'         => [ 'type' => [ 'string', 'null' ] ],
					],
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
			'config'    => $manager->config()->get(),
			'next_run'  => $manager->scheduler()->next_run_iso(),
			'sections'  => $manager->registry()->describe_for_ui(),
			'readiness' => $manager->data_provider()->readiness(),
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
