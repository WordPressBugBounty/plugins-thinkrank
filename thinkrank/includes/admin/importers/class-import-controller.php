<?php

/**
 * Import Controller
 *
 * REST API endpoints for the two-phase SEO data import system.
 * 6 endpoints under thinkrank/v1/import.
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
 * Import Controller Class
 *
 * @since 2.0.0
 */
class Import_Controller extends \WP_REST_Controller {

    /**
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * @var string
     */
    protected $rest_base = 'import';

    /**
     * Allowed plugin slugs
     */
    private const ALLOWED_PLUGINS = ['yoast', 'rankmath', 'seopress', 'aioseo'];

    /**
     * Allowed export/migrate types. Must cover every type the exporters and
     * the frontend workflow (useImportWorkflow.js EXPORT_TYPES) can send —
     * 404_logs was missing, so Rank Math's 404 Monitor could never be
     * exported or migrated through REST.
     */
    private const ALLOWED_TYPES = ['postmeta', 'termmeta', 'usermeta', 'redirections', '404_logs', 'settings'];

    /**
     * Register REST routes
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/detect', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'detect'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/export', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'export'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => $this->get_export_args(),
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/snapshots', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_snapshots'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/migrate', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'migrate'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => $this->get_migrate_args(),
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/cleanup', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'cleanup'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'plugin' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => self::ALLOWED_PLUGINS,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    // Required to proceed while the snapshot still holds
                    // extended data with no migration path (see cleanup()).
                    'force' => [
                        'required' => false,
                        'type'     => 'boolean',
                        'default'  => false,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/snapshot', [
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_snapshot'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'plugin' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => self::ALLOWED_PLUGINS,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Permission check — manage_options required
     *
     * @return bool
     */
    public function check_permissions(): bool {
        return current_user_can('manage_options');
    }

    /**
     * GET /import/detect — Detect source plugins and existing snapshots
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function detect(\WP_REST_Request $request): \WP_REST_Response {
        $detector = new Import_Detector();
        $detected = $detector->detect();
        $snapshots = Snapshot_Store::get_available_snapshots();

        return new \WP_REST_Response([
            'detected'  => $detected,
            'snapshots' => $snapshots,
        ], 200);
    }

    /**
     * POST /import/export — Export a batch of source data to snapshot
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     */
    public function export(\WP_REST_Request $request) {
        $plugin = $request->get_param('plugin');
        $type = $request->get_param('type');
        $page = (int) $request->get_param('page');

        $exporter = $this->get_exporter($plugin);
        if (is_wp_error($exporter)) {
            return $exporter;
        }

        $result = $exporter->export_chunk($type, $page);

        // If this type is complete and it's the last type, finalize
        if (!$result['has_more'] && $request->get_param('is_last_type')) {
            $exporter->finalize_export();
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * GET /import/snapshots — List existing snapshots
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_snapshots(\WP_REST_Request $request): \WP_REST_Response {
        $snapshots = Snapshot_Store::get_available_snapshots();
        return new \WP_REST_Response(['snapshots' => $snapshots], 200);
    }

    /**
     * POST /import/migrate — Migrate a batch from snapshot to ThinkRank meta
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function migrate(\WP_REST_Request $request): \WP_REST_Response {
        $plugin = $request->get_param('plugin');
        $type = $request->get_param('type');
        $page = (int) $request->get_param('page');

        $migrator = new Snapshot_Migrator();
        $result = $migrator->migrate_chunk($plugin, $type, $page);

        // If migration is complete for all types, update manifest
        if (!$result['has_more'] && $request->get_param('is_last_type')) {
            $migrator->update_manifest_migration_info($plugin);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * POST /import/cleanup — Remove source plugin meta from database
     *
     * Deletes SOURCE plugin data only — the ThinkRank snapshot is never touched
     * by this endpoint (use DELETE /import/snapshot for that). Because the
     * snapshot may still hold extended data ThinkRank cannot apply yet (e.g.
     * redirections, owed to the Pro Redirections feature), cleanup is gated:
     * while such buckets exist the request is rejected with HTTP 409 unless
     * force=true is passed, so the user explicitly acknowledges that the
     * snapshot becomes the only copy of that data.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     */
    public function cleanup(\WP_REST_Request $request) {
        global $wpdb;

        $plugin = $request->get_param('plugin');
        $force = (bool) $request->get_param('force');
        $deleted = 0;

        // Gate: block while the snapshot holds preserved-but-unapplied extended
        // data, unless the caller explicitly forces the cleanup.
        if (!$force) {
            $migrator = new Snapshot_Migrator();
            $unmigrated = $migrator->get_unmigrated_extended_buckets($plugin);

            if (!empty($unmigrated)) {
                $labels = array_map(
                    static function (array $bucket): string {
                        return $bucket['count'] > 1
                            ? sprintf('%s (%d)', $bucket['label'], $bucket['count'])
                            : (string) $bucket['label'];
                    },
                    $unmigrated
                );

                return new \WP_Error(
                    'thinkrank_cleanup_blocked',
                    sprintf(
                        /* translators: %s: comma-separated list of unapplied data buckets. */
                        __('The snapshot still holds data ThinkRank has not applied yet: %s. It stays preserved in the snapshot (cleanup never deletes the snapshot), but the source plugin\'s copy will be removed. Pass force=true to proceed.', 'thinkrank'),
                        implode(', ', $labels)
                    ),
                    [
                        'status'         => 409,
                        'preserved'      => $unmigrated,
                        'requires_force' => true,
                    ]
                );
            }
        }

        $prefix_map = [
            'yoast'    => '_yoast_wpseo_',
            'rankmath' => 'rank_math_',
            'seopress' => '_seopress_',
            'aioseo'   => null, // Custom table
        ];

        $prefix = $prefix_map[$plugin] ?? null;

        if ($prefix) {
            // Delete from postmeta
            $deleted += (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like($prefix) . '%'
                )
            );

            // Delete from termmeta
            $deleted += (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like($prefix) . '%'
                )
            );

            // Delete author-level SEO meta from usermeta. Yoast keys user meta
            // under a different prefix than its post meta, so map it explicitly;
            // the others reuse their post-meta prefix.
            $usermeta_prefix_map = [
                'yoast'    => 'wpseo_',
                'rankmath' => 'rank_math_',
                'seopress' => '_seopress_',
            ];
            $usermeta_prefix = $usermeta_prefix_map[$plugin] ?? $prefix;
            $deleted += (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like($usermeta_prefix) . '%'
                )
            );

            // Delete the source plugin's option rows — the exact options each
            // exporter reads, so the site-level settings we migrated are removed
            // too rather than left orphaned.
            $option_keys_map = [
                'yoast'    => ['wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_taxonomy_meta'],
                'rankmath' => ['rank-math-options-general', 'rank-math-options-titles', 'rank-math-options-sitemap', 'rank-math-options-instant-indexing'],
                'seopress' => ['seopress_titles_option_name', 'seopress_social_option_name', 'seopress_advanced_option_name', 'seopress_xml_sitemap_option_name', 'seopress_instant_indexing_option_name'],
            ];
            foreach ($option_keys_map[$plugin] ?? [] as $option_name) {
                if (delete_option($option_name)) {
                    $deleted++;
                }
            }
        }

        if ($plugin === 'aioseo') {
            // Drop the AIOSEO custom tables the exporter reads (posts + Pro terms).
            foreach (['aioseo_posts', 'aioseo_terms'] as $suffix) {
                $table = $wpdb->prefix . $suffix;
                $table_exists = $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $table)
                );
                if ($table_exists) {
                    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                    $wpdb->query("DROP TABLE {$table}");
                    $deleted += $count;
                }
            }

            // Legacy term meta (aioseo_*-prefixed) some versions used.
            $deleted += (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like('aioseo_') . '%'
                )
            );

            // The option blobs the exporter reads (settings, dynamic per-type
            // templates, Pro add-on settings).
            foreach (['aioseo_options', 'aioseo_options_dynamic', 'aioseo_options_pro'] as $option_name) {
                if (delete_option($option_name)) {
                    $deleted++;
                }
            }
        }

        // Clear detection cache
        $detector = new Import_Detector();
        $detector->clear_cache();

        return new \WP_REST_Response([
            'status'  => 'complete',
            'message' => sprintf('Deleted %d source data entries for %s', $deleted, $plugin),
            'deleted' => $deleted,
        ], 200);
    }

    /**
     * DELETE /import/snapshot — Delete snapshot data from wp_options
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function delete_snapshot(\WP_REST_Request $request): \WP_REST_Response {
        $plugin = $request->get_param('plugin');
        $deleted = Snapshot_Store::delete_snapshot($plugin);

        return new \WP_REST_Response([
            'status'  => 'complete',
            'message' => sprintf('Deleted %d snapshot options for %s', $deleted, $plugin),
            'deleted' => $deleted,
        ], 200);
    }

    /**
     * Get the appropriate exporter instance for a plugin
     *
     * @param string $plugin Plugin slug
     * @return Abstract_Plugin_Exporter|\WP_Error
     */
    private function get_exporter(string $plugin) {
        return match ($plugin) {
            'yoast'    => new Yoast_Exporter(),
            'rankmath' => new Rankmath_Exporter(),
            'seopress' => new SEOPress_Exporter(),
            'aioseo'   => new AIOSEO_Exporter(),
            default    => new \WP_Error('invalid_plugin', 'Unsupported plugin: ' . $plugin, ['status' => 400]),
        };
    }

    /**
     * Get argument schema for export endpoint
     *
     * @return array
     */
    private function get_export_args(): array {
        return [
            'plugin' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => self::ALLOWED_PLUGINS,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'type' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => self::ALLOWED_TYPES,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'page' => [
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'is_last_type' => [
                'required' => false,
                'type'     => 'boolean',
                'default'  => false,
            ],
        ];
    }

    /**
     * Get argument schema for migrate endpoint
     *
     * @return array
     */
    private function get_migrate_args(): array {
        return [
            'plugin' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => self::ALLOWED_PLUGINS,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'type' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => self::ALLOWED_TYPES,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'page' => [
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'is_last_type' => [
                'required' => false,
                'type'     => 'boolean',
                'default'  => false,
            ],
        ];
    }
}
