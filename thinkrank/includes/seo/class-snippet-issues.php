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
        ];
    }

    /**
     * Bit per issue that depends on the post alone.
     *
     * Duplicate title is not here: it depends on the other posts, so the index
     * stores a key for it and finds duplicates by grouping at query time. The
     * order is part of the stored format — append, never reorder.
     *
     * @since 2.8.0
     *
     * @return array<string,int> Issue => bit.
     */
    public static function flag_bits(): array {
        $bits = [];
        $position = 0;
        foreach (self::all() as $issue) {
            if (self::DUPLICATE_TITLE === $issue) {
                continue;
            }
            $bits[$issue] = 1 << $position;
            $position++;
        }

        return $bits;
    }

    /**
     * Encode issues as a bitmask. Duplicate title is ignored (see flag_bits()).
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
     * Duplicate status is not included — it needs the rest of the post type.
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
     *     duplicate_title?: bool
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
        $title_length = mb_strlen(trim((string) ($row['effective_title'] ?? '')));
        if ($title_length > 0 && $title_length < SEOScoreCalculator::TITLE_OPTIMAL_MIN) {
            $issues[] = self::TITLE_TOO_SHORT;
        } elseif ($title_length > SEOScoreCalculator::TITLE_OPTIMAL_MAX) {
            $issues[] = self::TITLE_TOO_LONG;
        }

        $description_length = mb_strlen(trim((string) ($row['effective_description'] ?? '')));
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

        return $issues;
    }

    /**
     * The key two posts share when they render the same title.
     *
     * Duplicate detection has to work without resolving every title on the
     * site, so it compares the *inputs* that produce a title rather than the
     * output, case-insensitively (a search engine does not care about case):
     *
     * - a custom title with no variable tags is the title itself;
     * - a custom title with tags renders from the tags plus the post's own
     *   title, so both are the key;
     * - no custom title means the post type's template, which is the same for
     *   every post of the type, so the post's title is the key.
     *
     * Equal inputs give equal titles. The reverse is not guaranteed — a custom
     * "Foo – Site" and a template that happens to render the same string are
     * not matched — which errs toward missing a duplicate, never toward
     * inventing one.
     *
     * @since 2.8.0
     *
     * @param string $raw_title  Stored `_thinkrank_seo_title` (may be empty).
     * @param string $post_title The post's own title.
     * @return string Grouping key, or '' when there is nothing to compare.
     */
    public static function duplicate_key(string $raw_title, string $post_title): string {
        $raw_title  = mb_strtolower(trim($raw_title));
        $post_title = mb_strtolower(trim($post_title));

        if ('' !== $raw_title) {
            return false === strpos($raw_title, '%')
                ? 'custom:' . $raw_title
                : 'tagged:' . $raw_title . '|' . $post_title;
        }

        return '' !== $post_title ? 'template:' . $post_title : '';
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
