<?php
/**
 * Top Losing Keywords Section
 *
 * Keywords with the worst average position among those getting any
 * impressions. Without prior-period data this surfaces the keywords most
 * in need of attention; once Data_Provider returns a prior period it
 * naturally migrates to position deltas.
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
        return __('Top Losing Keywords', 'thinkrank');
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
                'query'    => $entry['query'] ?? '',
                'position' => (float) ($entry['cur_pos'] ?? 0),
                'clicks'   => (int) $entry['cur_clicks'],
                'change'   => $delta,
            ];
        }

        // Biggest click loss first (most negative).
        usort($rows, static fn($a, $b) => $a['change'] <=> $b['change']);

        return [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
        ];
    }

    public function render(array $payload): string {
        return Top_Posts_Renderer::render_keywords($payload['rows'] ?? [], 'loss');
    }

    public function fallback_html(): string {
        return '<p style="color:#6b7280;font-style:italic;">'
            . esc_html__('Search Console data unavailable. Connect Search Console to see top losing keywords.', 'thinkrank')
            . '</p>';
    }
}
