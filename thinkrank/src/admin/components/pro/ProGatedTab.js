/**
 * ProGatedTab — the shared gate for a free-side tab whose body lives in Pro.
 *
 * Four tabs (WooCommerce, Internal Links, Redirections, Broken Links) each
 * carried their own copy of this gate, and each got the order wrong: they
 * consulted `applyFilters` first and only checked the plan afterwards. Pro's
 * admin bundle is enqueued even when the licence is invalid — deliberately, so
 * the License screen stays reachable (#512) — so its `addFilter` always ran and
 * the filter branch always won. The plan check below it was dead code.
 *
 * The panel then rendered on an unlicensed site and immediately called a Pro
 * REST route that `load_components()` had never registered, so the user got a
 * raw `rest_no_route` error where an upsell belonged (#533).
 *
 * Asking the plan first is what fixes it. The three states are distinct:
 *
 *   licensed              -> the Pro panel, via the filter
 *   installed, no licence -> <ProLicenseNotice/>, "your licence is not active"
 *   not installed         -> the teaser, pointed at pricing
 *
 * The middle state showed the upsell teaser until it was pointed out that
 * selling Pro to someone who has already installed it reads as though the
 * plugin cannot see its own Pro plugin. It now gets a licence warning instead.
 *
 * @package
 * @since 2.1.1
 */

import { applyFilters } from '@wordpress/hooks';

import ProLicenseNotice from './ProLicenseNotice';
import { isProUnlicensed } from './proPlan';

/**
 * @param {Object}      props
 * @param {string}      props.filter   Hook name Pro injects its panel through.
 * @param {Array}       [props.args]   Extra arguments passed to the filter after
 *                                     the null default (e.g. dateRange, insightsUrl).
 * @param {JSX.Element} [props.teaser] The slot's <ProTeaser/>, already populated
 *                                     with its copy and illustration. Rendered
 *                                     as-is when Pro is absent. Its `title` also
 *                                     names the feature in the licence notice.
 *                                     Omit for a slot that has nothing to upsell.
 * @return {JSX.Element|null} The Pro panel, the licence notice, or the teaser.
 */
const ProGatedTab = ({ filter, teaser = null, args = [] }) => {
	// Licensed: Pro owns this screen. A null from the filter means Pro is
	// present but chose not to render, so show nothing rather than upsell it.
	if (window.thinkrankAdmin?.isPro) {
		return applyFilters(filter, null, ...args) ?? null;
	}

	// Pro is here, the licence is not. Say so. This runs even for a slot with
	// no teaser: a blank panel leaves the owner with nothing to act on, and the
	// reason it is blank is exactly what the notice explains.
	if (isProUnlicensed()) {
		return <ProLicenseNotice feature={teaser?.props?.title} />;
	}

	// Pro is not installed: this is a genuine prospect, so the upsell stands.
	return teaser;
};

export default ProGatedTab;
