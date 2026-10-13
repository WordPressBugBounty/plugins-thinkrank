/**
 * Essential SEO → Focus Pages
 *
 * A curated shortlist of important pages, each diagnosed across the dimensions
 * ThinkRank can actually source — on-page score, Search Console, Analytics,
 * tracked keywords, index status, decay and internal links. The board, the
 * multi-page refresh and the history are Pro (thinkrank-pro#164); the free
 * plugin owns the navigation entry and the gate.
 */
import { __ } from '@wordpress/i18n';

import ProGatedTab from '../pro/ProGatedTab';
import ProTeaser from '../pro/ProTeaser';
import { FocusPagesIllustration } from '../pro/pro-teaser-illustrations';

const FocusPagesTab = () => (
	<div className="tr-flex tr-flex-col tr-gap-4">
		<ProGatedTab
			filter="thinkrank.focusPages.tab"
			teaser={
				<ProTeaser
					illustration={<FocusPagesIllustration />}
					title={__('Focus Pages', 'thinkrank')}
					description={__(
						'ThinkRank can tell you a page scores 62. It cannot yet tell you why that page is not ranking the answer is spread across the SEO score, Search Console, Analytics, your tracked keywords and the index report. Focus Pages joins them, per page, on one screen.',
						'thinkrank'
					)}
					features={[
						__(
							'Pick the handful of pages that matter and watch them together',
							'thinkrank'
						),
						__(
							'Each page diagnosed dimension by dimension, from real data only',
							'thinkrank'
						),
						__(
							'Every failing dimension links to the screen that fixes it',
							'thinkrank'
						),
						__(
							'Refreshed on a schedule, so quota-limited APIs are never called on page load',
							'thinkrank'
						),
					]}
				/>
			}
		/>
	</div>
);

export default FocusPagesTab;
