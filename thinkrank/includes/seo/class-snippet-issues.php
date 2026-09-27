<?php
/**
 * Snippet issue rules for Bulk Snippets.
 *
 * @package ThinkRank\SEO
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Seo_Text;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Snippet Issues
 *
 * Decides which problems a post's search snippet has — the flags behind Bulk
 * Snippets' "show only posts with a problem" filter (#727).
 *
 * Two definitions matter and are easy to get wrong:
 *
 * - **Empty** means no value of the post's own. An empty
 *   `_thinkrank_seo_title` is not "no title": the post inherits its post type's
 *   template, and that rendered title is what Google sees. So the empty flags
 *   answer "nobody wrote one for this post", and the length flags are judged
 *   on the *effective* value — the same value the editor score judges, via
 *   {@see Pattern_Resolver::effective_title()}.
 * - **Length** bands are the editor's, read from {@see SEOScoreCalculator}, so
 *   a post this screen calls "too long" is one the editor also calls too long.
 *
 * The rule evaluation is a pure function of an already-loaded row, so it is
 * testable without WordPress and cheap to run over a whole post type.
 *
 * @since 2.8.0
 */
class Snippet_Issues {

    public const EMPTY_TITLE           = 'empty_title';
    public const EMPTY_DESCRIPTION     = 'empty_description';
    public const TITLE_TOO_SHORT       = 'title_too_short';
    public const TITLE_TOO_LONG        = 'title_too_long';
    public const DESCRIPTION_TOO_SHORT = 'description_too_short';
    public const DESCRIPTION_TOO_LONG  = 'description_too_long';
    public const NO_FOCUS_KEYWORD      = 'no_focus_keyword';
    public const NOINDEX               = 'noindex';
    public const DUPLICATE_TITLE       = 'duplicate_title';
    public const DUPLICATE_DESCRIPTION = 'duplicate_description';

    /**
     * Every issue, in the order the filter chips show them.
     *
     * @since 2.8.0
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::EMPTY_TITLE,
            self::EMPTY_DESCRIPTION,
            self::TITLE_TOO_SHORT,
            self::TITLE_TOO_LONG,
            self::DESCRIPTION_TOO_SHORT,
            self::DESCRIPTION_TOO_LONG,
            self::NO_FOCUS_KEYWORD,
            self::NOINDEX,
            self::DUPLICATE_TITLE,
            self::DUPLICATE_DESCRIPTION,
        ];
    }

    /**
     * Issues decided by comparing a post against the rest of the site, rather
     * than by looking at the post alone.
     *
     * These carry no bit in the stored flags: the index stores a grouping key
     * for each and the duplicates are found by grouping at query time, so
     * fixing one of two duplicates clears the other without a write to it.
     *
     * @since 2.10.0
     *
     * @return string[]
     */
    public static function grouped(): array {
        return [self::DUPLICATE_TITLE, self::DUPLICATE_DESCRIPTION];
    }

    /**
     * Bit per issue that depends on the post alone.
     *
     * The grouped issues ({@see self::grouped()}) are not here. They sit last
     * in {@see self::all()} so that every other issue keeps the bit it has
     * always had — the order is part of the stored format, so append, never
     * reorder.
     *
     * @since 2.8.0
     *
     * @return array<string,int> Issue => bit.
     */
    public static function flag_bits(): array {
        $grouped = self::grouped();

        $bits = [];
        $position = 0;
        foreach (self::all() as $issue) {
            if (in_array($issue, $grouped, true)) {
                continue;
            }
            $bits[$issue] = 1 << $position;
            $position++;
        }

        return $bits;
    }

    /**
     * Encode issues as a bitmask. Grouped issues are ignored (see flag_bits()).
     *
     * @since 2.8.0
     *
     * @param string[] $issues Issue slugs.
     * @return int
     */
    public static function to_flags(array $issues): int {
        $bits = self::flag_bits();
        $flags = 0;
        foreach ($issues as $issue) {
            $flags |= $bits[$issue] ?? 0;
        }

        return $flags;
    }

    /**
     * Load everything the rules need for one post.
     *
     * Duplicate status is not included — it needs the rest of the site.
     *
     * @since 2.8.0
     *
     * @param \WP_Post $post Post.
     * @return array<string,mixed>
     */
    public static function snapshot(\WP_Post $post): array {
        $post_id  = (int) $post->ID;
        $keywords = Focus_Keywords::get($post_id);
        $robots   = self::robots_state($post_id, $post->post_type);

        return [
            'post'                  => $post,
            'raw_title'             => (string) get_post_meta($post_id, '_thinkrank_seo_title', true),
            'raw_description'       => (string) get_post_meta($post_id, '_thinkrank_meta_description', true),
            'effective_title'       => Pattern_Resolver::effective_title($post_id),
            'effective_description' => Pattern_Resolver::effective_description($post_id),
            'focus_keyword'         => (string) ($keywords[0] ?? ''),
            'noindex'               => $robots['noindex'],
            'noindex_source'        => $robots['source'],
        ];
    }

    /**
     * Which issues a snippet has.
     *
     * @since 2.8.0
     *
     * @param array{
     *     raw_title?: string,
     *     raw_description?: string,
     *     effective_title?: string,
     *     effective_description?: string,
     *     focus_keyword?: string,
     *     noindex?: bool,
     *     duplicate_title?: bool,
     *     duplicate_description?: bool
     * } $row Already-loaded snippet values.
     * @return string[] Issue slugs, in {@see self::all()} order.
     */
    public static function evaluate(array $row): array {
        $issues = [];

        if ('' === trim((string) ($row['raw_title'] ?? ''))) {
            $issues[] = self::EMPTY_TITLE;
        }

        if ('' === trim((string) ($row['raw_description'] ?? ''))) {
            $issues[] = self::EMPTY_DESCRIPTION;
        }

        // Lengths are what Google sees, so they are measured on the effective
        // value — a post inheriting a 90-character template title has a title
        // that is too long even though it has no title of its own. A value
        // that renders to nothing is reported as empty above, not as short.
        // Measured decoded, as the editor's counter measures it: a texturized
        // `&#038;` is one character on the results page, not six.
        $title_length = mb_strlen(trim(Seo_Text::as_displayed((string) ($row['effective_title'] ?? ''))));
        if ($title_length > 0 && $title_length < SEOScoreCalculator::TITLE_OPTIMAL_MIN) {
            $issues[] = self::TITLE_TOO_SHORT;
        } elseif ($title_length > SEOScoreCalculator::TITLE_OPTIMAL_MAX) {
            $issues[] = self::TITLE_TOO_LONG;
        }

        $description_length = mb_strlen(trim(Seo_Text::as_displayed((string) ($row['effective_description'] ?? ''))));
        if ($description_length > 0 && $description_length < SEOScoreCalculator::DESCRIPTION_OPTIMAL_MIN) {
            $issues[] = self::DESCRIPTION_TOO_SHORT;
        } elseif ($description_length > SEOScoreCalculator::DESCRIPTION_OPTIMAL_MAX) {
            $issues[] = self::DESCRIPTION_TOO_LONG;
        }

        if ('' === trim((string) ($row['focus_keyword'] ?? ''))) {
            $issues[] = self::NO_FOCUS_KEYWORD;
        }

        if (!empty($row['noindex'])) {
            $issues[] = self::NOINDEX;
        }

        if (!empty($row['duplicate_title'])) {
            $issues[] = self::DUPLICATE_TITLE;
        }

        if (!empty($row['duplicate_description'])) {
            $issues[] = self::DUPLICATE_DESCRIPTION;
        }

        return $issues;
    }

    /**
     * The key two posts share when they render the same title.
     *
     * This is the *rendered* title, normalized. Until 2.10.0 it was a hash of
     * the inputs that produce a title instead — the stored custom title, or
     * the post's own title when the post type's template was going to supply
     * the rest. That was sound only while duplicates were grouped inside one
     * post type, which is what Bulk Snippets did:
     *
     * - A post and a page can share a post title and still render different
     *   titles, because each post type has its own template. Grouping on the
     *   post title alone reports them as duplicates when they are not (#564).
     * - A template carrying any tag that varies per post beyond `%title%` —
     *   `%category%`, `%date%`, `%author%` — breaks the same assumption inside
     *   a single post type, since two posts with one post title between them
     *   render different titles.
     *
     * Both disappear when the key is what the page actually renders, and the
     * reverse direction improves too: a hand-written title that happens to
     * match what another page's template renders is now matched, where the old
     * key could not see it.
     *
     * There is no cost to resolving, because the caller has already resolved
     * it: {@see self::snapshot()} computes `effective_title` for the length
     * rules, and the index builds both keys from that one snapshot.
     *
     * @since 2.8.0
     * @since 2.10.0 Keyed on the rendered title rather than on its inputs.
     *
     * @param string $effective_title The title the page renders.
     * @return string Grouping key, or '' when there is nothing to compare.
     */
    public static function duplicate_key(string $effective_title): string {
        $normalized = self::normalize_for_comparison($effective_title);

        return '' !== $normalized ? 'title:' . $normalized : '';
    }

    /**
     * The key two posts share when they render the same meta description.
     *
     * The description side has never had an input-comparison option: the
     * default template is `%excerpt%`, which differs for every post and is not
     * recoverable from a short stored string, so two posts inheriting the same
     * template collide only when their excerpts do. The rendered value is the
     * only thing worth comparing — which is now also true of the title, so the
     * two keys are built the same way.
     *
     * A description that renders to nothing returns '' and never groups —
     * "every post without a description" is the empty-description issue, not a
     * duplicate.
     *
     * @since 2.10.0
     *
     * @param string $effective_description The description the page renders.
     * @return string Grouping key, or '' when there is nothing to compare.
     */
    public static function description_duplicate_key(string $effective_description): string {
        $normalized = self::normalize_for_comparison($effective_description);

        return '' !== $normalized ? 'description:' . $normalized : '';
    }

    /**
     * Reduce a rendered value to what a search engine would see as the same
     * string: entities decoded, case folded, and runs of whitespace collapsed
     * to one space.
     *
     * The whitespace half matters more than it looks. An excerpt rebuilt from
     * post content can differ from a hand-written copy of it by a line break
     * alone, and the two snippets are identical on a results page.
     *
     * Decoding comes first. A template title reaches here through
     * get_the_title(), which texturizes "Foo & Bar" into `Foo &#038; Bar`,
     * while the same words typed as an SEO title arrive as `Foo &amp; Bar` or
     * a bare `&`. All three print the same `<title>`, and keying the encoded
     * forms kept them in separate groups. A decoded `&nbsp;` is a no-break
     * space, which the `/u` whitespace class then collapses like any other.
     * Changing this changes every stored key, which is why
     * {@see Snippet_Index::KEY_FORMAT} moved with it.
     *
     * @since 2.10.0
     *
     * @param string $value Rendered title or description.
     * @return string Normalized value, '' when it renders to nothing.
     */
    private static function normalize_for_comparison(string $value): string {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Seo_Text::as_displayed($value))));
    }

    /**
     * Mark which rows share a duplicate key with another row.
     *
     * @since 2.8.0
     *
     * @param array<int,string> $keys Post ID => {@see self::duplicate_key()}.
     * @return array<int,bool> Post ID => whether another post shares its key.
     */
    public static function duplicates(array $keys): array {
        $counts = array_count_values(array_filter($keys, static fn ($key) => '' !== $key));

        $result = [];
        foreach ($keys as $post_id => $key) {
            $result[$post_id] = '' !== $key && ($counts[$key] ?? 0) > 1;
        }

        return $result;
    }

    /**
     * Whether a post is noindexed, and which layer decided it.
     *
     * Mirrors the cascade the frontend applies when it renders the robots tag
     * for a singular post ({@see \ThinkRank\Frontend\SEO_Manager}): site-wide
     * robots settings, then the post type's robots settings when switched on,
     * then the post's own override when switched on. The last layer that sets
     * the flag wins, so a post can be indexable inside a noindexed post type.
     *
     * @since 2.8.0
     *
     * @param int    $post_id   Post ID.
     * @param string $post_type Post type.
     * @return array{noindex: bool, source: string} source is 'post', 'post_type', 'site' or ''.
     */
    public static function robots_state(int $post_id, string $post_type): array {
        $site = get_option('thinkrank_global_robot_meta_settings', []);
        $noindex = is_array($site) && !empty($site['noindex']);
        $source = $noindex ? 'site' : '';

        $global_seo = get_option('thinkrank_global_seo_settings', []);
        $type_settings = is_array($global_seo) ? ($global_seo[$post_type] ?? null) : null;
        if (is_array($type_settings)
            && !empty($type_settings['robots_meta_enabled'])
            && is_array($type_settings['robots_meta'] ?? null)
            && array_key_exists('noindex', $type_settings['robots_meta'])
        ) {
            $noindex = !empty($type_settings['robots_meta']['noindex']);
            $source = $noindex ? 'post_type' : '';
        }

        if ((bool) get_post_meta($post_id, '_thinkrank_robots_meta_enabled', true)) {
            $raw = (string) get_post_meta($post_id, '_thinkrank_robots_meta', true);
            $post_robots = '' !== $raw ? json_decode($raw, true) : null;
            if (is_array($post_robots) && array_key_exists('noindex', $post_robots)) {
                $noindex = !empty($post_robots['noindex']);
                $source = $noindex ? 'post' : '';
            }
        }

        return ['noindex' => $noindex, 'source' => $source];
    }
}
