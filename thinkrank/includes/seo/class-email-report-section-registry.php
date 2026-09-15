<?php
/**
 * Email Report Section Registry
 *
 * Holds the ordered list of section objects that make up the email body.
 * Free code registers the six built-in sections at boot. Other plugins add
 * sections by hooking `thinkrank_email_report_register_sections` and calling
 * register().
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

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
     * Register a section. Last writer wins on key collision so an extension
     * can intentionally override a built-in section if it ever needs to.
     */
    public function register(Email_Report_Section_Interface $section): void {
        $this->sections[$section->key()] = $section;
    }

    /**
     * Drop a section by key. Free code shouldn't call this — it's here
     * for tests and extensions.
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
     * Resolve the ordered, enabled section list for a given config. Returned
     * in the order they should render.
     *
     * @param array $config Resolved Email Report config.
     * @return array<int,Email_Report_Section_Interface>
     */
    public function resolve_for(array $config): array {
        $enabled = $config['sections_enabled'] ?? [];

        $resolved = [];
        foreach ($this->sections as $key => $section) {
            if (!in_array($key, $enabled, true)) {
                continue;
            }
            $resolved[$key] = $section;
        }

        /**
         * Filter the ordered section list for a single report.
         *
         * @since 1.9.0
         *
         * @param array<string,Email_Report_Section_Interface> $resolved Ordered sections, key => section.
         * @param array                                        $config   Resolved config.
         */
        $resolved = apply_filters('thinkrank_email_report_sections', $resolved, $config);

        // Re-validate after filter: drop anything that doesn't implement the contract.
        return array_values(array_filter(
            $resolved,
            static fn ($s) => $s instanceof Email_Report_Section_Interface
        ));
    }

    /**
     * Section catalog for the admin UI, in registry order.
     *
     * @return array<int,array{key:string,label:string,default_enabled:bool}>
     */
    public function describe_for_ui(): array {
        $out = [];
        foreach ($this->sections as $key => $section) {
            $out[] = [
                'key' => $key,
                'label' => $section->label(),
                'default_enabled' => $section->default_enabled(),
            ];
        }
        return $out;
    }
}
