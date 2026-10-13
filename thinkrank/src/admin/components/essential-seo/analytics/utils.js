/**
 * Analytics Shared Utilities
 *
 * Shared helper functions used across analytics sub-sections.
 * Extracted from AnalyticsTab for reuse and clarity.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Return % change between current and previous values, or null if incalculable.
 * @param current
 * @param previous
 */
export const computePercentChange = (current, previous) => {
	const c = Number(current);
	const p = Number(previous);
	if (!p) return null;
	return ((c - p) / Math.abs(p)) * 100;
};

/**
 * Render a period-comparison badge below a metric value.
 * @param current
 * @param previous
 * @param lowerIsBetter
 */
export const renderChangeBadge = (current, previous, lowerIsBetter = false) => {
	const delta = computePercentChange(current, previous);
	if (delta === null) return null;
	const isPositive = delta > 0;
	const isStable = Math.abs(delta) < 0.5;
	const isGood = isStable ? true : lowerIsBetter ? !isPositive : isPositive;
	const arrow = isStable ? '→' : isPositive ? '↑' : '↓';
	const colorClass = isStable
		? 'thinkrank-trend-stable'
		: isGood
			? 'thinkrank-trend-up'
			: 'thinkrank-trend-down';
	return (
		<div
			className={`thinkrank-metric-trend ${colorClass}`}
			title={__('vs. previous period', 'thinkrank')}
		>
			{arrow} {Math.abs(delta).toFixed(1)}%
		</div>
	);
};

/**
 * Render a period-comparison badge from an already-computed percentage delta.
 * Shows "+12.5%" or "-2.1%" with green/red colour coding (no "vs last month" label).
 * Used by the Figma-style metric cards (Traffic Overview, Search Performance).
 * @param delta
 * @param lowerIsBetter
 */
export const renderPercentChangeBadge = (delta, lowerIsBetter = false) => {
	if (delta === null || delta === undefined || Number.isNaN(delta))
		return null;
	const isPositive = delta > 0;
	const isStable = Math.abs(delta) < 0.5;
	const isGood = isStable ? true : lowerIsBetter ? !isPositive : isPositive;
	const prefix = isStable
		? ''
		: lowerIsBetter
			? isPositive
				? '-'
				: '+'
			: isPositive
				? '+'
				: '-';
	const valueColor = isStable ? '#5a5c5e' : isGood ? '#027a48' : '#921c13';
	return (
		<span
			style={{
				fontWeight: 600,
				fontSize: '14px',
				color: valueColor,
				whiteSpace: 'nowrap',
			}}
		>
			{prefix}
			{Math.abs(delta).toFixed(1)}%
		</span>
	);
};

/**
 * Render period-comparison badge for the Figma-style Search Performance cards.
 * Computes the delta from current/previous totals, then delegates to renderPercentChangeBadge.
 * @param current
 * @param previous
 * @param lowerIsBetter
 */
export const renderSearchChangeBadge = (
	current,
	previous,
	lowerIsBetter = false
) => {
	return renderPercentChangeBadge(
		computePercentChange(current, previous),
		lowerIsBetter
	);
};

/**
 * Compute the previous period start/end dates for a given date range label.
 * e.g. "30d" → the 30-day window that ended the day before the current window started.
 * Defined BEFORE the early loading return so loadDashboardData can call it on first mount.
 * @param range
 */
export const getPreviousPeriodDates = (range) => {
	const days = range === '7d' ? 7 : range === '30d' ? 30 : 90;
	const fmt = (d) => d.toISOString().split('T')[0];
	const today = new Date();
	const currentEnd = new Date(today);
	currentEnd.setDate(currentEnd.getDate() - 2); // D-2: GSC has a 2-day data delay
	const currentStart = new Date(currentEnd);
	currentStart.setDate(currentStart.getDate() - days + 1);
	const prevEnd = new Date(currentStart);
	prevEnd.setDate(prevEnd.getDate() - 1); // day before current window
	const prevStart = new Date(prevEnd);
	prevStart.setDate(prevStart.getDate() - days + 1);
	return { prevStart: fmt(prevStart), prevEnd: fmt(prevEnd) };
};

/**
 * Build a dynamic Google Search Console performance URL.
 * resource_id: domain properties → sc-domain:example.com, URL properties → as-is.
 * Window is exactly N days back from today (inclusive), matching the chart and boxes:
 *   7d  → today-6  ... today
 *   30d → today-29 ... today
 *   90d → today-89 ... today
 * @param property
 * @param range
 */
export const getSearchConsoleUrl = (property, range) => {
	const days = range === '7d' ? 7 : range === '30d' ? 30 : 90;
	const fmtDate = (d) => d.toISOString().split('T')[0].replace(/-/g, '');
	const today = new Date();
	const start = new Date(today);
	start.setDate(start.getDate() - (days - 1));
	// property is already stored with its prefix (sc-domain:example.com or https://example.com/)
	const resourceId = property || '';
	const params = new URLSearchParams({
		resource_id: resourceId,
		start_date: fmtDate(start),
		end_date: fmtDate(today),
	});
	return `https://search.google.com/search-console/performance/search-analytics?${params.toString()}`;
};

/**
 * Build a Google Search Console "Performance → Insights" URL for the
 * connected property. Used by Top Countries, Branded vs Non-Branded,
 * and Top Content where GSC's Insights view is the natural deep-link.
 * @param property
 */
export const getSearchConsoleInsightsUrl = (property) => {
	const resourceId = property || '';
	const params = new URLSearchParams({ resource_id: resourceId });
	return `https://search.google.com/search-console/performance/insights?${params.toString()}`;
};

/**
 * Check if dashboard data contains valid/meaningful data
 * @param {Object} data Dashboard data object
 * @return {boolean} True if data contains meaningful information
 */
export const hasValidDashboardData = (data) => {
	if (!data) return false;

	// Check if traffic data has meaningful values
	const hasTrafficData =
		data.traffic &&
		Object.keys(data.traffic).length > 0 &&
		(data.traffic.sessions > 0 || data.traffic.pageviews > 0);

	// Check if search performance has data
	const hasSearchData =
		data.search_performance &&
		((data.search_performance.rows &&
			data.search_performance.rows.length > 0) ||
			(data.search_performance.totals &&
				(data.search_performance.totals.clicks > 0 ||
					data.search_performance.totals.impressions > 0)) ||
			data.search_performance.total_clicks > 0 ||
			data.search_performance.total_impressions > 0);

	// Check if core web vitals has data
	const hasWebVitalsData =
		data.core_web_vitals &&
		Object.keys(data.core_web_vitals).length > 0 &&
		!data.core_web_vitals.error;

	return hasTrafficData || hasSearchData || hasWebVitalsData;
};

/**
 * Default analytics settings
 * Following ThinkRank settings patterns
 */
export const getDefaultSettings = () => ({
	seo_analytics_enabled: false,
	seo_analytics_setup_completed: false,
	seo_analytics_google_analytics_property_id: '',
	search_console_property: '',
	seo_analytics_enable_ai_insights: true,
	seo_analytics_enable_automated_alerts: false,
	seo_analytics_monitoring_frequency: 3600,
	seo_analytics_cache_analytics_data: true,
});

/**
 * Date range options for dashboard selectors.
 *
 * The same ranges on every plan: the free backend serves all three, so a
 * plan check here would lock built-in functionality behind payment
 * (WordPress.org Guideline 5, #1104).
 */
export const dateRangeOptions = [
	{ label: __('Last 7 days', 'thinkrank'), value: '7d' },
	{ label: __('Last 30 days', 'thinkrank'), value: '30d' },
	{ label: __('Last 90 days', 'thinkrank'), value: '90d' },
];

/**
 * Compute Search Performance totals from daily rows,
 * so the metric cards stay in sync with the graph for every date range.
 * CTR and Position are weighted by impressions (matches GSC's own aggregation).
 * @param searchDailyData
 */
export const getSearchPerformanceTotals = (searchDailyData) => {
	const rows = searchDailyData?.rows || [];
	if (rows.length === 0) {
		return { clicks: 0, impressions: 0, ctr: 0, position: 0 };
	}
	const sums = rows.reduce(
		(acc, r) => {
			const impressions = Number(r.impressions) || 0;
			const position = Number(r.position) || 0;
			acc.clicks += Number(r.clicks) || 0;
			acc.impressions += impressions;
			acc.positionWeighted += position * impressions;
			return acc;
		},
		{ clicks: 0, impressions: 0, positionWeighted: 0 }
	);
	return {
		clicks: sums.clicks,
		impressions: sums.impressions,
		ctr: sums.impressions > 0 ? (sums.clicks / sums.impressions) * 100 : 0,
		position:
			sums.impressions > 0 ? sums.positionWeighted / sums.impressions : 0,
	};
};

/**
 * Build a Google Analytics "Reports → Dashboard" URL for the connected
 * GA4 account + property. Returns empty string when either ID is missing.
 * @param settings
 */
export const getGoogleAnalyticsReportUrl = (settings) => {
	const accountId = String(settings.ga_analytics_account_id || '').replace(
		/^accounts\//,
		''
	);
	const propertyId = String(
		settings.seo_analytics_google_analytics_property_id || ''
	).replace(/^properties\//, '');
	if (!accountId || !propertyId) return '';
	return `https://analytics.google.com/analytics/web/#/a${accountId}p${propertyId}/reports/dashboard?r=reporting-hub`;
};
