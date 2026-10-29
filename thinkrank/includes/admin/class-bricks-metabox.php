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
     * AJAX action returning the panel config for the document Bricks' instant
     * navigation switched to.
     *
     * @since 2.15.1
     * @var string
     */
    public const PANEL_DATA_ACTION = 'thinkrank_bricks_panel_data';

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
        // No nopriv handler: only a signed-in editor has a panel to refresh.
        add_action('wp_ajax_' . self::PANEL_DATA_ACTION, [$this, 'ajax_panel_data']);
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
     * The post this request may load the panel bundle for, if any.
     *
     * Shared by the enqueue and the mount node so the two cannot disagree:
     * printing the root div for a post the bundle then declines to load for
     * would leave an orphan element in Bricks' footer.
     *
     * Any document the user can edit, not only a supported post type. Bricks'
     * instant navigation moves between documents without a reload, so a
     * session that opens on a Bricks template and then switches to a page
     * needs the bundle already loaded to show that page's panel. Whether the
     * panel shows for the current document is `supported` in its config
     * (#971).
     *
     * @return int Post ID, or 0 when the bundle must not load.
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

        return get_post($post_id) ? $post_id : 0;
    }

    /**
     * Whether the panel shows for a post.
     *
     * @since 2.15.1
     *
     * @param \WP_Post $post Post open in the builder.
     * @return bool
     */
    private function is_supported(\WP_Post $post): bool {
        return in_array($post->post_type, $this->metabox->get_supported_post_types(), true);
    }

    /**
     * The panel config for a post, as the bundle reads it from
     * `window.thinkrankMetabox`.
     *
     * Used for the page load and for every instant-navigation switch, so the
     * two cannot drift. An unsupported document (a Bricks template, anything
     * outside the supported post types) gets only what the bundle needs to
     * follow the next switch, with `supported` false.
     *
     * @since 2.15.1
     *
     * @param int $post_id Post open in the builder.
     * @return array<string,mixed>
     */
    public function get_panel_data(int $post_id): array {
        $post = get_post($post_id);

        if (!$post || !$this->is_supported($post)) {
            return [
                'supported'     => false,
                'postId'        => $post_id,
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce('thinkrank_metabox_ajax'),
                'contextAction' => self::PANEL_DATA_ACTION,
            ];
        }

        // Reuse the exact metabox config, then add the data the classic editor
        // would normally hand the React app through hidden inputs.
        $data = $this->metabox->get_localized_data($post_id);
        $data['supported'] = true;
        $data['context'] = 'bricks';
        $data['contextAction'] = self::PANEL_DATA_ACTION;
        $data['existingMetadata'] = $this->metabox->get_post_metadata($post_id);
        $data['contentPreview'] = $this->metabox->get_content_preview($post);
        $data['postTitle'] = get_the_title($post_id);
        // AJAX route used to persist all fields (no #post form in the builder).
        $data['saveAction'] = 'thinkrank_save_metabox';

        return $data;
    }

    /**
     * Return the panel config for the document Bricks switched to.
     *
     * Same nonce and capability as the save route the panel then uses, so
     * nothing is readable here that the panel could not already save.
     *
     * @since 2.15.1
     *
     * @return void
     */
    public function ajax_panel_data(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_metabox_ajax')) {
            wp_send_json_error(['message' => __('Security check failed', 'thinkrank')], 403);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id || !get_post($post_id) || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'thinkrank')], 403);
        }

        wp_send_json_success($this->get_panel_data($post_id));
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

        // wp.media powers the social-image picker inside the drawer. Loaded
        // for an unsupported document too, which can switch to one that has
        // the drawer.
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

        wp_set_script_translations('thinkrank-bricks', 'thinkrank');
        wp_localize_script('thinkrank-bricks', 'thinkrankMetabox', $this->get_panel_data($post_id));

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
