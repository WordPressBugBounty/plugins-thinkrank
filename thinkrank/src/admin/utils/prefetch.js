/**
 * Hover prefetching for SPA pages.
 *
 * Pages fetch their data with raw `apiFetch` calls on mount (there are no
 * store resolvers), so the only cache both a prefetch and a page component
 * can share is the request layer itself. This module keeps a short-lived,
 * one-shot promise cache keyed by the exact request `path` and serves it
 * through an `apiFetch` middleware:
 *
 *  - `prefetchPage( pageId )` fires the page's known mount GETs ahead of
 *    time (bound to nav-link hover/focus by TopNav and the WP sidebar).
 *  - When the page mounts and issues the same GET, the middleware hands it
 *    the in-flight/settled promise instead of hitting the network.
 *  - Entries are served at most once and expire after FRESH_MS, so user
 *    refreshes (tab revisits, period switches) always hit the network.
 *
 * The path lists below mirror the mount effects of each page component; a
 * drifted path degrades to one extra speculative request, never a wrong
 * response.
 */

import apiFetch from '@wordpress/api-fetch';

/** How long a prefetched response may satisfy a page request. */
const FRESH_MS = 30000;

/** path -> { promise, ts } */
const cache = new Map();

/** Marks the warm-up requests themselves so the middleware lets them through. */
const PREFETCH_FLAG = '__thinkrankPrefetch';

/**
 * Mount-time GET paths per SPA page id. Query strings must match the
 * component's call byte-for-byte (defaults hardcoded in the components).
 */
const PAGE_PREFETCHES = {
	dashboard: [
		// The dashboard no longer reads /thinkrank/v1/settings (#574); warming
		// it here would be a request nobody consumes, and a guaranteed 403 for
		// any delegated role without `thinkrank_settings`.
		'/wp/v2/settings',
		'/wp/v2/posts?per_page=5&orderby=modified&order=desc&status=publish,draft,private&_fields=id,title,link,modified,modified_gmt,type',
	],
	'essential-seo': [
		// Default section (site-identity/basic-info); other sections load on demand.
		'/thinkrank/v1/site-identity/settings',
		'/thinkrank/v1/site-identity/title/templates',
		'/thinkrank/v1/site-identity/breadcrumbs/types',
	],
	'ai-tools': [
		'/thinkrank/v1/content-brief/list',
		// Hidden MCP tab mounts alongside the active one and reads settings.
		'/thinkrank/v1/settings',
	],
	analytics: [
		'/thinkrank/v1/analytics/overview?period=30d&user_id=0',
		'/thinkrank/v1/analytics/usage?period=30d&page=1&per_page=20',
	],
	settings: ['/thinkrank/v1/settings'],
};

/**
 * Path => the ThinkRank capability the current user must hold to fetch it.
 *
 * Only for reads the Role Manager can withhold from a role that still reaches
 * the page listing them — the AI Tools page keeps warming /thinkrank/v1/settings
 * for its hidden MCP tab, and a `thinkrank_content_tools`-only role would 403.
 * Keyed by path, so any page listing one inherits the guard.
 */
const PREFETCH_CAPABILITIES = {
	'/thinkrank/v1/settings': 'thinkrank_settings',
};

/**
 * Whether the current user may fetch a path we would otherwise warm.
 *
 * This is an optimisation, not access control — the server remains the
 * authority. It only skips a request already known to fail.
 *
 * @param {string} path REST path.
 * @return {boolean} Whether to prefetch it.
 */
const mayPrefetch = (path) => {
	const required = PREFETCH_CAPABILITIES[path];
	if (!required) {
		return true;
	}
	const caps = window.thinkrankAdmin?.caps;
	// Fail open when the list is absent rather than merely missing the entry:
	// not knowing what the user holds is not the same as knowing they lack it,
	// and a missing global must not silently disable every warm-up.
	if (!Array.isArray(caps)) {
		return true;
	}
	return caps.includes(required);
};

const isCacheableGet = (options) =>
	typeof options.path === 'string' &&
	(!options.method || options.method.toUpperCase() === 'GET') &&
	options.parse !== false;

apiFetch.use((options, next) => {
	if (options[PREFETCH_FLAG] || !isCacheableGet(options)) {
		return next(options);
	}
	const entry = cache.get(options.path);
	if (!entry) {
		return next(options);
	}
	// One-shot: whether served or expired, the entry is spent.
	cache.delete(options.path);
	if (Date.now() - entry.ts < FRESH_MS) {
		return entry.promise;
	}
	return next(options);
});

/**
 * Warm up a single GET path. No-op when a fresh entry is already pending.
 *
 * @param {string} path REST request path, including any query string.
 */
export const prefetchPath = (path) => {
	const entry = cache.get(path);
	if (entry && Date.now() - entry.ts < FRESH_MS) {
		return;
	}
	const promise = apiFetch({ path, [PREFETCH_FLAG]: true });
	// A failed warm-up must not be handed to the page — drop it so the
	// mount request goes to the network and surfaces its own error.
	promise.catch(() => {
		if (cache.get(path)?.promise === promise) {
			cache.delete(path);
		}
	});
	cache.set(path, { promise, ts: Date.now() });
};

/**
 * Warm up everything a page requests on mount.
 *
 * @param {string} pageId SPA page id from the router's route table.
 */
export const prefetchPage = (pageId) => {
	(PAGE_PREFETCHES[pageId] || []).filter(mayPrefetch).forEach(prefetchPath);
};
