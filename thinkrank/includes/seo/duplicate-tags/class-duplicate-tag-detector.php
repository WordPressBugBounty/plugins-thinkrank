<?php
/**
 * Duplicate Tag Guard: check what a few pages actually serve.
 *
 * @package ThinkRank\SEO\Duplicate_Tags
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Duplicate_Tags;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Duplicate Tag Detector
 *
 * Fetches the home page and the latest post the way a visitor gets them and
 * reports the head tags printed twice (#733) — on demand and cached for an
 * hour, never on a page view, which is the lesson of #402.
 *
 * Who printed a foreign tag is a guess, and reported as one: the comment a
 * plugin prints around its head output, or the class it puts on its tags.
 *
 * @since 2.23.0
 */
class Duplicate_Tag_Detector {

    private const CACHE_KEY = 'thinkrank_duplicate_tags_scan';

    private const CACHE_TTL = HOUR_IN_SECONDS;

    private const TIMEOUT = 10;

    private const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Query argument that asks page caches for a fresh copy, so a switch
     * just flipped is what the check sees.
     */
    private const CACHE_BUST_ARG = 'thinkrank_dt';

    /**
     * Who prints head tags besides us: name => needles matched against a
     * tag's label (the comment above it and its class). The ten most common
     * Open Graph emitters, plus the SEO plugins a site migrating to
     * ThinkRank still has active.
     *
     * @var array<string, string[]>
     */
    private const SOURCES = [
        'Yoast SEO'         => ['yoast'],
        'Rank Math'         => ['rank math', 'rank-math', 'rankmath'],
        'All in One SEO'    => ['all in one seo', 'aioseo'],
        'SEOPress'          => ['seopress'],
        'The SEO Framework' => ['the seo framework'],
        'Slim SEO'          => ['slim seo', 'slim-seo'],
        'Squirrly SEO'      => ['squirrly'],
        'Jetpack'           => ['jetpack'],
        'WPSSO'             => ['wpsso'],
        'Social Warfare'    => ['social warfare', 'social-warfare'],
        'WooCommerce'       => ['woocommerce'],
    ];

    /**
     * Page fetcher: (url) => html string or \WP_Error. Tests inject one.
     *
     * @var callable|null
     */
    private $fetch;

    /**
     * @param callable|null $fetch Fetcher (tests).
     */
    public function __construct(?callable $fetch = null) {
        $this->fetch = $fetch;
    }

    /**
     * The report, from the cache unless asked to look again.
     *
     * @param bool $use_cache Whether a cached report may be returned.
     * @return array{checked_at: int, pages: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function scan(bool $use_cache = true): array {
        if ($use_cache) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $pages = [];
        foreach ($this->urls() as $url) {
            $html    = null !== $this->fetch ? call_user_func($this->fetch, $url) : $this->fetch_page($url);
            $pages[] = is_wp_error($html)
                ? ['url' => $url, 'ok' => false, 'error' => $html->get_error_message(), 'duplicates' => [], 'removed' => []]
                : self::analyze_page($url, (string) $html, current_theme_supports('title-tag'));
        }

        $report = [
            'checked_at' => time(),
            'pages'      => $pages,
            'summary'    => self::summarize($pages),
        ];

        set_transient(self::CACHE_KEY, $report, self::CACHE_TTL);

        return $report;
    }

    /**
     * Drop the cached report. Hooked to anything that changes what a page
     * prints: a plugin or theme switched or updated, a guard switch flipped.
     *
     * @return void
     */
    public static function flush_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * One page's duplicates.
     *
     * @param string $url             Page URL.
     * @param string $html            Page markup.
     * @param bool   $core_prints_title Whether WordPress prints ThinkRank's `<title>` (title-tag support).
     * @return array{url: string, ok: bool, error: string, duplicates: array<int, array<string, mixed>>, removed: array<string, int>}
     */
    public static function analyze_page(string $url, string $html, bool $core_prints_title): array {
        $end  = Head_Tags::head_end($html);
        $head = null === $end ? $html : substr($html, 0, $end);

        $duplicates = [];
        foreach (Head_Tags::duplicates(Head_Tags::scan($head)) as $row) {
            // A fetched page cannot say which <title> is ours — the check does
            // not know the title ThinkRank produced for that URL. With
            // title-tag support, WordPress printed one of them from our answer.
            if ('title' === $row['key'] && $core_prints_title && 0 === $row['ours']) {
                $row['ours']    = 1;
                $row['foreign'] = $row['count'] - 1;
            }

            $row['removable'] = $row['ours'] > 0 && $row['foreign'] > 0;
            $row['sources']   = self::sources($row['labels']);
            unset($row['labels']);
            $duplicates[] = $row;
        }

        return [
            'url'        => $url,
            'ok'         => true,
            'error'      => '',
            'duplicates' => $duplicates,
            'removed'    => Head_Tags::read_marker($head),
        ];
    }

    /**
     * Totals across the pages checked.
     *
     * @param array<int, array<string, mixed>> $pages Pages.
     * @return array{checked: int, failed: int, duplicates: int, critical: int, removed: int, removable_families: string[]}
     */
    public static function summarize(array $pages): array {
        $summary = ['checked' => 0, 'failed' => 0, 'duplicates' => 0, 'critical' => 0, 'removed' => 0, 'removable_families' => []];

        foreach ($pages as $page) {
            if (!$page['ok']) {
                $summary['failed']++;
                continue;
            }

            $summary['checked']++;
            $summary['removed'] += (int) array_sum($page['removed']);
            foreach ($page['duplicates'] as $row) {
                $summary['duplicates']++;
                $summary['critical'] += $row['critical'] ? 1 : 0;
                if ($row['removable'] && !in_array($row['family'], $summary['removable_families'], true)) {
                    $summary['removable_families'][] = $row['family'];
                }
            }
        }

        // In the order the settings list them.
        $summary['removable_families'] = array_values(array_intersect(Head_Tags::FAMILIES, $summary['removable_families']));

        return $summary;
    }

    /**
     * Who printed the foreign copies, as far as can be told.
     *
     * @param string[] $labels Labels of the foreign tags.
     * @return string[] Names; empty when nothing identified them.
     */
    private static function sources(array $labels): array {
        $names = [];
        foreach ($labels as $label) {
            $label = strtolower($label);
            foreach (self::SOURCES as $name => $needles) {
                foreach ($needles as $needle) {
                    if (false !== strpos($label, $needle) && !in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * The pages to check: the home page, and the latest post, which carries
     * the most page-level tags on most sites.
     *
     * @return string[]
     */
    private function urls(): array {
        $urls = [home_url('/')];

        $posts = get_posts([
            'numberposts'      => 1,
            'post_status'      => 'publish',
            'post_type'        => 'post',
            'has_password'     => false,
            'suppress_filters' => false,
            'fields'           => 'ids',
        ]);
        if (!empty($posts)) {
            $permalink = get_permalink((int) $posts[0]);
            if (is_string($permalink) && '' !== $permalink) {
                $urls[] = $permalink;
            }
        }

        /**
         * Filter the pages the duplicate head tag check fetches.
         *
         * @since 2.23.0
         *
         * @param string[] $urls Front-end URLs.
         */
        $urls = apply_filters('thinkrank_duplicate_tags_scan_urls', $urls);

        return array_values(array_unique(array_filter(array_map('strval', (array) $urls))));
    }

    /**
     * Fetch a page anonymously from this server.
     *
     * @param string $url URL.
     * @return string|\WP_Error
     */
    private function fetch_page(string $url) {
        $response = wp_remote_get(add_query_arg(self::CACHE_BUST_ARG, (string) time(), $url), [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'user-agent'          => 'ThinkRank-DuplicateTagCheck/' . THINKRANK_VERSION . '; ' . home_url('/'),
            'limit_response_size' => self::MAX_BYTES,
            // What core's own Site Health loopback passes: a local site with a
            // self-signed certificate is still the site.
            'sslverify'           => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter.
            'headers'             => [
                'Cache-Control' => 'no-cache',
                'Accept'        => 'text/html',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new \WP_Error('thinkrank_duplicate_tags_http', sprintf(
                /* translators: %d: HTTP status code */
                __('The page answered with HTTP %d, so it could not be checked.', 'thinkrank'),
                $code
            ));
        }

        return (string) wp_remote_retrieve_body($response);
    }
}
