<?php
/**
 * Key Metrics Section
 *
 * Renders traffic, impressions, average position, and total tracked keywords
 * for the period. PRD §3, Sections-to-Include item 1.
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

final class Key_Metrics_Section implements Email_Report_Section_Interface {

    public function key(): string {
        return 'key_metrics';
    }

    public function label(): string {
        return __('Key Metrics', 'thinkrank');
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
        $current = $shared['current'] ?? [];
        $traffic = $current['traffic'] ?? [];
        $search = $current['search_performance'] ?? [];
        $totals = $search['totals'] ?? [];

        return [
            'sessions'      => (int) ($traffic['sessions'] ?? 0),
            'users'         => (int) ($traffic['users'] ?? 0),
            'impressions'   => (int) ($totals['impressions'] ?? 0),
            'clicks'        => (int) ($totals['clicks'] ?? 0),
            'avg_position'  => (float) ($totals['position'] ?? 0.0),
            'total_keywords' => (int) (count($search['rows'] ?? [])),
        ];
    }

    public function render(array $payload): string {
        $cells = [
            __('Sessions', 'thinkrank')        => number_format_i18n((int) $payload['sessions']),
            __('Users', 'thinkrank')           => number_format_i18n((int) $payload['users']),
            __('Impressions', 'thinkrank')     => number_format_i18n((int) $payload['impressions']),
            __('Clicks', 'thinkrank')          => number_format_i18n((int) $payload['clicks']),
            __('Avg. Position', 'thinkrank')   => number_format_i18n((float) $payload['avg_position'], 1),
            __('Tracked Keywords', 'thinkrank') => number_format_i18n((int) $payload['total_keywords']),
        ];

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        $i = 0;
        foreach ($cells as $label => $value) {
            if ($i % 3 === 0) {
                $html .= ($i === 0 ? '<tr>' : '</tr><tr>');
            }
            $html .= '<td style="width:33%;padding:10px;border:1px solid #e5e7eb;background:#f9fafb;text-align:center;">'
                . '<div style="font:500 12px/1.2 -apple-system,Segoe UI,Roboto,sans-serif;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">' . esc_html($label) . '</div>'
                . '<div style="font:700 20px/1.2 -apple-system,Segoe UI,Roboto,sans-serif;color:#111827;margin-top:4px;">' . esc_html($value) . '</div>'
                . '</td>';
            $i++;
        }
        $html .= '</tr></table>';
        return $html;
    }

    public function fallback_html(): string {
        return '<p style="color:#6b7280;font-style:italic;">'
            . esc_html__('Data temporarily unavailable. Please check your connected integrations.', 'thinkrank')
            . '</p>';
    }
}
