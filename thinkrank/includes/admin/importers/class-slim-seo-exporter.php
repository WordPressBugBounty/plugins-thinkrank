<?php

/**
 * Slim SEO Exporter
 *
 * Reads Slim SEO data and normalizes it into the canonical snapshot format
 * (#886).
 *
 * Slim SEO keeps everything an object needs in ONE serialized meta array,
 * `slim_seo`, on both posts and terms: title, description, facebook_image,
 * twitter_image, canonical and noindex. The primary term of each taxonomy is
 * its own post meta, `_slim_seo_primary_term_{taxonomy}`. There is no per-user
 * SEO and no focus keyword.
 *
 * Settings live in the `slim_seo` option: per-context templates keyed by
 * `home`, `author`, a post type slug, `{post_type}_archive` or a taxonomy
 * slug, plus social defaults, the feature toggles, robots.txt and header/
 * body/footer code. Redirects are the `ss_redirects` option, an id-keyed map.
 *
 * Slim SEO Pro adds post meta `slim_seo_pro` (the Writing assistant's
 * keywords), `slim_seo_schema` (the post's own schemas) and options
 * `slim_seo_schemas` (global schemas) and `slim_seo_pro` (its features).
 * Schemas go through Slim_SEO_Schema_Converter.
 *
 * Templates use Slim Twig: `{{ post.title }} {{ sep }} {{ site.title }}` —
 * dotted paths into a data tree, no filters, arrays joined with ", ", unknown
 * paths left as written. Slim SEO then drops empty segments between
 * separators, so a title whose `{{ page }}` is empty never shows "- -". The
 * resolver below follows the same rules.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.13.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Slim SEO Exporter Class
 *
 * @since 2.13.0
 */
class Slim_SEO_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Source plugin slug.
     */
    public const SLUG = 'slimseo';

    /**
     * Post/term meta key holding Slim SEO's per-object array.
     */
    public const META_KEY = 'slim_seo';

    /**
     * Prefix of the per-taxonomy primary term post meta.
     */
    public const PRIMARY_TERM_PREFIX = '_slim_seo_primary_term_';

    /**
     * Settings option.
     */
    public const OPTION = 'slim_seo';

    /**
     * Redirects option (Slim SEO's SLIM_SEO_REDIRECTS constant).
     */
    public const REDIRECTS_OPTION = 'ss_redirects';

    /**
     * 404 log table, without the WordPress prefix. Only created while Slim
     * SEO's "Enable 404 logs" setting is on.
     */
    public const LOG_404_TABLE = 'slim_seo_404';

    /**
     * Features Slim SEO switches on when the `features` list was never saved.
     * Settings::is_feature_active() treats an empty list as "all of these"
     * minus FEATURES_OFF_UNTIL_SAVED.
     */
    private const DEFAULT_FEATURES = [
        'meta_title', 'meta_description', 'meta_robots', 'open_graph', 'twitter_cards',
        'canonical_url', 'rel_links', 'sitemaps', 'images_alt', 'breadcrumbs', 'feed',
        'schema', 'redirection', 'no_category_base',
    ];

    /**
     * Default features Slim SEO nevertheless keeps OFF until the user saves
     * the Features tab ("Set features OFF by default" in is_feature_active()).
     */
    private const FEATURES_OFF_UNTIL_SAVED = ['no_category_base'];

    /**
     * Slim SEO's built-in templates (Title::DEFAULTS / Description::DEFAULTS)
     * for the contexts ThinkRank has no template store for. A stored value
     * equal to one of these is Slim SEO's behaviour, not a choice the user
     * made, and ThinkRank renders the same thing by default — so it is not
     * carried as preserved data.
     */
    private const DEFAULT_TEMPLATES = [
        'term'         => ['title' => '{{ term.name }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}', 'description' => '{{ term.auto_description }}'],
        'post_archive' => ['title' => '{{ post_type.labels.plural }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}', 'description' => ''],
    ];

    /**
     * Post meta Slim SEO's auto redirection writes on every slug change: one
     * row per old permalink, each 301'd to the post's current URL.
     */
    public const OLD_PERMALINK_META = '_ss_old_permalink';

    /**
     * Slim SEO Pro post meta: the Writing assistant's keywords under
     * `content_analysis` (`main_keyword`, and `keywords` joined with `;`).
     */
    public const PRO_META_KEY = 'slim_seo_pro';

    /**
     * Slim SEO Pro post meta: the post's own schemas, keyed by id.
     */
    public const SCHEMA_META_KEY = 'slim_seo_schema';

    /**
     * Slim SEO Pro post meta: also show the global schemas on this post.
     */
    public const ALLOW_GLOBAL_META = 'sss_allow_global';

    /**
     * Slim SEO Pro's global schemas (Schema\Settings::OPTION_NAME).
     */
    public const SCHEMAS_OPTION = 'slim_seo_schemas';

    /**
     * Slim SEO Pro's settings: the enabled `features` and `markdown_post_types`.
     */
    public const PRO_OPTION = 'slim_seo_pro';

    /**
     * Slim SEO Pro's features when its settings were never saved.
     */
    private const PRO_DEFAULT_FEATURES = ['link-manager', 'schema', 'analytics', 'content-analysis', 'auto-link', 'entities', 'markdown'];

    /**
     * Post meta keys that put a post in the export: Slim SEO's own array and
     * Slim SEO Pro's two (primary terms are matched by prefix).
     */
    private const POST_META_KEYS = [self::META_KEY, self::PRO_META_KEY, self::SCHEMA_META_KEY];

    /**
     * Slim SEO `condition` => ThinkRank Pro redirect `match_type`.
     */
    private const MATCH_TYPES = [
        'exact-match' => 'exact',
        'contain'     => 'contains',
        'start-with'  => 'start',
        'end-with'    => 'end',
        'regex'       => 'regex',
    ];

    /**
     * Option keys that are settings, not per-context templates.
     */
    private const NON_CONTEXT_KEYS = [
        'header_code', 'body_code', 'footer_code', 'default_facebook_image', 'default_twitter_image',
        'facebook_app_id', 'twitter_site', 'ai_provider', 'ai_model', 'ai_api_key', 'features',
        'robots_txt_editable', 'robots_txt_content',
    ];

    /**
     * Redirect behaviour settings and their Slim SEO defaults. Only values a
     * user changed are carried (and preserved, since ThinkRank has no
     * equivalent switches) — a default here is not a choice anyone made.
     */
    private const REDIRECT_SETTING_DEFAULTS = [
        'force_trailing_slash'     => 0,
        'auto_redirection'         => 1,
        'redirect_www'             => '',
        'case_sensitive'           => 0,
        'enable_404_logs'          => 0,
        'auto_delete_404_logs'     => 30,
        'redirect_404_to'          => '',
        'redirect_404_to_url'      => '',
        'disable_for_single_posts' => 0,
    ];

    /**
     * The redirect settings Slim SEO renders as checkboxes, which it saves as
     * -1 when unticked (Redirection\Settings::option_saved()).
     */
    private const REDIRECT_CHECKBOXES = [
        'force_trailing_slash', 'auto_redirection', 'case_sensitive', 'enable_404_logs', 'disable_for_single_posts',
    ];

    /**
     * Separator Slim SEO renders `{{ sep }}` with when no theme filters
     * `document_title_separator`.
     */
    private const SEPARATOR = '-';

    /**
     * Placeholder for `{{ sep }}` while the rest of a template resolves.
     */
    private const SEP_MARK = "\x1F";

    /**
     * Memoised get_available_types(): the detector and detect() both ask,
     * and the answer costs three COUNTs plus a SHOW TABLES.
     *
     * @var array|null
     */
    private ?array $available_types = null;

    /**
     * Memoised merged redirect list (manual rules + auto-redirected old
     * permalinks), so counting and paging read it once.
     *
     * @var array|null
     */
    private ?array $all_redirects = null;

    /**
     * Slim SEO Pro schema converter, built once per export.
     *
     * @var Slim_SEO_Schema_Converter|null
     */
    private ?Slim_SEO_Schema_Converter $schema_converter = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = self::SLUG;
        $this->plugin_name = 'Slim SEO';
        $this->plugin_file = 'slim-seo/slim-seo.php';
        // LIKE 'slim_seo%' matches the per-object array; the primary-term keys
        // carry a leading underscore and are queried explicitly below.
        $this->meta_key_prefix = self::META_KEY;
        $this->option_keys = [self::OPTION, self::REDIRECTS_OPTION, self::PRO_OPTION, self::SCHEMAS_OPTION];
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

        global $wpdb;

        $types = [];

        $post_count = $this->count_posts();
        if ($post_count > 0) {
            $types['postmeta'] = $post_count;
        }

        $term_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key = %s",
                self::META_KEY
            )
        );
        if ($term_count > 0) {
            $types['termmeta'] = $term_count;
        }

        $redirects = count($this->get_all_redirects());
        if ($redirects > 0) {
            $types['redirections'] = $redirects;
        }

        $logs = $this->count_404_logs();
        if ($logs > 0) {
            $types['404_logs'] = $logs;
        }

        $option = get_option(self::OPTION, null);
        if ((is_array($option) && !empty($option)) || is_array(get_option(self::PRO_OPTION, null)) || is_array(get_option(self::SCHEMAS_OPTION, null))) {
            $types['settings'] = 1;
        }

        $this->available_types = $types;

        return $types;
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

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
            $record = $this->build_post_record(
                $post_id,
                $this->read_meta_array(get_post_meta($post_id, self::META_KEY, true)),
                $this->get_primary_terms($post_id),
                $this->read_meta_array(get_post_meta($post_id, self::PRO_META_KEY, true)),
                $this->read_meta_array(get_post_meta($post_id, self::SCHEMA_META_KEY, true))
            );
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Canonical record for one post.
     *
     * @param int   $post_id       Post ID
     * @param array $meta          Slim SEO's `slim_seo` array for the post
     * @param array $primary_terms taxonomy => term ID
     * @param array $pro           Slim SEO Pro's `slim_seo_pro` array for the post
     * @param array $schemas       Slim SEO Pro's `slim_seo_schema` array for the post
     * @return array|null Record, or null when there is nothing to carry
     */
    private function build_post_record(int $post_id, array $meta, array $primary_terms, array $pro = [], array $schemas = []): ?array {
        $keywords = $this->focus_keywords($pro);
        if (empty($meta) && empty($primary_terms) && empty($keywords) && empty($schemas)) {
            return null;
        }

        $extended = ['primary_terms' => $primary_terms];
        if (!empty($schemas)) {
            $entries = $this->schema_converter()->convert_post($post_id, $schemas, $this->slim_seo_variables($post_id, $meta, $pro));
            if (!empty($entries)) {
                $extended['custom_schemas'] = $entries;
            }
        }

        return [
            'object_id'     => $post_id,
            'object_type'   => 'post',
            'source_plugin' => $this->plugin_slug,
            'data'          => [
                'seo_title'        => $this->convert_post_value($this->meta_string($meta, 'title'), $post_id),
                'meta_description' => $this->convert_post_value($this->meta_string($meta, 'description'), $post_id),
                'focus_keyword'    => $keywords[0] ?? '',
                'focus_keywords'   => $keywords,
                'canonical_url'    => $this->meta_string($meta, 'canonical'),
                'noindex'          => empty($meta['noindex']) ? 0 : 1,
                'nofollow'         => 0,
                'og_image'         => $this->meta_string($meta, 'facebook_image'),
                'twitter_image'    => $this->meta_string($meta, 'twitter_image'),
                // ThinkRank keeps one primary term, the category's.
                'primary_category' => (int) ($primary_terms['category'] ?? 0),
                'schema_type'      => '',
            ],
            'extended'      => $extended,
        ];
    }

    /**
     * The Writing assistant's keywords, main keyword first.
     *
     * @param array $pro Slim SEO Pro's `slim_seo_pro` array for the post
     * @return string[]
     */
    private function focus_keywords(array $pro): array {
        $analysis = is_array($pro['content_analysis'] ?? null) ? $pro['content_analysis'] : [];
        $main = is_scalar($analysis['main_keyword'] ?? null) ? trim((string) $analysis['main_keyword']) : '';
        $others = is_scalar($analysis['keywords'] ?? null) ? explode(';', (string) $analysis['keywords']) : [];

        // mbstring is not guaranteed on every host; fall back as the score
        // calculator does rather than fatal the export.
        $lower = static fn(string $text): string => function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        $keywords = [];
        $seen = [];
        foreach (array_merge([$main], $others) as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '' && !isset($seen[$lower($keyword)])) {
                $seen[$lower($keyword)] = true;
                $keywords[] = $keyword;
            }
        }

        return $keywords;
    }

    /**
     * The `slim_seo.*` variables a schema can use: the post's rendered Slim
     * SEO fields and the Writing assistant's main keyword.
     *
     * @param int   $post_id Post ID
     * @param array $meta    Slim SEO's `slim_seo` array for the post
     * @param array $pro     Slim SEO Pro's `slim_seo_pro` array for the post
     * @return array<string,string>
     */
    private function slim_seo_variables(int $post_id, array $meta, array $pro): array {
        $context = $this->post_context($post_id);
        $variables = [];
        foreach (['title', 'description', 'facebook_image', 'twitter_image'] as $field) {
            $value = $this->meta_string($meta, $field);
            if ($value !== '') {
                $variables[$field] = $this->render_template($value, $context);
            }
        }
        $keywords = $this->focus_keywords($pro);
        if (!empty($keywords)) {
            $variables['main_keyword'] = $keywords[0];
        }

        return $variables;
    }

    /**
     * The schema converter, reading the global schemas once.
     */
    private function schema_converter(): Slim_SEO_Schema_Converter {
        if ($this->schema_converter === null) {
            $this->schema_converter = new Slim_SEO_Schema_Converter(get_option(self::SCHEMAS_OPTION, null));
        }

        return $this->schema_converter;
    }

    /**
     * Posts of a viewable type carrying Slim SEO's per-object array, a Slim
     * SEO Pro array (keywords, schemas) or a primary term. Paged on the union
     * so a post with only one of them is still exported once.
     *
     * {@inheritDoc}
     */
    protected function get_post_ids_with_meta(int $page): array {
        global $wpdb;

        $post_types = $this->get_exportable_post_types();
        if (empty($post_types)) {
            $this->last_page_row_count = 0;
            return [];
        }

        $offset = ($page - 1) * $this->chunk_size;
        $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names are $wpdb properties and every value is a placeholder replacement.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE (pm.meta_key IN (%s, %s, %s) OR pm.meta_key LIKE %s)
             AND p.post_type IN ({$placeholders})
             ORDER BY pm.post_id ASC
             LIMIT %d OFFSET %d",
            array_merge(
                array_merge(self::POST_META_KEYS, [$wpdb->esc_like(self::PRIMARY_TERM_PREFIX) . '%']),
                $post_types,
                [$this->chunk_size, $offset]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
        $ids = $wpdb->get_col($sql);
        $this->last_page_row_count = count($ids);

        return $ids;
    }

    /**
     * Same population as get_post_ids_with_meta(), counted.
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
             WHERE (pm.meta_key IN (%s, %s, %s) OR pm.meta_key LIKE %s)
             AND p.post_type IN ({$placeholders})",
            array_merge(
                array_merge(self::POST_META_KEYS, [$wpdb->esc_like(self::PRIMARY_TERM_PREFIX) . '%']),
                $post_types
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
        return (int) $wpdb->get_var($sql);
    }

    /**
     * taxonomy => primary term ID for one post.
     *
     * @param int $post_id Post ID
     * @return array<string,int>
     */
    private function get_primary_terms(int $post_id): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                $post_id,
                $wpdb->esc_like(self::PRIMARY_TERM_PREFIX) . '%'
            ),
            ARRAY_A
        );

        $terms = [];
        foreach ((array) $rows as $row) {
            $taxonomy = substr((string) $row['meta_key'], strlen(self::PRIMARY_TERM_PREFIX));
            $term_id = (int) $row['meta_value'];
            if ($taxonomy !== '' && $term_id > 0) {
                $terms[$taxonomy] = $term_id;
            }
        }

        return $terms;
    }

    // -------------------------------------------------------------------------
    // Terms and users
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    protected function get_term_ids_with_meta(int $page): array {
        global $wpdb;

        $offset = ($page - 1) * $this->chunk_size;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s ORDER BY term_id ASC LIMIT %d OFFSET %d",
                self::META_KEY,
                $this->chunk_size,
                $offset
            )
        );
        $this->last_page_row_count = count($ids);

        return $ids;
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
            $term = get_term($term_id);
            $record = $this->build_term_record(
                $term_id,
                ($term instanceof \WP_Term) ? $term : null,
                $this->read_meta_array(get_term_meta($term_id, self::META_KEY, true))
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
     * @param array         $meta    Slim SEO's `slim_seo` array for the term
     * @return array|null Record, or null when there is nothing to carry
     */
    private function build_term_record(int $term_id, ?\WP_Term $term, array $meta): ?array {
        if (empty($meta)) {
            return null;
        }

        return [
            'object_id'     => $term_id,
            'object_type'   => 'term',
            'source_plugin' => $this->plugin_slug,
            'data'          => [
                'seo_title'        => $this->convert_term_value($this->meta_string($meta, 'title'), $term),
                'meta_description' => $this->convert_term_value($this->meta_string($meta, 'description'), $term),
                'canonical_url'    => $this->meta_string($meta, 'canonical'),
                'noindex'          => empty($meta['noindex']) ? 0 : 1,
                'nofollow'         => 0,
                'og_image'         => $this->meta_string($meta, 'facebook_image'),
                'twitter_image'    => $this->meta_string($meta, 'twitter_image'),
            ],
            'extended'      => [],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Slim SEO stores no per-user SEO; the author archive is a site template.
     */
    protected function export_usermeta_page(int $page): array {
        return [];
    }

    // -------------------------------------------------------------------------
    // Redirects
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    protected function export_redirections_page(int $page): array {
        $redirects = array_slice($this->get_all_redirects(), ($page - 1) * $this->chunk_size, $this->chunk_size);
        $this->last_page_row_count = count($redirects);

        $records = [];
        foreach ($redirects as $redirect) {
            $record = $this->map_redirect(is_array($redirect) ? $redirect : []);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The `ss_redirects` map, always an array.
     *
     * @return array<string,array>
     */
    private function get_redirects(): array {
        $redirects = get_option(self::REDIRECTS_OPTION, []);

        return is_array($redirects) ? $redirects : [];
    }

    /**
     * Every redirect Slim SEO serves: the manual `ss_redirects` rules followed
     * by the auto redirection's old permalinks, in Slim SEO's row shape so
     * map_redirect() handles both.
     *
     * The old permalinks are the ones a long-lived site has most of — every
     * slug edit since Slim SEO was installed — and they vanish with the plugin.
     *
     * @return array<int,array>
     */
    private function get_all_redirects(): array {
        if ($this->all_redirects === null) {
            $this->all_redirects = array_merge(array_values($this->get_redirects()), $this->get_old_permalink_redirects());
        }

        return $this->all_redirects;
    }

    /**
     * Slim SEO's auto redirection as exact-match rows: `_ss_old_permalink`
     * holds each previous permalink of a post, and Redirection::auto_redirection()
     * 301s a request for one to the post's current permalink.
     *
     * @return array<int,array>
     */
    private function get_old_permalink_redirects(): array {
        global $wpdb;

        $post_types = $this->get_exportable_post_types();
        if (empty($post_types)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names are $wpdb properties and every value is a placeholder replacement.
        $sql = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
             AND p.post_status = 'publish'
             AND p.post_type IN ({$placeholders})
             ORDER BY pm.post_id ASC, pm.meta_id ASC",
            array_merge([self::OLD_PERMALINK_META], $post_types)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $redirects = [];
        foreach ((array) $rows as $row) {
            $redirect = $this->map_old_permalink((int) ($row['post_id'] ?? 0), (string) ($row['meta_value'] ?? ''));
            if ($redirect !== null) {
                $redirects[] = $redirect;
            }
        }

        return $redirects;
    }

    /**
     * One old permalink as a Slim SEO redirect row (from, to, type,
     * condition), or null when it cannot redirect anywhere.
     *
     * Both URLs become paths relative to the home URL, the way Slim SEO
     * stores a manual rule. A path that still resolves to the post itself is
     * no redirect, and one carrying a query string is skipped because
     * ThinkRank Pro matches the request path alone.
     *
     * @param int    $post_id Post ID
     * @param string $old_url Previous permalink
     * @return array|null
     */
    private function map_old_permalink(int $post_id, string $old_url): ?array {
        if ($post_id <= 0 || trim($old_url) === '') {
            return null;
        }

        $current = (string) get_permalink($post_id);
        $from = $this->relative_path($old_url);
        $to = $this->relative_path($current);

        if ($from === '' || $current === '' || $from === $to || str_contains($from, '?')) {
            return null;
        }

        // The target keeps its trailing slash (as a manual Slim SEO rule does:
        // `sample-page/`), or every hit pays a second hop through WordPress's
        // canonical redirect to add it back.
        $to = $this->relative_path($current, false);

        return [
            'from'      => $from,
            'to'        => $to === '' ? '/' : $to,
            'type'      => 301,
            'condition' => 'exact-match',
            'enable'    => 1,
            'note'      => 'Slim SEO auto redirection',
        ];
    }

    /**
     * A URL as a path relative to the home URL, with no surrounding slashes —
     * Slim SEO's Redirection\Helper::normalize_url() shape.
     *
     * @param string $url   Absolute or relative URL
     * @param bool   $rtrim Whether to drop a trailing slash as well
     * @return string
     */
    private function relative_path(string $url, bool $rtrim = true): string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));
        $home = untrailingslashit((string) home_url());
        if ($home !== '' && str_starts_with($url, $home)) {
            $url = substr($url, strlen($home));
        }

        return $rtrim ? trim($url, '/') : ltrim($url, '/');
    }

    /**
     * One Slim SEO redirect as a canonical redirection record.
     *
     * Slim SEO stores `from` relative to the home URL without a leading slash
     * ("old-page"), which is what ThinkRank Pro matches against. A 410 has no
     * target, so an empty `to` is only a reason to skip for the other codes.
     *
     * @param array $redirect Slim SEO redirect row
     * @return array|null Record, or null when the row cannot be a redirect
     */
    private function map_redirect(array $redirect): ?array {
        $source = trim((string) ($redirect['from'] ?? ''));
        $target = trim((string) ($redirect['to'] ?? ''));
        $code = (int) ($redirect['type'] ?? 301);
        if (!in_array($code, [301, 302, 307, 410], true)) {
            $code = 301;
        }

        if ($source === '' || ($target === '' && $code !== 410)) {
            return null;
        }

        $condition = (string) ($redirect['condition'] ?? 'exact-match');
        if ($condition === 'regex') {
            $source = self::convert_regex_source($source);
        }

        return [
            'object_type'   => 'redirection',
            'source_plugin' => $this->plugin_slug,
            'data'          => [],
            'extended'      => [
                'source_url' => $source,
                'target_url' => $target,
                'http_code'  => $code,
                'match_type' => self::MATCH_TYPES[$condition] ?? 'exact',
                'is_regex'   => $condition === 'regex',
                // Slim SEO writes 1/0; a missing flag means the rule predates it.
                'enabled'    => !array_key_exists('enable', $redirect) || !empty($redirect['enable']),
                'note'       => (string) ($redirect['note'] ?? ''),
                'ignore_parameters' => !empty($redirect['ignoreParameters']),
            ],
        ];
    }

    /**
     * Rewrite a Slim SEO regex source for ThinkRank Pro's matcher.
     *
     * Slim SEO tests a pattern against the request path with its slashes
     * trimmed (`blog/post-9`); Pro tests it against the path with a leading
     * slash (`/blog/post-9`). An unanchored pattern matches either way, but
     * one anchored with `^` would never match again, so the slash moves into
     * the anchor. The pattern is first trimmed the way Slim SEO trims it.
     *
     * @param string $pattern Slim SEO `from` value.
     * @return string
     */
    public static function convert_regex_source(string $pattern): string {
        $home = home_url();
        if ($home !== '' && str_starts_with($pattern, $home)) {
            $pattern = substr($pattern, strlen($home));
        }

        // Slim SEO trims surrounding slashes; a trailing `\/` is an escaped
        // slash inside the pattern, not a delimiter, and must keep its slash
        // or the regex ends in a dangling backslash and never compiles.
        $pattern = ltrim($pattern, '/');
        $pattern = (string) preg_replace('#(?<!\\\\)/+$#', '', $pattern);

        if (str_starts_with($pattern, '^')
            && !str_starts_with($pattern, '^/')
            && !str_starts_with($pattern, '^\\/')
        ) {
            $pattern = '^/' . substr($pattern, 1);
        }

        return $pattern;
    }

    /**
     * {@inheritDoc}
     *
     * Slim SEO's 404 log (`url`, `hit`, `updated_at`) into ThinkRank Pro's
     * 404 Monitor. Both store the path relative to the home URL without a
     * leading slash, so the URL carries over as written.
     */
    protected function export_404_logs_page(int $page): array {
        global $wpdb;

        $table = $this->log_404_table();
        if ($table === '') {
            $this->last_page_row_count = 0;
            return [];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                $this->chunk_size,
                ($page - 1) * $this->chunk_size
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $this->last_page_row_count = is_array($rows) ? count($rows) : 0;

        $records = [];
        foreach ((array) $rows as $row) {
            $uri = ltrim(trim((string) ($row['url'] ?? '')), '/');
            if ($uri === '') {
                continue;
            }
            $records[] = [
                'object_type'   => '404_log',
                'source_plugin' => $this->plugin_slug,
                'data'          => [],
                'extended'      => [
                    'uri'            => $uri,
                    'times_accessed' => max(1, (int) ($row['hit'] ?? 1)),
                    'referer'        => '',
                    'user_agent'     => '',
                    'last_accessed'  => (string) ($row['updated_at'] ?? ''),
                ],
            ];
        }

        return $records;
    }

    /**
     * Rows in the 404 log, 0 when the table was never created.
     */
    private function count_404_logs(): int {
        global $wpdb;

        $table = $this->log_404_table();
        if ($table === '') {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is $wpdb->prefix plus a literal.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * The prefixed 404 table name, or '' when it does not exist.
     */
    private function log_404_table(): string {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_404_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return $exists ? $table : '';
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    protected function export_settings(): array {
        $option = get_option(self::OPTION, []);

        return [$this->build_settings_record(is_array($option) ? $option : [])];
    }

    /**
     * The canonical settings record from the `slim_seo` option.
     *
     * @param array $option `slim_seo` option value
     * @return array Settings record
     */
    private function build_settings_record(array $option): array {
        $site = $this->site_context();
        $home = $this->context_settings($option, 'home');
        $author = $this->context_settings($option, 'author');
        $features = $this->active_features($option);

        $twitter = $this->twitter_profile_url((string) ($option['twitter_site'] ?? ''));

        $data = [
            // Imported titles carry %sep% now, so ThinkRank's separator has to
            // be the one Slim SEO printed: `-`, unless the theme filters
            // document_title_separator (ThinkRank does not hook it).
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, not ours to name.
            'separator'            => (string) apply_filters('document_title_separator', self::SEPARATOR),
            'homepage_title'       => $this->render_template((string) ($home['title'] ?? ''), $site),
            // Kept as Site Identity tags like the homepage title, so a site
            // rename still reaches it (#897).
            'homepage_description' => $this->convert_identity_template((string) ($home['description'] ?? ''), ''),
            'social_profiles'      => $twitter !== '' ? ['twitter' => $twitter] : [],
            'noindex_archives'     => [
                'date'   => false,
                'author' => !empty($author['noindex']),
            ],
            'social_defaults'      => [
                'facebook_app_id'       => trim((string) ($option['facebook_app_id'] ?? '')),
                'og_default_image'      => trim((string) ($option['default_facebook_image'] ?? '')),
                'twitter_default_image' => trim((string) ($option['default_twitter_image'] ?? '')),
            ],
        ];

        $extended = [
            'title_formats'      => $this->extract_title_formats($option),
            'post_type_settings' => $this->extract_post_type_settings($option),
            'author_archives'    => array_filter([
                'title'       => $this->convert_identity_template((string) ($author['title'] ?? ''), '%author_name%'),
                'description' => $this->convert_identity_template((string) ($author['description'] ?? ''), '%author_name%'),
            ]),
            // Slim SEO fills an image's empty alt with its title on output.
            'image_seo'          => in_array('images_alt', $features, true) ? ['add_missing_alt' => true] : [],
            'breadcrumb_settings' => ['enabled' => in_array('breadcrumbs', $features, true)],
            'sitemap_settings'   => [
                'enabled'  => in_array('sitemaps', $features, true),
                'has_data' => true,
            ],
            // Slim SEO appends "The post … appeared first on …" to feed items.
            'feed'               => ['source_link' => in_array('feed', $features, true)],
            'robots_txt'         => !empty($option['robots_txt_editable']) && trim((string) ($option['robots_txt_content'] ?? '')) !== ''
                ? ['content' => (string) $option['robots_txt_content']]
                : [],
            // Raw capture for the snapshot, minus the AI provider key: a
            // secret does not belong in an export file.
            'raw_options'        => array_diff_key($option, ['ai_api_key' => true]),
        ];

        // Preserved, not applied: ThinkRank has no equivalent yet. Each one
        // keeps /import/cleanup from deleting the only copy without a warning.
        $code = array_filter([
            'header' => trim((string) ($option['header_code'] ?? '')),
            'body'   => trim((string) ($option['body_code'] ?? '')),
            'footer' => trim((string) ($option['footer_code'] ?? '')),
        ]);
        if (!empty($code)) {
            $extended['code_injection'] = $code;
        }

        // Removing Slim SEO brings /category/ back into every category URL.
        if (in_array('no_category_base', $features, true)) {
            $extended['no_category_base'] = true;
        }

        $redirect_settings = $this->changed_redirect_settings($option);
        if (!empty($redirect_settings)) {
            $extended['redirect_settings'] = $redirect_settings;
        }

        // Per-taxonomy noindex is applied (Content Type Matrix); the rest of
        // each context — custom templates, images — is preserved.
        $taxonomy_settings = $this->extract_taxonomy_settings($option);
        if (!empty($taxonomy_settings)) {
            $extended['taxonomy_settings'] = $taxonomy_settings;
        }

        // Post type archives: only the blog index has a ThinkRank entity, so
        // its noindex is applied and everything else is preserved.
        $archive_settings = $this->extract_post_type_archive_settings($option);
        if (!empty($archive_settings)) {
            $extended['post_type_archive_settings'] = $archive_settings;
        }

        // Slim SEO Pro. Global schemas that convert become Custom Schema
        // entries (ThinkRank Pro); per-post ones ride on each post record and
        // are counted here so cleanup knows they exist. The rest are kept.
        $schemas = $this->schema_converter()->convert_globals($this->posts_suppressing_global_schemas());
        $post_schemas = $this->count_posts_with_meta(self::SCHEMA_META_KEY);
        if (!empty($schemas['entries']) || $post_schemas > 0) {
            $extended['custom_schemas'] = ['entries' => $schemas['entries'], 'post_count' => $post_schemas];
        }
        if (!empty($schemas['preserved'])) {
            $extended['schema_templates'] = $schemas['preserved'];
        }

        $markdown = $this->markdown_settings();
        if (!empty($markdown)) {
            $extended['markdown_for_ai'] = $markdown;
        }

        return [
            'type'          => 'settings',
            'source_plugin' => $this->plugin_slug,
            'data'          => $data,
            'extended'      => $extended,
        ];
    }

    /**
     * Slim SEO Pro's Markdown feature (serve posts as Markdown to clients
     * that ask for it) in ThinkRank Pro's Markdown for AI shape, when it is on.
     *
     * The feature is on by default, so a Pro install whose settings were
     * never saved still has it; a site without Slim SEO Pro carries nothing.
     *
     * @return array{enabled?: bool, post_types?: string[]}
     */
    private function markdown_settings(): array {
        $pro = get_option(self::PRO_OPTION, null);
        if (!is_array($pro) && !$this->is_pro_active()) {
            return [];
        }

        $pro = is_array($pro) ? $pro : [];
        $features = is_array($pro['features'] ?? null) ? array_map('strval', $pro['features']) : self::PRO_DEFAULT_FEATURES;
        if (!in_array('markdown', $features, true)) {
            return [];
        }

        $types = is_array($pro['markdown_post_types'] ?? null) ? array_values(array_filter(array_map('strval', $pro['markdown_post_types']))) : ['post'];

        return ['enabled' => true, 'post_types' => !empty($types) ? $types : ['post']];
    }

    /**
     * Posts on which Slim SEO Pro shows no global schema: a post with active
     * schemas of its own replaces the globals unless its "also show global
     * schemas" box (`sss_allow_global`) is ticked (Factory\Base::get_schemas()).
     *
     * @return int[]
     */
    private function posts_suppressing_global_schemas(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = %s
                 AND NOT EXISTS (
                     SELECT 1 FROM {$wpdb->postmeta} allow
                     WHERE allow.post_id = pm.post_id AND allow.meta_key = %s AND allow.meta_value <> ''
                 )
                 ORDER BY pm.post_id ASC",
                self::SCHEMA_META_KEY,
                self::ALLOW_GLOBAL_META
            ),
            ARRAY_A
        );

        $post_ids = [];
        foreach ((array) $rows as $row) {
            if (Slim_SEO_Schema_Converter::has_active_schemas($this->read_meta_array($row['meta_value'] ?? ''))) {
                $post_ids[] = (int) $row['post_id'];
            }
        }

        return array_values(array_unique($post_ids));
    }

    /**
     * Whether Slim SEO Pro is active.
     */
    private function is_pro_active(): bool {
        return in_array('slim-seo-pro/slim-seo-pro.php', (array) get_option('active_plugins', []), true);
    }

    /**
     * Posts carrying one meta key.
     *
     * @param string $meta_key Meta key
     */
    private function count_posts_with_meta(string $meta_key): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key)
        );
    }

    /**
     * Features Slim SEO has switched on. An empty or missing list means every
     * default feature, exactly as Settings::is_feature_active() reads it.
     *
     * @param array $option `slim_seo` option value
     * @return string[]
     */
    private function active_features(array $option): array {
        $features = $option['features'] ?? [];

        if (is_array($features) && !empty($features)) {
            return array_values(array_map('strval', $features));
        }

        return array_values(array_diff(self::DEFAULT_FEATURES, self::FEATURES_OFF_UNTIL_SAVED));
    }

    /**
     * The X/Twitter profile URL for Slim SEO's `twitter_site`, which is
     * printed verbatim as `twitter:site`: usually `@handle`, sometimes a
     * full profile URL. A URL is kept; a handle becomes one.
     *
     * @param string $value Stored `twitter_site`
     * @return string Profile URL, or '' when there is nothing usable
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
     * One context's settings array (`home`, `author`, a post type, …).
     *
     * @param array  $option `slim_seo` option value
     * @param string $key    Context key
     * @return array
     */
    private function context_settings(array $option, string $key): array {
        return is_array($option[$key] ?? null) ? $option[$key] : [];
    }

    /**
     * Slim SEO's per-context titles onto ThinkRank's Site Identity keys.
     *
     * @param array $option `slim_seo` option value
     * @return array ThinkRank title-format key => template
     */
    private function extract_title_formats(array $option): array {
        $sources = [
            'homepage_title' => ['home', ''],
            'post_title'     => ['post', '%post_title%'],
            'page_title'     => ['page', '%page_title%'],
            'category_title' => ['category', '%category_title%'],
            'tag_title'      => ['post_tag', '%tag_title%'],
        ];

        $formats = [];
        foreach ($sources as $tr_key => [$context, $context_token]) {
            $raw = (string) ($this->context_settings($option, $context)['title'] ?? '');
            $converted = $this->convert_identity_template($raw, $context_token);
            if ($converted !== '') {
                $formats[$tr_key] = $converted;
            }
        }

        return $formats;
    }

    /**
     * Per-post-type title/description templates and noindex in the shape
     * migrate_post_type_settings() consumes.
     *
     * @param array $option `slim_seo` option value
     * @return array post_type => {title_template, description_template, custom_robots, robots}
     */
    private function extract_post_type_settings(array $option): array {
        $settings = [];

        foreach ($option as $key => $value) {
            $key = (string) $key;
            if (!is_array($value) || !$this->is_post_type_context($key)) {
                continue;
            }

            $pt = [];
            $title = $this->convert_global_template((string) ($value['title'] ?? ''));
            if ($title !== '') {
                $pt['title_template'] = $title;
            }
            $description = $this->convert_global_template((string) ($value['description'] ?? ''));
            if ($description !== '') {
                $pt['description_template'] = $description;
            }
            if (!empty($value['noindex'])) {
                $pt['custom_robots'] = true;
                $pt['robots'] = ['noindex'];
            }

            if (!empty($pt)) {
                $settings[$key] = $pt;
            }
        }

        return $settings;
    }

    /**
     * Per-taxonomy contexts: `noindex` (applied through the Content Type
     * Matrix) plus any template or image the user wrote, kept in Slim SEO's
     * own syntax. The category and tag titles are already in title_formats,
     * and a template equal to Slim SEO's default is dropped — ThinkRank
     * renders the same thing without it.
     *
     * @param array $option `slim_seo` option value
     * @return array taxonomy => {noindex?, title?, description?, facebook_image?, twitter_image?}
     */
    private function extract_taxonomy_settings(array $option): array {
        $kept = [];
        foreach ($option as $key => $value) {
            $key = (string) $key;
            if (!$this->is_taxonomy_context($key, $value)) {
                continue;
            }

            $entry = $this->non_default_context($value, self::DEFAULT_TEMPLATES['term']);
            if (in_array($key, ['category', 'post_tag'], true)) {
                unset($entry['title']);
            }
            if (!empty($value['noindex'])) {
                $entry = ['noindex' => true] + $entry;
            }
            if (!empty($entry)) {
                $kept[$key] = $entry;
            }
        }

        return $kept;
    }

    /**
     * `{post_type}_archive` contexts, keyed by post type: `noindex` plus any
     * non-default template or image, in Slim SEO's own syntax.
     *
     * @param array $option `slim_seo` option value
     * @return array post_type => {noindex?, title?, description?, facebook_image?, twitter_image?}
     */
    private function extract_post_type_archive_settings(array $option): array {
        $kept = [];
        foreach ($option as $key => $value) {
            $key = (string) $key;
            if (!is_array($value) || substr($key, -8) !== '_archive' || in_array($key, self::NON_CONTEXT_KEYS, true)) {
                continue;
            }
            $post_type = substr($key, 0, -8);
            if ($post_type === '' || !function_exists('post_type_exists') || !post_type_exists($post_type)) {
                continue;
            }

            $entry = $this->non_default_context($value, self::DEFAULT_TEMPLATES['post_archive']);
            if (!empty($value['noindex'])) {
                $entry = ['noindex' => true] + $entry;
            }
            if (!empty($entry)) {
                $kept[$post_type] = $entry;
            }
        }

        return $kept;
    }

    /**
     * Whether an option key is a taxonomy's settings array.
     *
     * @param string $key   Option key
     * @param mixed  $value Option value
     */
    private function is_taxonomy_context(string $key, $value): bool {
        if (!is_array($value) || in_array($key, self::NON_CONTEXT_KEYS, true) || in_array($key, ['home', 'author'], true)) {
            return false;
        }
        if (substr($key, -8) === '_archive' || $this->is_post_type_context($key)) {
            return false;
        }

        return function_exists('taxonomy_exists') && taxonomy_exists($key);
    }

    /**
     * A context's templates and images, minus empty values and minus the
     * templates that only restate Slim SEO's defaults.
     *
     * @param array $value    Context settings
     * @param array $defaults Slim SEO's default title/description for it
     * @return array
     */
    private function non_default_context(array $value, array $defaults): array {
        $entry = [];
        foreach (['title', 'description', 'facebook_image', 'twitter_image'] as $field) {
            $text = is_scalar($value[$field] ?? null) ? trim((string) $value[$field]) : '';
            if ($text === '') {
                continue;
            }
            if (isset($defaults[$field]) && $this->canonical_template($text) === $this->canonical_template($defaults[$field])) {
                continue;
            }
            $entry[$field] = $text;
        }

        return $entry;
    }

    /**
     * A template reduced to what it renders: `{{ page }}` (empty on page
     * one) removed, separators collapsed, whitespace normalised — so
     * `{{ term.name }} {{ sep }} {{ site.title }}` equals Slim SEO's default
     * `{{ term.name }} {{ sep }} {{ page }} {{ sep }} {{ site.title }}`.
     *
     * @param string $template Slim SEO template
     */
    private function canonical_template(string $template): string {
        $template = (string) preg_replace('/\{\{\s*page\s*\}\}/', '', $template);
        $template = (string) preg_replace('/\{\{\s*([^}\s]+?)\s*\}\}/', '{{ $1 }}', $template);
        $segments = array_filter(
            array_map('trim', explode('{{ sep }}', $template)),
            static fn(string $s): bool => $s !== ''
        );

        return trim((string) preg_replace('/\s+/', ' ', implode(' {{ sep }} ', $segments)));
    }

    /**
     * Whether an option key names a post type's single-item settings.
     *
     * @param string $key Option key
     */
    private function is_post_type_context(string $key): bool {
        if (in_array($key, self::NON_CONTEXT_KEYS, true) || in_array($key, ['home', 'author'], true)) {
            return false;
        }
        if (substr($key, -8) === '_archive') {
            return false;
        }
        if (function_exists('taxonomy_exists') && taxonomy_exists($key)) {
            return false;
        }

        return function_exists('post_type_exists') && post_type_exists($key);
    }

    /**
     * Redirect behaviour settings the user moved off Slim SEO's defaults.
     *
     * @param array $option `slim_seo` option value
     * @return array
     */
    private function changed_redirect_settings(array $option): array {
        $changed = [];
        foreach (self::REDIRECT_SETTING_DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $option)) {
                continue;
            }
            $value = $option[$key];
            // Slim SEO saves an unticked checkbox as -1 and reads it back as
            // 0 (Redirection\Settings::option_saved() / list()), so -1 is
            // "off", not a change (#899). Only the checkboxes: elsewhere -1 is
            // a real value.
            if (in_array($key, self::REDIRECT_CHECKBOXES, true) && is_numeric($value) && (int) $value === -1) {
                $value = 0;
            }
            if ((string) $value !== (string) $default) {
                $changed[$key] = $option[$key];
            }
        }

        return $changed;
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     *
     * Resolve a Slim SEO template against a post (or the site alone).
     */
    protected function convert_template_variables($value, ?int $post_id = null): string {
        $value = $this->stringify_template_value($value);

        return $this->render_template($value, $post_id ? $this->post_context($post_id) : $this->site_context());
    }

    /**
     * Slim SEO per-post paths ThinkRank resolves per request (#886): kept as
     * tags rather than frozen into text. `post.excerpt` (empty when the post
     * has none, where %excerpt% falls back to the content) and
     * `post.categories` (every category, where %category% is the first) stay
     * literal: mapping them would change what the title says.
     */
    private const POST_TOKENS = [
        'post.title'            => '%title%',
        'site.title'            => '%sitename%',
        'sep'                   => '%sep%',
        'post.auto_description' => '%excerpt%',
        'post.date'             => '%date%',
        'post.modified_date'    => '%modified%',
        'author.display_name'   => '%author%',
    ];

    /**
     * Slim SEO per-term paths ThinkRank resolves on a term archive.
     * `term.description` stays literal: %excerpt% is the trimmed description.
     */
    private const TERM_TOKENS = [
        'term.name'             => '%term%',
        'term.auto_description' => '%excerpt%',
        'site.title'            => '%sitename%',
        'sep'                   => '%sep%',
    ];

    /**
     * A per-post Slim SEO value: mapped paths as ThinkRank tags, the rest
     * rendered against the post. The data tree (content, every meta row,
     * terms, author) is only built when something is left to render.
     *
     * @param string $value   Raw Slim SEO value
     * @param int    $post_id Post ID
     * @return string
     */
    private function convert_post_value(string $value, int $post_id): string {
        return $this->tokenize_object_template(
            $value,
            self::brace_token_patterns(self::POST_TOKENS),
            fn(string $rest): string => $this->render_template($rest, $this->has_variables($rest) ? $this->post_context($post_id) : [])
        );
    }

    /**
     * A per-term Slim SEO value: mapped paths as ThinkRank tags, the rest
     * rendered against the term.
     *
     * @param string        $value Raw Slim SEO value
     * @param \WP_Term|null $term  The term, when it still exists
     * @return string
     */
    private function convert_term_value(string $value, ?\WP_Term $term): string {
        return $this->tokenize_object_template(
            $value,
            self::brace_token_patterns(self::TERM_TOKENS),
            fn(string $rest): string => $this->render_template($rest, $this->has_variables($rest) ? $this->term_context($term) : [])
        );
    }

    /**
     * Resolve `{{ path }}` variables against a data tree, then collapse
     * separators the way Slim SEO's Helper::normalize() does: split on
     * `{{ sep }}`, drop empty segments, rejoin with the separator.
     *
     * Unknown paths resolve to '' rather than staying literal — a stored
     * `{{ post.custom }}` must not reach a live title tag.
     *
     * @param string $template Slim SEO template
     * @param array  $context  Data tree (post, term, site, author, …)
     * @return string Resolved text
     */
    private function render_template(string $template, array $context): string {
        if (!$this->has_variables($template)) {
            return trim($template);
        }

        $text = (string) preg_replace_callback(
            '/\{\{\s*([^}\s]+?)\s*\}\}/',
            function (array $m) use ($context): string {
                $path = (string) $m[1];
                if ($path === 'sep') {
                    return self::SEP_MARK;
                }
                $value = $this->lookup($context, $path);
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
                }

                return is_scalar($value) ? (string) $value : '';
            },
            $template
        );

        $segments = array_filter(
            array_map('trim', explode(self::SEP_MARK, $text)),
            static fn(string $s): bool => $s !== ''
        );
        $text = implode(' ' . self::SEPARATOR . ' ', $segments);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Whether a template needs a data tree at all.
     *
     * @param string $template Slim SEO template
     */
    private function has_variables(string $template): bool {
        return $template !== '' && strpos($template, '{{') !== false;
    }

    /**
     * Walk a dotted path through nested arrays.
     *
     * @param array  $context Data tree
     * @param string $path    e.g. "post.custom_field.price"
     * @return mixed|null
     */
    private function lookup(array $context, string $path) {
        $node = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Data tree for site-level templates.
     */
    private function site_context(): array {
        $option = get_option(self::OPTION, []);
        $option = is_array($option) ? $option : [];

        return [
            'site'    => [
                'title'          => (string) get_bloginfo('name'),
                'description'    => (string) get_bloginfo('description'),
                'facebook_image' => trim((string) ($option['default_facebook_image'] ?? '')),
                'twitter_image'  => trim((string) ($option['default_twitter_image'] ?? '')),
            ],
            'current' => [
                'year'  => function_exists('wp_date') ? (string) wp_date('Y') : gmdate('Y'),
                'month' => function_exists('wp_date') ? (string) wp_date('m') : gmdate('m'),
            ],
            'page'    => '',
        ];
    }

    /**
     * Data tree for a post, mirroring SlimSEO\MetaTags\Data\Post.
     *
     * @param int $post_id Post ID
     */
    private function post_context(int $post_id): array {
        $context = $this->site_context();
        $post = get_post($post_id);
        if (!$post) {
            return $context;
        }

        $content = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $post->post_content))));
        $excerpt = (string) $post->post_excerpt;
        $date_format = (string) get_option('date_format', 'F j, Y');

        $custom_fields = [];
        foreach ((array) get_post_meta($post_id) as $key => $values) {
            $custom_fields[(string) $key] = is_array($values) ? (string) reset($values) : '';
        }

        $context['post'] = [
            'title'            => (string) $post->post_title,
            'excerpt'          => $excerpt,
            'content'          => $content,
            'auto_description' => $this->truncate($excerpt !== '' ? wp_strip_all_tags($excerpt) : $content),
            'date'             => $this->format_date((string) ($post->post_date_gmt ?? $post->post_date ?? ''), $date_format),
            'modified_date'    => $this->format_date((string) ($post->post_modified_gmt ?? $post->post_modified ?? ''), $date_format),
            'thumbnail'        => function_exists('get_the_post_thumbnail_url') ? (string) get_the_post_thumbnail_url($post_id, 'full') : '',
            'categories'       => $this->term_names($post_id, 'category'),
            'tags'             => $this->term_names($post_id, 'post_tag'),
            'custom_field'     => $custom_fields,
            'tax'              => $this->post_taxonomies_context($post_id, (string) $post->post_type),
        ];

        $post_type = function_exists('get_post_type_object') ? get_post_type_object((string) $post->post_type) : null;
        $context['post_type'] = [
            'labels' => [
                'singular' => (string) ($post_type->labels->singular_name ?? ''),
                'plural'   => (string) ($post_type->labels->name ?? ''),
            ],
        ];

        $author_id = (int) ($post->post_author ?? 0);
        $context['author'] = $this->author_context($author_id);

        return $context;
    }

    /**
     * `post.tax.{taxonomy}` — term names per taxonomy of the post's type,
     * keyed the way Slim SEO's Data\Post::normalize() keys them (`-` → `_`),
     * so `{{ post.tax.product_cat }}` resolves on a WooCommerce site.
     *
     * @param int    $post_id   Post ID
     * @param string $post_type Post type
     * @return array<string,string[]>
     */
    private function post_taxonomies_context(int $post_id, string $post_type): array {
        if (!function_exists('get_object_taxonomies')) {
            return [];
        }

        $tax = [];
        foreach ((array) get_object_taxonomies($post_type, 'names') as $taxonomy) {
            $tax[str_replace('-', '_', (string) $taxonomy)] = $this->term_names($post_id, (string) $taxonomy);
        }

        return $tax;
    }

    /**
     * Data tree for a term, mirroring SlimSEO\MetaTags\Data\Term.
     *
     * @param \WP_Term|null $term The term
     */
    private function term_context(?\WP_Term $term): array {
        $context = $this->site_context();
        if (!$term) {
            return $context;
        }

        $description = trim(wp_strip_all_tags((string) $term->description));
        $context['term'] = [
            'name'             => (string) $term->name,
            'description'      => $description,
            'auto_description' => $this->truncate($description),
        ];

        return $context;
    }

    /**
     * Author data Slim SEO exposes as `author.*`.
     *
     * @param int $user_id User ID
     */
    private function author_context(int $user_id): array {
        if ($user_id <= 0 || !function_exists('get_the_author_meta')) {
            return [];
        }

        $description = (string) get_the_author_meta('description', $user_id);

        return [
            'display_name'     => (string) get_the_author_meta('display_name', $user_id),
            'description'      => $description,
            'auto_description' => $this->truncate($description),
        ];
    }

    /**
     * Term names for a post in one taxonomy.
     *
     * @param int    $post_id  Post ID
     * @param string $taxonomy Taxonomy
     * @return string[]
     */
    private function term_names(int $post_id, string $taxonomy): array {
        if (!function_exists('get_the_terms')) {
            return [];
        }
        $terms = get_the_terms($post_id, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_map(static fn($t) => (string) $t->name, $terms));
    }

    /**
     * Slim SEO's Helper::truncate(): 160 characters, whitespace collapsed.
     *
     * @param string $text Text
     */
    private function truncate(string $text): string {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, 160) : substr($text, 0, 160);
    }

    /**
     * A stored GMT date in the site's date format.
     *
     * @param string $date   MySQL datetime
     * @param string $format PHP date format
     */
    private function format_date(string $date, string $format): string {
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($date . ' UTC');

        return $ts ? (string) wp_date($format, $ts) : '';
    }

    /**
     * Convert a Slim SEO template into ThinkRank's Site Identity vocabulary
     * (%site_title%/%sep%/%post_title%…). Paths ThinkRank cannot resolve are
     * dropped, along with a separator that would be left dangling.
     *
     * @param string $template      Slim SEO template
     * @param string $context_token Token post.title/term.name stand for ('' when none)
     * @return string ThinkRank Site Identity template
     */
    private function convert_identity_template(string $template, string $context_token): string {
        $map = [
            'site.title'          => '%site_title%',
            'site.description'    => '%site_description%',
            'sep'                 => '%sep%',
            'post.date'           => '%date%',
            'author.display_name' => '%author_name%',
            'post.categories'     => '%category_title%',
            'post.tags'           => '%tag_title%',
        ];
        if ($context_token !== '') {
            $map['post.title'] = $context_token;
            $map['term.name']  = $context_token;
        }

        return $this->convert_to_tokens($template, $map, '%sep%');
    }

    /**
     * Convert a Slim SEO template into the Global SEO Pattern_Resolver
     * vocabulary (%title%/%sitename%/%sep%/%excerpt%).
     *
     * @param string $template Slim SEO template
     * @return string Converted template
     */
    private function convert_global_template(string $template): string {
        $map = [
            'post.title'            => '%title%',
            'site.title'            => '%sitename%',
            'sep'                   => '%sep%',
            'post.excerpt'          => '%excerpt%',
            'post.auto_description' => '%excerpt%',
            'post.date'             => '%date%',
            'author.display_name'   => '%author%',
            'post.categories'       => '%category%',
        ];

        return $this->convert_to_tokens($template, $map, '%sep%');
    }

    /**
     * Shared token conversion: mapped paths become tokens, the rest vanish,
     * and separators left with nothing on one side are removed.
     *
     * @param string $template Slim SEO template
     * @param array  $map      Slim path => ThinkRank token
     * @param string $sep      The target vocabulary's separator token
     * @return string
     */
    private function convert_to_tokens(string $template, array $map, string $sep): string {
        $template = trim($template);
        if ($template === '' || strpos($template, '{{') === false) {
            return $template;
        }

        $converted = (string) preg_replace_callback(
            '/\{\{\s*([^}\s]+?)\s*\}\}/',
            static function (array $m) use ($map): string {
                return (string) ($map[$m[1]] ?? '');
            },
            $template
        );

        $segments = array_filter(
            array_map('trim', explode($sep, $converted)),
            static fn(string $s): bool => $s !== ''
        );

        return trim((string) preg_replace('/\s+/', ' ', implode(' ' . $sep . ' ', $segments)));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A stored `slim_seo` value as an array. WordPress unserializes it for
     * us; anything else is foreign data and reads as empty.
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
     * One string field of a meta array, trimmed.
     *
     * @param array  $meta Meta array
     * @param string $key  Field
     */
    private function meta_string(array $meta, string $key): string {
        $value = $meta[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
