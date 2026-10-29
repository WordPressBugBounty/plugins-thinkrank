=== ThinkRank SEO – AI SEO Plugin for SEO & Google Search Console ===
Contributors: wpdevteam, thinkrank, re_enter_rupok, rafinkhan, rudlinkon, mdnahidhasan
Tags: seo, ai seo, schema, xml sitemap, google search console
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.20.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI SEO plugin for WordPress SEO: AI SEO tools, Google Search Console, meta descriptions, schema & XML sitemaps. Focus keywords+MCP for Claude/ChatGPT.

== Description ==

ThinkRank is an AI SEO plugin for WordPress that helps you write SEO titles and meta descriptions, optimize content around a focus keyword, add schema markup, generate XML sitemaps, and review Google Search Console data in your dashboard.

Manage SEO inside WordPress or connect a compatible AI assistant through the built-in MCP server. Use your own AI provider key for generation, review the results, and control which tasks connected assistants can perform.

= AI SEO Titles and Meta Descriptions =

Create and edit an SEO title and meta description for posts, pages, products, and supported custom post types. Generate suggestions with AI, review the search preview, and adjust the wording before applying it. Use bulk optimization to review metadata across your content and find duplicate titles and descriptions.

= Focus Keyword Optimization and Content Analysis =

Choose a focus keyword and check how your content uses it in titles, descriptions, headings, and body text. ThinkRank provides an on-page SEO score and recommendations for links, readability, and content structure. Review suggestions in the editor as you improve each page.

= Schema Markup and Structured Data =

Add JSON-LD schema markup for supported content types, including Article, FAQ, HowTo, Organization, Local Business, and Breadcrumb. Use structured data to describe your content to search engines. FAQ and HowTo blocks help you add structured content while writing; rich-result eligibility depends on the search engine and its requirements.

= XML Sitemap Generator =

Generate an XML sitemap to help search engines discover your public content. Choose which post types to include and use a sitemap index with paginated child sitemaps. ThinkRank supports Basic, Complete, E-commerce, and Segmented modes, with sitemap discovery through robots.txt.

= Google Search Console Inside WordPress =

Connect Google Search Console to review clicks, impressions, search queries, and keyword opportunities without leaving your dashboard. Use those insights to decide which pages and metadata need attention. Core SEO settings work without connecting Google.

= Built-in MCP Server for AI Assistants =

Connect compatible assistants such as Claude, ChatGPT, and Cursor to ThinkRank's SEO tools. Ask an assistant to review missing metadata, inspect SEO issues, or help manage schema and sitemap settings.

An administrator enables MCP and approves each connection. Choose access by area, review changes in the activity log, and revert supported changes when needed. ThinkRank Hub is an optional way to view connected sites together.

= llms.txt Generator for AI Tools =

Generate and maintain an llms.txt file: a Markdown index that points AI agents and tools to important content on your site. Treat it as a content-discovery aid for compatible tools. It does not guarantee Google rankings, AI Overview inclusion, or citations in AI answers.

= Technical SEO and Site Audits =

Manage canonical URLs, robots meta, robots.txt, breadcrumbs, and social sharing metadata. Review the Site SEO Analyzer for site-wide issues and guidance. ThinkRank also includes image SEO controls, Local Business information, and product metadata for WooCommerce.

= Free SEO Features, AI Usage, and ThinkRank Pro =

The free plugin includes core metadata controls, content analysis, schema controls, XML sitemaps, and a Google Search Console connection. AI generation requires your own supported provider key or a configured compatible endpoint. Provider usage charges may apply; ThinkRank does not sell AI credit packs.

ThinkRank Pro adds advanced tools such as redirects, 404 monitoring, broken-link checking, AI internal linking, keyword rank tracking, custom schema, advanced WooCommerce SEO, multi-location Local SEO, GA4 integration, and News and Video sitemaps.

= Switch to ThinkRank =

Use the setup wizard to import supported SEO data from Yoast SEO, Rank Math, AIOSEO, SEOPress, Squirrly SEO, or Slim SEO. ThinkRank supports Gutenberg, the Classic Editor, and popular page builders. Configure your SEO settings, review your imported data, and connect an AI provider or assistant when you need one.

== Installation ==

**Minimum Requirements**
* WordPress 6.0 or higher
* PHP 7.4 or higher
* An OpenAI, Anthropic Claude, Google Gemini, or OpenRouter API key (for AI-powered features)

**Automatic Installation**
1. Go to Plugins → Add New in your WordPress admin.
2. Search for "ThinkRank".
3. Click "Install Now", then "Activate".
4. Run the ThinkRank Setup Wizard.

**Setup Workflow**
1. Open the ThinkRank Setup Wizard.
2. Import existing SEO data from Rank Math, Yoast SEO, AIOSEO, SEOPress, Squirrly SEO, or Slim SEO if needed.
3. Choose your AI provider and enter your API key for AI features.
4. Configure metadata, schema, XML sitemaps, robots meta, canonical URLs, social meta, Search Console, and llms.txt (GA4 with ThinkRank Pro).
5. (Optional) Enable the MCP server under ThinkRank → AI Tools → MCP and connect Claude, ChatGPT, or Cursor.

**Connect the MCP Server**
* *Claude:* enable the MCP server, copy your site's MCP URL, add it in Claude, and approve the connection through the guided OAuth 2.1 flow - no manual API key required.
* *ChatGPT, Cursor & other MCP clients:* use the Application Password fallback and the ready-made configuration details ThinkRank provides.

**Getting API Keys**
* OpenAI: https://platform.openai.com/api-keys
* Anthropic Claude: https://console.anthropic.com/
* Google Gemini: https://ai.google.dev/gemini-api/docs/api-key
* OpenRouter: https://openrouter.ai/

== Frequently Asked Questions ==

= What is ThinkRank? =

ThinkRank is an AI SEO plugin for WordPress that helps manage metadata, on-page SEO, technical SEO, schema markup, XML sitemaps, robots meta, canonical URLs, Open Graph social meta, Google Search Console insights, llms.txt, and AI-powered SEO recommendations. It also includes a built-in MCP server so AI assistants like Claude, ChatGPT, and Cursor can help run SEO tasks in plain language.

= What is the ThinkRank MCP server? =

The ThinkRank MCP server is a built-in Model Context Protocol server that lets compatible AI assistants connect to your WordPress site and work with ThinkRank's SEO tools. After authorization, your AI assistant can help generate metadata, review SEO opportunities, work with schema, manage llms.txt, inspect SEO scores, and guide optimization workflows - without constant dashboard switching.

= How do I connect ThinkRank to Claude? =

Enable the MCP server in ThinkRank, copy your site's MCP URL, add it to Claude, and approve the connection through the guided authorization flow. ThinkRank uses an OAuth 2.1 flow with PKCE protection, so you don't need to manually copy API keys into Claude for the standard connection.

= Does ThinkRank work with ChatGPT, Cursor and other MCP clients? =

Yes. ThinkRank is designed for MCP-compatible clients. Claude has a guided one-click-style setup, and ChatGPT, Cursor, and other compatible tools connect with a connection token and the ready-made configuration details ThinkRank provides.

= Is the MCP connection secure? =

Yes. The MCP server is off by default and only an administrator can turn it on. Every connection is approved by an administrator, gets Read or Read & Write access per area (Content, Settings, Indexing, Analytics), and uses tokens that expire and are only accepted in the request header. Every change an assistant makes is recorded in the MCP Activity log with the value it replaced, so you can see who changed what and revert it in one click. You can narrow or revoke any connection under Connected AI apps.

= What is ThinkRank Hub? =

ThinkRank Hub (hub.thinkrank.ai) is an optional service for people who look after several sites. Connect a site from ThinkRank > AI Tools > ThinkRank Hub, or from the Setup Wizard, and approve it on your own site the way you approve any AI app, choosing Read or Read & Write per area. The Hub then shows every connected site's SEO reports in one view and lets one AI connection work across all of them. No license key, AI key or Google token is shared, the Hub never stores your posts' content, and Disconnect ends its access at any time. Every ThinkRank feature works without it.

= Is ThinkRank beginner friendly? =

Yes. The Setup Wizard configures the essentials and imports from your previous SEO plugin, and a Basic / Advanced switch in the Essential SEO header lets each user keep the menu to the sections a site owner needs day to day. Switching changes no settings, and every screen still opens when you need it.

= Does ThinkRank work with Elementor, Divi and Oxygen? =

Yes. ThinkRank adds a native SEO panel inside the Elementor editor, the Divi Visual Builder, and the Oxygen/Breakdance builder, alongside the Gutenberg launcher and the Classic Editor metabox. Just as importantly, ThinkRank reads the content those builders store outside `post_content`, so SEO scoring, bulk optimization, the post-list SEO column, and AI assistants analyze the real page content instead of reporting a builder page as empty.

= Does ThinkRank support WPML, Polylang and TranslatePress? =

Yes. ThinkRank detects WPML and Polylang automatically. SEO fields appear in the WPML Translation Editor, each translated page advertises its own `og:locale` and links to its alternates, XML sitemaps cover every language, and hreflang tags are only added when your multilingual plugin isn't already printing them - so you don't end up with two competing sets. On monolingual sites the integration stays completely inactive.

= What is the Site SEO Analyzer? =

The Site SEO Analyzer is a crawl-free, whole-site audit that gives your site a 0–100 score and a letter grade without requiring a Google connection. It runs checks across Basic SEO, Advanced SEO, Content, Performance & Technical, Security, and AI Search (GEO), and shows per-category results with "how to fix" guidance that deep-links to the relevant setting. Common problems, such as a missing sitemap, schema that is switched off, or images without alt text, can be fixed in one click.

= Can I control which team members access ThinkRank? =

Yes. The Role Manager lets you decide which roles can access Essential SEO, AI Tools, and Settings, using a role × capability matrix. The rules are enforced on ThinkRank's REST API as well as in the admin menu, so access is genuinely restricted rather than just hidden.

= Do I need an AI API key to use ThinkRank? =

You don't need an AI key for every core SEO feature. Metadata fields, schema controls, XML sitemaps, robots meta, canonical URLs, breadcrumbs, Open Graph, the Site SEO Analyzer, multilingual output, page-builder integrations, and the Search Console connection work without AI generation. AI-powered features - AI metadata generation, content briefs, AI insights, and generative MCP tools - require your own OpenAI, Claude, Gemini, or OpenRouter API key, or an OpenAI-compatible endpoint you run.

= Which AI providers and models does ThinkRank support? =

ThinkRank supports OpenAI, Anthropic Claude, Google Gemini, and OpenRouter, plus a custom endpoint for any OpenAI-compatible server such as Ollama, LM Studio, vLLM or Azure OpenAI, so content can stay on your own infrastructure. Model availability depends on your provider account and configured API key. A daily request limit and a Pause all AI switch keep AI spending under your control.

= Does ThinkRank generate SEO titles and meta descriptions? =

Yes. ThinkRank can generate SEO titles and meta descriptions with AI and lets you edit them before publishing, across posts, pages, products, and supported custom post types.

= Does ThinkRank include SEO content analysis and focus keywords? =

Yes. ThinkRank includes real-time SEO content analysis and focus keyword optimization so you can see how well your content targets important search terms and improve title, description, headings, structure, and keyword usage before publishing.

= Does ThinkRank generate schema markup? =

Yes. ThinkRank generates JSON-LD schema markup and structured data for Organization, Website, Article, FAQ, HowTo, VideoObject, Review, Local Business, and Breadcrumb schema, and includes Gutenberg blocks for FAQ, HowTo, and Table of Contents that output FAQPage, HowTo, and SiteNavigationElement structured data. Schema markup helps search engines understand your content and can support rich snippets.

= Does ThinkRank create XML sitemaps? =

Yes. ThinkRank includes an XML sitemap generator with Basic, Complete, E-commerce, and Segmented modes, plus a real sitemap index with paginated child sitemaps, controls for post types, and sitemap discovery through robots.txt.

= What is llms.txt and why does ThinkRank support it? =

llms.txt is an emerging convention: a Markdown index of your important content, kept at a fixed URL, that AI coding agents and agentic tools read to discover site content. ThinkRank generates and maintains it automatically. To be straight with you: Google has said llms.txt does not affect Google Search or AI Overviews, and we don't claim it earns AI citations - it is useful infrastructure for AI tooling, and that is the claim we stand behind.

= Does ThinkRank work with Google Search Console and GA4? =

Yes. ThinkRank connects with Google Search Console to show clicks, impressions, queries, and keyword opportunities inside WordPress - including a dashboard widget with your last 30 days of traffic. Google Analytics 4 (GA4) is a ThinkRank Pro feature: Pro lets you pick your GA4 account, property, and data stream and adds GA4 traffic and engagement data to the dashboard and email reports.

= Does ThinkRank support Open Graph, canonical URLs and noindex? =

Yes. ThinkRank includes Open Graph and social meta controls for Facebook, LinkedIn, Pinterest, and X/Twitter, plus canonical URL controls and robots meta settings such as noindex and nofollow.

= Is ThinkRank a Yoast, Rank Math, AIOSEO or SEOPress alternative? =

Yes. ThinkRank combines core WordPress SEO tools with a built-in MCP server and bring-your-own-key AI generation. Use the setup wizard to import supported SEO data from your previous plugin. Compare the features and plans you need before switching; AI provider usage is billed separately by your provider.

= Can I migrate from AIOSEO, SEOPress, Squirrly SEO or Slim SEO? =

Yes. ThinkRank's Setup Wizard imports supported SEO data from All in One SEO (AIOSEO), SEOPress, Squirrly SEO and Slim SEO (including Slim SEO Pro's focus keywords and schemas), along with Rank Math and Yoast SEO - including title formats, Knowledge Graph details, role permissions, author archive settings, IndexNow keys, breadcrumb settings, and social defaults.

= Does ThinkRank include a redirect manager, 404 monitor, or internal linking? =

Redirect management, 404 monitoring, the broken link checker, and AI internal linking are available in ThinkRank Pro. The free plugin shows these sections with an option to upgrade.

= Does ThinkRank help with WooCommerce and Local SEO? =

Yes. The free plugin supports product metadata, an E-commerce sitemap mode, Local Business schema, business information, and a local sitemap. Advanced WooCommerce product SEO and multi-location Local SEO are available in ThinkRank Pro.

= Will ThinkRank conflict with my current SEO plugin? =

For best results, use only one primary SEO plugin at a time - running two can create duplicate meta tags, duplicate schema, conflicting robots meta, and sitemap confusion. ThinkRank's migration workflow imports supported data and can deactivate the previous SEO plugin when migration is complete.

= Who is ThinkRank for? =

WordPress site owners, bloggers and publishers producing content at scale, SEO professionals and agencies managing client sites, WooCommerce stores and local businesses, and teams that run their work through an AI assistant.

= Is ThinkRank free? =

Yes, ThinkRank is a free WordPress SEO plugin with bring-your-own-key AI features - your AI provider usage is billed by the provider you choose, giving you direct control over model, cost, and privacy. There are no ThinkRank AI credits to buy and no monthly meter, in free or in Pro. ThinkRank Pro adds advanced automation such as the redirect manager, 404 monitor, internal linking, and rank tracker.

== External services ==

Core SEO features need no external service. Each service below is contacted only when you use or switch on the feature that needs it - never to license or unlock ThinkRank features.

= AI providers: OpenAI, Anthropic, Google Gemini, OpenRouter =

For AI features: metadata and content brief generation, AI insights, "Explain with AI", image alt text, and the generative MCP tools. Only providers you add an API key for are contacted. ThinkRank sends that key and the content the task needs (such as a post's title and content, your prompt, or image data) when you run an AI action, or in the background for automatic AI features you switch on.

* OpenAI (api.openai.com): [Terms](https://openai.com/policies/services-agreement/), [Privacy](https://openai.com/policies/row-privacy-policy/)
* Anthropic (api.anthropic.com): [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy](https://www.anthropic.com/legal/privacy)
* Google Gemini (generativelanguage.googleapis.com): [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy)
* OpenRouter (openrouter.ai): [Terms](https://openrouter.ai/terms), [Privacy](https://openrouter.ai/privacy)

= OpenAI-compatible endpoint you configure =

Optional. If you choose the "Custom endpoint" AI provider and enter a base URL, the AI features above send the same content - and the API key you enter, if any - to that URL instead of to a provider above. Nothing is sent until you configure it, and where it goes is entirely your choice: a model on your own server (Ollama, LM Studio, vLLM), your Azure OpenAI deployment, your company gateway, or a hosted service. ThinkRank has no relationship with that endpoint, so its own terms and privacy policy apply.

= ThinkRank Google connection (api.thinkrank.ai) =

Clicking Connect Google (Search Console, GA4) signs in through api.thinkrank.ai, which holds ThinkRank's Google app credentials. It receives your site URL and a one-time sign-in code on connect, and your Google refresh token whenever the access token is renewed. [Terms](https://thinkrank.ai/terms-and-conditions/), [Privacy](https://thinkrank.ai/privacy-policy/)

= ThinkRank Hub (hub.thinkrank.ai, optional) =

Only if you click Connect to ThinkRank Hub under ThinkRank > AI Tools > ThinkRank Hub, or on the Setup Wizard's ThinkRank Hub step. Connecting turns on MCP access and sends your browser to hub.thinkrank.ai with this site's address and a one-time ticket. You then approve the hub on this site's own approval page, the way any AI client is approved, choosing for each area whether the hub can read only or also make changes it can undo. At the approval the site tells the hub its address, its blog ID, the ThinkRank (and ThinkRank Pro) versions and its public WordPress site icon URL. MCP initialization repeats these public facts so the hub can refresh them without another connection, which means every MCP client you have approved also receives them.

Under that approval, within the areas you approve, the hub and the AI assistants you connect to the hub can read over MCP: SEO Analyzer reports, settings, posts' and pages' content and SEO fields, Search Console, Google Analytics and PageSpeed data, indexing history and the MCP activity log. The hub keeps the reports and figures it reads, never your posts' content. With changes allowed, they make only the changes the approval and ThinkRank allow, each recorded in the MCP activity log where it can be reverted. The hub also checks this site's public pages from outside (the home page, robots.txt, the sitemap and llms.txt) to report uptime and crawlability. No license key, AI provider key or Google token travels. Connecting is optional: every feature works without the hub, and you can end its access at any time with Disconnect on the same screen. [Terms](https://thinkrank.ai/terms-and-conditions/), [Privacy](https://thinkrank.ai/privacy-policy/)

= Google APIs: Search Console, Analytics Data, PageSpeed Insights, OAuth =

After you connect Google or add a PageSpeed Insights key, ThinkRank sends your access token or API key, your Search Console property, and page URLs to test, to www.googleapis.com - when you open analytics or performance screens or the dashboard widget, and when scheduled reports or checks run. Disconnecting sends the token to oauth2.googleapis.com to revoke it. [Terms](https://developers.google.com/terms), [Privacy](https://policies.google.com/privacy)

= IndexNow (api.indexnow.org) =

Only with Instant Indexing on: your host name, IndexNow key and key file location, and the URLs you publish, delete or submit, shared with participating search engines. [Terms](https://www.indexnow.org/terms), [Privacy](https://privacy.microsoft.com/privacystatement)

= WP Insights usage tracking (send.wpinsight.com, opt-in) =

Nothing is sent unless you opt in, from the admin notice or by choosing "Allow & Continue" on the Setup Wizard's first step ("Skip" sends nothing). Then, on opt-in and daily after: site URL and name, admin email, WordPress, PHP and server versions, active and inactive plugins, active theme, the plugin's file path, and which ThinkRank features you use. Optional deactivation feedback is sent too. The email may be used for ThinkRank news and offers. [Terms](https://wpdeveloper.com/terms-and-conditions/), [Privacy](https://wpdeveloper.com/privacy-policy/)

= Websites you enter =

Content brief competitor analysis and schema import fetch the URLs you type in, from your server. Nothing else is sent to those sites.

== Source code and build ==

The JavaScript and CSS in `assets/` are compiled. Their human-readable source ships with the plugin in `src/`.

The assets are built from `src/` with `@wordpress/scripts` (webpack), which writes the compiled files to `assets/`.

== Screenshots ==

1. Agentic AI SEO - connect Claude, ChatGPT, or Cursor to your WordPress SEO with the built-in MCP server.
2. Your AI assistant generating and updating WordPress SEO metadata in plain language via ThinkRank's MCP tools.
3. AI Metadata Generator - SEO title and meta description with a live SERP preview.
4. SEO content analysis dashboard - 13-factor score, focus keyword, and one-click apply suggestions.
5. Schema Manager - choose Article, FAQ, Local Business, or Review schema with validation.
6. XML Sitemap generator - Basic, Complete, E-commerce, and Segmented modes with post-type controls.
7. llms.txt generator - a maintained Markdown index of your content for AI agents and tools.
8. Google Search Console insights with Core Web Vitals inside WordPress.
9. AI Content Brief Generator with competitor analysis and content gaps.

== Changelog ==

= 2.20.1 =
Release Date: 2026-10-29

- Fixed: Sitemaps keep their styled view in Chrome 158 and other browsers that drop XSLT
- Fixed: AI visits are counted on cached pages and from ChatGPT links with no referrer
- Fixed: WooCommerce Product schema starts from the product's price, currency, SKU and stock
- Fixed: Global SEO templates fill price, SKU and other WooCommerce variables on the front end
- Fixed: Thin Content and Duplicate Snippets leave out noindexed and redirected pages
- Fixed: The SEO panel no longer restores an old draft over newer saved values
- Fixed: Schema Deployment waits until unsaved Schema changes are saved
- Fixed: Article schema names the configured organization as its publisher
- Fixed: External Links and Image SEO apply to Bricks content, and builder image alts are read
- Fixed: Minor Bricks editor, SEO Analyzer, MCP Activity, OpenRouter and alt fill issues

= 2.20.0 =
Release Date: 2026-10-28

- New: Connect Gemini, Gemini CLI, Grok, Codex, Windsurf and Le Chat from the MCP screen
- New: ThinkRank's breadcrumb trail shows in the Kadence theme
- Improved: SEO changes now clear xSpeed and seven more page caches, and warn about host caches
- Fixed: Chinese, Japanese and Thai posts get a fair word count, keyword density and SEO score
- Fixed: Content Type Matrix "On" now outputs schema and social tags that are off site-wide
- Fixed: A site that represents a person publishes a Person instead of an Organization
- Fixed: Rank Math import keeps Product and Event schema fields and lists the schema it left behind
- Fixed: Image sitemaps list the images on Oxygen, Breakdance, Bricks and Divi pages
- Fixed: Title formats and author archive templates drop variables they cannot fill
- Fixed: Minor robots.txt, llms.txt, SEO score and MCP issues

= 2.19.0 =
Release Date: 2026-10-27

- New: Bulk SEO templates take price, SKU, stock and custom field variables, previewed on real items
- New: Business hours can have more than one opening range a day, such as a lunch break
- New: A Help drawer shows the documentation for the screen you are on
- New: SureCart support, with ThinkRank's title, Open Graph and Product schema on product pages
- New: Connect opencode, or any other MCP client, to your site
- Improved: Breadcrumb and hero texts translate per language with WPML and Polylang
- Improved: IndexNow pauses on staging copies and hidden sites, and the settings screen says so
- Improved: Content Type Matrix search matches slugs and keeps the content type column in view
- Fixed: Your Site Identity Site Name now appears everywhere the site is named
- Fixed: Minor schema, sitemap, robots.txt, image alt, title, WooCommerce sharing, MCP and AI issues

= 2.18.1 =
Release Date: 2026-10-26

- Improved: The SEO Analyzer does not score llms.txt while it is off, and credits WordPress's sitemap
- Changed: Focus keywords are no longer printed as a meta keywords tag
- Fixed: Search charts mark days Google is still processing and leave gaps for days with no data
- Fixed: A static posts page uses its own SEO title, description and social tags
- Fixed: Descriptions keep spaces between blocks and use the excerpt before the body
- Fixed: FAQ schema reads question headings, and HowTo and TOC schema stay off archives and search
- Fixed: Sitemaps leave out a noindexed homepage and pages canonicalised to another URL
- Fixed: Article author links point to a working URL when author archives are off
- Fixed: Minor robots.txt, word count, WPML sitemap, schema, MCP and Claude AI issues
- Removed: The Exclude Private Posts sitemap setting, which never had an effect

= 2.18.0 =
Release Date: 2026-10-25

- New: AI agents can edit a post's body and URL slug over MCP, with a preview first and an undo
- New: SEO scores and suggestions follow each post's own language, with French readability rules
- Improved: The admin screens and editor SEO panels now show in your site's language
- Fixed: WooCommerce cart, checkout and account pages are now noindex, as WooCommerce asks
- Fixed: Preview no longer publishes unsaved SEO fields on a published post
- Fixed: The SEO score is the same whole number in the editor and the posts list
- Fixed: Author archives follow the Metas switch in the Content Type Matrix
- Fixed: The sitemap drops a post as soon as it is unpublished
- Fixed: Instant Indexing sends nothing while off and announces trashed and unpublished posts
- Fixed: Minor email report subject, AI pause and social image setting issues

[See changelog for all versions](https://thinkrank.ai/changelog/).

== Upgrade Notice ==

= 2.20.1 =
Sitemaps keep their styled view once Chrome drops XSLT, AI visits on cached pages are counted, and WooCommerce Product schema starts from the product. Recommended for all sites.

= 2.20.0 =
Adds Gemini, Grok, Codex, Windsurf and Le Chat to the MCP guide and Kadence breadcrumbs. Chinese, Japanese and Thai posts get fair word counts, and title formats drop unknown variables. Recommended for all sites.
