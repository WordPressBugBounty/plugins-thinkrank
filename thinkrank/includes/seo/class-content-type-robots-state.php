<?php
/** Exact, bounded robots settings for a content-type row. @package ThinkRank\SEO */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inactive directives and absent keys are part of the state to restore.
 *
 * @since 2.17.0
 */
final class Content_Type_Robots_State {
    public const DIRECTIVES = ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'];
    private const MAX_OPTION_BYTES = 1048576;

    /** @return array The same exact-state schema for REST and abilities. */
    public static function schema(): array {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'description' => __('Exact stored robots state. Omitted enabled or directives keys remove those stored keys. Preserve the state returned by the read when changing only one value. An empty object restores an untouched inherited state.', 'thinkrank'),
            'properties' => [
                'enabled' => ['type' => 'boolean'],
                'directives' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => array_fill_keys(self::DIRECTIVES, ['type' => 'boolean']),
                ],
            ],
        ];
    }

    /** @param mixed $value Untrusted exact state. @return array|null Valid state, including an empty map. */
    public static function validate($value): ?array {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value) || array_diff(array_keys($value), ['enabled', 'directives'])) {
            return null;
        }
        if (array_key_exists('enabled', $value) && !is_bool($value['enabled'])) {
            return null;
        }
        if (array_key_exists('directives', $value)) {
            $directives = $value['directives'] instanceof \stdClass ? (array) $value['directives'] : $value['directives'];
            if (!is_array($directives) || array_diff(array_keys($directives), self::DIRECTIVES)) {
                return null;
            }
            foreach ($directives as $directive) {
                if (!is_bool($directive)) {
                    return null;
                }
            }
            ksort($directives);
            $value['directives'] = $directives;
        }
        ksort($value);
        return $value;
    }

    /** @param array $state Validated state. @return array|\stdClass A JSON object, including empty nested maps. */
    private static function object_value(array $state) {
        if (array_key_exists('directives', $state) && [] === $state['directives']) {
            $state['directives'] = new \stdClass();
        }
        return [] === $state ? new \stdClass() : $state;
    }

    /** @param string $entity Entity key. @param array $stored Unfiltered stored row. @return array Safe state and its current-value guard. */
    public static function inspect(string $entity, array $stored): array {
        $state = [];
        foreach (['robots_meta_enabled' => 'enabled', 'robots_meta' => 'directives'] as $key => $field) {
            if (array_key_exists($key, $stored)) {
                $state[$field] = $stored[$key];
            }
        }
        $state = self::validate($state);
        $value = null === $state ? null : self::object_value($state);
        return [
            'robots_state' => $value,
            'robots_state_fingerprint' => null === $state ? null : hash('sha256', (string) wp_json_encode([$entity, $value])),
        ];
    }

    /** @param array $stored Existing row. @param array $state Validated desired state. @return array Only the two robots keys are replaced. */
    public static function replace(array $stored, array $state): array {
        unset($stored['robots_meta_enabled'], $stored['robots_meta']);
        foreach (['enabled' => 'robots_meta_enabled', 'directives' => 'robots_meta'] as $field => $key) {
            if (array_key_exists($field, $state)) {
                $stored[$key] = $state[$field];
            }
        }
        return $stored;
    }

    /** Evict request-local and persistent option snapshots around the transaction. */
    private static function clear_cache(): void {
        foreach ([Content_Type_Settings::OPTION_NAME, 'alloptions', 'notoptions'] as $key) {
            wp_cache_delete($key, 'options');
        }
        Content_Type_Settings::flush_cache();
    }

    /** @param string $code Error suffix. @param string $message User-facing message. @param int $status HTTP status. @return \WP_Error */
    private static function error(string $code, string $message, int $status = 409): \WP_Error {
        return new \WP_Error('thinkrank_robots_' . $code, $message, ['status' => $status]);
    }

    /** Reject objects/resources in the shared option instead of rewriting an unknown serialized value. */
    private static function plain_option($value, int $depth = 0): bool {
        if ($depth > 12 || is_object($value) || is_resource($value)) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::plain_option($item, $depth + 1)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Replace one exact state in a fresh, bounded transaction. Ordinary option
     * writers honor the row/range lock, including when the option is absent.
     * No site/provider request runs here. An unknown commit is never retried.
     *
     * @param string $entity Valid entity key.
     * @param mixed  $value Exact desired state.
     * @param string $expected Fingerprint from the read.
     * @return array|\WP_Error The independently read stored state.
     */
    public static function save(string $entity, $value, string $expected) {
        $state = self::validate($value);
        if (null === $state || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !Content_Type_Settings::is_valid_entity($entity)) {
            return self::error('invalid_state', __('Read one valid content-type row and provide its exact robots state and fingerprint.', 'thinkrank'), 400);
        }
        global $wpdb;
        $started = false;
        $commit_attempted = false;
        $timeout = null;
        $reconnects = null;
        $suppressed = $wpdb->suppress_errors(true);
        $table = Database::quote_identifier($wpdb->options);
        try {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options));
            $old_timeout = $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
            if ('INNODB' !== strtoupper((string) $engine) || !is_numeric($old_timeout) || (int) $old_timeout < 1 || !isset($wpdb->reconnect_retries) || !is_int($wpdb->reconnect_retries) || $wpdb->reconnect_retries < 0) {
                return self::error('storage_unavailable', __('Exact robots changes require a transactional WordPress options table and a bounded lock. Review this row on the site.', 'thinkrank'));
            }
            $timeout = (int) $old_timeout;
            $reconnects = $wpdb->reconnect_retries;
            $wpdb->reconnect_retries = 0;
            if (0 !== $wpdb->reconnect_retries || false === $wpdb->query($wpdb->prepare('SET SESSION innodb_lock_wait_timeout = %d', min(3, $timeout))) || false === $wpdb->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE') || false === $wpdb->query('START TRANSACTION')) {
                return self::error('transaction_unavailable', __('A fresh robots-settings transaction is unavailable. No change was sent.', 'thinkrank'));
            }
            $started = true;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Quoted core table, bounded option name and read size.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT LEFT(option_value, %d) AS value, OCTET_LENGTH(option_value) AS bytes FROM {$table} WHERE option_name = %s LIMIT 2 FOR UPDATE", self::MAX_OPTION_BYTES + 1, Content_Type_Settings::OPTION_NAME), ARRAY_A);
            if ('' !== $wpdb->last_error || !is_array($rows) || count($rows) > 1 || ($rows && (int) $rows[0]['bytes'] > self::MAX_OPTION_BYTES)) {
                return self::error('state_unavailable', __('The current robots settings could not be locked within the review limit.', 'thinkrank'));
            }
            $all = [];
            if ($rows) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Never instantiate objects stored by another option writer.
                $all = @unserialize($rows[0]['value'], ['allowed_classes' => false]);
            }
            if (!is_array($all) || !self::plain_option($all) || (array_key_exists($entity, $all) && !is_array($all[$entity]))) {
                return self::error('unsupported_state', __('The stored settings contain values this editor cannot restore exactly.', 'thinkrank'));
            }
            $before = $all[$entity] ?? [];
            $read = self::inspect($entity, $before);
            if (null === $read['robots_state_fingerprint'] || !hash_equals($read['robots_state_fingerprint'], $expected)) {
                return self::error('state_changed', __('These robots settings changed after the read. Read the row again before saving.', 'thinkrank'));
            }
            $after = self::replace($before, $state);
            if ([] === $after) {
                unset($all[$entity]);
            } else {
                $all[$entity] = $after;
            }
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Compare WordPress's existing option encoding after refusing objects.
            $serialized = serialize($all);
            if (strlen($serialized) > self::MAX_OPTION_BYTES) {
                return self::error('state_limit', __('The updated settings exceed the exact-state review limit.', 'thinkrank'));
            }
            self::clear_cache();
            if ($before !== $after) {
                update_option(Content_Type_Settings::OPTION_NAME, $all);
            }
            // Verify storage, not a filtered/cached get_option or submitted values.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Quoted core table and a bound option name.
            $saved = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$table} WHERE option_name = %s", Content_Type_Settings::OPTION_NAME));
            if ('' !== $wpdb->last_error || ($saved !== $serialized && !(null === $saved && [] === $all))) {
                return self::error('save_failed', __('The stored robots settings did not match the requested state. No verified save is available.', 'thinkrank'), 502);
            }
            $commit_attempted = true;
            if (false === $wpdb->query('COMMIT')) {
                return self::error('write_unknown', __('The database did not confirm the save. Read the row before any further change.', 'thinkrank'), 502);
            }
            $started = false;
            return self::inspect($entity, $after);
        } catch (\Throwable $error) {
            if ($commit_attempted) {
                return self::error('write_unknown', __('The database did not confirm the save. Read the row before any further change.', 'thinkrank'), 502);
            }
            return self::error('write_failed', __('The robots change could not be completed. Read the current row before trying again.', 'thinkrank'), 502);
        } finally {
            $rollback_failed = false;
            if ($started) {
                try {
                    $rollback_failed = false === $wpdb->query('ROLLBACK');
                } catch (\Throwable $error) {
                    $rollback_failed = true;
                }
            }
            self::clear_cache();
            if (null !== $timeout) {
                try {
                    $wpdb->query($wpdb->prepare('SET SESSION innodb_lock_wait_timeout = %d', $timeout));
                } catch (\Throwable $error) {
                    // A lost connection must not retry a change during cleanup.
                }
            }
            if (null !== $reconnects) {
                $wpdb->reconnect_retries = $reconnects;
            }
            $wpdb->suppress_errors($suppressed);
            if ($rollback_failed) {
                return self::error('write_unknown', __('The database did not confirm rollback. Read the current row before any further change.', 'thinkrank'), 502);
            }
        }
    }
}
