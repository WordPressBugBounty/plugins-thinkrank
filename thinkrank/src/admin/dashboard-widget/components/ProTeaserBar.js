/**
 * Compact Pro teaser bar.
 *
 * A single-line upsell shown at the bottom of the connected widget for
 * non-Pro users: ThinkRank mark + value line + "Upgrade" link.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import ThinkRankLogo from '../../../metabox/components/ThinkRankLogo';

export default function ProTeaserBar({ config }) {
	if (config.isPro) {
		return null;
	}

	// Pro is here, the licence is not. Pointing at pricing tells a paying
	// customer to buy what they already own and buries the one actionable
	// fact — the key has not been entered.
	if (config.isProInstalled) {
		return (
			<div className="thinkrank-dashboard-widget__teaser">
				<ThinkRankLogo
					size={24}
					className="thinkrank-dashboard-widget__teaser-mark"
				/>
				<span className="thinkrank-dashboard-widget__teaser-text">
					{__(
						'Your ThinkRank Pro licence is not active yet',
						'thinkrank'
					)}
				</span>
				<a
					className="thinkrank-dashboard-widget__teaser-cta"
					href={config.licenseUrl || '#'}
				>
					{__('Activate license', 'thinkrank')}
				</a>
			</div>
		);
	}

	const upgradeUrl = config.upgradeUrl || 'https://thinkrank.ai/#pricing';

	return (
		<div className="thinkrank-dashboard-widget__teaser">
			<ThinkRankLogo
				size={24}
				className="thinkrank-dashboard-widget__teaser-mark"
			/>
			<span className="thinkrank-dashboard-widget__teaser-text">
				{__(
					'Turn these numbers into action with AI insights and ranking alerts',
					'thinkrank'
				)}
			</span>
			<a
				className="thinkrank-dashboard-widget__teaser-cta"
				href={upgradeUrl}
				target="_blank"
				rel="noopener noreferrer"
			>
				{__('Upgrade', 'thinkrank')}
			</a>
		</div>
	);
}
