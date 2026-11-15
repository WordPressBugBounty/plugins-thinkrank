<?php
/**
 * Campaign Notice
 *
 * The Black Friday 2026 offer for ThinkRank Pro: a banner on the WordPress
 * Dashboard, the Plugins screen and ThinkRank's own pages, and a pointer on
 * the ThinkRank menu item.
 *
 * @package ThinkRank\Admin
 * @since 2.21.4
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\Core\Plan_Config;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign Notice Class
 *
 * Single Responsibility: show the running campaign's offer to administrators
 * of a site without ThinkRank Pro, between the campaign's two dates, until
 * they dismiss it.
 *
 * The banner and the pointer are dismissed separately and per user: closing
 * one administrator's banner does not hide the offer from another.
 *
 * @since 2.21.4
 */
class Campaign_Notice {

    /**
     * User meta holding the banner's dismissal.
     *
     * The year is part of the key so that next year's campaign is not hidden
     * by this year's dismissal.
     *
     * @var string
     */
    public const NOTICE_DISMISSED_META = 'thinkrank_bfcm_2026_notice_dismissed';

    /**
     * User meta holding the pointer's dismissal.
     *
     * @var string
     */
    public const POINTER_DISMISSED_META = 'thinkrank_bfcm_2026_pointer_dismissed';

    /**
     * Option WPDeveloper plugins share to show one campaign pointer at a time.
     *
     * It holds the priority of the plugin whose pointer is showing; a lower
     * number wins. Each plugin lowers it to its own priority when it is empty
     * or higher, shows its pointer only while the two match, and deletes the
     * option when its pointer is closed so that the next plugin can take it.
     *
     * @var string
     */
    public const POINTER_PRIORITY_OPTION = '_wpdeveloper_plugin_pointer_priority';

    /**
     * ThinkRank's place in that order. Essential Addons is 1 and BetterLinks
     * is 7, so on a site running either, their pointer shows first.
     *
     * @var int
     */
    public const POINTER_PRIORITY = 10;

    /**
     * First and last moment of the campaign (UTC).
     *
     * @var string
     */
    private const STARTS = '2026-11-14 00:00:00 UTC';
    private const ENDS   = '2026-12-14 23:59:59 UTC';

    /**
     * Where both calls to action lead.
     *
     * @var string
     */
    private const URL = 'https://wpdeveloper.com/bfcm2026/thinkrank/admin-notice';

    /**
     * AJAX action and nonce action for both dismissals.
     *
     * @var string
     */
    private const AJAX_ACTION  = 'thinkrank_dismiss_campaign_notice';
    private const NONCE_ACTION = 'thinkrank_campaign_notice';

    /**
     * Initialize the notice.
     *
     * The banner hooks both `admin_notices` and `thinkrank_admin_notices`:
     * Manager::remove_admin_notice() strips every `admin_notices` callback on
     * ThinkRank's own screens and re-fires `thinkrank_admin_notices` instead.
     * The two never both run on the same request.
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_notices', [$this, 'render']);
        add_action('thinkrank_admin_notices', [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_dismiss']);
    }

    /**
     * Current time. A method of its own so a test can set the date.
     *
     * @return int Unix timestamp.
     */
    protected function now(): int {
        return time();
    }

    /**
     * Whether the offer applies to this request at all: the campaign is
     * running, the site has no ThinkRank Pro, and the user can buy it.
     *
     * @return bool
     */
    private function is_running(): bool {
        $now = $this->now();

        if ($now < strtotime(self::STARTS) || $now > strtotime(self::ENDS)) {
            return false;
        }

        if (Plan_Config::is_pro()) {
            return false;
        }

        return current_user_can('manage_options');
    }

    /**
     * ID of the screen being viewed, or an empty string when there is none.
     *
     * @return string
     */
    private function screen_id(): string {
        if (!function_exists('get_current_screen')) {
            return '';
        }

        $screen = get_current_screen();

        return $screen instanceof \WP_Screen ? (string) $screen->id : '';
    }

    /**
     * Whether the screen is one of ThinkRank's own pages. The setup wizard is
     * left out: an offer there competes with the steps the user is taking.
     *
     * @param string $screen_id Screen ID.
     * @return bool
     */
    private function is_own_screen(string $screen_id): bool {
        return strpos($screen_id, 'thinkrank') !== false
            && strpos($screen_id, 'thinkrank_setup_wizard') === false;
    }

    /**
     * Whether the banner should render on this request.
     *
     * Shown on the WordPress Dashboard, the Plugins screen and ThinkRank's own
     * pages only, never on another plugin's screen or in the editor.
     *
     * @return bool
     */
    private function should_display_notice(): bool {
        if (!$this->is_running()) {
            return false;
        }

        $screen_id = $this->screen_id();

        if (!in_array($screen_id, ['dashboard', 'plugins'], true) && !$this->is_own_screen($screen_id)) {
            return false;
        }

        return !get_user_meta(get_current_user_id(), self::NOTICE_DISMISSED_META, true);
    }

    /**
     * Whether the pointer may show on this request, before the shared
     * priority is asked.
     *
     * Shown on the WordPress Dashboard and ThinkRank's dashboard page only.
     *
     * @return bool
     */
    private function should_display_pointer(): bool {
        if (!$this->is_running()) {
            return false;
        }

        if (!in_array($this->screen_id(), ['dashboard', 'toplevel_page_thinkrank'], true)) {
            return false;
        }

        return !get_user_meta(get_current_user_id(), self::POINTER_DISMISSED_META, true);
    }

    /**
     * Take the shared pointer slot if no plugin ahead of ThinkRank holds it.
     *
     * @return bool Whether ThinkRank's pointer is the one to show.
     */
    private function claim_pointer(): bool {
        $current = get_option(self::POINTER_PRIORITY_OPTION);

        if (empty($current) || (int) $current > self::POINTER_PRIORITY) {
            update_option(self::POINTER_PRIORITY_OPTION, self::POINTER_PRIORITY);

            return true;
        }

        return (int) $current === self::POINTER_PRIORITY;
    }

    /**
     * Load the notice stylesheet for the banner, and the pointer with its
     * script when the pointer is the one to show.
     *
     * @return void
     */
    public function enqueue_assets(): void {
        if ($this->should_display_notice()) {
            wp_enqueue_style(
                'thinkrank-admin-notices',
                THINKRANK_PLUGIN_URL . 'static/css/admin-notices.css',
                [],
                THINKRANK_VERSION
            );
        }

        if (!$this->should_display_pointer() || !$this->claim_pointer()) {
            return;
        }

        wp_enqueue_style('wp-pointer');
        wp_enqueue_script('wp-pointer');
        wp_add_inline_script('wp-pointer', $this->pointer_script());
    }

    /**
     * Render the banner.
     *
     * @return void
     */
    public function render(): void {
        if (!$this->should_display_notice()) {
            return;
        }
        ?>
        <div class="notice is-dismissible thinkrank-notice thinkrank-campaign-notice">
            <div class="thinkrank-notice__inner">
                <div class="thinkrank-campaign-notice__logo">
                    <img src="<?php echo esc_url(THINKRANK_PLUGIN_URL . 'static/img/email/thinkrank-logo.png'); ?>" alt="" width="165" height="40">
                </div>
                <div class="thinkrank-notice__body">
                    <p class="thinkrank-notice__text">
                        <?php // `wp-exclude-emoji` stops WordPress replacing the emoji with a Twemoji SVG. ?>
                        <strong><span class="wp-exclude-emoji">🛍️</span> <?php esc_html_e('Black Friday Sale:', 'thinkrank'); ?></strong>
                        <?php esc_html_e('50% off ThinkRank Pro. Unlock AI-powered SEO features to rank higher and get found by AI assistants.', 'thinkrank'); ?>
                    </p>
                    <p class="thinkrank-notice__actions">
                        <a href="<?php echo esc_url(self::URL); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Upgrade To PRO Now', 'thinkrank'); ?>
                        </a>
                        <a href="#" class="thinkrank-notice__dismiss thinkrank-dismiss-campaign" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
                            <?php esc_html_e('I Want To Pay Full Price', 'thinkrank'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
        // The banner renders outside ThinkRank's pages too, so its dismiss
        // handler ships with it. Both our link and core's × button persist
        // the dismissal.
        wp_print_inline_script_tag(
            '( function () {
                document.addEventListener( "click", function ( event ) {
                    var notice = event.target.closest( ".thinkrank-campaign-notice" );
                    if ( ! notice ) {
                        return;
                    }
                    var link = event.target.closest( ".thinkrank-dismiss-campaign" );
                    if ( ! link && ! event.target.closest( ".notice-dismiss" ) ) {
                        return;
                    }
                    if ( link ) {
                        event.preventDefault();
                        notice.style.display = "none";
                    }
                    window.fetch( window.ajaxurl, {
                        method: "POST",
                        credentials: "same-origin",
                        body: new URLSearchParams( {
                            action: "' . self::AJAX_ACTION . '",
                            target: "notice",
                            nonce: notice.querySelector( ".thinkrank-dismiss-campaign" ).dataset.nonce,
                        } ),
                    } );
                } );
            } )();'
        );
    }

    /**
     * Script that opens the pointer on the ThinkRank menu item.
     *
     * @return string
     */
    private function pointer_script(): string {
        $content = sprintf(
            '<h3 style="font-weight: 600;">%1$s</h3><p style="margin: 1em 0;">%2$s</p><p><a class="button button-primary" href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>',
            esc_html__('Black Friday Is On!', 'thinkrank'),
            esc_html__('50% off ThinkRank Pro. Unlock AI-powered SEO features to rank higher and get found by AI assistants.', 'thinkrank'),
            esc_url(self::URL),
            esc_html__('View Deals', 'thinkrank')
        );

        $config = wp_json_encode(
            [
                'content' => $content,
                'action'  => self::AJAX_ACTION,
                'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            ]
        );

        return 'jQuery( function ( $ ) {
            var config = ' . $config . ';
            var item   = $( "#toplevel_page_thinkrank" );

            if ( ! item.length ) {
                return;
            }

            item.pointer( {
                content: config.content,
                position: { edge: "left", align: "center" },
                pointerClass: "wp-pointer",
                close: function () {
                    $( window ).off( ".thinkrankCampaignPointer" );
                    $.post( window.ajaxurl, {
                        action: config.action,
                        target: "pointer",
                        nonce: config.nonce,
                    } );
                },
            } ).pointer( "open" );

            // Keep the pointer on the menu item: the admin menu is fixed (or
            // absolute when it is taller than the window), so give the pointer
            // the same position and re-place it on scroll and resize.
            var widget  = item.pointer( "widget" );
            var menu    = $( "#adminmenuwrap" );
            var pending = false;
            var stick   = function () {
                pending = false;
                if ( ! widget.is( ":visible" ) ) {
                    return;
                }
                widget.css( "position", "fixed" === menu.css( "position" ) ? "fixed" : "absolute" );
                item.pointer( "reposition" );
            };

            $( window ).on( "scroll.thinkrankCampaignPointer resize.thinkrankCampaignPointer", function () {
                if ( ! pending ) {
                    pending = true;
                    window.requestAnimationFrame( stick );
                }
            } );
            stick();
        } );';
    }

    /**
     * AJAX handler persisting a dismissal.
     *
     * @return void
     */
    public function ajax_dismiss(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        // The nonce proves intent, not authorization. Require the capability
        // the offer is shown to.
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        $target = isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : '';

        if (!$this->dismiss($target)) {
            wp_send_json_error('Unknown target', 400);
        }

        wp_send_json_success();
    }

    /**
     * Record the current user's dismissal of the banner or the pointer.
     *
     * Closing the pointer also frees the shared slot, so that another
     * WPDeveloper plugin's pointer can show on the next page load.
     *
     * @param string $target `notice` or `pointer`.
     * @return bool False when the target is neither.
     */
    public function dismiss(string $target): bool {
        $user_id = get_current_user_id();

        if ('notice' === $target) {
            update_user_meta($user_id, self::NOTICE_DISMISSED_META, 1);

            return true;
        }

        if ('pointer' === $target) {
            update_user_meta($user_id, self::POINTER_DISMISSED_META, 1);

            if ((int) get_option(self::POINTER_PRIORITY_OPTION) === self::POINTER_PRIORITY) {
                delete_option(self::POINTER_PRIORITY_OPTION);
            }

            return true;
        }

        return false;
    }
}
