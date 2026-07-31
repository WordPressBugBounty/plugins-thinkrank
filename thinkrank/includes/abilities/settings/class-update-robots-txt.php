<?php
/**
 * Update robots.txt ability.
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
 * Updates the custom robots.txt content managed by ThinkRank.
 */
class Update_Robots_Txt extends Ability_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/update-robots-txt';
		$this->label       = __( 'Update ThinkRank Robots.txt', 'thinkrank' );
		$this->description = __( 'Replace the robots.txt served to crawlers with custom content. This overrides ThinkRank\'s auto-generated rules entirely, so fetch get-robots-txt first and edit its "rendered" output to avoid dropping the default wp-admin/sitemap directives. Pass an empty string to revert to the auto-generated defaults.', 'thinkrank' );
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
				'content' => [
					'type'        => 'string',
					'description' => __( 'The full robots.txt content to serve. Replaces the generated defaults entirely; seed it from get-robots-txt\'s "rendered" field so default directives are preserved. Pass an empty string to restore auto-generation.', 'thinkrank' ),
				],
			],
			'required'             => [ 'content' ],
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
				'success'  => [ 'type' => 'boolean' ],
				'content'  => [ 'type' => 'string' ],
				'rendered' => [
					'type'        => 'string',
					'description' => __( 'The effective robots.txt now served to crawlers after this update.', 'thinkrank' ),
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
		$content = isset( $input['content'] ) ? sanitize_textarea_field( (string) $input['content'] ) : '';

		$mgr     = new Site_Identity_Manager();
		$updated = $mgr->get_settings( 'site', null );

		$updated['robots_txt_content'] = $content;
		$updated['robots_txt_enabled'] = true;

		$result = $mgr->save_settings( 'site', null, $updated );

		// Keep the physical robots.txt in step with the saved override so the
		// change is served immediately, matching a Save from the admin screen.
		$mgr->sync_robots_txt_file();

		return [
			'success'  => (bool) $result,
			'content'  => $content,
			'rendered' => (string) $mgr->get_effective_robots_txt()['content'],
		];
	}
}
