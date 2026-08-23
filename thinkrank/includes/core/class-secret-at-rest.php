<?php
/**
 * Encrypt-at-rest for plugin secrets.
 *
 * The scheme Settings has used for API keys and OAuth tokens since 1.20 —
 * libsodium `secretbox` under a key derived from the site's auth salt, marked
 * by a `trenc:v1:` prefix — lifted out so anything else holding a credential
 * can use it. Settings delegates here, so a value written by one reads back
 * through the other.
 *
 * This protects a leaked database dump: a backup, a shared-host read, another
 * plugin's SQL injection. It does not protect a leaked server, because the key
 * comes from wp-config.php.
 *
 * @package ThinkRank
 * @subpackage Core
 * @since 2.0.1
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encrypt-at-rest helper.
 *
 * @since 2.0.1
 */
class Secret_At_Rest {

    /**
     * Marks a value as encrypted by this class.
     *
     * @var string
     */
    public const PREFIX = 'trenc:v1:';

    /**
     * Encrypt a secret for storage.
     *
     * Falls back to the plaintext value where sodium is unavailable or no salt
     * can be derived, so behaviour stays stable on a host that cannot encrypt
     * rather than silently storing nothing.
     *
     * @since 2.0.1
     *
     * @param string $value Raw secret.
     * @return string Ciphertext envelope, or the value unchanged.
     */
    public static function encrypt(string $value): string {
        if ('' === $value || !function_exists('sodium_crypto_secretbox')) {
            return $value;
        }

        $key = self::key();

        if ('' === $key) {
            return $value;
        }

        try {
            $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($value, $nonce, $key);

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for a sodium ciphertext, not obfuscation.
            return self::PREFIX . base64_encode($nonce . $cipher);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Decrypt a value written by encrypt().
     *
     * A value without the marker is legacy plaintext and returned untouched.
     * A value that carries the marker and will not open is unrecoverable — the
     * auth salt changed, or the row is corrupt — and returns '' rather than the
     * ciphertext, which would otherwise be sent upstream as a credential and
     * produce an opaque 401 far from the cause.
     *
     * @since 2.0.1
     *
     * @param string $value Stored value.
     * @return string Plaintext, the original value, or ''.
     */
    public static function decrypt(string $value): string {
        if (0 !== strncmp($value, self::PREFIX, strlen(self::PREFIX))) {
            return $value;
        }

        if (!function_exists('sodium_crypto_secretbox_open')) {
            return $value;
        }

        $key = self::key();

        if ('' === $key) {
            return $value;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes our own ciphertext envelope; strict mode is on.
        $decoded = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if (false === $decoded || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return $value;
        }

        $nonce  = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        return false === $plain ? '' : $plain;
    }

    /**
     * Whether a stored value is one of ours.
     *
     * @since 2.0.1
     *
     * @param string $value Stored value.
     * @return bool
     */
    public static function is_encrypted(string $value): bool {
        return 0 === strncmp($value, self::PREFIX, strlen(self::PREFIX));
    }

    /**
     * Derive the 32-byte key from the site's auth salt.
     *
     * @since 2.0.1
     *
     * @return string Raw 32-byte key, or '' when salts are unavailable.
     */
    private static function key(): string {
        if (!function_exists('wp_salt')) {
            return '';
        }

        // SHA-256 output length matches SODIUM_CRYPTO_SECRETBOX_KEYBYTES.
        // The literal is the one Settings has always used, so values written
        // before this class existed still open.
        return hash('sha256', 'thinkrank-settings|' . wp_salt('auth'), true);
    }
}
