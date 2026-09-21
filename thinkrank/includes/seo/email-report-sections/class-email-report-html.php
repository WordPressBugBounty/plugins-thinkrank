<?php
/**
 * Email Report HTML helpers
 *
 * The small vocabulary every card in the report is built from: a heading, a
 * stat tile with a change pill, a ranked list with +N / −N pills, a bar row,
 * a link. Everything is a table with inline styles so it survives Gmail,
 * Apple Mail and Outlook (#742).
 *
 * Stateless. Sections call these; the renderer's card shell and the layout
 * template share the same tokens so the whole email reads as one design.
 *
 * @package ThinkRank
 * @subpackage SEO\Email_Report_Sections
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Email_Report_Sections;

if (!defined('ABSPATH')) {
    exit;
}

final class Email_Report_Html {

    public const FONT    = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    public const INK     = '#14161f';
    public const MUTED   = '#5f6473';
    public const LINE    = '#e6e8ef';
    public const CANVAS  = '#f3f4f8';
    public const PRIMARY = '#4451ff';
    public const UP      = '#0f8a4f';
    public const UP_BG   = '#e5f6ec';
    public const DOWN    = '#c62828';
    public const DOWN_BG = '#fdecea';
    public const FLAT    = '#5f6473';
    public const FLAT_BG = '#eef0f6';

    /**
     * URL of a bundled email image (logo, stat icons).
     */
    public static function asset(string $file): string {
        return THINKRANK_PLUGIN_URL . 'static/img/email/' . $file;
    }

    /**
     * Card heading: title and an optional one-line subtitle.
     */
    public static function heading(string $title, string $subtitle = ''): string {
        $html = '<div style="' . self::FONT . 'font-size:17px;font-weight:700;color:' . self::INK . ';line-height:1.3;">'
            . esc_html($title) . '</div>';
        if ($subtitle !== '') {
            $html .= '<div style="' . self::FONT . 'font-size:13px;color:' . self::MUTED . ';margin-top:4px;line-height:1.5;">'
                . esc_html($subtitle) . '</div>';
        }
        return $html;
    }

    /**
     * A rounded pill. $tone is 'up', 'down' or 'flat'.
     */
    public static function pill(string $text, string $tone = 'flat', string $margin = ''): string {
        [$color, $bg] = self::tone_colors($tone);
        return '<span style="display:inline-block;' . $margin . 'padding:3px 9px;border-radius:999px;background:' . $bg
            . ';color:' . $color . ';' . self::FONT . 'font-size:12px;font-weight:700;white-space:nowrap;">'
            . esc_html($text) . '</span>';
    }

    /**
     * Change pill under a stat. $delta is the signed change already formatted
     * ("12.4%", "1.8"), $direction 'up'/'down'/'flat', $good whether that
     * direction is good news (clicks up = good; position number up = bad).
     */
    public static function change(?string $delta, string $direction, bool $good, string $suffix = ''): string {
        if ($delta === null) {
            return '';
        }
        if ($direction === 'flat') {
            return self::pill(__('No change', 'thinkrank'), 'flat', 'margin-top:6px;');
        }
        $arrow = $direction === 'up' ? '▲' : '▼';
        $text  = $arrow . ' ' . $delta . ($suffix !== '' ? ' ' . $suffix : '');
        return self::pill($text, $good ? 'up' : 'down', 'margin-top:6px;');
    }

    /**
     * One stat tile: icon circle, label, big number, change pill. Returns a
     * <td> — pair them with stat_grid().
     *
     * @param array{label:string,value:string,change?:string,icon?:string} $stat
     */
    public static function stat(array $stat): string {
        $icon = '';
        if (!empty($stat['icon'])) {
            $icon = '<td width="44" valign="top"><img src="' . esc_url(self::asset('icon-' . $stat['icon'] . '.png'))
                . '" width="40" height="40" alt="" style="display:block;border:0;border-radius:20px;" /></td>';
        }
        return '<td width="50%" valign="top" style="padding:12px 8px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>' . $icon
            . '<td style="padding-left:' . ($icon !== '' ? '12px' : '0') . ';' . self::FONT . '">'
            . '<div style="font-size:13px;color:' . self::MUTED . ';">' . esc_html($stat['label']) . '</div>'
            . '<div style="font-size:28px;font-weight:800;color:' . self::INK . ';letter-spacing:-0.02em;line-height:1.15;margin-top:2px;">'
            . esc_html($stat['value']) . '</div>'
            . ($stat['change'] ?? '')
            . '</td></tr></table></td>';
    }

    /**
     * Lay stat tiles out two per row.
     *
     * @param string[] $tiles Output of stat().
     */
    public static function stat_grid(array $tiles): string {
        if ($tiles === []) {
            return '';
        }
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;">';
        foreach (array_chunk($tiles, 2) as $pair) {
            $html .= '<tr>' . implode('', $pair) . (count($pair) === 1 ? '<td width="50%"></td>' : '') . '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Ranked list: a title, a muted line under it, and a pill on the right.
     *
     * @param array<int,array{title:string,subtitle?:string,pill:string,href?:string}> $rows
     * @param string $tone 'up' or 'down' for the pills.
     */
    public static function list_rows(array $rows, string $tone): string {
        if ($rows === []) {
            return '';
        }
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;">';
        foreach (array_values($rows) as $i => $row) {
            $border = $i > 0 ? 'border-top:1px solid ' . self::LINE . ';' : '';
            $title  = esc_html((string) $row['title']);
            if (!empty($row['href'])) {
                $title = '<a href="' . esc_url((string) $row['href']) . '" style="color:' . self::INK . ';text-decoration:none;">' . $title . '</a>';
            }
            $sub = '';
            if (!empty($row['subtitle'])) {
                $sub = '<div style="font-size:12px;color:' . self::MUTED . ';margin-top:2px;word-break:break-all;">' . esc_html((string) $row['subtitle']) . '</div>';
            }
            $html .= '<tr>'
                . '<td style="padding:12px 0;' . $border . self::FONT . '">'
                . '<div style="font-size:14px;font-weight:600;color:' . self::INK . ';line-height:1.35;word-break:break-word;">' . $title . '</div>' . $sub
                . '</td>'
                . '<td align="right" valign="middle" style="padding:12px 0 12px 12px;' . $border . 'white-space:nowrap;">'
                . self::pill((string) $row['pill'], $tone)
                . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * One horizontal bar row: label, bar, count (share).
     */
    public static function bar_row(string $label, int $count, int $total, string $color): string {
        $pct = $total > 0 ? (int) round($count / $total * 100) : 0;
        return '<tr>'
            . '<td style="padding:7px 0;' . self::FONT . 'font-size:13px;color:' . self::INK . ';width:120px;white-space:nowrap;">' . esc_html($label) . '</td>'
            . '<td style="padding:7px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="background:' . self::FLAT_BG . ';border-radius:6px;"><div style="width:' . $pct . '%;height:10px;border-radius:6px;background:' . $color . ';font-size:0;line-height:0;">&nbsp;</div></td>'
            . '</tr></table></td>'
            . '<td align="right" style="padding:7px 0;' . self::FONT . 'font-size:13px;font-weight:700;color:' . self::INK . ';width:96px;white-space:nowrap;">'
            . esc_html(number_format_i18n($count)) . ' <span style="color:' . self::MUTED . ';font-weight:500;">(' . $pct . '%)</span></td>'
            . '</tr>';
    }

    /**
     * Brand-coloured "See all ›" link.
     */
    public static function link(string $text, string $href): string {
        if ($href === '') {
            return '';
        }
        return '<div style="margin-top:14px;"><a href="' . esc_url($href) . '" style="' . self::FONT
            . 'font-size:14px;font-weight:600;color:' . self::PRIMARY . ';text-decoration:none;">' . esc_html($text) . ' ›</a></div>';
    }

    /**
     * Muted note under a card's content.
     */
    public static function note(string $text, string $margin_top = '10px'): string {
        return '<div style="' . self::FONT . 'font-size:13px;color:' . self::MUTED . ';margin-top:' . $margin_top . ';line-height:1.5;">'
            . esc_html($text) . '</div>';
    }

    /**
     * Percentage change between two totals, or null when there is no previous
     * value to compare with (a division by zero is not "+100%").
     *
     * @return array{text:string,direction:string}|null
     */
    public static function pct_change(float $current, float $previous): ?array {
        if ($previous <= 0.0) {
            return null;
        }
        $pct = ($current - $previous) / $previous * 100;
        if (abs($pct) < 0.05) {
            return ['text' => '0%', 'direction' => 'flat'];
        }
        return [
            'text'      => number_format_i18n(abs($pct), 1) . '%',
            'direction' => $pct > 0 ? 'up' : 'down',
        ];
    }

    /**
     * Signed percentage as a subject-line token: "+12.4%", "−3.1%", "".
     */
    public static function signed_pct(?array $change): string {
        if ($change === null || $change['direction'] === 'flat') {
            return '';
        }
        return ($change['direction'] === 'up' ? '+' : '−') . $change['text'];
    }

    /**
     * Path of a URL for a list subtitle: "/blog/post/" rather than the full
     * address, which is the site's own and already known to the reader.
     */
    public static function display_path(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($path === '') {
            return $url;
        }
        return $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Deep link into the Essential SEO screen. The recipient must be logged
     * in, but the link still lands them on the right panel.
     */
    public static function admin_link(string $section, string $item): string {
        return (string) admin_url(
            'admin.php?page=thinkrank-essential-seo&nav_section=' . rawurlencode($section) . '&nav_item=' . rawurlencode($item)
        );
    }

    /**
     * A page's title for a list row, when the URL belongs to this site and
     * resolves to a post; the URL path otherwise. A row that reads "How to
     * add a table of contents" is worth more than one that reads
     * "/blog/gutenberg-table-of-contents/".
     */
    public static function page_title(string $url): string {
        if (function_exists('url_to_postid') && function_exists('get_the_title')) {
            $post_id = (int) url_to_postid($url);
            if ($post_id > 0) {
                $title = trim((string) get_the_title($post_id));
                if ($title !== '') {
                    return $title;
                }
            }
        }
        return self::display_path($url);
    }

    /**
     * @return array{0:string,1:string} [text colour, background]
     */
    private static function tone_colors(string $tone): array {
        switch ($tone) {
            case 'up':
                return [self::UP, self::UP_BG];
            case 'down':
                return [self::DOWN, self::DOWN_BG];
            default:
                return [self::FLAT, self::FLAT_BG];
        }
    }
}
