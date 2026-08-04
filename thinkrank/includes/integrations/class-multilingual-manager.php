<?php
/**
 * Multilingual (WPML / Polylang / TranslatePress) integration.
 *
 * Auto-enables when a supported multilingual plugin is active and fills the
 * gaps ThinkRank leaves on a translated site:
 *
 *  - `og:locale:alternate` for every translated language. None of the three
 *    supported plugins emits Open Graph locale alternates, so without this the
 *    social crawlers see a single-language site.
 *  - `hreflang` alternates, but ONLY when the active multilingual plugin did
 *    not already print them for this request. All three ship their own
 *    hreflang output, so emitting ours unconditionally would produce
 *    duplicate, competing alternates — worse than the gap it set out to fix.
 *    WPML in particular registers its callback unconditionally and then gates
 *    the actual output behind internal `must_render()` checks, so "the setting
 *    is enabled" does not imply "tags were printed". We therefore observe what
 *    WPML did during the request and only fill in when it printed nothing.
 *  - Language-aware sitemaps, via the sitemap query filters. What this needs
 *    in practice was measured rather than assumed — see the two filter methods
 *    at the bottom of this class.
 *
 * A note on TranslatePress, which is architecturally unlike the other two: it
 * does not create a post per language. One post is rendered and its output is
 * translated on the way out, so there is nothing extra for the sitemap to find
 * and no per-language post meta to reconcile — the whole sitemap side is a
 * no-op there. What it does need is `og:locale`, which TranslatePress never
 * touches at all: without this integration every translated URL advertises the
 * site's default locale to social crawlers.
 *
 * @package ThinkRank\Integrations
 * @since 1.23.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridges ThinkRank's SEO output with WPML and Polylang.
 */
class Multilingual_Manager {

    /**
     * Active provider: 'wpml', 'polylang', 'translatepress', or '' when the
     * site is monolingual.
     *
     * @var string
     */
    private $provider = '';

    /**
     * Whether the active plugin actually printed hreflang for this request.
     *
     * Observed rather than assumed: WPML registers its hreflang callback
     * unconditionally but then gates the output behind its own `must_render()`
     * checks, so "the setting is on" is not the same as "tags were printed".
     * We watch for its filter instead and only fill in when nothing appeared.
     *
     * @var bool
     */
    private $provider_printed_hreflang = false;

    /**
     * Wire up hooks when a multilingual plugin is active.
     *
     * @return void
     */
    public function init(): void {
        $this->provider = $this->detect_provider();

        if ($this->provider === '') {
            return;
        }

        // Keep sitemap queries covering every language. Registered for admin
        // and front end alike: the sitemap can be generated from either.
        add_filter('thinkrank_sitemap_query_args', [$this, 'filter_sitemap_query_args']);
        add_filter('thinkrank_sitemap_term_query_args', [$this, 'filter_sitemap_term_query_args']);

        if (!is_admin()) {
            // WPML prints at wp_head priority 1 and Polylang at 10, so run
            // after both: by then we know whether anything was printed.
            add_action('wp_head', [$this, 'output_language_alternates'], 20);
            add_filter('thinkrank_og_locale', [$this, 'filter_og_locale']);

            if ($this->provider === 'wpml') {
                // Fires inside WPML's own hreflang render, and only once it has
                // decided to output. PHP_INT_MAX so we see the final list.
                add_filter('wpml_hreflangs', [$this, 'note_provider_hreflang'], PHP_INT_MAX);
            }
        }
    }

    /**
     * Record that WPML printed its own hreflang tags for this request.
     *
     * @param mixed $items WPML's hreflang list (code => url).
     * @return mixed The list, untouched.
     */
    public function note_provider_hreflang($items) {
        if (is_array($items) && !empty($items)) {
            $this->provider_printed_hreflang = true;
        }

        return $items;
    }

    /**
     * Report the locale of the language actually being viewed.
     *
     * WordPress only switches `get_locale()` once the active language's
     * translation files are installed, so on a multilingual site without them
     * every translated URL still advertises the default locale. The provider
     * always knows which language is current, so prefer its answer.
     *
     * @param string $locale Locale WordPress reported.
     * @return string Locale for the current language.
     */
    public function filter_og_locale($locale): string {
        $current = $this->get_current_locale();

        return $current !== '' ? $current : (string) $locale;
    }

    /**
     * Locale the provider reports for the language being viewed.
     *
     * @return string Locale, or '' when it cannot be resolved.
     */
    private function get_current_locale(): string {
        foreach ($this->get_alternates() as $language) {
            if (!empty($language['is_current']) && !empty($language['locale'])) {
                return (string) $language['locale'];
            }
        }

        return '';
    }

    /**
     * Which multilingual plugin is running.
     *
     * Checked in order of specificity. A site running two of these at once is
     * already broken, so first match wins rather than trying to merge them.
     *
     * @return string 'wpml', 'polylang', 'translatepress', or '' when none is active.
     */
    private function detect_provider(): string {
        if (defined('ICL_SITEPRESS_VERSION') && has_filter('wpml_active_languages')) {
            return 'wpml';
        }

        if (function_exists('pll_the_languages') && function_exists('pll_default_language')) {
            return 'polylang';
        }

        // TranslatePress. The constant alone isn't enough — the instance is what
        // we actually read languages and URLs from, so require the class too.
        if (defined('TRP_PLUGIN_VERSION') && class_exists('\TRP_Translate_Press')) {
            return 'translatepress';
        }

        return '';
    }

    /**
     * The active provider slug (exposed for tests and debugging).
     *
     * @return string
     */
    public function get_provider(): string {
        return $this->provider;
    }

    /**
     * Emit hreflang alternates (when the provider isn't) and Open Graph locale
     * alternates for the current request.
     *
     * @return void
     */
    public function output_language_alternates(): void {
        if (!$this->is_translatable_view()) {
            return;
        }

        $languages = $this->get_alternates();
        if (count($languages) < 2) {
            // Nothing to cross-reference: a single language is not an alternate
            // of itself.
            return;
        }

        if ($this->should_output_hreflang()) {
            $this->print_hreflang($languages);
        }

        $this->print_og_locale_alternates($languages);
    }

    /**
     * Whether the current view maps onto a piece of translatable content.
     *
     * Search results, 404s and feeds have no meaningful per-language
     * counterpart, and emitting alternates there is noise at best.
     *
     * @return bool
     */
    private function is_translatable_view(): bool {
        $eligible = is_singular()
            || is_front_page()
            || is_home()
            || is_category()
            || is_tag()
            || is_tax();

        if (is_404() || is_search() || is_feed()) {
            $eligible = false;
        }

        /**
         * Filter whether ThinkRank emits language alternates for this request.
         *
         * @since 1.23.0
         *
         * @param bool $eligible Whether the current view is eligible.
         */
        return (bool) apply_filters('thinkrank_multilingual_output_alternates', $eligible);
    }

    /**
     * Whether ThinkRank should print hreflang itself.
     *
     * Defaults to false whenever the active plugin already prints them, so the
     * page never carries two competing sets.
     *
     * @return bool
     */
    private function should_output_hreflang(): bool {
        $provider_handles_it = $this->provider_outputs_hreflang();

        /**
         * Filter whether ThinkRank prints hreflang alternates.
         *
         * Defaults to true only when the active multilingual plugin has its own
         * hreflang output switched off. Force it to true to let ThinkRank own
         * the tags (remember to disable the provider's, or the page ends up
         * with duplicates).
         *
         * @since 1.23.0
         *
         * @param bool   $should   Whether ThinkRank should print hreflang.
         * @param string $provider Active provider slug.
         */
        return (bool) apply_filters(
            'thinkrank_multilingual_output_hreflang',
            !$provider_handles_it,
            $this->provider
        );
    }

    /**
     * Whether the active multilingual plugin already prints hreflang tags.
     *
     * @return bool
     */
    private function provider_outputs_hreflang(): bool {
        if ($this->provider === 'wpml') {
            // Observed during this very request (see note_provider_hreflang).
            // WPML gates its output behind must_render(), so a site can have
            // the setting enabled and still print nothing — in that case we
            // step in rather than leaving the page without alternates.
            return $this->provider_printed_hreflang;
        }

        // Polylang prints hreflang alternates on the front end with no setting
        // to turn them off. TranslatePress hooks its own
        // TRP_Url_Converter::add_hreflang_to_head() onto wp_head
        // unconditionally, and emits region-independent variants and x-default
        // on top — a second set from us would compete with all of it. Never
        // double up on either.
        return true;
    }

    /**
     * Translated alternates for the current request.
     *
     * @return array<int, array{code: string, locale: string, url: string, is_default: bool}>
     */
    private function get_alternates(): array {
        switch ($this->provider) {
            case 'wpml':
                $alternates = $this->get_wpml_alternates();
                break;
            case 'polylang':
                $alternates = $this->get_polylang_alternates();
                break;
            case 'translatepress':
                $alternates = $this->get_translatepress_alternates();
                break;
            default:
                $alternates = [];
        }

        /**
         * Filter the resolved language alternates before output.
         *
         * @since 1.23.0
         *
         * @param array  $alternates Resolved alternates.
         * @param string $provider   Active provider slug.
         */
        return (array) apply_filters('thinkrank_multilingual_alternates', $alternates, $this->provider);
    }

    /**
     * Resolve alternates from WPML.
     *
     * @return array<int, array{code: string, locale: string, url: string, is_default: bool}>
     */
    private function get_wpml_alternates(): array {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 1]);
        if (!is_array($languages) || empty($languages)) {
            return [];
        }

        $default = (string) apply_filters('wpml_default_language', null);
        $current = (string) apply_filters('wpml_current_language', null);
        $out     = [];

        foreach ($languages as $language) {
            $url = (string) ($language['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $code = (string) ($language['language_code'] ?? $language['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $out[] = [
                'code'       => $code,
                // WPML's admin-configurable hreflang tag; authoritative when set.
                'tag'        => (string) ($language['tag'] ?? ''),
                'locale'     => (string) ($language['default_locale'] ?? ''),
                'url'        => $url,
                'is_default' => $code === $default,
                'is_current' => $code === $current,
            ];
        }

        return $out;
    }

    /**
     * Resolve alternates from Polylang.
     *
     * @return array<int, array{code: string, locale: string, url: string, is_default: bool}>
     */
    private function get_polylang_alternates(): array {
        $languages = pll_the_languages([
            'raw'                    => 1,
            'hide_if_no_translation' => 1,
        ]);

        if (!is_array($languages) || empty($languages)) {
            return [];
        }

        $default = (string) pll_default_language('slug');
        $out     = [];

        foreach ($languages as $language) {
            $url = (string) ($language['url'] ?? '');
            if ($url === '' || !empty($language['no_translation'])) {
                continue;
            }

            $code = (string) ($language['slug'] ?? '');
            if ($code === '') {
                continue;
            }

            $out[] = [
                'code'       => $code,
                // Polylang exposes a W3C-valid tag for exactly this purpose.
                'tag'        => (string) ($language['w3c'] ?? ''),
                'locale'     => (string) ($language['locale'] ?? ''),
                'url'        => $url,
                'is_default' => $code === $default,
                'is_current' => !empty($language['current_lang']),
            ];
        }

        return $out;
    }

    /**
     * Resolve alternates from TranslatePress.
     *
     * TranslatePress has no public helper for "every published language and its
     * URL", so this reads the two components it exposes through its singleton:
     * `settings` for the published-language list, `url_converter` for the URL of
     * the current request in a given language.
     *
     * Its language codes are already locales (`en_US`, `de_DE`), which is why
     * `locale` and `code` carry the same value here — unlike Polylang, where the
     * slug and locale differ.
     *
     * @return array<int, array{code: string, locale: string, url: string, is_default: bool}>
     */
    private function get_translatepress_alternates(): array {
        if (!class_exists('\TRP_Translate_Press')) {
            return [];
        }

        $trp = \TRP_Translate_Press::get_trp_instance();
        if (!is_object($trp) || !method_exists($trp, 'get_component')) {
            return [];
        }

        $settings_component = $trp->get_component('settings');
        $url_converter      = $trp->get_component('url_converter');

        if (!is_object($settings_component)
            || !is_object($url_converter)
            || !method_exists($settings_component, 'get_settings')
            || !method_exists($url_converter, 'get_url_for_language')
        ) {
            return [];
        }

        $settings  = (array) $settings_component->get_settings();
        $languages = $settings['publish-languages'] ?? [];

        if (!is_array($languages) || empty($languages)) {
            return [];
        }

        $default = (string) ($settings['default-language'] ?? '');

        // TranslatePress tracks the language being rendered on a global rather
        // than through an accessor.
        global $TRP_LANGUAGE;
        $current = is_string($TRP_LANGUAGE) ? $TRP_LANGUAGE : '';

        $out = [];

        foreach ($languages as $language) {
            $language = (string) $language;
            if ($language === '') {
                continue;
            }

            // get_url_for_language() tags its return value so TranslatePress can
            // tell already-processed links apart during output rewriting. That
            // marker is internal and must never reach a href.
            $url = str_replace(
                '#TRPLINKPROCESSED',
                '',
                (string) $url_converter->get_url_for_language($language)
            );

            if ($url === '') {
                continue;
            }

            // `de_DE_formal` is a TranslatePress formality variant, not a real
            // locale — Open Graph and hreflang both reject it, and both formal
            // and informal resolve to the same language anyway.
            $locale = str_replace(['_formal', '_informal'], '', $language);

            $out[] = [
                'code'       => $language,
                // No admin-configurable hreflang tag; to_hreflang_code() falls
                // through to the locale, which is what TranslatePress prints.
                'tag'        => '',
                'locale'     => $locale,
                'url'        => $url,
                'is_default' => $language === $default,
                'is_current' => $language === $current,
            ];
        }

        return $out;
    }

    /**
     * Print hreflang alternates plus x-default.
     *
     * @param array<int, array<string, mixed>> $languages Resolved alternates.
     * @return void
     */
    private function print_hreflang(array $languages): void {
        $default_url = '';

        foreach ($languages as $language) {
            $code = $this->to_hreflang_code($language);
            if ($code === '') {
                continue;
            }

            printf(
                '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
                esc_attr($code),
                esc_url((string) $language['url'])
            );

            if (!empty($language['is_default'])) {
                $default_url = (string) $language['url'];
            }
        }

        if ($default_url !== '') {
            printf(
                '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
                esc_url($default_url)
            );
        }
    }

    /**
     * Print `og:locale:alternate` for every language except the current one.
     *
     * @param array<int, array<string, mixed>> $languages Resolved alternates.
     * @return void
     */
    private function print_og_locale_alternates(array $languages): void {
        $current_locale = $this->get_current_locale();

        foreach ($languages as $language) {
            $locale = (string) $language['locale'];

            // Skip the language being viewed. Keyed off the provider's own
            // "current language" rather than get_locale(), which keeps
            // reporting the default locale when the active language has no
            // translation files installed.
            if ($locale === '' || !empty($language['is_current']) || $locale === $current_locale) {
                continue;
            }

            printf(
                '<meta property="og:locale:alternate" content="%s" />' . "\n",
                esc_attr($locale)
            );
        }
    }

    /**
     * Pick the hreflang value for a language, mirroring how the provider
     * itself would write it.
     *
     * Order matters: the provider's configured language tag wins, then the
     * locale, then the bare code — the same precedence WPML uses. A region is
     * never invented from the locale, because "de" and "de-DE" do not mean the
     * same thing: the former targets German speakers everywhere, the latter
     * only those in Germany, which would strand Austrian and Swiss readers.
     *
     * Formality suffixes are stripped first. `de_DE_formal` is a real WordPress
     * locale, and it is shaped just like a valid subtag sequence — so without
     * this it sailed through validation as `de-de-formal`, which is not a
     * language tag and which search engines discard. This affects every
     * provider, not just the one that surfaced it.
     *
     * @param array<string, mixed> $language Resolved language row.
     * @return string Normalized hreflang value, or '' when unusable.
     */
    private function to_hreflang_code(array $language): string {
        foreach (['tag', 'locale', 'code'] as $key) {
            $value = trim((string) ($language[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $value = strtolower(str_replace('_', '-', $value));
            $value = str_replace(['-formal', '-informal'], '', $value);

            if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Widen a sitemap post query to every language.
     *
     * @param array<string, mixed> $args Query args.
     * @return array<string, mixed>
     */
    public function filter_sitemap_query_args(array $args): array {
        if ($this->provider === 'polylang') {
            // Polylang filters through parse_query, which suppress_filters does
            // not bypass. An empty language disables its language clause.
            $args['lang'] = '';
            return $args;
        }

        if ($this->provider === 'translatepress') {
            // Nothing to widen. TranslatePress translates rendered output
            // rather than creating a post per language, so the query already
            // returns every piece of content exactly once — and forcing WPML's
            // suppress_filters here would only override a caller's intent for
            // no benefit.
            return $args;
        }

        // WPML filters posts through the posts_* SQL filters, which get_posts()
        // already bypasses via its suppress_filters default — that default is
        // the only reason the sitemap sees every language today. Pin it so a
        // caller cannot quietly turn it off.
        //
        // Measured against WPML 4.9.5: setting suppress_filters => false cut the
        // sitemap down to the active language, and a 'lang' => 'all' argument
        // was ignored outright, so neither is used here.
        $args['suppress_filters'] = true;

        return $args;
    }

    /**
     * Widen a sitemap term query to every language.
     *
     * @param array<string, mixed> $args Query args.
     * @return array<string, mixed>
     */
    public function filter_sitemap_term_query_args(array $args): array {
        if ($this->provider === 'polylang') {
            $args['lang'] = '';
        }

        // WPML does not language-filter get_terms() in the contexts the sitemap
        // runs in (verified against WPML 4.9.5), and it ignores 'lang' => 'all',
        // so there is nothing to add for it here. TranslatePress does not
        // duplicate terms per language at all, so likewise nothing to do.
        return $args;
    }

}
