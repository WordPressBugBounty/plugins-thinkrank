<?php
/**
 * Indexability
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.14.1
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a post or term is a destination worth submitting to a search engine.
 *
 * The robots tag a page prints was the only place that decided indexability.
 * Every URL feed re-decided it partially or not at all: the sitemap honoured a
 * per-post noindex but not a noindexed post type or taxonomy, and listed URLs
 * ThinkRank itself redirects; IndexNow checked nothing beyond the post type
 * (#911). Each of those contradicts a signal the site sends elsewhere, which
 * Search Console reports as "Submitted URL marked noindex" and "Page with
 * redirect".
 *
 * So this class answers the question once, from the same inputs the robots tag
 * reads, and every feed asks it. It is public so ThinkRank Pro's feeds (news
 * and video sitemaps, llms-full.txt, internal-link targets) can use it too.
 *
 * Four things make an object a non-destination:
 * - the effective robots decision is noindex, resolved through the cascade
 *   {@see \ThinkRank\Frontend\SEO_Manager} uses for the robots tag: site-wide
 *   directives, then the content type's row in the matrix, then the object's
 *   own override, each merged over the last;
 * - the post has a password;
 * - the object redirects elsewhere, either through its own "Redirect this to"
 *   field ({@see Object_Redirect}) or because a redirect provider answers the
 *   `thinkrank_url_redirects` filter for its URL;
 * - its canonical override names another URL, so the page itself tells search
 *   engines to index that one instead.
 *
 * @since 2.14.1
 */
final class Indexability {

    /**
     * Site-wide robots directives (the base of the cascade).
     */
    private const GLOBAL_ROBOTS_OPTION = 'thinkrank_global_robot_meta_settings';

    /**
     * Robots keys a per-object override may set, as the robots tag reads them.
     */
    private const OVERRIDE_KEYS = ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'];

    /**
     * Reasons an object is not indexable.
     */
    public const REASON_NOINDEX  = 'noindex';
    public const REASON_PASSWORD = 'password';
    public const REASON_REDIRECT = 'redirect';
    public const REASON_CANONICAL = 'canonical';

    /**
     * Meta key holding a post's or term's canonical override.
     */
    private const CANONICAL_META = '_thinkrank_canonical_url';

    /**
     * Whether a post or term may be submitted to search engines.
     *
     * @since 2.14.1
     *
     * @param \WP_Post|\WP_Term $item Post or term.
     * @return bool
     */
    public static function is_indexable_destination($item): bool {
        if ($item instanceof \WP_Post) {
            return self::is_indexable_post($item);
        }

        if ($item instanceof \WP_Term) {
            return self::is_indexable_term($item);
        }

        return false;
    }

    /**
     * Whether a post may be submitted to search engines.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_indexable_post(\WP_Post $post): bool {
        return self::post_exclusion_reason($post) === null;
    }

    /**
     * Whether a term archive may be submitted to search engines.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_indexable_term(\WP_Term $term): bool {
        return self::term_exclusion_reason($term) === null;
    }

    /**
     * Why a post is not indexable, or null when it is.
     *
     * Cheapest check first: the password is on the object, the robots cascade
     * reads options and primed meta, and a redirect lookup may query the
     * redirect provider's table.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return string|null One of the REASON_* constants, or null.
     */
    public static function post_exclusion_reason(\WP_Post $post): ?string {
        if (self::is_password_protected($post)) {
            return self::REASON_PASSWORD;
        }

        if (self::is_post_noindexed($post)) {
            return self::REASON_NOINDEX;
        }

        if (self::is_post_redirected($post)) {
            return self::REASON_REDIRECT;
        }

        if (self::is_post_canonicalized_away($post)) {
            return self::REASON_CANONICAL;
        }

        return null;
    }

    /**
     * Why a term is not indexable, or null when it is.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return string|null One of the REASON_* constants, or null.
     */
    public static function term_exclusion_reason(\WP_Term $term): ?string {
        if (self::is_term_noindexed($term)) {
            return self::REASON_NOINDEX;
        }

        if (self::is_term_redirected($term)) {
            return self::REASON_REDIRECT;
        }

        if (self::is_term_canonicalized_away($term)) {
            return self::REASON_CANONICAL;
        }

        return null;
    }

    /**
     * Whether the post has a password.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_password_protected(\WP_Post $post): bool {
        return (string) $post->post_password !== '';
    }

    /**
     * Whether the robots tag on this post's page says noindex.
     *
     * Site-wide directives, then the post type's own (when its robots switch
     * is on; the "Blog posts index" row for the static posts page), then the
     * post's override. A post-level `noindex: false` beats a
     * noindexed type, exactly as it does in the tag.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_post_noindexed(\WP_Post $post): bool {
        // The static posts page is served as the blog listing, so its tag reads
        // the "Blog posts index" row, not the page row. Reading the page row
        // here listed a posts page the tag noindexed (#1321).
        $entity_key = self::is_static_posts_page($post)
            ? Content_Type_Settings::ENTITY_BLOG_INDEX
            : (string) $post->post_type;

        $settings = self::merge_entity_robots(self::global_robots(), $entity_key);

        $settings = self::merge_override(
            $settings,
            (bool) get_post_meta($post->ID, '_thinkrank_robots_meta_enabled', true),
            get_post_meta($post->ID, '_thinkrank_robots_meta', true)
        );

        return !empty($settings['noindex']);
    }

    /**
     * Whether the robots tag on the blog posts index says noindex.
     *
     * The listing has no object of its own to carry an override: the tag reads
     * the site-wide directives, then the "Blog posts index" row. On a site that
     * shows its latest posts this is the homepage.
     *
     * @since 2.18.1
     *
     * @return bool
     */
    public static function is_blog_index_noindexed(): bool {
        $settings = self::merge_entity_robots(self::global_robots(), Content_Type_Settings::ENTITY_BLOG_INDEX);

        return !empty($settings['noindex']);
    }

    /**
     * Whether the post is the page WordPress serves as the blog posts index.
     *
     * @since 2.19.0
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private static function is_static_posts_page(\WP_Post $post): bool {
        if ($post->post_type !== 'page' || get_option('show_on_front') !== 'page') {
            return false;
        }

        $posts_page = (int) get_option('page_for_posts');

        return $posts_page > 0 && $posts_page === (int) $post->ID;
    }

    /**
     * Whether the robots tag on this term's archive says noindex.
     *
     * Site-wide directives, then the taxonomy's matrix row, then the term's
     * override.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_term_noindexed(\WP_Term $term): bool {
        $settings = self::merge_entity_robots(
            self::global_robots(),
            Content_Type_Settings::PREFIX_TAXONOMY . $term->taxonomy
        );

        $settings = self::merge_override(
            $settings,
            (bool) get_term_meta($term->term_id, '_thinkrank_robots_meta_enabled', true),
            get_term_meta($term->term_id, '_thinkrank_robots_meta', true)
        );

        return !empty($settings['noindex']);
    }

    /**
     * Whether a request for this post is redirected elsewhere.
     *
     * @since 2.14.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_post_redirected(\WP_Post $post): bool {
        if (Object_Redirect::get('post', (int) $post->ID)['url'] !== '') {
            return true;
        }

        $url = get_permalink($post);

        return is_string($url) && $url !== '' && self::url_is_redirected($url, $post);
    }

    /**
     * Whether a request for this term's archive is redirected elsewhere.
     *
     * @since 2.14.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_term_redirected(\WP_Term $term): bool {
        if (Object_Redirect::get('term', (int) $term->term_id)['url'] !== '') {
            return true;
        }

        $url = get_term_link($term);

        return is_string($url) && $url !== '' && self::url_is_redirected($url, $term);
    }

    /**
     * Whether the post's canonical override points at another URL.
     *
     * The canonical tag prints the override as typed, so a post canonicalised
     * to another page asks search engines to index that page instead. Listing
     * it in the sitemap contradicted the page (#1320). A canonical that is the
     * post's own permalink, in any scheme or with or without the trailing
     * slash, keeps it.
     *
     * @since 2.18.1
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    public static function is_post_canonicalized_away(\WP_Post $post): bool {
        $canonical = Absolute_Url::from((string) get_post_meta($post->ID, self::CANONICAL_META, true));
        if ($canonical === '') {
            return false;
        }

        $permalink = get_permalink($post);

        return is_string($permalink) && $permalink !== '' && !self::is_same_url($canonical, $permalink);
    }

    /**
     * Whether the term's canonical override points at another URL.
     *
     * Mirrors the archive canonical, which ignores an override that is just
     * the archive's own link.
     *
     * @since 2.18.1
     *
     * @param \WP_Term $term Term.
     * @return bool
     */
    public static function is_term_canonicalized_away(\WP_Term $term): bool {
        $canonical = Absolute_Url::from((string) get_term_meta($term->term_id, self::CANONICAL_META, true));
        if ($canonical === '') {
            return false;
        }

        $link = get_term_link($term);

        return is_string($link) && $link !== '' && !self::is_same_url($canonical, $link);
    }

    /**
     * Whether two URLs name the same page: host, port, path with or without
     * the trailing slash, and query. The scheme is ignored, since the
     * canonical tag normalises it to the site's own.
     *
     * @param string $a URL.
     * @param string $b URL.
     * @return bool
     */
    private static function is_same_url(string $a, string $b): bool {
        $key = static function (string $url): ?string {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return null;
            }

            return strtolower((string) $parts['host'])
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . trailingslashit((string) ($parts['path'] ?? '/'))
                . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        };

        $key_a = $key($a);

        return $key_a !== null && $key_a === $key($b);
    }

    /**
     * Ask the redirect provider whether a URL is redirected.
     *
     * @param string            $url    The object's URL.
     * @param \WP_Post|\WP_Term $item The object it belongs to.
     * @return bool
     */
    private static function url_is_redirected(string $url, $item): bool {
        /**
         * Filter whether a URL is redirected by a rule that is not tied to an object.
         *
         * Per-object redirects are already read through `thinkrank_object_redirect`.
         * This covers the rest: a rule whose source path happens to be a post's
         * or term's URL. Nothing answers it in the free plugin; a redirect
         * provider such as ThinkRank Pro does, from its own rule store.
         *
         * @since 2.14.1
         *
         * @param bool              $redirected Whether the URL redirects. Default false.
         * @param string            $url        The post's permalink or the term's archive link.
         * @param \WP_Post|\WP_Term $item     The object the URL belongs to.
         */
        return (bool) apply_filters('thinkrank_url_redirects', false, $url, $item);
    }

    /**
     * Site-wide robots directives with the same defaults the robots tag uses.
     *
     * @return array
     */
    private static function global_robots(): array {
        $stored = get_option(self::GLOBAL_ROBOTS_OPTION, []);

        return array_merge(
            ['index' => true, 'noindex' => false],
            is_array($stored) ? $stored : []
        );
    }

    /**
     * Merge a matrix row's robots directives when its robots switch is on.
     *
     * @param array  $settings   Directives so far.
     * @param string $entity_key Content_Type_Settings entity key.
     * @return array
     */
    private static function merge_entity_robots(array $settings, string $entity_key): array {
        $entity = Content_Type_Settings::get_entity_settings($entity_key);

        if (!empty($entity['robots_meta_enabled']) && is_array($entity['robots_meta'] ?? null)) {
            $settings = array_merge($settings, $entity['robots_meta']);
        }

        return $settings;
    }

    /**
     * Merge an object's stored robots override when it is switched on.
     *
     * @param array $settings Directives so far.
     * @param bool  $enabled  The object's `_thinkrank_robots_meta_enabled`.
     * @param mixed $raw      The object's `_thinkrank_robots_meta` JSON.
     * @return array
     */
    private static function merge_override(array $settings, bool $enabled, $raw): array {
        if (!$enabled || !is_string($raw) || $raw === '') {
            return $settings;
        }

        $override = json_decode($raw, true);
        if (!is_array($override)) {
            return $settings;
        }

        return array_merge($settings, array_intersect_key($override, array_flip(self::OVERRIDE_KEYS)));
    }
}
