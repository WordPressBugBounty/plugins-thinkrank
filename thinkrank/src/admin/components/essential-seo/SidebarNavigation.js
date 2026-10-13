import {
	useState,
	useEffect,
	memo,
	useCallback,
	useMemo,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon } from '@wordpress/components';
import {
	home,
	people,
	plugins,
	chartBar,
	update,
	globe,
	image,
	code,
	share,
	pages,
	upload,
	link,
	redo,
	linkOff,
	store,
	trendingUp,
	postList,
	pin,
} from '@wordpress/icons';
import classnames from 'classnames';

import { isVisibleInMode, useUiMode } from '../../utils/uiMode';

const getGlobalSEOItems = () => {
	const postTypes = window.thinkrankAdmin?.postTypes || [];
	return [
		...postTypes.map((pt) => ({
			id: `${pt.slug}-seo`,
			title: pt.plural_name || pt.label,
		})),
		// Every post's snippet in one table, filtered by problem — the
		// per-post-type screens above set the templates, this is where the
		// posts that need their own values get them (#727).
		{ id: 'bulk-snippets', title: __('Bulk Snippets', 'thinkrank') },
		// Which published pages are too short to rank or be cited, across
		// every post type, with a threshold each (#565).
		{ id: 'thin-content', title: __('Thin Content', 'thinkrank') },
		// Metadata on publish: the per-post-type screens above cover the
		// backlog, this covers everything published from now on (#707).
		{ id: 'auto-ai', title: __('Auto AI', 'thinkrank'), pro: true },
		// The matrix covers every content type at once, taxonomies, archives,
		// search and 404 included (#660). It led this list until it was moved
		// here: the per-post-type screens and Bulk Snippets are the day-to-day
		// entry points, and the matrix is the cross-cutting override sheet you
		// reach for afterwards, so it reads better as the last item than as the
		// first thing between the section header and Posts.
		{
			id: 'content-type-matrix',
			title: __('Content Type Matrix', 'thinkrank'),
		},
	];
};

const SECTION_ICONS = {
	'seo-analyzer': postList,
	'focus-pages': pin,
	'site-identity': home,
	'author-archives': people,
	integrations: plugins,
	analytics: chartBar,
	performance: update,
	'global-seo': pages,
	'ai-insights': trendingUp,
	'image-seo': image,
	schema: code,
	'social-media': share,
	'crawling-ai-indexing': globe,
	'instant-indexing': upload,
	'internal-links': link,
	'role-manager': people,
	redirections: redo,
	'broken-links': linkOff,
	woocommerce: store,
};

/**
 * What Simple mode hides from the menu and the search (#730): section id =>
 * `true` for the whole section, or the item ids to hide. Everything not listed
 * stays in Simple, so a new screen shows up unless someone decides otherwise.
 *
 * Simple keeps what a site owner needs day to day — site identity, the audit,
 * the analytics dashboard and keywords, AI traffic, Bulk Snippets and Auto AI,
 * image SEO, social previews, schema settings and types, redirects and broken
 * links, the sitemap, instant indexing settings, and the Google connection
 * the analytics screens ask for. Every hidden screen still opens by its URL.
 *
 * @type {Object<string, true|string[]>}
 */
const ADVANCED_ENTRIES = {
	'focus-pages': true,
	performance: true,
	'author-archives': true,
	woocommerce: true,
	'role-manager': true,
	'internal-links': true,
	'external-links': true,
	analytics: [
		'email-reporting',
		'rank-tracker',
		'traffic-recovery',
		'index-status',
	],
	// Every per-post-type screen, plus the two cross-cutting ones.
	'global-seo': (items) =>
		items
			.map((item) => item.id)
			.filter((id) => !['bulk-snippets', 'auto-ai'].includes(id)),
	schema: [
		'organization',
		'local-business',
		'person',
		'website',
		'custom-schema',
		'field-mapping',
		'deployment',
	],
	'crawling-ai-indexing': [
		'global-robot-meta',
		'robots-management',
		'publisher-sitemaps',
		'llms-ai-indexing',
		'markdown-for-ai',
		'ai-usage-policy',
		'llms-full',
	],
	'instant-indexing': [
		'instant-indexing-submit-urls',
		'instant-indexing-history',
	],
};

/**
 * Mark the items Simple mode hides with `advanced: true`.
 *
 * @param {Array} structure Sections with their items.
 * @return {Array} The same sections, items flagged.
 */
const withModeFlags = (structure) =>
	structure.map((section) => {
		const rule = ADVANCED_ENTRIES[section.id];
		if (!rule) {
			return section;
		}
		let hidden = rule;
		if (typeof rule === 'function') {
			hidden = rule(section.items);
		}

		return {
			...section,
			items: section.items.map((item) =>
				true === hidden || hidden.includes(item.id)
					? { ...item, advanced: true }
					: item
			),
		};
	});

const getBaseStructure = () => [
	{
		id: 'site-identity',
		title: __('Site Identity', 'thinkrank'),
		items: [
			{ id: 'basic-info', title: __('Basic Info', 'thinkrank') },
			{ id: 'title-formats', title: __('Title Formats', 'thinkrank') },
			{ id: 'breadcrumbs', title: __('Breadcrumbs', 'thinkrank') },
			{ id: 'hero-section', title: __('Hero & Branding', 'thinkrank') },
			{ id: 'local-seo', title: __('Business Info', 'thinkrank') },
		],
	},
	{
		id: 'seo-analyzer',
		title: __('SEO Analyzer', 'thinkrank'),
		items: [{ id: 'site-audit', title: __('Site Audit', 'thinkrank') }],
	},
	{
		id: 'author-archives',
		title: __('Author Archives', 'thinkrank'),
		items: [{ id: 'settings', title: __('Settings', 'thinkrank') }],
	},
	{
		id: 'integrations',
		title: __('Integrations', 'thinkrank'),
		items: [
			{
				id: 'google-services',
				title: __('Google Services', 'thinkrank'),
			},
			{
				id: 'social-platforms',
				title: __('Social Platforms', 'thinkrank'),
			},
		],
	},
	{
		id: 'analytics',
		title: __('Analytics', 'thinkrank'),
		items: [
			{ id: 'dashboard', title: __('Dashboard', 'thinkrank') },
			{ id: 'keywords', title: __('Keywords', 'thinkrank') },
			{
				id: 'email-reporting',
				title: __('Email Reporting', 'thinkrank'),
			},
			{
				id: 'rank-tracker',
				title: __('Rank Tracker', 'thinkrank'),
				pro: true,
			},
			{
				id: 'traffic-recovery',
				title: __('Traffic Recovery', 'thinkrank'),
				pro: true,
			},
			{
				id: 'index-status',
				title: __('Index Status', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'performance',
		title: __('Performance', 'thinkrank'),
		items: [
			{
				id: 'core-web-vitals',
				title: __('Core Web Vitals', 'thinkrank'),
			},
			{
				id: 'seo-performance',
				title: __('Opportunities', 'thinkrank'),
			},
			{ id: 'monitoring', title: __('Historical Data', 'thinkrank') },
			{ id: 'recommendations', title: __('Diagnostics', 'thinkrank') },
		],
	},
	{
		id: 'ai-insights',
		title: __('AI Insights', 'thinkrank'),
		items: [
			{
				id: 'ai-insights-overview',
				title: __('AI Traffic', 'thinkrank'),
			},
		],
	},
	{
		id: 'global-seo',
		title: __('Bulk SEO Optimization', 'thinkrank'),
		items: getGlobalSEOItems(),
	},
	{
		id: 'image-seo',
		title: __('Image SEO', 'thinkrank'),
		items: [
			{
				id: 'image-seo-settings',
				title: __('Image SEO Settings', 'thinkrank'),
			},
		],
	},
	{
		id: 'schema',
		title: __('Schema Manager', 'thinkrank'),
		items: [
			{
				id: 'schema-settings',
				title: __('Schema Settings', 'thinkrank'),
			},
			{ id: 'schema-types', title: __('Schema Types', 'thinkrank') },
			{ id: 'organization', title: __('Organization', 'thinkrank') },
			{ id: 'local-business', title: __('LocalBusiness', 'thinkrank') },
			{ id: 'person', title: __('Person', 'thinkrank') },
			{ id: 'website', title: __('Website', 'thinkrank') },
			{
				id: 'custom-schema',
				title: __('Custom Schema', 'thinkrank'),
				pro: true,
			},
			{
				id: 'field-mapping',
				title: __('Custom Fields', 'thinkrank'),
				pro: true,
			},
			{ id: 'deployment', title: __('Deployment', 'thinkrank') },
		],
	},
	{
		id: 'internal-links',
		title: __('Internal Links', 'thinkrank'),
		items: [
			{
				id: 'link-suggestions',
				title: __('AI Suggestions', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'external-links',
		title: __('External Links', 'thinkrank'),
		items: [
			{
				id: 'external-links-settings',
				title: __('Link Attributes', 'thinkrank'),
			},
		],
	},
	{
		id: 'redirections',
		title: __('Redirections', 'thinkrank'),
		items: [
			{
				id: 'redirections-manager',
				title: __('Redirects & 404s', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'broken-links',
		title: __('Broken Links', 'thinkrank'),
		items: [
			{
				id: 'broken-links-report',
				title: __('Link Report', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'woocommerce',
		title: __('WooCommerce', 'thinkrank'),
		items: [
			{
				id: 'woocommerce-settings',
				title: __('Product SEO', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'social-media',
		title: __('Social Media', 'thinkrank'),
		items: [
			{ id: 'open-graph', title: __('Open Graph', 'thinkrank') },
			{ id: 'twitter-cards', title: __('Twitter Cards', 'thinkrank') },
			{ id: 'preview', title: __('Preview', 'thinkrank') },
		],
	},
	{
		id: 'focus-pages',
		title: __('Focus Pages', 'thinkrank'),
		items: [
			{
				id: 'focus-pages-board',
				title: __('Diagnosis', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'crawling-ai-indexing',
		title: __('Crawling & AI Indexing', 'thinkrank'),
		items: [
			{
				id: 'global-robot-meta',
				title: __('Global Robots Meta', 'thinkrank'),
			},
			{
				id: 'robots-management',
				title: __('Robots.txt', 'thinkrank'),
			},
			{ id: 'xml-sitemap', title: __('XML Sitemap', 'thinkrank') },
			{
				id: 'publisher-sitemaps',
				title: __('Publisher Sitemaps', 'thinkrank'),
				pro: true,
			},
			{
				id: 'llms-ai-indexing',
				title: __('LLMs.txt for AI', 'thinkrank'),
			},
			{
				id: 'markdown-for-ai',
				title: __('Markdown for AI', 'thinkrank'),
				pro: true,
			},
			{
				id: 'ai-usage-policy',
				title: __('AI Usage Policy', 'thinkrank'),
				pro: true,
			},
			{
				id: 'llms-full',
				title: __('llms-full.txt', 'thinkrank'),
				pro: true,
			},
		],
	},
	{
		id: 'instant-indexing',
		title: __('Instant Indexing', 'thinkrank'),
		items: [
			{
				id: 'instant-indexing-settings',
				title: __('Settings', 'thinkrank'),
			},
			{
				id: 'instant-indexing-submit-urls',
				title: __('Submit URLs', 'thinkrank'),
			},
			{
				id: 'instant-indexing-history',
				title: __('History', 'thinkrank'),
			},
		],
	},
	{
		id: 'role-manager',
		title: __('Role Manager', 'thinkrank'),
		items: [
			{
				id: 'role-manager-settings',
				title: __('Access Control', 'thinkrank'),
			},
		],
	},
];

const getNavigationStructure = () => withModeFlags(getBaseStructure());

/**
 * Presentational grouping for the sidebar.
 *
 * Each group is a collapsible dropdown whose body is a flat list of the items
 * belonging to the sections it references (in order). Groups are purely a
 * display concern — routing, capabilities, search, and breadcrumbs still key
 * off the underlying section ids from getNavigationStructure(), so a section
 * can be moved between groups here without touching any of that.
 *
 * @return {Array<{id:string,title:string,sections:string[]}>} Groups.
 */
const getNavigationGroups = () => [
	{
		id: 'site-identity',
		title: __('Site Identity', 'thinkrank'),
		sections: ['site-identity'],
	},
	{
		id: 'seo-analyzer',
		title: __('SEO Analyzer', 'thinkrank'),
		sections: ['seo-analyzer'],
	},
	{
		id: 'focus-pages',
		title: __('Focus Pages', 'thinkrank'),
		sections: ['focus-pages'],
	},
	{
		id: 'integrations',
		title: __('Integrations', 'thinkrank'),
		sections: ['integrations'],
	},
	{
		id: 'analytics',
		title: __('Analytics', 'thinkrank'),
		sections: ['analytics'],
	},
	{
		id: 'performance',
		title: __('Performance', 'thinkrank'),
		sections: ['performance'],
	},
	{
		id: 'ai-insights',
		title: __('AI Insights', 'thinkrank'),
		sections: ['ai-insights'],
	},
	{
		id: 'content-seo',
		title: __('Bulk SEO Optimization', 'thinkrank'),
		sections: ['global-seo'],
	},
	{
		id: 'author-archives',
		title: __('Author Archives', 'thinkrank'),
		sections: ['author-archives'],
	},
	{
		id: 'image-seo',
		title: __('Image SEO', 'thinkrank'),
		sections: ['image-seo'],
	},
	{
		id: 'links',
		title: __('Link Manager', 'thinkrank'),
		sections: [
			'internal-links',
			'external-links',
			'redirections',
			'broken-links',
		],
	},
	{
		id: 'schema',
		title: __('Schema Manager', 'thinkrank'),
		sections: ['schema'],
	},
	{
		id: 'social-media',
		title: __('Social Media', 'thinkrank'),
		sections: ['social-media'],
	},
	{
		id: 'crawling',
		title: __('Crawling', 'thinkrank'),
		sections: ['crawling-ai-indexing'],
	},
	{
		id: 'indexing',
		title: __('Indexing', 'thinkrank'),
		sections: ['instant-indexing'],
	},
	{
		id: 'woocommerce',
		title: __('WooCommerce', 'thinkrank'),
		sections: ['woocommerce'],
	},
	{
		id: 'role-manager',
		title: __('Role Manager', 'thinkrank'),
		sections: ['role-manager'],
	},
];

const GROUP_ICONS = {
	'seo-analyzer': postList,
	'focus-pages': pin,
	'ai-insights': trendingUp,
	'site-identity': home,
	analytics: chartBar,
	performance: update,
	'content-seo': pages,
	'author-archives': people,
	'image-seo': image,
	links: link,
	schema: code,
	'social-media': share,
	integrations: plugins,
	crawling: globe,
	indexing: upload,
	woocommerce: store,
	'role-manager': people,
};

/**
 * Map every section id to the group that renders it, so the group holding the
 * active section can be auto-expanded.
 *
 * @return {Object<string,string>} sectionId => groupId.
 */
const getSectionToGroupMap = () => {
	const map = {};
	getNavigationGroups().forEach((group) => {
		group.sections.forEach((sectionId) => {
			map[sectionId] = group.id;
		});
	});
	return map;
};

/**
 * Sections only administrators may see, regardless of Role Manager caps. These
 * are not delegatable through the role matrix — they gate on the localized
 * `isAdmin` flag (current_user_can('manage_options')) instead.
 */
const ADMIN_ONLY_SECTIONS = ['seo-analyzer'];

/**
 * Whether the current user can see a nav section, based on the capability map
 * localized from PHP. Admin-only sections gate on `isAdmin`; sections without a
 * mapped capability are always shown; administrators receive every capability
 * so they see everything.
 *
 * @param {string} sectionId Section id.
 * @return {boolean} Visible.
 */
const canSeeSection = (sectionId) => {
	if (ADMIN_ONLY_SECTIONS.includes(sectionId)) {
		return Boolean(window.thinkrankAdmin?.isAdmin);
	}
	const userCaps = window.thinkrankAdmin?.caps || [];
	const sectionCaps = window.thinkrankAdmin?.sectionCaps || {};
	const required = sectionCaps[sectionId];
	if (!required) {
		return true;
	}
	return userCaps.includes(required);
};

const ChevronIcon = ({ open }) => (
	<svg
		viewBox="0 0 16 16"
		fill="currentColor"
		aria-hidden="true"
		className={classnames(
			'tr-w-3.5 tr-h-3.5 tr-shrink-0 tr-transition-transform tr-duration-150',
			open ? 'tr-rotate-90' : ''
		)}
	>
		<path
			fillRule="evenodd"
			d="M6.22 4.22a.75.75 0 0 1 1.06 0l3.25 3.25a.75.75 0 0 1 0 1.06l-3.25 3.25a.75.75 0 0 1-1.06-1.06L8.94 8 6.22 5.28a.75.75 0 0 1 0-1.06Z"
			clipRule="evenodd"
		/>
	</svg>
);

const SidebarNavigation = memo(({ activeSection, activeItem, onNavigate }) => {
	const isPro = window.thinkrankAdmin?.isPro;
	const { mode } = useUiMode();
	const navStructure = useMemo(() => getNavigationStructure(), []);
	const navGroups = useMemo(() => getNavigationGroups(), []);
	const sectionToGroup = useMemo(() => getSectionToGroupMap(), []);

	// Fast lookup of a section object (with its items) by id.
	const sectionById = useMemo(() => {
		const map = {};
		navStructure.forEach((section) => {
			map[section.id] = section;
		});
		return map;
	}, [navStructure]);

	// The flat list of visible items for a group, in section order. Each leaf
	// carries the section it routes to so a group can span several sections.
	const getGroupLeaves = useCallback(
		(group) => {
			const leaves = [];
			group.sections.forEach((sectionId) => {
				if (!canSeeSection(sectionId)) {
					return;
				}
				const section = sectionById[sectionId];
				if (!section) {
					return;
				}
				section.items.forEach((item) => {
					if (isVisibleInMode(item, mode)) {
						leaves.push({ sectionId, item });
					}
				});
			});
			return leaves;
		},
		[sectionById, mode]
	);

	// Accordion: only one group is open at a time. Tracking a single open group
	// id (rather than a map of booleans) means opening one collapses the rest,
	// which keeps the page short enough that the WP admin sidebar never unpins.
	const [openGroup, setOpenGroup] = useState(
		() => sectionToGroup[activeSection] || null
	);

	// Keep the group holding the active section expanded (e.g. after a search
	// jump or a deep link) — and, per accordion behaviour, close every other.
	useEffect(() => {
		const activeGroup = sectionToGroup[activeSection];
		if (activeGroup) {
			setOpenGroup(activeGroup);
		}
	}, [activeSection, sectionToGroup]);

	const toggleGroup = useCallback((groupId) => {
		// Clicking the open group collapses it; clicking any other opens it
		// alone.
		setOpenGroup((prev) => (prev === groupId ? null : groupId));
	}, []);

	const handleItemSelect = useCallback(
		(sectionId, itemId) => onNavigate(sectionId, itemId),
		[onNavigate]
	);

	return (
		<nav
			className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-shadow-sm tr-overflow-hidden"
			aria-label={__('Settings navigation', 'thinkrank')}
		>
			{navGroups.map((group, idx) => {
				const leaves = getGroupLeaves(group);

				// Skip groups with nothing visible (all sections hidden by the
				// Role Manager, or empty like Bulk SEO with no public post types).
				if (leaves.length === 0) {
					return null;
				}

				const isOpen = openGroup === group.id;
				const isGroupActive =
					sectionToGroup[activeSection] === group.id;
				const groupIcon = GROUP_ICONS[group.id];

				// A group with a single item doesn't need a collapsible body —
				// expanding to reveal exactly one child is a wasted click. Render
				// it as a direct link that jumps straight to that item.
				if (leaves.length === 1) {
					const { sectionId, item } = leaves[0];
					// Pro-locked items stay navigable so free users can open the
					// tab and see its upsell teaser — only the PRO badge marks them.
					const isProLocked = item.pro && !isPro;

					let linkStateClasses =
						'tr-text-secondary hover:tr-bg-white hover:tr-text-primary';
					if (isGroupActive) {
						linkStateClasses = 'tr-text-brand tr-bg-brand-bg';
					}

					return (
						<div
							key={group.id}
							className={classnames(
								idx > 0 && 'tr-border-t tr-border-secondary'
							)}
						>
							<button
								type="button"
								onClick={() =>
									handleItemSelect(sectionId, item.id)
								}
								className={classnames(
									'tr-relative tr-flex tr-items-center tr-w-full tr-gap-2.5',
									'tr-pl-4 tr-pr-3 tr-py-2.5 tr-text-left',
									'tr-text-sm tr-font-semibold tr-transition-colors tr-duration-150',
									'focus:tr-outline-none focus-visible:tr-ring-2 focus-visible:tr-ring-brand focus-visible:tr-ring-inset',
									linkStateClasses
								)}
							>
								{isGroupActive && (
									<span
										aria-hidden="true"
										className="tr-absolute tr-left-0 tr-top-1.5 tr-bottom-1.5 tr-w-0.5 tr-rounded-r tr-bg-brand"
									/>
								)}
								{groupIcon && (
									<span
										className={classnames(
											'tr-shrink-0 tr-flex tr-items-center tr-justify-center',
											isGroupActive
												? 'tr-text-brand'
												: 'tr-text-tertiary'
										)}
										aria-hidden="true"
									>
										<Icon icon={groupIcon} size={18} />
									</span>
								)}
								<span className="tr-flex-1 tr-truncate">
									{group.title}
								</span>
								{isProLocked && (
									<span className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-px tr-rounded tr-text-[10px] tr-font-semibold tr-bg-brand-bg tr-text-brand tr-shrink-0">
										PRO
									</span>
								)}
							</button>
						</div>
					);
				}

				return (
					<div
						key={group.id}
						className={classnames(
							idx > 0 && 'tr-border-t tr-border-secondary'
						)}
					>
						<button
							type="button"
							onClick={() => toggleGroup(group.id)}
							className={classnames(
								'tr-relative tr-flex tr-items-center tr-w-full tr-gap-2.5',
								'tr-pl-4 tr-pr-3 tr-py-2.5 tr-text-left',
								'tr-text-sm tr-font-semibold tr-transition-colors tr-duration-150',
								'focus:tr-outline-none focus-visible:tr-ring-2 focus-visible:tr-ring-brand focus-visible:tr-ring-inset',
								isGroupActive
									? 'tr-text-brand tr-bg-brand-bg'
									: 'tr-text-secondary hover:tr-bg-white hover:tr-text-primary'
							)}
							aria-expanded={isOpen}
						>
							{isGroupActive && (
								<span
									aria-hidden="true"
									className="tr-absolute tr-left-0 tr-top-1.5 tr-bottom-1.5 tr-w-0.5 tr-rounded-r tr-bg-brand"
								/>
							)}
							{groupIcon && (
								<span
									className={classnames(
										'tr-shrink-0 tr-flex tr-items-center tr-justify-center',
										isGroupActive
											? 'tr-text-brand'
											: 'tr-text-tertiary'
									)}
									aria-hidden="true"
								>
									<Icon icon={groupIcon} size={18} />
								</span>
							)}
							<span className="tr-flex-1 tr-truncate">
								{group.title}
							</span>
							<span
								className={classnames(
									'tr-shrink-0',
									isGroupActive
										? 'tr-text-brand'
										: 'tr-text-tertiary'
								)}
							>
								<ChevronIcon open={isOpen} />
							</span>
						</button>

						{/* Animated expand/collapse: the grid row track eases
						    from 0fr to 1fr, so variable-height bodies grow and
						    shrink smoothly without measuring in JS. The body
						    stays mounted, so `inert` keeps keyboard/AT users out
						    of the collapsed panel. */}
						<div
							className={classnames(
								'tr-grid tr-transition-[grid-template-rows] tr-duration-200 tr-ease-out',
								isOpen
									? 'tr-grid-rows-[1fr]'
									: 'tr-grid-rows-[0fr]'
							)}
							{...(!isOpen && { inert: '' })}
						>
							<div className="tr-overflow-hidden">
								<ul
									className={classnames(
										'tr-list-none tr-m-0 tr-py-1 tr-pl-7 tr-pr-2',
										'tr-relative',
										'before:tr-content-[""] before:tr-absolute before:tr-top-1 before:tr-bottom-1',
										'before:tr-left-[1.5rem] before:tr-w-px before:tr-bg-line-2'
									)}
								>
									{leaves.map(({ sectionId, item }) => {
										const isActive =
											activeSection === sectionId &&
											activeItem === item.id;
										const isProLocked = item.pro && !isPro;

										let stateClasses =
											'tr-text-secondary hover:tr-text-primary';
										if (isActive) {
											stateClasses =
												'tr-text-brand tr-font-medium';
										}

										return (
											<li
												key={`${sectionId}-${item.id}`}
												className="tr-m-0"
											>
												<button
													type="button"
													onClick={() =>
														handleItemSelect(
															sectionId,
															item.id
														)
													}
													className={classnames(
														'tr-relative tr-flex tr-items-center tr-justify-between tr-w-full tr-gap-2',
														'tr-px-2.5 tr-py-1.5 tr-rounded-md tr-text-sm tr-text-left',
														'tr-transition-colors tr-duration-150',
														'focus:tr-outline-none focus-visible:tr-ring-2 focus-visible:tr-ring-brand',
														stateClasses
													)}
												>
													{isActive && (
														<span
															aria-hidden="true"
															className="tr-absolute tr--left-[5px] tr-top-1.5 tr-bottom-1.5 tr-w-0.5 tr-rounded-r tr-bg-brand"
														/>
													)}
													<span className="tr-truncate">
														{item.title}
													</span>
													{isProLocked && (
														<span className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-px tr-rounded tr-text-[10px] tr-font-semibold tr-bg-brand-bg tr-text-brand tr-shrink-0">
															PRO
														</span>
													)}
												</button>
											</li>
										);
									})}
								</ul>
							</div>
						</div>
					</div>
				);
			})}
		</nav>
	);
});

export default SidebarNavigation;
export {
	getNavigationStructure,
	ADVANCED_ENTRIES,
	getNavigationGroups,
	SECTION_ICONS,
	canSeeSection,
};
