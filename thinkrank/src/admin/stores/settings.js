/**
 * Settings Data Store
 *
 * Manages plugin settings and configuration
 *
 * @package
 * @since 1.0.0
 */

import { createReduxStore } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../shared/utils/logger';

// Initial state
const DEFAULT_STATE = {
	settings: {},
	isLoading: false,
	isSaving: false,
	lastSaved: null,
};

// Action types
const TYPES = {
	SET_SETTINGS: 'SET_SETTINGS',
	SET_LOADING: 'SET_LOADING',
	SET_SAVING: 'SET_SAVING',
	UPDATE_SETTING: 'UPDATE_SETTING',
	SET_LAST_SAVED: 'SET_LAST_SAVED',
};

// Reducer
const reducer = (state = DEFAULT_STATE, action) => {
	switch (action.type) {
		case TYPES.SET_SETTINGS:
			return {
				...state,
				settings: action.settings,
			};

		case TYPES.SET_LOADING:
			return {
				...state,
				isLoading: action.loading,
			};

		case TYPES.SET_SAVING:
			return {
				...state,
				isSaving: action.saving,
			};

		case TYPES.UPDATE_SETTING:
			return {
				...state,
				settings: {
					...state.settings,
					[action.key]: action.value,
				},
			};

		case TYPES.SET_LAST_SAVED:
			return {
				...state,
				lastSaved: action.timestamp,
			};

		default:
			return state;
	}
};

// Actions
const actions = {
	setSettings(settings) {
		return {
			type: TYPES.SET_SETTINGS,
			settings,
		};
	},

	setLoading(loading) {
		return {
			type: TYPES.SET_LOADING,
			loading,
		};
	},

	setSaving(saving) {
		return {
			type: TYPES.SET_SAVING,
			saving,
		};
	},

	updateSetting(key, value) {
		return {
			type: TYPES.UPDATE_SETTING,
			key,
			value,
		};
	},

	setLastSaved(timestamp) {
		return {
			type: TYPES.SET_LAST_SAVED,
			timestamp,
		};
	},

	// Async actions
	*loadSettings() {
		yield actions.setLoading(true);

		try {
			const settings = yield apiFetch({
				path: '/thinkrank/v1/settings',
			});

			yield actions.setSettings(settings || {});
		} catch (error) {
			logger.error('Failed to load settings:', error);
			// Set default settings on error
			yield actions.setSettings({
				ai_provider: 'openai',
				openai_api_key: '',
				claude_api_key: '',
				gemini_api_key: '',
				openrouter_api_key: '',
				openai_compatible_base_url: '',
				openai_compatible_model: '',
				max_tokens: 1000,
				temperature: 0.7,
				cache_duration: 3600,
			});
		} finally {
			yield actions.setLoading(false);
		}
	},

	*saveSettings(settingsToSave = null) {
		yield actions.setSaving(true);

		try {
			const currentSettings = yield wp.data
				.select('thinkrank/settings')
				.getSettings();
			const settings = settingsToSave || currentSettings;

			const response = yield apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: settings,
			});

			if (response.success) {
				yield actions.setSettings(response.settings);
				yield actions.setLastSaved(Date.now());
				return response;
			}
			throw new Error(response.message || 'Failed to save settings');
		} catch (error) {
			logger.error('Failed to save settings:', error);
			throw error;
		} finally {
			yield actions.setSaving(false);
		}
	},
};

// Selectors
const selectors = {
	getSettings(state) {
		return state.settings;
	},

	getSetting(state, key, defaultValue = null) {
		return state.settings[key] ?? defaultValue;
	},

	getIsLoading(state) {
		return state.isLoading;
	},

	getIsSaving(state) {
		return state.isSaving;
	},

	getLastSaved(state) {
		return state.lastSaved;
	},

	// Computed selectors
	hasUnsavedChanges(state) {
		// This would need to track original vs current settings
		// For now, return false
		return false;
	},

	getApiKey(state) {
		return state.settings.openai_api_key || '';
	},

	getAiProvider(state) {
		return state.settings.ai_provider || 'openai';
	},

	// Mirrors Settings::has_ai_provider_configured() in PHP: provider-aware,
	// because the OpenAI-compatible endpoint has no key — it is configured by
	// base URL + model id (#721).
	isAiConfigured(state) {
		const provider = state.settings.ai_provider || '';

		if (!provider) return false;

		if (provider === 'openai_compatible') {
			return Boolean(
				state.settings.openai_compatible_base_url &&
					state.settings.openai_compatible_model
			);
		}

		return Boolean(state.settings[`${provider}_api_key`]);
	},
};

// Create and export store
const store = createReduxStore('thinkrank/settings', {
	reducer,
	actions,
	selectors,
});

export default store;
