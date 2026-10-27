/**
 * Opening hours: one or more ranges per day.
 *
 * The client side of ThinkRank\SEO\Business_Hours (#1119). A day is
 * `{open, close, closed}` and may carry `slots`, a list of `{open, close}`
 * ranges. `open`/`close` mirror the first range, so a reader that predates
 * `slots` still gets a valid day; a day with no `slots` is the single range
 * it always was.
 *
 * @package
 * @since 2.19.0
 */

/**
 * The most ranges one day may hold. Same as Business_Hours::MAX_SLOTS.
 *
 * @type {number}
 */
export const MAX_SLOTS = 4;

/**
 * A day's ranges as entered, ignoring whether it is closed.
 *
 * @param {Object} day One stored day.
 * @return {Array<{open: string, close: string}>} Ranges.
 */
export const rangesOf = (day) => {
	if (!day || typeof day !== 'object') {
		return [];
	}

	if (Array.isArray(day.slots) && day.slots.length > 0) {
		return day.slots.map((slot) => ({
			open: slot?.open || '',
			close: slot?.close || '',
		}));
	}

	return [{ open: day.open || '', close: day.close || '' }];
};

/**
 * The day with these ranges, `open`/`close` mirroring the first.
 *
 * @param {Object} day    One stored day.
 * @param {Array}  ranges Ranges to store.
 * @return {Object} The updated day.
 */
export const withRanges = (day, ranges) => ({
	...day,
	open: ranges[0]?.open || '',
	close: ranges[0]?.close || '',
	slots: ranges,
});

/**
 * Minutes since midnight for an HH:MM time, or null.
 *
 * @param {string} time HH:MM.
 * @return {number|null} Minutes.
 */
const minutes = (time) => {
	const match = /^(\d{1,2}):(\d{2})$/.exec(time || '');
	return match ? Number(match[1]) * 60 + Number(match[2]) : null;
};

/**
 * HH:MM for minutes since midnight, capped at 23:59.
 *
 * @param {number} total Minutes.
 * @return {string} HH:MM.
 */
const clock = (total) => {
	const capped = Math.min(total, 23 * 60 + 59);
	const hours = String(Math.floor(capped / 60)).padStart(2, '0');
	const mins = String(capped % 60).padStart(2, '0');
	return `${hours}:${mins}`;
};

/**
 * A range to add after the last one: an hour after it closes, four hours
 * long, capped at 23:59.
 *
 * @param {Array} ranges The day's ranges.
 * @return {{open: string, close: string}} The new range.
 */
export const nextRange = (ranges) => {
	const lastClose = minutes(ranges[ranges.length - 1]?.close);
	const start = null === lastClose ? 9 * 60 : lastClose + 60;

	return { open: clock(start), close: clock(start + 4 * 60) };
};

/**
 * Whether any two ranges of a day overlap.
 *
 * @param {Array} ranges The day's ranges.
 * @return {boolean} True on an overlap.
 */
export const hasOverlap = (ranges) => {
	const sorted = ranges
		.map((range) => [minutes(range.open), minutes(range.close)])
		.filter(([open, close]) => null !== open && null !== close)
		.sort((a, b) => a[0] - b[0]);

	return sorted.some((range, i) => i > 0 && range[0] < sorted[i - 1][1]);
};
