<?php
/**
 * Submit URLs to index ability.
 *
 * @package ThinkRank\Abilities\Indexing
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Indexing;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Instant_Indexing_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Submits URLs to search engines via the IndexNow protocol.
 *
 * Requires instant indexing to be configured (an API key must exist); otherwise
 * the manager responds with "API Key missing". This calls the external IndexNow
 * API.
 */
class Submit_Urls_To_Index extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/submit-urls-to-index';
		$this->label       = __( 'Submit URLs to Instant Indexing', 'thinkrank' );
		$this->description = __( 'Submit one or more URLs to search engines via the IndexNow protocol. Requires instant indexing to be configured (an API key must exist) or it returns "API Key missing". Calls the external IndexNow API.', 'thinkrank' );
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
			'idempotent'    => false,
			'priority'      => 2.0,
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
				'urls' => [
					'type'        => 'array',
					'description' => __( 'The URLs to submit for indexing (same-site host only).', 'thinkrank' ),
					'minItems'    => 1,
					'maxItems'    => \ThinkRank\SEO\Instant_Indexing_Manager::MAX_URLS_PER_SUBMISSION,
					'items'       => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
			],
			'required'             => [ 'urls' ],
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
				'success'         => [ 'type' => 'boolean' ],
				'code'            => [ 'type' => 'integer' ],
				'message'         => [ 'type' => 'string' ],
				'submitted_count' => [ 'type' => 'integer' ],
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
		$urls = isset( $input['urls'] ) && is_array( $input['urls'] ) ? $input['urls'] : [];
		$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );

		if ( empty( $urls ) ) {
			return new \WP_Error(
				'thinkrank_missing_index_urls',
				__( 'At least one valid URL is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$manager = new Instant_Indexing_Manager();
		$result  = $manager->submit_urls( $urls );

		return [
			'success'         => (bool) ( $result['success'] ?? false ),
			'code'            => (int) ( $result['code'] ?? 0 ),
			'message'         => (string) ( $result['message'] ?? '' ),
			// The count the manager actually submitted after same-host filtering
			// and its cap, not the raw input size.
			'submitted_count' => (int) ( $result['submitted_count'] ?? 0 ),
		];
	}
}
