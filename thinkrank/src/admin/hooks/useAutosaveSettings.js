/**
 * useAutosaveSettings — the house autosave behaviour for settings panels.
 *
 * House rule: flipping a feature switch IS the save. A toggle that needs a
 * separate "Save Changes" click reads as broken — the switch already looks
 * committed — so every enable/disable persists immediately, optimistically,
 * and rolls back with an error toast if the request fails.
 *
 * The hook owns only the save mechanics; the component keeps owning its
 * settings state, so adopting it is a two-line change:
 *
 *   const { update, isSaving } = useAutosaveSettings( {
 *       settings,
 *       setSettings,
 *       save: ( next ) => apiFetch( { path, method: 'POST', data: next } ),
 *   } );
 *
 *   <Toggle checked={ settings.enabled } disabled={ isSaving }
 *       onChange={ ( v ) => update( { enabled: v }, v ? __( 'X is on.' ) : … ) } />
 *
 * `save` may return the server's confirmed settings object; when it does, that
 * value replaces local state so a server-side sanitizer (dropping an invalid
 * post type, say) is reflected instead of silently diverging. That is the whole
 * contract: `save` must resolve with the *complete* settings object or with
 * nothing. Resolving with a partial or unrelated object replaces the panel's
 * entire state with it.
 *
 * @package
 * @since 1.32.0
 */

import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import notify from '../utils/notify';
import logger from '../../shared/utils/logger';

/**
 * @param {Object}   options             Options.
 * @param {Object}   options.settings    Current settings object.
 * @param {Function} options.setSettings State setter for the settings object.
 * @param {Function} options.save        Async (next) => complete settings object,
 *                                       or nothing. Throws on failure.
 * @param {string}   [options.errorText] Message shown when a save fails.
 * @param {string}   [options.logLabel]  Prefix used for console diagnostics.
 * @param {Function} [options.onSuccess] Override the success toast (message) => void.
 * @param {Function} [options.onError]   Override the error toast (message, error) => void.
 * @return {{update: Function, isSaving: boolean}} Autosave helpers.
 */
export default function useAutosaveSettings({
	settings,
	setSettings,
	save,
	errorText,
	logLabel = 'ThinkRank',
	onSuccess,
	onError,
}) {
	// A counter, not a boolean: two toggles flipped in quick succession means
	// two saves in flight, and the panel must stay busy until both land.
	const [inFlight, setInFlight] = useState(0);

	// Last value the server confirmed — the rollback target. Kept in a ref so
	// a failed save restores what was actually persisted rather than whatever
	// optimistic value happened to be on screen.
	const confirmedRef = useRef(settings);

	// Latest optimistic value, including patches still in flight. Each patch
	// merges onto THIS, not onto confirmedRef: the save posts the whole
	// settings object, so building a second patch from the last *confirmed*
	// value omitted the first toggle's key — and omission is an overwrite. The
	// first toggle flipped back on screen and the stale value was persisted
	// (#391).
	const pendingRef = useRef(settings);

	// Track loads and any change we did not initiate (a parent refetch), so
	// neither target goes stale.
	useEffect(() => {
		if (inFlight === 0) {
			confirmedRef.current = settings;
			pendingRef.current = settings;
		}
	}, [settings, inFlight]);

	// Saves are serialised. Concurrent POSTs of the same settings object can
	// land in either order, so the last write wins by arrival rather than by
	// intent.
	const queueRef = useRef(Promise.resolve());

	const update = useCallback(
		(patchOrFn, successMessage) => {
			// A patch may be a function of the latest optimistic value, the way
			// setState takes one. A control whose new value is derived from the
			// old one — a chip list, a counter — cannot pass a plain object:
			// its value is computed at render time, so two clicks in quick
			// succession both derive from the pre-first-click list and the
			// second overwrites the first. Resolved once, here, against the
			// newest intent.
			const patch =
				typeof patchOrFn === 'function'
					? patchOrFn(pendingRef.current)
					: patchOrFn;

			// Optimistic, immediately: the switch must look committed the
			// instant it is clicked.
			const optimistic = { ...pendingRef.current, ...patch };
			pendingRef.current = optimistic;

			setSettings(optimistic);
			setInFlight((count) => count + 1);

			const run = async () => {
				// The payload is built HERE, not at enqueue time. Saves are
				// serialised, so by the time this one starts an earlier one may
				// have failed and reverted its keys — a payload captured at
				// enqueue would still carry those reverted values and persist
				// them, resurrecting a change the user watched fail.
				const next = { ...pendingRef.current, ...patch };
				pendingRef.current = next;

				// Likewise read now: the values these specific keys are known to
				// hold on the server, and therefore what to revert them to if
				// this save fails.
				const confirmedAtStart = confirmedRef.current;

				try {
					const saved = await save(next);
					const confirmed =
						saved && typeof saved === 'object' ? saved : next;

					confirmedRef.current = confirmed;
					pendingRef.current = confirmed;
					setSettings(confirmed);

					if (successMessage) {
						(onSuccess || notify.success)(successMessage);
					}

					return confirmed;
				} catch (error) {
					logger.error(`${logLabel}: autosave failed`, error);

					// Revert only the keys this patch touched. Restoring the
					// whole pre-patch object would throw away a concurrent
					// toggle that succeeded — the same class of loss this fix
					// exists to stop.
					const reverted = { ...pendingRef.current };
					Object.keys(patch).forEach((key) => {
						reverted[key] = confirmedAtStart[key];
					});

					pendingRef.current = reverted;
					confirmedRef.current = reverted;
					setSettings(reverted);

					const message =
						errorText ||
						error?.message ||
						__('Couldn’t save that change.', 'thinkrank');

					if (onError) {
						onError(message, error);
					} else {
						notify.error(message);
					}
					return null;
				} finally {
					setInFlight((count) => Math.max(0, count - 1));
				}
			};

			// Chain onto the queue, and keep the queue alive whatever happens:
			// run() already swallows failures, but a rejection here would wedge
			// every later save.
			const result = queueRef.current.then(run, run);
			queueRef.current = result.catch(() => {});

			return result;
		},
		[save, setSettings, errorText, logLabel, onSuccess, onError]
	);

	return { update, isSaving: inFlight > 0 };
}
