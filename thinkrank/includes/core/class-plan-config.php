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
 * The Pro plugin attaches by filtering each feature's capability map. It never
 * needs to fork or monkey-patch this file.
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
 *   if (Plan_Config::can('usage_policy', 'llms_txt')) { ... }
 *   $caps = Plan_Config::llms_txt();
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
     * Capability map for the llms.txt feature.
     *
     * Generating and editing llms.txt is FREE and stays free. What Pro adds is
     * the usage policy: the `Training:` / `Summarization:` / `Embedding:` /
     * `Require-Attribution:` directives that state what agents may do with the
     * content, rather than describing the content itself (#160 in Pro).
     *
     * Schema:
     *   usage_policy  bool  May publish usage-policy directives in llms.txt.
     *
     * @since 2.5.0
     *
     * @return array Capability map.
     */
    public static function llms_txt(): array {
        $defaults = [
            'usage_policy' => false,
        ];

        /**
         * Filter the llms.txt capability map.
         *
         * ThinkRank Pro sets `usage_policy` to true; nothing in the free
         * plugin ever does, so the directives simply never render without it.
         *
         * @since 2.5.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_llms_txt_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for the Site SEO Analyzer.
     *
     * Running the audit and seeing the score is FREE and stays free. What Pro
     * adds is memory: dated snapshots of each run, a score trend, and a
     * side-by-side comparison of two runs (#161 in Pro). Free keeps exactly
     * what it has today — the most recent run, cached for an hour.
     *
     * Schema:
     *   history      bool  May persist and read audit snapshots.
     *   history_runs int   Snapshots retained per site (0 = unlimited).
     *
     * @since 2.5.0
     *
     * @return array Capability map.
     */
    public static function seo_analyzer(): array {
        $defaults = [
            'history'      => false,
            'history_runs' => 0,
        ];

        /**
         * Filter the SEO Analyzer capability map.
         *
         * ThinkRank Pro sets `history` to true and declares how many runs it
         * retains. Nothing in the free plugin ever does, so no snapshot is
         * ever written without Pro.
         *
         * @since 2.5.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_seo_analyzer_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for Focus Pages.
     *
     * Focus Pages is Pro: it is bulk (many pages in one run), historical, and
     * depends on Pro-only rank data (thinkrank-pro#164). Free has no entry
     * point in v1 — the per-post SEO score panel already answers the single-page
     * on-page question.
     *
     * Schema:
     *   enabled   bool  May curate and diagnose focus pages.
     *   max_pages int   Pages that may be curated (0 = none).
     *
     * @since 2.5.0
     *
     * @return array Capability map.
     */
    public static function focus_pages(): array {
        $defaults = [
            'enabled'   => false,
            'max_pages' => 0,
        ];

        /**
         * Filter the Focus Pages capability map.
         *
         * ThinkRank Pro enables the feature and declares its own page cap,
         * which bounds the external API cost of a refresh.
         *
         * @since 2.5.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_focus_pages_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Check a single capability for a given feature.
     *
     * Adding a feature means adding a switch case to capabilities_for() that
     * delegates to its own capability builder method.
     *
     * @param string $capability Capability key (e.g. 'usage_policy').
     * @param string $feature    Feature scope (e.g. 'llms_txt').
     */
    public static function can(string $capability, string $feature): bool {
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
            case 'llms_txt':
                return self::llms_txt();
            case 'seo_analyzer':
                return self::seo_analyzer();
            case 'focus_pages':
                return self::focus_pages();
            default:
                return [];
        }
    }
}
