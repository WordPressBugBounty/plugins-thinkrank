<?php
/**
 * SEO Goals: pages with snippet problems or too little content.
 *
 * @package ThinkRank\SEO\Goals\Providers
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals\Providers;

use ThinkRank\SEO\Global_SEO_Post_Types;
use ThinkRank\SEO\Goals\Goal;
use ThinkRank\SEO\Goals\Goal_Provider_Interface;
use ThinkRank\SEO\Snippet_Index;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content goals (#728): published pages missing a meta description or focus
 * keyword, sharing a title or description with another page, or carrying
 * too little content — counted sitewide, each linking to the screen that
 * fixes them in one sitting (Bulk Snippets, Thin Content).
 *
 * Counts come from the snippet index Bulk Snippets keeps. The index fills
 * itself a bounded batch at a time; each rebuild of the list advances it by
 * one batch, so on a large site that nobody has opened Bulk Snippets on yet
 * the counts start low and settle. They are a floor, never an overcount.
 *
 * @since 2.22.0
 */
class Content_Provider implements Goal_Provider_Interface {

    /**
     * Only live pages compete in search.
     *
     * @var string[]
     */
    private const STATUSES = ['publish'];

    /**
     * {@inheritDoc}
     */
    public function id(): string {
        return 'content';
    }

    /**
     * {@inheritDoc}
     */
    public function goals(): array {
        $goals = [];

        $counts = $this->snippet_counts();
        if (null !== $counts) {
            $goals = array_merge($goals, $this->snippet_goals($counts));
        }

        $thin = $this->thin_count();
        if (null !== $thin) {
            $goals[] = Goal::from_array([
                'id'       => 'content:thin',
                'title'    => $thin > 0
                    ? sprintf(
                        /* translators: %d: number of pages */
                        _n('Expand %d page with very little content', 'Expand %d pages with very little content', $thin, 'thinkrank'),
                        $thin
                    )
                    : __('No page is too thin', 'thinkrank'),
                'why'      => __('A page with only a few lines rarely answers a search well, and a site with many of them can look low-effort to search engines as a whole.', 'thinkrank'),
                'how'      => [
                    __('Open Bulk SEO Optimization → Thin Content to see which pages fall under their threshold.', 'thinkrank'),
                    __('Add to the ones worth keeping; merge or noindex the rest.', 'thinkrank'),
                ],
                'link'     => ['section' => 'global-seo', 'item' => 'thin-content'],
                'severity' => 'medium',
                'effort'   => self::effort($thin),
                'count'    => $thin,
                'section'  => 'global-seo',
                'open'     => $thin > 0,
            ]);
        }

        return array_values(array_filter($goals));
    }

    /**
     * Goals from the snippet issue counts.
     *
     * @param array<string, int> $counts Issue => published pages.
     * @return array<int, Goal|null>
     */
    private function snippet_goals(array $counts): array {
        $link = ['section' => 'global-seo', 'item' => 'bulk-snippets'];
        $how  = [
            __('Open Bulk SEO Optimization → Bulk Snippets.', 'thinkrank'),
            __('Filter by this problem and fix the pages in the table, or select them and let AI draft values for you to review before saving.', 'thinkrank'),
        ];

        $specs = [
            [
                'id'       => 'content:empty-description',
                'count'    => $counts['empty_description'] ?? 0,
                'open'     => /* translators: %d: number of pages */ _n('Write a meta description for %d page', 'Write meta descriptions for %d pages', max(1, $counts['empty_description'] ?? 0), 'thinkrank'),
                'done'     => __('Every page has its own meta description', 'thinkrank'),
                'why'      => __('The meta description is the text under your title in search results. Without one, Google picks a sentence from the page, which is often not the one that makes people click.', 'thinkrank'),
                'severity' => 'high',
            ],
            [
                'id'       => 'content:no-focus-keyword',
                'count'    => $counts['no_focus_keyword'] ?? 0,
                'open'     => /* translators: %d: number of pages */ _n('Set a focus keyword on %d page', 'Set focus keywords on %d pages', max(1, $counts['no_focus_keyword'] ?? 0), 'thinkrank'),
                'done'     => __('Every page has a focus keyword', 'thinkrank'),
                'why'      => __('The focus keyword is what ThinkRank scores a page against. Without one, the page’s SEO score and suggestions cannot tell you whether it targets the search you want.', 'thinkrank'),
                'severity' => 'medium',
            ],
            [
                'id'       => 'content:duplicate-title',
                'count'    => $counts['duplicate_title'] ?? 0,
                'open'     => /* translators: %d: number of pages */ _n('Give %d page a title no other page uses', 'Give %d pages titles no other page uses', max(1, $counts['duplicate_title'] ?? 0), 'thinkrank'),
                'done'     => __('No two pages share a title', 'thinkrank'),
                'why'      => __('Pages with the same title compete for the same search, and Google usually shows only one of them.', 'thinkrank'),
                'severity' => 'medium',
            ],
            [
                'id'       => 'content:duplicate-description',
                'count'    => $counts['duplicate_description'] ?? 0,
                'open'     => /* translators: %d: number of pages */ _n('Give %d page a description no other page uses', 'Give %d pages descriptions no other page uses', max(1, $counts['duplicate_description'] ?? 0), 'thinkrank'),
                'done'     => __('No two pages share a description', 'thinkrank'),
                'why'      => __('A description repeated across pages tells a searcher nothing about which page to pick, so Google often replaces it.', 'thinkrank'),
                'severity' => 'low',
            ],
            [
                'id'       => 'content:title-length',
                'count'    => ($counts['title_too_long'] ?? 0) + ($counts['title_too_short'] ?? 0),
                'open'     => /* translators: %d: number of pages */ _n('Fix the title length on %d page', 'Fix the title length on %d pages', max(1, ($counts['title_too_long'] ?? 0) + ($counts['title_too_short'] ?? 0)), 'thinkrank'),
                'done'     => __('Titles are a good length', 'thinkrank'),
                'why'      => __('Titles past about 60 characters are cut off in search results; very short ones waste the space that sells the click.', 'thinkrank'),
                'severity' => 'low',
            ],
        ];

        $goals = [];
        foreach ($specs as $spec) {
            $count   = (int) $spec['count'];
            $goals[] = Goal::from_array([
                'id'       => $spec['id'],
                'title'    => $count > 0 ? sprintf($spec['open'], $count) : $spec['done'],
                'why'      => $spec['why'],
                'how'      => $how,
                'link'     => $link,
                'severity' => $spec['severity'],
                'effort'   => self::effort($count),
                'count'    => $count,
                'section'  => 'global-seo',
                'open'     => $count > 0,
            ]);
        }

        return $goals;
    }

    /**
     * Published pages per snippet issue, summed across every post type
     * ThinkRank manages. Null when the index is not available.
     *
     * @return array<string, int>|null
     */
    private function snippet_counts(): ?array {
        if (!class_exists(Snippet_Index::class) || !class_exists(Global_SEO_Post_Types::class)) {
            return null;
        }

        $types = Global_SEO_Post_Types::allowed();
        if ([] === $types) {
            return null;
        }

        // One bounded batch per rebuild (the index caps it by posts and time).
        Snippet_Index::refresh_sitewide($types, self::STATUSES);

        $totals = [];
        foreach ($types as $post_type) {
            $result = Snippet_Index::query([
                'post_type'        => $post_type,
                'statuses'         => self::STATUSES,
                'group_post_types' => $types,
                'issue'            => '',
                'search'           => '',
                'page'             => 1,
                'per_page'         => 1,
                'visibility_sql'   => Snippet_Index::visibility_sql($post_type),
            ]);

            foreach ((array) ($result['counts'] ?? []) as $issue => $count) {
                $totals[(string) $issue] = ($totals[(string) $issue] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }

    /**
     * Thin pages from the thin content report's stored copy. Null when the
     * report has never been built — nothing is known, so nothing is claimed.
     *
     * @return int|null
     */
    private function thin_count(): ?int {
        $report = get_option('thinkrank_thin_content_report', null);
        if (!is_array($report) || !is_array($report['types'] ?? null)) {
            return null;
        }

        $thin = 0;
        foreach ($report['types'] as $type) {
            $thin += is_array($type) ? (int) ($type['thin'] ?? 0) : 0;
        }

        return $thin;
    }

    /**
     * Effort from how many pages need the work.
     *
     * @param int $count Pages.
     * @return string
     */
    private static function effort(int $count): string {
        if ($count <= 10) {
            return 'S';
        }

        return $count <= 50 ? 'M' : 'L';
    }
}
