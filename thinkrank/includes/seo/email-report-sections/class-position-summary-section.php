<?php
/**
 * Position Summary Section
 *
 * Buckets tracked keywords into Top 3 / 4–10 / 11–50 / 51–100 brackets.
 * Source: GSC `position_distribution` already computed by Analytics_Manager.
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

final class Position_Summary_Section implements Email_Report_Section_Interface {

    public function key(): string {
        return 'position_summary';
    }

    public function label(): string {
        return __('Position Summary', 'thinkrank');
    }

    public function default_enabled(): bool {
        return true;
    }

    public function requires_capability(): ?string {
        return null;
    }

    public function collect(array $context): array {
        $shared = $context['shared'] ?? [];
        if (empty($shared['available'])) {
            return [];
        }
        $dist = $shared['current']['search_performance']['position_distribution'] ?? [];
        if (!is_array($dist) || empty($dist)) {
            return [];
        }
        return [
            'top_3'   => (int) ($dist['top_3']   ?? 0),
            '4_10'    => (int) ($dist['4_10']    ?? 0),
            '11_50'   => (int) ($dist['10_50']   ?? 0), // GSC's `10_50` bucket starts at 11
            '51_100'  => (int) ($dist['51_100']  ?? 0),
        ];
    }

    public function render(array $payload): string {
        $rows = [
            __('Top 3', 'thinkrank')      => $payload['top_3'],
            __('Position 4–10', 'thinkrank')  => $payload['4_10'],
            __('Position 11–50', 'thinkrank') => $payload['11_50'],
            __('Position 51–100', 'thinkrank') => $payload['51_100'],
        ];

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        foreach ($rows as $label => $count) {
            $html .= '<tr>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#374151;font:500 14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">' . esc_html($label) . '</td>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;color:#111827;font:700 14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">' . esc_html(number_format_i18n((int) $count)) . '</td>'
                . '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    public function fallback_html(): string {
        return '<p style="color:#6b7280;font-style:italic;">'
            . esc_html__('Search Console data unavailable. Connect Search Console to see position distribution.', 'thinkrank')
            . '</p>';
    }
}
