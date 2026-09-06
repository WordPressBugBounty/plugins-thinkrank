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

use DateTimeImmutable;

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
     * Object-cache group for the buffered hit counters.
     */
    private const COUNTER_GROUP = 'thinkrank_traffic';

    /**
     * Key prefix for those counters.
     */
    private const COUNTER_PREFIX = 'tr_traffic_';

    /**
     * Flush a bucket once it has this many buffered hits.
     */
    private const FLUSH_AT = 50;

    /**
     * ...or once its oldest buffered hit is this many seconds old, so a quiet
     * site still records its traffic.
     */
    private const FLUSH_AFTER = 300;

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
     * Record a page served as Markdown to an AI agent.
     *
     * Called by Pro's Markdown for AI feature at serve time. Lives here rather
     * than in Pro because this class owns the aggregate table; Pro owning a
     * second writer to it would couple the schema to two repos.
     *
     * @param string $source Crawler slug when the agent is a known AI crawler,
     *                       'header' for Accept-negotiated requests, 'link' for
     *                       ?format=markdown / .md URLs.
     * @param string $path   Path of the post served.
     * @return void
     */
    public function record_served_markdown(string $source, string $path = ''): void {
        $source = sanitize_key($source);
        if ('' === $source) {
            $source = 'other';
        }
        $this->bump('markdown', $source, substr($path, 0, 191));
    }

    /**
     * Total Markdown-for-AI responses served in the last N days.
     *
     * @param int $days Range in days (bounded 1–180).
     * @return int
     */
    public function served_markdown_count(int $days = 30): int {
        global $wpdb;

        $days  = max(1, min(self::RETENTION_DAYS, $days));
        $table = $wpdb->prefix . 'thinkrank_ai_traffic';
        // Same clock as write_bucket(), and counted in calendar days so a
        // DST transition inside the window does not move the boundary.
        $since = $this->day_key_offset($days);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate over our own table.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(hits), 0) FROM {$table} WHERE kind = 'markdown' AND day >= %s",
                $since
            )
        );
        // phpcs:enable
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
        // Without a persistent object cache there is nowhere to buffer, so keep
        // the direct write rather than counting into per-request memory that is
        // thrown away — that would lose hits outright.
        if (!wp_using_ext_object_cache()) {
            $this->write_bucket($kind, $source, $path, 1);

            return;
        }

        // With one, buffer and flush in batches. The unique key is
        // (day, kind, source, path), so all baseline traffic funnels into a
        // single row per day: InnoDB took an exclusive row lock on it for every
        // visitor, serialising concurrent anonymous traffic, and made every
        // pageview a write even when the response was fully cacheable (#402).
        $bucket = self::COUNTER_PREFIX . md5($kind . '|' . $source . '|' . $path);
        $since  = $bucket . '_since';

        $hits = wp_cache_incr($bucket, 1, self::COUNTER_GROUP);

        if (false === $hits) {
            wp_cache_add($bucket, 1, self::COUNTER_GROUP, 0);
            wp_cache_add($since, time(), self::COUNTER_GROUP, 0);
            $hits = 1;
        }

        $started = (int) wp_cache_get($since, self::COUNTER_GROUP);

        // Flush on either bound, so a busy site writes rarely and a quiet one
        // still lands its hits — an eviction can cost at most one window.
        if ($hits < self::FLUSH_AT && $started > 0 && (time() - $started) < self::FLUSH_AFTER) {
            return;
        }

        wp_cache_set($bucket, 0, self::COUNTER_GROUP, 0);
        wp_cache_set($since, time(), self::COUNTER_GROUP, 0);

        $this->write_bucket($kind, $source, $path, (int) $hits);
    }

    /**
     * Add hits to a bucket's row.
     *
     * @since 2.0.1
     *
     * @param string $kind   'referral' | 'crawler' | 'baseline'.
     * @param string $source Platform/bot slug, or 'all' for baseline.
     * @param string $path   Landing path (referrals only).
     * @param int    $hits   How many hits to add.
     * @return void
     */
    private function write_bucket(string $kind, string $source, string $path, int $hits): void {
        if ($hits < 1) {
            return;
        }

        // `day` is the SITE-LOCAL date (see day_key()), not UTC. The column is
        // a bare `date` with no zone attached, so the clock that writes it is
        // the only thing that gives it meaning — and these keys reach the user
        // as the trend chart's dates, where the site's own calendar is what
        // they expect to read.
        //
        // Every range boundary and retention cutoff must be derived with
        // day_key() too. A gmdate() boundary against these rows drifts by a
        // day for part of every day on a non-UTC site.

        global $wpdb;

        $table = $wpdb->prefix . 'thinkrank_ai_traffic';

        // Aggregate counter upsert; the unique key is the bucket.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- aggregate counter upsert; table name is prefix-derived.
        $wpdb->query(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
            $wpdb->prepare(
                "INSERT INTO {$table} (day, kind, source, path, hits) VALUES (%s, %s, %s, %s, %d)
                 ON DUPLICATE KEY UPDATE hits = hits + %d",
                current_time('Y-m-d'),
                $kind,
                $source,
                $path,
                $hits,
                $hits
            )
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * The site-local date key for an instant, matching write_bucket().
     *
     * Every consumer of the `day` column goes through this, so the read side
     * cannot drift onto a different calendar from the write side.
     *
     * @param int|null $timestamp Unix timestamp, or null for now.
     * @return string `Y-m-d` on the site's clock.
     */
    private function day_key(?int $timestamp = null): string {
        return wp_date('Y-m-d', $timestamp ?? time());
    }

    /**
     * Midday on a given site-local date.
     *
     * Midday, not midnight: a handful of zones start DST at 00:00, so
     * midnight on a transition date can be a time that does not exist and
     * PHP quietly rolls it forward. Noon is never inside a DST gap, so
     * every date in the year is representable.
     *
     * @param string $day `Y-m-d` on the site's clock.
     * @return DateTimeImmutable
     */
    private function local_noon(string $day): DateTimeImmutable {
        return new DateTimeImmutable($day . ' 12:00:00', wp_timezone());
    }

    /**
     * The site-local date key N *calendar* days before today.
     *
     * Not `time() - N * DAY_IN_SECONDS`: a fixed 86400-second step is not a
     * day on a clock that shifts. Around a DST transition that arithmetic
     * lands an hour early or late, which moves the date for the hour either
     * side of midnight.
     *
     * @param int $days_ago Whole days back.
     * @return string `Y-m-d`.
     */
    private function day_key_offset(int $days_ago): string {
        return $this->local_noon($this->day_key())
            ->modify('-' . max(0, $days_ago) . ' day')
            ->format('Y-m-d');
    }

    /**
     * Every site-local date from $from to $to inclusive.
     *
     * Walks the calendar rather than stepping by 86400 seconds, so a DST
     * transition inside the range neither duplicates a date nor skips one.
     * Skipping one used to drop that day's referrals out of the trend while
     * they stayed in the totals.
     *
     * @param string $from `Y-m-d`, inclusive.
     * @param string $to   `Y-m-d`, inclusive.
     * @return string[] Ordered, contiguous date keys.
     */
    private function day_range(string $from, string $to): array {
        $cursor = $this->local_noon($from);
        $end    = $this->local_noon($to);

        $days = [];
        // Bounded by the caller's window (<= RETENTION_DAYS), with headroom
        // so a malformed pair can never spin here.
        $guard = self::RETENTION_DAYS + 2;
        $steps = 0;
        while ($cursor <= $end && $steps < $guard) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
            $steps++;
        }

        return $days;
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
        // Same clock as write_bucket(), and counted in calendar days so a
        // DST transition inside the window does not move the boundary.
        $since = $this->day_key_offset($days);

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
        $markdown  = 0;

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
                case 'markdown':
                    $markdown += $hits;
                    break;
            }
        }

        arsort($platforms);
        arsort($pages);
        arsort($crawlers);
        ksort($trend);

        // Fill every day the query covered, zeroes included. Only days that
        // had a referral produce a $trend key above, and the chart positions
        // points by index — so a sparse map drew a three-week gap exactly
        // like a one-day gap. A contiguous series makes even spacing correct,
        // and distinguishes "no referrals that day" from "no data".
        //
        // The range mirrors the WHERE clause (day >= $since, through today)
        // so the series covers exactly what was counted, and it is built on
        // day_key() so the keys match how the rows were written.
        $filled = [];
        foreach ($this->day_range($since, $this->day_key()) as $day) {
            $filled[$day] = $trend[$day] ?? 0;
        }

        // Safety net for anything the window did not cover — a row dated
        // ahead of today, which a site that moved timezone can hold. Union
        // keeps the filled zeroes and adds only keys not already present, so
        // the series can never total less than ai_sessions.
        $filled += $trend;
        ksort($filled);

        $trend = $filled;

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
            //
            // Ask the manager, not the filesystem: `dynamic` delivery — the
            // resolved default on every non-Apache stack — publishes no
            // physical file and answers from serve_llms_txt(), so a
            // file_exists() probe reports "not published" for a live document.
            'llms_txt'     => $this->llms_txt_published(),
            // Pages served as Markdown by Pro's Markdown for AI feature
            // (kind 'markdown', written via record_served_markdown()).
            'markdown_served' => $markdown,
        ];
    }

    /**
     * Whether llms.txt is currently being served, in either delivery mode.
     *
     * `static` publishes a file at ABSPATH; `dynamic` keeps the document in
     * an option and serves it from a PHP route. Only the manager knows which
     * is in force, so it is the single source of truth here.
     *
     * @return bool
     */
    private function llms_txt_published(): bool {
        // Spelt exactly as the class is declared. The autoloader routes this
        // one through a case-SENSITIVE special-case map, and while a
        // mis-cased name happens to fall through to the generic rule and
        // resolve anyway, that is a coincidence — a change to that rule would
        // silently make class_exists() false here, and the badge would go
        // back to reporting "No llms.txt" for a live document.
        if (!class_exists(LLMs_Txt_Manager::class)) {
            // Defensive: a partial load must not claim llms.txt is live.
            return false;
        }

        // is_published(), not get_llms_txt_status(): the latter resolves the
        // delivery mode, may fire a loopback probe and touches the filesystem
        // API, which is far too much work for a dashboard boolean.
        return (new LLMs_Txt_Manager())->is_published();
    }

    /**
     * Drop aggregate rows past the retention window.
     *
     * @return void
     */
    public function prune(): void {
        global $wpdb;

        $table  = $wpdb->prefix . 'thinkrank_ai_traffic';
        // Same clock as write_bucket(), counted in calendar days.
        $cutoff = $this->day_key_offset(self::RETENTION_DAYS);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- retention delete on our own table.
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE day < %s", $cutoff));
    }
}
