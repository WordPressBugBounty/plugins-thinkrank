/**
 * Step 7 — Ready (completion). Reaching this step completes the wizard.
 * The header (badge + heading + subtitle) is rendered by StepLayout; this body
 * renders the post-setup status grid, the offer of a free onboarding call
 * and the final actions.
 *
 * @package
 * @since 1.0.0
 */

import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { completeWizard } from '../api';
import logger from '../../../shared/utils/logger';

const CALENDLY_URL = 'https://calendly.com/thinkrank/onboarding';

const HELP_BENEFITS = [
	__('A 1-on-1 walkthrough of your ThinkRank setup', 'thinkrank'),
	__('A tailored SEO action plan for your site', 'thinkrank'),
	__('Answers to all your questions, free and no pressure', 'thinkrank'),
];

const IconCalendar = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<rect x="3" y="4.5" width="18" height="16" rx="2.5" />
		<path d="M3 9h18M8 3v3M16 3v3" />
		<path d="M8.5 14.5l2.2 2.2 4.3-4.6" />
	</svg>
);

const IconCheck = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="3"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

const IconArrow = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<line x1="5" y1="12" x2="19" y2="12" />
		<polyline points="12 5 19 12 12 19" />
	</svg>
);

/**
 * The offer of a free onboarding call, on the last screen of the wizard.
 */
function HelpCard() {
	return (
		<section className="tr-wizard__help">
			<span className="tr-wizard__help-icon">
				<IconCalendar />
			</span>
			<div className="tr-wizard__help-body">
				<div className="tr-wizard__help-head">
					<h3 className="tr-wizard__help-title">
						{__('Need a hand getting started?', 'thinkrank')}
					</h3>
					<span className="tr-wizard__help-pill">
						{__('Free · 30-minute onboarding', 'thinkrank')}
					</span>
				</div>
				<p className="tr-wizard__help-desc">
					{__(
						'Book a free onboarding call and our team will help you get the most out of ThinkRank.',
						'thinkrank'
					)}
				</p>
				<ul className="tr-wizard__help-benefits">
					{HELP_BENEFITS.map((benefit) => (
						<li key={benefit} className="tr-wizard__help-benefit">
							<span className="tr-wizard__help-check">
								<IconCheck />
							</span>
							<span>{benefit}</span>
						</li>
					))}
				</ul>
			</div>
			<Button
				variant="secondary"
				href={CALENDLY_URL}
				target="_blank"
				rel="noopener noreferrer"
				className="tr-wizard__help-cta"
			>
				{__('Schedule a call', 'thinkrank')}
				<IconArrow />
			</Button>
		</section>
	);
}

/**
 * Build the post-setup status list from what the user actually completed.
 *
 * Skipped steps contribute nothing. The Google item only appears when an
 * account was genuinely connected (not merely when the Analytics step was
 * passed through).
 *
 * @param {Object}   args
 * @param {string[]} args.completed       Slugs of steps finished via Continue/Save.
 * @param {boolean}  args.googleConnected Whether a Google account was connected.
 * @param {number}   args.ecosystemCount  Number of plugins selected for install.
 * @return {Array<{title: string, detail: string}>} Items to render.
 */
function buildStatusItems({ completed, googleConnected, ecosystemCount }) {
	const done = (slug) => completed.includes(slug);
	const items = [];

	if (done('migration')) {
		items.push({
			title: __('SEO Data Imported', 'thinkrank'),
			detail: __(
				'Your existing SEO settings were imported and applied to ThinkRank.',
				'thinkrank'
			),
		});
	}

	if (done('site-setup')) {
		items.push({
			title: __('Generating Metadata', 'thinkrank'),
			detail: __(
				'ThinkRank is preparing optimized titles and descriptions across your site.',
				'thinkrank'
			),
		});
		items.push({
			title: __('Sitemap Ready', 'thinkrank'),
			detail: __(
				'An XML sitemap is automatically created and updated to help search engines discover and index your content.',
				'thinkrank'
			),
		});
	}

	if (googleConnected) {
		items.push({
			title: __('Google Connected', 'thinkrank'),
			detail: __(
				'Search Console data, including impressions, clicks and keywords, is now available inside WordPress.',
				'thinkrank'
			),
		});
	}

	if (done('ecosystem') && ecosystemCount > 0) {
		items.push({
			title: __('Recommended Plugins Installed', 'thinkrank'),
			detail: __(
				'The plugins you selected were installed and activated on your site.',
				'thinkrank'
			),
		});
	}

	if (done('social')) {
		items.push({
			title: __('Social Profiles Added', 'thinkrank'),
			detail: __(
				'Your social profiles were saved and can appear in search knowledge panels.',
				'thinkrank'
			),
		});
	}

	return items;
}

export default function Step7Ready({
	dashboardUrl,
	sitemapUrl,
	completed = [],
	googleConnected = false,
	selected = [],
}) {
	const statusItems = buildStatusItems({
		completed,
		googleConnected,
		ecosystemCount: selected.length,
	});
	const [redirect, setRedirect] = useState(dashboardUrl);
	const [sitemapLink, setSitemapLink] = useState(sitemapUrl);

	// Reaching this step marks the wizard complete (idempotent on the server).
	// The response also carries the sitemap the site publishes right now, which
	// the page-load config can't know: it is written when Site Setup is saved,
	// and a migration can move it to an index file with a different name.
	useEffect(() => {
		completeWizard()
			.then((res) => {
				if (res && res.redirect) {
					setRedirect(res.redirect);
				}
				if (res && typeof res.sitemap_url === 'string') {
					setSitemapLink(res.sitemap_url);
				}
			})
			.catch((error) =>
				logger.error('Failed to mark wizard complete', error)
			);
	}, []);

	return (
		<div className="tr-wizard__ready">
			<ul className="tr-wizard__status-grid">
				{statusItems.map((item) => (
					<li key={item.title} className="tr-wizard__status-item">
						<span
							className="tr-wizard__status-badge"
							aria-hidden="true"
						>
							✓
						</span>
						<div className="tr-wizard__status-text">
							<span className="tr-wizard__status-title">
								{item.title}
							</span>
							<span className="tr-wizard__status-detail">
								{item.detail}
							</span>
						</div>
					</li>
				))}
			</ul>

			<HelpCard />

			<div className="tr-wizard__footer tr-wizard__footer--ready">
				<div className="tr-wizard__footer-left" />
				<div className="tr-wizard__footer-right">
					{sitemapLink && (
						<Button
							variant="secondary"
							href={sitemapLink}
							target="_blank"
							rel="noopener noreferrer"
						>
							{__('View Sitemap', 'thinkrank')}
						</Button>
					)}
					<Button variant="primary" href={redirect}>
						{__('Go to ThinkRank Dashboard', 'thinkrank')}
					</Button>
				</div>
			</div>
		</div>
	);
}
