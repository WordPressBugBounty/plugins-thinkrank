/**
 * Email Report Data Store
 *
 * Talks to /thinkrank/v1/email-report/{config,test-send}.
 *
 * Shape mirrors the PHP Email_Report_Config payload. ThinkRank Pro dispatches
 * load() after saving its own report settings so this store picks up the new
 * schedule and recipients.
 *
 * @package
 * @since 1.9.0
 */

import { createReduxStore } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const DEFAULT_STATE = {
	config: null, // null while loading; merged from server
	sections: [], // [{key,label,default_enabled}]
	nextRun: null, // ISO datetime string
	readiness: null, // { ready, search_console, analytics, ai_traffic, reason }
	isLoading: false,
	isSaving: false,
	isSendingTest: false,
	lastSaved: null,
	lastTestResult: null, // { success, recipients, subject, error? }
};

const TYPES = {
	SET_PAYLOAD: 'SET_PAYLOAD',
	UPDATE_FIELD: 'UPDATE_FIELD',
	SET_LOADING: 'SET_LOADING',
	SET_SAVING: 'SET_SAVING',
	SET_SENDING_TEST: 'SET_SENDING_TEST',
	SET_LAST_SAVED: 'SET_LAST_SAVED',
	SET_LAST_TEST_RESULT: 'SET_LAST_TEST_RESULT',
};

const reducer = (state = DEFAULT_STATE, action) => {
	switch (action.type) {
		case TYPES.SET_PAYLOAD:
			return {
				...state,
				config: action.payload.config ?? state.config,
				sections: action.payload.sections ?? state.sections,
				nextRun:
					action.payload.next_run ??
					action.payload.nextRun ??
					state.nextRun,
				readiness: action.payload.readiness ?? state.readiness,
			};

		case TYPES.UPDATE_FIELD:
			return {
				...state,
				config: { ...(state.config || {}), [action.key]: action.value },
			};

		case TYPES.SET_LOADING:
			return { ...state, isLoading: action.value };

		case TYPES.SET_SAVING:
			return { ...state, isSaving: action.value };

		case TYPES.SET_SENDING_TEST:
			return { ...state, isSendingTest: action.value };

		case TYPES.SET_LAST_SAVED:
			return { ...state, lastSaved: action.timestamp };

		case TYPES.SET_LAST_TEST_RESULT:
			return { ...state, lastTestResult: action.result };

		default:
			return state;
	}
};

const actions = {
	setPayload(payload) {
		return { type: TYPES.SET_PAYLOAD, payload };
	},
	updateField(key, value) {
		return { type: TYPES.UPDATE_FIELD, key, value };
	},
	setLoading(value) {
		return { type: TYPES.SET_LOADING, value };
	},
	setSaving(value) {
		return { type: TYPES.SET_SAVING, value };
	},
	setSendingTest(value) {
		return { type: TYPES.SET_SENDING_TEST, value };
	},
	setLastSaved(timestamp) {
		return { type: TYPES.SET_LAST_SAVED, timestamp };
	},
	setLastTestResult(result) {
		return { type: TYPES.SET_LAST_TEST_RESULT, result };
	},

	load() {
		return async ({ dispatch }) => {
			dispatch(actions.setLoading(true));
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/email-report/config',
				});
				dispatch(actions.setPayload(response || {}));
				return response;
			} catch (error) {
				throw error;
			} finally {
				dispatch(actions.setLoading(false));
			}
		};
	},

	save(overrides = {}) {
		return async ({ dispatch }) => {
			dispatch(actions.setSaving(true));
			try {
				// Strip undefined entries so we never POST `key: undefined`.
				const data = Object.fromEntries(
					Object.entries(overrides || {}).filter(
						([, v]) => v !== undefined
					)
				);
				const response = await apiFetch({
					path: '/thinkrank/v1/email-report/config',
					method: 'POST',
					data,
				});
				if (!response || response.success === false) {
					throw new Error(
						response?.message ||
							__(
								'Failed to save email report settings.',
								'thinkrank'
							)
					);
				}
				dispatch(actions.setPayload(response));
				dispatch(actions.setLastSaved(Date.now()));
				return response;
			} finally {
				dispatch(actions.setSaving(false));
			}
		};
	},

	sendTest() {
		return async ({ dispatch }) => {
			dispatch(actions.setSendingTest(true));
			dispatch(actions.setLastTestResult(null));
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/email-report/test-send',
					method: 'POST',
				});
				dispatch(
					actions.setLastTestResult({
						...(response?.result || { success: false }),
						not_connected: Boolean(response?.not_connected),
					})
				);
				return response;
			} catch (error) {
				dispatch(
					actions.setLastTestResult({
						success: false,
						error: error.message,
					})
				);
				throw error;
			} finally {
				dispatch(actions.setSendingTest(false));
			}
		};
	},
};

const selectors = {
	getConfig(state) {
		return state.config || {};
	},
	/**
	 * Whether the config request has come back yet.
	 *
	 * Components can't infer this from getConfig() — it falls back to an
	 * object literal, which is always truthy. Read the raw
	 * `config` slot, which stays null until the first response lands.
	 * @param state
	 */
	hasLoaded(state) {
		return state.config !== null;
	},
	getField(state, key, fallback = '') {
		return state.config?.[key] ?? fallback;
	},
	getSections(state) {
		return state.sections || [];
	},
	getNextRun(state) {
		return state.nextRun;
	},
	/**
	 * Whether the report has its data sources. Null until the config
	 * request lands; `search_console` is the one that gates sending.
	 * @param state
	 */
	getReadiness(state) {
		return state.readiness;
	},
	isLoading(state) {
		return state.isLoading;
	},
	isSaving(state) {
		return state.isSaving;
	},
	isSendingTest(state) {
		return state.isSendingTest;
	},
	getLastSaved(state) {
		return state.lastSaved;
	},
	getLastTestResult(state) {
		return state.lastTestResult;
	},
};

const store = createReduxStore('thinkrank/email-report', {
	reducer,
	actions,
	selectors,
});

export default store;
