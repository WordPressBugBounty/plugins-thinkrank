<?php
/**
 * Run SEO import ability.
 *
 * @package ThinkRank\Abilities\Import
 */

declare(strict_types=1);

namespace ThinkRank\Abilities\Import;

use ThinkRank\Abilities\Ability_Base;
use ThinkRank\Admin\Importers\AIOSEO_Exporter;
use ThinkRank\Admin\Importers\Rankmath_Exporter;
use ThinkRank\Admin\Importers\SEOPress_Exporter;
use ThinkRank\Admin\Importers\Snapshot_Migrator;
use ThinkRank\Admin\Importers\Yoast_Exporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs a full SEO data import from another plugin into ThinkRank.
 *
 * Composes the two-phase pipeline the REST controller exposes as separate
 * endpoints: it exports the chosen source (Yoast/RankMath/SEOPress/AIOSEO) into
 * a snapshot, then migrates that snapshot into ThinkRank's `_thinkrank_*`
 * metadata. Existing ThinkRank values are never overwritten. Source-plugin data
 * is left intact (no cleanup); reports aggregate counters, not per-item rows.
 */
class Run_Seo_Import extends Ability_Base {
	/**
	 * Source plugins that can be imported.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLUGINS = [ 'yoast', 'rankmath', 'seopress', 'aioseo' ];

	/**
	 * Data types processed, in pipeline order.
	 *
	 * @var string[]
	 */
	private const TYPES = [ 'postmeta', 'termmeta', 'usermeta', 'redirections', 'settings' ];

	/**
	 * Safety cap on chunk iterations per type (avoids runaway loops).
	 */
	private const MAX_PAGES = 1000;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/run-seo-import';
		$this->label       = __( 'Run SEO Data Import', 'thinkrank' );
		$this->description = __( 'Import SEO metadata from another plugin (Yoast, RankMath, SEOPress, or AIOSEO) into ThinkRank. Exports the source to a snapshot then migrates it; existing ThinkRank values are never overwritten and source data is left intact. Returns aggregate export/migration counters.', 'thinkrank' );
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
					'description' => __( 'The source SEO plugin to import from.', 'thinkrank' ),
					'enum'        => self::ALLOWED_PLUGINS,
				],
			],
			'required'             => [ 'plugin' ],
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
				'plugin'   => [ 'type' => 'string' ],
				'exported' => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'migrated' => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'errors'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
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
		$plugin = isset( $input['plugin'] ) ? sanitize_key( (string) $input['plugin'] ) : '';

		if ( ! in_array( $plugin, self::ALLOWED_PLUGINS, true ) ) {
			return new \WP_Error(
				'thinkrank_invalid_import_plugin',
				__( 'A supported source plugin is required (yoast, rankmath, seopress, aioseo).', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$exporter = $this->get_exporter( $plugin );

		if ( null === $exporter ) {
			return new \WP_Error(
				'thinkrank_invalid_import_plugin',
				__( 'Could not resolve an exporter for the selected plugin.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		try {
			$exported = $this->run_export( $exporter );
			$migrated = $this->run_migration( $plugin );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'thinkrank_import_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return [
			'success'  => empty( $migrated['errors'] ),
			'plugin'   => $plugin,
			'exported' => $exported,
			'migrated' => [
				'processed'       => $migrated['processed'],
				'skipped'         => $migrated['skipped'],
				'analyzed'        => $migrated['analyzed'],
				'keywords_seeded' => $migrated['keywords_seeded'],
			],
			'errors'   => $migrated['errors'],
		];
	}

	/**
	 * Resolve the exporter for a source plugin.
	 *
	 * @param string $plugin Source plugin slug.
	 * @return object|null Exporter instance or null when unknown.
	 */
	private function get_exporter( $plugin ) {
		switch ( $plugin ) {
			case 'yoast':
				return new Yoast_Exporter();
			case 'rankmath':
				return new Rankmath_Exporter();
			case 'seopress':
				return new SEOPress_Exporter();
			case 'aioseo':
				return new AIOSEO_Exporter();
			default:
				return null;
		}
	}

	/**
	 * Export every data type into a snapshot and finalize it.
	 *
	 * @param object $exporter Source-plugin exporter.
	 * @return array<string, int> Per-type exported counts.
	 */
	private function run_export( $exporter ) {
		$exported = [];

		foreach ( self::TYPES as $type ) {
			$count = 0;
			$page  = 1;

			do {
				$result   = $exporter->export_chunk( $type, $page );
				$count   += is_array( $result ) ? (int) ( $result['exported'] ?? 0 ) : 0;
				$has_more = is_array( $result ) && ! empty( $result['has_more'] );
				++$page;
			} while ( $has_more && $page <= self::MAX_PAGES );

			$exported[ $type ] = $count;
		}

		// Flip the manifest status to "complete" so migration is allowed to run.
		$exporter->finalize_export();

		return $exported;
	}

	/**
	 * Migrate the snapshot into ThinkRank metadata.
	 *
	 * @param string $plugin Source plugin slug.
	 * @return array<string, mixed> Aggregate counters and any errors.
	 */
	private function run_migration( $plugin ) {
		$migrator = new Snapshot_Migrator();
		$totals   = [
			'processed'       => 0,
			'skipped'         => 0,
			'analyzed'        => 0,
			'keywords_seeded' => 0,
			'errors'          => [],
		];

		foreach ( self::TYPES as $type ) {
			$page = 1;

			do {
				$result = $migrator->migrate_chunk( $plugin, $type, $page );

				if ( ! is_array( $result ) ) {
					break;
				}

				if ( 'error' === ( $result['status'] ?? '' ) ) {
					$totals['errors'][] = (string) ( $result['message'] ?? 'Unknown migration error.' );
					break;
				}

				$totals['processed']       += (int) ( $result['processed'] ?? 0 );
				$totals['skipped']         += (int) ( $result['skipped'] ?? 0 );
				$totals['analyzed']        += (int) ( $result['analyzed'] ?? 0 );
				$totals['keywords_seeded'] += (int) ( $result['keywords_seeded'] ?? 0 );

				$has_more = ! empty( $result['has_more'] );
				++$page;
			} while ( $has_more && $page <= self::MAX_PAGES );
		}

		$migrator->update_manifest_migration_info( $plugin );

		return $totals;
	}
}
