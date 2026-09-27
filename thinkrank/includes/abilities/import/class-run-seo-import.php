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
use ThinkRank\Admin\Importers\Squirrly_Exporter;
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
 *
 * One step is not metadata: `content_blocks` rewrites Rank Math FAQ / HowTo
 * blocks inside `post_content` into ThinkRank blocks (#777). The description
 * says so, and `types` lets a caller leave it out.
 */
class Run_Seo_Import extends Ability_Base {
	/**
	 * Source plugins that can be imported.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLUGINS = [ 'yoast', 'rankmath', 'seopress', 'aioseo', 'squirrly' ];

	/**
	 * Data types processed, in pipeline order.
	 *
	 * @var string[]
	 */
	private const TYPES = [ 'postmeta', 'termmeta', 'usermeta', 'redirections', 'content_blocks', 'settings' ];

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
		$this->description = __( 'Import SEO data from another plugin (Yoast, RankMath, SEOPress, AIOSEO, or Squirrly) into ThinkRank. Exports the source to a snapshot then migrates it; existing ThinkRank values are never overwritten and the source plugin\'s own meta and settings are left intact. The content_blocks type (RankMath only) edits post content: it rewrites RankMath FAQ and HowTo blocks into ThinkRank blocks. Each converted post gets a revision, or a restorable backup where revisions are disabled. Pass types to limit the run, for example to leave content_blocks out. Posts with malformed block markup are left unchanged and listed in errors. Returns aggregate export/migration counters. Run preview-seo-import first to see what would change; get-import-status reports on the snapshot afterwards.', 'thinkrank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Still false, deliberately, although content_blocks edits
			// post_content. `destructive` is for losses that cannot be undone
			// (#675), and this one can: every converted post gets a revision,
			// and where revisions are off Block_Converter keeps the original
			// in post meta for POST /import/content-blocks/restore. A post
			// whose markup cannot be converted safely is not written at all.
			// The description spells the content edit out instead, so the
			// caller can decide, and `types` can leave the step out.
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
				'types'  => [
					'type'        => 'array',
					'description' => __( 'Data types to import. Defaults to all of them. Omit content_blocks to leave post content untouched.', 'thinkrank' ),
					'items'       => [
						'type' => 'string',
						'enum' => self::TYPES,
					],
					'minItems'    => 1,
					'uniqueItems' => true,
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
				'types'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
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
				__( 'A supported source plugin is required (yoast, rankmath, seopress, aioseo, squirrly).', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$types = $this->resolve_types( $input['types'] ?? null );

		if ( is_wp_error( $types ) ) {
			return $types;
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
			$exported = $this->run_export( $exporter, $types );
			$migrated = $this->run_migration( $plugin, $types );
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
			'types'    => $types,
			'exported' => $exported,
			'migrated' => [
				'processed'       => $migrated['processed'],
				'skipped'         => $migrated['skipped'],
				'failed'          => $migrated['failed'],
				'analyzed'        => $migrated['analyzed'],
				'keywords_seeded' => $migrated['keywords_seeded'],
			],
			'errors'   => $migrated['errors'],
		];
	}

	/**
	 * The types to run, in pipeline order.
	 *
	 * Absent means every type, which is what the ability did before `types`
	 * existed. Order always follows TYPES rather than the caller's list, since
	 * the migrator relies on postmeta running before settings.
	 *
	 * @param mixed $requested The `types` input, if any.
	 * @return string[]|\WP_Error
	 */
	private function resolve_types( $requested ) {
		if ( null === $requested ) {
			return self::TYPES;
		}

		if ( ! is_array( $requested ) || empty( $requested ) ) {
			return new \WP_Error(
				'thinkrank_invalid_import_types',
				__( 'types must be a non-empty list of data types.', 'thinkrank' ),
				[ 'status' => 400 ]
			);
		}

		$requested = array_map( 'sanitize_key', array_map( 'strval', $requested ) );
		$unknown   = array_diff( $requested, self::TYPES );

		if ( ! empty( $unknown ) ) {
			return new \WP_Error(
				'thinkrank_invalid_import_types',
				sprintf(
					/* translators: 1: unknown type slugs, 2: allowed type slugs. */
					__( 'Unknown import types: %1$s. Allowed: %2$s.', 'thinkrank' ),
					implode( ', ', $unknown ),
					implode( ', ', self::TYPES )
				),
				[ 'status' => 400 ]
			);
		}

		return array_values( array_intersect( self::TYPES, $requested ) );
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
			case 'squirrly':
				return new Squirrly_Exporter();
			default:
				return null;
		}
	}

	/**
	 * Export every data type into a snapshot and finalize it.
	 *
	 * @param object   $exporter Source-plugin exporter.
	 * @param string[] $types    Types to export, in pipeline order.
	 * @return array<string, int> Per-type exported counts.
	 */
	private function run_export( $exporter, array $types ) {
		$exported = [];

		foreach ( $types as $type ) {
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
	 * @param string   $plugin Source plugin slug.
	 * @param string[] $types  Types to migrate, in pipeline order.
	 * @return array<string, mixed> Aggregate counters and any errors.
	 */
	private function run_migration( $plugin, array $types ) {
		$migrator = new Snapshot_Migrator();
		$totals   = [
			'processed'       => 0,
			'skipped'         => 0,
			'failed'          => 0,
			'analyzed'        => 0,
			'keywords_seeded' => 0,
			'errors'          => [],
		];

		foreach ( $types as $type ) {
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
				$totals['failed']          += (int) ( $result['failed'] ?? 0 );

				// Per-post failures (content_blocks: a post whose block markup
				// could not be converted safely and was left as it was). The
				// chunk itself succeeded, so they are not a chunk error, but
				// the caller needs the post ids to go and fix them.
				foreach ( (array) ( $result['failures'] ?? [] ) as $failure ) {
					$totals['errors'][] = (string) ( $failure['message'] ?? '' );
				}

				$has_more = ! empty( $result['has_more'] );
				++$page;
			} while ( $has_more && $page <= self::MAX_PAGES );
		}

		$migrator->update_manifest_migration_info( $plugin );

		return $totals;
	}
}
