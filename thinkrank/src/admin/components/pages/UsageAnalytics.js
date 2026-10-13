import { __ } from '@wordpress/i18n';
import { getPageUrl } from '../../utils/router';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import {
	Card,
	Container,
	Select,
	Alert,
	Tabs,
	TabPanel,
	SkeletonCard,
	EmptyState,
} from '../ui';
import SectionHeader from '../common/SectionHeader';
import UsageBreakdown from './UsageBreakdown';

const TABS = [
	{ id: 'overview', label: __('Overview', 'thinkrank') },
	{ id: 'usage-breakdown', label: __('Usage Breakdown', 'thinkrank') },
];

const PERIOD_OPTIONS = [
	{ label: __('Last 7 days', 'thinkrank'), value: '7d' },
	{ label: __('Last 30 days', 'thinkrank'), value: '30d' },
	{ label: __('Last 90 days', 'thinkrank'), value: '90d' },
	{ label: __('All time', 'thinkrank'), value: 'all' },
];

const ICONS = {
	posts: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M4.5 3.75A1.75 1.75 0 0 1 6.25 2h5.379a1.75 1.75 0 0 1 1.237.513l3.121 3.121A1.75 1.75 0 0 1 16.5 6.871V16.25A1.75 1.75 0 0 1 14.75 18h-8.5A1.75 1.75 0 0 1 4.5 16.25V3.75ZM12 3.5v3a.5.5 0 0 0 .5.5h3l-3.5-3.5ZM7 10.5a.75.75 0 0 1 .75-.75h5.5a.75.75 0 0 1 0 1.5h-5.5A.75.75 0 0 1 7 10.5Zm0 3a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 0 1.5h-3.5A.75.75 0 0 1 7 13.5Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	sparkles: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path d="M9 2.804a1 1 0 0 0-1.96 0L6.6 4.96a1 1 0 0 1-.785.785L3.66 6.184a1 1 0 0 0 0 1.96l2.155.439a1 1 0 0 1 .785.785l.44 2.155a1 1 0 0 0 1.96 0l.439-2.155a1 1 0 0 1 .785-.785l2.155-.44a1 1 0 0 0 0-1.96l-2.155-.439a1 1 0 0 1-.785-.785L9 2.804ZM15 11a1 1 0 0 0-1.96 0l-.15.75a1 1 0 0 1-.78.78l-.76.16a1 1 0 0 0 0 1.96l.76.15a1 1 0 0 1 .78.78l.16.76a1 1 0 0 0 1.96 0l.15-.76a1 1 0 0 1 .78-.78l.76-.15a1 1 0 0 0 0-1.96l-.76-.16a1 1 0 0 1-.78-.78L15 11Z" />
		</svg>
	),
	target: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm0-2a6 6 0 1 0 0-12 6 6 0 0 0 0 12Zm0-2.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm0-2a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	dollar: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M10 2a.75.75 0 0 1 .75.75v.5h.75a3 3 0 0 1 3 3 .75.75 0 0 1-1.5 0 1.5 1.5 0 0 0-1.5-1.5h-.75v4.13l1.39.49a3 3 0 0 1-1 5.83h-.39v.55a.75.75 0 0 1-1.5 0v-.55H8.5a3 3 0 0 1-3-3 .75.75 0 0 1 1.5 0 1.5 1.5 0 0 0 1.5 1.5h.75v-4.27l-1.16-.41A3 3 0 0 1 9.06 2.75H9.25v-.5A.75.75 0 0 1 10 2Zm-.75 6.6V4.75h-.19a1.5 1.5 0 0 0-.5 2.91l.69.25Zm1.5 2.74v3.92h.39a1.5 1.5 0 0 0 .5-2.91l-.89-.31Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	grid: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h3A1.5 1.5 0 0 1 9 4.5v3A1.5 1.5 0 0 1 7.5 9h-3A1.5 1.5 0 0 1 3 7.5v-3ZM11 4.5A1.5 1.5 0 0 1 12.5 3h3A1.5 1.5 0 0 1 17 4.5v3A1.5 1.5 0 0 1 15.5 9h-3A1.5 1.5 0 0 1 11 7.5v-3ZM3 12.5A1.5 1.5 0 0 1 4.5 11h3A1.5 1.5 0 0 1 9 12.5v3A1.5 1.5 0 0 1 7.5 17h-3A1.5 1.5 0 0 1 3 15.5v-3ZM11 12.5A1.5 1.5 0 0 1 12.5 11h3A1.5 1.5 0 0 1 17 12.5v3A1.5 1.5 0 0 1 15.5 17h-3A1.5 1.5 0 0 1 11 15.5v-3Z" />
		</svg>
	),
	clock: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-12.25a.75.75 0 0 0-1.5 0V10c0 .2.08.39.22.53l2.5 2.5a.75.75 0 1 0 1.06-1.06l-2.28-2.28V5.75Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	star: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0L7.464 6.526l-3.937.31c-.836.066-1.175 1.108-.538 1.652l3 2.566-.918 3.84c-.196.816.694 1.46 1.408 1.022L10 13.847l3.521 2.069c.714.438 1.604-.206 1.408-1.022l-.918-3.84 3-2.566c.637-.544.298-1.586-.538-1.652l-3.937-.31-1.668-3.642Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	check: (
		<svg
			viewBox="0 0 20 20"
			fill="currentColor"
			className="tr-w-5 tr-h-5"
			aria-hidden="true"
		>
			<path
				fillRule="evenodd"
				d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
				clipRule="evenodd"
			/>
		</svg>
	),
};

const ChangePill = ({ value }) => {
	if (value === 0 || value === null || value === undefined) {
		return (
			<span className="tr-inline-flex tr-items-center tr-text-xs tr-font-medium tr-text-tertiary">
				—
			</span>
		);
	}
	const isPositive = value > 0;
	const tone = isPositive
		? 'tr-text-success tr-bg-success-bg'
		: 'tr-text-danger tr-bg-danger-bg';
	return (
		<span
			className={`tr-inline-flex tr-items-center tr-gap-0.5 tr-px-1.5 tr-py-0.5 tr-rounded tr-text-[11px] tr-font-semibold ${tone}`}
		>
			<span aria-hidden="true">{isPositive ? '▲' : '▼'}</span>
			{Math.abs(value)}%
		</span>
	);
};

const KPICard = ({
	title,
	value,
	prefix = '',
	suffix = '',
	subtitle,
	change,
	icon,
	iconTone,
	// Decimal places for a numeric value. Formatting happens here so callers
	// never pre-stringify — passing a `.toFixed()` string used to flip the card
	// into the small text branch below and truncate the figure to "$0....".
	decimals = 0,
	// Genuinely textual values (e.g. a feature name) render smaller.
	isText = false,
}) => (
	<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-p-5 tr-flex tr-items-center tr-gap-4 tr-min-w-0">
		<div
			className={`tr-shrink-0 tr-w-11 tr-h-11 tr-rounded-lg tr-flex tr-items-center tr-justify-center ${iconTone}`}
		>
			{icon}
		</div>
		<div className="tr-flex-1 tr-min-w-0">
			{/* No truncate: "AVERAGE SEO SCORE" and "MOST USED FEATURE" were
			    both cut at 1280px. Wrapping costs a line; a clipped label
			    costs the meaning. */}
			<p
				className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-m-0"
				title={title}
			>
				{title}
			</p>
			{/*
			 * `tr-flex-wrap` so the change pill drops below the figure when the
			 * two do not fit side by side, and no `tr-truncate` on a numeric
			 * value: at 1280px the seven-card grid leaves ~60px beside the
			 * pill, and a 24px "$0.0377" is ~95px, so clipping turned it into
			 * "$0...." — a number cut short is misinformation, not overflow.
			 * Text values (a feature name) may still truncate; they carry a
			 * `title` for the full string.
			 */}
			<div className="tr-flex tr-flex-wrap tr-items-baseline tr-gap-x-2 tr-gap-y-1 tr-mt-1.5 tr-min-w-0">
				<span
					className={`tr-font-bold tr-text-primary tr-leading-none ${
						isText
							? 'tr-text-base tr-truncate'
							: 'tr-text-2xl tr-whitespace-nowrap'
					}`}
					title={
						typeof value === 'string' ? value : String(value ?? '')
					}
				>
					{prefix && (
						<span className="tr-text-sm tr-font-semibold tr-text-tertiary tr-mr-0.5">
							{prefix}
						</span>
					)}
					{isText || typeof value !== 'number'
						? value
						: value.toLocaleString(undefined, {
								minimumFractionDigits: decimals,
								maximumFractionDigits: decimals,
							})}
					{suffix && (
						<span className="tr-text-sm tr-font-semibold tr-text-tertiary tr-ml-0.5">
							{suffix}
						</span>
					)}
				</span>
				<ChangePill value={change} />
			</div>
			{subtitle && (
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1 tr-truncate">
					{subtitle}
				</p>
			)}
		</div>
	</div>
);

const RANK_TONES = [
	'tr-bg-amber-100 tr-text-amber-700',
	'tr-bg-line-2 tr-text-secondary',
	'tr-bg-orange-100 tr-text-orange-700',
];

const RankedRow = ({
	rank,
	label,
	displayValue,
	value,
	max,
	color = '#4451FF',
}) => {
	const pct = max > 0 ? Math.max(2, Math.round((value / max) * 100)) : 0;
	const rankTone = RANK_TONES[rank - 1] || 'tr-bg-white tr-text-tertiary';
	return (
		<div className="tr-flex tr-items-center tr-gap-3">
			<div
				className={`tr-shrink-0 tr-w-7 tr-h-7 tr-rounded-full tr-flex tr-items-center tr-justify-center tr-text-xs tr-font-bold ${rankTone}`}
				aria-hidden="true"
			>
				{rank}
			</div>
			<div className="tr-flex-1 tr-min-w-0">
				<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-mb-1.5">
					<span className="tr-text-sm tr-text-secondary tr-truncate">
						{label}
					</span>
					<span className="tr-text-sm tr-text-primary tr-font-semibold tr-shrink-0 tr-font-mono">
						{displayValue}
					</span>
				</div>
				<div className="tr-h-1.5 tr-rounded-full tr-bg-white tr-overflow-hidden">
					<div
						className="tr-h-full tr-rounded-full"
						style={{
							width: `${pct}%`,
							background: color,
							transition: 'width 200ms ease-out',
						}}
					/>
				</div>
			</div>
		</div>
	);
};

const formatFeatureLabel = (key) =>
	key
		.replace(/_/g, ' ')
		.replace(/\b\w/g, (l) => l.toUpperCase())
		.replace(/\bSeo\b/g, 'SEO');

const OverviewTab = ({ period }) => {
	const {
		dashboardMetrics,
		isLoading,
		error,
		hasData,
		lastUpdated,
		featureBreakdown,
		providerBreakdown,
	} = useSelect((select) => {
		const analyticsSelect = select('thinkrank/analytics');
		return {
			dashboardMetrics: analyticsSelect.getDashboardMetrics(),
			isLoading: analyticsSelect.getIsLoading(),
			error: analyticsSelect.getError(),
			hasData: analyticsSelect.hasData(),
			lastUpdated: analyticsSelect.getLastUpdated(),
			featureBreakdown: analyticsSelect.getFeatureBreakdown(),
			providerBreakdown: analyticsSelect.getProviderBreakdown(),
		};
	}, []);

	const { fetchMetrics } = useDispatch('thinkrank/analytics');

	useEffect(() => {
		fetchMetrics(period);
	}, [period, fetchMetrics]);

	const featureEntries = Object.entries(featureBreakdown || {}).sort(
		(a, b) => b[1] - a[1]
	);
	const maxFeatureCount = featureEntries.reduce(
		(m, [, c]) => Math.max(m, c),
		0
	);

	const providerEntries = Object.entries(providerBreakdown || {}).sort(
		(a, b) => (b[1]?.cost || 0) - (a[1]?.cost || 0)
	);
	const maxProviderCost = providerEntries.reduce(
		(m, [, d]) => Math.max(m, d?.cost || 0),
		0
	);

	// Naive capitalisation produced "Openai" and "Openrouter".
	const providerLabels = {
		openai: 'OpenAI',
		openrouter: 'OpenRouter',
		claude: 'Claude',
		gemini: 'Gemini',
		openai_compatible: 'Custom endpoint',
	};

	const providerColors = {
		openai: '#4451FF',
		claude: '#4451FF',
		gemini: '#4285f4',
		openrouter: '#6566F1',
		openai_compatible: '#64748B',
	};

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<SectionHeader
					title={__('Usage Overview', 'thinkrank')}
					description={__(
						'AI usage, cost, and provider split for the selected period.',
						'thinkrank'
					)}
				/>

				<div className="tr-p-6">
					{error && (
						<Alert variant="error" className="tr-mb-6">
							{__('Error loading analytics data:', 'thinkrank')}{' '}
							{error.message}
						</Alert>
					)}

					{isLoading ? (
						<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-4 tr-gap-4">
							{[...Array(8)].map((_, i) => (
								<SkeletonCard key={i} lines={2} />
							))}
						</div>
					) : (
						!error && (
							<>
								<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-4 tr-gap-4">
									<KPICard
										title={
											dashboardMetrics.postsAnalyzed.label
										}
										value={
											dashboardMetrics.postsAnalyzed.value
										}
										change={
											dashboardMetrics.postsAnalyzed
												.change
										}
										icon={ICONS.posts}
										iconTone="tr-bg-blue-50 tr-text-blue-600"
									/>
									<KPICard
										title={
											dashboardMetrics.aiOptimizations
												.label
										}
										value={
											dashboardMetrics.aiOptimizations
												.value
										}
										change={
											dashboardMetrics.aiOptimizations
												.change
										}
										icon={ICONS.sparkles}
										iconTone="tr-bg-brand-bg tr-text-brand"
									/>
									<KPICard
										title={
											dashboardMetrics.averageSeoScore
												.label
										}
										value={
											dashboardMetrics.averageSeoScore
												.value
										}
										change={
											dashboardMetrics.averageSeoScore
												.change
										}
										suffix={
											dashboardMetrics.averageSeoScore
												.suffix
										}
										icon={ICONS.target}
										iconTone="tr-bg-cyan-50 tr-text-cyan-600"
									/>
									<KPICard
										title={dashboardMetrics.totalCost.label}
										value={dashboardMetrics.totalCost.value}
										decimals={4}
										change={
											dashboardMetrics.totalCost.change
										}
										prefix={
											dashboardMetrics.totalCost.prefix
										}
										subtitle={
											dashboardMetrics.totalCost.subtitle
										}
										icon={ICONS.dollar}
										iconTone="tr-bg-emerald-50 tr-text-emerald-600"
									/>
								</div>

								<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-4 tr-gap-4 tr-mt-4">
									<KPICard
										title={
											dashboardMetrics.featuresUsed.label
										}
										value={
											dashboardMetrics.featuresUsed.value
										}
										change={
											dashboardMetrics.featuresUsed.change
										}
										icon={ICONS.grid}
										iconTone="tr-bg-amber-50 tr-text-amber-600"
									/>
									<KPICard
										title={dashboardMetrics.timeSaved.label}
										value={dashboardMetrics.timeSaved.value}
										change={
											dashboardMetrics.timeSaved.change
										}
										suffix={
											dashboardMetrics.timeSaved.suffix
										}
										subtitle={
											dashboardMetrics.timeSaved.subtitle
										}
										icon={ICONS.clock}
										iconTone="tr-bg-pink-50 tr-text-pink-600"
									/>
									<KPICard
										title={
											dashboardMetrics.mostUsedFeature
												.label
										}
										value={
											dashboardMetrics.mostUsedFeature
												.value
										}
										isText
										change={
											dashboardMetrics.mostUsedFeature
												.change
										}
										subtitle={
											dashboardMetrics.mostUsedFeature
												.subtitle
										}
										icon={ICONS.star}
										iconTone="tr-bg-white tr-text-secondary"
									/>
								</div>

								{!hasData && (
									<div className="tr-bg-white tr-rounded-lg tr-mt-6">
										<EmptyState
											size="md"
											title={__(
												'No Analytics Data Yet',
												'thinkrank'
											)}
											description={__(
												'Start using AI features to see your usage statistics here.',
												'thinkrank'
											)}
										/>
									</div>
								)}

								{lastUpdated && (
									<p className="tr-text-xs tr-text-tertiary tr-text-right tr-mt-6 tr-m-0">
										{__('Last updated:', 'thinkrank')}{' '}
										{new Date(lastUpdated).toLocaleString()}
									</p>
								)}
							</>
						)
					)}
				</div>
			</Card>

			{hasData && (
				<div className="tr-grid tr-grid-cols-1 lg:tr-grid-cols-2 tr-gap-4">
					<Card padding={false}>
						<SectionHeader
							title={__('Feature Usage', 'thinkrank')}
							description={__(
								'Which AI features your site relied on most.',
								'thinkrank'
							)}
						/>
						<div className="tr-p-6">
							{featureEntries.length > 0 ? (
								<Container direction="column" gap={4}>
									{featureEntries.map(
										([feature, count], idx) => (
											<RankedRow
												key={feature}
												rank={idx + 1}
												label={formatFeatureLabel(
													feature
												)}
												value={count}
												displayValue={count.toLocaleString()}
												max={maxFeatureCount}
												color="#4451FF"
											/>
										)
									)}
								</Container>
							) : (
								<p className="tr-text-sm tr-text-tertiary tr-m-0">
									{__(
										'No feature usage recorded for this period.',
										'thinkrank'
									)}
								</p>
							)}
						</div>
					</Card>

					<Card padding={false}>
						<SectionHeader
							title={__('Provider Cost Breakdown', 'thinkrank')}
							description={__(
								'Spend split by AI provider.',
								'thinkrank'
							)}
						/>
						<div className="tr-p-6">
							{providerEntries.length > 0 ? (
								<Container direction="column" gap={4}>
									{providerEntries.map(
										([provider, data], idx) => {
											const cost = data?.cost || 0;
											const pct = data?.percentage || 0;
											return (
												<RankedRow
													key={provider}
													rank={idx + 1}
													label={
														providerLabels[
															provider
														] ||
														provider
															.charAt(0)
															.toUpperCase() +
															provider.slice(1)
													}
													value={cost}
													displayValue={`$${cost.toFixed(4)} (${pct}%)`}
													max={maxProviderCost}
													color={
														providerColors[
															provider
														] || '#4451FF'
													}
												/>
											);
										}
									)}
								</Container>
							) : (
								<p className="tr-text-sm tr-text-tertiary tr-m-0">
									{__(
										'No provider cost data for this period.',
										'thinkrank'
									)}
								</p>
							)}
						</div>
					</Card>
				</div>
			)}
		</Container>
	);
};

/**
 * Read the active tab from the URL, falling back to the first tab.
 *
 * Validated against TABS so a hand-edited or stale `?tab=` cannot leave the
 * page with no panel showing.
 *
 * @return {string} Tab id.
 */
const tabFromUrl = () => {
	try {
		const tab = new URLSearchParams(window.location.search).get('tab');
		return TABS.some((t) => t.id === tab) ? tab : TABS[0].id;
	} catch (e) {
		return TABS[0].id;
	}
};

const Analytics = () => {
	const [activeTab, setActiveTab] = useState(tabFromUrl);

	// One period for the page. Each tab used to own its own useState('30d')
	// and its own selector in the same visual position, so setting Overview to
	// 7 days and Breakdown to 90 left the two views of the same dataset
	// silently disagreeing — and switching tabs looked like the value had
	// changed by itself.
	const [period, setPeriod] = useState('30d');

	// Keep the tab in the URL so it can be linked and bookmarked. pushState,
	// not the router's same-page replaceState, so Back returns to the previous
	// tab instead of leaving the page.
	const selectTab = (tabId) => {
		setActiveTab(tabId);
		const url = getPageUrl('analytics', { tab: tabId });
		if (url) {
			window.history.pushState({}, '', url);
		}
	};

	useEffect(() => {
		const onPop = () => setActiveTab(tabFromUrl());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<div className="tr-px-6 tr-pt-5 tr-flex tr-flex-wrap tr-items-end tr-justify-between tr-gap-4">
					<Tabs
						tabs={TABS}
						activeTab={activeTab}
						onTabChange={selectTab}
					/>
					<div className="tr-w-44 tr-pb-2">
						<Select
							value={period}
							options={PERIOD_OPTIONS}
							onChange={(e) => setPeriod(e.target.value)}
							aria-label={__('Reporting period', 'thinkrank')}
						/>
					</div>
				</div>
				<div className="tr-px-6 tr-py-6">
					<TabPanel tabId="overview" activeTab={activeTab}>
						<OverviewTab period={period} />
					</TabPanel>
					<TabPanel tabId="usage-breakdown" activeTab={activeTab}>
						{/* isActive gates the fetch: TabPanel keeps hidden
						    panels mounted, so this used to request
						    /analytics/usage on every page load even when the
						    tab was never opened. */}
						<UsageBreakdown
							period={period}
							isActive={activeTab === 'usage-breakdown'}
						/>
					</TabPanel>
				</div>
			</Card>
		</Container>
	);
};

export default Analytics;
