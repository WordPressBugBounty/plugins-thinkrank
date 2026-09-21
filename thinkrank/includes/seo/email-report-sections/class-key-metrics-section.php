<?php
/**
 * Key Metrics Section — the report's hero card.
 *
 * Clicks, impressions, CTR and average position for the period, each with
 * its change against the previous period of the same length. The numbers
 * come from the whole-property totals the Data Provider pulls for both
 * windows (#742), so the change is exact rather than summed from capped
 * row lists.
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
        return __('Search performance', 'thinkrank');
    }

    public function default_enabled(): bool {
        return true;
    }

    public function requires_capability(): ?string {
        return null;
    }

    /**
     * The card carries its own heading (site name, period) — not the label.
     */
    public function renders_own_heading(): bool {
        return true;
    }

    public function collect(array $context): array {
        $shared = $context['shared'] ?? [];
        if (empty($shared['available']) || empty($shared['readiness']['search_console'])) {
            return [];
        }

        $totals   = $shared['comparison']['totals'] ?? [];
        $current  = is_array($totals['current'] ?? null) ? $totals['current'] : [];
        $previous = is_array($totals['previous'] ?? null) ? $totals['previous'] : [];

        // Fall back to the dashboard totals when the dimensionless query
        // returned nothing — same numbers, just without a previous window.
        if ((int) ($current['clicks'] ?? 0) === 0 && (int) ($current['impressions'] ?? 0) === 0) {
            $dash = $shared['current']['search_performance']['totals'] ?? [];
            $current = [
                'clicks'      => (int) ($dash['clicks'] ?? 0),
                'impressions' => (int) ($dash['impressions'] ?? 0),
                'position'    => (float) ($dash['position'] ?? 0.0),
            ];
        }

        $has_previous = (int) ($previous['clicks'] ?? 0) > 0 || (int) ($previous['impressions'] ?? 0) > 0;

        return [
            'clicks'           => (int) ($current['clicks'] ?? 0),
            'impressions'      => (int) ($current['impressions'] ?? 0),
            'position'         => round((float) ($current['position'] ?? 0.0), 1),
            'prev_clicks'      => $has_previous ? (int) ($previous['clicks'] ?? 0) : null,
            'prev_impressions' => $has_previous ? (int) ($previous['impressions'] ?? 0) : null,
            'prev_position'    => $has_previous ? round((float) ($previous['position'] ?? 0.0), 1) : null,
            'period_days'      => (int) ($context['frequency_days'] ?? 30),
            'period_label'     => (string) ($context['period_label'] ?? ''),
        ];
    }

    /**
     * Zero clicks and zero impressions is a connected property with no
     * traffic — a placeholder, not a report.
     */
    public function has_data(array $payload): bool {
        return (int) ($payload['clicks'] ?? 0) > 0 || (int) ($payload['impressions'] ?? 0) > 0;
    }

    public function render(array $payload): string {
        $days = (int) ($payload['period_days'] ?? 30);
        $ctr  = self::ctr($payload['clicks'], $payload['impressions']);

        $clicks_change = '';
        $imps_change   = '';
        $ctr_change    = '';
        $pos_change    = '';
        $compared      = '';
        if ($payload['prev_clicks'] !== null) {
            $c = Email_Report_Html::pct_change((float) $payload['clicks'], (float) $payload['prev_clicks']);
            $i = Email_Report_Html::pct_change((float) $payload['impressions'], (float) $payload['prev_impressions']);
            $clicks_change = $c ? Email_Report_Html::change($c['text'], $c['direction'], $c['direction'] !== 'down') : '';
            $imps_change   = $i ? Email_Report_Html::change($i['text'], $i['direction'], $i['direction'] !== 'down') : '';

            $prev_ctr = self::ctr((int) $payload['prev_clicks'], (int) $payload['prev_impressions']);
            $pts      = round(($ctr - $prev_ctr) * 100, 1);
            if (abs($pts) >= 0.1) {
                $ctr_change = Email_Report_Html::change(
                    number_format_i18n(abs($pts), 1),
                    $pts > 0 ? 'up' : 'down',
                    $pts > 0,
                    __('pts', 'thinkrank')
                );
            } elseif ($prev_ctr > 0) {
                $ctr_change = Email_Report_Html::change('0', 'flat', true);
            }

            if ((float) $payload['prev_position'] > 0 && (float) $payload['position'] > 0) {
                $diff = round((float) $payload['prev_position'] - (float) $payload['position'], 1);
                if (abs($diff) >= 0.1) {
                    // A smaller position number is better, so "up" here means improved.
                    $pos_change = Email_Report_Html::change(
                        number_format_i18n(abs($diff), 1),
                        $diff > 0 ? 'up' : 'down',
                        $diff > 0,
                        $diff > 0 ? __('better', 'thinkrank') : __('worse', 'thinkrank')
                    );
                } else {
                    $pos_change = Email_Report_Html::change('0', 'flat', true);
                }
            }

            $compared = ' · ' . sprintf(
                /* translators: %d: number of days in the comparison window. */
                _n('compared with the previous %d day', 'compared with the previous %d days', $days, 'thinkrank'),
                $days
            );
        }

        $eyebrow = '<div style="' . Email_Report_Html::FONT . 'font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . Email_Report_Html::PRIMARY . ';">'
            . esc_html((string) get_bloginfo('name')) . '</div>';
        $title = '<div style="' . Email_Report_Html::FONT . 'font-size:24px;font-weight:800;color:' . Email_Report_Html::INK . ';letter-spacing:-0.02em;line-height:1.25;margin-top:6px;">'
            . esc_html(sprintf(
                /* translators: %d: number of days the report covers. */
                _n('Your search performance for the last %d day', 'Your search performance for the last %d days', $days, 'thinkrank'),
                $days
            )) . '</div>';
        $sub = '<div style="' . Email_Report_Html::FONT . 'font-size:14px;color:' . Email_Report_Html::MUTED . ';margin-top:6px;">'
            . esc_html((string) $payload['period_label'] . $compared) . '</div>';

        $tiles = [
            Email_Report_Html::stat([
                'label'  => __('Clicks from Google', 'thinkrank'),
                'value'  => number_format_i18n((int) $payload['clicks']),
                'change' => $clicks_change,
                'icon'   => 'clicks',
            ]),
            Email_Report_Html::stat([
                'label'  => __('Impressions', 'thinkrank'),
                'value'  => number_format_i18n((int) $payload['impressions']),
                'change' => $imps_change,
                'icon'   => 'impressions',
            ]),
            Email_Report_Html::stat([
                'label'  => __('Average CTR', 'thinkrank'),
                'value'  => number_format_i18n($ctr * 100, 1) . '%',
                'change' => $ctr_change,
                'icon'   => 'ctr',
            ]),
            Email_Report_Html::stat([
                'label'  => __('Average position', 'thinkrank'),
                'value'  => (float) $payload['position'] > 0 ? number_format_i18n((float) $payload['position'], 1) : '—',
                'change' => $pos_change,
                'icon'   => 'position',
            ]),
        ];

        return $eyebrow . $title . $sub
            . Email_Report_Html::stat_grid($tiles)
            . Email_Report_Html::note(__('Source: Google Search Console. Data is complete up to 2 days before this report.', 'thinkrank'), '8px');
    }

    public function fallback_html(): string {
        return '';
    }

    private static function ctr(int $clicks, int $impressions): float {
        return $impressions > 0 ? $clicks / $impressions : 0.0;
    }
}
