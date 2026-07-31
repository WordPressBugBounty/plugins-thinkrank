<?php
/**
 * Email Report Data Provider
 *
 * Pulls dashboard data for the current period and the immediately
 * preceding period of equal length, then hands both to sections so they
 * can diff and rank. Sections never call Analytics_Manager directly —
 * one fetch per period, shared across the report.
 *
 * If Analytics_Manager isn't available (no Google connection, etc.) the
 * provider returns an `available => false` result; sections then render
 * their fallback HTML per PRD's graceful-degradation requirement.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Data_Provider
 *
 * @since 1.9.0
 */
final class Email_Report_Data_Provider {

    /**
     * Pull current + prior period dashboard data.
     *
     * @param int $frequency_days Reporting frequency in days.
     * @return array{
     *     available:bool,
     *     current:array,
     *     prior:array,
     *     period_start:string,
     *     period_end:string,
     *     period_label:string,
     *     error?:string
     * }
     */
    public function fetch(int $frequency_days): array {
        $frequency_days = max(1, $frequency_days);
        $today = current_time('Y-m-d');
        $period_end = $today;
        $period_start = wp_date('Y-m-d', strtotime("-{$frequency_days} days", strtotime($today)));
        $period_label = $this->format_period_label($period_start, $period_end);

        $manager = $this->get_analytics_manager();
        if ($manager === null) {
            return [
                'available' => false,
                'current' => [],
                'prior' => [],
                'period_start' => $period_start,
                'period_end' => $period_end,
                'period_label' => $period_label,
                'error' => __('Analytics integration not available.', 'thinkrank'),
            ];
        }

        try {
            $range = $frequency_days . 'd';
            $current = $manager->get_dashboard_data($range);

            // Real period-over-period comparison: pull query- and page-level
            // metrics for the current window AND the immediately preceding
            // window of equal length straight from Search Console, then key
            // them so winning/losing sections can compute true deltas.
            $comparison = $this->build_comparison($manager, $frequency_days);

            return [
                'available' => true,
                'current' => is_array($current) ? $current : [],
                'comparison' => $comparison,
                'period_start' => $period_start,
                'period_end' => $period_end,
                'period_label' => $period_label,
            ];
        } catch (Throwable $e) {
            return [
                'available' => false,
                'current' => [],
                'comparison' => ['available' => false, 'queries' => [], 'pages' => []],
                'period_start' => $period_start,
                'period_end' => $period_end,
                'period_label' => $period_label,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the current-vs-previous comparison from Search Console.
     *
     * Uses the Search Console client's arbitrary date-range API
     * (`get_search_performance_by_dates`) — the same one the Rank Tracker
     * and the Pro winning/losing endpoint use — to fetch query- and
     * page-level rows for two equal, adjacent windows. GSC lags ~2 days, so
     * the current window ends yesterday and the previous window is the N
     * days before it.
     *
     * @param object $manager        Analytics_Manager instance.
     * @param int    $frequency_days Window length in days.
     * @return array{available:bool,queries:array,pages:array}
     */
    private function build_comparison($manager, int $frequency_days): array {
        $empty = ['available' => false, 'queries' => [], 'pages' => []];

        if (!method_exists($manager, 'get_search_console_client')) {
            return $empty;
        }
        $sc = $manager->get_search_console_client();
        if (!$sc || !method_exists($sc, 'get_search_performance_by_dates')) {
            return $empty;
        }
        $site_url = method_exists($manager, 'get_property_url') ? (string) $manager->get_property_url() : '';
        if ($site_url === '') {
            return $empty;
        }

        $cur_end    = gmdate('Y-m-d', strtotime('-1 day'));
        $cur_start  = gmdate('Y-m-d', strtotime('-' . $frequency_days . ' days'));
        $prev_end   = gmdate('Y-m-d', strtotime('-' . ($frequency_days + 1) . ' days'));
        $prev_start = gmdate('Y-m-d', strtotime('-' . ($frequency_days * 2) . ' days'));

        $cur_q  = $sc->get_search_performance_by_dates($site_url, $cur_start, $cur_end, 1000, ['query']);
        $prev_q = $sc->get_search_performance_by_dates($site_url, $prev_start, $prev_end, 1000, ['query']);
        $cur_p  = $sc->get_search_performance_by_dates($site_url, $cur_start, $cur_end, 1000, ['page']);
        $prev_p = $sc->get_search_performance_by_dates($site_url, $prev_start, $prev_end, 1000, ['page']);

        return [
            'available' => true,
            'queries'   => $this->merge_periods($cur_q, $prev_q, true),
            'pages'     => $this->merge_periods($cur_p, $prev_p, false),
        ];
    }

    /**
     * Merge current + previous GSC rows into one keyed map carrying both
     * periods' clicks and (for queries) average position.
     *
     * @param array $current  Current-window rows.
     * @param array $previous Previous-window rows.
     * @param bool  $is_query True for query rows, false for page rows.
     * @return array<string,array>
     */
    private function merge_periods(array $current, array $previous, bool $is_query): array {
        $prev_map = [];
        foreach ($previous as $row) {
            $key = (string) ($row['keys'][0] ?? '');
            if ($key === '') {
                continue;
            }
            $prev_map[$this->normalize_key($key, $is_query)] = $row;
        }

        $merged = [];
        foreach ($current as $row) {
            $raw = (string) ($row['keys'][0] ?? '');
            if ($raw === '') {
                continue;
            }
            $key  = $this->normalize_key($raw, $is_query);
            $prev = $prev_map[$key] ?? null;

            $entry = [
                'cur_clicks'  => (int) ($row['clicks'] ?? 0),
                'prev_clicks' => $prev ? (int) ($prev['clicks'] ?? 0) : 0,
            ];
            if ($is_query) {
                $entry['query']    = $raw;
                $entry['cur_pos']  = round((float) ($row['position'] ?? 0), 1);
                $entry['prev_pos'] = $prev ? round((float) ($prev['position'] ?? 0), 1) : null;
            } else {
                $entry['url'] = $raw;
            }
            $merged[$key] = $entry;
        }

        return $merged;
    }

    private function normalize_key(string $key, bool $is_query): string {
        return $is_query ? trim(strtolower($key)) : $key;
    }

    private function get_analytics_manager() {
        $cls = '\\ThinkRank\\SEO\\Analytics_Manager';
        if (!class_exists($cls)) {
            return null;
        }
        try {
            return new $cls();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function format_period_label(string $start, string $end): string {
        $fmt = (string) get_option('date_format', 'M j, Y');
        $a = wp_date($fmt, strtotime($start) ?: time());
        $b = wp_date($fmt, strtotime($end) ?: time());
        return sprintf('%s – %s', $a, $b);
    }
}
