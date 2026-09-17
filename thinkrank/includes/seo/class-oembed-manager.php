<?php
/**
 * What ThinkRank puts in the oEmbed card other sites show for a post.
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
 * Applies the site's SEO metadata to WordPress's oEmbed response.
 *
 * Paste a link to a post into another WordPress site, or into an app that
 * speaks oEmbed, and WordPress answers with a small card: title, author,
 * thumbnail. None of it knows about the SEO metadata the site has written. The
 * card shows the raw post title rather than the SEO title, carries the author's
 * personal name whether or not the site wants that travelling with every embed,
 * and has no image at all unless the post happens to have a featured one — the
 * social image the site chose is never consulted.
 *
 * Three settings, all default off, change that. Off by default because this
 * rewrites what other people's sites display: an upgrade must not silently
 * change the card an existing embed has been showing for months.
 *
 * Scope worth being precise about. A non-WordPress consumer renders the oEmbed
 * *payload*. A WordPress-to-WordPress embed renders an iframe of this site's
 * own embed template instead, with the payload's `html` blockquote as its
 * fallback. `oembed_use_seo_title` covers all three: the payload `title`, the
 * blockquote link text and iframe `title` inside `html`, and the heading of
 * the embed template (#737). Core's template shows no author line, so
 * `oembed_remove_author` is about the payload only: apps, bots and unfurlers.
 *
 * @since 2.7.0
 */
class Oembed_Manager {

    /**
     * Social settings, read once per request.
     *
     * @since 2.7.0
     * @var array|null
     */
    private $settings = null;

    /**
     * The post whose oEmbed response is being built, or 0.
     *
     * Core writes the response `html` with get_the_title() from its own
     * `oembed_response_data` callback, so the title filter has to know that
     * build is under way.
     *
     * @since 2.7.0
     * @var int
     */
    private $card_post_id = 0;

    /**
     * Register the response filter.
     *
     * @since 2.7.0
     * @return void
     */
    public function init(): void {
        // Core adds the response `html` at priority 10, from default-filters,
        // so ahead of this plugin's own callback at the same priority. The
        // card window opens before that and closes after everything else.
        add_filter('oembed_response_data', [$this, 'begin_card'], 9, 2);
        add_filter('oembed_response_data', [$this, 'filter_response'], 10, 2);
        add_filter('oembed_response_data', [$this, 'end_card'], PHP_INT_MAX);
        add_filter('the_title', [$this, 'filter_card_title'], 20, 2);
    }

    /**
     * Note which post an oEmbed response is being built for.
     *
     * @since 2.7.0
     *
     * @param mixed $data Response data, passed through untouched.
     * @param mixed $post Post being embedded.
     * @return mixed
     */
    public function begin_card($data, $post) {
        $this->card_post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

        return $data;
    }

    /**
     * Close the card window once the response is complete.
     *
     * @since 2.7.0
     *
     * @param mixed $data Response data, passed through untouched.
     * @return mixed
     */
    public function end_card($data) {
        $this->card_post_id = 0;

        return $data;
    }

    /**
     * Put the SEO title wherever the embed card prints the post title.
     *
     * That is the `html` core builds for the oEmbed response (blockquote link
     * and iframe title) and the heading of the embed template. Everywhere else
     * the title is left alone, and the cheap checks run before any setting is
     * read, since `the_title` fires for every title on every page.
     *
     * Core prints this value as HTML, so the plain-text SEO title is escaped.
     *
     * @since 2.7.0
     *
     * @param mixed $title   Title.
     * @param mixed $post_id Post ID.
     * @return mixed
     */
    public function filter_card_title($title, $post_id = 0) {
        $post_id = (int) $post_id;

        if ($post_id <= 0 || !$this->is_card_title($post_id)) {
            return $title;
        }

        if (empty($this->settings()['oembed_use_seo_title'])) {
            return $title;
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return $title;
        }

        $seo_title = $this->seo_title($post);

        return '' === $seo_title ? $title : esc_html($seo_title);
    }

    /**
     * Whether a title is being printed for an embed card.
     *
     * @since 2.7.0
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private function is_card_title(int $post_id): bool {
        if ($post_id === $this->card_post_id) {
            return true;
        }

        return function_exists('is_embed') && is_embed() && $post_id === (int) get_queried_object_id();
    }

    /**
     * Apply the site's choices to one oEmbed response.
     *
     * @since 2.7.0
     *
     * Untyped in and out on purpose. WordPress hands a filter whatever the
     * previous callback returned, so the guards below have to be able to hand
     * it straight back — and a declared `: array` return would turn passing a
     * non-array through into a TypeError on the front end, which is the one
     * thing a defensive guard must not do.
     *
     * @param mixed $data The response data, normally an array.
     * @param mixed $post The post the response describes.
     * @return mixed
     */
    public function filter_response($data, $post) {
        if (!is_array($data)) {
            return $data;
        }

        if (!$post instanceof \WP_Post) {
            return $data;
        }

        $settings = $this->settings();

        if (!empty($settings['oembed_use_seo_title'])) {
            $title = $this->seo_title($post);

            if ('' !== $title) {
                $data['title'] = $title;
            }
        }

        if (!empty($settings['oembed_use_social_image'])) {
            $data = $this->with_social_image($data, $post, $settings);
        }

        if (!empty($settings['oembed_remove_author'])) {
            unset($data['author_name'], $data['author_url']);
        }

        return $data;
    }

    /**
     * The post's SEO title, as an embed card should carry it.
     *
     * Deliberately the same chain the breadcrumb trail uses: the post's own
     * `_thinkrank_seo_title` with its variable tags resolved, and nothing else.
     * The global title pattern is not part of it, for the reason it is not part
     * of a breadcrumb either — resolving it appends the site name, and an
     * oEmbed payload already carries the site name in `provider_name`, so the
     * card would read "Post Title | Site Name — Site Name".
     *
     * @since 2.7.0
     * @param \WP_Post $post Post being embedded.
     * @return string Title, or '' to leave WordPress's alone.
     */
    private function seo_title(\WP_Post $post): string {
        $seo_title = trim((string) get_post_meta($post->ID, '_thinkrank_seo_title', true));

        if ('' === $seo_title) {
            return '';
        }

        return trim(Pattern_Resolver::resolve_value($seo_title, $post->ID));
    }

    /**
     * Put the site's chosen social image on the card.
     *
     * Only ever replaces a thumbnail with a deliberate choice: the post's own
     * Open Graph image beats the featured image WordPress already used, and the
     * site-wide default is consulted only when the post has neither, so a post
     * with a featured image and no social image keeps the card it had.
     *
     * @since 2.7.0
     *
     * @param array    $data     Response data.
     * @param \WP_Post $post     Post being embedded.
     * @param array    $settings Social settings.
     * @return array
     */
    private function with_social_image(array $data, \WP_Post $post, array $settings): array {
        $image = trim((string) get_post_meta($post->ID, '_thinkrank_og_image', true));

        if ('' === $image) {
            // WordPress already put the featured image here, and it is a
            // better answer than a site-wide default.
            if (!empty($data['thumbnail_url'])) {
                return $data;
            }

            foreach (['default_og_image', 'default_image'] as $key) {
                $candidate = trim((string) ($settings[$key] ?? ''));

                if ('' !== $candidate) {
                    $image = $candidate;
                    break;
                }
            }
        }

        if ('' === $image) {
            return $data;
        }

        $image = esc_url_raw($image);

        if ('' === $image) {
            return $data;
        }

        $data['thumbnail_url'] = $image;

        // oEmbed requires width and height alongside a thumbnail_url, and a
        // consumer is entitled to reject a payload carrying one without them.
        // WordPress itself always sends all three, so a partial replacement
        // would leave the old image's dimensions describing the new image.
        [$width, $height] = $this->image_dimensions($image, $settings);

        if ($width > 0 && $height > 0) {
            $data['thumbnail_width']  = $width;
            $data['thumbnail_height'] = $height;
        } else {
            unset($data['thumbnail_width'], $data['thumbnail_height']);
        }

        return $data;
    }

    /**
     * Real dimensions for an image URL, or the configured Open Graph size.
     *
     * @since 2.7.0
     *
     * @param string $url      Image URL.
     * @param array  $settings Social settings.
     * @return array{0:int,1:int} Width and height; 0,0 when unknown.
     */
    private function image_dimensions(string $url, array $settings): array {
        $attachment_id = attachment_url_to_postid($url);

        if ($attachment_id) {
            $meta = wp_get_attachment_image_src($attachment_id, 'full');

            if (is_array($meta) && !empty($meta[1]) && !empty($meta[2])) {
                return [(int) $meta[1], (int) $meta[2]];
            }
        }

        // An image hosted elsewhere cannot be measured without fetching it, so
        // fall back to the dimensions the site already declares for its Open
        // Graph images rather than dropping the thumbnail entirely.
        $width  = (int) ($settings['og_image_width'] ?? 0);
        $height = (int) ($settings['og_image_height'] ?? 0);

        return [max(0, $width), max(0, $height)];
    }

    /**
     * Site-context social settings, read once.
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
     * Read the social settings.
     *
     * Its own method so the filter can be exercised without standing up a
     * settings store: the interesting behaviour here is which of three
     * switches changed which field, not where the switches came from.
     *
     * @since 2.7.0
     * @return array
     */
    protected function load_settings(): array {
        return (array) (new Social_Meta_Manager())->get_settings('site', null);
    }
}
