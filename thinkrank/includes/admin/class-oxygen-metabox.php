<?php
/**
 * Oxygen / Breakdance editor integration for the ThinkRank SEO metabox.
 *
 * Oxygen 6 is built on the Breakdance engine: its editor is a standalone Vue
 * SPA served on a front-end builder request (`?breakdance=builder&id=ID`, or
 * the legacy `?oxygen=builder`), NOT the WordPress post edit screen. It never
 * renders WordPress metaboxes, never fires Elementor's `elementor/editor/*`
 * hooks, and never submits the #post form, so neither the classic/block metabox
 * nor the Elementor integration appears there.
 *
 * The builder template never calls `wp_head()`/`wp_footer()`, so the normal
 * enqueue flow prints nothing there. Breakdance instead fires its own actions
 * inside the builder chrome — `breakdance_builder_head` and
 * `breakdance_builder_footer` — which fire only on a real builder request. This
 * class hooks both and mounts the SAME React metabox app used elsewhere:
 *  - resolves the edited post from the `id` query var;
 *  - registers a dedicated `oxygen` bundle and localizes the same
 *    `thinkrankMetabox` data (via Metabox_Manager::get_localized_data()),
 *    augmented with the existing metadata + content preview that the classic
 *    editor would otherwise expose through hidden inputs;
 *  - prints the style in the builder <head> and the mount node + script in the
 *    builder footer by hand (wp_print_styles / wp_print_scripts), since neither
 *    wp_head nor wp_footer runs;
 *  - the bundle shows the drawer's own floating launcher and saves through the
 *    `thinkrank_save_metabox` AJAX route (Oxygen keeps its own content under the
 *    `_breakdance_data` postmeta via its own Save button — we never touch it).
 *
 * Scope: Oxygen 6 / Breakdance. The legacy shortcode builder (Oxygen < 6) is
 * covered for content *extraction* (see Builder_Content) but not for this
 * editor UI; the request detector still recognises its `?ct_builder=true` view
 * so a future entry can hook it without touching this contract.
 *
 * @package ThinkRank
 * @since   1.24.0
 */

namespace ThinkRank\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the React metabox into the Oxygen / Breakdance editor.
 */
class Oxygen_Metabox {

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
     * Handle used for the builder script + style + localized data.
     */
    private const HANDLE = 'thinkrank-oxygen';

    /**
     * Register the builder hooks.
     *
     * The Oxygen 6 / Breakdance builder template is a standalone HTML document
     * that never calls `wp_head()` or `wp_footer()`, so the usual enqueue flow
     * prints nothing there. Breakdance instead fires its own actions inside the
     * builder chrome — `breakdance_builder_head` (in <head>) and
     * `breakdance_builder_footer` (before </body>) — and those only fire on a
     * genuine builder request. We hook both and print our assets by hand.
     *
     * Registering unconditionally is harmless when Oxygen isn't installed: the
     * actions simply never fire.
     *
     * @return void
     */
    public function init(): void {
        add_action('breakdance_builder_head', [$this, 'print_builder_styles']);
        add_action('breakdance_builder_footer', [$this, 'print_builder_scripts']);
    }

    /**
     * Resolve the post currently open in the builder.
     *
     * The builder loads as `?oxygen=builder&id=<postId>` (or `?breakdance=...`
     * on Breakdance builds); the edited post is always the `id` query var.
     *
     * @return int Post ID, or 0 if it cannot be determined.
     */
    private function get_post_id(): int {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only context resolution inside the builder request, no state change
        foreach (['id', 'page_id', 'post', 'post_id'] as $key) {
            if (isset($_GET[$key])) {
                $candidate = absint(wp_unslash($_GET[$key]));
                if ($candidate) {
                    return $candidate;
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $queried = get_queried_object_id();
        return $queried ? (int) $queried : 0;
    }

    /**
     * Register the script/style and localize the data, once.
     *
     * Returns the post being edited when this request should get the panel, or
     * null when it should be skipped (no post, wrong type, missing capability).
     *
     * @return \WP_Post|null Post to render for, or null to skip.
     */
    private function register_assets(): ?\WP_Post {
        $post_id = $this->get_post_id();
        if (!$post_id) {
            return null;
        }

        // Editing SEO from the builder must respect the same capability the
        // classic metabox save enforces.
        if (!current_user_can('edit_post', $post_id)) {
            return null;
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->metabox->get_supported_post_types(), true)) {
            return null;
        }

        // Both hooks run per request; register only on the first.
        if (wp_script_is(self::HANDLE, 'registered')) {
            return $post;
        }

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/oxygen.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'react-dom', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
            'version' => THINKRANK_VERSION,
        ];

        wp_register_script(
            self::HANDLE,
            THINKRANK_PLUGIN_URL . 'assets/oxygen.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        // Reuse the exact metabox config, then add the data the classic editor
        // would normally hand the React app through hidden inputs.
        $data = $this->metabox->get_localized_data($post_id);
        $data['context'] = 'oxygen';
        $data['existingMetadata'] = $this->metabox->get_post_metadata($post_id);
        $data['contentPreview'] = $this->metabox->get_content_preview($post);
        $data['postTitle'] = get_the_title($post_id);
        // AJAX route used to persist all fields (no #post form in the builder).
        $data['saveAction'] = 'thinkrank_save_metabox';

        wp_localize_script(self::HANDLE, 'thinkrankMetabox', $data);

        // Depend on wp-components so the @wordpress/components controls inside
        // the drawer keep their styling against the builder's global resets.
        wp_register_style(
            self::HANDLE,
            THINKRANK_PLUGIN_URL . 'assets/oxygen.css',
            ['wp-components'],
            THINKRANK_VERSION
        );

        return $post;
    }

    /**
     * Print the drawer styles into the builder <head>.
     *
     * `wp_head()` never runs in the builder, so the enqueued style would never
     * be emitted — print it (and its dependencies) directly.
     *
     * @return void
     */
    public function print_builder_styles(): void {
        if (!$this->register_assets()) {
            return;
        }

        wp_print_styles(self::HANDLE);
    }

    /**
     * Print the mount node, media templates and drawer script into the builder
     * footer, right before </body>.
     *
     * @return void
     */
    public function print_builder_scripts(): void {
        if (!$this->register_assets()) {
            return;
        }

        echo '<div id="thinkrank-oxygen-root" class="thinkrank-metabox"></div>';

        // wp.media powers the social-image picker inside the drawer. Its
        // templates normally print on wp_footer, which the builder never fires,
        // so print the scripts and underscore templates by hand. Best-effort:
        // the drawer still works without the picker if this is unavailable.
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
            wp_print_scripts('media-editor');
        }

        wp_print_scripts(self::HANDLE);

        if (function_exists('wp_print_media_templates')) {
            wp_print_media_templates();
        }
    }
}
