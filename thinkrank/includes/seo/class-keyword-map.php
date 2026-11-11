<?php
/**
 * Site-level keyword governance: the map and the cannibalization report.
 *
 * @package ThinkRank\SEO
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Settings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keyword Map
 *
 * Which pages target which keywords, which target none, and which keywords more
 * than one page is competing for (#568).
 *
 * The two halves are one data model, which is why they are one report. A
 * keyword map is the list of (page, keyword) pairs; cannibalization is that
 * same list grouped the other way. Building them separately would have meant
 * two indexes that could disagree about what a keyword is.
 *
 * **What this catches that the edit-time warning cannot.** The per-post warning
 * fires while someone types a keyword another post already uses, one keyword at
 * a time. Two things follow from that shape. It cannot enumerate, so on any
 * site older than the warning the conflicts that already exist are invisible —
 * which on a real site is most of them. And it scopes its query to the post
 * type being edited, so a blog post and a product competing for one keyword
 * never warn at all; on a store that is where the conflicts are. This report is
 * scoped to neither.
 *
 * **Search Console is a separate call.** Query-level cannibalization — one
 * query where several of our URLs rank and split the clicks — needs a live
 * round trip to Google, and this report is polled while the index fills. Making
 * the map wait on an external API would have made the common case slow to
 * answer a question half the installs cannot ask at all. {@see search_console()}
 * is its own route, asked once, after.
 *
 * @since 2.21.3
 */
class Keyword_Map {

    /**
     * Option holding the cached report.
     */
    private const CACHE_OPTION = 'thinkrank_keyword_map_report';

    /**
     * Keyword groups listed, and posts named inside each.
     */
    public const MAX_CONFLICTS     = 50;
    public const MAX_POSTS_PER_KEY = 10;

    /**
     * Pages named in the "targets no keyword" list.
     */
    public const MAX_UNTARGETED = 25;

    /**
     * Rows asked of Search Console, and how far back.
     *
     * 25,000 is the API's per-request ceiling. The window matches the one the
     * analytics dashboard uses, minus the two-day reporting delay, so the two
     * screens describe the same period.
     */
    private const GSC_ROW_LIMIT = 25000;
    private const GSC_DAYS      = 28;

    /**
     * Least clicks a competing URL needs before a query is reported.
     *
     * A query where a second URL took one click in a month is noise: every
     * site has hundreds, and listing them buries the handful that matter.
     */
    private const GSC_MIN_CLICKS = 1;

    /**
     * ISO 639-1 language codes, for recognising a translated URL.
     *
     * A translated site ranks `/`, `/de/` and `/ja/` for one query, and those
     * are hreflang alternates doing what they should rather than pages
     * competing. Search Console reports URLs on the property and the alternates
     * are not this site's posts, so they cannot be matched against anything
     * stored — but the URLs themselves say it, because a language directory is
     * the shape every multilingual plugin uses.
     *
     * A real code list rather than "any two letters", so `/tv/`, `/us/` and
     * `/ux/` are left alone: folding a section that is not a language would
     * hide a genuine conflict, which is the more expensive mistake of the two.
     *
     * @var string[]
     */
    private const LANGUAGE_CODES = [
        'aa', 'ab', 'ae', 'af', 'ak', 'am', 'an', 'ar', 'as', 'av', 'ay', 'az',
        'ba', 'be', 'bg', 'bh', 'bi', 'bm', 'bn', 'bo', 'br', 'bs',
        'ca', 'ce', 'ch', 'co', 'cr', 'cs', 'cu', 'cv', 'cy',
        'da', 'de', 'dv', 'dz',
        'ee', 'el', 'en', 'eo', 'es', 'et', 'eu',
        'fa', 'ff', 'fi', 'fj', 'fo', 'fr', 'fy',
        'ga', 'gd', 'gl', 'gn', 'gu', 'gv',
        'ha', 'he', 'hi', 'ho', 'hr', 'ht', 'hu', 'hy', 'hz',
        'ia', 'id', 'ie', 'ig', 'ii', 'ik', 'io', 'is', 'it', 'iu',
        'ja', 'jv',
        'ka', 'kg', 'ki', 'kj', 'kk', 'kl', 'km', 'kn', 'ko', 'kr', 'ks', 'ku',
        'kv', 'kw', 'ky',
        'la', 'lb', 'lg', 'li', 'ln', 'lo', 'lt', 'lu', 'lv',
        'mg', 'mh', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt', 'my',
        'na', 'nb', 'nd', 'ne', 'ng', 'nl', 'nn', 'no', 'nr', 'nv', 'ny',
        'oc', 'oj', 'om', 'or', 'os',
        'pa', 'pi', 'pl', 'ps', 'pt',
        'qu',
        'rm', 'rn', 'ro', 'ru', 'rw',
        'sa', 'sc', 'sd', 'se', 'sg', 'si', 'sk', 'sl', 'sm', 'sn', 'so', 'sq',
        'sr', 'ss', 'st', 'su', 'sv', 'sw',
        'ta', 'te', 'tg', 'th', 'ti', 'tk', 'tl', 'tn', 'to', 'tr', 'ts', 'tt',
        'tw', 'ty',
        'ug', 'uk', 'ur', 'uz',
        've', 'vi', 'vo',
        'wa', 'wo',
        'xh',
        'yi', 'yo',
        'za', 'zh', 'zu',
    ];

    /**
     * The report, from cache when nothing it depends on has moved.
     *
     * The cache is checked before the index is touched. Every event that can
     * leave a post pending also bumps the index revision (a keyword edit, a
     * move into or out of the scope, a deletion, a batch of builds), the
     * format version and the post types are in the signature, and only a
     * complete report is ever cached. So a signature match means nothing is
     * pending, and the common call, the map read again with nothing changed,
     * runs no scan of the posts table at all.
     *
     * Every read first re-checks a bounded batch of entries against the meta
     * they were built from ({@see Keyword_Index::verify()}). The hooks only see
     * writes made while ThinkRank is loaded; a keyword written around them
     * leaves a marker that no longer matches, and retiring it bumps the
     * revision, so the cache check below already sees the change.
     *
     * @param bool $refresh Rebuild even when the cache is current.
     * @return array<string,mixed>
     */
    public static function report(bool $refresh = false): array {
        $post_types = self::post_types();

        Keyword_Index::verify();

        // A user who cannot read every post in scope gets a report built over
        // the posts they can, and it is never cached: the cache is one option
        // shared by every reader, so it only ever holds the unrestricted map.
        // Whichever map is served, present() then checks every listed post
        // with read_post for this reader ({@see readable_conflicts()}).
        $visibility = Keyword_Index::visibility_sql($post_types);

        $cached  = get_option(self::CACHE_OPTION, null);
        $current = is_array($cached) && ($cached['signature'] ?? null) === self::signature($post_types);

        if ($current && !$refresh && '' === $visibility) {
            return self::present($cached, 0);
        }

        // Keep filling the index before answering. Each call is bounded, and
        // `pending` tells the caller whether the answer is complete yet. A
        // current cache already proves there is nothing to fill.
        $pending = $current ? 0 : Keyword_Index::refresh($post_types);

        if ('' !== $visibility) {
            return self::present(self::build($post_types, $visibility, null), $pending);
        }

        // Read after the refresh, which bumps the revision when it builds.
        $report = self::build($post_types, '', self::signature($post_types));

        // Only a complete scan is worth caching: a partial one would be served
        // as the answer long after the index finished filling.
        if (0 === $pending) {
            update_option(self::CACHE_OPTION, $report, false);
        }

        return self::present($report, $pending);
    }

    /**
     * What a cached report depends on.
     *
     * The revision covers a reindex and anything that leaves a post pending;
     * the format version covers a change to the index format, which makes
     * every entry pending at once without touching the revision; the post
     * type list covers a plugin registering or dropping a post type.
     *
     * @param string[] $post_types Post types in scope.
     * @return array<string,mixed>
     */
    private static function signature(array $post_types): array {
        return [
            'revision'   => Keyword_Index::revision(),
            'version'    => Keyword_Index::VERSION,
            'post_types' => $post_types,
        ];
    }

    /**
     * Compute the report over the posts a visibility fragment admits.
     *
     * @param string[]                 $post_types Post types in scope.
     * @param string                   $visibility Trusted WHERE fragment from
     *                                             {@see Keyword_Index::visibility_sql()},
     *                                             '' for every post.
     * @param array<string,mixed>|null $signature  Cache signature, null when
     *                                             the report is not cached.
     * @return array<string,mixed>
     */
    private static function build(array $post_types, string $visibility, ?array $signature): array {
        $groups = Keyword_Index::duplicates($post_types, self::MAX_CONFLICTS, $visibility);

        // Every group's posts in one query rather than one per group.
        $posts = Keyword_Index::posts_for_many(
            array_column($groups, 'posts', 'hash'),
            $post_types,
            self::MAX_POSTS_PER_KEY,
            $visibility
        );

        $conflicts = [];

        foreach ($groups as $group) {
            $conflicts[] = [
                'keyword' => $group['keyword'],
                'hash'    => $group['hash'],
                'posts'   => $posts[$group['hash']] ?? [],
                // The group's real size, which the list above may be shorter
                // than. A reader seeing ten pages under a keyword used by
                // fourteen should be told, not left to count.
                'total'   => $group['posts'],
            ];
        }

        return [
            'signature'  => $signature,
            'totals'     => Keyword_Index::totals($post_types, $visibility),
            'conflicts'  => $conflicts,
            'untargeted' => Keyword_Index::untargeted($post_types, self::MAX_UNTARGETED, $visibility),
            'built_at'   => time(),
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
        $totals    = (array) ($report['totals'] ?? []);
        $conflicts = self::readable_conflicts((array) ($report['conflicts'] ?? []));

        // A group the reader cannot see is not counted as one either.
        $conflicted = max(0, (int) ($totals['conflicted'] ?? 0) - $conflicts['dropped']);

        return [
            // Above zero means the scan has not covered the whole site yet, so
            // every number below is a floor: a conflict cannot be seen until
            // both of its posts have been indexed.
            'pending'      => max(0, $pending),
            'indexed'      => (int) ($totals['indexed'] ?? 0),
            'targeted'     => (int) ($totals['targeted'] ?? 0),
            'untargeted'   => (int) ($totals['untargeted'] ?? 0),
            'keywords'     => (int) ($totals['keywords'] ?? 0),
            'conflicted'   => $conflicted,
            'conflicts'    => $conflicts['groups'],
            'untargeted_posts' => self::add_edit_fields((array) ($report['untargeted'] ?? [])),
            'statuses'     => Keyword_Index::STATUSES,
            'generated_at' => (int) ($report['built_at'] ?? 0),
            'limits'       => [
                'max_conflicts'     => self::MAX_CONFLICTS,
                'max_posts_per_key' => self::MAX_POSTS_PER_KEY,
                'max_untargeted'    => self::MAX_UNTARGETED,
            ],
        ];
    }

    /**
     * The conflict groups as this reader may see them.
     *
     * The SQL scope is core's rule for who reads what. A plugin can narrow it
     * through map_meta_cap, which no query can see, and the cached report is
     * shared by every reader the SQL scope does not restrict. So each listed
     * post is checked with `read_post` here, on every request, cached report
     * or not, and the group is rebuilt from the posts that pass:
     *
     * - A group left with fewer than two readable posts is dropped whole, its
     *   keyword, hash and size included. A conflict between posts the reader
     *   cannot open is not theirs to see.
     * - When any listed post was refused, the group's total is the readable
     *   count, not the size the index counted, which would otherwise say how
     *   many hidden posts share the keyword.
     *
     * @param array<int,array<string,mixed>> $conflicts Conflict groups.
     * @return array{groups:array<int,array<string,mixed>>, dropped:int}
     */
    private static function readable_conflicts(array $conflicts): array {
        $groups  = [];
        $dropped = 0;

        foreach ($conflicts as $conflict) {
            $listed   = (array) ($conflict['posts'] ?? []);
            $readable = self::add_edit_fields($listed);

            if (count($readable) < 2) {
                $dropped++;
                continue;
            }

            $conflict['posts'] = $readable;

            if (count($readable) < count($listed)) {
                $conflict['total'] = count($readable);
            }

            $groups[] = $conflict;
        }

        return [
            'groups'  => $groups,
            'dropped' => $dropped,
        ];
    }

    /**
     * Add the fields that belong to the reader rather than to the report, and
     * drop the rows the reader may not read.
     *
     * The cached report is one option shared by every user, so an `edit_url`
     * frozen into it would be handed to whoever asked next — including someone
     * who cannot edit the post. Resolved per request instead, as
     * {@see Thin_Content} and {@see Orphan_Pages} both do.
     *
     * @param array<int,array<string,mixed>> $posts Post rows.
     * @return array<int,array<string,mixed>>
     */
    private static function add_edit_fields(array $posts): array {
        foreach ($posts as $i => $post) {
            $id = (int) ($post['post_id'] ?? 0);

            // The SQL scope above is core's rule; a plugin can narrow it
            // further through map_meta_cap, which no query can see. A row the
            // reader may not read is dropped rather than named.
            if ($id <= 0 || !current_user_can('read_post', $id)) {
                unset($posts[$i]);
                continue;
            }

            $posts[$i]['can_edit'] = $id > 0 && current_user_can('edit_post', $id);
            $posts[$i]['edit_url'] = $posts[$i]['can_edit']
                ? (string) get_edit_post_link($id, 'raw')
                : '';
        }

        return array_values($posts);
    }

    /**
     * Queries where more than one of our URLs ranks.
     *
     * The other kind of cannibalization, and the one no amount of reading our
     * own database can find: two pages can target different keywords and still
     * compete for the same query, and only Search Console knows.
     *
     * Not cached. It is one call the user asks for, the data moves daily, and
     * the report it belongs to is already cached against the index — caching a
     * Google response behind that would produce a screen whose two halves
     * describe different weeks.
     *
     * @param Analytics_Manager|null $manager Manager to ask; a new one when null.
     * @return array<string,mixed>
     */
    public static function search_console(?Analytics_Manager $manager = null): array {
        if (!self::search_console_configured()) {
            return [
                'connected' => false,
                // Said plainly rather than returned as an empty list, which a
                // screen would render as "no cannibalization found" — the one
                // wrong answer available here.
                'reason'    => __('Search Console is not connected, so query-level conflicts cannot be checked. The keyword conflicts above are found without it.', 'thinkrank'),
                'queries'   => [],
                'window'    => self::gsc_window(),
            ];
        }

        $manager = $manager ?? new Analytics_Manager();

        // Connected but no property picked yet: Google would answer with its
        // own "'http://' is not a valid Search Console site URL", reported here
        // as an outage. Name the step the admin is missing instead.
        if (!$manager->has_search_console_property()) {
            return [
                'connected' => true,
                'reason'    => __('No Search Console property is selected for this site, so query-level conflicts cannot be checked. Choose your verified property in ThinkRank\'s Google integration settings.', 'thinkrank'),
                'queries'   => [],
                'window'    => self::gsc_window(),
            ];
        }

        try {
            $rows = self::search_console_rows($manager);
        } catch (\Throwable $e) {
            // The client throws rather than returning an error, and a Google
            // outage or a revoked token must not take the screen down with it:
            // the DB half of this report is still valid and still useful.
            return [
                'connected' => true,
                'reason'    => sprintf(
                    /* translators: %s: the error Google returned. */
                    __('Search Console could not be reached: %s', 'thinkrank'),
                    $e->getMessage()
                ),
                'queries'   => [],
                'window'    => self::gsc_window(),
            ];
        }

        return [
            'connected' => true,
            'reason'    => '',
            'queries'   => self::split_queries($rows),
            'window'    => self::gsc_window(),
        ];
    }

    /**
     * The window every branch reports.
     *
     * On the failure branches too: a caller reading `window.days` to label the
     * screen should not have to handle its absence, and the window is a
     * property of this report rather than of the call succeeding.
     *
     * @return array{days:int, row_limit:int}
     */
    private static function gsc_window(): array {
        return [
            'days'      => self::GSC_DAYS,
            'row_limit' => self::GSC_ROW_LIMIT,
        ];
    }

    /**
     * Whether Search Console can be called at all.
     *
     * Exactly the predicate `get-integrations-status` reports, read from the
     * same setting, so the two cannot disagree about whether this site is
     * connected. The key matters: the OAuth connect flow stores
     * `google_access_token` and nothing writes the `*_api_key` options any
     * more, and keying off the wrong one is how an OAuth-connected site came
     * to report every integration as unconfigured (#519).
     *
     * @return bool
     */
    public static function search_console_configured(): bool {
        $settings = Settings::instance();

        return '' !== (string) $settings->get('google_access_token', '')
            || '' !== (string) $settings->get('google_search_console_api_key', '');
    }

    /**
     * Ask Search Console for query-and-page rows.
     *
     * Through {@see Analytics_Manager::get_search_analytics_client()} rather
     * than by constructing a client here, so the OAuth token is refreshed if
     * it has expired and the timeout is the one the site configured.
     *
     * @param Analytics_Manager $manager Manager holding the client and property.
     * @return array<int,array<string,mixed>>
     * @throws \RuntimeException When the client cannot be built. The client's
     *                            own failures propagate as its exceptions.
     */
    private static function search_console_rows(Analytics_Manager $manager): array {
        $client  = $manager->get_search_analytics_client();

        if (!$client instanceof \ThinkRank\Integrations\Google_Search_Analytics_Client) {
            throw new \RuntimeException('The Search Console client could not be initialised.');
        }

        // The same two-day reporting delay the analytics dashboard applies, so
        // both screens describe the same window rather than one of them ending
        // on days Google has no data for yet.
        $end   = gmdate('Y-m-d', strtotime('-2 days'));
        $start = gmdate('Y-m-d', strtotime('-' . (self::GSC_DAYS - 1) . ' days', strtotime($end)));

        $response = $client->get_search_analytics_data(
            $manager->get_property_url(),
            $start,
            $end,
            // Both dimensions. The dashboard asks for `query` alone, which
            // cannot answer this: one row per query hides the fact that the
            // clicks came from three different pages.
            ['query', 'page'],
            self::GSC_ROW_LIMIT
        );

        return (array) ($response['rows'] ?? []);
    }

    /**
     * Group Search Console rows into queries served by more than one page.
     *
     * By *page*, not by URL, and the difference decides whether this report is
     * useful on a translated site. Against a real property, four of the seven
     * queries this returned were one page ranking in five languages: `/`,
     * `/pt/`, `/de/`, `/fr/` and `/vi/` are hreflang alternates doing exactly
     * what they should, and calling them a five-way split is a false finding of
     * the kind that teaches a reader to ignore the report. Two more had an
     * alternate sitting among their genuine competitors, inflating the count.
     *
     * So a leading language directory is folded away before anything is
     * counted, and `competing` counts distinct pages. A query whose competitors
     * all collapse to one page is not reported at all. The URLs themselves are
     * still listed, because the reader needs them, and `alternates` says how
     * many of them were the same page in another language.
     *
     * What this cannot fold is a language kept in a subdomain or a query
     * parameter, or a translation whose slug is translated too. Those stay as
     * rows to recognise and dismiss.
     *
     * @param array<int,array<string,mixed>> $rows Rows, each with keys[query, page].
     * @return array<int,array<string,mixed>>
     */
    private static function split_queries(array $rows): array {
        $grouped = [];

        foreach ($rows as $row) {
            $keys = (array) ($row['keys'] ?? []);
            if (count($keys) < 2) {
                continue;
            }

            $query = (string) $keys[0];
            $page  = (string) $keys[1];

            if (!isset($grouped[$query])) {
                $grouped[$query] = ['query' => $query, 'clicks' => 0.0, 'pages' => []];
            }

            $clicks = (float) ($row['clicks'] ?? 0);

            $grouped[$query]['clicks']  += $clicks;
            $grouped[$query]['pages'][] = [
                'page'        => $page,
                'clicks'      => $clicks,
                'impressions' => (float) ($row['impressions'] ?? 0),
                'position'    => round((float) ($row['position'] ?? 0), 1),
            ];
        }

        $split = [];

        foreach ($grouped as $group) {
            // More than one URL, and more than one of them actually earning
            // something. A query where the second URL took no clicks at all is
            // not splitting anything.
            $earning = array_filter(
                $group['pages'],
                static fn(array $page): bool => $page['clicks'] >= self::GSC_MIN_CLICKS
            );

            if (count($group['pages']) < 2 || count($earning) < 2) {
                continue;
            }

            // Distinct pages rather than distinct URLs: one page ranking in
            // five languages is not a five-way split.
            $distinct = [];
            foreach ($earning as $page) {
                $distinct[self::canonical_page($page['page'])] = true;
            }

            if (count($distinct) < 2) {
                continue;
            }

            usort(
                $earning,
                static fn(array $a, array $b): int => $b['clicks'] <=> $a['clicks']
            );

            $split[] = [
                'query'     => $group['query'],
                'clicks'    => (int) round($group['clicks']),
                // Every URL Google returned for the query, most of which
                // typically earned nothing, and `competing` for the ones that
                // actually split the clicks. Reported separately because they
                // are wildly different numbers on a real property: a query
                // with 71 ranking URLs and 4 earning ones is a four-way split
                // with a long tail, not a 71-way problem.
                'urls'      => count($group['pages']),
                // Distinct pages earning clicks. Counting URLs here reported a
                // translated home page as competing with itself.
                'competing' => count($distinct),
                // Earning URLs that were the same page in another language, so
                // a screen can say why it lists more URLs than competitors.
                'alternates' => count($earning) - count($distinct),
                // The earning URLs, not the first ten of everything. A list
                // padded with zero-click rows buries the split it is meant to
                // show; verified against a real property, where the top ten by
                // click count were eight zeroes.
                'pages'     => array_slice(array_values($earning), 0, self::MAX_POSTS_PER_KEY),
            ];
        }

        usort($split, static fn(array $a, array $b): int => $b['clicks'] <=> $a['clicks']);

        return array_slice($split, 0, self::MAX_CONFLICTS);
    }

    /**
     * The page a Search Console URL refers to, with a language directory
     * removed.
     *
     * `/fr/blog/x/` and `/blog/x/` are one page in two languages; `/tv/blog/x/`
     * is a section called `tv`, and is left alone because `tv` is not a
     * language code. A region suffix is accepted, since `pt-br` and `zh_hans`
     * are both shapes plugins write.
     *
     * @param string $url Absolute URL as Search Console reported it.
     * @return string Path, always starting with a slash.
     */
    private static function canonical_page(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        if ('' === $path) {
            return '/';
        }

        $segments = explode('/', trim($path, '/'));
        $first    = strtolower((string) ($segments[0] ?? ''));

        // Split a region off before testing, so `pt-br` is recognised by `pt`.
        $language = preg_split('/[-_]/', $first)[0] ?? '';

        if (in_array($language, self::LANGUAGE_CODES, true)) {
            array_shift($segments);
        }

        $rest = implode('/', $segments);

        return '' === $rest ? '/' : '/' . $rest . '/';
    }

    /**
     * Post types the map covers.
     *
     * The same rule {@see Thin_Content::post_types()} and
     * {@see Orphan_Pages::post_types()} apply: a post type ThinkRank does not
     * manage has no focus keyword to map, and an attachment is not something
     * anyone assigns one to.
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
}
