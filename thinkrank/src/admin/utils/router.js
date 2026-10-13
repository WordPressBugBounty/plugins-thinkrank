/**
 * SPA router for the ThinkRank admin app.
 *
 * Every ThinkRank submenu page (admin.php?page=thinkrank-*) enqueues the same
 * admin bundle and mounts the same <App/>, so moving between top-level
 * sections only needs a history.pushState plus a React re-render — no full
 * page reload. This module owns that URL <-> page mapping:
 *
 * - navigate(pageId, params) pushes a new history entry and notifies
 *   subscribers (App re-renders the target page).
 * - Browser back/forward is handled via popstate; every same-document history
 *   entry was created by navigate(), so entries always map to a known route.
 * - The WP admin sidebar is kept in sync: the `current` submenu highlight
 *   follows SPA navigation, and left-clicks on ThinkRank submenu links are
 *   intercepted and routed client-side.
 *
 * Pages that live outside the SPA bundle (Setup Wizard, Pro License) are not
 * in the route table and keep their normal full-page loads.
 *
 * @since 1.17.0
 */

import { prefetchPage } from './prefetch';

/**
 * Route table: SPA page id <-> WP submenu slug, plus the Role Manager
 * capability required to open the page (null = base access only). Mirrors
 * the caps used in includes/admin/class-manager.php::add_admin_menu().
 */
const ROUTES = [
	{ page: 'dashboard', slug: 'thinkrank', cap: null },
	{ page: 'essential-seo', slug: 'thinkrank-essential-seo', cap: null },
	{
		page: 'ai-tools',
		slug: 'thinkrank-ai-tools',
		cap: 'thinkrank_content_tools',
	},
	{ page: 'analytics', slug: 'thinkrank-usages', cap: 'thinkrank_analytics' },
	{ page: 'settings', slug: 'thinkrank-settings', cap: 'thinkrank_settings' },
	// Two screens, one per setting: Import / Export carries ThinkRank's own
	// data out and back ("Enable Import / Export", on by default), Migration
	// brings another plugin's data in ("Enable Migration Tools", off by
	// default). Both gated by manage_options. Either submenu may be absent, and
	// a route whose page is not registered simply never resolves from a URL.
	{
		page: 'import-export',
		slug: 'thinkrank-import-export',
		cap: 'manage_options',
	},
	// Slug kept as thinkrank-migration so existing links keep resolving.
	{ page: 'migration', slug: 'thinkrank-migration', cap: 'manage_options' },
];

const listeners = new Set();

const routeByPage = (pageId) => ROUTES.find((r) => r.page === pageId);
const routeBySlug = (slug) => ROUTES.find((r) => r.slug === slug);

/**
 * Whether the current user may open a page, based on the capabilities
 * localized by Capability_Manager. Administrators hold every capability.
 *
 * @param {string} pageId SPA page id.
 * @return {boolean} True when the page is accessible (or unknown caps data).
 */
export const canAccess = (pageId) => {
	const route = routeByPage(pageId);
	if (!route) {
		return false;
	}
	if (!route.cap) {
		return true;
	}
	const admin = window.thinkrankAdmin || {};
	if (route.cap === 'manage_options') {
		return !!admin.isAdmin;
	}
	const caps = Array.isArray(admin.caps) ? admin.caps : null;
	// No caps data localized (unexpected) — don't hide anything; the REST
	// permission callbacks remain the real gate.
	return caps ? caps.includes(route.cap) : true;
};

/**
 * Read the current SPA page id from the URL's ?page= param.
 *
 * @return {string|null} Page id, or null when the slug isn't an SPA route.
 */
export const getPageFromUrl = () => {
	try {
		const slug = new URLSearchParams(window.location.search).get('page');
		return routeBySlug(slug)?.page || null;
	} catch (e) {
		return null;
	}
};

/**
 * Build an admin.php URL for an SPA page.
 *
 * Starts from a clean `admin.php?page=<slug>` so transient params owned by
 * the previous page (nav_section, nav_item, tab, type, OAuth callbacks…)
 * never leak into the next one.
 *
 * @param {string} pageId SPA page id.
 * @param {Object} params Extra query params (e.g. { nav_section, nav_item }).
 * @return {string|null} URL string, or null for unknown pages.
 */
export const getPageUrl = (pageId, params = {}) => {
	const route = routeByPage(pageId);
	if (!route) {
		return null;
	}
	const query = new URLSearchParams({ page: route.slug });
	Object.entries(params).forEach(([key, value]) => {
		if (value !== undefined && value !== null) {
			query.set(key, value);
		}
	});
	return `${window.location.pathname}?${query.toString()}`;
};

/**
 * Subscribe to SPA page changes.
 *
 * @param {Function} callback Called with the new page id.
 * @return {Function} Unsubscribe.
 */
export const subscribe = (callback) => {
	listeners.add(callback);
	return () => listeners.delete(callback);
};

const notify = (pageId) => {
	listeners.forEach((callback) => {
		try {
			callback(pageId);
		} catch (e) {
			// One broken subscriber shouldn't stop the others.
		}
	});
};

/**
 * Update document.title, keeping WP's " ‹ Site — WordPress" suffix.
 *
 * @param {string} title New page title.
 */
const syncDocumentTitle = (title) => {
	const idx = document.title.indexOf(' ‹ ');
	document.title = idx === -1 ? title : title + document.title.slice(idx);
};

/**
 * Move the `current` highlight in the WP admin sidebar to the given slug so
 * the sidebar tracks SPA navigation.
 *
 * @param {string} slug WP submenu slug.
 */
const syncAdminMenuHighlight = (slug) => {
	const submenu = document.querySelector(
		'#toplevel_page_thinkrank .wp-submenu'
	);
	if (!submenu) {
		return;
	}
	submenu.querySelectorAll('li, a').forEach((el) => {
		el.classList.remove('current');
		el.removeAttribute('aria-current');
	});
	submenu.querySelectorAll('a[href]').forEach((link) => {
		let linkSlug;
		try {
			linkSlug = new URL(
				link.href,
				window.location.origin
			).searchParams.get('page');
		} catch (e) {
			return;
		}
		if (linkSlug === slug) {
			link.classList.add('current');
			link.setAttribute('aria-current', 'page');
			link.closest('li')?.classList.add('current');
		}
	});
};

const applyLocationSideEffects = (route, title) => {
	syncAdminMenuHighlight(route.slug);
	if (title) {
		syncDocumentTitle(title);
	}
	window.scrollTo({ top: 0 });
};

/** Page id -> document title, registered by App so the router stays UI-free. */
let titleResolver = null;

/**
 * Let the app provide page titles for document.title updates.
 *
 * @param {Function} resolver (pageId) => title string.
 */
export const setTitleResolver = (resolver) => {
	titleResolver = resolver;
};

/**
 * Navigate to an SPA page without a full reload.
 *
 * Falls back to a normal page load when the page is unknown or the user
 * lacks the capability (WP then renders its own access error, exactly as a
 * regular link would).
 *
 * @param {string} pageId SPA page id.
 * @param {Object} params Extra query params for the target page.
 */
export const navigate = (pageId, params = {}) => {
	const route = routeByPage(pageId);
	const url = getPageUrl(pageId, params);
	if (!route || !url) {
		return;
	}
	if (!canAccess(pageId)) {
		window.location.assign(url);
		return;
	}
	if (getPageFromUrl() === pageId) {
		// Already on this page. With params, refresh the URL in place (the
		// page owns reacting to them — e.g. NavSearch dispatches its own
		// event on Essential SEO); without params it's a no-op, like
		// clicking the active tab.
		if (Object.keys(params).length) {
			window.history.replaceState({}, '', url);
		}
		return;
	}
	window.history.pushState({}, '', url);
	applyLocationSideEffects(route, titleResolver?.(pageId));
	notify(pageId);
};

/* Back/forward: same-document entries always point at SPA routes. */
window.addEventListener('popstate', () => {
	const pageId = getPageFromUrl();
	const route = pageId && routeByPage(pageId);
	if (!route) {
		return;
	}
	applyLocationSideEffects(route, titleResolver?.(pageId));
	notify(pageId);
});

/**
 * Whether a click event should be handled by the SPA (plain left-click,
 * no modifier keys, not already handled).
 *
 * @param {Object} event Click event (React synthetic or native).
 * @return {boolean} True when the SPA should take over.
 */
export const isPlainLeftClick = (event) =>
	!event.defaultPrevented &&
	event.button === 0 &&
	!event.metaKey &&
	!event.ctrlKey &&
	!event.shiftKey &&
	!event.altKey;

/**
 * Resolve a WP admin sidebar event target to an accessible SPA route.
 * Links whose slug isn't in the route table (License, Setup Wizard, other
 * plugins) resolve to null and are left untouched.
 *
 * @param {Element} target Event target inside #adminmenu.
 * @return {Object|null} Route entry, or null when not an SPA link.
 */
const routeFromMenuLink = (target) => {
	const link = target.closest('a[href]');
	if (!link || link.target === '_blank') {
		return null;
	}
	let url;
	try {
		url = new URL(link.href, window.location.origin);
	} catch (e) {
		return null;
	}
	if (
		url.origin !== window.location.origin ||
		url.pathname !== window.location.pathname
	) {
		return null;
	}
	const route = routeBySlug(url.searchParams.get('page'));
	return route && canAccess(route.page) ? route : null;
};

const interceptAdminMenuClicks = () => {
	const menu = document.getElementById('adminmenu');
	if (!menu) {
		return;
	}
	menu.addEventListener('click', (event) => {
		if (!isPlainLeftClick(event)) {
			return;
		}
		const route = routeFromMenuLink(event.target);
		if (!route) {
			return;
		}
		event.preventDefault();
		navigate(route.page);
	});
	menu.addEventListener('mouseover', (event) => {
		const route = routeFromMenuLink(event.target);
		if (route && route.page !== getPageFromUrl()) {
			prefetchPage(route.page);
		}
	});
};

/**
 * Keep WP's hover fly-out submenus aligned on ThinkRank full-page screens.
 *
 * Those screens pin `#adminmenuwrap` as a scroll container (see
 * `src/global/base.css`), which clips the fly-out panels WP pops out to the
 * right for non-current top-level menu items. base.css promotes the hovered
 * fly-out to `position: fixed` so it escapes the clip, but in the normal
 * (non-folded) menu WP never sets a viewport-relative `top`, so the fixed
 * panel snaps to the top of the page. We set `top`/`left` from the hovered
 * item's rect on hover and clear them on leave so WP's own styles resume.
 *
 * Writing an inline `top` also takes over WP's own fly-out state handling:
 * `common.js` opens the fly-out via hoverIntent, but its handler bails when the
 * submenu's computed `top` is greater than `-5` ("the submenu is visible"), so
 * it never adds the `opensub` class. `opensub` is what colours the fly-out
 * pointer triangle (`li…:after`), the icon and the highlighted menu-top row —
 * without it the panel appears with no pointer (only a click, via
 * `:focus-within`, brought it back). So we toggle `opensub` ourselves, which
 * also drops WP's hover delay.
 */
const positionAdminFlyouts = () => {
	const menu = document.getElementById('adminmenu');
	// Only needed while the pinned/clipped sidebar CSS is in effect
	// (min-width: 783px); below that WP switches to its off-canvas menu.
	if (!menu || !window.matchMedia('(min-width: 783px)').matches) {
		return;
	}

	const align = (item) => {
		// Skip the current, inline-expanded menu — its submenu is in flow.
		if (!item.classList.contains('wp-not-current-submenu')) {
			return;
		}
		const submenu = item.querySelector(':scope > .wp-submenu');
		if (!submenu) {
			return;
		}
		const rect = item.getBoundingClientRect();

		/*
		 * Keep the panel inside the viewport. WP core's own `adjustSubmenu()`
		 * shifts a fly-out up with a negative `margin-top` when it would fall
		 * past the fold; writing an inline `top` here takes over from that
		 * logic, so a long panel opened from a low menu item (Settings, Tools)
		 * would otherwise be cut off at the bottom of the screen. Clamp it the
		 * same way core does: bottom-align against the fold, never above the
		 * admin bar.
		 */
		const adminBarHeight =
			document.getElementById('wpadminbar')?.offsetHeight ?? 32;
		const gap = 6;
		const maxTop = window.innerHeight - submenu.offsetHeight - gap;
		const top = Math.max(adminBarHeight + gap, Math.min(rect.top, maxTop));

		submenu.style.top = `${Math.round(top)}px`;
		submenu.style.left = `${Math.round(rect.right)}px`;

		menu.querySelectorAll('li.opensub').forEach((open) => {
			if (open !== item) {
				open.classList.remove('opensub');
			}
		});
		item.classList.add('opensub');
	};

	const clear = (item) => {
		item.classList.remove('opensub');
		const submenu = item.querySelector(':scope > .wp-submenu');
		if (submenu) {
			submenu.style.top = '';
			submenu.style.left = '';
		}
	};

	menu.addEventListener('mouseover', (event) => {
		const item = event.target.closest('li.menu-top');
		if (item) {
			align(item);
		}
	});
	menu.addEventListener('mouseout', (event) => {
		const item = event.target.closest('li.menu-top');
		// Ignore moves that stay within the same item.
		if (item && !item.contains(event.relatedTarget)) {
			clear(item);
		}
	});
};

interceptAdminMenuClicks();
positionAdminFlyouts();
