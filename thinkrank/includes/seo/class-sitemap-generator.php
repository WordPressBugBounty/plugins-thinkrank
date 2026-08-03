<?php
/**
 * Sitemap Generator Class
 *
 * XML sitemap generation and management for search engine optimization.
 * Implements 2025 SEO best practices with real sitemap specifications,
 * dynamic content inclusion, and performance optimization.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use InvalidArgumentException;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap Generator Class
 *
 * Generates and manages XML sitemaps for search engine optimization.
 * Provides dynamic sitemap generation with content filtering, priority
 * calculation, and change frequency optimization.
 *
 * @since 1.0.0
 */
class Sitemap_Generator extends Abstract_SEO_Manager {

    /**
     * Supported sitemap types
     *
     * @since 1.0.0
     * @var array
     */
    private array $sitemap_types = [
        'posts' => [
            'name' => 'Posts',
            'post_types' => ['post'],
            'priority' => 0.8,
            'changefreq' => 'weekly'
        ],
        'pages' => [
            'name' => 'Pages',
            'post_types' => ['page'],
            'priority' => 0.9,
            'changefreq' => 'monthly'
        ],
        'categories' => [
            'name' => 'Categories',
            'taxonomy' => 'category',
            'priority' => 0.6,
            'changefreq' => 'weekly'
        ],
        'tags' => [
            'name' => 'Tags',
            'taxonomy' => 'post_tag',
            'priority' => 0.4,
            'changefreq' => 'monthly'
        ]
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('sitemap');

        // Initialize auto-generation hooks
        $this->init_auto_generation_hooks();
    }

    /**
     * Filter the args of a sitemap post query.
     *
     * Exists so integrations can widen what the sitemap sees — the multilingual
     * manager uses it to include every language, since these queries otherwise
     * run in whichever language happened to be active at generation time.
     *
     * @since 1.23.0
     *
     * @param array $args get_posts() arguments.
     * @return array Filtered arguments.
     */
    private function filter_query_args(array $args): array {
        /**
         * Filter the arguments of a sitemap post query.
         *
         * @since 1.23.0
         *
         * @param array $args get_posts() arguments.
         */
        return (array) apply_filters('thinkrank_sitemap_query_args', $args);
    }

    /**
     * Filter the args of a sitemap term query.
     *
     * @since 1.23.0
     *
     * @param array $args get_terms() arguments.
     * @return array Filtered arguments.
     */
    private function filter_term_query_args(array $args): array {
        /**
         * Filter the arguments of a sitemap term query.
         *
         * @since 1.23.0
         *
         * @param array $args get_terms() arguments.
         */
        return (array) apply_filters('thinkrank_sitemap_term_query_args', $args);
    }

    /**
     * Initialize WordPress hooks for auto-generation
     *
     * @since 1.0.0
     * @return void
     */
    private function init_auto_generation_hooks(): void {
        // Content change hooks - use priority 20 to run after other plugins
        add_action('save_post', [$this, 'handle_content_change'], 20, 2);
        add_action('delete_post', [$this, 'handle_content_deletion'], 20);
        add_action('wp_trash_post', [$this, 'handle_content_deletion'], 20);
        add_action('untrash_post', [$this, 'handle_content_change_by_id'], 20);

        // Taxonomy change hooks
        add_action('created_term', [$this, 'handle_taxonomy_change'], 20, 3);
        add_action('edited_term', [$this, 'handle_taxonomy_change'], 20, 3);
        add_action('delete_term', [$this, 'handle_taxonomy_change'], 20, 3);

        // NOTE: the WP-Cron regeneration listeners (thinkrank_regenerate_sitemap
        // and thinkrank_regenerate_sitemap_settings) are registered at plugin
        // bootstrap (Plugin::register_sitemap_cron_listeners(), on plugins_loaded)
        // rather than here. A cron run never builds this class via the REST
        // endpoint (no rest_api_init), so registering them in the constructor
        // would leave the scheduled events with no listener at cron time.
    }

    /**
     * Generate XML sitemap
     *
     * @since 1.0.0
     *
     * @param array $options Sitemap generation options
     * @return string XML sitemap content
     */
    public function generate_sitemap(array $options = []): string {
        $settings = $this->get_settings('site');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        // Add XSL stylesheet only if styling is enabled
        if (!empty($settings['enable_styling'])) {
            $xml .= '<?xml-stylesheet type="text/xsl" href="' . home_url('/wp-content/plugins/thinkrank/static/xsl/sitemap.xsl') . '"?>' . "\n";
        }

        // Add image namespace if images are enabled
        if (!empty($settings['include_images'])) {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        } else {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        }

        // Add homepage. Use the static front page's real modified time when set,
        // so lastmod reflects real changes rather than the generation time.
        $xml .= $this->generate_url_entry(home_url('/'), $this->get_homepage_lastmod(), 1.0, 'daily');

        // MEMORY-EFFICIENT: Generate posts using chunked processing.
        // Note: the single "general" sitemap includes every URL (no per-file cap);
        // per-file chunking applies to the segmented/index model where each type
        // gets its own paginated file (see generate_multiple_sitemaps).
        $enabled_post_types = $this->get_enabled_post_types($settings);
        if (!empty($enabled_post_types)) {
            $xml .= implode('', $this->collect_post_entries($enabled_post_types, $settings));
        }

        // OPTIMIZED: Get all enabled taxonomies and fetch in single query
        $enabled_taxonomies = $this->get_enabled_taxonomies($settings);
        if (!empty($enabled_taxonomies)) {
            $all_taxonomy_data = $this->fetch_taxonomies_optimized($enabled_taxonomies, $settings);

            // Process each taxonomy's data
            foreach ($enabled_taxonomies as $taxonomy) {
                if (!empty($all_taxonomy_data[$taxonomy])) {
                    $xml .= $this->process_taxonomies_to_xml($all_taxonomy_data[$taxonomy], $taxonomy, $settings);
                }
            }
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => []
        ];

        try {
            // Validate exclude_posts
            if (!empty($settings['exclude_posts'])) {
                $this->validate_exclude_posts($settings['exclude_posts']);
            }

            // Validate exclude_terms
            if (!empty($settings['exclude_terms'])) {
                $this->validate_exclude_terms($settings['exclude_terms']);
            }

            // Validate custom_url_pattern
            if (!empty($settings['custom_url_pattern'])) {
                $this->validate_custom_url_pattern($settings['custom_url_pattern']);
            }

            // Validate sitemap_urls
            if (!empty($settings['sitemap_urls']) && is_array($settings['sitemap_urls'])) {
                foreach ($settings['sitemap_urls'] as $sitemap) {
                    if (!empty($sitemap['url'])) {
                        $this->validate_sitemap_url($sitemap['url']);
                    }
                }
            }

            // Validate numeric settings
            if (isset($settings['links_per_sitemap'])) {
                $this->validate_links_per_sitemap($settings['links_per_sitemap']);
            }

        } catch (InvalidArgumentException $e) {
            $validation['valid'] = false;
            $validation['errors'][] = $e->getMessage();
        }

        return $validation;
    }

    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        $settings = $this->get_settings($context_type, $context_id);

        return [
            'sitemap_url' => home_url('/sitemap.xml'),
            'enabled' => $settings['enabled'] ?? true,
            'last_generated' => $settings['last_generated'] ?? '',
            'total_urls' => $this->count_sitemap_urls($settings)
        ];
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        return [
            // Core settings
            'enabled' => true,

            // Multiple Sitemap URLs
            'sitemap_urls' => [
                [
                    'url' => '/sitemap.xml',
                    'type' => 'general',
                    'enabled' => true,
                    'last_checked' => null,
                    'status' => 'unknown'
                ]
            ],
            'use_sitemap_index' => false,

            // General Settings
            'links_per_sitemap' => 1000,
            'include_images' => true,
            'include_featured_images' => false,
            'auto_generate' => true,
            'ping_search_engines' => true,

            // Content Inclusion (backward compatibility)
            'include_posts' => true,
            'include_pages' => true,
            'include_categories' => true,
            'include_tags' => false,

            // Content Filtering
            'exclude_posts' => '',
            'exclude_terms' => '',
            'exclude_password_protected' => true,
            'exclude_private_posts' => true,

            // Advanced Options
            'enable_styling' => true,
            'custom_url_pattern' => 'sitemap-{type}.xml',

            // Generation tracking
            'last_generated' => ''
        ];
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Sitemap',
                'description' => 'Generate XML sitemap for search engines',
                'default' => true
            ],
            'include_posts' => [
                'type' => 'boolean',
                'title' => 'Include Posts',
                'description' => 'Include blog posts in sitemap',
                'default' => true
            ],
            'include_pages' => [
                'type' => 'boolean',
                'title' => 'Include Pages',
                'description' => 'Include static pages in sitemap',
                'default' => true
            ],
            'include_categories' => [
                'type' => 'boolean',
                'title' => 'Include Categories',
                'description' => 'Include category pages in sitemap',
                'default' => true
            ],
            'include_tags' => [
                'type' => 'boolean',
                'title' => 'Include Tags',
                'description' => 'Include tag pages in sitemap',
                'default' => false
            ],

            'auto_generate' => [
                'type' => 'boolean',
                'title' => 'Auto Generate',
                'description' => 'Automatically regenerate sitemap when content changes',
                'default' => true
            ],
            'ping_search_engines' => [
                'type' => 'boolean',
                'title' => 'Ping Search Engines',
                'description' => 'Notify Google and Bing when sitemap is updated',
                'default' => true
            ],
            'last_generated' => [
                'type' => 'string',
                'title' => 'Last Generated',
                'description' => 'Timestamp of last sitemap generation',
                'default' => ''
            ]
        ];
    }

    /**
     * Generate URL entry for sitemap
     *
     * @since 1.0.0
     *
     * @param string $url        URL
     * @param string $lastmod    Last modification date
     * @param float  $priority   Priority (0.0 to 1.0)
     * @param string $changefreq Change frequency
     * @param array  $images     Optional array of image data
     * @return string XML URL entry
     */
    private function generate_url_entry(string $url, string $lastmod, float $priority, string $changefreq, array $images = []): string {
        $xml = "  <url>\n";
        $xml .= "    <loc>" . esc_url($url) . "</loc>\n";
        // Omit <lastmod> when unknown (empty) — a fabricated timestamp is worse
        // than no timestamp, and an absent lastmod is valid per the spec.
        if (!empty($lastmod)) {
            $xml .= "    <lastmod>" . esc_html($lastmod) . "</lastmod>\n";
        }
        $xml .= "    <priority>" . number_format($priority, 1) . "</priority>\n";
        $xml .= "    <changefreq>" . esc_html($changefreq) . "</changefreq>\n";

        // Add image entries if provided
        foreach ($images as $image) {
            $xml .= "    <image:image>\n";
            $xml .= "      <image:loc>" . esc_url($image['url']) . "</image:loc>\n";

            if (!empty($image['title'])) {
                $xml .= "      <image:title>" . esc_html($image['title']) . "</image:title>\n";
            }

            if (!empty($image['alt'])) {
                $xml .= "      <image:caption>" . esc_html($image['alt']) . "</image:caption>\n";
            }

            $xml .= "    </image:image>\n";
        }

        $xml .= "  </url>\n";

        return $xml;
    }

    /**
     * Collect every post <url> entry for the given post type(s) as an array of
     * XML strings, using memory-efficient chunked fetching.
     *
     * Unlike the old capped generator, this returns ALL matching entries — the
     * links-per-sitemap limit is applied later by paginating this array into
     * separate files (see generate_multiple_sitemaps), matching how Rank Math
     * splits large sitemaps instead of truncating them.
     *
     * @since 1.14.0
     *
     * @param array $post_types Array of post types to fetch
     * @param array $settings   Sitemap settings
     * @return array<string> Individual <url>…</url> entry strings
     */
    /**
     * lastmod for the homepage <url> entry: the static front page's real
     * modification time when one is set, otherwise the generation time.
     *
     * @return string ISO-8601 date.
     */
    private function get_homepage_lastmod(): string {
        if (get_option('show_on_front') === 'page') {
            $front_id = (int) get_option('page_on_front');
            if ($front_id) {
                $modified = get_post_field('post_modified_gmt', $front_id);
                if (!empty($modified) && $modified !== '0000-00-00 00:00:00') {
                    return gmdate('c', strtotime($modified));
                }
            }
        }
        return gmdate('c');
    }

    private function collect_post_entries(array $post_types, array $settings): array {
        // Materialize the streaming source for callers that need the full set
        // (e.g. the single un-paginated "general" sitemap). The paginated index
        // path streams collect_post_entries_iter() directly to bound memory.
        return iterator_to_array($this->collect_post_entries_iter($post_types, $settings), false);
    }

    /**
     * Stream <url> entries for the given post types, yielding one at a time.
     *
     * Same chunked query, filtering, and ordering as before, but yields each
     * entry instead of accumulating the whole set — so the paginated index
     * generator never holds every URL of a large post type in memory at once.
     *
     * @param array $post_types Post types to include.
     * @param array $settings   Sitemap settings.
     * @return \Generator<string> <url> entry strings.
     */
    private function collect_post_entries_iter(array $post_types, array $settings): \Generator {
        if (empty($post_types)) {
            return;
        }

        // Parse and validate exclude_posts setting
        $exclude_ids = [];
        if (!empty($settings['exclude_posts'])) {
            try {
                $exclude_ids = $this->validate_exclude_posts($settings['exclude_posts']);
            } catch (InvalidArgumentException $e) {
                // Continue with empty array on validation failure - error details available in exception
            }
        }

        // The static front page is emitted once as the explicit homepage entry,
        // so exclude it here to avoid a duplicate <loc> (its permalink equals
        // home_url('/')).
        if (get_option('show_on_front') === 'page') {
            $front_id = (int) get_option('page_on_front');
            if ($front_id) {
                $exclude_ids[] = $front_id;
            }
        }

        // Resolve the ordered ID list in one indexed query, then hydrate in
        // chunks via post__in. This avoids large OFFSET windows (which MySQL
        // must scan-and-discard, making a full walk O(n^2)) while still loading
        // only one chunk of full post objects into memory at a time. The
        // original order is preserved (the id query and post__in hydration both
        // use it).
        $all_ids = get_posts($this->filter_query_args([
            'post_type'   => $post_types,
            'post_status' => 'publish',
            'numberposts' => -1,
            'exclude'     => $exclude_ids,
            'orderby'     => 'post_type post_date',
            'order'       => 'ASC DESC',
            'fields'      => 'ids',
        ]));

        if (empty($all_ids)) {
            return;
        }

        foreach (array_chunk($all_ids, 500) as $chunk) {
            $posts = get_posts($this->filter_query_args([
                'post_type'   => $post_types,
                'post_status' => 'publish',
                'numberposts' => count($chunk),
                'post__in'    => $chunk,
                'orderby'     => 'post__in', // preserve the resolved order
            ]));

            foreach ($posts as $post) {
                if ($this->should_include_in_sitemap($post, $settings)) {
                    $url = get_permalink($post);
                    $lastmod = gmdate('c', strtotime($post->post_modified_gmt));
                    $priority = $this->calculate_intelligent_priority($post, $post->post_type);
                    $changefreq = $this->calculate_change_frequency($post, $post->post_type);
                    $images = $this->extract_post_images($post, $settings);

                    yield $this->generate_url_entry($url, $lastmod, $priority, $changefreq, $images);
                }
            }

            // Free the hydrated chunk before loading the next one.
            unset($posts);
        }
    }

    /**
     * Resolve and bound the configured links-per-sitemap limit.
     *
     * @since 1.14.0
     *
     * @param array $settings Sitemap settings
     * @return int Links per sitemap file (1–50000)
     */
    private function get_links_per_sitemap(array $settings): int {
        $limit = !empty($settings['links_per_sitemap']) ? intval($settings['links_per_sitemap']) : 1000;

        return max(1, min(50000, $limit));
    }

    /**
     * Stream <url> entries for a taxonomy's terms, yielding one at a time and
     * fetching terms in bounded chunks (number/offset) — so the paginated index
     * generator never holds every term of a large taxonomy in memory at once.
     *
     * @param string $taxonomy Taxonomy name.
     * @param array  $settings Sitemap settings.
     * @return \Generator<string> <url> entry strings.
     */
    private function collect_taxonomy_entries_iter(string $taxonomy, array $settings): \Generator {
        $exclude_term_ids = [];
        if (!empty($settings['exclude_terms'])) {
            try {
                $exclude_term_ids = $this->validate_exclude_terms($settings['exclude_terms']);
            } catch (InvalidArgumentException $e) {
                // Continue with an empty exclude list on validation failure.
            }
        }

        // product_cat may legitimately have empty terms (products added later);
        // every other taxonomy hides empties — matching fetch_taxonomies_optimized().
        $hide_empty = $taxonomy !== 'product_cat';
        $priority   = $taxonomy === 'category' ? 0.6 : 0.4;

        // Resolve the ordered term IDs in one query, then hydrate in chunks via
        // include. Avoids large OFFSET windows (O(n^2) over a full walk) while
        // holding only one chunk of full term objects at a time.
        $all_ids = get_terms($this->filter_term_query_args([
            'taxonomy'   => $taxonomy,
            'hide_empty' => $hide_empty,
            'exclude'    => $exclude_term_ids,
            'orderby'    => 'count',
            'order'      => 'DESC',
            'fields'     => 'ids',
        ]));

        if (is_wp_error($all_ids) || empty($all_ids)) {
            return;
        }

        foreach (array_chunk($all_ids, 1000) as $chunk) {
            $terms = get_terms($this->filter_term_query_args([
                'taxonomy'   => $taxonomy,
                'include'    => $chunk,
                'orderby'    => 'include', // preserve the resolved order
                'hide_empty' => false,     // already filtered by the id query
            ]));

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $url = get_term_link($term);
                if (!is_wp_error($url)) {
                    // Omit lastmod for terms — the generation time is not a real
                    // modification time and would mislabel every term as just-changed.
                    yield $this->generate_url_entry($url, '', $priority, 'weekly');
                }
            }

            unset($terms);
        }
    }

    /**
     * Wrap a set of <url> entry strings in a complete <urlset> document.
     *
     * @since 1.14.0
     *
     * @param array<string> $entries       Entry strings
     * @param array         $settings      Sitemap settings
     * @param bool          $with_image_ns Include the image sitemap namespace
     * @return string Full sitemap XML
     */
    private function wrap_urlset(array $entries, array $settings, bool $with_image_ns): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        if (!empty($settings['enable_styling'])) {
            $xml .= '<?xml-stylesheet type="text/xsl" href="' . home_url('/wp-content/plugins/thinkrank/static/xsl/sitemap.xsl') . '"?>' . "\n";
        }

        if ($with_image_ns && !empty($settings['include_images'])) {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        } else {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        }

        $xml .= implode('', $entries);
        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Derive the filename/URL for a given pagination page.
     *
     * Page 1 keeps the base URL (e.g. /sitemap-posts.xml); pages 2+ insert the
     * page number before the extension (e.g. /sitemap-posts-2.xml).
     *
     * @since 1.14.0
     *
     * @param string $url  Base sitemap URL
     * @param int    $page 1-based page number
     * @return string Paginated URL
     */
    private function paginate_url(string $url, int $page): string {
        if ($page <= 1) {
            return $url;
        }

        return (string) preg_replace('/\.xml$/i', '-' . $page . '.xml', $url);
    }



    /**
     * Extract images from post for sitemap
     *
     * @since 1.0.0
     *
     * @param \WP_Post $post Post object
     * @param array $settings Sitemap settings
     * @return array Array of image data
     */
    private function extract_post_images(\WP_Post $post, array $settings): array {
        $images = [];

        // Skip if images are disabled
        if (empty($settings['include_images'])) {
            return $images;
        }

        // Get featured image if enabled
        if (!empty($settings['include_featured_images'])) {
            $featured_image = $this->get_featured_image($post->ID);
            if ($featured_image) {
                $images[] = $featured_image;
            }
        }

        // Extract images from content
        $content_images = $this->extract_content_images($post->post_content);
        $images = array_merge($images, $content_images);

        // Remove duplicates based on URL
        $unique_images = [];
        $seen_urls = [];
        foreach ($images as $image) {
            if (!in_array($image['url'], $seen_urls)) {
                $unique_images[] = $image;
                $seen_urls[] = $image['url'];
            }
        }

        return $unique_images;
    }

    /**
     * Get featured image data
     *
     * @since 1.0.0
     *
     * @param int $post_id Post ID
     * @return array|null Featured image data or null
     */
    private function get_featured_image(int $post_id): ?array {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return null;
        }

        $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
        if (!$image_url) {
            return null;
        }

        $image_title = get_the_title($thumbnail_id);
        $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);

        return [
            'url' => $image_url,
            'title' => $image_title ?: '',
            'alt' => $image_alt ?: ''
        ];
    }

    /**
     * Extract images from post content
     *
     * @since 1.0.0
     *
     * @param string $content Post content
     * @return array Array of image data
     */
    private function extract_content_images(string $content): array {
        $images = [];

        // Find all img tags in content
        preg_match_all('/<img[^>]+>/i', $content, $img_tags);

        foreach ($img_tags[0] as $img_tag) {
            // Extract src attribute
            if (preg_match('/src=["\']([^"\']+)["\']/', $img_tag, $src_match)) {
                $image_url = $src_match[1];

                // Skip if not a valid URL or external image
                if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                    continue;
                }

                // Extract title and alt attributes
                $title = '';
                $alt = '';

                if (preg_match('/title=["\']([^"\']*)["\']/', $img_tag, $title_match)) {
                    $title = $title_match[1];
                }

                if (preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_match)) {
                    $alt = $alt_match[1];
                }

                $images[] = [
                    'url' => $image_url,
                    'title' => $title,
                    'alt' => $alt
                ];
            }
        }

        return $images;
    }

    /**
     * Get enabled taxonomies based on settings
     *
     * @since 1.0.0
     * @param array $settings Sitemap settings
     * @return array Array of enabled taxonomies
     */
    private function get_enabled_taxonomies(array $settings): array {
        $taxonomies = [];

        // Core taxonomies based on settings
        if (!empty($settings['include_categories'])) {  // ✅ Evidence-based field name
            $taxonomies[] = 'category';
        }

        if (!empty($settings['include_tags'])) {  // ✅ Evidence-based field name
            $taxonomies[] = 'post_tag';
        }

        // Auto-detect public custom taxonomies
        $custom_taxonomies = get_taxonomies([
            'public' => true,
            '_builtin' => false
        ], 'names');

        foreach ($custom_taxonomies as $taxonomy) {
            if ($this->should_include_taxonomy($taxonomy)) {
                $taxonomies[] = $taxonomy;
            }
        }

        return array_unique($taxonomies);
    }



    /**
     * Fetch taxonomies using optimized combined query
     *
     * @since 1.0.0
     *
     * @param array $taxonomies Array of taxonomy names to fetch
     * @param array $settings   Sitemap settings
     * @return array Grouped terms by taxonomy
     */
    private function fetch_taxonomies_optimized(array $taxonomies, array $settings): array {
        if (empty($taxonomies)) {
            return [];
        }

        // Parse and validate exclude_terms setting
        $exclude_term_ids = [];
        if (!empty($settings['exclude_terms'])) {
            try {
                $exclude_term_ids = $this->validate_exclude_terms($settings['exclude_terms']);
            } catch (InvalidArgumentException $e) {
                // Continue with empty array on validation failure - error details available in exception
            }
        }

        // Determine hide_empty setting based on taxonomies
        $hide_empty = true;
        foreach ($taxonomies as $taxonomy) {
            // For product categories, don't hide empty categories since they might not have products yet
            if ($taxonomy === 'product_cat') {
                $hide_empty = false;
                break;
            }
        }

        // OPTIMIZED: Single query for multiple taxonomies
        $all_terms = get_terms($this->filter_term_query_args([
            'taxonomy' => $taxonomies,  // ✅ Multiple taxonomies in single query
            'hide_empty' => $hide_empty,
            'exclude' => $exclude_term_ids,
            'orderby' => 'taxonomy count',
            'order' => 'ASC DESC'  // Order by taxonomy ASC, then count DESC
        ]));

        if (is_wp_error($all_terms)) {
            return [];
        }

        // Group terms by taxonomy
        return $this->group_terms_by_taxonomy($all_terms);
    }

    /**
     * Group terms by taxonomy
     *
     * @since 1.0.0
     *
     * @param array $terms Array of term objects
     * @return array Grouped terms by taxonomy
     */
    private function group_terms_by_taxonomy(array $terms): array {
        $grouped = [];

        foreach ($terms as $term) {
            $taxonomy = $term->taxonomy;  // ✅ Evidence-based property name

            if (!isset($grouped[$taxonomy])) {
                $grouped[$taxonomy] = [];
            }

            $grouped[$taxonomy][] = $term;
        }

        return $grouped;
    }

    /**
     * Process taxonomy terms to XML entries
     *
     * @since 1.0.0
     *
     * @param array  $terms    Array of term objects
     * @param string $taxonomy Taxonomy name
     * @param array  $settings Sitemap settings
     * @return string XML entries
     */
    private function process_taxonomies_to_xml(array $terms, string $taxonomy, array $settings): string {
        $xml = '';

        foreach ($terms as $term) {
            $url = get_term_link($term);
            if (!is_wp_error($url)) {
                // Omit lastmod for terms (generation time is not a real
                // modification time).
                $lastmod = '';
                $priority = $taxonomy === 'category' ? 0.6 : 0.4;
                $changefreq = 'weekly';

                $xml .= $this->generate_url_entry($url, $lastmod, $priority, $changefreq);
            }
        }

        return $xml;
    }

    /**
     * Calculate intelligent priority based on content factors
     *
     * @since 1.0.0
     *
     * @param \WP_Post $post      Post object
     * @param string   $post_type Post type
     * @return float Priority value between 0.1 and 1.0
     */
    private function calculate_intelligent_priority(\WP_Post $post, string $post_type): float {
        $base_priority = $post_type === 'page' ? 0.9 : 0.8;

        // Factors that can adjust priority
        $adjustments = 0;

        // Recent content gets higher priority
        $days_old = (time() - strtotime($post->post_date)) / DAY_IN_SECONDS;
        if ($days_old < 30) {
            $adjustments += 0.1; // Recent content boost
        } elseif ($days_old > 365) {
            $adjustments -= 0.1; // Older content penalty
        }

        // Content length factor
        $content_length = strlen(wp_strip_all_tags($post->post_content));
        if ($content_length > 2000) {
            $adjustments += 0.05; // Comprehensive content boost
        } elseif ($content_length < 500) {
            $adjustments -= 0.1; // Thin content penalty
        }

        // Special page types get higher priority
        if ($post_type === 'page') {
            $page_template = get_page_template_slug($post->ID);
            if (in_array($page_template, ['page-home.php', 'front-page.php']) ||
                $post->ID == get_option('page_on_front')) {
                $base_priority = 1.0; // Homepage gets maximum priority
            } elseif (in_array($page_template, ['page-contact.php', 'page-about.php'])) {
                $adjustments += 0.05; // Important pages boost
            }
        }

        // Ensure priority stays within valid range
        $final_priority = max(0.1, min(1.0, $base_priority + $adjustments));

        return round($final_priority, 1);
    }

    /**
     * Calculate change frequency based on content type and age
     *
     * @since 1.0.0
     *
     * @param \WP_Post $post      Post object
     * @param string   $post_type Post type
     * @return string Change frequency
     */
    private function calculate_change_frequency(\WP_Post $post, string $post_type): string {
        // Pages typically change less frequently
        if ($post_type === 'page') {
            $page_template = get_page_template_slug($post->ID);
            if ($post->ID == get_option('page_on_front')) {
                return 'daily'; // Homepage changes frequently
            } elseif (in_array($page_template, ['page-contact.php', 'page-about.php'])) {
                return 'monthly'; // Static pages change monthly
            }
            return 'yearly'; // Other pages change rarely
        }

        // Posts frequency based on age and type
        $days_old = (time() - strtotime($post->post_date)) / DAY_IN_SECONDS;

        if ($days_old < 7) {
            return 'daily'; // Very recent posts
        } elseif ($days_old < 30) {
            return 'weekly'; // Recent posts
        } elseif ($days_old < 365) {
            return 'monthly'; // Older posts
        }

        return 'yearly'; // Very old posts
    }

    /**
     * Determine if content should be included in sitemap
     *
     * @since 1.0.0
     *
     * @param \WP_Post $post Post object
     * @param array $settings Sitemap settings
     * @return bool Whether to include in sitemap
     */
    private function should_include_in_sitemap(\WP_Post $post, array $settings): bool {
        // Respect user setting for password protected content
        if (!empty($post->post_password) && !empty($settings['exclude_password_protected'])) {
            return false;
        }

        // Respect user setting for private posts
        if ($post->post_status === 'private' && !empty($settings['exclude_private_posts'])) {
            return false;
        }

        // Only published (and, per setting, private) content belongs in the
        // sitemap. Content-quality heuristics (length, "demo"/"test"/"sample"
        // in the title, "lorem ipsum" text) were intentionally removed: an XML
        // sitemap should list every indexable published URL. Filtering by
        // description length silently dropped legitimate WooCommerce products
        // with short descriptions, and the substring title match excluded real
        // pages such as "Demo" or "Product Samples". Indexability is governed
        // by noindex directives below, not by heuristics.
        if (!in_array($post->post_status, ['publish', 'private'], true)) {
            return false;
        }

        // Check if post overrides robots and sets noindex.
        if ((bool) get_post_meta($post->ID, '_thinkrank_robots_meta_enabled', true)) {
            $raw = get_post_meta($post->ID, '_thinkrank_robots_meta', true);
            if (is_string($raw) && $raw !== '') {
                $robots = json_decode($raw, true);
                if (is_array($robots) && !empty($robots['noindex'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Count total URLs in sitemap
     *
     * @since 1.0.0
     *
     * @param array $settings Sitemap settings
     * @return int Total URL count
     */
    public function count_sitemap_urls(array $settings): int {
        $count = 1; // Homepage

        if (!empty($settings['include_posts'])) {
            $count += wp_count_posts('post')->publish;
        }

        if (!empty($settings['include_pages'])) {
            $count += wp_count_posts('page')->publish;
        }

        if (!empty($settings['include_categories'])) {
            $count += wp_count_terms(['taxonomy' => 'category', 'hide_empty' => true]);
        }

        if (!empty($settings['include_tags'])) {
            $count += wp_count_terms(['taxonomy' => 'post_tag', 'hide_empty' => true]);
        }

        return $count;
    }

    /**
     * Handle content changes for auto-generation
     *
     * @since 1.0.0
     * @param int      $post_id Post ID
     * @param \WP_Post $post    Post object
     * @return void
     */
    public function handle_content_change(int $post_id, \WP_Post $post): void {
        // Skip if auto-generation is disabled
        if (!$this->should_auto_generate()) {
            return;
        }

        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Only process published content
        if ($post->post_status !== 'publish') {
            return;
        }

        // Check if this post type should trigger regeneration
        if (!$this->should_include_post_type($post->post_type)) {
            return;
        }

        // Schedule debounced regeneration
        $this->schedule_debounced_regeneration();
    }

    /**
     * Handle content deletion for auto-generation
     *
     * @since 1.0.0
     * @param int $post_id Post ID
     * @return void
     */
    public function handle_content_deletion(int $post_id): void {
        // Skip if auto-generation is disabled
        if (!$this->should_auto_generate()) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Check if this post type should trigger regeneration
        if (!$this->should_include_post_type($post->post_type)) {
            return;
        }

        // Schedule debounced regeneration
        $this->schedule_debounced_regeneration();
    }

    /**
     * Handle content change by ID (for untrash, etc.)
     *
     * @since 1.0.0
     * @param int $post_id Post ID
     * @return void
     */
    public function handle_content_change_by_id(int $post_id): void {
        $post = get_post($post_id);
        if ($post) {
            $this->handle_content_change($post_id, $post);
        }
    }

    /**
     * Handle taxonomy changes for auto-generation
     *
     * @since 1.0.0
     * @param int    $term_id  Term ID
     * @param int    $tt_id    Term taxonomy ID
     * @param string $taxonomy Taxonomy slug
     * @return void
     */
    public function handle_taxonomy_change(int $term_id, int $tt_id, string $taxonomy): void {
        // Skip if auto-generation is disabled
        if (!$this->should_auto_generate()) {
            return;
        }

        // Check if this taxonomy should trigger regeneration
        if (!$this->should_include_taxonomy($taxonomy)) {
            return;
        }

        // Schedule debounced regeneration
        $this->schedule_debounced_regeneration();
    }

    /**
     * Check if auto-generation is enabled
     *
     * @since 1.0.0
     * @return bool True if auto-generation is enabled
     */
    private function should_auto_generate(): bool {
        $settings = $this->get_settings('site');

        // Check if sitemap is enabled
        if (empty($settings['enabled'])) {
            return false;
        }

        // Check if auto-generation is enabled
        return !empty($settings['auto_generate']);
    }

    /**
     * Get enabled post types based on settings
     *
     * @since 1.0.0
     * @param array $settings Sitemap settings
     * @return array Array of enabled post types
     */
    private function get_enabled_post_types(array $settings): array {
        $post_types = [];

        // Core post types based on settings
        if (!empty($settings['include_posts'])) {
            $post_types[] = 'post';
        }

        if (!empty($settings['include_pages'])) {
            $post_types[] = 'page';
        }

        // Auto-detect public custom post types that should be included
        $custom_post_types = get_post_types([
            'public' => true,
            '_builtin' => false
        ], 'names');

        foreach ($custom_post_types as $post_type) {
            if ($this->should_include_post_type($post_type)) {
                $post_types[] = $post_type;
            }
        }

        return array_unique($post_types);
    }

    /**
     * Check if post type should trigger regeneration
     *
     * @since 1.0.0
     * @param string $post_type Post type
     * @return bool True if post type should trigger regeneration
     */
    private function should_include_post_type(string $post_type): bool {
        // Match Rank Math: only publicly viewable post types belong in the
        // sitemap (public && publicly_queryable). Additionally skip post types
        // that opt out of front-end search (exclude_from_search => true) — e.g.
        // Templately's internal `templately_library` store — which are template
        // records, not standalone indexable URLs. BetterDocs `docs` and
        // WooCommerce `product` register exclude_from_search => false, so they
        // remain included.
        if (!is_post_type_viewable($post_type)) {
            return false;
        }

        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj || !empty($post_type_obj->exclude_from_search)) {
            return false;
        }

        return true;
    }

    /**
     * Check if taxonomy should trigger regeneration
     *
     * @since 1.0.0
     * @param string $taxonomy Taxonomy slug
     * @return bool True if taxonomy should trigger regeneration
     */
    private function should_include_taxonomy(string $taxonomy): bool {
        // Include all public taxonomies (presets control which sitemaps are created)
        $taxonomy_obj = get_taxonomy($taxonomy);
        return $taxonomy_obj && $taxonomy_obj->public;
    }

    /**
     * Schedule debounced sitemap regeneration
     *
     * @since 1.0.0
     * @return void
     */
    private function schedule_debounced_regeneration(): void {
        // Clear any existing scheduled regeneration
        wp_clear_scheduled_hook('thinkrank_regenerate_sitemap');

        // Schedule regeneration in 30 seconds to debounce rapid changes
        wp_schedule_single_event(time() + 30, 'thinkrank_regenerate_sitemap');
    }

    /**
     * Public entry point to debounce-rebuild the sitemap after a settings change
     * (e.g. toggling inclusion rules via REST or the MCP ability), so the served
     * file reflects the new settings instead of going stale until a content edit.
     *
     * @return void
     */
    public function schedule_regeneration(): void {
        // Debounce against rapid successive saves, but use the settings-specific
        // hook so the rebuild runs regardless of the auto_generate toggle (which
        // only governs content-change-triggered regeneration).
        wp_clear_scheduled_hook('thinkrank_regenerate_sitemap_settings');
        wp_schedule_single_event(time() + 30, 'thinkrank_regenerate_sitemap_settings');
    }

    /**
     * Rebuild the served sitemap after an explicit settings change.
     *
     * Unlike {@see auto_regenerate_sitemap()}, this is NOT gated on the
     * auto_generate setting: the user deliberately changed inclusion rules and
     * expects the served file to reflect them even if content-triggered
     * auto-generation is turned off. Still respects the master `enabled` flag.
     *
     * @return void
     */
    public function regenerate_sitemap_from_settings(): void {
        try {
            $settings = $this->get_settings('site');
            if (empty($settings['enabled'])) {
                // The sitemap was disabled: remove the previously generated static
                // files so the web server stops serving a stale sitemap that
                // crawlers would otherwise keep fetching.
                $this->delete_published_sitemaps();
                return;
            }
            $this->generate_and_save($settings);
        } catch (\Throwable $e) {
            // Settings-triggered regeneration failed - details in exception.
        }
    }

    /**
     * Remove every static sitemap file ThinkRank publishes to the web root.
     *
     * Called when the sitemap feature is disabled so /sitemap.xml,
     * /sitemap_index.xml, the segmented children (incl. paginated -N pages), and
     * /local-sitemap.xml stop being served. Only ThinkRank's own filenames are
     * targeted; WordPress core's wp-sitemap.xml is left untouched.
     *
     * @return void
     */
    public function delete_published_sitemaps(): void {
        $settings = $this->get_settings('site');

        // Base filenames to remove. Derive the child/index names from the stored
        // sitemap_urls so a custom custom_url_pattern (e.g. seo-{type}.xml) is
        // honored, not just the default sitemap-*.xml naming. Include the current
        // primary + the default names as a safety net.
        $names = [
            'sitemap.xml',
            'sitemap_index.xml',
            'local-sitemap.xml',
            $this->get_primary_sitemap_filename($settings),
        ];

        foreach ((is_array($settings['sitemap_urls'] ?? null) ? $settings['sitemap_urls'] : []) as $config) {
            if (empty($config['url'])) {
                continue;
            }
            $name = basename((string) wp_parse_url($config['url'], PHP_URL_PATH));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        foreach (array_unique(array_filter($names)) as $name) {
            // Remove the file itself and any paginated -N variants of its stem
            // (e.g. seo-posts.xml plus seo-posts-2.xml, seo-posts-3.xml…).
            $path = ABSPATH . $name;
            if (file_exists($path)) {
                wp_delete_file($path);
            }
            if (preg_match('/^(.*)\.xml$/i', $name, $m)) {
                foreach (glob(ABSPATH . $m[1] . '-*.xml') ?: [] as $paged) {
                    wp_delete_file($paged);
                }
            }
        }
    }

    /**
     * Auto-regenerate sitemap (called by scheduled action)
     *
     * @since 1.0.0
     * @return void
     */
    public function auto_regenerate_sitemap(): void {
        try {
            // Double-check that auto-generation is still enabled
            if (!$this->should_auto_generate()) {
                return;
            }

            $this->generate_and_save($this->get_settings('site'));

            // Sitemap auto-regenerated successfully

        } catch (\Throwable $e) {
            // Sitemap auto-regeneration failed - error details available in exception
        }
    }

    /**
     * Build and write the sitemap files for the given settings.
     *
     * Segmented files plus an index when the settings configure one, a single
     * file otherwise, and the standalone local business sitemap either way.
     * Records `last_generated` so the UI's "View Generated Sitemaps" links
     * unlock, mirroring the manual generate endpoint.
     *
     * @since 1.17.0
     * @param array $settings Sitemap settings.
     * @return bool True when the sitemap files were written.
     */
    public function generate_and_save(array $settings): bool {
        // Index mode is driven by the use_sitemap_index toggle (not merely by how
        // many sitemap_urls happen to be configured). When the toggle is on but
        // no child sitemaps are set up yet, synthesize the per-type segmented set
        // so we emit a real <sitemapindex> with paginated children instead of a
        // flat urlset misnamed sitemap_index.xml.
        $settings = $this->maybe_promote_to_index($settings);

        if (!empty($settings['use_sitemap_index']) || count((is_array($settings['sitemap_urls'] ?? null) ? $settings['sitemap_urls'] : [])) > 1) {
            $results = $this->generate_multiple_sitemaps($settings);
            $written = !empty($results['success']);
        } else {
            $xml = $this->generate_sitemap($settings);
            $written = $this->save_sitemap_to_file($xml, $this->get_primary_sitemap_filename($settings));

            // Local business sitemap is a standalone file, regenerated on the
            // single-sitemap path too (this is the default mode).
            $this->regenerate_local_sitemap($settings);
        }

        if ($written) {
            $settings['last_generated'] = gmdate('c');
            $this->save_settings('site', null, $settings);
        }

        return $written;
    }

    /**
     * Resolve index-vs-single mode, synthesizing child sitemaps when needed.
     *
     * - When use_sitemap_index is on but no child sitemaps are configured, build
     *   the per-type segmented set so a real <sitemapindex> is produced (#127).
     * - When the toggle is off but a single flat file would exceed the per-file
     *   URL cap, auto-promote to a paginated index instead of one oversized file
     *   that can cross Google's 50k-URL/50MB limits (#129).
     *
     * @param array $settings Sitemap settings.
     * @return array Possibly-updated settings.
     */
    public function maybe_promote_to_index(array $settings): array {
        $has_children = count((is_array($settings['sitemap_urls'] ?? null) ? $settings['sitemap_urls'] : [])) > 1;

        // Inclusion flags may be absent from a partial payload (e.g. the manual
        // generate endpoint) — fall back to saved settings so synthesized child
        // sitemaps reflect the real include_posts/pages/categories choices.
        $inclusions = array_merge($this->get_settings('site'), $settings);

        if (!empty($settings['use_sitemap_index'])) {
            if (!$has_children) {
                $settings['sitemap_urls'] = $this->build_segmented_sitemap_urls($inclusions);
            }
            return $settings;
        }

        if (!$has_children) {
            $limit = $this->get_links_per_sitemap($settings);
            if ($limit > 0 && $this->estimate_total_sitemap_urls($inclusions) > $limit) {
                $settings['use_sitemap_index'] = true;
                $settings['sitemap_urls']     = $this->build_segmented_sitemap_urls($inclusions);
            }
        }

        return $settings;
    }

    /**
     * Rough count of URLs a single-file sitemap would contain, used only to
     * decide whether to auto-promote to a paginated index. Cheap COUNT queries;
     * intentionally approximate (homepage + published posts of enabled types +
     * terms of enabled taxonomies).
     *
     * @param array $settings Sitemap settings.
     * @return int
     */
    private function estimate_total_sitemap_urls(array $settings): int {
        $total = 1; // homepage

        foreach ($this->get_enabled_post_types($settings) as $post_type) {
            $counts = wp_count_posts($post_type);
            $total += isset($counts->publish) ? (int) $counts->publish : 0;
        }

        foreach ($this->get_enabled_taxonomies($settings) as $taxonomy) {
            $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
            if (!is_wp_error($count)) {
                $total += (int) $count;
            }
        }

        return $total;
    }

    /**
     * Resolve the sitemap file the site publishes for the given settings.
     *
     * Index mode serves the index (`sitemap_index.xml`); the default single-file
     * mode serves `sitemap.xml`. Callers use this to link to — or check for —
     * the file the site actually serves, since ThinkRank's sitemap is a static
     * file in the web root rather than a route.
     *
     * @since 1.17.0
     * @param array $settings Sitemap settings.
     * @return string Sitemap filename.
     */
    public function get_primary_sitemap_filename(array $settings): string {
        $use_index = !empty($settings['use_sitemap_index']);

        foreach ((is_array($settings['sitemap_urls'] ?? null) ? $settings['sitemap_urls'] : []) as $config) {
            if (empty($config['enabled']) || empty($config['url'])) {
                continue;
            }

            if ($use_index !== (($config['type'] ?? '') === 'index')) {
                continue;
            }

            $path = wp_parse_url($config['url'], PHP_URL_PATH);
            if (!empty($path)) {
                return basename($path);
            }
        }

        return $use_index ? 'sitemap_index.xml' : 'sitemap.xml';
    }

    /**
     * Public URL of the sitemap the site serves.
     *
     * @since 1.17.0
     * @param array|null $settings Sitemap settings (falls back to saved site settings).
     * @return string Absolute sitemap URL.
     */
    public function get_primary_sitemap_url(?array $settings = null): string {
        $settings = $settings ?? $this->get_settings('site');

        return home_url('/' . $this->get_primary_sitemap_filename($settings));
    }

    /**
     * Whether the sitemap file this site publishes exists on disk.
     *
     * @since 1.17.0
     * @param array $settings Sitemap settings.
     * @return bool True when the file is present.
     */
    public function primary_sitemap_file_exists(array $settings): bool {
        return file_exists(ABSPATH . $this->get_primary_sitemap_filename($settings));
    }

    /**
     * Save sitemap XML to file
     *
     * @since 1.0.0
     * @param string $sitemap_xml Sitemap XML content
     * @param string $filename Optional. Filename to save (defaults to 'sitemap.xml')
     * @return bool True on success, false on failure
     */
    private function save_sitemap_to_file(string $sitemap_xml, string $filename = 'sitemap.xml'): bool {
        // Validate and sanitize filename for security
        try {
            $filename = $this->validate_sitemap_filename($filename);
        } catch (InvalidArgumentException $e) {
            // File validation failed - error details available in exception
            return false;
        }

        $sitemap_path = ABSPATH . $filename;

        // Use WordPress filesystem API for better security
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem) {
            return $wp_filesystem->put_contents($sitemap_path, $sitemap_xml, FS_CHMOD_FILE);
        }

        // WP_Filesystem initialization failed
        return false;
    }

    /**
     * Build a segmented, index-based sitemap_urls list from inclusion settings.
     *
     * Produces the index entry plus one child sitemap per enabled content type
     * (posts/pages/categories) and one per public custom post type — the shape
     * the "complete" preset creates and that generate_multiple_sitemaps() expects.
     * Used when enabling the index during import so the index has real children
     * instead of being empty.
     *
     * @since 1.14.0
     *
     * @param array $inclusions Inclusion flags (include_posts/pages/categories)
     *                          and optionally custom_url_pattern.
     * @return array Sitemap URL configs
     */
    public function build_segmented_sitemap_urls(array $inclusions): array {
        $pattern = $inclusions['custom_url_pattern'] ?? 'sitemap-{type}.xml';

        $entry = static function (string $url, string $type): array {
            return [
                'url'          => $url,
                'type'         => $type,
                'enabled'      => true,
                'last_checked' => null,
                'status'       => 'unknown',
            ];
        };
        $child = static function (string $type) use ($pattern, $entry): array {
            $file = str_replace('{type}', $type, $pattern);
            if (strpos($file, '/') !== 0) {
                $file = '/' . $file;
            }
            return $entry($file, $type);
        };

        $urls = [$entry('/sitemap_index.xml', 'index')];

        if (!empty($inclusions['include_posts'])) {
            $urls[] = $child('posts');
        }
        if (!empty($inclusions['include_pages'])) {
            $urls[] = $child('pages');
        }
        if (!empty($inclusions['include_categories'])) {
            $urls[] = $child('categories');
        }
        if (!empty($inclusions['include_tags'])) {
            $urls[] = $child('tags');
        }

        // Public custom post types each get a child sitemap (parity with the
        // "complete" preset and with Rank Math, which lists every public CPT).
        foreach (get_post_types(['public' => true, '_builtin' => false], 'names') as $cpt) {
            if ($this->should_include_post_type($cpt)) {
                $urls[] = $child($cpt);
            }
        }

        return $urls;
    }

    /**
     * Generate multiple sitemaps based on settings
     *
     * @since 1.0.0
     * @param array $settings Sitemap settings
     * @return array Results of sitemap generation
     */
    public function generate_multiple_sitemaps(array $settings): array {
        $results = [
            'success' => true,
            'sitemaps_generated' => [],
            'errors' => [],
            'total_urls' => 0
        ];

        $sitemap_urls = (is_array($settings['sitemap_urls'] ?? null) ? $settings['sitemap_urls'] : []);

        if (empty($sitemap_urls)) {
            $results['success'] = false;
            $results['errors'][] = 'No sitemap URLs configured';
            return $results;
        }

        $limit = $this->get_links_per_sitemap($settings);

        // Defer the index until every child sitemap has been generated, so it can
        // list the actual files produced (including pagination pages).
        $index_config = null;
        $index_children = [];

        foreach ($sitemap_urls as $sitemap_config) {
            if (empty($sitemap_config['enabled'])) {
                continue;
            }

            $type = $sitemap_config['type'];

            if ($type === 'index') {
                $index_config = $sitemap_config;
                continue;
            }

            // Skip CPT children that no longer qualify (e.g. an internal store
            // like Templately's `templately_library`) even when a previously
            // saved config still lists them. Built-in aggregate types ('posts',
            // 'pages', 'general', etc.) are not post type names, so this only
            // affects real custom post types.
            if (post_type_exists($type) && !$this->should_include_post_type($type)) {
                continue;
            }

            try {
                // The single "general"/"wordpress" sitemap is one un-paginated file
                // (there is no index to reference extra pages); it no longer drops
                // overflow URLs.
                if ($type === 'general' || $type === 'wordpress') {
                    $xml = $this->generate_sitemap($settings);
                    $this->write_sitemap_page($sitemap_config['url'], $xml, $type, $this->count_urls_in_xml($xml), $results, $index_children);
                    continue;
                }

                $source = $this->stream_type_entries($type, $settings);

                // Unknown type — fall back to a single general sitemap file.
                if ($source === null) {
                    $xml = $this->generate_sitemap($settings);
                    $this->write_sitemap_page($sitemap_config['url'], $xml, $type, $this->count_urls_in_xml($xml), $results, $index_children);
                    continue;
                }

                // Stream the entries into files of at most $limit URLs, matching
                // Rank Math: page 1 keeps the base filename, pages 2+ get a -N
                // suffix, and every page is listed in the index. Buffering only one
                // page at a time keeps peak memory bounded to $limit entries rather
                // than every URL of the type.
                $image_ns = $source['image_ns'];
                $buffer   = [];
                $page     = 0;

                foreach ($source['entries'] as $entry) {
                    $buffer[] = $entry;
                    if (count($buffer) >= $limit) {
                        $page++;
                        $this->write_sitemap_page($this->paginate_url($sitemap_config['url'], $page), $this->wrap_urlset($buffer, $settings, $image_ns), $type, count($buffer), $results, $index_children);
                        $buffer = [];
                    }
                }

                // Flush the trailing partial page, or a single empty page when the
                // type had no entries at all (parity with the previous behavior of
                // always writing at least one page per configured child).
                if (!empty($buffer) || $page === 0) {
                    $page++;
                    $this->write_sitemap_page($this->paginate_url($sitemap_config['url'], $page), $this->wrap_urlset($buffer, $settings, $image_ns), $type, count($buffer), $results, $index_children);
                }

                // Remove pages left over from a previous, larger generation.
                $this->cleanup_stale_pages($sitemap_config['url'], $page);

            } catch (\Exception $e) {
                $results['errors'][] = "Error generating {$type} sitemap: " . $e->getMessage();
                $results['success'] = false;
            }
        }

        // Local business sitemap (parity with Rank Math's local-sitemap.xml).
        // The physical file is written whenever a business identity is
        // configured — independent of segmented vs single mode — and is also
        // listed in the sitemap index when one exists.
        try {
            if ($this->regenerate_local_sitemap($settings) && $index_config !== null) {
                $index_children[] = ['url' => '/local-sitemap.xml'];
            }
        } catch (\Exception $e) {
            $results['errors'][] = 'Error generating local sitemap: ' . $e->getMessage();
            $results['success'] = false;
        }

        // Build the index last, from the child files actually generated.
        if ($index_config !== null) {
            try {
                $index_xml = $this->generate_sitemap_index($index_children, $settings);
                $filename = basename(wp_parse_url($index_config['url'], PHP_URL_PATH));

                if ($this->save_sitemap_to_file($index_xml, $filename)) {
                    $results['sitemaps_generated'][] = [
                        'url'       => $index_config['url'],
                        'type'      => 'index',
                        'filename'  => $filename,
                        'url_count' => count($index_children),
                    ];
                } else {
                    $results['errors'][] = "Failed to save sitemap: {$filename}";
                    $results['success'] = false;
                }
            } catch (\Exception $e) {
                $results['errors'][] = 'Error generating index sitemap: ' . $e->getMessage();
                $results['success'] = false;
            }
        }

        return $results;
    }

    /**
     * Collect the full (un-paginated) entry list for a content sitemap type.
     *
     * @since 1.14.0
     *
     * @param string $type     Sitemap config type (posts, pages, categories, …)
     * @param array  $settings Sitemap settings
     * @return array{entries: array<string>, image_ns: bool}|null Null for an
     *         unknown type (caller falls back to a general sitemap).
     */
    /**
     * Write (or remove) the physical local-sitemap.xml file.
     *
     * Called from every sitemap regeneration path — segmented generation, the
     * single-sitemap auto-regeneration, and the manual generate endpoint — so
     * the local sitemap works regardless of whether the site uses a sitemap
     * index. When no business identity is configured, any stale file is removed.
     *
     * @since 1.15.x
     * @param array|null $settings Sitemap settings (falls back to saved site settings).
     * @return bool True when the file was written, false when nothing was written.
     */
    public function regenerate_local_sitemap(?array $settings = null): bool {
        $settings = $settings ?? $this->get_settings('site');
        $entries  = $this->collect_local_entries();

        if (empty($entries)) {
            // Business identity was cleared — drop any file left from before.
            $path = ABSPATH . 'local-sitemap.xml';
            if (file_exists($path)) {
                wp_delete_file($path);
            }
            return false;
        }

        $xml = $this->wrap_urlset($entries, $settings, false);
        return $this->save_sitemap_to_file($xml, 'local-sitemap.xml');
    }

    /**
     * Build the local business sitemap entries.
     *
     * Returns a single URL entry pointing at the on-site page that carries the
     * business's LocalBusiness/Organization schema (the configured business URL
     * when it's on this site, otherwise the homepage). Gated — like Rank Math's
     * `local-sitemap.xml` — on a business (non-person) type being configured
     * with an actual location (address or geo coordinates). Returns an empty
     * array when no business location is set, so no empty local sitemap is
     * written or added to the index.
     *
     * @since 1.15.x
     * @return array Zero or one URL entry
     */
    private function collect_local_entries(): array {
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-site-identity-manager.php';
        }

        $identity = (new \ThinkRank\SEO\Site_Identity_Manager())->get_settings('site');

        // Gate like Rank Math's local sitemap: a business (non-person) identity
        // is configured. We only emit a single URL (the location page), so a
        // full postal address is NOT required — requiring one wrongly excluded
        // migrated sites, since the Rank Math importer carries over business
        // type/name/phone but not the address. Personal sites, and sites with no
        // business identity at all, get no local sitemap.
        $business_type = strtolower((string) ($identity['business_type'] ?? ''));
        if ($business_type === 'person') {
            return [];
        }
        if ($business_type === '' && empty($identity['business_name'])) {
            return [];
        }

        // Prefer the configured business URL when it points at this site;
        // otherwise fall back to the homepage (which outputs the schema).
        $location_url = home_url('/');
        if (!empty($identity['business_url'])) {
            $candidate = esc_url_raw((string) $identity['business_url']);
            if ($candidate && wp_parse_url($candidate, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
                $location_url = $candidate;
            }
        }

        // Omit lastmod — no real modification source for the local business URL.
        return [$this->generate_url_entry($location_url, '', 0.8, 'weekly')];
    }

    /**
     * Resolve a streaming entry source for a sitemap type.
     *
     * Returns an iterable that yields the type's <url> entries one at a time
     * (so the paginator never materializes the whole type), the image-namespace
     * flag, or null when the type isn't one we build (caller falls back to a
     * single general sitemap). Entry order matches the previous array-based
     * collect_type_entries() exactly.
     *
     * @param string $type     Sitemap type.
     * @param array  $settings Sitemap settings.
     * @return array{entries: iterable<string>, image_ns: bool}|null
     */
    private function stream_type_entries(string $type, array $settings): ?array {
        switch ($type) {
            case 'posts':
                return ['entries' => $this->collect_post_entries_iter(['post'], $settings), 'image_ns' => true];

            case 'pages':
                // The homepage lives in the pages sitemap (parity with prior
                // output) and is emitted first; collect_post_entries_iter()
                // already excludes the static front page, so there's no duplicate.
                $entries = (function () use ($settings) {
                    yield $this->generate_url_entry(home_url('/'), $this->get_homepage_lastmod(), 1.0, 'daily');
                    yield from $this->collect_post_entries_iter(['page'], $settings);
                })();
                return ['entries' => $entries, 'image_ns' => true];

            case 'categories':
                return ['entries' => $this->collect_taxonomy_entries_iter('category', $settings), 'image_ns' => false];

            case 'tags':
                return ['entries' => $this->collect_taxonomy_entries_iter('post_tag', $settings), 'image_ns' => false];

            case 'products':
                $entries = post_type_exists('product') ? $this->collect_post_entries_iter(['product'], $settings) : [];
                return ['entries' => $entries, 'image_ns' => true];

            case 'product_categories':
                $entries = taxonomy_exists('product_cat') ? $this->collect_taxonomy_entries_iter('product_cat', $settings) : [];
                return ['entries' => $entries, 'image_ns' => false];

            default:
                $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'names');
                if (in_array($type, $custom_post_types, true)) {
                    return ['entries' => $this->collect_post_entries_iter([$type], $settings), 'image_ns' => true];
                }
                return null;
        }
    }

    /**
     * Write one sitemap page file and record it in the results + index child list.
     *
     * @since 1.14.0
     *
     * @param string $url             Sitemap page URL
     * @param string $xml             Sitemap XML
     * @param string $type            Sitemap type (for reporting)
     * @param int    $url_count       Number of URLs in this page
     * @param array  $results         Results accumulator (by reference)
     * @param array  $index_children  Index child list (by reference)
     * @return void
     */
    private function write_sitemap_page(string $url, string $xml, string $type, int $url_count, array &$results, array &$index_children): void {
        $filename = basename(wp_parse_url($url, PHP_URL_PATH));

        if ($this->save_sitemap_to_file($xml, $filename)) {
            $results['sitemaps_generated'][] = [
                'url'       => $url,
                'type'      => $type,
                'filename'  => $filename,
                'url_count' => $url_count,
            ];
            $results['total_urls'] += $url_count;
            $index_children[] = ['url' => $url];
        } else {
            $results['errors'][] = "Failed to save sitemap: {$filename}";
            $results['success'] = false;
        }
    }

    /**
     * Delete pagination files left over when a type shrinks to fewer pages.
     *
     * For a base URL like /sitemap-posts.xml, removes sitemap-posts-N.xml files
     * whose page number N exceeds the current page count. Page 1 (the base file,
     * which has no -N suffix) is never touched.
     *
     * @since 1.14.0
     *
     * @param string $base_url      Base (page 1) sitemap URL
     * @param int    $current_pages Number of pages generated this run
     * @return void
     */
    private function cleanup_stale_pages(string $base_url, int $current_pages): void {
        $filename = basename(wp_parse_url($base_url, PHP_URL_PATH));
        if (!preg_match('/^(.*)\.xml$/i', $filename, $m)) {
            return;
        }
        $stem = $m[1];

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return;
        }

        $candidates = glob(ABSPATH . $stem . '-*.xml') ?: [];
        foreach ($candidates as $path) {
            // Only delete numeric-suffixed pages beyond the current count.
            if (preg_match('/-(\d+)\.xml$/', basename($path), $mm) && (int) $mm[1] > $current_pages) {
                $wp_filesystem->delete($path);
            }
        }
    }

    /**
     * Generate sitemap index XML from the list of child sitemap files produced
     * during generation (each already resolved to its final, possibly paginated,
     * URL).
     *
     * @since 1.0.0 (signature updated 1.14.0)
     * @param array $children Array of ['url' => string] child sitemap entries
     * @param array $settings Sitemap settings
     * @return string Sitemap index XML
     */
    private function generate_sitemap_index(array $children, array $settings): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        // Add XSL stylesheet only if styling is enabled
        if (!empty($settings['enable_styling'])) {
            $xml .= '<?xml-stylesheet type="text/xsl" href="' . home_url('/wp-content/plugins/thinkrank/static/xsl/sitemap-index.xsl') . '"?>' . "\n";
        }
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $site_url = home_url();

        foreach ($children as $child) {
            $sitemap_url = $child['url'] ?? '';
            if ($sitemap_url === '') {
                continue;
            }
            if (!str_starts_with($sitemap_url, 'http')) {
                $sitemap_url = $site_url . $sitemap_url;
            }

            $xml .= "  <sitemap>\n";
            $xml .= "    <loc>" . esc_url($sitemap_url) . "</loc>\n";
            $xml .= "    <lastmod>" . gmdate('c') . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * Count URLs in sitemap XML content
     *
     * @since 1.0.0
     * @param string $sitemap_xml Sitemap XML content
     * @return int Number of URLs
     */
    private function count_urls_in_xml(string $sitemap_xml): int {
        return substr_count($sitemap_xml, '<url>');
    }

    /**
     * Validate and sanitize exclude posts input
     *
     * @since 1.0.0
     * @param string $exclude_posts Comma-separated post IDs
     * @return array Validated post IDs
     * @throws InvalidArgumentException If validation fails
     */
    private function validate_exclude_posts(string $exclude_posts): array {
        if (empty($exclude_posts)) {
            return [];
        }

        // Limit input length to prevent DoS
        if (strlen($exclude_posts) > 1000) {
            throw new InvalidArgumentException('Exclude posts list too long (max 1000 characters)');
        }

        // Validate comma-separated integers only
        if (!preg_match('/^[\d,\s]+$/', $exclude_posts)) {
            throw new InvalidArgumentException('Exclude posts must contain only numbers and commas');
        }

        $ids = array_map('intval', array_filter(explode(',', $exclude_posts)));

        // Limit number of exclusions to prevent performance issues
        if (count($ids) > 100) {
            throw new InvalidArgumentException('Too many posts to exclude (max 100)');
        }

        return array_filter($ids, function($id) {
            return $id > 0; // Only positive integers
        });
    }

    /**
     * Validate and sanitize exclude terms input
     *
     * @since 1.0.0
     * @param string $exclude_terms Comma-separated term IDs
     * @return array Validated term IDs
     * @throws InvalidArgumentException If validation fails
     */
    private function validate_exclude_terms(string $exclude_terms): array {
        if (empty($exclude_terms)) {
            return [];
        }

        // Limit input length to prevent DoS
        if (strlen($exclude_terms) > 1000) {
            throw new InvalidArgumentException('Exclude terms list too long (max 1000 characters)');
        }

        // Validate comma-separated integers only
        if (!preg_match('/^[\d,\s]+$/', $exclude_terms)) {
            throw new InvalidArgumentException('Exclude terms must contain only numbers and commas');
        }

        $ids = array_map('intval', array_filter(explode(',', $exclude_terms)));

        // Limit number of exclusions to prevent performance issues
        if (count($ids) > 100) {
            throw new InvalidArgumentException('Too many terms to exclude (max 100)');
        }

        return array_filter($ids, function($id) {
            return $id > 0; // Only positive integers
        });
    }

    /**
     * Validate and sanitize custom URL pattern
     *
     * @since 1.0.0
     * @param string $pattern Custom URL pattern
     * @return string Validated and sanitized pattern
     * @throws InvalidArgumentException If validation fails
     */
    private function validate_custom_url_pattern(string $pattern): string {
        if (empty($pattern)) {
            return 'sitemap-{type}.xml';
        }

        // Remove any HTML/script tags to prevent XSS
        $pattern = wp_strip_all_tags($pattern);

        // Limit pattern length
        if (strlen($pattern) > 100) {
            throw new InvalidArgumentException('URL pattern too long (max 100 characters)');
        }

        // Validate pattern format - only allow safe characters
        if (!preg_match('/^[a-zA-Z0-9\-_{}\.]+$/', $pattern)) {
            throw new InvalidArgumentException('URL pattern contains invalid characters. Only letters, numbers, hyphens, underscores, dots, and {type} are allowed');
        }

        // Ensure it contains {type} placeholder
        if (strpos($pattern, '{type}') === false) {
            throw new InvalidArgumentException('URL pattern must contain {type} placeholder');
        }

        // Ensure it ends with .xml
        if (!str_ends_with($pattern, '.xml')) {
            $pattern .= '.xml';
        }

        return sanitize_file_name($pattern);
    }

    /**
     * Validate sitemap URL
     *
     * @since 1.0.0
     * @param string $url Sitemap URL
     * @return string Validated and sanitized URL
     * @throws InvalidArgumentException If validation fails
     */
    private function validate_sitemap_url(string $url): string {
        if (empty($url)) {
            throw new InvalidArgumentException('Sitemap URL cannot be empty');
        }

        // Remove leading/trailing whitespace
        $url = trim($url);

        // Limit URL length
        if (strlen($url) > 200) {
            throw new InvalidArgumentException('Sitemap URL too long (max 200 characters)');
        }

        // Ensure it starts with /
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        // Validate URL path format
        if (!preg_match('/^\/[a-zA-Z0-9\-_\/\.]+\.xml$/', $url)) {
            throw new InvalidArgumentException('Invalid sitemap URL format. Must be a valid path ending with .xml');
        }

        // Prevent directory traversal
        if (strpos($url, '..') !== false) {
            throw new InvalidArgumentException('Directory traversal not allowed in sitemap URL');
        }

        // Prevent multiple slashes
        $url = preg_replace('/\/+/', '/', $url);

        return sanitize_url($url);
    }

    /**
     * Validate links per sitemap setting
     *
     * @since 1.0.0
     * @param mixed $links_per_sitemap Links per sitemap value
     * @return int Validated links per sitemap
     * @throws InvalidArgumentException If validation fails
     */
    private function validate_links_per_sitemap($links_per_sitemap): int {
        $links = intval($links_per_sitemap);

        if ($links < 1) {
            throw new InvalidArgumentException('Links per sitemap must be at least 1');
        }

        if ($links > 50000) {
            throw new InvalidArgumentException('Links per sitemap cannot exceed 50,000');
        }

        return $links;
    }

    /**
     * Validate sitemap filename for security
     *
     * @since 1.0.0
     * @param string $filename Filename to validate
     * @return string Validated and sanitized filename
     * @throws InvalidArgumentException If validation fails
     */
    private function validate_sitemap_filename(string $filename): string {
        if (empty($filename)) {
            return 'sitemap.xml';
        }

        // Remove any path components to prevent directory traversal
        $filename = basename($filename);

        // Limit filename length
        if (strlen($filename) > 100) {
            throw new InvalidArgumentException('Filename too long (max 100 characters)');
        }

        // Validate filename format - only allow safe characters
        if (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $filename)) {
            throw new InvalidArgumentException('Filename contains invalid characters. Only letters, numbers, hyphens, underscores, and dots are allowed');
        }

        // Prevent directory traversal attempts
        if (strpos($filename, '..') !== false) {
            throw new InvalidArgumentException('Directory traversal not allowed in filename');
        }

        // Ensure it ends with .xml
        if (!str_ends_with($filename, '.xml')) {
            $filename .= '.xml';
        }

        // Additional sanitization
        $filename = sanitize_file_name($filename);

        // Final security check - ensure it's still a valid XML filename
        if (!preg_match('/^[a-zA-Z0-9\-_]+\.xml$/', $filename)) {
            throw new InvalidArgumentException('Invalid XML filename after sanitization');
        }

        return $filename;
    }
}
