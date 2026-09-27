<?php
/**
 * Get duplicate snippets ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Duplicate_Snippets;
use ThinkRank\SEO\Snippet_Issues;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports published pages that render the same SEO title, or the same meta
 * description, as another page anywhere on the site (#564).
 *
 * Distinct from list-snippet-issues, which answers "what is wrong with this
 * post type's snippets" one post type at a time. A duplicate is a relationship
 * between two pages that are often different post types, so it needs a report
 * whose scope is the whole site and whose unit is the repeated value rather
 * than the post.
 */
class Get_Duplicate_Snippets extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-duplicate-snippets';
		$this->label       = __( 'Get ThinkRank Duplicate Snippets', 'thinkrank' );
		$this->description = __( 'Find published pages across every post type that render the same search title, or the same meta description, as another page. Groups them by the repeated value, largest group first, so one bad post type template shows up as a single finding rather than fifty. Covers published content only, since a draft cannot compete in search. Returns the value, how many pages carry it, and which pages they are. Fix one with update-post-seo.', 'thinkrank' );
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
				'issue'   => [
					'type'        => 'string',
					'enum'        => array_merge( [ '' ], Snippet_Issues::grouped() ),
					'default'     => '',
					'description' => __( 'Limit the groups to one kind. Empty returns both. Counts are always returned for both.', 'thinkrank' ),
				],
				'refresh' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Discard the index and rescan every published page. The report already updates whenever a title, description or template changes, so this is only needed when it looks stale. The rescan runs in batches: pending rises, so call again without refresh until pending is zero.', 'thinkrank' ),
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
				'pending'      => [
					'type'        => 'integer',
					'description' => __( 'Pages not scanned yet. When above zero the counts are a floor, not the answer; call again to continue.', 'thinkrank' ),
				],
				'scanned'      => [
					'type'        => 'integer',
					'description' => __( 'Published pages the report covers.', 'thinkrank' ),
				],
				'counts'       => [
					'type'        => 'object',
					'description' => __( 'Pages caught up in a duplicate, per issue.', 'thinkrank' ),
				],
				'group_counts' => [
					'type'        => 'object',
					'description' => __( 'Distinct repeated values, per issue.', 'thinkrank' ),
				],
				'truncated'    => [
					'type'        => 'object',
					'description' => __( 'Per issue, whether there are more repeated values than the groups listed.', 'thinkrank' ),
				],
				'groups'       => [
					'type'        => 'object',
					'description' => __( 'Repeated values per issue, largest group first.', 'thinkrank' ),
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
		$issue   = isset( $input['issue'] ) ? sanitize_key( (string) $input['issue'] ) : '';
		$refresh = ! empty( $input['refresh'] );

		if ( '' !== $issue && ! in_array( $issue, Snippet_Issues::grouped(), true ) ) {
			return new \WP_Error(
				'thinkrank_invalid_issue',
				__( 'Unknown issue. Duplicates are reported for duplicate_title and duplicate_description.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$report = Duplicate_Snippets::report( $refresh );

		$groups = [];
		foreach ( $report['groups'] as $key => $found ) {
			if ( '' !== $issue && $key !== $issue ) {
				continue;
			}

			$groups[ $key ] = array_map(
				static function ( array $group ): array {
					// The grouping hash is an implementation detail of the
					// index; the value and the pages are the finding.
					return [
						'value' => $group['value'],
						'total' => $group['total'],
						'pages' => array_map(
							static function ( array $post ): array {
								return [
									'post_id'   => $post['post_id'],
									'post_title' => $post['post_title'],
									'post_type' => $post['post_type'],
									'permalink' => $post['permalink'],
								];
							},
							$group['posts']
						),
						// True when the group has more pages than are named.
						'partial' => $group['partial'],
					];
				},
				$found
			);
		}

		return [
			'pending'      => $report['pending'],
			'scanned'      => $report['scanned'],
			'counts'       => $report['counts'],
			'group_counts' => $report['group_counts'],
			'truncated'    => $report['truncated'],
			'groups'       => $groups,
		];
	}
}
