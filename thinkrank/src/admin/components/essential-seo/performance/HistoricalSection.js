/**
 * Historical Data Section
 *
 * Historical performance trend charts for CWV metrics.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	Button,
	Flex,
	__experimentalText as Text,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { SkeletonCard, EmptyState } from '../../ui';
import PerformanceChart from '../../common/PerformanceChart';
import PerformanceErrorNotice from './PerformanceErrorNotice';

/**
 * Render trend chart with performance coloring
 * @param title
 * @param data
 * @param unit
 * @param thresholds
 */
const renderTrendChart = (title, data, unit, thresholds) => {
	const getChartColor = () => {
		if (!data || data.length === 0) return '#0073aa';
		const currentValue = data[data.length - 1]?.value || 0;
		if (thresholds?.good && currentValue <= thresholds.good)
			return '#34a853';
		if (
			thresholds?.needs_improvement &&
			currentValue <= thresholds.needs_improvement
		)
			return '#fbbc04';
		return '#921C13';
	};

	return (
		<Card className="thinkrank-mb-4">
			<CardBody>
				<PerformanceChart
					title={title}
					data={data}
					unit={unit}
					thresholds={thresholds}
					height={320}
					showArea={true}
					color={getChartColor()}
				/>
				<div className="thinkrank-chart-stats">
					<div>
						<div className="thinkrank-current-value">
							{data[data.length - 1]?.value?.toFixed(
								unit === 'score' ? 3 : unit === 's' ? 2 : 0
							)}
							{unit}
						</div>
						<div className="thinkrank-current-label">
							{__('Current', 'thinkrank')}
						</div>
					</div>
					<div className="thinkrank-trend">
						<div
							className={`thinkrank-trend-value ${data.length > 1 && data[data.length - 1]?.value < data[data.length - 2]?.value ? 'status-good' : 'status-poor'}`}
						>
							{data.length > 1
								? data[data.length - 1]?.value <
									data[data.length - 2]?.value
									? '↓ Better'
									: '↑ Worse'
								: '—'}
						</div>
						<div className="thinkrank-trend-label">
							{__('vs. Previous', 'thinkrank')}
						</div>
					</div>
				</div>
			</CardBody>
		</Card>
	);
};

/**
 * HistoricalSection Component
 * @param root0
 * @param root0.historicalData
 * @param root0.isLoadingHistoricalData
 * @param root0.historicalDataError
 * @param root0.historicalPeriod
 * @param root0.setHistoricalPeriod
 * @param root0.performanceData
 * @param root0.onNavigate
 * @param root0.onRetry
 */
const HistoricalSection = ({
	historicalData,
	isLoadingHistoricalData,
	historicalDataError,
	historicalPeriod,
	setHistoricalPeriod,
	performanceData,
	onNavigate,
	onRetry,
}) => {
	if (isLoadingHistoricalData) {
		return (
			<div className="tr-empty-state">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	if (historicalDataError) {
		return (
			<PerformanceErrorNotice
				error={historicalDataError}
				onNavigate={onNavigate}
				onRetry={onRetry}
				isRetrying={isLoadingHistoricalData}
			/>
		);
	}

	const hasRealData =
		historicalData &&
		(historicalData.lcp?.length > 0 ||
			historicalData.inp?.length > 0 ||
			historicalData.cls?.length > 0);

	const hasApiConfigIssue =
		performanceData?.core_web_vitals?.error ||
		performanceData?.core_web_vitals?.message?.includes('not connected');

	if (!hasRealData && hasApiConfigIssue) {
		return (
			<div className="thinkrank-cwv-wrapper">
				<EmptyState
					size="md"
					title={__('No Historical Data Available', 'thinkrank')}
					description={__(
						'Add a PageSpeed API key or connect your Google account to start collecting historical performance data.',
						'thinkrank'
					)}
					action={
						<div className="tr-flex tr-justify-center tr-gap-3">
							<Button
								variant="primary"
								onClick={() =>
									onNavigate &&
									onNavigate(
										'integrations',
										'google-services'
									)
								}
							>
								{__('Add PageSpeed API Key', 'thinkrank')}
							</Button>
							<Button
								variant="secondary"
								onClick={onRetry}
								disabled={isLoadingHistoricalData}
							>
								{isLoadingHistoricalData
									? __('Retrying…', 'thinkrank')
									: __('Retry', 'thinkrank')}
							</Button>
						</div>
					}
				/>
			</div>
		);
	}

	if (!hasRealData) {
		return (
			<div className="thinkrank-cwv-wrapper">
				<EmptyState
					size="md"
					title={__('Collecting Historical Data', 'thinkrank')}
					description={__(
						"Historical performance data will appear here as it's collected over time. Data collection runs daily. Check back in a few days to see your performance trends.",
						'thinkrank'
					)}
					action={
						<Button
							variant="secondary"
							onClick={onRetry}
							disabled={isLoadingHistoricalData}
						>
							{isLoadingHistoricalData
								? __('Refreshing…', 'thinkrank')
								: __('Refresh Data', 'thinkrank')}
						</Button>
					}
				/>
			</div>
		);
	}

	return (
		<>
			<Flex
				justify="space-between"
				align="center"
				style={{ marginBottom: '20px' }}
			>
				<div>
					<Heading level={3} style={{ margin: '0 0 4px' }}>
						{__('Historical Performance Data', 'thinkrank')}
					</Heading>
					<Text variant="muted">
						{__('Showing data for the last', 'thinkrank')}{' '}
						{historicalPeriod} {__('days', 'thinkrank')}
					</Text>
				</div>
				<div className="thinkrank-cwv-device-tabs">
					{[28, 90].map((p) => (
						<button
							key={p}
							className={historicalPeriod === p ? 'active' : ''}
							onClick={() => setHistoricalPeriod(p)}
						>
							{p} {__('Days', 'thinkrank')}
						</button>
					))}
				</div>
			</Flex>

			<div className="thinkrank-hist-grid">
				{/* Each metric is gated on having points, not on the key
				    existing: loadHistoricalData() always writes an array for
				    every metric, so a truthiness check drew an empty chart with
				    a blank value for any vital the API had no data for. */}
				{historicalData.lcp?.length > 0 &&
					renderTrendChart(
						__('Largest Contentful Paint (LCP)', 'thinkrank'),
						historicalData.lcp,
						's',
						{ good: 2.5, needs_improvement: 4.0 }
					)}
				{historicalData.inp?.length > 0 &&
					renderTrendChart(
						__('Interaction to Next Paint (INP)', 'thinkrank'),
						historicalData.inp,
						'ms',
						{ good: 200, needs_improvement: 500 }
					)}
				{historicalData.cls?.length > 0 &&
					renderTrendChart(
						__('Cumulative Layout Shift (CLS)', 'thinkrank'),
						historicalData.cls,
						'score',
						{ good: 0.1, needs_improvement: 0.25 }
					)}
			</div>
		</>
	);
};

export default HistoricalSection;
