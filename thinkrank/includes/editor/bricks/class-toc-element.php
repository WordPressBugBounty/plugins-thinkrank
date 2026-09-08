<?php

/**
 * Bricks Table of Contents Element
 *
 * The Bricks counterpart of the thinkrank/toc Gutenberg block (#626).
 *
 * The block builds its list server-side because it can: a block tree holds the
 * page's headings and their anchors as stored attributes. A Bricks page's
 * headings are spread across `heading`, `text-basic`, rich text and nested
 * components, most of them rendered without an `id` for a link to point at, and
 * a content template can add more that live on another post entirely. Deriving
 * the list from stored settings would therefore list headings that the page has
 * no anchor for, and miss ones it does have.
 *
 * So the list is built from the rendered DOM, which is the only place the real
 * answer exists — the same approach the Elementor widget and Elementor Pro's own
 * TOC take. The script assigns ids to headings that lack them, then builds the
 * list from what it found.
 *
 * SiteNavigationElement is emitted from that same pass rather than server-side.
 * That keeps a single source of truth: every entry in the JSON-LD is an anchor
 * that is genuinely on the page and genuinely reachable, which a server-side
 * guess could not promise. Google renders pages before extracting structured
 * data, so script-inserted JSON-LD is read the same as inline.
 *
 * @package ThinkRank
 * @subpackage Editor\Bricks
 * @since 2.3.1
 */

declare(strict_types=1);

namespace ThinkRank\Editor\Bricks;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TOC Element.
 *
 * @since 2.3.1
 */
class TOC_Element extends \Bricks\Element {

    /**
     * Builder category.
     *
     * @var string
     */
    public $category = 'thinkrank';

    /**
     * Element name, matching the Elementor widget's.
     *
     * @var string
     */
    public $name = 'thinkrank-toc';

    /**
     * Panel icon.
     *
     * @var string
     */
    public $icon = 'ti-menu-alt';

    /**
     * Label shown in the element panel.
     *
     * @return string
     */
    public function get_label(): string {
        return esc_html__('Table of Contents (ThinkRank)', 'thinkrank');
    }

    /**
     * Panel search terms.
     *
     * @return array
     */
    public function get_keywords(): array {
        return ['table of contents', 'toc', 'index', 'anchor', 'thinkrank'];
    }

    /**
     * Load the shared block stylesheet, but only on a page using this element.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        wp_enqueue_style('thinkrank-toc-block');
    }

    /**
     * Controls.
     *
     * @return void
     */
    public function set_controls(): void {
        $this->controls['heading'] = [
            'label'   => esc_html__('Title', 'thinkrank'),
            'type'    => 'text',
            'default' => esc_html__('Table of Contents', 'thinkrank'),
        ];

        $this->controls['headingTag'] = [
            'label'   => esc_html__('Heading tag', 'thinkrank'),
            'type'    => 'select',
            'options' => [
                'h2' => 'H2',
                'h3' => 'H3',
                'h4' => 'H4',
                'p'  => esc_html__('Paragraph', 'thinkrank'),
            ],
            'default' => 'h2',
            'inline'  => true,
        ];

        $this->controls['maxLevel'] = [
            'label'   => esc_html__('Include headings up to', 'thinkrank'),
            'type'    => 'select',
            'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4'],
            'default' => '3',
            'inline'  => true,
        ];

        $this->controls['listStyle'] = [
            'label'   => esc_html__('List style', 'thinkrank'),
            'type'    => 'select',
            'options' => [
                'disc'    => esc_html__('Bulleted', 'thinkrank'),
                'decimal' => esc_html__('Numbered', 'thinkrank'),
                'none'    => esc_html__('Plain', 'thinkrank'),
            ],
            'default' => 'disc',
            'inline'  => true,
        ];

        $this->controls['outputSchema'] = [
            'label'       => esc_html__('Output navigation schema (JSON-LD)', 'thinkrank'),
            'type'        => 'checkbox',
            'default'     => true,
            'description' => esc_html__('Adds SiteNavigationElement structured data for the listed sections.', 'thinkrank'),
        ];
    }

    /**
     * Render.
     *
     * @return void
     */
    public function render(): void {
        $settings = $this->settings;

        $max_level  = self::max_level($settings);
        $list_style = self::list_style($settings);

        // Bricks already puts its own `brxe-<id>` on the root, and
        // `set_attribute()` appends rather than replaces — setting an `id` here
        // produced `id="brxe-etoc thinkrank-toc-etoc"`, one invalid id
        // containing a space that `getElementById()` could never match. A data
        // attribute is ours alone and collides with nothing.
        $uid = sanitize_html_class((string) $this->id);

        $this->set_attribute('_root', 'class', 'thinkrank-toc');
        $this->set_attribute('_root', 'data-thinkrank-toc', $uid);

        $output = '<div ' . $this->render_attributes('_root') . '>';

        $heading = trim((string) ($settings['heading'] ?? ''));
        if ('' !== $heading) {
            $output .= sprintf(
                '<%1$s class="thinkrank-toc__heading">%2$s</%1$s>',
                esc_html(self::heading_tag($settings)),
                esc_html($this->render_dynamic_data($heading))
            );
        }

        $output .= sprintf(
            '<nav aria-label="%s"><ul class="thinkrank-toc__list thinkrank-toc__list--%s"></ul></nav>',
            esc_attr__('Table of contents', 'thinkrank'),
            esc_attr($list_style)
        );

        $output .= '</div>';

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $this->render_builder_script($uid, $max_level, !empty($settings['outputSchema']));
    }

    /**
     * The dependency-free script that fills the list in.
     *
     * It scans the Bricks content wrapper for h2..maxLevel, gives each heading
     * an id if it has none, and appends one linked item per heading. With
     * schema on it then publishes the same anchors as SiteNavigationElement, so
     * the list and the structured data cannot drift apart.
     *
     * The element hides itself when the page has no headings — an empty
     * "Table of Contents" box is worse than none.
     *
     * @param string $uid       This element instance's Bricks id.
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
                // Bricks' own content wrapper, so site chrome is not listed.
                // Widened deliberately rather than taking the nearest match:
                // closest() returns the FIRST ancestor matching any selector in
                // the list, and on a Bricks page that is the container the
                // element was dropped into — which would list only the headings
                // sharing that container and silently miss the rest of the
                // article. #brx-content is the wrapper actually meant here.
                var scope = document.getElementById( 'brx-content' )
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
     * Deepest heading level to list.
     *
     * @param array $settings Element settings.
     * @return int
     */
    private static function max_level(array $settings): int {
        $level = (string) ($settings['maxLevel'] ?? '3');

        return in_array($level, ['2', '3', '4'], true) ? (int) $level : 3;
    }

    /**
     * List marker style.
     *
     * @param array $settings Element settings.
     * @return string
     */
    private static function list_style(array $settings): string {
        $style = (string) ($settings['listStyle'] ?? 'disc');

        return in_array($style, ['disc', 'decimal', 'none'], true) ? $style : 'disc';
    }

    /**
     * A heading tag from the allowed set.
     *
     * @param array $settings Element settings.
     * @return string
     */
    private static function heading_tag(array $settings): string {
        $tag = (string) ($settings['headingTag'] ?? 'h2');

        return in_array($tag, ['h2', 'h3', 'h4', 'p'], true) ? $tag : 'h2';
    }
}
