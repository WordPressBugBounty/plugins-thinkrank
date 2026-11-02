<?php
/**
 * WooCommerce as a commerce source.
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
 * Reads a WooCommerce product into the shared commerce shape.
 *
 * The Product schema resolvers in `Global_SEO_Schema_Output` have read
 * WooCommerce directly since 1.0 and still do; this class is not a replacement
 * for them. It exists so the Open Graph emitter, which published no price at
 * all, can ask the same question of WooCommerce and SureCart in one call.
 *
 * @since 2.19.0
 */
class Woocommerce {

    /**
     * Whether WooCommerce is loaded.
     *
     * @since 2.19.0
     *
     * @return bool
     */
    public static function is_active(): bool {
        return function_exists('wc_get_product');
    }

    /**
     * Whether this post is a WooCommerce product.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return bool
     */
    public static function owns(int $post_id): bool {
        if (!self::is_active()) {
            return false;
        }

        return in_array(get_post_type($post_id), ['product', 'product_variation'], true);
    }

    /**
     * Read the product.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return array<string,mixed> Commerce shape, or [] when it cannot be read.
     */
    public static function product_data(int $post_id): array {
        $product = wc_get_product($post_id);

        if (!is_object($product)) {
            return [];
        }

        $data = [
            'platform' => 'woocommerce',
            'sku'      => method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
        ];

        if (method_exists($product, 'get_price')) {
            $data['price'] = (string) $product->get_price();
        }

        // A variable product's own get_price() is one variation's; the range is
        // what the listing advertises and what a shopper is shown.
        if (method_exists($product, 'get_variation_price')) {
            $data['price_low']  = (string) $product->get_variation_price('min', true);
            $data['price_high'] = (string) $product->get_variation_price('max', true);
        }

        if (method_exists($product, 'get_stock_status')) {
            $data['availability'] = (string) $product->get_stock_status();
        } elseif (method_exists($product, 'is_in_stock')) {
            $data['availability'] = $product->is_in_stock() ? 'InStock' : 'OutOfStock';
        }

        if (method_exists($product, 'get_rating_count') && method_exists($product, 'get_average_rating')) {
            $count = (int) $product->get_rating_count();

            if ($count > 0) {
                $data['rating_count'] = $count;
                $data['rating_value'] = (string) $product->get_average_rating();
            }
        }

        $data['brand'] = self::brand($post_id);

        return $data;
    }

    /**
     * The brand taxonomies "Automatic" tries, in order.
     *
     * `product_brand` is WooCommerce's own since 9.4; `pwb-brand`,
     * `yith_product_brand` and `berocket_brand` belong to the plugins that
     * owned the job before that and are still on a large share of stores. The
     * schema and Open Graph asked the first three and the `%brand%` token all
     * four, so a BeRocket store had a brand in its title and none in its
     * schema; there is now one list (#1478).
     *
     * @since 2.21.0
     * @var string[]
     */
    public const AUTO_BRAND_TAXONOMIES = ['product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand'];

    /**
     * The taxonomies a product's brand is read from.
     *
     * The one the store chose in the WooCommerce settings, or the automatic
     * list. A chosen taxonomy that is no longer registered, because the brand
     * plugin behind it was deactivated, falls back to the automatic list
     * rather than to no brand at all.
     *
     * @since 2.21.0
     *
     * @return string[]
     */
    public static function brand_taxonomies(): array {
        $chosen = WooCommerce_Settings::brand_taxonomy();

        if ('' !== $chosen && taxonomy_exists($chosen)) {
            return [$chosen];
        }

        return self::AUTO_BRAND_TAXONOMIES;
    }

    /**
     * The product's brand.
     *
     * The single place a WooCommerce product's brand is resolved: Product
     * schema `brand` and the `product:brand` Open Graph tag read it through
     * product_data(), and the `%brand%` token calls it directly.
     *
     * @since 2.19.0
     * @since 2.21.0 Public; reads the chosen brand taxonomy and applies
     *               `thinkrank_product_brand`.
     *
     * @param int $post_id Post id.
     * @return string
     */
    public static function brand(int $post_id): string {
        $brand = '';

        foreach (self::brand_taxonomies() as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($post_id, $taxonomy);

            if (is_array($terms) && isset($terms[0]->name) && '' !== (string) $terms[0]->name) {
                $brand = (string) $terms[0]->name;
                break;
            }
        }

        /**
         * Filters a WooCommerce product's brand.
         *
         * Runs whether or not a brand term was found, so a store-wide brand can
         * be supplied for products that have none (ThinkRank Pro uses this),
         * and a found one can be replaced. Return '' to publish no brand.
         *
         * @since 2.21.0
         *
         * @param string $brand   Brand read from the brand taxonomy, or ''.
         * @param int    $post_id Product post id.
         */
        $brand = apply_filters('thinkrank_product_brand', $brand, $post_id);

        return is_scalar($brand) ? trim((string) $brand) : '';
    }

    /**
     * The attachment ids of a product's gallery images, in gallery order.
     *
     * The featured image is not part of the gallery in WooCommerce and is not
     * returned here.
     *
     * @since 2.21.0
     *
     * @param int $post_id Post id.
     * @return int[]
     */
    public static function gallery_image_ids(int $post_id): array {
        if (!self::owns($post_id)) {
            return [];
        }

        $product = wc_get_product($post_id);

        if (!is_object($product) || !method_exists($product, 'get_gallery_image_ids')) {
            return [];
        }

        $ids = array_map('intval', (array) $product->get_gallery_image_ids());

        return array_values(array_unique(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * A product's gallery image IDs, read from the meta WooCommerce stores them
     * in, without building a product.
     *
     * For the sitemap walk, where a product per item grows with the catalog:
     * WooCommerce's caches outlive the walk's own release. The walk's get_posts()
     * has already primed this meta.
     *
     * @since 2.21.0
     *
     * @param int $post_id Product ID.
     * @return int[] Attachment IDs, in gallery order.
     */
    public static function stored_gallery_image_ids(int $post_id): array {
        if (!self::owns($post_id)) {
            return [];
        }

        $stored = get_post_meta($post_id, '_product_image_gallery', true);
        $ids = array_map('intval', explode(',', is_string($stored) ? $stored : ''));

        return array_values(array_unique(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        })));
    }
}
