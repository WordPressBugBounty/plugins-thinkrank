<?php
/**
 * Get topical relevance ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Topical_Relevance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Compares a post's vocabulary against pages the caller names (#567).
 *
 * Deterministic and free: the terms come from reading the named pages, not
 * from asking a model, so the same inputs give the same answer and nothing is
 * spent per run.
 */
class Get_Topical_Relevance extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-topical-relevance';
		$this->label       = __( 'Get ThinkRank Topical Relevance', 'thinkrank' );
		$this->description = __( 'Compare a post against pages that already rank for its topic and report the terms they cover that it does not. Give it the competitor page addresses; it reads them and compares vocabulary, so the answer is the same every time and costs nothing to run - no AI tokens and no search data provider. Reports terms missing from the post entirely, terms it uses far less than the competitors do, and a coverage percentage. Terms are only reported when at least two of the pages use them, so one page\'s house style is not mistaken for the topic. English-style word languages only: the comparison is refused where WordPress counts characters rather than words. Use generate-content-brief when the need is an outline to write from rather than a gap list for a draft that exists.', 'thinkrank' );
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
			// Two runs can differ because the competitor pages changed, not
			// because this did. Saying otherwise invites a client to cache it.
			'idempotent'    => false,
			'priority'      => 1.0,
			// It fetches pages named by the caller.
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
			'required'             => [ 'post_id', 'urls' ],
			'properties'           => [
				'post_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The post to analyse. Use list-content-items to find one.', 'thinkrank' ),
				],
				'urls'    => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => Topical_Relevance::MAX_URLS,
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Competitor page addresses to compare against, up to five. These are fetched, so they must be public pages; anything on a private or internal address is refused.', 'thinkrank' ),
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
		$term = [
			'type'       => 'object',
			'properties' => [
				'term'       => [ 'type' => 'string' ],
				'documents'  => [
					'type'        => 'integer',
					'description' => __( 'Competitor pages using this term.', 'thinkrank' ),
				],
				'of'         => [
					'type'        => 'integer',
					'description' => __( 'Competitor pages read.', 'thinkrank' ),
				],
				'their_rate' => [
					'type'        => 'number',
					'description' => __( 'Average uses per thousand words across the pages read.', 'thinkrank' ),
				],
				'your_rate'  => [
					'type'        => 'number',
					'description' => __( 'Uses per thousand words in this post.', 'thinkrank' ),
				],
				'your_count' => [ 'type' => 'integer' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'read'       => [
					'type'        => 'integer',
					'description' => __( 'Competitor pages successfully read. Check `sources` when this is lower than the number given: a page that refused the request is not a page with nothing to say.', 'thinkrank' ),
				],
				'sources'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'url'     => [ 'type' => 'string' ],
							'fetched' => [ 'type' => 'boolean' ],
							'reason'  => [ 'type' => 'string' ],
							'words'   => [ 'type' => 'integer' ],
						],
					],
				],
				'your_words' => [
					'type'        => 'integer',
					'description' => __( 'Comparable words in this post: the total after stop words, numbers and two-letter fragments are removed. Lower than the post\'s word count, and the denominator the rates below are per thousand of.', 'thinkrank' ),
				],
				'terms'      => [
					'type'        => 'integer',
					'description' => __( 'Topic terms found across the pages read.', 'thinkrank' ),
				],
				'coverage'   => [
					'type'        => 'integer',
					'description' => __( 'Percentage of those terms the post already uses at a comparable rate.', 'thinkrank' ),
				],
				'missing'    => [
					'type'        => 'array',
					'description' => __( 'Terms the competitors cover and the post does not use at all.', 'thinkrank' ),
					'items'       => $term,
				],
				'underused'  => [
					'type'        => 'array',
					'description' => __( 'Terms the post uses at less than half the competitors\' rate.', 'thinkrank' ),
					'items'       => $term,
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
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'thinkrank_forbidden',
				__( 'You cannot edit that post.', 'thinkrank' ),
				[ 'status' => 403 ]
			);
		}

		$result = Topical_Relevance::analyze( $post_id, (array) ( $input['urls'] ?? [] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		unset( $result['supported'] );

		return $result;
	}
}
