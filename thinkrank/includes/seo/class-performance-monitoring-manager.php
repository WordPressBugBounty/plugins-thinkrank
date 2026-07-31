<?php
/**
 * Performance Monitoring Manager Class
 *
 * Advanced performance monitoring system with real-time Core Web Vitals tracking,
 * SEO metrics analysis, automated reporting, and integration with Google APIs.
 * Implements 2025 performance standards with industry-leading monitoring algorithms.
 *
 * @package ThinkRank
 * @subpackage SEO
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

use ThinkRank\Core\Settings_Manager;
use ThinkRank\Integrations\Google_PageSpeed_Client;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performance Monitoring Manager Class
 *
 * Provides comprehensive performance monitoring with Core Web Vitals tracking,
 * SEO metrics analysis, automated reporting, and performance optimization
 * recommendations integrated with AI Content Analyzer and Content Optimization Manager.
 *
 * @since 1.0.0
 */
class Performance_Monitoring_Manager extends Abstract_SEO_Manager {

    /**
     * Core Web Vitals thresholds (2025 Google standards)
     * Lazy-loaded to reduce memory usage
     *
     * @since 1.0.0
     * @var array|null
     */
    private ?array $core_web_vitals = null;

    /**
     * SEO performance metrics with tracking specifications
     *
     * @since 1.0.0
     * @var array
     */
    private array $seo_metrics = [
        'page_speed_score' => [
            'name' => 'Page Speed Score',
            'unit' => 'score',
            'target' => 90,
            'weight' => 20,
            'source' => 'pagespeed_insights'
        ],
        'mobile_usability' => [
            'name' => 'Mobile Usability',
            'unit' => 'score',
            'target' => 95,
            'weight' => 15,
            'source' => 'mobile_friendly_test'
        ],
        'seo_score' => [
            'name' => 'SEO Score',
            'unit' => 'score',
            'target' => 85,
            'weight' => 25,
            'source' => 'lighthouse'
        ],
        'accessibility_score' => [
            'name' => 'Accessibility Score',
            'unit' => 'score',
            'target' => 90,
            'weight' => 15,
            'source' => 'lighthouse'
        ],
        'best_practices_score' => [
            'name' => 'Best Practices Score',
            'unit' => 'score',
            'target' => 95,
            'weight' => 10,
            'source' => 'lighthouse'
        ],
        'pwa_score' => [
            'name' => 'PWA Score',
            'unit' => 'score',
            'target' => 80,
            'weight' => 15,
            'source' => 'lighthouse'
        ]
    ];

    /**
     * Performance monitoring configuration
     *
     * @since 1.0.0
     * @var array
     */
    private array $monitoring_config = [
        'measurement_frequency' => [
            'real_time' => 300,      // 5 minutes for real-time monitoring
            'hourly' => 3600,        // 1 hour for regular checks
            'daily' => 86400,        // 24 hours for daily reports
            'weekly' => 604800       // 7 days for weekly analysis
        ],
        'alert_thresholds' => [
            'critical' => 40,        // Below 40% performance score
            'warning' => 60,         // Below 60% performance score
            'good' => 80,            // Above 80% performance score
            'excellent' => 90        // Above 90% performance score
        ],
        'data_retention' => [
            'real_time_data' => 7,   // 7 days of real-time data
            'hourly_data' => 30,     // 30 days of hourly data
            'daily_data' => 365,     // 1 year of daily data
            'weekly_data' => 1095    // 3 years of weekly data
        ],
        'api_endpoints' => [
            'pagespeed_insights' => 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed',
            'mobile_friendly' => 'https://searchconsole.googleapis.com/v1/urlTestingTools/mobileFriendlyTest:run',
            'core_web_vitals' => 'https://chromeuxreport.googleapis.com/v1/records:queryRecord'
        ]
    ];

    /**
     * Performance benchmarks by industry and content type
     *
     * @since 1.0.0
     * @var array
     */
    private array $performance_benchmarks = [
        'e_commerce' => [
            'lcp' => 2.2,
            'fid' => 80,
            'cls' => 0.08,
            'page_speed' => 85,
            'conversion_impact' => 'high'
        ],
        'blog' => [
            'lcp' => 2.0,
            'fid' => 70,
            'cls' => 0.05,
            'page_speed' => 90,
            'conversion_impact' => 'medium'
        ],
        'corporate' => [
            'lcp' => 1.8,
            'fid' => 60,
            'cls' => 0.03,
            'page_speed' => 95,
            'conversion_impact' => 'medium'
        ],
        'news' => [
            'lcp' => 1.5,
            'fid' => 50,
            'cls' => 0.02,
            'page_speed' => 92,
            'conversion_impact' => 'low'
        ]
    ];

    /**
     * Settings Manager instance
     * Phase 4: Integration with Settings Manager for Google API keys
     *
     * @since 1.0.0
     * @var Settings_Manager
     */
    private Settings_Manager $settings_manager;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct('performance_monitoring_manager');
        $this->settings_manager = new Settings_Manager();
    }
    private function get_user_friendly_error(\Exception $exception): array {
        $message = $exception->getMessage();

        if (strpos($message, 'API key') !== false || strpos($message, 'not connected') !== false) {
            return [
                'type' => 'configuration',
                'title' => 'Google Account Connection Required',
                'message' => 'Please connect your Google account in Integrations > Google Services to view performance data.',
                'action' => 'connect_google'
            ];
        }

        if (strpos($message, 'rate limit') !== false) {
            return [
                'type' => 'rate_limit',
                'title' => 'API Rate Limit Exceeded',
                'message' => 'Google API rate limit exceeded. Please try again in a few minutes.',
                'action' => 'retry_later'
            ];
        }

        if (strpos($message, 'network') !== false || strpos($message, 'timeout') !== false) {
            return [
                'type' => 'network',
                'title' => 'Network Connection Issue',
                'message' => 'Unable to connect to Google services. Please check your internet connection.',
                'action' => 'check_connection'
            ];
        }

        return [
            'type' => 'general',
            'title' => 'Performance Data Unavailable',
            'message' => 'Unable to retrieve performance data at this time. Please try again later.',
            'action' => 'retry'
        ];
    }

    /**
     * Get Core Web Vitals configuration (lazy-loaded)
     *
     * @return array Core Web Vitals configuration
     */
    private function get_core_web_vitals_config(): array {
        if ($this->core_web_vitals === null) {
            $this->core_web_vitals = [
                'lcp' => [
                    'name' => 'Largest Contentful Paint',
                    'unit' => 'seconds',
                    'good_threshold' => 2.5,
                    'needs_improvement_threshold' => 4.0,
                    'weight' => 30,
                    'description' => 'Time to render the largest content element'
                ],
                'fid' => [
                    'name' => 'First Input Delay',
                    'unit' => 'milliseconds',
                    'good_threshold' => 100,
                    'needs_improvement_threshold' => 300,
                    'weight' => 25,
                    'description' => 'Time from first user interaction to browser response'
                ],
                'cls' => [
                    'name' => 'Cumulative Layout Shift',
                    'unit' => 'score',
                    'good_threshold' => 0.1,
                    'needs_improvement_threshold' => 0.25,
                    'weight' => 25,
                    'description' => 'Visual stability of page during loading'
                ],
                'fcp' => [
                    'name' => 'First Contentful Paint',
                    'unit' => 'seconds',
                    'good_threshold' => 1.8,
                    'needs_improvement_threshold' => 3.0,
                    'weight' => 20,
                    'description' => 'Time until the first content is painted on screen'
                ]
            ];
        }
        return $this->core_web_vitals;
    }

    /**
     * Monitor performance with comprehensive Core Web Vitals and SEO metrics analysis
     *
     * @since 1.0.0
     *
     * @param string $url          URL to monitor
     * @param array  $options      Monitoring options
     * @param string $device_type  Device type (desktop, mobile)
     * @return array Comprehensive performance monitoring results
     */
    public function monitor_performance(string $url, array $options = [], string $device_type = 'desktop'): array {
        $monitoring = [
            'url' => $url,
            'device_type' => $device_type,
            'core_web_vitals' => [],
            'seo_metrics' => [],
            'performance_score' => 0,
            'performance_grade' => '',
            'recommendations' => [],
            'historical_comparison' => [],
            'benchmark_comparison' => [],
            'monitoring_timestamp' => current_time('mysql')
        ];

        // Measure Core Web Vitals
        $monitoring['core_web_vitals'] = $this->measure_core_web_vitals($url, $device_type, $options);

        // Collect SEO performance metrics
        $monitoring['seo_metrics'] = $this->collect_seo_metrics($url, $device_type, $options);

        // Calculate overall performance score
        $monitoring['performance_score'] = $this->calculate_performance_score(
            $monitoring['core_web_vitals'],
            $monitoring['seo_metrics']
        );

        // Determine performance grade
        $monitoring['performance_grade'] = $this->determine_performance_grade($monitoring['performance_score']);

        // Generate performance recommendations
        $monitoring['recommendations'] = $this->generate_performance_recommendations(
            $monitoring['core_web_vitals'],
            $monitoring['seo_metrics'],
            $url
        );

        // Compare with historical data
        $monitoring['historical_comparison'] = $this->compare_with_historical_data($url, $monitoring);

        // Compare with industry benchmarks
        $monitoring['benchmark_comparison'] = $this->compare_with_benchmarks($monitoring, $options);

        // Store performance data
        $this->store_performance_data($url, $monitoring);

        return $monitoring;
    }

    /**
     * Generate automated performance reports
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @param string   $report_type  Report type (daily, weekly, monthly)
     * @param array    $options      Report options
     * @return array Automated performance report
     */
    public function generate_performance_report(string $context_type, ?int $context_id, string $report_type = 'weekly', array $options = []): array {
        $report = [
            'report_type' => $report_type,
            'context_type' => $context_type,
            'context_id' => $context_id,
            'report_period' => [],
            'performance_summary' => [],
            'core_web_vitals_analysis' => [],
            'seo_metrics_analysis' => [],
            'performance_trends' => [],
            'optimization_opportunities' => [],
            'competitive_analysis' => [],
            'action_items' => [],
            'report_timestamp' => current_time('mysql')
        ];

        // Define report period
        $report['report_period'] = $this->define_report_period($report_type);

        // Generate performance summary
        $report['performance_summary'] = $this->generate_performance_summary($context_type, $context_id, $report['report_period']);

        // Analyze Core Web Vitals trends
        $report['core_web_vitals_analysis'] = $this->analyze_core_web_vitals_trends($context_type, $context_id, $report['report_period']);

        // Analyze SEO metrics trends
        $report['seo_metrics_analysis'] = $this->analyze_seo_metrics_trends($context_type, $context_id, $report['report_period']);

        // Calculate performance trends
        $report['performance_trends'] = $this->calculate_performance_trends($context_type, $context_id, $report['report_period']);

        // Identify optimization opportunities
        $report['optimization_opportunities'] = $this->identify_optimization_opportunities($report['performance_summary'], $report['performance_trends']);

        // Perform competitive analysis
        $report['competitive_analysis'] = $this->perform_competitive_analysis($context_type, $context_id, $options);

        // Generate action items
        $report['action_items'] = $this->generate_action_items($report['optimization_opportunities'], $report['competitive_analysis']);

        // Store report data
        $this->store_report_data($report);

        return $report;
    }

    /**
     * Set up performance alerts and notifications
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @param array    $alert_config Alert configuration
     * @return array Alert setup results
     */
    public function setup_performance_alerts(string $context_type, ?int $context_id, array $alert_config): array {
        $alerts = [
            'context_type' => $context_type,
            'context_id' => $context_id,
            'alert_rules' => [],
            'notification_channels' => [],
            'alert_history' => [],
            'active_alerts' => [],
            'setup_timestamp' => current_time('mysql')
        ];

        // Configure alert rules
        $alerts['alert_rules'] = $this->configure_alert_rules($alert_config);

        // Set up notification channels
        $alerts['notification_channels'] = $this->setup_notification_channels($alert_config);

        // Get alert history
        $alerts['alert_history'] = $this->get_alert_history($context_type, $context_id);

        // Check for active alerts
        $alerts['active_alerts'] = $this->check_active_alerts($context_type, $context_id);

        return $alerts;
    }

    /**
     * Validate SEO settings (implements interface)
     *
     * @since 1.0.0
     *
     * @param array $settings Settings array to validate
     * @return array Validation results
     */
    public function validate_settings(array $settings): array {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'suggestions' => [],
            'score' => 100
        ];

        // Validate monitoring frequency
        if (isset($settings['monitoring_frequency'])) {
            if (!is_numeric($settings['monitoring_frequency']) || $settings['monitoring_frequency'] < 300) {
                $validation['errors'][] = 'Monitoring frequency must be at least 300 seconds (5 minutes)';
                $validation['valid'] = false;
            }
        }

        // Validate alert thresholds
        if (isset($settings['alert_thresholds']) && is_array($settings['alert_thresholds'])) {
            foreach ($settings['alert_thresholds'] as $threshold => $value) {
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    $validation['errors'][] = "Invalid alert threshold for {$threshold}: must be 0-100";
                    $validation['valid'] = false;
                }
            }
        }

        // Validate API configuration
        if (isset($settings['api_keys']) && is_array($settings['api_keys'])) {
            if (empty($settings['api_keys']['pagespeed_insights'])) {
                $validation['warnings'][] = 'PageSpeed Insights API key not configured - some features may be limited';
            }
            if (empty($settings['api_keys']['search_console'])) {
                $validation['warnings'][] = 'Search Console API key not configured - Core Web Vitals field data unavailable';
            }
        }

        // Validate data retention settings
        if (isset($settings['data_retention']) && is_array($settings['data_retention'])) {
            foreach ($settings['data_retention'] as $type => $days) {
                if (!is_numeric($days) || $days < 1 || $days > 3650) {
                    $validation['errors'][] = "Invalid data retention for {$type}: must be 1-3650 days";
                    $validation['valid'] = false;
                }
            }
        }

        // Validate performance targets
        if (isset($settings['performance_targets']) && is_array($settings['performance_targets'])) {
            foreach ($this->get_core_web_vitals_config() as $metric => $config) {
                if (isset($settings['performance_targets'][$metric])) {
                    $target = $settings['performance_targets'][$metric];
                    if (!is_numeric($target) || $target <= 0) {
                        $validation['errors'][] = "Invalid performance target for {$metric}: must be positive number";
                        $validation['valid'] = false;
                    }
                }
            }
        }

        // Calculate validation score
        $validation['score'] = $this->calculate_validation_score($validation);

        return $validation;
    }

    /**
     * Get output data for frontend rendering (implements interface)
     *
     * @since 1.0.0
     *
     * @param string   $context_type The context type
     * @param int|null $context_id   Optional. Context ID
     * @return array Output data ready for frontend rendering
     */
    public function get_output_data(string $context_type, ?int $context_id): array {
        $settings = $this->get_settings($context_type, $context_id);

        $output = [
            'performance_dashboard' => [],
            'core_web_vitals' => [],
            'seo_metrics' => [],
            'performance_trends' => [],
            'alerts' => [],
            'recommendations' => [],
            'enabled' => $settings['enabled'] ?? true
        ];

        if (!$output['enabled']) {
            return $output;
        }

        // Get URL for monitoring
        $url = $this->get_monitoring_url($context_type, $context_id);

        if (!empty($url)) {
            // Get current performance data
            $performance_data = $this->get_current_performance_data($url, $context_type, $context_id);

            // Generate performance dashboard
            $output['performance_dashboard'] = $this->generate_performance_dashboard($performance_data);

            // Get Core Web Vitals data
            $output['core_web_vitals'] = $this->get_core_web_vitals_data($url, $context_type, $context_id);

            // Get SEO metrics data
            $output['seo_metrics'] = $this->get_seo_metrics_data($url, $context_type, $context_id);

            // Get performance trends
            $output['performance_trends'] = $this->get_performance_trends_data($context_type, $context_id);

            // Get active alerts
            $output['alerts'] = $this->get_active_alerts_data($context_type, $context_id);

            // Get performance recommendations
            $output['recommendations'] = $this->get_performance_recommendations_data($performance_data);
        }

        return $output;
    }

    /**
     * Get default settings for a context type (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get defaults for
     * @return array Default settings array
     */
    public function get_default_settings(string $context_type): array {
        $defaults = [
            'enabled' => true,
            'monitoring_frequency' => 3600, // 1 hour
            'real_time_monitoring' => false,
            'automated_reports' => true,
            'performance_alerts' => true,
            'core_web_vitals_tracking' => true,
            'seo_metrics_tracking' => true,
            'competitive_analysis' => false,
            'alert_thresholds' => $this->monitoring_config['alert_thresholds'],
            'data_retention' => $this->monitoring_config['data_retention'],
            'performance_targets' => [
                'lcp' => 2.5,
                'fid' => 100,
                'cls' => 0.1,
                'fcp' => 1.8,
                'page_speed_score' => 90
            ],
            'notification_settings' => [
                'email_alerts' => true,
                'dashboard_notifications' => true,
                'weekly_reports' => true,
                'monthly_reports' => false
            ]
        ];

        // Context-specific defaults
        switch ($context_type) {
            case 'site':
                $defaults['monitoring_frequency'] = 1800; // 30 minutes for site-wide
                $defaults['competitive_analysis'] = true;
                $defaults['monthly_reports'] = true;
                break;
            case 'post':
                $defaults['monitoring_frequency'] = 3600; // 1 hour for posts
                $defaults['real_time_monitoring'] = false;
                break;
            case 'page':
                $defaults['monitoring_frequency'] = 1800; // 30 minutes for pages
                $defaults['real_time_monitoring'] = true;
                break;
            case 'product':
                $defaults['monitoring_frequency'] = 900; // 15 minutes for products
                $defaults['real_time_monitoring'] = true;
                $defaults['performance_targets']['lcp'] = 2.0; // Stricter for e-commerce
                break;
        }

        return $defaults;
    }

    /**
     * Get settings schema definition (implements interface)
     *
     * @since 1.0.0
     *
     * @param string $context_type The context type to get schema for
     * @return array Settings schema definition
     */
    public function get_settings_schema(string $context_type): array {
        return [
            'enabled' => [
                'type' => 'boolean',
                'title' => 'Enable Performance Monitoring',
                'description' => 'Enable comprehensive performance monitoring and Core Web Vitals tracking',
                'default' => true
            ],
            'monitoring_frequency' => [
                'type' => 'integer',
                'title' => 'Monitoring Frequency',
                'description' => 'How often to check performance metrics (in seconds)',
                'minimum' => 300,
                'maximum' => 86400,
                'default' => 3600
            ],
            'real_time_monitoring' => [
                'type' => 'boolean',
                'title' => 'Real-time Monitoring',
                'description' => 'Enable real-time performance monitoring for critical pages',
                'default' => false
            ],
            'automated_reports' => [
                'type' => 'boolean',
                'title' => 'Automated Reports',
                'description' => 'Generate automated performance reports',
                'default' => true
            ],
            'performance_alerts' => [
                'type' => 'boolean',
                'title' => 'Performance Alerts',
                'description' => 'Send alerts when performance thresholds are exceeded',
                'default' => true
            ],
            'core_web_vitals_tracking' => [
                'type' => 'boolean',
                'title' => 'Core Web Vitals Tracking',
                'description' => 'Track Core Web Vitals metrics (LCP, FID, CLS, FCP)',
                'default' => true
            ],
            'seo_metrics_tracking' => [
                'type' => 'boolean',
                'title' => 'SEO Metrics Tracking',
                'description' => 'Track SEO performance metrics and scores',
                'default' => true
            ],
            'competitive_analysis' => [
                'type' => 'boolean',
                'title' => 'Competitive Analysis',
                'description' => 'Compare performance with industry benchmarks',
                'default' => false
            ],
            'lcp_target' => [
                'type' => 'number',
                'title' => 'LCP Target (seconds)',
                'description' => 'Target Largest Contentful Paint time',
                'minimum' => 0.5,
                'maximum' => 10.0,
                'default' => 2.5
            ],
            'fid_target' => [
                'type' => 'integer',
                'title' => 'FID Target (milliseconds)',
                'description' => 'Target First Input Delay time',
                'minimum' => 10,
                'maximum' => 1000,
                'default' => 100
            ],
            'cls_target' => [
                'type' => 'number',
                'title' => 'CLS Target (score)',
                'description' => 'Target Cumulative Layout Shift score',
                'minimum' => 0.0,
                'maximum' => 1.0,
                'default' => 0.1
            ]
        ];
    }

    /**
     * Measure Core Web Vitals using real algorithms
     *
     * @since 1.0.0
     *
     * @param string $url         URL to measure
     * @param string $device_type Device type
     * @param array  $options     Measurement options
     * @return array Core Web Vitals measurements
     */
    private function measure_core_web_vitals(string $url, string $device_type, array $options): array {
        $vitals = [];

        foreach ($this->get_core_web_vitals_config() as $metric => $config) {
            $vitals[$metric] = [
                'name' => $config['name'],
                'value' => 0,
                'unit' => $config['unit'],
                'status' => 'unknown',
                'percentile' => 0,
                'field_data' => null,
                'lab_data' => null,
                'score' => 0
            ];

            // Get field data from Chrome UX Report
            $field_data = $this->get_field_data_for_metric($url, $metric, $device_type);
            if ($field_data) {
                $vitals[$metric]['field_data'] = $field_data;
                $vitals[$metric]['value'] = $field_data['percentile_75'] ?? 0;
                $vitals[$metric]['percentile'] = 75;
            }

            // Get lab data from PageSpeed Insights
            $lab_data = $this->get_lab_data_for_metric($url, $metric, $device_type);
            if ($lab_data) {
                $vitals[$metric]['lab_data'] = $lab_data;
                if (!$vitals[$metric]['value']) {
                    $vitals[$metric]['value'] = $lab_data['value'] ?? 0;
                }
            }

            // Determine status based on thresholds
            $vitals[$metric]['status'] = $this->determine_metric_status($vitals[$metric]['value'], $config);

            // Calculate metric score
            $vitals[$metric]['score'] = $this->calculate_metric_score($vitals[$metric]['value'], $config);
        }

        return $vitals;
    }

    /**
     * Collect SEO performance metrics
     *
     * @since 1.0.0
     *
     * @param string $url         URL to analyze
     * @param string $device_type Device type
     * @param array  $options     Collection options
     * @return array SEO metrics
     */
    private function collect_seo_metrics(string $url, string $device_type, array $options): array {
        $metrics = [];

        foreach ($this->seo_metrics as $metric => $config) {
            $metrics[$metric] = [
                'name' => $config['name'],
                'value' => 0,
                'unit' => $config['unit'],
                'target' => $config['target'],
                'status' => 'unknown',
                'score' => 0,
                'source' => $config['source']
            ];

            // Collect metric based on source
            switch ($config['source']) {
                case 'pagespeed_insights':
                    $data = $this->get_pagespeed_insights_data($url, $device_type);
                    $metrics[$metric]['value'] = $data[$metric] ?? 0;
                    break;
                case 'lighthouse':
                    $data = $this->get_lighthouse_data($url, $device_type);
                    $metrics[$metric]['value'] = $data[$metric] ?? 0;
                    break;
                case 'mobile_friendly_test':
                    $data = $this->get_mobile_friendly_data($url);
                    $metrics[$metric]['value'] = $data[$metric] ?? 0;
                    break;
            }

            // Determine status
            $metrics[$metric]['status'] = $this->determine_seo_metric_status($metrics[$metric]['value'], $config['target']);

            // Calculate score
            $metrics[$metric]['score'] = $this->calculate_seo_metric_score($metrics[$metric]['value'], $config['target']);
        }

        return $metrics;
    }

    /**
     * Calculate overall performance score
     *
     * @since 1.0.0
     *
     * @param array $core_web_vitals Core Web Vitals data
     * @param array $seo_metrics     SEO metrics data
     * @return int Overall performance score (0-100)
     */
    private function calculate_performance_score(array $core_web_vitals, array $seo_metrics): int {
        $scores = [];
        $total_weight = 0;

        // Weight Core Web Vitals scores
        foreach ($core_web_vitals as $metric => $data) {
            // Skip non-array values (like 'error' and 'message' keys)
            if (!is_array($data) || !isset($data['score'])) {
                continue;
            }
            $weight = $this->get_core_web_vitals_config()[$metric]['weight'] ?? 0;
            $scores[] = $data['score'] * $weight;
            $total_weight += $weight;
        }

        // Weight SEO metrics scores
        foreach ($seo_metrics as $metric => $data) {
            $weight = $this->seo_metrics[$metric]['weight'] ?? 0;
            $scores[] = $data['score'] * $weight;
            $total_weight += $weight;
        }

        return $total_weight > 0 ? (int) round(array_sum($scores) / $total_weight) : 0;
    }

    /**
     * Determine performance grade based on score
     *
     * @since 1.0.0
     *
     * @param int $score Performance score
     * @return string Performance grade
     */
    private function determine_performance_grade(int $score): string {
        if ($score >= 90) {
            return 'A';
        } elseif ($score >= 80) {
            return 'B';
        } elseif ($score >= 70) {
            return 'C';
        } elseif ($score >= 60) {
            return 'D';
        } else {
            return 'F';
        }
    }

    /**
     * Generate performance recommendations
     *
     * @since 1.0.0
     *
     * @param array  $core_web_vitals Core Web Vitals data
     * @param array  $seo_metrics     SEO metrics data
     * @param string $url             URL being analyzed
     * @return array Performance recommendations
     */
    private function generate_performance_recommendations(array $core_web_vitals, array $seo_metrics, string $url): array {
        $recommendations = [];

        // Core Web Vitals recommendations
        foreach ($core_web_vitals as $metric => $data) {
            // Skip non-array values (like 'error' and 'message' keys)
            if (!is_array($data) || !isset($data['status'])) {
                continue;
            }
            if ($data['status'] === 'poor' || $data['status'] === 'needs_improvement') {
                $recommendations = array_merge($recommendations, $this->get_cwv_recommendations($metric, $data));
            }
        }

        // SEO metrics recommendations
        foreach ($seo_metrics as $metric => $data) {
            if ($data['status'] === 'poor' || $data['score'] < 80) {
                $recommendations = array_merge($recommendations, $this->get_seo_recommendations($metric, $data));
            }
        }

        // Sort recommendations by priority
        usort($recommendations, function ($a, $b) {
            $priority_order = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            return ($priority_order[$b['priority']] ?? 0) - ($priority_order[$a['priority']] ?? 0);
        });

        return $recommendations;
    }

    /**
     * Store performance data in database
     *
     * @since 1.0.0
     *
     * @param string $url        URL monitored
     * @param array  $monitoring Monitoring results
     * @return bool Success status
     */
    private function store_performance_data(string $url, array $monitoring): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_performance';

        // Store Core Web Vitals data
        foreach ($monitoring['core_web_vitals'] as $metric => $data) {
            // Skip non-array values (like 'error' and 'message' keys)
            if (!is_array($data) || !isset($data['value'], $data['unit'], $data['status'])) {
                continue;
            }
            $performance_data = [
                'context_type' => 'url',
                'context_id' => null,
                'metric_type' => $metric,
                'metric_value' => $data['value'],
                'metric_unit' => $data['unit'],
                'threshold_good' => $this->get_core_web_vitals_config()[$metric]['good_threshold'],
                'threshold_poor' => $this->get_core_web_vitals_config()[$metric]['needs_improvement_threshold'],
                'status' => $data['status'],
                'device_type' => $monitoring['device_type'],
                'measured_by' => 'performance_monitoring_manager'
            ];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance data storage requires direct database access
            $wpdb->insert($table_name, $performance_data);
        }

        // Store SEO metrics data
        foreach ($monitoring['seo_metrics'] as $metric => $data) {
            $performance_data = [
                'context_type' => 'url',
                'context_id' => null,
                'metric_type' => $metric,
                'metric_value' => $data['value'],
                'metric_unit' => $data['unit'],
                'threshold_good' => $data['target'],
                'threshold_poor' => $data['target'] * 0.7, // 70% of target as poor threshold
                'status' => $data['status'],
                'device_type' => $monitoring['device_type'],
                'measured_by' => 'performance_monitoring_manager'
            ];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance data storage requires direct database access
            $wpdb->insert($table_name, $performance_data);
        }

        return true;
    }

    /**
     * Get monitoring URL for context
     *
     * @since 1.0.0
     *
     * @param string   $context_type Context type
     * @param int|null $context_id   Context ID
     * @return string URL to monitor
     */
    private function get_monitoring_url(string $context_type, ?int $context_id): string {
        switch ($context_type) {
            case 'site':
                return home_url();
            case 'post':
            case 'page':
            case 'product':
                if ($context_id) {
                    return get_permalink($context_id) ?: '';
                }
                break;
        }

        return '';
    }

    /**
     * Calculate validation score
     *
     * @since 1.0.0
     *
     * @param array $validation Validation results
     * @return int Score (0-100)
     */
    private function calculate_validation_score(array $validation): int {
        $score = 100;
        $score -= count($validation['errors']) * 20;
        $score -= count($validation['warnings']) * 10;
        $score -= count($validation['suggestions']) * 5;

        return max(0, $score);
    }

    /**
     * Determine metric status based on thresholds
     *
     * @since 1.0.0
     *
     * @param float $value  Metric value
     * @param array $config Metric configuration
     * @return string Status (good, needs_improvement, poor)
     */
    private function determine_metric_status(float $value, array $config): string {
        if ($value <= $config['good_threshold']) {
            return 'good';
        } elseif ($value <= $config['needs_improvement_threshold']) {
            return 'needs_improvement';
        } else {
            return 'poor';
        }
    }

    /**
     * Calculate metric score based on value and thresholds
     *
     * @since 1.0.0
     *
     * @param float $value  Metric value
     * @param array $config Metric configuration
     * @return int Score (0-100)
     */
    private function calculate_metric_score(float $value, array $config): int {
        $good_threshold = $config['good_threshold'];
        $poor_threshold = $config['needs_improvement_threshold'];

        if ($value <= $good_threshold) {
            return 100;
        } elseif ($value <= $poor_threshold) {
            // Linear interpolation between good and poor thresholds
            $range = $poor_threshold - $good_threshold;
            $position = $value - $good_threshold;
            return (int) round(100 - (($position / $range) * 50));
        } else {
            // Exponential decay for values beyond poor threshold
            $excess = $value - $poor_threshold;
            $decay_factor = min(50, $excess * 10);
            return max(0, 50 - (int) round($decay_factor));
        }
    }

    /**
     * Determine SEO metric status
     *
     * @since 1.0.0
     *
     * @param float $value  Metric value
     * @param float $target Target value
     * @return string Status
     */
    private function determine_seo_metric_status(float $value, float $target): string {
        if ($value >= $target) {
            return 'excellent';
        } elseif ($value >= $target * 0.9) {
            return 'good';
        } elseif ($value >= $target * 0.7) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    /**
     * Calculate SEO metric score
     *
     * @since 1.0.0
     *
     * @param float $value  Metric value
     * @param float $target Target value
     * @return int Score (0-100)
     */
    private function calculate_seo_metric_score(float $value, float $target): int {
        if ($value >= $target) {
            return 100;
        } else {
            return (int) round(($value / $target) * 100);
        }
    }

    /**
     * Get Core Web Vitals recommendations
     *
     * @since 1.0.0
     *
     * @param string $metric Metric name
     * @param array  $data   Metric data
     * @return array Recommendations
     */
    private function get_cwv_recommendations(string $metric, array $data): array {
        $recommendations = [];

        switch ($metric) {
            case 'lcp':
                if ($data['value'] > 4.0) {
                    $recommendations[] = [
                        'type' => 'lcp_optimization',
                        'priority' => 'critical',
                        'message' => 'Largest Contentful Paint is critically slow',
                        'action' => 'Optimize server response time and reduce resource load times',
                        'impact' => 'high',
                        'effort' => 'high'
                    ];
                } elseif ($data['value'] > 2.5) {
                    $recommendations[] = [
                        'type' => 'lcp_optimization',
                        'priority' => 'high',
                        'message' => 'Largest Contentful Paint needs improvement',
                        'action' => 'Optimize images and reduce render-blocking resources',
                        'impact' => 'medium',
                        'effort' => 'medium'
                    ];
                }
                break;

            case 'fid':
                if ($data['value'] > 300) {
                    $recommendations[] = [
                        'type' => 'fid_optimization',
                        'priority' => 'critical',
                        'message' => 'First Input Delay is critically high',
                        'action' => 'Reduce JavaScript execution time and optimize main thread',
                        'impact' => 'high',
                        'effort' => 'high'
                    ];
                } elseif ($data['value'] > 100) {
                    $recommendations[] = [
                        'type' => 'fid_optimization',
                        'priority' => 'high',
                        'message' => 'First Input Delay needs improvement',
                        'action' => 'Break up long tasks and defer non-critical JavaScript',
                        'impact' => 'medium',
                        'effort' => 'medium'
                    ];
                }
                break;

            case 'cls':
                if ($data['value'] > 0.25) {
                    $recommendations[] = [
                        'type' => 'cls_optimization',
                        'priority' => 'critical',
                        'message' => 'Cumulative Layout Shift is critically high',
                        'action' => 'Set explicit dimensions for images and ads, avoid inserting content above existing content',
                        'impact' => 'high',
                        'effort' => 'medium'
                    ];
                } elseif ($data['value'] > 0.1) {
                    $recommendations[] = [
                        'type' => 'cls_optimization',
                        'priority' => 'high',
                        'message' => 'Cumulative Layout Shift needs improvement',
                        'action' => 'Optimize font loading and reserve space for dynamic content',
                        'impact' => 'medium',
                        'effort' => 'low'
                    ];
                }
                break;

            case 'fcp':
                if ($data['value'] > 3.0) {
                    $recommendations[] = [
                        'type' => 'fcp_optimization',
                        'priority' => 'critical',
                        'message' => 'First Contentful Paint is critically slow',
                        'action' => 'Optimize server response time, reduce render-blocking resources, and improve resource delivery',
                        'impact' => 'high',
                        'effort' => 'high'
                    ];
                } elseif ($data['value'] > 1.8) {
                    $recommendations[] = [
                        'type' => 'fcp_optimization',
                        'priority' => 'high',
                        'message' => 'First Contentful Paint needs improvement',
                        'action' => 'Optimize critical rendering path and reduce server response time',
                        'impact' => 'medium',
                        'effort' => 'medium'
                    ];
                }
                break;
        }

        return $recommendations;
    }

    /**
     * Get SEO recommendations
     *
     * @since 1.0.0
     *
     * @param string $metric Metric name
     * @param array  $data   Metric data
     * @return array Recommendations
     */
    private function get_seo_recommendations(string $metric, array $data): array {
        $recommendations = [];

        switch ($metric) {
            case 'page_speed_score':
                if ($data['value'] < 50) {
                    $recommendations[] = [
                        'type' => 'page_speed',
                        'priority' => 'critical',
                        'message' => 'Page speed score is critically low',
                        'action' => 'Implement comprehensive performance optimization',
                        'impact' => 'high',
                        'effort' => 'high'
                    ];
                } elseif ($data['value'] < 80) {
                    $recommendations[] = [
                        'type' => 'page_speed',
                        'priority' => 'high',
                        'message' => 'Page speed score needs improvement',
                        'action' => 'Optimize images, minify CSS/JS, and enable compression',
                        'impact' => 'medium',
                        'effort' => 'medium'
                    ];
                }
                break;

            case 'seo_score':
                if ($data['value'] < 60) {
                    $recommendations[] = [
                        'type' => 'seo_optimization',
                        'priority' => 'high',
                        'message' => 'SEO score is below target',
                        'action' => 'Improve meta tags, headings, and content structure',
                        'impact' => 'high',
                        'effort' => 'medium'
                    ];
                }
                break;

            case 'accessibility_score':
                if ($data['value'] < 70) {
                    $recommendations[] = [
                        'type' => 'accessibility',
                        'priority' => 'medium',
                        'message' => 'Accessibility score needs improvement',
                        'action' => 'Add alt text to images, improve color contrast, and enhance keyboard navigation',
                        'impact' => 'medium',
                        'effort' => 'low'
                    ];
                }
                break;
        }

        return $recommendations;
    }

    /**
     * Simple implementations for methods referenced but not yet implemented
     * These would be enhanced with actual API integrations in production
     */

    private function get_field_data(string $url, string $device_type): array {
        try {
            // Get Google OAuth access token for Chrome UX Report access
            $settings = $this->settings_manager->get_settings('integrations', 'site', null);
            $access_token = $settings['google_access_token'] ?? '';

            if (empty($access_token)) {
                return ['field_data' => [], 'available' => false];
            }

            // Use PageSpeed Insights API which includes Chrome UX Report data.
            // The snapshot is shared with CWV/opportunities/diagnostics callers,
            // so this does not trigger an extra Lighthouse run.
            $pagespeed_client = Google_PageSpeed_Client::for_site();
            $loading_experience = $pagespeed_client->get_pagespeed_snapshot($url, $device_type)['loading_experience'];

            if (!empty($loading_experience)) {
                return [
                    'field_data' => [
                        'lcp' => $loading_experience['metrics']['LARGEST_CONTENTFUL_PAINT_MS'] ?? [],
                        'fid' => $loading_experience['metrics']['FIRST_INPUT_DELAY_MS'] ?? [],
                        'cls' => $loading_experience['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE'] ?? [],
                        'overall_category' => $loading_experience['overall_category'] ?? 'UNKNOWN'
                    ],
                    'available' => true
                ];
            }

            return ['field_data' => [], 'available' => false];

        } catch (\Exception $e) {
            return ['field_data' => [], 'available' => false];
        }
    }
    private function get_field_data_for_metric(string $url, string $metric, string $device_type): ?array {
        // No field data available without API key
        return null;
    }

    private function get_lab_data_for_metric(string $url, string $metric, string $device_type): ?array {
        // No lab data available without API key
        return null;
    }

    private function get_pagespeed_insights_data(string $url, string $device_type): array {
        try {
            // Get Google OAuth access token for PageSpeed data
            $settings = $this->settings_manager->get_settings('integrations', 'site', null);
            $access_token = $settings['google_access_token'] ?? '';

            if (empty($access_token)) {
                return ['page_speed_score' => 0];
            }

            // Create Google PageSpeed client and get performance data (shared
            // snapshot — no extra Lighthouse run when CWV was already fetched).
            $pagespeed_client = Google_PageSpeed_Client::for_site();
            $snapshot = $pagespeed_client->get_pagespeed_snapshot($url, $device_type);

            return ['page_speed_score' => $snapshot['performance_score']];
        } catch (\Exception $e) {
            return ['page_speed_score' => 0];
        }
    }

    private function get_lighthouse_data(string $url, string $device_type): array {
        // No Lighthouse data available without API key
        return [];
    }

    private function get_mobile_friendly_data(string $url): array {
        // No mobile-friendly data available without API key
        return [];
    }

    private function compare_with_historical_data(string $url, array $monitoring): array {
        return ['historical_comparison' => []];
    }

    private function compare_with_benchmarks(array $monitoring, array $options): array {
        return ['benchmark_comparison' => []];
    }

    // Placeholder implementations for methods referenced in interface methods
    private function get_current_performance_data(string $url, string $context_type, ?int $context_id): array {
        return ['performance_data' => []];
    }

    private function generate_performance_dashboard(array $performance_data): array {
        return ['dashboard_widgets' => []];
    }

    private function get_core_web_vitals_data(string $url, string $context_type, ?int $context_id, string $device_type = 'mobile'): array {
        try {
            // Get Google OAuth access token for PageSpeed data
            $settings = $this->settings_manager->get_settings('integrations', 'site', null);
            $access_token = $settings['google_access_token'] ?? '';

            if (empty($access_token)) {
                // Return error state when Google account is not connected
                return [
                    'error' => 'Google account not connected',
                    'message' => 'Please connect your Google account in Integrations > Google Services to view real Core Web Vitals data.',
                    'lcp' => $this->get_empty_metric_data('Largest Contentful Paint', 's', 2.5, 4.0),
                    'fid' => $this->get_empty_metric_data('First Input Delay', 'ms', 100, 300),
                    'cls' => $this->get_empty_metric_data('Cumulative Layout Shift', '', 0.1, 0.25),
                    'fcp' => $this->get_empty_metric_data('First Contentful Paint', 's', 1.8, 3.0)
                ];
            }

            // Validate device type
            $device_type = in_array($device_type, ['mobile', 'desktop'], true) ? $device_type : 'mobile';

            // Create Google PageSpeed client with OAuth token
            $pagespeed_client = Google_PageSpeed_Client::for_site();
            $core_web_vitals = $pagespeed_client->get_core_web_vitals($url, $device_type);

            // Transform the data to match expected format
            return [
                'lcp' => [
                    'name' => $core_web_vitals['lcp']['name'],
                    'value' => $core_web_vitals['lcp']['value'],
                    'unit' => $core_web_vitals['lcp']['unit'],
                    'good_threshold' => $core_web_vitals['lcp']['good_threshold'],
                    'needs_improvement_threshold' => $core_web_vitals['lcp']['needs_improvement_threshold'],
                    'description' => $core_web_vitals['lcp']['description'],
                    'score' => $core_web_vitals['lcp']['score'],
                    'status' => $this->determine_metric_status($core_web_vitals['lcp']['value'], [
                        'good_threshold' => $core_web_vitals['lcp']['good_threshold'],
                        'needs_improvement_threshold' => $core_web_vitals['lcp']['needs_improvement_threshold']
                    ])
                ],
                'fid' => [
                    'name' => $core_web_vitals['fid']['name'],
                    'value' => $core_web_vitals['fid']['value'],
                    'unit' => $core_web_vitals['fid']['unit'],
                    'good_threshold' => $core_web_vitals['fid']['good_threshold'],
                    'needs_improvement_threshold' => $core_web_vitals['fid']['needs_improvement_threshold'],
                    'description' => $core_web_vitals['fid']['description'],
                    'score' => $core_web_vitals['fid']['score'],
                    'status' => $this->determine_metric_status($core_web_vitals['fid']['value'], [
                        'good_threshold' => $core_web_vitals['fid']['good_threshold'],
                        'needs_improvement_threshold' => $core_web_vitals['fid']['needs_improvement_threshold']
                    ])
                ],
                'cls' => [
                    'name' => $core_web_vitals['cls']['name'],
                    'value' => $core_web_vitals['cls']['value'],
                    'unit' => $core_web_vitals['cls']['unit'],
                    'good_threshold' => $core_web_vitals['cls']['good_threshold'],
                    'needs_improvement_threshold' => $core_web_vitals['cls']['needs_improvement_threshold'],
                    'description' => $core_web_vitals['cls']['description'],
                    'score' => $core_web_vitals['cls']['score'],
                    'status' => $this->determine_metric_status($core_web_vitals['cls']['value'], [
                        'good_threshold' => $core_web_vitals['cls']['good_threshold'],
                        'needs_improvement_threshold' => $core_web_vitals['cls']['needs_improvement_threshold']
                    ])
                ],
                'fcp' => [
                    'name' => $core_web_vitals['fcp']['name'],
                    'value' => $core_web_vitals['fcp']['value'],
                    'unit' => $core_web_vitals['fcp']['unit'],
                    'good_threshold' => $core_web_vitals['fcp']['good_threshold'],
                    'needs_improvement_threshold' => $core_web_vitals['fcp']['needs_improvement_threshold'],
                    'description' => $core_web_vitals['fcp']['description'],
                    'score' => $core_web_vitals['fcp']['score'],
                    'status' => $this->determine_metric_status($core_web_vitals['fcp']['value'], [
                        'good_threshold' => $core_web_vitals['fcp']['good_threshold'],
                        'needs_improvement_threshold' => $core_web_vitals['fcp']['needs_improvement_threshold']
                    ])
                ]
            ];
        } catch (\Exception $e) {
            // Get user-friendly error information
            $error_info = $this->get_user_friendly_error($e);

            return [
                'error' => $error_info['title'],
                'message' => $error_info['message'],
                'error_type' => $error_info['type'],
                'suggested_action' => $error_info['action'],
                'lcp' => $this->get_empty_metric_data('Largest Contentful Paint', 's', 2.5, 4.0),
                'fid' => $this->get_empty_metric_data('First Input Delay', 'ms', 100, 300),
                'cls' => $this->get_empty_metric_data('Cumulative Layout Shift', '', 0.1, 0.25),
                'fcp' => $this->get_empty_metric_data('First Contentful Paint', 's', 1.8, 3.0)
            ];
        }
    }

    private function get_seo_metrics_data(string $url, string $context_type, ?int $context_id): array {
        // No SEO metrics data available without API key
        return [];
    }

    private function get_performance_trends_data(string $context_type, ?int $context_id): array {
        return ['trends' => []];
    }

    private function get_active_alerts_data(string $context_type, ?int $context_id): array {
        return ['alerts' => []];
    }

    private function get_performance_recommendations_data(array $performance_data): array {
        return ['recommendations' => []];
    }

    // Report generation helper methods
    private function define_report_period(string $report_type): array {
        $now = current_time('timestamp');
        switch ($report_type) {
            case 'daily':
                return ['start' => $now - DAY_IN_SECONDS, 'end' => $now];
            case 'weekly':
                return ['start' => $now - WEEK_IN_SECONDS, 'end' => $now];
            case 'monthly':
                return ['start' => $now - MONTH_IN_SECONDS, 'end' => $now];
            default:
                return ['start' => $now - WEEK_IN_SECONDS, 'end' => $now];
        }
    }

    private function generate_performance_summary(string $context_type, ?int $context_id, array $period): array {
        return ['summary' => []];
    }

    private function analyze_core_web_vitals_trends(string $context_type, ?int $context_id, array $period): array {
        return ['cwv_trends' => []];
    }

    private function analyze_seo_metrics_trends(string $context_type, ?int $context_id, array $period): array {
        return ['seo_trends' => []];
    }

    private function calculate_performance_trends(string $context_type, ?int $context_id, array $period): array {
        return ['trends' => []];
    }

    private function identify_optimization_opportunities(array $summary, array $trends): array {
        return ['opportunities' => []];
    }

    private function perform_competitive_analysis(string $context_type, ?int $context_id, array $options): array {
        return ['competitive_data' => []];
    }

    private function generate_action_items(array $opportunities, array $competitive_analysis): array {
        return ['action_items' => []];
    }

    private function store_report_data(array $report): bool {
        return true;
    }

    // Alert system helper methods
    private function configure_alert_rules(array $alert_config): array {
        return ['rules' => []];
    }

    private function setup_notification_channels(array $alert_config): array {
        return ['channels' => []];
    }

    private function get_alert_history(string $context_type, ?int $context_id): array {
        return ['history' => []];
    }

    private function check_active_alerts(string $context_type, ?int $context_id): array {
        return ['active' => []];
    }

    // ========================================
    // PUBLIC API METHODS FOR FRONTEND
    // ========================================

    /**
     * Get Core Web Vitals data (Public API method)
     *
     * @since 1.0.0
     *
     * @param string $url Optional URL to analyze (defaults to home URL)
     * @param bool   $store_data Whether to store the data for historical tracking
     * @param string $device_type Device type ('mobile' or 'desktop')
     * @return array Core Web Vitals data
     */
    public function get_core_web_vitals(string $url = '', bool $store_data = false, string $device_type = 'mobile'): array {
	    if ( empty( $url ) ) {
		    $url = home_url();
	    }

        // Validate device type
        $device_type = in_array($device_type, ['mobile', 'desktop'], true) ? $device_type : 'mobile';

        // Check cache first (5-minute TTL for API responses) - include device type in cache key
        $cache_key = 'thinkrank_core_web_vitals_' . md5($url . '_' . $device_type);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $core_web_vitals = $this->get_core_web_vitals_data($url, 'homepage', null, $device_type);

        // Cache the result for 5 minutes
        if (!empty($core_web_vitals) && !isset($core_web_vitals['error'])) {
            set_transient($cache_key, $core_web_vitals, 5 * MINUTE_IN_SECONDS);
        }

        // Store data for historical tracking if requested
        if ($store_data && !empty($core_web_vitals)) {
            $this->store_historical_performance_data($core_web_vitals, 'site', null, $device_type);
        }

        return $core_web_vitals;
    }

    /**
     * Build a full performance snapshot for the monitor endpoint without a
     * blocking live audit.
     *
     * Serving order (fast → slow):
     *   1. Warm 5-minute audit cache (a prior audit is still fresh).
     *   2. Most recent measurement collected by the daily cron (DB), reshaped
     *      to the same structure a live audit returns.
     *   3. When there is neither cached nor collected data yet, a background
     *      collection is scheduled and a lightweight "collecting" state is
     *      returned instead of blocking the request on a 10-40s Lighthouse run.
     *
     * @since 1.16.2
     *
     * @param string $device_type Device type ('mobile' or 'desktop').
     * @return array Response payload matching the /performance/monitor shape.
     */
    public function get_performance_snapshot(string $device_type = 'mobile'): array {
        $url         = home_url();
        $device_type = in_array($device_type, ['mobile', 'desktop'], true) ? $device_type : 'mobile';

        $stored = null;
        $source = 'live';

        // 1. Warm audit cache (fast path, no live call).
        $cache_key       = 'thinkrank_core_web_vitals_' . md5($url . '_' . $device_type);
        $core_web_vitals = get_transient($cache_key);

        if ($core_web_vitals !== false) {
            $source = 'cache';
        } else {
            // 2. Most recent measurement stored by the daily collector.
            $stored = $this->get_latest_stored_core_web_vitals($device_type);

            if ($stored !== null) {
                $core_web_vitals = $stored['core_web_vitals'];
                $source          = 'collected';
            } elseif ($this->has_pagespeed_credentials()) {
                // 3. Connected but nothing collected yet — schedule a background
                //    collection and return a non-blocking "collecting" state.
                $this->schedule_background_collection();
                return $this->build_collecting_snapshot($device_type);
            } else {
                // Not connected — the (internally cached) getter returns the
                // error structure that drives the "connect Google" empty state.
                $core_web_vitals = $this->get_core_web_vitals($url, false, $device_type);
                $source          = 'live';
            }
        }

        // Prefer the real stored Lighthouse score for collected data; otherwise
        // derive it from the metric set (matches the prior endpoint behaviour).
        if ($source === 'collected' && $stored !== null && $stored['performance_score'] !== null) {
            $performance_score = (int) round($stored['performance_score']);
        } else {
            $performance_score = $this->get_performance_score($core_web_vitals);
        }

        $performance_grade = $this->get_performance_grade($performance_score);
        $seo_correlation   = $this->get_seo_performance_correlation($core_web_vitals, $performance_score);

        return [
            'success' => true,
            'data'    => [
                'core_web_vitals'   => $core_web_vitals,
                'performance_score' => $performance_score,
                'performance_grade' => $performance_grade,
                'seo_correlation'   => $seo_correlation,
                'device_type'       => $device_type,
                'last_updated'      => current_time('mysql'),
                'status'            => 'success',
            ],
            'message' => __('Performance data retrieved successfully', 'thinkrank'),
        ];
    }

    /**
     * Fetch the most recent Core Web Vitals measurement collected by the daily
     * cron and reshape it into the structure a live audit returns.
     *
     * Metrics the collector does not persist (e.g. fcp) fall back to an
     * "unavailable" placeholder so the card layout stays consistent.
     *
     * @since 1.16.2
     *
     * @param string $device_type Device type ('mobile' or 'desktop').
     * @return array|null { core_web_vitals: array, performance_score: float|null } or null when nothing is stored.
     */
    private function get_latest_stored_core_web_vitals(string $device_type): ?array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_performance';

        // Most recent collection timestamp for this device.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reading collected performance data requires a direct query
        $measured_at = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is derived from $wpdb->prefix
            $wpdb->prepare(
                "SELECT measured_at FROM {$table_name}
                 WHERE context_type = %s AND device_type = %s AND measured_by = %s
                 ORDER BY measured_at DESC LIMIT 1",
                'site',
                $device_type,
                'google_pagespeed'
            )
        );

        if (empty($measured_at)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reading collected performance data requires a direct query
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is derived from $wpdb->prefix
            $wpdb->prepare(
                "SELECT metric_type, metric_value, metric_unit, status FROM {$table_name}
                 WHERE context_type = %s AND device_type = %s AND measured_by = %s AND measured_at = %s",
                'site',
                $device_type,
                'google_pagespeed',
                $measured_at
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return null;
        }

        $stored_metrics    = [];
        $performance_score = null;

        foreach ($rows as $row) {
            if ($row['metric_type'] === 'performance_score') {
                $performance_score = (float) $row['metric_value'];
                continue;
            }
            $stored_metrics[$row['metric_type']] = $row;
        }

        $config          = $this->get_core_web_vitals_config();
        $core_web_vitals = [];

        foreach ($config as $metric => $cfg) {
            if (!isset($stored_metrics[$metric])) {
                // Not collected for this metric (e.g. fcp) — placeholder card.
                $core_web_vitals[$metric] = $this->get_empty_metric_data(
                    $cfg['name'],
                    $cfg['unit'],
                    (float) $cfg['good_threshold'],
                    (float) $cfg['needs_improvement_threshold']
                );
                continue;
            }

            $row   = $stored_metrics[$metric];
            $value = round((float) $row['metric_value'], 4);
            $good  = (float) $cfg['good_threshold'];
            $poor  = (float) $cfg['needs_improvement_threshold'];

            $status = $row['status'];
            if (empty($status)) {
                $status = $value <= $good ? 'good' : ($value <= $poor ? 'needs_improvement' : 'poor');
            }

            $core_web_vitals[$metric] = [
                'name'                        => $cfg['name'],
                'value'                       => $value,
                'unit'                        => $row['metric_unit'] !== '' ? $row['metric_unit'] : $cfg['unit'],
                'good_threshold'              => $good,
                'needs_improvement_threshold' => $poor,
                'description'                 => $cfg['description'],
                'score'                       => $status === 'good' ? 100 : ($status === 'needs_improvement' ? 65 : 30),
                'status'                      => $status,
            ];
        }

        return [
            'core_web_vitals'   => $core_web_vitals,
            'performance_score' => $performance_score,
        ];
    }

    /**
     * Build a lightweight "collecting" snapshot returned while a background
     * collection runs, so the endpoint never blocks on a live audit.
     *
     * @since 1.16.2
     *
     * @param string $device_type Device type ('mobile' or 'desktop').
     * @return array Response payload matching the /performance/monitor shape.
     */
    private function build_collecting_snapshot(string $device_type): array {
        $config          = $this->get_core_web_vitals_config();
        $core_web_vitals = [];

        foreach ($config as $metric => $cfg) {
            $core_web_vitals[$metric] = $this->get_empty_metric_data(
                $cfg['name'],
                $cfg['unit'],
                (float) $cfg['good_threshold'],
                (float) $cfg['needs_improvement_threshold']
            );
        }

        $data = [
            'core_web_vitals'   => $core_web_vitals,
            'performance_score' => 0,
            'performance_grade' => $this->get_performance_grade(0),
            'seo_correlation'   => $this->get_seo_performance_correlation($core_web_vitals, 0),
            'device_type'       => $device_type,
            'last_updated'      => current_time('mysql'),
            'status'            => 'collecting',
        ];

        // If the last background attempt failed, surface WHY instead of an
        // endless "collecting" state. The PageSpeed client remembers the last
        // failure per url|device for a few minutes (e.g. quota exhausted —
        // fixable by adding a site-owned PageSpeed API key in Integrations).
        $failure = get_transient('thinkrank_psi_failure_' . md5(home_url() . '|' . $device_type));
        if (is_string($failure) && $failure !== '') {
            $data['collection_error'] = $failure;
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => __('Collecting performance data. This can take a minute — please refresh shortly.', 'thinkrank'),
        ];
    }

    /**
     * Whether a Google OAuth token is available for PageSpeed requests.
     *
     * @since 1.16.2
     *
     * @return bool
     */
    private function has_pagespeed_credentials(): bool {
        $settings = $this->settings_manager->get_settings('integrations', 'site', null);
        return !empty($settings['google_access_token'] ?? '');
    }

    /**
     * Schedule a one-off background performance collection so a cold cache can
     * be warmed out-of-band instead of blocking the request. A short-lived
     * transient lock prevents scheduling a burst of duplicate events.
     *
     * @since 1.16.2
     *
     * @return void
     */
    private function schedule_background_collection(): void {
        if (get_transient('thinkrank_cwv_collect_lock')) {
            return;
        }
        set_transient('thinkrank_cwv_collect_lock', 1, 15 * MINUTE_IN_SECONDS);
        // Due immediately so the spawn_cron() kick below can pick it up.
        wp_schedule_single_event(time() - 1, 'thinkrank_collect_performance_data');

        // Kick WP-Cron immediately (non-blocking loopback) instead of waiting
        // for organic traffic to spawn it — on quiet or cron-impaired sites
        // the "collecting" state would otherwise linger indefinitely.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Calculate overall performance score (Public API method)
     *
     * @since 1.0.0
     *
     * @param array|null $core_web_vitals Optional Core Web Vitals data. If not provided, will fetch with default device type.
     * @return int Performance score (0-100)
     */
    public function get_performance_score(?array $core_web_vitals = null): int {
        // If Core Web Vitals data not provided, fetch it
        if ($core_web_vitals === null) {
            $core_web_vitals = $this->get_core_web_vitals();
        }

        // Calculate weighted average of all metrics
        $total_score = 0;
        $total_weight = 0;

        foreach ($core_web_vitals as $metric => $data) {
            // Skip non-array values (like 'error' and 'message' keys)
            if (!is_array($data) || !isset($data['score'])) {
                continue;
            }
            $weight = $this->get_core_web_vitals_config()[$metric]['weight'] ?? 25;
            $total_score += $data['score'] * $weight;
            $total_weight += $weight;
        }

        return $total_weight > 0 ? (int) round($total_score / $total_weight) : 0;
    }

    /**
     * Get performance grade based on score
     *
     * @since 1.0.0
     *
     * @param int $score Performance score
     * @return string Performance grade (A-F)
     */
    public function get_performance_grade(int $score): string {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    /**
     * Get SEO performance correlation
     *
     * @since 1.0.0
     *
     * @param array|null $core_web_vitals Optional Core Web Vitals data. If not provided, will fetch with default device type.
     * @param int|null $performance_score Optional performance score. If not provided, will calculate from Core Web Vitals.
     * @return array SEO performance correlation data
     */
    public function get_seo_performance_correlation(?array $core_web_vitals = null, ?int $performance_score = null): array {
        // If Core Web Vitals data not provided, fetch it
        if ($core_web_vitals === null) {
            $core_web_vitals = $this->get_core_web_vitals();
        }

        // If performance score not provided, calculate it from Core Web Vitals
        if ($performance_score === null) {
            $performance_score = $this->get_performance_score($core_web_vitals);
        }

        // Calculate SEO impact based on Core Web Vitals
        $seo_impact = $this->calculate_seo_impact($core_web_vitals, $performance_score);

        return [
            'seo_score' => $seo_impact['seo_score'],
            'performance_impact' => $seo_impact['impact_level'],
            'ranking_factors' => [
                'core_web_vitals' => $this->get_cwv_ranking_impact($core_web_vitals),
                'page_speed' => $performance_score >= 90 ? 'positive_factor' : 'needs_improvement',
                'mobile_usability' => $this->assess_mobile_usability($core_web_vitals)
            ],
            'recommendations' => $this->generate_seo_recommendations($core_web_vitals, $performance_score),
            'correlation_strength' => $seo_impact['correlation_strength'],
            'potential_ranking_change' => $seo_impact['ranking_change_estimate']
        ];
    }

    /**
     * Calculate SEO impact based on performance metrics
     *
     * @param array $core_web_vitals Core Web Vitals data
     * @param int   $performance_score Overall performance score
     * @return array SEO impact analysis
     */
    private function calculate_seo_impact(array $core_web_vitals, int $performance_score): array {
        $good_metrics = 0;
        $total_metrics = 0;

        foreach ($core_web_vitals as $metric => $data) {
            if (is_array($data) && isset($data['status'])) {
                $total_metrics++;
                if ($data['status'] === 'good') {
                    $good_metrics++;
                }
            }
        }

        $cwv_pass_rate = $total_metrics > 0 ? ($good_metrics / $total_metrics) * 100 : 0;

        // Calculate SEO score based on CWV pass rate and performance score
        $seo_score = (int) round(($cwv_pass_rate * 0.6) + ($performance_score * 0.4));

        // Determine impact level
        $impact_level = 'minimal';
        if ($cwv_pass_rate >= 75) {
            $impact_level = 'positive';
        } elseif ($cwv_pass_rate >= 50) {
            $impact_level = 'moderate';
        } elseif ($cwv_pass_rate >= 25) {
            $impact_level = 'negative';
        } else {
            $impact_level = 'critical';
        }

        return [
            'seo_score' => $seo_score,
            'impact_level' => $impact_level,
            'correlation_strength' => $cwv_pass_rate >= 75 ? 'strong' : ($cwv_pass_rate >= 50 ? 'moderate' : 'weak'),
            'ranking_change_estimate' => $this->estimate_ranking_change($cwv_pass_rate, $performance_score)
        ];
    }

    /**
     * Estimate potential ranking change based on performance improvements
     *
     * @param float $cwv_pass_rate Core Web Vitals pass rate
     * @param int   $performance_score Performance score
     * @return string Ranking change estimate
     */
    private function estimate_ranking_change(float $cwv_pass_rate, int $performance_score): string {
        if ($cwv_pass_rate >= 75 && $performance_score >= 90) {
            return 'potential_improvement_5_10_positions';
        } elseif ($cwv_pass_rate >= 50 && $performance_score >= 70) {
            return 'potential_improvement_2_5_positions';
        } elseif ($cwv_pass_rate >= 25) {
            return 'potential_improvement_1_3_positions';
        } else {
            return 'potential_ranking_decline';
        }
    }

    /**
     * Get Core Web Vitals ranking impact
     *
     * @param array $core_web_vitals Core Web Vitals data
     * @return string Impact level
     */
    private function get_cwv_ranking_impact(array $core_web_vitals): string {
        $good_count = 0;
        $total_count = 0;

        foreach ($core_web_vitals as $metric => $data) {
            if (is_array($data) && isset($data['status'])) {
                $total_count++;
                if ($data['status'] === 'good') {
                    $good_count++;
                }
            }
        }

        if ($total_count === 0) return 'unknown';

        $pass_rate = ($good_count / $total_count) * 100;

        if ($pass_rate >= 75) return 'positive_ranking_signal';
        if ($pass_rate >= 50) return 'neutral_ranking_signal';
        return 'negative_ranking_signal';
    }

    /**
     * Assess mobile usability impact
     *
     * @param array $core_web_vitals Core Web Vitals data
     * @return string Mobile usability assessment
     */
    private function assess_mobile_usability(array $core_web_vitals): string {
        // Focus on CLS and INP for mobile usability
        $cls_good = isset($core_web_vitals['cls']['status']) && $core_web_vitals['cls']['status'] === 'good';
        $inp_good = isset($core_web_vitals['inp']['status']) && $core_web_vitals['inp']['status'] === 'good';

        if ($cls_good && $inp_good) return 'excellent_mobile_experience';
        if ($cls_good || $inp_good) return 'good_mobile_experience';
        return 'needs_mobile_optimization';
    }

    /**
     * Generate SEO-focused recommendations
     *
     * @param array $core_web_vitals Core Web Vitals data
     * @param int   $performance_score Performance score
     * @return array SEO recommendations
     */
    private function generate_seo_recommendations(array $core_web_vitals, int $performance_score): array {
        $recommendations = [];

        foreach ($core_web_vitals as $metric => $data) {
            if (is_array($data) && isset($data['status']) && $data['status'] !== 'good') {
                switch ($metric) {
                    case 'lcp':
                        $recommendations[] = 'Improve LCP to enhance page experience ranking signal';
                        break;
                    case 'fid':
                        $recommendations[] = 'Optimize FID to improve user interaction experience';
                        break;
                    case 'cls':
                        $recommendations[] = 'Reduce CLS to prevent layout shifts affecting user experience';
                        break;
                    case 'inp':
                        $recommendations[] = 'Optimize INP to improve page responsiveness for better rankings';
                        break;
                }
            }
        }

        if ($performance_score < 70) {
            $recommendations[] = 'Improve overall page speed to meet Google\'s performance standards';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Maintain current performance levels to preserve SEO benefits';
        }

        return $recommendations;
    }

    /**
     * Get performance recommendations
     *
     * @since 1.0.0
     *
     * @param array|null $core_web_vitals Optional Core Web Vitals data. If not provided, will fetch with default device type.
     * @return array Performance recommendations
     */
    public function get_performance_recommendations(?array $core_web_vitals = null): array {
        // If Core Web Vitals data not provided, fetch it
        if ($core_web_vitals === null) {
            $core_web_vitals = $this->get_core_web_vitals();
        }

        // Calculate performance score from the provided Core Web Vitals
        $performance_score = $this->get_performance_score($core_web_vitals);

        $recommendations = [
            'critical' => [],
            'important' => [],
            'minor' => [],
            'summary' => $this->generate_recommendations_summary($core_web_vitals, $performance_score)
        ];

        // Generate recommendations based on Core Web Vitals
        foreach ($core_web_vitals as $metric => $data) {
            // Skip non-array values (like 'error' and 'message' keys)
            if (!is_array($data) || !isset($data['status'])) {
                continue;
            }

            $enhanced_recommendation = $this->get_enhanced_metric_recommendation($metric, $data, $performance_score);

            if ($data['status'] === 'poor') {
                $recommendations['critical'][] = $enhanced_recommendation;
            } elseif ($data['status'] === 'needs_improvement') {
                $recommendations['important'][] = $enhanced_recommendation;
            } else {
                $recommendations['minor'][] = $enhanced_recommendation;
            }
        }

        return $recommendations;
    }

    /**
     * Generate recommendations summary
     *
     * @param array $core_web_vitals Core Web Vitals data
     * @param int   $performance_score Performance score
     * @return array Summary information
     */
    private function generate_recommendations_summary(array $core_web_vitals, int $performance_score): array {
        $issues_count = 0;
        $critical_issues = 0;

        foreach ($core_web_vitals as $metric => $data) {
            if (is_array($data) && isset($data['status'])) {
                if ($data['status'] === 'poor') {
                    $critical_issues++;
                    $issues_count++;
                } elseif ($data['status'] === 'needs_improvement') {
                    $issues_count++;
                }
            }
        }

        return [
            'total_issues' => $issues_count,
            'critical_issues' => $critical_issues,
            'performance_grade' => $this->get_performance_grade($performance_score),
            'estimated_improvement_time' => $issues_count > 2 ? '2-4 weeks' : ($issues_count > 0 ? '1-2 weeks' : 'maintenance_mode')
        ];
    }

    /**
     * Get enhanced metric recommendation with actionable insights
     *
     * @param string $metric Metric name
     * @param array  $data   Metric data
     * @param int    $performance_score Overall performance score
     * @return array Enhanced recommendation
     */
    private function get_enhanced_metric_recommendation(string $metric, array $data, int $performance_score): array {
        $base_recommendation = $this->get_metric_recommendation($metric, $data);

        // Add implementation difficulty and expected timeframe
        $difficulty_map = [
            'lcp' => 'medium',
            'fid' => 'hard',
            'cls' => 'easy',
            'inp' => 'medium'
        ];

        $current_value = $data['value'] ?? 0;
        $good_threshold = $data['good_threshold'] ?? 0;
        $improvement_needed = $current_value > $good_threshold ?
            (($current_value - $good_threshold) / $current_value) * 100 : 0;

        $timeframe = $improvement_needed > 50 ? '2-4 weeks' :
                    ($improvement_needed > 25 ? '1-2 weeks' : '2-5 days');

        return array_merge($base_recommendation, [
            'implementation_difficulty' => $difficulty_map[$metric] ?? 'medium',
            'expected_timeframe' => $timeframe,
            'improvement_potential' => round($improvement_needed, 1) . '%',
            'priority_score' => $this->calculate_priority_score($metric, $data, $performance_score)
        ]);
    }

    /**
     * Calculate priority score for recommendations
     *
     * @param string $metric Metric name
     * @param array  $data   Metric data
     * @param int    $performance_score Overall performance score
     * @return int Priority score (1-10, higher = more important)
     */
    private function calculate_priority_score(string $metric, array $data, int $performance_score): int {
        $base_score = 5;

        // Increase priority for poor metrics
        if ($data['status'] === 'poor') {
            $base_score += 3;
        } elseif ($data['status'] === 'needs_improvement') {
            $base_score += 1;
        }

        // LCP has highest impact on user experience
        if ($metric === 'lcp') {
            $base_score += 2;
        }

        // CLS has high impact on user frustration
        if ($metric === 'cls') {
            $base_score += 1;
        }

        // Lower overall performance score increases all priorities
        if ($performance_score < 50) {
            $base_score += 2;
        } elseif ($performance_score < 70) {
            $base_score += 1;
        }

        return min(10, max(1, $base_score));
    }

    /**
     * Get historical performance data from database
     *
     * @since 1.0.0
     *
     * @param int    $days   Number of days of history
     * @param string $metric Specific metric or 'all'
     * @return array Historical data
     */
    public function get_historical_data(int $days = 30, string $metric = 'all'): array {
        // Check cache first (1-hour TTL for historical data)
        $cache_key = "thinkrank_historical_data_{$days}_{$metric}";
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_performance';
        $start_date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        try {
            // Build query based on metric filter
            if ($metric === 'all') {
                $sql = sprintf("
                    SELECT
                        DATE(measured_at) as date,
                        metric_type,
                        AVG(metric_value) as avg_value
                    FROM %s
                    WHERE measured_at >= %%s
                        AND context_type = 'site'
                        AND metric_type IN ('lcp', 'fid', 'cls', 'inp', 'performance_score')
                    GROUP BY DATE(measured_at), metric_type
                    ORDER BY date ASC
                ", $table_name);
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance metrics retrieval requires direct database access
                $results = $wpdb->get_results(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                    $wpdb->prepare($sql, $start_date)
                );
            } else {
                $sql = sprintf("
                    SELECT
                        DATE(measured_at) as date,
                        metric_type,
                        AVG(metric_value) as avg_value
                    FROM %s
                    WHERE measured_at >= %%s
                        AND context_type = 'site'
                        AND metric_type = %%s
                    GROUP BY DATE(measured_at), metric_type
                    ORDER BY date ASC
                ", $table_name);
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance metrics retrieval requires direct database access
                $results = $wpdb->get_results(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
                    $wpdb->prepare($sql, $start_date, $metric)
                );
            }

            if (empty($results)) {
                // No data found, return empty array
                return [];
            }

            // Organize data by date and metric
            $organized_data = [];
            foreach ($results as $row) {
                $date = $row->date;
                $metric_type = $row->metric_type;
                $value = (float) $row->avg_value;

                if (!isset($organized_data[$date])) {
                    $organized_data[$date] = [];
                }

                $organized_data[$date][$metric_type] = $value;
            }

            // Fill in missing dates with null values
            $filled_data = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = gmdate('Y-m-d', strtotime("-{$i} days"));
                $filled_data[$date] = $organized_data[$date] ?? [];
            }

            $result = $metric === 'all' ? $filled_data : array_column($filled_data, $metric, null);

            // Cache the result for 1 hour
            set_transient($cache_key, $result, HOUR_IN_SECONDS);

            return $result;

        } catch (\Exception $e) {
            // Return empty array on error - no fake data
            return [];
        }
    }

    /**
     * Get recommendation for specific metric
     *
     * @since 1.0.0
     *
     * @param string $metric Metric name
     * @param array  $data   Metric data
     * @return array Recommendation
     */
    private function get_metric_recommendation(string $metric, array $data): array {
        $recommendations = [
            'lcp' => [
                'title' => 'Optimize Largest Contentful Paint',
                'description' => "Your LCP is {$data['value']}{$data['unit']}, target is ≤{$data['good_threshold']}{$data['unit']}",
                'actions' => [
                    'Optimize images and use next-gen formats',
                    'Implement lazy loading for below-fold content',
                    'Minimize render-blocking resources',
                    'Use a CDN for faster content delivery'
                ]
            ],
            'fid' => [
                'title' => 'Improve First Input Delay',
                'description' => "Your FID is {$data['value']}{$data['unit']}, target is ≤{$data['good_threshold']}{$data['unit']}",
                'actions' => [
                    'Minimize JavaScript execution time',
                    'Remove unused JavaScript',
                    'Split long tasks into smaller chunks',
                    'Use web workers for heavy computations'
                ]
            ],
            'cls' => [
                'title' => 'Reduce Cumulative Layout Shift',
                'description' => "Your CLS is {$data['value']}, target is ≤{$data['good_threshold']}",
                'actions' => [
                    'Add size attributes to images and videos',
                    'Reserve space for dynamic content',
                    'Avoid inserting content above existing content',
                    'Use CSS aspect-ratio for responsive media'
                ]
            ],
            'inp' => [
                'title' => 'Optimize Interaction to Next Paint',
                'description' => "Your INP is {$data['value']}{$data['unit']}, target is ≤{$data['good_threshold']}{$data['unit']}",
                'actions' => [
                    'Optimize event handlers',
                    'Reduce JavaScript execution time',
                    'Use requestIdleCallback for non-critical tasks',
                    'Implement efficient DOM updates'
                ]
            ]
        ];

        $base = $recommendations[$metric] ?? [
            'title' => 'Optimize Performance',
            'description' => 'General performance optimization needed',
            'actions' => ['Monitor and optimize this metric']
        ];

        return array_merge($base, [
            'impact' => $data['status'] === 'poor' ? 'high' : ($data['status'] === 'needs_improvement' ? 'medium' : 'low'),
            'effort' => 'medium',
            'current_value' => $data['value'],
            'target_value' => $data['good_threshold'],
            'unit' => $data['unit']
        ]);
    }

    /**
     * Get empty metric data structure for error states
     * Phase 4: Helper method for consistent error handling
     *
     * @since 1.0.0
     *
     * @param string $name Metric name
     * @param string $unit Metric unit
     * @param float $good_threshold Good threshold value
     * @param float $needs_improvement_threshold Needs improvement threshold
     * @return array Empty metric data structure
     */
    private function get_empty_metric_data(string $name, string $unit, float $good_threshold, float $needs_improvement_threshold): array {
        return [
            'name' => $name,
            'value' => 0,
            'unit' => $unit,
            'good_threshold' => $good_threshold,
            'needs_improvement_threshold' => $needs_improvement_threshold,
            'description' => 'Data not available',
            'score' => 0,
            'status' => 'unknown'
        ];
    }

    /**
     * Get performance opportunities from PageSpeed Insights
     *
     * @param string $url URL to analyze (defaults to home URL)
     * @param string $device_type Device type ('mobile' or 'desktop')
     * @return array Performance opportunities
     */
    public function get_performance_opportunities(string $url = '', string $device_type = 'mobile'): array {
        if (empty($url)) {
            $url = home_url();
        }

        // Validate device type
        $device_type = in_array($device_type, ['mobile', 'desktop'], true) ? $device_type : 'mobile';

        // Check cache first (10-minute TTL for opportunities) - include device type in cache key
        $cache_key = 'thinkrank_opportunities_' . md5($url . '_' . $device_type);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        try {
            // Get Google OAuth access token
            $settings = $this->settings_manager->get_settings('integrations', 'site', null);
            $access_token = $settings['google_access_token'] ?? '';

            if (empty($access_token)) {
                return [];
            }

            // Ensure the Google PageSpeed Client class is loaded
            if (!class_exists('ThinkRank\\Integrations\\Google_PageSpeed_Client')) {
                $pagespeed_file = THINKRANK_PLUGIN_DIR . 'includes/integrations/class-google-pagespeed-client.php';
                if (file_exists($pagespeed_file)) {
                    require_once $pagespeed_file;
                }
            }

            // Create Google PageSpeed client with OAuth token
            $pagespeed_client = Google_PageSpeed_Client::for_site();

            // Get opportunities data with device type
            $opportunities = $pagespeed_client->get_opportunities($url, $device_type);

            // Cache the result for 10 minutes
            if (!empty($opportunities)) {
                set_transient($cache_key, $opportunities, 10 * MINUTE_IN_SECONDS);
            }

            return $opportunities;

        } catch (\Exception $e) {
            // Return empty array if API fails
            return [];
        }
    }

    /**
     * Get performance diagnostics from PageSpeed Insights
     *
     * @param string $url URL to analyze (defaults to home URL)
     * @param string $device_type Device type ('mobile' or 'desktop')
     * @return array Performance diagnostics
     */
    public function get_performance_diagnostics(string $url = '', string $device_type = 'mobile'): array {
        if (empty($url)) {
            $url = home_url();
        }

        // Validate device type
        $device_type = in_array($device_type, ['mobile', 'desktop'], true) ? $device_type : 'mobile';

        // Check cache first (10-minute TTL for diagnostics) - include device type in cache key
        $cache_key = 'thinkrank_diagnostics_' . md5($url . '_' . $device_type);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        try {
            // Get Google OAuth access token
            $settings = $this->settings_manager->get_settings('integrations', 'site', null);
            $access_token = $settings['google_access_token'] ?? '';

            if (empty($access_token)) {
                return [];
            }

            // Ensure the Google PageSpeed Client class is loaded
            if (!class_exists('ThinkRank\\Integrations\\Google_PageSpeed_Client')) {
                $pagespeed_file = THINKRANK_PLUGIN_DIR . 'includes/integrations/class-google-pagespeed-client.php';
                if (file_exists($pagespeed_file)) {
                    require_once $pagespeed_file;
                }
            }

            // Create Google PageSpeed client with OAuth token
            $pagespeed_client = Google_PageSpeed_Client::for_site();

            // Get diagnostics data with device type
            $diagnostics = $pagespeed_client->get_diagnostics($url, $device_type);

            // Cache the result for 10 minutes
            if (!empty($diagnostics)) {
                set_transient($cache_key, $diagnostics, 10 * MINUTE_IN_SECONDS);
            }

            return $diagnostics;

        } catch (\Exception $e) {
            // Return empty array if API fails
            return [];
        }
    }

    /**
     * Store historical performance data in database
     *
     * @param array  $performance_data Performance metrics data
     * @param string $context_type     Context type (site, post, etc.)
     * @param int    $context_id       Context ID
     * @param string $device_type      Device type (mobile, desktop)
     * @return bool Success status
     */
    public function store_historical_performance_data(array $performance_data, string $context_type = 'site', ?int $context_id = null, string $device_type = 'mobile'): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_performance';
        $measured_at = current_time('mysql');
        $success = true;

        try {
            // Store Core Web Vitals
            $core_web_vitals = ['lcp', 'fid', 'cls', 'inp'];
            foreach ($core_web_vitals as $metric) {
                if (isset($performance_data[$metric])) {
                    $metric_data = $performance_data[$metric];

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance data storage requires direct database access
                    $result = $wpdb->insert(
                        $table_name,
                        [
                            'context_type' => $context_type,
                            'context_id' => $context_id,
                            'metric_type' => $metric,
                            'metric_value' => $metric_data['value'] ?? 0,
                            'metric_unit' => $metric_data['unit'] ?? '',
                            'threshold_good' => $metric_data['good_threshold'] ?? null,
                            'threshold_poor' => $metric_data['needs_improvement_threshold'] ?? null,
                            'status' => $this->get_metric_status($metric_data['value'] ?? 0, $metric_data),
                            'device_type' => $device_type,
                            'measured_at' => $measured_at,
                            'measured_by' => 'google_pagespeed'
                        ],
                        [
                            '%s', '%d', '%s', '%f', '%s', '%f', '%f', '%s', '%s', '%s', '%s'
                        ]
                    );

                    if (false === $result) {
                        $success = false;
                    }
                }
            }

            // Store overall performance score
            if (isset($performance_data['performance_score'])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Performance score storage requires direct database access
                $result = $wpdb->insert(
                    $table_name,
                    [
                        'context_type' => $context_type,
                        'context_id' => $context_id,
                        'metric_type' => 'performance_score',
                        'metric_value' => $performance_data['performance_score'],
                        'metric_unit' => 'score',
                        'threshold_good' => 90,
                        'threshold_poor' => 50,
                        'status' => $this->get_score_status($performance_data['performance_score']),
                        'device_type' => $device_type,
                        'measured_at' => $measured_at,
                        'measured_by' => 'google_pagespeed'
                    ],
                    [
                        '%s', '%d', '%s', '%f', '%s', '%f', '%f', '%s', '%s', '%s', '%s'
                    ]
                );

                if (false === $result) {
                    $success = false;
                }
            }

            return $success;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get metric status based on value and thresholds
     *
     * @param float $value       Metric value
     * @param array $metric_data Metric data with thresholds
     * @return string Status (good, needs_improvement, poor)
     */
    private function get_metric_status(float $value, array $metric_data): string {
        $good_threshold = $metric_data['good_threshold'] ?? 0;
        $poor_threshold = $metric_data['needs_improvement_threshold'] ?? 0;

        if ($value <= $good_threshold) {
            return 'good';
        } elseif ($value <= $poor_threshold) {
            return 'needs_improvement';
        } else {
            return 'poor';
        }
    }

    /**
     * Get score status based on performance score
     *
     * @param float $score Performance score
     * @return string Status
     */
    private function get_score_status(float $score): string {
        if ($score >= 90) {
            return 'good';
        } elseif ($score >= 50) {
            return 'needs_improvement';
        } else {
            return 'poor';
        }
    }

}
