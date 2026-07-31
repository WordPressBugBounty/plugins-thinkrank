<?php
/**
 * SEO Manager Interface
 *
 * Defines the contract for all SEO management classes in ThinkRank.
 * This interface ensures consistent patterns across all SEO functionality
 * including schema generation, social meta, robots meta, and site-wide settings.
 *
 * @package ThinkRank
 * @subpackage SEO\Interfaces
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Interfaces;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Manager Interface
 *
 * Universal interface for all SEO management classes providing consistent
 * CRUD operations, validation, and output generation across different contexts
 * (site-wide, per-post, per-page, etc.).
 *
 * @since 1.0.0
 */
interface SEO_Manager_Interface {

    /**
     * Get SEO settings for a specific context
     *
     * Retrieves SEO settings based on context type and optional context ID.
     * Context types include 'site', 'post', 'page', 'product', etc.
     * Context ID is null for site-wide settings, post ID for per-post settings.
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type ('site', 'post', 'page', 'product')
     * @param int|null $context_id   Optional. Context ID (null for site-wide, post ID for per-post)
     * @return array SEO settings array with standardized structure
     */
    public function get_settings(string $context_type, ?int $context_id = null): array;

    /**
     * Save SEO settings for a specific context
     *
     * Saves SEO settings with proper validation and sanitization.
     * Automatically handles context-specific logic and database operations.
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type ('site', 'post', 'page', 'product')
     * @param int|null $context_id   Optional. Context ID (null for site-wide, post ID for per-post)
     * @param array    $settings     Settings array to save
     * @return bool True on success, false on failure
     */
    public function save_settings(string $context_type, ?int $context_id, array $settings): bool;

    /**
     * Validate SEO settings
     *
     * Validates settings array according to context-specific rules.
     * Returns validation results with errors, warnings, and suggestions.
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results with 'valid', 'errors', 'warnings', 'suggestions' keys
     */
    public function validate_settings(array $settings): array;

    /**
     * Get output data for frontend rendering
     *
     * Generates the final output data for frontend meta tags, schema markup,
     * or other SEO elements based on context and settings.
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type ('site', 'post', 'page', 'product')
     * @param int|null $context_id   Optional. Context ID (null for site-wide, post ID for per-post)
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array;

    /**
     * Get supported context types
     *
     * Returns array of context types supported by this SEO manager.
     * Different managers may support different contexts.
     *
     * @since 1.0.0
     *
     * @return array Array of supported context types
     */
    public function get_supported_contexts(): array;

    /**
     * Get default settings for a context type
     *
     * Returns default settings structure for the specified context type.
     * Used for initialization and fallback values.
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array;

    /**
     * Delete settings for a specific context
     *
     * Removes all settings for the specified context.
     * Used for cleanup operations and reset functionality.
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type ('site', 'post', 'page', 'product')
     * @param int|null $context_id   Optional. Context ID (null for site-wide, post ID for per-post)
     * @return bool True on success, false on failure
     */
    public function delete_settings(string $context_type, ?int $context_id): bool;

    /**
     * Check if settings exist for a context
     *
     * Determines whether settings have been configured for the specified context.
     * Useful for conditional logic and fallback handling.
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type ('site', 'post', 'page', 'product')
     * @param int|null $context_id   Optional. Context ID (null for site-wide, post ID for per-post)
     * @return bool True if settings exist, false otherwise
     */
    public function has_settings(string $context_type, ?int $context_id): bool;

    /**
     * Get settings schema definition
     *
     * Returns the schema definition for settings validation and form generation.
     * Includes field types, validation rules, and UI configuration.
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array;

    /**
     * Bulk update settings
     *
     * Updates multiple settings efficiently in a single operation.
     * Useful for import/export and batch operations.
     *
     * @since 1.0.0
     *
     * @param array $bulk_settings Array of settings keyed by context_type:context_id
     * @return array Results array with success/failure status for each update
     */
    public function bulk_update_settings(array $bulk_settings): array;

    /**
     * Get settings history
     *
     * Returns revision history for settings changes.
     * Useful for audit trails and rollback functionality.
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type ('site', 'post', 'page', 'product')
     * @param int|null $context_id   Optional. Context ID (null for site-wide, post ID for per-post)
     * @param int      $limit        Optional. Number of revisions to return (default: 10)
     * @return array Array of settings revisions with timestamps and user info
     */
    public function get_settings_history(string $context_type, ?int $context_id, int $limit = 10): array;

    /**
     * Export settings
     *
     * Exports settings in a standardized format for backup or migration.
     * Includes metadata and version information.
     *
     * @since 1.0.0
     *
     * @param string   $context_type Optional. Context type to export (null for all)
     * @param int|null $context_id   Optional. Context ID to export (null for all in context_type)
     * @return array Exported settings with metadata
     */
    public function export_settings(?string $context_type = null, ?int $context_id = null): array;

    /**
     * Import settings
     *
     * Imports settings from exported data with validation and conflict resolution.
     * Supports partial imports and merge strategies.
     *
     * @since 1.0.0
     *
     * @param array $import_data Exported settings data
     * @param array $options     Import options (merge_strategy, validate, etc.)
     * @return array Import results with success/failure details
     */
    public function import_settings(array $import_data, array $options = []): array;
}
