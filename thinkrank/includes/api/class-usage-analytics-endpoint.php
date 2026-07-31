<?php
/**
 * Usage Analytics REST API Endpoint
 *
 * Handles REST API endpoints for usage analytics data including AI usage, costs, and performance metrics
 *
 * @package ThinkRank\API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Database;
use ThinkRank\API\Traits\API_Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load API Cache trait
require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-api-cache.php';

/**
 * Usage Analytics Endpoint Class
 *
 * Provides REST API endpoints for:
 * - /wp-json/thinkrank/v1/analytics/overview
 * - /wp-json/thinkrank/v1/analytics/usage
 * - /wp-json/thinkrank/v1/analytics/costs
 *
 * @since 1.0.0
 */
class Usage_Analytics_Endpoint {

    use API_Cache;
    
    /**
     * Database instance
     *
     * @var Database
     */
    private Database $database;
    
    /**
     * OpenAI pricing per 1M tokens (USD)
     */
    private const OPENAI_PRICING = [
        // GPT-5 models (official pricing from OpenAI)
        'gpt-5' => [
            'input' => 1.25,
            'output' => 10.00
        ],
        'gpt-5-mini' => [
            'input' => 0.25,
            'output' => 2.00
        ],
        'gpt-5-nano' => [
            'input' => 0.05,
            'output' => 0.40
        ],
        // GPT-4 models
        'gpt-4.1' => [
            'input' => 2.50,
            'output' => 10.00
        ],
        'gpt-4o' => [
            'input' => 2.50,
            'output' => 10.00
        ],
        'gpt-4-turbo' => [
            'input' => 10.00,
            'output' => 30.00
        ],
        // O3 models
        'o3-mini' => [
            'input' => 1.25,
            'output' => 5.00
        ],
        // Mini models
        'gpt-4o-mini' => [
            'input' => 0.15,
            'output' => 0.60
        ]
    ];
    
    /**
     * Claude pricing per 1M tokens (USD)
     * Model IDs sourced from https://docs.anthropic.com/en/docs/about-claude/models
     */
    private const CLAUDE_PRICING = [
        // Current models (recommended)
        'claude-opus-4-8' => [
            'input' => 5.00,
            'output' => 25.00
        ],
        'claude-sonnet-5' => [
            'input' => 3.00,
            'output' => 15.00
        ],
        'claude-haiku-4-5' => [
            'input' => 1.00,
            'output' => 5.00
        ],
        // Claude 4.x models
        'claude-opus-4-6' => [
            'input' => 5.00,
            'output' => 25.00
        ],
        'claude-sonnet-4-6' => [
            'input' => 3.00,
            'output' => 15.00
        ],
        // Claude 4.5 models
        'claude-haiku-4-5-20251001' => [
            'input' => 1.00,
            'output' => 5.00
        ],
        // Claude 3.5 models (legacy)
        'claude-3-5-sonnet-20241022' => [
            'input' => 3.00,
            'output' => 15.00
        ],
        'claude-3-5-haiku-20241022' => [
            'input' => 0.80,
            'output' => 4.00
        ],
        'claude-3-opus-20240229' => [
            'input' => 15.00,
            'output' => 75.00
        ]
    ];

    /**
     * Gemini pricing per 1M tokens (USD)
     */
    private const GEMINI_PRICING = [
        // Gemini 3.x models (tiered models use the base <=200k-token rate)
        'gemini-3.1-pro' => [
            'input' => 2.00,
            'output' => 12.00
        ],
        'gemini-3.5-flash' => [
            'input' => 1.50,
            'output' => 9.00
        ],
        'gemini-3.1-flash-lite' => [
            'input' => 0.25,
            'output' => 1.50
        ],
        // Gemini 2.5 models
        'gemini-2.5-flash' => [
            'input' => 0.30,
            'output' => 2.50
        ],
        'gemini-2.5-flash-lite' => [
            'input' => 0.10,
            'output' => 0.40
        ],
        'gemini-2.5-pro' => [
            'input' => 1.25,
            'output' => 10.00
        ],
        // Gemini 2.0 models
        'gemini-2.0-flash' => [
            'input' => 0.10,
            'output' => 0.40
        ],
        // Gemini 1.5 models
        'gemini-1.5-flash' => [
            'input' => 0.075,
            'output' => 0.30
        ],
        'gemini-1.5-pro' => [
            'input' => 1.25,
            'output' => 5.00
        ]
    ];

    /**
     * OpenRouter pricing per 1M tokens (USD)
     *
     * OpenRouter passes through each upstream model's pricing; these are
     * representative rates for the curated model list used for cost estimates.
     */
    private const OPENROUTER_PRICING = [
        'openai/gpt-4o-mini' => [
            'input' => 0.15,
            'output' => 0.60
        ],
        'anthropic/claude-3.5-sonnet' => [
            'input' => 3.00,
            'output' => 15.00
        ],
        'google/gemini-2.0-flash-001' => [
            'input' => 0.10,
            'output' => 0.40
        ],
        'meta-llama/llama-3.3-70b-instruct' => [
            'input' => 0.12,
            'output' => 0.30
        ],
        'deepseek/deepseek-chat' => [
            'input' => 0.14,
            'output' => 0.28
        ]
    ];

    /**
     * Time saved estimates per action (minutes)
     */
    private const TIME_SAVED_ESTIMATES = [
        'seo_metadata' => 20,
        'content_analysis' => 15,
        'content_brief' => 45,
        'seo_score' => 10
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new Database();

        // Configure caching for analytics endpoints
        $this->set_cache_prefix('thinkrank_analytics_');
        $this->set_cache_duration(600); // 10 minutes for analytics data

        // Set up cache invalidation hooks
        $this->setup_cache_invalidation();
    }
    
    /**
     * Register REST API routes
     * 
     * @return void
     */
    public function register_routes(): void {
        // Overview metrics endpoint
        register_rest_route('thinkrank/v1', '/analytics/overview', [
            'methods' => 'GET',
            'callback' => [$this, 'get_overview_metrics'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'period' => [
                    'default' => '30d',
                    'enum' => ['7d', '30d', '90d', 'all'],
                    'sanitize_callback' => 'sanitize_key'
                ],
                'user_id' => [
                    'default' => 0,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
        
        // Usage breakdown endpoint
        register_rest_route('thinkrank/v1', '/analytics/usage', [
            'methods' => 'GET',
            'callback' => [$this, 'get_usage_breakdown'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'period' => [
                    'default' => '30d',
                    'enum' => ['7d', '30d', '90d', 'all'],
                    'sanitize_callback' => 'sanitize_key'
                ],
                'group_by' => [
                    'default' => 'day',
                    'enum' => ['day', 'week', 'month'],
                    'sanitize_callback' => 'sanitize_key'
                ],
                'user_id' => [
                    'default' => 0,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
        
        // Cost analysis endpoint
        register_rest_route('thinkrank/v1', '/analytics/costs', [
            'methods' => 'GET',
            'callback' => [$this, 'get_cost_analysis'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'period' => [
                    'default' => '30d',
                    'enum' => ['7d', '30d', '90d', 'all'],
                    'sanitize_callback' => 'sanitize_key'
                ],
                'provider' => [
                    'default' => 'all',
                    'enum' => ['all', 'openai', 'claude', 'gemini', 'openrouter'],
                    'sanitize_callback' => 'sanitize_key'
                ],
                'user_id' => [
                    'default' => 0,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
    }
    
    /**
     * Get overview metrics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_overview_metrics(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $period = $request->get_param('period');
        $user_id = $request->get_param('user_id') ?: get_current_user_id();

        try {
            // Use cached response wrapper for performance
            $response_data = $this->cached_response(
                'overview_metrics',
                function() use ($period, $user_id) {
                    // Get date range for queries
                    $date_condition = $this->get_date_condition($period);

                    // Get AI usage metrics
                    $ai_metrics = $this->get_ai_usage_metrics($user_id, $date_condition);

                    // Get SEO metrics
                    $seo_metrics = $this->get_seo_metrics($user_id, $date_condition);

                    // Get content brief metrics
                    $brief_metrics = $this->get_content_brief_metrics($user_id, $date_condition);

                    // Calculate costs
                    $cost_data = $this->calculate_costs($ai_metrics['usage_data'] ?? []);

                    // Calculate time saved
                    $time_saved = $this->calculate_time_saved($ai_metrics['feature_breakdown'] ?? []);

                    return [
                        'success' => true,
                        'data' => [
                            'content_optimized' => $seo_metrics['content_optimized'],
                            'content_optimized_change' => $seo_metrics['content_optimized_change'],
                            'average_seo_score' => $seo_metrics['average_seo_score'],
                            'seo_score_change' => $seo_metrics['seo_score_change'],
                            'total_tokens' => $ai_metrics['total_tokens'],
                            'total_cost' => $cost_data['total'],
                            'cost_change' => $ai_metrics['cost_change'],
                            'time_saved' => $time_saved,
                            'time_saved_change' => $ai_metrics['time_saved_change'],
                            'ai_actions' => $ai_metrics['total_actions'],
                            'features_used_count' => $ai_metrics['features_used_count'],
                            'most_used_feature' => $ai_metrics['most_used_feature'],
                            'most_used_count' => $ai_metrics['most_used_count'],
                            'success_rate' => $ai_metrics['success_rate'],
                            'content_briefs' => $brief_metrics['total_briefs'],
                            'feature_breakdown' => $ai_metrics['feature_breakdown'],
                            'provider_breakdown' => $cost_data['by_provider']
                        ],
                        'period' => $period,
                        'generated_at' => current_time('c')
                    ];
                },
                ['period' => $period],
                null, // Use default cache duration
                $user_id
            );

            return new WP_REST_Response($response_data, 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'analytics_error',
                'Failed to retrieve analytics data: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Check permissions for analytics endpoints
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error Permission result
     */
    public function check_permissions(WP_REST_Request $request): bool|WP_Error {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'not_logged_in',
                'You must be logged in to view analytics data.',
                ['status' => 401]
            );
        }

        // Check if user can edit posts (basic content management capability)
        if (!current_user_can('edit_posts')) {
            return new WP_Error(
                'insufficient_permissions',
                'You do not have permission to view analytics data.',
                ['status' => 403]
            );
        }

        // If requesting another user's data, check admin permissions
        $requested_user_id = $request->get_param('user_id');
        if ($requested_user_id && $requested_user_id !== get_current_user_id()) {
            if (!current_user_can('manage_options')) {
                return new WP_Error(
                    'insufficient_permissions',
                    'You do not have permission to view other users\' analytics data.',
                    ['status' => 403]
                );
            }
        }

        return true;
    }

    /**
     * Set up cache invalidation hooks
     *
     * @since 1.0.0
     * @return void
     */
    private function setup_cache_invalidation(): void {
        // Invalidate analytics cache when AI usage is logged
        add_action('thinkrank_ai_usage_logged', [$this, 'invalidate_analytics_cache']);

        // Invalidate analytics cache when SEO scores are updated
        add_action('thinkrank_seo_score_updated', [$this, 'invalidate_analytics_cache']);

        // Invalidate analytics cache when content briefs are created
        add_action('thinkrank_content_brief_created', [$this, 'invalidate_analytics_cache']);
    }

    /**
     * Invalidate analytics cache
     *
     * @since 1.0.0
     * @return void
     */
    public function invalidate_analytics_cache(): void {
        // Clear all analytics cache entries
        $this->invalidate_cache_pattern('thinkrank_analytics_*');
    }
    
    /**
     * Get the cutoff datetime string for a period.
     * Returns null for 'all' (no date restriction).
     *
     * @param string $period Period string
     * @return string|null Cutoff datetime in MySQL format, or null for all time
     */
    private function get_date_cutoff(string $period): ?string {
        $days = match($period) {
            '7d'  => 7,
            '30d' => 30,
            '90d' => 90,
            'all' => null,
            default => 30,
        };
        if ($days === null) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    /**
     * Get date condition for SQL queries
     *
     * @deprecated Use get_date_cutoff() with parameterized queries instead.
     * Kept for back-compat with get_previous_period_condition() parsing.
     *
     * @param string $period Period string
     * @return string SQL date condition
     */
    private function get_date_condition(string $period): string {
        return match($period) {
            '7d' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90d' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            'all' => "",
            default => "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        };
    }
    
    /**
     * Calculate costs from usage data
     *
     * @param array $usage_data Usage data array
     * @return array Cost breakdown
     */
    private function calculate_costs(array $usage_data): array {
        $costs = [
            'openai' => 0,
            'claude' => 0,
            'gemini' => 0,
            'openrouter' => 0,
            'total' => 0,
            'by_provider' => []
        ];
        
        foreach ($usage_data as $usage) {
            $tokens = (int) $usage['tokens_used'];
            $provider = $usage['provider'];

            // Estimate 70% input, 30% output tokens
            $input_tokens = $tokens * 0.7;
            $output_tokens = $tokens * 0.3;

            $cost = 0;

            // Use the robust pricing helper for consistent cost calculation
            $pricing = $this->get_model_pricing($provider);
            if ($pricing) {
                $cost = ($input_tokens * $pricing['input'] / 1000000) +
                        ($output_tokens * $pricing['output'] / 1000000);
                $costs[$provider] += $cost;
            }
        }
        
        $costs['total'] = $costs['openai'] + $costs['claude'] + $costs['gemini'] + $costs['openrouter'];

        // Format provider breakdown
        $costs['by_provider'] = [
            'openai' => [
                'cost' => round($costs['openai'], 4),
                'percentage' => $costs['total'] > 0 ? round(($costs['openai'] / $costs['total']) * 100, 1) : 0
            ],
            'claude' => [
                'cost' => round($costs['claude'], 4),
                'percentage' => $costs['total'] > 0 ? round(($costs['claude'] / $costs['total']) * 100, 1) : 0
            ],
            'gemini' => [
                'cost' => round($costs['gemini'], 4),
                'percentage' => $costs['total'] > 0 ? round(($costs['gemini'] / $costs['total']) * 100, 1) : 0
            ],
            'openrouter' => [
                'cost' => round($costs['openrouter'], 4),
                'percentage' => $costs['total'] > 0 ? round(($costs['openrouter'] / $costs['total']) * 100, 1) : 0
            ]
        ];
        
        return $costs;
    }
    
    /**
     * Calculate time saved from feature usage
     *
     * @param array $feature_breakdown Feature usage breakdown
     * @return int Time saved in minutes
     */
    private function calculate_time_saved(array $feature_breakdown): int {
        $total_time_saved = 0;
        
        foreach ($feature_breakdown as $feature => $count) {
            $time_per_action = self::TIME_SAVED_ESTIMATES[$feature] ?? 15; // Default 15 minutes
            $total_time_saved += $count * $time_per_action;
        }
        
        return $total_time_saved;
    }

    /**
     * Get AI usage metrics from database
     *
     * @param int $user_id User ID
     * @param string $date_condition SQL date condition
     * @return array AI usage metrics
     */
    private function get_ai_usage_metrics(int $user_id, string $date_condition): array {
        global $wpdb;

        // Get table name and escape it properly (table names cannot be parameterized)
        $table_name = esc_sql($this->database->get_table('ai_usage'));

        $cutoff = $this->get_date_cutoff($this->resolve_period_from_condition($date_condition));

        if ($cutoff !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $usage_data = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is escaped via esc_sql().
                    "SELECT provider, action, tokens_used, created_at FROM `{$table_name}` WHERE user_id = %d AND created_at >= %s",
                    $user_id,
                    $cutoff
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $usage_data = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is escaped via esc_sql().
                    "SELECT provider, action, tokens_used, created_at FROM `{$table_name}` WHERE user_id = %d",
                    $user_id
                ),
                ARRAY_A
            );
        }

        if (empty($usage_data)) {
            return [
                'total_actions' => 0,
                'total_tokens' => 0,
                'feature_breakdown' => [],
                'usage_data' => [],
                'cost_change' => 0,
                'time_saved_change' => 0
            ];
        }

        // Calculate feature breakdown and new metrics
        $feature_breakdown = [];
        $total_actions = 0;
        $total_tokens = 0;

        foreach ($usage_data as $row) {
            $action = $row['action'];
            $tokens = (int) $row['tokens_used'];

            if (!isset($feature_breakdown[$action])) {
                $feature_breakdown[$action] = 0;
            }
            $feature_breakdown[$action]++;
            $total_actions++;
            $total_tokens += $tokens;
        }

        // Calculate new metrics
        $features_used_count = count($feature_breakdown);

        // Find most used feature
        $most_used_feature = '';
        $most_used_count = 0;
        foreach ($feature_breakdown as $feature => $count) {
            if ($count > $most_used_count) {
                $most_used_feature = $feature;
                $most_used_count = $count;
            }
        }

        // Calculate success rate (assuming all logged actions are successful for now)
        // In future, we could track failed attempts separately
        $success_rate = $total_actions > 0 ? 100 : 0;

        // Calculate changes from previous period
        $previous_period_data = $this->get_previous_period_data($user_id, $date_condition);
        $cost_change = $this->calculate_percentage_change(
            $previous_period_data['total_cost'] ?? 0,
            $this->calculate_total_cost($usage_data)
        );
        $time_saved_change = $this->calculate_percentage_change(
            $previous_period_data['time_saved'] ?? 0,
            $this->calculate_time_saved($feature_breakdown)
        );

        return [
            'total_actions' => $total_actions,
            'total_tokens' => $total_tokens,
            'feature_breakdown' => $feature_breakdown,
            'features_used_count' => $features_used_count,
            'most_used_feature' => $most_used_feature,
            'most_used_count' => $most_used_count,
            'success_rate' => $success_rate,
            'usage_data' => $usage_data,
            'cost_change' => $cost_change,
            'time_saved_change' => $time_saved_change
        ];
    }

    /**
     * Get SEO metrics from database
     *
     * @param int $user_id User ID
     * @param string $date_condition SQL date condition
     * @return array SEO metrics
     */
    private function get_seo_metrics(int $user_id, string $date_condition): array {
        global $wpdb;

        $table_name = esc_sql($this->database->get_table('seo_scores'));
        $cutoff = $this->get_date_cutoff($this->resolve_period_from_condition($date_condition));

        if ($cutoff !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is escaped via esc_sql().
                    "SELECT COUNT(DISTINCT post_id) as content_optimized, AVG(overall_score) as average_score FROM `{$table_name}` WHERE user_id = %d AND created_at >= %s",
                    $user_id,
                    $cutoff
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is escaped via esc_sql().
                    "SELECT COUNT(DISTINCT post_id) as content_optimized, AVG(overall_score) as average_score FROM `{$table_name}` WHERE user_id = %d",
                    $user_id
                ),
                ARRAY_A
            );
        }

        if (!$result || $result['content_optimized'] == 0) {
            return [
                'content_optimized' => 0,
                'average_seo_score' => 0,
                'content_optimized_change' => 0,
                'seo_score_change' => 0
            ];
        }

        // Calculate changes from previous period
        $previous_seo_data = $this->get_previous_seo_data($user_id, $date_condition);
        $content_optimized_change = $this->calculate_percentage_change(
            $previous_seo_data['content_optimized'] ?? 0,
            (int) $result['content_optimized']
        );
        $seo_score_change = $this->calculate_percentage_change(
            $previous_seo_data['average_seo_score'] ?? 0,
            round((float) $result['average_score'], 1)
        );

        return [
            'content_optimized' => (int) $result['content_optimized'],
            'average_seo_score' => round((float) $result['average_score'], 1),
            'content_optimized_change' => $content_optimized_change,
            'seo_score_change' => $seo_score_change
        ];
    }

    /**
     * Get content brief metrics from database
     *
     * @param int $user_id User ID
     * @param string $date_condition SQL date condition
     * @return array Content brief metrics
     */
    private function get_content_brief_metrics(int $user_id, string $date_condition): array {
        global $wpdb;

        $table_name = esc_sql($this->database->get_table('content_briefs'));
        $cutoff = $this->get_date_cutoff($this->resolve_period_from_condition($date_condition));

        if ($cutoff !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is escaped via esc_sql().
                    "SELECT COUNT(*) as total_briefs FROM `{$table_name}` WHERE user_id = %d AND created_at >= %s",
                    $user_id,
                    $cutoff
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is escaped via esc_sql().
                    "SELECT COUNT(*) as total_briefs FROM `{$table_name}` WHERE user_id = %d",
                    $user_id
                )
            );
        }

        return [
            'total_briefs' => (int) $result ?: 0
        ];
    }

    /**
     * Get detailed usage breakdown
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_usage_breakdown(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $user_id = get_current_user_id();
            $period = $request->get_param('period') ?? '30d';
            $page = max(1, (int) $request->get_param('page') ?? 1);
            $per_page = min(100, max(10, (int) $request->get_param('per_page') ?? 20));
            $offset = ($page - 1) * $per_page;

            // Get date range for queries
            $date_condition = $this->get_date_condition($period);

            // Get detailed usage breakdown
            $usage_data = $this->get_detailed_usage_breakdown($user_id, $date_condition, $per_page, $offset);
            $total_records = $this->get_usage_breakdown_count($user_id, $date_condition);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'usage_records' => $usage_data,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $per_page,
                        'total_records' => $total_records,
                        'total_pages' => ceil($total_records / $per_page)
                    ],
                    'period' => $period
                ]
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'usage_breakdown_failed',
                'Failed to get usage breakdown: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get cost analysis (placeholder for Phase 2)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_cost_analysis(WP_REST_Request $request): WP_REST_Response|WP_Error {
        // Return 200 with success:false so the frontend can render an
        // "unavailable" state — apiFetch rejects on non-2xx, which would
        // otherwise surface as a generic hard error.
        return new WP_REST_Response([
            'success' => false,
            'data' => null,
            'message' => 'Cost analysis is not yet implemented.'
        ], 200);
    }

    /**
     * Calculate total cost from usage data
     *
     * @param array $usage_data Usage data array
     * @return float Total cost
     */
    private function calculate_total_cost(array $usage_data): float {
        $cost_data = $this->calculate_costs($usage_data);
        return $cost_data['total'];
    }

    /**
     * Calculate percentage change between two values
     *
     * @param float $old_value Previous period value
     * @param float $new_value Current period value
     * @return float Percentage change
     */
    private function calculate_percentage_change(float $old_value, float $new_value): float {
        if ($old_value == 0) {
            return $new_value > 0 ? 100 : 0;
        }

        return round((($new_value - $old_value) / $old_value) * 100, 1);
    }

    /**
     * Get previous period data for comparison
     *
     * @param int $user_id User ID
     * @param string $current_date_condition Current period date condition
     * @return array Previous period data
     */
    private function get_previous_period_data(int $user_id, string $current_date_condition): array {
        global $wpdb;

        // Get table name and escape it properly (table names cannot be parameterized)
        $table_name = esc_sql($this->database->get_table('ai_usage'));

        // Extract the interval from current date condition to calculate previous period
        $previous_date_condition = $this->get_previous_period_condition($current_date_condition);

        // Prepare and execute query with proper parameter binding to prevent SQL injection
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is properly escaped, date condition is from controlled source
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data is real-time and shouldn't be cached
        $usage_data = $wpdb->get_results(
            $wpdb->prepare("
                SELECT
                    provider,
                    action,
                    tokens_used
                FROM `{$table_name}`
                WHERE user_id = %d
                {$previous_date_condition}
            ", $user_id),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (empty($usage_data)) {
            return ['total_cost' => 0, 'time_saved' => 0];
        }

        // Calculate feature breakdown for time saved
        $feature_breakdown = [];
        foreach ($usage_data as $row) {
            $action = $row['action'];
            if (!isset($feature_breakdown[$action])) {
                $feature_breakdown[$action] = 0;
            }
            $feature_breakdown[$action]++;
        }

        return [
            'total_cost' => $this->calculate_total_cost($usage_data),
            'time_saved' => $this->calculate_time_saved($feature_breakdown)
        ];
    }

    /**
     * Get detailed usage breakdown with pagination
     *
     * @param int $user_id User ID
     * @param string $date_condition SQL date condition
     * @param int $limit Number of records to return
     * @param int $offset Offset for pagination
     * @return array Detailed usage records
     */
    private function get_detailed_usage_breakdown(int $user_id, string $date_condition, int $limit, int $offset): array {
        global $wpdb;

        $table_name = esc_sql($this->database->get_table('ai_usage'));

        $sql = "
            SELECT
                id,
                provider,
                action,
                tokens_used,
                post_id,
                metadata,
                created_at
            FROM `{$table_name}`
            WHERE user_id = %d
            {$date_condition}
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data is real-time, table name and date condition are validated internally
        $usage_data = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $wpdb->prepare($sql, $user_id, $limit, $offset),
            ARRAY_A
        );

        // Process and enrich the data
        $processed_data = [];
        foreach ($usage_data as $record) {
            $metadata = !empty($record['metadata']) ? json_decode($record['metadata'], true) : [];
            $model = $metadata['actual_model'] ?? $this->get_default_model($record['provider']);
            $cost = $this->calculate_record_cost($record['provider'], (int) $record['tokens_used'], $model);

            $processed_data[] = [
                'id' => (int) $record['id'],
                'provider' => $record['provider'],
                'model' => $model,
                'action' => $record['action'],
                'tokens_used' => (int) $record['tokens_used'],
                'estimated_cost' => $cost,
                'post_id' => $record['post_id'] ? (int) $record['post_id'] : null,
                'created_at' => $record['created_at'],
                'formatted_date' => wp_date('M j, Y g:i A', strtotime($record['created_at']))
            ];
        }

        return $processed_data;
    }

    /**
     * Get total count of usage records for pagination
     *
     * @param int $user_id User ID
     * @param string $date_condition SQL date condition
     * @return int Total record count
     */
    private function get_usage_breakdown_count(int $user_id, string $date_condition): int {
        global $wpdb;

        $table_name = esc_sql($this->database->get_table('ai_usage'));

        $sql = "
            SELECT COUNT(*)
            FROM `{$table_name}`
            WHERE user_id = %d
            {$date_condition}
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data is real-time, table name and date condition are validated internally
        $count = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is properly prepared with placeholders
            $wpdb->prepare($sql, $user_id)
        );

        return (int) $count;
    }

    /**
     * Calculate cost for a single record using the robust pricing helper
     *
     * @param string $provider AI provider
     * @param int $tokens_used Number of tokens used
     * @param string $model Optional specific model name
     * @return float Estimated cost
     */
    private function calculate_record_cost(string $provider, int $tokens_used, string $model = ''): float {
        // Get pricing using the robust helper method
        $pricing = $this->get_model_pricing($provider, $model);

        if (!$pricing) {
            return 0.0;
        }

        // Estimate 70% input, 30% output tokens
        $input_tokens = $tokens_used * 0.7;
        $output_tokens = $tokens_used * 0.3;

        return (($input_tokens / 1000000) * $pricing['input']) +
               (($output_tokens / 1000000) * $pricing['output']);
    }

    /**
     * Get pricing for any model with intelligent fallbacks
     *
     * @param string $provider AI provider
     * @param string $model Model name (optional)
     * @return array|null Pricing array with 'input' and 'output' keys, or null if not found
     */
    private function get_model_pricing(string $provider, string $model = ''): ?array {
        switch ($provider) {
            case 'openai':
                // Try specific model first, fallback to default
                if ($model && isset(self::OPENAI_PRICING[$model])) {
                    return self::OPENAI_PRICING[$model];
                }
                return self::OPENAI_PRICING['gpt-4o'] ?? null;

            case 'claude':
                // Try specific model first, fallback to recommended default
                if ($model && isset(self::CLAUDE_PRICING[$model])) {
                    return self::CLAUDE_PRICING[$model];
                }
                return self::CLAUDE_PRICING['claude-sonnet-5'] ??
                       self::CLAUDE_PRICING['claude-sonnet-4-6'] ?? null;

            case 'gemini':
                // Try specific model first, fallback to default
                if ($model && isset(self::GEMINI_PRICING[$model])) {
                    return self::GEMINI_PRICING[$model];
                }
                return self::GEMINI_PRICING['gemini-2.5-flash'] ?? null;

            case 'openrouter':
                // Try specific model first, fallback to default
                if ($model && isset(self::OPENROUTER_PRICING[$model])) {
                    return self::OPENROUTER_PRICING[$model];
                }
                return self::OPENROUTER_PRICING['openai/gpt-4o-mini'] ?? null;

            default:
                return null;
        }
    }

    /**
     * Get default model for provider using proper aliases
     *
     * @param string $provider AI provider
     * @return string Default model name
     */
    private function get_default_model(string $provider): string {
        switch ($provider) {
            case 'openai':
                return 'gpt-5-nano';
            case 'claude':
                return 'claude-sonnet-5';
            case 'gemini':
                return 'gemini-2.5-flash';  // Keep stable default model
            case 'openrouter':
                return 'openai/gpt-4o-mini';
            default:
                return 'unknown';
        }
    }

    /**
     * Get previous SEO data for comparison
     *
     * @param int $user_id User ID
     * @param string $current_date_condition Current period date condition
     * @return array Previous SEO data
     */
    private function get_previous_seo_data(int $user_id, string $current_date_condition): array {
        global $wpdb;

        // Get table name and escape it properly (table names cannot be parameterized)
        $table_name = esc_sql($this->database->get_table('seo_scores'));

        $previous_date_condition = $this->get_previous_period_condition($current_date_condition);

        // Prepare and execute query with proper parameter binding to prevent SQL injection
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is properly escaped, date condition is from controlled source
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data is real-time and shouldn't be cached
        $result = $wpdb->get_row(
            $wpdb->prepare("
                SELECT
                    COUNT(DISTINCT post_id) as content_optimized,
                    AVG(overall_score) as average_score
                FROM `{$table_name}`
                WHERE user_id = %d
                {$previous_date_condition}
            ", $user_id),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$result) {
            return ['content_optimized' => 0, 'average_seo_score' => 0];
        }

        return [
            'content_optimized' => (int) $result['content_optimized'],
            'average_seo_score' => round((float) $result['average_score'], 1)
        ];
    }

    /**
     * Resolve a period key from a legacy SQL date condition string.
     * Used internally so the new parameterized helpers can derive the period.
     *
     * @param string $condition Legacy date condition string
     * @return string Period key
     */
    private function resolve_period_from_condition(string $condition): string {
        if (strpos($condition, 'INTERVAL 7') !== false)  return '7d';
        if (strpos($condition, 'INTERVAL 30') !== false) return '30d';
        if (strpos($condition, 'INTERVAL 90') !== false) return '90d';
        if (empty(trim($condition)))                     return 'all';
        return '30d';
    }

    /**
     * Convert current period condition to previous period condition
     *
     * @param string $current_condition Current period SQL condition
     * @return string Previous period SQL condition
     */
    private function get_previous_period_condition(string $current_condition): string {
        // Extract interval from conditions like "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        if (preg_match('/INTERVAL (\d+) (\w+)/', $current_condition, $matches)) {
            $interval = (int) $matches[1];
            $unit = $matches[2];

            // Calculate previous period: if current is last 30 days, previous is 30-60 days ago
            $start_interval = $interval * 2;
            $end_interval = $interval;

            return "AND created_at >= DATE_SUB(NOW(), INTERVAL {$start_interval} {$unit})
                    AND created_at < DATE_SUB(NOW(), INTERVAL {$end_interval} {$unit})";
        }

        // Fallback for unknown conditions
        return "AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
}
