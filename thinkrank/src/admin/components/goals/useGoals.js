/**
 * SEO Goals: load the list, mark goals, run an analyzer one-click fix (#728).
 *
 * @package
 * @since 2.22.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import notify from '../../utils/notify';

export const GOALS_PATH = '/thinkrank/v1/goals';

/**
 * Event the Site SEO Audit card fires when the audit it shows changes: its
 * first load, Re-analyze, a fix, an ignore. The audit is one of the things
 * the goals list is built from, so a list on the same page refetches.
 */
export const AUDIT_EVENT = 'thinkrank:audit-changed';

/** The goal that asks for an audit, listed while none exists. */
export const RUN_AUDIT_GOAL = 'analyzer:run-audit';

const messageFor = (error, fallback) => error?.message || fallback;

/**
 * @return {{feed: Object|null, loading: boolean, error: string, busy: string, mark: Function, fix: Function, reload: Function}} State and actions.
 */
const useGoals = () => {
	const [feed, setFeed] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState('');
	// Id of the goal an action is running on, so only its buttons wait.
	const [busy, setBusy] = useState('');
	const mounted = useRef(true);
	// Only the newest load may set state: a reload can overlap the first
	// load, and the older answer landing last would put the old list back.
	const latest = useRef(0);

	useEffect(() => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, []);

	const reload = useCallback((refresh = false) => {
		const request = ++latest.current;
		const isLatest = () => mounted.current && request === latest.current;
		setLoading(true);
		return apiFetch({ path: `${GOALS_PATH}${refresh ? '?refresh=1' : ''}` })
			.then((response) => {
				if (isLatest()) {
					setFeed(response?.data || null);
					setError('');
				}
			})
			.catch((e) => {
				if (isLatest()) {
					setError(
						messageFor(
							e,
							__('Your goals could not be loaded.', 'thinkrank')
						)
					);
				}
			})
			.finally(() => isLatest() && setLoading(false));
	}, []);

	useEffect(() => {
		reload();
	}, [reload]);

	const mark = useCallback(async (id, status) => {
		setBusy(id);
		setError('');
		try {
			const response = await apiFetch({
				path: `${GOALS_PATH}/mark`,
				method: 'POST',
				data: { id, status },
			});
			if (mounted.current) {
				setFeed(response?.data || null);
			}
		} catch (e) {
			if (mounted.current) {
				setError(
					messageFor(
						e,
						__('That goal could not be updated.', 'thinkrank')
					)
				);
			}
		} finally {
			if (mounted.current) {
				setBusy('');
			}
		}
	}, []);

	// The analyzer's own fix; it re-runs the audit, which drops the goals
	// cache on the server, so the rebuilt list shows whether it worked.
	const fix = useCallback(
		async (goal) => {
			setBusy(goal.id);
			setError('');
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/seo-analyzer/fix',
					method: 'POST',
					data: { check_id: goal.action.check_id },
				});
				// The fixer can run and still change nothing (no images to
				// fill, a block it may not touch); say so rather than leave
				// the goal sitting there with no word.
				if (response?.data?.fixed === false) {
					throw new Error(
						response?.message ||
							__('The fix did not apply.', 'thinkrank')
					);
				}
				notify.success(response?.message || __('Fixed.', 'thinkrank'));
				await reload(true);
			} catch (e) {
				if (mounted.current) {
					setError(
						messageFor(e, __('The fix did not apply.', 'thinkrank'))
					);
				}
			} finally {
				if (mounted.current) {
					setBusy('');
				}
			}
		},
		[reload]
	);

	return { feed, loading, error, busy, mark, fix, reload };
};

export default useGoals;
