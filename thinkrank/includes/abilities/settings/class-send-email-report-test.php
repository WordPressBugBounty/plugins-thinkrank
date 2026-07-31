<?php
/**
 * Send test email report ability.
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
 * Sends an immediate one-off test of the ThinkRank Email Report.
 *
 * Mirrors `POST /thinkrank/v1/email-report/test-send`: generates the report
 * from the current saved config and emails it right now to the configured
 * recipients, without touching the schedule. Fails when no recipients are
 * configured. This actually delivers an email, so it is a non-idempotent,
 * side-effecting action.
 */
class Send_Email_Report_Test extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/send-email-report-test';
		$this->label       = __( 'Send ThinkRank Test Email Report', 'thinkrank' );
		$this->description = __( 'Send an immediate one-off test of the ThinkRank Email Report to the configured recipients using the current saved settings. Does not change the schedule. Fails if no recipients are configured.', 'thinkrank' );
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
			'idempotent'    => false,
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
				'success' => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
				'result'  => [
					'type'                 => 'object',
					'description'          => __( 'Raw generator result (send status, error, or skip reason).', 'thinkrank' ),
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

		$result  = $manager->generator()->generate_test();
		$success = ! empty( $result['success'] );

		if ( ! $success ) {
			$message = isset( $result['error'] ) && is_string( $result['error'] )
				? (string) $result['error']
				: __( 'The test report could not be sent.', 'thinkrank' );

			return new \WP_Error(
				'thinkrank_email_report_test_send_failed',
				$message,
				[
					'status' => 400,
					'result' => $result,
				]
			);
		}

		return [
			'success' => true,
			'message' => __( 'Test email report sent.', 'thinkrank' ),
			'result'  => $result,
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
