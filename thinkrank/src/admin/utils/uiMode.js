/**
 * Basic / Advanced mode (#730): how much of the navigation this user sees.
 *
 * The mode arrives localized as `thinkrankAdmin.uiMode` and lives there for
 * the page's lifetime. The switch in the Essential SEO header changes it in
 * place and tells every subscriber, so the sidebar and search follow without
 * a reload. The server stores it per user (`POST /thinkrank/v1/ui-mode`).
 *
 * Basic mode only hides entries from the menu and the search: every screen
 * still opens by its URL.
 *
 * The stored value stays `simple`, in user meta and in the REST enum. The
 * label changed; the preference of every user who already chose did not.
 *
 * @package
 * @since 2.11.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import notify from './notify';

export const SIMPLE = 'simple';
export const ADVANCED = 'advanced';

const EVENT = 'thinkrank:ui-mode';

/**
 * The current mode. Anything but "simple" (the stored name for Basic) is
 * Advanced — the navigation every user had before the switch existed.
 *
 * @return {string} 'simple' or 'advanced'.
 */
export const getUiMode = () =>
	SIMPLE === window.thinkrankAdmin?.uiMode ? SIMPLE : ADVANCED;

/**
 * Whether a navigation entry shows in a mode.
 *
 * @param {Object} entry Entry with an optional `advanced` flag.
 * @param {string} mode  Mode (defaults to the current one).
 * @return {boolean} Visible.
 */
export const isVisibleInMode = (entry, mode = getUiMode()) =>
	!(SIMPLE === mode && entry?.advanced);

/**
 * Set the mode on the page and tell the subscribers.
 *
 * @param {string} mode Mode.
 */
const apply = (mode) => {
	if (window.thinkrankAdmin) {
		window.thinkrankAdmin.uiMode = mode;
	}
	window.dispatchEvent(new CustomEvent(EVENT, { detail: mode }));
};

/**
 * Store a mode for the current user.
 *
 * @param {string} mode Mode.
 * @return {Promise<string>} The stored mode.
 */
export const saveUiMode = (mode) =>
	apiFetch({
		path: '/thinkrank/v1/ui-mode',
		method: 'POST',
		data: { mode },
	}).then((res) => res?.data?.mode || mode);

/**
 * The mode, kept in step with the header switch.
 *
 * `setMode` follows the house autosave pattern: the switch flips at once, the
 * save runs behind it, a failure flips it back and says so, and a success
 * names the new state.
 *
 * @return {{mode: string, setMode: Function, isSaving: boolean}} State.
 */
export const useUiMode = () => {
	const [mode, setLocal] = useState(getUiMode);
	const [isSaving, setIsSaving] = useState(false);

	useEffect(() => {
		const onChange = (event) => setLocal(event.detail);
		window.addEventListener(EVENT, onChange);

		return () => window.removeEventListener(EVENT, onChange);
	}, []);

	const setMode = useCallback((next) => {
		const previous = getUiMode();
		if (next === previous) {
			return Promise.resolve(previous);
		}

		apply(next);
		setIsSaving(true);

		return saveUiMode(next)
			.then((stored) => {
				apply(stored);
				notify.success(
					SIMPLE === stored
						? __(
								'Basic mode: advanced sections are hidden from the menu.',
								'thinkrank'
							)
						: __(
								'Advanced mode: every section is in the menu.',
								'thinkrank'
							)
				);

				return stored;
			})
			.catch((error) => {
				apply(previous);
				notify.error(
					error?.message ||
						__('Couldn’t save the menu mode.', 'thinkrank')
				);

				return previous;
			})
			.finally(() => setIsSaving(false));
	}, []);

	return { mode, setMode, isSaving };
};
