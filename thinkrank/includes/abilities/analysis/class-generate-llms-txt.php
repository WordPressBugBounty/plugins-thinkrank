<?php
/**
 * Generate llms.txt preview ability.
 *
 * @package ThinkRank\Abilities\Analysis
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Analysis;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\LLMs_Txt_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Generates a preview of the llms.txt content without publishing it.
 *
 * This is a pure preview: it never writes the file to disk. Pass an empty
 * user_input object to preview using the currently saved settings.
 */
class Generate_Llms_Txt extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/generate-llms-txt';
		$this->label       = __( 'Generate ThinkRank llms.txt Preview', 'thinkrank' );
		$this->description = __( 'Generate a preview of the llms.txt content without publishing it to disk. Pass an empty user_input object to preview using the currently saved settings.', 'thinkrank' );
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
				'user_input' => [
					'type'        => 'object',
					'description' => __( 'Optional override values for the preview. When empty, saved settings are used.', 'thinkrank' ),
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
				'content'    => [ 'type' => 'string' ],
				'sections'   => [ 'type' => [ 'array', 'object' ] ],
				'metadata'   => [ 'type' => 'object' ],
				'validation' => [ 'type' => [ 'array', 'object' ] ],
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
		$user_input = isset( $input['user_input'] ) && is_array( $input['user_input'] ) ? $input['user_input'] : [];

		$manager = new LLMs_Txt_Manager();

		return $manager->generate_llms_txt( $user_input, [] );
	}
}
