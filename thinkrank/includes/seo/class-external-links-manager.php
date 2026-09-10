<?php

/**
 * External Links Manager Class
 *
 * Render-time rewriting of outbound anchors: rel="nofollow" and
 * target="_blank" (with the noopener that has to ride along), plus a
 * per-domain exception list for partners and affiliates the site DOES want
 * to pass equity to.
 *
 * Nothing here touches stored post content — the rewrite happens on
 * `the_content`, so turning either toggle off restores the author's markup
 * byte for byte.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 2.5.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use DOMDocument;
use DOMElement;
use DOMXPath;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * External Links Manager Class
 *
 * @since 2.5.0
 */
class External_Links_Manager extends Abstract_SEO_Manager {

    /**
     * URL schemes that never point at another site's page.
     *
     * `mailto:` and `tel:` are actions, not documents; `javascript:` and
     * `data:` are not navigations we should be annotating either. All are
     * left exactly as authored.
     *
     * @since 2.5.0
     * @var string[]
     */
    private const IGNORED_SCHEMES = ['mailto', 'tel', 'sms', 'javascript', 'data', 'ftp'];

    /**
     * Memoized host of home_url(), lowercased and stripped of "www.".
     *
     * @since 2.5.0
     * @var string|null
     */
    private ?string $home_host = null;

    /**
     * Memoized site settings for the current request.
     *
     * process_content() runs once per rendered post, and get_settings() is
     * cached anyway — this just keeps the common "both toggles off" exit
     * from touching the cache API on every filter pass.
     *
     * @since 2.5.0
     * @var array|null
     */
    private ?array $settings_cache = null;

    /**
     * Constructor
     *
     * @since 2.5.0
     */
    public function __construct() {
        parent::__construct('external_links');
    }

    /**
     * Default settings (implements interface)
     *
     * Both toggles ship off: rewriting every outbound link is a deliberate
     * editorial choice, not a sane default to impose on existing content.
     *
     * @since 2.5.0
     *
     * @param string $context_type The context type
     * @return array Default settings
     */
    public function get_default_settings(string $context_type): array {
        return [
            'nofollow_external'        => false,
            'open_external_in_new_tab' => false,
            'external_link_exceptions' => [],
        ];
    }

    /**
     * Keys coerced to real booleans on read.
     *
     * @since 2.5.0
     *
     * @return string[]
     */
    protected function boolean_setting_keys(): array {
        return array_merge(
            parent::boolean_setting_keys(),
            ['nofollow_external', 'open_external_in_new_tab']
        );
    }

    /**
     * Settings schema (implements interface)
     *
     * @since 2.5.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'nofollow_external' => [
                'type'        => 'boolean',
                'title'       => __('Add nofollow to external links', 'thinkrank'),
                'description' => __('Adds rel="nofollow" to links pointing at another domain.', 'thinkrank'),
                'default'     => false,
            ],
            'open_external_in_new_tab' => [
                'type'        => 'boolean',
                'title'       => __('Open external links in a new tab', 'thinkrank'),
                'description' => __('Adds target="_blank" (and the required rel="noopener") to links pointing at another domain.', 'thinkrank'),
                'default'     => false,
            ],
            'external_link_exceptions' => [
                'type'        => 'array',
                'title'       => __('Exceptions', 'thinkrank'),
                'description' => __('Domains that keep the markup the author wrote. Matched on host, subdomains included.', 'thinkrank'),
                'items'       => ['type' => 'string'],
                'default'     => [],
            ],
        ];
    }

    /**
     * Validate settings (implements interface)
     *
     * @since 2.5.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid'       => true,
            'errors'      => [],
            'warnings'    => [],
            'suggestions' => [],
            'score'       => 100,
        ];

        foreach (['nofollow_external', 'open_external_in_new_tab'] as $key) {
            if (isset($settings[$key]) && !is_bool($settings[$key]) && !is_numeric($settings[$key])) {
                $validation['valid']    = false;
                $validation['errors'][] = sprintf(
                    /* translators: %s: setting key */
                    __('%s must be a boolean value.', 'thinkrank'),
                    $key
                );
            }
        }

        if (isset($settings['external_link_exceptions']) && !is_array($settings['external_link_exceptions'])) {
            $validation['valid']    = false;
            $validation['errors'][] = __('The exception list must be an array of domains.', 'thinkrank');
        }

        if (empty($settings['nofollow_external']) && empty($settings['open_external_in_new_tab'])) {
            $validation['suggestions'][] = __('Both external link options are off, so outbound links render exactly as authored.', 'thinkrank');
        }

        return $validation;
    }

    /**
     * Output data (implements interface)
     *
     * @since 2.5.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        return $this->get_site_settings();
    }

    /**
     * Whether either toggle is on.
     *
     * @since 2.5.0
     *
     * @return bool
     */
    public function is_active(): bool {
        $settings = $this->get_site_settings();

        return !empty($settings['nofollow_external']) || !empty($settings['open_external_in_new_tab']);
    }

    /**
     * Rewrite external anchors in a block of rendered HTML.
     *
     * Returns the input untouched whenever there is nothing to do — both
     * toggles off, no anchor in the markup, or a parse that failed — so the
     * filter is a no-op rather than a risk on content it cannot handle.
     *
     * @since 2.5.0
     *
     * @param string $content Rendered HTML.
     * @return string Rewritten HTML.
     */
    public function process_content(string $content): string {
        // `<a ` would miss an anchor whose attributes start on the next line,
        // which hand-written and page-builder markup does routinely.
        if ($content === '' || !preg_match('/<a[\s>]/i', $content)) {
            return $content;
        }

        $settings = $this->get_site_settings();
        $nofollow = !empty($settings['nofollow_external']);
        $new_tab  = !empty($settings['open_external_in_new_tab']);

        if (!$nofollow && !$new_tab) {
            return $content;
        }

        $exceptions = $this->normalize_exceptions($settings['external_link_exceptions'] ?? []);

        return $this->rewrite_anchors($content, $nofollow, $new_tab, $exceptions);
    }

    /**
     * Parse, rewrite and re-serialize the anchors of an HTML fragment.
     *
     * DOMDocument rather than a regex: an attribute has to be READ (to keep
     * what the author set) before it is written, and the shapes a real
     * anchor takes — unquoted values, a `>` inside an attribute, nested
     * markup — are exactly what regex gets wrong when it mutates.
     *
     * @since 2.5.0
     *
     * @param string   $content    HTML fragment.
     * @param bool     $nofollow   Whether to add rel="nofollow".
     * @param bool     $new_tab    Whether to add target="_blank".
     * @param string[] $exceptions Normalized exception hosts.
     * @return string
     */
    private function rewrite_anchors(string $content, bool $nofollow, bool $new_tab, array $exceptions): string {
        // Attribute-level edits on the original bytes: no parse and
        // re-serialize cycle, so nothing outside the anchors it touches can be
        // rewritten at all.
        if (class_exists('WP_HTML_Tag_Processor')) {
            return $this->rewrite_anchors_with_tag_processor($content, $nofollow, $new_tab, $exceptions);
        }

        // WordPress 6.0/6.1 have no Tag Processor, and libxml's HTML parser
        // case-folds element and attribute names. SVG is case-sensitive XML, so
        // a round-trip turns `viewBox` into the ignored `viewbox` and
        // `<linearGradient>` into an element that does not exist — a gradient
        // reference then resolves to nothing and the graphic breaks. Leaving
        // such content as authored is the only honest option here: a missing
        // rel attribute is a smaller failure than a destroyed illustration.
        if (stripos($content, '<svg') !== false) {
            return $content;
        }

        return $this->rewrite_anchors_with_dom($content, $nofollow, $new_tab, $exceptions);
    }

    /**
     * Rewrite anchors through WP_HTML_Tag_Processor.
     *
     * Anchors inside `<svg>` and `<math>` are skipped: foreign content has its
     * own `<a>`, which is not an outbound HTML link and takes neither `rel` nor
     * `target` the way this feature means them.
     *
     * @since 2.5.0
     *
     * @param string   $content    HTML fragment.
     * @param bool     $nofollow   Whether to add rel="nofollow".
     * @param bool     $new_tab    Whether to add target="_blank".
     * @param string[] $exceptions Normalized exception hosts.
     * @return string
     */
    private function rewrite_anchors_with_tag_processor(string $content, bool $nofollow, bool $new_tab, array $exceptions): string {
        $tags    = new \WP_HTML_Tag_Processor($content);
        $foreign = 0;
        $changed = false;

        while ($tags->next_tag(['tag_closers' => 'visit'])) {
            $tag = (string) $tags->get_tag();

            if ('SVG' === $tag || 'MATH' === $tag) {
                if ($tags->is_tag_closer()) {
                    $foreign = max(0, $foreign - 1);
                } elseif (!$tags->has_self_closing_flag()) {
                    $foreign++;
                }

                continue;
            }

            if ($foreign > 0 || 'A' !== $tag || $tags->is_tag_closer()) {
                continue;
            }

            $href = $tags->get_attribute('href');

            if (!is_string($href) || !$this->is_external_url($href, $exceptions)) {
                continue;
            }

            $rel    = $tags->get_attribute('rel');
            $target = $tags->get_attribute('target');

            $updates = $this->attribute_updates(
                is_string($rel) ? $rel : '',
                is_string($target) ? $target : '',
                $nofollow,
                $new_tab
            );

            foreach ($updates as $name => $value) {
                $tags->set_attribute($name, $value);
                $changed = true;
            }
        }

        return $changed ? $tags->get_updated_html() : $content;
    }

    /**
     * Rewrite anchors through DOMDocument, for WordPress before 6.2.
     *
     * @since 2.5.0
     *
     * @param string   $content    HTML fragment.
     * @param bool     $nofollow   Whether to add rel="nofollow".
     * @param bool     $new_tab    Whether to add target="_blank".
     * @param string[] $exceptions Normalized exception hosts.
     * @return string
     */
    private function rewrite_anchors_with_dom(string $content, bool $nofollow, bool $new_tab, array $exceptions): string {
        $dom       = new DOMDocument();
        $libxml    = libxml_use_internal_errors(true);
        $wrapper   = '<div id="thinkrank-external-links-root">' . $content . '</div>';
        // The XML declaration pins UTF-8 without mb_convert_encoding()'s
        // HTML-ENTITIES mode, which is removed in PHP 8.2.
        $loaded    = $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $wrapper,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($libxml);

        if (!$loaded) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        // getElementById() is unreliable on a DTD-less HTML fragment; the
        // wrapper is found by attribute instead. With LIBXML_HTML_NOIMPLIED
        // it is normally documentElement, but not on markup libxml chose to
        // restructure.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own property name.
        $root = $dom->documentElement;

        if (!$root instanceof DOMElement || $root->getAttribute('id') !== 'thinkrank-external-links-root') {
            $found = $xpath->query('//div[@id="thinkrank-external-links-root"]');
            $root  = ($found && $found->length > 0) ? $found->item(0) : null;
        }

        if (!$root instanceof DOMElement) {
            return $content;
        }

        $anchors = $xpath->query('.//a[@href]', $root);

        if ($anchors === false || $anchors->length === 0) {
            return $content;
        }

        $changed = false;

        foreach ($anchors as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }

            if (!$this->is_external_url($anchor->getAttribute('href'), $exceptions)) {
                continue;
            }

            $changed = $this->apply_attributes($anchor, $nofollow, $new_tab) || $changed;
        }

        if (!$changed) {
            return $content;
        }

        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }

    /**
     * Add the configured tokens to one anchor, preserving what is there.
     *
     * `rel` and `target` are appended to, never replaced: an author who
     * wrote rel="sponsored" or target="_self" meant it, and a setting that
     * silently overwrote them would be a data loss the user cannot see.
     *
     * @since 2.5.0
     *
     * @param DOMElement $anchor   Anchor element.
     * @param bool       $nofollow Whether to add rel="nofollow".
     * @param bool       $new_tab  Whether to add target="_blank".
     * @return bool Whether the element was modified.
     */
    private function apply_attributes(DOMElement $anchor, bool $nofollow, bool $new_tab): bool {
        $updates = $this->attribute_updates(
            $anchor->getAttribute('rel'),
            $anchor->getAttribute('target'),
            $nofollow,
            $new_tab
        );

        foreach ($updates as $name => $value) {
            $anchor->setAttribute($name, $value);
        }

        return !empty($updates);
    }

    /**
     * The attributes one external anchor needs written, given what it already
     * carries. Empty when it already says everything the settings ask for.
     *
     * `rel` and `target` are appended to, never replaced: an author who wrote
     * rel="sponsored" or target="_self" meant it.
     *
     * `noopener` rides along with a new tab only when the link actually opens
     * one — ours or the author's. On a target="_self" link it protects
     * nothing, and adding it would override in spirit the very attribute this
     * method refuses to overwrite.
     *
     * @since 2.5.0
     *
     * @param string $rel      Current rel attribute.
     * @param string $target   Current target attribute.
     * @param bool   $nofollow Whether to add rel="nofollow".
     * @param bool   $new_tab  Whether to add target="_blank".
     * @return array<string,string> Attribute name => new value.
     */
    private function attribute_updates(string $rel, string $target, bool $nofollow, bool $new_tab): array {
        $updates = [];

        if ($new_tab && trim($target) === '') {
            $updates['target'] = '_blank';
            $target            = '_blank';
        }

        $rel_add = [];

        if ($nofollow) {
            $rel_add[] = 'nofollow';
        }

        if (strtolower(trim($target)) === '_blank') {
            $rel_add[] = 'noopener';
        }

        if (empty($rel_add)) {
            return $updates;
        }

        $existing = preg_split('/\s+/', trim($rel)) ?: [];
        $existing = array_values(array_filter($existing, static fn($token) => $token !== ''));
        $lower    = array_map('strtolower', $existing);
        $tokens   = $existing;
        $added    = false;

        foreach ($rel_add as $token) {
            if (!in_array($token, $lower, true)) {
                $tokens[] = $token;
                $lower[]  = $token;
                $added    = true;
            }
        }

        if ($added) {
            $updates['rel'] = implode(' ', $tokens);
        }

        return $updates;
    }

    /**
     * Whether a href points at a different site and is not excepted.
     *
     * Relative paths, fragments, protocol-relative links to our own host and
     * anything in IGNORED_SCHEMES all answer false.
     *
     * @since 2.5.0
     *
     * @param string   $href       Raw href attribute.
     * @param string[] $exceptions Normalized exception hosts.
     * @return bool
     */
    private function is_external_url(string $href, array $exceptions): bool {
        $href = trim($href);

        if ($href === '' || strpos($href, '#') === 0 || (strpos($href, '/') === 0 && strpos($href, '//') !== 0)) {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($href, PHP_URL_SCHEME));

        if ($scheme !== '' && in_array($scheme, self::IGNORED_SCHEMES, true)) {
            return false;
        }

        $host = $this->normalize_host((string) wp_parse_url($href, PHP_URL_HOST));

        // No host at all means a relative link — same site by definition.
        if ($host === '') {
            return false;
        }

        if ($host === $this->get_home_host()) {
            return false;
        }

        foreach ($exceptions as $exception) {
            if ($host === $exception || substr($host, -(strlen($exception) + 1)) === '.' . $exception) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize the exception list to comparable hosts.
     *
     * Accepts what a user actually types: a bare domain, a full URL, a
     * leading "www.", trailing slashes, or a newline-separated blob.
     *
     * @since 2.5.0
     *
     * @param array|string $exceptions Stored exception list.
     * @return string[]
     */
    public function normalize_exceptions($exceptions): array {
        if (is_string($exceptions)) {
            $exceptions = preg_split('/[\r\n,]+/', $exceptions) ?: [];
        }

        if (!is_array($exceptions)) {
            return [];
        }

        $hosts = [];

        foreach ($exceptions as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (strpos($entry, '//') !== false) {
                $entry = (string) wp_parse_url($entry, PHP_URL_HOST);
            } else {
                // "example.com/path" — keep the host half only.
                $entry = explode('/', $entry)[0];
            }

            $host = $this->normalize_host($entry);

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Lowercase a host and drop a leading "www." so comparisons match.
     *
     * @since 2.5.0
     *
     * @param string $host Host name.
     * @return string
     */
    private function normalize_host(string $host): string {
        $host = strtolower(trim($host));

        if ($host === '') {
            return '';
        }

        // Strip a userinfo prefix and any port.
        $at = strrpos($host, '@');
        if ($at !== false) {
            $host = substr($host, $at + 1);
        }

        $host = explode(':', $host)[0];

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return trim($host, '.');
    }

    /**
     * Host of home_url(), normalized and memoized.
     *
     * @since 2.5.0
     *
     * @return string
     */
    private function get_home_host(): string {
        if ($this->home_host === null) {
            $this->home_host = $this->normalize_host((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        }

        return $this->home_host;
    }

    /**
     * Site-wide settings for this manager, memoized per request.
     *
     * @since 2.5.0
     *
     * @return array
     */
    private function get_site_settings(): array {
        if ($this->settings_cache === null) {
            $this->settings_cache = $this->get_settings('site');
        }

        return $this->settings_cache;
    }

    /**
     * Drop the memoized settings so a save is visible without a new request.
     *
     * @since 2.5.0
     *
     * @return void
     */
    public function flush_runtime_cache(): void {
        $this->settings_cache = null;
    }
}
