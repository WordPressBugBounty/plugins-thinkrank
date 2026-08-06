<?php

/**
 * Yoast SEO Exporter
 *
 * Reads Yoast SEO data from postmeta/termmeta/options and normalizes
 * into the canonical snapshot format.
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
 * Yoast Exporter Class
 *
 * @since 2.0.0
 */
class Yoast_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = 'yoast';
        $this->plugin_name = 'Yoast SEO';
        $this->plugin_file = 'wordpress-seo/wp-seo.php';
        $this->meta_key_prefix = '_yoast_wpseo_';
        $this->option_keys = ['wpseo', 'wpseo_titles', 'wpseo_social'];
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

        // Yoast stores term SEO in the wpseo_taxonomy_meta option, not termmeta.
        $term_count = count($this->get_flattened_taxonomy_meta());
        if ($term_count > 0) {
            $types['termmeta'] = $term_count;
        }

        // Check for user meta
        $user_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like('wpseo_') . '%'
            )
        );
        if ($user_count > 0) {
            $types['usermeta'] = $user_count;
        }

        // Settings always available if options exist
        foreach ($this->option_keys as $key) {
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

            $robots = $this->map_yoast_post_robots(
                $meta['_yoast_wpseo_meta-robots-noindex'] ?? '',
                $meta['_yoast_wpseo_meta-robots-nofollow'] ?? ''
            );

            $advanced_robots = $this->parse_yoast_advanced_robots($meta['_yoast_wpseo_meta-robots-adv'] ?? '');

            $records[] = [
                'object_id'     => $post_id,
                'object_type'   => 'post',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'           => $this->convert_template_variables($meta['_yoast_wpseo_title'] ?? '', $post_id),
                    'meta_description'    => $this->convert_template_variables($meta['_yoast_wpseo_metadesc'] ?? '', $post_id),
                    'focus_keyword'       => $meta['_yoast_wpseo_focuskw'] ?? '',
                    // Full keyword list (primary + Yoast Premium's additional
                    // keyphrases) for ThinkRank's keyword array — without this
                    // the migrator falls back to the single focus_keyword and
                    // Premium keyphrases never reach the post.
                    'focus_keywords'      => array_values(array_filter(
                        array_merge(
                            [(string) ($meta['_yoast_wpseo_focuskw'] ?? '')],
                            $this->parse_yoast_additional_keywords($meta['_yoast_wpseo_focuskeywords'] ?? '')
                        ),
                        static fn($keyword) => trim((string) $keyword) !== ''
                    )),
                    'canonical_url'       => $meta['_yoast_wpseo_canonical'] ?? '',
                    'noindex'             => $robots['noindex'],
                    'nofollow'            => $robots['nofollow'],
                    'noarchive'           => $advanced_robots['noarchive'],
                    'noimageindex'        => $advanced_robots['noimageindex'],
                    'nosnippet'           => $advanced_robots['nosnippet'],
                    'og_title'            => $this->convert_template_variables($meta['_yoast_wpseo_opengraph-title'] ?? '', $post_id),
                    'og_description'      => $this->convert_template_variables($meta['_yoast_wpseo_opengraph-description'] ?? '', $post_id),
                    'og_image'            => $meta['_yoast_wpseo_opengraph-image'] ?? '',
                    'twitter_title'       => $this->convert_template_variables($meta['_yoast_wpseo_twitter-title'] ?? '', $post_id),
                    'twitter_description' => $this->convert_template_variables($meta['_yoast_wpseo_twitter-description'] ?? '', $post_id),
                    'twitter_image'       => $meta['_yoast_wpseo_twitter-image'] ?? '',
                    'primary_category'    => (int) ($meta['_yoast_wpseo_primary_category'] ?? 0),
                    'schema_type'         => $meta['_yoast_wpseo_schema_page_type'] ?? '',
                    // Yoast "cornerstone content" maps to ThinkRank pillar content.
                    'pillar_content'      => (int) ($meta['_yoast_wpseo_is_cornerstone'] ?? 0),
                ],
                'extended' => [
                    'is_cornerstone'            => (bool) ($meta['_yoast_wpseo_is_cornerstone'] ?? false),
                    'focus_keywords_additional' => $this->parse_yoast_additional_keywords($meta['_yoast_wpseo_focuskeywords'] ?? ''),
                    'breadcrumb_title'          => $meta['_yoast_wpseo_bctitle'] ?? '',
                    'advanced_robots'           => $meta['_yoast_wpseo_meta-robots-adv'] ?? '',
                    'schema_article_type'       => $meta['_yoast_wpseo_schema_article_type'] ?? '',
                    'og_image_id'               => $meta['_yoast_wpseo_opengraph-image-id'] ?? '',
                    'twitter_image_id'          => $meta['_yoast_wpseo_twitter-image-id'] ?? '',
                    'estimated_reading_time'    => (int) ($meta['_yoast_wpseo_estimated-reading-time-minutes'] ?? 0),
                    'wordproof_timestamp'       => $meta['_yoast_wpseo_wordproof_timestamp'] ?? '',
                    'inclusive_language_score'   => $meta['_yoast_wpseo_inclusive-language-score'] ?? '',
                    'linkdex'                   => $meta['_yoast_wpseo_linkdex'] ?? '',
                    'content_score'             => $meta['_yoast_wpseo_content_score'] ?? '',
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_termmeta_page(int $page): array {
        // Yoast keeps term SEO in the wpseo_taxonomy_meta option
        // ($option[$taxonomy][$term_id] = [...]), NOT the termmeta table.
        // Keys are wpseo_*-prefixed; the term description is wpseo_desc (not
        // wpseo_metadesc) and wpseo_noindex is a string (default/index/noindex).
        $flat = $this->get_flattened_taxonomy_meta();
        if (empty($flat)) {
            return [];
        }

        $offset = ($page - 1) * $this->chunk_size;
        $slice = array_slice($flat, $offset, $this->chunk_size, true);
        if (empty($slice)) {
            return [];
        }

        $records = [];
        foreach ($slice as $term_id => $meta) {
            $term_id = (int) $term_id;
            $noindex = (($meta['wpseo_noindex'] ?? 'default') === 'noindex') ? 1 : 0;

            $records[] = [
                'object_id'     => $term_id,
                'object_type'   => 'term',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'        => $this->convert_template_variables($meta['wpseo_title'] ?? ''),
                    'meta_description' => $this->convert_template_variables($meta['wpseo_desc'] ?? ''),
                    'focus_keyword'    => $meta['wpseo_focuskw'] ?? '',
                    'canonical_url'    => $meta['wpseo_canonical'] ?? '',
                    'noindex'          => $noindex,
                    'nofollow'         => 0,
                    'og_title'         => $this->convert_template_variables($meta['wpseo_opengraph-title'] ?? ''),
                    'og_description'   => $this->convert_template_variables($meta['wpseo_opengraph-description'] ?? ''),
                ],
                'extended' => [
                    'breadcrumb_title'    => $meta['wpseo_bctitle'] ?? '',
                    'og_image'            => $meta['wpseo_opengraph-image'] ?? '',
                    'twitter_title'       => $meta['wpseo_twitter-title'] ?? '',
                    'twitter_description' => $meta['wpseo_twitter-description'] ?? '',
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_usermeta_page(int $page): array {
        global $wpdb;

        $offset = ($page - 1) * $this->chunk_size;

        $user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY user_id ASC LIMIT %d OFFSET %d",
                $wpdb->esc_like('wpseo_') . '%',
                $this->chunk_size,
                $offset
            )
        );

        if (empty($user_ids)) {
            return [];
        }

        $records = [];
        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;

            // Author title/description go in canonical `data` (seo_title /
            // meta_description) so the plugin-agnostic migrator writes them to
            // the `_thinkrank_seo_title` / `_thinkrank_meta_description` USER meta
            // that Author_Archives_Manager reads. ThinkRank consumes those values
            // verbatim, so resolve Yoast template tags to literals here — seeding
            // %%name%% with the author display name (the generic converter only
            // knows post context).
            $display_name = (string) get_the_author_meta('display_name', $user_id);
            $author_title = (string) (get_user_meta($user_id, 'wpseo_title', true) ?: '');
            $author_desc  = (string) (get_user_meta($user_id, 'wpseo_metadesc', true) ?: '');
            $author_title = $this->convert_template_variables(str_replace('%%name%%', $display_name, $author_title));
            $author_desc  = $this->convert_template_variables(str_replace('%%name%%', $display_name, $author_desc));

            $records[] = [
                'object_id'     => $user_id,
                'object_type'   => 'user',
                'source_plugin' => $this->plugin_slug,
                'data'          => [
                    'seo_title'        => $author_title,
                    'meta_description' => $author_desc,
                ],
                'extended'      => [
                    'author_noindex'     => (bool) get_user_meta($user_id, 'wpseo_noindex_author', true),
                    'social_profiles'    => [
                        'facebook'  => get_user_meta($user_id, 'facebook', true) ?: '',
                        'twitter'   => get_user_meta($user_id, 'twitter', true) ?: '',
                        'linkedin'  => get_user_meta($user_id, 'linkedin', true) ?: '',
                        'instagram' => get_user_meta($user_id, 'instagram', true) ?: '',
                        'youtube'   => get_user_meta($user_id, 'youtube_url', true) ?: '',
                        'pinterest' => get_user_meta($user_id, 'pinterest_url', true) ?: '',
                        'wikipedia' => get_user_meta($user_id, 'wikipedia_url', true) ?: '',
                    ],
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_settings(): array {
        // get_option()'s [] default only applies to a missing row; a row that
        // exists but holds a scalar/false (reset, legacy, corrupted) would flow
        // into the array-typed helpers below and throw a TypeError. Normalize.
        $wpseo = get_option('wpseo', []);
        $wpseo = is_array($wpseo) ? $wpseo : [];
        $titles = get_option('wpseo_titles', []);
        $titles = is_array($titles) ? $titles : [];
        $social = get_option('wpseo_social', []);
        $social = is_array($social) ? $social : [];

        return [
            [
                'type'          => 'settings',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'separator'            => $this->map_yoast_separator($titles['separator'] ?? 'sc-dash'),
                    'homepage_title'       => $this->convert_template_variables($titles['title-home-wpseo'] ?? ''),
                    'homepage_description' => $this->convert_template_variables($titles['metadesc-home-wpseo'] ?? ''),
                    'organization_name'    => $this->yoast_identity($titles, $wpseo, 'company_name'),
                    'organization_logo'    => $this->yoast_identity($titles, $wpseo, 'company_logo'),
                    // Schema `alternateName` for the site.
                    'alternate_name'       => $this->yoast_identity($titles, $wpseo, 'alternate_website_name'),
                    // Knowledge Graph entity: Yoast's company_or_person, with the
                    // name taken from whichever side it points at.
                    'knowledge_graph'      => $this->extract_yoast_knowledge_graph($titles, $wpseo),
                    // Yoast's IndexNow key is a public verification token served
                    // at /{key}.txt, not an account secret — carrying it over
                    // avoids re-verifying the site with IndexNow.
                    'instant_indexing'     => [
                        'api_key' => (string) ($wpseo['index_now_key'] ?? ''),
                    ],
                    'social_profiles'      => [
                        'facebook'  => $social['facebook_site'] ?? '',
                        'twitter'   => $social['twitter_site'] ?? '',
                        'instagram' => $social['instagram_url'] ?? '',
                        'linkedin'  => $social['linkedin_url'] ?? '',
                        'youtube'   => $social['youtube_url'] ?? '',
                        'pinterest' => $social['pinterest_url'] ?? '',
                        'wikipedia' => $social['wikipedia_url'] ?? '',
                    ],
                    'noindex_archives' => [
                        'date'   => !empty($titles['noindex-archive-wpseo']),
                        'author' => !empty($titles['noindex-author-wpseo']),
                    ],
                    // Default Twitter card size for ThinkRank's Social Meta
                    // settings.
                    'twitter_card_type' => (string) ($social['twitter_card_type'] ?? ''),
                    // Site-wide social defaults with direct ThinkRank homes.
                    'social_defaults'   => [
                        'og_default_image' => (string) ($social['og_default_image'] ?? ''),
                    ],
                ],
                'extended' => [
                    'breadcrumb_settings' => [
                        'enabled'    => !empty($titles['breadcrumbs-enable']),
                        'home_label' => $titles['breadcrumbs-home'] ?? 'Home',
                        'separator'  => $titles['breadcrumbs-sep'] ?? '»',
                        'prefix'     => (string) ($titles['breadcrumbs-prefix'] ?? ''),
                    ],
                    // Webmaster-tools verification codes. Pinterest is applied
                    // (the one ThinkRank renders); the rest is preserved and
                    // gates /import/cleanup.
                    'webmaster_tools' => array_filter([
                        'google'    => (string) ($wpseo['googleverify'] ?? ''),
                        'bing'      => (string) ($wpseo['msverify'] ?? ''),
                        'yandex'    => (string) ($wpseo['yandexverify'] ?? ''),
                        'baidu'     => (string) ($wpseo['baiduverify'] ?? ''),
                        'pinterest' => (string) ($social['pinterestverify'] ?? ''),
                    ]),
                    'post_type_settings'   => $this->extract_post_type_settings($titles),
                    'taxonomy_settings'    => $this->extract_taxonomy_settings($titles),
                    // Per-context title formats in ThinkRank's SITE IDENTITY token
                    // vocabulary, which is a different dialect from the Global SEO
                    // one used by post_type_settings above.
                    'title_formats'        => $this->extract_yoast_title_formats($titles),
                    // Author archive behaviour (Author Archives feature).
                    'author_archives'      => $this->extract_yoast_author_archives($titles),
                    // Role Manager assignments (live on the roles, not in wpseo_*).
                    'role_capabilities'    => $this->extract_role_capabilities('wpseo_'),
                    // News/Video sitemap post types, when the Yoast News SEO /
                    // Video SEO add-ons are installed.
                    'publisher_sitemaps'   => $this->extract_yoast_publisher_sitemaps(),
                    'og_frontpage_title'   => $social['og_frontpage_title'] ?? '',
                    'og_frontpage_desc'    => $social['og_frontpage_desc'] ?? '',
                    'og_frontpage_image'   => $social['og_frontpage_image'] ?? '',
                    // Yoast's XML sitemap is otherwise filter-driven: per-type
                    // inclusion follows the noindex settings (already captured in
                    // post_type_settings / taxonomy_settings) and it exposes no
                    // links/images/ping options. The one real toggle is the global
                    // enable flag; only flag has_data when it is actually present so
                    // the migrator does not disable ThinkRank's sitemap by default.
                    'sitemap_settings'     => [
                        'enabled'  => !empty($wpseo['enable_xml_sitemap']),
                        'has_data' => array_key_exists('enable_xml_sitemap', $wpseo),
                    ],
                ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function export_redirections_page(int $page): array {
        // Yoast Premium stores redirections in a custom table
        global $wpdb;

        $table_name = $wpdb->prefix . 'yoast_seo_redirects';
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

        if (empty($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'object_type'   => 'redirection',
                'source_plugin' => $this->plugin_slug,
                'data'          => [],
                'extended'      => [
                    'source_url' => $row['origin'] ?? '',
                    'target_url' => $row['url'] ?? '',
                    'http_code'  => (int) ($row['type'] ?? 301),
                    'is_regex'   => !empty($row['format'] ?? '') && $row['format'] === 'regex',
                    'enabled'    => true,
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

        $replacements = [
            '%%sitename%%'    => get_bloginfo('name'),
            '%%sitedesc%%'    => get_bloginfo('description'),
            '%%sep%%'         => '-',
            '%%page%%'        => '',
            '%%pagenumber%%'  => '',
            '%%pagetotal%%'   => '',
            '%%currentyear%%' => gmdate('Y'),
            '%%currentdate%%' => gmdate('Y-m-d'),
            '%%currentmonth%%' => gmdate('F'),
            '%%currentday%%'  => gmdate('j'),
        ];

        // Add post-specific replacements
        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $replacements['%%title%%']    = $post->post_title;
                $replacements['%%excerpt%%']  = wp_trim_words($post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55), 55);
                $replacements['%%date%%']     = get_the_date('', $post);
                $replacements['%%modified%%'] = get_the_modified_date('', $post);
                $replacements['%%id%%']       = (string) $post_id;
                $replacements['%%name%%']     = get_the_author_meta('display_name', (int) $post->post_author);

                // Post type
                $post_type_obj = get_post_type_object($post->post_type);
                $replacements['%%pt_single%%'] = $post_type_obj ? $post_type_obj->labels->singular_name : '';
                $replacements['%%pt_plural%%'] = $post_type_obj ? $post_type_obj->labels->name : '';

                // Category
                $categories = get_the_category($post_id);
                $replacements['%%category%%']         = !empty($categories) ? $categories[0]->name : '';
                $replacements['%%primary_category%%'] = !empty($categories) ? $categories[0]->name : '';

                // Tags
                $tags = get_the_tags($post_id);
                $replacements['%%tag%%'] = !empty($tags) ? $tags[0]->name : '';
            }
        }

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Strip any remaining unknown variables
        $value = preg_replace('/%%[a-z0-9_-]+%%/i', '', $value);

        return trim($value);
    }

    /**
     * Translate a Yoast title/description *template* into ThinkRank pattern syntax.
     *
     * Unlike convert_template_variables() — which resolves a per-post value to a
     * literal string — post-type and taxonomy templates must stay templates so the
     * frontend can resolve them per request. Yoast uses double-percent tokens
     * (`%%title%%`); ThinkRank's Pattern_Resolver uses single-percent tokens
     * (`%title%`) with a smaller vocabulary. Map the tokens ThinkRank can resolve
     * and strip any Yoast token it has no equivalent for, so an imported template
     * never renders stray `%` characters.
     *
     * @param mixed $template Raw Yoast template (e.g. "%%title%% %%sep%% %%sitename%%").
     * @return string ThinkRank pattern (e.g. "%title% %sep% %sitename%").
     */
    private function convert_template_pattern(mixed $template): string {
        // Foreign data first: booleans/arrays in the source plugin's options
        // must degrade to '' here, not fatal the migration (see abstract).
        $template = $this->stringify_template_value($template);

        if ($template === '' || strpos($template, '%%') === false) {
            return $template;
        }

        // Yoast token => ThinkRank Pattern_Resolver token. Only tokens the
        // resolver understands (see Pattern_Resolver::placeholders_for()) are
        // mapped; everything else is stripped below.
        $map = [
            '%%title%%'            => '%title%',
            '%%sitename%%'         => '%sitename%',
            '%%sep%%'              => '%sep%',
            '%%excerpt%%'          => '%excerpt%',
            '%%excerpt_only%%'     => '%excerpt%',
            '%%date%%'             => '%date%',
            '%%modified%%'         => '%modified%',
            '%%name%%'             => '%author%',
            '%%category%%'         => '%category%',
            '%%primary_category%%' => '%category%',
        ];

        $template = str_replace(array_keys($map), array_values($map), $template);

        // Drop any remaining Yoast tokens ThinkRank cannot resolve
        // (e.g. %%page%%, %%sitedesc%%, %%pt_single%%, %%tag%%).
        $template = preg_replace('/%%[a-z0-9_-]+%%/i', '', $template);

        // Tidy whitespace left by removed tokens. Pattern_Resolver also collapses
        // whitespace/separators at render time, so this is mostly cosmetic.
        $template = preg_replace('/\s+/', ' ', $template);

        return trim($template);
    }

    /**
     * Parse Yoast's `_yoast_wpseo_meta-robots-adv` value.
     *
     * Stored as a comma-separated list of advanced directives
     * (e.g. "noimageindex,nosnippet"), or "-" / "none" when no
     * extras are selected.
     *
     * @param mixed $value Raw meta value from Yoast
     * @return array{noarchive:int,noimageindex:int,nosnippet:int}
     */
    private function parse_yoast_advanced_robots(mixed $value): array {
        $result = ['noarchive' => 0, 'noimageindex' => 0, 'nosnippet' => 0];

        // Foreign data first: non-string meta degrades to '' → no extras (see abstract).
        $value = $this->stringify_template_value($value);

        if ($value === '' || $value === '-' || $value === 'none') {
            return $result;
        }

        $tokens = array_map('trim', explode(',', $value));
        foreach ($tokens as $token) {
            if (isset($result[$token])) {
                $result[$token] = 1;
            }
        }

        return $result;
    }

    /**
     * Map Yoast's tri-state post robots values to canonical 0/1 flags.
     *
     * Yoast stores `_yoast_wpseo_meta-robots-noindex` as 0 (post-type default),
     * 1 (noindex) or 2 (index) — so ONLY an explicit 1 means noindex; 2 (index)
     * and 0 (default) must not. `_yoast_wpseo_meta-robots-nofollow` is a plain
     * 0/1 boolean (1 = nofollow).
     *
     * @param mixed $noindex_raw  Raw meta-robots-noindex value
     * @param mixed $nofollow_raw Raw meta-robots-nofollow value
     * @return array{noindex:int,nofollow:int}
     */
    private function map_yoast_post_robots($noindex_raw, $nofollow_raw): array {
        return [
            'noindex'  => ((int) $noindex_raw === 1) ? 1 : 0,
            'nofollow' => ((int) $nofollow_raw === 1) ? 1 : 0,
        ];
    }

    /**
     * Flatten the wpseo_taxonomy_meta option to a term_id => meta map.
     *
     * The option is nested as $option[$taxonomy][$term_id] = [...]. term_id is
     * globally unique in WordPress, so keying by it is safe and gives a stable,
     * paginatable order.
     *
     * @return array<int,array> Map of term_id => Yoast term meta array
     */
    private function get_flattened_taxonomy_meta(): array {
        $tax_meta = get_option('wpseo_taxonomy_meta', []);
        $tax_meta = is_array($tax_meta) ? $tax_meta : [];
        if (!is_array($tax_meta)) {
            return [];
        }

        $flat = [];
        foreach ($tax_meta as $terms) {
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term_id => $meta) {
                if (is_array($meta) && (int) $term_id > 0) {
                    $flat[(int) $term_id] = $meta;
                }
            }
        }

        ksort($flat);

        return $flat;
    }

    /**
     * Convert a Yoast separator slug (e.g. "sc-dash") to its literal character.
     *
     * Yoast stores the title separator as a slug, not the glyph. An unknown or
     * already-literal value is returned unchanged.
     *
     * @param string $separator Raw separator value from wpseo_titles
     * @return string Literal separator character
     */
    /**
     * Read a Yoast site-identity value, tolerating where the version stores it.
     *
     * Yoast moved company_name / company_logo / company_or_person / person_name /
     * website_name / alternate_website_name from the `wpseo` option into
     * `wpseo_titles` (around Yoast 14). Reading only `wpseo` — as this exporter
     * originally did — silently returned nothing on every modern install, so the
     * organization name and logo never migrated. Check the modern location
     * first, then fall back for older installs.
     *
     * @param array  $titles `wpseo_titles` option
     * @param array  $wpseo  `wpseo` option
     * @param string $key    Setting key
     * @return string Value, or '' when absent from both
     */
    private function yoast_identity(array $titles, array $wpseo, string $key): string {
        foreach ([$titles, $wpseo] as $source) {
            if (!empty($source[$key]) && is_scalar($source[$key])) {
                return (string) $source[$key];
            }
        }

        return '';
    }

    /**
     * Extract Yoast's Knowledge Graph entity into ThinkRank's vocabulary.
     *
     * Yoast stores `company_or_person` ('company' | 'person') alongside separate
     * name fields; ThinkRank models the same split as organization/person.
     *
     * @param array $titles `wpseo_titles` option
     * @param array $wpseo  `wpseo` option
     * @return array{type:string,name:string}
     */
    private function extract_yoast_knowledge_graph(array $titles, array $wpseo): array {
        $raw = strtolower(trim($this->yoast_identity($titles, $wpseo, 'company_or_person')));

        if ($raw === 'person') {
            return [
                'type' => 'person',
                'name' => $this->yoast_identity($titles, $wpseo, 'person_name'),
            ];
        }

        if ($raw === 'company') {
            return [
                'type' => 'organization',
                'name' => $this->yoast_identity($titles, $wpseo, 'company_name'),
            ];
        }

        // Yoast also allows "neither" (empty), which the migrator skips.
        return ['type' => '', 'name' => ''];
    }

    /**
     * Map Yoast's per-context title formats onto ThinkRank's Site Identity keys.
     *
     * A DIFFERENT token dialect from `post_type_settings`: the Site Identity
     * renderer resolves %site_title%/%post_title%/%category_title%/… while the
     * Global SEO renderer resolves %title%/%sitename%/%excerpt%. Converting with
     * the wrong one renders the token literally on the front end.
     *
     * @param array $titles `wpseo_titles` option
     * @return array Map of ThinkRank title-format key => converted template
     */
    private function extract_yoast_title_formats(array $titles): array {
        // ThinkRank key => [Yoast key, token Yoast's %%title%%/%%term_title%%
        // stands for in that context].
        $map = [
            'homepage_title' => ['title-home-wpseo', ''],
            'post_title'     => ['title-post', '%post_title%'],
            'page_title'     => ['title-page', '%page_title%'],
            'category_title' => ['title-tax-category', '%category_title%'],
            'tag_title'      => ['title-tax-post_tag', '%tag_title%'],
            'search_title'   => ['title-search-wpseo', '%search_term%'],
            'archive_title'  => ['title-archive-wpseo', '%archive_title%'],
            'author_title'   => ['title-author-wpseo', '%author_name%'],
        ];

        $formats = [];
        foreach ($map as $tr_key => [$yoast_key, $context_token]) {
            $raw = (string) ($titles[$yoast_key] ?? '');
            if ($raw === '') {
                continue;
            }

            $converted = $this->convert_identity_pattern($raw, $context_token);
            if ($converted !== '') {
                $formats[$tr_key] = $converted;
            }
        }

        return $formats;
    }

    /**
     * Convert a Yoast title template into ThinkRank's Site Identity token
     * vocabulary, preserving structure.
     *
     * Tokens ThinkRank cannot resolve (%%page%%, %%pt_single%%, %%currentyear%%)
     * are stripped so they never render literally, and a separator left dangling
     * by that strip is dropped rather than rendering as a leading/trailing dash.
     *
     * @param string $template      Raw Yoast template
     * @param string $context_token Token %%title%%/%%term_title%% stands for (may be '')
     * @return string ThinkRank Site Identity template
     */
    private function convert_identity_pattern(string $template, string $context_token): string {
        if ($template === '' || strpos($template, '%%') === false) {
            return trim($template);
        }

        $map = [
            '%%sitename%%'     => '%site_title%',
            '%%sitedesc%%'     => '%site_description%',
            '%%sep%%'          => '%sep%',
            '%%date%%'         => '%date%',
            '%%searchphrase%%' => '%search_term%',
            '%%name%%'         => '%author_name%',
            // Yoast's own archive/term variables, which ThinkRank resolves under
            // the same names.
            '%%archive_title%%' => '%archive_title%',
            '%%category%%'      => '%category_title%',
            '%%tag%%'           => '%tag_title%',
            // Rank Math leftovers. Yoast's own Rank Math importer rewrites title
            // settings with a blind str_replace('%', '%%') and NO token
            // translation, so a site that came RankMath -> Yoast carries Rank
            // Math tokens wrapped in Yoast's double-percent syntax — tokens Yoast
            // itself cannot resolve either. Map them rather than stripping them,
            // or the format silently loses its search term / term name.
            '%%search_query%%' => '%search_term%',
        ];
        if ($context_token !== '') {
            $map['%%title%%']      = $context_token;
            $map['%%term_title%%'] = $context_token;
            $map['%%term%%']       = $context_token; // Rank Math leftover (see above)
        }
        $template = str_replace(array_keys($map), array_values($map), $template);

        // Drop any remaining Yoast token ThinkRank cannot resolve.
        $template = preg_replace('/%%[a-z0-9_-]+%%/i', '', $template);

        $template = preg_replace('/\s{2,}/', ' ', (string) $template);
        $template = trim((string) $template);
        $template = preg_replace('/^(?:%sep%)\s*/', '', $template);
        $template = preg_replace('/\s*(?:%sep%)$/', '', (string) $template);

        return trim((string) $template);
    }

    /**
     * Extract Yoast's author-archive behaviour for ThinkRank's Author Archives
     * feature (author_archives_enabled / _title / _meta_desc).
     *
     * The archive noindex flag travels separately in `data.noindex_archives`.
     *
     * @param array $titles `wpseo_titles` option
     * @return array Author archive settings
     */
    private function extract_yoast_author_archives(array $titles): array {
        return [
            // Yoast stores the NEGATIVE `disable-author`; ThinkRank stores the
            // positive `enabled`, so invert. Cast loosely — Yoast has stored this
            // as both a real boolean and the string 'on'.
            'enabled'     => !filter_var($titles['disable-author'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'title'       => $this->convert_identity_pattern((string) ($titles['title-author-wpseo'] ?? ''), '%author_name%'),
            'description' => $this->convert_identity_pattern((string) ($titles['metadesc-author-wpseo'] ?? ''), '%author_name%'),
        ];
    }

    /**
     * Extract News/Video sitemap post types from the Yoast News SEO and Video
     * SEO add-ons, when installed. Both are separate paid plugins, so their
     * options are usually absent — an empty result means "nothing to migrate".
     *
     * @return array News/Video post type lists (absent keys omitted)
     */
    private function extract_yoast_publisher_sitemaps(): array {
        $out = [];

        $news = get_option('wpseo_news', []);
        $news = is_array($news) ? $news : [];
        if (is_array($news) && !empty($news['news_sitemap_include_post_types'])) {
            // Yoast News stores this as [post_type => 'on'] rather than a list.
            $types = $news['news_sitemap_include_post_types'];
            $out['news_post_types'] = is_array($types) && $this->is_assoc_toggle_map($types)
                ? array_keys(array_filter($types))
                : array_values(array_map('strval', (array) $types));
        }

        $video = get_option('wpseo_video', []);
        $video = is_array($video) ? $video : [];
        if (is_array($video) && !empty($video['videositemap_posttypes'])) {
            $types = $video['videositemap_posttypes'];
            $out['video_post_types'] = is_array($types) && $this->is_assoc_toggle_map($types)
                ? array_keys(array_filter($types))
                : array_values(array_map('strval', (array) $types));
        }

        return $out;
    }

    /**
     * Whether an array is a [slug => truthy] toggle map rather than a plain list
     * of slugs. Yoast's add-ons have used both shapes across versions.
     *
     * @param array $value Candidate array
     * @return bool
     */
    private function is_assoc_toggle_map(array $value): bool {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function map_yoast_separator(mixed $separator): string {
        // Foreign data first: a non-string separator setting degrades to '' so
        // the default fallback applies instead of fatalling (see abstract).
        $separator = $this->stringify_template_value($separator);

        $map = [
            'sc-dash'   => '-',
            'sc-ndash'  => '–',
            'sc-mdash'  => '—',
            'sc-colon'  => ':',
            'sc-middot' => '·',
            'sc-bull'   => '•',
            'sc-star'   => '*',
            'sc-smstar' => '⋆',
            'sc-pipe'   => '|',
            'sc-tilde'  => '~',
            'sc-laquo'  => '«',
            'sc-raquo'  => '»',
            'sc-lt'     => '>',
            'sc-gt'     => '<',
        ];

        return $map[$separator] ?? ($separator !== '' ? $separator : '-');
    }

    /**
     * Parse Yoast's additional focus keywords JSON
     *
     * @param mixed $json JSON string from _yoast_wpseo_focuskeywords
     * @return array Array of additional keyword strings
     */
    private function parse_yoast_additional_keywords(mixed $json): array {
        // Foreign data first: non-string meta degrades to '' → no keywords (see abstract).
        $json = $this->stringify_template_value($json);

        if (empty($json)) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $keywords = [];
        foreach ($decoded as $item) {
            if (isset($item['keyword']) && !empty($item['keyword'])) {
                $keywords[] = $item['keyword'];
            }
        }

        return $keywords;
    }

    /**
     * Extract post type SEO settings from Yoast titles option
     *
     * @param array $titles Yoast wpseo_titles option
     * @return array Post type settings
     */
    private function extract_post_type_settings(array $titles): array {
        $settings = [];
        $post_types = get_post_types(['public' => true], 'names');

        // Yoast's link suggestions is a single GLOBAL toggle, not per-post-type
        // like Rank Math's and ThinkRank's. Only a deliberate "off" carries
        // information (both plugins default it on), so an off is fanned out to
        // every public type and an on is left alone.
        $wpseo = get_option('wpseo', []);
        $link_suggestions_off = is_array($wpseo)
            && array_key_exists('enable_link_suggestions', $wpseo)
            && !$wpseo['enable_link_suggestions'];

        foreach ($post_types as $pt) {
            $pt_settings = [];
            if ($link_suggestions_off) {
                $pt_settings['link_suggestions'] = false;
            }
            if (isset($titles["title-{$pt}"])) {
                $pt_settings['title_template'] = $this->convert_template_pattern($titles["title-{$pt}"]);
            }
            if (isset($titles["metadesc-{$pt}"])) {
                $pt_settings['description_template'] = $this->convert_template_pattern($titles["metadesc-{$pt}"]);
            }
            if (isset($titles["noindex-{$pt}"])) {
                $pt_settings['noindex'] = (bool) $titles["noindex-{$pt}"];
            }
            if (!empty($pt_settings)) {
                $settings[$pt] = $pt_settings;
            }
        }

        return $settings;
    }

    /**
     * Extract taxonomy SEO settings from Yoast titles option
     *
     * @param array $titles Yoast wpseo_titles option
     * @return array Taxonomy settings
     */
    private function extract_taxonomy_settings(array $titles): array {
        $settings = [];
        $taxonomies = get_taxonomies(['public' => true], 'names');

        foreach ($taxonomies as $tax) {
            $tax_settings = [];
            if (isset($titles["title-tax-{$tax}"])) {
                $tax_settings['title_template'] = $this->convert_template_pattern($titles["title-tax-{$tax}"]);
            }
            if (isset($titles["metadesc-tax-{$tax}"])) {
                $tax_settings['description_template'] = $this->convert_template_pattern($titles["metadesc-tax-{$tax}"]);
            }
            if (isset($titles["noindex-tax-{$tax}"])) {
                $tax_settings['noindex'] = (bool) $titles["noindex-tax-{$tax}"];
            }
            if (!empty($tax_settings)) {
                $settings[$tax] = $tax_settings;
            }
        }

        return $settings;
    }
}
