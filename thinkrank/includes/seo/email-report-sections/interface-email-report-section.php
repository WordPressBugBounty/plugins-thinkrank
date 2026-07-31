<?php
/**
 * Email Report Section Interface
 *
 * Contract every section in the email report must implement. The renderer
 * walks the registry, calls collect() then render() on each registered
 * section, and falls back to fallback_html() if either throws.
 *
 * Extension model: the Pro plugin (or any other plugin) registers new
 * sections via the `thinkrank_email_report_register_sections` action,
 * implementing this interface. Free code never needs to know about them.
 *
 * @package ThinkRank
 * @subpackage SEO\Email_Report_Sections
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Email_Report_Sections;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Section_Interface
 *
 * @since 1.9.0
 */
interface Email_Report_Section_Interface {

    /**
     * Section identifier — also the key used in sections_enabled array.
     */
    public function key(): string;

    /**
     * Localized label shown in the "Sections to Include" UI.
     */
    public function label(): string;

    /**
     * Whether this section is on by default for a fresh install.
     */
    public function default_enabled(): bool;

    /**
     * The Plan_Config capability required to render this section.
     *
     * Return null for free sections. AI Highlights returns 'ai_highlights'.
     * The renderer skips sections whose capability is not satisfied without
     * raising an error — no surprises if a downgrade hides a Pro section.
     */
    public function requires_capability(): ?string;

    /**
     * Pull the data needed to render this section.
     *
     * Receives a context with the site URL, period, and any pre-fetched
     * data the orchestrator chooses to share. Throwing an exception is fine
     * — the renderer catches and falls back. Returning an empty payload
     * also triggers fallback HTML.
     *
     * @param array $context {
     *     @type string $site_url       Site URL (home_url()).
     *     @type string $period_start   ISO datetime.
     *     @type string $period_end     ISO datetime.
     *     @type int    $frequency_days Frequency the report is being sent at.
     *     @type array  $shared         Cross-section cached data, e.g. shared GSC pulls.
     * }
     * @return array Payload chunk consumed by render().
     */
    public function collect(array $context): array;

    /**
     * Render the section's HTML given the payload from collect().
     *
     * Must return safe HTML — the renderer wraps the result in a section
     * shell but does not re-sanitize. Use wp_kses_post() / esc_html() for
     * any data interpolated from collect().
     */
    public function render(array $payload): string;

    /**
     * HTML rendered when collect() returns empty or throws.
     *
     * Per PRD: "If any data source is unavailable, render with a fallback
     * message rather than failing the entire report."
     */
    public function fallback_html(): string;
}
