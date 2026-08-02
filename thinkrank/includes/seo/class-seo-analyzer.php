<?php
/**
 * Site SEO Analyzer
 *
 * Runs a crawl-free, site-wide SEO audit: a registry of individual checks is
 * evaluated against the site's own configuration and a bounded sample of its
 * published content, then aggregated into one overall 0–100 score, a letter
 * grade, and per-category subtotals. Unlike the analytics-based "SEO health
 * score", this requires no Google connection — it works out of the box.
 *
 * The result is cached in a transient; callers force a fresh run to bust it.
 * Checks are registered through the `thinkrank_seo_analyzer_checks` filter so
 * Pro/add-ons can contribute more without touching this class.
 *
 * @package ThinkRank\SEO
 * @since 1.18.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Analyzer Class
 *
 * @since 1.18.0
 */
class SEO_Analyzer {

    /**
     * Transient key holding the last full analysis.
     */
    private const CACHE_KEY = 'thinkrank_site_seo_analysis';

    /**
     * How long a computed analysis stays cached (seconds).
     */
    private const CACHE_TTL = HOUR_IN_SECONDS;

    // Check result statuses.
    public const PASSED  = 'passed';
    public const WARNING = 'warning';
    public const FAILED  = 'failed';

    /**
     * Human-readable labels for each category id.
     *
     * @return array<string,string>
     */
    private function get_category_labels(): array {
        return [
            'basic'       => __('Basic SEO', 'thinkrank'),
            'advanced'    => __('Advanced SEO', 'thinkrank'),
            'content'     => __('Content', 'thinkrank'),
            'performance' => __('Performance & Technical', 'thinkrank'),
            'security'    => __('Security', 'thinkrank'),
        ];
    }

    /**
     * Return the cached analysis, computing (and caching) it when missing or
     * when a fresh run is forced.
     *
     * @param bool $force When true, ignore and overwrite the cached result.
     * @return array The analysis payload (see analyze()).
     */
    public function run(bool $force = false): array {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['overall_score'])) {
                return $cached;
            }
        }

        $result = $this->analyze();
        set_transient(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Clear the cached analysis so the next run() recomputes.
     *
     * @return void
     */
    public function flush_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Run every registered check and aggregate the results.
     *
     * @return array {
     *     @type int    $overall_score Weighted 0–100 site score.
     *     @type string $grade         Letter grade A–F.
     *     @type array  $summary       passed/warning/failed/total counts.
     *     @type array  $categories    Per-category subtotal + its checks.
     *     @type array  $checks        Flat list of every check result.
     *     @type string $generated_at  ISO-8601 UTC timestamp.
     * }
     */
    public function analyze(): array {
        $checks         = $this->run_checks();
        $category_labels = $this->get_category_labels();

        $fraction = [
            self::PASSED  => 1.0,
            self::WARNING => 0.5,
            self::FAILED  => 0.0,
        ];

        $total_weight = 0.0;
        $earned       = 0.0;
        $summary      = [self::PASSED => 0, self::WARNING => 0, self::FAILED => 0, 'total' => 0];
        $categories   = [];

        foreach ($checks as $check) {
            $weight = (float) $check['weight'];
            $status = $check['status'];
            $frac   = $fraction[$status] ?? 0.0;

            $total_weight += $weight;
            $earned       += $weight * $frac;

            $summary[$status] = ($summary[$status] ?? 0) + 1;
            $summary['total']++;

            $cat = $check['category'];
            if (!isset($categories[$cat])) {
                $categories[$cat] = [
                    'id'     => $cat,
                    'label'  => $category_labels[$cat] ?? ucfirst($cat),
                    'score'  => 0,
                    'weight' => 0.0,
                    'earned' => 0.0,
                    self::PASSED  => 0,
                    self::WARNING => 0,
                    self::FAILED  => 0,
                    'checks' => [],
                ];
            }
            $categories[$cat]['weight']  += $weight;
            $categories[$cat]['earned']  += $weight * $frac;
            $categories[$cat][$status]    = ($categories[$cat][$status] ?? 0) + 1;
            $categories[$cat]['checks'][] = $check;
        }

        // Finalize per-category scores and drop the internal accumulators.
        foreach ($categories as $cat => &$data) {
            $data['score'] = $data['weight'] > 0
                ? (int) round(($data['earned'] / $data['weight']) * 100)
                : 0;
            unset($data['weight'], $data['earned']);
        }
        unset($data);

        $overall = $total_weight > 0 ? (int) round(($earned / $total_weight) * 100) : 0;

        return [
            'overall_score' => $overall,
            'grade'         => $this->score_to_grade($overall),
            'summary'       => $summary,
            'categories'    => array_values($categories),
            'checks'        => $checks,
            'generated_at'  => gmdate('c'),
        ];
    }

    /**
     * Map a 0–100 score to a letter grade.
     *
     * @param int $score The overall score.
     * @return string Letter grade.
     */
    private function score_to_grade(int $score): string {
        if ($score >= 90) {
            return 'A';
        }
        if ($score >= 80) {
            return 'B';
        }
        if ($score >= 70) {
            return 'C';
        }
        if ($score >= 60) {
            return 'D';
        }
        return 'F';
    }

    /**
     * Evaluate every registered check, normalizing each result.
     *
     * A check whose callback throws or returns a malformed value is skipped so
     * one broken check can't take down the whole analysis.
     *
     * @return array<int,array> Normalized check results.
     */
    private function run_checks(): array {
        $results = [];

        foreach ($this->get_check_definitions() as $def) {
            if (empty($def['callback']) || !is_callable($def['callback'])) {
                continue;
            }

            try {
                $outcome = call_user_func($def['callback']);
            } catch (\Throwable $e) {
                continue;
            }

            if (!is_array($outcome) || empty($outcome['status'])) {
                continue;
            }

            $results[] = [
                'id'         => (string) ($def['id'] ?? ''),
                'category'   => (string) ($def['category'] ?? 'basic'),
                'weight'     => isset($def['weight']) ? (float) $def['weight'] : 1.0,
                'label'      => (string) ($outcome['label'] ?? $def['label'] ?? ''),
                'status'     => (string) $outcome['status'],
                'message'    => (string) ($outcome['message'] ?? ''),
                'how_to_fix' => (string) ($outcome['how_to_fix'] ?? ''),
                'value'      => $outcome['value'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * The registry of checks: id, category, weight, and the callback that
     * evaluates it. Filterable so Pro/add-ons can register additional checks.
     *
     * @return array<int,array>
     */
    private function get_check_definitions(): array {
        $definitions = [
            // Basic SEO
            ['id' => 'site_title', 'category' => 'basic', 'weight' => 2, 'callback' => [$this, 'check_site_title']],
            ['id' => 'tagline', 'category' => 'basic', 'weight' => 1, 'callback' => [$this, 'check_tagline']],
            ['id' => 'search_visibility', 'category' => 'basic', 'weight' => 3, 'callback' => [$this, 'check_search_visibility']],
            ['id' => 'permalinks', 'category' => 'basic', 'weight' => 2, 'callback' => [$this, 'check_permalinks']],

            // Advanced SEO
            ['id' => 'xml_sitemap', 'category' => 'advanced', 'weight' => 2, 'callback' => [$this, 'check_sitemap']],
            ['id' => 'schema', 'category' => 'advanced', 'weight' => 2, 'callback' => [$this, 'check_schema']],

            // Content (bounded sample of published content)
            ['id' => 'meta_descriptions', 'category' => 'content', 'weight' => 2, 'callback' => [$this, 'check_meta_descriptions']],
            ['id' => 'image_alt_text', 'category' => 'content', 'weight' => 2, 'callback' => [$this, 'check_image_alt_text']],

            // Performance & Technical
            ['id' => 'php_version', 'category' => 'performance', 'weight' => 1, 'callback' => [$this, 'check_php_version']],
            ['id' => 'object_cache', 'category' => 'performance', 'weight' => 1, 'callback' => [$this, 'check_object_cache']],

            // Security
            ['id' => 'https', 'category' => 'security', 'weight' => 3, 'callback' => [$this, 'check_https']],
            ['id' => 'file_editing', 'category' => 'security', 'weight' => 2, 'callback' => [$this, 'check_file_editing']],
            ['id' => 'debug_display', 'category' => 'security', 'weight' => 1, 'callback' => [$this, 'check_debug_display']],
        ];

        /**
         * Filter the Site SEO Analyzer check registry.
         *
         * Each entry is an array with keys: id, category (basic|advanced|
         * content|performance|security), weight (float), and callback (callable
         * returning ['status' => passed|warning|failed, 'label', 'message',
         * 'how_to_fix']).
         *
         * @since 1.18.0
         *
         * @param array       $definitions Registered checks.
         * @param SEO_Analyzer $analyzer   The analyzer instance.
         */
        $definitions = apply_filters('thinkrank_seo_analyzer_checks', $definitions, $this);

        return is_array($definitions) ? $definitions : [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Basic SEO checks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The site must have a name/title configured.
     *
     * @return array
     */
    public function check_site_title(): array {
        $title = trim((string) get_bloginfo('name'));

        if ($title === '') {
            return [
                'label'      => __('Site title is set', 'thinkrank'),
                'status'     => self::FAILED,
                'message'    => __('Your site has no title. Search engines and browsers use it as your brand name.', 'thinkrank'),
                'how_to_fix' => __('Set a site title under Settings → General → Site Title.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('Site title is set', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('Your site title is configured.', 'thinkrank'),
            'value'   => $title,
        ];
    }

    /**
     * The tagline should be set and not left at the WordPress default.
     *
     * @return array
     */
    public function check_tagline(): array {
        $tagline = trim((string) get_bloginfo('description'));
        $is_default = strtolower($tagline) === strtolower('Just another WordPress site');

        if ($tagline === '' || $is_default) {
            return [
                'label'      => __('Tagline is customized', 'thinkrank'),
                'status'     => self::WARNING,
                'message'    => __('Your tagline is blank or still the WordPress default. Search engines may use it as your homepage description.', 'thinkrank'),
                'how_to_fix' => __('Write a descriptive tagline under Settings → General → Tagline.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('Tagline is customized', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('Your tagline is set and ready to describe your site.', 'thinkrank'),
            'value'   => $tagline,
        ];
    }

    /**
     * "Discourage search engines from indexing this site" must be OFF.
     *
     * @return array
     */
    public function check_search_visibility(): array {
        // blog_public = 0 means the WP "Discourage search engines" box is ticked.
        if (!get_option('blog_public')) {
            return [
                'label'      => __('Site is visible to search engines', 'thinkrank'),
                'status'     => self::FAILED,
                'message'    => __('Your site is telling search engines not to index it — it will not appear in search results.', 'thinkrank'),
                'how_to_fix' => __('Untick "Discourage search engines from indexing this site" under Settings → Reading.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('Site is visible to search engines', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('Your site allows search engines to index it.', 'thinkrank'),
        ];
    }

    /**
     * Permalinks should be pretty (not the default plain ?p=123 structure).
     *
     * @return array
     */
    public function check_permalinks(): array {
        $structure = (string) get_option('permalink_structure');

        if ($structure === '') {
            return [
                'label'      => __('Search-friendly permalinks', 'thinkrank'),
                'status'     => self::WARNING,
                'message'    => __('Your site uses plain, numeric URLs (e.g. ?p=123). Descriptive URLs are easier for search engines and users.', 'thinkrank'),
                'how_to_fix' => __('Choose a pretty permalink structure (e.g. Post name) under Settings → Permalinks.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('Search-friendly permalinks', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('Your permalinks are search-friendly.', 'thinkrank'),
            'value'   => $structure,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Advanced SEO checks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The ThinkRank XML sitemap should be enabled.
     *
     * @return array
     */
    public function check_sitemap(): array {
        $enabled = true;
        try {
            $generator = new Sitemap_Generator();
            $data      = $generator->get_output_data('global', null);
            $enabled   = !empty($data['enabled']);
        } catch (\Throwable $e) {
            // Fall back to "enabled" — the default state — on any lookup error.
            $enabled = true;
        }

        if (!$enabled) {
            return [
                'label'      => __('XML sitemap is enabled', 'thinkrank'),
                'status'     => self::WARNING,
                'message'    => __('Your XML sitemap is turned off. Search engines rely on it to discover new pages quickly.', 'thinkrank'),
                'how_to_fix' => __('Enable the XML sitemap under Essential SEO → Crawling & AI Indexing → XML Sitemap.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('XML sitemap is enabled', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('Your XML sitemap is enabled and pointing crawlers to your content.', 'thinkrank'),
        ];
    }

    /**
     * Structured data (schema) should be configured for at least one post type.
     *
     * @return array
     */
    public function check_schema(): array {
        $label = __('Structured data configured', 'thinkrank');

        if ($this->schema_is_output()) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('Structured data is configured for your content.', 'thinkrank'),
            ];
        }

        return [
            'label'      => $label,
            'status'     => self::WARNING,
            'message'    => __('No schema/structured data is configured. Schema powers rich results in search.', 'thinkrank'),
            'how_to_fix' => __('Choose a default schema type for each post type under Essential SEO → Bulk SEO Optimization.', 'thinkrank'),
        ];
    }

    /**
     * Whether ThinkRank actually emits structured data for this site.
     *
     * The audit must reflect what is rendered, not a single legacy option.
     * ThinkRank outputs schema from two current sources, so this check consults
     * both rather than the deprecated thinkrank_global_seo_settings['schema_type']
     * opt-in (which most sites never set even though schema is emitted):
     *
     *   1. The Schema Management System — its configuration lives in the
     *      thinkrank_seo_settings table (context "schema_management_system"),
     *      read through the manager's settings abstraction.
     *   2. The Global SEO output layer — an explicit saved schema_type OR the
     *      built-in per-post-type default both cause JSON-LD to be emitted on
     *      the frontend. We ask that layer directly (would_output_schema) so the
     *      audit and the rendered page can never diverge.
     *
     * @return bool True when structured data is emitted for the site's content.
     */
    private function schema_is_output(): bool {
        // 1) Newer Schema Management System (thinkrank_seo_settings table).
        if (class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
            $settings = (new Schema_Management_System())->get_settings('schema_management_system');
            if (is_array($settings)) {
                if (!empty($settings['enabled_schema_types']) && is_array($settings['enabled_schema_types'])) {
                    return true;
                }
                if (!empty($settings['auto_generate_schema'])) {
                    return true;
                }
            }
        }

        // 2) Global SEO output layer — explicit schema_type or per-post-type
        //    default. Reuse the output layer's own decision so audit == output.
        if (class_exists('ThinkRank\\Frontend\\Global_SEO_Schema_Output')) {
            $output = new \ThinkRank\Frontend\Global_SEO_Schema_Output();
            foreach (get_post_types(['public' => true], 'names') as $post_type) {
                if ($output->would_output_schema((string) $post_type)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Content checks (bounded sample of published content)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * How many recent published posts/pages the content checks sample.
     */
    private const CONTENT_SAMPLE_SIZE = 100;

    /**
     * Coverage thresholds shared by the content checks: at or above the first
     * is a pass, at or above the second is a warning, below it a fail.
     */
    private const COVERAGE_PASS = 90;
    private const COVERAGE_WARN = 50;

    /**
     * Recent published posts/pages should have meta descriptions.
     *
     * Samples the most recent CONTENT_SAMPLE_SIZE published posts/pages so the
     * check stays fast on large sites.
     *
     * @return array
     */
    public function check_meta_descriptions(): array {
        $label = __('Posts have meta descriptions', 'thinkrank');

        $post_ids = get_posts([
            'post_type'        => ['post', 'page'],
            'post_status'      => 'publish',
            'posts_per_page'   => self::CONTENT_SAMPLE_SIZE,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        $total = count($post_ids);
        if (0 === $total) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('No published content to check yet.', 'thinkrank'),
            ];
        }

        // Count posts with an *effective* meta description, the same way the
        // frontend resolves it: a custom _thinkrank_meta_description when set,
        // otherwise the global SEO pattern fallback (Pattern_Resolver). Counting
        // only the custom post-meta produced false negatives — posts that output
        // a valid description via the pattern fallback were wrongly reported as
        // missing. The sample is bounded (CONTENT_SAMPLE_SIZE) so the per-post
        // resolution stays cheap, and the whole analysis is cached for an hour.
        $with_description = 0;
        foreach ($post_ids as $post_id) {
            $custom   = (string) get_post_meta($post_id, '_thinkrank_meta_description', true);
            $resolved = '' !== $custom ? $custom : Pattern_Resolver::description((int) $post_id);
            if ('' !== trim($resolved)) {
                $with_description++;
            }
        }

        $coverage = (int) round(($with_description / $total) * 100);
        $missing  = $total - $with_description;
        $value    = sprintf('%d/%d', $with_description, $total);

        if ($coverage >= self::COVERAGE_PASS) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                /* translators: 1: posts with meta description, 2: sampled posts. */
                'message' => sprintf(__('%1$d of your %2$d most recent posts have a meta description.', 'thinkrank'), $with_description, $total),
                'value'   => $value,
            ];
        }

        return [
            'label'      => $label,
            'status'     => $coverage >= self::COVERAGE_WARN ? self::WARNING : self::FAILED,
            /* translators: 1: posts missing a meta description, 2: sampled posts. */
            'message'    => sprintf(__('%1$d of your %2$d most recent posts are missing a meta description. Search engines fall back to arbitrary page text for their snippets.', 'thinkrank'), $missing, $total),
            'how_to_fix' => __('Add meta descriptions in the ThinkRank SEO panel when editing a post — or use Bulk SEO Optimization to generate them with AI.', 'thinkrank'),
            'value'      => $value,
        ];
    }

    /**
     * Uploaded images should have alt text — it is an accessibility
     * requirement and how image search understands your media.
     *
     * @return array
     */
    public function check_image_alt_text(): array {
        $label = __('Images have alt text', 'thinkrank');

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- two indexed COUNTs; results are cached at the analysis level
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );

        if (0 === $total) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('No images in your media library to check yet.', 'thinkrank'),
            ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed COUNT via postmeta meta_key index
        $with_alt = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = '_wp_attachment_image_alt'
               AND pm.meta_value != ''
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
        );

        $coverage = (int) round(($with_alt / $total) * 100);
        $missing  = $total - $with_alt;
        $value    = sprintf('%d/%d', $with_alt, $total);

        if ($coverage >= self::COVERAGE_PASS) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                /* translators: 1: images with alt text, 2: total images. */
                'message' => sprintf(__('%1$d of your %2$d images have alt text.', 'thinkrank'), $with_alt, $total),
                'value'   => $value,
            ];
        }

        return [
            'label'      => $label,
            'status'     => $coverage >= self::COVERAGE_WARN ? self::WARNING : self::FAILED,
            /* translators: 1: images missing alt text, 2: total images. */
            'message'    => sprintf(__('%1$d of your %2$d images are missing alt text. Alt text drives image search rankings and is an accessibility requirement.', 'thinkrank'), $missing, $total),
            'how_to_fix' => __('Under Essential SEO → Image SEO, turn on "Save alt text to the Media Library" and run "Fill missing alt text" to populate them from your format, or add alt text manually in the Media Library.', 'thinkrank'),
            'value'      => $value,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Performance & Technical checks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The site should run a supported PHP version.
     *
     * @return array
     */
    public function check_php_version(): array {
        $current = PHP_VERSION;
        // ThinkRank itself requires PHP 8.0 to run, so anything below 8.1 (the
        // oldest actively-supported branch) is the meaningful warning line —
        // a 7.x threshold here could never fire.
        $supported = version_compare($current, '8.1', '>=');

        if (!$supported) {
            return [
                'label'      => __('Supported PHP version', 'thinkrank'),
                'status'     => self::WARNING,
                /* translators: %s: current PHP version. */
                'message'    => sprintf(__('You are running PHP %s, which no longer receives active support. Newer PHP is faster and more secure.', 'thinkrank'), $current),
                'how_to_fix' => __('Ask your host to upgrade to PHP 8.1 or newer.', 'thinkrank'),
                'value'      => $current,
            ];
        }

        return [
            'label'   => __('Supported PHP version', 'thinkrank'),
            'status'  => self::PASSED,
            /* translators: %s: current PHP version. */
            'message' => sprintf(__('You are running a supported PHP version (%s).', 'thinkrank'), $current),
            'value'   => $current,
        ];
    }

    /**
     * A persistent object cache should be active for a faster, less DB-bound
     * site.
     *
     * @return array
     */
    public function check_object_cache(): array {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return [
                'label'   => __('Persistent object cache', 'thinkrank'),
                'status'  => self::PASSED,
                'message' => __('A persistent object cache is active, reducing database load.', 'thinkrank'),
            ];
        }

        return [
            'label'      => __('Persistent object cache', 'thinkrank'),
            'status'     => self::WARNING,
            'message'    => __('No persistent object cache is active. On busier sites this means more database queries per request.', 'thinkrank'),
            'how_to_fix' => __('Enable a persistent object cache (e.g. Redis or Memcached) via your host or a caching plugin.', 'thinkrank'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Security checks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The site should be served over HTTPS (SSL).
     *
     * @return array
     */
    public function check_https(): array {
        $home = (string) get_option('home');
        $uses_https = strpos($home, 'https://') === 0;

        // WP 5.7+ can tell us the site is fully HTTPS-capable.
        if (function_exists('wp_is_using_https')) {
            $uses_https = $uses_https && wp_is_using_https();
        }

        if (!$uses_https) {
            return [
                'label'      => __('Site uses HTTPS (SSL)', 'thinkrank'),
                'status'     => self::FAILED,
                'message'    => __('Your site URL is not served over HTTPS. HTTPS is a confirmed ranking signal and required for user trust.', 'thinkrank'),
                'how_to_fix' => __('Install an SSL certificate and set your WordPress Address / Site Address to https:// under Settings → General.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('Site uses HTTPS (SSL)', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('Your site is served securely over HTTPS.', 'thinkrank'),
        ];
    }

    /**
     * The built-in plugin/theme file editor should be disabled
     * (DISALLOW_FILE_EDIT) so a compromised admin cannot edit PHP from wp-admin.
     *
     * @return array
     */
    public function check_file_editing(): array {
        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            return [
                'label'   => __('File editing disabled', 'thinkrank'),
                'status'  => self::PASSED,
                'message' => __('The dashboard plugin/theme file editor is disabled, reducing your attack surface.', 'thinkrank'),
            ];
        }

        return [
            'label'      => __('File editing disabled', 'thinkrank'),
            'status'     => self::WARNING,
            'message'    => __('The built-in file editor is enabled. If an admin account is compromised, an attacker could edit your PHP files from wp-admin.', 'thinkrank'),
            'how_to_fix' => __('Add define(\'DISALLOW_FILE_EDIT\', true); to your wp-config.php.', 'thinkrank'),
        ];
    }

    /**
     * The site should not publicly display PHP errors (WP_DEBUG_DISPLAY),
     * which can leak server paths and internals.
     *
     * @return array
     */
    public function check_debug_display(): array {
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        // WP_DEBUG_DISPLAY only shows errors when it is on (its default) AND
        // WP_DEBUG is enabled.
        $display  = !defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY;
        $exposing = $debug && $display;

        if ($exposing) {
            return [
                'label'      => __('Errors not shown publicly', 'thinkrank'),
                'status'     => self::WARNING,
                'message'    => __('Debug output is displayed on the front end. Visible PHP errors can leak server paths and internals.', 'thinkrank'),
                'how_to_fix' => __('Set define(\'WP_DEBUG_DISPLAY\', false); (or turn off WP_DEBUG) in wp-config.php on production.', 'thinkrank'),
            ];
        }

        return [
            'label'   => __('Errors not shown publicly', 'thinkrank'),
            'status'  => self::PASSED,
            'message' => __('PHP errors are not displayed to visitors.', 'thinkrank'),
        ];
    }
}
