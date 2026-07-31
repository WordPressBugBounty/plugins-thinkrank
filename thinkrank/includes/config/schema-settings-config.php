<?php
/**
 * Schema Settings Configuration
 *
 * Shared configuration for schema settings between frontend and backend.
 * This eliminates duplication and ensures consistency across the application.
 *
 * @package ThinkRank
 * @subpackage Config
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Config;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema Settings Configuration Class
 *
 * Provides centralized schema settings configuration for both frontend and backend.
 * Eliminates duplication between SchemaTab.js and Schema_Management_System.
 *
 * @since 1.0.0
 */
class Schema_Settings_Config {

    /**
     * Get default schema settings
     *
     * @since 1.0.0
     *
     * @param string $context_type Context type ('site', 'post', 'page', 'product')
     * @return array Default settings array
     */
    public static function get_default_settings(string $context_type = 'site'): array {
        $defaults = [
            // Core settings
            'enabled' => true,
            'auto_generate_schema' => true,
            'enabled_schema_types' => [],
            'validation_level' => 'moderate',
            'rich_snippets_optimization' => true,
            'cache_duration' => 3600, // 1 hour
            'auto_deploy' => true,

            // Basic schema settings (moved from Site Identity)
            'organization_schema' => true,
            'knowledge_graph' => true,

            // Organization schema settings
            'organization_name' => '',
            'organization_type' => 'Organization',
            'organization_logo' => '',
            'organization_url' => '',
            'organization_description' => '',
            'organization_social_facebook' => '',
            'organization_social_twitter' => '',
            'organization_social_linkedin' => '',
            'organization_social_instagram' => '',
            'organization_social_youtube' => '',
            'organization_social_pinterest' => '',
            'organization_social_whatsapp' => '',
            'organization_social_telegram' => '',
            'organization_contact_type' => 'customer service',
            'organization_contact_phone' => '',
            'organization_contact_email' => '',
            'organization_contact_hours' => '',

            // Website schema settings
            'website_name' => '',
            'website_url' => '',
            'website_description' => '',
            'website_author' => '',
            'website_enable_search' => true,
            'website_search_url' => '',

            // LocalBusiness schema settings
            'business_price_range' => '',
            'business_geo_latitude' => '',
            'business_geo_longitude' => '',
            'business_opening_hours' => [],

            // Content schema settings (site-wide only)
            'enable_breadcrumbs_schema' => true,
            'enable_local_business' => false,

            // Removed post/page-specific schema settings (Product, Event, Article, Software Application, HowTo, FAQ)
            // These are now handled only at the post/page level via metabox

            // Person schema settings (site-wide)
            'person_name' => '',
            'person_job_title' => '',
            'person_description' => '',
            'person_image' => '',
            'person_url' => '',
            'person_email' => '',
            'person_telephone' => '',
            'person_address' => '',
            'person_birth_date' => '',
            'person_nationality' => '',
            'person_works_for' => '',
            'person_same_as' => array(),

            // Removed FAQPage schema settings (now handled at post/page level)
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                $defaults['enabled_schema_types'] = ['Organization', 'LocalBusiness'];
                $defaults['auto_deploy'] = true;
                break;
            case 'post':
                $defaults['enabled_schema_types'] = ['Article', 'Person'];
                $defaults['validation_level'] = 'strict';
                break;
            case 'page':
                $defaults['enabled_schema_types'] = ['Article', 'FAQPage'];
                $defaults['rich_snippets_optimization'] = true;
                break;
            case 'product':
                $defaults['enabled_schema_types'] = ['Product'];
                $defaults['validation_level'] = 'strict';
                $defaults['rich_snippets_optimization'] = true;
                break;
        }

        return $defaults;
    }

    /**
     * Get settings schema definition
     *
     * @since 1.0.0
     *
     * @param string $context_type Context type
     * @return array Settings schema definition
     */
    public static function get_settings_schema(string $context_type = 'site'): array {
        return [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Schema Management',
                'description' => 'Enable automated schema markup generation and management',
                'default' => true
            ],
            'auto_generate_schema' => [
                'type' => 'boolean',
                'title' => 'Auto-Generate Schema',
                'description' => 'Automatically generate schema markup based on content',
                'default' => true
            ],
            'enabled_schema_types' => [
                'type' => 'array',
                'title' => 'Enabled Schema Types',
                'description' => 'Select which schema types to generate',
                'items' => [
                    'type' => 'string',
                    'enum' => self::get_supported_schema_types($context_type)
                ],
                'default' => []
            ],
            'deployment_method' => [
                'type' => 'string',
                'title' => 'Deployment Method',
                'description' => 'Schema markup output format (JSON-LD)',
                'enum' => ['json_ld'],
                'default' => 'json_ld'
            ],
            'validation_level' => [
                'type' => 'string',
                'title' => 'Validation Level',
                'description' => 'Schema validation strictness level',
                'enum' => ['strict', 'moderate', 'lenient'],
                'default' => 'moderate'
            ],
            'rich_snippets_optimization' => [
                'type' => 'boolean',
                'title' => 'Rich Snippets Optimization',
                'description' => 'Optimize schema for rich snippets in search results',
                'default' => true
            ],
            'cache_duration' => [
                'type' => 'integer',
                'title' => 'Cache Duration',
                'description' => 'Schema cache duration in seconds',
                'minimum' => 300,
                'maximum' => 86400,
                'default' => 3600
            ],
            'auto_deploy' => [
                'type' => 'boolean',
                'title' => 'Auto Deploy',
                'description' => 'Automatically deploy generated schema markup',
                'default' => true
            ]
        ];
    }

    /**
     * Get supported schema types for context
     *
     * @since 1.0.0
     *
     * @param string $context_type Context type
     * @return array Supported schema types
     */
    public static function get_supported_schema_types(string $context_type = 'site'): array {
        $all_types = [
            'Article', 'Product', 'Organization', 'LocalBusiness',
            'Person', 'WebSite', 'FAQPage', 'SoftwareApplication',
            'Event', 'HowTo', 'Review', 'VideoObject'
        ];

        // Context-specific filtering can be added here if needed
        return $all_types;
    }

    /**
     * Get validation level options
     *
     * @since 1.0.0
     *
     * @return array Validation level options
     */
    public static function get_validation_level_options(): array {
        return [
            ['value' => 'strict', 'label' => 'Strict'],
            ['value' => 'moderate', 'label' => 'Moderate'],
            ['value' => 'lenient', 'label' => 'Lenient']
        ];
    }

    /**
     * Get deployment method options
     *
     * @since 1.0.0
     *
     * @return array Deployment method options
     */
    public static function get_deployment_method_options(): array {
        return [
            ['value' => 'json_ld', 'label' => 'JSON-LD']
        ];
    }

    /**
     * Get boolean settings list
     *
     * @since 1.0.0
     *
     * @return array List of boolean setting keys
     */
    public static function get_boolean_settings(): array {
        return [
            'enabled',
            'auto_generate_schema',
            'rich_snippets_optimization',
            'auto_deploy',
            'organization_schema',
            'knowledge_graph',
            'enable_breadcrumbs_schema',
            'enable_article_schema',
            'enable_product_schema',
            'enable_local_business',
            'enable_faq_schema',
            'enable_howto_schema'
        ];
    }

    /**
     * Get array settings list
     *
     * @since 1.0.0
     *
     * @return array List of array setting keys
     */
    public static function get_array_settings(): array {
        return [
            'enabled_schema_types',
            'business_opening_hours',
            'software_operating_systems',
            'software_features',
            'howto_supply'
        ];
    }

    /**
     * Get integer settings list
     *
     * @since 1.0.0
     *
     * @return array List of integer setting keys
     */
    public static function get_integer_settings(): array {
        return [
            'cache_duration'
        ];
    }
}
