<?php

/**
 * Rank Math Exporter
 *
 * Reads Rank Math data from postmeta/termmeta/options and normalizes
 * into the canonical snapshot format.
 *
 * CRITICAL: rank_math_robots is a serialized array — use maybe_unserialize()
 * then in_array() to check for 'noindex'/'nofollow'.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rankmath Exporter Class
 *
 * @since 2.0.0
 */
class Rankmath_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Rank Math rich-snippet slug => ThinkRank schema-type vocabulary.
     *
     * ThinkRank's supported types come from Schema_Settings_Config::
     * get_supported_schema_types() (PascalCase). Rank Math stores lowercase
     * slugs and uses 'off' for "no schema". Slugs with no ThinkRank equivalent
     * (book, course, recipe, service, music, video, jobposting) and 'off'/'none'
     * map to '' so the migrator's empty-skip leaves no invalid schema-type value
     * behind. Review's rating fields are carried in the record's `extended`
     * (review_schema) and migrated into the post's schema form data.
     */
    private const SCHEMA_TYPE_MAP = [
        'article'     => 'Article',
        'product'     => 'Product',
        'woocommerce' => 'Product',
        'software'    => 'SoftwareApplication',
        'event'       => 'Event',
        'howto'       => 'HowTo',
        'faq'         => 'FAQPage',
        'person'      => 'Person',
        'restaurant'  => 'LocalBusiness',
        'review'      => 'Review',
        'video'       => 'VideoObject',
    ];

    /**
     * Rank Math MODERN schema @type => ThinkRank schema-type vocabulary.
     *
     * Current Rank Math stores per-post schema under `rank_math_schema_{Type}`
     * meta (a serialized block carrying an `@type` and a `metadata.isPrimary`
     * flag) rather than the legacy `rank_math_rich_snippet` slug. These keys are
     * already PascalCase schema.org types. Subtypes with no distinct ThinkRank
     * equivalent fold onto their nearest supported parent (e.g. BlogPosting →
     * Article); types ThinkRank does not model (Recipe, …) are absent and
     * resolve to '' (no schema). VideoObject maps through to ThinkRank's
     * VideoObject and its block fields are migrated into the schema form data.
     */
    private const MODERN_SCHEMA_TYPE_MAP = [
        'article'             => 'Article',
        'blogposting'         => 'Article',
        'newsarticle'         => 'Article',
        'product'             => 'Product',
        'woocommerceproduct'  => 'Product',
        'event'               => 'Event',
        'howto'               => 'HowTo',
        'faqpage'             => 'FAQPage',
        'person'              => 'Person',
        'localbusiness'       => 'LocalBusiness',
        'restaurant'          => 'LocalBusiness',
        'softwareapplication' => 'SoftwareApplication',
        'review'              => 'Review',
        'organization'        => 'Organization',
        'videoobject'         => 'VideoObject',
    ];

    /**
     * Deny-list of sensitive option-key fragments stripped from the raw option
     * capture (see capture_raw_options()). Account-bound secrets must never be
     * persisted into our wp_options snapshot — Search Console / Analytics are
     * always a fresh connect in ThinkRank, never a migrated token.
     *
     * Matches: tokens, secrets, credentials, api keys, connected-account emails
     * (console_email*), OAuth material (console_authorization_code, oauth_*)
     * and authentication fields. `auth` is matched via `authoriz|authenticat|
     * (^|[_-])auth([_-]|$)` rather than a bare `auth` so legitimate `author_*`
     * keys (author_custom_robots, authors_sitemap, …) are NOT stripped.
     */
    private const SENSITIVE_KEY_PATTERN =
        '/token|secret|credential|api_key|console_email|oauth|authoriz|authenticat|(^|[_-])auth([_-]|$)/i';

    /**
     * Upper bound on IndexNow history entries carried into the snapshot, so a
     * runaway source log cannot bloat the settings chunk. Overflow is reported
     * via the record's `truncated` count, never dropped silently.
     */
    private const MAX_INDEXNOW_LOG_ENTRIES = 1000;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = 'rankmath';
        $this->plugin_name = 'Rank Math';
        $this->plugin_file = 'seo-by-rank-math/rank-math.php';
        $this->meta_key_prefix = 'rank_math_';
        $this->option_keys = ['rank-math-options-general', 'rank-math-options-titles'];
    }

    /**
     * {@inheritDoc}
     */
    public function detect(): bool {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1",
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            )
        );

        return $count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function get_available_types(): array {
        global $wpdb;

        $types = [];

        $post_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            )
        );
        if ($post_count > 0) {
            $types['postmeta'] = $post_count;
        }

        $term_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            )
        );
        if ($term_count > 0) {
            $types['termmeta'] = $term_count;
        }

        $user_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            )
        );
        if ($user_count > 0) {
            $types['usermeta'] = $user_count;
        }

        // Redirection rules and 404 hits live in Rank Math's own tables. Both
        // have a ThinkRank Pro home (Redirections & 404 Monitor), so they are
        // offered as exportable types whenever the source table holds rows.
        $redirection_count = $this->count_source_table_rows('rank_math_redirections');
        if ($redirection_count > 0) {
            $types['redirections'] = $redirection_count;
        }

        $log_count = $this->count_source_table_rows('rank_math_404_logs');
        if ($log_count > 0) {
            $types['404_logs'] = $log_count;
        }

        foreach ($this->option_keys as $key) {
            if (get_option($key, null) !== null) {
                $types['settings'] = 1;
                break;
            }
        }

        return $types;
    }

    /**
     * Count rows in one of Rank Math's own tables, tolerating its absence
     * (modules can be disabled, and the standalone plugin ships fewer tables).
     *
     * @param string $unprefixed Table name without the `$wpdb->prefix`
     * @return int Row count, or 0 when the table does not exist
     */
    private function count_source_table_rows(string $unprefixed): int {
        global $wpdb;

        $table = $wpdb->prefix . $unprefixed;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * {@inheritDoc}
     */
    protected function export_postmeta_page(int $page): array {
        $post_ids = $this->get_post_ids_with_meta($page);

        if (empty($post_ids)) {
            return [];
        }

        $records = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $meta = $this->get_all_plugin_meta($post_id);

            if (empty($meta)) {
                continue;
            }

            // CRITICAL: rank_math_robots is a serialized indexed array of
            // directive strings (noindex, nofollow, noarchive, noimageindex,
            // nosnippet). The max-* directives live in a SEPARATE meta key,
            // rank_math_advanced_robots, stored as an associative array
            // (['max-snippet' => length|false, ...]).
            $robots_raw = $meta['rank_math_robots'] ?? '';
            $robots = $this->normalize_robots($robots_raw);
            $robots_flags = $this->extract_robots_flags($robots_raw);
            $advanced_robots = $this->parse_advanced_robots_meta($meta['rank_math_advanced_robots'] ?? '');

            // Focus keyword may be comma-separated; first = primary
            $focus_kw_raw = $meta['rank_math_focus_keyword'] ?? '';
            $focus_keywords = array_map('trim', explode(',', $focus_kw_raw));
            $primary_keyword = $focus_keywords[0] ?? '';
            $additional_keywords = array_slice($focus_keywords, 1);

            $records[] = [
                'object_id'     => $post_id,
                'object_type'   => 'post',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'           => $this->convert_template_variables($meta['rank_math_title'] ?? '', $post_id),
                    'meta_description'    => $this->convert_template_variables($meta['rank_math_description'] ?? '', $post_id),
                    'focus_keyword'       => $primary_keyword,
                    // Full keyword list; the migrator dedupes, drops empties and
                    // caps at the ThinkRank maximum via Focus_Keywords.
                    'focus_keywords'      => $focus_keywords,
                    'canonical_url'       => $meta['rank_math_canonical_url'] ?? '',
                    'noindex'             => $robots['noindex'],
                    'nofollow'            => $robots['nofollow'],
                    'noarchive'           => isset($robots_flags['noarchive']) ? 1 : 0,
                    'noimageindex'        => isset($robots_flags['noimageindex']) ? 1 : 0,
                    'nosnippet'           => isset($robots_flags['nosnippet']) ? 1 : 0,
                    'max_snippet'         => $this->advanced_robot_int($advanced_robots, 'max-snippet'),
                    'max_video_preview'   => $this->advanced_robot_int($advanced_robots, 'max-video-preview'),
                    'max_image_preview'   => $this->advanced_robot_string($advanced_robots, 'max-image-preview'),
                    'og_title'            => $this->convert_template_variables($meta['rank_math_facebook_title'] ?? '', $post_id),
                    'og_description'      => $this->convert_template_variables($meta['rank_math_facebook_description'] ?? '', $post_id),
                    'og_image'            => $meta['rank_math_facebook_image'] ?? '',
                    'twitter_title'       => $this->convert_template_variables($meta['rank_math_twitter_title'] ?? '', $post_id),
                    'twitter_description' => $this->convert_template_variables($meta['rank_math_twitter_description'] ?? '', $post_id),
                    'twitter_image'       => $meta['rank_math_twitter_image'] ?? '',
                    'primary_category'    => (int) ($meta['rank_math_primary_category'] ?? 0),
                    'schema_type'         => $this->resolve_schema_type($meta),
                    // Rank Math pillar content maps directly to ThinkRank pillar content.
                    'pillar_content'      => $this->normalize_pillar_content($meta['rank_math_pillar_content'] ?? ''),
                ],
                'extended' => [
                    'focus_keywords_additional' => $additional_keywords,
                    'pillar_content'            => (bool) ($meta['rank_math_pillar_content'] ?? false),
                    'breadcrumb_title'          => $meta['rank_math_breadcrumb_title'] ?? '',
                    'schema_details'            => $this->extract_schema_details($meta),
                    'review_schema'             => $this->extract_review_schema($meta),
                    'video_schema'              => $this->extract_video_schema($meta, $post_id),
                    'facebook_image_id'         => $meta['rank_math_facebook_image_id'] ?? '',
                    'twitter_image_id'          => $meta['rank_math_twitter_image_id'] ?? '',
                    'twitter_card_type'         => $meta['rank_math_twitter_card_type'] ?? '',
                    'twitter_use_facebook'      => $meta['rank_math_twitter_use_facebook'] ?? '',
                    'seo_score'                 => $meta['rank_math_seo_score'] ?? '',
                    'advanced_robots'           => $advanced_robots,
                    // Rank Math's per-post "Exclude from sitemap" toggle. ThinkRank
                    // has no per-post meta for this — the migrator folds these IDs
                    // into the sitemap's exclude_posts list.
                    'exclude_sitemap'           => !empty($meta['rank_math_exclude_sitemap']),
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_termmeta_page(int $page): array {
        $term_ids = $this->get_term_ids_with_meta($page);

        if (empty($term_ids)) {
            return [];
        }

        $records = [];
        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;
            $meta = $this->get_all_plugin_term_meta($term_id);

            if (empty($meta)) {
                continue;
            }

            $robots_raw = $meta['rank_math_robots'] ?? '';
            $robots = $this->normalize_robots($robots_raw);

            $focus_kw_raw = $meta['rank_math_focus_keyword'] ?? '';
            $focus_keywords = array_map('trim', explode(',', $focus_kw_raw));
            $primary_keyword = $focus_keywords[0] ?? '';

            $records[] = [
                'object_id'     => $term_id,
                'object_type'   => 'term',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'        => $this->convert_term_template_variables($meta['rank_math_title'] ?? '', $term_id),
                    'meta_description' => $this->convert_term_template_variables($meta['rank_math_description'] ?? '', $term_id),
                    'focus_keyword'    => $primary_keyword,
                    'canonical_url'    => $meta['rank_math_canonical_url'] ?? '',
                    'noindex'          => $robots['noindex'],
                    'nofollow'         => $robots['nofollow'],
                    'og_title'         => $this->convert_term_template_variables($meta['rank_math_facebook_title'] ?? '', $term_id),
                    'og_description'   => $this->convert_term_template_variables($meta['rank_math_facebook_description'] ?? '', $term_id),
                ],
                'extended' => [
                    'og_image'    => $meta['rank_math_facebook_image'] ?? '',
                    'twitter_title' => $meta['rank_math_twitter_title'] ?? '',
                    'twitter_description' => $meta['rank_math_twitter_description'] ?? '',
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_usermeta_page(int $page): array {
        $user_ids = $this->get_user_ids_with_meta($page);

        if (empty($user_ids)) {
            return [];
        }

        $records = [];
        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;
            $meta = $this->get_all_plugin_user_meta($user_id);

            if (empty($meta)) {
                continue;
            }

            // Author-archive SEO title/description override (Rank Math stores these
            // on the user profile). Values are literal text — resolve any stray
            // template tokens with the site-level resolver (no post context).
            $seo_title = $this->convert_template_variables($meta['rank_math_title'] ?? '');
            $meta_description = $this->convert_template_variables($meta['rank_math_description'] ?? '');

            // Rank Math also stores per-user social-overlay, permalink, twitter
            // card and SEO-score meta; ThinkRank has no equivalent for those, so a
            // record is only emitted when there is a migratable title/description.
            if ($seo_title === '' && $meta_description === '') {
                continue;
            }

            $records[] = [
                'object_id'     => $user_id,
                'object_type'   => 'user',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'        => $seo_title,
                    'meta_description' => $meta_description,
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_settings(): array {
        // get_option()'s [] default only covers a missing row; a row holding a
        // scalar/false would flow into the array-typed helpers below and throw a
        // TypeError. Normalize each to an array.
        $general = get_option('rank-math-options-general', []);
        $general = is_array($general) ? $general : [];
        $titles = get_option('rank-math-options-titles', []);
        $titles = is_array($titles) ? $titles : [];
        $sitemap = get_option('rank-math-options-sitemap', []);
        $sitemap = is_array($sitemap) ? $sitemap : [];

        return [
            [
                'type'          => 'settings',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'separator'            => $titles['title_separator'] ?? '-',
                    'homepage_title'       => $this->convert_template_variables($titles['homepage_title'] ?? ''),
                    'homepage_description' => $this->convert_template_variables($titles['homepage_description'] ?? ''),
                    'organization_name'    => $titles['knowledgegraph_name'] ?? '',
                    'organization_logo'    => $titles['knowledgegraph_logo'] ?? '',
                    // Rank Math's "Alternate Name" (schema.org alternateName) maps
                    // onto ThinkRank's site-identity alternate_name field.
                    'alternate_name'       => (string) ($titles['website_name'] ?? ''),
                    'social_profiles'      => [
                        'facebook'  => $titles['social_url_facebook'] ?? '',
                        'twitter'   => $titles['social_url_twitter'] ?? '',
                        'instagram' => $titles['social_url_instagram'] ?? '',
                        'linkedin'  => $titles['social_url_linkedin'] ?? '',
                        'youtube'   => $titles['social_url_youtube'] ?? '',
                        'pinterest' => $titles['social_url_pinterest'] ?? '',
                    ],
                    // Whether the archive is *noindexed*. Rank Math expresses this
                    // via its per-archive robots arrays (custom robots + 'noindex'),
                    // NOT via disable_*_archives (which removes the archive entirely).
                    'noindex_archives' => [
                        'date'   => in_array('noindex', (array) ($titles['date_archive_robots'] ?? []), true),
                        'author' => ($titles['author_custom_robots'] ?? 'off') === 'on'
                            && in_array('noindex', (array) ($titles['author_robots'] ?? []), true),
                    ],
                    'twitter_card_type' => in_array($titles['twitter_card_type'] ?? '', ['summary', 'summary_large_image', 'app', 'player'], true)
                        ? $titles['twitter_card_type']
                        : '',
                    // Site-wide social defaults with direct ThinkRank homes
                    // (Social Meta settings: facebook_app_id / default_image).
                    'social_defaults' => [
                        'facebook_app_id'  => (string) ($titles['facebook_app_id'] ?? ''),
                        'og_default_image' => (string) ($titles['open_graph_image'] ?? ''),
                    ],
                    // Rank Math's Knowledge Graph entity: 'company' or 'person',
                    // plus the entity name. Maps onto ThinkRank's schema settings
                    // (organization_name / person_name; organization_type's
                    // default 'Organization' already matches 'company').
                    'knowledge_graph' => [
                        'type' => $this->normalize_knowledgegraph_type($titles['knowledgegraph_type'] ?? ''),
                        'name' => (string) ($titles['knowledgegraph_name'] ?? ''),
                    ],
                    // IndexNow API key from Rank Math's Instant Indexing module.
                    // Unlike OAuth material this is NOT an account secret — it is a
                    // public verification token served at /{key}.txt — so carrying
                    // it over avoids re-verifying the site with IndexNow.
                    'instant_indexing' => [
                        'api_key' => $this->extract_rm_indexnow_key(),
                    ],
                ],
                'extended' => [
                    'breadcrumb_settings' => [
                        // Rank Math stores this as the string 'on'/'off'; !empty('off')
                        // is true, so it must be compared explicitly.
                        'enabled'    => ($general['breadcrumbs'] ?? 'off') === 'on',
                        'home_label' => $general['breadcrumbs_home_label'] ?? 'Home',
                        'separator'  => $general['breadcrumbs_separator'] ?? '»',
                        'prefix'     => (string) ($general['breadcrumbs_prefix'] ?? ''),
                    ],
                    // Webmaster-tools verification codes. Pinterest is applied
                    // (the one ThinkRank renders); the rest is preserved and
                    // gates /import/cleanup.
                    'webmaster_tools' => array_filter([
                        'google'    => (string) ($general['google_verify'] ?? ''),
                        'bing'      => (string) ($general['bing_verify'] ?? ''),
                        'yandex'    => (string) ($general['yandex_verify'] ?? ''),
                        'baidu'     => (string) ($general['baidu_verify'] ?? ''),
                        'pinterest' => (string) ($general['pinterest_verify'] ?? ''),
                    ]),
                    'local_seo' => [
                        'business_type'  => $titles['local_business_type'] ?? '',
                        'business_name'  => $titles['local_name'] ?? '',
                        'phone'          => $this->extract_rm_local_phone($titles),
                        'address'        => $this->extract_rm_local_address($titles),
                        'geo'            => $this->extract_rm_local_geo($titles),
                        'price_range'    => (string) ($titles['price_range'] ?? ''),
                        'opening_hours'  => $this->extract_rm_opening_hours($titles),
                    ],
                    'post_type_settings' => $this->extract_rm_post_type_settings($titles),
                    // Per-context title formats in ThinkRank's SITE IDENTITY token
                    // vocabulary (%site_title%/%post_title%/…), which is a different
                    // dialect from the Global SEO one used by post_type_settings.
                    'title_formats'      => $this->extract_rm_title_formats($titles),
                    // Author archive behaviour (Author Archives feature).
                    'author_archives'    => $this->extract_rm_author_archives($titles),
                    // Instant Indexing auto-submit post types (the API key travels
                    // in `data.instant_indexing`).
                    'instant_indexing_post_types' => $this->extract_rm_indexnow_post_types(),
                    // Past IndexNow submissions, so the Instant Indexing history
                    // is not blank after switching.
                    'instant_indexing_log'        => $this->extract_rm_indexnow_log(),
                    // News/Video sitemap post types (ThinkRank Pro Publisher Sitemaps).
                    'publisher_sitemaps'          => $this->extract_rm_publisher_sitemaps($sitemap),
                    // Role Manager: which roles hold which `rank_math_*`
                    // capabilities. These live on the roles themselves
                    // (wp_user_roles), not in any rank-math-options-* blob, so
                    // raw_options does not cover them.
                    'role_capabilities'  => $this->extract_role_capabilities('rank_math_'),
                    // Rank Math Pro's Search Console email report schedule.
                    'email_reports'      => [
                        'enabled'        => !empty($general['console_email_reports']),
                        'frequency_days' => $this->map_rm_email_frequency((string) ($general['console_email_frequency'] ?? '')),
                    ],
                    'image_seo' => [
                        'add_missing_alt'   => ($general['add_img_alt'] ?? 'off') === 'on',
                        'alt_format'        => $this->convert_image_tokens($general['img_alt_format'] ?? ''),
                        'add_missing_title' => ($general['add_img_title'] ?? 'off') === 'on',
                        'title_format'      => $this->convert_image_tokens($general['img_title_format'] ?? ''),
                    ],
                    'analytics_connected' => !empty($general['console_email']),
                    // ThinkRank's sitemap only models posts/pages/categories/tags,
                    // image inclusion, links-per-file and ping-search-engines; Rank
                    // Math's per-CPT / per-taxonomy toggles (and its authors/HTML
                    // sitemaps) have no equivalent and are intentionally not captured.
                    'sitemap_settings' => [
                        'include_posts'           => ($sitemap['pt_post_sitemap'] ?? 'off') === 'on',
                        'include_pages'           => ($sitemap['pt_page_sitemap'] ?? 'off') === 'on',
                        'include_categories'      => ($sitemap['tax_category_sitemap'] ?? 'off') === 'on',
                        'include_tags'            => ($sitemap['tax_post_tag_sitemap'] ?? 'off') === 'on',
                        'include_images'          => ($sitemap['include_images'] ?? 'off') === 'on',
                        'include_featured_images' => ($sitemap['include_featured_image'] ?? 'off') === 'on',
                        'links_per_sitemap'       => (int) ($sitemap['items_per_page'] ?? 1000),
                        // Rank Math defaults ping to 'on'; carry the user's choice so a
                        // disabled ping is not silently reset to ThinkRank's default (on).
                        'ping_search_engines'     => ($sitemap['ping_search_engines'] ?? 'on') === 'on',
                        // Rank Math's sitemap is ALWAYS an index (serves
                        // sitemap_index.xml; /sitemap.xml 301s to it) — there is no
                        // toggle to disable it. So migrating from Rank Math enables
                        // ThinkRank's sitemap index to match that structure.
                        'use_sitemap_index'       => true,
                        // Rank Math already stores both as comma-separated ID
                        // strings — ThinkRank's exclude format.
                        'exclude_posts'           => (string) ($sitemap['exclude_posts'] ?? ''),
                        'exclude_terms'           => (string) ($sitemap['exclude_terms'] ?? ''),
                        'has_data'                => !empty($sitemap),
                    ],
                    // FULL raw Rank Math option sets, redacted. The curated
                    // data/extended keys above only cover what ThinkRank can
                    // consume today; capturing everything means that when a
                    // matching feature ships (sitemaps detail, role manager,
                    // robots.txt, …) the data can be backfilled from the
                    // snapshot even after /import/cleanup deleted the source.
                    // The migrator ignores unknown extended keys, so this is
                    // inert until a mapping consumes it.
                    'raw_options' => $this->capture_raw_options(),
                ],
            ],
        ];
    }

    /**
     * Capture the full raw `rank-math-options-*` blobs into the snapshot,
     * passed through the sensitive-key redactor.
     *
     * @return array Map of option name => redacted option array
     */
    private function capture_raw_options(): array {
        $option_names = [
            'rank-math-options-general',
            'rank-math-options-titles',
            'rank-math-options-sitemap',
            'rank-math-options-instant-indexing',
        ];

        $raw = [];
        foreach ($option_names as $name) {
            $value = get_option($name, null);
            if (is_array($value) && !empty($value)) {
                $raw[$name] = $this->redact_sensitive_keys($value);
            }
        }

        return $raw;
    }

    /**
     * Recursively strip keys matching SENSITIVE_KEY_PATTERN from an option
     * array so tokens/credentials (console_authorization_code, console_email*,
     * analytics/API tokens, …) are never persisted into the snapshot.
     *
     * @param array $options Raw option array
     * @return array Redacted copy
     */
    private function redact_sensitive_keys(array $options): array {
        $clean = [];
        foreach ($options as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->redact_sensitive_keys($value) : $value;
        }

        return $clean;
    }

    /**
     * Normalize Rank Math's knowledgegraph_type to ThinkRank's entity vocabulary.
     *
     * Rank Math stores 'company' or 'person'; treat 'organization' as a company
     * alias defensively. Unknown/absent values return '' so the migrator skips.
     *
     * @param mixed $value Raw knowledgegraph_type value
     * @return string 'organization', 'person' or ''
     */
    private function normalize_knowledgegraph_type($value): string {
        $type = strtolower(trim((string) $value));
        if ($type === 'person') {
            return 'person';
        }
        if ($type === 'company' || $type === 'organization') {
            return 'organization';
        }

        return '';
    }

    /**
     * Read the IndexNow API key from Rank Math's Instant Indexing storage.
     *
     * The module (and the standalone Rank Math "Instant Indexing" plugin) has
     * stored the key under different option names/keys across versions, so
     * inspect the known candidates defensively and take the first non-empty.
     *
     * @return string API key, or '' when none configured
     */
    private function extract_rm_indexnow_key(): string {
        $option_names = ['rank-math-options-instant-indexing', 'rank_math_instant_indexing'];
        $key_candidates = ['indexnow_api_key', 'api_key', 'indexnow_key'];

        foreach ($option_names as $option_name) {
            $settings = get_option($option_name, []);
            if (!is_array($settings)) {
                continue;
            }
            foreach ($key_candidates as $key) {
                if (!empty($settings[$key]) && is_string($settings[$key])) {
                    return trim($settings[$key]);
                }
            }
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function export_redirections_page(int $page): array {
        // Rank Math stores redirections in its own table
        global $wpdb;

        $table_name = $wpdb->prefix . 'rank_math_redirections';
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );

        if (!$table_exists) {
            return [];
        }

        $offset = ($page - 1) * $this->chunk_size;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY id ASC LIMIT %d OFFSET %d",
                $this->chunk_size,
                $offset
            ),
            ARRAY_A
        );

        // One rule can fan out into several records, so pagination must key off
        // the number of ROWS fetched, not the number of records emitted.
        $this->last_page_row_count = is_array($rows) ? count($rows) : 0;

        if (empty($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            // Rank Math stores `sources` as a serialized list of
            // ['pattern' => …, 'comparison' => exact|contains|start|end|regex]
            // rows — ONE rule can match many source URLs. ThinkRank's redirect
            // table is one row per source, so fan each source out into its own
            // record rather than keeping only the first (which silently dropped
            // every additional source).
            $sources = maybe_unserialize($row['sources'] ?? '');
            if (!is_array($sources) || empty($sources)) {
                continue;
            }

            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }

                $pattern = trim((string) ($source['pattern'] ?? ''));
                if ($pattern === '') {
                    continue;
                }

                $comparison = strtolower((string) ($source['comparison'] ?? 'exact'));

                $records[] = [
                    'object_type'   => 'redirection',
                    'source_plugin' => $this->plugin_slug,
                    'data'          => [],
                    'extended'      => [
                        'source_url'    => $pattern,
                        'target_url'    => $row['url_to'] ?? '',
                        'http_code'     => (int) ($row['header_code'] ?? 301),
                        'match_type'    => $this->map_rm_match_type($comparison),
                        // Retained for readers that predate `match_type`.
                        'is_regex'      => $comparison === 'regex',
                        'enabled'       => ($row['status'] ?? 'active') === 'active',
                        'hits'          => (int) ($row['hits'] ?? 0),
                        'created_at'    => $this->normalize_rm_datetime($row['created'] ?? ''),
                        'last_accessed' => $this->normalize_rm_datetime($row['last_accessed'] ?? ''),
                    ],
                ];
            }
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     *
     * Rank Math's 404 monitor keeps one row per URI in `rank_math_404_logs`,
     * which lines up with ThinkRank Pro's `thinkrank_404_logs`.
     */
    protected function export_404_logs_page(int $page): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rank_math_404_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if (!$table_exists) {
            return [];
        }

        $offset = ($page - 1) * $this->chunk_size;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY id ASC LIMIT %d OFFSET %d",
                $this->chunk_size,
                $offset
            ),
            ARRAY_A
        );

        $this->last_page_row_count = is_array($rows) ? count($rows) : 0;

        if (empty($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            $uri = trim((string) ($row['uri'] ?? ''));
            if ($uri === '') {
                continue;
            }

            $records[] = [
                'object_type'   => '404_log',
                'source_plugin' => $this->plugin_slug,
                'data'          => [],
                'extended'      => [
                    'uri'            => $uri,
                    'times_accessed' => max(1, (int) ($row['times_accessed'] ?? 1)),
                    'referer'        => (string) ($row['referer'] ?? ''),
                    'user_agent'     => (string) ($row['user_agent'] ?? ''),
                    'last_accessed'  => $this->normalize_rm_datetime($row['accessed'] ?? ''),
                ],
            ];
        }

        return $records;
    }

    /**
     * Map a Rank Math redirection `comparison` onto ThinkRank Pro's match_type.
     *
     * Rank Math's vocabulary is exact|contains|start|end|regex; ThinkRank Pro
     * uses the same five, so this mostly normalizes and guards unknown values
     * (Rank Math also had a legacy 'exact' alias set).
     *
     * @param string $comparison Rank Math comparison slug
     * @return string ThinkRank match type
     */
    private function map_rm_match_type(string $comparison): string {
        $supported = ['exact', 'contains', 'start', 'end', 'regex'];

        return in_array($comparison, $supported, true) ? $comparison : 'exact';
    }

    /**
     * Normalize a Rank Math datetime column into a MySQL datetime string.
     *
     * Rank Math's tables default these columns to the zero date
     * ('0000-00-00 00:00:00'), which MySQL rejects on insert under strict mode.
     *
     * @param mixed $value Raw column value
     * @return string Valid `Y-m-d H:i:s` string, or '' when unusable
     */
    private function normalize_rm_datetime($value): string {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    /**
     * {@inheritDoc}
     */
    /**
     * Map a Rank Math rich-snippet slug to ThinkRank's schema-type vocabulary.
     * Unknown slugs and the 'off'/'none' sentinels return '' (no schema), which
     * the migrator skips like any empty value.
     *
     * @param mixed $rm_value Raw rank_math_rich_snippet value
     * @return string ThinkRank schema type, or '' when unmapped/disabled
     */
    private function map_schema_type($rm_value): string {
        $key = strtolower(trim((string) $rm_value));
        if ($key === '' || $key === 'off' || $key === 'none') {
            return '';
        }

        return self::SCHEMA_TYPE_MAP[$key] ?? '';
    }

    /**
     * Resolve a post's ThinkRank schema type from Rank Math meta.
     *
     * Prefers the legacy `rank_math_rich_snippet` slug when present, then falls
     * back to Rank Math's modern per-post schema storage (`rank_math_schema_*`),
     * which is what current Rank Math versions actually write. Returns '' when no
     * supported schema is found (the migrator skips empty schema types).
     *
     * @param array $meta All Rank Math meta for a post
     * @return string ThinkRank schema type, or ''
     */
    private function resolve_schema_type(array $meta): string {
        $legacy = $this->map_schema_type($meta['rank_math_rich_snippet'] ?? '');
        if ($legacy !== '') {
            return $legacy;
        }

        return $this->detect_modern_schema_type($meta);
    }

    /**
     * Derive a ThinkRank schema type from Rank Math's modern `rank_math_schema_*`
     * meta blocks.
     *
     * A post may carry several schema blocks (e.g. BlogPosting + VideoObject);
     * the one flagged `metadata.isPrimary` is preferred. To avoid discarding a
     * usable type when the primary block is one ThinkRank does not model (e.g. a
     * primary VideoObject alongside a secondary Article), the first block — in
     * primary-then-rest order — that maps to a supported type wins.
     *
     * @param array $meta All Rank Math meta for a post
     * @return string ThinkRank schema type, or ''
     */
    private function detect_modern_schema_type(array $meta): string {
        $primary = [];
        $others = [];

        foreach ($meta as $key => $value) {
            if (strpos($key, 'rank_math_schema_') !== 0) {
                continue;
            }

            $schema = maybe_unserialize($value);
            if (!is_array($schema)) {
                continue;
            }

            // The @type is stored at the block root for most types; older Article
            // blocks omit it and carry the type only in the meta key suffix.
            $type_name = (string) ($schema['@type'] ?? substr($key, strlen('rank_math_schema_')));

            // metadata.isPrimary is '1'/true for the primary block, '0'/false (or
            // absent) otherwise. empty() treats '0', '', false and 0 as not-primary.
            if (!empty($schema['metadata']['isPrimary'])) {
                $primary[] = $type_name;
            } else {
                $others[] = $type_name;
            }
        }

        foreach (array_merge($primary, $others) as $type_name) {
            $mapped = $this->map_modern_schema_type($type_name);
            if ($mapped !== '') {
                return $mapped;
            }
        }

        return '';
    }

    /**
     * Map a Rank Math modern schema @type (PascalCase) to ThinkRank's vocabulary.
     *
     * @param string $type_name Rank Math schema @type
     * @return string ThinkRank schema type, or '' when unmapped/unsupported
     */
    private function map_modern_schema_type(string $type_name): string {
        $key = strtolower(trim($type_name));
        if ($key === '') {
            return '';
        }

        return self::MODERN_SCHEMA_TYPE_MAP[$key] ?? '';
    }

    /**
     * Resolve a Rank Math TERM title/description value, replacing the
     * term-context tokens (%term%, %term_description%) Rank Math uses for term
     * archives before delegating to the shared variable resolver. Without this
     * the term name is stripped and titles render as "Archives  - Site".
     *
     * @param mixed $value   Raw Rank Math term meta value
     * @param int   $term_id Term ID for context
     * @return string Resolved value
     */
    private function convert_term_template_variables(mixed $value, int $term_id): string {
        // Same foreign-data rule as convert_template_variables (see abstract).
        $value = $this->stringify_template_value($value);
        if ($value === '' || strpos($value, '%') === false) {
            return $value;
        }

        $term = get_term($term_id);
        if ($term instanceof \WP_Term) {
            $value = str_replace(
                ['%term%', '%term_description%'],
                [$term->name, wp_strip_all_tags((string) term_description($term_id))],
                $value
            );
        }

        // Collapse whitespace left where a token (e.g. %page%) resolved to ''.
        return trim((string) preg_replace('/\s{2,}/', ' ', $this->convert_template_variables($value)));
    }

    protected function convert_template_variables(mixed $value, ?int $post_id = null): string {
        // Foreign data first: booleans/arrays in the source plugin's options
        // must degrade to '' here, not fatal the migration (see abstract).
        $value = $this->stringify_template_value($value);

        if (empty($value) || strpos($value, '%') === false) {
            return $value;
        }

        $replacements = [
            '%sitename%'    => get_bloginfo('name'),
            '%sitedesc%'    => get_bloginfo('description'),
            '%sep%'         => '-',
            '%page%'        => '',
            '%currentyear%' => gmdate('Y'),
            '%currentdate%' => gmdate('Y-m-d'),
            '%currentmonth%' => gmdate('F'),
            '%currentday%'  => gmdate('j'),
        ];

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $replacements['%title%']    = $post->post_title;
                $replacements['%excerpt%']  = wp_trim_words($post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55), 55);
                $replacements['%date%']     = get_the_date('', $post);
                $replacements['%modified%'] = get_the_modified_date('', $post);
                $replacements['%id%']       = (string) $post_id;
                $replacements['%name%']     = get_the_author_meta('display_name', (int) $post->post_author);

                $post_type_obj = get_post_type_object($post->post_type);
                $replacements['%pt_single%'] = $post_type_obj ? $post_type_obj->labels->singular_name : '';
                $replacements['%pt_plural%'] = $post_type_obj ? $post_type_obj->labels->name : '';

                $categories = get_the_category($post_id);
                $replacements['%category%'] = !empty($categories) ? $categories[0]->name : '';
                $replacements['%categories%'] = !empty($categories) ? implode(', ', wp_list_pluck($categories, 'name')) : '';

                $tags = get_the_tags($post_id);
                $replacements['%tag%']  = !empty($tags) ? $tags[0]->name : '';
                $replacements['%tags%'] = !empty($tags) ? implode(', ', wp_list_pluck($tags, 'name')) : '';
            }
        }

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Strip remaining unknown %variable% patterns (single percent)
        // Be careful not to strip legitimate percent signs
        $value = preg_replace('/%[a-z0-9_]+%/i', '', $value);

        return trim($value);
    }

    /**
     * Convert a Rank Math title/description TEMPLATE into ThinkRank's Global SEO
     * token vocabulary, preserving structural tokens (do NOT resolve to literal
     * values — these templates apply to every post of the type).
     *
     * ThinkRank's Global SEO engine understands: %title%, %sitename%, %sep%,
     * %excerpt%, %date%, %modified%, %author%, %category%. Rank Math tokens with
     * a direct equivalent are renamed; tokens ThinkRank cannot resolve (e.g.
     * %page%, %pt_single%, %currentyear%) are stripped so they never render
     * literally on the frontend.
     *
     * @param mixed $template Raw Rank Math template
     * @return string ThinkRank-compatible template
     */
    private function convert_template_tokens(mixed $template): string {
        // Foreign data first: booleans/arrays in the source plugin's options
        // must degrade to '' here, not fatal the migration (see abstract).
        $template = $this->stringify_template_value($template);

        if (empty($template) || strpos($template, '%') === false) {
            return $template;
        }

        // Rank Math token => ThinkRank Global SEO token (structure preserved).
        $token_map = [
            '%name%' => '%author%', // Rank Math author display name token
        ];
        $template = str_replace(array_keys($token_map), array_values($token_map), $template);

        // Tokens ThinkRank's Global SEO engine resolves natively — keep as-is.
        $supported = ['%title%', '%sitename%', '%sep%', '%excerpt%', '%date%', '%modified%', '%author%', '%category%'];

        // Strip any token ThinkRank cannot resolve so it does not render literally.
        $template = preg_replace_callback(
            '/%[a-z0-9_]+%/i',
            static function (array $m) use ($supported): string {
                return in_array(strtolower($m[0]), $supported, true) ? $m[0] : '';
            },
            $template
        );

        // Collapse whitespace left by stripped tokens (e.g. "%title% %page% %sep%").
        $template = preg_replace('/\s{2,}/', ' ', (string) $template);

        return trim((string) $template);
    }

    /**
     * Convert a Rank Math image alt/title FORMAT into ThinkRank's Image SEO token
     * vocabulary, preserving structure.
     *
     * ThinkRank's Image SEO engine resolves: %title%, %sitename%, %site_title%,
     * %sep%, %separator%, %count%, %filename%, %image_title%, %image_caption%.
     * Rank Math's counter tokens %count(alt)% / %count(title)% become %count%;
     * tokens with no equivalent are stripped so they never render literally.
     *
     * @param string $format Raw Rank Math image format
     * @return string ThinkRank-compatible image format
     */
    private function convert_image_tokens(mixed $format): string {
        // Foreign data first: booleans/arrays in the source plugin's options
        // must degrade to '' here, not fatal the migration (see abstract).
        $format = $this->stringify_template_value($format);

        if ($format === '' || strpos($format, '%') === false) {
            return $format;
        }

        // Rank Math counter tokens carry a parenthesised argument, e.g. %count(alt)%.
        $format = preg_replace('/%count\([a-z]+\)%/i', '%count%', $format);
        $format = str_replace('%name%', '', (string) $format);

        $supported = ['%title%', '%sitename%', '%site_title%', '%sep%', '%separator%', '%count%', '%filename%', '%image_title%', '%image_caption%'];
        $format = preg_replace_callback(
            '/%[a-z0-9_]+%/i',
            static function (array $m) use ($supported): string {
                return in_array(strtolower($m[0]), $supported, true) ? $m[0] : '';
            },
            (string) $format
        );

        $format = preg_replace('/\s{2,}/', ' ', (string) $format);

        return trim((string) $format);
    }

    /**
     * Extract schema details from Rank Math meta
     *
     * @param array $meta All Rank Math meta for a post
     * @return array Schema details
     */
    private function extract_schema_details(array $meta): array {
        $details = [];

        foreach ($meta as $key => $value) {
            if (strpos($key, 'rank_math_schema_') === 0) {
                $schema_key = str_replace('rank_math_schema_', '', $key);
                $details[$schema_key] = maybe_unserialize($value);
            }
        }

        return $details;
    }

    /**
     * Extract Rank Math's review rich-snippet rating fields into ThinkRank's
     * Review schema-form vocabulary. Returned keys match the `review_*` fields
     * the schema builder reads; empty values are omitted so the migrator only
     * writes meaningful data. Returns [] when the post is not a review snippet.
     *
     * @param array $meta All Rank Math meta for a post
     * @return array Review form data (review_rating_value, review_best_rating, ...)
     */
    private function extract_review_schema(array $meta): array {
        if (($meta['rank_math_rich_snippet'] ?? '') !== 'review') {
            return [];
        }

        $map = [
            'rank_math_snippet_name'                => 'review_item_name',
            'rank_math_snippet_desc'                => 'review_body',
            'rank_math_snippet_review_rating_value' => 'review_rating_value',
            'rank_math_snippet_review_best_rating'  => 'review_best_rating',
            'rank_math_snippet_review_worst_rating' => 'review_worst_rating',
        ];

        $review = [];
        foreach ($map as $rm_key => $tr_key) {
            $value = $meta[$rm_key] ?? '';
            if ($value !== '' && $value !== null) {
                $review[$tr_key] = $value;
            }
        }

        return $review;
    }

    /**
     * Extract Rank Math's modern VideoObject schema block into ThinkRank's
     * `video_*` schema-form vocabulary. Picks the primary VideoObject block (or
     * the first one), resolves text tokens, and drops any value still carrying an
     * unresolved Rank Math token (e.g. `%post_thumbnail%`) so the schema builder
     * falls back to the post's own data. Returns [] when no VideoObject block
     * exists or nothing meaningful survives.
     *
     * @param array $meta    All Rank Math meta for a post
     * @param int   $post_id Post ID for token resolution
     * @return array Video form data (video_name, video_embed_url, ...)
     */
    private function extract_video_schema(array $meta, int $post_id): array {
        $block = null;
        foreach ($meta as $key => $value) {
            if (strpos($key, 'rank_math_schema_') !== 0) {
                continue;
            }
            $schema = maybe_unserialize($value);
            if (!is_array($schema)) {
                continue;
            }
            $type = strtolower((string) ($schema['@type'] ?? substr($key, strlen('rank_math_schema_'))));
            if ($type !== 'videoobject') {
                continue;
            }
            // Prefer the primary block; otherwise keep the first one seen.
            if (!empty($schema['metadata']['isPrimary'])) {
                $block = $schema;
                break;
            }
            $block = $block ?? $schema;
        }

        if (!is_array($block)) {
            return [];
        }

        // Block field => ThinkRank form field. Text fields are run through the
        // template-variable resolver; URL/date fields are taken verbatim.
        $text_fields = [
            'name'        => 'video_name',
            'description' => 'video_description',
        ];
        $raw_fields = [
            'contentUrl'   => 'video_content_url',
            'embedUrl'     => 'video_embed_url',
            'duration'     => 'video_duration',
            'uploadDate'   => 'video_upload_date',
            'thumbnailUrl' => 'video_thumbnail',
        ];

        $video = [];

        foreach ($text_fields as $block_key => $tr_key) {
            $value = $this->convert_template_variables((string) ($block[$block_key] ?? ''), $post_id);
            if ($value !== '' && strpos($value, '%') === false) {
                $video[$tr_key] = $value;
            }
        }

        foreach ($raw_fields as $block_key => $tr_key) {
            $value = (string) ($block[$block_key] ?? '');
            // Drop unresolved tokens (e.g. %post_thumbnail%, %date(...)%) so the
            // builder's own fallback (featured image, publish date) applies.
            if ($value !== '' && strpos($value, '%') === false) {
                $video[$tr_key] = $value;
            }
        }

        return $video;
    }

    /**
     * Extract the boolean robots flags that Rank Math stores inside the
     * `rank_math_robots` indexed array (alongside index/noindex/nofollow).
     *
     * @param mixed $robots_raw Raw (serialized) rank_math_robots value
     * @return array Map of present flag => true (noarchive/noimageindex/nosnippet)
     */
    private function extract_robots_flags($robots_raw): array {
        $robots = maybe_unserialize($robots_raw);
        if (!is_array($robots)) {
            return [];
        }

        $flags = [];
        foreach (['noarchive', 'noimageindex', 'nosnippet'] as $flag) {
            if (in_array($flag, $robots, true)) {
                $flags[$flag] = true;
            }
        }

        return $flags;
    }

    /**
     * Normalize Rank Math's pillar/cornerstone content flag to 0 or 1.
     *
     * Rank Math stores the enabled flag as the string 'on' (its checkbox value).
     * A plain (int) cast of 'on' yields 0, which silently drops the flag during
     * migration — so it must be matched against Rank Math's truthy representations.
     *
     * @param mixed $value Raw rank_math_pillar_content meta value
     * @return int 0 or 1
     */
    private function normalize_pillar_content($value): int {
        return in_array($value, ['on', '1', 1, true], true) ? 1 : 0;
    }

    /**
     * Decode the `rank_math_advanced_robots` post meta.
     *
     * Rank Math stores it as an associative array keyed by directive, where the
     * value is the configured length/value or `false` when the directive is
     * disabled, e.g. ['max-snippet' => '120', 'max-video-preview' => false,
     * 'max-image-preview' => 'large'].
     *
     * @param mixed $raw Raw (serialized) meta value
     * @return array Associative directive => value map (empty when unset)
     */
    private function parse_advanced_robots_meta($raw): array {
        $advanced = maybe_unserialize($raw);
        return is_array($advanced) ? $advanced : [];
    }

    /**
     * Read an integer advanced-robots directive (max-snippet, max-video-preview).
     *
     * Returns an empty-string sentinel when the directive is unset or disabled
     * so the migrator skips it rather than forcing a 0 value (which would read
     * as "no snippet").
     *
     * @param array  $advanced Decoded rank_math_advanced_robots map
     * @param string $key      Directive key
     * @return int|string Integer value, or '' when unset/disabled
     */
    private function advanced_robot_int(array $advanced, string $key) {
        if (!isset($advanced[$key]) || $advanced[$key] === false || $advanced[$key] === '') {
            return '';
        }
        return (int) $advanced[$key];
    }

    /**
     * Read a string advanced-robots directive (max-image-preview).
     *
     * @param array  $advanced Decoded rank_math_advanced_robots map
     * @param string $key      Directive key
     * @return string Directive value, or '' when unset/disabled
     */
    private function advanced_robot_string(array $advanced, string $key): string {
        if (!isset($advanced[$key]) || $advanced[$key] === false) {
            return '';
        }
        return (string) $advanced[$key];
    }

    /**
     * Extract the local business phone from Rank Math titles.
     *
     * Rank Math stores local phones under `phone_numbers` (an array of
     * ['type' => ..., 'number' => ...]); older/knowledge-graph setups use a
     * flat `phone`. Prefer the first structured number, fall back to `phone`.
     *
     * @param array $titles Rank Math titles option
     * @return string
     */
    private function extract_rm_local_phone(array $titles): string {
        $numbers = $titles['phone_numbers'] ?? [];
        if (is_array($numbers)) {
            foreach ($numbers as $entry) {
                if (is_array($entry) && !empty($entry['number'])) {
                    return (string) $entry['number'];
                }
            }
        }

        return (string) ($titles['phone'] ?? '');
    }

    /**
     * Convert Rank Math opening hours into ThinkRank's Business Info format.
     *
     * Rank Math stores `opening_hours` as a group of ['day' => 'Monday',
     * 'time' => '09:00-17:00'] rows (24h H:i). ThinkRank stores hours keyed by
     * lowercase day: ['monday' => ['open' => 'HH:MM', 'close' => 'HH:MM',
     * 'closed' => bool]]. ThinkRank supports a single range per day, so the
     * first parseable row for each day wins (Rank Math's optional mid-day-break
     * second row is dropped). Days with no configured row are omitted.
     *
     * @param array $titles Rank Math titles option
     * @return array
     */
    private function extract_rm_opening_hours(array $titles): array {
        $hours = $titles['opening_hours'] ?? [];
        if (!is_array($hours) || empty($hours)) {
            return [];
        }

        $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $result = [];

        foreach ($hours as $entry) {
            if (!is_array($entry) || empty($entry['day']) || empty($entry['time'])) {
                continue;
            }

            $day = strtolower((string) $entry['day']);
            if (!in_array($day, $valid_days, true) || isset($result[$day])) {
                continue;
            }

            // Parse "HH:MM-HH:MM" (allow single-digit hours and en dash).
            if (!preg_match('/^\s*(\d{1,2}:\d{2})\s*[-\x{2013}]\s*(\d{1,2}:\d{2})\s*$/u', (string) $entry['time'], $m)) {
                continue;
            }

            $result[$day] = [
                'open'   => $this->pad_time_hh_mm($m[1]),
                'close'  => $this->pad_time_hh_mm($m[2]),
                'closed' => false,
            ];
        }

        return $result;
    }

    /**
     * Zero-pad the hour component of an "H:MM" time to "HH:MM".
     *
     * @param string $time Time string like "9:00" or "09:00"
     * @return string
     */
    private function pad_time_hh_mm(string $time): string {
        $parts = explode(':', $time);
        if (count($parts) !== 2) {
            return $time;
        }

        return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . $parts[1];
    }

    /**
     * Extract the local business postal address from Rank Math titles.
     *
     * Rank Math's `local_address` is a schema.org PostalAddress array
     * (streetAddress/addressLocality/addressRegion/postalCode/addressCountry).
     * Returns a normalized array keyed for ThinkRank's Business Info fields, or
     * an empty array when nothing is set.
     *
     * @param array $titles Rank Math titles option
     * @return array
     */
    private function extract_rm_local_address(array $titles): array {
        $address = $titles['local_address'] ?? [];
        if (!is_array($address) || empty($address)) {
            return [];
        }

        $map = [
            'street'      => 'streetAddress',
            'city'        => 'addressLocality',
            'state'       => 'addressRegion',
            'postal_code' => 'postalCode',
            'country'     => 'addressCountry',
        ];

        $result = [];
        foreach ($map as $target => $rm_key) {
            if (!empty($address[$rm_key])) {
                $result[$target] = (string) $address[$rm_key];
            }
        }

        return $result;
    }

    /**
     * Extract geo coordinates from Rank Math titles.
     *
     * Rank Math's `geo` is a single "latitude,longitude" string. Returns
     * ['latitude' => ..., 'longitude' => ...] when both parse, else [].
     *
     * @param array $titles Rank Math titles option
     * @return array
     */
    private function extract_rm_local_geo(array $titles): array {
        $geo = trim((string) ($titles['geo'] ?? ''));
        if ($geo === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $geo);
        if (!is_array($parts) || !isset($parts[0], $parts[1]) || $parts[0] === '' || $parts[1] === '') {
            return [];
        }

        if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return [];
        }

        return [
            'latitude'  => (string) $parts[0],
            'longitude' => (string) $parts[1],
        ];
    }

    /**
     * Map Rank Math's per-context title formats onto ThinkRank's Site Identity
     * title-format keys (homepage_title, post_title, page_title, category_title,
     * tag_title, search_title, archive_title).
     *
     * These are a DIFFERENT token dialect from `post_type_settings` above: the
     * Site Identity renderer resolves %site_title%/%post_title%/%category_title%/
     * %tag_title%/%search_term%/%archive_title%/%sep%, whereas the Global SEO
     * renderer resolves %title%/%sitename%/%excerpt%. Converting with the wrong
     * dialect renders the token literally, so each context is converted with the
     * matching context token.
     *
     * @param array $titles Rank Math titles option
     * @return array Map of ThinkRank title-format key => converted template
     */
    private function extract_rm_title_formats(array $titles): array {
        // ThinkRank key => [Rank Math key, the %…% token Rank Math's %title%/%term%
        // stands for in that context].
        $map = [
            'homepage_title' => ['homepage_title', ''],
            'post_title'     => ['pt_post_title', '%post_title%'],
            'page_title'     => ['pt_page_title', '%page_title%'],
            'category_title' => ['tax_category_title', '%category_title%'],
            'tag_title'      => ['tax_post_tag_title', '%tag_title%'],
            'search_title'   => ['search_title', '%search_term%'],
            'archive_title'  => ['date_archive_title', '%archive_title%'],
        ];

        $formats = [];
        foreach ($map as $tr_key => [$rm_key, $context_token]) {
            $raw = (string) ($titles[$rm_key] ?? '');
            if ($raw === '') {
                continue;
            }

            $converted = $this->convert_identity_tokens($raw, $context_token);
            if ($converted !== '') {
                $formats[$tr_key] = $converted;
            }
        }

        return $formats;
    }

    /**
     * Convert a Rank Math title template into ThinkRank's Site Identity token
     * vocabulary, preserving structure.
     *
     * The Site Identity renderer resolves: %site_title%, %site_description%,
     * %tagline%, %sep%/%separator%, %date%, plus the per-context tokens
     * %post_title%, %page_title%, %category_title%, %tag_title%, %search_term%,
     * %archive_title%, %author_name%. Rank Math's context-neutral %title% /
     * %term% become the caller-supplied $context_token; anything ThinkRank
     * cannot resolve is stripped so it never renders literally.
     *
     * @param string $template      Raw Rank Math template
     * @param string $context_token Token %title%/%term% stands for here (may be '')
     * @return string ThinkRank Site Identity template
     */
    private function convert_identity_tokens(string $template, string $context_token): string {
        if ($template === '' || strpos($template, '%') === false) {
            return trim($template);
        }

        $token_map = [
            '%sitename%'    => '%site_title%',
            '%sitedesc%'    => '%site_description%',
            '%name%'        => '%author_name%',
            '%search_query%' => '%search_term%',
        ];
        if ($context_token !== '') {
            $token_map['%title%'] = $context_token;
            $token_map['%term%']  = $context_token;
        }
        $template = str_replace(array_keys($token_map), array_values($token_map), $template);

        $supported = [
            '%site_title%', '%site_description%', '%tagline%', '%sep%', '%separator%',
            '%date%', '%post_title%', '%page_title%', '%category_title%', '%tag_title%',
            '%search_term%', '%archive_title%', '%author_name%',
        ];

        $template = preg_replace_callback(
            '/%[a-z0-9_]+%/i',
            static function (array $m) use ($supported): string {
                return in_array(strtolower($m[0]), $supported, true) ? $m[0] : '';
            },
            $template
        );

        // Collapse whitespace left by stripped tokens, then drop a separator that
        // ended up leading/trailing because the token beside it was removed.
        $template = preg_replace('/\s{2,}/', ' ', (string) $template);
        $template = trim((string) $template);
        $template = preg_replace('/^(?:%sep%|%separator%)\s*/', '', $template);
        $template = preg_replace('/\s*(?:%sep%|%separator%)$/', '', (string) $template);

        return trim((string) $template);
    }

    /**
     * Extract Rank Math's author-archive behaviour for ThinkRank's Author
     * Archives feature (author_archives_enabled / _title / _meta_desc).
     *
     * @param array $titles Rank Math titles option
     * @return array Author archive settings
     */
    private function extract_rm_author_archives(array $titles): array {
        return [
            // Rank Math DISABLES archives with this flag; ThinkRank stores the
            // positive `enabled`, so invert.
            'enabled'     => ($titles['disable_author_archives'] ?? 'off') !== 'on',
            'title'       => $this->convert_identity_tokens((string) ($titles['author_archive_title'] ?? ''), '%author_name%'),
            'description' => $this->convert_identity_tokens((string) ($titles['author_archive_description'] ?? ''), '%author_name%'),
        ];
    }

    /**
     * Read the post types Rank Math's Instant Indexing module auto-submits.
     *
     * @return array List of post type slugs (empty when unconfigured)
     */
    private function extract_rm_indexnow_post_types(): array {
        foreach (['rank-math-options-instant-indexing', 'rank_math_instant_indexing'] as $option_name) {
            $settings = get_option($option_name, []);
            if (!is_array($settings)) {
                continue;
            }
            foreach (['bing_post_types', 'indexnow_post_types', 'post_types'] as $key) {
                if (!empty($settings[$key]) && is_array($settings[$key])) {
                    return array_values(array_map('strval', $settings[$key]));
                }
            }
        }

        return [];
    }

    /**
     * Capture Rank Math's IndexNow submission history (`rank_math_indexnow_log`,
     * a list of ['url', 'status', 'message', 'time', 'manual_submission']).
     *
     * Rank Math trims this option itself, but cap it defensively so one site's
     * runaway log cannot bloat the settings chunk — and report the drop rather
     * than truncating silently.
     *
     * @return array{entries: array[], truncated: int}
     */
    private function extract_rm_indexnow_log(): array {
        $log = get_option('rank_math_indexnow_log', []);
        if (!is_array($log) || empty($log)) {
            return ['entries' => [], 'truncated' => 0];
        }

        // Newest last in Rank Math's log; keep the most recent when capping.
        $truncated = max(0, count($log) - self::MAX_INDEXNOW_LOG_ENTRIES);
        if ($truncated > 0) {
            $log = array_slice($log, -self::MAX_INDEXNOW_LOG_ENTRIES);
        }

        $entries = [];
        foreach ($log as $row) {
            if (!is_array($row) || empty($row['url'])) {
                continue;
            }

            $code = (int) ($row['status'] ?? 0);

            $entries[] = [
                'url'              => (string) $row['url'],
                // ThinkRank stores a success/failed verdict alongside the raw code.
                'status'           => ($code >= 200 && $code < 300) ? 'success' : 'failed',
                'response_code'    => $code,
                'response_message' => (string) ($row['message'] ?? ''),
                'submitted_at'     => !empty($row['time'])
                    ? gmdate('Y-m-d H:i:s', (int) $row['time'])
                    : '',
            ];
        }

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * Capture the post types Rank Math builds its News / Video sitemaps from,
     * for ThinkRank Pro's Publisher Sitemaps.
     *
     * @param array $sitemap Rank Math sitemap option
     * @return array News/Video post type lists (absent keys omitted)
     */
    private function extract_rm_publisher_sitemaps(array $sitemap): array {
        $out = [];

        foreach (['video_sitemap_post_type' => 'video_post_types', 'news_sitemap_post_type' => 'news_post_types'] as $rm_key => $tr_key) {
            if (empty($sitemap[$rm_key]) || !is_array($sitemap[$rm_key])) {
                continue;
            }
            $out[$tr_key] = array_values(array_map('strval', $sitemap[$rm_key]));
        }

        return $out;
    }

    /**
     * Map Rank Math's email-report cadence onto ThinkRank's `frequency_days`.
     *
     * @param string $frequency Rank Math frequency slug
     * @return int Days between reports (ThinkRank default 30 when unknown)
     */
    private function map_rm_email_frequency(string $frequency): int {
        switch (strtolower(trim($frequency))) {
            case 'daily':
                return 1;
            case 'weekly':
                return 7;
            case 'monthly':
                return 30;
            default:
                return 30;
        }
    }

    /**
     * Extract post type settings from Rank Math titles options
     *
     * @param array $titles Rank Math titles option
     * @return array Post type settings
     */
    private function extract_rm_post_type_settings(array $titles): array {
        $settings = [];
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $pt) {
            $pt_settings = [];
            if (isset($titles["pt_{$pt}_title"])) {
                $pt_settings['title_template'] = $this->convert_template_tokens($titles["pt_{$pt}_title"]);
            }
            if (isset($titles["pt_{$pt}_description"])) {
                $pt_settings['description_template'] = $this->convert_template_tokens($titles["pt_{$pt}_description"]);
            }
            if (isset($titles["pt_{$pt}_robots"])) {
                $pt_settings['robots'] = maybe_unserialize($titles["pt_{$pt}_robots"]);
            }
            // Rank Math's per-type Link Suggestions toggle maps onto ThinkRank's
            // global-SEO `link_suggestions` (which gates the Pillar Content column
            // and post-list filter). Only captured when Rank Math stored a value.
            if (isset($titles["pt_{$pt}_link_suggestions"])) {
                $pt_settings['link_suggestions'] = $titles["pt_{$pt}_link_suggestions"] === 'on';
            }
            // Rank Math only applies per-post-type robots when "custom robots" is
            // enabled for that type; capture the flag so migration does not force
            // robots that Rank Math was ignoring.
            $pt_settings['custom_robots'] = ($titles["pt_{$pt}_custom_robots"] ?? 'off') === 'on';
            // `link_suggestions` is a boolean, so array_key_exists — not !empty —
            // decides whether the type is worth emitting (a deliberate "off" is
            // exactly the value worth carrying over).
            if (!empty($pt_settings['title_template']) || !empty($pt_settings['description_template'])
                || !empty($pt_settings['robots']) || array_key_exists('link_suggestions', $pt_settings)) {
                $settings[$pt] = $pt_settings;
            }
        }

        return $settings;
    }
}
