<?php
/**
 * Bulk Snippets REST endpoints.
 *
 * Every post's SEO title, meta description, focus keyword and index state for
 * one post type, filterable by what is wrong with it, plus batch save and a
 * per-post AI suggestion that is never written without the user saving (#727).
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Admin\Metabox_Manager;
use ThinkRank\AI\Metadata_Generator;
use ThinkRank\Core\Capability_Manager;
use ThinkRank\Core\Settings;
use ThinkRank\SEO\Focus_Keywords;
use ThinkRank\SEO\Global_SEO_Post_Types;
use ThinkRank\SEO\Snippet_Index;
use ThinkRank\SEO\Snippet_Issues;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bulk Snippets endpoints.
 *
 * Lives under `global-seo/` rather than at `/snippets`: the screen is part of
 * the Bulk SEO Optimization section, and the Role Manager maps that route
 * prefix to the section's capability, so a role granted the section gets the
 * screen and one denied it does not — with no second mapping to keep in step.
 *
 * @since 2.8.0
 */
class Snippets_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     */
    private const REST_BASE = 'global-seo/snippets';

    /**
     * Largest page the list will serve.
     */
    private const PER_PAGE_MAX = 100;

    /**
     * Largest batch a single save will accept.
     */
    private const SAVE_MAX = 100;

    /**
     * How many rows one bulk AI-fill run may generate in the free plugin.
     *
     * Each row is a paid request against the site's own key, so an accidental
     * "select all" on 4,000 posts should not be able to spend it. Pro raises
     * this through the `thinkrank_bulk_ai_fill_cap` filter.
     */
    public const DEFAULT_AI_FILL_CAP = 25;

    /**
     * Longest value a snippet field accepts. Far past anything useful — it
     * exists so the endpoint cannot be used to write megabytes of meta.
     */
    private const FIELD_MAX_LENGTH = 1000;

    /**
     * Least post content, in characters, worth asking AI to describe. Matches
     * the floor the editor's own Generate with AI button enforces.
     */
    private const MIN_CONTENT_LENGTH = 50;

    /**
     * Post statuses each status filter covers. Public so the
     * list-snippet-issues ability filters by exactly the same sets.
     */
    public const STATUS_SETS = [
        'publish' => ['publish'],
        'draft'   => ['draft', 'pending', 'future'],
        'any'     => ['publish', 'future', 'draft', 'pending', 'private'],
    ];

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_snippets'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'post_type' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'issue' => [
                        'type'              => 'string',
                        'default'           => '',
                        'enum'              => array_merge([''], Snippet_Issues::all()),
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'status' => [
                        'type'              => 'string',
                        'default'           => 'publish',
                        'enum'              => array_keys(self::STATUS_SETS),
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'search' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'type'              => 'integer',
                        'default'           => 20,
                        'minimum'           => 1,
                        'maximum'           => self::PER_PAGE_MAX,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_snippets'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'items' => [
                        'type'     => 'array',
                        'required' => true,
                        'maxItems' => self::SAVE_MAX,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'post_id'       => ['type' => 'integer'],
                                'title'         => ['type' => 'string'],
                                'description'   => ['type' => 'string'],
                                'focus_keyword' => ['type' => 'string'],
                                'original'      => ['type' => 'object'],
                            ],
                        ],
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/(?P<id>\d+)/generate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_suggestion'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Section permission. Per-post edit rights are checked per row.
     *
     * @return bool
     */
    public function check_permissions(): bool {
        return Capability_Manager::current_user_can('thinkrank_global_seo');
    }

    /**
     * How many rows one bulk AI-fill run may generate.
     *
     * @since 2.8.0
     *
     * @return int
     */
    public static function ai_fill_cap(): int {
        /**
         * Filters how many rows one Bulk Snippets AI-fill run may generate.
         *
         * @since 2.8.0
         *
         * @param int $cap Maximum rows per run. 0 or less means unlimited.
         */
        return (int) apply_filters('thinkrank_bulk_ai_fill_cap', self::DEFAULT_AI_FILL_CAP);
    }

    /**
     * Is the selected AI provider configured?
     *
     * Defers to Settings::has_ai_provider_configured() when it exists (#721),
     * which knows that an OpenAI-compatible endpoint is configured by URL and
     * model rather than by key. Until then, the selected provider's key is the
     * answer — the same test the metabox uses.
     *
     * @since 2.8.0
     *
     * @return bool
     */
    private static function ai_configured(): bool {
        $settings = Settings::instance();

        if (method_exists($settings, 'has_ai_provider_configured')) {
            return (bool) $settings->has_ai_provider_configured();
        }

        $provider = (string) $settings->get('ai_provider', '');

        // A provider stored by a newer build (openai_compatible before #721
        // lands) has no client here, so a key under its name is not enough.
        return in_array($provider, Settings::selectable_ai_providers(), true)
            && '' !== $provider
            && !empty($settings->get($provider . '_api_key'));
    }

    /**
     * GET — one page of snippets, with issue counts for the whole filtered set.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function list_snippets(WP_REST_Request $request) {
        $post_type = (string) $request->get_param('post_type');
        if (!Global_SEO_Post_Types::is_allowed($post_type)) {
            return new WP_Error(
                'thinkrank_snippets_post_type',
                __('That post type is not one ThinkRank manages.', 'thinkrank'),
                ['status' => 400]
            );
        }

        $status   = (string) $request->get_param('status');
        $per_page = min(self::PER_PAGE_MAX, max(1, (int) $request->get_param('per_page')));

        $result = self::page([
            'post_type' => $post_type,
            'statuses'  => self::STATUS_SETS[$status] ?? self::STATUS_SETS['publish'],
            'issue'     => (string) $request->get_param('issue'),
            'search'    => trim((string) $request->get_param('search')),
            'page'      => max(1, (int) $request->get_param('page')),
            'per_page'  => $per_page,
        ]);

        return new WP_REST_Response([
            'rows'         => array_map([self::class, 'present'], $result['snippets']),
            'total'        => $result['total'],
            'total_pages'  => (int) max(1, ceil($result['total'] / $per_page)),
            'total_all'    => $result['total_all'],
            'page'         => $result['page'],
            'per_page'     => $per_page,
            'counts'       => $result['counts'],
            'with_problem' => $result['with_problem'],
            // Posts whose verdict is still being worked out. While this is above
            // zero the counts cover only the posts already analysed, and the
            // screen keeps asking until it reaches zero.
            'index'        => ['pending' => $result['pending']],
            'limits'       => [
                'title_min'       => \ThinkRank\AI\SEOScoreCalculator::TITLE_OPTIMAL_MIN,
                'title_max'       => \ThinkRank\AI\SEOScoreCalculator::TITLE_OPTIMAL_MAX,
                'description_min' => \ThinkRank\AI\SEOScoreCalculator::DESCRIPTION_OPTIMAL_MIN,
                'description_max' => \ThinkRank\AI\SEOScoreCalculator::DESCRIPTION_OPTIMAL_MAX,
            ],
            'ai'           => [
                'configured' => self::ai_configured(),
                'fill_cap'   => self::ai_fill_cap(),
            ],
        ]);
    }

    /**
     * One page of snippets, answered from the persisted index.
     *
     * The work a request does is bounded: {@see Snippet_Index::refresh()}
     * builds at most one batch of stale entries, counts and the page come from
     * SQL over the index, and only the rows on the page are resolved in full.
     * Shared with the list-snippet-issues ability so the screen and an agent
     * see the same posts and counts.
     *
     * Only posts the current user may read are counted or returned. The
     * section capability is delegable through the Role Manager, so it does not
     * prove the user may read every draft or private post of the type.
     *
     * @since 2.8.0
     *
     * @param array{post_type:string, statuses:string[], issue:string, search:string, page:int, per_page:int} $args Query.
     * @return array{snippets: array<int,array<string,mixed>>, total:int, page:int, counts:array<string,int>, with_problem:int, pending:int}
     */
    public static function page(array $args): array {
        $pending = Snippet_Index::refresh($args['post_type'], $args['statuses']);

        $result = Snippet_Index::query([
            'post_type'      => $args['post_type'],
            'statuses'       => $args['statuses'],
            'issue'          => $args['issue'],
            'search'         => $args['search'],
            'page'           => $args['page'],
            'per_page'       => $args['per_page'],
            'visibility_sql' => Snippet_Index::visibility_sql($args['post_type']),
        ]);

        _prime_post_caches($result['ids'], false, true);

        $snippets = [];
        foreach ($result['ids'] as $post_id) {
            $post = get_post($post_id);

            // The SQL above already scopes to readable posts; this is the
            // per-post check itself, so a policy the SQL cannot express (a
            // plugin filtering map_meta_cap) is still honoured.
            if (!$post instanceof \WP_Post || !current_user_can('read_post', $post_id)) {
                continue;
            }

            $snippets[] = self::judge(
                Snippet_Issues::snapshot($post),
                in_array($post_id, $result['duplicate_ids'], true)
            );
        }

        return [
            'snippets'     => $snippets,
            'total'        => $result['total'],
            'total_all'    => $result['total_all'],
            'page'         => $args['page'],
            'counts'       => $result['counts'],
            'with_problem' => $result['with_problem'],
            'pending'      => $pending,
        ];
    }

    /**
     * Attach the verdict to a snapshot.
     *
     * @param array<string,mixed> $snapshot  From {@see Snippet_Issues::snapshot()}.
     * @param bool                $duplicate Whether another post shares its title.
     * @return array<string,mixed>
     */
    private static function judge(array $snapshot, bool $duplicate): array {
        $snapshot['duplicate_title'] = $duplicate;
        $snapshot['issues'] = Snippet_Issues::evaluate($snapshot);

        return $snapshot;
    }

    /**
     * Shape one collected snippet for the response.
     *
     * @since 2.8.0
     *
     * @param array<string,mixed> $snippet From {@see self::page()}.
     * @return array<string,mixed>
     */
    public static function present(array $snippet): array {
        /** @var \WP_Post $post */
        $post = $snippet['post'];

        return [
            'post_id'               => (int) $post->ID,
            'post_title'            => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'status'                => $post->post_status,
            'edit_url'              => (string) get_edit_post_link($post->ID, 'raw'),
            'permalink'             => (string) get_permalink($post),
            'title'                 => $snippet['raw_title'],
            'description'           => $snippet['raw_description'],
            'focus_keyword'         => $snippet['focus_keyword'],
            // What the page renders when the field above is empty, so the
            // table can show the inherited template value as a placeholder.
            'effective_title'       => $snippet['effective_title'],
            'effective_description' => $snippet['effective_description'],
            'noindex'               => (bool) $snippet['noindex'],
            'noindex_source'        => $snippet['noindex_source'],
            'issues'                => $snippet['issues'],
            'can_edit'              => current_user_can('edit_post', $post->ID),
        ];
    }

    /**
     * POST — save a batch of edited snippets.
     *
     * Each row is authorized and saved on its own, and the response reports
     * each row's outcome with the values actually stored — a row the store
     * reduced (sanitization, a background write it declined to clobber) is
     * reported as what it became, not as what was sent.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function save_snippets(WP_REST_Request $request): WP_REST_Response {
        $items = $request->get_param('items');
        $items = is_array($items) ? array_slice($items, 0, self::SAVE_MAX) : [];

        $manager = new Metabox_Manager();
        $results = [];
        $saved = 0;
        $reload = [];
        $saved_ids = [];

        foreach ($items as $item) {
            $result = self::save_one(is_array($item) ? $item : [], $manager);
            if (!empty($result['success'])) {
                $saved++;
                $post = get_post((int) $result['post_id']);
                $reload[$post->post_type][$post->post_status] = true;
                $saved_ids[$post->post_type][] = (int) $post->ID;
            }
            $results[] = $result;
        }

        // Re-read and re-judge what was saved, so each row the client
        // re-renders is the one now in storage, with issue flags that match it.
        // Only the saved posts are rebuilt; duplicate status comes from the
        // index, so peers need no work — the other half of a duplicate pair
        // clears itself the next time the list is read.
        $fresh = [];
        foreach ($reload as $post_type => $statuses) {
            $ids = $saved_ids[$post_type] ?? [];
            Snippet_Index::rebuild($ids);
            $duplicates = Snippet_Index::duplicates_among($post_type, array_keys($statuses), $ids);

            foreach ($ids as $post_id) {
                $post = get_post($post_id);
                if ($post instanceof \WP_Post) {
                    $fresh[$post_id] = self::judge(
                        Snippet_Issues::snapshot($post),
                        in_array($post_id, $duplicates, true)
                    );
                }
            }
        }

        foreach ($results as $index => $result) {
            $post_id = (int) $result['post_id'];
            if (!empty($result['success']) && isset($fresh[$post_id])) {
                $results[$index]['row'] = self::present($fresh[$post_id]);
            }
        }

        return new WP_REST_Response([
            'success' => $saved === count($items),
            'saved'   => $saved,
            'failed'  => count($items) - $saved,
            'results' => $results,
        ], $saved > 0 || empty($items) ? 200 : 400);
    }

    /**
     * Save one row.
     *
     * @param array<string,mixed> $item    Row payload.
     * @param Metabox_Manager     $manager Shared persistence.
     * @return array<string,mixed> Row result.
     */
    private static function save_one(array $item, Metabox_Manager $manager): array {
        $post_id = absint($item['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;

        if (!$post instanceof \WP_Post || !Global_SEO_Post_Types::is_allowed($post->post_type)) {
            return self::row_error($post_id, __('Post not found.', 'thinkrank'));
        }

        if (!current_user_can('edit_post', $post_id)) {
            return self::row_error($post_id, __('You are not allowed to edit this post.', 'thinkrank'));
        }

        $map = [
            'title'         => 'thinkrank_seo_title',
            'description'   => 'thinkrank_meta_description',
            'focus_keyword' => 'thinkrank_focus_keyword',
        ];

        $original = is_array($item['original'] ?? null) ? $item['original'] : [];
        $fields = [];

        foreach ($map as $key => $field) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $value = (string) $item[$key];
            if (mb_strlen($value) > self::FIELD_MAX_LENGTH) {
                return self::row_error(
                    $post_id,
                    /* translators: %d: maximum length in characters. */
                    sprintf(__('A value is longer than %d characters.', 'thinkrank'), self::FIELD_MAX_LENGTH)
                );
            }

            $fields[$field] = $value;

            // The value the table loaded. When the user clears a field that was
            // blank on screen but has since been filled in storage — Auto AI or
            // an import landed while the table was open — the shared metabox
            // persistence keeps the stored value instead of blanking it.
            if (array_key_exists($key, $original) && 'focus_keyword' !== $key) {
                $fields[$field . '__orig'] = (string) $original[$key];
            }
        }

        if (empty($fields)) {
            return self::row_error($post_id, __('Nothing to save for this post.', 'thinkrank'));
        }

        $manager->save_seo_fields($post_id, $fields);

        return [
            'post_id' => $post_id,
            'success' => true,
        ];
    }

    /**
     * POST — an AI suggestion for one post, returned and never saved.
     *
     * The client runs these one post at a time so a run of 25 is 25 short
     * requests the user can watch and cancel, not one request that outlives
     * PHP's time limit. Nothing is written: the user reviews the suggestion in
     * the table and saves it like any other edit.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function generate_suggestion(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post || !Global_SEO_Post_Types::is_allowed($post->post_type)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Post not found.', 'thinkrank')], 404);
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_REST_Response(['success' => false, 'message' => __('You are not allowed to edit this post.', 'thinkrank')], 403);
        }

        if (!self::ai_configured()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('AI is not set up yet. Choose a provider under ThinkRank → Settings.', 'thinkrank'),
            ], 400);
        }

        // Same floor the editor's "Generate with AI" applies. Below it the
        // model has only the post title to go on and answers with filler — a
        // bulk run over empty posts would spend the user's key on "..." and put
        // it in front of them for review.
        $text = trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
        if (mb_strlen($text) < self::MIN_CONTENT_LENGTH) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'thinkrank_snippets_thin_content',
                'message' => __('This post has too little content for AI to describe. Add content first, or write the snippet by hand.', 'thinkrank'),
            ], 422);
        }

        try {
            $keywords = Focus_Keywords::get($post_id);
            $metadata = (new Metadata_Generator())->suggest_for_post($post_id, [
                // Keep the user's keyword when there is one; the suggestion
                // should serve it, not replace it.
                'target_keyword' => (string) ($keywords[0] ?? ''),
            ]);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => wp_strip_all_tags($e->getMessage()),
            ], 502);
        }

        $suggestion = [
            'title'         => trim((string) ($metadata['title'] ?? '')),
            'description'   => trim((string) ($metadata['description'] ?? '')),
            'focus_keyword' => trim((string) ($metadata['focus_keyword'] ?? '')),
        ];

        // A reply with no letters or digits in it ("...", "—") is a model
        // declining to answer, not a snippet. Offering it for review invites a
        // tired reviewer to save punctuation as a meta description.
        foreach (['title', 'description'] as $field) {
            if (!preg_match('/[\p{L}\p{N}]/u', $suggestion[$field])) {
                return new WP_REST_Response([
                    'success' => false,
                    'code'    => 'thinkrank_snippets_empty_suggestion',
                    'message' => __('The AI did not return a usable title and description for this post. Try again, or write them by hand.', 'thinkrank'),
                ], 502);
            }
        }

        return new WP_REST_Response([
            'success'    => true,
            'post_id'    => $post_id,
            'suggestion' => $suggestion,
        ]);
    }

    /**
     * A failed row.
     *
     * @param int    $post_id Post ID.
     * @param string $message Reason.
     * @return array<string,mixed>
     */
    private static function row_error(int $post_id, string $message): array {
        return [
            'post_id' => $post_id,
            'success' => false,
            'message' => $message,
        ];
    }
}
