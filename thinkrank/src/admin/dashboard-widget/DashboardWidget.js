/**
 * ThinkRank Website Insights — Dashboard Widget
 *
 * Two states:
 *  - Not connected → empty state with a "Connect to Google Search Console" CTA.
 *  - Connected     → last 30 days of Search Console traffic (clicks + impressions).
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import EmptyState from './components/EmptyState';
import TrafficDisplay from './components/TrafficDisplay';
import { DEFAULT_DATE_RANGE } from './constants';
import logger from '../../shared/utils/logger';

export default function DashboardWidget({ config }) {
	const [connected] = useState(Boolean(config.connected));
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(Boolean(config.connected));
	// Structured so the UI can explain the cause and offer the matching fix:
	// { reason, message, details }. `reason` comes from the REST error payload.
	const [error, setError] = useState(null);

	const fetchData = useCallback(() => {
		setLoading(true);
		setError(null);

		apiFetch({
			path: `/thinkrank/v1/seo-analytics/search-daily?date_range=${DEFAULT_DATE_RANGE}`,
		})
			.then((response) => {
				if (response?.success === false) {
					setError({
						reason: response?.data?.reason || '',
						message:
							response.message ||
							__(
								'Unable to load Search Console data.',
								'thinkrank'
							),
						details: response?.data?.details || '',
					});
					setData(null);
					return;
				}

				setData(response?.data || {});
			})
			.catch((err) => {
				logger.error('ThinkRank: dashboard widget fetch failed', err);
				setError({
					reason: err?.data?.reason || '',
					message:
						err?.message ||
						__('Unable to load Search Console data.', 'thinkrank'),
					details: err?.data?.details || '',
				});
				setData(null);
			})
			.finally(() => setLoading(false));
	}, []);

	useEffect(() => {
		if (connected) {
			fetchData();
		}
	}, [connected, fetchData]);

	if (!connected) {
		return <EmptyState config={config} />;
	}

	return (
		<TrafficDisplay
			config={config}
			data={data}
			loading={loading}
			error={error}
			onRetry={fetchData}
		/>
	);
}
