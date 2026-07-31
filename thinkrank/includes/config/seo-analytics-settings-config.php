<?php
/**
 * SEO Analytics Settings Configuration
 *
 * Default settings configuration for SEO Analytics & Intelligence system
 * including Google API integration, AI insights, monitoring, and reporting.
 *
 * @package ThinkRank
 * @subpackage Config
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get default SEO Analytics settings
 * Following ThinkRank settings configuration patterns
 *
 * @return array Default settings array
 */
function thinkrank_get_default_seo_analytics_settings(): array {
    return [
        // Core Analytics Settings
        'enabled' => false,
        'setup_completed' => false,
        
        // Google Analytics Configuration
        'google_analytics_property_id' => '',
        
        // Search Console Configuration  
        'search_console_property' => '',
        
        // AI Features
        'enable_ai_insights' => true,
        'enable_automated_alerts' => false,
        'enable_predictive_analysis' => false,
        
        // Monitoring Configuration
        'monitoring_frequency' => 3600, // 1 hour in seconds
        'alert_thresholds' => [
            'traffic_drop_percentage' => 20,
            'ranking_drop_positions' => 5,
            'core_web_vitals_threshold' => 'needs_improvement'
        ],
        'report_schedule' => 'weekly', // daily, weekly, monthly
        
        // Data Management
        'data_retention_days' => 90,
        'cache_analytics_data' => true,
        
        // Dashboard Configuration
        'dashboard_widgets' => [
            'traffic_overview',
            'search_performance', 
            'core_web_vitals',
            'seo_opportunities',
            'keyword_rankings'
        ],
        
        // Notification Settings
        'email_notifications' => false,
        'notification_email' => '',
        'slack_webhook_url' => '',
        
        // Advanced Settings
        'enable_debug_mode' => false,
        'api_rate_limiting' => true,
        'parallel_requests' => false,
        
        // Feature Flags
        'enable_competitor_analysis' => false,
        'enable_content_suggestions' => true,
        'enable_technical_seo_monitoring' => true
    ];
}

/**
 * Get SEO Analytics settings validation rules
 * Following ThinkRank validation patterns
 *
 * @return array Validation rules
 */
function thinkrank_get_seo_analytics_validation_rules(): array {
    return [
        'enabled' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'setup_completed' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'google_analytics_property_id' => [
            'type' => 'string',
            'required' => false,
            'pattern' => '/^properties\/\d+$/',
            'description' => 'GA4 Property ID in format: properties/123456789'
        ],
        'search_console_property' => [
            'type' => 'string',
            'required' => false,
            'format' => 'url',
            'description' => 'Verified site URL in Search Console'
        ],
        'enable_ai_insights' => [
            'type' => 'boolean',
            'required' => false,
            'default' => true
        ],
        'enable_automated_alerts' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'enable_predictive_analysis' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'monitoring_frequency' => [
            'type' => 'integer',
            'required' => false,
            'minimum' => 300, // 5 minutes minimum
            'maximum' => 86400, // 24 hours maximum
            'default' => 3600
        ],
        'alert_thresholds' => [
            'type' => 'object',
            'required' => false,
            'properties' => [
                'traffic_drop_percentage' => [
                    'type' => 'integer',
                    'minimum' => 5,
                    'maximum' => 50
                ],
                'ranking_drop_positions' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20
                ],
                'core_web_vitals_threshold' => [
                    'type' => 'string',
                    'enum' => ['good', 'needs_improvement', 'poor']
                ]
            ]
        ],
        'report_schedule' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['daily', 'weekly', 'monthly'],
            'default' => 'weekly'
        ],
        'data_retention_days' => [
            'type' => 'integer',
            'required' => false,
            'minimum' => 30,
            'maximum' => 365,
            'default' => 90
        ],
        'cache_analytics_data' => [
            'type' => 'boolean',
            'required' => false,
            'default' => true
        ],
        'dashboard_widgets' => [
            'type' => 'array',
            'required' => false,
            'items' => [
                'type' => 'string',
                'enum' => [
                    'traffic_overview',
                    'search_performance',
                    'core_web_vitals',
                    'seo_opportunities',
                    'keyword_rankings',
                    'competitor_analysis',
                    'content_suggestions'
                ]
            ]
        ],
        'email_notifications' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'notification_email' => [
            'type' => 'string',
            'required' => false,
            'format' => 'email'
        ],
        'slack_webhook_url' => [
            'type' => 'string',
            'required' => false,
            'format' => 'url'
        ],
        'enable_debug_mode' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'api_rate_limiting' => [
            'type' => 'boolean',
            'required' => false,
            'default' => true
        ],
        'parallel_requests' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'enable_competitor_analysis' => [
            'type' => 'boolean',
            'required' => false,
            'default' => false
        ],
        'enable_content_suggestions' => [
            'type' => 'boolean',
            'required' => false,
            'default' => true
        ],
        'enable_technical_seo_monitoring' => [
            'type' => 'boolean',
            'required' => false,
            'default' => true
        ]
    ];
}

/**
 * Get SEO Analytics feature descriptions
 * For UI help text and documentation
 *
 * @return array Feature descriptions
 */
function thinkrank_get_seo_analytics_feature_descriptions(): array {
    return [
        'ai_insights' => 'AI-powered analysis of your SEO performance with actionable recommendations',
        'automated_alerts' => 'Automatic notifications when significant changes are detected',
        'predictive_analysis' => 'Forecast potential SEO impact of content and technical changes',
        'competitor_analysis' => 'Track and compare your performance against competitors',
        'content_suggestions' => 'AI-generated content optimization suggestions',
        'technical_seo_monitoring' => 'Monitor Core Web Vitals and technical SEO factors'
    ];
}

/**
 * Get SEO Analytics setup requirements
 * For setup wizard and validation
 *
 * @return array Setup requirements
 */
function thinkrank_get_seo_analytics_setup_requirements(): array {
    return [
        'required_apis' => [
            'google_analytics' => [
                'name' => 'Google Analytics Data API',
                'required' => true,
                'description' => 'Required for traffic and user behavior data'
            ],
            'search_console' => [
                'name' => 'Google Search Console API',
                'required' => true,
                'description' => 'Required for search performance and indexing data'
            ],
            'pagespeed' => [
                'name' => 'Google PageSpeed Insights API',
                'required' => false,
                'description' => 'Optional for Core Web Vitals monitoring'
            ]
        ],
        'minimum_requirements' => [
            'wordpress_version' => '6.0',
            'php_version' => '8.0',
            'memory_limit' => '256M',
            'curl_support' => true
        ]
    ];
}
