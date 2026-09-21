<?php
/**
 * Site Traffic Section — sessions and active users from Google Analytics 4.
 *
 * Only renders when a GA4 property is connected; the report is built from
 * Search Console and this card is a bonus, not a requirement (#742). The
 * dashboard fetch covers the current window only, so no change is shown.
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

final class Site_Traffic_Section implements Email_Report_Section_Interface {

    public function key(): string {
        return 'site_traffic';
    }

    public function label(): string {
        return __('Site traffic (Google Analytics 4)', 'thinkrank');
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
        $traffic = $shared['current']['traffic'] ?? [];
        if (!is_array($traffic) || $traffic === []) {
            return [];
        }
        return [
            'sessions'  => (int) ($traffic['sessions'] ?? 0),
            'users'     => (int) ($traffic['active_users'] ?? $traffic['users'] ?? 0),
            'pageviews' => (int) ($traffic['pageviews'] ?? 0),
        ];
    }

    public function has_data(array $payload): bool {
        return (int) ($payload['sessions'] ?? 0) > 0 || (int) ($payload['users'] ?? 0) > 0;
    }

    public function render(array $payload): string {
        if (!$this->has_data($payload)) {
            return '';
        }
        $tiles = [
            Email_Report_Html::stat([
                'label' => __('Sessions', 'thinkrank'),
                'value' => number_format_i18n((int) $payload['sessions']),
                'icon'  => 'sessions',
            ]),
            Email_Report_Html::stat([
                'label' => __('Active users', 'thinkrank'),
                'value' => number_format_i18n((int) $payload['users']),
                'icon'  => 'users',
            ]),
        ];
        return Email_Report_Html::heading(__('Site traffic', 'thinkrank'), __('From Google Analytics 4, all channels', 'thinkrank'))
            . Email_Report_Html::stat_grid($tiles)
            . Email_Report_Html::link(__('Open the analytics dashboard', 'thinkrank'), Email_Report_Html::admin_link('analytics', 'dashboard'));
    }

    public function fallback_html(): string {
        return '';
    }
}
