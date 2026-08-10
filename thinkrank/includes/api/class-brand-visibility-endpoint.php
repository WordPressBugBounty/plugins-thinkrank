<?php
/**
 * Brand Visibility v2 REST endpoints.
 *
 * Config (brand profile, competitors, questions, platforms, keys), AI question
 * generation, run start, and progress/results reads.
 *
 * Every write is clamped to the current plan HERE as well as in the UI: the UI
 * limit is a courtesy, this one is the rule. Runs are started asynchronously —
 * `POST /run` returns a run id immediately and the client polls, because a
 * full analysis is 100+ provider calls and no REST request should hold that.
 *
 * @package ThinkRank\API
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\AI\Brand_Visibility_Providers;
use ThinkRank\AI\Brand_Visibility_Runner;
use ThinkRank\AI\Brand_Visibility_Scorer;
use ThinkRank\AI\Manager as AI_Manager;
use ThinkRank\Core\Plan_Config;
use ThinkRank\Core\Settings;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller for Brand Visibility v2.
 */
class Brand_Visibility_Endpoint extends WP_REST_Controller {

    /**
     * Route namespace.
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'brand-visibility';

    /**
     * Admin-only: these routes read and write API keys and spend the user's
     * AI credits.
     *
     * @return bool
     */
    public function check_admin_permissions(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Hard ceiling on saved questions, matching the wizard.
     */
    private const MAX_QUERIES = 12;

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/config', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_config'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_config'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/generate-queries', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_queries'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start_run'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/run/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_run'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/runs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_runs'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);
    }

    /**
     * Current configuration, plan caps and platform availability.
     *
     * @return WP_REST_Response
     */
    public function get_config(): WP_REST_Response {
        $settings  = Settings::instance();
        $caps      = Plan_Config::ai_visibility();
        $providers = new Brand_Visibility_Providers($settings);

        $brand = (string) $settings->get('bv_brand_name', '');

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'brand'       => '' !== $brand ? $brand : (string) get_bloginfo('name'),
                'variants'    => (array) $settings->get('bv_variants', []),
                'location'    => (string) $settings->get('bv_location', ''),
                'category'    => (string) $settings->get('bv_category', ''),
                'description' => (string) $settings->get('bv_description', (string) get_bloginfo('description')),
                'competitors' => (array) $settings->get('bv_competitors', []),
                'queries'     => (array) $settings->get('bv_queries', []),
                'platforms'   => (array) $settings->get('bv_platforms', ['chatgpt']),
                'samples'     => (int) $settings->get('bv_samples', 1),
                'host'        => (string) wp_parse_url(home_url(), PHP_URL_HOST),
                'configured'  => !empty($settings->get('bv_queries', [])),
                // Which platforms have a usable key, and whether it's their own
                // or borrowed from the site-wide provider.
                'available_platforms' => $providers->available_platforms(),
                // Keys are never returned in full — only whether one is stored
                // and a masked preview (e.g. "sk-pr••••••••ioA") for display.
                'keys_set'    => $this->keys_set($settings),
                'keys_masked' => $this->keys_masked($settings),
                // Effective model per platform plus the ids the picker may offer.
                'models'        => $providers->models(),
                'model_options' => Brand_Visibility_Providers::MODEL_CHOICES,
                'plan'        => $caps,
                'is_pro'      => Plan_Config::is_pro(),
                'query_types' => Brand_Visibility_Scorer::QUERY_TYPES,
                'weights'     => Brand_Visibility_Scorer::WEIGHTS,
            ],
        ], 200);
    }

    /**
     * Persist configuration, clamped to the plan.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function save_config(WP_REST_Request $request): WP_REST_Response {
        $settings = Settings::instance();
        $caps     = Plan_Config::ai_visibility();

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }

        if (isset($params['brand'])) {
            $settings->set('bv_brand_name', sanitize_text_field((string) $params['brand']));
        }
        if (isset($params['location'])) {
            $settings->set('bv_location', sanitize_text_field((string) $params['location']));
        }
        if (isset($params['category'])) {
            $settings->set('bv_category', sanitize_text_field((string) $params['category']));
        }
        if (isset($params['description'])) {
            $settings->set('bv_description', sanitize_textarea_field((string) $params['description']));
        }

        if (isset($params['variants']) && is_array($params['variants'])) {
            $variants = array_values(array_filter(array_map(
                static fn($v): string => sanitize_text_field((string) $v),
                $params['variants']
            )));
            $settings->set('bv_variants', array_slice($variants, 0, 10));
        }

        if (isset($params['competitors']) && is_array($params['competitors'])) {
            $max = (int) ($caps['brand_competitors'] ?? 0);

            $competitors = [];
            foreach ($params['competitors'] as $competitor) {
                $name = sanitize_text_field((string) ($competitor['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $competitors[] = [
                    'name' => $name,
                    'url'  => esc_url_raw((string) ($competitor['url'] ?? '')),
                ];
            }

            $settings->set('bv_competitors', $max > 0 ? array_slice($competitors, 0, $max) : []);
        }

        if (isset($params['queries']) && is_array($params['queries'])) {
            $limit = $this->query_limit($caps);

            $queries = [];
            foreach ($params['queries'] as $query) {
                $text = sanitize_text_field((string) ($query['text'] ?? $query));
                if ('' === $text) {
                    continue;
                }
                $type = sanitize_key((string) ($query['type'] ?? 'branded'));
                if (!in_array($type, Brand_Visibility_Scorer::QUERY_TYPES, true)) {
                    $type = 'branded';
                }
                $queries[] = ['text' => $text, 'type' => $type];
            }

            $settings->set('bv_queries', array_slice($queries, 0, $limit));
        }

        if (isset($params['platforms']) && is_array($params['platforms'])) {
            $allowed = array_keys(Brand_Visibility_Providers::PLATFORMS);
            $chosen  = array_values(array_intersect(
                array_map('sanitize_key', $params['platforms']),
                $allowed
            ));

            $max = (int) ($caps['brand_max_platforms'] ?? 1);
            $settings->set('bv_platforms', array_slice($chosen, 0, max(1, $max)));
        }

        if (isset($params['samples'])) {
            $max = max(1, (int) ($caps['brand_max_samples'] ?? 1));
            $settings->set('bv_samples', max(1, min($max, (int) $params['samples'])));
        }

        // Per-platform model choice. An empty string resets to the platform
        // default; an id outside the offered list is ignored.
        if (isset($params['models']) && is_array($params['models'])) {
            foreach ($params['models'] as $platform => $model) {
                $platform = sanitize_key((string) $platform);
                if (!isset(Brand_Visibility_Providers::PLATFORMS[$platform])) {
                    continue;
                }

                $model = sanitize_text_field((string) $model);
                if ('' !== $model && !Brand_Visibility_Providers::is_valid_model($platform, $model)) {
                    continue;
                }

                $settings->set(Brand_Visibility_Providers::model_option($platform), $model);
            }
        }

        // Per-platform API keys. An empty string clears a key; a key is never
        // echoed back by get_config().
        if (isset($params['keys']) && is_array($params['keys'])) {
            foreach ($params['keys'] as $platform => $key) {
                $platform = sanitize_key((string) $platform);
                if (!isset(Brand_Visibility_Providers::PLATFORMS[$platform])) {
                    continue;
                }
                $settings->set(
                    Brand_Visibility_Providers::key_option($platform),
                    sanitize_text_field((string) $key)
                );
            }
        }

        return $this->get_config();
    }

    /**
     * Generate starter questions with AI, grouped by type.
     *
     * Cheaper and better than making the user invent them: the four types
     * (branded, category, problem, comparison) are what separate "do people
     * find me when they already know my name" from "do people find me at all".
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function generate_queries(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params() ?: [];

        $brand       = sanitize_text_field((string) ($params['brand'] ?? get_bloginfo('name')));
        $category    = sanitize_text_field((string) ($params['category'] ?? ''));
        $location    = sanitize_text_field((string) ($params['location'] ?? ''));
        $description = sanitize_textarea_field((string) ($params['description'] ?? ''));

        $competitors = [];
        foreach ((array) ($params['competitors'] ?? []) as $competitor) {
            $name = sanitize_text_field((string) ($competitor['name'] ?? ''));
            if ('' !== $name) {
                $competitors[] = $name;
            }
        }

        try {
            $ai = new AI_Manager();
            $ai->initialize_client();

            $prompt = $this->query_prompt($brand, $category, $location, $description, $competitors);
            $result = $ai->answer_prompt($prompt, 4000, ['reasoning_effort' => 'minimal']);
            $text   = (string) ($result['ai_text'] ?? '');

            $queries = $this->parse_generated_queries($text);

            // Comparison questions are deterministic — build them from the
            // competitor list rather than trusting the model to echo names.
            foreach ($competitors as $name) {
                $queries[] = ['text' => sprintf('%s vs %s', $brand, $name), 'type' => 'comparison'];
            }

            if (empty($queries)) {
                throw new \Exception(esc_html__('The AI did not return any usable questions.', 'thinkrank'));
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => ['queries' => array_slice($queries, 0, self::MAX_QUERIES)],
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                // The wizard stays usable without AI — the user can type their
                // own questions, so this is a soft failure.
                'data'    => ['queries' => $this->fallback_queries($brand, $category, $location, $competitors)],
            ], 200);
        }
    }

    /**
     * Start an analysis run.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function start_run(WP_REST_Request $request): WP_REST_Response {
        $settings = Settings::instance();
        $caps     = Plan_Config::ai_visibility();

        $platforms = (array) $settings->get('bv_platforms', ['chatgpt']);
        $providers = new Brand_Visibility_Providers($settings);

        // Drop platforms whose key has since been removed, so a run can't be
        // scheduled to fail on every task of one platform.
        $usable = array_values(array_filter(
            $platforms,
            static fn($p): bool => '' !== $providers->api_key_for((string) $p)
        ));

        // Re-clamp to the plan at RUN time, not just at save time: a site that
        // saved 12 questions across 4 platforms on Pro and then lapsed to free
        // would otherwise keep firing the Pro-sized batch on every run.
        $usable = array_slice($usable, 0, max(1, (int) ($caps['brand_max_platforms'] ?? 1)));

        if (empty($usable)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('None of the selected AI platforms has an API key configured.', 'thinkrank'),
            ], 400);
        }

        $config = [
            'brand'       => (string) $settings->get('bv_brand_name', (string) get_bloginfo('name')),
            'variants'    => (array) $settings->get('bv_variants', []),
            'competitors' => (int) ($caps['brand_competitors'] ?? 0) > 0
                ? (array) $settings->get('bv_competitors', [])
                : [],
            'queries'     => array_slice((array) $settings->get('bv_queries', []), 0, $this->query_limit($caps)),
            'platforms'   => $usable,
            'samples'     => max(1, min(
                max(1, (int) ($caps['brand_max_samples'] ?? 1)),
                (int) $settings->get('bv_samples', 1)
            )),
        ];

        try {
            $runner = new Brand_Visibility_Runner($settings, $providers);
            $run_id = $runner->start($config);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'run_id'      => $run_id,
                'tasks_total' => count(Brand_Visibility_Runner::plan_tasks($config)),
            ],
        ], 201);
    }

    /**
     * How many questions this plan may run, bounded by the hard ceiling.
     *
     * @param array $caps Capability map.
     * @return int
     */
    private function query_limit(array $caps): int {
        $plan_max = (int) ($caps['brand_max_queries'] ?? 2);

        return $plan_max > 0 ? min($plan_max, self::MAX_QUERIES) : self::MAX_QUERIES;
    }

    /**
     * Run progress and (once finished) results.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_run(WP_REST_Request $request): WP_REST_Response {
        $runner = new Brand_Visibility_Runner();
        $run    = $runner->get_run((int) $request['id']);

        if (empty($run)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Run not found.', 'thinkrank'),
            ], 404);
        }

        $total    = max(1, (int) $run['tasks_total']);
        $finished = (int) $run['tasks_done'] + (int) $run['tasks_failed'];

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'id'           => (int) $run['id'],
                'status'       => (string) $run['status'],
                'started_at'   => (string) $run['started_at'],
                'finished_at'  => (string) ($run['finished_at'] ?? ''),
                'tasks_total'  => (int) $run['tasks_total'],
                'tasks_done'   => (int) $run['tasks_done'],
                'tasks_failed' => (int) $run['tasks_failed'],
                'progress'     => (int) round(($finished / $total) * 100),
                'results'      => $run['results'],
                'config'       => $run['config'],
                'error'        => (string) ($run['error'] ?? ''),
            ],
        ], 200);
    }

    /**
     * Run history for the trend chart, clamped to the plan.
     *
     * @return WP_REST_Response
     */
    public function get_runs(): WP_REST_Response {
        $caps   = Plan_Config::ai_visibility();
        $limit  = (int) ($caps['brand_history_runs'] ?? 1);
        $runner = new Brand_Visibility_Runner();

        $runs = $runner->recent_runs($limit > 0 ? $limit : 20);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'runs'    => array_map(static function (array $run): array {
                    return [
                        'id'          => (int) $run['id'],
                        'status'      => (string) $run['status'],
                        'started_at'  => (string) $run['started_at'],
                        'index'       => (int) ($run['results']['visibility_index'] ?? 0),
                        'mention_rate' => (float) ($run['results']['mention_rate'] ?? 0),
                        'competitors' => $run['results']['competitors'] ?? [],
                    ];
                }, $runs),
                'limited' => $limit > 0,
            ],
        ], 200);
    }

    /**
     * Which platforms have a dedicated key stored.
     *
     * @param Settings $settings Settings.
     * @return array<string, bool>
     */
    private function keys_set(Settings $settings): array {
        $out = [];
        foreach (array_keys(Brand_Visibility_Providers::PLATFORMS) as $platform) {
            $out[$platform] = '' !== trim((string) $settings->get(
                Brand_Visibility_Providers::key_option($platform),
                ''
            ));
        }

        return $out;
    }

    /**
     * Masked preview of each platform's dedicated key, for display.
     *
     * Reveals the first 5 and last 3 characters with a bullet run in between
     * (e.g. "sk-pr••••••••ioA"); keys of 8 chars or fewer are fully masked.
     * Empty when the platform has no dedicated key of its own.
     *
     * @param Settings $settings Settings instance.
     * @return array<string, string>
     */
    private function keys_masked(Settings $settings): array {
        $out = [];
        foreach (array_keys(Brand_Visibility_Providers::PLATFORMS) as $platform) {
            $key = trim((string) $settings->get(
                Brand_Visibility_Providers::key_option($platform),
                ''
            ));

            if ('' === $key) {
                $out[$platform] = '';
            } elseif (strlen($key) <= 8) {
                $out[$platform] = '••••••••';
            } else {
                $out[$platform] = substr($key, 0, 5) . '••••••••' . substr($key, -3);
            }
        }

        return $out;
    }

    /**
     * Prompt for question generation.
     *
     * @param string   $brand       Brand.
     * @param string   $category    Category.
     * @param string   $location    Location.
     * @param string   $description Short description.
     * @param string[] $competitors Competitor names.
     * @return string
     */
    private function query_prompt(string $brand, string $category, string $location, string $description, array $competitors): string {
        return sprintf(
            "You are helping measure how often an AI assistant mentions a business in its answers.\n\n"
            . "Business: %s\nCategory: %s\nLocation: %s\nAbout: %s\nCompetitors: %s\n\n"
            . "Write realistic questions a potential customer would ask an AI assistant. Group them exactly like this, one question per line, no numbering:\n\n"
            . "BRANDED:\n(3 questions that name the business directly)\n\n"
            . "CATEGORY:\n(3 questions about the product category and location that do NOT name the business)\n\n"
            . "PROBLEM:\n(3 questions about a problem this business solves, that do NOT name the business)\n\n"
            . "Return nothing except those three sections.",
            $brand,
            '' !== $category ? $category : 'general',
            '' !== $location ? $location : 'global',
            '' !== $description ? $description : 'n/a',
            !empty($competitors) ? implode(', ', $competitors) : 'none'
        );
    }

    /**
     * Parse the model's sectioned output into typed questions.
     *
     * @param string $text Model output.
     * @return array<int, array{text: string, type: string}>
     */
    private function parse_generated_queries(string $text): array {
        $type    = '';
        $queries = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $upper = strtoupper($line);
            foreach (['BRANDED', 'CATEGORY', 'PROBLEM', 'COMPARISON'] as $heading) {
                if (0 === strpos($upper, $heading)) {
                    $type = strtolower($heading);
                    continue 2;
                }
            }

            if ('' === $type) {
                continue;
            }

            // Strip list markers the model adds despite instructions.
            $line = ltrim($line, "-*•0123456789. \t");
            if (mb_strlen($line) < 8) {
                continue;
            }

            $queries[] = ['text' => sanitize_text_field($line), 'type' => $type];
        }

        return $queries;
    }

    /**
     * Deterministic questions used when AI generation is unavailable.
     *
     * @param string   $brand       Brand.
     * @param string   $category    Category.
     * @param string   $location    Location.
     * @param string[] $competitors Competitor names.
     * @return array<int, array{text: string, type: string}>
     */
    private function fallback_queries(string $brand, string $category, string $location, array $competitors): array {
        $subject = '' !== $category ? $category : __('products', 'thinkrank');
        $where   = '' !== $location ? sprintf(' in %s', $location) : '';

        $queries = [
            ['text' => sprintf('What is %s and what does it offer?', $brand), 'type' => 'branded'],
            ['text' => sprintf('Is %s a good choice for %s?', $brand, $subject), 'type' => 'branded'],
            ['text' => sprintf('What are the best %s%s?', $subject, $where), 'type' => 'category'],
            ['text' => sprintf('Where can I buy %s%s?', $subject, $where), 'type' => 'category'],
            ['text' => sprintf('How do I choose the right %s?', $subject), 'type' => 'problem'],
        ];

        foreach ($competitors as $name) {
            $queries[] = ['text' => sprintf('%s vs %s', $brand, $name), 'type' => 'comparison'];
        }

        return $queries;
    }
}
