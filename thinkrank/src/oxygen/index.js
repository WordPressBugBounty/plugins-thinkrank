/**
 * ThinkRank Oxygen / Breakdance Editor Entry Point
 *
 * Mounts the shared React SEO metabox (the same `MetaboxApp` + drawer used by
 * the classic/block editor) inside the Oxygen 6 / Breakdance builder. The
 * builder is a standalone Vue SPA served on a front-end request; it doesn't
 * render WordPress metaboxes and doesn't submit the #post form, so this entry:
 *
 *  - reads its initial data from the localized `thinkrankMetabox` object (which
 *    here also carries `existingMetadata`, `contentPreview` and `postTitle`,
 *    since there are no hidden #post inputs to read from);
 *  - passes a `saveHandler` that persists every field through the
 *    `thinkrank_save_metabox` AJAX route;
 *  - passes a `contentResolver` that reads the builder preview iframe for AI
 *    analysis.
 *
 * The launcher is a ThinkRank icon injected into the builder's top bar (next to
 * Save), mirroring the Elementor top-bar action. Oxygen exposes no public API
 * for this, so the icon is inserted into the top bar DOM directly (Vuetify
 * `.breakdance-topbar` → the right-hand `.topbar-section` that holds
 * `.button-save-oxygen`) once the async Vue app has rendered it. Clicking it
 * toggles the drawer via the `thinkrank:toggle-seo-drawer` window event that
 * MetaboxApp listens for. If the top bar never appears, we fall back to the
 * drawer's own floating launcher via `thinkrank:pin-failed` so the feature is
 * never unreachable.
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
import { persistSeoFields } from '../metabox/utils/persistSeoFields';

let mounted = false;
let pinInjected = false;

// Window event MetaboxApp listens for to open/close the drawer.
const TOGGLE_EVENT = 'thinkrank:toggle-seo-drawer';
// Emitted when the top-bar icon can't be placed, so the drawer reveals its own
// floating launcher instead (MetaboxApp handles this event).
const PIN_FAILED_EVENT = 'thinkrank:pin-failed';
// Emitted once the top-bar icon is in place (clears any earlier pin-failed).
const PIN_RENDERED_EVENT = 'thinkrank:pin-rendered';

/**
 * Extract the post's plain-text content from the builder preview iframe.
 *
 * Breakdance/Oxygen render the page inside an iframe; the id has changed across
 * versions, so a few known selectors are tried before giving up (the localized
 * `contentPreview` remains the fallback inside MetaboxApp).
 *
 * @return {string} Plain text content, or '' when the preview isn't ready.
 */
function getBuilderContent() {
	const selectors = [
		'#breakdance-iframe',
		'#oxygen-iframe',
		'iframe.breakdance-iframe',
		'iframe.oxygen-builder-iframe',
		'iframe[name="oxygen-iframe"]',
	];

	for (const selector of selectors) {
		try {
			const frame = document.querySelector(selector);
			const doc =
				frame?.contentDocument || frame?.contentWindow?.document;
			if (doc) {
				const el =
					doc.querySelector('.breakdance, #oxygen-wrapper') ||
					doc.body;
				const text = (el?.innerText || el?.textContent || '').trim();
				if (text) {
					return text;
				}
			}
		} catch (e) {
			// Cross-origin / not-ready — try the next selector.
		}
	}

	return '';
}

/**
 * Minimal, self-contained save feedback. The builder has no toast API we can
 * rely on, so a small transient banner always confirms the result.
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
 * This saves SEO metadata ONLY. It deliberately does not touch the builder's
 * own document save: Oxygen/Breakdance keep their tree under `_breakdance_data`
 * and persist it through their own Save button. The ThinkRank drawer only
 * writes SEO fields and never mutates editor state or the post status.
 *
 * @param {Object} formData Current MetaboxApp form state.
 * @return {Promise<void>}
 */
async function saveViaAjax(formData) {
	const result = await persistSeoFields(formData);
	notify(result.message, !result.success);
}

/**
 * Build the top-bar launcher button: a ThinkRank icon that toggles the drawer.
 *
 * @return {HTMLElement} The launcher wrapper element.
 */
function createTopBarButton() {
	const wrap = document.createElement('div');
	wrap.className = 'thinkrank-oxygen-topbar-item';
	wrap.style.cssText = 'display:flex;align-items:center;';

	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'thinkrank-oxygen-launcher';
	button.title = __('ThinkRank SEO', 'thinkrank');
	button.setAttribute('aria-label', __('ThinkRank SEO', 'thinkrank'));
	button.style.cssText = [
		'display:flex',
		'align-items:center',
		'justify-content:center',
		'width:34px',
		'height:34px',
		'padding:0',
		'border:0',
		'border-radius:6px',
		'background:transparent',
		'cursor:pointer',
		'transition:background .15s ease',
	].join(';');
	button.addEventListener('mouseenter', () => {
		button.style.background = 'rgba(255,255,255,.12)';
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
 * Insert the launcher into the builder top bar, to the left of the Save button.
 *
 * @return {boolean} True when the button was placed.
 */
function injectTopBarButton() {
	if (
		pinInjected ||
		document.querySelector('.thinkrank-oxygen-topbar-item')
	) {
		return true;
	}

	// Oxygen 6 renders a raw <button class="button-save-oxygen"> inside the
	// right-hand `.topbar-section`; anchor off it rather than nth-child.
	const saveBtn = document.querySelector('.button-save-oxygen');
	const section = saveBtn
		? saveBtn.closest('.topbar-section')
		: document.querySelector(
				'.breakdance-topbar .topbar-section:last-child'
			);

	if (!section) {
		return false;
	}

	const button = createTopBarButton();

	if (saveBtn && saveBtn.parentElement?.parentElement === section) {
		section.insertBefore(button, saveBtn.parentElement);
	} else if (saveBtn && section.contains(saveBtn)) {
		saveBtn.insertAdjacentElement('beforebegin', button);
	} else {
		section.appendChild(button);
	}

	pinInjected = true;
	window.dispatchEvent(new CustomEvent(PIN_RENDERED_EVENT));
	return true;
}

/**
 * Wait for the async Vue top bar to render, then inject the launcher. Falls back
 * to the drawer's floating launcher if the top bar never shows.
 *
 * @return {void}
 */
function watchForTopBar() {
	if (injectTopBarButton()) {
		return;
	}

	let tries = 0;
	const timer = setInterval(() => {
		tries += 1;
		if (injectTopBarButton()) {
			clearInterval(timer);
		} else if (tries > 60) {
			// ~15s elapsed and no top bar — reveal the floating launcher so the
			// drawer is still reachable.
			clearInterval(timer);
			window.dispatchEvent(new CustomEvent(PIN_FAILED_EVENT));
		}
	}, 250);
}

function mount() {
	if (mounted) {
		return;
	}

	const data = window.thinkrankMetabox || {};

	let container = document.getElementById('thinkrank-oxygen-root');
	if (!container) {
		container = document.createElement('div');
		container.id = 'thinkrank-oxygen-root';
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

	watchForTopBar();
}

if (document.readyState !== 'loading') {
	mount();
} else {
	document.addEventListener('DOMContentLoaded', mount);
}
