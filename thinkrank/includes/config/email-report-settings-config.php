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
 * in template paths. `ai_search` renders only when the AI traffic tracker
 * has something to show.
 *
 * ThinkRank Pro adds its GA4 `site_traffic` card here through
 * `thinkrank_email_report_default_sections` (#1115).
 *
 * @return array<string,array{label:string}>
 */
function thinkrank_get_email_report_default_sections(): array {
    $sections = [
        'key_metrics' => [
            'label' => __('Search performance', 'thinkrank'),
        ],
        'top_winning_posts' => [
            'label' => __('Top growing pages', 'thinkrank'),
        ],
        'top_winning_keywords' => [
            'label' => __('Top growing queries', 'thinkrank'),
        ],
        'top_losing_posts' => [
            'label' => __('Pages losing ground', 'thinkrank'),
        ],
        'top_losing_keywords' => [
            'label' => __('Queries losing ground', 'thinkrank'),
        ],
        'position_summary' => [
            'label' => __('Where your keywords rank', 'thinkrank'),
        ],
        'ai_search' => [
            'label' => __('Traffic from AI assistants', 'thinkrank'),
        ],
        'mcp_access' => [
            'label' => __('AI assistant access', 'thinkrank'),
        ],
    ];

    /**
     * Filter the section catalog: the default section list, the keys a saved
     * section choice may hold, and the catalog ThinkRank Pro reads to offer
     * new sections.
     *
     * Add a section with its key and label in the position it renders. A
     * section added here also needs registering on
     * `thinkrank_email_report_register_sections`, or it is listed but never
     * rendered.
     *
     * @since 2.17.1
     *
     * @param array<string,array{label:string}> $sections Key => ['label' => string], in render order.
     */
    $filtered = apply_filters('thinkrank_email_report_default_sections', $sections);

    return is_array($filtered) ? $filtered : $sections;
}
