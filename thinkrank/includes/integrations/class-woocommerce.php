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
     * The product's brand, from whichever taxonomy the site uses for one.
     *
     * `product_brand` is WooCommerce's own since 9.4; `pwb-brand` and
     * `yith_product_brand` are the two plugins that owned the job before that
     * and are still on a large share of stores.
     *
     * @since 2.19.0
     *
     * @param int $post_id Post id.
     * @return string
     */
    private static function brand(int $post_id): string {
        foreach (['product_brand', 'pwb-brand', 'yith_product_brand'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($post_id, $taxonomy);

            if (!empty($terms) && !is_wp_error($terms) && isset($terms[0]->name)) {
                return (string) $terms[0]->name;
            }
        }

        return '';
    }
}
