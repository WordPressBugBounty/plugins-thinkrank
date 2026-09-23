<?php
/**
 * Update AI budget ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\Spend_Guard;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sets the site's AI spend ceiling, or stops outbound AI entirely.
 *
 * Returns what was stored rather than what was submitted, so a caller that
 * sends a ceiling the store reduces is told the real value instead of a
 * successful save of something the site does not apply.
 */
class Update_Ai_Budget extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-ai-budget';
		$this->label       = __( 'Update ThinkRank AI Budget', 'thinkrank' );
		$this->description = __( 'Change ThinkRank AI spend limits. Set `paused` to true to stop every outbound AI request immediately, including scheduled ones, without touching the API key. Set `daily_request_limit` to cap requests per day (0 removes the cap), or `max_requests_per_minute` for the per-minute throttle. Use get-ai-budget to read current usage.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Pausing AI loses nothing and is reversed by the same call with
			// `paused: false`, so this is routine configuration, not a
			// destructive act. Marking settings destructive makes MCP clients
			// demand a human approval for every save (#675).
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
				'settings' => [
					'type'        => 'object',
					'description' => __( 'AI budget settings to update.', 'thinkrank' ),
					'properties'  => [
						'paused'                  => [
							'type'        => 'boolean',
							'description' => __( 'True stops all outbound AI requests immediately. False resumes them.', 'thinkrank' ),
						],
						'daily_request_limit'     => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Maximum AI requests per day. 0 means no daily ceiling.', 'thinkrank' ),
						],
						'max_requests_per_minute' => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Maximum AI requests per minute per user. 0 means no per-minute throttle.', 'thinkrank' ),
						],
						'reset_usage'             => [
							'type'        => 'boolean',
							'description' => __( 'True clears today\'s request count. This does not refund anything at the AI provider; it only restarts this site\'s daily window.', 'thinkrank' ),
						],
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
				'success' => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
				'budget'  => [ 'type' => 'object' ],
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
				'thinkrank_missing_ai_budget_payload',
				__( 'An AI budget settings payload is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$store = Settings::instance();
		$found = false;

		if ( array_key_exists( 'paused', $settings ) ) {
			$store->set( 'ai_paused', (bool) $settings['paused'] );
			$found = true;
		}

		if ( array_key_exists( 'daily_request_limit', $settings ) ) {
			$store->set( 'ai_daily_request_limit', max( 0, (int) $settings['daily_request_limit'] ) );
			$found = true;
		}

		if ( array_key_exists( 'max_requests_per_minute', $settings ) ) {
			$store->set( 'max_requests_per_minute', max( 0, (int) $settings['max_requests_per_minute'] ) );
			$found = true;
		}

		if ( ! empty( $settings['reset_usage'] ) ) {
			Spend_Guard::reset_usage();
			$found = true;
		}

		if ( ! $found ) {
			return new \WP_Error(
				'thinkrank_no_valid_ai_budget_keys',
				__( 'No valid AI budget setting keys were provided.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'success' => true,
			'message' => __( 'AI budget settings updated.', 'thinkrank' ),
			'budget'  => Spend_Guard::status(),
		];
	}
}
