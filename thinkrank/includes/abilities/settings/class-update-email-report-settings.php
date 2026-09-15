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
 * Switches ThinkRank Email Reporting on or off.
 *
 * Mirrors `POST /thinkrank/v1/email-report/config`. Returns the resolved config
 * after the write and the next scheduled run.
 */
class Update_Email_Report_Settings extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-email-report-settings';
		$this->label       = __( 'Update ThinkRank Email Report Settings', 'thinkrank' );
		$this->description = __( 'Switch the scheduled ThinkRank SEO email report on or off. Switching it on schedules the first report one period from now. Returns the stored config and the next scheduled send. Read the current state with get-email-report-settings.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
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
				'enabled' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the scheduled report is sent.', 'thinkrank' ),
				],
			],
			'required'             => [ 'enabled' ],
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
					'description'          => __( 'The resolved config after the write.', 'thinkrank' ),
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
		if ( ! is_array( $input ) || ! array_key_exists( 'enabled', $input ) ) {
			return new \WP_Error(
				'thinkrank_missing_email_report_settings_payload',
				__( 'The enabled flag is required.', 'thinkrank' ),
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

		$saved = $manager->config()->save( [ 'enabled' => (bool) $input['enabled'] ] );

		return [
			'success'  => true,
			'message'  => $saved['enabled']
				? __( 'Email reporting is on.', 'thinkrank' )
				: __( 'Email reporting is off.', 'thinkrank' ),
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
