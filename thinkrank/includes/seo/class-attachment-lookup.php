<?php
/**
 * Which attachment an image URL belongs to, asked at most once.
 *
 * @package ThinkRank
 * @since   2.12.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves image URLs to attachments without paying for it per call.
 *
 * attachment_url_to_postid() is one uncached query that reads every
 * `_wp_attached_file` row, and it only recognises the ORIGINAL upload: the URL
 * of a generated size (`photo-1024x768.jpg`) returns 0. Open Graph, Twitter,
 * schema, oEmbed and Image SEO each called it directly, mostly with the `large`
 * URL of a featured image whose ID they had been holding a moment earlier — so
 * a page paid for the same query several times and never got an answer (#847).
 *
 * Three ways to an ID, cheapest first:
 *
 * - **remember()** — the caller built the URL from an attachment ID, so it
 *   says so and nothing downstream has to ask.
 * - **a hint** — an ID read from markup (`wp-image-123`). Believed only when
 *   that attachment really owns the file the URL names.
 * - **the database** — one query, cached, that asks for the URL as written and
 *   for the original behind it if it looks like a generated size.
 *
 * @since 2.12.0
 */
class Attachment_Lookup {

    /**
     * Object-cache group. ThinkRank's own, so "purge caches" clears it.
     *
     * @since 2.12.0
     * @var string
     */
    private const CACHE_GROUP = 'thinkrank';

    /**
     * What core appends to the stored file when it replaces an upload: a
     * downscaled copy of an image over the big-image threshold, or one turned
     * upright from its EXIF orientation. The generated sizes are still named
     * after the file as uploaded, so `photo-1024x683.jpg` belongs to
     * `photo-scaled.jpg` and stripping the dimensions alone misses it.
     *
     * @since 2.12.0
     * @var string[]
     */
    private const REPLACED_ORIGINAL_SUFFIXES = ['-scaled', '-rotated'];

    /**
     * URLs whose attachment the caller already knew, for this request.
     *
     * @since 2.12.0
     * @var array<string, int>
     */
    private static array $known = [];

    /**
     * Record the attachment a URL was built from.
     *
     * @since 2.12.0
     *
     * @param string $url           Image URL, exactly as it will be asked about.
     * @param int    $attachment_id Attachment it came from.
     * @return void
     */
    public static function remember(string $url, int $attachment_id): void {
        if ('' !== $url && $attachment_id > 0) {
            self::$known[$url] = $attachment_id;
        }
    }

    /**
     * Forget everything remembered for this request.
     *
     * @since 2.12.0
     * @return void
     */
    public static function forget(): void {
        self::$known = [];
    }

    /**
     * Attachment ID named by a `wp-image-{ID}` class, or 0.
     *
     * The editor puts it on every image it inserts. It is a claim made by
     * markup, not a fact: content copied from another site carries that site's
     * IDs. Pass it to id_from_url() as the hint, which checks it.
     *
     * @since 2.12.0
     *
     * @param string $html An `<img>` tag, or just its class attribute value.
     * @return int
     */
    public static function hint_from_markup(string $html): int {
        if (preg_match('/(?<![\w-])wp-image-(\d+)(?![\w-])/', $html, $match)) {
            return (int) $match[1];
        }

        return 0;
    }

    /**
     * Attachment ID behind an image URL, or 0 when it is not in the library.
     *
     * @since 2.12.0
     *
     * @param string $url  Image URL, original or a generated size.
     * @param int    $hint Optional. An ID the markup claims; verified before use.
     * @return int
     */
    public static function id_from_url(string $url, int $hint = 0): int {
        $url = trim($url);

        if ('' === $url) {
            return 0;
        }

        if (isset(self::$known[$url])) {
            return self::$known[$url];
        }

        if ($hint > 0 && null !== self::listed_file($hint, $url)) {
            self::$known[$url] = $hint;

            return $hint;
        }

        // Salted with the posts group's last_changed, which core moves whenever
        // a post — an attachment included — is added, edited or deleted. An
        // upload therefore retires a cached miss, and a deletion a cached hit.
        $key    = 'attachment_id:' . md5($url) . ':' . wp_cache_get_last_changed('posts');
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        if (false !== $cached) {
            return (int) $cached;
        }

        $attachment_id = self::query($url);

        // Misses are stored too: an image hosted elsewhere is the URL that
        // would otherwise be asked about on every view.
        wp_cache_set($key, $attachment_id, self::CACHE_GROUP, DAY_IN_SECONDS);

        return $attachment_id;
    }

    /**
     * Dimensions and type of the file a URL points at.
     *
     * An attachment's own metadata describes the original. The URL being
     * published is often a generated size, and announcing the original's
     * 2000x1500 beside a 1024x768 file gives a consumer numbers to lay a card
     * out with that the image does not match.
     *
     * @since 2.12.0
     *
     * @param int    $attachment_id Attachment the URL belongs to.
     * @param string $url           The URL being described.
     * @return array{width:int, height:int, type:string} Zero dimensions when
     *                                                   unknown (vectors too).
     */
    public static function describe(int $attachment_id, string $url): array {
        // No attachment, nothing to describe. Without this the `-WxH` fallback
        // below would read a size out of the file name of a URL that is not in
        // the library at all — a remote `photo-1024x768.jpg` would be published
        // as 1024x768 on the word of its name. Every caller checks
        // id_from_url() first, so this only holds the contract for the next one.
        if ($attachment_id <= 0) {
            return ['width' => 0, 'height' => 0, 'type' => ''];
        }

        $type = (string) get_post_mime_type($attachment_id);
        $file = self::listed_file($attachment_id, $url);

        if (null !== $file) {
            return [
                'width'  => $file['width'],
                'height' => $file['height'],
                'type'   => '' !== $file['type'] ? $file['type'] : $type,
            ];
        }

        // A generated size the metadata no longer lists (thumbnails were
        // regenerated since the URL was written) still states its dimensions
        // in its name.
        if (preg_match('/-(\d+)x(\d+)\.[a-zA-Z0-9]+$/', self::path($url), $match)) {
            return [
                'width'  => (int) $match[1],
                'height' => (int) $match[2],
                'type'   => $type,
            ];
        }

        $meta = wp_get_attachment_metadata($attachment_id);

        return [
            'width'  => is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0,
            'height' => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0,
            'type'   => $type,
        ];
    }

    /**
     * Ask the database once for every file the URL could be.
     *
     * This is attachment_url_to_postid() with an IN list where core has one
     * value: the same normalisation, the same table, the same two filters.
     * Asking core itself once per candidate would cost a generated-size URL
     * that is not in the library four of the scans this class exists to avoid.
     *
     * The URL as written has priority: an upload the author named
     * `banner-1200x630.jpg` is an original, and must not be mistaken for a
     * size of some other `banner.jpg`.
     *
     * @since 2.12.0
     * @param string $url Image URL.
     * @return int
     */
    private static function query(string $url): int {
        /** This filter is documented in wp-includes/media.php */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, applied for parity with attachment_url_to_postid().
        $pre = apply_filters('pre_attachment_url_to_postid', null, $url);

        if (null !== $pre) {
            return (int) $pre;
        }

        $paths         = self::candidate_paths($url);
        $stored        = self::stored_paths($paths);
        $attachment_id = 0;

        foreach ($paths as $path) {
            if (isset($stored[$path])) {
                $attachment_id = $stored[$path];
                break;
            }
        }

        // MySQL matched case-insensitively and nothing matched exactly, which
        // core resolves the same way: take what the database found.
        if (!$attachment_id && [] !== $stored) {
            foreach ($paths as $path) {
                foreach ($stored as $meta_value => $stored_id) {
                    if (0 === strcasecmp((string) $meta_value, $path)) {
                        $attachment_id = $stored_id;
                        break 2;
                    }
                }
            }
        }

        /** This filter is documented in wp-includes/media.php */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, applied for parity with attachment_url_to_postid().
        return (int) apply_filters('attachment_url_to_postid', $attachment_id, $url);
    }

    /**
     * What `_wp_attached_file` could hold for this URL, most likely first.
     *
     * The first entry is what core itself would ask for: the URL relative to
     * the uploads directory, or the URL untouched when it is not under it. A
     * `-WxH` suffix adds the original it would have been generated from, and
     * the `-scaled` / `-rotated` copies core stores in the original's place.
     *
     * @since 2.12.0
     * @param string $url Image URL.
     * @return array<int, string>
     */
    private static function candidate_paths(string $url): array {
        $dir  = wp_get_upload_dir();
        $path = $url;

        // Core forces the URL's scheme to the uploads directory's, so an http
        // URL still finds a file the site serves over https.
        $site_scheme = wp_parse_url((string) ($dir['url'] ?? ''), PHP_URL_SCHEME);
        $url_scheme  = wp_parse_url($path, PHP_URL_SCHEME);

        if (is_string($url_scheme) && is_string($site_scheme) && $url_scheme !== $site_scheme) {
            $path = $site_scheme . substr($path, strlen($url_scheme));
        }

        $base = (string) ($dir['baseurl'] ?? '') . '/';

        if ('/' !== $base && 0 === strpos($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $paths    = [$path];
        $original = preg_replace('/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $path);

        if (is_string($original) && $original !== $path) {
            $paths[] = $original;

            foreach (self::REPLACED_ORIGINAL_SUFFIXES as $suffix) {
                $replaced = preg_replace('/(?=\.[a-zA-Z0-9]+$)/', $suffix, $original, 1);

                if (is_string($replaced)) {
                    $paths[] = $replaced;
                }
            }
        }

        return $paths;
    }

    /**
     * Which of the paths the library stores, as meta_value => attachment ID.
     *
     * @since 2.12.0
     * @param array<int, string> $paths Candidate `_wp_attached_file` values.
     * @return array<string, int>
     */
    private static function stored_paths(array $paths): array {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($paths), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is one %s per path, built above; the sniff cannot count through the variable.
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders})",
                $paths
            )
        );

        $stored = [];

        foreach ((array) $rows as $row) {
            if (!isset($row->meta_value, $row->post_id)) {
                continue;
            }

            // Two attachments with one file: the first row, as in core.
            if (!isset($stored[(string) $row->meta_value])) {
                $stored[(string) $row->meta_value] = (int) $row->post_id;
            }
        }

        return $stored;
    }

    /**
     * The metadata entry for the file a URL names, if this attachment has one.
     *
     * Doubles as the ownership check for a hinted ID: an attachment owns a URL
     * when the URL ends in a file its metadata lists, directory included.
     *
     * @since 2.12.0
     *
     * @param int    $attachment_id Attachment to look in.
     * @param string $url           Image URL.
     * @return array{width:int, height:int, type:string}|null Null when the
     *                                                        attachment lists no such file.
     */
    private static function listed_file(int $attachment_id, string $url): ?array {
        $meta = wp_get_attachment_metadata($attachment_id);

        if (!is_array($meta) || empty($meta['file']) || !is_string($meta['file'])) {
            return null;
        }

        $path = self::path($url);
        $dir  = dirname($meta['file']);
        $dir  = '.' === $dir ? '/' : '/' . $dir . '/';

        if (self::ends_with($path, $dir . wp_basename($meta['file']))) {
            return [
                'width'  => (int) ($meta['width'] ?? 0),
                'height' => (int) ($meta['height'] ?? 0),
                'type'   => '',
            ];
        }

        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            if (!is_array($size) || empty($size['file'])) {
                continue;
            }

            if (self::ends_with($path, $dir . $size['file'])) {
                return [
                    'width'  => (int) ($size['width'] ?? 0),
                    'height' => (int) ($size['height'] ?? 0),
                    'type'   => (string) ($size['mime-type'] ?? ''),
                ];
            }
        }

        // The file as uploaded, kept beside a -scaled or -rotated replacement.
        // Its dimensions are not recorded — the metadata's are the replacement's.
        if (!empty($meta['original_image']) && self::ends_with($path, $dir . $meta['original_image'])) {
            return ['width' => 0, 'height' => 0, 'type' => ''];
        }

        return null;
    }

    /**
     * The decoded path of a URL, which is what metadata file names compare to.
     *
     * @since 2.12.0
     * @param string $url Image URL.
     * @return string
     */
    private static function path(string $url): string {
        $path = wp_parse_url($url, PHP_URL_PATH);

        return is_string($path) ? rawurldecode($path) : '';
    }

    /**
     * @since 2.12.0
     * @param string $haystack String to look in.
     * @param string $needle   Ending to look for.
     * @return bool
     */
    private static function ends_with(string $haystack, string $needle): bool {
        return '' !== $needle && substr($haystack, -strlen($needle)) === $needle;
    }
}
