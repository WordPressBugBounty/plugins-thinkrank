/**
 * IndexNow Coverage Component
 *
 * Shows which published URLs IndexNow actually knows about, split into current,
 * stale, failed and never-submitted, and lets an admin run a reconciliation pass
 * without waiting for the daily cron.
 *
 * The submission history answers "what did we send?". This answers the question
 * that actually matters — "what did we fail to send?" — which a flat log cannot,
 * because the gaps are precisely the rows that were never written.
 *
 * @package
 * @since 1.31.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SkeletonCard, Card, Button } from '../../ui';
import apiFetch from '@wordpress/api-fetch';
import { toast } from 'react-toastify';
import logger from '../../../../shared/utils/logger';

/**
 * Display metadata per coverage bucket, in the order they should be read:
 * problems first, healthy last.
 */
const BUCKETS = [
	{
		key: 'stale',
		label: __('Stale', 'thinkrank'),
		help: __(
			'Edited after the last successful submission, so search engines have not been told about the newer content.',
			'thinkrank'
		),
		tone: 'tr-text-amber-600',
	},
	{
		key: 'failed',
		label: __('Failed', 'thinkrank'),
		help: __(
			'The most recent submission was rejected and nothing has succeeded since the last edit.',
			'thinkrank'
		),
		tone: 'tr-text-red-600',
	},
	{
		key: 'never_submitted',
		label: __('Never submitted', 'thinkrank'),
		help: __(
			'Published but never sent to IndexNow, typically edited while the feature was off.',
			'thinkrank'
		),
		tone: 'tr-text-red-600',
	},
	{
		key: 'current',
		label: __('Current', 'thinkrank'),
		help: __(
			'Successfully submitted at or after the last edit. Nothing to do.',
			'thinkrank'
		),
		tone: 'tr-text-emerald-600',
	},
];

/**
 * IndexNow coverage panel.
 * @param {Object} props           Component props.
 * @param {string} props.className Optional wrapper class.
 */
const Coverage = ({ className = '' }) => {
	const [isLoading, setIsLoading] = useState(true);
	const [isReconciling, setIsReconciling] = useState(false);
	const [report, setReport] = useState(null);
	const [expanded, setExpanded] = useState(null);

	useEffect(() => {
		loadCoverage();
	}, []);

	/**
	 * Fetch the current coverage report.
	 */
	const loadCoverage = async () => {
		try {
			setIsLoading(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/instant-indexing/coverage',
				method: 'GET',
			});

			setReport(response.success ? response.data : null);
		} catch (error) {
			logger.error('Failed to load IndexNow coverage:', error);
			setReport(null);
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Run a reconciliation pass now, then refresh the report.
	 */
	const handleReconcile = async () => {
		try {
			setIsReconciling(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/instant-indexing/reconcile',
				method: 'POST',
				data: { dry_run: false },
			});

			if (response.success && response.data?.ran) {
				toast.success(
					sprintf(
						/* translators: 1: URLs examined, 2: URLs resubmitted. */
						__(
							'Reconciliation complete: %1$d URLs checked, %2$d resubmitted.',
							'thinkrank'
						),
						response.data.examined,
						response.data.retried
					)
				);
			} else {
				toast.info(
					response.data?.reason ||
						__('Nothing to reconcile.', 'thinkrank')
				);
			}

			await loadCoverage();
		} catch (error) {
			logger.error('Reconciliation failed:', error);
			toast.error(__('Reconciliation failed.', 'thinkrank'));
		} finally {
			setIsReconciling(false);
		}
	};

	const counts = report?.counts || {};
	const needsAttention =
		(counts.stale || 0) +
		(counts.failed || 0) +
		(counts.never_submitted || 0);

	return (
		<Card padding={false} className={className}>
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
				<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap">
					<div>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Submission Coverage', 'thinkrank')}
						</h3>
						<p className="tr-text-xs tr-text-tertiary tr-mt-1 tr-mb-0">
							{__(
								'Compares every published URL against the last successful IndexNow submission. Runs daily on its own.',
								'thinkrank'
							)}
						</p>
					</div>
					<Button
						variant="secondary"
						size="sm"
						onClick={handleReconcile}
						loading={isReconciling}
						disabled={isLoading || isReconciling}
					>
						{__('Reconcile Now', 'thinkrank')}
					</Button>
				</div>
			</div>

			{isLoading && (
				<div className="tr-p-5">
					<SkeletonCard lines={2} />
				</div>
			)}

			{!isLoading && !report && (
				<div className="tr-p-5 tr-text-sm tr-text-tertiary">
					{__('Coverage data is unavailable.', 'thinkrank')}
				</div>
			)}

			{!isLoading && report && (
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					{!report.enabled && (
						<div className="tr-text-xs tr-text-amber-700 tr-bg-amber-50 tr-rounded tr-px-3 tr-py-2">
							{__(
								'Instant Indexing is turned off, so nothing is being submitted automatically. The figures below show what would need sending once it is enabled.',
								'thinkrank'
							)}
						</div>
					)}

					<div className="tr-grid tr-grid-cols-2 md:tr-grid-cols-4 tr-gap-3">
						{BUCKETS.map((bucket) => {
							const count = counts[bucket.key] || 0;
							const urls = report.urls?.[bucket.key] || [];
							const isOpen = expanded === bucket.key;

							return (
								<button
									key={bucket.key}
									type="button"
									title={bucket.help}
									onClick={() =>
										setExpanded(isOpen ? null : bucket.key)
									}
									className={`tr-text-left tr-rounded tr-border tr-border-secondary tr-px-4 tr-py-3 tr-bg-white ${
										isOpen
											? 'tr-ring-1 tr-ring-brand-500'
											: ''
									} ${urls.length ? 'tr-cursor-pointer' : 'tr-cursor-default'}`}
								>
									<div
										className={`tr-text-xl tr-font-semibold ${bucket.tone}`}
									>
										{count}
									</div>
									<div className="tr-text-xs tr-text-secondary tr-mt-1">
										{bucket.label}
									</div>
								</button>
							);
						})}
					</div>

					{needsAttention === 0 && (
						<p className="tr-text-xs tr-text-tertiary tr-m-0">
							{__(
								'Every checked URL has been announced since its last edit.',
								'thinkrank'
							)}
						</p>
					)}

					{expanded && (report.urls?.[expanded] || []).length > 0 && (
						<div className="tr-border tr-border-secondary tr-rounded tr-divide-y tr-divide-secondary">
							{(report.urls[expanded] || []).map((item) => (
								<div
									key={item.post_id}
									className="tr-px-4 tr-py-2 tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap"
								>
									<a
										href={item.url}
										target="_blank"
										rel="noopener noreferrer"
										className="tr-text-xs tr-text-brand-600 tr-break-all"
									>
										{item.url}
									</a>
									<span className="tr-text-xs tr-text-tertiary tr-whitespace-nowrap">
										{item.last_success
											? sprintf(
													/* translators: %s: date of the last successful submission. */
													__(
														'last sent %s',
														'thinkrank'
													),
													item.last_success
												)
											: __('never sent', 'thinkrank')}
									</span>
								</div>
							))}
						</div>
					)}

					{/* Never present a partial pass as a whole-site audit. */}
					{report.truncated && (
						<p className="tr-text-xs tr-text-tertiary tr-m-0">
							{sprintf(
								/* translators: 1: URLs checked, 2: total published URLs. */
								__(
									'Showing the first %1$d of %2$d published URLs. The scheduled run works through the rest in batches.',
									'thinkrank'
								),
								report.examined,
								report.total
							)}
						</p>
					)}
				</div>
			)}
		</Card>
	);
};

export default Coverage;
