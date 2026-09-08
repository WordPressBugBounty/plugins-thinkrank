<?php

/**
 * Bricks Elements Integration Manager
 *
 * Registers ThinkRank's Bricks elements (FAQ, HowTo, Table of Contents) so a
 * Bricks-built page can emit FAQPage, HowTo and SiteNavigationElement schema
 * the same way a Gutenberg or Elementor page already can (#626).
 *
 * The Elementor widgets do not port. They are written against Elementor's
 * widget API — `Widget_Base`, `Controls_Manager`, `Repeater` — and Bricks has
 * its own: elements extend `Bricks\Element`, declare controls as a plain array
 * in `set_controls()`, and are registered through
 * `Bricks\Elements::register_element()`. What is shared is the rendered markup
 * and the stylesheets, so the two builders' output is identical on the page.
 *
 * Registration runs on `init` at priority 11. Bricks loads its own element base
 * class in `Elements::init_elements()` on `init` at the default priority, so
 * anything earlier would extend a class that does not exist yet.
 *
 * All hooks are Bricks-gated: without the theme, none of this runs and no
 * Bricks class is referenced.
 *
 * @package ThinkRank
 * @subpackage Editor
 * @since 2.3.1
 */

declare(strict_types=1);

namespace ThinkRank\Editor;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bricks Elements Manager.
 *
 * @since 2.3.1
 */
class Bricks_Elements_Manager {

    /**
     * Builder category the elements are filed under.
     *
     * @var string
     */
    private const CATEGORY = 'thinkrank';

    /**
     * Element name => file basename, in panel order.
     *
     * @var array<string,string>
     */
    private const ELEMENTS = [
        'thinkrank-faq'   => 'class-faq-element',
        'thinkrank-howto' => 'class-howto-element',
        'thinkrank-toc'   => 'class-toc-element',
    ];

    /**
     * Element name => the shared block stylesheet it renders against.
     *
     * The Bricks elements reuse the Gutenberg blocks' compiled CSS rather than
     * shipping a second copy, so a page built either way looks the same.
     *
     * @var array<string,string>
     */
    private const STYLES = [
        'thinkrank-faq'   => 'faq-block',
        'thinkrank-howto' => 'howto-block',
        'thinkrank-toc'   => 'toc-block',
    ];

    /**
     * Wire up hooks. Safe without Bricks — every one of them checks for it.
     *
     * @return void
     */
    public function init(): void {
        add_action('init', [$this, 'register_elements'], 11);
        add_filter('bricks/builder/i18n', [$this, 'register_category']);
        add_action('wp_enqueue_scripts', [$this, 'register_styles']);
    }

    /**
     * Hand the element files to Bricks.
     *
     * @return void
     */
    public function register_elements(): void {
        if (!class_exists('\\Bricks\\Elements') || !class_exists('\\Bricks\\Element')) {
            return;
        }

        foreach (self::ELEMENTS as $name => $basename) {
            $file = THINKRANK_PLUGIN_DIR . "includes/editor/bricks/{$basename}.php";
            if (!is_readable($file)) {
                continue;
            }

            \Bricks\Elements::register_element($file, $name, self::class_for($name));
        }
    }

    /**
     * Name the ThinkRank category in the builder's element panel.
     *
     * Bricks reads category labels from this map, keyed by the `$category`
     * an element declares. Without an entry the panel falls back to the raw
     * key, which would read "thinkrank" rather than "ThinkRank".
     *
     * @param mixed $i18n Builder strings.
     * @return mixed
     */
    public function register_category($i18n) {
        // Another filter may have handed on something that is not a map;
        // passing it through unchanged is better than replacing it.
        if (!is_array($i18n)) {
            return $i18n;
        }

        $i18n[self::CATEGORY] = __('ThinkRank', 'thinkrank');

        return $i18n;
    }

    /**
     * Register (not enqueue) the shared block stylesheets.
     *
     * Each element enqueues its own handle from `enqueue_scripts()`, which
     * Bricks calls only for elements actually on the page — so a page with no
     * ThinkRank element loads none of this CSS.
     *
     * Registration is skipped for a handle another integration already
     * registered: the Elementor manager registers the same three on the same
     * hook, and a site running both builders would otherwise register twice.
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

    /**
     * The class implementing one element.
     *
     * @param string $name Bricks element name.
     * @return string Fully qualified class name.
     */
    private static function class_for(string $name): string {
        $map = [
            'thinkrank-faq'   => Bricks\FAQ_Element::class,
            'thinkrank-howto' => Bricks\Howto_Element::class,
            'thinkrank-toc'   => Bricks\TOC_Element::class,
        ];

        return $map[$name] ?? '';
    }
}
