/**
 * Snapshot Info Component
 *
 * Displays snapshot metadata: export date, record counts per type,
 * last migrated date, and not-yet-migrated extended data categories.
 * Styled with ThinkRank design tokens.
 *
 * @package
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

import { Badge } from '../ui';

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
 * Map a snapshot status string to a badge config.
 *
 * @param {string} status Snapshot status.
 * @return {{variant: string, label: string}} Badge config.
 */
const getStatusBadge = (status) => {
	switch (status) {
		case 'complete':
			return { variant: 'good', label: __('Complete', 'thinkrank') };
		case 'migrated':
			return { variant: 'info', label: __('Migrated', 'thinkrank') };
		case 'partial':
			return { variant: 'warning', label: __('Partial', 'thinkrank') };
		case 'exporting':
			return { variant: 'neutral', label: __('Exporting…', 'thinkrank') };
		default:
			return {
				variant: 'neutral',
				label: status || __('Unknown', 'thinkrank'),
			};
	}
};

/**
 * Format a date string for display.
 *
 * @param {string} dateStr ISO date string.
 * @return {string} Formatted date.
 */
const formatDate = (dateStr) => {
	if (!dateStr) {
		return __('Never', 'thinkrank');
	}
	try {
		return new Date(dateStr).toLocaleDateString(undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		});
	} catch {
		return dateStr;
	}
};

/**
 * A single key/value metadata row.
 *
 * @param {Object} props       Props.
 * @param {string} props.label Row label.
 * @param {*}      props.value Row value.
 * @return {JSX.Element} Row.
 */
const MetaRow = ({ label, value }) => (
	<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-text-sm">
		<span className="tr-text-tertiary">{label}</span>
		<span className="tr-text-secondary tr-font-medium tr-text-right">
			{value}
		</span>
	</div>
);

/**
 * Snapshot Info
 *
 * @param {Object} props          Component props
 * @param {Object} props.manifest Snapshot manifest data
 */
const SnapshotInfo = ({ manifest }) => {
	if (!manifest) {
		return null;
	}

	const types = manifest.types || {};
	const typeEntries = Object.entries(types);
	const extendedTypes = ['usermeta', 'redirections'];
	const hasExtendedData = extendedTypes.some(
		(t) => types[t]?.total_records > 0
	);
	const status = getStatusBadge(manifest.status);

	return (
		<div className="tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-p-4 tr-flex tr-flex-col tr-gap-4">
			{/* Header: title + status badge */}
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
				<p className="tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary tr-m-0">
					{__('Snapshot details', 'thinkrank')}
				</p>
				<Badge variant={status.variant}>{status.label}</Badge>
			</div>

			{/* Dates */}
			<div className="tr-flex tr-flex-col tr-gap-1.5">
				<MetaRow
					label={__('Exported', 'thinkrank')}
					value={formatDate(manifest.exported_at)}
				/>
				<MetaRow
					label={__('Last migrated', 'thinkrank')}
					value={formatDate(manifest.last_migrated)}
				/>
			</div>

			{/* Record counts as scannable stat tiles */}
			{typeEntries.length > 0 && (
				<div className="tr-grid tr-grid-cols-2 tr-gap-2">
					{typeEntries.map(([type, info]) => (
						<div
							key={type}
							className="tr-rounded-md tr-bg-[var(--tr-bg-subtle)] tr-px-3 tr-py-2"
						>
							<div className="tr-text-base tr-font-semibold tr-text-primary tr-leading-tight">
								{(info.total_records || 0).toLocaleString()}
							</div>
							<div className="tr-text-xs tr-text-tertiary tr-mt-0.5">
								{TYPE_LABELS[type] || type}
							</div>
						</div>
					))}
				</div>
			)}

			{hasExtendedData && (
				<div className="tr-rounded-md tr-bg-warning-bg tr-text-warning tr-p-3 tr-text-sm">
					<p className="tr-font-semibold tr-m-0 tr-mb-1">
						{__('Not yet migratable', 'thinkrank')}
					</p>
					<ul className="tr-m-0 tr-pl-5 tr-flex tr-flex-col tr-gap-0.5">
						{types.usermeta?.total_records > 0 && (
							<li>
								{types.usermeta.total_records}{' '}
								{__('author profiles', 'thinkrank')}
							</li>
						)}
						{types.redirections?.total_records > 0 && (
							<li>
								{types.redirections.total_records}{' '}
								{__('redirections', 'thinkrank')}
							</li>
						)}
					</ul>
				</div>
			)}
		</div>
	);
};

/**
 * Get extended data descriptions for the snapshot deletion dialog.
 *
 * @param {Object} manifest Snapshot manifest.
 * @return {string[]} Array of extended data descriptions.
 */
export const getExtendedDataDescriptions = (manifest) => {
	const descriptions = [];
	const types = manifest?.types || {};

	if (types.usermeta?.total_records > 0) {
		descriptions.push(`${types.usermeta.total_records} author profiles`);
	}
	if (types.redirections?.total_records > 0) {
		descriptions.push(`${types.redirections.total_records} redirections`);
	}

	// Always mention these since extended data within postmeta chunks may
	// contain them.
	descriptions.push('Advanced robots meta (max-snippet, max-image-preview)');
	descriptions.push('Additional focus keywords');

	return descriptions;
};

export default SnapshotInfo;
