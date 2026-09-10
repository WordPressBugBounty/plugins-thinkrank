<?php
/**
 * Beaver Builder integration for the ThinkRank SEO metabox.
 *
 * Beaver Builder's editor is a front-end editing overlay, not a standalone SPA:
 * it loads the post on its own permalink with `?fl_builder` and paints its own
 * chrome (top bar, panels, settings modals) over the rendered page. It does NOT
 * render WordPress metaboxes and does not submit the #post form, so the
 * classic/block metabox never appears or saves there.
 *
 * That makes this the Divi/Bricks shape — the ordinary `wp_enqueue_scripts` /
 * `wp_footer` flow runs inside the builder, so the SAME React metabox app used
 * everywhere else is reused:
 *  - enqueues a dedicated `beaver` bundle and localizes the same
 *    `thinkrankMetabox` data (via Metabox_Manager::get_localized_data()),
 *    augmented with the metadata + content preview the classic editor exposes
 *    through hidden inputs;
 *  - outputs a mount node in the footer;
 *  - saves through the `thinkrank_save_metabox` AJAX route (Beaver Builder keeps
 *    its own layout in the `_fl_builder_data` / `_fl_builder_draft` postmeta and
 *    persists it through its own Publish button — we never touch it).
 *
 * ## The launcher is registered, not injected
 *
 * `fl_builder_ui_bar_buttons` is a public filter for adding a button to the top
 * bar, so the launcher is REGISTERED rather than injected into private toolbar
 * markup from JS. That is why there is no DOM-injection watcher here the way
 * there is in `src/divi/index.js` and `src/bricks/index.js`, and no
 * `.et-vb-page-bar-tools`-style private selector to go stale on an update.
 *
 * Detection is `FLBuilderModel::is_builder_active()`, Beaver Builder's own
 * authoritative check, so none of the query-string fallbacks the Divi class
 * carries are needed either.
 *
 * ## Why the hooks are split across two requests
 *
 * Beaver Builder 2.4+ defaults to an iframe UI (`FLBuilderUIIFrame`, switchable
 * via `fl_builder_iframe_ui_enabled`) which splits the builder over TWO requests
 * for the same post:
 *  - the top-level UI (`?fl_builder&fl_builder_ui`), a shell that embeds the
 *    page in `#fl-builder-ui-iframe`;
 *  - the layout iframe (`?fl_builder&fl_builder_ui_iframe`), which renders the
 *    page itself.
 *
 * The division of labour between them is counter-intuitive, and getting it
 * backwards produces an integration that looks right and does nothing:
 *
 *  - Beaver Builder's entire builder UI *runs* in the LAYOUT IFRAME.
 *    `FLBuilder::enqueue_ui_layout_styles_scripts()` — "scripts for the builder
 *    UI that need to run in the layout iframe" — is what loads
 *    `fl-builder-ui.js`, and `FLBuilder::render_ui()` prints the toolbar's
 *    underscore template (`#tmpl-fl-toolbar`, which is where this filter's
 *    output is baked in) into that document. So `add_bar_button()` MUST run on
 *    the layout iframe request; gating it to the top-level UI would filter the
 *    button out of the only document that reads it.
 *  - The rendered toolbar is then handed to the TOP WINDOW: `Toolbar.render()`
 *    does `$( 'body', window.parent.document ).prepend( $html )`. So the button
 *    element, and everything that has to interact with it, lives up there.
 *
 * The drawer therefore belongs in the top-level UI, alongside the toolbar it
 * launches from and outside the canvas that would otherwise clip it — which is
 * why `enqueue_editor_assets()` and `render_root()` gate on `ui_post_id()`
 * (top-level UI only) while `add_bar_button()` gates on `builder_post_id()`
 * (either request). In the legacy UI the two collapse onto one document and the
 * split is a no-op.
 *
 * The remaining catch is that the top-level UI empties the entire script queue
 * (`FLBuilderUIIFrame::enqueue_scripts()` sets `$wp_scripts->queue = array()` at
 * `PHP_INT_MAX`) so third-party front-end scripts don't load outside the layout
 * frame. No `wp_enqueue_scripts` priority can win that — it runs last by
 * definition. Beaver Builder's answer is the `fl_builder_ui_enqueue_scripts`
 * action it fires immediately afterwards for exactly this purpose, so the bundle
 * is re-queued there. Registration and the localized data survive the wipe (only
 * the queue is cleared), so re-queuing by handle is enough.
 *
 * Styles are not wiped, and `wp_enqueue_media()` is deliberately called in the
 * ordinary pass: `FLBuilderUIIFrame::enqueue_scripts()` re-runs it only when it
 * has already fired, which is what keeps the media library available to the
 * drawer's social-image picker after the queue is emptied.
 *
 * @package ThinkRank
 * @since   2.2.2
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the React metabox into the Beaver Builder editor.
 */
class Beaver_Metabox {

    /**
     * Script/style handle for the builder bundle.
     */
    private const HANDLE = 'thinkrank-beaver';

    /**
     * Shared metabox manager (data builder + supported post types).
     *
     * @var Metabox_Manager
     */
    private Metabox_Manager $metabox;

    /**
     * Memoized result of builder_post_id().
     *
     * Resolved once per request and reused by the enqueue, the toolbar filter
     * and the mount node, so the three cannot disagree — printing the root div
     * for a post the bundle then declines to load for would leave an orphan
     * element in the builder footer, and registering a toolbar button with no
     * drawer behind it would leave a dead launcher.
     *
     * @var int|null
     */
    private ?int $builder_post = null;

    /**
     * Constructor.
     *
     * @param Metabox_Manager $metabox Shared metabox manager instance.
     */
    public function __construct(Metabox_Manager $metabox) {
        $this->metabox = $metabox;
    }

    /**
     * Register the Beaver Builder hooks.
     *
     * Every callback gates on the builder detector, so registering
     * unconditionally is harmless when Beaver Builder isn't installed: the
     * detector returns false and nothing runs.
     *
     * The enqueue runs at 9999 so it lands after any front-end asset juggling,
     * and is repeated on `fl_builder_ui_enqueue_scripts` because the top-level
     * iframe UI empties the script queue at `PHP_INT_MAX` (see the class
     * docblock).
     *
     * @return void
     */
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_editor_assets'], 9999);
        add_action('fl_builder_ui_enqueue_scripts', [$this, 'requeue_editor_script']);
        add_action('wp_footer', [$this, 'render_root']);
        add_filter('fl_builder_ui_bar_buttons', [$this, 'add_bar_button']);
    }

    /**
     * Whether Beaver Builder's editor is open on this request.
     *
     * True for both halves of the modern UI (the top-level shell and the layout
     * iframe) and for the single legacy-UI document.
     *
     * @return bool
     */
    private function is_builder(): bool {
        return class_exists('\FLBuilderModel') && (bool) \FLBuilderModel::is_builder_active();
    }

    /**
     * Whether this request is the layout iframe of the modern UI.
     *
     * @return bool
     */
    private function is_layout_iframe(): bool {
        return class_exists('\FLBuilderUIIFrame')
            && \FLBuilderUIIFrame::is_enabled()
            && \FLBuilderUIIFrame::is_iframe_request();
    }

    /**
     * Resolve the post currently open in the builder.
     *
     * `FLBuilderModel::get_post_id()` is Beaver Builder's own resolver and is
     * what its own callbacks use, so it stays correct for the cases the queried
     * object gets wrong (theme-builder layouts, AJAX). The queried object is the
     * fallback for a release that ever stops setting it.
     *
     * @return int Post ID, or 0 if it cannot be determined.
     */
    private function get_post_id(): int {
        if (class_exists('\FLBuilderModel')) {
            $post_id = (int) \FLBuilderModel::get_post_id();
            if ($post_id) {
                return $post_id;
            }
        }

        return (int) get_queried_object_id();
    }

    /**
     * The post being edited, when ThinkRank may act on this builder request.
     *
     * Covers BOTH halves of the modern UI, because the toolbar button has to be
     * registered on the layout iframe request — that is the document whose
     * `#tmpl-fl-toolbar` the builder actually renders from.
     *
     * @return int Post ID, or 0 when this isn't an editable builder request.
     */
    private function builder_post_id(): int {
        if (null !== $this->builder_post) {
            return $this->builder_post;
        }

        $this->builder_post = 0;

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

        $this->builder_post = $post_id;

        return $post_id;
    }

    /**
     * The post this request may mount the drawer for, if any.
     *
     * Narrower than `builder_post_id()`: the drawer mounts in the window that
     * owns the toolbar and the viewport, never in the layout iframe. Mounting in
     * both would give the builder two drawers, one of them sealed inside the
     * canvas where nothing can reach it.
     *
     * @return int Post ID, or 0 when the drawer must not mount here.
     */
    private function ui_post_id(): int {
        return $this->is_layout_iframe() ? 0 : $this->builder_post_id();
    }

    /**
     * Enqueue the Beaver Builder metabox bundle and localize its data.
     *
     * @return void
     */
    public function enqueue_editor_assets(): void {
        $post_id = $this->ui_post_id();
        if (!$post_id) {
            return;
        }

        $post = get_post($post_id);

        // wp.media powers the social-image picker inside the drawer. Calling it
        // here rather than on `fl_builder_ui_enqueue_scripts` is deliberate: the
        // iframe UI only restores the media scripts it wiped when
        // `wp_enqueue_media` has ALREADY fired by the time it empties the queue.
        wp_enqueue_media();

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/beaver.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'react-dom', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
            'version' => THINKRANK_VERSION,
        ];

        wp_enqueue_script(
            self::HANDLE,
            THINKRANK_PLUGIN_URL . 'assets/beaver.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        // Reuse the exact metabox config, then add the data the classic editor
        // would normally hand the React app through hidden inputs.
        $data = $this->metabox->get_localized_data($post_id);
        $data['context'] = 'beaver';
        $data['existingMetadata'] = $this->metabox->get_post_metadata($post_id);
        $data['contentPreview'] = $this->metabox->get_content_preview($post);
        $data['postTitle'] = get_the_title($post_id);
        // AJAX route used to persist all fields (no #post form in the builder).
        $data['saveAction'] = 'thinkrank_save_metabox';

        wp_localize_script(self::HANDLE, 'thinkrankMetabox', $data);

        // Depend on wp-components so the @wordpress/components controls inside
        // the drawer keep their styling against the builder's global resets.
        wp_enqueue_style(
            self::HANDLE,
            THINKRANK_PLUGIN_URL . 'assets/beaver.css',
            ['wp-components'],
            THINKRANK_VERSION
        );
    }

    /**
     * Put the bundle back in the queue after the iframe UI empties it.
     *
     * `FLBuilderUIIFrame::enqueue_scripts()` clears `$wp_scripts->queue`
     * wholesale at `PHP_INT_MAX` and then fires this action so third parties can
     * opt back in. The script is still registered and still carries its
     * localized data — only the queue was cleared — so re-queuing by handle is
     * all that is needed. Enqueuing an unregistered handle is a no-op, which
     * covers the case where `enqueue_editor_assets()` declined to run.
     *
     * @return void
     */
    public function requeue_editor_script(): void {
        if (!$this->ui_post_id()) {
            return;
        }

        wp_enqueue_script(self::HANDLE);
    }

    /**
     * Register the ThinkRank launcher in the builder's top bar.
     *
     * Runs on `builder_post_id()`, not `ui_post_id()`: the button has to exist
     * in the layout iframe's `#tmpl-fl-toolbar`, because that is the copy
     * `wp.template( 'fl-toolbar' )` reads. The toolbar Beaver Builder renders
     * from it is then prepended to the top window, where the bundle picks the
     * button up.
     *
     * Beaver Builder renders each entry as
     * `<button class="fl-builder-{slug}-button fl-builder-button {class}" …>`
     * and echoes `label`, `title` and `id` WITHOUT escaping them, so every value
     * handed over here is escaped at the point it is built.
     *
     * `.fl-builder-bar-actions` is `flex-direction: row-reverse`, so array order
     * runs right-to-left on screen: inserting immediately after `done` puts the
     * launcher just to the LEFT of the Publish/Done button, which is where the
     * Elementor, Oxygen, Divi and Bricks launchers sit. Appending is the
     * fallback for a release that ever renames or drops that key.
     *
     * The button carries no `onclick`: the bundle binds a delegated listener
     * instead, so the launcher keeps working across the re-renders that rebuild
     * the toolbar from its template.
     *
     * @param array<string, array<string, mixed>> $buttons Registered bar buttons.
     * @return array<string, array<string, mixed>> Filtered bar buttons.
     */
    public function add_bar_button(array $buttons): array {
        if (!$this->builder_post_id()) {
            return $buttons;
        }

        $button = [
            'thinkrank' => [
                // Empty mount node: the bundle renders the React `LauncherMark`
                // (logo + live SEO score badge) into it, the same mark the
                // Bricks toolbar and the block editor's pinned launcher show.
                // Duplicating that gradient SVG here would fork the brand mark
                // and collide on the `<filter>`/`<linearGradient>` ids it
                // references, which is why nothing is drawn server-side.
                'label' => '<span class="thinkrank-fl-launcher-mark"></span>',
                // `fl-builder-button-silent` is Beaver Builder's own icon-button
                // treatment (the one the + content-panel button uses), so the
                // launcher inherits its hover and focus states instead of
                // fighting them with inline styles. The bundle adds
                // `thinkrank-is-ready` once the mark is mounted; until then the
                // stylesheet keeps the button hidden so no empty 40px gap ever
                // shows in the toolbar.
                'class' => 'fl-builder-button-silent thinkrank-fl-launcher',
                'title' => esc_attr__('ThinkRank SEO', 'thinkrank'),
            ],
        ];

        if (!array_key_exists('done', $buttons)) {
            return array_merge($buttons, $button);
        }

        $position = array_search('done', array_keys($buttons), true) + 1;

        return array_merge(
            array_slice($buttons, 0, $position, true),
            $button,
            array_slice($buttons, $position, null, true)
        );
    }

    /**
     * Output the React mount node into the builder footer.
     *
     * `wp_footer` fires in the top-level UI too: `includes/ui-iframe.php` calls
     * `do_action( 'wp_footer' )` explicitly, which is also what prints the
     * bundle's own script tag there.
     *
     * @return void
     */
    public function render_root(): void {
        if (!$this->ui_post_id()) {
            return;
        }

        echo '<div id="thinkrank-beaver-root" class="thinkrank-metabox"></div>';
    }
}
