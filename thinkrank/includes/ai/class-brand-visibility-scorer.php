<?php
/**
 * Brand Visibility v2 — scoring engine.
 *
 * Pure functions, no WordPress: every number the dashboard shows is computed
 * here from a flat list of completed task rows, so the maths is unit-testable
 * and the formulas live in exactly one place.
 *
 * Why sampling matters: an LLM answers the same question differently run to
 * run, so a single probe is a coin flip, not a measurement. Every metric here
 * is therefore a RATE over samples (mentions / samples), which is what makes
 * "45%" mean something. The v1 single-shot Yes/No is the noise problem this
 * replaces.
 *
 * @package ThinkRank\AI
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Computes brand-visibility verdicts and run aggregates.
 */
final class Brand_Visibility_Scorer {

    /**
     * Visibility Index weights. Published in the UI tooltip on purpose — a
     * score nobody can explain is a score nobody trusts.
     *
     * Mention rate dominates because "does the AI name you at all" is the
     * question; share of voice is next because being named alongside three
     * competitors is not the same as being named alone.
     */
    public const WEIGHTS = [
        'mention_rate'   => 0.45,
        'share_of_voice' => 0.25,
        'citation_rate'  => 0.15,
        'sentiment'      => 0.15,
    ];

    /**
     * Query types, in the order the wizard presents them.
     */
    public const QUERY_TYPES = ['branded', 'category', 'problem', 'comparison'];

    /**
     * Whether a name appears in an answer as a WORD, not a substring.
     *
     * Substring matching is why naive checkers over-report: the brand "Ace"
     * would match "surface", and a competitor "On" would match every sentence.
     * Boundaries are checked against non-word characters so multi-word and
     * punctuated brands ("Raymond Coffee", "Yoast SEO") still match, while
     * `str_contains` false positives don't.
     *
     * @param string $answer Answer text to search.
     * @param string $name   Brand or competitor name.
     * @return bool True when the name appears as a discrete word/phrase.
     */
    public static function name_appears(string $answer, string $name): bool {
        $name = trim($name);
        if ('' === $name || '' === trim($answer)) {
            return false;
        }

        // \b is unreliable for names ending in punctuation or non-ASCII, so
        // assert "not preceded/followed by a word character" explicitly.
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/ui';

        return 1 === preg_match($pattern, $answer);
    }

    /**
     * Whether the brand (or any of its variants) is mentioned.
     *
     * Variants exist because AI answers rarely use a brand's exact legal
     * string — "ThinkRank", "Think Rank" and "ThinkRank SEO" are the same
     * brand to a reader, and counting only the first understates visibility.
     *
     * @param string   $answer   Answer text.
     * @param string   $brand    Primary brand name.
     * @param string[] $variants Alternate spellings/aliases.
     * @return bool
     */
    public static function is_mentioned(string $answer, string $brand, array $variants = []): bool {
        foreach (array_merge([$brand], $variants) as $candidate) {
            if (self::name_appears($answer, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the answer cites the site's domain.
     *
     * Host comparison is done on the bare host (no scheme, no www) and as a
     * plain case-insensitive search rather than a word match, because URLs
     * legitimately appear glued to punctuation and paths.
     *
     * @param string $answer Answer text.
     * @param string $host   Site host, e.g. example.com.
     * @return bool
     */
    public static function is_cited(string $answer, string $host): bool {
        $bare = self::bare_host($host);

        return '' !== $bare && false !== stripos($answer, $bare);
    }

    /**
     * Competitors named in an answer.
     *
     * @param string $answer      Answer text.
     * @param array  $competitors List of ['name' => string, 'url' => string].
     * @return string[] Names found, in config order.
     */
    public static function competitors_in(string $answer, array $competitors): array {
        $found = [];

        foreach ($competitors as $competitor) {
            $name = (string) ($competitor['name'] ?? '');
            if ('' === $name) {
                continue;
            }

            $host = self::bare_host((string) ($competitor['url'] ?? ''));
            $hit  = self::name_appears($answer, $name)
                || ('' !== $host && false !== stripos($answer, $host));

            if ($hit) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Excerpt centred on the first brand hit, so the UI can show HOW the brand
     * came up rather than only that it did.
     *
     * @param string   $answer   Answer text.
     * @param string   $brand    Brand name.
     * @param string[] $variants Alternates.
     * @return string Excerpt (may be empty when nothing matched).
     */
    public static function excerpt(string $answer, string $brand, array $variants = []): string {
        foreach (array_merge([$brand], $variants) as $candidate) {
            $candidate = trim((string) $candidate);
            if ('' === $candidate) {
                continue;
            }

            $pos = stripos($answer, $candidate);
            if (false === $pos) {
                continue;
            }

            $start   = max(0, $pos - 120);
            $excerpt = trim(substr($answer, $start, 300));

            if ($start > 0) {
                $excerpt = '…' . $excerpt;
            }
            if ($start + 300 < strlen($answer)) {
                $excerpt .= '…';
            }

            return $excerpt;
        }

        return '';
    }

    /**
     * Aggregate completed tasks into the run's results payload.
     *
     * Only tasks that actually produced an answer are counted. A failed probe
     * is NOT a "not mentioned" — folding errors into the denominator would
     * silently depress every score, which is exactly the false-negative class
     * of bug that made v1 untrustworthy.
     *
     * @param array $tasks  Task rows: query_text, query_type, platform,
     *                      status, mentioned, cited, sentiment, competitors.
     * @param array $config Run config: competitors[].
     * @return array Aggregates for the dashboard.
     */
    public static function aggregate(array $tasks, array $config = []): array {
        $competitors = $config['competitors'] ?? [];

        $done = array_values(array_filter(
            $tasks,
            static fn(array $t): bool => 'done' === ($t['status'] ?? '')
        ));

        $samples = count($done);

        if (0 === $samples) {
            return self::empty_results($competitors);
        }

        $mentions  = 0;
        $citations = 0;
        $sentiment = ['positive' => 0, 'neutral' => 0, 'negative' => 0];

        // Competitor mention tallies, keyed by name and seeded so a competitor
        // that never appears still shows up in the leaderboard at 0.
        $competitor_hits = [];
        foreach ($competitors as $competitor) {
            $name = (string) ($competitor['name'] ?? '');
            if ('' !== $name) {
                $competitor_hits[$name] = 0;
            }
        }

        $by_platform = [];
        $by_query    = [];

        foreach ($done as $task) {
            $mentioned = !empty($task['mentioned']);
            $cited     = !empty($task['cited']);

            $mentions  += $mentioned ? 1 : 0;
            $citations += $cited ? 1 : 0;

            $mood = (string) ($task['sentiment'] ?? '');
            if ($mentioned && isset($sentiment[$mood])) {
                $sentiment[$mood]++;
            }

            foreach (self::task_competitors($task) as $name) {
                if (!isset($competitor_hits[$name])) {
                    $competitor_hits[$name] = 0;
                }
                $competitor_hits[$name]++;
            }

            $platform = (string) ($task['platform'] ?? 'unknown');
            $by_platform[$platform] ??= ['platform' => $platform, 'samples' => 0, 'mentions' => 0, 'citations' => 0];
            $by_platform[$platform]['samples']++;
            $by_platform[$platform]['mentions']  += $mentioned ? 1 : 0;
            $by_platform[$platform]['citations'] += $cited ? 1 : 0;

            $query = (string) ($task['query_text'] ?? '');
            $by_query[$query] ??= [
                'query'     => $query,
                'type'      => (string) ($task['query_type'] ?? 'branded'),
                'samples'   => 0,
                'mentions'  => 0,
                'citations' => 0,
                'platforms' => [],
            ];
            $by_query[$query]['samples']++;
            $by_query[$query]['mentions']  += $mentioned ? 1 : 0;
            $by_query[$query]['citations'] += $cited ? 1 : 0;

            // Track which platform mentions the brand most for this query, so
            // the table can answer "where am I actually winning?".
            $by_query[$query]['platforms'][$platform] ??= ['samples' => 0, 'mentions' => 0];
            $by_query[$query]['platforms'][$platform]['samples']++;
            $by_query[$query]['platforms'][$platform]['mentions'] += $mentioned ? 1 : 0;
        }

        $mention_rate  = self::rate($mentions, $samples);
        $citation_rate = self::rate($citations, $samples);

        // Share of voice: your mentions against the whole named field. With no
        // competitors configured there is no field to share, so SoV is your
        // mention rate — honest, and it keeps the index meaningful on free.
        $competitor_total = array_sum($competitor_hits);
        $share_of_voice   = ($mentions + $competitor_total) > 0
            ? self::rate($mentions, $mentions + $competitor_total)
            : 0.0;

        // Sentiment share is measured over MENTIONS, not samples: an answer
        // that never named you has no opinion about you.
        $sentiment_total = array_sum($sentiment);
        $positive_share  = $sentiment_total > 0
            ? self::rate($sentiment['positive'], $sentiment_total)
            : 0.0;

        // Components this run can honestly score on.
        $measured = ['mention_rate', 'share_of_voice', 'citation_rate'];
        if ($sentiment_total > 0) {
            $measured[] = 'sentiment';
        }

        return [
            'samples'         => $samples,
            'mention_rate'    => $mention_rate,
            'citation_rate'   => $citation_rate,
            'share_of_voice'  => $share_of_voice,
            'sentiment'       => [
                'positive'       => $sentiment['positive'],
                'neutral'        => $sentiment['neutral'],
                'negative'       => $sentiment['negative'],
                'positive_share' => $positive_share,
            ],
            // Sentiment is only part of the score when it was actually
            // measured. Free plans never run the sentiment probe, and an
            // answer that never named the brand holds no opinion of it —
            // scoring an unmeasured component as 0 capped those runs at 85
            // no matter how visible the brand was.
            'measured'         => $measured,
            'visibility_index' => self::visibility_index([
                'mention_rate'   => $mention_rate,
                'share_of_voice' => $share_of_voice,
                'citation_rate'  => $citation_rate,
                'sentiment'      => $positive_share,
            ], $measured),
            'by_platform'  => array_values(array_map(
                static function (array $row): array {
                    $row['mention_rate']  = self::rate($row['mentions'], $row['samples']);
                    $row['citation_rate'] = self::rate($row['citations'], $row['samples']);
                    return $row;
                },
                $by_platform
            )),
            'by_query'     => array_values(array_map(
                static function (array $row): array {
                    $row['mention_rate'] = self::rate($row['mentions'], $row['samples']);
                    $row['cited']        = $row['citations'] > 0;
                    $row['best_platform'] = self::best_platform($row['platforms']);
                    unset($row['platforms']);
                    return $row;
                },
                $by_query
            )),
            'by_type'      => self::by_type($by_query),
            'competitors'  => self::leaderboard($mentions, $competitor_hits),
        ];
    }

    /**
     * Weighted 0–100 composite.
     *
     * @param array      $parts    Rates in 0..1 keyed like self::WEIGHTS.
     * @param array|null $measured Component keys this run could measure; null
     *                             means all of them.
     * @return int Index, 0–100.
     */
    public static function visibility_index(array $parts, ?array $measured = null): int {
        // Weights are renormalised over the components that were actually
        // measured, so a run that couldn't measure one of them is scored out
        // of what it could measure rather than penalised for the gap.
        $weights = null === $measured
            ? self::WEIGHTS
            : array_intersect_key(self::WEIGHTS, array_flip($measured));

        $total = array_sum($weights);
        if ($total <= 0) {
            return 0;
        }

        $score = 0.0;
        foreach ($weights as $key => $weight) {
            $score += ((float) ($parts[$key] ?? 0)) * ($weight / $total);
        }

        return (int) round(max(0.0, min(1.0, $score)) * 100);
    }

    /**
     * Share-of-voice leaderboard: you plus every configured competitor,
     * sorted by share so the UI can render it directly.
     *
     * @param int   $own_mentions     Your mention count.
     * @param array $competitor_hits  name => mentions.
     * @return array<int, array{name: string, mentions: int, share: float, is_you: bool}>
     */
    private static function leaderboard(int $own_mentions, array $competitor_hits): array {
        $total = $own_mentions + array_sum($competitor_hits);

        $rows = [[
            'name'     => '',            // filled by the caller/UI with "Your site"
            'mentions' => $own_mentions,
            'share'    => $total > 0 ? self::rate($own_mentions, $total) : 0.0,
            'is_you'   => true,
        ]];

        foreach ($competitor_hits as $name => $hits) {
            $rows[] = [
                'name'     => (string) $name,
                'mentions' => (int) $hits,
                'share'    => $total > 0 ? self::rate((int) $hits, $total) : 0.0,
                'is_you'   => false,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['mentions'] <=> $a['mentions']);

        return $rows;
    }

    /**
     * Mention rate per query type — feeds the radar chart.
     *
     * @param array $by_query Per-query rows.
     * @return array<int, array{type: string, mention_rate: float, queries: int}>
     */
    private static function by_type(array $by_query): array {
        $totals = [];

        foreach ($by_query as $row) {
            $type = (string) ($row['type'] ?? 'branded');
            $totals[$type] ??= ['samples' => 0, 'mentions' => 0, 'queries' => 0];
            $totals[$type]['samples']  += (int) $row['samples'];
            $totals[$type]['mentions'] += (int) $row['mentions'];
            $totals[$type]['queries']++;
        }

        $out = [];
        foreach (self::QUERY_TYPES as $type) {
            if (!isset($totals[$type])) {
                continue;
            }
            $out[] = [
                'type'         => $type,
                'mention_rate' => self::rate($totals[$type]['mentions'], $totals[$type]['samples']),
                'queries'      => $totals[$type]['queries'],
            ];
        }

        return $out;
    }

    /**
     * Platform with the highest mention rate for a query (ties → most samples).
     *
     * @param array $platforms platform => ['samples' => int, 'mentions' => int].
     * @return string Platform slug, or '' when nothing was mentioned anywhere.
     */
    private static function best_platform(array $platforms): string {
        $best      = '';
        $best_rate = 0.0;

        foreach ($platforms as $platform => $counts) {
            $rate = self::rate((int) $counts['mentions'], (int) $counts['samples']);
            if ($rate > $best_rate) {
                $best_rate = $rate;
                $best      = (string) $platform;
            }
        }

        return $best;
    }

    /**
     * Competitor names recorded on a task row (stored as JSON).
     *
     * @param array $task Task row.
     * @return string[]
     */
    private static function task_competitors(array $task): array {
        $raw = $task['competitors'] ?? [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
    }

    /**
     * Host without scheme, path or www.
     *
     * @param string $url URL or bare host.
     * @return string
     */
    private static function bare_host(string $url): string {
        $url = trim($url);
        if ('' === $url) {
            return '';
        }

        if (false !== strpos($url, '//')) {
            $parsed = wp_parse_url($url, PHP_URL_HOST);
            $url    = is_string($parsed) ? $parsed : $url;
        }

        // Drop any path that survived (bare "example.com/shop").
        $url = explode('/', $url)[0];

        return strtolower(preg_replace('/^www\./i', '', $url));
    }

    /**
     * Ratio rounded to 4dp, guarding division by zero.
     *
     * @param int $numerator   Hits.
     * @param int $denominator Total.
     * @return float 0..1
     */
    private static function rate(int $numerator, int $denominator): float {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }

    /**
     * Zeroed results for a run with nothing usable, so the UI renders an empty
     * dashboard rather than breaking on missing keys.
     *
     * @param array $competitors Configured competitors.
     * @return array
     */
    private static function empty_results(array $competitors): array {
        $hits = [];
        foreach ($competitors as $competitor) {
            $name = (string) ($competitor['name'] ?? '');
            if ('' !== $name) {
                $hits[$name] = 0;
            }
        }

        return [
            'samples'          => 0,
            'measured'         => [],
            'mention_rate'     => 0.0,
            'citation_rate'    => 0.0,
            'share_of_voice'   => 0.0,
            'sentiment'        => ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'positive_share' => 0.0],
            'visibility_index' => 0,
            'by_platform'      => [],
            'by_query'         => [],
            'by_type'          => [],
            'competitors'      => self::leaderboard(0, $hits),
        ];
    }
}
