<?php
/**
 * Title Experiments: read a window's numbers from Search Console.
 *
 * @package ThinkRank\SEO\Experiments
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Experiments;

use ThinkRank\Core\Settings;
use ThinkRank\SEO\Analytics_Manager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Experiment Measurer
 *
 * The only part of Title Experiments that talks to Google. Two calls per
 * window — the page, and the whole property for the control — so a finished
 * experiment costs four requests (#719). Kept apart so the lifecycle can be
 * tested without Google.
 *
 * @since 2.22.0
 */
class Experiment_Measurer {

    /**
     * Whether Search Console is connected. An experiment needs it: without
     * it there is nothing to measure, and running one blind is refused.
     *
     * @return bool
     */
    public function available(): bool {
        $settings = Settings::instance();

        return '' !== (string) $settings->get('google_access_token', '')
            || '' !== (string) $settings->get('google_search_console_api_key', '');
    }

    /**
     * Clicks, impressions and average position over dates, for one page or
     * (with '') the whole property.
     *
     * @param string $from     First day, `Y-m-d`.
     * @param string $to       Last day, `Y-m-d`.
     * @param string $page_url Page URL, or '' for the whole property.
     * @return array{clicks: int, impressions: int, position: float}
     * @throws \Exception When Search Console cannot be reached or refuses.
     */
    public function totals(string $from, string $to, string $page_url = ''): array {
        // A measurement runs from the daily event, often long after the
        // access token expired; refresh it before building the client, as
        // Focus Pages and the Keywords screen do.
        Analytics_Manager::ensure_fresh_token();

        $manager = new Analytics_Manager();
        $client  = $manager->get_search_console_client();

        if (null === $client) {
            throw new \Exception(__('Search Console is not connected.', 'thinkrank'));
        }

        return $client->get_totals_by_dates($manager->get_property_url(), $from, $to, $page_url);
    }
}
