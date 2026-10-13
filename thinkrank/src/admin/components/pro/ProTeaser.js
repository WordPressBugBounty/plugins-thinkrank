/**
 * ProTeaser — upsell card for a locked Pro feature.
 *
 * A horizontal marketing card (illustration panel + title/Pro badge, blurb,
 * benefit bullets, and an Upgrade CTA) shown on free-side tabs when the Pro
 * plugin is inactive. One shared component keeps every Pro teaser consistent;
 * per-feature copy and a bespoke illustration come in through props. Styles
 * live in the `tr-pro-teaser` block in styles/components.scss.
 *
 * @package
 * @since 1.18.0
 */

import { __ } from '@wordpress/i18n';

const PRICING_URL = 'https://thinkrank.ai/#pricing';

/**
 * @param {Object}      props
 * @param {JSX.Element} props.illustration  Bespoke feature SVG (see pro-teaser-illustrations.js).
 * @param {string}      props.title         Feature name, e.g. "Smart Redirection Manager".
 * @param {string}      props.description   One or two sentences of value framing.
 * @param {string[]}    props.features      Benefit bullet points.
 * @param {string}      [props.ctaLabel]    CTA text (defaults to "Upgrade Now").
 * @param {string}      [props.ctaUrl]      CTA target (defaults to the pricing page).
 * @param {boolean}     [props.ctaExternal] Whether the CTA leaves WordPress. False for
 *                                          an in-admin destination such as the License
 *                                          page, which should not open a new tab.
 */
const ProTeaser = ({
	illustration,
	title,
	description,
	features = [],
	ctaLabel,
	ctaUrl = PRICING_URL,
	ctaExternal = true,
}) => (
	<div className="tr-pro-teaser">
		<div className="tr-pro-teaser__illustration">{illustration}</div>

		<div className="tr-pro-teaser__content">
			<div className="tr-pro-teaser__heading">
				<h3 className="tr-pro-teaser__title">{title}</h3>
				<span className="tr-pro-teaser__badge">
					{__('Pro', 'thinkrank')}
				</span>
			</div>

			<p className="tr-pro-teaser__description">{description}</p>

			{features.length > 0 && (
				<ul className="tr-pro-teaser__features">
					{features.map((feature) => (
						<li key={feature} className="tr-pro-teaser__feature">
							<span
								aria-hidden="true"
								className="tr-pro-teaser__bullet"
							/>
							<span>{feature}</span>
						</li>
					))}
				</ul>
			)}

			<a
				href={ctaUrl}
				target={ctaExternal ? '_blank' : undefined}
				rel={ctaExternal ? 'noreferrer' : undefined}
				className="tr-pro-teaser__cta"
			>
				{ctaLabel || __('Upgrade Now', 'thinkrank')}
			</a>
		</div>
	</div>
);

export default ProTeaser;
