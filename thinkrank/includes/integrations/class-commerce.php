<?php
/**
 * One answer to "what does this shop say about this product".
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
 * Reads price, stock, SKU and rating out of whichever e-commerce plugin owns a
 * post, in one shape, so the schema graph and the Open Graph tags cannot
 * disagree about what a product costs.
 *
 * Two platforms today. WooCommerce, whose data the schema resolvers have always
 * read directly and still do; and SureCart (#256), whose products are mirrored
 * into an `sc_product` post with the shop's JSON in post meta.
 *
 * A third is a class implementing the three static methods this one dispatches
 * to, plus an entry in `sources()`. The `thinkrank_product_post_types` and
 * `thinkrank_commerce_product_data` filters are the no-code version of the same
 * seam.
 *
 * @since 2.19.0
 */
class Commerce {

    /**
     * Request-level memo, keyed by post id.
     *
     * A product page asks for this from the schema builder, the Open Graph
     * emitter and the validator, and the SureCart read hydrates a model object
     * per price and per media item.
     *
     * @since 2.19.0
     * @var array<int,array<string,mixed>>
     */
    private static array $memo = [];

    /**
     * The shape every source returns, and every consumer can rely on.
     *
     * @since 2.19.0
     * @var array<string,mixed>
     */
    private const EMPTY = [
        'platform'     => '',
        'sku'          => '',
        'brand'        => '',
        'price'        => '',
        'price_low'    => '',
        'price_high'   => '',
        'currency'     => '',
        'availability' => '',
        'rating_value' => '',
        'rating_count' => 0,
        'offers'       => [],
    ];

    /**
     * Post types that hold products.
     *
     * @since 2.19.0
     *
     * @return array<int,string>
     */
    public static function product_post_types(): array {
        $types = [];

        if (self::woocommerce_active()) {
            $types[] = 'product';
        }

        if (Surecart::is_active()) {
            $types[] = Surecart::POST_TYPE;
        }

        /**
         * Filters the post types ThinkRank treats as products.
         *
         * A post type here defaults to Product schema and to `og:type=product`,
         * and is offered the price and availability tags.
         *
         * @since 2.19.0
         *
         * @param array<int,string> $types Post type names.
         */
        $types = (array) apply_filters('thinkrank_product_post_types', $types);

        return array_values(array_unique(array_filter(array_map('strval', $types))));
    }

    /**
     * Whether a post type holds products.
     *
     * @since 2.19.0
     *
     * @param string $post_type Post type name.
     * @return bool
     */
    public static function is_product_post_type(string $post_type): bool {
        return '' !== $post_type && in_array($post_type, self::product_post_types(), true);
    }

    /**
     * Everything the shop knows about a product, in one shape.
     *
     * Every value is a string a tag or a schema property can carry directly,
     * and '' means "the shop did not say", never 0 or "unknown" — a consumer
     * must be able to tell a free product from an unpriced one.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post holding the product.
     * @return array<string,mixed> Shape of self::EMPTY.
     */
    public static function product_data(int $post_id): array {
        if ($post_id <= 0) {
            return self::EMPTY;
        }

        if (isset(self::$memo[$post_id])) {
            return self::$memo[$post_id];
        }

        $data = self::EMPTY;

        foreach (self::sources() as $source) {
            if (!$source::owns($post_id)) {
                continue;
            }

            $data = self::normalize($source::product_data($post_id));
            break;
        }

        /**
         * Filters the product data ThinkRank publishes for a post.
         *
         * @since 2.19.0
         *
         * @param array<string,mixed> $data    Normalised product data.
         * @param int                 $post_id Post holding the product.
         */
        $data = self::normalize((array) apply_filters('thinkrank_commerce_product_data', $data, $post_id));

        self::$memo[$post_id] = $data;

        return $data;
    }

    /**
     * Whether any shop recognises this post.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return bool
     */
    public static function has_product(int $post_id): bool {
        return '' !== (string) self::product_data($post_id)['platform'];
    }

    /**
     * The sources, in the order they are asked.
     *
     * @since 2.19.0
     *
     * @return array<int,class-string>
     */
    private static function sources(): array {
        return [Woocommerce::class, Surecart::class];
    }

    /**
     * Coerce a source's answer into the shared shape.
     *
     * Defensive on purpose: a source reads another plugin's data, which can be
     * a float, a localised string, or absent, and everything downstream puts
     * these values straight into a meta tag or a JSON-LD property.
     *
     * @since 2.19.0
     *
     * @param array<string,mixed> $data Raw source data.
     * @return array<string,mixed>
     */
    private static function normalize(array $data): array {
        $data = array_merge(self::EMPTY, array_intersect_key($data, self::EMPTY));

        foreach (['platform', 'sku', 'brand', 'price', 'price_low', 'price_high', 'rating_value'] as $key) {
            $data[$key] = is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
        }

        $data['currency']     = self::normalize_currency($data['currency']);
        $data['availability'] = self::normalize_availability($data['availability']);
        $data['rating_count'] = max(0, (int) $data['rating_count']);
        $data['offers']       = is_array($data['offers']) ? array_values($data['offers']) : [];

        // A single price is the low and the high. Filling them here rather than
        // in each source means a consumer that wants a range never has to ask
        // whether this shop supports one.
        if ('' === $data['price_low'] && '' !== $data['price']) {
            $data['price_low'] = $data['price'];
        }

        if ('' === $data['price_high'] && '' !== $data['price']) {
            $data['price_high'] = $data['price'];
        }

        if ('' === $data['price'] && '' !== $data['price_low']) {
            $data['price'] = $data['price_low'];
        }

        return $data;
    }

    /**
     * ISO 4217, uppercase.
     *
     * schema.org and Open Graph both want the code in uppercase, and SureCart
     * stores it lowercase: its own JSON-LD publishes `"priceCurrency":"usd"`,
     * which is not a currency code any consumer is obliged to recognise.
     *
     * @since 2.19.0
     *
     * @param mixed $currency Raw currency.
     * @return string Code, or '' when it is not one.
     */
    public static function normalize_currency($currency): string {
        $currency = is_scalar($currency) ? strtoupper(trim((string) $currency)) : '';

        return 1 === preg_match('/^[A-Z]{3}$/', $currency) ? $currency : '';
    }

    /**
     * A schema.org availability URL.
     *
     * Accepts the bare token, the full URL, and WooCommerce's own
     * `instock`/`outofstock`/`onbackorder` spelling.
     *
     * @since 2.19.0
     *
     * @param mixed $availability Raw availability.
     * @return string Full schema.org URL, or '' when unknown.
     */
    public static function normalize_availability($availability): string {
        $raw = is_scalar($availability) ? trim((string) $availability) : '';

        if ('' === $raw) {
            return '';
        }

        if (0 === strpos($raw, 'https://schema.org/')) {
            return $raw;
        }

        if (0 === strpos($raw, 'http://schema.org/')) {
            return 'https://schema.org/' . substr($raw, strlen('http://schema.org/'));
        }

        $known = [
            'instock'      => 'InStock',
            'in_stock'     => 'InStock',
            'outofstock'   => 'OutOfStock',
            'out_of_stock' => 'OutOfStock',
            'onbackorder'  => 'BackOrder',
            'backorder'    => 'BackOrder',
            'preorder'     => 'PreOrder',
            'presale'      => 'PreSale',
            'soldout'      => 'SoldOut',
            'discontinued' => 'Discontinued',
            'limited'      => 'LimitedAvailability',
        ];

        $key = strtolower(str_replace([' ', '-'], '', $raw));

        if (isset($known[$key])) {
            return 'https://schema.org/' . $known[$key];
        }

        // Already a schema.org term, spelled correctly.
        return 1 === preg_match('/^[A-Z][A-Za-z]+$/', $raw)
            ? 'https://schema.org/' . $raw
            : '';
    }

    /**
     * The Open Graph product tags, for whatever the shop knows.
     *
     * Open Graph has no range, so a product with several prices advertises the
     * lowest — the one a shopper sees as "from". The schema graph carries the
     * full set of offers, which is where a range belongs.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post holding the product.
     * @return array<string,string> Tag name => value, empty when nothing is known.
     */
    public static function open_graph_tags(int $post_id): array {
        $data = self::product_data($post_id);
        $tags = [];

        if ('' !== $data['price_low'] && '' !== $data['currency']) {
            $tags['product:price:amount']   = $data['price_low'];
            $tags['product:price:currency'] = $data['currency'];
        }

        if ('' !== $data['availability']) {
            // Open Graph's own vocabulary, not schema.org's URL. A product
            // on backorder or preorder can still be bought, so it must not
            // be shared as out of stock (#1432).
            $og_availability = [
                'InStock'             => 'in stock',
                'LimitedAvailability' => 'in stock',
                'OnlineOnly'          => 'in stock',
                'InStoreOnly'         => 'in stock',
                'BackOrder'           => 'available for order',
                'PreOrder'            => 'preorder',
                'PreSale'             => 'preorder',
                'Discontinued'        => 'discontinued',
            ];
            $term = substr($data['availability'], strlen('https://schema.org/'));

            $tags['product:availability'] = $og_availability[$term] ?? 'out of stock';
        }

        if ('' !== $data['sku']) {
            $tags['product:retailer_item_id'] = $data['sku'];
        }

        if ('' !== $data['brand']) {
            $tags['product:brand'] = $data['brand'];
        }

        // Pro 2.9.x prints its own price, availability and retailer id tags
        // for WooCommerce products. Printing ours as well put every one of
        // them on the page twice, with different values (#1431). Pro has
        // no brand tag, so that one stays.
        if ('woocommerce' === $data['platform'] && self::pro_prints_product_tags()) {
            unset(
                $tags['product:price:amount'],
                $tags['product:price:currency'],
                $tags['product:availability'],
                $tags['product:retailer_item_id']
            );
        }

        /**
         * Filters the Open Graph product tags, before they are emitted.
         *
         * Returning [] stops ThinkRank publishing any, which is the one-line
         * way for another producer of the same tags to take the job over.
         *
         * @since 2.19.0
         *
         * @param array<string,string> $tags     Tag name => value.
         * @param int                  $post_id  Post holding the product.
         * @param string               $platform Shop that supplied the data.
         */
        return (array) apply_filters('thinkrank_commerce_open_graph_tags', $tags, $post_id, $data['platform']);
    }

    /**
     * Whether ThinkRank Pro's WooCommerce Open Graph block is on wp_head.
     *
     * Pro registers it only while its product Open Graph setting is on, so
     * the hook itself is the answer. Matched by class name, so free needs
     * no Pro class loaded to ask.
     *
     * @since 2.19.0
     *
     * @return bool
     */
    private static function pro_prints_product_tags(): bool {
        $hook = $GLOBALS['wp_filter']['wp_head'] ?? null;
        $callbacks = is_object($hook) && isset($hook->callbacks) ? $hook->callbacks : [];

        foreach ((array) $callbacks as $priority_callbacks) {
            foreach ((array) $priority_callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (is_array($function)
                    && is_object($function[0] ?? null)
                    && 'ThinkRank\\Pro\\WooCommerce\\Open_Graph' === get_class($function[0])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Forget the memo.
     *
     * @since 2.19.0
     *
     * @return void
     */
    public static function flush_cache(): void {
        self::$memo = [];
    }

    /**
     * Whether WooCommerce is loaded.
     *
     * @since 2.19.0
     *
     * @return bool
     */
    private static function woocommerce_active(): bool {
        return function_exists('wc_get_product');
    }
}
