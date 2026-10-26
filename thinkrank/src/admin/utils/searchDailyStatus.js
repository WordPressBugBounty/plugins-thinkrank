/**
 * Day statuses of the `/seo-analytics/search-daily` series (#1272).
 *
 * Each row carries `status`: `final`, `preliminary` (Google is still
 * processing the day, so the numbers can change), `zero` (a real day without
 * search traffic; position is null) or `unavailable` (Google has no data for
 * the day yet; every metric is null). Chart.js leaves a gap at a null point,
 * so nothing plots a missing value as 0. A cached payload from before the
 * statuses existed has no `status`, and reads as final.
 *
 * Shared by the Analytics › Dashboard chart and the wp-admin Dashboard widget.
 */

import { __ } from '@wordpress/i18n';

export const isPreliminaryDay = (row) => row?.status === 'preliminary';

export const isUnavailableDay = (row) => row?.status === 'unavailable';

/**
 * Chart.js `segment` option drawing the line into a preliminary day dashed.
 *
 * @param {Array} rows Daily rows, in chart order.
 * @return {Object} Dataset `segment` option.
 */
export const preliminarySegment = (rows) => ({
	borderDash: (ctx) =>
		isPreliminaryDay(rows[ctx.p1DataIndex]) ? [5, 4] : undefined,
});

/**
 * A short label for a day's status, or '' for a final or zero day.
 *
 * @param {Object} row Daily row.
 * @return {string} Label.
 */
export const dayStatusLabel = (row) => {
	if (isPreliminaryDay(row)) {
		return __('Preliminary', 'thinkrank');
	}
	if (isUnavailableDay(row)) {
		return __('No data yet', 'thinkrank');
	}
	return '';
};

/**
 * The note under a chart explaining dashed lines and gaps, or ''.
 *
 * @param {Array} rows Daily rows.
 * @return {string} Note.
 */
export const dailyStatusNote = (rows) => {
	const preliminary = rows.some(isPreliminaryDay);
	const unavailable = rows.some(isUnavailableDay);

	if (preliminary && unavailable) {
		return __(
			'Dashed lines are preliminary: Google is still processing those days. Gaps are days Google has no data for yet.',
			'thinkrank'
		);
	}
	if (preliminary) {
		return __(
			'Dashed lines are preliminary: Google is still processing those days.',
			'thinkrank'
		);
	}
	if (unavailable) {
		return __('Gaps are days Google has no data for yet.', 'thinkrank');
	}
	return '';
};
