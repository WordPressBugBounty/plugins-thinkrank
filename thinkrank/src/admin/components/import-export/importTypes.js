/**
 * Import data types and how they read in a "what we found" line.
 *
 * Shared by the Migration screen card and Quick Setup's migration row. Each
 * kept its own map, and Quick Setup's only knew the four object-meta types,
 * so a site with redirects and 404 logs read "Global, Post meta, Term meta"
 * while both were imported (#901).
 */
import { __ } from '@wordpress/i18n';

/* All exportable types: one click imports everything the plugin holds. */
export const EXPORT_TYPES = [
	'postmeta',
	'termmeta',
	'usermeta',
	'redirections',
	'404_logs',
	'content_blocks',
	'settings',
];

export const DETAIL_LABELS = {
	settings: __('Global', 'thinkrank'),
	postmeta: __('Post meta', 'thinkrank'),
	termmeta: __('Term meta', 'thinkrank'),
	usermeta: __('Author meta', 'thinkrank'),
	redirections: __('Redirections', 'thinkrank'),
	'404_logs': __('404 logs', 'thinkrank'),
	content_blocks: __('FAQ & HowTo blocks', 'thinkrank'),
};

/**
 * Labels for the types a detection reported: labelled types in label order,
 * then any other type by its key, so a type added without a label still
 * shows instead of disappearing from the line.
 *
 * @param {Object} counts Detection counts keyed by type.
 * @return {string[]} Labels.
 */
export function describeCounts(counts) {
	const types = [
		...Object.keys(DETAIL_LABELS),
		...Object.keys(counts || {}).filter((type) => !DETAIL_LABELS[type]),
	];

	return types
		.filter((type) => counts?.[type])
		.map((type) => DETAIL_LABELS[type] || type);
}
