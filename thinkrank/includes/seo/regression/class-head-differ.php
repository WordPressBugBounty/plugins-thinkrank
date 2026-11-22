<?php
/**
 * Regression Guard: compare two snapshots of a page and grade what changed.
 *
 * @package ThinkRank\SEO\Regression
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Regression;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Head Differ
 *
 * Turns two {@see Head_Snapshotter} snapshots into a list of changes, each with
 * a severity and one plain sentence:
 *
 * - **critical** — the page can drop out of search or lose a rich result:
 *   noindex appeared, it stopped answering 200, it now redirects, its canonical
 *   points somewhere else, a JSON-LD type vanished, its title went empty.
 * - **warning** — worth a look: title or description changed, a duplicate
 *   entity or canonical appeared, og:image or the hreflang set changed.
 * - **info** — everything else that differs.
 *
 * Whether a change was *expected* is not the differ's call; the caller knows
 * whether the post or a setting was edited in between (see
 * {@see Regression_Guard::expected_reasons()}) and passes it in. The
 * severity stays the same either way — an expected noindex is still a noindex —
 * so the screen can say "you did this" without hiding what it costs.
 *
 * Pure: no WordPress state is read, so every rule is covered by fixtures.
 *
 * @since 2.23.0
 */
class Head_Differ {

    public const CRITICAL = 'critical';
    public const WARNING  = 'warning';
    public const INFO     = 'info';

    /**
     * Compare two snapshots.
     *
     * @param array<string, mixed> $before  Previous snapshot.
     * @param array<string, mixed> $after   Current snapshot.
     * @param string[]             $reasons Why a change would be expected (empty: nobody edited anything).
     * @return array{
     *     changed: bool,
     *     expected: bool,
     *     reasons: string[],
     *     counts: array{critical: int, warning: int, info: int},
     *     changes: array<int, array{code: string, field: string, severity: string, message: string, before: string, after: string}>
     * }
     */
    public static function diff(array $before, array $after, array $reasons = []): array {
        $changes = self::status_changes($before, $after);

        // Only two 200 responses have heads worth comparing. Diffing an error
        // template against the page would bury the one finding that matters —
        // the status — under "title removed", "schema removed", and so on.
        if (200 === (int) ($before['status'] ?? 0) && 200 === (int) ($after['status'] ?? 0)) {
            $changes = array_merge(
                $changes,
                self::title_changes($before, $after),
                self::description_changes($before, $after),
                self::robots_changes($before, $after),
                self::canonical_changes($before, $after),
                self::schema_changes($before, $after),
                self::social_changes($before, $after),
                self::hreflang_changes($before, $after),
                self::h1_changes($before, $after),
                self::duplicate_tag_changes($before, $after)
            );
        }

        $order = [self::CRITICAL => 0, self::WARNING => 1, self::INFO => 2];
        usort($changes, static function (array $a, array $b) use ($order): int {
            return $order[$a['severity']] <=> $order[$b['severity']];
        });

        $counts = [self::CRITICAL => 0, self::WARNING => 0, self::INFO => 0];
        foreach ($changes as $change) {
            $counts[$change['severity']]++;
        }

        $changed = [] !== $changes;

        return [
            'changed'  => $changed,
            'expected' => $changed && [] !== $reasons,
            'reasons'  => array_values($reasons),
            'counts'   => $counts,
            'changes'  => $changes,
        ];
    }

    /**
     * Head tags that are now printed twice, or printed once again (#733) —
     * the usual sign that an update made a theme or plugin start printing
     * its own copy.
     *
     * A snapshot taken before the field existed is not compared: every
     * duplicate on the page would read as new.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function duplicate_tag_changes(array $before, array $after): array {
        if (!isset($before['duplicate_tags']) || !is_array($before['duplicate_tags'])) {
            return [];
        }

        $was     = $before['duplicate_tags'];
        $now     = is_array($after['duplicate_tags'] ?? null) ? $after['duplicate_tags'] : [];
        $changes = [];

        foreach ($now as $key => $count) {
            if (!isset($was[$key])) {
                $changes[] = self::change('duplicate_tag', 'duplicate_tags', self::WARNING, sprintf(
                    /* translators: 1: number of tags, 2: tag name such as og:title */
                    __('The page now prints %1$d %2$s tags. Another plugin or the theme may have started printing its own; Duplicate Tag Guard can remove the extra copy.', 'thinkrank'),
                    (int) $count,
                    $key
                ), '1', (string) $count);
            }
        }

        foreach ($was as $key => $count) {
            if (!isset($now[$key])) {
                $changes[] = self::change('duplicate_tag_resolved', 'duplicate_tags', self::INFO, sprintf(
                    /* translators: %s: tag name such as og:title */
                    __('%s is printed once again.', 'thinkrank'),
                    $key
                ), (string) $count, '1');
            }
        }

        return $changes;
    }

    /**
     * Whether two URLs name the same page, ignoring scheme, a trailing slash,
     * host case and a fragment.
     *
     * Scheme is ignored because a site behind a proxy can print `http://`
     * canonicals for its own `https://` pages; calling that "points elsewhere"
     * would be a false critical on every check.
     *
     * @param string $a URL.
     * @param string $b URL.
     * @return bool
     */
    public static function same_url(string $a, string $b): bool {
        return self::url_key($a) === self::url_key($b);
    }

    /**
     * Status and redirect changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function status_changes(array $before, array $after): array {
        $was = (int) ($before['status'] ?? 0);
        $now = (int) ($after['status'] ?? 0);

        if ($was === $now) {
            $from = (string) ($before['redirect_to'] ?? '');
            $to   = (string) ($after['redirect_to'] ?? '');
            if ($now >= 300 && $now < 400 && $from !== $to) {
                return [self::change('redirect_changed', 'status', self::WARNING, __('The redirect now goes somewhere else.', 'thinkrank'), $from, $to)];
            }
            return [];
        }

        $from = (string) $was;
        $to   = $now >= 300 && $now < 400
            ? sprintf('%d → %s', $now, (string) ($after['redirect_to'] ?? ''))
            : (string) $now;

        if (200 === $was && $now >= 300 && $now < 400) {
            return [self::change('now_redirects', 'status', self::CRITICAL, __('The page now redirects. Search engines will index the destination instead.', 'thinkrank'), $from, $to)];
        }

        if (200 === $was) {
            return [self::change('status_changed', 'status', self::CRITICAL, sprintf(
                /* translators: %d: HTTP status code */
                __('The page stopped loading: it now answers HTTP %d instead of 200.', 'thinkrank'),
                $now
            ), $from, $to)];
        }

        if (200 === $now) {
            return [self::change('status_recovered', 'status', self::INFO, __('The page loads normally again.', 'thinkrank'), $from, $to)];
        }

        return [self::change('status_changed', 'status', self::INFO, __('The page answers with a different HTTP status.', 'thinkrank'), $from, $to)];
    }

    /**
     * Title changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function title_changes(array $before, array $after): array {
        $from = (string) ($before['title'] ?? '');
        $to   = (string) ($after['title'] ?? '');

        if ($from === $to) {
            return [];
        }
        if ('' === $to) {
            return [self::change('title_empty', 'title', self::CRITICAL, __('The page no longer has a title.', 'thinkrank'), $from, $to)];
        }

        return [self::change('title_changed', 'title', self::WARNING, __('The title changed.', 'thinkrank'), $from, $to)];
    }

    /**
     * Meta description changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function description_changes(array $before, array $after): array {
        $from = (string) ($before['description'] ?? '');
        $to   = (string) ($after['description'] ?? '');

        if ($from === $to) {
            return [];
        }
        if ('' === $to) {
            return [self::change('description_removed', 'description', self::WARNING, __('The meta description is gone. Google will write its own snippet.', 'thinkrank'), $from, $to)];
        }

        return [self::change('description_changed', 'description', self::WARNING, __('The meta description changed.', 'thinkrank'), $from, $to)];
    }

    /**
     * Robots meta and X-Robots-Tag changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function robots_changes(array $before, array $after): array {
        $was_noindex = Head_Snapshotter::is_noindex($before);
        $is_noindex  = Head_Snapshotter::is_noindex($after);
        $from        = self::robots_label($before);
        $to          = self::robots_label($after);

        if (!$was_noindex && $is_noindex) {
            return [self::change('noindex_added', 'robots', self::CRITICAL, __('The page now tells search engines not to index it (noindex).', 'thinkrank'), $from, $to)];
        }
        if ($was_noindex && !$is_noindex) {
            return [self::change('noindex_removed', 'robots', self::INFO, __('The page no longer carries noindex.', 'thinkrank'), $from, $to)];
        }
        if ($from !== $to) {
            return [self::change('robots_changed', 'robots', self::INFO, __('The robots directives changed.', 'thinkrank'), $from, $to)];
        }

        return [];
    }

    /**
     * Canonical changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function canonical_changes(array $before, array $after): array {
        $page    = (string) ($after['url'] ?? '');
        $from    = (string) ($before['canonical'] ?? '');
        $to      = (string) ($after['canonical'] ?? '');
        $changes = [];

        if ($from !== $to) {
            $pointed_home = '' === $from || self::same_url($from, $page);

            if ('' !== $to && $pointed_home && !self::same_url($to, $page)) {
                $changes[] = self::change('canonical_elsewhere', 'canonical', self::CRITICAL, __('The canonical now points to a different URL. Search engines may drop this page in favour of that one.', 'thinkrank'), $from, $to);
            } elseif ('' !== $to && !$pointed_home && self::same_url($to, $page)) {
                $changes[] = self::change('canonical_restored', 'canonical', self::INFO, __('The canonical points at this page again.', 'thinkrank'), $from, $to);
            } elseif ('' === $to) {
                $changes[] = self::change('canonical_removed', 'canonical', self::WARNING, __('The canonical tag is gone.', 'thinkrank'), $from, $to);
            } elseif (!self::same_url($from, $to)) {
                $changes[] = self::change('canonical_changed', 'canonical', self::WARNING, __('The canonical URL changed.', 'thinkrank'), $from, $to);
            } else {
                $changes[] = self::change('canonical_changed', 'canonical', self::INFO, __('The canonical URL changed form (scheme or trailing slash) but names the same page.', 'thinkrank'), $from, $to);
            }
        }

        $was_count = (int) ($before['canonical_count'] ?? 0);
        $now_count = (int) ($after['canonical_count'] ?? 0);
        if ($now_count > 1 && $was_count <= 1) {
            $changes[] = self::change('canonical_duplicate', 'canonical', self::WARNING, sprintf(
                /* translators: %d: number of canonical tags */
                __('The page now prints %d canonical tags. Search engines may ignore all of them.', 'thinkrank'),
                $now_count
            ), (string) $was_count, (string) $now_count);
        } elseif ($was_count > 1 && $now_count <= 1) {
            // The recovery, which has to be said out loud. Someone who saw the
            // warning above, found the plugin printing the second tag and fixed
            // it re-checks and is told "nothing changed" — which reads as the
            // fix not having worked. Info, like the noindex recovery.
            $changes[] = self::change('canonical_duplicate_resolved', 'canonical', self::INFO, __('The page prints a single canonical tag again.', 'thinkrank'), (string) $was_count, (string) $now_count);
        }

        return $changes;
    }

    /**
     * JSON-LD changes: types that disappeared, duplicate entities, new types,
     * key properties that changed, and blocks that stopped parsing.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function schema_changes(array $before, array $after): array {
        $was     = self::by_type((array) ($before['schema'] ?? []));
        $now     = self::by_type((array) ($after['schema'] ?? []));
        $changes = [];

        foreach ($was as $type => $entities) {
            if (!isset($now[$type])) {
                $changes[] = self::change('schema_type_removed', 'schema', self::CRITICAL, sprintf(
                    /* translators: %s: schema.org type, e.g. Product */
                    __('%s structured data is gone from the page.', 'thinkrank'),
                    $type
                ), $type, '');
                continue;
            }

            if (count($now[$type]) > count($entities)) {
                $changes[] = self::change('schema_duplicate', 'schema', self::WARNING, sprintf(
                    /* translators: 1: schema.org type, 2: number of entities */
                    __('The page now has %2$d %1$s entities. Two sources are likely printing the same structured data.', 'thinkrank'),
                    $type,
                    count($now[$type])
                ), (string) count($entities), (string) count($now[$type]));
            } elseif (count($entities) > 1 && count($now[$type]) < count($entities)) {
                // Same reason as the canonical recovery above: the duplicate
                // warning is actionable, so the moment it stops being true has
                // to be reported or the fix looks like it did nothing.
                $changes[] = self::change('schema_duplicate_resolved', 'schema', self::INFO, sprintf(
                    /* translators: 1: schema.org type, 2: number of entities now */
                    __('The page is down to %2$d %1$s entity where it had more than one.', 'thinkrank'),
                    $type,
                    count($now[$type])
                ), (string) count($entities), (string) count($now[$type]));
            }

            foreach (self::pair($entities, $now[$type]) as [$entity, $match]) {
                foreach ($entity as $prop => $value) {
                    if (in_array($prop, ['type', 'url'], true)) {
                        continue;
                    }
                    $new = (string) ($match[$prop] ?? '');
                    if ((string) $value === $new) {
                        continue;
                    }
                    if ('' !== (string) $value && '' === $new && in_array($prop, ['price', 'availability', 'rating'], true)) {
                        $changes[] = self::change('schema_property_removed', 'schema', self::WARNING, sprintf(
                            /* translators: 1: property label, 2: schema.org type */
                            __('%1$s is missing from the %2$s structured data. The rich result that shows it may stop appearing.', 'thinkrank'),
                            self::prop_label($prop),
                            $type
                        ), (string) $value, $new);
                        continue;
                    }
                    $changes[] = self::change('schema_property_changed', 'schema', self::INFO, sprintf(
                        /* translators: 1: property label, 2: schema.org type */
                        __('%1$s changed in the %2$s structured data.', 'thinkrank'),
                        self::prop_label($prop),
                        $type
                    ), (string) $value, $new);
                }
            }
        }

        foreach (array_diff_key($now, $was) as $type => $entities) {
            $changes[] = self::change('schema_type_added', 'schema', self::INFO, sprintf(
                /* translators: %s: schema.org type */
                __('%s structured data was added.', 'thinkrank'),
                $type
            ), '', (string) $type);
        }

        $was_invalid = (int) ($before['schema_invalid'] ?? 0);
        $now_invalid = (int) ($after['schema_invalid'] ?? 0);
        if ($now_invalid > $was_invalid) {
            $changes[] = self::change('schema_invalid', 'schema', self::WARNING, __('A structured data block no longer parses as JSON. Search engines ignore it.', 'thinkrank'), (string) $was_invalid, (string) $now_invalid);
        }

        return $changes;
    }

    /**
     * Open Graph and Twitter card changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function social_changes(array $before, array $after): array {
        $changes = [];

        foreach (['og' => 'og:', 'twitter' => 'twitter:'] as $group => $prefix) {
            $was = (array) ($before[$group] ?? []);
            $now = (array) ($after[$group] ?? []);

            foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $key) {
                $from = (string) ($was[$key] ?? '');
                $to   = (string) ($now[$key] ?? '');
                if ($from === $to) {
                    continue;
                }

                if ('og' === $group && 'image' === $key && '' === $to) {
                    $changes[] = self::change('og_image_removed', 'social', self::WARNING, __('og:image is gone. Shares of this page will show no picture.', 'thinkrank'), $from, $to);
                    continue;
                }

                $changes[] = self::change('social_changed', 'social', self::INFO, sprintf(
                    /* translators: %s: tag name, e.g. og:title */
                    __('%s changed.', 'thinkrank'),
                    $prefix . $key
                ), $from, $to);
            }
        }

        return $changes;
    }

    /**
     * Hreflang set changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function hreflang_changes(array $before, array $after): array {
        $was = (array) ($before['hreflang'] ?? []);
        $now = (array) ($after['hreflang'] ?? []);
        ksort($was);
        ksort($now);

        if ($was === $now) {
            return [];
        }

        return [self::change('hreflang_changed', 'hreflang', self::WARNING, __('The language alternates (hreflang) changed.', 'thinkrank'), self::pairs($was), self::pairs($now))];
    }

    /**
     * H1 count changes.
     *
     * @param array<string, mixed> $before Previous snapshot.
     * @param array<string, mixed> $after  Current snapshot.
     * @return array<int, array<string, string>>
     */
    private static function h1_changes(array $before, array $after): array {
        $was = (int) ($before['h1_count'] ?? 0);
        $now = (int) ($after['h1_count'] ?? 0);

        if ($was === $now) {
            return [];
        }
        if (0 === $now) {
            return [self::change('h1_missing', 'h1', self::WARNING, __('The page no longer has an H1 heading.', 'thinkrank'), (string) $was, '0')];
        }

        return [self::change('h1_changed', 'h1', self::INFO, __('The number of H1 headings changed.', 'thinkrank'), (string) $was, (string) $now)];
    }

    /**
     * Pair each earlier entity of one type with its counterpart now.
     *
     * The same name and URL is the same entity; whatever is left pairs in
     * order. Pairing by position alone would match a newly added duplicate
     * against the original whenever it sorts first, and report the original's
     * name as changed when it is untouched.
     *
     * @param array<int, array<string, string>> $was Entities before.
     * @param array<int, array<string, string>> $now Entities now.
     * @return array<int, array{0: array<string, string>, 1: array<string, string>}>
     */
    private static function pair(array $was, array $now): array {
        $pairs    = [];
        $left_was = [];

        foreach ($was as $entity) {
            foreach ($now as $j => $candidate) {
                if ($candidate['name'] === $entity['name'] && $candidate['url'] === $entity['url']) {
                    $pairs[] = [$entity, $candidate];
                    unset($now[$j]);
                    continue 2;
                }
            }
            $left_was[] = $entity;
        }

        foreach (array_values($now) as $i => $candidate) {
            if (!isset($left_was[$i])) {
                break;
            }
            $pairs[] = [$left_was[$i], $candidate];
        }

        return $pairs;
    }

    /**
     * Group entities by type.
     *
     * @param array<int, mixed> $schema Snapshot schema list.
     * @return array<string, array<int, array<string, string>>>
     */
    private static function by_type(array $schema): array {
        $out = [];
        foreach ($schema as $entity) {
            if (is_array($entity) && '' !== (string) ($entity['type'] ?? '')) {
                $out[(string) $entity['type']][] = $entity;
            }
        }

        return $out;
    }

    /**
     * Robots directives from meta and header as one readable line.
     *
     * @param array<string, mixed> $snapshot Snapshot.
     * @return string
     */
    private static function robots_label(array $snapshot): string {
        $meta   = implode(', ', (array) ($snapshot['robots'] ?? []));
        $header = implode(', ', (array) ($snapshot['x_robots_tag'] ?? []));

        if ('' === $header) {
            return $meta;
        }

        return trim($meta . ' | X-Robots-Tag: ' . $header, ' |');
    }

    /**
     * A key => value map as one readable line.
     *
     * @param array<string, mixed> $map Map.
     * @return string
     */
    private static function pairs(array $map): string {
        $out = [];
        foreach ($map as $key => $value) {
            $out[] = $key . ' → ' . (string) $value;
        }

        return implode(', ', $out);
    }

    /**
     * A readable label for a schema property.
     *
     * @param string $prop Property key.
     * @return string
     */
    private static function prop_label(string $prop): string {
        $labels = [
            'name'         => __('The name', 'thinkrank'),
            'price'        => __('The price', 'thinkrank'),
            'availability' => __('Availability', 'thinkrank'),
            'rating'       => __('The rating', 'thinkrank'),
            'review_count' => __('The review count', 'thinkrank'),
            'items'        => __('The number of items', 'thinkrank'),
        ];

        return $labels[$prop] ?? $prop;
    }

    /**
     * Comparison key for a URL.
     *
     * @param string $url URL.
     * @return string
     */
    private static function url_key(string $url): string {
        $url = trim($url);
        if ('' === $url) {
            return '';
        }

        $url = (string) preg_replace('/#.*$/', '', $url);
        $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url);

        $slash = strpos($url, '/');
        $host  = false === $slash ? $url : substr($url, 0, $slash);
        $path  = false === $slash ? '' : substr($url, $slash);

        return strtolower($host) . rtrim($path, '/');
    }

    /**
     * One change record.
     *
     * @param string $code     Stable identifier.
     * @param string $field    Snapshot field it concerns.
     * @param string $severity One of the severity constants.
     * @param string $message  Plain-language sentence.
     * @param string $before   Previous value, for display.
     * @param string $after    Current value, for display.
     * @return array{code: string, field: string, severity: string, message: string, before: string, after: string}
     */
    private static function change(string $code, string $field, string $severity, string $message, string $before, string $after): array {
        return [
            'code'     => $code,
            'field'    => $field,
            'severity' => $severity,
            'message'  => $message,
            'before'   => $before,
            'after'    => $after,
        ];
    }
}
