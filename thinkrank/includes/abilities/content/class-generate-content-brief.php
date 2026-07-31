<?php
/**
 * Generate content brief ability.
 *
 * @package ThinkRank\Abilities\Content
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Content;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\AI\Content_Brief_Generator;
use ThinkRank\AI\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Generates an AI SEO content brief for a set of target keywords.
 *
 * Mirrors `POST /thinkrank/v1/content-brief/generate`: given target keywords
 * (and optional content type, audience, length, tone, competitor URLs, and
 * extra context) it produces a structured brief — title/meta suggestions,
 * outline, keyword analysis, schema and social recommendations, and more.
 *
 * Requires a configured AI provider API key. This calls an external AI service,
 * consumes tokens, persists the brief, and is rate limited per user.
 */
class Generate_Content_Brief extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/generate-content-brief';
		$this->label       = __( 'Generate Content Brief', 'thinkrank' );
		$this->description = __( 'Generate an AI SEO content brief for the given target keywords: title and meta suggestions, an outline, keyword analysis, and schema/social recommendations. Requires a configured AI provider API key; calls an external AI service and consumes tokens.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
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
				'target_keywords'    => [
					'type'        => 'array',
					'description' => __( 'One or more target keywords for the brief.', 'thinkrank' ),
					'minItems'    => 1,
					'items'       => [
						'type'      => 'string',
						'minLength' => 1,
					],
				],
				'content_type'       => [
					'type'        => 'string',
					'description' => __( 'The type of content to brief.', 'thinkrank' ),
					'enum'        => [ 'blog_post', 'product_page', 'landing_page', 'tutorial' ],
					'default'     => 'blog_post',
				],
				'target_audience'    => [
					'type'        => 'string',
					'description' => __( 'The intended audience.', 'thinkrank' ),
					'enum'        => [ 'beginners', 'professionals', 'general', 'experts' ],
					'default'     => 'general',
				],
				'content_length'     => [
					'type'        => 'string',
					'description' => __( 'The target content length.', 'thinkrank' ),
					'enum'        => [ 'short', 'medium', 'long' ],
					'default'     => 'medium',
				],
				'tone'               => [
					'type'        => 'string',
					'description' => __( 'The writing tone.', 'thinkrank' ),
					'enum'        => [ 'professional', 'casual', 'technical', 'friendly' ],
					'default'     => 'professional',
				],
				'competitor_urls'    => [
					'type'        => 'array',
					'description' => __( 'Optional competitor URLs to analyze for content gaps.', 'thinkrank' ),
					'default'     => [],
					'items'       => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
				'additional_context' => [
					'type'        => 'string',
					'description' => __( 'Optional additional context for the brief.', 'thinkrank' ),
					'default'     => '',
				],
			],
			'required'             => [ 'target_keywords' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$keywords = isset( $input['target_keywords'] ) && is_array( $input['target_keywords'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $input['target_keywords'] ) ) )
			: [];

		if ( empty( $keywords ) ) {
			return new \WP_Error(
				'thinkrank_invalid_keywords',
				__( 'At least one target keyword is required.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$competitor_urls = isset( $input['competitor_urls'] ) && is_array( $input['competitor_urls'] )
			? array_values( array_filter( array_map( 'esc_url_raw', $input['competitor_urls'] ) ) )
			: [];

		$params = [
			'target_keywords'    => $keywords,
			'content_type'       => isset( $input['content_type'] ) ? (string) $input['content_type'] : 'blog_post',
			'target_audience'    => isset( $input['target_audience'] ) ? (string) $input['target_audience'] : 'general',
			'content_length'     => isset( $input['content_length'] ) ? (string) $input['content_length'] : 'medium',
			'tone'               => isset( $input['tone'] ) ? (string) $input['tone'] : 'professional',
			'competitor_urls'    => $competitor_urls,
			'additional_context' => isset( $input['additional_context'] ) ? sanitize_textarea_field( (string) $input['additional_context'] ) : '',
		];

		try {
			$ai_manager = new Manager();
			$ai_client  = $ai_manager->get_client();
			$generator  = new Content_Brief_Generator( null, $ai_client );

			return $generator->generate_brief( $params );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'thinkrank_brief_generation_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}
}
