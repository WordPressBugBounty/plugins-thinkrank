<?php
/**
 * Sitewide orphan page report.
 *
 * @package ThinkRank\SEO
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orphan Pages
 *
 * Answers "which of my published pages has nothing linking to it?" across the
 * whole site, grouped by post type (#566). An orphan is not a broken page: it
 * loads, it is in the sitemap, and it can rank. It is a page the site itself
 * never recommends, which is how a page ends up crawled rarely, understood
 * poorly and ranked below pages the site does link to.
 *
 * Three decisions shape this report, and they are the same three that shape
 * {@see Thin_Content} — deliberately, because the two answer the same *kind* of
 * question and disagreeing about the shape of the answer would be a worse cost
 * than the duplication.
 *
 * **It counts the links a visitor can follow.** Every edge comes from
 * {@see Link_Index}, which resolves builder content, so a page linked only from
 * an Elementor button is not reported as an orphan. This is why the scan has to
 * be batched, and it is also why the answer differs from what a naive
 * `post_content` grep would give.
 *
 * **It is not part of the Site SEO Analyzer.** That audit reads a bounded
 * sample of recent posts, which is right for a crawl-free score that has to be
 * instant on any site, and wrong for this question: the orphans on a large site
 * are rarely the hundred most recent posts.
 *
 * **Published only, and on demand.** A draft nobody can reach is not orphaned,
 * it is unfinished. The scan runs when the screen asks, a bounded batch at a
 * time.
 *
 * @since 2.22.0
 */
class Orphan_Pages {

    /**
     * Option holding the cached report.
     */
    private const CACHE_OPTION = 'thinkrank_orphan_pages_report';

    /**
     * Statuses the report covers.
     *
     * @var string[]
     */
    private const STATUSES = ['publish'];

    /**
     * Posts listed per post type. The counts above the list are exact; this
     * only bounds how many are named.
     */
    public const MAX_LISTED = 25;

    /**
     * The report, from cache when nothing it depends on has moved.
     *
     * The cache is checked before the index is touched. Every event that can
     * leave a source pending also bumps the index revision (an edit, a status
     * change in either direction, a deletion, a batch of builds), the format
     * version and the post types are in the signature, and only a complete
     * report is ever cached, so a signature match means nothing is pending and
     * the cached answer is current. That keeps the common call, a report read
     * with nothing changed, free of any scan of the posts table.
     *
     * While a backlog remains, a call indexes one bounded batch and reports how
     * many sources are left, without the totals or the per-type lists: those
     * are whole-site queries, and an answer computed over a half-built index
     * would only be thrown away on the next call.
     *
     * @param bool $refresh Rebuild even when the cache is current.
     * @return array<string,mixed>
     */
    public static function report(bool $refresh = false): array {
        $post_types = self::post_types();

        $cached = get_option(self::CACHE_OPTION, null);
        if (!$refresh
            && is_array($cached)
            && ($cached['signature'] ?? null) === self::signature($post_types)
        ) {
            return self::present($cached, 0);
        }

        // Keep filling the index before answering. Each call is bounded, and
        // `pending` tells the caller whether the answer is complete yet.
        $pending = Link_Index::refresh($post_types, self::STATUSES);

        if ($pending > 0) {
            return self::present(['types' => [], 'built_at' => 0], $pending);
        }

        $totals = Link_Index::totals($post_types, self::STATUSES);

        $types = [];
        foreach ($post_types as $post_type) {
            $counted = (int) ($totals[$post_type]['counted'] ?? 0);
            $orphans = (int) ($totals[$post_type]['orphans'] ?? 0);

            // A post type with nothing published is not a finding. Listing it
            // would fill the report with rows that can never change.
            if (0 === $counted) {
                continue;
            }

            $types[] = [
                'post_type' => $post_type,
                'label'     => self::post_type_label($post_type),
                'counted'   => $counted,
                'orphans'   => $orphans,
                'posts'     => $orphans > 0
                    ? Link_Index::orphans($post_type, self::STATUSES, self::MAX_LISTED)
                    : [],
            ];
        }

        // Read after the refresh, which bumps the revision when it builds.
        $report = [
            'signature' => self::signature($post_types),
            'types'     => $types,
            'built_at'  => time(),
        ];

        update_option(self::CACHE_OPTION, $report, false);

        return self::present($report, 0);
    }

    /**
     * What a cached report depends on.
     *
     * The revision covers a reindex and anything that leaves a source pending;
     * the format version covers a change to what counts as a link, which makes
     * every stored entry pending at once without touching the revision; the
     * post type list covers a plugin registering or dropping a post type, which
     * changes both the rows and what counts as an inbound link; the active
     * menus cover a menu being assigned, unassigned or swapped in a template,
     * which changes which stored edges count without touching any of them; the
     * front and posts pages are left out of the candidates by ID.
     *
     * @param string[] $post_types Post types the report covers.
     * @return array<string,mixed>
     */
    private static function signature(array $post_types): array {
        return [
            'revision'   => Link_Index::revision(),
            'version'    => Link_Index::VERSION,
            'post_types' => $post_types,
            'menus'      => Link_Index::active_menu_sources(),
            'front'      => [(int) get_option('page_on_front'), (int) get_option('page_for_posts')],
        ];
    }

    /**
     * Shape a stored report for its caller.
     *
     * @param array<string,mixed> $report  Stored report.
     * @param int                 $pending Posts still to index.
     * @return array<string,mixed>
     */
    private static function present(array $report, int $pending): array {
        $types = self::add_viewer_fields(array_values((array) ($report['types'] ?? [])));

        $orphans = 0;
        $counted = 0;
        foreach ($types as $type) {
            $orphans += (int) ($type['orphans'] ?? 0);
            $counted += (int) ($type['counted'] ?? 0);
        }

        return [
            // Above zero means the scan has not covered the whole site yet, and
            // the report carries no findings until it has: a page looks
            // orphaned until whatever links to it has been indexed.
            'pending'      => max(0, $pending),
            'counted'      => $counted,
            'orphans'      => $orphans,
            'types'        => $types,
            'generated_at' => (int) ($report['built_at'] ?? 0),
            'limits'       => [
                'max_listed' => self::MAX_LISTED,
            ],
        ];
    }

    /**
     * Add the fields that belong to the reader rather than to the report.
     *
     * The cached report is one option shared by every user, so an `edit_url`
     * frozen into it would be handed to whoever asked next — including someone
     * who cannot edit the post, and who would then be sent to an editor screen
     * that refuses them. Resolved per request instead, the same way
     * {@see Thin_Content} resolves them.
     *
     * @param array<int,array<string,mixed>> $types Post type rows.
     * @return array<int,array<string,mixed>>
     */
    private static function add_viewer_fields(array $types): array {
        foreach ($types as $i => $type) {
            foreach ((array) ($type['posts'] ?? []) as $j => $post) {
                $id = (int) ($post['post_id'] ?? 0);

                $types[$i]['posts'][$j]['can_edit'] = $id > 0 && current_user_can('edit_post', $id);
                $types[$i]['posts'][$j]['edit_url'] = $types[$i]['posts'][$j]['can_edit']
                    ? (string) get_edit_post_link($id, 'raw')
                    : '';
            }
        }

        return $types;
    }

    /**
     * Post types the report covers.
     *
     * The same rule {@see Thin_Content::post_types()} applies, and for the same
     * reason: a post type ThinkRank does not manage has no SEO answer to give,
     * and attachments are not pages anyone writes internal links to on purpose.
     *
     * @return string[]
     */
    public static function post_types(): array {
        $post_types = [];

        foreach (get_post_types(['public' => true], 'objects') as $object) {
            if ('attachment' === $object->name || !Global_SEO_Post_Types::is_allowed($object)) {
                continue;
            }

            $post_types[] = $object->name;
        }

        return $post_types;
    }

    /**
     * A post type's plural name, for the report.
     *
     * @param string $post_type Post type.
     * @return string
     */
    private static function post_type_label(string $post_type): string {
        $object = get_post_type_object($post_type);

        if (!$object instanceof \WP_Post_Type) {
            return $post_type;
        }

        return (string) ($object->labels->name ?? $post_type);
    }
}
