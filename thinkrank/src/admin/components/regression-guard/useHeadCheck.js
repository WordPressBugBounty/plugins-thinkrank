/**
 * Regression Guard: load a page's last check and run a new one (#720).
 *
 * @package
 * @since 2.23.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export const REGRESSION_GUARD_PATH = '/thinkrank/v1/regression-guard';

/**
 * The message to show for a failed request. A refusal the server explains —
 * a draft, a post that does not exist — carries its own words.
 *
 * @param {Object} error apiFetch rejection.
 * @return {string} Message.
 */
const messageFor = (error) =>
	error?.message ||
	__('The check could not be loaded. Try again.', 'thinkrank');

/**
 * @param {number}  postId       Post id; 0 is the homepage.
 * @param {Object}  options      Options.
 * @param {boolean} options.load Whether to load the stored check on mount.
 * @return {{view: Object|null, loading: boolean, checking: boolean, error: string, check: Function}} State and the check action.
 */
const useHeadCheck = (postId, { load = true } = {}) => {
	const [view, setView] = useState(null);
	const [loading, setLoading] = useState(load);
	const [checking, setChecking] = useState(false);
	const [error, setError] = useState('');
	const mounted = useRef(true);
	// Every load and check takes the next number; only the newest may write
	// `view` or `error`. A slow initial GET that lands after a check has
	// finished would otherwise put the older result back on screen.
	const latest = useRef(0);
	const isLatest = (request) => mounted.current && request === latest.current;

	useEffect(() => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, []);

	useEffect(() => {
		if (!load) {
			return;
		}
		const request = ++latest.current;
		let current = true;
		setLoading(true);
		apiFetch({ path: `${REGRESSION_GUARD_PATH}?post_id=${postId}` })
			.then((response) => {
				if (isLatest(request)) {
					setView(response?.data || null);
					setError('');
				}
			})
			.catch((e) => isLatest(request) && setError(messageFor(e)))
			// A load for another post owns `loading` once it has started.
			.finally(() => current && mounted.current && setLoading(false));
		return () => {
			current = false;
		};
	}, [postId, load]);

	const check = useCallback(async () => {
		const request = ++latest.current;
		setChecking(true);
		setError('');
		try {
			const response = await apiFetch({
				path: `${REGRESSION_GUARD_PATH}/check`,
				method: 'POST',
				data: { post_id: postId },
			});
			if (isLatest(request)) {
				setView(response?.data || null);
			}
			return response?.data || null;
		} catch (e) {
			if (isLatest(request)) {
				setError(messageFor(e));
			}
			return null;
		} finally {
			if (mounted.current) {
				setChecking(false);
			}
		}
	}, [postId]);

	return { view, loading, checking, error, check };
};

export default useHeadCheck;
