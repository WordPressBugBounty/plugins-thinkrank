<?php
/**
 * Bounded transactional storage and scoped restoration for reviewed image alt text.
 *
 * @package ThinkRank\SEO
 * @since 2.17.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The lock covers the attachment and its metadata range, including an absent row.
 *
 * A WordPress previous-value argument is not a compare-and-swap for an empty
 * string. A named lock alone would not serialize ordinary Media Library writes.
 * This requires transactional core tables and a fresh serializable transaction.
 */
final class Image_Alt_Store {
    public const META_KEY = '_wp_attachment_image_alt';
    public const MAX_ALT_BYTES = 4096;
    public const UNDO_SECONDS = 30 * 24 * 60 * 60;
    private const TOKEN_PURPOSE = 'thinkrank-image-alt-restore-v1.';

    /** @var \wpdb */
    private $db;

    public function __construct() {
        $this->db = $GLOBALS['wpdb'];
    }

    /**
     * Run one short local mutation under row/range locks. Never call AI here.
     *
     * WP_Error or an exception rolls back. SET TRANSACTION must succeed before
     * START TRANSACTION so an existing caller transaction is never committed
     * implicitly. Ordinary WordPress writers honor the InnoDB row/range locks.
     *
     * @param int      $attachment_id Attachment ID.
     * @param callable $operation    Work to do while the image is locked.
     * @return mixed|\WP_Error
     */
    public function run(int $attachment_id, callable $operation) {
        if ($attachment_id < 1) {
            return $this->error('invalid_attachment_id', __('Choose a valid image attachment.', 'thinkrank'), 400);
        }
        $started = false;
        $timeout = null;
        $reconnects = null;
        $suppressed = $this->db->suppress_errors(true);
        try {
            $engines = $this->db->get_results($this->db->prepare(
                'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
                $this->db->posts,
                $this->db->postmeta
            ), ARRAY_A);
            if (!is_array($engines) || count($engines) !== 2 || count(array_filter($engines, static fn($row) => strtoupper((string) ($row['engine'] ?? '')) === 'INNODB')) !== 2) {
                return $this->error('storage_unavailable', __('Reviewed alt changes require transactional WordPress posts and metadata tables. Edit this image in the Media Library instead.', 'thinkrank'), 409);
            }
            $old_timeout = $this->db->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
            if (!is_numeric($old_timeout) || (int) $old_timeout < 1) {
                return $this->error('storage_unavailable', __('The database cannot provide a bounded image metadata lock.', 'thinkrank'), 409);
            }
            $timeout = (int) $old_timeout;
            // wpdb normally reconnects and retries a query after MySQL goes
            // away. That new connection would have lost these transaction
            // locks. Its backward-compatible property accessors expose this
            // setting; fail closed if a database drop-in cannot honor it.
            if (!isset($this->db->reconnect_retries) || !is_int($this->db->reconnect_retries) || $this->db->reconnect_retries < 0) {
                return $this->error('storage_unavailable', __('The database cannot preserve the reviewed transaction without reconnecting. Edit this image in the Media Library.', 'thinkrank'), 409);
            }
            $reconnects = $this->db->reconnect_retries;
            $this->db->reconnect_retries = 0;
            if ($this->db->reconnect_retries !== 0) {
                return $this->error('storage_unavailable', __('The database cannot preserve the reviewed transaction without reconnecting. Edit this image in the Media Library.', 'thinkrank'), 409);
            }
            if (false === $this->db->query($this->db->prepare('SET SESSION innodb_lock_wait_timeout = %d', min(3, $timeout))) ||
                false === $this->db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')) {
                return $this->error('transaction_unavailable', __('A fresh image metadata transaction is unavailable. No reviewed change was sent.', 'thinkrank'), 409);
            }
            if (false === $this->db->query('START TRANSACTION')) {
                return $this->error('transaction_unavailable', __('The image metadata transaction could not start.', 'thinkrank'), 503);
            }
            $started = true;
            $posts = Database::quote_identifier($this->db->posts);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name quoted by Database; the attachment ID is bound.
            $locked = $this->db->get_var($this->db->prepare("SELECT ID FROM {$posts} WHERE ID = %d FOR UPDATE", $attachment_id));
            if ('' !== $this->db->last_error) {
                return $this->error('busy', __('This image is being changed elsewhere. Read a fresh preview before trying again.', 'thinkrank'), 409);
            }
            if ((int) $locked !== $attachment_id) {
                return $this->error('missing', __('The image is no longer available.', 'thinkrank'), 404);
            }
            $meta = Database::quote_identifier($this->db->postmeta);
            // Lock the post_id index range, not just an existing alt row. Refuse
            // unusually large metadata sets before attempting any mutation.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name quoted; fixed index/columns and a bound attachment ID.
            $rows = $this->db->get_col($this->db->prepare("SELECT meta_id FROM {$meta} FORCE INDEX (post_id) WHERE post_id = %d LIMIT 2001 FOR UPDATE", $attachment_id));
            if ('' !== $this->db->last_error || !is_array($rows) || count($rows) > 2000) {
                return $this->error('busy', __('The image metadata could not be locked within the review limit. Review it on the site.', 'thinkrank'), 409);
            }
            // WordPress primes all metadata for one attachment on first read.
            // Bound that cache load before the callback asks for a snapshot.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Quoted core table and a bound attachment ID.
            $bytes = $this->db->get_var($this->db->prepare("SELECT COALESCE(SUM(OCTET_LENGTH(meta_value)), 0) FROM {$meta} WHERE post_id = %d", $attachment_id));
            if ('' !== $this->db->last_error || !is_numeric($bytes) || (int) $bytes > 1048576) {
                return $this->error('metadata_limit', __('This image exceeds the metadata review limit. Edit it in the Media Library.', 'thinkrank'), 409);
            }
            self::clear_cache($attachment_id);
            $result = $operation();
            if (is_wp_error($result)) {
                $rolled_back = $this->rollback();
                $started = false;
                if (!$rolled_back) {
                    return $this->error('write_unknown', __('The database did not confirm rollback. Read the current image alt text before any further change.', 'thinkrank'), 502);
                }
                return $result;
            }
            if (false === $this->db->query('COMMIT')) {
                // A lost acknowledgement does not prove that COMMIT failed.
                return $this->error('write_unknown', __('The database did not confirm the save. Read the image on the site before deciding whether to try again.', 'thinkrank'), 502);
            }
            $started = false;
            return $result;
        } catch (\Throwable $error) {
            if ($started) {
                $rolled_back = $this->rollback();
                $started = false;
                if (!$rolled_back) {
                    return $this->error('write_unknown', __('The database did not confirm rollback. Read the current image alt text before any further change.', 'thinkrank'), 502);
                }
            }
            return $this->error('write_failed', __('The reviewed image change could not be completed. Read the current alt text before trying again.', 'thinkrank'), 500);
        } finally {
            if ($started) {
                $this->rollback();
            }
            // Neither committed nor rolled-back request-local values should
            // survive in a persistent cache after the transaction ends.
            self::clear_cache($attachment_id);
            if (null !== $timeout) {
                try {
                    $this->db->query($this->db->prepare('SET SESSION innodb_lock_wait_timeout = %d', $timeout));
                } catch (\Throwable $error) {
                    // A lost connection must not turn an unknown outcome into
                    // an uncaught cleanup error or retry the original write.
                }
            }
            if (null !== $reconnects) {
                $this->db->reconnect_retries = $reconnects;
            }
            $this->db->suppress_errors($suppressed);
        }
    }

    /**
     * Read the exact logical metadata state, distinguishing absent from empty.
     *
     * Ambiguous duplicate rows and non-string values are not rewritten.
     *
     * @param int $attachment_id Attachment ID.
     * @return array{exists: bool, value: string}|\WP_Error
     */
    public function state(int $attachment_id) {
        $meta = Database::quote_identifier($this->db->postmeta);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Quoted core table, bound ID and metadata key.
        $rows = $this->db->get_results($this->db->prepare("SELECT meta_key, LEFT(meta_value, %d) AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM {$meta} WHERE post_id = %d AND meta_key = %s LIMIT 2", self::MAX_ALT_BYTES + 65, $attachment_id, self::META_KEY), ARRAY_A);
        if ('' !== $this->db->last_error || !is_array($rows)) {
            return $this->error('state_unavailable', __('The stored image alt text could not be verified.', 'thinkrank'), 503);
        }
        if ([] === $rows) {
            return ['exists' => false, 'value' => ''];
        }
        if (count($rows) !== 1 || $rows[0]['meta_key'] !== self::META_KEY) {
            return $this->error('ambiguous_metadata', __('This image has ambiguous alt metadata. Review its metadata on the site before making a hub change.', 'thinkrank'), 409);
        }
        if ((int) $rows[0]['value_bytes'] > self::MAX_ALT_BYTES + 64) {
            return $this->error('unsupported_metadata', __('This image has an oversized alt value. Edit it in the Media Library.', 'thinkrank'), 409);
        }
        $value = $rows[0]['meta_value'];
        if (is_serialized($value)) {
            // Never instantiate an object stored by another metadata writer.
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Existing metadata can encode a literal serialized string; other types are refused below.
            $value = @unserialize(trim($value), ['allowed_classes' => false]);
        }
        if (!is_string($value) || strlen($value) > self::MAX_ALT_BYTES || 1 !== preg_match('//u', $value)) {
            return $this->error('unsupported_metadata', __('This image has an unsupported or oversized alt value. Edit it in the Media Library.', 'thinkrank'), 409);
        }
        return ['exists' => true, 'value' => $value];
    }

    /**
     * Mint an authenticated, expiring undo descriptor for one exact alt change.
     *
     * This is a signed payload, not encryption. Clients retaining it must seal
     * it at rest; it contains only the previous alt metadata, never post content.
     * A different salt, site URL, WordPress user or attachment invalidates it.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $before        Original logical state.
     * @param array $after         Expected state after the change.
     * @return string|\WP_Error
     */
    public function restoration(int $attachment_id, array $before, array $after) {
        $json = wp_json_encode([
            'v' => 1, 'site' => home_url('/'), 'user' => get_current_user_id(),
            'attachment' => $attachment_id, 'expires' => time() + self::UNDO_SECONDS,
            'before' => $before, 'after' => self::state_digest($after),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || get_current_user_id() < 1) {
            return $this->error('undo_unavailable', __('An authenticated restoration handle could not be prepared. No alt text was saved.', 'thinkrank'), 409);
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport for a bounded, authenticated payload.
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $token = $encoded . '.' . hash_hmac('sha256', self::TOKEN_PURPOSE . $encoded, wp_salt('auth'));
        if (strlen($token) > 12000) {
            return $this->error('undo_unavailable', __('The previous alt value exceeds the restoration limit. No alt text was saved.', 'thinkrank'), 409);
        }
        return $token;
    }

    /**
     * Authenticate a restoration handle; the caller must still compare current state.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $token         Signed restoration payload.
     * @return array|\WP_Error
     */
    public function open_restoration(int $attachment_id, string $token) {
        $invalid = $this->error('undo_invalid', __('This restoration handle is invalid, expired or belongs to another image or user. No alt text was changed.', 'thinkrank'), 409);
        if (strlen($token) > 12000 || 1 !== preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/', $token, $parts) ||
            !hash_equals(hash_hmac('sha256', self::TOKEN_PURPOSE . $parts[1], wp_salt('auth')), $parts[2])) {
            return $invalid;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict decode after authenticating the bounded payload.
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true, 8) : null;
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || ($payload['site'] ?? null) !== home_url('/') ||
            ($payload['user'] ?? null) !== get_current_user_id() || get_current_user_id() < 1 || ($payload['attachment'] ?? null) !== $attachment_id ||
            !is_int($payload['expires'] ?? null) || $payload['expires'] <= time() ||
            !is_array($payload['before'] ?? null) || !is_bool($payload['before']['exists'] ?? null) ||
            !is_string($payload['before']['value'] ?? null) || strlen($payload['before']['value']) > self::MAX_ALT_BYTES ||
            (!$payload['before']['exists'] && '' !== $payload['before']['value']) ||
            !is_string($payload['after'] ?? null) || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $payload['after'])) {
            return $invalid;
        }
        return $payload;
    }

    public static function state_digest(array $state): string {
        return hash('sha256', (string) wp_json_encode(['exists' => $state['exists'], 'value' => $state['value']]));
    }

    public static function clear_cache(int $attachment_id): void {
        wp_cache_delete($attachment_id, 'posts');
        wp_cache_delete($attachment_id, 'post_meta');
    }

    private function error(string $code, string $message, int $status): \WP_Error {
        return new \WP_Error('thinkrank_alt_' . $code, $message, ['status' => $status]);
    }

    private function rollback(): bool {
        try {
            return false !== $this->db->query('ROLLBACK');
        } catch (\Throwable $error) {
            return false;
        }
    }
}
