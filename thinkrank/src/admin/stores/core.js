/**
 * Core Data Store
 *
 * Manages global application state
 *
 * @package
 * @since 1.0.0
 */

import { createReduxStore } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../shared/utils/logger';

// Initial state
const DEFAULT_STATE = {
	isInitialized: false,
	isLoading: false,
	hasError: false,
	errorMessage: '',
	notices: [],
	userCapabilities: {},
	pluginInfo: {},
	systemStatus: {},
};

// Action types
const TYPES = {
	SET_INITIALIZED: 'SET_INITIALIZED',
	SET_LOADING: 'SET_LOADING',
	SET_ERROR: 'SET_ERROR',
	CLEAR_ERROR: 'CLEAR_ERROR',
	ADD_NOTICE: 'ADD_NOTICE',
	REMOVE_NOTICE: 'REMOVE_NOTICE',
	SET_USER_CAPABILITIES: 'SET_USER_CAPABILITIES',
	SET_PLUGIN_INFO: 'SET_PLUGIN_INFO',
	SET_SYSTEM_STATUS: 'SET_SYSTEM_STATUS',
};

// Reducer
const reducer = (state = DEFAULT_STATE, action) => {
	switch (action.type) {
		case TYPES.SET_INITIALIZED:
			return {
				...state,
				isInitialized: action.initialized,
			};

		case TYPES.SET_LOADING:
			return {
				...state,
				isLoading: action.loading,
			};

		case TYPES.SET_ERROR:
			return {
				...state,
				hasError: true,
				errorMessage: action.message,
				isLoading: false,
			};

		case TYPES.CLEAR_ERROR:
			return {
				...state,
				hasError: false,
				errorMessage: '',
			};

		case TYPES.ADD_NOTICE:
			return {
				...state,
				notices: [
					...state.notices,
					{
						id: action.id || Date.now(),
						message: action.message,
						type: action.noticeType || 'info',
						isDismissible: action.isDismissible !== false,
					},
				],
			};

		case TYPES.REMOVE_NOTICE:
			return {
				...state,
				notices: state.notices.filter(
					(notice) => notice.id !== action.id
				),
			};

		case TYPES.SET_USER_CAPABILITIES:
			return {
				...state,
				userCapabilities: action.capabilities,
			};

		case TYPES.SET_PLUGIN_INFO:
			return {
				...state,
				pluginInfo: action.info,
			};

		case TYPES.SET_SYSTEM_STATUS:
			return {
				...state,
				systemStatus: action.status,
			};

		default:
			return state;
	}
};

// Actions
const actions = {
	setInitialized(initialized) {
		return {
			type: TYPES.SET_INITIALIZED,
			initialized,
		};
	},

	setLoading(loading) {
		return {
			type: TYPES.SET_LOADING,
			loading,
		};
	},

	setError(message) {
		return {
			type: TYPES.SET_ERROR,
			message,
		};
	},

	clearError() {
		return {
			type: TYPES.CLEAR_ERROR,
		};
	},

	addNotice(message, noticeType = 'info', isDismissible = true) {
		return {
			type: TYPES.ADD_NOTICE,
			message,
			noticeType,
			isDismissible,
			id: Date.now(),
		};
	},

	removeNotice(id) {
		return {
			type: TYPES.REMOVE_NOTICE,
			id,
		};
	},

	setUserCapabilities(capabilities) {
		return {
			type: TYPES.SET_USER_CAPABILITIES,
			capabilities,
		};
	},

	setPluginInfo(info) {
		return {
			type: TYPES.SET_PLUGIN_INFO,
			info,
		};
	},

	setSystemStatus(status) {
		return {
			type: TYPES.SET_SYSTEM_STATUS,
			status,
		};
	},

	// Async actions
	*initialize() {
		yield actions.setLoading(true);

		try {
			// Set up API fetch with nonce
			if (window.thinkrankAdmin?.restNonce) {
				apiFetch.use(
					apiFetch.createNonceMiddleware(
						window.thinkrankAdmin.restNonce
					)
				);
			}

			// PERFORMANCE FIX: Parallelize API requests to reduce initialization time
			// Create individual API request functions with error handling
			const fetchCapabilities = async () => {
				try {
					return await apiFetch({
						path: '/thinkrank/v1/capabilities',
					});
				} catch (error) {
					logger.error('Failed to fetch capabilities:', error);
					return {
						manage_settings: false,
						view_analytics: false,
						manage_credits: false,
						use_ai_features: false,
					};
				}
			};

			const fetchPluginInfo = async () => {
				try {
					return await apiFetch({
						path: '/thinkrank/v1/plugin-info',
					});
				} catch (error) {
					logger.error('Failed to fetch plugin info:', error);
					return {
						version: '1.0.0',
						name: 'ThinkRank',
						description: 'AI-native SEO plugin for WordPress',
					};
				}
			};

			const fetchSystemStatus = async () => {
				try {
					return await apiFetch({
						path: '/thinkrank/v1/system-status',
					});
				} catch (error) {
					logger.error('Failed to fetch system status:', error);
					return {
						status: 'unknown',
						issues: [],
					};
				}
			};

			// Execute all API calls in parallel
			const [capabilities, pluginInfo, systemStatus] = yield Promise.all([
				fetchCapabilities(),
				fetchPluginInfo(),
				fetchSystemStatus(),
			]);

			yield actions.setUserCapabilities(capabilities);
			yield actions.setPluginInfo(pluginInfo);
			yield actions.setSystemStatus(systemStatus);
			yield actions.setInitialized(true);
		} catch (error) {
			logger.error('ThinkRank initialization error:', error);
			yield actions.setError(
				error.message || 'Failed to initialize ThinkRank'
			);
		} finally {
			yield actions.setLoading(false);
		}
	},

	*checkSystemHealth() {
		try {
			const status = yield apiFetch({
				path: '/thinkrank/v1/system-status',
			});

			yield actions.setSystemStatus(status);

			// Add notices for any issues
			if (status.issues && status.issues.length > 0) {
				for (const issue of status.issues) {
					yield actions.addNotice(issue.message, issue.type);
				}
			}
		} catch (error) {
			yield actions.setError('Failed to check system health');
		}
	},
};

// Selectors
const selectors = {
	getIsInitialized(state) {
		return state.isInitialized;
	},

	getIsLoading(state) {
		return state.isLoading;
	},

	getHasError(state) {
		return state.hasError;
	},

	getErrorMessage(state) {
		return state.errorMessage;
	},

	getNotices(state) {
		return state.notices;
	},

	getUserCapabilities(state) {
		return state.userCapabilities;
	},

	getPluginInfo(state) {
		return state.pluginInfo;
	},

	getSystemStatus(state) {
		return state.systemStatus;
	},

	// Computed selectors
	canManageSettings(state) {
		return state.userCapabilities.manage_settings !== false;
	},

	canViewAnalytics(state) {
		return state.userCapabilities.view_analytics !== false;
	},

	canManageCredits(state) {
		return state.userCapabilities.manage_credits !== false;
	},

	getPluginVersion(state) {
		return state.pluginInfo.version || '1.0.0';
	},

	isSystemHealthy(state) {
		return (
			!state.systemStatus.issues || state.systemStatus.issues.length === 0
		);
	},
};

// Create and export store
const store = createReduxStore('thinkrank/core', {
	reducer,
	actions,
	selectors,
});

export default store;
