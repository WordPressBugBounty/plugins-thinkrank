<?php
/**
 * Get page head snapshot ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Regression\Regression_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads Regression Guard's last check of a page (#720): what the page served
 * search engines at that moment, and what changed since the check before it.
 */
class Get_Page_Head_Snapshot extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-page-head-snapshot';
		$this->label       = __( 'Get ThinkRank Page Head Snapshot', 'thinkrank' );
		$this->description = __( 'Read the last Regression Guard check of a page without loading it again: the SEO output the page actually served (title, meta description, robots and X-Robots-Tag, canonical, hreflang, Open Graph, Twitter card, JSON-LD types with price, availability and rating, HTTP status, H1 count) and what changed since the check before it. Each change has a severity - critical (noindex appeared, not 200, now redirects, canonical points elsewhere, a structured data type disappeared, title empty), warning or info - and the result says whether the changes were expected, meaning the post, the term or a ThinkRank or WordPress setting was edited between the two checks. Unexpected critical changes are the ones a plugin, theme or core update caused. Use post_id 0 for the homepage; find other ids with list-content-items. snapshot is null when the page was never checked - run check-page-head to check it now.', 'thinkrank' );
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
				'post_id' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'Published post, page or product id. 0 is the homepage.', 'thinkrank' ),
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
		$change = [
			'type'       => 'object',
			'properties' => [
				'code'     => [ 'type' => 'string' ],
				'field'    => [
					'type' => 'string',
					'enum' => [ 'status', 'title', 'description', 'robots', 'canonical', 'schema', 'social', 'hreflang', 'h1', 'duplicate_tags' ],
				],
				'severity' => [
					'type' => 'string',
					'enum' => [ 'critical', 'warning', 'info' ],
				],
				'message'  => [ 'type' => 'string' ],
				'before'   => [ 'type' => 'string' ],
				'after'    => [ 'type' => 'string' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'post_id'             => [ 'type' => 'integer' ],
				'url'                 => [ 'type' => 'string' ],
				'label'               => [ 'type' => 'string' ],
				'checked_at'          => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Unix time of the stored check, or null if the page was never checked.', 'thinkrank' ),
				],
				'previous_checked_at' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Unix time of the check the result compares against. Null when the stored check is the first one.', 'thinkrank' ),
				],
				'snapshot'            => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'What the page served. noindex is true when the robots meta or X-Robots-Tag header blocks indexing.', 'thinkrank' ),
				],
				'result'              => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'Null for a first check, which has nothing to compare with.', 'thinkrank' ),
					'properties'  => [
						'changed'  => [ 'type' => 'boolean' ],
						'expected' => [ 'type' => 'boolean' ],
						'reasons'  => [
							'type'  => 'array',
							'items' => [
								'type' => 'string',
								'enum' => Regression_Guard::REASONS,
							],
						],
						'counts'   => [
							'type'       => 'object',
							'properties' => [
								'critical' => [ 'type' => 'integer' ],
								'warning'  => [ 'type' => 'integer' ],
								'info'     => [ 'type' => 'integer' ],
							],
						],
						'changes'  => [
							'type'  => 'array',
							'items' => $change,
						],
					],
				],
				'error'               => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'Set when the page could not be loaded. Nothing was compared and the previous check is kept.', 'thinkrank' ),
					'properties'  => [
						'code'    => [
							'type' => 'string',
							'enum' => [ 'loopback_failed', 'loopback_blocked', 'loopback_foreign_url' ],
						],
						'message' => [ 'type' => 'string' ],
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
		return Regression_Guard::get( absint( $input['post_id'] ?? 0 ) );
	}
}
