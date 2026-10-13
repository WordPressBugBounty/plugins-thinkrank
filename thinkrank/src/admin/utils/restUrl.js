/**
 * restUrl — build a ThinkRank REST URL for a raw fetch() call.
 *
 * `window.thinkrankAdmin.apiUrl` is `rest_url('thinkrank/v1/')`, whose shape
 * depends on the site's permalink structure:
 *
 *   pretty  ->  https://site/wp-json/thinkrank/v1/
 *   plain   ->  https://site/index.php?rest_route=/thinkrank/v1/
 *
 * The plain form already contains a `?`, so appending a query string with a
 * hard-coded `?` yields a URL with two of them. PHP's query parser splits on
 * `&` only, so everything after the second `?` is folded into the `rest_route`
 * *value* — WordPress then looks up a route that doesn't exist, returns
 * `rest_no_route` (404), and the intended parameters never arrive. That is what
 * left Bulk SEO Optimization permanently stuck on its "couldn't load your
 * settings" state for every Plain-permalink site (#531).
 *
 * Components built on @wordpress/api-fetch don't need this — api-fetch resolves
 * paths against the REST root itself. It is only for the raw-fetch call sites.
 *
 * @package
 * @since 2.1.1
 */

/**
 * Join a REST path and query parameters onto the localized API root.
 *
 * @param {string} path   Route path relative to the namespace root, e.g.
 *                        'global-seo/settings'.
 * @param {Object} params Query parameters. Values are URL-encoded; entries
 *                        that are undefined or null are omitted.
 * @return {string} A URL safe to hand to fetch() under either permalink mode.
 */
export const restUrl = (path, params = {}) => {
	const base = `${window.thinkrankAdmin?.apiUrl || ''}${path}`;

	const search = new URLSearchParams();
	Object.entries(params).forEach(([key, value]) => {
		if (value !== undefined && value !== null) {
			search.set(key, value);
		}
	});

	const query = search.toString();
	if (!query) {
		return base;
	}

	// `?` only when the base doesn't already carry a query string.
	return `${base}${base.includes('?') ? '&' : '?'}${query}`;
};

export default restUrl;
