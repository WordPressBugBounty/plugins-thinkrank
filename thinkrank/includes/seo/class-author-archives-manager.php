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
        if (!is_author() || !self::metas_enabled()) {
            return;
        }

        $meta_desc = self::resolve_description((int) get_queried_object_id());
        if ($meta_desc === '') {
            return;
        }

        $this->print_meta_description($meta_desc);
    }

    /**
     * Whether the Content Type Matrix lets ThinkRank own the author archive's
     * title and meta description.
     *
     * SEO_Manager asks the same question for every other page type and steps
     * aside when Metas is Off, but it hands author archives to this class, which
     * never asked: Off on the Author archives row left the description tag and
     * the templated <title> in place (#1334).
     *
     * @since 2.18.0
     * @return bool
     */
    private static function metas_enabled(): bool {
        return Content_Type_Settings::is_enabled_for_current(Content_Type_Settings::FEATURE_META, true);
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
        $description = self::tidy_whitespace($description);
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
        // The implementation moved to Seo_Text so the four other
        // description paths could stop carrying the broken version of it
        // (#687). This stays as the local name the author-archive code reads.
        return \ThinkRank\Core\Seo_Text::trim_to_length($description, $limit);
    }

    /**
     * Modify document title for author archives
     *
     * @since 1.0.0
     * @param string $title Original title
     * @return string Modified title
     */
    public function modify_document_title(string $title): string {
        if (!is_author() || !self::metas_enabled()) {
            return $title;
        }

        $resolved = self::resolve_title((int) get_queried_object_id());

        return $resolved === '' ? $title : $this->with_page_suffix($resolved);
    }

    /**
     * The URL to publish for an author in schema and social data.
     *
     * The author archive while archives are on. With them off,
     * maybe_redirect_author_archives() 302s every /author/<name>/ to the
     * homepage, so pointing a Person at it links the author to a redirect.
     * The user's own website (the profile's Website field) stands in then,
     * and with none set there is no URL at all (#1339).
     *
     * @since 2.18.1
     * @param int $user_id Author user ID.
     * @return string Absolute URL, or '' when the author has none to publish.
     */
    public static function author_url(int $user_id): string {
        if ($user_id <= 0) {
            return '';
        }

        if (Settings::instance()->get('author_archives_enabled', true)) {
            return (string) get_author_posts_url($user_id);
        }

        $website = trim((string) get_the_author_meta('user_url', $user_id));

        return '' === $website ? '' : (string) esc_url_raw($website);
    }

    /**
     * The SEO title an author archive renders, without the page indicator.
     *
     * A per-author SEO title (e.g. imported from another SEO plugin) overrides
     * the Author Archives title template entirely. Public so SEO_Manager can
     * hand the same string to og:title and twitter:title: they used to inherit
     * the Site Identity author title instead, so the share card disagreed with
     * the document <title> this class prints (#977).
     *
     * @since 2.14.3
     * @param int $author_id Queried author.
     * @return string Rendered title, or '' when the template is empty.
     */
    public static function resolve_title(int $author_id): string {
        $custom_title = (string) get_user_meta($author_id, '_thinkrank_seo_title', true);
        if (trim($custom_title) !== '') {
            return self::render_template($custom_title, $author_id);
        }

        $template = (string) Settings::instance()->get('author_archives_title', Settings::DEFAULT_AUTHOR_ARCHIVES_TITLE);
        if (trim($template) === '') {
            return '';
        }

        return self::render_template($template, $author_id);
    }

    /**
     * The meta description an author archive renders, before the length trim.
     *
     * A per-author meta description overrides the Author Archives description
     * template. Public for the same reason as resolve_title(): it is also the
     * og:description and twitter:description of the archive (#977).
     *
     * @since 2.14.3
     * @param int $author_id Queried author.
     * @return string Rendered description, or '' when there is none.
     */
    public static function resolve_description(int $author_id): string {
        $custom_desc = (string) get_user_meta($author_id, '_thinkrank_meta_description', true);
        if (trim($custom_desc) !== '') {
            return self::render_template($custom_desc, $author_id);
        }

        // Default value matches what we set in endpoint
        $template = (string) Settings::instance()->get('author_archives_meta_desc', Settings::DEFAULT_AUTHOR_ARCHIVES_META_DESC);
        if (trim($template) === '') {
            return '';
        }

        return self::render_template($template, $author_id);
    }

    /**
     * Fill an author-archive template with the author and site values.
     *
     * The Author Archives tab offers %author_name%, %site_title%, %separator%
     * and %page%, but the importers write Site Identity vocabulary into the
     * same setting: Rank Math's stock "%name% %sep% %sitename%" arrives as
     * "%author_name% %sep% %site_title%", and %sep% used to reach the <title>
     * as literal text. The Site Identity aliases resolve here too, with the
     * values that context gives them on an author archive.
     *
     * %page% resolves to nothing. modify_document_title() runs at priority 15,
     * after SEO_Manager's at priority 1, so its own page indicator was the one
     * that reached the page, and it rendered without the separator the rest
     * of the site uses ("admin | tr Page 2" against "Uncategorized | tr |
     * Page 2" everywhere else). The token stays recognised so a template that
     * already contains it does not leak the literal string; the indicator
     * itself comes from the one helper every other context uses (#397 review).
     *
     * @since 2.14.3
     * @param string $template  Title or description template.
     * @param int    $author_id Queried author.
     * @return string Rendered text with whitespace tidied.
     */
    private static function render_template(string $template, int $author_id): string {
        $author_name = (string) get_the_author_meta('display_name', $author_id);
        // The Site Identity Site Name, as everywhere else %site_title% and
        // %sitename% are resolved (#1331).
        $site_title  = class_exists('ThinkRank\SEO\Site_Identity_Manager')
            ? \ThinkRank\SEO\Site_Identity_Manager::site_name()
            : (string) get_bloginfo('name');
        $tagline     = (string) get_bloginfo('description');

        $separator = '-';
        if (class_exists('ThinkRank\SEO\Site_Identity_Manager')) {
            $separator = \ThinkRank\SEO\Site_Identity_Manager::get_active_separator_symbol();
        }

        $replacements = [
            '%author_name%'      => $author_name,
            '%author%'           => $author_name,
            '%archive_title%'    => $author_name,
            // The Content Types vocabulary (%title%, %sitename%) and Rank
            // Math's author token (%name%) name the same values here (#1330).
            '%title%'            => $author_name,
            '%name%'             => $author_name,
            '%site_title%'       => $site_title,
            '%site_name%'        => $site_title,
            '%sitename%'         => $site_title,
            '%site_description%' => $tagline,
            '%tagline%'          => $tagline,
            '%separator%'        => $separator,
            '%sep%'              => $separator,
            // An author archive has no date of its own. This used to be the
            // current month, which no author page is about (#1330).
            '%date%'             => '',
            '%page%'             => '',
        ];

        // Anything else (%currentyear%, %term%, a Yoast %%token%%) is dropped
        // before substitution, the rule the Content Types templates follow,
        // rather than reaching the <title> as literal text (#1330).
        $template = Pattern_Resolver::strip_unresolved_tokens($template, $replacements);

        $rendered = self::tidy_whitespace(str_replace(array_keys($replacements), array_values($replacements), $template));

        return self::collapse_separators($rendered, [$separator, '|']);
    }

    /**
     * Collapse and trim the separators that tokens resolving to nothing leave.
     *
     * Author templates carry the active separator through %separator% and,
     * often, a literal pipe typed by hand, so both are tidied: "admin | |
     * Media Wipe |" becomes "admin | Media Wipe".
     *
     * @since 2.18.0
     * @param string   $value      Rendered template, whitespace already tidied.
     * @param string[] $separators Separator symbols to collapse.
     * @return string
     */
    private static function collapse_separators(string $value, array $separators): string {
        foreach (array_unique(array_filter(array_map('trim', $separators), 'strlen')) as $symbol) {
            $quoted  = preg_quote($symbol, '/');
            $doubled = '/\s*' . $quoted . '\s*' . $quoted . '\s*/u';
            do {
                $before = $value;
                $value  = (string) preg_replace($doubled, ' ' . $symbol . ' ', $value);
            } while ($value !== $before);

            $value = \ThinkRank\Core\Seo_Text::trim_separator($value, $symbol);
        }

        return self::tidy_whitespace($value);
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
    private static function tidy_whitespace(string $value): string {
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
