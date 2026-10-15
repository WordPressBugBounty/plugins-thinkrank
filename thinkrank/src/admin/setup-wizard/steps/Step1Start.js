/**
 * Step 1 — Start (intro). Non-saving welcome screen with a centered layout:
 * a decorative analytics preview and a usage-tracking consent card offering
 * "Allow & Continue" or "Skip".
 *
 * Both buttons move on to the next step; only "Allow & Continue" grants
 * tracking consent, so the choice is explicit and the default sends nothing
 * (WordPress.org Guideline 7).
 *
 * @package
 * @since 1.0.0
 */

import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import welcomePreview from '../../../shared/assets/thinkrank-hero.webp';

export default function Step1Start({ onStart, isSaving }) {
	const [showDetails, setShowDetails] = useState(false);
	// Which button was pressed, so only that one shows the busy state.
	const [choice, setChoice] = useState(null);

	const start = (consent) => {
		setChoice(consent);
		onStart(consent);
	};

	return (
		<div className="tr-wizard__start">
			<div className="tr-wizard__start-preview" aria-hidden="true">
				<img
					className="tr-wizard__start-preview-img"
					src={welcomePreview}
					alt=""
					width="380"
					height="270"
				/>
			</div>

			<div className="tr-wizard__start-consent">
				<p className="tr-wizard__start-usage">
					{__(
						'Help improve ThinkRank by sharing usage data with us. Nothing is sent unless you choose “Allow & Continue”.',
						'thinkrank'
					)}{' '}
					{!showDetails && (
						<button
							type="button"
							className="tr-wizard__start-collect-link"
							onClick={() => setShowDetails(true)}
						>
							{__('Find out what we collect.', 'thinkrank')}
						</button>
					)}
				</p>
				{showDetails && (
					<p className="tr-wizard__start-usage">
						{__(
							'If you choose “Allow & Continue”, ThinkRank sends your site URL and name, your admin email address, WordPress, PHP and server versions, active and inactive plugins, active theme, and which ThinkRank features you use to our usage-tracking service (WP Insights). We use it to keep ThinkRank compatible with popular plugins and themes, and may email you ThinkRank news and offers.',
							'thinkrank'
						)}
					</p>
				)}

				<div className="tr-wizard__start-actions">
					<Button
						variant="secondary"
						onClick={() => start(true)}
						isBusy={isSaving && choice === true}
						disabled={isSaving}
					>
						{__('Allow & Continue', 'thinkrank')}
					</Button>
					<Button
						variant="secondary"
						onClick={() => start(false)}
						isBusy={isSaving && choice === false}
						disabled={isSaving}
					>
						{__('Skip', 'thinkrank')}
					</Button>
				</div>
			</div>
		</div>
	);
}
