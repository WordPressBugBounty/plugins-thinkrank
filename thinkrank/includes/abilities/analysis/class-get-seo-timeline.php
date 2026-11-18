<?php
/**
 * Read the SEO Timeline.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Timeline_Endpoint;
use ThinkRank\SEO\Timeline\Event_Recorder;
use ThinkRank\SEO\Timeline\Event_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * "What changed before the traffic dropped?", as an ability.
 *
 * The question an agent is asked after a drop is the same question a human is
 * asked, and until now neither could answer it from inside WordPress. Pointing
 * an agent at this before it starts guessing is most of the value of recording
 * the events at all.
 */
class Get_Seo_Timeline extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-seo-timeline';
		$this->label       = __( 'Get SEO Timeline', 'thinkrank' );
		$this->description = __( 'List the recorded changes that can move search traffic: search-engine visibility and permalink changes, plugin, theme and core updates, activations and deactivations, theme switches, posts published or unpublished, slug changes, per-post SEO field edits, and ThinkRank settings saves. Newest first, filterable by date range, type and severity. Use it to answer "what changed before this date" before looking for a cause elsewhere. Free keeps 30 days; a settings event names the section and the keys and never the values. Pair it with thinkrank/get-seo-analytics-data to line the changes up against the traffic they may explain.', 'thinkrank' );
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
				'from'     => [
					'type'        => 'string',
					'description' => __( 'Earliest event time: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" in UTC, or an ISO 8601 time such as "2026-10-01T08:00:00Z".', 'thinkrank' ),
				],
				'to'       => [
					'type'        => 'string',
					'description' => __( 'Latest event time: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" in UTC, or an ISO 8601 time such as "2026-10-01T08:00:00Z".', 'thinkrank' ),
				],
				'type'     => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => Event_Recorder::types(),
					],
					'description' => __( 'Event types to include. Omit for all.', 'thinkrank' ),
				],
				'severity' => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => Event_Repository::SEVERITIES,
					],
					'description' => __( 'Severities to include: critical changes every URL or the whole site, warning changes one URL or the markup, info is everything else.', 'thinkrank' ),
				],
				'limit'    => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => Event_Repository::MAX_LIMIT,
					'default' => 50,
				],
				'offset'   => [
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
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
				'events'         => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'integer' ],
							'occurred_at' => [ 'type' => 'string' ],
							'event_type'  => [ 'type' => 'string' ],
							'severity'    => [ 'type' => 'string' ],
							'object_type' => [ 'type' => 'string' ],
							'object_id'   => [ 'type' => 'integer' ],
							'actor'       => [ 'type' => 'string' ],
							'summary'     => [ 'type' => 'string' ],
							'meta'        => [ 'type' => [ 'object', 'null' ] ],
						],
					],
				],
				'total'          => [ 'type' => 'integer' ],
				'retention_days' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$input = (array) $input;

		// A bound in another format was dropped, so the whole history came
		// back as if it matched the filter (#1687).
		foreach ( [ 'from', 'to' ] as $param ) {
			$error = Timeline_Endpoint::date_param_error( $param, $input[ $param ] ?? null );
			if ( null !== $error ) {
				return $error;
			}
		}

		$args = [
			'from'     => Timeline_Endpoint::normalize_date( (string) ( $input['from'] ?? '' ), '00:00:00' ) ?? '',
			'to'       => Timeline_Endpoint::normalize_date( (string) ( $input['to'] ?? '' ), '23:59:59' ) ?? '',
			'type'     => (array) ( $input['type'] ?? [] ),
			'severity' => (array) ( $input['severity'] ?? [] ),
			'limit'    => (int) ( $input['limit'] ?? 50 ),
			'offset'   => (int) ( $input['offset'] ?? 0 ),
		];

		return [
			'events'         => Event_Repository::query( $args ),
			'total'          => Event_Repository::count( $args ),
			'retention_days' => Event_Repository::retention_days(),
		];
	}
}
