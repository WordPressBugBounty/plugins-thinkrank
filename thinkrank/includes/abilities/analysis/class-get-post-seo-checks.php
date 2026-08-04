<?php
/**
 * Get post SEO checks ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
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
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-post-seo-checks';
		$this->label       = __( 'Get ThinkRank Post SEO Checks', 'thinkrank' );
		$this->description = __( 'Run ThinkRank SEO score analysis for one or more posts, pages, or custom post type items.', 'thinkrank' );
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
					'description' => __( 'One or more post IDs to inspect.', 'thinkrank' ),
					'items'       => [
						'type' => 'integer',
					],
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
				'data'    => [ 'type' => 'array' ],
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
		$post_ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? array_map( 'absint', $input['post_ids'] ) : [];
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
					'post_id' => $post_id,
					'score'   => $score,
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
