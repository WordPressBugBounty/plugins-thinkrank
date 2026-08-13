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

        // Ensure description is within optimal length (150-160 characters)
        if (strlen($description) > 160) {
            $description = wp_trim_words($description, 25, '...');
        }

        echo "<!-- Search Engine Optimization by ThinkRank - https://thinkrank.ai/ -->\n";
        echo "<!-- ThinkRank SEO Meta Description -->\n";
        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
        echo "<!-- /ThinkRank SEO Meta Description -->\n";
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
                return $custom_title;
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

            // Page number
            $page_str = '';
            $paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;
            if ($paged > 1) {
                // translators: %d is the page number for paginated author archives.
                $page_str = sprintf(__('Page %d', 'thinkrank'), $paged);
            }

            $replacements = [
                '%author_name%' => $author_name,
                '%site_title%' => $site_title,
                '%separator%' => $separator,
                '%page%' => $page_str
            ];

            $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);

            return $this->tidy_whitespace($rendered);
        }
        return $title;
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
                wp_safe_redirect(home_url(), 301);
                exit;
            }
        }
    }
}
