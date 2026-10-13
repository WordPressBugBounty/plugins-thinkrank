/**
 * Inline-style helpers shared by the HowTo block's edit and save components so
 * the editor preview matches the saved front-end output exactly.
 */

export const HEADING_TAGS = [
	{ label: 'H2', value: 'h2' },
	{ label: 'H3', value: 'h3' },
	{ label: 'H4', value: 'h4' },
	{ label: 'Paragraph', value: 'p' },
];

export function stepStyle(attributes) {
	const { stepSpacing, stepBackground, stepBorderColor, stepBorderRadius } =
		attributes;
	return {
		marginBottom: `${stepSpacing ?? 12}px`,
		background: stepBackground || undefined,
		border: stepBorderColor ? `1px solid ${stepBorderColor}` : undefined,
		borderRadius: `${stepBorderRadius ?? 6}px`,
	};
}

export function stepTitleStyle(attributes) {
	return {
		color: attributes.stepTitleColor || undefined,
		fontSize: `${attributes.stepTitleFontSize ?? 17}px`,
	};
}

export function stepTextStyle(attributes) {
	return {
		color: attributes.stepTextColor || undefined,
	};
}

/**
 * Human-readable total time from the duration attributes, e.g. "1 day, 2
 * hours, 30 minutes". Returns '' when no duration is set.
 *
 * @param {Object} attributes Block attributes.
 * @return {string} Formatted duration.
 */
export function formatTotalTime(attributes) {
	const { totalDays, totalHours, totalMinutes } = attributes;
	const parts = [];
	if (totalDays > 0) {
		parts.push(totalDays === 1 ? '1 day' : `${totalDays} days`);
	}
	if (totalHours > 0) {
		parts.push(totalHours === 1 ? '1 hour' : `${totalHours} hours`);
	}
	if (totalMinutes > 0) {
		parts.push(totalMinutes === 1 ? '1 minute' : `${totalMinutes} minutes`);
	}
	return parts.join(', ');
}
