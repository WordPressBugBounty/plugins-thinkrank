<?php
/**
 * MCP Access Section — what AI assistants changed, and a token about to lapse.
 *
 * Two lines an owner should not have to open the MCP screen to learn: how many
 * changes AI assistants made to the site this period, and that the connection
 * token header-based clients use expires within a week (or already has).
 * Rendered only when one of those is true, so a site that never connected an
 * assistant never sees it (#717).
 *
 * @package ThinkRank
 * @subpackage SEO\Email_Report_Sections
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Email_Report_Sections;

use ThinkRank\Mcp\Mcp_Audit;
use ThinkRank\Mcp\Mcp_Manager;
use ThinkRank\Mcp\Mcp_Pairing;

if (!defined('ABSPATH')) {
    exit;
}

final class Mcp_Access_Section implements Email_Report_Section_Interface {

    public function key(): string {
        return 'mcp_access';
    }

    public function label(): string {
        return __('AI assistant access', 'thinkrank');
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
        if (!class_exists(Mcp_Manager::class) || !Mcp_Manager::is_enabled()) {
            return [];
        }

        // Report periods are whole days (Search Console's lagged window), and
        // consecutive reports tile them, so each change is counted once.
        $since = $this->utc((string) ($context['period_start'] ?? ''), 0);
        $until = $this->utc((string) ($context['period_end'] ?? ''), DAY_IN_SECONDS);

        $changes = ($since !== '' && $until !== '') ? Mcp_Audit::count_changes($since, $until) : 0;

        $status  = Mcp_Pairing::is_connected() ? Mcp_Pairing::public_status() : [];
        $expires = (int) ($status['expires_at'] ?? 0);

        return [
            'changes'      => $changes,
            'expires_at'   => $expires,
            'expired'      => !empty($status['expired']),
            'expires_soon' => !empty($status['expires_soon']),
        ];
    }

    public function has_data(array $payload): bool {
        return (int) ($payload['changes'] ?? 0) > 0
            || !empty($payload['expired'])
            || !empty($payload['expires_soon']);
    }

    public function render(array $payload): string {
        if (!$this->has_data($payload)) {
            return '';
        }

        $lines   = [];
        $changes = (int) $payload['changes'];

        if ($changes > 0) {
            $lines[] = sprintf(
                /* translators: %s: number of changes. */
                _n('AI assistants made %s change to your site this period.', 'AI assistants made %s changes to your site this period.', $changes, 'thinkrank'),
                '<strong>' . esc_html(number_format_i18n($changes)) . '</strong>'
            ) . ' ' . esc_html__('Each one is in the activity log, and those that recorded the value they replaced can be reverted there.', 'thinkrank');
        }

        $date = (int) $payload['expires_at'] > 0 ? wp_date(get_option('date_format'), (int) $payload['expires_at']) : '';

        if (!empty($payload['expired'])) {
            $lines[] = sprintf(
                /* translators: %s: date. */
                esc_html__('The connection token that Claude Code, Cursor and VS Code use expired on %s. Those clients are disconnected until you reset it and paste the new command.', 'thinkrank'),
                esc_html($date)
            );
        } elseif (!empty($payload['expires_soon'])) {
            $lines[] = sprintf(
                /* translators: %s: date. */
                esc_html__('The connection token that Claude Code, Cursor and VS Code use expires on %s. Reset it before then to keep those clients connected.', 'thinkrank'),
                '<strong>' . esc_html($date) . '</strong>'
            );
        }

        $detail = '<div style="' . Email_Report_Html::FONT . 'font-size:13px;color:' . Email_Report_Html::INK . ';margin-top:10px;line-height:1.7;">'
            . implode('<br>', $lines) . '</div>';

        return Email_Report_Html::heading(
            $this->label(),
            __('What AI assistants connected through MCP did on your site', 'thinkrank')
        )
            . $detail
            . Email_Report_Html::link(__('Open MCP activity', 'thinkrank'), (string) admin_url('admin.php?page=thinkrank-ai-tools&tab=mcp'));
    }

    public function fallback_html(): string {
        return '';
    }

    /**
     * A report period bound (Y-m-d, UTC) as Y-m-d H:i:s, the form the
     * activity log stores, shifted by $offset seconds.
     */
    private function utc(string $day, int $offset): string {
        if ($day === '') {
            return '';
        }
        $ts = strtotime($day . ' 00:00:00 UTC');
        return false === $ts ? '' : gmdate('Y-m-d H:i:s', $ts + $offset);
    }
}
