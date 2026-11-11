<?php
/**
 * Rendered-page sampler.
 *
 * @package ThinkRank\Diagnostics
 */

declare(strict_types=1);

namespace ThinkRank\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches a small, representative set of this site's own pages as HTML.
 *
 * The Site SEO Analyzer is crawl-free by design: it reads settings and stored
 * `post_content`, which costs nothing and works on any host. That design has
 * one blind spot, and it is not a small one. On a page-builder site the stored
 * content is empty or a bare shortcode, so anything that exists only in the
 * rendered document — an icon link with no text, an image link whose alt is
 * blank, a `<button>` with a sprite inside it — is invisible to every check
 * (#769, and the half of #339 that was split out).
 *
 * So: a sample, not a crawl. Three URLs rather than a sitemap walk, because
 * the questions this answers are about how a site is *built* — its header, its
 * menus, its template chrome — and those repeat on every page. A fourth URL
 * from the same theme almost never says anything the first three did not, and
 * the cost of being wrong about that is an audit that times out on shared
 * hosting.
 *
 * Shared deliberately. #339's rendered-site audit and #769's agent
 * accessibility checks both need exactly this, and two copies of a loopback
 * fetcher would drift on timeouts, redirects and TLS the first time one of
 * them hit a real host.
 *
 * @since 2.21.3
 */
class Page_Sampler {

    /**
     * Transient holding the last sampled pages.
     *
     * @var string
     */
    private const CACHE_KEY = 'thinkrank_page_sample';

    /**
     * How long a sample stays usable, in seconds.
     *
     * Longer than the analyzer's own hour on purpose: the analysis is cheap to
     * recompute and a user may re-run it several times while fixing things,
     * and re-fetching the site over HTTP on each of those runs is the one part
     * that is not cheap. The pages a sample describes — templates, menus,
     * header markup — change on the scale of a theme edit, not an audit run.
     *
     * @var int
     */
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * URLs fetched per sample, before filtering.
     *
     * @var int
     */
    private const SAMPLE_SIZE = 3;

    /**
     * Seconds to wait for one page.
     *
     * @var int
     */
    private const FETCH_TIMEOUT = 5;

    /**
     * Largest response body kept, in bytes.
     *
     * A sample is held in a transient and read by every check that wants it, so
     * an unbounded body would put a multi-megabyte page-builder document into
     * the options table. Truncation costs the tail of one page; the header,
     * navigation and main content that these checks are about are long past.
     *
     * @var int
     */
    private const MAX_BODY_BYTES = 512000;

    /**
     * The sampled pages, fetching them when no usable sample is stored.
     *
     * @since 2.21.3
     *
     * @param bool $use_cache Whether a stored sample may be returned.
     * @return array<int, array{url: string, html: string}> Empty when nothing could be fetched.
     */
    public static function pages(bool $use_cache = true): array {
        if ($use_cache) {
            $cached = get_transient(self::CACHE_KEY);

            if (is_array($cached) && ($cached['home'] ?? '') === home_url('/')) {
                return is_array($cached['pages'] ?? null) ? $cached['pages'] : [];
            }
        }

        $pages = [];

        foreach (self::urls() as $url) {
            $html = self::fetch($url);

            if (is_wp_error($html)) {
                continue;
            }

            $pages[] = [
                'url'  => $url,
                'html' => $html,
            ];
        }

        // Stored even when empty, so a host that blocks loopback requests is
        // asked once every six hours rather than on every audit.
        set_transient(
            self::CACHE_KEY,
            [
                'home'       => home_url('/'),
                'pages'      => $pages,
                'sampled_at' => time(),
            ],
            self::CACHE_TTL
        );

        return $pages;
    }

    /**
     * Drop the stored sample.
     *
     * @since 2.21.3
     * @return void
     */
    public static function flush_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * The URLs a sample covers.
     *
     * The front page first, because it is the one URL every site has and the
     * one most likely to carry the site's header, menu and footer chrome. Then
     * the most recent published entry of each public post type, which is how a
     * template that only ever renders for a custom post type gets looked at.
     *
     * @since 2.21.3
     * @return string[]
     */
    public static function urls(): array {
        $urls = [home_url('/')];

        foreach (self::sampled_post_types() as $post_type) {
            if (count($urls) >= self::sample_size()) {
                break;
            }

            $posts = get_posts([
                'post_type'        => $post_type,
                'post_status'      => 'publish',
                'numberposts'      => 1,
                'fields'           => 'ids',
                'orderby'          => 'date',
                'order'            => 'DESC',
                'has_password'     => false,
                'suppress_filters' => false,
            ]);

            if (empty($posts)) {
                continue;
            }

            $permalink = get_permalink((int) $posts[0]);

            if (is_string($permalink) && '' !== $permalink && !in_array($permalink, $urls, true)) {
                $urls[] = $permalink;
            }
        }

        $urls = array_slice($urls, 0, self::sample_size());

        /**
         * Filters the URLs the rendered-page sampler fetches.
         *
         * Returning a longer list is allowed and is the supported way to widen
         * a rendered audit; every URL is fetched over HTTP, so the cost is the
         * caller's to choose.
         *
         * @since 2.21.3
         *
         * @param string[] $urls URLs to sample.
         */
        $urls = apply_filters('thinkrank_page_sample_urls', $urls);

        return array_values(array_filter(array_map('strval', (array) $urls)));
    }

    /**
     * Post types worth a sample, in a stable order.
     *
     * `page` before `post` because a site's page templates carry more chrome
     * than its single-post template, and both before whatever else is
     * registered, so adding a custom post type never changes which two URLs a
     * three-URL sample already covered.
     *
     * @return string[]
     */
    private static function sampled_post_types(): array {
        $types = get_post_types(['public' => true], 'names');

        if (!is_array($types)) {
            return ['page', 'post'];
        }

        unset($types['attachment']);

        // The sitemap's own test for "is this site content": a public post type
        // that opts out of search (Elementor's `elementor_library`, Templately's
        // store) holds templates, and a sample slot spent on one audits markup
        // no visitor sees.
        $types = array_filter(
            $types,
            static fn($type): bool => \ThinkRank\SEO\Content_Type_Settings::sitemap_accepts_post_type((string) $type)
        );

        $ordered = [];

        foreach (['page', 'post'] as $preferred) {
            if (isset($types[$preferred])) {
                $ordered[] = $preferred;
                unset($types[$preferred]);
            }
        }

        return array_merge($ordered, array_values($types));
    }

    /**
     * How many URLs one sample covers.
     *
     * @return int
     */
    private static function sample_size(): int {
        /**
         * Filters how many URLs the rendered-page sampler fetches.
         *
         * @since 2.21.3
         *
         * @param int $size Number of URLs.
         */
        $size = (int) apply_filters('thinkrank_page_sample_size', self::SAMPLE_SIZE);

        return max(1, min(20, $size));
    }

    /**
     * Fetch one of this site's own URLs.
     *
     * Anonymous — no cookies — so the sample is what a visitor or an agent
     * sees rather than what an administrator sees, and `sslverify` off because
     * this is a self-request: staging and local installs routinely run on
     * certificates this host does not trust, and failing there would leave the
     * sites most likely to be misconfigured unchecked. Mirrors how WP Site
     * Health runs its own loopback probes.
     *
     * The caller may override the request budget. The sampler's own numbers
     * are tuned for three pages held in a transient; the schema-conflict scan
     * reads one page, keeps nothing, and has always allowed longer and read the
     * whole document — a JSON-LD block a foreign plugin prints in the footer of
     * a page-builder page sits past any truncation, and losing it reports a
     * conflicting plugin as no conflict at all.
     *
     * @since 2.21.3
     *
     * @param string               $url  URL on this site.
     * @param array<string, mixed> $args Optional. `timeout`, `max_bytes` (0 for
     *                                   no truncation) and `user_agent`.
     * @return string|\WP_Error Response body, or the failure.
     */
    public static function fetch(string $url, array $args = []) {
        if (!function_exists('wp_remote_get')) {
            return new \WP_Error(
                'thinkrank_http_unavailable',
                __('The WordPress HTTP API is not available in this context.', 'thinkrank')
            );
        }

        $timeout    = max(1, (int) ($args['timeout'] ?? self::FETCH_TIMEOUT));
        $max_bytes  = array_key_exists('max_bytes', $args)
            ? max(0, (int) $args['max_bytes'])
            : self::MAX_BODY_BYTES;
        $user_agent = (string) ($args['user_agent'] ?? 'ThinkRank-PageSampler/1.0');

        $request = [
            'timeout'     => $timeout,
            'sslverify'   => false,
            'redirection' => 3,
            'user-agent'  => $user_agent,
            'headers'     => ['Cache-Control' => 'no-cache'],
        ];

        // Let the transport stop reading at the budget. Truncating afterwards
        // alone still held the whole response in memory first, once per
        // sampled URL. The substr() below stays as a backstop for a transport
        // that ignores the limit.
        if ($max_bytes > 0) {
            $request['limit_response_size'] = $max_bytes;
        }

        $response = wp_remote_get($url, $request);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if (200 !== $code) {
            return new \WP_Error(
                'thinkrank_sample_http_error',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The page returned HTTP %d, so it could not be read.', 'thinkrank'),
                    $code
                )
            );
        }

        $body = (string) wp_remote_retrieve_body($response);

        if ('' === $body) {
            return new \WP_Error(
                'thinkrank_sample_empty_body',
                __('The page returned an empty response, so it could not be read.', 'thinkrank')
            );
        }

        if (0 === $max_bytes || strlen($body) <= $max_bytes) {
            return $body;
        }

        return substr($body, 0, $max_bytes);
    }
}
