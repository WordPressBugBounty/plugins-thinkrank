/**
 * Setup Wizard API helpers
 *
 * Wizard state lives on a dedicated endpoint; the actual SEO settings reuse the
 * existing per-feature endpoints (site-identity, sitemap, schema).
 *
 * @package
 * @since 1.0.0
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Persist the current step so the wizard can resume later.
 *
 * @param {number} step 1-based step index.
 * @return {Promise<Object>} Response.
 */
export function saveStep(step) {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/step',
		method: 'POST',
		data: { step },
	});
}

/**
 * Record the Start step and the user's usage-tracking choice.
 *
 * Tracking is enabled server-side only when `shareUsage` is true ("Allow &
 * Continue" was chosen); "Skip" sends false.
 *
 * @param {boolean} shareUsage Whether "Allow & Continue" was chosen.
 * @return {Promise<Object>} Response.
 */
export function recordStartStep(shareUsage) {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/consent',
		method: 'POST',
		data: { consent: !!shareUsage },
	});
}

/**
 * Mark the wizard as completed.
 *
 * @return {Promise<Object>} Response (includes redirect URL).
 */
export function completeWizard() {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/complete',
		method: 'POST',
	});
}

/**
 * Install the selected ecosystem plugins from WordPress.org (step 5).
 *
 * Best-effort: the response reports a per-plugin outcome but never rejects on
 * an individual plugin failure, so the wizard can always advance.
 *
 * @param {string[]} slugs Wizard plugin slugs to install.
 * @return {Promise<Object>} Response with a `results` map.
 */
export function installPlugins(slugs) {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/install-plugins',
		method: 'POST',
		data: { slugs },
	});
}

/**
 * Detect SEO data from other installed plugins (step 2 — Migration).
 *
 * Reuses the existing import system's detection endpoint so the wizard's
 * enabled/disabled Import state matches the standalone Import/Export tool.
 *
 * @return {Promise<Object>} Response with `detected` and `snapshots` maps.
 */
export function detectImportPlugins() {
	return apiFetch({ path: '/thinkrank/v1/import/detect' });
}

/**
 * Read SEO data migrated from another plugin, mapped to the Site Setup step's
 * form fields.
 *
 * The Migration step's import writes into ThinkRank's option-based stores, which
 * the per-feature settings endpoints do not read back. This dedicated endpoint
 * surfaces the migrated values so the Site Setup step can pre-fill them.
 *
 * @return {Promise<Object>} Response with a `settings` map (only migrated keys).
 */
export function getMigratedSiteData() {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/migrated-site-data',
	});
}

/**
 * Record that a source plugin has been migrated from inside the wizard.
 *
 * Persists the completion so its Import button stays disabled (shown as
 * "Imported") after a refresh or when returning to the Migration step. This is
 * wizard-only — the standalone Migration page never calls it and always allows
 * a deliberate re-run.
 *
 * @param {string} plugin Source-plugin slug (yoast|rankmath|aioseo|seopress|squirrly|slimseo).
 * @return {Promise<Object>} Response with the updated `migrated_plugins` list.
 */
export function markWizardPluginMigrated(plugin) {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/migrated-plugins',
		method: 'POST',
		data: { plugin },
	});
}

/**
 * Deactivate a source SEO plugin whose data was migrated in the wizard.
 *
 * Wizard-only: called when the user leaves the Migration step via "Continue",
 * so a migrated source plugin isn't left running alongside ThinkRank. The
 * standalone Migration page never calls this. Best-effort — the response
 * reports the outcome (`deactivated` / `success`) but the caller always lets
 * the wizard advance.
 *
 * @param {string} plugin Source-plugin slug (yoast|rankmath|aioseo|seopress|squirrly|slimseo).
 * @return {Promise<Object>} Response with `success`, `deactivated`, `name`.
 */
export function deactivateMigratedPlugin(plugin) {
	return apiFetch({
		path: '/thinkrank/v1/setup-wizard/deactivate-plugin',
		method: 'POST',
		data: { plugin },
	});
}

/**
 * Save site identity settings (step 3).
 *
 * @param {Object} settings Settings object.
 * @return {Promise<Object>} Response.
 */
export function saveSiteIdentity(settings) {
	return apiFetch({
		path: '/thinkrank/v1/site-identity/settings',
		method: 'POST',
		data: { context_type: 'site', settings },
	});
}

/**
 * Save sitemap settings (step 3).
 *
 * @param {Object} settings Settings object.
 * @return {Promise<Object>} Response.
 */
export function saveSitemap(settings) {
	return apiFetch({
		path: '/thinkrank/v1/sitemap/settings',
		method: 'POST',
		data: { context_type: 'site', settings },
	});
}

/**
 * Save social profile URLs into the Organization schema (step 6).
 *
 * @param {Object} organizationSocial Map of organization_social_* keys to URLs.
 * @return {Promise<Object>} Response.
 */
export function saveSocialProfiles(organizationSocial) {
	return apiFetch({
		path: '/thinkrank/v1/schema/settings',
		method: 'POST',
		data: {
			context_type: 'site',
			settings: { ...organizationSocial, auto_deploy: true },
		},
	});
}

/**
 * Store how much of the menu this user sees: Simple or Advanced (#730).
 *
 * @param {string} mode 'simple' or 'advanced'.
 * @return {Promise<Object>} Response.
 */
export function saveUiMode(mode) {
	return apiFetch({
		path: '/thinkrank/v1/ui-mode',
		method: 'POST',
		data: { mode },
	});
}
