/**
 * Performance Tab — Shell Component
 *
 * State management, data orchestration, and sub-section routing.
 * Delegates rendering to CWVSection, OpportunitiesSection, HistoricalSection, DiagnosticsSection.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import { SkeletonCard } from '../../ui';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	Notice,
	__experimentalText as Text,
	__experimentalHeading as Heading,
} from '@wordpress/components';

// Sub-section components
import CWVSection from './CWVSection';
import OpportunitiesSection from './OpportunitiesSection';
import HistoricalSection from './HistoricalSection';
import DiagnosticsSection from './DiagnosticsSection';

// API functions
import {
	loadPerformanceData as apiLoadPerformanceData,
	loadRecommendations as apiLoadRecommendations,
	loadHistoricalData as apiLoadHistoricalData,
	loadOpportunities as apiLoadOpportunities,
	loadDiagnostics as apiLoadDiagnostics,
} from './api';
import { purgeApiCache } from '../../../utils/apiCache';

/**
 * Performance Tab Component
 * @param root0
 * @param root0.activeSubSection
 * @param root0.onNavigate
 */
const PerformanceTab = ({
	activeSubSection = 'core-web-vitals',
	onNavigate,
}) => {
	const [performanceData, setPerformanceData] = useState(null);
	const [isLoading, setIsLoading] = useState(false);
	const [notice, setNotice] = useState(null);
	const [lastUpdated, setLastUpdated] = useState(null);
	const [recommendations, setRecommendations] = useState(null);
	const [historicalData, setHistoricalData] = useState(null);
	const [isLoadingHistoricalData, setIsLoadingHistoricalData] =
		useState(false);
	const [historicalDataError, setHistoricalDataError] = useState(null);
	const [deviceType, setDeviceType] = useState('mobile');
	const [historicalPeriod, setHistoricalPeriod] = useState(28);
	const [opportunities, setOpportunities] = useState([]);
	const [isLoadingOpportunities, setIsLoadingOpportunities] = useState(false);
	const [opportunitiesError, setOpportunitiesError] = useState(null);
	const [diagnostics, setDiagnostics] = useState([]);
	const [isLoadingDiagnostics, setIsLoadingDiagnostics] = useState(false);
	const [diagnosticsError, setDiagnosticsError] = useState(null);

	/**
	 * Build API context
	 */
	const getApiContext = () => ({
		deviceType,
		historicalPeriod,
		setPerformanceData,
		setIsLoading,
		setNotice,
		setLastUpdated,
		setRecommendations,
		setHistoricalData,
		setIsLoadingHistoricalData,
		setHistoricalDataError,
		setOpportunities,
		setIsLoadingOpportunities,
		setOpportunitiesError,
		setDiagnostics,
		setIsLoadingDiagnostics,
		setDiagnosticsError,
	});

	const loadPerformanceData = () => apiLoadPerformanceData(getApiContext());
	// Explicit refresh: bust the browser-side cache first, otherwise the
	// long-TTL cached monitor response would be served right back.
	const refreshPerformanceData = () => {
		purgeApiCache();
		return loadPerformanceData();
	};
	const loadHistoricalData = (period) =>
		apiLoadHistoricalData(getApiContext(), period);
	const loadOpportunities = () => apiLoadOpportunities(getApiContext());
	const loadDiagnostics = () => apiLoadDiagnostics(getApiContext());

	/**
	 * Render content based on active sub-section
	 */
	const renderSubSectionContent = () => {
		// Only the Core Web Vitals view depends on the (slow, PageSpeed-backed)
		// monitor payload. Opportunities, history and diagnostics have their own
		// data + loading states and must not be blocked behind it.
		const needsMonitorData =
			activeSubSection === 'core-web-vitals' ||
			!['seo-performance', 'monitoring', 'recommendations'].includes(
				activeSubSection
			);

		if (isLoading && !performanceData && needsMonitorData) {
			return (
				<div style={{ textAlign: 'center', padding: '48px' }}>
					<SkeletonCard lines={3} />
				</div>
			);
		}

		// First measurement is still being collected in the background —
		// show an honest progress state instead of a bogus 0/F score. When
		// the last attempt failed, say WHY (e.g. PageSpeed quota exhausted,
		// fixable with a site-owned API key) instead of spinning forever.
		if (performanceData?.status === 'collecting' && needsMonitorData) {
			const collectionError = performanceData?.collection_error;
			const isQuotaError =
				collectionError &&
				/quota|rate limit|429/i.test(collectionError);
			return (
				<Card>
					<CardBody>
						<div
							style={{
								textAlign: 'center',
								padding: '48px 24px',
							}}
						>
							<div
								style={{
									fontSize: '48px',
									marginBottom: '24px',
								}}
							>
								{collectionError ? '⚠️' : '⏱️'}
							</div>
							<Heading level={3} style={{ marginBottom: '12px' }}>
								{collectionError
									? __(
											'Measurement failed, will retry',
											'thinkrank'
										)
									: __('Measuring your site…', 'thinkrank')}
							</Heading>
							<Text variant="muted" style={{ display: 'block' }}>
								{collectionError ||
									__(
										'Google is running a Lighthouse audit of your homepage. This usually takes under a minute. The results will appear automatically.',
										'thinkrank'
									)}
							</Text>
							{isQuotaError && (
								<div style={{ marginTop: '24px' }}>
									<Text
										variant="muted"
										style={{
											display: 'block',
											marginBottom: '12px',
										}}
									>
										{__(
											'Tip: add your own (free) PageSpeed Insights API key to get a dedicated 25,000/day quota for this site.',
											'thinkrank'
										)}
									</Text>
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
										{__(
											'Add PageSpeed API Key',
											'thinkrank'
										)}
									</Button>
								</div>
							)}
						</div>
					</CardBody>
				</Card>
			);
		}

		if (!performanceData && needsMonitorData) {
			return (
				<Card>
					<CardHeader>
						<Heading level={3}>
							{__('Performance Data Not Available', 'thinkrank')}
						</Heading>
					</CardHeader>
					<CardBody>
						<div
							style={{
								textAlign: 'center',
								padding: '48px 24px',
							}}
						>
							<div
								style={{
									fontSize: '48px',
									marginBottom: '24px',
								}}
							>
								⚡
							</div>
							<div style={{ marginBottom: '32px' }}>
								<Text
									style={{
										fontSize: '16px',
										marginBottom: '12px',
										display: 'block',
									}}
								>
									{__(
										'No performance data available. This could be due to missing API configuration.',
										'thinkrank'
									)}
								</Text>
								<Text
									variant="muted"
									style={{ display: 'block' }}
								>
									{__(
										'Add a PageSpeed API key or connect your Google account in Integrations > Google Services to view real performance metrics.',
										'thinkrank'
									)}
								</Text>
							</div>
							<div
								style={{
									display: 'flex',
									justifyContent: 'center',
									gap: '12px',
									flexWrap: 'wrap',
								}}
							>
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
									onClick={refreshPerformanceData}
									disabled={isLoading}
								>
									{isLoading ? (
										<SkeletonCard lines={2} />
									) : (
										__('Refresh Data', 'thinkrank')
									)}
								</Button>
							</div>
						</div>
					</CardBody>
				</Card>
			);
		}

		switch (activeSubSection) {
			case 'core-web-vitals':
				return (
					<CWVSection
						performanceData={performanceData}
						deviceType={deviceType}
						setDeviceType={setDeviceType}
						isLoading={isLoading}
						onNavigate={onNavigate}
						onRefresh={refreshPerformanceData}
					/>
				);
			case 'seo-performance':
				return (
					<OpportunitiesSection
						opportunities={opportunities}
						isLoadingOpportunities={isLoadingOpportunities}
						opportunitiesError={opportunitiesError}
						performanceData={performanceData}
						onNavigate={onNavigate}
						onRetry={loadOpportunities}
					/>
				);
			case 'monitoring':
				return (
					<HistoricalSection
						historicalData={historicalData}
						isLoadingHistoricalData={isLoadingHistoricalData}
						historicalDataError={historicalDataError}
						historicalPeriod={historicalPeriod}
						setHistoricalPeriod={setHistoricalPeriod}
						performanceData={performanceData}
						onNavigate={onNavigate}
						onRetry={() => loadHistoricalData()}
					/>
				);
			case 'recommendations':
				return (
					<DiagnosticsSection
						diagnostics={diagnostics}
						isLoadingDiagnostics={isLoadingDiagnostics}
						diagnosticsError={diagnosticsError}
						onNavigate={onNavigate}
						onRetry={loadDiagnostics}
					/>
				);
			default:
				return (
					<CWVSection
						performanceData={performanceData}
						deviceType={deviceType}
						setDeviceType={setDeviceType}
						isLoading={isLoading}
						onNavigate={onNavigate}
						onRefresh={refreshPerformanceData}
					/>
				);
		}
	};

	// Monitor data (the slow, PageSpeed-backed call): load on mount and when
	// the device toggles. Sub-section switches and history-period changes
	// reuse the already-loaded data instead of re-firing it.
	useEffect(() => {
		loadPerformanceData();
	}, [deviceType]);

	// While the server reports it is still collecting the first measurement
	// (a background Lighthouse run), poll every 15s — capped so a stuck
	// collection doesn't poll forever.
	const collectingAttemptsRef = useRef(0);
	useEffect(() => {
		if (performanceData?.status !== 'collecting') {
			collectingAttemptsRef.current = 0;
			return;
		}
		if (collectingAttemptsRef.current >= 8) {
			return;
		}
		collectingAttemptsRef.current++;
		const timer = setTimeout(() => loadPerformanceData(), 15000);
		return () => clearTimeout(timer);
	}, [performanceData]);

	// Section-specific data, loaded lazily when its section is opened.
	useEffect(() => {
		if (activeSubSection === 'recommendations' && !recommendations) {
			apiLoadRecommendations(getApiContext());
		}
		if (activeSubSection === 'monitoring') {
			loadHistoricalData(historicalPeriod);
		}
		if (
			activeSubSection === 'seo-performance' &&
			opportunities.length === 0 &&
			!isLoadingOpportunities
		) {
			loadOpportunities();
		}
		if (
			activeSubSection === 'recommendations' &&
			diagnostics.length === 0 &&
			!isLoadingDiagnostics
		) {
			loadDiagnostics();
		}
	}, [activeSubSection, historicalPeriod]);

	return (
		<div className="thinkrank-performance-tab">
			<div className="thinkrank-perf-header tr-flex tr-justify-end tr-mb-4">
				<Button
					variant="secondary"
					onClick={refreshPerformanceData}
					disabled={isLoading}
					className="thinkrank-perf-refresh-btn"
				>
					{isLoading
						? __('Refreshing…', 'thinkrank')
						: __('Refresh Data', 'thinkrank')}
				</Button>
			</div>

			{notice && (
				<Notice
					status={notice.status}
					onRemove={() => setNotice(null)}
					style={{ marginBottom: '16px' }}
				>
					{notice.message}
				</Notice>
			)}

			{renderSubSectionContent()}
		</div>
	);
};

export default PerformanceTab;
