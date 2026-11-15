<?php

/**
 * Snapshot Scrubber
 *
 * One-time cleanup of import snapshots stored before the exporter's redaction
 * deny-list covered everything it covers now.
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.21.4
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot Scrubber Class
 *
 * The Rank Math exporter copies whole `rank-math-options-*` blobs into
 * `extended.raw_options` of its settings record, minus the keys its redactor
 * drops. Widening that redactor only protects exports taken afterwards: a
 * snapshot is kept on purpose once the source plugin's data is cleaned up, so
 * one written by an older version keeps whatever the older rule let through
 * (#1561). This re-runs today's redactor over the stored settings chunks.
 *
 * @since 2.21.4
 */
class Snapshot_Scrubber {

    /**
     * Marker for the one-time scrub. Autoloaded, so the check on each admin
     * request costs no query.
     *
     * @var string
     */
    public const MARKER_OPTION = 'thinkrank_snapshot_secrets_scrubbed';

    /**
     * Snapshot source whose settings record carries `raw_options` captured
     * through Rankmath_Exporter::redact_sensitive_keys().
     *
     * @var string
     */
    private const PLUGIN = 'rankmath';

    /**
     * Snapshot type holding that record.
     *
     * @var string
     */
    private const TYPE = 'settings';

    /**
     * Most chunks one run visits. The settings type is a single record in a
     * single chunk; the ceiling only keeps the loop bounded.
     *
     * @var int
     */
    private const MAX_CHUNKS = 50;

    /**
     * Run the scrub once per site.
     *
     * The marker is written only when every chunk is known to be clean: a run
     * that dies part-way, or whose write did not land, is repeated on the next
     * admin request. Repeating is safe: a chunk already clean is left as it is.
     *
     * @since 2.21.4
     *
     * @return void
     */
    public static function maybe_scrub(): void {
        if (get_option(self::MARKER_OPTION)) {
            return;
        }

        if (self::scrub_chunks()['complete']) {
            update_option(self::MARKER_OPTION, time(), true);
        }
    }

    /**
     * Redact the stored Rank Math settings chunks in place.
     *
     * Only `extended.raw_options` is touched, and a chunk is written back only
     * when the redactor removed something from it.
     *
     * @since 2.21.4
     *
     * @return int Number of chunks rewritten.
     */
    public static function scrub(): int {
        return self::scrub_chunks()['rewritten'];
    }

    /**
     * Walk the stored chunks, rewriting the ones the redactor changes.
     *
     * Snapshot_Store::write_chunk() does not report a failed write, so each
     * rewrite is read back: a chunk the redactor would still change was not
     * stored, and the pass is then not complete.
     *
     * @return array{rewritten: int, complete: bool}
     */
    private static function scrub_chunks(): array {
        $rewritten = 0;
        $complete  = true;

        foreach (Snapshot_Store::list_chunk_pages(self::PLUGIN, self::TYPE, self::MAX_CHUNKS) as $page) {
            $records = Snapshot_Store::read_chunk(self::PLUGIN, self::TYPE, $page);
            if (null === $records) {
                continue;
            }

            $clean = self::redact_records($records);
            if ($clean === $records) {
                continue;
            }

            Snapshot_Store::write_chunk(self::PLUGIN, self::TYPE, $page, $clean);

            $stored = Snapshot_Store::read_chunk(self::PLUGIN, self::TYPE, $page);
            if (null === $stored || self::redact_records($stored) !== $stored) {
                $complete = false;
                continue;
            }

            $rewritten++;
        }

        return [
            'rewritten' => $rewritten,
            'complete'  => $complete,
        ];
    }

    /**
     * Apply the exporter's redactor to each record's raw option capture.
     *
     * @param array $records Chunk records.
     * @return array The records, with `extended.raw_options` redacted.
     */
    private static function redact_records(array $records): array {
        foreach ($records as $index => $record) {
            if (!is_array($record) || !isset($record['extended']['raw_options']) || !is_array($record['extended']['raw_options'])) {
                continue;
            }

            // Per option, as capture_raw_options() does: the option names are
            // the map's keys and are not themselves subject to the deny-list.
            foreach ($record['extended']['raw_options'] as $name => $options) {
                if (is_array($options)) {
                    $records[$index]['extended']['raw_options'][$name] = Rankmath_Exporter::redact_sensitive_keys($options);
                }
            }
        }

        return $records;
    }
}
