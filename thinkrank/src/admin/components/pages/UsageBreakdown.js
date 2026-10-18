import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Alert, Button, SkeletonCard, EmptyState } from '../ui';
import ModelBadge from '../shared/ModelBadge';
import logger from '../../../shared/utils/logger';

const UsageBreakdownTable = ({ records, isLoading }) => {
	if (isLoading) {
		return <SkeletonCard lines={6} />;
	}

	if (!records || records.length === 0) {
		return (
			<EmptyState
				size="sm"
				title={__('No Usage Data Yet', 'thinkrank')}
				description={__(
					'No usage records found for the selected period. Start using AI features to see your activity here.',
					'thinkrank'
				)}
			/>
		);
	}

	return (
		<div className="tr-relative tr-overflow-x-auto">
			<table className="tr-w-full tr-text-sm">
				<thead>
					<tr className="tr-border-b tr-border-secondary">
						<th className="tr-text-left tr-py-3 tr-px-4 tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide">
							{__('Date & Time', 'thinkrank')}
						</th>
						<th className="tr-text-left tr-py-3 tr-px-4 tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide">
							{__('Action', 'thinkrank')}
						</th>
						<th className="tr-text-left tr-py-3 tr-px-4 tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide">
							{__('AI Model', 'thinkrank')}
						</th>
						<th className="tr-text-right tr-py-3 tr-px-4 tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide">
							{__('Tokens', 'thinkrank')}
						</th>
						<th className="tr-text-right tr-py-3 tr-px-4 tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide">
							{__('Est. Cost', 'thinkrank')}
						</th>
					</tr>
				</thead>
				<tbody>
					{records.map((record) => (
						<tr
							key={record.id}
							className="tr-border-b tr-border-secondary hover:tr-bg-white tr-transition-colors"
						>
							<td className="tr-py-3 tr-px-4">
								<span className="tr-font-medium tr-text-primary">
									{record.formatted_date}
								</span>
							</td>
							<td className="tr-py-3 tr-px-4 tr-text-secondary">
								{record.action
									.replace(/_/g, ' ')
									.replace(/\b\w/g, (l) => l.toUpperCase())
									.replace(/\bSeo\b/g, 'SEO')}
							</td>
							<td className="tr-py-3 tr-px-4">
								<ModelBadge
									provider={record.provider}
									model={record.model}
								/>
							</td>
							<td className="tr-py-3 tr-px-4 tr-text-right tr-font-mono tr-text-secondary">
								{record.tokens_used.toLocaleString()}
							</td>
							<td className="tr-py-3 tr-px-4 tr-text-right tr-font-mono tr-text-secondary">
								{/* A custom endpoint has no price we know: it may
								    be a free local model or an Azure contract.
								    "$0.0000" would read as "this was free", so an
								    uncosted row shows an em dash until the user
								    enters a per-1M-token rate (#721). */}
								{record.provider === 'openai_compatible' &&
								!record.estimated_cost
									? '—'
									: `$${record.estimated_cost.toFixed(4)}`}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
};

const Pagination = ({ currentPage, totalPages, onPageChange, isLoading }) => {
	if (totalPages <= 1) return null;

	const maxVisible = 5;
	let start = Math.max(1, currentPage - Math.floor(maxVisible / 2));
	const end = Math.min(totalPages, start + maxVisible - 1);
	if (end - start + 1 < maxVisible) {
		start = Math.max(1, end - maxVisible + 1);
	}
	const pages = Array.from({ length: end - start + 1 }, (_, i) => start + i);

	return (
		<div className="tr-flex tr-items-center tr-justify-between tr-mt-6 tr-pt-4 tr-border-t tr-border-secondary">
			<div className="tr-flex tr-items-center tr-gap-1">
				<Button
					variant="secondary"
					size="sm"
					disabled={currentPage === 1 || isLoading}
					onClick={() => onPageChange(currentPage - 1)}
				>
					{__('Previous', 'thinkrank')}
				</Button>
				{pages.map((page) => (
					<Button
						key={page}
						variant={page === currentPage ? 'primary' : 'secondary'}
						size="sm"
						disabled={isLoading}
						onClick={() => onPageChange(page)}
					>
						{page}
					</Button>
				))}
				<Button
					variant="secondary"
					size="sm"
					disabled={currentPage === totalPages || isLoading}
					onClick={() => onPageChange(currentPage + 1)}
				>
					{__('Next', 'thinkrank')}
				</Button>
			</div>
			<span className="tr-text-sm tr-text-tertiary">
				{__('Page', 'thinkrank')} {currentPage} {__('of', 'thinkrank')}{' '}
				{totalPages}
			</span>
		</div>
	);
};

const UsageBreakdown = ({ period = '30d', isActive = true }) => {
	const [currentPage, setCurrentPage] = useState(1);
	const [perPage] = useState(20);
	const [data, setData] = useState(null);
	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState(null);

	const fetchUsageBreakdown = async (
		selectedPeriod = period,
		page = currentPage
	) => {
		setIsLoading(true);
		setError(null);

		try {
			const response = await apiFetch({
				path: `/thinkrank/v1/analytics/usage?period=${selectedPeriod}&page=${page}&per_page=${perPage}`,
				method: 'GET',
			});

			if (response.success) {
				setData(response.data);
			} else {
				setError(
					__('Failed to load usage breakdown data.', 'thinkrank')
				);
			}
		} catch (err) {
			logger.error('Usage breakdown fetch error:', err);
			setError(__('Failed to load usage breakdown data.', 'thinkrank'));
		} finally {
			setIsLoading(false);
		}
	};

	const handlePageChange = (newPage) => {
		setCurrentPage(newPage);
		fetchUsageBreakdown(period, newPage);
	};

	// TabPanel keeps hidden panels mounted, so an unguarded mount effect
	// requested /analytics/usage on every page load even when this tab was
	// never opened — two DB queries (rows plus a COUNT) most users never see.
	// The page-level period also changes underneath us while hidden, so this
	// re-runs on both.
	useEffect(() => {
		if (!isActive) {
			return;
		}
		setCurrentPage(1);
		fetchUsageBreakdown(period, 1);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [isActive, period]);

	return (
		<div>
			<div className="tr-flex tr-justify-between tr-items-center tr-mb-6">
				<h2 className="tr-text-lg tr-font-semibold tr-text-primary tr-m-0">
					{__('AI Usage Breakdown', 'thinkrank')}
				</h2>
			</div>

			{error && (
				<Alert variant="error" className="tr-mb-4">
					{error}
				</Alert>
			)}

			<UsageBreakdownTable
				records={data?.usage_records || []}
				isLoading={isLoading}
			/>

			{data?.pagination && (
				<Pagination
					currentPage={data.pagination.page}
					totalPages={data.pagination.total_pages}
					onPageChange={handlePageChange}
					isLoading={isLoading}
				/>
			)}

			{data?.pagination && (
				<p className="tr-text-xs tr-text-tertiary tr-text-center tr-mt-3 tr-m-0">
					{/* Was "Showing 29 total records" while the table displayed
					    20, assembled from two separately translated fragments
					    whose word order no locale could change. */}
					{sprintf(
						/* translators: 1: first row shown, 2: last row shown, 3: total number of records. */
						_n(
							'Showing %1$d–%2$d of %3$d record',
							'Showing %1$d–%2$d of %3$d records',
							data.pagination.total_records,
							'thinkrank'
						),
						data.pagination.total_records === 0
							? 0
							: (data.pagination.page - 1) *
									data.pagination.per_page +
									1,
						Math.min(
							data.pagination.page * data.pagination.per_page,
							data.pagination.total_records
						),
						data.pagination.total_records
					)}
				</p>
			)}
		</div>
	);
};

export default UsageBreakdown;
