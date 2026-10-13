/**
 * Connected state — last 30 days of Google Search Console traffic.
 *
 * Header ("Traffic from last few days") + a daily clicks/impressions line chart,
 * with Clicks / Impressions totals summed from the same daily series. Sourced
 * from `/thinkrank/v1/seo-analytics/search-daily`.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import SearchTrafficChart from './SearchTrafficChart';
import { ClicksIcon, ImpressionsIcon } from './metric-icons';
import ProTeaserBar from './ProTeaserBar';
import ErrorState from './ErrorState';

// Left-axis series colors — match the Analytics › Dashboard chart.
const CLICKS_COLOR = '#4794ff';
const IMPRESSIONS_COLOR = '#6f52ff';

/**
 * Format a number with locale grouping.
 *
 * @param {number} value Raw value.
 * @return {string} Formatted number.
 */
function formatNumber(value) {
	const num = Number(value) || 0;
	return num.toLocaleString();
}

export default function TrafficDisplay({
	config,
	data,
	loading,
	error,
	onRetry,
}) {
	const rows = Array.isArray(data?.rows) ? data.rows : [];

	const totals = rows.reduce(
		(acc, row) => {
			acc.clicks += Number(row.clicks) || 0;
			acc.impressions += Number(row.impressions) || 0;
			return acc;
		},
		{ clicks: 0, impressions: 0 }
	);

	const hasData = totals.clicks > 0 || totals.impressions > 0;

	const metrics = [
		{
			key: 'clicks',
			label: __('Total Clicks', 'thinkrank'),
			value: totals.clicks,
			color: CLICKS_COLOR,
			icon: <ClicksIcon />,
		},
		{
			key: 'impressions',
			label: __('Total Impressions', 'thinkrank'),
			value: totals.impressions,
			color: IMPRESSIONS_COLOR,
			icon: <ImpressionsIcon />,
		},
	];

	return (
		<div className="thinkrank-dashboard-widget__panel">
			<div className="thinkrank-dashboard-widget__toolbar">
				<span className="thinkrank-dashboard-widget__toolbar-label">
					{__('Traffic from last few days', 'thinkrank')}
				</span>
				<a
					className="thinkrank-dashboard-widget__view-more"
					href={config.settingsUrl}
				>
					{__('View More', 'thinkrank')}
					<span aria-hidden="true">↗</span>
				</a>
			</div>

			{loading && (
				<p className="thinkrank-dashboard-widget__note">
					{__('Loading Search Console data…', 'thinkrank')}
				</p>
			)}

			{!loading && error && (
				<ErrorState error={error} config={config} onRetry={onRetry} />
			)}

			{!loading && !error && !hasData && (
				<div className="thinkrank-dashboard-widget__empty-data">
					<p className="thinkrank-dashboard-widget__empty-data-title">
						{__('No data available', 'thinkrank')}
					</p>
					<p className="thinkrank-dashboard-widget__note">
						{__(
							'Search Console data might take up to 30 days to appear for newly added sites. Please check back later.',
							'thinkrank'
						)}
					</p>
				</div>
			)}

			{!loading && !error && hasData && (
				<>
					<SearchTrafficChart rows={rows} />

					<div className="thinkrank-dashboard-widget__stats">
						{metrics.map((metric) => (
							<div
								key={metric.key}
								className="thinkrank-dashboard-widget__stat"
							>
								<div className="thinkrank-dashboard-widget__stat-top">
									<span
										className="thinkrank-dashboard-widget__stat-icon"
										style={{
											backgroundColor: metric.color,
										}}
									>
										{metric.icon}
									</span>
									<span className="thinkrank-dashboard-widget__stat-label">
										{metric.label}
									</span>
								</div>
								<span className="thinkrank-dashboard-widget__stat-value">
									{formatNumber(metric.value)}
								</span>
							</div>
						))}
					</div>
				</>
			)}

			<ProTeaserBar config={config} />
		</div>
	);
}
