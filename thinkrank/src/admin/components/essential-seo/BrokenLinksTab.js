/**
 * Broken Links Tab
 *
 * Rendered through ProGatedTab: the Pro panel when licensed, a
 * licence-activation teaser when Pro is installed but unlicensed, and the
 * upsell teaser when Pro is absent.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

import ProGatedTab from '../pro/ProGatedTab';
import ProTeaser from '../pro/ProTeaser';
import { BrokenLinksIllustration } from '../pro/pro-teaser-illustrations';

const BrokenLinksTab = () => {
	return (
		<ProGatedTab
			filter="thinkrank.brokenLinks.tab"
			teaser={
				<ProTeaser
					illustration={<BrokenLinksIllustration />}
					title={__('Broken Link Checker', 'thinkrank')}
					description={__(
						'Broken links frustrate visitors and hurt your SEO. The Broken Link Checker finds them for you so every link on your site keeps working.',
						'thinkrank'
					)}
					features={[
						__(
							'Automatically scan posts and pages for broken internal and external links',
							'thinkrank'
						),
						__(
							'See the exact status code and anchor text for every broken link',
							'thinkrank'
						),
						__(
							'Fix or unlink issues in one click, right from the dashboard',
							'thinkrank'
						),
					]}
				/>
			}
		/>
	);
};

export default BrokenLinksTab;
