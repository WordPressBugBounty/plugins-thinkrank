<?php
/**
 * Content Access
 *
 * @package ThinkRank
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a logged-out visitor can read something taken from a post's body.
 *
 * ThinkRank reads the stored body to build public output: descriptions, FAQ
 * structured data, image sitemap entries, social images. WordPress's own
 * visibility rules (status, password) are checked where those reads happen.
 * A site can also restrict a body from a `the_content` filter, and a read of
 * the stored body never runs that filter. This class is the one place that
 * asks it: it renders the body once as a logged-out visitor and answers, per
 * fragment, whether the visitor's page holds it.
 *
 * The source of a description stays the stored body. The render is only the
 * thing a fragment is checked against, so nothing `the_content` adds (share
 * buttons, related posts) reaches a description.
 *
 * What it cannot see: a restriction that is not a `the_content` filter, or
 * one that only acts in a context this class cannot reproduce. The
 * `thinkrank_content_is_public` filter is the exact answer for those.
 *
 * @since 2.21.4
 */
final class Content_Access {

    /**
     * Post meta holding the stored answers for one post version.
     *
     * @var string
     */
    public const META_KEY = '_thinkrank_visitor_access';

    /**
     * Version of the logic that produced a stored answer.
     *
     * Raise it when the measurement changes, so answers written by the older
     * logic are measured again.
     *
     * Version 2: an answer for a builder's page carries the description cut
     * from the visitor's render.
     *
     * @var int
     */
    private const VERSION = 2;

    /**
     * Most fragment answers kept per post.
     *
     * @var int
     */
    private const MAX_ITEMS = 150;

    /**
     * The global post and every global `setup_postdata()` writes.
     *
     * @var string[]
     */
    private const LOOP_GLOBALS = [
        'post', 'id', 'authordata', 'currentday', 'currentmonth',
        'page', 'pages', 'multipage', 'more', 'numpages',
    ];

    /**
     * Visitor renders made during this request, by post ID and version.
     *
     * @var array<string, array{html:string, words:array<string,int>}>
     */
    private static array $renders = [];

    /**
     * Post meta that changes without the post's access rules changing.
     *
     * Caches other code writes while a page renders. Leaving one out only
     * costs a render; none of them is a rule.
     *
     * @var string[]
     */
    private const VOLATILE_META = ['_thinkrank', '_edit_', '_oembed_', '_elementor_', '_wp_', '_pingme', '_encloseme'];

    /**
     * Fingerprints worked out during this request, by post ID.
     *
     * @var array<int, string>
     */
    private static array $fingerprints = [];

    /**
     * Seconds spent rendering posts other than the one being viewed.
     *
     * @var float
     */
    private static float $spent = 0.0;

    /**
     * Whether a visitor render is running.
     *
     * @var bool
     */
    private static bool $measuring = false;

    /**
     * Share of a builder's words the visitor's render must hold for a page a
     * builder owns to count as unrestricted.
     *
     * @var float
     */
    private const BUILDER_OVERLAP = 0.8;

    /**
     * Most questions kept to answer once the page has rendered.
     *
     * @var int
     */
    private const MAX_DEFERRED = 50;

    /**
     * Questions asked from inside the page's own render, answered at shutdown.
     *
     * @var array<string, array{0:\WP_Post, 1:string, 2:string}>
     */
    private static array $deferred = [];

    /**
     * How many measured() calls are running.
     *
     * @var int
     */
    private static int $forced = 0;

    /**
     * Run a callback with every question measured, wherever it is asked.
     *
     * On an admin or REST request an unknown answer reads as visible, since
     * the reader there can read the body. That is wrong for a value which is
     * worked out there, stored, and published later as it is: wrap the code
     * that works it out in this.
     *
     * @since 2.21.4
     *
     * @param callable $callback Called with no arguments.
     * @return mixed Whatever the callback returns.
     */
    public static function measured(callable $callback) {
        ++self::$forced;

        try {
            return $callback();
        } finally {
            --self::$forced;
        }
    }

    /**
     * Whether a visitor render is running right now.
     *
     * Code that keeps per-request state and is reached from `the_content`
     * asks this, so the off-page render does not consume that state.
     *
     * @since 2.21.4
     *
     * @return bool
     */
    public static function is_measuring(): bool {
        return self::$measuring;
    }

    /**
     * A description derived from the body, or '' when a visitor cannot read it.
     *
     * @since 2.21.4
     *
     * @param \WP_Post $post  Post being described.
     * @param int      $words Word cap.
     * @return string
     */
    public static function derived_description(\WP_Post $post, int $words = 25): string {
        $text = Pattern_Resolver::derive_excerpt(Builder_Content::visible_content($post), $words);

        if ('' === $text) {
            return '';
        }

        return self::sees_text($post, $text) ? $text : self::rendered_description($post, $text, $words);
    }

    /**
     * A description cut from what the visitor's page shows, for a page a
     * builder owns, or ''.
     *
     * On such a page `post_content` is a copy the builder writes, and it can
     * hold text the page no longer shows, or never did: a widget's default
     * text, a removed section. A description cut from it then fails the
     * check with no restriction anywhere, and the page was left with no
     * description at all. The visitor's render is the page, so the
     * description is cut from that instead. Behind a restriction it is the
     * notice the visitor reads, never the body.
     *
     * Only for a builder's page. On any other post the stored body is the
     * page, and text missing from the render is text that was withheld.
     *
     * @param \WP_Post $post    Post being described.
     * @param string   $refused Description that was not found in the render.
     * @param int      $words   Word cap.
     * @return string
     */
    private static function rendered_description(\WP_Post $post, string $refused, int $words): string {
        if ([] === self::builder_words($post)) {
            return '';
        }

        $on_view = self::is_view_of($post);
        $state   = self::state($post, $on_view);
        $key     = md5('text:' . $refused) . ':' . $words;

        if (null !== $state && isset($state['alts'][$key])) {
            return (string) $state['alts'][$key];
        }

        // Where sees() would not measure, neither does this.
        if (
            null === $state
            || (0 === self::$forced && !self::is_front_end())
            || (function_exists('doing_filter') && doing_filter('the_content'))
        ) {
            return '';
        }

        $render = self::render($post, $on_view);
        if (null === $render) {
            return '';
        }

        $description = Pattern_Resolver::derive_excerpt($render['html'], $words);

        if (count((array) ($state['alts'] ?? [])) < self::MAX_ITEMS) {
            $state['alts'][$key] = $description;

            if ($on_view || empty($state['view'])) {
                update_post_meta((int) $post->ID, self::META_KEY, $state);
            }
        }

        return $description;
    }

    /**
     * The post's excerpt, or '' when it was cut from a body a visitor cannot read.
     *
     * An authored excerpt is written for the public and is returned as it is.
     * Without one, core cuts the excerpt from the body, so it is checked like
     * any other text taken from the body.
     *
     * @since 2.21.4
     *
     * @param \WP_Post $post Post.
     * @return string
     */
    public static function excerpt(\WP_Post $post): string {
        $excerpt = (string) get_the_excerpt($post);

        if ('' === trim($excerpt) || '' !== trim((string) $post->post_excerpt)) {
            return $excerpt;
        }

        return self::sees_text($post, $excerpt) ? $excerpt : '';
    }

    /**
     * Whether a logged-out visitor reads this text on the post's page.
     *
     * @since 2.21.4
     *
     * @param \WP_Post $post  Post the text was taken from.
     * @param string   $text  Text or HTML taken from the stored body.
     * @param bool     $force Measure even on an admin or REST request, where
     *                        an unknown answer otherwise reads as visible.
     *                        For output that is published from there.
     * @return bool
     */
    public static function sees_text(\WP_Post $post, string $text, bool $force = false): bool {
        return self::sees($post, 'text', $text, $force);
    }

    /**
     * Whether a logged-out visitor reads text that only a render prints.
     *
     * For words kept outside the body's own text: a dynamic block's
     * attributes, a builder's stored data. The stored body can read as whole
     * without them, so they are always looked for in the visitor's page.
     *
     * @since 2.21.4
     *
     * @param \WP_Post $post  Post the text belongs to.
     * @param string   $text  Text the render is expected to print.
     * @param bool     $force See sees_text().
     * @return bool
     */
    public static function sees_rendered_text(\WP_Post $post, string $text, bool $force = false): bool {
        return self::sees($post, 'rendered', $text, $force);
    }

    /**
     * Whether a logged-out visitor is served this image on the post's page.
     *
     * @since 2.21.4
     *
     * @param \WP_Post $post  Post the image was found in.
     * @param string   $url   Image URL.
     * @param bool     $force See sees_text().
     * @return bool
     */
    public static function sees_image(\WP_Post $post, string $url, bool $force = false): bool {
        return self::sees($post, 'image', $url, $force);
    }

    /**
     * Whether a logged-out visitor reads the whole stored body.
     *
     * For readers that take more than a fragment from the body.
     *
     * @since 2.21.4
     *
     * @param \WP_Post $post  Post.
     * @param bool     $force See sees_text().
     * @return bool
     */
    public static function sees_all(\WP_Post $post, bool $force = false): bool {
        return self::sees($post, 'all', '', $force);
    }

    /**
     * Forget everything measured during this request.
     *
     * @since 2.21.4
     *
     * @return void
     */
    public static function flush(): void {
        self::$renders      = [];
        self::$fingerprints = [];
        self::$spent     = 0.0;
        self::$measuring = false;
        self::$forced    = 0;
        self::$deferred  = [];
    }

    /**
     * Answer one question about one post.
     *
     * @param \WP_Post $post     Post.
     * @param string   $kind     'text', 'rendered', 'image' or 'all'.
     * @param string   $fragment Text or URL; unused for 'all'.
     * @param bool     $force    Measure outside a front-end request.
     * @return bool
     */
    private static function sees(\WP_Post $post, string $kind, string $fragment, bool $force): bool {
        if ('' !== (string) $post->post_password && (!function_exists('post_password_required') || post_password_required($post))) {
            return false;
        }

        /**
         * Filter whether a logged-out visitor can read a post's body.
         *
         * The measurement infers the answer from a render, so it cannot see
         * a restriction that is not a `the_content` filter. An integration
         * that knows the answer returns it here: false withholds everything
         * taken from the body, true skips the measurement, null leaves the
         * decision to the measurement.
         *
         * @since 2.21.4
         *
         * @param bool|null $public Null to measure.
         * @param \WP_Post  $post   Post under consideration.
         */
        $decided = apply_filters('thinkrank_content_is_public', null, $post);
        if (is_bool($decided)) {
            return $decided;
        }

        // Nothing the_content could withhold. A builder that keeps the page
        // outside post_content does not print it through that filter either,
        // so a render of the stored column would describe another page.
        $body = 'rendered' === $kind ? (string) $post->post_content : wp_strip_all_tags((string) $post->post_content);
        if ('' === trim($body) || '' !== Builder_Content::superseding_content($post)) {
            return true;
        }

        // A nested question, asked from inside the render. It has no answer
        // yet and must not start a second render.
        if (self::$measuring) {
            return false;
        }

        $id      = (int) $post->ID;
        $on_view = self::is_view_of($post);
        $state   = self::state($post, $on_view);
        $key     = 'all' === $kind ? '' : md5($kind . ':' . $fragment);

        if (null !== $state) {
            if (!empty($state['full']) && 'rendered' !== $kind) {
                return true;
            }
            if ('all' === $kind) {
                return false;
            }
            if (isset($state['items'][$key])) {
                return (bool) $state['items'][$key];
            }
        }

        // An editor reading a preview or a report can read the body anyway,
        // and a render inside the admin is not the visitor's page.
        if (!$force && 0 === self::$forced && !self::is_front_end()) {
            return true;
        }

        // Asked from inside the page's own render of the body, as a block
        // theme's template does before the head is printed. A second render
        // nested in the first is not the visitor's page: a builder takes its
        // filter off while it runs, so the stored copy would come back
        // instead. The question is kept and answered once the page is done;
        // until then the answer is unknown, and the next view has it.
        if (function_exists('doing_filter') && doing_filter('the_content')) {
            self::defer($post, $kind, $fragment);

            return false;
        }

        $render = self::render($post, $on_view);
        if (null === $render) {
            return false;
        }

        if (null === $state) {
            $state = [
                'v'     => self::VERSION,
                'key'   => self::version_key($post),
                'at'    => time(),
                'view'  => $on_view,
                'full'  => self::is_full($post, $render) || self::is_builders_page($post, $render),
                'items' => [],
            ];
        }

        $answer = !empty($state['full']) && 'rendered' !== $kind;
        if (!$answer && 'all' !== $kind) {
            $answer = 'image' === $kind
                ? self::html_has_image($render['html'], $fragment)
                : self::words_hold($render['words'], $fragment);

            if (count($state['items']) < self::MAX_ITEMS) {
                $state['items'][$key] = $answer;
            }
        }

        // An answer measured away from the post's page never joins the ones
        // measured on it: a restriction may not act here, and a view would
        // then trust it.
        if ($on_view || empty($state['view'])) {
            update_post_meta($id, self::META_KEY, $state);
        }

        return $answer;
    }

    /**
     * Keep a question to answer when the request ends.
     *
     * @param \WP_Post $post     Post.
     * @param string   $kind     Kind of question.
     * @param string   $fragment Text or URL.
     * @return void
     */
    private static function defer(\WP_Post $post, string $kind, string $fragment): void {
        if ([] === self::$deferred && function_exists('add_action')) {
            add_action('shutdown', [self::class, 'answer_deferred'], 5);
        }

        if (count(self::$deferred) < self::MAX_DEFERRED) {
            self::$deferred[(int) $post->ID . ':' . $kind . ':' . md5($fragment)] = [$post, $kind, $fragment];
        }
    }

    /**
     * Answer the questions that were asked from inside the page's render.
     *
     * Their answers are stored, so the next view of the post has them.
     *
     * @since 2.21.4
     *
     * @return void
     */
    public static function answer_deferred(): void {
        $deferred       = self::$deferred;
        self::$deferred = [];

        foreach ($deferred as [$post, $kind, $fragment]) {
            self::sees($post, $kind, $fragment, false);
        }
    }

    /**
     * Whether the visitor's render is the page a builder made for this post.
     *
     * On a post a page builder owns, `post_content` is a copy the builder
     * writes on save, and it can go stale. A render that differs from a stale
     * copy is not a restriction. It is told apart by what the builder itself
     * holds for the page: a restriction notice or a teaser holds few of those
     * words, the builder's own render holds nearly all of them.
     *
     * @param \WP_Post                                        $post   Post.
     * @param array{html:string, words:array<string,int>} $render Visitor render.
     * @return bool
     */
    private static function is_builders_page(\WP_Post $post, array $render): bool {
        $want  = self::builder_words($post);
        $total = array_sum($want);
        if ($total <= 0) {
            return false;
        }

        $found = 0;
        foreach ($want as $word => $count) {
            $found += min($count, $render['words'][$word] ?? 0);
        }

        return $found / $total >= self::BUILDER_OVERLAP;
    }

    /**
     * The words a builder holds for this post, counted, or [] when no
     * builder owns it.
     *
     * @param \WP_Post $post Post.
     * @return array<string,int>
     */
    private static function builder_words(\WP_Post $post): array {
        if (!self::has_builder_data((int) $post->ID)) {
            return [];
        }

        try {
            // From nothing, so the resolver reads the builder's own storage
            // and not the copy in post_content.
            return array_count_values(self::words(Builder_Content::resolve_markup('', $post)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Whether a builder keeps data of its own for this post.
     *
     * Read without a string cast: Beaver Builder stores its layout as an
     * array, and casting one raises a warning on every view of the page and
     * reads an empty array as data (#1621).
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private static function has_builder_data(int $post_id): bool {
        foreach (Builder_Content::builder_meta_keys() as $key) {
            $value = get_post_meta($post_id, (string) $key, true);

            if (is_array($value)) {
                $held = [] !== $value;
            } elseif (is_scalar($value)) {
                $held = '' !== trim((string) $value);
            } else {
                $held = null !== $value;
            }

            if ($held) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored answers that still stand for this post, or null.
     *
     * @param \WP_Post $post    Post.
     * @param bool     $on_view Whether this request is a view of the post.
     * @return array<string, mixed>|null
     */
    private static function state(\WP_Post $post, bool $on_view): ?array {
        $state = get_post_meta((int) $post->ID, self::META_KEY, true);
        if (!is_array($state)) {
            return null;
        }

        if (
            self::VERSION !== (int) ($state['v'] ?? 0)
            || self::version_key($post) !== (string) ($state['key'] ?? '')
            || !isset($state['full'], $state['items'])
            || !is_array($state['items'])
        ) {
            return null;
        }

        // The post's own page is where a restriction is sure to act, and
        // where one more render costs least. So a view trusts only an answer
        // measured on a view, and measures again once that answer is old:
        // access rules can change without the post changing. Everywhere else
        // the stored answer is the best one available.
        if ($on_view && isset(self::$renders[self::render_key($post)])) {
            return $state;
        }

        if ($on_view && (empty($state['view']) || time() - (int) ($state['at'] ?? 0) > self::ttl($post))) {
            return null;
        }

        return $state;
    }

    /**
     * Seconds a stored answer is trusted on the post's own page.
     *
     * @param \WP_Post $post Post.
     * @return int
     */
    private static function ttl(\WP_Post $post): int {
        /**
         * Filter how long a stored visitor-access answer is trusted.
         *
         * An edit to the post always retires the answer. This covers access
         * rules that change without one.
         *
         * @since 2.21.4
         *
         * @param int      $seconds Lifetime in seconds.
         * @param \WP_Post $post    Post.
         */
        return max(0, (int) apply_filters('thinkrank_content_access_ttl', 12 * HOUR_IN_SECONDS, $post));
    }

    /**
     * What identifies the version of the post an answer belongs to.
     *
     * @param \WP_Post $post Post.
     * @return string
     */
    private static function version_key(\WP_Post $post): string {
        return (string) $post->post_modified_gmt . '|' . strlen((string) $post->post_content) . '|' . self::fingerprint($post);
    }

    /**
     * A fingerprint of what a restriction usually decides from.
     *
     * A membership plugin keeps a post's rule in post meta or reads it from
     * the post's terms, and both can change without the post being saved, so
     * the modified time alone would keep an answer measured under the old
     * rule. Counters are left out: a number counting up on every view would
     * retire the answer on every view.
     *
     * @param \WP_Post $post Post.
     * @return string
     */
    private static function fingerprint(\WP_Post $post): string {
        $id = (int) $post->ID;

        if (isset(self::$fingerprints[$id])) {
            return self::$fingerprints[$id];
        }

        $meta = get_post_meta($id);
        $kept = [];

        foreach (is_array($meta) ? $meta : [] as $key => $values) {
            foreach (self::VOLATILE_META as $prefix) {
                if (0 === strpos((string) $key, $prefix)) {
                    continue 2;
                }
            }

            $values = array_values(array_filter(
                (array) $values,
                static fn($value): bool => !(is_numeric($value) && (float) $value > 1)
            ));
            if ([] !== $values) {
                $kept[(string) $key] = $values;
            }
        }
        ksort($kept);

        $terms = [];
        if (function_exists('get_object_taxonomies') && function_exists('get_the_terms')) {
            foreach ((array) get_object_taxonomies((string) $post->post_type) as $taxonomy) {
                $found = get_the_terms($post, (string) $taxonomy);
                if (is_array($found)) {
                    $ids = array_map(static fn($term): int => (int) ($term->term_id ?? 0), $found);
                    sort($ids);
                    $terms[(string) $taxonomy] = $ids;
                }
            }
        }

        self::$fingerprints[$id] = substr(md5((string) wp_json_encode([$kept, $terms])), 0, 12);

        return self::$fingerprints[$id];
    }

    /**
     * Whether this request is a front-end view of this post.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private static function is_view_of(\WP_Post $post): bool {
        return self::is_front_end()
            && function_exists('is_singular') && is_singular()
            && function_exists('get_queried_object_id')
            && (int) get_queried_object_id() === (int) $post->ID;
    }

    /**
     * Whether this request serves the public site.
     *
     * @return bool
     */
    private static function is_front_end(): bool {
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        return true;
    }

    /**
     * The body as `the_content` gives it to a logged-out visitor.
     *
     * Rendered once per post per request. Posts other than the one being
     * viewed share a time budget, so a sitemap or a list cannot render
     * without bound: past it the answer is unknown and the caller withholds.
     *
     * @param \WP_Post $post    Post.
     * @param bool     $on_view Whether this request is a view of the post.
     * @return array{html:string, words:array<string,int>}|null
     */
    private static function render(\WP_Post $post, bool $on_view): ?array {
        $memo = self::render_key($post);

        if (isset(self::$renders[$memo])) {
            return self::$renders[$memo];
        }

        if (!$on_view) {
            $default = (function_exists('wp_doing_cron') && wp_doing_cron()) || (defined('WP_CLI') && WP_CLI) ? 20.0 : 3.0;

            /**
             * Filter the seconds one request may spend rendering posts it is
             * not a view of, to learn what a visitor reads.
             *
             * @since 2.21.4
             *
             * @param float $seconds Budget in seconds.
             */
            if (self::$spent >= (float) apply_filters('thinkrank_content_access_budget', $default)) {
                return null;
            }
        }

        $started  = microtime(true);
        $snapshot = [];
        foreach (self::LOOP_GLOBALS as $name) {
            if (array_key_exists($name, $GLOBALS)) {
                $snapshot[$name] = $GLOBALS[$name];
            }
        }

        // As a logged-out visitor, whoever is logged in: a restriction decides
        // from the current user, and the answer is stored for everyone.
        $user     = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $switched = 0 !== $user && function_exists('wp_set_current_user');

        // A restriction written for a theme often acts only inside the main
        // loop. On a view of the post this is the main query, so the render
        // says so, the way the template's own call will.
        $query   = $on_view && isset($GLOBALS['wp_query']) && is_object($GLOBALS['wp_query']) ? $GLOBALS['wp_query'] : null;
        $in_loop = null !== $query && property_exists($query, 'in_the_loop') ? $query->in_the_loop : null;

        self::$measuring = true;
        $html            = '';

        // A filter that prints instead of returning must not reach the page.
        ob_start();

        try {
            if ($switched) {
                wp_set_current_user(0);
            }
            if (null !== $in_loop) {
                $query->in_the_loop = true;
            }

            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- entering the loop deliberately; restored in the finally below.
            $GLOBALS['post'] = $post;
            setup_postdata($post);

            /** This filter is documented in wp-includes/post-template.php */
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, asked what it publishes.
            $html = (string) apply_filters('the_content', (string) $post->post_content);
        } catch (\Throwable $e) {
            // A render that fails shows the visitor nothing we can vouch for.
            $html = '';
        } finally {
            ob_end_clean();
            self::$measuring = false;

            if (null !== $in_loop) {
                $query->in_the_loop = $in_loop;
            }
            if ($switched) {
                wp_set_current_user($user);
            }
            foreach (self::LOOP_GLOBALS as $name) {
                if (array_key_exists($name, $snapshot)) {
                    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- restoring core's loop globals to the values snapshotted above.
                    $GLOBALS[$name] = $snapshot[$name];
                } else {
                    unset($GLOBALS[$name]);
                }
            }
        }

        if (!$on_view) {
            self::$spent += microtime(true) - $started;
        }

        // The render can write to post meta; the answer is keyed on what is
        // there once it has.
        unset(self::$fingerprints[(int) $post->ID]);

        self::$renders[$memo] = [
            'html'  => $html,
            'words' => array_count_values(self::words($html)),
        ];

        return self::$renders[$memo];
    }

    /**
     * What a render made during this request is remembered under.
     *
     * The version is part of it: a long-running process can see a post
     * before and after an edit.
     *
     * @param \WP_Post $post Post.
     * @return string
     */
    private static function render_key(\WP_Post $post): string {
        return (int) $post->ID . '|' . self::version_key($post) . '|' . md5((string) $post->post_content);
    }

    /**
     * Whether the visitor's render holds the whole stored body.
     *
     * True is the common case and answers every later question about the post
     * without looking at the fragment. It has to be exact for that reason: a
     * body that is mostly there can still be missing the one paragraph a
     * description would be cut from.
     *
     * @param \WP_Post                                        $post   Post.
     * @param array{html:string, words:array<string,int>} $render Visitor render.
     * @return bool
     */
    private static function is_full(\WP_Post $post, array $render): bool {
        $content = (string) $post->post_content;

        // Shortcode tags and their attributes are replaced on render. The text
        // a shortcode encloses stays: a restriction shortcode wraps the body
        // it protects, and that body is what has to be found.
        $text = (string) preg_replace('/\[\[?\/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?\/?\]\]?/', ' ', $content);

        $have = $render['words'];
        foreach (array_count_values(self::words($text)) as $word => $count) {
            if (($have[$word] ?? 0) < $count) {
                return false;
            }
        }

        if (false !== stripos($content, '<img') && preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (!self::html_has_image($render['html'], (string) $url)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether every word of a fragment is among the visitor's words.
     *
     * Counted per occurrence, so a notice cannot cover a sentence by sharing
     * its common words once. The last word may be the start of a longer one:
     * where a description is cut by characters rather than words, the cut
     * lands inside a run of text.
     *
     * @param array<string,int> $have     Visitor's words, counted.
     * @param string            $fragment Text or HTML taken from the body.
     * @return bool
     */
    private static function words_hold(array $have, string $fragment): bool {
        $words = self::words($fragment);
        if ([] === $words) {
            return false;
        }

        $last = (string) array_pop($words);

        foreach (array_count_values($words) as $word => $count) {
            if (($have[$word] ?? 0) < $count) {
                return false;
            }
            $have[$word] -= $count;
        }

        if (($have[$last] ?? 0) > 0) {
            return true;
        }

        foreach ($have as $word => $count) {
            if ($count > 0 && 0 === strpos((string) $word, $last)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the visitor's markup carries an image.
     *
     * Matched on the file name: a CDN or lazy-load plugin rewrites the host
     * and the attribute, and keeps the name.
     *
     * @param string $html Visitor's render.
     * @param string $url  Image URL.
     * @return bool
     */
    private static function html_has_image(string $html, string $url): bool {
        $path = (string) wp_parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
        $name = basename($path);

        return '' !== $name && false !== stripos(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $name);
    }

    /**
     * The words of a text, lower-cased, in order.
     *
     * Entities are decoded and words split on anything that is not a letter
     * or a digit, so texturized quotes and dashes are not differences. The
     * few letters texturize turns into symbols are turned back: `4x4` renders
     * as `4×4`, and a description holding it would otherwise never be found.
     * URLs are dropped: an address on a line of its own becomes a player on
     * render.
     *
     * @param string $text Text or HTML.
     * @return string[]
     */
    private static function words(string $text): array {
        // Block boundaries become spaces first: stripping `</summary><p>`
        // bare would join the last word of one element to the first of the next.
        $text = html_entity_decode(\ThinkRank\Core\Seo_Text::plain_text($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~https?://\S+~i', ' ', $text);
        $text = strtr($text, ['×' => 'x', '™' => '(tm)', '©' => '(c)', '®' => '(r)']);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
    }
}
