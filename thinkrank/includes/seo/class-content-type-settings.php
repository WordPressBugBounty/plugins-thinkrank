<?php
/**
 * Per-content-type feature policy.
 *
 * Global SEO settings were per post type for metas, robots meta and the schema
 * *type* only; Open Graph, Twitter cards, schema on/off and analytics were
 * site-wide, and taxonomies, author/date archives, search results and 404 had
 * no entry at all. This class is the single place that answers "is <feature>
 * on for <this content type>?" for every read path (#660).
 *
 * Storage reuses the existing `thinkrank_global_seo_settings` option, keyed by
 * an entity key: a post type is stored under its own slug (so every value
 * already saved by the per-post-type screen keeps working untouched), and the
 * new non-post-type entities live under prefixed keys that can never collide
 * with a post type slug (`taxonomy:`, `archive:`, `special:`).
 *
 * Every feature flag is a THREE-state value — 'inherit' | 'on' | 'off' — and
 * defaults to 'inherit'. Inherit resolves to the feature's existing global
 * setting, so an install that has never touched the matrix produces byte
 * identical output to before.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.5.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-content-type feature policy and resolver.
 *
 * @since 2.5.0
 */
class Content_Type_Settings {

    /**
     * Option holding the per-entity settings (shared with Global_SEO_Endpoint).
     *
     * @var string
     */
    public const OPTION_NAME = 'thinkrank_global_seo_settings';

    /**
     * Entity key prefixes for the non-post-type entities.
     */
    public const PREFIX_TAXONOMY = 'taxonomy:';
    public const PREFIX_ARCHIVE  = 'archive:';
    public const PREFIX_SPECIAL  = 'special:';

    public const ENTITY_BLOG_INDEX     = self::PREFIX_ARCHIVE . 'blog';
    public const ENTITY_AUTHOR_ARCHIVE = self::PREFIX_ARCHIVE . 'author';
    public const ENTITY_DATE_ARCHIVE   = self::PREFIX_ARCHIVE . 'date';
    public const ENTITY_SEARCH         = self::PREFIX_SPECIAL . 'search';
    public const ENTITY_404            = self::PREFIX_SPECIAL . '404';

    /**
     * Switchable features. The value is the settings key each is stored under.
     */
    public const FEATURE_META       = 'meta_enabled';
    public const FEATURE_SCHEMA     = 'schema_enabled';
    public const FEATURE_OPEN_GRAPH = 'open_graph_enabled';
    public const FEATURE_TWITTER    = 'twitter_card_enabled';
    public const FEATURE_ANALYTICS  = 'analytics_enabled';

    /**
     * Every tri-state feature key handled by this class.
     *
     * Sitemap inclusion is deliberately NOT here: it keeps living in the
     * sitemap settings' own `include_*` / `exclude_*` flags so the presets UI
     * and the generator's regex matching stay the single storage format.
     *
     * @var string[]
     */
    public const FEATURES = [
        self::FEATURE_META,
        self::FEATURE_SCHEMA,
        self::FEATURE_OPEN_GRAPH,
        self::FEATURE_TWITTER,
        self::FEATURE_ANALYTICS,
    ];

    /**
     * Allowed tri-state values.
     *
     * @var string[]
     */
    public const STATES = ['inherit', 'on', 'off'];

    /**
     * Memoised copy of the option for the current request.
     *
     * @var array|null
     */
    private static ?array $cache = null;

    /**
     * Drop the memoised option copy (used after a write, and by tests).
     *
     * @return void
     */
    public static function flush_cache(): void {
        self::$cache = null;
    }

    /**
     * All stored per-entity settings.
     *
     * @return array<string, array>
     */
    public static function all_settings(): array {
        if (self::$cache === null) {
            $stored      = get_option(self::OPTION_NAME, []);
            self::$cache = is_array($stored) ? $stored : [];
        }

        return self::$cache;
    }

    /**
     * Stored settings for one entity ('' when nothing saved).
     *
     * @param string $entity_key Entity key.
     * @return array
     */
    public static function get_entity_settings(string $entity_key): array {
        $all = self::all_settings();

        return is_array($all[$entity_key] ?? null) ? $all[$entity_key] : [];
    }

    /**
     * The stored tri-state for one feature on one entity.
     *
     * @param string $entity_key Entity key.
     * @param string $feature    One of self::FEATURES.
     * @return string 'inherit' | 'on' | 'off'
     */
    public static function feature_state(string $entity_key, string $feature): string {
        $value = self::get_entity_settings($entity_key)[$feature] ?? 'inherit';

        return in_array($value, self::STATES, true) ? $value : 'inherit';
    }

    /**
     * Resolve a feature for an entity, falling back to the global default.
     *
     * @param string      $feature        One of self::FEATURES.
     * @param string|null $entity_key     Entity key, or null when the request maps to none.
     * @param bool        $global_default The feature's current site-wide value.
     * @return bool
     */
    public static function is_enabled(string $feature, ?string $entity_key, bool $global_default): bool {
        if ($entity_key === null || $entity_key === '') {
            return $global_default;
        }

        $state = self::feature_state($entity_key, $feature);

        if ($state === 'on') {
            return true;
        }
        if ($state === 'off') {
            return false;
        }

        return $global_default;
    }

    /**
     * Resolve a feature for the entity the current request maps to.
     *
     * @param string $feature        One of self::FEATURES.
     * @param bool   $global_default The feature's current site-wide value.
     * @return bool
     */
    public static function is_enabled_for_current(string $feature, bool $global_default): bool {
        return self::is_enabled($feature, self::current_entity_key(), $global_default);
    }

    /**
     * Map the current main query to an entity key.
     *
     * Mirrors SEO_Manager::detect_current_context() but resolves to the entity
     * whose settings apply, so a category archive answers `taxonomy:category`
     * rather than the generic `category` context string.
     *
     * @return string|null Entity key, or null when nothing applies (e.g. feeds).
     */
    public static function current_entity_key(): ?string {
        if (!function_exists('is_404')) {
            return null;
        }

        if (is_404()) {
            return self::ENTITY_404;
        }

        if (is_search()) {
            return self::ENTITY_SEARCH;
        }

        // The blog posts index, which is the homepage on a default install.
        // It is not singular even when it IS the front page, so it needs its
        // own branch or it resolves to null and every feature silently falls
        // back to the global default.
        if (function_exists('is_home') && is_home()) {
            return self::ENTITY_BLOG_INDEX;
        }

        if (is_singular()) {
            $post_type = (string) get_post_type();

            return $post_type !== '' ? $post_type : null;
        }

        if (is_category() || is_tag() || is_tax()) {
            $queried = get_queried_object();

            return $queried instanceof \WP_Term ? self::PREFIX_TAXONOMY . $queried->taxonomy : null;
        }

        if (is_author()) {
            return self::ENTITY_AUTHOR_ARCHIVE;
        }

        if (is_date()) {
            return self::ENTITY_DATE_ARCHIVE;
        }

        if (is_post_type_archive()) {
            $queried = get_queried_object();

            return $queried instanceof \WP_Post_Type ? $queried->name : null;
        }

        return null;
    }

    /**
     * Whether an entity key names something that exists on this site.
     *
     * @param string $entity_key Entity key.
     * @return bool
     */
    public static function is_valid_entity(string $entity_key): bool {
        foreach (self::get_entities() as $entity) {
            if ($entity['key'] === $entity_key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every configurable entity on this site, in display order.
     *
     * Each entry: key, label, group, features (applicable tri-state features),
     * supports_sitemap, supports_robots, and — for post types and taxonomies —
     * the object slug the sitemap flags are keyed by.
     *
     * @return array<int, array>
     */
    public static function get_entities(): array {
        $entities = [];

        foreach (get_post_types(['public' => true], 'objects') as $post_type) {
            if (!Global_SEO_Post_Types::is_allowed($post_type)) {
                continue;
            }

            $entities[] = [
                'key'              => $post_type->name,
                'label'            => $post_type->label,
                'group'            => 'post_type',
                'object'           => $post_type->name,
                'features'         => self::FEATURES,
                'supports_sitemap' => self::sitemap_accepts_post_type($post_type->name),
                'supports_robots'  => true,
            ];
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            if (empty($taxonomy->public)) {
                continue;
            }

            $entities[] = [
                'key'              => self::PREFIX_TAXONOMY . $taxonomy->name,
                'label'            => $taxonomy->label,
                'group'            => 'taxonomy',
                'object'           => $taxonomy->name,
                'features'         => self::FEATURES,
                'supports_sitemap' => self::sitemap_accepts_taxonomy($taxonomy->name),
                'supports_robots'  => true,
            ];
        }

        $entities[] = [
            'key'              => self::ENTITY_BLOG_INDEX,
            'label'            => __('Blog posts index', 'thinkrank'),
            'group'            => 'archive',
            'object'           => '',
            'features'         => self::FEATURES,
            // The posts page is a `page` in the sitemap, included or excluded
            // with every other page; it has no inclusion flag of its own.
            'supports_sitemap' => false,
            'supports_robots'  => true,
        ];

        $entities[] = [
            'key'              => self::ENTITY_AUTHOR_ARCHIVE,
            'label'            => __('Author archives', 'thinkrank'),
            'group'            => 'archive',
            'object'           => '',
            'features'         => self::FEATURES,
            'supports_sitemap' => false,
            'supports_robots'  => true,
        ];

        $entities[] = [
            'key'              => self::ENTITY_DATE_ARCHIVE,
            'label'            => __('Date archives', 'thinkrank'),
            'group'            => 'archive',
            'object'           => '',
            'features'         => self::FEATURES,
            'supports_sitemap' => false,
            'supports_robots'  => true,
        ];

        // Search and 404 carry no schema: neither is a page with an entity to
        // describe, and ThinkRank emits none there today.
        $special_features = array_values(array_diff(self::FEATURES, [self::FEATURE_SCHEMA]));

        $entities[] = [
            'key'              => self::ENTITY_SEARCH,
            'label'            => __('Search results', 'thinkrank'),
            'group'            => 'special',
            'object'           => '',
            'features'         => $special_features,
            'supports_sitemap' => false,
            'supports_robots'  => true,
        ];

        $entities[] = [
            'key'              => self::ENTITY_404,
            'label'            => __('404 page', 'thinkrank'),
            'group'            => 'special',
            'object'           => '',
            'features'         => $special_features,
            'supports_sitemap' => false,
            'supports_robots'  => true,
        ];

        /**
         * Filter the configurable content-type matrix entities.
         *
         * @since 2.5.0
         *
         * @param array $entities Entity descriptors.
         */
        return (array) apply_filters('thinkrank_content_type_entities', $entities);
    }

    /**
     * Robots defaults for an entity.
     *
     * Search results and 404 default to noindex/follow — the behaviour that was
     * hardcoded in the frontend before this became settings-driven — so the
     * default install keeps them out of the index.
     *
     * @param string $entity_key Entity key.
     * @return array
     */
    public static function default_robots_meta(string $entity_key): array {
        $noindexed = in_array($entity_key, [self::ENTITY_SEARCH, self::ENTITY_404], true);

        return [
            'index'        => !$noindexed,
            'noindex'      => $noindexed,
            'nofollow'     => false,
            'noarchive'    => false,
            'noimageindex' => false,
            'nosnippet'    => false,
        ];
    }

    /**
     * Effective robots directives for an entity, defaults included.
     *
     * Returns the stored `robots_meta` overlaid on the entity defaults when the
     * entity's `robots_meta_enabled` switch is on, and the defaults otherwise.
     *
     * @param string $entity_key Entity key.
     * @return array
     */
    public static function resolve_robots_meta(string $entity_key): array {
        $defaults = self::default_robots_meta($entity_key);
        $settings = self::get_entity_settings($entity_key);

        if (empty($settings['robots_meta_enabled']) || !is_array($settings['robots_meta'] ?? null)) {
            return $defaults;
        }

        $resolved = array_merge($defaults, $settings['robots_meta']);

        // `index` and `noindex` are one decision stored as two booleans, and
        // merging them separately could leave both false (or both true).
        // build_robots_directives() resolves that sanely today, but the stored
        // pair should not say two different things: noindex is the explicit
        // instruction, so index is whatever it is not.
        $resolved['index'] = empty($resolved['noindex']);

        return $resolved;
    }

    /**
     * Legacy sitemap inclusion flags, kept as the storage format.
     *
     * The presets UI and the generator both read these names, so the matrix is
     * a second surface onto the same flags rather than a new format.
     *
     * @var array<string, string>
     */
    private const SITEMAP_LEGACY_FLAGS = [
        'post_type:post'     => 'include_posts',
        'post_type:page'     => 'include_pages',
        'taxonomy:category'  => 'include_categories',
        'taxonomy:post_tag'  => 'include_tags',
    ];

    /**
     * The sitemap settings key holding one object's inclusion flag.
     *
     * @param string $group  'post_type' or 'taxonomy'.
     * @param string $object_slug Post type or taxonomy slug.
     * @return string
     */
    public static function sitemap_flag_key(string $group, string $object_slug): string {
        return self::SITEMAP_LEGACY_FLAGS[$group . ':' . $object_slug] ?? 'include_' . $object_slug;
    }

    /**
     * Whether the sitemap generator will emit this post type at all.
     *
     * Sitemap_Generator::should_include_post_type() delegates here, so the
     * matrix cannot advertise a switch the generator ignores: it bails on a
     * non-viewable or `exclude_from_search` post type BEFORE any inclusion flag
     * is consulted, which made the toggle inert in the ON direction for
     * `attachment` (#669 review).
     *
     * @param string $post_type Post type slug.
     * @return bool
     */
    public static function sitemap_accepts_post_type(string $post_type): bool {
        if (function_exists('is_post_type_viewable') && !is_post_type_viewable($post_type)) {
            return false;
        }

        $object = get_post_type_object($post_type);

        return is_object($object) && empty($object->exclude_from_search);
    }

    /**
     * Whether the sitemap generator will emit this taxonomy at all.
     *
     * `category` and `post_tag` reach it through their legacy
     * include_categories / include_tags flags; every other taxonomy reaches it
     * through a walk over `_builtin => false` taxonomies only, so `post_format`
     * never arrives however its flag is set.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return bool
     */
    public static function sitemap_accepts_taxonomy(string $taxonomy): bool {
        $object = function_exists('get_taxonomy') ? get_taxonomy($taxonomy) : null;

        if (!is_object($object) || empty($object->public)) {
            return false;
        }

        return in_array($taxonomy, ['category', 'post_tag'], true) || empty($object->_builtin);
    }

    /**
     * Whether a post type or taxonomy belongs in the sitemap.
     *
     * The four legacy flags are authoritative for the objects they name. Every
     * other object honours an explicit `exclude_<slug>` first (the flag the
     * presets UI writes), then `include_<slug>`, and otherwise defaults to
     * included — which is what the generator did before it read these flags.
     *
     * @param string $group    'post_type' or 'taxonomy'.
     * @param string $object_slug Post type or taxonomy slug.
     * @param array  $settings    Sitemap settings.
     * @return bool
     */
    public static function is_included_in_sitemap(string $group, string $object_slug, array $settings): bool {
        $legacy = self::SITEMAP_LEGACY_FLAGS[$group . ':' . $object_slug] ?? null;

        if ($legacy !== null) {
            return !empty($settings[$legacy]);
        }

        if (isset($settings['exclude_' . $object_slug])) {
            return empty($settings['exclude_' . $object_slug]);
        }

        if (isset($settings['include_' . $object_slug])) {
            return !empty($settings['include_' . $object_slug]);
        }

        return true;
    }

    /**
     * Sanitize the tri-state feature values in a settings patch.
     *
     * @param array $settings Raw values.
     * @return array Only recognized feature keys, each a valid state.
     */
    public static function sanitize_feature_states(array $settings): array {
        $out = [];

        foreach (self::FEATURES as $feature) {
            if (!isset($settings[$feature])) {
                continue;
            }

            $value = is_string($settings[$feature]) ? $settings[$feature] : '';
            $out[$feature] = in_array($value, self::STATES, true) ? $value : 'inherit';
        }

        return $out;
    }

    /**
     * Persist a patch of entity settings, merged over what is stored.
     *
     * @param string $entity_key Entity key.
     * @param array  $patch      Already-sanitized values.
     * @return bool True when the option holds the requested values afterwards.
     */
    public static function update_entity_settings(string $entity_key, array $patch): bool {
        $all = self::all_settings();

        $existing = is_array($all[$entity_key] ?? null) ? $all[$entity_key] : [];
        $merged   = array_merge($existing, $patch);

        if ($merged === $existing) {
            return true;
        }

        $all[$entity_key] = $merged;

        $updated = update_option(self::OPTION_NAME, $all);
        self::flush_cache();

        return (bool) $updated;
    }
}
