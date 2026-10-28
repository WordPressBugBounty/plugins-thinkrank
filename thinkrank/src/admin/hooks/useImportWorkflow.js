/**
 * Import Workflow Hook
 *
 * State machine for the two-phase SEO data import system.
 * States: DETECTED → EXPORTING → SNAPSHOT_READY → MIGRATING → MIGRATION_COMPLETE → ERROR
 *
 * @package
 * @since 2.0.0
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	addSchemaResult,
	EMPTY_SCHEMA_TOTALS,
} from '../components/import-export/schemaReport';

/**
 * Workflow states
 */
export const STATES = {
	DETECTED: 'DETECTED',
	EXPORTING: 'EXPORTING',
	SNAPSHOT_READY: 'SNAPSHOT_READY',
	MIGRATING: 'MIGRATING',
	MIGRATION_COMPLETE: 'MIGRATION_COMPLETE',
	ERROR: 'ERROR',
};

/**
 * Ordered data types for export
 */
const EXPORT_TYPES = [
	'postmeta',
	'termmeta',
	'usermeta',
	'redirections',
	'404_logs',
	'content_blocks',
	'settings',
];

/**
 * Preferred order for the migration loop.
 *
 * Not a filter — the snapshot manifest decides what there is to migrate. A
 * ThinkRank export can carry types this build knows nothing about (Pro
 * registers its own), and dropping those here would mean the UI silently
 * refused to restore them.
 */
const MIGRATE_ORDER = [
	'postmeta',
	'termmeta',
	'usermeta',
	'redirections',
	'404_logs',
	'content_blocks',
	'settings',
];

/**
 * Order the manifest's types, keeping any this build does not recognise.
 *
 * @param {string[]} types Types present in the snapshot manifest.
 * @return {string[]} Ordered types.
 */
function orderTypes(types) {
	const known = MIGRATE_ORDER.filter((t) => types.includes(t));
	const rest = types.filter((t) => !MIGRATE_ORDER.includes(t));

	return [...known, ...rest];
}

/**
 * Custom hook for managing the import workflow state machine
 *
 * @param {string} plugin          Plugin slug
 * @param {Object} initialSnapshot Existing snapshot manifest if available
 * @return {Object} Workflow state and actions
 */
const useImportWorkflow = (plugin, initialSnapshot = null) => {
	const initialState =
		initialSnapshot?.status === 'complete'
			? STATES.SNAPSHOT_READY
			: STATES.DETECTED;

	const [state, setState] = useState(initialState);
	const [error, setError] = useState(null);
	const [progress, setProgress] = useState({
		currentType: '',
		currentPage: 0,
		totalPages: 0,
		exported: 0,
		total: 0,
		processed: 0,
		skipped: 0,
		missing: 0,
		failed: 0,
		percentage: 0,
	});

	const abortRef = useRef(false);

	/**
	 * Start the export process
	 *
	 * @param {string[]} selectedTypes Types to export (subset of EXPORT_TYPES)
	 */
	const startExport = useCallback(
		async (selectedTypes) => {
			abortRef.current = false;
			setState(STATES.EXPORTING);
			setError(null);
			// Reset progress so a retry (or the previous phase) can't leak a stale
			// percentage into this phase's first render.
			setProgress((prev) => ({ ...prev, percentage: 0 }));

			const typesToExport = EXPORT_TYPES.filter((t) =>
				selectedTypes.includes(t)
			);

			try {
				for (
					let typeIndex = 0;
					typeIndex < typesToExport.length;
					typeIndex++
				) {
					const type = typesToExport[typeIndex];
					let page = 1;
					let hasMore = true;
					const isLastType = typeIndex === typesToExport.length - 1;

					while (hasMore && !abortRef.current) {
						const result = await apiFetch({
							path: '/thinkrank/v1/import/export',
							method: 'POST',
							data: {
								plugin,
								type,
								page,
								is_last_type: isLastType,
								// Clear the slot on the very first chunk. The
								// manifest is merged into, so a leftover
								// snapshot — a deselected type from an earlier
								// run, or a file uploaded and then not restored
								// — would otherwise ride along in the download.
								reset: typeIndex === 0 && page === 1,
							},
						});

						hasMore = result.has_more;

						const total = result.total || 1;
						const exported = page * 100; // Approximate
						const percentage = Math.min(
							Math.round(
								(typeIndex / typesToExport.length +
									Math.min(exported, total) /
										total /
										typesToExport.length) *
									100
							),
							99
						);

						setProgress({
							currentType: type,
							currentPage: page,
							totalPages: Math.ceil(total / 100),
							exported: result.exported || 0,
							total,
							processed: 0,
							skipped: 0,
							percentage,
						});

						page++;
					}

					if (abortRef.current) {
						return false;
					}
				}

				// The export loop's final in-loop call (is_last_type=true on the last
				// type's last page) already triggers finalize_export() server-side, so
				// no extra finalize request is needed here. Re-exporting page 1 would
				// reset the last type's total_chunks to 1 and revert status to
				// "exporting", truncating a later migration to the first chunk only.
				setProgress((prev) => ({ ...prev, percentage: 100 }));
				setState(STATES.SNAPSHOT_READY);

				return true;
			} catch (err) {
				setError(err.message || 'Export failed');
				setState(STATES.ERROR);

				// Reported rather than thrown: the state machine already holds
				// the error for the UI, but a caller that goes on to send the
				// browser to the download URL needs to know not to.
				return false;
			}
		},
		[plugin]
	);

	/**
	 * Start the migration process
	 *
	 * @param {string} conflict How to treat data ThinkRank already holds:
	 *                          'skip' (never overwrite — the safe answer when
	 *                          importing another plugin's data) or 'overwrite'
	 *                          (right for restoring a ThinkRank backup, where
	 *                          getting the saved values back is the point).
	 */
	const startMigration = useCallback(
		async (conflict = 'skip') => {
			abortRef.current = false;
			setState(STATES.MIGRATING);
			setError(null);
			// The export phase ends at percentage 100; reset it so the migration
			// phase starts from 0 instead of spiking the combined bar to 100%.
			setProgress((prev) => ({ ...prev, percentage: 0 }));

			try {
				// Get the snapshot manifest to know what types and chunks are available
				const snapshotResponse = await apiFetch({
					path: '/thinkrank/v1/import/snapshots',
				});

				const manifest = snapshotResponse.snapshots?.[plugin];
				if (!manifest) {
					throw new Error('No snapshot found for ' + plugin);
				}

				const availableTypes = orderTypes(
					Object.keys(manifest.types || {})
				);

				let totalProcessed = 0;
				let totalSkipped = 0;
				let totalMissing = 0;
				let totalFailed = 0;
				let totalKeywordsTruncated = 0;
				let schemaTotals = EMPTY_SCHEMA_TOTALS;
				const unrestoredTypes = [];

				for (
					let typeIndex = 0;
					typeIndex < availableTypes.length;
					typeIndex++
				) {
					const type = availableTypes[typeIndex];
					const typeInfo = manifest.types[type];
					const totalChunks = typeInfo.total_chunks || 1;
					const isLastType = typeIndex === availableTypes.length - 1;

					for (
						let page = 1;
						page <= totalChunks && !abortRef.current;
						page++
					) {
						let result;
						try {
							result = await apiFetch({
								path: '/thinkrank/v1/import/migrate',
								method: 'POST',
								data: {
									plugin,
									type,
									page,
									conflict,
									is_last_type:
										isLastType && page === totalChunks,
								},
							});
						} catch (typeError) {
							// A type this site cannot take — a Pro type with Pro
							// inactive, say — is rejected by the route. Skip it
							// and keep going: the records stay in the snapshot,
							// and aborting would lose the types that DO restore.
							unrestoredTypes.push(type);
							break;
						}

						totalProcessed += result.processed || 0;
						totalSkipped += result.skipped || 0;
						// Only the native restore reports this: records whose
						// post/term/user is not on this site.
						totalMissing += result.missing || 0;
						// Posts the FAQ/HowTo block converter refused and left
						// untouched (broken block markup). Kept apart from
						// skipped, which means "nothing to do".
						totalFailed += result.failed || 0;
						totalKeywordsTruncated +=
							result.keywords_truncated || 0;
						// Schema left behind or staged in the editor (#1286).
						schemaTotals = addSchemaResult(schemaTotals, result);

						const percentage = Math.min(
							Math.round(
								(typeIndex / availableTypes.length +
									page /
										totalChunks /
										availableTypes.length) *
									100
							),
							99
						);

						setProgress({
							currentType: type,
							currentPage: page,
							totalPages: totalChunks,
							exported: 0,
							total: typeInfo.total_records || 0,
							processed: totalProcessed,
							skipped: totalSkipped,
							missing: totalMissing,
							failed: totalFailed,
							keywordsTruncated: totalKeywordsTruncated,
							schema: schemaTotals,
							percentage,
						});
					}

					if (abortRef.current) {
						return;
					}
				}

				setProgress((prev) => ({
					...prev,
					percentage: 100,
					unrestoredTypes,
				}));
				setState(STATES.MIGRATION_COMPLETE);
			} catch (err) {
				setError(err.message || 'Migration failed');
				setState(STATES.ERROR);
			}
		},
		[plugin]
	);

	/**
	 * Load a ThinkRank export file into the snapshot store.
	 *
	 * Sent as multipart rather than through apiFetch's JSON path, and with the
	 * nonce set by hand: apiFetch only adds its header to requests it builds,
	 * and a FormData body must keep the browser-generated multipart boundary,
	 * so Content-Type is deliberately left unset.
	 *
	 * @param {File} file The .json export file.
	 * @return {Promise<Object|null>} The stored manifest, or null on failure.
	 */
	const uploadFile = useCallback(async (file) => {
		setError(null);

		const body = new FormData();
		body.append('file', file);

		try {
			const result = await apiFetch({
				path: '/thinkrank/v1/export/upload',
				method: 'POST',
				body,
			});

			setState(STATES.SNAPSHOT_READY);
			return result;
		} catch (err) {
			setError(err.message || 'Upload failed');
			setState(STATES.ERROR);
			return null;
		}
	}, []);

	/**
	 * Clean up source plugin data
	 *
	 * The server rejects cleanup with HTTP 409 (`thinkrank_cleanup_blocked`)
	 * while the snapshot still holds extended data ThinkRank cannot apply yet.
	 * That is not an error state — it is surfaced as `{ blocked: true }` so the
	 * caller can ask the user to confirm and retry with `force = true`.
	 *
	 * @param {boolean} force Proceed even when unapplied snapshot data exists.
	 */
	const cleanup = useCallback(
		async (force = false) => {
			try {
				const result = await apiFetch({
					path: '/thinkrank/v1/import/cleanup',
					method: 'POST',
					data: { plugin, force },
				});
				return result;
			} catch (err) {
				if (err?.code === 'thinkrank_cleanup_blocked') {
					return {
						blocked: true,
						message: err.message,
						preserved: err.data?.preserved || [],
					};
				}
				setError(err.message || 'Cleanup failed');
				setState(STATES.ERROR);
				return null;
			}
		},
		[plugin]
	);

	/**
	 * Delete the snapshot
	 */
	const deleteSnapshot = useCallback(async () => {
		try {
			const result = await apiFetch({
				path: '/thinkrank/v1/import/snapshot',
				method: 'DELETE',
				data: { plugin },
			});
			setState(STATES.DETECTED);
			return result;
		} catch (err) {
			setError(err.message || 'Snapshot deletion failed');
			setState(STATES.ERROR);
			return null;
		}
	}, [plugin]);

	/**
	 * Cancel the current operation
	 */
	const cancel = useCallback(() => {
		abortRef.current = true;
	}, []);

	/**
	 * Retry from error state
	 */
	const retry = useCallback(() => {
		setError(null);
		setState(STATES.DETECTED);
	}, []);

	return {
		state,
		error,
		progress,
		startExport,
		startMigration,
		uploadFile,
		cleanup,
		deleteSnapshot,
		cancel,
		retry,
	};
};

export default useImportWorkflow;
