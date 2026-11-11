<?php
/**
 * Get AI Insights ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Ai_Insights_Endpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the AI Insights dashboard: AI referral traffic, the pages each AI
 * platform sends visitors to, AI crawler fetches, and the state of the controls
 * that affect AI visibility (#734).
 *
 * Reads through the same payload the screen renders, so an agent and a person
 * looking at AI Insights see the same numbers.
 */
class Get_Ai_Insights extends Ability_Base {

	/**
	 * Ranges the dashboard offers, in days.
	 */
	private const RANGES = [ 7, 30, 90 ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-ai-insights';
		$this->label       = __( 'Get ThinkRank AI Insights', 'thinkrank' );
		$this->description = __( 'Read AI traffic recorded on this site from its own server logs: visits referred by AI assistants (ChatGPT, Perplexity, Gemini, Claude, Copilot and others) with their share of all visits, a daily trend, the landing pages each platform sends visitors to, AI crawler fetches, and the current state of the controls that affect AI visibility (how many AI crawlers robots.txt allows, and whether llms.txt is live and when it was last updated). Counts are first-party request data, not estimates of AI citations. Read-only: change which AI crawlers robots.txt allows with update-site-identity-settings, and see or regenerate llms.txt with get-llms-txt-status and generate-llms-txt.', 'thinkrank' );
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
				'days' => [
					'type'        => 'integer',
					'enum'        => self::RANGES,
					'default'     => 30,
					'description' => __( 'Range in days.', 'thinkrank' ),
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
				'days'              => [ 'type' => 'integer' ],
				'ai_sessions'       => [
					'type'        => 'integer',
					'description' => __( 'Visits referred by an AI platform in the range.', 'thinkrank' ),
				],
				'ai_sessions_to_yesterday' => [
					'type'        => 'integer',
					'description' => __( 'AI referral visits over the complete days of the range, ending yesterday (today left out). The same window GA4 reports, so compare GA4 sessions with this, not with ai_sessions.', 'thinkrank' ),
				],
				'baseline'          => [
					'type'        => 'integer',
					'description' => __( 'All tracked pageviews in the range.', 'thinkrank' ),
				],
				'ai_share'          => [
					'type'        => 'number',
					'description' => __( 'AI referral visits as a percentage of all tracked pageviews.', 'thinkrank' ),
				],
				'platforms'         => [
					'type'        => 'object',
					'description' => __( 'Referral visits per AI platform slug, highest first.', 'thinkrank' ),
				],
				'pages_by_platform' => [
					'type'        => 'object',
					'description' => __( 'Per AI platform slug, the landing paths it sent visitors to with visit counts (top 10 each).', 'thinkrank' ),
				],
				'top_pages'         => [
					'type'        => 'object',
					'description' => __( 'Landing paths across all AI platforms with visit counts (top 10).', 'thinkrank' ),
				],
				'trend'             => [
					'type'        => 'object',
					'description' => __( 'AI referral visits per day (site-local date), zero-filled across the range.', 'thinkrank' ),
				],
				'crawlers'          => [
					'type'        => 'object',
					'description' => __( 'Page fetches per AI crawler slug.', 'thinkrank' ),
				],
				'llms_txt'          => [ 'type' => 'boolean' ],
				'markdown_served'   => [ 'type' => 'integer' ],
				'guidance'          => [
					'type'       => 'object',
					'properties' => [
						'crawlers' => [
							'type'        => 'object',
							'description' => __( 'AI crawler rules ThinkRank writes to robots.txt: total known crawlers, how many are allowed and blocked, and the blocked slugs.', 'thinkrank' ),
						],
						'llms_txt' => [
							'type'        => 'object',
							'description' => __( 'Whether llms.txt is published, its URL, and when it was last updated (Unix time, null when unpublished).', 'thinkrank' ),
						],
					],
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
		$days = isset( $input['days'] ) ? (int) $input['days'] : 30;

		if ( ! in_array( $days, self::RANGES, true ) ) {
			return new \WP_Error(
				'thinkrank_invalid_range',
				__( 'days must be 7, 30 or 90.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$payload = Ai_Insights_Endpoint::payload( $days );

		// Maps keyed by platform, path, date or crawler are empty on a site
		// with no AI traffic yet and must still reach the client as `{}` (#1089).
		foreach ( [ 'platforms', 'pages_by_platform', 'top_pages', 'trend', 'crawlers' ] as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$payload[ $key ] = self::object_value( $payload[ $key ] );
			}
		}

		return $payload;
	}
}
