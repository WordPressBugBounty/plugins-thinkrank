/**
 * History Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * The submission log: what was sent to IndexNow, when, and how the API replied.
 * Coverage (above) answers "what still needs sending?"; this answers "what did
 * we send?". Status uses shared Badge tokens, response codes carry the reason as
 * a tooltip, and an empty log offers a shortcut to the Submit URLs screen.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { SkeletonCard, Card, Button, Badge, EmptyState } from '../../ui';
import SettingsCard from '../../common/SettingsCard';
import apiFetch from '@wordpress/api-fetch';
import { toast } from 'react-toastify';
import logger from '../../../../shared/utils/logger';
import { purgeApiCache } from '../../../utils/apiCache';
import Coverage from './Coverage';

const IconChevronDown = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="6 9 12 15 18 9" />
	</svg>
);

const IconChevronUp = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="18 15 12 9 6 15" />
	</svg>
);

const IconRefresh = ({ className }) => (
	<svg
		className={className}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="23 4 23 10 17 10" />
		<polyline points="1 20 1 14 7 14" />
		<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
	</svg>
);

const IconInbox = ({ className }) => (
	<svg
		className={className}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
		<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
	</svg>
);

const RESPONSE_CODE_HELP = [
	{
		code: 200,
		message: 'OK',
		reason: __(
			'The URL was successfully submitted to the IndexNow API.',
			'thinkrank'
		),
	},
	{
		code: 202,
		message: 'Accepted',
		reason: __(
			'The URL was successfully submitted to the IndexNow API, but the API key will be checked later.',
			'thinkrank'
		),
	},
	{
		code: 400,
		message: 'Bad Request',
		reason: __('The request was invalid.', 'thinkrank'),
	},
	{
		code: 403,
		message: 'Forbidden',
		reason: __(
			'The key was invalid (e.g. key not found, file found but key not in the file).',
			'thinkrank'
		),
	},
	{
		code: 422,
		message: 'Unprocessable Entity',
		reason: __(
			"The URLs don't belong to the host or the key is not matching the schema in the protocol.",
			'thinkrank'
		),
	},
	{
		code: 429,
		message: 'Too Many Requests',
		reason: __('Too Many Requests (potential Spam).', 'thinkrank'),
	},
];

// Quick lookup so a row can explain its response code on hover.
const REASON_BY_CODE = RESPONSE_CODE_HELP.reduce((acc, row) => {
	acc[row.code] = row.reason;
	return acc;
}, {});

const statusVariant = (status) => {
	if (status === 'success') {
		return 'good';
	}
	// Backend stores 'failed' (not 'error') for failures — treat both as red.
	if (status === 'failed' || status === 'error') {
		return 'poor';
	}
	return 'neutral';
};

const History = ({ onNavigate }) => {
	const [history, setHistory] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [isClearing, setIsClearing] = useState(false);
	const [currentPage, setCurrentPage] = useState(1);
	const [isHelpOpen, setIsHelpOpen] = useState(false);
	const itemsPerPage = 10;
	const [totalItems, setTotalItems] = useState(0);
	// Numbers each history load. Pages can be flipped faster than the server
	// answers, and only the newest answer may reach the table: a late page 2
	// used to land on top of page 3.
	const loadSeq = useRef(0);

	// Leaving the screen orphans any answer still in flight.
	useEffect(
		() => () => {
			loadSeq.current++;
		},
		[]
	);

	const fetchHistory = (page = currentPage, notify = false) => {
		const seq = ++loadSeq.current;
		setIsLoading(true);
		// Server-side pagination: request only the current page.
		apiFetch({
			path: `/thinkrank/v1/instant-indexing/history?page=${page}&per_page=${itemsPerPage}`,
		})
			.then((response) => {
				if (seq !== loadSeq.current) {
					return; // Superseded by a newer load.
				}
				if (response.success && Array.isArray(response.data)) {
					setHistory(response.data);
					setTotalItems(
						response.pagination?.total ?? response.data.length
					);
				} else {
					setHistory([]);
					setTotalItems(0);
				}
				setIsLoading(false);
				if (notify) {
					toast.success(
						__('Submission history refreshed.', 'thinkrank')
					);
				}
			})
			.catch((error) => {
				if (seq !== loadSeq.current) {
					return;
				}
				logger.error('Error fetching history:', error);
				setIsLoading(false);
				if (notify) {
					toast.error(__('Failed to refresh history.', 'thinkrank'));
				}
			});
	};

	// Refresh must show newly submitted URLs. The browser cache serves the
	// history GET for up to 5 minutes, so purge it first or the button just
	// re-renders the same cached page.
	const handleRefresh = () => {
		purgeApiCache();
		fetchHistory(currentPage, true);
	};

	const handleCleanHistory = () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Are you sure you want to clear all history? This action cannot be undone.',
				'thinkrank'
			)
		);
		if (!confirmed) {
			return;
		}

		setIsClearing(true);
		// A load already in flight was read before the delete and would put
		// the cleared rows back, so it is made stale here.
		loadSeq.current++;
		apiFetch({
			path: '/thinkrank/v1/instant-indexing/history',
			method: 'DELETE',
		})
			.then(() => {
				setIsClearing(false);
				// One load of page 1: changing the page already triggers it,
				// and calling fetchHistory() as well started two.
				if (currentPage !== 1) {
					setCurrentPage(1);
				} else {
					fetchHistory(1);
				}
			})
			.catch((error) => {
				logger.error('Error clearing history:', error);
				toast.error(
					__(
						'Failed to clear history. Please try again.',
						'thinkrank'
					)
				);
				setIsClearing(false);
			});
	};

	// Refetch whenever the page changes (server-side pagination).
	useEffect(() => {
		fetchHistory(currentPage);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [currentPage]);

	const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));
	// The server already returns just this page.
	const paginatedHistory = history;
	const isEmpty = !isLoading && paginatedHistory.length === 0;

	const goToSubmit = () => {
		if (typeof onNavigate === 'function') {
			onNavigate('instant-indexing', 'instant-indexing-submit-urls');
		}
	};

	return (
		<div className="thinkrank-instant-indexing-history">
			<SettingsCard
				title={__('Submission History', 'thinkrank')}
				description={__(
					'View the history of URLs submitted to IndexNow.',
					'thinkrank'
				)}
				enabled={true}
				showToggle={false}
			>
				<div className="tr-flex tr-flex-col tr-gap-4">
					{/* ── Coverage ──────────────────────────────────────────── */}
					<Coverage />

					{/* ── History Table ─────────────────────────────────────── */}
					<Card padding={false}>
						<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
							<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap">
								<div>
									<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
										{__('URL Submissions', 'thinkrank')}
									</h3>
									{totalItems > 0 && (
										<p className="tr-text-xs tr-text-tertiary tr-mt-1 tr-mb-0">
											{sprintf(
												/* translators: %d: total number of submissions */
												_n(
													'%d submission logged',
													'%d submissions logged',
													totalItems,
													'thinkrank'
												),
												totalItems
											)}
										</p>
									)}
								</div>
								<div className="tr-flex tr-items-center tr-gap-2">
									<Button
										variant="secondary"
										size="sm"
										icon={IconRefresh}
										onClick={handleRefresh}
										loading={isLoading}
										disabled={isLoading || isClearing}
									>
										{__('Refresh', 'thinkrank')}
									</Button>
									<Button
										variant="secondary"
										size="sm"
										onClick={handleCleanHistory}
										loading={isClearing}
										disabled={
											isLoading ||
											isClearing ||
											history.length === 0
										}
									>
										{__('Clean History', 'thinkrank')}
									</Button>
								</div>
							</div>
						</div>

						{isLoading && (
							<div className="tr-p-5">
								<SkeletonCard lines={3} />
							</div>
						)}

						{!isLoading && isEmpty && (
							<EmptyState
								icon={IconInbox}
								title={__('No submissions yet', 'thinkrank')}
								description={__(
									'URLs you send to IndexNow, manually or automatically on publish, will appear here.',
									'thinkrank'
								)}
								action={
									<Button
										variant="primary"
										onClick={goToSubmit}
									>
										{__('Submit URLs', 'thinkrank')}
									</Button>
								}
							/>
						)}

						{!isLoading && !isEmpty && (
							<div className="tr-relative tr-overflow-x-auto">
								<table className="tr-w-full tr-text-sm tr-border-collapse">
									<thead>
										<tr className="tr-bg-[#FAFBFC] tr-border-b tr-border-secondary">
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__('URL', 'thinkrank')}
											</th>
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__('Status', 'thinkrank')}
											</th>
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__('Response', 'thinkrank')}
											</th>
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__('Time', 'thinkrank')}
											</th>
										</tr>
									</thead>
									<tbody>
										{paginatedHistory.map((item) => (
											<tr
												key={item.id}
												className="tr-border-b tr-border-secondary last:tr-border-b-0 hover:tr-bg-[#FAFBFC]"
											>
												<td className="tr-px-4 tr-py-3 tr-max-w-xs tr-truncate">
													<a
														href={item.url}
														target="_blank"
														rel="noopener noreferrer"
														title={item.url}
														className="tr-text-brand-600 hover:tr-text-brand-700 tr-no-underline"
													>
														{item.url}
													</a>
												</td>
												<td className="tr-px-4 tr-py-3">
													<Badge
														variant={statusVariant(
															item.status
														)}
													>
														{String(
															item.status
														).toUpperCase()}
													</Badge>
												</td>
												<td
													className="tr-px-4 tr-py-3 tr-text-secondary"
													title={
														REASON_BY_CODE[
															item.response_code
														] || undefined
													}
												>
													<span className="tr-font-mono tr-font-semibold">
														{item.response_code}
													</span>
													{item.response_message &&
														`: ${item.response_message}`}
												</td>
												<td className="tr-px-4 tr-py-3 tr-text-tertiary tr-whitespace-nowrap">
													{item.created_at}
												</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>
						)}

						{!isEmpty && totalPages > 1 && (
							<div className="tr-flex tr-items-center tr-justify-center tr-gap-3 tr-px-5 tr-py-4 tr-border-t tr-border-secondary">
								<Button
									variant="secondary"
									size="sm"
									onClick={() =>
										setCurrentPage((prev) =>
											Math.max(prev - 1, 1)
										)
									}
									disabled={
										currentPage === 1 ||
										isLoading ||
										isClearing
									}
								>
									{__('Previous', 'thinkrank')}
								</Button>
								<span className="tr-text-sm tr-text-secondary">
									{sprintf(
										/* translators: 1: Current page number, 2: Total pages */
										__('Page %1$s of %2$s', 'thinkrank'),
										currentPage,
										totalPages
									)}
								</span>
								<Button
									variant="secondary"
									size="sm"
									onClick={() =>
										setCurrentPage((prev) =>
											Math.min(prev + 1, totalPages)
										)
									}
									disabled={
										currentPage === totalPages ||
										isLoading ||
										isClearing
									}
								>
									{__('Next', 'thinkrank')}
								</Button>
							</div>
						)}
					</Card>

					{/* ── Response Code Help ────────────────────────────────── */}
					<Card padding={false}>
						<div
							className={`tr-px-5 tr-py-4 ${
								isHelpOpen
									? 'tr-border-b tr-border-secondary'
									: ''
							}`}
						>
							<div className="tr-flex tr-items-center tr-justify-between">
								<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
									{__('Response Code Help', 'thinkrank')}
								</h3>
								<button
									onClick={() => setIsHelpOpen(!isHelpOpen)}
									className="tr-flex tr-items-center tr-gap-1 tr-text-sm tr-text-tertiary tr-bg-transparent tr-border-0 tr-cursor-pointer tr-p-1"
									style={{ background: 'none' }}
									aria-label={
										isHelpOpen
											? __('Collapse Help', 'thinkrank')
											: __('Expand Help', 'thinkrank')
									}
								>
									{isHelpOpen ? (
										<IconChevronUp />
									) : (
										<IconChevronDown />
									)}
								</button>
							</div>
						</div>
						{isHelpOpen && (
							<div className="tr-relative tr-overflow-x-auto">
								<table className="tr-w-full tr-text-sm tr-border-collapse">
									<thead>
										<tr className="tr-bg-[#FAFBFC] tr-border-b tr-border-secondary">
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__(
													'Response Code',
													'thinkrank'
												)}
											</th>
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__(
													'Response Message',
													'thinkrank'
												)}
											</th>
											<th className="tr-text-left tr-px-4 tr-py-3 tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide">
												{__('Reasons', 'thinkrank')}
											</th>
										</tr>
									</thead>
									<tbody>
										{RESPONSE_CODE_HELP.map((row) => (
											<tr
												key={row.code}
												className="tr-border-b tr-border-secondary last:tr-border-b-0"
											>
												<td className="tr-px-4 tr-py-3 tr-font-mono tr-font-semibold tr-text-secondary">
													{row.code}
												</td>
												<td className="tr-px-4 tr-py-3 tr-text-secondary">
													{row.message}
												</td>
												<td className="tr-px-4 tr-py-3 tr-text-tertiary">
													{row.reason}
												</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>
						)}
					</Card>
				</div>
			</SettingsCard>
		</div>
	);
};

export default History;
