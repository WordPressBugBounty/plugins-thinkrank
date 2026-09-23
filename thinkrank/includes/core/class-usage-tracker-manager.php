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
     * Days after install before the opt-in notice may be shown.
     *
     * Activation redirects into the Setup Wizard, which asks for consent
     * itself. Asking again on the user's first admin page load is noise, so
     * the card is held back for users who skipped the wizard and only appears
     * once they have had a chance to use the plugin.
     *
     * @var int
     */
    private const NOTICE_DELAY_DAYS = 7;

    /**
     * Option holding the install timestamp the delay is measured from.
     *
     * @var string
     */
    private const INSTALL_TIME_OPTION = 'thinkrank_usage_notice_since';

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

        // Only the admin ever renders the notice, and resolving the baseline
        // can write an option — neither belongs on a front-end request.
        if (is_admin()) {
            $this->tracker->set_notice_after(
                $this->get_notice_baseline() + (self::NOTICE_DELAY_DAYS * DAY_IN_SECONDS)
            );
        }

        $this->tracker->set_notice_options([
            'notice_title' => __('Want to help make ThinkRank even better?', 'thinkrank'),
            'notice'       => __('Allow us to collect non-sensitive diagnostic data and usage information.', 'thinkrank'),
            'extra_notice' => __('We collect non-sensitive diagnostic data and plugin usage information — your site URL, WordPress &amp; PHP version, active plugins &amp; theme, and admin email. This lets us keep ThinkRank compatible with the most popular plugins and themes. No spam, we promise.', 'thinkrank'),
        ]);

        $this->tracker->init();
    }

    /**
     * Timestamp the opt-in delay is measured from.
     *
     * Deliberately not `thinkrank_activation_time`: the activator rewrites
     * that on every activation, so deactivating and reactivating would restart
     * the grace period and a user who toggles the plugin could never be asked.
     * This option is written once and then left alone.
     *
     * On upgrade the baseline is seeded from `thinkrank_activation_time`, so a
     * site that has had ThinkRank for months is not made to wait another week
     * before it can be asked — it has been seeing this card on every admin
     * screen already, and the screen scoping is the change it needs. Only an
     * install with no activation timestamp at all starts its week now.
     *
     * Autoloaded: it is read on every admin request, so the alternative is an
     * extra query per page load.
     *
     * @since 2.8.1
     * @return int Unix timestamp.
     */
    private function get_notice_baseline(): int {
        $since = (int) get_option(self::INSTALL_TIME_OPTION, 0);

        if ($since > 0) {
            return $since;
        }

        $since = (int) get_option('thinkrank_activation_time', 0);
        if ($since <= 0) {
            $since = time();
        }

        // add_option() so a concurrent request cannot move a recorded baseline.
        add_option(self::INSTALL_TIME_OPTION, $since);

        return $since;
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
