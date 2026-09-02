<?php
/**
 * Author Archives Manager
 *
 * Handles author archives functionality including redirects
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ThinkRank\Core\Settings;

/**
 * Author Archives Manager Class
 *
 * @since 1.0.0
 */
class Author_Archives_Manager {

    /**
     * Initialize the manager
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        add_action('template_redirect', [$this, 'maybe_redirect_author_archives']);
        add_filter('thinkrank_robots_meta', [$this, 'filter_robots']);
        add_filter('pre_get_document_title', [$this, 'modify_document_title'], 15);
        add_action('wp_head', [$this, 'add_meta_description'], 5);
    }

    /**
     * Add meta description for author archives
     *
     * This is the only place a description is printed for author archives —
     * SEO_Manager::output_meta_description() returns early on is_author() so
     * the two never both emit a <meta name="description"> tag.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_meta_description(): void {
        if (!is_author()) {
            return;
        }

        $author_id = get_queried_object_id();

        // A per-author meta description (e.g. imported from another SEO plugin)
        // overrides the global template.
        $custom_desc = (string) get_user_meta($author_id, '_thinkrank_meta_description', true);
        if ($custom_desc !== '') {
            $this->print_meta_description($custom_desc);
            return;
        }

        $settings = Settings::instance();
        // Default value matches what we set in endpoint
        $template = $settings->get('author_archives_meta_desc', Settings::DEFAULT_AUTHOR_ARCHIVES_META_DESC);
        if (empty($template)) {
            return;
        }

        // Get variables
        $author_name = get_the_author_meta('display_name', $author_id);
        $site_title = get_bloginfo('name');

        $replacements = [
            '%author_name%' => $author_name,
            '%site_title%' => $site_title
        ];

        $meta_desc = str_replace(array_keys($replacements), array_values($replacements), $template);

        $this->print_meta_description($meta_desc);
    }

    /**
     * Print the description tag with the same framing SEO_Manager uses.
     *
     * Keeps author archives consistent with every other page type: the plugin
     * header comment, the 160-character trim, and the open/close markers.
     *
     * @since 1.29.1
     * @param string $description Resolved description text
     * @return void
     */
    private function print_meta_description(string $description): void {
        $description = $this->tidy_whitespace($description);
        if ($description === '') {
            return;
        }

        // Keep the rendered description within the ~160 characters search
        // engines display. Measure and cut in CHARACTERS: strlen() counts
        // BYTES, so a Cyrillic/CJK description tripped a limit it was nowhere
        // near, and wp_trim_words() cuts by WORD COUNT, so a long-worded
        // description sailed past the cap entirely. Three units, three
        // different answers.
        $description = $this->trim_to_length($description, self::DESCRIPTION_MAX_LENGTH);

        // Opens the block through SEO_Manager so its closing comment, printed
        // on wp_head at priority 99, knows an opener was emitted.
        \ThinkRank\Frontend\SEO_Manager::note_opening_comment();
        echo "<!-- ThinkRank SEO Meta Description -->\n";
        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
        echo "<!-- /ThinkRank SEO Meta Description -->\n";
    }

    /**
     * Characters search engines display for a meta description.
     *
     * @since 2.2.0
     * @var int
     */
    private const DESCRIPTION_MAX_LENGTH = 160;

    /**
     * Trim a description to a character budget, multibyte-safe.
     *
     * Cuts on a word boundary when one is available inside the budget, so the
     * result does not end mid-word; falls back to a hard character cut for
     * scripts that do not use spaces (CJK), where a word-boundary search would
     * find nothing and return the string untouched.
     *
     * @since 2.2.0
     * @param string $description Description text.
     * @param int    $limit       Maximum length in characters, ellipsis included.
     * @return string
     */
    private function trim_to_length(string $description, int $limit): string {
        if (mb_strlen($description) <= $limit) {
            return $description;
        }

        // Reserve one character for the ellipsis.
        $budget  = $limit - 1;
        $cut     = mb_substr($description, 0, $budget);
        $last_gap = mb_strrpos($cut, ' ');

        // Only honour a word boundary that is not absurdly early — otherwise a
        // long unbroken token would collapse the description to a few chars.
        if (false !== $last_gap && $last_gap > (int) ($budget * 0.6)) {
            $cut = mb_substr($cut, 0, $last_gap);
        }

        return rtrim($cut) . '…';
    }

    /**
     * Modify document title for author archives
     *
     * @since 1.0.0
     * @param string $title Original title
     * @return string Modified title
     */
    public function modify_document_title(string $title): string {
        if (is_author()) {
            $author_id = get_queried_object_id();

            // A per-author SEO title (e.g. imported from another SEO plugin)
            // overrides the global template entirely.
            $custom_title = (string) get_user_meta($author_id, '_thinkrank_seo_title', true);
            if ($custom_title !== '') {
                return $this->with_page_suffix($custom_title);
            }

            $settings = Settings::instance();
            $template = $settings->get('author_archives_title', Settings::DEFAULT_AUTHOR_ARCHIVES_TITLE);

            if (empty($template)) {
                return $title;
            }

            // Get variables
            $author_name = get_the_author_meta('display_name', $author_id);
            $site_title = get_bloginfo('name');

            // Separator
            $separator = '-';
            if (class_exists('ThinkRank\SEO\Site_Identity_Manager')) {
                $separator = \ThinkRank\SEO\Site_Identity_Manager::get_active_separator_symbol();
            }

            // %page% resolves to nothing here. This filter runs at priority 15,
            // after SEO_Manager's at priority 1, so its own page indicator was
            // the one that reached the page — and it rendered without the
            // separator the rest of the site uses ("admin | tr Page 2" against
            // "Uncategorized | tr | Page 2" everywhere else). The token stays
            // recognised so a template that already contains it does not leak
            // the literal string; the indicator itself comes from the one
            // helper every other context uses (#397 review).
            $replacements = [
                '%author_name%' => $author_name,
                '%site_title%' => $site_title,
                '%separator%' => $separator,
                '%page%' => ''
            ];

            $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);

            return $this->with_page_suffix($this->tidy_whitespace($rendered));
        }
        return $title;
    }

    /**
     * Append the shared page indicator, when SEO_Manager is available.
     *
     * @since 2.0.1
     * @param string $title Rendered author-archive title.
     * @return string
     */
    private function with_page_suffix(string $title): string {
        if (!class_exists('\ThinkRank\Frontend\SEO_Manager')) {
            return $title;
        }

        return \ThinkRank\Frontend\SEO_Manager::with_page_suffix($title);
    }

    /**
     * Collapse the gaps left by variables that resolved to nothing.
     *
     * %page% is empty on an unpaginated archive, so the stock template ended
     * every author <title> with a stray trailing space; two empty variables in
     * a row would leave a double space mid-string.
     *
     * @since 1.29.1
     * @param string $value Rendered template
     * @return string Template with runs of whitespace collapsed and trimmed
     */
    private function tidy_whitespace(string $value): string {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Filter robots meta tag
     *
     * @since 1.0.0
     * @param array $robots Robots meta array
     * @return array Filtered robots meta
     */
    public function filter_robots(array $robots): array {
        if (is_author()) {
            $settings = Settings::instance();
            $index = $settings->get('author_archives_index', true);

            if (!$index) {
                // Remove 'index' if present
                $index_key = array_search('index', $robots, true);
                if ($index_key !== false) {
                    unset($robots[$index_key]);
                }
                // Add 'noindex' if not present
                if (!in_array('noindex', $robots, true)) {
                    $robots[] = 'noindex';
                }
            } else {
                // If showing in search results, check if empty archives should be hidden
                $show_empty = $settings->get('author_archives_show_empty', false);
                if (!$show_empty) {
                    $author_id = get_queried_object_id();
                    // Check if author has any published posts
                    $post_count = count_user_posts($author_id, 'post', true); // true = only public posts
                    if ((int) $post_count === 0) {
                        // Remove 'index' if present
                        $index_key = array_search('index', $robots, true);
                        if ($index_key !== false) {
                            unset($robots[$index_key]);
                        }
                        // Add 'noindex' if not present
                        if (!in_array('noindex', $robots, true)) {
                            $robots[] = 'noindex';
                        }
                    }
                }
            }
        }

        return array_values($robots);
    }

    /**
     * Redirect author archives to homepage if disabled
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_redirect_author_archives(): void {
        if (is_author()) {
            $settings = Settings::instance();
            // Default to true (enabled)
            $enabled = $settings->get('author_archives_enabled', true);

            if (!$enabled) {
                // 302, not 301. This redirect lasts exactly as long as the
                // setting stays off, but a 301 is cached by browsers and CDNs
                // indefinitely — so turning author archives back on could not
                // undo it for anyone who had already been redirected, and there
                // was no server-side way to fix that.
                wp_safe_redirect(home_url(), 302);
                exit;
            }
        }
    }
}
