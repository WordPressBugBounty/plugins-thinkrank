<?php

/**
 * Beaver Builder Table of Contents Module
 *
 * The Beaver counterpart of the thinkrank/toc Gutenberg block, the Elementor
 * TOC widget and the Bricks TOC element: a heading index built client-side,
 * optionally publishing SiteNavigationElement JSON-LD (#662).
 *
 * The list is built in the browser rather than server-side for the same reason
 * it is in every other build of this widget: the headings it indexes are
 * rendered by other modules, and on a Beaver Builder page they may not even be
 * in this layout — a Themer header or a shortcode can contribute them. Reading
 * the DOM after paint is the only place all of them exist together.
 *
 * @package ThinkRank
 * @subpackage Editor\Beaver
 * @since 2.5.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table of Contents Module.
 *
 * @since 2.5.0
 */
class ThinkRank_Beaver_TOC_Module extends FLBuilderModule {

    /**
     * The module slug, and the settings `type` its stored nodes carry.
     */
    public const SLUG = 'thinkrank-toc';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'name'            => __('Table of Contents (ThinkRank)', 'thinkrank'),
            'description'     => __('An automatic index of the page headings, with navigation schema.', 'thinkrank'),
            'category'        => __('ThinkRank', 'thinkrank'),
            'slug'            => self::SLUG,
            'dir'             => THINKRANK_PLUGIN_DIR . 'includes/editor/beaver/toc/',
            'url'             => THINKRANK_PLUGIN_URL . 'includes/editor/beaver/toc/',
            'partial_refresh' => true,
        ]);
    }

    /**
     * Render the shell, then the script that fills it.
     *
     * @since 2.5.0
     * @param object|array $settings Module settings.
     * @return void
     */
    public function render_content($settings): void {
        $settings = ThinkRank_Beaver_FAQ_Module::to_array($settings);

        wp_enqueue_style('thinkrank-toc-block');

        $max_level  = self::max_level($settings);
        $list_style = self::list_style($settings);

        // Beaver Builder already owns the module's own id/classes on its
        // wrapper, so the script addresses this instance through a data
        // attribute of ours instead of competing for the id. `$this->node` is
        // the layout node id, unique per module instance on the page.
        $uid = sanitize_html_class((string) $this->node);

        $output = '<div class="thinkrank-toc" data-thinkrank-toc="' . esc_attr($uid) . '">';

        $heading = trim((string) ($settings['heading'] ?? ''));
        if ('' !== $heading) {
            $output .= sprintf(
                '<%1$s class="thinkrank-toc__heading">%2$s</%1$s>',
                esc_html(self::heading_tag($settings)),
                esc_html($heading)
            );
        }

        $output .= sprintf(
            '<nav aria-label="%s"><ul class="thinkrank-toc__list thinkrank-toc__list--%s"></ul></nav>',
            esc_attr__('Table of contents', 'thinkrank'),
            esc_attr($list_style)
        );

        $output .= '</div>';

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $this->render_builder_script($uid, $max_level, !empty($settings['output_schema']));
    }

    /**
     * The dependency-free script that fills the list in.
     *
     * It scans Beaver Builder's content wrapper for h2..maxLevel, gives each
     * heading an id if it has none, and appends one linked item per heading.
     * With schema on it then publishes the same anchors as
     * SiteNavigationElement, so the list and the structured data cannot drift.
     *
     * The module hides itself when the page has no headings — an empty "Table
     * of Contents" box is worse than none.
     *
     * @param string $uid       This module instance's layout node id.
     * @param int    $max_level Deepest heading level to include.
     * @param bool   $schema    Whether to publish SiteNavigationElement.
     * @return void
     */
    private function render_builder_script(string $uid, int $max_level, bool $schema): void {
        $selector = implode(',', array_map(
            static fn($level) => 'h' . $level,
            range(2, max(2, $max_level))
        ));

        ?>
        <script>
        ( function() {
            var init = function() {
                var widget = document.querySelector(
                    '[data-thinkrank-toc="' + <?php echo wp_json_encode($uid); ?> + '"]'
                );
                if ( ! widget ) {
                    return;
                }
                var list = widget.querySelector( '.thinkrank-toc__list' );
                if ( ! list ) {
                    return;
                }
                // Beaver Builder's own content wrapper for the post in the main
                // query, so Themer header/footer layouts — which are sibling
                // .fl-builder-content nodes — are not indexed as page sections.
                // Deliberately not closest(): that returns the row or column the
                // module was dropped into, which would list only the headings
                // sharing that container and silently miss the rest of the page.
                var scope = document.querySelector( '.fl-builder-content-primary' )
                    || widget.closest( 'main, article' )
                    || document.body;
                var used = {};
                var entries = [];
                scope.querySelectorAll( <?php echo wp_json_encode($selector); ?> ).forEach( function( el ) {
                    var text = el.textContent.trim();
                    if ( widget.contains( el ) || ! text ) {
                        return;
                    }
                    if ( ! el.id ) {
                        var base = text.toLowerCase()
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
                    a.textContent = text;
                    li.appendChild( a );
                    list.appendChild( li );
                    entries.push( { name: text, id: el.id } );
                } );
                if ( ! entries.length ) {
                    widget.hidden = true;
                    return;
                }
                <?php if ($schema) : ?>
                // The canonical, not location.href: a page reached with a
                // tracking query would otherwise publish anchor URLs carrying
                // it, so the same section gets a different URL per visitor.
                var canonical = document.querySelector( 'link[rel="canonical"]' );
                var base = canonical && canonical.href
                    ? canonical.href.split( '#' )[ 0 ]
                    : window.location.origin + window.location.pathname;
                var graph = entries.map( function( entry ) {
                    return {
                        '@type': 'SiteNavigationElement',
                        name: entry.name,
                        url: base + '#' + entry.id
                    };
                } );
                var tag = document.createElement( 'script' );
                tag.type = 'application/ld+json';
                tag.textContent = JSON.stringify( {
                    '@context': 'https://schema.org',
                    '@graph': graph
                } );
                widget.appendChild( tag );
                <?php endif; ?>
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

    /**
     * Deepest heading level to index, from the allowed set.
     *
     * @param array $settings Module settings.
     * @return int
     */
    private static function max_level(array $settings): int {
        $level = (int) ($settings['max_level'] ?? 3);

        return in_array($level, [2, 3, 4], true) ? $level : 3;
    }

    /**
     * List style from the allowed set.
     *
     * @param array $settings Module settings.
     * @return string
     */
    private static function list_style(array $settings): string {
        $style = (string) ($settings['list_style'] ?? 'disc');

        return in_array($style, ['disc', 'decimal', 'none'], true) ? $style : 'disc';
    }

    /**
     * A heading tag from the allowed set.
     *
     * @param array $settings Module settings.
     * @return string
     */
    private static function heading_tag(array $settings): string {
        $tag = (string) ($settings['heading_tag'] ?? 'h2');

        return in_array($tag, ['h2', 'h3', 'h4', 'p'], true) ? $tag : 'h2';
    }
}

FLBuilder::register_module('ThinkRank_Beaver_TOC_Module', [
    'general' => [
        'title'    => __('Contents', 'thinkrank'),
        'sections' => [
            'content' => [
                'title'  => '',
                'fields' => [
                    'heading'     => [
                        'type'        => 'text',
                        'label'       => __('Title', 'thinkrank'),
                        'default'     => __('Table of Contents', 'thinkrank'),
                        'connections' => ['string'],
                    ],
                    'heading_tag' => [
                        'type'    => 'select',
                        'label'   => __('Heading tag', 'thinkrank'),
                        'default' => 'h2',
                        'options' => [
                            'h2' => 'H2',
                            'h3' => 'H3',
                            'h4' => 'H4',
                            'p'  => __('Paragraph', 'thinkrank'),
                        ],
                    ],
                    'max_level'   => [
                        'type'    => 'select',
                        'label'   => __('Include headings up to', 'thinkrank'),
                        'default' => '3',
                        'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4'],
                    ],
                    'list_style'  => [
                        'type'    => 'select',
                        'label'   => __('List style', 'thinkrank'),
                        'default' => 'disc',
                        'options' => [
                            'disc'    => __('Bulleted', 'thinkrank'),
                            'decimal' => __('Numbered', 'thinkrank'),
                            'none'    => __('Plain', 'thinkrank'),
                        ],
                    ],
                ],
            ],
        ],
    ],
    'schema'  => [
        'title'    => __('Schema', 'thinkrank'),
        'sections' => [
            'schema' => [
                'title'  => '',
                'fields' => [
                    'output_schema' => [
                        'type'    => 'select',
                        'label'   => __('Output navigation schema (JSON-LD)', 'thinkrank'),
                        'default' => '1',
                        'options' => [
                            '1' => __('Yes', 'thinkrank'),
                            '0' => __('No', 'thinkrank'),
                        ],
                        'help'    => __('Adds SiteNavigationElement structured data for the listed sections.', 'thinkrank'),
                    ],
                ],
            ],
        ],
    ],
]);
