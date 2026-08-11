<?php

/**
 * Safe Unserializer
 *
 * Object-injection-safe replacement for maybe_unserialize() on foreign data.
 *
 * @package ThinkRank\Admin\Importers
 * @since 1.29.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safe Unserializer Class
 *
 * The importers read rows written by other SEO plugins — their options, their
 * meta, their own tables. maybe_unserialize() on that data will happily
 * instantiate objects, which is the entry point for a POP-gadget chain if the
 * source row was ever tampered with. Every importer deserialization goes
 * through here instead, with `allowed_classes => false` so no object can be
 * constructed: an object payload comes back as __PHP_Incomplete_Class and is
 * rejected rather than woken up.
 *
 * @since 1.29.0
 */
class Safe_Unserializer {

    /**
     * Maximum nesting depth walked when scanning a decoded payload for objects.
     *
     * Real importer rows (robots directives, schema blocks, settings maps) are
     * shallow; anything past this is treated as untrusted and rejected.
     */
    private const MAX_DEPTH = 10;

    /**
     * Unserialize a value from foreign data without allowing objects.
     *
     * Non-strings and non-serialized strings are returned unchanged, matching
     * maybe_unserialize()'s contract. A serialized payload that fails to decode
     * — or that decodes to an object — yields $fallback.
     *
     * @since 1.29.0
     *
     * @param mixed $value    Raw value read from the source plugin.
     * @param mixed $fallback Optional. Value to return when the payload is
     *                        undecodable or contains an object. Default null.
     * @return mixed Unserialized value, the original value, or $fallback.
     */
    public static function unserialize($value, $fallback = null) {
        if (!is_string($value) || !is_serialized($value)) {
            return $value;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes => false is exactly the hardening this helper exists for; the @ suppresses the notice unserialize() emits on a malformed payload, which is a normal reject-path here.
        $decoded = @unserialize($value, ['allowed_classes' => false]);

        if (false === $decoded) {
            // Distinguish a genuine serialized false from a decode failure.
            return 'b:0;' === $value ? false : $fallback;
        }

        return self::contains_object($decoded) ? $fallback : $decoded;
    }

    /**
     * Unserialize and require an array result.
     *
     * Convenience for the call sites that only ever want an array shape (schema
     * blocks, robots directives, settings maps) and should skip anything else.
     *
     * @since 1.29.0
     *
     * @param mixed $value Raw value read from the source plugin.
     * @return array Decoded array, or an empty array when the value isn't one.
     */
    public static function to_array($value): array {
        $decoded = self::unserialize($value, null);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether a decoded payload contains an object at any depth.
     *
     * With allowed_classes => false any object arrives as
     * __PHP_Incomplete_Class; treat that as untrusted and drop it.
     *
     * @param mixed $value Decoded value.
     * @param int   $depth Current recursion depth.
     * @return bool True when an object is present.
     */
    private static function contains_object($value, int $depth = 0): bool {
        if ($depth > self::MAX_DEPTH) {
            // Refuse rather than walk an absurdly nested payload. A genuine but
            // deep import row is dropped here too, so leave a breadcrumb under
            // debug — otherwise the data silently vanishes with no explanation.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic only, WP_DEBUG-gated.
                error_log(sprintf('[ThinkRank] Safe_Unserializer: payload exceeded max depth of %d and was rejected.', self::MAX_DEPTH));
            }
            return true;
        }

        if (is_object($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains_object($item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
