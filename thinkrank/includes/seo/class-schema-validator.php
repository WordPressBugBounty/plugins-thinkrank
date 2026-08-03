<?php
/**
 * Schema Validator Class
 *
 * Handles Schema.org compliance validation and SEO optimization checks.
 * Extracted from Schema_Generator to follow Single Responsibility Principle.
 * Maintains exact same validation logic and return formats as original implementation.
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
 * Schema Validator Class
 *
 * Validates schema markup against Schema.org specifications and provides SEO suggestions.
 * Preserves all existing validation logic and return formats.
 *
 * @since 1.0.0
 */
class Schema_Validator {

    /**
     * Schema Factory instance for type specifications
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
     * Validate schema markup against Schema.org specifications
     * PRESERVED: Exact same method signature and return format from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema Schema markup to validate
     * @return array Validation results with errors, warnings, and suggestions
     */
    public function validate_schema(array $schema): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Check basic structure
        if (!isset($schema['@context']) || $schema['@context'] !== $this->schema_factory->get_schema_context()) {
            $validation['errors'][] = 'Missing or invalid @context. Should be: ' . $this->schema_factory->get_schema_context();
            $validation['valid'] = false;
        }

        if (!isset($schema['@type'])) {
            $validation['errors'][] = 'Missing @type property';
            $validation['valid'] = false;
            return $validation;
        }

        $schema_type = $schema['@type'];
        $spec = $this->schema_factory->get_schema_specification($schema_type);

        if (empty($spec)) {
            $validation['errors'][] = "Unsupported schema type: {$schema_type}";
            $validation['valid'] = false;
            return $validation;
        }

        // Check required properties
        foreach ($spec['required'] as $required_prop) {
            if (!isset($schema[$required_prop]) || empty($schema[$required_prop])) {
                $validation['errors'][] = "Missing required property: {$required_prop}";
                $validation['valid'] = false;
            }
        }

        // Check recommended properties
        $missing_recommended = 0;
        foreach ($spec['recommended'] as $recommended_prop) {
            if (!isset($schema[$recommended_prop]) || empty($schema[$recommended_prop])) {
                $validation['warnings'][] = "Missing recommended property: {$recommended_prop}";
                $missing_recommended++;
            }
        }

        // Calculate score based on completeness
        $total_props = count($spec['required']) + count($spec['recommended']);
        $missing_props = count($validation['errors']) + $missing_recommended;
        $validation['score'] = $total_props > 0 ? (int) round(max(0, 100 - (($missing_props / $total_props) * 100))) : 100;

        // Type-specific validation
        $validation = $this->validate_schema_type_specific($schema, $schema_type, $validation);

        // SEO-specific suggestions
        $validation = $this->add_seo_suggestions($schema, $schema_type, $validation);

        return $validation;
    }

    /**
     * Validate schema type-specific requirements
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema     Schema markup
     * @param string $schema_type Schema type
     * @param array  $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_schema_type_specific(array $schema, string $schema_type, array $validation): array {
        switch ($schema_type) {
            case 'Article':
            case 'BlogPosting':
            case 'TechnicalArticle':
            case 'NewsArticle':
            case 'ScholarlyArticle':
            case 'Report':
                $validation = $this->validate_article_schema($schema, $validation);
                break;
            case 'Product':
                $validation = $this->validate_product_schema($schema, $validation);
                break;
            case 'Organization':
                $validation = $this->validate_organization_schema($schema, $validation);
                break;
            case 'LocalBusiness':
                $validation = $this->validate_local_business_schema($schema, $validation);
                break;
            case 'WebSite':
                $validation = $this->validate_website_schema($schema, $validation);
                break;
            case 'FAQPage':
                $validation = $this->validate_faq_schema($schema, $validation);
                break;
            case 'VideoObject':
                $validation = $this->validate_video_object_schema($schema, $validation);
                break;
        }

        return $validation;
    }

    /**
     * Validate VideoObject schema specific requirements.
     *
     * Google requires name, description, thumbnailUrl and uploadDate. A video is
     * only playable with a contentUrl or embedUrl, so warn when both are missing.
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_video_object_schema(array $schema, array $validation): array {
        foreach (['name', 'description', 'thumbnailUrl', 'uploadDate'] as $field) {
            if (empty($schema[$field])) {
                $validation['errors'][] = sprintf('VideoObject schema requires a %s', $field);
                $validation['valid'] = false;
            }
        }

        if (empty($schema['contentUrl']) && empty($schema['embedUrl'])) {
            $validation['warnings'][] = 'VideoObject should include a contentUrl or embedUrl so the video is playable';
        }

        return $validation;
    }

    /**
     * Validate Article schema specific requirements
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_article_schema(array $schema, array $validation): array {
        // Check headline length (Google recommends under 110 characters)
        if (isset($schema['headline']) && is_string($schema['headline']) && strlen($schema['headline']) > 110) {
            $validation['warnings'][] = 'Headline is longer than 110 characters, may be truncated in search results';
        }

        // Check for image
        if (!isset($schema['image'])) {
            $validation['suggestions'][] = 'Add an image to improve rich snippet appearance';
        }

        // Check for author structure
        if (isset($schema['author']) && is_array($schema['author'])) {
            if (!isset($schema['author']['@type']) || $schema['author']['@type'] !== 'Person') {
                $validation['warnings'][] = 'Author should be structured as a Person entity';
            }
        }

        // Check for publisher
        if (!isset($schema['publisher'])) {
            $validation['suggestions'][] = 'Add publisher information for better credibility';
        }

        return $validation;
    }

    /**
     * Validate Product schema specific requirements
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_product_schema(array $schema, array $validation): array {
        // Check for offers
        if (!isset($schema['offers'])) {
            $validation['suggestions'][] = 'Add price information with offers property';
        } else {
            // Validate offers structure
            $offers = $schema['offers'];
            if (!isset($offers['@type']) || $offers['@type'] !== 'Offer') {
                $validation['warnings'][] = 'Offers should be structured as an Offer entity';
            }
            if (!isset($offers['price']) || !isset($offers['priceCurrency'])) {
                $validation['warnings'][] = 'Offers should include price and priceCurrency';
            }
        }

        // Check for brand
        if (!isset($schema['brand'])) {
            $validation['suggestions'][] = 'Add brand information to improve product visibility';
        }

        // Check for reviews or ratings
        if (!isset($schema['review']) && !isset($schema['aggregateRating'])) {
            $validation['suggestions'][] = 'Add reviews or ratings to improve product credibility';
        }

        return $validation;
    }

    /**
     * Validate Organization schema specific requirements
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_organization_schema(array $schema, array $validation): array {
        // Check for logo - properly check if it exists and has URL
        if (!isset($schema['logo']) || empty($schema['logo']) ||
            (is_array($schema['logo']) && empty($schema['logo']['url']))) {
            $validation['suggestions'][] = 'Add a logo to improve brand recognition';
        }

        // Check for contact information - properly validate the structure
        if (isset($schema['contactPoint']) && !empty($schema['contactPoint']) && is_array($schema['contactPoint'])) {
            $contact = $schema['contactPoint'];

            // Only suggest missing fields if they're actually missing
            if (!isset($contact['telephone']) || empty($contact['telephone'])) {
                $validation['suggestions'][] = 'Contact phone number is recommended for organization schema.';
            }
            if (!isset($contact['email']) || empty($contact['email'])) {
                $validation['suggestions'][] = 'Contact email is recommended for organization schema.';
            }
            if (!isset($contact['contactType']) || empty($contact['contactType'])) {
                $validation['suggestions'][] = 'Contact type is recommended for organization schema.';
            }
        } else {
            // Only suggest if contactPoint is completely missing or invalid
            $validation['suggestions'][] = 'Contact information is recommended for organization schema.';
        }

        // Check for address (especially for LocalBusiness)
        if ($schema['@type'] === 'LocalBusiness' && (!isset($schema['address']) || empty($schema['address']))) {
            $validation['errors'][] = 'LocalBusiness requires an address';
            $validation['valid'] = false;
        }

        // Check for social media profiles - properly validate array
        if (!isset($schema['sameAs']) || empty($schema['sameAs']) ||
            (is_array($schema['sameAs']) && count(array_filter($schema['sameAs'], function($url) {
                return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
            })) === 0)) {
            $validation['suggestions'][] = 'Social media profiles (sameAs) are recommended for organization schema - add Facebook, Twitter, LinkedIn, Instagram, or YouTube URLs.';
        }

        return $validation;
    }

    /**
     * Validate LocalBusiness schema specific requirements
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_local_business_schema(array $schema, array $validation): array {
        // Check for logo - properly check if it exists and has URL
        if (!isset($schema['logo']) || empty($schema['logo']) ||
            (is_array($schema['logo']) && empty($schema['logo']['url']))) {
            $validation['suggestions'][] = 'Add a logo to improve brand recognition';
        }

        // LocalBusiness uses direct telephone property, not contactPoint
        if (!isset($schema['telephone']) || empty($schema['telephone'])) {
            $validation['suggestions'][] = 'Contact phone number is recommended for local business schema.';
        }

        // Check for address (required for LocalBusiness)
        if (!isset($schema['address']) || empty($schema['address'])) {
            $validation['errors'][] = 'LocalBusiness requires an address';
            $validation['valid'] = false;
        }

        // Check for social media profiles - properly validate array
        if (!isset($schema['sameAs']) || empty($schema['sameAs']) ||
            (is_array($schema['sameAs']) && count(array_filter($schema['sameAs'], function($url) {
                return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
            })) === 0)) {
            $validation['suggestions'][] = 'Social media profiles (sameAs) are recommended for local business schema.';
        }

        return $validation;
    }

    /**
     * Validate Website schema specific requirements
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_website_schema(array $schema, array $validation): array {
        // Check for logo - properly check both direct logo and publisher logo
        $has_logo = false;
        if (isset($schema['logo']) && !empty($schema['logo']) &&
            (is_string($schema['logo']) || (is_array($schema['logo']) && !empty($schema['logo']['url'])))) {
            $has_logo = true;
        } elseif (isset($schema['publisher']['logo']) && !empty($schema['publisher']['logo']) &&
                  (is_string($schema['publisher']['logo']) || (is_array($schema['publisher']['logo']) && !empty($schema['publisher']['logo']['url'])))) {
            $has_logo = true;
        }

        if (!$has_logo) {
            $validation['suggestions'][] = 'No logo configured. Add a logo in Site Identity > Site Assets for better brand recognition.';
        }

        // Check for search action (potentialAction) - properly validate structure
        if (!isset($schema['potentialAction']) || empty($schema['potentialAction']) ||
            (is_array($schema['potentialAction']) && empty($schema['potentialAction']['@type']))) {
            $validation['suggestions'][] = 'Add search functionality with potentialAction for enhanced search box appearance.';
        }

        // Check for publisher information - properly validate structure
        if (!isset($schema['publisher']) || empty($schema['publisher']) ||
            (is_array($schema['publisher']) && empty($schema['publisher']['name']))) {
            $validation['suggestions'][] = 'Add publisher information for better website credibility.';
        }

        return $validation;
    }

    /**
     * Validate FAQ schema specific requirements
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array $schema     Schema markup
     * @param array $validation Current validation results
     * @return array Updated validation results
     */
    private function validate_faq_schema(array $schema, array $validation): array {
        // Check minimum number of questions
        if (isset($schema['mainEntity']) && is_array($schema['mainEntity']) && count($schema['mainEntity']) < 2) {
            $validation['warnings'][] = 'FAQ pages should have at least 2 questions for optimal SEO';
        }

        // Validate question structure
        if (isset($schema['mainEntity']) && is_array($schema['mainEntity'])) {
            foreach ($schema['mainEntity'] as $index => $question) {
                if (!isset($question['@type']) || $question['@type'] !== 'Question') {
                    $validation['warnings'][] = "FAQ item {$index} should be structured as a Question entity";
                }
                if (!isset($question['acceptedAnswer'])) {
                    $validation['errors'][] = "FAQ question {$index} is missing acceptedAnswer";
                    $validation['valid'] = false;
                }
            }
        }

        return $validation;
    }

    /**
     * Add SEO-specific suggestions
     * PRESERVED: Exact same method logic from original Schema_Generator
     *
     * @since 1.0.0
     *
     * @param array  $schema     Schema markup
     * @param string $schema_type Schema type
     * @param array  $validation Current validation results
     * @return array Updated validation results
     */
    private function add_seo_suggestions(array $schema, string $schema_type, array $validation): array {
        // General SEO suggestions
        if (!isset($schema['description'])) {
            $validation['suggestions'][] = 'Add a description to improve search result snippets';
        }

        if (!isset($schema['url'])) {
            $validation['suggestions'][] = 'Add a URL to help search engines understand the content location';
        }

        // Type-specific SEO suggestions
        switch ($schema_type) {
            case 'Article':
            case 'BlogPosting':
                if (!isset($schema['dateModified'])) {
                    $validation['suggestions'][] = 'Add dateModified to show content freshness';
                }
                if (!isset($schema['wordCount'])) {
                    $validation['suggestions'][] = 'Add wordCount for better content analysis';
                }
                break;

            case 'Product':
                if (!isset($schema['sku'])) {
                    $validation['suggestions'][] = 'Add SKU for better product identification';
                }
                break;
        }

        return $validation;
    }
}
