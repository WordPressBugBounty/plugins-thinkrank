/**
 * Shared helpers for the TOC block's edit and save components.
 */

export const HEADING_TAGS = [
	{ label: 'H2', value: 'h2' },
	{ label: 'H3', value: 'h3' },
	{ label: 'H4', value: 'h4' },
	{ label: 'Paragraph', value: 'p' },
];

export const LIST_STYLES = [
	{ label: 'Bulleted', value: 'disc' },
	{ label: 'Numbered', value: 'decimal' },
	{ label: 'Plain', value: 'none' },
];

/**
 * Kebab-case a heading's text into an anchor id (matches what users expect
 * from core's own anchor field: lowercase, hyphen-separated, ascii-ish).
 *
 * @param {string} text Heading text (already tag-stripped).
 * @return {string} Anchor slug ('' when nothing usable remains).
 */
export function slugify(text) {
	return text
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/[^a-z0-9\s-]/g, '')
		.trim()
		.replace(/[\s-]+/g, '-')
		.replace(/^-+|-+$/g, '');
}

export function listItemStyle(attributes) {
	return {
		marginBottom: `${attributes.itemSpacing ?? 6}px`,
		color: attributes.linkColor || undefined,
	};
}
