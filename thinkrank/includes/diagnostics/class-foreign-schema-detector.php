<?php
/**
 * Foreign JSON-LD detector.
 *
 * @package ThinkRank\Diagnostics
 */

declare(strict_types=1);

namespace ThinkRank\Diagnostics;

use ThinkRank\Core\SEO_Plugin_Detector;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finds JSON-LD on a rendered page that ThinkRank did not emit.
 *
 * #355 gave ThinkRank a single arbitrated `@graph` so its own emitters stop
 * contradicting each other on one URL. Nothing arbitrates against schema
 * produced by *other* plugins: when a second schema-producing plugin is active
 * both publish page-level entities for the same URL with no coordination, which
 * is the same failure #355 closed, only sourced externally (#447).
 *
 * This class detects that and nothing else. It warns; it never merges ThinkRank's
 * output into another plugin's graph and never suppresses the other plugin's
 * output. Merging is deliberately out of scope: ThinkRank is the primary SEO
 * plugin, and building for two active ones would endorse a configuration we do
 * not want users in and mean tracking competitors' graph structures as they change.
 *
 * Detection runs on demand from Site Health (see Schema_Conflict_Health_Check),
 * not on every pageview. Buffering the front end to scan it would put the work
 * on every anonymous request, which is how #402 happened.
 *
 * @since 2.9.0
 */
class Foreign_Schema_Detector {

    /**
     * Transient holding the last scan result.
     */
    private const CACHE_KEY = 'thinkrank_foreign_schema_scan';

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Timeout for the loopback fetch, in seconds.
     */
    private const FETCH_TIMEOUT = 10;

    /**
     * Signatures that attribute a foreign JSON-LD block to a named plugin.
     *
     * `class` matches a token in the script tag's class attribute, which is the
     * strongest signal available — the big four each tag their own block. `comment`
     * matches the HTML comment wrapper a plugin prints around its head output,
     * for the ones that carry no class.
     *
     * @var array<string, array{name:string, class:string[], comment:string[]}>
     */
    private const SIGNATURES = [
        'yoast' => [
            'name'    => 'Yoast SEO',
            'class'   => ['yoast-schema-graph'],
            'comment' => ['yoast seo plugin'],
        ],
        'rankmath' => [
            'name'    => 'Rank Math',
            'class'   => ['rank-math-schema', 'rank-math-schema-pro'],
            'comment' => ['rank math wordpress seo'],
        ],
        'aioseo' => [
            'name'    => 'All in One SEO',
            'class'   => ['aioseo-schema'],
            'comment' => ['all in one seo'],
        ],
        'seopress' => [
            'name'    => 'SEOPress',
            'class'   => ['seopress-schema'],
            'comment' => ['seopress'],
        ],
        'theseoframework' => [
            'name'    => 'The SEO Framework',
            'class'   => [],
            'comment' => ['the seo framework'],
        ],
        'slimseo' => [
            'name'    => 'Slim SEO',
            'class'   => ['slim-seo-schema'],
            'comment' => ['slim seo'],
        ],
        'woocommerce' => [
            'name'    => 'WooCommerce',
            'class'   => [],
            'comment' => ['woocommerce json-ld'],
        ],
    ];

    /**
     * Schema types worth warning about when both sides publish one.
     *
     * A duplicated `WebPage` or `Organization` is the conflict users get
     * penalised for. A second `SearchAction` or `ImageObject` is noise, so the
     * notice stays about entities a search engine reconciles per URL.
     *
     * @var string[]
     */
    private const PAGE_LEVEL_TYPES = [
        'Article',
        'BlogPosting',
        'NewsArticle',
        'BreadcrumbList',
        'CollectionPage',
        'ContactPage',
        'Event',
        'FAQPage',
        'HowTo',
        'ItemList',
        'LocalBusiness',
        'Organization',
        'Person',
        'Product',
        'ProfilePage',
        'Recipe',
        'SearchResultsPage',
        'SoftwareApplication',
        'VideoObject',
        'WebPage',
        'WebSite',
    ];

    /**
     * Run a scan, using the cached result when one is fresh.
     *
     * @param bool $use_cache Whether a cached result may be returned.
     * @return array<string, mixed> Scan report, see build_report().
     */
    public function scan(bool $use_cache = true): array {
        if ($use_cache) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url  = $this->representative_url();
        $html = $this->fetch($url);

        if (is_wp_error($html)) {
            $report = [
                'scanned_url' => $url,
                'error'       => $html->get_error_message(),
                'conflicts'   => [],
                'foreign'     => [],
                'own_types'   => [],
                'checked_at'  => time(),
            ];
        } else {
            $report = $this->analyze($html);
            $report['scanned_url'] = $url;
            $report['checked_at']  = time();
        }

        set_transient(self::CACHE_KEY, $report, self::CACHE_TTL);

        return $report;
    }

    /**
     * Drop the cached scan result.
     *
     * @return void
     */
    public static function flush_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Analyse a rendered HTML document for foreign JSON-LD.
     *
     * Kept separate from the fetch so it can be exercised against a fixture
     * without an HTTP request.
     *
     * @param string $html Rendered page HTML.
     * @return array{conflicts:array, foreign:array, own_types:string[]}
     */
    public function analyze(string $html): array {
        $blocks    = $this->extract_blocks($html);
        $own_types = [];
        $foreign   = [];

        foreach ($blocks as $block) {
            $types = $this->collect_types($this->decode($block['json']));

            if ($block['is_ours']) {
                $own_types = array_merge($own_types, $types);
                continue;
            }

            $source = $this->attribute($block);

            if (!isset($foreign[$source['slug']])) {
                $foreign[$source['slug']] = [
                    'slug'   => $source['slug'],
                    'name'   => $source['name'],
                    'guess'  => $source['guess'],
                    'types'  => [],
                    'blocks' => 0,
                ];
            }

            $foreign[$source['slug']]['types']  = array_merge($foreign[$source['slug']]['types'], $types);
            $foreign[$source['slug']]['blocks']++;
        }

        $own_types = $this->unique_types($own_types);
        $conflicts = [];

        foreach ($foreign as $slug => $entry) {
            $entry['types'] = $this->unique_types($entry['types']);
            $foreign[$slug] = $entry;

            $duplicated = array_values(array_intersect(
                $this->page_level_only($own_types),
                $this->page_level_only($entry['types'])
            ));

            if (!empty($duplicated)) {
                $conflicts[] = [
                    'slug'       => $slug,
                    'name'       => $entry['name'],
                    'guess'      => $entry['guess'],
                    'duplicated' => $duplicated,
                ];
            }
        }

        return [
            'conflicts' => $conflicts,
            'foreign'   => array_values($foreign),
            'own_types' => $own_types,
        ];
    }

    /**
     * Pull every `application/ld+json` block out of a document.
     *
     * Regex rather than DOMDocument: the scan runs against whatever a third
     * party emitted, and a malformed document must still yield the blocks that
     * did parse. Each block carries the raw tag and the comment immediately
     * preceding it, which is what attribution reads.
     *
     * @param string $html Rendered page HTML.
     * @return array<int, array{json:string, tag:string, preceding:string, is_ours:bool}>
     */
    private function extract_blocks(string $html): array {
        $pattern = '#<script\b([^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*)>(.*?)</script>#is';

        if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $blocks = [];

        foreach ($matches as $match) {
            $attributes = $match[1][0];
            $offset     = (int) $match[0][1];
            $preceding  = $this->preceding_comment($html, $offset);

            $blocks[] = [
                'json'      => $match[2][0],
                'tag'       => $attributes,
                'preceding' => $preceding,
                'is_ours'   => $this->is_ours($attributes, $preceding),
            ];
        }

        return $blocks;
    }

    /**
     * The HTML comment that ends just before a script block, if any.
     *
     * Only whitespace may sit between the comment and the tag, so an unrelated
     * comment further up the head is never treated as the block's label.
     *
     * A *closing* comment is not a label. Both ThinkRank and Yoast bracket their
     * head output in a matched pair, so the text immediately before a block is
     * very often the previous block's `<!-- /… -->` — reading that as the label
     * made every plugin's schema look like ThinkRank's the moment it happened to
     * be printed next.
     *
     * @param string $html   Rendered page HTML.
     * @param int    $offset Byte offset of the script tag.
     * @return string Comment body (without the delimiters), or an empty string.
     */
    private function preceding_comment(string $html, int $offset): string {
        $before = rtrim(substr($html, 0, $offset));

        if (substr($before, -3) !== '-->') {
            return '';
        }

        $start = strrpos($before, '<!--');

        if ($start === false) {
            return '';
        }

        $comment = trim(substr($before, $start + 4, -3));

        if ($comment !== '' && $comment[0] === '/') {
            return '';
        }

        return $comment;
    }

    /**
     * Whether a JSON-LD block was emitted by ThinkRank or ThinkRank Pro.
     *
     * Two markers, because the plugins have two kinds of emit point. Every
     * block ThinkRank writes from PHP carries a `data-thinkrank*` attribute
     * (`data-thinkrank-custom-schema` on Pro's custom-schema output predates
     * this check and is matched by the same prefix). The graph and Pro's Local
     * SEO output additionally sit inside a named HTML comment, which is kept as
     * a second signal so a site running an older Pro build is not reported as
     * conflicting with itself.
     *
     * @param string $attributes Attribute text from the opening script tag.
     * @param string $preceding  Comment immediately before the tag.
     * @return bool
     */
    private function is_ours(string $attributes, string $preceding): bool {
        if (stripos($attributes, 'data-thinkrank') !== false) {
            return true;
        }

        return $preceding !== '' && stripos($preceding, 'thinkrank') !== false;
    }

    /**
     * Name the plugin that emitted a foreign block.
     *
     * Falls back to correlating with the active-plugin list: when exactly one
     * other schema-capable SEO plugin is running, an unattributed block is
     * almost certainly its, and naming it beats telling the user "something on
     * this page". That case is flagged with `guess` so the wording can hedge.
     *
     * @param array{tag:string, preceding:string} $block Parsed block.
     * @return array{slug:string, name:string, guess:bool}
     */
    private function attribute(array $block): array {
        $classes = $this->class_tokens($block['tag']);
        $comment = strtolower($block['preceding']);

        foreach (self::SIGNATURES as $slug => $signature) {
            foreach ($signature['class'] as $class) {
                if (in_array($class, $classes, true)) {
                    return ['slug' => $slug, 'name' => $signature['name'], 'guess' => false];
                }
            }

            foreach ($signature['comment'] as $needle) {
                if ($comment !== '' && strpos($comment, $needle) !== false) {
                    return ['slug' => $slug, 'name' => $signature['name'], 'guess' => false];
                }
            }
        }

        $active = $this->other_active_seo_plugins();

        if (count($active) === 1) {
            $slug = array_key_first($active);
            return ['slug' => $slug, 'name' => $active[$slug], 'guess' => true];
        }

        return [
            'slug'  => 'unknown',
            'name'  => __('an unidentified plugin or theme', 'thinkrank'),
            'guess' => true,
        ];
    }

    /**
     * Active third-party SEO plugins, as slug => display name.
     *
     * @return array<string, string>
     */
    private function other_active_seo_plugins(): array {
        $names = [];

        foreach (SEO_Plugin_Detector::detect_plugins() as $slug => $plugin) {
            $names[$slug] = (string) ($plugin['name'] ?? $slug);
        }

        return $names;
    }

    /**
     * Class tokens on a script tag.
     *
     * @param string $attributes Attribute text from the opening script tag.
     * @return string[]
     */
    private function class_tokens(string $attributes): array {
        if (!preg_match('#\bclass\s*=\s*["\']([^"\']*)["\']#i', $attributes, $match)) {
            return [];
        }

        return preg_split('/\s+/', strtolower(trim($match[1]))) ?: [];
    }

    /**
     * Decode a block's JSON, tolerating the shapes emitters actually use.
     *
     * @param string $json Raw script contents.
     * @return array<mixed> Decoded data, or an empty array when unusable.
     */
    private function decode(string $json): array {
        $decoded = json_decode(trim($json), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Every `@type` in a decoded block.
     *
     * A block may hold a single entity, a bare list of entities, or an object
     * wrapping `@graph`, and `@type` itself may be a string or a list — so the
     * walk is recursive rather than assuming one shape.
     *
     * @param mixed $data Decoded JSON-LD.
     * @return string[]
     */
    private function collect_types($data): array {
        if (!is_array($data)) {
            return [];
        }

        $types = [];

        if (isset($data['@type'])) {
            foreach ((array) $data['@type'] as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }

        foreach ($data as $key => $value) {
            if ($key === '@type' || !is_array($value)) {
                continue;
            }

            $types = array_merge($types, $this->collect_types($value));
        }

        return $types;
    }

    /**
     * Normalise a type list: unique, sorted, empties dropped.
     *
     * @param string[] $types Raw type list.
     * @return string[]
     */
    private function unique_types(array $types): array {
        $types = array_values(array_unique(array_filter($types)));
        sort($types);

        return $types;
    }

    /**
     * Keep only the types a duplicate of which is actually a problem.
     *
     * @param string[] $types Type list.
     * @return string[]
     */
    private function page_level_only(array $types): array {
        return array_values(array_intersect($types, self::PAGE_LEVEL_TYPES));
    }

    /**
     * The URL to scan.
     *
     * The most recent published post, because a single post carries more
     * page-level entities than the front page on most sites, and falls back to
     * the home URL when there is no post to use.
     *
     * @return string
     */
    private function representative_url(): string {
        /**
         * Filters the URL the schema-conflict scan fetches.
         *
         * @since 2.9.0
         *
         * @param string $url Representative front-end URL.
         */
        $filtered = apply_filters('thinkrank_schema_conflict_scan_url', '');

        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }

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

            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return home_url('/');
    }

    /**
     * Fetch a front-end URL from this server.
     *
     * Anonymous (no cookies) so the scan sees what a search engine sees, and
     * `sslverify` off because this is a self-request — a local or self-signed
     * certificate must not read as a conflict-free page. Mirrors how WP Site
     * Health runs its own loopback probes, and how Instant Indexing checks its
     * key file.
     *
     * @param string $url URL to fetch.
     * @return string|\WP_Error Response body, or the failure.
     */
    private function fetch(string $url) {
        $response = wp_remote_get(
            $url,
            [
                'timeout'     => self::FETCH_TIMEOUT,
                'sslverify'   => false,
                'redirection' => 3,
                'user-agent'  => 'ThinkRank-SchemaConflictCheck/1.0',
                'headers'     => ['Cache-Control' => 'no-cache'],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return new \WP_Error(
                'thinkrank_scan_http_error',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The page returned HTTP %d, so it could not be checked.', 'thinkrank'),
                    $code
                )
            );
        }

        $body = (string) wp_remote_retrieve_body($response);

        if ($body === '') {
            return new \WP_Error(
                'thinkrank_scan_empty_body',
                __('The page returned an empty response, so it could not be checked.', 'thinkrank')
            );
        }

        return $body;
    }
}
