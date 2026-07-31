<?php

/**
 * Settings Class
 * 
 * Handles plugin settings and configuration using WordPress options
 * 
 * @package ThinkRank\Core
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/** 
 * Settings Class
 * 
 * Single Responsibility: Manage plugin settings and configuration
 * Uses WordPress options for storage.
 * 
 * Use Settings::instance() to get the shared instance rather than
 * creating new instances — this ensures the internal cache is shared
 * across all consumers and avoids redundant get_option() calls.
 * 
 * @since 1.0.0
 */
class Settings {

    /**
     * Shared singleton instance
     * 
     * @var Settings|null
     */
    private static ?Settings $instance = null;

    /**
     * Get the shared Settings instance
     * 
     * Returns the instance registered in the main plugin's component
     * container, or creates a standalone instance if the plugin hasn't
     * loaded yet (e.g., during activation).
     * 
     * @since 1.10.0
     * @return Settings
     */
    public static function instance(): Settings {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Try to get from the main plugin container
        if (function_exists('thinkrank')) {
            $plugin = thinkrank();
            $component = $plugin->get_component('settings');
            if ($component instanceof self) {
                self::$instance = $component;
                return self::$instance;
            }
        }

        // Fallback: create standalone instance (during activation, etc.)
        self::$instance = new self();
        return self::$instance;
    }

    /**
     * Settings cache
     * 
     * @var array
     */
    private array $cache = [];

    /**
     * Default settings
     * 
     * @var array
     */
    private array $defaults = [
        // AI Settings
        'ai_provider' => 'openai',
        'openai_api_key' => '',
        'openai_model' => 'gpt-5-nano', // Default to GPT‑5‑nano
        'claude_api_key' => '',
        'claude_model' => 'claude-sonnet-5', // Recommended default (best speed/quality balance)
        'gemini_api_key' => '',
        'gemini_model' => 'gemini-2.5-flash',
        'openrouter_api_key' => '',
        'openrouter_model' => 'openai/gpt-4o-mini',
        'max_tokens' => 1000,
        'temperature' => 0.7,

        // Google API Keys (encrypted)
        'google_analytics_api_key' => '',
        'google_search_console_api_key' => '',
        'google_pagespeed_api_key' => '',

        // Google OAuth Tokens (encrypted)
        'google_access_token' => '',
        'google_refresh_token' => '',
        'google_token_expires_in' => 0,
        'google_token_created' => 0,
        'google_account_connected' => false,

        // Google Analytics Pro Settings
        'ga_analytics_account_id' => '',
        'ga_analytics_data_stream_id' => '',

        // Performance Settings
        'cache_duration' => 3600,
        'max_requests_per_minute' => 10,
        'enable_logging' => true,

        // Integration Settings
        'api_timeout' => 30,
        'enable_rate_limiting' => true,
        'auto_test_connections' => true,
        'retry_failed_requests' => true,

        // Social Platform Settings
        // Public IDs (not encrypted)
        'facebook_app_id' => '',
        'facebook_admins' => '',
        'youtube_channel_id' => '',
        'whatsapp_business_id' => '',
        // Verification codes (encrypted)
        'pinterest_site_verification' => '',
        'instagram_verification' => '',
        'tiktok_verification' => '',

        // SEO Settings
        'auto_optimize' => false,
        'seo_score_threshold' => 70,
        'enable_meta_generation' => true,
        'enable_schema_markup' => true,

        // Author Archives Settings
        'author_archives_enabled' => true,
        'author_archives_index' => true,
        'author_archives_show_empty' => false,
        'author_archives_title' => '%author_name% – %site_title% %page%',
        'author_archives_meta_desc' => 'Articles written by %author_name% on %site_title%',

        // UI Settings
        'show_welcome_message' => true,
        'dashboard_widgets' => ['seo_score', 'ai_usage', 'recent_briefs'],
        'editor_panel_position' => 'side',

        // Advanced Settings
        'debug_mode' => false,
        'retry_attempts' => 3,
        // Exposes the standalone Migration admin page for re-running SEO data
        // imports after setup. Hidden by default; opt-in for advanced/support use.
        'enable_migration_tools' => false,

        // Privacy Settings
        'data_retention_days' => 90,
        'anonymize_logs' => true,
        'share_usage_data' => false,

        // Integration Settings
        'google_analytics_id' => '',
        'search_console_property' => '',

        // GA4 Tracking Settings
        'ga4_measurement_id' => '',
        'ga4_auto_inject' => false,
        'ga4_anonymize_ip' => false,
        'ga4_exclude_admin' => false,
        'ga4_tracking_verified' => false,
        'ga4_last_verification' => '',

        // SEO Analytics Settings
        'seo_analytics_enabled' => false,
        'seo_analytics_setup_completed' => false,
        'seo_analytics_google_analytics_property_id' => '',
        'seo_analytics_enable_ai_insights' => true,
        'seo_analytics_enable_automated_alerts' => false,
        'seo_analytics_enable_predictive_analysis' => false,
        'seo_analytics_monitoring_frequency' => 3600,
        'seo_analytics_alert_thresholds' => [],
        'seo_analytics_report_schedule' => 'weekly',
        'seo_analytics_data_retention_days' => 90,
        'seo_analytics_cache_analytics_data' => true,

        // Uninstall Settings
        'keep_data_on_uninstall' => true,

        // MCP (Model Context Protocol) Settings
        'enable_mcp' => false,
    ];

    /**
     * Encrypted settings keys
     *
     * @var array
     */
    private array $encrypted_keys = [
        // AI API Keys
        'openai_api_key',
        'claude_api_key',
        'gemini_api_key',
        'openrouter_api_key',
        // Google API Keys
        'google_analytics_api_key',
        'google_search_console_api_key',
        'google_pagespeed_api_key',
        'google_access_token',
        'google_refresh_token',
        // Social Platform Verification Codes (sensitive)
        'pinterest_site_verification',
        'instagram_verification',
        'tiktok_verification',
    ];

    /**
     * Initialize settings
     * 
     * @return void
     */
    public function init(): void {
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Register WordPress settings
     * 
     * @return void
     */
    public function register_settings(): void {
        register_setting('thinkrank_settings', 'thinkrank_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => $this->defaults,
        ]);
    }

    /**
     * Get setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value
     * @param int $user_id User ID (0 for global, >0 for user-specific)
     * @return mixed Setting value
     */
    public function get(string $key, $default = null, int $user_id = 0) {
        // Check cache first
        $cache_key = $user_id > 0 ? "user_{$user_id}_{$key}" : $key;

        if (isset($this->cache[$cache_key])) {
            return $this->maybe_decrypt($key, $this->cache[$cache_key]);
        }

        // Load from WordPress options or user meta
        if ($user_id > 0) {
            $value = get_user_meta($user_id, "thinkrank_{$key}", true);
        } else {
            $value = get_option("thinkrank_{$key}", null);
        }



        // Handle boolean settings with special logic for WordPress storage quirks
        if (isset($this->defaults[$key]) && is_bool($this->defaults[$key])) {
            // Convert string representations back to booleans
            if ('1' === $value || 1 === $value || true === $value || 'true' === $value) {
                $value = true;
            } elseif ('0' === $value || 0 === $value || false === $value || 'false' === $value) {
                $value = false;
            } elseif (null === $value || '' === $value) {
                // For boolean settings, empty string could mean false was stored
                // null means option doesn't exist, empty string means it was stored as empty
                if (null !== $value && $value === '') {
                    // Empty string exists in DB, this likely means false was stored
                    $value = false;
                } else {
                    // Option doesn't exist, use default
                    $value = $default ?? $this->defaults[$key];
                }
            }
        } else {
            // Non-boolean settings: use default if not found
            if (null === $value || '' === $value) {
                $value = $default ?? ($this->defaults[$key] ?? null);
            }
        }

        // Cache the value
        $this->cache[$cache_key] = $value;

        return $this->maybe_decrypt($key, $value);
    }

    /**
     * Set setting value
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @param int $user_id User ID (0 for global, >0 for user-specific)
     * @return bool Success status
     */
    public function set(string $key, $value, int $user_id = 0): bool {
        // Check if key exists in defaults
        if (!array_key_exists($key, $this->defaults)) {
            return false;
        }



        // Encrypt if needed
        $encrypted_value = $this->maybe_encrypt($key, $value);

        // Save to WordPress options or user meta
        if ($user_id > 0) {
            $result = update_user_meta($user_id, "thinkrank_{$key}", $encrypted_value);
        } else {
            $option_name = "thinkrank_{$key}";
            $is_sensitive = in_array($key, $this->encrypted_keys, true);

            // Determine if option exists
            $existing = get_option($option_name, '__tr_not_set__');
            if ($existing === '__tr_not_set__') {
                // First-time save: set autoload=no for sensitive options
                $autoload = $is_sensitive ? 'no' : 'yes';
                $result = add_option($option_name, $encrypted_value, '', $autoload);
            } else {
                // Update existing option; enforce autoload=no for sensitive options
                if ($is_sensitive) {
                    $result = update_option($option_name, $encrypted_value, 'no');
                } else {
                    $result = update_option($option_name, $encrypted_value);
                }
            }

            // Verify value actually saved (update_option returns false when unchanged)
            $saved_value = get_option($option_name, 'NOT_FOUND');
            $values_match = ($saved_value === $encrypted_value) ||
                ($saved_value == $encrypted_value && $encrypted_value !== 'NOT_FOUND');

            // Consider it successful if the value was saved correctly
            $result = $result || $values_match;
        }

        // Update cache
        if ($result) {
            $cache_key = $user_id > 0 ? "user_{$user_id}_{$key}" : $key;
            $this->cache[$cache_key] = $encrypted_value;

            // Invalidate bulk cache when individual setting changes
            $bulk_cache_key = $user_id > 0 ? "bulk_user_{$user_id}" : 'bulk_global';
            wp_cache_delete($bulk_cache_key, 'thinkrank_settings');
        }

        return $result !== false;
    }

    /**
     * Delete setting
     * 
     * @param string $key Setting key
     * @param int $user_id User ID (0 for global, >0 for user-specific)
     * @return bool Success status
     */
    public function delete(string $key, int $user_id = 0): bool {
        // Remove from WordPress options or user meta
        if ($user_id > 0) {
            $result = delete_user_meta($user_id, "thinkrank_{$key}");
        } else {
            $result = delete_option("thinkrank_{$key}");
        }

        // Remove from cache
        if ($result) {
            $cache_key = $user_id > 0 ? "user_{$user_id}_{$key}" : $key;
            unset($this->cache[$cache_key]);

            // Also invalidate the bulk cache written by get_all(), or it serves
            // stale values for up to 5 minutes after a delete.
            $bulk_cache_key = $user_id > 0 ? "bulk_user_{$user_id}" : 'bulk_global';
            wp_cache_delete($bulk_cache_key, 'thinkrank_settings');
        }

        return $result;
    }

    /**
     * Get all settings (optimized with bulk caching)
     *
     * @param int $user_id User ID (0 for global)
     * @return array All settings
     */
    public function get_all(int $user_id = 0): array {
        // Check bulk cache first
        $bulk_cache_key = $user_id > 0 ? "bulk_user_{$user_id}" : 'bulk_global';

        $cached_settings = wp_cache_get($bulk_cache_key, 'thinkrank_settings');
        if ($cached_settings !== false) {
            return $cached_settings;
        }

        $settings = [];

        foreach (array_keys($this->defaults) as $key) {
            $settings[$key] = $this->get($key, null, $user_id);
        }

        // Cache all settings for 5 minutes
        wp_cache_set($bulk_cache_key, $settings, 'thinkrank_settings', 300);

        return $settings;
    }

    /**
     * Reset settings to defaults
     * 
     * @param int $user_id User ID (0 for global)
     * @return bool Success status
     */
    public function reset(int $user_id = 0): bool {
        $success = true;

        foreach (array_keys($this->defaults) as $key) {
            if (!$this->delete($key, $user_id)) {
                $success = false;
            }
        }

        // Clear cache — both the instance array and the bulk object cache
        // written by get_all(), which would otherwise return stale pre-reset
        // values for up to 5 minutes.
        $this->cache = [];
        $bulk_cache_key = $user_id > 0 ? "bulk_user_{$user_id}" : 'bulk_global';
        wp_cache_delete($bulk_cache_key, 'thinkrank_settings');

        return $success;
    }

    /**
     * Sanitize settings
     * 
     * @param array $settings Settings array
     * @return array Sanitized settings
     */
    public function sanitize_settings(array $settings): array {
        $sanitized = [];

        foreach ($settings as $key => $value) {
            $sanitized[$key] = $this->sanitize_setting($key, $value);
        }

        return $sanitized;
    }

    /**
     * Sanitize individual setting
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return mixed Sanitized value
     */
    private function sanitize_setting(string $key, $value) {
        switch ($key) {
            case 'openai_api_key':
            case 'claude_api_key':
            case 'gemini_api_key':
            case 'openrouter_api_key':
                return sanitize_text_field($value);

            case 'ai_provider':
                return sanitize_key($value);

            case 'openai_model':
            case 'claude_model':
            case 'gemini_model':
            case 'openrouter_model':
                // Model ids may contain dots and slashes (e.g. "gpt-4.1" or
                // "openai/gpt-4o-mini") and users can enter custom models, so
                // sanitize_key() would corrupt them — use text-field sanitizing.
                return sanitize_text_field($value);

            case 'max_tokens':
            case 'cache_duration':
            case 'max_requests_per_minute':
            case 'seo_score_threshold':
            case 'api_timeout':
            case 'retry_attempts':
            case 'data_retention_days':
            case 'monitoring_frequency':
                return absint($value);

            case 'temperature':
                return (float) $value;

            case 'dashboard_widgets':
            case 'seo_analytics_alert_thresholds':
                return is_array($value) ? array_map('sanitize_key', $value) : [];

            case 'google_analytics_property_id':
            case 'seo_analytics_google_analytics_property_id':
            case 'seo_analytics_report_schedule':
                return sanitize_text_field($value);

            case 'robots_txt_content':
                // Multi-line content — sanitize_text_field() collapses newlines
                // and would flatten the whole file onto a single line.
                return sanitize_textarea_field($value);

            default:
                if (is_bool($value)) {
                    return (bool) $value;
                } elseif (is_string($value)) {
                    return sanitize_text_field($value);
                } elseif (is_array($value)) {
                    return array_map('sanitize_text_field', $value);
                }
                return $value;
        }
    }

    /**
     * Marker prefix for values encrypted with the libsodium scheme below.
     */
    private const ENC_PREFIX = 'trenc:v1:';

    /**
     * Encrypt secret settings (API keys, OAuth tokens) at rest using libsodium
     * authenticated encryption. Non-secret keys and environments without sodium
     * fall back to plaintext so behaviour stays stable.
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return mixed Encrypted payload (string) or original value
     */
    private function maybe_encrypt(string $key, $value) {
        if (!in_array($key, $this->encrypted_keys, true) || !is_string($value) || '' === $value) {
            return $value;
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            return $value; // sodium unavailable — store as-is
        }

        $enc_key = $this->encryption_key();
        if ('' === $enc_key) {
            return $value;
        }

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($value, $nonce, $enc_key);
            return self::ENC_PREFIX . base64_encode($nonce . $cipher);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Decrypt a value previously encrypted by maybe_encrypt. Values without the
     * marker prefix are legacy plaintext and returned untouched (backward compat).
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return mixed Decrypted or original value
     */
    private function maybe_decrypt(string $key, $value) {
        if (!is_string($value) || strncmp($value, self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0) {
            return $value; // legacy plaintext or non-string
        }
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return $value;
        }

        $enc_key = $this->encryption_key();
        if ('' === $enc_key) {
            return $value;
        }

        $decoded = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if (false === $decoded || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return $value;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $enc_key);

        if (false === $plain) {
            // We reach here only when sodium is available and a key was
            // derivable, so this is a genuine failure: the auth salt changed
            // (config rotated, or the site was migrated without wp-config) or
            // the row is corrupt. The value is unrecoverable either way.
            //
            // Returning the ciphertext would send it upstream as a bearer
            // token or API key, producing an opaque 401 far from the cause.
            // Return empty so callers see "no credential" and can prompt for
            // a reconnect instead.
            return '';
        }

        return $plain;
    }

    /**
     * Derive a 32-byte encryption key from the site's auth salt.
     *
     * @return string Raw 32-byte key, or '' if salts are unavailable.
     */
    private function encryption_key(): string {
        if (!function_exists('wp_salt')) {
            return '';
        }
        // 32 raw bytes from a site-specific secret — SHA-256 output length
        // matches SODIUM_CRYPTO_SECRETBOX_KEYBYTES.
        return hash('sha256', 'thinkrank-settings|' . wp_salt('auth'), true);
    }
}
