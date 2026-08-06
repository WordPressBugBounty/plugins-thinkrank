<?php
/**
 * Instant Indexing Manager Class
 *
 * Handles automated submission of URLs to IndexNow API.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instant Indexing Manager Class
 *
 * Auto-submits URLs to IndexNow when content is updated.
 *
 * @since 1.1.0
 */
class Instant_Indexing_Manager {
    /**
     * Settings option name
     *
     * @var string
     */
    private $option_name = 'thinkrank_instant_indexing_settings';

    /**
     * IndexNow API Endpoint
     *
     * @var string
     */
    private $api_endpoint = 'https://api.indexnow.org/indexnow';

    /**
     * Cron hook used to submit URLs to IndexNow out-of-band.
     *
     * @var string
     */
    private const CRON_SUBMIT_HOOK = 'thinkrank_instant_indexing_submit';

    /**
     * Maximum URLs accepted per submission across every path (manual REST, bulk,
     * MCP). Keeps the paths consistent; larger sets are truncated to this cap.
     */
    public const MAX_URLS_PER_SUBMISSION = 100;

    /**
     * Initialize the component
     *
     * @since 1.1.0
     * @return void
     */
    public function init(): void {
        add_action('transition_post_status', [$this, 'handle_post_transition'], 10, 3);
        add_action('delete_post', [$this, 'handle_post_deletion'], 10, 2);

        // Serve the IndexNow key file from PHP when no physical file exists.
        // The key is normally written to the WordPress root, but on managed and
        // hardened hosting that root is read-only, the write was skipped in
        // silence, and every submission then came back 403 Forbidden — the one
        // status IndexNow returns when it cannot read the key at the advertised
        // keyLocation. Answering the request directly removes the filesystem
        // from the critical path entirely. A real file on disk still wins: the
        // web server serves it and this never runs.
        add_action('parse_request', [$this, 'maybe_serve_key_file']);

        // Automatic submissions run out-of-band via WP-Cron so the editor's
        // save/publish/delete request never blocks on the IndexNow HTTP call.
        // Registered unconditionally (WP-Cron runs outside the admin context).
        add_action(self::CRON_SUBMIT_HOOK, [$this, 'submit_urls_cron'], 10, 1);

        if (is_admin()) {
            $this->register_bulk_actions_hook();
            $this->register_handler_hooks();
            add_action('admin_notices', [$this, 'bulk_action_admin_notice']);

            // Row actions
            add_filter('post_row_actions', [$this, 'add_row_action_link'], 10, 2);
            add_filter('page_row_actions', [$this, 'add_row_action_link'], 10, 2);
            add_action('admin_action_thinkrank_instant_index_single', [$this, 'handle_single_action_submit']);
        }
    }

    /**
     * Serve `<key>.txt` at the site root when no physical file is present.
     *
     * IndexNow verifies ownership by fetching the key from `keyLocation` and
     * comparing it to the key in the payload; anything else is a 403. Writing
     * that file to ABSPATH fails on read-only roots, so this answers the
     * request from PHP instead. Runs on `parse_request` (before the main query)
     * because a missing `.txt` is routed to WordPress by the standard rewrite,
     * and only matches the site's own current key — never an arbitrary path.
     *
     * @since 1.27.0
     * @param \WP $wp Current WordPress environment instance.
     * @return void
     */
    public function maybe_serve_key_file($wp): void {
        if (is_admin()) {
            return;
        }

        $settings = get_option($this->option_name, []);
        if (empty($settings['enabled'])) {
            return;
        }

        $api_key = (string) ($settings['api_key'] ?? '');
        // The key is also a filename elsewhere, so it is always a plain hex
        // token; refuse to match on anything else rather than compare loosely.
        if (!preg_match('/^[a-f0-9]{8,64}$/', $api_key)) {
            return;
        }

        $path = (string) wp_parse_url(
            isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            PHP_URL_PATH
        );

        // Compare against the path of the advertised keyLocation, so a site in
        // a subdirectory resolves exactly as it is announced to IndexNow.
        $expected = (string) wp_parse_url(self::key_location($api_key), PHP_URL_PATH);
        if ($expected === '' || untrailingslashit($path) !== untrailingslashit($expected)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo esc_html($api_key);
        exit;
    }

    /**
     * The public URL IndexNow is told to fetch the key from.
     *
     * Single source of truth: the submission payload, the settings screen and
     * the request matcher above all derive from this, so they cannot drift.
     *
     * @since 1.27.0
     * @param string $api_key Verification key.
     * @return string Absolute key file URL.
     */
    public static function key_location(string $api_key): string {
        return home_url('/' . $api_key . '.txt');
    }

    /**
     * Actively verify that the advertised keyLocation is reachable and returns
     * the key — the general safety net for #247.
     *
     * IndexNow only ever answers 403 when it cannot read a matching key at
     * keyLocation, and that failure is otherwise silent until the first
     * submission. On a read-only root the physical `<key>.txt` is never written,
     * and the `parse_request` fallback only fires when the request actually
     * reaches WordPress — which it does not on an Apache-style host running
     * Plain permalinks. This does a one-shot loopback fetch of the exact URL we
     * announce to IndexNow so any unreachable-key configuration (that one
     * included) is caught on the settings screen instead of at first submit.
     *
     * @since 1.28.0
     * @return array{reachable:bool,code:int,url:string,reason:string}
     */
    public function verify_key_reachable(): array {
        $settings = get_option($this->option_name, []);
        $api_key  = (string) ($settings['api_key'] ?? '');
        $url      = $api_key !== '' ? self::key_location($api_key) : '';

        $result = [
            'reachable' => false,
            'code'      => 0,
            'url'       => $url,
            'reason'    => '',
        ];

        if ($api_key === '' || !preg_match('/^[a-f0-9]{8,64}$/', $api_key)) {
            $result['reason'] = __('No valid IndexNow key is set yet.', 'thinkrank');
            return $result;
        }

        // Loopback fetch of our own key URL. sslverify is off because this is a
        // self-check against this very site (a self-signed/local cert must not
        // read as "unreachable"), mirroring how WP Site Health runs its loopback
        // probes.
        $response = wp_remote_get(
            $url,
            [
                'timeout'   => 7,
                'sslverify' => false,
                // translators: this is a diagnostic self-request user agent.
                'user-agent' => 'ThinkRank-IndexNow-KeyCheck/1.0',
            ]
        );

        if (is_wp_error($response)) {
            $result['reason'] = sprintf(
                /* translators: %s: HTTP error message. */
                __('Could not reach the key file from this server (%s). Search engines may still reach it; open it in a browser to confirm.', 'thinkrank'),
                $response->get_error_message()
            );
            return $result;
        }

        $result['code'] = (int) wp_remote_retrieve_response_code($response);
        $body           = trim((string) wp_remote_retrieve_body($response));

        if ($result['code'] === 200 && hash_equals($api_key, $body)) {
            $result['reachable'] = true;
            return $result;
        }

        $result['reason'] = $this->key_unreachable_reason($result['code']);
        return $result;
    }

    /**
     * Build an actionable explanation when the key file is not reachable, naming
     * the specific #247 combination (read-only root + Plain permalinks) so the
     * user gets a fix instead of a silent, permanent 403.
     *
     * @since 1.28.0
     * @param int $code HTTP status observed for the key URL (0 when none).
     * @return string
     */
    private function key_unreachable_reason(int $code): string {
        $root_writable = wp_is_writable(ABSPATH);
        $pretty        = (bool) get_option('permalink_structure');

        // The exact #247 trap: the file can't be written (read-only root) AND
        // the server only routes unknown paths to WordPress under pretty
        // permalinks, so the PHP fallback never runs either.
        if (!$root_writable && !$pretty) {
            return __('Your site root is read-only (so the key file can’t be written) and permalinks are set to “Plain” (so ThinkRank can’t serve the key dynamically). Fix either one: set Settings → Permalinks to any option other than “Plain”, or make the site root writable.', 'thinkrank');
        }

        if ($code === 404) {
            return __('The key file returned 404. If your site root is read-only, set Settings → Permalinks to any option other than “Plain” so ThinkRank can serve the key.', 'thinkrank');
        }

        return sprintf(
            /* translators: %d: HTTP status code returned by the key URL. */
            __('The key file could not be verified (HTTP %d). Open it in a browser — it should show the key and nothing else.', 'thinkrank'),
            $code
        );
    }

    /**
     * Add Row Action Link
     *
     * @since 1.1.0
     * @param array    $actions Existing actions.
     * @param \WP_Post $post    Post object.
     * @return array Modified actions.
     */
    public function add_row_action_link(array $actions, \WP_Post $post): array {
        // Check if enabled and post type is supported
        if (!$this->is_enabled() || !$this->is_post_type_supported($post->post_type)) {
            return $actions;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $nonce = wp_create_nonce('thinkrank_instant_index_' . $post->ID);
        $url = admin_url('admin.php?action=thinkrank_instant_index_single&post_id=' . $post->ID . '&nonce=' . $nonce);

        $actions['thinkrank_instant_index'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('ThinkRank: Instant Indexing Submit Page', 'thinkrank')
        );

        return $actions;
    }

    /**
     * Handle Single Post Submission
     *
     * @since 1.1.0
     * @return void
     */
    public function handle_single_action_submit(): void {
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'thinkrank_instant_index_' . $post_id)) {
            wp_die(esc_html__('Security check failed.', 'thinkrank'));
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You do not have permission to edit this post.', 'thinkrank'));
        }

        $url = get_permalink($post_id);
        $redirect_to = wp_get_referer() ?: admin_url('edit.php');

        if ($url) {
            $result = $this->submit_urls([$url]);

            $redirect_to = add_query_arg([
                'thinkrank_indexed_count' => 1,
                'thinkrank_index_status' => $result['success'] ? 'success' : 'failed',
            ], $redirect_to);
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Register Bulk Actions Hook
     *
     * @since 1.1.0
     * @return void
     */
    public function register_bulk_actions_hook(): void {
        add_filter('thinkrank_bulk_actions', [$this, 'add_bulk_action_item'], 10, 2);
    }

    /**
     * Add Bulk Action Item to Dropdown
     *
     * @since 1.1.0
     * @param array  $actions   Existing thinkrank actions.
     * @param string $post_type Current post type.
     * @return array Modified actions.
     */
    public function add_bulk_action_item(array $actions, string $post_type): array {
        // Check if enabled and post type is supported
        if (!$this->is_enabled() || !$this->is_post_type_supported($post_type)) {
            return $actions;
        }

        $actions['thinkrank_instant_index'] = __('Instant Indexing: Submit Page', 'thinkrank');
        return $actions;
    }

    /**
     * Register handler hooks for bulk actions
     * 
     * @since 1.1.0
     * @return void
     */
    private function register_handler_hooks(): void {
        $settings = get_option($this->option_name, []);
        $supported_types = $settings['auto_submit_post_types'] ?? [];

        foreach ($supported_types as $post_type) {
            add_filter("handle_bulk_actions-edit-{$post_type}", [$this, 'handle_bulk_action_submit'], 10, 3);
        }
    }

    /**
     * Handle post status transitions (publish, update)
     *
     * @since 1.1.0
     *
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param \WP_Post $post       Post object.
     * @return void
     */
    public function handle_post_transition(string $new_status, string $old_status, \WP_Post $post): void {

        // Check if we should process this post
        if (!$this->should_process_post($post)) {
            return;
        }

        // We only care if the new status is publish (created or updated)
        // OR if we are unpublishing (publish -> something else), we might want to update (though IndexNow is mostly for crawling new/updated content)
        // For now, let's focus on published content.
        if ($new_status === 'publish') {
            $url = get_permalink($post->ID);

            // Check for duplicate submission using short-lived cache (15 seconds)
            if ($this->is_recently_submitted_cache($url)) {
                return;
            }

            // Defer the outbound IndexNow call to WP-Cron so publishing doesn't
            // block on a third-party HTTP request.
            $this->schedule_url_submission([$url]);
        }
    }

    /**
     * Check if URL was recently submitted using transient cache
     *
     * @since 1.1.0
     * @param string $url URL to check
     * @return bool
     */
    private function is_recently_submitted_cache(string $url): bool {
        $cache_key = 'thinkrank_indexing_' . md5($url);

        if (get_transient($cache_key)) {
            return true;
        }

        set_transient($cache_key, true, 15); // Cache for 15 seconds
        return false;
    }

    /**
     * Handle post deletion
     *
     * @since 1.1.0
     *
     * @param int     $postid Post ID.
     * @param \WP_Post $post   Post object.
     * @return void
     */
    public function handle_post_deletion(int $postid, \WP_Post $post): void {
        // Check if we should process this post (even if it's being deleted, we might want to notify, 
        // though IndexNow 'submit' usually implies "please crawl this". 
        // IndexNow documentation says "notify... that a URL and its content has been added, updated, or deleted."
        // So yes, we submit deleted URLs too if they were public.)

        // For deletion, status might be 'trash' or 'delete', careful with checks.
        // We only care if it was a supported post type.
        if (!$this->is_post_type_supported($post->post_type)) {
            return;
        }

        // If global setting disabled, abort
        if (!$this->is_enabled()) {
            return;
        }

        $url = get_permalink($postid);
        if ($url) {
            // Defer to WP-Cron so the delete request doesn't block on IndexNow.
            $this->schedule_url_submission([$url]);
        }
    }

    /**
     * Check if a post should be processed
     *
     * @since 1.1.0
     *
     * @param \WP_Post $post Post object.
     * @return bool True if should process, false otherwise.
     */
    private function should_process_post(\WP_Post $post): bool {
        // 1. Check global enable switch
        if (!$this->is_enabled()) {
            return false;
        }

        // 2. Check if post type is supported
        if (!$this->is_post_type_supported($post->post_type)) {
            return false;
        }

        // 3. Check autosave/revision
        if (wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return false;
        }

        return true;
    }

    /**
     * Check if feature is enabled globally
     *
     * @since 1.1.0
     * @return bool
     */
    private function is_enabled(): bool {
        $settings = get_option($this->option_name, []);
        return isset($settings['enabled']) && $settings['enabled'];
    }

    /**
     * Check if post type is in settings
     *
     * @since 1.1.0
     * @param string $post_type The post type slug.
     * @return bool
     */
    private function is_post_type_supported(string $post_type): bool {
        $settings = get_option($this->option_name, []);
        $supported_types = $settings['auto_submit_post_types'] ?? [];

        return in_array($post_type, (array) $supported_types, true);
    }

    /**
     * Submit URLs to IndexNow API
     *
     * @since 1.1.0
     * @param array $urls List of URLs to submit.
     * @return array Submission results.
     */
    public function submit_urls(array $urls): array {
        if (empty($urls)) {
            return ['success' => false, 'message' => 'No URLs provided', 'submitted_count' => 0];
        }

        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        // Only submit URLs on this site's host. IndexNow rejects a urlList whose
        // entries don't match the declared host (HTTP 422), and the site's key
        // must not be sent for foreign URLs — enforce it locally on every path.
        $urls = array_values(array_filter($urls, static function ($u) use ($host) {
            return strcasecmp((string) wp_parse_url((string) $u, PHP_URL_HOST), (string) $host) === 0;
        }));
        if (empty($urls)) {
            return ['success' => false, 'message' => 'No URLs matched this site host', 'submitted_count' => 0];
        }

        // Enforce the shared per-submission cap so every path (manual, bulk, MCP)
        // behaves consistently.
        if (count($urls) > self::MAX_URLS_PER_SUBMISSION) {
            $urls = array_slice($urls, 0, self::MAX_URLS_PER_SUBMISSION);
        }

        $settings = get_option($this->option_name, []);
        $api_key = $settings['api_key'] ?? '';
        if (empty($api_key)) {
            return ['success' => false, 'message' => 'API Key missing', 'submitted_count' => 0];
        }

        $key_location = self::key_location($api_key);

        $body = [
            'host' => $host,
            'key' => $api_key,
            'keyLocation' => $key_location,
            'urlList' => $urls
        ];

        $response = wp_remote_post($this->api_endpoint, [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 15,
            'blocking' => true
        ]);

        // A network-layer failure (timeout, DNS, SSL) returns a WP_Error rather
        // than an HTTP response — surface its message instead of logging an empty
        // 0/'' row so the failure is diagnosable in the UI and history.
        if (is_wp_error($response)) {
            $status = 'failed';
            $response_code = 0;
            $response_message = $response->get_error_message();
        } else {
            $response_code = (int) wp_remote_retrieve_response_code($response);
            $response_message = wp_remote_retrieve_response_message($response);
            $status = ($response_code >= 200 && $response_code < 300) ? 'success' : 'failed';

            // "403 Forbidden" is IndexNow's answer for exactly one problem —
            // it could not read a matching key at keyLocation — but the raw
            // status reads like a permissions error against the API and sent a
            // customer hunting through the wrong settings for days. Say what it
            // actually means, and name the URL to check.
            if ($response_code === 403) {
                $response_message = sprintf(
                    /* translators: %s: public URL of the IndexNow key file. */
                    __('Key file could not be verified. Search engines must be able to read your key at %s — open it in a browser: it should show the key and nothing else.', 'thinkrank'),
                    $key_location
                );
            }
        }

        // Log each URL
        foreach ($urls as $url) {
            $this->log_submission($url, $status, $response_code, $response_message);
        }

        return [
            'success' => $status === 'success',
            'code' => $response_code,
            'message' => $response_message,
            // The count actually sent to IndexNow after same-host filtering and
            // the per-submission cap, so callers report the real number instead
            // of the raw input size.
            'submitted_count' => count($urls),
            'submitted_urls' => $urls,
        ];
    }

    /**
     * Queue a set of URLs for out-of-band submission to IndexNow.
     *
     * Used by the automatic (transition_post_status / delete_post) hooks so the
     * blocking HTTP call runs on a WP-Cron request instead of the editor's save
     * request. WP-Cron collapses identical (hook + args) events scheduled close
     * together, which further de-dupes rapid repeat saves of the same URL.
     *
     * @since 1.16.0
     * @param array $urls List of URLs to submit.
     * @return void
     */
    private function schedule_url_submission(array $urls): void {
        if (empty($urls)) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_SUBMIT_HOOK, [$urls])) {
            wp_schedule_single_event(time(), self::CRON_SUBMIT_HOOK, [$urls]);
        }
    }

    /**
     * WP-Cron handler: perform the deferred IndexNow submission.
     *
     * @since 1.16.0
     * @param array $urls List of URLs to submit.
     * @return void
     */
    public function submit_urls_cron(array $urls): void {
        // Re-check the feature is still enabled in case it was turned off between
        // scheduling and execution.
        if (!$this->is_enabled()) {
            return;
        }

        $this->submit_urls($urls);
    }

    /**
     * Log submission to database
     * 
     * @param string $url URL submitted
     * @param string $status success/failed
     * @param int|string $code Response code
     * @param string $message Response message
     */
    private function log_submission(string $url, string $status, $code, string $message): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'thinkrank_instant_indexing_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Logging requires direct insert.
        $wpdb->insert(
            $table_name,
            [
                'url' => $url,
                'status' => $status,
                'response_code' => $code,
                'response_message' => $message,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Get submission history
     * 
     * @param int $limit Number of records to retrieve
     * @return array
     */
    public function get_history(int $limit = -1): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'thinkrank_instant_indexing_logs';

        if ($limit === -1) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from controlled prefix
            return $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is from $wpdb->prefix.
                "SELECT * FROM `{$table_name}` ORDER BY created_at DESC",
                ARRAY_A
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from controlled prefix
        return $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is from $wpdb->prefix.
            $wpdb->prepare("SELECT * FROM `{$table_name}` ORDER BY created_at DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    /**
     * Get a bounded page of submission history plus the total row count, so the
     * History tab paginates server-side instead of fetching the whole table.
     *
     * @param int $page     1-based page number.
     * @param int $per_page Rows per page (clamped 1..100).
     * @return array{items: array, total: int, page: int, per_page: int}
     */
    public function get_history_page(int $page = 1, int $per_page = 10): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'thinkrank_instant_indexing_logs';

        $per_page = max(1, min(100, $per_page));
        $page = max(1, $page);
        $offset = ($page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is from $wpdb->prefix.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from controlled prefix
        $items = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is from $wpdb->prefix.
            $wpdb->prepare("SELECT * FROM `{$table_name}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset),
            ARRAY_A
        );

        return [
            'items' => $items ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Clear submission history
     *
     * @since 1.1.0
     * @return bool
     */
    public function clear_history(): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'thinkrank_instant_indexing_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from controlled prefix, TRUNCATE requires direct query
        $result = $wpdb->query("TRUNCATE TABLE `{$table_name}`");

        return $result !== false;
    }

    /**
     * Handle Bulk Action Submission
     *
     * @since 1.1.0
     * @param string $redirect_to Redirect URL.
     * @param string $action      Action name.
     * @param array  $post_ids    Selected post IDs.
     * @return string Modified redirect URL.
     */
    public function handle_bulk_action_submit(string $redirect_to, string $action, array $post_ids): string {
        if ($action !== 'thinkrank_instant_index') {
            return $redirect_to;
        }

        $urls = [];
        foreach ($post_ids as $post_id) {
            $url = get_permalink($post_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        if (empty($urls)) {
            return $redirect_to;
        }

        // Cap the set and defer the outbound call to WP-Cron so a large bulk
        // selection doesn't block the admin request (parity with the auto path).
        if (count($urls) > self::MAX_URLS_PER_SUBMISSION) {
            $urls = array_slice($urls, 0, self::MAX_URLS_PER_SUBMISSION);
        }
        $count = count($urls);
        $this->schedule_url_submission($urls);

        // Add query args for admin notice
        $redirect_to = add_query_arg([
            'thinkrank_indexed_count' => $count,
            'thinkrank_index_status' => 'scheduled',
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Display Admin Notice for Bulk Action
     *
     * @since 1.1.0
     * @return void
     */
    public function bulk_action_admin_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display reads URL params, not form processing.
        if (!isset($_GET['thinkrank_indexed_count']) || !isset($_GET['thinkrank_index_status'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display reads URL params.
        $count = (int) $_GET['thinkrank_indexed_count'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display reads URL params.
        $status = sanitize_key(wp_unslash($_GET['thinkrank_index_status']));

        if ($status === 'scheduled') {
            $class = 'notice-success';
            // translators: %s is the number of URLs queued for IndexNow submission.
            $message = sprintf(_n('%s URL queued for submission to IndexNow.', '%s URLs queued for submission to IndexNow.', $count, 'thinkrank'), $count);
        } else {
            $class = ($status === 'success') ? 'notice-success' : 'notice-error';
            $message = ($status === 'success')
                // translators: %s is the number of URLs submitted to IndexNow.
                ? sprintf(_n('%s URL submitted to IndexNow successfully.', '%s URLs submitted to IndexNow successfully.', $count, 'thinkrank'), $count)
                : __('Failed to submit URLs to IndexNow.', 'thinkrank');
        }

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
