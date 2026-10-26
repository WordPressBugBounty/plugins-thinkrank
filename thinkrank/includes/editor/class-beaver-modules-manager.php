<?php

/**
 * Beaver Builder Modules Integration Manager
 *
 * Registers ThinkRank's Beaver Builder modules (FAQ, HowTo, Table of Contents)
 * so a Beaver-built page can emit FAQPage, HowTo and SiteNavigationElement
 * schema the same way a Gutenberg, Elementor or Bricks page already can (#662).
 *
 * Neither the Elementor widgets nor the Bricks elements port. Each builder has
 * its own API: Elementor has `Widget_Base` with `Controls_Manager`/`Repeater`,
 * Bricks has `Bricks\Element` with a `set_controls()` array, and Beaver Builder
 * has `FLBuilderModule` plus a nested tabs/sections/fields array handed to
 * `FLBuilder::register_module()`. What IS shared is the rendered markup and the
 * stylesheets, so all four builders paint the same component from the same CSS.
 *
 * Two Beaver-specific shapes are worth knowing before reading the modules:
 *
 *  - **Repeaters are a separate registration.** A repeating field is
 *    `[ 'type' => 'form', 'form' => '<id>', 'multiple' => true ]`, and `<id>`
 *    is a form registered independently through
 *    `FLBuilder::register_settings_form()`. Elementor and Bricks both declare
 *    their repeater's sub-fields inline instead.
 *  - **Rendering goes through a file, not a method.** Beaver Builder renders a
 *    module by `include`ing `<module dir>/includes/frontend.php` with `$module`
 *    and `$settings` in scope. Each of ours is a one-line delegate to a method
 *    on the module class, so the logic stays testable and stays next to the
 *    settings it reads.
 *
 * Registration runs on `init` at 11. Beaver Builder loads `FLBuilderModule` on
 * `plugins_loaded`, so anything earlier risks extending a class that does not
 * exist yet; 11 also matches the Bricks manager for the same reason.
 *
 * All hooks are Beaver-gated: without the plugin, none of this runs and no
 * `FLBuilder*` class is referenced.
 *
 * @package ThinkRank
 * @subpackage Editor
 * @since 2.5.0
 */

declare(strict_types=1);

namespace ThinkRank\Editor;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Beaver Builder Modules Manager.
 *
 * @since 2.5.0
 */
class Beaver_Modules_Manager {

    /**
     * Module slug => directory name, in panel order.
     *
     * The slug is set explicitly on each module rather than left to Beaver
     * Builder's default, which derives it from the class file's basename — a
     * bare `faq` would be a slug collision waiting to happen with any other
     * plugin's FAQ module, and the slug is what Beaver Builder keys its module
     * registry and its `fl-module-<slug>` class on.
     *
     * @var array<string,string>
     */
    private const MODULES = [
        'thinkrank-faq'   => 'faq',
        'thinkrank-howto' => 'howto',
        'thinkrank-toc'   => 'toc',
    ];

    /**
     * Module slug => the shared block stylesheet it renders against.
     *
     * The modules reuse the Gutenberg blocks' compiled CSS rather than shipping
     * a second copy, so a page built either way looks the same.
     *
     * @var array<string,string>
     */
    private const STYLES = [
        'thinkrank-faq'   => 'faq-block',
        'thinkrank-howto' => 'howto-block',
        'thinkrank-toc'   => 'toc-block',
    ];

    /**
     * Comments wrapping an editor-only placeholder.
     *
     * When Beaver Builder publishes, `FLBuilderModel::save_layout()` renders
     * the layout through `FLBuilder::render_editor_content()` with the builder
     * still active and saves the result into `post_content`. An empty module's
     * "Add a question to get started." placeholder was therefore stored there,
     * and ThinkRank reads `post_content` for the description fallback,
     * og/twitter descriptions, schema and the score (#1307). Beaver strips
     * divs, spans and class attributes from that render but leaves comments,
     * so the markers survive to `fl_builder_editor_content`, which drops
     * everything between them.
     */
    public const PLACEHOLDER_OPEN  = '<!-- thinkrank:builder-placeholder -->';
    public const PLACEHOLDER_CLOSE = '<!-- /thinkrank:builder-placeholder -->';

    /**
     * Wire up hooks. Safe without Beaver Builder — every one of them checks.
     *
     * @return void
     */
    public function init(): void {
        add_action('init', [$this, 'register_modules'], 11);
        add_action('wp_enqueue_scripts', [$this, 'register_styles']);
        add_filter('fl_builder_editor_content', [self::class, 'strip_placeholders']);
    }

    /**
     * Markup for an empty module's builder-only placeholder.
     *
     * The canvas shows the text as before; the markers keep it out of the
     * copy Beaver Builder saves into `post_content`.
     *
     * @since 2.18.1
     * @param string $wrapper_class CSS class of the module's wrapper.
     * @param string $text  Placeholder text, unescaped.
     * @return string
     */
    public static function placeholder(string $wrapper_class, string $text): string {
        return '<div class="' . esc_attr($wrapper_class) . '">'
            . self::PLACEHOLDER_OPEN . esc_html($text) . self::PLACEHOLDER_CLOSE
            . '</div>';
    }

    /**
     * Remove placeholders from the layout Beaver Builder saves to the post.
     *
     * @since 2.18.1
     * @param mixed $content Stripped-down layout content.
     * @return mixed
     */
    public static function strip_placeholders($content) {
        if (!is_string($content) || false === strpos($content, self::PLACEHOLDER_OPEN)) {
            return $content;
        }

        return (string) preg_replace(
            '/' . preg_quote(self::PLACEHOLDER_OPEN, '/') . '.*?' . preg_quote(self::PLACEHOLDER_CLOSE, '/') . '/s',
            '',
            $content
        );
    }

    /**
     * Load the module files, each of which registers itself.
     *
     * Loading the file is what registers the module: every Beaver Builder
     * module file ends in a `FLBuilder::register_module()` call, which is the
     * convention its own modules and every third-party one follow. Requiring
     * them behind this gate is what keeps `FLBuilderModule` from being extended
     * on a site without the plugin — a fatal error, not a warning.
     *
     * @return void
     */
    public function register_modules(): void {
        if (!class_exists('\\FLBuilder') || !class_exists('\\FLBuilderModule')) {
            return;
        }

        foreach (self::MODULES as $dir) {
            $file = THINKRANK_PLUGIN_DIR . "includes/editor/beaver/{$dir}/{$dir}.php";
            if (is_readable($file)) {
                require_once $file;
            }
        }
    }

    /**
     * Register (not enqueue) the shared block stylesheets.
     *
     * Each module enqueues its own handle as it renders, so a page with no
     * ThinkRank module loads none of this CSS.
     *
     * Registration is skipped for a handle another integration already
     * registered: the Elementor and Bricks managers register the same three on
     * the same hook, and a site running two builders would otherwise register
     * twice.
     *
     * @return void
     */
    public function register_styles(): void {
        foreach (self::STYLES as $handle) {
            $style_handle = "thinkrank-{$handle}";
            if (wp_style_is($style_handle, 'registered')) {
                continue;
            }

            $css = THINKRANK_PLUGIN_DIR . "assets/{$handle}.css";
            if (!file_exists($css)) {
                continue;
            }

            $asset_path = THINKRANK_PLUGIN_DIR . "assets/{$handle}.asset.php";
            $asset = file_exists($asset_path) ? include $asset_path : [];

            wp_register_style(
                $style_handle,
                THINKRANK_PLUGIN_URL . "assets/{$handle}.css",
                [],
                $asset['version'] ?? THINKRANK_VERSION
            );
        }
    }
}
