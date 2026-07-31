<?php
/**
 * Get term SEO checks ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs lightweight ThinkRank-native on-page SEO checks for taxonomy terms.
 *
 * ThinkRank has no dedicated term analysis engine, so this ability performs a
 * small set of basic checks against the term's SEO title, meta description, and
 * description.
 */
class Get_Term_Seo_Checks extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-term-seo-checks';
		$this->label       = __( 'Get ThinkRank Term SEO Checks', 'thinkrank' );
		$this->description = __( 'Run ThinkRank-native basic on-page SEO checks for one or more taxonomy terms (SEO title, meta description, and term description).', 'thinkrank' );
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
				'term_ids' => [
					'type'        => 'array',
					'description' => __( 'One or more term IDs to inspect.', 'thinkrank' ),
					'items'       => [
						'type' => 'integer',
					],
				],
			],
			'required'             => [ 'term_ids' ],
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
		$term_ids = isset( $input['term_ids'] ) && is_array( $input['term_ids'] ) ? array_map( 'absint', $input['term_ids'] ) : [];
		$term_ids = array_values(
			array_filter(
				$term_ids,
				static function ( $term_id ) {
					return $term_id > 0 && get_term( $term_id ) instanceof \WP_Term;
				}
			)
		);

		if ( empty( $term_ids ) ) {
			return new \WP_Error(
				'thinkrank_missing_term_ids',
				__( 'At least one valid term ID is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$data = [];

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$data[] = [
				'term_id'  => $term_id,
				'taxonomy' => (string) $term->taxonomy,
				'checks'   => $this->build_checks( $term ),
			];
		}

		return [
			'status'  => 'success',
			'message' => __( 'Term SEO checks generated.', 'thinkrank' ),
			'data'    => $data,
		];
	}

	/**
	 * Build the on-page checks for a single term.
	 *
	 * @param \WP_Term $term Term object.
	 * @return array<int, array<string, string>>
	 */
	private function build_checks( \WP_Term $term ) {
		$seo_title   = (string) get_term_meta( $term->term_id, '_thinkrank_seo_title', true );
		$description = (string) get_term_meta( $term->term_id, '_thinkrank_meta_description', true );

		$title_length = mb_strlen( $seo_title );
		$desc_length  = mb_strlen( $description );

		$checks = [];

		if ( '' === $seo_title ) {
			$checks[] = [
				'id'      => 'seo_title_present',
				'label'   => __( 'SEO title', 'thinkrank' ),
				'status'  => 'warning',
				'message' => __( 'No SEO title is set for this term.', 'thinkrank' ),
			];
		} else {
			$checks[] = [
				'id'      => 'seo_title_present',
				'label'   => __( 'SEO title', 'thinkrank' ),
				'status'  => 'ok',
				'message' => __( 'An SEO title is set.', 'thinkrank' ),
			];

			if ( $title_length < 30 || $title_length > 60 ) {
				$checks[] = [
					'id'      => 'seo_title_length',
					'label'   => __( 'SEO title length', 'thinkrank' ),
					'status'  => 'warning',
					'message' => __( 'The SEO title should be between 30 and 60 characters.', 'thinkrank' ),
				];
			} else {
				$checks[] = [
					'id'      => 'seo_title_length',
					'label'   => __( 'SEO title length', 'thinkrank' ),
					'status'  => 'ok',
					'message' => __( 'The SEO title length is within the recommended range.', 'thinkrank' ),
				];
			}
		}

		if ( '' === $description ) {
			$checks[] = [
				'id'      => 'meta_description_present',
				'label'   => __( 'Meta description', 'thinkrank' ),
				'status'  => 'warning',
				'message' => __( 'No meta description is set for this term.', 'thinkrank' ),
			];
		} else {
			$checks[] = [
				'id'      => 'meta_description_present',
				'label'   => __( 'Meta description', 'thinkrank' ),
				'status'  => 'ok',
				'message' => __( 'A meta description is set.', 'thinkrank' ),
			];

			if ( $desc_length < 70 || $desc_length > 160 ) {
				$checks[] = [
					'id'      => 'meta_description_length',
					'label'   => __( 'Meta description length', 'thinkrank' ),
					'status'  => 'warning',
					'message' => __( 'The meta description should be between 70 and 160 characters.', 'thinkrank' ),
				];
			} else {
				$checks[] = [
					'id'      => 'meta_description_length',
					'label'   => __( 'Meta description length', 'thinkrank' ),
					'status'  => 'ok',
					'message' => __( 'The meta description length is within the recommended range.', 'thinkrank' ),
				];
			}
		}

		$checks[] = '' === trim( (string) $term->description )
			? [
				'id'      => 'term_description_present',
				'label'   => __( 'Term description', 'thinkrank' ),
				'status'  => 'warning',
				'message' => __( 'This term has no description.', 'thinkrank' ),
			]
			: [
				'id'      => 'term_description_present',
				'label'   => __( 'Term description', 'thinkrank' ),
				'status'  => 'ok',
				'message' => __( 'This term has a description.', 'thinkrank' ),
			];

		return $checks;
	}
}
