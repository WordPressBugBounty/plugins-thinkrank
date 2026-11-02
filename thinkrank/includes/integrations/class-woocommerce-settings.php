<?php
/**
 * Free WooCommerce SEO settings.
 *
 * @package ThinkRank
 * @since   2.21.0
 */

declare(strict_types=1);

namespace ThinkRank\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The store-wide WooCommerce switches the free plugin owns (#1478).
 *
 * Stores migrating from Rank Math found three of its free WooCommerce settings
 * had nowhere to go: which taxonomy names a product's brand, whether shop and
 * product category archives carry a product list in their schema, and whether
 * WooCommerce's generator tag is printed. The WooCommerce tab only ever held
 * Pro's panel, so this is the first free setting on it.
 *
 * One option, `thinkrank_woocommerce_seo`, read through the static helpers
 * below so every consumer (schema, Open Graph, the title tokens) resolves the
 * same value. ThinkRank Pro keeps its own `thinkrank_pro_woocommerce` option
 * and is not affected.
 *
 * The only setting that changes a hook rather than a value read at render
 * time is `remove_generator`, so this class is also the component that applies
 * it.
 *
 * @since 2.21.0
 */
class WooCommerce_Settings {

    /**
     * Option name.
     *
     * @since 2.21.0
     * @var string
     */
    public const OPTION = 'thinkrank_woocommerce_seo';

    /**
     * Product taxonomies that are WooCommerce's own plumbing, never a brand.
     *
     * `product_type` and `product_visibility` are not shown in the admin at
     * all, and a shipping class describes a parcel, not a maker.
     *
     * @since 2.21.0
     * @var string[]
     */
    private const NOT_A_BRAND = ['product_type', 'product_visibility', 'product_shipping_class'];

    /**
     * Request-level memo of the stored settings.
     *
     * Read on every product page by the schema, Open Graph and title code, so
     * it is resolved once. save() refreshes it.
     *
     * @since 2.21.0
     * @var array<string,mixed>|null
     */
    private static ?array $memo = null;

    /**
     * Settings as they are before anything is saved.
     *
     * Each default is today's behaviour, so an update changes nothing on a
     * store until someone flips a switch:
     *
     * - `brand_taxonomy` '' means "Automatic": the brand taxonomies ThinkRank
     *   has always tried, in order ({@see Woocommerce::AUTO_BRAND_TAXONOMIES}).
     * - `shop_archive_schema` off: the shop and product category/tag archives
     *   publish their CollectionPage with no product list. On, each lists its
     *   products in schema, which loads them on every such page, so a store opts
     *   in (#1534). Rank Math's "Remove Schema Markup on Shop Archives" is the
     *   inverse of this switch, and it too leaves the list out by default.
     * - `remove_generator` off: WooCommerce's generator tag stays.
     *
     * @since 2.21.0
     *
     * @return array{brand_taxonomy: string, shop_archive_schema: bool, remove_generator: bool}
     */
    public static function defaults(): array {
        return [
            'brand_taxonomy'      => '',
            'shop_archive_schema' => false,
            'remove_generator'    => false,
        ];
    }

    /**
     * The stored settings over the defaults.
     *
     * A stored value of the wrong type is replaced by its default rather than
     * coerced, so a hand-edited option cannot switch something on.
     *
     * @since 2.21.0
     *
     * @return array{brand_taxonomy: string, shop_archive_schema: bool, remove_generator: bool}
     */
    public static function get(): array {
        if (null !== self::$memo) {
            return self::$memo;
        }

        $stored   = get_option(self::OPTION, []);
        $stored   = is_array($stored) ? $stored : [];
        $settings = self::defaults();

        if (isset($stored['brand_taxonomy']) && is_string($stored['brand_taxonomy'])) {
            $settings['brand_taxonomy'] = sanitize_key($stored['brand_taxonomy']);
        }

        foreach (['shop_archive_schema', 'remove_generator'] as $key) {
            if (isset($stored[$key]) && is_bool($stored[$key])) {
                $settings[$key] = $stored[$key];
            }
        }

        self::$memo = $settings;

        return $settings;
    }

    /**
     * The taxonomy the store chose for brands, or '' for Automatic.
     *
     * @since 2.21.0
     *
     * @return string
     */
    public static function brand_taxonomy(): string {
        return self::get()['brand_taxonomy'];
    }

    /**
     * Whether shop and product category/tag archives list their products.
     *
     * @since 2.21.0
     *
     * @return bool
     */
    public static function shop_archive_schema(): bool {
        return self::get()['shop_archive_schema'];
    }

    /**
     * Whether WooCommerce's generator tag is removed.
     *
     * @since 2.21.0
     *
     * @return bool
     */
    public static function remove_generator(): bool {
        return self::get()['remove_generator'];
    }

    /**
     * Taxonomies registered on products that could hold a brand.
     *
     * Rank Math offers every product taxonomy, and so does this: a store that
     * keeps its brands in a product attribute (`pa_brand`) or in tags has to
     * be able to say so. Only WooCommerce's internal taxonomies are left out.
     *
     * @since 2.21.0
     *
     * @return array<string,string> Taxonomy name => label, in registration order.
     */
    public static function brand_taxonomy_choices(): array {
        if (!function_exists('get_object_taxonomies')) {
            return [];
        }

        $choices = [];

        foreach ((array) get_object_taxonomies('product', 'objects') as $name => $taxonomy) {
            $name = is_object($taxonomy) && isset($taxonomy->name) ? (string) $taxonomy->name : (string) $name;

            if ('' === $name || in_array($name, self::NOT_A_BRAND, true)) {
                continue;
            }

            // A taxonomy with no admin screen holds nothing a store owner
            // assigned, so it cannot be where their brands are.
            if (is_object($taxonomy) && isset($taxonomy->show_ui) && !$taxonomy->show_ui) {
                continue;
            }

            $label = '';
            if (is_object($taxonomy)) {
                $label = (string) ($taxonomy->labels->singular_name ?? $taxonomy->label ?? '');
            }

            $choices[$name] = '' !== $label ? $label : $name;
        }

        return $choices;
    }

    /**
     * Why an input cannot be saved, or null when it can.
     *
     * A brand taxonomy has to be one the products actually carry. Saving an
     * unknown one would look like a successful save and then publish no brand
     * at all, so it is refused instead.
     *
     * The stored value is let through even when its taxonomy has gone: that is
     * what a store has after deactivating its brand plugin, the read falls back
     * to Automatic, and the card and the abilities send it back with every other
     * change. Refusing it locked the unrelated switches until the brand was
     * changed first (#1530).
     *
     * @since 2.21.0
     *
     * @param array<string,mixed> $input Raw input.
     * @return \WP_Error|null
     */
    public static function validate(array $input): ?\WP_Error {
        if (!array_key_exists('brand_taxonomy', $input)) {
            return null;
        }

        $taxonomy = $input['brand_taxonomy'];

        if (null === $taxonomy || '' === $taxonomy || $taxonomy === self::brand_taxonomy()) {
            return null;
        }

        if (!is_string($taxonomy) || !isset(self::brand_taxonomy_choices()[$taxonomy])) {
            return new \WP_Error(
                'thinkrank_invalid_brand_taxonomy',
                sprintf(
                    /* translators: %s: comma-separated taxonomy names. */
                    __('brand_taxonomy must be empty (Automatic) or one of the taxonomies registered on products: %s.', 'thinkrank'),
                    implode(', ', array_keys(self::brand_taxonomy_choices())) ?: __('none', 'thinkrank')
                ),
                ['status' => 400]
            );
        }

        return null;
    }

    /**
     * Save the keys present in the input; the rest keep their stored value.
     *
     * Call validate() first. A brand taxonomy that does not pass it is not
     * written here either, so nothing new can name an unknown one.
     *
     * @since 2.21.0
     *
     * @param array<string,mixed> $input Raw input.
     * @return array{brand_taxonomy: string, shop_archive_schema: bool, remove_generator: bool} What is stored after the write.
     */
    public static function save(array $input): array {
        $settings = self::get();

        if (array_key_exists('brand_taxonomy', $input) && null === self::validate(['brand_taxonomy' => $input['brand_taxonomy']])) {
            $settings['brand_taxonomy'] = is_string($input['brand_taxonomy']) ? $input['brand_taxonomy'] : '';
        }

        foreach (['shop_archive_schema', 'remove_generator'] as $key) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = function_exists('rest_sanitize_boolean')
                    ? (bool) rest_sanitize_boolean($input[$key])
                    : (bool) $input[$key];
            }
        }

        update_option(self::OPTION, $settings);
        self::$memo = null;

        // The product data the schema and Open Graph read is memoized per
        // request and carries the brand.
        Commerce::flush_cache();

        return self::get();
    }

    /**
     * Forget the memo.
     *
     * @since 2.21.0
     *
     * @return void
     */
    public static function flush_cache(): void {
        self::$memo = null;
    }

    /**
     * Apply the settings that change hooks.
     *
     * Registered from the plugin's component list on every request. Off a
     * WooCommerce site, and with the switch off, it registers nothing.
     *
     * @since 2.21.0
     *
     * @return void
     */
    public function init(): void {
        if (!Woocommerce::is_active() || !self::remove_generator()) {
            return;
        }

        // WooCommerce adds its generator filters while it loads its template
        // hooks, which happens before `init` on every request, so removing them
        // there cannot run too early.
        add_action('init', [self::class, 'remove_generator_tag'], 20);
    }

    /**
     * Stop WooCommerce adding its `<meta name="generator">` tag (#1478).
     *
     * The same two filters Rank Math removes. WooCommerce prints its version
     * through them, beside WordPress's own generator tag.
     *
     * @since 2.21.0
     *
     * @return void
     */
    public static function remove_generator_tag(): void {
        remove_filter('get_the_generator_html', 'wc_generator_tag', 10);
        remove_filter('get_the_generator_xhtml', 'wc_generator_tag', 10);
    }
}
