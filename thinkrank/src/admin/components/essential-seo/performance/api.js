/**
 * Performance Tab API Functions
 *
 * All API interaction functions for performance monitoring.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Load performance data
 * @param ctx
 */
export const loadPerformanceData = async (ctx) => {
	try {
		ctx.setIsLoading(true);
		ctx.setNotice(null);

		const response = await apiFetch({
			path: `/thinkrank/v1/performance/monitor?device_type=${ctx.deviceType}`,
			method: 'GET',
		});

		if (response.success) {
			ctx.setPerformanceData(response.data);
			ctx.setLastUpdated(new Date().toLocaleString());
		} else {
			throw new Error(
				response.error || 'Failed to load performance data'
			);
		}
	} catch (error) {
		if (
			error.message.includes('API key is required') ||
			error.message.includes('not configured') ||
			error.message.includes('not connected')
		) {
			// Silently handled — CWVSection empty state guides the user
		} else if (error.message.includes('rate limit exceeded')) {
			ctx.setNotice({
				status: 'warning',
				message: __(
					'Google API rate limit exceeded. Please try again later.',
					'thinkrank'
				),
			});
		} else if (
			error.message.includes('invalid API key') ||
			error.message.includes('unauthorized')
		) {
			ctx.setNotice({
				status: 'error',
				message: __(
					'Google account token is expired or invalid. Please reconnect your Google account in Integrations > Google Services.',
					'thinkrank'
				),
			});
		} else {
			ctx.setNotice({
				status: 'error',
				message: __(
					'Failed to load performance data. Please check your API configuration in Integrations > Google Services.',
					'thinkrank'
				),
			});
		}
		ctx.setPerformanceData(null);
	} finally {
		ctx.setIsLoading(false);
	}
};

/**
 * Load recommendations data
 * @param ctx
 */
export const loadRecommendations = async (ctx) => {
	try {
		const response = await apiFetch({
			path: '/thinkrank/v1/performance/recommendations',
			method: 'GET',
		});

		if (response.success) {
			ctx.setRecommendations(response.data);
		}
	} catch (error) {
		// Silently fail for recommendations - not critical
	}
};

/**
 * Load historical data for the selected period.
 * Transforms { "YYYY-MM-DD": { lcp, inp, ... } } into { lcp: [{date, value}], ... }
 * @param ctx
 * @param period
 */
export const loadHistoricalData = async (ctx, period) => {
	ctx.setIsLoadingHistoricalData(true);
	ctx.setHistoricalDataError(null);
	try {
		const days = period ?? ctx.historicalPeriod;
		const response = await apiFetch({
			path: `/thinkrank/v1/performance/history?days=${days}`,
			method: 'GET',
		});

		if (response.success) {
			const raw = response.data;

			if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
				ctx.setHistoricalData(null);
				return;
			}

			const metrics = ['lcp', 'cls', 'inp', 'performance_score'];
			const transformed = {};

			metrics.forEach((metric) => {
				transformed[metric] = Object.entries(raw)
					.map(([date, values]) => ({
						date,
						value: values[metric] ?? null,
					}))
					.filter((point) => point.value !== null);
			});

			ctx.setHistoricalData(transformed);
		} else {
			ctx.setHistoricalDataError(
				__('Failed to load historical data.', 'thinkrank')
			);
		}
	} catch (error) {
		ctx.setHistoricalDataError(
			__('Unable to connect to the performance API.', 'thinkrank')
		);
	} finally {
		ctx.setIsLoadingHistoricalData(false);
	}
};

/**
 * Load opportunities data
 * @param ctx
 */
export const loadOpportunities = async (ctx) => {
	ctx.setIsLoadingOpportunities(true);
	ctx.setOpportunitiesError(null);

	try {
		const response = await apiFetch({
			path: `/thinkrank/v1/performance/opportunities?device_type=${ctx.deviceType}`,
			method: 'GET',
		});

		if (response.success) {
			ctx.setOpportunities(response.data || []);
		} else {
			// The endpoint says why when it did not fetch — no PageSpeed
			// credential, or a failed request. Prefer that over a generic line.
			ctx.setOpportunitiesError({
				message:
					response.message ||
					__('Failed to load opportunities data', 'thinkrank'),
				code: response.error_code || '',
			});
		}
	} catch (error) {
		ctx.setOpportunitiesError({
			message: __('Unable to connect to performance API', 'thinkrank'),
			code: '',
		});
	} finally {
		ctx.setIsLoadingOpportunities(false);
	}
};

/**
 * Load diagnostics data
 * @param ctx
 */
export const loadDiagnostics = async (ctx) => {
	ctx.setIsLoadingDiagnostics(true);
	ctx.setDiagnosticsError(null);

	try {
		const response = await apiFetch({
			path: `/thinkrank/v1/performance/diagnostics?device_type=${ctx.deviceType}`,
			method: 'GET',
		});

		if (response.success) {
			ctx.setDiagnostics(response.data || []);
		} else {
			// The endpoint says why when it did not fetch — no PageSpeed
			// credential, or a failed request. Prefer that over a generic line.
			ctx.setDiagnosticsError({
				message:
					response.message ||
					__('Failed to load diagnostics data', 'thinkrank'),
				code: response.error_code || '',
			});
		}
	} catch (error) {
		ctx.setDiagnosticsError({
			message: __('Unable to connect to performance API', 'thinkrank'),
			code: '',
		});
	} finally {
		ctx.setIsLoadingDiagnostics(false);
	}
};
