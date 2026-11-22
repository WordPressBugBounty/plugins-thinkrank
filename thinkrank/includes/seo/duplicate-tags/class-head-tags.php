<?php
/**
 * Duplicate Tag Guard: find the SEO tags in a page's head, tell ours from
 * everyone else's, and take out the foreign copies of ours.
 *
 * @package ThinkRank\SEO\Duplicate_Tags
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Duplicate_Tags;

use ThinkRank\Diagnostics\Foreign_Schema_Detector;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Head Tags
 *
 * Themes and social plugins print their own `<title>`, Open Graph and
 * canonical tags constantly, and ThinkRank prints its own. Two `og:image`
 * tags and Facebook picks the wrong one; two canonicals and Google may ignore
 * both (#733).
 *
 * This class reads a head as text rather than through DOMDocument, because
 * whatever it does not remove must leave byte for byte as it came in. It
 * finds the tags that carry SEO meaning, and marks each as ThinkRank's or
 * foreign:
 *
 * - ThinkRank prints each group of head tags between a pair of comments,
 *   `<!-- ThinkRank … -->` and `<!-- /ThinkRank … -->`. A tag inside a pair is
 *   ours. The outer "Search Engine Optimization by ThinkRank" wrapper is not
 *   used: it spans wp_head priorities 1–99, and another plugin hooked in
 *   between would be taken for us.
 * - The `<title>` is printed by WordPress core from our
 *   `pre_get_document_title` answer, outside any pair, so it is recognised by
 *   its text.
 * - JSON-LD is ours when it carries a `data-thinkrank` attribute, the marker
 *   every ThinkRank emitter sets.
 *
 * Comments, scripts, styles and `<noscript>` are opaque: a `<meta>` inside
 * a string in a script is not a tag.
 *
 * Pure: every rule is covered by fixtures.
 *
 * @since 2.23.0
 */
final class Head_Tags {

    /**
     * Tag families, each with its own switch.
     *
     * - title:     `<title>` and the meta description
     * - canonical: `<link rel="canonical">`
     * - robots:    `<meta name="robots">` (and googlebot / bingbot)
     * - social:    Open Graph (`og:*`, `article:*`) and Twitter card tags
     * - schema:    JSON-LD blocks
     *
     * @var string[]
     */
    public const FAMILIES = ['title', 'canonical', 'robots', 'social', 'schema'];

    /**
     * Keys a search engine reads one of, and ignores (or picks at random)
     * when there are two.
     *
     * @var string[]
     */
    public const CRITICAL_KEYS = ['title', 'link:canonical', 'meta:robots'];

    /**
     * Robots meta names.
     *
     * @var string[]
     */
    private const ROBOTS_NAMES = ['robots', 'googlebot', 'bingbot'];

    /**
     * Open Graph and Twitter keys a page legitimately repeats — several
     * images, several article tags. Repeated by one party, they are not
     * duplicates; printed by both, they still are.
     *
     * @var string[]
     */
    private const MULTI_VALUED_PREFIXES = ['og:image', 'og:video', 'og:audio', 'og:locale:alternate', 'article:tag', 'article:author', 'article:section', 'twitter:image'];

    /**
     * Where the head ends, or null when there is no `</head>`.
     *
     * @param string $html Page markup.
     * @return int|null Offset just after `</head>`.
     */
    public static function head_end(string $html): ?int {
        if (!preg_match('#</head\s*>#i', $html, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return (int) $match[0][1] + strlen($match[0][0]);
    }

    /**
     * Every SEO tag in a head.
     *
     * @param string $head      Head markup (anything after `</head>` is ignored).
     * @param string $our_title The document title ThinkRank produced, or '' when unknown.
     * `label` is the text of the last comment opened before the tag (and a
     * class attribute, when the tag has one) — what a check reads to guess
     * who printed a foreign tag.
     *
     * @return array<int, array{family: string, key: string, ours: bool, start: int, end: int, label: string, types?: string[]}>
     */
    public static function scan(string $head, string $our_title = ''): array {
        $end  = self::head_end($head);
        $head = null === $end ? $head : substr($head, 0, $end);

        $tags  = [];
        $depth = 0;
        $label = '';
        $title = self::normalise($our_title);

        // One pass over the regions, in order, so a ThinkRank comment pair
        // opens and closes around exactly the tags between them. Comments,
        // scripts, styles, <noscript> and <template> are consumed whole, so
        // nothing inside them is read as a tag of the page's.
        $pattern = '#<!--(.*?)-->|<(script|style|noscript|template)\b([^>]*)>(.*?)</\2\s*>|<title\b[^>]*>(.*?)</title\s*>|<(meta|link)\b([^>]*)>#is';
        if (!preg_match_all($pattern, $head, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return [];
        }

        foreach ($matches as $m) {
            $start = (int) $m[0][1];
            $stop  = $start + strlen($m[0][0]);

            if (null !== $m[1][0]) {
                $comment = trim($m[1][0]);
                $depth   = self::track_depth($depth, $comment);
                // A closing comment ends the group it labelled.
                $label = '' === $comment || '/' === $comment[0] ? '' : substr($comment, 0, 120);
                continue;
            }

            if (null !== $m[2][0]) {
                if ('script' !== strtolower($m[2][0]) || 'application/ld+json' !== strtolower((string) self::attribute((string) $m[3][0], 'type'))) {
                    continue;
                }
                $ours   = $depth > 0 || false !== stripos((string) $m[3][0], 'data-thinkrank');
                $types  = self::schema_types((string) $m[4][0], $ours);
                $tags[] = [
                    'family' => 'schema',
                    'key'    => 'schema',
                    'ours'   => $ours,
                    'start'  => $start,
                    'end'    => $stop,
                    'label'  => trim((string) self::attribute((string) $m[3][0], 'class') . ' ' . $label),
                    'types'  => $types,
                ];
                continue;
            }

            if (null !== $m[5][0]) {
                $tags[] = [
                    'family' => 'title',
                    'key'    => 'title',
                    'ours'   => $depth > 0 || ('' !== $title && self::normalise((string) $m[5][0]) === $title),
                    'start'  => $start,
                    'end'    => $stop,
                    'label'  => $label,
                ];
                continue;
            }

            $tag = self::classify(strtolower((string) $m[6][0]), (string) $m[7][0]);
            if (null !== $tag) {
                $tags[] = $tag + [
                    'ours'  => $depth > 0,
                    'start' => $start,
                    'end'   => $stop,
                    'label' => trim((string) self::attribute((string) $m[7][0], 'class') . ' ' . $label),
                ];
            }
        }

        return $tags;
    }

    /**
     * The duplicates among a head's tags.
     *
     * A key is duplicated when ThinkRank and someone else both print it, or
     * when a single-valued key appears twice from anyone. JSON-LD is judged
     * by page-level `@type`: a type in two blocks.
     *
     * @param array<int, array<string, mixed>> $tags {@see self::scan()}.
     * @return array<int, array{key: string, family: string, count: int, ours: int, foreign: int, labels: string[], critical: bool}>
     */
    public static function duplicates(array $tags): array {
        $counts = [];
        foreach ($tags as $tag) {
            $keys = 'schema' === $tag['family']
                ? array_map(static fn(string $type): string => 'schema:' . $type, $tag['types'] ?? [])
                : [$tag['key']];

            foreach ($keys as $key) {
                $counts[$key] ??= ['key' => $key, 'family' => $tag['family'], 'count' => 0, 'ours' => 0, 'foreign' => 0, 'labels' => []];
                $counts[$key]['count']++;
                $counts[$key][$tag['ours'] ? 'ours' : 'foreign']++;
                if (!$tag['ours'] && '' !== ($tag['label'] ?? '') && !in_array($tag['label'], $counts[$key]['labels'], true)) {
                    $counts[$key]['labels'][] = $tag['label'];
                }
            }
        }

        $out = [];
        foreach ($counts as $key => $row) {
            $both   = $row['ours'] > 0 && $row['foreign'] > 0;
            $single = $row['count'] > 1 && !self::multi_valued($key);
            if ($both || $single) {
                $out[] = $row + ['critical' => in_array($key, self::CRITICAL_KEYS, true)];
            }
        }

        usort($out, static fn(array $a, array $b): int => [(int) !$a['critical'], $a['key']] <=> [(int) !$b['critical'], $b['key']]);

        return $out;
    }

    /**
     * Take out the foreign copies of the tags ThinkRank printed, in the
     * families asked for. A foreign tag ThinkRank has no counterpart for is
     * left alone: with nothing of ours to keep, removing it would only lose
     * the tag. A foreign JSON-LD block goes only when every page-level type
     * in it, at any depth, is one ThinkRank publishes as an entity of its
     * own, so a block that adds a Recipe or an Event stays, and so does one
     * whose Organization we only name as a publisher.
     *
     * @param string   $head      Head markup.
     * @param string[] $families  Families to act on.
     * @param string   $our_title The document title ThinkRank produced.
     * @return array{html: string, removed: array<string, int>}
     */
    public static function strip(string $head, array $families, string $our_title = ''): array {
        $families = array_values(array_intersect($families, self::FAMILIES));
        if ([] === $families) {
            return ['html' => $head, 'removed' => []];
        }

        $tags       = self::scan($head, $our_title);
        $ours_keys  = [];
        $ours_types = [];
        foreach ($tags as $tag) {
            if ($tag['ours']) {
                $ours_keys[$tag['key']] = true;
                foreach ($tag['types'] ?? [] as $type) {
                    $ours_types[$type] = true;
                }
            }
        }

        $remove  = [];
        $removed = [];
        foreach ($tags as $tag) {
            if ($tag['ours'] || !in_array($tag['family'], $families, true)) {
                continue;
            }

            if ('schema' === $tag['family']) {
                $types = $tag['types'] ?? [];
                $drop  = [] !== $types && [] === array_diff($types, array_keys($ours_types));
            } else {
                $drop = isset($ours_keys[$tag['key']]);
            }

            if ($drop) {
                $remove[] = $tag;
                $removed[$tag['family']] = ($removed[$tag['family']] ?? 0) + 1;
            }
        }

        // From the end, so earlier offsets stay valid.
        usort($remove, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($remove as $tag) {
            $end = $tag['end'];
            // The line break the emitter printed after the tag goes with it.
            if ("\r\n" === substr($head, $end, 2)) {
                $end += 2;
            } elseif ("\n" === substr($head, $end, 1)) {
                ++$end;
            }
            $head = substr($head, 0, $tag['start']) . substr($head, $end);
        }

        return ['html' => $head, 'removed' => $removed];
    }

    /**
     * The comment the guard leaves before `</head>` when it took tags out,
     * so a check of the page can say what happened. Counts only, never the
     * tags' content.
     *
     * Does not start with "ThinkRank", so {@see self::scan()} never reads it
     * as one of our comment pairs.
     *
     * @param array<string, int> $removed Family => tags removed.
     * @return string
     */
    public static function marker(array $removed): string {
        $parts = [];
        foreach ($removed as $family => $count) {
            $parts[] = $family . ': ' . (int) $count;
        }

        return '<!-- Duplicate head tags removed by ThinkRank: ' . (int) array_sum($removed) . ' (' . implode(', ', $parts) . ") -->\n";
    }

    /**
     * Read the guard's comment back.
     *
     * @param string $html Page markup.
     * @return array<string, int> Family => tags removed; empty when none.
     */
    public static function read_marker(string $html): array {
        if (!preg_match('#<!-- Duplicate head tags removed by ThinkRank: \d+ \(([^)]*)\) -->#', $html, $match)) {
            return [];
        }

        $removed = [];
        foreach (explode(',', $match[1]) as $part) {
            [$family, $count] = array_map('trim', explode(':', $part) + [1 => '0']);
            if (in_array($family, self::FAMILIES, true)) {
                $removed[$family] = (int) $count;
            }
        }

        return $removed;
    }

    /**
     * Family and key of a `<meta>` or `<link>`, or null when it carries no
     * SEO meaning.
     *
     * @param string $element    'meta' or 'link'.
     * @param string $attributes Attribute text.
     * @return array{family: string, key: string}|null
     */
    private static function classify(string $element, string $attributes): ?array {
        if ('link' === $element) {
            $rel = preg_split('/\s+/', strtolower(trim((string) self::attribute($attributes, 'rel')))) ?: [];

            return in_array('canonical', $rel, true) ? ['family' => 'canonical', 'key' => 'link:canonical'] : null;
        }

        $name     = strtolower(trim((string) self::attribute($attributes, 'name')));
        $property = strtolower(trim((string) self::attribute($attributes, 'property')));

        if ('description' === $name) {
            return ['family' => 'title', 'key' => 'meta:description'];
        }
        if (in_array($name, self::ROBOTS_NAMES, true)) {
            return ['family' => 'robots', 'key' => 'meta:' . $name];
        }

        // Open Graph is specified as `property` and Twitter as `name`, but
        // both are printed either way in the wild.
        foreach ([$property, $name] as $key) {
            if (preg_match('/^(og|article|twitter):[a-z0-9_:]+$/', $key)) {
                return ['family' => 'social', 'key' => $key];
            }
        }

        return null;
    }

    /**
     * An attribute's value from a tag's attribute text.
     *
     * @param string $attributes Attribute text.
     * @param string $name       Attribute name.
     * @return string|null Null when absent.
     */
    private static function attribute(string $attributes, string $name): ?string {
        if (!preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $match)) {
            return null;
        }

        // Double-quoted, single-quoted or bare: whichever group matched.
        $value = '';
        foreach ([1, 2, 3] as $group) {
            if (isset($match[$group]) && '' !== $match[$group]) {
                $value = $match[$group];
                break;
            }
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Page-level `@type`s in a JSON-LD block, from the list
     * Foreign_Schema_Detector keeps.
     *
     * The two sides are read differently on purpose. Our block counts only
     * the entities it publishes, so a `publisher` or `mainEntityOfPage` stub
     * does not pass for an Organization or a WebPage. Another party's block
     * counts every type in it, nested ones too, because removing the block
     * removes all of them.
     *
     * @param string $json Block content.
     * @param bool   $ours Whether ThinkRank printed the block.
     * @return string[]
     */
    private static function schema_types(string $json, bool $ours): array {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $ours
            ? Foreign_Schema_Detector::entity_types($decoded)
            : Foreign_Schema_Detector::page_level_types($decoded);
    }

    /**
     * Follow ThinkRank's comment pairs.
     *
     * @param int    $depth   Pairs open before this comment.
     * @param string $comment Comment text.
     * @return int
     */
    private static function track_depth(int $depth, string $comment): int {
        if (preg_match('#^ThinkRank\b#', $comment)) {
            return $depth + 1;
        }

        // `/ThinkRank SEO` alone closes the outer wrapper, which never opened
        // a pair (see the class comment).
        if (preg_match('#^/ThinkRank\b#', $comment) && '/ThinkRank SEO' !== $comment) {
            return max(0, $depth - 1);
        }

        return $depth;
    }

    /**
     * Whether a key is one a page may repeat.
     *
     * @param string $key Key.
     * @return bool
     */
    private static function multi_valued(string $key): bool {
        foreach (self::MULTI_VALUED_PREFIXES as $prefix) {
            if (0 === strpos($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Title text for comparison: entities decoded, whitespace collapsed.
     *
     * @param string $text Text.
     * @return string
     */
    private static function normalise(string $text): string {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
