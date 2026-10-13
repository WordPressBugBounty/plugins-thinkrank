/**
 * Migrate Progress Component
 *
 * Progress bar for Phase B (migrate) with type label, chunk/total,
 * processed/skipped counts, and percentage. Uses ThinkRank design tokens.
 *
 * @package
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

const TYPE_LABELS = {
	postmeta: __('Post Meta', 'thinkrank'),
	termmeta: __('Term Meta', 'thinkrank'),
	content_blocks: __('FAQ & HowTo Blocks', 'thinkrank'),
	settings: __('Settings', 'thinkrank'),
};

/**
 * Migrate Progress
 *
 * @param {Object} props          Component props
 * @param {Object} props.progress Progress state from useImportWorkflow
 */
const MigrateProgress = ({ progress }) => {
	const {
		currentType,
		currentPage,
		totalPages,
		percentage,
		processed,
		skipped,
		failed,
		keywordsTruncated,
	} = progress;

	const stats = [
		`${__('Processed', 'thinkrank')}: ${processed.toLocaleString()}`,
	];
	if (skipped > 0) {
		stats.push(
			`${__('Skipped', 'thinkrank')}: ${skipped.toLocaleString()}`
		);
	}
	if (failed > 0) {
		stats.push(
			`${__('Left unchanged', 'thinkrank')}: ${failed.toLocaleString()}`
		);
	}
	if (keywordsTruncated > 0) {
		stats.push(
			`${__('Pro-only keywords', 'thinkrank')}: ${keywordsTruncated.toLocaleString()}`
		);
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-2">
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
				<span className="tr-text-sm tr-text-secondary">
					{__('Restoring', 'thinkrank')}
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
						{__('Chunk', 'thinkrank')} {currentPage}{' '}
						{__('of', 'thinkrank')} {totalPages}
					</span>
				)}
			</div>
			<div className="tr-h-2 tr-rounded-full tr-bg-line-2 tr-overflow-hidden">
				<div
					className="tr-h-full tr-rounded-full tr-bg-success"
					style={{
						width: `${percentage}%`,
						transition: 'width 0.3s ease',
					}}
				/>
			</div>
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
				<span className="tr-text-xs tr-text-tertiary">
					{stats.join(' · ')}
				</span>
				<span className="tr-text-xs tr-font-medium tr-text-tertiary">
					{percentage}%
				</span>
			</div>
		</div>
	);
};

export default MigrateProgress;
