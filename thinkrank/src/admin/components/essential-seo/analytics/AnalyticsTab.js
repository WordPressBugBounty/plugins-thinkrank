/**
 * Analytics Tab Component (Shell)
 *
 * SEO Analytics & Intelligence tab — state management, data loading,
 * and section router. All rendering is delegated to sub-components.
 *
 * @package
 * @since 1.11.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Flex } from '@wordpress/components';
import { SkeletonCard } from '../../ui';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	BarElement,
	LineElement,
	PointElement,
	ArcElement,
	Title,
	Tooltip,
	Legend,
	Filler,
} from 'chart.js';

ChartJS.register(
	CategoryScale,
	LinearScale,
	BarElement,
	LineElement,
	PointElement,
	ArcElement,
	Title,
	Tooltip,
	Legend,
	Filler
);

// Import shared components following ThinkRank patterns
import SettingsCard from '../../common/SettingsCard';
import OptimizationButton from '../../common/OptimizationButton';
import useAutosaveSettings from '../../../hooks/useAutosaveSettings';
import logger from '../../../../shared/utils/logger';

// Import sub-section components
import SetupSection from './SetupSection';
import DashboardSection from './DashboardSection';
import OpportunitiesSection from './OpportunitiesSection';
import KeywordsSection from './KeywordsSection';
import EmailReportingPanel from '../email-reporting/EmailReportingPanel';
import ProTeaser from '../../pro/ProTeaser';
import {
	RankTrackerIllustration,
	IndexStatusIllustration,
	TopContentIllustration,
} from '../../pro/pro-teaser-illustrations';

// Import shared utilities
import { getDefaultSettings, getPreviousPeriodDates } from './utils';
import ProGatedTab from '../../pro/ProGatedTab';

/**
 * Analytics Tab Component
 */

const AnalyticsTab = ({ activeSubSection = 'setup', onNavigate }) => {
	// State management following Site Identity patterns
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);

	const [isRefreshing, setIsRefreshing] = useState(false);
	const [settings, setSettings] = useState({});
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);

	const [dashboardData, setDashboardData] = useState(null);
	const [seoOpportunities, setSeoOpportunities] = useState(null);
	const [dateRange, setDateRange] = useState('30d');

	// Period-over-period traffic trends for the dashboard cards, supplied by
	// ThinkRank Pro through `thinkrank.analytics.trafficTrendsLoader`.
	const [trafficTrends, setTrafficTrends] = useState(null);
	const [isLoadingIntelligence, setIsLoadingIntelligence] = useState(false);

	// Previous period comparison state
	const [previousPeriodData, setPreviousPeriodData] = useState(null);

	// Daily Search Console data for the trend chart
	const [searchDailyData, setSearchDailyData] = useState(null);

	// Top Countries data + active tab in the countries/channels card
	const [countryData, setCountryData] = useState(null);
	const [brandedData, setBrandedData] = useState(null);
	const [countryCardTab, setCountryCardTab] = useState('top-countries');

	// Guard so overlapping loadDashboardData() runs don't stack.
	const loadInFlightRef = useRef(false);
	// Set when a load is requested mid-flight; triggers exactly one re-run on
	// completion so a change made during a slow load isn't dropped.
	const reloadQueuedRef = useRef(false);
	const [reloadTick, setReloadTick] = useState(0);

	// Reflects the persisted master toggle (set after settings load or save).
	// Data loads key on this — not on the in-form toggle value — so Google API
	// calls never fire while SEO Analytics is disabled, and flipping the
	// toggle on only starts loading once the setting is actually saved.
	const [persistedEnabled, setPersistedEnabled] = useState(false);

	// Settings only need to load once per mount — they are not
	// sub-section-specific, and reloading them on every sub-section switch
	// added a redundant request per navigation.
	useEffect(() => {
		loadSettings();
	}, []);

	// Tracks which dateRange/reloadTick combination the dashboard data was
	// last loaded for, so switching between sub-sections (which all render
	// from the same loaded state) doesn't re-fire the full 6-9 request load.
	const lastLoadedKeyRef = useRef(null);

	// Single data-load effect keyed on both deps; previously two separate
	// effects both fired on mount and double-loaded. Gated on the persisted
	// master toggle so a disabled feature never hits the Google-backed
	// endpoints (which would surface raw 401s when no account is connected).
	useEffect(() => {
		if (!persistedEnabled) {
			return;
		}
		if (
			activeSubSection === 'dashboard' ||
			activeSubSection === 'opportunities' ||
			activeSubSection === 'keywords'
		) {
			const loadKey = `${dateRange}:${reloadTick}`;
			if (lastLoadedKeyRef.current === loadKey) {
				return;
			}
			lastLoadedKeyRef.current = loadKey;
			loadDashboardData();
		}
	}, [activeSubSection, dateRange, reloadTick, persistedEnabled]);

	// Clear any lingering notice when switching sub-sections so a stale
	// error/info message doesn't carry over to an unrelated view.
	useEffect(() => {
		setNotice(null);
	}, [activeSubSection]);

	/**
	 * Load settings from API
	 * Following Site Identity loadSettings pattern
	 */
	const loadSettings = async () => {
		try {
			setIsLoading(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/settings-management/category/seo_analytics',
				method: 'GET',
			});

			if (response.success && response.data && response.data.settings) {
				const loadedSettings = {
					...getDefaultSettings(),
					...response.data.settings,
				};
				setSettings(loadedSettings);
				setPersistedEnabled(!!loadedSettings.seo_analytics_enabled);
			} else {
				setSettings(getDefaultSettings());
				setPersistedEnabled(false);
			}
		} catch (error) {
			logger.error('Analytics settings load error:', error);
			setSettings(getDefaultSettings());
			setPersistedEnabled(false);
			setNotice({
				status: 'error',
				message: __(
					'Failed to load analytics settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Save settings to API
	 * Following Site Identity saveSettings pattern
	 */
	const saveSettings = async () => {
		try {
			setIsSaving(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/settings-management/category/seo_analytics',
				method: 'POST',
				data: { settings },
			});

			if (response.success) {
				setHasChanges(false);
				// Sync the persisted toggle so the data-load effect starts (or
				// stops) loading without needing a remount.
				setPersistedEnabled(!!settings.seo_analytics_enabled);
				setNotice({
					status: 'success',
					message: __(
						'Analytics settings saved successfully.',
						'thinkrank'
					),
				});
			} else {
				setNotice({
					status: 'error',
					message:
						response.message ||
						__('Failed to save settings.', 'thinkrank'),
				});
			}
		} catch (error) {
			logger.error('Analytics settings save error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to save analytics settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsSaving(false);
		}
	};

	/**
	 * Handle setting changes
	 * Following Site Identity handleSettingChange pattern
	 * @param key
	 * @param value
	 */
	const handleSettingChange = (key, value) => {
		setSettings((prev) => ({
			...prev,
			[key]: value,
		}));
		setHasChanges(true);
	};

	// The master switch IS the save: flipping it persists immediately and rolls
	// back if the request fails. Everything else on this tab keeps using the
	// Save Settings button.
	const { update: updateFeature, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				const response = await apiFetch({
					path: '/thinkrank/v1/settings-management/category/seo_analytics',
					method: 'POST',
					data: { settings: next },
				});

				if (!response.success) {
					throw new Error(
						response.message ||
							__('Failed to save settings.', 'thinkrank')
					);
				}

				// Keep the data-load effect in sync with what is persisted, so
				// the Google-backed endpoints start (or stop) without a remount.
				setPersistedEnabled(!!next.seo_analytics_enabled);
			},
			logLabel: 'SEO Analytics',
		});

	/**
	 * Load dashboard data with intelligence enhancements
	 */
	const loadDashboardData = async () => {
		// Never hit the Google-backed endpoints while the master toggle is
		// off — with no connected account they only produce raw 401 errors.
		if (!settings.seo_analytics_enabled) {
			return;
		}

		// In-flight guard so overlapping loads don't stack. If a load is
		// requested while one is running (e.g. a date-range change), queue a
		// single re-run for when it finishes instead of dropping it.
		if (loadInFlightRef.current) {
			reloadQueuedRef.current = true;
			return;
		}
		loadInFlightRef.current = true;

		try {
			setIsRefreshing(true);
			setIsLoadingIntelligence(true);
			setPreviousPeriodData(null);
			setSearchDailyData(null);

			// Independent requests run concurrently instead of serial awaits.
			const tasks = [];

			// Load basic dashboard data (maintain backward compatibility)
			tasks.push(
				(async () => {
					const dashboardResponse = await apiFetch({
						path: `/thinkrank/v1/seo-analytics/dashboard?date_range=${dateRange}`,
						method: 'GET',
					});

					if (dashboardResponse.success) {
						setDashboardData(dashboardResponse.data);
					}
				})()
			);

			// Fetch previous period Search Console totals for period comparison badges
			tasks.push(
				(async () => {
					try {
						const { prevStart, prevEnd } =
							getPreviousPeriodDates(dateRange);
						const prevPeriodResponse = await apiFetch({
							path: `/thinkrank/v1/seo-analytics/search-totals?start_date=${prevStart}&end_date=${prevEnd}`,
							method: 'GET',
						});
						if (prevPeriodResponse.success) {
							setPreviousPeriodData(prevPeriodResponse.data);
						}
					} catch (prevError) {
						// Previous period data is optional — silently skip if unavailable
						setPreviousPeriodData(null);
					}
				})()
			);

			// Fetch daily Search Console data for the trend chart
			tasks.push(
				(async () => {
					try {
						const dailyResponse = await apiFetch({
							path: `/thinkrank/v1/seo-analytics/search-daily?date_range=${dateRange}`,
							method: 'GET',
						});
						if (dailyResponse.success) {
							setSearchDailyData(dailyResponse.data);
						}
					} catch (dailyError) {
						// Daily data is optional — chart falls back gracefully if unavailable
						setSearchDailyData(null);
					}
				})()
			);

			// Fetch top countries from Search Console
			tasks.push(
				(async () => {
					try {
						const countriesResponse = await apiFetch({
							path: `/thinkrank/v1/seo-analytics/countries?date_range=${dateRange}`,
							method: 'GET',
						});
						if (countriesResponse.success) {
							setCountryData(countriesResponse.data);
						}
					} catch (_) {
						setCountryData(null);
					}
				})()
			);

			// Fetch branded vs non-branded breakdown from Search Console
			tasks.push(
				(async () => {
					try {
						const brandedResponse = await apiFetch({
							path: `/thinkrank/v1/seo-analytics/branded?date_range=${dateRange}`,
							method: 'GET',
						});
						if (brandedResponse.success) {
							setBrandedData(brandedResponse.data);
						}
					} catch (_) {
						setBrandedData(null);
					}
				})()
			);

			// Load basic SEO opportunities (maintain backward compatibility)
			tasks.push(
				(async () => {
					const opportunitiesResponse = await apiFetch({
						path: `/thinkrank/v1/seo-analytics/opportunities?date_range=${dateRange}`,
						method: 'GET',
					});

					if (opportunitiesResponse.success) {
						setSeoOpportunities(opportunitiesResponse.data);
					}
				})()
			);

			// Traffic trends for the dashboard cards come from ThinkRank Pro. Ask
			// the plan before the filter: Pro registers its filters even when
			// unlicensed, and its route only exists behind the licence check.
			const loadTrafficTrends = window.thinkrankAdmin?.isPro
				? applyFilters('thinkrank.analytics.trafficTrendsLoader', null)
				: null;
			if (
				settings.seo_analytics_enable_ai_insights &&
				typeof loadTrafficTrends === 'function'
			) {
				tasks.push(
					(async () => {
						try {
							setTrafficTrends(
								await loadTrafficTrends(dateRange)
							);
						} catch (_) {
							// Trends are an optional badge on each card.
							setTrafficTrends(null);
						}
					})()
				);
			}

			await Promise.all(tasks);
		} catch (error) {
			logger.error('Dashboard data load error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to load analytics data. Please check your Google API connections in the Integrations tab.',
					'thinkrank'
				),
			});
		} finally {
			setIsRefreshing(false);
			setIsLoadingIntelligence(false);
			loadInFlightRef.current = false;
			// A load was requested while this one ran — re-run once with the
			// latest deps (fresh closure via the effect) so nothing is lost.
			if (reloadQueuedRef.current) {
				reloadQueuedRef.current = false;
				setReloadTick((t) => t + 1);
			}
		}
	};

	/**
	 * Refresh analytics data
	 */
	const refreshData = async () => {
		try {
			setIsRefreshing(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/seo-analytics/refresh',
				method: 'POST',
			});

			if (response.success) {
				setNotice({
					status: 'success',
					message: response.data.message,
				});
				// Reload dashboard data
				await loadDashboardData();
			} else {
				setNotice({
					status: 'error',
					message:
						response.message ||
						__('Failed to refresh data.', 'thinkrank'),
				});
			}
		} catch (error) {
			logger.error('Data refresh error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to refresh analytics data. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsRefreshing(false);
		}
	};

	/**
	 * Get optimization button configuration based on active section
	 * Following Site Identity getOptimizationButtonConfig pattern
	 */
	const getOptimizationButtonConfig = () => {
		switch (activeSubSection) {
			case 'setup':
				return null; // No button for setup section
			case 'dashboard':
			case 'opportunities':
			case 'keywords':
				return {
					type: 'refresh',
					label: __('Refresh Data', 'thinkrank'),
					loadingLabel: __('Refreshing…', 'thinkrank'),
					onClick: refreshData,
					isBusy: isRefreshing || isLoadingIntelligence,
					disabled:
						!settings.seo_analytics_enabled ||
						isRefreshing ||
						isLoadingIntelligence,
				};
			default:
				return null;
		}
	};

	/**
	 * Render loading state
	 * Following Site Identity loading pattern
	 */
	if (isLoading) {
		return (
			<div className="thinkrank-flex thinkrank-justify-center thinkrank-items-center thinkrank-p-8">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	/**
	 * Render content based on active sub-section
	 * Following Performance tab renderContent pattern
	 */
	const renderSubSectionContent = () => {
		// Email Reporting has its own enable toggle and degrades gracefully
		// when GA4/GSC aren't connected — let users reach it without first
		// enabling the parent Analytics integration.
		if (activeSubSection === 'email-reporting') {
			return <EmailReportingPanel onNavigate={onNavigate} />;
		}

		if (!settings.seo_analytics_enabled) {
			return null;
		}

		switch (activeSubSection) {
			case 'setup':
				return <SetupSection onNavigate={onNavigate} />;
			case 'dashboard':
				return (
					<DashboardSection
						settings={settings}
						dateRange={dateRange}
						setDateRange={setDateRange}
						dashboardData={dashboardData}
						searchDailyData={searchDailyData}
						previousPeriodData={previousPeriodData}
						countryData={countryData}
						brandedData={brandedData}
						countryCardTab={countryCardTab}
						setCountryCardTab={setCountryCardTab}
						trafficTrends={trafficTrends}
						isRefreshing={isRefreshing}
					/>
				);
			case 'opportunities':
				return (
					<OpportunitiesSection
						dateRange={dateRange}
						setDateRange={setDateRange}
						seoOpportunities={seoOpportunities}
						isRefreshing={isRefreshing}
					/>
				);
			case 'keywords':
				return (
					<KeywordsSection
						dateRange={dateRange}
						setDateRange={setDateRange}
						dashboardData={dashboardData}
					/>
				);
			case 'rank-tracker':
				return (
					<ProGatedTab
						filter="thinkrank.analytics.rankTrackerTab"
						teaser={
							<ProTeaser
								illustration={<RankTrackerIllustration />}
								title={__('Rank Tracker', 'thinkrank')}
								description={__(
									'Know exactly where you rank. Track your target keywords over time so you can see what is working and react before you lose positions.',
									'thinkrank'
								)}
								features={[
									__(
										'Monitor daily keyword positions straight from Search Console',
										'thinkrank'
									),
									__(
										'Spot rank gains and drops at a glance with change indicators',
										'thinkrank'
									),
									__(
										'Follow your progress over time to prove your SEO results',
										'thinkrank'
									),
								]}
							/>
						}
					/>
				);
			case 'traffic-recovery':
				return (
					<ProGatedTab
						filter="thinkrank.analytics.trafficRecoveryTab"
						teaser={
							<ProTeaser
								illustration={<TopContentIllustration />}
								title={__('Traffic Recovery', 'thinkrank')}
								description={__(
									'Rankings decay over time. Traffic Recovery finds the posts quietly losing search traffic and tells you exactly what to update to win it back.',
									'thinkrank'
								)}
								features={[
									__(
										'Automatically detect decaying content from your Search Console data',
										'thinkrank'
									),
									__(
										'Get an AI refresh brief for each post: what is outdated and what to add',
										'thinkrank'
									),
									__(
										'Apply an optimized title and meta description, then re-score in one click',
										'thinkrank'
									),
								]}
							/>
						}
					/>
				);
			case 'site-analytics':
				return (
					<ProGatedTab filter="thinkrank.analytics.siteAnalyticsTab" />
				);
			case 'index-status':
				return (
					<ProGatedTab
						filter="thinkrank.analytics.indexStatusTab"
						teaser={
							<ProTeaser
								illustration={<IndexStatusIllustration />}
								title={__('Index Status', 'thinkrank')}
								description={__(
									'If Google has not indexed a page, it cannot rank. Index Status shows you which pages are indexed so you can fix the ones that are being missed.',
									'thinkrank'
								)}
								features={[
									__(
										'See indexed, not-indexed, and crawled counts at a glance',
										'thinkrank'
									),
									__(
										'Review the index status and last-crawled date for every page',
										'thinkrank'
									),
									__(
										'Catch pages Google is missing before they cost you traffic',
										'thinkrank'
									),
								]}
							/>
						}
					/>
				);
			default:
				return <SetupSection onNavigate={onNavigate} />;
		}
	};

	return (
		<div className="thinkrank-analytics-tab">
			{/* Main Settings Card */}
			<SettingsCard
				title={__('SEO Analytics & Intelligence', 'thinkrank')}
				enabled={settings.seo_analytics_enabled}
				onToggle={(value) =>
					updateFeature(
						{ seo_analytics_enabled: value },
						value
							? __('SEO Analytics is on.', 'thinkrank')
							: __('SEO Analytics is off.', 'thinkrank')
					)
				}
				toggleLabel={__('Enable SEO Analytics', 'thinkrank')}
				toggleBusy={isTogglingFeature}
				optimizationButtons={[
					(() => {
						const buttonConfig = getOptimizationButtonConfig();
						return buttonConfig ? (
							<OptimizationButton
								key="action"
								type={buttonConfig.type}
								label={buttonConfig.label}
								loadingLabel={buttonConfig.loadingLabel}
								onClick={buttonConfig.onClick}
								isBusy={buttonConfig.isBusy}
								disabled={buttonConfig.disabled}
							/>
						) : null;
					})(),
					...applyFilters(
						'thinkrank.analytics.headerActions',
						[],
						activeSubSection
					),
				].filter(Boolean)}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
				showDisabledWarning={true}
				disabledWarningMessage={__(
					'SEO Analytics features are disabled. Enable to access Google API integration and AI insights.',
					'thinkrank'
				)}
			>
				{/* Render content based on active sub-section */}
				<div className="thinkrank-py-lg">
					{renderSubSectionContent()}
				</div>
			</SettingsCard>

			{/* Save Button */}
			{hasChanges && (
				<Flex justify="flex-end" className="thinkrank-mt-lg">
					<div>
						<Button
							variant="primary"
							onClick={saveSettings}
							isBusy={isSaving}
							disabled={isSaving}
						>
							{isSaving
								? __('Saving…', 'thinkrank')
								: __('Save Settings', 'thinkrank')}
						</Button>
					</div>
				</Flex>
			)}
		</div>
	);
};

export default AnalyticsTab;
