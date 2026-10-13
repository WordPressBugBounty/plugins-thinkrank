/**
 * WooCommerce Tab
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
import { WooCommerceIllustration } from '../pro/pro-teaser-illustrations';

const WooCommerceTab = () => {
	return (
		<ProGatedTab
			filter="thinkrank.woocommerce.tab"
			teaser={
				<ProTeaser
					illustration={<WooCommerceIllustration />}
					title={__('WooCommerce SEO', 'thinkrank')}
					description={__(
						'Help shoppers and search engines understand your products. Rich product data means better listings and more clicks from Google Shopping and social.',
						'thinkrank'
					)}
					features={[
						__(
							'Add GTIN / MPN / ISBN fields to every product and variation',
							'thinkrank'
						),
						__(
							'Enrich Product structured data with those identifiers',
							'thinkrank'
						),
						__(
							'Show an AggregateOffer price range for variable products',
							'thinkrank'
						),
						__(
							'Add product Open Graph (price, availability) for social sharing',
							'thinkrank'
						),
					]}
				/>
			}
		/>
	);
};

export default WooCommerceTab;
