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

            // Dedupe check for scheduled sends only (tests can repeat).
            if (!$is_test) {
                $dedupe = $this->record_attempt($config, $context);
                if (!$dedupe['inserted']) {
                    return ['success' => false, 'skipped' => 'duplicate'];
                }
            }

            $html = $this->renderer->render($config, $context);

            $tokens = ['%period%' => $context['period_label']];
            $result = $this->mailer->send($config, $html, $tokens);

            if (!$is_test) {
                $this->finalize_log($config, $context, $result);
                $this->config->update_schedule(
                    current_time('mysql'),
                    $this->compute_next_run($frequency)
                );
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
     * Insert a `pending` row in email_report_logs. The unique key on
     * (site_id, period_start, recipient_hash) is the dedupe gate — a
     * conflicting insert returns 0 rows and we abort the send.
     */
    private function record_attempt(array $config, array $context): array {
        global $wpdb;
        $table = $wpdb->prefix . 'thinkrank_email_report_logs';

        $period_start = $context['period_start'] ?: current_time('mysql');
        $period_end   = $context['period_end']   ?: current_time('mysql');
        $recipients   = (array) ($config['recipients'] ?? []);

        // Insert with dbDelta-friendly columns; the unique key handles dedupe.
        $rows = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'site_id'         => get_current_blog_id(),
                'period_start'    => $period_start,
                'period_end'      => $period_end,
                'recipient_hash'  => $this->recipient_hash($recipients),
                'recipient_count' => count($recipients),
                'frequency_days'  => (int) ($config['frequency_days'] ?? 30),
                'status'          => 'pending',
                'created_at'      => current_time('mysql'),
            ],
            ['%d','%s','%s','%s','%d','%d','%s','%s']
        );

        return [
            'inserted' => (bool) $rows,
            'log_id'   => (int) $wpdb->insert_id,
        ];
    }

    /**
     * Update the row inserted by record_attempt() with the send outcome.
     */
    private function finalize_log(array $config, array $context, array $result): void {
        global $wpdb;
        $table = $wpdb->prefix . 'thinkrank_email_report_logs';

        $status = !empty($result['success']) ? 'sent' : 'failed';
        $error = empty($result['success']) ? ($result['error'] ?? __('Unknown send failure.', 'thinkrank')) : null;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'status'        => $status,
                'sent_at'       => current_time('mysql'),
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
}
