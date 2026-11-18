<?php
/**
 * Title Experiments: decide whether a variant earned more clicks.
 *
 * @package ThinkRank\SEO\Experiments
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Experiments;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Experiment Verdict
 *
 * Google shows one title per URL at a time, so a title test is sequential:
 * the original over one window, the variant over another (#719). Comparing
 * the two CTRs raw would credit the variant with anything that moved the
 * whole site — a holiday, an algorithm update. So each window's CTR is
 * divided by the rest of the site's CTR over the same dates (the control),
 * and the lift is the change in that ratio.
 *
 * It refuses to name a winner when the numbers cannot carry one:
 *
 * - fewer than {@see self::MIN_IMPRESSIONS} impressions in either window —
 *   too few searches to tell a real change from noise;
 * - the page's average position moved by more than {@see self::MAX_POSITION_SHIFT}
 *   places — a CTR change caused by ranking higher or lower is not a title
 *   win;
 * - no clicks at all on the original, or no control CTR — nothing to divide by.
 *
 * And it calls a lift inside ±{@see self::MIN_LIFT} a tie: a 2% difference is
 * inside what two fortnights of the same title produce.
 *
 * Pure: every rule is covered by fixtures.
 *
 * @since 2.22.0
 */
final class Experiment_Verdict {

    public const MIN_IMPRESSIONS = 200;

    public const MAX_POSITION_SHIFT = 2.0;

    public const MIN_LIFT = 0.05;

    /**
     * Outcomes.
     *
     * @var string[]
     */
    public const OUTCOMES = ['variant_wins', 'original_wins', 'tie', 'inconclusive'];

    /**
     * Why an experiment was inconclusive.
     *
     * @var string[]
     */
    public const REASONS = ['too_few_impressions', 'position_moved', 'no_baseline'];

    /**
     * Decide.
     *
     * @param array{clicks: int, impressions: int, position: float} $original        The page, while serving the original.
     * @param array{clicks: int, impressions: int, position: float} $variant         The page, while serving the variant.
     * @param float                                                 $control_original Rest-of-site CTR (0–1) over the original's dates.
     * @param float                                                 $control_variant  Rest-of-site CTR (0–1) over the variant's dates.
     * @return array{outcome: string, reason: string, lift: ?float, original_ctr: float, variant_ctr: float, position_shift: float}
     */
    public static function decide(array $original, array $variant, float $control_original, float $control_variant): array {
        $o_impr = (int) ($original['impressions'] ?? 0);
        $v_impr = (int) ($variant['impressions'] ?? 0);
        $o_ctr  = $o_impr > 0 ? (int) ($original['clicks'] ?? 0) / $o_impr : 0.0;
        $v_ctr  = $v_impr > 0 ? (int) ($variant['clicks'] ?? 0) / $v_impr : 0.0;
        $shift  = round((float) ($variant['position'] ?? 0) - (float) ($original['position'] ?? 0), 2);

        $base = [
            'lift'           => null,
            'original_ctr'   => round($o_ctr, 5),
            'variant_ctr'    => round($v_ctr, 5),
            'position_shift' => $shift,
        ];

        if ($o_impr < self::MIN_IMPRESSIONS || $v_impr < self::MIN_IMPRESSIONS) {
            return ['outcome' => 'inconclusive', 'reason' => 'too_few_impressions'] + $base;
        }

        if (abs($shift) > self::MAX_POSITION_SHIFT) {
            return ['outcome' => 'inconclusive', 'reason' => 'position_moved'] + $base;
        }

        if ($o_ctr <= 0.0 || $control_original <= 0.0 || $control_variant <= 0.0) {
            return ['outcome' => 'inconclusive', 'reason' => 'no_baseline'] + $base;
        }

        $lift = ($v_ctr / $control_variant) / ($o_ctr / $control_original) - 1.0;
        $base['lift'] = round($lift, 4);

        if (abs($lift) < self::MIN_LIFT) {
            return ['outcome' => 'tie', 'reason' => ''] + $base;
        }

        return ['outcome' => $lift > 0 ? 'variant_wins' : 'original_wins', 'reason' => ''] + $base;
    }

    /**
     * The experiment's outcome from each variant's verdict (Pro tests up to
     * three). The winner is the variant that beat the original by the most;
     * with none, the original stays — "original_wins" only when every
     * variant that could be judged lost, "inconclusive" only when none could.
     *
     * @param array<int, array<string, mixed>> $verdicts One {@see self::decide()} per variant, in order.
     * @return array{outcome: string, reason: string, lift: ?float, winner: ?int}
     */
    public static function overall(array $verdicts): array {
        $winner = null;
        foreach ($verdicts as $i => $verdict) {
            if ('variant_wins' === $verdict['outcome'] && (null === $winner || $verdict['lift'] > $verdicts[$winner]['lift'])) {
                $winner = $i;
            }
        }
        if (null !== $winner) {
            return ['outcome' => 'variant_wins', 'reason' => '', 'lift' => $verdicts[$winner]['lift'], 'winner' => $winner];
        }

        $judged = array_values(array_filter($verdicts, static fn(array $v): bool => 'inconclusive' !== $v['outcome']));
        if ([] === $judged) {
            return ['outcome' => 'inconclusive', 'reason' => (string) ($verdicts[0]['reason'] ?? ''), 'lift' => null, 'winner' => null];
        }

        $all_lost = [] === array_filter($judged, static fn(array $v): bool => 'original_wins' !== $v['outcome']);
        $best     = max(array_map(static fn(array $v): float => (float) $v['lift'], $judged));

        return ['outcome' => $all_lost ? 'original_wins' : 'tie', 'reason' => '', 'lift' => round($best, 4), 'winner' => null];
    }

    /**
     * Rest-of-site CTR: the site's totals minus the page's own.
     *
     * @param array{clicks: int, impressions: int} $site Site totals.
     * @param array{clicks: int, impressions: int} $page Page totals over the same dates.
     * @return float CTR 0–1, or 0 when the rest of the site had no impressions.
     */
    public static function control_ctr(array $site, array $page): float {
        $impr = (int) ($site['impressions'] ?? 0) - (int) ($page['impressions'] ?? 0);
        if ($impr <= 0) {
            return 0.0;
        }

        return max(0, (int) ($site['clicks'] ?? 0) - (int) ($page['clicks'] ?? 0)) / $impr;
    }
}
