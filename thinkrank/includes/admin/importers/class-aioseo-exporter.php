<?php

/**
 * All in One SEO Exporter
 *
 * Reads AIOSEO data from the custom {prefix}aioseo_posts table (NOT postmeta).
 * Must DESCRIBE table before querying for version safety.
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
 * AIOSEO Exporter Class
 *
 * @since 2.0.0
 */
class AIOSEO_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Cached table columns from DESCRIBE
     *
     * @var array|null
     */
    private ?array $table_columns = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = 'aioseo';
        $this->plugin_name = 'All in One SEO';
        $this->plugin_file = 'all-in-one-seo-pack/all_in_one_seo_pack.php';
        $this->meta_key_prefix = '';
        $this->option_keys = ['aioseo_options'];
    }

    /**
     * Get the AIOSEO posts table name
     *
     * @return string
     */
    private function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'aioseo_posts';
    }

    /**
     * Get the AIOSEO redirects table name
     *
     * @return string
     */
    private function get_redirects_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'aioseo_redirects';
    }

    /**
     * Check if the AIOSEO posts table exists
     *
     * @return bool
     */
    private function table_exists(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $this->get_table_name())
        );
    }

    /**
     * Get table column names via DESCRIBE for version safety
     *
     * @return array Array of column names
     */
    private function get_table_columns(): array {
        if ($this->table_columns !== null) {
            return $this->table_columns;
        }

        global $wpdb;

        if (!$this->table_exists()) {
            $this->table_columns = [];
            return $this->table_columns;
        }

        $columns = $wpdb->get_col("DESCRIBE {$this->get_table_name()}", 0);
        $this->table_columns = is_array($columns) ? $columns : [];

        return $this->table_columns;
    }

    /**
     * Check if a column exists in the AIOSEO table
     *
     * @param string $column Column name
     * @return bool
     */
    private function has_column(string $column): bool {
        return in_array($column, $this->get_table_columns(), true);
    }

    /**
     * {@inheritDoc}
     */
    public function detect(): bool {
        if (!$this->table_exists()) {
            return false;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->get_table_name()}");

        return $count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function get_available_types(): array {
        global $wpdb;

        $types = [];

        if ($this->table_exists()) {
            $post_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->get_table_name()}");
            if ($post_count > 0) {
                $types['postmeta'] = $post_count;
            }
        }

        // AIOSEO Pro term SEO table.
        $terms_table = $wpdb->prefix . 'aioseo_terms';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $terms_table))) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $term_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$terms_table}");
            if ($term_count > 0) {
                $types['termmeta'] = $term_count;
            }
        }

        // Check for redirections table
        $redirects_table = $this->get_redirects_table_name();
        $redirects_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $redirects_table)
        );
        if ($redirects_exists) {
            $redirect_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$redirects_table}");
            if ($redirect_count > 0) {
                $types['redirections'] = $redirect_count;
            }
        }

        if (get_option('aioseo_options', null) !== null) {
            $types['settings'] = 1;
        }

        return $types;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_postmeta_page(int $page): array {
        if (!$this->table_exists()) {
            return [];
        }

        global $wpdb;

        $table = $this->get_table_name();
        $offset = ($page - 1) * $this->chunk_size;
        $columns = $this->get_table_columns();

        if (empty($columns)) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY post_id ASC LIMIT %d OFFSET %d",
                $this->chunk_size,
                $offset
            ),
            ARRAY_A
        );

        // Report the raw fetched-row count so export_chunk() paginates on it
        // rather than the post-filter emitted count (rows with post_id=0 are
        // skipped below).
        $this->last_page_row_count = is_array($rows) ? count($rows) : 0;

        if (empty($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if (!$post_id) {
                continue;
            }

            // Parse keyphrases JSON
            $focus_keyword = '';
            $additional_keyphrases = [];
            if (!empty($row['keyphrases'])) {
                $keyphrases = json_decode($row['keyphrases'], true);
                if (is_array($keyphrases)) {
                    if (isset($keyphrases['focus']['keyphrase'])) {
                        $focus_keyword = $keyphrases['focus']['keyphrase'];
                    }
                    if (isset($keyphrases['additional']) && is_array($keyphrases['additional'])) {
                        foreach ($keyphrases['additional'] as $additional) {
                            if (isset($additional['keyphrase']) && !empty($additional['keyphrase'])) {
                                $additional_keyphrases[] = $additional['keyphrase'];
                            }
                        }
                    }
                }
            }

            // Robots. AIOSEO's robots_default flag means "inherit the global
            // defaults" — the per-post robots columns are meaningless then
            // (NULL or stale), so no directive may be emitted. Coercing the
            // NULL robots_max_* columns to 0 would write an explicit
            // "snippets/previews disabled" override onto every post. The
            // migrator skips null values entirely.
            $robots_default = $this->safe_column_int($row, 'robots_default') === 1;
            $noindex  = $robots_default ? null : $this->safe_column_int($row, 'robots_noindex');
            $nofollow = $robots_default ? null : $this->safe_column_int($row, 'robots_nofollow');

            // AIOSEO's schema_type placeholder 'default' means "no explicit
            // choice"; passing it through would stamp a literal 'default'
            // schema type on every post.
            $schema_type = $this->safe_column($row, 'schema_type');
            if (strtolower($schema_type) === 'default') {
                $schema_type = '';
            }

            // max-image-preview is only a real restriction as 'none'/'standard';
            // 'large' is the crawler default (and what AIOSEO stores alongside
            // otherwise-inert robots rows), so emitting it would manufacture a
            // robots override for posts that have no active directive.
            $max_image_preview = $robots_default ? '' : $this->safe_column($row, 'robots_max_imagepreview');
            if (!in_array($max_image_preview, ['none', 'standard'], true)) {
                $max_image_preview = '';
            }

            // Full focus-keyword list (focus + additional) for the post's
            // ThinkRank keyword array; the migrator falls back to the single
            // focus_keyword when this is absent, dropping the additionals.
            $focus_keywords = array_values(array_filter(
                array_merge([$focus_keyword], $additional_keyphrases),
                static fn($keyword) => trim((string) $keyword) !== ''
            ));

            $records[] = [
                'object_id'     => $post_id,
                'object_type'   => 'post',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'           => $this->convert_template_variables($this->safe_column($row, 'title'), $post_id),
                    'meta_description'    => $this->convert_template_variables($this->safe_column($row, 'description'), $post_id),
                    'focus_keyword'       => $focus_keyword,
                    'focus_keywords'      => $focus_keywords,
                    'canonical_url'       => $this->safe_column($row, 'canonical_url'),
                    'noindex'             => $noindex,
                    'nofollow'            => $nofollow,
                    'noarchive'           => $robots_default ? null : $this->safe_column_int($row, 'robots_noarchive'),
                    'noimageindex'        => $robots_default ? null : $this->safe_column_int($row, 'robots_noimageindex'),
                    'nosnippet'           => $robots_default ? null : $this->safe_column_int($row, 'robots_nosnippet'),
                    'max_snippet'         => $robots_default ? null : $this->robots_limit($row, 'robots_max_snippet'),
                    'max_video_preview'   => $robots_default ? null : $this->robots_limit($row, 'robots_max_videopreview'),
                    'max_image_preview'   => $max_image_preview,
                    'og_title'            => $this->convert_template_variables($this->safe_column($row, 'og_title'), $post_id),
                    'og_description'      => $this->convert_template_variables($this->safe_column($row, 'og_description'), $post_id),
                    'og_image'            => $this->safe_column($row, 'og_image_custom_url'),
                    'twitter_title'       => $this->convert_template_variables($this->safe_column($row, 'twitter_title'), $post_id),
                    'twitter_description' => $this->convert_template_variables($this->safe_column($row, 'twitter_description'), $post_id),
                    'twitter_image'       => $this->safe_column($row, 'twitter_image_custom_url'),
                    'primary_category'    => $this->extract_primary_category($row),
                    'schema_type'         => $schema_type,
                    // AIOSEO pillar content maps directly to ThinkRank pillar content.
                    'pillar_content'      => $this->safe_column_int($row, 'pillar_content'),
                ],
                'extended' => [
                    'focus_keywords_additional' => $additional_keyphrases,
                    'pillar_content'            => (bool) $this->safe_column_int($row, 'pillar_content'),
                    // "Use Facebook data for Twitter" toggle — ThinkRank has no
                    // per-post equivalent yet; preserved for a future mapping.
                    'twitter_use_og'            => (bool) $this->safe_column_int($row, 'twitter_use_og'),
                    'og_object_type'            => $this->safe_column($row, 'og_object_type'),
                    'og_image_type'             => $this->safe_column($row, 'og_image_type'),
                    'twitter_card'              => $this->safe_column($row, 'twitter_card'),
                    'twitter_image_type'        => $this->safe_column($row, 'twitter_image_type'),
                    'schema_type_options'       => $this->safe_column($row, 'schema_type_options'),
                    'seo_score'                 => $this->safe_column_int($row, 'seo_score'),
                    'keyphrases_score'          => $this->safe_column($row, 'keyphrases_score'),
                    'page_analysis'             => $this->safe_column($row, 'page_analysis'),
                    'priority'                  => $this->safe_column($row, 'priority'),
                    'frequency'                 => $this->safe_column($row, 'frequency'),
                    'videos'                    => $this->safe_column($row, 'videos'),
                    'video_thumbnail'           => $this->safe_column($row, 'video_thumbnail'),
                    'local_seo'                 => $this->safe_column($row, 'local_seo'),
                ],
            ];
        }

        return $records;
    }

    /**
     * Read an AIOSEO robots_max_* limit column, treating "no explicit limit"
     * as absent. AIOSEO stores NULL when unset and -1 for "unlimited/default";
     * only a real 0+ value is an actual directive.
     *
     * @param array  $row    Database row
     * @param string $column Column name
     * @return int|null Limit value, or null when not set
     */
    private function robots_limit(array $row, string $column): ?int {
        if (!$this->has_column($column)) {
            return null;
        }

        $value = $row[$column] ?? null;
        if ($value === null || $value === '' || (int) $value === -1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Extract the primary category term ID from AIOSEO's primary_term column
     * (JSON of {"<taxonomy>": <term_id>}, written per-taxonomy — see AIOSEO's
     * own Yoast importer).
     *
     * @param array $row Database row
     * @return int Term ID, or 0 when none is set
     */
    private function extract_primary_category(array $row): int {
        if (!$this->has_column('primary_term') || empty($row['primary_term'])) {
            return 0;
        }

        $terms = json_decode((string) $row['primary_term'], true);
        if (!is_array($terms) || empty($terms['category'])) {
            return 0;
        }

        return (int) $terms['category'];
    }

    /**
     * {@inheritDoc}
     *
     * AIOSEO Pro stores term SEO in its own {prefix}aioseo_terms table (same
     * column layout as aioseo_posts, keyed by term_id); some free/legacy
     * versions used aioseo_*-prefixed termmeta instead. The table wins when it
     * exists, with the termmeta scan as fallback.
     */
    protected function export_termmeta_page(int $page): array {
        global $wpdb;

        $terms_table = $wpdb->prefix . 'aioseo_terms';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $terms_table))) {
            return $this->export_terms_table_page($page);
        }

        $offset = ($page - 1) * $this->chunk_size;

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key LIKE %s ORDER BY term_id ASC LIMIT %d OFFSET %d",
                $wpdb->esc_like('aioseo_') . '%',
                $this->chunk_size,
                $offset
            )
        );

        if (empty($term_ids)) {
            return [];
        }

        $records = [];
        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;

            $records[] = [
                'object_id'     => $term_id,
                'object_type'   => 'term',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'        => get_term_meta($term_id, 'aioseo_title', true) ?: '',
                    'meta_description' => get_term_meta($term_id, 'aioseo_description', true) ?: '',
                    'canonical_url'    => get_term_meta($term_id, 'aioseo_canonical_url', true) ?: '',
                    'noindex'          => (int) get_term_meta($term_id, 'aioseo_noindex', true),
                    'nofollow'         => (int) get_term_meta($term_id, 'aioseo_nofollow', true),
                    'og_title'         => get_term_meta($term_id, 'aioseo_og_title', true) ?: '',
                    'og_description'   => get_term_meta($term_id, 'aioseo_og_description', true) ?: '',
                ],
                'extended' => [],
            ];
        }

        return $records;
    }

    /**
     * Export one page of AIOSEO Pro's aioseo_terms table.
     *
     * @param int $page Page number (1-indexed)
     * @return array Snapshot records
     */
    private function export_terms_table_page(int $page): array {
        global $wpdb;

        $table = $wpdb->prefix . 'aioseo_terms';
        $offset = ($page - 1) * $this->chunk_size;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY term_id ASC LIMIT %d OFFSET %d",
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
            $term_id = (int) ($row['term_id'] ?? 0);
            if (!$term_id) {
                continue;
            }

            $focus_keyword = '';
            $additional = [];
            if (!empty($row['keyphrases'])) {
                $keyphrases = json_decode((string) $row['keyphrases'], true);
                if (is_array($keyphrases)) {
                    $focus_keyword = (string) ($keyphrases['focus']['keyphrase'] ?? '');
                    foreach ($keyphrases['additional'] ?? [] as $extra) {
                        if (!empty($extra['keyphrase'])) {
                            $additional[] = (string) $extra['keyphrase'];
                        }
                    }
                }
            }

            // Same robots semantics as posts: robots_default means "inherit".
            $robots_default = (int) ($row['robots_default'] ?? 1) === 1;

            $records[] = [
                'object_id'     => $term_id,
                'object_type'   => 'term',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'           => $this->convert_template_variables((string) ($row['title'] ?? '')),
                    'meta_description'    => $this->convert_template_variables((string) ($row['description'] ?? '')),
                    'focus_keyword'       => $focus_keyword,
                    'focus_keywords'      => array_values(array_filter(
                        array_merge([$focus_keyword], $additional),
                        static fn($keyword) => trim((string) $keyword) !== ''
                    )),
                    'canonical_url'       => (string) ($row['canonical_url'] ?? ''),
                    'noindex'             => $robots_default ? null : (int) ($row['robots_noindex'] ?? 0),
                    'nofollow'            => $robots_default ? null : (int) ($row['robots_nofollow'] ?? 0),
                    'og_title'            => $this->convert_template_variables((string) ($row['og_title'] ?? '')),
                    'og_description'      => $this->convert_template_variables((string) ($row['og_description'] ?? '')),
                    'og_image'            => (string) ($row['og_image_custom_url'] ?? ''),
                    'twitter_title'       => $this->convert_template_variables((string) ($row['twitter_title'] ?? '')),
                    'twitter_description' => $this->convert_template_variables((string) ($row['twitter_description'] ?? '')),
                    'twitter_image'       => (string) ($row['twitter_image_custom_url'] ?? ''),
                ],
                'extended' => [],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_usermeta_page(int $page): array {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function export_settings(): array {
        $options_raw = get_option('aioseo_options', '');

        // aioseo_options is stored as a JSON string
        $options = is_string($options_raw) ? json_decode($options_raw, true) : [];
        if (!is_array($options)) {
            $options = [];
        }

        // Guard against scalar sub-values (a malformed/legacy JSON blob) before
        // they reach the array-typed extractors below.
        $search_appearance = is_array($options['searchAppearance'] ?? null) ? $options['searchAppearance'] : [];
        $social = is_array($options['social'] ?? null) ? $options['social'] : [];
        $sitemap = is_array($options['sitemap'] ?? null) ? $options['sitemap'] : [];
        $archives = is_array($search_appearance['archives'] ?? null) ? $search_appearance['archives'] : [];

        // AIOSEO Pro add-on settings (news/video sitemaps, image SEO, local
        // business) live in a SEPARATE aioseo_options_pro option.
        $pro_raw = get_option('aioseo_options_pro', '');
        $pro = is_string($pro_raw) ? json_decode($pro_raw, true) : [];
        $pro = is_array($pro) ? $pro : [];

        // Bare Twitter handle: ThinkRank stores handles (matching Yoast/Rank
        // Math), AIOSEO stores a full profile URL.
        $twitter = (string) ($social['profiles']['urls']['twitterUrl'] ?? '');
        $twitter = preg_replace('#^https?://(?:www\.)?(?:twitter|x)\.com/#i', '', $twitter);
        $twitter = ltrim((string) $twitter, '@');

        // Per-post-type/taxonomy templates live in the SEPARATE
        // aioseo_options_dynamic option (also a JSON string).
        $dynamic_raw = get_option('aioseo_options_dynamic', '');
        $dynamic = is_string($dynamic_raw) ? json_decode($dynamic_raw, true) : [];
        $dynamic_sa = is_array($dynamic['searchAppearance'] ?? null) ? $dynamic['searchAppearance'] : [];

        return [
            [
                'type'          => 'settings',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    // AIOSEO stores the separator HTML-encoded ('&#45;'); the
                    // migrator's Global-SEO path writes it verbatim, so decode
                    // here at the source.
                    'separator'            => $this->decode_entities($search_appearance['global']['separator'] ?? '-'),
                    'homepage_title'       => $this->convert_template_variables($search_appearance['global']['siteTitle'] ?? ''),
                    'homepage_description' => $this->convert_template_variables($search_appearance['global']['metaDescription'] ?? ''),
                    'organization_name'    => $this->convert_template_variables($search_appearance['global']['schema']['organizationName'] ?? ''),
                    'organization_logo'    => $search_appearance['global']['schema']['organizationLogo'] ?? '',
                    'knowledge_graph'      => $this->extract_aioseo_knowledge_graph($search_appearance),
                    'noindex_archives'     => [
                        'author' => $this->archive_noindex($archives['author'] ?? []),
                        'date'   => $this->archive_noindex($archives['date'] ?? []),
                    ],
                    // Default Twitter card ('summary'/'summary_large_image') for
                    // ThinkRank's Social Meta settings.
                    'twitter_card_type'    => (string) ($social['twitter']['general']['defaultCardType'] ?? ''),
                    // Site-wide social defaults with direct ThinkRank homes.
                    'social_defaults'      => [
                        'facebook_app_id'  => (string) ($social['facebook']['advanced']['appId'] ?? ''),
                        'og_default_image' => (string) ($social['facebook']['general']['defaultImagePosts'] ?? ''),
                    ],
                    'social_profiles'      => [
                        'facebook'  => $social['facebook']['general']['facebookPageUrl'] ?? '',
                        'twitter'   => $twitter,
                        'instagram' => $social['profiles']['urls']['instagramUrl'] ?? '',
                        'linkedin'  => $social['profiles']['urls']['linkedinUrl'] ?? '',
                        'youtube'   => $social['profiles']['urls']['youtubeUrl'] ?? '',
                        'pinterest' => $social['profiles']['urls']['pinterestUrl'] ?? '',
                    ],
                ],
                'extended' => [
                    'search_appearance' => $search_appearance,
                    // Normalize AIOSEO's nested sitemap tree into the flat keys the
                    // migrator's migrate_sitemap() consumes. The full raw tree is kept
                    // under sitemap_settings_raw for features ThinkRank does not model.
                    'sitemap_settings'     => $this->normalize_aioseo_sitemap($sitemap),
                    'sitemap_settings_raw' => $sitemap,
                    'social_settings'   => $social,
                    'advanced'          => $options['advanced'] ?? [],
                    'access_control'    => $options['accessControl'] ?? [],
                    // Site-Identity per-context title formats (template dialect:
                    // %site_title%/%post_title%/%sep%/…).
                    'title_formats'     => $this->extract_aioseo_title_formats($search_appearance, $dynamic_sa, $archives),
                    // Global-SEO per-post-type templates (template dialect:
                    // %title%/%sitename%/%sep%/…) — a DIFFERENT vocabulary from
                    // title_formats, resolved by a different renderer.
                    'post_type_settings' => $this->extract_aioseo_post_type_settings($dynamic_sa),
                    'author_archives'    => $this->extract_aioseo_author_archives($archives),
                    'breadcrumb_settings' => $this->extract_aioseo_breadcrumbs(is_array($options['breadcrumbs'] ?? null) ? $options['breadcrumbs'] : []),
                    // Webmaster-tools verification codes. ThinkRank only renders
                    // a Pinterest verification tag today (the migrator applies
                    // it); the rest is preserved here — this bucket is NOT in
                    // HANDLED_EXTENDED_SETTINGS, so it gates /import/cleanup.
                    'webmaster_tools'    => array_filter([
                        'google'    => (string) ($options['webmasterTools']['google'] ?? ''),
                        'bing'      => (string) ($options['webmasterTools']['bing'] ?? ''),
                        'yandex'    => (string) ($options['webmasterTools']['yandex'] ?? ''),
                        'baidu'     => (string) ($options['webmasterTools']['baidu'] ?? ''),
                        'pinterest' => (string) ($options['webmasterTools']['pinterest'] ?? ''),
                    ]),
                    // AIOSEO Pro add-ons → existing migrator paths.
                    'publisher_sitemaps' => $this->extract_aioseo_publisher_sitemaps($pro),
                    'image_seo'          => $this->extract_aioseo_image_seo($pro),
                    'local_seo'          => $this->extract_aioseo_local_seo($pro),
                ],
            ],
        ];
    }

    /**
     * Decode HTML entities AIOSEO stores in settings values (e.g. the title
     * separator '&#45;' or breadcrumb '&raquo;').
     *
     * @param string $value Raw value
     * @return string Decoded value
     */
    private function decode_entities(string $value): string {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Whether an AIOSEO archive node is explicitly noindexed: its robotsMeta
     * must have default=false (custom robots on) AND noindex=true.
     *
     * @param array $archive AIOSEO archives.{author|date|search} node
     * @return bool
     */
    private function archive_noindex(array $archive): bool {
        // AIOSEO can remove an archive entirely (show=false, e.g. its RankMath
        // importer maps "disable date archives" this way). ThinkRank cannot
        // remove date archives, so noindex is the nearest equivalent.
        if (array_key_exists('show', $archive) && !$archive['show']) {
            return true;
        }

        $robots = $archive['advanced']['robotsMeta'] ?? [];
        if (!is_array($robots) || !empty($robots['default'])) {
            return false;
        }

        return !empty($robots['noindex']);
    }

    /**
     * Extract AIOSEO's Knowledge Graph entity (searchAppearance.global.schema).
     * AIOSEO stores siteRepresents ('organization'|'person') with separate
     * organizationName/personName fields, both of which may hold smart tags
     * (organizationName defaults to '#site_title').
     *
     * @param array $search_appearance searchAppearance option subtree
     * @return array{type: string, name: string}
     */
    private function extract_aioseo_knowledge_graph(array $search_appearance): array {
        $schema = $search_appearance['global']['schema'] ?? [];
        $represents = strtolower((string) ($schema['siteRepresents'] ?? ''));

        if ($represents === 'person') {
            $name = (string) ($schema['personName'] ?? '');

            return [
                'type' => 'person',
                'name' => $this->convert_template_variables($name),
            ];
        }

        if ($represents === 'organization') {
            $name = (string) ($schema['organizationName'] ?? '');

            return [
                'type' => 'organization',
                'name' => $this->convert_template_variables($name),
            ];
        }

        return ['type' => '', 'name' => ''];
    }

    /**
     * Map AIOSEO's per-context title templates onto ThinkRank's Site Identity
     * keys, converting #smart_tags to the identity renderer's %token%
     * vocabulary.
     *
     * @param array $search_appearance aioseo_options searchAppearance subtree
     * @param array $dynamic_sa        aioseo_options_dynamic searchAppearance subtree
     * @param array $archives          searchAppearance.archives subtree
     * @return array Map of ThinkRank title-format key => converted template
     */
    private function extract_aioseo_title_formats(array $search_appearance, array $dynamic_sa, array $archives): array {
        $post_types = $dynamic_sa['postTypes'] ?? [];
        $taxonomies = $dynamic_sa['taxonomies'] ?? [];

        // ThinkRank key => [raw AIOSEO template, token #post_title/#taxonomy_title
        // stands for in that context].
        $sources = [
            'homepage_title' => [$search_appearance['global']['siteTitle'] ?? '', ''],
            'post_title'     => [$post_types['post']['title'] ?? '', '%post_title%'],
            'page_title'     => [$post_types['page']['title'] ?? '', '%page_title%'],
            'category_title' => [$taxonomies['category']['title'] ?? '', '%category_title%'],
            'tag_title'      => [$taxonomies['post_tag']['title'] ?? '', '%tag_title%'],
            'search_title'   => [$archives['search']['title'] ?? '', '%search_term%'],
            'archive_title'  => [$archives['date']['title'] ?? '', '%date%'],
            'author_title'   => [$archives['author']['title'] ?? '', '%author_name%'],
        ];

        $formats = [];
        foreach ($sources as $tr_key => [$raw, $context_token]) {
            $converted = $this->convert_aioseo_identity_template((string) $raw, $context_token);
            if ($converted !== '') {
                $formats[$tr_key] = $converted;
            }
        }

        return $formats;
    }

    /**
     * Convert an AIOSEO title template into ThinkRank's Site Identity token
     * vocabulary, preserving structure. Tokens ThinkRank cannot resolve are
     * stripped, and a separator left dangling by that strip is dropped.
     *
     * @param string $template      Raw AIOSEO template (#smart_tag syntax)
     * @param string $context_token Token #post_title/#taxonomy_title stands for (may be '')
     * @return string ThinkRank Site Identity template
     */
    private function convert_aioseo_identity_template(string $template, string $context_token): string {
        if ($template === '' || strpos($template, '#') === false) {
            return trim($template);
        }

        $map = [
            '#site_title'        => '%site_title%',
            '#tagline'           => '%site_description%',
            '#separator_sa'      => '%sep%',
            '#search_term'       => '%search_term%',
            '#archive_date'      => '%date%',
            '#post_date'         => '%date%',
            '#archive_title'     => '%archive_title%',
            '#author_name'       => '%author_name%',
            // AIOSEO splits the author name into first/last tags; ThinkRank has
            // a single %author_name%, so first maps onto it and last collapses.
            '#author_first_name' => '%author_name%',
            '#author_last_name'  => '',
        ];
        if ($context_token !== '') {
            $map['#post_title']     = $context_token;
            $map['#taxonomy_title'] = $context_token;
            $map['#category_title'] = $context_token;
            $map['#tag_title']      = $context_token;
        }
        $template = str_replace(array_keys($map), array_values($map), $template);

        // Drop remaining AIOSEO tags ThinkRank cannot resolve.
        $template = (string) preg_replace(self::AIOSEO_TAG_PATTERN, '', $template);

        $template = (string) preg_replace('/\s{2,}/', ' ', $template);
        $template = trim($template);
        $template = (string) preg_replace('/^(?:%sep%)\s*/', '', $template);
        $template = (string) preg_replace('/\s*(?:%sep%)$/', '', $template);

        return trim($template);
    }

    /**
     * Extract AIOSEO's per-post-type title/description templates (from
     * aioseo_options_dynamic) in the shape the migrator's
     * migrate_post_type_settings() consumes, using the Global SEO renderer's
     * %title%/%sitename% dialect. A post type's noindex only counts when its
     * robotsMeta default flag is off (custom robots active).
     *
     * @param array $dynamic_sa aioseo_options_dynamic searchAppearance subtree
     * @return array Map of post_type => {title_template, description_template, noindex}
     */
    private function extract_aioseo_post_type_settings(array $dynamic_sa): array {
        $settings = [];

        foreach ($dynamic_sa['postTypes'] ?? [] as $post_type => $pt) {
            if (!is_array($pt)) {
                continue;
            }

            $pt_settings = [];

            $title = $this->convert_aioseo_global_template((string) ($pt['title'] ?? ''));
            if ($title !== '') {
                $pt_settings['title_template'] = $title;
            }

            $description = $this->convert_aioseo_global_template((string) ($pt['metaDescription'] ?? ''));
            if ($description !== '') {
                $pt_settings['description_template'] = $description;
            }

            // Custom per-post-type robots, in the {custom_robots, robots: [...]}
            // shape migrate_post_type_settings() consumes.
            $robots = $pt['advanced']['robotsMeta'] ?? [];
            if (is_array($robots) && empty($robots['default'])) {
                $directives = [];
                foreach (['noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'] as $flag) {
                    if (!empty($robots[$flag])) {
                        $directives[] = $flag;
                    }
                }
                if (!empty($directives)) {
                    $pt_settings['custom_robots'] = true;
                    $pt_settings['robots'] = $directives;
                }
            }

            if (!empty($pt_settings)) {
                $settings[$post_type] = $pt_settings;
            }
        }

        return $settings;
    }

    /**
     * Convert an AIOSEO template into the Global SEO Pattern_Resolver
     * vocabulary (%title%/%sitename%/%sep%/%excerpt% — a DIFFERENT dialect
     * from the Site Identity one).
     *
     * @param string $template Raw AIOSEO template
     * @return string Converted template
     */
    private function convert_aioseo_global_template(string $template): string {
        if ($template === '' || strpos($template, '#') === false) {
            return trim($template);
        }

        $map = [
            '#post_title'     => '%title%',
            '#site_title'     => '%sitename%',
            '#separator_sa'   => '%sep%',
            '#post_excerpt'   => '%excerpt%',
            '#post_date'      => '%date%',
            '#author_name'    => '%author%',
            '#category_title' => '%category%',
        ];
        $template = str_replace(array_keys($map), array_values($map), $template);

        // Drop remaining AIOSEO tags the resolver cannot handle.
        $template = (string) preg_replace(self::AIOSEO_TAG_PATTERN, '', $template);
        $template = (string) preg_replace('/\s+/', ' ', $template);

        return trim($template);
    }

    /**
     * Extract AIOSEO's author-archive behaviour for ThinkRank's Author
     * Archives feature. The archive noindex flag travels separately in
     * data.noindex_archives.
     *
     * @param array $archives searchAppearance.archives subtree
     * @return array Author archive settings
     */
    private function extract_aioseo_author_archives(array $archives): array {
        $author = $archives['author'] ?? [];
        if (!is_array($author) || empty($author)) {
            return [];
        }

        return [
            'enabled'     => !empty($author['show']),
            'title'       => $this->convert_aioseo_identity_template((string) ($author['title'] ?? ''), '%author_name%'),
            'description' => $this->convert_aioseo_identity_template((string) ($author['metaDescription'] ?? ''), '%author_name%'),
        ];
    }

    /**
     * Extract AIOSEO's breadcrumb settings. AIOSEO breadcrumbs render only
     * where placed (block/shortcode/PHP) and expose no global on/off toggle,
     * so the presence of breadcrumb config maps to enabled=true — which also
     * matches ThinkRank's default, keeping the migration non-destructive.
     *
     * @param array $breadcrumbs AIOSEO top-level breadcrumbs option
     * @return array Canonical breadcrumb_settings payload
     */
    private function extract_aioseo_breadcrumbs(array $breadcrumbs): array {
        if (empty($breadcrumbs)) {
            return [];
        }

        return [
            'enabled'    => true,
            'home_label' => (string) ($breadcrumbs['homepageLabel'] ?? ''),
            'separator'  => $this->decode_entities((string) ($breadcrumbs['separator'] ?? '')),
            'prefix'     => (string) ($breadcrumbs['breadcrumbPrefix'] ?? ''),
        ];
    }

    /**
     * Extract AIOSEO Pro's News/Video sitemap post types in the shape
     * migrate_publisher_sitemaps() consumes.
     *
     * @param array $pro aioseo_options_pro subtree
     * @return array {news_post_types, video_post_types} (absent keys omitted)
     */
    private function extract_aioseo_publisher_sitemaps(array $pro): array {
        $out = [];

        foreach (['news' => 'news_post_types', 'video' => 'video_post_types'] as $kind => $target) {
            $node = $pro['sitemap'][$kind] ?? [];
            if (!is_array($node) || empty($node['enable'])) {
                continue;
            }

            $types = $this->aioseo_inclusion_node($node['postTypes'] ?? []);
            $list = $types['all']
                ? array_values(get_post_types(['public' => true], 'names'))
                : $types['included'];

            if (!empty($list)) {
                $out[$target] = $list;
            }
        }

        return $out;
    }

    /**
     * Extract AIOSEO Pro's Image SEO title/alt formats in the shape
     * migrate_image_seo() consumes. Presence of a format implies the feature
     * was in use, so the matching auto-generation flag is enabled (mirroring
     * how Rank Math's AIOSEO importer treats it).
     *
     * @param array $pro aioseo_options_pro subtree
     * @return array Image SEO payload (empty when unused)
     */
    private function extract_aioseo_image_seo(array $pro): array {
        $format = $pro['image']['format'] ?? [];
        if (!is_array($format) || empty($format)) {
            return [];
        }

        $out = [];

        $title = $this->convert_aioseo_image_template((string) ($format['title'] ?? ''));
        if ($title !== '') {
            $out['add_missing_title'] = true;
            $out['title_format'] = $title;
        }

        $alt = $this->convert_aioseo_image_template((string) ($format['altTag'] ?? ''));
        if ($alt !== '') {
            $out['add_missing_alt'] = true;
            $out['alt_format'] = $alt;
        }

        return $out;
    }

    /**
     * Convert an AIOSEO image-format template to ThinkRank's Image SEO token
     * vocabulary (%title%/%filename%/%separator%/%sitename%).
     *
     * @param string $template Raw AIOSEO template
     * @return string Converted template
     */
    private function convert_aioseo_image_template(string $template): string {
        if ($template === '' || strpos($template, '#') === false) {
            return trim($template);
        }

        $map = [
            '#post_title'         => '%title%',
            '#image_title'        => '%title%',
            '#site_title'         => '%sitename%',
            '#separator_sa'       => '%separator%',
            '#image_filename'     => '%filename%',
            '#attachment_caption' => '%caption%',
            '#alt_tag'            => '%alt%',
        ];
        $template = str_replace(array_keys($map), array_values($map), $template);
        $template = (string) preg_replace(self::AIOSEO_TAG_PATTERN, '', $template);
        $template = (string) preg_replace('/\s+/', ' ', $template);

        return trim($template);
    }

    /**
     * Extract AIOSEO Pro's Local Business settings in the canonical local_seo
     * shape migrate_site_identity() consumes (business NAP + hours).
     *
     * @param array $pro aioseo_options_pro subtree
     * @return array Local SEO payload (empty when unused)
     */
    private function extract_aioseo_local_seo(array $pro): array {
        $business = $pro['localBusiness']['locations']['business'] ?? [];
        if (!is_array($business) || empty($business)) {
            return [];
        }

        $address = is_array($business['address'] ?? null) ? $business['address'] : [];
        $street = trim((string) ($address['streetLine1'] ?? ''));
        if (!empty($address['streetLine2'])) {
            $street = trim($street . ', ' . $address['streetLine2'], ', ');
        }

        $out = [
            'business_type' => (string) ($business['businessType'] ?? ''),
            'business_name' => (string) ($business['name'] ?? ''),
            'phone'         => (string) ($business['contact']['phone'] ?? ''),
            'price_range'   => (string) ($business['payment']['priceRange'] ?? ''),
            'address'       => array_filter([
                'street'      => $street,
                'city'        => (string) ($address['city'] ?? ''),
                'state'       => (string) ($address['state'] ?? ''),
                'postal_code' => (string) ($address['zipCode'] ?? ''),
                'country'     => (string) ($address['country'] ?? ''),
            ]),
            'opening_hours' => $this->extract_aioseo_opening_hours($pro['localBusiness']['openingHours'] ?? []),
        ];

        return array_filter($out);
    }

    /**
     * Convert AIOSEO's per-day opening hours into ThinkRank's business_hours
     * shape ({day: {open, close, closed}}).
     *
     * @param array $opening_hours AIOSEO localBusiness.openingHours subtree
     * @return array Per-day hours (closed days omitted)
     */
    private function extract_aioseo_opening_hours(array $opening_hours): array {
        $days = is_array($opening_hours['days'] ?? null) ? $opening_hours['days'] : [];
        $out = [];

        foreach ($days as $day => $hours) {
            $day = strtolower((string) $day);
            if (!is_array($hours) || !empty($hours['closed'])) {
                continue;
            }

            $open = (string) ($hours['openTime'] ?? '');
            $close = (string) ($hours['closeTime'] ?? '');
            if ($open === '' || $close === '') {
                continue;
            }

            $out[$day] = [
                'open'   => $open,
                'close'  => $close,
                'closed' => false,
            ];
        }

        return $out;
    }

    /**
     * Normalize AIOSEO's nested `sitemap` option tree into the flat, canonical
     * `sitemap_settings` shape the migrator's migrate_sitemap() reads.
     *
     * AIOSEO stores the general sitemap config under sitemap.general with an
     * `enable` flag, `linksPerIndex`, and post-type/taxonomy inclusion expressed
     * as { all: bool, included: [slugs] }. Image inclusion is on unless
     * advancedSettings.excludeImages is set. AIOSEO exposes no featured-image or
     * ping toggle, so those keys are intentionally omitted (the migrator skips
     * absent keys). Returns ['has_data' => false] when there is nothing to migrate.
     *
     * NOTE: AIOSEO is not installed in this environment; the key paths below come
     * from AIOSEO's documented options schema, not a live capture.
     *
     * @param array $sitemap AIOSEO options['sitemap'] subtree
     * @return array Canonical sitemap_settings payload
     */
    private function normalize_aioseo_sitemap(array $sitemap): array {
        $general = $sitemap['general'] ?? [];
        if (!is_array($general) || empty($general)) {
            return ['has_data' => false];
        }

        $post_types = $this->aioseo_inclusion_node($general['postTypes'] ?? []);
        $taxonomies = $this->aioseo_inclusion_node($general['taxonomies'] ?? []);
        $advanced   = is_array($general['advancedSettings'] ?? null) ? $general['advancedSettings'] : [];

        $normalized = [
            'enabled'            => !empty($general['enable']),
            'include_posts'      => $post_types['all'] || in_array('post', $post_types['included'], true),
            'include_pages'      => $post_types['all'] || in_array('page', $post_types['included'], true),
            'include_categories' => $taxonomies['all'] || in_array('category', $taxonomies['included'], true),
            'include_tags'       => $taxonomies['all'] || in_array('post_tag', $taxonomies['included'], true),
            // AIOSEO includes images unless advancedSettings.excludeImages is on.
            'include_images'     => empty($advanced['excludeImages']),
            // AIOSEO's `indexes` flag is the equivalent of ThinkRank's sitemap
            // index toggle (an index that points at per-type child sitemaps).
            'use_sitemap_index'  => !empty($general['indexes']),
            'has_data'           => true,
        ];

        if (isset($general['linksPerIndex'])) {
            $normalized['links_per_sitemap'] = (int) $general['linksPerIndex'];
        }

        // Excluded posts/terms: AIOSEO stores each entry as a JSON-encoded
        // {value: <id>, label: ...} object. ThinkRank stores comma-separated
        // ID lists.
        foreach (['excludePosts' => 'exclude_posts', 'excludeTerms' => 'exclude_terms'] as $source => $target) {
            $ids = [];
            foreach ((array) ($advanced[$source] ?? []) as $entry) {
                $entry = is_string($entry) ? json_decode($entry, true) : $entry;
                if (is_array($entry) && !empty($entry['value'])) {
                    $ids[] = (int) $entry['value'];
                }
            }
            if (!empty($ids)) {
                $normalized[$target] = implode(', ', $ids);
            }
        }

        return $normalized;
    }

    /**
     * Coerce an AIOSEO inclusion node ({ all, included }) to a predictable shape.
     * `included` may be a real array of slugs or a JSON-encoded string depending
     * on the AIOSEO version, so both are normalized to a flat slug array.
     *
     * @param mixed $node AIOSEO postTypes/taxonomies node
     * @return array{all: bool, included: array}
     */
    private function aioseo_inclusion_node($node): array {
        if (!is_array($node)) {
            return ['all' => false, 'included' => []];
        }

        $included = $node['included'] ?? [];
        if (is_string($included)) {
            $decoded = json_decode($included, true);
            $included = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($included)) {
            $included = [];
        }

        return ['all' => !empty($node['all']), 'included' => array_values($included)];
    }

    /**
     * {@inheritDoc}
     */
    protected function export_redirections_page(int $page): array {
        global $wpdb;

        $table = $this->get_redirects_table_name();
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table)
        );

        if (!$table_exists) {
            return [];
        }

        $offset = ($page - 1) * $this->chunk_size;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                $this->chunk_size,
                $offset
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            // AIOSEO stores source URLs as JSON array
            $source_urls = json_decode($row['source_url'] ?? '[]', true);
            $source_url = '';
            $is_regex = false;

            if (is_array($source_urls) && !empty($source_urls)) {
                $first = $source_urls[0] ?? [];
                $source_url = $first['url'] ?? '';
                $is_regex = ($first['match'] ?? '') === 'regex';
            } elseif (is_string($source_urls)) {
                $source_url = $source_urls;
            }

            $records[] = [
                'object_type'   => 'redirection',
                'source_plugin' => $this->plugin_slug,
                'data'          => [],
                'extended'      => [
                    'source_url' => $source_url,
                    'target_url' => $row['target_url'] ?? '',
                    'http_code'  => (int) ($row['type'] ?? 301),
                    'is_regex'   => $is_regex,
                    'enabled'    => (bool) ($row['enabled'] ?? true),
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function convert_template_variables(mixed $value, ?int $post_id = null): string {
        // Foreign data first: booleans/arrays in the source plugin's options
        // must degrade to '' here, not fatal the migration (see abstract).
        $value = $this->stringify_template_value($value);

        // No template tag → return untouched. Trimming/stripping plain values
        // mutates real content (trailing spaces, hashtags, URL anchors).
        if (empty($value) || strpos($value, '#') === false) {
            return $value;
        }

        // AIOSEO uses #variable syntax
        $replacements = [
            '#site_title'       => get_bloginfo('name'),
            '#tagline'          => get_bloginfo('description'),
            '#separator_sa'     => '-',
            '#current_year'     => gmdate('Y'),
            '#current_date'     => gmdate('Y-m-d'),
            '#current_month'    => gmdate('F'),
            '#current_day'      => gmdate('j'),
        ];

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $replacements['#post_title']    = $post->post_title;
                $replacements['#post_excerpt']  = wp_trim_words($post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55), 55);
                $replacements['#post_date']     = get_the_date('', $post);
                $replacements['#author_name']   = get_the_author_meta('display_name', (int) $post->post_author);

                $post_type_obj = get_post_type_object($post->post_type);
                $replacements['#post_type'] = $post_type_obj ? $post_type_obj->labels->singular_name : '';

                $categories = get_the_category($post_id);
                $replacements['#category_title'] = !empty($categories) ? $categories[0]->name : '';

                $tags = get_the_tags($post_id);
                $replacements['#tag_title'] = !empty($tags) ? $tags[0]->name : '';
            }
        }

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Strip only KNOWN AIOSEO tags that resolved to nothing above — a
        // blanket /#\w+/ would destroy real hashtags and URL anchors in
        // descriptions.
        $value = preg_replace(self::AIOSEO_TAG_PATTERN, '', $value);

        return trim($value);
    }

    /**
     * Every smart tag AIOSEO can emit in a title/description template (see
     * AIOSEO's app/Common/Utils/Tags.php). Used to strip tags this exporter
     * cannot resolve without touching real `#hashtag` content.
     */
    private const AIOSEO_TAG_PATTERN = '/#(?:post_title|post_excerpt|post_content|post_date|post_day|post_month|post_year|site_title|tagline|separator_sa|current_year|current_date|current_month|current_day|author_name|author_first_name|author_last_name|author_bio|post_type|category_title|taxonomy_title|tag_title|taxonomy_description|category|categories|archive_title|archive_date|search_term|page_number|attachment_caption|attachment_description|alt_tag|permalink|custom_field-[a-zA-Z0-9_-]+|tax_name|tax_parent_name|breadcrumb_[a-z0-9_]+)\b/';

    /**
     * Safely get a string column value
     *
     * @param array $row Database row
     * @param string $column Column name
     * @return string Column value or empty string
     */
    private function safe_column(array $row, string $column): string {
        if (!$this->has_column($column)) {
            return '';
        }
        return (string) ($row[$column] ?? '');
    }

    /**
     * Safely get an integer column value
     *
     * @param array $row Database row
     * @param string $column Column name
     * @return int Column value or 0
     */
    private function safe_column_int(array $row, string $column): int {
        if (!$this->has_column($column)) {
            return 0;
        }
        return (int) ($row[$column] ?? 0);
    }
}
