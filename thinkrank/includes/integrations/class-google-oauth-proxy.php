<?php
/**
 * Google OAuth Proxy Class
 *
 * Drives the whole Google connect flow through a ThinkRank-hosted proxy so the
 * plugin never ships Google app credentials — not even the public client ID —
 * and access/refresh tokens never travel through the browser.
 *
 * Round trip:
 *   1. Admin clicks Connect  → admin-post.php?action=thinkrank_google_connect
 *   2. We redirect to the proxy with ?action=connect&state=<base64 {r,n}>
 *   3. Proxy owns client_id/client_secret, runs the Google consent + code
 *      exchange, then redirects the browser back to state.r with a one-time
 *      exchange token (never the access token).
 *   4. Our front-end callback route swaps that token server-to-server for the
 *      real tokens and stores them encrypted.
 *
 * @package ThinkRank\Integrations
 * @since 1.20.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

use ThinkRank\Core\Settings_Manager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google OAuth Proxy Class
 *
 * Single Responsibility: own the browser-facing half of the Google OAuth flow.
 * Token refresh lives in Analytics_Manager and calls this class only for the
 * proxy URL.
 *
 * @since 1.20.0
 */
class Google_OAuth_Proxy {

    /**
     * Default proxy endpoint. Single URL, dispatched by an `action` field:
     * `connect` (GET, redirect), `exchange` (POST, JSON), `refresh` (POST, JSON).
     */
    private const DEFAULT_PROXY_URL = 'https://api.thinkrank.ai/v1/callback.php';

    /**
     * Nonce action guarding the whole flow (start → callback).
     */
    private const NONCE_ACTION = 'thinkrank_google_oauth';

    /**
     * Bumped whenever the rewrite rules change, to force a one-time flush.
     */
    private const REWRITE_VERSION = '1';

    /**
     * Bumped when the token contract changes in a way that invalidates stored
     * credentials, forcing connected sites to re-authorize.
     */
    private const TOKEN_CONTRACT_VERSION = '2';

    /**
     * Settings Manager instance
     *
     * @var Settings_Manager
     */
    private Settings_Manager $settings_manager;

    /**
     * Constructor
     *
     * @param Settings_Manager|null $settings_manager Settings manager instance.
     */
    public function __construct(?Settings_Manager $settings_manager = null) {
        $this->settings_manager = $settings_manager ?? new Settings_Manager();
    }

    /**
     * Initialize hooks
     *
     * @return void
     */
    public function init(): void {
        add_action('init', [$this, 'register_rewrites']);
        add_action('update_option_permalink_structure', [$this, 'reset_rewrite_version']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'handle_callback']);
        add_action('admin_post_thinkrank_google_connect', [$this, 'handle_connect']);
        add_action('admin_init', [$this, 'maybe_require_reconnect']);
    }

    /**
     * Resolve the proxy base URL.
     *
     * Overridable so staging installs can point at a test proxy without a
     * plugin release.
     *
     * @return string Proxy URL without a trailing slash.
     */
    public static function get_proxy_url(): string {
        $proxy = defined('THINKRANK_GOOGLE_OAUTH_PROXY')
            ? THINKRANK_GOOGLE_OAUTH_PROXY
            : self::DEFAULT_PROXY_URL;

        return untrailingslashit(apply_filters('thinkrank_google_oauth_proxy_url', $proxy));
    }

    /**
     * Register the front-end callback route.
     *
     * A front-end rewrite (rather than admin-ajax) keeps the return URL stable
     * and free of query args, so the proxy can append its own params safely.
     *
     * WordPress only evaluates rewrite rules when a permalink structure is set,
     * so on Plain-permalink sites the rule (and its flush) would be dead weight —
     * get_redirect_uri() routes through the query var there instead.
     *
     * @return void
     */
    public function register_rewrites(): void {
        if ($this->is_plain_permalinks()) {
            return;
        }

        add_rewrite_rule('^thinkrank-google-auth/?$', 'index.php?thinkrank_google_auth=1', 'top');

        if (get_option('thinkrank_google_rewrite_version') !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('thinkrank_google_rewrite_version', self::REWRITE_VERSION);
        }
    }

    /**
     * Drop the stored rewrite version when the permalink structure changes.
     *
     * Without this, a site switching from Plain to pretty permalinks would keep
     * the already-current version marker and never re-register/flush our rule.
     *
     * @return void
     */
    public function reset_rewrite_version(): void {
        delete_option('thinkrank_google_rewrite_version');
    }

    /**
     * Whether the site runs on Plain permalinks (no rewrite rules evaluated).
     *
     * @return bool
     */
    private function is_plain_permalinks(): bool {
        return '' === (string) get_option('permalink_structure');
    }

    /**
     * Register the callback query var.
     *
     * @param array $vars Registered query vars.
     * @return array
     */
    public function register_query_var(array $vars): array {
        $vars[] = 'thinkrank_google_auth';
        return $vars;
    }

    /**
     * The URL the proxy redirects the browser back to.
     *
     * The pretty path only resolves through the registered rewrite rule, which
     * WordPress skips entirely on Plain permalinks — so fall back to the raw
     * query var there. The query var is registered either way, so the callback
     * handler works unchanged.
     *
     * @return string
     */
    public function get_redirect_uri(): string {
        if ($this->is_plain_permalinks()) {
            return home_url('/?thinkrank_google_auth=1');
        }

        return home_url('/thinkrank-google-auth/');
    }

    /**
     * Build the admin-facing "Connect with Google" URL.
     *
     * This points at admin-post.php, not at Google — the consent URL (client_id,
     * scopes, redirect_uri) is assembled by the proxy, never by the plugin.
     *
     * @param string $return_url Admin URL to land on when the flow completes.
     * @return string
     */
    public static function get_connect_url(string $return_url = ''): string {
        // Built with add_query_arg rather than wp_nonce_url() because the latter
        // HTML-escapes the ampersands, which breaks the URL once it is handed to
        // JavaScript rather than printed into markup.
        $args = [
            'action'   => 'thinkrank_google_connect',
            '_wpnonce' => wp_create_nonce('thinkrank_google_connect'),
        ];

        if (!empty($return_url)) {
            $args['return'] = rawurlencode($return_url);
        }

        return add_query_arg($args, admin_url('admin-post.php'));
    }

    /**
     * Start the flow: stash the return URL, then bounce to the proxy.
     *
     * @return void
     */
    public function handle_connect(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to connect a Google account.', 'thinkrank'), '', ['response' => 403]);
        }

        check_admin_referer('thinkrank_google_connect');

        // The nonce doubles as the transient key, so only the admin who started
        // the flow can finish it — and only within the nonce lifetime.
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        set_transient(
            'thinkrank_google_oauth_' . $nonce,
            $this->resolve_return_url(),
            15 * MINUTE_IN_SECONDS
        );

        $state = $this->encode_state([
            'r' => $this->get_redirect_uri(),
            'n' => $nonce,
        ]);

        // External host, so wp_safe_redirect() is not applicable here.
        wp_redirect(add_query_arg(
            ['action' => 'connect', 'state' => $state],
            self::get_proxy_url()
        ));
        exit;
    }

    /**
     * Handle the proxy's redirect back: verify, swap, store, return to admin.
     *
     * @return void
     */
    public function handle_callback(): void {
        $flag = (string) get_query_var('thinkrank_google_auth');
        if ('' === $flag) {
            return;
        }

        // With the query-var return URL, a proxy that joins its params with '?'
        // instead of '&' folds them into this var's value. Recover them so the
        // flow still completes rather than failing as invalid_state.
        $separator = strpos($flag, '?');
        if (false !== $separator) {
            parse_str(substr($flag, $separator + 1), $recovered);
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only merge; params are verified below.
            $_GET = array_merge($recovered, $_GET);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the
        // nonce arrives as `thinkrank_g_state` and is verified below.
        $nonce = isset($_GET['thinkrank_g_state']) ? sanitize_text_field(wp_unslash($_GET['thinkrank_g_state'])) : '';
        $exchange_token = isset($_GET['thinkrank_g_exchange']) ? sanitize_text_field(wp_unslash($_GET['thinkrank_g_exchange'])) : '';
        $error = isset($_GET['thinkrank_g_error']) ? sanitize_text_field(wp_unslash($_GET['thinkrank_g_error'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $return_url = '';
        if (!empty($nonce)) {
            $stored = get_transient('thinkrank_google_oauth_' . $nonce);
            if (is_string($stored) && !empty($stored)) {
                $return_url = $stored;
            }
            delete_transient('thinkrank_google_oauth_' . $nonce);
        }
        if (empty($return_url)) {
            $return_url = $this->default_return_url();
        }

        if (!empty($error)) {
            $this->finish($return_url, ['thinkrank_google_error' => $error]);
        }

        if (empty($nonce) || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            $this->finish($return_url, ['thinkrank_google_error' => 'invalid_state']);
        }

        if (!current_user_can('manage_options')) {
            $this->finish($return_url, ['thinkrank_google_error' => 'forbidden']);
        }

        if (empty($exchange_token)) {
            $this->finish($return_url, ['thinkrank_google_error' => 'missing_token']);
        }

        $tokens = $this->exchange($exchange_token);
        if (empty($tokens['access_token'])) {
            $this->finish($return_url, ['thinkrank_google_error' => 'exchange_failed']);
        }

        $this->store_tokens($tokens);
        $this->finish($return_url, ['thinkrank_google_connected' => '1']);
    }

    /**
     * Swap the one-time exchange token for real credentials, server to server.
     *
     * @param string $exchange_token One-time token issued by the proxy.
     * @return array Decoded proxy response, or an empty array on failure.
     */
    private function exchange(string $exchange_token): array {
        $response = wp_remote_post(self::get_proxy_url(), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode([
                'action'         => 'exchange',
                'exchange_token' => $exchange_token,
                'site'           => home_url(),
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Persist the credentials returned by the proxy.
     *
     * @param array $tokens Proxy response.
     * @return void
     */
    private function store_tokens(array $tokens): void {
        $settings = [
            'google_access_token'      => $tokens['access_token'],
            'google_token_created'     => time(),
            'google_token_expires_in'  => (int) ($tokens['expires_in'] ?? 3600),
            'google_account_connected' => true,
        ];

        if (!empty($tokens['refresh_token'])) {
            $settings['google_refresh_token'] = $tokens['refresh_token'];
        }

        $this->settings_manager->update_settings($settings, 'integrations');

        update_option('thinkrank_google_token_contract', self::TOKEN_CONTRACT_VERSION);
        delete_option('thinkrank_google_reconnect_required');

        // Settings memoizes reads, so a stale empty token from before the
        // connect would otherwise trip the credential check on this request.
        $this->settings_manager = new Settings_Manager();
    }

    /**
     * Force sites connected under the old contract to re-authorize.
     *
     * Tokens obtained through the pre-proxy flow can't be refreshed against the
     * new proxy contract, so they are cleared rather than left to fail silently
     * on the next cron run.
     *
     * @return void
     */
    public function maybe_require_reconnect(): void {
        $integrations = $this->settings_manager->get_settings('integrations');

        // One-time migration off the pre-proxy token contract.
        if (get_option('thinkrank_google_token_contract') !== self::TOKEN_CONTRACT_VERSION) {
            if (!empty($integrations['google_account_connected']) || !empty($integrations['google_access_token'])) {
                $this->clear_tokens('contract');
            }

            update_option('thinkrank_google_token_contract', self::TOKEN_CONTRACT_VERSION);

            return;
        }

        // Credentials are encrypted with a key derived from wp_salt('auth').
        // Rotating the salts — or restoring a database without the matching
        // wp-config.php — leaves rows that can never be decrypted. Settings
        // hands back an empty string in that case, so a connection that claims
        // to be live but has no usable token is the signal. Without this the
        // only symptom is Google returning 401 forever.
        if (empty($integrations['google_account_connected'])) {
            return;
        }

        if (empty($integrations['google_access_token']) || empty($integrations['google_refresh_token'])) {
            $this->clear_tokens('credentials');
        }
    }

    /**
     * Drop credentials Google has refused for good.
     *
     * Called from the refresh path, which is static and has no instance to
     * hand, so this is the public entry point onto clear_tokens().
     *
     * @return void
     */
    public static function mark_revoked(): void {
        (new self())->clear_tokens('revoked');
    }

    /**
     * Drop stored credentials and flag the site for re-authorization.
     *
     * @param string $reason Why the reconnect is needed — drives the notice copy.
     * @return void
     */
    private function clear_tokens(string $reason): void {
        $this->settings_manager->update_settings([
            'google_access_token'      => '',
            'google_refresh_token'     => '',
            'google_token_expires_in'  => '',
            'google_token_created'     => '',
            'google_account_connected' => false,
        ], 'integrations');

        update_option('thinkrank_google_reconnect_required', $reason);
    }

    /**
     * Encode the state blob as URL-safe base64.
     *
     * @param array $payload State payload.
     * @return string
     */
    private function encode_state(array $payload): string {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
        return rtrim(strtr(base64_encode((string) wp_json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * Resolve and validate the admin URL to return to after the flow.
     *
     * @return string
     */
    private function resolve_return_url(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by the caller.
        $raw = isset($_GET['return']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['return']))) : '';

        // Only ever return to this site's admin — never an attacker-supplied host.
        if (!empty($raw) && 0 === strpos($raw, admin_url())) {
            return $raw;
        }

        return $this->default_return_url();
    }

    /**
     * The Google Services screen.
     *
     * @return string
     */
    private function default_return_url(): string {
        return admin_url('admin.php?page=thinkrank-essential-seo&nav_section=integrations&nav_item=google-services');
    }

    /**
     * Redirect back into the admin and stop.
     *
     * @param string $return_url Base URL.
     * @param array  $args       Query args to append.
     * @return void
     */
    private function finish(string $return_url, array $args): void {
        wp_safe_redirect(add_query_arg($args, $return_url));
        exit;
    }
}
