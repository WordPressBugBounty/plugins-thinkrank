/**
 * Sitemap Input Validators
 *
 * Pure validation functions for sitemap settings inputs.
 * Extracted for testability and reuse.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';

/**
 * Route input validation to type-specific validators
 * @param key
 * @param value
 */
export const validateInput = (key, value) => {
	switch (key) {
		case 'exclude_posts':
		case 'exclude_terms':
			return validateExcludeList(value);
		case 'custom_url_pattern':
			return validateUrlPattern(value);
		case 'links_per_sitemap':
			return validateLinksPerSitemap(value);
		default:
			return value;
	}
};

/**
 * Validate exclude lists (posts/terms)
 * @param value
 */
export const validateExcludeList = (value) => {
	if (!value || typeof value !== 'string') return '';

	if (value.length > 1000) {
		throw new Error(
			__('Exclude list too long (max 1000 characters)', 'thinkrank')
		);
	}

	if (!/^[\d,\s]*$/.test(value)) {
		throw new Error(
			__('Exclude list must contain only numbers and commas', 'thinkrank')
		);
	}

	const items = value.split(',').filter((item) => item.trim());
	if (items.length > 100) {
		throw new Error(__('Too many items to exclude (max 100)', 'thinkrank'));
	}

	return value.trim();
};

/**
 * Validate URL pattern
 * @param value
 */
export const validateUrlPattern = (value) => {
	if (!value || typeof value !== 'string') return 'sitemap-{type}.xml';

	const cleaned = value.replace(/<[^>]*>/g, '');

	if (cleaned.length > 100) {
		throw new Error(
			__('URL pattern too long (max 100 characters)', 'thinkrank')
		);
	}

	// `\\` here also added a literal backslash to the allowed set; use a plain
	// dot (literal inside a character class) instead.
	if (!/^[a-zA-Z0-9\-_{}.]+$/.test(cleaned)) {
		throw new Error(
			__(
				'URL pattern contains invalid characters. Only letters, numbers, hyphens, underscores, dots, and {type} are allowed',
				'thinkrank'
			)
		);
	}

	if (!cleaned.includes('{type}')) {
		throw new Error(
			__('URL pattern must contain {type} placeholder', 'thinkrank')
		);
	}

	return cleaned.endsWith('.xml') ? cleaned : cleaned + '.xml';
};

/**
 * Validate links per sitemap
 * @param value
 */
export const validateLinksPerSitemap = (value) => {
	const num = parseInt(value, 10);

	if (isNaN(num) || num < 1) {
		throw new Error(
			__('Links per sitemap must be at least 1', 'thinkrank')
		);
	}

	if (num > 50000) {
		throw new Error(
			__('Links per sitemap cannot exceed 50,000', 'thinkrank')
		);
	}

	return num;
};

/**
 * Validate sitemap URL
 * @param value
 */
export const validateSitemapUrl = (value) => {
	if (!value || typeof value !== 'string') {
		throw new Error(__('Sitemap URL cannot be empty', 'thinkrank'));
	}

	const url = value.trim();

	if (url.length > 200) {
		throw new Error(
			__('Sitemap URL too long (max 200 characters)', 'thinkrank')
		);
	}

	const normalizedUrl = url.startsWith('/') ? url : '/' + url;

	// NOTE: `\\` in a JS regex literal is a LITERAL backslash. The previous
	// pattern (`[...\\.]+\\.xml$`) was double-escaped during the port from the
	// PHP validator, so it required a literal backslash before "xml" and
	// therefore rejected every real path (e.g. "/sitemap.xml"). It also
	// (unintentionally) allowed backslashes inside the path.
	if (!/^\/[a-zA-Z0-9\-_/.]+\.xml$/.test(normalizedUrl)) {
		throw new Error(
			__(
				'Invalid sitemap URL format. Must be a valid path ending with .xml',
				'thinkrank'
			)
		);
	}

	if (normalizedUrl.includes('..')) {
		throw new Error(
			__('Directory traversal not allowed in sitemap URL', 'thinkrank')
		);
	}

	return normalizedUrl.replace(/\/+/g, '/');
};
