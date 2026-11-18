<?php
/**
 * Get post SEO checks ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Answer_Readiness;
use ThinkRank\Admin\Metabox_Manager;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;
use ThinkRank\SEO\Pattern_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs ThinkRank's SEO score analysis for one or more posts.
 */
class Get_Post_Seo_Checks extends Ability_Base {
	/**
	 * Most posts one call analyses.
	 *
	 * Every post gets a full score analysis and an answer readiness pass,
	 * each a scan of the whole content, and the response carries keyword
	 * placements and five check records per post. Uncapped, one call could
	 * ask for the whole site in a single synchronous request. Twenty-five is
	 * a screen's worth of posts to inspect; bulk-analyze-and-save covers
	 * scoring many posts.
	 */
	public const MAX_POSTS = 25;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-post-seo-checks';
		$this->label       = __( 'Get ThinkRank Post SEO Checks', 'thinkrank' );
		$this->description = __( 'Run ThinkRank SEO score analysis for one or more posts, pages, or custom post type items. Each score includes keywords: for every focus keyword, whether it appears in the SEO title, meta description, URL slug, first paragraph, a subheading (H2-H6), the body, and an image alt text, with the heading or alt text that matched - use it to see which keyword is missing where. Each result also carries answer_readiness: the five answer-engine checks (opening answer, question heading, list or table, depth, freshness) for that page, graded by the same rules as the site audit\'s GEO category, with a fix line for each failing one. A page with no extractable prose, such as a builder layout or a shortcode-only page, reports applicable false rather than failing everything. Scores are not persisted; use bulk-analyze-and-save to score and store them.', 'thinkrank' );
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
				'post_ids' => [
					'type'        => 'array',
					'description' => sprintf(
						/* translators: %d: maximum number of posts. */
						__( 'One or more post IDs to inspect, at most %d per call.', 'thinkrank' ),
						self::MAX_POSTS
					),
					'items'       => [
						'type' => 'integer',
					],
					'minItems'    => 1,
					'maxItems'    => self::MAX_POSTS,
				],
			],
			'required'             => [ 'post_ids' ],
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
				'status'  => [ 'type' => 'string' ],
				'message' => [ 'type' => 'string' ],
				'data'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'post_id' => [ 'type' => 'integer' ],
							'error'   => [ 'type' => 'string' ],
							'score'   => [
								'type'       => 'object',
								'properties' => [
									// Kept a number (#988): the calculator rounds the
									// overall score to a whole number since #1311, and
									// an integer still fits.
									'overall_score' => [
										'type'        => 'number',
										'description' => __( 'Score from 0 to 100, a whole number.', 'thinkrank' ),
									],
									'grade'         => [ 'type' => 'string' ],
									'keywords'      => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'keyword'    => [ 'type' => 'string' ],
												'passed'     => [
													'type'        => 'integer',
													'description' => __( 'Placements the keyword appears in.', 'thinkrank' ),
												],
												'total'      => [ 'type' => 'integer' ],
												'placements' => [
													'type'        => 'object',
													'description' => sprintf(
														/* translators: %s: comma-separated placement names. */
														__( 'One entry per placement (%s), each {passed, where}; where is the matching heading or alt text.', 'thinkrank' ),
														implode( ', ', SEOScoreCalculator::PLACEMENTS )
													),
												],
											],
										],
									],
								],
							],
							// Declared so a caller knows the field is there
							// without having to run the tool and read the JSON.
							// `applicable` false carries `reason` and no checks:
							// a builder layout or a shortcode-only page leaves
							// nothing an answer engine could quote.
							'answer_readiness' => [
								'type'       => 'object',
								'properties' => [
									'applicable' => [ 'type' => 'boolean' ],
									'reason'     => [
										'type'        => 'string',
										'description' => __( 'Why the page was not graded. Empty when it was.', 'thinkrank' ),
									],
									'passed'     => [ 'type' => 'integer' ],
									'total'      => [ 'type' => 'integer' ],
									'checks'     => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'id'     => [
													'type' => 'string',
													'enum' => [
														'direct_answer',
														'question_heading',
														'structured',
														'depth',
														'freshness',
													],
												],
												'label'  => [ 'type' => 'string' ],
												'passed' => [ 'type' => 'boolean' ],
												'detail' => [
													'type'        => 'string',
													'description' => __( 'What was measured. Present whether the check passed or failed.', 'thinkrank' ),
												],
												'fix'    => [
													'type'        => 'string',
													'description' => __( 'What to change. Only meaningful when passed is false.', 'thinkrank' ),
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
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? array_values( array_unique( array_map( 'absint', $input['post_ids'] ) ) ) : [];

		// Enforced here as well as in the schema: a caller that reaches
		// execute() without the Abilities API's validation must not get an
		// unbounded run either. Refused rather than truncated, so the caller
		// learns that the rest were not analysed.
		if ( count( $post_ids ) > self::MAX_POSTS ) {
			return new \WP_Error(
				'thinkrank_post_seo_checks_too_many',
				sprintf(
					/* translators: %d: maximum number of posts. */
					__( 'Too many posts: pass at most %d post_ids per call.', 'thinkrank' ),
					self::MAX_POSTS
				),
				[ 'status' => 400 ]
			);
		}

		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) {
					return $post_id > 0 && get_post( $post_id ) instanceof \WP_Post;
				}
			)
		);

		if ( empty( $post_ids ) ) {
			return new \WP_Error(
				'thinkrank_missing_post_ids',
				__( 'At least one valid post ID is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$calculator = new SEOScoreCalculator( new Database() );
		$metabox    = new Metabox_Manager();
		$data       = [];

		foreach ( $post_ids as $post_id ) {
			try {
				$content = $calculator->analyze_post_content( $post_id );

				// Override the raw per-post title/description with their effective
				// values (custom value, else the resolved Global pattern) so an
				// inherited title or description is scored as present — matching
				// the editor and frontend — instead of counting as missing.
				$metadata                = $metabox->get_post_metadata( $post_id );
				$metadata['title']       = Pattern_Resolver::effective_title( $post_id );
				$metadata['description'] = Pattern_Resolver::effective_description( $post_id );

				$score = $calculator->calculate_score( $content, $metadata );

				$data[] = [
					'post_id'          => $post_id,
					'score'            => $score,
					// Answer-engine readiness (#677), beside the score rather
					// than inside it: the thirteen scoring factors sum to 100
					// and adding a fourteenth would re-score every post on the
					// site. Same predicates as the site audit's GEO checks and
					// as the editor panel, so all three grade a page alike.
					// Graded on the content the audit samples, resolved from
					// builder meta where the page keeps it there (#1629).
					'answer_readiness' => Answer_Readiness::evaluate(
						Answer_Readiness::post_content( get_post( $post_id ) ),
						Answer_Readiness::modified_time( $post_id )
					),
				];
			} catch ( \Throwable $e ) {
				$data[] = [
					'post_id' => $post_id,
					'error'   => $e->getMessage(),
				];
			}
		}

		return [
			'status'  => 'success',
			'message' => __( 'SEO checks generated.', 'thinkrank' ),
			'data'    => $data,
		];
	}
}
