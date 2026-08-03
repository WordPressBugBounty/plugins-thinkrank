<?php
/**
 * Global SEO Schema Output Class
 *
 * Handles JSON-LD schema markup output based on Global SEO settings for different post types.
 * Generates appropriate schema markup according to the schema_type setting configured in
 * the Global SEO options for each post type.
 *
 * @package ThinkRank\Frontend
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Frontend;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global SEO Schema Output Class
 *
 * Generates and outputs JSON-LD schema markup based on Global SEO settings.
 * Supports various schema types including WebPage, Article, BlogPosting, etc.
 *
 * @since 1.0.0
 */
class Global_SEO_Schema_Output {

    /**
     * WordPress option name for storing global SEO settings
     *
     * @since 1.0.0
     * @var string
     */
    private const OPTION_NAME = 'thinkrank_global_seo_settings';

    /**
     * Schema context URL
     *
     * @since 1.0.0
     * @var string
     */
    private const SCHEMA_CONTEXT = 'https://schema.org';

    /**
     * Initialize the schema output
     *
     * @since 1.0.0
     */
    public function init(): void {
        // Hook into wp_head to output schema markup
        add_action('wp_head', [$this, 'output_global_seo_schema'], 15);
    }

    /**
     * Output JSON-LD schema markup based on Global SEO settings
     *
     * @since 1.0.0
     * @return void
     */
    public function output_global_seo_schema(): void {
        // Archives get a CollectionPage schema instead of the per-post-type one
        if (!is_singular()) {
            $this->output_archive_schema();
            return;
        }

        $post = get_post();
        if (!$post) {
            return;
        }

        $post_type = get_post_type($post);
        if (!$post_type) {
            return;
        }

        // Get Global SEO settings for this post type
        $settings = $this->get_global_seo_settings($post_type);
        if (empty($settings) || empty($settings['schema_type'])) {
            return;
        }

        $schema_type = $settings['schema_type'];
        $article_type = $settings['article_type'] ?? '';
        $media_type = $settings['media_type'] ?? '';

        // Generate schema markup
        $schema = $this->generate_schema($schema_type, $article_type, $media_type, $post);

        if (empty($schema)) {
            return;
        }

        /**
         * Filter the generated schema graph before output.
         *
         * Lets add-ons (e.g. ThinkRank Pro's WooCommerce module) enrich the
         * schema — adding GTIN/MPN, variation offers, brand, etc. — without
         * forking this class.
         *
         * @since 1.14.0
         *
         * @param array    $schema      The schema array.
         * @param string   $schema_type The configured schema type.
         * @param \WP_Post $post        The current post.
         */
        $schema = apply_filters('thinkrank_schema_output', $schema, $schema_type, $post);

        if (empty($schema)) {
            return;
        }

        // Output the schema markup
        $this->output_schema_markup($schema, $schema_type);
    }

    /**
     * Output CollectionPage schema for archive contexts.
     *
     * Covers the blog home, post type archives (e.g. a docs archive) and
     * taxonomy archives. Search results, 404s and other contexts get nothing.
     *
     * @since 1.16.0
     * @return void
     */
    private function output_archive_schema(): void {
        $name = '';
        $url = '';
        $description = '';

        if (is_home() && !is_front_page()) {
            $posts_page_id = (int) get_option('page_for_posts');
            $name = $posts_page_id ? get_the_title($posts_page_id) : __('Blog', 'thinkrank');
            $url = $posts_page_id ? (string) get_permalink($posts_page_id) : home_url('/');
        } elseif (is_post_type_archive()) {
            $post_type_object = get_queried_object();
            if (!$post_type_object instanceof \WP_Post_Type) {
                return;
            }
            $name = $post_type_object->labels->name ?? $post_type_object->label;
            $url = (string) get_post_type_archive_link($post_type_object->name);
            $description = $post_type_object->description;
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term instanceof \WP_Term) {
                return;
            }
            $term_link = get_term_link($term);
            if (is_wp_error($term_link)) {
                return;
            }
            $name = $term->name;
            $url = $term_link;
            $description = (string) term_description($term);
        } else {
            return;
        }

        if (empty($url)) {
            return;
        }

        $schema = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type'    => 'CollectionPage',
            'name'     => $name,
            'url'      => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                '@id'   => home_url('/#website'),
                'url'   => home_url('/'),
            ],
        ];

        $description = trim(wp_strip_all_tags($description));
        if (!empty($description)) {
            $schema['description'] = $description;
        }

        /**
         * Filter the archive CollectionPage schema before output.
         *
         * @since 1.16.0
         *
         * @param array $schema The schema array ([] suppresses output).
         */
        $schema = apply_filters('thinkrank_archive_schema_output', $schema);

        if (empty($schema)) {
            return;
        }

        $this->output_schema_markup($schema, 'CollectionPage');
    }

    /**
     * Whether ThinkRank would emit structured data for a given post type.
     *
     * Reflects the exact decision `output_global_seo_schema()` makes for
     * singular views: schema is emitted when a `schema_type` resolves for the
     * post type — either an explicit saved value or the built-in per-post-type
     * default. Exposed so the Site SEO Analyzer can ask the output layer
     * directly instead of re-reading a legacy option, keeping the audit and the
     * rendered page from ever disagreeing about whether schema is configured.
     *
     * @since 1.23.1
     * @param string $post_type Post type slug.
     * @return bool True when structured data would be output for this post type.
     */
    public function would_output_schema(string $post_type): bool {
        $settings = $this->get_global_seo_settings($post_type);

        return !empty($settings['schema_type']);
    }

    /**
     * Get Global SEO settings for a specific post type
     *
     * @since 1.0.0
     * @param string $post_type Post type
     * @return array Settings array
     */
    private function get_global_seo_settings(string $post_type): array {
        $all_settings = get_option(self::OPTION_NAME, []);
        $settings     = $all_settings[$post_type] ?? [];

        // Fall back to a sensible default schema type when nothing is saved for
        // this post type, so structured data works out of the box on sites that
        // never opened the Global SEO settings (e.g. migrated from Rank Math).
        // An explicit saved schema_type always wins. Mirrors the per-post-type
        // defaults the REST endpoint (Global_SEO_Endpoint::get_default_settings)
        // exposes to the admin UI.
        if (empty($settings['schema_type'])) {
            $default = $this->get_default_schema_type($post_type);
            if ($default !== null) {
                $settings = array_merge($default, $settings);
            }
        }

        return $settings;
    }

    /**
     * Default schema type (and sub-type) for a post type when unconfigured.
     *
     * @since 1.15.x
     * @param string $post_type Post type slug
     * @return array|null ['schema_type' => ..., 'article_type' => ..., 'media_type' => ...] or null to emit nothing
     */
    private function get_default_schema_type(string $post_type): ?array {
        switch ($post_type) {
            case 'post':
                return ['schema_type' => 'Article', 'article_type' => 'BlogPosting', 'media_type' => ''];
            case 'page':
                return ['schema_type' => 'WebPage', 'article_type' => '', 'media_type' => ''];
            case 'attachment':
                return ['schema_type' => 'Media', 'article_type' => '', 'media_type' => 'ImageObject'];
            case 'product':
                // Only claim Product schema when WooCommerce is actually present,
                // so a generic CPT named "product" without WooCommerce still gets
                // WebPage rather than an offers-less Product graph.
                return class_exists('WooCommerce')
                    ? ['schema_type' => 'Product', 'article_type' => '', 'media_type' => '']
                    : ['schema_type' => 'WebPage', 'article_type' => '', 'media_type' => ''];
            default:
                // Public custom post types (e.g. BetterDocs `docs`) get WebPage.
                $object = get_post_type_object($post_type);
                if ($object && empty($object->public)) {
                    return null;
                }
                return ['schema_type' => 'WebPage', 'article_type' => '', 'media_type' => ''];
        }
    }

    /**
     * Generate schema markup based on schema type
     *
     * @since 1.0.0
     * @param string   $schema_type  Schema type (e.g., 'Article', 'WebPage', 'Media')
     * @param string   $article_type Article type (e.g., 'BlogPosting', 'NewsArticle')
     * @param string   $media_type   Media type (e.g., 'ImageObject', 'VideoObject')
     * @param \WP_Post $post         WordPress post object
     * @return array Schema markup array
     */
    private function generate_schema(string $schema_type, string $article_type, string $media_type, \WP_Post $post): array {
        // Determine the actual type to use based on schema_type and sub-types
        $type = $schema_type;

        // Use article_type if schema_type is 'Article' and article_type is specified
        if ($schema_type === 'Article' && !empty($article_type)) {
            $type = $article_type;
        }

        // Use media_type if schema_type is 'Media' and media_type is specified
        if ($schema_type === 'Media' && !empty($media_type)) {
            $type = $media_type;
        }

        // Generate schema based on type
        switch ($type) {
            case 'Article':
            case 'BlogPosting':
            case 'NewsArticle':
            case 'ScholarlyArticle':
            case 'TechArticle':
                return $this->generate_article_schema($type, $post);

            case 'WebPage':
            case 'AboutPage':
            case 'ContactPage':
            case 'FAQPage':
            case 'ProfilePage':
                return $this->generate_webpage_schema($type, $post);

            case 'ImageObject':
                return $this->generate_image_schema($post);

            case 'VideoObject':
                return $this->generate_video_schema($post);

            case 'Product':
                return $this->generate_product_schema($post);

            case 'Event':
                return $this->generate_event_schema($post);

            case 'Media':
                // Fallback to ImageObject if Media is selected but no media_type specified
                return $this->generate_image_schema($post);

            default:
                // Fallback to WebPage for unknown types
                return $this->generate_webpage_schema('WebPage', $post);
        }
    }

    /**
     * Generate Article schema markup
     *
     * @since 1.0.0
     * @param string   $type Article type
     * @param \WP_Post $post WordPress post object
     * @return array Schema markup
     */
    private function generate_article_schema(string $type, \WP_Post $post): array {
        $schema = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type' => $type,
            'headline' => get_the_title($post),
            'url' => get_permalink($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
        ];

        // Add description
        $excerpt = get_the_excerpt($post);
        if (!empty($excerpt)) {
            $schema['description'] = wp_strip_all_tags($excerpt);
        }

        // Add author
        $author_id = $post->post_author;
        if ($author_id) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $author_id),
                'url' => get_author_posts_url($author_id),
            ];
        }

        // Add publisher (site info)
        $schema['publisher'] = $this->get_publisher_schema();

        // Add featured image if available
        if (has_post_thumbnail($post)) {
            $image_id = get_post_thumbnail_id($post);
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $schema['image'] = [
                    '@type' => 'ImageObject',
                    'url' => $image_url,
                ];

                // Add image dimensions if available
                $image_meta = wp_get_attachment_metadata($image_id);
                if (!empty($image_meta['width']) && !empty($image_meta['height'])) {
                    $schema['image']['width'] = $image_meta['width'];
                    $schema['image']['height'] = $image_meta['height'];
                }
            }
        }

        // Add main entity of page
        $schema['mainEntityOfPage'] = [
            '@type' => 'WebPage',
            '@id' => get_permalink($post),
        ];

        return $schema;
    }

    /**
     * Generate WebPage schema markup
     *
     * @since 1.0.0
     * @param string   $type WebPage type
     * @param \WP_Post $post WordPress post object
     * @return array Schema markup
     */
    private function generate_webpage_schema(string $type, \WP_Post $post): array {
        $schema = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type' => $type,
            'name' => get_the_title($post),
            'url' => get_permalink($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
        ];

        // Add description
        $excerpt = get_the_excerpt($post);
        if (!empty($excerpt)) {
            $schema['description'] = wp_strip_all_tags($excerpt);
        }

        // Add featured image if available
        if (has_post_thumbnail($post)) {
            $image_url = get_the_post_thumbnail_url($post, 'full');
            if ($image_url) {
                $schema['image'] = $image_url;
            }
        }

        return $schema;
    }

    /**
     * Generate ImageObject schema markup
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object (attachment)
     * @return array Schema markup
     */
    private function generate_image_schema(\WP_Post $post): array {
        $image_url = wp_get_attachment_url($post->ID);
        $image_meta = wp_get_attachment_metadata($post->ID);

        $schema = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type' => 'ImageObject',
            'contentUrl' => $image_url,
            'url' => get_permalink($post),
            'name' => get_the_title($post),
        ];

        // Add caption/description
        $caption = wp_get_attachment_caption($post->ID);
        if (!empty($caption)) {
            $schema['caption'] = $caption;
            $schema['description'] = $caption;
        }

        // Add dimensions
        if (!empty($image_meta['width']) && !empty($image_meta['height'])) {
            $schema['width'] = $image_meta['width'];
            $schema['height'] = $image_meta['height'];
        }

        // Add upload date
        $schema['uploadDate'] = get_the_date('c', $post);

        return $schema;
    }

    /**
     * Generate VideoObject schema markup
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object (attachment or post with video)
     * @return array Schema markup
     */
    private function generate_video_schema(\WP_Post $post): array {
        $schema = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type' => 'VideoObject',
            'name' => get_the_title($post),
            'url' => get_permalink($post),
        ];

        // Add description
        $description = get_the_excerpt($post);
        if (empty($description)) {
            $caption = wp_get_attachment_caption($post->ID);
            if (!empty($caption)) {
                $description = $caption;
            }
        }
        if (!empty($description)) {
            $schema['description'] = wp_strip_all_tags($description);
        }

        // For video attachments, add contentUrl
        if ($post->post_type === 'attachment') {
            $video_url = wp_get_attachment_url($post->ID);
            if ($video_url) {
                $schema['contentUrl'] = $video_url;
            }

            // Add upload date
            $schema['uploadDate'] = get_the_date('c', $post);
        }

        // Add thumbnail/poster image if available
        if (has_post_thumbnail($post)) {
            $thumbnail_url = get_the_post_thumbnail_url($post, 'full');
            if ($thumbnail_url) {
                $schema['thumbnailUrl'] = $thumbnail_url;
            }
        }

        // Add duration if available from meta
        $duration = get_post_meta($post->ID, '_thinkrank_video_duration', true);
        if (!empty($duration)) {
            $schema['duration'] = $duration; // Should be in ISO 8601 format (e.g., PT1M30S)
        }

        // Add embed URL if available from meta
        $embed_url = get_post_meta($post->ID, '_thinkrank_video_embed_url', true);
        if (!empty($embed_url)) {
            $schema['embedUrl'] = $embed_url;
        }

        return $schema;
    }

    /**
     * Generate Product schema markup
     *
     * Generates valid Schema.org Product markup with required and recommended properties.
     * Supports custom meta fields and WooCommerce integration.
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return array Schema markup
     */
    private function generate_product_schema(\WP_Post $post): array {
        // Base Product schema with required properties
        $schema = [
            '@context' => self::SCHEMA_CONTEXT,
            '@type' => 'Product',
            'name' => get_the_title($post),
            'url' => get_permalink($post),
        ];

        // Add description (required for valid Product schema)
        $description = $this->get_product_description($post);
        if (!empty($description)) {
            $schema['description'] = $description;
        }

        // Add image (required for valid Product schema)
        $image = $this->get_product_image($post);
        if (!empty($image)) {
            $schema['image'] = $image;
        }

        // Add SKU if available
        $sku = $this->get_product_sku($post);
        if (!empty($sku)) {
            $schema['sku'] = $sku;
        }

        // Add brand (recommended)
        $brand = $this->get_product_brand($post);
        if (!empty($brand)) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $brand,
            ];
        }

        // Add offers (required for valid Product schema)
        $offers = $this->get_product_offers($post);
        if (!empty($offers)) {
            $schema['offers'] = $offers;
        }

        // Add aggregate rating (recommended)
        $rating = $this->get_product_rating($post);
        if (!empty($rating)) {
            $schema['aggregateRating'] = $rating;
        }

        // Add reviews (recommended)
        $reviews = $this->get_product_reviews($post);
        if (!empty($reviews)) {
            $schema['review'] = $reviews;
        }

        return $schema;
    }

    /**
     * Generate Event schema markup (placeholder)
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return array Schema markup
     */
    private function generate_event_schema(\WP_Post $post): array {
        // Basic Event schema - can be extended based on requirements
        return $this->generate_webpage_schema('WebPage', $post);
    }

    /**
     * Get publisher schema (Organization or Person)
     *
     * @since 1.0.0
     * @return array Publisher schema
     */
    private function get_publisher_schema(): array {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $publisher = [
            '@type' => 'Organization',
            'name' => $site_name,
            'url' => $site_url,
        ];

        // Add logo if available
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
            if ($logo_url) {
                $publisher['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $logo_url,
                ];
            }
        }

        return $publisher;
    }

    /**
     * Get product description
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return string Product description
     */
    private function get_product_description(\WP_Post $post): string {
        // Try custom meta field first
        $description = get_post_meta($post->ID, '_thinkrank_product_description', true);

        // Fallback to excerpt or content
        if (empty($description)) {
            $description = get_the_excerpt($post);
        }

        if (empty($description)) {
            $description = wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        }

        return wp_strip_all_tags($description);
    }

    /**
     * Get product image
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return array|string Product image data
     */
    private function get_product_image(\WP_Post $post) {
        // Try featured image first
        if (has_post_thumbnail($post)) {
            $image_id = get_post_thumbnail_id($post);
            $image_url = wp_get_attachment_image_url($image_id, 'full');

            if ($image_url) {
                $image_meta = wp_get_attachment_metadata($image_id);

                return [
                    '@type' => 'ImageObject',
                    'url' => $image_url,
                    // SVGs report 0x0 — send null rather than a zero dimension.
                    'width' => !empty($image_meta['width']) ? (int) $image_meta['width'] : null,
                    'height' => !empty($image_meta['height']) ? (int) $image_meta['height'] : null,
                ];
            }
        }

        // Try custom meta field
        $custom_image = get_post_meta($post->ID, '_thinkrank_product_image', true);
        if (!empty($custom_image)) {
            return $custom_image;
        }

        return '';
    }

    /**
     * Get product SKU
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return string Product SKU
     */
    private function get_product_sku(\WP_Post $post): string {
        // Try custom meta field
        $sku = get_post_meta($post->ID, '_thinkrank_product_sku', true);

        // Try WooCommerce if available
        if (empty($sku) && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $sku = $product->get_sku();
            }
        }

        return (string) $sku;
    }

    /**
     * Get product brand
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return string Product brand
     */
    private function get_product_brand(\WP_Post $post): string {
        // Try custom meta field
        $brand = get_post_meta($post->ID, '_thinkrank_product_brand', true);

        // Try WooCommerce brand taxonomy if available
        if (empty($brand) && taxonomy_exists('product_brand')) {
            $terms = get_the_terms($post->ID, 'product_brand');
            if (!empty($terms) && !is_wp_error($terms)) {
                $brand = $terms[0]->name;
            }
        }

        return (string) $brand;
    }

    /**
     * Get product offers
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return array Product offers data
     */
    private function get_product_offers(\WP_Post $post): array {
        $offers = [
            '@type' => 'Offer',
            'url' => get_permalink($post),
        ];

        // Get price
        $price = get_post_meta($post->ID, '_thinkrank_product_price', true);

        // Try WooCommerce if available
        if (empty($price) && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $price = $product->get_price();
            }
        }

        if (!empty($price)) {
            $offers['price'] = (string) $price;
        }

        // Get currency
        $currency = get_post_meta($post->ID, '_thinkrank_product_currency', true);

        // Try WooCommerce currency if available
        if (empty($currency) && function_exists('get_woocommerce_currency')) {
            $currency = get_woocommerce_currency();
        }

        // Default to USD
        if (empty($currency)) {
            $currency = 'USD';
        }

        $offers['priceCurrency'] = $currency;

        // Get availability
        $availability = get_post_meta($post->ID, '_thinkrank_product_availability', true);

        // Try WooCommerce if available
        if (empty($availability) && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $availability = $product->is_in_stock() ? 'InStock' : 'OutOfStock';
            }
        }

        // Default to InStock
        if (empty($availability)) {
            $availability = 'InStock';
        }

        // Ensure proper schema.org URL format
        if (strpos($availability, 'https://schema.org/') !== 0) {
            $offers['availability'] = 'https://schema.org/' . $availability;
        } else {
            $offers['availability'] = $availability;
        }

        // Add price valid until if available
        $price_valid_until = get_post_meta($post->ID, '_thinkrank_product_price_valid_until', true);
        if (!empty($price_valid_until)) {
            $offers['priceValidUntil'] = $price_valid_until;
        }

        return $offers;
    }

    /**
     * Get product aggregate rating
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return array Product rating data
     */
    private function get_product_rating(\WP_Post $post): array {
        $rating = [];

        // Try custom meta fields
        $rating_value = get_post_meta($post->ID, '_thinkrank_product_rating_value', true);
        $rating_count = get_post_meta($post->ID, '_thinkrank_product_rating_count', true);

        // Try WooCommerce if available
        if ((empty($rating_value) || empty($rating_count)) && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $wc_rating_count = $product->get_rating_count();
                $wc_average = $product->get_average_rating();

                if ($wc_rating_count > 0 && $wc_average > 0) {
                    $rating_value = $wc_average;
                    $rating_count = $wc_rating_count;
                }
            }
        }

        // Only return rating if we have both value and count
        if (!empty($rating_value) && !empty($rating_count)) {
            $rating = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $rating_value,
                'reviewCount' => (int) $rating_count,
                'bestRating' => '5',
            ];
        }

        return $rating;
    }

    /**
     * Get product reviews
     *
     * @since 1.0.0
     * @param \WP_Post $post WordPress post object
     * @return array Product reviews data
     */
    private function get_product_reviews(\WP_Post $post): array {
        $reviews = [];

        // Try WooCommerce reviews if available
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $comments = get_comments([
                    'post_id' => $post->ID,
                    'status' => 'approve',
                    'type' => 'review',
                    'number' => 5, // Limit to 5 most recent reviews
                ]);

                foreach ($comments as $comment) {
                    $rating = get_comment_meta($comment->comment_ID, 'rating', true);

                    if (!empty($rating)) {
                        $reviews[] = [
                            '@type' => 'Review',
                            'reviewRating' => [
                                '@type' => 'Rating',
                                'ratingValue' => (string) $rating,
                                'bestRating' => '5',
                            ],
                            'author' => [
                                '@type' => 'Person',
                                'name' => $comment->comment_author,
                            ],
                            'reviewBody' => wp_strip_all_tags($comment->comment_content),
                            'datePublished' => get_comment_date('c', $comment),
                        ];
                    }
                }
            }
        }

        // Try custom meta field for manual reviews
        if (empty($reviews)) {
            $custom_reviews = get_post_meta($post->ID, '_thinkrank_product_reviews', true);
            if (!empty($custom_reviews) && is_array($custom_reviews)) {
                $reviews = $custom_reviews;
            }
        }

        return $reviews;
    }

    /**
     * Output schema markup as JSON-LD
     *
     * @since 1.0.0
     * @param array  $schema      Schema data
     * @param string $schema_type Schema type name
     * @return void
     */
    private function output_schema_markup(array $schema, string $schema_type): void {
        if (empty($schema)) {
            return;
        }

        echo '<!-- ThinkRank Global SEO: ' . esc_html($schema_type) . ' Schema -->' . "\n";
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "\n";
        echo '</script>' . "\n";
        echo '<!-- /ThinkRank Global SEO: ' . esc_html($schema_type) . ' Schema -->' . "\n";
    }
}

