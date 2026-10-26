<?php

/**
 * Search Console daily series
 *
 * @package ThinkRank\Integrations
 * @since 2.17.2
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the contiguous day-by-day series the search charts draw.
 *
 * Search Analytics answers `dataState: all` with preliminary values for the
 * days Google is still processing, and with no row at all for a day it has
 * nothing for. The series used to show the first as final and turn the
 * second into a confirmed zero, with `position: 0` plotted as a top ranking
 * (#1272). Each day now carries a status:
 *
 *   - final        a row, before Google's first incomplete date.
 *   - preliminary  a row, on or after it: the numbers can still change.
 *   - zero         no row, before it: a real day without search traffic.
 *   - unavailable  no row, on or after it: Google has no data yet. Metrics
 *                  are null, not 0.
 *
 * Search Analytics dates are Pacific Time, so the window is too. A UTC window
 * ended on a day that had not started in Google's timezone for seven or eight
 * hours of every day.
 *
 * @since 2.17.2
 */
class Search_Daily_Series {

    public const STATUS_FINAL       = 'final';
    public const STATUS_PRELIMINARY = 'preliminary';
    public const STATUS_ZERO        = 'zero';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * The timezone Search Analytics reports its dates in.
     */
    public const TIMEZONE = 'America/Los_Angeles';

    /**
     * The window of $days days ending today, in Google's timezone.
     *
     * @param int                     $days Days in the window, today included.
     * @param \DateTimeImmutable|null $now  The current moment; null for now.
     * @return array{0: string, 1: string} Start and end dates (Y-m-d).
     */
    public static function window(int $days, ?\DateTimeImmutable $now = null): array {
        $today = ($now ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return [
            $today->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d'),
            $today->format('Y-m-d'),
        ];
    }

    /**
     * One entry per day from $start_date to $end_date, each with a status.
     *
     * Without a first incomplete date, which Google sends only when the range
     * holds incomplete data, a missing day after the newest row is unavailable
     * rather than zero: nothing says Google has finished with it.
     *
     * @param array<int, array<string, mixed>> $raw_rows              Rows grouped by the `date` dimension.
     * @param string                           $start_date            First day (Y-m-d).
     * @param string                           $end_date              Last day (Y-m-d).
     * @param string                           $first_incomplete_date Google's `metadata.firstIncompleteDate`, or ''.
     * @return array<int, array{date: string, status: string, clicks: ?int, impressions: ?int, ctr: float|int|null, position: float|null}>
     */
    public static function build(array $raw_rows, string $start_date, string $end_date, string $first_incomplete_date = ''): array {
        $by_date = [];
        foreach ($raw_rows as $row) {
            $date = (string) ($row['keys'][0] ?? '');
            if ('' === $date) {
                continue;
            }

            $by_date[$date] = [
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => round(((float) ($row['ctr'] ?? 0)) * 100, 2),
                'position'    => round((float) ($row['position'] ?? 0), 1),
            ];
        }

        $newest = [] === $by_date ? '' : max(array_keys($by_date));
        $rows   = [];

        try {
            $cursor = new \DateTimeImmutable($start_date);
            $end    = new \DateTimeImmutable($end_date);
        } catch (\Exception $e) {
            return [];
        }

        while ($cursor <= $end) {
            $date       = $cursor->format('Y-m-d');
            $incomplete = '' !== $first_incomplete_date && $date >= $first_incomplete_date;

            if (isset($by_date[$date])) {
                $rows[] = ['date' => $date, 'status' => $incomplete ? self::STATUS_PRELIMINARY : self::STATUS_FINAL] + $by_date[$date];
            } elseif ($incomplete || ('' === $first_incomplete_date && '' !== $newest && $date > $newest)) {
                $rows[] = [
                    'date'        => $date,
                    'status'      => self::STATUS_UNAVAILABLE,
                    'clicks'      => null,
                    'impressions' => null,
                    'ctr'         => null,
                    'position'    => null,
                ];
            } else {
                // No impressions means no position: null, never a plotted 0.
                $rows[] = [
                    'date'        => $date,
                    'status'      => self::STATUS_ZERO,
                    'clicks'      => 0,
                    'impressions' => 0,
                    'ctr'         => 0,
                    'position'    => null,
                ];
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $rows;
    }
}
