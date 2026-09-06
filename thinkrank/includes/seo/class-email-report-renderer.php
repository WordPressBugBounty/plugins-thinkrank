<?php
/**
 * Email Report Renderer
 *
 * Walks the registered sections, calls collect() then render() on each,
 * and assembles the final HTML using templates/email-report/email.html.php.
 *
 * Failure model: any single section that throws or returns empty drops to
 * its fallback_html() — the whole report keeps rendering. Per PRD's
 * "graceful degradation" acceptance criterion.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Plan_Config;
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
     * A section that returns an empty payload (or renders to nothing) drops
     * to its fallback_html() notice. That outcome is only known *inside*
     * render_one_section(), so it is counted here for the caller to read
     * back after rendering — see sections_with_data().
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
     *     @type string $period_start  ISO datetime.
     *     @type string $period_end    ISO datetime.
     *     @type string $period_label  Human-readable, e.g. "May 1 – May 30, 2026".
     *     @type bool   $is_test       True when called from "Send Test Email".
     * }
     */
    public function render(array $config, array $context): string {
        // Reset before walking the sections so sections_with_data() always
        // describes this render and never a previous one.
        $this->sections_with_data = 0;

        $caps = Plan_Config::email_report();

        $shared_context = array_merge([
            'site_url' => (string) home_url(),
            'frequency_days' => (int) ($config['frequency_days'] ?? 30),
            'period_start' => '',
            'period_end' => '',
            'period_label' => '',
            'is_test' => false,
            'shared' => [],
        ], $context);

        $sections_html = $this->render_sections($config, $shared_context);

        $intro_text  = empty($caps['intro_text']) ? '' : (string) ($config['intro_text'] ?? '');
        $footer_text = empty($caps['footer_text']) ? $this->default_footer() : (string) ($config['footer_text'] ?? $this->default_footer());

        $payload = [
            'config'         => $config,
            'caps'           => $caps,
            'context'        => $shared_context,
            'sections_html'  => $sections_html,
            'header_logo'    => $this->resolve_logo($config, $caps),
            'header_bg'      => empty($caps['header_background']) ? '' : (string) ($config['header_background'] ?? ''),
            'logo_link'      => empty($caps['logo_link']) ? '' : (string) ($config['logo_link'] ?? ''),
            'intro_text'     => $this->apply_text_tokens($intro_text, $shared_context),
            'footer_text'    => $this->apply_text_tokens($footer_text, $shared_context),
            'additional_css' => empty($caps['additional_css']) ? '' : (string) ($config['additional_css'] ?? ''),
            'cta_url'        => $this->resolve_cta_url($config, $caps),
            'site_title'     => (string) get_bloginfo('name'),
        ];

        /**
         * Filter the assembled payload before the layout template runs.
         *
         * Pro can rewrite logo/header/footer here without touching the renderer.
         *
         * @since 1.9.0
         *
         * @param array $payload
         * @param array $config
         * @param array $shared_context
         */
        $payload = (array) apply_filters('thinkrank_email_report_payload', $payload, $config, $shared_context);

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
            $html .= $this->wrap_section($section, $section_html);
        }

        return $html;
    }

    private function render_one_section($section, array $context): string {
        try {
            $payload = $section->collect($context);
            if (empty($payload)) {
                return $section->fallback_html();
            }
            $rendered = $section->render($payload);
            if ($rendered === '') {
                return $section->fallback_html();
            }

            // A non-empty payload is not the same as data. Key Metrics
            // collects whenever the provider reports itself "available",
            // which it does even with no Search Console connection — and
            // then renders a row of zeroes. That is a placeholder, not a
            // report, so it must not make an empty send look non-empty.
            if ($this->payload_has_data($section, $payload)) {
                $this->sections_with_data++;
            }

            return $rendered;
        } catch (Throwable $e) {
            // Don't let one section break the report.
            return $section->fallback_html();
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
     * Zero means every section fell back to its "no data" notice, so the
     * report is a header, a footer and a column of placeholders. Callers
     * use this to decide whether that is worth sending.
     */
    public function sections_with_data(): int {
        return $this->sections_with_data;
    }

    private function wrap_section($section, string $body_html): string {
        $heading = esc_html($section->label());
        return '<section class="tr-email-section" style="margin:0 0 24px 0;">'
            . '<h2 style="font:600 18px/1.3 -apple-system,Segoe UI,Roboto,sans-serif;margin:0 0 12px 0;color:#111827;">'
            . $heading
            . '</h2>'
            . $body_html
            . '</section>';
    }

    private function resolve_logo(array $config, array $caps): string {
        if (!empty($caps['custom_logo']) && !empty($config['logo_url'])) {
            return (string) $config['logo_url'];
        }
        // No bundled default logo asset yet — return empty so the layout
        // falls back to a text-rendered site title in the header. Pro
        // (or a follow-up) can ship a real PNG and wire it via this filter.
        /**
         * Filter the default email logo URL.
         *
         * @since 1.9.0
         *
         * @param string $default Default logo URL — empty by default.
         */
        return (string) apply_filters('thinkrank_email_report_default_logo', '');
    }

    private function resolve_cta_url(array $config, array $caps): string {
        if (empty($config['link_to_full_report'])) {
            return '';
        }
        // The dashboard analytics view URL — admin-side. The recipient must
        // be logged in to see it, but the link still gives them a clear path.
        return (string) admin_url('admin.php?page=thinkrank-essential-seo#analytics');
    }

    /**
     * Substitute the documented %tokens% (see thinkrank_get_email_report_tokens)
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

    private function default_footer(): string {
        return sprintf(
            /* translators: %s: site title */
            esc_html__('This report was generated by ThinkRank for %s.', 'thinkrank'),
            esc_html((string) get_bloginfo('name'))
        );
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
        return '<!doctype html><html><body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f4f5;padding:24px;">'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;padding:24px;border-radius:8px;">'
            . '<h1 style="margin:0 0 16px 0;font-size:22px;color:#111827;">' . $title . '</h1>'
            . $sections
            . '</div></body></html>';
    }
}
