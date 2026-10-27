<?php
/**
 * Opening hours: one or more ranges per day.
 *
 * @package ThinkRank\SEO
 * @since 2.19.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and normalises a day of opening hours.
 *
 * A day is stored as `{open, close, closed}`, and may carry `slots`, a list of
 * `{open, close}` ranges, so a business that closes for lunch can say
 * 09:00–13:00 and 14:00–18:00 (#1119). `open` and `close` always mirror the
 * first range, so a reader that predates `slots` (an older Pro build, a theme
 * reading the option) still gets a valid day, the morning range. A day with no
 * `slots` is the single range it always was, so no stored data needs to move.
 *
 * Every reader (schema, the business meta tag, Open Graph, the Business Info
 * score) goes through get_ranges(), so they cannot disagree on what a day is.
 */
final class Business_Hours {

    /**
     * The most ranges one day may hold.
     *
     * @var int
     */
    public const MAX_SLOTS = 4;

    /**
     * Day keys, in week order.
     *
     * @var string[]
     */
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * The open ranges of one day, earliest first.
     *
     * Empty when the day is closed or holds no valid range. A time is H:MM or
     * HH:MM on a 24-hour clock, and comes back as HH:MM.
     *
     * @param mixed $day One stored day.
     * @return array<int, array{open: string, close: string}>
     */
    public static function get_ranges($day): array {
        if (!is_array($day) || !empty($day['closed'])) {
            return [];
        }

        return self::ranges_of($day);
    }

    /**
     * Normalise one day for storage.
     *
     * `slots` is stored only when the day has more than one range, so a
     * single-range day is saved in exactly the shape it always had, and
     * re-saving old data changes nothing. The ranges are kept on a closed
     * day, so opening it again restores them.
     *
     * @param mixed $day One submitted day.
     * @return array{open: string, close: string, closed: bool, slots?: array<int, array{open: string, close: string}>}
     */
    public static function sanitize_day($day): array {
        $ranges = is_array($day) ? self::ranges_of($day) : [];

        $clean = [
            'open'   => $ranges[0]['open'] ?? '',
            'close'  => $ranges[0]['close'] ?? '',
            'closed' => is_array($day) && !empty($day['closed']),
        ];

        if (count($ranges) > 1) {
            $clean['slots'] = $ranges;
        }

        return $clean;
    }

    /**
     * Normalise a whole week for storage.
     *
     * Each day key is normalised; any other key (an importer's numbered list,
     * say) is left as it was, since readers already skip what they cannot
     * name.
     *
     * @param mixed $hours Submitted hours.
     * @return array<string|int, mixed>
     */
    public static function sanitize_hours($hours): array {
        if (!is_array($hours)) {
            return [];
        }

        foreach ($hours as $key => $day) {
            if (in_array(strtolower((string) $key), self::DAYS, true)) {
                $hours[$key] = self::sanitize_day($day);
            }
        }

        return $hours;
    }

    /**
     * Problems with a day's ranges, worded for the Business Info score.
     *
     * Ranges that overlap, and a range that ends before it starts. A range
     * past midnight (22:00–02:00) is reported too: schema.org has no way to
     * say it in `openingHours`, so it stays unsupported as it was.
     *
     * @param mixed  $day   One stored day.
     * @param string $label Day name to put in the message.
     * @return string[]
     */
    public static function problems($day, string $label): array {
        $ranges   = self::get_ranges($day);
        $problems = [];

        foreach ($ranges as $range) {
            if (strcmp($range['close'], $range['open']) <= 0) {
                /* translators: %s: day of the week. */
                $problems[] = sprintf(__('A range on %s closes before it opens. Ranges past midnight are not supported.', 'thinkrank'), $label);
                break;
            }
        }

        for ($i = 1, $count = count($ranges); $i < $count; $i++) {
            if (strcmp($ranges[$i]['open'], $ranges[$i - 1]['close']) < 0) {
                /* translators: %s: day of the week. */
                $problems[] = sprintf(__('Overlapping hours on %s.', 'thinkrank'), $label);
                break;
            }
        }

        return $problems;
    }

    /**
     * Valid ranges of a day, ignoring whether it is closed.
     *
     * @param array<string, mixed> $day One day.
     * @return array<int, array{open: string, close: string}>
     */
    private static function ranges_of(array $day): array {
        $slots = (isset($day['slots']) && is_array($day['slots']) && [] !== $day['slots'])
            ? $day['slots']
            : [['open' => $day['open'] ?? '', 'close' => $day['close'] ?? '']];

        $ranges = [];
        foreach ($slots as $slot) {
            $open  = is_array($slot) ? self::time((string) ($slot['open'] ?? '')) : '';
            $close = is_array($slot) ? self::time((string) ($slot['close'] ?? '')) : '';

            if ('' !== $open && '' !== $close) {
                $ranges[] = ['open' => $open, 'close' => $close];
            }
        }

        usort($ranges, static fn(array $a, array $b): int => strcmp($a['open'], $b['open']));

        return array_slice($ranges, 0, self::MAX_SLOTS);
    }

    /**
     * A 24-hour time as HH:MM, or '' when it is not one.
     *
     * @param string $time Time as stored.
     * @return string
     */
    private static function time(string $time): string {
        if (!preg_match('/^\s*([01]?\d|2[0-3]):([0-5]\d)\s*$/', $time, $m)) {
            return '';
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }
}
