<?php
/**
 * Top Losing Keywords Section — "Queries losing ground".
 *
 * Queries with the largest click loss vs the previous period, computed from
 * the shared current-vs-previous comparison the Data Provider builds from
 * Search Console (query dimension). Ranking is on the click delta, not on
 * average position — position is carried alongside as context only, and is
 * null for a query that dropped out of the current window entirely.
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

final class Top_Losing_Keywords_Section implements Email_Report_Section_Interface {

    private const MAX_ROWS = 5;

    public function key(): string {
        return 'top_losing_keywords';
    }

    public function label(): string {
        return __('Queries losing ground', 'thinkrank');
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

        // Real losers: keywords whose clicks fell vs the previous period.
        $rows = [];
        foreach ($comparison['queries'] as $entry) {
            $delta = (int) $entry['cur_clicks'] - (int) $entry['prev_clicks'];
            if ($delta >= 0) {
                continue;
            }
            $rows[] = [
                'query'         => $entry['query'] ?? '',
                // A keyword that dropped out entirely has no current position.
                // Keep it null so the row says so instead of "0.0".
                'position'      => isset($entry['cur_pos']) ? (float) $entry['cur_pos'] : null,
                'prev_position' => isset($entry['prev_pos']) ? (float) $entry['prev_pos'] : null,
                'clicks'        => (int) $entry['cur_clicks'],
                'change'        => $delta,
            ];
        }

        // Biggest click loss first (most negative).
        usort($rows, static fn($a, $b) => $a['change'] <=> $b['change']);

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
            __('Worth a look: these search terms lost the most clicks vs the previous period', 'thinkrank')
        )
            . Email_Report_Html::list_rows($rows, 'down')
            . Email_Report_Html::link(__('See all queries', 'thinkrank'), Email_Report_Html::admin_link('analytics', 'keywords'));
    }

    public function fallback_html(): string {
        return '';
    }
}
