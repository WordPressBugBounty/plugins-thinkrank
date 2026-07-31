<?php
/**
 * Elementor editor integration for the ThinkRank SEO metabox.
 *
 * Elementor's editor is a standalone SPA: it does not render WordPress
 * metaboxes and it does not submit the #post form, so the classic/block metabox
 * (which relies on hidden inputs + save_post) never appears or saves there.
 *
 * This class mounts the SAME React metabox app inside the Elementor editor:
 *  - enqueues a dedicated `elementor` bundle and localizes the same
 *    `thinkrankMetabox` data (via Metabox_Manager::get_localized_data()),
 *    augmented with the existing metadata + content preview that the classic
 *    editor would otherwise expose through hidden inputs;
 *  - outputs a mount node in the editor footer;
 *  - the bundle adds a launcher to Elementor's panel footer that opens the
 *    existing drawer, and saves through the `thinkrank_save_metabox` AJAX route.
 *
 * @package ThinkRank
 * @since   1.0.0
 */

namespace ThinkRank\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the React metabox into the Elementor editor.
 */
class Elementor_Metabox {

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
     * Register Elementor editor hooks.
     *
     * The `elementor/editor/*` actions only fire when Elementor is active, so
     * registering them unconditionally is harmless when it isn't installed.
     *
     * @return void
     */
    public function init(): void {
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_assets']);
        add_action('elementor/editor/footer', [$this, 'render_root']);
    }

    /**
     * Resolve the post currently open in the Elementor editor.
     *
     * @return int Post ID, or 0 if it cannot be determined.
     */
    private function get_post_id(): int {
        if (class_exists('\\Elementor\\Plugin')) {
            $documents = \Elementor\Plugin::$instance->documents ?? null;
            if ($documents) {
                $document = $documents->get_current();
                if ($document) {
                    return (int) $document->get_main_id();
                }
            }
        }

        // Fallback: Elementor editor loads as ?post=ID&action=elementor.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context resolution, no state change
        return isset($_GET['post']) ? absint($_GET['post']) : 0;
    }

    /**
     * Enqueue the Elementor metabox bundle and localize its data.
     *
     * @return void
     */
    public function enqueue_editor_assets(): void {
        $post_id = $this->get_post_id();
        if (!$post_id) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->metabox->get_supported_post_types(), true)) {
            return;
        }

        // wp.media powers the social-image picker inside the drawer.
        wp_enqueue_media();

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/elementor.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
            'version' => THINKRANK_VERSION,
        ];

        $dependencies = $asset['dependencies'];

        // Elementor v2 app-bar package — exposes window.elementorV2.editorAppBar
        // used to add the ThinkRank logo to the editor's top-right utilities
        // menu. Depend on it (when present) so the global loads first; the JS
        // gracefully falls back to a floating launcher when it isn't available.
        if (wp_script_is('elementor-v2-editor-app-bar', 'registered')
            || wp_script_is('elementor-v2-editor-app-bar', 'enqueued')) {
            $dependencies[] = 'elementor-v2-editor-app-bar';
        }

        wp_enqueue_script(
            'thinkrank-elementor',
            THINKRANK_PLUGIN_URL . 'assets/elementor.js',
            $dependencies,
            $asset['version'],
            true
        );

        // Reuse the exact metabox config, then add the data the classic editor
        // would normally hand the React app through hidden inputs.
        $data = $this->metabox->get_localized_data($post_id);
        $data['context'] = 'elementor';
        $data['existingMetadata'] = $this->metabox->get_post_metadata($post_id);
        $data['contentPreview'] = $this->metabox->get_content_preview($post);
        $data['postTitle'] = get_the_title($post_id);
        // AJAX route used to persist all fields (no #post form in Elementor).
        $data['saveAction'] = 'thinkrank_save_metabox';

        wp_localize_script('thinkrank-elementor', 'thinkrankMetabox', $data);

        // Depend on wp-components so the @wordpress/components controls (toggles,
        // checkboxes, text/select inputs) inside the drawer keep their styling.
        // Without it, Elementor's global `input { width: 100% }` reset wins and
        // the checkboxes/fields render broken.
        wp_enqueue_style(
            'thinkrank-elementor',
            THINKRANK_PLUGIN_URL . 'assets/elementor.css',
            ['wp-components'],
            THINKRANK_VERSION
        );
    }

    /**
     * Output the React mount node into the Elementor editor footer.
     *
     * @return void
     */
    public function render_root(): void {
        echo '<div id="thinkrank-elementor-root" class="thinkrank-metabox"></div>';
    }
}
