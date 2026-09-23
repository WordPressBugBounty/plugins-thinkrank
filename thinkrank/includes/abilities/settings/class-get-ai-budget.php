<?php
/**
 * Get AI budget ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\Spend_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports what ThinkRank has spent against the site's AI limits today.
 *
 * Reads the live counter rather than the stored setting alone, so an agent
 * asked "how much AI have we used today" gets the number the guard is actually
 * enforcing against (#448).
 */
class Get_Ai_Budget extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-ai-budget';
		$this->label       = __( 'Get ThinkRank AI Budget', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank AI spend limits and today\'s usage: whether AI is paused, the daily request ceiling (0 means no ceiling), requests used today, requests remaining, the per-minute limit, and when the daily count resets. Use update-ai-budget to change the limits or pause AI.', 'thinkrank' );
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
				'paused'           => [ 'type' => 'boolean' ],
				'daily_limit'      => [ 'type' => 'integer' ],
				'used_today'       => [ 'type' => 'integer' ],
				'remaining_today'  => [ 'type' => [ 'integer', 'null' ] ],
				'per_minute_limit' => [ 'type' => 'integer' ],
				'resets_at'        => [ 'type' => 'string' ],
				'blocked'          => [ 'type' => 'boolean' ],
				'blocked_reason'   => [
					'type' => [ 'string', 'null' ],
					'enum' => [ Spend_Guard::REASON_PAUSED, Spend_Guard::REASON_DAILY_LIMIT, null ],
				],
				'message'          => [ 'type' => 'string' ],
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
		return Spend_Guard::status();
	}
}
