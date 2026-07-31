<?php
/**
 * Publish llms.txt ability.
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
 * Generates the llms.txt content and writes it to the site root.
 *
 * Unlike generate-llms-txt (which is preview-only and never touches disk), this
 * ability persists the generated content to <site root>/llms.txt so it is
 * served publicly. Pass an empty user_input object to publish using the
 * currently saved settings.
 */
class Publish_Llms_Txt extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/publish-llms-txt';
		$this->label       = __( 'Publish ThinkRank llms.txt', 'thinkrank' );
		$this->description = __( 'Generate the llms.txt content and write it to the site root (llms.txt) so it is served publicly. Pass an empty user_input object to publish using the currently saved settings. Unlike generate-llms-txt, this persists the file to disk; afterwards get-llms-txt-status reports file_exists: true.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => true,
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
			'properties'           => [
				'user_input' => [
					'type'        => 'object',
					'description' => __( 'Optional override values. When empty, saved settings are used.', 'thinkrank' ),
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
				'success'     => [ 'type' => 'boolean' ],
				'message'     => [ 'type' => 'string' ],
				'content'     => [ 'type' => 'string' ],
				'validation'  => [ 'type' => [ 'array', 'object' ] ],
				'file_status' => [ 'type' => [ 'array', 'object' ] ],
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
		$user_input = isset( $input['user_input'] ) && is_array( $input['user_input'] ) ? $input['user_input'] : [];

		$manager = new LLMs_Txt_Manager();

		$generated  = $manager->generate_llms_txt( $user_input, [] );
		$validation = isset( $generated['validation'] ) && is_array( $generated['validation'] ) ? $generated['validation'] : [];

		// Do not write an incomplete/invalid file to disk.
		if ( empty( $validation['valid'] ) ) {
			return [
				'success'     => false,
				'message'     => __( 'llms.txt was not published because the settings are incomplete. Save the required fields (Website Description, Key Features, Target Audience) first.', 'thinkrank' ),
				'content'     => '',
				'validation'  => $validation,
				'file_status' => $manager->get_llms_txt_status( true ),
			];
		}

		$content = isset( $generated['content'] ) ? (string) $generated['content'] : '';
		$write   = $manager->write_llms_txt_to_file( $content );

		return [
			'success'     => ! empty( $write['success'] ),
			'message'     => isset( $write['message'] ) ? (string) $write['message'] : '',
			'content'     => $content,
			'validation'  => $validation,
			'file_status' => $manager->get_llms_txt_status( true ),
		];
	}
}
