/**
 * Native Export / Restore Card
 *
 * ThinkRank's own data, out to a file and back in again — portability, backup,
 * and site-to-site migration in one card. Distinct from PluginImportCard, which
 * pulls another plugin's data IN.
 *
 * @package
 * @since 2.2.0
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import classnames from 'classnames';

import { Card, Button, Alert, Badge, Select } from '../ui';
import notify from '../../utils/notify';
import useImportWorkflow, { STATES } from '../../hooks/useImportWorkflow';
import ExportProgress from './ExportProgress';
import MigrateProgress from './MigrateProgress';
import ConfirmationDialog from './ConfirmationDialog';

const SLUG = 'thinkrank';

const TYPE_LABELS = {
	postmeta: __('Post SEO data', 'thinkrank'),
	termmeta: __('Category & tag SEO data', 'thinkrank'),
	usermeta: __('Author SEO data', 'thinkrank'),
	settings: __('Settings', 'thinkrank'),
};

const TYPE_DETAILS = {
	postmeta: __('Titles, descriptions, keywords, robots, social', 'thinkrank'),
	termmeta: __('Titles and descriptions for terms', 'thinkrank'),
	usermeta: __('Author archive titles and descriptions', 'thinkrank'),
	settings: __('Every ThinkRank setting except API keys', 'thinkrank'),
};

/**
 * Box-with-arrow icon.
 *
 * @param {Object} props Props forwarded to the SVG.
 * @return {JSX.Element} Icon.
 */
const ExportIcon = (props) => (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		{...props}
	>
		<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
		<polyline points="17 8 12 3 7 8" />
		<line x1="12" y1="3" x2="12" y2="15" />
	</svg>
);

/**
 * Tray-with-arrow-in icon for the restore dropzone — the mirror of ExportIcon.
 *
 * @param {Object} props Props forwarded to the SVG.
 * @return {JSX.Element} Icon.
 */
const UploadIcon = (props) => (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		{...props}
	>
		<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
		<polyline points="7 10 12 15 17 10" />
		<line x1="12" y1="15" x2="12" y2="3" />
	</svg>
);

/**
 * Build the download URL for a finished snapshot.
 *
 * The browser fetches this as a plain navigation, so cookie auth needs the
 * nonce in the query string — apiFetch's header never reaches it.
 *
 * @param {string} format 'json' or 'csv'.
 * @return {string} Absolute URL.
 */
function buildDownloadUrl(format) {
	const admin = window.thinkrankAdmin || {};
	const base = `${admin.apiUrl || '/wp-json/thinkrank/v1/'}export/download`;
	const params = new URLSearchParams({
		format,
		_wpnonce: admin.restNonce || '',
	});

	return `${base}?${params.toString()}`;
}

/**
 * Native Export / Restore Card
 *
 * @param {Object}   props           Component props
 * @param {Object}   props.native    Detection result for ThinkRank's own data
 * @param {Object}   props.snapshot  Existing native snapshot manifest, if any
 * @param {Function} props.onRefresh Re-run detection after a change
 */
const NativeExportCard = ({ native, snapshot = null, onRefresh }) => {
	const counts = native?.counts || {};
	const availableTypes = Object.keys(counts);

	const [selectedTypes, setSelectedTypes] = useState(availableTypes);
	const [format, setFormat] = useState('json');
	const [pendingRestore, setPendingRestore] = useState(null);
	const [conflict, setConflict] = useState('overwrite');
	const [isDragging, setIsDragging] = useState(false);
	const [isUploading, setIsUploading] = useState(false);
	const fileInputRef = useRef(null);

	const {
		state,
		error,
		progress,
		startExport,
		startMigration,
		uploadFile,
		retry,
	} = useImportWorkflow(SLUG, snapshot);

	const toggleType = useCallback((type) => {
		setSelectedTypes((prev) =>
			prev.includes(type)
				? prev.filter((t) => t !== type)
				: [...prev, type]
		);
	}, []);

	const allSelected =
		availableTypes.length > 0 &&
		availableTypes.every((type) => selectedTypes.includes(type));

	const toggleAll = useCallback(() => {
		setSelectedTypes(allSelected ? [] : availableTypes);
	}, [allSelected, availableTypes]);

	// stream_csv() reads postmeta and nothing else, so a CSV export cannot
	// carry the other types whatever is ticked. Rather than quietly dropping
	// them, the rows go disabled and the button exports what the format can
	// actually hold. The tick state itself is left alone, so switching back to
	// JSON restores the selection the user made.
	const csvOnly = format === 'csv';
	const isTypeExportable = useCallback(
		(type) => !csvOnly || type === 'postmeta',
		[csvOnly]
	);
	const effectiveTypes = selectedTypes.filter(isTypeExportable);
	const canExport = effectiveTypes.length > 0;

	const exportsSettings = effectiveTypes.includes('settings');
	// Settings carries no record count, so on its own the sentence below would
	// read "0 records plus your settings".
	const settingsOnly = exportsSettings && effectiveTypes.length === 1;

	const selectedRecordCount = effectiveTypes.reduce(
		// `settings` is one row in the manifest but every setting on the site;
		// counting it as 1 record would read as one setting.
		(total, type) => total + (type === 'settings' ? 0 : counts[type] || 0),
		0
	);

	// Each count needs its own plural: one gettext string cannot carry two
	// independent ones, and pluralising the sentence on the type count is what
	// produced "2 data types — 1 records".
	const typesPhrase = sprintf(
		/* translators: %d: number of data types. */
		_n('%d data type', '%d data types', effectiveTypes.length, 'thinkrank'),
		effectiveTypes.length
	);
	const recordsPhrase = sprintf(
		/* translators: %s: formatted number of records. */
		_n('%s record', '%s records', selectedRecordCount, 'thinkrank'),
		Number(selectedRecordCount).toLocaleString()
	);

	/**
	 * Export, then hand the browser the file.
	 *
	 * The download itself deletes the snapshot server-side, so nothing is left
	 * sitting in wp_options after the user has the file.
	 */
	const handleExport = useCallback(async () => {
		if (effectiveTypes.length === 0) {
			return;
		}

		// Only hand the browser the download once the snapshot is actually
		// there. Navigating after a failed export lands the user on a raw
		// "no completed export" JSON error, under a success toast.
		const exported = await startExport(effectiveTypes);
		if (!exported) {
			return;
		}

		window.location.href = buildDownloadUrl(format);
		notify.success(__('Your export is downloading.', 'thinkrank'));

		// The snapshot is cleared as part of the download, so the counts the
		// card shows need re-reading.
		if (onRefresh) {
			setTimeout(onRefresh, 1500);
		}
	}, [effectiveTypes, format, startExport, onRefresh]);

	// One path for both ways in — the file picker and a drop on the zone.
	const acceptFile = useCallback(
		async (file) => {
			if (!file) {
				return;
			}

			setIsUploading(true);
			try {
				const result = await uploadFile(file);
				if (result?.manifest) {
					setPendingRestore(result.manifest);
				}
			} finally {
				setIsUploading(false);
			}
		},
		[uploadFile]
	);

	const handleFilePicked = useCallback(
		async (event) => {
			const file = event.target.files?.[0];
			// Let the same file be chosen again after a cancel.
			event.target.value = '';
			await acceptFile(file);
		},
		[acceptFile]
	);

	const handleDrop = useCallback(
		async (event) => {
			event.preventDefault();
			setIsDragging(false);
			await acceptFile(event.dataTransfer?.files?.[0]);
		},
		[acceptFile]
	);

	const openFilePicker = useCallback(() => {
		fileInputRef.current?.click();
	}, []);

	const handleConfirmRestore = useCallback(async () => {
		setPendingRestore(null);
		await startMigration(conflict);
		notify.success(__('Restore complete.', 'thinkrank'));
		if (onRefresh) {
			onRefresh();
		}
	}, [conflict, startMigration, onRefresh]);

	const isBusy = state === STATES.EXPORTING || state === STATES.MIGRATING;

	const foreignSite =
		pendingRestore?.source_site_url &&
		pendingRestore.source_site_url !== window.thinkrankAdmin?.siteUrl;

	return (
		<Card>
			<div className="tr-flex tr-flex-col tr-gap-5">
				{/* One card, one header. */}
				<div className="tr-flex tr-items-start tr-gap-3">
					<span className="tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-lg tr-bg-brand-bg tr-text-brand tr-shrink-0">
						<ExportIcon className="tr-w-5 tr-h-5" />
					</span>
					<div className="tr-flex-1 tr-min-w-0">
						<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
							<h3 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
								{__('Your ThinkRank data', 'thinkrank')}
							</h3>
							{native?.total > 0 && (
								<Badge variant="neutral">
									{sprintf(
										/* translators: %s: formatted record count. */
										__('%s records', 'thinkrank'),
										Number(native.total).toLocaleString()
									)}
								</Badge>
							)}
						</div>
						<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary">
							{__(
								'Download everything ThinkRank stores as a single file, and load it back on this site or any other. Useful as a backup before big changes and it means your SEO data is never locked in here.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>

				{error && (
					<Alert
						variant="error"
						title={__('Something went wrong', 'thinkrank')}
					>
						<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
							<span>{error}</span>
							<Button variant="ghost" size="sm" onClick={retry}>
								{__('Dismiss', 'thinkrank')}
							</Button>
						</div>
					</Alert>
				)}

				{state === STATES.EXPORTING && (
					<ExportProgress progress={progress} />
				)}
				{state === STATES.MIGRATING && (
					<MigrateProgress progress={progress} />
				)}

				{!isBusy && (
					<>
						{/* Export */}
						<div className="tr-flex tr-flex-col tr-gap-3">
							<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap">
								<h4 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary">
									{__('Export', 'thinkrank')}
								</h4>
								{availableTypes.length > 1 && (
									<button
										type="button"
										onClick={toggleAll}
										className="tr-bg-transparent tr-border-0 tr-p-0 tr-cursor-pointer tr-text-xs tr-text-tertiary hover:tr-text-brand hover:tr-underline"
									>
										{allSelected
											? __('Clear all', 'thinkrank')
											: __('Select all', 'thinkrank')}
									</button>
								)}
							</div>

							{/* One line per type: tick, name, count. The
							    per-type blurb moved to a title so the list
							    stays four short rows instead of eight, and the
							    CSV caveat lives on the hint below rather than
							    being repeated under every greyed-out row —
							    between the dimming, the format name and that
							    line it was already said three times. */}
							<ul className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 tr-gap-x-8 tr-gap-y-2.5 tr-list-none tr-m-0 tr-p-0">
								{availableTypes.map((type) => {
									const checked =
										selectedTypes.includes(type);
									const exportable = isTypeExportable(type);

									return (
										<li key={type} className="tr-m-0">
											<label
												htmlFor={`tr-export-type-${type}`}
												title={TYPE_DETAILS[type] || ''}
												className={classnames(
													'tr-flex tr-items-center tr-gap-2.5 tr-text-sm',
													exportable
														? 'tr-cursor-pointer'
														: 'tr-cursor-not-allowed tr-opacity-50'
												)}
											>
												<input
													id={`tr-export-type-${type}`}
													type="checkbox"
													checked={checked}
													disabled={!exportable}
													onChange={() =>
														toggleType(type)
													}
													className="tr-shrink-0"
												/>
												<span className="tr-text-primary tr-truncate">
													{TYPE_LABELS[type] || type}
												</span>
												{type !== 'settings' &&
													counts[type] > 0 && (
														<span className="tr-text-tertiary tr-tabular-nums">
															{Number(
																counts[type]
															).toLocaleString()}
														</span>
													)}
											</label>
										</li>
									);
								})}
							</ul>

							<div className="tr-flex tr-items-end tr-gap-3 tr-flex-wrap">
								<Select
									id="tr-export-format"
									label={__('Format', 'thinkrank')}
									value={format}
									onChange={(e) => setFormat(e.target.value)}
									className="tr-w-full sm:tr-w-80"
									options={[
										{
											value: 'json',
											label: __(
												'JSON: complete, can be restored',
												'thinkrank'
											),
										},
										{
											value: 'csv',
											label: __(
												'CSV: post data only, for spreadsheets',
												'thinkrank'
											),
										},
									]}
								/>

								<Button
									variant="primary"
									onClick={handleExport}
									disabled={!canExport}
								>
									{__('Export & download', 'thinkrank')}
								</Button>
							</div>

							{/* Say what the button is about to produce, so the
							    format and the ticks do not have to be held in
							    the head at once. */}
							{canExport ? (
								<p className="tr-m-0 tr-text-xs tr-text-tertiary">
									{csvOnly
										? __(
												'Post SEO data only, as a spreadsheet. Restoring needs the JSON file.',
												'thinkrank'
											)
										: settingsOnly
											? __(
													'Exports your settings. No post, term or author data selected.',
													'thinkrank'
												)
											: exportsSettings
												? sprintf(
														/* translators: 1: e.g. "2 data types", 2: e.g. "1 record". */
														__(
															'Exports %1$s: %2$s plus your settings.',
															'thinkrank'
														),
														typesPhrase,
														recordsPhrase
													)
												: sprintf(
														/* translators: 1: e.g. "2 data types", 2: e.g. "1 record". */
														__(
															'Exports %1$s: %2$s.',
															'thinkrank'
														),
														typesPhrase,
														recordsPhrase
													)}
								</p>
							) : (
								<p className="tr-m-0 tr-text-xs tr-text-tertiary">
									{csvOnly
										? __(
												'Tick Post SEO data, or switch to JSON.',
												'thinkrank'
											)
										: __(
												'Pick at least one type to export.',
												'thinkrank'
											)}
								</p>
							)}
						</div>

						{/* Restore — a real dropzone, not just a button.
						    "Load a file" is a drag target everywhere else, and
						    the click path is kept for keyboard and for anyone
						    who would rather browse. */}
						<div className="tr-flex tr-flex-col tr-gap-3 tr-pt-4 tr-border-t tr-border-secondary">
							<h4 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary">
								{__('Restore from a file', 'thinkrank')}
							</h4>

							<input
								ref={fileInputRef}
								type="file"
								accept="application/json,.json"
								onChange={handleFilePicked}
								className="tr-hidden"
							/>

							<div
								role="button"
								tabIndex={0}
								aria-label={__(
									'Choose a ThinkRank export file, or drop one here',
									'thinkrank'
								)}
								aria-busy={isUploading}
								onClick={openFilePicker}
								onKeyDown={(event) => {
									if (
										event.key === 'Enter' ||
										event.key === ' '
									) {
										event.preventDefault();
										openFilePicker();
									}
								}}
								onDragOver={(event) => {
									event.preventDefault();
									setIsDragging(true);
								}}
								onDragLeave={() => setIsDragging(false)}
								onDrop={handleDrop}
								className={classnames(
									'tr-flex tr-flex-col tr-items-center tr-justify-center tr-gap-2 tr-text-center',
									'tr-px-4 tr-py-7 tr-rounded-lg tr-border tr-border-dashed tr-cursor-pointer',
									'tr-transition-colors tr-duration-150',
									'focus:tr-outline-none focus-visible:tr-border-brand focus-visible:tr-ring-2 focus-visible:tr-ring-brand',
									isDragging
										? 'tr-border-brand tr-bg-brand-bg'
										: 'tr-border-secondary tr-bg-white hover:tr-border-brand'
								)}
							>
								<span
									className={classnames(
										'tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-full tr-shrink-0',
										isDragging
											? 'tr-bg-white tr-text-brand'
											: 'tr-bg-brand-bg tr-text-brand'
									)}
								>
									<UploadIcon className="tr-w-5 tr-h-5" />
								</span>

								{isUploading ? (
									<span className="tr-text-sm tr-font-medium tr-text-primary">
										{__('Reading your file…', 'thinkrank')}
									</span>
								) : (
									<>
										<span className="tr-text-sm tr-font-medium tr-text-primary">
											{isDragging
												? __(
														'Drop to load it',
														'thinkrank'
													)
												: __(
														'Drop a ThinkRank export here, or click to browse',
														'thinkrank'
													)}
										</span>
										<span className="tr-text-xs tr-text-tertiary tr-max-w-md">
											{__(
												'JSON only. You will see what the file contains, and choose how conflicts are handled, before anything is written.',
												'thinkrank'
											)}
										</span>
									</>
								)}
							</div>
						</div>

						{state === STATES.MIGRATION_COMPLETE &&
							progress.unrestoredTypes?.length > 0 && (
								<Alert
									variant="warning"
									title={__(
										'Some data was left in place',
										'thinkrank'
									)}
								>
									{sprintf(
										/* translators: %s: comma-separated list of data types. */
										__(
											'This site could not take: %s. Those records are still in the file. Restore it again once the feature that owns them is active.',
											'thinkrank'
										),
										progress.unrestoredTypes
											.map((t) => TYPE_LABELS[t] || t)
											.join(', ')
									)}
								</Alert>
							)}

						{state === STATES.MIGRATION_COMPLETE && (
							<Alert
								variant="success"
								title={__('Restore complete', 'thinkrank')}
							>
								{sprintf(
									/* translators: 1: records restored, 2: records skipped, 3: records whose object is missing. */
									__(
										'%1$s restored, %2$s skipped, %3$s referred to content that no longer exists here.',
										'thinkrank'
									),
									Number(
										progress.processed || 0
									).toLocaleString(),
									Number(
										progress.skipped || 0
									).toLocaleString(),
									Number(
										progress.missing || 0
									).toLocaleString()
								)}
							</Alert>
						)}
					</>
				)}
			</div>

			{pendingRestore && (
				<ConfirmationDialog
					title={__('Restore this export?', 'thinkrank')}
					confirmLabel={__('Restore', 'thinkrank')}
					onCancel={() => setPendingRestore(null)}
					onConfirm={handleConfirmRestore}
					message={
						<div>
							<p>
								{sprintf(
									/* translators: 1: ThinkRank version the file was made with, 2: export date. */
									__(
										'Made with ThinkRank %1$s on %2$s.',
										'thinkrank'
									),
									pendingRestore.source_version || '—',
									(pendingRestore.exported_at || '').slice(
										0,
										10
									) || '—'
								)}
							</p>

							<ul className="tr-list-disc tr-pl-5 tr-m-0 tr-mb-3">
								{Object.entries(pendingRestore.types || {}).map(
									([type, info]) => (
										<li key={type} className="tr-text-sm">
											{TYPE_LABELS[type] || type}:{' '}
											{Number(
												info.total_records || 0
											).toLocaleString()}
										</li>
									)
								)}
							</ul>

							{foreignSite && (
								<>
									<p>
										<strong>
											{sprintf(
												/* translators: %s: site URL the file came from. */
												__(
													'This file came from %s, not this site. Records are matched by post ID, so anything whose ID does not exist here is skipped.',
													'thinkrank'
												),
												pendingRestore.source_site_url
											)}
										</strong>
									</p>
									<p>
										{__(
											'Image URLs (social images, schema images) are restored exactly as they were saved, so they will still point at the old site. Run a search-and-replace over the database afterwards to update them. That is the same step any SEO plugin needs after a domain move.',
											'thinkrank'
										)}
									</p>
								</>
							)}

							<fieldset className="tr-m-0 tr-p-0 tr-border-0">
								<legend className="tr-text-sm tr-font-semibold tr-text-primary tr-mb-1.5 tr-p-0">
									{__(
										'Where this site already has a value',
										'thinkrank'
									)}
								</legend>
								<label
									htmlFor="tr-restore-conflict-overwrite"
									className="tr-flex tr-items-start tr-gap-2 tr-cursor-pointer tr-mb-1.5"
								>
									<input
										id="tr-restore-conflict-overwrite"
										type="radio"
										name="tr-restore-conflict"
										value="overwrite"
										checked={conflict === 'overwrite'}
										onChange={() =>
											setConflict('overwrite')
										}
										className="tr-mt-0.5"
									/>
									<span className="tr-text-sm">
										{__(
											'Replace it with the file’s value',
											'thinkrank'
										)}
										<span className="tr-block tr-text-xs tr-text-tertiary">
											{__(
												'What you want when restoring a backup.',
												'thinkrank'
											)}
										</span>
									</span>
								</label>
								<label
									htmlFor="tr-restore-conflict-skip"
									className="tr-flex tr-items-start tr-gap-2 tr-cursor-pointer"
								>
									<input
										id="tr-restore-conflict-skip"
										type="radio"
										name="tr-restore-conflict"
										value="skip"
										checked={conflict === 'skip'}
										onChange={() => setConflict('skip')}
										className="tr-mt-0.5"
									/>
									<span className="tr-text-sm">
										{__('Keep what is here', 'thinkrank')}
										<span className="tr-block tr-text-xs tr-text-tertiary">
											{__(
												'Only fills in fields that are currently empty.',
												'thinkrank'
											)}
										</span>
									</span>
								</label>
							</fieldset>
						</div>
					}
				/>
			)}
		</Card>
	);
};

export default NativeExportCard;
