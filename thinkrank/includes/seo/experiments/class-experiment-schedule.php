<?php
/**
 * Title Experiments: which dates each variant is served and measured on.
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
 * Experiment Schedule
 *
 * The page has been serving its original title all along, so the window
 * right before the start *is* the original's window — measured as it is, no
 * waiting (#719). From the start the variant is served; its measurement
 * begins after a grace period (Google has to recrawl the page before the new
 * title can be shown) and runs for the same number of days. Search Console
 * needs a few more days before those dates have data, so the verdict comes
 * after the lag.
 *
 *     [--- original window ---][start: variant served][grace][--- variant window ---][lag][decide]
 *
 * Pro tests up to three variants — B, then C, then D — each with its own
 * grace and window, all compared with the one original window.
 *
 * Dates are UTC calendar days, `Y-m-d` — what Search Console queries take.
 * UTC also keeps a window across a daylight-saving change the same number of
 * days long.
 *
 * @since 2.22.0
 */
final class Experiment_Schedule {

    public const DEFAULT_WINDOW = 14;

    public const MIN_WINDOW = 7;

    public const MAX_WINDOW = 28;

    public const GRACE_DAYS = 3;

    public const LAG_DAYS = 3;

    /**
     * Plan an experiment starting on a date.
     *
     * With more than one variant (Pro), each is served in turn — its own grace
     * period, then its own window — and each is compared with the same
     * original window.
     *
     * @param string $start    First day the first variant is served, UTC `Y-m-d`.
     * @param int    $window   Days measured per variant.
     * @param int    $variants How many variants are tested (1 or more).
     * @return array{
     *     start: string,
     *     window: int,
     *     original: array{from: string, to: string},
     *     variants: array<int, array{serve_from: string, from: string, to: string}>,
     *     decide_on: string
     * }
     */
    public static function plan(string $start, int $window = self::DEFAULT_WINDOW, int $variants = 1): array {
        $window = self::clamp_window($window);
        $day    = new \DateTimeImmutable($start . ' 00:00:00', new \DateTimeZone('UTC'));

        $plan = [
            'start'    => $day->format('Y-m-d'),
            'window'   => $window,
            'original' => [
                'from' => $day->modify('-' . $window . ' days')->format('Y-m-d'),
                'to'   => $day->modify('-1 day')->format('Y-m-d'),
            ],
            'variants' => [],
        ];

        $serve_from = $day;
        $last_to    = $day;
        $count      = max(1, $variants);
        for ($i = 0; $i < $count; $i++) {
            $from    = $serve_from->modify('+' . self::GRACE_DAYS . ' days');
            $last_to = $from->modify('+' . ($window - 1) . ' days');

            $plan['variants'][] = [
                'serve_from' => $serve_from->format('Y-m-d'),
                'from'       => $from->format('Y-m-d'),
                'to'         => $last_to->format('Y-m-d'),
            ];

            $serve_from = $last_to->modify('+1 day');
        }

        $plan['decide_on'] = $last_to->modify('+' . (self::LAG_DAYS + 1) . ' days')->format('Y-m-d');

        return $plan;
    }

    /**
     * Keep a window inside the allowed range.
     *
     * @param int $window Requested days.
     * @return int
     */
    public static function clamp_window(int $window): int {
        return max(self::MIN_WINDOW, min(self::MAX_WINDOW, $window));
    }
}
