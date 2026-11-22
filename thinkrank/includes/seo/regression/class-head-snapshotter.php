<?php
/**
 * Regression Guard: fetch a page the way a visitor gets it and reduce what it
 * serves to the SEO signals worth comparing.
 *
 * @package ThinkRank\SEO\Regression
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Regression;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Head Snapshotter
 *
 * Regression Guard (#720) compares what a page *serves*, not what ThinkRank
 * *stores*. A theme, a builder or another plugin can rewrite the head after we
 * print it, drop a JSON-LD block, or add a second canonical, and none of that
 * shows up in post meta. So the only honest source is the page itself: a
 * loopback request, anonymous (no cookies, so it sees what Google sees), not
 * following redirects (a page that now redirects is the finding, not something
 * to walk past).
 *
 * The parse is split from the fetch so it can be tested on fixed markup, and
 * the result is normalised — token lists sorted, JSON-LD reduced to each
 * entity's type and a handful of key properties — so two snapshots of an
 * unchanged page are equal and a diff only ever reports a real change.
 *
 * @since 2.23.0
 */
class Head_Snapshotter {

    /**
     * User agent the loopback identifies itself with, so it can be told apart
     * in access logs and excluded by anything that counts visits.
     */
    public const USER_AGENT = 'ThinkRank-RegressionGuard';

    /**
     * Query argument that busts page caches for the loopback.
     *
     * Stripped from every URL the snapshot records, so a canonical or og:url
     * that echoes the request URL does not read as a change on every check.
     */
    public const CACHE_BUST_ARG = 'thinkrank_rg';

    /**
     * Shape version of a stored snapshot. A snapshot of another version is not
     * compared against: a diff between two shapes reports the shape change.
     */
    public const VERSION = 1;

    /**
     * Seconds the loopback may take.
     */
    private const TIMEOUT = 10;

    /**
     * Largest response read. Far past any real page; it exists so a runaway
     * response cannot exhaust memory in the parser.
     */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Longest value kept for any one text field.
     */
    private const MAX_TEXT = 500;

    /**
     * Most JSON-LD entities kept. A page with more is a listing, and the types
     * above the cut are still counted.
     */
    private const MAX_ENTITIES = 50;

    /**
     * Open Graph properties kept, without the `og:` prefix.
     *
     * @var string[]
     */
    private const OG_KEYS = ['title', 'description', 'image', 'type', 'url'];

    /**
     * Twitter card properties kept, without the `twitter:` prefix.
     *
     * @var string[]
     */
    private const TWITTER_KEYS = ['card', 'title', 'description', 'image'];

    /**
     * Fetch a URL over loopback and snapshot it.
     *
     * Never reports a page as unchanged when it could not be read: a failed
     * request comes back as an error with a code the screen explains, not as
     * an empty snapshot that would diff as "everything removed".
     *
     * @param string $url Public URL of the page.
     * @return array{ok: bool, snapshot?: array<string, mixed>, error?: array{code: string, message: string}}
     */
    public static function fetch(string $url): array {
        // The free routes take a post id and derive the URL themselves, which
        // is why #720 chose `post_id` over `?url=`. But compare() is public and
        // documented as taking "any URL on the site", and thinkrank-pro#217
        // calls it for taxonomy archives — so the "on the site" half has to be
        // enforced here rather than assumed, or the SSRF the REST shape avoids
        // walks back in one layer up. `sslverify` is filtered off for the
        // loopback, which makes an off-site fetch worse than an ordinary one.
        if (!self::is_same_site($url)) {
            return [
                'ok'    => false,
                'error' => [
                    'code'    => 'loopback_foreign_url',
                    'message' => __('Regression Guard only checks URLs on this site.', 'thinkrank'),
                ],
            ];
        }

        $response = wp_remote_get(
            add_query_arg(self::CACHE_BUST_ARG, (string) time(), $url),
            [
                'timeout'             => self::TIMEOUT,
                'redirection'         => 0,
                'user-agent'          => self::USER_AGENT . '/' . THINKRANK_VERSION . '; ' . home_url('/'),
                'limit_response_size' => self::MAX_BYTES,
                // What core's own Site Health loopback test passes: a local
                // site with a self-signed certificate is still the site.
                'sslverify'           => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter.
                'headers'             => [
                    'Cache-Control' => 'no-cache',
                    'Accept'        => 'text/html',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [
                'ok'    => false,
                'error' => [
                    'code'    => 'loopback_failed',
                    'message' => $response->get_error_message(),
                ],
            ];
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $headers = [
            'x-robots-tag' => wp_remote_retrieve_header($response, 'x-robots-tag'),
            'location'     => wp_remote_retrieve_header($response, 'location'),
        ];

        // A host that answers every loopback with its own login or firewall
        // page does so with one of these. The page was not read, so there is
        // nothing to compare — say so rather than snapshot the block page.
        if (in_array($status, [401, 403], true)) {
            return [
                'ok'    => false,
                'error' => [
                    'code'    => 'loopback_blocked',
                    'message' => sprintf(
                        /* translators: %d: HTTP status code */
                        __('The server refused its own request with HTTP %d.', 'thinkrank'),
                        $status
                    ),
                ],
            ];
        }

        return [
            'ok'       => true,
            'snapshot' => self::parse(
                (string) wp_remote_retrieve_body($response),
                $headers,
                $status,
                $url
            ),
        ];
    }

    /**
     * Whether a URL is served by this site.
     *
     * Host and scheme both, against `home_url()`. A relative or
     * protocol-relative string has no host and is refused rather than
     * resolved: a caller that means a page on this site has a permalink to
     * hand.
     *
     * @since 2.23.0
     *
     * @param string $url URL to check.
     * @return bool
     */
    private static function is_same_site(string $url): bool {
        $target = wp_parse_url($url);
        $home   = wp_parse_url(home_url('/'));

        if (!is_array($target) || !is_array($home) || empty($target['host']) || empty($home['host'])) {
            return false;
        }

        if (0 !== strcasecmp((string) $target['host'], (string) $home['host'])) {
            return false;
        }

        // A site on https must not be probed over http by a caller that built
        // the URL by hand: that is a different response, and on some hosts a
        // redirect this fetch deliberately does not follow.
        return 0 === strcasecmp((string) ($target['scheme'] ?? ''), (string) ($home['scheme'] ?? ''));
    }

    /**
     * Reduce a response to the fields Regression Guard compares.
     *
     * @param string               $html    Response body.
     * @param array<string, mixed> $headers Lowercase header name => value (string or list).
     * @param int                  $status  HTTP status code.
     * @param string               $url     URL that was requested, without the cache-bust argument.
     * @return array<string, mixed>
     */
    public static function parse(string $html, array $headers, int $status, string $url): array {
        $snapshot = self::empty_snapshot($url, $status);

        $snapshot['x_robots_tag'] = self::tokens(self::header_value($headers['x-robots-tag'] ?? ''));

        if ($status >= 300 && $status < 400) {
            $snapshot['redirect_to'] = self::strip_bust(self::header_value($headers['location'] ?? ''));
            return $snapshot;
        }

        // A non-200 page's markup is an error template, not the page: its
        // title and schema would diff as the page's own.
        if (200 !== $status || '' === trim($html) || !class_exists('DOMDocument')) {
            return $snapshot;
        }

        $dom    = new \DOMDocument();
        $libxml = libxml_use_internal_errors(true);
        // The XML declaration pins UTF-8 without mb_convert_encoding()'s
        // entity round trip; see External_Links_Manager for the same call.
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($libxml);

        if (!$loaded) {
            return $snapshot;
        }

        $title = $dom->getElementsByTagName('title')->item(0);
        if (null !== $title) {
            $snapshot['title'] = self::text($title->textContent);
        }

        $robots = [];
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            /** @var \DOMElement $meta */
            $name     = strtolower(trim($meta->getAttribute('name')));
            $property = strtolower(trim($meta->getAttribute('property')));
            $content  = $meta->getAttribute('content');

            if ('description' === $name && '' === $snapshot['description']) {
                $snapshot['description'] = self::text($content);
            } elseif ('robots' === $name || 'googlebot' === $name) {
                $robots = array_merge($robots, self::tokens($content));
            }

            // Open Graph is specified as `property`, but `name` is common
            // enough in the wild that reading only one would misreport a
            // theme's tags as missing.
            $og_key = 0 === strpos($property, 'og:') ? substr($property, 3) : (0 === strpos($name, 'og:') ? substr($name, 3) : '');
            if (in_array($og_key, self::OG_KEYS, true) && '' === $snapshot['og'][$og_key]) {
                $snapshot['og'][$og_key] = 'url' === $og_key ? self::strip_bust(self::text($content)) : self::text($content);
            }

            $tw_key = 0 === strpos($name, 'twitter:') ? substr($name, 8) : (0 === strpos($property, 'twitter:') ? substr($property, 8) : '');
            if (in_array($tw_key, self::TWITTER_KEYS, true) && '' === $snapshot['twitter'][$tw_key]) {
                $snapshot['twitter'][$tw_key] = self::text($content);
            }
        }
        $robots = array_values(array_unique($robots));
        sort($robots);
        $snapshot['robots'] = $robots;

        $hreflang = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            /** @var \DOMElement $link */
            $rel  = self::tokens($link->getAttribute('rel'));
            $href = self::strip_bust(trim($link->getAttribute('href')));

            if (in_array('canonical', $rel, true)) {
                $snapshot['canonical_count']++;
                if ('' === $snapshot['canonical']) {
                    $snapshot['canonical'] = $href;
                }
            } elseif (in_array('alternate', $rel, true) && '' !== $link->getAttribute('hreflang')) {
                $hreflang[strtolower(trim($link->getAttribute('hreflang')))] = $href;
            }
        }
        ksort($hreflang);
        $snapshot['hreflang'] = $hreflang;

        $entities = [];
        foreach ($dom->getElementsByTagName('script') as $script) {
            /** @var \DOMElement $script */
            if ('application/ld+json' !== strtolower(trim($script->getAttribute('type')))) {
                continue;
            }

            $decoded = json_decode(trim($script->textContent), true);
            if (!is_array($decoded)) {
                $snapshot['schema_invalid']++;
                continue;
            }

            foreach (self::top_level_nodes($decoded) as $node) {
                $entity = self::entity($node);
                if (null !== $entity) {
                    $entities[] = $entity;
                }
            }
        }
        usort($entities, static function (array $a, array $b): int {
            return [$a['type'], $a['name'], $a['url']] <=> [$b['type'], $b['name'], $b['url']];
        });
        $snapshot['schema'] = array_slice($entities, 0, self::MAX_ENTITIES);

        $snapshot['h1_count'] = $dom->getElementsByTagName('h1')->length;

        // Tags printed twice (#733). Canonicals and schema entities are
        // already compared above; this covers the rest.
        foreach (\ThinkRank\SEO\Duplicate_Tags\Head_Tags::duplicates(\ThinkRank\SEO\Duplicate_Tags\Head_Tags::scan($html)) as $row) {
            if ('link:canonical' !== $row['key'] && 'schema' !== $row['family']) {
                $snapshot['duplicate_tags'][$row['key']] = $row['count'];
            }
        }
        ksort($snapshot['duplicate_tags']);

        return $snapshot;
    }

    /**
     * Whether the page tells search engines not to index it, from the meta
     * tags or the X-Robots-Tag header.
     *
     * @param array<string, mixed> $snapshot Snapshot.
     * @return bool
     */
    public static function is_noindex(array $snapshot): bool {
        foreach (array_merge((array) ($snapshot['robots'] ?? []), (array) ($snapshot['x_robots_tag'] ?? [])) as $token) {
            // The header may scope a directive to one crawler
            // ("googlebot: noindex"); the directive is the part after it.
            $directive = trim((string) substr((string) strrchr(':' . $token, ':'), 1));
            if ('noindex' === $directive || 'none' === $directive) {
                return true;
            }
        }

        return false;
    }

    /**
     * A snapshot with every field present and empty.
     *
     * @param string $url    Page URL.
     * @param int    $status HTTP status code.
     * @return array<string, mixed>
     */
    private static function empty_snapshot(string $url, int $status): array {
        return [
            'v'               => self::VERSION,
            'url'             => $url,
            'status'          => $status,
            'redirect_to'     => '',
            'title'           => '',
            'description'     => '',
            'robots'          => [],
            'x_robots_tag'    => [],
            'canonical'       => '',
            'canonical_count' => 0,
            'hreflang'        => [],
            'og'              => array_fill_keys(self::OG_KEYS, ''),
            'twitter'         => array_fill_keys(self::TWITTER_KEYS, ''),
            'schema'          => [],
            'schema_invalid'  => 0,
            'h1_count'        => 0,
            'duplicate_tags'  => [],
        ];
    }

    /**
     * The typed nodes a JSON-LD block declares at its top level: the block
     * itself, each item of a list, or each item of an `@graph`.
     *
     * Nested entities (an Article's author, a Product's brand) are properties
     * of their parent, not entities of the page; comparing them would report a
     * reordering inside one entity as types coming and going.
     *
     * @param array<mixed> $decoded Decoded JSON-LD.
     * @return array<int, array<mixed>>
     */
    private static function top_level_nodes(array $decoded): array {
        $nodes = self::is_list($decoded) ? $decoded : [$decoded];

        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['@graph']) && is_array($node['@graph'])) {
                foreach ($node['@graph'] as $item) {
                    if (is_array($item)) {
                        $out[] = $item;
                    }
                }
                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * Reduce one JSON-LD node to its type and the properties whose loss or
     * change costs a rich result.
     *
     * @param array<mixed> $node JSON-LD node.
     * @return array<string, string>|null Null when the node has no type.
     */
    private static function entity(array $node): ?array {
        $type = $node['@type'] ?? '';
        if (is_array($type)) {
            $type = array_map('strval', array_filter($type, 'is_scalar'));
            sort($type);
            $type = implode(', ', $type);
        }
        $type = self::text((string) (is_scalar($type) ? $type : ''));
        if ('' === $type) {
            return null;
        }

        $offers = $node['offers'] ?? [];
        if (is_array($offers) && self::is_list($offers)) {
            $offers = is_array($offers[0] ?? null) ? $offers[0] : [];
        }
        $offers = is_array($offers) ? $offers : [];

        $rating = is_array($node['aggregateRating'] ?? null) ? $node['aggregateRating'] : [];

        $items = $node['itemListElement'] ?? null;

        return [
            'type'         => $type,
            'name'         => self::scalar($node['name'] ?? ($node['headline'] ?? '')),
            'url'          => self::strip_bust(self::scalar($node['url'] ?? '')),
            'price'        => self::scalar($offers['price'] ?? ($offers['lowPrice'] ?? '')),
            'availability' => self::scalar($offers['availability'] ?? ''),
            'rating'       => self::scalar($rating['ratingValue'] ?? ''),
            'review_count' => self::scalar($rating['reviewCount'] ?? ($rating['ratingCount'] ?? '')),
            'items'        => is_array($items) ? (string) count($items) : '',
        ];
    }

    /**
     * A scalar JSON value as trimmed text; anything else as empty.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function scalar($value): string {
        return is_scalar($value) ? self::text((string) $value) : '';
    }

    /**
     * Collapse whitespace and cap the length, so markup formatting never
     * reads as a change.
     *
     * @param string $text Text.
     * @return string
     */
    private static function text(string $text): string {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_TEXT) : substr($text, 0, self::MAX_TEXT);
    }

    /**
     * Split a comma- or space-separated directive list into sorted, lowercase,
     * unique tokens.
     *
     * @param string $value Directive list.
     * @return string[]
     */
    private static function tokens(string $value): array {
        $tokens = [];
        foreach (preg_split('/,/', strtolower($value)) ?: [] as $part) {
            $part = trim((string) preg_replace('/\s+/', ' ', $part));
            // `rel` is space-separated; robots directives are comma-separated
            // but a directive never contains a space unless scoped ("googlebot: noindex").
            foreach (false === strpos($part, ':') ? explode(' ', $part) : [$part] as $token) {
                $token = trim($token);
                if ('' !== $token) {
                    $tokens[] = $token;
                }
            }
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }

    /**
     * A header value as one string; repeated headers are joined.
     *
     * @param mixed $value Header value.
     * @return string
     */
    private static function header_value($value): string {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Remove the cache-bust argument from a URL the page echoed back.
     *
     * @param string $url URL.
     * @return string
     */
    private static function strip_bust(string $url): string {
        if ('' === $url || false === strpos($url, self::CACHE_BUST_ARG)) {
            return $url;
        }

        return (string) remove_query_arg(self::CACHE_BUST_ARG, $url);
    }

    /**
     * Whether an array is a list (sequential integer keys from zero).
     *
     * @param array<mixed> $value Array.
     * @return bool
     */
    private static function is_list(array $value): bool {
        return [] === $value || array_keys($value) === range(0, count($value) - 1);
    }
}
