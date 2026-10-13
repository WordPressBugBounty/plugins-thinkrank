/**
 * ThinkRank editor header launcher (block editor).
 *
 * Registers a Gutenberg plugin sidebar so WordPress renders a MANAGED, pinned
 * launcher icon in the editor header's pinned-items cluster (next to Settings /
 * Yoast) — the supported mechanism, pinned by default, that other plugins can't
 * displace. Activating the icon opens the existing ThinkRank bottom drawer (via
 * the shared `thinkrank:toggle-seo-drawer` event); the sidebar panel itself is
 * suppressed, so the icon behaves as a plain drawer toggle.
 *
 * Why not fill `PinnedItems` directly (the previous approach): on WP 6.6+/7.x
 * `PinnedItems` and `@wordpress/interface` are no longer reachable as public
 * globals, so a third-party `PinnedItems` fill silently never mounts and the UI
 * falls back to the old DOM-injected launcher. `PluginSidebar` is the public,
 * version-stable route into that same cluster.
 *
 * The score is mirrored via the `thinkrank:score-updated` broadcast from
 * MetaboxApp and shown as a badge over the logo.
 *
 * @package
 * @since 1.17.0
 */

import { registerPlugin } from '@wordpress/plugins';
// Forces the `wp-editor` script handle to load (fixing the old load-order gap)
// and gives us PluginSidebar on WP 6.6+. On 6.0–6.5 it lives on wp.editPost —
// see resolvePluginSidebar().
import { PluginSidebar as EditorPluginSidebar } from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ThinkRankLogo from './components/ThinkRankLogo';
import { scoreBadgeStyle } from '@utils/scoreScheme';
import {
	normalizeScore,
	readLatestScore as readInitialScore,
} from './components/LauncherMark';

const PLUGIN_NAME = 'thinkrank-configure-seo';
const SIDEBAR_NAME = 'thinkrank-seo';

// Fully-qualified complementary-area id WordPress assigns to our sidebar.
const AREA = `${PLUGIN_NAME}/${SIDEBAR_NAME}`;

// Complementary-area scope: 'core' on WP 6.6+, 'core/edit-post' on 6.0–6.5.
const SCOPES = ['core', 'core/edit-post'];
const INTERFACE_STORE = 'core/interface';

// PluginSidebar moved from @wordpress/edit-post (wp.editPost) to
// @wordpress/editor (wp.editor) in WP 6.6. Prefer the static import (WP 6.6+);
// fall back to the runtime globals for older releases.
const resolvePluginSidebar = () => {
	const wp = typeof window !== 'undefined' ? window.wp : undefined;
	return (
		EditorPluginSidebar ||
		wp?.editor?.PluginSidebar ||
		wp?.editPost?.PluginSidebar ||
		null
	);
};

/**
 * The pinned launcher: a compact logo + score badge, rendered as the icon of a
 * (suppressed) plugin sidebar. Activating it opens the bottom drawer instead.
 *
 * @param {Object}   props               Component props.
 * @param {Function} props.PluginSidebar Resolved PluginSidebar component.
 * @return {JSX.Element} The plugin sidebar registration.
 */
const ConfigureSeoSidebar = ({ PluginSidebar }) => {
	const [score, setScore] = useState(readInitialScore);

	useEffect(() => {
		// Re-read once after mount in case MetaboxApp broadcast the score before
		// this (separate) React tree attached its listener.
		setScore(readInitialScore());

		const onScore = (event) =>
			setScore(normalizeScore(event.detail?.score));
		window.addEventListener('thinkrank:score-updated', onScore);
		return () =>
			window.removeEventListener('thinkrank:score-updated', onScore);
	}, []);

	// Whether our complementary area is the active one, and in which scope.
	const activeScope = useSelect((select) => {
		const store = select(INTERFACE_STORE);
		if (!store) {
			return null;
		}
		return (
			SCOPES.find(
				(scope) => store.getActiveComplementaryArea(scope) === AREA
			) || null
		);
	}, []);

	const { disableComplementaryArea } = useDispatch(INTERFACE_STORE);

	// When WordPress activates our sidebar (the user clicked the pinned icon),
	// open the bottom drawer instead and immediately relinquish the sidebar so
	// no empty panel lingers. Because we always disable, the next click
	// re-activates → this fires again → the drawer toggles open/closed.
	useEffect(() => {
		if (!activeScope) {
			return;
		}
		window.dispatchEvent(new CustomEvent('thinkrank:toggle-seo-drawer'));
		disableComplementaryArea?.(activeScope);
	}, [activeScope, disableComplementaryArea]);

	// Always show a score badge. Until a post has been analyzed the score is
	// null, so fall back to 0 rather than hiding the badge entirely.
	const displayScore = score ?? 0;

	const icon = (
		<span className="thinkrank-configure-seo__mark">
			<ThinkRankLogo size={24} />
			<span
				className="thinkrank-configure-seo__score"
				style={scoreBadgeStyle(displayScore)}
			>
				{displayScore}
			</span>
		</span>
	);

	return (
		<PluginSidebar
			name={SIDEBAR_NAME}
			title={__('ThinkRank SEO', 'thinkrank')}
			icon={icon}
		>
			{/* Intentionally empty — the pin only toggles the bottom drawer. */}
		</PluginSidebar>
	);
};

let registered = false;

/**
 * Whether the managed pinned launcher can be registered on this install, so the
 * caller can decide between the plugin sidebar and the drawer's own launcher.
 *
 * @return {boolean} True when registerPlugin and a PluginSidebar component exist.
 */
export const canPinHeaderButton = () =>
	typeof registerPlugin === 'function' && !!resolvePluginSidebar();

/**
 * Register the pinned header launcher. Safe to call more than once.
 *
 * @return {void}
 */
export const registerHeaderButton = () => {
	if (registered || typeof registerPlugin !== 'function') {
		return;
	}
	const PluginSidebar = resolvePluginSidebar();
	if (!PluginSidebar) {
		return;
	}
	registered = true;
	registerPlugin(PLUGIN_NAME, {
		render: () => <ConfigureSeoSidebar PluginSidebar={PluginSidebar} />,
	});

	// Safety net: if the pinned icon never renders (an unexpected editor/scope),
	// tell MetaboxApp to fall back to the drawer's own launcher so the button
	// never disappears. The editor header mounts asynchronously and on slow,
	// plugin-heavy sites can take well over 20s, so a short one-shot check
	// misfires and produces a duplicate launcher. Instead, watch until the icon
	// appears (confirmed via `thinkrank:pin-rendered`, which also dismisses a
	// fallback launcher shown by an earlier misfire) and only declare failure
	// after a generous window.
	if (typeof window !== 'undefined') {
		const PIN_FAIL_TIMEOUT = 30000;
		const hasMark = () =>
			!!document.querySelector('.thinkrank-configure-seo__mark');
		const observer = new window.MutationObserver(() => {
			if (hasMark()) {
				observer.disconnect();
				window.clearTimeout(failTimer);
				window.dispatchEvent(new CustomEvent('thinkrank:pin-rendered'));
			}
		});
		const failTimer = window.setTimeout(() => {
			if (!hasMark()) {
				// Keep observing: a later mount still recovers via pin-rendered.
				window.dispatchEvent(new CustomEvent('thinkrank:pin-failed'));
			}
		}, PIN_FAIL_TIMEOUT);
		if (hasMark()) {
			window.clearTimeout(failTimer);
			window.dispatchEvent(new CustomEvent('thinkrank:pin-rendered'));
		} else {
			observer.observe(document.body, {
				childList: true,
				subtree: true,
			});
		}
	}
};
