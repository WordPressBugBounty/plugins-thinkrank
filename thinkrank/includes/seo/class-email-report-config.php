<?php
/**
 * Email Report Config
 *
 * Persistence layer for the per-site Email Reporting settings. Stores a
 * single associative array under the `thinkrank_email_report_config` option
 * — one row per site is enough; we don't shard by user. Values are
 * sanitized at the boundary and capability-clamped via Plan_Config so a
 * free plan never accidentally persists Pro values that don't belong.
 *
 * Pro plugin can extend the saved schema by hooking
 * `thinkrank_email_report_config_schema` (added fields are sanitized
 * if a callback is provided).
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
 * Email_Report_Config
 *
 * @since 1.9.0
 */
final class Email_Report_Config {

    private const OPTION_KEY = 'thinkrank_email_report_config';

    /**
     * Load defaults helper. Lazy-loads the config defaults file because
     * it lives outside the autoloader path (it's procedural functions).
     */
    private function defaults(): array {
        if (!function_exists('thinkrank_get_default_email_report_config')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/config/email-report-settings-config.php';
        }
        return thinkrank_get_default_email_report_config();
    }

    /**
     * Read the current config. Always merges over defaults so newly added
     * keys (e.g. after a plugin update) are populated even on existing sites.
     */
    public function get(): array {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge($this->defaults(), $stored);
    }

    /**
     * Save the config. Returns the post-sanitize array that was persisted
     * so callers can echo it back to the client and avoid a second read.
     *
     * Sanitization happens here, not in the REST args layer — the REST
     * layer accepts intent, this layer enforces invariants. That way the
     * cron-driven path (which doesn't go through REST) gets the same guarantees.
     */
    public function save(array $input): array {
        $sanitized = $this->sanitize($input);

        // Defense-in-depth: re-clamp at save time even though sanitize() also clamps.
        $sanitized['frequency_days'] = Plan_Config::clamp_email_report_frequency((int) $sanitized['frequency_days']);
        $sanitized['recipients'] = Plan_Config::clamp_email_report_recipients($sanitized['recipients']);

        // Seed next_scheduled_at on first enable so the UI shows a real
        // "Next report" date immediately. The scheduler still re-seeds on
        // its first tick for any other path that flips enable on.
        if ($sanitized['enabled'] && empty($sanitized['next_scheduled_at'])) {
            $sanitized['next_scheduled_at'] = wp_date(
                'Y-m-d H:i:s',
                strtotime('+' . max(1, (int) $sanitized['frequency_days']) . ' days')
            );
        }

        update_option(self::OPTION_KEY, $sanitized, false);

        /**
         * Fires after Email Report config is saved.
         *
         * Pro plugin uses this to re-validate its own added fields, refresh
         * an audit table, or trigger a re-schedule.
         *
         * @since 1.9.0
         *
         * @param array $sanitized The persisted config.
         */
        do_action('thinkrank_email_report_settings_saved', $sanitized);

        return $sanitized;
    }

    /**
     * Pure sanitization — no DB writes. Useful for previews and tests.
     *
     * Free vs. Pro behavior: Pro-only fields are accepted into the array
     * even on free, but their values are coerced to defaults if the user
     * isn't allowed to set them. Why keep them at all? So if the user
     * upgrades, their previously-saved values aren't lost.
     */
    public function sanitize(array $input): array {
        $defaults = $this->defaults();
        $caps = Plan_Config::email_report();
        $limits = function_exists('thinkrank_get_email_report_field_limits')
            ? thinkrank_get_email_report_field_limits()
            : [];

        // Existing stored values are the baseline — partial updates (e.g.
        // a toggle-only POST or a Pro field added later) merge over the
        // saved config rather than reverting unsupplied keys to defaults.
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $existing = array_merge($defaults, $stored);

        $clean = [];

        $clean['enabled'] = isset($input['enabled'])
            ? !empty($input['enabled'])
            : (bool) $existing['enabled'];

        $clean['frequency_days'] = Plan_Config::clamp_email_report_frequency(
            isset($input['frequency_days']) ? (int) $input['frequency_days'] : (int) $existing['frequency_days']
        );

        $clean['recipients'] = Plan_Config::clamp_email_report_recipients(
            $this->normalize_recipients($input['recipients'] ?? $existing['recipients'])
        );

        // Subject: free plan always uses the default. Pro: keep existing
        // when input doesn't include the key, accept new when it does.
        if (!empty($caps['custom_subject']) && array_key_exists('subject_template', $input)) {
            $subject = sanitize_text_field((string) $input['subject_template']);
            if ($subject === '') {
                $subject = (string) $defaults['subject_template'];
            }
        } elseif (!empty($caps['custom_subject'])) {
            $subject = (string) $existing['subject_template'];
        } else {
            $subject = (string) $defaults['subject_template'];
        }
        $clean['subject_template'] = $this->trim_to($subject, $limits['subject_template'] ?? 200);

        // Logo URL: free plan stays null. Pro: only overwrite when the key
        // is present in input (so partial updates don't blank the logo).
        $clean['logo_url'] = $this->resolve_optional_url(
            $caps,
            'custom_logo',
            $input,
            'logo_url',
            $existing['logo_url'] ?? null,
            $limits['logo_url'] ?? 2048
        );

        $clean['logo_link'] = $this->resolve_optional_url(
            $caps,
            'logo_link',
            $input,
            'logo_link',
            $existing['logo_link'] ?? null,
            $limits['logo_link'] ?? 2048
        );

        $clean['header_background'] = $this->resolve_optional_text(
            $caps,
            'header_background',
            $input,
            'header_background',
            $existing['header_background'] ?? null,
            $limits['header_background'] ?? 500
        );

        // Free is forced to the default toggle (true) so the dashboard CTA
        // still appears. Pro: prefer input, fall back to existing, then default.
        $clean['link_to_full_report'] = empty($caps['link_to_full_report'])
            ? (bool) $defaults['link_to_full_report']
            : (
                array_key_exists('link_to_full_report', $input)
                    ? (bool) $input['link_to_full_report']
                    : (bool) $existing['link_to_full_report']
            );

        $clean['intro_text'] = $this->resolve_optional_rich_text(
            $caps,
            'intro_text',
            $input,
            'intro_text',
            $existing['intro_text'] ?? null,
            $limits['intro_text'] ?? 5000
        );

        $clean['footer_text'] = $this->resolve_optional_rich_text(
            $caps,
            'footer_text',
            $input,
            'footer_text',
            $existing['footer_text'] ?? null,
            $limits['footer_text'] ?? 5000
        );

        $clean['additional_css'] = empty($caps['additional_css'])
            ? null
            : (
                array_key_exists('additional_css', $input)
                    ? $this->sanitize_css($input['additional_css'], $limits['additional_css'] ?? 20000)
                    : ($existing['additional_css'] ?? null)
            );

        // Sections: Free is locked to all-on. Pro user submits the list,
        // falling back to existing when the key is missing.
        if (empty($caps['sections_configurable'])) {
            $clean['sections_enabled'] = (array) $defaults['sections_enabled'];
        } elseif (array_key_exists('sections_enabled', $input)) {
            $clean['sections_enabled'] = $this->sanitize_section_keys($input['sections_enabled']);
        } else {
            $clean['sections_enabled'] = (array) $existing['sections_enabled'];
        }

        // Schedule timestamps are server-managed — never trust client input.
        $clean['next_scheduled_at'] = $stored['next_scheduled_at'] ?? null;
        $clean['last_sent_at']      = $stored['last_sent_at']      ?? null;

        /**
         * Filter the sanitized config before persistence.
         *
         * Pro plugin uses this to sanitize fields it has added via
         * `thinkrank_email_report_config_schema`. The filter receives the
         * raw input alongside the sanitized output so consumers can read
         * pro-only field intent without re-parsing the request.
         *
         * @since 1.9.0
         *
         * @param array $clean Sanitized config so far.
         * @param array $input Raw input as received.
         */
        return apply_filters('thinkrank_email_report_config_sanitized', $clean, $input);
    }

    /**
     * Update only the schedule timestamps. Called from the scheduler after
     * a successful send so we don't round-trip the whole sanitize() flow
     * (the rest of the config hasn't changed).
     */
    public function update_schedule(?string $last_sent_at, ?string $next_scheduled_at): array {
        $current = $this->get();
        $current['last_sent_at'] = $last_sent_at;
        $current['next_scheduled_at'] = $next_scheduled_at;
        update_option(self::OPTION_KEY, $current, false);
        return $current;
    }

    /**
     * Normalize a recipient input that might arrive as a string
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
     * Resolve an optional URL field with partial-update semantics.
     * Free plan: always null. Pro: prefer input, fall back to existing.
     */
    private function resolve_optional_url(array $caps, string $cap_key, array $input, string $field, $existing, int $max_len): ?string {
        if (empty($caps[$cap_key])) {
            return null;
        }
        if (array_key_exists($field, $input)) {
            return $this->sanitize_url($input[$field], $max_len);
        }
        return is_string($existing) && $existing !== '' ? $existing : null;
    }

    private function resolve_optional_text(array $caps, string $cap_key, array $input, string $field, $existing, int $max_len): ?string {
        if (empty($caps[$cap_key])) {
            return null;
        }
        if (array_key_exists($field, $input)) {
            $raw = $input[$field];
            return is_string($raw)
                ? $this->trim_to(sanitize_text_field($raw), $max_len)
                : null;
        }
        return is_string($existing) && $existing !== '' ? $existing : null;
    }

    private function resolve_optional_rich_text(array $caps, string $cap_key, array $input, string $field, $existing, int $max_len): ?string {
        if (empty($caps[$cap_key])) {
            return null;
        }
        if (array_key_exists($field, $input)) {
            return $this->sanitize_rich_text($input[$field], $max_len);
        }
        return is_string($existing) && $existing !== '' ? $existing : null;
    }

    private function sanitize_url($raw, int $max_len): ?string {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $url = esc_url_raw(trim($raw));
        if ($url === '') {
            return null;
        }
        return $this->trim_to($url, $max_len);
    }

    private function sanitize_rich_text($raw, int $max_len): ?string {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $clean = wp_kses_post($raw);
        return $this->trim_to($clean, $max_len);
    }

    private function sanitize_css($raw, int $max_len): ?string {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // wp_strip_all_tags + length cap is enough — actual CSS-in-email
        // safety is an email-client problem we can't solve server-side.
        $clean = wp_strip_all_tags($raw);
        return $this->trim_to($clean, $max_len);
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private function sanitize_section_keys($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = function_exists('thinkrank_get_email_report_default_sections')
            ? array_keys(thinkrank_get_email_report_default_sections())
            : [];
        $allowed = array_unique(array_merge(
            $allowed,
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

    private function trim_to(string $value, int $max_len): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_len);
        }
        return substr($value, 0, $max_len);
    }
}
