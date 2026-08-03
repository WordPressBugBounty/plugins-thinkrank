<?php

/**
 * Frontend SEO Manager Class
 * 
 * Handles frontend SEO meta tag output and WordPress integration
 * 
 * @package ThinkRank\Frontend
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Frontend;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend SEO Manager Class
 * 
 * Single Responsibility: Output SEO meta tags and integrate with WordPress SEO
 * 
 * @since 1.0.0
 */
class SEO_Manager {

    /**
     * Current post ID
     *
     * @var int|null
     */
    private ?int $current_post_id = null;

    /**
     * Current post metadata
     *
     * @var array
     */
    private array $current_metadata = [];

    /**
     * Site Identity Manager instance
     *
     * @var \ThinkRank\SEO\Site_Identity_Manager|null
     */
    private ?\ThinkRank\SEO\Site_Identity_Manager $site_identity_manager = null;

    /**
     * Social Meta Manager instance
     *
     * @var \ThinkRank\SEO\Social_Meta_Manager|null
     */
    private ?\ThinkRank\SEO\Social_Meta_Manager $social_manager = null;

    /**
     * Schema Management System instance
     *
     * @var \ThinkRank\SEO\Schema_Management_System|null
     */
    private ?\ThinkRank\SEO\Schema_Management_System $schema_manager = null;

    /**
     * Global SEO Schema Output instance
     *
     * @var Global_SEO_Schema_Output|null
     */
    private ?Global_SEO_Schema_Output $global_seo_schema = null;

    /**
     * Site identity data cache
     *
     * @var array|null
     */
    private ?array $site_identity_data = null;

    /**
     * Image SEO Manager instance
     *
     * @var \ThinkRank\SEO\Image_SEO_Manager|null
     */
    private ?\ThinkRank\SEO\Image_SEO_Manager $image_seo_manager = null;

    /**
     * Current page context
     *
     * @var string
     */
    private string $current_context = 'site';

    /**
     * Initialize SEO manager
     *
     * @return void
     */
    public function init(): void {
        // Initialize Site Identity Manager
        $this->initialize_site_identity_manager();

        // Initialize Social Meta Manager
        $this->initialize_social_meta_manager();

        // Initialize Schema Manager for enhanced schema output
        $this->initialize_schema_manager();

        // Initialize Global SEO Schema Output
        $this->initialize_global_seo_schema();

        // Initialize Google Analytics Tracking Manager
        $this->initialize_google_analytics_tracking();

        // Initialize Image SEO Manager
        $this->initialize_image_seo_manager();

        // Initialize current post and context data first
        add_action('wp', [$this, 'initialize_current_context']);

        // Use HIGH PRIORITY hooks to override other SEO plugins
        // Priority 1-5 ensures ThinkRank runs before other SEO plugins

        // Override WordPress title with HIGH priority
        add_filter('pre_get_document_title', [$this, 'override_document_title'], 1);
        add_filter('wp_title', [$this, 'override_wp_title'], 1, 2);

        // Remove WordPress core's robots output so ours isn't duplicated.
        // Core registers wp_robots() on wp_head at priority 1; without this the
        // page would emit two <meta name="robots"> tags (core's + ThinkRank's).
        // The priority MUST match core's (1) or remove_action is a no-op.
        //
        // Exception: when "Discourage search engines" is enabled (blog_public=0),
        // leave core's wp_robots in place so it emits the native noindex directive,
        // and ThinkRank suppresses its own robots tag (see output_seo_meta_tags).
        if (get_option('blog_public')) {
            remove_action('wp_head', 'wp_robots', 1);
        }

        // Output meta tags with HIGH priority
        add_action('wp_head', [$this, 'output_meta_description'], 1);
        add_action('wp_head', [$this, 'output_seo_meta_tags'], 2);
        add_action('wp_head', [$this, 'output_open_graph_tags'], 3);
        add_action('wp_head', [$this, 'output_twitter_card_tags'], 4);
        add_action('wp_head', [$this, 'output_platform_meta_tags'], 5);

        // Remove WordPress core's canonical output so ours isn't duplicated.
        // Core registers rel_canonical() on wp_head at priority 10; without this
        // the page would emit two <link rel="canonical"> tags on singular views.
        remove_action('wp_head', 'rel_canonical');
        add_action('wp_head', [$this, 'output_canonical_url'], 6);

        // Add Site Identity specific outputs
        add_action('wp_head', [$this, 'output_site_schema_markup'], 7);
        add_action('wp_head', [$this, 'output_breadcrumb_schema'], 8);

        // Add closing comment (runs last)
        add_action('wp_head', [$this, 'output_closing_comment'], 99);

        // Add breadcrumb display hook
        add_action('thinkrank_breadcrumbs', [$this, 'display_breadcrumbs']);

        // Breadcrumb shortcode for use inside post/page content
        add_shortcode('thinkrank_breadcrumbs', [$this, 'breadcrumbs_shortcode']);

        // Hero section (Site Identity → Hero & Branding): theme action hook +
        // shortcode so the configured hero title/subtitle/CTA/background render.
        add_action('thinkrank_hero', [$this, 'display_hero']);
        add_shortcode('thinkrank_hero', [$this, 'hero_shortcode']);

        // Add robots.txt filter hook
        add_filter('robots_txt', [$this, 'filter_robots_txt'], 10, 2);

        // Keep an existing physical robots.txt in step with WordPress's
        // "Discourage search engines" toggle (blog_public). A physical file
        // bypasses core's robots_txt filter, so flipping blog_public after the
        // file was written would otherwise leave the previous crawl policy served
        // until an unrelated robots save. Covers both transitions.
        add_action('update_option_blog_public', [$this, 'on_blog_public_changed'], 10, 0);

        // Serve the Site Identity favicon through core's site-icon pipeline so
        // wp_site_icon() outputs it on the front-end (and previews pick it up)
        add_filter('get_site_icon_url', [$this, 'filter_site_icon_url'], 10, 2);

        // Process image SEO in content
        add_filter('the_content', [$this, 'filter_content_images'], 99999);
        add_filter('post_thumbnail_html', [$this, 'filter_content_images'], 11, 2);
        add_filter('woocommerce_single_product_image_thumbnail_html', [$this, 'filter_content_images'], 11);

        // Persist alt text to the Media Library for newly uploaded images (opt-in).
        add_action('add_attachment', [$this, 'maybe_fill_attachment_alt']);
    }

    /**
     * Initialize Site Identity Manager
     *
     * @return void
     */
    private function initialize_site_identity_manager(): void {
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-site-identity-manager.php';
        }

        $this->site_identity_manager = new \ThinkRank\SEO\Site_Identity_Manager();
    }

    /**
     * Initialize Social Meta Manager
     *
     * @return void
     */
    private function initialize_social_meta_manager(): void {
        if (!class_exists('ThinkRank\\SEO\\Social_Meta_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-social-meta-manager.php';
        }

        $this->social_manager = new \ThinkRank\SEO\Social_Meta_Manager();
    }

    /**
     * Initialize Schema Manager for enhanced schema output
     *
     * @return void
     */
    private function initialize_schema_manager(): void {
        if (!class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-schema-management-system.php';
        }

        // Initialize Schema Manager and store reference for integration
        $this->schema_manager = new \ThinkRank\SEO\Schema_Management_System();
    }

    /**
     * Initialize Global SEO Schema Output
     *
     * @return void
     */
    private function initialize_global_seo_schema(): void {
        if (!class_exists('ThinkRank\\Frontend\\Global_SEO_Schema_Output')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/frontend/class-global-seo-schema-output.php';
        }

        // Initialize Global SEO Schema Output and store reference
        $this->global_seo_schema = new Global_SEO_Schema_Output();
        $this->global_seo_schema->init();
    }

    /**
     * Initialize Google Analytics Tracking Manager
     *
     * @return void
     */
    private function initialize_google_analytics_tracking(): void {
        if (!class_exists('ThinkRank\\Frontend\\Google_Analytics_Tracking_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/frontend/class-google-analytics-tracking-manager.php';
        }

        // Initialize Google Analytics Tracking Manager
        new \ThinkRank\Frontend\Google_Analytics_Tracking_Manager();
    }

    /**
     * Initialize Image SEO Manager
     *
     * @return void
     */
    private function initialize_image_seo_manager(): void {
        if (!class_exists('ThinkRank\\SEO\\Image_SEO_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-image-seo-manager.php';
        }

        $this->image_seo_manager = new \ThinkRank\SEO\Image_SEO_Manager();
    }

    /**
     * Filter content to inject image SEO attributes
     *
     * @since 1.0.0
     * @param string $content Content to filter
     * @return string Filtered content
     */
    public function filter_content_images(string $content, $post_id = null): string {
        if (!$this->image_seo_manager) {
            return $content;
        }

        // Ensure post_id is an integer if provided
        if ($post_id !== null && !is_numeric($post_id)) {
            $post_id = null;
        }

        return $this->image_seo_manager->process_content($content, $post_id ? (int) $post_id : null);
    }

    /**
     * Persist generated alt text to a freshly uploaded image (opt-in).
     *
     * Delegates to the Image SEO Manager, which no-ops unless the
     * "save alt to media" + "fill on upload" settings are enabled.
     *
     * @since 1.19.1
     * @param int $attachment_id The newly created attachment ID.
     * @return void
     */
    public function maybe_fill_attachment_alt($attachment_id): void {
        if ($this->image_seo_manager && is_numeric($attachment_id)) {
            $this->image_seo_manager->maybe_auto_fill_on_upload((int) $attachment_id);
        }
    }

    /**
     * Initialize current context and post data
     *
     * @return void
     */
    public function initialize_current_context(): void {
        // Determine current context
        $this->current_context = $this->detect_current_context();

        // Initialize post data if singular
        if (is_singular()) {
            $post_id = get_the_ID();
            if ($post_id) {
                $this->current_post_id = $post_id;
                $this->current_metadata = $this->get_post_seo_metadata($post_id);
            }
        }

        // Load site identity data
        $this->load_site_identity_data();
    }

    /**
     * Detect current page context
     *
     * @return string Current context type
     */
    private function detect_current_context(): string {
        if (is_home() || is_front_page()) {
            return 'homepage';
        } elseif (is_single()) {
            return 'post';
        } elseif (is_page()) {
            return 'page';
        } elseif (is_category()) {
            return 'category';
        } elseif (is_tag()) {
            return 'tag';
        } elseif (is_author()) {
            return 'author';
        } elseif (is_search()) {
            return 'search';
        } elseif (is_archive()) {
            return 'archive';
        }

        return 'site';
    }

    /**
     * Load site identity data
     *
     * @return void
     */
    private function load_site_identity_data(): void {
        if ($this->site_identity_manager && $this->site_identity_data === null) {
            $this->site_identity_data = $this->site_identity_manager->get_output_data('site', null);
        }
    }

    /**
     * Get SEO metadata for a post
     * 
     * @param int $post_id Post ID
     * @return array SEO metadata
     */
    private function get_post_seo_metadata(int $post_id): array {
        $focus_keywords = \ThinkRank\SEO\Focus_Keywords::get($post_id);

        $title = get_post_meta($post_id, '_thinkrank_seo_title', true);
        $description = get_post_meta($post_id, '_thinkrank_meta_description', true);

        return [
            // Per-post values may contain variable tags (e.g. "%title% %sep%
            // %sitename%") entered in the metabox, so resolve them. Literal
            // values without tags pass through unchanged.
            'title' => $title ? \ThinkRank\SEO\Pattern_Resolver::resolve_value($title, $post_id) : $title,
            'description' => $description ? \ThinkRank\SEO\Pattern_Resolver::resolve_value($description, $post_id) : $description,
            'focus_keyword' => $focus_keywords[0] ?? '',
            'focus_keywords' => $focus_keywords,
            'seo_score' => get_post_meta($post_id, '_thinkrank_seo_score', true),
        ];
    }

    /**
     * Resolve the effective SEO title for the current request.
     *
     * Same priority chain as override_document_title() — post-specific
     * ThinkRank metadata (resolved _thinkrank_seo_title) > Global SEO
     * template > Site Identity template — without the raw WordPress-title
     * fallback. Returns null when no ThinkRank-managed title applies, letting
     * callers (e.g. the social manager OG fallback) drop to their own default.
     *
     * @return string|null Effective SEO title, or null if none applies.
     */
    private function get_effective_seo_title(): ?string {
        if ($this->has_thinkrank_metadata() && !empty($this->current_metadata['title'])) {
            return $this->current_metadata['title'];
        }

        return $this->generate_context_title();
    }

    /**
     * Override WordPress document title (HIGH PRIORITY)
     * Priority: Post-specific metadata > Global SEO templates > Site Identity templates
     *
     * @param string $title Original title
     * @return string Modified title
     */
    public function override_document_title($title): string {
        // First priority: Post-specific ThinkRank metadata
        if ($this->has_thinkrank_metadata() && !empty($this->current_metadata['title'])) {
            return $this->current_metadata['title'];
        }

        // Second priority: Global SEO templates, Third priority: Site Identity templates
        $generated_title = $this->generate_context_title();
        if ($generated_title) {
            return $generated_title;
        }

        return $title;
    }

    /**
     * Override WordPress wp_title (HIGH PRIORITY)
     * Priority: Post-specific metadata > Global SEO templates > Site Identity templates
     *
     * @param string $title Original title
     * @param string $sep Title separator
     * @return string Modified title
     */
    public function override_wp_title(string $title, string $sep = ''): string {
        // First priority: Post-specific ThinkRank metadata
        if ($this->has_thinkrank_metadata() && !empty($this->current_metadata['title'])) {
            $site_name = get_bloginfo('name');
            return $this->current_metadata['title'] . ($sep ? " $sep " : ' | ') . $site_name;
        }

        // Second priority: Global SEO templates, Third priority: Site Identity templates
        $generated_title = $this->generate_context_title();
        if ($generated_title) {
            return $generated_title;
        }

        return $title;
    }

    /**
     * Output meta description (HIGH PRIORITY)
     * Priority: Post-specific metadata > Global SEO templates > Site Identity templates > WordPress defaults
     *
     * @return void
     */
    public function output_meta_description(): void {
        $description = $this->get_meta_description();

        if ($description) {
            // Output main ThinkRank SEO header comment (only once)
            static $header_output = false;
            if (!$header_output) {
                echo "<!-- Search Engine Optimization by ThinkRank - https://thinkrank.ai/ -->\n";
                $header_output = true;
            }

            // Ensure description is within optimal length (150-160 characters)
            if (strlen($description) > 160) {
                $description = wp_trim_words($description, 25, '...');
            }

            echo "<!-- ThinkRank SEO Meta Description -->\n";
            echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
            echo "<!-- /ThinkRank SEO Meta Description -->\n";
        }
    }

    /**
     * Output SEO meta tags
     *
     * @return void
     */
    public function output_seo_meta_tags(): void {
        echo "<!-- ThinkRank SEO Meta Tags -->\n";

        // Output robots meta tag with proper directives.
        // When "Discourage search engines" (blog_public=0) is enabled, defer to
        // WordPress core's native noindex output and skip ThinkRank's tag so we
        // don't emit a conflicting/duplicate directive.
        if (get_option('blog_public')) {
            $robots_content = $this->get_robots_meta_content();
            echo '<meta name="robots" content="' . esc_attr($robots_content) . '" />' . "\n";
        }

        // Output focus keywords as meta keywords (all keywords, comma-separated)
        $focus_keywords = $this->current_metadata['focus_keywords'] ?? [];
        if (empty($focus_keywords) && !empty($this->current_metadata['focus_keyword'])) {
            $focus_keywords = [$this->current_metadata['focus_keyword']];
        }
        if (!empty($focus_keywords)) {
            $keywords = implode(', ', array_filter(array_map('trim', (array) $focus_keywords), 'strlen'));
            if (!empty($keywords)) {
                echo '<meta name="keywords" content="' . esc_attr($keywords) . '" />' . "\n";
            }
        }

        // Output local SEO meta tags if business info is available
        $this->output_local_seo_meta_tags();

        // Output generator meta tag
        echo '<meta name="generator" content="ThinkRank ' . esc_attr(THINKRANK_VERSION) . '" />' . "\n";

        // Output viewport meta tag if not already present
        if (!has_action('wp_head', 'wp_site_icon') || !wp_is_mobile()) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\n";
        }
        echo "<!-- /ThinkRank SEO Meta Tags -->\n";
    }

    /**
     * Get robots meta content based on context and settings
     *
     * @return string Robots meta content
     */
    private function get_robots_meta_content(): string {
        $robots = [];

        // 404 and search results must never be indexed, regardless of the
        // configured global/post-type directives. Links are still followed so
        // crawlers can discover the rest of the site.
        if (is_404() || is_search()) {
            $robots = apply_filters('thinkrank_robots_meta', ['noindex', 'follow']);
            return implode(', ', array_unique($robots));
        }

        // 1. Get global robot meta settings (Base)
        $global_settings = get_option('thinkrank_global_robot_meta_settings', []);

        // Initialize current settings with global defaults
        $current_settings = wp_parse_args($global_settings, [
            'index' => true,
            'noindex' => false,
            'nofollow' => false,
            'noarchive' => false,
            'noimageindex' => false,
            'nosnippet' => false,
        ]);

        // 2. Apply Post Type based option (if singular)
        if (is_singular()) {
            $post_type = get_post_type();
            $global_seo_settings = get_option('thinkrank_global_seo_settings', []);

            // Check if post type settings are enabled
            $robots_enabled = isset($global_seo_settings[$post_type]['robots_meta_enabled']) && $global_seo_settings[$post_type]['robots_meta_enabled'];

            if ($robots_enabled && isset($global_seo_settings[$post_type]['robots_meta']) && is_array($global_seo_settings[$post_type]['robots_meta'])) {
                // Merge post type settings over global settings
                $current_settings = array_merge($current_settings, $global_seo_settings[$post_type]['robots_meta']);
            }
        }

        // Determine Index/Noindex based on merged settings
        // Priority: if noindex is true, it overrides index
        if (!empty($current_settings['noindex'])) {
            $robots[] = 'noindex';
        } else {
            // Default to index if noindex is not set
            $robots[] = 'index';
        }

        // Determine Follow/Nofollow based on merged settings
        if (!empty($current_settings['nofollow'])) {
            $robots[] = 'nofollow';
        } else {
            $robots[] = 'follow';
        }

        // Other directives
        if (!empty($current_settings['noarchive'])) {
            $robots[] = 'noarchive';
        }
        if (!empty($current_settings['noimageindex'])) {
            $robots[] = 'noimageindex';
        }
        if (!empty($current_settings['nosnippet'])) {
            $robots[] = 'nosnippet';
        }

        // Add advanced directives for better SEO
        // Get advanced settings
        $advanced_settings = [
            'snippet_enabled' => true,
            'max_snippet' => -1,
            'video_preview_enabled' => true,
            'max_video_preview' => -1,
            'image_preview_enabled' => true,
            'max_image_preview' => 'large'
        ];

        // Apply post type specific advanced settings if enabled
        if (is_singular() && isset($robots_enabled) && $robots_enabled && isset($global_seo_settings[$post_type]['advanced_robots_meta'])) {
            $advanced_settings = array_merge($advanced_settings, $global_seo_settings[$post_type]['advanced_robots_meta']);
        }

        // Generate advanced directives
        if (empty($current_settings['nosnippet'])) {
            if ($advanced_settings['snippet_enabled']) {
                $robots[] = 'max-snippet:' . (int)$advanced_settings['max_snippet'];
            }

            if ($advanced_settings['video_preview_enabled']) {
                $robots[] = 'max-video-preview:' . (int)$advanced_settings['max_video_preview'];
            }
        }

        // Only add max-image-preview if we are allowing image indexing
        if (empty($current_settings['noimageindex']) && $advanced_settings['image_preview_enabled']) {
            $robots[] = 'max-image-preview:' . esc_attr($advanced_settings['max_image_preview']);
        }

        // 3. Check for single post meta based option (Overrides everything)
        if (is_singular()) {
            $robots = $this->apply_post_robots_override(get_the_ID(), $robots, $current_settings);
        }

        // Check for archive pages (search is handled by the early return above)
        if (is_archive()) {
            // Allow indexing of category/tag archives but be more conservative
            if (is_paged()) {
                $robots = ['noindex', 'follow'];
            }

            // Honor the global date-archive noindex toggle (written by the
            // Rank Math/Yoast settings importer). Author archives are handled
            // by Author_Archives_Manager via the thinkrank_robots_meta filter.
            if (is_date() && !empty($current_settings['noindex_date_archives'])) {
                $robots = ['noindex', 'follow'];
            }
        }

        // Apply filters for customization
        $robots = apply_filters('thinkrank_robots_meta', $robots);

        // Remove duplicates and implode
        return implode(', ', array_unique($robots));
    }

    /**
     * Apply per-post robots overrides on top of the cascaded directives.
     *
     * Reads `_thinkrank_robots_meta` (JSON) when `_thinkrank_robots_meta_enabled`
     * is truthy. When the override is off, the cascaded directives pass through
     * unchanged.
     *
     * @param int   $post_id           Post being rendered
     * @param array $robots            Directives accumulated so far
     * @param array $current_settings  Effective robots flags (global + post type)
     * @return array Updated robots directive list
     */
    private function apply_post_robots_override(int $post_id, array $robots, array $current_settings): array {
        if (!(bool) get_post_meta($post_id, '_thinkrank_robots_meta_enabled', true)) {
            return $robots;
        }

        $raw_robots = get_post_meta($post_id, '_thinkrank_robots_meta', true);
        $post_robots = is_string($raw_robots) && $raw_robots !== '' ? json_decode($raw_robots, true) : null;
        if (!is_array($post_robots)) {
            return $robots;
        }

        $raw_advanced = get_post_meta($post_id, '_thinkrank_advanced_robots_meta', true);
        $post_advanced = is_string($raw_advanced) && $raw_advanced !== '' ? json_decode($raw_advanced, true) : null;

        $effective = array_merge($current_settings, array_intersect_key($post_robots, array_flip([
            'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet',
        ])));

        $rebuilt = [];
        $rebuilt[] = !empty($effective['noindex']) ? 'noindex' : 'index';
        $rebuilt[] = !empty($effective['nofollow']) ? 'nofollow' : 'follow';

        if (!empty($effective['noarchive'])) {
            $rebuilt[] = 'noarchive';
        }
        if (!empty($effective['noimageindex'])) {
            $rebuilt[] = 'noimageindex';
        }
        if (!empty($effective['nosnippet'])) {
            $rebuilt[] = 'nosnippet';
        }

        if (is_array($post_advanced)) {
            $advanced = array_merge([
                'snippet_enabled'        => true,
                'max_snippet'            => -1,
                'video_preview_enabled'  => true,
                'max_video_preview'      => -1,
                'image_preview_enabled'  => true,
                'max_image_preview'      => 'large',
            ], $post_advanced);

            if (empty($effective['nosnippet'])) {
                if (!empty($advanced['snippet_enabled'])) {
                    $rebuilt[] = 'max-snippet:' . (int) $advanced['max_snippet'];
                }
                if (!empty($advanced['video_preview_enabled'])) {
                    $rebuilt[] = 'max-video-preview:' . (int) $advanced['max_video_preview'];
                }
            }

            if (empty($effective['noimageindex']) && !empty($advanced['image_preview_enabled'])) {
                $rebuilt[] = 'max-image-preview:' . sanitize_text_field((string) $advanced['max_image_preview']);
            }
        }

        return $rebuilt;
    }

    /**
     * Output local SEO meta tags for business information
     *
     * @return void
     */
    private function output_local_seo_meta_tags(): void {
        if (!$this->site_identity_manager) {
            return;
        }

        $settings = $this->site_identity_manager->get_settings('site');

        // Only output if local SEO is enabled and business info is available
        if (empty($settings['local_seo_enabled']) || empty($settings['business_name'])) {
            return;
        }

        echo "<!-- ThinkRank Local SEO Meta Tags -->\n";

        // NAP (Name, Address, Phone) Consistency Meta Tags
        if (!empty($settings['business_name'])) {
            echo '<meta name="business:name" content="' . esc_attr($settings['business_name']) . '" />' . "\n";
        }

        // Business address components
        if (!empty($settings['business_address'])) {
            echo '<meta name="business:contact_data:street_address" content="' . esc_attr($settings['business_address']) . '" />' . "\n";
        }

        if (!empty($settings['business_city'])) {
            echo '<meta name="business:contact_data:locality" content="' . esc_attr($settings['business_city']) . '" />' . "\n";
            echo '<meta name="geo.placename" content="' . esc_attr($settings['business_city']) . '" />' . "\n";
        }

        if (!empty($settings['business_state'])) {
            echo '<meta name="business:contact_data:region" content="' . esc_attr($settings['business_state']) . '" />' . "\n";
        }

        if (!empty($settings['business_postal_code'])) {
            echo '<meta name="business:contact_data:postal_code" content="' . esc_attr($settings['business_postal_code']) . '" />' . "\n";
        }

        if (!empty($settings['business_country'])) {
            echo '<meta name="business:contact_data:country_name" content="' . esc_attr($settings['business_country']) . '" />' . "\n";
        }

        // Phone number
        if (!empty($settings['business_phone'])) {
            echo '<meta name="business:contact_data:phone_number" content="' . esc_attr($settings['business_phone']) . '" />' . "\n";
        }

        // Email address
        if (!empty($settings['business_email'])) {
            echo '<meta name="business:contact_data:email" content="' . esc_attr($settings['business_email']) . '" />' . "\n";
        }

        // Geo-location meta tags (if coordinates are available)
        if (!empty($settings['business_latitude']) && !empty($settings['business_longitude'])) {
            $coordinates = $settings['business_latitude'] . ';' . $settings['business_longitude'];
            echo '<meta name="geo.position" content="' . esc_attr($coordinates) . '" />' . "\n";
            echo '<meta name="ICBM" content="' . esc_attr($settings['business_latitude'] . ', ' . $settings['business_longitude']) . '" />' . "\n";
        }

        // Regional meta tag (state/country combination)
        if (!empty($settings['business_state']) && !empty($settings['business_country'])) {
            $region = strtoupper($settings['business_country']) . '-' . strtoupper($settings['business_state']);
            echo '<meta name="geo.region" content="' . esc_attr($region) . '" />' . "\n";
        }

        // Business hours in structured format
        if (!empty($settings['business_hours']) && is_array($settings['business_hours'])) {
            $formatted_hours = $this->format_business_hours_for_meta($settings['business_hours']);
            if (!empty($formatted_hours)) {
                echo '<meta name="business:hours" content="' . esc_attr($formatted_hours) . '" />' . "\n";
            }
        }

        // Business type
        if (!empty($settings['business_type'])) {
            echo '<meta name="business:type" content="' . esc_attr($settings['business_type']) . '" />' . "\n";
        }

        echo "<!-- /ThinkRank Local SEO Meta Tags -->\n";
    }

    /**
     * Format business hours for meta tag output
     *
     * @param array $business_hours Business hours array
     * @return string Formatted hours string
     */
    private function format_business_hours_for_meta(array $business_hours): string {
        $formatted_days = [];

        $day_abbreviations = [
            'monday' => 'Mo',
            'tuesday' => 'Tu',
            'wednesday' => 'We',
            'thursday' => 'Th',
            'friday' => 'Fr',
            'saturday' => 'Sa',
            'sunday' => 'Su'
        ];

        foreach ($day_abbreviations as $day => $abbrev) {
            if (isset($business_hours[$day]) && !empty($business_hours[$day])) {
                $day_data = $business_hours[$day];

                if (!empty($day_data['closed']) || empty($day_data['open']) || empty($day_data['close'])) {
                    continue; // Skip closed days
                }

                $formatted_days[] = $abbrev . ' ' . $day_data['open'] . '-' . $day_data['close'];
            }
        }

        return implode(', ', $formatted_days);
    }

    /**
     * Output social media Open Graph tags from Social Meta Manager
     *
     * @param array $og_tags Open Graph tags array
     * @return void
     */
    private function output_social_og_tags(array $og_tags): void {
        // Honor the thinkrank_og_type filter here too — this "Enhanced" path is
        // the active OG emitter, so add-ons (e.g. Pro's WooCommerce module which
        // sets 'product' on product pages) must be applied to it, not only to
        // output_open_graph_tags().
        if (isset($og_tags['og:type'])) {
            $og_tags['og:type'] = apply_filters('thinkrank_og_type', $og_tags['og:type']);
        }

        echo "<!-- ThinkRank SEO Open Graph Tags (Enhanced) -->\n";

        // Define optimal order for Open Graph tags
        $og_order = [
            'og:title',
            'og:description',
            'og:type',
            'og:url',
            'og:site_name',
            'og:locale',
            'og:image',
            'og:image:width',
            'og:image:height',
            'og:image:type',
            'og:image:alt',
            'article:published_time',
            'article:modified_time',
            'article:author',
            'article:section'
        ];

        // Output tags in optimal order
        foreach ($og_order as $property) {
            if (!empty($og_tags[$property])) {
                echo '<meta property="' . esc_attr($property) . '" content="' . $this->esc_meta_value($property, $og_tags[$property]) . '" />' . "\n";
            }
        }

        // Output any remaining tags not in the order list
        foreach ($og_tags as $property => $content) {
            if (!empty($content) && !in_array($property, $og_order, true)) {
                echo '<meta property="' . esc_attr($property) . '" content="' . $this->esc_meta_value($property, $content) . '" />' . "\n";
            }
        }

        echo "<!-- /ThinkRank SEO Open Graph Tags -->\n";
    }

    /**
     * Output social media Twitter Card tags from Social Meta Manager
     *
     * @param array $twitter_tags Twitter Card tags array
     * @return void
     */
    private function output_social_twitter_tags(array $twitter_tags): void {
        echo "<!-- ThinkRank SEO Twitter Card Tags (Enhanced) -->\n";

        // Define optimal order for Twitter Card tags
        $twitter_order = [
            'twitter:card',
            'twitter:title',
            'twitter:description',
            'twitter:site',
            'twitter:creator',
            'twitter:image',
            'twitter:image:alt'
        ];

        // Output tags in optimal order
        foreach ($twitter_order as $name) {
            if (!empty($twitter_tags[$name])) {
                echo '<meta name="' . esc_attr($name) . '" content="' . $this->esc_meta_value($name, $twitter_tags[$name]) . '" />' . "\n";
            }
        }

        // Output any remaining tags not in the order list
        foreach ($twitter_tags as $name => $content) {
            if (!empty($content) && !in_array($name, $twitter_order, true)) {
                echo '<meta name="' . esc_attr($name) . '" content="' . $this->esc_meta_value($name, $content) . '" />' . "\n";
            }
        }

        echo "<!-- /ThinkRank SEO Twitter Card Tags -->\n";
    }

    /**
     * Escape a social meta tag value, using esc_url() for URL-valued keys so a
     * javascript:/data: scheme is stripped and output stays spec-compliant, and
     * esc_attr() for everything else.
     *
     * @param string     $key   The OG/Twitter property or name.
     * @param string|int $value The tag value. Image dimension keys
     *                          (og:image:width/height) arrive as integers, so
     *                          accept any scalar and normalise to string here —
     *                          the file is under strict_types, which would
     *                          otherwise throw a TypeError on the int.
     * @return string Escaped value.
     */
    private function esc_meta_value(string $key, $value): string {
        $value = (string) $value;
        $url_keys = [
            'og:image', 'og:image:url', 'og:image:secure_url', 'og:url',
            'twitter:image', 'twitter:player',
        ];
        return in_array($key, $url_keys, true) ? esc_url($value) : esc_attr($value);
    }

    /**
     * Output platform-specific meta tags
     *
     * @return void
     */
    public function output_platform_meta_tags(): void {
        // Try Social Meta Manager for platform tags
        if ($this->social_manager) {
            // Map context for Social Meta Manager (homepage -> site for site-wide settings)
            $social_context = $this->current_context === 'homepage' ? 'site' : $this->current_context;

            // Pass the same effective title/description as the OG and Twitter
            // callbacks so all three share one memoized get_output_data() result
            // (platform tags don't depend on them, so output is unchanged).
            $social_data = $this->social_manager->get_output_data(
                $social_context,
                $this->current_post_id,
                $this->get_effective_seo_title(),
                $this->get_meta_description()
            );

            if ($social_data['enabled'] && !empty($social_data['platform_tags'])) {
                $this->output_social_platform_tags($social_data['platform_tags']);
            }
        }
    }

    /**
     * Output social media platform tags from Social Meta Manager
     *
     * @param array $platform_tags Platform tags array
     * @return void
     */
    private function output_social_platform_tags(array $platform_tags): void {
        echo "<!-- ThinkRank SEO Platform Meta Tags -->\n";

        foreach ($platform_tags as $name => $content) {
            if (!empty($content)) {
                // Determine if it should be property or name attribute
                if (strpos($name, 'fb:') === 0) {
                    // Facebook tags use property attribute
                    echo '<meta property="' . esc_attr($name) . '" content="' . esc_attr($content) . '" />' . "\n";
                } else {
                    // Other platform tags use name attribute
                    echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($content) . '" />' . "\n";
                }
            }
        }

        echo "<!-- /ThinkRank SEO Platform Meta Tags -->\n";
    }

    /**
     * Output Open Graph meta tags (HIGH PRIORITY)
     * Uses Social Meta Manager with fallback to Site Identity templates
     *
     * @return void
     */
    public function output_open_graph_tags(): void {
        // Priority 1: Try Social Meta Manager (Social Media tab settings)
        if ($this->social_manager) {
            // Map context for Social Meta Manager (homepage -> site for site-wide settings)
            $social_context = $this->current_context === 'homepage' ? 'site' : $this->current_context;

            // Effective SEO title/description for this request (resolved
            // per-post value > Global SEO template > Site Identity), identical
            // to what is output as the document <title>/meta description and
            // mirrored by the Social metabox preview. Passed as fallbacks so a
            // cleared Open Graph Title/Description renders the same inherited
            // value the preview shows.
            $social_data = $this->social_manager->get_output_data(
                $social_context,
                $this->current_post_id,
                $this->get_effective_seo_title(),
                $this->get_meta_description()
            );

            // The Social Meta Manager ran, so it owns Open Graph output. If OG is
            // toggled off, emit nothing — do NOT fall through to the basic
            // emitter (which would re-add a full OG block despite the toggle).
            if (!empty($social_data['og_enabled'])) {
                $this->output_social_og_tags($social_data['og_tags']);
            }
            return;
        }

        // Priority 2: Fallback only when the Social Meta Manager is unavailable.
        $this->output_basic_og_tags();
    }

    /**
     * Output basic Open Graph tags (fallback implementation)
     *
     * @return void
     */
    private function output_basic_og_tags(): void {
        // Check for per-post OG overrides first
        $og_title_override = '';
        $og_description_override = '';
        $og_image_override = '';
        if (is_singular() && $this->current_post_id) {
            // Social fields may hold variable tags entered in the metabox.
            $og_title_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value(
                (string) get_post_meta($this->current_post_id, '_thinkrank_og_title', true),
                $this->current_post_id
            );
            $og_description_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value(
                (string) get_post_meta($this->current_post_id, '_thinkrank_og_description', true),
                $this->current_post_id
            );
            $og_image_override = get_post_meta($this->current_post_id, '_thinkrank_og_image', true);
        }

        // Get title using priority system: OG override > post-specific > Global SEO > Site Identity > default
        $title = '';
        if (!empty($og_title_override)) {
            $title = $og_title_override;
        } elseif ($this->has_thinkrank_metadata() && !empty($this->current_metadata['title'])) {
            $title = $this->current_metadata['title'];
        } else {
            $title = $this->generate_context_title();
        }
        if (!$title) {
            $title = is_singular() ? get_the_title() : get_bloginfo('name');
        }

        // Get description with OG override priority
        $description = '';
        if (!empty($og_description_override)) {
            $description = $og_description_override;
        } else {
            $description = $this->get_meta_description();
        }
        if (!$description) {
            $description = is_singular() ? wp_trim_words(get_the_excerpt(), 30) : get_bloginfo('description');
        }

        $url = is_singular() ? get_permalink() : home_url();
        $site_name = $this->site_identity_data && !empty($this->site_identity_data['identity']['site_name'])
            ? $this->site_identity_data['identity']['site_name']
            : get_bloginfo('name');

        // Determine proper og:type based on context
        $og_type = 'website';
        if (is_singular('post')) {
            $og_type = 'article';
        } elseif (is_singular('page')) {
            $og_type = 'website';
        } elseif (is_home() || is_front_page()) {
            $og_type = 'website';
        }

        /**
         * Filter the Open Graph og:type. Add-ons (e.g. ThinkRank Pro's
         * WooCommerce module) use this to set 'product' on product pages.
         *
         * @since 1.14.0
         *
         * @param string $og_type Determined og:type.
         */
        $og_type = apply_filters('thinkrank_og_type', $og_type);

        echo "<!-- ThinkRank SEO Open Graph Meta Tags -->\n";
        echo "<meta property=\"og:type\" content=\"" . esc_attr($og_type) . "\" />\n";
        echo "<meta property=\"og:title\" content=\"" . esc_attr($title) . "\" />\n";
        echo "<meta property=\"og:description\" content=\"" . esc_attr($description) . "\" />\n";
        echo "<meta property=\"og:url\" content=\"" . esc_url($url) . "\" />\n";
        echo "<meta property=\"og:site_name\" content=\"" . esc_attr($site_name) . "\" />\n";
        /**
         * Filter the og:locale value.
         *
         * Defaults to get_locale(), which is only language-correct while the
         * active language's translation files are installed — on a multilingual
         * site without them WordPress keeps reporting the default locale even
         * on translated URLs. The multilingual integration overrides this with
         * the locale its provider reports for the current language.
         *
         * @since 1.23.0
         *
         * @param string $locale Locale for the current request.
         */
        $og_locale = (string) apply_filters('thinkrank_og_locale', get_locale());
        echo "<meta property=\"og:locale\" content=\"" . esc_attr($og_locale) . "\" />\n";

        // Add OG image — per-post override > featured image
        if (is_singular() && $this->current_post_id) {
            if (!empty($og_image_override)) {
                echo "<meta property=\"og:image\" content=\"" . esc_url($og_image_override) . "\" />\n";
                echo "<meta property=\"og:image:secure_url\" content=\"" . esc_url($og_image_override) . "\" />\n";
            } elseif (has_post_thumbnail($this->current_post_id)) {
                $image_url = get_the_post_thumbnail_url($this->current_post_id, 'large');
                echo "<meta property=\"og:image\" content=\"" . esc_url($image_url) . "\" />\n";
                echo "<meta property=\"og:image:secure_url\" content=\"" . esc_url($image_url) . "\" />\n";

                // Get image dimensions and alt text
                $image_id = get_post_thumbnail_id($this->current_post_id);
                $image_meta = wp_get_attachment_metadata($image_id);
                if ($image_meta) {
                    // SVGs (and other vector uploads) report 0x0 — emitting
                    // those as og:image dimensions is invalid, so skip them.
                    $og_width  = isset($image_meta['width']) ? (int) $image_meta['width'] : 0;
                    $og_height = isset($image_meta['height']) ? (int) $image_meta['height'] : 0;
                    if ($og_width > 0 && $og_height > 0) {
                        echo "<meta property=\"og:image:width\" content=\"" . esc_attr($og_width) . "\" />\n";
                        echo "<meta property=\"og:image:height\" content=\"" . esc_attr($og_height) . "\" />\n";
                    }
                    // Derive the real mime type instead of hardcoding image/jpeg,
                    // which mislabels PNG/WebP featured images.
                    $image_mime = get_post_mime_type($image_id);
                    if ($image_mime) {
                        echo "<meta property=\"og:image:type\" content=\"" . esc_attr($image_mime) . "\" />\n";
                    }
                }

                // Add image alt text
                $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                if ($image_alt) {
                    echo "<meta property=\"og:image:alt\" content=\"" . esc_attr($image_alt) . "\" />\n";
                }
            }

            // Add article specific tags for posts only
            if ($og_type === 'article') {
                echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c', $this->current_post_id)) . '" />' . "\n";
                echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c', $this->current_post_id)) . '" />' . "\n";

                // Add author
                $author_id = get_post_field('post_author', $this->current_post_id);
                $author_name = get_the_author_meta('display_name', $author_id);
                echo "<meta property=\"article:author\" content=\"" . esc_attr($author_name) . "\" />\n";

                // Add categories as article:section
                if (is_single()) {
                    $categories = get_the_category($this->current_post_id);
                    if (!empty($categories)) {
                        echo "<meta property=\"article:section\" content=\"" . esc_attr($categories[0]->name) . "\" />\n";
                    }
                }
            }
        }
        echo "<!-- /ThinkRank SEO Open Graph Meta Tags -->\n";
    }

    /**
     * Output Twitter Card meta tags (HIGH PRIORITY)
     * Uses Social Meta Manager with fallback to Site Identity templates
     *
     * @return void
     */
    public function output_twitter_card_tags(): void {
        // Priority 1: Try Social Meta Manager (Social Media tab settings)
        if ($this->social_manager) {
            // Map context for Social Meta Manager (homepage -> site for site-wide settings)
            $social_context = $this->current_context === 'homepage' ? 'site' : $this->current_context;

            // Twitter title/description derive from the same content data, so
            // pass the effective SEO title and meta description as fallbacks to
            // keep a cleared override in step with the document <title>/meta
            // description and the metabox preview.
            $social_data = $this->social_manager->get_output_data(
                $social_context,
                $this->current_post_id,
                $this->get_effective_seo_title(),
                $this->get_meta_description()
            );


            // The Social Meta Manager ran, so it owns Twitter output. If Twitter
            // Cards are toggled off, emit nothing — do NOT fall through to the
            // basic emitter (which would re-add twitter:* tags despite the toggle).
            if (!empty($social_data['twitter_enabled'])) {
                $this->output_social_twitter_tags($social_data['twitter_tags']);
            }
            return;
        }

        // Priority 2: Fallback only when the Social Meta Manager is unavailable.
        $this->output_basic_twitter_tags();
    }

    /**
     * Output basic Twitter Card tags (fallback implementation)
     *
     * @return void
     */
    private function output_basic_twitter_tags(): void {
        // Check for per-post Twitter overrides first, then fall through to OG overrides.
        $twitter_title_override = '';
        $twitter_description_override = '';
        $og_title_override = '';
        $og_description_override = '';
        if (is_singular() && $this->current_post_id) {
            // Social fields may hold variable tags entered in the metabox.
            $pid = $this->current_post_id;
            $twitter_title_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value((string) get_post_meta($pid, '_thinkrank_twitter_title', true), $pid);
            $twitter_description_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value((string) get_post_meta($pid, '_thinkrank_twitter_description', true), $pid);
            $og_title_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value((string) get_post_meta($pid, '_thinkrank_og_title', true), $pid);
            $og_description_override = \ThinkRank\SEO\Pattern_Resolver::resolve_value((string) get_post_meta($pid, '_thinkrank_og_description', true), $pid);
        }

        // Title cascade: Twitter override > OG override > Global SEO > Site Identity > default
        $title = '';
        if (!empty($twitter_title_override)) {
            $title = $twitter_title_override;
        } elseif (!empty($og_title_override)) {
            $title = $og_title_override;
        } elseif ($this->has_thinkrank_metadata() && !empty($this->current_metadata['title'])) {
            $title = $this->current_metadata['title'];
        } else {
            $title = $this->generate_context_title();
        }
        if (!$title) {
            $title = is_singular() ? get_the_title() : get_bloginfo('name');
        }

        // Description cascade: Twitter override > OG override > meta description > excerpt
        $description = '';
        if (!empty($twitter_description_override)) {
            $description = $twitter_description_override;
        } elseif (!empty($og_description_override)) {
            $description = $og_description_override;
        } else {
            $description = $this->get_meta_description();
        }
        if (!$description) {
            $description = is_singular() ? wp_trim_words(get_the_excerpt(), 30) : get_bloginfo('description');
        }

        // Determine card type based on image availability
        $card_type = 'summary';
        if (is_singular() && $this->current_post_id && has_post_thumbnail($this->current_post_id)) {
            $card_type = 'summary_large_image';
        }

        echo "<!-- ThinkRank SEO Twitter Card Meta Tags -->\n";
        echo '<meta name="twitter:card" content="' . esc_attr($card_type) . '" />' . "\n";
        echo "<meta name=\"twitter:title\" content=\"" . esc_attr($title) . "\" />\n";
        echo "<meta name=\"twitter:description\" content=\"" . esc_attr($description) . "\" />\n";

        // Add Twitter image with proper fallback priority
        $twitter_image_url = $this->get_twitter_image_with_fallback();
        if ($twitter_image_url) {
            echo "<meta name=\"twitter:image\" content=\"" . esc_url($twitter_image_url) . "\" />\n";

            // Add image alt text for accessibility (if it's a featured image)
            if (is_singular() && $this->current_post_id && has_post_thumbnail($this->current_post_id)) {
                $featured_image_url = get_the_post_thumbnail_url($this->current_post_id, 'large');
                if ($twitter_image_url === $featured_image_url) {
                    $image_id = get_post_thumbnail_id($this->current_post_id);
                    $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                    if ($image_alt) {
                        echo "<meta name=\"twitter:image:alt\" content=\"" . esc_attr($image_alt) . "\" />\n";
                    }
                }
            }
        }

        // Add site Twitter handle if configured
        if ($this->site_identity_data && !empty($this->site_identity_data['social']['twitter_username'])) {
            $twitter_handle = $this->site_identity_data['social']['twitter_username'];
            // Ensure handle starts with @
            if (strpos($twitter_handle, '@') !== 0) {
                $twitter_handle = '@' . $twitter_handle;
            }
            echo "<meta name=\"twitter:site\" content=\"" . esc_attr($twitter_handle) . "\" />\n";
        }
        echo "<!-- /ThinkRank SEO Twitter Card Meta Tags -->\n";
    }

    /**
     * Output canonical URL
     *
     * @return void
     */
    public function output_canonical_url(): void {
        $canonical_url = '';

        if (is_singular()) {
            // Check for custom canonical URL override
            if ($this->current_post_id) {
                $custom_canonical = get_post_meta($this->current_post_id, '_thinkrank_canonical_url', true);
                if (!empty($custom_canonical)) {
                    $canonical_url = $custom_canonical;
                }
            }

            if (empty($canonical_url)) {
                $canonical_url = $this->current_post_id ? get_permalink($this->current_post_id) : get_permalink();
            }
        } else {
            $canonical_url = $this->get_non_singular_canonical_url();
        }

        /**
         * Filter the canonical URL before output.
         *
         * @since 1.16.0
         *
         * @param string $canonical_url Canonical URL ('' suppresses the tag).
         */
        $canonical_url = apply_filters('thinkrank_canonical_url', $canonical_url);

        if (empty($canonical_url)) {
            return;
        }

        echo "<!-- ThinkRank SEO Canonical URL -->\n";
        echo "<link rel=\"canonical\" href=\"" . esc_url($canonical_url) . "\" />\n";
        echo "<!-- /ThinkRank SEO Canonical URL -->\n";
    }

    /**
     * Build the canonical URL for non-singular contexts.
     *
     * Covers the blog home, post type / taxonomy / author / date archives.
     * Search results and 404 pages get no canonical (they are noindexed).
     * Paginated archives canonicalize to their own page URL so page 2+ is
     * self-referential rather than pointing at page 1.
     *
     * @return string Canonical URL or '' when none applies
     */
    private function get_non_singular_canonical_url(): string {
        if (is_404() || is_search()) {
            return '';
        }

        $canonical_url = '';

        if (is_front_page() || is_home()) {
            $canonical_url = is_home() && !is_front_page()
                ? (string) get_permalink((int) get_option('page_for_posts'))
                : home_url('/');
        } elseif (is_post_type_archive()) {
            $canonical_url = (string) get_post_type_archive_link((string) get_query_var('post_type'));
        } elseif (is_category() || is_tag() || is_tax()) {
            $term_link = get_term_link(get_queried_object());
            $canonical_url = is_wp_error($term_link) ? '' : $term_link;
        } elseif (is_author()) {
            $canonical_url = get_author_posts_url((int) get_queried_object_id());
        } elseif (is_date()) {
            if (is_day()) {
                $canonical_url = get_day_link((int) get_query_var('year'), (int) get_query_var('monthnum'), (int) get_query_var('day'));
            } elseif (is_month()) {
                $canonical_url = get_month_link((int) get_query_var('year'), (int) get_query_var('monthnum'));
            } elseif (is_year()) {
                $canonical_url = get_year_link((int) get_query_var('year'));
            }
        }

        if (empty($canonical_url)) {
            return '';
        }

        // Point paginated archives at their own page, not page 1.
        $paged = (int) get_query_var('paged');
        if ($paged > 1) {
            global $wp_rewrite;
            $canonical_url = $wp_rewrite->using_permalinks()
                ? trailingslashit($canonical_url) . user_trailingslashit($wp_rewrite->pagination_base . '/' . $paged, 'paged')
                : add_query_arg('paged', $paged, $canonical_url);
        }

        return $canonical_url;
    }


    /**
     * Check if ThinkRank has metadata for current post
     *
     * @return bool True if has ThinkRank metadata
     */
    private function has_thinkrank_metadata(): bool {
        if (!is_singular()) {
            return false;
        }

        return !empty($this->current_metadata['title']) || !empty($this->current_metadata['description']);
    }

    /**
     * Check if current page has SEO data (public method for template functions)
     *
     * @return bool True if has SEO data
     */
    public function has_seo_data(): bool {
        // Check if Site Identity is enabled and active
        if ($this->site_identity_data && $this->site_identity_data['enabled']) {
            return true;
        }

        // Check if post has ThinkRank metadata
        return $this->has_thinkrank_metadata();
    }

    /**
     * Get current breadcrumbs data (public method for template functions)
     *
     * @return array|null Breadcrumb data or null if not available
     */
    public function get_current_breadcrumbs(): ?array {
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return null;
        }

        $settings = $this->site_identity_manager->get_settings('site');

        if (empty($settings['breadcrumbs_enabled'])) {
            return null;
        }

        return $this->generate_breadcrumbs($settings);
    }

    /**
     * Get current SEO metadata
     *
     * @return array Current metadata
     */
    public function get_current_metadata(): array {
        return $this->current_metadata;
    }

    /**
     * Generate title based on current context using Site Identity templates
     *
     * @return string|null Generated title or null if no template available
     */
    private function generate_context_title(): ?string {
        // Priority 1: Try Global SEO settings for current post type
        $global_seo_title = $this->get_global_seo_title();
        if ($global_seo_title) {
            return $global_seo_title;
        }

        // Priority 2: Fall back to Site Identity templates
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return null;
        }

        $template = $this->get_title_template_for_context();
        if (!$template) {
            return null;
        }

        $placeholders = $this->get_title_placeholders();
        return $this->process_title_template($template, $placeholders);
    }

    /**
     * Get title from Global SEO settings for current post type
     *
     * @return string|null Generated title or null if no Global SEO template available
     */
    private function get_global_seo_title(): ?string {
        // Only apply Global SEO to singular posts/pages
        if (!is_singular()) {
            return null;
        }

        $post_type = get_post_type();
        if (!$post_type) {
            return null;
        }

        // Get Global SEO settings for this post type
        $global_seo_settings = $this->get_global_seo_settings($post_type);
        if (empty($global_seo_settings['title'])) {
            return null;
        }

        $template = $global_seo_settings['title'];
        $placeholders = $this->get_global_seo_placeholders();

        return $this->process_global_seo_template($template, $placeholders);
    }

    /**
     * Get Global SEO settings for a post type
     *
     * @param string $post_type Post type slug
     * @return array Global SEO settings or empty array
     */
    private function get_global_seo_settings(string $post_type): array {
        $all_settings = get_option('thinkrank_global_seo_settings', []);
        return $all_settings[$post_type] ?? [];
    }

    /**
     * Get placeholders for Global SEO template processing
     *
     * @return array Placeholder values
     */
    private function get_global_seo_placeholders(): array {
        $placeholders = [
            '%title%' => '',
            '%sitename%' => get_bloginfo('name'),
            '%sep%' => $this->get_global_seo_separator(),
            '%excerpt%' => '',
            '%date%' => get_the_date(),
            '%modified%' => get_the_modified_date(),
            '%author%' => '',
            '%category%' => '',
        ];

        // Get current post data if available
        if ($this->current_post_id) {
            $placeholders['%title%'] = get_the_title($this->current_post_id);

            // Get excerpt
            $post = get_post($this->current_post_id);
            if ($post) {
                $excerpt = !empty($post->post_excerpt)
                    ? $post->post_excerpt
                    : wp_trim_words(wp_strip_all_tags($post->post_content), 25, '...');
                $placeholders['%excerpt%'] = $excerpt;
            }

            // Get author
            $author_id = get_post_field('post_author', $this->current_post_id);
            $placeholders['%author%'] = get_the_author_meta('display_name', $author_id);

            // Get category (for posts)
            if (get_post_type($this->current_post_id) === 'post') {
                $categories = get_the_category($this->current_post_id);
                $placeholders['%category%'] = !empty($categories) ? $categories[0]->name : '';
            }
        }

        return $placeholders;
    }

    /**
     * Get separator for Global SEO title
     *
     * @return string Separator symbol
     */
    public function get_global_seo_separator(): string {
        return \ThinkRank\SEO\Site_Identity_Manager::get_active_separator_symbol();
    }

    /**
     * Process Global SEO template with placeholders
     *
     * @param string $template Template string with variables
     * @param array $placeholders Placeholder values
     * @return string Processed title
     */
    private function process_global_seo_template(string $template, array $placeholders): string {
        // Replace all placeholders
        $title = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Clean up multiple spaces
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        // Clean up multiple separators (e.g., "| |" becomes "|")
        $separator = $placeholders['%sep%'] ?? '|';
        $separator_pattern = preg_quote($separator, '/');
        $title = preg_replace('/\s*' . $separator_pattern . '\s*' . $separator_pattern . '\s*/', ' ' . $separator . ' ', $title);

        // Remove leading/trailing separators
        $title = trim($title, " \t\n\r\0\x0B" . $separator);

        return $title;
    }

    /**
     * Get title template for current context
     *
     * @return string|null Template string or null if not found
     */
    private function get_title_template_for_context(): ?string {
        $settings = $this->site_identity_manager->get_settings('site');

        switch ($this->current_context) {
            case 'homepage':
                return $settings['homepage_title'] ?? null;
            case 'post':
                return $settings['post_title'] ?? null;
            case 'page':
                return $settings['page_title'] ?? null;
            case 'category':
                return $settings['category_title'] ?? null;
            case 'tag':
                return $settings['tag_title'] ?? null;
            case 'author':
                return $settings['author_title'] ?? null;
            case 'search':
                return $settings['search_title'] ?? null;
            case 'archive':
                return $settings['archive_title'] ?? null;
            default:
                return null;
        }
    }

    /**
     * Get title placeholders for current context
     *
     * @return array Placeholder values
     */
    private function get_title_placeholders(): array {
        global $post, $wp_query;

        $settings = $this->site_identity_manager->get_settings('site');
        $separator = $this->get_title_separator($settings['title_separator'] ?? 'pipe');

        $placeholders = [
            '%site_title%' => $settings['site_name'] ?? get_bloginfo('name'),
            '%site_name%' => $settings['site_name'] ?? get_bloginfo('name'),
            '%site_description%' => $settings['site_description'] ?? get_bloginfo('description'),
            '%tagline%' => $settings['tagline'] ?? get_bloginfo('description'),
            '%separator%' => ' ' . $separator . ' ',
            '%sep%' => ' ' . $separator . ' ',
            '%date%' => gmdate('F Y'),
        ];

        // Context-specific placeholders
        switch ($this->current_context) {
            case 'post':
            case 'page':
                if ($this->current_post_id) {
                    $placeholders['%post_title%'] = get_the_title($this->current_post_id);
                    $placeholders['%page_title%'] = get_the_title($this->current_post_id);
                    $post_author = get_post_field('post_author', $this->current_post_id);
                    $placeholders['%author%'] = get_the_author_meta('display_name', $post_author);
                    $placeholders['%author_name%'] = get_the_author_meta('display_name', $post_author);

                    // Get categories for posts
                    $post_type = get_post_type($this->current_post_id);
                    if ($post_type === 'post') {
                        $categories = get_the_category($this->current_post_id);
                        $placeholders['%category%'] = !empty($categories) ? $categories[0]->name : '';
                    }
                }
                break;

            case 'category':
                $category = get_queried_object();
                if ($category) {
                    $placeholders['%category_title%'] = $category->name;
                    $placeholders['%category%'] = $category->name;
                }
                break;

            case 'tag':
                $tag = get_queried_object();
                if ($tag) {
                    $placeholders['%tag_title%'] = $tag->name;
                    $placeholders['%tag%'] = $tag->name;
                }
                break;

            case 'author':
                $author = get_queried_object();
                if ($author) {
                    $placeholders['%author_name%'] = $author->display_name;
                    $placeholders['%author%'] = $author->display_name;
                }
                break;

            case 'search':
                $placeholders['%search_term%'] = get_search_query();
                break;

            case 'archive':
                $placeholders['%archive_title%'] = get_the_archive_title();
                break;
        }

        return $placeholders;
    }

    /**
     * Process title template with placeholders
     *
     * @param string $template Template string
     * @param array $placeholders Placeholder values
     * @return string Processed title
     */
    private function process_title_template(string $template, array $placeholders): string {
        $title = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Clean up multiple separators and extra spaces
        $separator = $placeholders['%separator%'] ?? ' | ';

        // Legacy templates stored a literal pipe as separator — apply the active separator to them
        $title = preg_replace('/\s*\|\s*/', $separator, $title);
        $title = preg_replace('/\s*' . preg_quote(trim($separator), '/') . '\s*' . preg_quote(trim($separator), '/') . '\s*/', $separator, $title);
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        // Remove trailing separator
        $separator_trimmed = trim($separator);
        if (substr($title, -strlen($separator_trimmed)) === $separator_trimmed) {
            $title = trim(substr($title, 0, -strlen($separator_trimmed)));
        }

        return $title;
    }

    /**
     * Get title separator symbol
     *
     * @param string $separator_type Separator type
     * @return string Separator symbol
     */
    private function get_title_separator(string $separator_type): string {
        return \ThinkRank\SEO\Site_Identity_Manager::$title_separators[$separator_type]['symbol'] ?? \ThinkRank\SEO\Site_Identity_Manager::$title_separators['pipe']['symbol'];
    }

    /**
     * Get meta description with fallback system
     * Priority: Post-specific metadata > Global SEO templates > Site Identity templates > WordPress defaults
     *
     * @return string|null Meta description or null if none available
     */
    private function get_meta_description(): ?string {
        // First priority: Post-specific ThinkRank metadata
        if ($this->has_thinkrank_metadata() && !empty($this->current_metadata['description'])) {
            return $this->current_metadata['description'];
        }

        // Second priority: Global SEO description template
        $global_seo_description = $this->get_global_seo_description();
        if ($global_seo_description) {
            return $global_seo_description;
        }

        // Archive contexts: derive the description from the archive itself
        // (term description, post type description, author bio)
        $archive_description = $this->get_archive_meta_description();
        if ($archive_description) {
            return $archive_description;
        }

        // Third priority: Site Identity default meta description
        if ($this->site_identity_data && $this->site_identity_data['enabled']) {
            $settings = $this->site_identity_manager->get_settings('site');
            $default_description = $settings['default_meta_description'] ?? '';

            if (!empty($default_description)) {
                return $default_description;
            }
        }

        // Fourth priority: Generate from content for posts/pages
        if (is_singular() && $this->current_post_id) {
            $post_content = get_post_field('post_content', $this->current_post_id);
            if ($post_content) {
                $excerpt = wp_trim_words(wp_strip_all_tags($post_content), 25, '...');
                if (!empty($excerpt)) {
                    return $excerpt;
                }
            }
        }

        // Fifth priority: Site description for homepage
        if (is_home() || is_front_page()) {
            $site_description = get_bloginfo('description');
            if (!empty($site_description)) {
                return $site_description;
            }
        }

        return null;
    }

    /**
     * Get a meta description for archive contexts.
     *
     * Post type archives use the post type's description, taxonomy archives
     * the term description, author archives the author bio. Returns null for
     * non-archive contexts so the regular fallback chain continues.
     *
     * @return string|null Archive description or null when not applicable
     */
    private function get_archive_meta_description(): ?string {
        $description = '';

        // Author archives are intentionally excluded — Author_Archives_Manager
        // outputs its own template-based meta description on wp_head.
        if (is_post_type_archive()) {
            $post_type_object = get_queried_object();
            if ($post_type_object instanceof \WP_Post_Type && !empty($post_type_object->description)) {
                $description = $post_type_object->description;
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $description = term_description() ?: '';
        }

        $description = trim(wp_strip_all_tags((string) $description));
        if ($description === '') {
            return null;
        }

        if (strlen($description) > 160) {
            $description = wp_trim_words($description, 25, '...');
        }

        return $description;
    }

    /**
     * Get description from Global SEO settings for current post type
     *
     * @return string|null Generated description or null if no Global SEO template available
     */
    private function get_global_seo_description(): ?string {
        // Only apply Global SEO to singular posts/pages
        if (!is_singular()) {
            return null;
        }

        $post_type = get_post_type();
        if (!$post_type) {
            return null;
        }

        // Get Global SEO settings for this post type
        $global_seo_settings = $this->get_global_seo_settings($post_type);
        if (empty($global_seo_settings['description'])) {
            return null;
        }

        $template = $global_seo_settings['description'];
        $placeholders = $this->get_global_seo_placeholders();

        return $this->process_global_seo_description_template($template, $placeholders);
    }

    /**
     * Process Global SEO description template with placeholders
     *
     * @param string $template Template string with variables
     * @param array $placeholders Placeholder values
     * @return string Processed description
     */
    private function process_global_seo_description_template(string $template, array $placeholders): string {
        // Replace all placeholders
        $description = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Clean up multiple spaces
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);

        // Ensure description doesn't exceed recommended length (160 characters)
        if (strlen($description) > 160) {
            $description = wp_trim_words($description, 25, '...');
        }

        return $description;
    }

    /**
     * Output site-wide schema markup with priority system
     *
     * Priority: Schema Manager > Site Identity (like Twitter Cards approach)
     *
     * @return void
     */
    public function output_site_schema_markup(): void {
        $has_schema_manager_output = false;
        $has_website_schema = false;

        // PRIORITY 1: Always output site-wide schemas (Organization, Website, LocalBusiness, Person)
        if ($this->schema_manager) {
            $site_wide_schemas = $this->schema_manager->get_deployed_schemas('site', null);

            if (!empty($site_wide_schemas)) {
                foreach ($site_wide_schemas as $schema_type => $schema_info) {
                    $this->output_schema_markup($schema_info['data'], $schema_type, 'Schema Manager');
                }
                $has_schema_manager_output = true;
                $has_website_schema = isset($site_wide_schemas['WebSite']);
            }
        }

        // The homepage always gets a WebSite schema (with a SearchAction) so
        // search engines can associate the site name and sitelinks searchbox —
        // unless the Schema Manager already deployed one.
        if ((is_front_page() || is_home()) && !$has_website_schema) {
            $website_schema = $this->generate_website_schema();

            /**
             * Filter the default homepage WebSite schema before output.
             *
             * @since 1.16.0
             *
             * @param array $website_schema WebSite schema array ([] suppresses output).
             */
            $website_schema = apply_filters('thinkrank_website_schema', $website_schema);

            if (!empty($website_schema)) {
                $this->output_schema_markup($website_schema, 'WebSite', 'Site Identity');
            }
        }

        // PRIORITY 2: Also output page-specific schemas (Article, HowTo, FAQ, etc.) on individual posts/pages
        if ($this->schema_manager && (is_single() || is_page())) {
	        $context_id   = get_the_ID();
	        $context_type = get_post_type( $context_id );
	        $context_type = in_array( $context_type, [ 'site', 'post', 'page', 'product' ] ) ? $context_type : 'post';

            $page_specific_schemas = $this->schema_manager->get_deployed_schemas($context_type, $context_id);

            if (!empty($page_specific_schemas)) {
                // Apply filter for Pro to allow multiple schemas
                // In free version, it's limited to 1 schema if not filtered
                $page_specific_schemas = apply_filters(
                    'thinkrank_page_schemas_to_render',
                    $page_specific_schemas,
                    $context_type,
                    $context_id
                );

                // If still multiple schemas and not Pro, limit to 1 (enforcing free limit)
                $is_pro = \ThinkRank\Core\Plan_Config::is_pro();
                if (!$is_pro && count($page_specific_schemas) > 2) {
                    $page_specific_schemas = array_slice($page_specific_schemas, 0, 2, true);
                }

                foreach ($page_specific_schemas as $schema_type => $schema_info) {
                    $this->output_schema_markup($schema_info['data'], $schema_type, 'Schema Manager');
                }
                $has_schema_manager_output = true;
            }
        }

        // Skip Site Identity fallback if any Schema Manager schemas were output
        if ($has_schema_manager_output) {
            return;
        }

        // PRIORITY 2: Fall back to Site Identity schemas (like basic Twitter Cards)
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return;
        }

        $settings = $this->site_identity_manager->get_settings('site');

        // Only output on homepage or if organization schema is enabled
        if (!is_home() && !is_front_page() && empty($settings['organization_schema'])) {
            return;
        }

        $schema = $this->generate_organization_schema($settings);

        if ($schema) {
            $this->output_schema_markup($schema, 'Organization', 'Site Identity');
        }
    }

    /**
     * Generate the default WebSite schema for the homepage.
     *
     * Includes a SearchAction potentialAction so search engines can surface a
     * sitelinks searchbox, mirroring what Rank Math/Yoast output by default.
     *
     * @return array WebSite schema
     */
    private function generate_website_schema(): array {
        $settings = $this->site_identity_manager ? $this->site_identity_manager->get_settings('site') : [];

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            '@id'      => home_url('/#website'),
            'name'     => !empty($settings['site_name']) ? $settings['site_name'] : get_bloginfo('name'),
            'url'      => home_url('/'),
        ];

        $description = !empty($settings['site_description']) ? $settings['site_description'] : get_bloginfo('description');
        if (!empty($description)) {
            $schema['description'] = $description;
        }

        $schema['potentialAction'] = [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => home_url('/?s={search_term_string}'),
            ],
            'query-input' => 'required name=search_term_string',
        ];

        return $schema;
    }

    /**
     * Output schema markup with consistent formatting
     *
     * @param array  $schema_data Schema data
     * @param string $schema_type Schema type name
     * @param string $source      Source of schema (Schema Manager, Site Identity, etc.)
     * @return void
     */
    private function output_schema_markup(array $schema_data, string $schema_type, string $source): void {
        if (empty($schema_data)) {
            return;
        }

        echo '<!-- ThinkRank ' . esc_html($source) . ': ' . esc_html($schema_type) . ' Schema -->' . "\n";
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "\n";
        echo '</script>' . "\n";
        echo '<!-- /ThinkRank ' . esc_html($source) . ': ' . esc_html($schema_type) . ' Schema -->' . "\n";
    }

    /**
     * Generate organization schema markup
     *
     * Priority: Schema Manager organization settings > Site Identity settings
     *
     * @param array $settings Site identity settings (used as fallback)
     * @return array|null Schema data or null if insufficient data
     */
    private function generate_organization_schema(array $settings): ?array {
        // PRIORITY 1: Get Schema Manager organization settings
        $schema_settings = [];
        if ($this->schema_manager) {
            $schema_settings = $this->schema_manager->get_settings('site', null);
        }

        // Determine organization values (Schema Manager > Site Identity > WordPress default).
        // Use first_non_empty() rather than ??: these settings keys are always present
        // and default to an empty string, so a ?? chain would stop dead on '' and never
        // reach the WordPress fallback.
        $org_name = $this->first_non_empty(
            $schema_settings['organization_name'] ?? null,
            $settings['site_name'] ?? null,
            get_bloginfo('name')
        );

        $org_url = $this->first_non_empty(
            $schema_settings['organization_url'] ?? null,
            $settings['site_url'] ?? null,
            home_url()
        );

        $org_description = $this->first_non_empty(
            $schema_settings['organization_description'] ?? null,
            $settings['site_description'] ?? null,
            get_bloginfo('description')
        );

        if (empty($org_name)) {
            return null;
        }

        // Determine organization type (Schema Manager setting or default)
        $org_type = $schema_settings['organization_type'] ?? 'Organization';

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $org_type,
            '@id' => home_url() . '#organization',
            'name' => $org_name,
            'url' => $org_url,
        ];

        // Add description if available
        if (!empty($org_description)) {
            $schema['description'] = $org_description;
        }

        // Add logo if available with proper ImageObject structure
        // Priority: Schema Manager logo > Site Identity logo
        $logo_url = $schema_settings['organization_logo'] ?? $settings['logo_url'] ?? '';

        if (!empty($logo_url)) {
            $schema['logo'] = [
                '@type' => 'ImageObject',
                '@id' => home_url() . '#logo',
                'url' => $logo_url,
                'contentUrl' => $logo_url,
                'caption' => $org_name . ' Logo'
            ];

            // Also add as image property
            $schema['image'] = $schema['logo'];
        }

        // Add social media accounts if available
        // Priority: Schema Manager social profiles > Site Identity social profiles
        $social_urls = [];

        // Check Schema Manager organization social profiles first
        if (!empty($schema_settings['organization_social_facebook'])) {
            $social_urls[] = $schema_settings['organization_social_facebook'];
        }
        if (!empty($schema_settings['organization_social_twitter'])) {
            $twitter_url = $schema_settings['organization_social_twitter'];
            // Ensure it's a full URL
            if (strpos($twitter_url, 'http') !== 0) {
                $twitter_url = 'https://twitter.com/' . ltrim($twitter_url, '@');
            }
            $social_urls[] = $twitter_url;
        }
        if (!empty($schema_settings['organization_social_linkedin'])) {
            $social_urls[] = $schema_settings['organization_social_linkedin'];
        }
        if (!empty($schema_settings['organization_social_instagram'])) {
            $social_urls[] = $schema_settings['organization_social_instagram'];
        }
        if (!empty($schema_settings['organization_social_youtube'])) {
            $social_urls[] = $schema_settings['organization_social_youtube'];
        }
        if (!empty($schema_settings['organization_social_pinterest'])) {
            $social_urls[] = $schema_settings['organization_social_pinterest'];
        }
        if (!empty($schema_settings['organization_social_whatsapp'])) {
            $social_urls[] = $schema_settings['organization_social_whatsapp'];
        }
        if (!empty($schema_settings['organization_social_telegram'])) {
            $social_urls[] = $schema_settings['organization_social_telegram'];
        }

        // Fallback to Site Identity social profiles if no Schema Manager profiles
        if (empty($social_urls) && !empty($this->site_identity_data['social'])) {
            $social_data = $this->site_identity_data['social'];

            if (!empty($social_data['facebook_url'])) {
                $social_urls[] = $social_data['facebook_url'];
            }
            if (!empty($social_data['twitter_username'])) {
                $twitter_url = 'https://twitter.com/' . ltrim($social_data['twitter_username'], '@');
                $social_urls[] = $twitter_url;
            }
            if (!empty($social_data['linkedin_url'])) {
                $social_urls[] = $social_data['linkedin_url'];
            }
            if (!empty($social_data['instagram_url'])) {
                $social_urls[] = $social_data['instagram_url'];
            }
            if (!empty($social_data['youtube_url'])) {
                $social_urls[] = $social_data['youtube_url'];
            }
        }

        if (!empty($social_urls)) {
            $schema['sameAs'] = $social_urls;
        }

        // Add contact information if available
        // Priority: Schema Manager contact info > Site Identity contact info
        if (!empty($schema_settings['organization_contact_phone']) || !empty($schema_settings['organization_contact_email'])) {
            $contact_point = [
                '@type' => 'ContactPoint',
                'contactType' => $schema_settings['organization_contact_type'] ?? 'customer service'
            ];

            if (!empty($schema_settings['organization_contact_phone'])) {
                $contact_point['telephone'] = $schema_settings['organization_contact_phone'];
            }

            if (!empty($schema_settings['organization_contact_email'])) {
                $contact_point['email'] = $schema_settings['organization_contact_email'];
            }

            if (!empty($schema_settings['organization_contact_hours'])) {
                $contact_point['hoursAvailable'] = $schema_settings['organization_contact_hours'];
            }

            $schema['contactPoint'] = $contact_point;
        } elseif (!empty($settings['contact_email'])) {
            // Fallback to Site Identity contact email
            $schema['email'] = $settings['contact_email'];
        }

        return $schema;
    }

    /**
     * Return the first value that is a non-empty (after trim) string.
     *
     * Settings keys such as organization_url are always present and default to
     * an empty string, so the null-coalescing operator (??) cannot be used to
     * build a fallback chain: '' is not null and would short-circuit the chain.
     * This helper skips empty strings and returns the first real value, falling
     * back to '' when none qualify.
     *
     * @param string|null ...$values Candidate values in priority order.
     * @return string First non-empty value, or '' if none.
     */
    private function first_non_empty(...$values): string {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Output breadcrumb schema markup
     *
     * @return void
     */
    public function output_breadcrumb_schema(): void {
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return;
        }

        $settings = $this->site_identity_manager->get_settings('site');

        // Only output if breadcrumbs are enabled
        if (empty($settings['breadcrumbs_enabled'])) {
            return;
        }

        $breadcrumbs = $this->generate_breadcrumbs($settings);

        if (!empty($breadcrumbs['schema'])) {
            echo "<!-- ThinkRank SEO Breadcrumb Schema Markup -->\n";
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($breadcrumbs['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "\n";
            echo '</script>' . "\n";
            echo "<!-- /ThinkRank SEO Breadcrumb Schema Markup -->\n";
        }
    }

    /**
     * Output closing comment for ThinkRank SEO
     *
     * @return void
     */
    public function output_closing_comment(): void {
        // Only output if we've output any SEO content
        static $header_output = false;
        if ($header_output || $this->has_seo_output()) {
            echo "<!-- /ThinkRank SEO -->\n";
        }
    }

    /**
     * Check if any SEO content has been output
     *
     * @return bool True if SEO content was output
     */
    private function has_seo_output(): bool {
        // Check if we have meta description or any other SEO data
        return !empty($this->get_meta_description()) ||
            $this->has_thinkrank_metadata() ||
            ($this->site_identity_data && $this->site_identity_data['enabled']);
    }

    /**
     * Display breadcrumbs HTML
     *
     * @return void
     */
    public function display_breadcrumbs(): void {
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return;
        }

        $settings = $this->site_identity_manager->get_settings('site');

        // Only display if breadcrumbs are enabled
        if (empty($settings['breadcrumbs_enabled'])) {
            return;
        }

        $breadcrumbs = $this->generate_breadcrumbs($settings);

        if (!empty($breadcrumbs['html'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is properly escaped in generate_breadcrumb_html method
            echo $breadcrumbs['html'];
        }
    }

    /**
     * Render breadcrumbs for the [thinkrank_breadcrumbs] shortcode
     *
     * Respects the same site-identity / breadcrumbs_enabled gates as
     * display_breadcrumbs().
     *
     * @return string Breadcrumb HTML (empty string when disabled)
     */
    public function breadcrumbs_shortcode(): string {
        ob_start();
        $this->display_breadcrumbs();
        return (string) ob_get_clean();
    }

    /**
     * Display the hero section for the `thinkrank_hero` action hook /
     * `thinkrank_hero()` template tag.
     *
     * Gated on the Site Identity master toggle. Emits nothing when no hero
     * content (title/subtitle/CTA) is configured, so an empty hero never
     * appears on the front end.
     *
     * @return void
     */
    public function display_hero(): void {
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return;
        }

        $settings = $this->site_identity_manager->get_settings('site');
        $html = $this->generate_hero_html($settings);

        if ($html !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped field-by-field in generate_hero_html().
            echo $html;
        }
    }

    /**
     * Render the hero section for the [thinkrank_hero] shortcode.
     *
     * Respects the same gates as display_hero().
     *
     * @return string Hero HTML (empty string when disabled or unconfigured)
     */
    public function hero_shortcode(): string {
        ob_start();
        $this->display_hero();
        return (string) ob_get_clean();
    }

    /**
     * Get the current hero section data without displaying it.
     *
     * @return array|null Hero data (title, subtitle, cta_text, cta_url,
     *                    background_image, html) or null when unavailable.
     */
    public function get_current_hero(): ?array {
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return null;
        }

        $settings = $this->site_identity_manager->get_settings('site');

        $hero = [
            'title'            => (string) ($settings['hero_title'] ?? ''),
            'subtitle'         => (string) ($settings['hero_subtitle'] ?? ''),
            'cta_text'         => (string) ($settings['hero_cta_text'] ?? ''),
            'cta_url'          => (string) ($settings['hero_cta_url'] ?? ''),
            'background_image' => (string) ($settings['hero_background_image'] ?? ''),
        ];

        // generate_hero_html() is the single source of truth for the
        // "is anything renderable?" gate (title, subtitle, or a complete CTA),
        // so defer to it rather than duplicate the check — and never expose an
        // empty hero.
        $hero['html'] = $this->generate_hero_html($settings);
        if ($hero['html'] === '') {
            return null;
        }

        return $hero;
    }

    /**
     * Build the hero section HTML from Site Identity settings.
     *
     * Every dynamic value is escaped at the point of output. Returns an empty
     * string when there is no title, subtitle, or complete CTA (text + URL).
     *
     * @param array $settings Site Identity settings
     * @return string Hero HTML, or '' when there is nothing to render
     */
    private function generate_hero_html(array $settings): string {
        $title    = trim((string) ($settings['hero_title'] ?? ''));
        $subtitle = trim((string) ($settings['hero_subtitle'] ?? ''));
        $cta_text = trim((string) ($settings['hero_cta_text'] ?? ''));
        $cta_url  = trim((string) ($settings['hero_cta_url'] ?? ''));
        $bg_image = trim((string) ($settings['hero_background_image'] ?? ''));

        // A CTA is only meaningful with both a label and a destination.
        $has_cta = ($cta_text !== '' && $cta_url !== '');

        // Don't emit an empty hero when nothing renderable is configured. A
        // background image alone — or CTA text without a URL — is not enough.
        if ($title === '' && $subtitle === '' && !$has_cta) {
            return '';
        }

        $classes = ['thinkrank-hero'];
        $style   = '';
        if ($bg_image !== '') {
            $classes[] = 'thinkrank-hero--has-image';
            $style = ' style="background-image:url(' . esc_url($bg_image) . ');"';
        }

        $html  = '<section class="' . esc_attr(implode(' ', $classes)) . '"' . $style . '>';
        $html .= '<div class="thinkrank-hero__inner">';

        if ($title !== '') {
            $html .= '<h2 class="thinkrank-hero__title">' . esc_html($title) . '</h2>';
        }

        if ($subtitle !== '') {
            $html .= '<p class="thinkrank-hero__subtitle">' . esc_html($subtitle) . '</p>';
        }

        if ($has_cta) {
            $html .= '<a class="thinkrank-hero__cta" href="' . esc_url($cta_url) . '">' . esc_html($cta_text) . '</a>';
        }

        $html .= '</div></section>';

        return $html;
    }

    /**
     * Generate breadcrumbs data
     *
     * @param array $settings Breadcrumb settings
     * @return array Breadcrumb data with HTML and schema
     */
    private function generate_breadcrumbs(array $settings): array {
        $breadcrumbs = [
            'items' => [],
            'html' => '',
            'schema' => null
        ];

        // Get breadcrumb items
        $items = $this->get_breadcrumb_items($settings);

        if (empty($items)) {
            return $breadcrumbs;
        }

        $breadcrumbs['items'] = $items;

        // Generate HTML
        $breadcrumbs['html'] = $this->generate_breadcrumb_html($items, $settings);

        // Generate schema
        $breadcrumbs['schema'] = $this->generate_breadcrumb_schema($items);

        return $breadcrumbs;
    }

    /**
     * Filter WordPress robots.txt output
     *
     * @param string $output The default robots.txt output
     * @param string $public Whether the site is public
     * @return string Modified robots.txt content
     */
    public function filter_robots_txt(string $output, string $public): string {
        // Only override if Site Identity is enabled and robots.txt management is enabled
        if (!$this->site_identity_data || !$this->site_identity_data['enabled']) {
            return $output;
        }

        $settings = $this->site_identity_manager->get_settings('site');
        if (empty($settings['robots_txt_enabled'])) {
            return $output;
        }

        // Serve the effective content (manual textarea edit if present, else
        // auto-generated) so the live /robots.txt matches what the admin sees.
        try {
            $content = $this->site_identity_manager->render_robots_txt();

            if (!empty($content)) {
                return $content;
            }
        } catch (\Exception $e) {
            // Rendering failed - fall back to default output
        }

        // Fallback to default output if rendering fails
        return $output;
    }

    /**
     * Re-sync the physical robots.txt when WordPress's "Discourage search
     * engines" setting (blog_public) changes.
     *
     * Only acts when ThinkRank robots management is enabled AND a physical
     * robots.txt already exists — a stale physical file is the failure being
     * fixed. When no file exists the virtual robots_txt filter already reflects
     * blog_public live (render_robots_txt() enforces the full block), so there is
     * nothing to re-sync and no reason to create a file the user never generated.
     *
     * @return void
     */
    public function on_blog_public_changed(): void {
        if (!$this->site_identity_manager) {
            return;
        }

        // Gate on robots management (robots_txt_enabled), not the Site Identity
        // master toggle: the physical file's lifecycle is governed by that
        // setting alone (same as sync_robots_txt_file() and the save endpoint),
        // and a stale physical file is served by the web server regardless of the
        // master toggle.
        $settings = $this->site_identity_manager->get_settings('site');
        if (empty($settings['robots_txt_enabled'])) {
            return;
        }

        if (file_exists(ABSPATH . 'robots.txt')) {
            $this->site_identity_manager->sync_robots_txt_file();
        }
    }

    /**
     * Use the Site Identity favicon as the site icon URL
     *
     * When a favicon is uploaded in ThinkRank Site Identity it takes
     * precedence over the core site icon; with no core icon set this also
     * makes has_site_icon() truthy so wp_site_icon() prints the icon tags.
     * Reads settings directly (not site_identity_data) because this filter
     * also runs in admin, before initialize_current_context().
     *
     * @param string $url  Site icon URL from core
     * @param int    $size Requested icon size
     * @return string Icon URL
     */
    public function filter_site_icon_url($url, $size = 512): string {
        $settings = $this->site_identity_manager->get_settings('site');

        if (empty($settings['enabled'])) {
            return (string) $url;
        }

        // Apple touch icon has its own dedicated setting
        if ((int) $size === 180 && !empty($settings['apple_touch_icon_url'])) {
            return esc_url($settings['apple_touch_icon_url']);
        }

        if (!empty($settings['favicon_url'])) {
            return esc_url($settings['favicon_url']);
        }

        return (string) $url;
    }

    /**
     * Get breadcrumb items for current page
     *
     * @param array $settings Breadcrumb settings
     * @return array Breadcrumb items
     */
    private function get_breadcrumb_items(array $settings): array {
        $items = [];

        // Always start with home
        $home_text = $settings['breadcrumb_home_text'] ?? 'Home';
        $items[] = [
            'title' => $home_text,
            'url' => home_url(),
            'position' => 1
        ];

        $position = 2;

        if (is_single()) {
            $current_post_id = get_the_ID();

            if ($current_post_id) {
                // Add categories for posts
                $post_type = get_post_type($current_post_id);
                if ($post_type === 'post') {
                    $categories = get_the_category($current_post_id);
                    if (!empty($categories)) {
                        $category = $categories[0];
                        $items[] = [
                            'title' => $category->name,
                            'url' => get_category_link($category->term_id),
                            'position' => $position++
                        ];
                    }
                }

                // Add current post
                if (empty($settings['show_current_page']) || $settings['show_current_page']) {
                    $items[] = [
                        'title' => get_the_title($current_post_id),
                        'url' => get_permalink($current_post_id),
                        'position' => $position,
                        'current' => true
                    ];
                }
            }
        } elseif (is_page()) {
            $current_post_id = get_the_ID();

            if ($current_post_id) {
                // Add parent pages
                $parents = [];
                $parent_id = wp_get_post_parent_id($current_post_id);

                while ($parent_id) {
                    $parent = get_post($parent_id);
                    if ($parent) {
                        $parents[] = [
                            'title' => get_the_title($parent->ID),
                            'url' => get_permalink($parent->ID),
                            'position' => 0 // Will be set later
                        ];
                        $parent_id = $parent->post_parent;
                    } else {
                        break;
                    }
                }

                // Reverse to get correct order
                $parents = array_reverse($parents);

                // Add parents with correct positions
                foreach ($parents as $parent) {
                    $parent['position'] = $position++;
                    $items[] = $parent;
                }

                // Add current page
                if (empty($settings['show_current_page']) || $settings['show_current_page']) {
                    $items[] = [
                        'title' => get_the_title($current_post_id),
                        'url' => get_permalink($current_post_id),
                        'position' => $position,
                        'current' => true
                    ];
                }
            }
        } elseif (is_category()) {
            $category = get_queried_object();

            // Add parent categories
            $parents = [];
            $parent_id = $category->parent;

            while ($parent_id) {
                $parent = get_category($parent_id);
                if ($parent && !is_wp_error($parent)) {
                    $parents[] = [
                        'title' => $parent->name,
                        'url' => get_category_link($parent->term_id),
                        'position' => 0 // Will be set later
                    ];
                    $parent_id = $parent->parent;
                } else {
                    break;
                }
            }

            // Reverse to get correct order
            $parents = array_reverse($parents);

            // Add parents with correct positions
            foreach ($parents as $parent) {
                $parent['position'] = $position++;
                $items[] = $parent;
            }

            // Add current category
            if (empty($settings['show_current_page']) || $settings['show_current_page']) {
                $items[] = [
                    'title' => $category->name,
                    'url' => get_category_link($category->term_id),
                    'position' => $position,
                    'current' => true
                ];
            }
        }

        return $items;
    }

    /**
     * Generate breadcrumb HTML
     *
     * @param array $items Breadcrumb items
     * @param array $settings Breadcrumb settings
     * @return string HTML output
     */
    private function generate_breadcrumb_html(array $items, array $settings): string {
        if (empty($items)) {
            return '';
        }

        $separator = $settings['breadcrumb_separator'] ?? '>';
        $prefix = $settings['breadcrumb_prefix'] ?? '';

        $html = '<nav class="thinkrank-breadcrumbs" aria-label="Breadcrumb">';

        if (!empty($prefix)) {
            $html .= '<span class="breadcrumb-prefix">' . esc_html($prefix) . '</span> ';
        }

        $html .= '<ol class="breadcrumb-list">';

        $total_items = count($items);

        foreach ($items as $index => $item) {
            $is_last = ($index === $total_items - 1);
            $is_current = !empty($item['current']);

            $html .= '<li class="breadcrumb-item' . ($is_current ? ' current' : '') . '">';

            if (!$is_current && !empty($item['url'])) {
                $html .= '<a href="' . esc_url($item['url']) . '">' . esc_html($item['title']) . '</a>';
            } else {
                $html .= '<span>' . esc_html($item['title']) . '</span>';
            }

            if (!$is_last) {
                $html .= ' <span class="breadcrumb-separator">' . esc_html($separator) . '</span> ';
            }

            $html .= '</li>';
        }

        $html .= '</ol>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Generate breadcrumb schema markup
     *
     * @param array $items Breadcrumb items
     * @return array Schema data
     */
    private function generate_breadcrumb_schema(array $items): array {
        if (empty($items)) {
            return [];
        }

        $schema_items = [];

        foreach ($items as $item) {
            $schema_items[] = [
                '@type' => 'ListItem',
                'position' => $item['position'],
                'name' => $item['title'],
                'item' => $item['url']
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $schema_items
        ];
    }

    /**
     * Get Twitter image with proper fallback priority
     *
     * @since 1.0.0
     *
     * @return string|null Twitter image URL or null if none available
     */
    private function get_twitter_image_with_fallback(): ?string {
        // Cascade: post-specific Twitter image > post-specific OG image > featured image.
        if (is_singular() && $this->current_post_id) {
            $post_twitter_image = get_post_meta($this->current_post_id, '_thinkrank_twitter_image', true);
            if (!empty($post_twitter_image)) {
                return $post_twitter_image;
            }

            $post_og_image = get_post_meta($this->current_post_id, '_thinkrank_og_image', true);
            if (!empty($post_og_image)) {
                return $post_og_image;
            }

            // Check featured image as fallback for posts
            if (has_post_thumbnail($this->current_post_id)) {
                $featured_image_url = get_the_post_thumbnail_url($this->current_post_id, 'large');
                if ($featured_image_url) {
                    return $featured_image_url;
                }
            }
        }

        // Check Social Meta Manager settings for Twitter-specific default image
        if ($this->social_manager) {
            $social_context = $this->current_context === 'homepage' ? 'site' : $this->current_context;
            $social_settings = $this->social_manager->get_settings($social_context, $this->current_post_id);

            // Prioritize Twitter-specific default image
            if (!empty($social_settings['default_twitter_image'])) {
                return $social_settings['default_twitter_image'];
            }

            // Fallback to Open Graph default image
            if (!empty($social_settings['default_og_image'])) {
                return $social_settings['default_og_image'];
            }

            // Final fallback to generic default image
            if (!empty($social_settings['default_image'])) {
                return $social_settings['default_image'];
            }
        }

        // Check Site Identity data for social images
        if ($this->site_identity_data && !empty($this->site_identity_data['social'])) {
            $social_data = $this->site_identity_data['social'];

            // Check for any configured social image
            if (!empty($social_data['default_image'])) {
                return $social_data['default_image'];
            }
        }

        return null;
    }
}
