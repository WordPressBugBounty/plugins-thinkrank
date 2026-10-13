/**
 * Inline-style helpers shared by the FAQ block's edit and save components so the
 * editor preview matches the saved front-end output exactly.
 */

export const HEADING_TAGS = [
	{ label: 'H2', value: 'h2' },
	{ label: 'H3', value: 'h3' },
	{ label: 'H4', value: 'h4' },
	{ label: 'Paragraph', value: 'p' },
];

export function itemStyle(attributes) {
	const { itemSpacing, itemBackground, itemBorderColor, itemBorderRadius } =
		attributes;
	return {
		marginBottom: `${itemSpacing ?? 8}px`,
		background: itemBackground || undefined,
		border: `1px solid ${itemBorderColor || '#e2e4e7'}`,
		borderRadius: `${itemBorderRadius ?? 6}px`,
	};
}

export function questionStyle(attributes) {
	return {
		color: attributes.questionColor || undefined,
		background: attributes.questionBackground || undefined,
		fontSize: `${attributes.titleFontSize ?? 17}px`,
	};
}

export function answerStyle(attributes) {
	return {
		color: attributes.answerColor || undefined,
	};
}
