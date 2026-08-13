<?php
/**
 * Email Report Generator
 *
 * Orchestrates one report end-to-end: fetch data, run the section
 * pipeline through the renderer, send via the mailer, and log to
 * email_report_logs (insert-with-unique-key dedupe).
 *
 * Two entry points:
 *  - generate_for_due()  : called by the scheduler when a site's next_scheduled_at is past
 *  - generate_test()     : called by the REST endpoint's "Send Test Email" button
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Generator
 *
 * @since 1.9.0
 */
final class Email_Report_Generator {

    /**
     * How long to wait before re-attempting a send that failed. Short
     * enough that a transient SMTP problem doesn't cost the user a whole
     * reporting period, long enough not to hammer a broken relay.
     */
    private const RETRY_DELAY_HOURS = 6;

    /**
     * Total send attempts per reporting period, including the first. Once
     * spent, the schedule falls back to the normal cadence so a permanently
     * misconfigured mailer doesn't retry forever.
     */
    private const MAX_SEND_ATTEMPTS = 3;

    private Email_Report_Config $config;
    private Email_Report_Renderer $renderer;
    private Email_Report_Mailer $mailer;
    private Email_Report_Data_Provider $data_provider;

    public function __construct(
        Email_Report_Config $config,
        Email_Report_Renderer $renderer,
        Email_Report_Mailer $mailer,
        Email_Report_Data_Provider $data_provider
    ) {
        $this->config = $config;
        $this->renderer = $renderer;
        $this->mailer = $mailer;
        $this->data_provider = $data_provider;
    }

    /**
     * Run the scheduled-send path.
     *
     * @return array{success:bool,skipped?:string,result?:array,error?:string}
     */
    public function generate_for_due(): array {
        $config = $this->config->get();

        if (empty($config['enabled'])) {
            return ['success' => false, 'skipped' => 'disabled'];
        }
        if (empty($config['recipients'])) {
            return ['success' => false, 'skipped' => 'no_recipients'];
        }

        return $this->run($config, false);
    }

    /**
     * Run the immediate "send test" path. Bypasses the schedule check
     * and the dedupe log insert (a test isn't a real period send).
     */
    public function generate_test(): array {
        $config = $this->config->get();
        if (empty($config['recipients'])) {
            return ['success' => false, 'error' => __('No recipients configured.', 'thinkrank')];
        }
        return $this->run($config, true);
    }

    /**
     * Common send pipeline.
     */
    private function run(array $config, bool $is_test): array {
        $frequency = (int) ($config['frequency_days'] ?? 30);

        try {
            $shared = $this->data_provider->fetch($frequency);

            $context = [
                'period_start' => $shared['period_start'] ?? '',
                'period_end'   => $shared['period_end']   ?? '',
                'period_label' => $shared['period_label'] ?? '',
                'is_test'      => $is_test,
                'shared'       => $shared,
            ];

            /**
             * Fires before the report is rendered/sent. Pro plugin uses
             * this to fetch + attach the AI Highlights summary.
             *
             * @since 1.9.0
             *
             * @param array $config  Per-site config.
             * @param array $context Render context with shared data.
             */
            do_action('thinkrank_email_report_before_generate', $config, $context);

            // Nothing to report. Checked after the hook above so a section
            // Pro registers there still counts. An email with a header, a
            // footer and nothing between them isn't a successful send.
            if (!$this->renderer->has_renderable_sections($config)) {
                if (!$is_test) {
                    // Don't re-evaluate this every hour — wait out a period.
                    $this->config->update_schedule(
                        $config['last_sent_at'] ?? null,
                        $this->compute_next_run($frequency)
                    );
                }
                return ['success' => false, 'skipped' => 'no_sections'];
            }

            // Dedupe check for scheduled sends only (tests can repeat).
            if (!$is_test) {
                $dedupe = $this->record_attempt($config, $context);
                if (!$dedupe['inserted']) {
                    // Out of retries for this period: stop re-attempting and
                    // rejoin the normal cadence rather than ticking forever.
                    if (!empty($dedupe['exhausted'])) {
                        $this->config->update_schedule(
                            $this->config->get()['last_sent_at'] ?? null,
                            $this->compute_next_run($frequency)
                        );
                        return ['success' => false, 'skipped' => 'retry_limit'];
                    }
                    return [
                        'success' => false,
                        'skipped' => empty($dedupe['write_failed']) ? 'duplicate' : 'log_write_failed',
                    ];
                }
            }

            $html = $this->renderer->render($config, $context);

            $tokens = ['%period%' => $context['period_label']];
            $result = $this->mailer->send($config, $html, $tokens);

            if (!$is_test) {
                $this->finalize_log($config, $context, $result);

                if (!empty($result['success'])) {
                    $this->config->update_schedule(
                        current_time('mysql'),
                        $this->compute_next_run($frequency)
                    );
                } else {
                    // A transient mail failure must not cost the user a whole
                    // period, and it must not stamp last_sent_at with a send
                    // that never happened. Retry soon; give up after
                    // MAX_SEND_ATTEMPTS and fall back to the normal cadence.
                    $attempts = (int) ($dedupe['attempts'] ?? 1);
                    $next = $attempts >= self::MAX_SEND_ATTEMPTS
                        ? $this->compute_next_run($frequency)
                        : $this->compute_retry_run();

                    $this->config->update_schedule(
                        $this->config->get()['last_sent_at'] ?? null,
                        $next
                    );
                }
            }

            /**
             * Fires after a report has been sent (or has failed).
             *
             * @since 1.9.0
             *
             * @param array $config
             * @param array $result
             * @param bool  $is_test
             */
            do_action('thinkrank_email_report_after_send', $config, $result, $is_test);

            return ['success' => (bool) ($result['success'] ?? false), 'result' => $result];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Claim this period's send by inserting a `pending` row in
     * email_report_logs. The unique key on (site_id, period_start,
     * recipient_hash) is the dedupe gate — a conflicting insert means the
     * period is already accounted for.
     *
     * "Already accounted for" is not always "already delivered", though: a
     * previous attempt may have failed. In that case we re-claim the same
     * row for another attempt, up to MAX_SEND_ATTEMPTS, so the retry the
     * scheduler booked can actually run.
     *
     * @return array{inserted:bool,log_id:int,attempts:int,retry?:bool,exhausted?:bool,write_failed?:bool}
     */
    private function record_attempt(array $config, array $context): array {
        global $wpdb;
        $table = $wpdb->prefix . 'thinkrank_email_report_logs';

        $period_start = $context['period_start'] ?: current_time('mysql');
        $period_end   = $context['period_end']   ?: current_time('mysql');
        $recipients   = (array) ($config['recipients'] ?? []);
        $hash         = $this->recipient_hash($recipients);
        $site_id      = get_current_blog_id();

        // The UNIQUE KEY on (site_id, period_start, recipient_hash) is the
        // dedupe gate, so a colliding insert is an expected outcome on a
        // normal tick — not an error. Suppress $wpdb's own error handling
        // for the duration so a routine dedupe doesn't dump SQL and a stack
        // trace into the log (or, under WP_DEBUG_DISPLAY, into cron output).
        $suppressed = $wpdb->suppress_errors(true);
        $rows = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'site_id'         => $site_id,
                'period_start'    => $period_start,
                'period_end'      => $period_end,
                'recipient_hash'  => $hash,
                'recipient_count' => count($recipients),
                'frequency_days'  => (int) ($config['frequency_days'] ?? 30),
                'status'          => 'pending',
                'attempts'        => 1,
                'created_at'      => current_time('mysql'),
            ],
            ['%d','%s','%s','%s','%d','%d','%s','%d','%s']
        );
        $last_error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ($rows) {
            return [
                'inserted' => true,
                'log_id'   => (int) $wpdb->insert_id,
                'attempts' => 1,
            ];
        }

        // A failed insert is only a dedupe signal when it failed *because of
        // the unique key*. Anything else — missing table, wrong schema, disk
        // full — must not masquerade as "already sent this period", or every
        // scheduled send would be silently skipped forever with no alert.
        if (stripos($last_error, 'duplicate entry') === false) {
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'ThinkRank email report: could not write the send log — '
                . ($last_error !== '' ? $last_error : 'insert failed with no error reported.')
            );
            return ['inserted' => false, 'log_id' => 0, 'attempts' => 0, 'write_failed' => true];
        }

        return $this->claim_retry($table, $site_id, $period_start, $hash);
    }

    /**
     * A row already exists for this period + recipient set. Decide whether
     * it represents a completed send (skip) or a failed one we may retry.
     *
     * @return array{inserted:bool,log_id:int,attempts:int,retry?:bool,exhausted?:bool}
     */
    private function claim_retry(string $table, int $site_id, string $period_start, string $hash): array {
        global $wpdb;

        $existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
                "SELECT id, status, attempts FROM {$table} WHERE site_id = %d AND period_start = %s AND recipient_hash = %s",
                $site_id,
                $period_start,
                $hash
            ),
            ARRAY_A
        );

        // No row behind the failed insert — the write itself broke, not a
        // dedupe collision. Treat as "don't send" and leave it to the caller.
        if (!is_array($existing)) {
            return ['inserted' => false, 'log_id' => 0, 'attempts' => 0];
        }

        // Anything that isn't a recorded failure means this period is done
        // (or in flight elsewhere) — the original dedupe behaviour.
        if (($existing['status'] ?? '') !== 'failed') {
            return ['inserted' => false, 'log_id' => (int) $existing['id'], 'attempts' => (int) $existing['attempts']];
        }

        $attempts = (int) ($existing['attempts'] ?? 1);
        if ($attempts >= self::MAX_SEND_ATTEMPTS) {
            return [
                'inserted'  => false,
                'log_id'    => (int) $existing['id'],
                'attempts'  => $attempts,
                'exhausted' => true,
            ];
        }

        $attempts++;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'status'        => 'pending',
                'attempts'      => $attempts,
                'error_message' => null,
            ],
            ['id' => (int) $existing['id']],
            ['%s','%d','%s'],
            ['%d']
        );

        return [
            'inserted' => true,
            'log_id'   => (int) $existing['id'],
            'attempts' => $attempts,
            'retry'    => true,
        ];
    }

    /**
     * Update the row inserted by record_attempt() with the send outcome.
     */
    private function finalize_log(array $config, array $context, array $result): void {
        global $wpdb;
        $table = $wpdb->prefix . 'thinkrank_email_report_logs';

        $success = !empty($result['success']);
        $status  = $success ? 'sent' : 'failed';
        $error   = $success ? null : ($result['error'] ?? __('Unknown send failure.', 'thinkrank'));

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'status' => $status,
                // Only a real send has a send time. wpdb writes a literal
                // NULL for a null value, which is what a failed row wants.
                'sent_at'       => $success ? current_time('mysql') : null,
                'error_message' => $error,
            ],
            [
                'site_id'        => get_current_blog_id(),
                'period_start'   => $context['period_start'] ?: current_time('mysql'),
                'recipient_hash' => $this->recipient_hash((array) $config['recipients']),
            ],
            ['%s','%s','%s'],
            ['%d','%s','%s']
        );
    }

    /**
     * Stable hash of the recipient list. Lowercased + sorted so reordering
     * doesn't bypass dedupe.
     */
    private function recipient_hash(array $recipients): string {
        $normalized = array_values(array_unique(array_map('strtolower', array_map('trim', $recipients))));
        sort($normalized);
        return hash('sha256', implode(',', $normalized));
    }

    private function compute_next_run(int $frequency_days): string {
        $frequency_days = max(1, $frequency_days);
        return wp_date('Y-m-d H:i:s', strtotime('+' . $frequency_days . ' days'));
    }

    private function compute_retry_run(): string {
        return wp_date('Y-m-d H:i:s', strtotime('+' . self::RETRY_DELAY_HOURS . ' hours'));
    }
}
