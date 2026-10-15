/**
 * Sitemap API Functions
 *
 * All API interaction functions for sitemap management.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Load sitemap settings
 * @param ctx
 */
export const loadSettings = async (ctx) => {
	try {
		ctx.setIsLoading(true);

		const response = await apiFetch({
			path: '/thinkrank/v1/sitemap/settings?context_type=site',
			method: 'GET',
		});

		if (response.success) {
			const loadedSettings = {
				...ctx.getDefaultSettings(),
				...response.data.settings,
			};
			ctx.setSettings(loadedSettings);
			ctx.setSelectedPreset(loadedSettings.selected_preset || 'basic');
			// Generator state, not a setting: how automatic regeneration is
			// faring, so a rebuild that never ran is visible instead of silent.
			ctx.setHealth(response.data.health || null);
			// `delivery_mode` may still be 'auto'; only the server knows which
			// way that resolves. The links below need the resolved answer,
			// because a dynamic site never sets last_generated.
			ctx.setResolvedDeliveryMode(
				response.data.resolved_delivery_mode || null
			);
			// Children whose post type or taxonomy is no longer registered
			// publish nothing, so the rows say so rather than linking to a 404.
			// The server names them by URL, but what it judges is the type, so
			// the screen keeps the types: a row stays marked while the admin
			// edits its URL, and stops being marked once its type is changed.
			const unregisteredUrls =
				response.data.unregistered_child_urls || [];
			ctx.setUnregisteredTypes(
				(loadedSettings.sitemap_urls || [])
					.filter((sitemap) => unregisteredUrls.includes(sitemap.url))
					.map((sitemap) => sitemap.type)
			);
		} else {
			const defaultSettings = ctx.getDefaultSettings();
			ctx.setSettings(defaultSettings);
			ctx.setSelectedPreset(defaultSettings.selected_preset || 'basic');
		}
	} catch (error) {
		ctx.setSettings(ctx.getDefaultSettings());
		ctx.setNotice({
			status: 'error',
			message: __(
				'Failed to load sitemap settings. Using defaults.',
				'thinkrank'
			),
		});
	} finally {
		ctx.setIsLoading(false);
	}
};

/**
 * Persist an explicit settings object.
 *
 * Separate from saveSettings(ctx) because the feature-switch autosave has to
 * POST the object it was handed: inside that callback the component's own
 * `settings` state (and therefore ctx.settings) is still the pre-flip value.
 * Throws on failure so the caller can roll the switch back.
 *
 * @param {Object} settings Full sitemap settings object to persist.
 */
export const saveSettingsObject = async (settings) => {
	const response = await apiFetch({
		path: '/thinkrank/v1/sitemap/settings',
		method: 'POST',
		data: {
			settings,
			context_type: 'site',
		},
	});

	if (!response.success) {
		throw new Error(response.error || 'Failed to save settings');
	}
};

/**
 * Save sitemap settings
 * @param ctx
 */
export const saveSettings = async (ctx) => {
	try {
		ctx.setIsSaving(true);
		ctx.setNotice(null);

		await saveSettingsObject(ctx.settings);

		ctx.setHasChanges(false);
		ctx.setNotice({
			status: 'success',
			message: __('Sitemap settings saved successfully!', 'thinkrank'),
		});
	} catch (error) {
		ctx.setNotice({
			status: 'error',
			message: __(
				'Failed to save sitemap settings. Please try again.',
				'thinkrank'
			),
		});
	} finally {
		ctx.setIsSaving(false);
	}
};

/**
 * Clean up old sitemap files before generation
 */
export const cleanupOldSitemaps = async () => {
	try {
		const response = await apiFetch({
			path: '/thinkrank/v1/sitemap/cleanup',
			method: 'POST',
			data: {},
		});

		if (response.success && response.data.total_cleaned > 0) {
			return response.data.total_cleaned;
		}
		return 0;
	} catch (error) {
		return {
			error: true,
			message:
				error.message ||
				__('Failed to clean up previous sitemap files', 'thinkrank'),
		};
	}
};

/**
 * Generate sitemap
 * @param ctx
 */
export const generateSitemap = async (ctx) => {
	// Read by the catch below, which is outside the try's scope.
	let cleanupRemovedFiles = false;

	try {
		ctx.setIsGenerating(true);
		ctx.setNotice(null);

		const cleanupResult = await cleanupOldSitemaps();
		const cleanedCount = cleanupResult.error ? 0 : cleanupResult;
		const cleanupError = cleanupResult.error ? cleanupResult.message : null;

		// Cleanup has already deleted the published files at this point, so if
		// the generate below fails the site is serving no sitemap. Drop
		// last_generated straight away rather than leaving the "View Generated
		// Sitemaps" links pointing at files that are gone; a success path
		// re-sets it from the response a few lines down.
		if (cleanedCount > 0) {
			cleanupRemovedFiles = true;
			ctx.setSettings((prev) => ({ ...prev, last_generated: '' }));
		}

		const response = await apiFetch({
			path: '/thinkrank/v1/sitemap/generate',
			method: 'POST',
			data: {
				options: ctx.settings,
				save_to_file: true,
			},
		});

		if (response.success) {
			let message = '';
			if (response.data.sitemaps_generated) {
				const sitemapCount = response.data.sitemaps_generated.length;
				message = __(
					'Successfully generated {sitemap_count} sitemap files with {url_count} total URLs.',
					'thinkrank'
				)
					.replace('{sitemap_count}', sitemapCount)
					.replace('{url_count}', response.data.url_count);
			} else {
				message = __(
					'Successfully generated 1 sitemap file with {url_count} URLs.',
					'thinkrank'
				).replace('{url_count}', response.data.url_count);
			}

			if (cleanupError) {
				message +=
					' ' +
					__(
						'Warning: Could not clean up previous sitemap files.',
						'thinkrank'
					);
			} else if (cleanedCount > 0) {
				message +=
					' ' +
					__(
						'Cleaned up {count} previous sitemap file(s).',
						'thinkrank'
					).replace('{count}', cleanedCount);
			}

			ctx.setNotice({ status: 'success', message });

			// Dynamic delivery writes no file, so there is no generation to
			// record. last_generated means "these files are on disk" and must
			// stay empty here — falling back to `new Date()` would claim a
			// static publication that never happened.
			if (response.data.delivery_mode === 'dynamic') {
				ctx.setResolvedDeliveryMode('dynamic');
				ctx.setSettings((prev) => ({ ...prev, last_generated: '' }));
			} else {
				// Generation has run — record it so the "View Generated Sitemaps"
				// links activate without a page reload.
				ctx.setSettings((prev) => ({
					...prev,
					last_generated:
						response.data.last_generated ||
						new Date().toISOString(),
				}));
			}

			// This generation wrote what the automatic rebuild was queued for,
			// so its staleness/failure warning no longer applies.
			ctx.setHealth((prev) =>
				prev
					? {
							...prev,
							stale: false,
							pending_since: null,
							pending_seconds: 0,
							last_error: null,
						}
					: prev
			);
		} else {
			throw new Error(response.error || 'Failed to generate sitemap');
		}
	} catch (error) {
		let errorMessage = __(
			'Failed to generate sitemap. Please try again.',
			'thinkrank'
		);
		let noticeStatus = 'error';

		if (error.code) {
			switch (error.code) {
				case 'rate_limit_exceeded':
					errorMessage = __(
						'Too many sitemap generation requests. Please wait a few minutes before trying again.',
						'thinkrank'
					);
					noticeStatus = 'warning';
					break;
				case 'generation_in_progress':
					errorMessage = __(
						'Sitemap generation is already in progress. Please wait for it to complete.',
						'thinkrank'
					);
					noticeStatus = 'info';
					break;
				default:
					errorMessage = error.message || errorMessage;
					break;
			}
		} else if (error.message) {
			errorMessage = error.message;
		}

		// Say so plainly when the failure left the site with nothing published:
		// the server queues a rebuild after a cleanup, but the user should know
		// the sitemap is down in the meantime rather than reading a bare
		// "failed to generate" and assuming the previous file is still serving.
		if (cleanupRemovedFiles) {
			errorMessage +=
				' ' +
				__(
					'The previously published sitemap files were removed before this attempt, so no sitemap is being served right now. A rebuild has been queued. Retry if it does not reappear shortly.',
					'thinkrank'
				);
		}

		ctx.setNotice({ status: noticeStatus, message: errorMessage });
	} finally {
		ctx.setIsGenerating(false);
	}
};

/**
 * Validate a single sitemap URL
 * @param ctx
 * @param index
 */
export const validateSingleSitemap = async (ctx, index) => {
	const sitemap = ctx.settings.sitemap_urls?.[index];
	if (!sitemap) {
		return;
	}

	ctx.setValidatingIndex(index);
	try {
		const siteUrl = window.location.origin;
		const fullUrl = sitemap.url.startsWith('http')
			? sitemap.url
			: `${siteUrl}${sitemap.url}`;

		const response = await fetch(fullUrl, { method: 'HEAD' });
		const status = response.ok ? 'active' : 'not_found';

		// Status write-back only: don't re-validate the URL (that threw and
		// discarded the result, so the row status never updated) and don't mark
		// the form dirty — validating is a read-only action.
		ctx.updateSitemap(
			index,
			{
				...sitemap,
				status,
				last_checked: new Date().toISOString(),
				response_code: response.status,
			},
			{ validateUrl: false, markDirty: false }
		);
	} catch (error) {
		ctx.updateSitemap(
			index,
			{
				...sitemap,
				status: 'error',
				last_checked: new Date().toISOString(),
			},
			{ validateUrl: false, markDirty: false }
		);

		ctx.setNotice({
			status: 'error',
			message: __(
				'Failed to validate sitemap. Please check the URL and try again.',
				'thinkrank'
			),
		});
	} finally {
		ctx.setValidatingIndex(null);
	}
};

/**
 * Validate all sitemaps
 * @param ctx
 */
export const validateAllSitemaps = async (ctx) => {
	ctx.setIsValidatingAll(true);
	try {
		const sitemapUrls = ctx.settings.sitemap_urls || [];
		for (let i = 0; i < sitemapUrls.length; i++) {
			await validateSingleSitemap(ctx, i);
		}
	} finally {
		ctx.setIsValidatingAll(false);
	}
};

/**
 * Ping search engines
 * @param ctx
 */
export const pingSearchEngines = async (ctx) => {
	ctx.setIsPinging(true);
	try {
		const response = await apiFetch({
			path: '/thinkrank/v1/sitemap/ping',
			method: 'POST',
			data: { sitemap_urls: ctx.settings.sitemap_urls },
		});

		if (response.success) {
			ctx.setNotice({
				status: 'success',
				message: __(
					'Search engines notified successfully!',
					'thinkrank'
				),
			});
		}
	} catch (error) {
		let errorMessage = __('Failed to ping search engines.', 'thinkrank');
		let noticeStatus = 'error';

		if (error.code) {
			switch (error.code) {
				case 'ping_rate_limit_exceeded':
					errorMessage = __(
						'Too many ping requests. Please wait an hour before trying again.',
						'thinkrank'
					);
					noticeStatus = 'warning';
					break;
				default:
					errorMessage = error.message || errorMessage;
					break;
			}
		} else if (error.message) {
			errorMessage = error.message;
		}

		ctx.setNotice({ status: noticeStatus, message: errorMessage });
	} finally {
		ctx.setIsPinging(false);
	}
};
