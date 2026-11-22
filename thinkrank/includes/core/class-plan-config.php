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
     *   usage_policy   bool  May publish usage-policy directives in llms.txt.
     *   full_document  bool  May publish llms-full.txt, the full-content
     *                        companion document (thinkrank-pro#171).
     *
     * @since 2.5.0
     *
     * @return array Capability map.
     */
    public static function llms_txt(): array {
        $defaults = [
            'usage_policy'  => false,
            'full_document' => false,
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
     * Capability map for sitewide duplicate snippet detection.
     *
     * The check itself is Free: finding out that two of your pages carry one
     * title is a read, it runs when the user asks for it, and it is exactly the
     * kind of thing that should work on a first install (#564). What Pro adds
     * is the unattended half — a recheck that runs on a schedule and tells you
     * when a new duplicate appears, rather than when you next open the screen.
     *
     * Nothing in the free plugin schedules anything, so `scheduled` only ever
     * reports what Pro has arranged; the report reads it to say whether it is
     * being watched or only answered on demand.
     *
     * Schema:
     *   scheduled bool Duplicates are rechecked without the user asking.
     *
     * @since 2.10.0
     *
     * @return array Capability map.
     */
    public static function duplicate_snippets(): array {
        $defaults = [
            'scheduled' => false,
        ];

        /**
         * Filter the duplicate snippets capability map.
         *
         * @since 2.10.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_duplicate_snippets_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for the thin content report.
     *
     * Finding out which of your pages are thin is a read, it runs when the user
     * asks for it, and it is exactly what should work on a first install, so
     * the report is Free (#565). What Pro adds is the unattended half: an alert
     * when content that was fine becomes thin, or when something thin is
     * published, which is the decay framing Content Refresh Radar already uses.
     *
     * Nothing in the free plugin schedules anything, so `scheduled_alerts` only
     * ever reports what Pro has arranged; the report reads it to say whether it
     * is being watched or only answered on demand.
     *
     * Schema:
     *   scheduled_alerts bool Newly-thin content is reported without being asked.
     *
     * @since 2.10.0
     *
     * @return array Capability map.
     */
    public static function thin_content(): array {
        $defaults = [
            'scheduled_alerts' => false,
        ];

        /**
         * Filter the thin content capability map.
         *
         * @since 2.10.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_thin_content_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for Regression Guard.
     *
     * Checking a page by hand and seeing what changed since the last check is
     * FREE and stays free (#720). What Pro adds is the unattended half: checks
     * that run nightly and right after a plugin, theme or core update, across
     * a set of key pages, with a history of every check and an alert when a
     * change nobody made turns up.
     *
     * Nothing in the free plugin schedules anything, so these only ever report
     * what Pro has arranged.
     *
     * Schema:
     *   scheduled    bool Pages are checked without the user asking.
     *   history_runs int  Checks kept per page (0 = only the last one).
     *   alerts       bool Unexpected critical changes are reported by email.
     *
     * @since 2.23.0
     *
     * @return array Capability map.
     */
    public static function regression_guard(): array {
        $defaults = [
            'scheduled'    => false,
            'history_runs' => 0,
            'alerts'       => false,
        ];

        /**
         * Filter the Regression Guard capability map.
         *
         * @since 2.23.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_regression_guard_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for SEO Goals.
     *
     * The list itself — goals, done/ignore, section checklists — is FREE and
     * stays free (#728). What Pro adds is memory: when each goal opened and
     * was completed, "goals completed this month", and goals fed by Pro-only
     * signals (index drops, rank drops, decaying content, Focus Pages).
     *
     * Schema:
     *   history bool Goal openings and completions are recorded over time.
     *
     * @since 2.22.0
     *
     * @return array Capability map.
     */
    public static function seo_goals(): array {
        $defaults = [
            'history' => false,
        ];

        /**
         * Filter the SEO Goals capability map.
         *
         * @since 2.22.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_seo_goals_capabilities', $defaults);

        return array_merge($defaults, is_array($caps) ? $caps : []);
    }

    /**
     * Capability map for Title Experiments.
     *
     * One experiment at a time, the original against one variant, measured
     * on Search Console and kept with one click, is FREE (#719): it is a
     * complete, honest test on one page. Pro runs many at once, tests up to
     * four variants, applies winners on its own and keeps the history.
     *
     * Schema:
     *   concurrent   int  Experiments that may run at once.
     *   max_variants int  Variants per experiment, the original included.
     *   auto_apply   bool Winners are applied without a click.
     *   history      bool Finished experiments are kept.
     *
     * @since 2.22.0
     *
     * @return array Capability map.
     */
    public static function title_experiments(): array {
        $defaults = [
            'concurrent'   => 1,
            'max_variants' => 2,
            'auto_apply'   => false,
            'history'      => false,
        ];

        /**
         * Filter the Title Experiments capability map.
         *
         * @since 2.22.0
         *
         * @param array $defaults Capability map (see schema above).
         */
        $caps = apply_filters('thinkrank_title_experiments_capabilities', $defaults);

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
            case 'duplicate_snippets':
                return self::duplicate_snippets();
            case 'thin_content':
                return self::thin_content();
            case 'regression_guard':
                return self::regression_guard();
            case 'seo_goals':
                return self::seo_goals();
            case 'title_experiments':
                return self::title_experiments();
            default:
                return [];
        }
    }
}
