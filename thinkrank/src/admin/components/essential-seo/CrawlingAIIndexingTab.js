/**
 * Crawling & AI Indexing Tab Component
 *
 * Main tab component that consolidates Robots.txt, XML Sitemap, and LLMs.txt functionality
 * following the Schema Manager pattern with separate component files.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SkeletonCard } from '../ui';

// Import sub-components (following Schema tab pattern)
import RobotsManagement from './crawling-ai-indexing/RobotsManagement';
import SitemapGeneration from './crawling-ai-indexing/sitemap';
import LLMsAIIndexing from './crawling-ai-indexing/LLMsAIIndexing';
import GlobalRobotMeta from './crawling-ai-indexing/GlobalRobotMeta';
import ProGatedTab from '../pro/ProGatedTab';
import ProTeaser from '../pro/ProTeaser';
import {
	PublisherSitemapsIllustration,
	MarkdownForAiIllustration,
	AiUsagePolicyIllustration,
} from '../pro/pro-teaser-illustrations';

/**
 * Crawling & AI Indexing Tab Component
 * @param root0
 * @param root0.activeSubSection
 * @param root0.onNavigate
 */
const CrawlingAIIndexingTab = ({
	activeSubSection = 'robots-management',
	onNavigate,
}) => {
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		// Initialize the tab
		setIsLoading(false);
	}, []);

	/**
	 * Get optimization button configuration based on active sub-section
	 * Following Site Identity getOptimizationButtonConfig pattern
	 */
	const getOptimizationButtonConfig = () => {
		// Each sub-component will handle its own optimization buttons
		// This is just for the main tab toggle if needed
		return [];
	};

	if (isLoading) {
		return (
			<div className="thinkrank-loading-container">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	return (
		<div className="thinkrank-crawling-ai-indexing-tab">
			{/* Render content based on active sub-section */}
			{(() => {
				switch (activeSubSection) {
					case 'robots-management':
						return <RobotsManagement onNavigate={onNavigate} />;
					case 'xml-sitemap':
						return <SitemapGeneration onNavigate={onNavigate} />;
					case 'llms-ai-indexing':
						return <LLMsAIIndexing onNavigate={onNavigate} />;
					case 'global-robot-meta':
						return <GlobalRobotMeta onNavigate={onNavigate} />;
					case 'publisher-sitemaps':
						return (
							<ProGatedTab
								filter="thinkrank.sitemap.publisherSitemapsTab"
								teaser={
									<ProTeaser
										illustration={
											<PublisherSitemapsIllustration />
										}
										title={__(
											'Publisher Sitemaps',
											'thinkrank'
										)}
										description={__(
											'Publish news or video content? Dedicated News and Video sitemaps help Google surface your content in Google News and video search results.',
											'thinkrank'
										)}
										features={[
											__(
												'Generate a Google News sitemap for your latest articles',
												'thinkrank'
											),
											__(
												'Generate a Video sitemap so your videos get indexed',
												'thinkrank'
											),
											__(
												'Keep both updated automatically as you publish',
												'thinkrank'
											),
										]}
									/>
								}
							/>
						);
					case 'markdown-for-ai':
						return (
							<ProGatedTab
								filter="thinkrank.crawling.markdownForAiTab"
								teaser={
									<ProTeaser
										illustration={
											<MarkdownForAiIllustration />
										}
										title={__(
											'Markdown for AI',
											'thinkrank'
										)}
										description={__(
											'AI agents that fetch your pages have to dig your content out of a full HTML document. Serve them a clean Markdown copy of the same URL instead, while visitors keep the normal page.',
											'thinkrank'
										)}
										features={[
											__(
												'Answer the same URL with Markdown when an agent asks for it',
												'thinkrank'
											),
											__(
												'Choose which post types serve Markdown',
												'thinkrank'
											),
											__(
												'Your HTML page stays canonical, so nothing about your SEO changes',
												'thinkrank'
											),
										]}
									/>
								}
							/>
						);
					case 'ai-usage-policy':
						return (
							<ProGatedTab
								filter="thinkrank.crawling.aiUsagePolicyTab"
								teaser={
									<ProTeaser
										illustration={
											<AiUsagePolicyIllustration />
										}
										title={__(
											'AI Usage Policy',
											'thinkrank'
										)}
										description={__(
											'Your llms.txt describes what your site is about, but says nothing about what AI companies may do with it. Publish a usage policy alongside it so crawlers read your terms before they train on your work.',
											'thinkrank'
										)}
										features={[
											__(
												'Allow or disallow model training on your content',
												'thinkrank'
											),
											__(
												'Decide separately about summarization and embedding',
												'thinkrank'
											),
											__(
												'Require attribution when an assistant quotes you',
												'thinkrank'
											),
											__(
												'Published at the top of llms.txt, where crawlers look first',
												'thinkrank'
											),
										]}
									/>
								}
							/>
						);
					case 'llms-full':
						return (
							<ProGatedTab
								filter="thinkrank.crawling.llmsFullTab"
								teaser={
									<ProTeaser
										illustration={
											<MarkdownForAiIllustration />
										}
										title={__('llms-full.txt', 'thinkrank')}
										description={__(
											'llms.txt tells AI agents what your site is. llms-full.txt gives them the content itself: every published entry as clean Markdown in one file, so an agent can read your whole site in a single request.',
											'thinkrank'
										)}
										features={[
											__(
												'Your published posts and pages as one Markdown document',
												'thinkrank'
											),
											__(
												'Rebuilt in the background daily and after you publish',
												'thinkrank'
											),
											__(
												'Carries your AI Usage Policy at the top',
												'thinkrank'
											),
										]}
									/>
								}
							/>
						);
					default:
						return (
							<div>
								<p>
									{__(
										'Please select a sub-section from the navigation.',
										'thinkrank'
									)}
								</p>
							</div>
						);
				}
			})()}
		</div>
	);
};

export default CrawlingAIIndexingTab;
