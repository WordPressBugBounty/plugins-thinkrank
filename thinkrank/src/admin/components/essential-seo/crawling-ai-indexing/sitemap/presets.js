/**
 * Sitemap Preset Configurations
 *
 * Default settings and quick-setup template logic.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Get default sitemap settings
 */
export const getDefaultSettings = () => ({
	enabled: true,
	selected_preset: 'basic',
	sitemap_urls: [
		{
			url: '/sitemap.xml',
			type: 'general',
			enabled: true,
			last_checked: null,
			status: 'unknown',
		},
	],
	use_sitemap_index: false,
	// 'auto' resolves to static wherever the WordPress root is writable, so an
	// existing site keeps publishing exactly the files it published before.
	delivery_mode: 'auto',
	links_per_sitemap: 1000,
	include_images: true,
	include_featured_images: false,
	auto_generate: true,
	ping_search_engines: true,
	exclude_posts: '',
	exclude_terms: '',
	exclude_password_protected: true,
	enable_styling: true,
	custom_url_pattern: 'sitemap-{type}.xml',
	// Branding is unset by default, and '' is what keeps it that way: it means
	// the stylesheet's own colour stands, so a site that never opens this
	// screen renders exactly as it did before the setting existed.
	styling_logo: false,
	styling_logo_url: '',
	styling_color_main: '',
	styling_color_accent: '',
});

/**
 * Apply URL pattern to generate sitemap filename
 * @param type
 * @param settings
 */
export const applyUrlPattern = (type, settings) => {
	const pattern = settings.custom_url_pattern || 'sitemap-{type}.xml';
	const filename = pattern.replace('{type}', type);
	return filename.startsWith('/') ? filename : '/' + filename;
};

/**
 * Apply a preset template configuration
 * @param presetType
 * @param ctx
 */
export const applyPreset = async (presetType, ctx) => {
	if (!presetType) return;

	ctx.setSelectedPreset(presetType);
	ctx.setSettings((prev) => ({ ...prev, selected_preset: presetType }));

	let presetUrls = [];
	let useSitemapIndex = false;
	// Set true when a branch below already surfaced a warning/error notice, so
	// the generic "click Generate" info notice at the end doesn't clobber it.
	let noticeSet = false;
	const urlPattern = (type) => applyUrlPattern(type, ctx.settings);

	switch (presetType) {
		case 'basic':
			presetUrls = [
				{
					url: '/sitemap.xml',
					type: 'general',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
			];
			useSitemapIndex = false;
			break;

		case 'wordpress-core':
			presetUrls = [
				{
					url: '/wp-sitemap.xml',
					type: 'wordpress',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
			];
			useSitemapIndex = false;
			break;

		case 'segmented':
			presetUrls = [
				{
					url: '/sitemap_index.xml',
					type: 'index',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
				{
					url: urlPattern('posts'),
					type: 'posts',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
				{
					url: urlPattern('pages'),
					type: 'pages',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
				{
					url: urlPattern('categories'),
					type: 'categories',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
			];
			useSitemapIndex = true;
			break;

		case 'ecommerce':
			try {
				const wooResponse = await apiFetch({
					path: '/thinkrank/v1/sitemap/woocommerce-status',
				});

				if (wooResponse.success && wooResponse.data.is_active) {
					presetUrls = [
						{
							url: '/sitemap_index.xml',
							type: 'index',
							enabled: true,
							last_checked: null,
							status: 'unknown',
						},
						{
							url: urlPattern('products'),
							type: 'products',
							enabled: true,
							last_checked: null,
							status: 'unknown',
						},
						{
							url: urlPattern('product_categories'),
							type: 'product_categories',
							enabled: true,
							last_checked: null,
							status: 'unknown',
						},
					];
					useSitemapIndex = true;
				} else {
					presetUrls = [
						{
							url: '/sitemap.xml',
							type: 'general',
							enabled: true,
							last_checked: null,
							status: 'unknown',
						},
					];
					useSitemapIndex = false;
					ctx.setNotice({
						status: 'warning',
						message: __(
							'WooCommerce is not active. E-commerce template has been changed to Basic template.',
							'thinkrank'
						),
					});
					noticeSet = true;
					setTimeout(() => ctx.setNotice(null), 5000);
					ctx.setSelectedPreset('basic');
					ctx.setSettings((prev) => ({
						...prev,
						selected_preset: 'basic',
					}));
				}
			} catch (error) {
				presetUrls = [
					{
						url: '/sitemap.xml',
						type: 'general',
						enabled: true,
						last_checked: null,
						status: 'unknown',
					},
				];
				useSitemapIndex = false;
				ctx.setNotice({
					status: 'error',
					message: __(
						'Failed to check WooCommerce status. E-commerce template has been changed to Basic template.',
						'thinkrank'
					),
				});
				noticeSet = true;
				setTimeout(() => ctx.setNotice(null), 5000);
				ctx.setSelectedPreset('basic');
				ctx.setSettings((prev) => ({
					...prev,
					selected_preset: 'basic',
				}));
			}
			break;

		case 'complete':
			presetUrls = [
				{
					url: '/sitemap_index.xml',
					type: 'index',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
				{
					url: urlPattern('posts'),
					type: 'posts',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
				{
					url: urlPattern('pages'),
					type: 'pages',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
				{
					url: urlPattern('categories'),
					type: 'categories',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
			];

			try {
				const wooResponse = await apiFetch({
					path: '/thinkrank/v1/sitemap/woocommerce-status',
				});
				if (wooResponse.success && wooResponse.data.is_active) {
					presetUrls.push(
						{
							url: urlPattern('products'),
							type: 'products',
							enabled: true,
							last_checked: null,
							status: 'unknown',
						},
						{
							url: urlPattern('product_categories'),
							type: 'product_categories',
							enabled: true,
							last_checked: null,
							status: 'unknown',
						}
					);
				}
			} catch (error) {
				// WooCommerce check failed - continue without
			}

			try {
				const cptResponse = await apiFetch({
					path: '/thinkrank/v1/sitemap/custom-post-types',
				});
				if (cptResponse.success && cptResponse.data) {
					cptResponse.data.forEach((postType) => {
						presetUrls.push({
							url: urlPattern(postType.name),
							type: postType.name,
							enabled: true,
							last_checked: null,
							status: 'unknown',
						});
					});
				}
			} catch (error) {
				ctx.setNotice({
					status: 'warning',
					message: __(
						'Failed to fetch custom post types. Complete template may not include all content types.',
						'thinkrank'
					),
				});
				noticeSet = true;
				setTimeout(() => ctx.setNotice(null), 5000);
			}

			useSitemapIndex = true;
			break;

		case 'custom':
			return; // Custom - don't change anything

		default:
			return;
	}

	ctx.setSettings((prev) => ({
		...prev,
		sitemap_urls: presetUrls,
		use_sitemap_index: useSitemapIndex,
		// The new URL set hasn't been generated yet — reset so the "View
		// Generated Sitemaps" links stay disabled until the user regenerates.
		last_generated: '',
	}));
	ctx.setHasChanges(true);

	// Applying a template only rewrites the URL config — it does not create the
	// sitemap files. Nudge the user to actually generate them (unless a branch
	// above already surfaced a more specific warning/error).
	if (!noticeSet) {
		ctx.setNotice({
			status: 'info',
			message: __(
				'Template applied. Click "Generate Sitemap" to create the sitemap files.',
				'thinkrank'
			),
		});
	}
};
