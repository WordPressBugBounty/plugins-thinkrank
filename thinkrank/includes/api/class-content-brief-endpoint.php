<?php
/**
 * Content Brief API Endpoint
 *
 * Handles REST API endpoints for content brief generation
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.0.0
 */

namespace ThinkRank\API;

use ThinkRank\AI\Content_Brief_Generator;
use ThinkRank\AI\Manager as AI_Manager;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content Brief Endpoint class
 */
class Content_Brief_Endpoint {

    /**
     * API namespace
     */
    const NAMESPACE = 'thinkrank/v1';

    /**
     * Content brief generator instance
     *
     * @var Content_Brief_Generator|null
     */
    private ?Content_Brief_Generator $generator = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Don't instantiate generator here - do it lazily when needed
    }

    /**
     * Get generator instance (lazy loading)
     *
     * @return Content_Brief_Generator
     * @throws \Exception If generator cannot be created
     */
    private function get_generator(): Content_Brief_Generator {
        if ($this->generator === null) {
            // Get AI client from AI Manager with proper timeout configuration
            $ai_manager = new AI_Manager();
            $ai_client = $ai_manager->get_client();

            $this->generator = new Content_Brief_Generator(null, $ai_client);
        }
        return $this->generator;
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes(): void {
        // Generate content brief
        register_rest_route(self::NAMESPACE, '/content-brief/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_brief'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'target_keywords' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1
                    ],
                    'minItems' => 1,
                    'validate_callback' => [$this, 'validate_keywords']
                ],
                'content_type' => [
                    'type' => 'string',
                    'default' => 'blog_post',
                    'enum' => ['blog_post', 'product_page', 'landing_page', 'tutorial']
                ],
                'target_audience' => [
                    'type' => 'string',
                    'default' => 'general',
                    'enum' => ['beginners', 'professionals', 'general', 'experts']
                ],
                'content_length' => [
                    'type' => 'string',
                    'default' => 'medium',
                    'enum' => ['short', 'medium', 'long']
                ],
                'tone' => [
                    'type' => 'string',
                    'default' => 'professional',
                    'enum' => ['professional', 'casual', 'technical', 'friendly']
                ],
                'competitor_urls' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'format' => 'uri'
                    ],
                    'default' => []
                ],
                'additional_context' => [
                    'type' => 'string',
                    'default' => ''
                ]
            ]
        ]);

        // Get user's content briefs
        register_rest_route(self::NAMESPACE, '/content-brief/list', [
            'methods' => 'GET',
            'callback' => [$this, 'get_briefs'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'limit' => [
                    'type' => 'integer',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 50
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'minimum' => 0
                ]
            ]
        ]);

        // Delete content brief
        register_rest_route(self::NAMESPACE, '/content-brief/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_brief'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1
                ]
            ]
        ]);

        // Export content brief
        register_rest_route(self::NAMESPACE, '/content-brief/(?P<id>\d+)/export', [
            'methods' => 'GET',
            'callback' => [$this, 'export_brief'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1
                ],
                // Only plain-text export is implemented; keep the enum honest
                // rather than advertising pdf/docx that fall back to text.
                'format' => [
                    'type' => 'string',
                    'default' => 'txt',
                    'enum' => ['txt']
                ]
            ]
        ]);
    }

    /**
     * Generate content brief
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function generate_brief(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            // Persistent per-user throttle on this paid AI-backed route (the other
            // AI endpoints do the same) to prevent an edit_posts user looping it.
            if (!$this->check_ai_rate_limit()) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    'Rate limit exceeded. Please wait a few minutes before generating another content brief.',
                    ['status' => 429]
                );
            }

            $params = [
                'target_keywords' => $request->get_param('target_keywords'),
                'content_type' => $request->get_param('content_type'),
                'target_audience' => $request->get_param('target_audience'),
                'content_length' => $request->get_param('content_length'),
                'tone' => $request->get_param('tone'),
                'competitor_urls' => $request->get_param('competitor_urls'),
                'additional_context' => $request->get_param('additional_context')
            ];

            $brief_data = $this->get_generator()->generate_brief($params);

            return new WP_REST_Response([
                'success' => true,
                'data' => $brief_data,
                'message' => 'Content brief generated successfully'
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'brief_generation_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get user's content briefs
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_briefs(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $limit = $request->get_param('limit');
            $offset = $request->get_param('offset');

            $briefs = $this->get_generator()->get_user_briefs($limit, $offset);

            return new WP_REST_Response([
                'success' => true,
                'data' => $briefs,
                'total' => count($briefs)
            ], 200);

        } catch (\Exception $e) {
            // If no API key is configured, return empty list instead of error
            if (strpos($e->getMessage(), 'Please configure your AI provider') !== false) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [],
                    'total' => 0
                ], 200);
            }

            return new WP_Error(
                'briefs_fetch_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Delete content brief
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function delete_brief(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $brief_id = $request->get_param('id');
            $success = $this->get_generator()->delete_brief($brief_id);

            if ($success) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'Content brief deleted successfully'
                ], 200);
            } else {
                return new WP_Error(
                    'brief_delete_failed',
                    'Failed to delete content brief',
                    ['status' => 500]
                );
            }

        } catch (\Exception $e) {
            return new WP_Error(
                'brief_delete_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Export content brief
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function export_brief(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $brief_id = (int) $request->get_param('id');
            $format = $request->get_param('format');

            // Fetch the requested brief, scoped to the current user. Returns null
            // (→ 404) when the id doesn't exist or belongs to another user.
            $brief = $this->get_generator()->get_brief($brief_id);

            if (!$brief) {
                return new WP_Error(
                    'brief_not_found',
                    'Content brief not found',
                    ['status' => 404]
                );
            }

            $export_data = $this->format_brief_for_export($brief, $format);

            return new WP_REST_Response([
                'success' => true,
                'data' => $export_data,
                'format' => $format
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'brief_export_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Format brief for export
     *
     * @param array $brief Brief data
     * @param string $format Export format
     * @return string Formatted content
     */
    private function format_brief_for_export(array $brief, string $format): string {
        $brief_data = $brief['brief_data'];
        
        $content = "Content Brief: " . $brief['title'] . "\n\n";
        $content .= "Target Keywords: " . implode(', ', $brief['target_keywords']) . "\n";
        $content .= "Content Type: " . $brief['content_type'] . "\n\n";
        
        if (!empty($brief_data['outline'])) {
            $content .= "Content Outline:\n";
            foreach ($brief_data['outline'] as $item) {
                $indent = str_repeat('  ', $item['level'] - 1);
                $content .= $indent . "H{$item['level']}: " . $item['heading'];
                if ($item['word_count'] > 0) {
                    $content .= " ({$item['word_count']} words)";
                }
                $content .= "\n";
            }
        }
        
        $content .= "\nGenerated on: " . $brief['created_at'];
        
        return $content;
    }

    /**
     * Validate keywords parameter
     *
     * @param array $keywords Keywords to validate
     * @return bool|WP_Error Validation result
     */
    public function validate_keywords($keywords): bool|WP_Error {
        // A custom validate_callback replaces WP's array type-coercion, so the
        // raw param arrives here as-is; reject non-arrays instead of letting a
        // strict array type hint throw an uncaught TypeError during dispatch.
        if (!is_array($keywords)) {
            return new WP_Error(
                'invalid_keywords',
                'Keywords must be provided as an array',
                ['status' => 400]
            );
        }

        if (empty($keywords)) {
            return new WP_Error(
                'invalid_keywords',
                'At least one keyword is required',
                ['status' => 400]
            );
        }

        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || empty(trim($keyword))) {
                return new WP_Error(
                    'invalid_keyword',
                    'All keywords must be non-empty strings',
                    ['status' => 400]
                );
            }
        }

        return true;
    }

    /**
     * Check permissions for API access
     *
     * @return bool Permission status
     */
    public function check_permissions(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Persistent per-user rate limit for the AI-backed generate route.
     *
     * Transient-backed (survives across requests) and keyed per user, mirroring
     * the llms-txt endpoint's AI throttle but with its own bucket so the two
     * features don't share a budget.
     *
     * @return bool True if the request is within the limit.
     */
    private function check_ai_rate_limit(): bool {
        $user_id = get_current_user_id();
        $rate_key = "thinkrank_ai_rate_content_brief_{$user_id}";

        $requests = (int) get_transient($rate_key);

        if ($requests >= 5) { // Max 5 content briefs per 10 minutes.
            return false;
        }

        set_transient($rate_key, $requests + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }
}
