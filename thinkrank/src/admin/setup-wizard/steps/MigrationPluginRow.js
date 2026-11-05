/**
 * Migration plugin row — a single supported-plugin card inside the wizard's
 * Migration step.
 *
 * Reuses the existing two-phase import workflow (`useImportWorkflow`): clicking
 * Import first creates a snapshot (export) and, once that succeeds, the
 * migration (restore) starts automatically. Both phases are shown as ONE
 * continuous 0–100 progress bar with a phase-aware title ("Step 1 of 2 …" /
 * "Step 2 of 2 …"), and the running state is reported up so the wizard can lock
 * navigation until it finishes.
 *
 * @package
 * @since 1.0.0
 */

import { useEffect, useCallback, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import useImportWorkflow, { STATES } from '../../hooks/useImportWorkflow';
import { markWizardPluginMigrated, deactivateMigratedPlugin } from '../api';
import {
	EXPORT_TYPES,
	describeCounts,
} from '../../components/import-export/importTypes';
import {
	schemaNotImportedCount,
	schemaStagedNote,
} from '../../components/import-export/schemaReport';
import logger from '../../../shared/utils/logger';

const CheckIcon = () => (
	<svg
		width="10"
		height="8"
		viewBox="0 0 10 8"
		fill="none"
		aria-hidden="true"
	>
		<path
			d="M1 4.2 3.5 6.6 9 1"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/**
 * Build the human-readable "what we found" line from detection counts.
 *
 * @param {Object|null} detection Detection entry for this plugin (or null).
 * @return {string} Detail line.
 */
function buildDetail(detection) {
	if (!detection) {
		return __('Not detected', 'thinkrank');
	}

	const parts = describeCounts(detection.counts || {});

	return parts.length
		? parts.join(', ')
		: __('SEO data detected', 'thinkrank');
}

/**
 * Count what a finished migration did not write, by kind.
 *
 * Each of these is still provided by the source plugin only: a redirect or
 * custom schema that needs ThinkRank Pro, an item Pro refused, a post whose
 * FAQ/HowTo blocks could not be converted, a whole type the site could not
 * take, schema with no ThinkRank equivalent, or schema staged in the editor
 * that is not published until it is deployed. Deactivating the source plugin
 * would switch them off.
 *
 * The same shape is persisted with the wizard's migration record, so the
 * warning survives a refresh.
 *
 * @param {Object} progress Final migration progress from useImportWorkflow.
 * @return {Object} Non-zero counters keyed like the REST `left_behind` arg;
 *                  {} when everything was written.
 */
export function leftBehindSummary(progress) {
	const counts = {
		pro_required: Number(progress?.proRequired) || 0,
		rejected: Number(progress?.rejected) || 0,
		failed: Number(progress?.failed) || 0,
		unrestored_types: progress?.unrestoredTypes?.length || 0,
		schema_not_imported: schemaNotImportedCount(progress?.schema),
		schema_staged: Number(progress?.schema?.staged) || 0,
	};

	return Object.fromEntries(
		Object.entries(counts).filter(([, count]) => count > 0)
	);
}

/**
 * One line per kind of item a migration left behind.
 *
 * @param {Object} summary Counters from {@link leftBehindSummary}, live or
 *                         persisted.
 * @return {string[]} Lines; [] when everything was written.
 */
export function leftBehindLines(summary) {
	const lines = [];
	const proRequired = Number(summary?.pro_required) || 0;
	const rejected = Number(summary?.rejected) || 0;
	const failed = Number(summary?.failed) || 0;
	const unrestored = Number(summary?.unrestored_types) || 0;
	const schemaLeft = Number(summary?.schema_not_imported) || 0;
	const schemaStaged = Number(summary?.schema_staged) || 0;

	if (proRequired > 0) {
		lines.push(
			sprintf(
				/* translators: %s: number of items. */
				_n(
					'%s item (a redirect, custom schema or similar) needs ThinkRank Pro and stays in the snapshot.',
					'%s items (redirects, custom schemas or similar) need ThinkRank Pro and stay in the snapshot.',
					proRequired,
					'thinkrank'
				),
				proRequired.toLocaleString()
			)
		);
	}
	if (rejected > 0) {
		lines.push(
			sprintf(
				/* translators: %s: number of items. */
				_n(
					'%s item was refused and not imported.',
					'%s items were refused and not imported.',
					rejected,
					'thinkrank'
				),
				rejected.toLocaleString()
			)
		);
	}
	if (failed > 0) {
		lines.push(
			sprintf(
				/* translators: %s: number of posts. */
				_n(
					'%s post with FAQ or HowTo blocks could not be converted and was left as it was.',
					'%s posts with FAQ or HowTo blocks could not be converted and were left as they were.',
					failed,
					'thinkrank'
				),
				failed.toLocaleString()
			)
		);
	}
	if (unrestored > 0) {
		lines.push(
			sprintf(
				/* translators: %s: number of data types. */
				_n(
					'%s type of data could not be restored on this site.',
					'%s types of data could not be restored on this site.',
					unrestored,
					'thinkrank'
				),
				unrestored.toLocaleString()
			)
		);
	}
	if (schemaLeft > 0) {
		lines.push(
			sprintf(
				/* translators: %s: number of schema items. */
				_n(
					'%s schema item has no ThinkRank equivalent and was not imported.',
					'%s schema items have no ThinkRank equivalent and were not imported.',
					schemaLeft,
					'thinkrank'
				),
				schemaLeft.toLocaleString()
			)
		);
	}
	if (schemaStaged > 0) {
		lines.push(schemaStagedNote({ staged: schemaStaged }));
	}

	return lines;
}

export default function MigrationPluginRow({
	slug,
	name,
	logo,
	detection,
	alreadyMigrated = false,
	savedLeftBehind = null,
	onImportStart,
	onImportComplete,
	onDeactivated,
	onBusyChange,
}) {
	const detected = !!detection;
	const { state, error, progress, startExport, startMigration, cancel } =
		useImportWorkflow(slug);

	// Source-plugin deactivation status, tracked separately from the import so a
	// failed deactivation never un-marks a successful migration. `null` until an
	// import finishes; seeded 'done' when a prior visit already deactivated it.
	//   'busy'   – deactivation request in flight
	//   'done'   – plugin deactivated (or was already inactive)
	//   'failed' – migration succeeded but the plugin could not be deactivated
	//   'kept'   – the migration left items behind, so the plugin stays active
	//              until the user deactivates it on purpose
	//
	// `leftBehind` holds the lines naming what the migration did not write; []
	// when the run was clean. A partial migration recorded on an earlier visit
	// starts with its lines and as 'kept', so a refresh never turns it into a
	// clean "Imported".
	const [leftBehind, setLeftBehind] = useState(() =>
		alreadyMigrated ? leftBehindLines(savedLeftBehind) : []
	);
	const [deactivation, setDeactivation] = useState(() =>
		leftBehind.length > 0 ? 'kept' : null
	);

	// True from the moment Import is clicked until the whole flow settles, so
	// the SNAPSHOT_READY hand-off only triggers an auto-migration for an import
	// the user actually started here.
	const importingRef = useRef(false);

	// Abort any in-flight export/migration loop if the step unmounts.
	useEffect(() => () => cancel(), [cancel]);

	// Export finished → snapshot ready → start the migration automatically.
	useEffect(() => {
		if (importingRef.current && state === STATES.SNAPSHOT_READY) {
			startMigration();
		}
	}, [state, startMigration]);

	// Deactivate the source plugin and report the outcome up. A failed
	// deactivation is surfaced but never un-marks the successful migration.
	const deactivateSource = useCallback(() => {
		setDeactivation('busy');
		deactivateMigratedPlugin(slug)
			.then((res) => {
				if (res?.success === false) {
					setDeactivation('failed');
					onDeactivated?.(slug, {
						ok: false,
						name: res?.name || name,
						message: res?.message || '',
					});
					return;
				}
				setDeactivation('done');
				onDeactivated?.(slug, {
					ok: true,
					deactivated: !!res?.deactivated,
					name: res?.name || name,
				});
			})
			.catch((e) => {
				logger.error('Failed to deactivate migrated plugin', e);
				setDeactivation('failed');
				onDeactivated?.(slug, { ok: false, name });
			});
	}, [slug, name, onDeactivated]);

	// Migration finished → (1) persist the completion so the Import button stays
	// disabled after a refresh, (2) tell the wizard to refresh the Site Setup
	// step with the values just written, and (3) deactivate the source plugin
	// so two SEO plugins never run at once. Clear the flag so this fires once.
	// All three are best-effort and wizard-only; the standalone Migration page
	// never mounts this row and is unaffected.
	//
	// When the run left items behind (they need Pro, Pro refused them, a post
	// failed to convert, a type could not be restored), the source plugin is
	// the only thing still providing them, so it stays active and the row says
	// why. The user can still deactivate it from the warning.
	useEffect(() => {
		if (!importingRef.current || state !== STATES.MIGRATION_COMPLETE) {
			return;
		}
		importingRef.current = false;

		// Record what was left behind with the completion, so a refresh
		// still shows this as a partial import.
		const summary = leftBehindSummary(progress);
		markWizardPluginMigrated(slug, summary).catch((e) =>
			logger.error('Failed to persist wizard migration state', e)
		);
		onImportComplete?.();

		const lines = leftBehindLines(summary);
		setLeftBehind(lines);
		if (lines.length > 0) {
			setDeactivation('kept');
			return;
		}

		deactivateSource();
	}, [state, slug, progress, onImportComplete, deactivateSource]);

	const handleImport = useCallback(() => {
		importingRef.current = true;
		// Tell the step to hide the other plugins and focus this import.
		onImportStart?.();
		startExport(EXPORT_TYPES);
	}, [startExport, onImportStart]);

	const exporting = state === STATES.EXPORTING;
	const migrating = state === STATES.MIGRATING;
	const handingOff = importingRef.current && state === STATES.SNAPSHOT_READY;
	const busy = exporting || migrating || handingOff;
	// `alreadyMigrated` seeds the done state from persisted wizard history so a
	// refresh / return keeps the plugin shown as imported with Import disabled.
	const done = state === STATES.MIGRATION_COMPLETE || alreadyMigrated;
	const failed = state === STATES.ERROR;
	// A migration that finished but left items in the source plugin.
	const partial = done && leftBehind.length > 0;

	// Migration is two sequential server phases: create a snapshot (export) then
	// restore it into ThinkRank (migrate). Map both onto ONE continuous 0–100 bar
	// so it fills exactly once — snapshot fills the first half, restore the
	// second — instead of two confusing 0→100 cycles.
	let displayPct = 0;
	if (exporting) {
		displayPct = progress.percentage / 2;
	} else if (handingOff) {
		displayPct = 50;
	} else if (migrating) {
		displayPct = 50 + progress.percentage / 2;
	} else if (done) {
		displayPct = 100;
	}
	displayPct = Math.round(displayPct);

	// Report the running state up so the wizard can lock navigation and disable
	// Continue until the migration finishes (or fails, which is recoverable).
	useEffect(() => {
		onBusyChange?.(busy);
	}, [busy, onBusyChange]);

	// Guard against a mid-migration tab close / refresh, which would leave the
	// import half-applied.
	useEffect(() => {
		if (!busy) {
			return undefined;
		}
		const warn = (e) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener('beforeunload', warn);
		return () => window.removeEventListener('beforeunload', warn);
	}, [busy]);

	// The two phases, each with a pending → active → done status.
	const phaseStatus = (isDone, isActive) => {
		if (isDone) {
			return 'is-done';
		}
		if (isActive) {
			return 'is-active';
		}
		return 'is-pending';
	};
	const snapshotStatus = phaseStatus(
		done || migrating || handingOff,
		exporting
	);
	const restoreStatus = phaseStatus(done, migrating);
	const phases = [
		{
			key: 'snapshot',
			label: __('Creating migration snapshot', 'thinkrank'),
			status: snapshotStatus,
		},
		{
			key: 'restore',
			label: __('Restoring imported data', 'thinkrank'),
			status: restoreStatus,
		},
	];

	let progressTitle;
	if (partial) {
		progressTitle = __(
			'Migration finished, but some items were not imported',
			'thinkrank'
		);
	} else if (done) {
		progressTitle = __('Migration completed successfully', 'thinkrank');
	} else if (migrating || handingOff) {
		progressTitle = sprintf(
			/* translators: %1$d: overall progress percentage. */
			__('Step 2 of 2: Restoring imported data… %1$d%%', 'thinkrank'),
			displayPct
		);
	} else {
		progressTitle = sprintf(
			/* translators: %1$d: overall progress percentage. */
			__('Step 1 of 2: Creating migration snapshot… %1$d%%', 'thinkrank'),
			displayPct
		);
	}

	// Reflect the deactivation outcome in the row's status label so the list
	// shows the plugin as migrated *and* deactivated once both have happened.
	let doneLabel = __('Imported', 'thinkrank');
	if (deactivation === 'busy') {
		doneLabel = __('Imported, deactivating…', 'thinkrank');
	} else if (deactivation === 'done') {
		doneLabel = __('Imported & deactivated', 'thinkrank');
	} else if (deactivation === 'kept') {
		doneLabel = __('Partly imported', 'thinkrank');
	}

	let action;
	if (done) {
		action = (
			<span className="tr-wizard__import-done">
				<span className="tr-wizard__import-done-dot">
					<CheckIcon />
				</span>
				{doneLabel}
			</span>
		);
	} else if (failed) {
		action = (
			<Button
				variant="secondary"
				className="tr-wizard__import-btn"
				onClick={handleImport}
			>
				{__('Try again', 'thinkrank')}
			</Button>
		);
	} else {
		action = (
			<Button
				variant="secondary"
				className="tr-wizard__import-btn"
				disabled={!detected || busy}
				aria-disabled={!detected || busy}
				onClick={handleImport}
			>
				{__('Import', 'thinkrank')}
			</Button>
		);
	}

	const showProgress = busy || done;

	return (
		<div className="tr-wizard__row-card tr-wizard__row-card--migration">
			<div className="tr-wizard__row-head">
				<div className="tr-wizard__row-main">
					{logo ? (
						<img
							className="tr-wizard__row-logo"
							src={logo}
							alt=""
							aria-hidden="true"
						/>
					) : (
						<span
							className="tr-wizard__row-logo tr-wizard__row-logo--text"
							aria-hidden="true"
						>
							{name.charAt(0)}
						</span>
					)}
					<div className="tr-wizard__row-text">
						<span className="tr-wizard__row-title">{name}</span>
						<span className="tr-wizard__row-detail">
							{buildDetail(detection)}
						</span>
					</div>
				</div>
				{action}
			</div>

			{showProgress && (
				<div className="tr-wizard__import-progress">
					<span className="tr-wizard__import-progress-title">
						{progressTitle}
					</span>
					{!done && (
						<div
							className="tr-wizard__import-bar"
							role="progressbar"
							aria-valuenow={displayPct}
							aria-valuemin={0}
							aria-valuemax={100}
						>
							<div
								className="tr-wizard__import-bar-fill"
								style={{ width: `${displayPct}%` }}
							/>
						</div>
					)}
					<ul className="tr-wizard__import-steps">
						{phases.map((phase) => (
							<li
								key={phase.key}
								className={`tr-wizard__import-step ${phase.status}`}
							>
								<span className="tr-wizard__import-step-dot">
									{phase.status === 'is-done' && (
										<CheckIcon />
									)}
								</span>
								<span className="tr-wizard__import-step-label">
									{phase.label}
								</span>
							</li>
						))}
					</ul>
				</div>
			)}

			{partial && deactivation === 'kept' && (
				<div className="tr-wizard__import-warning" role="status">
					<ul className="tr-wizard__import-warning-list">
						{leftBehind.map((line) => (
							<li key={line}>{line}</li>
						))}
					</ul>
					<p className="tr-wizard__import-warning-text">
						{sprintf(
							/* translators: %s: source SEO plugin name. */
							__(
								'%s was left active so what it still provides keeps working. Keep it until the remaining items are migrated, or deactivate it now.',
								'thinkrank'
							),
							name
						)}
					</p>
					<Button
						variant="secondary"
						className="tr-wizard__import-warning-btn"
						onClick={deactivateSource}
					>
						{sprintf(
							/* translators: %s: source SEO plugin name. */
							__('Deactivate %s anyway', 'thinkrank'),
							name
						)}
					</Button>
				</div>
			)}

			{failed && (
				<p className="tr-wizard__import-error" role="alert">
					{error ||
						__('Import failed. Please try again.', 'thinkrank')}
				</p>
			)}
		</div>
	);
}
