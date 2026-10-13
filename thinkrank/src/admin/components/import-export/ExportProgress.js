/**
 * Export Progress Component
 *
 * Progress bar for Phase A (export) with type label, page/total, percentage.
 * Uses the ThinkRank design tokens so it matches the rest of the admin.
 *
 * @package
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

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
 * Export Progress
 *
 * @param {Object} props          Component props
 * @param {Object} props.progress Progress state from useImportWorkflow
 */
const ExportProgress = ({ progress }) => {
	const { currentType, currentPage, totalPages, percentage, total } =
		progress;

	return (
		<div className="tr-flex tr-flex-col tr-gap-2">
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
				<span className="tr-text-sm tr-text-secondary">
					{__('Exporting', 'thinkrank')}
					{currentType ? (
						<strong className="tr-text-primary">
							{' '}
							{TYPE_LABELS[currentType] || currentType}
						</strong>
					) : (
						'…'
					)}
				</span>
				{currentPage > 0 && totalPages > 0 && (
					<span className="tr-text-xs tr-text-tertiary tr-shrink-0">
						{sprintfPage(currentPage, totalPages, total)}
					</span>
				)}
			</div>
			<div className="tr-h-2 tr-rounded-full tr-bg-line-2 tr-overflow-hidden">
				<div
					className="tr-h-full tr-rounded-full tr-bg-brand"
					style={{
						width: `${percentage}%`,
						transition: 'width 0.3s ease',
					}}
				/>
			</div>
			<span className="tr-text-xs tr-font-medium tr-text-tertiary tr-text-right">
				{percentage}%
			</span>
		</div>
	);
};

/**
 * Build a compact "Page N of M (X items)" label.
 *
 * @param {number} page  Current page.
 * @param {number} pages Total pages.
 * @param {number} total Total items.
 * @return {string} Label.
 */
function sprintfPage(page, pages, total) {
	const base = `${__('Page', 'thinkrank')} ${page} ${__(
		'of',
		'thinkrank'
	)} ${pages}`;
	if (total > 0) {
		return `${base} · ${total.toLocaleString()} ${__(
			'items',
			'thinkrank'
		)}`;
	}
	return base;
}

export default ExportProgress;
