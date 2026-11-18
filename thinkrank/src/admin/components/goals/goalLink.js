/**
 * SEO Goals: where a goal is fixed, as a URL and as an SPA navigation.
 *
 * @package
 * @since 2.22.0
 */

import { getPageUrl, navigate, isPlainLeftClick } from '../../utils/router';

/**
 * Page id and query params for a goal's link.
 *
 * @param {Object} link Goal link: { section, item }, { page, tab } or { screen }.
 * @return {{page: string, params: Object}} Route.
 */
export const goalRoute = (link = {}) => {
	if (link.page) {
		return {
			page: link.page,
			params: link.tab ? { tab: link.tab } : {},
		};
	}
	return {
		page: 'essential-seo',
		params: {
			nav_section: link.section,
			...(link.item ? { nav_item: link.item } : {}),
		},
	};
};

/**
 * The link's URL, for the anchor's href (new tab, copy link).
 *
 * @param {Object} link Goal link.
 * @return {string} URL.
 */
export const goalHref = (link) => {
	// A WordPress core settings screen, beside admin.php.
	if (link?.screen) {
		return window.location.pathname.replace(/[^/]*$/, link.screen);
	}
	const { page, params } = goalRoute(link);
	return getPageUrl(page, params) || '#';
};

/**
 * Follow a goal's link inside the SPA. Within Essential SEO the page's own
 * section switcher is used, since the router leaves same-page moves to the
 * page.
 *
 * @param {Event}         event      Click event.
 * @param {Object}        link       Goal link.
 * @param {Function|null} onNavigate Essential SEO's (section, item) switcher, when on that page.
 */
export const followGoal = (event, link, onNavigate = null) => {
	// Core screens are outside the SPA: let the browser follow the href.
	if (!isPlainLeftClick(event) || link?.screen) {
		return;
	}
	event.preventDefault();

	if (!link.page && onNavigate) {
		onNavigate(link.section, link.item);
		window.scrollTo({ top: 0, behavior: 'smooth' });
		return;
	}

	const { page, params } = goalRoute(link);
	navigate(page, params);
};
