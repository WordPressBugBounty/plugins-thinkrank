/**
 * Keywords Tab Component
 *
 * Displays a table of keyword performance metrics from Google Search Console.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { EmptyState } from '../ui';

const KeywordsTab = ({ data }) => {
	const [sortConfig, setSortConfig] = useState({
		key: 'clicks',
		direction: 'descending',
	});
	const [currentPage, setCurrentPage] = useState(1);
	const itemsPerPage = 10;

	const keywords = useMemo(() => {
		if (!data || !data.rows) return [];
		return data.rows;
	}, [data]);

	const sortedKeywords = useMemo(() => {
		const sortableItems = [...keywords];
		if (sortConfig.key) {
			sortableItems.sort((a, b) => {
				const aValue =
					sortConfig.key === 'keyword'
						? a.keys?.[0] || ''
						: a[sortConfig.key];
				const bValue =
					sortConfig.key === 'keyword'
						? b.keys?.[0] || ''
						: b[sortConfig.key];
				if (aValue < bValue)
					return sortConfig.direction === 'ascending' ? -1 : 1;
				if (aValue > bValue)
					return sortConfig.direction === 'ascending' ? 1 : -1;
				return 0;
			});
		}
		return sortableItems;
	}, [keywords, sortConfig]);

	const paginatedKeywords = useMemo(() => {
		const start = (currentPage - 1) * itemsPerPage;
		return sortedKeywords.slice(start, start + itemsPerPage);
	}, [sortedKeywords, currentPage]);

	const totalPages = Math.ceil(keywords.length / itemsPerPage);
	const startItem =
		keywords.length === 0 ? 0 : (currentPage - 1) * itemsPerPage + 1;
	const endItem = Math.min(currentPage * itemsPerPage, keywords.length);

	const pageNumbers = useMemo(() => {
		if (totalPages <= 1) return [];
		const pages = [];
		const siblings = 1;
		const showLeftDots = currentPage - siblings > 2;
		const showRightDots = currentPage + siblings < totalPages - 1;

		pages.push(1);
		if (showLeftDots) {
			pages.push('left-dots');
		}
		const start = Math.max(2, currentPage - siblings);
		const end = Math.min(totalPages - 1, currentPage + siblings);
		for (let i = start; i <= end; i++) {
			pages.push(i);
		}
		if (showRightDots) {
			pages.push('right-dots');
		}
		if (totalPages > 1) {
			pages.push(totalPages);
		}
		return pages;
	}, [currentPage, totalPages]);

	const requestSort = (key) => {
		setSortConfig((prev) => ({
			key,
			direction:
				prev.key === key && prev.direction === 'ascending'
					? 'descending'
					: 'ascending',
		}));
		setCurrentPage(1);
	};

	const SortArrow = ({ col }) => {
		if (sortConfig.key !== col) return null;
		return (
			<span
				style={{
					marginLeft: '4px',
					fontSize: '10px',
					color: 'var(--tr-accent)',
				}}
			>
				{sortConfig.direction === 'ascending' ? '▲' : '▼'}
			</span>
		);
	};

	if (!data || !data.rows || data.rows.length === 0) {
		return (
			<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-overflow-hidden">
				<div className="tr-px-6 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
						{__('Top Keywords', 'thinkrank')}
					</h3>
				</div>
				<EmptyState
					size="md"
					title={__('No Keywords Yet', 'thinkrank')}
					description={__(
						'No keyword data available for the selected date range. Try widening the period or check back after more search data is collected.',
						'thinkrank'
					)}
				/>
			</div>
		);
	}

	const thStyle = {
		padding: '14px 24px',
		textAlign: 'left',
		fontWeight: '600',
		color: 'var(--tr-text-heading)',
		fontSize: '14px',
		cursor: 'pointer',
		userSelect: 'none',
		whiteSpace: 'nowrap',
	};

	const tdStyle = {
		padding: '14px 24px',
		color: 'var(--tr-text-heading)',
		fontSize: '14px',
		borderBottom: '1px solid #f0f0f1',
		whiteSpace: 'nowrap',
	};

	return (
		<div
			style={{
				border: '1px solid var(--tr-border)',
				borderRadius: '8px',
				background: '#fff',
				overflow: 'hidden',
			}}
		>
			{/* Header */}
			<div
				style={{
					padding: '20px 24px',
					borderBottom: '1px solid #e2e4e7',
				}}
			>
				<span
					style={{
						fontWeight: '700',
						fontSize: '18px',
						color: 'var(--tr-text-heading)',
					}}
				>
					{__('Top Keywords', 'thinkrank')}
				</span>
			</div>

			{/* Table */}
			<div style={{ position: 'relative', overflowX: 'auto' }}>
				<table
					style={{
						width: '100%',
						borderCollapse: 'collapse',
						fontSize: '14px',
					}}
				>
					<thead>
						<tr style={{ borderBottom: '1px solid #e2e4e7' }}>
							<th
								style={thStyle}
								onClick={() => requestSort('keyword')}
							>
								{__('Keyword', 'thinkrank')}
								<SortArrow col="keyword" />
							</th>
							<th
								style={thStyle}
								onClick={() => requestSort('clicks')}
							>
								{__('Clicks', 'thinkrank')}
								<SortArrow col="clicks" />
							</th>
							<th
								style={thStyle}
								onClick={() => requestSort('impressions')}
							>
								{__('Impressions', 'thinkrank')}
								<SortArrow col="impressions" />
							</th>
							<th
								style={thStyle}
								onClick={() => requestSort('ctr')}
							>
								{__('CTR', 'thinkrank')}
								<SortArrow col="ctr" />
							</th>
							<th
								style={thStyle}
								onClick={() => requestSort('position')}
							>
								{__('Position', 'thinkrank')}
								<SortArrow col="position" />
							</th>
						</tr>
					</thead>
					<tbody>
						{paginatedKeywords.map((row, index) => (
							<tr key={index}>
								<td
									style={{
										...tdStyle,
										maxWidth: '420px',
										whiteSpace: 'nowrap',
										overflow: 'hidden',
									}}
								>
									<div
										style={{
											display: 'flex',
											alignItems: 'center',
											gap: '6px',
											overflow: 'hidden',
										}}
									>
										<span
											style={{
												overflow: 'hidden',
												textOverflow: 'ellipsis',
												whiteSpace: 'nowrap',
											}}
										>
											{row.keys?.[0] ||
												__('(unknown)', 'thinkrank')}
										</span>
										<a
											href={`https://www.google.com/search?q=${encodeURIComponent(row.keys?.[0] || '')}`}
											target="_blank"
											rel="noopener noreferrer"
											style={{
												color: '#9ca3af',
												textDecoration: 'none',
												flexShrink: 0,
												display: 'flex',
												alignItems: 'center',
											}}
										>
											<svg
												width="12"
												height="12"
												viewBox="0 0 12 12"
												fill="none"
												xmlns="http://www.w3.org/2000/svg"
											>
												<path
													d="M2 10L10 2M10 2H5M10 2V7"
													stroke="#9ca3af"
													strokeWidth="1.5"
													strokeLinecap="round"
													strokeLinejoin="round"
												/>
											</svg>
										</a>
									</div>
								</td>
								<td style={tdStyle}>
									{Number(row.clicks).toLocaleString()}
								</td>
								<td style={tdStyle}>
									{Number(row.impressions).toLocaleString()}
								</td>
								<td style={tdStyle}>
									{(row.ctr * 100).toFixed(2)}%
								</td>
								<td style={tdStyle}>
									{Number(row.position).toFixed(1)}
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>

			{/* Pagination footer */}
			<div
				style={{
					padding: '16px 24px',
					borderTop: '1px solid #e2e4e7',
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
				}}
			>
				<span
					style={{
						fontSize: '14px',
						color: 'var(--tr-text-heading)',
						fontWeight: '500',
					}}
				>
					{__('Showing', 'thinkrank')} {startItem}-{endItem}{' '}
					{__('of', 'thinkrank')} {keywords.length}{' '}
					{__('keywords', 'thinkrank')}
				</span>
				<div
					style={{
						display: 'flex',
						gap: '6px',
						alignItems: 'center',
						flexWrap: 'wrap',
					}}
				>
					<Button
						variant="secondary"
						disabled={currentPage === 1}
						onClick={() =>
							setCurrentPage((prev) => Math.max(prev - 1, 1))
						}
					>
						{__('Previous', 'thinkrank')}
					</Button>
					{pageNumbers.map((page, idx) => {
						if (page === 'left-dots' || page === 'right-dots') {
							return (
								<span
									key={`${page}-${idx}`}
									style={{
										padding: '0 6px',
										color: 'var(--tr-text-muted)',
										fontSize: '14px',
									}}
								>
									…
								</span>
							);
						}
						const isActive = page === currentPage;
						return (
							<Button
								key={page}
								variant={isActive ? 'primary' : 'secondary'}
								aria-current={isActive ? 'page' : undefined}
								onClick={() => setCurrentPage(page)}
								style={{
									minWidth: '36px',
									justifyContent: 'center',
								}}
							>
								{page}
							</Button>
						);
					})}
					<Button
						variant="secondary"
						disabled={
							currentPage === totalPages || totalPages === 0
						}
						onClick={() =>
							setCurrentPage((prev) =>
								Math.min(prev + 1, totalPages)
							)
						}
					>
						{__('Next', 'thinkrank')}
					</Button>
				</div>
			</div>
		</div>
	);
};

export default KeywordsTab;
