<?php
/**
 * SureCart as a commerce source, and as a second SEO plugin to stand down.
 *
 * @package ThinkRank
 * @since   2.19.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SureCart support (#256).
 *
 * The issue asks for Product schema on SureCart products. Measured on a product
 * page with both plugins active, the problem turned out to be the opposite of
 * missing output:
 *
 *     <title>          SureCart's, not ThinkRank's
 *     og:title         twice, with different values
 *     og:description   twice
 *     og:type          twice: "article" then "website", never "product"
 *     og:url           twice
 *     og:site_name     twice
 *     og:locale        twice
 *     JSON-LD          two blocks, one Product (SureCart's), one @graph
 *                      whose main entity was WebPage
 *
 * SureCart publishes its own `<title>`, its own Open Graph block and its own
 * Product JSON-LD, the same way WooCommerce does, so the work is the same work:
 * exactly one plugin describes the page, and it is the SEO plugin the site
 * installed to do that. This class is the three halves of that.
 *
 * **Stand down.** SureCart offers `sc_display_product_json_ld_schema` and
 * `sc_display_product_seo_meta`, and both are used here rather than
 * unregistering its hooks, so a site that wants SureCart's output back can have
 * it by filtering them later.
 *
 * **Give the title back.** `ProductPostTypeService::disallowPreTitle()` returns
 * '' from `pre_get_document_title` at priority 214748364, which wipes
 * ThinkRank's title (filtered at priority 1) on every product page. That one is
 * unregistered, because a filter that returns the empty string cannot be
 * overridden by returning anything else from a later priority.
 *
 * **Fill in the graph.** SureCart's own Product schema carries no SKU, no
 * brand, no `url` and no `@id`, declares `http://schema.org` rather than https,
 * and publishes `"priceCurrency":"usd"` in lowercase. Reading its data into
 * ThinkRank's graph instead fixes all five.
 *
 * @since 2.19.0
 */
class Surecart {

    /**
     * The post type SureCart mirrors its products into.
     *
     * @since 2.19.0
     * @var string
     */
    public const POST_TYPE = 'sc_product';

    /**
     * Register the hooks that resolve the overlap.
     *
     * @since 2.19.0
     *
     * @return void
     */
    public function init(): void {
        // No is_active() gate here. Components initialise on plugins_loaded and
        // post types register on init, so SureCart's product type does not
        // exist yet and the gate was false on every request. Each callback asks
        // instead, by which time it is true.
        add_filter('sc_display_product_json_ld_schema', [self::class, 'yield_schema'], 10, 1);
        add_filter('sc_display_product_seo_meta', [self::class, 'yield_meta'], 10, 1);
        // Late, because SureCart registers the filter when its product
        // controller boots, which is after plugins_loaded.
        add_action('wp', [self::class, 'release_document_title'], 1);
        add_filter('default_post_metadata', [self::class, 'inherit_seo_text'], 10, 3);
    }

    /**
     * Seed ThinkRank's per-post SEO text from the merchant's SureCart fields.
     *
     * Taking the document title back from SureCart would otherwise throw away
     * the SEO title and description a merchant had already written in
     * SureCart's own product editor: before this change SureCart's `page_title`
     * was what shipped, so a site that activated ThinkRank would have watched
     * its product titles revert to the bare post title.
     *
     * `default_post_metadata` fires only when no row exists, so this is a
     * default and never an override: the first value anybody saves in ThinkRank
     * wins permanently, and the metabox shows the inherited text rather than an
     * empty field. Scoped to the two keys and to SureCart's post type, so
     * nothing else on the site sees a different answer from get_post_meta().
     *
     * @since 2.19.0
     *
     * @param mixed  $value    The default value.
     * @param int    $post_id  Post id.
     * @param string $meta_key Meta key being read.
     * @return mixed
     */
    public static function inherit_seo_text($value, $post_id, $meta_key) {
        $fields = [
            '_thinkrank_seo_title'       => 'page_title',
            '_thinkrank_meta_description' => 'meta_description',
        ];

        if (!isset($fields[$meta_key]) || !self::owns((int) $post_id)) {
            return $value;
        }

        $product = self::product((int) $post_id);

        if (null === $product) {
            return $value;
        }

        $inherited = self::scalar($product, $fields[$meta_key]);

        return '' !== $inherited ? $inherited : $value;
    }

    /**
     * Whether SureCart is loaded with its product type registered.
     *
     * @since 2.19.0
     *
     * @return bool
     */
    public static function is_active(): bool {
        return function_exists('sc_get_product') && post_type_exists(self::POST_TYPE);
    }

    /**
     * Whether this post is a SureCart product.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return bool
     */
    public static function owns(int $post_id): bool {
        return self::is_active() && self::POST_TYPE === get_post_type($post_id);
    }

    /**
     * Read the product into the shared commerce shape.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return array<string,mixed> Commerce shape, or [] when it cannot be read.
     */
    public static function product_data(int $post_id): array {
        $product = self::product($post_id);

        if (null === $product) {
            return [];
        }

        $data = [
            'platform' => 'surecart',
            'sku'      => self::scalar($product, 'sku'),
        ];

        $prices = self::prices($product);
        $amounts = [];

        foreach ($prices as $price) {
            // SureCart stores minor units: 12900 is $129.00. The shop's own
            // Currency::maybeConvertAmount does this division, but calling it
            // would make this class depend on a SureCart internal; the
            // zero-decimal currencies are the only exception and they are a
            // closed list.
            $amounts[] = self::to_major_units((string) ($price->amount ?? ''), (string) ($price->currency ?? ''));
        }

        $amounts = array_values(array_filter($amounts, static function (string $amount): bool {
            return '' !== $amount;
        }));

        if (!empty($amounts)) {
            sort($amounts, SORT_NUMERIC);
            $data['price_low']  = (string) $amounts[0];
            $data['price_high'] = (string) $amounts[count($amounts) - 1];
            $data['price']      = $data['price_low'];
        }

        $first = $prices[0] ?? null;
        if ($first && isset($first->currency)) {
            $data['currency'] = (string) $first->currency;
        }

        $in_stock = $product->in_stock ?? null;
        if (null !== $in_stock) {
            $data['availability'] = $in_stock ? 'InStock' : 'OutOfStock';
        }

        $breakdown = $product->reviews_breakdown ?? null;
        if (is_object($breakdown) || is_array($breakdown)) {
            $breakdown = (object) $breakdown;
            $count     = (int) ($breakdown->count ?? 0);
            $average   = $breakdown->average ?? '';

            if ($count > 0 && '' !== (string) $average) {
                $data['rating_count'] = $count;
                $data['rating_value'] = (string) $average;
            }
        }

        $data['offers'] = self::offers($prices, $data['availability'] ?? '', $post_id);

        return $data;
    }

    /**
     * One Offer per active price.
     *
     * Kept rather than collapsed to a range because a SureCart product's prices
     * are named plans, not variations of one thing, and a shopper choosing
     * between "Standard" and "Pro" is choosing between two offers.
     *
     * @since 2.19.0
     *
     * @param array<int,object> $prices       Active prices.
     * @param string            $availability Normalised availability.
     * @param int               $post_id      Post id, for the offer URL.
     * @return array<int,array<string,string>>
     */
    public static function offers(array $prices, string $availability, int $post_id): array {
        $url    = $post_id > 0 ? (string) get_permalink($post_id) : '';
        $offers = [];

        foreach ($prices as $price) {
            $amount   = self::to_major_units((string) ($price->amount ?? ''), (string) ($price->currency ?? ''));
            $currency = Commerce::normalize_currency($price->currency ?? '');

            if ('' === $amount || '' === $currency) {
                continue;
            }

            $offer = [
                '@type'         => 'Offer',
                'price'         => $amount,
                'priceCurrency' => $currency,
            ];

            if ('' !== $url) {
                $offer['url'] = $url;
            }

            $stock = Commerce::normalize_availability($availability);
            if ('' !== $stock) {
                $offer['availability'] = $stock;
            }

            $name = (string) ($price->name ?? '');
            if ('' !== $name) {
                $offer['name'] = $name;
            }

            $offers[] = $offer;
        }

        return $offers;
    }

    /**
     * Minor units to major, respecting the zero-decimal currencies.
     *
     * @since 2.19.0
     *
     * @param string $amount   Amount in minor units.
     * @param string $currency Currency code.
     * @return string Amount in major units, or '' when there is none.
     */
    public static function to_major_units(string $amount, string $currency): string {
        $amount = trim($amount);

        if ('' === $amount || !is_numeric($amount)) {
            return '';
        }

        // ISO 4217 currencies with no minor unit: the amount is already whole,
        // and dividing would turn ¥1290 into ¥12.90.
        $zero_decimal = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        if (in_array(strtoupper(trim($currency)), $zero_decimal, true)) {
            return (string) (int) $amount;
        }

        return number_format(((float) $amount) / 100, 2, '.', '');
    }

    /**
     * Hydrate the product behind a post.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return object|null
     */
    public static function product(int $post_id) {
        if (!self::owns($post_id)) {
            return null;
        }

        $product = sc_get_product($post_id);

        return is_object($product) ? $product : null;
    }

    /**
     * The product's active prices, as a plain list.
     *
     * @since 2.19.0
     *
     * @param object $product SureCart product model.
     * @return array<int,object>
     */
    private static function prices($product): array {
        $prices = $product->active_prices ?? [];

        if (!is_array($prices)) {
            $prices = (array) $prices;
        }

        return array_values(array_filter($prices, 'is_object'));
    }

    /**
     * Read a scalar attribute off the model without tripping its magic getter.
     *
     * @since 2.19.0
     *
     * @param object $product  Model.
     * @param string $property Attribute name.
     * @return string
     */
    private static function scalar($product, string $property): string {
        $value = $product->$property ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    // -----------------------------------------------------------------------
    // Standing SureCart's own SEO output down
    // -----------------------------------------------------------------------

    /**
     * Whether ThinkRank is describing the product page being viewed.
     *
     * @since 2.19.0
     *
     * @return bool
     */
    public static function thinkrank_owns_this_page(): bool {
        if (!self::is_active() || !is_singular(self::POST_TYPE)) {
            return false;
        }

        /**
         * Filters whether ThinkRank replaces SureCart's own SEO output.
         *
         * False leaves SureCart's title, Open Graph block and Product JSON-LD
         * exactly as they were, duplicates included.
         *
         * @since 2.19.0
         *
         * @param bool $owns Whether ThinkRank takes over.
         */
        return (bool) apply_filters('thinkrank_surecart_replaces_seo_output', true);
    }

    /**
     * Let ThinkRank's Product schema be the only one on the page.
     *
     * @since 2.19.0
     *
     * @param bool $display Whether SureCart renders its JSON-LD.
     * @return bool
     */
    public static function yield_schema($display): bool {
        return self::thinkrank_owns_this_page() ? false : (bool) $display;
    }

    /**
     * Let ThinkRank's Open Graph block be the only one on the page.
     *
     * @since 2.19.0
     *
     * @param bool $display Whether SureCart renders its meta tags.
     * @return bool
     */
    public static function yield_meta($display): bool {
        return self::thinkrank_owns_this_page() ? false : (bool) $display;
    }

    /**
     * Stop SureCart blanking the document title.
     *
     * `disallowPreTitle()` returns '' from `pre_get_document_title` at priority
     * 214748364 whenever a product is being viewed. ThinkRank's title is
     * filtered at priority 1, so it is computed and then thrown away, and core
     * falls through to the parts SureCart sets in `document_title_parts`.
     *
     * Returning a value from a later priority cannot fix that, because there is
     * no later priority left and the empty string is not a value another filter
     * can be asked to prefer. So both of SureCart's title filters are
     * unregistered for this request, and only for a product page ThinkRank is
     * describing.
     *
     * @since 2.19.0
     *
     * @return void
     */
    public static function release_document_title(): void {
        if (!self::thinkrank_owns_this_page()) {
            return;
        }

        $service = self::product_post_service();

        if (null === $service) {
            return;
        }

        remove_filter('pre_get_document_title', [$service, 'disallowPreTitle'], 214748364);
        remove_filter('document_title_parts', [$service, 'documentTitle']);
    }

    /**
     * SureCart's product post type service, when it is reachable.
     *
     * @since 2.19.0
     *
     * @return object|null
     */
    private static function product_post_service() {
        // `method_exists()` is the wrong question: SureCart's facade resolves
        // productPost() through ApplicationTrait's __callStatic mixin, so the
        // method does not exist in the reflection sense and the guard rejected
        // every site. SureCart's own BuyPageController calls it the same way
        // this does, which is the proof it is the supported entry point.
        if (!class_exists('\SureCart')) {
            return null;
        }

        try {
            $service = \SureCart::productPost();
        } catch (\Throwable $e) {
            return null;
        }

        return is_object($service) ? $service : null;
    }
}
