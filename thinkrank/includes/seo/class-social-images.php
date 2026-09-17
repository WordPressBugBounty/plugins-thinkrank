<?php
/**
 * The set of images a page offers to Open Graph consumers.
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
 * Collects the secondary images that follow the primary `og:image`.
 *
 * Open Graph allows several `og:image` tags on one page and consumers treat
 * the first as primary: Facebook lets the sharer pick between them, and others
 * fall back down the list when the first fails validation (too small, wrong
 * aspect, 404 behind a CDN). ThinkRank published exactly one, so a post whose
 * social image failed validation shared with no image at all rather than with
 * its second-best one.
 *
 * This produces everything *after* the primary, in the order the issue asked
 * for: the featured image (when a per-post override displaced it), then images
 * found in the content. The primary itself is resolved elsewhere and passed in,
 * so there is one answer to "what is the main image" and this cannot disagree
 * with it.
 *
 * Twitter and schema are deliberately not involved. A Twitter card renders one
 * image and schema's `image` identifies the page's primary; handing either a
 * list would change what they mean, not enrich it.
 *
 * @since 2.7.0
 */
class Social_Images {

    /**
     * Total `og:image` tags a page may carry, primary included.
     *
     * Four is enough to give a sharer a real choice without turning the head
     * into a gallery; consumers read the list in order and stop at the first
     * that validates, so length past this point buys nothing.
     *
     * @since 2.7.0
     * @var int
     */
    public const DEFAULT_LIMIT = 4;

    /**
     * Every image after the primary, ready to emit.
     *
     * @since 2.7.0
     *
     * @param int    $post_id     Post being rendered.
     * @param string $primary_url The `og:image` already resolved for the page.
     * @return array<int, array<string, mixed>> Each with url, and width/height/
     *                                          type/alt where they are known.
     */
    public static function additional(int $post_id, string $primary_url): array {
        if ($post_id <= 0) {
            return [];
        }

        /**
         * Filter how many og:image tags a page may carry in total.
         *
         * @since 2.7.0
         *
         * @param int $limit   Maximum tags, primary included.
         * @param int $post_id Post being rendered.
         */
        $limit = (int) apply_filters('thinkrank_og_image_limit', self::DEFAULT_LIMIT, $post_id);

        // One slot is the primary's, so nothing below can be offered unless the
        // site asked for at least two.
        if ($limit < 2) {
            return [];
        }

        // The primary occupies the first slot whether or not it was resolvable
        // here, so it seeds the seen-list and can never be offered twice.
        $seen = [];

        if ('' !== $primary_url) {
            $seen[self::fingerprint($primary_url)] = true;
        }

        $images = [];

        foreach (self::candidates($post_id) as $url) {
            if (count($images) >= $limit - 1) {
                break;
            }

            $url = self::usable($url);

            if ('' === $url) {
                continue;
            }

            $print = self::fingerprint($url);

            if (isset($seen[$print])) {
                continue;
            }

            $seen[$print] = true;
            $images[]     = self::describe($url, $post_id);
        }

        /**
         * Filter the secondary Open Graph images for a page.
         *
         * @since 2.7.0
         *
         * @param array $images  Secondary images, primary excluded.
         * @param int   $post_id Post being rendered.
         */
        return (array) apply_filters('thinkrank_og_extra_images', $images, $post_id);
    }

    /**
     * Candidate URLs, in the order they should be offered.
     *
     * @since 2.7.0
     * @param int $post_id Post being rendered.
     * @return array<int, string>
     */
    private static function candidates(int $post_id): array {
        $urls = [];

        // The featured image. Usually this IS the primary and the fingerprint
        // check drops it; it matters when a per-post social override displaced
        // it, which is exactly the case where a second image is worth offering.
        $thumbnail_id = (int) get_post_thumbnail_id($post_id);

        if ($thumbnail_id) {
            $src = wp_get_attachment_image_src($thumbnail_id, 'large');

            if (is_array($src) && !empty($src[0])) {
                $urls[] = (string) $src[0];
            }
        }

        foreach (self::from_content($post_id) as $url) {
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * Image URLs in the post body, in document order.
     *
     * Reads the stored content rather than running it through `the_content`.
     * This is called while the document head is being written, and rendering
     * the body there would run every shortcode and block on the page for the
     * sake of a few `src` attributes.
     *
     * @since 2.7.0
     * @param int $post_id Post being rendered.
     * @return array<int, string>
     */
    private static function from_content(int $post_id): array {
        $content = (string) get_post_field('post_content', $post_id);

        if ('' === $content || false === stripos($content, '<img')) {
            return [];
        }

        if (!preg_match_all('/<img\b[^>]*>/i', $content, $tags)) {
            return [];
        }

        $urls = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $match)) {
                $urls[] = $match[1];
            }
        }

        return $urls;
    }

    /**
     * A URL Open Graph can actually use, or '' to skip it.
     *
     * @since 2.7.0
     * @param string $url Raw candidate.
     * @return string
     */
    private static function usable(string $url): string {
        $url = trim($url);

        if ('' === $url) {
            return '';
        }

        // Inline data and blob URLs are not fetchable by a crawler, and a
        // protocol-relative or root-relative src cannot be resolved without
        // guessing the host it belongs to.
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '';
        }

        return (string) esc_url_raw($url);
    }

    /**
     * An image plus whatever is known about it.
     *
     * Dimensions and type are only claimed for an attachment on this site.
     * Guessing them for a remote image would publish numbers a consumer uses
     * to lay out a card before it has fetched the file.
     *
     * @since 2.7.0
     *
     * @param string $url     Image URL.
     * @param int    $post_id Post being rendered, for the alt fallback.
     * @return array<string, mixed>
     */
    private static function describe(string $url, int $post_id): array {
        $image = ['url' => $url];

        $attachment_id = (int) attachment_url_to_postid($url);

        if ($attachment_id) {
            $meta = wp_get_attachment_metadata($attachment_id);

            // Vector uploads report 0x0; publishing that as a dimension is
            // invalid, so the companions are omitted rather than zeroed.
            $width  = is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0;
            $height = is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0;

            if ($width > 0 && $height > 0) {
                $image['width']  = $width;
                $image['height'] = $height;
            }

            $type = (string) get_post_mime_type($attachment_id);

            if ('' !== $type) {
                $image['type'] = $type;
            }

            $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));

            if ('' !== $alt) {
                $image['alt'] = $alt;
            }
        }

        if (!isset($image['alt'])) {
            $alt = self::alt_from_content($url, $post_id);

            if ('' !== $alt) {
                $image['alt'] = $alt;
            }
        }

        return $image;
    }

    /**
     * The `alt` the author wrote on this image in the body.
     *
     * The attachment's own alt text wins where there is one, but an image
     * inserted with a different alt — or one hosted elsewhere, which has no
     * attachment at all — still has the author's words on it.
     *
     * @since 2.7.0
     *
     * @param string $url     Image URL.
     * @param int    $post_id Post being rendered.
     * @return string
     */
    private static function alt_from_content(string $url, int $post_id): string {
        $content = (string) get_post_field('post_content', $post_id);

        if ('' === $content) {
            return '';
        }

        if (!preg_match_all('/<img\b[^>]*>/i', $content, $tags)) {
            return '';
        }

        foreach ($tags[0] as $tag) {
            if (!preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $src)) {
                continue;
            }

            if (self::fingerprint(trim($src[1])) !== self::fingerprint($url)) {
                continue;
            }

            if (preg_match('/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag, $alt)) {
                return trim($alt[1]);
            }
        }

        return '';
    }

    /**
     * An identity for a URL that survives WordPress's size derivatives.
     *
     * `logo.jpg`, `logo-1024x768.jpg` and the `large` derivative of the same
     * upload are one image to a reader, and offering all three as alternatives
     * is noise. Scheme and query are dropped for the same reason — the same
     * file over http and https is not a second option.
     *
     * @since 2.7.0
     * @param string $url Image URL.
     * @return string
     */
    private static function fingerprint(string $url): string {
        $parts = wp_parse_url($url);

        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : $url;

        // Strip a trailing -WxH that WordPress appends to a resized copy.
        $path = (string) preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $path);

        return $host . $path;
    }
}
