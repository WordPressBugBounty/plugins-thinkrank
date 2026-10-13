/**
 * Import Export Tab Component
 *
 * Body of both data screens, chosen by the `section` prop: the Import / Export
 * page renders ThinkRank's own export/restore card, and the Migration page
 * renders the "import from another plugin" flow. Each has its own submenu entry
 * and its own enable setting.
 *
 * @package
 * @since 2.0.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { Card, Alert, Button, SkeletonCard } from '../ui';
import notify from '../../utils/notify';
import { purgeApiCache } from '../../utils/apiCache';
import PluginImportCard from './PluginImportCard';
import NativeExportCard from './NativeExportCard';
import { SUPPORTED_PLATFORMS } from './platformLogos';

/**
 * Download-tray icon for the empty state.
 *
 * @param {Object} props Props forwarded to the SVG (className).
 * @return {JSX.Element} Icon.
 */
const ImportIcon = (props) => (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" {...props}>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth="2"
			d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"
		/>
	</svg>
);

/**
 * Circular-arrows icon for the Re-scan button.
 *
 * @param {Object} props Props forwarded to the SVG (className).
 * @return {JSX.Element} Icon.
 */
const RescanIcon = (props) => (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		{...props}
	>
		<polyline points="23 4 23 10 17 10" />
		<polyline points="1 20 1 14 7 14" />
		<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
	</svg>
);

/**
 * How many source plugins the user can import from.
 *
 * ThinkRank's own snapshot is not one of them: it belongs to the export card,
 * not the "import from" list. The header and the re-scan toast both report this
 * number, and they disagreed while each counted it for itself — the toast said
 * "2 SEO plugins" under a header reading "1 SEO plugin".
 *
 * @param {Object} detected  Detected source plugins, keyed by slug.
 * @param {Object} snapshots Stored snapshots, keyed by slug.
 * @return {number} Source plugins with importable data.
 */
const countImportSources = (detected, snapshots) =>
	Object.keys(detected).length +
	Object.keys(snapshots).filter(
		(slug) => !detected[slug] && slug !== 'thinkrank'
	).length;

/**
 * Import / Export screen body.
 *
 * One component, two screens: both halves read the same /import/detect
 * response, so splitting them into separate components would mean running that
 * scan twice for no gain.
 *
 * @param {Object} props         Component props.
 * @param {string} props.section Which half to render: 'export' (ThinkRank's own
 *                               data, out and back) or 'import' (bring another
 *                               plugin's data in).
 * @return {JSX.Element} The screen.
 */
const ImportExportTab = ({ section = 'export' }) => {
	const [detected, setDetected] = useState({});
	const [native, setNative] = useState(null);
	const [snapshots, setSnapshots] = useState({});
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState(null);
	// The re-import caution is informational; let the user dismiss it once read.
	const [showCaution, setShowCaution] = useState(true);

	// `refresh` is what makes Re-scan mean anything. The scan is cached twice —
	// in sessionStorage by the apiFetch middleware (5 min) and in a transient on
	// the server (1 hour) — and neither is purged by a GET, so without this the
	// button re-rendered the same stale counts. Only the explicit button pays
	// for a live scan; the mount-time load stays cached so repeat navigation
	// still renders instantly.
	const fetchDetectionData = useCallback(async ({ refresh = false } = {}) => {
		try {
			setIsLoading(true);
			setError(null);

			if (refresh) {
				purgeApiCache();
			}

			const result = await apiFetch({
				path: refresh
					? '/thinkrank/v1/import/detect?refresh=1'
					: '/thinkrank/v1/import/detect',
			});

			const nextDetected = result.detected || {};
			const nextSnapshots = result.snapshots || {};
			setDetected(nextDetected);
			setNative(result.native || null);
			setSnapshots(nextSnapshots);
			return { detected: nextDetected, snapshots: nextSnapshots };
		} catch (err) {
			setError(
				err.message || __('Failed to detect SEO plugins', 'thinkrank')
			);
			throw err;
		} finally {
			setIsLoading(false);
		}
	}, []);

	// User-triggered re-scan — toasts the outcome. The initial mount scan calls
	// fetchDetectionData directly so it stays silent.
	const handleRescan = useCallback(async () => {
		try {
			const { detected: d, snapshots: s } = await fetchDetectionData({
				refresh: true,
			});
			const found = countImportSources(d, s);
			if (found > 0) {
				notify.success(
					sprintf(
						/* translators: %d: number of source SEO plugins found. */
						_n(
							'Found %d SEO plugin with importable data.',
							'Found %d SEO plugins with importable data.',
							found,
							'thinkrank'
						),
						found
					)
				);
			} else {
				notify.info(__('No SEO plugin data detected.', 'thinkrank'));
			}
		} catch (err) {
			notify.error(
				err.message ||
					__('Re-scan failed. Please try again.', 'thinkrank')
			);
		}
	}, [fetchDetectionData]);

	useEffect(() => {
		fetchDetectionData();
	}, [fetchDetectionData]);

	if (isLoading) {
		return (
			<div className="tr-flex tr-flex-col tr-gap-4">
				<SkeletonCard
					lines={4}
					label={
						section === 'import'
							? __('Scanning for SEO plugin data…', 'thinkrank')
							: __('Reading your ThinkRank data…', 'thinkrank')
					}
				/>
				<SkeletonCard lines={4} />
			</div>
		);
	}

	if (error) {
		return (
			<Alert
				variant="error"
				title={__('Couldn’t scan for SEO data', 'thinkrank')}
			>
				<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
					<span>{error}</span>
					<Button
						variant="ghost"
						size="sm"
						onClick={() => fetchDetectionData({ refresh: true })}
					>
						{__('Try again', 'thinkrank')}
					</Button>
				</div>
			</Alert>
		);
	}

	const detectedPlugins = Object.values(detected);
	// ThinkRank's own snapshot is not a source plugin; it belongs to the export
	// card, not the "import from" list.
	const snapshotPlugins = Object.keys(snapshots).filter(
		(slug) => !detected[slug] && slug !== 'thinkrank'
	);
	const count = countImportSources(detected, snapshots);
	const hasContent = count > 0;

	// `section` alone decides what this screen shows. PHP registers each page
	// only while its own setting is on and re-checks in the render callback, so
	// a screen that got here is enabled by definition.
	//
	// It deliberately does NOT also consult window.thinkrankAdmin's
	// migrationToolsEnabled / importExportEnabled. Those are a snapshot taken
	// when the page HTML was built, and they go stale the moment the toggle is
	// flipped: turning Migration Tools on in Settings and following the "Open
	// Migration" link — an SPA navigation, no reload — arrived here with the
	// flag still saying off and rendered an empty page, which came back only
	// after a manual refresh. A second gate that can disagree with the first
	// one is not defence in depth, it is a blank screen.
	const showImportSection = section === 'import';
	const showExportSection = section === 'export';

	return (
		<div className="tr-flex tr-flex-col tr-gap-6">
			{showExportSection && (
				<NativeExportCard
					native={native}
					snapshot={snapshots.thinkrank || null}
					onRefresh={fetchDetectionData}
				/>
			)}

			{!showImportSection ? null : (
				<>
					{/* Toolbar: status summary + re-scan (re-detect after installing or
			    activating a source plugin — supports "re-import at any time"). */}
					<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap">
						<span className="tr-text-sm tr-text-secondary">
							{hasContent
								? sprintf(
										/* translators: %d: number of source SEO plugins with importable data. */
										_n(
											'%d SEO plugin with data you can import.',
											'%d SEO plugins with data you can import.',
											count,
											'thinkrank'
										),
										count
									)
								: __(
										'No SEO plugin data detected yet.',
										'thinkrank'
									)}
						</span>
						<Button
							variant="secondary"
							size="sm"
							icon={RescanIcon}
							onClick={handleRescan}
						>
							{__('Re-scan', 'thinkrank')}
						</Button>
					</div>

					{hasContent && showCaution && (
						<Alert
							variant="warning"
							title={__('Before you re-import', 'thinkrank')}
							onDismiss={() => setShowCaution(false)}
						>
							{__(
								'Re-running a migration may overwrite existing ThinkRank settings and metadata where applicable. Fields still at their defaults are filled from the imported data, while values you have already customized are preserved. We recommend creating a database backup before re-importing.',
								'thinkrank'
							)}
						</Alert>
					)}

					{!hasContent ? (
						<Card>
							<div className="tr-flex tr-flex-col tr-items-center tr-text-center tr-gap-5 tr-py-4">
								<span className="tr-flex tr-items-center tr-justify-center tr-w-14 tr-h-14 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-shrink-0">
									<ImportIcon className="tr-w-7 tr-h-7" />
								</span>
								<div className="tr-max-w-lg">
									<h3 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
										{__(
											'No SEO data to migrate',
											'thinkrank'
										)}
									</h3>
									<p className="tr-mt-1.5 tr-mb-0 tr-text-sm tr-text-secondary">
										{__(
											'ThinkRank reads SEO data straight from your database. Activate one of the supported plugins below, then re-scan you can migrate at any time.',
											'thinkrank'
										)}
									</p>
								</div>

								{/* Supported roster so users know exactly what's importable. */}
								<ul className="tr-flex tr-flex-wrap tr-justify-center tr-gap-2.5 tr-list-none tr-m-0 tr-p-0">
									{SUPPORTED_PLATFORMS.map((platform) => (
										<li
											key={platform.slug}
											className="tr-inline-flex tr-items-center tr-gap-2 tr-m-0 tr-pl-2 tr-pr-3 tr-py-1.5 tr-rounded-full tr-border tr-border-secondary tr-bg-white"
										>
											<span className="tr-flex tr-items-center tr-justify-center tr-w-6 tr-h-6 tr-rounded-full tr-bg-gray-50 tr-shrink-0 tr-overflow-hidden">
												<img
													src={platform.logo}
													alt=""
													className="tr-w-4 tr-h-4 tr-object-contain"
												/>
											</span>
											<span className="tr-text-xs tr-font-medium tr-text-secondary">
												{platform.name}
											</span>
										</li>
									))}
								</ul>

								<Button
									variant="secondary"
									size="sm"
									icon={RescanIcon}
									onClick={handleRescan}
								>
									{__('Re-scan for SEO plugins', 'thinkrank')}
								</Button>
							</div>
						</Card>
					) : (
						<div className="tr-flex tr-flex-col tr-gap-4">
							{detectedPlugins.map((plugin) => (
								<PluginImportCard
									key={plugin.plugin}
									plugin={plugin}
									snapshot={snapshots[plugin.plugin] || null}
									onRefresh={fetchDetectionData}
								/>
							))}

							{snapshotPlugins.map((slug) => (
								<PluginImportCard
									key={slug}
									plugin={{
										plugin: slug,
										plugin_name:
											snapshots[slug].plugin_name || slug,
										counts: {},
										total: 0,
									}}
									snapshot={snapshots[slug]}
									onRefresh={fetchDetectionData}
								/>
							))}
						</div>
					)}
				</>
			)}
		</div>
	);
};

export { ImportExportTab };
export default ImportExportTab;
