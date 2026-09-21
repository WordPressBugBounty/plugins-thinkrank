<?php
/**
 * Top Winning Keywords Section — "Top growing queries".
 *
 * Queries with the largest click gain vs the previous period, computed from
 * the shared current-vs-previous comparison the Data Provider builds from
 * Search Console (query dimension). Ranking is on the click delta; average
 * position rides along as context.
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

final class Top_Winning_Keywords_Section implements Email_Report_Section_Interface {

    private const MAX_ROWS = 5;

    public function key(): string {
        return 'top_winning_keywords';
    }

    public function label(): string {
        return __('Top growing queries', 'thinkrank');
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
        $comparison = $shared['comparison'] ?? [];
        if (empty($comparison['available']) || empty($comparison['queries'])) {
            return [];
        }

        // Real gainers: keywords whose clicks grew vs the previous period.
        $rows = [];
        foreach ($comparison['queries'] as $entry) {
            $delta = (int) $entry['cur_clicks'] - (int) $entry['prev_clicks'];
            if ($delta <= 0) {
                continue;
            }
            $rows[] = [
                'query'         => $entry['query'] ?? '',
                'position'      => isset($entry['cur_pos']) ? (float) $entry['cur_pos'] : null,
                'prev_position' => isset($entry['prev_pos']) ? (float) $entry['prev_pos'] : null,
                'clicks'        => (int) $entry['cur_clicks'],
                'change'        => $delta,
            ];
        }

        usort($rows, static fn($a, $b) => $b['change'] <=> $a['change']);

        return [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
        ];
    }

    public function has_data(array $payload): bool {
        return !empty($payload['rows']);
    }

    public function render(array $payload): string {
        $rows = Top_Posts_Renderer::keyword_rows($payload['rows'] ?? []);
        if ($rows === []) {
            return '';
        }
        return Email_Report_Html::heading(
            $this->label(),
            __('Search terms that sent more clicks than last period', 'thinkrank')
        )
            . Email_Report_Html::list_rows($rows, 'up')
            . Email_Report_Html::link(__('See all queries', 'thinkrank'), Email_Report_Html::admin_link('analytics', 'keywords'));
    }

    public function fallback_html(): string {
        return '';
    }
}
