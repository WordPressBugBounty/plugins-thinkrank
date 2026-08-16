<?php
/**
 * Performance Data Collector Class
 *
 * Automated system for collecting and storing performance data from Google PageSpeed Insights.
 * Runs on WordPress cron to build historical performance tracking over time.
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ThinkRank\Core\Settings;
use ThinkRank\Integrations\Google_PageSpeed_Client;

/**
 * Performance Data Collector Class
 *
 * Handles automated collection and storage of performance data for historical tracking.
 * Integrates with WordPress cron system for scheduled data collection.
 *
 * @since 1.0.0
 */
class Performance_Data_Collector {

    /**
     * Performance Monitoring Manager instance (lazy — only built when a
     * collection actually runs, not on every request)
     *
     * @var Performance_Monitoring_Manager|null
     */
    private ?Performance_Monitoring_Manager $performance_manager = null;

    /**
     * Google PageSpeed Client instance
     *
     * @var Google_PageSpeed_Client|null
     */
    private ?Google_PageSpeed_Client $pagespeed_client;

    /**
     * Cron hook name for data collection
     */
    private const CRON_HOOK = 'thinkrank_collect_performance_data';

    /**
     * Constructor
     */
    public function __construct() {
        $this->pagespeed_client = null;

        // Register cron hooks. Routed through the wrapper so a scheduled failure
        // gets logged — WP-Cron throws the return value away.
        add_action(self::CRON_HOOK, [$this, 'collect_performance_data_via_cron']);

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Get the Performance Monitoring Manager, constructing it on first use.
     *
     * The collector is instantiated on every request (bootstrap + REST), so the
     * manager chain (Settings_Manager, SEO_Settings_Manager, …) must not be
     * built until a collection actually needs it.
     *
     * @return Performance_Monitoring_Manager
     */
    private function get_performance_manager(): Performance_Monitoring_Manager {
        if ($this->performance_manager === null) {
            $this->performance_manager = new Performance_Monitoring_Manager();
        }
        return $this->performance_manager;
    }

    /**
     * Initialize PageSpeed client with proper error handling
     *
     * @return void
     */
    private function initialize_pagespeed_client(): void {
        // Ensure the Google PageSpeed Client class is loaded
        if (!class_exists('ThinkRank\\Integrations\\Google_PageSpeed_Client')) {
            $pagespeed_file = THINKRANK_PLUGIN_DIR . 'includes/integrations/class-google-pagespeed-client.php';
            if (file_exists($pagespeed_file)) {
                require_once $pagespeed_file;
            }
        }

        // Ensure the base client is loaded
        if (!class_exists('ThinkRank\\Integrations\\Google_API_Base_Client')) {
            $base_client_file = THINKRANK_PLUGIN_DIR . 'includes/integrations/class-google-api-base-client.php';
            if (file_exists($base_client_file)) {
                require_once $base_client_file;
            }
        }

        try {
            // Either credential is enough. This used to require an OAuth token,
            // which locked out sites configured with only a PageSpeed API key —
            // the credential Google_PageSpeed_Client::for_site() actually
            // *prefers*, since a dedicated key bills its own project quota. Those
            // sites could never collect and got the same generic failure.
            if (!$this->has_pagespeed_credentials()) {
                $this->pagespeed_client = null;
                $this->last_error = __('Connect Google or add a PageSpeed API key to collect Core Web Vitals.', 'thinkrank');
                $this->last_error_code = self::ERROR_NOT_CONFIGURED;
                return;
            }

            $this->pagespeed_client = Google_PageSpeed_Client::for_site();
        } catch (\Exception $e) {
            $this->pagespeed_client = null;
            $this->last_error = $e->getMessage();
            $this->last_error_code = self::ERROR_NOT_CONFIGURED;
        }
    }

    /**
     * Whether this site has a credential the PageSpeed API will accept.
     *
     * @return bool
     */
    private function has_pagespeed_credentials(): bool {
        if ($this->get_google_pagespeed_api_key() !== '') {
            return true;
        }

        return $this->get_google_access_token() !== '';
    }

    /**
     * Get the site-owned PageSpeed API key from settings.
     *
     * @return string API key, or an empty string when not configured.
     */
    private function get_google_pagespeed_api_key(): string {
        $api_key = (new Settings())->get('google_pagespeed_api_key', '');
        return is_string($api_key) ? trim($api_key) : '';
    }

    /**
     * Get Google OAuth access token from settings
     *
     * @return string Access token or empty string if not configured
     */
    private function get_google_access_token(): string {
        // OAuth tokens are encrypted at rest; Settings::get() decrypts them.
        // Reading the raw option yields ciphertext that PageSpeed rejects with a 401.
        $access_token = (new Settings())->get('google_access_token', '');
        return is_string($access_token) ? $access_token : '';
    }

    /**
     * Option storing the timestamp of the last successful collection,
     * used by the 7-day auto-refresh gate.
     */
    private const LAST_COLLECTED_OPTION = 'thinkrank_cwv_last_collected';

    /**
     * How long a successful measurement satisfies automatic collections.
     * Lighthouse lab data is effectively static week-to-week (RankMath uses
     * the same 7-day gate), and staying frugal keeps every install inside
     * the shared PageSpeed quota.
     */
    private const AUTO_REFRESH_GAP = 7 * DAY_IN_SECONDS;

    /**
     * Failure classes a collection can end in. Every one of these used to
     * collapse into a bare `false` and then into the literal string
     * "Data collection failed", which told the user nothing and made the REST
     * route answer 500 for conditions that are not server faults.
     */
    public const ERROR_NOT_CONFIGURED = 'not_configured';
    public const ERROR_URL_UNREACHABLE = 'url_unreachable';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_RECENT_FAILURE = 'recent_failure';
    public const ERROR_STORAGE_FAILED = 'storage_failed';
    public const ERROR_API_FAILED = 'api_failed';

    /**
     * Human-readable reason the last collection failed.
     *
     * @var string
     */
    private string $last_error = '';

    /**
     * Machine-readable class of the last failure — one of the ERROR_* constants.
     *
     * @var string
     */
    private string $last_error_code = '';

    /**
     * Collect performance data for the site
     *
     * @param bool $force Bypass the 7-day auto-refresh gate (manual refresh).
     * @return bool Success status
     */
    public function collect_performance_data(bool $force = false): bool {
        $this->last_error = '';
        $this->last_error_code = '';

        try {
            // Auto-collections (cron / background) re-measure at most every
            // 7 days; only an explicit user refresh forces a new audit.
            if (!$force) {
                $last = (int) get_option(self::LAST_COLLECTED_OPTION, 0);
                if ($last && (time() - $last) < self::AUTO_REFRESH_GAP) {
                    return true;
                }
            }

            // Build the client here rather than in the constructor: the collector is
            // instantiated on ordinary requests too, and the token must be read (and
            // refreshed) at collection time to avoid using a stale one.
            $this->initialize_pagespeed_client();

            $home_url = home_url();

            // Test both mobile and desktop
            $devices = ['mobile', 'desktop'];
            $success = true;

            foreach ($devices as $device) {
                $device_success = $this->collect_device_performance_data($home_url, $device, $force);
                if (!$device_success) {
                    $success = false;
                }
            }

            if ($success) {
                update_option(self::LAST_COLLECTED_OPTION, time(), false);
            }

            // Run data cleanup (keep 1 year of data)
            $this->cleanup_old_data(365);

            return $success;

        } catch (\Exception $e) {
            $this->record_error($e);
            return false;
        }
    }

    /**
     * Cron entry point.
     *
     * WP-Cron discards a callback's return value, so a hook that returns false is
     * still reported as having run successfully — this collection could fail on
     * every scheduled pass with the only evidence being an empty table. Log the
     * reason instead.
     *
     * @since 1.31.0
     * @return void
     */
    public function collect_performance_data_via_cron(): void {
        if ($this->collect_performance_data()) {
            return;
        }

        error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the only record that a silent cron failure happened.
            sprintf(
                'ThinkRank [performance]: scheduled Core Web Vitals collection failed (%s) — %s',
                $this->last_error_code !== '' ? $this->last_error_code : 'unknown',
                $this->last_error !== '' ? $this->last_error : 'no reason reported'
            )
        );
    }

    /**
     * Reason the last collection failed, for the REST layer to report.
     *
     * @since 1.31.0
     * @return array{code: string, message: string} Empty strings when the last
     *         run did not fail.
     */
    public function get_last_error(): array {
        return [
            'code' => $this->last_error_code,
            'message' => $this->last_error,
        ];
    }

    /**
     * Classify an exception from the PageSpeed call into a failure class.
     *
     * The distinctions matter to the caller: an unreachable site and an
     * exhausted quota need different advice, and neither is a server fault.
     *
     * @since 1.31.0
     * @param \Exception $e Exception thrown while collecting.
     * @return void
     */
    private function record_error(\Exception $e): void {
        $message = $e->getMessage();
        $this->last_error = $message;

        if ((int) $e->getCode() === Google_PageSpeed_Client::CODE_REMEMBERED_FAILURE) {
            $this->last_error_code = self::ERROR_RECENT_FAILURE;
            return;
        }

        // Lighthouse could not load the page: not public, DNS/TLS failure, or the
        // server refused the fetch.
        if (stripos($message, 'FAILED_DOCUMENT_REQUEST') !== false
            || stripos($message, 'ERRORED_DOCUMENT_REQUEST') !== false
            || stripos($message, 'DNS_FAILURE') !== false
            || stripos($message, 'net::') !== false) {
            $this->last_error_code = self::ERROR_URL_UNREACHABLE;
            return;
        }

        // The base client throws with the HTTP status as the exception code.
        if ((int) $e->getCode() === 429
            || stripos($message, 'rate limit') !== false
            || stripos($message, 'quota') !== false) {
            $this->last_error_code = self::ERROR_RATE_LIMITED;
            return;
        }

        $this->last_error_code = self::ERROR_API_FAILED;
    }

    /**
     * Collect performance data for specific device type
     *
     * @param string $url         URL to test
     * @param string $device_type Device type (mobile/desktop)
     * @return bool Success status
     */
    private function collect_device_performance_data(string $url, string $device_type, bool $force = false): bool {
        try {
            // Check if PageSpeed client is available
            if (!$this->pagespeed_client) {
                if ($this->last_error === '') {
                    $this->last_error = __('Connect Google or add a PageSpeed API key to collect Core Web Vitals.', 'thinkrank');
                    $this->last_error_code = self::ERROR_NOT_CONFIGURED;
                }
                return false;
            }

            // One snapshot provides both the Core Web Vitals and the performance
            // score — previously this ran two full Lighthouse audits per device.
            $snapshot = $this->pagespeed_client->get_pagespeed_snapshot($url, $device_type, $force);

            // Prepare data for storage
            $performance_data = $snapshot['core_web_vitals'];
            $performance_data['performance_score'] = $snapshot['performance_score'];
            
            // Store in database
            $stored = $this->get_performance_manager()->store_historical_performance_data(
                $performance_data,
                'site',
                null,
                $device_type
            );
            

            
            if (!$stored) {
                $this->last_error = sprintf(
                    /* translators: %s: device type (mobile or desktop). */
                    __('Measured %s successfully but could not store the result.', 'thinkrank'),
                    $device_type
                );
                $this->last_error_code = self::ERROR_STORAGE_FAILED;
            }

            return $stored;

        } catch (\Exception $e) {
            $this->record_error($e);
            return false;
        }
    }

    /**
     * Manually trigger data collection (for testing or immediate collection)
     *
     * @return array Collection results
     */
    public function manual_collect(): array {
        $results = [
            'success' => false,
            'message' => '',
            'data_collected' => false,
            'error_code' => '',
            'errors' => []
        ];
        
        try {
            // Manual refresh always re-measures (bypasses the 7-day gate).
            $success = $this->collect_performance_data(true);

            if ($success) {
                $results['success'] = true;
                $results['data_collected'] = true;
                $results['message'] = __('Performance data collected successfully', 'thinkrank');
            } else {
                $error = $this->get_last_error();
                $results['message'] = $error['message'] !== ''
                    ? $error['message']
                    : __('Failed to collect performance data', 'thinkrank');
                $results['error_code'] = $error['code'];
                $results['errors'][] = $results['message'];
            }

        } catch (\Exception $e) {
            $this->record_error($e);
            $error = $this->get_last_error();
            $results['message'] = $error['message'] !== ''
                ? $error['message']
                : __('Error during data collection', 'thinkrank');
            $results['error_code'] = $error['code'];
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Get collection schedule information
     *
     * @return array Schedule information
     */
    public function get_schedule_info(): array {
        $next_scheduled = wp_next_scheduled(self::CRON_HOOK);

        return [
            'is_scheduled' => $next_scheduled !== false,
            'next_run' => $next_scheduled ? gmdate('Y-m-d H:i:s', $next_scheduled) : null,
            'next_run_human' => $next_scheduled ? human_time_diff($next_scheduled) : null,
            'cron_hook' => self::CRON_HOOK,
            'frequency' => 'daily'
        ];
    }

    /**
     * Clean up old performance data (data retention policy)
     *
     * @param int $days Number of days to keep (default: 365 days = 1 year)
     * @return int Number of records deleted
     */
    public function cleanup_old_data(int $days = 365): int {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_performance';
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = sprintf(
            "DELETE FROM %s WHERE measured_at < %%s",
            $table_name
        );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance data cleanup requires direct database access
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $wpdb->prepare($sql, $cutoff_date)
        );

        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Reschedule data collection
     *
     * @param string $frequency Cron frequency (hourly, daily, weekly)
     * @return bool Success status
     */
    public function reschedule_collection(string $frequency = 'daily'): bool {
        try {
            // Clear existing schedule
            wp_clear_scheduled_hook(self::CRON_HOOK);
            
            // Schedule new collection
            $scheduled = wp_schedule_event(time(), $frequency, self::CRON_HOOK);
            
            return $scheduled !== false;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear scheduled data collection
     *
     * @return bool Success status
     */
    public function clear_schedule(): bool {
        try {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get recent collection statistics
     *
     * @param int $days Number of days to check
     * @return array Collection statistics
     */
    public function get_collection_stats(int $days = 7): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'thinkrank_seo_performance';
        $start_date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        try {
            $sql = sprintf("
                SELECT
                    COUNT(*) as total_records,
                    COUNT(DISTINCT DATE(measured_at)) as days_with_data,
                    COUNT(DISTINCT device_type) as device_types,
                    MIN(measured_at) as first_measurement,
                    MAX(measured_at) as last_measurement
                FROM %s
                WHERE measured_at >= %%s
                    AND context_type = 'site'
                    AND measured_by = 'google_pagespeed'
            ", $table_name);
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance statistics retrieval requires direct database access
            $stats = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                $wpdb->prepare($sql, $start_date),
                ARRAY_A
            );
            
            return [
                'total_records' => (int) ($stats['total_records'] ?? 0),
                'days_with_data' => (int) ($stats['days_with_data'] ?? 0),
                'device_types' => (int) ($stats['device_types'] ?? 0),
                'first_measurement' => $stats['first_measurement'] ?? null,
                'last_measurement' => $stats['last_measurement'] ?? null,
                'collection_rate' => $stats['days_with_data'] ? round(($stats['days_with_data'] / $days) * 100, 1) : 0
            ];
            
        } catch (\Exception $e) {
            return [
                'total_records' => 0,
                'days_with_data' => 0,
                'device_types' => 0,
                'first_measurement' => null,
                'last_measurement' => null,
                'collection_rate' => 0
            ];
        }
    }
}
