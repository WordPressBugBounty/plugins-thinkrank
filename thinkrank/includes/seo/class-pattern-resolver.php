<?php
/**
 * Variable-tag pattern resolver.
 *
 * Resolves the Global / Bulk SEO variable-tag patterns (e.g.
 * "%title% %sep% %sitename%") into concrete values for a specific post,
 * independent of the main query / loop. Used to preview, inside the post
 * editor, the value the frontend will output when a per-post SEO field is left
 * empty — the frontend already falls back to these same patterns.
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves Global SEO patterns for an explicit post.
 *
 * @since 1.0.0
 */
class Pattern_Resolver {

    /**
     * Option holding the per-post-type Global SEO patterns.
     */
    private const OPTION_NAME = 'thinkrank_global_seo_settings';

    /**
     * Default title pattern (mirrors the Global SEO endpoint default).
     */
    private const DEFAULT_TITLE = '%title% %sep% %sitename%';

    /**
     * Default description pattern (mirrors the Global SEO endpoint default).
     */
    private const DEFAULT_DESCRIPTION = '%excerpt%';

    /**
     * Post meta key holding the per-post SEO title.
     */
    private const META_TITLE = '_thinkrank_seo_title';

    /**
     * Post meta key holding the per-post meta description.
     */
    private const META_DESCRIPTION = '_thinkrank_meta_description';

    /**
     * Resolve the SEO title pattern for a post.
     *
     * @param int $post_id Post ID.
     * @return string Resolved title, or '' when it resolves to nothing.
     */
    public static function title(int $post_id): string {
        $template = self::template_for($post_id, 'title', self::DEFAULT_TITLE);
        return self::resolve_value($template, $post_id);
    }

    /**
     * Resolve any variable-tag string against a post's values.
     *
     * Replaces tokens (e.g. "%title% %sep% %sitename%") with the post's actual
     * values. A literal string containing no tokens passes through unchanged, so
     * this is safe to run over per-post SEO fields that may or may not hold a
     * pattern.
     *
     * @param string $value   Raw string, possibly containing variable tags.
     * @param int    $post_id Post ID.
     * @return string Resolved string.
     */
    public static function resolve_value(string $value, int $post_id): string {
        if (strpos($value, '%') === false) {
            return $value;
        }
        return self::process($value, self::placeholders_for($post_id));
    }

    /**
     * Sanitize a variable-tag template for storage.
     *
     * The write-side counterpart of resolve_value(): every template that
     * reaches this class has to survive the trip into the database first.
     *
     * sanitize_text_field() cannot be used for that. Core's
     * _sanitize_text_fields() strips percent-encoded characters, looping
     * `preg_replace( '/%[a-f0-9]{2}/i', ... )` until nothing matches, so any
     * token whose first two characters are hex digits is eaten on save:
     * %date% is stored as "te%" and %category% as "tegory%" (#521). They are
     * the only two tags in the language that collide, which is why the
     * corruption looked arbitrary — %title%, %sitename%, %sep%, %excerpt%,
     * %modified% and %author% all pass through core untouched.
     *
     * This mirrors what core does either side of that percent loop — invalid
     * UTF-8 dropped, tags stripped, control characters removed, whitespace
     * collapsed — and simply omits the loop itself.
     *
     * @since 2.1.1
     *
     * @param string $value         Raw template as submitted.
     * @param bool   $keep_newlines Preserve newlines, as sanitize_textarea_field() does.
     * @return string Sanitized template with its %tokens% intact.
     */
    public static function sanitize_template(string $value, bool $keep_newlines = false): string {
        $filtered = wp_check_invalid_utf8($value);

        if (strpos($filtered, '<') !== false) {
            $filtered = wp_pre_kses_less_than($filtered);
            // Tags out, the text between them kept.
            $filtered = wp_strip_all_tags($filtered, false);
            $filtered = str_replace("<\n", "&lt;\n", $filtered);
        }

        // C0 controls and DEL, less the tab/newline/carriage-return handled below.
        $filtered = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $filtered);

        if (!$keep_newlines) {
            $filtered = (string) preg_replace('/[\r\n\t ]+/', ' ', $filtered);
        }

        return trim($filtered);
    }

    /**
     * Sanitize a variable-tag template that may span multiple lines.
     *
     * The sanitize_textarea_field() counterpart of sanitize_template().
     *
     * @since 2.1.1
     *
     * @param string $value Raw template as submitted.
     * @return string Sanitized template with its %tokens% and newlines intact.
     */
    public static function sanitize_template_textarea(string $value): string {
        return self::sanitize_template($value, true);
    }

    /**
     * Derive a description from raw post content.
     *
     * wp_strip_all_tags() removes HTML but not shortcodes, so a page built with
     * them published its shortcode source as the description — `[woocommerce_cart]`
     * as the meta description, og:description and twitter:description of the
     * cart page. Core's own wp_trim_excerpt() runs strip_shortcodes() and
     * excerpt_remove_blocks() first; this path did neither, which is why the
     * two disagreed about the same post (#387).
     *
     * @since 2.0.1
     *
     * @param string $content Raw post content.
     * @param int    $words   Word cap.
     * @return string Derived description, or '' when nothing survives.
     */
    public static function derive_excerpt(string $content, int $words = 25): string {
        if ('' === trim($content)) {
            return '';
        }

        $text = excerpt_remove_blocks($content);
        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text);

        // $words is a WORD cap, but wp_trim_words() counts CHARACTERS on
        // th/ja/zh_*, where it would cut to ~25 characters instead of ~25
        // words — about six times too short (#687). trim_words() keeps the
        // word cap where words are the unit and falls back to a character
        // budget where they are not.
        return trim(\ThinkRank\Core\Seo_Text::trim_words($text, $words));
    }

    /**
     * Resolve any variable-tag string against a term's values.
     *
     * The term counterpart of resolve_value(). Term SEO fields reach the
     * frontend from three writers — the term UI, the abilities API and the
     * Yoast/RankMath/AIOSEO/SEOPress importer — and the importers already
     * substitute their own term tokens (%%term_title%%, %term%) with the term
     * name at export time, so what lands here is either literal text or
     * ThinkRank's own tags.
     *
     * @since 2.0.1
     *
     * @param string $value   Raw string, possibly containing variable tags.
     * @param int    $term_id Term ID.
     * @return string Resolved string.
     */
    public static function resolve_term_value(string $value, int $term_id): string {
        if (strpos($value, '%') === false) {
            return $value;
        }
        return self::process($value, self::placeholders_for_term($term_id));
    }

    /**
     * Token => value map for a term.
     *
     * The post-only tokens resolve to an empty string rather than being left
     * unreplaced: they have no meaning on an archive, and process() collapses
     * the separators an empty token leaves behind. A raw "%author%" in the
     * rendered title would be worse than nothing.
     *
     * @since 2.0.1
     *
     * @param int $term_id Term ID.
     * @return array<string,string> Placeholder map.
     */
    private static function placeholders_for_term(int $term_id): array {
        $term = get_term($term_id);

        $name        = ($term && !is_wp_error($term)) ? $term->name : '';
        $description = ($term && !is_wp_error($term)) ? (string) $term->description : '';

        return [
            '%title%'     => $name,
            '%term%'      => $name,
            '%sitename%'  => get_bloginfo('name'),
            '%sep%'       => self::separator(),
            // Same locale trap as derive_excerpt(): a word cap here is a
            // ~25-character cap on th/ja/zh_* (#687).
            '%excerpt%'   => $description !== ''
                ? self::derive_excerpt($description)
                : '',
            '%date%'      => '',
            '%modified%'  => '',
            '%author%'    => '',
            '%category%'  => '',
        ];
    }

    /**
     * Token => value map for a post, keyed WITHOUT the surrounding percents
     * (e.g. 'title' => 'My Post'). Used by the editor for live client-side
     * preview of a pattern as the user types.
     *
     * @param int $post_id Post ID.
     * @return array<string,string> Variable map.
     */
    public static function variables(int $post_id): array {
        $map = [];
        foreach (self::placeholders_for($post_id) as $token => $value) {
            $map[trim($token, '%')] = $value;
        }
        return $map;
    }

    /**
     * Resolve the meta description pattern for a post.
     *
     * Trimmed to the same ~160-char ceiling the frontend applies on output.
     *
     * @param int $post_id Post ID.
     * @return string Resolved description, or '' when it resolves to nothing.
     */
    public static function description(int $post_id): string {
        $template = self::template_for($post_id, 'description', self::DEFAULT_DESCRIPTION);
        $description = self::resolve_value($template, $post_id);

        // Measure and cut in CHARACTERS. strlen() counts bytes, so a Thai or
        // CJK description tripped this limit at a third of its length, and
        // wp_trim_words() then cut by a unit the locale chooses — 25 words in
        // English, 25 characters in Thai (#687).
        $description = \ThinkRank\Core\Seo_Text::trim_to_length($description);

        return $description;
    }

    /**
     * Effective SEO title for a post: the per-post custom value (with any
     * variable tags resolved) when set, otherwise the rendered Global/Bulk
     * title pattern. This is the value the frontend actually outputs.
     *
     * Scoring MUST use this rather than the raw `_thinkrank_seo_title` meta —
     * an empty meta means "inherit the global pattern", not "no title", so the
     * raw value would make an inherited-title post score as if it had none.
     *
     * @param int $post_id Post ID.
     * @return string Effective title.
     */
    public static function effective_title(int $post_id): string {
        return self::effective_value(
            (string) get_post_meta($post_id, self::META_TITLE, true),
            $post_id,
            'title'
        );
    }

    /**
     * Effective meta description for a post: the per-post custom value (with any
     * variable tags resolved) when set, otherwise the rendered Global/Bulk
     * description pattern. Counterpart to {@see self::effective_title()}.
     *
     * @param int $post_id Post ID.
     * @return string Effective description.
     */
    public static function effective_description(int $post_id): string {
        return self::effective_value(
            (string) get_post_meta($post_id, self::META_DESCRIPTION, true),
            $post_id,
            'description'
        );
    }

    /**
     * Resolve a raw per-post field to its effective value.
     *
     * When the raw value is non-empty its variable tags are resolved; when it is
     * empty the field falls back to the rendered Global/Bulk pattern. Exposed so
     * callers that already hold a raw value (e.g. the SEO score endpoint scoring
     * unsaved editor input) can route through the same fallback logic.
     *
     * @param string $raw     Raw per-post field value (may hold variable tags).
     * @param int    $post_id Post ID.
     * @param string $field   Which pattern to fall back to: 'title' or 'description'.
     * @return string Effective value.
     */
    public static function effective_value(string $raw, int $post_id, string $field): string {
        if ($raw !== '') {
            return self::resolve_value($raw, $post_id);
        }

        return 'description' === $field
            ? self::description($post_id)
            : self::title($post_id);
    }

    /**
     * Build the full set of pattern previews for the post editor.
     *
     * Social fields mirror the frontend fallback: an empty og/twitter title
     * resolves to the SEO title, and an empty og/twitter description to the
     * meta description.
     *
     * @param int $post_id Post ID.
     * @return array<string,string> Resolved previews keyed by metabox field.
     */
    public static function previews(int $post_id): array {
        $title = self::title($post_id);
        $description = self::description($post_id);

        return [
            'seo_title' => $title,
            'meta_description' => $description,
            'og_title' => $title,
            'og_description' => $description,
            'twitter_title' => $title,
            'twitter_description' => $description,
        ];
    }

    /**
     * Get the configured pattern for a post type, falling back to a default.
     *
     * @param int    $post_id Post ID.
     * @param string $key     Setting key ('title' or 'description').
     * @param string $fallback Default pattern.
     * @return string Pattern template.
     */
    private static function template_for(int $post_id, string $key, string $fallback): string {
        $post_type = get_post_type($post_id) ?: 'post';
        $all = get_option(self::OPTION_NAME, []);
        $template = $all[$post_type][$key] ?? '';

        return is_string($template) && $template !== '' ? $template : $fallback;
    }

    /**
     * Build placeholder values for an explicit post (no loop dependency).
     *
     * @param int $post_id Post ID.
     * @return array<string,string> Placeholder map.
     */
    private static function placeholders_for(int $post_id): array {
        $post = get_post($post_id);

        $excerpt = '';
        if ($post) {
            $excerpt = !empty($post->post_excerpt)
                ? $post->post_excerpt
                : self::derive_excerpt(Builder_Content::visible_content($post));
        }

        $author_id = (int) get_post_field('post_author', $post_id);

        $category = '';
        if (get_post_type($post_id) === 'post') {
            $categories = get_the_category($post_id);
            $category = !empty($categories) ? $categories[0]->name : '';
        }

        return array_merge(
            [
                '%title%' => get_the_title($post_id),
                '%sitename%' => get_bloginfo('name'),
                '%sep%' => self::separator(),
                '%excerpt%' => $excerpt,
                '%date%' => get_the_date('', $post_id),
                '%modified%' => get_the_modified_date('', $post_id),
                '%author%' => $author_id ? get_the_author_meta('display_name', $author_id) : '',
                '%category%' => $category,
            ],
            self::product_placeholders($post_id)
        );
    }

    /**
     * Tokens that only mean anything on a WooCommerce product.
     *
     * Rank Math and Yoast WooCommerce SEO both let a product title or
     * description carry the price, the SKU and the stock status, and both of
     * our converters dropped every token they did not recognise — so
     * "Buy %title% for %wc_price%" imported as "Buy %title% for" and the
     * customer in support #171748 found four variables where they had had a
     * stock one (#715).
     *
     * Always present, never conditional on the post type. A token that exists
     * on a product and is an unknown token everywhere else would resolve on
     * one page and leak literally on another; resolving to an empty string off
     * a product is the same answer every other token gives when it has nothing
     * to say, and process() then collapses the separator it leaves behind.
     *
     * @since 2.10.1
     *
     * @param int $post_id Post ID.
     * @return array<string,string>
     */
    private static function product_placeholders(int $post_id): array {
        $empty = [
            '%price%'             => '',
            '%sale_price%'        => '',
            '%sku%'               => '',
            '%stock_status%'      => '',
            '%short_description%' => '',
            '%brand%'             => '',
        ];

        if (!function_exists('wc_get_product') || 'product' !== get_post_type($post_id)) {
            return $empty;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return $empty;
        }

        // Read through wc_price()/get_price_html() rather than formatting the
        // number here: currency symbol, position, decimals and the "from X"
        // form on a variable product are all store settings, and a second
        // formatter is how a template starts disagreeing with the price shown
        // three lines below it on the same page.
        $price      = (string) $product->get_price();
        $sale_price = (string) $product->get_sale_price();

        $stock_status = (string) $product->get_stock_status();
        $stock_labels = [
            'instock'     => __('In stock', 'thinkrank'),
            'outofstock'  => __('Out of stock', 'thinkrank'),
            'onbackorder' => __('On backorder', 'thinkrank'),
        ];

        return [
            '%price%'             => self::formatted_price($price),
            '%sale_price%'        => self::formatted_price($sale_price),
            '%sku%'               => (string) $product->get_sku(),
            '%stock_status%'      => $stock_labels[$stock_status] ?? $stock_status,
            '%short_description%' => self::derive_excerpt((string) $product->get_short_description()),
            '%brand%'             => self::product_brand($post_id),
        ];
    }

    /**
     * A price as text, in the store's own currency format.
     *
     * wc_price() returns markup, and stripping the tags off it leaves the
     * entities behind: a title read "11.05&#2547;&nbsp;" on the first live
     * run. Entities are decoded and the non-breaking space collapsed, because
     * this value ends up inside a `<title>` and a meta description, where
     * markup has no meaning and an entity is just noise a reader sees.
     *
     * @since 2.10.1
     *
     * @param string $price Raw price, or '' when the product has none.
     * @return string
     */
    private static function formatted_price(string $price): string {
        if ('' === $price) {
            return '';
        }

        $text = wp_strip_all_tags((string) wc_price((float) $price));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // \xC2\xA0 is the non-breaking space wc_price() puts between the
        // amount and the symbol; a literal one in a title is invisible to a
        // reader and awkward for everything else.
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A product's brand, from whichever taxonomy the store uses for one.
     *
     * WooCommerce core added `product_brand` in 9.4; before that every brand
     * plugin shipped its own taxonomy, and a store that migrated from one of
     * them still has the old terms. Asking each in turn costs one cached term
     * lookup and means the token is not empty on the stores most likely to
     * have used a brand token in the plugin they are leaving.
     *
     * @since 2.10.1
     *
     * @param int $post_id Product ID.
     * @return string
     */
    private static function product_brand(int $post_id): string {
        foreach (['product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($post_id, $taxonomy);
            if (is_array($terms) && !empty($terms)) {
                return (string) $terms[0]->name;
            }
        }

        return '';
    }

    /**
     * Active title separator symbol.
     *
     * @return string Separator.
     */
    private static function separator(): string {
        if (class_exists('\ThinkRank\SEO\Site_Identity_Manager')) {
            return Site_Identity_Manager::get_active_separator_symbol();
        }
        return '-';
    }

    /**
     * Replace placeholders and tidy the result (mirrors the frontend cleanup).
     *
     * @param string               $template     Pattern template.
     * @param array<string,string> $placeholders Placeholder map.
     * @return string Resolved string.
     */
    private static function process(string $template, array $placeholders): string {
        // Both forms go BEFORE substitution, and the strip is decided against
        // the placeholder map rather than by what is left over afterwards.
        // Running it on the substituted string scanned the resolved VALUES too,
        // so a post whose own title read "Using %name% placeholders in
        // %%mustache%% templates" published "Using placeholders in %%
        // templates" — its title edited, and a stray double percent where the
        // inner token had been eaten out of the middle of one.
        $template = self::strip_unresolved_tokens($template, $placeholders);

        $value = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        // Collapse whitespace.
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);

        // Collapse doubled separators left by empty tokens (e.g. "| |" -> "|").
        $separator = $placeholders['%sep%'] ?? '|';
        $separator_pattern = preg_quote($separator, '/');
        $value = preg_replace(
            '/\s*' . $separator_pattern . '\s*' . $separator_pattern . '\s*/',
            ' ' . $separator . ' ',
            $value
        );

        // Strip leading/trailing separators and whitespace.
        return trim($value, " \t\n\r\0\x0B" . $separator);
    }

    /**
     * Remove any token the placeholder map did not resolve.
     *
     * The last line of defence, and the reason it exists is that every layer
     * above it is a list someone has to remember to extend. A converter that
     * misses a token, a template typed by hand, a value written straight into
     * postmeta by an importer we have not met: each one ends with `%%title%%`
     * or `%some_token%` rendering literally in a `<title>` on a live site, and
     * that is precisely what was reported on 14 September (#715).
     *
     * Both syntaxes, because a migrated site carries both: Yoast's `%%x%%`
     * (which includes Rank Math tokens Yoast's own importer wrapped in double
     * percent signs without translating them) and the single-percent form
     * ThinkRank and Rank Math share. `%%x%%` is matched as a whole so the pass
     * cannot eat the inner `%x%` and leave a stray percent sign at each end.
     *
     * Applied to the TEMPLATE, and a token is kept only when the placeholder
     * map has it. Stripping whatever still looked like a token after
     * substitution read the resolved values as well, and content is not a
     * template: a post titled "Using %name% placeholders in %%mustache%%
     * templates" published "Using placeholders in %% templates", and an excerpt
     * of "Learn %name% and %fabric% placeholders." published "Learn and
     * placeholders." Deciding against the map also means a token this resolver
     * knows about is never at risk, whatever a value happens to contain.
     *
     * A bare percent is left alone: "50% off" is ordinary copy, and a guard
     * that ate it would be a worse bug than the one it prevents.
     *
     * @since 2.10.1
     *
     * @param string                $template     Raw template.
     * @param array<string, string> $placeholders Tokens this resolver can resolve.
     * @return string
     */
    private static function strip_unresolved_tokens(string $template, array $placeholders): string {
        if (false === strpos($template, '%')) {
            return $template;
        }

        $stripped = preg_replace_callback(
            '/%%[a-z0-9_-]+%%|%[a-z0-9_-]+%/i',
            static function (array $found) use ($placeholders): string {
                return array_key_exists($found[0], $placeholders) ? $found[0] : '';
            },
            $template
        );

        // preg_replace_callback() answers null on content that is not valid
        // UTF-8 rather than throwing, and returning null here would blank a
        // title outright.
        return null === $stripped ? $template : (string) $stripped;
    }
}
