<?php
/**
 * Get image SEO settings ability.
 *
 * @package ThinkRank\Abilities\Settings
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Settings;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\SEO\Image_SEO_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves ThinkRank image SEO settings.
 *
 * Controls automatic alt/title injection and the token-based formats used when
 * generating those attributes, stored via the Image_SEO_Manager SEO manager.
 */
class Get_Image_Seo_Settings extends Ability_Base {
	/**
	 * Boolean-typed image SEO keys.
	 */
	private const BOOL_KEYS = [
		'add_missing_alt',
		'add_missing_title',
		'save_alt_to_media',
		'auto_fill_on_upload',
		'media_alt_overwrite',
	];

	/**
	 * String-typed image SEO keys.
	 */
	private const STRING_KEYS = [
		'alt_format',
		'title_format',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-image-seo-settings';
		$this->label       = __( 'Get ThinkRank Image SEO Settings', 'thinkrank' );
		$this->description = __( 'Retrieve ThinkRank image SEO settings, which control automatic alt/title injection, the token-based formats used to generate them, and whether generated alt text is persisted to the Media Library.', 'thinkrank' );
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
				'settings' => [
					'type'       => 'object',
					'properties' => [
						'add_missing_alt'     => [ 'type' => 'boolean' ],
						'alt_format'          => [ 'type' => 'string' ],
						'add_missing_title'   => [ 'type' => 'boolean' ],
						'title_format'        => [ 'type' => 'string' ],
						'save_alt_to_media'   => [ 'type' => 'boolean' ],
						'auto_fill_on_upload' => [ 'type' => 'boolean' ],
						'media_alt_overwrite' => [ 'type' => 'boolean' ],
					],
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
		$mgr = new Image_SEO_Manager();
		$s   = $mgr->get_settings( 'site', null );

		$out = [];
		foreach ( self::BOOL_KEYS as $key ) {
			$out[ $key ] = (bool) ( $s[ $key ] ?? false );
		}
		foreach ( self::STRING_KEYS as $key ) {
			$out[ $key ] = (string) ( $s[ $key ] ?? '' );
		}

		return [ 'settings' => $out ];
	}
}
