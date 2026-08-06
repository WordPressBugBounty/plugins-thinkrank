<?php

/**
 * SEOPress Exporter
 *
 * Reads SEOPress data from postmeta/termmeta/options and normalizes
 * into the canonical snapshot format.
 *
 * CRITICAL: _seopress_robots_index = 'yes' means NOINDEX (inverted logic).
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
 * SEOPress Exporter Class
 *
 * @since 2.0.0
 */
class SEOPress_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = 'seopress';
        $this->plugin_name = 'SEOPress';
        $this->plugin_file = 'wp-seopress/seopress.php';
        $this->meta_key_prefix = '_seopress_';
        // SEOPress option names all carry the `_option_name` suffix.
        $this->option_keys = ['seopress_titles_option_name', 'seopress_social_option_name', 'seopress_advanced_option_name'];
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

        // Check for redirections via seopress_404 CPT
        $redirect_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'seopress_404'"
        );
        if ($redirect_count > 0) {
            $types['redirections'] = $redirect_count;
        }

        foreach (array_merge($this->option_keys, ['seopress_xml_sitemap_option_name', 'seopress_instant_indexing_option_name']) as $key) {
            if (get_option($key, null) !== null) {
                $types['settings'] = 1;
                break;
            }
        }

        return $types;
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

            // CRITICAL: SEOPress inverted noindex logic
            // _seopress_robots_index = 'yes' means NOINDEX
            $noindex = ($meta['_seopress_robots_index'] ?? '') === 'yes' ? 1 : 0;
            $nofollow = ($meta['_seopress_robots_follow'] ?? '') === 'yes' ? 1 : 0;

            // Focus keywords are one comma-separated string. The full list
            // feeds ThinkRank's keyword array (data.focus_keywords); the first
            // entry stays the legacy single focus keyword.
            $focus_kw_raw = $meta['_seopress_analysis_target_kw'] ?? '';
            $focus_keywords = array_values(array_filter(
                array_map('trim', explode(',', $focus_kw_raw)),
                static fn($keyword) => $keyword !== ''
            ));
            $primary_keyword = $focus_keywords[0] ?? '';
            $additional_keywords = array_slice($focus_keywords, 1);

            $records[] = [
                'object_id'     => $post_id,
                'object_type'   => 'post',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'           => $this->convert_template_variables($meta['_seopress_titles_title'] ?? '', $post_id),
                    'meta_description'    => $this->convert_template_variables($meta['_seopress_titles_desc'] ?? '', $post_id),
                    'focus_keyword'       => $primary_keyword,
                    'focus_keywords'      => $focus_keywords,
                    'canonical_url'       => $meta['_seopress_robots_canonical'] ?? '',
                    'noindex'             => $noindex,
                    'nofollow'            => $nofollow,
                    'noarchive'           => ($meta['_seopress_robots_archive'] ?? '') === 'yes' ? 1 : 0,
                    'noimageindex'        => ($meta['_seopress_robots_imageindex'] ?? '') === 'yes' ? 1 : 0,
                    'nosnippet'           => ($meta['_seopress_robots_snippet'] ?? '') === 'yes' ? 1 : 0,
                    'og_title'            => $this->convert_template_variables($meta['_seopress_social_fb_title'] ?? '', $post_id),
                    'og_description'      => $this->convert_template_variables($meta['_seopress_social_fb_desc'] ?? '', $post_id),
                    'og_image'            => $meta['_seopress_social_fb_img'] ?? '',
                    'twitter_title'       => $this->convert_template_variables($meta['_seopress_social_twitter_title'] ?? '', $post_id),
                    'twitter_description' => $this->convert_template_variables($meta['_seopress_social_twitter_desc'] ?? '', $post_id),
                    'twitter_image'       => $meta['_seopress_social_twitter_img'] ?? '',
                    'primary_category'    => (int) ($meta['_seopress_robots_primary_cat'] ?? 0),
                    'schema_type'         => '',
                ],
                'extended' => [
                    'focus_keywords_additional' => $additional_keywords,
                    'redirect_enabled'          => !empty($meta['_seopress_redirections_enabled']),
                    'redirect_url'              => $meta['_seopress_redirections_value'] ?? '',
                    'redirect_type'             => $meta['_seopress_redirections_type'] ?? '301',
                    'og_image_width'            => $meta['_seopress_social_fb_img_width'] ?? '',
                    'og_image_height'           => $meta['_seopress_social_fb_img_height'] ?? '',
                    'twitter_card_type'         => $meta['_seopress_social_twitter_card_img_size'] ?? '',
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

            $noindex = ($meta['_seopress_robots_index'] ?? '') === 'yes' ? 1 : 0;
            $nofollow = ($meta['_seopress_robots_follow'] ?? '') === 'yes' ? 1 : 0;

            $records[] = [
                'object_id'     => $term_id,
                'object_type'   => 'term',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'        => $this->convert_template_variables($meta['_seopress_titles_title'] ?? ''),
                    'meta_description' => $this->convert_template_variables($meta['_seopress_titles_desc'] ?? ''),
                    'canonical_url'    => $meta['_seopress_robots_canonical'] ?? '',
                    'noindex'          => $noindex,
                    'nofollow'         => $nofollow,
                    'og_title'         => $this->convert_template_variables($meta['_seopress_social_fb_title'] ?? ''),
                    'og_description'   => $this->convert_template_variables($meta['_seopress_social_fb_desc'] ?? ''),
                ],
                'extended' => [
                    'og_image'    => $meta['_seopress_social_fb_img'] ?? '',
                    'twitter_title' => $meta['_seopress_social_twitter_title'] ?? '',
                    'twitter_description' => $meta['_seopress_social_twitter_desc'] ?? '',
                ],
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
        $titles = get_option('seopress_titles_option_name', []);
        $social = get_option('seopress_social_option_name', []);
        $advanced = get_option('seopress_advanced_option_name', []);
        $xml_sitemap = get_option('seopress_xml_sitemap_option_name', []);
        $instant = get_option('seopress_instant_indexing_option_name', []);
        $pro = get_option('seopress_pro_option_name', []);

        $titles = is_array($titles) ? $titles : [];
        $social = is_array($social) ? $social : [];
        $advanced = is_array($advanced) ? $advanced : [];
        $xml_sitemap = is_array($xml_sitemap) ? $xml_sitemap : [];
        $instant = is_array($instant) ? $instant : [];
        $pro = is_array($pro) ? $pro : [];

        return [
            [
                'type'          => 'settings',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'separator'            => $titles['seopress_titles_sep'] ?? '-',
                    'homepage_title'       => $this->convert_template_variables($titles['seopress_titles_home_site_title'] ?? ''),
                    'homepage_description' => $this->convert_template_variables($titles['seopress_titles_home_site_desc'] ?? ''),
                    // Knowledge Graph lives in SEOPress's SOCIAL option
                    // (knowledge_type 'Organization'|'Person', name, img).
                    'organization_name'    => $this->convert_template_variables($social['seopress_social_knowledge_name'] ?? ''),
                    'organization_logo'    => $social['seopress_social_knowledge_img'] ?? '',
                    'knowledge_graph'      => $this->extract_seopress_knowledge_graph($social),
                    'social_profiles'      => [
                        'facebook'  => $social['seopress_social_accounts_facebook'] ?? '',
                        'twitter'   => $social['seopress_social_accounts_twitter'] ?? '',
                        'instagram' => $social['seopress_social_accounts_instagram'] ?? '',
                        'linkedin'  => $social['seopress_social_accounts_linkedin'] ?? '',
                        'youtube'   => $social['seopress_social_accounts_youtube'] ?? '',
                        'pinterest' => $social['seopress_social_accounts_pinterest'] ?? '',
                    ],
                    // A disabled archive is stronger than noindex — ThinkRank
                    // cannot remove date archives, so both map to noindex.
                    'noindex_archives' => [
                        'date'   => !empty($titles['seopress_titles_archives_date_noindex'])
                            || !empty($titles['seopress_titles_archives_date_disable']),
                        'author' => !empty($titles['seopress_titles_archives_author_noindex'])
                            || !empty($titles['seopress_titles_archives_author_disable']),
                    ],
                    'twitter_card_type' => (string) ($social['seopress_social_twitter_card_img_size'] ?? ''),
                    'social_defaults'   => [
                        'facebook_app_id'  => (string) ($social['seopress_social_facebook_app_id'] ?? ''),
                        'og_default_image' => (string) ($social['seopress_social_facebook_img'] ?? ''),
                    ],
                    // SEOPress's "Bing API key" IS the IndexNow key (served at
                    // /{key}.txt) — carrying it over skips re-verification.
                    'instant_indexing'  => [
                        'api_key' => (string) ($instant['seopress_instant_indexing_bing_api_key'] ?? ''),
                    ],
                ],
                'extended' => [
                    // Pinterest is applied (ThinkRank renders it); the rest is
                    // preserved and gates /import/cleanup.
                    'webmaster_tools' => array_filter([
                        'google'    => (string) ($advanced['seopress_advanced_advanced_google'] ?? ''),
                        'bing'      => (string) ($advanced['seopress_advanced_advanced_bing'] ?? ''),
                        'yandex'    => (string) ($advanced['seopress_advanced_advanced_yandex'] ?? ''),
                        'baidu'     => (string) ($advanced['seopress_advanced_advanced_baidu'] ?? ''),
                        'pinterest' => (string) ($advanced['seopress_advanced_advanced_pinterest'] ?? ''),
                    ]),
                    'title_formats'      => $this->extract_seopress_title_formats($titles),
                    'post_type_settings' => $this->extract_seopress_post_type_settings($titles),
                    'author_archives'    => $this->extract_seopress_author_archives($titles),
                    // Auto image alt is a single toggle in SEOPress (no format).
                    'image_seo'          => !empty($advanced['seopress_advanced_advanced_image_auto_alt_txt'])
                        ? ['add_missing_alt' => true]
                        : [],
                    'breadcrumb_settings' => $this->extract_seopress_breadcrumbs($pro),
                    'sitemap_settings'    => $this->normalize_seopress_sitemap($xml_sitemap),
                ],
            ],
        ];
    }

    /**
     * Extract SEOPress's Knowledge Graph entity (social option:
     * knowledge_type 'Organization'|'Person' + knowledge_name).
     *
     * @param array $social seopress_social_option_name value
     * @return array{type: string, name: string}
     */
    private function extract_seopress_knowledge_graph(array $social): array {
        $type = strtolower((string) ($social['seopress_social_knowledge_type'] ?? ''));
        $name = $this->convert_template_variables((string) ($social['seopress_social_knowledge_name'] ?? ''));

        if (!in_array($type, ['organization', 'person'], true) || $name === '') {
            return ['type' => '', 'name' => ''];
        }

        return ['type' => $type, 'name' => $name];
    }

    /**
     * Map SEOPress's per-context title templates onto ThinkRank's Site
     * Identity keys, converting %%vars%% to the identity renderer's %token%
     * vocabulary.
     *
     * @param array $titles seopress_titles_option_name value
     * @return array Map of ThinkRank title-format key => converted template
     */
    private function extract_seopress_title_formats(array $titles): array {
        $single = is_array($titles['seopress_titles_single_titles'] ?? null) ? $titles['seopress_titles_single_titles'] : [];
        $tax = is_array($titles['seopress_titles_tax_titles'] ?? null) ? $titles['seopress_titles_tax_titles'] : [];

        $sources = [
            'homepage_title' => [$titles['seopress_titles_home_site_title'] ?? '', ''],
            'post_title'     => [$single['post']['title'] ?? '', '%post_title%'],
            'page_title'     => [$single['page']['title'] ?? '', '%page_title%'],
            'category_title' => [$tax['category']['title'] ?? '', '%category_title%'],
            'tag_title'      => [$tax['post_tag']['title'] ?? '', '%tag_title%'],
            'search_title'   => [$titles['seopress_titles_archives_search_title'] ?? '', '%search_term%'],
            'archive_title'  => [$titles['seopress_titles_archives_date_title'] ?? '', '%date%'],
            'author_title'   => [$titles['seopress_titles_archives_author_title'] ?? '', '%author_name%'],
        ];

        $formats = [];
        foreach ($sources as $tr_key => [$raw, $context_token]) {
            $converted = $this->convert_seopress_identity_template((string) $raw, $context_token);
            if ($converted !== '') {
                $formats[$tr_key] = $converted;
            }
        }

        return $formats;
    }

    /**
     * Convert a SEOPress title template into ThinkRank's Site Identity token
     * vocabulary, preserving structure. Unknown %%vars%% are stripped and a
     * dangling separator dropped.
     *
     * @param string $template      Raw SEOPress template (%%var%% syntax)
     * @param string $context_token Token %%post_title%%/%%term_title%% stands for (may be '')
     * @return string ThinkRank Site Identity template
     */
    private function convert_seopress_identity_template(string $template, string $context_token): string {
        if ($template === '' || strpos($template, '%%') === false) {
            return trim($template);
        }

        $map = [
            '%%sitetitle%%'       => '%site_title%',
            '%%sitename%%'        => '%site_title%',
            '%%tagline%%'         => '%site_description%',
            '%%sitedesc%%'        => '%site_description%',
            '%%sep%%'             => '%sep%',
            '%%search_keywords%%' => '%search_term%',
            '%%archive_title%%'   => '%archive_title%',
            '%%archive_date%%'    => '%date%',
            '%%post_date%%'       => '%date%',
            '%%post_author%%'     => '%author_name%',
        ];
        if ($context_token !== '') {
            $map['%%post_title%%'] = $context_token;
            $map['%%title%%']      = $context_token;
            $map['%%term_title%%'] = $context_token;
        }
        $template = str_replace(array_keys($map), array_values($map), $template);

        // Drop remaining SEOPress vars ThinkRank cannot resolve (%%page%%, …).
        $template = (string) preg_replace('/%%[a-z0-9_-]+%%/i', '', $template);

        $template = (string) preg_replace('/\s{2,}/', ' ', $template);
        $template = trim($template);
        $template = (string) preg_replace('/^(?:%sep%)\s*/', '', $template);
        $template = (string) preg_replace('/\s*(?:%sep%)$/', '', $template);

        return trim($template);
    }

    /**
     * Extract SEOPress's per-post-type title/description templates and robots
     * in the shape migrate_post_type_settings() consumes (Global SEO dialect:
     * %title%/%sitename%/%excerpt%).
     *
     * @param array $titles seopress_titles_option_name value
     * @return array Map of post_type => {title_template, description_template, custom_robots, robots}
     */
    private function extract_seopress_post_type_settings(array $titles): array {
        $single = is_array($titles['seopress_titles_single_titles'] ?? null) ? $titles['seopress_titles_single_titles'] : [];
        $settings = [];

        foreach ($single as $post_type => $pt) {
            if (!is_array($pt)) {
                continue;
            }

            $pt_settings = [];

            $title = $this->convert_seopress_global_template((string) ($pt['title'] ?? ''));
            if ($title !== '') {
                $pt_settings['title_template'] = $title;
            }

            $description = $this->convert_seopress_global_template((string) ($pt['description'] ?? ''));
            if ($description !== '') {
                $pt_settings['description_template'] = $description;
            }

            $directives = [];
            foreach (['noindex', 'nofollow'] as $flag) {
                if (!empty($pt[$flag])) {
                    $directives[] = $flag;
                }
            }
            if (!empty($directives)) {
                $pt_settings['custom_robots'] = true;
                $pt_settings['robots'] = $directives;
            }

            if (!empty($pt_settings)) {
                $settings[$post_type] = $pt_settings;
            }
        }

        // SEOPress keeps attachment noindex as its own flag outside
        // single_titles.
        if (!empty($titles['seopress_titles_attachments_noindex'])) {
            $settings['attachment']['custom_robots'] = true;
            $settings['attachment']['robots'] = array_values(array_unique(array_merge(
                $settings['attachment']['robots'] ?? [],
                ['noindex']
            )));
        }

        return $settings;
    }

    /**
     * Convert a SEOPress template into the Global SEO Pattern_Resolver
     * vocabulary (%title%/%sitename%/%sep%/%excerpt%).
     *
     * @param string $template Raw SEOPress template
     * @return string Converted template
     */
    private function convert_seopress_global_template(string $template): string {
        if ($template === '' || strpos($template, '%%') === false) {
            return trim($template);
        }

        $map = [
            '%%post_title%%'   => '%title%',
            '%%title%%'        => '%title%',
            '%%sitetitle%%'    => '%sitename%',
            '%%sitename%%'     => '%sitename%',
            '%%sep%%'          => '%sep%',
            '%%post_excerpt%%' => '%excerpt%',
            '%%excerpt%%'      => '%excerpt%',
            '%%post_date%%'    => '%date%',
            '%%post_author%%'  => '%author%',
            '%%post_category%%' => '%category%',
        ];
        $template = str_replace(array_keys($map), array_values($map), $template);

        $template = (string) preg_replace('/%%[a-z0-9_-]+%%/i', '', $template);
        $template = (string) preg_replace('/\s+/', ' ', $template);

        return trim($template);
    }

    /**
     * Extract SEOPress's author-archive behaviour for ThinkRank's Author
     * Archives feature. The noindex flag travels in data.noindex_archives.
     *
     * @param array $titles seopress_titles_option_name value
     * @return array Author archive settings
     */
    private function extract_seopress_author_archives(array $titles): array {
        $has_any = isset($titles['seopress_titles_archives_author_disable'])
            || !empty($titles['seopress_titles_archives_author_title'])
            || !empty($titles['seopress_titles_archives_author_desc']);
        if (!$has_any) {
            return [];
        }

        return [
            'enabled'     => empty($titles['seopress_titles_archives_author_disable']),
            'title'       => $this->convert_seopress_identity_template((string) ($titles['seopress_titles_archives_author_title'] ?? ''), '%author_name%'),
            'description' => $this->convert_seopress_identity_template((string) ($titles['seopress_titles_archives_author_desc'] ?? ''), '%author_name%'),
        ];
    }

    /**
     * Extract SEOPress Pro's breadcrumb settings in the canonical
     * breadcrumb_settings shape. No-op on SEOPress Free (option absent).
     *
     * @param array $pro seopress_pro_option_name value
     * @return array Canonical breadcrumb_settings payload
     */
    private function extract_seopress_breadcrumbs(array $pro): array {
        if (empty($pro)) {
            return [];
        }

        $has_any = isset($pro['seopress_breadcrumbs_enable'])
            || !empty($pro['seopress_breadcrumbs_separator'])
            || !empty($pro['seopress_breadcrumbs_i18n_home']);
        if (!$has_any) {
            return [];
        }

        return [
            'enabled'    => !empty($pro['seopress_breadcrumbs_enable']),
            'home_label' => (string) ($pro['seopress_breadcrumbs_i18n_home'] ?? ''),
            'separator'  => trim(html_entity_decode((string) ($pro['seopress_breadcrumbs_separator'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'prefix'     => (string) ($pro['seopress_breadcrumbs_i18n_here'] ?? ''),
        ];
    }

    /**
     * Normalize SEOPress's `seopress_xml_sitemap_option` into the flat, canonical
     * `sitemap_settings` shape the migrator's migrate_sitemap() reads.
     *
     * SEOPress stores a global enable flag, an image-inclusion flag, and per-post-
     * type / per-taxonomy inclusion as { <slug>: { include: '1' } } maps. It
     * exposes no links-per-file, featured-image or ping toggle, so those keys are
     * omitted (the migrator skips absent keys). Returns ['has_data' => false] when
     * there is nothing to migrate.
     *
     * NOTE: SEOPress is not installed in this environment; the key paths below
     * come from SEOPress's documented options schema, not a live capture.
     *
     * @param array $sitemap seopress_xml_sitemap_option value
     * @return array Canonical sitemap_settings payload
     */
    private function normalize_seopress_sitemap(array $sitemap): array {
        if (empty($sitemap)) {
            return ['has_data' => false];
        }

        $post_types = is_array($sitemap['seopress_xml_sitemap_post_types_list'] ?? null)
            ? $sitemap['seopress_xml_sitemap_post_types_list'] : [];
        $taxonomies = is_array($sitemap['seopress_xml_sitemap_taxonomies_list'] ?? null)
            ? $sitemap['seopress_xml_sitemap_taxonomies_list'] : [];

        return [
            'enabled'            => !empty($sitemap['seopress_xml_sitemap_general_enable']),
            'include_images'     => !empty($sitemap['seopress_xml_sitemap_img_enable']),
            'include_posts'      => !empty($post_types['post']['include']),
            'include_pages'      => !empty($post_types['page']['include']),
            'include_categories' => !empty($taxonomies['category']['include']),
            'include_tags'       => !empty($taxonomies['post_tag']['include']),
            'has_data'           => true,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function export_redirections_page(int $page): array {
        $offset = ($page - 1) * $this->chunk_size;

        $redirects = get_posts([
            'post_type'      => 'seopress_404',
            'posts_per_page' => $this->chunk_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'post_status'    => 'any',
        ]);

        if (empty($redirects)) {
            return [];
        }

        $records = [];
        foreach ($redirects as $redirect) {
            $meta = get_post_meta($redirect->ID);

            $records[] = [
                'object_type'   => 'redirection',
                'source_plugin' => $this->plugin_slug,
                'data'          => [],
                'extended'      => [
                    'source_url' => $redirect->post_title ?? '',
                    'target_url' => $meta['_seopress_redirections_value'][0] ?? '',
                    'http_code'  => (int) ($meta['_seopress_redirections_type'][0] ?? 301),
                    'is_regex'   => !empty($meta['_seopress_redirections_enabled_regex'][0]),
                    'enabled'    => !empty($meta['_seopress_redirections_enabled'][0]),
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

        if (empty($value) || strpos($value, '%%') === false) {
            return $value;
        }

        // SEOPress uses %%variable%% syntax similar to Yoast
        $replacements = [
            '%%sitetitle%%'    => get_bloginfo('name'),
            '%%sitename%%'     => get_bloginfo('name'),
            '%%tagline%%'      => get_bloginfo('description'),
            '%%sitedesc%%'     => get_bloginfo('description'),
            '%%sep%%'          => '-',
            '%%currentyear%%'  => gmdate('Y'),
            '%%currentmonth%%' => gmdate('F'),
            '%%currentday%%'   => gmdate('j'),
            '%%currentdate%%'  => gmdate('Y-m-d'),
        ];

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $replacements['%%post_title%%']    = $post->post_title;
                $replacements['%%title%%']         = $post->post_title;
                $replacements['%%post_excerpt%%']  = wp_trim_words($post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55), 55);
                $replacements['%%post_date%%']     = get_the_date('', $post);
                $replacements['%%post_modified_date%%'] = get_the_modified_date('', $post);
                $replacements['%%post_author%%']   = get_the_author_meta('display_name', (int) $post->post_author);

                $post_type_obj = get_post_type_object($post->post_type);
                $replacements['%%post_type%%'] = $post_type_obj ? $post_type_obj->labels->singular_name : '';

                $categories = get_the_category($post_id);
                $replacements['%%post_category%%'] = !empty($categories) ? $categories[0]->name : '';

                $tags = get_the_tags($post_id);
                $replacements['%%post_tag%%'] = !empty($tags) ? $tags[0]->name : '';
            }
        }

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Strip remaining unknown variables
        $value = preg_replace('/%%[a-z0-9_-]+%%/i', '', $value);

        return trim($value);
    }
}
