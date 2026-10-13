/**
 * Redirections Tab
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
import { RedirectionsIllustration } from '../pro/pro-teaser-illustrations';

const RedirectionsTab = () => {
	return (
		<ProGatedTab
			filter="thinkrank.redirections.tab"
			teaser={
				<ProTeaser
					illustration={<RedirectionsIllustration />}
					title={__('Smart Redirection Manager', 'thinkrank')}
					description={__(
						'Never lose visitors or rankings when your URLs change. The Redirection Manager makes sure old links still lead to the right place, keeping your traffic safe.',
						'thinkrank'
					)}
					features={[
						__(
							'Create 301/302 redirects instantly for a smooth user experience',
							'thinkrank'
						),
						__(
							'Serve 410/451 responses for removed or restricted content',
							'thinkrank'
						),
						__(
							'Automatically log every 404 and fix broken URLs in one click',
							'thinkrank'
						),
					]}
				/>
			}
		/>
	);
};

export default RedirectionsTab;
