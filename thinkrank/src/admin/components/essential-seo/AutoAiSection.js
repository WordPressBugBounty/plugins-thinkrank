/**
 * Auto AI — automatic metadata on publish (Pro).
 *
 * Lives under Bulk SEO Optimization. That section is where AI writes the
 * metadata for the content a site already has; this is the same job for
 * everything published from now on, so the two belong side by side. It used
 * to be a tab inside AI Insights, which only shared the word "AI" — that
 * section reports on AI platforms as traffic sources, this one changes what
 * the site publishes (#707).
 *
 * The body is a ThinkRank Pro slot. The filter keeps the name it had inside
 * AI Insights so a free plugin newer than Pro 2.7.0 still finds the panel that
 * release registers; renaming it is a coordinated free + Pro change.
 *
 * @package
 * @since 2.7.0
 */

import { __ } from '@wordpress/i18n';
import { Card } from '../ui';
import SectionHeader from '../common/SectionHeader';
import ProTeaser from '../pro/ProTeaser';
import ProGatedTab from '../pro/ProGatedTab';
import { InternalLinksIllustration } from '../pro/pro-teaser-illustrations';

export const AUTO_AI_FILTER = 'thinkrank.aiInsights.autoAiTab';

const AutoAiSection = () => (
	<Card padding={false}>
		<SectionHeader
			title={__('Auto AI', 'thinkrank')}
			description={__(
				'Write the SEO title, meta description and focus keyword in the background for every post published without them.',
				'thinkrank'
			)}
		/>
		<div className="tr-p-6">
			<ProGatedTab
				filter={AUTO_AI_FILTER}
				teaser={
					<ProTeaser
						illustration={<InternalLinksIllustration />}
						title={__('Auto AI metadata', 'thinkrank')}
						description={__(
							'Publish and move on. ThinkRank Pro writes the SEO title, meta description and focus keyword in the background for every post published without them, using your own AI provider key.',
							'thinkrank'
						)}
						features={[
							__(
								'Runs automatically on first publish, in the background',
								'thinkrank'
							),
							__(
								'Fills only what is empty and never overwrites your own copy',
								'thinkrank'
							),
							__(
								'Choose exactly which post types it applies to',
								'thinkrank'
							),
						]}
					/>
				}
			/>
		</div>
	</Card>
);

export default AutoAiSection;
