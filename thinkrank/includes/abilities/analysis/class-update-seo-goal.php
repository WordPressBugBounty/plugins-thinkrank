<?php
/**
 * Update SEO goal ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Goals\Goal_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Marks an SEO goal done or ignored, or restores it (#728).
 *
 * Mirrors `POST /thinkrank/v1/goals/mark`.
 */
class Update_Seo_Goal extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-seo-goal';
		$this->label       = __( 'Update ThinkRank SEO Goal', 'thinkrank' );
		$this->description = __( 'Mark one SEO goal from get-seo-goals as done, ignore it, or restore it (open). Done hides the goal while it stays the size it was; if it grows (more pages affected) or goes away and comes back, it shows again. Ignored hides it until restored. Fixing the underlying problem removes a goal on its own - marking is for goals the owner has handled some other way or does not want. Returns the goal list as it now stands.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Not destructive: a mark is undone by setting the goal open again.
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
			'required'             => [ 'id', 'status' ],
			'properties'           => [
				'id'     => [
					'type'        => 'string',
					'description' => __( 'Goal id from get-seo-goals.', 'thinkrank' ),
				],
				'status' => [
					'type' => 'string',
					'enum' => Goal_Registry::STATUSES,
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
		return ( new Get_Seo_Goals() )->get_output_schema();
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$registry = new Goal_Registry();
		$marked   = $registry->mark( (string) ( $input['id'] ?? '' ), (string) ( $input['status'] ?? '' ) );

		if ( is_wp_error( $marked ) ) {
			return $marked;
		}

		return $registry->feed();
	}
}
