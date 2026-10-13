/**
 * Core Web Vitals Section
 *
 * Performance score donut, CWV metric cards, status icons.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import {
	Button,
	__experimentalText as Text,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { SkeletonCard, EmptyState } from '../../ui';
import emptyStateIcon from '../../../../shared/assets/empty-state.svg';

/**
 * Get vital status category
 * @param value
 * @param thresholds
 */
export const getVitalStatus = (value, thresholds) => {
	if (value <= thresholds.good) return 'good';
	if (value <= thresholds.needs_improvement) return 'needs-improvement';
	return 'poor';
};

/**
 * Get status color hex
 * @param status
 */
export const getStatusColor = (status) => {
	switch (status) {
		case 'good':
			return '#00a32a';
		case 'needs-improvement':
			return '#dba617';
		case 'poor':
			return '#d63638';
		default:
			return '#50575e';
	}
};

/**
 * Get performance score color
 * @param score
 */
export const getScoreColor = (score) => {
	if (score >= 90) return '#00a32a';
	if (score >= 50) return '#dba617';
	return '#d63638';
};

/**
 * Render status icon SVG
 * @param status
 */
const renderStatusIcon = (status) => {
	if (status === 'good') {
		return (
			<span className="thinkrank-cwv-status-icon">
				<svg width="22" height="22" viewBox="0 0 22 22" fill="none">
					<circle cx="11" cy="11" r="11" fill="#34a853" />
					<path
						d="M6.5 11.5l3 3 6-7"
						stroke="#fff"
						strokeWidth="2"
						strokeLinecap="round"
						strokeLinejoin="round"
					/>
				</svg>
			</span>
		);
	}
	if (status === 'needs-improvement') {
		return (
			<span className="thinkrank-cwv-status-icon">
				<svg width="22" height="22" viewBox="0 0 22 22" fill="none">
					<path d="M11 2L1 20h20L11 2z" fill="#B54708" />
					<path
						d="M11 9v5"
						stroke="#fff"
						strokeWidth="2"
						strokeLinecap="round"
					/>
					<circle cx="11" cy="16.5" r="1" fill="#fff" />
				</svg>
			</span>
		);
	}
	return (
		<span className="thinkrank-cwv-status-icon">
			<svg width="22" height="22" viewBox="0 0 22 22" fill="none">
				<rect width="22" height="22" rx="5" fill="#921C13" />
				<path
					d="M7 7.5c0-2.2 1.8-4 4-4s4 1.8 4 4"
					stroke="#fff"
					strokeWidth="1.8"
					strokeLinecap="round"
					fill="none"
				/>
				<circle cx="9" cy="10" r="1.2" fill="#fff" />
				<circle cx="13" cy="10" r="1.2" fill="#fff" />
				<path
					d="M8 14.5s1.2-2 3-2 3 2 3 2"
					stroke="#fff"
					strokeWidth="1.8"
					strokeLinecap="round"
					fill="none"
				/>
			</svg>
		</span>
	);
};

/**
 * Render a single vital card
 * @param vital
 * @param data
 */
const renderVitalCard = (vital, data) => {
	if (!data) return null;

	if (data.status === 'unknown' || data.value === 0) {
		return (
			<div key={vital} className="thinkrank-cwv-metric-card">
				<div className="thinkrank-cwv-metric-header">
					<span className="thinkrank-cwv-metric-name">
						{data.name}
					</span>
				</div>
				<div className="thinkrank-cwv-metric-value">—</div>
				<div className="thinkrank-cwv-metric-desc">
					{__('Data not available', 'thinkrank')}
				</div>
			</div>
		);
	}

	const status = getVitalStatus(data.value, {
		good: data.good_threshold,
		needs_improvement: data.needs_improvement_threshold,
	});

	return (
		<div key={vital} className="thinkrank-cwv-metric-card">
			<div className="thinkrank-cwv-metric-header">
				<span className="thinkrank-cwv-metric-name">{data.name}</span>
				{renderStatusIcon(status)}
			</div>
			<div className="thinkrank-cwv-metric-value">
				{data.value}
				{data.unit}
			</div>
			<div className="thinkrank-cwv-metric-desc">
				{data.description ||
					__(
						'Time until the largest content element is rendered',
						'thinkrank'
					)}
			</div>
			<div className="thinkrank-cwv-metric-thresholds">
				<div className="thinkrank-cwv-threshold-row">
					<span>{__('Good', 'thinkrank')}</span>
					<span className="thinkrank-cwv-threshold-good">
						↑{data.good_threshold}
						{data.unit}
					</span>
				</div>
				<div className="thinkrank-cwv-threshold-row">
					<span>{__('Poor', 'thinkrank')}</span>
					<span className="thinkrank-cwv-threshold-poor">
						↓{data.needs_improvement_threshold}
						{data.unit}
					</span>
				</div>
			</div>
		</div>
	);
};

/**
 * Render segmented donut chart
 * @param performanceData
 * @param deviceType
 * @param setDeviceType
 */
const renderSegmentedDonut = (performanceData, deviceType, setDeviceType) => {
	const score = performanceData?.performance_score || 0;
	const cx = 110,
		cy = 110,
		r = 82,
		sw = 22;
	const circ = 2 * Math.PI * r;
	const fillDash = (score / 100) * circ;
	const arcColor =
		score >= 90 ? '#027A48' : score >= 50 ? '#4451FF' : '#921C13';
	const isMobile = deviceType === 'mobile';

	return (
		<div className="thinkrank-cwv-donut-wrap">
			<svg width="220" height="220" viewBox="0 0 220 220">
				<circle
					cx={cx}
					cy={cy}
					r={r}
					fill="none"
					stroke="#eef0f7"
					strokeWidth={sw}
				/>
				<circle
					cx={cx}
					cy={cy}
					r={r}
					fill="none"
					stroke={arcColor}
					strokeWidth={sw}
					strokeDasharray={`${fillDash} ${circ}`}
					strokeLinecap="round"
					transform={`rotate(-90, ${cx}, ${cy})`}
				/>
				<text
					x={cx}
					y={cy - 6}
					textAnchor="middle"
					fontSize="38"
					fontWeight="700"
					fill="#1e1e1e"
					fontFamily="Inter, sans-serif"
				>
					{score}
				</text>
				<text
					x={cx}
					y={cy + 20}
					textAnchor="middle"
					fontSize="14"
					fill={arcColor}
					fontFamily="Inter, sans-serif"
					fontWeight="600"
				>
					{isMobile
						? __('Mobile', 'thinkrank')
						: __('Desktop', 'thinkrank')}
				</text>
			</svg>

			<div className="thinkrank-cwv-device-breakdown">
				<button
					type="button"
					aria-pressed={isMobile}
					className={`thinkrank-cwv-device-item${isMobile ? ' is-active' : ''}`}
					onClick={() => setDeviceType('mobile')}
				>
					<svg width="16" height="16" viewBox="0 0 16 16" fill="none">
						<rect
							x="2"
							y="3"
							width="12"
							height="9"
							rx="1.5"
							stroke="currentColor"
							strokeWidth="1.4"
						/>
						<path
							d="M5.5 14h5"
							stroke="currentColor"
							strokeWidth="1.4"
							strokeLinecap="round"
						/>
						<path
							d="M8 12v2"
							stroke="currentColor"
							strokeWidth="1.4"
							strokeLinecap="round"
						/>
					</svg>
					<span>{__('Mobile', 'thinkrank')}</span>
				</button>
				<button
					type="button"
					aria-pressed={!isMobile}
					className={`thinkrank-cwv-device-item${!isMobile ? ' is-active' : ''}`}
					onClick={() => setDeviceType('desktop')}
				>
					<svg width="16" height="16" viewBox="0 0 16 16" fill="none">
						<rect
							x="1"
							y="2"
							width="14"
							height="10"
							rx="1.5"
							stroke="currentColor"
							strokeWidth="1.4"
						/>
						<path
							d="M4.5 14h7"
							stroke="currentColor"
							strokeWidth="1.4"
							strokeLinecap="round"
						/>
						<path
							d="M8 12v2"
							stroke="currentColor"
							strokeWidth="1.4"
							strokeLinecap="round"
						/>
					</svg>
					<span>{__('Desktop', 'thinkrank')}</span>
				</button>
			</div>
		</div>
	);
};

/**
 * CWVSection Component
 * @param root0
 * @param root0.performanceData
 * @param root0.deviceType
 * @param root0.setDeviceType
 * @param root0.isLoading
 * @param root0.onNavigate
 * @param root0.onRefresh
 */
const CWVSection = ({
	performanceData,
	deviceType,
	setDeviceType,
	isLoading,
	onNavigate,
	onRefresh,
}) => {
	if (isLoading) {
		return (
			<div className="thinkrank-cwv-wrapper">
				<div className="tr-empty-state">
					<SkeletonCard lines={3} />
				</div>
			</div>
		);
	}

	if (
		!performanceData ||
		!performanceData.core_web_vitals ||
		performanceData.core_web_vitals.error
	) {
		return (
			<div className="thinkrank-cwv-wrapper">
				<EmptyState
					size="md"
					title={__('No Core Web Vitals Data Available', 'thinkrank')}
					description={
						performanceData?.core_web_vitals?.message ||
						__(
							'Add a PageSpeed API key or connect your Google account in Integrations > Google Services to view real performance data.',
							'thinkrank'
						)
					}
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
								onClick={onRefresh}
								disabled={isLoading}
							>
								{isLoading ? (
									<SkeletonCard lines={2} />
								) : (
									__('Retry', 'thinkrank')
								)}
							</Button>
						</div>
					}
				/>
			</div>
		);
	}

	const vitals = performanceData.core_web_vitals
		? Object.entries(performanceData.core_web_vitals).filter(
				([k]) => k !== 'error' && k !== 'message'
			)
		: [];

	return (
		<div className="thinkrank-cwv-wrapper">
			<div className="thinkrank-cwv-panel-layout">
				<div className="thinkrank-cwv-score-panel">
					<span className="thinkrank-cwv-score-title">
						{__('Performance', 'thinkrank')}
					</span>
					{renderSegmentedDonut(
						performanceData,
						deviceType,
						setDeviceType
					)}
				</div>
				<div className="thinkrank-cwv-metrics-panel">
					<div className="thinkrank-cwv-metrics-header">
						<div>
							<span className="thinkrank-cwv-metrics-title">
								{__('Core Web', 'thinkrank')}
							</span>
							<span className="thinkrank-cwv-metrics-subtitle">
								{__(
									'Google ranking factors that measure user experience',
									'thinkrank'
								)}
							</span>
						</div>
						<div className="thinkrank-cwv-device-tabs">
							{['mobile', 'desktop'].map((device) => (
								<button
									key={device}
									className={
										deviceType === device ? 'active' : ''
									}
									onClick={() => setDeviceType(device)}
								>
									{device.charAt(0).toUpperCase() +
										device.slice(1)}
								</button>
							))}
						</div>
					</div>
					<div className="thinkrank-cwv-metrics-grid">
						{vitals.map(([vital, data]) =>
							renderVitalCard(vital, data)
						)}
					</div>
				</div>
			</div>
		</div>
	);
};

export default CWVSection;
