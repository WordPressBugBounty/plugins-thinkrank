/**
 * Internal Links Tab
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
import { InternalLinksIllustration } from '../pro/pro-teaser-illustrations';

const InternalLinksTab = () => {
	return (
		<ProGatedTab
			filter="thinkrank.internalLinks.tab"
			teaser={
				<ProTeaser
					illustration={<InternalLinksIllustration />}
					title={__('AI Internal Links', 'thinkrank')}
					description={__(
						'Strong internal linking helps readers and search engines find your best content. Let AI suggest the right links so your pages support each other.',
						'thinkrank'
					)}
					features={[
						__(
							'Get AI-powered internal link suggestions based on your content',
							'thinkrank'
						),
						__(
							'Insert relevant links with the perfect anchor text in one click',
							'thinkrank'
						),
						__(
							'Spread link equity and keep visitors exploring your site longer',
							'thinkrank'
						),
					]}
				/>
			}
		/>
	);
};

export default InternalLinksTab;
