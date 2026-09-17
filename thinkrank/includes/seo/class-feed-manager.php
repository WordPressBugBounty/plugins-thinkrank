<?php
/**
 * What ThinkRank does to the site's RSS feeds.
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
 * Feed controls: shorten entries, sign them, and keep them out of the index.
 *
 * WordPress publishes a feed of every site, and content scrapers read it to
 * republish posts wholesale. The standard defence — shipped by The SEO
 * Framework, Yoast and Rank Math — is three small controls, and ThinkRank had
 * none of them, which made a ThinkRank site the easy target among the big
 * plugins.
 *
 *   - **Shorten entries to an excerpt.** A scraper that copies the feed gets an
 *     intro rather than the article.
 *   - **Sign each entry with a link home.** The useful half: a scraped copy
 *     carries a link back to the original, which is worth more than the copy
 *     costs.
 *   - **Keep feeds out of the index.** A feed URL competing with the post it
 *     duplicates helps nobody. Deliberately a choice rather than a hardcode:
 *     podcast sites need their feed indexable, which is the caveat The SEO
 *     Framework documents for its own version of this.
 *
 * All three default off, so an upgrade changes nothing about what an existing
 * site already publishes to its subscribers. A brand-new install is seeded with
 * the signature and the noindex on ({@see Activator::seed_feed_defaults()}),
 * which is where matching the competitors' out-of-the-box posture belongs.
 *
 * @since 2.7.0
 */
class Feed_Manager {

    /**
     * Site identity settings, read once per request.
     *
     * @since 2.7.0
     * @var array|null
     */
    private $settings = null;

    /**
     * Register the feed hooks.
     *
     * @since 2.7.0
     * @return void
     */
    public function init(): void {
        // Early, so the header is set before anything can start the body.
        add_action('template_redirect', [$this, 'maybe_noindex_feed'], 1);

        add_filter('the_content_feed', [$this, 'filter_content'], 20);
        add_filter('the_excerpt_rss', [$this, 'filter_excerpt'], 20);
    }

    /**
     * Ask crawlers not to index a feed.
     *
     * A header rather than a robots.txt rule: a disallowed URL is never fetched,
     * so the crawler never learns it is not to be indexed and the URL can still
     * surface from links elsewhere. `follow` is kept so the links inside the
     * feed still count.
     *
     * @since 2.7.0
     * @return void
     */
    public function maybe_noindex_feed(): void {
        if (!is_feed() || headers_sent()) {
            return;
        }

        if (empty($this->settings()['feed_noindex'])) {
            return;
        }

        header('X-Robots-Tag: noindex, follow', true);
    }

    /**
     * The body of a feed entry.
     *
     * @since 2.7.0
     * @param mixed $content Entry content.
     * @return mixed
     */
    public function filter_content($content) {
        // Untyped: this value belongs to whoever filtered it before us, and
        // coercing another plugin's object or null to a string would drop it.
        if (!is_string($content)) {
            return $content;
        }

        $settings = $this->settings();

        if (!empty($settings['feed_excerpt_only'])) {
            $excerpt = $this->excerpt();

            if ('' !== $excerpt) {
                $content = $excerpt;
            }
        }

        return $content . $this->source_link($settings);
    }

    /**
     * The excerpt of a feed entry.
     *
     * Signed as well as the body: which of the two a reader sees depends on the
     * feed template and on the site's Reading setting, and an unsigned entry is
     * exactly the one a scraper wants.
     *
     * @since 2.7.0
     * @param mixed $excerpt Entry excerpt.
     * @return mixed
     */
    public function filter_excerpt($excerpt) {
        if (!is_string($excerpt)) {
            return $excerpt;
        }

        return $excerpt . $this->source_link($this->settings());
    }

    /**
     * The line that points a scraped copy back here.
     *
     * @since 2.7.0
     * @param array $settings Site identity settings.
     * @return string HTML, or '' when the feature is off.
     */
    private function source_link(array $settings): string {
        if (empty($settings['feed_source_link'])) {
            return '';
        }

        $post_id = get_the_ID();

        if (!$post_id) {
            return '';
        }

        $permalink = (string) get_permalink($post_id);
        $home      = home_url('/');

        if ('' === $permalink) {
            return '';
        }

        $html = sprintf(
            '<p>%s</p>',
            sprintf(
                /* translators: 1: link to the original post, 2: link to the site. */
                esc_html__('The post %1$s first appeared on %2$s.', 'thinkrank'),
                '<a href="' . esc_url($permalink) . '">' . esc_html(get_the_title($post_id)) . '</a>',
                '<a href="' . esc_url($home) . '">' . esc_html(get_bloginfo('name')) . '</a>'
            )
        );

        /**
         * Filter the source line appended to each feed entry.
         *
         * @since 2.7.0
         *
         * @param string $html    The line, as HTML.
         * @param int    $post_id Post the entry describes.
         */
        $filtered = apply_filters('thinkrank_feed_source_link', $html, $post_id);

        // Same reasoning as filter_content() above, from the other side: a
        // caller who returns an array gets `Array to string conversion` and the
        // literal string "Array" appended to every entry in the feed. Take the
        // filtered value only when it is one, and keep our own line otherwise.
        if (is_string($filtered)) {
            $html = $filtered;
        }

        // Feed bodies are wrapped in CDATA by the feed templates, and a literal
        // ]]> inside one ends the section early and breaks the whole document.
        return str_replace(']]>', ']]&gt;', $html);
    }

    /**
     * The excerpt to stand in for a full entry.
     *
     * Falls back to one derived from the content when the author wrote none,
     * because the point of the setting is that the full text does not go out.
     *
     * @since 2.7.0
     * @return string
     */
    private function excerpt(): string {
        $post_id = get_the_ID();

        if (!$post_id) {
            return '';
        }

        // Core already shortens entries when the Reading setting says
        // "Summary". Shortening an excerpt again would cut a short entry down
        // to almost nothing, so this defers to it.
        if (get_option('rss_use_excerpt')) {
            return '';
        }

        $excerpt = trim((string) get_the_excerpt($post_id));

        return '' === $excerpt ? '' : wpautop($excerpt);
    }

    /**
     * Site identity settings, read once.
     *
     * @since 2.7.0
     * @return array
     */
    private function settings(): array {
        if (null === $this->settings) {
            $this->settings = $this->load_settings();
        }

        return $this->settings;
    }

    /**
     * Read the settings.
     *
     * Its own method so the filters can be exercised without a settings store;
     * what matters here is which switch changed which part of an entry.
     *
     * @since 2.7.0
     * @return array
     */
    protected function load_settings(): array {
        return (array) (new Site_Identity_Manager())->get_settings('site', null);
    }
}
