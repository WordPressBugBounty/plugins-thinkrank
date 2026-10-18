/**
 * Options for the Type dropdown in the Sitemap URLs rows.
 */
import { __ } from '@wordpress/i18n';

/**
 * The types an admin can pick for a hand-added sitemap URL.
 */
export const SITEMAP_TYPE_OPTIONS = [
	{ value: 'index', label: __('Index', 'thinkrank') },
	{ value: 'general', label: __('General', 'thinkrank') },
	{ value: 'posts', label: __('Posts', 'thinkrank') },
	{ value: 'pages', label: __('Pages', 'thinkrank') },
	{ value: 'categories', label: __('Categories', 'thinkrank') },
];

/**
 * Labels for the other types the generator and the presets write.
 */
const BUILT_IN_LABELS = {
	tags: __('Tags', 'thinkrank'),
	products: __('Products', 'thinkrank'),
	product_categories: __('Product Categories', 'thinkrank'),
	wordpress: 'WordPress',
};

/**
 * The options for one row, including the types the screen holds.
 *
 * A native select whose value matches no option displays the first one, so a
 * custom post type, custom taxonomy or WooCommerce child read as "Index" and,
 * once changed, could not be set back (#1052). The row's current type and
 * every type the screen loaded with are appended when outside the fixed list.
 *
 * Each appended type is labelled by the screen's own name for a built-in
 * type, else by the label WordPress registers for the post type or taxonomy
 * (`child_type_labels` from the settings response, #1221), else by its slug,
 * which is all a type nothing registers any more has. Two options that would
 * read the same, such as the `products` child and a `product` post type both
 * called "Products", carry their slug as well so they can be told apart.
 *
 * The loaded types are one list for the screen, not one per row: keyed by
 * the row's URL, the loaded type was lost as soon as the admin edited it.
 * They come first, so the options keep their order while the row changes.
 *
 * @param {string|undefined}       type        The row's current type.
 * @param {string[]}               loadedTypes Every row's type as loaded.
 * @param {Object<string, string>} typeLabels  Registered label by type.
 * @return {Array<{value: string, label: string}>} Options for the select.
 */
export const getSitemapTypeOptions = (
	type,
	loadedTypes = [],
	typeLabels = {}
) => {
	const extra = [...new Set([...loadedTypes, type])].filter(
		(value) =>
			value &&
			!SITEMAP_TYPE_OPTIONS.some((option) => option.value === value)
	);

	if (!extra.length) {
		return SITEMAP_TYPE_OPTIONS;
	}

	const labelled = extra.map((value) => ({
		value,
		label: BUILT_IN_LABELS[value] ?? typeLabels?.[value] ?? value,
	}));

	const taken = [...SITEMAP_TYPE_OPTIONS, ...labelled].map(
		(option) => option.label
	);

	return [
		...SITEMAP_TYPE_OPTIONS,
		...labelled.map((option) =>
			option.label !== option.value &&
			taken.filter((label) => label === option.label).length > 1
				? { ...option, label: `${option.label} (${option.value})` }
				: option
		),
	];
};
