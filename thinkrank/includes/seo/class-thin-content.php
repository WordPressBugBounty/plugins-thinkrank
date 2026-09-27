<?php
/**
 * Sitewide thin content report.
 *
 * @package ThinkRank\SEO
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Plan_Config;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin Content
 *
 * Answers "which of my pages are thin?" across the whole site, grouped by post
 * type, with a threshold each (#565). ThinkRank already scores word count for
 * the post being edited; nothing told anyone which of five hundred pages to
 * open.
 *
 * Four decisions shape this class.
 *
 * **It counts what the visitor reads.** Every count goes through
 * {@see Word_Count_Index}, which resolves builder content, so an Elementor or
 * Bricks page reports its real length rather than the empty `post_content`
 * column. This is the whole reason the work has to be batched, and it is also
 * what makes the answer different from the one the Site SEO Analyzer's depth
 * check gives today.
 *
 * **It is not part of the Site SEO Analyzer.** That check reads the most recent
 * 100 posts and reads them raw, which is right for a crawl-free sampled audit
 * and wrong for this question: the thin pages on a large site are rarely the
 * hundred most recent, and a builder page is not thin just because its
 * `post_content` is empty.
 *
 * **A threshold per post type.** A 200-word product page is not a 200-word blog
 * post. There is one default and an override per post type, because inventing a
 * number for every post type on the site would be guessing with the user's
 * content — but showing a store "products: 15 under 300" next to the control
 * that changes 300 is not.
 *
 * **Published only, and on demand.** A draft nobody can read is not thin, it is
 * unfinished. The scan is driven by the screen asking, a bounded batch at a
 * time; scheduled alerts on newly-thin content are the Pro half, which is why
 * the capability is read here rather than assumed.
 *
 * @since 2.10.0
 */
class Thin_Content {

    /**
     * Option holding the thresholds.
     */
    public const SETTINGS_OPTION = 'thinkrank_thin_content_settings';

    /**
     * Option holding the cached report.
     */
    private const CACHE_OPTION = 'thinkrank_thin_content_report';

    /**
     * Statuses the report covers.
     *
     * @var string[]
     */
    private const STATUSES = ['publish'];

    /**
     * Default threshold, in the locale's counting unit.
     *
     * 300 is the number the Site SEO Analyzer's depth check already uses, so
     * the two screens cannot disagree about what "thin" means.
     */
    public const DEFAULT_THRESHOLD = 300;

    /**
     * Bounds on a stored threshold. Zero would mark nothing thin and make the
     * report look broken; the ceiling stops a typo turning every page red.
     */
    public const MIN_THRESHOLD = 1;
    public const MAX_THRESHOLD = 10000;

    /**
     * Posts listed per post type. The counts above the list are exact; this
     * only bounds how many are named.
     */
    public const MAX_LISTED = 25;

    /**
     * The report, from cache when nothing it depends on has moved.
     *
     * @param bool $refresh Rebuild even when the cache is current.
     * @return array<string,mixed>
     */
    public static function report(bool $refresh = false): array {
        $thresholds = self::thresholds();

        // Keep filling the index before answering. Each call is bounded, and
        // `pending` tells the caller whether the answer is complete yet.
        $pending = Word_Count_Index::refresh(array_keys($thresholds), self::STATUSES);

        // What the answer depends on. The revision covers a recount, the
        // thresholds cover the user changing what thin means, and the unit
        // covers a locale switch, which changes every number at once.
        $signature = [
            'revision'   => Word_Count_Index::revision(),
            'thresholds' => $thresholds,
            'unit'       => Word_Count_Index::unit(),
        ];

        $cached = get_option(self::CACHE_OPTION, null);
        if (!$refresh
            && is_array($cached)
            && 0 === $pending
            && ($cached['signature'] ?? null) === $signature
        ) {
            return self::present($cached, 0);
        }

        $totals = Word_Count_Index::totals($thresholds, self::STATUSES);

        $types = [];
        foreach ($thresholds as $post_type => $threshold) {
            $counted = (int) ($totals[$post_type]['counted'] ?? 0);
            $thin    = (int) ($totals[$post_type]['thin'] ?? 0);

            // A post type with nothing published is not a finding. Listing it
            // would fill the report with rows that can never change.
            if (0 === $counted) {
                continue;
            }

            $types[] = [
                'post_type' => $post_type,
                'label'     => self::post_type_label($post_type),
                'threshold' => $threshold,
                'counted'   => $counted,
                'thin'      => $thin,
                'overridden' => self::has_override($post_type),
                'posts'     => $thin > 0
                    ? self::describe(Word_Count_Index::thinnest(
                        $post_type,
                        $threshold,
                        self::STATUSES,
                        self::MAX_LISTED
                    ))
                    : [],
            ];
        }

        $report = [
            'signature' => $signature,
            'types'     => $types,
            'unit'      => $signature['unit'],
            'built_at'  => time(),
        ];

        // Only a complete scan is worth caching: a partial one would be served
        // as the answer long after the index finished filling.
        if (0 === $pending) {
            update_option(self::CACHE_OPTION, $report, false);
        }

        return self::present($report, $pending);
    }

    /**
     * Shape a stored report for its caller.
     *
     * @param array<string,mixed> $report  Stored report.
     * @param int                 $pending Posts still to count.
     * @return array<string,mixed>
     */
    private static function present(array $report, int $pending): array {
        $types = self::add_viewer_fields(array_values((array) ($report['types'] ?? [])));

        $thin = 0;
        $counted = 0;
        foreach ($types as $type) {
            $thin += (int) ($type['thin'] ?? 0);
            $counted += (int) ($type['counted'] ?? 0);
        }

        return [
            // Above zero means the scan has not covered the whole site yet, so
            // the numbers below are a floor rather than the answer.
            'pending'      => max(0, $pending),
            'counted'      => $counted,
            'thin'         => $thin,
            'types'        => $types,
            // Words on most sites, characters on ja/th/zh_* — the number above
            // means nothing without it.
            'unit'         => (string) ($report['unit'] ?? Word_Count_Index::unit()),
            'default_threshold' => self::default_threshold(),
            'generated_at' => (int) ($report['built_at'] ?? 0),
            'scheduled'    => Plan_Config::can('scheduled_alerts', 'thin_content'),
            'limits'       => [
                'max_listed'    => self::MAX_LISTED,
                'min_threshold' => self::MIN_THRESHOLD,
                'max_threshold' => self::MAX_THRESHOLD,
            ],
        ];
    }

    /**
     * Turn counted post IDs into something a reader can act on.
     *
     * Deliberately carries nothing that depends on *who is asking*. This array
     * is what gets stored in the cache option, and the report is one option
     * shared by every user: an editor's `edit_url` and `can_edit` frozen into it
     * would be handed to the administrator who asked next, who would then be
     * sent to the public permalink instead of the editor. {@see add_viewer_fields()}
     * resolves those two per request instead.
     *
     * @param array<int,array{post_id:int, count:int}> $rows From the index.
     * @return array<int,array<string,mixed>>
     */
    private static function describe(array $rows): array {
        $ids = array_column($rows, 'post_id');
        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);
        }

        $described = [];
        foreach ($rows as $row) {
            $post = get_post($row['post_id']);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $described[] = [
                'post_id'    => (int) $post->ID,
                'post_title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'count'      => $row['count'],
                'permalink'  => (string) get_permalink($post),
                'modified'   => (string) $post->post_modified_gmt,
            ];
        }

        return $described;
    }

    /**
     * Add the two fields that belong to the reader rather than to the report.
     *
     * Answered for the current user on every request, cache hit or not, because
     * the cached report is shared and capabilities are not.
     *
     * @param array<int,array<string,mixed>> $types Post type rows.
     * @return array<int,array<string,mixed>>
     */
    private static function add_viewer_fields(array $types): array {
        $ids = [];
        foreach ($types as $type) {
            foreach ((array) ($type['posts'] ?? []) as $post) {
                $ids[] = (int) ($post['post_id'] ?? 0);
            }
        }

        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            _prime_post_caches($ids, false, true);
        }

        foreach ($types as $i => $type) {
            foreach ((array) ($type['posts'] ?? []) as $j => $post) {
                $post_id = (int) ($post['post_id'] ?? 0);

                $types[$i]['posts'][$j]['can_edit'] = $post_id > 0
                    && current_user_can('edit_post', $post_id);
                $types[$i]['posts'][$j]['edit_url'] = $post_id > 0
                    ? (string) get_edit_post_link($post_id, 'raw')
                    : '';
            }
        }

        return $types;
    }

    /**
     * The threshold for every post type in scope.
     *
     * @return array<string,int> Post type => threshold, in post type order.
     */
    public static function thresholds(): array {
        $settings = self::settings();

        $thresholds = [];
        foreach (self::post_types() as $post_type) {
            $thresholds[$post_type] = isset($settings['overrides'][$post_type])
                ? self::clamp((int) $settings['overrides'][$post_type])
                : (int) $settings['default'];
        }

        return $thresholds;
    }

    /**
     * The default threshold, applied to any post type with no override.
     *
     * @return int
     */
    public static function default_threshold(): int {
        return (int) self::settings()['default'];
    }

    /**
     * Whether a post type has a threshold of its own.
     *
     * @param string $post_type Post type.
     * @return bool
     */
    private static function has_override(string $post_type): bool {
        return isset(self::settings()['overrides'][$post_type]);
    }

    /**
     * Stored settings, with every value validated.
     *
     * @return array{default:int, overrides:array<string,int>}
     */
    public static function settings(): array {
        $stored = get_option(self::SETTINGS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $default = isset($stored['default'])
            ? self::clamp((int) $stored['default'])
            : self::DEFAULT_THRESHOLD;

        $overrides = [];
        $raw = isset($stored['overrides']) && is_array($stored['overrides']) ? $stored['overrides'] : [];
        $allowed = self::post_types();
        foreach ($raw as $post_type => $value) {
            $post_type = (string) $post_type;

            // A post type that has been unregistered since the override was
            // saved is kept out of the map rather than dropped from storage:
            // deactivating a plugin for an afternoon should not lose the
            // threshold its post type had.
            if (!in_array($post_type, $allowed, true)) {
                continue;
            }

            $overrides[$post_type] = self::clamp((int) $value);
        }

        return ['default' => $default, 'overrides' => $overrides];
    }

    /**
     * Store new settings, merging into what is there.
     *
     * @param array<string,mixed> $input Partial settings.
     * @return array{default:int, overrides:array<string,int>} What is now stored.
     */
    public static function save_settings(array $input): array {
        $stored = get_option(self::SETTINGS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $next = [
            'default'   => isset($stored['default']) ? (int) $stored['default'] : self::DEFAULT_THRESHOLD,
            'overrides' => isset($stored['overrides']) && is_array($stored['overrides']) ? $stored['overrides'] : [],
        ];

        if (isset($input['default'])) {
            $next['default'] = self::clamp((int) $input['default']);
        }

        if (isset($input['overrides']) && is_array($input['overrides'])) {
            foreach ($input['overrides'] as $post_type => $value) {
                $post_type = sanitize_key((string) $post_type);

                // null clears an override and returns the post type to the
                // default, which is the only way back from one.
                if (null === $value || '' === $value) {
                    unset($next['overrides'][$post_type]);
                    continue;
                }

                $next['overrides'][$post_type] = self::clamp((int) $value);
            }
        }

        update_option(self::SETTINGS_OPTION, $next, false);

        // The thresholds are part of the report's signature, so the cached
        // report retires itself. Nothing has to be recounted: a threshold
        // decides how a count is read, not what it is.
        return self::settings();
    }

    /**
     * Hold a threshold inside its bounds.
     *
     * @param int $value Requested threshold.
     * @return int
     */
    public static function clamp(int $value): int {
        return max(self::MIN_THRESHOLD, min(self::MAX_THRESHOLD, $value));
    }

    /**
     * Post types the report covers.
     *
     * The same policy the rest of Global SEO applies, resolved here rather than
     * on {@see Global_SEO_Post_Types} so this feature carries no shared-surface
     * change of its own. Attachments are excluded: an attachment page has no
     * body to be thin, and including them would report every image on the site.
     *
     * @return string[]
     */
    public static function post_types(): array {
        $post_types = [];

        foreach (get_post_types(['public' => true], 'objects') as $object) {
            if ('attachment' === $object->name || !Global_SEO_Post_Types::is_allowed($object)) {
                continue;
            }

            $post_types[] = $object->name;
        }

        return $post_types;
    }

    /**
     * A post type's plural name, for the report.
     *
     * @param string $post_type Post type.
     * @return string
     */
    private static function post_type_label(string $post_type): string {
        $object = get_post_type_object($post_type);

        if (!$object instanceof \WP_Post_Type) {
            return $post_type;
        }

        return (string) ($object->labels->name ?? $object->label ?? $post_type);
    }
}
