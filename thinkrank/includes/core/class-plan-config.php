<?php

/**
 * Plan Config Class
 *
 * Single source of truth for free vs. pro capability gating across ThinkRank.
 * Every freemium gate (REST validation, render pipeline, scheduler, mailer, UI)
 * reads from here. No feature code should check `defined('THINKRANK_PRO_VERSION')`
 * or `apply_filters('thinkrank_is_pro_active', ...)` directly — call these methods
 * instead so a single override flips behavior everywhere.
 *
 * The Pro plugin attaches by filtering `thinkrank_email_report_capabilities`
 * (or the analogous filter for other features). It never needs to fork or
 * monkey-patch this file.
 *
 * @package ThinkRank\Core
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plan_Config — capability registry for free/pro feature gating.
 *
 * Usage:
 *   if (Plan_Config::can('custom_subject', 'email_report')) { ... }
 *   $caps = Plan_Config::email_report();
 *
 * @since 1.9.0
 */
final class Plan_Config {

    /**
     * Whether the Pro plugin is active.
     *
     * Resolves through `thinkrank_is_pro_active` so the Pro plugin (or a
     * staging override) can flip the answer. Defaults to checking the
     * `THINKRANK_PRO_VERSION` constant the Pro plugin defines on load.
     */
    public static function is_pro(): bool {
        return (bool) apply_filters(
            'thinkrank_is_pro_active',
            defined('THINKRANK_PRO_VERSION')
        );
    }

    /**
     * Capability map for the Email Reporting feature.
     *
     * Returns a flat array of capability_key => bool|int|array describing
     * what the current plan can do. The Pro plugin filters this to enable
     * its capabilities.
     *
     * Schema (keep in sync with src/admin/config/email-report-plan.js):
     *   - allowed_frequencies   int[]   Days between sends user may pick.
     *   - max_recipients        int     Max addresses on the recipients field.
     *   - recipients_locked_to  string|null  'admin_email' = pre-filled & read-only.
     *   - custom_subject        bool    May edit subject template.
     *   - custom_logo           bool    May upload a header logo.
     *   - logo_link             bool    May set a click-through URL on the logo.
     *   - header_background     bool    May set a custom header background CSS.
     *   - link_to_full_report   bool    May toggle the dashboard CTA at the foot.
     *   - intro_text            bool    May set a custom intro paragraph.
     *   - sections_configurable bool    May enable/disable individual sections.
     *   - footer_text           bool    May set a custom footer paragraph.
     *   - additional_css        bool    May inject additional CSS into the email.
     *   - ai_highlights         bool    May render the AI Highlights section.
     */
    public static function email_report(): array {
        $defaults = [
            'allowed_frequencies'   => [30],
            'max_recipients'        => 1,
            'recipients_locked_to'  => 'admin_email',
            'custom_subject'        => false,
            'custom_logo'           => false,
            'logo_link'             => false,
            'header_background'     => false,
            'link_to_full_report'   => false,
            'intro_text'            => false,
            'sections_configurable' => false,
            'footer_text'           => false,
            'additional_css'        => false,
            'ai_highlights'         => false,
        ];

        if (self::is_pro()) {
            $defaults = array_merge($defaults, [
                'allowed_frequencies'   => [7, 15, 30],
                'max_recipients'        => 50,
                'recipients_locked_to'  => null,
                'custom_subject'        => true,
                'custom_logo'           => true,
                'logo_link'             => true,
                'header_background'     => true,
                'link_to_full_report'   => true,
                'intro_text'            => true,
                'sections_configurable' => true,
                'footer_text'           => true,
                'additional_css'        => true,
                // ai_highlights stays false by default — it's a separate sub-feature
                // the Pro plugin opts in to once the AI summary integration ships.
            ]);
        }

        /**
         * Filter the Email Reporting capability map.
         *
         * The Pro plugin uses this filter (and only this filter) to enable
         * pro capabilities. Returning a partial array is fine; missing keys
         * fall back to the values above.
         *
         * @since 1.9.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_email_report_capabilities', $defaults);

        // Defensive merge: never let a filter drop required keys.
        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for the Focus Keywords feature.
     *
     * Free allows up to 5 focus keywords; ThinkRank Pro lifts the cap. The 6th
     * and subsequent keywords are stored but only become usable (analyzed,
     * output, editable) once Pro raises the limit.
     *
     * Localized to the metabox as `thinkrankMetabox.focusKeywords`.
     * Schema:
     *   - max_keywords   int   Usable focus keywords. 0 = unlimited (Pro).
     *
     * @since 2.0.0
     *
     * @return array Capability map.
     */
    public static function focus_keywords(): array {
        // Free default. ThinkRank Pro lifts the cap by filtering the map below
        // (Pro owns its own limit rather than the free plugin hard-coding it).
        $defaults = [
            'max_keywords' => 5,
        ];

        /**
         * Filter the Focus Keywords capability map.
         *
         * The Pro plugin uses this filter to lift the free cap — set
         * `max_keywords` to 0 for unlimited, or a finite number.
         *
         * @since 2.0.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_focus_keywords_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Check a single capability for a given feature.
     *
     * Currently only the `email_report` feature is registered. Adding more
     * features means adding a switch case here that delegates to its own
     * capability builder method.
     *
     * @param string $capability Capability key (e.g. 'custom_subject').
     * @param string $feature    Feature scope (default 'email_report').
     */
    public static function can(string $capability, string $feature = 'email_report'): bool {
        $caps = self::capabilities_for($feature);
        return ! empty($caps[$capability]);
    }

    /**
     * Get the full capability map for a feature.
     *
     * @param string $feature Feature scope.
     * @return array
     */
    public static function capabilities_for(string $feature): array {
        switch ($feature) {
            case 'email_report':
                return self::email_report();
            default:
                return [];
        }
    }

    /**
     * Clamp a frequency value to one the current plan allows.
     *
     * Free plans always end up at 30. Pro plans accept 7, 15, or 30.
     * Anything else falls back to the highest allowed value (most permissive
     * default that still respects the cap).
     *
     * @param int $requested Requested frequency in days.
     * @return int Clamped frequency.
     */
    public static function clamp_email_report_frequency(int $requested): int {
        $allowed = self::email_report()['allowed_frequencies'];
        if (in_array($requested, $allowed, true)) {
            return $requested;
        }
        return (int) max($allowed);
    }

    /**
     * Truncate a list of recipients to the plan-allowed maximum.
     *
     * Used at save and at render time. The save-time call gives the user
     * feedback; the render-time call is a defense in depth so a downgrade
     * never accidentally fans a report out to a list the user no longer
     * has the plan for.
     *
     * @param string[] $recipients Recipient email addresses.
     * @return string[] Truncated, de-duplicated recipients.
     */
    public static function clamp_email_report_recipients(array $recipients): array {
        $caps = self::email_report();
        $unique = array_values(array_unique(array_filter(array_map('trim', $recipients))));

        if ('admin_email' === $caps['recipients_locked_to']) {
            return [(string) get_option('admin_email')];
        }

        return array_slice($unique, 0, (int) $caps['max_recipients']);
    }
}
