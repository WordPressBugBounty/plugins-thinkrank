<?php

/**
 * Elementor Table of Contents Widget
 *
 * The Elementor counterpart of the thinkrank/toc Gutenberg block. Elementor
 * content has no block tree to derive headings from server-side, so the list
 * is built client-side: a small dependency-free script scans the page's
 * headings, assigns ids where missing, and renders the linked list — the
 * same approach Elementor Pro's own TOC widget uses.
 *
 * @package ThinkRank
 * @subpackage Editor\Elementor
 * @since 1.18.0
 */

declare(strict_types=1);

namespace ThinkRank\Editor\Elementor;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TOC Widget.
 *
 * @since 1.18.0
 */
class TOC_Widget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'thinkrank-toc';
    }

    public function get_title(): string {
        return __('Table of Contents (ThinkRank)', 'thinkrank');
    }

    public function get_icon(): string {
        return 'eicon-table-of-contents';
    }

    public function get_categories(): array {
        return ['thinkrank'];
    }

    public function get_keywords(): array {
        return ['table of contents', 'toc', 'index', 'anchor', 'thinkrank'];
    }

    public function get_style_depends(): array {
        return ['thinkrank-toc-block'];
    }

    protected function register_controls(): void {
        $this->start_controls_section('section_content', [
            'label' => __('Table of Contents', 'thinkrank'),
        ]);

        $this->add_control('heading', [
            'label'       => __('Title', 'thinkrank'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => __('Table of Contents', 'thinkrank'),
            'label_block' => true,
        ]);

        $this->add_control('heading_tag', [
            'label'   => __('Heading tag', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'h2',
            'options' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => __('Paragraph', 'thinkrank')],
        ]);

        $this->add_control('max_level', [
            'label'   => __('Include headings up to', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4'],
        ]);

        $this->add_control('list_style', [
            'label'   => __('List style', 'thinkrank'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'disc',
            'options' => [
                'disc'    => __('Bulleted', 'thinkrank'),
                'decimal' => __('Numbered', 'thinkrank'),
                'none'    => __('Plain', 'thinkrank'),
            ],
        ]);

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $heading_tag = in_array($settings['heading_tag'] ?? 'h2', ['h2', 'h3', 'h4', 'p'], true)
            ? $settings['heading_tag']
            : 'h2';
        $max_level  = in_array($settings['max_level'] ?? '3', ['2', '3', '4'], true) ? (int) $settings['max_level'] : 3;
        $list_style = in_array($settings['list_style'] ?? 'disc', ['disc', 'decimal', 'none'], true)
            ? $settings['list_style']
            : 'disc';
        $widget_id = 'thinkrank-toc-' . esc_attr($this->get_id());

        echo '<div class="thinkrank-toc" id="' . $widget_id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if (!empty($settings['heading'])) {
            printf(
                '<%1$s class="thinkrank-toc__heading">%2$s</%1$s>',
                esc_html($heading_tag), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html($settings['heading'])
            );
        }

        printf(
            '<nav aria-label="%s"><ul class="thinkrank-toc__list thinkrank-toc__list--%s"></ul></nav>',
            esc_attr__('Table of contents', 'thinkrank'),
            esc_attr($list_style)
        );

        echo '</div>';

        $this->render_builder_script($widget_id, $max_level);
    }

    /**
     * Inline, dependency-free script that builds the list from the page's
     * headings (h2..maxLevel), assigning ids to headings that lack one.
     *
     * @param string $widget_id DOM id of this widget instance.
     * @param int    $max_level Deepest heading level to include.
     * @return void
     */
    private function render_builder_script(string $widget_id, int $max_level): void {
        $selector = implode(',', array_map(
            static fn($level) => 'h' . $level,
            range(2, max(2, $max_level))
        ));

        ?>
        <script>
        ( function() {
            var init = function() {
                var widget = document.getElementById( <?php echo wp_json_encode($widget_id); ?> );
                if ( ! widget ) {
                    return;
                }
                var list = widget.querySelector( '.thinkrank-toc__list' );
                var scope = widget.closest( '[data-elementor-type]' ) || document.body;
                var used = {};
                var count = 0;
                scope.querySelectorAll( <?php echo wp_json_encode($selector); ?> ).forEach( function( el ) {
                    if ( widget.contains( el ) || ! el.textContent.trim() ) {
                        return;
                    }
                    if ( ! el.id ) {
                        var base = el.textContent.trim().toLowerCase()
                            .normalize( 'NFKD' ).replace( /[̀-ͯ]/g, '' )
                            .replace( /[^a-z0-9\s-]/g, '' ).trim()
                            .replace( /[\s-]+/g, '-' ) || 'section';
                        var id = base, n = 2;
                        while ( used[ id ] || document.getElementById( id ) ) {
                            id = base + '-' + ( n++ );
                        }
                        el.id = id;
                    }
                    used[ el.id ] = true;
                    var li = document.createElement( 'li' );
                    li.className = 'thinkrank-toc__item thinkrank-toc__item--level-' + el.tagName.charAt( 1 );
                    var a = document.createElement( 'a' );
                    a.href = '#' + el.id;
                    a.textContent = el.textContent.trim();
                    li.appendChild( a );
                    list.appendChild( li );
                    count++;
                } );
                if ( ! count ) {
                    widget.style.display = 'none';
                }
            };
            if ( 'loading' === document.readyState ) {
                document.addEventListener( 'DOMContentLoaded', init );
            } else {
                init();
            }
        } )();
        </script>
        <?php
    }
}
