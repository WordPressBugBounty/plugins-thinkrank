<?php
/**
 * Get keyword map ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Keyword_Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports which pages target which focus keywords, which target none, and
 * which keywords more than one page is competing for (#568).
 *
 * The keyword conflicts come from the database alone. Query-level conflicts,
 * where several of the site's URLs rank for one search and split its clicks,
 * need Search Console and are reported separately when it is connected.
 */
class Get_Keyword_Map extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-keyword-map';
		$this->label       = __( 'Get ThinkRank Keyword Map', 'thinkrank' );
		$this->description = __( 'See which pages target which focus keywords across the whole site, which pages target none, and which keywords more than one page is competing for (keyword cannibalization). Two pages chasing one keyword split their own ranking, and the edit-time warning only catches that at the moment someone types it, so on an established site most existing conflicts have never been reported. Keywords are matched case-insensitively, the way the plugin itself deduplicates them. Covers published, scheduled, draft, pending and private posts, because a draft competing with a live page is worth knowing about before it ships. Set or change a page keyword with update-post-seo; get-thin-content answers the other site-wide content question.', 'thinkrank' );
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
			// The Search Console half reaches Google when the site is
			// connected, so this is not a purely local read.
			'openWorldHint' => true,
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
				'include_search_console' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also ask Search Console for queries where several of this site\'s URLs rank and split the clicks. Off by default because it is a live request to Google and needs Search Console connected; the keyword conflicts are found without it.', 'thinkrank' ),
				],
				'refresh'                => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Rebuild the report instead of reading the cached one. The cache is already discarded when a keyword changes, so this is only needed to force a rescan.', 'thinkrank' ),
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
				'pending'    => [
					'type'        => 'integer',
					'description' => __( 'Pages not indexed yet. When above zero every number below is a floor, not the answer: a conflict is invisible until both of its pages have been read. Call again to continue.', 'thinkrank' ),
				],
				'indexed'    => [
					'type'        => 'integer',
					'description' => __( 'Pages the map covers.', 'thinkrank' ),
				],
				'targeted'   => [
					'type'        => 'integer',
					'description' => __( 'Pages with at least one focus keyword.', 'thinkrank' ),
				],
				'untargeted' => [
					'type'        => 'integer',
					'description' => __( 'Pages with no focus keyword at all.', 'thinkrank' ),
				],
				'keywords'   => [
					'type'        => 'integer',
					'description' => __( 'Distinct keywords in use across the site.', 'thinkrank' ),
				],
				'conflicted' => [
					'type'        => 'integer',
					'description' => __( 'Keywords targeted by more than one page.', 'thinkrank' ),
				],
				'conflicts'  => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'keyword' => [ 'type' => 'string' ],
							'total'   => [
								'type'        => 'integer',
								'description' => __( 'Pages targeting this keyword, which may be more than the list below names.', 'thinkrank' ),
							],
							'pages'   => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'post_id'     => [ 'type' => 'integer' ],
										'post_title'  => [ 'type' => 'string' ],
										'permalink'   => [ 'type' => 'string' ],
										'post_status' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
				'untargeted_pages' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'post_id'     => [ 'type' => 'integer' ],
							'post_title'  => [ 'type' => 'string' ],
							'permalink'   => [ 'type' => 'string' ],
							'post_status' => [ 'type' => 'string' ],
						],
					],
				],
				'search_console' => [
					'type'        => 'object',
					'description' => __( 'Present only when asked for. `connected` false means the check was not possible, not that no conflicts exist - read `reason` before reporting a clean result.', 'thinkrank' ),
					'properties'  => [
						'connected' => [ 'type' => 'boolean' ],
						'reason'    => [ 'type' => 'string' ],
						'queries'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'query'     => [ 'type' => 'string' ],
									'clicks'    => [ 'type' => 'integer' ],
									'urls'      => [
										'type'        => 'integer',
										'description' => __( 'URLs ranking for this query at all, most of which usually earn nothing.', 'thinkrank' ),
									],
									'competing' => [
										'type'        => 'integer',
										'description' => __( 'Distinct pages that earned clicks, which is the size of the split. Two below means two pages really are competing for this query.', 'thinkrank' ),
									],
									'alternates' => [
										'type'        => 'integer',
										'description' => __( 'How many of the listed URLs were the same page in another language. A query whose competitors were all language alternates is not reported at all, so this only explains why more URLs are listed than there are competing pages.', 'thinkrank' ),
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
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$report = Keyword_Map::report( ! empty( $input['refresh'] ) );

		$conflicts = [];
		foreach ( $report['conflicts'] as $conflict ) {
			$conflicts[] = [
				'keyword' => $conflict['keyword'],
				'total'   => $conflict['total'],
				'pages'   => array_map( [ self::class, 'page_fields' ], $conflict['posts'] ),
			];
		}

		$out = [
			'pending'          => $report['pending'],
			'indexed'          => $report['indexed'],
			'targeted'         => $report['targeted'],
			'untargeted'       => $report['untargeted'],
			'keywords'         => $report['keywords'],
			'conflicted'       => $report['conflicted'],
			'conflicts'        => $conflicts,
			'untargeted_pages' => array_map( [ self::class, 'page_fields' ], $report['untargeted_posts'] ),
		];

		if ( ! empty( $input['include_search_console'] ) ) {
			$out['search_console'] = Keyword_Map::search_console();
		}

		return $out;
	}

	/**
	 * The fields an agent gets for a page.
	 *
	 * The screen's edit URL and capability flag stay on the REST response; an
	 * agent acts through update-post-seo.
	 *
	 * @param array<string, mixed> $page Page row from the report.
	 * @return array<string, mixed>
	 */
	private static function page_fields( array $page ): array {
		return [
			'post_id'     => $page['post_id'],
			'post_title'  => $page['post_title'],
			'permalink'   => $page['permalink'],
			'post_status' => $page['post_status'],
		];
	}
}
