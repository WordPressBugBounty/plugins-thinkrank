<?php
/**
 * AI referral traffic + AI crawler tracking.
 *
 * AI platforms (ChatGPT, Perplexity, Gemini, Claude, Copilot…) send real human
 * visitors, but analytics tools misattribute much of it: the platforms strip
 * or rewrite referrers, so GA4 files a large share under "Direct". WordPress
 * serves its own pages, so — unlike a hosted storefront — the plugin IS in the
 * request path and can read the referrer first-party, with no pixel and no
 * JavaScript.
 *
 * What is stored (and deliberately nothing more): daily aggregate counters,
 * one row per (day, kind, source, path). Three kinds:
 *
 *   referral — a human pageview whose referrer host matched an AI platform
 *   crawler  — a request whose user agent matched a known AI crawler
 *   baseline — every human pageview (source 'all', no path), so the dashboard
 *              can say "AI referrals are N% of traffic" without Google
 *
 * No IPs, no raw user agents, no cookies, no per-visit rows — nothing that
 * identifies a visitor. That keeps the table small and the feature clean
 * under wordpress.org privacy expectations.
 *
 * @package ThinkRank\SEO
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records AI referrals/crawlers and serves the dashboard summary.
 */
class Ai_Traffic_Tracker {

    /**
     * Cron hook for pruning old aggregate rows.
     */
    private const PRUNE_HOOK = 'thinkrank_ai_traffic_prune';

    /**
     * Days of history to keep. The dashboard reads 30; keep 6 months so a
     * longer range is possible later without changing collection.
     */
    private const RETENTION_DAYS = 180;

    /**
     * Referrer host fragments → platform slug. Checked with substring match
     * against the referrer host, so subdomains are covered.
     *
     * @var array<string, string>
     */
    private const REFERRER_PLATFORMS = [
        'chatgpt.com'           => 'chatgpt',
        'chat.openai.com'       => 'chatgpt',
        'perplexity.ai'         => 'perplexity',
        'pplx.ai'               => 'perplexity',
        'gemini.google.com'     => 'gemini',
        'bard.google.com'       => 'gemini',
        'claude.ai'             => 'claude',
        'copilot.microsoft.com' => 'copilot',
        'meta.ai'               => 'meta-ai',
        'you.com'               => 'you',
        'poe.com'               => 'poe',
        'grok.com'              => 'grok',
        'x.ai'                  => 'grok',
        'chat.mistral.ai'       => 'mistral',
        'chat.deepseek.com'     => 'deepseek',
        'kimi.com'              => 'kimi',
    ];

    /**
     * User-agent fragments → AI crawler slug. Case-insensitive substring
     * match. Order matters where one token contains another — more specific
     * entries first.
     *
     * @var array<string, string>
     */
    private const CRAWLER_AGENTS = [
        'OAI-SearchBot'        => 'oai-searchbot',
        'ChatGPT-User'         => 'chatgpt-user',
        'GPTBot'               => 'gptbot',
        'Perplexity-User'      => 'perplexity-user',
        'PerplexityBot'        => 'perplexitybot',
        'Claude-SearchBot'     => 'claude-searchbot',
        'Claude-User'          => 'claude-user',
        'ClaudeBot'            => 'claudebot',
        'anthropic-ai'         => 'anthropic-ai',
        'Google-Extended'      => 'google-extended',
        'Applebot-Extended'    => 'applebot-extended',
        'meta-externalagent'   => 'meta-externalagent',
        'meta-externalfetcher' => 'meta-externalfetcher',
        'Bytespider'           => 'bytespider',
        'Amazonbot'            => 'amazonbot',
        'CCBot'                => 'ccbot',
        'cohere-ai'            => 'cohere-ai',
        'MistralAI-User'       => 'mistral-user',
    ];

    /**
     * Wire the front-end recorder and the retention cron.
     *
     * @return void
     */
    public function init(): void {
        // Priority 1: record before any template logic can redirect/exit.
        add_action('template_redirect', [$this, 'record'], 1);

        add_action(self::PRUNE_HOOK, [$this, 'prune']);
        if (!wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    /**
     * Classify a referrer URL as an AI platform.
     *
     * @param string $referrer Full referrer URL (may be empty).
     * @return string|null Platform slug, or null when not an AI platform.
     */
    public static function classify_referrer(string $referrer): ?string {
        if ('' === $referrer) {
            return null;
        }

        $host = strtolower((string) wp_parse_url($referrer, PHP_URL_HOST));
        if ('' === $host) {
            return null;
        }

        foreach (self::REFERRER_PLATFORMS as $fragment => $slug) {
            // Suffix match on the host so evil.com/?q=claude.ai can't spoof
            // via path, and subdomains (www.perplexity.ai) still match.
            if ($host === $fragment || str_ends_with($host, '.' . $fragment)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Classify a user agent as an AI crawler.
     *
     * @param string $user_agent Raw user agent (may be empty).
     * @return string|null Crawler slug, or null when not a known AI crawler.
     */
    public static function classify_crawler(string $user_agent): ?string {
        if ('' === $user_agent) {
            return null;
        }

        foreach (self::CRAWLER_AGENTS as $fragment => $slug) {
            if (false !== stripos($user_agent, $fragment)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Record the current front-end request into the daily aggregates.
     *
     * @return void
     */
    public function record(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (is_feed() || is_preview() || is_robots() || is_404()) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ('GET' !== $method) {
            return;
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- classified, never stored raw.

        // AI crawler: count it and stop — a bot is not part of the human
        // baseline and has no meaningful referrer.
        $bot = self::classify_crawler($user_agent);
        if (null !== $bot) {
            $this->bump('crawler', $bot);
            return;
        }

        // Editors/admins browsing their own site would skew small sites.
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return;
        }

        $this->bump('baseline', 'all');

        $referrer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- classified, never stored raw.
        $platform = self::classify_referrer($referrer);
        if (null !== $platform) {
            $this->bump('referral', $platform, $this->current_path());
        }
    }

    /**
     * The current request path, normalized for the aggregate key.
     *
     * @return string
     */
    private function current_path(): string {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to its path component below.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ('' === $path) {
            $path = '/';
        }
        return substr($path, 0, 191);
    }

    /**
     * Increment one daily aggregate bucket.
     *
     * @param string $kind   'referral' | 'crawler' | 'baseline'.
     * @param string $source Platform/bot slug, or 'all' for baseline.
     * @param string $path   Landing path (referrals only).
     * @return void
     */
    private function bump(string $kind, string $source, string $path = ''): void {
        global $wpdb;

        $table = $wpdb->prefix . 'thinkrank_ai_traffic';

        // Single cheap upsert per pageview; the unique key is the bucket.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- aggregate counter upsert; table name is prefix-derived.
        $wpdb->query(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
            $wpdb->prepare(
                "INSERT INTO {$table} (day, kind, source, path, hits) VALUES (%s, %s, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1",
                current_time('Y-m-d'),
                $kind,
                $source,
                $path
            )
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Dashboard summary for the last N days.
     *
     * @param int $days Range in days (bounded 1–180).
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array {
        global $wpdb;

        $days  = max(1, min(self::RETENTION_DAYS, $days));
        $table = $wpdb->prefix . 'thinkrank_ai_traffic';
        $since = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregates over our own table.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT day, kind, source, path, hits FROM {$table} WHERE day >= %s",
                $since
            ),
            ARRAY_A
        );
        // phpcs:enable

        $baseline  = 0;
        $referrals = 0;
        $platforms = [];
        $trend     = [];
        $pages     = [];
        $crawlers  = [];

        foreach ((array) $rows as $row) {
            $hits = (int) $row['hits'];
            switch ($row['kind']) {
                case 'baseline':
                    $baseline += $hits;
                    break;
                case 'referral':
                    $referrals += $hits;
                    $platforms[$row['source']] = ($platforms[$row['source']] ?? 0) + $hits;
                    $trend[$row['day']]        = ($trend[$row['day']] ?? 0) + $hits;
                    if ('' !== $row['path']) {
                        $pages[$row['path']] = ($pages[$row['path']] ?? 0) + $hits;
                    }
                    break;
                case 'crawler':
                    $crawlers[$row['source']] = ($crawlers[$row['source']] ?? 0) + $hits;
                    break;
            }
        }

        arsort($platforms);
        arsort($pages);
        arsort($crawlers);
        ksort($trend);

        return [
            'days'         => $days,
            'baseline'     => $baseline,
            'ai_sessions'  => $referrals,
            'ai_share'     => $baseline > 0 ? round($referrals / $baseline * 100, 1) : 0.0,
            'platforms'    => $platforms,
            'trend'        => $trend,
            'top_pages'    => array_slice($pages, 0, 10, true),
            'crawlers'     => $crawlers,
            // Whether llms.txt is being served, so the crawler panel can pair
            // "bots are coming" with "and here's what we feed them".
            'llms_txt'     => file_exists(ABSPATH . 'llms.txt'),
        ];
    }

    /**
     * Drop aggregate rows past the retention window.
     *
     * @return void
     */
    public function prune(): void {
        global $wpdb;

        $table  = $wpdb->prefix . 'thinkrank_ai_traffic';
        $cutoff = gmdate('Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- retention delete on our own table.
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE day < %s", $cutoff));
    }
}
