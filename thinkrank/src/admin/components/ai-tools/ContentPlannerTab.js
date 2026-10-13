/**
 * Content Planner Tab Component
 *
 * Wrapper component for ContentBriefGenerator in AI Tools page
 *
 * @package
 * @since 1.0.0
 */

import ContentBriefGenerator from '../content-brief/ContentBriefGenerator';

/**
 * Content Planner Tab Component
 *
 * This is a thin wrapper around the existing ContentBriefGenerator component
 * to maintain the same functionality while integrating it into the AI Tools tabbed interface
 */
const ContentPlannerTab = () => {
	return <ContentBriefGenerator />;
};

export default ContentPlannerTab;
