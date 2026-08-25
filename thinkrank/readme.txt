=== ThinkRank AI SEO – AI SEO Plugin for WordPress: Schema, XML Sitemaps, Meta Tags, Search Console & Local SEO ===
Contributors: wpdevteam, thinkrank, re_enter_rupok, rafinkhan, rudlinkon, mdnahidhasan
Tags: seo, ai seo, schema, xml sitemap, google search console
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress SEO plugin with AI SEO: meta descriptions, schema, XML sitemaps, Search Console, llms.txt, AEO & GEO. Your AI SEO assistant runs it via MCP.

== Description ==

**[ThinkRank](https://thinkrank.ai/) is the AI SEO plugin for WordPress that your AI assistant can operate.** Ask Claude, ChatGPT, or Cursor to write metadata, audit posts, fix schema, and run bulk SEO work on your site — in plain language, from the chat window you already use. Underneath sits a complete SEO plugin: meta tags (SEO titles and meta descriptions), real-time on-page content analysis, keyword optimization, schema markup and structured data, XML sitemaps, robots meta, canonical URLs, Open Graph and Twitter Cards, Local SEO, llms.txt, and Google Search Console + GA4 insights — built for Google and for AI search (AEO and GEO) alike.

Two things make ThinkRank different, and both are checkable:

* **Your assistant runs it directly.** ThinkRank ships a built-in connection for AI assistants (a self-hosted MCP server) — no companion plugin, no terminal. No other WordPress SEO plugin ships one.
* **Every AI feature is included, with no credit meter.** You bring your own API key (OpenAI, Claude, Gemini, OpenRouter), so there are no monthly AI credits to buy, meter, or watch expire — in free and in Pro alike.

Switching? The Setup Wizard **imports your data from Yoast SEO, Rank Math, AIOSEO, and SEOPress** and takes over cleanly — details below.

It works where you already build: **Gutenberg, Elementor, Divi, Oxygen/Breakdance, and the Classic Editor** — and on multilingual sites running **WPML, Polylang or TranslatePress**.

= Watch: SEO in the Assistant Era =

SEO creator Tin Rovic on how AI assistants and answer engines are changing day-to-day SEO work:

https://youtu.be/TEzfS2dAMC8

= Run Your SEO From a Chat Window — Claude, ChatGPT and Cursor =

ThinkRank ships a self-contained **Model Context Protocol (MCP) server** built right into the plugin — no companion plugin, no external libraries, no terminal. It turns your AI assistant into an **SEO operator, not just an SEO copywriter**.

* **Connect Claude, ChatGPT, Cursor** or any MCP-compatible AI assistant to your WordPress site.
* **Ask for SEO in plain language:**
  * "Write an SEO title and meta description for this post."
  * "Which posts are missing SEO metadata?"
  * "Add FAQ schema to this page."
  * "Generate an llms.txt file for my website."
  * "Review this page and suggest on-page SEO improvements."
  * "Check Search Console opportunities for pages with high impressions and low CTR."
* **35+ SEO tools** exposed to your assistant — metadata, schema, site identity, sitemaps, robots.txt & robots meta, image SEO, social meta, instant indexing, llms.txt, SEO scores, insights and opportunities.
* **Connection health check** — a "Test connection" button makes a real call and tells you exactly which step failed.
* **Safe imports** — connected assistants can preview an SEO import as a dry run before anything is written.

= Easy Claude, ChatGPT, Cursor and MCP Client Setup =

* **One-click Claude connection** via a guided OAuth 2.1 flow with PKCE — no API key to copy, no config file.
* **Application Password fallback** for ChatGPT, Cursor, and other MCP-compatible clients, with ready-made configuration details.
* **Admin-controlled, off by default, revocable in one click** — enable it under ThinkRank → MCP when you're ready; delete the connected Application Password and access ends immediately.

= Migrate from Rank Math, Yoast SEO, AIOSEO or SEOPress =

A guided Setup Wizard detects your current SEO plugin and imports your metadata, schema settings, sitemap settings, image SEO data, templates, and related SEO settings from **Rank Math SEO, Yoast SEO, All in One SEO (AIOSEO), and SEOPress** — then deactivates the old plugin once its data is safely migrated.

The importers go well past basic post meta: title formats, Knowledge Graph identity, author archive and role settings, IndexNow keys and history, sitemap exclusions, breadcrumbs, social defaults, News/Video sitemap post types and local business details all carry over.

Looking for a **Rank Math, Yoast SEO, AIOSEO or SEOPress alternative** because of upsell notices, metered AI credits or per-site pricing? This migration path is built for exactly that switch.

= AI SEO Metadata Generator: Meta Tags, SEO Titles and Meta Descriptions =

* Generate SEO title suggestions for posts, pages, products, and custom post types.
* AI meta descriptions written for search snippets and click-through rate.
* Live SERP preview before you publish.
* Apply suggestions with one click — no copy-pasting.
* Fully editable fields with manual override.

= SEO Content Analysis, Focus Keywords and Keyword Optimization =

* Real-time on-page SEO content analysis with a 13-factor SEO score.
* Focus keyword tracking and usage (up to 5 keywords per post) with cannibalization warnings.
* Actionable recommendations for title, meta description, headings, links, readability, and structure.
* One-click "Apply" for AI-suggested fixes — not just generic advice.
* **"Explain with AI"** on any suggestion — a short, post-specific explanation of why it matters and how to fix it.
* **Bulk SEO Optimization** — review and fix titles, descriptions, and keywords across many posts from one screen.

= Site SEO Analyzer — a Whole-Site Audit With No Google Connection =

* A crawl-free, whole-site SEO audit with a **0–100 score and letter grade**.
* Checks across **Basic SEO, Advanced SEO, Content, Performance & Technical, and Security**.
* Per-category results with "how to fix" guidance, plus deep links straight to the relevant setting.
* Runs without connecting Google — useful on staging, new sites, and client audits.

= Schema Markup and Structured Data for Rich Snippets =

Output valid JSON-LD structured data so search engines can show rich results:

* Organization, Website, Article, FAQ, HowTo, VideoObject, Review, Local Business, and Breadcrumb schema.
* Out-of-the-box schema on posts, pages, CPTs, archives, and the homepage.
* **Import Schema From Any Website** to clone a competitor's structured data as a starting point.
* Deployment validation to catch structured-data errors before they reach Search Console.

= SEO Blocks: FAQ, HowTo and Table of Contents =

* **FAQ block** — inline Q&A rendered as an accordion that works with **no JavaScript**, and outputs FAQPage structured data automatically.
* **HowTo block** — step-by-step instructions with per-step images and total time, with automatic HowTo schema.
* **Table of Contents block** — builds its list live from your headings, adds anchor links that work without JavaScript, and emits SiteNavigationElement schema.
* **Elementor widgets** for all three, so Elementor-built pages get the same content patterns and structured data as Gutenberg.

= Works With Every Page Builder =

Page builders store their content outside `post_content`, which is why SEO plugins often report a 1,500-word page as empty. ThinkRank reads each builder's own stored content, so scoring, bulk optimization, the post-list SEO column, cron reports, and AI assistants all see the real words on the page.

* **Gutenberg / Block Editor** — a pinned "Configure SEO" launcher in the editor header with a live SEO score badge.
* **Elementor** — edit ThinkRank SEO fields without leaving the Elementor editor.
* **Divi** — a ThinkRank button in the Divi Visual Builder page bar opens the full SEO panel over the canvas.
* **Oxygen / Breakdance** — a floating launcher inside the builder opens the same SEO panel, with content read straight from the builder's node tree.
* **Classic Editor** — the full ThinkRank metabox with a bottom drawer and live SEO pattern previews.

= Multilingual SEO for WPML, Polylang and TranslatePress =

* **SEO fields in the WPML Translation Editor** — SEO title, meta description, social titles/descriptions, and focus keyword are exposed as translatable strings, so translators no longer need to open every language by hand.
* **Correct `og:locale` and `og:locale:alternate`** — each translated page advertises its own language and links to its alternates for social crawlers.
* **hreflang without duplicates** — ThinkRank emits hreflang tags **only** when WPML or Polylang isn't already printing them, so you never end up with two competing sets.
* **Language-aware XML sitemaps** — the sitemap covers every language, instead of only the one that was active when it was generated.
* Detected automatically, and completely inactive on monolingual sites.

= XML Sitemap Generator and Indexing Tools =

* Multiple sitemap modes: Basic, Complete, E-commerce, and Segmented.
* Real sitemap index with paginated child sitemaps, automatic splitting of oversized sitemaps, and per-post-type controls.
* Memory-efficient generation that stays fast on large sites.
* AI-optimized robots.txt with automatic sitemap discovery.
* **Instant Indexing** — submit new and updated URLs straight to search engines (IndexNow) for faster indexing, in the background via WP-Cron.

= llms.txt Generator =

* Generate and maintain an llms.txt file — a Markdown index of your important content that AI coding agents and agentic tools read by convention.
* Straight talk, because you deserve a vendor that gives it: Google has stated llms.txt does not affect Google Search or AI Overviews, and no reliable evidence ties the file to AI citations. We ship it as useful infrastructure for AI tooling, not as a ranking lever.
* Auto-regenerates as your content changes, with full control over what's included.

= Built for AI Search: AEO and GEO =

**Answer Engine Optimization (AEO)** is being the answer ChatGPT, Perplexity, Gemini, Claude and Google AI Overviews give; **Generative Engine Optimization (GEO)** is being the source they cite. ThinkRank ships the groundwork both depend on — no unverifiable ranking claims:

* **Structured data AI systems can parse** — Organization, Article, FAQ, HowTo, Product, Local Business and breadcrumb schema, validated before output.
* **Consistent on-page signals** — meta titles and descriptions, canonical URLs, robots directives and Open Graph that agree everywhere, so answer engines read the page as you intend.
* **llms.txt** — a maintained Markdown index of your key content for AI agents.
* **An AI assistant in the loop** — over MCP, ask Claude or ChatGPT to review a page the way an answer engine reads it and apply the fixes from the same chat.

= Google Search Console, GA4 and SEO Insights =

* Google Search Console: clicks, impressions, queries, and keyword opportunities inside WordPress.
* **Website Insights dashboard widget** — your last 30 days of Search Console traffic, top queries, and headline metrics right on the WordPress dashboard.
* Google Analytics 4: traffic and organic performance.
* PageSpeed Insights and Core Web Vitals monitoring.
* AI-powered, natural-language explanations, trends, and scheduled email SEO reports.

= On-Page & Technical SEO: Robots Meta, Canonical URLs, Robots.txt, Breadcrumbs =

* Canonical URL controls to manage duplicate-content signals.
* Robots meta settings (noindex, nofollow) per post and globally.
* Robots.txt management with sitemap linking, kept in sync with the live file.
* Schema-enabled breadcrumb navigation, with context-aware titles and an optional border.
* Site identity and title-format management, including Tag and Archive title formats with context-aware variable buttons.

= Open Graph, Twitter Cards and Social Media Previews =

* Open Graph metadata, social titles, and descriptions for every social media share.
* Twitter Cards (X) support.
* Facebook, LinkedIn, and Pinterest previews with real-time editing.
* Social profile fields validated before they're published as verification tags.

= AI Content Brief Generator =

* Generate a full content brief — outline, headings, entities, and content gaps — from a target keyword.
* Competitor analysis so you can see what's already ranking.
* Generate a **complete article draft**, not just an outline, in a tabbed layout.
* Save briefs and export them in multiple formats.

= Image SEO, Local SEO & WooCommerce =

* **Image SEO** — automated alt text written to the Media Library record itself, so it works everywhere (not just in rendered content). Bulk-fill every image missing alt text, auto-fill new uploads, and optionally overwrite existing alt text.
* **Local SEO** — business information, opening hours, location data, Local Business schema, and a local sitemap.
* **WooCommerce** — product metadata and E-commerce sitemap support (advanced product SEO is available in ThinkRank Pro).

= Role Manager: Team and Client Access Control =

* Control which roles can access Essential SEO, AI Tools, and Settings areas.
* A role × capability matrix, so editors, authors, contributors, and custom roles get exactly the SEO access you intend.
* Access rules are enforced on the REST API too, not just hidden in the menu.

= Bring Your Own AI Provider Key =

ThinkRank works with **OpenAI, Anthropic Claude, Google Gemini, OpenRouter, and compatible custom endpoints** — you bring your own key, so you keep direct control over model selection, cost, and privacy.

* **No AI key needed for:** metadata, schema, XML sitemaps, robots meta, canonical URLs, breadcrumbs, Open Graph, Search Console/GA4, the Site SEO Analyzer, multilingual output and page-builder integrations.
* **AI key required for:** AI metadata generation, content briefs, AI insights, "Explain with AI", and the generative MCP tools.

= ThinkRank Pro =

Upgrade to **ThinkRank Pro** for advanced, agentic-ready SEO automation:

* **Redirect Manager & 404 Monitor** — create and manage redirects (301/302/307/308/410/451) and log 404s with one-click "create redirect from 404".
* **AI Internal Linking** — relevance-ranked internal-link suggestions with one-click insertion, in bulk by post type.
* **Broken Link Checker** — scan content, verify links, and fix, unlink, or dismiss broken URLs.
* **Rank Tracker** — track keyword positions over time with daily Search Console snapshots and per-keyword history charts.
* **Custom Schema & Display Conditions** — add any JSON-LD schema type and control exactly where it outputs, with live validation.
* **Advanced WooCommerce SEO** — product identifiers (GTIN/MPN/ISBN), variation offers, and product Open Graph.
* **Multi-location Local SEO** — a locations table, per-location LocalBusiness schema, and the `[thinkrank_locations]` shortcode.
* **Publisher Sitemaps** — News and Video sitemaps — plus additional focus keywords.
* **Advanced Analytics** — GA4 users overview, traffic channels, top content, and URL index status inside WordPress.
* Pro features are exposed to connected AI assistants through the MCP server too.

= Perfect For =

* WordPress site owners who want AI-powered SEO
* Bloggers and publishers producing content at scale
* SEO professionals and agencies managing client sites
* WooCommerce stores and local businesses
* Elementor, Divi, and Oxygen/Breakdance site builders
* Multilingual sites running WPML or Polylang
* Content teams, developers, and teams that run their work through an AI assistant
* Publishers who want to show up in AI answers and citations (AEO and GEO)

= Why Choose ThinkRank? =

* The only WordPress SEO plugin your AI assistant can operate directly (built-in MCP server)
* Every AI feature included at a flat price — your own API key, no monthly credit meter
* Real on-page and technical SEO — not just an AI writer
* A whole-site SEO Analyzer that needs no Google connection
* Accurate scoring on Elementor, Divi, and Oxygen/Breakdance pages
* WPML and Polylang support with correct hreflang and per-language sitemaps
* Schema markup and structured data for rich snippets, plus FAQ/HowTo/TOC blocks
* XML sitemap generator, robots.txt, instant indexing, and llms.txt
* AEO and GEO groundwork — structured data, clean on-page signals and llms.txt for ChatGPT, Perplexity, Gemini and AI Overviews
* One-click migration from Rank Math, Yoast, AIOSEO, and SEOPress

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
2. Import existing SEO data from Rank Math, Yoast SEO, AIOSEO, or SEOPress if needed.
3. Choose your AI provider and enter your API key for AI features.
4. Configure metadata, schema, XML sitemaps, robots meta, canonical URLs, social meta, Search Console, GA4, and llms.txt.
5. (Optional) Enable the MCP server under ThinkRank → MCP and connect Claude, ChatGPT, or Cursor.

**Connect the MCP Server**
* *Claude:* enable the MCP server, copy your site's MCP URL, add it in Claude, and approve the connection through the guided OAuth 2.1 flow — no manual API key required.
* *ChatGPT, Cursor & other MCP clients:* use the Application Password fallback and the ready-made configuration details ThinkRank provides.

**Getting API Keys**
* OpenAI: https://platform.openai.com/api-keys
* Anthropic Claude: https://console.anthropic.com/
* Google Gemini: https://ai.google.dev/gemini-api/docs/api-key
* OpenRouter: https://openrouter.ai/

== Frequently Asked Questions ==

= What is ThinkRank? =

ThinkRank is an AI SEO plugin for WordPress that helps manage metadata, on-page SEO, technical SEO, schema markup, XML sitemaps, robots meta, canonical URLs, Open Graph social meta, Google Search Console insights, GA4 analytics, llms.txt, and AI-powered SEO recommendations. It also includes a built-in MCP server so AI assistants like Claude, ChatGPT, and Cursor can help run SEO tasks in plain language.

= What is the ThinkRank MCP server? =

The ThinkRank MCP server is a built-in Model Context Protocol server that lets compatible AI assistants connect to your WordPress site and work with ThinkRank's SEO tools. After authorization, your AI assistant can help generate metadata, review SEO opportunities, work with schema, manage llms.txt, inspect SEO scores, and guide optimization workflows — without constant dashboard switching.

= How do I connect ThinkRank to Claude? =

Enable the MCP server in ThinkRank, copy your site's MCP URL, add it to Claude, and approve the connection through the guided authorization flow. ThinkRank uses an OAuth 2.1 flow with PKCE protection, so you don't need to manually copy API keys into Claude for the standard connection.

= Does ThinkRank work with ChatGPT, Cursor and other MCP clients? =

Yes. ThinkRank is designed for MCP-compatible clients. Claude has a guided one-click-style setup, and ChatGPT, Cursor, and other compatible tools can connect using the Application Password fallback and ready-made configuration details provided by ThinkRank.

= Is the MCP connection secure? =

Yes. The MCP server is off by default, admin-controlled, and designed for authorized users only. Connections are tied to WordPress authorization and Application Passwords. If you delete the connected Application Password, access is revoked immediately.

= Does ThinkRank work with Elementor, Divi and Oxygen? =

Yes. ThinkRank adds a native SEO panel inside the Elementor editor, the Divi Visual Builder, and the Oxygen/Breakdance builder, alongside the Gutenberg launcher and the Classic Editor metabox. Just as importantly, ThinkRank reads the content those builders store outside `post_content`, so SEO scoring, bulk optimization, the post-list SEO column, and AI assistants analyze the real page content instead of reporting a builder page as empty.

= Does ThinkRank support WPML, Polylang and TranslatePress? =

Yes. ThinkRank detects WPML and Polylang automatically. SEO fields appear in the WPML Translation Editor, each translated page advertises its own `og:locale` and links to its alternates, XML sitemaps cover every language, and hreflang tags are only added when your multilingual plugin isn't already printing them — so you don't end up with two competing sets. On monolingual sites the integration stays completely inactive.

= What is the Site SEO Analyzer? =

The Site SEO Analyzer is a crawl-free, whole-site audit that gives your site a 0–100 score and a letter grade without requiring a Google connection. It runs checks across Basic SEO, Advanced SEO, Content, Performance & Technical, and Security, and shows per-category results with "how to fix" guidance that deep-links to the relevant setting.

= Can I control which team members access ThinkRank? =

Yes. The Role Manager lets you decide which roles can access Essential SEO, AI Tools, and Settings, using a role × capability matrix. The rules are enforced on ThinkRank's REST API as well as in the admin menu, so access is genuinely restricted rather than just hidden.

= Do I need an AI API key to use ThinkRank? =

You don't need an AI key for every core SEO feature. Metadata fields, schema controls, XML sitemaps, robots meta, canonical URLs, breadcrumbs, Open Graph, the Site SEO Analyzer, multilingual output, page-builder integrations, and Search Console/GA4 connections work without AI generation. AI-powered features — AI metadata generation, content briefs, AI insights, and generative MCP tools — require your own OpenAI, Claude, Gemini, OpenRouter, or compatible provider key.

= Which AI providers and models does ThinkRank support? =

ThinkRank supports OpenAI, Anthropic Claude, Google Gemini, OpenRouter, and compatible custom endpoints. Model availability depends on your provider account and configured API key.

= Does ThinkRank generate SEO titles and meta descriptions? =

Yes. ThinkRank can generate SEO titles and meta descriptions with AI and lets you edit them before publishing, across posts, pages, products, and supported custom post types.

= Does ThinkRank include SEO content analysis and focus keywords? =

Yes. ThinkRank includes real-time SEO content analysis and focus keyword optimization so you can see how well your content targets important search terms and improve title, description, headings, structure, and keyword usage before publishing.

= Does ThinkRank generate schema markup? =

Yes. ThinkRank generates JSON-LD schema markup and structured data for Organization, Website, Article, FAQ, HowTo, VideoObject, Review, Local Business, and Breadcrumb schema, and includes Gutenberg blocks for FAQ, HowTo, and Table of Contents that output FAQPage, HowTo, and SiteNavigationElement structured data. Schema markup helps search engines understand your content and can support rich snippets.

= Does ThinkRank create XML sitemaps? =

Yes. ThinkRank includes an XML sitemap generator with Basic, Complete, E-commerce, and Segmented modes, plus a real sitemap index with paginated child sitemaps, controls for post types, and sitemap discovery through robots.txt.

= What is llms.txt and why does ThinkRank support it? =

llms.txt is an emerging convention: a Markdown index of your important content, kept at a fixed URL, that AI coding agents and agentic tools read to discover site content. ThinkRank generates and maintains it automatically. To be straight with you: Google has said llms.txt does not affect Google Search or AI Overviews, and we don't claim it earns AI citations — it is useful infrastructure for AI tooling, and that is the claim we stand behind.

= Does ThinkRank work with Google Search Console and GA4? =

Yes. ThinkRank connects with Google Search Console to show clicks, impressions, queries, and keyword opportunities inside WordPress — including a dashboard widget with your last 30 days of traffic — and supports GA4 for traffic and organic-performance insights.

= Does ThinkRank support Open Graph, canonical URLs and noindex? =

Yes. ThinkRank includes Open Graph and social meta controls for Facebook, LinkedIn, Pinterest, and X/Twitter, plus canonical URL controls and robots meta settings such as noindex and nofollow.

= Is ThinkRank a Yoast, Rank Math, AIOSEO or SEOPress alternative? =

Yes. ThinkRank covers the core SEO those plugins cover — metadata, schema, XML sitemaps, robots meta, canonical URLs, Search Console and GA4 insights — plus two things they don't sell at any price: an AI-assistant connection that operates the plugin directly, and AI features at a flat price with your own key instead of metered credits. The Setup Wizard imports your existing Rank Math, Yoast, AIOSEO, or SEOPress data and can deactivate the old plugin once migration completes.

= Can I migrate from AIOSEO or SEOPress? =

Yes. ThinkRank's Setup Wizard imports supported SEO data from All in One SEO (AIOSEO) and SEOPress, along with Rank Math and Yoast SEO — including title formats, Knowledge Graph details, role permissions, author archive settings, IndexNow keys, breadcrumb settings, and social defaults.

= Does ThinkRank include a redirect manager, 404 monitor, or internal linking? =

Redirect management, 404 monitoring, the broken link checker, and AI internal linking are available in ThinkRank Pro. The free plugin shows these sections with an option to upgrade.

= Does ThinkRank help with WooCommerce and Local SEO? =

Yes. The free plugin supports product metadata, an E-commerce sitemap mode, Local Business schema, business information, and a local sitemap. Advanced WooCommerce product SEO and multi-location Local SEO are available in ThinkRank Pro.

= Will ThinkRank conflict with my current SEO plugin? =

For best results, use only one primary SEO plugin at a time — running two can create duplicate meta tags, duplicate schema, conflicting robots meta, and sitemap confusion. ThinkRank's migration workflow imports supported data and can deactivate the previous SEO plugin when migration is complete.

= Is ThinkRank free? =

Yes, ThinkRank is a free WordPress SEO plugin with bring-your-own-key AI features — your AI provider usage is billed by the provider you choose, giving you direct control over model, cost, and privacy. There are no ThinkRank AI credits to buy and no monthly meter, in free or in Pro. ThinkRank Pro adds advanced automation such as the redirect manager, 404 monitor, internal linking, and rank tracker.

== Screenshots ==

1. Agentic AI SEO — connect Claude, ChatGPT, or Cursor to your WordPress SEO with the built-in MCP server.
2. Your AI assistant generating and updating WordPress SEO metadata in plain language via ThinkRank's MCP tools.
3. AI Metadata Generator — SEO title and meta description with a live SERP preview.
4. SEO content analysis dashboard — 13-factor score, focus keyword, and one-click apply suggestions.
5. Schema Manager — choose Article, FAQ, Local Business, or Review schema with validation.
6. XML Sitemap generator — Basic, Complete, E-commerce, and Segmented modes with post-type controls.
7. llms.txt generator — a maintained Markdown index of your content for AI agents and tools.
8. Google Search Console & GA4 insights with Core Web Vitals inside WordPress.
9. AI Content Brief Generator with competitor analysis and content gaps.

== Changelog ==

= 2.0.2 =
Release Date: 2026-08-25

- New: A master Enable Schema Markup switch in Schema Manager. While it is off, the Organization, Website and Person forms stay read-only with a notice explaining why, and no structured data is published
- Changed: Picking an image is now the same compact control everywhere — the post Social tab, Hero & Branding, and the setup wizard
- Fixed: Saving Schema Manager settings left a single schema type live even when four were enabled and configured
- Fixed: Turning Knowledge Graph or Auto-Generate Schema off had no effect — both stayed on
- Fixed: Opening a post's schema screen rewrote what you had deployed and published types you never chose
- Fixed: Switching a post's schema type left the old type published beside the new one
- Fixed: Structured data carried dates in a format Google reports as invalid, which drops the Article rich result
- Fixed: The site language was published as en_US where search engines expect en-US
- Fixed: Empty titles, descriptions and image dimensions were published as blank values instead of being left out, which fails validation harder than their absence
- Fixed: Your business and your personal profile were given a new identity on every address, so one business looked like many to a crawler
- Fixed: A page could carry two breadcrumb trails
- Fixed: FAQ questions and HowTo steps typed in the editor were emptied when saved, so FAQ schema could never be deployed
- Fixed: Schema deployed while a post was still a draft advertised the draft's temporary address forever
- Fixed: Importing schema from a URL missed the layout Yoast and Rank Math publish, and an entry with more than one type was rejected
- Fixed: Review and Video schema were offered in the editor and then rejected on save
- Fixed: The Person "Profile URLs" box would not take a second URL — the new line was erased as it was typed
- Fixed: One schema type failing validation took every valid type down with it on each save
- Fixed: A role granted schema access was locked out of deploying and of every site-wide schema screen
- Fixed: Schema Cache Duration was saved and then never applied
- Fixed: WooCommerce shop archives got no schema at all
- Fixed: Repeated settings saves made each save slower than the last, up to hundreds of extra database queries
- Fixed: The editor's schema preview showed raw stored data after a reload, and deleting one saved schema could overwrite another
- Fixed: An AI provider declining a request was retried, doubling the cost of a request that was never going to succeed
- Fixed: An AI assistant sending an unexpected field to ThinkRank got a server error instead of a clear message
- Fixed: The WordPress admin menu's fly-out panels were cut off at the bottom of the screen when opened from a low menu item such as Settings or Tools

= 2.0.1 =
Release Date: 2026-08-23

- New: A Delivery Method setting for your llms.txt file — automatic, a static file in the site root, or served by WordPress. On Nginx the web server sends the file without naming its character set, which turns accented letters and curly quotes into mojibake; served by WordPress it always arrives as UTF-8. Automatic picks the right one for your server
- New: The keyword breakdown — title, meta description, content, image alt, address — now shows on posts with a single focus keyword. It was being calculated and then hidden unless you had two or more keywords, which is the least common setup. Each row now states plainly whether it Matched or not
- New: Page 2 and beyond of an archive or a multi-page post now describes itself: its own title, its own address, and previous/next links, instead of repeating page 1
- Fixed: Settings that saved and then did nothing. The breadcrumb "Show current page" switch, the %site_description% and %tagline% variables, the sitemap include toggles for anything other than the admin screen, and the Open Graph type and Twitter card type choices all persisted without changing what your site published
- Fixed: Saving settings for a category could answer with an error after the save had already succeeded, so you re-entered settings that were never lost
- Fixed: If a settings screen failed to load it silently filled the form with defaults. Pressing Save then wrote those defaults over everything you had stored — on the Robots.txt screen that emptied a saved robots.txt. The screen now shows an error with a Retry button and refuses to save until a load succeeds
- Fixed: Flipping two switches quickly made the first one silently revert and stay reverted after a reload
- Fixed: The Local SEO switch could not be turned on — the save was rejected for a business name whose field only appears after the switch is on
- Fixed: Saving Site Identity stored a copy of the whole response back into your settings, growing the payload on every save
- Fixed: A settings screen would store any stray field a client sent it, and once stored it came back in every later response and was written again on every save, so the settings table and every settings request grew and never shrank. Only settings ThinkRank actually defines are stored now, and strays already saved are cleared on upgrade
- Fixed: An SEO title or meta description saved on a category, tag or custom taxonomy term was ignored on the archive page — the theme's own title was used and no description was published, while the admin screen showed the value as saved. Open Graph and Twitter values stored on a term now render too
- Fixed: Category, tag, author, date and search archives published no address or description to social networks, and an author archive's title lost its separator
- Fixed: A Twitter-specific description saved on a post was never used; the card showed the Open Graph description instead
- Fixed: Pages built with shortcodes or page builders published their own shortcode source as the meta description
- Fixed: WooCommerce product pages carried two aggregate ratings, which Search Console reports as a critical error on every reviewed product. ThinkRank now stands aside from WooCommerce's own product markup when it publishes its own
- Fixed: The sitemap listed the cart, checkout and account pages that ThinkRank's own robots.txt blocks, so Search Console reported "Submitted URL blocked by robots.txt" on every store
- Fixed: robots.txt put a blank line between each Sitemap line, which ends the record for a crawler, and listed child sitemaps the index already covers. The screen also reported the served file as in sync when it was not
- Fixed: On WPML sites every translation in the sitemap pointed at the default language's address
- Fixed: The readability score could report "Very Difficult (0)" on ordinary writing, because silent letters were counted as extra syllables
- Fixed: SEO suggestions are now ordered by how many points they can actually recover, and advice for a factor already scoring full marks is no longer listed
- Fixed: Core Web Vitals your site has no field data for were counted as failures, pushing the performance score down by 15–30 points for data you do not control. Unmeasured metrics are now reported as unmeasured
- Fixed: AI-drafted articles could carry heading labels ("H2: ") into the published heading text
- Fixed: Uninstalling the free plugin deleted an active ThinkRank Pro installation's license and settings. Uninstall also left scheduled tasks and term data behind, and on a network install only cleaned the current site
- Fixed: Bulk SEO Optimization could show one post type's settings under another and save them there if you switched post types quickly
- Fixed: An imported schema field could not be edited — the text snapped back as soon as it stopped being valid JSON — Print as PDF did nothing under a pop-up blocker, and the social preview could repaint with an out-of-date result
- Fixed: A failed save on Site Identity reported the literal word "undefined" instead of the reason
- Fixed: On sites whose database is not utf8mb4, none of ThinkRank's tables could be created: every settings save failed with a generic error and Quick Setup could not be completed. Affected sites heal on upgrade, and the real database error is now reported instead of a generic message
- Fixed: A page that does not exist advertised your homepage as its address and carried social tags
- Fixed: Every page carried a second viewport tag beside the theme's
- Fixed: FAQ blocks on a blog or archive listing published their own FAQ structured data beside the page's, and a block theme could publish the same questions twice
- Fixed: The MCP connection token was stored in the database in plain text. It is admin-equivalent, so it is now stored hashed and encrypted; existing connections keep working
- Fixed: Several REST routes accepted any post or page id from a user with only a section permission, exposing titles and descriptions from other authors' drafts, and four of them wrote settings onto content the caller could not edit
- Fixed: The protection against fetching internal addresses checked one address and then connected to whatever the hostname resolved to a moment later
- Performance: Anonymous page views make fewer database queries — a Google token refresh no longer runs on the front end, AI traffic counting is batched instead of locking a row on every visitor, and schema and settings lookups are cached properly

= 2.0.0 =
Release Date: 2026-08-20

- New: The FAQ block has a new accordion design and each answer can now carry its own image. The question sits on its own row with a plus that becomes a minus when the answer opens, a line separates the open question from its answer, and the image you add shows with the answer — and travels into the FAQ structured data, so search engines can use it
- Fixed: While editing an FAQ block the question and answer fields did not line up — the question was pushed to the right edge while the answer stayed on the left, which made an empty item look broken
- Fixed: Changing a page's address from ThinkRank's Permalink field looked like it did nothing. The new address was saved, but the editor went on showing the old one until the page was reloaded, so it read as though nothing had happened. In Elementor, Divi and Oxygen it genuinely did nothing: the address was never saved at all
- Fixed: The check for whether your keyword appears in the page address was wrong in both directions. On a draft it always reported the keyword as missing, whatever the address said, and only began passing once the post was published. On a published page it could report a match that actually came from a parent page, a category or a date in the address rather than from the page's own address. Each line of the keyword breakdown now states plainly whether it matched or not, instead of leaving you to read it out of a list of words

= 1.32.0 =
Release Date: 2026-08-18

- New: All of ThinkRank's structured data is now published as one linked graph instead of several separate scripts. A page that had, say, an Article, a breadcrumb trail and an FAQ used to emit three unconnected blocks — search engines now receive one graph in which those entities reference each other, and duplicate or competing entries are merged away
- New: FAQ content is collected from anywhere on the page — the FAQ block, the Elementor FAQ widget and any FAQ you deployed for the post — and published as a single FAQPage instead of several competing ones with different questions
- New: The Instant Indexing Submit URLs screen tells you what will actually be sent before you send it. Line numbers down the side, and each URL checked as you type: URLs pointing at another site, lines that are not URLs and duplicates are all called out, with a counter against the 100-URL limit. Clean up tidies the list, Clear empties it, Cmd/Ctrl+Enter submits, and the success message says how many URLs went
- New: The submission history screen was rebuilt — an empty state instead of a blank table, readable response codes with an explanation of what each one means, and Refresh now really re-reads the log, so URLs you just submitted show up immediately
- New: Every feature on/off switch now saves itself. Flipping a switch is the save — there is no separate Save button to remember, the switch rolls back and tells you if the save fails, and a short message confirms the new state. The Save button stays where you are filling in a form
- New: Screens show a placeholder shaped like the content while their data loads, instead of the word "Loading" or a blank panel
- New: Role Manager can grant or withhold AI Insights and Manage Roles separately, like every other ThinkRank area
- Changed: Connecting ChatGPT and other AI assistants now works on hosts that answer /.well-known/ addresses themselves before WordPress ever sees the request — reported on SiteGround, where the connection failed with "does not implement OAuth". ThinkRank now advertises an address that reaches WordPress on every host, and publishes the discovery files directly where a host insists on serving them itself
- Fixed: The first few sentences of a password-protected post were published as its meta description, and in its Facebook and X preview text — visible to anyone requesting the page and to every crawler and link preview, while the page itself still showed the password form. Questions from an FAQ block on a protected post could be published the same way
- Fixed: AI content briefs were displayed without sanitizing them. Brief text comes back from an AI provider and can include content pulled from competitor pages you supplied, so it is not trusted input; it is now cleaned before it is stored and before it is shown
- Fixed: A user given access to only the Social Media or Schema section could read the SEO details of posts they cannot edit — including other authors' drafts and pending posts — by changing the post number in the request
- Fixed: A user given access to only the Settings section could write post-specific social and SEO settings onto another author's post, changing what its public page shares. The same request also overwrote your site-wide defaults
- Fixed: Saving settings reported a server error even though the settings had been saved, on 8 of the 11 settings groups. People re-entered settings that were never lost, and a genuine failure looked identical to the permanent one. A successful save also came back empty, leaving the form blank
- Fixed: A published llms.txt file could display accented and non-Latin characters as mojibake ("Aktivitäten" as "AktivitÃ¤ten") because the file was served without saying which character set it used. The bytes were always correct — only the declaration was missing
- Fixed: On block themes, a page with an FAQ block published its questions twice — once inside ThinkRank's graph and once in a second block beside it
- Fixed: A Codex connection on the MCP screen showed a plain letter instead of the OpenAI mark
- Fixed: The two Schema screens showed their title three times over before any content, and the Global SEO, Crawling and Author Archives screens had the wrong icon or a missing divider

= 1.31.0 =
Release Date: 2026-08-16

- New: Submission Coverage for Instant Indexing — compares every published URL against the last successful IndexNow submission and tells you what is stale, what failed and what was never announced, then closes the gaps on its own once a day. There is a Reconcile Now button for when you do not want to wait
- New: A noindex you set on a category, tag or custom taxonomy archive is finally applied. The setting saved and read back correctly before, but the archive still carried your site-wide default — and the archive is now dropped from the XML sitemap too, so the sitemap cannot advertise a page that asks not to be indexed
- New: The Robots.txt screen shows what crawlers actually receive right now, and warns you when a robots.txt file sitting in your site's folder is being served by the web server instead of the content you saved
- Changed: ThinkRank now runs on PHP 7.4 and higher, instead of requiring PHP 8.0
- Changed: WordPress's own sitemap is switched off while ThinkRank's is enabled, so your site stops publishing two competing sitemaps and pointing search engines at both
- Changed: Core Web Vitals now reports Interaction to Next Paint, the metric Google replaced First Input Delay with
- Fixed: /sitemap.xml led search engines to a dead page on sites using a sitemap index — the one sitemap URL crawlers guess. It now goes to the sitemap you actually publish, and while your sitemap has not been generated yet WordPress's own sitemap is left in place rather than leaving that URL answering nothing
- Fixed: Cleaning up sitemaps deleted any file in your site's folder with "sitemap" in its name — including WordPress's own and other plugins' — and then left your site with no sitemap and nothing scheduled to rebuild it. It now removes only ThinkRank's files and queues the rebuild
- Fixed: Turning off a content type stopped its sitemap from being updated but left the old file serving and listed in the index. Sitemaps you no longer publish are now removed
- Fixed: Regenerating the sitemap from anywhere other than the settings screen could republish one flat sitemap over your sitemap index, or republish files that had lost their styling and their image entries
- Fixed: When a Core Web Vitals check fails, ThinkRank now tells you why — your site could not be reached, no Google connection, or the daily quota is used up — instead of reporting a server error for all three. Pressing refresh after a failure also really re-measures now, and a site set up with only a PageSpeed API key works without a Google account
- Fixed: The historical performance charts drew an empty card for a metric with no measurements, most visibly for the new INP metric on sites with older data
- Fixed: Open Graph told Facebook and LinkedIn your site was in US English no matter what language it was actually in
- Fixed: Pages built with Elementor and similar builders contributed no links, images or headings to SEO analysis, so scores and recommendations were based on text alone
- Fixed: SEO Insights failed with a server error on sites that had connected PageSpeed
- Fixed: With some caching or optimization plugins, ThinkRank's screens and the Dashboard widget could stay stuck on "Loading" forever
- Fixed: The AI usage overview reported empty fields on a site that had not used any AI features yet

[See changelog for all versions](https://thinkrank.ai/changelog/).

== Upgrade Notice ==

= 2.0.2 =
Adds a master Enable Schema Markup switch and fixes a large batch of Schema Manager defects: settings saves collapsing four schema types to one, invalid dates dropping Article rich results, FAQ questions emptied on save, and duplicate business identities. Recommended for all sites.

= 2.0.1 =
A large correctness release. Settings that saved and then did nothing now take effect, a failed settings load can no longer overwrite what you had stored, and SEO titles and descriptions saved on categories and tags finally render. Fixes double aggregate ratings on WooCommerce products, cart and checkout pages in the sitemap, garbled accented characters in llms.txt on Nginx, paginated archives describing themselves as page 1, and an uninstall that damaged an active Pro install. Also hardens the AI-assistant connection token and object permissions on several REST routes. Recommended for all sites.
