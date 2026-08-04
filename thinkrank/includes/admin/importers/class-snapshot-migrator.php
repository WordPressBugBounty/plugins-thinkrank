<?php

/**
 * Snapshot Migrator
 *
 * Plugin-agnostic migrator that reads normalized snapshot data from wp_options
 * and writes _thinkrank_* post/term/user meta. Has no knowledge of source plugin
 * formats.
 *
 * Rules:
 * - Never overwrite existing ThinkRank data
 * - Skip empty string values
 * - Write _thinkrank_imported_from audit trail
 * - Refuse to start if manifest status != 'complete'
 * - Ignore record['extended'] (preserved in snapshot for future migration)
 *
 * @package ThinkRank\Admin\Importers
 * @since 2.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin\Importers;

use ThinkRank\SEO\Focus_Keywords;
use ThinkRank\SEO\Pattern_Resolver;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot Migrator Class
 *
 * @since 2.0.0
 */
class Snapshot_Migrator {

    /**
     * Canonical field → ThinkRank meta key mapping.
     * This map grows as ThinkRank adds features.
     */
    private const META_MAP = [
        'seo_title'           => '_thinkrank_seo_title',
        'meta_description'    => '_thinkrank_meta_description',
        'focus_keyword'       => '_thinkrank_focus_keyword',
        'canonical_url'       => '_thinkrank_canonical_url',
        'og_title'            => '_thinkrank_og_title',
        'og_description'      => '_thinkrank_og_description',
        'og_image'            => '_thinkrank_og_image',
        'twitter_title'       => '_thinkrank_twitter_title',
        'twitter_description' => '_thinkrank_twitter_description',
        'twitter_image'       => '_thinkrank_twitter_image',
        'primary_category'    => '_thinkrank_primary_category',
        'schema_type'         => '_thinkrank_selected_schema_type',
    ];

    /**
     * Canonical robots meta fields. Composed into JSON-encoded
     * `_thinkrank_robots_meta` / `_thinkrank_advanced_robots_meta`
     * post meta by build_robots_payload().
     */
    private const ROBOTS_FIELDS = [
        'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet',
    ];

    private const ADVANCED_ROBOTS_FIELDS = [
        'max_snippet', 'max_video_preview', 'max_image_preview',
    ];

    /**
     * Data types that are migratable (have post/term/user meta mappings)
     */
    private const MIGRATABLE_TYPES = ['postmeta', 'termmeta', 'usermeta', 'redirections', '404_logs', 'settings'];

    /**
     * Settings-record `extended` keys that either migrate today or are safe to
     * discard on cleanup (raw_options is pure capture-all insurance; a fresh
     * export recreates it, and analytics_connected is informational only).
     * Anything OUTSIDE this list is treated as preserved-but-unapplied data by
     * get_unmigrated_extended_buckets(), which gates /import/cleanup.
     */
    private const HANDLED_EXTENDED_SETTINGS = [
        'breadcrumb_settings',
        'local_seo',
        'post_type_settings',
        'title_formats',
        'author_archives',
        'instant_indexing_post_types',
        'instant_indexing_log',
        'publisher_sitemaps',
        'email_reports',
        'role_capabilities',
        'image_seo',
        'sitemap_settings',
        'analytics_connected',
        // Capture-all raw buckets (whole source option sets stored verbatim).
        // They live in the SNAPSHOT — cleanup never touches the snapshot — and
        // a re-export recreates them, so they never block cleanup.
        'raw_options',
        'search_appearance',
        'social_settings',
        'advanced',
        'sitemap_settings_raw',
    ];

    /**
     * Migrate one chunk of snapshot data to ThinkRank meta
     *
     * @param string $plugin Plugin slug
     * @param string $type Data type (postmeta, termmeta, usermeta, settings)
     * @param int $page Chunk/page number
     * @return array Result with status, has_more, processed, skipped
     */
    public function migrate_chunk(string $plugin, string $type, int $page): array {
        // Validate manifest status
        $manifest = Snapshot_Store::get_manifest($plugin);
        if (!$manifest || ($manifest['status'] ?? '') !== 'complete') {
            return [
                'status'  => 'error',
                'message' => 'Snapshot is not complete. Run export first.',
                'has_more' => false,
                'processed' => 0,
                'skipped' => 0,
            ];
        }

        if ($type === 'settings') {
            return $this->migrate_settings($plugin);
        }

        if ($type === 'redirections') {
            return $this->migrate_redirections($plugin, $page);
        }

        if ($type === '404_logs') {
            return $this->migrate_404_logs($plugin, $page);
        }

        $chunk = Snapshot_Store::read_chunk($plugin, $type, $page);
        if ($chunk === null || empty($chunk)) {
            return [
                'status'   => 'complete',
                'message'  => 'No data in chunk',
                'has_more' => false,
                'processed' => 0,
                'skipped'  => 0,
            ];
        }

        $processed = 0;
        $skipped = 0;
        $keywords = [];
        $post_ids = [];
        // Post IDs the source excluded from its sitemap.
        $sitemap_excluded = [];
        // Focus keyword overflow: posts whose source had more than MAX keywords.
        $truncations = [];

        foreach ($chunk as $record) {
            $object_id = (int) ($record['object_id'] ?? 0);
            $object_type = $record['object_type'] ?? '';
            $source_plugin = $record['source_plugin'] ?? $plugin;
            $data = $record['data'] ?? [];

            if (!$object_id || empty($data)) {
                $skipped++;
                continue;
            }

            // Track migrated posts so their SEO score can be computed once the
            // chunk's meta has landed (terms are not scored).
            if ($object_type === 'post') {
                $post_ids[$object_id] = true;
            }

            $record_had_writes = false;

            // Collect focus keywords (primary + secondary) to seed the Pro
            // Rank Tracker watch-list once the chunk is processed.
            $this->collect_keywords($record, $data, $keywords);

            foreach ($data as $canonical_key => $value) {
                if (!isset(self::META_MAP[$canonical_key])) {
                    continue;
                }

                // Focus keywords are migrated as an array via the dedicated
                // migrate_focus_keywords() below (which also keeps the legacy
                // single-value meta in sync), so skip the scalar write here.
                if ($canonical_key === 'focus_keyword') {
                    continue;
                }

                $thinkrank_key = self::META_MAP[$canonical_key];

                // Skip empty string values
                if ($value === '' || $value === null) {
                    continue;
                }

                // Skip zero values for integer fields that are "not set"
                if ($value === 0 && in_array($canonical_key, ['primary_category'], true)) {
                    continue;
                }

                if ($object_type === 'post') {
                    // Never overwrite existing ThinkRank data
                    $existing = get_post_meta($object_id, $thinkrank_key, true);
                    if ($existing !== '' && $existing !== false && $existing !== null) {
                        continue;
                    }

                    update_post_meta($object_id, $thinkrank_key, $value);
                    $record_had_writes = true;
                } elseif ($object_type === 'term') {
                    $existing = get_term_meta($object_id, $thinkrank_key, true);
                    if ($existing !== '' && $existing !== false && $existing !== null) {
                        continue;
                    }

                    update_term_meta($object_id, $thinkrank_key, $value);
                    $record_had_writes = true;
                } elseif ($object_type === 'user') {
                    $existing = get_user_meta($object_id, $thinkrank_key, true);
                    if ($existing !== '' && $existing !== false && $existing !== null) {
                        continue;
                    }

                    update_user_meta($object_id, $thinkrank_key, $value);
                    $record_had_writes = true;
                }
            }

            // Focus keywords (post meta only). Migrates the full deduped,
            // capped keyword array and keeps the legacy single value in sync.
            if ($object_type === 'post' && $this->migrate_focus_keywords($object_id, $data, $truncations)) {
                $record_had_writes = true;
            }

            // Compose the per-post robots JSON payload (post meta only).
            if ($object_type === 'post' && $this->migrate_robots_payload($object_id, $data)) {
                $record_had_writes = true;
            }

            // Pillar / cornerstone content flag (post meta only).
            if ($object_type === 'post' && $this->migrate_pillar_content($object_id, $data)) {
                $record_had_writes = true;
            }

            // Review schema form data (post meta only). Seeds the metabox Review
            // form so an imported review renders once deployed.
            if ($object_type === 'post' && $this->migrate_review_schema($object_id, $data, $record)) {
                $record_had_writes = true;
            }

            // VideoObject schema form data (post meta only). Seeds the metabox
            // Video form so an imported video schema renders once deployed.
            if ($object_type === 'post' && $this->migrate_video_schema($object_id, $data, $record)) {
                $record_had_writes = true;
            }

            // Per-post "exclude from sitemap" flags. ThinkRank models sitemap
            // exclusion as one comma-separated ID list on the sitemap settings
            // rather than per-post meta, so collect the IDs and apply them once
            // after the chunk (a settings write per post would be wasteful).
            if ($object_type === 'post' && !empty($record['extended']['exclude_sitemap'])) {
                $sitemap_excluded[] = $object_id;
            }

            if ($record_had_writes) {
                // Write audit trail
                if ($object_type === 'post') {
                    update_post_meta($object_id, '_thinkrank_imported_from', $source_plugin);
                } elseif ($object_type === 'term') {
                    update_term_meta($object_id, '_thinkrank_imported_from', $source_plugin);
                } elseif ($object_type === 'user') {
                    update_user_meta($object_id, '_thinkrank_imported_from', $source_plugin);
                }
                $processed++;
            } else {
                $skipped++;
            }
        }

        // Seed the Pro Rank Tracker watch-list from the collected keywords.
        // No-op when Pro is inactive.
        $keywords_seeded = $this->seed_rank_tracker($keywords);

        // Fold this chunk's sitemap-excluded posts into the sitemap settings.
        $sitemap_excluded_count = $this->migrate_sitemap_exclusions($sitemap_excluded);

        // Compute + persist SEO scores for the migrated posts so the SEO
        // Overview reflects accurate data without a manual re-analyze. The
        // snapshot's chunk pagination bounds this to <=100 posts per request,
        // which keeps each pass well within PHP execution limits.
        $analyzed = $this->analyze_posts(array_keys($post_ids));

        // Check if there are more chunks
        $type_info = $manifest['types'][$type] ?? [];
        $total_chunks = $type_info['total_chunks'] ?? 0;
        $has_more = $page < $total_chunks;

        return [
            'status'          => $has_more ? 'processing' : 'complete',
            'message'         => sprintf('Migrated %d records, skipped %d (page %d)', $processed, $skipped, $page),
            'has_more'        => $has_more,
            'page'            => $page,
            'total_chunks'    => $total_chunks,
            'processed'       => $processed,
            'skipped'         => $skipped,
            'keywords_seeded' => $keywords_seeded,
            'sitemap_excluded' => $sitemap_excluded_count,
            'analyzed'        => $analyzed,
            // Posts whose source had more than the max focus keywords; the
            // excess was capped but preserved in the overflow meta.
            'keywords_truncated' => count($truncations),
            'keywords_truncated_sample' => array_slice($truncations, 0, 10),
        ];
    }

    /**
     * Dry-run a snapshot chunk: classify what a migrate WOULD do without
     * writing anything. Mirrors migrate_chunk()'s per-field decision (skip
     * empty values, never overwrite existing ThinkRank data) so the counts
     * match what a real migrate would produce.
     *
     * Each object-meta record lands in exactly one bucket:
     * - `unmatched`   — the referenced post/term/user no longer exists here.
     * - `would_write` — at least one field would be written (target empty).
     * - `conflicts`   — no writes, but the source differs from an existing
     *                   ThinkRank value that migrate would NOT overwrite.
     * - `skipped`     — matched, but nothing to write and nothing conflicting
     *                   (empty data, or values already identical).
     *
     * Only object-meta types (postmeta/termmeta/usermeta) are previewed;
     * settings/redirections/404 logs are migrated wholesale and return zeros.
     *
     * @param string $plugin Source plugin slug.
     * @param string $type   Snapshot data type.
     * @param int    $page   1-based chunk page.
     * @return array<string,mixed>
     */
    public function preview_chunk(string $plugin, string $type, int $page): array {
        $summary = [
            'type'        => $type,
            'page'        => $page,
            'records'     => 0,
            'unmatched'   => 0,
            'would_write' => 0,
            'conflicts'   => 0,
            'skipped'     => 0,
            'has_more'    => false,
            'samples'     => ['unmatched' => [], 'would_write' => [], 'conflicts' => []],
        ];

        $manifest = Snapshot_Store::get_manifest($plugin);
        if (!$manifest || ($manifest['status'] ?? '') !== 'complete') {
            $summary['error'] = 'Snapshot is not complete. Run export first.';
            return $summary;
        }

        if (!in_array($type, ['postmeta', 'termmeta', 'usermeta'], true)) {
            return $summary;
        }

        $chunk = Snapshot_Store::read_chunk($plugin, $type, $page);
        if ($chunk === null || empty($chunk)) {
            return $summary;
        }

        foreach ($chunk as $record) {
            $summary['records']++;

            $object_id = (int) ($record['object_id'] ?? 0);
            $object_type = $record['object_type'] ?? '';
            $data = $record['data'] ?? [];

            if (!$object_id || empty($data)) {
                $summary['skipped']++;
                continue;
            }

            if (!$this->object_exists($object_type, $object_id)) {
                $summary['unmatched']++;
                if (count($summary['samples']['unmatched']) < 10) {
                    $summary['samples']['unmatched'][] = ['object_id' => $object_id, 'object_type' => $object_type];
                }
                continue;
            }

            $writes = [];
            $conflicts = [];

            foreach ($data as $canonical_key => $value) {
                if (!isset(self::META_MAP[$canonical_key])) {
                    continue;
                }
                if ($value === '' || $value === null) {
                    continue;
                }
                if ($value === 0 && in_array($canonical_key, ['primary_category'], true)) {
                    continue;
                }

                $existing = $this->get_object_meta($object_type, $object_id, self::META_MAP[$canonical_key]);
                if ($existing === '' || $existing === false || $existing === null) {
                    $writes[] = $canonical_key;
                } else {
                    $source = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
                    if ((string) $existing !== $source) {
                        $conflicts[] = $canonical_key;
                    }
                }
            }

            if (!empty($writes)) {
                $summary['would_write']++;
                if (count($summary['samples']['would_write']) < 10) {
                    $summary['samples']['would_write'][] = ['object_id' => $object_id, 'fields' => $writes];
                }
            } elseif (!empty($conflicts)) {
                $summary['conflicts']++;
                if (count($summary['samples']['conflicts']) < 10) {
                    $summary['samples']['conflicts'][] = ['object_id' => $object_id, 'fields' => $conflicts];
                }
            } else {
                $summary['skipped']++;
            }
        }

        $type_info = $manifest['types'][$type] ?? [];
        $total_chunks = $type_info['total_chunks'] ?? 0;
        $summary['has_more'] = $page < $total_chunks;

        return $summary;
    }

    /**
     * Whether the referenced object still exists on this site.
     *
     * @param string $object_type One of post|term|user.
     * @param int    $object_id   Object id.
     * @return bool
     */
    private function object_exists(string $object_type, int $object_id): bool {
        switch ($object_type) {
            case 'post':
                return (bool) get_post($object_id);
            case 'term':
                return (bool) get_term($object_id);
            case 'user':
                return (bool) get_userdata($object_id);
        }
        return false;
    }

    /**
     * Read a single meta value for post|term|user.
     *
     * @param string $object_type One of post|term|user.
     * @param int    $object_id   Object id.
     * @param string $key         Meta key.
     * @return mixed
     */
    private function get_object_meta(string $object_type, int $object_id, string $key) {
        switch ($object_type) {
            case 'post':
                return get_post_meta($object_id, $key, true);
            case 'term':
                return get_term_meta($object_id, $key, true);
            case 'user':
                return get_user_meta($object_id, $key, true);
        }
        return '';
    }

    /**
     * Lazily instantiated SEO score calculator.
     *
     * @var \ThinkRank\AI\SEOScoreCalculator|null
     */
    private ?\ThinkRank\AI\SEOScoreCalculator $score_calculator = null;

    /**
     * Calculate and store SEO scores for freshly migrated posts.
     *
     * The scorer is purely local/algorithmic (no external AI calls), so it is
     * safe to run synchronously in bulk. Posts that already carry a score are
     * skipped, keeping the pass idempotent across re-runs. A per-post failure
     * is swallowed so one bad post never aborts the whole chunk.
     *
     * Fires `thinkrank_seo_score_updated` once when any score was written so the
     * cached SEO Overview / usage-analytics responses are invalidated.
     *
     * @param int[] $post_ids Migrated post IDs (de-duplicated)
     * @return int Number of posts scored this pass
     */
    private function analyze_posts(array $post_ids): int {
        if (empty($post_ids)) {
            return 0;
        }

        // Delegate to the shared scoring loop (also used by the
        // bulk-analyze-and-save ability); migration only needs the count.
        $summary = $this->score_posts($post_ids);

        return $summary['scored'];
    }

    /**
     * Score and persist SEO scores for a set of posts, returning per-post
     * results plus totals. This is the shared bulk-scoring loop used both by
     * migration (via analyze_posts()) and the bulk-analyze-and-save ability.
     *
     * The scorer is purely local/algorithmic (no external AI calls), so it is
     * safe to run synchronously in bulk. A per-post failure is captured, never
     * thrown, so one bad post cannot abort the batch. Fires
     * `thinkrank_seo_score_updated` once when any score was written so cached
     * SEO Overview / usage-analytics responses are invalidated.
     *
     * @param int[] $post_ids Post IDs to score (de-duplicated internally).
     * @param bool  $rescore  When false (default), posts that already carry a
     *                        stored score are left untouched (idempotent). When
     *                        true, every post is re-scored and re-saved.
     * @return array{results:array<int,array<string,mixed>>,scored:int,skipped:int,failed:int,total:int}
     */
    public function score_posts(array $post_ids, bool $rescore = false): array {
        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));

        $calculator = $this->get_score_calculator();
        $user_id = get_current_user_id();

        $results = [];
        $scored = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($post_ids as $post_id) {
            $result = $this->score_single_post($calculator, $user_id, $post_id, $rescore);
            $results[] = $result;

            if ($result['status'] === 'scored') {
                $scored++;
            } elseif ($result['status'] === 'error') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        if ($scored > 0) {
            // Invalidate cached analytics / SEO Overview responses.
            do_action('thinkrank_seo_score_updated');
        }

        return [
            'results' => $results,
            'scored'  => $scored,
            'skipped' => $skipped,
            'failed'  => $failed,
            'total'   => count($results),
        ];
    }

    /**
     * Score and persist a single post. Returns a structured per-post result:
     * status is one of `scored`, `skipped_existing`, `not_found`,
     * `no_content`, or `error`.
     *
     * @param \ThinkRank\AI\SEOScoreCalculator $calculator Shared calculator.
     * @param int                              $user_id    Acting user id.
     * @param int                              $post_id    Post to score.
     * @param bool                             $rescore    Re-score even if scored.
     * @return array<string,mixed>
     */
    private function score_single_post(\ThinkRank\AI\SEOScoreCalculator $calculator, int $user_id, int $post_id, bool $rescore): array {
        $base = ['post_id' => $post_id, 'status' => '', 'score' => null, 'score_id' => null];

        if (!$rescore && $calculator->get_latest_score($post_id) !== null) {
            return array_merge($base, ['status' => 'skipped_existing']);
        }

        if (!get_post($post_id)) {
            return array_merge($base, ['status' => 'not_found']);
        }

        try {
            $content_data = $calculator->analyze_post_content($post_id);
            if (empty($content_data)) {
                return array_merge($base, ['status' => 'no_content']);
            }

            // Score against the effective title/description (custom value, else
            // the resolved Global pattern) so posts that inherit their title or
            // description from a global pattern are scored the same as in the
            // editor and on the frontend, instead of as if those fields were
            // empty. Pattern_Resolver::title() already falls back through the
            // WordPress post title, so the previous post_title fallback is covered.
            $metadata = [
                'title'       => Pattern_Resolver::effective_title($post_id),
                'description' => Pattern_Resolver::effective_description($post_id),
            ];

            // Score against all focus keywords; the calculator uses the
            // highest-scoring keyword as the final score.
            $target_keywords = Focus_Keywords::get($post_id);

            $score_data = $calculator->calculate_score(
                $content_data,
                $metadata,
                ['target_keywords' => $target_keywords]
            );

            $score_id = $calculator->save_score($post_id, $user_id, $score_data);
            if ($score_id === false) {
                return array_merge($base, ['status' => 'error']);
            }

            return [
                'post_id'  => $post_id,
                'status'   => 'scored',
                'score'    => isset($score_data['overall_score']) ? (int) $score_data['overall_score'] : null,
                'score_id' => (int) $score_id,
            ];
        } catch (\Throwable $e) {
            // Never let a single post abort the batch.
            return array_merge($base, ['status' => 'error']);
        }
    }

    /**
     * Get (and lazily build) the shared SEO score calculator instance.
     *
     * @return \ThinkRank\AI\SEOScoreCalculator
     */
    private function get_score_calculator(): \ThinkRank\AI\SEOScoreCalculator {
        if ($this->score_calculator === null) {
            $this->score_calculator = new \ThinkRank\AI\SEOScoreCalculator(new \ThinkRank\Core\Database());
        }

        return $this->score_calculator;
    }

    /**
     * Collect a record's focus keywords (primary + secondary) into an
     * accumulator keyed by a normalized form to avoid duplicate inserts.
     *
     * Primary lives in the canonical `data['focus_keyword']`; secondary
     * keyphrases are preserved in `extended['focus_keywords_additional']`.
     *
     * @param array $record   Full snapshot record
     * @param array $data     Canonical record data
     * @param array $keywords Accumulator (passed by reference): normalized => raw
     * @return void
     */
    private function collect_keywords(array $record, array $data, array &$keywords): void {
        $candidates = [];

        $primary = (string) ($data['focus_keyword'] ?? '');
        if ($primary !== '') {
            $candidates[] = $primary;
        }

        $additional = $record['extended']['focus_keywords_additional'] ?? [];
        if (is_array($additional)) {
            foreach ($additional as $keyword) {
                $candidates[] = (string) $keyword;
            }
        }

        foreach ($candidates as $keyword) {
            $key = strtolower(trim($keyword));
            if ($key !== '') {
                $keywords[$key] = $keyword;
            }
        }
    }

    /**
     * Seed the Pro Rank Tracker watch-list with the collected keywords.
     *
     * Gated on Pro being active (classes present). The Free plugin never
     * hard-depends on Pro — the fully-qualified references only resolve when
     * Pro's autoloader is registered. Pro lazily creates its tables via
     * Schema::ensure() and add_keyword() is idempotent (INSERT IGNORE).
     *
     * @param array $keywords Map of normalized => raw keyword
     * @return int Number of keywords handed to the watch-list
     */
    private function seed_rank_tracker(array $keywords): int {
        if (empty($keywords)) {
            return 0;
        }

        if (
            !class_exists('ThinkRank\\Pro\\Rank_Tracker\\Schema')
            || !class_exists('ThinkRank\\Pro\\Rank_Tracker\\Repository')
        ) {
            return 0;
        }

        \ThinkRank\Pro\Rank_Tracker\Schema::ensure();
        $repository = new \ThinkRank\Pro\Rank_Tracker\Repository();

        $seeded = 0;
        foreach ($keywords as $keyword) {
            if ($repository->add_keyword($keyword)) {
                $seeded++;
            }
        }

        return $seeded;
    }

    /**
     * Migrate the pillar / cornerstone content flag to ThinkRank post meta.
     *
     * ThinkRank stores an enabled flag as the string '1'; the reader
     * (Pillar_Content endpoint) matches meta_value = '1'. Never overwrites an
     * existing ThinkRank value.
     *
     * @param int   $post_id Target post ID
     * @param array $data    Canonical record data
     * @return bool True when the flag was written
     */
    private function migrate_pillar_content(int $post_id, array $data): bool {
        if (empty($data['pillar_content'])) {
            return false;
        }

        $existing = get_post_meta($post_id, '_thinkrank_pillar_content', true);
        if ($existing !== '' && $existing !== false && $existing !== null) {
            return false;
        }

        update_post_meta($post_id, '_thinkrank_pillar_content', '1');

        return true;
    }

    /**
     * Migrate the post's focus keywords.
     *
     * Reads the full list from the snapshot's `focus_keywords` (falling back to
     * the single `focus_keyword`) and persists via Focus_Keywords::save_with_
     * overflow(): the first MAX keywords are the base, the rest are stored as
     * gated overflow (free) that Pro unlocks automatically. Never overwrites
     * existing ThinkRank focus keywords.
     *
     * Posts whose source exceeded the free limit are recorded in `$truncations`
     * so the import summary can surface them as a Pro upsell.
     *
     * @param int        $post_id      Target post ID.
     * @param array      $data         Canonical record data.
     * @param array|null $truncations  Accumulator: appended with overflow info.
     * @return bool True when keywords were written.
     */
    private function migrate_focus_keywords(int $post_id, array $data, ?array &$truncations = null): bool {
        $keywords = [];
        if (!empty($data['focus_keywords']) && is_array($data['focus_keywords'])) {
            $keywords = $data['focus_keywords'];
        } elseif (!empty($data['focus_keyword'])) {
            $keywords = [$data['focus_keyword']];
        }

        if (empty(Focus_Keywords::normalize($keywords, 0))) {
            return false;
        }

        // Never overwrite existing ThinkRank focus keywords.
        if (!empty(Focus_Keywords::get($post_id))) {
            return false;
        }

        $result = Focus_Keywords::save_with_overflow($post_id, $keywords);

        if (!empty($result['overflow']) && is_array($truncations)) {
            $truncations[] = [
                'post_id' => $post_id,
                'kept'    => count($result['kept']),
                'gated'   => $result['overflow'],
            ];
        }

        return !empty($result['kept']);
    }

    /**
     * Seed the metabox Review schema form data for an imported review post.
     *
     * Only runs when the record's schema type resolved to 'Review'. Writes the
     * carried `review_*` fields (from the snapshot's extended.review_schema) as
     * the JSON `_thinkrank_schema_form_data` the metabox Review form reads, so
     * the rating survives the import and renders once the user deploys it.
     * Never overwrites existing ThinkRank schema form data.
     *
     * @param int   $post_id Target post ID
     * @param array $data    Canonical record data
     * @param array $record  Full snapshot record (for the extended payload)
     * @return bool True when form data was written
     */
    private function migrate_review_schema(int $post_id, array $data, array $record): bool {
        if (($data['schema_type'] ?? '') !== 'Review') {
            return false;
        }

        $review = $record['extended']['review_schema'] ?? [];
        if (empty($review) || !is_array($review)) {
            return false;
        }

        // Never overwrite existing ThinkRank schema form data.
        $existing = get_post_meta($post_id, '_thinkrank_schema_form_data', true);
        if (is_string($existing) && $existing !== '') {
            return false;
        }

        update_post_meta($post_id, '_thinkrank_schema_form_data', wp_json_encode($review));

        return true;
    }

    /**
     * Seed the metabox Video schema form data for an imported VideoObject post.
     *
     * Only runs when the record's schema type resolved to 'VideoObject'. Writes
     * the carried `video_*` fields (from the snapshot's extended.video_schema) as
     * the JSON `_thinkrank_schema_form_data` the metabox Video form reads, so the
     * video details survive the import. Never overwrites existing schema form data.
     *
     * @param int   $post_id Target post ID
     * @param array $data    Canonical record data
     * @param array $record  Full snapshot record (for the extended payload)
     * @return bool True when form data was written
     */
    private function migrate_video_schema(int $post_id, array $data, array $record): bool {
        if (($data['schema_type'] ?? '') !== 'VideoObject') {
            return false;
        }

        $video = $record['extended']['video_schema'] ?? [];
        if (empty($video) || !is_array($video)) {
            return false;
        }

        // Never overwrite existing ThinkRank schema form data.
        $existing = get_post_meta($post_id, '_thinkrank_schema_form_data', true);
        if (is_string($existing) && $existing !== '') {
            return false;
        }

        update_post_meta($post_id, '_thinkrank_schema_form_data', wp_json_encode($video));

        return true;
    }

    /**
     * Compose and persist the per-post robots payload.
     *
     * Folds the canonical robots flags into JSON-encoded
     * `_thinkrank_robots_meta` and `_thinkrank_advanced_robots_meta`
     * post meta and flips the override toggle when at least one
     * directive is present. Never overwrites existing ThinkRank data.
     *
     * @param int   $post_id Target post ID
     * @param array $data    Canonical record data
     * @return bool True when at least one robots field was written
     */
    private function migrate_robots_payload(int $post_id, array $data): bool {
        $existing_payload = get_post_meta($post_id, '_thinkrank_robots_meta', true);
        if (is_string($existing_payload) && $existing_payload !== '') {
            return false;
        }

        $robots = [];
        foreach (self::ROBOTS_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === '' || $value === null) {
                continue;
            }
            $robots[$field] = (bool) (int) $value;
        }

        $advanced = [];
        foreach (self::ADVANCED_ROBOTS_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === '' || $value === null) {
                continue;
            }
            if ($field === 'max_image_preview') {
                $allowed = ['none', 'standard', 'large'];
                $value = in_array($value, $allowed, true) ? $value : 'large';
                $advanced[$field] = $value;
                $advanced['image_preview_enabled'] = $value !== 'none';
                continue;
            }
            $advanced[$field] = (int) $value;
            if ($field === 'max_snippet') {
                $advanced['snippet_enabled'] = (int) $value !== 0;
            } elseif ($field === 'max_video_preview') {
                $advanced['video_preview_enabled'] = (int) $value !== 0;
            }
        }

        // The source exporter emits every robots flag (0/1) for every post, so
        // $robots is rarely empty. Only persist a robots override when at least
        // one directive is actually active (or an advanced directive exists);
        // an all-false array equals ThinkRank's default index/follow and must
        // not flip robots_meta_enabled on for posts that had no directive.
        $has_active_directive = false;
        foreach ($robots as $flag) {
            if ($flag) {
                $has_active_directive = true;
                break;
            }
        }
        if (!$has_active_directive && empty($advanced)) {
            return false;
        }

        // Default index=true unless noindex was explicitly imported.
        if (!isset($robots['index'])) {
            $robots['index'] = empty($robots['noindex']);
        }

        $wrote = false;
        if (!empty($robots)) {
            update_post_meta($post_id, '_thinkrank_robots_meta', wp_json_encode($robots));
            $wrote = true;
        }
        if (!empty($advanced)) {
            update_post_meta($post_id, '_thinkrank_advanced_robots_meta', wp_json_encode($advanced));
            $wrote = true;
        }
        if ($wrote) {
            update_post_meta($post_id, '_thinkrank_robots_meta_enabled', 1);
        }

        return $wrote;
    }

    /**
     * Migrate settings from snapshot
     *
     * @param string $plugin Plugin slug
     * @return array Result
     */
    private function migrate_settings(string $plugin): array {
        $chunk = Snapshot_Store::read_chunk($plugin, 'settings', 1);
        if ($chunk === null || empty($chunk)) {
            return [
                'status'    => 'complete',
                'message'   => 'No settings to migrate',
                'has_more'  => false,
                'processed' => 0,
                'skipped'   => 0,
            ];
        }

        $settings_record = $chunk[0] ?? [];
        $data = $settings_record['data'] ?? [];
        $extended = $settings_record['extended'] ?? [];
        $processed = 0;

        // Map settings to ThinkRank options
        if (!empty($data['separator'])) {
            $global_seo = get_option('thinkrank_global_seo_settings', []);
            if (empty($global_seo['separator'])) {
                $global_seo['separator'] = $data['separator'];
                update_option('thinkrank_global_seo_settings', $global_seo);
                $processed++;
            }
        }

        if (!empty($data['homepage_title']) || !empty($data['homepage_description']) || !empty($data['organization_name']) || !empty($data['organization_logo'])) {
            $site_identity = get_option('thinkrank_site_identity_settings', []);
            $updated = false;

            if (!empty($data['homepage_title']) && empty($site_identity['homepage_title'])) {
                $site_identity['homepage_title'] = $data['homepage_title'];
                $updated = true;
            }
            if (!empty($data['homepage_description']) && empty($site_identity['homepage_description'])) {
                $site_identity['homepage_description'] = $data['homepage_description'];
                $updated = true;
            }
            if (!empty($data['organization_name']) && empty($site_identity['organization_name'])) {
                $site_identity['organization_name'] = $data['organization_name'];
                $updated = true;
            }
            if (!empty($data['organization_logo']) && empty($site_identity['organization_logo'])) {
                $site_identity['organization_logo'] = $data['organization_logo'];
                $updated = true;
            }

            if ($updated) {
                update_option('thinkrank_site_identity_settings', $site_identity);
                $processed++;
            }
        }

        if (!empty($data['social_profiles'])) {
            $social = get_option('thinkrank_social_media_settings', []);
            $updated = false;

            foreach ($data['social_profiles'] as $platform => $url) {
                if (!empty($url) && empty($social[$platform])) {
                    $social[$platform] = $url;
                    $updated = true;
                }
            }

            if ($updated) {
                update_option('thinkrank_social_media_settings', $social);
                $processed++;
            }
        }

        if (!empty($data['noindex_archives'])) {
            $robot_meta = get_option('thinkrank_global_robot_meta_settings', []);
            $updated = false;

            if (!empty($data['noindex_archives']['date']) && empty($robot_meta['noindex_date_archives'])) {
                $robot_meta['noindex_date_archives'] = true;
                $updated = true;
            }
            if (!empty($data['noindex_archives']['author']) && empty($robot_meta['noindex_author_archives'])) {
                $robot_meta['noindex_author_archives'] = true;
                $updated = true;
            }

            if ($updated) {
                update_option('thinkrank_global_robot_meta_settings', $robot_meta);
                $processed++;
            }

            // Author-archive noindex has an effective home in ThinkRank: the core
            // author_archives_index setting the Author Archives feature consults
            // (the global_robot_meta keys above are not read for archives).
            if (!empty($data['noindex_archives']['author']) && class_exists('ThinkRank\\Core\\Settings')) {
                $settings = \ThinkRank\Core\Settings::instance();
                if ($settings->get('author_archives_index', true)) {
                    $settings->set('author_archives_index', false);
                    $processed++;
                }
            }
        }

        // Twitter card default.
        if ($this->migrate_twitter_card($data)) {
            $processed++;
        }

        // Site-wide social defaults (Facebook App ID, default OG image).
        if ($this->migrate_social_defaults($data)) {
            $processed++;
        }

        // Pinterest site verification — the only webmaster-tools code
        // ThinkRank renders today. The rest of extended.webmaster_tools stays
        // preserved in the snapshot (and gates cleanup).
        if ($this->migrate_pinterest_verification($extended)) {
            $processed++;
        }

        // Per-post-type title/description templates and (active) robots defaults.
        if (!empty($extended['post_type_settings']) && is_array($extended['post_type_settings'])) {
            if ($this->migrate_post_type_settings($extended['post_type_settings'])) {
                $processed++;
            }
        }

        // Site-identity settings (homepage/org/breadcrumbs/local SEO) are served to
        // the frontend from the wp_thinkrank_seo_settings table via the manager, not
        // from the option written above — route them through the manager so they
        // actually take effect.
        if ($this->migrate_site_identity($data, $extended)) {
            $processed++;
        }

        // Image SEO auto alt/title generation settings.
        if ($this->migrate_image_seo($extended)) {
            $processed++;
        }

        // Sitemap inclusion settings.
        if ($this->migrate_sitemap($extended)) {
            $processed++;
        }

        // Knowledge Graph entity (organization/person name) into schema settings.
        if ($this->migrate_knowledge_graph($data)) {
            $processed++;
        }

        // IndexNow API key + auto-submit post types into Instant Indexing.
        if ($this->migrate_instant_indexing($data, $extended)) {
            $processed++;
        }

        // Author archive behaviour (enabled / title / meta description).
        if ($this->migrate_author_archives($extended)) {
            $processed++;
        }

        // Scheduled SEO email report cadence.
        if ($this->migrate_email_reports($extended)) {
            $processed++;
        }

        // Role Manager: per-role access to ThinkRank's admin areas.
        if ($this->migrate_role_capabilities($extended)) {
            $processed++;
        }

        // Past IndexNow submissions into the Instant Indexing history table.
        if ($this->migrate_instant_indexing_log($extended) > 0) {
            $processed++;
        }

        // News/Video sitemap post types into Pro's Publisher Sitemaps.
        if ($this->migrate_publisher_sitemaps($extended)) {
            $processed++;
        }

        return [
            'status'    => 'complete',
            'message'   => sprintf('Migrated %d settings groups', $processed),
            'has_more'  => false,
            'processed' => $processed,
            'skipped'   => 0,
        ];
    }

    /**
     * Migrate site-identity settings (homepage title, organization, breadcrumbs,
     * local SEO) into the wp_thinkrank_seo_settings table via Site_Identity_Manager,
     * which is what the frontend actually reads. Non-destructive: a value is only
     * written when ThinkRank still holds its default seed (or is empty), so user
     * customizations are preserved.
     *
     * @param array $data     Canonical settings `data` payload
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if any value was written
     */
    private function migrate_site_identity(array $data, array $extended): bool {
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
            return false;
        }

        $manager = new \ThinkRank\SEO\Site_Identity_Manager();
        $current = $manager->get_settings('site');

        // ThinkRank default seeds — only overwrite a value the user has not changed.
        $seeds = [
            'homepage_title'       => '%site_title% | %site_description%',
            'site_name'            => get_bloginfo('name'),
            'logo_url'             => '',
            'breadcrumb_home_text' => 'Home',
            'breadcrumb_separator' => '>',
            'business_type'        => '',
            'business_name'        => '',
            'business_phone'       => '',
        ];

        $updates = [];
        $set = static function (string $key, $value) use (&$updates, $current, $seeds): void {
            if ($value === '' || $value === null) {
                return;
            }
            $cur = $current[$key] ?? null;
            $is_default = !array_key_exists($key, $current) || $cur === '' || $cur === ($seeds[$key] ?? null);
            if ($is_default) {
                $updates[$key] = $value;
            }
        };

        // Homepage title + organization (organization maps onto site identity's
        // site_name / logo_url, which schema output uses as its fallback source).
        // A per-context title format (extended.title_formats.homepage_title) is a
        // real template and beats the literal-resolved data.homepage_title, so it
        // wins when the source provided one.
        $title_formats = is_array($extended['title_formats'] ?? null) ? $extended['title_formats'] : [];
        $set('homepage_title', $title_formats['homepage_title'] ?? ($data['homepage_title'] ?? ''));
        $set('site_name', $data['organization_name'] ?? '');
        $set('alternate_name', $data['alternate_name'] ?? '');
        $set('logo_url', $data['organization_logo'] ?? '');

        // Title separator. ThinkRank stores a KEY ('dash'), not the symbol Rank
        // Math stores ('-'), and every migrated %sep% template renders through it
        // — so an unmapped separator silently changes every title.
        $separator_key = $this->map_separator_symbol((string) ($data['separator'] ?? ''));
        if ($separator_key !== '' && ($current['title_separator'] ?? 'pipe') === 'pipe') {
            $updates['title_separator'] = $separator_key;
        }

        // Knowledge Graph entity → what this site "represents" (wizard field).
        $kg_type = (string) ($data['knowledge_graph']['type'] ?? '');
        if ($kg_type !== '' && empty($current['represents'])) {
            $updates['represents'] = $kg_type === 'person' ? 'person' : 'organization';
        }

        // Per-context title formats (Post/Page/Category/Tag/Search/Archive).
        foreach (['post_title', 'page_title', 'category_title', 'tag_title', 'search_title', 'archive_title'] as $key) {
            $set($key, $title_formats[$key] ?? '');
        }

        // The author-archive title has two readers: site identity's `author_title`
        // (the front-end title renderer's 'author' context) and the Author Archives
        // feature's own `author_archives_title`, written by migrate_author_archives().
        $set('author_title', $extended['author_archives']['title'] ?? '');

        // Breadcrumbs (extended.breadcrumb_settings) — replicate Rank Math's
        // enabled state when ThinkRank breadcrumbs are still at their default.
        $breadcrumbs = $extended['breadcrumb_settings'] ?? [];
        if (!empty($breadcrumbs)) {
            // Replicate Rank Math's on/off state while ThinkRank breadcrumbs are
            // still at their default (enabled). Cast loosely — the stored value may
            // be '1'/'' rather than a real boolean.
            if (filter_var($current['breadcrumbs_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                $updates['breadcrumbs_enabled'] = !empty($breadcrumbs['enabled']);
            }
            $set('breadcrumb_home_text', $breadcrumbs['home_label'] ?? '');
            $set('breadcrumb_separator', $breadcrumbs['separator'] ?? '');
            $set('breadcrumb_prefix', $breadcrumbs['prefix'] ?? '');
        }

        // Local SEO (extended.local_seo) — migrate the full NAP + geo when there
        // is any meaningful business data (name, phone, address or coordinates),
        // and enable the feature alongside it. Each field is written only while
        // ThinkRank's Business Info still holds its default (non-destructive).
        $local = $extended['local_seo'] ?? [];
        $address = is_array($local['address'] ?? null) ? $local['address'] : [];
        $geo     = is_array($local['geo'] ?? null) ? $local['geo'] : [];
        $hours   = is_array($local['opening_hours'] ?? null) ? $local['opening_hours'] : [];
        $has_local = !empty($local['business_name']) || !empty($local['phone'])
            || !empty($address) || !empty($geo) || !empty($hours)
            || !empty($local['price_range']);

        // Local SEO lands in its own $local_updates batch, saved separately from
        // the identity batch. Site_Identity_Manager::save_settings() validates the
        // whole payload and aborts ALL writes when any field is invalid — and
        // `local_seo_enabled` makes `business_name` mandatory. Mixing the two
        // batches meant a source with opening hours but no business name (Rank
        // Math's default Local SEO state) failed validation and silently
        // discarded the homepage title, logo, breadcrumbs and separator too.
        $local_updates = [];
        if ($has_local) {
            $set_local = static function (string $key, $value) use (&$local_updates, $current, $seeds): void {
                if ($value === '' || $value === null) {
                    return;
                }
                $cur = $current[$key] ?? null;
                $is_default = !array_key_exists($key, $current) || $cur === '' || $cur === ($seeds[$key] ?? null);
                if ($is_default) {
                    $local_updates[$key] = $value;
                }
            };

            $set_local('business_type', $local['business_type'] ?? '');
            $set_local('business_name', $local['business_name'] ?? '');
            $set_local('business_phone', $local['phone'] ?? '');

            // Postal address (schema.org PostalAddress → ThinkRank Business Info).
            $set_local('business_address', $address['street'] ?? '');
            $set_local('business_city', $address['city'] ?? '');
            $set_local('business_state', $address['state'] ?? '');
            $set_local('business_postal_code', $address['postal_code'] ?? '');
            $set_local('business_country', $address['country'] ?? '');

            // Geo coordinates.
            $set_local('business_latitude', $geo['latitude'] ?? '');
            $set_local('business_longitude', $geo['longitude'] ?? '');

            // Price range (scalar, e.g. "$$").
            $set_local('business_price_range', $local['price_range'] ?? '');

            // Opening hours are a per-day array, so the scalar-tuned helper
            // doesn't apply — write directly while ThinkRank still holds no hours.
            if (!empty($hours) && empty($current['business_hours'])) {
                $local_updates['business_hours'] = $hours;
            }

            // Only turn the feature ON when the business name it requires is
            // actually present (either carried over now or already stored).
            $has_name = !empty($local_updates['business_name']) || !empty($current['business_name']);
            if ($has_name && empty($current['local_seo_enabled'])) {
                $local_updates['local_seo_enabled'] = true;
            }
        }

        $wrote = false;

        if (!empty($updates) && $manager->save_settings('site', null, $updates)) {
            $wrote = true;
        }

        if (!empty($local_updates) && $manager->save_settings('site', null, $local_updates)) {
            $wrote = true;
        }

        return $wrote;
    }

    /**
     * Map a title-separator SYMBOL (what source plugins store) to ThinkRank's
     * separator KEY (what Site_Identity_Manager stores and renders %sep% from).
     *
     * @param string $symbol Raw separator symbol, e.g. '-'
     * @return string ThinkRank separator key, or '' when unmapped
     */
    private function map_separator_symbol(string $symbol): string {
        $symbol = trim(html_entity_decode($symbol, ENT_QUOTES, 'UTF-8'));
        if ($symbol === '') {
            return '';
        }

        $map = [
            '|' => 'pipe',
            '-' => 'dash',
            '–' => 'dash',
            '—' => 'dash',
            '•' => 'bullet',
            ':' => 'colon',
            '>' => 'greater',
            '~' => 'tilde',
        ];

        return $map[$symbol] ?? '';
    }

    /**
     * Migrate Rank Math's global Twitter card type into ThinkRank's Social Meta
     * settings (wp_thinkrank_seo_settings via Social_Meta_Manager). Non-destructive:
     * only written while ThinkRank still holds its default card type.
     *
     * @param array $data Canonical settings `data` payload
     * @return bool True if written
     */
    private function migrate_twitter_card(array $data): bool {
        $card_type = $data['twitter_card_type'] ?? '';
        if ($card_type === '' || !class_exists('ThinkRank\\SEO\\Social_Meta_Manager')) {
            return false;
        }

        $manager = new \ThinkRank\SEO\Social_Meta_Manager();
        $current = $manager->get_settings('site');

        // ThinkRank default card type — only overwrite while unchanged.
        if (($current['twitter_card_type'] ?? 'summary_large_image') !== 'summary_large_image') {
            return false;
        }
        if ($card_type === 'summary_large_image') {
            return false; // Identical to ThinkRank's default — nothing to change.
        }

        return $manager->save_settings('site', null, ['twitter_card_type' => $card_type]);
    }

    /**
     * Migrate site-wide social defaults (Facebook App ID, default OG image)
     * into ThinkRank's Social Meta settings. Non-destructive: each value is
     * written only while ThinkRank still holds none.
     *
     * @param array $data Canonical settings `data` payload
     * @return bool True if anything was written
     */
    private function migrate_social_defaults(array $data): bool {
        $defaults = $data['social_defaults'] ?? [];
        if (!is_array($defaults) || !class_exists('ThinkRank\\SEO\\Social_Meta_Manager')) {
            return false;
        }

        $app_id = trim((string) ($defaults['facebook_app_id'] ?? ''));
        $og_image = trim((string) ($defaults['og_default_image'] ?? ''));
        if ($app_id === '' && $og_image === '') {
            return false;
        }

        $manager = new \ThinkRank\SEO\Social_Meta_Manager();
        $current = $manager->get_settings('site');

        $updates = [];
        if ($app_id !== '' && empty($current['facebook_app_id'])) {
            $updates['facebook_app_id'] = $app_id;
        }
        if ($og_image !== '' && empty($current['default_image'])) {
            $updates['default_image'] = $og_image;
        }

        if (empty($updates)) {
            return false;
        }

        return (bool) $manager->save_settings('site', null, $updates);
    }

    /**
     * Migrate the source plugin's Pinterest site-verification code into
     * ThinkRank's core `pinterest_site_verification` setting. Never overwrites
     * a configured code.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if written
     */
    private function migrate_pinterest_verification(array $extended): bool {
        $code = trim((string) ($extended['webmaster_tools']['pinterest'] ?? ''));
        if ($code === '' || !class_exists('ThinkRank\\Core\\Settings')) {
            return false;
        }

        $settings = \ThinkRank\Core\Settings::instance();
        if ((string) $settings->get('pinterest_site_verification', '') !== '') {
            return false;
        }

        $settings->set('pinterest_site_verification', $code);

        return true;
    }

    /**
     * Build the schema settings manager, when available.
     *
     * Split out (and protected) so the shim-based unit tests can substitute a
     * fake manager — the real one persists to the wp_thinkrank_seo_settings
     * table, which needs a live database.
     *
     * @return object|null Schema_Management_System instance, or null when unavailable
     */
    protected function create_schema_manager(): ?object {
        if (!class_exists('ThinkRank\\SEO\\Schema_Management_System')) {
            return null;
        }

        return new \ThinkRank\SEO\Schema_Management_System();
    }

    /**
     * Migrate the source plugin's Knowledge Graph entity into ThinkRank's schema
     * settings (wp_thinkrank_seo_settings via Schema_Management_System).
     *
     * Rank Math's knowledgegraph_type is 'company' or 'person' (normalized to
     * 'organization'/'person' by the exporter). ThinkRank's schema settings model
     * the same split: organization_* fields (organization_type already defaults
     * to 'Organization', matching 'company') and person_* fields. The entity
     * name lands in organization_name or person_name accordingly. Non-destructive:
     * a value is only written while ThinkRank still holds no value for it.
     *
     * @param array $data Canonical settings `data` payload
     * @return bool True if any value was written
     */
    private function migrate_knowledge_graph(array $data): bool {
        $kg = $data['knowledge_graph'] ?? [];
        if (!is_array($kg)) {
            return false;
        }

        $type = (string) ($kg['type'] ?? '');
        $name = trim((string) ($kg['name'] ?? ''));
        if ($type === '' || $name === '') {
            return false;
        }

        $manager = $this->create_schema_manager();
        if ($manager === null) {
            return false;
        }

        $current = $manager->get_settings('site');
        $updates = [];

        if ($type === 'person') {
            if (empty($current['person_name'])) {
                $updates['person_name'] = $name;
            }
        } else {
            // 'organization' — organization_type's default ('Organization')
            // already matches Rank Math's 'company', so only the name needs
            // a home. Fill it while ThinkRank still holds none.
            if (empty($current['organization_name'])) {
                $updates['organization_name'] = $name;
            }
        }

        if (empty($updates)) {
            return false;
        }

        return (bool) $manager->save_settings('site', null, $updates);
    }

    /**
     * Migrate the source plugin's IndexNow API key into ThinkRank's Instant
     * Indexing settings (thinkrank_instant_indexing_settings['api_key'], read by
     * Instant_Indexing_Manager). Carrying the key over avoids re-verifying the
     * site with IndexNow ({key}.txt is already served for it).
     *
     * Never clobbers a configured key: ThinkRank generates its own key on
     * activation, so this only fills the slot when it is genuinely empty/unset.
     *
     * Also carries the source's auto-submit post types
     * (extended.instant_indexing_post_types) so publishing keeps pinging the
     * same content types it did before the switch.
     *
     * @param array $data     Canonical settings `data` payload
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if anything was written
     */
    private function migrate_instant_indexing(array $data, array $extended = []): bool {
        $api_key = trim((string) ($data['instant_indexing']['api_key'] ?? ''));
        $source_types = $extended['instant_indexing_post_types'] ?? [];
        $source_types = is_array($source_types) ? $source_types : [];

        if ($api_key === '' && empty($source_types)) {
            return false;
        }

        $settings = get_option('thinkrank_instant_indexing_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $wrote = false;

        // Auto-submit post types. ThinkRank seeds ['post','page'] on activation,
        // so only replace that untouched seed — never a user's own selection.
        if (!empty($source_types)) {
            $types = [];
            foreach ($source_types as $type) {
                $type = sanitize_key((string) $type);
                if ($type !== '' && post_type_exists($type)) {
                    $types[] = $type;
                }
            }
            $types = array_values(array_unique($types));

            $current_types = $settings['auto_submit_post_types'] ?? null;
            $is_seed = $current_types === null
                || (is_array($current_types) && array_diff($current_types, ['post', 'page']) === []
                    && array_diff(['post', 'page'], $current_types) === []);

            if (!empty($types) && $is_seed && $types !== $current_types) {
                $settings['auto_submit_post_types'] = $types;
                $wrote = true;
            }
        }

        if ($api_key === '') {
            if ($wrote) {
                update_option('thinkrank_instant_indexing_settings', $settings);
            }

            return $wrote;
        }

        // Only migrate the key when the target is empty/unset — never overwrite.
        if (empty($settings['api_key'])) {
            $settings['api_key'] = $api_key;
            $wrote = true;
        }

        if ($wrote) {
            update_option('thinkrank_instant_indexing_settings', $settings);
        }

        return $wrote;
    }

    /**
     * Migrate a chunk of redirection rules into ThinkRank Pro's Redirections.
     *
     * Pro-gated: the redirect table belongs to ThinkRank Pro, so this is a no-op
     * (reported as skipped, never as an error) when Pro is inactive. The snapshot
     * keeps the records either way, so activating Pro and re-running the
     * migration picks them up. Referenced only through string class names so the
     * free plugin never hard-depends on Pro.
     *
     * @param string $plugin Plugin slug
     * @param int    $page   Chunk number
     * @return array Migration result
     */
    private function migrate_redirections(string $plugin, int $page): array {
        $chunk = Snapshot_Store::read_chunk($plugin, 'redirections', $page);
        if ($chunk === null || empty($chunk)) {
            return [
                'status'    => 'complete',
                'message'   => 'No redirections in chunk',
                'has_more'  => false,
                'processed' => 0,
                'skipped'   => 0,
            ];
        }

        $store = $this->create_redirections_store();
        if ($store === null || !method_exists($store, 'import_redirect')) {
            return [
                'status'    => 'complete',
                'message'   => sprintf(
                    'Skipped %d redirections — ThinkRank Pro (Redirections) is not active. They stay in the snapshot.',
                    count($chunk)
                ),
                'has_more'  => false,
                'processed' => 0,
                'skipped'   => count($chunk),
            ];
        }

        $processed = 0;
        $skipped = 0;

        foreach ($chunk as $record) {
            $r = $record['extended'] ?? [];
            $source = trim((string) ($r['source_url'] ?? ''));
            if ($source === '') {
                $skipped++;
                continue;
            }

            // Pre-`match_type` snapshots only carried the `is_regex` boolean.
            $match_type = (string) ($r['match_type'] ?? (!empty($r['is_regex']) ? 'regex' : 'exact'));

            $id = $store->import_redirect([
                'source_url'    => $source,
                'match_type'    => $match_type,
                'target_url'    => (string) ($r['target_url'] ?? ''),
                'http_code'     => (int) ($r['http_code'] ?? 301),
                'status'        => !empty($r['enabled']) ? 'active' : 'inactive',
                'hits'          => (int) ($r['hits'] ?? 0),
                'created_at'    => (string) ($r['created_at'] ?? ''),
                'last_accessed' => (string) ($r['last_accessed'] ?? ''),
            ]);

            if ($id > 0) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        return [
            'status'    => 'complete',
            'message'   => sprintf('Migrated %d redirections, skipped %d (page %d)', $processed, $skipped, $page),
            'has_more'  => false,
            'processed' => $processed,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Migrate a chunk of logged 404 hits into ThinkRank Pro's 404 Monitor.
     * Pro-gated exactly like migrate_redirections().
     *
     * @param string $plugin Plugin slug
     * @param int    $page   Chunk number
     * @return array Migration result
     */
    private function migrate_404_logs(string $plugin, int $page): array {
        $chunk = Snapshot_Store::read_chunk($plugin, '404_logs', $page);
        if ($chunk === null || empty($chunk)) {
            return [
                'status'    => 'complete',
                'message'   => 'No 404 logs in chunk',
                'has_more'  => false,
                'processed' => 0,
                'skipped'   => 0,
            ];
        }

        $store = $this->create_redirections_store();
        if ($store === null || !method_exists($store, 'import_404_log')) {
            return [
                'status'    => 'complete',
                'message'   => sprintf(
                    'Skipped %d 404 logs — ThinkRank Pro (404 Monitor) is not active. They stay in the snapshot.',
                    count($chunk)
                ),
                'has_more'  => false,
                'processed' => 0,
                'skipped'   => count($chunk),
            ];
        }

        $processed = 0;
        $skipped = 0;

        foreach ($chunk as $record) {
            $log = $record['extended'] ?? [];
            if ($store->import_404_log([
                'uri'            => (string) ($log['uri'] ?? ''),
                'times_accessed' => (int) ($log['times_accessed'] ?? 1),
                'referer'        => (string) ($log['referer'] ?? ''),
                'user_agent'     => (string) ($log['user_agent'] ?? ''),
                'last_accessed'  => (string) ($log['last_accessed'] ?? ''),
            ])) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        return [
            'status'    => 'complete',
            'message'   => sprintf('Migrated %d 404 logs, skipped %d (page %d)', $processed, $skipped, $page),
            'has_more'  => false,
            'processed' => $processed,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Build ThinkRank Pro's Redirections store, when Pro is active.
     *
     * Split out (and protected) so tests can substitute a fake — the real store
     * writes to Pro's tables. Pro lazily creates them via Schema::ensure().
     *
     * @return object|null Store instance, or null when Pro is unavailable
     */
    protected function create_redirections_store(): ?object {
        if (
            !class_exists('ThinkRank\\Pro\\Redirections\\Schema')
            || !class_exists('ThinkRank\\Pro\\Redirections\\Store')
        ) {
            return null;
        }

        \ThinkRank\Pro\Redirections\Schema::ensure();

        return new \ThinkRank\Pro\Redirections\Store();
    }

    /**
     * Migrate the source plugin's author-archive behaviour into ThinkRank's
     * Author Archives settings (core Settings keys read by
     * Author_Archives_Manager).
     *
     * The noindex flag is handled separately in migrate_settings() via
     * `data.noindex_archives.author`; this covers whether archives exist at all
     * and the title / meta-description templates they render with.
     * Non-destructive: each key is written only while ThinkRank still holds its
     * default.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if any value was written
     */
    private function migrate_author_archives(array $extended): bool {
        $author = $extended['author_archives'] ?? [];
        if (!is_array($author) || empty($author) || !class_exists('ThinkRank\\Core\\Settings')) {
            return false;
        }

        $settings = \ThinkRank\Core\Settings::instance();
        $wrote = false;

        // Rank Math's "disable author archives" → ThinkRank's positive `enabled`.
        // Only act on a disable; leaving them on is already ThinkRank's default.
        if (array_key_exists('enabled', $author) && !$author['enabled']
            && $settings->get('author_archives_enabled', true)) {
            $settings->set('author_archives_enabled', false);
            $wrote = true;
        }

        $title = trim((string) ($author['title'] ?? ''));
        if ($title !== '' && $settings->get('author_archives_title', '') === '') {
            $settings->set('author_archives_title', $title);
            $wrote = true;
        }

        $description = trim((string) ($author['description'] ?? ''));
        if ($description !== '' && $settings->get('author_archives_meta_desc', '') === '') {
            $settings->set('author_archives_meta_desc', $description);
            $wrote = true;
        }

        return $wrote;
    }

    /**
     * Migrate the source plugin's IndexNow submission history into ThinkRank's
     * `thinkrank_instant_indexing_logs` table, so the Instant Indexing history
     * screen is not blank after switching.
     *
     * Idempotent: an entry is skipped when a row with the same URL and
     * timestamp already exists, so re-running never double-counts.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return int Number of entries written
     */
    private function migrate_instant_indexing_log(array $extended): int {
        global $wpdb;

        $log = $extended['instant_indexing_log'] ?? [];
        $entries = is_array($log['entries'] ?? null) ? $log['entries'] : [];
        if (empty($entries)) {
            return 0;
        }

        $table = $wpdb->prefix . 'thinkrank_instant_indexing_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return 0;
        }

        $written = 0;
        foreach ($entries as $entry) {
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $created_at = (string) ($entry['submitted_at'] ?? '');
            if ($created_at === '') {
                $created_at = current_time('mysql');
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE url = %s AND created_at = %s LIMIT 1", $url, $created_at)
            );
            if ($exists) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted = $wpdb->insert(
                $table,
                [
                    'url'              => $url,
                    'status'           => (string) ($entry['status'] ?? 'failed'),
                    'response_code'    => (int) ($entry['response_code'] ?? 0),
                    'response_message' => (string) ($entry['response_message'] ?? ''),
                    'created_at'       => $created_at,
                ],
                ['%s', '%s', '%d', '%s', '%s']
            );

            if ($inserted) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Migrate the source plugin's News/Video sitemap post types into ThinkRank
     * Pro's Publisher Sitemaps settings.
     *
     * Pro-gated via string class names so the free plugin never hard-depends on
     * Pro, and non-destructive: a list is written only while Pro still holds its
     * default for it.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if any list was written
     */
    private function migrate_publisher_sitemaps(array $extended): bool {
        $source = $extended['publisher_sitemaps'] ?? [];
        if (!is_array($source) || empty($source)
            || !class_exists('ThinkRank\\Pro\\Sitemaps\\Settings')) {
            return false;
        }

        $settings = new \ThinkRank\Pro\Sitemaps\Settings();
        $current = $settings->get();
        $defaults = \ThinkRank\Pro\Sitemaps\Settings::defaults();

        $updates = [];
        foreach (['video_post_types', 'news_post_types'] as $key) {
            if (empty($source[$key]) || !is_array($source[$key])) {
                continue;
            }

            // Only replace Pro's untouched default — never a user's selection.
            if (($current[$key] ?? null) !== ($defaults[$key] ?? null)) {
                continue;
            }

            $types = [];
            foreach ($source[$key] as $type) {
                $type = sanitize_key((string) $type);
                if ($type !== '' && post_type_exists($type)) {
                    $types[] = $type;
                }
            }
            $types = array_values(array_unique($types));

            if (!empty($types) && $types !== ($current[$key] ?? null)) {
                $updates[$key] = $types;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $settings->save($updates);

        return true;
    }

    /**
     * Source-plugin capability => the ThinkRank capabilities it corresponds to.
     *
     * Deliberately conservative: a role only gains an area when the source
     * plainly granted the equivalent one. Over-granting here is a privilege
     * escalation, while under-granting is a re-tick in the Role Manager UI, so
     * ambiguous cases are left out and reported instead.
     *
     * Notably absent:
     * - `thinkrank_settings` (Settings & API Keys) — it exposes AI provider keys
     *   and the Google connection, a class of secret neither source plugin ever
     *   held. Rank Math's nearest cap (`rank_math_general`) is a grab-bag and
     *   Yoast's (`wpseo_manage_options`) is plugin-wide, so neither is specific
     *   enough to justify handing over credentials: this stays administrator-only
     *   after an import and must be granted by hand.
     * - Redirections / 404 Monitor — ThinkRank models no capability for them, so
     *   `rank_math_redirections`, `rank_math_404_monitor` and Yoast Premium's
     *   `wpseo_manage_redirects` have nowhere to land.
     * - `rank_math_admin_bar`, `rank_math_edit_htaccess` — no equivalent.
     */
    private const ROLE_CAPABILITY_MAP = [
        // Titles & Meta / Search Appearance.
        'rank_math_titles'           => ['thinkrank_site_identity', 'thinkrank_global_seo', 'thinkrank_author_archives'],
        // General Settings holds Rank Math's Images and Instant Indexing panels.
        'rank_math_general'          => ['thinkrank_image_seo', 'thinkrank_instant_indexing'],
        'rank_math_sitemap'          => ['thinkrank_crawling'],
        'rank_math_analytics'        => ['thinkrank_analytics', 'thinkrank_performance'],
        'rank_math_site_analysis'    => ['thinkrank_analytics'],
        'rank_math_content_ai'       => ['thinkrank_content_tools'],
        'rank_math_link_builder'     => ['thinkrank_internal_links'],
        // Per-post metabox tabs.
        'rank_math_onpage_analysis'  => ['thinkrank_content_tools'],
        'rank_math_onpage_snippet'   => ['thinkrank_schema'],
        'rank_math_onpage_social'    => ['thinkrank_social_media'],
        'rank_math_onpage_advanced'  => ['thinkrank_crawling'],
        'rank_math_role_manager'     => ['thinkrank_manage_roles'],

        // Yoast. Its capability set is much coarser — three caps cover the whole
        // plugin — so `wpseo_manage_options` fans out to the areas it genuinely
        // controlled in Yoast (titles, social, schema, crawl, sitemaps). It does
        // NOT imply `thinkrank_manage_roles`: Yoast has no role-manager screen,
        // so nothing in the source says that role was trusted to grant access to
        // others.
        'wpseo_manage_options'         => [
            'thinkrank_site_identity', 'thinkrank_global_seo', 'thinkrank_social_media',
            'thinkrank_schema', 'thinkrank_crawling', 'thinkrank_analytics',
            'thinkrank_image_seo', 'thinkrank_instant_indexing', 'thinkrank_author_archives',
        ],
        // Yoast's bulk title/description editor.
        'wpseo_bulk_edit'              => ['thinkrank_global_seo'],
        // The metabox "Advanced" tab: robots directives, canonical, breadcrumb title.
        'wpseo_edit_advanced_metadata' => ['thinkrank_crawling'],
    ];

    /**
     * Migrate the source plugin's Role Manager assignments into ThinkRank's
     * per-role capabilities (Capability_Manager).
     *
     * Without this, every non-administrator role loses its SEO access the
     * moment the source plugin is deactivated: ThinkRank grants its caps to the
     * administrator only, so an editor who could edit titles and social meta
     * simply stops seeing ThinkRank.
     *
     * Non-destructive: a role is only filled while it holds NO ThinkRank
     * capability yet, so a matrix the user has already configured is never
     * rewritten. `save_matrix()` grants the base ACCESS cap implicitly.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if any role was granted capabilities
     */
    private function migrate_role_capabilities(array $extended): bool {
        $source_roles = $extended['role_capabilities'] ?? [];
        if (!is_array($source_roles) || empty($source_roles)
            || !class_exists('ThinkRank\\Core\\Capability_Manager')) {
            return false;
        }

        $manager = '\\ThinkRank\\Core\\Capability_Manager';
        $matrix = $manager::get_matrix();
        $editable = $manager::editable_roles();
        $updated = false;

        foreach ($source_roles as $role_slug => $source_caps) {
            $role_slug = sanitize_key((string) $role_slug);

            // editable_roles() already excludes the administrator.
            if (!isset($editable[$role_slug]) || !is_array($source_caps)) {
                continue;
            }

            // Never rewrite a role the user has already given ThinkRank access.
            if (!empty($matrix[$role_slug])) {
                continue;
            }

            $granted = [];
            foreach ($source_caps as $source_cap) {
                foreach (self::ROLE_CAPABILITY_MAP[(string) $source_cap] ?? [] as $thinkrank_cap) {
                    $granted[$thinkrank_cap] = true;
                }
            }

            if (empty($granted)) {
                continue;
            }

            $matrix[$role_slug] = array_keys($granted);
            $updated = true;
        }

        if (!$updated) {
            return false;
        }

        $manager::save_matrix($matrix);

        return true;
    }

    /**
     * Migrate the source plugin's scheduled SEO email report cadence into
     * ThinkRank's Email Reporting config.
     *
     * Only touches a config the user has not enabled yet, and never turns
     * reports ON unless the source had them on — an unexpected recurring email
     * after an import would be worse than a missing one.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if the config was written
     */
    private function migrate_email_reports(array $extended): bool {
        $reports = $extended['email_reports'] ?? [];
        if (!is_array($reports) || empty($reports['enabled'])
            || !class_exists('ThinkRank\\SEO\\Email_Report_Config')) {
            return false;
        }

        $config_manager = new \ThinkRank\SEO\Email_Report_Config();
        $current = $config_manager->get();

        // Never re-enable over a deliberate opt-out or clobber a live schedule.
        if (!empty($current['enabled'])) {
            return false;
        }

        $frequency = (int) ($reports['frequency_days'] ?? 0);
        $update = ['enabled' => true];
        if ($frequency > 0) {
            $update['frequency_days'] = $frequency;
        }

        $config_manager->save(array_merge($current, $update));

        return true;
    }

    /**
     * Inspect a plugin's snapshot for extended data that has NO migration path
     * yet — data that is preserved in the snapshot but would become the only
     * copy once /import/cleanup deletes the source plugin's rows.
     *
     * Covers: redirection records (exported, but the migrator has no redirect
     * target yet — owed to the Pro Redirections feature) and any settings
     * `extended` bucket outside HANDLED_EXTENDED_SETTINGS. The raw_options
     * capture-all bucket is deliberately NOT counted (a fresh export recreates
     * it; it exists precisely to survive cleanup inside the snapshot).
     *
     * Used by Import_Controller::cleanup() to require force=true before
     * deleting source data while such buckets exist.
     *
     * @param string $plugin Plugin slug
     * @return array List of ['key' => ..., 'label' => ..., 'count' => ...]
     */
    public function get_unmigrated_extended_buckets(string $plugin): array {
        $buckets = [];

        $manifest = Snapshot_Store::get_manifest($plugin);
        if (!$manifest) {
            return $buckets;
        }

        // Redirections and 404 logs migrate into ThinkRank Pro. With Pro active
        // they have a real home and never block cleanup; without it they are
        // preserved-but-unapplied, so cleanup must warn before the source rows
        // (the only other copy) go away.
        $store = $this->create_redirections_store();
        $pro_can_take_redirects = $store !== null && method_exists($store, 'import_redirect');

        if (!$pro_can_take_redirects) {
            $redirection_count = (int) ($manifest['types']['redirections']['total_records'] ?? 0);
            if ($redirection_count > 0) {
                $buckets[] = [
                    'key'   => 'redirections',
                    'label' => __('Redirections', 'thinkrank'),
                    'count' => $redirection_count,
                ];
            }

            $log_count = (int) ($manifest['types']['404_logs']['total_records'] ?? 0);
            if ($log_count > 0) {
                $buckets[] = [
                    'key'   => '404_logs',
                    'label' => __('404 Logs', 'thinkrank'),
                    'count' => $log_count,
                ];
            }
        }

        $chunk = Snapshot_Store::read_chunk($plugin, 'settings', 1);
        $extended = $chunk[0]['extended'] ?? [];
        if (is_array($extended)) {
            foreach ($extended as $key => $value) {
                if (in_array($key, self::HANDLED_EXTENDED_SETTINGS, true) || empty($value)) {
                    continue;
                }
                $buckets[] = [
                    'key'   => 'settings.' . $key,
                    'label' => (string) $key,
                    'count' => 1,
                ];
            }
        }

        return $buckets;
    }

    /**
     * Fold post IDs the source excluded from its sitemap into ThinkRank's
     * sitemap `exclude_posts` list (a comma-separated ID string — ThinkRank has
     * no per-post exclusion meta).
     *
     * Additive and idempotent: IDs already listed are left in place and never
     * duplicated, so re-running a migration converges.
     *
     * @param int[] $post_ids Post IDs to exclude
     * @return int Number of IDs newly added
     */
    private function migrate_sitemap_exclusions(array $post_ids): int {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids) || !class_exists('ThinkRank\\SEO\\Sitemap_Generator')) {
            return 0;
        }

        $manager = new \ThinkRank\SEO\Sitemap_Generator();
        $current = $manager->get_settings('site');

        $existing = array_filter(array_map(
            'intval',
            array_map('trim', explode(',', (string) ($current['exclude_posts'] ?? '')))
        ));

        $merged = array_values(array_unique(array_merge($existing, $post_ids)));
        $added = count($merged) - count($existing);
        if ($added <= 0) {
            return 0;
        }

        sort($merged);
        $manager->save_settings('site', null, ['exclude_posts' => implode(',', $merged)]);

        return $added;
    }

    /**
     * Migrate a source plugin's sitemap inclusion settings into ThinkRank's
     * sitemap settings (wp_thinkrank_seo_settings via Sitemap_Generator). ThinkRank only
     * models the global enable toggle, posts/pages/categories/tags inclusion,
     * images, links-per-file, the ping-search-engines toggle and the sitemap-index
     * toggle (Rank Math is always index-based; AIOSEO exposes it explicitly);
     * source plugins' per-CPT / per-taxonomy toggles beyond these are not
     * represented. Shared by all source exporters (Rank Math, AIOSEO, SEOPress,
     * Yoast), which each emit this canonical shape.
     * Non-destructive: a value is written only while ThinkRank still holds its
     * default for that key.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if any value was written
     */
    private function migrate_sitemap(array $extended): bool {
        $sitemap = $extended['sitemap_settings'] ?? [];
        if (empty($sitemap['has_data']) || !class_exists('ThinkRank\\SEO\\Sitemap_Generator')) {
            return false;
        }

        $manager = new \ThinkRank\SEO\Sitemap_Generator();
        $current = $manager->get_settings('site');
        $defaults = $manager->get_default_settings('site');

        $keys = ['enabled', 'include_posts', 'include_pages', 'include_categories', 'include_tags', 'include_images', 'include_featured_images', 'links_per_sitemap', 'ping_search_engines', 'exclude_posts', 'exclude_terms'];
        $updates = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $sitemap)) {
                continue;
            }
            // Only write while ThinkRank still holds its default for this key.
            $current_val = $current[$key] ?? null;
            $default_val = $defaults[$key] ?? null;
            if ($current_val === $default_val && $sitemap[$key] !== $default_val) {
                $updates[$key] = $sitemap[$key];
            }
        }

        // Sitemap index toggle is COUPLED to sitemap_urls in ThinkRank: the UI
        // rewrites the URL list when the toggle flips, and generation keys off
        // sitemap_urls[].type ('index' vs 'general'). So the flag and its matching
        // URL entry must be written together — mirror the frontend's rewrite
        // (SitemapGeneration.js). Only AIOSEO exposes a source equivalent. Guard on
        // ThinkRank still being at its default (index off) so a user's customized
        // URL list is never clobbered.
        if (!empty($sitemap['use_sitemap_index']) && empty($current['use_sitemap_index'])) {
            $updates['use_sitemap_index'] = true;
            // Populate the full segmented list (index + one child per enabled type
            // and per public CPT) so the index has real children — not just the
            // bare index entry, which would generate an empty index.
            $updates['sitemap_urls'] = $manager->build_segmented_sitemap_urls(array_merge($current, $sitemap));
        }

        $saved = empty($updates) ? false : $manager->save_settings('site', null, $updates);

        // Generate the physical sitemap files now so the sitemap is live
        // immediately after import. ThinkRank serves static files that otherwise
        // only appear on the next content change or a manual "Generate", whereas
        // Rank Math served a ready sitemap — this closes that gap. Runs off the
        // migrated settings whatever they are (an import that matched ThinkRank's
        // defaults writes no updates but still needs its files), and never fails
        // the migration.
        try {
            $fresh = $manager->get_settings('site');
            if (!empty($fresh['enabled'])) {
                $manager->generate_and_save($fresh);
            }
        } catch (\Throwable $e) {
            // Non-fatal: settings persisted; files will be built on next trigger.
        }

        return $saved;
    }

    /**
     * Migrate Rank Math image auto alt/title settings into ThinkRank's Image SEO
     * settings (wp_thinkrank_seo_settings table via Image_SEO_Manager). Enables
     * auto-generation only when Rank Math had it on and ThinkRank is still at its
     * default; formats are filled only when ThinkRank still holds its default.
     *
     * @param array $extended Canonical settings `extended` payload
     * @return bool True if any value was written
     */
    private function migrate_image_seo(array $extended): bool {
        if (empty($extended['image_seo']) || !class_exists('ThinkRank\\SEO\\Image_SEO_Manager')) {
            return false;
        }

        $img = $extended['image_seo'];
        $manager = new \ThinkRank\SEO\Image_SEO_Manager();
        $current = $manager->get_settings('site');

        // ThinkRank Image SEO default seeds.
        $default_alt_format   = '%filename%';
        $default_title_format = '%title% %separator% %sitename%';

        $updates = [];
        if (!empty($img['add_missing_alt']) && empty($current['add_missing_alt'])) {
            $updates['add_missing_alt'] = true;
        }
        if (!empty($img['add_missing_title']) && empty($current['add_missing_title'])) {
            $updates['add_missing_title'] = true;
        }
        if (!empty($img['alt_format']) && ($current['alt_format'] ?? '') === $default_alt_format) {
            $updates['alt_format'] = $img['alt_format'];
        }
        if (!empty($img['title_format']) && ($current['title_format'] ?? '') === $default_title_format) {
            $updates['title_format'] = $img['title_format'];
        }

        if (empty($updates)) {
            return false;
        }

        return $manager->save_settings('site', null, $updates);
    }

    /**
     * Migrate Rank Math per-post-type title/description templates and robots
     * defaults into ThinkRank's Global SEO option (thinkrank_global_seo_settings),
     * which is keyed by post type. Non-destructive: only fills values ThinkRank
     * has not already customized.
     *
     * @param array $post_type_settings Map of post_type => {title_template, description_template, robots, custom_robots}
     * @return bool True if any value was written
     */
    private function migrate_post_type_settings(array $post_type_settings): bool {
        $global_seo = get_option('thinkrank_global_seo_settings', []);
        $updated = false;

        foreach ($post_type_settings as $post_type => $pt) {
            if (!post_type_exists((string) $post_type)) {
                continue;
            }

            $existing = $global_seo[$post_type] ?? [];

            if (!empty($pt['title_template']) && empty($existing['title'])) {
                $existing['title'] = $pt['title_template'];
                $updated = true;
            }
            if (!empty($pt['description_template']) && empty($existing['description'])) {
                $existing['description'] = $pt['description_template'];
                $updated = true;
            }

            // Link Suggestions. ThinkRank's default is ON, so only a source that
            // turned it OFF carries information — writing an "on" would just
            // restate the default. Never overrides an explicit ThinkRank value.
            if (array_key_exists('link_suggestions', $pt) && !$pt['link_suggestions']
                && !array_key_exists('link_suggestions', $existing)) {
                $existing['link_suggestions'] = false;
                $updated = true;
            }

            // Only migrate robots when Rank Math actually applied custom robots for
            // this type; otherwise the array is Rank Math's inert default.
            if (!empty($pt['custom_robots']) && !empty($pt['robots']) && is_array($pt['robots'])
                && empty($existing['robots_meta_enabled'])) {
                $robots = $pt['robots'];
                $existing['robots_meta'] = [
                    'index'        => !in_array('noindex', $robots, true),
                    'noindex'      => in_array('noindex', $robots, true),
                    'nofollow'     => in_array('nofollow', $robots, true),
                    'noarchive'    => in_array('noarchive', $robots, true),
                    'noimageindex' => in_array('noimageindex', $robots, true),
                    'nosnippet'    => in_array('nosnippet', $robots, true),
                ];
                $existing['robots_meta_enabled'] = true;
                $updated = true;
            }

            if (!empty($existing)) {
                $global_seo[$post_type] = $existing;
            }
        }

        if ($updated) {
            update_option('thinkrank_global_seo_settings', $global_seo);
        }

        return $updated;
    }

    /**
     * Update manifest with migration info after all chunks are migrated
     *
     * @param string $plugin Plugin slug
     * @return void
     */
    public function update_manifest_migration_info(string $plugin): void {
        $manifest = Snapshot_Store::get_manifest($plugin);
        if ($manifest) {
            $manifest['last_migrated'] = gmdate('c');
            $manifest['migration_version'] = defined('THINKRANK_VERSION') ? THINKRANK_VERSION : '2.0.0';
            Snapshot_Store::write_manifest($plugin, $manifest);
        }
    }

    /**
     * Get migratable types from a manifest
     *
     * @param array $manifest Snapshot manifest
     * @return array Types that can be migrated
     */
    public function get_migratable_types(array $manifest): array {
        $types = [];

        foreach ($manifest['types'] ?? [] as $type => $info) {
            if (in_array($type, self::MIGRATABLE_TYPES, true)) {
                $types[$type] = $info;
            }
        }

        return $types;
    }
}
