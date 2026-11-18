<?php
/**
 * Title Experiments REST endpoints.
 *
 * - GET  /thinkrank/v1/title-experiments                → running experiments and recent results
 * - GET  /thinkrank/v1/title-experiments/{post_id}      → one page's experiment
 * - POST /thinkrank/v1/title-experiments/{post_id}      → start one
 * - POST /thinkrank/v1/title-experiments/{post_id}/stop → end it early
 * - POST /thinkrank/v1/title-experiments/{post_id}/apply → keep the variant
 * - POST /thinkrank/v1/title-experiments/{post_id}/suggest → AI variants to choose from
 *
 * @package ThinkRank\API
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\AI\Language_Resolver;
use ThinkRank\AI\Manager as AI_Manager;
use ThinkRank\Core\Capability_Manager;
use ThinkRank\Core\Plan_Config;
use ThinkRank\Core\Settings;
use ThinkRank\SEO\Experiments\Experiment_Measurer;
use ThinkRank\SEO\Experiments\Experiment_Schedule;
use ThinkRank\SEO\Experiments\Title_Experiments;
use ThinkRank\SEO\Focus_Keywords;
use ThinkRank\SEO\Pattern_Resolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Title Experiments endpoints (#719).
 *
 * Page-level routes are for anyone who can edit the page — the editor is
 * where a test starts. The site-wide list is for whoever can see Analytics.
 *
 * @since 2.22.0
 */
class Title_Experiments_Endpoint {

    private const NAMESPACE = 'thinkrank/v1';

    private const BASE = 'title-experiments';

    /**
     * Least post content, in characters, worth asking AI about — the floor
     * the editor's own Generate button and Bulk Snippets enforce.
     */
    private const MIN_CONTENT_LENGTH = 50;

    /**
     * Experiments.
     *
     * @var Title_Experiments
     */
    private Title_Experiments $experiments;

    /**
     * @param Title_Experiments|null $experiments Experiments (injected in tests).
     */
    public function __construct(?Title_Experiments $experiments = null) {
        $this->experiments = $experiments ?? new Title_Experiments();
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        $id = [
            'id' => [
                'type'              => 'integer',
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
        ];

        register_rest_route(self::NAMESPACE, '/' . self::BASE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_experiments'],
            'permission_callback' => [$this, 'can_view_list'],
        ]);

        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_experiment'],
                'permission_callback' => [$this, 'can_edit_post'],
                'args'                => $id,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_experiment'],
                'permission_callback' => [$this, 'can_edit_post'],
                'args'                => $id + [
                    'variants' => [
                        'type'     => 'array',
                        'required' => true,
                        'minItems' => 1,
                        'maxItems' => 3,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'title'       => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'source'      => ['type' => 'string', 'enum' => ['ai', 'manual']],
                            ],
                        ],
                    ],
                    'window'   => [
                        'type'    => 'integer',
                        'default' => Experiment_Schedule::DEFAULT_WINDOW,
                        'minimum' => Experiment_Schedule::MIN_WINDOW,
                        'maximum' => Experiment_Schedule::MAX_WINDOW,
                    ],
                ],
            ],
        ]);

        foreach (['stop', 'apply', 'suggest'] as $action) {
            register_rest_route(self::NAMESPACE, '/' . self::BASE . '/(?P<id>\d+)/' . $action, [
                'methods'             => 'POST',
                'callback'            => [$this, $action . '_experiment'],
                'permission_callback' => [$this, 'can_edit_post'],
                'args'                => $id,
            ]);
        }
    }

    /**
     * GET /title-experiments.
     *
     * @return WP_REST_Response
     */
    public function list_experiments(): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'active'        => array_map([$this, 'view'], $this->experiments->active()),
                'recent'        => array_map([$this, 'view'], $this->recent()),
                'gsc_connected' => (new Experiment_Measurer())->available(),
                'capabilities'  => Plan_Config::capabilities_for('title_experiments'),
            ],
        ], 200);
    }

    /**
     * GET /title-experiments/{id}.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_experiment(WP_REST_Request $request): WP_REST_Response {
        return $this->page_response((int) $request->get_param('id'));
    }

    /**
     * POST /title-experiments/{id}.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_experiment(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('id');
        $created = $this->experiments->create(
            $post_id,
            (array) $request->get_param('variants'),
            (int) $request->get_param('window')
        );

        return is_wp_error($created) ? $created : $this->page_response($post_id);
    }

    /**
     * POST /title-experiments/{id}/stop.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function stop_experiment(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('id');
        $stopped = $this->experiments->stop($post_id);

        return is_wp_error($stopped) ? $stopped : $this->page_response($post_id);
    }

    /**
     * POST /title-experiments/{id}/apply.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function apply_experiment(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('id');
        $applied = $this->experiments->apply($post_id);

        return is_wp_error($applied) ? $applied : $this->page_response($post_id);
    }

    /**
     * POST /title-experiments/{id}/suggest — AI variants to pick from. Never
     * starts anything.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function suggest_experiment(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!Settings::instance()->has_ai_provider_configured()) {
            return new WP_Error('thinkrank_experiment_no_ai', __('Set up an AI provider under Settings → AI Provider to get suggestions, or type a title to test yourself.', 'thinkrank'), ['status' => 400]);
        }

        $content = trim(wp_strip_all_tags((string) ($post->post_content ?? '')));
        if (mb_strlen($content) < self::MIN_CONTENT_LENGTH) {
            return new WP_Error('thinkrank_experiment_thin_content', __('This page has too little text for AI to suggest titles. Type a title to test instead.', 'thinkrank'), ['status' => 422]);
        }

        try {
            $variants = (new AI_Manager())->suggest_title_variants($post->post_title . "\n\n" . $content, [
                'current_title'       => Pattern_Resolver::effective_title($post_id),
                'current_description' => Pattern_Resolver::effective_description($post_id),
                'target_keyword'      => class_exists(Focus_Keywords::class) ? (string) (Focus_Keywords::get($post_id)[0] ?? '') : '',
                'language'            => class_exists(Language_Resolver::class) ? Language_Resolver::resolve($post_id) : '',
                'count'               => 3,
            ]);
        } catch (\Throwable $e) {
            return new WP_Error('thinkrank_experiment_ai_failed', $e->getMessage(), ['status' => 502]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['variants' => $variants],
        ], 200);
    }

    /**
     * Anyone who can edit the page.
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function can_edit_post(WP_REST_Request $request) {
        $post_id = absint($request->get_param('id'));
        if ($post_id > 0 && current_user_can('edit_post', $post_id)) {
            return true;
        }

        return new WP_Error('rest_forbidden', __('You cannot edit this page.', 'thinkrank'), ['status' => is_user_logged_in() ? 403 : 401]);
    }

    /**
     * Whoever can see Analytics.
     *
     * @return bool|WP_Error
     */
    public function can_view_list() {
        if (Capability_Manager::current_user_can('thinkrank_analytics')) {
            return true;
        }

        return new WP_Error('rest_forbidden', __('You cannot view title experiments.', 'thinkrank'), ['status' => is_user_logged_in() ? 403 : 401]);
    }

    /**
     * One page's state, as the editor reads it.
     *
     * @param int $post_id Post id.
     * @return WP_REST_Response
     */
    private function page_response(int $post_id): WP_REST_Response {
        $record = $this->experiments->get($post_id);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'experiment'    => null === $record ? null : $this->view($record),
                'locked'        => $this->experiments->is_locked($post_id),
                'gsc_connected' => (new Experiment_Measurer())->available(),
                'ai_configured' => Settings::instance()->has_ai_provider_configured(),
                'capabilities'  => Plan_Config::capabilities_for('title_experiments'),
                'current'       => [
                    'title'       => Pattern_Resolver::effective_title($post_id),
                    'description' => Pattern_Resolver::effective_description($post_id),
                ],
            ],
        ], 200);
    }

    /**
     * An experiment with what the screen needs to name the page.
     *
     * @param array<string, mixed> $record Experiment.
     * @return array<string, mixed>
     */
    public function view(array $record): array {
        $post_id = (int) $record['post_id'];

        return $record + [
            'post_title' => (string) get_the_title($post_id),
            'edit_url'   => (string) get_edit_post_link($post_id, 'raw'),
        ];
    }

    /**
     * The most recently finished experiments, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(): array {
        return $this->experiments->recent();
    }
}
