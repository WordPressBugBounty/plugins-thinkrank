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

    /**
     * Names ThinkRank's FAQ / How-To producers share across Elementor widgets,
     * Bricks elements and Beaver Builder modules.
     */
    private const ANSWER_FAQ_NAME   = 'thinkrank-faq';
    private const ANSWER_HOWTO_NAME = 'thinkrank-howto';

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
            // Generative/answer engine optimization. Spelled out because the
            // acronym alone reads as geography, and ucfirst()'d "Geo" — what a
            // filter-registered category falls back to — reads as nothing.
            'geo'         => __('AI Search (GEO)', 'thinkrank'),
        ];
    }

    /**
     * WordPress options whose value the analyzer reports on directly.
     *
     * @since 2.2.0
     * @var string[]
     */
    private const WATCHED_OPTIONS = [
        'blog_public',
        'permalink_structure',
        'blogname',
        'blogdescription',
        // The published llms.txt document. Publishing or clearing it flips a
        // GEO check, and it is written as an option rather than through the
        // settings manager, so the settings-saved hook never sees it.
        'thinkrank_llms_txt_content',
    ];

    /**
     * Register cache invalidation.
     *
     * The analysis is cached for an hour, and until now only the image alt-text
     * bulk writer ever busted it — so changing any other setting the audit
     * reports on left the screen confidently wrong for up to 60 minutes. The
     * audit's whole job is to describe the site's current configuration, so it
     * invalidates on every write it could possibly be reading.
     *
     * @since 2.2.0
     * @return void
     */
    public function init(): void {
        foreach (self::WATCHED_OPTIONS as $option) {
            add_action("update_option_{$option}", [$this, 'flush_cache']);
            add_action("add_option_{$option}", [$this, 'flush_cache']);
        }

        // Any ThinkRank settings category can feed a check (sitemap, schema,
        // image SEO today; more later). Flushing on all of them is cheaper than
        // a list that silently rots as checks are added.
        add_action('thinkrank_seo_settings_saved', [$this, 'flush_cache']);
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

        $result = [
            'overall_score' => $overall,
            'grade'         => $this->score_to_grade($overall),
            'summary'       => $summary,
            'categories'    => array_values($categories),
            'checks'        => $checks,
            'generated_at'  => gmdate('c'),
        ];

        /**
         * Fires after a site audit has been computed.
         *
         * Every path that produces a fresh analysis passes through here — the
         * REST run route, the one-click fixer's re-run, and a cold cache — so a
         * listener sees every run exactly once and never sees a cache hit.
         * ThinkRank Pro uses this to persist a dated snapshot for the score
         * trend and the run comparison.
         *
         * The payload is the analysis as returned to the caller; a listener
         * must treat it as read-only.
         *
         * @since 2.5.0
         *
         * @param array $result The completed analysis (see the return docblock).
         */
        do_action('thinkrank_seo_analysis_completed', $result);

        return $result;
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

            $id     = (string) ($def['id'] ?? '');
            $status = (string) $outcome['status'];

            // Only offer a fix on a finding that still needs one — a passing
            // check with a Fix button reads as "did this even work?".
            $fixable = self::PASSED !== $status && SEO_Analyzer_Fixer::can_fix($id);
            $fix     = $fixable ? (SEO_Analyzer_Fixer::fixable()[$id] ?? []) : [];

            // The list can be capped below the number of items the finding is
            // about, so the total travels with it.
            $affected = $this->normalize_affected_posts($outcome['affected_posts'] ?? []);

            $results[] = [
                'id'           => $id,
                'category'     => (string) ($def['category'] ?? 'basic'),
                'weight'       => isset($def['weight']) ? (float) $def['weight'] : 1.0,
                'label'        => (string) ($outcome['label'] ?? $def['label'] ?? ''),
                'status'       => $status,
                'message'      => (string) ($outcome['message'] ?? ''),
                'how_to_fix'   => (string) ($outcome['how_to_fix'] ?? ''),
                'value'        => $outcome['value'] ?? null,
                'affected_posts' => $affected,
                'affected_total' => max(count($affected), absint($outcome['affected_total'] ?? 0)),
                'can_auto_fix' => $fixable,
                'fix_label'    => (string) ($fix['label'] ?? ''),
                'fix_warning'  => (string) ($fix['warning'] ?? ''),
            ];
        }

        return $results;
    }

    /**
     * Reduce a check's list of posts to fix to a known, escaped shape.
     *
     * The list reaches the audit UI as links, and a check registered through
     * `thinkrank_seo_analyzer_checks` can put anything in it, so every entry is
     * rebuilt here rather than passed through.
     *
     * @since 2.6.0
     * @param mixed $posts Raw `affected_posts` from a check result.
     * @return array<int,array{id: int, title: string, type: string, edit_url: string, url: string}>
     */
    private function normalize_affected_posts($posts): array {
        if (!is_array($posts)) {
            return [];
        }

        $out = [];

        foreach ($posts as $post) {
            if (!is_array($post) || empty($post['id'])) {
                continue;
            }

            $out[] = [
                'id'       => absint($post['id']),
                'title'    => sanitize_text_field((string) ($post['title'] ?? '')),
                'type'     => sanitize_text_field((string) ($post['type'] ?? '')),
                'edit_url' => esc_url_raw((string) ($post['edit_url'] ?? '')),
                'url'      => esc_url_raw((string) ($post['url'] ?? '')),
            ];
        }

        return $out;
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

            // GEO / AEO — AI answer-engine readiness. The three configuration
            // checks carry the weight of a normal check; the five sampled
            // content ones are half that, so the category as a whole sits
            // beside the existing ones rather than dominating the score.
            ['id' => 'ai_crawler_access', 'category' => 'geo', 'weight' => 1.5, 'callback' => [$this, 'check_ai_crawler_access']],
            ['id' => 'llms_txt', 'category' => 'geo', 'weight' => 1.5, 'callback' => [$this, 'check_llms_txt']],
            ['id' => 'answer_ready_schema', 'category' => 'geo', 'weight' => 1.5, 'callback' => [$this, 'check_answer_ready_schema']],
            ['id' => 'direct_answer', 'category' => 'geo', 'weight' => 0.5, 'callback' => [$this, 'check_direct_answer']],
            ['id' => 'question_headings', 'category' => 'geo', 'weight' => 0.5, 'callback' => [$this, 'check_question_headings']],
            ['id' => 'structured_content', 'category' => 'geo', 'weight' => 0.5, 'callback' => [$this, 'check_structured_content']],
            ['id' => 'content_depth', 'category' => 'geo', 'weight' => 0.5, 'callback' => [$this, 'check_content_depth']],
            ['id' => 'content_freshness', 'category' => 'geo', 'weight' => 0.5, 'callback' => [$this, 'check_content_freshness']],
        ];

        /**
         * Filter the Site SEO Analyzer check registry.
         *
         * Each entry is an array with keys: id, category (basic|advanced|
         * content|performance|security|geo), weight (float), and callback (callable
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

        $is_default = $this->is_default_tagline($tagline);

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
     * Whether a tagline is still WordPress' shipped default.
     *
     * The installer writes the TRANSLATED default into blogdescription, so an
     * English-only literal silently passed an untouched tagline on every
     * non-English install. The string lives in core's `admin-{locale}.mo`,
     * which a REST request (how this analyzer runs) does not load — so the
     * catalogue is loaded on demand for the comparison when the site is not
     * running in English.
     *
     * @since 2.2.0
     * @param string $tagline Trimmed tagline.
     * @return bool
     */
    private function is_default_tagline(string $tagline): bool {
        $candidates = ['Just another WordPress site'];

        $locale = get_locale();
        if ('en_US' !== $locale) {
            // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.LowLevelTranslationFunction -- core's own string in the `default` domain, read at runtime.
            $translated = translate('Just another WordPress site', 'default');

            if ($translated === 'Just another WordPress site') {
                // Not in the loaded catalogue — pull in the admin one, which is
                // where core ships this string, then ask again.
                $mofile = WP_LANG_DIR . '/admin-' . $locale . '.mo';
                if (is_readable($mofile)) {
                    load_textdomain('default', $mofile, $locale);
                    // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.LowLevelTranslationFunction -- as above.
                    $translated = translate('Just another WordPress site', 'default');
                }
            }

            $candidates[] = $translated;
        }

        foreach ($candidates as $candidate) {
            if (strtolower($tagline) === strtolower($candidate)) {
                return true;
            }
        }

        return false;
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
            // 'site' is the stored context; 'global' is unsupported and
            // returns DEFAULTS (enabled=true), which made this check unable
            // to fail no matter what the user configured.
            $data      = $generator->get_output_data('site', null);
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

        if ($this->schema_is_configured()) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('Structured data is configured for your content.', 'thinkrank'),
            ];
        }

        // Nothing is configured, but ThinkRank still emits JSON-LD from its
        // built-in per-post-type defaults. Saying "no schema" there would be
        // false; the actionable point is that nobody has reviewed it.
        if ($this->schema_is_output()) {
            return [
                'label'      => $label,
                'status'     => self::WARNING,
                'message'    => __('Structured data is running on ThinkRank\'s built-in defaults. Reviewing the schema type for each post type gives you control over how rich results appear.', 'thinkrank'),
                'how_to_fix' => __('Choose a schema type for each post type under Essential SEO → Bulk SEO Optimization.', 'thinkrank'),
            ];
        }

        return [
            'label'      => $label,
            'status'     => self::FAILED,
            'message'    => __('No schema/structured data is configured or emitted. Schema powers rich results in search.', 'thinkrank'),
            'how_to_fix' => __('Turn on automatic structured data, or choose a schema type for each post type under Essential SEO → Bulk SEO Optimization.', 'thinkrank'),
        ];
    }

    /**
     * Whether the user has EXPLICITLY configured structured data.
     *
     * Distinct from schema_is_output(): the Global SEO layer falls back to a
     * built-in schema type for every public post type, so "something is
     * emitted" is true on every site and made this check impossible to fail
     * (its weight was earned unconditionally and its one-click fix was
     * unreachable). This asks the question the check's copy actually claims to
     * answer.
     *
     * Both layers must be read WITHOUT their defaults, or the same trap closes
     * again one level down: get_settings() merges the context defaults under
     * the saved rows, and Schema_Settings_Config's 'site' defaults set both
     * enabled_schema_types and auto_generate_schema — so an untouched site came
     * back looking configured and this method still could not return false
     * (#586). get_stored_settings() answers with only what was actually saved.
     *
     * @since 2.2.0
     * @return bool
     */
    private function schema_is_configured(): bool {
        // 1) Schema Management System — an explicit opt-in. Read the SAVED rows
        //    only; the defaults-merged view is truthy on every site.
        if (class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
            $settings = (new Schema_Management_System())->get_stored_settings('site', null);
            // The master switch gates these the same way it gates
            // schema_is_output(). Saving the settings form persists the whole
            // payload, so turning the feature off stores enabled = '0' while
            // auto_generate_schema stays '1' — and reading past the switch then
            // reported "structured data is configured" for a site emitting
            // none, with the one-click fix withheld. array_key_exists rather
            // than a bare !empty so an untouched site, where 'enabled' was
            // never saved at all, still falls through to the Global SEO layer
            // below instead of short-circuiting to false.
            $master_on = is_array($settings)
                && (!array_key_exists('enabled', $settings) || !empty($settings['enabled']));

            if ($master_on) {
                if (!empty($settings['enabled_schema_types']) && is_array($settings['enabled_schema_types'])) {
                    return true;
                }
                if (!empty($settings['auto_generate_schema'])) {
                    return true;
                }
            }
        }

        // 2) A saved per-post-type schema_type in the Global SEO layer. The
        //    built-in default deliberately does not count here.
        if (class_exists('ThinkRank\\Frontend\\Global_SEO_Schema_Output')) {
            $output = new \ThinkRank\Frontend\Global_SEO_Schema_Output();
            foreach (get_post_types(['public' => true], 'names') as $post_type) {
                if ($output->has_explicit_schema_type((string) $post_type)) {
                    return true;
                }
            }
        }

        return false;
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
            // 'site' is the context type; 'schema_management_system' is the manager
            // NAME, which get_settings() rejects as an unsupported context and
            // answers with bare defaults — where auto_generate_schema is true, so
            // this always returned true and never read the site's real settings (#473).
            //
            // This one KEEPS the defaults-merged view on purpose, unlike
            // schema_is_configured() (#586). The question here is "does JSON-LD
            // reach the page?", and an untouched site answers yes: the 'site'
            // defaults leave the system enabled with auto_generate_schema on, so
            // the merged value is the emitted behaviour, not a mask over it.
            $settings = (new Schema_Management_System())->get_settings('site', null);
            // The master switch gates everything below it: with 'enabled' off,
            // get_output_data() reports the feature as off and nothing is
            // emitted, so reading auto_generate_schema past it told the audit
            // schema was on the page when it was not (the same shape as #461).
            if (is_array($settings) && !empty($settings['enabled'])) {
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
     * Most items a finding names when it is not drawn from the bounded sample.
     *
     * The image check counts the whole media library, which can run to tens of
     * thousands of rows; a list that long would bloat the cached analysis and
     * every stored audit snapshot while telling the user nothing more.
     */
    private const AFFECTED_LIMIT = 100;

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

        $post_ids = $this->sample_post_ids();

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
        // 'fields' => 'ids' skips WP_Query's meta priming, so the first
        // get_post_meta() below would issue a query per post. Warm the whole
        // sample once instead — 100 posts went from ~200 queries to a handful.
        _prime_post_caches($post_ids, false, true);

        $with_description = 0;
        $without          = [];
        foreach ($post_ids as $post_id) {
            $custom   = (string) get_post_meta($post_id, '_thinkrank_meta_description', true);
            $resolved = '' !== $custom ? $custom : Pattern_Resolver::description((int) $post_id);
            if ('' !== trim($resolved)) {
                $with_description++;
            } else {
                $without[] = (int) $post_id;
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
            'how_to_fix'     => __('Add meta descriptions in the ThinkRank SEO panel when editing a post — or use Bulk SEO Optimization to generate them with AI.', 'thinkrank'),
            'value'          => $value,
            'affected_posts' => $this->affected_posts($without),
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
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'
               AND post_status != 'trash'"
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
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%'
               AND p.post_status != 'trash'"
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
            'how_to_fix'     => __('Under Essential SEO → Image SEO, turn on "Save alt text to the Media Library" and run "Fill missing alt text" to populate them from your format, or add alt text manually in the Media Library.', 'thinkrank'),
            'value'          => $value,
            'affected_posts' => $this->affected_posts($this->image_ids_without_alt()),
            'affected_total' => $missing,
        ];
    }

    /**
     * The most recent images with no alt text, capped at AFFECTED_LIMIT.
     *
     * Mirrors the counts above: an image counts as having alt text when any
     * `_wp_attachment_image_alt` row for it is non-empty.
     *
     * @since 2.6.0
     * @return int[]
     */
    private function image_ids_without_alt(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded id list for a cached analysis; no core API filters on a missing meta value
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 WHERE p.post_type = 'attachment'
                   AND p.post_mime_type LIKE %s
                   AND p.post_status != 'trash'
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm
                        WHERE pm.post_id = p.ID
                          AND pm.meta_key = '_wp_attachment_image_alt'
                          AND pm.meta_value != ''
                   )
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                $wpdb->esc_like('image/') . '%',
                self::AFFECTED_LIMIT
            )
        );

        return array_map('intval', (array) $ids);
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
    // ─────────────────────────────────────────────────────────────────────
    // GEO / AEO checks (AI answer-engine readiness)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The crawlers that decide whether a site can be CITED by an AI answer
     * engine, as opposed to the ones that only collect training data.
     *
     * Blocking a training crawler (GPTBot, ClaudeBot, CCBot…) is a legitimate
     * editorial choice and is deliberately not marked down here: it costs the
     * site nothing in ChatGPT, Claude, Perplexity or AI Overviews. Blocking
     * the agents below is what makes a site invisible to those answers, so
     * they are the only ones this check reports on.
     *
     * Public because the one-click fix writes exactly this set to `allow`;
     * a second copy in the fixer is how the check and its remedy drift into
     * disagreeing about which crawlers matter.
     *
     * @since 2.5.0
     * @var string[]
     */
    public const GEO_ANSWER_AGENTS = [
        'oai-searchbot',
        'chatgpt-user',
        'perplexitybot',
        'perplexity-user',
        'claude-searchbot',
        'claude-user',
        'google-extended',
        'mistral-user',
    ];

    /**
     * Word-count window for an opening passage that reads as a direct answer.
     *
     * The AEO convention is a self-contained 40–60 word answer directly under
     * the title. The window is widened at both ends so ordinary good writing
     * passes: below the floor there is no answer to quote, and well above the
     * ceiling the passage is a preamble an engine has to summarize rather than
     * a sentence it can lift.
     */
    private const GEO_ANSWER_MIN_WORDS = 20;
    private const GEO_ANSWER_MAX_WORDS = 120;

    /**
     * Words below which a page has too little substance to be cited.
     */
    private const GEO_DEPTH_MIN_WORDS = 300;

    /**
     * How long a page can go unrevised before it reads as stale to an engine
     * that prefers recent sources.
     */
    private const GEO_FRESHNESS_MAX_AGE = 365 * DAY_IN_SECONDS;

    /**
     * The content sample the GEO checks share, memoized for one analysis.
     *
     * Five checks read the same 100 posts. Sampling once turns five queries
     * (and five cache-priming passes) into one, and guarantees the five
     * results describe the same set of posts.
     *
     * @since 2.5.0
     * @var array<int,array{id: int, content: string, text: string, modified: int}>|null
     */
    private $content_sample = null;

    /**
     * The sampled post ids, memoized so the Content and GEO categories run one
     * query between them rather than one each over the same CONTENT_SAMPLE_SIZE
     * posts. Both want the same slice — the most recent published posts and
     * pages — so two queries only guaranteed they could disagree after a
     * publish mid-analysis.
     *
     * @since 2.5.0
     * @var int[]|null
     */
    private $sample_post_ids = null;

    /**
     * The sampled post ids, fetched once and shared by every check that reads
     * the same slice.
     *
     * @since 2.5.0
     * @return int[]
     */
    private function sample_post_ids(): array {
        if (null !== $this->sample_post_ids) {
            return $this->sample_post_ids;
        }

        $this->sample_post_ids = get_posts([
            'post_type'        => ['post', 'page'],
            'post_status'      => 'publish',
            'posts_per_page'   => self::CONTENT_SAMPLE_SIZE,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        return $this->sample_post_ids;
    }

    /**
     * The most recent published posts/pages, as raw content, its plain-text
     * rendering and the modified time.
     *
     * Raw `post_content` on purpose: running `the_content` over 100 posts in a
     * REST request would fire every shortcode and block renderer on the site.
     * The structural signals these checks look for (headings, lists, tables,
     * the opening passage) survive in the stored markup.
     *
     * @since 2.5.0
     * @return array<int,array{id: int, content: string, text: string, modified: int}>
     */
    private function get_content_sample(): array {
        if (null !== $this->content_sample) {
            return $this->content_sample;
        }

        $post_ids = $this->sample_post_ids();

        if (function_exists('_prime_post_caches')) {
            _prime_post_caches($post_ids, false, false);
        }

        $sample = [];

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $modified = isset($post->post_modified_gmt) ? strtotime((string) $post->post_modified_gmt . ' UTC') : false;

            $content = (string) $post->post_content;

            $sample[] = [
                'id'       => (int) $post->ID,
                'content'  => $content,
                'text'     => $this->content_to_text($content),
                'modified' => is_int($modified) ? $modified : 0,
            ];
        }

        $this->content_sample = $sample;

        return $sample;
    }

    /**
     * Shared shape for the content-sampled GEO checks: count how many posts in
     * the sample satisfy a predicate and grade it on the coverage thresholds
     * the Content category already uses.
     *
     * @since 2.5.0
     *
     * @param string   $label      Check label.
     * @param callable $predicate  Receives one sample row, returns bool.
     * @param string   $pass_text  sprintf template: 1 = matching, 2 = sampled.
     * @param string   $fail_text  sprintf template: 1 = missing, 2 = sampled.
     * @param string   $how_to_fix Advice shown on a warning/fail.
     * @param string   $empty_text Message when the site has no content yet.
     * @return array
     */
    private function coverage_check(
        string $label,
        callable $predicate,
        string $pass_text,
        string $fail_text,
        string $how_to_fix,
        string $empty_text
    ): array {
        // A page whose body is a shortcode or a builder layout leaves no
        // extractable text, so every prose-shaped question here answers "no"
        // for it — Cart, Checkout, My account and Shop would drag the category
        // down over content nobody wants quoted in an AI answer. Skipping them
        // is deliberate: this grades the pages that could be cited.
        $sample = [];
        foreach ($this->get_content_sample() as $row) {
            if ('' !== $row['text']) {
                $sample[] = $row;
            }
        }

        $total = count($sample);

        if (0 === $total) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => $empty_text,
            ];
        }

        $matching = 0;
        $failing  = [];
        foreach ($sample as $row) {
            if ($predicate($row)) {
                $matching++;
            } else {
                $failing[] = $row['id'];
            }
        }

        $coverage = (int) round(($matching / $total) * 100);
        $value    = sprintf('%d/%d', $matching, $total);

        if ($coverage >= self::COVERAGE_PASS) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => sprintf($pass_text, $matching, $total),
                'value'   => $value,
            ];
        }

        // A count alone leaves the user to guess which pages it means, so the
        // finding names them.
        return [
            'label'          => $label,
            'status'         => $coverage >= self::COVERAGE_WARN ? self::WARNING : self::FAILED,
            'message'        => sprintf($fail_text, $total - $matching, $total),
            'how_to_fix'     => $how_to_fix,
            'value'          => $value,
            'affected_posts' => $this->affected_posts($failing),
        ];
    }

    /**
     * The posts a finding is about, with where to fix and where to view each.
     *
     * The edit link is built rather than taken from get_edit_post_link(), which
     * returns nothing without a user who can edit — and the analysis is cached
     * and can be computed outside a request.
     *
     * @since 2.6.0
     * @param int[] $post_ids Post ids, in sample order.
     * @return array<int,array{id: int, title: string, type: string, edit_url: string, url: string}>
     */
    private function affected_posts(array $post_ids): array {
        $posts = [];

        if ($post_ids && function_exists('_prime_post_caches')) {
            _prime_post_caches($post_ids, false, false);
        }

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $type_object = get_post_type_object($post->post_type);
            $title       = html_entity_decode((string) get_the_title($post), ENT_QUOTES, 'UTF-8');

            // An attachment's permalink is its attachment page, which core
            // redirects to the file on most sites; link the file itself.
            $url = 'attachment' === $post->post_type && function_exists('wp_get_attachment_url')
                ? (string) wp_get_attachment_url((int) $post->ID)
                : (string) get_permalink($post);

            $posts[] = [
                'id'       => (int) $post->ID,
                'title'    => '' !== trim($title) ? $title : __('(no title)', 'thinkrank'),
                'type'     => $type_object ? (string) $type_object->labels->singular_name : (string) $post->post_type,
                'edit_url' => admin_url('post.php?post=' . (int) $post->ID . '&action=edit'),
                'url'      => $url,
            ];
        }

        return $posts;
    }

    /**
     * The plain text of a post's content, with markup, blocks and shortcodes
     * reduced to the words a language model would actually read.
     *
     * @since 2.5.0
     * @param string $content Raw post content.
     * @return string
     */
    private function content_to_text(string $content): string {
        // Block delimiters are HTML comments, so strip_tags leaves their
        // attribute JSON behind as text and inflates every word count.
        $text = preg_replace('/<!--.*?-->/s', ' ', $content);
        $text = preg_replace('/\[[^\]]*\]/', ' ', (string) $text);
        $text = wp_strip_all_tags((string) $text);

        // `/u` makes preg_replace() return NULL on content that is not valid
        // UTF-8 — a latin1 install, a raw SQL import, a migrated dump — and
        // trim(null) is a TypeError under strict_types. run_checks() catches
        // Throwable and continues, so the check did not fail: it DISAPPEARED
        // from the audit, taking its weight with it and pushing the overall
        // score UP because a failing check had been removed rather than scored.
        // Fall back to the byte-wise collapse when the Unicode pass cannot read
        // the bytes; a slightly coarser word split is worth far more than a
        // check that silently deletes itself.
        $collapsed = preg_replace('/\s+/u', ' ', (string) $text);

        if (null === $collapsed) {
            $collapsed = preg_replace('/\s+/', ' ', (string) $text);
        }

        return trim((string) $collapsed);
    }

    /**
     * Word count of a string of plain text.
     *
     * @since 2.5.0
     * @param string $text Plain text.
     * @return int
     */
    private function word_count(string $text): int {
        if ('' === $text) {
            return 0;
        }

        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Whether AI answer engines are allowed to reach this site.
     *
     * Reads the robots.txt that is ACTUALLY served — physical file, custom
     * body or generated defaults — rather than the per-agent rule map alone,
     * because a hand-written `Disallow: /` for GPTBot blocks it just as
     * effectively as the toggle does, and a `User-agent: *` block reaches
     * every crawler on the list at once.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_ai_crawler_access(): array {
        $label   = __('AI answer engines can crawl your site', 'thinkrank');
        $blocked = $this->blocked_answer_agents();

        if (empty($blocked)) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('ChatGPT, Claude, Perplexity and Google AI Overviews can all reach your content and cite it.', 'thinkrank'),
            ];
        }

        $names = implode(', ', $blocked);
        // Every one of them blocked is a different situation from one stray
        // rule: the site cannot appear in AI answers at all. Counted against
        // the agents the registry actually knows, not the slug list: a slug
        // leaving AI_Crawlers can never be collected as blocked, so comparing
        // with the list would make this branch quietly unreachable.
        $known = count($this->known_answer_agents());
        $all   = $known > 0 && count($blocked) >= $known;

        return [
            'label'      => $label,
            'status'     => $all ? self::FAILED : self::WARNING,
            'message'    => $all
                /* translators: %s: comma-separated crawler names. */
                ? sprintf(__('Your robots.txt blocks every AI answer engine (%s), so your pages cannot be cited in AI answers at all.', 'thinkrank'), $names)
                /* translators: %s: comma-separated crawler names. */
                : sprintf(__('Your robots.txt blocks these AI answer engines: %s. Their assistants cannot read or cite your pages.', 'thinkrank'), $names),
            'how_to_fix' => __('Under Essential SEO → Crawling & AI Indexing → Robots.txt, set the answer-engine crawlers to Allow. Blocking the training-only crawlers (GPTBot, ClaudeBot, CCBot) is a separate choice and does not cost you citations.', 'thinkrank'),
            'value'      => $names,
        ];
    }

    /**
     * The answer-engine crawlers the served robots.txt disallows entirely.
     *
     * Public so the one-click fix can re-ask the question after writing the
     * rules, rather than reporting a success the served file contradicts.
     *
     * @since 2.5.0
     * @return string[] Crawler labels, empty when all are allowed.
     */
    public function blocked_answer_agents(): array {
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager') || !class_exists('ThinkRank\\SEO\\AI_Crawlers')) {
            return [];
        }

        $effective = (new Site_Identity_Manager())->get_effective_robots_txt();
        $groups    = $this->parse_robots_disallow_all((string) ($effective['content'] ?? ''));

        if (empty($groups)) {
            return [];
        }

        $blocked = [];

        foreach ($this->known_answer_agents() as $agent) {
            $token = strtolower((string) $agent['token']);
            // A group naming the agent wins over the wildcard group, which is
            // how robots.txt precedence works: the most specific matching
            // group is the only one that applies.
            $denied = array_key_exists($token, $groups) ? $groups[$token] : ($groups['*'] ?? false);

            if ($denied) {
                $blocked[] = $agent['label'];
            }
        }

        return $blocked;
    }

    /**
     * The answer-engine crawlers the AI_Crawlers registry actually knows.
     *
     * The slug list is ThinkRank's editorial position on which crawlers decide
     * whether a site can be cited; the registry is what carries their tokens
     * and labels. Everything that counts answer engines counts these, so a slug
     * that ever leaves the registry drops out of the check and its totals
     * together instead of skewing one against the other.
     *
     * @since 2.5.0
     * @return array<string,array> Registry entries keyed by slug.
     */
    private function known_answer_agents(): array {
        if (!class_exists('ThinkRank\\SEO\\AI_Crawlers')) {
            return [];
        }

        $agents = AI_Crawlers::all();
        $known  = [];

        foreach (self::GEO_ANSWER_AGENTS as $slug) {
            if (isset($agents[$slug]['token'], $agents[$slug]['label'])) {
                $known[$slug] = $agents[$slug];
            }
        }

        return $known;
    }

    /**
     * Map a robots.txt body to `user-agent => disallows everything`.
     *
     * Consecutive `User-agent:` lines open one shared group, so the agents
     * listed above a `Disallow: /` all inherit it. An agent that appears with
     * narrower rules is recorded as false rather than omitted — otherwise it
     * would fall through to the wildcard group it is meant to override.
     *
     * @since 2.5.0
     * @param string $body Robots.txt content.
     * @return array<string,bool>
     */
    private function parse_robots_disallow_all(string $body): array {
        $groups  = [];
        $current = [];
        // Whether the next `User-agent:` continues this group or opens a new
        // one. Rules close a group; another agent line before any rule does not.
        $collecting = true;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if ('' === $line || false === strpos($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field           = strtolower($field);

            if ('user-agent' === $field) {
                if (!$collecting) {
                    $current    = [];
                    $collecting = true;
                }

                $agent = strtolower($value);
                if ('' !== $agent) {
                    $current[] = $agent;
                    if (!isset($groups[$agent])) {
                        $groups[$agent] = false;
                    }
                }

                continue;
            }

            $collecting = false;

            // `/` is the canonical full block; `/*` is the same instruction
            // written for a wildcard-aware crawler, and every answer engine on
            // the list is one.
            if ('disallow' === $field && ('/' === $value || '/*' === $value)) {
                foreach ($current as $agent) {
                    $groups[$agent] = true;
                }
            }
        }

        return $groups;
    }

    /**
     * /llms.txt should be published — it is the one file whose entire purpose
     * is telling an AI assistant what this site is and what to read.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_llms_txt(): array {
        $label = __('llms.txt is published', 'thinkrank');

        if (!class_exists('ThinkRank\\SEO\\LLMs_Txt_Manager')) {
            return [
                'label'   => $label,
                'status'  => self::WARNING,
                'message' => __('The llms.txt module is unavailable, so this could not be checked.', 'thinkrank'),
            ];
        }

        $manager = new LLMs_Txt_Manager();

        if ($manager->is_published()) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('Your llms.txt is published, so AI assistants have a summary of your site and its key pages.', 'thinkrank'),
                'value'   => home_url('/llms.txt'),
            ];
        }

        return [
            'label'      => $label,
            'status'     => self::FAILED,
            'message'    => __('No llms.txt is published. It is the file AI assistants read to learn what your site is about and which pages matter.', 'thinkrank'),
            'how_to_fix' => __('Fill in the fields under Essential SEO → Crawling & AI Indexing → LLMs.txt and publish it.', 'thinkrank'),
        ];
    }

    /**
     * Answer-shaped schema — FAQ, HowTo or Q&A — is what lets an engine lift a
     * question and its answer as a pair instead of guessing at prose.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_answer_ready_schema(): array {
        $label = __('Answer-ready structured data', 'thinkrank');

        if ($this->has_answer_schema()) {
            return [
                'label'   => $label,
                'status'  => self::PASSED,
                'message' => __('Your site publishes FAQ, How-To or Q&A structured data, which AI answers can quote question-and-answer pairs from directly.', 'thinkrank'),
            ];
        }

        $how_to_fix = __('Add a ThinkRank FAQ or How-To block, widget or element to your key pages, or enable the FAQPage / HowTo schema types under Essential SEO → Schema.', 'thinkrank');

        // Article/WebPage schema still tells an engine what the page is; the
        // gap is the answer pairing, not structured data as a whole.
        if ($this->schema_is_output()) {
            return [
                'label'      => $label,
                'status'     => self::WARNING,
                'message'    => __('Your pages publish Article or WebPage structured data, but no FAQ, How-To or Q&A schema. Those are the types AI answers quote from.', 'thinkrank'),
                'how_to_fix' => $how_to_fix,
            ];
        }

        return [
            'label'      => $label,
            'status'     => self::FAILED,
            'message'    => __('Your pages publish no structured data at all, so an AI assistant has to infer what each page is from its prose.', 'thinkrank'),
            'how_to_fix' => $how_to_fix,
        ];
    }

    /**
     * Whether the site publishes FAQ / HowTo / Q&A structured data.
     *
     * Three sources, because three things emit it: the Schema Management
     * System's enabled types, a per-post-type schema_type in Global SEO, and
     * ThinkRank's own FAQ/HowTo blocks, which output their schema from the
     * block itself with nothing to configure.
     *
     * @since 2.5.0
     * @return bool
     */
    private function has_answer_schema(): bool {
        $answer_types = ['faqpage', 'faq', 'howto', 'qapage'];

        if (class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
            $settings = (new Schema_Management_System())->get_settings('site', null);

            if (is_array($settings) && !empty($settings['enabled'])) {
                $enabled = $settings['enabled_schema_types'] ?? [];
                if (is_array($enabled)) {
                    foreach ($enabled as $type) {
                        if (in_array(strtolower((string) $type), $answer_types, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        $global = get_option('thinkrank_global_seo_settings', []);
        if (is_array($global)) {
            foreach ($global as $per_type) {
                $type = is_array($per_type) ? strtolower((string) ($per_type['schema_type'] ?? '')) : '';
                if ('' !== $type && in_array($type, $answer_types, true)) {
                    return true;
                }
            }
        }

        // The blocks carry their own schema, so a single page using one is a
        // true positive even with every schema setting untouched.
        foreach ($this->get_content_sample() as $row) {
            if ($this->has_answer_content((int) ($row['id'] ?? 0), (string) $row['content'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one post carries a ThinkRank FAQ or How-To that emits schema.
     *
     * Four surfaces, because `Schema_Graph` collects from four: the Gutenberg
     * block in `post_content`, the Elementor widget, the Bricks element and the
     * Beaver Builder module — the last three living in postmeta. Reading only
     * the block would tell a site whose FAQs are built in a page builder that it
     * publishes no FAQ schema while the graph is publishing exactly that.
     *
     * @since 2.5.0
     * @param int    $post_id Post to inspect; 0 skips the builder surfaces.
     * @param string $content Raw post content.
     * @return bool
     */
    private function has_answer_content(int $post_id, string $content): bool {
        if (false !== strpos($content, 'wp:thinkrank/faq')
            || false !== strpos($content, 'wp:thinkrank/howto')) {
            return true;
        }

        if ($post_id <= 0) {
            return false;
        }

        // Elementor stores its tree as JSON, so the widget name appears verbatim.
        $elementor = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($elementor)
            && (false !== strpos($elementor, '"' . self::ANSWER_FAQ_NAME . '"')
                || false !== strpos($elementor, '"' . self::ANSWER_HOWTO_NAME . '"'))) {
            return true;
        }

        // Bricks: the tree it will actually render, resolved the same way
        // Schema_Graph resolves it, so templates and components are covered.
        if ($this->bricks_has_answer_element($post_id)) {
            return true;
        }

        // Beaver Builder keeps its layout in postmeta as a map of node objects.
        $layout = get_post_meta($post_id, '_fl_builder_data', true);
        if (is_array($layout)) {
            foreach ($layout as $node) {
                $settings = is_object($node) ? ($node->settings ?? null) : ($node['settings'] ?? null);
                $settings = is_object($settings) ? get_object_vars($settings) : $settings;
                $type     = is_array($settings) ? (string) ($settings['type'] ?? '') : '';

                if (self::ANSWER_FAQ_NAME === $type || self::ANSWER_HOWTO_NAME === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a Bricks-rendered post holds a ThinkRank FAQ or How-To element.
     *
     * @since 2.5.0
     * @param int $post_id Post to inspect.
     * @return bool
     */
    private function bricks_has_answer_element(int $post_id): bool {
        if (!class_exists('ThinkRank\\SEO\\Builder_Content')) {
            $file = THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
            if (!file_exists($file)) {
                return false;
            }
            require_once $file;
        }

        foreach (Builder_Content::bricks_tree($post_id) as $element) {
            $name = is_array($element) ? (string) ($element['name'] ?? '') : '';

            if (self::ANSWER_FAQ_NAME === $name || self::ANSWER_HOWTO_NAME === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pages should open with a short, self-contained answer an engine can lift.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_direct_answer(): array {
        return $this->coverage_check(
            __('Pages open with a direct answer', 'thinkrank'),
            function (array $row): bool {
                $words = $this->word_count($this->opening_passage($row['content']));

                return $words >= self::GEO_ANSWER_MIN_WORDS && $words <= self::GEO_ANSWER_MAX_WORDS;
            },
            /* translators: 1: matching posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages open with a concise, quotable answer.', 'thinkrank'),
            /* translators: 1: posts without one, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages do not open with a concise answer. AI assistants quote the first self-contained passage they find, and a long preamble gives them nothing to lift.', 'thinkrank'),
            __('Open each page with a 40–60 word paragraph that answers its title directly, before any background or introduction.', 'thinkrank'),
            __('No published content to check yet.', 'thinkrank')
        );
    }

    /**
     * The first block of prose in a post, before any heading.
     *
     * @since 2.5.0
     * @param string $content Raw post content.
     * @return string Plain text of the opening passage.
     */
    private function opening_passage(string $content): string {
        // Cut at the first heading: everything above it is the intro, and an
        // intro that runs past a heading is not a direct answer either way.
        $chunks = preg_split('/<h[1-6][^>]*>/i', $content, 3) ?: [];
        $intro  = (string) ($chunks[0] ?? $content);

        $passage = $this->first_paragraph_text($intro);
        if ('' !== $passage) {
            return $passage;
        }

        $text = $this->content_to_text($intro);

        // A page that opens with its heading has nothing above it, so the chunk
        // read so far is empty and the answer sits directly under that heading.
        // Scoring it as a missing opening would fail exactly the pages written
        // as "question, then answer" — the shape this check exists to reward.
        if ('' === trim($text) && isset($chunks[1])) {
            // Drop the heading's own text, which the split left at the head of
            // the next chunk, so a long heading cannot pose as the answer.
            $after = preg_replace('/^.*?<\/h[1-6]>/is', '', (string) $chunks[1], 1);
            $after = null === $after ? (string) $chunks[1] : $after;

            $passage = $this->first_paragraph_text($after);
            if ('' !== $passage) {
                return $passage;
            }

            return $this->content_to_text($after);
        }

        return $text;
    }

    /**
     * The first paragraph of a chunk of content with enough words to be a
     * passage rather than a caption or a stray line.
     *
     * Only the first one counts — a three-paragraph intro is exactly the
     * preamble the direct-answer check is looking for.
     *
     * @since 2.5.0
     * @param string $chunk Raw content fragment.
     * @return string Plain text, or '' when the chunk holds no paragraph.
     */
    private function first_paragraph_text(string $chunk): string {
        $paragraphs = preg_split('/<\/p>|\R{2,}/', $chunk) ?: [];

        foreach ($paragraphs as $paragraph) {
            $candidate = $this->content_to_text((string) $paragraph);
            if ($this->word_count($candidate) >= 5) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Question-shaped H2/H3 headings map a page onto the questions people
     * actually ask an assistant.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_question_headings(): array {
        return $this->coverage_check(
            __('Headings phrased as questions', 'thinkrank'),
            function (array $row): bool {
                return $this->has_question_heading($row['content']);
            },
            /* translators: 1: matching posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages use question-style headings.', 'thinkrank'),
            /* translators: 1: posts without one, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages have no question-style heading. Assistants match a user\'s question against your headings first.', 'thinkrank'),
            __('Phrase at least one H2 or H3 per page as the question it answers — "How does X work?" rather than "Overview".', 'thinkrank'),
            __('No published content to check yet.', 'thinkrank')
        );
    }

    /**
     * Whether any H2/H3 in the content reads as a question.
     *
     * @since 2.5.0
     * @param string $content Raw post content.
     * @return bool
     */
    private function has_question_heading(string $content): bool {
        if (!preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $content, $matches)) {
            return false;
        }

        $starters = ['what', 'why', 'how', 'when', 'where', 'who', 'which', 'can', 'do', 'does', 'is', 'are', 'should', 'will'];

        foreach ($matches[1] as $heading) {
            $text = $this->content_to_text((string) $heading);

            if ('' === $text) {
                continue;
            }

            if ('?' === substr($text, -1)) {
                return true;
            }

            // A question mark is the reliable signal, but plenty of good
            // question headings drop it ("How image search works").
            $first = strtolower((string) strtok($text, " \t\n"));
            if (in_array($first, $starters, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lists and tables are the shapes an engine extracts most reliably.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_structured_content(): array {
        return $this->coverage_check(
            __('Content uses lists or tables', 'thinkrank'),
            static function (array $row): bool {
                return (bool) preg_match('/<(ul|ol|table)[\s>]/i', $row['content']);
            },
            /* translators: 1: matching posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages present information in lists or tables.', 'thinkrank'),
            /* translators: 1: posts without one, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages are unbroken prose. Steps, comparisons and specifications are extracted far more reliably from a list or table.', 'thinkrank'),
            __('Break steps, comparisons and specifications out of the paragraphs into list or table blocks.', 'thinkrank'),
            __('No published content to check yet.', 'thinkrank')
        );
    }

    /**
     * Thin pages are not cited: there is nothing in them worth quoting.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_content_depth(): array {
        return $this->coverage_check(
            __('Pages have enough depth to cite', 'thinkrank'),
            function (array $row): bool {
                return $this->word_count($row['text']) >= self::GEO_DEPTH_MIN_WORDS;
            },
            /* translators: 1: matching posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages have enough substance for an assistant to cite.', 'thinkrank'),
            /* translators: 1: thin posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages are under 300 words. An assistant with a choice of sources rarely quotes the thinnest one.', 'thinkrank'),
            __('Expand thin pages so each one answers its topic completely, or merge them into a page that does.', 'thinkrank'),
            __('No published content to check yet.', 'thinkrank')
        );
    }

    /**
     * Answer engines strongly prefer recently-revised sources.
     *
     * @since 2.5.0
     * @return array
     */
    public function check_content_freshness(): array {
        $cutoff = time() - self::GEO_FRESHNESS_MAX_AGE;

        return $this->coverage_check(
            __('Content has been updated recently', 'thinkrank'),
            static function (array $row) use ($cutoff): bool {
                return $row['modified'] > 0 && $row['modified'] >= $cutoff;
            },
            /* translators: 1: matching posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages were revised within the last year.', 'thinkrank'),
            /* translators: 1: stale posts, 2: sampled posts. */
            __('%1$d of your %2$d most recent pages have not been revised in over a year. AI answers favour sources that look current.', 'thinkrank'),
            __('Review your most important pages, update what has changed, and save them so their modified date reflects the revision.', 'thinkrank'),
            __('No published content to check yet.', 'thinkrank')
        );
    }
}
