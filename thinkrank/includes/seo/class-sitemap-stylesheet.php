<?php
/**
 * Serves the sitemap XSL stylesheets, branded with the site's own logo and colours.
 *
 * @package ThinkRank
 * @since   2.7.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The browser-facing stylesheet for published sitemaps.
 *
 * Until 2.7.0 the `<?xml-stylesheet?>` instruction pointed straight at
 * `static/xsl/sitemap.xsl`, a file the web server hands over untouched. That is
 * why the sitemap could not be branded: nothing of ours runs on that request,
 * so no setting could reach it. The instruction now points here instead, and
 * this answers it from PHP with the stored palette substituted in.
 *
 * Three consequences worth keeping in mind when changing any of this:
 *
 *   - **The .xsl files stay complete and stock.** They are read as templates,
 *     but every brandable colour in them is a CSS custom property holding the
 *     value it has always held, so a sitemap still pointing at the old static
 *     URL renders exactly as it did. Substitution only ever overwrites a
 *     custom property declaration; it never introduces one.
 *   - **Colour changes need no regeneration.** The published XML holds a fixed
 *     URL, and the palette is read per request, so saving a colour is visible
 *     immediately on sitemaps written months ago.
 *   - **The URL is a query string on the home URL, not a pretty path.** A path
 *     only reaches WordPress when the rewrite rules send it there, which on an
 *     Apache-style host running Plain permalinks they do not. The same trap
 *     Instant Indexing documents for its key file (#247).
 *
 * @since 2.7.0
 */
class Sitemap_Stylesheet {

    /**
     * Query variable naming the stylesheet to serve.
     *
     * @since 2.7.0
     * @var string
     */
    public const QUERY_VAR = 'thinkrank_sitemap_xsl';

    /**
     * Stylesheet variants, mapped to their template basename.
     *
     * @since 2.7.0
     * @var array<string, string>
     */
    private const VARIANTS = [
        'sitemap' => 'sitemap.xsl',
        'index'   => 'sitemap-index.xsl',
    ];

    /**
     * The stock palette, mirroring the `:root` block in both templates.
     *
     * Used to answer "what does an unset colour look like?" for the settings
     * screen. The templates remain the source of truth for rendering: an unset
     * colour substitutes nothing at all, rather than substituting these.
     *
     * @since 2.7.0
     * @var array<string, string>
     */
    public const STOCK_PALETTE = [
        'main'   => '#667eea',
        'accent' => '#764ba2',
    ];

    /**
     * Where the logo goes in the rendered header.
     *
     * An XSLT processor strips stylesheet comments from its output, so this
     * marker costs a site with no logo nothing: served un-substituted, it
     * simply never reaches the page.
     *
     * @since 2.7.0
     * @var string
     */
    private const LOGO_MARKER = '<!-- thinkrank:logo -->';

    /**
     * Register the request handler.
     *
     * @since 2.7.0
     * @return void
     */
    public function init(): void {
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('parse_request', [$this, 'maybe_serve']);
    }

    /**
     * Declare the query variable so WordPress keeps it.
     *
     * @since 2.7.0
     * @param string[] $vars Registered public query vars.
     * @return string[]
     */
    public function register_query_var(array $vars): array {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * The URL a published sitemap points its `<?xml-stylesheet?>` at.
     *
     * Single source of truth: the prolog writer and the request matcher below
     * both derive from this, so they cannot drift.
     *
     * @since 2.7.0
     * @param string $variant One of the VARIANTS keys.
     * @return string Absolute stylesheet URL.
     */
    public static function url(string $variant): string {
        if (!isset(self::VARIANTS[$variant])) {
            $variant = 'sitemap';
        }

        return add_query_arg(self::QUERY_VAR, $variant, home_url('/'));
    }

    /**
     * Answer a stylesheet request.
     *
     * @since 2.7.0
     * @param \WP $wp Current WordPress environment instance.
     * @return void
     */
    public function maybe_serve($wp): void {
        if (is_admin()) {
            return;
        }

        $variant = isset($wp->query_vars[self::QUERY_VAR])
            ? sanitize_key((string) $wp->query_vars[self::QUERY_VAR])
            : '';

        if ($variant === '' || !isset(self::VARIANTS[$variant])) {
            return;
        }

        $settings = $this->settings();

        // Styling is opt-in, and turning it off stops the prolog writing the
        // instruction at all. Serving a stylesheet the site has switched off
        // would only paint sitemaps published before the change.
        if (empty($settings['enabled']) || empty($settings['enable_styling'])) {
            status_header(404);
            nocache_headers();
            exit;
        }

        $xsl = $this->render($settings, $variant);

        if ($xsl === '') {
            status_header(404);
            nocache_headers();
            exit;
        }

        // Validated rather than timed. The URL in a published sitemap is fixed,
        // so a freshness window is a window in which a colour the owner just
        // saved is not the colour they see, with nothing they can do but wait
        // it out; a plain `max-age` of an hour had exactly that effect while
        // this was being built. Revalidating every time costs a 304.
        $etag = '"' . md5($xsl) . '"';

        header('Content-Type: text/xsl; charset=UTF-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('ETag: ' . $etag);

        $known = isset($_SERVER['HTTP_IF_NONE_MATCH'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])))
            : '';

        if ($known !== '' && $known === $etag) {
            status_header(304);
            exit;
        }

        status_header(200);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XSL document assembled and escaped in render().
        echo $xsl;
        exit;
    }

    /**
     * Build the stylesheet for a variant with the site's branding applied.
     *
     * @since 2.7.0
     * @param array  $settings Sitemap settings.
     * @param string $variant  One of the VARIANTS keys.
     * @return string XSL document, or '' when the template is unreadable.
     */
    public function render(array $settings, string $variant): string {
        if (!isset(self::VARIANTS[$variant])) {
            return '';
        }

        $path = THINKRANK_PLUGIN_DIR . 'static/xsl/' . self::VARIANTS[$variant];

        if (!is_readable($path)) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read of a fixed file shipped with the plugin.
        $xsl = (string) file_get_contents($path);

        if ($xsl === '') {
            return '';
        }

        foreach ($this->palette($settings) as $property => $value) {
            $xsl = self::set_custom_property($xsl, $property, $value);
        }

        $logo = $this->logo_markup($settings);

        if ($logo === '') {
            // Take the whole line, not just the marker: an XSLT processor drops
            // the comment either way, but leaving a stray indented blank line
            // in a document people do read is needless.
            return (string) preg_replace(
                '/^[ \t]*' . preg_quote(self::LOGO_MARKER, '/') . '\R/m',
                '',
                $xsl,
                1
            );
        }

        return str_replace(self::LOGO_MARKER, $logo, $xsl);
    }

    /**
     * The custom property overrides a site's chosen colours imply.
     *
     * A colour is never simply pasted everywhere it appears. Where it becomes
     * a background the text on top is recomputed for it, and where it becomes
     * text on the page's own white it is darkened until it is readable there.
     * A branding feature that lets an owner pick pale yellow and hands back a
     * sitemap nobody can read is worse than one with no branding at all.
     *
     * An unset colour contributes nothing, which is what keeps an untouched
     * site rendering exactly as it did.
     *
     * @since 2.7.0
     * @param array $settings Sitemap settings.
     * @return array<string, string> Property name => value.
     */
    private function palette(array $settings): array {
        $main   = self::hex($settings['styling_color_main'] ?? '');
        $accent = self::hex($settings['styling_color_accent'] ?? '');

        if ($main === '' && $accent === '') {
            return [];
        }

        $values = [];

        if ($main !== '') {
            // Backgrounds and rules take the colour as picked.
            $values['--tr-brand-main']    = $main;
            $values['--tr-brand-th']      = $main;
            $values['--tr-brand-rule']    = $main;
            $values['--tr-brand-note-bg'] = self::tint($main);

            // Text on those backgrounds is chosen to sit on them.
            $values['--tr-brand-th-text'] = self::readable_ink([$main]);

            // Text on the page itself is darkened to stay legible on white.
            $values['--tr-brand-link'] = self::readable_on_paper($main);
            $values['--tr-brand-note'] = self::readable_on_paper($main);
        }

        if ($accent !== '') {
            $values['--tr-brand-accent']     = $accent;
            $values['--tr-brand-badge']      = $accent;
            $values['--tr-brand-badge-text'] = self::readable_ink([$accent]);
            $values['--tr-brand-link-hover'] = self::readable_on_paper($accent);
        }

        // The header is a gradient, so its text has to clear both stops. An
        // unset colour still contributes the stock one it will render as.
        $values['--tr-brand-on-brand'] = self::readable_ink([
            $main !== '' ? $main : self::STOCK_PALETTE['main'],
            $accent !== '' ? $accent : self::STOCK_PALETTE['accent'],
        ]);

        return $values;
    }

    /**
     * Overwrite one custom property declaration, leaving everything else alone.
     *
     * Deliberately narrow: it rewrites the value of a `--tr-*` declaration that
     * is already in the template and does nothing when that declaration is
     * absent. A template edited past recognition therefore renders stock rather
     * than broken.
     *
     * @since 2.7.0
     * @param string $xsl      Stylesheet source.
     * @param string $property Custom property name, leading dashes included.
     * @param string $value    Replacement value.
     * @return string
     */
    private static function set_custom_property(string $xsl, string $property, string $value): string {
        return (string) preg_replace(
            '/(' . preg_quote($property, '/') . '\s*:\s*)[^;]+/',
            '${1}' . $value,
            $xsl,
            1
        );
    }

    /**
     * The `<img>` that replaces the logo marker, or '' to leave it stripped.
     *
     * @since 2.7.0
     * @param array $settings Sitemap settings.
     * @return string XML-safe markup.
     */
    private function logo_markup(array $settings): string {
        if (empty($settings['styling_logo'])) {
            return '';
        }

        $url = (string) ($settings['styling_logo_url'] ?? '');

        // Nothing picked means the site icon, which is the logo a site has
        // already told WordPress about.
        if (trim($url) === '') {
            $url = (string) get_site_icon_url(192);
        }

        // esc_url_raw() for the protocol allow-list, which is the part that
        // matters for safety, and no display escaping of its own.
        $url = esc_url_raw($url);

        if ($url === '') {
            return '';
        }

        // Escaped for XML explicitly rather than by esc_url()/esc_attr(). Those
        // escape for HTML, where a bare `&` in a query string is tolerated; here
        // the markup is spliced into an XML document, and one unescaped
        // ampersand in a logo URL takes the whole sitemap view down rather than
        // just looking wrong.
        return sprintf(
            '<img class="sitemap-logo" src="%s" alt="%s"/>',
            self::xml_attr($url),
            self::xml_attr((string) get_bloginfo('name'))
        );
    }

    /**
     * Escape a value for an XML attribute.
     *
     * @since 2.7.0
     * @param string $value Raw value.
     * @return string
     */
    private static function xml_attr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Black or white, whichever is legible on these backgrounds.
     *
     * A single background is answered by luminance alone: 0.45 sits between
     * the luminance of a mid grey and a light one, which is where white text
     * stops being readable.
     *
     * The header is a gradient, and a gradient can span a dark stop and a
     * light one. Then no flat ink clears AA on both, and the luminance test
     * alone answers the lightest stop while ignoring the other: navy to cyan
     * picked near-black, which reads at 1.22:1 where the heading actually
     * starts. For more than one stop the ink is therefore judged against its
     * hardest stop, which is what this was always documented to do.
     *
     * @since 2.7.0
     * @param string[] $colours Hex colours, `#rrggbb`.
     * @return string `#ffffff` or `#111111`.
     */
    private static function readable_ink(array $colours): string {
        $lightest = 0.0;

        foreach ($colours as $colour) {
            $lightest = max($lightest, self::luminance($colour));
        }

        $ink = $lightest > 0.45 ? '#111111' : '#ffffff';

        if (count($colours) < 2) {
            return $ink;
        }

        $other = '#111111' === $ink ? '#ffffff' : '#111111';

        // Strictly better, never merely different: the alternative has to beat
        // the luminance test's answer at its worst stop to displace it, so a
        // gradient both inks handle keeps the ink it has always been given.
        return self::worst_contrast($other, $colours) > self::worst_contrast($ink, $colours)
            ? $other
            : $ink;
    }

    /**
     * The lowest WCAG contrast ratio an ink reaches across several backgrounds.
     *
     * @since 2.7.0
     * @param string   $ink     Hex colour the text is drawn in.
     * @param string[] $colours Hex backgrounds it has to sit on.
     * @return float Contrast ratio, 1.0 when there is nothing to sit on.
     */
    private static function worst_contrast(string $ink, array $colours): float {
        $worst = null;

        foreach ($colours as $colour) {
            $high = max(self::luminance($ink), self::luminance($colour));
            $low  = min(self::luminance($ink), self::luminance($colour));

            $ratio = ($high + 0.05) / ($low + 0.05);
            $worst = null === $worst ? $ratio : min($worst, $ratio);
        }

        return null === $worst ? 1.0 : $worst;
    }

    /**
     * The same colour, darkened until it is readable as text on white.
     *
     * Links, and the note panel's body text, sit on the page's own background
     * rather than on the brand colour, so a pale brand has to be brought down
     * to meet it. Returned unchanged when it already clears WCAG AA.
     *
     * @since 2.7.0
     * @param string $colour Hex colour, `#rrggbb`.
     * @return string Hex colour.
     */
    private static function readable_on_paper(string $colour): string {
        // Contrast against white is 1.05 / (L + 0.05); AA body text wants 4.5.
        $ceiling = (1.05 / 4.5) - 0.05;

        if (self::luminance($colour) <= $ceiling) {
            return $colour;
        }

        $hex = ltrim($colour, '#');

        if (strlen($hex) !== 6) {
            return $colour;
        }

        $channels = [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];

        // Scale the channels down together so the hue survives the darkening,
        // in small steps so the result clears the bar without overshooting it
        // into something near-black the owner would not recognise.
        for ($factor = 0.98; $factor > 0.0; $factor -= 0.02) {
            $candidate = '#';

            foreach ($channels as $channel) {
                $candidate .= str_pad(dechex((int) round($channel * $factor)), 2, '0', STR_PAD_LEFT);
            }

            if (self::luminance($candidate) <= $ceiling) {
                return $candidate;
            }
        }

        return '#111111';
    }

    /**
     * A colour mixed most of the way into white.
     *
     * Computed here rather than left to CSS `color-mix()`, so the value in the
     * served stylesheet is a plain hex and the template stays renderable by
     * anything that can read the stock one.
     *
     * @since 2.7.0
     * @param string $colour Hex colour, `#rrggbb`.
     * @return string Hex colour.
     */
    private static function tint(string $colour): string {
        $hex = ltrim($colour, '#');

        if (strlen($hex) !== 6) {
            return $colour;
        }

        $out = '#';

        foreach ([0, 2, 4] as $offset) {
            $channel = (int) hexdec(substr($hex, $offset, 2));
            $out    .= str_pad(dechex((int) round(255 - ((255 - $channel) * 0.10))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * WCAG relative luminance of a hex colour, 0 (black) to 1 (white).
     *
     * @since 2.7.0
     * @param string $colour Hex colour, `#rrggbb`.
     * @return float
     */
    private static function luminance(string $colour): float {
        $hex = ltrim($colour, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6) {
            return 0.0;
        }

        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value      = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : pow(($value + 0.055) / 1.055, 2.4);
        }

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    /**
     * A stored colour, normalized, or '' when it is not one.
     *
     * '' is the meaningful default: it means "substitute nothing", so the
     * template's own value stands and an untouched site renders byte for byte
     * as it did before this feature existed.
     *
     * @since 2.7.0
     * @param mixed $value Stored setting value.
     * @return string `#rrggbb`, or ''.
     */
    public static function hex($value): string {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if ($value[0] !== '#') {
            $value = '#' . $value;
        }

        $colour = sanitize_hex_color(strtolower($value));

        return is_string($colour) ? $colour : '';
    }

    /**
     * Stored sitemap settings.
     *
     * @since 2.7.0
     * @return array
     */
    private function settings(): array {
        return (new Sitemap_Generator(false))->get_settings('site', null);
    }
}
