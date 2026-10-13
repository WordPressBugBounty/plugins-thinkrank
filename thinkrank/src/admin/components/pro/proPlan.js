/**
 * Pro plan state — the two questions a free-side screen can ask about Pro.
 *
 * They are not the same question, and conflating them is what #512 and #533
 * both came from:
 *
 *   window.thinkrankAdmin.isPro  — "Pro is here AND licensed". It resolves
 *                                  through the `thinkrank_is_pro_active`
 *                                  filter, which Pro's licence manager owns.
 *                                  False on an activated-but-unlicensed site.
 *   isProInstalled()             — "the Pro plugin is on this site at all",
 *                                  licence or not.
 *
 * A screen that gates its paid panel on the first question and its upsell on
 * the second can tell the three states apart: not installed (sell it),
 * installed but unlicensed (warn that the licence is missing, via
 * <ProLicenseNotice/>), licensed (render it).
 *
 * @package
 * @since 2.1.1
 */

/**
 * Whether the Pro plugin is installed on this site at all.
 *
 * `window.thinkrankProLicense` is localized only by Pro, so its presence is
 * the signal that Pro is here regardless of licence state. `isPro` stays in
 * the test so a licensed install still resolves true if that data is ever
 * absent (an older Pro build, a conditional enqueue mismatch).
 *
 * @return {boolean} True when Pro is present, licensed or not.
 */
export const isProInstalled = () =>
	!!window.thinkrankAdmin?.isPro || !!window.thinkrankProLicense;

/**
 * Whether Pro is present but has no valid licence.
 *
 * @return {boolean} True only in the activated-but-unlicensed state.
 */
export const isProUnlicensed = () =>
	isProInstalled() && !window.thinkrankAdmin?.isPro;

/**
 * Admin URL of the ThinkRank License page.
 *
 * Built from the current pathname so it works on any admin URL layout.
 *
 * @return {string} The License page URL.
 */
export const licensePageUrl = () =>
	`${window.location.pathname}?page=thinkrank-license`;
