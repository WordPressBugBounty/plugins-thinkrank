<?php
/**
 * Divi Visual Builder integration for the ThinkRank SEO metabox.
 *
 * Divi's Visual Builder is a front-end editing overlay: it loads the page on
 * its own permalink with `?et_fb=1` and mounts a React app over the themed
 * page. It does NOT render WordPress metaboxes and does not submit the #post
 * form, so the classic/block metabox never appears or saves there.
 *
 * Unlike the Oxygen/Breakdance builder (a standalone template with no
 * `wp_head()`/`wp_footer()`), the Divi VB is a normal themed front-end request,
 * so the ordinary enqueue flow works. This class mounts the SAME React metabox
 * app used elsewhere:
 *  - enqueues a dedicated `divi` bundle and localizes the same `thinkrankMetabox`
 *    data (via Metabox_Manager::get_localized_data()), augmented with the
 *    existing metadata + content preview the classic editor exposes via hidden
 *    inputs;
 *  - outputs a mount node in the footer;
 *  - the bundle injects a ThinkRank logo into the VB page bar tools
 *    (`.et-vb-page-bar-tools`) that opens the drawer, and saves through the
 *    `thinkrank_save_metabox` AJAX route (Divi keeps its own content in
 *    post_content via its own Save button — we never touch it).
 *
 * Scope: Divi 5 Visual Builder (the installed architecture). Detection is by
 * Divi's own `et_core_is_fb_enabled()` helper, so it no-ops when Divi isn't the
 * active theme/builder.
 *
 * @package ThinkRank
 * @since   1.24.0
 */

namespace ThinkRank\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the React metabox into the Divi Visual Builder.
 */
class Divi_Metabox {

    /**
     * Shared metabox manager (data builder + supported post types).
     *
     * @var Metabox_Manager
     */
    private Metabox_Manager $metabox;

    /**
     * Constructor.
     *
     * @param Metabox_Manager $metabox Shared metabox manager instance.
     */
    public function __construct(Metabox_Manager $metabox) {
        $this->metabox = $metabox;
    }

    /**
     * Register the Divi Visual Builder hooks.
     *
     * The VB is a themed front-end request, so we hook the front-end enqueue +
     * footer actions and gate every callback on `is_visual_builder()`.
     * Registering unconditionally is harmless when Divi isn't active: the
     * detector simply returns false.
     *
     * @return void
     */
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_editor_assets']);
        add_action('wp_footer', [$this, 'render_root']);
    }

    /**
     * Whether the current request is the Divi Visual Builder.
     *
     * @return bool
     */
    private function is_visual_builder(): bool {
        // Divi's own detector is authoritative when present.
        if (function_exists('et_core_is_fb_enabled') && et_core_is_fb_enabled()) {
            return true;
        }
        if (function_exists('et_fb_is_enabled') && et_fb_is_enabled()) {
            return true;
        }

        // Fallback: the VB always loads as ?et_fb=1.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request classification, no state change
        return !empty($_GET['et_fb']);
    }

    /**
     * Resolve the post currently open in the Visual Builder.
     *
     * The VB loads on the post's own permalink, so the queried object is the
     * post being edited.
     *
     * @return int Post ID, or 0 if it cannot be determined.
     */
    private function get_post_id(): int {
        $queried = get_queried_object_id();
        if ($queried) {
            return (int) $queried;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only context resolution, no state change
        foreach (['post', 'post_id', 'page_id', 'et_post_id'] as $key) {
            if (isset($_GET[$key])) {
                $candidate = absint(wp_unslash($_GET[$key]));
                if ($candidate) {
                    return $candidate;
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return 0;
    }

    /**
     * Enqueue the Divi metabox bundle and localize its data.
     *
     * @return void
     */
    public function enqueue_editor_assets(): void {
        if (!$this->is_visual_builder()) {
            return;
        }

        $post_id = $this->get_post_id();
        if (!$post_id) {
            return;
        }

        // Editing SEO from the builder must respect the same capability the
        // classic metabox save enforces.
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->metabox->get_supported_post_types(), true)) {
            return;
        }

        // wp.media powers the social-image picker inside the drawer.
        wp_enqueue_media();

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/divi.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'react-dom', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
            'version' => THINKRANK_VERSION,
        ];

        wp_enqueue_script(
            'thinkrank-divi',
            THINKRANK_PLUGIN_URL . 'assets/divi.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        // Reuse the exact metabox config, then add the data the classic editor
        // would normally hand the React app through hidden inputs.
        $data = $this->metabox->get_localized_data($post_id);
        $data['context'] = 'divi';
        $data['existingMetadata'] = $this->metabox->get_post_metadata($post_id);
        $data['contentPreview'] = $this->metabox->get_content_preview($post);
        $data['postTitle'] = get_the_title($post_id);
        // AJAX route used to persist all fields (no #post form in the builder).
        $data['saveAction'] = 'thinkrank_save_metabox';

        wp_localize_script('thinkrank-divi', 'thinkrankMetabox', $data);

        // Depend on wp-components so the @wordpress/components controls inside
        // the drawer keep their styling against the builder's global resets.
        wp_enqueue_style(
            'thinkrank-divi',
            THINKRANK_PLUGIN_URL . 'assets/divi.css',
            ['wp-components'],
            THINKRANK_VERSION
        );
    }

    /**
     * Output the React mount node into the Visual Builder footer.
     *
     * @return void
     */
    public function render_root(): void {
        if (!$this->is_visual_builder()) {
            return;
        }

        echo '<div id="thinkrank-divi-root" class="thinkrank-metabox"></div>';
    }
}
