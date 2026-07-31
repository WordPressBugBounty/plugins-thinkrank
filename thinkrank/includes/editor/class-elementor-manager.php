<?php

/**
 * Elementor Integration Manager
 *
 * Registers ThinkRank's Elementor widgets (FAQ, HowTo, Table of Contents) so
 * Elementor-built pages get the same content patterns and structured data as
 * the Gutenberg blocks. Notably, RankMath ships no such widgets — Elementor
 * users there get schema only by decorating Elementor's own accordion.
 *
 * All hooks are Elementor-fired: when Elementor is not active, none of this
 * runs and no Elementor classes are referenced.
 *
 * @package ThinkRank
 * @subpackage Editor
 * @since 1.18.0
 */

declare(strict_types=1);

namespace ThinkRank\Editor;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elementor Manager.
 *
 * @since 1.18.0
 */
class Elementor_Manager {

    /**
     * Wire up hooks. Safe without Elementor — the hooks simply never fire.
     *
     * @return void
     */
    public function init(): void {
        add_action('elementor/elements/categories_registered', [$this, 'register_category']);
        add_action('elementor/widgets/register', [$this, 'register_widgets']);
        add_action('wp_enqueue_scripts', [$this, 'register_widget_styles']);
        add_action('elementor/editor/after_enqueue_styles', [$this, 'register_widget_styles']);
        add_action('elementor/preview/enqueue_styles', [$this, 'enqueue_preview_styles']);
    }

    /**
     * Add the ThinkRank widget category.
     *
     * @param object $elements_manager Elementor elements manager.
     * @return void
     */
    public function register_category($elements_manager): void {
        $elements_manager->add_category('thinkrank', [
            'title' => __('ThinkRank', 'thinkrank'),
            'icon'  => 'eicon-search',
        ]);
    }

    /**
     * Register the widgets.
     *
     * @param object $widgets_manager Elementor widgets manager.
     * @return void
     */
    public function register_widgets($widgets_manager): void {
        $widgets_manager->register(new Elementor\FAQ_Widget());
        $widgets_manager->register(new Elementor\Howto_Widget());
        $widgets_manager->register(new Elementor\TOC_Widget());
    }

    /**
     * Register (not enqueue) the shared block stylesheets so widgets can
     * declare them via get_style_depends() — Elementor then loads them only
     * on pages that actually use the widget.
     *
     * @return void
     */
    public function register_widget_styles(): void {
        foreach (['faq-block', 'howto-block', 'toc-block'] as $handle) {
            $css = THINKRANK_PLUGIN_DIR . "assets/{$handle}.css";
            if (!file_exists($css)) {
                continue;
            }

            $asset_path = THINKRANK_PLUGIN_DIR . "assets/{$handle}.asset.php";
            $asset = file_exists($asset_path) ? include $asset_path : [];

            wp_register_style(
                "thinkrank-{$handle}",
                THINKRANK_PLUGIN_URL . "assets/{$handle}.css",
                [],
                $asset['version'] ?? THINKRANK_VERSION
            );
        }
    }

    /**
     * In the Elementor editor preview all styles must load up front (the
     * preview doesn't know which widgets will be dropped in).
     *
     * @return void
     */
    public function enqueue_preview_styles(): void {
        $this->register_widget_styles();
        foreach (['faq-block', 'howto-block', 'toc-block'] as $handle) {
            wp_enqueue_style("thinkrank-{$handle}");
        }
    }
}
