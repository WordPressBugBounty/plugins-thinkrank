/**
 * Essential SEO → SEO Analyzer → Site Audit
 *
 * Full-page home for the site-wide SEO Analyzer. Reuses the self-contained
 * SiteHealthAudit widget (score gauge + category breakdown + expandable
 * fixes + Re-analyze), which fetches its own data from
 * /thinkrank/v1/seo-analyzer.
 *
 * Below it sits the score history: free keeps the latest run only, so the
 * trend and the run comparison are Pro's (thinkrank-pro#161). The slot is a
 * ProGatedTab like every other paid panel, so an unlicensed install gets the
 * licence notice rather than a blank space.
 */
import { __ } from '@wordpress/i18n';

import SiteHealthAudit from '../dashboard/SiteHealthAudit';
import ProGatedTab from '../pro/ProGatedTab';
import ProTeaser from '../pro/ProTeaser';
import { AuditHistoryIllustration } from '../pro/pro-teaser-illustrations';

const SEOAnalyzerTab = () => (
	<div className="tr-flex tr-flex-col tr-gap-4">
		<SiteHealthAudit compact={false} />
		<ProGatedTab
			filter="thinkrank.seoAnalyzer.historyPanel"
			teaser={
				<ProTeaser
					illustration={<AuditHistoryIllustration />}
					title={__('Audit history', 'thinkrank')}
					description={__(
						'Re-running the audit replaces the old result, so a score on its own never tells you whether the site improved. Pro keeps every run and shows what changed between any two of them.',
						'thinkrank'
					)}
					features={[
						__(
							'A score trend across your recent audits',
							'thinkrank'
						),
						__(
							'Compare two runs side by side, check by check',
							'thinkrank'
						),
						__(
							'See exactly which checks improved and which regressed',
							'thinkrank'
						),
					]}
				/>
			}
		/>
	</div>
);

export default SEOAnalyzerTab;
