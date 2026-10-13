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
import InstantIndexingSettings from './instant-indexing/Settings';
import SubmitURLs from './instant-indexing/SubmitURLs';
import History from './instant-indexing/History';

/**
 * Crawling & AI Indexing Tab Component
 * @param root0
 * @param root0.activeSubSection
 * @param root0.onNavigate
 */
const InstantIndexingTab = ({
	activeSubSection = 'instant-indexing-settings',
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
		<div className="thinkrank-instant-indexing-tab">
			{/* Render content based on active sub-section */}
			{(() => {
				switch (activeSubSection) {
					case 'instant-indexing-settings':
						return (
							<InstantIndexingSettings onNavigate={onNavigate} />
						);
					case 'instant-indexing-submit-urls':
						return <SubmitURLs onNavigate={onNavigate} />;
					case 'instant-indexing-history':
						return <History onNavigate={onNavigate} />;
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

export default InstantIndexingTab;
