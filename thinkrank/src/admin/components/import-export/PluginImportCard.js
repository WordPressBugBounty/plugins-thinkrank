/**
 * Plugin Import Card Component
 *
 * Per-plugin two-step card: Step 1 (Export snapshot) → Step 2 (Restore).
 * Driven by the useImportWorkflow state machine and styled with the ThinkRank
 * design system (Card / Badge / Button / Alert) so it matches the rest of the
 * admin.
 *
 * @package
 * @since 2.0.0
 */

import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import classnames from 'classnames';

import { Card, Badge, Button, Alert } from '../ui';
import notify from '../../utils/notify';
import { PLATFORM_LOGOS } from './platformLogos';
import { describeCounts } from './importTypes';
import useImportWorkflow, { STATES } from '../../hooks/useImportWorkflow';
import ExportProgress from './ExportProgress';
import MigrateProgress from './MigrateProgress';
import { schemaNotImportedLines, schemaStagedNote } from './schemaReport';
import SnapshotInfo, { getExtendedDataDescriptions } from './SnapshotInfo';
import ConfirmationDialog, {
	getExportDialog,
	getMigrateDialog,
	getCleanupDialog,
	getForceCleanupDialog,
	getDeleteSnapshotDialog,
} from './ConfirmationDialog';

const TYPE_LABELS = {
	postmeta: __('Post Meta', 'thinkrank'),
	termmeta: __('Term Meta', 'thinkrank'),
	usermeta: __('User Meta', 'thinkrank'),
	redirections: __('Redirections', 'thinkrank'),
	'404_logs': __('404 Logs', 'thinkrank'),
	content_blocks: __('FAQ & HowTo Blocks', 'thinkrank'),
	settings: __('Settings', 'thinkrank'),
};

/**
 * Map the workflow state to a status badge.
 *
 * @param {string} state Workflow state.
 * @return {{variant: string, label: string}} Badge config.
 */
const getStatus = (state) => {
	switch (state) {
		case STATES.ERROR:
			return { variant: 'poor', label: __('Error', 'thinkrank') };
		case STATES.MIGRATION_COMPLETE:
			return { variant: 'good', label: __('Migrated', 'thinkrank') };
		case STATES.SNAPSHOT_READY:
			return {
				variant: 'info',
				label: __('Snapshot ready', 'thinkrank'),
			};
		case STATES.EXPORTING:
			return {
				variant: 'neutral',
				label: __('Exporting…', 'thinkrank'),
			};
		case STATES.MIGRATING:
			return {
				variant: 'neutral',
				label: __('Restoring…', 'thinkrank'),
			};
		default:
			return { variant: 'neutral', label: __('Detected', 'thinkrank') };
	}
};

/**
 * A numbered step header.
 *
 * @param {Object} props        Props.
 * @param {number} props.number Step number.
 * @param {string} props.title  Step title.
 * @return {JSX.Element} Header.
 */
const StepHeader = ({ number, title }) => (
	<div className="tr-flex tr-items-center tr-gap-2">
		<span className="tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-text-xs tr-font-semibold tr-shrink-0">
			{number}
		</span>
		<span className="tr-text-sm tr-font-semibold tr-text-primary">
			{title}
		</span>
	</div>
);

/**
 * Plugin Import Card
 *
 * @param {Object}   props           Component props
 * @param {Object}   props.plugin    Plugin detection data
 * @param {Object}   props.snapshot  Existing snapshot manifest (if any)
 * @param {Function} props.onRefresh Callback to refresh detection data
 */
const PluginImportCard = ({ plugin, snapshot, onRefresh }) => {
	const { plugin: pluginSlug, plugin_name: pluginName, counts } = plugin;

	const {
		state,
		error,
		progress,
		startExport,
		startMigration,
		cleanup,
		deleteSnapshot,
		retry,
	} = useImportWorkflow(pluginSlug, snapshot);

	const [selectedTypes, setSelectedTypes] = useState(
		Object.keys(counts).reduce(
			(acc, type) => ({ ...acc, [type]: true }),
			{}
		)
	);
	const [dialog, setDialog] = useState(null);
	const schemaLines = schemaNotImportedLines(progress.schema);
	const schemaNote = schemaStagedNote(progress.schema);

	// Toast the export/migration outcome once, on the actual state transition —
	// tracked via a ref so an initial SNAPSHOT_READY (from a pre-existing
	// snapshot) or an unrelated re-render never fires a stray toast. Cleanup and
	// snapshot deletion are toasted inline from their handlers instead, since
	// those return a result directly.
	const prevStateRef = useRef(state);
	useEffect(() => {
		const prev = prevStateRef.current;
		if (prev === state) {
			return;
		}
		prevStateRef.current = state;

		if (prev === STATES.EXPORTING && state === STATES.SNAPSHOT_READY) {
			notify.success(
				sprintf(
					/* translators: %s: source plugin name. */
					__('%s snapshot exported.', 'thinkrank'),
					pluginName
				)
			);
		} else if (
			prev === STATES.MIGRATING &&
			state === STATES.MIGRATION_COMPLETE &&
			progress.failed > 0
		) {
			// The FAQ/HowTo converter leaves a post alone when its block
			// markup is broken. A plain "complete" toast would hide that the
			// post still carries its Rank Math blocks.
			notify.warning(
				sprintf(
					/* translators: %d: number of posts left unchanged. */
					_n(
						'Migration complete, but the FAQ & HowTo blocks in %d post have broken markup and were left unchanged. Fix that post in the editor.',
						'Migration complete, but the FAQ & HowTo blocks in %d posts have broken markup and were left unchanged. Fix those posts in the editor.',
						progress.failed,
						'thinkrank'
					),
					progress.failed
				)
			);
		} else if (
			prev === STATES.MIGRATING &&
			state === STATES.MIGRATION_COMPLETE
		) {
			notify.success(
				__(
					'Migration complete. ThinkRank is now using the imported data.',
					'thinkrank'
				)
			);
		} else if (
			state === STATES.ERROR &&
			(prev === STATES.EXPORTING || prev === STATES.MIGRATING)
		) {
			notify.error(
				error || __('The import operation failed.', 'thinkrank')
			);
		}
	}, [state, error, pluginName, progress.failed]);

	const handleTypeToggle = useCallback((type) => {
		setSelectedTypes((prev) => ({ ...prev, [type]: !prev[type] }));
	}, []);

	const handleExport = useCallback(() => {
		const types = Object.entries(selectedTypes)
			.filter(([, selected]) => selected)
			.map(([type]) => type);

		if (types.length === 0) {
			return;
		}

		setDialog({
			...getExportDialog(pluginName),
			onConfirm: () => {
				setDialog(null);
				startExport(types);
			},
		});
	}, [selectedTypes, pluginName, startExport]);

	const handleMigrate = useCallback(() => {
		setDialog({
			...getMigrateDialog(),
			onConfirm: () => {
				setDialog(null);
				startMigration();
			},
		});
	}, [startMigration]);

	// Toast the outcome of a cleanup() call. A truthy result means the source
	// data was removed; a null result means the hook already flagged the error
	// (and flipped the card into its error state).
	const notifyCleanupResult = useCallback(
		(result) => {
			if (result && !result.blocked) {
				notify.success(
					sprintf(
						/* translators: %s: source plugin name. */
						__('%s data removed from the database.', 'thinkrank'),
						pluginName
					)
				);
			} else if (!result) {
				notify.error(
					sprintf(
						/* translators: %s: source plugin name. */
						__('Couldn’t remove %s data.', 'thinkrank'),
						pluginName
					)
				);
			}
		},
		[pluginName]
	);

	const handleCleanup = useCallback(() => {
		setDialog({
			...getCleanupDialog(pluginName),
			onConfirm: async () => {
				setDialog(null);
				const result = await cleanup();

				// Server blocked the cleanup: the snapshot still holds data
				// ThinkRank has not applied yet. Ask again with the server's
				// explanation; confirming retries with force=true.
				if (result?.blocked) {
					setDialog({
						...getForceCleanupDialog(
							pluginName,
							result.message,
							result.preserved
						),
						onConfirm: async () => {
							setDialog(null);
							notifyCleanupResult(await cleanup(true));
							if (onRefresh) {
								onRefresh();
							}
						},
					});
					return;
				}

				notifyCleanupResult(result);
				if (onRefresh) {
					onRefresh();
				}
			},
		});
	}, [pluginName, cleanup, onRefresh, notifyCleanupResult]);

	const handleDeleteSnapshot = useCallback(() => {
		const extendedData = snapshot
			? getExtendedDataDescriptions(snapshot)
			: [];
		setDialog({
			...getDeleteSnapshotDialog(pluginName, extendedData),
			onConfirm: async () => {
				setDialog(null);
				const result = await deleteSnapshot();
				if (result) {
					notify.success(
						sprintf(
							/* translators: %s: source plugin name. */
							__('%s snapshot deleted.', 'thinkrank'),
							pluginName
						)
					);
				} else {
					notify.error(
						__('Couldn’t delete the snapshot.', 'thinkrank')
					);
				}
				if (onRefresh) {
					onRefresh();
				}
			},
		});
	}, [pluginName, snapshot, deleteSnapshot, onRefresh]);

	const isExporting = state === STATES.EXPORTING;
	const isMigrating = state === STATES.MIGRATING;
	const isBusy = isExporting || isMigrating;
	const canMigrate =
		state === STATES.SNAPSHOT_READY || state === STATES.MIGRATION_COMPLETE;
	const hasSnapshot =
		snapshot?.status === 'complete' ||
		state === STATES.SNAPSHOT_READY ||
		state === STATES.MIGRATION_COMPLETE;

	const status = getStatus(state);
	const detailParts = describeCounts(counts);
	const detailLine = detailParts.length
		? detailParts.join(' · ')
		: __('Snapshot available', 'thinkrank');

	const canExport = !isBusy && Object.values(selectedTypes).some(Boolean);

	return (
		<Card padding={false}>
			{/* Header */}
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
				<div className="tr-flex tr-items-center tr-gap-3 tr-min-w-0">
					<span
						className="tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-lg tr-bg-white tr-border tr-border-secondary tr-shrink-0 tr-overflow-hidden"
						aria-hidden="true"
					>
						{PLATFORM_LOGOS[pluginSlug] ? (
							<img
								src={PLATFORM_LOGOS[pluginSlug]}
								alt=""
								className="tr-w-6 tr-h-6 tr-object-contain"
							/>
						) : (
							<span className="tr-text-brand tr-text-base tr-font-semibold">
								{pluginName.charAt(0)}
							</span>
						)}
					</span>
					<div className="tr-min-w-0">
						<h3 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0 tr-truncate">
							{pluginName}
						</h3>
						<p className="tr-text-sm tr-text-tertiary tr-m-0 tr-truncate">
							{detailLine}
						</p>
					</div>
				</div>
				<Badge variant={status.variant}>{status.label}</Badge>
			</div>

			{/* Inline alerts */}
			{(error || state === STATES.MIGRATION_COMPLETE) && (
				<div className="tr-px-5 tr-pt-4">
					{error && (
						<Alert
							variant="error"
							title={__('Import failed', 'thinkrank')}
						>
							<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
								<span>{error}</span>
								<Button
									variant="ghost"
									size="sm"
									onClick={retry}
								>
									{__('Retry', 'thinkrank')}
								</Button>
							</div>
						</Alert>
					)}
					{!error && state === STATES.MIGRATION_COMPLETE && (
						<Alert
							variant="success"
							title={__('Migration complete', 'thinkrank')}
						>
							{__(
								'ThinkRank is now using the imported SEO data.',
								'thinkrank'
							)}
						</Alert>
					)}
					{!error &&
						state === STATES.MIGRATION_COMPLETE &&
						(schemaLines.length > 0 || schemaNote) && (
							<div className="tr-mt-3">
								<Alert
									variant="warning"
									title={__(
										'Some schema was not imported',
										'thinkrank'
									)}
								>
									{schemaLines.length > 0 && (
										<>
											<p className="tr-m-0 tr-mb-1">
												{__(
													'Not imported, and still only in the source plugin:',
													'thinkrank'
												)}
											</p>
											<ul className="tr-m-0 tr-pl-5 tr-flex tr-flex-col tr-gap-0.5">
												{schemaLines.map((line) => (
													<li key={line}>{line}</li>
												))}
											</ul>
										</>
									)}
									{schemaNote && (
										<p className="tr-m-0 tr-mt-1">
											{schemaNote}
										</p>
									)}
								</Alert>
							</div>
						)}
				</div>
			)}

			{/* Two-step body */}
			<div className="tr-p-5 tr-grid tr-grid-cols-1 md:tr-grid-cols-2 tr-gap-4">
				{/* Step 1: Export */}
				<div className="tr-rounded-lg tr-bg-[var(--tr-bg-subtle)] tr-p-4 tr-flex tr-flex-col tr-gap-3">
					<StepHeader
						number={1}
						title={__('Export snapshot', 'thinkrank')}
					/>

					{isExporting ? (
						<ExportProgress progress={progress} />
					) : (
						<>
							<div className="tr-flex tr-flex-col tr-gap-1">
								{Object.entries(counts).map(([type, count]) => (
									<CheckboxControl
										key={type}
										__nextHasNoMarginBottom
										label={`${
											TYPE_LABELS[type] || type
										} (${count.toLocaleString()})`}
										checked={selectedTypes[type] || false}
										onChange={() => handleTypeToggle(type)}
										disabled={isBusy}
									/>
								))}
							</div>
							<div>
								<Button
									variant="primary"
									size="sm"
									onClick={handleExport}
									disabled={!canExport}
								>
									{__('Export selected data', 'thinkrank')}
								</Button>
							</div>
						</>
					)}
				</div>

				{/* Step 2: Restore */}
				<div
					className={classnames(
						'tr-rounded-lg tr-bg-[var(--tr-bg-subtle)] tr-p-4 tr-flex tr-flex-col tr-gap-3',
						!canMigrate && !isMigrating && 'tr-opacity-60'
					)}
				>
					<StepHeader
						number={2}
						title={__('Restore to ThinkRank', 'thinkrank')}
					/>

					{isMigrating ? (
						<MigrateProgress progress={progress} />
					) : (
						<>
							{hasSnapshot && snapshot && (
								<SnapshotInfo manifest={snapshot} />
							)}

							{!canMigrate && (
								<p className="tr-text-sm tr-text-tertiary tr-m-0">
									{__(
										'Complete step 1 first to enable restore.',
										'thinkrank'
									)}
								</p>
							)}

							<div>
								<Button
									variant="primary"
									size="sm"
									onClick={handleMigrate}
									disabled={!canMigrate}
								>
									{__('Start migration', 'thinkrank')}
								</Button>
							</div>
						</>
					)}
				</div>
			</div>

			{/* Destructive footer actions */}
			{(hasSnapshot || state === STATES.MIGRATION_COMPLETE) && (
				<div className="tr-flex tr-flex-wrap tr-gap-2 tr-px-5 tr-py-4 tr-border-t tr-border-secondary">
					<Button
						variant="secondary"
						size="sm"
						onClick={handleCleanup}
						disabled={isBusy}
					>
						{sprintf(
							/* translators: %s: source plugin name. */
							__('Clean up %s data', 'thinkrank'),
							pluginName
						)}
					</Button>
					<Button
						variant="secondary"
						size="sm"
						onClick={handleDeleteSnapshot}
						disabled={isBusy}
					>
						{__('Delete snapshot', 'thinkrank')}
					</Button>
				</div>
			)}

			{/* Confirmation dialog */}
			{dialog && (
				<ConfirmationDialog
					{...dialog}
					onCancel={() => setDialog(null)}
				/>
			)}
		</Card>
	);
};

export default PluginImportCard;
