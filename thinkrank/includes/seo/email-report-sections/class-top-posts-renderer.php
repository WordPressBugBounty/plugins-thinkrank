<?php
/**
 * Top Posts Renderer
 *
 * Shared HTML helper used by Top Winning Posts and Top Losing Posts so
 * both render identically. Not a section itself — purely a stateless
 * presentation helper.
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
     * Render a page table (page, clicks, click change vs previous period).
     *
     * @param array  $rows    Each row: ['url'|'page' => string, 'clicks' => int, 'change' => ?int, 'title' => ?string]
     * @param string $variant 'gain' | 'loss' — controls accent color only.
     */
    public static function render(array $rows, string $variant = 'gain'): string {
        if (empty($rows)) {
            return '';
        }

        $accent = $variant === 'loss' ? '#dc2626' : '#16a34a';

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        $html .= '<tr>'
            . self::th(__('Page', 'thinkrank'), 'left')
            . self::th(__('Clicks', 'thinkrank'), 'right')
            . self::th(__('Change', 'thinkrank'), 'right')
            . '</tr>';

        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? $row['page'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $clicks = (int) ($row['clicks'] ?? 0);
            $display = $title !== '' ? $title : $url;

            $html .= '<tr>'
                . '<td style="padding:10px;border-bottom:1px solid #f3f4f6;">'
                .   ($url !== ''
                        ? '<a href="' . esc_url($url) . '" style="color:#111827;text-decoration:none;">' . esc_html($display) . '</a>'
                        : '<span style="color:#111827;">' . esc_html($display) . '</span>')
                . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #f3f4f6;text-align:right;color:#374151;font:600 13px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">'
                .   esc_html(number_format_i18n($clicks))
                . '</td>'
                . self::change_cell($row['change'] ?? null, $accent)
                . '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Render a keyword table (query, position, clicks, click change).
     *
     * @param array  $rows    Each row: ['query' => string, 'position' => float, 'clicks' => int, 'change' => ?int]
     * @param string $variant 'gain' | 'loss'
     */
    public static function render_keywords(array $rows, string $variant = 'gain'): string {
        if (empty($rows)) {
            return '';
        }

        $accent = $variant === 'loss' ? '#dc2626' : '#16a34a';

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        $html .= '<tr>'
            . self::th(__('Keyword', 'thinkrank'), 'left')
            . self::th(__('Position', 'thinkrank'), 'right')
            . self::th(__('Clicks', 'thinkrank'), 'right')
            . self::th(__('Change', 'thinkrank'), 'right')
            . '</tr>';

        foreach ($rows as $row) {
            $query = (string) ($row['query'] ?? ($row['keys'][0] ?? ''));
            $position = (float) ($row['position'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            if ($query === '') {
                continue;
            }
            $html .= '<tr>'
                . '<td style="padding:10px;border-bottom:1px solid #f3f4f6;color:#111827;">' . esc_html($query) . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #f3f4f6;text-align:right;color:#374151;font:600 13px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">' . esc_html(number_format_i18n($position, 1)) . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #f3f4f6;text-align:right;color:#374151;">' . esc_html(number_format_i18n($clicks)) . '</td>'
                . self::change_cell($row['change'] ?? null, $accent)
                . '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Table header cell.
     */
    private static function th(string $label, string $align): string {
        return '<th style="text-align:' . esc_attr($align) . ';padding:8px 10px;border-bottom:1px solid #e5e7eb;font:600 12px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">'
            . esc_html($label) . '</th>';
    }

    /**
     * Signed click-change cell. Positive renders green with ▲, negative red
     * with ▼. A null/zero change renders a neutral dash.
     */
    private static function change_cell($change, string $accent): string {
        $td = '<td style="padding:10px;border-bottom:1px solid #f3f4f6;text-align:right;font:600 13px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;color:%s;">%s</td>';

        if ($change === null || (int) $change === 0) {
            return sprintf($td, '#9ca3af', '—');
        }
        $change = (int) $change;
        $arrow = $change > 0 ? '▲' : '▼';
        return sprintf($td, esc_attr($accent), esc_html($arrow . ' ' . number_format_i18n(abs($change))));
    }
}
