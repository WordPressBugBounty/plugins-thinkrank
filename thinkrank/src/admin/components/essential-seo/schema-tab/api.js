/**
 * Schema Tab API Functions
 *
 * All API interaction functions for the Schema Tab.
 * Extracted from SchemaTab.js for maintainability.
 *
 * Each function accepts a `ctx` (context) object with the component's
 * state helpers: { settings, setSettings, setHasChanges, updateLoadingState,
 * safeSetState, setNotice, updateSchemaData, isMountedRef, abortControllerRef }
 *
 * @package
 * @since 1.11.0
 */

import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../../../shared/utils/logger';
import { validateSectionData } from '../../../utils/schemaValidation';
import notify from '../../../utils/notify';
import { getDefaultSettings, normalizeSettingsDataTypes } from './utils';
import {
	validateSettings,
	getSectionValidationData,
	formatBusinessHoursForContact,
	mapSiteIdentityData,
	getSyncTypeDisplayName,
} from './validation';

/**
 * Load settings from Settings Management API
 * @param ctx
 */
export const loadSettings = async (ctx) => {
	try {
		ctx.abortControllerRef.current = new AbortController();
		ctx.updateLoadingState('isLoading', true);

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/settings?context_type=site',
			method: 'GET',
			signal: ctx.abortControllerRef.current.signal,
		});

		if (response.success) {
			const mergedSettings = {
				...getDefaultSettings(),
				...response.data.settings,
			};
			const normalizedSettings =
				normalizeSettingsDataTypes(mergedSettings);
			ctx.safeSetState(ctx.setSettings, normalizedSettings);
		} else {
			ctx.safeSetState(ctx.setSettings, getDefaultSettings());
		}
	} catch (error) {
		if (error.name !== 'AbortError') {
			logger.error('Schema settings load error:', error);
			ctx.safeSetState(ctx.setSettings, getDefaultSettings());
			notify.error(
				__(
					'Failed to load schema settings. Using defaults.',
					'thinkrank'
				)
			);
		}
	} finally {
		ctx.updateLoadingState('isLoading', false);
	}
};

/**
 * Save settings to Schema API
 * @param ctx
 */
export const saveSettings = async (ctx) => {
	try {
		ctx.abortControllerRef.current = new AbortController();
		ctx.updateLoadingState('isSaving', true);
		ctx.safeSetState(ctx.setNotice, null);

		// Validate settings before saving
		const validationErrors = validateSettings(ctx.settings);
		if (validationErrors.length > 0) {
			ctx.safeSetState(ctx.setNotice, {
				status: 'error',
				message: (
					<div>
						<strong>
							{__(
								'Please fix the following errors:',
								'thinkrank'
							)}
						</strong>
						<ul
							className="thinkrank-mt-xs"
							style={{ paddingLeft: '20px' }}
						>
							{validationErrors.map((error, index) => (
								<li key={index}>{error}</li>
							))}
						</ul>
					</div>
				),
			});
			return;
		}

		const normalizedSettings = normalizeSettingsDataTypes(ctx.settings);

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/settings',
			method: 'POST',
			data: {
				settings: normalizedSettings,
				context_type: 'site',
			},
			signal: ctx.abortControllerRef.current.signal,
		});

		if (response.success) {
			ctx.safeSetState(ctx.setHasChanges, false);
			notify.success(
				__('Schema settings saved successfully!', 'thinkrank')
			);
		} else {
			throw new Error(response.error || 'Failed to save settings');
		}
	} catch (error) {
		if (error.name !== 'AbortError') {
			logger.error('Schema settings save error:', error);

			// Handle validation errors with detailed feedback. This is
			// actionable detail the user has to read, so it stays an inline
			// banner rather than a transient toast.
			if (
				error.code === 'validation_failed' &&
				error.data?.validation_errors
			) {
				ctx.safeSetState(ctx.setNotice, {
					status: 'error',
					message: (
						<div>
							<strong>
								{__('Settings validation failed:', 'thinkrank')}
							</strong>
							<ul
								className="thinkrank-mt-xs"
								style={{ paddingLeft: '20px' }}
							>
								{error.data.validation_errors.map(
									(validationError, index) => (
										<li key={index}>{validationError}</li>
									)
								)}
							</ul>
							{error.data.validation_warnings &&
								error.data.validation_warnings.length > 0 && (
									<>
										<strong>
											{__('Warnings:', 'thinkrank')}
										</strong>
										<ul
											className="thinkrank-mt-xs"
											style={{ paddingLeft: '20px' }}
										>
											{error.data.validation_warnings.map(
												(warning, index) => (
													<li key={index}>
														{warning}
													</li>
												)
											)}
										</ul>
									</>
								)}
						</div>
					),
				});
			} else {
				notify.error(
					error.message ||
						__(
							'Failed to save schema settings. Please try again.',
							'thinkrank'
						)
				);
			}
		}
	} finally {
		ctx.updateLoadingState('isSaving', false);
	}
};

/**
 * Load available schema types
 * @param ctx
 */
export const loadSchemaTypes = async (ctx) => {
	try {
		const response = await apiFetch({
			path: '/thinkrank/v1/schema/types?context=site',
			method: 'GET',
		});

		if (response.success) {
			ctx.updateSchemaData('schemaTypes', response.data.types || []);
		}
	} catch (error) {
		logger.error('Schema types load error:', error);
		// Fallback to site-level schema types only
		ctx.updateSchemaData('schemaTypes', [
			{
				name: 'Organization',
				description: 'Companies and organizations',
			},
			{
				name: 'LocalBusiness',
				description: 'Local businesses with physical locations',
			},
			{
				name: 'Person',
				description: 'Individual person or author information',
			},
			{
				name: 'WebSite',
				description:
					'Website-level information and search functionality',
			},
		]);
	}
};

/**
 * Load deployed schemas from database
 * @param ctx
 */
export const loadDeployedSchemas = async (ctx) => {
	try {
		const response = await apiFetch({
			path: '/thinkrank/v1/schema/deployed?context_type=site',
		});

		if (response.success) {
			ctx.updateSchemaData('deployedSchemas', response.data);
		}
	} catch (error) {
		logger.error('Failed to load deployed schemas:', error);
	}
};

/**
 * Run comprehensive validation for current section
 * @param ctx
 * @param activeSubSection
 */
export const runValidation = async (ctx, activeSubSection) => {
	try {
		ctx.updateLoadingState('isValidating', true);
		ctx.safeSetState(ctx.setNotice, null);

		const sectionData = getSectionValidationData(
			activeSubSection,
			ctx.settings
		);
		const validationResult = await validateSectionData(
			activeSubSection,
			sectionData
		);

		ctx.updateSchemaData('validationResults', validationResult);

		// Set detailed notice based on validation result
		if (validationResult.isValid) {
			ctx.setNotice({
				status: 'success',
				message: (
					<div>
						<strong>
							{__(
								'✓ Validation passed successfully!',
								'thinkrank'
							)}
						</strong>
						<p>
							{__(
								'Schema is valid and ready for deployment. See detailed results below.',
								'thinkrank'
							)}
						</p>
					</div>
				),
			});
		} else if (
			validationResult.hasWarnings &&
			!validationResult.hasErrors
		) {
			ctx.setNotice({
				status: 'warning',
				message: (
					<div>
						<strong>
							{__(
								'⚠ Validation completed with warnings',
								'thinkrank'
							)}
						</strong>
						<p>
							{__(
								'Schema will work but could be improved. Check the warnings below for recommendations.',
								'thinkrank'
							)}
						</p>
						<ul style={{ paddingLeft: '20px', marginTop: '8px' }}>
							{validationResult.warnings
								.slice(0, 3)
								.map((warning, index) => (
									<li key={index}>{warning}</li>
								))}
							{validationResult.warnings.length > 3 && (
								<li>
									{sprintf(
										// translators: %d is the number of additional warnings.
										__(
											'… and %d more warnings',
											'thinkrank'
										),
										validationResult.warnings.length - 3
									)}
								</li>
							)}
						</ul>
					</div>
				),
			});
		} else {
			ctx.setNotice({
				status: 'error',
				message: (
					<div>
						<strong>
							{__('✗ Validation failed', 'thinkrank')}
						</strong>
						<p>
							{__(
								'Please fix the following errors before deploying schema:',
								'thinkrank'
							)}
						</p>
						<ul style={{ paddingLeft: '20px', marginTop: '8px' }}>
							{validationResult.errors
								.slice(0, 3)
								.map((error, index) => (
									<li key={index}>{error}</li>
								))}
							{validationResult.errors.length > 3 && (
								<li>
									{sprintf(
										// translators: %d is the number of additional errors.
										__('… and %d more errors', 'thinkrank'),
										validationResult.errors.length - 3
									)}
								</li>
							)}
						</ul>
					</div>
				),
			});
		}
	} catch (error) {
		logger.error('Validation error:', error);
		notify.error(
			__(
				'Validation failed due to an unexpected error. Please try again.',
				'thinkrank'
			)
		);
	} finally {
		ctx.updateLoadingState('isValidating', false);
	}
};

/**
 * Test schema with Google Rich Results
 * @param ctx
 */
export const testWithGoogleRichResults = async (ctx) => {
	try {
		ctx.updateLoadingState('isValidating', true);
		ctx.safeSetState(ctx.setNotice, null);

		const testUrl = window.location.origin;
		const richResultsUrl = `https://search.google.com/test/rich-results?url=${encodeURIComponent(testUrl)}`;

		window.open(richResultsUrl, '_blank');

		notify.info(
			__(
				'Google Rich Results Test opened in new tab. Test your schema markup there.',
				'thinkrank'
			)
		);
	} catch (error) {
		logger.error('Rich Results test error:', error);
		notify.error(
			__(
				'Failed to open Rich Results Test. Please try again.',
				'thinkrank'
			)
		);
	} finally {
		ctx.updateLoadingState('isValidating', false);
	}
};

/**
 * Generate schema markup
 * @param ctx
 */
export const generateSchema = async (ctx) => {
	try {
		ctx.updateLoadingState('isGenerating', true);

		// Valid site-level schema types (post/page schemas handled by metabox)
		const validSchemaTypes = [
			'Organization',
			'LocalBusiness',
			'Person',
			'WebSite',
		];

		// Filter and validate schema types
		let enabledTypes = ctx.settings.enabled_schema_types || [];
		if (Array.isArray(enabledTypes)) {
			enabledTypes = enabledTypes.filter((type) =>
				validSchemaTypes.includes(type)
			);
		}

		// Use fallback if no valid types
		if (!enabledTypes.length) {
			enabledTypes = ['Organization'];
		}

		const requestData = {
			context_type: 'site',
			schema_types: enabledTypes,
			options: {
				validation_level: ctx.settings.validation_level || 'moderate',
				// `?? true` not `|| true` — `false || true` is `true`, so these
				// three could never be turned off (#472).
				rich_snippets_optimization:
					ctx.settings.rich_snippets_optimization ?? true,
				knowledge_graph: ctx.settings.knowledge_graph ?? true,
				auto_generate_schema: ctx.settings.auto_generate_schema ?? true,
				enable_breadcrumbs_schema:
					ctx.settings.enable_breadcrumbs_schema || false,
				enable_accordion_faq_schema:
					ctx.settings.enable_accordion_faq_schema || false,
				enable_local_business:
					ctx.settings.enable_local_business || false,
			},
		};

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/generate',
			method: 'POST',
			data: requestData,
		});

		if (response.success) {
			ctx.updateSchemaData('generatedSchemas', response.data);
			notify.success(
				__('Schema markup generated successfully!', 'thinkrank')
			);
		}
	} catch (error) {
		logger.error('Schema generation error:', error);
		notify.error(
			__('Schema generation failed. Please try again.', 'thinkrank')
		);
	} finally {
		ctx.updateLoadingState('isGenerating', false);
	}
};

/**
 * Validate all schema markup
 * @param ctx
 */
export const validateAllSchemas = async (ctx) => {
	try {
		ctx.updateLoadingState('isValidating', true);

		if (!ctx.generatedSchemas?.generated_schemas) {
			notify.error(
				__(
					'No schema markup to validate. Please generate schema first.',
					'thinkrank'
				)
			);
			return;
		}

		const validationResults = {};
		const schemas = ctx.generatedSchemas.generated_schemas;

		for (const [schemaType, schemaData] of Object.entries(schemas)) {
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/schema/validate',
					method: 'POST',
					data: {
						schema_data: schemaData,
						schema_type: schemaType,
						options: {
							validation_level:
								ctx.settings.validation_level || 'moderate',
						},
					},
				});

				if (response.success) {
					validationResults[schemaType] = response.data;
				}
			} catch (error) {
				validationResults[schemaType] = {
					valid: false,
					errors: [error.message || 'Validation failed'],
				};
			}
		}

		ctx.updateSchemaData('validationResults', {
			validation_details: validationResults,
			overall_status: Object.values(validationResults).every(
				(result) => result.valid !== false
			)
				? 'valid'
				: 'invalid',
		});

		notify.success(__('Schema validation completed!', 'thinkrank'));
	} catch (error) {
		logger.error('Schema validation error:', error);
		notify.error(
			__('Schema validation failed. Please try again.', 'thinkrank')
		);
	} finally {
		ctx.updateLoadingState('isValidating', false);
	}
};

/**
 * Validate individual schema type
 * @param ctx
 * @param schemaType
 */
export const validateSingleSchema = async (ctx, schemaType) => {
	try {
		ctx.updateLoadingState('isValidating', true);

		if (!ctx.generatedSchemas?.generated_schemas?.[schemaType]) {
			notify.error(
				__(
					`No ${schemaType} schema to validate. Please generate schema first.`,
					'thinkrank'
				)
			);
			return;
		}

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/validate',
			method: 'POST',
			data: {
				schema_data: ctx.generatedSchemas.generated_schemas[schemaType],
				schema_type: schemaType,
				options: {
					validation_level:
						ctx.settings.validation_level || 'moderate',
				},
			},
		});

		if (response.success) {
			const newValidationResults = {
				...ctx.validationResults,
				validation_details: {
					...ctx.validationResults?.validation_details,
					[schemaType]: response.data,
				},
			};

			ctx.updateSchemaData('validationResults', newValidationResults);

			notify.success(
				__(`${schemaType} schema validation completed!`, 'thinkrank')
			);
		}
	} catch (error) {
		logger.error(`${schemaType} schema validation error:`, error);

		ctx.updateSchemaData('validationResults', {
			...ctx.validationResults,
			validation_details: {
				...ctx.validationResults?.validation_details,
				[schemaType]: {
					valid: false,
					errors: [error.message || 'Validation failed'],
				},
			},
		});

		notify.error(
			__(
				`${schemaType} schema validation failed. Please try again.`,
				'thinkrank'
			)
		);
	} finally {
		ctx.updateLoadingState('isValidating', false);
	}
};

/**
 * Deploy schema markup
 * @param ctx
 */
export const deploySchema = async (ctx) => {
	try {
		ctx.updateLoadingState('isDeploying', true);

		const schemasToDeploy =
			ctx.generatedSchemas?.generated_schemas || ctx.deployedSchemas;

		if (!schemasToDeploy) {
			notify.error(
				__(
					'No schema markup to deploy. Please generate schema first.',
					'thinkrank'
				)
			);
			return;
		}

		const requestData = {
			context_type: 'site',
			schema_data: schemasToDeploy,
			options: {
				deployment_method: ctx.settings.deployment_method || 'json_ld',
			},
		};

		const response = await apiFetch({
			path: '/thinkrank/v1/schema/deploy',
			method: 'POST',
			data: requestData,
		});

		if (response.success) {
			// A type that fails server validation is skipped, not deployed.
			// Reporting that as plain success hid a missing Organization (#949).
			const skipped = Array.isArray(response.skipped)
				? response.skipped
				: [];

			if (skipped.length) {
				notify.warning(
					sprintf(
						/* translators: 1: comma-separated schema types, 2: first validation error. */
						__(
							'Some schema was not deployed: %1$s. %2$s',
							'thinkrank'
						),
						skipped.map((entry) => entry.type).join(', '),
						skipped[0]?.errors?.[0] || ''
					)
				);
			} else {
				notify.success(
					__('Schema markup deployed successfully!', 'thinkrank')
				);
			}

			// Refresh deployed schemas after successful deployment
			loadDeployedSchemas(ctx);
		}
	} catch (error) {
		logger.error('Schema deployment error:', error);
		notify.error(
			__('Schema deployment failed. Please try again.', 'thinkrank')
		);
	} finally {
		ctx.updateLoadingState('isDeploying', false);
	}
};

/**
 * Generate schema preview
 * @param ctx
 */
export const generatePreview = async (ctx) => {
	try {
		if (!ctx.generatedSchemas?.generated_schemas) {
			notify.error(
				__(
					'No schema markup to preview. Please generate schema first.',
					'thinkrank'
				)
			);
			return;
		}

		ctx.updateLoadingState('isPreviewing', true);

		const previewResults = {};
		const schemas = ctx.generatedSchemas.generated_schemas;
		const failed = [];

		for (const [schemaType, schemaData] of Object.entries(schemas)) {
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/schema/preview',
					method: 'POST',
					data: {
						schema_data: schemaData,
						schema_type: schemaType,
					},
				});

				if (response.success && response.data.rich_snippets) {
					previewResults[schemaType] = response.data.rich_snippets;
				} else {
					failed.push(schemaType);
				}
			} catch (error) {
				logger.error(`Schema preview error for ${schemaType}:`, error);
				failed.push(schemaType);
			}
		}

		ctx.updateSchemaData('schemaPreview', {
			rich_snippets: previewResults,
		});

		// A per-type failure used to be logged and nothing else, so a preview
		// that silently produced nothing looked identical to a working one.
		if (failed.length > 0) {
			notify.error(
				sprintf(
					/* translators: %s: comma-separated schema type names. */
					__('Could not build a preview for: %s', 'thinkrank'),
					failed.join(', ')
				)
			);
		}
	} catch (error) {
		logger.error('Schema preview error:', error);
		notify.error(
			__('Could not generate the preview. Please try again.', 'thinkrank')
		);
	} finally {
		ctx.updateLoadingState('isPreviewing', false);
	}
};

/**
 * Sync data from Site Identity
 * @param ctx
 * @param syncType
 */
export const syncFromSiteIdentity = async (ctx, syncType) => {
	try {
		ctx.updateLoadingState('isSyncing', true);
		ctx.safeSetState(ctx.setNotice, null);

		const response = await apiFetch({
			path: '/thinkrank/v1/site-identity/settings',
			method: 'GET',
		});

		if (response.success) {
			const siteIdentityData = response.data.settings;
			const syncedData = mapSiteIdentityData(siteIdentityData, syncType);

			const updatedSettings = { ...ctx.settings, ...syncedData };
			ctx.setSettings(updatedSettings);
			// Sync fills the form but does not persist — without marking the
			// form dirty the Save button stayed disabled, so the values were
			// lost on reload even though the toast said "synced successfully".
			ctx.setHasChanges(true);

			notify.success(
				sprintf(
					/* translators: %s: the kind of data synced, e.g. "Website". */
					__(
						'%s data synced from Site Identity. Save to apply.',
						'thinkrank'
					),
					getSyncTypeDisplayName(syncType)
				)
			);
		} else {
			throw new Error('Failed to fetch Site Identity data');
		}
	} catch (error) {
		logger.error('Sync error:', error);
		notify.error(
			sprintf(
				/* translators: %s: the kind of data synced, e.g. "Website". */
				__('Failed to sync %s data. Please try again.', 'thinkrank'),
				getSyncTypeDisplayName(syncType)
			)
		);
	} finally {
		ctx.updateLoadingState('isSyncing', false);
	}
};

/**
 * Sync contact hours from Local SEO
 * @param ctx
 * @param syncType
 */
export const syncFromLocalSEO = async (ctx, syncType) => {
	try {
		ctx.updateLoadingState('isSyncing', true);
		ctx.safeSetState(ctx.setNotice, null);

		const response = await apiFetch({
			path: '/thinkrank/v1/site-identity/settings',
			method: 'GET',
		});

		if (response.success) {
			const localSeoData = response.data.settings;

			if (syncType === 'contact_hours') {
				const businessHours = localSeoData.business_hours || {};
				const formattedHours =
					formatBusinessHoursForContact(businessHours);

				const updatedSettings = {
					...ctx.settings,
					organization_contact_hours: formattedHours,
				};
				ctx.setSettings(updatedSettings);
				ctx.setHasChanges(true);

				notify.success(
					__(
						'Contact hours synced successfully from Local SEO business hours!',
						'thinkrank'
					)
				);
			}
		} else {
			throw new Error('Failed to fetch Local SEO data');
		}
	} catch (error) {
		logger.error('Local SEO sync error:', error);
		notify.error(
			__(
				'Failed to sync contact hours from Local SEO. Please try again.',
				'thinkrank'
			)
		);
	} finally {
		ctx.updateLoadingState('isSyncing', false);
	}
};
