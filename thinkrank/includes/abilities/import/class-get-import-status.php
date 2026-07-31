<?php
/**
 * Get import status ability.
 *
 * @package ThinkRank\Abilities\Import
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Import;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Admin\Importers\Snapshot_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports the status of captured SEO import snapshots.
 *
 * Reads the snapshot manifests maintained by the import pipeline. Each entry
 * reports the export `status` (`exporting`/`complete`), per-type record and
 * chunk totals, and migration timestamps (`last_migrated`, `migration_version`)
 * so an agent can tell whether a source has been exported and/or migrated.
 */
class Get_Import_Status extends Ability_Base {
	/**
	 * Source plugins that can hold a snapshot.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLUGINS = [ 'yoast', 'rankmath', 'seopress', 'aioseo' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/get-import-status';
		$this->label       = __( 'Get SEO Import Status', 'thinkrank' );
		$this->description = __( 'Report the status of captured SEO import snapshots: export status (exporting/complete), per-type record and chunk totals, and migration timestamps. Optionally scope to a single source plugin.', 'thinkrank' );
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
			'properties'           => [
				'plugin' => [
					'type'        => 'string',
					'description' => __( 'Optional source plugin to scope the status to.', 'thinkrank' ),
					'enum'        => self::ALLOWED_PLUGINS,
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
				'snapshots' => [
					'type'                 => 'object',
					'additionalProperties' => true,
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
		$plugin = isset( $input['plugin'] ) ? sanitize_key( (string) $input['plugin'] ) : '';

		if ( '' !== $plugin && in_array( $plugin, self::ALLOWED_PLUGINS, true ) ) {
			$manifest = Snapshot_Store::get_manifest( $plugin );

			return [
				'snapshots' => null === $manifest ? [] : [ $plugin => $manifest ],
			];
		}

		return [
			'snapshots' => Snapshot_Store::get_available_snapshots(),
		];
	}
}
