/**
 * Usage Analytics Data Store
 *
 * Manages AI usage analytics and performance metrics
 *
 * @package
 * @since 1.0.0
 */

import { createReduxStore } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import logger from '../../shared/utils/logger';

// Initial state
const DEFAULT_STATE = {
	metrics: {},
	reports: {},
	costs: {},
	usage: {},
	isLoading: false,
	error: null,
	lastUpdated: null,
};

// Action types
const TYPES = {
	SET_METRICS: 'SET_METRICS',
	SET_REPORTS: 'SET_REPORTS',
	SET_COSTS: 'SET_COSTS',
	SET_USAGE: 'SET_USAGE',
	SET_LOADING: 'SET_LOADING',
	SET_ERROR: 'SET_ERROR',
	SET_LAST_UPDATED: 'SET_LAST_UPDATED',
	CLEAR_ERROR: 'CLEAR_ERROR',
};

// Reducer
const reducer = (state = DEFAULT_STATE, action) => {
	switch (action.type) {
		case TYPES.SET_METRICS:
			return {
				...state,
				metrics: action.metrics,
				error: null, // Clear error on successful data fetch
			};

		case TYPES.SET_REPORTS:
			return {
				...state,
				reports: action.reports,
				error: null,
			};

		case TYPES.SET_COSTS:
			return {
				...state,
				costs: action.costs,
				error: null,
			};

		case TYPES.SET_USAGE:
			return {
				...state,
				usage: action.usage,
				error: null,
			};

		case TYPES.SET_LOADING:
			return {
				...state,
				isLoading: action.loading,
			};

		case TYPES.SET_ERROR:
			return {
				...state,
				error: action.error,
				isLoading: false, // Stop loading on error
			};

		case TYPES.CLEAR_ERROR:
			return {
				...state,
				error: null,
			};

		case TYPES.SET_LAST_UPDATED:
			return {
				...state,
				lastUpdated: action.timestamp,
			};

		default:
			return state;
	}
};

// Actions
const actions = {
	setMetrics(metrics) {
		return {
			type: TYPES.SET_METRICS,
			metrics,
		};
	},

	setReports(reports) {
		return {
			type: TYPES.SET_REPORTS,
			reports,
		};
	},

	setCosts(costs) {
		return {
			type: TYPES.SET_COSTS,
			costs,
		};
	},

	setUsage(usage) {
		return {
			type: TYPES.SET_USAGE,
			usage,
		};
	},

	setLoading(loading) {
		return {
			type: TYPES.SET_LOADING,
			loading,
		};
	},

	setError(error) {
		return {
			type: TYPES.SET_ERROR,
			error,
		};
	},

	clearError() {
		return {
			type: TYPES.CLEAR_ERROR,
		};
	},

	setLastUpdated(timestamp) {
		return {
			type: TYPES.SET_LAST_UPDATED,
			timestamp,
		};
	},

	// Simple action that returns a function for async operations
	fetchMetrics: (period = '30d', userId = 0) => {
		return async ({ dispatch }) => {
			dispatch(actions.setLoading(true));
			dispatch(actions.clearError());

			try {
				const response = await apiFetch({
					path: `/thinkrank/v1/analytics/overview?period=${period}&user_id=${userId}`,
				});

				if (response && response.success && response.data) {
					dispatch(actions.setMetrics(response.data));
					dispatch(actions.setLastUpdated(Date.now()));
				} else {
					throw new Error(
						response?.message || 'Failed to fetch analytics data'
					);
				}
			} catch (error) {
				logger.error('Failed to fetch analytics metrics:', error);

				let errorMessage = 'Failed to load analytics data';
				if (error.message) {
					if (error.message.includes('404')) {
						errorMessage = 'Analytics API endpoint not found.';
					} else if (error.message.includes('403')) {
						errorMessage =
							'You do not have permission to view analytics data.';
					} else if (error.message.includes('500')) {
						errorMessage =
							'Server error while loading analytics data.';
					} else {
						errorMessage = error.message;
					}
				}

				dispatch(
					actions.setError({
						message: errorMessage,
						code: error.code || 'fetch_error',
						type: 'metrics',
					})
				);
			} finally {
				dispatch(actions.setLoading(false));
			}
		};
	},

	fetchCostAnalysis: (period = '30d', provider = 'all', userId = 0) => {
		return async ({ dispatch }) => {
			dispatch(actions.setLoading(true));
			dispatch(actions.clearError());

			try {
				const response = await apiFetch({
					path: `/thinkrank/v1/analytics/costs?period=${period}&provider=${provider}&user_id=${userId}`,
				});

				if (response && response.success && response.data) {
					dispatch(actions.setCosts(response.data));
				} else {
					throw new Error(
						response?.message || 'Failed to fetch cost data'
					);
				}
			} catch (error) {
				logger.error('Failed to fetch cost analysis:', error);
				dispatch(
					actions.setError({
						message: error.message || 'Failed to load cost data',
						code: error.code || 'fetch_error',
						type: 'costs',
					})
				);
			} finally {
				dispatch(actions.setLoading(false));
			}
		};
	},

	fetchReports: (type = 'overview') => {
		return async ({ dispatch }) => {
			dispatch(actions.setLoading(true));
			dispatch(actions.clearError());

			try {
				// Placeholder for future reports implementation
				const reports = {
					type,
					message: 'Reports feature coming in Phase 4',
				};

				dispatch(actions.setReports(reports));
			} catch (error) {
				logger.error('Failed to fetch reports:', error);
				dispatch(
					actions.setError({
						message: error.message || 'Failed to load reports',
						code: error.code || 'fetch_error',
						type: 'reports',
					})
				);
			} finally {
				dispatch(actions.setLoading(false));
			}
		};
	},
};

// Selectors
const selectors = {
	// Basic state selectors
	getMetrics(state) {
		return state.metrics;
	},

	getReports(state) {
		return state.reports;
	},

	getCosts(state) {
		return state.costs;
	},

	getUsage(state) {
		return state.usage;
	},

	getIsLoading(state) {
		return state.isLoading;
	},

	getError(state) {
		return state.error;
	},

	getLastUpdated(state) {
		return state.lastUpdated;
	},

	// Metrics selectors
	getContentOptimized(state) {
		return state.metrics.content_optimized || 0;
	},

	getAverageSeoScore(state) {
		return state.metrics.average_seo_score || 0;
	},

	getCreditsUsed(state) {
		return state.metrics.credits_used || 0;
	},

	getTimeSaved(state) {
		return state.metrics.time_saved || 0;
	},

	getAiActions(state) {
		return state.metrics.ai_actions || 0;
	},

	getContentBriefs(state) {
		return state.metrics.content_briefs || 0;
	},

	getFeaturesUsedCount(state) {
		return state.metrics.features_used_count || 0;
	},

	getMostUsedFeature(state) {
		return state.metrics.most_used_feature || '';
	},

	getMostUsedCount(state) {
		return state.metrics.most_used_count || 0;
	},

	getFeatureBreakdown(state) {
		return state.metrics.feature_breakdown || {};
	},

	getProviderBreakdown(state) {
		return state.metrics.provider_breakdown || {};
	},

	// Cost-related selectors
	getTotalCosts(state) {
		return state.metrics.total_cost || 0;
	},

	getCostsByProvider(state) {
		const breakdown = state.metrics.provider_breakdown || {};
		return {
			openai: breakdown.openai?.cost || 0,
			claude: breakdown.claude?.cost || 0,
		};
	},

	getEstimatedMonthlyCost(state) {
		const totalCost = state.metrics.total_cost || 0;
		const period = state.metrics.period || '30d';

		// Calculate monthly projection based on current period
		switch (period) {
			case '7d':
				return totalCost * (30 / 7);
			case '30d':
				return totalCost;
			case '90d':
				return totalCost / 3;
			default:
				return totalCost;
		}
	},

	getCostEfficiency(state) {
		const totalCost = state.metrics.total_cost || 0;
		const contentOptimized = state.metrics.content_optimized || 0;
		const avgScore = state.metrics.average_seo_score || 0;

		return {
			costPerOptimizedPost:
				contentOptimized > 0 ? totalCost / contentOptimized : 0,
			costPerSeoPoint: avgScore > 0 ? totalCost / avgScore : 0,
			timeSavedValue: (state.metrics.time_saved || 0) * 0.5, // Assuming $30/hour, 0.5 per minute
		};
	},

	// Usage trend selectors. The /analytics/usage endpoint returns
	// { usage_records, pagination, period } — read usage_records, not the
	// never-supplied `trend`/`by_feature` keys the old selectors referenced.
	getUsageTrend(state) {
		return state.usage.usage_records || [];
	},

	getFeatureUsageBreakdown(state) {
		// Backend does not currently aggregate by feature; derive it from the
		// usage_records so consumers get real data instead of an empty object.
		const records = state.usage.usage_records || [];
		return records.reduce((acc, record) => {
			const feature = record.feature || record.feature_type || 'unknown';
			acc[feature] = (acc[feature] || 0) + 1;
			return acc;
		}, {});
	},

	// Error handling selectors
	hasError(state) {
		return !!state.error;
	},

	getErrorMessage(state) {
		return state.error?.message || '';
	},

	getErrorType(state) {
		return state.error?.type || '';
	},

	// Data availability selectors
	hasData(state) {
		const metrics = state.metrics;
		return !!(
			metrics.content_optimized ||
			metrics.ai_actions ||
			metrics.total_cost
		);
	},

	isDataStale(state) {
		if (!state.lastUpdated) return true;
		const fiveMinutes = 5 * 60 * 1000;
		return Date.now() - state.lastUpdated > fiveMinutes;
	},

	// Combined selectors for dashboard cards (8-card layout)
	getDashboardMetrics(state) {
		// Format most used feature display
		const mostUsedFeature = state.metrics.most_used_feature || '';
		const mostUsedCount = state.metrics.most_used_count || 0;

		// Get contextual subtitle based on feature type
		const getFeatureSubtitle = (feature, count) => {
			if (!feature || count === 0) return '';

			// Map feature types to their action descriptions
			const actionMap = {
				content_brief: 'Generated',
				seo_metadata: 'Optimized',
				content_analysis: 'Analyzed',
				site_identity_optimization: 'Optimized',
				homepage_meta_optimization: 'Optimized',
				homepage_hero_optimization: 'Optimized',
				llms_txt_optimization: 'Generated',
			};

			const action = actionMap[feature] || 'Used';
			return `${count} ${action}`;
		};

		const mostUsedDisplay = mostUsedFeature
			? mostUsedFeature
					.replace(/_/g, ' ')
					.replace(/\b\w/g, (l) => l.toUpperCase())
					.replace(/\bSeo\b/g, 'SEO')
			: 'None';
		const mostUsedSubtitle = getFeatureSubtitle(
			mostUsedFeature,
			mostUsedCount
		);

		return {
			// Row 1: Core Performance Metrics
			postsAnalyzed: {
				value: state.metrics.content_optimized || 0,
				change: state.metrics.content_optimized_change ?? null,
				label: __('Posts Analyzed', 'thinkrank'),
			},
			aiOptimizations: {
				value: state.metrics.ai_actions || 0,
				change: 0, // No change tracking for total actions yet
				label: __('AI Optimizations', 'thinkrank'),
			},
			averageSeoScore: {
				value: state.metrics.average_seo_score || 0,
				change: state.metrics.seo_score_change ?? null,
				label: __('Average SEO Score', 'thinkrank'),
				suffix: '/100',
			},
			totalCost: {
				value: state.metrics.total_cost || 0,
				change: state.metrics.cost_change ?? null,
				label: __('Estimated AI Cost', 'thinkrank'),
				prefix: '$',
				subtitle: `${state.metrics.total_tokens || 0} tokens`,
			},
			// Row 2: Feature & Efficiency Metrics
			featuresUsed: {
				value: state.metrics.features_used_count || 0,
				change: 0, // No change tracking for features count yet
				label: __('Features Used', 'thinkrank'),
			},
			timeSaved: {
				value: Math.round((state.metrics.time_saved || 0) / 60),
				change: state.metrics.time_saved_change ?? null,
				label: __('Time Saved', 'thinkrank'),
				suffix: 'h',
				subtitle: `${state.metrics.ai_actions || 0} AI actions`,
			},
			mostUsedFeature: {
				value: mostUsedDisplay,
				change: 0, // No change tracking for most used feature yet
				label: __('Most Used Feature', 'thinkrank'),
				subtitle: mostUsedSubtitle,
				isText: true,
			},
		};
	},
};

// Create and export store
const store = createReduxStore('thinkrank/analytics', {
	reducer,
	actions,
	selectors,
});

export default store;
