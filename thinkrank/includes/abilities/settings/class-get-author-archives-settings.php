<?php
/**
 * Get author archives settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank author archives settings.
 *
 * Controls whether author archives are enabled and indexable, whether empty
 * archives are shown, and the SEO title/meta description templates.
 */
class Get_Author_Archives_Settings extends Ability_Base {
	/**
	 * Boolean settings: API alias => [ storage_key, default ].
	 */
	private const BOOL_MAP = [
		'enabled'                => [ 'author_archives_enabled', true ],
		'show_in_search_results' => [ 'author_archives_index', true ],
		'show_empty_archives'    => [ 'author_archives_show_empty', false ],
	];

	/**
	 * String settings: API alias => [ storage_key, default ].
	 */
	private const STRING_MAP = [
		'title'            => [ 'author_archives_title', '%author_name% – %site_title% %page%' ],
		'meta_description' => [ 'author_archives_meta_desc', 'Articles written by %author_name% on %site_title%' ],
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-author-archives-settings';
		$this->label       = __( 'Get ThinkRank Author Archives Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank author archives settings: enable/index toggles, empty-archive visibility, and the SEO title/meta description templates.', 'thinkrank' );
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
				'enabled'                => [ 'type' => 'boolean' ],
				'show_in_search_results' => [ 'type' => 'boolean' ],
				'show_empty_archives'    => [ 'type' => 'boolean' ],
				'title'                  => [ 'type' => 'string' ],
				'meta_description'       => [ 'type' => 'string' ],
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
		$settings = Settings::instance();
		$out      = [];

		foreach ( self::BOOL_MAP as $alias => $config ) {
			$out[ $alias ] = (bool) $settings->get( $config[0], $config[1] );
		}
		foreach ( self::STRING_MAP as $alias => $config ) {
			$out[ $alias ] = (string) $settings->get( $config[0], $config[1] );
		}

		return $out;
	}
}
