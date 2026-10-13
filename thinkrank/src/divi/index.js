/**
 * ThinkRank Divi Visual Builder Entry Point
 *
 * Mounts the shared React SEO metabox (the same `MetaboxApp` + drawer used by
 * the classic/block editor) inside the Divi Visual Builder. The VB is a
 * front-end editing overlay; it doesn't render WordPress metaboxes and doesn't
 * submit the #post form, so this entry:
 *
 *  - reads its initial data from the localized `thinkrankMetabox` object (which
 *    here also carries `existingMetadata`, `contentPreview` and `postTitle`,
 *    since there are no hidden #post inputs to read from);
 *  - passes a `saveHandler` that persists every field through the
 *    `thinkrank_save_metabox` AJAX route;
 *  - passes a `contentResolver` that reads the VB's rendered content for AI
 *    analysis.
 *
 * The launcher is a ThinkRank logo injected into the VB page bar tools
 * (`.et-vb-page-bar-tools`), mirroring the Elementor/Oxygen top-bar actions.
 * Divi exposes no public API for this, so the icon is inserted into the bar DOM
 * directly once the async React app has rendered it. Clicking it toggles the
 * drawer via the `thinkrank:toggle-seo-drawer` window event that MetaboxApp
 * listens for. If the bar never appears, we fall back to the drawer's own
 * floating launcher via `thinkrank:pin-failed` so the feature is never
 * unreachable.
 *
 * @package
 * @since 1.24.0
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import MetaboxApp from '../metabox/components/MetaboxApp';
import ThinkRankLogo from '../metabox/components/ThinkRankLogo';
import '../metabox/styles.scss';
import '../global/base.css';
import '../global/tailwind.css';
// Divi-only stacking overrides — must load last so it wins the cascade.
import './divi.scss';
import { persistSeoFields } from '../metabox/utils/persistSeoFields';

let mounted = false;

// Window event MetaboxApp listens for to open/close the drawer.
const TOGGLE_EVENT = 'thinkrank:toggle-seo-drawer';
// Emitted once the bar icon is in place.
const PIN_RENDERED_EVENT = 'thinkrank:pin-rendered';

// The launcher is inserted immediately before the VB page bar's right-hand
// cluster (`.et-vb-page-bar-right-side-save-button`, which holds Exit / Preview
// / Save), so the ThinkRank icon sits just to the left of that group.
const RIGHT_SIDE_SELECTOR = '.et-vb-page-bar-right-side-save-button';

/**
 * Extract the post's plain-text content from the VB's rendered page.
 *
 * The Divi VB renders in the same document (no iframe), so a few known content
 * containers are tried before giving up (the localized `contentPreview` remains
 * the fallback inside MetaboxApp).
 *
 * @return {string} Plain text content, or '' when nothing is readable.
 */
function getBuilderContent() {
	const selectors = [
		'.et-l--body',
		'#et-boc .et_builder_inner_content',
		'#et-boc',
		'.et_builder_inner_content',
		'#main-content',
	];

	// The VB renders page content inside a child iframe, so search this document
	// and any same-origin iframe documents.
	const docs = [document];
	try {
		document.querySelectorAll('iframe').forEach((frame) => {
			const doc = frame.contentDocument || frame.contentWindow?.document;
			if (doc) {
				docs.push(doc);
			}
		});
	} catch (e) {
		// Cross-origin iframe — ignore.
	}

	for (const doc of docs) {
		for (const selector of selectors) {
			try {
				const el = doc.querySelector(selector);
				const text = (el?.innerText || el?.textContent || '').trim();
				if (text) {
					return text;
				}
			} catch (e) {
				// Not ready — try the next selector.
			}
		}
	}

	return '';
}

/**
 * Minimal, self-contained save feedback. A small transient banner always
 * confirms the result.
 *
 * @param {string}  message Text to display.
 * @param {boolean} isError Whether this is an error toast.
 * @return {void}
 */
function notify(message, isError = false) {
	const toast = document.createElement('div');
	toast.textContent = message;
	toast.setAttribute('role', 'status');
	toast.style.cssText = [
		'position:fixed',
		'bottom:20px',
		'left:50%',
		'transform:translateX(-50%)',
		'z-index:2147483647',
		'padding:10px 16px',
		'border-radius:8px',
		'font:13px/1.4 sans-serif',
		'color:#fff',
		`background:${isError ? '#dc2626' : '#16a34a'}`,
		'box-shadow:0 4px 12px rgba(0,0,0,.2)',
	].join(';');
	document.body.appendChild(toast);
	setTimeout(() => toast.remove(), 4000);
}

/**
 * Persist the metabox fields through admin-ajax (no #post form in the builder).
 *
 * This saves SEO metadata ONLY. It deliberately does not touch Divi's own
 * document save: Divi keeps its layout in post_content and persists it through
 * its own Save button. The ThinkRank drawer only writes SEO fields and never
 * mutates editor state or the post status.
 *
 * @param {Object} formData Current MetaboxApp form state.
 * @return {Promise<void>}
 */
async function saveViaAjax(formData) {
	const result = await persistSeoFields(formData);
	notify(result.message, !result.success);
}

/**
 * Build the page-bar launcher button: a ThinkRank icon that toggles the drawer.
 *
 * @return {HTMLElement} The launcher wrapper element.
 */
function createBarButton() {
	const wrap = document.createElement('div');
	wrap.className = 'thinkrank-divi-bar-item';
	wrap.style.cssText = 'display:flex;align-items:center;';

	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'thinkrank-divi-launcher';
	button.title = __('ThinkRank SEO', 'thinkrank');
	button.setAttribute('aria-label', __('ThinkRank SEO', 'thinkrank'));
	button.style.cssText = [
		'display:flex',
		'align-items:center',
		'justify-content:center',
		'width:32px',
		'height:32px',
		'padding:0',
		'margin:0 2px',
		'border:0',
		'border-radius:50%',
		'background:transparent',
		'cursor:pointer',
		'transition:background .15s ease',
	].join(';');
	button.addEventListener('mouseenter', () => {
		button.style.background = 'rgba(0,0,0,.06)';
	});
	button.addEventListener('mouseleave', () => {
		button.style.background = 'transparent';
	});
	button.addEventListener('click', (e) => {
		e.preventDefault();
		e.stopPropagation();
		window.dispatchEvent(new CustomEvent(TOGGLE_EVENT));
	});

	const iconMount = document.createElement('span');
	iconMount.style.cssText = 'display:inline-flex;line-height:0;';
	button.appendChild(iconMount);
	wrap.appendChild(button);

	createRoot(iconMount).render(<ThinkRankLogo size={22} />);

	return wrap;
}

/**
 * Insert the launcher into the VB page bar tools.
 *
 * @return {boolean} True when the button was placed.
 */
function injectBarButton() {
	// Already placed — nothing to do.
	if (document.querySelector('.thinkrank-divi-bar-item')) {
		return true;
	}

	const rightSide = document.querySelector(RIGHT_SIDE_SELECTOR);
	if (rightSide && rightSide.parentNode) {
		rightSide.parentNode.insertBefore(createBarButton(), rightSide);
		window.dispatchEvent(new CustomEvent(PIN_RENDERED_EVENT));
		return true;
	}

	return false;
}

/**
 * Keep the launcher present in the (React-rendered) VB page bar.
 *
 * The bar mounts asynchronously and Divi re-renders it, so we retry until the
 * right-side cluster appears and re-insert the icon if a re-render drops it.
 * There is no floating-launcher fallback in the Visual Builder — the page-bar
 * icon is the only launcher.
 *
 * @return {void}
 */
function watchForBar() {
	injectBarButton();

	// Retry while the bar is still mounting.
	let tries = 0;
	const timer = setInterval(() => {
		tries += 1;
		if (injectBarButton() || tries > 120) {
			clearInterval(timer);
		}
	}, 250);

	// Survive React re-renders of the page bar (injectBarButton is a no-op when
	// the icon is already present, so this never duplicates).
	try {
		const observer = new MutationObserver(() => injectBarButton());
		observer.observe(document.body, { childList: true, subtree: true });
	} catch (e) {
		// MutationObserver unavailable — the interval above still covers mount.
	}
}

function mount() {
	if (mounted) {
		return;
	}

	const data = window.thinkrankMetabox || {};

	let container = document.getElementById('thinkrank-divi-root');
	if (!container) {
		container = document.createElement('div');
		container.id = 'thinkrank-divi-root';
		container.className = 'thinkrank-metabox';
		document.body.appendChild(container);
	}

	mounted = true;

	const root = createRoot(container);
	root.render(
		<MetaboxApp
			postId={data.postId}
			existingMetadata={data.existingMetadata || {}}
			contentPreview={data.contentPreview || ''}
			contentFull={data.contentFull}
			strings={data.strings || {}}
			postTitle={data.postTitle || ''}
			homeUrl={data.homeUrl || window.location.origin}
			postModified={data.postModified}
			linkSuggestionsEnabled={data.linkSuggestionsEnabled}
			saveHandler={saveViaAjax}
			contentResolver={getBuilderContent}
			hideLauncher={true}
		/>
	);

	watchForBar();
}

// The VB page bar and chrome live in the top window; Divi renders page content
// in a child iframe that also loads this bundle. Only run in the top window so
// a single drawer + launcher mount — and no stray floating launcher appears in
// the content iframe.
if (window.self === window.top) {
	if (document.readyState !== 'loading') {
		mount();
	} else {
		document.addEventListener('DOMContentLoaded', mount);
	}
}
