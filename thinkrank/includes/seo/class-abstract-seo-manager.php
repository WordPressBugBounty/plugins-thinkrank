<?php

/**
 * Abstract SEO Manager Base Class
 *
 * Provides common functionality for all SEO managers including database operations,
 * validation patterns, and utility methods. All concrete SEO managers should extend
 * this class to ensure consistent behavior and reduce code duplication.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\SEO\Interfaces\SEO_Manager_Interface;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract SEO Manager Base Class
 *
 * Implements common functionality for all SEO managers following DRY principles.
 * Provides database operations, validation utilities, and standardized patterns.
 *
 * @since 1.0.0
 */
abstract class Abstract_SEO_Manager implements SEO_Manager_Interface {

    /**
     * WordPress database instance
     *
     * @since 1.0.0
     * @var \wpdb
     */
    protected \wpdb $wpdb;

    /**
     * Settings table name
     *
     * @since 1.0.0
     * @var string
     */
    protected string $settings_table;

    /**
     * Manager type identifier
     *
     * @since 1.0.0
     * @var string
     */
    protected string $manager_type;

    /**
     * Why the most recent save_settings() call failed.
     *
     * save_settings() returns a bare bool, so the caller that has to tell the
     * user something ends up printing a generic "failed" string while the real
     * reason goes only to the error log. Holding it here lets the REST layer
     * put the actual cause in the response. First failure wins: a rejected
     * INSERT can cascade across keys, and the first one names the root cause.
     *
     * @since 1.32.1
     * @var string
     */
    protected string $last_save_error = '';

    /**
     * Machine-readable counterpart to $last_save_error.
     *
     * @since 1.32.1
     * @var string
     */
    protected string $last_save_error_code = '';

    /**
     * Supported context types
     *
     * @since 1.0.0
     * @var array
     */
    protected array $supported_contexts = ['site', 'post', 'page', 'product'];

    /**
     * Constructor
     *
     * @since 1.0.0
     *
     * @param string $manager_type The manager type identifier
     */
    public function __construct(string $manager_type) {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->settings_table = $wpdb->prefix . 'thinkrank_seo_settings';
        $this->manager_type = sanitize_key($manager_type);
    }

    /**
     * Get SEO settings for a specific context
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array SEO settings array
     */
    public function get_settings(string $context_type, ?int $context_id = null): array {
        $context_type = sanitize_key($context_type);

        if (!in_array($context_type, $this->get_supported_contexts(), true)) {
            return $this->get_default_settings($context_type);
        }

        // Convert NULL context_id to 0 for site-wide settings to match save behavior
        $db_context_id = $context_id === null ? 0 : $context_id;

        // Serve from the object cache when available. This runs on every front-end
        // request (the_content, thumbnails), so avoiding a DB hit per request matters.
        $cache_key = $this->get_cache_key($context_type, $context_id);
        $cached = wp_cache_get($cache_key, 'thinkrank_seo');
        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings require direct database access for real-time data, table name is validated
        $sql = sprintf(
            'SELECT setting_key, setting_value FROM `%s` WHERE context_type = %%s AND context_id = %%d AND setting_category = %%s AND is_active = 1',
            $this->settings_table
        );

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from sprintf with validated table name then prepared below.
        $results = $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                $sql,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameters are validated and used as placeholders
                $context_type,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- context_id is validated integer
                $db_context_id,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- manager_type is validated class property
                $this->manager_type
            ),
            ARRAY_A
        );

        $settings = [];
        foreach ($results as $row) {
            $value = maybe_unserialize($row['setting_value']);

            // Ensure proper data type conversion for boolean fields
            if (in_array($row['setting_key'], $this->boolean_setting_keys(), true)) {
                // Convert string/numeric boolean representations to actual booleans
                if (is_string($value)) {
                    $value = in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
                } elseif (is_numeric($value)) {
                    $value = (bool) $value;
                }
            }

            // Ensure cache_duration is an integer
            if ($row['setting_key'] === 'cache_duration') {
                $value = (int) $value;
            }

            $settings[$row['setting_key']] = $value;
        }

        // Merge with defaults to ensure all required keys exist
        $merged = array_merge($this->get_default_settings($context_type), $settings);

        // Cache the resolved settings; invalidated on every save via clear_cache().
        wp_cache_set($cache_key, $merged, 'thinkrank_seo');

        return $merged;
    }

    /**
     * Save SEO settings for a specific context
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @param array    $settings     Settings array to save
     * @return bool True on success, false on failure
     */
    public function save_settings(string $context_type, ?int $context_id, array $settings): bool {
        $context_type = sanitize_key($context_type);

        $this->last_save_error      = '';
        $this->last_save_error_code = '';

        if (!in_array($context_type, $this->get_supported_contexts(), true)) {
            $this->log_save_failure("unsupported context type '{$context_type}'", 'unsupported_context');
            return false;
        }

        // Check if settings table exists. This is the failure a user cannot
        // diagnose from the UI: on hosts where CREATE TABLE failed (e.g. the
        // 767-byte InnoDB index limit on MySQL 5.6-era servers), every save in
        // every manager fails with a generic message while option-backed
        // features keep working — so name the cause loudly.
        //
        // Creation is retried on every request, so a table that stays missing
        // means the database is refusing the statement. Database_Schema records
        // that refusal; lead with it, because it is the only text here that
        // names this site's actual problem.
        if (!$this->ensure_settings_table_exists()) {
            $create_error = \ThinkRank\Database\Database_Schema::get_last_create_failure();

            $this->log_save_failure(
                "settings table '{$this->settings_table}' does not exist. " .
                ('' !== $create_error
                    ? 'The database refused to create it: ' . $create_error
                    : 'ThinkRank re-attempts creation on every load, so no reactivation is needed. ' .
                        'If the table never appears, the database is rejecting the CREATE TABLE: check that the ' .
                        'database user holds the CREATE privilege, and ask your host for the MySQL/MariaDB version, ' .
                        'as 5.6-era servers cap an index at 767 bytes and reject wider schemas.'),
                'settings_table_missing'
            );
            return false;
        }

        // Validate settings before saving
        $validation = $this->validate_settings($settings);
        if (!$validation['valid']) {
            $this->log_save_failure(
                'validation failed: ' . wp_json_encode($validation['errors'] ?? []),
                'validation_failed'
            );
            return false;
        }

        // Sanitize settings
        $sanitized_settings = $this->sanitize_settings($settings, $context_type);

        $success = true;
        foreach ($sanitized_settings as $key => $value) {
            $sanitized_key = sanitize_key($key);
            $serialized_value = maybe_serialize($value);
            $current_time = current_time('mysql');

            // Convert NULL context_id to 0 for site-wide settings to work with UNIQUE constraint
            // MySQL treats multiple NULL values as distinct in UNIQUE constraints
            $db_context_id = $context_id === null ? 0 : $context_id;

            // Use INSERT ... ON DUPLICATE KEY UPDATE for proper upsert behavior
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->settings_table is a validated class property set from $wpdb->prefix.
            $sql = $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "INSERT INTO `{$this->settings_table}`
                (`context_type`, `context_id`, `setting_category`, `setting_key`, `setting_value`, `is_active`, `created_at`, `updated_at`)
                VALUES (%s, %d, %s, %s, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                `setting_value` = VALUES(`setting_value`),
                `is_active` = VALUES(`is_active`),
                `updated_at` = VALUES(`updated_at`)",
                $context_type,
                $db_context_id,
                $this->manager_type,
                $sanitized_key,
                $serialized_value,
                1,
                $current_time,
                $current_time
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings require direct database access, SQL is properly prepared
            $result = $this->wpdb->query($sql);

            if (false === $result) {
                $this->log_save_failure(
                    "insert failed for key '{$sanitized_key}'" .
                    ('' !== (string) $this->wpdb->last_error ? ' — ' . $this->wpdb->last_error : ''),
                    'db_insert_failed'
                );
                $success = false;
            }
        }

        // Clear relevant caches
        $this->clear_cache($context_type, $context_id);

        /**
         * Fires after a settings category has been written.
         *
         * Lets one manager react to another's save — the Schema Manager uses it
         * to refresh LocalBusiness when Site Identity's Business Info changes,
         * since those fields live in a different category and never appear in a
         * schema settings payload (#455).
         *
         * @since 2.0.2
         *
         * @param string   $manager_type Settings category that was saved.
         * @param array    $settings     The sanitized settings that were written.
         * @param string   $context_type Context type.
         * @param int|null $context_id   Context ID.
         */
        do_action(
            'thinkrank_seo_settings_saved',
            $this->manager_type,
            $sanitized_settings,
            $context_type,
            $context_id
        );

        return $success;
    }

    /**
     * Delete settings for a specific context
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return bool True on success, false on failure
     */
    public function delete_settings(string $context_type, ?int $context_id): bool {
        $context_type = sanitize_key($context_type);

        // Convert NULL context_id to 0 for site-wide settings to match save behavior
        $db_context_id = $context_id === null ? 0 : $context_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings deletion requires direct database access
        $result = $this->wpdb->delete(
            $this->settings_table,
            [
                'context_type' => $context_type,
                'context_id' => $db_context_id,
                'setting_category' => $this->manager_type
            ],
            ['%s', '%d', '%s']
        );

        if ($result !== false) {
            $this->clear_cache($context_type, $context_id);
            return true;
        }

        return false;
    }

    /**
     * Check if settings exist for a context
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return bool True if settings exist, false otherwise
     */
    public function has_settings(string $context_type, ?int $context_id): bool {
        $context_type = sanitize_key($context_type);

        // Convert NULL context_id to 0 for site-wide settings to match save behavior
        $db_context_id = $context_id === null ? 0 : $context_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings existence check requires direct database access, table name is validated
        $sql = sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE context_type = %%s AND context_id = %%d AND setting_category = %%s AND is_active = 1',
            $this->settings_table
        );

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from sprintf with validated table name then prepared below.
        $count = $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                $sql,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parameters are validated and used as placeholders
                $context_type,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- context_id is validated integer
                $db_context_id,
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- manager_type is validated class property
                $this->manager_type
            )
        );

        return (int) $count > 0;
    }

    /**
     * Get supported context types
     *
     * @since 1.0.0
     *
     * @return array Array of supported context types
     */
    public function get_supported_contexts(): array {
        return $this->supported_contexts;
    }

    /**
     * String setting keys whose newlines must be preserved on save.
     *
     * @var string[]
     */
    private const MULTILINE_STRING_KEYS = ['robots_txt_content'];

    /**
     * Keys holding %token% TEMPLATES rather than plain text.
     *
     * sanitize_text_field() strips anything matching /%[a-f0-9]{2}/ as a
     * percent-encoded byte, which silently eats the leading characters of any
     * token whose first two letters are valid hex — %category_title% becomes
     * "tegory_title%", %date% becomes "te%". These keys therefore go through
     * sanitize_template_field() instead.
     */
    private const TEMPLATE_STRING_KEYS = [
        'homepage_title', 'post_title', 'page_title', 'category_title', 'tag_title',
        'search_title', 'archive_title', 'author_title',
        'homepage_description', 'post_description', 'page_description',
        'title_template', 'description_template',
        'alt_format', 'title_format', 'caption_format',
        'subject_template',
    ];

    /**
     * REST envelope keys that must never become stored settings.
     *
     * Every settings endpoint answers with
     * {settings, schema, context_type, context_id}. A caller that posts that
     * whole envelope back as `settings` writes those four keys as rows, and
     * because get_settings() returns every stored row, they then round-trip
     * into the next request forever — the Site Identity payload carried ~6KB
     * of a serialized copy of itself plus its own JSON schema on every save.
     * They are not settings in any manager, so drop them on the way in.
     *
     * @var string[]
     */
    protected const RESERVED_ENVELOPE_KEYS = ['settings', 'schema', 'context_type', 'context_id'];

    /**
     * Setting keys this manager stores that its defaults do not name.
     *
     * get_default_settings() is the natural allow-list, but it is not complete
     * in every manager: Site Identity declares 16 defaults while the screens
     * behind it legitimately store 55 keys, and gating on defaults alone would
     * stop title formats, breadcrumb configuration and business details from
     * saving at all. A manager whose defaults are complete overrides nothing.
     *
     * @since 2.0.1
     *
     * @return string[]
     */
    protected function additional_setting_keys(): array {
        return [];
    }

    /**
     * Regular expressions matching key FAMILIES this manager stores.
     *
     * For settings whose key set is open by design — the schema manager's
     * per-entity fields, the sitemap's per-post-type inclusion flags — an
     * enumerated list would go stale the first time a post type is registered.
     * Patterns are anchored and deliberately narrow: they must describe a
     * family the manager owns, never a catch-all.
     *
     * @since 2.0.1
     *
     * @return string[] PCRE patterns, delimiters included.
     */
    protected function dynamic_setting_key_patterns(): array {
        return [];
    }

    /**
     * The setting keys this manager accepts.
     *
     * @since 2.0.1
     *
     * @param string $context_type Context the save is for.
     * @return string[]
     */
    public function get_known_setting_keys(string $context_type = 'site'): array {
        $keys = array_merge(
            array_keys($this->get_default_settings($context_type)),
            $this->additional_setting_keys()
        );

        $keys = array_values(array_unique(array_filter($keys, 'is_string')));

        /**
         * Filters the keys a settings category accepts.
         *
         * Shared with Settings_Management_Endpoint so an add-on registering
         * settings against an existing category declares them once.
         *
         * @since 2.0.1
         *
         * @param string[] $keys         Accepted setting keys.
         * @param string   $category     Settings category (the manager type).
         * @param string   $context_type Context the save is for.
         */
        return apply_filters('thinkrank_known_setting_keys', $keys, $this->manager_type, $context_type);
    }

    /**
     * Whether this manager stores a setting under this key.
     *
     * Public counterpart of is_known_setting_key() for callers outside the
     * save path — the schema upgrade that clears rows written before the
     * allow-list existed, and tests.
     *
     * @since 2.0.1
     *
     * @param string $key          Setting key.
     * @param string $context_type Context to judge it in.
     * @return bool
     */
    public function accepts_setting_key(string $key, string $context_type = 'site'): bool {
        return $this->is_known_setting_key(sanitize_key($key), $this->get_known_setting_keys($context_type));
    }

    /**
     * Whether a key is one this manager stores.
     *
     * @since 2.0.1
     *
     * @param string $key          Sanitized setting key.
     * @param array  $known        Known keys for the context.
     * @return bool
     */
    protected function is_known_setting_key(string $key, array $known): bool {
        if (in_array($key, $known, true)) {
            return true;
        }

        foreach ($this->dynamic_setting_key_patterns() as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize settings array
     *
     * @since 1.0.0
     *
     * @param array $settings Settings to sanitize
     * @return array Sanitized settings
     */
    protected function sanitize_settings(array $settings, string $context_type = 'site'): array {
        $sanitized = [];
        $known     = $this->get_known_setting_keys($context_type);

        foreach ($settings as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (in_array($sanitized_key, self::RESERVED_ENVELOPE_KEYS, true)) {
                continue;
            }

            // A key no manager declares is not a setting. Stored, it becomes a
            // row that get_settings() returns forever, so it round-trips into
            // every later response and is re-posted by the UI on the next save
            // — which is how the REST envelope came to be stored (#452).
            if (!$this->is_known_setting_key($sanitized_key, $known)) {
                $this->log_unknown_setting_key($sanitized_key);
                continue;
            }

            if (is_string($value)) {
                // Multi-line fields must keep their newlines; sanitize_text_field
                // would flatten them onto a single line.
                if (in_array($sanitized_key, self::MULTILINE_STRING_KEYS, true)) {
                    $sanitized[$sanitized_key] = sanitize_textarea_field($value);
                } elseif (in_array($sanitized_key, self::TEMPLATE_STRING_KEYS, true)) {
                    $sanitized[$sanitized_key] = $this->sanitize_template_field($value);
                } else {
                    $sanitized[$sanitized_key] = sanitize_text_field($value);
                }
            } elseif (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_array_recursive($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Record a rejected setting key.
     *
     * Dropping silently is the hazard this gate carries: a legitimate key
     * missing from a manager's declarations would disappear with no trace. On
     * a debug install it says so; in production it stays quiet, since the
     * common source is a client posting fields that were never settings.
     *
     * @since 2.0.1
     *
     * @param string $key Key that was dropped.
     * @return void
     */
    private function log_unknown_setting_key(string $key): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostic; a dropped key is otherwise invisible.
        error_log(sprintf('ThinkRank [%s]: dropped unknown setting key "%s"', $this->manager_type, $key));
    }

    /**
     * Sanitize a %token% template while keeping its tokens intact.
     *
     * Applies the same protections as sanitize_text_field() — tag stripping,
     * invalid-UTF8 rejection, control-character and newline removal — but
     * deliberately omits its percent-encoding strip, which corrupts tokens like
     * %category_title% and %date%. Templates are only ever rendered into
     * escaped output, so no percent sequence here reaches a URL context raw.
     *
     * @since 1.20.1
     *
     * @param string $value Raw template
     * @return string Sanitized template
     */
    private function sanitize_template_field(string $value): string {
        $filtered = wp_check_invalid_utf8($value);

        if (strpos($filtered, '<') !== false) {
            $filtered = wp_pre_kses_less_than($filtered);
            // Wrap in a paragraph so wp_strip_all_tags() sees a complete node.
            $filtered = wp_strip_all_tags($filtered, false);
            $filtered = str_replace("<\n", "&lt;\n", $filtered);
        }

        // Collapse newlines/tabs to spaces and drop other control characters,
        // mirroring sanitize_text_field()'s single-line guarantee.
        $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered);
        $filtered = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $filtered);

        return trim((string) $filtered);
    }

    /**
     * Recursively sanitize array values
     *
     * @since 1.0.0
     *
     * @param array $input Array to sanitize
     * @return array Sanitized array
     */
    private function sanitize_array_recursive(array $input): array {
        $sanitized = [];

        foreach ($input as $key => $value) {
            $sanitized_key = sanitize_key($key);

            if (is_string($value)) {
                $sanitized[$sanitized_key] = sanitize_text_field($value);
            } elseif (is_array($value)) {
                $sanitized[$sanitized_key] = $this->sanitize_array_recursive($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitized_key] = (float) $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitized_key] = (bool) $value;
            } elseif ('' === $value || null === $value) {
                // Handle empty values - preserve as empty string for open/close times, convert to boolean for closed
                if ($sanitized_key === 'closed') {
                    $sanitized[$sanitized_key] = false;
                } else {
                    $sanitized[$sanitized_key] = '';
                }
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * Clear cache for specific context
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     */
    protected function clear_cache(string $context_type, ?int $context_id): void {
        $cache_key = $this->get_cache_key($context_type, $context_id);
        wp_cache_delete($cache_key, 'thinkrank_seo');

        // Clear related transients
        delete_transient("thinkrank_seo_{$this->manager_type}_{$context_type}_{$context_id}");
    }

    /**
     * Get cache key for context
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return string Cache key
     */
    /**
     * Setting keys stored as booleans, so a read hands them back as booleans.
     *
     * The database stores them as '1' / '', and a manager whose validator
     * demands a real boolean will then reject its own stored values — which is
     * exactly what made every save routed through
     * Seo_Settings_Manager::save_settings_by_category() fail after it merged
     * the existing settings back in (#395). Subclasses override this so the
     * read and the validator cannot drift apart.
     *
     * @since 2.0.1
     *
     * @return string[] Keys to coerce to boolean on read.
     */
    protected function boolean_setting_keys(): array {
        return [
            'enabled',
            'auto_generate_schema',
            'rich_snippets_optimization',
            'performance_tracking',
            'auto_deploy',
            'validation_on_save',
            'rich_snippets_testing',
            'organization_schema',
            'knowledge_graph',
            'add_missing_alt',
            'add_missing_title',
            'save_alt_to_media',
            'auto_fill_on_upload',
            'media_alt_overwrite',
        ];
    }

    protected function get_cache_key(string $context_type, ?int $context_id): string {
        // Normalise NULL to 0 so reads (which pass NULL for site-wide) and writes
        // (which pass 0) resolve to the SAME cache entry — otherwise a save would
        // never invalidate the value a front-end read cached.
        $db_context_id = $context_id === null ? 0 : $context_id;
        return "seo_settings_{$this->manager_type}_{$context_type}_{$db_context_id}";
    }

    /**
     * Ensure settings table exists
     *
     * @since 1.0.0
     *
     * @return bool True if table exists or was created successfully
     */
    /**
     * Record why a save failed, so "Failed to update … settings" in the UI has
     * a matching, actionable line in the PHP error log.
     *
     * A customer cannot act on the generic message, and neither can support
     * without this — the missing-table case (wizard blocked after migration,
     * every settings screen failing) looked identical to a validation problem.
     *
     * The reason is also kept on the instance so the REST layer can put it in
     * the response instead of a fixed string — see get_last_save_error().
     *
     * @since 1.28.0
     *
     * @param string $reason Why the save failed.
     * @param string $code   Optional. Machine-readable failure code.
     * @return void
     */
    protected function log_save_failure(string $reason, string $code = 'save_failed'): void {
        if ('' === $this->last_save_error) {
            $this->last_save_error      = $reason;
            $this->last_save_error_code = $code;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic; the UI only shows a generic failure message.
        error_log(sprintf('ThinkRank [%s]: settings save failed — %s', $this->manager_type, $reason));
    }

    /**
     * Why the last save_settings() call returned false.
     *
     * @since 1.32.1
     *
     * @return string Failure reason, or '' if the last save succeeded.
     */
    public function get_last_save_error(): string {
        return $this->last_save_error;
    }

    /**
     * Machine-readable code for the last save failure.
     *
     * One of: unsupported_context, settings_table_missing, validation_failed,
     * db_insert_failed, save_failed.
     *
     * @since 1.32.1
     *
     * @return string Failure code, or '' if the last save succeeded.
     */
    public function get_last_save_error_code(): string {
        return $this->last_save_error_code;
    }

    protected function ensure_settings_table_exists(): bool {
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table existence check requires direct database access
        $table_exists = $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- settings_table is validated class property
                $this->settings_table
            )
        );

        return $table_exists === $this->settings_table;
    }

    // Abstract methods that must be implemented by concrete classes

    /**
     * Validate SEO settings (must be implemented by concrete classes)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    abstract public function validate_settings(array $settings): array;

    /**
     * Get output data for frontend rendering (must be implemented by concrete classes)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    abstract public function get_output_data(string $context_type, ?int $context_id): array;

    /**
     * Get default settings for a context type (must be implemented by concrete classes)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    abstract public function get_default_settings(string $context_type): array;

    /**
     * Get settings schema definition (must be implemented by concrete classes)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    abstract public function get_settings_schema(string $context_type): array;

    /**
     * Bulk update settings
     *
     * @since 1.0.0
     *
     * @param array $bulk_settings Array of settings keyed by context_type:context_id
     * @return array Results array with success/failure status for each update
     */
    public function bulk_update_settings(array $bulk_settings): array {
        $results = [];

        foreach ($bulk_settings as $context_key => $settings) {
            // Parse context key (format: "context_type:context_id" or "context_type")
            $parts = explode(':', $context_key);
            $context_type = $parts[0];
            $context_id = isset($parts[1]) ? (int) $parts[1] : null;

            $success = $this->save_settings($context_type, $context_id, $settings);
            $results[$context_key] = [
                'success' => $success,
                'context_type' => $context_type,
                'context_id' => $context_id,
                'message' => $success ? 'Settings updated successfully' : 'Failed to update settings'
            ];
        }

        return $results;
    }

    /**
     * Get settings history
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @param int      $limit        Optional. Number of revisions to return
     * @return array Array of settings revisions
     */
    public function get_settings_history(string $context_type, ?int $context_id, int $limit = 10): array {
        // For now, return empty array - history tracking can be implemented later
        // This would require additional database tables for revision tracking
        return [];
    }

    /**
     * Export settings
     *
     * @since 1.0.0
     *
     * @param string   $context_type Optional. Context type to export
     * @param int|null $context_id   Optional. Context ID to export
     * @return array Exported settings with metadata
     */
    public function export_settings(?string $context_type = null, ?int $context_id = null): array {
        $export_data = [
            'version' => '1.0.0',
            'manager_type' => $this->manager_type,
            'exported_at' => current_time('mysql'),
            'settings' => []
        ];

        if ($context_type !== null) {
            // Export specific context
            $settings = $this->get_settings($context_type, $context_id);
            $export_data['settings'][$context_type . ':' . ($context_id ?? 'site')] = $settings;
        } else {
            // Export all settings for this manager type
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO settings export requires direct database access, table name is validated
            $sql = sprintf(
                'SELECT context_type, context_id, setting_key, setting_value FROM `%s` WHERE setting_category = %%s AND is_active = 1',
                $this->settings_table
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from sprintf with validated table name then prepared below.
            $results = $this->wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                $this->wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                    $sql,
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- manager_type is validated class property
                    $this->manager_type
                ),
                ARRAY_A
            );

            $grouped_settings = [];
            foreach ($results as $row) {
                $key = $row['context_type'] . ':' . ($row['context_id'] ?? 'site');
                $grouped_settings[$key][$row['setting_key']] = maybe_unserialize($row['setting_value']);
            }

            $export_data['settings'] = $grouped_settings;
        }

        return $export_data;
    }

    /**
     * Import settings
     *
     * @since 1.0.0
     *
     * @param array $import_data Exported settings data
     * @param array $options     Import options
     * @return array Import results with success/failure details
     */
    public function import_settings(array $import_data, array $options = []): array {
        $results = [
            'success' => true,
            'imported_count' => 0,
            'failed_count' => 0,
            'details' => []
        ];

        // Validate import data structure
        if (!isset($import_data['settings']) || !is_array($import_data['settings'])) {
            $results['success'] = false;
            $results['details'][] = 'Invalid import data structure';
            return $results;
        }

        // Default import options
        $options = array_merge([
            'merge_strategy' => 'replace', // 'replace', 'merge', 'skip_existing'
            'validate' => true
        ], $options);

        foreach ($import_data['settings'] as $context_key => $settings) {
            // Parse context key
            $parts = explode(':', $context_key);
            $context_type = $parts[0];
            $context_id = isset($parts[1]) && $parts[1] !== 'site' ? (int) $parts[1] : null;

            // Check if settings already exist
            if ($options['merge_strategy'] === 'skip_existing' && $this->has_settings($context_type, $context_id)) {
                $results['details'][] = "Skipped existing settings for {$context_key}";
                continue;
            }

            // Merge with existing settings if requested
            if ($options['merge_strategy'] === 'merge' && $this->has_settings($context_type, $context_id)) {
                $existing_settings = $this->get_settings($context_type, $context_id);
                $settings = array_merge($existing_settings, $settings);
            }

            // Validate settings if requested
            if ($options['validate']) {
                $validation = $this->validate_settings($settings);
                if (!$validation['valid']) {
                    $results['failed_count']++;
                    $results['details'][] = "Validation failed for {$context_key}: " . implode(', ', $validation['errors']);
                    continue;
                }
            }

            // Import settings
            $success = $this->save_settings($context_type, $context_id, $settings);
            if ($success) {
                $results['imported_count']++;
                $results['details'][] = "Successfully imported settings for {$context_key}";
            } else {
                $results['failed_count']++;
                $results['details'][] = "Failed to import settings for {$context_key}";
            }
        }

        $results['success'] = $results['failed_count'] === 0;
        return $results;
    }
}
