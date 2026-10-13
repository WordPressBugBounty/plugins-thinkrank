/**
 * Step 4 — Analytics. Reuses the existing Google OAuth connect flow.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import ConnectGoogleButton from '../../components/essential-seo/ConnectGoogleButton';
import googleIcon from '../assets/google-g.svg';

export default function Step4Analytics({ googleConnected, onGoogleConnect }) {
	const connected = !!googleConnected;

	return (
		<div className="tr-wizard__rows">
			<div className="tr-wizard__row-card">
				<div className="tr-wizard__row-text">
					<span className="tr-wizard__row-title">
						{__('Google Search Console', 'thinkrank')}
					</span>
					<span className="tr-wizard__row-detail">
						{__(
							'View impressions, clicks, and keyword data.',
							'thinkrank'
						)}
					</span>
				</div>
				{connected ? (
					<span className="tr-wizard__badge is-success">
						{__('Connected', 'thinkrank')}
					</span>
				) : (
					<ConnectGoogleButton
						onConnect={onGoogleConnect}
						label={__('Connect with Google', 'thinkrank')}
						variant="secondary"
						icon={googleIcon}
						className="tr-wizard__google-btn"
					/>
				)}
			</div>
		</div>
	);
}
