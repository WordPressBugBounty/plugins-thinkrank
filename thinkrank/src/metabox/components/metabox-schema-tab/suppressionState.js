/**
 * State of the per-post suppression control: its value and whether the stored
 * value has loaded (#821).
 *
 * The stored value arrives with the tab's metadata GET, which is separate from
 * the deployments request that ends the tab's loading screen. While it was
 * pending the box was already live, so a user could change and save it, and the
 * older GET then landed and put the box back to the empty value it read. A
 * failed save in that window also rolled back to that empty value rather than
 * the setting actually stored.
 *
 * So the box is usable only once its own value has loaded. A load answer is
 * applied only while that load is the current one, so a late answer never
 * replaces a value the user has changed since, and a failed load leaves the box
 * unavailable until a retry succeeds.
 *
 * @package
 */

/**
 * Turn the stored meta value into what the control holds.
 *
 * @param {*} stored Raw `_thinkrank_schema_disabled` meta value.
 * @return {string|string[]} 'all', a list of @type names, or ''.
 */
export const parseSchemaSuppression = (stored) => {
	if ('all' === stored) {
		return 'all';
	}

	if (!stored || 'string' !== typeof stored) {
		return '';
	}

	try {
		const parsed = JSON.parse(stored);

		return Array.isArray(parsed) ? parsed : '';
	} catch (e) {
		return '';
	}
};

export const initialSuppressionState = {
	status: 'loading',
	value: '',
	load: 0,
};

/**
 * @param {{status: string, value: (string|string[]), load: number}} state
 * @param {Object}                                                   action
 * @return {{status: string, value: (string|string[]), load: number}} Next state.
 */
export const suppressionReducer = (state, action) => {
	switch (action.type) {
		// A load started; `load` identifies it.
		case 'load':
			return { ...state, status: 'loading', load: action.load };

		case 'loaded':
			if ('loading' !== state.status || action.load !== state.load) {
				return state;
			}
			return {
				...state,
				status: 'ready',
				value: parseSchemaSuppression(action.stored),
			};

		case 'failed':
			if ('loading' !== state.status || action.load !== state.load) {
				return state;
			}
			return { ...state, status: 'error' };

		// The user changed the box.
		case 'change':
			if ('ready' !== state.status) {
				return state;
			}
			return { ...state, value: action.value };

		// The save chain went idle; this is what the server holds.
		case 'settled':
			return { ...state, value: action.value };

		default:
			return state;
	}
};
