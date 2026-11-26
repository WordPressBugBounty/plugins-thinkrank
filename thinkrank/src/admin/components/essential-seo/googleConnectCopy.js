/**
 * What connecting Google unlocks, worded for the plan the site is on.
 *
 * GA4 is a ThinkRank Pro feature. On the free plugin, connecting Google
 * brings in Search Console and PageSpeed only, so offering "GA4 metrics"
 * there told people they would get data that never arrives (#1584).
 *
 * The plan is read the way ProGatedTab reads it, from `thinkrankAdmin.isPro`:
 * Pro's admin bundle also loads when its licence is invalid, so a Pro filter
 * being registered says nothing about whether GA4 works.
 *
 * @package
 * @since 2.22.1
 */

import { __ } from '@wordpress/i18n';

/**
 * Whether this site gets GA4 from its Google connection.
 *
 * @return {boolean} True on a licensed Pro install.
 */
export const googleConnectIncludesGa4 = () => !!window.thinkrankAdmin?.isPro;

/**
 * The not-connected card's description and perk list.
 *
 * @param {boolean} withGa4 Whether GA4 comes with the connection.
 * @return {{description: string, perks: string[]}} Card copy.
 */
export const googleConnectCopy = (withGa4) =>
	withGa4
		? {
				description: __(
					'Connect your Google account to unlock Search Console, Analytics 4, and PageSpeed insights, all surfaced in your dashboards.',
					'thinkrank'
				),
				perks: [
					__('Search Console data', 'thinkrank'),
					__('GA4 metrics', 'thinkrank'),
					__('PageSpeed insights', 'thinkrank'),
				],
			}
		: {
				description: __(
					'Connect your Google account to unlock Search Console and PageSpeed insights, all surfaced in your dashboards.',
					'thinkrank'
				),
				perks: [
					__('Search Console data', 'thinkrank'),
					__('PageSpeed insights', 'thinkrank'),
				],
			};
