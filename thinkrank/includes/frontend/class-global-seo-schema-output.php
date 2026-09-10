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

        // One Product entity per product page: when ThinkRank emits the
        // Product schema (the default for WooCommerce products), WooCommerce
        // core's own JSON-LD must stand down, or the page carries two
        // aggregateRating blocks and Search Console raises the critical
        // "Review has multiple aggregate ratings" error. Registered eagerly
        // and decided lazily inside the callback, because WooCommerce
        // generates its data during the product template render — which on
        // block themes can run before wp_head, too early for a flag set at
        // output time to exist yet.
        add_filter('woocommerce_structured_data_product', [$this, 'suppress_woocommerce_product_schema'], 20, 2);
    }

    /**
     * Yield WooCommerce's Product structured data when ThinkRank emits the
     * Product entity for the page being viewed.
     *
     * Mirrors what other SEO plugins do with WC_Structured_Data: exactly one
     * plugin may describe the product. Suppression is surgical — only the
     * queried product on its own singular view, only when this class's
     * settings resolution says a Product schema will be generated (explicit
     * or the WooCommerce default), and WooCommerce's breadcrumb and other
     * structured data are never touched. With ThinkRank's product schema
     * disabled or set to another type, WooCommerce's markup passes through
     * unchanged.
     *
     * @since 2.0.1
     * @param array $markup  WooCommerce's generated Product markup.
     * @param mixed $product WC_Product being described.
     * @return array Original markup, or empty to suppress.
     */
    public function suppress_woocommerce_product_schema($markup, $product = null) {
        if (!is_array($markup) || !is_singular()) {
            return $markup;
        }

        // Only the main product of this page — a card grid or related-products
        // widget describing other products is not ours to silence.
        $queried_id = (int) get_queried_object_id();
        $product_id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        if (!$queried_id || !$product_id || $queried_id !== $product_id) {
            return $markup;
        }

        $post_type = (string) get_post_type($queried_id);
        if ($post_type === '') {
            return $markup;
        }

        $settings = $this->get_global_seo_settings($post_type);
        if (($settings['schema_type'] ?? '') === 'Product') {
            // ...but only if this class is actually going to emit it. The
            // per-content-type Schema switch (#660) makes
            // output_global_seo_schema() return before it builds anything, so
            // claiming the entity here as well left the page with NO product
            // structured data at all — strictly worse than the duplicate this
            // method exists to prevent, and the opposite of what the docblock
            // above promises for "ThinkRank's product schema disabled".
            if (!\ThinkRank\SEO\Content_Type_Settings::is_enabled_for_current(
                \ThinkRank\SEO\Content_Type_Settings::FEATURE_SCHEMA,
                true
            )) {
                return $markup;
            }

            return [];
        }

        // A per-post DEPLOYED Product schema duplicates WooCommerce's markup
        // just the same, even when the post-type-wide setting points elsewhere.
        // Checked second because the default path above answers without a
        // query; this one is a single indexed lookup and only runs on the
        // rare configured-away sites.
        if ($this->post_has_deployed_product_schema($queried_id)) {
            return [];
        }

        return $markup;
    }

    /**
     * Whether an active per-post Product schema deployment exists for a post.
     *
     * Reads the deployment table directly rather than constructing
     * Schema_Management_System — this runs inside WooCommerce's structured
     * data filter on product pages, where spinning up the full manager (and
     * its builder) to answer a yes/no question would be waste. Query shape
     * matches get_deployed_schemas(): active rows for the post context.
     *
     * @since 2.0.1
     * @param int $post_id Post to check.
     * @return bool
     */
    private function post_has_deployed_product_schema(int $post_id): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'thinkrank_seo_schema';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed EXISTS-style lookup on the render path; the deployment cache layer belongs to the full manager this deliberately avoids constructing.
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE context_type = 'post' AND context_id = %d AND schema_type = 'Product' AND is_active = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            $post_id
        ));

        return '1' === (string) $found;
    }

    /**
     * Output JSON-LD schema markup based on Global SEO settings
     *
     * @since 1.0.0
     * @return void
     */
    public function output_global_seo_schema(): void {
        // Per-content-type schema switch. 'inherit' (the default) keeps schema
        // on, exactly as before the matrix existed (#660).
        if (!\ThinkRank\SEO\Content_Type_Settings::is_enabled_for_current(
            \ThinkRank\SEO\Content_Type_Settings::FEATURE_SCHEMA,
            true
        )) {
            return;
        }

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

        // Register as a candidate for the page's single page-level entity. The
        // Schema Manager's per-post deployment outranks this post-type-wide
        // default when both describe the same page (#355).
        $this->register_schema($schema, $schema_type, 'global_seo');
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

            // WooCommerce maps the shop archive onto a real page, so
            // get_queried_object() returns that WP_Post while
            // is_post_type_archive() is still true. Bailing here left every
            // store's main archive with no CollectionPage (#466). Fall back to
            // the query var, exactly as the canonical resolver already does.
            if (!$post_type_object instanceof \WP_Post_Type) {
                $queried_post_type = (string) get_query_var('post_type');
                $post_type_object  = $queried_post_type
                    ? get_post_type_object($queried_post_type)
                    : null;
            }

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

        // Page 2 of an archive is a different URL and must be a different node.
        // The link above is always the un-paginated one, so Schema_Graph::base_url()
        // minted the identical #collectionpage and #breadcrumb @id on every
        // page — distinct URLs claiming the same node identity (#397).
        $url = \ThinkRank\Frontend\SEO_Manager::with_pagination(
            (string) $url,
            \ThinkRank\Frontend\SEO_Manager::current_page_number()
        );

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

        $this->register_schema($schema, 'CollectionPage', 'global_seo');
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
        if (!\ThinkRank\SEO\Content_Type_Settings::is_enabled(
            \ThinkRank\SEO\Content_Type_Settings::FEATURE_SCHEMA,
            $post_type,
            true
        )) {
            return false;
        }

        $settings = $this->get_global_seo_settings($post_type);

        return !empty($settings['schema_type']);
    }

    /**
     * Whether this post type has a SAVED schema type, ignoring the built-in
     * per-post-type default.
     *
     * would_output_schema() answers "will JSON-LD be emitted?", which the
     * fallback in get_global_seo_settings() makes true for every public post
     * type. The audit needs the different question "has the user configured
     * anything?", so this reads the stored option without the default merge.
     *
     * @since 2.2.0
     * @param string $post_type Post type.
     * @return bool True when an explicit schema_type is stored for this type.
     */
    public function has_explicit_schema_type(string $post_type): bool {
        $all_settings = get_option(self::OPTION_NAME, []);

        return !empty($all_settings[$post_type]['schema_type']);
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

            case 'FAQPage':
                return $this->generate_faq_schema($post);

            case 'WebPage':
            case 'AboutPage':
            case 'ContactPage':
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

        // Add description. A Bricks page's stored `post_content` is not on the
        // page, so core's derived excerpt must not describe it (#651).
        $excerpt = $this->post_excerpt_text($post);
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
     * Generate FAQPage schema markup
     *
     * FAQPage previously fell through to generate_webpage_schema(), which emits a
     * WebPage-shaped object labelled @type FAQPage with no mainEntity — invalid for
     * rich results. Delegate to Schema_Builder instead, which owns the FAQ question
     * extraction already used by the deploy path, rather than growing a second
     * FAQ implementation here.
     *
     * Unlike the deploy path, this runs automatically on every post of the type with
     * no human reviewing the result, so questions that don't actually read as
     * questions are dropped and a page with none left falls back to WebPage — an
     * FAQPage with an empty mainEntity is worse than a valid WebPage.
     *
     * @since 1.32.0
     * @param \WP_Post $post WordPress post object
     * @return array Schema markup
     */
    private function generate_faq_schema(\WP_Post $post): array {
        if (!class_exists('ThinkRank\\SEO\\Schema_Builder')) {
            $builder_file = THINKRANK_PLUGIN_DIR . 'includes/seo/class-schema-builder.php';
            if (!file_exists($builder_file)) {
                return $this->generate_webpage_schema('WebPage', $post);
            }
            require_once $builder_file;
        }

        $excerpt = $this->post_excerpt_text($post);

        $builder = new \ThinkRank\SEO\Schema_Builder();
        $schema  = $builder->build_schema(
            'FAQPage',
            [
                'title'   => get_the_title($post),
                'content' => \ThinkRank\SEO\Builder_Content::visible_content($post),
                'excerpt' => $excerpt ? wp_strip_all_tags($excerpt) : '',
                'url'     => get_permalink($post),
            ],
            get_post_type($post) === 'page' ? 'page' : 'post'
        );

        if (!empty($schema['_error'])) {
            return $this->generate_webpage_schema('WebPage', $post);
        }

        $schema['mainEntity'] = $this->filter_faq_entities($schema['mainEntity'] ?? []);

        // No usable Q&A pairs — emit a valid WebPage rather than an empty FAQPage.
        if (empty($schema['mainEntity'])) {
            return $this->generate_webpage_schema('WebPage', $post);
        }

        $schema['datePublished'] = get_the_date('c', $post);
        $schema['dateModified']  = get_the_modified_date('c', $post);

        return $schema;
    }

    /**
     * Keep only FAQ entities that genuinely read as a question/answer pair.
     *
     * Schema_Builder's content extraction falls back to a heading-followed-by-paragraph
     * pattern, which on an ordinary page matches every section and would fabricate Q&A
     * that never appears on the page as such.
     *
     * @since 1.32.0
     * @param array $entities Candidate mainEntity entries
     * @return array Filtered entries
     */
    private function filter_faq_entities(array $entities): array {
        $filtered = [];

        foreach ($entities as $entity) {
            $question = isset($entity['name']) ? trim((string) $entity['name']) : '';
            $answer   = isset($entity['acceptedAnswer']['text'])
                ? trim((string) $entity['acceptedAnswer']['text'])
                : '';

            if ($question === '' || $answer === '' || strpos($question, '?') === false) {
                continue;
            }

            $filtered[] = $entity;
        }

        return array_values($filtered);
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
        $excerpt = $this->post_excerpt_text($post);
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
        $description = $this->post_excerpt_text($post);
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

        // Fallback to excerpt or content. On a Bricks page the excerpt core
        // derives comes from discarded `post_content`, so the visible body is
        // used instead (#651).
        if (empty($description)) {
            $description = $this->post_excerpt_text($post);
        }

        if (empty($description)) {
            $description = \ThinkRank\SEO\Pattern_Resolver::derive_excerpt(
                \ThinkRank\SEO\Builder_Content::visible_content($post),
                30
            );
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

                // SVGs, offloaded media and failed metadata regeneration all
                // report no dimensions. Omit the keys entirely — a literal JSON
                // null is an invalid value that Google flags, which is what the
                // previous `: null` fallback emitted (#471). Matches
                // Schema_Builder::format_image_schema().
                $image_object = [
                    '@type' => 'ImageObject',
                    'url' => $image_url,
                ];

                if (!empty($image_meta['width'])) {
                    $image_object['width'] = (int) $image_meta['width'];
                }

                if (!empty($image_meta['height'])) {
                    $image_object['height'] = (int) $image_meta['height'];
                }

                return $image_object;
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
     * The post's excerpt, taken from content the page actually renders.
     *
     * `get_the_excerpt()` falls back to trimming `post_content`, which a Bricks
     * page discards — so on one of those it describes text no visitor sees. A
     * hand-written excerpt is the author's own summary and still wins, because
     * `superseding_excerpt_source()` yields nothing for a post that has one
     * (#651).
     *
     * @since 2.3.1
     * @param \WP_Post $post Post being described.
     * @return string
     */
    private function post_excerpt_text(\WP_Post $post): string {
        $superseding = \ThinkRank\SEO\Builder_Content::superseding_excerpt_source($post);

        return '' !== $superseding
            ? \ThinkRank\SEO\Pattern_Resolver::derive_excerpt($superseding, 30)
            : (string) get_the_excerpt($post);
    }

    /**
     * Register generated schema with the request's schema graph.
     *
     * Replaces the direct echo this class used to do: the graph arbitrates
     * between this post-type-wide schema and the Schema Manager's per-post
     * deployment, then emits one linked @graph (#355).
     *
     * @since 1.32.0
     * @param array  $schema      Schema markup array
     * @param string $schema_type Schema @type
     * @param string $source      Producer key used for precedence
     * @return void
     */
    private function register_schema(array $schema, string $schema_type, string $source): void {
        if (empty($schema)) {
            return;
        }

        if (!class_exists('ThinkRank\\Frontend\\Schema_Graph')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/frontend/class-schema-graph.php';
        }

        Schema_Graph::instance()->add_primary($schema, $schema_type, $source);
    }
}
