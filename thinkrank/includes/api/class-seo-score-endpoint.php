<?php
/**
 * SEO Score REST API Endpoint
 * 
 * Handles REST API endpoints for SEO score calculation and history
 * 
 * @package ThinkRank\API
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Database;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Score Endpoint Class
 * 
 * Provides REST API endpoints for:
 * - /wp-json/thinkrank/v1/seo-score/calculate
 * - /wp-json/thinkrank/v1/seo-score/history
 * 
 * @since 1.0.0
 */
class SEOScoreEndpoint {
    
    /**
     * SEO Score Calculator instance
     *
     * @var SEOScoreCalculator
     */
    private SEOScoreCalculator $calculator;
    
    /**
     * Constructor
     *
     * @param SEOScoreCalculator $calculator SEO Score Calculator instance
     */
    public function __construct(SEOScoreCalculator $calculator) {
        $this->calculator = $calculator;
    }
    
    /**
     * Initialize the endpoint
     * 
     * @return void
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes
     * 
     * @return void
     */
    public function register_routes(): void {
        // Calculate SEO score endpoint
        register_rest_route('thinkrank/v1', '/seo-score/calculate', [
            'methods' => 'POST',
            'callback' => [$this, 'calculate_score'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => [$this, 'validate_post_id'],
                ],
                'target_keyword' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'target_keywords' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'sanitize_callback' => function ($value) {
                        if (!is_array($value)) {
                            return [];
                        }
                        return array_map('sanitize_text_field', $value);
                    },
                ],
                'save_score' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true,
                ],
                'live_content' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                ],
                // Unsaved SEO title/description straight from the editor. Both
                // are scored factors, and without them an "Apply" that changes
                // the title scores against the stale saved value — the score
                // only moved once the post was saved, which read as the score
                // updating minutes later on its own. Omitted (null) means "use
                // what's saved"; an empty string means the user cleared the
                // field, which must fall back to the rendered pattern exactly
                // as an empty stored value does.
                'live_title' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'live_description' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'readability_score' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'content_quality' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Get existing SEO score endpoint
        register_rest_route('thinkrank/v1', '/seo-score/get', [
            'methods' => 'GET',
            'callback' => [$this, 'get_existing_score'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => [$this, 'validate_post_id'],
                ],
            ],
        ]);
        
        // Get score history endpoint
        register_rest_route('thinkrank/v1', '/seo-score/history', [
            'methods' => 'GET',
            'callback' => [$this, 'get_score_history'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => [$this, 'validate_post_id'],
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
        ]);
        
        // Get latest score endpoint
        register_rest_route('thinkrank/v1', '/seo-score/latest', [
            'methods' => 'GET',
            'callback' => [$this, 'get_latest_score'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => [$this, 'validate_post_id'],
                ],
            ],
        ]);
    }
    
    /**
     * Calculate SEO score for a post
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function calculate_score(WP_REST_Request $request) {
        try {
            $post_id = $request->get_param('post_id');
            $target_keyword = $request->get_param('target_keyword') ?? '';
            $target_keywords = $request->get_param('target_keywords');
            $save_score = $request->get_param('save_score') ?? true;
            $live_content = $request->get_param('live_content') ?? '';
            $readability_score = $request->get_param('readability_score') ?? null;
            $content_quality = $request->get_param('content_quality') ?? null;

            // Analyze content - use live content if provided, otherwise saved content
            if (!empty($live_content)) {
                $content_data = $this->calculator->analyze_live_content($live_content, $post_id);
            } else {
                $content_data = $this->calculator->analyze_post_content($post_id);
            }

            if (empty($content_data)) {
                return new WP_Error(
                    'post_not_found',
                    'Post not found or has no content',
                    ['status' => 404]
                );
            }

            // Get post metadata. Score against the FINAL rendered SEO title/
            // description — resolve any variable tags in a custom value, and fall
            // back to the rendered Global/Bulk pattern when the field is empty, so
            // the length-based scoring matches what the frontend actually outputs.
            // Prefer the editor's live values when the request carried them, so
            // an unsaved title/description edit scores immediately instead of
            // waiting for the post to be saved. `null` means the request said
            // nothing about the field, which keeps the saved value.
            $live_title       = $request->get_param('live_title');
            $live_description = $request->get_param('live_description');

            $raw_title = $live_title !== null
                ? (string) $live_title
                : get_post_meta($post_id, '_thinkrank_seo_title', true);
            $raw_description = $live_description !== null
                ? (string) $live_description
                : get_post_meta($post_id, '_thinkrank_meta_description', true);
            $metadata = [
                'title' => \ThinkRank\SEO\Pattern_Resolver::effective_value($raw_title, $post_id, 'title'),
                'description' => \ThinkRank\SEO\Pattern_Resolver::effective_value($raw_description, $post_id, 'description'),
                // Fallback source for the scorer when the request carries no
                // keyword (e.g. a plain "score this post" call). An explicit
                // request keyword still wins, so the editor keeps scoring
                // unsaved keyword edits live.
                'focus_keywords' => \ThinkRank\SEO\Focus_Keywords::get($post_id),
            ];

            // Calculate score. Prefer the multi-keyword list when provided; the
            // calculator scores each keyword and returns the highest as final.
            //
            // Only forward keyword options when the request actually carried
            // them. The editor always sends its live keyword state (including
            // empty, when the user clears the field) and that must be honored
            // verbatim; a request that mentions no keyword at all leaves the
            // options untouched so the calculator falls back to the keywords
            // stored on the post.
            $score_options = [];
            if ($request->get_param('target_keyword') !== null) {
                $score_options['target_keyword'] = (string) $target_keyword;
            }
            if (is_array($target_keywords)) {
                $score_options['target_keywords'] = $target_keywords;
            }
            $score_data = $this->calculator->calculate_score(
                $content_data,
                $metadata,
                $score_options
            );

            // Add readability_score and content_quality from frontend if provided
            if (!empty($readability_score)) {
                $score_data['readability_score'] = $readability_score;
            }
            if (!empty($content_quality)) {
                $score_data['content_quality'] = $content_quality;
            }

            // Save score if requested
            if ($save_score) {
                $user_id = get_current_user_id();
                $score_id = $this->calculator->save_score($post_id, $user_id, $score_data);

                if ($score_id) {
                    $score_data['score_id'] = $score_id;
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $score_data,
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'calculation_failed',
                'Failed to calculate SEO score: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get existing SEO score for a post
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_existing_score(WP_REST_Request $request) {
        try {
            $post_id = $request->get_param('post_id');

            // Get existing score data from database
            $existing_data = $this->calculator->get_existing_score_data($post_id);

            if ($existing_data) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => $existing_data,
                    'message' => __('Existing SEO score retrieved successfully', 'thinkrank')
                ], 200);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'data' => null,
                    'message' => __('No existing SEO analysis found', 'thinkrank')
                ], 200); // 200 because it's not an error, just no data
            }

        } catch (\Exception $e) {
            return new WP_Error('get_score_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Get score history for a post
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_score_history(WP_REST_Request $request) {
        try {
            $post_id = $request->get_param('post_id');
            $limit = $request->get_param('limit') ?? 10;
            
            $history = $this->calculator->get_score_history($post_id, $limit);
            
            return new WP_REST_Response([
                'success' => true,
                'data' => $history,
            ], 200);
            
        } catch (\Exception $e) {
            return new WP_Error(
                'history_failed',
                'Failed to retrieve score history: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Get latest score for a post
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function get_latest_score(WP_REST_Request $request) {
        try {
            $post_id = $request->get_param('post_id');
            
            $latest_score = $this->calculator->get_latest_score($post_id);
            
            return new WP_REST_Response([
                'success' => true,
                'data' => $latest_score,
            ], 200);
            
        } catch (\Exception $e) {
            return new WP_Error(
                'latest_failed',
                'Failed to retrieve latest score: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Check permissions for API access
     * 
     * @param WP_REST_Request $request Request object
     * @return bool True if user has permission
     */
    public function check_permissions(WP_REST_Request $request): bool {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return false;
        }
        
        // Check if user can edit posts
        if (!current_user_can('edit_posts')) {
            return false;
        }
        
        // For specific post operations, check if user can edit the specific post
        $post_id = $request->get_param('post_id');
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate post ID parameter
     * 
     * @param mixed $value Parameter value
     * @param WP_REST_Request $request Request object
     * @param string $param Parameter name
     * @return bool True if valid
     */
    public function validate_post_id($value, WP_REST_Request $request, string $param): bool {
        if (!is_numeric($value) || $value <= 0) {
            return false;
        }
        
        $post = get_post((int) $value);
        return $post !== null;
    }
}
