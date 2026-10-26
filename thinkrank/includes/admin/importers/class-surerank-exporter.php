<?php

/**
 * SureRank Exporter
 *
 * Reads SureRank data and normalizes it into the canonical snapshot format
 * (#1051).
 *
 * SureRank keeps an object's SEO in grouped meta rows, the same set on posts,
 * terms and users: `surerank_settings_general` (an array holding page_title,
 * page_description, canonical_url and focus_keyword), `surerank_settings_social`
 * (the Facebook and X overrides), one scalar row per robots flag
 * (`surerank_settings_post_no_index` / `_no_follow` / `_no_archive`, each
 * 'yes' or 'no') and `surerank_settings_schemas`. Only the groups a user
 * touched are stored; everything else is filled from the global settings when
 * the page renders.
 *
 * The global settings are one option, `surerank_settings`. There is a single
 * title template and a single description template for every post, page,
 * term and author archive, a separate homepage pair, robots lists naming post
 * types and taxonomies, and the social and crawl switches. The Organization
 * or Person the site represents, with its social profiles, is the onboarding
 * option `surerank_settings_onboarding`; a hand-written robots.txt is
 * `surerank_robots_txt_content`.
 *
 * Templates use `%var%` tokens resolved per request (`%title%`, `%separator%`,
 * `%site_name%`, …). An unknown token resolves to nothing.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.16.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SureRank Exporter Class
 *
 * @since 2.16.0
 */
class SureRank_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Source plugin slug.
     */
    public const SLUG = 'surerank';

    /**
     * Prefix of every per-object row SureRank writes for SEO (post, term and
     * user meta alike). Its other rows (`surerank_seo_checks`,
     * `surerank_post_optimized_at`, …) are its own bookkeeping and stay out.
     */
    public const META_PREFIX = 'surerank_settings_';

    /**
     * Global settings option (SureRank's SURERANK_SETTINGS).
     */
    public const OPTION = 'surerank_settings';

    /**
     * Site entity and social profiles collected by SureRank's onboarding.
     */
    public const ONBOARDING_OPTION = 'surerank_settings_onboarding';

    /**
     * Hand-written robots.txt (SureRank's SURERANK_ROBOTS_TXT_CONTENT).
     */
    public const ROBOTS_TXT_OPTION = 'surerank_robots_txt_content';

    /**
     * Per-object meta rows, by SureRank's group name.
     */
    private const GENERAL_META = self::META_PREFIX . 'general';
    private const SOCIAL_META = self::META_PREFIX . 'social';
    private const SCHEMAS_META = self::META_PREFIX . 'schemas';

    /**
     * The schema row SureRank writes for an object whose schemas were never
     * changed, as stored. Skipped in SQL; any other spelling of an empty
     * diff is caught when the row is decoded.
     *
     * @since 2.16.0
     */
    private const EMPTY_SCHEMAS_ROW = 'a:2:{s:7:"schemas";a:0:{}s:16:"excluded_schemas";a:0:{}}';

    /**
     * Schema rows read per query when counting objects with schema changes.
     *
     * @since 2.16.0
     */
    private const SCHEMA_ROWS_PAGE = 500;

    /**
     * SureRank's defaults for the settings this exporter reads
     * (Defaults::$global_*_defaults in SureRank 1.10). The option only holds
     * what SureRank's admin screen saved, and SureRank fills the rest from
     * these when the page renders, so an install whose settings were never
     * saved behaves exactly as this array says.
     */
    private const DEFAULTS = [
        'separator'                                => '-',
        'page_title'                               => '%title% - %site_name%',
        'page_description'                         => '%content%',
        'fallback_image'                           => '',
        'home_page_title'                          => '%site_name% - %tagline%',
        'home_page_description'                    => '%tagline%',
        'home_page_facebook_image_url'             => '',
        'home_page_facebook_title'                 => '',
        'home_page_facebook_description'           => '',
        'home_page_twitter_image_url'              => '',
        'home_page_twitter_title'                  => '',
        'home_page_twitter_description'            => '',
        'index_home_page_paginated_pages'          => true,
        'open_graph_tags'                          => true,
        'facebook_meta_tags'                       => true,
        'twitter_meta_tags'                        => true,
        'facebook_page_url'                        => '',
        'facebook_author_fallback'                 => '',
        'twitter_card_type'                        => 'summary_large_image',
        'twitter_profile_username'                 => '',
        'twitter_profile_fallback'                 => '',
        'author_archive'                           => false,
        'date_archive'                             => false,
        'noindex_paginated_pages'                  => false,
        'addlink_to_source_below_feed_entries'     => true,
        'remove_global_comments_feed'              => false,
        'remove_post_authors_feed'                 => false,
        'remove_post_types_feed'                   => false,
        'remove_category_feed'                     => false,
        'remove_tag_feeds'                         => false,
        'remove_custom_taxonomy_feeds'             => false,
        'remove_search_results_feed'               => false,
        'remove_atom_rdf_feeds'                    => false,
        'enable_xml_sitemap'                       => true,
        'enable_xml_image_sitemap'                 => true,
        'redirect_attachment_pages_to_post_parent' => true,
        'auto_set_image_title'                     => true,
        'auto_set_image_alt'                       => true,
        'no_index'                                 => ['post_format', 'attachment', 'author', 'date', 'search'],
        'no_follow'                                => [],
        'no_archive'                               => [],
    ];

    /**
     * SureRank's feed switches. ThinkRank has none of them, so a feed the
     * user removed comes back after the switch.
     */
    private const FEED_SWITCHES = [
        'remove_global_comments_feed', 'remove_post_authors_feed', 'remove_post_types_feed',
        'remove_category_feed', 'remove_tag_feeds', 'remove_custom_taxonomy_feeds',
        'remove_search_results_feed', 'remove_atom_rdf_feeds',
    ];

    /**
     * Settings with no ThinkRank home, captured when the user moved them off
     * SureRank's default.
     */
    private const UNMAPPED_SETTINGS = [
        'facebook_author_fallback', 'twitter_profile_fallback', 'oembeded_scripts', 'oembeded_og_title',
        'oembeded_social_images', 'oembeded_remove_author_name', 'paginated_link_relationships',
        'enable_headless_rest_api',
    ];

    /**
     * SureRank's defaults for UNMAPPED_SETTINGS.
     */
    private const UNMAPPED_DEFAULTS = [
        'facebook_author_fallback'     => '',
        'twitter_profile_fallback'     => '',
        'oembeded_scripts'             => true,
        'oembeded_og_title'            => true,
        'oembeded_social_images'       => true,
        'oembeded_remove_author_name'  => true,
        'paginated_link_relationships' => ['homepage', 'pages', 'archives'],
        'enable_headless_rest_api'     => false,
    ];

    /**
     * Default schema rows carry a deterministic `srdef-` key
     * (Utils::generate_stable_id()). ThinkRank publishes its own WebSite,
     * WebPage, Organization/Person, BreadcrumbList, Article and SearchAction,
     * so these are not carried. Any other key is a schema the user added.
     */
    private const DEFAULT_SCHEMA_PREFIX = 'srdef-';

    /**
     * Onboarding website types SureRank publishes as a Person
     * (Onboarding::person_or_organization()).
     */
    private const PERSON_WEBSITE_TYPES = ['blog', 'personal'];

    /**
     * Organization subtypes ThinkRank's Organization setting renders.
     */
    private const ORGANIZATION_TYPES = \ThinkRank\Config\Schema_Settings_Config::ORGANIZATION_TYPES;

    /**
     * SureRank per-post tokens ThinkRank resolves per request: kept as tags
     * rather than frozen into text. `%content%` is SureRank's generated
     * description, which ThinkRank's `%excerpt%` produces the same way (the
     * excerpt, or the trimmed content when there is none).
     */
    private const POST_TOKENS = [
        '%title%'       => '%title%',
        '%site_name%'   => '%sitename%',
        '%separator%'   => '%sep%',
        '%excerpt%'     => '%excerpt%',
        '%content%'     => '%excerpt%',
        '%published%'   => '%date%',
        '%modified%'    => '%modified%',
        '%author_name%' => '%author%',
    ];

    /**
     * SureRank per-term tokens ThinkRank resolves on a term archive. SureRank
     * reads `%title%` as the term name there (Settings::replace_meta_variables()).
     */
    private const TERM_TOKENS = [
        '%term_title%'       => '%term%',
        '%title%'            => '%term%',
        '%term_description%' => '%excerpt%',
        '%excerpt%'          => '%excerpt%',
        '%content%'          => '%excerpt%',
        '%site_name%'        => '%sitename%',
        '%separator%'        => '%sep%',
    ];

    /**
     * Memoised get_available_types().
     *
     * @var array|null
     */
    private ?array $available_types = null;

    /**
     * Memoised settings merged over DEFAULTS.
     *
     * @var array|null
     */
    private ?array $settings = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = self::SLUG;
        $this->plugin_name = 'SureRank';
        $this->plugin_file = 'surerank/surerank.php';
        $this->meta_key_prefix = self::META_PREFIX;
        $this->option_keys = [self::OPTION, self::ONBOARDING_OPTION, self::ROBOTS_TXT_OPTION];
    }

    /**
     * {@inheritDoc}
     */
    public function detect(): bool {
        return !empty($this->get_available_types());
    }

    /**
     * {@inheritDoc}
     */
    public function get_available_types(): array {
        if ($this->available_types !== null) {
            return $this->available_types;
        }

        $types = [];

        $posts = $this->count_posts();
        if ($posts > 0) {
            $types['postmeta'] = $posts;
        }

        $terms = $this->count_objects('termmeta', 'term_id');
        if ($terms > 0) {
            $types['termmeta'] = $terms;
        }

        $users = $this->count_objects('usermeta', 'user_id');
        if ($users > 0) {
            $types['usermeta'] = $users;
        }

        foreach ($this->option_keys as $option) {
            if (get_option($option, null) !== null) {
                $types['settings'] = 1;
                break;
            }
        }

        $this->available_types = $types;

        return $types;
    }

    /**
     * Posts of a viewable type with SureRank SEO rows. Same population as
     * get_post_ids_with_meta(), counted.
     */
    private function count_posts(): int {
        global $wpdb;

        $post_types = $this->get_exportable_post_types();
        if (empty($post_types)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names are $wpdb properties and every value is a placeholder replacement.
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key LIKE %s
             AND p.post_type IN ({$placeholders})",
            array_merge([$wpdb->esc_like(self::META_PREFIX) . '%'], $post_types)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Terms or users with SureRank SEO rows.
     *
     * @param string $table  'termmeta' or 'usermeta'
     * @param string $column The object id column of that table
     */
    private function count_objects(string $table, string $column): int {
        global $wpdb;

        $name = $table === 'usermeta' ? $wpdb->usermeta : $wpdb->termmeta;
        $column = $column === 'user_id' ? 'user_id' : 'term_id';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column come from the two literals above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT {$column}) FROM {$name} WHERE meta_key LIKE %s",
                $wpdb->esc_like(self::META_PREFIX) . '%'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // -------------------------------------------------------------------------
    // Posts, terms and users
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    protected function export_postmeta_page(int $page): array {
        $records = [];
        foreach ($this->get_post_ids_with_meta($page) as $post_id) {
            $post_id = (int) $post_id;
            $record = $this->build_post_record($post_id, $this->read_object_meta($this->get_all_plugin_meta($post_id)));
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Canonical record for one post.
     *
     * Only what SureRank stored is carried. A field it left empty renders
     * from the global templates, and those are carried as ThinkRank's
     * per-type templates, so the post renders the same without a frozen copy
     * of them in its own meta.
     *
     * @param int   $post_id Post ID
     * @param array $meta    read_object_meta() output
     * @return array|null Record, or null when there is nothing to carry
     */
    private function build_post_record(int $post_id, array $meta): ?array {
        $post_type = get_post_type($post_id);
        $index = $this->explicit_index($meta, is_string($post_type) ? $post_type : '');
        if (!$index && $this->is_empty_object($meta)) {
            return null;
        }

        $general = $meta['general'];
        $social = $meta['social'];
        $keyword = $this->meta_string($general, 'focus_keyword');

        $data = [
            'seo_title'        => $this->convert_post_value($this->meta_string($general, 'page_title'), $post_id),
            'meta_description' => $this->convert_post_value($this->meta_string($general, 'page_description'), $post_id),
            'focus_keyword'    => $keyword,
            'focus_keywords'   => $keyword !== '' ? [$keyword] : [],
            'canonical_url'    => $this->meta_string($general, 'canonical_url'),
            'noindex'          => $meta['noindex'],
            'nofollow'         => $meta['nofollow'],
            'noarchive'        => $meta['noarchive'],
            'og_title'         => $this->convert_post_value($this->meta_string($social, 'facebook_title'), $post_id),
            'og_description'   => $this->convert_post_value($this->meta_string($social, 'facebook_description'), $post_id),
            'og_image'         => $this->meta_string($social, 'facebook_image_url'),
        ];
        if ($index) {
            $data['index'] = 1;
        }

        // With "same as Facebook" on (SureRank's default) the X tags are the
        // Facebook ones, which is also what ThinkRank prints when the X
        // fields are empty, so only a separate X override is carried.
        if (!$this->twitter_follows_facebook($social)) {
            $data['twitter_title'] = $this->convert_post_value($this->meta_string($social, 'twitter_title'), $post_id);
            $data['twitter_description'] = $this->convert_post_value($this->meta_string($social, 'twitter_description'), $post_id);
            $data['twitter_image'] = $this->meta_string($social, 'twitter_image_url');
        }

        return [
            'object_id'     => $post_id,
            'object_type'   => 'post',
            'source_plugin' => $this->plugin_slug,
            'data'          => $data,
            'extended'      => $this->object_extended($meta),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function export_termmeta_page(int $page): array {
        $records = [];
        foreach ($this->get_term_ids_with_meta($page) as $term_id) {
            $term_id = (int) $term_id;
            $term = get_term($term_id);
            $record = $this->build_term_record(
                $term_id,
                ($term instanceof \WP_Term) ? $term : null,
                $this->read_object_meta($this->get_all_plugin_term_meta($term_id))
            );
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Canonical record for one term.
     *
     * @param int           $term_id Term ID
     * @param \WP_Term|null $term    The term, when it still exists
     * @param array         $meta    read_object_meta() output
     * @return array|null Record, or null when there is nothing to carry
     */
    private function build_term_record(int $term_id, ?\WP_Term $term, array $meta): ?array {
        $index = $this->explicit_index($meta, $term ? (string) $term->taxonomy : '');
        if (!$index && $this->is_empty_object($meta)) {
            return null;
        }

        $general = $meta['general'];
        $social = $meta['social'];

        $data = [
            'seo_title'        => $this->convert_term_value($this->meta_string($general, 'page_title'), $term),
            'meta_description' => $this->convert_term_value($this->meta_string($general, 'page_description'), $term),
            'focus_keyword'    => $this->meta_string($general, 'focus_keyword'),
            'canonical_url'    => $this->meta_string($general, 'canonical_url'),
            'noindex'          => $meta['noindex'],
            'nofollow'         => $meta['nofollow'],
            'noarchive'        => $meta['noarchive'],
            'og_title'         => $this->convert_term_value($this->meta_string($social, 'facebook_title'), $term),
            'og_description'   => $this->convert_term_value($this->meta_string($social, 'facebook_description'), $term),
            'og_image'         => $this->meta_string($social, 'facebook_image_url'),
        ];
        if ($index) {
            $data['index'] = 1;
        }
        if (!$this->twitter_follows_facebook($social)) {
            $data['twitter_title'] = $this->convert_term_value($this->meta_string($social, 'twitter_title'), $term);
            $data['twitter_description'] = $this->convert_term_value($this->meta_string($social, 'twitter_description'), $term);
            $data['twitter_image'] = $this->meta_string($social, 'twitter_image_url');
        }

        return [
            'object_id'     => $term_id,
            'object_type'   => 'term',
            'source_plugin' => $this->plugin_slug,
            'data'          => $data,
            'extended'      => $this->object_extended($meta),
        ];
    }

    /**
     * {@inheritDoc}
     *
     * An author's own title and description. ThinkRank stores these as plain
     * text, so SureRank's tokens are resolved against the author here. The
     * author's robots, canonical and social values have no ThinkRank home;
     * they are kept on the record and counted in the settings record's
     * `author_seo` so cleanup warns before deleting them.
     */
    protected function export_usermeta_page(int $page): array {
        $records = [];
        foreach ($this->get_user_ids_with_meta($page) as $user_id) {
            $user_id = (int) $user_id;
            $meta = $this->read_object_meta($this->get_all_plugin_user_meta($user_id));
            $general = $meta['general'];

            $title = $this->resolve_tokens($this->meta_string($general, 'page_title'), $this->user_values($user_id));
            $description = $this->resolve_tokens($this->meta_string($general, 'page_description'), $this->user_values($user_id));
            $unapplied = $this->author_unapplied($meta);
            if ($title === '' && $description === '' && empty($unapplied)) {
                continue;
            }

            $extended = $this->object_extended($meta);
            if (!empty($unapplied)) {
                $extended['surerank_author_seo'] = $unapplied;
            }

            $records[] = [
                'object_id'     => $user_id,
                'object_type'   => 'user',
                'source_plugin' => $this->plugin_slug,
                'data'          => [
                    'seo_title'        => $title,
                    'meta_description' => $description,
                ],
                'extended'      => $extended,
            ];
        }

        return $records;
    }

    /**
     * An author's values ThinkRank has no field for: the canonical URL, the
     * robots directives (an explicit index too, when author archives are on
     * SureRank's noindex list) and the social overrides.
     *
     * @since 2.16.0
     *
     * @param array $meta read_object_meta() output
     * @return array{canonical_url?: string, robots?: string[], social?: array<string,string>}
     */
    private function author_unapplied(array $meta): array {
        $out = [];

        $canonical = $this->meta_string($meta['general'], 'canonical_url');
        if ($canonical !== '') {
            $out['canonical_url'] = $canonical;
        }

        $robots = array_keys(array_filter([
            'noindex'   => $meta['noindex'],
            'nofollow'  => $meta['nofollow'],
            'noarchive' => $meta['noarchive'],
        ]));
        if ($this->explicit_index($meta, 'author')) {
            $robots[] = 'index';
        }
        if (!empty($robots)) {
            $out['robots'] = $robots;
        }

        $fields = ['facebook_title', 'facebook_description', 'facebook_image_url'];
        if (!$this->twitter_follows_facebook($meta['social'])) {
            $fields = array_merge($fields, ['twitter_title', 'twitter_description', 'twitter_image_url']);
        }
        $social = [];
        foreach ($fields as $field) {
            $value = $this->meta_string($meta['social'], $field);
            if ($value !== '') {
                $social[$field] = $value;
            }
        }
        if (!empty($social)) {
            $out['social'] = $social;
        }

        return $out;
    }

    /**
     * Authors whose robots, canonical or social values have no ThinkRank
     * home (author_unapplied()).
     *
     * @since 2.16.0
     */
    private function count_author_unapplied(): int {
        $count = 0;
        for ($page = 1; $this->chunk_size > 0; $page++) {
            $ids = $this->get_user_ids_with_meta($page);
            foreach ($ids as $user_id) {
                if (!empty($this->author_unapplied($this->read_object_meta($this->get_all_plugin_user_meta((int) $user_id))))) {
                    $count++;
                }
            }
            if (count($ids) < $this->chunk_size) {
                break;
            }
        }

        return $count;
    }

    /**
     * {@inheritDoc}
     *
     * SureRank has no redirect manager.
     */
    protected function export_redirections_page(int $page): array {
        return [];
    }

    /**
     * One object's SureRank rows, decoded: the `general` and `social` groups
     * as arrays, the robots flags as 0/1 and the raw schema row.
     *
     * @param array $rows meta_key => raw meta_value
     * @return array{general: array, social: array, noindex: int, nofollow: int, noarchive: int, own_robots: bool, schemas: array}
     */
    private function read_object_meta(array $rows): array {
        return [
            'general'   => $this->without_inherited_templates($this->read_meta_array($rows[self::GENERAL_META] ?? null)),
            'social'    => $this->read_meta_array($rows[self::SOCIAL_META] ?? null),
            'noindex'   => $this->robots_flag($rows[self::META_PREFIX . 'post_no_index'] ?? ''),
            'nofollow'  => $this->robots_flag($rows[self::META_PREFIX . 'post_no_follow'] ?? ''),
            'noarchive' => $this->robots_flag($rows[self::META_PREFIX . 'post_no_archive'] ?? ''),
            // SureRank uses an object's own robots rows in place of the global
            // lists as soon as any of them holds a value (Robots::print_meta()).
            'own_robots' => $this->has_own_robots($rows),
            'schemas'   => $this->schema_changes($this->read_meta_array($rows[self::SCHEMAS_META] ?? null)),
        ];
    }

    /**
     * A decoded schema row, or nothing when it changes nothing.
     *
     * SureRank saves every object it edits with a schema row, and an object
     * whose schemas were never touched gets an empty diff
     * (`['schemas' => [], 'excluded_schemas' => []]`). Only a row that
     * overrides or excludes a schema is a change. A legacy row holds the
     * full set under `schemas` alone; a shape this exporter does not know is
     * kept as it is.
     *
     * @since 2.16.0
     *
     * @param array $row Decoded `surerank_settings_schemas`
     * @return array The row, or [] when it changes nothing
     */
    private function schema_changes(array $row): array {
        if (!array_key_exists('schemas', $row) && !array_key_exists('excluded_schemas', $row)) {
            return $row;
        }

        return (!empty($row['schemas']) || !empty($row['excluded_schemas'])) ? $row : [];
    }

    /**
     * The `general` group minus a title or description that is only the
     * template SureRank would have used anyway.
     *
     * Saving an object with an empty title or description makes SureRank
     * write its fallback template into the object's own row
     * (Utils::process_array_option_value()): the built-in default, or the
     * global template when the editor sent that back. Carried as the
     * object's own value it would pin the object to that template, so a
     * later change to ThinkRank's title format or Global SEO template would
     * never reach it. Such a value reads as unset.
     *
     * SureRank Pro's per-post-type templates are not checked: Pro stores
     * them through a filter this exporter cannot read without Pro.
     *
     * @since 2.16.0
     *
     * @param array $general The decoded `general` group
     * @return array
     */
    private function without_inherited_templates(array $general): array {
        foreach (['page_title', 'page_description'] as $field) {
            $value = $this->meta_string($general, $field);
            if ($value !== '' && $this->is_inherited_template($value, $field)) {
                unset($general[$field]);
            }
        }

        return $general;
    }

    /**
     * Whether a stored title or description equals the template SureRank
     * falls back to for that field: its built-in default or the saved
     * global template.
     *
     * @since 2.16.0
     *
     * @param string $value Stored value
     * @param string $field 'page_title' or 'page_description'
     */
    private function is_inherited_template(string $value, string $field): bool {
        $normalized = $this->normalize_template($value);
        foreach ([self::DEFAULTS[$field], $this->setting_string($this->settings(), $field)] as $template) {
            if ($template !== '' && $this->normalize_template($template) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a decoded object holds nothing worth a record.
     *
     * @param array $meta read_object_meta() output
     */
    private function is_empty_object(array $meta): bool {
        return empty($meta['general']) && empty($meta['social']) && empty($meta['schemas'])
            && !$meta['noindex'] && !$meta['nofollow'] && !$meta['noarchive'];
    }

    /**
     * The per-object values ThinkRank has no field for, kept on the record
     * so the snapshot still holds them.
     *
     * @param array $meta read_object_meta() output
     * @return array
     */
    private function object_extended(array $meta): array {
        $extended = [];
        $card = $this->meta_string($meta['social'], 'twitter_card_type');
        if ($card !== '') {
            $extended['twitter_card_type'] = $card;
        }
        if (!empty($meta['schemas'])) {
            $extended['surerank_schemas'] = $meta['schemas'];
        }

        return $extended;
    }

    /**
     * SureRank's robots row ('yes' / 'no') as 0/1. A 'no' reads as 0 like
     * an unset row; where it overrides a noindexed type or taxonomy the
     * record says so separately (explicit_index()).
     *
     * @param mixed $value Raw meta value
     */
    private function robots_flag($value): int {
        return is_scalar($value) && strtolower(trim((string) $value)) === 'yes' ? 1 : 0;
    }

    /**
     * Whether any of an object's robots rows holds a value, 'no' included.
     *
     * @since 2.16.0
     *
     * @param array $rows meta_key => raw meta_value
     */
    private function has_own_robots(array $rows): bool {
        foreach (['post_no_index', 'post_no_follow', 'post_no_archive'] as $flag) {
            $value = $rows[self::META_PREFIX . $flag] ?? '';
            if (is_scalar($value) && !empty(trim((string) $value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an object is indexed against its type's noindex.
     *
     * SureRank ignores its robots lists for an object with its own robots
     * rows, so one whose noindex row is not 'yes' is indexed even when its
     * post type or taxonomy is on the noindex list. That list is imported as
     * the type's noindex, so the object needs an explicit index override
     * (the canonical `index` field) to stay indexed. Off the list nothing is
     * needed and nothing is carried.
     *
     * @since 2.16.0
     *
     * @param array  $meta read_object_meta() output
     * @param string $type The object's post type or taxonomy
     */
    private function explicit_index(array $meta, string $type): bool {
        return $meta['own_robots'] && !$meta['noindex'] && $type !== ''
            && in_array($type, $this->setting_list($this->settings(), 'no_index'), true);
    }

    /**
     * Whether the X tags mirror the Facebook ones (SureRank's default when
     * the flag was never saved).
     *
     * @param array $social The `social` group
     */
    private function twitter_follows_facebook(array $social): bool {
        if (!array_key_exists('twitter_same_as_facebook', $social)) {
            return true;
        }

        return filter_var($social['twitter_same_as_facebook'], FILTER_VALIDATE_BOOLEAN);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    protected function export_settings(): array {
        $onboarding = get_option(self::ONBOARDING_OPTION, []);
        $robots_txt = get_option(self::ROBOTS_TXT_OPTION, '');

        return [$this->build_settings_record(
            $this->settings(),
            is_array($onboarding) ? $onboarding : [],
            is_string($robots_txt) ? $robots_txt : ''
        )];
    }

    /**
     * The `surerank_settings` option over SureRank's defaults, the way
     * SureRank itself reads it.
     *
     * @return array
     */
    private function settings(): array {
        if ($this->settings === null) {
            $option = get_option(self::OPTION, []);
            $this->settings = array_merge(self::DEFAULTS, is_array($option) ? $option : []);
        }

        return $this->settings;
    }

    /**
     * The canonical settings record.
     *
     * @param array  $settings   `surerank_settings` merged over DEFAULTS
     * @param array  $onboarding `surerank_settings_onboarding`
     * @param string $robots_txt `surerank_robots_txt_content`
     * @return array Settings record
     */
    private function build_settings_record(array $settings, array $onboarding, string $robots_txt): array {
        $separator = $this->setting_string($settings, 'separator');
        $title = $this->setting_string($settings, 'page_title');
        $description = $this->setting_string($settings, 'page_description');
        $no_index = $this->setting_list($settings, 'no_index');
        $fallback_image = $this->setting_string($settings, 'fallback_image');
        $kg = $this->knowledge_graph($onboarding);

        $data = [
            'separator'            => $separator !== '' ? $separator : '-',
            'homepage_title'       => $this->convert_identity_template($this->setting_string($settings, 'home_page_title'), ''),
            'homepage_description' => $this->convert_identity_template($this->setting_string($settings, 'home_page_description'), ''),
            // The Organization logo, also Site Identity's schema fallback.
            // The entity's name is NOT passed as organization_name: that
            // feeds Site Identity's site title and would rename %site_title%
            // in every title, which SureRank never did.
            'organization_logo'    => $kg['logo'] ?? '',
            'social_profiles'      => $this->social_profiles($settings, $onboarding),
            'noindex_archives'     => [
                'date'   => in_array('date', $no_index, true),
                'author' => in_array('author', $no_index, true),
            ],
            'twitter_card_type'    => in_array($settings['twitter_card_type'] ?? '', ['summary', 'summary_large_image'], true)
                ? (string) $settings['twitter_card_type']
                : '',
            'social_defaults'      => [
                // SureRank's one fallback image serves both Facebook and X.
                'og_default_image'      => $fallback_image,
                'twitter_default_image' => $fallback_image,
            ],
            'knowledge_graph'      => $kg,
        ];

        // SureRank redirects author archives to the homepage unless they are
        // switched on (Archives::archive_redirect()), and titles them with the
        // global template, `%title%` read as the author's name.
        $author_archives = ['enabled' => !empty($settings['author_archive'])];
        if ($title !== '' && !$this->is_default_template($title, $separator)) {
            $author_archives['title'] = $this->convert_identity_template($title, '%author_name%');
        }

        $extended = [
            'title_formats'      => $this->title_formats($settings),
            'post_type_settings' => $this->post_type_settings($settings),
            'author_archives'    => $author_archives,
            'image_seo'          => [
                'add_missing_alt'   => !empty($settings['auto_set_image_alt']),
                'add_missing_title' => !empty($settings['auto_set_image_title']),
            ],
            'sitemap_settings'   => [
                'enabled'        => !empty($settings['enable_xml_sitemap']),
                'include_images' => !empty($settings['enable_xml_image_sitemap']),
                'has_data'       => true,
            ],
            'feed'               => ['source_link' => !empty($settings['addlink_to_source_below_feed_entries'])],
            'robots_txt'         => trim($robots_txt) !== '' ? ['content' => $robots_txt] : [],
            // Raw capture for the snapshot, minus anything secret.
            'raw_options'        => [
                self::OPTION            => $this->without_secrets((array) get_option(self::OPTION, [])),
                self::ONBOARDING_OPTION => $onboarding,
            ],
        ];

        $taxonomies = $this->taxonomy_settings($settings);
        if (!empty($taxonomies['noindex'])) {
            $extended['taxonomy_settings'] = $taxonomies['noindex'];
        }

        // Preserved, not applied: ThinkRank has no equivalent. Each keeps
        // /import/cleanup from deleting the only copy without a warning.
        if (!empty($taxonomies['other'])) {
            $extended['taxonomy_robots'] = $taxonomies['other'];
        }
        // The same description template also describes term and author
        // archives, which have no template store in ThinkRank.
        if ($description !== '' && $description !== self::DEFAULTS['page_description']) {
            $extended['archive_description_template'] = $description;
        }
        if (empty($settings['date_archive'])) {
            $extended['date_archive_redirect'] = true;
        }
        $homepage_social = array_filter([
            'facebook_title'       => $this->setting_string($settings, 'home_page_facebook_title'),
            'facebook_description' => $this->setting_string($settings, 'home_page_facebook_description'),
            'facebook_image'       => $this->setting_string($settings, 'home_page_facebook_image_url'),
            'twitter_title'        => $this->setting_string($settings, 'home_page_twitter_title'),
            'twitter_description'  => $this->setting_string($settings, 'home_page_twitter_description'),
            'twitter_image'        => $this->setting_string($settings, 'home_page_twitter_image_url'),
        ]);
        if (!empty($homepage_social)) {
            $extended['homepage_social'] = $homepage_social;
        }
        $paginated = array_filter([
            'noindex_paginated_pages'    => !empty($settings['noindex_paginated_pages']),
            'noindex_paginated_homepage' => empty($settings['index_home_page_paginated_pages']),
        ]);
        if (!empty($paginated)) {
            $extended['paginated_robots'] = $paginated;
        }
        $feeds = array_values(array_filter(self::FEED_SWITCHES, static fn(string $key): bool => !empty($settings[$key])));
        if (!empty($feeds)) {
            $extended['removed_feeds'] = $feeds;
        }
        $social_output = array_keys(array_filter([
            'open_graph_tags'    => empty($settings['open_graph_tags']),
            'facebook_meta_tags' => empty($settings['facebook_meta_tags']),
            'twitter_meta_tags'  => empty($settings['twitter_meta_tags']),
        ]));
        if (!empty($social_output)) {
            $extended['social_output_off'] = $social_output;
        }
        // WordPress itself redirects attachment pages unless the site kept
        // them (wp_attachment_pages_enabled), so the switch only matters there.
        if (!empty($settings['redirect_attachment_pages_to_post_parent']) && get_option('wp_attachment_pages_enabled', false)) {
            $extended['attachment_redirect'] = true;
        }
        $unmapped = $this->changed_unmapped_settings($settings);
        if (!empty($unmapped)) {
            $extended['unmapped_settings'] = $unmapped;
        }

        $schemas = $this->custom_schemas($settings);
        if (!empty($schemas)) {
            $extended['schema_templates'] = $schemas;
        }
        $object_schemas = $this->count_object_schemas();
        if (array_sum($object_schemas) > 0) {
            $extended['object_schemas'] = $object_schemas;
        }
        $author_seo = $this->count_author_unapplied();
        if ($author_seo > 0) {
            $extended['author_seo'] = ['users' => $author_seo];
        }

        return [
            'type'          => 'settings',
            'source_plugin' => $this->plugin_slug,
            'data'          => $data,
            'extended'      => $extended,
        ];
    }

    /**
     * The global title template onto ThinkRank's Site Identity formats, one
     * per context, with SureRank's `%title%` standing for each context's
     * own name. The homepage has its own template.
     *
     * A template that renders exactly what ThinkRank's default renders with
     * the imported separator is left out: writing it would only pin the
     * default.
     *
     * @param array $settings Merged settings
     * @return array ThinkRank title-format key => template
     */
    private function title_formats(array $settings): array {
        $formats = [];

        $home = $this->setting_string($settings, 'home_page_title');
        $separator = $this->setting_string($settings, 'separator');
        if ($home !== '' && !($this->normalize_template($home) === self::DEFAULTS['home_page_title'] && $separator === '-')) {
            $formats['homepage_title'] = $this->convert_identity_template($home, '');
        }

        $title = $this->setting_string($settings, 'page_title');
        if ($title === '' || $this->is_default_template($title, $separator)) {
            return $formats;
        }

        $contexts = [
            'post_title'     => '%post_title%',
            'page_title'     => '%page_title%',
            'category_title' => '%category_title%',
            'tag_title'      => '%tag_title%',
            'archive_title'  => '%archive_title%',
        ];
        foreach ($contexts as $key => $token) {
            $converted = $this->convert_identity_template($title, $token);
            if ($converted !== '') {
                $formats[$key] = $converted;
            }
        }

        return $formats;
    }

    /**
     * The global templates and robots lists as ThinkRank's per-post-type
     * Global SEO settings, for every public post type (SureRank has one
     * template for all of them).
     *
     * @param array $settings Merged settings
     * @return array post_type => {title_template?, description_template?, custom_robots?, robots?}
     */
    private function post_type_settings(array $settings): array {
        $separator = $this->setting_string($settings, 'separator');
        $title = $this->setting_string($settings, 'page_title');
        $description = $this->setting_string($settings, 'page_description');

        $title_template = ($title !== '' && !$this->is_default_template($title, $separator)) ? $this->convert_global_template($title) : '';
        $description_template = ($description !== '' && $description !== self::DEFAULTS['page_description'])
            ? $this->convert_global_template($description)
            : '';

        $lists = [
            'noindex'   => $this->setting_list($settings, 'no_index'),
            'nofollow'  => $this->setting_list($settings, 'no_follow'),
            'noarchive' => $this->setting_list($settings, 'no_archive'),
        ];

        $out = [];
        foreach ((array) get_post_types(['public' => true], 'names') as $post_type) {
            $post_type = (string) $post_type;
            if ($post_type === 'attachment') {
                continue;
            }

            $entry = [];
            if ($title_template !== '') {
                $entry['title_template'] = $title_template;
            }
            if ($description_template !== '') {
                $entry['description_template'] = $description_template;
            }

            $robots = [];
            foreach ($lists as $directive => $list) {
                if (in_array($post_type, $list, true)) {
                    $robots[] = $directive;
                }
            }
            if (!empty($robots)) {
                $entry['custom_robots'] = true;
                $entry['robots'] = $robots;
            }

            if (!empty($entry)) {
                $out[$post_type] = $entry;
            }
        }

        return $out;
    }

    /**
     * Taxonomies in the robots lists: noindex (applied through the Content
     * Type Matrix) apart from nofollow / noarchive (preserved).
     *
     * @param array $settings Merged settings
     * @return array{noindex: array, other: array}
     */
    private function taxonomy_settings(array $settings): array {
        $noindex = [];
        $other = [];
        foreach (['no_index' => 'noindex', 'no_follow' => 'nofollow', 'no_archive' => 'noarchive'] as $list_key => $directive) {
            foreach ($this->setting_list($settings, $list_key) as $slug) {
                if (!function_exists('taxonomy_exists') || !taxonomy_exists($slug)) {
                    continue;
                }
                if ($directive === 'noindex') {
                    $noindex[$slug] = ['noindex' => true];
                } else {
                    $other[$slug][] = $directive;
                }
            }
        }

        return ['noindex' => $noindex, 'other' => $other];
    }

    /**
     * The site entity from SureRank's onboarding.
     *
     * @param array $onboarding `surerank_settings_onboarding`
     * @return array{type?: string, name?: string, logo?: string, description?: string, phone?: string, organization_type?: string}
     */
    private function knowledge_graph(array $onboarding): array {
        $website_type = $this->meta_string($onboarding, 'website_type');
        $is_person = in_array($website_type, self::PERSON_WEBSITE_TYPES, true);
        $owner = $this->meta_string($onboarding, 'website_owner_name');
        $name = $is_person && $owner !== '' ? $owner : $this->meta_string($onboarding, 'website_name');

        if ($name === '') {
            return [];
        }

        $kg = array_filter([
            'type'        => $is_person ? 'person' : 'organization',
            'name'        => $name,
            'logo'        => $this->meta_string($onboarding, 'website_logo'),
            'description' => $this->meta_string($onboarding, 'business_description'),
            'phone'       => $this->meta_string($onboarding, 'website_owner_phone'),
        ]);

        $org_type = $this->meta_string($onboarding, 'organization_type');
        if (!$is_person && in_array($org_type, self::ORGANIZATION_TYPES, true)) {
            $kg['organization_type'] = $org_type;
        }

        return $kg;
    }

    /**
     * Social profile URLs, by network. The onboarding profiles come first;
     * the Facebook page and X username from the Social settings fill gaps.
     *
     * @param array $settings   Merged settings
     * @param array $onboarding `surerank_settings_onboarding`
     * @return array<string,string>
     */
    private function social_profiles(array $settings, array $onboarding): array {
        $profiles = [];
        foreach ([$onboarding['social_profiles'] ?? [], $settings['social_profiles'] ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $network => $url) {
                $network = sanitize_key((string) $network);
                $url = $network === 'twitter' ? $this->twitter_profile_url(is_scalar($url) ? (string) $url : '') : (is_scalar($url) ? trim((string) $url) : '');
                if ($network !== '' && $url !== '' && empty($profiles[$network]) && preg_match('#^https?://#i', $url)) {
                    $profiles[$network] = $url;
                }
            }
        }

        $facebook = $this->setting_string($settings, 'facebook_page_url');
        if ($facebook !== '' && empty($profiles['facebook'])) {
            $profiles['facebook'] = $facebook;
        }
        $twitter = $this->twitter_profile_url($this->setting_string($settings, 'twitter_profile_username'));
        if ($twitter !== '' && empty($profiles['twitter'])) {
            $profiles['twitter'] = $twitter;
        }

        return $profiles;
    }

    /**
     * An X profile URL for a username, `@handle` or URL.
     *
     * @param string $value Stored value
     */
    private function twitter_profile_url(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $handle = ltrim($value, '@');

        return $handle !== '' ? 'https://x.com/' . rawurlencode($handle) : '';
    }

    /**
     * Global schema rows the user added. The deterministic default rows are
     * SureRank's stock set, which ThinkRank replaces with its own.
     *
     * @param array $settings Merged settings
     * @return array<string,array> Schema key => row, kept in SureRank's shape
     */
    private function custom_schemas(array $settings): array {
        $schemas = is_array($settings['schemas'] ?? null) ? $settings['schemas'] : [];
        $custom = [];
        foreach ($schemas as $key => $row) {
            if (!is_array($row) || strpos((string) $key, self::DEFAULT_SCHEMA_PREFIX) === 0) {
                continue;
            }
            $custom[(string) $key] = $row;
        }

        return $custom;
    }

    /**
     * Objects carrying their own schema changes, by kind.
     *
     * Every object SureRank saved has a schema row, most of them an empty
     * diff, so each row is decoded and judged by schema_changes() rather
     * than counted in SQL. Rows are read a page at a time by meta id.
     *
     * @return array{posts: int, terms: int, users: int}
     */
    private function count_object_schemas(): array {
        global $wpdb;

        $count = function (string $table, string $id_column, string $object_column) use ($wpdb): int {
            $objects = [];
            $after = 0;
            do {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and columns are $wpdb properties and literals.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT {$id_column} AS id, {$object_column} AS object_id, meta_value FROM {$table}
                         WHERE meta_key = %s AND {$id_column} > %d AND meta_value NOT IN ('', 'a:0:{}', %s)
                         ORDER BY {$id_column} ASC LIMIT %d",
                        self::SCHEMAS_META,
                        $after,
                        self::EMPTY_SCHEMAS_ROW,
                        self::SCHEMA_ROWS_PAGE
                    )
                );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = is_array($rows) ? $rows : [];
                $full_page = count($rows) === self::SCHEMA_ROWS_PAGE;

                foreach ($rows as $row) {
                    $after = max($after, (int) ($row->id ?? 0));
                    if (!empty($this->schema_changes($this->read_meta_array($row->meta_value ?? null)))) {
                        $objects[(int) ($row->object_id ?? 0)] = true;
                    }
                }
            } while ($full_page);

            return count($objects);
        };

        return [
            'posts' => $count($wpdb->postmeta, 'meta_id', 'post_id'),
            'terms' => $count($wpdb->termmeta, 'meta_id', 'term_id'),
            'users' => $count($wpdb->usermeta, 'umeta_id', 'user_id'),
        ];
    }

    /**
     * Settings with no ThinkRank home that the user moved off SureRank's
     * default.
     *
     * @param array $settings Merged settings
     * @return array
     */
    private function changed_unmapped_settings(array $settings): array {
        $changed = [];
        foreach (self::UNMAPPED_SETTINGS as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $default = self::UNMAPPED_DEFAULTS[$key] ?? null;
            if (wp_json_encode($settings[$key]) !== wp_json_encode($default)) {
                $changed[$key] = $settings[$key];
            }
        }

        return $changed;
    }

    /**
     * An option array minus keys that look like credentials.
     *
     * @param array $option Option value
     * @return array
     */
    private function without_secrets(array $option): array {
        return array_filter(
            $option,
            static fn($key): bool => !preg_match('/(api_key|secret|token|password|auth)/i', (string) $key),
            ARRAY_FILTER_USE_KEY
        );
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     *
     * Resolve SureRank tokens against a post (or the site alone).
     */
    protected function convert_template_variables($value, ?int $post_id = null): string {
        $value = $this->stringify_template_value($value);

        return $this->resolve_tokens($value, $post_id ? $this->post_values($post_id) : $this->site_values());
    }

    /**
     * A per-post SureRank value: mapped tokens as ThinkRank tags, the rest
     * resolved against the post.
     *
     * @param string $value   Raw SureRank value
     * @param int    $post_id Post ID
     */
    private function convert_post_value(string $value, int $post_id): string {
        return $this->tokenize_object_template(
            $value,
            self::literal_token_patterns(self::POST_TOKENS),
            fn(string $rest): string => $this->resolve_tokens($rest, strpos($rest, '%') !== false ? $this->post_values($post_id) : [])
        );
    }

    /**
     * A per-term SureRank value: mapped tokens as ThinkRank tags, the rest
     * resolved against the term.
     *
     * @param string        $value Raw SureRank value
     * @param \WP_Term|null $term  The term, when it still exists
     */
    private function convert_term_value(string $value, ?\WP_Term $term): string {
        return $this->tokenize_object_template(
            $value,
            self::literal_token_patterns(self::TERM_TOKENS),
            fn(string $rest): string => $this->resolve_tokens($rest, strpos($rest, '%') !== false ? $this->term_values($term) : [])
        );
    }

    /**
     * Replace `%token%` with its value. A token SureRank does not know
     * resolves to nothing, as it does in SureRank (Variables::get_key_value()),
     * and the whitespace it leaves is collapsed.
     *
     * @param string $value  Template
     * @param array  $values Token name (no percents) => value
     */
    private function resolve_tokens(string $value, array $values): string {
        $value = trim($value);
        if ($value === '' || strpos($value, '%') === false) {
            return $value;
        }

        $resolved = (string) preg_replace_callback(
            '/%([^%\s]+)%/u',
            static function (array $m) use ($values): string {
                $name = (string) $m[1];
                if (array_key_exists($name, $values)) {
                    return (string) $values[$name];
                }
                if (strpos($name, 'custom_field.') === 0 && isset($values['__custom_fields'])) {
                    return (string) ($values['__custom_fields'][substr($name, 13)] ?? '');
                }

                return '';
            },
            $value
        );

        return trim((string) preg_replace('/\s+/', ' ', $resolved));
    }

    /**
     * Site-level token values (SureRank's Meta_Variables\Site).
     */
    private function site_values(): array {
        $settings = $this->settings();
        $onboarding = get_option(self::ONBOARDING_OPTION, []);
        $onboarding = is_array($onboarding) ? $onboarding : [];

        return [
            'separator'    => $this->setting_string($settings, 'separator') !== '' ? $this->setting_string($settings, 'separator') : '-',
            'tagline'      => (string) get_bloginfo('description'),
            'site_name'    => (string) get_bloginfo('name'),
            'site_url'     => (string) home_url(),
            'page'         => '',
            'search_query' => '',
            'currentdate'  => function_exists('wp_date') ? (string) wp_date((string) get_option('date_format', 'F j, Y')) : gmdate('F j, Y'),
            'currentday'   => function_exists('wp_date') ? (string) wp_date('j') : gmdate('j'),
            'currentmonth' => function_exists('wp_date') ? (string) wp_date('F') : gmdate('F'),
            'currentyear'  => function_exists('wp_date') ? (string) wp_date('Y') : gmdate('Y'),
            'currenttime'  => function_exists('wp_date') ? (string) wp_date((string) get_option('time_format', 'g:i a')) : gmdate('g:i a'),
            'org_name'     => $this->meta_string($onboarding, 'website_name'),
            'org_logo'     => $this->meta_string($onboarding, 'website_logo'),
            'org_url'      => (string) home_url(),
        ];
    }

    /**
     * Post-level token values (SureRank's Meta_Variables\Post) over the site's.
     *
     * @param int $post_id Post ID
     */
    private function post_values(int $post_id): array {
        $values = $this->site_values();
        $post = get_post($post_id);
        if (!$post) {
            return $values;
        }

        $content = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $post->post_content))));
        $excerpt = trim(wp_strip_all_tags((string) $post->post_excerpt));
        $author_id = (int) ($post->post_author ?? 0);

        $custom_fields = [];
        foreach ((array) get_post_meta($post_id) as $key => $rows) {
            $custom_fields[(string) $key] = is_array($rows) ? (string) reset($rows) : '';
        }

        return array_merge($values, [
            'title'           => (string) $post->post_title,
            'excerpt'         => $excerpt !== '' ? $excerpt : $this->truncate($content),
            'content'         => $this->truncate($content),
            'permalink'       => (string) get_permalink($post_id),
            'published'       => (string) get_the_date('', $post),
            'modified'        => (string) get_the_modified_date('', $post),
            'author_name'     => $author_id > 0 ? (string) get_the_author_meta('display_name', $author_id) : '',
            'archive_title'   => '',
            '__custom_fields' => $custom_fields,
        ]);
    }

    /**
     * Term-level token values (SureRank's Meta_Variables\Term) over the site's.
     *
     * @param \WP_Term|null $term The term
     */
    private function term_values(?\WP_Term $term): array {
        $values = $this->site_values();
        if (!$term) {
            return $values;
        }

        $description = trim(wp_strip_all_tags((string) $term->description));
        $link = get_term_link($term);

        return array_merge($values, [
            'term_title'       => (string) $term->name,
            'title'            => (string) $term->name,
            'term_description' => $description,
            'excerpt'          => $description,
            'slug'             => (string) $term->slug,
            'permalink'        => is_string($link) ? $link : '',
        ]);
    }

    /**
     * Author-level token values (SureRank's Meta_Variables\User) over the
     * site's. SureRank reads `%title%` as the author's name and `%excerpt%` /
     * `%content%` as the biography there (Settings::replace_user_meta_variables()).
     *
     * @param int $user_id User ID
     */
    private function user_values(int $user_id): array {
        $name = (string) get_the_author_meta('display_name', $user_id);
        $bio = trim(wp_strip_all_tags((string) get_the_author_meta('description', $user_id)));

        return array_merge($this->site_values(), [
            'author_name'        => $name,
            'title'              => $name,
            'author_description' => $bio,
            'excerpt'            => $bio,
            'content'            => $bio,
            'author_nicename'    => (string) get_the_author_meta('user_nicename', $user_id),
            'permalink'          => (string) get_author_posts_url($user_id),
        ]);
    }

    /**
     * SureRank's description length: 160 characters, whitespace collapsed.
     *
     * @param string $text Text
     */
    private function truncate(string $text): string {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, 160) : substr($text, 0, 160);
    }

    /**
     * Whether a global title template renders what SureRank's default does
     * with a `-` separator, which is what ThinkRank's own default renders
     * once the separator is imported.
     *
     * @param string $template  Title template
     * @param string $separator Separator
     */
    private function is_default_template(string $template, string $separator): bool {
        $normalized = $this->normalize_template($template);
        if ($normalized === self::DEFAULTS['page_title']) {
            return true;
        }

        return ($separator === '' || $separator === '-') && $normalized === '%title% %separator% %site_name%';
    }

    /**
     * A template with its whitespace collapsed.
     *
     * @param string $template Template
     */
    private function normalize_template(string $template): string {
        return trim((string) preg_replace('/\s+/', ' ', $template));
    }

    /**
     * A SureRank template in ThinkRank's Site Identity vocabulary
     * (%site_title%, %sep%, %post_title%, …). `%title%` and SureRank's own
     * context tokens become $context_token; tokens with no Site Identity
     * equivalent are dropped with any separator they leave dangling.
     *
     * @param string $template      SureRank template
     * @param string $context_token Token `%title%` stands for ('' on the homepage)
     */
    private function convert_identity_template(string $template, string $context_token): string {
        $template = $this->normalize_template($template);
        if ($template === '' || strpos($template, '%') === false) {
            return $template;
        }

        $map = [
            '%site_name%'   => '%site_title%',
            '%tagline%'     => '%site_description%',
            '%separator%'   => '%sep%',
            '%published%'   => '%date%',
            '%search_query%' => '%search_term%',
        ];
        if ($context_token !== '') {
            $map['%title%'] = $context_token;
            $map['%term_title%'] = $context_token;
            $map['%author_name%'] = $context_token;
        }

        $supported = [
            '%site_title%', '%site_description%', '%sep%', '%date%', '%search_term%', '%post_title%',
            '%page_title%', '%category_title%', '%tag_title%', '%archive_title%', '%author_name%',
        ];

        return $this->map_tokens($template, $map, $supported);
    }

    /**
     * A SureRank template in the Global SEO Pattern_Resolver vocabulary
     * (%title%, %sitename%, %sep%, %excerpt%, …).
     *
     * @param string $template SureRank template
     */
    private function convert_global_template(string $template): string {
        $template = $this->normalize_template($template);
        if ($template === '' || strpos($template, '%') === false) {
            return $template;
        }

        $map = [
            '%site_name%'   => '%sitename%',
            '%separator%'   => '%sep%',
            '%content%'     => '%excerpt%',
            '%published%'   => '%date%',
            '%author_name%' => '%author%',
        ];
        $supported = ['%title%', '%sitename%', '%sep%', '%excerpt%', '%date%', '%modified%', '%author%'];

        return $this->map_tokens($template, $map, $supported);
    }

    /**
     * Rename mapped tokens, resolve the site-wide ones ThinkRank has no tag
     * for (a tagline in a template is a fixed string), drop the rest, then
     * tidy separators left at either end.
     *
     * @param string   $template  Template
     * @param array    $map       SureRank token => ThinkRank token
     * @param string[] $supported ThinkRank tokens the target vocabulary resolves
     */
    private function map_tokens(string $template, array $map, array $supported): string {
        $site = $this->site_values();
        $converted = (string) preg_replace_callback(
            '/%[a-z0-9_.]+%/i',
            static function (array $m) use ($map, $supported, $site): string {
                $token = strtolower($m[0]);
                $token = $map[$token] ?? $token;
                if (in_array($token, $supported, true)) {
                    return $token;
                }
                $name = trim($token, '%');

                return in_array($name, ['site_name', 'tagline', 'site_url', 'org_name', 'currentyear'], true) ? (string) ($site[$name] ?? '') : '';
            },
            $template
        );

        $converted = trim((string) preg_replace('/\s+/', ' ', $converted));
        $converted = (string) preg_replace('/^(?:%sep%\s*)+|(?:\s*%sep%)+$/', '', $converted);

        return trim($converted);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A stored SureRank array. WordPress unserializes it for us; anything
     * else is foreign data and reads as empty.
     *
     * @param mixed $value Raw meta value
     */
    private function read_meta_array($value): array {
        if (is_string($value) && is_serialized($value)) {
            $value = Safe_Unserializer::to_array($value);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * One string field of an array, trimmed.
     *
     * @param array  $meta Array
     * @param string $key  Field
     */
    private function meta_string(array $meta, string $key): string {
        $value = $meta[$key] ?? '';

        return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
    }

    /**
     * One string setting, trimmed.
     *
     * @param array  $settings Merged settings
     * @param string $key      Setting
     */
    private function setting_string(array $settings, string $key): string {
        return $this->meta_string($settings, $key);
    }

    /**
     * One list setting, as strings.
     *
     * @param array  $settings Merged settings
     * @param string $key      Setting
     * @return string[]
     */
    private function setting_list(array $settings, string $key): array {
        $value = $settings[$key] ?? [];

        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }
}
