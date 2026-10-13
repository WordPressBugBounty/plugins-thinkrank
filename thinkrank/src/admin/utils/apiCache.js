/**
 * Browser-side API response cache — Site Kit pattern.
 *
 * Google Site Kit keeps its dashboard fast without any server-side response
 * caching: GET responses are cached in sessionStorage (per user, per plugin
 * version) with a TTL, concurrent identical requests share one in-flight
 * promise, and any write invalidates the cache. This middleware applies the
 * same model to all `/thinkrank/v1` and `/thinkrank-pro/v1` GET requests, so
 * navigating back to a screen (or reloading wp-admin) renders instantly from
 * cache instead of re-firing every Google-backed endpoint.
 *
 * Invalidation model: ANY non-GET request to a ThinkRank namespace purges the
 * whole cache. Writes are rare (saving settings, refresh buttons), and
 * cross-endpoint dependencies (e.g. saving the seo_analytics settings
 * category changes what the analytics endpoints return) make per-path
 * invalidation error-prone.
 *
 * @package
 * @since 1.18.0
 */

import apiFetch from '@wordpress/api-fetch';

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;

const VERSION = window.thinkrankAdmin?.version || '0';
const USER_ID = window.thinkrankAdmin?.currentUser || 0;
const KEY_PREFIX = `thinkrank_api_${VERSION}_u${USER_ID}::`;

/**
 * Longest-prefix-match TTL rules. A TTL of 0 means "never cache".
 * Google-backed report data also has server-side transient caching; this
 * layer only exists to make repeat navigation and reloads instant.
 */
const TTL_RULES = [
	// Volatile scan state — polling must always hit the server.
	['/thinkrank-pro/v1/url-inspection/', 0],
	// MCP connection + connected-apps state changes out-of-band: an AI client
	// completes an OAuth approval entirely outside ThinkRank, so no browser
	// write purges the cache. These admin reads must always load live or the
	// "Connected AI apps" list shows a stale (often empty) snapshot.
	['/thinkrank/v1/mcp/', 0],
	// The hub connection is approved on the consent page and finished on the
	// hub, both outside this screen, and the browser comes back to it with a
	// full page load: a cached "not connected" would be what it showed.
	['/thinkrank/v1/hub', 0],
	// Email Reporting carries the readiness the panel warns on: whether
	// Search Console is connected, and why the last scheduled run sent
	// nothing. Connecting happens on the Integrations screen behind a Google
	// OAuth redirect, so the browser that cached "paused" is not the one that
	// wrote the token — a cached response keeps the warning up, and the
	// "Paused" pill with it, for the rest of the TTL on a site that is now
	// connected. The read is local (no Google round-trip), so caching it buys
	// nothing.
	['/thinkrank/v1/email-report/', 0],
	// Google admin lists (GSC sites, GA accounts/properties) change rarely
	// but cost a live Google round-trip each — cache generously.
	['/thinkrank/v1/integrations/search-console/', 30 * MINUTE],
	// Never cache the served robots.txt read: it can change out-of-band (MCP,
	// WP-CLI, another admin) with no browser write to purge the cache, so the
	// editor must always load it live. More specific than the rule below, so
	// longest-prefix match wins.
	['/thinkrank/v1/site-identity/robots', 0],
	['/thinkrank/v1/site-identity/', 5 * MINUTE],
	// Sitemap settings carry the regeneration health the admin warns on
	// (last failure, staleness, whether a rebuild is overdue). All of it
	// changes out-of-band — a WP-Cron run, a request-time rebuild, a failed
	// write — with no browser write to purge the cache, so a cached response
	// would keep showing a failure that has since been fixed, or hide one that
	// has just happened. The read itself is local (no Google round-trip), so
	// there is nothing to save by caching it.
	['/thinkrank/v1/sitemap/settings', 0],
	// Bulk Snippets reads per-post state that changes out-of-band — an edit
	// in the post editor, Auto AI on publish, an import — and its issue index
	// is built progressively, so the screen polls while posts are still being
	// analysed. A cached page would show edits made elsewhere as missing and
	// freeze that progress (#727).
	['/thinkrank/v1/global-seo/snippets', 0],
	// Thin Content reads word counts that are built a batch per request, so
	// the screen polls until `pending` reaches 0; its thresholds also change
	// out-of-band through the update-thin-content-settings ability. A cached
	// response returned the same `pending` to every poll, and the scan froze
	// after its first batch on any site with more than 50 pages (#565).
	['/thinkrank/v1/global-seo/thin-content', 0],
	['/thinkrank/v1/seo-analytics/', HOUR],
	['/thinkrank/v1/performance/history', 30 * MINUTE],
	['/thinkrank/v1/performance/', 10 * MINUTE],
	// Core Web Vitals: CrUX/Lighthouse data is effectively daily; the
	// Refresh button purges explicitly (team audit recommendation).
	['/thinkrank/v1/performance/monitor', 24 * 60 * MINUTE],
	['/thinkrank-pro/v1/keywords/', 30 * MINUTE],
	['/thinkrank-pro/v1/top-content', 30 * MINUTE],
	['/thinkrank-pro/v1/google-analytics/', 30 * MINUTE],
	['/thinkrank-pro/v1/rank-tracker/suggestions', 15 * MINUTE],
	['/thinkrank-pro/v1/rank-tracker/', 5 * MINUTE],
];

const DEFAULT_TTL = 5 * MINUTE;

/**
 * Reads modeled as POST (report queries carrying a request body). These must
 * NOT purge the cache — the GA4 dashboard cards fire ~10 run-report POSTs per
 * load, which previously wiped every cached GET mid-load ("only Keywords is
 * cached" audit finding). Their responses are themselves cacheable, keyed by
 * path + a stable hash of the request body.
 */
const READ_POST_RULES = [
	['/thinkrank-pro/v1/google-analytics/run-report', 30 * MINUTE],
];

/**
 * Stable stringify: recursively sorts object keys so hashes are stable.
 * @param value
 */
const stableStringify = (value) => {
	if (Array.isArray(value)) {
		return '[' + value.map(stableStringify).join(',') + ']';
	}
	if (value && typeof value === 'object') {
		return (
			'{' +
			Object.keys(value)
				.sort()
				.map((k) => JSON.stringify(k) + ':' + stableStringify(value[k]))
				.join(',') +
			'}'
		);
	}
	return JSON.stringify(value);
};

/**
 * djb2 — tiny non-cryptographic hash for cache keys.
 * @param str
 */
const hashString = (str) => {
	let h = 5381;
	for (let i = 0; i < str.length; i++) {
		h = ((h << 5) + h + str.charCodeAt(i)) | 0;
	}
	return (h >>> 0).toString(36);
};

const isThinkRankPath = (path) =>
	typeof path === 'string' &&
	(path.startsWith('/thinkrank/v1/') ||
		path.startsWith('/thinkrank-pro/v1/'));

export const ttlForPath = (path) => {
	let best = null;
	for (const [prefix, ttl] of TTL_RULES) {
		if (
			path.startsWith(prefix) &&
			(best === null || prefix.length > best.prefixLength)
		) {
			best = { ttl, prefixLength: prefix.length };
		}
	}
	return best ? best.ttl : DEFAULT_TTL;
};

/**
 * Some successful responses represent transient states that must not be
 * pinned in the cache (e.g. the performance monitor's "collecting" state).
 *
 * @param {string} path     Request path.
 * @param {Object} response Parsed response body.
 * @return {boolean} Whether the response is safe to cache.
 */
const isCacheableResponse = (path, response) => {
	if (!response || typeof response !== 'object') {
		return false;
	}
	if (response.success === false) {
		return false;
	}
	if (
		path.startsWith('/thinkrank/v1/performance/monitor') &&
		response.data?.status === 'collecting'
	) {
		return false;
	}
	return true;
};

const storage = (() => {
	try {
		const probe = '__thinkrank_probe__';
		window.sessionStorage.setItem(probe, '1');
		window.sessionStorage.removeItem(probe);
		return window.sessionStorage;
	} catch (e) {
		return null;
	}
})();

const readCache = (key) => {
	if (!storage) {
		return undefined;
	}
	try {
		const raw = storage.getItem(KEY_PREFIX + key);
		if (!raw) {
			return undefined;
		}
		const entry = JSON.parse(raw);
		if (!entry || Date.now() - entry.t >= entry.ttl) {
			storage.removeItem(KEY_PREFIX + key);
			return undefined;
		}
		return entry.v;
	} catch (e) {
		return undefined;
	}
};

const writeCache = (key, value, ttl) => {
	if (!storage || ttl <= 0) {
		return;
	}
	try {
		storage.setItem(
			KEY_PREFIX + key,
			JSON.stringify({ t: Date.now(), ttl, v: value })
		);
	} catch (e) {
		// Quota exceeded — drop our entries and carry on uncached.
		purgeAll();
	}
};

const purgeAll = () => {
	if (!storage) {
		return;
	}
	try {
		const doomed = [];
		for (let i = 0; i < storage.length; i++) {
			const key = storage.key(i);
			// Purge every thinkrank_api_* entry, including ones from other
			// plugin versions/users, so stale generations don't accumulate.
			if (key && key.startsWith('thinkrank_api_')) {
				doomed.push(key);
			}
		}
		doomed.forEach((key) => storage.removeItem(key));
	} catch (e) {
		// Best effort only.
	}
};

// Concurrent identical GETs share one in-flight promise (Site Kit's dedupe).
const inFlight = new Map();

/**
 * Explicitly purge the ThinkRank browser cache (exposed for refresh buttons
 * or debugging via window.thinkrankApiCache.purge()).
 */
export const purgeApiCache = purgeAll;

apiFetch.use((options, next) => {
	const { path, method = 'GET' } = options;

	if (!isThinkRankPath(path)) {
		return next(options);
	}

	if (method.toUpperCase() !== 'GET') {
		// Reads modeled as POST: cacheable, and must not purge.
		const readPost = READ_POST_RULES.find(([prefix]) =>
			path.startsWith(prefix)
		);
		if (readPost) {
			const key =
				path + '::' + hashString(stableStringify(options.data ?? null));
			const cachedPost = readCache(key);
			if (cachedPost !== undefined) {
				return Promise.resolve(cachedPost);
			}
			if (inFlight.has(key)) {
				return inFlight.get(key);
			}
			const postRequest = next(options)
				.then((response) => {
					if (isCacheableResponse(path, response)) {
						writeCache(key, response, readPost[1]);
					}
					return response;
				})
				.finally(() => inFlight.delete(key));
			inFlight.set(key, postRequest);
			return postRequest;
		}

		// Writes invalidate everything — the next reads refill the cache.
		// Exception: URL-inspection scan chunks fire every few seconds while
		// a scan runs and only mutate scan state, which is never cached.
		if (path.startsWith('/thinkrank-pro/v1/url-inspection/')) {
			return next(options);
		}
		return next(options).finally(purgeAll);
	}

	// Callers that need raw Response objects bypass the cache.
	if (options.parse === false) {
		return next(options);
	}

	const ttl = ttlForPath(path);
	if (ttl <= 0) {
		return next(options);
	}

	const cached = readCache(path);
	if (cached !== undefined) {
		return Promise.resolve(cached);
	}

	if (inFlight.has(path)) {
		return inFlight.get(path);
	}

	const request = next(options)
		.then((response) => {
			if (isCacheableResponse(path, response)) {
				writeCache(path, response, ttl);
			}
			return response;
		})
		.finally(() => {
			inFlight.delete(path);
		});

	inFlight.set(path, request);
	return request;
});

// Server-side preloaded REST responses (Site Kit pattern): registered AFTER
// the cache middleware so it runs first — each preloaded GET is served from
// memory exactly once, before any network or cache lookup.
if (typeof window !== 'undefined' && window.thinkrankApiPreload) {
	apiFetch.use(
		apiFetch.createPreloadingMiddleware(window.thinkrankApiPreload)
	);
}

if (typeof window !== 'undefined') {
	window.thinkrankApiCache = { purge: purgeAll };
}
