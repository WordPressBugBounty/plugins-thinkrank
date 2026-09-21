<?php
/**
 * Top Posts Renderer
 *
 * Shared row-shaping helper used by the four growing / losing sections so
 * pages and queries read the same way in every card. Not a section itself
 * — purely a stateless presentation helper on top of Email_Report_Html.
 *
 * @package ThinkRank
 * @subpackage SEO\Email_Report_Sections
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Email_Report_Sections;

if (!defined('ABSPATH')) {
    exit;
}

final class Top_Posts_Renderer {

    /**
     * Shape page entries into list rows: title (resolved from the URL when
     * it is one of this site's posts), the path underneath, a ±N clicks pill.
     *
     * @param array $rows Each row: ['url' => string, 'clicks' => int, 'change' => int]
     * @return array<int,array{title:string,subtitle:string,pill:string,href:string}>
     */
    public static function page_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? $row['page'] ?? '');
            if ($url === '') {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            if ($title === '') {
                $title = Email_Report_Html::page_title($url);
            }
            $path = Email_Report_Html::display_path($url);
            $out[] = [
                'title'    => $title,
                'subtitle' => $path !== $title ? $path : '',
                'pill'     => self::clicks_pill((int) ($row['change'] ?? 0)),
                'href'     => $url,
            ];
        }
        return $out;
    }

    /**
     * Shape query entries into list rows: the query, its average position
     * (and the previous one when it moved), a ±N clicks pill.
     *
     * @param array $rows Each row: ['query' => string, 'position' => ?float, 'prev_position' => ?float, 'change' => int]
     * @return array<int,array{title:string,subtitle:string,pill:string}>
     */
    public static function keyword_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $query = (string) ($row['query'] ?? ($row['keys'][0] ?? ''));
            if ($query === '') {
                continue;
            }
            $out[] = [
                'title'    => $query,
                'subtitle' => self::position_line($row['position'] ?? null, $row['prev_position'] ?? null),
                'pill'     => self::clicks_pill((int) ($row['change'] ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * "Avg. position 9.4 (was 5.1)". A null current position means the query
     * had no impressions this period — say so rather than print 0.0.
     */
    private static function position_line($position, $previous): string {
        if ($position === null) {
            return __('No impressions this period', 'thinkrank');
        }
        $line = sprintf(
            /* translators: %s: average position, one decimal. */
            __('Avg. position %s', 'thinkrank'),
            number_format_i18n((float) $position, 1)
        );
        if ($previous !== null && abs((float) $previous - (float) $position) >= 0.1) {
            $line .= ' ' . sprintf(
                /* translators: %s: previous average position, one decimal. */
                __('(was %s)', 'thinkrank'),
                number_format_i18n((float) $previous, 1)
            );
        }
        return $line;
    }

    /**
     * "+412 clicks" / "−188 clicks".
     */
    private static function clicks_pill(int $change): string {
        $sign = $change < 0 ? '−' : '+';
        return $sign . sprintf(
            /* translators: %s: number of clicks. */
            _n('%s click', '%s clicks', abs($change), 'thinkrank'),
            number_format_i18n(abs($change))
        );
    }
}
