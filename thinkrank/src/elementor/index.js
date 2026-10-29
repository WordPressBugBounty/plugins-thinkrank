/**
 * ThinkRank Elementor Editor Entry Point
 *
 * Mounts the shared React SEO metabox (the same `MetaboxApp` + drawer used by
 * the classic/block editor) inside the Elementor editor. Elementor doesn't
 * render WordPress metaboxes and doesn't submit the #post form, so this entry:
 *
 *  - reads its initial data from the localized `thinkrankMetabox` object (which
 *    here also carries `existingMetadata`, `contentPreview` and `postTitle`,
 *    since there are no hidden #post inputs to read from);
 *  - passes a `saveHandler` that persists every field through the
 *    `thinkrank_save_metabox` AJAX route;
 *  - passes a `contentResolver` that reads the Elementor preview iframe for AI
 *    analysis.
 *
 * The launcher is the ThinkRank logo added to Elementor's v2 top-bar utilities
 * menu (top-right) via `window.elementorV2.editorAppBar.utilitiesMenu`. When
 * that API isn't available it falls back to the drawer's floating launcher.
 *
 * @package
 * @since 1.0.0
 */

import { createRoot, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import MetaboxApp from '../metabox/components/MetaboxApp';
import ThinkRankLogo from '../metabox/components/ThinkRankLogo';
import '../metabox/styles.scss';
import '../global/base.css';
import '../global/tailwind.css';
import { persistSeoFields } from '../metabox/utils/persistSeoFields';

let mounted = false;
let topBarRegistered = false;

// Window event the top-bar action dispatches to open/close the drawer.
const TOGGLE_EVENT = 'thinkrank:toggle-seo-drawer';
// Title used for both the tooltip and the DOM-presence fallback check.
const ACTION_TITLE = __('ThinkRank SEO', 'thinkrank');

/**
 * Whether Elementor's v2 editor app-bar injection API is available.
 *
 * @return {boolean} True when the top-bar action can be registered.
 */
function hasAppBarApi() {
	return !!window.elementorV2?.editorAppBar?.utilitiesMenu?.registerAction;
}

/**
 * Register the ThinkRank logo action in Elementor's top-bar utilities menu
 * (top-right). Clicking it toggles our drawer via a window event. Idempotent.
 *
 * @return {boolean} True if the API exists (registration attempted).
 */
function registerTopBarLauncher() {
	if (!hasAppBarApi()) {
		return false;
	}
	if (topBarRegistered) {
		return true;
	}
	topBarRegistered = true;

	const Icon = () => <ThinkRankLogo size={22} />;

	window.elementorV2.editorAppBar.utilitiesMenu.registerAction({
		id: 'thinkrank-seo',
		priority: 5,
		useProps: () => ({
			title: ACTION_TITLE,
			icon: Icon,
			onClick: () => window.dispatchEvent(new CustomEvent(TOGGLE_EVENT)),
		}),
	});

	return true;
}

/**
 * Extract the post's plain-text content from the Elementor preview iframe.
 *
 * @return {string} Plain text content, or '' when the preview isn't ready.
 */
function getElementorContent() {
	try {
		const previewWin = window.elementor?.$preview?.[0]?.contentWindow;
		const doc = previewWin?.document;
		if (doc) {
			const el = doc.querySelector('.elementor') || doc.body;
			if (el) {
				return (el.innerText || el.textContent || '').trim();
			}
		}
	} catch (e) {
		// Cross-origin / not-ready — fall back to the localized contentPreview.
	}
	return '';
}

/**
 * Minimal save feedback. Prefers Elementor's own toast, falls back to a small
 * transient banner so the user always gets confirmation.
 *
 * @param {string}  message Text to display.
 * @param {boolean} isError Whether this is an error toast.
 * @return {void}
 */
function notify(message, isError = false) {
	try {
		if (window.elementor?.notifications?.showToast) {
			window.elementor.notifications.showToast({ message });
			return;
		}
	} catch (e) {
		// Fall through to the DOM fallback.
	}

	const toast = document.createElement('div');
	toast.textContent = message;
	toast.setAttribute('role', 'status');
	toast.style.cssText = [
		'position:fixed',
		'bottom:20px',
		'left:50%',
		'transform:translateX(-50%)',
		'z-index:100000',
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
 * Persist the metabox fields through admin-ajax (no #post form in Elementor).
 *
 * This saves SEO metadata ONLY. It deliberately does NOT trigger Elementor's
 * own document save: doing so mutated Elementor's editor state (clearing the
 * unsaved-changes flag, flipping the Publish/Update control to a "saved" state)
 * which interfered with the user's ability to publish the page afterwards.
 * Elementor's content stays under Elementor's own Update/Publish buttons; the
 * ThinkRank drawer only persists SEO fields and never touches editor state or
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
 * Elementor host wrapper. Prefers the top-bar logo launcher; if that action
 * doesn't actually render (older Elementor / API change), it falls back to the
 * drawer's own floating launcher so the feature is never unreachable.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.data Localized `thinkrankMetabox` data.
 * @return {JSX.Element} The metabox app.
 */
function ElementorApp({ data }) {
	const [hideLauncher, setHideLauncher] = useState(() =>
		registerTopBarLauncher()
	);

	useEffect(() => {
		if (!hideLauncher) {
			return undefined;
		}
		// Confirm the top-bar action mounted; otherwise reveal the floating one.
		let tries = 0;
		const timer = setInterval(() => {
			tries += 1;
			const exists = document.querySelector(
				`[aria-label="${ACTION_TITLE}"], [title="${ACTION_TITLE}"]`
			);
			if (exists) {
				clearInterval(timer);
			} else if (tries > 12) {
				clearInterval(timer);
				setHideLauncher(false);
			}
		}, 250);
		return () => clearInterval(timer);
	}, [hideLauncher]);

	return (
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
			contentResolver={getElementorContent}
			hideLauncher={hideLauncher}
		/>
	);
}

function mount() {
	if (mounted) {
		return;
	}

	const data = window.thinkrankMetabox || {};

	let container = document.getElementById('thinkrank-elementor-root');
	if (!container) {
		container = document.createElement('div');
		container.id = 'thinkrank-elementor-root';
		container.className = 'thinkrank-metabox';
		document.body.appendChild(container);
	}

	mounted = true;

	const root = createRoot(container);
	root.render(<ElementorApp data={data} />);
}

/**
 * Wait until the Elementor editor is active (so the panel footer launcher slot
 * exists and the `elementor-editor-active` body class is set) before mounting.
 * Falls back to mounting after a timeout so the drawer is never lost.
 *
 * @return {void}
 */
function boot() {
	const isReady = () =>
		typeof window.elementor !== 'undefined' &&
		document.body.classList.contains('elementor-editor-active');

	if (isReady()) {
		mount();
		return;
	}

	let tries = 0;
	const timer = setInterval(() => {
		tries += 1;
		if (isReady() || tries > 60) {
			clearInterval(timer);
			mount();
		}
	}, 250);
}

if (document.readyState !== 'loading') {
	boot();
} else {
	document.addEventListener('DOMContentLoaded', boot);
}
