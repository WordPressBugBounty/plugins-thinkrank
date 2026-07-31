<?php
/**
 * Email Report Section Registry
 *
 * Holds the ordered list of section objects that make up the email body.
 * Free code registers the six built-in sections at boot. The Pro plugin
 * adds AI Highlights (or any other section) by hooking
 * `thinkrank_email_report_register_sections` and calling register().
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Plan_Config;
use ThinkRank\SEO\Email_Report_Sections\Email_Report_Section_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Section_Registry
 *
 * @since 1.9.0
 */
final class Email_Report_Section_Registry {

    /**
     * @var array<string,Email_Report_Section_Interface>
     */
    private array $sections = [];

    /**
     * Register a section. Last writer wins on key collision so Pro can
     * intentionally override a free section if it ever needs to (rare).
     */
    public function register(Email_Report_Section_Interface $section): void {
        $this->sections[$section->key()] = $section;
    }

    /**
     * Drop a section by key. Free code shouldn't call this — it's here
     * for tests and for the Pro plugin if ever needed.
     */
    public function unregister(string $key): void {
        unset($this->sections[$key]);
    }

    /**
     * @return array<string,Email_Report_Section_Interface>
     */
    public function all(): array {
        return $this->sections;
    }

    /**
     * Resolve the ordered, capability-filtered, user-enabled section list
     * for a given config. Returned in the order they should render.
     *
     * Pro can re-order or insert sections via the
     * `thinkrank_email_report_sections` filter — that's how AI Highlights
     * jumps to the top of the list.
     *
     * @param array $config Per-site Email Report config.
     * @return array<int,Email_Report_Section_Interface>
     */
    public function resolve_for(array $config): array {
        $enabled = $config['sections_enabled'] ?? [];
        $caps = Plan_Config::email_report();

        $resolved = [];
        foreach ($this->sections as $key => $section) {
            if (!in_array($key, $enabled, true)) {
                continue;
            }
            $required = $section->requires_capability();
            if ($required !== null && empty($caps[$required])) {
                // Capability not granted by current plan — silently skip.
                continue;
            }
            $resolved[$key] = $section;
        }

        /**
         * Filter the ordered section list for a single report.
         *
         * Pro plugin uses this to insert AI Highlights at the top, or
         * re-arrange sections per agency preference.
         *
         * @since 1.9.0
         *
         * @param array<string,Email_Report_Section_Interface> $resolved Ordered sections, key => section.
         * @param array                                        $config   Per-site config.
         */
        $resolved = apply_filters('thinkrank_email_report_sections', $resolved, $config);

        // Re-validate after filter: drop anything that doesn't implement the contract.
        return array_values(array_filter(
            $resolved,
            static fn ($s) => $s instanceof Email_Report_Section_Interface
        ));
    }

    /**
     * Convenience: list of section keys to expose in the admin UI.
     * Mirrors the registry order, includes pro-gated sections so the UI
     * can render them with a lock chip.
     *
     * @return array<int,array{key:string,label:string,requires_capability:?string}>
     */
    public function describe_for_ui(): array {
        $out = [];
        foreach ($this->sections as $key => $section) {
            $out[] = [
                'key' => $key,
                'label' => $section->label(),
                'requires_capability' => $section->requires_capability(),
                'default_enabled' => $section->default_enabled(),
            ];
        }
        return $out;
    }
}
