<?php
/**
 * Cache-safe AI referral detection (#966).
 *
 * Ai_Traffic_Tracker::record() runs on `template_redirect`, so it only sees
 * pages PHP renders. A full-page cache (an advanced-cache.php drop-in,
 * Varnish, LiteSpeed, Cloudflare) answers before that hook, and the pages AI
 * assistants link to (posts, products, categories) are exactly the pages that
 * get cached. Those visits were never counted, and Pro never set its revenue
 * attribution cookie for them.
 *
 * This class closes the gap from the browser:
 *
 *  1. Every front-end page carries a few lines of inline script with the
 *     platform map. It compares `document.referrer`'s host and `utm_source`
 *     against that map, and does nothing at all on a non-match, so ordinary
 *     visitors make no extra request.
 *  2. On a match it POSTs to `thinkrank/v1/ai-referral`, which is never
 *     cached. That classifies the visit again on the server (the client only
 *     decides whether to ask), records it, and fires
 *     `thinkrank_ai_referral_detected` for Pro.
 *
 * Double counting. On a cache miss PHP has already recorded the visit, and
 * the page it rendered carries the same script. The cached copy of a page is
 * byte-for-byte what an earlier visitor got, so nothing inside the HTML can
 * say "this one was counted". The signal travels in a response header
 * instead: when record() counts a referral it sets a short-lived marker
 * cookie holding the landing path, and the script stays quiet when the marker
 * names the page it is on. A cache hit runs no PHP, sets no marker, and the
 * script reports. The response that sets the marker is sent with no-cache
 * headers, so a cache never stores it.
 *
 * @package ThinkRank\SEO
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inline detector + uncached endpoint for AI referrals PHP did not see.
 */
class Ai_Referral_Beacon {

    /**
     * REST namespace and route.
     */
    public const REST_NAMESPACE = 'thinkrank/v1';
    public const REST_ROUTE     = '/ai-referral';

    /**
     * Marker cookie set when PHP has recorded the referral itself.
     *
     * Not HttpOnly: the inline script has to read it. It holds the landing
     * path and nothing else.
     */
    public const MARKER_COOKIE = 'thinkrank_ai_seen';

    /**
     * Seconds the marker lives. The script reads it as the page loads, so it
     * only has to outlast one page load; any longer and a second AI click to
     * the same page, served from cache, would go uncounted.
     */
    private const MARKER_TTL = 120;

    /**
     * Transient key prefix for the per-client limiter; a hash of the client
     * is appended.
     */
    private const LIMIT_PREFIX = 'thinkrank_ai_beacon_';

    /**
     * Key prefix for the site-wide limiter; the window number is appended.
     *
     * An option without a persistent object cache (swept by uninstall with
     * every other `thinkrank_` option), a cache key with one.
     */
    private const TOTAL_KEY = 'thinkrank_ai_beacon_total';

    /**
     * Object cache group for the site-wide limiter.
     */
    private const CACHE_GROUP = 'thinkrank_ai_beacon';

    /**
     * Longest referrer / path / utm value accepted.
     */
    private const MAX_URL = 2048;
    private const MAX_UTM = 200;

    /**
     * The path marked on this request, if any.
     *
     * @var string|null
     */
    private static ?string $marked = null;

    /**
     * Tracker that owns the aggregate table.
     *
     * @var Ai_Traffic_Tracker
     */
    private Ai_Traffic_Tracker $tracker;

    /**
     * Constructor.
     *
     * @param Ai_Traffic_Tracker $tracker Tracker to record into.
     */
    public function __construct(Ai_Traffic_Tracker $tracker) {
        $this->tracker = $tracker;
    }

    /**
     * Wire the script and the endpoint.
     *
     * @return void
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_head', [$this, 'print_script'], 1);
    }

    /**
     * Whether client-side detection is on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        /**
         * Filters whether AI referrals are also detected in the browser.
         *
         * Turning it off removes the inline script, the marker cookie and the
         * `thinkrank/v1/ai-referral` endpoint. AI visits served from a page
         * cache, or identified only by `utm_source` on a cached page, then go
         * uncounted again.
         *
         * @since 2.15.0
         *
         * @param bool $enabled Default true.
         */
        return (bool) apply_filters('thinkrank_ai_referral_beacon_enabled', true);
    }

    /**
     * Tell this response's page that PHP already recorded its referral.
     *
     * Called by Ai_Traffic_Tracker::record() after it counts a referral.
     *
     * @param string $path Landing path, as the browser sent it.
     * @return void
     */
    public static function mark_recorded(string $path): void {
        if (!self::enabled()) {
            return;
        }

        self::$marked = $path;

        if (headers_sent()) {
            return;
        }

        // A response that sets a per-visitor cookie must never be stored.
        nocache_headers();

        // Raw, so the value is rawurlencode()'s output exactly, which the
        // script reverses with decodeURIComponent().
        setrawcookie(self::MARKER_COOKIE, rawurlencode($path), [
            'expires'  => time() + self::MARKER_TTL,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The path marked on this request, or null.
     *
     * @return string|null
     */
    public static function marked_path(): ?string {
        return self::$marked;
    }

    /**
     * Forget the marker state. For tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$marked = null;
    }

    /**
     * Whether this page should carry the detector.
     *
     * Same exclusions as record(): no feeds, previews, robots.txt or 404s,
     * and none for editors browsing their own site. Decided per render, which
     * is safe under a cache because each of these is a property of the page,
     * not of the visitor; a cache does not serve logged-in users a page
     * rendered for someone else.
     *
     * @return bool
     */
    public static function should_print(): bool {
        if (!self::enabled() || is_admin() || is_feed() || is_preview() || is_robots() || is_404() || is_embed()) {
            return false;
        }

        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return false;
        }

        return !(is_user_logged_in() && current_user_can('edit_posts'));
    }

    /**
     * Print the detector into the page head.
     *
     * @return void
     */
    public function print_script(): void {
        if (!self::should_print()) {
            return;
        }

        wp_print_inline_script_tag(
            self::script(),
            [
                'id'               => 'thinkrank-ai-referral',
                // Page optimisers that defer inline scripts until the first
                // interaction would lose every visitor who reads and leaves.
                'data-cfasync'     => 'false',
                'data-no-optimize' => '1',
                'data-no-defer'    => '1',
                'data-no-minify'   => '1',
                'nowprocket'       => true,
            ]
        );
    }

    /**
     * The detector's source.
     *
     * Mirrors Ai_Traffic_Tracker::classify_referrer() and
     * classify_utm_source(); change them together. The client only decides
     * whether to ask: the endpoint classifies again from what it is sent.
     *
     * @return string
     */
    public static function script(): string {
        $config = [
            'e' => rest_url(self::REST_NAMESPACE . self::REST_ROUTE),
            'k' => self::MARKER_COOKIE,
            'h' => array_keys(Ai_Traffic_Tracker::referrer_platforms()),
            'u' => array_map('strval', array_keys(Ai_Traffic_Tracker::utm_sources())),
        ];

        $json = (string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return <<<JS
(function(c){try{
var l=window.location,d=document,p=l.pathname,m=d.cookie.match(new RegExp('(?:^|; )'+c.k+'=([^;]*)'));
if(m){try{if(decodeURIComponent(m[1])===p){return;}}catch(e){}}
var hit=function(h){h=(h||'').toLowerCase();for(var i=0;i<c.h.length;i++){var f=c.h[i];if(h===f||(h.length>f.length&&h.slice(-f.length-1)==='.'+f)){return true;}}return false;};
var r='',rh='';if(d.referrer){try{rh=new URL(d.referrer).hostname;r=d.referrer;}catch(e){}}
if(rh===l.hostname){rh='';}
var u='';try{u=(new URLSearchParams(l.search).get('utm_source')||'').trim();}catch(e){}
var v=u.toLowerCase(),uh=v.indexOf('://')>-1?v.slice(v.indexOf('://')+3):v;uh=uh.split('/')[0];
if(!hit(rh)&&!(v&&(hit(uh)||c.u.indexOf(v)>-1))){return;}
var b=new URLSearchParams();b.append('ref',r);b.append('utm',u);b.append('path',p);
fetch(c.e,{method:'POST',body:b,credentials:'same-origin',keepalive:true,cache:'no-store'}).catch(function(){});
}catch(e){}})({$json});
JS;
    }

    /**
     * Register the endpoint.
     *
     * @return void
     */
    public function register_routes(): void {
        if (!self::enabled()) {
            return;
        }

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                // Public by design: it is called by anonymous visitors from
                // cached pages, which cannot carry a nonce. Abuse is bounded
                // by the origin check and the limiters in handle().
                'permission_callback' => '__return_true',
                'args'                => [
                    'ref'  => [
                        'type'      => 'string',
                        'default'   => '',
                        'maxLength' => self::MAX_URL,
                    ],
                    'utm'  => [
                        'type'      => 'string',
                        'default'   => '',
                        'maxLength' => self::MAX_UTM,
                    ],
                    'path' => [
                        'type'      => 'string',
                        'default'   => '/',
                        'maxLength' => self::MAX_URL,
                    ],
                ],
            ]
        );
    }

    /**
     * Record an AI referral reported by the page.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle(WP_REST_Request $request): WP_REST_Response {
        $response = $this->decide($request);

        foreach (wp_get_nocache_headers() as $name => $value) {
            if ('' !== (string) $value) {
                $response->header((string) $name, (string) $value);
            }
        }

        return $response;
    }

    /**
     * Classify, limit and record.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    private function decide(WP_REST_Request $request): WP_REST_Response {
        $hosts = self::own_hosts();

        // The page posting here is one of ours. Browsers send Origin on every
        // POST and a Referer naming the page unless a policy strips it; a
        // foreign value of either means another site is posting.
        $origin = (string) $request->get_header('origin');
        if ('' !== $origin && !self::is_own($origin, $hosts)) {
            return self::result(false, 403);
        }

        $page = (string) $request->get_header('referer');
        if ('' !== $page && !self::is_own($page, $hosts)) {
            return self::result(false, 403);
        }

        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- classified, never stored.
        if (null !== Ai_Traffic_Tracker::classify_crawler($agent) || self::request_is_editor()) {
            return self::result(false);
        }

        $referrer = self::bounded($request->get_param('ref'), self::MAX_URL);
        $utm      = self::bounded($request->get_param('utm'), self::MAX_UTM);

        // A referrer on our own host is an internal click, never an AI one;
        // the utm_source still counts.
        if ('' !== $referrer && self::is_own($referrer, $hosts)) {
            $referrer = '';
        }

        // Never the client's verdict: the server classifies what it was sent.
        $platform = Ai_Traffic_Tracker::classify_visit($referrer, $utm);
        if (null === $platform) {
            return self::result(false);
        }

        $path = self::landing_path($page, self::bounded($request->get_param('path'), self::MAX_URL));
        if (null === $path) {
            return self::result(false, 400);
        }

        if (!$this->within_limits()) {
            return self::result(false, 429);
        }

        $this->tracker->record_referral($platform, $path);

        /**
         * Fires when the browser reports an AI referral that PHP did not see
         * when the page was served, after it is recorded.
         *
         * Runs inside the uncached `thinkrank/v1/ai-referral` request, so a
         * listener may set cookies. ThinkRank Pro sets its AI revenue
         * attribution cookie here. Not fired for referrals record() counts on
         * `template_redirect`; listen there for those.
         *
         * @since 2.15.0
         *
         * @param string $platform Platform slug, from Ai_Traffic_Tracker::classify_visit().
         * @param string $path     Landing path on this site.
         * @param string $via      How it was detected. Currently always 'beacon'.
         */
        do_action('thinkrank_ai_referral_detected', $platform, $path, 'beacon');

        return self::result(true);
    }

    /**
     * Build the endpoint's response.
     *
     * @param bool $recorded Whether a referral was recorded.
     * @param int  $status   HTTP status.
     * @return WP_REST_Response
     */
    private static function result(bool $recorded, int $status = 200): WP_REST_Response {
        return new WP_REST_Response(['recorded' => $recorded], $status);
    }

    /**
     * A request parameter as a string no longer than $max, or ''.
     *
     * @param mixed $value Raw value.
     * @param int   $max   Longest accepted length.
     * @return string
     */
    private static function bounded($value, int $max): string {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return strlen($value) > $max ? '' : $value;
    }

    /**
     * This site's hosts: the home URL's, and the one this request was sent to.
     *
     * The second covers a multilingual site that serves a language per
     * domain, where the page and its same-origin POST are on a domain that is
     * not the home URL's.
     *
     * @return string[] Lower-case hosts.
     */
    private static function own_hosts(): array {
        $hosts = [strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))];

        if (!empty($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            // Host header may carry a port; the URL hosts compared against it never do.
            $hosts[] = strtolower((string) preg_replace('/:\d+$/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))));
        }

        return array_values(array_filter(array_unique($hosts)));
    }

    /**
     * Whether a URL is on one of the given hosts.
     *
     * @param string   $url   URL.
     * @param string[] $hosts Lower-case hosts.
     * @return bool
     */
    private static function is_own(string $url, array $hosts): bool {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        return '' !== $host && in_array($host, $hosts, true);
    }

    /**
     * The landing path to record.
     *
     * The posting page's own Referer is the authority when the browser sent
     * one, since it is the page the script ran on. Otherwise the path the
     * script reported, which must look like a path on this site.
     *
     * @param string $page     Referer of the beacon request (own host, or '').
     * @param string $reported Path the script sent.
     * @return string|null Path, or null when neither is usable.
     */
    private static function landing_path(string $page, string $reported): ?string {
        $path = '' !== $page ? (string) wp_parse_url($page, PHP_URL_PATH) : $reported;

        if ('' === $path) {
            $path = '/';
        }

        // A path, not a URL, and nothing that could not have been a request
        // line: no scheme-relative `//host`, no whitespace or control bytes.
        if ('/' !== $path[0] || str_starts_with($path, '//') || 1 === preg_match('/[\x00-\x20\x7f]/', $path)) {
            return null;
        }

        return substr($path, 0, 191);
    }

    /**
     * Whether the request comes from someone who can edit posts.
     *
     * REST drops cookie authentication when no nonce is sent, and a cached
     * page cannot carry one, so ask the logged-in cookie directly. An editor
     * browsing their own site is excluded here exactly as record() excludes
     * them.
     *
     * @return bool
     */
    private static function request_is_editor(): bool {
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return true;
        }

        if (!function_exists('wp_validate_auth_cookie')) {
            return false;
        }

        $user_id = (int) wp_validate_auth_cookie('', 'logged_in');

        return $user_id > 0 && user_can($user_id, 'edit_posts');
    }

    /**
     * Count this beacon against the per-client and site-wide limits.
     *
     * A real visitor sends one beacon per AI click, so the per-client limit
     * is generous for people and tight for a script. The site-wide limit
     * bounds what a distributed flood can write. Both are fixed windows that
     * a new beacon never extends.
     *
     * The site-wide window is checked first, so once it is full a beacon
     * stores nothing at all: a flood with a fresh forwarded address on every
     * request cannot create a per-client entry each. Its counter is taken
     * atomically, so concurrent beacons cannot read the same count and all
     * pass; the per-client counter is best-effort on top of it.
     *
     * @return bool False when either limit is reached.
     */
    private function within_limits(): bool {
        $defaults = [
            'per_client' => 20,
            'site'       => 2000,
            'window'     => HOUR_IN_SECONDS,
        ];

        /**
         * Filters the AI referral beacon's rate limits.
         *
         * @since 2.15.0
         *
         * @param array{per_client:int, site:int, window:int} $limits Beacons
         *        per client and per site in each window of `window` seconds.
         */
        $limits = apply_filters('thinkrank_ai_referral_beacon_limits', $defaults);
        $limits = is_array($limits) ? array_merge($defaults, $limits) : $defaults;
        $window = max(60, (int) $limits['window']);
        $site   = max(1, (int) $limits['site']);

        if (self::site_count($window) >= $site) {
            return false;
        }

        return self::take(self::LIMIT_PREFIX . md5(self::client_key()), max(1, (int) $limits['per_client']), $window)
            && self::take_site($site, $window);
    }

    /**
     * Take one slot from a per-client fixed-window counter.
     *
     * @param string $key    Transient key.
     * @param int    $max    Slots per window.
     * @param int    $window Window length in seconds.
     * @return bool False when the window is full.
     */
    private static function take(string $key, int $max, int $window): bool {
        $now   = time();
        $entry = get_transient($key);

        if (!is_array($entry) || !isset($entry['count'], $entry['until']) || (int) $entry['until'] <= $now) {
            set_transient($key, ['count' => 1, 'until' => $now + $window], $window);
            return true;
        }

        if ((int) $entry['count'] >= $max) {
            return false;
        }

        $entry['count'] = (int) $entry['count'] + 1;
        set_transient($key, $entry, max(1, (int) $entry['until'] - $now));

        return true;
    }

    /**
     * Key of the current site-wide window.
     *
     * @param int $window Window length in seconds.
     * @return string
     */
    private static function site_key(int $window): string {
        return self::TOTAL_KEY . '_' . intdiv(time(), $window);
    }

    /**
     * Beacons counted so far in the current site-wide window.
     *
     * @param int $window Window length in seconds.
     * @return int
     */
    private static function site_count(int $window): int {
        $key = self::site_key($window);

        if (wp_using_ext_object_cache()) {
            return (int) wp_cache_get($key, self::CACHE_GROUP);
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counter; a cached read would let a flood past the limit.
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key)
        );
    }

    /**
     * Take one slot from the site-wide counter, atomically.
     *
     * With a persistent object cache the increment is the cache's own atomic
     * one. Without, a single conditional UPDATE both checks and counts, so
     * two requests can never take the last slot twice; the first beacon of a
     * window inserts the row and removes the previous windows' rows.
     *
     * @param int $max    Slots per window.
     * @param int $window Window length in seconds.
     * @return bool False when the window is full.
     */
    private static function take_site(int $max, int $window): bool {
        $key = self::site_key($window);

        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::CACHE_GROUP, $window);
            $count = wp_cache_incr($key, 1, self::CACHE_GROUP);

            return false !== $count && (int) $count <= $max;
        }

        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter; the options API has no conditional increment.
        $taken = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value < %d",
                $key,
                $max
            )
        );

        if (1 === (int) $taken) {
            return true;
        }

        $created = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')",
                $key
            )
        );

        if (1 === (int) $created) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
                    $wpdb->esc_like(self::TOTAL_KEY . '_') . '%',
                    $key
                )
            );

            return true;
        }

        // Another beacon created the row first: count against it.
        $taken = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value < %d",
                $key,
                $max
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return 1 === (int) $taken;
    }

    /**
     * Who the per-client limit counts against.
     *
     * The connecting address plus the client address a CDN or proxy reports.
     * Behind Cloudflare or a load balancer every visitor shares one connecting
     * address, so without the forwarded value they would share one bucket and
     * real referrals would be refused. A client that forges the header only
     * gets a fresh per-client bucket, which the site-wide limit still caps.
     * Hashed before it is stored; never kept raw.
     *
     * @return string
     */
    private static function client_key(): string {
        $parts = [];

        foreach (['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            if (empty($_SERVER[$header]) || !is_string($_SERVER[$header])) {
                continue;
            }

            $value   = sanitize_text_field(wp_unslash($_SERVER[$header]));
            $parts[] = trim(explode(',', $value)[0]);
        }

        return implode('|', $parts);
    }
}
