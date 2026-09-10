<?php
/**
 * Shared base for the external link abilities.
 *
 * @package ThinkRank\Abilities\Links
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Links;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\External_Links_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Common manager access and schema fragments for the external link abilities.
 *
 * Mirrors {@see \ThinkRank\API\External_Links_Endpoint}, which owns the
 * site-wide `nofollow_external`, `open_external_in_new_tab` and
 * `external_link_exceptions` settings.
 */
abstract class External_Links_Ability_Base extends Ability_Base {

	/**
	 * Settings manager.
	 *
	 * @var External_Links_Manager|null
	 */
	private ?External_Links_Manager $manager = null;

	/**
	 * The settings manager, built once per ability instance.
	 *
	 * @return External_Links_Manager
	 */
	protected function manager(): External_Links_Manager {
		if ( null === $this->manager ) {
			$this->manager = new External_Links_Manager();
		}

		return $this->manager;
	}

	/**
	 * The stored settings, with the exception list re-indexed.
	 *
	 * @return array<string, mixed>
	 */
	protected function stored(): array {
		$settings = $this->manager()->get_settings( 'site' );

		$settings['external_link_exceptions'] = array_values(
			(array) ( $settings['external_link_exceptions'] ?? [] )
		);

		return $settings;
	}

	/**
	 * Schema for the three settings.
	 *
	 * @return array<string, mixed>
	 */
	protected function settings_properties(): array {
		return [
			'nofollow_external'        => [
				'type'        => 'boolean',
				'description' => __( 'Add rel="nofollow" to every link pointing at another domain.', 'thinkrank' ),
			],
			'open_external_in_new_tab' => [
				'type'        => 'boolean',
				'description' => __( 'Add target="_blank" to external links, along with the rel="noopener" it requires.', 'thinkrank' ),
			],
			'external_link_exceptions' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => __( 'Hosts neither switch touches. A full URL or a "www." prefix is accepted and reduced to the host it matches on, so read the value back rather than assuming what was stored. Subdomains of a listed host are included.', 'thinkrank' ),
			],
		];
	}
}
