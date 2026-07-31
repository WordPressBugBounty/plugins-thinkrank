<?php
/**
 * Get robots.txt ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Site_Identity_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves the custom robots.txt content managed by ThinkRank.
 */
class Get_Robots_Txt extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-robots-txt';
		$this->label       = __( 'Get ThinkRank Robots.txt', 'thinkrank' );
		$this->description = __( 'Retrieve the ThinkRank robots.txt: both the stored custom override (may be empty) and the "rendered" content actually served to crawlers, which includes the auto-generated default rules when no override is set.', 'thinkrank' );
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
				'content'    => [
					'type'        => 'string',
					'description' => __( 'The stored custom override. Empty when ThinkRank is auto-generating robots.txt.', 'thinkrank' ),
				],
				'enabled'    => [ 'type' => 'boolean' ],
				'rendered'   => [
					'type'        => 'string',
					'description' => __( 'The effective robots.txt actually served to crawlers (custom override, or the generated defaults when none is set).', 'thinkrank' ),
				],
				'is_default' => [
					'type'        => 'boolean',
					'description' => __( 'True when the served content is ThinkRank\'s auto-generated defaults rather than a custom override.', 'thinkrank' ),
				],
				'source'     => [
					'type'        => 'string',
					'description' => __( 'Origin of the served content: "file" (physical robots.txt in the web root), "custom" (stored override), "generated" (auto-generated defaults), or "wordpress" (management disabled).', 'thinkrank' ),
				],
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
		$mgr       = new Site_Identity_Manager();
		$settings  = $mgr->get_settings( 'site', null );
		$effective = $mgr->get_effective_robots_txt();

		return [
			'content'    => (string) ( $settings['robots_txt_content'] ?? '' ),
			'enabled'    => (bool) ( $settings['robots_txt_enabled'] ?? true ),
			'rendered'   => (string) $effective['content'],
			'is_default' => (bool) $effective['is_default'],
			'source'     => (string) $effective['source'],
		];
	}
}
