<?php
/**
 * Get duplicate head tags ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Duplicate_Tags\Duplicate_Tag_Detector;
use ThinkRank\SEO\Duplicate_Tags\Guard_Settings;
use ThinkRank\SEO\Duplicate_Tags\Head_Tags;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports the SEO head tags printed more than once on the home page and the
 * latest post, and the Duplicate Tag Guard switches (#733).
 *
 * Mirrors `GET /thinkrank/v1/duplicate-tags` (and `POST …/scan` with refresh).
 */
class Get_Duplicate_Tags extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-duplicate-tags';
		$this->label       = __( 'Get ThinkRank Duplicate Head Tags', 'thinkrank' );
		$this->description = __( 'Find the SEO tags printed more than once in the <head> of the home page and the latest post - a second <title>, canonical, robots or meta description, duplicate Open Graph or Twitter card tags, or a schema type published twice - as a logged-out visitor gets the pages. Each duplicate says how many copies ThinkRank printed and how many came from another plugin or the theme, a best guess at which plugin, and whether ThinkRank can remove the foreign copies (removable: ThinkRank prints the same tag). Also returns the Duplicate Tag Guard switches per tag family and what the guard already removed. Cached for an hour; set refresh to fetch the pages again. Turn removal on with thinkrank/update-duplicate-tag-guard.', 'thinkrank' );
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
			'priority'      => 1.5,
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
				'refresh' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Fetch the pages again instead of using the last hour\'s check.', 'thinkrank' ),
				],
			],
		];
	}

	/**
	 * The switches, shared with the update ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_schema() {
		$properties = [];
		$labels     = [
			'title'     => __( 'The <title> tag and the meta description.', 'thinkrank' ),
			'canonical' => __( 'The canonical link.', 'thinkrank' ),
			'robots'    => __( 'The robots meta tag (and googlebot / bingbot).', 'thinkrank' ),
			'social'    => __( 'Open Graph (og:*, article:*) and Twitter card tags.', 'thinkrank' ),
			'schema'    => __( 'JSON-LD blocks whose every page-level type ThinkRank already publishes.', 'thinkrank' ),
		];
		foreach ( Head_Tags::FAMILIES as $family ) {
			$properties[ $family ] = [
				'type'        => 'boolean',
				'description' => $labels[ $family ] ?? '',
			];
		}

		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		$families = Head_Tags::FAMILIES;

		return [
			'type'       => 'object',
			'properties' => [
				'settings' => self::settings_schema(),
				'report'   => [
					'type'       => 'object',
					'properties' => [
						'checked_at' => [ 'type' => 'integer' ],
						'summary'    => [
							'type'       => 'object',
							'properties' => [
								'checked'            => [ 'type' => 'integer' ],
								'failed'             => [ 'type' => 'integer' ],
								'duplicates'         => [ 'type' => 'integer' ],
								'critical'           => [ 'type' => 'integer' ],
								'removed'            => [ 'type' => 'integer' ],
								'removable_families' => [
									'type'  => 'array',
									'items' => [
										'type' => 'string',
										'enum' => $families,
									],
								],
							],
						],
						'pages'      => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'url'        => [ 'type' => 'string' ],
									'ok'         => [ 'type' => 'boolean' ],
									'error'      => [ 'type' => 'string' ],
									'removed'    => [
										'type'        => 'object',
										'description' => __( 'Tags the guard removed on this page, by family.', 'thinkrank' ),
									],
									'duplicates' => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'key'       => [
													'type'        => 'string',
													'description' => __( 'The tag: "title", "meta:description", "link:canonical", "meta:robots", an Open Graph or Twitter property such as "og:title", or "schema:<Type>".', 'thinkrank' ),
												],
												'family'    => [
													'type' => 'string',
													'enum' => $families,
												],
												'count'     => [ 'type' => 'integer' ],
												'ours'      => [ 'type' => 'integer' ],
												'foreign'   => [ 'type' => 'integer' ],
												'critical'  => [ 'type' => 'boolean' ],
												'removable' => [ 'type' => 'boolean' ],
												'sources'   => [
													'type'        => 'array',
													'items'       => [ 'type' => 'string' ],
													'description' => __( 'Best guess at who printed the other copies; empty when unknown (another plugin or the theme).', 'thinkrank' ),
												],
											],
										],
									],
								],
							],
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
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$report = ( new Duplicate_Tag_Detector() )->scan( empty( ( (array) $input )['refresh'] ) );

		// A page the guard removed nothing from has an empty map, which would
		// reach the client as `[]` where the schema promises an object.
		if ( isset( $report['pages'] ) && is_array( $report['pages'] ) ) {
			foreach ( $report['pages'] as $i => $page ) {
				if ( is_array( $page ) && isset( $page['removed'] ) && is_array( $page['removed'] ) ) {
					$report['pages'][ $i ]['removed'] = self::object_value( $page['removed'] );
				}
			}
		}

		return [
			'settings' => ( new Guard_Settings() )->get(),
			'report'   => $report,
		];
	}
}
