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
     * Canonical default AI model per provider.
     *
     * Single source of truth. Every call site that needs a provider's default
     * model — the $defaults array below, the AI client constructors, the manager
     * fallbacks, the content-brief generator and the usage-analytics endpoint —
     * MUST reference these constants instead of repeating the literal, so the
     * defaults can never silently drift out of sync (see issue #273).
     *
     * @since 1.28.0
     */
    const DEFAULT_OPENAI_MODEL     = 'gpt-5-nano';
    const DEFAULT_CLAUDE_MODEL     = 'claude-sonnet-5';
    const DEFAULT_GEMINI_MODEL     = 'gemini-3.5-flash';
    const DEFAULT_OPENROUTER_MODEL = 'openai/gpt-4o-mini';

    /**
     * Canonical default author-archive templates.
     *
     * Same single-source-of-truth rule as the model constants above: the
     * defaults array, the REST endpoint, the get-settings ability and
     * Author_Archives_Manager all read these instead of repeating the literal,
     * which had already drifted — the title default hardcoded an en dash while
     * the feature resolves %separator% from Site Identity (#318).
     *
     * @since 1.29.1
     */
    const DEFAULT_AUTHOR_ARCHIVES_TITLE     = '%author_name% %separator% %site_title% %page%';
    const DEFAULT_AUTHOR_ARCHIVES_META_DESC = 'Articles written by %author_name% on %site_title%';

    /**
     * String settings where a stored empty string is a real value, not "unset".
     *
     * get() normally treats '' the same as a missing option and returns the
     * default, which is right for most keys — a blank API key or model name is
     * never what the user meant. For these template fields it is the opposite:
     * clearing the box means "render no template", and the consumers already
     * branch on an empty value. Without the opt-out the save appeared to
     * succeed and the default reappeared on the next request (#316).
     *
     * @since 1.29.1
     * @var string[]
     */
    private const EMPTY_IS_A_VALUE = [
        'author_archives_title',
        'author_archives_meta_desc',
    ];

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
        'openai_model' => self::DEFAULT_OPENAI_MODEL,
        'claude_api_key' => '',
        'claude_model' => self::DEFAULT_CLAUDE_MODEL, // Recommended default (best speed/quality balance)
        'gemini_api_key' => '',
        'gemini_model' => self::DEFAULT_GEMINI_MODEL,
        'openrouter_api_key' => '',
        'openrouter_model' => self::DEFAULT_OPENROUTER_MODEL,
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

        // Google Analytics Pro Settings. Read/written by thinkrank-pro's
        // GoogleAnalyticsSettings.js through the settings-management endpoint —
        // no consumer exists in THIS repo, so don't dead-key these.
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

        // Auto AI Optimization (on-publish metadata fill; #248 P1)
        'auto_ai_meta_enabled' => false,
        'auto_ai_meta_post_types' => ['post'],

        // Brand Visibility v2. The brand profile drives question generation
        // and mention detection; per-platform keys let this feature query
        // several assistants without changing the site-wide AI provider.
        'bv_brand_name' => '',
        'bv_variants' => [],
        'bv_location' => '',
        'bv_category' => '',
        'bv_description' => '',
        'bv_competitors' => [],
        'bv_queries' => [],
        'bv_platforms' => ['chatgpt'],
        'bv_samples' => 1,
        'bv_key_chatgpt' => '',
        'bv_key_gemini' => '',
        'bv_key_claude' => '',
        'bv_key_perplexity' => '',
        // Empty = use the platform's default model (see Brand_Visibility_Providers).
        'bv_model_chatgpt' => '',
        'bv_model_gemini' => '',
        'bv_model_claude' => '',
        'bv_model_perplexity' => '',

        // AI Brand Visibility (BYO-key checks; #248 P1)
        'brand_visibility_queries' => [],

        // Author Archives Settings
        'author_archives_enabled' => true,
        'author_archives_index' => true,
        'author_archives_show_empty' => false,
        'author_archives_title' => self::DEFAULT_AUTHOR_ARCHIVES_TITLE,
        'author_archives_meta_desc' => self::DEFAULT_AUTHOR_ARCHIVES_META_DESC,

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
     * @param mixed $fallback Default value
     * @param int $user_id User ID (0 for global, >0 for user-specific)
     * @return mixed Setting value
     */
    public function get(string $key, $fallback = null, int $user_id = 0) {
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
                    $value = $fallback ?? $this->defaults[$key];
                }
            }
        } else {
            // Non-boolean settings: use default if not found.
            //
            // get_option() above is called with a null default, so null means
            // "no row" while '' means a value was deliberately stored. For most
            // keys we collapse the two — an empty string is treated as unset so
            // the documented default applies. Keys in EMPTY_IS_A_VALUE opt out:
            // there, clearing the field is a real choice the consumer honours,
            // and folding it back into the default made the save look like it
            // silently failed (#316).
            $empty_is_unset = !in_array($key, self::EMPTY_IS_A_VALUE, true);

            if (null === $value || ($empty_is_unset && '' === $value)) {
                $value = $fallback ?? ($this->defaults[$key] ?? null);
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
            // The loose branch is load-bearing: get_option() returns the stored
            // STRING ('1', '0', '30') while $encrypted_value may be the original
            // bool/int. On an unchanged re-save update_option() returns false, so
            // this comparison is the only thing that marks the save successful —
            // strict-only here made every unchanged-boolean re-save report failure.
            $values_match = ($saved_value === $encrypted_value) ||
                ($saved_value == $encrypted_value && $encrypted_value !== 'NOT_FOUND'); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- intentional type-tolerant verify, see above.

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
        // Only keys that are declared array-typed may receive an array. For a
        // scalar key an array/object value is malformed input, and the scalar
        // sanitizers below would fatal on it, so fall back to the declared
        // default instead of letting the bad value through.
        if (is_array($value) || is_object($value)) {
            if (!is_array($this->defaults[$key] ?? null)) {
                return $this->defaults[$key] ?? '';
            }
            $value = (array) $value;
        }

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
                return is_array($value) ? array_map('sanitize_key', $value) : [];

            case 'seo_analytics_alert_thresholds':
                // A threshold_name => value map where the values are numbers
                // (e.g. traffic_drop_percentage => 20) as well as strings, so
                // sanitize each value by its own type rather than forcing every
                // one through sanitize_key() — that turned ints into strings.
                if (!is_array($value)) {
                    return [];
                }
                $thresholds = [];
                foreach ($value as $threshold_key => $threshold_value) {
                    if (is_array($threshold_value)) {
                        continue;
                    }
                    $thresholds[sanitize_key($threshold_key)] = is_numeric($threshold_value)
                        ? $threshold_value + 0
                        : sanitize_text_field((string) $threshold_value);
                }
                return $thresholds;

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
                    // Flat list/map of scalars; nested members are dropped rather
                    // than passed to a string sanitizer that would fatal on them.
                    $sanitized = [];
                    foreach ($value as $item_key => $item) {
                        if (is_array($item) || is_object($item)) {
                            continue;
                        }
                        $sanitized[is_string($item_key) ? sanitize_key($item_key) : $item_key] =
                            sanitize_text_field((string) $item);
                    }
                    return $sanitized;
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
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for a sodium ciphertext, not obfuscation.
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

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes our own ciphertext envelope; strict mode is on.
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
