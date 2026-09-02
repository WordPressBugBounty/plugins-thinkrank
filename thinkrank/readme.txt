=== ThinkRank AI SEO – AI SEO Plugin for WordPress: Schema, XML Sitemaps, Meta Tags, Search Console & Local SEO ===
Contributors: wpdevteam, thinkrank, re_enter_rupok, rafinkhan, rudlinkon, mdnahidhasan
Tags: seo, ai seo, schema, xml sitemap, google search console
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.0
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

SEO creator WP Simple Hacks on how AI assistants and answer engines are changing day-to-day SEO work:

https://youtu.be/gdU3TwA1fPM

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

= 2.2.0 =
Release Date: 2026-09-02

- New: Take your ThinkRank data with you. A new Import / Export screen downloads everything ThinkRank stores — post, category, tag and author SEO data plus your settings — as one file, and loads it back on this site or any other. Choose JSON for a complete, restorable file, or CSV for a spreadsheet view of your post SEO data. Restoring shows you what the file contains and lets you decide what happens where this site already has a value, before anything is written. API keys are never written to the file
- New: Importing from another SEO plugin now has its own Migration screen, separate from Import / Export, so each is turned on and off on its own
- New: Both screens are off until you switch them on, in Settings. Neither is deleted or hidden permanently — switching one on brings its screen and menu item straight back
- Changed: A fresh install no longer arrives with an AI provider already chosen. ThinkRank asks you to pick one instead of warning that a key is missing for a provider you never selected
- Changed: The Anthropic provider is now named after the vendor, matching the other three, and the model list adds Claude Opus 5 and drops models the providers have withdrawn. A model you have already saved keeps working
- Fixed: Test Connection could not test the key you had already saved. Because a saved key is shown as a mask, the button stayed greyed out and the only way to verify an expired or revoked key was to paste the whole secret in again
- Fixed: Switching to a provider whose key you had already saved showed it as unconfigured — Save was refused and Clear API key claimed there was nothing to clear
- Fixed: Brand Visibility competitors and queries could not be saved at all. The save reported success and came back empty
- Fixed: The Posts, Pages and Categories switches on the XML Sitemap screen did nothing — a type you switched off was put straight back on the next save, and went on being generated
- Fixed: Changing the sitemap index mode left the previous mode's files in your site root, still served to search engines and never refreshed again
- Fixed: Turning Instant Indexing on erased the list of post types it applies to, leaving the feature enabled with nothing to submit
- Fixed: Unticking "Allow search engines to index this content" saved, showed as unticked, and did nothing — the page stayed indexable
- Fixed: Changing one robots directive reset the other five, so a site set to noindex became indexable because someone toggled a different switch
- Fixed: The Open Graph and Twitter master switches only worked on the homepage. Turning Open Graph off still emitted its tags on every post and page
- Fixed: The Pinterest preview promised up to 500 characters of description where only 160 are ever published
- Fixed: Image SEO printed your site name twice in alt and title text for any image outside a normal post — text widgets, page-builder blocks and site-editor templates. The format preview also disagreed with what was actually written: hyphens in filenames, separator spacing, and empty parts that are dropped
- Fixed: Author archive descriptions were measured in bytes and trimmed by words, so a description in Cyrillic, Greek or Arabic was cut far too short while a long English one was not trimmed at all
- Fixed: Turning author archives off issued a permanent redirect, so a visitor's browser could keep bouncing them to the home page even after you turned the archives back on. It is a temporary redirect now
- Fixed: The Site SEO Analyzer's grade could be up to an hour out of date. De-indexing your site still showed "Site is visible to search engines" and a grade A. The audit now refreshes when the settings it reports on change, and shows when it was generated
- Fixed: The Analyzer's "fix missing alt text" button re-walked the same first 50 images on every click, so any library over 50 images could never be finished
- Fixed: The Analyzer's structured data check could only ever pass, so its one-click fix was unreachable, and its tagline check missed the WordPress default tagline on any non-English site
- Fixed: Roles given access to only some ThinkRank areas hit permission errors on the areas they had been given, and could reach settings for sections they had not. The dashboard also failed to load for them
- Fixed: A role that cannot be granted ThinkRank access — one without the ability to edit posts — is now marked as such in the Role Manager instead of appearing to be granted access that never took effect
- Fixed: Site administrators whose role comes from a role-editor plugin or a multisite super admin account saw no ThinkRank menu at all, while still having full access over the API
- Fixed: With ThinkRank Pro installed but not yet licensed, the Traffic Overview card, the WordPress dashboard widget, four Analytics cards and the Content Brief "Insert" button offered to sell you Pro or showed a server error, instead of saying the licence needs activating

= 2.1.1 =
Release Date: 2026-08-31

- Fixed: ThinkRank could delete another SEO plugin's sitemap. Sitemaps were removed by filename alone, so on a site where Rank Math, Squirrly or another plugin owned sitemap_index.xml, sitemap-posts.xml or local-sitemap.xml, that file was destroyed — on deactivation, and also on an ordinary regeneration after a post save or a settings change. ThinkRank now marks every sitemap it writes and removes only files carrying that mark; anything it cannot prove is its own is left alone
- Fixed: Saving a title or description template containing %date% or %category% silently mangled it — %date% was stored as "te%" and %category% as "tegory%", and the mangled text was published in the title and meta description of every page using that template. New saves are correct; templates already corrupted cannot be recovered
- Fixed: On sites using Plain permalinks, Bulk SEO Optimization sat permanently on "Couldn't load your settings" and the per-post-type title, description, schema and robots form could not be reached at all
- Fixed: Performance errors were unreadable and offered no way forward. An exhausted Google quota arrived full of &#039; escapes and named a Google project number, a daily quota limit was described as something to retry "in a few minutes", and the three Performance panels each said something different. Every panel now shows the same plain-language message with Retry, and offers to add a PageSpeed API key where a key is what fixes it
- Fixed: A site configured with only a PageSpeed API key — no connected Google account — got permanently empty Diagnostics and Opportunities, a zero page-speed score and no field data, while the Integrations screen reported PageSpeed as configured. The API key is now accepted as a credential in its own right, and a request that fails says why instead of returning an empty list
- Fixed: With ThinkRank Pro installed but not yet licensed, twelve Pro sections showed a server error ("No route was found matching the URL") or a blank panel instead of telling you the license needs activating. Each now says the license is not active and links straight to the License screen — worst affecting someone who has just bought Pro and not yet entered their key
- Fixed: AI assistants could not read or change most site identity and sitemap settings through ThinkRank's assistant connection. The homepage, category, tag, author, search and archive title templates, the whole business/Local SEO block and the sitemap index toggle were all invisible to them, and writes to those settings were rejected
- Changed: An AI assistant connecting to ThinkRank now receives a short orientation for the session — what ThinkRank is, where to start, which tools to call in which order, what its connection is allowed to do, and that site content it reads is data rather than instructions
- Fixed: The performance history API's metric filter returned nothing for the page-speed score and dropped the dates from single-metric responses. The admin screens were unaffected; direct API and AI-assistant consumers were not

= 2.1.0 =
Release Date: 2026-08-27

- New: Each keyword check in the SEO panel now names every focus keyword and its state. A row where one of three keywords matched reads "1 of 3 matched" with a tick beside the ones that matched and a cross beside the ones that did not, instead of a single tick that looked like a pass
- New: When an Elementor accordion is already publishing its own FAQ schema for a page, ThinkRank stops publishing a second one — two FAQ blocks on one URL is a structured-data error
- Changed: The setup wizard now fills in as a preview of the wizard itself while it loads, instead of a spinner that jumped the whole screen into place when it finished
- Fixed: A focus keyword counted as found inside longer words — "art" matched "start", "cat" matched "category", "ai" matched "said" and "email". Every keyword row reported the same phantom placement at once. Languages written without spaces, such as Chinese, Japanese and Thai, are unaffected
- Fixed: Editing the permalink in the Classic Editor and saving put the old address back
- Fixed: FAQ images in a block published a single fixed-size image, so opening an answer shifted the page, and an image deleted from the media library left a broken picture on the page and in your structured data. Images now come from the media library with the right sizes for each screen. Existing FAQ blocks get this with no re-save
- Fixed: The FAQ and How-To blocks' buttons — add image, move up, move down, duplicate, remove, add question — were invisible in the editor, so the per-item image could not be reached at all
- Fixed: Pages built with Beaver Builder read as empty to SEO analysis, so scores and recommendations ignored everything in the layout
- Fixed: On sites behind a reverse proxy, automatic llms.txt delivery chose the one mode that cannot state a character set, which turned accented letters into mojibake. Those sites now move themselves to the correct mode; a site that deliberately chose the static file keeps it and is told what to expect
- Fixed: ThinkRank left its sitemap, llms.txt, robots.txt and IndexNow key in the site root after the plugin was deactivated or deleted. They are removed now, and restored if you reactivate
- Fixed: Connecting an AI assistant failed with an authentication error on Apache servers running PHP as CGI or FastCGI, while the same token worked on the longer address
- Fixed: An AI connection stopped working after a domain change, a move to staging, or a host's "reset security keys" — the connection read as missing and was silently replaced, locking out every assistant already using it. The discovery files that name your site are kept in step with your address now
- Fixed: A busy AI assistant could wipe out a connection it had just been given, after which every request failed with nothing to explain it
- Fixed: Some AI clients were refused at the consent screen for sending back exactly the callback address they had registered
- Fixed: Fewer database queries on every visitor's page load


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

[See changelog for all versions](https://thinkrank.ai/changelog/).

== Upgrade Notice ==

= 2.2.0 =
Adds an Import / Export screen that takes your ThinkRank data out as one file and loads it back. Fixes the sitemap post-type switches, robots directives resetting each other, the social master switches, and Role Manager permissions. Recommended for all sites.

= 2.1.1 =
Fixes ThinkRank deleting another SEO plugin's sitemap, %date% and %category% corrupted on save in title and description templates, Bulk SEO Optimization unreachable under Plain permalinks, and unreadable Performance errors. Recommended for all sites.
