<?php
/**
 * SEO Goals: setup still missing.
 *
 * @package ThinkRank\SEO\Goals\Providers
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals\Providers;

use ThinkRank\Admin\Importers\Snapshot_Store;
use ThinkRank\Core\SEO_Plugin_Detector;
use ThinkRank\Core\Settings;
use ThinkRank\SEO\Goals\Goal;
use ThinkRank\SEO\Goals\Goal_Provider_Interface;
use ThinkRank\SEO\LLMs_Txt_Manager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup goals (#728): the connections and one-time steps that everything
 * else in ThinkRank reads from. On a fresh install these are the list — the
 * other providers have nothing to measure until Search Console is connected
 * and the old plugin's data is in.
 *
 * Reads options and files only.
 *
 * @since 2.22.0
 */
class Setup_Provider implements Goal_Provider_Interface {

    /**
     * {@inheritDoc}
     */
    public function id(): string {
        return 'setup';
    }

    /**
     * {@inheritDoc}
     */
    public function goals(): array {
        $settings = Settings::instance();
        $oauth    = '' !== (string) $settings->get('google_access_token', '');

        // Mirrors get-integrations-status: Search Console needs only the
        // token, Analytics needs the token and a chosen property.
        $gsc = $oauth || '' !== (string) $settings->get('google_search_console_api_key', '');
        $ga  = ($oauth && '' !== (string) $settings->get('seo_analytics_google_analytics_property_id', ''))
            || '' !== (string) $settings->get('google_analytics_api_key', '');

        $goals = [
            Goal::from_array([
                'id'       => 'setup:connect-search-console',
                'title'    => $gsc ? __('Search Console is connected', 'thinkrank') : __('Connect Google Search Console', 'thinkrank'),
                'why'      => __('Search Console is how ThinkRank sees which searches show your pages, where they rank and how often people click. Without it, the opportunities, keyword and page reports stay empty.', 'thinkrank'),
                'how'      => [
                    __('Open Integrations → Google Services.', 'thinkrank'),
                    __('Click Connect with Google and sign in with the account that manages this site in Search Console.', 'thinkrank'),
                    __('Pick this site’s property.', 'thinkrank'),
                ],
                'link'     => ['section' => 'integrations', 'item' => 'google-services'],
                'severity' => 'critical',
                'effort'   => 'S',
                'section'  => 'integrations',
                'open'     => !$gsc,
            ]),
            Goal::from_array([
                'id'       => 'setup:connect-analytics',
                'title'    => $ga ? __('Google Analytics is connected', 'thinkrank') : __('Connect Google Analytics', 'thinkrank'),
                'why'      => __('Analytics tells you what visitors do after they arrive: which pages keep them and which lose them. ThinkRank uses it for the traffic cards and the email report.', 'thinkrank'),
                'how'      => [
                    __('Open Integrations → Google Services.', 'thinkrank'),
                    $oauth
                        ? __('Choose this site’s Google Analytics 4 property.', 'thinkrank')
                        : __('Connect with Google, then choose this site’s Google Analytics 4 property.', 'thinkrank'),
                ],
                'link'     => ['section' => 'integrations', 'item' => 'google-services'],
                'severity' => 'medium',
                'effort'   => 'S',
                'section'  => 'integrations',
                'open'     => !$ga,
            ]),
        ];

        if (class_exists(LLMs_Txt_Manager::class)) {
            $published = (new LLMs_Txt_Manager())->is_published();
            $goals[]   = Goal::from_array([
                'id'       => 'setup:publish-llms-txt',
                'title'    => $published ? __('llms.txt is published', 'thinkrank') : __('Publish your llms.txt', 'thinkrank'),
                'why'      => __('llms.txt is a short guide to your site written for AI assistants such as ChatGPT and Perplexity. Publishing one helps them describe your site accurately when they answer questions about it.', 'thinkrank'),
                'how'      => [
                    __('Open Crawling → LLMs & AI Indexing.', 'thinkrank'),
                    __('Generate the file, read it over, and publish it.', 'thinkrank'),
                ],
                'link'     => ['section' => 'crawling-ai-indexing', 'item' => 'llms-ai-indexing'],
                'severity' => 'high',
                'effort'   => 'S',
                'section'  => 'crawling-ai-indexing',
                'open'     => !$published,
            ]);
        }

        // Every active importable plugin is reported, finished or not: a
        // finished import is how a done mark on its goal learns the condition
        // went away, and how Pro's history records it as completed (#1678).
        foreach ($this->imports() as $import) {
            $done    = $import['complete'];
            $goals[] = Goal::from_array([
                'id'       => 'setup:finish-import-' . $import['slug'],
                'title'    => $done
                    ? sprintf(
                        /* translators: %s: SEO plugin name, e.g. Yoast SEO */
                        __('Your SEO data is imported from %s', 'thinkrank'),
                        $import['name']
                    )
                    : sprintf(
                        /* translators: %s: SEO plugin name, e.g. Yoast SEO */
                        __('Bring your SEO data over from %s', 'thinkrank'),
                        $import['name']
                    ),
                'why'      => sprintf(
                    /* translators: %s: SEO plugin name */
                    __('%s is still active and its titles, descriptions and settings have not been imported. Until they are, ThinkRank serves its own defaults for those pages, and two SEO plugins print competing tags.', 'thinkrank'),
                    $import['name']
                ),
                'how'      => [
                    __('Open the Migration screen and run the import. Nothing in the other plugin is changed.', 'thinkrank'),
                    sprintf(
                        /* translators: %s: SEO plugin name */
                        __('Check a few pages, then deactivate %s.', 'thinkrank'),
                        $import['name']
                    ),
                ],
                'link'     => $import['link'],
                'severity' => 'high',
                'effort'   => 'M',
                'open'     => !$done,
            ]);
        }

        return array_values(array_filter($goals));
    }

    /**
     * Active importable SEO plugins: every one whose import is complete, and
     * the first one whose data has not been fully imported.
     *
     * Only active plugins count — an inactive one prints nothing and its data
     * is still importable later. An import counts as finished once the
     * migration's last chunk recorded `last_migrated` on the snapshot. Only
     * the first unfinished import is listed, so the list asks for one import
     * at a time.
     *
     * @since 2.22.0
     *
     * @return array<int, array{slug: string, name: string, link: array<string, string>, complete: bool}>
     */
    private function imports(): array {
        if (!class_exists(SEO_Plugin_Detector::class)) {
            return [];
        }

        // The Migration screen exists only while its switch is on; the
        // Settings tab is where the switch lives.
        $link = (bool) Settings::instance()->get('enable_migration_tools', false)
            ? ['page' => 'migration']
            : ['page' => 'settings', 'tab' => 'migration-tools'];

        $imports    = [];
        $unfinished = false;

        foreach (SEO_Plugin_Detector::detect_plugins() as $slug => $plugin) {
            if (empty($plugin['importable'])) {
                continue;
            }

            $manifest = class_exists(Snapshot_Store::class) ? Snapshot_Store::get_manifest((string) $slug) : null;
            $complete = is_array($manifest) && 'complete' === ($manifest['status'] ?? '') && !empty($manifest['last_migrated']);

            if (!$complete) {
                if ($unfinished) {
                    continue;
                }
                $unfinished = true;
            }

            $imports[] = [
                'slug'     => sanitize_key((string) $slug),
                'name'     => (string) ($plugin['name'] ?? $slug),
                'link'     => $link,
                'complete' => $complete,
            ];
        }

        return $imports;
    }
}
