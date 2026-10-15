/**
 * Step 2 — Migration.
 *
 * Lists the supported SEO plugins, using the existing import detection system
 * to decide which can be imported. Each row drives the existing two-phase
 * import workflow (snapshot → migrate) via {@link MigrationPluginRow}.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import MigrationPluginRow from './MigrationPluginRow';
import { detectImportPlugins } from '../api';
import logger from '../../../shared/utils/logger';

import yoast from '../assets/migration/yoast.png';
import rankmath from '../assets/migration/rankmath.png';
import aioseo from '../assets/migration/aioseo.png';
import seopress from '../assets/migration/seopress.svg';
import squirrly from '../assets/migration/squirrly.svg';
import slimseo from '../assets/migration/slimseo.svg';
import surerank from '../assets/migration/surerank.svg';

/**
 * Supported plugins shown in the step. The `slug` must match the import
 * detector / controller slugs (see ALLOWED_PLUGINS in class-import-controller).
 */
const SUPPORTED_PLUGINS = [
	{ slug: 'yoast', name: 'Yoast SEO', logo: yoast },
	{ slug: 'rankmath', name: 'Rank Math', logo: rankmath },
	{ slug: 'aioseo', name: 'All in One SEO', logo: aioseo },
	{ slug: 'seopress', name: 'SEOPress', logo: seopress },
	{ slug: 'squirrly', name: 'Squirrly SEO', logo: squirrly },
	{ slug: 'slimseo', name: 'Slim SEO', logo: slimseo },
	{ slug: 'surerank', name: 'SureRank', logo: surerank },
];

export default function Step2Migration({
	config,
	onMigrationComplete,
	onBusyChange,
}) {
	const [detected, setDetected] = useState(null); // null = loading
	const [failed, setFailed] = useState(false);
	// Once an import starts, the other plugins are hidden and only the active
	// plugin's progress is shown.
	const [activeSlug, setActiveSlug] = useState(null);
	// Plugins already migrated in the wizard (persisted server-side). Seeded from
	// the localized config so a completed import shows as "Imported" after a
	// refresh / on return, then grown locally as imports finish this session.
	const [migratedSlugs, setMigratedSlugs] = useState(
		() => new Set(config?.migratedPlugins || [])
	);
	// Notice shown on this step after a source plugin is auto-deactivated
	// following a successful migration. Shape: { type: 'success'|'error', message }.
	const [deactivationNotice, setDeactivationNotice] = useState(null);

	const handlePluginMigrated = (slug) => {
		setMigratedSlugs((prev) => new Set(prev).add(slug));
		onMigrationComplete?.();
	};

	// Report the deactivation outcome for the just-migrated plugin as a
	// success/error notice on this same step. A failed deactivation still leaves
	// the migration marked successful — it only asks the user to deactivate
	// manually. An already-inactive plugin produces no notice (nothing changed).
	const handleDeactivated = (slug, result) => {
		if (result?.ok) {
			if (result.deactivated) {
				setDeactivationNotice({
					type: 'success',
					message: sprintf(
						/* translators: %s: source SEO plugin name. */
						__(
							'%s has been deactivated successfully to prevent conflicts with ThinkRank.',
							'thinkrank'
						),
						result.name || slug
					),
				});
			}
			return;
		}
		setDeactivationNotice({
			type: 'error',
			message: sprintf(
				/* translators: %s: source SEO plugin name. */
				__(
					'%s data was migrated successfully, but it couldn’t be deactivated automatically. Please deactivate it manually to avoid conflicts with ThinkRank.',
					'thinkrank'
				),
				result?.name || slug
			),
		});
	};

	useEffect(() => {
		let active = true;
		detectImportPlugins()
			.then((res) => {
				if (active) {
					setDetected(res?.detected || {});
				}
			})
			.catch((e) => {
				logger.error('Import detection failed', e);
				if (active) {
					setDetected({});
					setFailed(true);
				}
			});
		return () => {
			active = false;
		};
	}, []);

	if (detected === null) {
		return (
			<p className="tr-wizard__import-detecting">
				{__('Scanning for existing SEO data…', 'thinkrank')}
			</p>
		);
	}

	// Only show plugins that are active and have valid data to migrate — a
	// detection entry only exists for those (see Import_Detector). Once an
	// import starts, narrow to just that plugin.
	const eligiblePlugins = SUPPORTED_PLUGINS.filter(
		(plugin) => detected[plugin.slug]
	);
	const visiblePlugins = activeSlug
		? eligiblePlugins.filter((plugin) => plugin.slug === activeSlug)
		: eligiblePlugins;

	return (
		<>
			{deactivationNotice && (
				<div
					className={`tr-wizard__notice tr-wizard__notice--${deactivationNotice.type}`}
					role="status"
				>
					<span className="tr-wizard__notice-text">
						{deactivationNotice.message}
					</span>
					<button
						type="button"
						className="tr-wizard__notice-dismiss"
						aria-label={__('Dismiss', 'thinkrank')}
						onClick={() => setDeactivationNotice(null)}
					>
						×
					</button>
				</div>
			)}
			{failed && !activeSlug && (
				<p className="tr-wizard__import-error" role="alert">
					{__(
						'We couldn’t scan for existing SEO data. You can still continue and import later.',
						'thinkrank'
					)}
				</p>
			)}
			{!failed && !visiblePlugins.length && (
				<p className="tr-wizard__import-empty">
					{__(
						'No active SEO plugins with data to migrate were found. You’re all set. Continue to the next step.',
						'thinkrank'
					)}
				</p>
			)}
			<div className="tr-wizard__rows">
				{visiblePlugins.map((plugin) => (
					<MigrationPluginRow
						key={plugin.slug}
						slug={plugin.slug}
						name={plugin.name}
						logo={plugin.logo}
						detection={detected[plugin.slug] || null}
						alreadyMigrated={migratedSlugs.has(plugin.slug)}
						onImportStart={() => setActiveSlug(plugin.slug)}
						onImportComplete={() =>
							handlePluginMigrated(plugin.slug)
						}
						onDeactivated={handleDeactivated}
						onBusyChange={onBusyChange}
					/>
				))}
			</div>
		</>
	);
}
