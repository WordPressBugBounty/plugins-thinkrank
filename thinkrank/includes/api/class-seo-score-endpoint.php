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
                // The permalink field as it stands in the editor, so an unsaved
                // slug edit scores immediately instead of matching the old URL.
                'live_slug' => [
                    'required' => false,
                    'type' => 'string',
                    // NOT sanitize_title: REST calls a sanitize_callback as
                    // ($value, $request, $param), and sanitize_title()'s second
                    // parameter is $fallback_title — so an empty slug returned
                    // the WP_REST_Request object, which fataled on the string
                    // cast below. The editor sends an empty slug whenever the
                    // permalink field is blank (every draft), so this fired on
                    // ordinary use. Sanitized with a single-argument
                    // sanitize_title() where it is consumed instead.
                    //
                    // Decoded first: the editor sends post_name as stored,
                    // and a non-Latin slug is stored percent-encoded
                    // (%e3%83%a9…). sanitize_text_field() strips %XX octets,
                    // so the slug arrived as "-", never matched the saved
                    // post and every Analyze on such a post scored the saved
                    // post a second time. sanitize_title() re-encodes the
                    // decoded slug to the stored form.
                    'sanitize_callback' => static function ($value): string {
                        return sanitize_text_field(rawurldecode((string) $value));
                    },
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

            // Score the slug the editor is showing. Omitted (null) keeps the
            // saved slug; an empty string means the field was cleared, which
            // falls back to the title-derived slug exactly as an unsaved draft
            // does inside the calculator.
            $live_slug = $request->get_param('live_slug');
            if ($live_slug !== null) {
                $content_data['slug'] = sanitize_title((string) $live_slug);
            }

            $raw_title = $live_title !== null
                ? (string) $live_title
                : get_post_meta($post_id, '_thinkrank_seo_title', true);
            $raw_description = $live_description !== null
                ? (string) $live_description
                : get_post_meta($post_id, '_thinkrank_meta_description', true);
            $metadata = $this->score_metadata((int) $post_id, $raw_title, $raw_description);

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

            // The browser's own readability_score and content_quality are no
            // longer applied. They used to be written over the labels this
            // request had just computed, and then persisted, so the saved
            // Readability and Content Quality described the editor text alone
            // (#829).
            //
            // That is wrong whenever the two see different content, which is
            // exactly when it matters: `analyze_live_content()` resolves what
            // the editor sent AND whatever `thinkrank_analyzable_content`
            // contributes on top, so a post with 2 words in the editor and 615
            // added by a filter scored 617 words server-side and saved
            // "Too short" / "Needs improvement" from the browser's 2.
            //
            // Nothing is lost by ignoring them: when `live_content` is non-empty
            // the server analysed the very text the editor measured, so its
            // labels cover the live case too. The parameters stay accepted so an
            // older cached bundle still gets a successful response, and they
            // stay documented in the route args; they simply no longer win.
            //
            // The panel keeps computing them in the browser for instant feedback
            // between calculations. What it must not do is persist them.
            unset($readability_score, $content_quality);

            // The resolved word count, so the panel can show the number this
            // score was actually calculated from rather than its own count of
            // the editor text (#829).
            $score_data['word_count'] = (int) ($content_data['word_count'] ?? 0);

            // Save score if requested.
            //
            // A request carrying the editor's unsaved state is scored on that
            // state, which is what the editor shows, but that score must not be
            // stored: the posts list and the dashboard read the stored row as
            // the post's current score whenever it is newer than
            // post_modified, which a row written a moment ago always is. An
            // author who pressed Analyze and left without saving had the list
            // show the score of content that was never saved (#1310). So the
            // row stored is the score of the saved post; when nothing was
            // edited the two are the same score.
            //
            // Scoring the saved post is a second full analysis (render,
            // headings, readability, links, schema), so it runs only when it
            // has to: not when the editor state is the saved post, and not
            // when the stored row already scores the saved post.
            if ($save_score) {
                $user_id = get_current_user_id();
                $carries_live_state = !$this->editor_state_is_saved(
                    (int) $post_id,
                    (string) $live_content,
                    $live_title,
                    $live_description,
                    $live_slug,
                    $request
                );

                if (!$carries_live_state) {
                    $to_store = $score_data;
                } elseif ($this->stored_score_is_current((int) $post_id)) {
                    $to_store = null;
                } else {
                    $to_store = $this->score_saved_post((int) $post_id);
                }

                $score_id = $to_store ? $this->calculator->save_score($post_id, $user_id, $to_store) : false;

                // score_id names the stored row, so only when that row holds
                // the score this response carries.
                if ($score_id && !$carries_live_state) {
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
     * The title, description and focus keywords a post is scored against.
     *
     * Scores against the final rendered SEO title/description: variable tags
     * in a custom value are resolved, and an empty field falls back to the
     * rendered Global/Bulk pattern, so the length-based checks match what the
     * frontend outputs.
     *
     * @since 2.18.0
     *
     * @param int   $post_id         Post ID.
     * @param mixed $raw_title       SEO title as typed or stored.
     * @param mixed $raw_description Meta description as typed or stored.
     * @return array{title: string, description: string, focus_keywords: string[]}
     */
    private function score_metadata(int $post_id, $raw_title, $raw_description): array {
        return [
            'title' => \ThinkRank\SEO\Pattern_Resolver::effective_value($raw_title, $post_id, 'title'),
            'description' => \ThinkRank\SEO\Pattern_Resolver::effective_value($raw_description, $post_id, 'description'),
            // Fallback source for the scorer when the request carries no
            // keyword (e.g. a plain "score this post" call). An explicit
            // request keyword still wins, so the editor keeps scoring
            // unsaved keyword edits live.
            'focus_keywords' => \ThinkRank\SEO\Focus_Keywords::get($post_id),
        ];
    }

    /**
     * Whether the state this request scored is the saved post.
     *
     * The editor always sends its body, title, description, slug and keywords,
     * edited or not, so the presence of a live field says nothing. When every
     * field it sent matches what is stored, the score it just calculated is
     * the saved post's score and can be stored as it is.
     *
     * Comparisons are exact: a field that differs only in formatting counts
     * as edited, which costs a second analysis but never stores a score of
     * content that was not saved. A Bricks page is always treated as edited,
     * because its saved body is resolved from Bricks storage, not from
     * post_content.
     *
     * @since 2.18.0
     *
     * @param int             $post_id          Post ID.
     * @param string          $live_content     Editor body, '' when none was sent.
     * @param mixed           $live_title       Editor SEO title, null when not sent.
     * @param mixed           $live_description Editor meta description, null when not sent.
     * @param mixed           $live_slug        Editor slug, null when not sent.
     * @param WP_REST_Request $request          The request, for its keyword params.
     * @return bool True when the request scored the saved post.
     */
    private function editor_state_is_saved(
        int $post_id,
        string $live_content,
        $live_title,
        $live_description,
        $live_slug,
        WP_REST_Request $request
    ): bool {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        if ($live_content !== '') {
            if ($live_content !== (string) $post->post_content
                || \ThinkRank\SEO\Builder_Content::bricks_supersedes_post_content($post_id)) {
                return false;
            }
        }

        if ($live_title !== null
            && (string) $live_title !== (string) get_post_meta($post_id, '_thinkrank_seo_title', true)) {
            return false;
        }

        if ($live_description !== null
            && (string) $live_description !== (string) get_post_meta($post_id, '_thinkrank_meta_description', true)) {
            return false;
        }

        if ($live_slug !== null && sanitize_title((string) $live_slug) !== (string) $post->post_name) {
            return false;
        }

        // Keywords, resolved the way the calculator resolves them: a non-empty
        // list wins, then a non-empty single keyword, then an explicit clear.
        // A request that names no keyword scored the stored ones.
        $target_keywords = $request->get_param('target_keywords');
        $target_keyword  = $request->get_param('target_keyword');
        if (is_array($target_keywords) && !empty($target_keywords)) {
            $requested = $target_keywords;
        } elseif ($target_keyword !== null && (string) $target_keyword !== '') {
            $requested = [$target_keyword];
        } elseif ($target_keywords !== null || $target_keyword !== null) {
            $requested = [];
        } else {
            return true;
        }

        return self::keyword_set($requested) === self::keyword_set(\ThinkRank\SEO\Focus_Keywords::get($post_id));
    }

    /**
     * Keywords normalized for comparison: trimmed, lowercased, deduplicated, in order.
     *
     * @param array $keywords Keywords.
     * @return string[]
     */
    private static function keyword_set(array $keywords): array {
        $set = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $set[function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword)] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * Whether the latest stored row already scores the saved post.
     *
     * The same rule the posts list applies: a row written at or after the
     * post's last modification is current. Rows are only ever stored from the
     * saved post, so a current row needs no rescoring.
     *
     * @since 2.18.0
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private function stored_score_is_current(int $post_id): bool {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || empty($post->post_modified)) {
            return false;
        }

        $latest = $this->calculator->get_latest_score($post_id);
        if (empty($latest['created_at'])) {
            return false;
        }

        return (string) $latest['created_at'] >= (string) $post->post_modified;
    }

    /**
     * Score the post as it is saved: its stored content, SEO title, meta
     * description, slug and focus keywords, ignoring anything the editor sent.
     *
     * This is the score worth storing when a request scored unsaved editor
     * state (#1310).
     *
     * @since 2.18.0
     *
     * @param int $post_id Post ID.
     * @return array|null Score data, or null when the saved post has nothing to analyze.
     */
    private function score_saved_post(int $post_id): ?array {
        $content_data = $this->calculator->analyze_post_content($post_id);
        if (empty($content_data)) {
            return null;
        }

        $score_data = $this->calculator->calculate_score(
            $content_data,
            $this->score_metadata(
                $post_id,
                get_post_meta($post_id, '_thinkrank_seo_title', true),
                get_post_meta($post_id, '_thinkrank_meta_description', true)
            )
        );
        $score_data['word_count'] = (int) ($content_data['word_count'] ?? 0);

        return $score_data;
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
                $existing_data['keywords'] = $this->keyword_placements((int) $post_id);

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
     * Where each focus keyword sits (#729), for a stored score. A stored score
     * predates the editor's current keywords and content, so this is read
     * from the post as saved now — a text pass, no request, no AI.
     *
     * @param int $post_id Post ID.
     * @return array<int, array<string, mixed>>
     */
    private function keyword_placements(int $post_id): array {
        return $this->calculator->keyword_placements(
            $this->calculator->analyze_post_content($post_id),
            [
                'title'       => \ThinkRank\SEO\Pattern_Resolver::effective_title($post_id),
                'description' => \ThinkRank\SEO\Pattern_Resolver::effective_description($post_id),
            ],
            \ThinkRank\SEO\Focus_Keywords::get($post_id)
        );
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
            
            // The stored row only — no keyword gauges (#729). The dashboard
            // asks for five posts at once and reads overall_score; a gauge
            // pass would analyse and render each whole post for nothing. The
            // editor reads its gauges from /seo-score/get.
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
