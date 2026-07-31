<?php
/**
 * Top Winning Posts Section
 *
 * Pages with the largest click gain vs the previous period, computed from
 * the shared current-vs-previous comparison the Data Provider builds from
 * Search Console (page dimension).
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

final class Top_Winning_Posts_Section implements Email_Report_Section_Interface {

    private const MAX_ROWS = 5;

    public function key(): string {
        return 'top_winning_posts';
    }

    public function label(): string {
        return __('Top Winning Posts', 'thinkrank');
    }

    public function default_enabled(): bool {
        return true;
    }

    public function requires_capability(): ?string {
        return null;
    }

    public function collect(array $context): array {
        $shared = $context['shared'] ?? [];
        $comparison = $shared['comparison'] ?? [];
        if (empty($comparison['available']) || empty($comparison['pages'])) {
            return [];
        }

        // Real gainers: pages whose clicks grew vs the previous period.
        $rows = [];
        foreach ($comparison['pages'] as $entry) {
            $delta = (int) $entry['cur_clicks'] - (int) $entry['prev_clicks'];
            if ($delta <= 0) {
                continue;
            }
            $rows[] = [
                'url'    => $entry['url'] ?? '',
                'clicks' => (int) $entry['cur_clicks'],
                'change' => $delta,
            ];
        }

        usort($rows, static fn($a, $b) => $b['change'] <=> $a['change']);

        return [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
        ];
    }

    public function render(array $payload): string {
        return Top_Posts_Renderer::render($payload['rows'] ?? [], 'gain');
    }

    public function fallback_html(): string {
        return '<p style="color:#6b7280;font-style:italic;">'
            . esc_html__('Search Console data unavailable. Connect Search Console to see your winning posts.', 'thinkrank')
            . '</p>';
    }
}
