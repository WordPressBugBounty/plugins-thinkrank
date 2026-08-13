<?php
/**
 * Auto AI optimization — fill missing SEO metadata on publish, automatically.
 *
 * ThinkRank's metadata generation has always been on-demand: a human opens the
 * editor and clicks. High-volume sites (a newswire publishing dozens of
 * articles a day was the motivating request) never click — their posts go out
 * with empty SEO titles and descriptions.
 *
 * This closes that gap with deliberately conservative rules:
 *
 *   - opt-in (off by default), per post type
 *   - fires on the transition INTO publish, once, via a queued WP-Cron event —
 *     the publish request itself never waits on an AI call (same out-of-band
 *     pattern as Instant Indexing)
 *   - fills EMPTY fields only (SEO title, meta description, focus keyword); a
 *     human-written value is never overwritten
 *   - uses the site's own configured AI provider key, like every other AI
 *     feature — no key, no-op
 *
 * The last run's outcome is stored for the settings UI, so "is it working?"
 * has an answer without digging through logs.
 *
 * @package ThinkRank\SEO
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\AI\Metadata_Generator;
use ThinkRank\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queues and runs on-publish metadata generation.
 */
class Auto_Ai_Optimizer {

    /**
     * Cron hook carrying the post id.
     */
    public const CRON_HOOK = 'thinkrank_auto_ai_optimize';

    /**
     * Option recording the last run's outcome for the settings UI.
     */
    public const LAST_RUN_OPTION = 'thinkrank_auto_ai_last_run';

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action('transition_post_status', [$this, 'maybe_queue'], 10, 3);
        add_action(self::CRON_HOOK, [$this, 'optimize'], 10, 1);
    }

    /**
     * Decide whether a status transition should queue an optimization.
     *
     * Pure (settings passed in) so the rules are unit-testable: first
     * transition into publish only, enabled, post type opted in.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param string   $post_type  Post type.
     * @param bool     $enabled    The auto_ai_meta_enabled setting.
     * @param string[] $post_types The auto_ai_meta_post_types setting.
     * @return bool
     */
    public static function should_queue(
        string $new_status,
        string $old_status,
        string $post_type,
        bool $enabled,
        array $post_types
    ): bool {
        if (!$enabled) {
            return false;
        }
        // Pro capability. Checked here rather than only at the settings layer
        // so a stored `true` from a lapsed licence (or a direct option write)
        // cannot keep spending the user's AI credits unattended.
        if (!\ThinkRank\Core\Plan_Config::can('auto_ai_meta', 'ai_visibility')) {
            return false;
        }
        // First publish only: an already-published post being updated has had
        // its chance at human metadata — never race an editor.
        if ('publish' !== $new_status || 'publish' === $old_status) {
            return false;
        }
        return in_array($post_type, $post_types, true);
    }

    /**
     * transition_post_status listener — queue the out-of-band run.
     *
     * @param string   $new_status New status.
     * @param string   $old_status Old status.
     * @param \WP_Post $post       Post object.
     * @return void
     */
    public function maybe_queue(string $new_status, string $old_status, $post): void {
        if (!$post instanceof \WP_Post) {
            return;
        }

        $settings   = Settings::instance();
        $enabled    = (bool) $settings->get('auto_ai_meta_enabled', false);
        $post_types = (array) $settings->get('auto_ai_meta_post_types', ['post']);

        if (!self::should_queue($new_status, $old_status, $post->post_type, $enabled, $post_types)) {
            return;
        }

        // All target fields already set → nothing to do; skip the cron
        // round-trip. Only when title, description AND focus keyword are all
        // present is there nothing left for Auto AI to fill.
        if ('' !== (string) get_post_meta($post->ID, '_thinkrank_seo_title', true)
            && '' !== (string) get_post_meta($post->ID, '_thinkrank_meta_description', true)
            && '' !== Focus_Keywords::get_primary($post->ID)
        ) {
            return;
        }

        // Tell the editor panel a write is coming, *before* queueing it, so a
        // panel that mounts between publish and the cron tick sees the flag
        // and knows to wait for the value instead of polling blind (#329).
        Metadata_Pending::mark($post->ID);

        // WP-Cron collapses identical (hook, args) events scheduled close
        // together, which de-dupes rapid re-saves.
        wp_schedule_single_event(time() + 15, self::CRON_HOOK, [$post->ID]);
    }

    /**
     * Cron handler: generate and fill the EMPTY metadata fields.
     *
     * @param int $post_id Post to optimize.
     * @return void
     */
    public function optimize(int $post_id): void {
        try {
            $this->run_optimization($post_id);
        } finally {
            // Whatever happened below — skipped, filled, or thrown — no
            // further write is coming, so the editor panel must stop waiting
            // for one. Cleared on every path, including exceptions (#329).
            Metadata_Pending::clear($post_id);
        }
    }

    /**
     * The actual optimization run, wrapped by `optimize()` so the pending
     * marker is always cleared.
     *
     * @param int $post_id Post to optimize.
     * @return void
     */
    private function run_optimization(int $post_id): void {
        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status) {
            return;
        }

        // Re-check the toggle at run time — it may have been switched off
        // between queueing and the cron tick.
        if (!(bool) Settings::instance()->get('auto_ai_meta_enabled', false)) {
            return;
        }

        $empty_title       = '' === (string) get_post_meta($post_id, '_thinkrank_seo_title', true);
        $empty_description = '' === (string) get_post_meta($post_id, '_thinkrank_meta_description', true);
        $empty_keyword     = '' === Focus_Keywords::get_primary($post_id);

        if (!$empty_title && !$empty_description && !$empty_keyword) {
            return;
        }

        try {
            // A fresh AI Manager has no client until initialize_client() runs
            // (the plugin's singleton instance does this on `init`; this cron
            // context builds its own).
            $ai = new \ThinkRank\AI\Manager();
            $ai->initialize_client();

            $generator = new Metadata_Generator($ai);
            $metadata  = $generator->generate_for_post($post_id);

            // Fill ONLY what was empty at run time — never overwrite a human.
            if ($empty_title && !empty($metadata['title'])) {
                update_post_meta($post_id, '_thinkrank_seo_title', sanitize_text_field((string) $metadata['title']));
            }
            if ($empty_description && !empty($metadata['description'])) {
                update_post_meta($post_id, '_thinkrank_meta_description', sanitize_text_field((string) $metadata['description']));
            }
            if ($empty_keyword && !empty($metadata['focus_keyword'])) {
                Focus_Keywords::save($post_id, sanitize_text_field((string) $metadata['focus_keyword']));
            }

            $this->record_last_run($post_id, 'success', '');
        } catch (\Exception $e) {
            // One failed post must not break the feature silently — the
            // settings UI shows this outcome.
            $this->record_last_run($post_id, 'failed', $e->getMessage());
        }
    }

    /**
     * Persist the last run's outcome for the settings UI.
     *
     * @param int    $post_id Post processed.
     * @param string $status  'success' | 'failed'.
     * @param string $error   Error message when failed.
     * @return void
     */
    private function record_last_run(int $post_id, string $status, string $error): void {
        update_option(
            self::LAST_RUN_OPTION,
            [
                'post_id' => $post_id,
                'title'   => get_the_title($post_id),
                'status'  => $status,
                'error'   => substr($error, 0, 300),
                'time'    => current_time('mysql'),
            ],
            false
        );
    }
}
