/**
 * ProLicenseNotice — what a Pro-gated slot shows when Pro is installed but the
 * licence is not activated.
 *
 * This state used to render the marketing <ProTeaser/> with its CTA swapped to
 * the License page (#534). That was misleading: the visitor has already bought
 * and installed Pro, so selling it to them again ("Upgrade Now", benefit
 * bullets, a Pro badge on a feature they own) reads as though the plugin does
 * not know it is there. The honest message is that the licence is missing.
 *
 * The three states each Pro slot distinguishes:
 *
 *   licensed              -> the Pro panel, via the filter
 *   installed, no licence -> THIS notice
 *   not installed         -> <ProTeaser/>, pointed at pricing
 *
 * Deliberately mirrors the dashboard's LicenseActivatePrompt (ProSlot.js) —
 * same amber key tile, same "Not activated" chip, same "Activate license"
 * wording — so the two surfaces read as one message about one licence.
 *
 * @package
 * @since 2.1.1
 */

import { __, sprintf } from '@wordpress/i18n';

import { licensePageUrl } from './proPlan';

const KeyIcon = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 20 20"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M12.5 7.5a2.5 2.5 0 1 1 5 0 2.5 2.5 0 0 1-5 0Zm0 0L8.25 11.75m0 0L10 13.5l-1.75 1.75L6.5 13.5m1.75-1.75L6.5 10 2.75 13.75 4.5 15.5l.75-.75"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/**
 * @param {Object} props
 * @param {string} [props.feature] Name of the feature the visitor was trying
 *                                 to reach, e.g. "Google Analytics". Used to
 *                                 say which screen is locked; a generic line
 *                                 stands in when a slot has no name to give.
 * @return {JSX.Element} The licence-missing notice.
 */
const ProLicenseNotice = ({ feature }) => (
	<div className="tr-pro-license-notice">
		<div className="tr-pro-license-notice__icon">
			<KeyIcon />
		</div>

		<div className="tr-pro-license-notice__content">
			<div className="tr-pro-license-notice__heading">
				<h3 className="tr-pro-license-notice__title">
					{feature
						? sprintf(
								/* translators: %s: name of the Pro feature, e.g. "Google Analytics". */
								__(
									'%s needs an activated license',
									'thinkrank'
								),
								feature
							)
						: __(
								'This feature needs an activated license',
								'thinkrank'
							)}
				</h3>
				<span className="tr-pro-license-notice__badge">
					<span
						aria-hidden="true"
						className="tr-pro-license-notice__dot"
					/>
					{__('Not activated', 'thinkrank')}
				</span>
			</div>

			<p className="tr-pro-license-notice__description">
				{__(
					'ThinkRank Pro is installed on this site, but its license has not been activated yet. Add your license key to unlock this feature.',
					'thinkrank'
				)}
			</p>

			<a href={licensePageUrl()} className="tr-pro-license-notice__cta">
				{__('Activate license', 'thinkrank')}
			</a>
		</div>
	</div>
);

export default ProLicenseNotice;
