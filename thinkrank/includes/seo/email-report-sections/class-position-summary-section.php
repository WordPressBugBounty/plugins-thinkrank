<?php
/**
 * Position Summary Section — "Where your keywords rank".
 *
 * Buckets the period's queries into Top 3 / 4–10 / 11–50 / 51–100 and draws
 * them as bars. Source: GSC `position_distribution` already computed by
 * Analytics_Manager.
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
        return __('Where your keywords rank', 'thinkrank');
    }

    public function default_enabled(): bool {
        return true;
    }

    public function requires_capability(): ?string {
        return null;
    }

    public function renders_own_heading(): bool {
        return true;
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

    public function has_data(array $payload): bool {
        return array_sum(array_map('intval', $payload)) > 0;
    }

    public function render(array $payload): string {
        $counts = [
            'top_3'  => (int) ($payload['top_3'] ?? 0),
            '4_10'   => (int) ($payload['4_10'] ?? 0),
            '11_50'  => (int) ($payload['11_50'] ?? 0),
            '51_100' => (int) ($payload['51_100'] ?? 0),
        ];
        $total = array_sum($counts);
        if ($total === 0) {
            return '';
        }
        $page_one = $counts['top_3'] + $counts['4_10'];

        $html = Email_Report_Html::heading(
            $this->label(),
            sprintf(
                /* translators: %s: number of queries. */
                _n('%s query got at least one impression this period', '%s queries got at least one impression this period', $total, 'thinkrank'),
                number_format_i18n($total)
            )
        );
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;">'
            . Email_Report_Html::bar_row(__('Top 3', 'thinkrank'), $counts['top_3'], $total, Email_Report_Html::PRIMARY)
            . Email_Report_Html::bar_row(__('Positions 4–10', 'thinkrank'), $counts['4_10'], $total, '#6e79ff')
            . Email_Report_Html::bar_row(__('Positions 11–50', 'thinkrank'), $counts['11_50'], $total, '#b3b9ff')
            . Email_Report_Html::bar_row(__('Positions 51–100', 'thinkrank'), $counts['51_100'], $total, '#dcdfff')
            . '</table>';
        $html .= Email_Report_Html::note(sprintf(
            /* translators: %s: number of queries ranking in positions 1–10. */
            _n('%s query is on page one.', '%s queries are on page one.', $page_one, 'thinkrank'),
            number_format_i18n($page_one)
        ));
        $html .= Email_Report_Html::link(__('See all keywords', 'thinkrank'), Email_Report_Html::admin_link('analytics', 'keywords'));

        return $html;
    }

    public function fallback_html(): string {
        return '';
    }
}
