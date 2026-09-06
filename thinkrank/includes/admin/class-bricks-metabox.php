<?php
/**
 * Bricks Builder integration for the ThinkRank SEO metabox.
 *
 * Bricks is a theme-based builder whose editor is a front-end overlay: it loads
 * the post on its own permalink with `?bricks=run`, renders the canvas in a
 * child iframe and paints its own chrome (toolbar, panels) in the top window.
 * It does NOT render WordPress metaboxes and does not submit the #post form, so
 * the classic/block metabox never appears or saves there.
 *
 * That makes this the Divi shape, not the Oxygen one. Bricks runs the ordinary
 * `wp_enqueue_scripts` / `wp_footer` flow inside the builder — which is how
 * both AIOSEO and SureRank mount their own panels — so no hand-printed builder
 * head/footer is needed and the SAME React metabox app used everywhere else is
 * reused:
 *  - enqueues a dedicated `bricks` bundle and localizes the same
 *    `thinkrankMetabox` data (via Metabox_Manager::get_localized_data()),
 *    augmented with the metadata + content preview the classic editor exposes
 *    through hidden inputs;
 *  - outputs a mount node in the footer;
 *  - the bundle injects a ThinkRank logo into the Bricks toolbar that opens the
 *    drawer, and saves through the `thinkrank_save_metabox` AJAX route (Bricks
 *    keeps its own layout in postmeta via its own Save button — we never touch
 *    it).
 *
 * Detection is Bricks' own `bricks_is_builder_main()`, the same public helper
 * the competing integrations gate on, so every callback no-ops when Bricks
 * isn't the active theme. `_main` matters: Bricks loads the canvas in a second
 * request (`bricks_is_builder_iframe()`), and mounting there would give the
 * page two drawers.
 *
 * @package ThinkRank
 * @since   2.2.1
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the React metabox into the Bricks builder.
 */
class Bricks_Metabox {

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
     * Register the Bricks builder hooks.
     *
     * Both callbacks gate on `is_builder()`, so registering unconditionally is
     * harmless when Bricks isn't the active theme: the detector returns false.
     *
     * The enqueue runs at 9999 because Bricks dequeues front-end assets it
     * doesn't recognise while setting up the builder; registering after that
     * pass is what keeps the bundle on the page.
     *
     * @return void
     */
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_editor_assets'], 9999);
        add_action('wp_footer', [$this, 'render_root']);
    }

    /**
     * Whether the current request is the main Bricks builder window.
     *
     * @return bool
     */
    private function is_builder(): bool {
        // Bricks' own helper is authoritative and is the documented way to
        // detect the builder; `_main` excludes the canvas iframe, which is a
        // separate request that would otherwise mount a second drawer.
        if (function_exists('bricks_is_builder_main')) {
            return (bool) \bricks_is_builder_main();
        }

        return false;
    }

    /**
     * Resolve the post currently open in the builder.
     *
     * Bricks loads on the post's own permalink, so the queried object is the
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
        foreach (['post_id', 'postId', 'p', 'page_id'] as $key) {
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
     * The post this request may mount the drawer for, if any.
     *
     * Shared by the enqueue and the mount node so the two cannot disagree:
     * printing the root div for a post the bundle then declines to load for
     * would leave an orphan element in Bricks' footer.
     *
     * @return int Post ID, or 0 when the drawer must not mount.
     */
    private function eligible_post_id(): int {
        if (!$this->is_builder()) {
            return 0;
        }

        $post_id = $this->get_post_id();
        if (!$post_id) {
            return 0;
        }

        // Editing SEO from the builder must respect the same capability the
        // classic metabox save enforces.
        if (!current_user_can('edit_post', $post_id)) {
            return 0;
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->metabox->get_supported_post_types(), true)) {
            return 0;
        }

        return $post_id;
    }

    /**
     * Enqueue the Bricks metabox bundle and localize its data.
     *
     * @return void
     */
    public function enqueue_editor_assets(): void {
        $post_id = $this->eligible_post_id();
        if (!$post_id) {
            return;
        }

        $post = get_post($post_id);

        // wp.media powers the social-image picker inside the drawer.
        wp_enqueue_media();

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/bricks.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'react-dom', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
            'version' => THINKRANK_VERSION,
        ];

        wp_enqueue_script(
            'thinkrank-bricks',
            THINKRANK_PLUGIN_URL . 'assets/bricks.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        // Reuse the exact metabox config, then add the data the classic editor
        // would normally hand the React app through hidden inputs.
        $data = $this->metabox->get_localized_data($post_id);
        $data['context'] = 'bricks';
        $data['existingMetadata'] = $this->metabox->get_post_metadata($post_id);
        $data['contentPreview'] = $this->metabox->get_content_preview($post);
        $data['postTitle'] = get_the_title($post_id);
        // AJAX route used to persist all fields (no #post form in the builder).
        $data['saveAction'] = 'thinkrank_save_metabox';

        wp_localize_script('thinkrank-bricks', 'thinkrankMetabox', $data);

        // Depend on wp-components so the @wordpress/components controls inside
        // the drawer keep their styling against the builder's global resets.
        wp_enqueue_style(
            'thinkrank-bricks',
            THINKRANK_PLUGIN_URL . 'assets/bricks.css',
            ['wp-components'],
            THINKRANK_VERSION
        );
    }

    /**
     * Output the React mount node into the builder footer.
     *
     * @return void
     */
    public function render_root(): void {
        if (!$this->eligible_post_id()) {
            return;
        }

        echo '<div id="thinkrank-bricks-root" class="thinkrank-metabox"></div>';
    }
}
