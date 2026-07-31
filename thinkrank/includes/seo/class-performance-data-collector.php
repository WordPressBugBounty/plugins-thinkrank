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

        // Register cron hooks
        add_action(self::CRON_HOOK, [$this, 'collect_performance_data']);

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
            // The PageSpeed API itself is public (API key / keyless — see
            // Google_PageSpeed_Client::for_site()), but a Google connection
            // still gates the feature: only collect for connected sites.
            $access_token = $this->get_google_access_token();

            if (empty($access_token)) {
                $this->pagespeed_client = null;
                return;
            }

            $this->pagespeed_client = Google_PageSpeed_Client::for_site();
        } catch (\Exception $e) {
            $this->pagespeed_client = null;
        }
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
     * Collect performance data for the site
     *
     * @param bool $force Bypass the 7-day auto-refresh gate (manual refresh).
     * @return bool Success status
     */
    public function collect_performance_data(bool $force = false): bool {
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
                $device_success = $this->collect_device_performance_data($home_url, $device);
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
            return false;
        }
    }

    /**
     * Collect performance data for specific device type
     *
     * @param string $url         URL to test
     * @param string $device_type Device type (mobile/desktop)
     * @return bool Success status
     */
    private function collect_device_performance_data(string $url, string $device_type): bool {
        try {
            // Check if PageSpeed client is available
            if (!$this->pagespeed_client) {
                return false;
            }

            // One snapshot provides both the Core Web Vitals and the performance
            // score — previously this ran two full Lighthouse audits per device.
            $snapshot = $this->pagespeed_client->get_pagespeed_snapshot($url, $device_type);

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
            

            
            return $stored;
            
        } catch (\Exception $e) {
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
                $results['message'] = __('Failed to collect performance data', 'thinkrank');
                $results['errors'][] = 'Data collection failed';
            }
            
        } catch (\Exception $e) {
            $results['message'] = __('Error during data collection', 'thinkrank');
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
