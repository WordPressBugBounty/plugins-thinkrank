<?php
/**
 * Email Report Renderer
 *
 * Walks the registered sections, calls collect() then render() on each,
 * wraps each result in a card and assembles the final HTML using
 * templates/email-report/email.html.php.
 *
 * Failure model: a section that throws, collects nothing or renders to
 * nothing is dropped — the card is simply absent — and the rest of the
 * report keeps rendering. A section may still supply fallback_html(); when
 * it is non-empty it is shown in the section's card, which keeps sections
 * written against 1.9 working unchanged (#742).
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\SEO\Email_Report_Sections\Email_Report_Html;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Renderer
 *
 * @since 1.9.0
 */
final class Email_Report_Renderer {

    private Email_Report_Section_Registry $registry;

    /**
     * How many sections produced real data during the last render().
     *
     * A section that returns an empty payload (or renders to nothing) is
     * dropped. That outcome is only known *inside* render_one_section(),
     * so it is counted here for the caller to read back after rendering —
     * see sections_with_data().
     */
    private int $sections_with_data = 0;

    public function __construct(Email_Report_Section_Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Render the full HTML for a report.
     *
     * @param array $config  Per-site config.
     * @param array $context {
     *     @type string $period_start   ISO datetime.
     *     @type string $period_end     ISO datetime.
     *     @type string $period_label   Human-readable, e.g. "May 1 – May 30, 2026".
     *     @type bool   $is_test        True when called from "Send Test Email".
     *     @type bool   $not_connected  True to render the "connect Search Console"
     *                                  state instead of the sections.
     *     @type array  $readiness      Data_Provider::readiness() result.
     * }
     */
    public function render(array $config, array $context): string {
        // Reset before walking the sections so sections_with_data() always
        // describes this render and never a previous one.
        $this->sections_with_data = 0;

        $shared_context = array_merge([
            'site_url' => (string) home_url(),
            'frequency_days' => (int) ($config['frequency_days'] ?? 30),
            'period_start' => '',
            'period_end' => '',
            'period_label' => '',
            'is_test' => false,
            'not_connected' => false,
            'readiness' => [],
            'shared' => [],
        ], $context);

        $sections_html = !empty($shared_context['not_connected'])
            ? $this->render_not_connected($shared_context)
            : $this->render_sections($config, $shared_context);

        $frequency_days = (int) $shared_context['frequency_days'];

        $payload = [
            'config'          => $config,
            'context'         => $shared_context,
            'sections_html'   => $sections_html,
            'header_logo'     => $this->default_logo(),
            'header_bg'       => '',
            'logo_link'       => '',
            'intro_text'      => '',
            'footer_text'     => $this->default_footer($config),
            'additional_css'  => '',
            'cta_url'         => $this->dashboard_url(),
            'settings_url'    => Email_Report_Html::admin_link('analytics', 'email-reporting'),
            'site_title'      => (string) get_bloginfo('name'),
            'site_url'        => (string) home_url(),
            'frequency_days'  => $frequency_days,
            'frequency_label' => $this->frequency_label($frequency_days),
        ];

        /**
         * Filter the assembled payload before the layout template runs.
         *
         * The layout's presentation slots — header logo, logo link, header
         * background, intro, footer, extra CSS and the dashboard link — are
         * filled here. ThinkRank Pro fills them from its branding settings.
         *
         * @since 1.9.0
         *
         * @param array $payload
         * @param array $config
         * @param array $shared_context
         */
        $payload = (array) apply_filters('thinkrank_email_report_payload', $payload, $config, $shared_context);

        // Tokens resolve after the filter, so text supplied through it gets
        // the same %site_title% / %period% substitution as the defaults.
        $payload['intro_text']  = $this->apply_text_tokens((string) ($payload['intro_text'] ?? ''), $shared_context);
        $payload['footer_text'] = $this->apply_text_tokens((string) ($payload['footer_text'] ?? ''), $shared_context);

        $layout = $this->locate_layout();
        if (!is_readable($layout)) {
            return $this->emergency_fallback_html($payload);
        }

        ob_start();
        // The layout file expects $payload in scope.
        include $layout;
        return (string) ob_get_clean();
    }

    /**
     * Whether this config resolves to at least one section.
     *
     * A report with everything switched off still produced a valid email —
     * header, footer, and nothing in between — and reported it as a
     * successful send. Callers use this to skip the send instead.
     *
     * This is a *configuration* check: it answers "is anything enabled?",
     * not "did anything have data?". A section that is enabled but returns
     * nothing still counts here, because emptiness is only discovered later,
     * during render(). For the data question use sections_with_data() after
     * rendering.
     */
    public function has_renderable_sections(array $config): bool {
        return $this->registry->resolve_for($config) !== [];
    }

    /**
     * Walk the resolved sections, render each, return concatenated HTML.
     */
    private function render_sections(array $config, array $context): string {
        $sections = $this->registry->resolve_for($config);
        $html = '';

        foreach ($sections as $section) {
            $section_html = $this->render_one_section($section, $context);
            if ($section_html === '') {
                continue;
            }
            $html .= $this->wrap_section($section, $section_html);
        }

        return $html;
    }

    private function render_one_section($section, array $context): string {
        try {
            $payload = $section->collect($context);
            if (empty($payload)) {
                return $this->fallback_for($section);
            }
            $rendered = $section->render($payload);
            if ($rendered === '') {
                return $this->fallback_for($section);
            }

            // A non-empty payload is not the same as data. Key Metrics
            // collects whenever the provider reports itself "available",
            // which it does even with no Search Console connection — and
            // then renders a row of zeroes. That is a placeholder, not a
            // report, so it must not make an empty send look non-empty.
            if (!$this->payload_has_data($section, $payload)) {
                return $this->fallback_for($section);
            }

            $this->sections_with_data++;

            return $rendered;
        } catch (Throwable $e) {
            // Don't let one section break the report.
            return $this->fallback_for($section);
        }
    }

    /**
     * What a section shows when it has nothing: its fallback_html(), which
     * the built-in sections leave empty so their card is dropped, and a
     * section written for 1.9 may still fill with a notice.
     */
    private function fallback_for($section): string {
        try {
            return (string) $section->fallback_html();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Whether a section's payload carries anything worth reporting.
     *
     * A section may override the default judgement by implementing
     * `has_data(array $payload): bool` — the interface does not require it,
     * so Pro sections with their own notion of emptiness can opt in without
     * every existing section having to change.
     *
     * The default is a structural read: any non-zero number, any non-empty
     * string, any true flag counts. An all-zero payload — six metrics at 0,
     * no keyword rows — does not. Sections that already return an empty
     * payload when they have nothing never reach this check.
     *
     * @param object $section
     */
    private function payload_has_data($section, array $payload): bool {
        if (method_exists($section, 'has_data')) {
            return (bool) $section->has_data($payload);
        }

        return $this->array_has_value($payload);
    }

    private function array_has_value(array $values): bool {
        foreach ($values as $value) {
            if (is_array($value)) {
                if ($this->array_has_value($value)) {
                    return true;
                }
                continue;
            }
            if (is_bool($value)) {
                if ($value) {
                    return true;
                }
                continue;
            }
            if (is_int($value) || is_float($value)) {
                if (abs((float) $value) > 0.0) {
                    return true;
                }
                continue;
            }
            if (is_string($value)) {
                if (trim($value) !== '') {
                    return true;
                }
                continue;
            }
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many sections produced real data in the most recent render().
     *
     * Zero means every section was dropped, so the report is a header and a
     * footer with nothing between. Callers use this to decide whether that
     * is worth sending.
     */
    public function sections_with_data(): int {
        return $this->sections_with_data;
    }

    /**
     * One white card. A section that draws its own heading (the built-in
     * ones, via renders_own_heading()) gets the bare shell; any other gets
     * its label as the card title, so a section written for 1.9 still reads
     * as one card among the rest.
     */
    private function wrap_section($section, string $body_html): string {
        $heading = '';
        $own = method_exists($section, 'renders_own_heading') && $section->renders_own_heading();
        if (!$own) {
            $heading = Email_Report_Html::heading((string) $section->label()) . '<div style="height:14px;line-height:14px;font-size:0;">&nbsp;</div>';
        }

        return self::card($heading . $body_html, 'tr-email-section tr-email-section-' . sanitize_html_class((string) $section->key()));
    }

    /**
     * The card shell used by sections, the connect state and the layout.
     */
    public static function card(string $inner_html, string $css_class = 'tr-email-section', string $padding = '24px 28px'): string {
        return '<tr><td class="' . esc_attr($css_class) . '" style="padding:0 0 16px 0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid ' . Email_Report_Html::LINE . ';border-radius:14px;">'
            . '<tr><td style="padding:' . esc_attr($padding) . ';">' . $inner_html . '</td></tr>'
            . '</table></td></tr>';
    }

    /**
     * The one-card email a test send delivers while Search Console is not
     * connected: what the report will contain, how to connect, and that
     * scheduled reports are paused until then (#742).
     */
    private function render_not_connected(array $context): string {
        $font       = Email_Report_Html::FONT;
        $site_title = (string) get_bloginfo('name');
        $connect    = Email_Report_Html::admin_link('integrations', 'google-services');
        $settings   = Email_Report_Html::admin_link('analytics', 'email-reporting');

        $intro = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td width="56" valign="top"><img src="' . esc_url(Email_Report_Html::asset('icon-connect.png')) . '" width="52" height="52" alt="" style="display:block;border:0;border-radius:26px;" /></td>'
            . '<td style="padding-left:16px;' . $font . '">'
            . '<div style="font-size:20px;font-weight:800;color:' . Email_Report_Html::INK . ';letter-spacing:-0.01em;line-height:1.3;">'
            . esc_html__('Connect Google Search Console to get your first report', 'thinkrank') . '</div>'
            . '<div style="font-size:14px;color:' . Email_Report_Html::MUTED . ';line-height:1.6;margin-top:8px;">'
            . esc_html(sprintf(
                /* translators: %s: site title. */
                __('This report is built from Search Console data: clicks, impressions, growing pages and queries, and where your keywords rank. %s isn’t connected yet, so there is nothing to report.', 'thinkrank'),
                $site_title
            )) . '</div>'
            . '<div style="font-size:14px;color:' . Email_Report_Html::MUTED . ';line-height:1.6;margin-top:8px;">'
            . '<strong style="color:' . Email_Report_Html::INK . ';">' . esc_html__('Scheduled reports are paused', 'thinkrank') . '</strong> '
            . esc_html__('until Search Console is connected. Nothing will be sent to your recipients in the meantime.', 'thinkrank') . '</div>'
            . '<div style="margin-top:18px;">'
            . '<a href="' . esc_url($connect) . '" style="display:inline-block;background:' . Email_Report_Html::PRIMARY . ';color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:12px 22px;border-radius:10px;">'
            . esc_html__('Connect Search Console', 'thinkrank') . '</a>'
            . '&nbsp;&nbsp;<a href="' . esc_url($settings) . '" style="font-size:14px;font-weight:600;color:' . Email_Report_Html::PRIMARY . ';text-decoration:none;">'
            . esc_html__('Report settings', 'thinkrank') . ' ›</a></div>'
            . '</td></tr></table>';

        $items = [
            __('Clicks, impressions, CTR and average position, with the change vs the previous period', 'thinkrank'),
            __('Top growing pages and queries, and the ones losing ground', 'thinkrank'),
            __('Where your keywords rank: top 3, page one, page two and beyond', 'thinkrank'),
            __('Site traffic from Google Analytics 4 and visits from AI assistants, when those are connected', 'thinkrank'),
        ];
        $list = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;' . $font . 'font-size:14px;color:' . Email_Report_Html::INK . ';line-height:1.6;">';
        foreach ($items as $item) {
            $list .= '<tr><td width="22" valign="top" style="padding:6px 0;color:' . Email_Report_Html::UP . ';font-weight:700;">✓</td>'
                . '<td style="padding:6px 0;">' . esc_html($item) . '</td></tr>';
        }
        $list .= '</table>';

        return self::card($intro, 'tr-email-section tr-email-connect', '28px')
            . self::card(Email_Report_Html::heading(__('What you’ll get once connected', 'thinkrank')) . $list, 'tr-email-section tr-email-connect-list');
    }

    private function default_logo(): string {
        /**
         * Filter the default email logo URL.
         *
         * The bundled ThinkRank lockup by default (#742). Pro's branding
         * logo replaces it through the payload filter, not here.
         *
         * @since 1.9.0
         *
         * @param string $default Default logo URL.
         */
        return (string) apply_filters('thinkrank_email_report_default_logo', Email_Report_Html::asset('thinkrank-logo.png'));
    }

    private function dashboard_url(): string {
        return Email_Report_Html::admin_link('analytics', 'dashboard');
    }

    /**
     * "Monthly SEO report" / "Weekly SEO report" / "SEO report every N days"
     * for the header's right-hand label.
     */
    private function frequency_label(int $days): string {
        if ($days === 1) {
            return __('Daily SEO report', 'thinkrank');
        }
        if ($days === 7) {
            return __('Weekly SEO report', 'thinkrank');
        }
        if ($days >= 28 && $days <= 31) {
            return __('Monthly SEO report', 'thinkrank');
        }
        return sprintf(
            /* translators: %d: number of days between reports. */
            _n('SEO report every %d day', 'SEO report every %d days', $days, 'thinkrank'),
            $days
        );
    }

    /**
     * Substitute %site_title%, %site_url%, %date% and %period%
     * in free-text fields like the intro and footer. Previously only the subject
     * line ran token substitution, so these tokens rendered literally in the body.
     *
     * @param string $text           Raw text, possibly containing %tokens%.
     * @param array  $shared_context Render context (supplies the period label).
     * @return string Text with tokens replaced.
     */
    private function apply_text_tokens(string $text, array $shared_context): string {
        if ($text === '' || strpos($text, '%') === false) {
            return $text;
        }

        $tokens = [
            '%site_title%' => (string) get_bloginfo('name'),
            '%site_url%'   => (string) home_url(),
            '%date%'       => wp_date(get_option('date_format', 'Y-m-d')),
            '%period%'     => (string) ($shared_context['period_label'] ?? ''),
        ];

        /**
         * Filter the token map used for email report free-text fields.
         *
         * Mirrors the subject-line token filter so Pro can register extra
         * tokens (e.g. %client_name%) that resolve everywhere.
         *
         * @since 1.16.0
         *
         * @param array $tokens Token => replacement.
         * @param array $shared_context Render context.
         */
        $tokens = (array) apply_filters('thinkrank_email_report_tokens', $tokens, $shared_context);

        return strtr($text, $tokens);
    }

    /**
     * "Sent by ThinkRank for example.com · every 30 days to a@x.com, b@x.com"
     */
    private function default_footer(array $config): string {
        $days       = max(1, (int) ($config['frequency_days'] ?? 30));
        $recipients = array_values(array_filter((array) ($config['recipients'] ?? []), 'is_string'));
        $host       = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);

        $line = sprintf(
            /* translators: %s: site host name. */
            __('Sent by ThinkRank for %s', 'thinkrank'),
            $host !== '' ? $host : (string) get_bloginfo('name')
        );
        $cadence = $days === 1
            ? __('every day', 'thinkrank')
            : sprintf(
                /* translators: %d: number of days between reports. */
                _n('every %d day', 'every %d days', $days, 'thinkrank'),
                $days
            );
        if ($recipients !== []) {
            $line .= ' · ' . sprintf(
                /* translators: 1: cadence ("every 30 days"), 2: recipient list. */
                __('%1$s to %2$s', 'thinkrank'),
                $cadence,
                implode(', ', array_slice($recipients, 0, 3)) . (count($recipients) > 3 ? '…' : '')
            );
        } else {
            $line .= ' · ' . $cadence;
        }

        return esc_html($line);
    }

    private function locate_layout(): string {
        return THINKRANK_PLUGIN_DIR . 'templates/email-report/email.html.php';
    }

    /**
     * If the layout template is missing for some reason, render a minimal
     * but still useful HTML email. Better than a blank message — the user
     * still gets the data they care about.
     */
    private function emergency_fallback_html(array $payload): string {
        $title = esc_html($payload['site_title'] ?? '');
        $sections = $payload['sections_html'] ?? '';
        return '<!doctype html><html><body style="' . Email_Report_Html::FONT . 'background:' . Email_Report_Html::CANVAS . ';padding:24px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;">'
            . '<tr><td style="padding:0 0 16px 0;"><h1 style="margin:0;font-size:22px;color:' . Email_Report_Html::INK . ';">' . $title . '</h1></td></tr>'
            . $sections
            . '</table></body></html>';
    }
}
