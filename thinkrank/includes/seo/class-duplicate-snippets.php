<?php
/**
 * Sitewide duplicate title and meta description report.
 *
 * @package ThinkRank\SEO
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Duplicate Snippets
 *
 * Answers one question across the whole site: which published pages render the
 * same SEO title, or the same meta description, as another one (#564). Two
 * pages with one title between them compete for the same result, and on a
 * template-heavy site a single bad pattern stamps the same metadata on fifty
 * archive pages without anything saying so.
 *
 * Three decisions shape this class.
 *
 * **It does not crawl, and it is not part of the Site SEO Analyzer.** The
 * analyzer is deliberately crawl-free *and* sample-bounded — it reads the most
 * recent hundred posts — and a duplicate outside a bounded sample is invisible
 * by definition, so putting this there would have answered the question wrong
 * rather than expensively. Instead it reads {@see Snippet_Index}, which already
 * stores a grouping key per post, and finds collisions by grouping in SQL over
 * every post rather than a sample.
 *
 * **Published only.** A draft is not on the web and cannot compete with
 * anything, so counting one as a duplicate would report a problem that does not
 * exist. Bulk Snippets still shows the per-row duplicate badge under whichever
 * status filter is in use; this report is the sitewide number, and the sitewide
 * number is about what is live.
 *
 * **On demand, not scheduled.** The scan is driven by the screen asking for it,
 * a bounded batch at a time, and the result is cached against the index
 * generation so a second look is free until something changes. A scheduled
 * recheck is the Pro half of the same feature (see `Plan_Config`), which is why
 * the capability is read here rather than assumed.
 *
 * @since 2.10.0
 */
class Duplicate_Snippets {

    /**
     * Option holding the cached report.
     */
    private const CACHE_OPTION = 'thinkrank_duplicate_snippets_report';

    /**
     * Statuses the report covers. See the class docblock: a page that is not
     * published is not competing with anything.
     *
     * @var string[]
     */
    private const STATUSES = ['publish'];

    /**
     * Groups reported per kind. A site with more than this many distinct
     * duplicated titles has a template problem, not fifty separate ones, and
     * the counts above the list still report the true total.
     */
    public const MAX_GROUPS = 25;

    /**
     * Posts listed inside one group. The group's count is exact; this only
     * bounds how many of them are named.
     */
    public const MAX_MEMBERS = 10;

    /**
     * The report, from cache when the index has not moved since it was built.
     *
     * `$refresh` rescans rather than only regrouping. Regrouping reads the same
     * index entries the cache was built from, so an entry the invalidation
     * hooks missed (a term, option or plugin filter that changed what a title
     * renders without saving the post) survived a "refresh" untouched, which
     * is the one case a user reaches for the button. Bumping the generation
     * makes every entry stale, and the next calls rebuild them a bounded
     * batch at a time as usual; callers continue without `$refresh`, or each
     * call would start the scan over.
     *
     * @since 2.10.0 `$refresh` rescans the site.
     *
     * @param bool $refresh Discard every index entry and rescan.
     * @return array{
     *     pending: int,
     *     scanned: int,
     *     counts: array<string,int>,
     *     groups: array<string,array<int,array<string,mixed>>>,
     *     truncated: array<string,bool>,
     *     generated_at: int,
     *     scheduled: bool
     * }
     */
    public static function report(bool $refresh = false): array {
        $post_types = Global_SEO_Post_Types::allowed();

        if ($refresh) {
            Snippet_Index::bump_generation();
        }

        // Keep filling the index before answering. Each call is bounded, and
        // `pending` tells the caller whether the answer is complete yet.
        $pending = Snippet_Index::refresh_sitewide($post_types, self::STATUSES);

        // What the answer depends on. The generation alone is not enough: it
        // only moves when a *global* input changes, and one post's title
        // edited to match another's changes this report without touching it.
        // The revision covers that, and the count covers a post leaving the
        // scope entirely, which no rebuild would ever report.
        $signature = [
            'generation' => Snippet_Index::generation(),
            'revision'   => Snippet_Index::revision(),
            'scanned'    => Snippet_Index::current_count($post_types, self::STATUSES),
        ];

        $cached = get_option(self::CACHE_OPTION, null);
        if (!$refresh
            && is_array($cached)
            && 0 === $pending
            && ($cached['signature'] ?? null) === $signature
        ) {
            return self::present($cached, 0);
        }

        $report = [
            'signature' => $signature,
            'scanned'   => $signature['scanned'],
            'counts'    => [],
            'groups'    => [],
            'truncated' => [],
            'built_at'  => time(),
        ];

        foreach (Snippet_Issues::grouped() as $issue) {
            $which = Snippet_Issues::DUPLICATE_TITLE === $issue ? 'title' : 'description';

            // Totals come from their own count rather than from the listed
            // groups, which are capped — otherwise a site with 200 duplicated
            // titles would report 25 groups' worth and call it the total.
            $totals = Snippet_Index::duplicate_totals($post_types, self::STATUSES, $which);

            $report['counts'][$issue] = $totals['posts'];
            $report['group_counts'][$issue] = $totals['groups'];
            $report['truncated'][$issue] = $totals['groups'] > self::MAX_GROUPS;
            $report['groups'][$issue] = self::describe(
                Snippet_Index::duplicate_groups(
                    $post_types,
                    self::STATUSES,
                    $which,
                    self::MAX_MEMBERS,
                    self::MAX_GROUPS
                ),
                $which
            );
        }

        // Only a complete scan is worth caching: a partial one would be served
        // as the answer long after the index finished filling.
        if (0 === $pending) {
            update_option(self::CACHE_OPTION, $report, false);
        }

        return self::present($report, $pending);
    }

    /**
     * Shape a stored report for its caller.
     *
     * @param array<string,mixed> $report  Stored report.
     * @param int                 $pending Entries still to be indexed.
     * @return array<string,mixed>
     */
    private static function present(array $report, int $pending): array {
        $counts = [];
        $group_counts = [];
        $groups = [];
        $truncated = [];

        foreach (Snippet_Issues::grouped() as $issue) {
            $counts[$issue] = (int) ($report['counts'][$issue] ?? 0);
            $group_counts[$issue] = (int) ($report['group_counts'][$issue] ?? 0);
            $groups[$issue] = array_values((array) ($report['groups'][$issue] ?? []));
            $truncated[$issue] = (bool) ($report['truncated'][$issue] ?? false);
        }

        $groups = self::add_viewer_fields($groups);

        return [
            // Above zero means the scan has not covered the whole site yet, so
            // the numbers below are a floor rather than the answer.
            'pending'      => max(0, $pending),
            'scanned'      => (int) ($report['scanned'] ?? 0),
            // Posts caught up in a duplicate, per issue.
            'counts'       => $counts,
            // Distinct values being duplicated, per issue.
            'group_counts' => $group_counts,
            'groups'       => $groups,
            'truncated'    => $truncated,
            'generated_at' => (int) ($report['built_at'] ?? 0),
            'scheduled'    => \ThinkRank\Core\Plan_Config::can('scheduled', 'duplicate_snippets'),
        ];
    }

    /**
     * Add the two fields that belong to the reader rather than to the report.
     *
     * Answered for the current user on every request, cache hit or not. The
     * report is one option shared by every user, so a `can_edit` resolved while
     * building it would be handed to whoever asked next: a rebuild triggered by
     * someone without edit rights cached an empty `edit_url`, and the
     * administrator who opened the screen after them was linked to the public
     * page instead of the editor.
     *
     * @param array<string,array<int,array<string,mixed>>> $groups Groups per issue.
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function add_viewer_fields(array $groups): array {
        $ids = [];
        foreach ($groups as $found) {
            foreach ($found as $group) {
                foreach ((array) ($group['posts'] ?? []) as $post) {
                    $ids[] = (int) ($post['post_id'] ?? 0);
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);
        }

        foreach ($groups as $issue => $found) {
            foreach ($found as $i => $group) {
                foreach ((array) ($group['posts'] ?? []) as $j => $post) {
                    $post_id = (int) ($post['post_id'] ?? 0);

                    $groups[$issue][$i]['posts'][$j]['can_edit'] = $post_id > 0
                        && current_user_can('edit_post', $post_id);
                    $groups[$issue][$i]['posts'][$j]['edit_url'] = $post_id > 0
                        ? (string) get_edit_post_link($post_id, 'raw')
                        : '';
                }
            }
        }

        return $groups;
    }

    /**
     * Turn grouped IDs into something a reader can act on: the value the group
     * shares, and a link to each page carrying it.
     *
     * The shared value is resolved once per group rather than once per post —
     * a duplicate group is by definition one value — so a report of 25 groups
     * resolves 25 snippets, not 250.
     *
     * Deliberately carries nothing that depends on *who is asking*: this array
     * is what gets stored in the cache option. {@see self::add_viewer_fields()}
     * resolves `edit_url` and `can_edit` per request instead.
     *
     * @param array<int,array{key:string, post_ids:int[], total:int}> $groups Groups.
     * @param string                                                  $which  'title' or 'description'.
     * @return array<int,array<string,mixed>>
     */
    private static function describe(array $groups, string $which): array {
        $all_ids = [];
        foreach ($groups as $group) {
            $all_ids = array_merge($all_ids, $group['post_ids']);
        }

        if (!empty($all_ids)) {
            _prime_post_caches(array_values(array_unique($all_ids)), false, true);
        }

        $described = [];
        foreach ($groups as $group) {
            $posts = [];
            foreach ($group['post_ids'] as $post_id) {
                $post = get_post($post_id);
                if (!$post instanceof \WP_Post) {
                    continue;
                }

                $posts[] = [
                    'post_id'    => (int) $post->ID,
                    'post_title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                    'post_type'  => $post->post_type,
                    'permalink'  => (string) get_permalink($post),
                ];
            }

            if (empty($posts)) {
                continue;
            }

            $first = (int) $posts[0]['post_id'];
            $described[] = [
                'key'     => $group['key'],
                // Decoded, like post_title above: the resolved value is still
                // HTML (`Foo &#038; Bar`), and both the card and the ability
                // hand it on as text, so the entity was shown as typed.
                'value'   => \ThinkRank\Core\Seo_Text::as_displayed(
                    'title' === $which
                        ? Pattern_Resolver::effective_title($first)
                        : Pattern_Resolver::effective_description($first)
                ),
                'total'   => $group['total'],
                'posts'   => $posts,
                // True when the group has more members than are named here.
                'partial' => $group['total'] > count($posts),
            ];
        }

        return $described;
    }
}
