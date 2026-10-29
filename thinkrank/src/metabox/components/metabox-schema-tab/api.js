/**
 * MetaboxSchemaTab API Functions
 *
 * All API interaction and content extraction functions for the Metabox Schema Tab.
 * Each function accepts a `ctx` context object with relevant state/setters.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	calculateWordCount,
	resolveEditorContent,
} from '../../../admin/components/seo/utils/contentAnalysis';

/**
 * Extract content from WordPress editor (same method as SEO Analysis tab)
 */
export const getPostContent = () => {
	let content = '';
	let htmlContent = '';

	// Try to get content from Block Editor first
	if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
		try {
			const blockContent = wp.data
				.select('core/editor')
				.getEditedPostContent();
			if (blockContent) {
				htmlContent = blockContent;
			}
		} catch (e) {
			// Block editor not available, try classic editor
		}
	}

	// Fallback to TinyMCE (Classic Editor)
	if (!htmlContent && typeof tinymce !== 'undefined') {
		const editor = tinymce.get('content');
		if (editor && !editor.isHidden()) {
			htmlContent = editor.getContent();
		}
	}

	// Fallback to textarea
	if (!htmlContent) {
		const contentTextarea = document.getElementById('content');
		if (contentTextarea) {
			htmlContent = contentTextarea.value || '';
		}
	}

	// Same resolver the Analysis panel uses: builder markup keeps its words in
	// block-attribute JSON inside an HTML comment, so reading textContent off
	// it sent schema generation an empty body and a word_count of 0. The
	// attribute path runs only when the markup has no text of its own, so a
	// FAQ block on an ordinary post does not replace the article.
	if (htmlContent) {
		content = resolveEditorContent(htmlContent).text;
	}

	return { content: content.trim(), htmlContent };
};

/**
 * Get schema content data with priority logic
 * Priority: SEO Metadata form fields (AI-optimized) → Post data
 * @param ctx
 */
export const getSchemaContentData = (ctx) => {
	const postPermalink =
		window.thinkrankMetabox?.postPermalink ||
		window.location.origin + '/?p=' + ctx.postId;
	const { content } = getPostContent();
	const wordCount = calculateWordCount(content);

	return {
		title: ctx.formData?.thinkrank_seo_title || ctx.postTitle || '',
		description: ctx.formData?.thinkrank_meta_description || '',
		content: content || ctx.contentPreview || '',
		word_count: wordCount,
		focus_keyword: ctx.formData?.thinkrank_focus_keyword || '',
		post_type: 'post',
		post_url: postPermalink,
	};
};

/**
 * Load deployed schemas from database
 * @param ctx
 * @param isInitialLoad
 */
export const loadDeployedSchemas = async (ctx, isInitialLoad = false) => {
	if (!ctx.postId) return;

	try {
		if (isInitialLoad) {
			ctx.setIsLoading(true);
		} else {
			ctx.setIsRefreshing(true);
		}

		const response = await apiFetch({
			path: `/thinkrank/v1/schema/deployed?context_type=${['site', 'post', 'page', 'product'].includes(ctx.currentPostType) ? ctx.currentPostType : 'post'}&context_id=${ctx.postId}`,
		});

		if (response.success) {
			ctx.setDeployedSchemas(response.data);
		}
	} catch (error) {
		ctx.showNotice?.(
			__('Failed to load deployed schemas', 'thinkrank'),
			'error'
		);
	} finally {
		if (isInitialLoad) {
			ctx.setIsLoading(false);
		} else {
			ctx.setIsRefreshing(false);
		}
	}
};

/**
 * Load saved schema form data from post meta
 * @param ctx
 */
export const loadSchemaFormData = async (ctx) => {
	if (!ctx.postId) return;

	try {
		const apiPath = `/wp/v2/${ctx.postRestBase}/${ctx.postId}?context=edit`;

		const response = await apiFetch({
			path: apiPath,
			method: 'GET',
		});

		if (response && response.meta) {
			const savedSchemaType =
				response.meta._thinkrank_selected_schema_type;
			// Only a type this post type's select offers. Any other (an
			// Article saved on a product) would leave the select showing its
			// first option while Generate sent the saved one (#1011).
			if (savedSchemaType) {
				if (
					ctx.schemaTypes.some(
						(type) => type.value === savedSchemaType
					)
				) {
					ctx.setSelectedSchemaType(savedSchemaType);
				}
			}

			const savedFormData = response.meta._thinkrank_schema_form_data;
			if (savedFormData) {
				try {
					const parsedData = JSON.parse(savedFormData);
					ctx.setSchemaFormData(parsedData);
				} catch (e) {
					ctx.setSchemaFormData({});
				}
			} else {
				ctx.setSchemaFormData({});
			}

			const savedAdditionalSchemas =
				response.meta._thinkrank_additional_schemas;
			if (savedAdditionalSchemas) {
				try {
					const parsedSchemas = JSON.parse(savedAdditionalSchemas);
					if (Array.isArray(parsedSchemas)) {
						ctx.setSavedSchemas(parsedSchemas);
					}
				} catch (e) {
					ctx.setSavedSchemas([]);
				}
			} else {
				ctx.setSavedSchemas([]);
			}
		}
	} catch (error) {
		// Failed to load schema form data - handled silently
	}
};

/**
 * Save schema form data to post meta
 * @param ctx
 * @param formData
 * @param schemaType
 */
export const saveSchemaFormData = async (ctx, formData, schemaType) => {
	if (!ctx.postId) return;

	try {
		const apiPath = `/wp/v2/${ctx.postRestBase}/${ctx.postId}`;

		await apiFetch({
			path: apiPath,
			method: 'POST',
			data: {
				meta: {
					_thinkrank_schema_form_data: JSON.stringify(formData),
					_thinkrank_selected_schema_type: schemaType,
				},
			},
		});
	} catch (error) {
		// Schema form data save failed - handled silently
	}
};

/**
 * Save schema form data on page unload.
 *
 * `beforeunload` is the one place the normal save can't be used: the browser
 * tears the document down without waiting for in-flight XHR, so an ordinary
 * `apiFetch` started there is routinely killed mid-request and the edit is lost.
 * `keepalive` is the supported way to let a request outlive the page.
 *
 * Best-effort by nature — the debounced save during editing is what actually
 * keeps the data safe; this only catches the last few seconds of edits.
 *
 * @param {Object} ctx        API context (postId, postRestBase).
 * @param {Object} formData   Schema form data to persist.
 * @param {string} schemaType Selected schema type.
 * @return {boolean} Whether a request was dispatched.
 */
export const saveSchemaFormDataOnUnload = (ctx, formData, schemaType) => {
	if (!ctx.postId) return false;

	const config = window.thinkrankMetabox || {};
	// `restUrl` is rest_url('thinkrank/v1/'); strip our namespace for the root.
	const root = String(config.restUrl || '').replace(/thinkrank\/v1\/?$/, '');
	if (!root) return false;

	const body = JSON.stringify({
		meta: {
			_thinkrank_schema_form_data: JSON.stringify(formData),
			_thinkrank_selected_schema_type: schemaType,
		},
	});

	// keepalive caps the body at 64KB. Oversized payloads would be rejected
	// outright, and the debounced save has already handled them anyway.
	if (body.length > 60000) return false;

	try {
		fetch(`${root}wp/v2/${ctx.postRestBase}/${ctx.postId}`, {
			method: 'POST',
			keepalive: true,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.restNonce || '',
			},
			body,
		}).catch(() => {});
		return true;
	} catch (error) {
		return false;
	}
};

/**
 * Save additional schemas data
 * @param ctx
 * @param schemas
 */
export const saveAdditionalSchemas = async (ctx, schemas) => {
	if (!ctx.postId) return;

	try {
		const apiPath = `/wp/v2/${ctx.postRestBase}/${ctx.postId}`;

		await apiFetch({
			path: apiPath,
			method: 'POST',
			data: {
				meta: {
					_thinkrank_additional_schemas: JSON.stringify(schemas),
				},
			},
		});
	} catch (error) {
		ctx.showNotice?.(
			__('Failed to save schema list.', 'thinkrank'),
			'error'
		);
	}
};

/**
 * Generate schema markup
 * @param ctx
 */
export const generateSchema = async (ctx) => {
	if (!ctx.postId) {
		ctx.showNotice?.(
			__('Post ID is required for schema generation', 'thinkrank'),
			'error'
		);
		return;
	}

	ctx.setIsGenerating(true);

	try {
		const requestData = {
			context_type: ['site', 'post', 'page', 'product'].includes(
				ctx.currentPostType
			)
				? ctx.currentPostType
				: 'post',
			context_id: ctx.postId,
			schema_types: [ctx.selectedSchemaType],
			content_data: getSchemaContentData(ctx),
			schema_form_data: ctx.schemaFormData,
			options: {
				validation_level: 'moderate',
				rich_snippets_optimization: true,
			},
		};

		// Save form data before generating schema
		await saveSchemaFormData(
			ctx,
			ctx.schemaFormData,
			ctx.selectedSchemaType
		);

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/generate',
			method: 'POST',
			data: requestData,
		});

		if (response.success) {
			ctx.setGeneratedSchemas(response.data);
			ctx.showNotice?.(
				__('Schema markup generated successfully!', 'thinkrank'),
				'success'
			);
		} else {
			ctx.showNotice?.(
				response.message || __('Schema generation failed', 'thinkrank'),
				'error'
			);
		}
	} catch (error) {
		ctx.showNotice?.(
			__('Schema generation failed. Please try again.', 'thinkrank'),
			'error'
		);
	} finally {
		ctx.setIsGenerating(false);
	}
};

/**
 * Deploy schema markup
 * @param ctx
 */
export const deploySchema = async (ctx) => {
	if (!ctx.postId) {
		ctx.showNotice?.(
			__('Post ID is required for schema deployment', 'thinkrank'),
			'error'
		);
		return;
	}

	let schemasToDeploy;

	if (ctx.generatedSchemas?.generated_schemas) {
		schemasToDeploy = ctx.generatedSchemas.generated_schemas;
	} else if (ctx.deployedSchemas) {
		schemasToDeploy = ctx.deployedSchemas;
	}

	// Normalize schema data structure
	if (schemasToDeploy) {
		const normalizedSchemas = {};
		Object.entries(schemasToDeploy).forEach(([schemaType, schemaData]) => {
			if (
				schemaData &&
				typeof schemaData === 'object' &&
				schemaData.data &&
				schemaData.method &&
				schemaData.type
			) {
				normalizedSchemas[schemaType] = schemaData.data;
			} else {
				normalizedSchemas[schemaType] = schemaData;
			}
		});
		schemasToDeploy = normalizedSchemas;
	} else {
		schemasToDeploy = {};
	}

	// Merge active saved schemas
	if (ctx.savedSchemas && ctx.savedSchemas.length > 0) {
		ctx.savedSchemas.forEach((schema, index) => {
			// `@type` may be an array — "@type": ["Product","Offer"] is valid
			// JSON-LD and common in imported markup. Using it as an object key
			// directly produced "Product,Offer", which no validator accepts
			// (#468). Key by the first type; the full array stays in the payload.
			const primaryType = Array.isArray(schema['@type'])
				? schema['@type'][0]
				: schema['@type'];

			if (schema._isActive && primaryType) {
				const cleanSchema = { ...schema };
				delete cleanSchema._isActive;

				let schemaKey = primaryType;
				if (schemasToDeploy[schemaKey]) {
					schemaKey = `${primaryType}-${index}`;
				}
				schemasToDeploy[schemaKey] = cleanSchema;
			}
		});
	}

	if (!schemasToDeploy || Object.keys(schemasToDeploy).length === 0) {
		ctx.showNotice?.(
			__(
				'No schema markup to deploy. Please generate schema first.',
				'thinkrank'
			),
			'error'
		);
		return;
	}

	ctx.setIsDeploying(true);

	try {
		const requestData = {
			context_type: ['site', 'post', 'page', 'product'].includes(
				ctx.currentPostType
			)
				? ctx.currentPostType
				: 'post',
			context_id: ctx.postId,
			schema_data: schemasToDeploy,
			options: {
				deployment_method: 'json_ld',
			},
		};

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/deploy',
			method: 'POST',
			data: requestData,
		});

		if (response.success) {
			ctx.showNotice?.(
				__('Schema markup deployed successfully!', 'thinkrank'),
				'success'
			);
			await loadDeployedSchemas(ctx, false);
			ctx.setGeneratedSchemas(null);
		} else {
			ctx.showNotice?.(
				response.message || __('Schema deployment failed', 'thinkrank'),
				'error'
			);
		}
	} catch (error) {
		ctx.showNotice?.(
			__('Schema deployment failed. Please try again.', 'thinkrank'),
			'error'
		);
	} finally {
		ctx.setIsDeploying(false);
	}
};

/**
 * Import schema from URL
 * @param ctx
 */
export const importSchemaFromUrl = async (ctx) => {
	if (!ctx.importUrl) {
		ctx.showNotice?.(
			__('Please enter a URL to import from.', 'thinkrank'),
			'error'
		);
		return;
	}

	ctx.setIsImporting(true);
	ctx.setImportedData(null);
	ctx.setAvailableSchemas([]);
	ctx.setSelectedImportIndex(0);

	try {
		const response = await apiFetch({
			path: '/thinkrank/v1/schema/import',
			method: 'POST',
			data: { url: ctx.importUrl },
		});

		if (response.success && response.data) {
			ctx.setImportedData(response.data);
			if (response.all_found && Array.isArray(response.all_found)) {
				ctx.setAvailableSchemas(response.all_found);
			}
			ctx.showNotice?.(
				__(
					'Schema imported successfully! Please review and apply the data below.',
					'thinkrank'
				),
				'success'
			);
		} else {
			ctx.showNotice?.(
				response.message || __('Failed to import schema.', 'thinkrank'),
				'error'
			);
		}
	} catch (error) {
		ctx.showNotice?.(
			error.message ||
				__('An error occurred while importing schema.', 'thinkrank'),
			'error'
		);
	} finally {
		ctx.setIsImporting(false);
	}
};
