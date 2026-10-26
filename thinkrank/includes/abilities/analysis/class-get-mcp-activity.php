<?php
/**
 * Get MCP activity ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Mcp\Mcp_Audit;
use ThinkRank\Mcp\Mcp_Governance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the MCP activity log, so an assistant can answer "what did you change
 * yesterday", and a second assistant can review what the first one did (#717).
 *
 * Read-only on purpose. Reverting, changing a connection's scopes and setting
 * the log's retention stay with the site administrator on the MCP screen: an
 * agent that could do any of those could widen its own access or erase its
 * own trail.
 */
class Get_Mcp_Activity extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-mcp-activity';
		$this->label       = __( 'Get MCP Activity', 'thinkrank' );
		$this->description = __( 'Read the log of what AI assistants did on this site through the ThinkRank MCP server, newest first: every change with the values before and after it, every refused call, and a daily count of reads per connection. Use it to answer what was changed and when, or to review another assistant\'s changes. Credentials are masked, and for a change in an area this connection cannot read, the arguments, values, message and target title are withheld (`withheld: true`). To see a value as it stands now, call the matching get- tool, such as get-post-seo for an update-post-seo entry. Only the site administrator can revert a change, from ThinkRank > AI Tools > MCP > Activity.', 'thinkrank' );
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
			'properties'           => [
				'days'         => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 7,
					'description' => __( 'How many days back to read. The log keeps 30, 90 or 365 days, as the site administrator set it.', 'thinkrank' ),
				],
				'changes_only' => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Return only successful changes and reverts. Set false to include refused calls, errors and the daily read counts.', 'thinkrank' ),
				],
				'tool'         => [
					'type'        => 'string',
					'description' => __( 'Only entries for this tool, e.g. update-post-seo.', 'thinkrank' ),
				],
				'post_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Only entries that targeted this post.', 'thinkrank' ),
				],
				'limit'        => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				],
			],
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
				'entries'        => [
					'type'        => 'array',
					'description' => __( 'Log entries, newest first. kind is write, revert or read; result is ok, error or denied; before and after hold only the keys the call changed; reverted_by is the id of the revert that undid an entry, 0 if none.', 'thinkrank' ),
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
				'total'          => [ 'type' => 'integer' ],
				'retention_days' => [ 'type' => 'integer' ],
				'available'      => [
					'type'        => 'boolean',
					'description' => __( 'False until the activity table has been created, which happens the next time an administrator loads the WordPress admin.', 'thinkrank' ),
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
		$input = (array) $input;
		$days  = max( 1, min( 365, (int) ( $input['days'] ?? 7 ) ) );

		$page = Mcp_Audit::query(
			[
				'since'        => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
				'changes_only' => ! array_key_exists( 'changes_only', $input ) || ! empty( $input['changes_only'] ),
				'tool'         => sanitize_key( (string) ( $input['tool'] ?? '' ) ),
				'object'       => ! empty( $input['post_id'] ) ? 'post:' . (int) $input['post_id'] : '',
			],
			1,
			max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) )
		);

		return [
			'entries'        => array_map(
				static function ( array $row ): array {
					// The caller's IP is for the administrator, not for an agent.
					unset( $row['ip'] );
					return Mcp_Audit::for_caller( $row );
				},
				$page['rows']
			),
			'total'          => $page['total'],
			'retention_days' => Mcp_Governance::settings()['retention_days'],
			'available'      => Mcp_Audit::available(),
		];
	}
}
