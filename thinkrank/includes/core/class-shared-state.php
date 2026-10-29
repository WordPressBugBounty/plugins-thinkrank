<?php
/**
 * Read-modify-write of shared options and transients, one request at a time.
 *
 * `get_option()` → change → `update_option()` is two steps with a gap between
 * them, and every PHP request runs in its own process. Two requests that both
 * read a counter at 4 both write 5; two that both read a settings array each
 * write back their own copy, and the second undoes the first. The same holds
 * for transients, which live in the options table unless a persistent object
 * cache is installed.
 *
 * Every helper here takes a MySQL advisory lock (GET_LOCK) named after the
 * value, re-reads the value from the database under it, applies the change and
 * writes it back before releasing. GET_LOCK is the one primitive WordPress
 * gives a plugin that holds across processes on every MySQL host: add_option()
 * is not one, because core's INSERT … ON DUPLICATE KEY UPDATE reports success
 * to both of two racing callers.
 *
 * Best effort by design, as in Mcp_OAuth::lock(): where the lock cannot be
 * taken (an SQLite drop-in, a proxy without session locks, contention past the
 * timeout) the change runs unguarded, which is exactly the behaviour these
 * call sites had before.
 *
 * Re-reading matters as much as the lock. An autoloaded option is read once,
 * at the start of the request, and a request that waited on the lock (or made
 * a slow HTTP call before its write) would otherwise apply its change to that
 * snapshot and write back whatever another request committed in the meantime.
 *
 * @package ThinkRank\Core
 * @since 2.15.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Locked read-modify-write helpers for options and transients.
 *
 * @since 2.15.0
 */
final class Shared_State {

    /**
     * Returned by an update_option() mutator to leave the option untouched.
     */
    public const KEEP = "\0thinkrank:keep";

    /**
     * Returned by an update_option() mutator to delete the option.
     */
    public const DELETE = "\0thinkrank:delete";

    /**
     * Seconds to wait for a lock. The guarded sections are a read and a write,
     * so a wait this long means something is wrong; proceeding beats hanging
     * an admin request.
     */
    private const LOCK_TIMEOUT = 3;

    /**
     * Locks this request holds, name => nesting depth.
     *
     * @var array<string, int>
     */
    private static array $held = [];

    /**
     * Run $work while holding the named lock.
     *
     * @since 2.15.0
     *
     * @param string   $name Lock name; any string, hashed before use.
     * @param callable $work   function (bool $locked): mixed.
     * @return mixed Whatever $work returned.
     */
    public static function locked(string $name, callable $work) {
        $locked = self::lock($name);

        try {
            return $work($locked);
        } finally {
            if ($locked) {
                self::unlock($name);
            }
        }
    }

    /**
     * Change an option under its lock, starting from its committed value.
     *
     * The mutator receives the current value (`$fallback` when the option does
     * not exist) and returns the new one, or self::KEEP to write nothing, or
     * self::DELETE to remove the option.
     *
     * @since 2.15.0
     *
     * @param string    $option   Option name.
     * @param callable  $mutator  function (mixed $current): mixed.
     * @param bool|null $autoload Passed to update_option().
     * @param mixed     $fallback  Value when the option does not exist.
     * @return mixed The value now stored, or $fallback after a delete.
     */
    public static function update_option(string $option, callable $mutator, ?bool $autoload = null, $fallback = false) {
        return self::locked(
            'option|' . $option,
            static function (bool $locked) use ($option, $mutator, $autoload, $fallback) {
                $current = $locked ? self::fresh_option($option, $fallback) : get_option($option, $fallback);
                $next    = $mutator($current);

                if (self::KEEP === $next) {
                    return $current;
                }

                if (self::DELETE === $next) {
                    delete_option($option);
                    return $fallback;
                }

                update_option($option, $next, $autoload);

                return $next;
            }
        );
    }

    /**
     * Add one to an integer option and return the new value.
     *
     * @since 2.15.0
     *
     * @param string    $option   Option name.
     * @param int       $floor    Value counted from when the option is missing or lower.
     * @param bool|null $autoload Passed to update_option().
     * @return int The new value.
     */
    public static function increment_option(string $option, int $floor = 0, ?bool $autoload = null): int {
        return (int) self::update_option(
            $option,
            static fn($current): int => max($floor, (int) $current) + 1,
            $autoload,
            $floor
        );
    }

    /**
     * Count one use against a transient-backed limit.
     *
     * Reading the count, comparing it with the limit and writing count + 1 is
     * done under one lock, so a burst cannot all read the same count and all
     * get through: the burst is exactly when a limiter has to count right.
     * The transient's lifetime restarts on every counted use, as it did before.
     *
     * @since 2.15.0
     *
     * @param string $transient Transient name.
     * @param int    $limit     Uses allowed while the transient lives.
     * @param int    $ttl       Transient lifetime in seconds.
     * @return bool True when this use is within the limit (and was counted).
     */
    public static function take(string $transient, int $limit, int $ttl): bool {
        return (bool) self::locked(
            'transient|' . $transient,
            static function (bool $locked) use ($transient, $limit, $ttl): bool {
                $used = (int) self::read_transient($transient, $locked);

                if ($used >= $limit) {
                    return false;
                }

                set_transient($transient, $used + 1, $ttl);

                return true;
            }
        );
    }

    /**
     * Claim single-flight work, recorded in a transient.
     *
     * Exactly one of several overlapping callers gets true. A get_transient()
     * check followed by set_transient() let every caller that checked before
     * the first one wrote go ahead. A holder that dies is released by the
     * transient expiring.
     *
     * Work that can outlive the transient passes an `$owner` token and
     * releases with {@see self::release_transient()}, which only deletes the
     * claim it still holds. Without one, a holder that overran the lifetime
     * deleted the claim a successor had taken since, and a third caller could
     * start alongside the successor (review on #965). Without an owner the
     * claim records the time it was taken, and is released with
     * delete_transient() as before.
     *
     * @since 2.15.0
     * @since 2.19.0 Takes an owner token.
     *
     * @param string $transient Transient name.
     * @param int    $ttl       Seconds before an unreleased claim lapses.
     * @param string $owner     Token identifying this holder; '' for none.
     * @return bool True when this caller holds the claim.
     */
    public static function claim_transient(string $transient, int $ttl, string $owner = ''): bool {
        return (bool) self::locked(
            'transient|' . $transient,
            static function (bool $locked) use ($transient, $ttl, $owner): bool {
                if (self::read_transient($transient, $locked)) {
                    return false;
                }

                set_transient($transient, '' !== $owner ? $owner : time(), $ttl);

                return true;
            }
        );
    }

    /**
     * Release a transient claim, but only while this owner still holds it.
     *
     * Checked and deleted under the same lock {@see self::claim_transient()}
     * takes, so a successor that claims in between is never released by the
     * holder it replaced.
     *
     * @since 2.19.0
     *
     * @param string $transient Transient name.
     * @param string $owner     The token the claim was taken with.
     * @return bool True when the claim was this owner's and is now released.
     */
    public static function release_transient(string $transient, string $owner): bool {
        if ('' === $owner) {
            return false;
        }

        return (bool) self::locked(
            'transient|' . $transient,
            static function (bool $locked) use ($transient, $owner): bool {
                $held = self::read_transient($transient, $locked);

                if (!is_string($held) || !hash_equals($held, $owner)) {
                    return false;
                }

                delete_transient($transient);

                return true;
            }
        );
    }

    /**
     * A token to claim single-flight work with.
     *
     * @since 2.19.0
     *
     * @return string 32 hex characters.
     */
    public static function owner_token(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Claim single-flight work, recorded in an option as the time it was taken.
     *
     * The option form, for a claim that must live in the options table even
     * when a persistent object cache is installed. A claim older than $ttl is
     * taken over: its holder died without releasing it. Release with
     * delete_option().
     *
     * @since 2.15.0
     *
     * @param string $option Option name.
     * @param int    $ttl    Seconds after which a claim is treated as abandoned.
     * @return bool True when this caller holds the claim.
     */
    public static function claim_option(string $option, int $ttl): bool {
        $claimed = false;

        self::update_option(
            $option,
            static function ($held) use (&$claimed, $ttl) {
                $held = (int) $held;

                if ($held > 0 && (time() - $held) <= $ttl) {
                    return self::KEEP;
                }

                $claimed = true;

                return time();
            },
            false,
            0
        );

        return $claimed;
    }

    /**
     * A transient's committed value: fresh from the store when $fresh, else
     * whatever get_transient() returns.
     *
     * Call with $fresh only while holding a lock; outside one a fresh read
     * buys nothing, because the value can change straight after it.
     *
     * @since 2.15.0
     *
     * @param string $transient Transient name.
     * @param bool   $fresh     Bypass this request's cached copy.
     * @return mixed The value, or false when missing or expired.
     */
    public static function read_transient(string $transient, bool $fresh) {
        if (!$fresh) {
            return get_transient($transient);
        }

        if (wp_using_ext_object_cache()) {
            // Transients live in the cache; force past the drop-in's runtime copy.
            return wp_cache_get($transient, 'transient', true);
        }

        $timeout = self::fresh_option('_transient_timeout_' . $transient, false);
        $value   = self::fresh_option('_transient_' . $transient, false);

        if (false !== $timeout && (int) $timeout < time()) {
            return false;
        }

        return $value;
    }

    /**
     * An option's committed value, read straight from the options table.
     *
     * Also brings this request's option caches in line with what it read, so
     * the update_option() that follows compares against the committed value:
     * core skips the write when the new value equals its cached old one, and
     * a stale cache could make it skip a write the database needs.
     *
     * @since 2.15.0
     *
     * @param string $option  Option name.
     * @param mixed  $fallback Value when the option does not exist.
     * @return mixed The value.
     */
    public static function fresh_option(string $option, $fallback = false) {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return get_option($option, $fallback);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the point is to bypass the cache.
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option),
            ARRAY_A
        );

        if (!is_array($row) || !array_key_exists('option_value', $row)) {
            self::sync_cache($option, null);
            return $fallback;
        }

        $raw = (string) $row['option_value'];
        self::sync_cache($option, $raw);

        return maybe_unserialize($raw);
    }

    /**
     * Make this request's option caches agree with a value just read.
     *
     * @param string      $option Option name.
     * @param string|null $raw    Stored (serialized) value, null when absent.
     * @return void
     */
    private static function sync_cache(string $option, ?string $raw): void {
        $alloptions = wp_load_alloptions();
        $notoptions = wp_cache_get('notoptions', 'options');
        $notoptions = is_array($notoptions) ? $notoptions : [];

        if (null === $raw) {
            if (isset($alloptions[$option])) {
                unset($alloptions[$option]);
                wp_cache_set('alloptions', $alloptions, 'options');
            }
            wp_cache_delete($option, 'options');
            $notoptions[$option] = true;
            wp_cache_set('notoptions', $notoptions, 'options');
            return;
        }

        if (isset($notoptions[$option])) {
            unset($notoptions[$option]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }

        if (isset($alloptions[$option])) {
            $alloptions[$option] = $raw;
            wp_cache_set('alloptions', $alloptions, 'options');
            return;
        }

        wp_cache_set($option, $raw, 'options');
    }

    /**
     * Take the named advisory lock (re-entrant within this request).
     *
     * @param string $name Lock name.
     * @return bool Whether the lock is held.
     */
    private static function lock(string $name): bool {
        global $wpdb;

        if (isset(self::$held[$name])) {
            ++self::$held[$name];
            return true;
        }

        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not cacheable data.
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name($name), self::LOCK_TIMEOUT));

        if ('1' !== (string) $got) {
            return false;
        }

        self::$held[$name] = 1;

        return true;
    }

    /**
     * Release a lock taken by lock(); only the outermost holder releases it.
     *
     * @param string $name Lock name.
     * @return void
     */
    private static function unlock(string $name): void {
        global $wpdb;

        if (!isset(self::$held[$name])) {
            return;
        }

        if (--self::$held[$name] > 0) {
            return;
        }

        unset(self::$held[$name]);

        if (isset($wpdb) && is_object($wpdb)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not cacheable data.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($name)));
        }
    }

    /**
     * Lock name inside MySQL's 64-character limit, unique per install: GET_LOCK
     * names are server-wide, and shared MySQL servers are the common case.
     *
     * @param string $name Logical name.
     * @return string
     */
    private static function lock_name(string $name): string {
        global $wpdb;

        $prefix = isset($wpdb) && is_object($wpdb) ? (string) $wpdb->prefix : '';

        return 'trk_ss_' . md5((defined('DB_NAME') ? (string) DB_NAME : '') . '|' . $prefix . '|' . $name);
    }
}
