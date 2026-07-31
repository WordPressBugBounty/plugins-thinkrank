<?php
/**
 * Email Report Settings Configuration
 *
 * Default per-site configuration + field schema for the Email Reporting feature.
 * Used by Email_Report_Config (load/save), the renderer (templating), and the
 * REST endpoint (validation args).
 *
 * Free vs. Pro field availability is NOT decided here — it lives in
 * `Plan_Config::email_report()`. This file only describes shape and defaults.
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
 * Default Email Reporting per-site config.
 *
 * Pre-fills sensible values for a fresh install. Free plan never overrides
 * paid-only fields once saved; they sit dormant in the DB so they re-light
 * if the user upgrades.
 *
 * @return array
 */
function thinkrank_get_default_email_report_config(): array {
    return [
        'enabled'             => false,
        'frequency_days'      => 30,
        'recipients'          => [(string) get_option('admin_email')],
        'subject_template'    => '%site_title% SEO performance report',
        'logo_url'            => null,
        'logo_link'           => null,
        'header_background'   => null,
        'link_to_full_report' => true,
        'intro_text'          => null,
        'sections_enabled'    => array_keys(thinkrank_get_email_report_default_sections()),
        'footer_text'         => null,
        'additional_css'      => null,
        'next_scheduled_at'   => null,
        'last_sent_at'        => null,
    ];
}

/**
 * Default section ordering + free/pro flag.
 *
 * Keys here are the section identifiers used everywhere — in the sections_enabled
 * array of the per-site config, in the Section_Registry, and in template paths.
 *
 * The order in this array is the order sections render. Pro can re-order via
 * `thinkrank_email_report_sections` filter; AI Highlights inserts at the top.
 *
 * @return array<string,array{label:string,requires_capability:?string}>
 */
function thinkrank_get_email_report_default_sections(): array {
    return [
        'key_metrics' => [
            'label' => __('Key Metrics', 'thinkrank'),
            'requires_capability' => null,
        ],
        'position_summary' => [
            'label' => __('Position Summary', 'thinkrank'),
            'requires_capability' => null,
        ],
        'top_winning_posts' => [
            'label' => __('Top Winning Posts', 'thinkrank'),
            'requires_capability' => null,
        ],
        'top_losing_posts' => [
            'label' => __('Top Losing Posts', 'thinkrank'),
            'requires_capability' => null,
        ],
        'top_winning_keywords' => [
            'label' => __('Top Winning Keywords', 'thinkrank'),
            'requires_capability' => null,
        ],
        'top_losing_keywords' => [
            'label' => __('Top Losing Keywords', 'thinkrank'),
            'requires_capability' => null,
        ],
    ];
}

/**
 * Tokens supported in subject/intro/footer text.
 *
 * Each token resolves at render time against the report context. Pro can add
 * tokens via the `thinkrank_email_report_tokens` filter (e.g. %client_name%).
 *
 * @return array<string,string> token => human description
 */
function thinkrank_get_email_report_tokens(): array {
    return [
        '%site_title%' => __('Site title from WordPress general settings', 'thinkrank'),
        '%site_url%'   => __('Home URL of the site', 'thinkrank'),
        '%date%'       => __('Date the report is sent (localized)', 'thinkrank'),
        '%period%'     => __('Reporting period label, e.g. "May 1 – May 30"', 'thinkrank'),
    ];
}

/**
 * Maximum lengths for free-text fields. Defense-in-depth limits to keep
 * a misconfigured Pro plugin or pasted bulk content from blowing up the email.
 *
 * @return array<string,int>
 */
function thinkrank_get_email_report_field_limits(): array {
    return [
        'subject_template'  => 200,
        'intro_text'        => 5000,
        'footer_text'       => 5000,
        'additional_css'    => 20000,
        'logo_url'          => 2048,
        'logo_link'         => 2048,
        'header_background' => 500,
    ];
}
