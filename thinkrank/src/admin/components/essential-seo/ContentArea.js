import { __ } from '@wordpress/i18n';
import { memo, useMemo } from '@wordpress/element';
import {
	getNavigationStructure,
	getNavigationGroups,
	canSeeSection,
} from './SidebarNavigation';
import { ADVANCED, isVisibleInMode, useUiMode } from '../../utils/uiMode';

// Direct imports — no lazy loading to avoid code splitting
import SiteIdentityTab from './SiteIdentityTab';
import PerformanceTab from './performance';
import AnalyticsTab from './analytics';
import IntegrationsTab from './IntegrationsTab';
import GlobalSEOTab from './GlobalSEOTab';
import AIInsightsTab from './ai-insights/AIInsightsTab';
import SchemaTab from './schema-tab';
import SocialMediaTab from './SocialMediaTab';
import CrawlingAIIndexingTab from './CrawlingAIIndexingTab';
import ImageSEOTab from './ImageSEOTab';
import InstantIndexingTab from './InstantIndexing';
import AuthorArchivesTab from './AuthorArchivesTab';
import RoleManagerTab from './RoleManagerTab';
import InternalLinksTab from './InternalLinksTab';
import ExternalLinksTab from './ExternalLinksTab';
import RedirectionsTab from './RedirectionsTab';
import BrokenLinksTab from './BrokenLinksTab';
import WooCommerceTab from './WooCommerceTab';
import SEOAnalyzerTab from './SEOAnalyzerTab';
import FocusPagesTab from './FocusPagesTab';

const SectionBreadcrumb = memo(({ section, item }) => {
	const navStructure = useMemo(() => getNavigationStructure(), []);
	const navGroups = useMemo(() => getNavigationGroups(), []);
	const sectionData = navStructure.find((s) => s.id === section);
	const itemData = sectionData?.items.find((i) => i.id === item);

	// The sidebar labels the top level by GROUP, not section, and a group can
	// span several sections (e.g. "Link Manager" holds Internal Links,
	// Redirections and Broken Links). Key the breadcrumb off the group so it
	// always matches the heading the user navigated under; fall back to the
	// section title if the section isn't grouped.
	const groupData = navGroups.find((g) => g.sections.includes(section));
	const topTitle = groupData?.title || sectionData?.title;

	return (
		<div className="tr-flex tr-items-center tr-gap-1.5 tr-text-sm tr-min-h-[2.5rem] tr-mb-2">
			<span className="tr-font-semibold tr-text-primary">{topTitle}</span>
			{itemData && (
				<>
					<span className="tr-text-tertiary">›</span>
					<span className="tr-text-tertiary">{itemData.title}</span>
				</>
			)}
		</div>
	);
});

/**
 * A screen Simple mode hides from the menu still opens by its URL (#730);
 * say why it is not in the menu, and offer the way back.
 *
 * @param {Object} props         Props.
 * @param {string} props.section Section id.
 * @param {string} props.item    Item id.
 * @return {JSX.Element|null} The notice, or nothing.
 */
const AdvancedScreenNotice = ({ section, item }) => {
	const { mode, setMode, isSaving } = useUiMode();
	const entry = useMemo(
		() =>
			getNavigationStructure()
				.find((s) => s.id === section)
				?.items.find((i) => i.id === item),
		[section, item]
	);

	if (!entry || isVisibleInMode(entry, mode)) {
		return null;
	}

	return (
		<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-2 tr-mb-3 tr-px-4 tr-py-2.5 tr-rounded-lg tr-border tr-border-secondary tr-bg-brand-bg tr-text-sm tr-text-secondary">
			<span>
				{__(
					'This is an advanced screen. Switch to Advanced to see it in the menu.',
					'thinkrank'
				)}
			</span>
			<button
				type="button"
				className="tr-bg-transparent tr-border-0 tr-p-0 tr-text-brand tr-font-semibold tr-cursor-pointer disabled:tr-opacity-50"
				disabled={isSaving}
				onClick={() => setMode(ADVANCED)}
			>
				{__('Switch to Advanced', 'thinkrank')}
			</button>
		</div>
	);
};

const ContentRouter = ({ section, item, onNavigate }) => {
	const render = (Tab) => (
		<Tab activeSubSection={item} onNavigate={onNavigate} />
	);

	switch (section) {
		case 'seo-analyzer':
			return render(SEOAnalyzerTab);
		case 'focus-pages':
			return render(FocusPagesTab);
		case 'site-identity':
			return render(SiteIdentityTab);
		case 'performance':
			return render(PerformanceTab);
		case 'analytics':
			return render(AnalyticsTab);
		case 'integrations':
			return render(IntegrationsTab);
		case 'global-seo':
			return render(GlobalSEOTab);
		case 'ai-insights':
			return render(AIInsightsTab);
		case 'schema':
			return render(SchemaTab);
		case 'social-media':
			return render(SocialMediaTab);
		case 'crawling-ai-indexing':
			return render(CrawlingAIIndexingTab);
		case 'image-seo':
			return render(ImageSEOTab);
		case 'instant-indexing':
			return render(InstantIndexingTab);
		case 'author-archives':
			return render(AuthorArchivesTab);
		case 'internal-links':
			return render(InternalLinksTab);
		case 'external-links':
			return render(ExternalLinksTab);
		case 'role-manager':
			return render(RoleManagerTab);
		case 'redirections':
			return render(RedirectionsTab);
		case 'broken-links':
			return render(BrokenLinksTab);
		case 'woocommerce':
			return render(WooCommerceTab);
		default:
			return (
				<div className="tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-p-8 tr-text-center">
					<p className="tr-text-tertiary tr-m-0">
						{__(
							'Select a section from the sidebar to get started.',
							'thinkrank'
						)}
					</p>
				</div>
			);
	}
};

const ContentArea = memo(({ activeSection, activeItem, onNavigate }) => {
	if (!activeSection) {
		return (
			<div className="tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-p-8 tr-text-center">
				<p className="tr-text-tertiary tr-m-0">
					{__(
						'Select a section from the sidebar to get started.',
						'thinkrank'
					)}
				</p>
			</div>
		);
	}

	// Never render a section the current role has no capability for, even if it
	// was reached via a stale URL or search. The REST gate protects the data;
	// this keeps the UI honest.
	if (!canSeeSection(activeSection)) {
		return (
			<div className="tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-p-8 tr-text-center">
				<p className="tr-text-tertiary tr-m-0">
					{__(
						'You do not have permission to access this section.',
						'thinkrank'
					)}
				</p>
			</div>
		);
	}

	return (
		<div>
			<SectionBreadcrumb section={activeSection} item={activeItem} />
			<AdvancedScreenNotice section={activeSection} item={activeItem} />
			<ContentRouter
				section={activeSection}
				item={activeItem}
				onNavigate={onNavigate}
			/>
		</div>
	);
});

export default ContentArea;
