<?php
/**
 * Schema Factory Class
 *
 * Handles schema type instantiation, configuration, and registry management.
 * Extracted from Schema_Generator to follow Single Responsibility Principle.
 * Maintains exact same functionality and data structures as original implementation.
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
 * Schema Factory Class
 *
 * Manages schema type registry, instantiation, and configuration.
 * Preserves all existing schema type definitions and specifications.
 *
 * @since 1.0.0
 */
class Schema_Factory {

    /**
     * Supported schema types with their Schema.org specifications
     * PRESERVED: Exact same data structure from original Schema_Generator
     *
     * @since 1.0.0
     * @var array
     */
    private array $supported_schema_types = [
        'Article' => [
            'required' => ['@type', 'headline', 'author', 'datePublished'],
            'recommended' => ['description', 'image', 'dateModified', 'publisher', 'mainEntityOfPage'],
            'optional' => ['wordCount', 'keywords', 'articleSection', 'articleBody']
        ],
        'BlogPosting' => [
            'required' => ['@type', 'headline', 'author', 'datePublished'],
            'recommended' => ['description', 'image', 'dateModified', 'publisher', 'mainEntityOfPage'],
            'optional' => ['wordCount', 'keywords', 'blogPost', 'comment']
        ],
        'TechnicalArticle' => [
            'required' => ['@type', 'headline', 'author', 'datePublished'],
            'recommended' => ['description', 'image', 'dateModified', 'publisher', 'mainEntityOfPage'],
            'optional' => ['wordCount', 'keywords', 'articleSection', 'articleBody', 'dependencies', 'proficiencyLevel']
        ],
        'NewsArticle' => [
            'required' => ['@type', 'headline', 'author', 'datePublished'],
            'recommended' => ['description', 'image', 'dateModified', 'publisher', 'mainEntityOfPage'],
            'optional' => ['wordCount', 'keywords', 'articleSection', 'articleBody', 'dateline', 'printColumn', 'printEdition', 'printPage', 'printSection']
        ],
        'ScholarlyArticle' => [
            'required' => ['@type', 'headline', 'author', 'datePublished'],
            'recommended' => ['description', 'image', 'dateModified', 'publisher', 'mainEntityOfPage'],
            'optional' => ['wordCount', 'keywords', 'articleSection', 'articleBody', 'citation', 'pageStart', 'pageEnd', 'pagination']
        ],
        'Report' => [
            'required' => ['@type', 'headline', 'author', 'datePublished'],
            'recommended' => ['description', 'image', 'dateModified', 'publisher', 'mainEntityOfPage'],
            'optional' => ['wordCount', 'keywords', 'articleSection', 'articleBody', 'reportNumber']
        ],
        'Product' => [
            'required' => ['@type', 'name', 'description'],
            'recommended' => ['image', 'offers', 'brand', 'sku', 'gtin', 'review', 'aggregateRating'],
            'optional' => ['category', 'manufacturer', 'mpn', 'productID']
        ],
        'Organization' => [
            'required' => ['@type', 'name', 'url'],
            'recommended' => ['logo', 'contactPoint', 'sameAs', 'address'],
            'optional' => ['founder', 'foundingDate', 'description']
        ],
        'WebSite' => [
            'required' => ['@type', 'name', 'url'],
            'recommended' => ['description', 'author', 'publisher', 'potentialAction'],
            'optional' => ['sameAs', 'inLanguage']
        ],
        'FAQPage' => [
            'required' => ['@type', 'mainEntity'],
            'recommended' => ['about', 'author'],
            'optional' => ['name', 'description', 'url', 'dateModified']
        ],
        'LocalBusiness' => [
            'required' => ['@type', 'name', 'address'],
            'recommended' => ['telephone', 'url', 'openingHours', 'geo', 'priceRange'],
            'optional' => ['description', 'logo', 'contactPoint', 'sameAs', 'paymentAccepted', 'currenciesAccepted']
        ],
        'Person' => [
            'required' => ['@type', 'name'],
            'recommended' => ['url', 'image'],
            'optional' => ['jobTitle', 'worksFor', 'sameAs', 'description']
        ],
        'SoftwareApplication' => [
            'required' => ['@type', 'name', 'applicationCategory'],
            'recommended' => ['description', 'url', 'offers', 'creator', 'features', 'aggregateRating'],
            'optional' => ['alternateName', 'version', 'operatingSystem', 'fileSize', 'license', 'downloadUrl', 'screenshot']
        ],
        'Event' => [
            'required' => ['@type', 'name', 'startDate', 'location'],
            'recommended' => ['endDate', 'description', 'image', 'offers', 'performer'],
            'optional' => ['eventStatus', 'eventAttendanceMode', 'url', 'organizer']
        ],
        'Review' => [
            'required' => ['@type', 'itemReviewed', 'reviewRating', 'author'],
            'recommended' => ['reviewBody', 'datePublished', 'publisher'],
            'optional' => ['name', 'url']
        ],
        'HowTo' => [
            'required' => ['@type', 'name', 'step'],
            'recommended' => ['image', 'totalTime', 'estimatedCost', 'tool', 'supply'],
            'optional' => ['description', 'prepTime', 'difficulty', 'yield', 'video']
        ],
        'VideoObject' => [
            'required' => ['@type', 'name', 'description', 'thumbnailUrl', 'uploadDate'],
            'recommended' => ['contentUrl', 'embedUrl', 'duration'],
            'optional' => ['interactionStatistic', 'expires', 'regionsAllowed']
        ]
    ];

    /**
     * Schema.org context URL
     * PRESERVED: Exact same value from original Schema_Generator
     *
     * @since 1.0.0
     * @var string
     */
    private string $schema_context = 'https://schema.org';

    /**
     * Get all supported schema types
     * Simplified: Context filtering moved to config files
     *
     * @since 1.0.0
     *
     * @return array Array of all supported schema types
     */
    public function get_all_supported_types(): array {
        return array_keys($this->supported_schema_types);
    }

    /**
     * Check if schema type is supported
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type to check
     * @return bool True if supported, false otherwise
     */
    public function is_supported_schema_type(string $schema_type): bool {
        return isset($this->supported_schema_types[$schema_type]);
    }

    /**
     * Get schema type specification
     * PRESERVED: Returns exact same data structure as original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type
     * @return array Schema type specification
     */
    public function get_schema_specification(string $schema_type): array {
        return $this->supported_schema_types[$schema_type] ?? [];
    }

    /**
     * Normalize schema type name to proper Schema.org format
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type to normalize
     * @return string Normalized schema type
     */
    public function normalize_schema_type(string $schema_type): string {
        // Handle Schema.org camelCase requirements
        $schema_cases = [
            'localbusiness' => 'LocalBusiness',
            'softwareapplication' => 'SoftwareApplication',
            'blogposting' => 'BlogPosting',
            'technicalarticle' => 'TechnicalArticle',
            'newsarticle' => 'NewsArticle',
            'scholarlyarticle' => 'ScholarlyArticle',
            'faqpage' => 'FAQPage',
            'howto' => 'HowTo',
            'videoobject' => 'VideoObject'
        ];

        $key = strtolower($schema_type);
        return $schema_cases[$key] ?? ucfirst($schema_type);
    }

    /**
     * Create base schema structure
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param string $schema_type Schema type
     * @return array Base schema structure
     */
    public function create_base_schema(string $schema_type): array {
        return [
            '@context' => $this->schema_context,
            '@type' => $schema_type
        ];
    }

    // Removed error schema creation - moved to Schema_Builder where it belongs

    // Removed auto-detection logic - over-engineered and rarely used
    // Schema types are now explicitly selected by users

    /**
     * Get schema context URL
     * PRESERVED: Returns exact same value as original Schema_Generator
     *
     * @since 1.0.0
     *
     * @return string Schema context URL
     */
    public function get_schema_context(): string {
        return $this->schema_context;
    }

    /**
     * Get all supported schema types
     * PRESERVED: Returns exact same data structure as original Schema_Generator
     *
     * @since 1.0.0
     *
     * @return array All supported schema types with specifications
     */
    public function get_all_schema_types(): array {
        return $this->supported_schema_types;
    }
}
