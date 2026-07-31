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
     * @since 1.0.0
     * @return void
     */
    public function add_meta_description(): void {
        if (is_author()) {
            $author_id = get_queried_object_id();

            // A per-author meta description (e.g. imported from another SEO plugin)
            // overrides the global template.
            $custom_desc = (string) get_user_meta($author_id, '_thinkrank_meta_description', true);
            if ($custom_desc !== '') {
                echo '<meta name="description" content="' . esc_attr($custom_desc) . '" />' . "\n";
                return;
            }

            $settings = Settings::instance();
            // Default value matches what we set in endpoint
            $template = $settings->get('author_archives_meta_desc', 'Articles written by %author_name% on %site_title%');
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
            $meta_desc = trim($meta_desc);

            if (!empty($meta_desc)) {
                echo '<meta name="description" content="' . esc_attr($meta_desc) . '" />' . "\n";
            }
        }
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
            $template = $settings->get('author_archives_title', '%author_name% – %site_title% %page%');

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

            return str_replace(array_keys($replacements), array_values($replacements), $template);
        }
        return $title;
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
                if (($key = array_search('index', $robots)) !== false) {
                    unset($robots[$key]);
                }
                // Add 'noindex' if not present
                if (!in_array('noindex', $robots)) {
                    $robots[] = 'noindex';
                }
            } else {
                // If showing in search results, check if empty archives should be hidden
                $show_empty = $settings->get('author_archives_show_empty', false);
                if (!$show_empty) {
                    $author_id = get_queried_object_id();
                    // Check if author has any published posts
                    $post_count = count_user_posts($author_id, 'post', true); // true = only public posts
                    if ($post_count == 0) {
                        // Remove 'index' if present
                        if (($key = array_search('index', $robots)) !== false) {
                            unset($robots[$key]);
                        }
                        // Add 'noindex' if not present
                        if (!in_array('noindex', $robots)) {
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
