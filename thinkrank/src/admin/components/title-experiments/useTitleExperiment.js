/**
 * Title Experiments: one page's experiment — load, start, stop, apply,
 * and AI suggestions (#719).
 *
 * @package
 * @since 2.22.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export const EXPERIMENTS_PATH = '/thinkrank/v1/title-experiments';

const messageFor = (error, fallback) => error?.message || fallback;

/**
 * @param {number} postId Post id.
 * @return {Object} State and actions.
 */
const useTitleExperiment = (postId) => {
	const [state, setState] = useState(null);
	const [loading, setLoading] = useState(true);
	const [busy, setBusy] = useState('');
	const [error, setError] = useState('');
	const [attempt, setAttempt] = useState(0);
	const mounted = useRef(true);

	useEffect(() => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, []);

	useEffect(() => {
		if (!postId) {
			setLoading(false);
			return;
		}
		let current = true;
		setLoading(true);
		setError('');
		apiFetch({ path: `${EXPERIMENTS_PATH}/${postId}` })
			.then((res) => current && setState(res?.data || null))
			.catch(
				(e) =>
					current &&
					setError(
						messageFor(
							e,
							__(
								'The experiment could not be loaded.',
								'thinkrank'
							)
						)
					)
			)
			.finally(() => current && setLoading(false));
		return () => {
			current = false;
		};
	}, [postId, attempt]);

	// Load the page's state again, after a failed first load.
	const reload = useCallback(() => setAttempt((n) => n + 1), []);

	// Every action answers with the page's full state; the screen redraws
	// from that rather than guessing.
	const run = useCallback(async (name, path, data = undefined) => {
		setBusy(name);
		setError('');
		try {
			const res = await apiFetch({ path, method: 'POST', data });
			if (mounted.current && 'suggest' !== name) {
				setState(res?.data || null);
			}
			return res?.data || null;
		} catch (e) {
			if (mounted.current) {
				setError(
					messageFor(
						e,
						__('That did not work. Try again.', 'thinkrank')
					)
				);
			}
			return null;
		} finally {
			if (mounted.current) {
				setBusy('');
			}
		}
	}, []);

	const start = useCallback(
		(variant) => run('start', `${EXPERIMENTS_PATH}/${postId}`, variant),
		[run, postId]
	);
	const stop = useCallback(
		() => run('stop', `${EXPERIMENTS_PATH}/${postId}/stop`),
		[run, postId]
	);
	const apply = useCallback(
		() => run('apply', `${EXPERIMENTS_PATH}/${postId}/apply`),
		[run, postId]
	);
	const suggest = useCallback(
		() => run('suggest', `${EXPERIMENTS_PATH}/${postId}/suggest`),
		[run, postId]
	);

	return {
		state,
		loading,
		busy,
		error,
		start,
		stop,
		apply,
		suggest,
		reload,
	};
};

export default useTitleExperiment;
