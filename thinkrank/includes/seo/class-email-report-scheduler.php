<?php
/**
 * Email Report Scheduler
 *
 * Owns the cron lifecycle for Email Reporting:
 *  - registers the recurring `thinkrank_email_report_tick` event
 *  - on each tick, asks the config whether the site is due
 *  - delegates to Email_Report_Generator::generate_for_due()
 *
 * The cron event is cleared on plugin deactivation by ThinkRank\Core\Deactivator
 * (see its clear_scheduled_hooks()).
 *
 * Cron cadence: hourly. Per-site frequency (7/15/30 days) is enforced
 * by checking next_scheduled_at against now — not by scheduling N
 * different cron events. One event for all sites, simple to reason about.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Scheduler
 *
 * @since 1.9.0
 */
final class Email_Report_Scheduler {

    public const CRON_HOOK = 'thinkrank_email_report_tick';

    private Email_Report_Config $config;
    private Email_Report_Generator $generator;

    public function __construct(Email_Report_Config $config, Email_Report_Generator $generator) {
        $this->config = $config;
        $this->generator = $generator;
    }

    /**
     * Register cron + handler. Called once at plugin boot.
     */
    public function register(): void {
        add_action(self::CRON_HOOK, [$this, 'on_tick']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Cron tick handler. Runs every hour.
     *
     * Decision tree:
     *   1. Feature disabled → skip
     *   2. No next_scheduled_at on file → set one and skip this tick
     *      (gives the user a fresh cycle starting from "now" rather than
     *       firing immediately on first enable)
     *   3. next_scheduled_at is in the future → skip
     *   4. Otherwise → generate.
     */
    public function on_tick(): void {
        $config = $this->config->get();
        if (empty($config['enabled'])) {
            return;
        }

        if (empty($config['next_scheduled_at'])) {
            $next = wp_date('Y-m-d H:i:s', strtotime('+' . max(1, (int) $config['frequency_days']) . ' days'));
            $this->config->update_schedule(null, $next);
            return;
        }

        $next_ts = strtotime((string) $config['next_scheduled_at']);
        if ($next_ts && $next_ts > time()) {
            return;
        }

        $this->generator->generate_for_due();
    }

    /**
     * Convenience used by REST + admin UI to surface the next-send timestamp
     * to the user. Defends against an empty or stale next_scheduled_at.
     *
     * next_scheduled_at is stored as a site-local `Y-m-d H:i:s` string with
     * no zone information. Handing that to the browser meant `new Date()`
     * read it in the *viewer's* timezone, so any admin whose browser zone
     * differed from the site's saw the wrong time. Emit a real ISO 8601
     * string carrying the site's UTC offset instead, which parses to one
     * unambiguous instant everywhere.
     */
    public function next_run_iso(): ?string {
        $config = $this->config->get();
        if (empty($config['enabled']) || empty($config['next_scheduled_at'])) {
            return null;
        }

        // Interpret the stored wall clock in the site timezone, then render
        // that same instant with its offset attached.
        $timestamp = (int) get_gmt_from_date((string) $config['next_scheduled_at'], 'U');
        if ($timestamp <= 0) {
            return null;
        }

        return wp_date('c', $timestamp);
    }
}
