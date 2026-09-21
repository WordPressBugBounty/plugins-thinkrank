<?php
/**
 * Email Report Data Provider
 *
 * Pulls dashboard data for the current period and the immediately
 * preceding period of equal length, then hands both to sections so they
 * can diff and rank. Sections never call Analytics_Manager directly —
 * one fetch per period, shared across the report.
 *
 * readiness() answers, before any data is pulled, whether there is a report
 * to build: Search Console is required, Google Analytics 4 and the AI
 * traffic tracker each add a card when present (#742). The generator asks
 * this first so a disconnected site is paused with a reason rather than
 * fetched, rendered and sent as a column of blanks.
 *
 * If Analytics_Manager isn't available the provider returns an
 * `available => false` result and sections collect nothing.
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
     * Which data sources the report can draw on right now.
     *
     * Cheap on purpose — no dashboard fetch, no Search Console query. It
     * runs on every hourly tick while a site is paused and on every load of
     * the Email Reporting panel.
     *
     * @return array{
     *     ready:bool,
     *     search_console:bool,
     *     analytics:bool,
     *     ai_traffic:bool,
     *     reason:?string
     * }
     */
    public function readiness(): array {
        // Credentials, not client objects. Analytics_Manager constructs a
        // Search Console client whether or not a token exists, so "is there
        // a client?" is always yes. The predicates below are the ones
        // get-integrations-status reports, so the panel, the ability and
        // the report can never disagree about the same site.
        $settings = $this->settings();

        $oauth_present  = '' !== (string) $settings->get('google_access_token', '');
        $search_console = $oauth_present
            || '' !== (string) $settings->get('google_search_console_api_key', '');

        // GA4: the Data API client is built only when a token AND a selected
        // property both exist — mirror that exactly.
        $ga_property = (string) $settings->get('seo_analytics_google_analytics_property_id', '');
        $analytics   = ($oauth_present && '' !== $ga_property)
            || '' !== (string) $settings->get('google_analytics_api_key', '');

        $readiness = [
            'ready'          => $search_console,
            'search_console' => $search_console,
            'analytics'      => $analytics,
            'ai_traffic'     => $this->ai_tracker_has_data(),
            'reason'         => $search_console ? null : 'search_console_not_connected',
        ];

        /**
         * Filter the report's readiness.
         *
         * Lets a host that feeds the report from somewhere other than the
         * Google integrations declare itself ready, and lets tests force
         * either state.
         *
         * @since 2.8.0
         *
         * @param array $readiness See readiness().
         */
        $filtered = apply_filters('thinkrank_email_report_readiness', $readiness);

        return is_array($filtered) ? array_merge($readiness, $filtered) : $readiness;
    }

    /**
     * Subject-line tokens for a fetched report (#742).
     *
     * `%headline%` is the sentence the default subject is built from:
     * "12,480 Google clicks (+12.4%) in the last 30 days" when both windows
     * have totals, "Your SEO report for Aug 19 – Sep 17" when there is
     * nothing to compare. The parts are exposed as their own tokens so a
     * custom subject template can rebuild it differently.
     *
     * @param array $shared         Output of fetch().
     * @param int   $frequency_days Report window in days.
     * @return array<string,string> Token => value.
     */
    public static function subject_tokens(array $shared, int $frequency_days): array {
        $days     = max(1, $frequency_days);
        $label    = (string) ($shared['period_label'] ?? '');
        $totals   = $shared['comparison']['totals'] ?? [];
        $current  = is_array($totals['current'] ?? null) ? $totals['current'] : [];
        $previous = is_array($totals['previous'] ?? null) ? $totals['previous'] : [];

        $clicks      = (int) ($current['clicks'] ?? 0);
        $impressions = (int) ($current['impressions'] ?? 0);
        if ($clicks === 0 && $impressions === 0) {
            $dash        = $shared['current']['search_performance']['totals'] ?? [];
            $clicks      = (int) ($dash['clicks'] ?? 0);
            $impressions = (int) ($dash['impressions'] ?? 0);
        }

        $change = null;
        if ((int) ($previous['clicks'] ?? 0) > 0 && class_exists(Email_Report_Sections\Email_Report_Html::class)) {
            $change = Email_Report_Sections\Email_Report_Html::pct_change((float) $clicks, (float) ($previous['clicks'] ?? 0));
        }
        $signed = $change !== null && $change['direction'] !== 'flat'
            ? ($change['direction'] === 'up' ? '+' : '−') . $change['text']
            : '';

        if ($clicks > 0 || $impressions > 0) {
            $headline = sprintf(
                /* translators: 1: number of clicks, 2: change in parentheses or empty, 3: number of days. */
                _n('%1$s Google clicks%2$s in the last %3$d day', '%1$s Google clicks%2$s in the last %3$d days', $days, 'thinkrank'),
                number_format_i18n($clicks),
                $signed !== '' ? ' (' . $signed . ')' : '',
                $days
            );
        } else {
            $headline = $label !== ''
                ? sprintf(
                    /* translators: %s: period label, e.g. "Aug 19 – Sep 17, 2026". */
                    __('Your SEO report for %s', 'thinkrank'),
                    $label
                )
                : __('Your SEO report', 'thinkrank');
        }

        return [
            '%period%'        => $label,
            '%period_days%'   => (string) $days,
            '%clicks%'        => number_format_i18n($clicks),
            '%clicks_change%' => $signed,
            '%impressions%'   => number_format_i18n($impressions),
            '%headline%'      => $headline,
        ];
    }

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

        // One canonical window for the whole report. Search Console lags
        // ~2 days, so it ends on the last date that actually has data —
        // the same anchor Analytics_Manager::get_dashboard_data() uses for
        // Key Metrics and Position Summary. Previously the header label ran
        // through today and the comparison through yesterday, so a reader
        // was handed three windows and told they were one.
        $period_end = gmdate('Y-m-d', strtotime('-2 days'));
        $period_start = gmdate(
            'Y-m-d',
            strtotime('-' . ($frequency_days - 1) . ' days', strtotime($period_end))
        );
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
            $comparison = $this->build_comparison($manager, $frequency_days, $period_start, $period_end);

            return [
                'available' => true,
                'readiness' => $this->readiness(),
                'current' => is_array($current) ? $current : [],
                'comparison' => $comparison,
                'ai' => $this->ai_summary($frequency_days),
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
     * page-level rows for two equal, adjacent windows.
     *
     * The current window is handed in by fetch() rather than recomputed
     * here, so the deltas describe exactly the period the report's header
     * advertises. The previous window is the same length, immediately
     * before it, with no gap or overlap.
     *
     * @param object $manager        Analytics_Manager instance.
     * @param int    $frequency_days Window length in days.
     * @param string $cur_start      Current window start (Y-m-d).
     * @param string $cur_end        Current window end (Y-m-d).
     * @return array{available:bool,queries:array,pages:array}
     */
    private function build_comparison($manager, int $frequency_days, string $cur_start, string $cur_end): array {
        $empty = ['available' => false, 'queries' => [], 'pages' => [], 'totals' => []];

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

        // Previous window: the same number of days, ending the day before
        // the current window opens.
        $prev_end   = gmdate('Y-m-d', strtotime('-1 day', strtotime($cur_start)));
        $prev_start = gmdate('Y-m-d', strtotime('-' . ($frequency_days - 1) . ' days', strtotime($prev_end)));

        $cur_q  = $sc->get_search_performance_by_dates($site_url, $cur_start, $cur_end, 1000, ['query']);
        $prev_q = $sc->get_search_performance_by_dates($site_url, $prev_start, $prev_end, 1000, ['query']);
        $cur_p  = $sc->get_search_performance_by_dates($site_url, $cur_start, $cur_end, 1000, ['page']);
        $prev_p = $sc->get_search_performance_by_dates($site_url, $prev_start, $prev_end, 1000, ['page']);

        // Whole-property totals for both windows. A query with no
        // dimensions returns one aggregated row, so the hero's clicks,
        // impressions, CTR and position — and their change — are exact
        // rather than summed from the 1,000-row query lists above.
        $cur_t  = $sc->get_search_performance_by_dates($site_url, $cur_start, $cur_end, 1, []);
        $prev_t = $sc->get_search_performance_by_dates($site_url, $prev_start, $prev_end, 1, []);

        return [
            'available' => true,
            'queries'   => $this->merge_periods($cur_q, $prev_q, true),
            'pages'     => $this->merge_periods($cur_p, $prev_p, false),
            'totals'    => [
                'current'  => $this->totals_row($cur_t),
                'previous' => $this->totals_row($prev_t),
            ],
        ];
    }

    /**
     * Normalise the single aggregate row Search Console returns for a
     * dimensionless query. An empty result (a property with no traffic in
     * the window) yields zeroes, which the hero treats as "no comparison".
     *
     * @param array $rows API rows.
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    private function totals_row(array $rows): array {
        $row = is_array($rows[0] ?? null) ? $rows[0] : [];
        return [
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr'         => (float) ($row['ctr'] ?? 0.0),
            'position'    => (float) ($row['position'] ?? 0.0),
        ];
    }

    /**
     * AI-assistant traffic for the current window and the one before it,
     * from the first-party tracker AI Insights already runs. Null when the
     * tracker is absent or has recorded nothing.
     *
     * The tracker summarises a trailing window, so the previous period is
     * the double window minus the current one.
     *
     * @return array{current:array,previous:array}|null
     */
    private function ai_summary(int $frequency_days): ?array {
        $tracker = $this->get_ai_tracker();
        if ($tracker === null) {
            return null;
        }
        try {
            $current = (array) $tracker->summary($frequency_days);
            if ((int) ($current['ai_sessions'] ?? 0) === 0 && (int) ($current['baseline'] ?? 0) === 0) {
                return null;
            }
            $double   = (array) $tracker->summary($frequency_days * 2);
            $previous = [
                'ai_sessions' => max(0, (int) ($double['ai_sessions'] ?? 0) - (int) ($current['ai_sessions'] ?? 0)),
                'baseline'    => max(0, (int) ($double['baseline'] ?? 0) - (int) ($current['baseline'] ?? 0)),
            ];
            return ['current' => $current, 'previous' => $previous];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function ai_tracker_has_data(): bool {
        $tracker = $this->get_ai_tracker();
        if ($tracker === null) {
            return false;
        }
        try {
            $summary = (array) $tracker->summary(30);
            return (int) ($summary['ai_sessions'] ?? 0) > 0 || (int) ($summary['baseline'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function get_ai_tracker() {
        $cls = '\\ThinkRank\\SEO\\Ai_Traffic_Tracker';
        if (!class_exists($cls) || !method_exists($cls, 'summary')) {
            return null;
        }
        try {
            return new $cls();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Merge current + previous GSC rows into one keyed map carrying both
     * periods' clicks and (for queries) average position.
     *
     * The key set is the union of both windows. Search Console omits rows
     * with no activity in a window, so a page or query that dropped to zero
     * clicks has no current row at all — keying off `$current` alone would
     * silently discard exactly the biggest losers the losing sections exist
     * to surface.
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

        // Total drop-outs: present last period, absent now. Synthesize them
        // from the previous window with the current metrics zeroed. Position
        // stays null rather than 0 — "no data" is not "ranked first".
        foreach ($prev_map as $key => $prev_row) {
            if (isset($merged[$key])) {
                continue;
            }
            $raw = (string) ($prev_row['keys'][0] ?? '');
            if ($raw === '') {
                continue;
            }

            $entry = [
                'cur_clicks'  => 0,
                'prev_clicks' => (int) ($prev_row['clicks'] ?? 0),
            ];
            if ($is_query) {
                $entry['query']    = $raw;
                $entry['cur_pos']  = null;
                $entry['prev_pos'] = round((float) ($prev_row['position'] ?? 0), 1);
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

    /**
     * The plugin settings store, or a null-object when it is not loaded
     * (unit tests without the core classes), which reads as "nothing
     * configured".
     */
    private function settings() {
        $cls = '\\ThinkRank\\Core\\Settings';
        if (class_exists($cls) && method_exists($cls, 'instance')) {
            try {
                return $cls::instance();
            } catch (Throwable $e) {
                // Fall through to the null object.
            }
        }
        return new class() {
            public function get(string $key, $fallback = null) {
                return $fallback;
            }
        };
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
        return sprintf('%s – %s', $this->format_day($start, $fmt), $this->format_day($end, $fmt));
    }

    /**
     * Render a bare Y-m-d as a localized date.
     *
     * Anchored at midday UTC on purpose: wp_date() shifts the timestamp into
     * the site timezone, and a date parsed at midnight would render as the
     * day before on any negative offset. Midday leaves the calendar date
     * intact across every real-world offset.
     */
    private function format_day(string $date, string $format): string {
        $ts = strtotime($date . ' 12:00:00 UTC');
        return wp_date($format, $ts ?: time());
    }
}
