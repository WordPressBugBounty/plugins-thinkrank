/**
 * Which documentation article explains the screen the user is looking at.
 *
 * The table below is the whole of it: 85 article paths, their titles and the
 * one-line summary each page publishes, plus a map from an admin screen to the
 * articles that cover it. It ships with the bundle rather than being fetched,
 * which is what lets the Help drawer open instantly, work with thinkrank.ai
 * unreachable, and send no request anywhere until the reader clicks a link.
 *
 * Generated from https://thinkrank.ai/sitemap-0.xml and each page's own
 * `<title>` and `<meta name="description">`. Regenerate it when the docs site
 * gains or renames an article; docsMap.test.js fails on a screen pointing at a
 * path that is not in the table, so a rename cannot rot quietly.
 *
 * @since 2.19.0
 */

export const DOCS_BASE = 'https://thinkrank.ai';

/**
 * Human names for the docs sections, in the order the docs site lists them.
 *
 * @type {Object<string,string>}
 */
export const DOC_SECTIONS = {
	'': 'Documentation',
	'getting-started': 'Getting Started',
	'ai-tools': 'AI Tools',
	'ai-insights': 'AI Insights',
	'on-page-seo': 'On-Page SEO',
	schema: 'Schema',
	'sitemaps-crawling': 'Sitemaps & Crawling',
	'site-identity-social': 'Site Identity & Social',
	'analytics-reporting': 'Analytics & Reporting',
	integrations: 'Integrations',
	advanced: 'Advanced',
	developer: 'Developer',
};

/**
 * Every published documentation article.
 *
 * @type {Array<{path: string, title: string, section: string, summary: string}>}
 */
export const DOC_ARTICLES = [
	{
		path: '/docs/',
		title: 'Documentation',
		section: '',
		summary:
			'ThinkRank documentation, guides for every feature, from setup and AI tools to schema, sitemaps, analytics and migrating from Yoast, Rank Math, AIOSEO or SEOPress.',
	},
	{
		path: '/docs/advanced/',
		title: 'Advanced docs',
		section: 'advanced',
		summary:
			'Role manager, redirections, broken links, WooCommerce SEO, and data management.',
	},
	{
		path: '/docs/advanced/broken-links/',
		title: 'Broken link checker',
		section: 'advanced',
		summary:
			'Automatically scan your published posts and pages for broken links, then fix each one, edit the URL or unlink it, right from the ThinkRank Pro report.',
	},
	{
		path: '/docs/advanced/data-management/',
		title: 'Data management',
		section: 'advanced',
		summary:
			"Manage your ThinkRank data, choose what's kept when you uninstall, export your AI content briefs, and re-run SEO data imports from another plugin.",
	},
	{
		path: '/docs/advanced/internal-links/',
		title: 'AI internal links',
		section: 'advanced',
		summary:
			'Get AI-ranked internal link suggestions for your posts, review the best candidates, and insert them with one click, or run them in bulk across a post type.',
	},
	{
		path: '/docs/advanced/redirections/',
		title: 'Redirections & 404 monitor',
		section: 'advanced',
		summary:
			'Set up 301 and 302 redirects and let the 404 monitor log every broken URL so you can fix it with one click, a ThinkRank Pro redirect manager.',
	},
	{
		path: '/docs/advanced/role-manager/',
		title: 'Role manager',
		section: 'advanced',
		summary:
			'Control which WordPress user roles can access each ThinkRank area with a free role-by-role permission matrix, so clients and editors only see what they should.',
	},
	{
		path: '/docs/advanced/site-seo-audit/',
		title: 'Site SEO audit',
		section: 'advanced',
		summary:
			'Score your whole site out of 100, including AI Search checks, with no Google connection, and apply the safe fixes in one click from the audit screen.',
	},
	{
		path: '/docs/advanced/woocommerce-seo/',
		title: 'WooCommerce SEO',
		section: 'advanced',
		summary:
			'ThinkRank gives WooCommerce products SEO-ready Product schema for free, with GTIN, MPN, and variation data plus product Open Graph in ThinkRank Pro.',
	},
	{
		path: '/docs/ai-insights/',
		title: 'AI Insights docs',
		section: 'ai-insights',
		summary:
			'AI referral traffic and revenue attribution, Auto AI metadata, and Traffic Recovery.',
	},
	{
		path: '/docs/ai-insights/ai-traffic/',
		title: 'AI Traffic analytics',
		section: 'ai-insights',
		summary:
			'See how many visitors arrive from ChatGPT, Perplexity, Claude and Gemini, and which AI crawlers read your site, measured first-party without Google.',
	},
	{
		path: '/docs/ai-insights/auto-ai-metadata/',
		title: 'Auto AI metadata',
		section: 'ai-insights',
		summary:
			'Fill a missing SEO title or meta description automatically when you publish, in the background, without ever overwriting what you wrote yourself.',
	},
	{
		path: '/docs/ai-insights/traffic-recovery/',
		title: 'Traffic Recovery',
		section: 'ai-insights',
		summary:
			'Find published pages losing search traffic and get an AI refresh brief for each \u2014 what is outdated, what is missing, and a rewritten title and description.',
	},
	{
		path: '/docs/ai-tools/',
		title: 'AI Tools docs',
		section: 'ai-tools',
		summary:
			'Content briefs, AI metadata, the real-time SEO score, and competitor analysis.',
	},
	{
		path: '/docs/ai-tools/ai-metadata-generator/',
		title: 'AI metadata generation',
		section: 'ai-tools',
		summary:
			"Generate SEO-optimized titles and meta descriptions with AI, tuned for length, tone, and your focus keyword, and preview exactly how they'll look in Google.",
	},
	{
		path: '/docs/ai-tools/competitor-analysis/',
		title: 'Competitor analysis',
		section: 'ai-tools',
		summary:
			'See how competing pages are structured, which keywords they target, and where the content gaps are, so you can write pages that outrank them.',
	},
	{
		path: '/docs/ai-tools/content-brief-generator/',
		title: 'AI content brief generator',
		section: 'ai-tools',
		summary:
			'Generate a data-backed content brief from a topic or keyword, outline, headings, FAQs, and competitor gaps, so you know what to write before you start.',
	},
	{
		path: '/docs/ai-tools/mcp-server/',
		title: 'MCP server',
		section: 'ai-tools',
		summary:
			"Connect Claude or ChatGPT to your WordPress site with ThinkRank's MCP server so it can read and update your SEO for you, through an approved connection.",
	},
	{
		path: '/docs/ai-tools/seo-score/',
		title: 'Real-time SEO score',
		section: 'ai-tools',
		summary:
			'ThinkRank scores every post as you write with a 13-factor algorithm and applies AI-suggested fixes for the title, meta, links, and schema in one click.',
	},
	{
		path: '/docs/analytics-reporting/',
		title: 'Analytics & Reporting docs',
		section: 'analytics-reporting',
		summary:
			'Google Analytics, Search Console, Core Web Vitals, and scheduled email reports.',
	},
	{
		path: '/docs/analytics-reporting/email-reporting/',
		title: 'Email reporting',
		section: 'analytics-reporting',
		summary:
			'Get a monthly SEO report by email with clicks, impressions, top pages and queries and AI assistant visits, with custom schedules and branding in Pro.',
	},
	{
		path: '/docs/analytics-reporting/focus-pages/',
		title: 'Focus Pages',
		section: 'analytics-reporting',
		summary:
			'Pick up to ten important pages in ThinkRank Pro and see why each one is not ranking, across SEO score, Search Console, Analytics, indexing and internal links.',
	},
	{
		path: '/docs/analytics-reporting/google-analytics-search-console/',
		title: 'Google Analytics & Search Console',
		section: 'analytics-reporting',
		summary:
			'Connect Google Analytics 4, Search Console, and PageSpeed Insights to see your traffic, rankings, and Core Web Vitals right inside WordPress, no tab-switching.',
	},
	{
		path: '/docs/analytics-reporting/google-index-status/',
		title: 'Google index status',
		section: 'analytics-reporting',
		summary:
			'See whether Google has indexed each post, right in your post list and editor, check a URL live, and resubmit pages that are not indexed with ThinkRank Pro.',
	},
	{
		path: '/docs/analytics-reporting/performance-core-web-vitals/',
		title: 'Performance & Core Web Vitals',
		section: 'analytics-reporting',
		summary:
			'Track PageSpeed scores and Core Web Vitals (LCP, INP, CLS) inside WordPress, with diagnostics that tell you what to fix to speed up your pages.',
	},
	{
		path: '/docs/analytics-reporting/rank-tracker/',
		title: 'Keyword rank tracker',
		section: 'analytics-reporting',
		summary:
			'Track where your pages rank for the keywords that matter, with daily Search Console snapshots, position history over time, and keyword suggestions.',
	},
	{
		path: '/docs/developer/',
		title: 'Developer docs',
		section: 'developer',
		summary:
			'Extend ThinkRank with public template tags and filters, for theme and plugin developers.',
	},
	{
		path: '/docs/developer/filters/',
		title: 'Filters',
		section: 'developer',
		summary:
			"Public WordPress filters to customize ThinkRank's schema, canonical URLs, Open Graph type, robots directives, and which post types it handles.",
	},
	{
		path: '/docs/developer/overview/',
		title: 'Developer overview',
		section: 'developer',
		summary:
			'ThinkRank exposes public template tags and filters so theme and plugin developers can read SEO data and adjust schema, meta tags, and breadcrumbs.',
	},
	{
		path: '/docs/developer/template-tags/',
		title: 'Template tags',
		section: 'developer',
		summary:
			"Public PHP functions your theme can call to print ThinkRank breadcrumbs and read the current page's SEO metadata directly in template files.",
	},
	{
		path: '/docs/getting-started/',
		title: 'Getting Started docs',
		section: 'getting-started',
		summary:
			'Install, run the setup wizard, connect an AI provider, and migrate from another SEO plugin.',
	},
	{
		path: '/docs/getting-started/activate-license/',
		title: 'Activate your license key',
		section: 'getting-started',
		summary:
			'How to activate your ThinkRank Pro license key, verify it with the emailed code, and unlock automatic updates, premium support, and the Pro features.',
	},
	{
		path: '/docs/getting-started/connect-ai-provider/',
		title: 'Connect an AI provider',
		section: 'getting-started',
		summary:
			'Connect OpenAI, Anthropic, Gemini, OpenRouter or any OpenAI-compatible endpoint with your own key, and set daily limits on AI spending.',
	},
	{
		path: '/docs/getting-started/install-thinkrank-pro/',
		title: 'Install ThinkRank Pro',
		section: 'getting-started',
		summary:
			'How to download ThinkRank Pro from your WPDeveloper account and install it on your WordPress site, then activate it alongside the free plugin.',
	},
	{
		path: '/docs/getting-started/installation/',
		title: 'Install ThinkRank',
		section: 'getting-started',
		summary:
			'Install and activate ThinkRank on your WordPress site from the plugin directory, then open the dashboard and run your first setup checks.',
	},
	{
		path: '/docs/getting-started/migrate-from-aioseo/',
		title: 'Migrate from All in One SEO',
		section: 'getting-started',
		summary:
			'Switch from All in One SEO to ThinkRank and keep your SEO data, the importer brings across your metadata and settings, then deactivates AIOSEO for you.',
	},
	{
		path: '/docs/getting-started/migrate-from-rank-math/',
		title: 'Migrate from Rank Math',
		section: 'getting-started',
		summary:
			'Switch from Rank Math to ThinkRank and keep your SEO data, the importer brings across metadata, schema, image SEO, and more, then deactivates Rank Math.',
	},
	{
		path: '/docs/getting-started/migrate-from-seopress/',
		title: 'Migrate from SEOPress',
		section: 'getting-started',
		summary:
			'Switch from SEOPress to ThinkRank and keep your SEO data, the importer brings across your metadata and settings, then deactivates SEOPress for you.',
	},
	{
		path: '/docs/getting-started/migrate-from-squirrly/',
		title: 'Migrate from Squirrly SEO',
		section: 'getting-started',
		summary:
			'Switch from Squirrly SEO to ThinkRank and keep your titles, descriptions, keywords, schema, redirects and settings, with a snapshot you check before applying.',
	},
	{
		path: '/docs/getting-started/migrate-from-yoast/',
		title: 'Migrate from Yoast SEO',
		section: 'getting-started',
		summary:
			'Move from Yoast SEO to ThinkRank without losing your work, the importer brings across metadata, schema, image SEO, and more, then deactivates Yoast.',
	},
	{
		path: '/docs/getting-started/purchase-thinkrank-pro/',
		title: 'Purchase ThinkRank Pro',
		section: 'getting-started',
		summary:
			'How to buy ThinkRank Pro, compare the Starter, Innovator, Visionary and Lifetime plans, and where to find your download and license key after checkout.',
	},
	{
		path: '/docs/getting-started/seo-plugin-conflicts/',
		title: 'Avoiding SEO plugin conflicts',
		section: 'getting-started',
		summary:
			'Running two SEO plugins causes duplicate meta tags, canonicals, and schema. ThinkRank detects the others and helps you deactivate them after importing.',
	},
	{
		path: '/docs/getting-started/setup-wizard/',
		title: 'The setup wizard',
		section: 'getting-started',
		summary:
			'Learn how to use the ThinkRank setup wizard to configure your site details, connect analytics, add social profiles, and finish onboarding in minutes.',
	},
	{
		path: '/docs/integrations/',
		title: 'Integrations docs',
		section: 'integrations',
		summary:
			'Page builders (Gutenberg, Elementor, Divi, Oxygen, Breakdance, Bricks and Beaver Builder) and multilingual sites with WPML, Polylang or TranslatePress.',
	},
	{
		path: '/docs/integrations/beaver-builder/',
		title: 'Beaver Builder',
		section: 'integrations',
		summary:
			"Open ThinkRank's SEO panel from the Beaver Builder toolbar with your live SEO score, and add FAQ, HowTo and Table of Contents modules with schema.",
	},
	{
		path: '/docs/integrations/bricks/',
		title: 'Bricks Builder',
		section: 'integrations',
		summary:
			"Open ThinkRank's SEO panel inside Bricks Builder, see your live SEO score in the toolbar, and add FAQ, HowTo and Table of Contents elements with schema.",
	},
	{
		path: '/docs/integrations/divi/',
		title: 'Divi',
		section: 'integrations',
		summary:
			"Open ThinkRank's full SEO panel from inside the Divi Builder, and keep Divi layouts and Theme Builder templates out of your SEO screens.",
	},
	{
		path: '/docs/integrations/elementor/',
		title: 'Elementor',
		section: 'integrations',
		summary:
			"Edit ThinkRank's SEO fields inside the Elementor editor, and add FAQ, HowTo and Table of Contents widgets that emit the same structured data as the blocks.",
	},
	{
		path: '/docs/integrations/gutenberg/',
		title: 'Gutenberg (block editor)',
		section: 'integrations',
		summary:
			'Edit your SEO from the block editor sidebar, watch the live score as you write, and add FAQ, HowTo and Table of Contents blocks with built-in schema.',
	},
	{
		path: '/docs/integrations/oxygen-breakdance/',
		title: 'Oxygen and Breakdance',
		section: 'integrations',
		summary:
			"Open ThinkRank's full SEO panel from inside the Oxygen or Breakdance builder, and have your builder-made content scored properly everywhere.",
	},
	{
		path: '/docs/integrations/wpml-polylang/',
		title: 'WPML, Polylang and TranslatePress',
		section: 'integrations',
		summary:
			'Run ThinkRank on a multilingual site so every translation carries its own SEO fields, declares its language, links to its alternates and appears in the sitemap.',
	},
	{
		path: '/docs/on-page-seo/',
		title: 'On-Page SEO docs',
		section: 'on-page-seo',
		summary:
			'The SEO metabox, focus keywords, Bulk Snippets, the Content Type Matrix, external links, and the FAQ, HowTo and Table of Contents blocks.',
	},
	{
		path: '/docs/on-page-seo/bulk-seo-optimization/',
		title: 'Bulk SEO optimization',
		section: 'on-page-seo',
		summary:
			'Set default titles, meta descriptions, schema type, and robots directives for a whole post type at once, instead of editing every post by hand.',
	},
	{
		path: '/docs/on-page-seo/bulk-snippets/',
		title: 'Bulk Snippets',
		section: 'on-page-seo',
		summary:
			'Edit SEO titles, meta descriptions and focus keywords for every post in one table, filter by problems like missing or duplicate titles, and draft fixes with AI.',
	},
	{
		path: '/docs/on-page-seo/content-type-matrix/',
		title: 'Content Type Matrix',
		section: 'on-page-seo',
		summary:
			'Turn meta tags, schema, Open Graph, Twitter cards, no-index and sitemap inclusion on or off per post type, taxonomy and archive from one matrix.',
	},
	{
		path: '/docs/on-page-seo/external-links/',
		title: 'External link attributes (nofollow and new tab)',
		section: 'on-page-seo',
		summary:
			'Add nofollow to external links and open them in a new tab across your whole WordPress site, with an exception list, without rewriting your posts.',
	},
	{
		path: '/docs/on-page-seo/faq-block/',
		title: 'FAQ block',
		section: 'on-page-seo',
		summary:
			'Add a FAQ Gutenberg block, type questions and answers inline, get a no-JavaScript accordion and valid FAQPage schema for rich results, with no code.',
	},
	{
		path: '/docs/on-page-seo/focus-keywords/',
		title: 'Focus keywords',
		section: 'on-page-seo',
		summary:
			"Set the keyword each post targets so ThinkRank can score its optimization, and get warned when you've already used a focus keyword on another post.",
	},
	{
		path: '/docs/on-page-seo/howto-toc-blocks/',
		title: 'HowTo and Table of Contents blocks',
		section: 'on-page-seo',
		summary:
			'Add HowTo and Table of Contents blocks in the WordPress editor that publish HowTo and navigation schema, with matching Elementor, Bricks and Beaver versions.',
	},
	{
		path: '/docs/on-page-seo/pillar-content/',
		title: 'Pillar content & link suggestions',
		section: 'on-page-seo',
		summary:
			'Mark your best posts as pillar content, and ThinkRank suggests them as internal links while you edit related posts, building topic clusters that rank.',
	},
	{
		path: '/docs/on-page-seo/quick-edit-seo/',
		title: 'Quick Edit SEO',
		section: 'on-page-seo',
		summary:
			'Edit the SEO title, meta description and focus keywords straight from the posts list, without opening a single post in the editor.',
	},
	{
		path: '/docs/on-page-seo/seo-metabox/',
		title: 'The SEO metabox',
		section: 'on-page-seo',
		summary:
			'Optimize any post from the editor, title, meta description, slug, and focus keyword with a live SERP preview, plus social, robots, and schema controls.',
	},
	{
		path: '/docs/on-page-seo/seo-overview-column/',
		title: 'SEO Overview column',
		section: 'on-page-seo',
		summary:
			"See each post's SEO score, focus keyword and readiness right in the posts list, and edit the focus keyword inline without opening the editor.",
	},
	{
		path: '/docs/schema/',
		title: 'Schema docs',
		section: 'schema',
		summary:
			'Schema Manager, automatic per-post-type schema, and importing schema from any website.',
	},
	{
		path: '/docs/schema/automatic-schema/',
		title: 'Automatic schema',
		section: 'schema',
		summary:
			'ThinkRank outputs structured data out of the box, Article, WebPage, and Product on content, CollectionPage on archives, and WebSite schema on your homepage.',
	},
	{
		path: '/docs/schema/custom-fields-to-schema/',
		title: 'Custom fields to schema',
		section: 'schema',
		summary:
			'Map ACF, SCF, Meta Box, Pods or ACPT custom fields to schema properties per post type in ThinkRank Pro, so every post publishes complete JSON-LD.',
	},
	{
		path: '/docs/schema/custom-schema/',
		title: 'Custom schema & display conditions',
		section: 'schema',
		summary:
			'Add your own JSON-LD structured data and control exactly where it outputs with include and exclude display conditions, validated live before it ships.',
	},
	{
		path: '/docs/schema/import-schema-from-website/',
		title: 'Import Schema From a Website',
		section: 'schema',
		summary:
			'Fetch the structured data from any live URL, review and edit it, then deploy it as schema on your own WordPress post, no hand-coding JSON-LD.',
	},
	{
		path: '/docs/schema/schema-manager/',
		title: 'Schema Manager',
		section: 'schema',
		summary:
			"Manage your site's structured data from one place, set the Schema.org type per post type plus your organization and website markup, output as clean JSON-LD.",
	},
	{
		path: '/docs/schema/validate-your-schema/',
		title: 'Validate your schema',
		section: 'schema',
		summary:
			"Confirm your structured data is error-free and eligible for rich results with Google's Rich Results Test and the Schema.org validator, and fix common issues.",
	},
	{
		path: '/docs/site-identity-social/',
		title: 'Site Identity & Social docs',
		section: 'site-identity-social',
		summary:
			'Site identity, business info, social/Open Graph, author archives, and image SEO.',
	},
	{
		path: '/docs/site-identity-social/author-archives/',
		title: 'Author archives SEO',
		section: 'site-identity-social',
		summary:
			'Control whether author archive pages exist, whether search engines index them, and the SEO titles and descriptions they use, with per-author variables.',
	},
	{
		path: '/docs/site-identity-social/breadcrumbs/',
		title: 'Breadcrumbs',
		section: 'site-identity-social',
		summary:
			'Add SEO-friendly breadcrumbs to WordPress with BreadcrumbList schema, choose the trail type and separator, and label crumbs with your SEO titles.',
	},
	{
		path: '/docs/site-identity-social/image-seo/',
		title: 'Image SEO',
		section: 'site-identity-social',
		summary:
			'Automatically fill missing alt and title attributes on your images with configurable format strings and tokens, better accessibility and image search.',
	},
	{
		path: '/docs/site-identity-social/local-seo/',
		title: 'Local SEO',
		section: 'site-identity-social',
		summary:
			'Add your business name, address, hours and location so ThinkRank outputs LocalBusiness schema, with multi-location support for stores and franchises in Pro.',
	},
	{
		path: '/docs/site-identity-social/site-identity/',
		title: 'Site identity',
		section: 'site-identity-social',
		summary:
			'Set your site-wide SEO foundation once, basic info, title formats, breadcrumbs, branding, and business info, and every page inherits sensible defaults.',
	},
	{
		path: '/docs/site-identity-social/social-media-optimization/',
		title: 'Social media optimization',
		section: 'site-identity-social',
		summary:
			'Control how your pages look when shared, Open Graph and Twitter/X cards for Facebook, LinkedIn and Pinterest, with a live preview and per-post overrides.',
	},
	{
		path: '/docs/sitemaps-crawling/',
		title: 'Sitemaps & Crawling docs',
		section: 'sitemaps-crawling',
		summary:
			'XML sitemaps, robots.txt and AI crawler controls, LLMs.txt, Markdown for AI, and Instant Indexing with IndexNow.',
	},
	{
		path: '/docs/sitemaps-crawling/ai-usage-policy/',
		title: 'AI Usage Policy in llms.txt',
		section: 'sitemaps-crawling',
		summary:
			'State in your llms.txt whether AI systems may train on, summarize or embed your content, and whether they must credit you, with ThinkRank Pro.',
	},
	{
		path: '/docs/sitemaps-crawling/instant-indexing/',
		title: 'Instant Indexing (IndexNow)',
		section: 'sitemaps-crawling',
		summary:
			'Notify search engines the moment you publish, update, or delete content using the IndexNow protocol, so your pages get crawled in seconds.',
	},
	{
		path: '/docs/sitemaps-crawling/llms-txt/',
		title: 'LLMs.txt for AI',
		section: 'sitemaps-crawling',
		summary:
			'Generate and maintain an LLMs.txt for the AI agents and tools that read it, with llms-full.txt and an AI usage policy in ThinkRank Pro.',
	},
	{
		path: '/docs/sitemaps-crawling/markdown-for-ai/',
		title: 'Markdown for AI',
		section: 'sitemaps-crawling',
		summary:
			'Serve AI agents a clean Markdown copy of your posts on the same URL, while visitors keep the normal page and your HTML stays canonical.',
	},
	{
		path: '/docs/sitemaps-crawling/publisher-sitemaps/',
		title: 'News & video sitemaps',
		section: 'sitemaps-crawling',
		summary:
			'Generate Google News and video sitemaps so publishers and media sites get their latest articles and videos discovered and indexed faster.',
	},
	{
		path: '/docs/sitemaps-crawling/robots-txt/',
		title: 'Robots.txt management',
		section: 'sitemaps-crawling',
		summary:
			'Manage your robots.txt from WordPress, ThinkRank generates a sensible default, links your sitemap automatically, and never blocks render-critical assets.',
	},
	{
		path: '/docs/sitemaps-crawling/xml-sitemaps/',
		title: 'XML sitemaps',
		section: 'sitemaps-crawling',
		summary:
			'ThinkRank generates and auto-updates XML sitemaps of your published, indexable URLs, plus an optional local business sitemap, so search engines stay current.',
	},
];

/**
 * Articles for an admin screen, keyed `page`, `page:section` or
 * `page:section:item`.
 *
 * Most specific wins, and a screen with no entry of its own inherits its
 * section's. The first path in a list is the one the drawer calls the main
 * article for the screen.
 *
 * @type {Object<string,string[]>}
 */
export const SCREEN_DOCS = {
	// Top-level pages.
	dashboard: [
		'/docs/getting-started/',
		'/docs/getting-started/setup-wizard/',
	],
	'ai-tools': [
		'/docs/ai-tools/',
		'/docs/ai-tools/content-brief-generator/',
		'/docs/ai-tools/ai-metadata-generator/',
		'/docs/ai-tools/competitor-analysis/',
	],
	analytics: ['/docs/analytics-reporting/'],
	settings: [
		'/docs/getting-started/connect-ai-provider/',
		'/docs/ai-tools/mcp-server/',
		'/docs/advanced/role-manager/',
		'/docs/advanced/data-management/',
	],
	'import-export': ['/docs/advanced/data-management/'],
	migration: [
		'/docs/getting-started/migrate-from-yoast/',
		'/docs/getting-started/migrate-from-rank-math/',
		'/docs/getting-started/migrate-from-aioseo/',
		'/docs/getting-started/migrate-from-seopress/',
		'/docs/getting-started/migrate-from-squirrly/',
		'/docs/getting-started/seo-plugin-conflicts/',
	],
	'essential-seo': ['/docs/on-page-seo/'],

	// Essential SEO sections. Every nav_section in SidebarNavigation.js that
	// has an article; the ones that do not fall back to the page entry above.
	'essential-seo:global-seo': [
		'/docs/on-page-seo/',
		'/docs/on-page-seo/seo-metabox/',
		'/docs/on-page-seo/bulk-seo-optimization/',
	],
	'essential-seo:global-seo:bulk-snippets': [
		'/docs/on-page-seo/bulk-snippets/',
		'/docs/on-page-seo/quick-edit-seo/',
		'/docs/on-page-seo/seo-overview-column/',
	],
	'essential-seo:global-seo:content-type-matrix': [
		'/docs/on-page-seo/content-type-matrix/',
	],
	'essential-seo:global-seo:auto-ai': ['/docs/ai-insights/auto-ai-metadata/'],
	'essential-seo:site-identity': [
		'/docs/site-identity-social/site-identity/',
		'/docs/site-identity-social/local-seo/',
	],
	'essential-seo:site-identity:breadcrumbs': [
		'/docs/site-identity-social/breadcrumbs/',
	],
	'essential-seo:site-identity:local-seo': [
		'/docs/site-identity-social/local-seo/',
	],
	'essential-seo:seo-analyzer': ['/docs/advanced/site-seo-audit/'],
	'essential-seo:author-archives': [
		'/docs/site-identity-social/author-archives/',
	],
	'essential-seo:integrations': [
		'/docs/analytics-reporting/google-analytics-search-console/',
	],
	'essential-seo:integrations:social-platforms': [
		'/docs/site-identity-social/social-media-optimization/',
	],
	'essential-seo:analytics': [
		'/docs/analytics-reporting/',
		'/docs/analytics-reporting/google-analytics-search-console/',
	],
	'essential-seo:analytics:keywords': ['/docs/on-page-seo/focus-keywords/'],
	'essential-seo:analytics:email-reporting': [
		'/docs/analytics-reporting/email-reporting/',
	],
	'essential-seo:analytics:rank-tracker': [
		'/docs/analytics-reporting/rank-tracker/',
	],
	'essential-seo:analytics:traffic-recovery': [
		'/docs/ai-insights/traffic-recovery/',
	],
	'essential-seo:analytics:index-status': [
		'/docs/analytics-reporting/google-index-status/',
	],
	'essential-seo:performance': [
		'/docs/analytics-reporting/performance-core-web-vitals/',
	],
	'essential-seo:ai-insights': [
		'/docs/ai-insights/',
		'/docs/ai-insights/ai-traffic/',
	],
	'essential-seo:image-seo': ['/docs/site-identity-social/image-seo/'],
	'essential-seo:schema': [
		'/docs/schema/schema-manager/',
		'/docs/schema/automatic-schema/',
	],
	'essential-seo:schema:schema-types': ['/docs/schema/automatic-schema/'],
	'essential-seo:schema:custom-schema': ['/docs/schema/custom-schema/'],
	'essential-seo:schema:field-mapping': [
		'/docs/schema/custom-fields-to-schema/',
	],
	'essential-seo:schema:deployment': ['/docs/schema/validate-your-schema/'],
	'essential-seo:internal-links': ['/docs/advanced/internal-links/'],
	'essential-seo:external-links': ['/docs/on-page-seo/external-links/'],
	'essential-seo:redirections': ['/docs/advanced/redirections/'],
	'essential-seo:broken-links': ['/docs/advanced/broken-links/'],
	'essential-seo:woocommerce': ['/docs/advanced/woocommerce-seo/'],
	'essential-seo:social-media': [
		'/docs/site-identity-social/social-media-optimization/',
	],
	'essential-seo:focus-pages': ['/docs/analytics-reporting/focus-pages/'],
	'essential-seo:crawling-ai-indexing': [
		'/docs/sitemaps-crawling/',
		'/docs/sitemaps-crawling/robots-txt/',
	],
	'essential-seo:crawling-ai-indexing:robots-management': [
		'/docs/sitemaps-crawling/robots-txt/',
	],
	'essential-seo:crawling-ai-indexing:global-robot-meta': [
		'/docs/on-page-seo/content-type-matrix/',
	],
	'essential-seo:crawling-ai-indexing:xml-sitemap': [
		'/docs/sitemaps-crawling/xml-sitemaps/',
	],
	'essential-seo:crawling-ai-indexing:publisher-sitemaps': [
		'/docs/sitemaps-crawling/publisher-sitemaps/',
	],
	'essential-seo:crawling-ai-indexing:llms-ai-indexing': [
		'/docs/sitemaps-crawling/llms-txt/',
	],
	'essential-seo:crawling-ai-indexing:llms-full': [
		'/docs/sitemaps-crawling/llms-txt/',
	],
	'essential-seo:crawling-ai-indexing:markdown-for-ai': [
		'/docs/sitemaps-crawling/markdown-for-ai/',
	],
	'essential-seo:crawling-ai-indexing:ai-usage-policy': [
		'/docs/sitemaps-crawling/ai-usage-policy/',
	],
	'essential-seo:instant-indexing': [
		'/docs/sitemaps-crawling/instant-indexing/',
	],
	'essential-seo:role-manager': ['/docs/advanced/role-manager/'],
};

const BY_PATH = DOC_ARTICLES.reduce((map, article) => {
	map[article.path] = article;
	return map;
}, {});

/**
 * Look an article up by path.
 *
 * @param {string} path Docs path, e.g. '/docs/schema/schema-manager/'.
 * @return {Object|null} The article, or null when the path is not in the table.
 */
export const docArticle = (path) => BY_PATH[path] || null;

/**
 * Absolute URL for a docs path.
 *
 * @param {string} path Docs path.
 * @return {string} URL on the docs site.
 */
export const docsUrl = (path) => `${DOCS_BASE}${path}`;

/**
 * Identify a screen from the admin URL's query string.
 *
 * Read from the URL rather than passed down, because `nav_section` and
 * `nav_item` are URL state the SPA already owns: no component has to thread the
 * current sub-section through the tree for the drawer's benefit.
 *
 * @param {string} search Query string, e.g. window.location.search.
 * @param {string} page   SPA page id.
 * @return {string} Key for SCREEN_DOCS, most specific first.
 */
export const screenKey = (search, page) => {
	let params;
	try {
		params = new URLSearchParams(search || '');
	} catch (e) {
		params = new URLSearchParams();
	}

	const section = params.get('nav_section') || '';
	const item = params.get('nav_item') || '';

	return [page || '', section, item].filter(Boolean).join(':');
};

/**
 * Articles that cover a screen.
 *
 * Falls back from `page:section:item` to `page:section` to `page`, so a screen
 * nobody has mapped yet still opens on something relevant rather than on the
 * docs index.
 *
 * @param {string} key Key from screenKey().
 * @return {Array<Object>} Articles, most relevant first. Never undefined.
 */
export const docsForScreen = (key) => {
	const parts = String(key || '')
		.split(':')
		.filter(Boolean);

	for (let depth = parts.length; depth > 0; depth--) {
		const paths = SCREEN_DOCS[parts.slice(0, depth).join(':')];

		if (paths) {
			return paths.map(docArticle).filter(Boolean);
		}
	}

	return [];
};

/**
 * Search the article table.
 *
 * Title matches rank above summary matches, and an article whose title starts
 * with the query ranks above one that merely contains it, which is what makes
 * typing "sch" put Schema Manager first. Every term has to appear somewhere, so
 * "schema import" finds the import article and not every schema page.
 *
 * @param {string} query Raw query.
 * @param {number} limit Maximum results.
 * @return {Array<Object>} Matching articles, best first.
 */
export const searchDocs = (query, limit = 12) => {
	const terms = String(query || '')
		.toLowerCase()
		.split(/\s+/)
		.filter(Boolean);

	if (!terms.length) {
		return [];
	}

	const scored = [];

	DOC_ARTICLES.forEach((article) => {
		const title = article.title.toLowerCase();
		const haystack = `${title} ${article.summary.toLowerCase()} ${article.path}`;

		if (!terms.every((term) => haystack.includes(term))) {
			return;
		}

		let score = 0;
		terms.forEach((term) => {
			if (title.startsWith(term)) {
				score += 3;
			} else if (title.includes(term)) {
				score += 2;
			} else {
				score += 1;
			}
		});

		scored.push({ article, score });
	});

	return scored
		.sort(
			(a, b) =>
				b.score - a.score ||
				a.article.title.localeCompare(b.article.title)
		)
		.slice(0, limit)
		.map((entry) => entry.article);
};
