/**
 * Empty state — shown before Google Search Console is connected.
 *
 * The CTA starts the Google Search Console OAuth flow directly (a nonce-signed
 * admin-post link built by the proxy).
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import InsightsIllustration from './InsightsIllustration';

export default function EmptyState({ config }) {
	// Start the Google Search Console OAuth flow directly. Fall back to the
	// Essential SEO screen if the connect URL is unavailable.
	const connectUrl = config.connectUrl || config.settingsUrl || '';

	return (
		<div className="thinkrank-dashboard-widget__empty">
			<div className="thinkrank-dashboard-widget__illustration">
				<InsightsIllustration />
			</div>

			<h3 className="thinkrank-dashboard-widget__title">
				{__(
					'Unlock SEO Insights Using Google Search Console',
					'thinkrank'
				)}
			</h3>
			<p className="thinkrank-dashboard-widget__subtext">
				{__(
					'Connect Google Search Console to gain search insights and monitor your growth.',
					'thinkrank'
				)}
			</p>

			<a className="thinkrank-dashboard-widget__cta" href={connectUrl}>
				{__('Connect to Google Search Console', 'thinkrank')}
			</a>
		</div>
	);
}
