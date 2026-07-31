<?php
/**
 * Get llms.txt status ability.
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
 * Reports the current status of the published llms.txt file.
 */
class Get_Llms_Txt_Status extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-llms-txt-status';
		$this->label       = __( 'Get ThinkRank llms.txt Status', 'thinkrank' );
		$this->description = __( 'Report the current status of the published llms.txt file, including existence, path, URL, writability, last modified time, size, and a content preview.', 'thinkrank' );
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
			'properties'           => [],
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
				'file_exists'     => [ 'type' => 'boolean' ],
				'file_path'       => [ 'type' => 'string' ],
				'file_url'        => [ 'type' => 'string' ],
				'writable'        => [ 'type' => 'boolean' ],
				'last_modified'   => [ 'type' => [ 'string', 'integer', 'null' ] ],
				'file_size'       => [ 'type' => [ 'integer', 'null' ] ],
				'content_preview' => [ 'type' => 'string' ],
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
		$manager = new LLMs_Txt_Manager();

		return $manager->get_llms_txt_status( false );
	}
}
