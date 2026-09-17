<?php
/**
 * Refuses to serve a page for a query variable that resolved to nothing.
 *
 * @package ThinkRank
 * @since   2.7.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a fabricated query variable into a 404 instead of an indexable page.
 *
 * Anyone can append a content selector to a URL. WordPress 404s most of them on
 * its own, but a handful slip through and answer with the blog listing at HTTP
 * 200, indexable, with unbounded variations available to anyone who wants to
 * mint them. Measured on a stock install, these six do:
 *
 *     /?post_type=<junk>    /?taxonomy=<junk>     /?tag_id=<junk>
 *     /?cpage=<junk>        /?static=<junk>       /?author_name=<junk>
 *
 * The canonical tag already points each of them at the clean home URL, so the
 * duplicate-content signal was covered. What it does not cover is that every one
 * of them is a real 200 a crawler has to fetch, and there is no limit to how
 * many a hostile actor can produce.
 *
 * **The detection deliberately does not test `is_home()`.** That reads
 * differently depending on whether the site shows posts or a static page on the
 * front, and a guard whose behaviour depends on that setting would be right on
 * half of all installs. It tests the thing that is actually wrong instead: a
 * request asked for specific content by name, and nothing resolved.
 *
 * Every condition below is an exclusion that was verified against a live
 * install rather than assumed. The one that does most of the work is
 * {@see self::EXPLOITABLE}: an unregistered parameter — `?utm_source=`,
 * `?fbclid=`, a tracking tag, anything a campaign or a plugin appends — is not
 * on that list and can never trip this, which is what keeps a marketing URL
 * from turning into a 404.
 *
 * @since 2.7.0
 */
class Query_Guard {

    /**
     * Query variables that name specific content.
     *
     * If one of these is in the request and nothing resolved, the request asked
     * for something that does not exist.
     *
     * Notable absences, all deliberate, all legitimate on a listing page:
     * `s` and `sentence` (a search that found nothing is still a search),
     * `paged` and `page` (pagination), `orderby` and `order` (WooCommerce sorts
     * with these constantly), `preview*` (an unsaved draft resolves to nothing
     * by definition), and `embed`.
     *
     * @since 2.7.0
     * @var string[]
     */
    private const EXPLOITABLE = [
        'attachment',
        'attachment_id',
        'author',
        'author_name',
        'cat',
        'category_name',
        'cpage',
        'day',
        'hour',
        'm',
        'minute',
        'monthnum',
        'name',
        'p',
        'page_id',
        'pagename',
        'post_format',
        'post_type',
        'second',
        'static',
        'subpost',
        'subpost_id',
        'tag',
        'tag_id',
        'tag_slug__and',
        'tag_slug__in',
        'taxonomy',
        'term',
        'w',
        'year',
    ];

    /**
     * Register the guard.
     *
     * @since 2.7.0
     * @return void
     */
    public function init(): void {
        // After the query has run and before the template is chosen, which is
        // the only point where set_404() still changes what renders.
        add_action('template_redirect', [$this, 'maybe_block'], 2);
    }

    /**
     * Turn an unresolved content request into a 404.
     *
     * @since 2.7.0
     * @return void
     */
    public function maybe_block(): void {
        if (!$this->should_block()) {
            return;
        }

        global $wp_query;

        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        // So the reason is visible in the page a site owner is looking at.
        // Support otherwise has to guess why a URL they can see in a browser is
        // answering 404, and the answer is not in any log.
        add_action(
            'wp_head',
            static function (): void {
                echo "<!-- ThinkRank: query protection, no content resolved for this request -->\n";
            },
            0
        );
    }

    /**
     * Whether this request asked for content that does not exist.
     *
     * @since 2.7.0
     * @return bool
     */
    private function should_block(): bool {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        if (!is_main_query()) {
            return false;
        }

        $settings = (new Site_Identity_Manager())->get_settings('site', null);

        if (empty($settings['query_protection'])) {
            return false;
        }

        // Core already answered correctly, or this is a context that has no
        // queried object by design.
        if (is_404() || is_search() || is_preview() || is_feed() || is_robots() || is_trackback()) {
            return false;
        }

        // A date archive resolves no object and is perfectly valid. Core 404s a
        // date that cannot exist, so anything reaching here is a real one.
        if (is_date()) {
            return false;
        }

        // Something real resolved: a post, a page, a term, a post type, an
        // author. Nothing to protect against.
        if (null !== get_queried_object()) {
            return false;
        }

        $found = $this->exploitable_vars_present();

        if ([] === $found) {
            return false;
        }

        /**
         * Filter whether a request is treated as an unresolved content request.
         *
         * The escape hatch for a setup that registers a public query variable
         * deliberately — some page builders and tracking integrations do — and
         * serves a real page for it.
         *
         * @since 2.7.0
         *
         * @param bool     $block Whether to answer 404.
         * @param string[] $found The exploitable query variables in the request.
         */
        return (bool) apply_filters('thinkrank_block_unresolved_query', true, $found);
    }

    /**
     * Whether a selector's value names something this site really has.
     *
     * Most selectors need no check: a category or an author that exists
     * resolves a queried object, so the guard never gets this far. Two do not
     * behave that way, and both were false positives before this existed:
     *
     *   - `?post_type=post` is a legitimate way to ask for the blog listing,
     *     and `post` registers no archive, so nothing resolves and the request
     *     looked identical to `?post_type=nosuchtype`. Every public post type
     *     without an archive had the same problem.
     *   - `?taxonomy=category` without a `term` is a real taxonomy naming no
     *     term.
     *
     * A value naming a real, public thing is WordPress's business, whatever it
     * chooses to render for it. Only a value naming nothing is this guard's.
     *
     * @since 2.7.0
     *
     * @param string $key   Query variable name.
     * @param mixed  $value Its value.
     * @return bool True when the value names something registered.
     */
    public static function names_something_real(string $key, $value): bool {
        if (!is_string($value) || '' === $value) {
            return false;
        }

        if ('post_type' === $key) {
            return in_array($value, get_post_types(['public' => true]), true);
        }

        if ('taxonomy' === $key) {
            return in_array($value, get_taxonomies(['public' => true]), true);
        }

        return false;
    }

    /**
     * Content selectors present in this request.
     *
     * Read from the request rather than from the parsed query variables:
     * WordPress drops an invalid `post_type` before it reaches `$wp->query_vars`,
     * so by then the evidence that the request asked for one is gone. That is
     * the single most exploitable case of the six, and reading the parsed vars
     * would miss it entirely.
     *
     * @since 2.7.0
     * @return string[]
     */
    private function exploitable_vars_present(): array {
        global $wp;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of a public front-end URL; nothing is acted on or stored.
        $get = (array) $_GET;

        return self::exploitable_in(
            $get,
            $wp instanceof \WP ? (array) $wp->query_vars : []
        );
    }

    /**
     * The decision itself, as a function of its inputs.
     *
     * Separated from the globals so the part that decides which variables count
     * can be exercised directly. That is the part with the risk in it: the first
     * version of this list turned `?post_type=post` into a 404, because `post`
     * registers no archive and so looked exactly like a fabricated type.
     *
     * @since 2.7.0
     *
     * @param array $get        Request parameters.
     * @param array $query_vars Parsed query variables, for a pretty permalink
     *                          that puts the same selectors in the rewrite
     *                          result rather than the query string.
     * @return string[] The selectors that name nothing real.
     */
    public static function exploitable_in(array $get, array $query_vars = []): array {
        $requested = [];

        foreach ($get as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::EXPLOITABLE, true) && !self::names_something_real($key, $value)) {
                $requested[] = $key;
            }
        }

        foreach (self::EXPLOITABLE as $key) {
            if (isset($query_vars[$key])
                && '' !== $query_vars[$key]
                && !in_array($key, $requested, true)
                && !self::names_something_real($key, $query_vars[$key])) {
                $requested[] = $key;
            }
        }

        return $requested;
    }
}
