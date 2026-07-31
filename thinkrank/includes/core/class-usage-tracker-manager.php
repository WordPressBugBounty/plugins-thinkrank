<?php

/**
 * Usage Tracker Manager
 *
 * Bootstraps the opt-in Plugin_Usage_Tracker SDK: configures the opt-in
 * notice copy and registers the tracker hooks on admin init.
 *
 * @package ThinkRank\Core
 * @since 1.12.0
 */

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Usage Tracker Manager
 *
 * Single Responsibility: wire the usage tracker into WordPress.
 *
 * @since 1.12.0
 */
class Usage_Tracker_Manager {

    /**
     * WP Insights item id for this product.
     *
     * Obtain the real id from the WP Insights dashboard and place it here
     * (or filter `thinkrank_usage_tracker_item_id`). Without a valid id the
     * tracker collects data but the initial site registration is skipped,
     * so nothing is transmitted.
     *
     * @var string|false
     */
    private const ITEM_ID = '08649e95a94ecddfd027';

    /**
     * Tracker instance.
     *
     * @var Plugin_Usage_Tracker|null
     */
    private ?Plugin_Usage_Tracker $tracker = null;

    /**
     * Register the bootstrap hook.
     *
     * Mirrors the reference integration: the tracker is built on `init`
     * (admin context) so the opt-in notice and cron handlers are wired
     * once per request. The activation/deactivation hooks are registered
     * inside the tracker constructor.
     *
     * @return void
     */
    public function init(): void {
        add_action('init', [$this, 'start_tracking']);
    }

    /**
     * Build and configure the tracker.
     *
     * @return void
     */
    public function start_tracking(): void {
        $item_id = apply_filters('thinkrank_usage_tracker_item_id', self::ITEM_ID);

        $this->tracker = Plugin_Usage_Tracker::get_instance(THINKRANK_PLUGIN_FILE, [
            'opt_in'       => true,
            'goodbye_form' => true,
            'item_id'      => $item_id,
        ]);

        $this->tracker->set_notice_options([
            'notice_title' => __('Want to help make ThinkRank even better?', 'thinkrank'),
            'notice'       => __('Allow us to collect non-sensitive diagnostic data and usage information.', 'thinkrank'),
            'extra_notice' => __('We collect non-sensitive diagnostic data and plugin usage information — your site URL, WordPress &amp; PHP version, active plugins &amp; theme, and admin email. This lets us keep ThinkRank compatible with the most popular plugins and themes. No spam, we promise.', 'thinkrank'),
        ]);

        $this->tracker->init();
    }

    /**
     * Record explicit user consent for usage tracking.
     *
     * Called by flows outside the opt-in notice (e.g. the Setup Wizard
     * "Get Started" action). Builds the tracker if it has not been wired
     * yet so consent works regardless of hook timing.
     *
     * @return void
     */
    public function grant_consent(): void {
        if (null === $this->tracker) {
            $this->start_tracking();
        }
        $this->tracker->opt_in();
    }

    /**
     * Expose the tracker instance (mainly for tests / diagnostics).
     *
     * @return Plugin_Usage_Tracker|null
     */
    public function get_tracker(): ?Plugin_Usage_Tracker {
        return $this->tracker;
    }
}
