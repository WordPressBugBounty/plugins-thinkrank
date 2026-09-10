<?php
/**
 * Get external link settings ability.
 *
 * @package ThinkRank\Abilities\Links
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the site-wide outbound-link handling settings.
 *
 * Mirrors `GET /thinkrank/v1/external-links/settings`. The rewrite happens at
 * render time and never touches stored post content, so both switches are
 * reversible — worth knowing before changing them. Read-only.
 */
class Get_External_Links_Settings extends External_Links_Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-external-links-settings';
		$this->label       = __( 'Get ThinkRank External Link Settings', 'thinkrank' );
		$this->description = __( 'Read the site-wide outbound-link settings: whether external links get rel="nofollow", whether they open in a new tab, and the list of hosts excluded from both. The rewrite is applied while the page renders and never modifies stored post content. Use update-external-links-settings to change them.', 'thinkrank' );
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
			'properties'           => self::empty_properties(),
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
			'properties' => $this->settings_properties(),
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		$settings = $this->stored();

		return [
			'nofollow_external'        => (bool) ( $settings['nofollow_external'] ?? false ),
			'open_external_in_new_tab' => (bool) ( $settings['open_external_in_new_tab'] ?? false ),
			'external_link_exceptions' => $settings['external_link_exceptions'],
		];
	}
}
