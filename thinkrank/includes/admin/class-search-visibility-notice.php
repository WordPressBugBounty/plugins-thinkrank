<?php
/**
 * Search Visibility Notice
 *
 * Warns site owners when WordPress is set to discourage search engines, which
 * makes the whole site noindex.
 *
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search Visibility Notice Class
 *
 * Single Responsibility: Surface the "Discourage search engines" setting as a
 * site-wide admin notice.
 *
 * The condition is already reported by the Site SEO Analyzer
 * (SEO_Analyzer::check_search_visibility()), but nobody opens the Analyzer to
 * discover their site is invisible — so it also needs to find the user.
 *
 * @since 1.0.0
 */
class Search_Visibility_Notice {

    /**
     * Option flag storing the dismissal.
     *
     * Cleared whenever blog_public changes, so re-enabling "Discourage search
     * engines" later warns again instead of staying silenced forever.
     *
     * @var string
     */
    public const OPT_DISMISSED = 'thinkrank_search_visibility_dismissed';

    /**
     * Nonce action for the dismiss request.
     *
     * @var string
     */
    private const NONCE_ACTION = 'thinkrank_search_visibility_notice';

    /**
     * Initialize the notice.
     *
     * Hooks both `admin_notices` and `thinkrank_admin_notices`: Manager
     * ::remove_admin_notice() strips every `admin_notices` callback on
     * ThinkRank's own screens and re-fires `thinkrank_admin_notices` instead,
     * so a notice registered on only one of the two disappears on half the
     * admin. The two never both run on the same request.
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_notices', [$this, 'render']);
        add_action('thinkrank_admin_notices', [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_thinkrank_dismiss_search_visibility', [$this, 'ajax_dismiss']);

        // A change either resolves the problem or re-introduces it; both cases
        // want a clean slate for the dismissal.
        add_action('update_option_blog_public', [$this, 'reset_dismissal']);
    }

    /**
     * Whether the notice should render on this request.
     *
     * @return bool
     */
    private function should_display(): bool {
        // blog_public = 0 means "Discourage search engines from indexing this
        // site" is ticked under Settings → Reading.
        if (get_option('blog_public')) {
            return false;
        }

        // Only users who can actually flip the setting are shown the warning.
        if (!current_user_can('manage_options')) {
            return false;
        }

        return !get_option(self::OPT_DISMISSED);
    }

    /**
     * Load the shared notice stylesheet when the notice will render.
     *
     * @return void
     */
    public function enqueue_assets(): void {
        if (!$this->should_display()) {
            return;
        }

        wp_enqueue_style(
            'thinkrank-admin-notices',
            THINKRANK_PLUGIN_URL . 'static/css/admin-notices.css',
            [],
            THINKRANK_VERSION
        );
    }

    /**
     * Render the notice.
     *
     * @return void
     */
    public function render(): void {
        if (!$this->should_display()) {
            return;
        }

        $reading_url = admin_url('options-reading.php');
        ?>
        <div class="notice notice-warning is-dismissible thinkrank-notice thinkrank-search-visibility-notice">
            <div class="thinkrank-notice__inner">
                <div class="thinkrank-notice__body">
                    <p class="thinkrank-notice__title"><?php esc_html_e('Your site is hidden from search engines', 'thinkrank'); ?></p>
                    <p class="thinkrank-notice__text">
                        <?php esc_html_e('WordPress is set to discourage search engines from indexing this site, so your pages will not appear in search results — no matter how well they are optimized.', 'thinkrank'); ?>
                    </p>
                    <p class="thinkrank-notice__actions">
                        <a href="<?php echo esc_url($reading_url); ?>" class="button button-primary">
                            <?php esc_html_e('Change search engine visibility', 'thinkrank'); ?>
                        </a>
                        <a href="#" class="thinkrank-notice__dismiss thinkrank-dismiss-search-visibility" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
                            <?php esc_html_e('Dismiss', 'thinkrank'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
        // The notice renders on every admin screen, so the dismiss handler must
        // ship with it — the thinkrank-admin bundle only loads on ThinkRank
        // pages. Persist the dismissal for both our "Dismiss" link and core's
        // × button.
        wp_print_inline_script_tag(
            '( function () {
                document.addEventListener( "click", function ( event ) {
                    var notice = event.target.closest( ".thinkrank-search-visibility-notice" );
                    if ( ! notice ) {
                        return;
                    }
                    var link = event.target.closest( ".thinkrank-dismiss-search-visibility" );
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
                            action: "thinkrank_dismiss_search_visibility",
                            nonce: notice.querySelector( ".thinkrank-dismiss-search-visibility" ).dataset.nonce,
                        } ),
                    } );
                } );
            } )();'
        );
    }

    /**
     * AJAX handler persisting the dismissal.
     *
     * @return void
     */
    public function ajax_dismiss(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        // The nonce proves intent, not authorization — dismissing a site-wide
        // notice writes an option, so require the same capability that renders
        // it.
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        update_option(self::OPT_DISMISSED, 1, true);

        wp_send_json_success();
    }

    /**
     * Clear the dismissal whenever the visibility setting changes.
     *
     * @return void
     */
    public function reset_dismissal(): void {
        delete_option(self::OPT_DISMISSED);
    }
}
