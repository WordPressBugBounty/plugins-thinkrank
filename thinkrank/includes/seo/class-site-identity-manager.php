<?php

/**
 * Site Identity Manager Class
 *
 * Comprehensive site identity management with title formats, separators,
 * breadcrumb navigation, robots.txt management, and site identity optimization.
 * Implements 2025 SEO best practices with real industry-standard algorithms.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Ensure dependencies are loaded
if (!class_exists('ThinkRank\\SEO\\Abstract_SEO_Manager')) {
    require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-abstract-seo-manager.php';
}

if (!interface_exists('ThinkRank\\SEO\\Interfaces\\SEO_Manager_Interface')) {
    require_once THINKRANK_PLUGIN_DIR . 'includes/seo/interfaces/class-seo-manager-interface.php';
}

/**
 * Site Identity Manager Class
 *
 * Manages all aspects of site identity including title formats, breadcrumbs,
 * robots.txt, and global SEO settings with context-aware optimization.
 *
 * @since 1.0.0
 */
class Site_Identity_Manager extends Abstract_SEO_Manager {

    /**
     * WordPress filesystem instance
     *
     * @since 1.0.0
     * @var \WP_Filesystem_Base|null
     */
    private $filesystem = null;

    /**
     * Title format templates with dynamic placeholders
     *
     * @since 1.0.0
     * @var array
     */
    private array $title_templates = [
        'default' => '%title% %separator% %sitename%',
        'reverse' => '%sitename% %separator% %title%',
        'title_only' => '%title%',
        'sitename_only' => '%sitename%',
        'custom' => '%title% %separator% %sitename% %separator% %tagline%',
        'category' => '%title% %separator% %category% %separator% %sitename%',
        'author' => '%title% %separator% %author% %separator% %sitename%',
        'date' => '%title% %separator% %date% %separator% %sitename%',
        'search' => 'Search Results for "%searchterm%" %separator% %sitename%',
        '404' => 'Page Not Found %separator% %sitename%'
    ];

    /**
     * Available title separators with their specifications
     *
     * @since 1.0.0
     * @var array
     */
    public static array $title_separators = [
        'pipe' => [
            'symbol' => '|',
            'name' => 'Pipe',
            'description' => 'Vertical bar separator (most common)',
            'seo_score' => 10
        ],
        'dash' => [
            'symbol' => '-',
            'name' => 'Dash',
            'description' => 'Hyphen separator (clean and readable)',
            'seo_score' => 9
        ],
        'bullet' => [
            'symbol' => '•',
            'name' => 'Bullet',
            'description' => 'Bullet point separator (modern)',
            'seo_score' => 8
        ],
        'colon' => [
            'symbol' => ':',
            'name' => 'Colon',
            'description' => 'Colon separator (formal)',
            'seo_score' => 7
        ],
        'greater' => [
            'symbol' => '>',
            'name' => 'Greater Than',
            'description' => 'Arrow-like separator (hierarchical)',
            'seo_score' => 6
        ],
        'tilde' => [
            'symbol' => '~',
            'name' => 'Tilde',
            'description' => 'Wave separator (unique)',
            'seo_score' => 5
        ]
    ];

    /**
     * Get the currently active title separator symbol
     *
     * @since 1.0.0
     * @return string Separator symbol
     */
    public static function get_active_separator_symbol(): string {
        $manager = new self();
        $settings = $manager->get_settings('site');
        $separator_key = $settings['title_separator'] ?? 'pipe';

        return self::$title_separators[$separator_key]['symbol'] ?? '|';
    }

    /**
     * Breadcrumb types and their configurations
     *
     * @since 1.0.0
     * @var array
     */
    private array $breadcrumb_types = [
        'hierarchical' => [
            'name' => 'Hierarchical',
            'description' => 'Based on page hierarchy and categories',
            'schema_type' => 'BreadcrumbList',
            'seo_value' => 10
        ],
        'taxonomy' => [
            'name' => 'Taxonomy-based',
            'description' => 'Based on post categories and tags',
            'schema_type' => 'BreadcrumbList',
            'seo_value' => 9
        ],
        'path' => [
            'name' => 'URL Path',
            'description' => 'Based on URL structure',
            'schema_type' => 'BreadcrumbList',
            'seo_value' => 8
        ],
        'custom' => [
            'name' => 'Custom',
            'description' => 'Manually defined breadcrumb structure',
            'schema_type' => 'BreadcrumbList',
            'seo_value' => 7
        ]
    ];

    /**
     * Robots.txt directives and their specifications
     *
     * @since 1.0.0
     * @var array
     */
    private array $robots_directives = [
        'user_agent' => [
            'required' => true,
            'description' => 'Specifies which web crawler the rules apply to',
            'examples' => ['*', 'Googlebot', 'Bingbot', 'Yandexbot']
        ],
        'disallow' => [
            'required' => false,
            'description' => 'Specifies paths that should not be crawled',
            'examples' => ['/admin/', '/wp-admin/', '/wp-includes/', '/private/']
        ],
        'allow' => [
            'required' => false,
            'description' => 'Specifies paths that should be crawled (overrides disallow)',
            'examples' => ['/wp-admin/admin-ajax.php', '/wp-content/uploads/']
        ],
        'sitemap' => [
            'required' => false,
            'description' => 'Specifies the location of XML sitemaps',
            'examples' => ['/sitemap.xml', '/sitemap_index.xml']
        ],
        'crawl_delay' => [
            'required' => false,
            'description' => 'Specifies delay between requests (in seconds)',
            'examples' => ['1', '5', '10']
        ]
    ];

    /**
     * Site identity elements configuration
     *
     * @since 1.0.0
     * @var array
     */
    private array $identity_elements = [
        'logo' => [
            'type' => 'image',
            'required' => false,
            'description' => 'Site logo for branding and schema markup',
            'recommended_size' => '600x60',
            'max_size' => '2MB'
        ],
        'favicon' => [
            'type' => 'image',
            'required' => false,
            'description' => 'Site favicon for browser tabs',
            'recommended_size' => '32x32',
            'formats' => ['ico', 'png']
        ],
        'apple_touch_icon' => [
            'type' => 'image',
            'required' => false,
            'description' => 'Apple touch icon for iOS devices',
            'recommended_size' => '180x180',
            'format' => 'png'
        ],
        'site_name' => [
            'type' => 'text',
            'required' => true,
            'description' => 'Official site name for branding',
            'max_length' => 60
        ],
        'tagline' => [
            'type' => 'text',
            'required' => false,
            'description' => 'Site tagline or slogan',
            'max_length' => 160
        ],
        'description' => [
            'type' => 'text',
            'required' => false,
            'description' => 'Site description for meta tags',
            'max_length' => 160
        ]
    ];

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('site_identity');
    }

    /**
     * Initialize WordPress filesystem
     *
     * @since 1.0.0
     * @return bool True if filesystem is initialized, false otherwise
     */
    private function init_filesystem(): bool {
        if ($this->filesystem !== null) {
            return true;
        }

        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $credentials = request_filesystem_credentials('', '', false, false, null);
        if (!WP_Filesystem($credentials)) {
            return false;
        }

        $this->filesystem = $wp_filesystem;
        return true;
    }

    /**
     * Check if directory is writable using WP_Filesystem
     *
     * @since 1.0.0
     * @param string $path Directory path to check
     * @return bool True if writable, false otherwise
     */
    private function is_directory_writable(string $path): bool {
        if (!$this->init_filesystem()) {
            return false;
        }

        return $this->filesystem->is_writable($path);
    }

    /**
     * Check if file is writable using WP_Filesystem
     *
     * @since 1.0.0
     * @param string $file File path to check
     * @return bool True if writable, false otherwise
     */
    private function is_file_writable(string $file): bool {
        if (!$this->init_filesystem()) {
            return false;
        }

        return $this->filesystem->is_writable($file);
    }
    public function generate_title(string $template_name = 'default', array $data = [], string $context = 'site'): string {
        // Get template
        $template = $this->title_templates[$template_name] ?? $this->title_templates['default'];

        // Get site settings
        $settings = $this->get_settings('site');
        $separator = $this->get_title_separator($settings['title_separator'] ?? 'pipe');

        // Prepare placeholder data
        $placeholders = $this->prepare_title_placeholders($data, $context, $settings);

        // Replace placeholders
        $title = $this->replace_title_placeholders($template, $placeholders, $separator);

        // Clean and optimize title
        $title = $this->optimize_title($title, $context);

        return $title;
    }

    /**
     * Generate breadcrumb navigation with schema markup
     *
     * @since 1.0.0
     *
     * @param string $type    Breadcrumb type
     * @param array  $options Breadcrumb options
     * @return array Breadcrumb data with schema markup
     */
    public function generate_breadcrumbs(string $type = 'hierarchical', array $options = []): array {
        $breadcrumbs = [
            'items' => [],
            'schema' => [],
            'html' => '',
            'type' => $type,
            'count' => 0
        ];

        // Get breadcrumb settings
        $settings = $this->get_settings('site');
        $breadcrumb_settings = $settings['breadcrumbs'] ?? [];

        // Generate breadcrumb items based on type
        switch ($type) {
            case 'hierarchical':
                $breadcrumbs['items'] = $this->generate_hierarchical_breadcrumbs($options);
                break;
            case 'taxonomy':
                $breadcrumbs['items'] = $this->generate_taxonomy_breadcrumbs($options);
                break;
            case 'path':
                $breadcrumbs['items'] = $this->generate_path_breadcrumbs($options);
                break;
            case 'custom':
                $breadcrumbs['items'] = $this->generate_custom_breadcrumbs($options);
                break;
        }

        // Generate schema markup
        $breadcrumbs['schema'] = $this->generate_breadcrumb_schema($breadcrumbs['items']);

        // Generate HTML output
        $breadcrumbs['html'] = $this->generate_breadcrumb_html($breadcrumbs['items'], $breadcrumb_settings);

        // Set count
        $breadcrumbs['count'] = count($breadcrumbs['items']);

        return $breadcrumbs;
    }

    /**
     * Generate and manage robots.txt content
     *
     * @since 1.0.0
     *
     * @param array $custom_rules Optional custom rules to add
     * @return array Robots.txt data and validation
     */
    public function generate_robots_txt(array $custom_rules = []): array {
        $robots_data = [
            'content' => '',
            'rules' => [],
            'validation' => [],
            'file_exists' => false,
            'writable' => false
        ];

        // Check if robots.txt file exists and is writable
        $robots_file = ABSPATH . 'robots.txt';
        $robots_data['file_exists'] = file_exists($robots_file);
        $robots_data['writable'] = $this->is_directory_writable(dirname($robots_file));

        // Get site settings
        $settings = $this->get_settings('site');

        // Generate default rules (pass full settings so sitemap_url is available)
        $default_rules = $this->generate_default_robots_rules($settings);

        // Merge with custom rules
        $all_rules = array_merge($default_rules, $custom_rules);

        // Validate rules
        $robots_data['validation'] = $this->validate_robots_rules($all_rules);

        // Generate robots.txt content
        $robots_data['content'] = $this->build_robots_txt_content($all_rules);
        $robots_data['rules'] = $all_rules;

        return $robots_data;
    }

    /**
     * Resolve the robots.txt that should actually be served.
     *
     * The Robots.txt textarea (`robots_txt_content`) is the source of truth the
     * admin sees and edits; per the UI, an empty value means "auto-generate".
     * Both the virtual `robots_txt` filter and the physical file are rendered
     * through here so what is served always matches what the textarea shows —
     * previously the served output was regenerated from rules and silently
     * ignored any manual edit.
     *
     * @since 1.20.0
     * @return string Robots.txt body, always newline-terminated.
     */
    public function render_robots_txt(): string {
        $settings = $this->get_settings('site');

        // A site-wide crawl block — "Allow Search Engines" off, or WordPress's
        // "Discourage search engines" (Settings → Reading, blog_public=0) — must
        // win over any custom robots.txt content. Otherwise a stored override
        // that permits crawling would silently defeat the block on every serving
        // and persistence path. When blocked, force the generated output, which
        // resolves to `User-agent: * / Disallow: /` via generate_default_robots_rules().
        $allow_search  = $settings['allow_search_engines'] ?? true;
        $fully_blocked = empty($allow_search) || !get_option('blog_public');

        $custom = trim((string) ($settings['robots_txt_content'] ?? ''));
        // A user edit may still carry the old header if it was stored before the
        // header/body split — strip it so we don't emit two headers.
        $body = ($custom !== '' && !$fully_blocked)
            ? $this->strip_robots_header($custom)
            : trim($this->generate_robots_txt()['content']);

        if ($body === '') {
            return '';
        }

        return $this->robots_txt_header() . $body . "\n";
    }

    /**
     * Resolve the robots.txt actually served to crawlers, with its origin.
     *
     * Lets an API/MCP consumer see the effective output without crawling the
     * URL. Mirrors serving precedence: a physical robots.txt in the web root is
     * served verbatim by the web server; otherwise the rendered content (custom
     * override or generated defaults) is served through the `robots_txt` filter.
     *
     * @since 1.20.0
     * @return array{content: string, is_default: bool, source: string} Effective
     *         robots.txt, whether it is ThinkRank's generated default (vs. a
     *         custom override), and where it originates from.
     */
    public function get_effective_robots_txt(): array {
        // A real file in the web root wins — the web server serves it directly.
        $robots_file = ABSPATH . 'robots.txt';
        if (file_exists($robots_file) && is_readable($robots_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a public web-root file; WP_Filesystem is not available on front-end requests.
            return [
                'content' => (string) file_get_contents($robots_file),
                'is_default' => false,
                'source' => 'file',
            ];
        }

        $settings = $this->get_settings('site');

        // Management disabled — WordPress serves its own core default.
        if (empty($settings['robots_txt_enabled'])) {
            return [
                'content' => '',
                'is_default' => true,
                'source' => 'wordpress',
            ];
        }

        // A non-empty stored override replaces the generated defaults.
        $custom = trim((string) ($settings['robots_txt_content'] ?? ''));

        return [
            'content' => $this->render_robots_txt(),
            'is_default' => $custom === '',
            'source' => $custom === '' ? 'generated' : 'custom',
        ];
    }

    /**
     * Keep the physical robots.txt file in step with the saved settings.
     *
     * When management is enabled the physical file is the source of truth the
     * web server serves, so this makes sure it exists and matches the effective
     * content — creating it if missing. When management is disabled it removes
     * any existing file so WordPress serves its default again. Callers invoke
     * this after saving robots settings so a plain Save both creates and
     * refreshes the file without a separate "Generate" step.
     *
     * @since 1.20.0
     * @return bool True if the file was written or removed as intended.
     */
    public function sync_robots_txt_file(): bool {
        $robots_file = ABSPATH . 'robots.txt';
        $settings = $this->get_settings('site');

        // Management turned off: drop any existing file so WordPress serves its
        // default again, rather than leaving a stale ThinkRank file behind.
        if (empty($settings['robots_txt_enabled'])) {
            if (file_exists($robots_file) && $this->init_filesystem()) {
                $this->filesystem->delete($robots_file);
            }
            return true;
        }

        $content = $this->render_robots_txt();
        if ($content === '') {
            return false;
        }

        // Run the effective content through the standard robots_txt filter so
        // lines added by other integrations (ThinkRank Pro's News/Video
        // Publisher Sitemaps at priority 999, and any third-party plugin) are
        // baked into the physical file. A physical robots.txt bypasses core's
        // do_robots()/robots_txt filter entirely, so without this those lines
        // are silently dropped. ThinkRank's own filter_robots_txt callback just
        // re-returns this same content (it calls render_robots_txt(), which does
        // not re-apply the filter), so there is no recursion or double-append.
        $content = (string) apply_filters('robots_txt', $content, (bool) get_option('blog_public'));
        if ($content === '') {
            return false;
        }

        // write_robots_txt() creates the file when absent and overwrites it
        // otherwise, so this covers both first-time creation and re-sync.
        $result = $this->write_robots_txt($content);
        return !empty($result['success']);
    }

    /**
     * Write robots.txt content to filesystem
     *
     * @since 1.0.0
     *
     * @param string $content Robots.txt content to write
     * @return array Write operation result
     */
    public function write_robots_txt(string $content): array {
        $result = [
            'success' => false,
            'message' => '',
            'file_path' => '',
            'permissions' => []
        ];

        $robots_file = ABSPATH . 'robots.txt';

        // Security: Validate file path to prevent path traversal attacks
        $real_robots_file = realpath(dirname($robots_file)) . DIRECTORY_SEPARATOR . basename($robots_file);
        $allowed_dir = realpath(ABSPATH);

        if (!$allowed_dir || strpos(dirname($real_robots_file), $allowed_dir) !== 0) {
            $result['message'] = 'Invalid file path detected for security reasons.';
            return $result;
        }

        $result['file_path'] = $robots_file;

        // Check directory permissions
        $result['permissions'] = [
            'directory_writable' => $this->is_directory_writable(ABSPATH),
            'file_exists' => file_exists($robots_file),
            'file_writable' => file_exists($robots_file) ? $this->is_file_writable($robots_file) : null
        ];

        // Check if we can write to the directory
        if (!$result['permissions']['directory_writable']) {
            $result['message'] = 'WordPress root directory is not writable. Please check file permissions.';
            return $result;
        }

        // Check if existing file is writable (if it exists)
        if ($result['permissions']['file_exists'] && !$result['permissions']['file_writable']) {
            $result['message'] = 'Existing robots.txt file is not writable. Please check file permissions.';
            return $result;
        }

        try {
            // Write new content using WP_Filesystem
            if (!$this->init_filesystem()) {
                $result['message'] = 'Could not initialize WordPress filesystem.';
                return $result;
            }

            $write_success = $this->filesystem->put_contents($robots_file, $content, FS_CHMOD_FILE);

            if ($write_success) {
                $result['success'] = true;
                $result['message'] = 'Robots.txt file written successfully.';
                $result['bytes_written'] = strlen($content);
            } else {
                $result['message'] = 'Failed to write robots.txt file.';
            }
        } catch (\Exception $e) {
            $result['message'] = 'Error writing robots.txt file: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Optimize site identity data with comprehensive analysis
     *
     * @since 1.0.0
     *
     * @param array $identity_data Site identity data to optimize
     * @param array $options Optimization options including section focus
     * @return array Optimized identity data with validation
     */
    public function optimize_site_identity(array $identity_data, array $options = []): array {
        $optimization = [
            'optimized_data' => [],
            'validation' => [],
            'suggestions' => [],
            'warnings' => [],
            'improvements' => [],
            'score' => 0,
            'section_scores' => []
        ];

        // Determine optimization focus
        $focus = $options['focus'] ?? 'all';

        // Section-specific optimization
        if ($focus === 'title_formats' || $focus === 'all') {
            $title_optimization = $this->optimize_title_formats($identity_data);
            $optimization['section_scores']['title_formats'] = $title_optimization['score'];
            $optimization['suggestions'] = array_merge($optimization['suggestions'], $title_optimization['suggestions']);
            $optimization['warnings'] = array_merge($optimization['warnings'], $title_optimization['warnings']);
        }

        if ($focus === 'breadcrumbs' || $focus === 'all') {
            $breadcrumb_optimization = $this->optimize_breadcrumbs($identity_data);
            $optimization['section_scores']['breadcrumbs'] = $breadcrumb_optimization['score'];
            $optimization['suggestions'] = array_merge($optimization['suggestions'], $breadcrumb_optimization['suggestions']);
            $optimization['warnings'] = array_merge($optimization['warnings'], $breadcrumb_optimization['warnings']);
        }

        if ($focus === 'robots_txt' || $focus === 'all') {
            $robots_optimization = $this->optimize_robots_txt($identity_data);
            $optimization['section_scores']['robots_txt'] = $robots_optimization['score'];
            $optimization['suggestions'] = array_merge($optimization['suggestions'], $robots_optimization['suggestions']);
            $optimization['warnings'] = array_merge($optimization['warnings'], $robots_optimization['warnings']);
        }

        if ($focus === 'site_assets' || $focus === 'all') {
            $assets_optimization = $this->optimize_site_assets($identity_data);
            $optimization['section_scores']['site_assets'] = $assets_optimization['score'];
            $optimization['suggestions'] = array_merge($optimization['suggestions'], $assets_optimization['suggestions']);
            $optimization['warnings'] = array_merge($optimization['warnings'], $assets_optimization['warnings']);
        }

        // Legacy element-by-element optimization for basic site info
        if ($focus === 'site_info' || $focus === 'all') {
            foreach ($this->identity_elements as $element => $config) {
                if (isset($identity_data[$element])) {
                    $element_optimization = $this->optimize_identity_element(
                        $element,
                        $identity_data[$element],
                        $config
                    );

                    $optimization['optimized_data'][$element] = $element_optimization['optimized_value'];
                    $optimization['validation'][$element] = $element_optimization['validation'];
                    $optimization['suggestions'] = array_merge(
                        $optimization['suggestions'],
                        $element_optimization['suggestions']
                    );
                }
            }
        }

        // Calculate overall optimization score
        if (!empty($optimization['section_scores'])) {
            $optimization['score'] = (int) round(array_sum($optimization['section_scores']) / count($optimization['section_scores']));
        } else {
            $optimization['score'] = $this->calculate_identity_score($optimization['validation']);
        }

        // Store optimization results in seo_analysis table
        $this->store_optimization_results($optimization, $focus);

        return $optimization;
    }

    /**
     * Optimize title formats with enhanced rules
     *
     * @since 1.0.0
     *
     * @param array $settings Title format settings
     * @return array Optimization results
     */
    public function optimize_title_formats(array $settings): array {
        $optimization = [
            'score' => 100,
            'suggestions' => [],
            'warnings' => [],
            'improvements' => []
        ];

        // Check separator choice (applies to every context template).
        $separator = $settings['title_separator'] ?? 'pipe';
        $separator_data = self::$title_separators[$separator] ?? null;
        if ($separator_data) {
            $seo_score = $separator_data['seo_score'] ?? 5;
            if ($seo_score < 8) {
                $optimization['suggestions'][] = "Consider using '|' or '-' separators for better SEO performance";
                $optimization['score'] -= (10 - $seo_score);
            }
        }

        // Analyze the per-context templates the Title Formats UI actually edits
        // and the front end actually renders — not the legacy `title_template`
        // enum, which this screen never sets.
        $context_labels = [
            'homepage_title' => 'Homepage',
            'post_title'     => 'Post',
            'page_title'     => 'Page',
            'category_title' => 'Category',
            'tag_title'      => 'Tag',
            'author_title'   => 'Author',
            'search_title'   => 'Search',
            'archive_title'  => 'Archive',
        ];

        $configured = 0;
        foreach ($context_labels as $key => $label) {
            $template = isset($settings[$key]) ? trim((string) $settings[$key]) : '';
            if ($template === '') {
                continue; // Unconfigured — the front end falls back for this context.
            }
            $configured++;

            // Brand recognition: the title should carry the site name.
            if (strpos($template, '%site_title%') === false && strpos($template, '%site_name%') === false) {
                $optimization['suggestions'][] = "{$label} title has no site name — add %site_title% for brand recognition";
                $optimization['score'] -= 5;
            }

            // Length check against the ~60-char guideline, measured on the
            // resolved title for THIS context (with representative sample data).
            $sample_length = strlen($this->generate_sample_title($settings, $key));
            if ($sample_length > 60) {
                $optimization['warnings'][] = "{$label} title renders about {$sample_length} characters (over the 60-character limit)";
                $optimization['score'] -= 10;
            } elseif ($sample_length > 0 && $sample_length < 20) {
                $optimization['suggestions'][] = "{$label} title renders only about {$sample_length} characters — consider adding more context";
                $optimization['score'] -= 3;
            }
        }

        // No context templates set at all — ThinkRank won't control any titles.
        if ($configured === 0) {
            $optimization['suggestions'][] = 'No title formats are configured — set templates so ThinkRank controls your page titles';
            $optimization['score'] -= 10;
        }

        $optimization['score'] = max(0, min(100, $optimization['score']));

        return $optimization;
    }

    /**
     * Optimize breadcrumb settings with UX best practices
     *
     * @since 1.0.0
     *
     * @param array $settings Breadcrumb settings
     * @return array Optimization results
     */
    public function optimize_breadcrumbs(array $settings): array {
        $optimization = [
            'score' => 100,
            'suggestions' => [],
            'warnings' => [],
            'improvements' => []
        ];

        // Check if breadcrumbs are enabled
        if (!($settings['breadcrumbs_enabled'] ?? true)) {
            $optimization['suggestions'][] = 'Enable breadcrumbs to improve user navigation and SEO (recommended by Google)';
            $optimization['score'] = 20; // Major penalty for disabled breadcrumbs
            return $optimization;
        }

        // Validate breadcrumb type
        $type = $settings['breadcrumb_type'] ?? 'hierarchical';
        $type_scores = [
            'hierarchical' => 100,
            'category_based' => 90,
            'simple' => 70,
            'custom' => 80
        ];

        $type_score = $type_scores[$type] ?? 60;
        $optimization['score'] = min($optimization['score'], $type_score);

        if ($type === 'simple') {
            $optimization['suggestions'][] = 'Consider hierarchical breadcrumbs for better site structure representation';
        }

        // Validate separator choice
        $separator = $settings['breadcrumb_separator'] ?? '›';
        $separator_ux = [
            '›' => ['score' => 100, 'note' => 'Clear directional indicator'],
            '>' => ['score' => 95, 'note' => 'Simple and effective'],
            '/' => ['score' => 85, 'note' => 'Familiar but can confuse with URLs'],
            '|' => ['score' => 75, 'note' => 'Less intuitive for navigation'],
            '»' => ['score' => 90, 'note' => 'Distinctive double arrow']
        ];

        $sep_data = $separator_ux[$separator] ?? ['score' => 50, 'note' => 'Unusual choice'];
        $optimization['score'] = min($optimization['score'], $sep_data['score']);

        if ($sep_data['score'] < 95) {
            $optimization['suggestions'][] = "Separator '{$separator}': {$sep_data['note']}";
        }

        // Validate home text
        $home_text = $settings['breadcrumb_home_text'] ?? 'Home';
        if (empty($home_text)) {
            $optimization['warnings'][] = 'Empty home text reduces accessibility for screen readers';
            $optimization['score'] -= 15;
        } elseif (strlen($home_text) > 20) {
            $optimization['suggestions'][] = 'Keep home text concise (current: ' . strlen($home_text) . ' chars)';
            $optimization['score'] -= 5;
        }

        // Check prefix usage
        $prefix = $settings['breadcrumb_prefix'] ?? '';
        if (!empty($prefix) && strlen($prefix) > 50) {
            $optimization['suggestions'][] = 'Breadcrumb prefix is quite long (' . strlen($prefix) . ' chars) - consider shortening';
            $optimization['score'] -= 5;
        }

        // Current page display
        if (!($settings['show_current_page'] ?? true)) {
            $optimization['suggestions'][] = 'Show current page in breadcrumbs for better user orientation';
            $optimization['score'] -= 10;
        }

        return $optimization;
    }

    /**
     * Optimize robots.txt settings with technical SEO best practices
     *
     * @since 1.0.0
     *
     * @param array $settings Robots.txt settings
     * @return array Optimization results
     */
    public function optimize_robots_txt(array $settings): array {
        $optimization = [
            'score' => 100,
            'suggestions' => [],
            'warnings' => [],
            'improvements' => []
        ];

        // Check if robots.txt management is enabled
        if (!($settings['robots_txt_enabled'] ?? true)) {
            $optimization['suggestions'][] = 'Enable robots.txt management for better SEO control and automated updates';
            $optimization['score'] = 30;
            return $optimization;
        }

        // Critical: Search engine access
        if (!($settings['allow_search_engines'] ?? true)) {
            $optimization['warnings'][] = 'CRITICAL: Search engines are blocked - your site will not be indexed by Google, Bing, etc.';
            $optimization['score'] = 10; // Severe penalty
        }

        // Sitemap URL validation (now from sitemap settings)
        $sitemap_urls = $this->get_sitemap_urls_for_robots();
        if (empty($sitemap_urls)) {
            $optimization['suggestions'][] = 'Enable sitemap generation to include sitemap URLs in robots.txt';
            $optimization['score'] -= 15;
        } else {
            // Validate first sitemap accessibility (representative check)
            $first_sitemap = $sitemap_urls[0];
            $sitemap_response = wp_remote_head($first_sitemap, ['timeout' => 10]);
            if (is_wp_error($sitemap_response) || wp_remote_retrieve_response_code($sitemap_response) !== 200) {
                $optimization['warnings'][] = 'Primary sitemap URL is not accessible - check sitemap generation';
                $optimization['score'] -= 10;
            }
        }

        // File system permissions
        $robots_file = ABSPATH . 'robots.txt';
        $robots_dir = dirname($robots_file);

        if (!$this->is_directory_writable($robots_dir)) {
            $optimization['warnings'][] = 'WordPress root directory is not writable - robots.txt cannot be managed automatically';
            $optimization['score'] -= 15;
        } elseif (file_exists($robots_file) && !$this->is_file_writable($robots_file)) {
            $optimization['warnings'][] = 'Existing robots.txt file is not writable - cannot update automatically';
            $optimization['score'] -= 10;
        }

        // Content analysis
        $custom_content = $settings['robots_txt_content'] ?? '';
        if (!empty($custom_content)) {
            // Check for dangerous patterns
            if (preg_match('/User-agent:\s*\*\s*\n\s*Disallow:\s*\/\s*$/m', $custom_content)) {
                $optimization['warnings'][] = 'Blocking all content for all crawlers - this will prevent search engine indexing';
                $optimization['score'] -= 30;
            }

            // Check for sitemap declaration in content
            if (!empty($sitemap_urls) && strpos($custom_content, 'Sitemap:') === false) {
                $optimization['suggestions'][] = 'Sitemap URLs are automatically included in generated robots.txt';
                $optimization['score'] -= 5;
            }
        }

        return $optimization;
    }

    /**
     * Optimize site assets (logo, favicon, apple touch icon)
     *
     * @since 1.0.0
     *
     * @param array $settings Site assets settings
     * @return array Optimization results
     */
    public function optimize_site_assets(array $settings): array {
        $optimization = [
            'score' => 100,
            'suggestions' => [],
            'warnings' => [],
            'improvements' => []
        ];

        // Check site logo
        $logo_url = $settings['logo_url'] ?? '';
        if (empty($logo_url)) {
            $optimization['suggestions'][] = 'Add a site logo for better branding and professional appearance';
            $optimization['score'] -= 20;
        } else {
            // Validate logo URL and dimensions
            if (!filter_var($logo_url, FILTER_VALIDATE_URL)) {
                $optimization['warnings'][] = 'Logo URL format is invalid';
                $optimization['score'] -= 15;
            }
        }

        // Check favicon
        $favicon_url = $settings['favicon_url'] ?? '';
        if (empty($favicon_url)) {
            $optimization['suggestions'][] = 'Add a favicon for better browser tab identification';
            $optimization['score'] -= 15;
        }

        // Check Apple touch icon
        $apple_icon_url = $settings['apple_touch_icon_url'] ?? '';
        if (empty($apple_icon_url)) {
            $optimization['suggestions'][] = 'Add an Apple touch icon for better iOS device experience';
            $optimization['score'] -= 10;
        }

        // Additional logo analysis for local images
        if (!empty($logo_url) && filter_var($logo_url, FILTER_VALIDATE_URL)) {
            $attachment_id = attachment_url_to_postid($logo_url);
            if ($attachment_id) {
                $image_meta = wp_get_attachment_metadata($attachment_id);
                $width  = isset($image_meta['width']) ? (int) $image_meta['width'] : 0;
                $height = isset($image_meta['height']) ? (int) $image_meta['height'] : 0;

                // SVG logos store 0x0 metadata — no dimension/ratio analysis
                // is possible (and dividing by 0 is fatal).
                if ($image_meta && $width > 0 && $height > 0) {
                    if ($width < 112 || $height < 112) {
                        $optimization['warnings'][] = "Logo dimensions ({$width}x{$height}) are below recommended minimum (112x112)";
                        $optimization['score'] -= 10;
                    }

                    if ($width > 1920 || $height > 1920) {
                        $optimization['suggestions'][] = "Logo dimensions ({$width}x{$height}) are very large - consider optimizing for faster loading";
                        $optimization['score'] -= 5;
                    }

                    // Aspect ratio check
                    $ratio = $width / $height;
                    if ($ratio < 0.5 || $ratio > 2.0) {
                        $optimization['suggestions'][] = 'Logo aspect ratio should be between 1:2 and 2:1 for optimal display';
                        $optimization['score'] -= 5;
                    }
                }
            }
        }

        return $optimization;
    }

    /**
     * Optimize local SEO settings for better local search visibility
     *
     * @since 1.0.0
     *
     * @param array $settings Local SEO settings
     * @return array Optimization results
     */
    public function optimize_local_seo(array $settings): array {
        $optimization = [
            'score' => 100,
            'suggestions' => [],
            'warnings' => [],
            'improvements' => [],
            'optimized_data' => []
        ];

        // Check if local SEO is enabled
        if (empty($settings['local_seo_enabled'])) {
            $optimization['warnings'][] = 'Local SEO is disabled - enable it to improve local search visibility';
            $optimization['score'] -= 20;
            return $optimization;
        }

        // Validate business name (required for local SEO)
        if (empty($settings['business_name'])) {
            $optimization['warnings'][] = 'Business name is required for local SEO';
            $optimization['score'] -= 25;
        } else {
            // Optimize business name
            $optimized_name = $this->optimize_business_name($settings['business_name']);
            if ($optimized_name !== $settings['business_name']) {
                $optimization['optimized_data']['business_name'] = $optimized_name;
                $optimization['suggestions'][] = 'Business name optimized for better local search visibility';
            }
        }

        // Validate complete address (NAP consistency)
        $address_score = $this->validate_business_address($settings, $optimization);
        $optimization['score'] -= (100 - $address_score);

        // Validate phone number
        if (empty($settings['business_phone'])) {
            $optimization['warnings'][] = 'Business phone number is missing - important for local SEO and NAP consistency';
            $optimization['score'] -= 15;
        } else {
            $optimized_phone = $this->optimize_phone_number($settings['business_phone']);
            if ($optimized_phone !== $settings['business_phone']) {
                $optimization['optimized_data']['business_phone'] = $optimized_phone;
                $optimization['suggestions'][] = 'Phone number formatted for better consistency';
            }
        }

        // Validate business hours
        if (empty($settings['business_hours']) || !is_array($settings['business_hours'])) {
            $optimization['suggestions'][] = 'Add business hours to improve local search visibility and customer experience';
            $optimization['score'] -= 10;
        } else {
            $hours_validation = $this->validate_business_hours($settings['business_hours']);
            if (!$hours_validation['valid']) {
                $optimization['warnings'] = array_merge($optimization['warnings'], $hours_validation['warnings']);
                $optimization['score'] -= $hours_validation['penalty'];
            }
        }

        // Check for geo-coordinates
        if (empty($settings['business_latitude']) || empty($settings['business_longitude'])) {
            $optimization['suggestions'][] = 'Add latitude and longitude coordinates for precise location targeting';
            $optimization['score'] -= 10;
        } else {
            // Validate coordinates
            if (!$this->validate_coordinates($settings['business_latitude'], $settings['business_longitude'])) {
                $optimization['warnings'][] = 'Invalid latitude or longitude coordinates';
                $optimization['score'] -= 15;
            }
        }

        // Business type validation
        if (empty($settings['business_type'])) {
            $optimization['suggestions'][] = 'Select a specific business type for better schema markup';
            $optimization['score'] -= 5;
        }

        // Email validation
        if (!empty($settings['business_email']) && !is_email($settings['business_email'])) {
            $optimization['warnings'][] = 'Business email format is invalid';
            $optimization['score'] -= 10;
        }

        // Local SEO best practices
        $this->add_local_seo_best_practices($optimization, $settings);

        return $optimization;
    }

    /**
     * Optimize business name for local SEO
     *
     * @param string $business_name Original business name
     * @return string Optimized business name
     */
    private function optimize_business_name(string $business_name): string {
        // Remove excessive punctuation and normalize spacing
        $optimized = preg_replace('/[^\w\s\-&.,]/', '', $business_name);
        $optimized = preg_replace('/\s+/', ' ', $optimized);
        $optimized = trim($optimized);

        // Ensure proper capitalization
        $optimized = ucwords(strtolower($optimized));

        return $optimized;
    }

    /**
     * Validate business address components
     *
     * @param array $settings Business settings
     * @param array &$optimization Optimization results (passed by reference)
     * @return int Address completeness score (0-100)
     */
    private function validate_business_address(array $settings, array &$optimization): int {
        $score = 100;
        $required_fields = ['business_address', 'business_city', 'business_state', 'business_country'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (empty($settings[$field])) {
                $missing_fields[] = str_replace('business_', '', $field);
                $score -= 20;
            }
        }

        if (!empty($missing_fields)) {
            $optimization['warnings'][] = 'Missing address components: ' . implode(', ', $missing_fields) . ' - important for NAP consistency';
        }

        // Postal code is recommended but not required
        if (empty($settings['business_postal_code'])) {
            $optimization['suggestions'][] = 'Add postal code for more precise location targeting';
            $score -= 5;
        }

        return max(0, $score);
    }

    /**
     * Optimize phone number format for consistency
     *
     * @param string $phone_number Original phone number
     * @return string Optimized phone number
     */
    private function optimize_phone_number(string $phone_number): string {
        // Remove all non-numeric characters except + for international numbers
        $cleaned = preg_replace('/[^\d+]/', '', $phone_number);

        // If it's a US number (10 digits), format as (XXX) XXX-XXXX
        if (preg_match('/^(\d{10})$/', $cleaned, $matches)) {
            return '(' . substr($matches[1], 0, 3) . ') ' . substr($matches[1], 3, 3) . '-' . substr($matches[1], 6);
        }

        // If it's a US number with country code, format as +1 (XXX) XXX-XXXX
        if (preg_match('/^1(\d{10})$/', $cleaned, $matches)) {
            return '+1 (' . substr($matches[1], 0, 3) . ') ' . substr($matches[1], 3, 3) . '-' . substr($matches[1], 6);
        }

        // For international numbers, keep the + and return as-is
        return $cleaned;
    }

    /**
     * Validate business hours format and completeness
     *
     * @param array $business_hours Business hours array
     * @return array Validation results
     */
    private function validate_business_hours(array $business_hours): array {
        $validation = [
            'valid' => true,
            'warnings' => [],
            'penalty' => 0
        ];

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $open_days = 0;

        foreach ($days as $day) {
            if (!isset($business_hours[$day])) {
                continue;
            }

            $day_data = $business_hours[$day];

            if (empty($day_data['closed'])) {
                $open_days++;

                // Validate time format
                if (empty($day_data['open']) || empty($day_data['close'])) {
                    $validation['warnings'][] = "Missing opening or closing time for {$day}";
                    $validation['penalty'] += 2;
                } else {
                    // Validate time format (HH:MM)
                    if (!preg_match('/^\d{2}:\d{2}$/', $day_data['open']) || !preg_match('/^\d{2}:\d{2}$/', $day_data['close'])) {
                        $validation['warnings'][] = "Invalid time format for {$day} (use HH:MM format)";
                        $validation['penalty'] += 2;
                    }
                }
            }
        }

        if ($open_days === 0) {
            $validation['warnings'][] = 'No business hours specified - all days marked as closed';
            $validation['penalty'] += 10;
        }

        if ($validation['penalty'] > 0) {
            $validation['valid'] = false;
        }

        return $validation;
    }

    /**
     * Validate latitude and longitude coordinates
     *
     * @param string $latitude Latitude coordinate
     * @param string $longitude Longitude coordinate
     * @return bool True if coordinates are valid
     */
    private function validate_coordinates(string $latitude, string $longitude): bool {
        $lat = floatval($latitude);
        $lng = floatval($longitude);

        // Validate latitude range (-90 to 90)
        if ($lat < -90 || $lat > 90) {
            return false;
        }

        // Validate longitude range (-180 to 180)
        if ($lng < -180 || $lng > 180) {
            return false;
        }

        return true;
    }

    /**
     * Add local SEO best practices suggestions
     *
     * @param array &$optimization Optimization results (passed by reference)
     * @param array $settings Business settings
     * @return void
     */
    private function add_local_seo_best_practices(array &$optimization, array $settings): void {
        // Check for Google My Business integration
        if (empty($settings['google_my_business_url'])) {
            $optimization['suggestions'][] = 'Consider adding your Google My Business profile URL for better local visibility';
        }

        // Check for social media profiles
        $social_platforms = ['facebook_url', 'twitter_url', 'instagram_url', 'linkedin_url'];
        $has_social = false;
        foreach ($social_platforms as $platform) {
            if (!empty($settings[$platform])) {
                $has_social = true;
                break;
            }
        }

        if (!$has_social) {
            $optimization['suggestions'][] = 'Add social media profiles to improve local business credibility';
        }

        // Check for business description
        if (empty($settings['business_description'])) {
            $optimization['suggestions'][] = 'Add a business description for better context in local search results';
        }

        // Service area suggestions
        if (empty($settings['service_areas'])) {
            $optimization['suggestions'][] = 'Define service areas if your business serves multiple locations';
        }
    }

    /**
     * Generate a sample rendered title for a given context template, so the
     * optimizer can measure the length users will actually see.
     *
     * Resolves the per-context template (e.g. `post_title`) with representative
     * sample values for the same variable tokens the front-end renderer fills
     * in (see SEO_Manager::get_title_placeholders()).
     *
     * @since 1.0.0
     *
     * @param array  $settings    Title format settings
     * @param string $context_key Per-context template key (e.g. 'post_title')
     * @return string Resolved sample title (empty string when the template is unset)
     */
    private function generate_sample_title(array $settings, string $context_key = 'post_title'): string {
        $template = isset($settings[$context_key]) ? trim((string) $settings[$context_key]) : '';
        if ($template === '') {
            return '';
        }

        $separator = $settings['title_separator'] ?? 'pipe';
        $separator_symbol = self::$title_separators[$separator]['symbol'] ?? '|';

        $site_name = $settings['site_name'] ?? '';
        if ($site_name === '') {
            $site_name = get_bloginfo('name') ?: 'Your Site Name';
        }
        $site_description = $settings['site_description'] ?? '';
        if ($site_description === '') {
            $site_description = get_bloginfo('description') ?: 'Your Site Description';
        }
        $tagline = $settings['tagline'] ?? '';
        if ($tagline === '') {
            $tagline = $site_description;
        }

        // Representative sample values for the variable tokens the front end
        // substitutes per request. Keys mirror get_title_placeholders().
        $sample_data = [
            '%site_title%'       => $site_name,
            '%site_name%'        => $site_name,
            '%site_description%' => $site_description,
            '%tagline%'          => $tagline,
            '%sep%'              => ' ' . $separator_symbol . ' ',
            '%separator%'        => ' ' . $separator_symbol . ' ',
            '%post_title%'       => 'How to Optimize Your Website for Better SEO Results',
            '%page_title%'       => 'About Our Company',
            '%category_title%'   => 'SEO Tips',
            '%category%'         => 'SEO Tips',
            '%tag_title%'        => 'On-Page SEO',
            '%tag%'              => 'On-Page SEO',
            '%author_name%'      => 'Jane Doe',
            '%author%'           => 'Jane Doe',
            '%search_term%'      => 'keyword research',
            '%search_phrase%'    => 'keyword research',
            '%archive_title%'    => 'July 2026',
            '%date%'             => gmdate('F Y'),
        ];

        $title = str_replace(array_keys($sample_data), array_values($sample_data), $template);

        // Collapse whitespace left by any empty/unresolved tokens, then trim.
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    /**
     * Store optimization results in seo_analysis table
     *
     * @since 1.0.0
     *
     * @param array $optimization Optimization results
     * @param string $focus Optimization focus section
     * @return void
     */
    private function store_optimization_results(array $optimization, string $focus): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_analysis';

        // Only store if we have meaningful results
        if (empty($optimization['suggestions']) && empty($optimization['warnings'])) {
            return;
        }

        $analysis_type = 'site_identity_rule_optimization';
        if ($focus !== 'all') {
            $analysis_type .= '_' . $focus;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Site identity analysis storage requires direct database access
        $wpdb->insert(
            $table_name,
            [
                'context_type' => 'site',
                'context_id' => null,
                'analysis_type' => $analysis_type,
                'analysis_data' => wp_json_encode($optimization),
                'score' => $optimization['score'],
                'status' => 'completed',
                'recommendations' => wp_json_encode($optimization['suggestions']),
                'validation_errors' => wp_json_encode($optimization['warnings']),
                'analyzed_by' => get_current_user_id()
            ],
            ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @param string $tab_context Optional tab context for specific validation
     * @return array Validation results
     */
    public function validate_settings(array $settings, string $tab_context = ''): array {
        // If tab context is provided, use tab-specific validation
        if (!empty($tab_context)) {
            return $this->get_tab_specific_validation($settings, $tab_context);
        }

        // Default comprehensive validation for backward compatibility
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Validate title template
        if (isset($settings['title_template'])) {
            if (!isset($this->title_templates[$settings['title_template']])) {
                $validation['errors'][] = 'Invalid title template specified';
                $validation['valid'] = false;
            }
        }

        // Validate title separator
        if (isset($settings['title_separator'])) {
            if (!isset(self::$title_separators[$settings['title_separator']])) {
                $validation['errors'][] = __('Invalid title separator specified.', 'thinkrank');
                $validation['valid'] = false;
            }
        }

        // Validate site name
        if (isset($settings['site_name'])) {
            if (empty($settings['site_name'])) {
                $validation['errors'][] = 'Site name is required';
                $validation['valid'] = false;
            } elseif (strlen($settings['site_name']) > 60) {
                $validation['warnings'][] = 'Site name is longer than 60 characters, may be truncated';
            }
        }

        // Validate site description
        if (isset($settings['site_description']) && !empty($settings['site_description'])) {
            if (strlen($settings['site_description']) > 160) {
                $validation['warnings'][] = 'Site description is longer than 160 characters, may be truncated';
            } elseif (strlen($settings['site_description']) < 120) {
                $validation['suggestions'][] = 'Consider making site description longer (120-160 characters)';
            }
        }

        // Validate logo URL
        if (isset($settings['logo_url']) && !empty($settings['logo_url'])) {
            if (!filter_var($settings['logo_url'], FILTER_VALIDATE_URL)) {
                $validation['errors'][] = 'Logo URL must be a valid URL';
                $validation['valid'] = false;
            }
        }

        // Validate breadcrumb settings
        if (isset($settings['breadcrumb_type'])) {
            if (!isset($this->breadcrumb_types[$settings['breadcrumb_type']])) {
                $validation['errors'][] = 'Invalid breadcrumb type specified';
                $validation['valid'] = false;
            }
        }

        // Validate robots.txt settings
        if (isset($settings['robots_txt_enabled']) && $settings['robots_txt_enabled']) {
            if (!$this->is_directory_writable(ABSPATH)) {
                $validation['warnings'][] = 'WordPress root directory is not writable, robots.txt cannot be automatically managed';
            }
        }

        // Validate local SEO settings if enabled
        if (isset($settings['local_seo_enabled']) && $settings['local_seo_enabled']) {
            $local_seo_validation = $this->validate_local_seo_settings($settings);
            $validation['errors'] = array_merge($validation['errors'], $local_seo_validation['errors']);
            $validation['warnings'] = array_merge($validation['warnings'], $local_seo_validation['warnings']);
            $validation['suggestions'] = array_merge($validation['suggestions'], $local_seo_validation['suggestions']);

            if (!$local_seo_validation['valid']) {
                $validation['valid'] = false;
            }
        }

        // Calculate validation score
        $validation['score'] = $this->calculate_validation_score($validation);

        // Add detailed field validation breakdown for generic validation
        $validation['field_details'] = $this->get_detailed_field_validation($settings, '');

        return $validation;
    }

    /**
     * Get tab-specific validation
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @param string $tab_context Tab context for specific validation
     * @return array Tab-specific validation results
     */
    private function get_tab_specific_validation(array $settings, string $tab_context): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Get tab-specific field details
        $field_details = $this->get_detailed_field_validation($settings, $tab_context);

        // Convert field details to validation format
        foreach ($field_details as $field) {
            switch ($field['status']) {
                case 'error':
                    $validation['errors'][] = $field['label'];
                    $validation['valid'] = false;
                    $validation['score'] -= 20;
                    break;
                case 'warning':
                    $validation['warnings'][] = $field['label'];
                    $validation['score'] -= 10;
                    break;
                case 'suggestion':
                    $validation['suggestions'][] = $field['label'];
                    $validation['score'] -= 5;
                    break;
            }
        }

        // Ensure score doesn't go below 0
        $validation['score'] = max(0, $validation['score']);

        // Add field details for frontend display
        $validation['field_details'] = $field_details;

        return $validation;
    }

    /**
     * Get detailed field validation breakdown
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @param string $tab_context Tab context for specific validation
     * @return array Detailed field validation results
     */
    private function get_detailed_field_validation(array $settings, string $tab_context = ''): array {
        $field_details = [];

        // Return tab-specific validation based on context
        switch ($tab_context) {
            case 'local-seo':
                return $this->get_business_info_validation($settings);
            case 'hero-section':
                return $this->get_hero_section_validation($settings);
            case 'title-formats':
                return $this->get_title_formats_validation($settings);
            case 'breadcrumbs':
                return $this->get_breadcrumbs_validation($settings);
            default:
                // Default basic info validation
                return $this->get_basic_info_validation($settings);
        }
    }

    /**
     * Get Business Info specific validation
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Business Info validation results
     */
    private function get_business_info_validation(array $settings): array {
        $field_details = [];

        // Check if Local SEO is enabled
        if (empty($settings['local_seo_enabled'])) {
            $field_details[] = [
                'field' => 'local_seo_enabled',
                'label' => 'Local SEO is disabled. Enable to configure business information.',
                'status' => 'warning',
                'icon' => '⚠'
            ];
            return $field_details;
        }

        // Business Name validation
        if (!empty($settings['business_name'])) {
            $field_details[] = [
                'field' => 'business_name',
                'label' => 'Business name is properly configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'business_name',
                'label' => 'Business name is required for local SEO.',
                'status' => 'error',
                'icon' => '✗'
            ];
        }

        // Business Type validation
        if (!empty($settings['business_type']) && $settings['business_type'] !== 'LocalBusiness') {
            $field_details[] = [
                'field' => 'business_type',
                'label' => 'Business type is selected for proper schema markup.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'business_type',
                'label' => 'Specific business type selection recommended for better schema markup.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Address validation (NAP consistency)
        $address_fields = ['business_address', 'business_city', 'business_state', 'business_country'];
        $address_complete = true;
        foreach ($address_fields as $field) {
            if (empty($settings[$field])) {
                $address_complete = false;
                break;
            }
        }

        if ($address_complete) {
            $field_details[] = [
                'field' => 'business_address',
                'label' => 'Complete business address is configured for NAP consistency.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'business_address',
                'label' => 'Complete address (street, city, state, country) required for local SEO.',
                'status' => 'error',
                'icon' => '✗'
            ];
        }

        // Phone validation
        if (!empty($settings['business_phone'])) {
            if ($this->validate_phone_format($settings['business_phone'])) {
                $field_details[] = [
                    'field' => 'business_phone',
                    'label' => 'Business phone number is properly formatted.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'business_phone',
                    'label' => 'Business phone number format could be improved.',
                    'status' => 'warning',
                    'icon' => '⚠'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'business_phone',
                'label' => 'Business phone number is important for local SEO and customer contact.',
                'status' => 'warning',
                'icon' => '⚠'
            ];
        }

        // Email validation
        if (!empty($settings['business_email'])) {
            if (is_email($settings['business_email'])) {
                $field_details[] = [
                    'field' => 'business_email',
                    'label' => 'Business email address is valid.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'business_email',
                    'label' => 'Business email address format is invalid.',
                    'status' => 'error',
                    'icon' => '✗'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'business_email',
                'label' => 'Business email address recommended for contact information.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Coordinates validation
        if (!empty($settings['business_latitude']) && !empty($settings['business_longitude'])) {
            if ($this->validate_coordinates($settings['business_latitude'], $settings['business_longitude'])) {
                $field_details[] = [
                    'field' => 'business_coordinates',
                    'label' => 'Business coordinates are properly configured for precise location.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'business_coordinates',
                    'label' => 'Business coordinates appear to be invalid.',
                    'status' => 'error',
                    'icon' => '✗'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'business_coordinates',
                'label' => 'Business coordinates recommended for precise location targeting.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }

    /**
     * Get Basic Info validation (default)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Basic Info validation results
     */
    private function get_basic_info_validation(array $settings): array {
        $field_details = [];

        // Site Name validation
        if (!empty($settings['site_name'])) {
            $field_details[] = [
                'field' => 'site_name',
                'label' => 'Site name is properly configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'site_name',
                'label' => 'Site name is required.',
                'status' => 'error',
                'icon' => '✗'
            ];
        }

        // Site Description validation
        if (!empty($settings['site_description'])) {
            $length = strlen($settings['site_description']);
            if ($length >= 120 && $length <= 160) {
                $field_details[] = [
                    'field' => 'site_description',
                    'label' => 'Site description is properly configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'site_description',
                    'label' => 'Site description length could be optimized (120-160 characters recommended).',
                    'status' => 'warning',
                    'icon' => '⚠'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'site_description',
                'label' => 'Site description is recommended for better SEO.',
                'status' => 'warning',
                'icon' => '⚠'
            ];
        }

        // Tagline validation
        if (!empty($settings['tagline'])) {
            $field_details[] = [
                'field' => 'tagline',
                'label' => 'Site tagline is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'tagline',
                'label' => 'Site tagline recommended for better branding.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Default Meta Description validation
        if (!empty($settings['default_meta_description'])) {
            $length = strlen($settings['default_meta_description']);
            if ($length >= 120 && $length <= 160) {
                $field_details[] = [
                    'field' => 'default_meta_description',
                    'label' => 'Default meta description is properly configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'default_meta_description',
                    'label' => 'Default meta description length could be optimized (120-160 characters recommended).',
                    'status' => 'warning',
                    'icon' => '⚠'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'default_meta_description',
                'label' => 'Default meta description recommended for pages without specific descriptions.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }

    /**
     * Get Hero Section validation
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Hero Section validation results
     */
    private function get_hero_section_validation(array $settings): array {
        $field_details = [];

        // Hero Title validation
        if (!empty($settings['hero_title'])) {
            $field_details[] = [
                'field' => 'hero_title',
                'label' => 'Hero title is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'hero_title',
                'label' => 'Hero title recommended for better homepage presentation.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Hero Subtitle validation (correct field name)
        if (!empty($settings['hero_subtitle'])) {
            $field_details[] = [
                'field' => 'hero_subtitle',
                'label' => 'Hero subtitle is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'hero_subtitle',
                'label' => 'Hero subtitle recommended for better user engagement.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // CTA Text validation
        if (!empty($settings['hero_cta_text'])) {
            $field_details[] = [
                'field' => 'hero_cta_text',
                'label' => 'Call-to-action text is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'hero_cta_text',
                'label' => 'Call-to-action text recommended for better conversion.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // CTA URL validation
        if (!empty($settings['hero_cta_url'])) {
            if (filter_var($settings['hero_cta_url'], FILTER_VALIDATE_URL) || strpos($settings['hero_cta_url'], '/') === 0) {
                $field_details[] = [
                    'field' => 'hero_cta_url',
                    'label' => 'Call-to-action URL is properly configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'hero_cta_url',
                    'label' => 'Call-to-action URL format appears invalid.',
                    'status' => 'warning',
                    'icon' => '⚠'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'hero_cta_url',
                'label' => 'Call-to-action URL recommended for better conversion.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Hero Background Image validation
        if (!empty($settings['hero_background_image'])) {
            $field_details[] = [
                'field' => 'hero_background_image',
                'label' => 'Hero background image is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'hero_background_image',
                'label' => 'Hero background image recommended for visual appeal.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Site Logo validation (from Site Assets section)
        if (!empty($settings['logo_url'])) {
            if (filter_var($settings['logo_url'], FILTER_VALIDATE_URL)) {
                $field_details[] = [
                    'field' => 'logo_url',
                    'label' => 'Site logo is properly configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'logo_url',
                    'label' => 'Site logo URL format appears invalid.',
                    'status' => 'warning',
                    'icon' => '⚠'
                ];
            }
        } else {
            $field_details[] = [
                'field' => 'logo_url',
                'label' => 'Site logo recommended for branding and schema markup.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Favicon validation
        if (!empty($settings['favicon_url'])) {
            $field_details[] = [
                'field' => 'favicon_url',
                'label' => 'Favicon is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'favicon_url',
                'label' => 'Favicon recommended for browser tab identification.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Apple Touch Icon validation
        if (!empty($settings['apple_touch_icon_url'])) {
            $field_details[] = [
                'field' => 'apple_touch_icon_url',
                'label' => 'Apple touch icon is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'apple_touch_icon_url',
                'label' => 'Apple touch icon recommended for iOS devices.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }

    /**
     * Get Title Formats validation
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Title Formats validation results
     */
    private function get_title_formats_validation(array $settings): array {
        $field_details = [];

        // Title Separator validation
        if (!empty($settings['title_separator'])) {
            $field_details[] = [
                'field' => 'title_separator',
                'label' => 'Title separator is properly configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'title_separator',
                'label' => 'Title separator is required.',
                'status' => 'error',
                'icon' => '✗'
            ];
        }

        // Homepage Title validation
        if (!empty($settings['homepage_title'])) {
            $field_details[] = [
                'field' => 'homepage_title',
                'label' => 'Homepage title format is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'homepage_title',
                'label' => 'Homepage title format recommended.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Post Title validation
        if (!empty($settings['post_title'])) {
            $field_details[] = [
                'field' => 'post_title',
                'label' => 'Post title format is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'post_title',
                'label' => 'Post title format recommended.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Page Title validation
        if (!empty($settings['page_title'])) {
            $field_details[] = [
                'field' => 'page_title',
                'label' => 'Page title format is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'page_title',
                'label' => 'Page title format recommended.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Category Title validation
        if (!empty($settings['category_title'])) {
            $field_details[] = [
                'field' => 'category_title',
                'label' => 'Category title format is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'category_title',
                'label' => 'Category title format recommended.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        // Search Title validation
        if (!empty($settings['search_title'])) {
            $field_details[] = [
                'field' => 'search_title',
                'label' => 'Search title format is configured.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'search_title',
                'label' => 'Search title format recommended.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }

    /**
     * Get Breadcrumbs validation
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Breadcrumbs validation results
     */
    private function get_breadcrumbs_validation(array $settings): array {
        $field_details = [];

        // Breadcrumbs enabled validation
        if (!empty($settings['breadcrumbs_enabled'])) {
            $field_details[] = [
                'field' => 'breadcrumbs_enabled',
                'label' => 'Breadcrumbs are enabled for better navigation.',
                'status' => 'valid',
                'icon' => '✓'
            ];

            // Only validate other fields if breadcrumbs are enabled
            // Breadcrumb Type validation
            if (!empty($settings['breadcrumb_type'])) {
                $field_details[] = [
                    'field' => 'breadcrumb_type',
                    'label' => 'Breadcrumb type is properly configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'breadcrumb_type',
                    'label' => 'Breadcrumb type selection is required.',
                    'status' => 'error',
                    'icon' => '✗'
                ];
            }

            // Home Text validation
            if (!empty($settings['breadcrumb_home_text'])) {
                $field_details[] = [
                    'field' => 'breadcrumb_home_text',
                    'label' => 'Home breadcrumb text is configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'breadcrumb_home_text',
                    'label' => 'Home breadcrumb text recommended for clarity.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

            // Breadcrumb Separator validation
            if (!empty($settings['breadcrumb_separator'])) {
                $field_details[] = [
                    'field' => 'breadcrumb_separator',
                    'label' => 'Breadcrumb separator is configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'breadcrumb_separator',
                    'label' => 'Breadcrumb separator recommended for better formatting.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

            // Breadcrumb Prefix validation (optional)
            if (!empty($settings['breadcrumb_prefix'])) {
                $field_details[] = [
                    'field' => 'breadcrumb_prefix',
                    'label' => 'Breadcrumb prefix is configured.',
                    'status' => 'valid',
                    'icon' => '✓'
                ];
            } else {
                $field_details[] = [
                    'field' => 'breadcrumb_prefix',
                    'label' => 'Breadcrumb prefix is optional but can improve user guidance.',
                    'status' => 'suggestion',
                    'icon' => '⚠'
                ];
            }

            // Show Current Page validation
            $field_details[] = [
                'field' => 'show_current_page',
                'label' => isset($settings['show_current_page']) ?
                    'Current page display preference is configured.' :
                    'Current page display preference is set to default.',
                'status' => 'valid',
                'icon' => '✓'
            ];
        } else {
            $field_details[] = [
                'field' => 'breadcrumbs_enabled',
                'label' => 'Breadcrumbs recommended for better user experience and SEO.',
                'status' => 'suggestion',
                'icon' => '⚠'
            ];
        }

        return $field_details;
    }

    /**
     * Validate local SEO settings
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Local SEO validation results
     */
    private function validate_local_seo_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => []
        ];

        // Validate business name (required for local SEO)
        if (empty($settings['business_name'])) {
            $validation['errors'][] = 'Business name is required when local SEO is enabled';
            $validation['valid'] = false;
        } elseif (strlen($settings['business_name']) > 100) {
            $validation['warnings'][] = 'Business name is very long, consider shortening for better display';
        }

        // Validate business address components (NAP consistency)
        $required_address_fields = [
            'business_address' => 'Business address',
            'business_city' => 'Business city',
            'business_state' => 'Business state/province',
            'business_country' => 'Business country'
        ];

        foreach ($required_address_fields as $field => $label) {
            if (empty($settings[$field])) {
                $validation['warnings'][] = "{$label} is missing - important for NAP consistency and local search";
            }
        }

        // Validate postal code (recommended)
        if (empty($settings['business_postal_code'])) {
            $validation['suggestions'][] = 'Add postal code for more precise location targeting';
        }

        // Validate phone number
        if (empty($settings['business_phone'])) {
            $validation['warnings'][] = 'Business phone number is missing - important for local SEO and customer contact';
        } elseif (!$this->validate_phone_format($settings['business_phone'])) {
            $validation['suggestions'][] = 'Phone number format could be improved for consistency';
        }

        // Validate email address
        if (!empty($settings['business_email']) && !is_email($settings['business_email'])) {
            $validation['errors'][] = 'Business email address format is invalid';
            $validation['valid'] = false;
        }

        // Validate coordinates if provided
        if (!empty($settings['business_latitude']) || !empty($settings['business_longitude'])) {
            if (empty($settings['business_latitude']) || empty($settings['business_longitude'])) {
                $validation['warnings'][] = 'Both latitude and longitude are required for geo-location';
            } elseif (!$this->validate_coordinates($settings['business_latitude'], $settings['business_longitude'])) {
                $validation['errors'][] = 'Invalid latitude or longitude coordinates';
                $validation['valid'] = false;
            }
        } else {
            $validation['suggestions'][] = 'Add latitude and longitude coordinates for precise location targeting';
        }

        // Validate business hours
        if (!empty($settings['business_hours']) && is_array($settings['business_hours'])) {
            $hours_validation = $this->validate_business_hours($settings['business_hours']);
            if (!$hours_validation['valid']) {
                $validation['warnings'] = array_merge($validation['warnings'], $hours_validation['warnings']);
            }
        } else {
            $validation['suggestions'][] = 'Add business hours to improve local search visibility';
        }

        // Validate business type
        if (empty($settings['business_type'])) {
            $validation['suggestions'][] = 'Select a specific business type for better schema markup';
        }

        return $validation;
    }

    /**
     * Validate phone number format
     *
     * @since 1.0.0
     *
     * @param string $phone_number Phone number to validate
     * @return bool True if format is acceptable
     */
    private function validate_phone_format(string $phone_number): bool {
        // Remove all non-numeric characters except + for international numbers
        $cleaned = preg_replace('/[^\d+]/', '', $phone_number);

        // Check for common valid formats
        return (
            preg_match('/^\d{10}$/', $cleaned) ||           // 10 digits (US)
            preg_match('/^1\d{10}$/', $cleaned) ||          // 1 + 10 digits (US with country code)
            preg_match('/^\+\d{7,15}$/', $cleaned)          // International format
        );
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

        $output = [
            'title' => '',
            'breadcrumbs' => [],
            'identity' => [],
            'robots_txt' => [],
            'enabled' => $settings['enabled'] ?? true
        ];

        if (!$output['enabled']) {
            return $output;
        }

        // Generate title for current context
        $title_data = $this->extract_title_data($context_type, $context_id);
        $output['title'] = $this->generate_title(
            $settings['title_template'] ?? 'default',
            $title_data,
            $context_type
        );

        // Generate breadcrumbs if enabled
        if (!empty($settings['breadcrumbs_enabled'])) {
            $breadcrumb_options = [
                'context_type' => $context_type,
                'context_id' => $context_id
            ];
            $output['breadcrumbs'] = $this->generate_breadcrumbs(
                $settings['breadcrumb_type'] ?? 'hierarchical',
                $breadcrumb_options
            );
        }

        // Get site identity data
        $output['identity'] = $this->get_site_identity_data($settings);

        // Get robots.txt data if enabled
        if (!empty($settings['robots_txt_enabled'])) {
            $output['robots_txt'] = $this->generate_robots_txt($settings['custom_robots_rules'] ?? []);
        }

        return $output;
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
        $defaults = [
            'enabled' => true,
            'title_template' => 'default',
            'title_separator' => 'pipe',
            'site_name' => get_bloginfo('name'),
            'site_description' => get_bloginfo('description'),
            'tagline' => get_bloginfo('description'),
            'breadcrumbs_enabled' => true,
            'breadcrumb_type' => 'hierarchical',
            'breadcrumb_home_text' => 'Home',
            'breadcrumb_separator' => '>',
            'robots_txt_enabled' => true,
            'allow_search_engines' => true,
            'robots_txt_content' => '',
            'logo_url' => '',
            'favicon_url' => '',
            'apple_touch_icon_url' => ''
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                // Site-wide defaults are already set above
                break;
            case 'post':
                $defaults['title_template'] = 'default';
                $defaults['breadcrumb_type'] = 'taxonomy';
                break;
            case 'page':
                $defaults['title_template'] = 'default';
                $defaults['breadcrumb_type'] = 'hierarchical';
                break;
            case 'product':
                $defaults['title_template'] = 'category';
                $defaults['breadcrumb_type'] = 'taxonomy';
                break;
        }

        return $defaults;
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
                'title' => 'Enable Site Identity',
                'description' => 'Enable site identity management features',
                'default' => true
            ],
            'title_template' => [
                'type' => 'string',
                'title' => 'Title Template',
                'description' => 'Template for generating page titles',
                'enum' => array_keys($this->title_templates),
                'default' => 'default'
            ],
            'title_separator' => [
                'type' => 'string',
                'title' => 'Title Separator',
                'description' => 'Character used to separate title elements',
                'enum' => array_keys(self::$title_separators),
                'default' => 'pipe'
            ],
            'site_name' => [
                'type' => 'string',
                'title' => 'Site Name',
                'description' => 'Official name of the website',
                'maxLength' => 60,
                'default' => get_bloginfo('name')
            ],
            'site_description' => [
                'type' => 'string',
                'title' => 'Site Description',
                'description' => 'Brief description of the website',
                'maxLength' => 160,
                'default' => get_bloginfo('description')
            ],
            'breadcrumbs_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Breadcrumbs',
                'description' => 'Enable breadcrumb navigation generation',
                'default' => true
            ],
            'breadcrumb_type' => [
                'type' => 'string',
                'title' => 'Breadcrumb Type',
                'description' => 'Type of breadcrumb navigation to generate',
                'enum' => array_keys($this->breadcrumb_types),
                'default' => 'hierarchical'
            ],
            'robots_txt_enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Robots.txt Management',
                'description' => 'Enable automatic robots.txt generation and management',
                'default' => true
            ],
            'logo_url' => [
                'type' => 'string',
                'title' => 'Logo URL',
                'description' => 'URL of the site logo image',
                'format' => 'uri',
                'default' => ''
            ],
            'favicon_url' => [
                'type' => 'string',
                'title' => 'Favicon URL',
                'description' => 'URL of the site favicon',
                'format' => 'uri',
                'default' => ''
            ]
        ];
    }

    /**
     * Prepare title placeholders for replacement
     *
     * @since 1.0.0
     *
     * @param array  $data     Content data
     * @param string $context  Context type
     * @param array  $settings Site settings
     * @return array Placeholder values
     */
    private function prepare_title_placeholders(array $data, string $context, array $settings): array {
        $placeholders = [
            '%title%' => $data['title'] ?? '',
            '%sitename%' => $settings['site_name'] ?? get_bloginfo('name'),
            '%tagline%' => $settings['tagline'] ?? get_bloginfo('description'),
            '%separator%' => '', // Will be replaced with actual separator
            '%category%' => '',
            '%author%' => '',
            '%date%' => '',
            '%searchterm%' => ''
        ];

        // Context-specific placeholders
        switch ($context) {
            case 'post':
            case 'page':
            case 'product':
                if (!empty($data['context_id'])) {
                    $post = get_post($data['context_id']);
                    if ($post) {
                        $placeholders['%title%'] = get_the_title($post);
                        $placeholders['%author%'] = get_the_author_meta('display_name', $post->post_author);
                        $placeholders['%date%'] = get_the_date('F j, Y', $post);

                        // Get primary category
                        $categories = get_the_category($post->ID);
                        if (!empty($categories)) {
                            $placeholders['%category%'] = $categories[0]->name;
                        }
                    }
                }
                break;
            case 'search':
                $placeholders['%searchterm%'] = get_search_query();
                break;
        }

        return $placeholders;
    }

    /**
     * Replace title placeholders with actual values
     *
     * @since 1.0.0
     *
     * @param string $template     Title template
     * @param array  $placeholders Placeholder values
     * @param string $separator    Title separator
     * @return string Processed title
     */
    private function replace_title_placeholders(string $template, array $placeholders, string $separator): string {
        // Replace separator placeholder
        $placeholders['%separator%'] = $separator;

        // Replace all placeholders
        $title = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Clean up empty placeholders and extra separators
        $title = preg_replace('/\s*' . preg_quote($separator, '/') . '\s*' . preg_quote($separator, '/') . '\s*/', ' ' . $separator . ' ', $title);
        $title = preg_replace('/^\s*' . preg_quote($separator, '/') . '\s*|\s*' . preg_quote($separator, '/') . '\s*$/', '', $title);

        return trim($title);
    }

    /**
     * Get title separator symbol
     *
     * @since 1.0.0
     *
     * @param string $separator_key Separator key
     * @return string Separator symbol
     */
    private function get_title_separator(string $separator_key): string {
        return self::$title_separators[$separator_key]['symbol'] ?? self::$title_separators['pipe']['symbol'];
    }

    /**
     * Optimize title for SEO
     *
     * @since 1.0.0
     *
     * @param string $title   Title to optimize
     * @param string $context Context type
     * @return string Optimized title
     */
    private function optimize_title(string $title, string $context): string {
        // Remove extra whitespace
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        // Ensure title is not too long (60 characters max for SEO)
        if (strlen($title) > 60) {
            // Try to truncate at word boundary
            $title = wp_trim_words($title, 8, '...');
            if (strlen($title) > 60) {
                $title = substr($title, 0, 57) . '...';
            }
        }

        // Ensure title is not empty
        if (empty($title)) {
            $title = get_bloginfo('name');
        }

        return $title;
    }

    /**
     * Extract title data from context
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return array Title data
     */
    private function extract_title_data(string $context_type, ?int $context_id): array {
        $data = [
            'title' => '',
            'context_type' => $context_type,
            'context_id' => $context_id
        ];

        switch ($context_type) {
            case 'site':
                $data['title'] = get_bloginfo('name');
                break;
            case 'post':
            case 'page':
            case 'product':
                if ($context_id) {
                    $data['title'] = get_the_title($context_id);
                }
                break;
            case 'search':
                $data['title'] = 'Search Results';
                break;
            case '404':
                $data['title'] = 'Page Not Found';
                break;
        }

        return $data;
    }

    /**
     * Generate hierarchical breadcrumbs
     *
     * @since 1.0.0
     *
     * @param array $options Breadcrumb options
     * @return array Breadcrumb items
     */
    private function generate_hierarchical_breadcrumbs(array $options): array {
        $breadcrumbs = [];

        // Add home breadcrumb
        $breadcrumbs[] = [
            'title' => 'Home',
            'url' => home_url(),
            'position' => 1
        ];

        $context_type = $options['context_type'] ?? '';
        $context_id = $options['context_id'] ?? null;

        if ($context_type === 'post' || $context_type === 'page' || $context_type === 'product') {
            if ($context_id) {
                $post = get_post($context_id);
                if ($post) {
                    // Add parent pages for hierarchical content
                    $ancestors = get_post_ancestors($post);
                    $ancestors = array_reverse($ancestors);

                    $position = 2;
                    foreach ($ancestors as $ancestor_id) {
                        $breadcrumbs[] = [
                            'title' => get_the_title($ancestor_id),
                            'url' => get_permalink($ancestor_id),
                            'position' => $position++
                        ];
                    }

                    // Add current page
                    $breadcrumbs[] = [
                        'title' => get_the_title($post),
                        'url' => get_permalink($post),
                        'position' => $position,
                        'current' => true
                    ];
                }
            }
        }

        return $breadcrumbs;
    }

    /**
     * Generate taxonomy-based breadcrumbs
     *
     * @since 1.0.0
     *
     * @param array $options Breadcrumb options
     * @return array Breadcrumb items
     */
    private function generate_taxonomy_breadcrumbs(array $options): array {
        $breadcrumbs = [];

        // Add home breadcrumb
        $breadcrumbs[] = [
            'title' => 'Home',
            'url' => home_url(),
            'position' => 1
        ];

        $context_type = $options['context_type'] ?? '';
        $context_id = $options['context_id'] ?? null;

        if (($context_type === 'post' || $context_type === 'product') && $context_id) {
            $post = get_post($context_id);
            if ($post) {
                // Get primary category
                $categories = get_the_category($post->ID);
                if (!empty($categories)) {
                    $primary_category = $categories[0];

                    // Add category hierarchy
                    $category_ancestors = get_ancestors($primary_category->term_id, 'category');
                    $category_ancestors = array_reverse($category_ancestors);

                    $position = 2;
                    foreach ($category_ancestors as $ancestor_id) {
                        $ancestor = get_category($ancestor_id);
                        $breadcrumbs[] = [
                            'title' => $ancestor->name,
                            'url' => get_category_link($ancestor_id),
                            'position' => $position++
                        ];
                    }

                    // Add primary category
                    $breadcrumbs[] = [
                        'title' => $primary_category->name,
                        'url' => get_category_link($primary_category->term_id),
                        'position' => $position++
                    ];
                }

                // Add current post
                $breadcrumbs[] = [
                    'title' => get_the_title($post),
                    'url' => get_permalink($post),
                    'position' => $position,
                    'current' => true
                ];
            }
        }

        return $breadcrumbs;
    }

    /**
     * Generate path-based breadcrumbs
     *
     * @since 1.0.0
     *
     * @param array $options Breadcrumb options
     * @return array Breadcrumb items
     */
    private function generate_path_breadcrumbs(array $options): array {
        $breadcrumbs = [];

        // Add home breadcrumb
        $breadcrumbs[] = [
            'title' => 'Home',
            'url' => home_url(),
            'position' => 1
        ];

        // Get current URL path
        $current_url = home_url(add_query_arg([]));
        $path = wp_parse_url($current_url, PHP_URL_PATH);
        $path_parts = array_filter(explode('/', trim($path, '/')));

        $position = 2;
        $cumulative_path = '';

        foreach ($path_parts as $part) {
            $cumulative_path .= '/' . $part;
            $url = home_url($cumulative_path);

            // Try to get a meaningful title
            $title = ucwords(str_replace(['-', '_'], ' ', $part));

            $breadcrumbs[] = [
                'title' => $title,
                'url' => $url,
                'position' => $position++,
                'current' => $cumulative_path === $path
            ];
        }

        return $breadcrumbs;
    }

    /**
     * Generate custom breadcrumbs
     *
     * @since 1.0.0
     *
     * @param array $options Breadcrumb options
     * @return array Breadcrumb items
     */
    private function generate_custom_breadcrumbs(array $options): array {
        // Return custom breadcrumbs if provided in options
        return $options['custom_breadcrumbs'] ?? [];
    }

    /**
     * Generate breadcrumb schema markup
     *
     * @since 1.0.0
     *
     * @param array $breadcrumb_items Breadcrumb items
     * @return array Schema markup
     */
    private function generate_breadcrumb_schema(array $breadcrumb_items): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => []
        ];

        foreach ($breadcrumb_items as $item) {
            $schema['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $item['position'],
                'name' => $item['title'],
                'item' => $item['url']
            ];
        }

        return $schema;
    }

    /**
     * Generate breadcrumb HTML
     *
     * @since 1.0.0
     *
     * @param array $breadcrumb_items Breadcrumb items
     * @param array $settings         Breadcrumb settings
     * @return string HTML output
     */
    private function generate_breadcrumb_html(array $breadcrumb_items, array $settings): string {
        if (empty($breadcrumb_items)) {
            return '';
        }

        $separator = $settings['separator'] ?? '>';
        $html = '<nav class="thinkrank-breadcrumbs" aria-label="Breadcrumb">';
        $html .= '<ol class="breadcrumb-list">';

        foreach ($breadcrumb_items as $item) {
            $html .= '<li class="breadcrumb-item">';

            if (!empty($item['current'])) {
                $html .= '<span class="breadcrumb-current" aria-current="page">' . esc_html($item['title']) . '</span>';
            } else {
                $html .= '<a href="' . esc_url($item['url']) . '">' . esc_html($item['title']) . '</a>';
            }

            if ($item['position'] < count($breadcrumb_items)) {
                $html .= ' <span class="breadcrumb-separator">' . esc_html($separator) . '</span> ';
            }

            $html .= '</li>';
        }

        $html .= '</ol>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Generate default robots.txt rules
     *
     * @since 1.0.0
     *
     * @param array $settings Robots.txt settings
     * @return array Default rules
     */
    private function generate_default_robots_rules(array $settings): array {
        $rules = [];

        // Full block: when the admin turns off "Allow Search Engines" or enables
        // WordPress's "Discourage search engines" (Settings → Reading, stored as
        // blog_public=0), serve a robots.txt that disallows everything rather
        // than the default per-path rules — otherwise the toggle has no effect.
        $allow_search = $settings['allow_search_engines'] ?? true;
        if (empty($allow_search) || !get_option('blog_public')) {
            $rules[] = ['directive' => 'user_agent', 'value' => '*'];
            $rules[] = ['directive' => 'disallow', 'value' => '/'];
            return $rules;
        }

        // Default user agent rule
        $rules[] = [
            'directive' => 'user_agent',
            'value' => '*'
        ];

        // WordPress core disallows.
        //
        // Deliberately minimal, matching Yoast/Rank Math defaults. We do NOT
        // block /wp-includes/, /wp-content/plugins/, or /wp-content/themes/:
        // those paths serve the CSS and JS Google must fetch to render pages,
        // and blocking them causes "blocked resource" warnings and can hurt
        // rankings. /wp-json/ is left crawlable for the same reason (embeds,
        // oEmbed, structured previews). Only wp-admin (bar admin-ajax) and the
        // handful of non-content endpoints below are disallowed.
        $default_disallows = [
            '/wp-admin/',
            '/xmlrpc.php',
            '/readme.html',
            '/license.txt',
        ];

        // WooCommerce: keep cart/checkout/account and add-to-cart query URLs out
        // of the index to avoid crawl noise and duplicate/session URLs (parity
        // with Rank Math's WooCommerce robots defaults).
        if (class_exists('WooCommerce')) {
            $default_disallows[] = '/cart/';
            $default_disallows[] = '/checkout/';
            $default_disallows[] = '/my-account/';
            $default_disallows[] = '/*add-to-cart=*';
        }

        foreach ($default_disallows as $disallow) {
            $rules[] = [
                'directive' => 'disallow',
                'value' => $disallow
            ];
        }

        // Allow specific files
        $default_allows = [
            '/wp-admin/admin-ajax.php',
            '/wp-content/uploads/'
        ];

        foreach ($default_allows as $allow) {
            $rules[] = [
                'directive' => 'allow',
                'value' => $allow
            ];
        }

        // Add sitemap URLs from sitemap settings (auto-sync)
        $sitemap_urls = $this->get_sitemap_urls_for_robots();

        foreach ($sitemap_urls as $sitemap_url) {
            if (!empty($sitemap_url)) {
                $rules[] = [
                    'directive' => 'sitemap',
                    'value' => $sitemap_url
                ];
            }
        }

        // Add crawl delay if specified
        if (!empty($settings['crawl_delay'])) {
            $rules[] = [
                'directive' => 'crawl_delay',
                'value' => (int) $settings['crawl_delay']
            ];
        }

        return $rules;
    }

    /**
     * Get sitemap URLs from sitemap settings for robots.txt integration
     *
     * @since 1.0.0
     * @return array Array of sitemap URLs
     */
    private function get_sitemap_urls_for_robots(): array {
        try {
            // Get sitemap settings
            $sitemap_generator = new \ThinkRank\SEO\Sitemap_Generator();
            $sitemap_settings = $sitemap_generator->get_settings('site');

            // If sitemap is disabled, return default
            if (empty($sitemap_settings['enabled'])) {
                return [home_url('/sitemap.xml')];
            }

            $sitemap_urls = [];
            $site_url = home_url();

            // Extract enabled sitemap URLs
            if (!empty($sitemap_settings['sitemap_urls']) && is_array($sitemap_settings['sitemap_urls'])) {
                foreach ($sitemap_settings['sitemap_urls'] as $sitemap) {
                    if (!empty($sitemap['enabled']) && !empty($sitemap['url'])) {
                        $sitemap_urls[] = $site_url . $sitemap['url'];
                    }
                }
            }

            // Fallback to default if no URLs found
            if (empty($sitemap_urls)) {
                $sitemap_urls[] = home_url('/sitemap.xml');
            }

            // Advertise the local business sitemap when it exists. In segmented
            // mode it is already listed inside the sitemap index; in single mode
            // there is no index, so robots.txt is its discovery path.
            if (file_exists(ABSPATH . 'local-sitemap.xml')) {
                $local_url = home_url('/local-sitemap.xml');
                if (!in_array($local_url, $sitemap_urls, true)) {
                    $sitemap_urls[] = $local_url;
                }
            }

            return $sitemap_urls;
        } catch (\Exception $e) {
            // Fallback to default on error
            return [home_url('/sitemap.xml')];
        }
    }

    /**
     * Validate robots.txt rules
     *
     * @since 1.0.0
     *
     * @param array $rules Rules to validate
     * @return array Validation results
     */
    private function validate_robots_rules(array $rules): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => []
        ];

        $has_user_agent = false;

        foreach ($rules as $rule) {
            $directive = $rule['directive'] ?? '';
            $value = $rule['value'] ?? '';

            // Check if directive is valid
            if (!isset($this->robots_directives[$directive])) {
                $validation['errors'][] = "Unknown robots.txt directive: {$directive}";
                $validation['valid'] = false;
                continue;
            }

            // Check for required user-agent
            if ($directive === 'user_agent') {
                $has_user_agent = true;
            }

            // Validate directive-specific rules
            switch ($directive) {
                case 'disallow':
                case 'allow':
                    if (!str_starts_with($value, '/')) {
                        $validation['warnings'][] = "Path '{$value}' should start with '/'";
                    }
                    break;
                case 'sitemap':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $validation['errors'][] = "Invalid sitemap URL: {$value}";
                        $validation['valid'] = false;
                    }
                    break;
                case 'crawl_delay':
                    if (!is_numeric($value) || $value < 0) {
                        $validation['errors'][] = "Crawl delay must be a positive number";
                        $validation['valid'] = false;
                    }
                    break;
            }
        }

        if (!$has_user_agent) {
            $validation['errors'][] = 'robots.txt must include at least one User-agent directive';
            $validation['valid'] = false;
        }

        return $validation;
    }

    /**
     * Build robots.txt content from rules
     *
     * @since 1.0.0
     *
     * @param array $rules Robots.txt rules
     * @return string Robots.txt content
     */
    private function build_robots_txt_content(array $rules): string {
        // Body only — no header. The "# generated by ThinkRank SEO" + timestamp
        // block is added at render time (see robots_txt_header), so it never
        // gets baked into the stored/editable content and can't show a stale
        // timestamp on every update.
        $content = '';

        $current_user_agent = '';

        foreach ($rules as $rule) {
            $directive = $rule['directive'] ?? '';
            $value = $rule['value'] ?? '';

            switch ($directive) {
                case 'user_agent':
                    if ($current_user_agent !== $value) {
                        $content .= "\nUser-agent: {$value}\n";
                        $current_user_agent = $value;
                    }
                    break;
                case 'disallow':
                    $content .= "Disallow: {$value}\n";
                    break;
                case 'allow':
                    $content .= "Allow: {$value}\n";
                    break;
                case 'crawl_delay':
                    $content .= "Crawl-delay: {$value}\n";
                    break;
                case 'sitemap':
                    $content .= "\nSitemap: {$value}\n";
                    break;
            }
        }

        return ltrim($content, "\n");
    }

    /**
     * The auto-generated header prepended to the served robots.txt.
     *
     * Kept separate from the body so it is only ever added at render time with
     * a fresh timestamp, never stored or shown in the editable textarea.
     *
     * @return string
     */
    private function robots_txt_header(): string {
        return "# Robots.txt generated by ThinkRank SEO\n"
            . "# " . gmdate('Y-m-d H:i:s') . " UTC\n\n";
    }

    /**
     * Strip our auto-generated header from a robots.txt string.
     *
     * Used when surfacing existing content for editing so the header/timestamp
     * doesn't round-trip back into storage.
     *
     * @param string $content Raw robots.txt content.
     * @return string Body without the ThinkRank header.
     */
    private function strip_robots_header(string $content): string {
        $pattern = '/^# Robots\.txt generated by ThinkRank SEO\r?\n# [^\r\n]* UTC\r?\n\r?\n/';
        return trim((string) preg_replace($pattern, '', $content, 1));
    }

    /**
     * The body that should populate the editor for the current site.
     *
     * Prefers what is actually being served: the physical file if one exists
     * (header stripped), otherwise the effective body. This is what the admin
     * screen shows so the textarea is never blank while /robots.txt has content.
     *
     * @return string
     */
    public function get_served_robots_body(): string {
        $settings = $this->get_settings('site');

        $custom = trim((string) ($settings['robots_txt_content'] ?? ''));
        if ($custom !== '') {
            return $this->strip_robots_header($custom);
        }

        $robots_file = ABSPATH . 'robots.txt';
        if (file_exists($robots_file)) {
            $raw = (string) @file_get_contents($robots_file);
            if ($raw !== '') {
                return $this->strip_robots_header($raw);
            }
        }

        return trim($this->generate_robots_txt()['content']);
    }
    private function get_site_identity_data(array $settings): array {
        return [
            'site_name' => $settings['site_name'] ?? get_bloginfo('name'),
            'site_description' => $settings['site_description'] ?? get_bloginfo('description'),
            'tagline' => $settings['tagline'] ?? get_bloginfo('description'),
            'logo_url' => $settings['logo_url'] ?? '',
            'favicon_url' => $settings['favicon_url'] ?? '',
            'apple_touch_icon_url' => $settings['apple_touch_icon_url'] ?? ''
        ];
    }

    /**
     * Optimize individual identity element
     *
     * @since 1.0.0
     *
     * @param string $element Element name
     * @param mixed  $value   Element value
     * @param array  $config  Element configuration
     * @return array Optimization results
     */
    private function optimize_identity_element(string $element, $value, array $config): array {
        $optimization = [
            'optimized_value' => $value,
            'validation' => [
                'valid' => true,
                'errors' => [],
                'warnings' => []
            ],
            'suggestions' => []
        ];

        switch ($config['type']) {
            case 'text':
                $optimization = $this->optimize_text_element($element, $value, $config, $optimization);
                break;
            case 'image':
                $optimization = $this->optimize_image_element($element, $value, $config, $optimization);
                break;
        }

        return $optimization;
    }

    /**
     * Optimize text identity element
     *
     * @since 1.0.0
     *
     * @param string $element      Element name
     * @param string $value        Element value
     * @param array  $config       Element configuration
     * @param array  $optimization Current optimization
     * @return array Updated optimization
     */
    private function optimize_text_element(string $element, string $value, array $config, array $optimization): array {
        if (empty($value) && !empty($config['required'])) {
            $optimization['validation']['errors'][] = "{$element} is required";
            $optimization['validation']['valid'] = false;
        }

        if (!empty($value) && isset($config['max_length'])) {
            if (strlen($value) > $config['max_length']) {
                $optimization['validation']['warnings'][] = "{$element} exceeds maximum length of {$config['max_length']} characters";
                $optimization['optimized_value'] = substr($value, 0, $config['max_length']);
            }
        }

        // SEO-specific optimizations
        if ($element === 'site_name' && !empty($value)) {
            // Remove excessive punctuation
            $optimization['optimized_value'] = preg_replace('/[!@#$%^&*()]+/', '', $value);
        }

        return $optimization;
    }

    /**
     * Optimize image identity element
     *
     * @since 1.0.0
     *
     * @param string $element      Element name
     * @param string $value        Element value
     * @param array  $config       Element configuration
     * @param array  $optimization Current optimization
     * @return array Updated optimization
     */
    private function optimize_image_element(string $element, string $value, array $config, array $optimization): array {
        if (empty($value)) {
            if (!empty($config['required'])) {
                $optimization['validation']['errors'][] = "{$element} is required";
                $optimization['validation']['valid'] = false;
            }
            return $optimization;
        }

        // Validate URL
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $optimization['validation']['errors'][] = "{$element} must be a valid URL";
            $optimization['validation']['valid'] = false;
            return $optimization;
        }

        // Check if it's a local image
        $attachment_id = attachment_url_to_postid($value);
        if ($attachment_id) {
            $image_meta = wp_get_attachment_metadata($attachment_id);

            if ($image_meta && isset($image_meta['width'], $image_meta['height'])) {
                // Check recommended size
                if (isset($config['recommended_size'])) {
                    [$rec_width, $rec_height] = explode('x', $config['recommended_size']);

                    if ($image_meta['width'] != $rec_width || $image_meta['height'] != $rec_height) {
                        $optimization['suggestions'][] = "Consider using {$config['recommended_size']} size for optimal {$element}";
                    }
                }

                // Check file size
                if (isset($config['max_size'])) {
                    $file_path = get_attached_file($attachment_id);
                    if ($file_path && file_exists($file_path)) {
                        $file_size = filesize($file_path);
                        $max_size_bytes = $this->parse_size_string($config['max_size']);

                        if ($file_size > $max_size_bytes) {
                            $optimization['validation']['warnings'][] = "{$element} file size exceeds {$config['max_size']}";
                        }
                    }
                }
            }
        }

        return $optimization;
    }

    /**
     * Parse size string to bytes
     *
     * @since 1.0.0
     *
     * @param string $size_string Size string (e.g., '2MB', '500KB')
     * @return int Size in bytes
     */
    private function parse_size_string(string $size_string): int {
        $size_string = strtoupper(trim($size_string));
        $size = (int) $size_string;

        if (strpos($size_string, 'KB') !== false) {
            return $size * 1024;
        } elseif (strpos($size_string, 'MB') !== false) {
            return $size * 1024 * 1024;
        } elseif (strpos($size_string, 'GB') !== false) {
            return $size * 1024 * 1024 * 1024;
        }

        return $size;
    }

    /**
     * Calculate identity optimization score
     *
     * @since 1.0.0
     *
     * @param array $validations Element validations
     * @return int Score (0-100)
     */
    private function calculate_identity_score(array $validations): int {
        $total_score = 0;
        $element_count = 0;

        foreach ($validations as $validation) {
            $element_score = 100;
            $element_score -= count($validation['errors']) * 30;
            $element_score -= count($validation['warnings']) * 15;

            $total_score += max(0, $element_score);
            $element_count++;
        }

        return $element_count > 0 ? (int) round($total_score / $element_count) : 0;
    }

    /**
     * Calculate validation score
     *
     * @since 1.0.0
     *
     * @param array $validation Validation results
     * @return int Score (0-100)
     */
    private function calculate_validation_score(array $validation): int {
        $score = 100;
        $score -= count($validation['errors']) * 20;
        $score -= count($validation['warnings']) * 10;
        $score -= count($validation['suggestions']) * 5;

        return max(0, $score);
    }
}
