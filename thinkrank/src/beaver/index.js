/**
 * ThinkRank Beaver Builder Entry Point
 *
 * Mounts the shared React SEO metabox (the same `MetaboxApp` + drawer used by
 * the classic/block editor) inside the Beaver Builder editor. Beaver Builder is
 * a front-end editing overlay: it doesn't render WordPress metaboxes and doesn't
 * submit the #post form, so this entry:
 *
 *  - reads its initial data from the localized `thinkrankMetabox` object (which
 *    here also carries `existingMetadata`, `contentPreview` and `postTitle`,
 *    since there are no hidden #post inputs to read from);
 *  - passes a `saveHandler` that persists every field through the
 *    `thinkrank_save_metabox` AJAX route;
 *  - passes a `contentResolver` that reads the rendered layout for live
 *    analysis.
 *
 * The launcher is different in kind from the Divi and Bricks ones. Those inject
 * a node into private toolbar markup, because neither builder exposes a way to
 * add one. Beaver Builder does: `Beaver_Metabox::add_bar_button()` registers the
 * button through the public `fl_builder_ui_bar_buttons` filter, so the element
 * is Beaver Builder's own — rendered by Beaver Builder, styled by Beaver
 * Builder's `fl-builder-button-silent`, and positioned by Beaver Builder. This
 * file only fills it in and wires it up.
 *
 * Where that button ends up is the part worth knowing. Beaver Builder's UI code
 * runs inside the layout iframe and renders the toolbar from the
 * `#tmpl-fl-toolbar` underscore template found THERE, then hands the result to
 * the top window: `Toolbar.render()` ends in
 * `$( 'body', window.parent.document ).prepend( $html )`. So this bundle — which
 * PHP loads in the top window only — finds the button in its own document even
 * though the markup was authored in the frame's. Hence:
 *
 *  - the click handler is DELEGATED from the document rather than bound to the
 *    element, because that toolbar is re-rendered wholesale (on save, on preview
 *    mode, on responsive breakpoint changes) and a bound listener would be
 *    discarded with the old node;
 *  - the `LauncherMark` (logo + live SEO score badge) is mounted into the
 *    button's empty `.thinkrank-fl-launcher-mark` span, and re-mounted whenever
 *    a re-render replaces that span with a fresh one.
 * * The `thinkrank:pin-failed` fallback is kept, as in the Bricks entry: the
 * filter is public but a white-labelled or heavily customised build can still
 * remove the button, and the drawer must never become unreachable.
 *
 * @package
 * @since 2.2.2
 */

import { createRoot } from '@wordpress/element';
import MetaboxApp from '../metabox/components/MetaboxApp';
import LauncherMark from '../metabox/components/LauncherMark';
import '../metabox/styles.scss';
import '../global/base.css';
import '../global/tailwind.css';
// Beaver-only stacking overrides — must load last so it wins the cascade.
import './beaver.scss';
import { persistSeoFields } from '../metabox/utils/persistSeoFields';

let mounted = false;
let pinInjected = false;

// Window event MetaboxApp listens for to open/close the drawer.
const TOGGLE_EVENT = 'thinkrank:toggle-seo-drawer';
// Emitted when the toolbar button never appears, so the drawer reveals its own
// floating launcher instead (MetaboxApp handles this event).
const PIN_FAILED_EVENT = 'thinkrank:pin-failed';
// Emitted once the toolbar button is live (clears any earlier pin-failed).
const PIN_RENDERED_EVENT = 'thinkrank:pin-rendered';

// The button Beaver_Metabox::add_bar_button() registers, and the empty span
// inside it that LauncherMark is rendered into.
const LAUNCHER_SELECTOR = '.thinkrank-fl-launcher';
const MARK_SELECTOR = '.thinkrank-fl-launcher-mark';
// Added to the button once its mark is mounted; the stylesheet keeps the button
// hidden until then so no empty gap appears in the toolbar.
const READY_CLASS = 'thinkrank-is-ready';

// Mark spans already rendered into. A WeakSet rather than a class flag because
// a toolbar re-render produces a brand-new span, which must be rendered into
// again — while the surviving ones must not be mounted twice.
const mountedMarks = new WeakSet();

/**
 * Extract the post's plain-text content from the rendered layout.
 *
 * Beaver Builder wraps each layout in `.fl-builder-content`, tagging the one
 * belonging to the post in the main query with `.fl-builder-content-primary`.
 * That distinction matters: a Beaver Themer site renders its header and footer
 * layouts as sibling `.fl-builder-content` nodes, and scoring those as part of
 * the post would report the same boilerplate on every page.
 *
 * Where that markup lives depends on which UI is running. The modern iframe UI
 * (Beaver Builder 2.4+, the default) paints its chrome in the top window and
 * renders the page inside `#fl-builder-ui-iframe`, so the layout is in the frame
 * document; the legacy UI renders both in one document. Both are searched, this
 * document first. The localized `contentPreview` remains the fallback inside
 * MetaboxApp when nothing here is readable.
 *
 * @return {string} Plain text content, or '' when nothing is readable.
 */
function getBuilderContent() {
	const docs = [document];

	// The layout frame, then any other same-origin frame in case the id
	// changes in a future release.
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

	const selectors = [
		'.fl-builder-content-primary',
		'.fl-builder-content',
		'.fl-post-content',
	];

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
 * Minimal, self-contained save feedback. The builder's own notification API is
 * internal, so a small transient banner always confirms the result.
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
 * This saves SEO metadata ONLY. It deliberately does not touch Beaver Builder's
 * own document save: the builder keeps its layout in the `_fl_builder_data` /
 * `_fl_builder_draft` postmeta and persists it through its own Publish button.
 * The ThinkRank drawer only writes SEO fields and never mutates builder state or
 * the post status.
 *
 * @param {Object} formData Current MetaboxApp form state.
 * @return {Promise<{success: boolean, message: string}>} Save outcome.
 */
async function saveViaAjax(formData) {
	const result = await persistSeoFields(formData);
	notify(result.message, !result.success);
	// MetaboxApp drops its local draft only on a confirmed save.
	return result;
}

/**
 * Render the launcher mark into any toolbar button that doesn't have one yet.
 *
 * Beaver Builder renders the button itself (see `fl_builder_ui_bar_buttons`), so
 * there is nothing to inject here — only the React mark to fill it with, and the
 * ready class that reveals it.
 *
 * @return {boolean} True when at least one launcher is mounted and visible.
 */
function renderLauncherMarks() {
	const marks = document.querySelectorAll(MARK_SELECTOR);
	let live = false;

	marks.forEach((mark) => {
		const button = mark.closest(LAUNCHER_SELECTOR);

		if (!mountedMarks.has(mark)) {
			mountedMarks.add(mark);
			createRoot(mark).render(<LauncherMark size={22} />);
		}

		if (button) {
			button.classList.add(READY_CLASS);
			live = true;
		}
	});

	if (live && !pinInjected) {
		pinInjected = true;
		window.dispatchEvent(new CustomEvent(PIN_RENDERED_EVENT));
	}

	return live;
}

/**
 * Toggle the drawer when the toolbar launcher is clicked.
 *
 * Delegated from the document so it survives the toolbar being re-rendered from
 * its underscore template, which discards the button element itself.
 *
 * @return {void}
 */
function bindLauncherClicks() {
	document.addEventListener(
		'click',
		(event) => {
			const launcher = event.target?.closest?.(LAUNCHER_SELECTOR);
			if (!launcher) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			window.dispatchEvent(new CustomEvent(TOGGLE_EVENT));
		},
		// Capture, so Beaver Builder's own delegated bar handlers can't swallow
		// the click before it reaches us.
		true
	);
}

/**
 * Watch for the toolbar, which Beaver Builder renders asynchronously and
 * re-renders in place, and keep the launcher mark mounted inside it.
 *
 * Falls back to the drawer's own floating launcher if the button never appears —
 * a white-labelled or customised build can filter it back out, and the drawer
 * must not become unreachable when it does.
 *
 * @return {void}
 */
function watchForToolbar() {
	renderLauncherMarks();

	let tries = 0;
	const timer = setInterval(() => {
		tries += 1;
		if (renderLauncherMarks()) {
			clearInterval(timer);
		} else if (tries > 60) {
			// ~15s elapsed and no button — reveal the floating launcher so the
			// drawer is still reachable.
			clearInterval(timer);
			if (!pinInjected) {
				window.dispatchEvent(new CustomEvent(PIN_FAILED_EVENT));
			}
		}
	}, 250);

	// Survive re-renders of the toolbar: each one replaces the mark span, which
	// then needs the React root mounted into it again.
	try {
		const observer = new MutationObserver(() => {
			if (pinInjected) {
				renderLauncherMarks();
			}
		});
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

	let container = document.getElementById('thinkrank-beaver-root');
	if (!container) {
		container = document.createElement('div');
		container.id = 'thinkrank-beaver-root';
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

	bindLauncherClicks();
	watchForToolbar();
}

// The toolbar and chrome live in the top window; the modern UI renders the page
// itself in `#fl-builder-ui-iframe`. Beaver_Metabox already declines to enqueue
// on the layout iframe request, so this is belt and braces against a future
// release loading the bundle there too — mounting in both would give the builder
// two drawers.
if (window.self === window.top) {
	if (document.readyState !== 'loading') {
		mount();
	} else {
		document.addEventListener('DOMContentLoaded', mount);
	}
}
