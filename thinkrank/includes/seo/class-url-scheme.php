<?php
/**
 * The scheme ThinkRank publishes its own URLs with.
 *
 * @package ThinkRank
 * @since   2.7.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a self-referential URL goes out as http or https.
 *
 * Sites behind a proxy or load balancer that terminates TLS hand PHP a plain
 * HTTP request, so `is_ssl()` is false and WordPress falls back to whatever
 * scheme `home` was stored with. On a site installed over http and later put
 * behind TLS without anyone updating the option, every URL WordPress builds
 * says `http://` while every visitor is on `https://` — and the canonical tag
 * then tells search engines the wrong address for every page on the site.
 *
 * The site owner frequently cannot fix that at the server level, which is why
 * this is a setting rather than a support answer.
 *
 * Two rules keep it from doing damage of its own:
 *
 *   - **Only this site's own URLs are touched.** A cross-domain canonical the
 *     author typed, a `sameAs` pointing at a social profile, an image on a CDN:
 *     all of them are somebody else's URLs, and forcing our scheme onto them
 *     would break them. Host has to match before anything is rewritten.
 *   - **`automatic` changes nothing at all.** Not "resolves to the current
 *     scheme" — nothing. A site that never opens the setting emits exactly what
 *     WordPress reports, which is what every site did before this existed.
 *
 * @since 2.7.0
 */
class Url_Scheme {

    /**
     * Follow whatever WordPress reports. The default.
     *
     * @since 2.7.0
     * @var string
     */
    public const AUTOMATIC = 'automatic';

    /**
     * Publish every self-URL as https.
     *
     * @since 2.7.0
     * @var string
     */
    public const HTTPS = 'https';

    /**
     * Publish every self-URL as http.
     *
     * @since 2.7.0
     * @var string
     */
    public const HTTP = 'http';

    /**
     * The accepted values, in the order the settings screen lists them.
     *
     * @since 2.7.0
     * @var string[]
     */
    public const MODES = [self::AUTOMATIC, self::HTTPS, self::HTTP];

    /**
     * Resolved preference for this request.
     *
     * @since 2.7.0
     * @var string|null
     */
    private static $mode = null;

    /**
     * This site's host, lowercased.
     *
     * @since 2.7.0
     * @var string|null
     */
    private static $host = null;

    /**
     * The scheme preference this site has stored.
     *
     * Read once per request. Every emit point calls this, including the
     * sitemap generator inside a loop over thousands of URLs, so the settings
     * read must not repeat.
     *
     * @since 2.7.0
     * @return string One of MODES.
     */
    public static function preference(): string {
        if (null === self::$mode) {
            $stored = '';

            if (class_exists('\ThinkRank\SEO\Site_Identity_Manager')) {
                $settings = (new Site_Identity_Manager())->get_settings('site', null);
                $stored   = (string) ($settings['canonical_scheme'] ?? '');
            }

            /**
             * Filter the scheme ThinkRank publishes its own URLs with.
             *
             * @since 2.7.0
             *
             * @param string $mode One of Url_Scheme::MODES.
             */
            $mode = (string) apply_filters('thinkrank_canonical_scheme', $stored);

            self::$mode = in_array($mode, self::MODES, true) ? $mode : self::AUTOMATIC;
        }

        return self::$mode;
    }

    /**
     * Apply the site's scheme preference to one URL.
     *
     * @since 2.7.0
     * @param string $url Absolute URL, or anything else (returned untouched).
     * @return string
     */
    public static function apply(string $url): string {
        $mode = self::preference();

        if (self::AUTOMATIC === $mode || '' === $url) {
            return $url;
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);

        // No scheme means relative or protocol-relative: not ours to decide.
        if (!is_string($scheme) || '' === $scheme) {
            return $url;
        }

        $scheme = strtolower($scheme);

        if ('http' !== $scheme && 'https' !== $scheme) {
            return $url;
        }

        if ($scheme === $mode || !self::is_own_url($url)) {
            return $url;
        }

        return set_url_scheme($url, $mode);
    }

    /**
     * Apply the preference to every string in a nested structure.
     *
     * The schema graph is the reason this exists: its `@id` and `url` values
     * come from a dozen different producers, some deriving from WordPress and
     * some from stored settings, and normalizing at the point of serialization
     * is what stops one graph carrying both schemes at once.
     *
     * @since 2.7.0
     * @param mixed $value Array, string, or anything else.
     * @return mixed Same shape, schemes normalized.
     */
    public static function apply_deep($value) {
        if (self::AUTOMATIC === self::preference()) {
            return $value;
        }

        if (is_string($value)) {
            return self::apply($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::apply_deep($item);
        }

        return $value;
    }

    /**
     * Whether a URL belongs to this site.
     *
     * Host comparison only. A subdomain is a different site as far as a
     * canonical is concerned, and a port or path difference is still us.
     *
     * @since 2.7.0
     * @param string $url Absolute URL.
     * @return bool
     */
    private static function is_own_url(string $url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || '' === $host) {
            return false;
        }

        return strtolower($host) === self::host();
    }

    /**
     * This site's host, lowercased and memoised.
     *
     * @since 2.7.0
     * @return string
     */
    private static function host(): string {
        if (null === self::$host) {
            $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

            self::$host = is_string($host) ? strtolower($host) : '';
        }

        return self::$host;
    }

    /**
     * Forget the memoised preference and host.
     *
     * @since 2.7.0
     * @return void
     */
    public static function reset(): void {
        self::$mode = null;
        self::$host = null;
    }
}
