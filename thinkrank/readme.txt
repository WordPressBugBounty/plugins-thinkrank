=== ThinkRank – AI SEO Plugin: Keywords, Metadata, Schema, llms.txt, MCP & Search Console ===
Contributors: wpdevteam, thinkrank, re_enter_rupok, rafinkhan, rudlinkon, mdnahidhasan
Tags: seo, ai seo, focus keyword, schema, llms.txt
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.27.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI SEO plugin with metadata, focus keywords, schema, XML sitemaps, llms.txt, MCP and Search Console insights.

== Description ==

**[ThinkRank](https://thinkrank.ai/) is an AI SEO plugin for WordPress built for the new agentic SEO workflow.** Optimize WordPress SEO with AI-generated meta titles and meta descriptions, real-time content analysis, keyword optimization, schema markup, XML sitemaps, robots meta, canonical URLs, Open Graph social previews, Google Search Console insights, GA4 analytics, llms.txt — and a built-in **MCP server** that lets Claude, ChatGPT, Cursor, and other compatible AI assistants help manage SEO directly from your site.

ThinkRank is not just another SEO plugin. It is an **agentic AI SEO platform for WordPress** — built for the way SEO work is moving: from manual checklists to AI-assisted action. Instead of switching between dashboards, browser tabs, SEO checklists, and AI chat windows, you can ask your AI assistant to help configure and improve your SEO in plain language.

It works where you already build: **Gutenberg, Elementor, Divi, Oxygen/Breakdance, and the Classic Editor** — and on multilingual sites running **WPML, Polylang or TranslatePress**.

= Watch: Ranking in AI Search =

SEO creator Tin Rovic breaks down how to get your brand surfaced by AI search engines and LLMs:

https://youtu.be/TEzfS2dAMC8

= Agentic AI SEO with MCP for Claude, ChatGPT and Cursor =

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
* **Connection health check** — a "Test connection" button makes a real call and tells you exactly which step failed: HTTPS, authentication, permissions, or ability discovery.
* **Safe imports** — connected assistants can preview an SEO import as a dry run before anything is written.

= Easy Claude, ChatGPT, Cursor and MCP Client Setup =

* **One-click Claude connection** via a guided OAuth 2.1 flow with PKCE — no API key to copy, no config file.
* **Application Password fallback** for ChatGPT, Cursor, and other MCP-compatible clients, with ready-made configuration details.
* **No companion plugin required** and **no terminal setup**.
* **Admin-controlled and off by default** — enable it under ThinkRank → MCP whenever you're ready.
* **Revocable in one click** — delete the connected Application Password and access is removed immediately.

= AI SEO Metadata Generator for Titles and Meta Descriptions =

* Generate SEO title suggestions for posts, pages, products, and custom post types.
* AI meta descriptions written for search snippets and click-through rate.
* Live SERP preview before you publish.
* Apply suggestions with one click — no copy-pasting.
* Fully editable fields with manual override.

= SEO Content Analysis, Focus Keywords and Keyword Optimization =

* Real-time content analysis with a 13-factor SEO score.
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
* **Sensible per-field behaviour** — copy for indexing intent and imagery, translate for copy, and never copy an explicit canonical onto a translation (which would point it back at the source language).
* Detected automatically, and completely inactive on monolingual sites.

= XML Sitemap Generator and Indexing Tools =

* Multiple sitemap modes: Basic, Complete, E-commerce, and Segmented.
* Real sitemap index with paginated child sitemaps, automatic splitting of oversized sitemaps, and per-post-type controls.
* Memory-efficient generation that stays fast on large sites.
* AI-optimized robots.txt with automatic sitemap discovery.
* **Instant Indexing** — submit new and updated URLs straight to search engines (IndexNow) for faster indexing, in the background via WP-Cron.

= llms.txt Generator for LLM SEO, GEO and AI Search Visibility =

* Generate and maintain an llms.txt file for your WordPress site.
* Built for **LLM SEO**, **generative engine optimization (GEO)**, and AI search readiness.
* Machine-readable summaries so AI assistants and AI search engines can discover and cite your content.

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

= Open Graph and Social Meta =

* Open Graph metadata, social titles, and descriptions.
* Twitter/X card support.
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

= Migrate from Rank Math, Yoast SEO, AIOSEO or SEOPress =

A guided Setup Wizard detects your current SEO plugin and imports your metadata, schema settings, sitemap settings, image SEO data, templates, and related SEO settings from **Rank Math SEO, Yoast SEO, All in One SEO (AIOSEO), and SEOPress** — then deactivates the old plugin once its data is safely migrated.

The importers go well past basic post meta: title formats per page type, Knowledge Graph and organization identity, author archive settings, role permissions, IndexNow keys and submission history, per-post sitemap exclusions, breadcrumb settings, social defaults, News/Video sitemap post types, and local business details all carry over where the source plugin has them.

If you're looking for a **Rank Math alternative, Yoast SEO alternative, AIOSEO alternative, or SEOPress alternative** with stronger AI SEO and MCP-based agentic workflows, ThinkRank is built for that transition.

= Bring Your Own AI Provider Key =

ThinkRank works with **OpenAI, Anthropic Claude, Google Gemini, OpenRouter, and compatible custom endpoints** — you bring your own key, so you keep direct control over model selection, cost, and privacy.

* **No AI key needed for:** metadata fields, schema controls, XML sitemaps, robots meta, canonical URLs, breadcrumbs, Open Graph, Search Console/GA4 connections, the Site SEO Analyzer, multilingual output, page-builder integrations, and most dashboard controls.
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
* Content teams, developers, and AI power users
* Any site preparing for AI search, LLM SEO, and GEO

= Why Choose ThinkRank? =

* An AI SEO plugin with real on-page and technical SEO — not just an AI writer
* A built-in MCP server for Claude, ChatGPT, Cursor, and compatible clients
* AI-generated meta titles and descriptions with SERP preview
* SEO content analysis and keyword optimization with one-click fixes
* A whole-site SEO Analyzer that needs no Google connection
* Accurate scoring on Elementor, Divi, and Oxygen/Breakdance pages
* WPML and Polylang support with correct hreflang and per-language sitemaps
* Schema markup and structured data for rich snippets, plus FAQ/HowTo/TOC blocks
* XML sitemap generator, robots.txt, instant indexing, and llms.txt
* Search Console and GA4 insights inside WordPress
* Role Manager for team and client access control
* One-click migration from Rank Math, Yoast, AIOSEO, and SEOPress

== Installation ==

**Minimum Requirements**
* WordPress 6.0 or higher
* PHP 8.0 or higher
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

llms.txt is an emerging file format that helps AI systems, AI search engines, and large language models discover and understand important website content. ThinkRank can generate and maintain llms.txt for WordPress, helping prepare your content for LLM SEO, generative engine optimization (GEO), and AI search visibility.

= Does ThinkRank work with Google Search Console and GA4? =

Yes. ThinkRank connects with Google Search Console to show clicks, impressions, queries, and keyword opportunities inside WordPress — including a dashboard widget with your last 30 days of traffic — and supports GA4 for traffic and organic-performance insights.

= Does ThinkRank support Open Graph, canonical URLs and noindex? =

Yes. ThinkRank includes Open Graph and social meta controls for Facebook, LinkedIn, Pinterest, and X/Twitter, plus canonical URL controls and robots meta settings such as noindex and nofollow.

= Is ThinkRank a Yoast, Rank Math, AIOSEO or SEOPress alternative? =

Yes. ThinkRank can be used as a Rank Math or Yoast SEO alternative for sites that want core SEO — metadata, schema, XML sitemaps, robots meta, canonical URLs, Search Console and GA4 insights, and llms.txt — plus AI-powered generation and an MCP server for Claude, ChatGPT, and Cursor. The Setup Wizard imports your existing Rank Math, Yoast, AIOSEO, or SEOPress data.

= Can I migrate from AIOSEO or SEOPress? =

Yes. ThinkRank's Setup Wizard imports supported SEO data from All in One SEO (AIOSEO) and SEOPress, along with Rank Math and Yoast SEO — including title formats, Knowledge Graph details, role permissions, author archive settings, IndexNow keys, breadcrumb settings, and social defaults.

= Does ThinkRank include a redirect manager, 404 monitor, or internal linking? =

Redirect management, 404 monitoring, the broken link checker, and AI internal linking are available in ThinkRank Pro. The free plugin shows these sections with an option to upgrade.

= Does ThinkRank help with WooCommerce and Local SEO? =

Yes. The free plugin supports product metadata, an E-commerce sitemap mode, Local Business schema, business information, and a local sitemap. Advanced WooCommerce product SEO and multi-location Local SEO are available in ThinkRank Pro.

= Will ThinkRank conflict with my current SEO plugin? =

For best results, use only one primary SEO plugin at a time — running two can create duplicate meta tags, duplicate schema, conflicting robots meta, and sitemap confusion. ThinkRank's migration workflow imports supported data and can deactivate the previous SEO plugin when migration is complete.

= Is ThinkRank free? =

Yes, ThinkRank is a free WordPress SEO plugin with bring-your-own-key AI features — your AI provider usage is billed by the provider you choose, giving you direct control over model, cost, and privacy. ThinkRank Pro adds advanced automation such as the redirect manager, 404 monitor, internal linking, and rank tracker.

== Screenshots ==

1. Agentic AI SEO — connect Claude, ChatGPT, or Cursor to your WordPress SEO with the built-in MCP server.
2. Your AI assistant generating and updating WordPress SEO metadata in plain language via ThinkRank's MCP tools.
3. AI Metadata Generator — SEO title and meta description with a live SERP preview.
4. SEO content analysis dashboard — 13-factor score, focus keyword, and one-click apply suggestions.
5. Schema Manager — choose Article, FAQ, Local Business, or Review schema with validation.
6. XML Sitemap generator — Basic, Complete, E-commerce, and Segmented modes with post-type controls.
7. llms.txt generator for LLM SEO and AI search visibility.
8. Google Search Console & GA4 insights with Core Web Vitals inside WordPress.
9. AI Content Brief Generator with competitor analysis and content gaps.

== Changelog ==

= 1.27.0 =
Release Date: 2026-08-06

- New: Connected Apps page for MCP — see every AI app connected to your site, when it last used the connection, and disconnect any of them in one click
- New: AI now writes in your site's language. Titles, descriptions and content briefs came back in English on non-English sites; they now follow the post's language, or the site language, and can be overridden with a filter
- New: Instant Indexing checks that your IndexNow key file is actually reachable and tells you what is wrong before you submit a single URL
- New: The MCP connection test detects hosts that block AI clients by User-Agent — the "couldn't register with the sign-in service" failure that no other check could see
- Fixed: MCP could connect successfully and offer no tools at all, with nothing reporting a problem — the most common cause was another plugin's copy of the Abilities API loading first
- Fixed: A connector still using an old token could lock itself out of your site permanently by retrying. The lockout window no longer extends on every retry, and the plugin now shows when clients are locked out
- Fixed: ChatGPT rejected some sites with "MCP server does not implement OAuth" — the sign-in handshake itself was tripping the rate limiter
- Fixed: Every IndexNow submission returned 403 on hosts with a read-only web root, because the key file could never be written. The key is now served directly by WordPress, and the error message names the file and what to check
- Fixed: Importing from Rank Math (and Yoast, AIOSEO, SEOPress) could fail outright on a single unexpected setting value
- Fixed: The "ThinkRank SEO" metabox was hidden by a leftover style rule — Classic Editor users never saw it, and the block editor's panel toggle appeared to do nothing
- Fixed: Missing borders in the SEO drawer inside the Divi Visual Builder
- Improved: The plugin package now installs as an upgrade rather than a second copy, and ships the WPML config file that earlier packages left out

= 1.26.0 =
Release Date: 2026-08-04

- New: Search Console's Property field is now searchable — type to filter instead of scrolling a long site list
- New: TranslatePress support — translated URLs now advertise their own language to social networks instead of the site's default
- Improved: SEO scoring now uses the title and description your site actually outputs, so posts that inherit the Global or Bulk pattern are no longer scored as if they had no title or description
- Improved: Suggestions that only offer guidance now read "How to fix" instead of "Apply", so a button never promises an edit it will not make
- Fixed: Saving from the ThinkRank panel silently did nothing on posts with no other edits — SEO fields were never written and no error was shown
- Fixed: The SEO score kept showing the old value for several minutes after changing the title or description, then appeared to update on its own
- Fixed: The "ThinkRank SEO" metabox rendered empty — it now shows the current score with a way into the panel
- Fixed: Schema edits could be lost when navigating away right after editing
- Fixed: A focus keyword typed but not yet added was lost when saving, and the typed text lingered in the box after it was added
- Fixed: Sites in a formal locale (such as German formal) emitted an invalid hreflang language code that search engines discard


= 1.25.0 =
Release Date: 2026-08-03

- New: Redesigned AI Metadata Generator — generated title and description now come with a live Google preview, character-length indicators, one-click copy, and a result card that carries the metadata straight to the next step
- New: Press Enter to add a keyword in the Content Brief generator instead of reaching for the mouse
- Improved: Refreshed styling across the Content Brief generator, top navigation and form controls
- Fixed: Signing in with Google failed on sites using Plain permalinks — the callback now completes on any permalink setting
- Fixed: The dashboard could still show the old connection state right after finishing Google sign-in
- Fixed: WordPress hover fly-out submenus were mispositioned and lost their pointer arrow on ThinkRank's full-page screens
- Fixed: Posts and pages without their own social image now correctly inherit the site-wide default in every context
- Fixed: Social meta tags and image schema no longer output zero width and height for images WordPress has no dimensions for
- Fixed: Several crashes on unusual or malformed data — sitemap generation, custom schema validation, saving settings, Local SEO business hours, importing from another SEO plugin, and unexpected responses from AI or Google APIs


= 1.24.0 =
Release Date: 2026-08-02

- New: Website Insights dashboard widget — see your last 30 days of Google Search Console traffic, top queries and headline metrics right on the WordPress dashboard
- New: ThinkRank now works inside the Oxygen/Breakdance and Divi visual builders — a launcher button in the builder's top bar opens the full ThinkRank panel without leaving the canvas
- New: Content briefs can now generate a complete article draft, not just an outline, in a cleaner tabbed layout
- New: MCP Server screen has a connection health card and a "Test connection" button that makes a real call and tells you exactly which step failed — HTTPS, authentication, permissions or ability discovery
- New: Connected AI assistants can preview an SEO import as a dry run before anything is written, and score and save many posts in a single request
- Improved: ThinkRank's abilities are now always registered, so any AI connector can discover the plugin — the MCP switch now only controls the MCP server itself
- Improved: Redesigned Settings page with tabs and independent saving per tab, and a card-based AI provider picker
- Improved: Content briefs on OpenAI reasoning models no longer time out before finishing
- Fixed: Pages built with Oxygen, Breakdance, Divi or Elementor were scored as having no content at all — a client reported published pages with over 1,000 words scoring in the 40s. ThinkRank now reads the real page content everywhere: bulk optimization, the post list SEO column, cron reports, AI assistants and the live analysis panel in the editor
- Fixed: The SEO score shown by AI assistants is now saved, so the post list no longer keeps saying "Not Analyzed"
- Fixed: Social profile fields accepted invalid values (like a malformed YouTube channel ID) and published them as broken verification tags — invalid values are now rejected with a clear message
- Fixed: Homepage social sharing — the share title now matches your SEO title, your logo is used as the share image with a large card, and the share URL matches your canonical URL
- Fixed: Search Console errors on the dashboard widget now explain what to fix instead of showing raw Google error text
- Fixed: Divi's layout library and Theme Builder templates no longer appear in SEO screens
- Few minor bug fixes & improvements

= 1.23.0 =
Release Date: 2026-07-29

- New: WPML and Polylang support — your SEO fields now appear in the WPML Translation Editor, each translated page advertises its own language to search engines and links to its alternates, and the XML sitemap covers every language instead of only the one active when it was generated. hreflang tags are added only when WPML or Polylang isn't already printing them, so you never end up with two competing sets
- New: All in One SEO import now matches the coverage of Rank Math's own AIOSEO importer — sitemap exclusions, per-post-type robots settings, Twitter card defaults, Facebook App ID, default social share image, Pinterest verification, breadcrumb prefix, and AIOSEO Pro data such as term SEO, News/Video sitemap post types, image SEO formats and local business details
- New: Breadcrumbs can show a border, and breadcrumb titles now reflect the section you're in
- New: With ThinkRank Pro active, its features are available to connected AI assistants through the MCP server too
- Improved: "Content SEO" is now called Bulk SEO Optimization, and author archives moved to their own section
- Improved: The usage-tracking opt-in notice uses the same clean, branded card design as the welcome notice
- Fixed: SEOPress import never actually imported your settings — it read option names that don't exist. Settings, keyword lists (297 posts lost their extra keywords in our test migration), Knowledge Graph, IndexNow key, title formats, author archives, image alt settings and SEOPress Pro breadcrumbs now all carry over, and cleanup removes the right options
- Fixed: All in One SEO import — every post was given a robots override that switched off search snippets and previews, 226 primary categories were dropped, 295 posts lost their extra keyphrases, and site-wide settings such as title formats, Knowledge Graph, archive settings and breadcrumbs were never read at all
- Fixed: Import screen — redirects and 404 logs weren't counted, so Rank Math Pro redirects, the 404 Monitor, Yoast Premium redirects and SEOPress 404s were never offered for import; a SEOPress site with settings but no post data wasn't detected at all; and All in One SEO cleanup left most of its data behind
- Fixed: The Social Media Preview card rendered unstyled and looked broken
- Fixed: The welcome notice's Dismiss link did nothing outside ThinkRank's own pages, and the notice kept reappearing on sites using Gemini or OpenRouter as their AI provider
- Fixed: Content brief section icons showed as empty blue squares
- Few minor bug fixes & improvements

= 1.22.0 =
Release Date: 2026-07-28

- New: Yoast SEO import now matches the Rank Math importer — per-page-type title formats, alternate name, Knowledge Graph entity, author archive settings, IndexNow key, role permissions, News/Video sitemap post types (into Publisher Sitemaps with Pro), and link suggestion preferences all carry over
- New: Setup Wizard now includes an MCP Server step, so you can connect Claude, ChatGPT, or Cursor during onboarding, and the final step offers a free onboarding call booking link
- New: MCP abilities for Email Reporting and Social Platforms — connected AI assistants can now read and update your email report settings, send a test report email, and manage your social platform profiles
- Improved: The Setup Wizard has a refreshed design — new onboarding canvas, smoother step transitions, clearer error messages, and better small-screen layouts
- Improved: The welcome and SEO plugin conflict notices have a cleaner, branded card design
- Improved: The Setup Wizard is shorter — the social profiles step was removed, and the last step's button now reads "Continue" instead of "Finish"
- Fixed: Reinstalling ThinkRank could lock administrators out with "Sorry, you are not allowed to access this page" — access is now restored automatically, including on sites already affected
- Fixed: Yoast import — your organization name and logo were silently skipped on any Yoast version from the last several years; they now migrate correctly
- Fixed: Yoast import — leftover Rank Math variables in a site that had previously moved from Rank Math to Yoast are now translated to ThinkRank variables instead of being dropped
- Fixed: Long AI content briefs generated with Gemini failed with "Error: Unable to parse AI response" — the token limit is raised and a cut-off reply now reports the real reason

= 1.21.0 =
Release Date: 2026-07-26

- New: Rank Math import now covers everything ThinkRank has added since the importer was written — your title separator, per-page-type title formats (posts, pages, categories, tags, search, archives, authors), author archive settings, Instant Indexing post types, per-post sitemap exclusions, email report schedule, alternate name, and Knowledge Graph entity all carry over
- New: Rank Math role permissions are migrated — editors, authors, contributors and custom roles keep their SEO access instead of losing it the moment Rank Math is deactivated
- New: Rank Math's IndexNow submission history is migrated, so the Instant Indexing history screen isn't empty after switching
- New: With ThinkRank Pro active, Rank Math redirect rules and 404 logs are migrated too, keeping their match types, hit counts and dates; News/Video sitemap post types carry over to Publisher Sitemaps
- New: Toast notifications for site analysis, so errors and successes are actually visible
- Improved: Sitemap generation uses far less memory and is much faster on large sites — big sites no longer risk running out of memory while generating
- Improved: Bulk Instant Indexing submissions run in the background instead of blocking the admin screen
- Improved: The Image SEO alt text settings now appear above the "Fill missing alt text" button, since they change what the fill does
- Fixed: A fatal error (white screen) on 404 pages and on any page whose social share image had known pixel dimensions
- Fixed: Posts were scored as if no focus keyword was set, costing around 14 points on posts that did have one; the SEO score now reads the post's saved focus keyword
- Fixed: Saving a title format containing variables like %category_title% or %date% corrupted them — the variables are now preserved exactly as typed
- Fixed: XML sitemap overhaul — "Use Sitemap Index" now produces a real sitemap index with paginated child sitemaps, the homepage appears only once, oversized sitemaps split automatically, no more invented "last modified" dates, tag archives are included in index mode, and changing your inclusion rules rebuilds the sitemap instead of leaving a stale file
- Fixed: Scheduled sitemap regeneration silently never ran; it now works on schedule
- Fixed: Turning the sitemap off now deletes the generated sitemap files instead of leaving them on your site
- Fixed: The sitemap "Validate" button flickered and never updated the row status
- Fixed: Instant Indexing — network failures record the real error, history is paginated and cleaned up after 90 days, failed rows show in red, the submitted count reflects what was actually sent, and URLs from other domains are dropped before submission
- Fixed: llms.txt — the file is removed when you disable the feature or deactivate the plugin, a failed removal is reported instead of showing a false success, and each Key Feature line renders as its own bullet
- Fixed: Robots.txt — turning off "Allow Search Engines" (or WordPress's own "Discourage search engines" setting) now genuinely blocks crawlers even with custom robots.txt content saved, and changing that setting updates the live file
- Fixed: Social media — disabling Open Graph or Twitter Cards now removes those tags completely, saving social meta no longer errors, and the Twitter card type and language tag are detected correctly
- Fixed: Schema — importing schema from a URL can no longer be pointed at internal server addresses, bulk operations check per-post permissions, rate limiting actually works, and special characters in schema fields are safely escaped
- Fixed: Bulk SEO Optimization — page-builder helper post types no longer clutter the list, a failed save reports an error instead of a false success, and the title/description counters match the search preview
- Fixed: Role Manager — Pro features are now covered by role permissions, and saving one role no longer strips permissions from other roles
- Fixed: AI-generated titles and descriptions respect their length limits and show accurate character counts
- Fixed: The Setup Wizard's "Connect with Google" button now works and returns you to the wizard
- Fixed: Image SEO — the "Fill missing alt text" button no longer wraps, an empty Media Library shows a clear message, and the button is disabled when there's nothing to fill
- Fixed: Empty business or organization details no longer emit blank values into structured data, which invalidated Local Business rich results
- Few minor bug fixes & improvements

= 1.20.0 =
Release Date: 2026-07-22

- New: Media Library alt text — save generated alt text onto the image record itself so it works everywhere (not just in rendered content), bulk-fill every image missing alt text, auto-fill new uploads, and optionally overwrite existing alt text
- New: Hero section — render the Site Identity hero (title, subtitle, CTA button, background image) anywhere with the [thinkrank_hero] shortcode or the thinkrank_hero theme hook
- New: Title formats for Tag and Archive pages, with context-aware variable buttons under every title field so each format only suggests variables that work in that context
- New: 11 new MCP abilities for AI assistants — publish llms.txt, run and read the Site SEO Analyzer, fetch performance data and integration status, generate content briefs, read and update pillar content, and detect, run, and monitor SEO imports from other plugins
- Changed: Connecting Google now runs through a secure ThinkRank-hosted flow — Google app credentials no longer ship inside the plugin and access tokens never pass through the browser; previously connected sites will be asked to reconnect once
- Improved: AI title optimization analyzes each title context separately (posts, pages, categories, and more), and AI site-identity optimization only requires a site name — an empty description or tagline is generated instead of rejected
- Fixed: Robots.txt editing overhauled — saving keeps the physical robots.txt file in sync so the live file always matches the editor, the View button shows the content actually being served, line breaks are preserved, and the editor no longer shows the auto-generated header
- Fixed: Google connection reliability — the token refresh now runs on schedule in the background, a revoked Google account prompts a reconnect instead of retrying forever, and credentials that can no longer be read (for example after a site migration) show a clear reconnect prompt instead of failing silently
- Fixed: Copying MCP connection details now works on non-HTTPS sites and older browsers
- Fixed: A fatal error that could occur when calculating sitemap statistics on the Sitemap settings screen
- Fixed: The Search title format uses the correct %search_term% variable, so the searched term actually appears in search results page titles
- Fixed: The SEO metabox drawer keeps its left navigation fixed while only the content area scrolls
- Few minor bug fixes & improvements

= 1.19.0 =
Release Date: 2026-07-19

- New: Site SEO Analyzer — a whole-site SEO audit with a 0–100 score and letter grade, no Google connection required; checks span Basic SEO, Advanced SEO, Content, Performance & Technical, and Security, with a dashboard card and a full SEO Analyzer tab including per-category results and "how to fix" guidance
- New: HowTo block — step-by-step instructions with per-step images, total time, and automatic HowTo schema markup
- New: Table of Contents block — builds its list live from your headings, adds anchor links that work without JavaScript, and emits SiteNavigationElement schema
- New: Elementor widgets for FAQ, HowTo, and Table of Contents — Elementor-built pages get the same content patterns and structured data as the Gutenberg blocks
- Improved: Traffic Overview on the Analytics dashboard redesigned with clearer metric cards, per-metric icons, and trend badges (bounce rate correctly treats lower as better)
- Improved: Google Services settings screens redesigned and load faster — settings load in parallel, and Google account lists (Search Console sites, Analytics accounts/properties) are cached for 30 minutes instead of fetched on every visit
- Improved: Core Web Vitals collection uses your own PageSpeed API key or Google login first and re-measures automatic collections at most once a week, so measurements stay within Google's quota (the Refresh button still forces a fresh run)
- Fixed: Blank admin screen crash in production builds ("cannot access lexical declaration before initialization")
- Fixed: The Opportunities screen could fail with a server error (500) due to a data-format mismatch in the opportunity prioritizer
- Fixed: The "Configure SEO" launcher could appear twice in the block editor on sites where the editor loads slowly
- Fixed: Inserting a FAQ, HowTo, or Table of Contents block no longer makes the whole post render unstyled in the editor
- Fixed: The free Analytics dashboard no longer shows a "requires ThinkRank Pro" notice for features already presented as Pro previews
- Few minor bug fixes & improvements

= 1.18.0 =
Release Date: 2026-07-16

- New: "Explain with AI" on SEO score suggestions — get a short, post-specific explanation of why a suggestion matters and how to fix it, right from the editor metabox or the SEO analysis screen (uses your configured AI provider)
- Improved: AI provider settings redesigned as tabs with a clear "Active" indicator; saved API keys now show as a masked preview with a Connected status and a Replace button
- Improved: Claude model list refreshed (Claude Sonnet 5, Opus 4.8, Haiku 4.5) — retired model IDs saved in settings are migrated automatically so AI requests keep working
- Improved: Google Services screens (Analytics dashboard, Core Web Vitals, Opportunities, Performance) load much faster — Lighthouse data now comes from a single shared PageSpeed run and admin screens reuse cached responses instead of refetching on every visit
- Improved: Site Health Audit "Configure" actions now deep-link straight to the relevant settings section
- Fixed: PageSpeed measurements no longer fail with rate-limit (429) errors — requests now run keyless or with your own Google API key instead of a shared quota
- Fixed: Empty Core Web Vitals and Opportunities screens caused by failed Google responses being cached — transient failures now retry instead of blanking the screens, and a performance score stuck at "collecting" now surfaces the actual reason
- Fixed: Fatal error from a class autoloader mis-mapping when loading the PageSpeed integration
- Fixed: AI title/meta suggestions could fail on reasoning models due to truncated responses, and AI response caching now works as intended
- Security: AI endpoints now cap analyzed content length server-side to prevent oversized requests
- Few minor bug fixes & improvements

= 1.17.0 =
Release Date: 2026-07-14

- New: Agentic AI SEO with the built-in ThinkRank MCP server — connect Claude, ChatGPT, Cursor, and other MCP-compatible AI assistants to manage SEO in plain language (35+ SEO tools; one-click OAuth 2.1 connect for Claude, Application Password fallback for other clients; off by default, admin-only)
- Improved: "View Generated Sitemaps" links are enabled only after the sitemap files have actually been generated, and applying a sitemap template now prompts you to regenerate
- Fixed: Content brief generation no longer times out on large briefs — AI requests for briefs now allow up to 120 seconds
- Fixed: WebSite schema was silently skipped when regenerating schema after site settings changed
- Fixed: SEO score heading analysis now counts the post title as the page H1, so titled posts are no longer flagged as missing an H1
- Fixed: The "Configure SEO" launcher is reliably pinned in the block editor header on WordPress 6.6+ and always shows the SEO score badge
- Fixed: Instant Indexing history now respects Role Manager permissions instead of a generic capability check
- Few minor bug fixes & improvements

= 1.16.1 =
Release Date: 2026-07-12

- Improved: Instant Indexing now submits URLs in the background via WP-Cron, so publishing or updating a post no longer waits on the IndexNow request
- Improved: Expanded the Privacy section and added an "External services" section documenting every third-party request ThinkRank can make
- Fixed: AI features (content brief, metadata, SEO score) no longer time out with a 502 on slower hosts — the PHP time limit is raised for blocking AI requests
- Fixed: Saved Briefs panel is capped to the form height and scrolls internally instead of overflowing
- Fixed: Removed a stray breadcrumb reference from schema output
- Security: Hardened REST API permission and capability checks, added SSRF protection to sitemap and content-brief URL fetching, per-user AI rate limiting, and direct-access (ABSPATH) guards
- Few minor bug fixes & improvements

= 1.16.0 =
Release Date: 2026-07-09

- New: FAQ Gutenberg block (`thinkrank/faq`) — inline Q&A rendered as a no-JavaScript accordion with FAQPage schema
- New: Migration Tools — opt-in setting that reveals a dedicated Migration page to re-run SEO data imports after setup
- New: Setup Wizard Ecosystem auto-detects already-active plugins; migrated SEO plugins are deactivated automatically once their data is imported
- New: Editable permalink slug in the metabox, and a "You have already used this Focus Keyword" warning
- New: Out-of-the-box schema on posts, pages, CPTs, archives, and the homepage (WebSite + SearchAction, CollectionPage), plus canonical URLs and meta descriptions on archive pages
- New: Local business sitemap (`local-sitemap.xml`), and fuller Rank Math local-business migration (address, geo, price range, opening hours)
- Changed: Migration page and SEO conflict notice redesigned to match the ThinkRank/native WordPress look; sidebar navigation regrouped
- Fixed: XML sitemap no longer drops short/"demo" content or lists non-viewable CPTs; 404/search/date-archive robots directives corrected
- Fixed: robots.txt no longer blocks render-critical CSS/JS paths
- Security: Hardened REST API permission and capability checks
- Few minor bug fixes & improvements

= 1.15.0 =
Release Date: 2026-07-06

- New: Role Manager — control which roles can access Essential SEO, AI Tools, and Settings sections
- New: Elementor integration — edit ThinkRank SEO fields directly inside the Elementor editor, plus a Classic Editor sidebar widget
- New: AI providers — OpenRouter support, custom-model entry for any provider, and Gemini 3.x models
- New: SEO Score — one-click "Apply" for AI-generated title, meta description, link, keyword, and schema fixes
- Changed: Per-post SEO scoring now aligns with other SEO plugins. Segmented sitemaps now paginate based on the Links per Sitemap setting.
- Fixed: Duplicate robots/canonical meta tags removed; honor "Discourage search engines"; full og:title without truncation
- Improved: Import now migrates sitemap settings and Yoast title/description templates and author meta
- Few minor bug fixes & improvements

= 1.14.0 =
Release Date: 2026-06-25

- New: 7-step Setup Wizard onboarding with migration and Site Setup pre-fill
- New: Native multiple focus keywords (up to 5), with extra keywords gated behind Pro
- New: Redesigned SEO metabox with logo launcher, bottom drawer, and live SEO pattern previews
- New: VideoObject and Review schema types with Rank Math import support
- Changed: Expanded SEO data import (Rank Math schema, sitemap, Image SEO, templates, pillar content & more)
- Fixed: Honor per-post OG title/description overrides; full-size featured image in social preview
- Few minor bug fixes & improvements

= 1.13.0 =
Release Date: 2026-06-16

- New: Email Reporting — scheduled SEO email reports
- Changed: Refactored schema generation and settings components
- Few minor bug fixes & improvements

= 1.12.0 =
Release Date: 2026-06-15

- Added: Redesigned admin interface built on Tailwind CSS with a new reusable UI component library
- Changed: Modernized loading states and navigation, and renamed "Usage Analytics" to "Usages"
- Fixed: Uninstall now preserves data by default, preventing accidental data deletion

= 1.11.0 =
Release Date: 2026-05-24

- Added: CSS design system with `tr-*` component classes and `--tr-*` design tokens
- Added: Pre-commit hooks (husky + lint-staged) for automated lint on commit
- Added: Auto DB migration on `plugins_loaded` — missing tables/columns are created automatically without requiring deactivation/reactivation
- Changed: WordPress 6.9+ component compliance (`__nextHasNoMarginBottom`, `__next40pxDefaultSize` props)
- Changed: Moved XSL sitemap stylesheets from `assets/` to `static/xsl/` (survives webpack clean builds)
- Changed: DB schema version bumped to 1.1.0
- Fixed: 63 dead JS imports removed across 22 files
- Fixed: `npm run release` zip now correctly includes `assets/` and `static/` directories
- Fixed: lint-staged no longer hangs on existing formatting mismatches
- Removed: Build artifacts from git tracking (12 files, 1.4MB) — now generated via `npm run build`

= 1.10.0 =
Release Date: 2026-05-05

- Added: New Feature | Google Analytics
- Few minor bug fixes & improvements


= 1.9.0 =
Release Date: 2026-03-31

- Improved: Updated dashboard interface for better user experience
- Few minor bug fixes & improvements


= 1.8.0 =
Release Date: 2026-03-11

- Added: New Feature | Import Schema From Any Website
- Few minor bug fixes & improvements

= 1.7.0 =
Release Date: 2026-02-15

- Added: New Feature | Author Archives SEO
- Few minor bug fixes & improvements

= 1.6.0 =
Release Date: 2026-02-09

- Added: New Feature | Pillar Content
- Few minor bug fixes & improvements

= 1.5.0 =
Release Date: 2026-01-12

- Added: New Feature | Global Robot Meta
- Few minor bug fixes & improvements

= 1.4.0 =
Release Date: 2026-01-06

- Added: New Feature | Image SEO
- Few minor bug fixes & improvements

= 1.3.0 =
Release Date: 2025-12-29

- Added: New Feature | Instant Indexing
- Few minor bug fixes & improvements

= 1.2.0 =
Release Date: 2025-12-11

- Added: New Feature | ThinkRank SEO Overview
- Few minor bug fixes & improvements

= 1.1.0 =
Release Date: 2025-11-23

- Added: New Feature | Bulk SEO Optimization Settings
- Few minor bug fixes & improvements

= 1.0.2 =
Release Date: 2025-10-30

- Fixed: Google Service integration issue for PageSpeed Insights
- Fixed: WooCommerce edit conflict with ThinkRank plugin
- Fixed: Version number inconsistency
- Few minor bug fixes & improvements

= 1.0.1 =
Release Date: 2025-09-25

- Improved: Updated Database Schema
- Fixed: AI Content Brief not showing properly on Firefox
- Few minor bug fixes & improvements

= 1.0.0 =
Release Date: 2025-07-07

- Initial release
- AI Content Brief Generator with competitor analysis
- Real-time SEO Score Calculator (13-factor)
- AI Metadata Generation with SERP preview
- Competitor analysis insights
- Export content briefs in multiple formats
- Complete Site Identity management (Basic Info, Title Formats, Breadcrumbs, Hero & Branding, Business Info)
- Advanced Schema Manager for organization/website structured data
- Social Media Optimization (Open Graph, Twitter Cards)
- Performance Monitoring (Core Web Vitals, PageSpeed, diagnostics)
- Analytics Integration (GA4, Search Console) with insights
- Robots.txt management with AI optimization
- XML Sitemap generation: Basic, Complete, E-commerce, Segmented
- LLMs.txt for AI indexing
- SEO Analytics Dashboard with keyword opportunities
- AI-powered insights with natural language explanations
- Real-time diagnostics and improvement recommendations
- Intelligent reporting with automated alerts and trends
- Post/Page metabox integration with real-time optimization
- Multi-AI provider support (OpenAI GPT-4, Claude)
- Local SEO data management
- Secure API key management; your own keys
- React-based admin interface
- PHP 8.0+ with strict typing
- Custom tables and intelligent caching
- Error handling, logging, performance optimization
- WordPress 6.0+ compatibility

== Upgrade Notice ==

= 1.27.0 =
Fixes MCP connections that succeeded while offering zero tools, connectors that could lock themselves out permanently, and ChatGPT rejecting sites with "does not implement OAuth". Also fixes IndexNow returning 403 on read-only hosts, AI writing English metadata on non-English sites, and a hidden ThinkRank SEO metabox. Recommended for all sites.

= 1.26.0 =
Fixes saving from the ThinkRank panel silently doing nothing on posts with no other edits, and SEO scores that stayed stale for minutes after a title or description change. Also scores inherited titles and descriptions correctly and adds TranslatePress support. Recommended for all sites.

= 1.24.0 =
Fixes page-builder pages (Oxygen, Breakdance, Divi, Elementor) being scored as empty everywhere outside the editor. Adds a Search Console dashboard widget, builder-native editing panels, and full-article content briefs. Recommended for all sites, especially page-builder sites.

= 1.23.0 =
Adds WPML and Polylang support. Fixes SEOPress import, which never imported settings at all, and several All in One SEO import defects that dropped keywords, primary categories and site-wide settings. Recommended for anyone migrating from another SEO plugin or running a multilingual site.

= 1.22.0 =
Fixes administrators being locked out of ThinkRank after a reinstall, plus Yoast import gaps (organization identity, title formats, roles) and Gemini content briefs failing on long output.

= 1.21.0 =
Fixes a fatal error that could white-screen 404 pages and posts with a social share image. Also fixes SEO scoring ignoring your focus keyword, corrupted title-format variables, and a large batch of XML sitemap defects. Update recommended for all sites.

= 1.17.0 =
ThinkRank now includes a built-in MCP server for agentic AI SEO — connect Claude, ChatGPT, or Cursor to manage metadata, schema, sitemaps, llms.txt, and Search Console insights in plain language.

= 1.0.0 =
Initial release of ThinkRank. Welcome to AI-powered SEO optimization!
