<?php

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restores live date variables in values migrated before ThinkRank had them.
 *
 * Importers used to render the source plugin's current-date variables at import
 * time, so "Best shoes %currentyear%" arrived as "Best shoes 2026" and stays
 * that way (#1472). A second migration cannot help, because an import never
 * replaces a ThinkRank value that exists (#1497).
 *
 * The source plugin's meta is still the record of what the title was meant to
 * be, so this re-reads it through the current exporter and compares. A stored
 * value is replaced only when it equals the exporter's value with its date
 * variables rendered as an importer could have rendered them: a real month
 * name, a day of the month, and a year no earlier than the value could have
 * been frozen. Anything else is left alone. A narrower match can miss a frozen
 * value, which an admin can fix by hand; a wider one overwrites a title with no
 * way back (#1507). A hand-typed value inside those bounds cannot be told from
 * a frozen one, so the preview lists every value it would change.
 *
 * @since 2.21.0
 */
class Date_Variable_Repair {

    /**
     * Canonical snapshot field => ThinkRank meta key, for the text fields an
     * importer could have frozen a date into.
     *
     * @var array<string,string>
     */
    private const FIELDS = [
        'seo_title'           => '_thinkrank_seo_title',
        'meta_description'    => '_thinkrank_meta_description',
        'og_title'            => '_thinkrank_og_title',
        'og_description'      => '_thinkrank_og_description',
        'twitter_title'       => '_thinkrank_twitter_title',
        'twitter_description' => '_thinkrank_twitter_description',
    ];

    /**
     * Where the migrator records which plugin an object's values came from.
     *
     * @var string
     */
    private const IMPORTED_FROM = '_thinkrank_imported_from';

    /**
     * The year the first importer shipped, so the earliest any value could have
     * been frozen. Terms have no date of their own, so it is their only bound.
     *
     * @since 2.21.0
     *
     * @var int
     */
    private const FIRST_IMPORT_YEAR = 2026;

    /**
     * The source plugins this site has imported values from.
     *
     * @return string[] Plugin slugs.
     */
    public static function imported_sources(): array {
        global $wpdb;

        $key = self::IMPORTED_FROM;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A short admin-screen read over an indexed key.
        $posts = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $key));
        $terms = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s", $key));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

        return array_values(array_unique(array_map('strval', array_merge($posts, $terms))));
    }

    /**
     * The pattern a frozen copy of a value would match.
     *
     * The separator, site name and object title are accepted in either form:
     * older imports rendered those too, newer ones keep them as tags. The
     * object title stands for `%title%` and, on a term, for the `%term%` or
     * `%term_title%` the exporters now give term values (#1557). A
     * `%taxonomy(x)%` is accepted as itself or as the `%customterm(x)%` an older
     * Rank Math import left in the value (#1468). A `%%ct_x%%`, `%%_ct_x%%` or
     * `#tax_name-x` an older import dropped, or a `{{ post.tax.x }}` it rendered,
     * cannot be told from other text, so such a value is left alone.
     *
     * @param string $live       The value as the exporter gives it today.
     * @param string $site_name  Site name.
     * @param string $post_title Title of the object the value belongs to: the post title or the term name.
     * @param int    $earliest_year The first year the value could have been frozen.
     * @return string|null Regular expression, or null when the value has no date variable.
     */
    public static function frozen_pattern(string $live, string $site_name = '', string $post_title = '', int $earliest_year = self::FIRST_IMPORT_YEAR): ?string {
        if (!preg_match('/%current(?:year|month|day|date)%/', $live)) {
            return null;
        }

        $alternatives = self::frozen_date_patterns($earliest_year) + [
            '%sep%'        => '(?:%sep%|-)',
            '%sitename%'   => self::either('%sitename%', $site_name),
            '%site_title%' => self::either('%site_title%', $site_name),
            '%title%'      => self::either('%title%', $post_title),
            '%term%'       => self::either('%term%', $post_title),
            '%term_title%' => self::either('%term_title%', $post_title),
        ];

        $tokens  = implode('|', array_map(static fn(string $token): string => preg_quote($token, '/'), array_keys($alternatives)));
        $tokens .= '|%taxonomy\([^%()]+\)%';
        $parts  = preg_split('/(' . $tokens . ')/', $live, -1, PREG_SPLIT_DELIM_CAPTURE);

        $pattern = '';
        foreach ((array) $parts as $index => $part) {
            if (0 === $index % 2) {
                $pattern .= preg_quote((string) $part, '/');
                continue;
            }

            $pattern .= $alternatives[$part] ?? self::either($part, str_replace('%taxonomy(', '%customterm(', $part));
        }

        return '/^' . $pattern . '$/u';
    }

    /**
     * Find, and optionally restore, the frozen values in a page of records.
     *
     * @param array[] $records Records as an exporter builds them.
     * @param string  $source  Source plugin slug.
     * @param bool    $apply   Whether to write the live values.
     * @return array[] One entry per frozen value: object_type, object_id, title, field, from, to.
     */
    public static function process_records(array $records, string $source, bool $apply): array {
        $site_name = (string) get_bloginfo('name');
        $found     = [];

        foreach ($records as $record) {
            $type = (string) ($record['object_type'] ?? '');
            $id   = (int) ($record['object_id'] ?? 0);
            $data = (array) ($record['data'] ?? []);

            if ($id <= 0 || !in_array($type, ['post', 'term'], true)
                || (string) self::get_meta($type, $id, self::IMPORTED_FROM) !== $source) {
                continue;
            }

            $title    = self::object_title($type, $id);
            $earliest = self::earliest_frozen_year($type, $id);

            foreach (self::FIELDS as $field => $meta_key) {
                $live    = (string) ($data[$field] ?? '');
                $pattern = self::frozen_pattern($live, $site_name, $title, $earliest);
                $stored  = null === $pattern ? '' : (string) self::get_meta($type, $id, $meta_key);

                if ('' === $stored || $stored === $live || 1 !== preg_match((string) $pattern, $stored)) {
                    continue;
                }

                $found[] = [
                    'object_type' => $type,
                    'object_id'   => $id,
                    'title'       => $title,
                    'field'       => $field,
                    'from'        => $stored,
                    'to'          => $live,
                ];

                if ($apply) {
                    self::update_meta($type, $id, $meta_key, $live);
                }
            }
        }

        return $found;
    }

    /**
     * What each date variable looked like once frozen. Importers used
     * gmdate('Y'), 'F', 'j' and 'Y-m-d', or wp_date() in the site's own formats.
     *
     * @since 2.21.0
     *
     * @param int $earliest_year The first year the value could have been frozen.
     * @return array<string,string> Date variable => pattern.
     */
    private static function frozen_date_patterns(int $earliest_year): array {
        // Local and UTC disagree on the year for a few hours each new year.
        $latest_year = max((int) gmdate('Y'), (int) wp_date('Y'));
        $first_year  = max($earliest_year, self::FIRST_IMPORT_YEAR);
        $years       = $first_year <= $latest_year ? array_map('strval', range($first_year, $latest_year)) : [];

        // A value that cannot have been frozen yet matches no year at all.
        $year       = [] !== $years ? '(?:' . implode('|', $years) . ')' : '(?!)';
        $short_year = [] !== $years ? '(?:' . implode('|', array_merge($years, array_map(static fn(string $full): string => substr($full, 2), $years))) . ')' : '(?!)';

        $month = self::frozen_month_pattern();
        $day   = '(?:0?[1-9]|[12]\d|3[01])';

        return [
            '%currentyear%'  => $year,
            '%currentmonth%' => $month,
            '%currentday%'   => '(?:[1-9]|[12]\d|3[01])',
            '%currentdate%'  => '(?:' . $year . '-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])'
                . '|' . $month . ' ' . $day . ',? ' . $year
                . '|' . $day . '\.? ' . $month . ',? ' . $year
                . '|' . $day . '[.\/-]' . $day . '[.\/-]' . $short_year . ')',
        ];
    }

    /**
     * The twelve month names, in the site's locale and in English, because
     * importers rendered them with both wp_date() and gmdate().
     *
     * @since 2.21.0
     *
     * @return string Pattern.
     */
    private static function frozen_month_pattern(): string {
        $names = [];

        foreach (range(1, 12) as $month) {
            // Mid-month, so no timezone moves it into the next one.
            $moment  = gmmktime(12, 0, 0, $month, 15, 2000);
            $names[] = (string) wp_date('F', $moment);
            $names[] = gmdate('F', $moment);
        }

        return '(?:' . implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), array_unique($names))) . ')';
    }

    /**
     * The first year this object's values could have been frozen.
     *
     * An import cannot freeze a value before the post exists, so its creation
     * date is a real bound. Its modified date is not: a post edited after the
     * migration still holds the older frozen value. A term has no date.
     *
     * @since 2.21.0
     *
     * @param string $type Object type: post or term.
     * @param int    $id   Object ID.
     * @return int Year.
     */
    private static function earliest_frozen_year(string $type, int $id): int {
        if ('post' !== $type) {
            return self::FIRST_IMPORT_YEAR;
        }

        // A draft has no GMT date until it is published.
        foreach (['post_date_gmt', 'post_date'] as $field) {
            $year = (int) substr((string) get_post_field($field, $id, 'raw'), 0, 4);
            if ($year > 0) {
                return max($year, self::FIRST_IMPORT_YEAR);
            }
        }

        return self::FIRST_IMPORT_YEAR;
    }

    /**
     * A token's pattern, also accepting its rendered text when there is any.
     *
     * @param string $token    Token.
     * @param string $rendered Rendered text.
     * @return string Pattern.
     */
    private static function either(string $token, string $rendered): string {
        return '(?:' . preg_quote($token, '/') . ('' !== $rendered ? '|' . preg_quote($rendered, '/') : '') . ')';
    }

    /**
     * Read a post or term meta value.
     *
     * @param string $type Object type: post or term.
     * @param int    $id   Object ID.
     * @param string $key  Meta key.
     * @return mixed
     */
    private static function get_meta(string $type, int $id, string $key) {
        return 'term' === $type ? get_term_meta($id, $key, true) : get_post_meta($id, $key, true);
    }

    /**
     * Write a post or term meta value. The meta writers unslash their value.
     *
     * @param string $type  Object type: post or term.
     * @param int    $id    Object ID.
     * @param string $key   Meta key.
     * @param string $value Value.
     * @return void
     */
    private static function update_meta(string $type, int $id, string $key, string $value): void {
        if ('term' === $type) {
            update_term_meta($id, $key, wp_slash($value));
            return;
        }

        update_post_meta($id, $key, wp_slash($value));
    }

    /**
     * The name to show for an object in the preview.
     *
     * @param string $type Object type: post or term.
     * @param int    $id   Object ID.
     * @return string Title.
     */
    private static function object_title(string $type, int $id): string {
        if ('term' === $type) {
            $term = get_term($id);
            return is_object($term) && isset($term->name) ? (string) $term->name : '';
        }

        return (string) get_the_title($id);
    }
}
