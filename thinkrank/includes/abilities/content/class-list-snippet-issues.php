<?php
/**
 * List snippet issues ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Snippets_Endpoint;
use ThinkRank\SEO\Global_SEO_Post_Types;
use ThinkRank\SEO\Snippet_Issues;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lists posts whose search snippet has a problem — the Bulk Snippets screen,
 * for an agent (#727).
 *
 * Reads through the same collection as the screen, so an agent and a person
 * looking at "empty description" see the same posts and the same counts.
 */
class List_Snippet_Issues extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/list-snippet-issues';
		$this->label       = __( 'List ThinkRank Snippet Issues', 'thinkrank' );
		$this->description = __( 'Find posts whose search snippet has a problem: no custom SEO title or meta description, a title or description outside the recommended length, no focus keyword, noindex, or a title another post also uses. Returns each post\'s stored values, the title and description the page actually renders, its issues, and a count per issue across the whole post type. Use list-content-types for post type slugs, then fix a post with update-post-seo.', 'thinkrank' );
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
				'post_type' => [
					'type'        => 'string',
					'description' => __( 'Post type slug, e.g. post, page or product. See list-content-types.', 'thinkrank' ),
				],
				'issue'     => [
					'type'        => 'string',
					'enum'        => array_merge( [ '' ], Snippet_Issues::all() ),
					'default'     => '',
					'description' => __( 'Only return posts with this issue. Empty returns every post, each with its issues. empty_title and empty_description mean the post has no value of its own and renders its post type template; length issues are judged on the rendered value.', 'thinkrank' ),
				],
				'status'    => [
					'type'        => 'string',
					'enum'        => array_keys( Snippets_Endpoint::STATUS_SETS ),
					'default'     => 'publish',
					'description' => __( 'publish = published only; draft = drafts, pending and scheduled; any = both plus private.', 'thinkrank' ),
				],
				'search'    => [
					'type'        => 'string',
					'description' => __( 'Optional search within post titles and content.', 'thinkrank' ),
				],
				'page'      => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
				'per_page'  => [
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				],
			],
			'required'             => [ 'post_type' ],
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
				'post_type'   => [ 'type' => 'string' ],
				'issue'       => [ 'type' => 'string' ],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
				'page'        => [ 'type' => 'integer' ],
				'pending'     => [
					'type'        => 'integer',
					'description' => __( 'Posts not analysed yet. When above zero, counts are partial; call again to continue.', 'thinkrank' ),
				],
				'counts'      => [
					'type'        => 'object',
					'description' => __( 'Posts per issue across the whole post type and status, regardless of the issue filter.', 'thinkrank' ),
				],
				'items'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'post_id'               => [ 'type' => 'integer' ],
							'post_title'            => [ 'type' => 'string' ],
							'status'                => [ 'type' => 'string' ],
							'title'                 => [ 'type' => 'string' ],
							'description'           => [ 'type' => 'string' ],
							'focus_keyword'         => [ 'type' => 'string' ],
							'effective_title'       => [ 'type' => 'string' ],
							'effective_description' => [ 'type' => 'string' ],
							'noindex'               => [ 'type' => 'boolean' ],
							'issues'                => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
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
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$issue     = isset( $input['issue'] ) ? sanitize_key( (string) $input['issue'] ) : '';
		$status    = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish';
		$search    = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
		$page      = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page  = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;

		if ( ! Global_SEO_Post_Types::is_allowed( $post_type ) ) {
			return new \WP_Error(
				'thinkrank_invalid_post_type',
				__( 'That post type is not one ThinkRank manages. Use list-content-types for valid slugs.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' !== $issue && ! in_array( $issue, Snippet_Issues::all(), true ) ) {
			return new \WP_Error(
				'thinkrank_invalid_issue',
				__( 'Unknown issue.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$result = Snippets_Endpoint::page(
			[
				'post_type' => $post_type,
				'statuses'  => Snippets_Endpoint::STATUS_SETS[ $status ] ?? Snippets_Endpoint::STATUS_SETS['publish'],
				'issue'     => $issue,
				'search'    => $search,
				'page'      => $page,
				'per_page'  => $per_page,
			]
		);

		$items = [];
		foreach ( $result['snippets'] as $snippet ) {
			$row = Snippets_Endpoint::present( $snippet );

			// Only what an agent needs to decide and act; the screen's URLs and
			// editing flags stay on the REST response.
			$items[] = [
				'post_id'               => $row['post_id'],
				'post_title'            => $row['post_title'],
				'status'                => $row['status'],
				'title'                 => $row['title'],
				'description'           => $row['description'],
				'focus_keyword'         => $row['focus_keyword'],
				'effective_title'       => $row['effective_title'],
				'effective_description' => $row['effective_description'],
				'noindex'               => $row['noindex'],
				'issues'                => $row['issues'],
			];
		}

		$total = $result['total'];
		$counts = $result['counts'];

		return [
			'post_type'   => $post_type,
			'issue'       => $issue,
			'total'       => $total,
			'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
			'page'        => $page,
			'counts'      => $counts,
			// Posts not analysed yet; counts cover the rest. Call again to
			// continue — each call analyses another batch.
			'pending'     => $result['pending'],
			'items'       => $items,
		];
	}
}
