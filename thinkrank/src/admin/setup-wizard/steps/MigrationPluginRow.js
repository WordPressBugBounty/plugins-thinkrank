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
import { __, sprintf } from '@wordpress/i18n';

import useImportWorkflow, { STATES } from '../../hooks/useImportWorkflow';
import { markWizardPluginMigrated, deactivateMigratedPlugin } from '../api';
import {
	EXPORT_TYPES,
	describeCounts,
} from '../../components/import-export/importTypes';
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

export default function MigrationPluginRow({
	slug,
	name,
	logo,
	detection,
	alreadyMigrated = false,
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
	const [deactivation, setDeactivation] = useState(null);

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

	// Migration finished → (1) persist the completion so the Import button stays
	// disabled after a refresh, (2) tell the wizard to refresh the Site Setup
	// step with the values just written, and (3) immediately deactivate the
	// source plugin so two SEO plugins never run at once. Clear the flag so this
	// fires once. All three are best-effort and wizard-only — the standalone
	// Migration page never mounts this row and is unaffected. A failed
	// deactivation is surfaced but never un-marks the successful migration.
	useEffect(() => {
		if (!importingRef.current || state !== STATES.MIGRATION_COMPLETE) {
			return;
		}
		importingRef.current = false;

		markWizardPluginMigrated(slug).catch((e) =>
			logger.error('Failed to persist wizard migration state', e)
		);
		onImportComplete?.();

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
	}, [state, slug, name, onImportComplete, onDeactivated]);

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
	if (done) {
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

			{failed && (
				<p className="tr-wizard__import-error" role="alert">
					{error ||
						__('Import failed. Please try again.', 'thinkrank')}
				</p>
			)}
		</div>
	);
}
