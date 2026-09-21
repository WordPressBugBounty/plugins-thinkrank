<?php
/**
 * Email Report Config
 *
 * Persistence layer for the per-site Email Reporting state. Stores a single
 * associative array under the `thinkrank_email_report_config` option.
 *
 * The free report is fixed: every 30 days, to the site admin email, with every
 * section. What is stored is only whether it is on and when it last and next
 * runs. ThinkRank Pro owns the schedule, recipient and branding settings and
 * supplies them through the `thinkrank_email_report_config` filter (#673).
 *
 * Keys a save does not own are left in the stored array untouched, so values an
 * earlier release wrote there (recipients, branding) survive for Pro to pick up.
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
 * Email_Report_Config
 *
 * @since 1.9.0
 */
final class Email_Report_Config {

    private const OPTION_KEY = 'thinkrank_email_report_config';

    /**
     * Days between reports when nothing filters the schedule.
     */
    public const FREQUENCY_DAYS = 30;

    /**
     * Load the procedural defaults file, which lives outside the autoloader.
     */
    private function load_defaults_file(): void {
        if (!function_exists('thinkrank_get_default_email_report_config')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/config/email-report-settings-config.php';
        }
    }

    /**
     * The stored option, as an array.
     */
    private function stored(): array {
        $stored = get_option(self::OPTION_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * The resolved config every consumer reads.
     *
     * @return array{enabled: bool, frequency_days: int, recipients: string[], sections_enabled: string[], next_scheduled_at: ?string, last_sent_at: ?string, last_skip: ?array}
     */
    public function get(): array {
        $this->load_defaults_file();

        $stored = $this->stored();
        $state  = [
            'enabled'           => !empty($stored['enabled']),
            'next_scheduled_at' => $stored['next_scheduled_at'] ?? null,
            'last_sent_at'      => $stored['last_sent_at'] ?? null,
            // Why the last scheduled run sent nothing, or null. Read by the
            // panel so a paused report is never mistaken for a healthy one.
            'last_skip'         => is_array($stored['last_skip'] ?? null) ? $stored['last_skip'] : null,
        ];

        $report = [
            'frequency_days'   => self::FREQUENCY_DAYS,
            'recipients'       => [(string) get_option('admin_email')],
            'sections_enabled' => array_keys(thinkrank_get_email_report_default_sections()),
        ];

        /**
         * Filter what the report covers and who receives it.
         *
         * ThinkRank Pro returns its own schedule, recipients and sections here.
         * Extra keys are passed through to the renderer and mailer filters.
         * The on/off switch and schedule timestamps are not filterable.
         *
         * @since 2.6.0
         *
         * @param array $report {
         *     @type int      $frequency_days   Days between reports.
         *     @type string[] $recipients       Recipient addresses.
         *     @type string[] $sections_enabled Section keys, in render order.
         * }
         * @param array $state  Stored on/off switch and schedule timestamps.
         */
        $filtered = apply_filters('thinkrank_email_report_config', $report, $state);
        $filtered = is_array($filtered) ? $filtered : $report;

        return array_merge(
            $filtered,
            [
                'frequency_days'   => max(1, (int) ($filtered['frequency_days'] ?? self::FREQUENCY_DAYS)),
                'recipients'       => $this->normalize_recipients($filtered['recipients'] ?? []),
                'sections_enabled' => $this->normalize_section_keys($filtered['sections_enabled'] ?? []),
            ],
            $state
        );
    }

    /**
     * Save the on/off switch. Returns the resolved config after the write.
     *
     * The first enable seeds `next_scheduled_at` so the UI shows a real "Next
     * report" date immediately. The scheduler still re-seeds on its first tick
     * for any other path that flips enable on.
     */
    public function save(array $input): array {
        $this->load_defaults_file();

        $stored = $this->stored() + thinkrank_get_default_email_report_config();

        if (array_key_exists('enabled', $input)) {
            $stored['enabled'] = (bool) filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($stored['enabled'] && empty($stored['next_scheduled_at'])) {
            $next = strtotime('+' . $this->get()['frequency_days'] . ' days');

            $stored['next_scheduled_at'] = wp_date('Y-m-d H:i:s', max($next ?: time(), time()));
        }

        update_option(self::OPTION_KEY, $stored, false);

        $config = $this->get();

        /**
         * Fires after Email Report config is saved.
         *
         * @since 1.9.0
         *
         * @param array $config The resolved config.
         */
        do_action('thinkrank_email_report_settings_saved', $config);

        return $config;
    }

    /**
     * Move the next send after the report's frequency changed.
     *
     * Called by whatever changed the frequency (ThinkRank Pro) with the value
     * it had before. Carrying the old timestamp through meant switching 30 → 7
     * days still waited out the original 30-day window. The new date anchors
     * off the last send when there is one, so shortening the cadence brings the
     * next report forward instead of adding a full period on top of time
     * already elapsed.
     *
     * @param int $previous_frequency_days Frequency before the change.
     * @return array The resolved config.
     */
    public function reschedule(int $previous_frequency_days): array {
        $config = $this->get();

        if (empty($config['enabled']) || $previous_frequency_days === (int) $config['frequency_days']) {
            return $config;
        }

        // last_sent_at is a site-local wall clock (current_time('mysql')).
        // strtotime() would read it as UTC and skew the whole cadence by the
        // site's offset, so resolve it in the site timezone instead.
        $anchor = !empty($config['last_sent_at'])
            ? (int) get_gmt_from_date((string) $config['last_sent_at'], 'U')
            : time();
        $anchor = $anchor ?: time();

        $next = strtotime('+' . (int) $config['frequency_days'] . ' days', $anchor);

        // Never schedule into the past — a big cadence cut on an old
        // last_sent_at means "due now", which the next tick picks up.
        return $this->update_schedule(
            $config['last_sent_at'],
            wp_date('Y-m-d H:i:s', max($next ?: time(), time()))
        );
    }

    /**
     * Note why a scheduled run sent nothing (#742).
     *
     * `search_console_not_connected` leaves the schedule alone so the next
     * hourly tick tries again; `no_data` is recorded by the generator after
     * it has already pushed the schedule out a period. Either way the panel
     * shows the reason and when it was last seen.
     *
     * @param string $reason Machine-readable reason.
     */
    public function record_skip(string $reason): void {
        $stored = $this->stored();
        $stored['last_skip'] = [
            'reason' => sanitize_key($reason),
            'at'     => current_time('mysql'),
        ];
        update_option(self::OPTION_KEY, $stored, false);
    }

    /**
     * A report went out — whatever paused it earlier no longer applies.
     */
    public function clear_skip(): void {
        $stored = $this->stored();
        if (!array_key_exists('last_skip', $stored)) {
            return;
        }
        unset($stored['last_skip']);
        update_option(self::OPTION_KEY, $stored, false);
    }

    /**
     * Update only the schedule timestamps. Called from the scheduler after
     * a send.
     */
    public function update_schedule(?string $last_sent_at, ?string $next_scheduled_at): array {
        $stored = $this->stored();
        $stored['last_sent_at']      = $last_sent_at;
        $stored['next_scheduled_at'] = $next_scheduled_at;
        update_option(self::OPTION_KEY, $stored, false);

        return $this->get();
    }

    /**
     * Normalize a recipient list that might arrive as a string
     * ("a@x.com, b@x.com") or as an array.
     *
     * @param mixed $raw
     * @return string[]
     */
    private function normalize_recipients($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $emails = [];
        foreach ($raw as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = sanitize_email(trim($candidate));
            if ($candidate !== '' && is_email($candidate)) {
                $emails[] = strtolower($candidate);
            }
        }
        return array_values(array_unique($emails));
    }

    /**
     * Keep known section keys, in the order given.
     *
     * @param mixed $raw
     * @return string[]
     */
    private function normalize_section_keys($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = array_unique(array_merge(
            array_keys(thinkrank_get_email_report_default_sections()),
            (array) apply_filters('thinkrank_email_report_section_keys', [])
        ));
        $clean = [];
        foreach ($raw as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = sanitize_key($key);
            if (in_array($key, $allowed, true)) {
                $clean[] = $key;
            }
        }
        return array_values(array_unique($clean));
    }
}
