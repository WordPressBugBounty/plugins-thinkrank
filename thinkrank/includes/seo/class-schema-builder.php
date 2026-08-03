<?php
/**
 * Schema Builder Class
 *
 * Handles schema markup construction and JSON-LD output formatting.
 * Extracted from Schema_Generator to follow Single Responsibility Principle.
 * Maintains exact same building logic and output formats as original implementation.
 *
 * @package ThinkRank\SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema Builder Class
 *
 * Constructs schema markup from data and formats JSON-LD output.
 * Preserves all existing building logic and output formats.
 *
 * @since 1.0.0
 */
class Schema_Builder {

    /**
     * Schema Factory instance for base structures
     *
     * @since 1.0.0
     * @var Schema_Factory
     */
    private Schema_Factory $schema_factory;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Ensure Schema_Factory is loaded
        if (!class_exists('ThinkRank\\SEO\\Schema_Factory')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-schema-factory.php';
        }
        $this->schema_factory = new Schema_Factory();
    }

    /**
     * Build schema markup for specific type
     * PRESERVED: Maintains exact same building logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type to build
     * @param array  $data        Content data for schema building
     * @param string $context     Context type ('site', 'post', 'page', etc.)
     * @return array Built schema markup
     */
    public function build_schema(string $schema_type, array $data, string $context): array {
        // Normalize and validate schema type
        $schema_type = $this->schema_factory->normalize_schema_type($schema_type);

        if (!$this->schema_factory->is_supported_schema_type($schema_type)) {
            return $this->create_error_schema('Unsupported schema type: ' . $schema_type);
        }

        // Get base schema structure
        $schema = $this->schema_factory->create_base_schema($schema_type);
        
        // Populate schema with data based on type
        switch ($schema_type) {
            case 'Article':
            case 'BlogPosting':
            case 'TechnicalArticle':
            case 'NewsArticle':
            case 'ScholarlyArticle':
            case 'Report':
                $schema = $this->populate_article_schema($schema, $data, $context);
                break;
            case 'Product':
                $schema = $this->populate_product_schema($schema, $data, $context);
                break;
            case 'Organization':
                $schema = $this->populate_organization_schema($schema, $data, $context);
                break;
            case 'WebSite':
                $schema = $this->populate_website_schema($schema, $data, $context);
                break;
            case 'WebPage':
                $schema = $this->populate_webpage_schema($schema, $data, $context);
                break;
            case 'FAQPage':
                $schema = $this->populate_faq_schema($schema, $data, $context);
                break;
            case 'LocalBusiness':
                $schema = $this->populate_local_business_schema($schema, $data, $context);
                break;
            case 'Person':
                $schema = $this->populate_person_schema($schema, $data, $context);
                break;
            case 'SoftwareApplication':
                $schema = $this->populate_software_application_schema($schema, $data, $context);
                break;
            case 'Event':
                $schema = $this->populate_event_schema($schema, $data, $context);
                break;
            case 'HowTo':
                $schema = $this->populate_howto_schema($schema, $data, $context);
                break;
            case 'Review':
                $schema = $this->populate_review_schema($schema, $data, $context);
                break;
            case 'VideoObject':
                $schema = $this->populate_video_object_schema($schema, $data, $context);
                break;
            default:
                $schema = $this->populate_generic_schema($schema, $data, $context);
                break;
        }

        return $schema;
    }

    /**
     * Get JSON-LD formatted output
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema Schema markup array
     * @return string JSON-LD formatted string
     */
    public function get_json_ld_output(array $schema): string {
        // Ensure proper JSON-LD structure
        if (!isset($schema['@context'])) {
            $schema['@context'] = $this->schema_factory->get_schema_context();
        }

        // Clean up empty values
        $schema = $this->clean_schema_array($schema);

        // Generate JSON-LD with proper formatting. Include the HEX flags so a
        // </script> in any field is emitted as <\/script> and can't break out
        // of the surrounding <script type="application/ld+json"> block, matching
        // the site-wide schema output path.
        $json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $json_flags |= JSON_PRETTY_PRINT;
        }

        return wp_json_encode($schema, $json_flags);
    }

    /**
     * Create error schema for unsupported types or errors
     *
     * @since 1.0.0
     *
     * @param string $error_message Error message
     * @return array Error schema structure
     */
    private function create_error_schema(string $error_message): array {
        return [
            '@context' => $this->schema_factory->get_schema_context(),
            '@type' => 'Thing',
            'name' => 'Schema Generation Error',
            'description' => $error_message,
            '_error' => true
        ];
    }

    /**
     * Populate Article schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_article_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured fields
        $schema['headline'] = $this->truncate_text(
            $data['site_data']['article_headline'] ?? $data['title'] ?? '',
            110
        );

        // Author from user configuration or fallback
        if (!empty($data['site_data']['article_author'])) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $data['site_data']['article_author']
            ];
        } else {
            $schema['author'] = $this->format_author_schema($data['author'] ?? []);
        }

        // Date published - prioritize user-configured date
        if (!empty($data['site_data']['article_date_published'])) {
            $schema['datePublished'] = $data['site_data']['article_date_published'];
        } elseif (!empty($data['date'])) {
            $schema['datePublished'] = $data['date'];
        } else {
            $schema['datePublished'] = current_time('c');
        }

        // Recommended properties - prioritize user-configured fields
        if (!empty($data['site_data']['article_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['article_description'], 160);
        } elseif (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        // Image from user configuration or fallback
        if (!empty($data['site_data']['article_image'])) {
            $schema['image'] = $this->format_image_schema($data['site_data']['article_image']);
        } elseif (!empty($data['image'])) {
            $schema['image'] = $this->format_image_schema($data['image']);
        }

        // Date modified - prioritize user-configured date
        if (!empty($data['site_data']['article_date_modified'])) {
            $schema['dateModified'] = $data['site_data']['article_date_modified'];
        } elseif (!empty($data['modified'])) {
            $schema['dateModified'] = $data['modified'];
        } else {
            $schema['dateModified'] = $schema['datePublished'];
        }

        $schema['publisher'] = $this->get_organization_schema();

        // URL for the article
        if (!empty($data['url'])) {
            $schema['url'] = $data['url'];
            $schema['mainEntityOfPage'] = [
                '@type' => 'WebPage',
                '@id' => $data['url']
            ];
        }

        // Optional properties
        if (!empty($data['content'])) {
            // Use frontend-calculated word count if provided (from SEO Analysis method)
            // Otherwise fallback to backend calculation
            $schema['wordCount'] = isset($data['word_count']) ? (int) $data['word_count'] : str_word_count(wp_strip_all_tags($data['content']));

            // Use focus keywords if available, otherwise extract from content.
            $focus_keywords = $this->resolve_focus_keywords($data);
            if (!empty($focus_keywords)) {
                $schema['keywords'] = array_merge($focus_keywords, $this->extract_keywords_from_content($data['content']));
                // Remove duplicates and limit to 10
                $schema['keywords'] = array_slice(array_unique($schema['keywords']), 0, 10);
            } else {
                $schema['keywords'] = $this->extract_keywords_from_content($data['content']);
            }
        }

        return $schema;
    }

    /**
     * Resolve the focus keyword list from schema content data.
     *
     * Prefers the multi-keyword array (`focus_keywords`); falls back to the
     * legacy single/comma-separated `focus_keyword` string.
     *
     * @param array $data Schema content data.
     * @return string[] Trimmed, non-empty focus keywords.
     */
    private function resolve_focus_keywords(array $data): array {
        $keywords = [];

        if (!empty($data['focus_keywords']) && is_array($data['focus_keywords'])) {
            $keywords = $data['focus_keywords'];
        } elseif (!empty($data['focus_keyword'])) {
            $keywords = explode(',', (string) $data['focus_keyword']);
        }

        $keywords = array_map('trim', $keywords);

        return array_values(array_filter($keywords, 'strlen'));
    }

    /**
     * Populate Product schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_product_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured fields
        $schema['name'] = $data['site_data']['product_name'] ?? $data['title'] ?? '';
        $schema['description'] = $data['site_data']['product_description'] ?? $data['excerpt'] ?? $data['content'] ?? '';

        // Truncate description
        if (!empty($schema['description'])) {
            $schema['description'] = $this->truncate_text($schema['description'], 160);
        }

        // Image from user configuration or fallback
        if (!empty($data['site_data']['product_image'])) {
            $schema['image'] = $this->format_image_schema($data['site_data']['product_image']);
        } elseif (!empty($data['image'])) {
            $schema['image'] = $this->format_image_schema($data['image']);
        }

        // Brand from user configuration
        if (!empty($data['site_data']['product_brand'])) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $data['site_data']['product_brand']
            ];
        }

        // SKU from user configuration
        if (!empty($data['site_data']['product_sku'])) {
            $schema['sku'] = $data['site_data']['product_sku'];
        }

        // GTIN from user configuration
        if (!empty($data['site_data']['product_gtin'])) {
            $schema['gtin'] = $data['site_data']['product_gtin'];
        }

        // URL from user configuration or fallback
        if (!empty($data['site_data']['product_url'])) {
            $schema['url'] = $data['site_data']['product_url'];
        } elseif (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        // Review from user configuration - check both field name formats
        if (!empty($data['site_data']['product_review_rating']) && !empty($data['site_data']['product_review_author'])) {
            $schema['review'] = [
                '@type' => 'Review',
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => $data['site_data']['product_review_rating'],
                    'bestRating' => '5'
                ],
                'author' => [
                    '@type' => 'Person',
                    'name' => $data['site_data']['product_review_author']
                ]
            ];
        } elseif (!empty($data['site_data']['product_review']) && !empty($data['site_data']['product_rating_value'])) {
            // Use form fields: product_review text and rating_value
            $schema['review'] = [
                '@type' => 'Review',
                'reviewBody' => $data['site_data']['product_review'],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => $data['site_data']['product_rating_value'],
                    'bestRating' => '5'
                ],
                'author' => [
                    '@type' => 'Person',
                    'name' => $this->first_non_empty(
                        $data['site_data']['organization_name'] ?? null,
                        get_bloginfo('name')
                    )
                ]
            ];
        }

        // Aggregate Rating from user configuration - check both field name formats
        if (!empty($data['site_data']['product_aggregate_rating']) && !empty($data['site_data']['product_review_count'])) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $data['site_data']['product_aggregate_rating'],
                'reviewCount' => $data['site_data']['product_review_count'],
                'bestRating' => '5'
            ];
        } elseif (!empty($data['site_data']['product_rating_value']) && !empty($data['site_data']['product_rating_count'])) {
            // Use form fields: product_rating_value and product_rating_count
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $data['site_data']['product_rating_value'],
                'reviewCount' => $data['site_data']['product_rating_count'],
                'bestRating' => '5'
            ];
        }

        // Offers - prioritize user-configured price
        $offers = [
            '@type' => 'Offer',
            'availability' => 'https://schema.org/InStock',
            'priceCurrency' => $data['site_data']['product_currency'] ?? 'USD'
        ];

        if (!empty($data['site_data']['product_price'])) {
            $offers['price'] = $data['site_data']['product_price'];
        }

        if (!empty($data['url'])) {
            $offers['url'] = $data['url'];
        }

        $schema['offers'] = $offers;

        // Fallback to content extraction if user fields are empty
        if (empty($schema['name']) || empty($schema['description'])) {
            $product_data = $this->extract_product_data($data['content'] ?? '');

            if (empty($schema['name']) && !empty($product_data['name'])) {
                $schema['name'] = $product_data['name'];
            }

            if (empty($schema['sku']) && !empty($product_data['sku'])) {
                $schema['sku'] = $product_data['sku'];
            }

            if (empty($schema['brand']) && !empty($product_data['brand'])) {
                $schema['brand'] = [
                    '@type' => 'Brand',
                    'name' => $product_data['brand']
                ];
            }

            if (empty($offers['price']) && !empty($product_data['price'])) {
                $schema['offers']['price'] = $product_data['price'];
            }
        }

        return $schema;
    }

    /**
     * First argument that is a non-empty string (after trimming).
     *
     * @since 1.17.0
     *
     * @param mixed ...$values Candidate values in priority order.
     * @return string First non-empty candidate, or '' when none qualify.
     */
    private function first_non_empty(...$values): string {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Populate Organization schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_organization_schema(array $schema, array $data, string $context): array {
        // Get business data from Site Identity Business Info (single source of truth)
        $business_data = $this->get_business_data_from_site_identity();

        // Required properties - prioritize Schema Manager organization settings.
        // first_non_empty() rather than ??: a saved-but-empty string is "set"
        // and would otherwise stop the fallback chain dead.
        $schema['name'] = $this->first_non_empty(
            $data['site_data']['organization_name'] ?? null,
            $business_data['business_name'] ?? null,
            $data['title'] ?? null,
            get_bloginfo('name')
        );
        $schema['url'] = $this->first_non_empty(
            $data['site_data']['organization_url'] ?? null,
            $business_data['business_website'] ?? null,
            $data['url'] ?? null,
            home_url()
        );

        // Logo from user configuration or theme customizer
        if (!empty($data['site_data']['organization_logo'])) {
            $schema['logo'] = $this->format_image_schema($data['site_data']['organization_logo']);
        } else {
            // Fallback to WordPress custom logo
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                if ($logo_data) {
                    $schema['logo'] = [
                        '@type' => 'ImageObject',
                        'url' => $logo_data[0],
                        'width' => $logo_data[1],
                        'height' => $logo_data[2]
                    ];
                }
            }
        }

        // Contact point from Business Info or contact configuration
        $contact_point = ['@type' => 'ContactPoint'];
        $has_contact_info = false;

        // Use Business Info phone as primary contact
        if (!empty($business_data['business_phone'])) {
            $contact_point['telephone'] = $business_data['business_phone'];
            $has_contact_info = true;
        }

        // Use Business Info email as primary contact
        if (!empty($business_data['business_email'])) {
            $contact_point['email'] = $business_data['business_email'];
            $has_contact_info = true;
        }

        // Add contact type and hours if available
        if ($has_contact_info) {
            $contact_point['contactType'] = 'customer service';

            // Add contact hours if available from organization settings
            if (!empty($data['site_data']['organization_contact_hours'])) {
                $contact_point['hoursAvailable'] = $data['site_data']['organization_contact_hours'];
            }

            $schema['contactPoint'] = $contact_point;
        }

        // Address from Business Info (single source of truth)
        if (!empty($business_data['business_address'])) {
            $schema['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $business_data['business_address']
            ];

            // Add additional address components if available
            if (!empty($business_data['business_city'])) {
                $schema['address']['addressLocality'] = $business_data['business_city'];
            }
            if (!empty($business_data['business_state'])) {
                $schema['address']['addressRegion'] = $business_data['business_state'];
            }
            if (!empty($business_data['business_postal_code'])) {
                $schema['address']['postalCode'] = $business_data['business_postal_code'];
            }
            if (!empty($business_data['business_country'])) {
                $schema['address']['addressCountry'] = $business_data['business_country'];
            }
        }

        // Social media profiles
        $social_profiles = $this->get_social_profiles();
        if (!empty($social_profiles)) {
            $schema['sameAs'] = $social_profiles;
        }

        // Add description - prioritize Schema Manager organization description
        if (!empty($data['site_data']['organization_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['organization_description'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        return $schema;
    }

    /**
     * Populate Review schema (standalone review of an item).
     *
     * Emits a schema.org Review: the reviewed entity (itemReviewed), a Rating,
     * the reviewing author, and an optional review body. Falls back to the post
     * title for the reviewed item and the post author for the reviewer when the
     * user-configured fields are empty, so an imported review with sparse data
     * still produces valid markup.
     *
     * @since 1.13.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_review_schema(array $schema, array $data, string $context): array {
        $site_data = $data['site_data'] ?? [];

        // itemReviewed — the thing being reviewed; fall back to the content title.
        $item_name = $site_data['review_item_name'] ?? $data['title'] ?? '';
        if (!empty($item_name)) {
            $item_type = $site_data['review_item_type'] ?? 'Thing';
            $schema['itemReviewed'] = [
                '@type' => $item_type,
                'name'  => $item_name,
            ];
        }

        // reviewRating — only emitted when a rating value is present.
        $rating_value = $site_data['review_rating_value'] ?? '';
        if ($rating_value !== '' && $rating_value !== null) {
            $schema['reviewRating'] = [
                '@type'       => 'Rating',
                'ratingValue' => $rating_value,
                'bestRating'  => $site_data['review_best_rating'] ?? '5',
                'worstRating' => $site_data['review_worst_rating'] ?? '1',
            ];
        }

        // author — user-configured reviewer, else the post author.
        $author = $site_data['review_author'] ?? ($data['author']['name'] ?? '');
        if (!empty($author)) {
            $schema['author'] = [
                '@type' => 'Person',
                'name'  => $author,
            ];
        }

        // reviewBody — optional free-text review.
        if (!empty($site_data['review_body'])) {
            $schema['reviewBody'] = $this->truncate_text($site_data['review_body'], 500);
        }

        // datePublished + url from content context.
        if (!empty($data['date'])) {
            $schema['datePublished'] = $data['date'];
        }
        if (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        return $schema;
    }

    /**
     * Populate VideoObject schema.
     *
     * Google requires name, description, thumbnailUrl and uploadDate; contentUrl
     * and/or embedUrl are strongly recommended so the video is playable. Each
     * required field falls back to content context (title, excerpt, featured
     * image, publish date) when the user has not set a video-specific value.
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_video_object_schema(array $schema, array $data, string $context): array {
        $site_data = $data['site_data'] ?? [];

        // name — required; fall back to the content title.
        $schema['name'] = $this->truncate_text(
            $site_data['video_name'] ?? $data['title'] ?? '',
            110
        );

        // description — required; fall back to excerpt then content.
        $description = $site_data['video_description'] ?? '';
        if ($description === '') {
            $description = $data['excerpt'] ?? $data['content'] ?? '';
        }
        if ($description !== '') {
            $schema['description'] = $this->truncate_text($description, 160);
        }

        // thumbnailUrl — required; fall back to the featured/content image.
        $thumbnail = $site_data['video_thumbnail'] ?? '';
        if ($thumbnail === '' && !empty($data['image'])) {
            $thumbnail = is_array($data['image']) ? ($data['image']['url'] ?? '') : $data['image'];
        }
        if ($thumbnail !== '') {
            $schema['thumbnailUrl'] = $thumbnail;
        }

        // uploadDate — required; fall back to the content publish date.
        $upload_date = $site_data['video_upload_date'] ?? '';
        if ($upload_date === '') {
            $upload_date = $data['date'] ?? current_time('c');
        }
        $schema['uploadDate'] = $upload_date;

        // contentUrl / embedUrl — recommended; at least one makes the video playable.
        if (!empty($site_data['video_content_url'])) {
            $schema['contentUrl'] = $site_data['video_content_url'];
        }
        if (!empty($site_data['video_embed_url'])) {
            $schema['embedUrl'] = $site_data['video_embed_url'];
        }

        // duration — optional ISO 8601 (e.g. PT1M33S).
        if (!empty($site_data['video_duration'])) {
            $schema['duration'] = $site_data['video_duration'];
        }

        // url from content context.
        if (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        return $schema;
    }

    /**
     * Truncate text to specified length
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $text   Text to truncate
     * @param int    $length Maximum length
     * @return string Truncated text
     */
    private function truncate_text(string $text, int $length): string {
        $text = wp_strip_all_tags($text);
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Format author schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $author_data Author data
     * @return array Formatted author schema
     */
    private function format_author_schema(array $author_data): array {
        if (empty($author_data['name'])) {
            return [
                '@type' => 'Person',
                'name' => get_bloginfo('name')
            ];
        }

        $author_schema = [
            '@type' => 'Person',
            'name' => $author_data['name']
        ];

        if (!empty($author_data['url'])) {
            $author_schema['url'] = $author_data['url'];
        }

        if (!empty($author_data['description'])) {
            $author_schema['description'] = $this->truncate_text($author_data['description'], 160);
        }

        return $author_schema;
    }

    /**
     * Format image schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $image_url Image URL
     * @return array Formatted image schema
     */
    private function format_image_schema(string $image_url): array {
        $image_schema = [
            '@type' => 'ImageObject',
            'url' => $image_url
        ];

        // Try to get image dimensions if it's a WordPress attachment
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            $image_data = wp_get_attachment_image_src($attachment_id, 'full');
            // SVGs report 0x0 — omit the dimensions rather than emitting
            // zeroes, which invalidate the ImageObject.
            if ($image_data && (int) $image_data[1] > 0 && (int) $image_data[2] > 0) {
                $image_schema['width'] = (int) $image_data[1];
                $image_schema['height'] = (int) $image_data[2];
            }
        }

        return $image_schema;
    }

    /**
     * Get organization schema for publisher
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @return array Organization schema
     */
    private function get_organization_schema(): array {
        $org_schema = [
            '@type' => 'Organization',
            'name' => get_bloginfo('name'),
            'url' => home_url()
        ];

        // Add logo if available
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo_data) {
                $org_schema['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $logo_data[0],
                    'width' => $logo_data[1],
                    'height' => $logo_data[2]
                ];
            }
        }

        return $org_schema;
    }

    /**
     * Get social media profiles
     * Enhanced to retrieve from Schema Manager organization settings
     *
     * @since 1.0.0
     *
     * @return array Social media profile URLs
     */
    private function get_social_profiles(): array {
        // Get Schema Manager settings for organization social profiles
        $schema_manager = new \ThinkRank\SEO\Schema_Management_System();
        $settings = $schema_manager->get_settings('site', null);

        $social_profiles = [];

        // Organization social media fields from Schema Manager
        $social_fields = [
            'organization_social_facebook',
            'organization_social_twitter',
            'organization_social_linkedin',
            'organization_social_instagram',
            'organization_social_youtube',
            'organization_social_pinterest',
            'organization_social_whatsapp',
            'organization_social_telegram'
        ];

        foreach ($social_fields as $field) {
            if (!empty($settings[$field]) && filter_var($settings[$field], FILTER_VALIDATE_URL)) {
                $social_profiles[] = $settings[$field];
            }
        }

        return $social_profiles;
    }

    /**
     * Extract keywords from content
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @return array Extracted keywords
     */
    private function extract_keywords_from_content(string $content): array {
        // Simple keyword extraction - can be enhanced with AI
        $content = wp_strip_all_tags($content);
        $words = str_word_count($content, 1);
        
        // Filter out common words and short words
        $common_words = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'this', 'that', 'these', 'those', 'a', 'an'];
        
        $keywords = [];
        foreach ($words as $word) {
            $word = strtolower(trim($word));
            if (strlen($word) > 3 && !in_array($word, $common_words)) {
                $keywords[] = $word;
            }
        }
        
        // Return top 10 most frequent keywords
        $keyword_counts = array_count_values($keywords);
        arsort($keyword_counts);
        return array_slice(array_keys($keyword_counts), 0, 10);
    }

    /**
     * Extract product data from content
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @return array Extracted product data
     */
    private function extract_product_data(string $content): array {
        $product_data = [];

        // Extract price
        if (preg_match('/\$([0-9,]+\.?[0-9]*)/i', $content, $matches)) {
            $product_data['price'] = str_replace(',', '', $matches[1]);
        }

        // Extract SKU
        if (preg_match('/sku[:\s]+([a-z0-9\-]+)/i', $content, $matches)) {
            $product_data['sku'] = $matches[1];
        }

        // Extract brand (simple pattern)
        if (preg_match('/brand[:\s]+([a-z\s]+)/i', $content, $matches)) {
            $product_data['brand'] = trim($matches[1]);
        }

        return $product_data;
    }

    /**
     * Clean schema array by removing empty values
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema Schema array to clean
     * @return array Cleaned schema array
     */
    private function clean_schema_array(array $schema): array {
        // Remove internal validation metadata (should not be in final output)
        if (isset($schema['_validation'])) {
            unset($schema['_validation']);
        }
        if (isset($schema['_error'])) {
            unset($schema['_error']);
        }

        // Remove empty values recursively, but preserve critical schema fields
        $critical_fields = ['@context', '@type', '@id'];

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->clean_schema_array($value);
                if (empty($schema[$key])) {
                    unset($schema[$key]);
                }
            } elseif (empty($value) && $value !== 0 && $value !== '0' && !in_array($key, $critical_fields)) {
                unset($schema[$key]);
            }
        }

        return $schema;
    }

    /**
     * Populate Website schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_website_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured Website schema fields
        $schema['name'] = $data['site_data']['website_name'] ?? $data['title'] ?? get_bloginfo('name');
        $schema['url'] = $data['site_data']['website_url'] ?? $data['url'] ?? home_url();

        // Recommended properties - prioritize user-configured Website schema description
        if (!empty($data['site_data']['website_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['website_description'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        } else {
            $schema['description'] = get_bloginfo('description');
        }

        // Author - use organization or person data
        if (!empty($data['site_data']['organization_name'])) {
            $schema['author'] = [
                '@type' => 'Organization',
                'name' => $data['site_data']['organization_name']
            ];
        } elseif (!empty($data['site_data']['person_name'])) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $data['site_data']['person_name']
            ];
        } else {
            // Fallback to site name as organization
            $schema['author'] = [
                '@type' => 'Organization',
                'name' => get_bloginfo('name')
            ];
        }

        // Publisher - enhanced with logo from Site Identity
        $publisher = [
            '@type' => 'Organization',
            'name' => $this->first_non_empty(
                $data['site_data']['organization_name'] ?? null,
                get_bloginfo('name')
            ),
            'url' => $this->first_non_empty(
                $data['site_data']['organization_url'] ?? null,
                home_url()
            )
        ];

        // Add logo to publisher from Site Identity or user configuration
        if (!empty($data['site_data']['logo_url'])) {
            $publisher['logo'] = $this->format_image_schema($data['site_data']['logo_url']);
        } elseif (!empty($data['site_data']['organization_logo'])) {
            $publisher['logo'] = $this->format_image_schema($data['site_data']['organization_logo']);
        } else {
            // Fallback to WordPress custom logo
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                if ($logo_data) {
                    $publisher['logo'] = [
                        '@type' => 'ImageObject',
                        'url' => $logo_data[0],
                        'width' => $logo_data[1],
                        'height' => $logo_data[2]
                    ];
                }
            }
        }

        $schema['publisher'] = $publisher;

        // Search action for sitelinks search box (optional but recommended)
        if ($data['site_data']['website_enable_search'] ?? true) {
            $search_url = $data['site_data']['website_search_url'] ?? home_url('/?s={search_term_string}');
            $schema['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $search_url
                ],
                'query-input' => 'required name=search_term_string'
            ];
        }

        // Social media profiles (sameAs)
        $social_profiles = $this->get_social_profiles();
        if (!empty($social_profiles)) {
            $schema['sameAs'] = $social_profiles;
        }

        // Language
        $schema['inLanguage'] = get_locale();

        return $schema;
    }

    /**
     * Populate WebPage schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_webpage_schema(array $schema, array $data, string $context): array {
        // Required properties
        $schema['name'] = $data['title'] ?? '';
        $schema['url'] = $data['url'] ?? '';

        // Optional properties
        if (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        if (!empty($data['date'])) {
            $schema['datePublished'] = $data['date'];
        }

        if (!empty($data['modified'])) {
            $schema['dateModified'] = $data['modified'];
        }

        $schema['isPartOf'] = [
            '@type' => 'WebSite',
            'name' => get_bloginfo('name'),
            'url' => home_url()
        ];

        return $schema;
    }

    /**
     * Populate FAQ schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_faq_schema(array $schema, array $data, string $context): array {
        // Use user-configured FAQ questions first, then fallback to content extraction
        $faq_data = [];

        // Check for user-configured FAQ questions
        if (!empty($data['site_data']['faq_questions']) && is_array($data['site_data']['faq_questions'])) {
            foreach ($data['site_data']['faq_questions'] as $faq_item) {
                if (!empty($faq_item['question']) && !empty($faq_item['answer'])) {
                    $faq_data[] = [
                        '@type' => 'Question',
                        'name' => $faq_item['question'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => $faq_item['answer']
                        ]
                    ];
                }
            }
        }

        // Fallback to content extraction if no user-configured questions
        if (empty($faq_data) && !empty($data['content'])) {
            $extracted_faq = $this->extract_faq_data($data['content']);
            foreach ($extracted_faq as $faq_item) {
                $faq_data[] = [
                    '@type' => 'Question',
                    'name' => $faq_item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq_item['answer']
                    ]
                ];
            }
        }

        $schema['mainEntity'] = $faq_data;

        // Optional properties
        $schema['name'] = $data['title'] ?? 'Frequently Asked Questions';
        if (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        }

        // URL for the FAQ page
        if (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        // About - recommended property
        if (!empty($data['site_data']['faq_page_description'])) {
            $schema['about'] = $data['site_data']['faq_page_description'];
        } elseif (!empty($data['excerpt'])) {
            $schema['about'] = $this->truncate_text($data['excerpt'], 160);
        }

        // Author - recommended property
        if (!empty($data['site_data']['organization_name'])) {
            $schema['author'] = [
                '@type' => 'Organization',
                'name' => $data['site_data']['organization_name']
            ];
        } elseif (!empty($data['site_data']['person_name'])) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $data['site_data']['person_name']
            ];
        } elseif (!empty($data['author']['name'])) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $data['author']['name']
            ];
        }

        return $schema;
    }

    /**
     * Populate LocalBusiness schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_local_business_schema(array $schema, array $data, string $context): array {
        // Get business data from Site Identity Business Info (single source of truth)
        $business_data = $this->get_business_data_from_site_identity();

        // Required properties - use business name from Business Info.
        // `name` is required for LocalBusiness, so an empty saved value must fall
        // through to the next source rather than emit "".
        $schema['name'] = $this->first_non_empty(
            $business_data['business_name'] ?? null,
            $data['site_data']['organization_name'] ?? null,
            $data['title'] ?? null,
            get_bloginfo('name')
        );

        // Address is required for LocalBusiness - use Business Info data
        if (!empty($business_data['business_address'])) {
            $schema['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $business_data['business_address']
            ];

            // Add additional address components if available
            if (!empty($business_data['business_city'])) {
                $schema['address']['addressLocality'] = $business_data['business_city'];
            }
            if (!empty($business_data['business_state'])) {
                $schema['address']['addressRegion'] = $business_data['business_state'];
            }
            if (!empty($business_data['business_postal_code'])) {
                $schema['address']['postalCode'] = $business_data['business_postal_code'];
            }
            if (!empty($business_data['business_country'])) {
                $schema['address']['addressCountry'] = $business_data['business_country'];
            }
        } else {
            // Fallback to content extraction only if no Business Info data
            $extracted_data = $this->extract_business_data($data['content'] ?? '');
            if (!empty($extracted_data['address'])) {
                $schema['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $extracted_data['address']
                ];
            }
        }

        // Telephone - use Business Info phone
        if (!empty($business_data['business_phone'])) {
            $schema['telephone'] = $business_data['business_phone'];
        } else {
            // Fallback to content extraction
            $extracted_data = $this->extract_business_data($data['content'] ?? '');
            if (!empty($extracted_data['phone'])) {
                $schema['telephone'] = $extracted_data['phone'];
            }
        }

        // Opening hours from Business Info
        if (!empty($business_data['business_hours']) && is_array($business_data['business_hours'])) {
            $schema['openingHours'] = $this->format_business_hours($business_data['business_hours']);
        } else {
            // Fallback to content extraction
            $extracted_data = $this->extract_business_data($data['content'] ?? '');
            if (!empty($extracted_data['hours'])) {
                $schema['openingHours'] = $extracted_data['hours'];
            }
        }

        // Geo coordinates from Business Info
        if (!empty($business_data['business_latitude']) && !empty($business_data['business_longitude'])) {
            $schema['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $business_data['business_latitude'],
                'longitude' => $business_data['business_longitude']
            ];
        }

        // Add URL - use business website or site URL
        $schema['url'] = $business_data['business_website'] ?? home_url();

        // Add description - use business description or site description
        if (!empty($business_data['business_description'])) {
            $schema['description'] = $this->truncate_text($business_data['business_description'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        // Price range from Business Info
        if (!empty($business_data['business_price_range'])) {
            $schema['priceRange'] = $business_data['business_price_range'];
        }

        // Price range - recommended property
        if (!empty($data['site_data']['business_price_range'])) {
            $schema['priceRange'] = $data['site_data']['business_price_range'];
        }

        // Logo from Site Identity assets or WordPress custom logo
        if (!empty($data['site_data']['logo_url'])) {
            $schema['logo'] = $this->format_image_schema($data['site_data']['logo_url']);
        } else {
            // Fallback to WordPress custom logo
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                if ($logo_data) {
                    $schema['logo'] = [
                        '@type' => 'ImageObject',
                        'url' => $logo_data[0],
                        'width' => $logo_data[1],
                        'height' => $logo_data[2]
                    ];
                }
            }
        }

        // Social media profiles from Organization settings (sameAs property)
        $social_profiles = [];
        $social_fields = [
            'organization_social_facebook',
            'organization_social_twitter',
            'organization_social_linkedin',
            'organization_social_instagram',
            'organization_social_youtube',
            'organization_social_pinterest',
            'organization_social_whatsapp',
            'organization_social_telegram'
        ];

        foreach ($social_fields as $field) {
            if (!empty($data['site_data'][$field])) {
                $social_profiles[] = $data['site_data'][$field];
            }
        }

        if (!empty($social_profiles)) {
            $schema['sameAs'] = $social_profiles;
        }

        return $schema;
    }

    /**
     * Get business data from Site Identity Business Info (single source of truth)
     *
     * @since 1.0.0
     *
     * @return array Business data array
     */
    private function get_business_data_from_site_identity(): array {
        // Get Site Identity Manager
        if (!class_exists('ThinkRank\\SEO\\Site_Identity_Manager')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-site-identity-manager.php';
        }

        $site_identity_manager = new \ThinkRank\SEO\Site_Identity_Manager();
        $settings = $site_identity_manager->get_settings('site');

        // Only return data if local SEO is enabled
        if (empty($settings['local_seo_enabled'])) {
            return [];
        }

        return [
            'business_name' => $settings['business_name'] ?? '',
            'business_address' => $settings['business_address'] ?? '',
            'business_city' => $settings['business_city'] ?? '',
            'business_state' => $settings['business_state'] ?? '',
            'business_postal_code' => $settings['business_postal_code'] ?? '',
            'business_country' => $settings['business_country'] ?? '',
            'business_phone' => $settings['business_phone'] ?? '',
            'business_email' => $settings['business_email'] ?? '',
            'business_hours' => $settings['business_hours'] ?? [],
            'business_website' => $settings['business_website'] ?? home_url(),
            'business_latitude' => $settings['business_latitude'] ?? '',
            'business_longitude' => $settings['business_longitude'] ?? '',
            'business_description' => $settings['business_description'] ?? $settings['site_description'] ?? '',
            'business_type' => $settings['business_type'] ?? 'LocalBusiness',
            'business_price_range' => $settings['business_price_range'] ?? ''
        ];
    }

    /**
     * Populate Person schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_person_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured fields
        $schema['name'] = $data['site_data']['person_name'] ?? $data['author']['name'] ?? $data['title'] ?? '';

        // Image from user configuration or fallback
        if (!empty($data['site_data']['person_image'])) {
            $schema['image'] = $this->format_image_schema($data['site_data']['person_image']);
        } elseif (!empty($data['image'])) {
            $schema['image'] = $this->format_image_schema($data['image']);
        }

        // URL from user configuration or fallback
        if (!empty($data['site_data']['person_url'])) {
            $schema['url'] = $data['site_data']['person_url'];
        } elseif (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        // Job title from user configuration
        if (!empty($data['site_data']['person_job_title'])) {
            $schema['jobTitle'] = $data['site_data']['person_job_title'];
        }

        // Works for organization - Fixed field name from person_organization to person_works_for
        if (!empty($data['site_data']['person_works_for'])) {
            $schema['worksFor'] = [
                '@type' => 'Organization',
                'name' => $data['site_data']['person_works_for']
            ];
        }

        // Description from user configuration or fallback
        if (!empty($data['site_data']['person_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['person_description'], 160);
        } elseif (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        // Email from user configuration
        if (!empty($data['site_data']['person_email'])) {
            $schema['email'] = $data['site_data']['person_email'];
        }

        // Telephone from user configuration
        if (!empty($data['site_data']['person_telephone'])) {
            $schema['telephone'] = $data['site_data']['person_telephone'];
        }

        // Nationality from user configuration
        if (!empty($data['site_data']['person_nationality'])) {
            $schema['nationality'] = $data['site_data']['person_nationality'];
        }

        // Birth date from user configuration
        if (!empty($data['site_data']['person_birth_date'])) {
            $schema['birthDate'] = $data['site_data']['person_birth_date'];
        }

        // Address from user configuration
        if (!empty($data['site_data']['person_address'])) {
            $schema['address'] = $data['site_data']['person_address'];
        }

        // Social media profiles - Enhanced to include user-configured sameAs
        $social_profiles = [];

        // Get user-configured social profiles first
        if (!empty($data['site_data']['person_same_as']) && is_array($data['site_data']['person_same_as'])) {
            $social_profiles = array_merge($social_profiles, $data['site_data']['person_same_as']);
        }

        // Add global social profiles as fallback
        $global_social_profiles = $this->get_social_profiles();
        if (!empty($global_social_profiles)) {
            $social_profiles = array_merge($social_profiles, $global_social_profiles);
        }

        // Remove duplicates and empty values
        $social_profiles = array_unique(array_filter($social_profiles));

        if (!empty($social_profiles)) {
            $schema['sameAs'] = $social_profiles;
        }

        return $schema;
    }

    /**
     * Populate generic schema for unsupported types
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_generic_schema(array $schema, array $data, string $context): array {
        // Basic properties that apply to most schema types
        if (!empty($data['title'])) {
            $schema['name'] = $data['title'];
        }
        if (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        }
        if (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        return $schema;
    }

    /**
     * Extract FAQ data from content
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @return array Extracted FAQ data
     */
    private function extract_faq_data(string $content): array {
        $faq_data = [];

        // Look for question/answer patterns
        $patterns = [
            '/Q:\s*(.+?)\s*A:\s*(.+?)(?=Q:|$)/s',
            '/\?\s*(.+?)\n(.+?)(?=\?|$)/s',
            '/<h[1-6][^>]*>\s*(.+?)\s*<\/h[1-6]>\s*<p>\s*(.+?)\s*<\/p>/s'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (count($match) >= 3) {
                        $question = trim(wp_strip_all_tags($match[1]));
                        $answer = trim(wp_strip_all_tags($match[2]));

                        if (!empty($question) && !empty($answer)) {
                            $faq_data[] = [
                                'question' => $question,
                                'answer' => $answer
                            ];
                        }
                    }
                }
                break; // Use first matching pattern
            }
        }

        return $faq_data;
    }

    /**
     * Extract business data from content
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $content Content to analyze
     * @return array Extracted business data
     */
    private function extract_business_data(string $content): array {
        $business_data = [];

        // Extract phone number
        if (preg_match('/(\+?1?[-.\s]?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4})/', $content, $matches)) {
            $business_data['phone'] = $matches[1];
        }

        // Extract address (simple pattern)
        if (preg_match('/([0-9]+\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln|Way|Court|Ct))/i', $content, $matches)) {
            $business_data['address'] = $matches[1];
        }

        // Extract hours (simple pattern)
        if (preg_match('/(Monday|Mon).*?([0-9]{1,2}:[0-9]{2}\s*(?:AM|PM|am|pm))/i', $content, $matches)) {
            $business_data['hours'] = ['Monday 9:00 AM - 5:00 PM']; // Simplified
        }

        return $business_data;
    }

    /**
     * Format business hours
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $business_hours Business hours array
     * @return array Formatted opening hours
     */
    private function format_business_hours(array $business_hours): array {
        $opening_hours = [];

        $day_mapping = [
            'monday' => 'Mo',
            'tuesday' => 'Tu',
            'wednesday' => 'We',
            'thursday' => 'Th',
            'friday' => 'Fr',
            'saturday' => 'Sa',
            'sunday' => 'Su'
        ];

        foreach ($business_hours as $day => $hours) {
            // $day may be an int key when business_hours is a numerically-indexed
            // list (e.g. from an import/API); cast before strtolower() so it does
            // not throw a TypeError under strict_types.
            $day_code = $day_mapping[strtolower((string) $day)] ?? $day;
            if (!empty($hours['open']) && !empty($hours['close'])) {
                $opening_hours[] = "{$day_code} {$hours['open']}-{$hours['close']}";
            }
        }

        return $opening_hours;
    }

    /**
     * Populate SoftwareApplication schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_software_application_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured fields
        $schema['name'] = $data['site_data']['software_name'] ?? $data['title'] ?? get_bloginfo('name');
        $schema['applicationCategory'] = $data['site_data']['software_category'] ?? 'WebApplication';

        // Recommended properties
        if (!empty($data['site_data']['software_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['software_description'], 160);
        } elseif (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        // URL from user configuration or fallback
        if (!empty($data['site_data']['software_url'])) {
            $schema['url'] = $data['site_data']['software_url'];
        } elseif (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        } else {
            $schema['url'] = home_url();
        }

        // Creator/Developer - use form data if available
        if (!empty($data['site_data']['software_creator'])) {
            // Default to 'Person' if creator_type is not specified (since form defaults to Person)
            $creator_type = $data['site_data']['software_creator_type'] ?? 'Person';

            $schema['creator'] = [
                '@type' => $creator_type,
                'name' => $data['site_data']['software_creator']
            ];

            // Add URL for Organization type
            if ($creator_type === 'Organization') {
                $schema['creator']['url'] = home_url();
            }
        } else {
            // Fallback to site data
            $schema['creator'] = [
                '@type' => 'Organization',
                'name' => $this->first_non_empty(
                    $data['site_data']['organization_name'] ?? null,
                    get_bloginfo('name')
                ),
                'url' => home_url()
            ];
        }

        // Features from user configuration
        if (!empty($data['site_data']['software_features']) && is_array($data['site_data']['software_features'])) {
            $schema['features'] = $data['site_data']['software_features'];
        }

        // Aggregate Rating from user configuration - check both field name formats
        if (!empty($data['site_data']['software_aggregate_rating']) && !empty($data['site_data']['software_review_count'])) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $data['site_data']['software_aggregate_rating'],
                'reviewCount' => $data['site_data']['software_review_count'],
                'bestRating' => '5'
            ];
        } elseif (!empty($data['site_data']['software_rating_value']) && !empty($data['site_data']['software_rating_count'])) {
            // Use form fields: software_rating_value and software_rating_count
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $data['site_data']['software_rating_value'],
                'reviewCount' => $data['site_data']['software_rating_count'],
                'bestRating' => '5'
            ];
        }

        // Offers/Pricing
        if (!empty($data['site_data']['software_price'])) {
            // Map availability from form data
            $availability_mapping = [
                'InStock' => 'https://schema.org/InStock',
                'OutOfStock' => 'https://schema.org/OutOfStock',
                'PreOrder' => 'https://schema.org/PreOrder',
                'ComingSoon' => 'https://schema.org/ComingSoon'
            ];

            $availability = $data['site_data']['software_availability'] ?? 'InStock';
            $schema_availability = $availability_mapping[$availability] ?? 'https://schema.org/InStock';

            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => $data['site_data']['software_price'],
                'priceCurrency' => $data['site_data']['software_currency'] ?? 'USD',
                'availability' => $schema_availability
            ];

            // Add price valid until if provided
            if (!empty($data['site_data']['software_price_valid_until'])) {
                $schema['offers']['priceValidUntil'] = $data['site_data']['software_price_valid_until'];
            }
        }

        // Optional properties
        if (!empty($data['site_data']['software_version'])) {
            $schema['version'] = $data['site_data']['software_version'];
        }

        // Operating Systems - check both singular and plural forms
        if (!empty($data['site_data']['software_operating_systems'])) {
            $schema['operatingSystem'] = $data['site_data']['software_operating_systems'];
        } elseif (!empty($data['site_data']['software_operating_system'])) {
            $schema['operatingSystem'] = $data['site_data']['software_operating_system'];
        }

        if (!empty($data['site_data']['software_download_url'])) {
            $schema['downloadUrl'] = $data['site_data']['software_download_url'];
        }

        // Image/Screenshot
        if (!empty($data['site_data']['software_screenshot'])) {
            $schema['screenshot'] = $this->format_image_schema($data['site_data']['software_screenshot']);
        } elseif (!empty($data['image'])) {
            $schema['screenshot'] = $this->format_image_schema($data['image']);
        }

        return $schema;
    }

    /**
     * Populate Event schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_event_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured fields
        $schema['name'] = $data['site_data']['event_name'] ?? $data['title'] ?? '';

        // Start date is required
        if (!empty($data['site_data']['event_start_date'])) {
            $schema['startDate'] = $data['site_data']['event_start_date'];
        } else {
            // Fallback to current date if not specified
            $schema['startDate'] = current_time('c');
        }

        // Recommended properties
        if (!empty($data['site_data']['event_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['event_description'], 160);
        } elseif (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        // Location
        if (!empty($data['site_data']['event_location'])) {
            $schema['location'] = [
                '@type' => 'Place',
                'name' => $data['site_data']['event_location']
            ];

            // Add address if available
            if (!empty($data['site_data']['event_address'])) {
                $schema['location']['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $data['site_data']['event_address']
                ];
            }
        }

        // Organizer
        if (!empty($data['site_data']['event_organizer'])) {
            $schema['organizer'] = [
                '@type' => 'Organization',
                'name' => $data['site_data']['event_organizer']
            ];
        } else {
            $schema['organizer'] = [
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
                'url' => home_url()
            ];
        }

        // End date
        if (!empty($data['site_data']['event_end_date'])) {
            $schema['endDate'] = $data['site_data']['event_end_date'];
        }

        // Optional properties
        if (!empty($data['site_data']['event_status'])) {
            $schema['eventStatus'] = 'https://schema.org/' . $data['site_data']['event_status'];
        }

        if (!empty($data['site_data']['event_attendance_mode'])) {
            $schema['eventAttendanceMode'] = 'https://schema.org/' . $data['site_data']['event_attendance_mode'];
        }

        // Offers/Tickets
        if (!empty($data['site_data']['event_price'])) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => $data['site_data']['event_price'],
                'priceCurrency' => $data['site_data']['event_currency'] ?? 'USD',
                'availability' => 'https://schema.org/InStock'
            ];
        }

        // Image
        if (!empty($data['site_data']['event_image'])) {
            $schema['image'] = $this->format_image_schema($data['site_data']['event_image']);
        } elseif (!empty($data['image'])) {
            $schema['image'] = $this->format_image_schema($data['image']);
        }

        // Performer - recommended property
        if (!empty($data['site_data']['event_performer'])) {
            $schema['performer'] = [
                '@type' => 'Person',
                'name' => $data['site_data']['event_performer']
            ];
        }

        // URL
        if (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        return $schema;
    }

    /**
     * Populate HowTo schema
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema  Base schema
     * @param array  $data    Content data
     * @param string $context Context type
     * @return array Populated schema
     */
    private function populate_howto_schema(array $schema, array $data, string $context): array {
        // Required properties - prioritize user-configured fields
        $schema['name'] = $data['site_data']['howto_name'] ?? $data['title'] ?? '';

        // Recommended properties
        if (!empty($data['site_data']['howto_description'])) {
            $schema['description'] = $this->truncate_text($data['site_data']['howto_description'], 160);
        } elseif (!empty($data['excerpt'])) {
            $schema['description'] = $this->truncate_text($data['excerpt'], 160);
        } elseif (!empty($data['content'])) {
            $schema['description'] = $this->truncate_text($data['content'], 160);
        }

        // Total time
        if (!empty($data['site_data']['howto_total_time'])) {
            $schema['totalTime'] = $data['site_data']['howto_total_time'];
        }

        // Optional properties
        if (!empty($data['site_data']['howto_prep_time'])) {
            $schema['prepTime'] = $data['site_data']['howto_prep_time'];
        }

        if (!empty($data['site_data']['howto_difficulty'])) {
            $schema['difficulty'] = $data['site_data']['howto_difficulty'];
        }

        if (!empty($data['site_data']['howto_estimated_cost'])) {
            $schema['estimatedCost'] = [
                '@type' => 'MonetaryAmount',
                'currency' => $data['site_data']['howto_currency'] ?? 'USD',
                'value' => $data['site_data']['howto_estimated_cost']
            ];
        }

        // Supply/Materials
        if (!empty($data['site_data']['howto_supply']) && is_array($data['site_data']['howto_supply'])) {
            $schema['supply'] = [];
            foreach ($data['site_data']['howto_supply'] as $supply_item) {
                $schema['supply'][] = [
                    '@type' => 'HowToSupply',
                    'name' => $supply_item
                ];
            }
        }

        // Tools
        if (!empty($data['site_data']['howto_tool']) && is_array($data['site_data']['howto_tool'])) {
            $schema['tool'] = [];
            foreach ($data['site_data']['howto_tool'] as $tool_item) {
                $schema['tool'][] = [
                    '@type' => 'HowToTool',
                    'name' => $tool_item
                ];
            }
        }

        // Steps - handle both string and array formats
        if (!empty($data['site_data']['howto_steps'])) {
            $schema['step'] = [];

            if (is_array($data['site_data']['howto_steps'])) {
                // Handle array format (structured steps)
                foreach ($data['site_data']['howto_steps'] as $index => $step) {
                    $step_schema = [
                        '@type' => 'HowToStep',
                        'name' => $step['name'] ?? "Step " . ($index + 1),
                        'text' => $step['text'] ?? ''
                    ];

                    if (!empty($step['image'])) {
                        $step_schema['image'] = $this->format_image_schema($step['image']);
                    }

                    $schema['step'][] = $step_schema;
                }
            } elseif (is_string($data['site_data']['howto_steps'])) {
                // Handle string format (textarea with line breaks)
                $steps_text = trim($data['site_data']['howto_steps']);
                if (!empty($steps_text)) {
                    $step_lines = explode("\n", $steps_text);
                    foreach ($step_lines as $index => $step_line) {
                        $step_line = trim($step_line);
                        if (!empty($step_line)) {
                            // Remove numbering if present (e.g., "1. Step text" -> "Step text")
                            $step_text = preg_replace('/^\d+\.\s*/', '', $step_line);

                            $schema['step'][] = [
                                '@type' => 'HowToStep',
                                'name' => "Step " . ($index + 1),
                                'text' => $step_text
                            ];
                        }
                    }
                }
            }
        }

        // Yield/Output
        if (!empty($data['site_data']['howto_yield'])) {
            $schema['yield'] = $data['site_data']['howto_yield'];
        }

        // Image
        if (!empty($data['site_data']['howto_image'])) {
            $schema['image'] = $this->format_image_schema($data['site_data']['howto_image']);
        } elseif (!empty($data['image'])) {
            $schema['image'] = $this->format_image_schema($data['image']);
        }

        // URL from user configuration or fallback
        if (!empty($data['site_data']['howto_url'])) {
            $schema['url'] = $data['site_data']['howto_url'];
        } elseif (!empty($data['url'])) {
            $schema['url'] = $data['url'];
        }

        // Video
        if (!empty($data['site_data']['howto_video'])) {
            $schema['video'] = [
                '@type' => 'VideoObject',
                'contentUrl' => $data['site_data']['howto_video']
            ];
        }

        return $schema;
    }
}
