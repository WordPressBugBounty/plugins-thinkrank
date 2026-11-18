<?php
/**
 * SEO Goals: searches you rank for but rarely get the click on.
 *
 * @package ThinkRank\SEO\Goals\Providers
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals\Providers;

use ThinkRank\SEO\Analytics_Manager;
use ThinkRank\SEO\Goals\Goal;
use ThinkRank\SEO\Goals\Goal_Provider_Interface;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opportunity goals (#728): Search Console queries on page one with a low
 * click-through rate — the cheapest traffic a site can win, by rewriting a
 * title and description.
 *
 * Reads the cached 30-day opportunities report and nothing else. When there
 * is no cached copy (Search Console not connected, or the Analytics screen
 * not opened lately) the provider says nothing rather than calling Google.
 *
 * @since 2.22.0
 */
class Opportunities_Provider implements Goal_Provider_Interface {

    /**
     * Range of the Analytics screen's cached report this reads.
     */
    private const RANGE = '30d';

    /**
     * {@inheritDoc}
     */
    public function id(): string {
        return 'opportunities';
    }

    /**
     * {@inheritDoc}
     */
    public function goals(): array {
        // The key the Analytics screen writes, through its own constant: a
        // literal here drifted from it and the goal never appeared (#1642).
        $report = get_transient(Analytics_Manager::OPPORTUNITIES_CACHE_PREFIX . self::RANGE);
        if (!is_array($report) || !is_array($report['keyword_opportunities'] ?? null)) {
            return [];
        }

        $list  = (array) ($report['keyword_opportunities']['opportunities'] ?? []);
        $count = (int) ($report['keyword_opportunities']['total_opportunities'] ?? count($list));

        $top = [];
        foreach (array_slice($list, 0, 3) as $row) {
            if (is_array($row) && '' !== (string) ($row['query'] ?? '')) {
                $top[] = '“' . (string) $row['query'] . '”';
            }
        }

        $why = __('Google already shows you on the first page for these searches, but few people click. A clearer title and description that match what they searched for is the quickest traffic you can win.', 'thinkrank');
        if ([] !== $top) {
            $why .= ' ' . sprintf(
                /* translators: %s: comma-separated search queries */
                __('Start with %s.', 'thinkrank'),
                implode(', ', $top)
            );
        }

        // A report with no opportunities is reported closed, not left out:
        // that is how a done mark on the goal learns it went away, and how
        // Pro's history records it as completed (#1678).
        return array_filter([Goal::from_array([
            'id'       => 'opportunities:low-ctr',
            'title'    => $count > 0
                ? sprintf(
                    /* translators: %d: number of search queries */
                    _n('Win more clicks on %d search you already rank for', 'Win more clicks on %d searches you already rank for', $count, 'thinkrank'),
                    $count
                )
                : __('The searches you rank for are getting their clicks', 'thinkrank'),
            'why'      => $why,
            'how'      => [
                __('Open Analytics → Keywords to see which page ranks for each search.', 'thinkrank'),
                __('Rewrite that page’s SEO title and description to answer the search directly. Keep the page’s position in mind: this is about clicks, not ranking.', 'thinkrank'),
            ],
            'link'     => ['section' => 'analytics', 'item' => 'keywords'],
            'severity' => 'medium',
            'effort'   => 'M',
            'count'    => max(0, $count),
            'section'  => 'analytics',
            'open'     => $count > 0,
        ])]);
    }
}
