/**
 * ThinkRank Bricks Builder Entry Point
 *
 * Mounts the shared React SEO metabox (the same `MetaboxApp` + drawer used by
 * the classic/block editor) inside the Bricks builder. Bricks is a front-end
 * editing overlay: it doesn't render WordPress metaboxes and doesn't submit the
 * #post form, so this entry:
 *
 *  - reads its initial data from the localized `thinkrankMetabox` object (which
 *    here also carries `existingMetadata`, `contentPreview` and `postTitle`,
 *    since there are no hidden #post inputs to read from);
 *  - passes a `saveHandler` that persists every field through the
 *    `thinkrank_save_metabox` AJAX route;
 *  - passes a `contentResolver` that reads the Bricks canvas for live analysis.
 *
 * The launcher is a ThinkRank logo injected into the Bricks toolbar, mirroring
 * the Elementor/Oxygen/Divi top-bar actions. Bricks exposes no public API for
 * adding a toolbar item, so the icon is inserted into the toolbar DOM directly
 * once Bricks' own Vue app has rendered it. Clicking it toggles the drawer via
 * the `thinkrank:toggle-seo-drawer` window event that MetaboxApp listens for.
 *
 * Unlike the Divi entry, this one keeps the `thinkrank:pin-failed` fallback:
 * the toolbar markup is private to Bricks and can be restyled between releases,
 * so if the anchor never appears the drawer still reveals its own floating
 * launcher rather than becoming unreachable.
 *
 * @package
 * @since 2.2.1
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import MetaboxApp from '../metabox/components/MetaboxApp';
import LauncherMark from '../metabox/components/LauncherMark';
import '../metabox/styles.scss';
import '../global/base.css';
import '../global/tailwind.css';
// Bricks-only stacking overrides — must load last so it wins the cascade.
import './bricks.scss';
import { persistSeoFields } from '../metabox/utils/persistSeoFields';
import { followInstantNavigation } from './navigation';
import { clearPanelStatus, showPanelStatus } from './panel-status';

let mounted = false;
let pinInjected = false;
let root = null;

// Set on <body> while the open document has no panel (a Bricks template, or a
// switch still loading), so bricks.scss hides the toolbar launcher.
const UNSUPPORTED_CLASS = 'thinkrank-bricks-unsupported';

// Window event MetaboxApp listens for to open/close the drawer.
const TOGGLE_EVENT = 'thinkrank:toggle-seo-drawer';
// Emitted when the toolbar icon can't be placed, so the drawer reveals its own
// floating launcher instead (MetaboxApp handles this event).
const PIN_FAILED_EVENT = 'thinkrank:pin-failed';
// Emitted once the toolbar icon is in place (clears any earlier pin-failed).
const PIN_RENDERED_EVENT = 'thinkrank:pin-rendered';

/**
 * The toolbar group holding Undo/Redo/Structure/Save, most current first.
 *
 * Confirmed against Bricks 2.3.12, whose toolbar is three `<ul>`s —
 * `.group-wrapper.start`, `.group-wrapper.breakpoints` and `.group-wrapper.end`
 * — with the save action last in `end`. The looser selectors below are the
 * fallbacks for other releases; the bare `#bricks-toolbar` is the last resort,
 * and if none of them match the drawer falls back to its own floating launcher
 * rather than becoming unreachable.
 *
 * Bricks paints its chrome in the top window and renders the page itself in a
 * child iframe, so the toolbar is always in this document.
 */
const TOOLBAR_SELECTORS = [
	'#bricks-toolbar ul.group-wrapper.end',
	'#bricks-toolbar ul.group.right',
	'#bricks-toolbar .group.right',
	'#bricks-toolbar ul:last-of-type',
	'#bricks-toolbar',
	'.bricks-toolbar .group-wrapper.end',
	'.bricks-toolbar',
];

/**
 * Save action inside that group. The launcher goes immediately before it, so
 * ThinkRank sits to the left of Save exactly as it does in the Elementor,
 * Oxygen and Divi top bars, rather than past the right edge of the toolbar.
 */
const SAVE_SELECTOR = 'li.save, .save';

/**
 * Extract the post's plain-text content from the Bricks canvas.
 *
 * Bricks renders the page in a child iframe (`#bricks-builder-iframe`), so the
 * canvas document is read through the frame; the id has changed across releases,
 * hence the list. The localized `contentPreview` remains the fallback inside
 * MetaboxApp when nothing here is readable.
 *
 * @return {string} Plain text content, or '' when the canvas isn't ready.
 */
function getBuilderContent() {
	const frameSelectors = [
		'#bricks-builder-iframe',
		'iframe#bricks-builder-iframe',
		'iframe[name="bricks-builder-iframe"]',
	];

	const docs = [];

	for (const selector of frameSelectors) {
		try {
			const frame = document.querySelector(selector);
			const doc =
				frame?.contentDocument || frame?.contentWindow?.document;
			if (doc) {
				docs.push(doc);
			}
		} catch (e) {
			// Cross-origin / not-ready — try the next selector.
		}
	}

	// Any other same-origin iframe, in case Bricks renames the canvas again.
	try {
		document.querySelectorAll('iframe').forEach((frame) => {
			const doc = frame.contentDocument || frame.contentWindow?.document;
			if (doc && !docs.includes(doc)) {
				docs.push(doc);
			}
		});
	} catch (e) {
		// Cross-origin iframe — ignore.
	}

	for (const doc of docs) {
		// `#brx-content` is Bricks' own content wrapper; the body is the
		// fallback when a template renders outside it.
		const containers = ['#brx-content', 'main.brx-content', '#brx-body'];
		for (const selector of containers) {
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

		try {
			const text = (
				doc.body?.innerText ||
				doc.body?.textContent ||
				''
			).trim();
			if (text) {
				return text;
			}
		} catch (e) {
			// Not ready — try the next document.
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
 * This saves SEO metadata ONLY. It deliberately does not touch Bricks' own
 * document save: Bricks keeps its layout in postmeta and persists it through its
 * own Save button. The ThinkRank drawer only writes SEO fields and never mutates
 * builder state or the post status.
 *
 * @param {Object} formData Current MetaboxApp form state.
 * @param {number} postId   Post the panel was rendered for.
 * @return {Promise<{success: boolean, message: string}>} Save outcome.
 */
async function saveViaAjax(formData, postId) {
	// The payload takes its post ID from the live config. A panel rendered for
	// another document must never save into the one now open (#971).
	if (Number(window.thinkrankMetabox?.postId) !== Number(postId)) {
		const message = __(
			'This SEO panel belongs to a document that is no longer open. Nothing was saved.',
			'thinkrank'
		);
		notify(message, true);
		// Not a confirmed save, so MetaboxApp keeps its local draft.
		return { success: false, message };
	}

	const result = await persistSeoFields(formData);
	notify(result.message, !result.success);
	// MetaboxApp drops its local draft only on a confirmed save.
	return result;
}

/**
 * Build the toolbar launcher: a ThinkRank icon that toggles the drawer.
 *
 * Rendered as an `<li>` so it sits correctly inside Bricks' `<ul>` toolbar
 * groups; a stray list item is still valid layout in the non-list fallback
 * containers, where the inline flex styling carries it.
 *
 * @return {HTMLElement} The launcher element.
 */
function createToolbarButton() {
	const item = document.createElement('li');
	item.className = 'thinkrank-bricks-toolbar-item';
	item.style.cssText =
		'display:flex;align-items:center;justify-content:center;list-style:none;';

	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'thinkrank-bricks-launcher';
	button.title = __('ThinkRank SEO', 'thinkrank');
	button.setAttribute('aria-label', __('ThinkRank SEO', 'thinkrank'));
	button.style.cssText = [
		'display:flex',
		'align-items:center',
		'justify-content:center',
		'width:34px',
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
	item.appendChild(button);

	// Logo + live SEO score, the same mark the block editor's pinned launcher
	// shows. It keeps itself in sync with the drawer through the
	// `thinkrank:score-updated` broadcast, so re-analysing in the builder
	// updates the toolbar without a reload (#257).
	createRoot(iconMount).render(<LauncherMark size={22} />);

	return item;
}

/**
 * Insert the launcher into the Bricks toolbar.
 *
 * @return {boolean} True when the button is in place.
 */
function injectToolbarButton() {
	// Already placed — nothing to do (a Bricks re-render can drop it, in which
	// case the node is gone and this falls through to re-insert).
	if (document.querySelector('.thinkrank-bricks-toolbar-item')) {
		pinInjected = true;
		return true;
	}

	for (const selector of TOOLBAR_SELECTORS) {
		const target = document.querySelector(selector);
		if (!target) {
			continue;
		}

		const button = createToolbarButton();
		const save = target.querySelector(SAVE_SELECTOR);

		if (save) {
			target.insertBefore(button, save);
		} else {
			target.appendChild(button);
		}

		pinInjected = true;
		window.dispatchEvent(new CustomEvent(PIN_RENDERED_EVENT));
		return true;
	}

	return false;
}

/**
 * Wait for Bricks' async Vue toolbar to render, then inject the launcher, and
 * keep it there across re-renders. Falls back to the drawer's own floating
 * launcher if the toolbar never appears.
 *
 * @return {void}
 */
function watchForToolbar() {
	injectToolbarButton();

	let tries = 0;
	const timer = setInterval(() => {
		tries += 1;
		if (injectToolbarButton()) {
			clearInterval(timer);
		} else if (tries > 60) {
			// ~15s elapsed and no toolbar — reveal the floating launcher so the
			// drawer is still reachable.
			clearInterval(timer);
			if (!pinInjected) {
				window.dispatchEvent(new CustomEvent(PIN_FAILED_EVENT));
			}
		}
	}, 250);

	// Survive Vue re-renders of the toolbar (injectToolbarButton is a no-op
	// when the icon is already present, so this never duplicates).
	try {
		const observer = new MutationObserver(() => {
			if (pinInjected) {
				injectToolbarButton();
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	} catch (e) {
		// MutationObserver unavailable — the interval above still covers mount.
	}
}

/**
 * Render the panel for a document's config, or nothing.
 *
 * Keyed on the post ID, so a switch remounts the whole panel with fresh state
 * instead of carrying the previous document's fields over (#971).
 *
 * @param {Object|null} data Panel config, or null to show nothing.
 * @return {void}
 */
function renderPanel(data) {
	// `wp_localize_script` turns false into '', hence the cast.
	const supported = !!(data && data.supported && data.postId);

	document.body.classList.toggle(UNSUPPORTED_CLASS, !supported);

	if (!supported) {
		root.render(null);
		return;
	}

	root.render(
		<MetaboxApp
			key={`post-${data.postId}`}
			postId={data.postId}
			existingMetadata={data.existingMetadata || {}}
			contentPreview={data.contentPreview || ''}
			contentFull={data.contentFull}
			strings={data.strings || {}}
			postTitle={data.postTitle || ''}
			homeUrl={data.homeUrl || window.location.origin}
			postModified={data.postModified}
			linkSuggestionsEnabled={data.linkSuggestionsEnabled}
			saveHandler={(formData) => saveViaAjax(formData, data.postId)}
			contentResolver={getBuilderContent}
			hideLauncher={true}
		/>
	);
}

function mount() {
	if (mounted) {
		return;
	}

	const data = window.thinkrankMetabox || {};

	let container = document.getElementById('thinkrank-bricks-root');
	if (!container) {
		container = document.createElement('div');
		container.id = 'thinkrank-bricks-root';
		container.className = 'thinkrank-metabox';
		document.body.appendChild(container);
	}

	mounted = true;

	root = createRoot(container);
	renderPanel(data);

	// Bricks' instant navigation switches documents without a reload. Drop
	// the old panel the moment it does, then render the new document's.
	// While it loads, and if it fails, a banner says so; a failure offers a
	// Retry, since the panel would otherwise stay gone until a reload.
	followInstantNavigation({
		getConfig: () => window.thinkrankMetabox || {},
		onLeave: (postId) => {
			window.thinkrankMetabox = {
				...(window.thinkrankMetabox || {}),
				postId,
				supported: false,
			};
			renderPanel(null);
		},
		onLoad: () => showPanelStatus('loading'),
		onArrive: (next) => {
			clearPanelStatus();
			window.thinkrankMetabox = next;
			renderPanel(next);
		},
		onError: (postId, retry) =>
			showPanelStatus('error', { onRetry: retry }),
	});

	watchForToolbar();
}

// The toolbar and chrome live in the top window; Bricks renders the page in a
// child iframe. The PHP side already gates on `bricks_is_builder_main()`, so
// the canvas never loads this bundle — this is belt and braces against a
// future Bricks release enqueueing it there too.
if (window.self === window.top) {
	if (document.readyState !== 'loading') {
		mount();
	} else {
		document.addEventListener('DOMContentLoaded', mount);
	}
}
