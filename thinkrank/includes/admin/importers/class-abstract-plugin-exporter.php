<?php

/**
 * Abstract Plugin Exporter
 *
 * Base class for all plugin-specific exporters. Provides shared orchestration
 * logic for reading source data, normalizing it, and writing to wp_options
 * via Snapshot_Store.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract Plugin Exporter Class
 *
 * @since 2.0.0
 */
abstract class Abstract_Plugin_Exporter {

    /**
     * Plugin slug identifier (e.g., 'yoast')
     *
     * @var string
     */
    protected string $plugin_slug;

    /**
     * Human-readable plugin name
     *
     * @var string
     */
    protected string $plugin_name;

    /**
     * Plugin file path for detection
     *
     * @var string
     */
    protected string $plugin_file;

    /**
     * Meta key prefix used by the source plugin
     *
     * @var string
     */
    protected string $meta_key_prefix;

    /**
     * WordPress option keys used by the source plugin for settings
     *
     * @var array
     */
    protected array $option_keys = [];

    /**
     * Number of records per chunk
     *
     * @var int
     */
    protected int $chunk_size = 100;

    /**
     * Raw number of rows the current page's paginated query returned, before any
     * per-record filtering. Page methods that skip records (e.g. users with no
     * migratable title/description) set this via the id helpers so export_chunk()
     * can decide has_more from the fetched-row count, not the emitted count.
     * Null means the page method didn't report one (1:1 methods) — fall back to
     * the emitted count.
     *
     * @var int|null
     */
    protected ?int $last_page_row_count = null;

    /**
     * Ordered list of data types to export
     */
    private const EXPORT_TYPES = ['postmeta', 'termmeta', 'usermeta', 'redirections', '404_logs', 'settings'];

    /**
     * Detect whether this plugin's data exists in the database
     *
     * @return bool True if source data is detected
     */
    abstract public function detect(): bool;

    /**
     * Get available data types with their record counts
     *
     * @return array Associative array of type => count
     */
    abstract public function get_available_types(): array;

    /**
     * Export a page of post meta data
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of normalized records
     */
    abstract protected function export_postmeta_page(int $page): array;

    /**
     * Export a page of term meta data
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of normalized records
     */
    abstract protected function export_termmeta_page(int $page): array;

    /**
     * Export a page of user meta data
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of normalized records
     */
    abstract protected function export_usermeta_page(int $page): array;

    /**
     * Export global settings
     *
     * @return array Array with single settings record
     */
    abstract protected function export_settings(): array;

    /**
     * Export a page of redirections
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of normalized records
     */
    abstract protected function export_redirections_page(int $page): array;

    /**
     * Export a page of logged 404 hits.
     *
     * Concrete, not abstract: only source plugins that ship a 404 monitor have
     * anything to hand over, so the default is "nothing to export" and the
     * exporters that do (Rank Math) override it.
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of normalized records
     */
    protected function export_404_logs_page(int $page): array {
        return [];
    }

    /**
     * Capture the source plugin's Role Manager assignments: role slug => the
     * capabilities that role holds whose name starts with $prefix.
     *
     * Shared by every exporter because role capabilities live on the roles
     * themselves (`wp_user_roles`), not in any of the plugin's own options — so
     * an exporter's raw option capture never covers them, whichever plugin it is.
     *
     * The administrator is skipped: ThinkRank's Capability_Manager never
     * modifies it (it always passes via `manage_options`), so carrying its caps
     * over would be meaningless.
     *
     * @param string $prefix Source capability prefix, e.g. 'rank_math_' or 'wpseo_'
     * @return array<string,string[]> Role slug => granted capabilities
     */
    protected function extract_role_capabilities(string $prefix): array {
        if (!function_exists('wp_roles') || $prefix === '') {
            return [];
        }

        $captured = [];
        foreach (wp_roles()->roles as $slug => $role) {
            if ($slug === 'administrator' || empty($role['capabilities'])) {
                continue;
            }

            $caps = [];
            foreach ($role['capabilities'] as $cap => $granted) {
                // Roles store revoked caps as `false`; only carry over grants.
                if ($granted && strpos((string) $cap, $prefix) === 0) {
                    $caps[] = (string) $cap;
                }
            }

            if (!empty($caps)) {
                sort($caps);
                $captured[(string) $slug] = $caps;
            }
        }

        return $captured;
    }

    /**
     * Convert plugin-specific template variables to literal values
     *
     * Accepts mixed because the input is another plugin's stored data, over
     * which we have no schema guarantees. Rank Math in particular can hold
     * booleans inside its options arrays where a template string is expected,
     * and the `?? ''` at the call sites only guards against a MISSING key —
     * a present-but-boolean value sailed straight into a string-typed
     * parameter and fataled the whole migration at the snapshot step.
     * Implementations MUST start with stringify_template_value().
     *
     * @param mixed $value Value potentially containing template variables
     * @param int|null $post_id Post ID for context-specific variables
     * @return string Converted string
     */
    abstract protected function convert_template_variables(mixed $value, ?int $post_id = null): string;

    /**
     * Coerce a foreign settings/meta value into a template string.
     *
     * Strings pass through; ints and floats are kept as their string form (a
     * purely numeric title is odd but meaningful); everything else — booleans,
     * arrays, objects, null — has no sensible reading as a template, so it
     * becomes '', which downstream already treats as "not set" and replaces
     * with defaults. Dropping garbage beats failing the migration over it.
     *
     * @param mixed $value Raw value from the source plugin's storage.
     * @return string Usable template string, possibly ''.
     */
    final protected function stringify_template_value(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Export a chunk of data and write to snapshot
     *
     * This is the main orchestration method. It calls the appropriate
     * export_*_page() method, writes the chunk via Snapshot_Store, and
     * updates the manifest.
     *
     * @param string $type Data type to export
     * @param int $page Page number (1-indexed)
     * @return array Result with status, has_more, page, total, exported
     */
    public function export_chunk(string $type, int $page): array {
        // Reset before the page method runs; it (or its id helper) records the
        // raw fetched-row count here.
        $this->last_page_row_count = null;

        $records = match ($type) {
            'postmeta'     => $this->export_postmeta_page($page),
            'termmeta'     => $this->export_termmeta_page($page),
            'usermeta'     => $this->export_usermeta_page($page),
            'settings'     => $this->export_settings(),
            'redirections' => $this->export_redirections_page($page),
            '404_logs'     => $this->export_404_logs_page($page),
            default        => [],
        };

        $exported_count = count($records);

        // Write chunk to snapshot store
        if ($exported_count > 0) {
            Snapshot_Store::write_chunk($this->plugin_slug, $type, $page, $records);
        }

        // Determine if there are more pages from the number of rows the paginated
        // query returned, NOT the emitted count. A page method may fetch a full
        // chunk_size of rows but emit fewer after filtering (e.g. users without a
        // migratable title/description); keying has_more off the emitted count
        // would halt pagination early and silently skip later pages. Fall back to
        // the emitted count for 1:1 page methods that don't report a row count.
        $fetched_count = $this->last_page_row_count ?? $exported_count;
        $has_more = $fetched_count >= $this->chunk_size && $type !== 'settings';

        // Get total count for this type
        $types = $this->get_available_types();
        $total = $types[$type] ?? 0;

        // Update manifest
        $this->update_manifest($type, $page, $exported_count, $has_more, $total);

        return [
            'status'   => $has_more ? 'processing' : 'complete',
            'message'  => sprintf(
                'Exported %d %s records (page %d)',
                $exported_count,
                $type,
                $page
            ),
            'has_more' => $has_more,
            'page'     => $page,
            'total'    => $total,
            'exported' => $exported_count,
        ];
    }

    /**
     * Update the snapshot manifest after writing a chunk
     *
     * @param string $type Data type
     * @param int $page Current page
     * @param int $count Records in this chunk
     * @param bool $has_more Whether more pages remain
     * @param int $total Total records for this type
     * @return void
     */
    private function update_manifest(string $type, int $page, int $count, bool $has_more, int $total): void {
        $manifest = Snapshot_Store::get_manifest($this->plugin_slug) ?? [
            'plugin'       => $this->plugin_slug,
            'plugin_name'  => $this->plugin_name,
            'exported_at'  => gmdate('c'),
            'version'      => '1.0',
            'types'        => [],
            'status'       => 'exporting',
            'last_migrated' => null,
            'migration_version' => null,
        ];

        // Update type info
        if (!isset($manifest['types'][$type])) {
            $manifest['types'][$type] = [
                'total_records' => $total,
                'total_chunks'  => 0,
            ];
        }

        $manifest['types'][$type]['total_chunks'] = $page;
        $manifest['types'][$type]['total_records'] = $total;
        $manifest['status'] = 'exporting';
        $manifest['exported_at'] = gmdate('c');

        Snapshot_Store::write_manifest($this->plugin_slug, $manifest);
    }

    /**
     * Mark the export as complete in the manifest
     *
     * Called by the controller after all types have been exported.
     *
     * @return void
     */
    public function finalize_export(): void {
        $manifest = Snapshot_Store::get_manifest($this->plugin_slug);
        if ($manifest) {
            $manifest['status'] = 'complete';
            $manifest['exported_at'] = gmdate('c');
            Snapshot_Store::write_manifest($this->plugin_slug, $manifest);
        }
    }

    /**
     * Normalize robots directives from any plugin format to standard 0/1 values
     *
     * Handles:
     * - Integer/string 1/0 (Yoast noindex/nofollow)
     * - Serialized array containing 'noindex'/'nofollow' strings (Rank Math)
     * - Boolean true/false (AIOSEO)
     * - String 'yes' for noindex (SEOPress inverted logic — caller must handle inversion)
     *
     * @param mixed $noindex_value Raw noindex value from source
     * @param mixed $nofollow_value Raw nofollow value from source
     * @return array ['noindex' => 0|1, 'nofollow' => 0|1]
     */
    protected function normalize_robots($noindex_value, $nofollow_value = null): array {
        $result = ['noindex' => 0, 'nofollow' => 0];

        // Handle serialized array (Rank Math stores robots as serialized array)
        if (is_string($noindex_value) && is_serialized($noindex_value)) {
            $noindex_value = maybe_unserialize($noindex_value);
        }

        if (is_array($noindex_value)) {
            // Rank Math format: serialized array with 'noindex', 'nofollow' as values
            $result['noindex'] = in_array('noindex', $noindex_value, true) ? 1 : 0;
            $result['nofollow'] = in_array('nofollow', $noindex_value, true) ? 1 : 0;
            return $result;
        }

        // Handle individual values
        $result['noindex'] = $this->normalize_bool_value($noindex_value);

        if ($nofollow_value !== null) {
            $result['nofollow'] = $this->normalize_bool_value($nofollow_value);
        }

        return $result;
    }

    /**
     * Normalize a value to 0 or 1
     *
     * @param mixed $value Value to normalize
     * @return int 0 or 1
     */
    private function normalize_bool_value($value): int {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return (int) $value ? 1 : 0;
    }

    /**
     * Get paginated post IDs that have meta keys with the plugin's prefix
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of post IDs
     */
    protected function get_post_ids_with_meta(int $page): array {
        global $wpdb;

        $offset = ($page - 1) * $this->chunk_size;
        $post_types = $this->get_exportable_post_types();

        // Defensive: a site with no viewable post types has nothing to export.
        if (empty($post_types)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

        // Restrict to publicly-viewable post types so WordPress-internal objects
        // (oembed_cache, revisions, nav menu items, block/template CPTs, …) never
        // enter the snapshot — SEO meta left on them is noise. Filtering in the
        // query (rather than per-record) keeps the chunk-size based has_more
        // pagination in export_chunk() accurate.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key LIKE %s
             AND p.post_type IN ({$placeholders})
             ORDER BY pm.post_id ASC
             LIMIT %d OFFSET %d",
            array_merge(
                [$wpdb->esc_like($this->meta_key_prefix) . '%'],
                $post_types,
                [$this->chunk_size, $offset]
            )
        );

        $ids = $wpdb->get_col($sql);
        $this->last_page_row_count = count($ids);

        return $ids;
    }

    /**
     * Publicly-viewable post types whose meta is worth exporting.
     *
     * Excludes WordPress-internal types (oembed_cache, revision, nav_menu_item,
     * wp_* block/template CPTs) that are never served to visitors and therefore
     * carry no meaningful SEO data.
     *
     * @return string[] Post type slugs
     */
    protected function get_exportable_post_types(): array {
        return array_values(
            array_filter(get_post_types([], 'names'), 'is_post_type_viewable')
        );
    }

    /**
     * Get paginated term IDs that have meta keys with the plugin's prefix
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of term IDs
     */
    protected function get_term_ids_with_meta(int $page): array {
        global $wpdb;

        $offset = ($page - 1) * $this->chunk_size;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key LIKE %s ORDER BY term_id ASC LIMIT %d OFFSET %d",
                $wpdb->esc_like($this->meta_key_prefix) . '%',
                $this->chunk_size,
                $offset
            )
        );
        $this->last_page_row_count = count($ids);

        return $ids;
    }

    /**
     * Get paginated user IDs that have meta keys with the plugin's prefix
     *
     * @param int $page Page number (1-indexed)
     * @return array Array of user IDs
     */
    protected function get_user_ids_with_meta(int $page): array {
        global $wpdb;

        $offset = ($page - 1) * $this->chunk_size;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY user_id ASC LIMIT %d OFFSET %d",
                $wpdb->esc_like($this->meta_key_prefix) . '%',
                $this->chunk_size,
                $offset
            )
        );
        $this->last_page_row_count = count($ids);

        return $ids;
    }

    /**
     * Get all meta values for a user with the plugin's prefix
     *
     * @param int $user_id User ID
     * @return array Associative array of meta_key => meta_value
     */
    protected function get_all_plugin_user_meta(int $user_id): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
                $user_id,
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            ),
            ARRAY_A
        );

        $meta = [];
        foreach ($results as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }

        return $meta;
    }

    /**
     * Get all meta values for a post with the plugin's prefix
     *
     * @param int $post_id Post ID
     * @return array Associative array of meta_key => meta_value
     */
    protected function get_all_plugin_meta(int $post_id): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                $post_id,
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            ),
            ARRAY_A
        );

        $meta = [];
        foreach ($results as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }

        return $meta;
    }

    /**
     * Get all meta values for a term with the plugin's prefix
     *
     * @param int $term_id Term ID
     * @return array Associative array of meta_key => meta_value
     */
    protected function get_all_plugin_term_meta(int $term_id): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key LIKE %s",
                $term_id,
                $wpdb->esc_like($this->meta_key_prefix) . '%'
            ),
            ARRAY_A
        );

        $meta = [];
        foreach ($results as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }

        return $meta;
    }

    /**
     * Get the plugin slug
     *
     * @return string
     */
    public function get_plugin_slug(): string {
        return $this->plugin_slug;
    }

    /**
     * Get the plugin name
     *
     * @return string
     */
    public function get_plugin_name(): string {
        return $this->plugin_name;
    }
}
