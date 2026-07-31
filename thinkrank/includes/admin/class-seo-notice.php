<?php
/**
 * SEO Plugin Notice Manager
 *
 * Handles admin notices for SEO plugin conflicts
 *
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\Core\SEO_Plugin_Detector;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Notice Manager Class
 *
 * Single Responsibility: Display admin notices for SEO plugin conflicts
 *
 * @since 1.0.0
 */
class SEO_Notice {

    /**
     * Initialize notice manager
     *
     * @return void
     */
    public function init(): void {
        add_action('admin_notices', [$this, 'display_seo_plugin_notice']);
        add_action('wp_ajax_thinkrank_dismiss_seo_notice', [$this, 'ajax_dismiss_notice']);
        add_action('wp_ajax_thinkrank_deactivate_seo_plugin', [$this, 'ajax_deactivate_plugin']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_notice_scripts']);
    }

    /**
     * Display SEO plugin conflict notice
     *
     * @return void
     */
    public function display_seo_plugin_notice(): void {
        if (!SEO_Plugin_Detector::should_show_notice()) {
            return;
        }

        $detected = SEO_Plugin_Detector::detect_plugins();
        $message = SEO_Plugin_Detector::get_notice_message();
        if (empty($detected) || empty($message)) {
            return;
        }

        $names = array_column($detected, 'name');
        $heading = count($names) === 1
            /* translators: %s: detected SEO plugin name */
            ? sprintf(__('%s is running alongside ThinkRank', 'thinkrank'), $names[0])
            : __('Other SEO plugins are running alongside ThinkRank', 'thinkrank');

        $can_deactivate = current_user_can('deactivate_plugins');
        $migrate_url = $this->get_migration_url();
        ?>
        <div class="notice notice-warning is-dismissible thinkrank-notice thinkrank-seo-notice">
            <div class="thinkrank-notice__inner">
                <div class="thinkrank-notice__body">
                    <p class="thinkrank-notice__title"><?php echo esc_html($heading); ?></p>
                    <?php if (count($names) > 1) : ?>
                        <p class="thinkrank-notice__badges">
                            <?php foreach ($names as $name) : ?>
                                <span class="thinkrank-notice__badge"><?php echo esc_html($name); ?></span>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <p class="thinkrank-notice__text"><?php echo esc_html($message); ?></p>
                    <?php if ($migrate_url) : ?>
                        <p class="thinkrank-notice__hint">
                            <?php echo self::get_shield_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, trusted SVG markup ?>
                            <span><?php esc_html_e('Your existing SEO data is safe — migrate titles, descriptions, and other metadata into ThinkRank before deactivating.', 'thinkrank'); ?></span>
                        </p>
                    <?php endif; ?>
                    <p class="thinkrank-notice__actions">
                        <?php if ($can_deactivate) : ?>
                            <?php foreach ($detected as $slug => $plugin) : ?>
                                <button type="button" class="button button-primary thinkrank-deactivate-seo-plugin" data-plugin="<?php echo esc_attr($slug); ?>">
                                    <?php
                                    /* translators: %s: detected SEO plugin name */
                                    echo esc_html(sprintf(__('Deactivate %s', 'thinkrank'), $plugin['name']));
                                    ?>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($migrate_url) : ?>
                            <a href="<?php echo esc_url($migrate_url); ?>" class="button button-secondary">
                                <?php esc_html_e('Migrate SEO data first', 'thinkrank'); ?>
                            </a>
                        <?php endif; ?>
                        <a href="#" class="thinkrank-notice__dismiss thinkrank-dismiss-seo-notice"><?php esc_html_e('Dismiss', 'thinkrank'); ?></a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Small shield glyph for the data-safety reassurance chip.
     *
     * @return string Inline SVG markup
     */
    private static function get_shield_icon(): string {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
            . '<path d="M12 2 4 5v6c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V5l-8-3zm-1 13-3-3 1.4-1.4 1.6 1.6 3.6-3.6L16 10l-5 5z" fill="currentColor"/>'
            . '</svg>';
    }

    /**
     * URL of the Setup Wizard migration step, when migration is still possible.
     *
     * The wizard redirects to the dashboard once completed, so the link is
     * only offered while the wizard is still accessible and at least one
     * detected plugin has a ThinkRank importer.
     *
     * @return string Migration URL or '' when unavailable
     */
    private function get_migration_url(): string {
        if (!SEO_Plugin_Detector::has_importable_plugin()) {
            return '';
        }

        if (get_option(Setup_Wizard::OPT_COMPLETED, false)) {
            return '';
        }

        return admin_url('admin.php?page=' . Setup_Wizard::PAGE_SLUG);
    }

    /**
     * Enqueue notice scripts
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_notice_scripts(string $hook): void {
        if (!SEO_Plugin_Detector::should_show_notice()) {
            return;
        }

        wp_enqueue_style(
            'thinkrank-admin-notices',
            THINKRANK_PLUGIN_URL . 'static/css/admin-notices.css',
            [],
            THINKRANK_VERSION
        );

        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                function thinkrankDismissSeoNotice() {
                    $.post(ajaxurl, {
                        action: "thinkrank_dismiss_seo_notice",
                        nonce: "' . wp_create_nonce('thinkrank_seo_notice_nonce') . '"
                    }, function(response) {
                        if (response.success) {
                            $(".thinkrank-seo-notice").fadeOut();
                        }
                    });
                }

                $(".thinkrank-dismiss-seo-notice").on("click", function(e) {
                    e.preventDefault();
                    thinkrankDismissSeoNotice();
                });

                // Persist dismissal from the core notice X button too
                // (delegated — core injects the button after DOM ready)
                $(document).on("click", ".thinkrank-seo-notice .notice-dismiss", thinkrankDismissSeoNotice);

                $(".thinkrank-deactivate-seo-plugin").on("click", function(e) {
                    e.preventDefault();
                    var $button = $(this);
                    $button.prop("disabled", true);

                    $.post(ajaxurl, {
                        action: "thinkrank_deactivate_seo_plugin",
                        plugin: $button.data("plugin"),
                        nonce: "' . wp_create_nonce('thinkrank_seo_notice_nonce') . '"
                    }, function(response) {
                        if (response.success) {
                            window.location.reload();
                        } else {
                            $button.prop("disabled", false);
                        }
                    });
                });
            });
        ');
    }

    /**
     * AJAX handler to dismiss SEO notice
     *
     * @return void
     */
    public function ajax_dismiss_notice(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_seo_notice_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // The notice is an admin-facing plugin-conflict warning — only users who
        // can manage the plugin should be able to dismiss it.
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }

        SEO_Plugin_Detector::dismiss_notice();
        wp_send_json_success();
    }

    /**
     * AJAX handler to deactivate a conflicting SEO plugin.
     *
     * Only accepts a known-plugin slug; the plugin file(s) — including
     * premium companions — are resolved server-side by the detector.
     *
     * @return void
     */
    public function ajax_deactivate_plugin(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_seo_notice_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('deactivate_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }

        $slug = sanitize_key(wp_unslash($_POST['plugin'] ?? ''));
        $files = SEO_Plugin_Detector::get_deactivatable_files($slug);
        if (empty($files)) {
            wp_send_json_error('Unknown plugin');
        }

        deactivate_plugins($files);
        wp_send_json_success();
    }
}
