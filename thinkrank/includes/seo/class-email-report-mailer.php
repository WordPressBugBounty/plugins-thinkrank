<?php

/**
 * Email Report Mailer
 *
 * Thin wrapper around wp_mail() for the Email Reporting feature.
 *
 * Responsibilities:
 *  - Build From/Reply-To/Content-Type headers
 *  - Resolve subject template tokens
 *  - Truncate recipients to plan-allowed maximum (defense-in-depth)
 *  - Return a structured result with per-recipient outcome
 *
 * Not its job:
 *  - Building HTML (that's Email_Report_Renderer)
 *  - Deciding when to send (that's Email_Report_Scheduler)
 *  - Logging to the email_report_logs table (that's Email_Report_Generator)
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Plan_Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Mailer
 *
 * @since 1.9.0
 */
final class Email_Report_Mailer {

    /**
     * Send a rendered email report.
     *
     * @param array  $config Per-site config (recipients, subject_template).
     * @param string $html   Rendered HTML body.
     * @param array  $tokens Tokens to substitute in subject (date, period, …).
     * @return array{success:bool,recipients:array<string,bool>,subject:string,error?:string}
     */
    public function send(array $config, string $html, array $tokens = []): array {
        $recipients = Plan_Config::clamp_email_report_recipients(
            (array) ($config['recipients'] ?? [])
        );

        if (empty($recipients)) {
            return [
                'success' => false,
                'recipients' => [],
                'subject' => '',
                'error' => __('No valid recipients configured.', 'thinkrank'),
            ];
        }

        $subject = $this->resolve_subject((string) ($config['subject_template'] ?? ''), $tokens);

        /**
         * Filter the rendered HTML one last time before send.
         *
         * Pro plugin uses this to insert tracking pixels, rewrite links,
         * inject AI Highlights summary, etc. Free code never reads this
         * filter — it just provides the seam.
         *
         * @since 1.9.0
         *
         * @param string $html       Rendered HTML.
         * @param array  $config     Per-site config.
         * @param array  $recipients Final recipient list.
         */
        $html = (string) apply_filters('thinkrank_email_report_html', $html, $config, $recipients);

        $headers = $this->build_headers($config);

        // wp_mail accepts an array of recipients but reports a single boolean.
        // Send per-recipient so a single bad address doesn't sink the batch.
        $results = [];
        $any_failed = false;

        foreach ($recipients as $address) {
            $sent = wp_mail($address, $subject, $html, $headers);
            $results[$address] = (bool) $sent;
            if (!$sent) {
                $any_failed = true;
            }
        }

        return [
            'success' => !$any_failed,
            'recipients' => $results,
            'subject' => $subject,
        ];
    }

    /**
     * Resolve %token% substitutions in the subject. Pro can extend the
     * token set via the `thinkrank_email_report_tokens` filter.
     */
    private function resolve_subject(string $template, array $tokens): string {
        if ($template === '') {
            $template = '%site_title% SEO performance report';
        }

        $defaults = [
            '%site_title%' => (string) get_bloginfo('name'),
            '%site_url%'   => (string) home_url(),
            '%date%'       => wp_date(get_option('date_format', 'Y-m-d')),
            '%period%'     => (string) ($tokens['%period%'] ?? ''),
        ];

        $merged = array_merge($defaults, $tokens);

        /**
         * Filter the subject token map.
         *
         * @since 1.9.0
         *
         * @param array  $merged   Token => replacement.
         * @param string $template Subject template, pre-substitution.
         */
        $merged = (array) apply_filters('thinkrank_email_report_subject_tokens', $merged, $template);

        $subject = strtr($template, $merged);

        /**
         * Filter the final subject string after substitution.
         *
         * Pro can apply its own custom template that ignores the free token
         * set entirely.
         *
         * @since 1.9.0
         *
         * @param string $subject  Resolved subject.
         * @param string $template Original template.
         * @param array  $merged   Token map used.
         */
        return (string) apply_filters('thinkrank_email_report_subject', $subject, $template, $merged);
    }

    /**
     * Build wp_mail headers. Uses the WP admin From identity to keep
     * deliverability sane; agencies on Pro typically swap this via the
     * `wp_mail_from`/`wp_mail_from_name` filters elsewhere in their stack.
     *
     * @return string[]
     */
    private function build_headers(array $config): array {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $reply_to = $this->first_recipient($config['recipients'] ?? []);
        if ($reply_to !== '') {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        /**
         * Filter the email headers.
         *
         * @since 1.9.0
         *
         * @param string[] $headers Default headers.
         * @param array    $config  Per-site config.
         */
        $headers = (array) apply_filters('thinkrank_email_report_headers', $headers, $config);

        return array_values(array_filter($headers, 'is_string'));
    }

    private function first_recipient(array $recipients): string {
        foreach ($recipients as $r) {
            if (is_string($r) && is_email($r)) {
                return $r;
            }
        }
        return '';
    }
}
