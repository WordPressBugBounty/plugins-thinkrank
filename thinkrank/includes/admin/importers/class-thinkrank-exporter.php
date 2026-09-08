<?php

/**
 * ThinkRank Exporter
 *
 * Exports ThinkRank's OWN data so a user can take it with them, move it to
 * another site, or keep it as a backup.
 *
 * Unlike the source-plugin exporters, this one does NOT normalize into the
 * canonical format the migrator maps through Snapshot_Migrator::META_MAP.
 * That round-trip is lossy for our own data: migrate_robots_payload() rebuilds
 * `_thinkrank_robots_meta` from canonical flags (deriving `index`,
 * `snippet_enabled`, …) and writes nothing when every flag is false, and every
 * meta key outside META_MAP — `_thinkrank_seo_score`, `_thinkrank_metadata`,
 * `_thinkrank_seo_settings`, `_thinkrank_focus_keywords_overflow`, and anything
 * added in future — is dropped. For a backup, fidelity is the point, so records
 * carry raw `_thinkrank_*` key => value pairs verbatim and the restore side
 * writes them back unchanged.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.2.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

use ThinkRank\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ThinkRank Exporter Class
 *
 * @since 2.2.0
 */
class Thinkrank_Exporter extends Abstract_Plugin_Exporter {

    /**
     * Snapshot slug for ThinkRank's own data.
     */
    public const SLUG = 'thinkrank';

    /**
     * Meta key prefix for all ThinkRank post/term/user meta.
     */
    public const META_PREFIX = '_thinkrank_';

    /**
     * Aggregate settings options written directly by feature managers and the
     * migrator, rather than through Settings (which uses one option per key).
     *
     * None of these collide with a `thinkrank_{key}` option from
     * Settings::$defaults, so the two buckets stay disjoint.
     *
     * Public because the restore side uses it as an allow-list: a hand-edited
     * export must not be able to write arbitrary site options.
     */
    public const AGGREGATE_OPTIONS = [
        'thinkrank_global_seo_settings',
        'thinkrank_site_identity_settings',
        'thinkrank_social_media_settings',
        'thinkrank_global_robot_meta_settings',
        'thinkrank_instant_indexing_settings',
        'thinkrank_image_seo_settings',
        'thinkrank_email_report_settings',
        'thinkrank_author_archives_settings',
    ];

    /**
     * Data types the free plugin exports itself.
     */
    private const CORE_TYPES = ['postmeta', 'termmeta', 'usermeta', 'settings'];

    /**
     * Secrets that are NOT in Settings::$encrypted_keys but must never reach an
     * export file either: the Brand Visibility per-platform API keys, which are
     * stored as plain settings today.
     *
     * The encrypted keys themselves come from Settings::get_encrypted_keys() so
     * this list cannot drift from that one.
     */
    private const EXTRA_SECRET_KEYS = [
        'bv_key_chatgpt',
        'bv_key_gemini',
        'bv_key_claude',
        'bv_key_perplexity',
    ];

    /**
     * Credential-shaped keys nested INSIDE an aggregate option.
     *
     * The flat secret list matches on key name wherever it appears, which is
     * the right rule for names that are unambiguous on their own
     * (`openai_api_key`). A bare `api_key` is not: it is only a secret because
     * of the option it sits in, so it is scoped here rather than banned
     * everywhere.
     *
     * IndexNow's key is public by design — the plugin serves it at
     * `/<key>.txt` — so this is hygiene rather than a leak being closed. It is
     * still stripped, because it is the only credential-shaped value that
     * currently reaches an export and leaving one exception in place is what
     * let redaction drift out of two of the three buckets to begin with.
     *
     * Snapshot_Migrator reads this to put the LOCAL value back on restore, so
     * stripping a key here never wipes the one the receiving site already has.
     *
     * @var array<string, string[]> Option name => secret keys inside it.
     */
    public const SECRET_OPTION_KEYS = [
        'thinkrank_instant_indexing_settings' => ['api_key'],
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_slug     = self::SLUG;
        $this->plugin_name     = 'ThinkRank';
        $this->plugin_file     = 'thinkrank/thinkrank.php';
        $this->meta_key_prefix = self::META_PREFIX;
        // Settings are read from three stores (see export_settings()), not from
        // a flat list of option keys, so this stays empty on purpose.
        $this->option_keys = [];
    }

    /**
     * ThinkRank's own data is always "detected" — we are the running plugin.
     *
     * @return bool
     */
    public function detect(): bool {
        return true;
    }

    /**
     * Every type a ThinkRank export can carry.
     *
     * Pro's data lives in its own tables (redirections, 404 logs, rank tracker,
     * Brand Visibility runs), which the free plugin cannot read. Rather than
     * leaving Pro users with a half-export, Pro registers its types here and
     * supplies the records through `thinkrank_export_records`; the restore side
     * hands them back through `thinkrank_restore_records`. Free ships the seam
     * so Pro is not blocked on a follow-up release.
     *
     * @since 2.2.0
     *
     * @return string[] Type slugs, in export order.
     */
    public static function get_exportable_types(): array {
        /**
         * Filters the data types a ThinkRank export covers.
         *
         * A type added here must also answer `thinkrank_export_records` (to
         * produce records) and `thinkrank_restore_records` (to apply them).
         *
         * @since 2.2.0
         *
         * @param string[] $types Type slugs, in export order.
         */
        $types = (array) apply_filters('thinkrank_export_types', self::CORE_TYPES);

        return array_values(array_unique(array_filter($types, 'is_string')));
    }

    /**
     * Get available data types with their record counts
     *
     * @return array Associative array of type => count
     */
    public function get_available_types(): array {
        global $wpdb;

        $counts = [];
        $like   = $wpdb->esc_like($this->meta_key_prefix) . '%';

        // Mirror get_post_ids_with_meta()'s viewable-post-type filter, or the
        // total reported here would not match the number of records the export
        // actually emits.
        $post_types = $this->get_exportable_post_types();
        if (!empty($post_types)) {
            $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names are $wpdb properties; every value is a placeholder replacement.
            $sql = $wpdb->prepare(
                "SELECT COUNT(DISTINCT pm.post_id)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key LIKE %s
                 AND p.post_type IN ({$placeholders})",
                array_merge([$like], $post_types)
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
            $post_count = (int) $wpdb->get_var($sql);
            if ($post_count > 0) {
                $counts['postmeta'] = $post_count;
            }
        }

        $term_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
                $like
            )
        );
        if ($term_count > 0) {
            $counts['termmeta'] = $term_count;
        }

        // Counted here (unlike the source-plugin detector, which never counts
        // usermeta) because the UI derives its type checkboxes from these
        // counts — a type missing here is invisible to the user.
        $user_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $like
            )
        );
        if ($user_count > 0) {
            $counts['usermeta'] = $user_count;
        }

        // Settings always exist — Settings::get_all() falls back to defaults —
        // so this type is always offered.
        $counts['settings'] = 1;

        /**
         * Filters the record counts a ThinkRank export offers.
         *
         * The UI derives its type checkboxes from these, so a type with no
         * count here is invisible even when a handler stands ready to export it.
         *
         * @since 2.2.0
         *
         * @param array<string,int> $counts Type slug => record count.
         */
        $counts = (array) apply_filters('thinkrank_export_type_counts', $counts);

        // Keep the declared order, and drop anything that has nothing to say.
        $ordered = [];
        foreach (self::get_exportable_types() as $type) {
            if (!empty($counts[$type])) {
                $ordered[$type] = (int) $counts[$type];
            }
        }

        return $ordered;
    }

    /**
     * Export a page of post meta
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of records
     */
    protected function export_postmeta_page(int $page): array {
        $records = [];

        foreach ($this->get_post_ids_with_meta($page) as $post_id) {
            $post_id = (int) $post_id;
            $meta    = $this->collect_meta($this->get_all_plugin_meta($post_id));

            if (empty($meta)) {
                continue;
            }

            $records[] = $this->build_record($post_id, 'post', $meta);
        }

        return $records;
    }

    /**
     * Export a page of term meta
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of records
     */
    protected function export_termmeta_page(int $page): array {
        $records = [];

        foreach ($this->get_term_ids_with_meta($page) as $term_id) {
            $term_id = (int) $term_id;
            $meta    = $this->collect_meta($this->get_all_plugin_term_meta($term_id));

            if (empty($meta)) {
                continue;
            }

            $records[] = $this->build_record($term_id, 'term', $meta);
        }

        return $records;
    }

    /**
     * Export a page of user meta
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of records
     */
    protected function export_usermeta_page(int $page): array {
        $records = [];

        foreach ($this->get_user_ids_with_meta($page) as $user_id) {
            $user_id = (int) $user_id;
            $meta    = $this->collect_meta($this->get_all_plugin_user_meta($user_id));

            if (empty($meta)) {
                continue;
            }

            $records[] = $this->build_record($user_id, 'user', $meta);
        }

        return $records;
    }

    /**
     * Export every settings store as a single record.
     *
     * ThinkRank keeps settings in three places, and a backup that misses one of
     * them restores cleanly while changing nothing on the site:
     *
     * - `thinkrank_{key}` options    — Settings::$defaults, one option per key
     * - the thinkrank_seo_settings table — what the FRONTEND actually reads
     * - standalone aggregate options — written directly by feature managers
     *
     * @return array Array with a single settings record
     */
    protected function export_settings(): array {
        return [
            [
                'type'          => 'settings',
                'source_plugin' => self::SLUG,
                'data'          => $this->redact_secrets([
                    'options'   => $this->export_option_settings(),
                    'seo_table' => $this->export_seo_table_settings(),
                    'aggregate' => $this->export_aggregate_options(),
                ]),
            ],
        ];
    }

    /**
     * Strip every secret from the assembled payload, once.
     *
     * Redaction used to live inside export_option_settings() alone, so it
     * protected the bucket it was written for and neither of the other two:
     * the settings table and the aggregate options were serialized verbatim.
     * Nothing said they shouldn't be, which meant the day any of them held a
     * real credential it would leave the site silently.
     *
     * Doing it here instead of per-bucket means a bucket added later is
     * covered by construction rather than by remembering.
     *
     * @param array $data The three settings buckets.
     * @return array The same buckets with secrets removed.
     */
    private function redact_secrets(array $data): array {
        $secret_keys = self::secret_setting_keys();

        foreach (['options', 'seo_table', 'aggregate'] as $bucket) {
            if (isset($data[$bucket]) && is_array($data[$bucket])) {
                $data[$bucket] = $this->strip_secret_keys($data[$bucket], $secret_keys);
            }
        }

        // Option-scoped secrets: only a secret because of where they sit.
        foreach (self::SECRET_OPTION_KEYS as $option_name => $option_secrets) {
            if (!isset($data['aggregate'][$option_name]) || !is_array($data['aggregate'][$option_name])) {
                continue;
            }

            foreach ($option_secrets as $secret_key) {
                unset($data['aggregate'][$option_name][$secret_key]);
            }
        }

        return $data;
    }

    /**
     * Remove any key named as a secret, at any depth.
     *
     * Recursive on purpose: the settings table nests the stored key three
     * levels down (category → context → id → key), and a stored value can
     * itself be an array. A secret is a secret wherever it turns up.
     *
     * @param array    $data        Data to filter.
     * @param string[] $secret_keys Key names that must never be exported.
     * @return array
     */
    private function strip_secret_keys(array $data, array $secret_keys): array {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $secret_keys, true)) {
                unset($data[$key]);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->strip_secret_keys($value, $secret_keys);
            }
        }

        return $data;
    }

    /**
     * Redirections live in Pro's own store, so the free plugin has nothing of
     * its own here — the records come from Pro through the extension filter.
     *
     * @param int $page Page number (1-indexed)
     * @return array
     */
    protected function export_redirections_page(int $page): array {
        return $this->export_extension_page('redirections', $page);
    }

    /**
     * As above: Pro owns the 404 log.
     *
     * @param int $page Page number (1-indexed)
     * @return array
     */
    protected function export_404_logs_page(int $page): array {
        return $this->export_extension_page('404_logs', $page);
    }

    /**
     * A type Pro registered through `thinkrank_export_types`.
     *
     * @param string $type Data type
     * @param int    $page Page number (1-indexed)
     * @return array Records
     */
    protected function export_custom_type_page(string $type, int $page): array {
        return $this->export_extension_page($type, $page);
    }

    /**
     * Records for a type the free plugin does not own.
     *
     * @param string $type Data type
     * @param int    $page Page number (1-indexed)
     * @return array Records
     */
    public function export_extension_page(string $type, int $page): array {
        /**
         * Filters the records a non-core export type contributes.
         *
         * Return the same record shape the core types use, and an empty array
         * once the last page is reached — pagination stops when a page comes
         * back shorter than the chunk size.
         *
         * @since 2.2.0
         *
         * @param array  $records Records for this page (empty by default).
         * @param string $type    Data type being exported.
         * @param int    $page    1-based page number.
         */
        $records = apply_filters('thinkrank_export_records', [], $type, $page);

        return is_array($records) ? $records : [];
    }

    /**
     * Templates in our own data are already in ThinkRank's format and must
     * round-trip untouched, so this is a pass-through — the only transform is
     * the base class's coercion of non-string values.
     *
     * @param mixed    $value   Stored value
     * @param int|null $post_id Unused; kept for the parent signature
     * @return string
     */
    protected function convert_template_variables($value, ?int $post_id = null): string {
        return $this->stringify_template_value($value);
    }

    /**
     * Build a snapshot record.
     *
     * @param int    $object_id   Post / term / user ID
     * @param string $object_type Object type
     * @param array  $meta        Raw meta, key => value
     * @return array
     */
    private function build_record(int $object_id, string $object_type, array $meta): array {
        return [
            'object_id'     => $object_id,
            'object_type'   => $object_type,
            'source_plugin' => self::SLUG,
            'data'          => $meta,
        ];
    }

    /**
     * Prepare raw meta rows for the snapshot.
     *
     * The `get_all_plugin_*_meta()` helpers return raw DB strings, so a
     * serialized array arrives as its serialized STRING. Writing that back with
     * update_post_meta() would store a string where an array used to be — the
     * value looks right in the database and comes back wrong from
     * get_post_meta(). Unserializing here keeps the round-trip exact and lets
     * the JSON export hold real structure instead of a PHP-serialized blob.
     *
     * @param array $meta Raw meta_key => meta_value pairs
     * @return array Prepared meta
     */
    private function collect_meta(array $meta): array {
        /**
         * Filter meta keys to leave out of a ThinkRank export.
         *
         * Everything under `_thinkrank_` is exported verbatim by default,
         * including derived caches. Sites that would rather not carry those can
         * drop them here.
         *
         * @since 2.2.0
         *
         * @param string[] $skip_keys Meta keys to omit.
         */
        $skip_keys = (array) apply_filters('thinkrank_export_skip_meta_keys', []);

        $prepared = [];
        foreach ($meta as $key => $value) {
            if (in_array($key, $skip_keys, true)) {
                continue;
            }

            $prepared[$key] = is_serialized($value)
                ? Safe_Unserializer::unserialize($value, $value)
                : $value;
        }

        return $prepared;
    }

    /**
     * Export the `thinkrank_{key}` options behind Settings.
     *
     * Secrets are removed rather than offered behind an opt-in. Beyond being a
     * plain-text credential leak in a file users pass around, encrypted values
     * carry the `trenc:v1:` marker and are derived from the site's auth salts —
     * they could not be decrypted after a restore onto another site anyway.
     *
     * The stripping itself is not done here: redact_secrets() runs over all
     * three buckets once, so this returns the raw store.
     *
     * @return array Setting key => value
     */
    private function export_option_settings(): array {
        return Settings::instance()->get_all();
    }

    /**
     * Settings keys that must never appear in an export.
     *
     * Public and static because Snapshot_Migrator needs the same list to undo
     * the redaction on the way back in: an aggregate option is restored whole,
     * so every key stripped here has to be carried forward from the receiving
     * site or the restore deletes it.
     *
     * @return string[]
     */
    public static function secret_setting_keys(): array {
        return array_values(
            array_unique(
                array_merge(Settings::instance()->get_encrypted_keys(), self::EXTRA_SECRET_KEYS)
            )
        );
    }

    /**
     * Export every row of the thinkrank_seo_settings table.
     *
     * Read straight from the table rather than through each feature manager's
     * get_settings(), which merges in defaults — a backup wants the rows that
     * are actually stored, and this way a category we forgot to enumerate (or
     * one added later) is still captured.
     *
     * @return array Category => context type => context id => [key => value]
     */
    private function export_seo_table_settings(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'thinkrank_seo_settings';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return [];
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off export read of our own table; caching a full-table dump would be worse than the query.
        $rows = $wpdb->get_results(
            "SELECT context_type, context_id, setting_category, setting_key, setting_value, setting_type, priority
             FROM `{$table}`
             WHERE is_active = 1
             ORDER BY setting_category ASC, context_type ASC, context_id ASC, setting_key ASC",
            ARRAY_A
        );
        // phpcs:enable

        $settings = [];
        foreach ((array) $rows as $row) {
            $category     = (string) $row['setting_category'];
            $context_type = (string) $row['context_type'];
            $context_id   = (int) $row['context_id'];

            $settings[$category][$context_type][$context_id][$row['setting_key']] = [
                'value'    => maybe_unserialize($row['setting_value']),
                'type'     => $row['setting_type'],
                'priority' => (int) $row['priority'],
            ];
        }

        return $settings;
    }

    /**
     * Export the standalone aggregate settings options.
     *
     * @return array Option name => value
     */
    private function export_aggregate_options(): array {
        $options = [];

        foreach (self::AGGREGATE_OPTIONS as $option_name) {
            $value = get_option($option_name, null);
            if ($value === null || $value === false) {
                continue;
            }

            $options[$option_name] = $value;
        }

        return $options;
    }
}
