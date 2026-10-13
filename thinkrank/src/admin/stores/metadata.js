/**
 * Metadata Data Store
 *
 * Manages AI-generated metadata for posts and pages
 *
 * @package
 * @since 1.0.0
 */

import { createReduxStore } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../shared/utils/logger';

// Initial state
const DEFAULT_STATE = {
	metadata: {},
	isGenerating: {},
	generationHistory: {},
	isLoading: false,
};

// Action types
const TYPES = {
	SET_METADATA: 'SET_METADATA',
	SET_GENERATING: 'SET_GENERATING',
	SET_LOADING: 'SET_LOADING',
	ADD_TO_HISTORY: 'ADD_TO_HISTORY',
	CLEAR_METADATA: 'CLEAR_METADATA',
};

// Reducer
const reducer = (state = DEFAULT_STATE, action) => {
	switch (action.type) {
		case TYPES.SET_METADATA:
			return {
				...state,
				metadata: {
					...state.metadata,
					[action.postId]: action.metadata,
				},
			};

		case TYPES.SET_GENERATING:
			return {
				...state,
				isGenerating: {
					...state.isGenerating,
					[action.postId]: action.generating,
				},
			};

		case TYPES.SET_LOADING:
			return {
				...state,
				isLoading: action.loading,
			};

		case TYPES.ADD_TO_HISTORY:
			return {
				...state,
				generationHistory: {
					...state.generationHistory,
					[action.postId]: [
						...(state.generationHistory[action.postId] || []),
						action.entry,
					],
				},
			};

		case TYPES.CLEAR_METADATA:
			return {
				...state,
				metadata: {
					...state.metadata,
					[action.postId]: null,
				},
			};

		default:
			return state;
	}
};

// Actions
const actions = {
	setMetadata(postId, metadata) {
		return {
			type: TYPES.SET_METADATA,
			postId,
			metadata,
		};
	},

	setGenerating(postId, generating) {
		return {
			type: TYPES.SET_GENERATING,
			postId,
			generating,
		};
	},

	setLoading(loading) {
		return {
			type: TYPES.SET_LOADING,
			loading,
		};
	},

	addToHistory(postId, entry) {
		return {
			type: TYPES.ADD_TO_HISTORY,
			postId,
			entry,
		};
	},

	clearMetadata(postId) {
		return {
			type: TYPES.CLEAR_METADATA,
			postId,
		};
	},

	// Async actions (placeholders for Sprint 2)
	*generateMetadata(postId, options = {}) {
		yield actions.setGenerating(postId, true);

		try {
			// This will be implemented in Sprint 2
			const metadata = {
				title: 'AI Generated Title',
				description: 'AI Generated Description',
				generated_at: new Date().toISOString(),
			};

			yield actions.setMetadata(postId, metadata);
			yield actions.addToHistory(postId, {
				timestamp: Date.now(),
				action: 'generated',
				metadata,
			});

			return metadata;
		} catch (error) {
			logger.error('Failed to generate metadata:', error);
			throw error;
		} finally {
			yield actions.setGenerating(postId, false);
		}
	},

	*loadMetadata(postId) {
		yield actions.setLoading(true);

		try {
			// This will be implemented in Sprint 2
			const metadata = yield apiFetch({
				path: `/thinkrank/v1/metadata/${postId}`,
			});

			yield actions.setMetadata(postId, metadata);
			return metadata;
		} catch (error) {
			logger.error('Failed to load metadata:', error);
		} finally {
			yield actions.setLoading(false);
		}
	},
};

// Selectors
const selectors = {
	getMetadata(state, postId) {
		return state.metadata[postId] || null;
	},

	getIsGenerating(state, postId) {
		return state.isGenerating[postId] || false;
	},

	getIsLoading(state) {
		return state.isLoading;
	},

	getGenerationHistory(state, postId) {
		return state.generationHistory[postId] || [];
	},

	// Computed selectors
	hasMetadata(state, postId) {
		return !!state.metadata[postId];
	},

	getMetadataTitle(state, postId) {
		const metadata = state.metadata[postId];
		return metadata?.title || '';
	},

	getMetadataDescription(state, postId) {
		const metadata = state.metadata[postId];
		return metadata?.description || '';
	},
};

// Create and export store
const store = createReduxStore('thinkrank/metadata', {
	reducer,
	actions,
	selectors,
});

export default store;
