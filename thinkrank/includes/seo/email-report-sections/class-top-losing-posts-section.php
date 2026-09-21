<?php
/**
 * Top Losing Posts Section — "Pages losing ground".
 *
 * Pages with the largest click loss vs the previous period, computed from
 * the shared current-vs-previous comparison the Data Provider builds from
 * Search Console (page dimension). A page that dropped out of the current
 * window entirely still appears — the comparison keys off both windows.
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

final class Top_Losing_Posts_Section implements Email_Report_Section_Interface {

    private const MAX_ROWS = 5;

    public function key(): string {
        return 'top_losing_posts';
    }

    public function label(): string {
        return __('Pages losing ground', 'thinkrank');
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
        if (empty($comparison['available']) || empty($comparison['pages'])) {
            return [];
        }

        // Real losers: pages whose clicks fell vs the previous period.
        $rows = [];
        foreach ($comparison['pages'] as $entry) {
            $delta = (int) $entry['cur_clicks'] - (int) $entry['prev_clicks'];
            if ($delta >= 0) {
                continue;
            }
            $rows[] = [
                'url'    => $entry['url'] ?? '',
                'clicks' => (int) $entry['cur_clicks'],
                'change' => $delta,
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
        $rows = Top_Posts_Renderer::page_rows($payload['rows'] ?? []);
        if ($rows === []) {
            return '';
        }
        return Email_Report_Html::heading(
            $this->label(),
            __('Worth a look: these pages lost the most clicks vs the previous period', 'thinkrank')
        )
            . Email_Report_Html::list_rows($rows, 'down')
            . Email_Report_Html::link(__('See all pages', 'thinkrank'), Email_Report_Html::admin_link('analytics', 'dashboard'));
    }

    public function fallback_html(): string {
        return '';
    }
}
