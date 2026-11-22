<?php
/**
 * Regression Guard: check a page, compare it with its last check, keep the
 * result.
 *
 * @package ThinkRank\SEO\Regression
 * @since 2.23.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Regression;

use ThinkRank\Admin\Importers\Thinkrank_Exporter;
use ThinkRank\SEO\Duplicate_Tags\Guard_Settings;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Regression Guard
 *
 * Catches a plugin, theme or core update silently breaking what a page serves
 * to search engines (#720). A check fetches the page ({@see Head_Snapshotter}),
 * compares it with the previous check ({@see Head_Differ}) and stores the new
 * snapshot with the result, so the screen and the MCP ability read the same
 * answer.
 *
 * **What counts as expected.** Beside each snapshot sits a fingerprint of the
 * things a site owner edits on purpose: the post and its ThinkRank meta, and
 * the settings that shape output (ThinkRank's settings table and aggregate
 * options, plus the WordPress reading/permalink/site-title options). When a
 * fingerprint moved between two checks, the changes are labelled expected —
 * "you edited this" — rather than hidden. When neither moved and the page still
 * changed, something else did it, which is the case this feature exists for.
 * Fingerprints rather than timestamps, because most of those settings are
 * options that record no time of change.
 *
 * **Storage.** Free keeps the last check per page: post meta for a post, an
 * option for the homepage. Scheduled checks, history and alerts are Pro's, and
 * sit behind the `thinkrank.seoAnalyzer.regressionGuardTab` slot.
 *
 * @since 2.23.0
 */
class Regression_Guard {

    /**
     * Post meta holding a post's last check.
     */
    public const META_KEY = '_thinkrank_head_snapshot';

    /**
     * Post meta holding when a post was last checked, as a bare timestamp. The
     * check record is serialized and cannot be ordered on, so the checked-pages
     * list sorts and limits by this in SQL.
     */
    public const CHECKED_AT_META_KEY = '_thinkrank_head_checked_at';

    /**
     * Option holding the homepage's last check.
     */
    public const HOME_OPTION = 'thinkrank_home_head_snapshot';

    /**
     * Target id for the homepage.
     */
    public const HOME = 0;

    /**
     * Why a change can be expected: the post, the term, or the settings that
     * shape output were edited between two checks.
     *
     * @var string[]
     */
    public const REASONS = ['post_edited', 'term_edited', 'settings_changed'];

    /**
     * WordPress options that change what pages serve and that an owner only
     * changes on purpose. `blog_public` is here because unticking it is how a
     * site asks to be noindexed.
     *
     * @var string[]
     */
    private const CORE_OPTIONS = [
        'blogname',
        'blogdescription',
        'blog_public',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'permalink_structure',
        'home',
        'siteurl',
        'WPLANG',
    ];

    /**
     * Post meta outside ThinkRank's prefix that feeds the head.
     *
     * @var string[]
     */
    private const POST_META_KEYS = ['_thumbnail_id'];

    /**
     * ThinkRank post meta derived from the post rather than edited: indexes,
     * scores and caches rebuilt on their own schedule. A rebuild is not an
     * edit, and counting it as one would label every change expected.
     *
     * @var string[]
     */
    private const DERIVED_META_KEYS = [
        self::META_KEY,
        self::CHECKED_AT_META_KEY,
        '_thinkrank_seo_score',
        '_thinkrank_word_count',
        '_thinkrank_snippet_index',
        '_thinkrank_seo_column_metrics',
        '_thinkrank_rank_math_blocks_backup',
        '_thinkrank_rank_math_blocks_backup_hash',
    ];

    /**
     * Resolve a target to the URL that is checked.
     *
     * @param int $post_id Post id, or {@see self::HOME} for the homepage.
     * @return array{post_id: int, url: string, label: string, context_post_id: int}|WP_Error
     */
    public static function target(int $post_id) {
        if (self::HOME === $post_id) {
            $front = 'page' === get_option('show_on_front') ? (int) get_option('page_on_front') : 0;

            return [
                'post_id'         => self::HOME,
                'url'             => home_url('/'),
                'label'           => __('Homepage', 'thinkrank'),
                'context_post_id' => $front,
            ];
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('thinkrank_regression_guard_not_found', __('That post does not exist.', 'thinkrank'), ['status' => 404]);
        }

        // The loopback is anonymous — it has to be, to see what Google sees —
        // so a draft or private post would answer 404 and read as "the page
        // broke". Refuse up front and say why instead.
        if ('publish' !== $post->post_status || !is_post_type_viewable($post->post_type)) {
            return new WP_Error(
                'thinkrank_regression_guard_not_public',
                __('Only published, publicly viewable content can be checked: the check loads the page the way a logged-out visitor does.', 'thinkrank'),
                ['status' => 400]
            );
        }

        return [
            'post_id'         => (int) $post->ID,
            'url'             => (string) get_permalink($post),
            'label'           => (string) get_the_title($post),
            'context_post_id' => (int) $post->ID,
        ];
    }

    /**
     * Check a page now and store the result.
     *
     * A failed fetch leaves the stored check untouched and returns the reason:
     * the previous snapshot is still the right baseline for the next check.
     *
     * @param int $post_id Post id, or {@see self::HOME}.
     * @return array<string, mixed>|WP_Error View of the target (see {@see self::view()}).
     */
    public static function check(int $post_id) {
        $target = self::target($post_id);
        if (is_wp_error($target)) {
            return $target;
        }

        $compared = self::compare($target['url'], self::context($target['context_post_id']), self::stored($post_id));
        if (!$compared['ok']) {
            return self::view($target, self::stored($post_id), $compared['error']);
        }

        $record = $compared['record'];

        self::save($post_id, $record);

        return self::view($target, $record);
    }

    /**
     * Load a URL and compare it with a previous check of the same URL.
     *
     * The one step the manual check and Pro's scheduled runs share, so both
     * grade and label changes the same way; the caller owns storage. Any URL
     * on the site works here — Pro checks taxonomy archives too — which is why
     * this takes the context rather than a post id.
     *
     * @param string                    $url      Public URL on this site.
     * @param array<string, string>     $context  Fingerprints now (see {@see self::context()}).
     * @param array<string, mixed>|null $previous Previous record for this URL, if any.
     * @return array{ok: bool, record?: array<string, mixed>, error?: array{code: string, message: string}}
     */
    public static function compare(string $url, array $context, ?array $previous): array {
        $fetched = Head_Snapshotter::fetch($url);
        if (!$fetched['ok']) {
            return [
                'ok'    => false,
                'error' => self::explain($fetched['error']),
            ];
        }

        $snapshot = $fetched['snapshot'];

        $result = null;
        if (null !== $previous && is_array($previous['snapshot'] ?? null) && self::comparable($previous['snapshot'], $snapshot)) {
            $result = Head_Differ::diff(
                $previous['snapshot'],
                $snapshot,
                self::expected_reasons((array) ($previous['context'] ?? []), $context)
            );
        }

        return [
            'ok'     => true,
            'record' => [
                'snapshot'            => $snapshot,
                'context'             => $context,
                'result'              => $result,
                'checked_at'          => time(),
                'previous_checked_at' => null === $result ? null : (int) ($previous['checked_at'] ?? 0),
            ],
        ];
    }

    /**
     * The stored check for a target, without fetching anything.
     *
     * @param int $post_id Post id, or {@see self::HOME}.
     * @return array<string, mixed>|WP_Error
     */
    public static function get(int $post_id) {
        $target = self::target($post_id);
        if (is_wp_error($target)) {
            return $target;
        }

        return self::view($target, self::stored($post_id));
    }

    /**
     * Posts that have been checked, most recently checked first.
     *
     * @param int $limit Most posts returned.
     * @return array<int, array<string, mixed>>
     */
    public static function checked_posts(int $limit = 20): array {
        $ids = get_posts([
            'post_type'              => 'any',
            'post_status'            => 'any',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'meta_key'               => self::CHECKED_AT_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded by $limit; only checked posts carry the key.
            'orderby'                => 'meta_value_num',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ]);

        $rows = [];
        foreach ($ids as $id) {
            $record = self::stored((int) $id);
            if (null === $record) {
                continue;
            }
            $rows[] = [
                'post_id'    => (int) $id,
                'label'      => (string) get_the_title((int) $id),
                'url'        => (string) ($record['snapshot']['url'] ?? ''),
                'checked_at' => (int) $record['checked_at'],
                'counts'     => $record['result']['counts'] ?? null,
                'expected'   => (bool) ($record['result']['expected'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * Why a change between two checks would be expected.
     *
     * @param array<string, string> $before Context stored with the previous snapshot.
     * @param array<string, string> $after  Context now.
     * @return string[] Any of {@see self::REASONS}; empty when nothing was edited.
     */
    public static function expected_reasons(array $before, array $after): array {
        $reasons = [];

        if (($before['post'] ?? '') !== ($after['post'] ?? '')) {
            $reasons[] = 'post_edited';
        }
        if (($before['term'] ?? '') !== ($after['term'] ?? '')) {
            $reasons[] = 'term_edited';
        }
        if (($before['settings'] ?? '') !== ($after['settings'] ?? '')) {
            $reasons[] = 'settings_changed';
        }

        return $reasons;
    }

    /**
     * Fingerprints of what an owner edits on purpose.
     *
     * @param int $post_id Post whose edits count (0: none, e.g. a blog homepage).
     * @param int $term_id Term whose edits count, for a taxonomy archive (0: none).
     * @return array{post: string, term: string, settings: string}
     */
    public static function context(int $post_id, int $term_id = 0): array {
        return [
            'post'     => $post_id > 0 ? self::post_fingerprint($post_id) : '',
            'term'     => $term_id > 0 ? self::term_fingerprint($term_id) : '',
            'settings' => self::settings_fingerprint(),
        ];
    }

    /**
     * Fingerprint of a term: its name, description and ThinkRank meta.
     *
     * @param int $term_id Term id.
     * @return string
     */
    private static function term_fingerprint(int $term_id): string {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return '';
        }

        $meta = [];
        foreach ((array) get_term_meta($term_id) as $key => $values) {
            if (0 === strpos((string) $key, '_thinkrank_')) {
                $meta[(string) $key] = $values;
            }
        }
        ksort($meta);

        return md5((string) wp_json_encode([(string) $term->name, (string) $term->description, $meta]));
    }

    /**
     * Fingerprint of a post: its modified time and its ThinkRank meta.
     *
     * The meta is read because Bulk Snippets, imports and Auto AI write it
     * without touching `post_modified`, and a title changed there is an edit.
     *
     * @param int $post_id Post id.
     * @return string
     */
    private static function post_fingerprint(int $post_id): string {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $meta = [];
        foreach ((array) get_post_meta($post_id) as $key => $values) {
            $key = (string) $key;
            if (in_array($key, self::DERIVED_META_KEYS, true)) {
                continue;
            }
            if (0 === strpos($key, '_thinkrank_') || in_array($key, self::POST_META_KEYS, true)) {
                $meta[$key] = $values;
            }
        }
        ksort($meta);

        return md5((string) wp_json_encode([(string) $post->post_modified_gmt, $meta]));
    }

    /**
     * Fingerprint of the settings that shape output.
     *
     * @return string
     */
    private static function settings_fingerprint(): string {
        global $wpdb;

        $parts = [];

        foreach (Thinkrank_Exporter::AGGREGATE_OPTIONS as $option) {
            $parts['aggregate'][$option] = get_option($option, null);
        }
        foreach (self::CORE_OPTIONS as $option) {
            $parts['core'][$option] = get_option($option, null);
        }

        // The Duplicate Tag Guard takes other plugins' tags out of the head
        // (#733), so switching a family changes what the page serves. The
        // families switched on rather than the stored option: never saved and
        // saved with everything off serve the same page.
        $parts['duplicate_tag_guard'] = (new Guard_Settings())->enabled();

        // The settings table records when each row changed, so its newest
        // change and its row count stand in for reading every value.
        $table = $wpdb->prefix . 'thinkrank_seo_settings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is our own prefix; the value must be read fresh.
        $parts['table'] = $wpdb->get_row("SELECT MAX(updated_at) AS changed, COUNT(*) AS total FROM `{$table}`", ARRAY_A);

        /**
         * Filter what a settings change is detected from.
         *
         * A change to anything in here between two Regression Guard checks marks
         * the page's changes as expected. Add the options a feature renders
         * from; leave out anything that changes on its own (caches, tokens,
         * counters), or every change will read as expected.
         *
         * @since 2.23.0
         *
         * @param array<string, mixed> $parts Named parts of the fingerprint.
         */
        $parts = apply_filters('thinkrank_regression_guard_settings_fingerprint', $parts);

        return md5((string) wp_json_encode($parts));
    }

    /**
     * Whether a stored snapshot can be diffed against a new one.
     *
     * Not when the snapshot shape changed, and not when the page's URL did: a
     * permalink change makes the previous snapshot a different page, and an
     * imported snapshot from another site is a different site.
     *
     * @param mixed                $previous Stored snapshot.
     * @param array<string, mixed> $current  New snapshot.
     * @return bool
     */
    private static function comparable($previous, array $current): bool {
        return is_array($previous)
            && Head_Snapshotter::VERSION === (int) ($previous['v'] ?? 0)
            && (string) ($previous['url'] ?? '') === (string) $current['url'];
    }

    /**
     * The stored check for a target.
     *
     * @param int $post_id Post id, or {@see self::HOME}.
     * @return array<string, mixed>|null
     */
    private static function stored(int $post_id): ?array {
        $record = self::HOME === $post_id
            ? get_option(self::HOME_OPTION, null)
            : get_post_meta($post_id, self::META_KEY, true);

        if (!is_array($record) || !is_array($record['snapshot'] ?? null)) {
            return null;
        }

        return $record;
    }

    /**
     * Store a check.
     *
     * @param int                  $post_id Post id, or {@see self::HOME}.
     * @param array<string, mixed> $record  Check record.
     * @return void
     */
    private static function save(int $post_id, array $record): void {
        if (self::HOME === $post_id) {
            update_option(self::HOME_OPTION, $record, false);
            return;
        }

        // update_post_meta() unslashes: page text with a backslash in it
        // would otherwise come back altered and diff as a change.
        update_post_meta($post_id, self::META_KEY, wp_slash($record));
        update_post_meta($post_id, self::CHECKED_AT_META_KEY, (int) $record['checked_at']);
    }

    /**
     * The response shape shared by the REST endpoint and the abilities.
     *
     * @param array<string, mixed>      $target Target.
     * @param array<string, mixed>|null $record Stored check.
     * @param array<string, string>|null $error Why this check could not run.
     * @return array<string, mixed>
     */
    private static function view(array $target, ?array $record, ?array $error = null): array {
        return [
            'post_id'             => $target['post_id'],
            'url'                 => $target['url'],
            'label'               => $target['label'],
            'checked_at'          => null === $record ? null : (int) $record['checked_at'],
            'previous_checked_at' => null === $record || null === $record['previous_checked_at'] ? null : (int) $record['previous_checked_at'],
            'snapshot'            => null === $record ? null : self::describe($record['snapshot']),
            'result'              => null === $record ? null : $record['result'],
            'error'               => $error,
        ];
    }

    /**
     * A snapshot with the verdicts a reader needs spelled out.
     *
     * @param array<string, mixed> $snapshot Snapshot.
     * @return array<string, mixed>
     */
    private static function describe(array $snapshot): array {
        $snapshot['noindex'] = Head_Snapshotter::is_noindex($snapshot);

        return $snapshot;
    }

    /**
     * A fetch failure with what to do about it.
     *
     * @param array{code: string, message: string} $error Fetch error.
     * @return array{code: string, message: string}
     */
    private static function explain(array $error): array {
        // Refused before any request: nothing failed to load, and the host
        // has nothing to allow, so the loopback advice below would mislead.
        if ('loopback_foreign_url' === $error['code']) {
            return $error;
        }

        if ('loopback_blocked' === $error['code']) {
            $message = sprintf(
                /* translators: %s: technical detail */
                __('The page could not be read (%s). Either it is behind a login, password or firewall for logged-out visitors, or the host blocks the site from loading its own pages. Nothing was compared, and the last check is kept.', 'thinkrank'),
                $error['message']
            );
        } else {
            $message = sprintf(
                /* translators: %s: technical detail */
                __('The server could not load its own page (%s). Some hosts block a site from requesting itself; ask your host to allow loopback requests. Nothing was compared, and the last check is kept.', 'thinkrank'),
                $error['message']
            );
        }

        return [
            'code'    => $error['code'],
            'message' => $message,
        ];
    }
}
