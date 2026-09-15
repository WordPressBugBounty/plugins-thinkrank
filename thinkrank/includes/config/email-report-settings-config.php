<?php
/**
 * Email Report Settings Configuration
 *
 * Default stored state and section catalog for the Email Reporting feature.
 *
 * The free report is fixed: every 30 days, to the site admin email, with every
 * section. Only the on/off switch and the schedule timestamps are stored.
 * Schedule, recipients and branding settings belong to ThinkRank Pro, which
 * supplies them through the `thinkrank_email_report_config`,
 * `thinkrank_email_report_subject_template` and `thinkrank_email_report_payload`
 * filters (#673).
 *
 * @package ThinkRank
 * @subpackage Config
 * @since 1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default stored Email Reporting state.
 *
 * @return array{enabled: bool, next_scheduled_at: ?string, last_sent_at: ?string}
 */
function thinkrank_get_default_email_report_config(): array {
    return [
        'enabled'           => false,
        'next_scheduled_at' => null,
        'last_sent_at'      => null,
    ];
}

/**
 * Built-in sections, in render order.
 *
 * Keys here are the section identifiers used everywhere — in the
 * sections_enabled list of the resolved config, in the Section_Registry, and
 * in template paths.
 *
 * @return array<string,array{label:string}>
 */
function thinkrank_get_email_report_default_sections(): array {
    return [
        'key_metrics' => [
            'label' => __('Key Metrics', 'thinkrank'),
        ],
        'position_summary' => [
            'label' => __('Position Summary', 'thinkrank'),
        ],
        'top_winning_posts' => [
            'label' => __('Top Winning Posts', 'thinkrank'),
        ],
        'top_losing_posts' => [
            'label' => __('Top Losing Posts', 'thinkrank'),
        ],
        'top_winning_keywords' => [
            'label' => __('Top Winning Keywords', 'thinkrank'),
        ],
        'top_losing_keywords' => [
            'label' => __('Top Losing Keywords', 'thinkrank'),
        ],
    ];
}
