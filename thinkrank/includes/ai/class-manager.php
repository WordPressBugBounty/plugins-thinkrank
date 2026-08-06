<?php
/**
 * AI Manager Class
 *
 * Handles AI provider integration and management
 *
 * @package ThinkRank\AI
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Settings;


// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Manager Class
 *
 * Single Responsibility: Manage AI providers and requests
 *
 * @since 1.0.0
 */
class Manager {

    /**
     * Settings instance
     *
     * @var Settings
     */
    private Settings $settings;



    /**
     * Cache manager instance
     *
     * @var Cache_Manager
     */
    private Cache_Manager $cache;

    /**
     * Current AI client
     *
     * @var OpenAI_Client|Claude_Client|null
     */
    private $client = null;


    /**
     * Constructor
     *
     * @param Settings|null $settings Settings instance
     */
    public function __construct(?Settings $settings = null) {
        $this->settings = $settings ?? Settings::instance();
        $this->cache = new Cache_Manager((int) $this->settings->get('cache_duration', 3600));
    }

    /**
     * Initialize AI manager
     *
     * @return void
     */
    public function init(): void {
        // Initialize AI client based on settings
        add_action('init', [$this, 'initialize_client']);

        // Schedule cache cleanup
        add_action('thinkrank_daily_cleanup', [$this, 'cleanup_cache']);

        // Add AJAX handlers for AI requests
        add_action('wp_ajax_thinkrank_generate_metadata', [$this, 'ajax_generate_metadata']);
        add_action('wp_ajax_thinkrank_test_api_connection', [$this, 'ajax_test_connection']);
    }

    /**
     * Initialize AI client
     *
     * @return void
     */
    public function initialize_client(): void {
        $provider = $this->settings->get('ai_provider', 'openai');

        try {
            switch ($provider) {
                case 'openai':
                    $api_key = $this->settings->get('openai_api_key');
                    if ($api_key) {
                        // Allow any model id (incl. user-entered custom models);
                        // only fall back to the default when none is set.
                        $model = $this->settings->get('openai_model', 'gpt-5-nano');
                        if (empty($model)) {
                            $model = 'gpt-5-nano';
                        }
                        // OpenAI's reasoning models (GPT-5/o-series) spend a long
                        // time on reasoning tokens before emitting content, so
                        // large completions (content briefs) regularly outlive the
                        // 120s used for the other providers. Give them 300s.
                        $timeout = 300;
                        $this->client = new OpenAI_Client($api_key, $model, $timeout);

                        // OpenAI client created successfully
                    }
                    break;

                case 'claude':
                    $api_key = $this->settings->get('claude_api_key');
                    if ($api_key) {
                        // Allow any model id (incl. user-entered custom models);
                        // only fall back to the default when none is set.
                        $model = $this->settings->get('claude_model', 'claude-sonnet-5');
                        if (empty($model)) {
                            $model = 'claude-sonnet-5';
                        }
                        // Use 120-second timeout for complex AI operations
                        $timeout = 120;
                        $this->client = new Claude_Client($api_key, $model, $timeout);

                        // Claude client created successfully
                    }
                    break;

                case 'gemini':
                    $api_key = $this->settings->get('gemini_api_key');
                    if ($api_key) {
                        // Allow any model id (incl. user-entered custom models);
                        // only fall back to the default when none is set.
                        $model = $this->settings->get('gemini_model', 'gemini-2.5-flash');
                        if (empty($model)) {
                            $model = 'gemini-2.5-flash';
                        }
                        // Use 120-second timeout for complex AI operations
                        $timeout = 120;
                        $this->client = new Gemini_Client($api_key, $model, $timeout);
                    }
                    break;

                case 'openrouter':
                    $api_key = $this->settings->get('openrouter_api_key');
                    if ($api_key) {
                        // Allow any model id (incl. user-entered custom models);
                        // only fall back to the default when none is set.
                        $model = $this->settings->get('openrouter_model', 'openai/gpt-4o-mini');
                        if (empty($model)) {
                            $model = 'openai/gpt-4o-mini';
                        }
                        // Use 120-second timeout for complex AI operations
                        $timeout = 120;
                        $this->client = new OpenRouter_Client($api_key, $model, $timeout);
                    }
                    break;

                default:
                    throw new \Exception("Unsupported AI provider: {$provider}");
            }
        } catch (\Exception $e) {
            // AI client initialization failed, will be handled later
        }
    }

    /**
     * Get the display name of the currently selected AI provider
     *
     * @return string Provider display name (e.g. "OpenAI")
     */
    private function get_provider_label(): string {
        $labels = [
            'openai'     => 'OpenAI',
            'claude'     => 'Claude',
            'gemini'     => 'Gemini',
            'openrouter' => 'OpenRouter',
        ];

        $provider = (string) $this->settings->get('ai_provider', 'openai');

        return $labels[$provider] ?? ucfirst($provider);
    }

    /**
     * Build a user-friendly message explaining why AI features are unavailable
     *
     * Provider-aware: tells the user exactly which API key is missing and where
     * to add it, instead of a generic "client not initialized" error.
     *
     * @return string Actionable error message for end users
     */
    private function get_client_unavailable_message(): string {
        $provider = (string) $this->settings->get('ai_provider', 'openai');

        // The React admin renders this anchor as a real link via linkifyMessage().
        $settings_link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(admin_url('admin.php?page=thinkrank-settings')),
            __('ThinkRank → Settings', 'thinkrank')
        );

        if (empty($this->settings->get("{$provider}_api_key"))) {
            return sprintf(
                /* translators: %s: link to the ThinkRank settings page. */
                __('AI features are not set up yet. To enable them, add your API key under %s.', 'thinkrank'),
                $settings_link
            );
        }

        return sprintf(
            /* translators: 1: AI provider name (e.g. OpenAI), 2: link to the ThinkRank settings page. */
            __('ThinkRank could not connect to %1$s. Please verify your API key and model under %2$s, then try again.', 'thinkrank'),
            $this->get_provider_label(),
            $settings_link
        );
    }

    /**
     * Force re-initialization of client (useful after settings change)
     *
     * @return void
     */
    public function reinitialize_client(): void {
        $this->client = null;
        $this->initialize_client();
    }

    /**
     * Get the AI client instance
     *
     * @return OpenAI_Client|Claude_Client|null AI client instance
     * @throws \Exception If client cannot be initialized
     */
    public function get_client() {
        // Initialize client if not already done
        if (!$this->client) {
            $this->initialize_client();
        }

        // If still not available, throw error
        if (!$this->client) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        return $this->client;
    }

    /**
     * Generate SEO metadata for content
     *
     * @param string $content Content to analyze
     * @param array $options Generation options
     * @return array Generated metadata
     * @throws \Exception If generation fails
     */
    public function generate_seo_metadata(string $content, array $options = []): array {
        // Check if client is available, try to initialize if not
        if (!$this->client) {
            $this->initialize_client();

            // If still not available, throw error
            if (!$this->client) {
                throw new \Exception($this->get_client_unavailable_message());
            }
        }

        // Check rate limits
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }

        // Get current user for logging
        $user_id = get_current_user_id();

        // Generate cache key
        $cache_key = $this->cache->generate_content_key($content, $options);

        // Check cache first
        $cached_result = $this->cache->get($cache_key);
        if ($cached_result !== null) {
            return $cached_result['data'];
        }

        try {
            // Generate metadata using AI
            $metadata = $this->client->generate_seo_metadata($content, $options);

            // Ensure user has configured their API key
            $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));

            if (!$user_has_api_key) {
                throw new \Exception($this->get_client_unavailable_message());
            }

            // Cache the result
            $this->cache->set($cache_key, $metadata);

            // Log usage with actual model information and raw AI text (Content Brief pattern)
            $actual_model = $this->client ? $this->client->get_model() : null;
            $ai_text = $metadata['_ai_text'] ?? null;
            $this->log_ai_usage($user_id, 'SEO Metadata', $metadata['tokens_used'] ?? 0, $actual_model, $ai_text);

            // Remove AI text from returned data to keep it clean
            unset($metadata['_ai_text']);

            return $metadata;

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Generate an improved SEO title that addresses a specific suggestion.
     *
     * Used by the "Apply" action on title-related SEO score suggestions. Builds a
     * focused, best-practice title prompt and runs it through the configured
     * provider, reusing the same completion/extraction path as the content brief
     * generator so OpenAI, Claude and Gemini all parse consistently.
     *
     * @since 1.14.0
     *
     * @param string $content Post content for context.
     * @param array  $options {
     *     @type string $current_title  Current SEO title.
     *     @type string $target_keyword Focus keyword.
     *     @type string $content_type   Content type (blog_post, page, …).
     *     @type string $tone           Desired tone.
     *     @type string $suggestion     The suggestion the title must address.
     * }
     * @return array{title:string} The improved SEO title.
     * @throws \Exception If the AI client is unavailable or returns no title.
     */
    public function improve_seo_title(string $content, array $options = []): array {
        if (!$this->client) {
            $this->initialize_client();
            if (!$this->client) {
                throw new \Exception($this->get_client_unavailable_message());
            }
        }

        // Ensure user has configured their API key.
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));
        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Check rate limits.
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }

        $current_title  = (string) ($options['current_title'] ?? '');
        $target_keyword = (string) ($options['target_keyword'] ?? '');
        $content_type   = (string) ($options['content_type'] ?? 'blog_post');
        $tone           = (string) ($options['tone'] ?? 'professional');
        $suggestion     = (string) ($options['suggestion'] ?? '');
        $language       = (string) ($options['language'] ?? '');

        // Cache identical requests (same content + inputs) to avoid duplicate calls.
        // Cap content server-side (mirror the frontend 5000-char trim) so a
        // direct REST caller can't force oversized prompt/cache/AI work.
        $content = mb_substr($content, 0, 5000);

        $cache_key = 'improve_title_' . md5($content . '|' . $current_title . '|' . $target_keyword . '|' . $content_type . '|' . $tone . '|' . $suggestion);
        $cached_result = $this->cache->get($cache_key);
        if ($cached_result !== null) {
            return $cached_result['data'] ?? $cached_result;
        }

        $user_id = get_current_user_id();
        $provider = method_exists($this->client, 'get_provider') ? $this->client->get_provider() : 'openai';

        // Use ThinkRank's own validation word lists so the generated title passes
        // the same emotion/sentiment and power-word checks the scorer applies.
        $sentiment_words = SEOScoreCalculator::get_title_sentiment_words();
        $power_words = SEOScoreCalculator::get_title_power_words();

        // When the suggestion explicitly asks for an emotional/sentiment word we
        // strictly validate the result (and retry once) to guarantee it passes.
        $needs_sentiment = stripos($suggestion, 'sentiment') !== false || stripos($suggestion, 'emotional') !== false;

        $prompt = (new Prompt_Builder())->build_title_improvement_prompt(
            $content,
            $current_title,
            $target_keyword,
            $content_type,
            $tone,
            $suggestion,
            $provider,
            $sentiment_words,
            $power_words,
            $language
        );

        $generated = $this->request_title($prompt);
        $title = $generated['title'];
        $total_tokens = $generated['tokens'];
        $ai_text = $generated['ai_text'];

        // A reasoning model can still return an empty/truncated title on the
        // first pass; retry once before giving up so the "Apply" action reliably
        // produces a title.
        if ($title === '') {
            $retry = $this->request_title($prompt);
            $total_tokens += $retry['tokens'];
            if ($retry['ai_text'] !== '') {
                $ai_text = $retry['ai_text'];
            }
            if ($retry['title'] !== '') {
                $title = $retry['title'];
            }
        }

        // Guarantee the emotion/sentiment check passes: if it was required but the
        // title still lacks a listed word, retry once with a non-negotiable
        // instruction. If the retry also fails we keep the best title we have.
        if ($needs_sentiment && !$this->title_contains_word($title, $sentiment_words)) {
            $retry_prompt = $prompt . "\n\nIMPORTANT: Your previous attempt was rejected because the title did not contain a required word. The new title MUST include at least one of these exact words verbatim: " . implode(', ', $sentiment_words) . '.';
            $retry = $this->request_title($retry_prompt);
            $total_tokens += $retry['tokens'];
            if ($retry['ai_text'] !== '') {
                $ai_text = $retry['ai_text'];
            }
            if ($retry['title'] !== '' && $this->title_contains_word($retry['title'], $sentiment_words)) {
                $title = $retry['title'];
            }
        }

        if ($title === '') {
            throw new \Exception('The AI did not return a usable title. Please try again.');
        }

        // Log usage.
        $actual_model = $this->client ? $this->client->get_model() : null;
        $this->log_ai_usage($user_id, 'SEO Title Improvement', (int) $total_tokens, $actual_model, $ai_text);

        $result = ['title' => $title];
        $this->cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Run a single title-generation request: call the provider, extract the
     * title text across provider response shapes, and clamp it to 60 characters.
     *
     * @param string $prompt The prompt to send.
     * @return array{title:string,ai_text:string,tokens:int}
     */
    private function request_title(string $prompt): array {
        // Larger budget so reasoning models (e.g. gpt-5-nano) don't spend the
        // whole allowance "thinking" and truncate the JSON before the title.
        $completion = $this->request_completion($prompt, 4096);
        $title = $this->extract_json_field($completion['ai_text'], 'title');

        // Safety net: enforce the 60-character maximum even if the model overruns.
        if (mb_strlen($title) > 60) {
            $title = rtrim(mb_substr($title, 0, 60));
        }

        return [
            'title' => $title,
            'ai_text' => $completion['ai_text'],
            'tokens' => $completion['tokens'],
        ];
    }

    /**
     * Generate an improved meta description that addresses a specific suggestion.
     *
     * Guarantees ThinkRank's technical check passes (120-160 characters) and,
     * when the suggestion is about the focus keyword, that the keyword is present
     * — retrying once if the first attempt falls outside the constraints.
     *
     * @since 1.14.0
     *
     * @param string $content Post content for context.
     * @param array  $options {
     *     @type string $current_description Current meta description.
     *     @type string $target_keyword     Focus keyword.
     *     @type string $content_type        Content type.
     *     @type string $tone                Desired tone.
     *     @type string $suggestion          The suggestion to address.
     * }
     * @return array{description:string} The improved meta description.
     * @throws \Exception If the AI client is unavailable or returns nothing usable.
     */
    public function improve_meta_description(string $content, array $options = []): array {
        if (!$this->client) {
            $this->initialize_client();
            if (!$this->client) {
                throw new \Exception($this->get_client_unavailable_message());
            }
        }

        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));
        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }

        $current_desc   = (string) ($options['current_description'] ?? '');
        $target_keyword = (string) ($options['target_keyword'] ?? '');
        $content_type   = (string) ($options['content_type'] ?? 'blog_post');
        $tone           = (string) ($options['tone'] ?? 'professional');
        $suggestion     = (string) ($options['suggestion'] ?? '');
        $language       = (string) ($options['language'] ?? '');

        // Cap content server-side (mirror the frontend 5000-char trim) so a
        // direct REST caller can't force oversized prompt/cache/AI work.
        $content = mb_substr($content, 0, 5000);

        $cache_key = 'improve_meta_' . md5($content . '|' . $current_desc . '|' . $target_keyword . '|' . $content_type . '|' . $tone . '|' . $suggestion);
        $cached_result = $this->cache->get($cache_key);
        if ($cached_result !== null) {
            return $cached_result['data'] ?? $cached_result;
        }

        $user_id = get_current_user_id();
        $provider = method_exists($this->client, 'get_provider') ? $this->client->get_provider() : 'openai';

        // The keyword must appear when the suggestion is keyword-specific, or
        // whenever a focus keyword exists (the scorer rewards it either way).
        $needs_keyword = $target_keyword !== '';

        $build_prompt = fn() => (new Prompt_Builder())->build_meta_description_improvement_prompt(
            $content,
            $current_desc,
            $target_keyword,
            $content_type,
            $tone,
            $suggestion,
            $provider,
            $language
        );

        $valid = function (string $desc) use ($needs_keyword, $target_keyword): bool {
            $len = mb_strlen($desc);
            if ($len < 120 || $len > 160) {
                return false;
            }
            if ($needs_keyword && strpos(strtolower($desc), strtolower($target_keyword)) === false) {
                return false;
            }
            return true;
        };

        $prompt = $build_prompt();
        // Larger budget so reasoning models don't truncate the JSON before the
        // description (which surfaced as "could not produce a 120-160 character
        // meta description" on gpt-5-nano).
        $completion = $this->request_completion($prompt, 4096);
        $description = $this->extract_json_field($completion['ai_text'], 'description');
        $total_tokens = $completion['tokens'];
        $ai_text = $completion['ai_text'];

        // Retry once with explicit, measurable constraints if the first attempt
        // misses the mandatory length window or the required keyword.
        if (!$valid($description)) {
            $extra = "\n\nIMPORTANT: Your previous attempt did not meet the requirements. The description MUST be between 120 and 160 characters";
            if ($needs_keyword) {
                $extra .= " and MUST contain the exact phrase \"{$target_keyword}\"";
            }
            $extra .= '. Count the characters before answering.';
            $retry = $this->request_completion($prompt . $extra, 4096);
            $retry_desc = $this->extract_json_field($retry['ai_text'], 'description');
            $total_tokens += $retry['tokens'];
            if ($retry['ai_text'] !== '') {
                $ai_text = $retry['ai_text'];
            }
            // Prefer a valid candidate; otherwise keep the longer non-empty one so
            // the clamp below can bring an over-long description into range.
            if ($valid($retry_desc)) {
                $description = $retry_desc;
            } elseif ($description === '') {
                $description = $retry_desc;
            } elseif (!$valid($description) && mb_strlen($retry_desc) > mb_strlen($description)) {
                $description = $retry_desc;
            }
        }

        // Hard safety net: guarantee the 160-character ceiling by trimming at a
        // word boundary, so the scorer's 120-160 technical check passes even if a
        // "thinking" model overran the limit.
        $description = $this->clamp_meta_description($description);

        if ($description === '' || mb_strlen($description) < 120) {
            throw new \Exception('The AI could not produce a 120-160 character meta description. Please try again.');
        }

        $actual_model = $this->client ? $this->client->get_model() : null;
        $this->log_ai_usage($user_id, 'SEO Meta Description', (int) $total_tokens, $actual_model, $ai_text);

        $result = ['description' => $description];
        $this->cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Explain a single SEO score suggestion in plain, post-specific language.
     *
     * Powers the "Explain with AI" copilot action on each suggestion. Unlike the
     * improve_* methods this does not modify content — it returns a short,
     * context-aware explanation of why the suggestion matters for this post and
     * how to resolve it, so the author understands the fix before applying it.
     *
     * @since 1.18.0
     *
     * @param string $content Post content for context.
     * @param array  $options {
     *     @type string $suggestion     The suggestion to explain (required).
     *     @type string $title          Post/SEO title for context.
     *     @type string $target_keyword Focus keyword.
     *     @type string $content_type   Content type (blog_post, page, …).
     * }
     * @return array{explanation:string} The plain-language explanation.
     * @throws \Exception If the AI client is unavailable or returns nothing usable.
     */
    public function explain_seo_suggestion(string $content, array $options = []): array {
        $this->ensure_ready_for_ai();

        // Cap content server-side so a direct REST caller cannot bypass the
        // frontend's 5000-character trim and force oversized prompt building,
        // cache hashing, and expensive AI calls/retries.
        $content = mb_substr($content, 0, 5000);

        $suggestion = trim((string) ($options['suggestion'] ?? ''));
        if ($suggestion === '') {
            throw new \Exception('A suggestion is required to generate an explanation.');
        }
        $title          = (string) ($options['title'] ?? '');
        $target_keyword = (string) ($options['target_keyword'] ?? '');
        $content_type   = (string) ($options['content_type'] ?? 'blog_post');

        $cache_key = 'explain_' . md5($suggestion . '|' . $content . '|' . $title . '|' . $target_keyword . '|' . $content_type);
        $cached = $this->cache->get($cache_key);
        if ($cached !== null) {
            return $cached['data'] ?? $cached;
        }

        $provider = method_exists($this->client, 'get_provider') ? $this->client->get_provider() : 'openai';
        $prompt = (new Prompt_Builder())->build_suggestion_explanation_prompt($suggestion, $content, $title, $target_keyword, $content_type, $provider);

        // Give reasoning models (e.g. gpt-5-nano) enough headroom that they don't
        // burn the whole budget "thinking" and truncate the JSON before the
        // closing brace, and retry once if the first attempt yields nothing
        // parseable — mirrors the resilience of the keyword-paragraph path.
        $explanation = '';
        $tokens_used = 0;
        $ai_text = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = $this->request_completion($prompt, 4096);
            $tokens_used += (int) $completion['tokens'];
            $ai_text = $completion['ai_text'];
            $candidate = $this->extract_json_field($completion['ai_text'], 'explanation');
            if ($candidate !== '') {
                $explanation = $candidate;
                break;
            }
        }

        if ($explanation === '') {
            throw new \Exception('The AI did not return an explanation. Please try again.');
        }

        $actual_model = $this->client ? $this->client->get_model() : null;
        $this->log_ai_usage(get_current_user_id(), 'SEO Suggestion Explanation', (int) $tokens_used, $actual_model, $ai_text);

        $result = ['explanation' => $explanation];
        $this->cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Generate a targeted content fragment that adds one authoritative external
     * dofollow link, so the scorer's external-dofollow-link check passes.
     *
     * @since 1.14.0
     *
     * @param string $content Post content for context.
     * @param array  $options { @type string $target_keyword; @type string $content_type; }
     * @return array{html:string,url:string,anchor:string} HTML paragraph to append.
     * @throws \Exception If the AI client is unavailable or returns no valid link.
     */
    public function generate_dofollow_link(string $content, array $options = []): array {
        $this->ensure_ready_for_ai();

        $target_keyword = (string) ($options['target_keyword'] ?? '');
        $content_type   = (string) ($options['content_type'] ?? 'blog_post');

        // Cap content server-side (mirror the frontend 5000-char trim) so a
        // direct REST caller can't force oversized prompt/cache/AI work.
        $content = mb_substr($content, 0, 5000);

        $cache_key = 'dofollow_' . md5($content . '|' . $target_keyword . '|' . $content_type);
        $cached = $this->cache->get($cache_key);
        if ($cached !== null) {
            return $cached['data'] ?? $cached;
        }

        $provider = method_exists($this->client, 'get_provider') ? $this->client->get_provider() : 'openai';
        $prompt = (new Prompt_Builder())->build_dofollow_link_prompt($content, $target_keyword, $content_type, $provider);

        // Larger budget + one retry: reasoning models can truncate the JSON and
        // yield no URL, which surfaced as "did not return a valid external
        // source" on the first attempt.
        $data = [];
        $tokens_used = 0;
        $ai_text = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = $this->request_completion($prompt, 4096);
            $tokens_used += (int) $completion['tokens'];
            $ai_text = $completion['ai_text'];
            $candidate = $this->extract_json_object($completion['ai_text']);
            if (is_array($candidate) && !empty($candidate['url'])) {
                $data = $candidate;
                break;
            }
        }

        $url = isset($data['url']) ? esc_url_raw(trim((string) $data['url'])) : '';
        $anchor = isset($data['anchor']) ? sanitize_text_field((string) $data['anchor']) : '';
        $sentence = isset($data['sentence']) ? sanitize_text_field((string) $data['sentence']) : '';

        // Validate: must be a real external http(s) URL pointing off-site.
        $site_host = wp_parse_url(get_site_url(), PHP_URL_HOST);
        $link_host = $url !== '' ? wp_parse_url($url, PHP_URL_HOST) : '';
        $is_external = $url !== '' && preg_match('#^https?://#i', $url) && $link_host && strcasecmp($link_host, (string) $site_host) !== 0;
        if (!$is_external) {
            throw new \Exception('The AI did not return a valid external source. Please try again.');
        }
        if ($anchor === '') {
            $anchor = $link_host;
        }
        if ($sentence === '') {
            $sentence = sprintf('For more on this topic, see %s.', $anchor);
        }

        // Build a dofollow anchor (no rel=nofollow) and weave it into the
        // sentence by linking the anchor text; append it if the anchor phrase is
        // not present.
        $link = sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($anchor));
        if (stripos($sentence, $anchor) !== false) {
            $linked = preg_replace('/' . preg_quote($anchor, '/') . '/i', $link, $sentence, 1);
        } else {
            $linked = rtrim($sentence, '.') . ' (' . $link . ').';
        }
        $html = '<p>' . $linked . '</p>';

        $actual_model = $this->client ? $this->client->get_model() : null;
        $this->log_ai_usage(get_current_user_id(), 'SEO Dofollow Link', (int) $tokens_used, $actual_model, $ai_text);

        $result = ['html' => $html, 'url' => $url, 'anchor' => $anchor];
        $this->cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Generate a short, relevant closing paragraph that uses the focus keyword
     * enough times to lift keyword density into the scorer's healthy band
     * (0.5%-2.5%), returned as an HTML paragraph to append to the content.
     *
     * @since 1.14.0
     *
     * @param string $content Post content for context.
     * @param array  $options {
     *     @type string $target_keyword;
     *     @type string $content_type;
     *     @type string $tone;
     *     @type int    $word_count    Current document word count.
     *     @type int    $keyword_count Current focus-keyword occurrences.
     * }
     * @return array{html:string,mentions:int} HTML paragraph to append.
     * @throws \Exception If the AI client is unavailable or returns nothing usable.
     */
    public function generate_keyword_paragraph(string $content, array $options = []): array {
        $this->ensure_ready_for_ai();

        $target_keyword = trim((string) ($options['target_keyword'] ?? ''));
        if ($target_keyword === '') {
            throw new \Exception('A focus keyword is required to improve keyword density.');
        }
        $content_type  = (string) ($options['content_type'] ?? 'blog_post');
        $tone          = (string) ($options['tone'] ?? 'professional');
        $word_count    = max(0, (int) ($options['word_count'] ?? 0));
        $keyword_count = max(0, (int) ($options['keyword_count'] ?? 0));

        // Size the closing section to land just above the 0.5% floor. Solving
        // (kw + m) / (words + W) >= target for a section that uses ~14 words per
        // keyword mention (W = 14m) keeps the writing readable rather than
        // stuffed. Cap mentions so a very long, sparse article doesn't demand an
        // absurd block — in that case one pass improves density without fully
        // resolving it, which the caller surfaces honestly.
        // Target a bit above the 0.5% floor and assume a tight ~11 words per
        // mention when sizing the request, because models tend to under-deliver
        // mentions and over-write length — both of which dilute density. The cap
        // keeps very long, sparse posts from demanding an absurd block; those may
        // still need a second pass, which the caller surfaces honestly.
        $target_density = 0.0065;
        $words_per_mention = 11;
        $denom_factor = 1 - ($target_density * $words_per_mention); // ~0.928
        $needed = $denom_factor > 0
            ? ($target_density * $word_count - $keyword_count) / $denom_factor
            : 4;
        $mentions = (int) max(3, min(24, ceil($needed)));
        $para_words = max(90, $mentions * $words_per_mention);

        // Cap content server-side (mirror the frontend 5000-char trim) so a
        // direct REST caller can't force oversized prompt/cache/AI work.
        $content = mb_substr($content, 0, 5000);

        $cache_key = 'kw_para_' . md5($content . '|' . $target_keyword . '|' . $content_type . '|' . $tone . '|' . $mentions . '|' . $para_words);
        $cached = $this->cache->get($cache_key);
        if ($cached !== null) {
            return $cached['data'] ?? $cached;
        }

        $provider = method_exists($this->client, 'get_provider') ? $this->client->get_provider() : 'openai';
        $prompt = (new Prompt_Builder())->build_keyword_paragraph_prompt($content, $target_keyword, $content_type, $tone, $mentions, $provider, $para_words);

        // Bigger token budget: the section is long and thinking models burn
        // output tokens reasoning before writing the JSON. Generation can be
        // truncated intermittently, yielding a stub — validate and retry once so
        // we never apply (or cache) a degenerate paragraph.
        $paragraph = '';
        $tokens_used = 0;
        $ai_text = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = $this->request_completion($prompt, 4096);
            $tokens_used += (int) $completion['tokens'];
            $ai_text = $completion['ai_text'];
            $candidate = $this->extract_json_field($completion['ai_text'], 'paragraph');
            if (str_word_count(wp_strip_all_tags($candidate)) >= 40) {
                $paragraph = $candidate;
                break;
            }
        }
        if ($paragraph === '') {
            throw new \Exception('The AI did not return a usable paragraph. Please try again.');
        }

        // wp_kses keeps it to safe inline markup; wrap as a paragraph block.
        $paragraph = wp_kses($paragraph, ['a' => ['href' => [], 'title' => []], 'strong' => [], 'em' => []]);
        $html = '<p>' . $paragraph . '</p>';

        // Report the density this addition achieves so the UI can tell the user
        // whether the check is now satisfied or needs another pass.
        $added_words = str_word_count(wp_strip_all_tags($paragraph));
        $added_mentions = substr_count(strtolower(wp_strip_all_tags($paragraph)), strtolower($target_keyword));
        $new_density = ($word_count + $added_words) > 0
            ? (($keyword_count + $added_mentions) / ($word_count + $added_words)) * 100
            : 0.0;
        $resolves = $new_density >= 0.5 && $new_density <= 2.5;

        $actual_model = $this->client ? $this->client->get_model() : null;
        $this->log_ai_usage(get_current_user_id(), 'SEO Keyword Paragraph', $tokens_used, $actual_model, $ai_text);

        $result = [
            'html' => $html,
            'mentions' => $added_mentions,
            'new_density' => round($new_density, 2),
            'resolves' => $resolves,
        ];
        $this->cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Shared guard for the lightweight AI helpers: ensure a client is available,
     * the user has an API key, and the per-minute rate limit is not exceeded.
     *
     * @throws \Exception When any precondition fails.
     */
    private function ensure_ready_for_ai(): void {
        if (!$this->client) {
            $this->initialize_client();
            if (!$this->client) {
                throw new \Exception($this->get_client_unavailable_message());
            }
        }
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));
        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }
    }

    /**
     * Decode the first JSON object found in an AI response.
     *
     * @param string $ai_text Raw AI text.
     * @return array|null Decoded object, or null if none parses.
     */
    private function extract_json_object(string $ai_text): ?array {
        $json_start = strpos($ai_text, '{');
        $json_end = strrpos($ai_text, '}');
        if ($json_start === false || $json_end === false || $json_end <= $json_start) {
            return null;
        }
        $decoded = json_decode(substr($ai_text, $json_start, $json_end - $json_start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Send one prompt to the configured provider and return the raw text plus
     * token usage, normalising across provider response shapes (mirrors the
     * content brief generator's multi-provider handling).
     *
     * @param string $prompt The prompt to send.
     * @return array{ai_text:string,tokens:int}
     */
    private function request_completion(string $prompt, int $max_tokens = 2048): array {
        // "Thinking" providers (e.g. Gemini 2.5) spend output tokens on reasoning
        // before emitting text, so the cap must cover both the reasoning and the
        // visible JSON. Longer outputs (paragraphs) need a bigger budget. It's
        // only a ceiling — short replies cost no more.
        $response = $this->client->generate_completion($prompt, [
            'max_tokens' => $max_tokens,
            'temperature' => 0.4,
        ]);

        $ai_text = '';
        if (isset($response['choices'][0]['message']['content'])) {
            $ai_text = is_array($response['choices'][0]['message']['content'])
                ? implode(' ', array_map(static fn($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part, $response['choices'][0]['message']['content']))
                : (string) $response['choices'][0]['message']['content'];
        } elseif (isset($response['content'][0]['text'])) {
            $ai_text = (string) $response['content'][0]['text'];
        } elseif (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $ai_text = (string) $response['candidates'][0]['content']['parts'][0]['text'];
        } elseif (isset($response['content']) && is_string($response['content'])) {
            $ai_text = $response['content'];
        }

        $tokens = $response['usage']['total_tokens']
            ?? $response['usage']['output_tokens']
            ?? ($response['usageMetadata']['totalTokenCount'] ?? 0);

        return ['ai_text' => $ai_text, 'tokens' => (int) $tokens];
    }

    /**
     * Trim a meta description to at most 160 characters at a word boundary,
     * preserving sentence-ish endings and avoiding broken words. Descriptions of
     * 160 characters or fewer are returned unchanged.
     *
     * @param string $desc Meta description.
     * @return string Description clamped to <= 160 characters.
     */
    private function clamp_meta_description(string $desc): string {
        $desc = trim($desc);
        if (mb_strlen($desc) <= 160) {
            return $desc;
        }

        $cut = mb_substr($desc, 0, 160);
        $last_space = mb_strrpos($cut, ' ');
        // Only back off to the last space when doing so keeps us at/above 120.
        if ($last_space !== false && $last_space >= 120) {
            $cut = mb_substr($cut, 0, $last_space);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:-");
    }

    /**
     * Whether a title contains any of the given words, using the same
     * case-insensitive substring match the scorer's title checks use.
     *
     * @param string   $title Title to test.
     * @param string[] $words Words to look for.
     * @return bool
     */
    private function title_contains_word(string $title, array $words): bool {
        $title_lower = strtolower($title);
        foreach ($words as $word) {
            if ($word !== '' && strpos($title_lower, strtolower($word)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pull a named string field out of an AI response, tolerating both JSON and
     * plain-text replies.
     *
     * @param string $ai_text Raw AI text.
     * @param string $field   JSON field to read (e.g. 'title', 'description').
     * @return string Sanitized value (without surrounding quotes), or '' on failure.
     */
    private function extract_json_field(string $ai_text, string $field): string {
        $ai_text = trim($ai_text);
        if ($ai_text === '') {
            return '';
        }

        // Prefer a JSON object with the requested field.
        $json_start = strpos($ai_text, '{');
        $json_end = strrpos($ai_text, '}');
        if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
            $decoded = json_decode(substr($ai_text, $json_start, $json_end - $json_start + 1), true);
            if (is_array($decoded) && !empty($decoded[$field])) {
                return sanitize_text_field(trim((string) $decoded[$field], " \t\n\r\0\x0B\"'"));
            }
        }

        // Fall back to the first non-empty line, stripping wrapping quotes.
        $first_line = strtok($ai_text, "\n");
        return sanitize_text_field(trim((string) $first_line, " \t\n\r\0\x0B\"'"));
    }

    /**
     * Analyze content for SEO optimization
     *
     * @param string $content Content to analyze
     * @param array $metadata Existing metadata
     * @return array Analysis results
     * @throws \Exception If analysis fails
     */
    public function analyze_content(string $content, array $metadata = []): array {
        if (!$this->client) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        $user_id = get_current_user_id();

        // Ensure user has configured their API key
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));

        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Check rate limits
        if (!$this->check_rate_limit($user_id, 'content_analysis')) {
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }

        // Check cache first
        $cache_key = 'content_analysis_' . md5($content . serialize($metadata));
        $cached_result = $this->cache->get($cache_key);
        if ($cached_result) {
            return $cached_result['data'] ?? $cached_result;
        }

        try {
            // Analyze content using AI
            $analysis = $this->client->analyze_content($content, $metadata);

            // Cache the result
            $this->cache->set($cache_key, $analysis);

            // Log usage with actual model information and raw AI text (Content Brief pattern)
            $actual_model = $this->client ? $this->client->get_model() : null;
            $ai_text = $analysis['_ai_text'] ?? null;
            $this->log_ai_usage($user_id, 'Content Analysis', $analysis['tokens_used'] ?? 0, $actual_model, $ai_text);

            // Remove AI text from returned data to keep it clean
            unset($analysis['_ai_text']);

            return $analysis;

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Test API connection
     *
     * @return array Test result
     */
    public function test_api_connection(): array {
        if (!$this->client) {
            return [
                'success' => false,
                'message' => $this->get_client_unavailable_message(),
            ];
        }

        try {
            $success = $this->client->test_connection();

            return [
                'success' => $success,
                'message' => $success
                    ? 'API connection successful!'
                    : 'API connection failed. Please check your API key.',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Optimize site identity using AI
     *
     * @since 1.0.0
     *
     * @param array $site_data Site data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_site_identity(array $site_data, array $options = []): array {
        // Validate input
        if (empty($site_data)) {
            throw new \Exception('Site data cannot be empty');
        }

        $user_id = get_current_user_id();

        // Ensure user has configured their API key
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));

        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Generate cache key using existing pattern
        $cache_key = 'site_identity_' . md5(serialize($site_data) . serialize($options)) . '_' . $user_id;

        // Check existing cache infrastructure
        // Cache_Manager::set() wraps entries as ['data' => …], so unwrap
        // before inspecting — checking optimized_data on the wrapped array
        // never matches and the cache would never hit.
        $cached_result = $this->cache->get($cache_key);
        $cached_result = $cached_result['data'] ?? $cached_result;
        if (!empty($cached_result['optimized_data'])) {
            return $cached_result;
        }

        // Check rate limiting
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded for AI optimization requests.');
        }

        // Get AI client
        $client = $this->get_client();

        if (!$client) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Perform AI optimization
        $optimization_results = $client->optimize_site_identity($site_data, $options);

        // Validate that we got meaningful results
        if (empty($optimization_results) || empty($optimization_results['optimized_data'])) {
            throw new \Exception('AI optimization returned empty results. Please try again.');
        }

        // Add metadata
        $optimization_results['ai_model'] = $client->get_model();
        $optimization_results['provider'] = $this->settings->get('ai_provider', 'openai');
        $optimization_results['generated_at'] = gmdate('Y-m-d H:i:s');
        $optimization_results['user_id'] = $user_id;

        // Cache the results (24 hours)
        $this->cache->set($cache_key, $optimization_results, 86400);

        // Record usage with actual model information and raw AI text (Content Brief pattern)
        $actual_model = $optimization_results['ai_model'] ?? ($client ? $client->get_model() : null);
        $ai_text = $optimization_results['_ai_text'] ?? null;
        $this->log_ai_usage($user_id, 'Site Identity Optimization', $optimization_results['tokens_used'] ?? 0, $actual_model, $ai_text);

        // Remove AI text from returned data to keep it clean
        unset($optimization_results['_ai_text']);

        return $optimization_results;
    }

    /**
     * Optimize LLMs.txt content using AI
     *
     * @since 1.0.0
     *
     * @param array $website_data Website data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_llms_txt(array $website_data, array $options = []): array {
        // Validate input
        if (empty($website_data)) {
            throw new \Exception('Website data cannot be empty');
        }

        $user_id = get_current_user_id();

        // Ensure user has configured their API key
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));

        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Generate cache key
        $cache_key = 'llms_txt_' . md5(serialize($website_data) . serialize($options)) . '_' . $user_id;

        // Check cache first
        // Cache_Manager::set() wraps entries as ['data' => …], so unwrap
        // before inspecting — checking optimized_data on the wrapped array
        // never matches and the cache would never hit.
        $cached_result = $this->cache->get($cache_key);
        $cached_result = $cached_result['data'] ?? $cached_result;
        if (!empty($cached_result['optimized_data'])) {
            return $cached_result;
        }

        // Check rate limiting
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded for AI optimization requests.');
        }

        // Get AI client
        $client = $this->get_client();

        if (!$client) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Perform AI optimization
        $optimization_results = $client->optimize_llms_txt($website_data, $options);

        // Validate that we got meaningful results
        if (empty($optimization_results) || empty($optimization_results['optimized_data'])) {
            throw new \Exception('AI optimization returned empty results. Please try again.');
        }

        // Add metadata
        $optimization_results['ai_model'] = $client->get_model();
        $optimization_results['provider'] = $this->settings->get('ai_provider', 'openai');
        $optimization_results['generated_at'] = gmdate('Y-m-d H:i:s');
        $optimization_results['user_id'] = $user_id;

        // Cache the results (24 hours)
        $this->cache->set($cache_key, $optimization_results, 86400);

        // Record usage with actual model information and raw AI text (Content Brief pattern)
        $actual_model = $optimization_results['ai_model'] ?? ($client ? $client->get_model() : null);
        $ai_text = $optimization_results['_ai_text'] ?? null;
        $this->log_ai_usage($user_id, 'LLMs.txt Optimization', $optimization_results['tokens_used'] ?? 0, $actual_model, $ai_text);

        // Remove AI text from returned data to keep it clean
        unset($optimization_results['_ai_text']);

        return $optimization_results;
    }

    /**
     * Get available AI providers
     *
     * @return array Available providers
     */
    public function get_available_providers(): array {
        return [
            'openai' => [
                'name' => 'OpenAI',
                'description' => 'GPT‑5 series and GPT‑4o',
                'models' => ['gpt-5-nano', 'gpt-5-mini', 'gpt-5', 'gpt-4o'],
                'requires_key' => true,
            ],
            'claude' => [
                'name' => 'Claude (Anthropic)',
                'description' => 'Claude Opus 4.8, Sonnet 5, and Haiku 4.5',
                'models' => ['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5'],
                'requires_key' => true,
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'description' => 'Gemini 3.x and 2.x models',
                'models' => ['gemini-3.1-pro', 'gemini-3.5-flash', 'gemini-3.1-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-flash'],
                'requires_key' => true,
            ],
            'openrouter' => [
                'name' => 'OpenRouter',
                'description' => 'Unified access to many models via one key',
                'models' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'google/gemini-2.0-flash-001', 'meta-llama/llama-3.3-70b-instruct', 'deepseek/deepseek-chat'],
                'requires_key' => true,
            ],
        ];
    }

    /**
     * Get current provider status
     *
     * @return array Provider status
     */
    public function get_provider_status(): array {
        $provider = $this->settings->get('ai_provider', 'openai');
        $api_key = $this->settings->get($provider . '_api_key');

        return [
            'provider' => $provider,
            'configured' => !empty($api_key),
            'connected' => $this->client !== null,
        ];
    }

    /**
     * AJAX handler for generating metadata
     *
     * @return void
     */
    public function ajax_generate_metadata(): void {
        // Verify nonce
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_ai_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }

        $content = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        $options = [
            'target_keyword' => sanitize_text_field(wp_unslash($_POST['target_keyword'] ?? '')),
            'content_type' => sanitize_text_field(wp_unslash($_POST['content_type'] ?? 'blog_post')),
            'tone' => sanitize_text_field(wp_unslash($_POST['tone'] ?? 'professional')),
        ];

        try {
            $metadata = $this->generate_seo_metadata($content, $options);

            wp_send_json_success([
                'metadata' => $metadata,
                'message' => __('SEO metadata generated successfully!', 'thinkrank'),
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for testing API connection
     *
     * @return void
     */
    public function ajax_test_connection(): void {
        // Verify nonce
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_ai_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $result = $this->test_api_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Check rate limits.
     *
     * Backed by a per-minute transient counter so the limit is enforced across
     * requests. A fresh Manager is constructed on every AJAX/REST call, so the
     * previous in-memory array always started empty and never limited anything —
     * letting an edit_posts user loop the metadata AJAX and drive unbounded paid
     * AI-provider spend.
     *
     * @param int|null $user_id Optional user id (defaults to the current user).
     * @param string   $context Rate-limit bucket (keeps distinct flows separate).
     * @return bool True if within limits.
     */
    private function check_rate_limit(?int $user_id = null, string $context = 'ai'): bool {
        $user_id = $user_id ?? get_current_user_id();
        $max_requests = (int) $this->settings->get('max_requests_per_minute', 10);

        // A non-positive limit means "unlimited".
        if ($max_requests <= 0) {
            return true;
        }

        // Counter is keyed to the current wall-clock minute; the transient TTL
        // lets the window roll over on its own.
        $minute_key = "thinkrank_ai_rate_{$context}_{$user_id}_" . floor(time() / MINUTE_IN_SECONDS);
        $attempts = (int) get_transient($minute_key);

        if ($attempts >= $max_requests) {
            return false;
        }

        set_transient($minute_key, $attempts + 1, MINUTE_IN_SECONDS);

        return true;
    }



    /**
     * Log AI usage with actual model information
     *
     * @param int $user_id User ID
     * @param string $action Action performed
     * @param int $tokens_used Tokens consumed
     * @param string|null $actual_model Actual model used (from AI response)
     * @param string|null $raw_response Raw AI response for debugging
     * @return void
     */
    private function log_ai_usage(int $user_id, string $action, int $tokens_used, ?string $actual_model = null, ?string $raw_response = null): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_usage';

        // Prepare metadata with actual model information and raw response
        $metadata = [];
        if ($actual_model) {
            $metadata['actual_model'] = $actual_model;
        }
        if ($raw_response) {
            $metadata['raw_response'] = $raw_response;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AI usage logging requires direct database access
        $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'action' => $action,
                'tokens_used' => $tokens_used,
                'provider' => $this->settings->get('ai_provider', 'openai'),
                'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Cleanup expired cache entries
     *
     * @return void
     */
    public function cleanup_cache(): void {
        $this->cache->clean_expired();
    }

    /**
     * Optimize homepage meta content using AI (copying Site Identity pattern exactly)
     *
     * @since 1.0.0
     *
     * @param array $content_data Meta content data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_homepage_meta(array $content_data, array $options = []): array {
        // Validate input
        if (empty($content_data)) {
            throw new \Exception('Content data cannot be empty');
        }

        $user_id = get_current_user_id();

        // Ensure user has configured their API key (copying Site Identity pattern)
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));

        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Generate cache key using existing pattern
        $cache_key = 'homepage_meta_' . md5(serialize($content_data) . serialize($options)) . '_' . $user_id;

        // Check existing cache infrastructure
        // Cache_Manager::set() wraps entries as ['data' => …], so unwrap
        // before inspecting — checking optimized_data on the wrapped array
        // never matches and the cache would never hit.
        $cached_result = $this->cache->get($cache_key);
        $cached_result = $cached_result['data'] ?? $cached_result;
        if (!empty($cached_result['optimized_data'])) {
            return $cached_result;
        }

        // Check rate limiting
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded for AI optimization requests.');
        }

        // Get AI client
        $client = $this->get_client();

        if (!$client) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Perform AI optimization
        $optimization_results = $client->optimize_homepage_meta($content_data, $options);

        // Validate that we got meaningful results
        if (empty($optimization_results) || empty($optimization_results['optimized_data'])) {
            throw new \Exception('AI optimization returned empty results. Please try again.');
        }

        // Add metadata
        $optimization_results['ai_model'] = $client->get_model();
        $optimization_results['provider'] = $this->settings->get('ai_provider', 'openai');
        $optimization_results['generated_at'] = gmdate('Y-m-d H:i:s');
        $optimization_results['user_id'] = $user_id;

        // Cache the results (24 hours)
        $this->cache->set($cache_key, $optimization_results, 86400);

        // Record usage with actual model information and raw AI text (Content Brief pattern)
        $actual_model = $optimization_results['ai_model'] ?? ($client ? $client->get_model() : null);
        $ai_text = $optimization_results['_ai_text'] ?? null;
        $this->log_ai_usage($user_id, 'Homepage Meta Optimization', $optimization_results['tokens_used'] ?? 0, $actual_model, $ai_text);

        // Remove AI text from returned data to keep it clean
        unset($optimization_results['_ai_text']);

        return $optimization_results;
    }

    /**
     * Optimize homepage hero content using AI (copying Site Identity pattern exactly)
     *
     * @since 1.0.0
     *
     * @param array $hero_data Hero content data to optimize
     * @param array $options Optimization options
     * @return array Optimization results
     * @throws \Exception If optimization fails
     */
    public function optimize_homepage_hero(array $hero_data, array $options = []): array {
        // Validate input
        if (empty($hero_data)) {
            throw new \Exception('Hero data cannot be empty');
        }

        $user_id = get_current_user_id();

        // Ensure user has configured their API key (copying Site Identity pattern)
        $user_has_api_key = !empty($this->settings->get('openai_api_key')) || !empty($this->settings->get('claude_api_key')) || !empty($this->settings->get('gemini_api_key')) || !empty($this->settings->get('openrouter_api_key'));

        if (!$user_has_api_key) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Generate cache key using existing pattern
        $cache_key = 'homepage_hero_' . md5(serialize($hero_data) . serialize($options)) . '_' . $user_id;

        // Check existing cache infrastructure
        // Cache_Manager::set() wraps entries as ['data' => …], so unwrap
        // before inspecting — checking optimized_data on the wrapped array
        // never matches and the cache would never hit.
        $cached_result = $this->cache->get($cache_key);
        $cached_result = $cached_result['data'] ?? $cached_result;
        if (!empty($cached_result['optimized_data'])) {
            return $cached_result;
        }

        // Check rate limiting
        if (!$this->check_rate_limit()) {
            throw new \Exception('Rate limit exceeded for AI optimization requests.');
        }

        // Get AI client
        $client = $this->get_client();

        if (!$client) {
            throw new \Exception($this->get_client_unavailable_message());
        }

        // Perform AI optimization
        $optimization_results = $client->optimize_homepage_hero($hero_data, $options);

        // Validate that we got meaningful results
        if (empty($optimization_results) || empty($optimization_results['optimized_data'])) {
            throw new \Exception('AI optimization returned empty results. Please try again.');
        }

        // Add metadata
        $optimization_results['ai_model'] = $client->get_model();
        $optimization_results['provider'] = $this->settings->get('ai_provider', 'openai');
        $optimization_results['generated_at'] = gmdate('Y-m-d H:i:s');
        $optimization_results['user_id'] = $user_id;

        // Cache the results (24 hours)
        $this->cache->set($cache_key, $optimization_results, 86400);

        // Record usage with actual model information and raw AI text (Content Brief pattern)
        $actual_model = $optimization_results['ai_model'] ?? ($client ? $client->get_model() : null);
        $ai_text = $optimization_results['_ai_text'] ?? null;
        $this->log_ai_usage($user_id, 'Homepage Hero Optimization', $optimization_results['tokens_used'] ?? 0, $actual_model, $ai_text);

        // Remove AI text from returned data to keep it clean
        unset($optimization_results['_ai_text']);

        return $optimization_results;
    }
}
