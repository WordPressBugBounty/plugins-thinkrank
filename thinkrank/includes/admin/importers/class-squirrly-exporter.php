<?php

/**
 * Squirrly SEO Exporter
 *
 * Reads Squirrly SEO ("GEO Plugin by Squirrly SEO") data and normalizes it
 * into the canonical snapshot format.
 *
 * Squirrly keeps per-URL SEO in its own `{prefix}qss` table, NOT in postmeta
 * (#740). Each row carries the object it belongs to in a serialized `post`
 * column ({ID, post_type, term_id, taxonomy}) and the SEO values in a
 * serialized `seo` column (SQ_Models_Domain_Sq::toArray()). One table holds
 * posts, terms, author profiles and the homepage, so every paged reader below
 * walks the same table and emits only the rows of its own kind.
 *
 * Settings live in one option, `sq_options`, stored as a JSON string. Title
 * and description templates use Squirrly's `{{token}}` syntax.
 *
 * Squirrly's cloud data (Briefcase keywords, Focus Pages, Rankings, Audits,
 * AI Visibility) never touches the site's database and cannot be exported.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Squirrly SEO Exporter Class
 *
 * @since 2.8.0
 */
class Squirrly_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Source plugin slug.
     */
    public const SLUG = 'squirrly';

    /**
     * Squirrly's per-URL SEO table (without the WordPress prefix).
     */
    private const TABLE = 'qss';

    /**
     * Advanced Pack redirects table (without the WordPress prefix).
     */
    private const REDIRECTS_TABLE = 'qss_redirects';

    /**
     * Squirrly `jsonld_types` slug => ThinkRank schema type.
     *
     * ThinkRank's supported types come from Schema_Settings_Config::
     * get_supported_schema_types() (PascalCase). Slugs with no equivalent map
     * to '' so the migrator's empty-skip leaves no invalid value behind.
     */
    private const SCHEMA_TYPE_MAP = [
        'article'      => 'Article',
        'newsarticle'  => 'Article',
        'blogposting'  => 'Article',
        'product'      => 'Product',
        'website'      => 'WebSite',
        'organization' => 'Organization',
        'person'       => 'Person',
        'event'        => 'Event',
        'review'       => 'Review',
        'video'        => 'VideoObject',
        'videoobject'  => 'VideoObject',
        'faq'          => 'FAQPage',
        'faqpage'      => 'FAQPage',
        'howto'        => 'HowTo',
        'service'      => 'LocalBusiness',
        'local'        => 'LocalBusiness',
        'localbusiness' => 'LocalBusiness',
        'localstore'   => 'LocalBusiness',
        'localrestaurant' => 'LocalBusiness',
        'software'     => 'SoftwareApplication',
        'softwareapplication' => 'SoftwareApplication',
    ];

    /**
     * Pattern keys in `sq_options.patterns` that describe a context rather
     * than a post type, so they never become per-post-type templates.
     */
    private const NON_POST_TYPE_PATTERNS = ['home', 'category', 'tag', 'shop', 'profile', 'archive', 'search', '404', 'custom'];

    /**
     * Decoded `sq_options`, cached for the request.
     *
     * @var array|null
     */
    private ?array $options_cache = null;

    /**
     * Row-kind counts, cached for the request.
     *
     * @var array|null
     */
    private ?array $kind_counts = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug = self::SLUG;
        $this->plugin_name = 'Squirrly SEO';
        $this->plugin_file = 'squirrly-seo/squirrly.php';
        // Squirrly only writes a handful of fallback keys to postmeta
        // (_sq_keywords, _sq_description, _sq_jsonld_*); the SEO itself is in
        // the qss table. The prefix exists for cleanup and the meta fallbacks.
        $this->meta_key_prefix = '_sq_';
        $this->option_keys = ['sq_options'];
    }

    /**
     * {@inheritDoc}
     */
    public function detect(): bool {
        if ($this->table_exists() && $this->count_rows() > 0) {
            return true;
        }

        return get_option('sq_options', null) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function get_available_types(): array {
        $types = [];
        $counts = $this->get_kind_counts();

        if (!empty($counts['post'])) {
            $types['postmeta'] = $counts['post'];
        }
        if (!empty($counts['term'])) {
            $types['termmeta'] = $counts['term'];
        }
        if (!empty($counts['user'])) {
            $types['usermeta'] = $counts['user'];
        }

        $redirects = $this->count_redirects();
        if ($redirects > 0) {
            $types['redirections'] = $redirects;
        }

        if (get_option('sq_options', null) !== null) {
            $types['settings'] = 1;
        }

        return $types;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_postmeta_page(int $page): array {
        $records = [];

        foreach ($this->get_rows_page($page) as $row) {
            $parsed = $this->classify_row($row);
            if ($parsed['kind'] !== 'post') {
                continue;
            }

            $post_id = $parsed['object_id'];
            $seo = $parsed['seo'];

            // Squirrly falls back to these postmeta keys when the row is
            // empty (SQ_Models_Domain_Sq), so honour the same precedence.
            $description = $this->seo_string($seo, 'description');
            if ($description === '') {
                $description = (string) get_post_meta($post_id, '_sq_description', true);
            }
            $keywords_raw = $this->seo_string($seo, 'keywords');
            if ($keywords_raw === '') {
                $keywords_raw = (string) get_post_meta($post_id, '_sq_keywords', true);
            }
            $focus_keywords = $this->split_keywords($keywords_raw);
            $robots = $this->extract_robots($seo);

            $redirect_url = $this->seo_string($seo, 'redirect');

            $records[] = [
                'object_id'     => $post_id,
                'object_type'   => 'post',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'           => $this->convert_template_variables($this->seo_string($seo, 'title'), $post_id),
                    'meta_description'    => $this->convert_template_variables($description, $post_id),
                    'focus_keyword'       => $focus_keywords[0] ?? '',
                    'focus_keywords'      => $focus_keywords,
                    'canonical_url'       => $this->seo_string($seo, 'canonical'),
                    'noindex'             => $this->seo_flag($seo, 'noindex'),
                    'nofollow'            => $this->seo_flag($seo, 'nofollow'),
                    'noarchive'           => $robots['noarchive'],
                    'noimageindex'        => $robots['noimageindex'],
                    'nosnippet'           => $robots['nosnippet'],
                    'og_title'            => $this->convert_template_variables($this->seo_string($seo, 'og_title'), $post_id),
                    'og_description'      => $this->convert_template_variables($this->seo_string($seo, 'og_description'), $post_id),
                    'og_image'            => $this->seo_string($seo, 'og_media'),
                    'twitter_title'       => $this->convert_template_variables($this->seo_string($seo, 'tw_title'), $post_id),
                    'twitter_description' => $this->convert_template_variables($this->seo_string($seo, 'tw_description'), $post_id),
                    'twitter_image'       => $this->seo_string($seo, 'tw_media'),
                    'primary_category'    => (int) ($seo['primary_category'] ?? 0),
                    'schema_type'         => $this->map_schema_type($seo['jsonld_types'] ?? null),
                    // Squirrly's `focuspage` marks a page as one of the site's
                    // important ones — the same concept Yoast calls cornerstone
                    // and ThinkRank calls pillar content. Squirrly's own
                    // ImportExport maps it to `yst_is_cornerstone`, so this
                    // follows the source plugin's reading of its own field.
                    // The Focus Pages *audit* stays on Squirrly's servers and
                    // is not importable; the flag itself is local to the row.
                    'pillar_content'      => $this->seo_flag($seo, 'focuspage'),
                ],
                'extended' => [
                    'is_cornerstone'            => $this->seo_flag($seo, 'focuspage') === 1,
                    'focus_keywords_additional' => array_slice($focus_keywords, 1),
                    'redirect_enabled'          => $redirect_url !== '',
                    'redirect_url'              => $redirect_url,
                    'redirect_type'             => (string) ((int) ($seo['redirect_type'] ?? 301) ?: 301),
                    'exclude_from_sitemap'      => $this->seo_flag($seo, 'nositemap') === 1,
                    'og_type'                   => $this->seo_string($seo, 'og_type'),
                    'twitter_card_type'         => $this->seo_string($seo, 'tw_type'),
                    'squirrly_enabled'          => !isset($seo['doseo']) || (int) $seo['doseo'] !== 0,
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_termmeta_page(int $page): array {
        $records = [];

        foreach ($this->get_rows_page($page) as $row) {
            $parsed = $this->classify_row($row);
            if ($parsed['kind'] !== 'term') {
                continue;
            }

            $seo = $parsed['seo'];
            $term = get_term($parsed['object_id'], $parsed['taxonomy']);
            $term = ($term instanceof \WP_Term) ? $term : null;

            $records[] = [
                'object_id'     => $parsed['object_id'],
                'object_type'   => 'term',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'seo_title'        => $this->convert_term_template($this->seo_string($seo, 'title'), $term),
                    'meta_description' => $this->convert_term_template($this->seo_string($seo, 'description'), $term),
                    'canonical_url'    => $this->seo_string($seo, 'canonical'),
                    'noindex'          => $this->seo_flag($seo, 'noindex'),
                    'nofollow'         => $this->seo_flag($seo, 'nofollow'),
                    'og_title'         => $this->convert_term_template($this->seo_string($seo, 'og_title'), $term),
                    'og_description'   => $this->convert_term_template($this->seo_string($seo, 'og_description'), $term),
                ],
                'extended' => [
                    'taxonomy'            => $parsed['taxonomy'],
                    'og_image'            => $this->seo_string($seo, 'og_media'),
                    'twitter_title'       => $this->seo_string($seo, 'tw_title'),
                    'twitter_description' => $this->seo_string($seo, 'tw_description'),
                    'twitter_image'       => $this->seo_string($seo, 'tw_media'),
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     *
     * Squirrly stores author-archive SEO as `profile` rows in the same table
     * (ID = user id). The title/description go in canonical `data` so the
     * plugin-agnostic migrator writes the USER meta Author_Archives_Manager
     * reads; ThinkRank consumes those verbatim, so tokens resolve to literals
     * here with {{name}} seeded from the display name.
     */
    protected function export_usermeta_page(int $page): array {
        $records = [];

        foreach ($this->get_rows_page($page) as $row) {
            $parsed = $this->classify_row($row);
            if ($parsed['kind'] !== 'user') {
                continue;
            }

            $user_id = $parsed['object_id'];
            $seo = $parsed['seo'];
            $display_name = (string) get_the_author_meta('display_name', $user_id);

            $title = str_replace(['{{name}}', '{{ name }}'], $display_name, $this->seo_string($seo, 'title'));
            $desc  = str_replace(['{{name}}', '{{ name }}'], $display_name, $this->seo_string($seo, 'description'));

            if ($title === '' && $desc === '') {
                continue;
            }

            $records[] = [
                'object_id'     => $user_id,
                'object_type'   => 'user',
                'source_plugin' => $this->plugin_slug,
                'data'          => [
                    'seo_title'        => $this->convert_template_variables($title),
                    'meta_description' => $this->convert_template_variables($desc),
                ],
                'extended'      => [
                    'author_noindex' => $this->seo_flag($seo, 'noindex') === 1,
                ],
            ];
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     */
    protected function export_settings(): array {
        $opts = $this->get_options();
        $patterns = is_array($opts['patterns'] ?? null) ? $opts['patterns'] : [];
        $socials = is_array($opts['socials'] ?? null) ? $opts['socials'] : [];
        $codes = is_array($opts['codes'] ?? null) ? $opts['codes'] : [];
        $jsonld = is_array($opts['sq_jsonld'] ?? null) ? $opts['sq_jsonld'] : [];
        $organization = is_array($jsonld['Organization'] ?? null) ? $jsonld['Organization'] : [];
        $person = is_array($jsonld['Person'] ?? null) ? $jsonld['Person'] : [];

        // The homepage row in the qss table wins over the home pattern: it is
        // what the owner typed for the homepage specifically.
        $home = $this->get_home_seo();
        $home_pattern = is_array($patterns['home'] ?? null) ? $patterns['home'] : [];
        $homepage_title = $this->seo_string($home, 'title');
        if ($homepage_title === '') {
            $homepage_title = (string) ($home_pattern['title'] ?? '');
        }
        $homepage_description = $this->seo_string($home, 'description');
        if ($homepage_description === '') {
            $homepage_description = (string) ($home_pattern['description'] ?? '');
        }

        $post_pattern = is_array($patterns['post'] ?? null) ? $patterns['post'] : [];
        $separator = trim((string) ($post_pattern['sep'] ?? ($home_pattern['sep'] ?? '|')));

        $indexnow_key = $opts['indexnow_key'] ?? '';
        $indexnow_types = is_array($opts['indexnow_post_type'] ?? null)
            ? array_values(array_map('strval', $opts['indexnow_post_type']))
            : [];

        return [
            [
                'type'          => 'settings',
                'source_plugin' => $this->plugin_slug,
                'data' => [
                    'separator'            => $separator !== '' ? $separator : '|',
                    'homepage_title'       => $this->convert_template_variables($homepage_title),
                    'homepage_description' => $this->convert_template_variables($homepage_description),
                    'organization_name'    => $this->convert_template_variables((string) ($organization['name'] ?? '')),
                    'organization_logo'    => (string) ($organization['logo']['url'] ?? ''),
                    'knowledge_graph'      => $this->extract_knowledge_graph($opts, $organization, $person),
                    'social_profiles'      => [
                        'facebook'  => (string) ($socials['facebook_site'] ?? ''),
                        'twitter'   => (string) ($socials['twitter_site'] ?? ''),
                        'instagram' => (string) ($socials['instagram_url'] ?? ''),
                        'linkedin'  => (string) ($socials['linkedin_url'] ?? ''),
                        'youtube'   => (string) ($socials['youtube_url'] ?? ''),
                        'pinterest' => (string) ($socials['pinterest_url'] ?? ''),
                    ],
                    'noindex_archives' => [
                        'date'   => !empty($patterns['archive']['noindex']),
                        'author' => !empty($patterns['profile']['noindex']),
                    ],
                    'twitter_card_type' => (string) ($socials['twitter_card_type'] ?? ''),
                    'social_defaults'   => [
                        'facebook_app_id'  => (string) ($socials['fbadminapp'] ?? ''),
                        'og_default_image' => (string) ($opts['sq_og_image'] ?? ''),
                    ],
                    'instant_indexing'  => [
                        'api_key' => is_string($indexnow_key) ? $indexnow_key : '',
                    ],
                ],
                'extended' => [
                    // Pinterest is applied (ThinkRank renders it); the rest is
                    // preserved and gates /import/cleanup until #631 renders it.
                    'webmaster_tools' => array_filter([
                        'google'    => (string) ($codes['google_wt'] ?? ''),
                        'bing'      => (string) ($codes['bing_wt'] ?? ''),
                        'yandex'    => (string) ($codes['yandex_wt'] ?? ''),
                        'baidu'     => (string) ($codes['baidu_wt'] ?? ''),
                        'pinterest' => (string) ($codes['pinterest_verify'] ?? ''),
                    ]),
                    'title_formats'               => $this->extract_title_formats($patterns),
                    'post_type_settings'          => $this->extract_post_type_settings($patterns),
                    'author_archives'             => $this->extract_author_archives($patterns),
                    'local_seo'                   => $this->extract_local_seo($opts, $organization),
                    'sitemap_settings'            => $this->normalize_sitemap($opts),
                    'instant_indexing_post_types' => $indexnow_types,
                    'focus_pages'                 => $this->fetch_focus_pages(),
                ],
            ],
        ];
    }

    /**
     * The post ids Squirrly has marked as Focus Pages.
     *
     * These are the one part of the migration that is not in the database.
     * Squirrly keeps the list on its own servers — `getFocusPages()` is a live
     * call to `api/posts/focus` — and writes nothing locally: the `focuspage`
     * field on a `qss` row is declared but never populated, and the only local
     * trace is an `sq_auditpage_<cloud id>` transient keyed by Squirrly's id
     * rather than WordPress's. So a site that switches to ThinkRank loses the
     * selection entirely unless it is read while Squirrly is still installed
     * and connected, which is exactly when an import runs.
     *
     * The response carries `post_id`, the local WordPress id, so no URL
     * matching is needed. Credentials are Squirrly's to handle and never
     * touch the snapshot; only the resulting ids are stored.
     *
     * Everything here is best-effort: no Squirrly, no connection, an API
     * error or a changed response shape all yield an empty list and the rest
     * of the settings export carries on.
     *
     * @return int[] Local post ids, in the order Squirrly returned them.
     */
    private function fetch_focus_pages(): array {
        if (!class_exists('SQ_Classes_ObjController')) {
            return [];
        }

        try {
            // Squirrly autoloads its classes through this controller; the
            // remote controller is not loaded until something asks for it.
            \SQ_Classes_ObjController::getClass('SQ_Classes_RemoteController');

            if (!class_exists('SQ_Classes_RemoteController')
                || !method_exists('SQ_Classes_RemoteController', 'getFocusPages')) {
                return [];
            }

            $response = \SQ_Classes_RemoteController::getFocusPages();
        } catch (\Throwable $e) {
            return [];
        }

        if (is_wp_error($response) || empty($response)) {
            return [];
        }

        $ids = [];
        foreach ((array) $response as $page) {
            $post_id = (int) (((array) $page)['post_id'] ?? 0);

            // A focus page for content that has since been deleted, or for
            // another site on the same Squirrly account, has nothing to point
            // at here.
            if ($post_id > 0 && get_post($post_id) !== null) {
                $ids[] = $post_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * {@inheritDoc}
     *
     * Advanced Pack redirects (`{prefix}qss_redirects`, a Redirection-plugin
     * lineage schema): `url` is the source path, `action_data` the target,
     * `action_code` the status, `regex` the flag, `status` enabled/disabled.
     * Only `url` actions become redirects; `error` (serve a 404) and `pass`
     * have no ThinkRank equivalent.
     */
    protected function export_redirections_page(int $page): array {
        if (!$this->table_exists(self::REDIRECTS_TABLE)) {
            return [];
        }

        global $wpdb;

        $table = $wpdb->prefix . self::REDIRECTS_TABLE;
        $offset = ($page - 1) * $this->chunk_size;

        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $this->chunk_size, $offset),
            ARRAY_A
        );

        $this->last_page_row_count = is_array($rows) ? count($rows) : 0;

        if (empty($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            $record = $this->map_redirect_row((array) $row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Map one qss_redirects row onto the canonical redirection record.
     *
     * @param array $row Table row
     * @return array|null Record, or null when the row is not a URL redirect
     */
    private function map_redirect_row(array $row): ?array {
        if (($row['action_type'] ?? 'url') !== 'url') {
            return null;
        }

        $source = trim((string) ($row['url'] ?? ''));
        $target = $this->extract_redirect_target($row['action_data'] ?? '');

        if ($source === '' || $target === '') {
            return null;
        }

        $code = (int) ($row['action_code'] ?? 301);
        if (!in_array($code, [301, 302, 303, 307, 308], true)) {
            $code = 301;
        }

        return [
            'object_type'   => 'redirection',
            'source_plugin' => $this->plugin_slug,
            'data'          => [],
            'extended'      => [
                'source_url' => $source,
                'target_url' => $target,
                'http_code'  => $code,
                'is_regex'   => !empty($row['regex']),
                'enabled'    => ($row['status'] ?? 'enabled') === 'enabled',
                'hits'       => (int) ($row['last_count'] ?? 0),
            ],
        ];
    }

    /**
     * `action_data` is a plain target for simple rules and a JSON/serialized
     * object ({url: …} or {url_from, url_notfrom}) for conditional ones.
     *
     * @param mixed $data Raw action_data
     * @return string Target URL or ''
     */
    private function extract_redirect_target($data): string {
        if (is_array($data)) {
            return (string) ($data['url'] ?? ($data['url_from'] ?? ''));
        }
        $data = trim((string) $data);
        if ($data === '') {
            return '';
        }
        if ($data[0] === '{' || $data[0] === '[') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                return (string) ($decoded['url'] ?? ($decoded['url_from'] ?? ''));
            }
        }
        if (is_serialized($data)) {
            $decoded = Safe_Unserializer::to_array($data);
            return (string) ($decoded['url'] ?? ($decoded['url_from'] ?? ''));
        }
        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * Squirrly's `{{token}}` syntax resolved to literals for a stored value.
     * Per-post tokens need a post; site tokens resolve anywhere. Unknown
     * tokens are stripped so nothing renders literally after import.
     */
    protected function convert_template_variables($value, ?int $post_id = null): string {
        // Foreign data first: booleans/arrays in the source plugin's options
        // must degrade to '' here, not fatal the migration (see abstract).
        $value = $this->stringify_template_value($value);

        if ($value === '' || strpos($value, '{{') === false) {
            return $value;
        }

        $replacements = [
            'sitename'    => get_bloginfo('name'),
            'sitedesc'    => get_bloginfo('description'),
            'sep'         => '-',
            'page'        => '',
            'keyword'     => '',
            'searchphrase' => '',
            'plural'      => '',
            'url'         => '',
        ];

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $replacements['title']   = $post->post_title;
                $replacements['excerpt'] = \ThinkRank\Core\Seo_Text::trim_words(
                    $post->post_excerpt ?: \ThinkRank\Core\Seo_Text::trim_words(wp_strip_all_tags($post->post_content), 55),
                    55
                );
                $replacements['date'] = get_the_date('', $post);
                $replacements['name'] = get_the_author_meta('display_name', (int) $post->post_author);
                $replacements['url']  = (string) get_permalink($post);

                $categories = get_the_category($post_id);
                $replacements['category'] = !empty($categories) ? $categories[0]->name : '';
                $replacements['category_description'] = !empty($categories) ? (string) $categories[0]->description : '';

                $tags = get_the_tags($post_id);
                $replacements['tag'] = !empty($tags) ? $tags[0]->name : '';
            }
        }

        $value = (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($replacements): string {
                $token = strtolower($m[1]);
                return array_key_exists($token, $replacements) ? (string) $replacements[$token] : '';
            },
            $value
        );

        $value = (string) preg_replace('/\s{2,}/', ' ', $value);

        return trim($value);
    }

    /**
     * Resolve a term-level value: the generic converter only knows post
     * context, so {{term_title}}/{{title}}/{{category}}/{{tag}} are seeded
     * with the term name and {{category_description}} with its description
     * first. Without this a category title of "{{term_title}} archive" would
     * import as " archive" — a silent loss, not a literal token.
     *
     * @param string        $value Raw stored value
     * @param \WP_Term|null $term  The term, when it still exists
     * @return string Resolved value
     */
    private function convert_term_template(string $value, ?\WP_Term $term): string {
        if ($value === '' || strpos($value, '{{') === false) {
            return trim($value);
        }

        if ($term) {
            $seeded = [
                'term_title'           => $term->name,
                'title'                => $term->name,
                'category'             => $term->name,
                'tag'                  => $term->name,
                'category_description' => (string) $term->description,
                'excerpt'              => (string) $term->description,
            ];
            $value = (string) preg_replace_callback(
                '/\{\{\s*(term_title|title|category|tag|category_description|excerpt)\s*\}\}/i',
                static function (array $m) use ($seeded): string {
                    return $seeded[strtolower($m[1])] ?? '';
                },
                $value
            );
        }

        return $this->convert_template_variables($value);
    }

    // -------------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------------

    /**
     * Squirrly's per-context patterns onto ThinkRank's Site Identity keys, in
     * the identity renderer's %token% vocabulary.
     *
     * @param array $patterns sq_options.patterns
     * @return array Map of ThinkRank title-format key => converted template
     */
    private function extract_title_formats(array $patterns): array {
        $sources = [
            'homepage_title' => ['home', ''],
            'post_title'     => ['post', '%post_title%'],
            'page_title'     => ['page', '%page_title%'],
            'category_title' => ['category', '%category_title%'],
            'tag_title'      => ['tag', '%tag_title%'],
            'search_title'   => ['search', '%search_term%'],
            'archive_title'  => ['archive', '%archive_title%'],
            'author_title'   => ['profile', '%author_name%'],
        ];

        $formats = [];
        foreach ($sources as $tr_key => [$pattern_key, $context_token]) {
            $raw = (string) ($patterns[$pattern_key]['title'] ?? '');
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
     * Convert a Squirrly pattern into ThinkRank's Site Identity token
     * vocabulary, preserving structure. Tokens ThinkRank cannot resolve are
     * stripped and a separator left dangling by that strip is dropped.
     *
     * @param string $template      Raw Squirrly pattern ({{token}} syntax)
     * @param string $context_token Token {{title}}/{{term_title}} stands for (may be '')
     * @return string ThinkRank Site Identity template
     */
    private function convert_identity_pattern(string $template, string $context_token): string {
        if ($template === '' || strpos($template, '{{') === false) {
            return trim($template);
        }

        $map = [
            'sitename'     => '%site_title%',
            'sitedesc'     => '%site_description%',
            'sep'          => '%sep%',
            'date'         => '%date%',
            'searchphrase' => '%search_term%',
            'name'         => '%author_name%',
            'category'     => '%category_title%',
            'tag'          => '%tag_title%',
        ];
        if ($context_token !== '') {
            $map['title']      = $context_token;
            $map['term_title'] = $context_token;
        }

        $template = (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($map): string {
                $token = strtolower($m[1]);
                return $map[$token] ?? '';
            },
            $template
        );

        $template = (string) preg_replace('/\s{2,}/', ' ', $template);
        $template = trim($template);
        $template = (string) preg_replace('/^(?:%sep%)\s*/', '', $template);
        $template = (string) preg_replace('/\s*(?:%sep%)$/', '', $template);

        return trim($template);
    }

    /**
     * Convert a Squirrly pattern into the Global SEO Pattern_Resolver
     * vocabulary (%title%/%sitename%/%sep%/%excerpt%).
     *
     * @param string $template Raw Squirrly pattern
     * @return string Converted template
     */
    private function convert_global_pattern(string $template): string {
        if ($template === '' || strpos($template, '{{') === false) {
            return trim($template);
        }

        $map = [
            'title'    => '%title%',
            'sitename' => '%sitename%',
            'sep'      => '%sep%',
            'excerpt'  => '%excerpt%',
            'date'     => '%date%',
            'name'     => '%author%',
            'category' => '%category%',
        ];

        $template = (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($map): string {
                return $map[strtolower($m[1])] ?? '';
            },
            $template
        );

        $template = (string) preg_replace('/\s+/', ' ', $template);

        return trim($template);
    }

    /**
     * Per-post-type title/description templates and robots in the shape
     * migrate_post_type_settings() consumes. Squirrly keys patterns by post
     * type slug; context keys (home, category, tax-*, …) are skipped and the
     * migrator drops any slug that isn't a registered post type.
     *
     * @param array $patterns sq_options.patterns
     * @return array Map of post_type => {title_template, description_template, custom_robots, robots}
     */
    private function extract_post_type_settings(array $patterns): array {
        $settings = [];

        foreach ($patterns as $key => $pattern) {
            $key = (string) $key;
            if (!is_array($pattern) || in_array($key, self::NON_POST_TYPE_PATTERNS, true) || strpos($key, 'tax-') === 0) {
                continue;
            }

            $pt_settings = [];

            $title = $this->convert_global_pattern((string) ($pattern['title'] ?? ''));
            if ($title !== '') {
                $pt_settings['title_template'] = $title;
            }

            $description = $this->convert_global_pattern((string) ($pattern['description'] ?? ''));
            if ($description !== '') {
                $pt_settings['description_template'] = $description;
            }

            $directives = [];
            foreach (['noindex', 'nofollow'] as $flag) {
                if (!empty($pattern[$flag])) {
                    $directives[] = $flag;
                }
            }
            if (!empty($directives)) {
                $pt_settings['custom_robots'] = true;
                $pt_settings['robots'] = $directives;
            }

            if (!empty($pt_settings)) {
                $settings[$key] = $pt_settings;
            }
        }

        return $settings;
    }

    /**
     * Author archive behaviour from the `profile` pattern. The noindex flag
     * travels in data.noindex_archives.
     *
     * @param array $patterns sq_options.patterns
     * @return array Author archive settings
     */
    private function extract_author_archives(array $patterns): array {
        $profile = is_array($patterns['profile'] ?? null) ? $patterns['profile'] : [];
        if (empty($profile)) {
            return [];
        }

        return [
            'enabled'     => empty($profile['noindex']),
            'title'       => $this->convert_identity_pattern((string) ($profile['title'] ?? ''), '%author_name%'),
            'description' => $this->convert_identity_pattern((string) ($profile['description'] ?? ''), '%author_name%'),
        ];
    }

    /**
     * Knowledge Graph entity from sq_jsonld_type + the matching sq_jsonld block.
     *
     * @param array $opts         Decoded sq_options
     * @param array $organization sq_jsonld.Organization
     * @param array $person       sq_jsonld.Person
     * @return array{type: string, name: string}
     */
    private function extract_knowledge_graph(array $opts, array $organization, array $person): array {
        $type = strtolower((string) ($opts['sq_jsonld_type'] ?? 'Organization'));
        if ($type === 'person') {
            $name = $this->convert_template_variables((string) ($person['name'] ?? ''));
            return $name === '' ? ['type' => '', 'name' => ''] : ['type' => 'person', 'name' => $name];
        }

        $name = $this->convert_template_variables((string) ($organization['name'] ?? ''));
        if ($name === '') {
            return ['type' => '', 'name' => ''];
        }

        // Any local-business subtype is still an organization for the graph.
        return ['type' => 'organization', 'name' => $name];
    }

    /**
     * Local SEO in the canonical shape migrate_settings() reads, only when
     * Squirrly's Local SEO JSON-LD is switched on. The NAP lives in the
     * Organization block; hours and price range in sq_jsonld_local.
     *
     * @param array $opts         Decoded sq_options
     * @param array $organization sq_jsonld.Organization
     * @return array Canonical local_seo payload, or [] when off
     */
    private function extract_local_seo(array $opts, array $organization): array {
        if (empty($opts['sq_auto_jsonld_local'])) {
            return [];
        }

        $local = is_array($opts['sq_jsonld_local'] ?? null) ? $opts['sq_jsonld_local'] : [];
        $address = is_array($organization['address'] ?? null) ? $organization['address'] : [];
        $geo = is_array($organization['place']['geo'] ?? null) ? $organization['place']['geo'] : [];

        $type = (string) ($opts['sq_jsonld_type'] ?? '');
        if ($type === '' || in_array(strtolower($type), ['organization', 'person'], true)) {
            $type = 'LocalBusiness';
        }

        $address_out = array_filter([
            'street'      => (string) ($address['streetAddress'] ?? ''),
            'city'        => (string) ($address['addressLocality'] ?? ''),
            'state'       => (string) ($address['addressRegion'] ?? ''),
            'postal_code' => (string) ($address['postalCode'] ?? ''),
            'country'     => (string) ($address['addressCountry'] ?? ''),
        ]);

        $geo_out = [];
        if (($geo['latitude'] ?? '') !== '' && ($geo['longitude'] ?? '') !== '') {
            $geo_out = [
                'latitude'  => (string) $geo['latitude'],
                'longitude' => (string) $geo['longitude'],
            ];
        }

        return [
            'business_type' => $type,
            'business_name' => $this->convert_template_variables((string) ($organization['name'] ?? '')),
            'phone'         => (string) ($organization['contactPoint']['telephone'] ?? ''),
            'address'       => $address_out,
            'geo'           => $geo_out,
            'price_range'   => (string) ($local['priceRange'] ?? ''),
            'opening_hours' => $this->extract_opening_hours($local),
        ];
    }

    /**
     * sq_jsonld_local.openingHoursSpecification ({dayOfWeek, opens, closes}
     * rows) onto ThinkRank's lowercase-day map. Days without both times are
     * skipped rather than written as closed: Squirrly leaves them blank by
     * default, which says nothing about the business.
     *
     * @param array $local sq_jsonld_local
     * @return array Map of day => {open, close, closed}
     */
    private function extract_opening_hours(array $local): array {
        $spec = $local['openingHoursSpecification'] ?? [];
        if (!is_array($spec)) {
            return [];
        }

        $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $result = [];

        foreach ($spec as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $day = strtolower((string) ($entry['dayOfWeek'] ?? ''));
            $opens = trim((string) ($entry['opens'] ?? ''));
            $closes = trim((string) ($entry['closes'] ?? ''));
            if (!in_array($day, $valid_days, true) || isset($result[$day]) || $opens === '' || $closes === '') {
                continue;
            }
            $result[$day] = [
                'open'   => $opens,
                'close'  => $closes,
                'closed' => false,
            ];
        }

        return $result;
    }

    /**
     * Sitemap keys onto the flat canonical `sitemap_settings` shape.
     * `sq_sitemap` maps `sitemap-<type>` => [filename, enabled].
     *
     * @param array $opts Decoded sq_options
     * @return array Canonical sitemap_settings payload
     */
    private function normalize_sitemap(array $opts): array {
        if (!array_key_exists('sq_auto_sitemap', $opts) && !array_key_exists('sq_sitemap', $opts)) {
            return ['has_data' => false];
        }

        $files = is_array($opts['sq_sitemap'] ?? null) ? $opts['sq_sitemap'] : [];
        $show = is_array($opts['sq_sitemap_show'] ?? null) ? $opts['sq_sitemap_show'] : [];

        $included = static function (string $key) use ($files): bool {
            $entry = $files[$key] ?? null;
            return is_array($entry) && !empty($entry[1]);
        };

        $result = [
            'enabled'            => !empty($opts['sq_auto_sitemap']),
            'include_images'     => !empty($show['images']),
            'include_posts'      => $included('sitemap-post'),
            'include_pages'      => $included('sitemap-page'),
            'include_categories' => $included('sitemap-category'),
            'include_tags'       => $included('sitemap-post_tag'),
            'ping_search_engines' => !empty($opts['sq_sitemap_ping']),
            'has_data'           => true,
        ];

        $per_page = (int) ($opts['sq_sitemap_perpage'] ?? 0);
        if ($per_page > 0) {
            $result['links_per_sitemap'] = $per_page;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Row helpers
    // -------------------------------------------------------------------------

    /**
     * Classify one qss row by its serialized `post` column.
     *
     * @param array $row Table row (URL, post, seo)
     * @return array{kind: string, object_id: int, post_type: string, taxonomy: string, seo: array}
     */
    private function classify_row(array $row): array {
        $post = Safe_Unserializer::to_array($row['post'] ?? '');
        $seo = Safe_Unserializer::to_array($row['seo'] ?? '');

        $id = (int) ($post['ID'] ?? 0);
        $post_type = (string) ($post['post_type'] ?? '');
        $term_id = (int) ($post['term_id'] ?? 0);
        $taxonomy = (string) ($post['taxonomy'] ?? '');

        $kind = 'other';
        $object_id = 0;

        if ($term_id > 0 && $taxonomy !== '') {
            $kind = 'term';
            $object_id = $term_id;
        } elseif ($post_type === 'profile' && $id > 0) {
            $kind = 'user';
            $object_id = $id;
        } elseif ($post_type === 'home') {
            $kind = 'home';
        } elseif ($id > 0) {
            $kind = 'post';
            $object_id = $id;
        }

        return [
            'kind'      => $kind,
            'object_id' => $object_id,
            'post_type' => $post_type,
            'taxonomy'  => $taxonomy,
            'seo'       => $seo,
        ];
    }

    /**
     * A string field from the seo array; Squirrly stores NULL for anything
     * never set.
     *
     * @param array  $seo Parsed seo array
     * @param string $key Field
     * @return string
     */
    private function seo_string(array $seo, string $key): string {
        $value = $seo[$key] ?? '';
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }

    /**
     * A 0/1 flag from the seo array.
     *
     * @param array  $seo Parsed seo array
     * @param string $key Field
     * @return int
     */
    private function seo_flag(array $seo, string $key): int {
        return !empty($seo[$key]) ? 1 : 0;
    }

    /**
     * Comma-separated keywords => trimmed, de-duplicated list.
     *
     * @param string $raw Raw keywords string
     * @return string[]
     */
    private function split_keywords(string $raw): array {
        if ($raw === '') {
            return [];
        }
        $keywords = array_map('trim', explode(',', $raw));
        $keywords = array_filter($keywords, static fn($keyword) => $keyword !== '');
        return array_values(array_unique($keywords));
    }

    /**
     * Advanced robots directives from the seo `robots` field, which may be an
     * array of directive names or a comma-separated string.
     *
     * @param array $seo Parsed seo array
     * @return array{noarchive: int, noimageindex: int, nosnippet: int}
     */
    private function extract_robots(array $seo): array {
        $raw = $seo['robots'] ?? null;
        $directives = [];
        if (is_array($raw)) {
            $directives = array_map('strval', $raw);
        } elseif (is_string($raw) && $raw !== '') {
            $directives = array_map('trim', explode(',', $raw));
        }
        $directives = array_map('strtolower', $directives);

        return [
            'noarchive'    => in_array('noarchive', $directives, true) ? 1 : 0,
            'noimageindex' => in_array('noimageindex', $directives, true) ? 1 : 0,
            'nosnippet'    => in_array('nosnippet', $directives, true) ? 1 : 0,
        ];
    }

    /**
     * Squirrly `jsonld_types` (array of slugs, or one slug) => ThinkRank type.
     * The first slug with a ThinkRank equivalent wins; `website` on a post is
     * Squirrly's default rather than a choice, so it only counts when nothing
     * else is set.
     *
     * @param mixed $types Raw jsonld_types
     * @return string ThinkRank schema type or ''
     */
    private function map_schema_type($types): string {
        if (is_string($types)) {
            $types = [$types];
        }
        if (!is_array($types)) {
            return '';
        }

        $fallback = '';
        foreach ($types as $type) {
            $key = strtolower(trim((string) $type));
            if ($key === '' || !isset(self::SCHEMA_TYPE_MAP[$key])) {
                continue;
            }
            if ($key === 'website') {
                $fallback = self::SCHEMA_TYPE_MAP[$key];
                continue;
            }
            return self::SCHEMA_TYPE_MAP[$key];
        }

        return $fallback;
    }

    // -------------------------------------------------------------------------
    // Storage helpers
    // -------------------------------------------------------------------------

    /**
     * Decoded `sq_options`. Squirrly stores it as a JSON string; older
     * versions used a serialized array.
     *
     * @return array
     */
    private function get_options(): array {
        if ($this->options_cache !== null) {
            return $this->options_cache;
        }

        $raw = get_option('sq_options', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = is_serialized($raw) ? Safe_Unserializer::to_array($raw) : [];
            }
            $raw = $decoded;
        }

        $this->options_cache = is_array($raw) ? $raw : [];

        return $this->options_cache;
    }

    /**
     * Whether a Squirrly table exists.
     *
     * @param string $suffix Table suffix without prefix
     * @return bool
     */
    private function table_exists(string $suffix = self::TABLE): bool {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;

        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * Total qss rows for this blog.
     *
     * @return int
     */
    private function count_rows(): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE blog_id = %d", get_current_blog_id()));
    }

    /**
     * Advanced Pack redirect rows that are URL redirects.
     *
     * @return int
     */
    private function count_redirects(): int {
        if (!$this->table_exists(self::REDIRECTS_TABLE)) {
            return 0;
        }

        global $wpdb;

        $table = $wpdb->prefix . self::REDIRECTS_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE action_type = 'url'");
    }

    /**
     * Row counts per kind (post/term/user/home), from the `post` column only.
     *
     * @return array
     */
    private function get_kind_counts(): array {
        if ($this->kind_counts !== null) {
            return $this->kind_counts;
        }

        $counts = ['post' => 0, 'term' => 0, 'user' => 0, 'home' => 0, 'other' => 0];

        if ($this->table_exists()) {
            global $wpdb;

            $table = $wpdb->prefix . self::TABLE;
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
                $wpdb->prepare("SELECT post FROM {$table} WHERE blog_id = %d", get_current_blog_id()),
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $kind = $this->classify_row(['post' => $row['post'] ?? '', 'seo' => ''])['kind'];
                $counts[$kind]++;
            }
        }

        $this->kind_counts = $counts;

        return $counts;
    }

    /**
     * One page of qss rows, in id order. Sets last_page_row_count so
     * export_chunk() paginates on rows fetched, not records emitted (a page
     * of term rows yields no post records but is not the end).
     *
     * @param int $page Page number (1-indexed)
     * @return array Rows
     */
    private function get_rows_page(int $page): array {
        if (!$this->table_exists()) {
            $this->last_page_row_count = 0;
            return [];
        }

        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $offset = ($page - 1) * $this->chunk_size;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
                "SELECT id, URL, post, seo FROM {$table} WHERE blog_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
                get_current_blog_id(),
                $this->chunk_size,
                $offset
            ),
            ARRAY_A
        );

        $this->last_page_row_count = is_array($rows) ? count($rows) : 0;

        return is_array($rows) ? $rows : [];
    }

    /**
     * The homepage row's seo array, when Squirrly has one.
     *
     * @return array
     */
    private function get_home_seo(): array {
        if (!$this->table_exists()) {
            return [];
        }

        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
                "SELECT post, seo FROM {$table} WHERE blog_id = %d AND post LIKE %s LIMIT 5",
                get_current_blog_id(),
                '%' . $wpdb->esc_like('"home"') . '%'
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $parsed = $this->classify_row($row);
            if ($parsed['kind'] === 'home') {
                return $parsed['seo'];
            }
        }

        return [];
    }
}
