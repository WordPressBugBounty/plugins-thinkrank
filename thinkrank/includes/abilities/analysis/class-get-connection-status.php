<?php
/**
 * Get connection status ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Diagnostics\Connection_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports ThinkRank's own integration health so an AI/MCP client can tell a
 * certificate problem from an authentication problem from a permission problem
 * from an ability-registration problem — without inspecting plugin source or DB
 * tables by hand (see #188).
 *
 * Read-only and side-effect free. Never returns secret material (API keys,
 * OAuth tokens, connection tokens).
 */
class Get_Connection_Status extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-connection-status';
		$this->label       = __( 'Get Connection Status', 'thinkrank' );
		$this->description = __( 'Report ThinkRank integration health: plugin/Pro versions and active state, Abilities API availability and the count + names of registered ThinkRank abilities, MCP server toggle and endpoints, the current user and their ThinkRank capabilities, home/siteurl/REST URL scheme agreement and HTTPS state, custom database-table status, and scoring availability. Never returns API keys, OAuth tokens, or connection tokens.', 'thinkrank' );
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
				'plugin'     => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'abilities'  => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'mcp_server' => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'user'       => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'urls'       => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'database'   => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'scoring'    => [
					'type'                 => 'object',
					'additionalProperties' => true,
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
		return Connection_Status::report();
	}
}
