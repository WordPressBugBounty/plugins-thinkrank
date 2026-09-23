<?php
/**
 * Site Health test for JSON-LD emitted by other plugins.
 *
 * @package ThinkRank\Diagnostics
 */

declare(strict_types=1);

namespace ThinkRank\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Surfaces foreign JSON-LD as a Site Health test.
 *
 * Registered async, so the loopback fetch Foreign_Schema_Detector performs runs
 * from the browser after the Site Health screen has painted, instead of adding
 * up to ten seconds to the page load. The alternative shape considered in #447
 * — buffering the front end and scanning every response — was rejected: it puts
 * the cost on every anonymous pageview, which is the mistake #402 documents.
 *
 * The test reports. It never merges, rewrites or suppresses another plugin's
 * output.
 *
 * @since 2.9.0
 */
class Schema_Conflict_Health_Check {

    /**
     * Test identifier, also the `health-check-{test}` AJAX action suffix.
     *
     * Dashes only, no underscores. Core builds the AJAX action from the test id
     * with a *string* replace — `'health-check-' + this.test.replace( '_', '-' )`
     * in wp-admin/js/site-health.js — and a string pattern replaces only the
     * first match. Every core async test id has at most one underscore, so the
     * single replace is enough there. An id with two (`thinkrank_schema_conflicts`)
     * became `health-check-thinkrank-schema_conflicts` in the browser while we
     * registered `health-check-thinkrank_schema_conflicts`, so admin-ajax
     * answered `0`, core discarded the invalid payload and the test silently
     * never appeared on the Site Health screen.
     */
    private const TEST = 'thinkrank-schema-conflicts';

    /**
     * Capability WordPress itself requires to view Site Health results.
     */
    private const CAPABILITY = 'view_site_health_checks';

    /**
     * Register the test.
     *
     * @return void
     */
    public function init(): void {
        add_filter('site_status_tests', [$this, 'register_test']);
        add_action('wp_ajax_health-check-' . self::TEST, [$this, 'ajax_run']);

        // A plugin going active or inactive is exactly the event that changes
        // the answer, so the cached scan must not outlive it.
        add_action('activated_plugin', [Foreign_Schema_Detector::class, 'flush_cache']);
        add_action('deactivated_plugin', [Foreign_Schema_Detector::class, 'flush_cache']);
    }

    /**
     * Add the test to the Site Health registry.
     *
     * @param array<string, array<string, mixed>> $tests Registered tests.
     * @return array<string, array<string, mixed>>
     */
    public function register_test(array $tests): array {
        $tests['async'][self::TEST] = [
            'label'             => __('Schema markup from other plugins', 'thinkrank'),
            'test'              => self::TEST,
            'has_rest'          => false,
            'async_direct_test' => [$this, 'run'],
        ];

        return $tests;
    }

    /**
     * AJAX entry point for the async test.
     *
     * @return void
     */
    public function ajax_run(): void {
        check_ajax_referer('health-check-site-status');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(null, 403);
        }

        wp_send_json_success($this->run());
    }

    /**
     * Run the scan and shape it as a Site Health result.
     *
     * @return array<string, mixed>
     */
    public function run(): array {
        $report = (new Foreign_Schema_Detector())->scan();

        if (!empty($report['error'])) {
            return $this->result(
                'recommended',
                __('ThinkRank could not check this site for duplicate schema markup', 'thinkrank'),
                '<p>' . sprintf(
                    /* translators: 1: scanned URL, 2: error message. */
                    esc_html__('ThinkRank tried to read %1$s from this server and could not: %2$s Duplicate schema markup from another plugin would go unnoticed until this check can run.', 'thinkrank'),
                    '<code>' . esc_url((string) ($report['scanned_url'] ?? '')) . '</code>',
                    esc_html((string) $report['error'])
                ) . '</p>'
            );
        }

        if (empty($report['conflicts'])) {
            return $this->result(
                'good',
                __('ThinkRank is the only source of schema markup on this page', 'thinkrank'),
                '<p>' . sprintf(
                    /* translators: %s: scanned URL. */
                    esc_html__('ThinkRank checked %s and found no page-level schema markup from another plugin competing with its own.', 'thinkrank'),
                    '<code>' . esc_url((string) ($report['scanned_url'] ?? '')) . '</code>'
                ) . '</p>'
            );
        }

        return $this->result(
            'recommended',
            __('Another plugin is publishing schema markup on the same pages', 'thinkrank'),
            $this->conflict_description($report)
        );
    }

    /**
     * Body copy for a page carrying foreign schema.
     *
     * @param array<string, mixed> $report Scan report.
     * @return string
     */
    private function conflict_description(array $report): string {
        $html = '<p>' . sprintf(
            /* translators: %s: scanned URL. */
            esc_html__('ThinkRank checked %s and found schema markup published by another plugin for the same page. Search engines see both, and nothing decides which one is right, so they may show the wrong title, image or breadcrumb trail, or ignore the markup altogether.', 'thinkrank'),
            '<code>' . esc_url((string) ($report['scanned_url'] ?? '')) . '</code>'
        ) . '</p>';

        $html .= '<ul>';

        foreach ((array) $report['conflicts'] as $conflict) {
            $types = implode(', ', array_map('esc_html', (array) $conflict['duplicated']));

            $line = empty($conflict['guess'])
                /* translators: 1: plugin name, 2: comma-separated schema types. */
                ? esc_html__('%1$s also publishes: %2$s', 'thinkrank')
                /* translators: 1: plugin name, 2: comma-separated schema types. */
                : esc_html__('Probably %1$s (the block carries no plugin marker) also publishes: %2$s', 'thinkrank');

            $html .= '<li>' . sprintf(
                $line,
                '<strong>' . esc_html((string) $conflict['name']) . '</strong>',
                '<code>' . $types . '</code>'
            ) . '</li>';
        }

        $html .= '</ul>';

        $html .= '<p>' . esc_html__('ThinkRank does not change or remove another plugin\'s markup. To resolve this, keep one source of schema markup: either turn off the other plugin\'s schema output, or turn off ThinkRank\'s under SEO Settings.', 'thinkrank') . '</p>';

        return $html;
    }

    /**
     * Assemble a Site Health result array.
     *
     * @param string $status      One of good, recommended, critical.
     * @param string $label       Result headline.
     * @param string $description Result body HTML.
     * @return array<string, mixed>
     */
    private function result(string $status, string $label, string $description): array {
        return [
            'label'       => $label,
            'status'      => $status,
            'badge'       => [
                'label' => __('SEO', 'thinkrank'),
                'color' => 'blue',
            ],
            'description' => $description,
            'actions'     => '',
            'test'        => self::TEST,
        ];
    }
}
