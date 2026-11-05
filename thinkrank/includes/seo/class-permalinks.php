<?php
/**
 * Permalinks: optional removal of the product, product category and category
 * bases from URLs.
 *
 * Rank Math, Yoast, SEOPress, AIOSEO and Slim SEO all let a site publish
 * `/jerseys/` instead of `/product-category/jerseys/`, `/my-shirt/` instead of
 * `/product/my-shirt/`, and `/news/` instead of `/category/news/`. ThinkRank had
 * none of these, so a store that switched got WordPress's and WooCommerce's
 * bases back: every ranked base-free URL answered 404 (#1476).
 *
 * Four switches, all off by default:
 *
 * - `remove_product_base`: `/product/shirt/` becomes `/shirt/`. A base that
 *   carries `%product_cat%` (`/shop/%product_cat%/`) keeps the category path.
 * - `remove_product_category_base`: `/product-category/jerseys/` → `/jerseys/`.
 * - `remove_product_category_parents`: `/product-category/kits/jerseys/` →
 *   `/product-category/jerseys/` (with the base switch too, `/jerseys/`).
 * - `remove_category_base`: `/category/news/` → `/news/`.
 *
 * Links are rewritten through core's `post_type_link` and `term_link`, so the
 * sitemap, canonical, breadcrumbs and schema follow without changes of their
 * own. The base-free URLs get rewrite rules of their own, and the old based
 * URLs keep resolving so that they can be answered with a 301 to the new one.
 *
 * Beyond what the other plugins do, a request that would 404 is checked
 * against the based and base-free forms of the product, product category or
 * category it names; when it is exactly one of them, it is answered with a
 * 301 to that object's current URL. That covers a store that already
 * migrated (its base-free URLs 404 today, before anyone turns a switch on) and
 * a switch turned off later, so no URL is stranded either way.
 *
 * Settings live in an autoloaded option rather than the SEO settings table:
 * rewrite rules and link filters are read on every request, before anything
 * else would have loaded that table.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.21.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL base removal for products, product categories and categories.
 *
 * @since 2.21.0
 */
class Permalinks {

    /**
     * The option holding the four switches.
     */
    public const OPTION = 'thinkrank_permalinks';

    /**
     * Default settings: everything off, so an install that never opens the
     * screen keeps WordPress's and WooCommerce's URLs.
     */
    public const DEFAULTS = [
        'remove_category_base'            => false,
        'remove_product_base'             => false,
        'remove_product_category_base'    => false,
        'remove_product_category_parents' => false,
    ];

    /**
     * A slug no site uses, for asking what becomes of a path at the site root
     * whatever its slug.
     */
    private const ANY_SLUG = 'thinkrank-any-slug';

    /**
     * Query variable the old category base is answered through.
     */
    private const CATEGORY_REDIRECT_VAR = 'thinkrank_category_redirect';

    /**
     * Query vars that mean a rewrite rule already resolved the request to a
     * product, taxonomy, author, date or search.
     */
    private const REQUEST_OWNERS = ['product', 'product_cat', 'product_tag', 'category_name', 'tag', 'taxonomy', 'author_name', 'year', 'monthnum', 's', 'post_type'];

    /**
     * Settings memoized for the request; reset by save().
     *
     * @var array<string, bool>|null
     */
    private static ?array $settings = null;

    /**
     * Whether a rewrite flush is already scheduled for this request.
     *
     * @var bool
     */
    private static bool $flush_scheduled = false;

    /**
     * Where the colliding product slugs are kept between requests.
     *
     * @since 2.21.0
     *
     * @var string
     */
    private const COLLIDING_PRODUCTS_TRANSIENT = 'thinkrank_colliding_product_slugs';

    /**
     * Base-free paths the product categories publish, memoized for the
     * request; null until asked for. Only filled while both bases are removed.
     *
     * @var array<string, bool>|null
     */
    private static ?array $product_category_paths = null;

    /**
     * Slugs of products whose base-free path other content holds, memoized for
     * the request; null until asked for. Read from
     * {@see COLLIDING_PRODUCTS_TRANSIENT}, as finding them scans the catalogue.
     *
     * @var array<string, bool>|null
     */
    private static ?array $colliding_product_slugs = null;

    /**
     * The cached rewrite rules that can decide a one-segment path, memoized for
     * the request; null until asked for.
     *
     * @var array<string, string>|null
     */
    private static ?array $one_segment_rules = null;

    /**
     * The rewrite rules that give a path to something other than content or a
     * term, memoized for the request; null until asked for.
     *
     * @var array{open: string[], literal: array<string, string[]>}|null
     */
    private static ?array $reserved_rules = null;

    /**
     * Base-free term paths a published page or post already holds, memoized
     * for the request; null until asked for.
     *
     * @var array<string, bool>|null
     */
    private static ?array $held_term_paths = null;

    /**
     * Register the hooks. Every callback reads the switches when it runs, so
     * a save in this request is honoured by the flush it schedules.
     *
     * @return void
     */
    public function init(): void {
        add_filter('post_type_link', [$this, 'filter_product_link'], 1, 2);
        add_filter('term_link', [$this, 'filter_term_link'], 0, 3);
        add_filter('rewrite_rules_array', [$this, 'filter_rewrite_rules'], 99);
        add_filter('category_rewrite_rules', [$this, 'filter_category_rewrite_rules']);
        add_filter('query_vars', [$this, 'filter_query_vars']);
        add_filter('request', [$this, 'filter_request'], 11);
        add_action('template_redirect', [$this, 'redirect_to_current_url'], 9);
        add_filter('oembed_request_post_id', [$this, 'filter_oembed_product_id'], 10, 2);

        // A restore, or anything else writing the option directly, moves URLs
        // too: rebuild the rules then as well.
        add_action('add_option_' . self::OPTION, [self::class, 'forget_and_flush']);
        add_action('update_option_' . self::OPTION, [self::class, 'forget_and_flush']);

        foreach (['created', 'edited', 'delete'] as $event) {
            add_action("{$event}_product_cat", [$this, 'flush_when_product_categories_matter']);
            add_action("{$event}_category", [$this, 'flush_when_categories_matter']);
        }

        // A page, post or file taking or freeing a slug changes which products
        // keep their base, for the rest of this request too. Attachments have
        // actions of their own instead of save_post; terms are covered above.
        // Attaching a file in the media library writes post_parent directly.
        add_action('save_post', [self::class, 'forget_colliding_products_for_post'], 10, 2);
        add_action('add_attachment', [self::class, 'forget_colliding_products']);
        add_action('edit_attachment', [self::class, 'forget_colliding_products']);
        add_action('wp_media_attach_action', [self::class, 'forget_colliding_products']);
        add_action('deleted_post', [self::class, 'forget_colliding_products_for_post'], 10, 2);

        // A page or post holding a term's path takes that term's rule away, so
        // one taking or freeing such a path has to rebuild the rules.
        add_action('save_post', [$this, 'flush_when_content_holds_term_path'], 10, 2);
        add_action('post_updated', [$this, 'flush_when_content_holds_term_path'], 10, 3);
        add_action('deleted_post', [$this, 'flush_when_content_holds_term_path'], 10, 2);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * The four switches, as booleans.
     *
     * @return array<string, bool>
     */
    public static function settings(): array {
        if (null === self::$settings) {
            $stored = get_option(self::OPTION, []);
            $stored = is_array($stored) ? $stored : [];

            $settings = [];
            foreach (self::DEFAULTS as $key => $default) {
                $settings[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : $default;
            }
            self::$settings = $settings;
        }

        return self::$settings;
    }

    /**
     * Save the switches that are sent, keep the rest, and rebuild the
     * rewrite rules at the end of the request.
     *
     * Only what was sent is stored, and a key that is missing reads as its
     * default. A stored key is what tells a migration the admin decided that
     * switch, off included, so storing all four on any save made it skip
     * switches nobody had touched (#1533).
     *
     * @param array<string, mixed> $input Switch => value; unknown keys are ignored.
     * @return array<string, bool> The stored settings.
     */
    public static function save(array $input): array {
        $settings = get_option(self::OPTION, []);
        $settings = is_array($settings) ? array_intersect_key($settings, self::DEFAULTS) : [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = function_exists('rest_sanitize_boolean')
                    ? (bool) rest_sanitize_boolean($input[$key])
                    : (bool) $input[$key];
            }
        }

        update_option(self::OPTION, $settings, true);
        self::$settings = null;
        self::$product_category_paths = null;
        self::forget_colliding_products();
        self::$one_segment_rules = null;
        self::$reserved_rules = null;
        self::$held_term_paths = null;
        self::schedule_flush();

        return self::settings();
    }

    /**
     * Most products whose cached pages are purged after a change; a larger
     * catalogue is told to clear its cache.
     */
    public const PURGE_PRODUCT_LIMIT = 2000;

    /**
     * Drop cached copies of every URL a change of the switches moved.
     *
     * A page cache keeps serving a product's or category's old page under the
     * old URL, as a 200, until it expires: the 301 is only sent once the cache
     * stops answering. Both forms of every affected URL are purged through
     * {@see Cache_Purger::purge_urls()}, the integrations ThinkRank already
     * verifies per plugin; what it cannot reach is returned as a sentence to
     * show the user.
     *
     * @return string[] Warnings: caches that could not be cleared, or a catalogue too large to purge.
     */
    public static function purge_changed_urls(): array {
        if (!class_exists('ThinkRank\\SEO\\Cache_Purger')) {
            return [];
        }

        $urls = [];
        $add_forms = static function (string $current, array $forms) use (&$urls): void {
            $urls[] = $current;
            foreach ($forms as $form) {
                $urls[] = home_url('/' . user_trailingslashit($form));
            }
        };

        foreach (['product_cat', 'category'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $base = 'product_cat' === $taxonomy ? self::product_category_base() : self::category_base();
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $link = is_object($term) ? get_term_link($term) : '';
                if (is_string($link) && '' !== $link && null !== $base) {
                    $add_forms($link, self::category_forms_for($link, $base, (string) $term->slug, self::term_path($term)));
                }
            }
        }

        $warnings = [];
        if (post_type_exists('product') && null !== self::product_base()) {
            $ids = get_posts([
                'post_type'   => 'product',
                'post_status' => 'publish',
                'numberposts' => self::PURGE_PRODUCT_LIMIT + 1,
                'orderby'     => 'modified',
                'fields'      => 'ids',
            ]);
            if (count($ids) > self::PURGE_PRODUCT_LIMIT) {
                $warnings[] = sprintf(
                    /* translators: %d: number of products. */
                    __('Your store has more than %d products, so not every product page was cleared from your page cache. Clear the cache so the old product addresses redirect straight away.', 'thinkrank'),
                    self::PURGE_PRODUCT_LIMIT
                );
                $ids = array_slice($ids, 0, self::PURGE_PRODUCT_LIMIT);
            }
            foreach ($ids as $id) {
                $link = get_permalink((int) $id);
                if (is_string($link) && '' !== $link) {
                    $add_forms($link, self::product_forms($link));
                }
            }
        }

        foreach (array_chunk(array_values(array_unique($urls)), 500) as $chunk) {
            Cache_Purger::purge_urls($chunk);
        }

        return array_merge(Cache_Purger::warnings(Cache_Purger::PURGE_URL), $warnings);
    }

    /**
     * Forget the memoized settings (tests, and a save in another class).
     *
     * @return void
     */
    public static function reset(): void {
        self::$settings = null;
        self::$product_category_paths = null;
        self::forget_colliding_products();
        self::$one_segment_rules = null;
        self::$reserved_rules = null;
        self::$held_term_paths = null;
        self::$flush_scheduled = false;
    }

    /**
     * Rebuild the rewrite rules once, at shutdown, after every term and
     * setting change of the request has landed.
     *
     * @return void
     */
    public static function schedule_flush(): void {
        if (self::$flush_scheduled) {
            return;
        }
        self::$flush_scheduled = true;
        add_action('shutdown', static function (): void {
            self::$product_category_paths = null;
            self::$held_term_paths = null;
            flush_rewrite_rules(false);
        });
    }

    /**
     * The option was written: drop the memoized switches and rebuild the
     * rules at the end of the request.
     *
     * @return void
     */
    public static function forget_and_flush(): void {
        self::$settings = null;
        self::$product_category_paths = null;
        self::forget_colliding_products();
        self::$one_segment_rules = null;
        self::$reserved_rules = null;
        self::$held_term_paths = null;
        self::schedule_flush();
    }

    /**
     * A product category was added, renamed or removed: its base-free rule
     * changes with it.
     *
     * @return void
     */
    public function flush_when_product_categories_matter(): void {
        self::$product_category_paths = null;
        self::$held_term_paths = null;
        self::forget_colliding_products();
        $settings = self::settings();
        if ($settings['remove_product_category_base'] || $settings['remove_product_category_parents'] || $settings['remove_product_base']) {
            self::schedule_flush();
        }
    }

    /**
     * A category was added, renamed or removed.
     *
     * @return void
     */
    public function flush_when_categories_matter(): void {
        self::$held_term_paths = null;
        self::forget_colliding_products();
        if (self::settings()['remove_category_base']) {
            self::schedule_flush();
        }
    }

    /**
     * A page or post was saved, renamed, trashed or deleted: when it holds or
     * held the slug at the end of a base-free term path, that term's rule and
     * link change. Any other save costs no flush.
     *
     * @since 2.21.0
     *
     * @param int|mixed   $post_id Post ID.
     * @param object|null $post    The post as it is now.
     * @param mixed       $before  The post before an update; save_post passes a flag here.
     * @return void
     */
    public function flush_when_content_holds_term_path($post_id, $post = null, $before = null): void {
        self::$held_term_paths = null;

        $last_segments = [];
        foreach (array_keys(self::base_free_term_paths()) as $path) {
            $last_segments[self::last_segment((string) $path)] = true;
        }

        foreach ([$post, $before] as $content) {
            if (is_object($content) && in_array($content->post_type ?? '', ['page', 'post'], true)
                && isset($last_segments[(string) ($content->post_name ?? '')])) {
                self::schedule_flush();

                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Links
    // -------------------------------------------------------------------------

    /**
     * A product's link without its base.
     *
     * Runs before WooCommerce fills `%product_cat%` (priority 10), so a base
     * of `/shop/%product_cat%/` loses `/shop/` and keeps the category path.
     *
     * @param string   $link Permalink.
     * @param \WP_Post $post Post.
     * @return string
     */
    public function filter_product_link($link, $post) {
        if (!is_string($link) || !is_object($post) || 'product' !== ($post->post_type ?? '')) {
            return $link;
        }
        if (!self::settings()['remove_product_base'] || !self::pretty_permalinks() || self::is_builder_preview()) {
            return $link;
        }

        if (self::product_keeps_base((string) ($post->post_name ?? ''))) {
            return $link;
        }

        $base = self::product_base();

        return null === $base ? $link : self::strip_first($link, $base, '/');
    }

    /**
     * A product category's or category's link without its base, and without
     * its parents when that is switched on.
     *
     * @param string   $link     Term link.
     * @param \WP_Term $term     Term.
     * @param string   $taxonomy Taxonomy.
     * @return string
     */
    public function filter_term_link($link, $term, $taxonomy) {
        if (!is_string($link) || !is_object($term) || !self::pretty_permalinks()) {
            return $link;
        }

        $settings = self::settings();

        if ('product_cat' === $taxonomy && ($settings['remove_product_category_base'] || $settings['remove_product_category_parents'])) {
            $base = self::product_category_base();
            if (null === $base) {
                return $link;
            }

            // WPML builds its language switcher from term links carrying
            // ?lang=; rebuilding those from the slug would drop the language.
            $language_switcher = false !== strpos($link, 'lang=');
            $own_path = $settings['remove_product_category_parents'] ? (string) $term->slug : self::term_path($term);

            return self::product_category_link(
                $link,
                (string) $term->slug,
                $base,
                $settings['remove_product_category_base'] && !self::product_category_keeps_base($own_path),
                $settings['remove_product_category_parents'] && !$language_switcher
            );
        }

        if ('category' === $taxonomy && $settings['remove_category_base']) {
            if (self::category_keeps_base(self::term_path($term))) {
                return $link;
            }

            // A multisite main site keeps /blog/, as core does for its posts, so
            // the link says what the rule answers (#1524). category_base() carries
            // that /blog/ too, because core's permastruct does.
            return self::strip_first($link, '/' . self::category_base() . '/', '/' . self::blog_prefix());
        }

        return $link;
    }

    /**
     * A product category link with the base and/or the parents removed.
     *
     * @param string $link          The term link WordPress built.
     * @param string $slug          The term's own slug.
     * @param string $base          Product category base, e.g. "product-category".
     * @param bool   $remove_base   Drop the base.
     * @param bool   $remove_parents Keep only the term's own slug.
     * @return string
     */
    public static function product_category_link(string $link, string $slug, string $base, bool $remove_base, bool $remove_parents): string {
        if ($remove_parents) {
            $path = ($remove_base ? '' : $base . '/') . $slug;

            return home_url('/' . user_trailingslashit($path, 'category'));
        }

        return $remove_base ? self::strip_first($link, '/' . $base . '/', '/') : $link;
    }

    // -------------------------------------------------------------------------
    // Rewrite rules
    // -------------------------------------------------------------------------

    /**
     * Rules for the base-free product category URLs, and for products under
     * a base-free `%product_cat%` path. Prepended: WordPress takes the first
     * rule that matches, and WooCommerce's own rules stay in place so that
     * the old URLs still resolve, to be redirected.
     *
     * @param array<string, string>|mixed $rules Rewrite rules.
     * @return array<string, string>|mixed
     */
    public function filter_rewrite_rules($rules) {
        if (!is_array($rules)) {
            return $rules;
        }

        // The rules being built are the only ones there are: core has emptied the
        // option, and it needs these to know what a term must not take (#1535).
        self::$reserved_rules = self::reserved_rules_among($rules);
        self::$held_term_paths = null;
        $rules = self::without_reserved_category_rules($rules);

        if (!function_exists('wc_get_permalink_structure') || !taxonomy_exists('product_cat')) {
            return $rules;
        }

        $settings = self::settings();
        $categories_change = $settings['remove_product_category_base'] || $settings['remove_product_category_parents'];
        $structure = wc_get_permalink_structure();
        $product_paths_change = $settings['remove_product_base']
            && false !== strpos((string) ($structure['product_rewrite_slug'] ?? ''), '%product_cat%');

        if (!$categories_change && !$product_paths_change) {
            return $rules;
        }

        global $wp_rewrite;

        $added = self::product_category_rules(
            self::product_categories(),
            trim((string) ($structure['category_rewrite_slug'] ?? 'product-category'), '/'),
            $settings['remove_product_category_base'],
            $settings['remove_product_category_parents'],
            $product_paths_change,
            [
                'feeds'      => isset($wp_rewrite->feeds) ? (array) $wp_rewrite->feeds : ['feed', 'rdf', 'rss', 'rss2', 'atom'],
                'feed_base'  => (string) ($wp_rewrite->feed_base ?? 'feed'),
                'pagination' => (string) ($wp_rewrite->pagination_base ?? 'page'),
                'comments'   => (string) ($wp_rewrite->comments_pagination_base ?? 'comment-page'),
            ],
            self::held_term_paths()
        );

        return $added + $rules;
    }

    /**
     * The rewrite rules for a set of product categories.
     *
     * @param array<int, array{slug: string, parent: int}> $categories Term ID => slug and parent.
     * @param string $base            Product category base.
     * @param bool   $remove_base     Base removed.
     * @param bool   $remove_parents  Parents removed.
     * @param bool   $product_paths   Also add rules for products under each category path.
     * @param array<string, mixed> $rewrite Feeds, feed base, pagination and comment bases.
     * @param array<string, bool>  $held_paths Base-free paths something else holds; those terms keep their base.
     * @return array<string, string>
     */
    public static function product_category_rules(array $categories, string $base, bool $remove_base, bool $remove_parents, bool $product_paths, array $rewrite, array $held_paths = []): array {
        $feed = '(' . implode('|', array_map('strval', (array) $rewrite['feeds'])) . ')';

        $category_rules = [];
        $product_rules = [];

        foreach ($categories as $category) {
            $slug = (string) $category['slug'];
            $full_path = self::category_path($categories, $category);
            $own_path = $remove_parents ? $slug : $full_path;
            $drop_base = $remove_base && !isset($held_paths[$own_path]);

            if ($drop_base || $remove_parents) {
                $path = urldecode(($drop_base ? '' : $base . '/') . $own_path);
                $query = 'index.php?product_cat=' . $slug;

                $category_rules[$path . '/?$'] = $query;
                $category_rules[$path . '/embed/?$'] = $query . '&embed=true';
                $category_rules[$path . '/' . $rewrite['feed_base'] . '/' . $feed . '/?$'] = $query . '&feed=$matches[1]';
                $category_rules[$path . '/' . $feed . '/?$'] = $query . '&feed=$matches[1]';
                $category_rules[$path . '/' . $rewrite['pagination'] . '/?([0-9]{1,})/?$'] = $query . '&paged=$matches[1]';
            }

            if ($product_paths) {
                $product_path = urldecode($full_path);
                $product_rules[$product_path . '/([^/]+)/?$'] = 'index.php?product=$matches[1]';
                $product_rules[$product_path . '/([^/]+)/' . $rewrite['comments'] . '-([0-9]{1,})/?$'] = 'index.php?product=$matches[1]&cpage=$matches[2]';
            }
        }

        return $category_rules + $product_rules;
    }

    /**
     * A category's path through its parents, e.g. "kits/home/jerseys".
     *
     * @param array<int, array{slug: string, parent: int}> $categories All terms of the taxonomy, by ID.
     * @param array{slug: string, parent: int}             $category   The term.
     * @return string
     */
    public static function category_path(array $categories, array $category): string {
        $path = (string) $category['slug'];
        // Slugs are unique within a taxonomy, so a slug seen twice is a loop
        // in the parent chain (a corrupt term table), not a real path.
        $seen = [$path => true];
        $parent = (int) $category['parent'];

        while ($parent > 0 && isset($categories[$parent]) && !isset($seen[$categories[$parent]['slug']])) {
            $seen[$categories[$parent]['slug']] = true;
            $path = $categories[$parent]['slug'] . '/' . $path;
            $parent = (int) $categories[$parent]['parent'];
        }

        return $path;
    }

    /**
     * Base-free category rules ahead of WordPress's own, which stay so that
     * the old `/category/…` URL still resolves, to be redirected.
     *
     * @param array<string, string>|mixed $rules Category rewrite rules.
     * @return array<string, string>|mixed
     */
    public function filter_category_rewrite_rules($rules) {
        if (!is_array($rules) || !self::settings()['remove_category_base']) {
            return $rules;
        }

        $categories = self::terms_by_id('category');
        $added = [];

        foreach ($categories as $category) {
            $path = self::category_path($categories, $category);
            if (!self::category_keeps_base($path)) {
                $added += self::base_free_category_rules($path);
            }
        }

        return $added + $rules;
    }

    /**
     * The base-free rules of one category.
     *
     * @since 2.21.0
     *
     * @param string $path Category's path through its parents.
     * @return array<string, string>
     */
    private static function base_free_category_rules(string $path): array {
        global $wp_rewrite;

        $pagination = (string) ($wp_rewrite->pagination_base ?? 'page');
        $prefix = self::blog_prefix();
        $rules = [];

        // Percent-encoded slugs reach the rules in either case; WordPress
        // matches the request byte for byte.
        foreach (array_unique([$path, self::uppercase_encoding($path)]) as $form) {
            $form = preg_quote($form, '#');
            $rules[$prefix . '(' . $form . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?category_name=$matches[1]&feed=$matches[2]';
            $rules[$prefix . '(' . $form . ')/' . $pagination . '/?([0-9]{1,})/?$'] = 'index.php?category_name=$matches[1]&paged=$matches[2]';
            $rules[$prefix . '(' . $form . ')/?$'] = 'index.php?category_name=$matches[1]';
        }

        return $rules;
    }

    /**
     * The rules without those of a category that turned out to keep its base.
     *
     * Core builds the category rules before the date rules, so
     * {@see filter_category_rewrite_rules()} cannot see what they reserve, and
     * this runs once every rule is known.
     *
     * @since 2.21.0
     *
     * @param array<string, string> $rules Rewrite rules.
     * @return array<string, string>
     */
    private static function without_reserved_category_rules(array $rules): array {
        if (!self::settings()['remove_category_base']) {
            return $rules;
        }

        $categories = self::terms_by_id('category');
        foreach ($categories as $category) {
            $path = self::category_path($categories, $category);
            if (self::category_keeps_base($path)) {
                $rules = array_diff_key($rules, self::base_free_category_rules($path));
            }
        }

        return $rules;
    }

    /**
     * Uppercase the hex digits of percent-encoded characters.
     *
     * @param string $path Path.
     * @return string
     */
    private static function uppercase_encoding(string $path): string {
        return (string) preg_replace_callback('/%[0-9a-f]{2}/', static fn(array $m): string => strtoupper($m[0]), $path);
    }

    /**
     * The `blog/` prefix of a multisite main site's posts, when it has one.
     *
     * Post categories live under it, base-free or not: `/blog/category/news/`
     * becomes `/blog/news/`. Pages, products and product categories do not,
     * which is why WooCommerce's rules and URLs never see it.
     *
     * @return string
     */
    public static function blog_prefix(): string {
        if (function_exists('is_multisite') && is_multisite() && !is_subdomain_install() && is_main_site()
            && 0 === strpos((string) get_option('permalink_structure'), '/blog/')) {
            return 'blog/';
        }

        return '';
    }

    /**
     * Register the query variable the old category base used to be answered
     * through. Kept so a rule cached by an earlier version resolves.
     *
     * @param array<int, string>|mixed $vars Query vars.
     * @return array<int, string>|mixed
     */
    public function filter_query_vars($vars) {
        if (is_array($vars)) {
            $vars[] = self::CATEGORY_REDIRECT_VAR;
        }

        return $vars;
    }

    // -------------------------------------------------------------------------
    // Request resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a base-free product URL to the product.
     *
     * WordPress reads `/shirt/` as a page or a post named "shirt", finds
     * neither and 404s. When a product owns the slug, the request becomes
     * that product. A page or post that really exists under the path keeps
     * it: an existing page is never replaced by a product with the same slug.
     * Neither is anything a rewrite rule resolved to something else, whatever
     * query var that rule uses (#1505).
     *
     * @param array<string, mixed>|mixed $query_vars Parsed query.
     * @return array<string, mixed>|mixed
     */
    public function filter_request($query_vars) {
        if (!is_array($query_vars) || is_admin() || !self::settings()['remove_product_base'] || !post_type_exists('product')) {
            return $query_vars;
        }

        global $wp;
        $path = isset($wp->request) ? (string) $wp->request : '';
        if ('' === $path) {
            return $query_vars;
        }

        // A page or post below a category path is only read as a product by the
        // rule the category's real products need (#1537, #1548).
        $content_vars = self::content_behind_product_rule($query_vars, $path);
        if (null !== $content_vars) {
            return $content_vars;
        }

        // A product, taxonomy, author, date or search request is already
        // resolved, by WordPress or by the rules above.
        if (self::request_has_owner($query_vars)) {
            return $query_vars;
        }

        // Core reads /shirt/2/ as page 2 of "shirt", so the number is not the slug.
        $page = trim((string) ($query_vars['page'] ?? ''), '/');
        if ('' !== $page) {
            $path = (string) preg_replace('#/' . preg_quote($page, '#') . '$#', '', $path);
        }

        $parsed = self::product_request_slug($path);
        if (null === $parsed || !self::request_reads_as_product($query_vars, $path, $parsed)) {
            return $query_vars;
        }

        if (self::request_names_existing_content($query_vars)) {
            return $query_vars;
        }

        $product = self::find_product_by_slug($parsed['slug']);
        if (null === $product) {
            return $query_vars;
        }

        // Only what misread the path goes: `preview`, `page` and the rest of the
        // request belong to the product as they do at its based URL (#1531).
        return [
            'product'   => $parsed['slug'],
            'name'      => $parsed['slug'],
            'post_type' => 'product',
        ] + $parsed['extra'] + array_diff_key($query_vars, array_flip(['pagename', 'attachment', 'error']));
    }

    /**
     * The query for a page or post when a `%product_cat%` product rule matched
     * its path and no such product exists, or null.
     *
     * `/clothing/([^/]+)/` is what publishes `/clothing/blue-shirt/`, so it also
     * takes `/clothing/size-guide/`, the child of a `clothing` page, and under
     * `/%category%/%postname%/` every post in a post category named like a
     * product category, `uncategorized` first of all (#1548). Leaving the
     * category's path out of the rules would lose its products. A product and a
     * page or post at the same path stay the product's, as that link is the
     * product's.
     *
     * @since 2.21.0
     * @since 2.21.1 Also falls back to a post.
     *
     * @param array<string, mixed> $query_vars Parsed query.
     * @param string               $path       Request path.
     * @return array<string, mixed>|null
     */
    private static function content_behind_product_rule(array $query_vars, string $path): ?array {
        if (empty($query_vars['product']) || !function_exists('get_page_by_path') || !function_exists('wc_get_permalink_structure')
            || false === strpos((string) (wc_get_permalink_structure()['product_rewrite_slug'] ?? ''), '%product_cat%')
            || null !== self::find_product_by_slug((string) $query_vars['product'])) {
            return null;
        }

        $rest = array_diff_key($query_vars, array_flip(['product', 'name', 'post_type']));
        $page_path = (string) preg_replace('#/comment-page-[0-9]+$#', '', $path);
        if (null !== get_page_by_path($page_path)) {
            return ['pagename' => $page_path] + $rest;
        }

        $post = self::post_behind_product_rule($page_path, (string) $query_vars['product']);

        return null === $post ? null : $post + $rest;
    }

    /**
     * The query core gives a category path and slug, when the permalink
     * structure is `/%category%/%postname%/` and a published post has that
     * slug; null otherwise.
     *
     * Core finds a single post by its slug alone and leaves a wrong category in
     * the path to redirect_canonical(), which sends it to the post's own URL.
     * The same happens here, as it does with the product base kept.
     *
     * @since 2.21.1
     *
     * @param string $path Request path without the comment page.
     * @param string $slug The slug the product rule read.
     * @return array{category_name: string, name: string}|null
     */
    private static function post_behind_product_rule(string $path, string $slug): ?array {
        if ('%category%/%postname%' !== trim((string) get_option('permalink_structure'), '/')) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        array_pop($segments);
        $category = implode('/', $segments);
        if ('' === $category) {
            return null;
        }

        $posts = get_posts([
            'name'        => $slug,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        return empty($posts) ? null : ['category_name' => $category, 'name' => $slug];
    }

    /**
     * Whether a rewrite rule already resolved a request to something of its own.
     *
     * @since 2.21.0
     *
     * @param array<string, mixed> $query_vars Parsed query.
     * @return bool
     */
    private static function request_has_owner(array $query_vars): bool {
        foreach (self::REQUEST_OWNERS as $owner) {
            if (!empty($query_vars[$owner])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The product slug a request path ends in, with the endpoint after it.
     *
     * @param string $path Request path without slashes at either end.
     * @return array{slug: string, extra: array<string, string>}|null
     */
    public static function product_request_slug(string $path): ?array {
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        $extra = [];

        $last = (string) end($segments);
        if ('feed' === $last) {
            $extra['feed'] = 'feed';
            array_pop($segments);
        } elseif ('amp' === $last) {
            $extra['amp'] = '';
            array_pop($segments);
        } elseif (0 === strpos($last, 'comment-page-')) {
            $extra['cpage'] = substr($last, strlen('comment-page-'));
            array_pop($segments);
        } elseif ('embed' === $last && count($segments) > 1) {
            $extra['embed'] = 'true';
            array_pop($segments);
        } elseif (in_array($last, ['rdf', 'rss', 'rss2', 'atom'], true) && count($segments) > 1) {
            // Both /slug/atom/ and /slug/feed/atom/ are feed URLs in WordPress.
            $extra['feed'] = $last;
            array_pop($segments);
            if (count($segments) > 1 && 'feed' === end($segments)) {
                array_pop($segments);
            }
        }

        $slug = (string) end($segments);

        return '' === $slug ? null : ['slug' => rawurldecode($slug), 'extra' => $extra];
    }

    /**
     * The product a base-free URL names, for the oEmbed endpoint.
     *
     * Core finds the post through url_to_postid(), which reads `/shirt/` as a
     * post named "shirt", so a product embedded on another site got no response
     * even with its `/shirt/embed/` page working (#1510). A URL that something
     * else holds, or a product that is not public, stays unanswered.
     *
     * Core runs this filter for every URL an author embeds, whichever site it is
     * on, and with plain permalinks no base-free URL exists at all: answering
     * those would publish the store's product in place of the link (#1522).
     *
     * @since 2.21.0
     *
     * @param int|mixed $post_id Post ID core found; 0 for none.
     * @param string    $url     URL being embedded.
     * @return int|mixed
     */
    public function filter_oembed_product_id($post_id, $url) {
        if (!empty($post_id) || !is_string($url) || !self::settings()['remove_product_base'] || !post_type_exists('product')
            || !self::pretty_permalinks() || !self::url_is_below_home($url)) {
            return $post_id;
        }

        $slug = self::path_of($url);
        if (null === $slug || '' === $slug || false !== strpos($slug, '/') || self::product_keeps_base($slug)) {
            return $post_id;
        }

        $product = self::find_product_by_slug($slug);

        return null !== $product && 'publish' === $product->post_status ? (int) $product->ID : $post_id;
    }

    /**
     * Whether a URL is on this site: its host is the home URL's host, in any
     * case, and its path sits below the home URL's own path.
     *
     * A `www.` mismatch counts as another host. It usually is the same site, but
     * it does not have to be, and treating it as foreign only leaves the embed to
     * core's discovery rather than answering it from a guess.
     *
     * @since 2.21.0
     *
     * @param string $url URL.
     * @return bool
     */
    private static function url_is_below_home(string $url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_string($host) || !is_string($home_host) || 0 !== strcasecmp($host, $home_host)) {
            return false;
        }

        $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $path = trim(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH)), '/');

        return '' === $home_path || 0 === strpos($path . '/', $home_path . '/');
    }

    /**
     * Whether a request can be a base-free product at all: WordPress read it
     * as a page or post named by the slug alone, with an endpoint after it.
     *
     * Asks what the request is rather than listing what it is not, because a
     * list of query vars can never hold every brand, attribute or third-party
     * rule. The slug is read from the query var and not from the path's length,
     * so a language prefix in the path (Polylang) does not count as a segment.
     *
     * @since 2.21.0
     *
     * @param array<string, mixed>                              $query_vars Parsed query.
     * @param string                                            $path       Request path.
     * @param array{slug: string, extra: array<string, string>} $parsed     The slug and endpoint in the path.
     * @return bool
     */
    private static function request_reads_as_product(array $query_vars, string $path, array $parsed): bool {
        $slug = $parsed['slug'];
        $is_amp = array_key_exists('amp', $parsed['extra']);

        foreach (['pagename', 'name'] as $var) {
            if (!empty($query_vars[$var])) {
                $named = trim(rawurldecode((string) $query_vars[$var]), '/');
                if ($named === $slug || ($is_amp && $named === $slug . '/amp')) {
                    return true;
                }
            }
        }

        // Without an AMP plugin's endpoint, core reads /slug/amp/ as an
        // attachment named "amp" of the post at /slug/.
        if ($is_amp && 'amp' === ($query_vars['attachment'] ?? '')) {
            return true;
        }

        return self::request_under_product_category_path($query_vars, $path, $slug);
    }

    /**
     * Whether a request WordPress could only guess at is a product under its
     * `%product_cat%` path. The product rules cover the plain URL and comment
     * pages; a feed, embed or AMP URL below one has no rule of its own.
     *
     * @since 2.21.0
     *
     * @param array<string, mixed> $query_vars Parsed query.
     * @param string               $path       Request path.
     * @param string               $slug       The product slug in the path.
     * @return bool
     */
    private static function request_under_product_category_path(array $query_vars, string $path, string $slug): bool {
        if (!function_exists('wc_get_permalink_structure')
            || false === strpos((string) (wc_get_permalink_structure()['product_rewrite_slug'] ?? ''), '%product_cat%')) {
            return false;
        }

        $guessed = !empty($query_vars['pagename']) || !empty($query_vars['name'])
            || !empty($query_vars['attachment']) || !empty($query_vars['error']);
        if (!$guessed) {
            return false;
        }

        $segments = array_map('rawurldecode', explode('/', trim($path, '/')));
        $positions = array_keys($segments, $slug, true);
        if (empty($positions)) {
            return false;
        }
        $parents = implode('/', array_slice($segments, 0, (int) end($positions)));

        $categories = self::product_categories();
        foreach ($categories as $category) {
            if (rawurldecode(self::category_path($categories, $category)) === $parents) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the parsed request already names a page or post that exists.
     *
     * @param array<string, mixed> $query_vars Parsed query.
     * @return bool
     */
    private static function request_names_existing_content(array $query_vars): bool {
        if (!empty($query_vars['pagename']) && function_exists('get_page_by_path')
            && null !== get_page_by_path((string) $query_vars['pagename'])) {
            return true;
        }

        if (!empty($query_vars['name']) && empty($query_vars['post_type'])) {
            $posts = get_posts([
                'name'        => (string) $query_vars['name'],
                'post_type'   => 'post',
                'post_status' => 'publish',
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if (!empty($posts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The published (or private) product with a slug.
     *
     * @param string $slug Slug.
     * @return \WP_Post|null
     */
    private static function find_product_by_slug(string $slug): ?\WP_Post {
        $posts = get_posts([
            'name'        => $slug,
            'post_type'   => 'product',
            'post_status' => ['publish', 'private'],
            'numberposts' => 1,
        ]);

        return (!empty($posts) && $posts[0] instanceof \WP_Post) ? $posts[0] : null;
    }

    // -------------------------------------------------------------------------
    // Redirects
    // -------------------------------------------------------------------------

    /**
     * Answer an old URL with a 301 to the current one.
     *
     * A product, product category or category reached through a URL that is
     * not its current one (the based URL after a base was removed) is sent to
     * its current URL. A request that 404s is sent to the product, product
     * category or category whose based or base-free URL it is exactly.
     *
     * @return void
     */
    public function redirect_to_current_url(): void {
        if (is_admin() || self::is_builder_preview() || (function_exists('is_preview') && is_preview())
            || (function_exists('is_feed') && is_feed()) || (function_exists('is_embed') && is_embed())
            || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || !self::pretty_permalinks()) {
            return;
        }

        $request = self::request_path();
        if (null === $request || self::ends_in_endpoint($request['path'])) {
            return;
        }

        $target = is_404() ? $this->rescue_target($request) : $this->moved_target($request);
        if (null === $target) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- appended to a URL we built, then passed through wp_safe_redirect().
        $query = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : '';
        if ('' !== $query) {
            $target .= (false === strpos($target, '?') ? '?' : '&') . $query;
        }

        if (wp_safe_redirect($target, 301, 'ThinkRank')) {
            exit;
        }
    }

    /**
     * Where a product, product category or category reached through an old
     * URL now lives, or null when the request already is its current URL.
     *
     * @param array{path: string, page: int} $request Request path and page number.
     * @return string|null
     */
    private function moved_target(array $request): ?string {
        $settings = self::settings();
        $current = null;

        if ($settings['remove_product_base'] && function_exists('is_product') && is_product()) {
            $current = get_permalink();
            if (is_string($current)) {
                $current = self::with_content_page($current, (int) get_query_var('page'));
            }
        } elseif (($settings['remove_product_category_base'] || $settings['remove_product_category_parents'])
            && function_exists('is_product_category') && is_product_category() && self::request_asks_for_term_alone(['product_cat'])) {
            $current = get_term_link(get_queried_object());
        } elseif ($settings['remove_category_base'] && is_category() && self::request_asks_for_term_alone(['category_name', 'cat'])) {
            $current = get_term_link(get_queried_object());
        }

        if (!is_string($current) || '' === $current) {
            return null;
        }

        return self::redirect_if_moved($request, $current);
    }

    /**
     * Whether the request is the term's archive and nothing more.
     *
     * is_category() and is_product_category() also hold for a date, author,
     * shop or search request filtered by a term (`/2026/?cat=1`,
     * `/shop/?product_cat=hoodies`). Sending those to the term's URL dropped the
     * archive they asked for (#1549).
     *
     * @since 2.21.1
     *
     * @param array<int, string> $term_vars Query vars that name the term.
     * @return bool
     */
    private static function request_asks_for_term_alone(array $term_vars): bool {
        foreach (['is_search', 'is_date', 'is_author', 'is_post_type_archive'] as $conditional) {
            if (function_exists($conditional) && $conditional()) {
                return false;
            }
        }

        global $wp;

        return self::matched_term_alone(is_object($wp) && isset($wp->matched_query) ? (string) $wp->matched_query : '', $term_vars);
    }

    /**
     * Whether the rewrite rule a request matched names the term and nothing
     * but its page. No rule (the home URL with `?cat=1`) names nothing else.
     *
     * The query string is not read: `?orderby=price` on an old based URL is
     * still that term's archive, and keeps its 301.
     *
     * @since 2.21.1
     *
     * @param string             $matched_query The matched rule's query, e.g. `year=2026`.
     * @param array<int, string> $term_vars     Query vars that name the term.
     * @return bool
     */
    public static function matched_term_alone(string $matched_query, array $term_vars): bool {
        $matched = [];
        parse_str($matched_query, $matched);

        return [] === array_diff(array_keys($matched), $term_vars, ['paged', self::CATEGORY_REDIRECT_VAR]);
    }

    /**
     * The current URL, with the request's page number, when the request path
     * differs from it.
     *
     * @param array{path: string, page: int} $request Request path and page number.
     * @param string $current Object's current URL.
     * @return string|null
     */
    public static function redirect_if_moved(array $request, string $current): ?string {
        $current_path = self::path_of($current);
        if (null === $current_path || $current_path === $request['path']) {
            return null;
        }

        return self::with_page($current, $request['page']);
    }

    /**
     * The current URL of the product, product category or category a 404
     * path is the based or base-free form of.
     *
     * @param array{path: string, page: int} $request Request path and page number.
     * @return string|null
     */
    private function rescue_target(array $request): ?string {
        $segments = explode('/', $request['path']);
        $slug = rawurldecode((string) end($segments));
        if ('' === $slug) {
            return null;
        }

        // A slug can name a term and a product at once. The term goes first: a
        // store's ranked /x/ being a category must not turn into its product.
        $candidates = [];

        $term = function_exists('wc_get_permalink_structure') && taxonomy_exists('product_cat') ? get_term_by('slug', $slug, 'product_cat') : false;
        if (is_object($term)) {
            $link = get_term_link($term);
            if (is_string($link)) {
                $candidates[] = [$link, self::product_category_forms($link, (string) $term->slug, self::term_path($term))];
            }
        }

        $term = get_term_by('slug', $slug, 'category');
        if (is_object($term)) {
            $link = get_term_link($term);
            if (is_string($link)) {
                $candidates[] = [$link, self::category_forms_for($link, self::category_base(), (string) $term->slug, self::term_path($term))];
            }
        }

        if (function_exists('wc_get_permalink_structure') && post_type_exists('product')) {
            $product = self::find_product_by_slug($slug);
            if (null !== $product && 'publish' === $product->post_status) {
                $link = get_permalink($product);
                if (is_string($link) && '' !== $link) {
                    $candidates[] = [$link, self::product_forms($link)];
                }
            }
        }

        foreach ($candidates as [$link, $forms]) {
            $target = self::rescue_match($request, $link, $forms);
            if (null !== $target) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The object's current URL when the 404 path is one of its other forms.
     *
     * @param array{path: string, page: int} $request Request path and page number.
     * @param string   $current Object's current URL.
     * @param string[] $forms   Paths the object is or was reachable at.
     * @return string|null
     */
    public static function rescue_match(array $request, string $current, array $forms): ?string {
        $current_path = self::path_of($current);
        if (null === $current_path || $current_path === $request['path']) {
            return null;
        }

        // A term's slug and path are stored percent-encoded; the request path has
        // been decoded (#1536).
        return in_array($request['path'], array_map('rawurldecode', $forms), true) ? self::with_page($current, $request['page']) : null;
    }

    /**
     * The paths a product has had: with and without its base.
     *
     * @param string $current Product's current URL.
     * @return string[]
     */
    private static function product_forms(string $current): array {
        $path = self::path_of($current);
        $base = self::product_base();
        if (null === $path || null === $base) {
            return [];
        }

        $base = trim($base, '/');

        return self::with_and_without($path, $base);
    }

    /**
     * The paths a product category has had: with and without the base, with
     * and without its parents.
     *
     * @param string $current   Term's current URL.
     * @param string $slug      Term's own slug.
     * @param string $full_path Term's path through its parents, e.g. "kits/jerseys".
     * @return string[]
     */
    private static function product_category_forms(string $current, string $slug, string $full_path): array {
        $base = self::product_category_base();

        return null === $base ? [] : self::category_forms_for($current, $base, $slug, $full_path);
    }

    /**
     * Every path a term archive can have had: its current path, and its slug
     * and its full path, each with and without the base.
     *
     * @param string $current   Term's current URL.
     * @param string $base      Taxonomy base, e.g. "product-category".
     * @param string $slug      Term's own slug.
     * @param string $full_path Term's path through its parents.
     * @return string[]
     */
    public static function category_forms_for(string $current, string $base, string $slug, string $full_path): array {
        $path = self::path_of($current);
        if (null === $path) {
            return [];
        }

        return array_values(array_unique(array_merge(
            self::with_and_without($path, $base),
            self::with_and_without($slug, $base),
            self::with_and_without($full_path, $base)
        )));
    }

    /**
     * A term's path through its parents, e.g. "kits/jerseys".
     *
     * @param \WP_Term $term Term.
     * @return string
     */
    private static function term_path($term): string {
        $slugs = [(string) $term->slug];
        foreach (function_exists('get_ancestors') ? get_ancestors((int) $term->term_id, (string) $term->taxonomy, 'taxonomy') : [] as $ancestor_id) {
            $ancestor = get_term((int) $ancestor_id, (string) $term->taxonomy);
            if (is_object($ancestor) && !is_wp_error($ancestor)) {
                array_unshift($slugs, (string) $ancestor->slug);
            }
        }

        return implode('/', $slugs);
    }

    /**
     * A path with a base in front and without it.
     *
     * @param string $path Path without slashes at either end.
     * @param string $base Base without slashes at either end.
     * @return string[]
     */
    public static function with_and_without(string $path, string $base): array {
        if ('' === $base) {
            return [$path];
        }

        $prefix = $base . '/';
        $without = 0 === strpos($path, $prefix) ? substr($path, strlen($prefix)) : $path;

        return array_values(array_unique([$without, $prefix . $without]));
    }

    /**
     * Whether a path ends in an endpoint (AMP, an embed, a feed, a comment
     * page). Its object is the one before the endpoint, so comparing the
     * whole path with that object's URL would "redirect" a working URL.
     *
     * @param string $path Request path.
     * @return bool
     */
    public static function ends_in_endpoint(string $path): bool {
        $segments = explode('/', $path);

        return 1 === preg_match('/^(amp|embed|feed|rss2?|rdf|atom|comment-page-[0-9]+)$/', (string) end($segments));
    }

    /**
     * The request's path below the home URL, without its page suffix.
     *
     * @return array{path: string, page: int}|null
     */
    private static function request_path(): ?array {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its path is used, compared against paths we built.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ('' === $uri) {
            return null;
        }

        return self::split_request(self::relative_path((string) wp_parse_url($uri, PHP_URL_PATH)));
    }

    /**
     * Split a relative path into its path and the page number of a
     * `/page/N` suffix.
     *
     * @param string $path Path relative to home, without slashes at either end.
     * @return array{path: string, page: int}
     */
    public static function split_request(string $path): array {
        $page = 0;
        if (preg_match('#^(.*?)/?(?:page|' . preg_quote(self::pagination_base(), '#') . ')/([0-9]+)$#', $path, $m)) {
            $path = $m[1];
            $page = (int) $m[2];
        }

        return ['path' => trim($path, '/'), 'page' => $page];
    }

    /**
     * A URL's path below the home URL, without slashes at either end.
     *
     * @param string $url URL.
     * @return string|null
     */
    public static function path_of(string $url): ?string {
        $path = wp_parse_url($url, PHP_URL_PATH);

        return is_string($path) ? self::relative_path($path) : (null === $path ? '' : null);
    }

    /**
     * A path made relative to the home URL's own path.
     *
     * @param string $path Absolute path.
     * @return string
     */
    private static function relative_path(string $path): string {
        $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $path = trim(rawurldecode($path), '/');

        if ('' !== $home_path && (0 === strpos($path . '/', $home_path . '/'))) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        return $path;
    }

    /**
     * A URL with `/page/N/` added for a paginated request.
     *
     * @param string $url  URL.
     * @param int    $page Page number; 0 or 1 for none.
     * @return string
     */
    private static function with_page(string $url, int $page): string {
        if ($page < 2) {
            return $url;
        }

        return trailingslashit($url) . user_trailingslashit(self::pagination_base() . '/' . $page, 'paged');
    }

    /**
     * A product's URL with the page of its own content, as core writes it:
     * `/shirt/2/`, where an archive has `/page/2/`.
     *
     * @since 2.21.0
     *
     * @param string $url  URL.
     * @param int    $page Page number; 0 or 1 for none.
     * @return string
     */
    public static function with_content_page(string $url, int $page): string {
        if ('' === $url || $page < 2) {
            return $url;
        }

        return trailingslashit($url) . user_trailingslashit((string) $page, 'single_paged');
    }

    // -------------------------------------------------------------------------
    // Bases
    // -------------------------------------------------------------------------

    /**
     * The product base as it appears in a product URL, e.g. "/product/", or
     * "/shop/" for "/shop/%product_cat%/". Null when there is none to remove.
     *
     * @return string|null
     */
    public static function product_base(): ?string {
        if (!function_exists('wc_get_permalink_structure')) {
            return null;
        }

        $structure = wc_get_permalink_structure();
        $base = str_replace('%product_cat%', '', (string) ($structure['product_rewrite_slug'] ?? ''));
        $base = trim(preg_replace('#/+#', '/', $base), '/');

        return '' === $base ? null : '/' . $base . '/';
    }

    /**
     * Whether a post category has to keep its base, because a product category
     * already publishes the same base-free path, a page or post holds it, or
     * WordPress reserves it.
     *
     * Two archives cannot share a URL. The product category keeps it, as it
     * does under Rank Math, whose product rules sit ahead of the category
     * rules; without this the post category's link and its redirect pointed at
     * the product archive, and the post archive could not be reached (#1486).
     * A page or post keeps its URL over either of them (#1508). On a multisite
     * main site the category publishes under /blog/, so that whole path is what
     * is compared (#1524).
     *
     * @since 2.21.0
     *
     * @param string $path Category's path through its parents, e.g. "news/local".
     * @return bool
     */
    public static function category_keeps_base(string $path): bool {
        if (null === self::$product_category_paths) {
            self::$product_category_paths = self::removed_base_product_category_paths();
        }

        $path = self::blog_prefix() . $path;

        return isset(self::$product_category_paths[$path]) || isset(self::held_term_paths()[$path]);
    }

    /**
     * Whether a product category has to keep its base, because a page or post
     * holds its base-free path, or WordPress reserves it.
     *
     * The term rules sit ahead of the page rules, so without this the category
     * replaced the page with no warning, and a category named "shop" or "cart"
     * did the same to those pages (#1508).
     *
     * @since 2.21.0
     *
     * @param string $path The base-free path the category would publish.
     * @return bool
     */
    public static function product_category_keeps_base(string $path): bool {
        return isset(self::held_term_paths()[$path]);
    }

    /**
     * The paths of the post categories that keep their base, for the screen
     * and the abilities to name.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    public static function categories_keeping_base(): array {
        $settings = self::settings();
        if (!$settings['remove_category_base'] || !taxonomy_exists('category')) {
            return [];
        }

        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        $paths = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            if (is_object($term) && self::category_keeps_base(self::term_path($term))) {
                $paths[] = self::term_path($term);
            }
        }

        return $paths;
    }

    /**
     * The paths of the product categories that keep their base, for the screen
     * and the abilities to name.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    public static function product_categories_keeping_base(): array {
        $held = self::held_term_paths();
        if ([] === $held || !self::settings()['remove_product_category_base']) {
            return [];
        }

        return array_values(array_map('strval', array_keys(array_intersect_key(self::base_free_product_category_paths(), $held))));
    }

    /**
     * The base-free paths of the terms, as path => true, built as the rewrite
     * rules build them: only for a taxonomy whose base is removed.
     *
     * @return array<string, bool>
     */
    private static function base_free_term_paths(): array {
        $paths = self::base_free_product_category_paths();

        // A multisite main site publishes post categories under /blog/.
        if (self::settings()['remove_category_base'] && taxonomy_exists('category')) {
            $prefix = self::blog_prefix();
            $categories = self::terms_by_id('category');
            foreach ($categories as $category) {
                $paths[$prefix . self::category_path($categories, $category)] = true;
            }
        }

        return $paths;
    }

    /**
     * The base-free paths of the product categories, as path => true; empty
     * while their base stays.
     *
     * @return array<string, bool>
     */
    private static function base_free_product_category_paths(): array {
        $settings = self::settings();
        if (!$settings['remove_product_category_base'] || !taxonomy_exists('product_cat')) {
            return [];
        }

        $categories = self::product_categories();
        $paths = [];
        foreach ($categories as $category) {
            $paths[$settings['remove_product_category_parents'] ? (string) $category['slug'] : self::category_path($categories, $category)] = true;
        }

        return $paths;
    }

    /**
     * The base-free term paths a page or post already holds, or a rule of
     * WordPress or another plugin reserves.
     *
     * @return array<string, bool>
     */
    private static function held_term_paths(): array {
        if (null === self::$held_term_paths) {
            self::$held_term_paths = self::find_held_term_paths();
        }

        return self::$held_term_paths;
    }

    /**
     * Find which base-free term paths are held, in one query.
     *
     * Only a published page counts. A draft has no public address to protect,
     * and a category's public URL should not move for it. A post only counts
     * when posts are published at the root, or directly under a multisite main
     * site's /blog/, where its post categories are too. A page below a parent is
     * compared by its whole path, so a term at `kits/jerseys` meets the child
     * page at the same path and not a top-level page named `jerseys`.
     *
     * A reserved path is held too, so every surface that knows about a held path
     * covers it: the rules, the link and the list on the screen (#1535).
     *
     * @return array<string, bool>
     */
    private static function find_held_term_paths(): array {
        $term_paths = self::base_free_term_paths();
        if ([] === $term_paths) {
            return [];
        }

        $slugs = [];
        foreach (array_keys($term_paths) as $path) {
            $slugs[self::last_segment((string) $path)] = true;
        }
        $slugs = array_map('strval', array_keys($slugs));

        global $wpdb;

        $prefix = self::blog_prefix();
        $posts_at_root = self::posts_publish_at($prefix)
            ? " OR (post_type = 'post' AND post_status = 'publish')"
            : '';
        $placeholders = implode(', ', array_fill(0, count($slugs), '%s'));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name, a fixed clause and one placeholder per slug. Memoized for the request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_name, post_parent, post_type FROM {$wpdb->posts}
                WHERE post_name IN ({$placeholders})
                    AND ((post_type = 'page' AND post_status = 'publish'){$posts_at_root})",
                $slugs
            )
        );
        // phpcs:enable

        $held = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_object($row)) {
                continue;
            }
            $path = (int) $row->post_parent > 0 ? get_page_uri((int) $row->ID) : $row->post_name;
            if ('' !== $prefix && 'post' === ($row->post_type ?? '')) {
                $path = $prefix . $row->post_name;
            }
            if (is_string($path) && isset($term_paths[$path])) {
                $held[$path] = true;
            }
        }

        foreach (array_keys($term_paths) as $path) {
            if (self::path_is_reserved((string) $path)) {
                $held[$path] = true;
            }
        }

        return $held;
    }

    /**
     * Whether a rule gives a term's path to something a term must not replace:
     * `/2026/` is a year and `/wp-json/` the REST API, and term rules sit ahead
     * of both (#1535).
     *
     * @since 2.21.0
     *
     * @param string $path Term path.
     * @return bool
     */
    private static function path_is_reserved(string $path): bool {
        if (null === self::$reserved_rules) {
            $rules = get_option('rewrite_rules');
            self::$reserved_rules = self::reserved_rules_among(is_array($rules) ? $rules : []);
        }

        $decoded = urldecode($path);
        $first = explode('/', $decoded)[0];
        $regexes = array_merge(self::$reserved_rules['open'], self::$reserved_rules['literal'][$first] ?? []);

        foreach ($regexes as $regex) {
            if (preg_match("#^$regex#", $path) || preg_match("#^$regex#", $decoded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rules that reserve a path: those naming something specific, unlike a
     * rule any slug passes (what answers `/slug/` under `/%author%/%postname%/`
     * says nothing about one path), and answering with neither content nor a
     * term. Content is {@see find_held_term_paths()}'s to compare, which also
     * keeps `/kits/2026/` from reading as page 2026 of a post named "kits". Terms
     * and products are left out because their rules are ThinkRank's own too.
     *
     * Rules opening with a whole literal segment are kept by that segment, as
     * they are most of them and each can only match paths starting with it. This
     * is read for every term on a request that builds a term link.
     *
     * @since 2.21.0
     *
     * @param array<string, string> $rules Rewrite rules.
     * @return array{open: string[], literal: array<string, string[]>}
     */
    private static function reserved_rules_among(array $rules): array {
        $any_slug = [self::ANY_SLUG, self::ANY_SLUG . '/' . self::ANY_SLUG, self::ANY_SLUG . '/' . self::ANY_SLUG . '/' . self::ANY_SLUG];
        $reserved = ['open' => [], 'literal' => []];

        foreach ($rules as $regex => $query) {
            $regex = (string) preg_replace('#^index\.php/#', '', (string) $regex);
            if (1 === preg_match('/[?&](?:name|pagename|attachment|category_name|product_cat|product)=/', (string) $query)) {
                continue;
            }

            foreach ($any_slug as $path) {
                if (preg_match("#^$regex#", $path)) {
                    continue 2;
                }
            }

            if (1 === preg_match('#^\^?([\w-]+)/(?!\?)#', $regex, $literal)) {
                $reserved['literal'][$literal[1]][] = $regex;
            } else {
                $reserved['open'][] = $regex;
            }
        }

        return $reserved;
    }

    /**
     * Whether a post's whole URL is its slug below a prefix, so it holds that
     * path against a term or a product. A trailing slash is only how core writes
     * the URL (#1532), and `index.php` comes before every URL of a PATHINFO
     * structure, products and terms included.
     *
     * @since 2.21.0
     *
     * @param string $prefix `blog/` on a multisite main site, otherwise empty.
     * @return bool
     */
    private static function posts_publish_at(string $prefix): bool {
        $structure = (string) preg_replace('#^/?index\.php#', '', (string) get_option('permalink_structure', ''));

        return '/' . $prefix . '%postname%' === rtrim($structure, '/');
    }

    /**
     * The last segment of a path.
     *
     * @param string $path Path.
     * @return string
     */
    private static function last_segment(string $path): string {
        $segments = explode('/', $path);

        return (string) end($segments);
    }

    /**
     * The root paths the product categories publish, while both bases are
     * removed. With either base in place the two taxonomies cannot collide.
     *
     * @return array<string, bool>
     */
    private static function removed_base_product_category_paths(): array {
        $settings = self::settings();
        if (!$settings['remove_category_base'] || !$settings['remove_product_category_base']
            || !function_exists('wc_get_permalink_structure') || !taxonomy_exists('product_cat')) {
            return [];
        }

        $categories = self::product_categories();
        $paths = [];
        foreach ($categories as $category) {
            $path = $settings['remove_product_category_parents']
                ? (string) $category['slug']
                : self::category_path($categories, $category);
            $paths[$path] = true;
        }

        return $paths;
    }

    /**
     * Whether a product has to keep its base, because a page, post, unattached
     * file or base-free category already holds its slug at the site root, or a
     * base-free URL for that slug would not be read as a product at all.
     *
     * filter_request() gives that path to the page, post or file, and the term
     * rules sit ahead of it, so a base-free link and the 301 to it led to
     * unrelated content and the product had no working URL (#1487, #1504). Slugs
     * are unique per post type and taxonomy only, so such a pair is legitimate.
     * A base containing %product_cat% publishes under the category path, where
     * nothing collides.
     *
     * @since 2.21.0
     *
     * @param string $slug Product slug.
     * @return bool
     */
    public static function product_keeps_base(string $slug): bool {
        if (null === self::$colliding_product_slugs) {
            self::$colliding_product_slugs = array_fill_keys(self::stored_colliding_product_slugs(), true);
        }

        return isset(self::$colliding_product_slugs[$slug]) || self::slug_reads_as_something_else($slug);
    }

    /**
     * The slugs of the products that keep their base, for the screen and the
     * abilities to name.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    public static function products_keeping_base(): array {
        self::product_keeps_base('');

        return array_values(array_unique(array_merge(
            array_map('strval', array_keys((array) self::$colliding_product_slugs)),
            self::product_slugs_read_as_something_else()
        )));
    }

    /**
     * Whether a product's base-free URL would not reach it, because WordPress or
     * ThinkRank reads a path made of that slug alone as something else (#1523).
     *
     * Core keeps `post` slugs off its date archives but not product slugs, so
     * `/3090/` is the year 3090, and a third-party archive at the root does the
     * same. Asking the site's own rules, in the order WP::parse_request() does,
     * gets this right per permalink structure: under `/%post_id%/` every number
     * is a post ID, and `/search/` or `/tag/` is only claimed with something
     * after it. The verdict is filter_request()'s own predicate, so the link and
     * the request cannot disagree. Nothing here lists a reserved word.
     *
     * A path no rule answers never reaches filter_request() as a page or post
     * either, so it counts as read as something else: the 404 (#1529).
     *
     * With no rules cached (just flushed) only the endpoint check answers, for
     * the one request until core rebuilds them.
     *
     * @since 2.21.0
     *
     * @param string $slug Product slug.
     * @return bool
     */
    private static function slug_reads_as_something_else(string $slug): bool {
        if ('' === $slug || !self::products_publish_at_root() || !self::pretty_permalinks()) {
            return false;
        }

        // A lone /amp/ or /comment-page-2/ is taken for the endpoint with no slug.
        $parsed = self::product_request_slug($slug);
        if (null === $parsed || rawurldecode($slug) !== $parsed['slug']) {
            return true;
        }

        $rules = self::one_segment_rules();
        foreach ($rules as $regex => $query) {
            if (preg_match("#^$regex#", $slug, $matches) || preg_match("#^$regex#", urldecode($slug), $matches)) {
                return !self::rule_reads_as_product($query, $matches, $slug, $parsed);
            }
        }

        return [] !== $rules;
    }

    /**
     * Whether the permalink structure leaves no product a base-free URL, because
     * a path at the site root reaches no product whatever its slug: nothing
     * answers it under `/blog/%postname%/` or `/%postname%.html`, and it is a
     * category or an author under `/%category%/%postname%/` or
     * `/%author%/%postname%/` (#1529). Every product keeps its base then, so the
     * screen says that once rather than naming the whole catalogue.
     *
     * @since 2.21.0
     *
     * @return bool
     */
    public static function products_keep_base_under_structure(): bool {
        return self::slug_reads_as_something_else(self::ANY_SLUG);
    }

    /**
     * Whether core drops a page rule when no page answers it, and moves on to
     * the next rule. The test WP_Rewrite::init() makes, on the same option.
     *
     * @since 2.21.0
     *
     * @return bool
     */
    private static function verbose_page_rules(): bool {
        return 1 === preg_match('/^[^%]*%(?:postname|category|tag|author)%/', (string) get_option('permalink_structure', ''));
    }

    /**
     * Whether what a rewrite rule resolves to is a page or post named by the
     * slug, which filter_request() then turns into the product.
     *
     * @since 2.21.0
     *
     * @param string                                            $query   The rule's query.
     * @param string[]                                          $matches What its regex captured.
     * @param string                                            $path    The path it matched.
     * @param array{slug: string, extra: array<string, string>} $parsed  The slug and endpoint in the path.
     * @return bool
     */
    private static function rule_reads_as_product(string $query, array $matches, string $path, array $parsed): bool {
        $filled = preg_replace_callback(
            '/\$matches\[([0-9]+)\]/',
            static fn(array $m): string => rawurlencode((string) ($matches[(int) $m[1]] ?? '')),
            (string) preg_replace('/^[^?]*\?/', '', $query)
        );
        parse_str((string) $filled, $query_vars);

        return !self::request_has_owner($query_vars) && self::request_reads_as_product($query_vars, $path, $parsed);
    }

    /**
     * The cached rewrite rules that can decide a path of one segment, in order.
     *
     * Only for speed, as this runs for every product link: rules that need a
     * second segment cannot match, nothing below the first rule that takes any
     * slug is ever reached, and the rules naming one term are left to
     * {@see root_term_slugs()}, which knows them without a stale cache. A rule
     * taking any path as a category is not one of those: under
     * `/%category%/%postname%/` it is what answers `/slug/`.
     *
     * A page rule is left out where core drops it for a path with no page
     * (#1529). A page at the path is a collision, so that product keeps its base
     * through {@see find_colliding_product_slugs()}. `index.php/` comes off a
     * PATHINFO structure's rules, as the slug is compared without it. Read from
     * the option rather than WP_Rewrite::wp_rewrite_rules(), which would rebuild
     * them from inside a link filter when they are missing.
     *
     * @since 2.21.0
     *
     * @return array<string, string> Regex => query.
     */
    private static function one_segment_rules(): array {
        if (null !== self::$one_segment_rules) {
            return self::$one_segment_rules;
        }

        $rules = get_option('rewrite_rules');
        $kept = [];
        $verbose = self::verbose_page_rules();
        foreach (is_array($rules) ? $rules : [] as $regex => $query) {
            $regex = (string) preg_replace('#^index\.php/#', '', (string) $regex);
            $query = (string) $query;
            if (1 === preg_match('/[?&](?:category_name|product_cat)=(?!\$matches)/', $query)
                || self::rule_needs_two_segments($regex)
                || ($verbose && 1 === preg_match('/pagename=\$matches\[[0-9]+\]/', $query))) {
                continue;
            }

            $kept[$regex] = $query;
            if (preg_match("#^$regex#", self::ANY_SLUG, $matches)
                && self::rule_reads_as_product($query, $matches, self::ANY_SLUG, ['slug' => self::ANY_SLUG, 'extra' => []])) {
                break;
            }
        }
        self::$one_segment_rules = $kept;

        return $kept;
    }

    /**
     * Whether a rewrite regex has a slash no path of one segment can supply: one
     * outside any group or character class, and not itself optional.
     *
     * @since 2.21.0
     *
     * @param string $regex Rewrite regex.
     * @return bool
     */
    private static function rule_needs_two_segments(string $regex): bool {
        $shape = str_replace('\\/', '/', $regex);
        $shape = (string) preg_replace('/\[[^\]]*\]/', '', $shape);
        $shape = (string) preg_replace('#/(?:\?|\*|\{0,[0-9]*\})#', '', $shape);
        do {
            $shape = (string) preg_replace('/\([^()]*\)/', '', $shape, -1, $replaced);
        } while ($replaced > 0);

        return false === strpos($shape, '|') && false !== strpos($shape, '/');
    }

    /**
     * The slugs of the products {@see slug_reads_as_something_else()} is true
     * for. Reads every product's slug, so it is only for the admin surfaces that
     * name them, never for a front-end request.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    private static function product_slugs_read_as_something_else(): array {
        if (!self::products_publish_at_root() || !self::pretty_permalinks() || self::products_keep_base_under_structure()) {
            return [];
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only; nothing to bind. One read for the settings screen.
        $slugs = $wpdb->get_col("SELECT DISTINCT post_name FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish', 'private') AND post_name <> ''");

        return array_values(array_filter(array_map('strval', is_array($slugs) ? $slugs : []), [self::class, 'slug_reads_as_something_else']));
    }

    /**
     * Whether products are published at the site root once their base is gone.
     * A base containing %product_cat% publishes them under the category path.
     *
     * @since 2.21.0
     *
     * @return bool
     */
    private static function products_publish_at_root(): bool {
        return self::settings()['remove_product_base']
            && function_exists('wc_get_permalink_structure')
            && false === strpos((string) (wc_get_permalink_structure()['product_rewrite_slug'] ?? ''), '%product_cat%');
    }

    /**
     * Forget which products keep their base, in this request and for the next.
     *
     * @return void
     */
    public static function forget_colliding_products(): void {
        self::$colliding_product_slugs = null;
        delete_transient(self::COLLIDING_PRODUCTS_TRANSIENT);
    }

    /**
     * A post was saved or deleted: forget the slugs only when its type can hold
     * or take one, so a busy store's orders do not rescan the catalogue.
     *
     * @since 2.21.0
     *
     * @param int|mixed   $post_id Post ID.
     * @param object|null $post    The post. Passed by save_post and deleted_post; by then a deleted post can no longer be looked up.
     * @return void
     */
    public static function forget_colliding_products_for_post($post_id, $post = null): void {
        $type = is_object($post) && isset($post->post_type) ? $post->post_type : get_post_type((int) $post_id);

        if (in_array($type, ['product', 'page', 'post', 'attachment'], true)) {
            self::forget_colliding_products();
        }
    }

    /**
     * The colliding slugs, found once and kept between requests: finding them
     * joins every product, which no product link should pay for per visitor.
     *
     * Saves of the content and terms involved drop the set. What a save does not
     * reach is covered here: the fingerprint catches a change of what the query
     * depends on, however it was written, and the hour bounds a direct database
     * write or a save overlapping a read.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    private static function stored_colliding_product_slugs(): array {
        if (!self::products_publish_at_root() || self::products_keep_base_under_structure()) {
            return [];
        }

        $fingerprint = self::colliding_products_fingerprint();
        $stored = get_transient(self::COLLIDING_PRODUCTS_TRANSIENT);

        if (is_array($stored) && ($stored['fingerprint'] ?? null) === $fingerprint && is_array($stored['slugs'] ?? null)) {
            return array_map('strval', $stored['slugs']);
        }

        global $wpdb;

        $slugs = self::find_colliding_product_slugs();

        // A failed query reads as "nothing collides". Never keep that.
        if ('' === (string) $wpdb->last_error) {
            set_transient(self::COLLIDING_PRODUCTS_TRANSIENT, ['fingerprint' => $fingerprint, 'slugs' => $slugs], HOUR_IN_SECONDS);
        }

        return $slugs;
    }

    /**
     * What the colliding slugs depend on besides content. The locale is here
     * because a multilingual plugin narrows get_terms() to the current language.
     *
     * @since 2.21.0
     *
     * @return string
     */
    private static function colliding_products_fingerprint(): string {
        return md5((string) wp_json_encode([
            self::settings(),
            (string) get_option('permalink_structure', ''),
            (string) (wc_get_permalink_structure()['product_rewrite_slug'] ?? ''),
            taxonomy_exists('product_cat'),
            get_locale(),
        ]));
    }

    /**
     * Find the colliding slugs.
     *
     * A page of any live status counts, because get_page_by_path() in
     * request_names_existing_content() finds drafts too; so does an attachment
     * without a parent, which it finds alongside pages. A post only counts when
     * posts are published at the root, and only once it is published, as that
     * check also requires. A term counts through {@see root_term_slugs()}.
     *
     * @return string[]
     */
    private static function find_colliding_product_slugs(): array {
        if (!self::products_publish_at_root()) {
            return [];
        }

        global $wpdb;

        $posts_at_root = self::posts_publish_at('')
            ? " OR (other.post_type = 'post' AND other.post_status = 'publish')"
            : '';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names, a fixed clause and one placeholder per slug. Kept by stored_colliding_product_slugs().
        $slugs = $wpdb->get_col(
            "SELECT DISTINCT product.post_name
            FROM {$wpdb->posts} product
            INNER JOIN {$wpdb->posts} other ON other.post_name = product.post_name
            WHERE product.post_type = 'product'
                AND product.post_status IN ('publish', 'private')
                AND product.post_name <> ''
                AND (
                    (other.post_type IN ('page', 'attachment') AND other.post_parent = 0 AND other.post_status NOT IN ('trash', 'auto-draft'))
                    {$posts_at_root}
                )"
        );
        $slugs = is_array($slugs) ? $slugs : [];

        $term_slugs = self::root_term_slugs();
        if ([] !== $term_slugs) {
            // Two steps rather than a join: posts and terms can differ in
            // collation on an older site, and comparing them fails the query.
            $placeholders = implode(', ', array_fill(0, count($term_slugs), '%s'));
            $held = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_name FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish', 'private') AND post_name IN ({$placeholders})",
                    $term_slugs
                )
            );
            $slugs = array_merge($slugs, is_array($held) ? $held : []);
        }
        // phpcs:enable

        return array_values(array_unique(array_map('strval', $slugs)));
    }

    /**
     * The slugs of the terms whose rules answer a path at the site root: only
     * while their base is removed, and only a term with no parents in its path.
     * The paths are built as the rules build them, so the two cannot disagree.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    private static function root_term_slugs(): array {
        $settings = self::settings();
        $paths = [];

        if ($settings['remove_product_category_base'] && taxonomy_exists('product_cat')) {
            $categories = self::product_categories();
            foreach ($categories as $category) {
                $paths[] = $settings['remove_product_category_parents']
                    ? (string) $category['slug']
                    : self::category_path($categories, $category);
            }
        }

        // A multisite main site publishes post categories under /blog/.
        if ($settings['remove_category_base'] && taxonomy_exists('category') && '' === self::blog_prefix()) {
            $categories = self::terms_by_id('category');
            foreach ($categories as $category) {
                $paths[] = self::category_path($categories, $category);
            }
        }

        return array_values(array_unique(array_filter($paths, static fn(string $path): bool => '' !== $path && false === strpos($path, '/'))));
    }

    /**
     * The product category base, e.g. "product-category".
     *
     * @return string|null
     */
    public static function product_category_base(): ?string {
        if (!function_exists('wc_get_permalink_structure')) {
            return null;
        }

        $base = trim((string) (wc_get_permalink_structure()['category_rewrite_slug'] ?? ''), '/');

        return '' === $base ? null : $base;
    }

    /**
     * The category base, e.g. "category" or a site's own "topics".
     *
     * @return string
     */
    public static function category_base(): string {
        $base = trim((string) get_option('category_base', ''), '/');
        if ('' !== $base) {
            return $base;
        }

        global $wp_rewrite;
        if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'get_category_permastruct')) {
            $base = trim(str_replace('%category%', '', (string) $wp_rewrite->get_category_permastruct()), '/');
        }

        return '' === $base ? 'category' : $base;
    }

    /**
     * Every product category, by ID, with its slug and parent.
     *
     * @return array<int, array{slug: string, parent: int}>
     */
    private static function product_categories(): array {
        return self::terms_by_id('product_cat');
    }

    /**
     * Every term of a taxonomy, by ID, with its slug and parent.
     *
     * @param string $taxonomy Taxonomy.
     * @return array<int, array{slug: string, parent: int}>
     */
    private static function terms_by_id(string $taxonomy): array {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        $categories = [];

        foreach (is_array($terms) ? $terms : [] as $term) {
            if (is_object($term)) {
                $categories[(int) $term->term_id] = ['slug' => (string) $term->slug, 'parent' => (int) $term->parent];
            }
        }

        return $categories;
    }

    /**
     * The pagination base, usually "page".
     *
     * @return string
     */
    private static function pagination_base(): string {
        global $wp_rewrite;

        return is_object($wp_rewrite) && !empty($wp_rewrite->pagination_base) ? (string) $wp_rewrite->pagination_base : 'page';
    }

    /**
     * Whether the site uses pretty permalinks; with plain ones there is no
     * base to remove.
     *
     * @return bool
     */
    private static function pretty_permalinks(): bool {
        return '' !== (string) get_option('permalink_structure', '');
    }

    /**
     * Elementor's preview loads a product through its permalink; rewriting it
     * there sends the preview to a URL Elementor does not expect.
     *
     * @return bool
     */
    private static function is_builder_preview(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
        return isset($_GET['elementor-preview']);
    }

    /**
     * Replace the first occurrence of a substring.
     *
     * @param string $subject     Subject.
     * @param string $search      Substring.
     * @param string $replacement Replacement.
     * @return string
     */
    private static function strip_first(string $subject, string $search, string $replacement): string {
        $position = strpos($subject, $search);

        return false === $position ? $subject : substr_replace($subject, $replacement, $position, strlen($search));
    }
}
