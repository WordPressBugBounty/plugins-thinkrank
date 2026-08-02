<?php
/**
 * Preview SEO import (dry-run) ability.
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
 * Dry-run an SEO import and report what it WOULD do, writing nothing to
 * ThinkRank (see #190).
 *
 * It exports the chosen source (Yoast/RankMath/SEOPress/AIOSEO) into a snapshot
 * — the same non-destructive read phase the real import uses — then classifies
 * every object-meta record without migrating: how many would be written, how
 * many conflict with an existing ThinkRank value that a migrate would not
 * overwrite, and how many reference posts/terms/users that no longer exist.
 * Run this before `run-seo-import` to see the impact up front.
 */
class Preview_Seo_Import extends Ability_Base {
	/**
	 * Source plugins that can be previewed.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLUGINS = [ 'yoast', 'rankmath', 'seopress', 'aioseo' ];

	/**
	 * Data types exported before previewing, in pipeline order.
	 *
	 * @var string[]
	 */
	private const EXPORT_TYPES = [ 'postmeta', 'termmeta', 'usermeta', 'redirections', 'settings' ];

	/**
	 * Object-meta types the dry-run classifies (settings/redirections/404 logs
	 * are migrated wholesale and aren't previewed per-record).
	 *
	 * @var string[]
	 */
	private const PREVIEW_TYPES = [ 'postmeta', 'termmeta', 'usermeta' ];

	/**
	 * Safety cap on chunk iterations per type.
	 */
	private const MAX_PAGES = 1000;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'thinkrank/preview-seo-import';
		$this->label       = __( 'Preview SEO Import (Dry Run)', 'thinkrank' );
		$this->description = __( 'Dry-run an SEO import from another plugin (Yoast, RankMath, SEOPress, or AIOSEO) and report what it would do without writing anything to ThinkRank: counts of records that would be written, records that conflict with existing ThinkRank values (which are never overwritten), and records whose post/term/user no longer exists. Returns totals plus small samples. Run before run-seo-import.', 'thinkrank' );
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
			'idempotent'    => true,
			'priority'      => 1.5,
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
					'description' => __( 'The source SEO plugin to preview an import from.', 'thinkrank' ),
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
				'plugin'      => [ 'type' => 'string' ],
				'records'     => [ 'type' => 'integer' ],
				'would_write' => [ 'type' => 'integer' ],
				'conflicts'   => [ 'type' => 'integer' ],
				'unmatched'   => [ 'type' => 'integer' ],
				'skipped'     => [ 'type' => 'integer' ],
				'by_type'     => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'samples'     => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'errors'      => [
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
			$this->run_export( $exporter );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'thinkrank_import_preview_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return $this->run_preview( $plugin );
	}

	/**
	 * Export every data type into a fresh snapshot and finalize it (the
	 * non-destructive read phase). Writes nothing to ThinkRank.
	 *
	 * @param object $exporter Source-plugin exporter.
	 * @return void
	 */
	private function run_export( $exporter ) {
		foreach ( self::EXPORT_TYPES as $type ) {
			$page = 1;
			do {
				$result   = $exporter->export_chunk( $type, $page );
				$has_more = is_array( $result ) && ! empty( $result['has_more'] );
				++$page;
			} while ( $has_more && $page <= self::MAX_PAGES );
		}

		$exporter->finalize_export();
	}

	/**
	 * Dry-run the snapshot and aggregate the per-type preview counts.
	 *
	 * @param string $plugin Source plugin slug.
	 * @return array<string, mixed>
	 */
	private function run_preview( $plugin ) {
		$migrator = new Snapshot_Migrator();

		$totals  = [
			'records'     => 0,
			'would_write' => 0,
			'conflicts'   => 0,
			'unmatched'   => 0,
			'skipped'     => 0,
		];
		$by_type = [];
		$samples = [ 'would_write' => [], 'conflicts' => [], 'unmatched' => [] ];
		$errors  = [];

		foreach ( self::PREVIEW_TYPES as $type ) {
			$type_totals = [
				'records'     => 0,
				'would_write' => 0,
				'conflicts'   => 0,
				'unmatched'   => 0,
				'skipped'     => 0,
			];

			$page = 1;
			do {
				$preview = $migrator->preview_chunk( $plugin, $type, $page );

				if ( ! empty( $preview['error'] ) ) {
					$errors[] = (string) $preview['error'];
					break;
				}

				foreach ( array_keys( $type_totals ) as $key ) {
					$type_totals[ $key ] += (int) ( $preview[ $key ] ?? 0 );
				}

				foreach ( [ 'would_write', 'conflicts', 'unmatched' ] as $bucket ) {
					foreach ( (array) ( $preview['samples'][ $bucket ] ?? [] ) as $sample ) {
						if ( count( $samples[ $bucket ] ) < 10 ) {
							$samples[ $bucket ][] = array_merge( [ 'type' => $type ], $sample );
						}
					}
				}

				$has_more = ! empty( $preview['has_more'] );
				++$page;
			} while ( $has_more && $page <= self::MAX_PAGES );

			$by_type[ $type ] = $type_totals;
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += $type_totals[ $key ];
			}
		}

		return array_merge(
			[ 'plugin' => $plugin ],
			$totals,
			[
				'by_type' => $by_type,
				'samples' => $samples,
				'errors'  => $errors,
			]
		);
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
}
