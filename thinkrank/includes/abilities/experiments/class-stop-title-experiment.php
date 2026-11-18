<?php
/**
 * Stop title experiment ability.
 *
 * @package ThinkRank\Abilities\Experiments
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Experiments;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\API\Title_Experiments_Endpoint;
use ThinkRank\SEO\Experiments\Title_Experiments;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ends a running Title Experiment early (#719).
 */
class Stop_Title_Experiment extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/stop-title-experiment';
		$this->label       = __( 'Stop ThinkRank Title Experiment', 'thinkrank' );
		$this->description = __( 'End the running title experiment on one page. The page serves its original SEO title and description again - exactly, since they were never changed - and the SEO fields unlock for update-post-seo. No result is recorded. Find running experiments with get-title-experiments.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not destructive: the original is what the page served before, and was
	 * never changed.
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 2.0,
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
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		return Get_Title_Experiments::experiment_schema();
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'thinkrank_cannot_edit_post', __( 'You are not allowed to edit this post.', 'thinkrank' ), [ 'status' => 403 ] );
		}

		$stopped = ( new Title_Experiments() )->stop( $post_id );

		return is_wp_error( $stopped ) ? $stopped : ( new Title_Experiments_Endpoint() )->view( $stopped );
	}
}
