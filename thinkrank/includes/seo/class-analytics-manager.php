<?php

/**
 * Analytics Manager Class
 *
 * Coordinates Google API integrations for SEO analytics data collection,
 * processing, and AI-powered insights generation. Manages Google Analytics,
 * Search Console, and PageSpeed data with intelligent caching and rate limiting.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Settings_Manager;
use ThinkRank\Integrations\Google_Analytics_Client;
use ThinkRank\Integrations\Google_Search_Console_Client;
use ThinkRank\Integrations\Google_PageSpeed_Client;
use ThinkRank\Integrations\Google_Search_Analytics_Client;
use ThinkRank\Integrations\Google_OAuth_Proxy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics Manager Class
 *
 * Single Responsibility: Coordinate Google API data collection and processing
 * Following ThinkRank manager patterns from AI_Manager and Performance_Monitoring_Manager
 *
 * @since 1.0.0
 */
class Analytics_Manager {

    /**
     * Settings Manager instance
     *
     * @var Settings_Manager
     */
    private Settings_Manager $settings_manager;

    /**
     * Google Analytics client
     *
     * @var Google_Analytics_Client|null
     */
    private ?Google_Analytics_Client $analytics_client = null;

    /**
     * Google Search Console client
     *
     * @var Google_Search_Console_Client|null
     */
    private ?Google_Search_Console_Client $search_console_client = null;

    /**
     * Google Search Analytics client
     *
     * @var Google_Search_Analytics_Client|null
     */
    private ?Google_Search_Analytics_Client $search_analytics_client = null;

    /**
     * Google PageSpeed client
     *
     * @var Google_PageSpeed_Client|null
     */
    private ?Google_PageSpeed_Client $pagespeed_client = null;

    /**
     * Cache duration in seconds
     *
     * @var int
     */
    private int $cache_duration;

    /**
     * Static flag to prevent multiple token refreshes in the same request
     *
     * @var bool
     */
    private static bool $token_refreshed_this_request = false;

    /**
     * Single-flight lock for the OAuth refresh exchange.
     *
     * @var string
     */
    private const REFRESH_LOCK = 'thinkrank_token_refresh_lock';

    /**
     * How long a held refresh lock stays valid. Longer than the request
     * timeout below, so a request that dies mid-exchange still frees it.
     *
     * @var int
     */
    private const REFRESH_LOCK_TTL = 60;

    /**
     * Set after a failed exchange; suppresses retries until it expires.
     *
     * @var string
     */
    private const REFRESH_BACKOFF = 'thinkrank_token_refresh_backoff';

    /**
     * How long to stay quiet after a failed exchange.
     *
     * @var int
     */
    private const REFRESH_BACKOFF_TTL = 300;

    /**
     * Timeout for the refresh exchange. A healthy proxy answers in ~1s; the
     * old 30s meant one outage held a request open for half a minute.
     *
     * @var int
     */
    private const REFRESH_TIMEOUT = 10;

    /**
     * Constructor
     *
     * @param Settings_Manager|null $settings_manager Settings manager instance
     */
    public function __construct(?Settings_Manager $settings_manager = null) {
        $this->settings_manager = $settings_manager ?? new Settings_Manager();
        // Pro: daily refresh (86400s), Free: 3-day refresh (259200s)
        $this->cache_duration = defined('THINKRANK_PRO_VERSION') ? 86400 : 259200;
    }

    /**
     * Initialize Analytics Manager
     * Following ThinkRank init patterns
     *
     * @return void
     */
    public function init(): void {
        // Register custom cron interval (45 minutes)
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        // Initialize Google API clients — but only in the contexts that can use
        // them. See maybe_initialize_clients().
        add_action('init', [$this, 'maybe_initialize_clients']);

        // Initialize token refresh scheduling
        add_action('init', [$this, 'init_token_refresh']);

        // Cron hook for token refresh
        add_action('thinkrank_google_token_refresh', [$this, 'refresh_access_token_cron']);

        // Schedule cache cleanup
        add_action('thinkrank_daily_cleanup', [$this, 'cleanup_cache']);

        // Cleanup cron on plugin deactivation
        register_deactivation_hook(THINKRANK_PLUGIN_FILE, [__CLASS__, 'deactivation_cleanup']);
    }

    /**
     * Add custom cron intervals
     *
     * @param array $schedules Existing cron schedules
     * @return array Modified cron schedules
     */
    public function add_cron_intervals(array $schedules): array {
        // Only translate once `init` has run: wp_get_schedules() can be reached
        // before then (wp_schedule_event() at plugin boot does), and translating
        // that early trips the _load_textdomain_just_in_time notice on WP 6.7+.
        $schedules['thinkrank_45min'] = [
            'interval' => 2700, // 45 minutes in seconds
            'display'  => did_action('init')
                ? __('Every 45 Minutes', 'thinkrank')
                : 'Every 45 Minutes'
        ];
        return $schedules;
    }

    /**
     * Clean up cron events on plugin deactivation
     *
     * @return void
     */
    public static function deactivation_cleanup(): void {
        $timestamp = wp_next_scheduled('thinkrank_google_token_refresh');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'thinkrank_google_token_refresh');
        }
    }

    /**
     * Get the initialized Search Console client
     *
     * @return Google_Search_Console_Client|null
     */
    public function get_search_console_client(): ?Google_Search_Console_Client {
        if (!$this->search_console_client) {
            $this->initialize_clients();
        }
        return $this->search_console_client;
    }

    /**
     * Get the configured Search Console property URL
     *
     * @return string
     */
    public function get_property_url(): string {
        return $this->get_setting('search_console_property', get_site_url());
    }

    /**
     * Initialize the Google clients on `init`, in the contexts that use them.
     *
     * initialize_clients() refreshes the OAuth token, which is a blocking
     * outbound POST to the OAuth proxy. Hooked unconditionally it ran on every
     * anonymous front-end request, so a proxy outage became a site-wide TTFB
     * collapse — with each visitor waiting for the network call, and none of
     * them able to use a Google client anyway. No front-end code path reads
     * one: every consumer is a REST endpoint, a cron callback or WP-CLI, and
     * each either calls initialize_clients() itself or goes through
     * get_search_console_client(), which initializes lazily (#383).
     *
     * @since 2.0.1
     * @return void
     */
    public function maybe_initialize_clients(): void {
        $wanted = is_admin()
            || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI);

        /**
         * Filter whether the Google API clients are initialized for this request.
         *
         * @since 2.0.1
         *
         * @param bool $wanted Whether to initialize the clients.
         */
        if (!apply_filters('thinkrank_initialize_google_clients', $wanted)) {
            return;
        }

        $this->initialize_clients();
    }

    /**
     * Initialize Google API clients
     * Following AI_Manager client initialization pattern
     *
     * @return void
     */
    public function initialize_clients(): void {
        try {
            // Refresh token if needed (non-forced, checks expiration)
            $this->refresh_access_token();

            // Initialize Search Console client
            $gsc_api_key = $this->get_setting('google_search_console_api_key');
            $access_token = $this->get_setting('google_access_token');

            $timeout = (int) $this->get_setting('api_timeout', 30);
            $this->search_console_client = new Google_Search_Console_Client(
                $gsc_api_key ?: '',
                $timeout,
                !empty($access_token) ? $access_token : null
            );

            // Initialize Search Analytics client
            $this->search_analytics_client = new Google_Search_Analytics_Client(
                $gsc_api_key ?: '',
                $timeout,
                !empty($access_token) ? $access_token : null
            );

            // Initialize PageSpeed client. PSI is a public API — it uses the
            // site's own API key (or keyless per-IP quota), never the shared
            // OAuth token, which would bill every install's Lighthouse runs
            // to one exhausted Google Cloud project (429 for everyone).
            // Shorter timeout here: the dashboard CWV card fetches in-request
            // on a cold cache and must not stall the whole dashboard payload.
            $this->pagespeed_client = Google_PageSpeed_Client::for_site(25);

            // Initialize Google Analytics (GA4) client when a property has
            // been selected. The GA settings UI stores the property in the
            // Admin API's "properties/XXXXXXXX" form, which is exactly what
            // the Data API endpoints expect.
            $ga_property = (string) $this->get_setting('seo_analytics_google_analytics_property_id');
            if (!empty($access_token) && $ga_property !== '') {
                if (strpos($ga_property, 'properties/') !== 0) {
                    $ga_property = 'properties/' . $ga_property;
                }
                $this->analytics_client = new Google_Analytics_Client('', $ga_property, $timeout, $access_token);
            }
        } catch (\Exception $e) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('ThinkRank Analytics Init Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Initialize token refresh scheduling
     * Also migrates old absolute-timestamp expires_in values to relative seconds
     *
     * @return void
     */
    public function init_token_refresh(): void {
        $access_token = $this->get_setting('google_access_token');
        $refresh_token = $this->get_setting('google_refresh_token');

        if (empty($access_token) || empty($refresh_token)) {
            return;
        }

        // Migrate old expires_in values stored as absolute timestamps
        $this->maybe_migrate_expires_in();

        // Schedule recurring hourly cron for token refresh
        $this->schedule_token_refresh();
    }

    /**
     * Migrate old expires_in values from absolute timestamps to relative seconds
     *
     * Old callback.php stored expires_in as time() + token->expires_in (e.g., 1771330205).
     * New behavior stores raw seconds from Google (e.g., 3599).
     *
     * @return void
     */
    private function maybe_migrate_expires_in(): void {
        $expires_in = (int) $this->get_setting('google_token_expires_in');
        $created = (int) $this->get_setting('google_token_created');

        // Google tokens expire in 3600 seconds max. If stored value is > 86400,
        // it's almost certainly the old absolute timestamp format.
        if ($expires_in > 86400 && $created > 0) {
            $relative = $expires_in - $created;
            if ($relative > 0 && $relative <= 7200) {
                // Valid relative value, save the corrected value
                $this->settings_manager->update_settings([
                    'google_token_expires_in' => $relative
                ], 'integrations');
            } else {
                // Can't reliably compute, default to standard 3600
                $this->settings_manager->update_settings([
                    'google_token_expires_in' => 3600
                ], 'integrations');
            }
            $this->merged_settings = null;
        }
    }

    /**
     * Schedule recurring cron for token refresh (every 45 minutes)
     *
     * Uses WP recurring cron instead of single events for reliability.
     * The cron callback checks expiration and only refreshes when needed.
     * Using 45-minute interval ensures the cron always fires before
     * Google's ~60-minute token expiry window.
     *
     * @return void
     */
    public function schedule_token_refresh(): void {
        $next = wp_next_scheduled('thinkrank_google_token_refresh');

        // If already scheduled with the old 'hourly' interval, reschedule with 45min
        if ($next) {
            // Check if it's using the old interval by looking at the schedule
            $crons = _get_cron_array();
            foreach ($crons as $timestamp => $cron_hooks) {
                if (isset($cron_hooks['thinkrank_google_token_refresh'])) {
                    foreach ($cron_hooks['thinkrank_google_token_refresh'] as $hash => $args) {
                        if (($args['schedule'] ?? '') === 'hourly') {
                            // Remove old hourly schedule and re-add with 45min
                            wp_unschedule_event($timestamp, 'thinkrank_google_token_refresh');
                            $next = false; // Will be rescheduled below
                        }
                    }
                    break;
                }
            }
        }

        if (!$next) {
            wp_schedule_event(time(), 'thinkrank_45min', 'thinkrank_google_token_refresh');
        }
    }

    /**
     * Cron callback for token refresh
     * Called every 45 minutes; only refreshes if token is expired or expiring soon.
     *
     * @return void
     */
    public function refresh_access_token_cron(): void {
        $this->refresh_access_token();
    }

    /**
     * Ensure the Google access token is fresh before making API calls.
     *
     * This is a static convenience method that can be called from any endpoint
     * (including the Pro plugin) before making Google API requests.
     * Uses a per-request flag to avoid redundant refreshes when multiple
     * endpoints are called in the same HTTP request.
     *
     * @since 1.6.0
     * @return void
     */
    public static function ensure_fresh_token(): void {
        // Only refresh once per HTTP request to avoid parallel race conditions
        if (self::$token_refreshed_this_request) {
            return;
        }

        $manager = new self();
        $manager->refresh_access_token();
        self::$token_refreshed_this_request = true;
    }

    /**
     * Refresh OAuth access token if expired or expiring soon
     *
     * @since 1.5.0
     * @param bool $force Force refresh even if not expired
     * @return void
     */
    public function refresh_access_token(bool $force = false): void {
        $refresh_token = $this->get_setting('google_refresh_token');

        // If no refresh token, we can't refresh
        if (empty($refresh_token)) {
            return;
        }

        $expires_in = (int) $this->get_setting('google_token_expires_in');
        $created = (int) $this->get_setting('google_token_created');
        $current_time = time();

        // Calculate absolute expiration time (created + relative seconds)
        $expiration_time = $created + $expires_in;

        // Refresh if forced, expired, or expiring within 5 minutes (300 seconds)
        if (!$force && $current_time < ($expiration_time - 300)) {
            return;
        }

        // A failed exchange leaves google_token_created untouched, so the
        // expiry condition above stays true and the next request tries again.
        // Without a backoff a proxy outage means one blocking network call per
        // request, forever. A forced refresh — the user reconnecting — is a
        // deliberate act and skips the wait (#383).
        if (!$force && get_transient(self::REFRESH_BACKOFF)) {
            return;
        }

        // One exchange at a time. Concurrent callers past the expiry threshold
        // would otherwise all refresh at once and invalidate each other's
        // in-flight grants; the losers fall through with the current token and
        // pick up the new one on their next read.
        if (!$force && !$this->acquire_refresh_lock()) {
            return;
        }

        try {
            // The proxy owns the Google app credentials; we only ever hand it
            // the refresh token and let it perform the exchange.
            $response = wp_remote_post(Google_OAuth_Proxy::get_proxy_url(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body' => wp_json_encode([
                    'action' => 'refresh',
                    'refresh_token' => $refresh_token,
                    'site' => home_url(),
                ]),
                'timeout' => self::REFRESH_TIMEOUT
            ]);

            if (is_wp_error($response)) {
                $this->back_off_refresh();
                return;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (empty($data['access_token'])) {
                // invalid_grant is terminal: the user revoked access in their
                // Google account, or the refresh token was superseded by a
                // newer grant. Retrying can never succeed, so stop pretending
                // the site is connected — otherwise the UI shows "Connected"
                // while every API call 401s.
                if (($data['error'] ?? '') === 'invalid_grant') {
                    Google_OAuth_Proxy::mark_revoked();
                    return;
                }

                // Any other failure (network blip, proxy 502) is transient;
                // leave the credentials alone and let the next run retry —
                // after the backoff, not on the very next request.
                $this->back_off_refresh();
                return;
            }

            // Update settings with new token data
            $this->settings_manager->update_settings([
                'google_access_token' => $data['access_token'],
                'google_token_created' => $current_time,
                'google_token_expires_in' => (int) ($data['expires_in'] ?? 3600)
            ], 'integrations');

            // Also update refresh token if a new one was returned
            if (!empty($data['refresh_token'])) {
                $this->settings_manager->update_settings([
                    'google_refresh_token' => $data['refresh_token']
                ], 'integrations');
            }

            // A success clears any backoff a previous failure left behind.
            delete_transient(self::REFRESH_BACKOFF);

            // Drop the memoized settings merge so subsequent reads (e.g.
            // re-initializing clients) see the fresh token.
            $this->merged_settings = null;
        } finally {
            $this->release_refresh_lock();
        }
    }

    /**
     * Take the single-flight lock for the refresh exchange.
     *
     * @since 2.0.1
     * @return bool True when this request holds the lock.
     */
    private function acquire_refresh_lock(): bool {
        // With a persistent object cache, add is atomic — memcached and Redis
        // both fail an ADD on an existing key — so exactly one caller wins.
        if (wp_using_ext_object_cache()) {
            return (bool) wp_cache_add(self::REFRESH_LOCK, time(), 'thinkrank', self::REFRESH_LOCK_TTL);
        }

        // Without one, the options table is the shared store, and the unique
        // index on option_name gives add_option() the same all-or-nothing
        // result. set_transient() would not: it is an update, so every
        // concurrent caller would "win".
        if (add_option(self::REFRESH_LOCK, time(), '', 'no')) {
            return true;
        }

        // Reclaim a lock whose holder died before releasing it.
        $held = (int) get_option(self::REFRESH_LOCK);

        if ($held > 0 && (time() - $held) > self::REFRESH_LOCK_TTL) {
            delete_option(self::REFRESH_LOCK);

            return (bool) add_option(self::REFRESH_LOCK, time(), '', 'no');
        }

        return false;
    }

    /**
     * Release the single-flight lock.
     *
     * @since 2.0.1
     * @return void
     */
    private function release_refresh_lock(): void {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete(self::REFRESH_LOCK, 'thinkrank');

            return;
        }

        delete_option(self::REFRESH_LOCK);
    }

    /**
     * Stop retrying the exchange for a while after a failure.
     *
     * @since 2.0.1
     * @return void
     */
    private function back_off_refresh(): void {
        set_transient(self::REFRESH_BACKOFF, time(), self::REFRESH_BACKOFF_TTL);
    }

    /**
     * Test all Google API connections
     * Following ThinkRank test_connection patterns
     *
     * @return array Connection test results
     */
    public function test_connections(): array {
        $results = [
            'google_analytics' => ['status' => 'not_configured'],
            'search_console' => ['status' => 'not_configured'],
            'pagespeed' => ['status' => 'not_configured']
        ];

        // Test Google Analytics connection
        if ($this->analytics_client) {
            try {
                $test_result = $this->analytics_client->test_connection();
                $results['google_analytics'] = [
                    'status' => $test_result['success'] ? 'connected' : 'error',
                    'message' => $test_result['message'],
                    'details' => $test_result
                ];
            } catch (\Exception $e) {
                $results['google_analytics'] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        // Test Search Console connection
        if ($this->search_console_client) {
            try {
                $test_result = $this->search_console_client->test_connection();
                $results['search_console'] = [
                    'status' => $test_result['success'] ? 'connected' : 'error',
                    'message' => $test_result['message'],
                    'details' => $test_result
                ];
            } catch (\Exception $e) {
                $results['search_console'] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        // Test PageSpeed connection
        if ($this->pagespeed_client) {
            try {
                $test_result = $this->pagespeed_client->test_connection();
                $results['pagespeed'] = [
                    'status' => $test_result['success'] ? 'connected' : 'error',
                    'message' => $test_result['message'],
                    'details' => $test_result
                ];
            } catch (\Exception $e) {
                $results['pagespeed'] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Get analytics dashboard data
     * Combines data from all Google APIs with caching
     *
     * @param string $date_range Date range for data
     * @return array Dashboard data
     *
     * @throws \Exception On failure.
     */
    public function get_dashboard_data(string $date_range = '30d'): array {
        $cache_key = "analytics_dashboard_v5_{$date_range}";
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            // Core Web Vitals are cached separately with a much shorter
            // lifetime than the GSC data (and failures are never cached), so
            // a transient PageSpeed failure can't blank the CWV card for the
            // dashboard cache's full 1-3 day TTL.
            $cached_data['core_web_vitals'] = $this->get_dashboard_core_web_vitals();
            return $cached_data;
        }

        $dashboard_data = [
            'traffic' => [],
            'search_performance' => [],
            'core_web_vitals' => [],
            'last_updated' => current_time('mysql'),
            'date_range' => $date_range
        ];

        $retry_count = 0;
        $max_retries = 1;

        while ($retry_count <= $max_retries) {
            try {
                // Ensure clients are initialized (lazy load) before any of
                // them are used — this also builds the GA4 client when a
                // property is configured.
                if (!$this->search_console_client || !$this->search_analytics_client) {
                    $this->initialize_clients();
                }

                // Get Google Analytics traffic data. GA is optional — an
                // isolated failure (misconfigured property, missing scope)
                // must not abort the Search Console portion of the dashboard.
                // 401s are re-thrown so the token-refresh retry below runs.
                if ($this->analytics_client) {
                    try {
                        $dashboard_data['traffic'] = $this->analytics_client->get_traffic_data($date_range);
                    } catch (\Exception $ga_error) {
                        if ($ga_error->getCode() === 401) {
                            throw $ga_error;
                        }
                        $dashboard_data['traffic'] = [];
                        $dashboard_data['traffic_error'] = $ga_error->getMessage();
                    }
                }

                // Get Search Console data
                if ($this->search_console_client) {
                    $site_url = $this->get_setting('search_console_property', get_site_url());
                    // Get totals
                    $totals = $this->search_console_client->get_search_totals($site_url, $date_range);

                    // Get performance data (keywords) using new client
                    if ($this->search_analytics_client) {
                        // GSC data has a 2-day delay; use D-2 as end_date to match the GSC dashboard.
                        $days       = (int) str_replace('d', '', $date_range);
                        $end_date   = gmdate('Y-m-d', strtotime('-2 days'));
                        $start_date = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($end_date)));

                        $search_performance = $this->search_analytics_client->get_search_analytics_data(
                            $site_url,
                            $start_date,
                            $end_date,
                            ['query'],
                            1000
                        );
                    } else {
                        // Fallback to old client if new one fails init (shouldn't happen if they use same creds)
                        $search_performance = $this->search_console_client->get_search_performance($site_url, $date_range, ['query'], 1000);
                    }

                    // Calculate position distribution
                    $position_distribution = [
                        'top_3' => 0,
                        '4_10' => 0,
                        '10_50' => 0,
                        '51_100' => 0
                    ];

                    foreach ($search_performance['rows'] ?? [] as $row) {
                        $position = $row['position'] ?? 0;
                        if ($position <= 3) {
                            $position_distribution['top_3']++;
                        } elseif ($position <= 10) {
                            $position_distribution['4_10']++;
                        } elseif ($position <= 50) {
                            $position_distribution['10_50']++;
                        } elseif ($position <= 100) {
                            $position_distribution['51_100']++;
                        }
                    }

                    $dashboard_data['search_performance'] = array_merge($search_performance, [
                        'totals' => $totals,
                        'position_distribution' => $position_distribution
                    ]);
                } // Closing Search Console block

                // If successful, break loop
                break;
            } catch (\Exception $e) {
                // Check for 401 error
                if ($e->getCode() === 401 && $retry_count < $max_retries) {
                    $this->refresh_access_token(true); // Force refresh

                    // Re-initialize clients with new token
                    $this->initialize_clients();

                    $retry_count++;
                    continue;
                }
                $dashboard_data['error'] = $e->getMessage();
                break;
            }
        }

        // Add last updated timestamp
        $dashboard_data['last_updated'] = current_time('mysql');

        // Cache the results — but never cache an error payload, otherwise a
        // transient failure (e.g. a Google API 401) would be served from the
        // cache for the full TTL even after the underlying issue is fixed.
        // Core Web Vitals are deliberately NOT part of this cache (see below).
        if (empty($dashboard_data['error'])) {
            set_transient($cache_key, $dashboard_data, $this->cache_duration);
        }

        // Merge Core Web Vitals from their own short-lived cache after the
        // long-lived GSC payload has been stored.
        $dashboard_data['core_web_vitals'] = $this->get_dashboard_core_web_vitals();

        return $dashboard_data;
    }

    /**
     * Get Core Web Vitals for the analytics dashboard, cached independently
     * of the dashboard payload.
     *
     * Successful results are cached for 1 hour; failures are never cached
     * here (the PageSpeed client itself remembers failures for a few minutes
     * to avoid re-blocking requests on a broken URL), so CWV recovers as soon
     * as PageSpeed does instead of staying empty for the dashboard cache's
     * 1-3 day TTL.
     *
     * @return array Core Web Vitals data, or an error payload
     */
    private function get_dashboard_core_web_vitals(): array {
        $cached = get_transient('thinkrank_dashboard_cwv');
        if (is_array($cached)) {
            return $cached;
        }

        if (!$this->pagespeed_client) {
            $this->initialize_clients();
        }

        if (!$this->pagespeed_client) {
            return [];
        }

        try {
            $core_web_vitals = $this->pagespeed_client->get_core_web_vitals(get_site_url());
            set_transient('thinkrank_dashboard_cwv', $core_web_vitals, HOUR_IN_SECONDS);
            return $core_web_vitals;
        } catch (\Exception $psi_error) {
            return [
                'error' => $psi_error->getMessage(),
                'note'  => 'PageSpeed data unavailable. This is expected on localhost or non-public URLs.'
            ];
        }
    }

    /**
     * Get SEO opportunities using Search Console data
     *
     * @param string $date_range Date range for analysis
     * @return array SEO opportunities
     */
    public function get_seo_opportunities(string $date_range = '30d'): array {
        $cache_key = "seo_opportunities_{$date_range}";
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $opportunities = [
            'keyword_opportunities' => [],
            'page_opportunities' => [],
            'device_insights' => [],
            'last_updated' => current_time('mysql')
        ];

        $retry_count = 0;
        $max_retries = 1;

        while ($retry_count <= $max_retries) {
            try {
                if ($this->search_console_client) {
                    $site_url = $this->get_setting('search_console_property', get_site_url());

                    // Get keyword opportunities
                    $opportunities['keyword_opportunities'] = $this->search_console_client->get_keyword_opportunities($site_url, $date_range);

                    // Get device performance insights
                    $opportunities['device_insights'] = $this->search_console_client->get_device_performance($site_url, $date_range);

                    // Get search appearance data
                    $opportunities['search_appearance'] = $this->search_console_client->get_search_appearance($site_url, $date_range);
                }

                // If successful, break loop
                break;
            } catch (\Exception $e) {
                // Check for 401 error
                if ($e->getCode() === 401 && $retry_count < $max_retries) {
                    $this->refresh_access_token(true); // Force refresh

                    // Re-initialize clients with new token
                    $this->initialize_clients();

                    $retry_count++;
                    continue;
                }

                $opportunities['error'] = $e->getMessage();
                break;
            }
        }

        // Cache the results — but never cache an error payload (see
        // get_dashboard_data() for rationale).
        if (empty($opportunities['error'])) {
            set_transient($cache_key, $opportunities, $this->cache_duration);
        }

        return $opportunities;
    }

    /**
     * Memoized merge of the two settings categories this manager reads from.
     * Rebuilt when settings are updated through update_settings() below.
     *
     * @var array|null
     */
    private ?array $merged_settings = null;

    private function get_setting(string $key, $fallback = '') {
        if ($this->merged_settings === null) {
            // Merge settings to allow access to both categories. Memoized:
            // this getter is called many times per request and each category
            // read decrypts every sensitive option again.
            $this->merged_settings = array_merge(
                $this->settings_manager->get_settings('integrations'),
                $this->settings_manager->get_settings('seo_analytics')
            );
        }

        return $this->merged_settings[$key] ?? $fallback;
    }

    /**
     * One-click setup for Google Search Console verification
     * Following ThinkRank setup patterns
     *
     * @param string $site_url Site URL to verify
     * @return array Setup results
     */
    public function setup_search_console_verification(string $site_url): array {
        try {
            if (!$this->search_console_client) {
                return [
                    'success' => false,
                    'message' => 'Search Console API key not configured'
                ];
            }

            $verification_result = $this->search_console_client->verify_site($site_url);

            if ($verification_result['success']) {
                // Update settings with verified site URL
                $this->settings_manager->update_settings(['search_console_property' => $site_url], 'seo_analytics');
                $this->merged_settings = null;
            }

            return $verification_result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Force refresh of all cached data
     *
     * @return array Refresh results
     */
    public function refresh_data(): array {
        // Clear all analytics-related transients, including the previous-period
        // ranges used for trend comparison (14d/60d/180d) and the separately
        // cached Core Web Vitals payload.
        $cache_keys = [
            'analytics_dashboard_v5_7d',
            'analytics_dashboard_v5_30d',
            'analytics_dashboard_v5_90d',
            'analytics_dashboard_v5_14d',
            'analytics_dashboard_v5_60d',
            'analytics_dashboard_v5_180d',
            'seo_opportunities_7d',
            'seo_opportunities_30d',
            'seo_opportunities_90d',
            'indexing_status',
            'thinkrank_dashboard_cwv'
        ];

        // Also clear PageSpeed-derived caches. Their keys are md5-derived from
        // URL + device, so compute them for the URL/device combinations the
        // plugin actually tests.
        foreach (array_unique([home_url(), get_site_url()]) as $url) {
            foreach (['mobile', 'desktop'] as $device) {
                $psi_hash = md5($url . '|' . $device);
                $legacy_hash = md5($url . '_' . $device);
                $cache_keys[] = 'thinkrank_psi_snapshot_' . $psi_hash;
                $cache_keys[] = 'thinkrank_psi_failure_' . $psi_hash;
                $cache_keys[] = 'thinkrank_core_web_vitals_' . $legacy_hash;
                $cache_keys[] = 'thinkrank_opportunities_' . $legacy_hash;
                $cache_keys[] = 'thinkrank_diagnostics_' . $legacy_hash;
            }
        }

        $cleared = 0;
        foreach ($cache_keys as $key) {
            if (delete_transient($key)) {
                $cleared++;
            }
        }

        return [
            'success' => true,
            'message' => "Cleared {$cleared} cached data entries",
            'cleared_count' => $cleared,
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * Get client status for debugging
     *
     * @return array Client status information
     */
    public function get_client_status(): array {
        return [
            'google_analytics' => [
                'initialized' => !is_null($this->analytics_client),
                'api_key_configured' => !empty($this->get_setting('google_analytics_api_key')),
                'property_id_configured' => !empty($this->get_setting('seo_analytics_google_analytics_property_id'))
            ],
            'search_console' => [
                'initialized' => !is_null($this->search_console_client),
                'api_key_configured' => !empty($this->get_setting('google_search_console_api_key')),
                'site_url_configured' => !empty($this->get_setting('search_console_property'))
            ],
            'pagespeed' => [
                'initialized' => !is_null($this->pagespeed_client),
                'api_key_configured' => !empty($this->get_setting('google_pagespeed_api_key'))
            ],
            'cache_duration' => $this->cache_duration,
            'last_checked' => current_time('mysql')
        ];
    }

    /**
     * Cleanup expired cache data
     * Following ThinkRank cache cleanup patterns
     *
     * @return void
     */
    public function cleanup_cache(): void {
        // WordPress handles transient cleanup automatically
        // This method is for future custom cache cleanup if needed
    }
}
