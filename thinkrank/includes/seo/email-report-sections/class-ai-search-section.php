<?php
/**
 * AI Search Section — visits referred by AI assistants.
 *
 * Reads the first-party tracker AI Insights already runs: referral sessions
 * from ChatGPT, Perplexity, Gemini, Claude, Copilot and the rest, their share
 * of all sessions, the top sources, crawler fetches and whether llms.txt is
 * live. Rendered only when the tracker has recorded something (#742).
 *
 * @package ThinkRank
 * @subpackage SEO\Email_Report_Sections
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Email_Report_Sections;

if (!defined('ABSPATH')) {
    exit;
}

final class Ai_Search_Section implements Email_Report_Section_Interface {

    private const PLATFORM_LABELS = [
        'chatgpt'    => 'ChatGPT',
        'perplexity' => 'Perplexity',
        'gemini'     => 'Gemini',
        'claude'     => 'Claude',
        'copilot'    => 'Copilot',
        'meta-ai'    => 'Meta AI',
        'you'        => 'You.com',
        'poe'        => 'Poe',
        'grok'       => 'Grok',
        'mistral'    => 'Mistral',
        'deepseek'   => 'DeepSeek',
        'kimi'       => 'Kimi',
    ];

    public function key(): string {
        return 'ai_search';
    }

    public function label(): string {
        return __('Traffic from AI assistants', 'thinkrank');
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
        $ai     = $shared['ai'] ?? null;
        if (!is_array($ai) || !is_array($ai['current'] ?? null)) {
            return [];
        }
        $current  = $ai['current'];
        $previous = is_array($ai['previous'] ?? null) ? $ai['previous'] : [];

        $prev_sessions = (int) ($previous['ai_sessions'] ?? 0);
        $prev_baseline = (int) ($previous['baseline'] ?? 0);

        return [
            'ai_sessions'   => (int) ($current['ai_sessions'] ?? 0),
            'baseline'      => (int) ($current['baseline'] ?? 0),
            'ai_share'      => (float) ($current['ai_share'] ?? 0.0),
            'prev_sessions' => $prev_baseline > 0 || $prev_sessions > 0 ? $prev_sessions : null,
            'prev_share'    => $prev_baseline > 0 ? round($prev_sessions / $prev_baseline * 100, 1) : null,
            'platforms'     => is_array($current['platforms'] ?? null) ? $current['platforms'] : [],
            'crawler_hits'  => (int) array_sum(array_map('intval', is_array($current['crawlers'] ?? null) ? $current['crawlers'] : [])),
            'llms_txt'      => !empty($current['llms_txt']),
        ];
    }

    public function has_data(array $payload): bool {
        return (int) ($payload['ai_sessions'] ?? 0) > 0 || (int) ($payload['crawler_hits'] ?? 0) > 0;
    }

    public function render(array $payload): string {
        if (!$this->has_data($payload)) {
            return '';
        }

        $sessions_change = '';
        if ($payload['prev_sessions'] !== null) {
            $c = Email_Report_Html::pct_change((float) $payload['ai_sessions'], (float) $payload['prev_sessions']);
            $sessions_change = $c ? Email_Report_Html::change($c['text'], $c['direction'], $c['direction'] !== 'down') : '';
        }
        $share_change = '';
        if ($payload['prev_share'] !== null) {
            $pts = round((float) $payload['ai_share'] - (float) $payload['prev_share'], 1);
            if (abs($pts) >= 0.1) {
                $share_change = Email_Report_Html::change(number_format_i18n(abs($pts), 1), $pts > 0 ? 'up' : 'down', $pts > 0, __('pts', 'thinkrank'));
            }
        }

        $tiles = [
            Email_Report_Html::stat([
                'label'  => __('AI referral sessions', 'thinkrank'),
                'value'  => number_format_i18n((int) $payload['ai_sessions']),
                'change' => $sessions_change,
                'icon'   => 'ai',
            ]),
            Email_Report_Html::stat([
                'label'  => __('Share of all sessions', 'thinkrank'),
                'value'  => number_format_i18n((float) $payload['ai_share'], 1) . '%',
                'change' => $share_change,
                'icon'   => 'share',
            ]),
        ];

        $lines = [];
        $sources = $this->source_line((array) $payload['platforms'], (int) $payload['ai_sessions']);
        if ($sources !== '') {
            $lines[] = __('Top sources:', 'thinkrank') . ' ' . $sources;
        }
        if ((int) $payload['crawler_hits'] > 0) {
            $lines[] = sprintf(
                /* translators: %s: number of page fetches by AI crawlers. */
                _n('AI crawlers fetched %s page this period.', 'AI crawlers fetched %s pages this period.', (int) $payload['crawler_hits'], 'thinkrank'),
                '<strong>' . esc_html(number_format_i18n((int) $payload['crawler_hits'])) . '</strong>'
            );
        }
        $lines[] = !empty($payload['llms_txt'])
            ? __('Your llms.txt is published.', 'thinkrank')
            : __('Your llms.txt is not published yet, so AI crawlers get no guide to your site.', 'thinkrank');

        $detail = '<div style="' . Email_Report_Html::FONT . 'font-size:13px;color:' . Email_Report_Html::INK . ';margin-top:6px;line-height:1.7;">'
            . implode('<br>', $lines) . '</div>';

        return Email_Report_Html::heading(
            $this->label(),
            __('Visits referred by ChatGPT, Perplexity, Gemini, Claude, Copilot and other assistants', 'thinkrank')
        )
            . Email_Report_Html::stat_grid($tiles)
            . $detail
            . Email_Report_Html::link(__('Open AI Insights', 'thinkrank'), Email_Report_Html::admin_link('ai-insights', 'ai-insights-overview'));
    }

    public function fallback_html(): string {
        return '';
    }

    /**
     * "ChatGPT 62% · Perplexity 21% · Other 6%" — top three platforms by
     * share, the rest folded into Other. Already-escaped HTML.
     */
    private function source_line(array $platforms, int $total): string {
        if ($total <= 0 || $platforms === []) {
            return '';
        }
        arsort($platforms);
        $parts = [];
        $shown = 0;
        $i     = 0;
        foreach ($platforms as $slug => $hits) {
            if ($i++ >= 3) {
                break;
            }
            $shown += (int) $hits;
            $label  = self::PLATFORM_LABELS[(string) $slug] ?? ucfirst((string) $slug);
            $parts[] = '<strong>' . esc_html($label) . '</strong> ' . (int) round((int) $hits / $total * 100) . '%';
        }
        $rest = $total - $shown;
        if ($rest > 0) {
            $parts[] = esc_html__('Other', 'thinkrank') . ' ' . (int) round($rest / $total * 100) . '%';
        }
        return implode(' · ', $parts);
    }
}
