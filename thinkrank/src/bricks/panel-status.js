/**
 * Loading and error state for a switched-to document's SEO panel.
 *
 * After an instant-navigation switch the old panel is hidden at once and the
 * new document's config is fetched (#971). Until it arrives there is no panel
 * and no toolbar launcher, so on its own a slow or failed request looked
 * exactly like a document that has no SEO panel, and a failed one could not
 * be brought back without leaving the page. This small banner says the panel
 * is loading, or that it failed, with a Retry that loads it again.
 *
 * Plain DOM rather than React: it shows while no MetaboxApp is mounted.
 *
 * @package
 * @since 2.15.1
 */

import { __ } from '@wordpress/i18n';

/**
 * Element ID of the banner.
 */
export const STATUS_ID = 'thinkrank-bricks-panel-status';

/**
 * How long a load runs before the loading state shows, so a quick switch
 * does not flash it.
 */
const LOADING_DELAY = 400;

let loadingTimer = null;

/**
 * Remove the banner, and any loading state still waiting to show.
 *
 * @param {Document} [doc] Document, for tests.
 * @return {void}
 */
export function clearPanelStatus(doc = document) {
	if (loadingTimer) {
		clearTimeout(loadingTimer);
		loadingTimer = null;
	}

	const existing = doc.getElementById(STATUS_ID);
	if (existing) {
		existing.remove();
	}
}

/**
 * Build the banner element.
 *
 * @param {Document}      doc     Document.
 * @param {boolean}       isError Whether this is the error state.
 * @param {Function|null} onRetry Retry callback for the error state.
 * @return {HTMLElement} The banner.
 */
function build(doc, isError, onRetry) {
	const banner = doc.createElement('div');
	banner.id = STATUS_ID;
	banner.setAttribute('role', isError ? 'alert' : 'status');
	banner.style.cssText = [
		'position:fixed',
		'bottom:20px',
		'right:20px',
		'z-index:2147483647',
		'display:flex',
		'align-items:center',
		'gap:12px',
		'max-width:360px',
		'padding:10px 14px',
		'border-radius:8px',
		'font:13px/1.4 sans-serif',
		'color:#fff',
		`background:${isError ? '#dc2626' : '#1f2937'}`,
		'box-shadow:0 4px 12px rgba(0,0,0,.2)',
	].join(';');

	const text = doc.createElement('span');
	text.textContent = isError
		? __(
				'ThinkRank could not load the SEO panel for this page.',
				'thinkrank'
			)
		: __('Loading the ThinkRank SEO panel…', 'thinkrank');
	banner.appendChild(text);

	if (isError && onRetry) {
		const button = doc.createElement('button');
		button.type = 'button';
		button.textContent = __('Retry', 'thinkrank');
		button.style.cssText = [
			'flex:none',
			'padding:4px 10px',
			'border:1px solid rgba(255,255,255,.7)',
			'border-radius:6px',
			'background:transparent',
			'color:#fff',
			'font:inherit',
			'cursor:pointer',
		].join(';');
		button.addEventListener('click', (e) => {
			e.preventDefault();
			onRetry();
		});
		banner.appendChild(button);
	}

	return banner;
}

/**
 * Show the loading or the error state, replacing whichever was showing.
 *
 * @param {string}   state             'loading' or 'error'.
 * @param {Object}   [options]
 * @param {Function} [options.onRetry] Called when Retry is clicked (error).
 * @param {Document} [options.doc]     Document, for tests.
 * @return {void}
 */
export function showPanelStatus(
	state,
	{ onRetry = null, doc = document } = {}
) {
	clearPanelStatus(doc);

	if ('error' === state) {
		doc.body.appendChild(build(doc, true, onRetry));
		return;
	}

	loadingTimer = setTimeout(() => {
		loadingTimer = null;
		doc.body.appendChild(build(doc, false, null));
	}, LOADING_DELAY);
}
