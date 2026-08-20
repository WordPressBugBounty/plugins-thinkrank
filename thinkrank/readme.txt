=== ThinkRank – The SEO Plugin Your AI Assistant Can Operate: Metadata, Schema, Sitemaps & Migration ===
Contributors: wpdevteam, thinkrank, re_enter_rupok, rafinkhan, rudlinkon, mdnahidhasan
Tags: seo, ai, schema, xml sitemap, meta description
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Switch from Yoast, Rank Math, AIOSEO or SEOPress in minutes. Run SEO from an AI chat window — every AI feature included, no credit meter.

== Description ==

**[ThinkRank](https://thinkrank.ai/) is the WordPress SEO plugin your AI assistant can operate.** Ask Claude, ChatGPT, or Cursor to write metadata, audit posts, fix schema, and run bulk SEO work on your site — in plain language, from the chat window you already use. Underneath sits a complete SEO plugin: meta titles and descriptions, real-time content analysis, keyword optimization, schema markup, XML sitemaps, robots meta, canonical URLs, Open Graph, and Google Search Console + GA4 insights.

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
* **Connection health check** — a "Test connection" button makes a real call and tells you exactly which step failed: HTTPS, authentication, permissions, or ability discovery.
* **Safe imports** — connected assistants can preview an SEO import as a dry run before anything is written.

= Easy Claude, ChatGPT, Cursor and MCP Client Setup =

* **One-click Claude connection** via a guided OAuth 2.1 flow with PKCE — no API key to copy, no config file.
* **Application Password fallback** for ChatGPT, Cursor, and other MCP-compatible clients, with ready-made configuration details.
* **No companion plugin required** and **no terminal setup**.
* **Admin-controlled and off by default** — enable it under ThinkRank → MCP whenever you're ready.
* **Revocable in one click** — delete the connected Application Password and access is removed immediately.

= Migrate from Rank Math, Yoast SEO, AIOSEO or SEOPress =

A guided Setup Wizard detects your current SEO plugin and imports your metadata, schema settings, sitemap settings, image SEO data, templates, and related SEO settings from **Rank Math SEO, Yoast SEO, All in One SEO (AIOSEO), and SEOPress** — then deactivates the old plugin once its data is safely migrated.

The importers go well past basic post meta: title formats per page type, Knowledge Graph and organization identity, author archive settings, role permissions, IndexNow keys and submission history, per-post sitemap exclusions, breadcrumb settings, social defaults, News/Video sitemap post types, and local business details all carry over where the source plugin has them.

If you're looking for a **Rank Math, Yoast SEO, AIOSEO, or SEOPress alternative** because of upsell notices, metered AI credits, or per-site pricing, this migration path is built for exactly that switch — and our promotional surface stays confined to ThinkRank's own settings pages, where every notice is dismissible.

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

= llms.txt Generator =

* Generate and maintain an llms.txt file — a Markdown index of your important content that AI coding agents and agentic tools read by convention.
* Straight talk, because you deserve a vendor that gives it: Google has stated llms.txt does not affect Google Search or AI Overviews, and no reliable evidence ties the file to AI citations. We ship it as useful infrastructure for AI tooling, not as a ranking lever.
* Auto-regenerates as your content changes, with full control over what's included.

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
* Teams that run their work through an AI assistant

= Why Choose ThinkRank? =

* The only WordPress SEO plugin your AI assistant can operate directly (built-in MCP server)
* Every AI feature included at a flat price — your own API key, no monthly credit meter
* Real on-page and technical SEO — not just an AI writer
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

= 1.30.0 =
Release Date: 2026-08-13

- New: AI alt text for images — ThinkRank can now look at the picture and describe it, instead of only rewriting the filename. Choose it under Image SEO; the filename template stays the default because AI costs you money per image, and AI runs are capped at 10 images per click
- New: One-click fixes in the Site SEO Audit. Where an issue is safely fixable — search visibility, XML sitemap, structured data, missing image alt text — a button applies it and the audit is re-scored on the spot. Fixes with consequences warn you before you click, and things that are your call (your titles, your permalinks, your server) are deliberately left alone with an explanation
- New: A warning when WordPress is set to discourage search engines from indexing your site, so the setting that quietly undoes all your SEO cannot sit there unnoticed
- Fixed: Opening the editor could hammer your site with repeated SEO requests until pages took 30-40 seconds to load, or failed outright. The panel now makes one request at a time, and only keeps checking while ThinkRank is actually writing metadata in the background
- Fixed: SEO data imported from another plugin did not appear in an editor that was already open until you reloaded the page
- Fixed: Saved content briefs could not be listed, exported or deleted on sites with no AI key configured — the screen failed with a server error, even though none of those actions need AI
- Fixed: On some hosts ThinkRank's settings tables were never created, so every save in Site Identity, Sitemap, Social and Schema silently failed and the setup wizard dead-ended after a migration. The tables now fit the stricter index limit on those servers, and existing sites are migrated
- Fixed: A settings save that fails now records why in the error log, instead of leaving you with "Failed to update settings" and nothing to act on
- Fixed: The one-click sitemap and schema fixes reported success while saving nothing, and the audit's sitemap check could never fail no matter what your settings said
- Fixed: Author archives printed two meta descriptions, ended their title in a stray space, ignored your chosen title separator, and would not let you clear the title or description templates — a cleared field came back on the next load
- Fixed: The alt-text coverage panel could claim "6 of 5 images have alt text" by counting trashed images on one side only, and the bulk alt fill could spin forever on sites with private images, turning one click into thousands of requests
- Fixed: The Alt text source setting accepted any value, so an unrecognised value silently fell back to the template and the AI batch limit stopped applying
- Fixed: Saving Schema settings could white-screen the site on WordPress 6.0, after the settings had already been written
- Fixed: Email reports — the Save button disappeared when you toggled the feature off, so turning it off never stuck; changing the frequency did not move the next send; a failed send still consumed the whole reporting period; the report described three different date ranges at once; "Next report" showed the wrong time outside UTC; pages and keywords that lost all their clicks were dropped from the Top Losing sections; and a report with no sections could be configured and sent empty
- Fixed: Deleting ThinkRank with "Delete all data on uninstall" enabled while ThinkRank Pro was active silently reinstalled the free plugin and recreated all of its data
- Fixed: Data Management could not be saved on sites without an AI API key
- Fixed: An author or contributor granted Schema Manager access could deploy structured data onto other people's posts
- Fixed: Alt text beginning with an accented character was corrupted
- Changed: AI Visibility data is now removed when you delete the plugin with data deletion enabled, and old probe transcripts are aged out after 90 days instead of accumulating forever
- Changed: The Integrations tab no longer displays or re-saves settings it has no control over, and a partial save now leaves untouched settings exactly as they were

= 1.29.0 =
Release Date: 2026-08-11

- New: Show/hide your API key while typing it, and a Replace control so a saved key can be swapped without clearing it first. Saved keys are now only ever sent back to your browser masked, never in full
- New: A social card on the dashboard with the ThinkRank community and channels in one place
- Fixed: Content briefs that came back as "Unable to parse AI response", or saved a brief full of raw API output. When the AI refuses, is cut off at its token limit, or answers in an unexpected shape, ThinkRank now tells you what happened instead of saving the failure as a brief
- Fixed: Short, Medium and Long content briefs all asked the AI for the same output budget, so a Long brief could be truncated while a Short one paid for room it never used. Each length now requests a budget that fits it
- Fixed: Briefs on GPT-5 models ran at maximum reasoning effort by default — the slowest and most expensive setting, with much of the budget spent on hidden reasoning before any text was written
- Fixed: A request that ran past the time ThinkRank allows was retried twice more, each attempt near-certain to time out again — multiplying the wait while the abandoned requests kept running, and billing, on your AI provider. A timed-out request now fails once, straight away, instead of being retried
- Fixed: "X days ago" counts on sites outside UTC were off by the site's time offset
- Fixed: Saving settings could send your API key back to the browser in the clear, and re-saving a form that displayed a masked key could overwrite the real key with the mask
- Fixed: Importing SEO data from another plugin read serialized values from that plugin's tables in a way that could be abused to create PHP objects on your site; those values are now parsed without ever instantiating an object
- Fixed: Fetching a schema or competitor URL could be pointed at private, internal or cloud-metadata addresses, including addresses disguised as IPv6. All outbound fetches now share one check, applied again on every redirect hop
- Fixed: Dismissing a ThinkRank admin notice, importing or resetting the whole configuration, and adding database indexes no longer accept requests from users without the capability for them
- Changed: Brand Visibility is temporarily hidden while it is reworked. Nothing is deleted — recorded runs, history and settings are kept, and the feature returns in a later release

= 1.28.0 =
Release Date: 2026-08-10

- New: AI Insights — see how much of your traffic comes from AI assistants and AI crawlers, first-party. AI platforms strip referrers so Google Analytics files those visits under "Direct"; ThinkRank reads them as they arrive. Daily totals only — no IPs, no cookies, no per-visit tracking
- New: Brand Visibility measures whether AI assistants mention your brand — asking each question several times for a real mention rate instead of a one-shot yes/no, ranking you against named competitors as share of voice, and scoring everything into a 0–100 Visibility Index
- New: Brand Visibility uses its own API key and model per AI platform, so measuring visibility never means switching the provider that writes your metadata. Adds Perplexity, which searches the web live — the closest thing to what a person actually sees
- New: Auto AI writes a missing SEO title or description when you publish, in the background, so publishing never waits on an AI call. It fills empty fields only and never overwrites what you wrote
- New: Quick Edit SEO on the posts list — edit the SEO title, meta description and focus keywords straight from the posts table, without opening the post. Cleared fields fall back to your Global SEO patterns
- New: Traffic Recovery tab on the AI Tools screen, which finds published pages losing search traffic and writes a refresh brief for each
- New: A Migration screen for moving SEO data in from another plugin, with a re-scan control that re-detects what is installed
- New: Social platform verification is now one tab per network, with per-card saving and masked verification codes
- Fixed: The AI Traffic and Brand Visibility tables were never created, so AI traffic went unrecorded and every brand check spent a paid AI call without saving any history
- Fixed: Brand Visibility failed to start on upgraded sites with "Could not start the analysis run". A missing table is now detected and repaired on load, instead of going unnoticed until a deactivate/reactivate
- Fixed: Brand checks reported "not mentioned" for questions that could not plausibly omit your brand — the AI never actually returned an answer, and the blank was saved as a clean negative. A probe with no answer now fails visibly and is never stored
- Fixed: A Brand Visibility run could sit at 0% forever, and progress never advanced while it was running
- Fixed: A site that configured Brand Visibility on Pro and then lapsed to free kept running the larger Pro batch on the user's own paid API keys
- Fixed: The SEO Overview column showed "Not Analyzed" forever on sites that never ran an analysis, even right after editing a post's SEO title and description
- Fixed: Saving in the editor could wipe an SEO title or description that Auto AI, bulk optimization or an import had just written, and the editor kept showing the old empty value until a manual reload
- Fixed: Google Analytics, Search Console and PageSpeed were reported as "not configured" on sites connected through Google sign-in, and a site with a perfectly working GA4 tag was told its tracking was unverified
- Fixed: Reconnecting a different Google account kept showing the previous account's Search Console properties for up to 30 minutes, and domain properties could not be found by searching
- Fixed: The ThinkRank data stream picker in Pro went blank on every reload
- Fixed: ThinkRank's styles leaked into the WordPress admin and page builders

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

[See changelog for all versions](https://thinkrank.ai/changelog/).

== Upgrade Notice ==

= 2.0.0 =
Redesigns the FAQ block and lets every answer carry its own image, both on the page and in its structured data. Fixes the FAQ editor's misaligned question and answer fields, a permalink change that looked like it had not saved (and in Elementor, Divi and Oxygen genuinely had not), and a keyword-in-address check that always failed on drafts while reporting false matches from parent pages, categories and dates on published ones. Recommended for all sites.

= 1.32.0 =
Publishes all of ThinkRank's structured data as one linked graph, with every FAQ on the page merged into a single FAQPage. Rebuilds the Instant Indexing Submit URLs and History screens, makes every feature switch save itself, and fixes AI assistant connections on hosts that intercept /.well-known/ addresses. Includes five security fixes: password-protected post content published as meta and social descriptions, unsanitized AI content briefs, and three cases where a user with access to one ThinkRank section could read or write SEO data for content they cannot edit. Also stops settings saves reporting a failure after they had saved. Recommended for all sites.

= 1.31.0 =
Adds Submission Coverage for Instant Indexing, applies a noindex set on category and tag archives, and shows what your robots.txt actually serves. ThinkRank now runs on PHP 7.4 and stops WordPress publishing a second, competing sitemap. Fixes /sitemap.xml leading crawlers to a dead page, a cleanup that deleted other plugins' sitemap files and left your site without one, Core Web Vitals failures reported as server errors, and Open Graph claiming every site is in US English. Recommended for all sites.
