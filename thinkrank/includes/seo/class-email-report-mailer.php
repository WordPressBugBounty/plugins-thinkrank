<?php

/**
 * Email Report Mailer
 *
 * Thin wrapper around wp_mail() for the Email Reporting feature.
 *
 * Responsibilities:
 *  - Build From/Reply-To/Content-Type headers
 *  - Resolve subject template tokens
 *  - Drop invalid and duplicate recipients
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
     * Subject line when nothing filters it.
     *
     * `%headline%` is the report's own summary — "12,480 Google clicks
     * (+12.4%) in the last 30 days" — supplied by the generator (#742). A
     * subject that carries the number gets opened; a label does not.
     */
    public const DEFAULT_SUBJECT = '%site_title%: %headline%';

    /**
     * Send a rendered email report.
     *
     * @param array  $config Resolved config (recipients).
     * @param string $html   Rendered HTML body.
     * @param array  $tokens Tokens to substitute in subject (date, period, …).
     * @return array{success:bool,recipients:array<string,bool>,subject:string,error?:string}
     */
    public function send(array $config, string $html, array $tokens = []): array {
        $recipients = $this->valid_recipients((array) ($config['recipients'] ?? []));

        if (empty($recipients)) {
            return [
                'success' => false,
                'recipients' => [],
                'subject' => '',
                'error' => __('No valid recipients configured.', 'thinkrank'),
            ];
        }

        /**
         * Filter the subject template before token substitution.
         *
         * ThinkRank Pro returns its custom subject line here.
         *
         * @since 2.6.0
         *
         * @param string $template Subject template.
         * @param array  $config   Resolved config.
         */
        $template = (string) apply_filters('thinkrank_email_report_subject_template', self::DEFAULT_SUBJECT, $config);

        $subject = $this->resolve_subject($template, $tokens);

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
            $template = self::DEFAULT_SUBJECT;
        }

        $defaults = [
            '%site_title%'    => (string) get_bloginfo('name'),
            '%site_url%'      => (string) home_url(),
            '%date%'          => wp_date(get_option('date_format', 'Y-m-d')),
            '%period%'        => (string) ($tokens['%period%'] ?? ''),
            '%headline%'      => __('SEO performance report', 'thinkrank'),
            '%clicks%'        => '',
            '%clicks_change%' => '',
            '%impressions%'   => '',
            '%period_days%'   => '',
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

    /**
     * Unique, valid addresses, in the order given.
     *
     * @param array $recipients Candidate addresses.
     * @return string[]
     */
    private function valid_recipients(array $recipients): array {
        $valid = array_filter(
            array_map(static fn ($r) => is_string($r) ? trim($r) : '', $recipients),
            static fn (string $r): bool => $r !== '' && (bool) is_email($r)
        );

        return array_values(array_unique($valid));
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
