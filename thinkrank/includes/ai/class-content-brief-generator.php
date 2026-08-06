<?php
/**
 * Content Brief Generator
 *
 * Handles AI-powered content brief generation with competitor analysis
 *
 * @package ThinkRank
 * @subpackage AI
 * @since 1.0.0
 */

namespace ThinkRank\AI;

use ThinkRank\Core\Settings;
use ThinkRank\AI\OpenAI_Client;
use ThinkRank\AI\Claude_Client;
use ThinkRank\AI\OpenRouter_Client;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content Brief Generator class
 */
class Content_Brief_Generator {

    /**
     * AI request timeout (seconds) for brief generation.
     *
     * Content briefs request a very large completion (~0.9–0.95 of the model's
     * max tokens) from reasoning models, which routinely take 40–90s — far
     * longer than the AI clients' 30s default. Without this the HTTP call is
     * aborted with cURL error 28 and the brief never completes. PHP execution
     * time is covered by each client's raise_request_time_limit() (timeout+45).
     */
    private const AI_REQUEST_TIMEOUT = 120;

    /**
     * AI request timeout (seconds) for OpenAI specifically.
     *
     * OpenAI's reasoning models (GPT-5 / o-series) burn reasoning tokens before
     * emitting any content, and briefs request ~95% of the model's completion
     * limit — so the call frequently runs past the 120s the other providers
     * need. PHP execution time is covered by raise_request_time_limit()
     * (timeout+45); the web server's own read timeout still caps the maximum.
     */
    private const OPENAI_REQUEST_TIMEOUT = 300;

    /**
     * Settings instance
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * AI client instance
     *
     * @var OpenAI_Client|Claude_Client
     */
    private $ai_client;

    /**
     * Constructor
     *
     * @param Settings|null $settings Settings instance
     * @param OpenAI_Client|Claude_Client|null $ai_client AI client instance
     */
    public function __construct(?Settings $settings = null, $ai_client = null) {
        $this->settings = $settings ?? Settings::instance();

        if ($ai_client) {
            $this->ai_client = $ai_client;
        } else {
            // Fallback to creating own client for backward compatibility
            $this->init_ai_client();
        }
    }

    /**
     * Initialize AI client based on available API keys
     *
     * @return void
     */
    private function init_ai_client(): void {
        $provider = $this->settings->get('ai_provider', 'openai');

        if ($provider === 'openai') {
            $api_key = $this->settings->get('openai_api_key');
            if ($api_key) {
                $model = $this->settings->get('openai_model', 'gpt-5-nano');
                $this->ai_client = new OpenAI_Client($api_key, $model, self::OPENAI_REQUEST_TIMEOUT);
            }
        } elseif ($provider === 'claude') {
            $api_key = $this->settings->get('claude_api_key');
            if ($api_key) {
                $model = $this->settings->get('claude_model', 'claude-3.7-sonnet');
                $this->ai_client = new Claude_Client($api_key, $model, self::AI_REQUEST_TIMEOUT);
            }
        } elseif ($provider === 'gemini') {
            $api_key = $this->settings->get('gemini_api_key');
            if ($api_key) {
                $model = $this->settings->get('gemini_model', 'gemini-2.5-flash');
                $this->ai_client = new Gemini_Client($api_key, $model, self::AI_REQUEST_TIMEOUT);
            }
        } elseif ($provider === 'openrouter') {
            $api_key = $this->settings->get('openrouter_api_key');
            if ($api_key) {
                $model = $this->settings->get('openrouter_model', 'openai/gpt-4o-mini');
                $this->ai_client = new OpenRouter_Client($api_key, $model, self::AI_REQUEST_TIMEOUT);
            }
        }

        if (!$this->ai_client) {
            throw new \Exception('Please configure your AI provider API key in ThinkRank settings.');
        }
    }

    /**
     * Get current AI model being used
     *
     * @return string Current model name
     */
    private function get_current_model(): string {
        // Try to get model from the actual AI client if available
        if ($this->ai_client && method_exists($this->ai_client, 'get_model')) {
            return $this->ai_client->get_model();
        }

        // Fallback to settings
        $provider = $this->settings->get('ai_provider', 'openai');
        if ($provider === 'claude') {
            return $this->settings->get('claude_model', 'claude-sonnet-5');
        } elseif ($provider === 'gemini') {
            return $this->settings->get('gemini_model', 'gemini-2.5-flash');
        } elseif ($provider === 'openrouter') {
            return $this->settings->get('openrouter_model', 'openai/gpt-4o-mini');
        } else {
            return $this->settings->get('openai_model', 'gpt-5-nano');
        }
    }

    /**
     * Get current AI provider
     *
     * @return string Current provider name
     */
    private function get_current_provider(): string {
        return $this->settings->get('ai_provider', 'openai');
    }

    /**
     * Extract token usage from AI response
     *
     * @param array $ai_response AI response data
     * @return int Number of tokens used
     */
    private function extract_token_usage(array $ai_response): int {
        $provider = $this->get_current_provider();

        if ($provider === 'openai' || $provider === 'openrouter') {
            // OpenAI-compatible format: response['usage']['total_tokens']
            return (int) ($ai_response['usage']['total_tokens'] ?? 0);
        } elseif ($provider === 'claude') {
            // Claude format: response['usage']['input_tokens'] + response['usage']['output_tokens']
            $input_tokens = (int) ($ai_response['usage']['input_tokens'] ?? 0);
            $output_tokens = (int) ($ai_response['usage']['output_tokens'] ?? 0);
            return $input_tokens + $output_tokens;
        } elseif ($provider === 'gemini') {
            // Gemini format: response['usageMetadata']['totalTokenCount']
            return (int) ($ai_response['usageMetadata']['totalTokenCount'] ?? 0);
        }

        // Fallback: return 0 if provider not recognized or no usage data
        return 0;
    }

    /**
     * Extract actual model used from AI response
     *
     * @param array $ai_response AI response data
     * @return string|null Actual model used or null if not found
     */
    private function extract_model_from_response(array $ai_response): ?string {
        // OpenAI format: response['model']
        if (isset($ai_response['model'])) {
            return $ai_response['model'];
        }

        // Claude format: response['model']
        if (isset($ai_response['model'])) {
            return $ai_response['model'];
        }

        // Gemini doesn't include model in response, fallback to client model
        return null;
    }

    /**
     * Generate content brief
     *
     * @param array $params Brief generation parameters
     * @return array Generated brief data
     * @throws \Exception If generation fails
     */
    public function generate_brief(array $params): array {
        // Validate required parameters
        $this->validate_brief_params($params);

        // Extract parameters
        $target_keywords = $params['target_keywords'] ?? [];
        $content_type = $params['content_type'] ?? 'blog_post';
        $target_audience = $params['target_audience'] ?? 'general';
        $content_length = $params['content_length'] ?? 'medium';
        $tone = $params['tone'] ?? 'professional';
        $competitor_urls = $params['competitor_urls'] ?? [];
        $additional_context = $params['additional_context'] ?? '';
        // Write the brief in the site (or related post's) language rather than
        // defaulting to English on non-English sites (issue #234).
        $language = \ThinkRank\AI\Language_Resolver::resolve((int) ($params['post_id'] ?? 0));

        // Analyze competitor URLs if provided
        $competitor_analysis = '';
        if (!empty($competitor_urls)) {
            $competitor_analysis = $this->analyze_competitor_urls($competitor_urls);
        }

        // Build AI prompt using shared Prompt Builder
        $prompt_builder = $this->get_prompt_builder();
        $prompt = $prompt_builder->build_content_brief_prompt(
            $target_keywords,
            $content_type,
            $target_audience,
            $content_length,
            $tone,
            $competitor_analysis,
            $additional_context,
            $this->get_current_provider(),
            $language
        );

        try {
            // Get recommended token limit for content briefs (model-specific)
            $max_tokens = method_exists($this->ai_client, 'get_recommended_tokens')
                ? $this->ai_client->get_recommended_tokens('content_brief')
                : 4000; // Fallback for non-OpenAI clients

            // Generate brief using AI
            $ai_response = $this->ai_client->generate_completion($prompt, [
                // For GPT‑5 family the client will translate to max_completion_tokens internally
                'max_tokens' => $max_tokens,
                'temperature' => 0.7,
            ]);

            // Extract text content from AI response
            $ai_text = '';

            // Handle OpenAI response format
            if (isset($ai_response['choices'][0]['message']['content'])) {
                $contentField = $ai_response['choices'][0]['message']['content'];
                if (is_string($contentField)) {
                    $ai_text = $contentField;
                } elseif (is_array($contentField)) {
                    // Concatenate text parts from array-based content (Chat Completions multimodal)
                    $parts = array_map(function($part) {
                        if (is_array($part)) {
                            return $part['text'] ?? '';
                        }
                        return is_string($part) ? $part : '';
                    }, $contentField);
                    $ai_text = trim(implode("\n", array_filter($parts)));
                }
            }
            // Handle Claude response format
            elseif (isset($ai_response['content'][0]['text'])) {
                $ai_text = $ai_response['content'][0]['text'];
            }
            // Handle Gemini response format
            elseif (isset($ai_response['candidates'][0]['content']['parts'][0]['text'])) {
                $ai_text = $ai_response['candidates'][0]['content']['parts'][0]['text'];

                // A reply cut off at the token limit is incomplete JSON, so it
                // can never be parsed. Fail with the real reason instead of
                // saving a brief titled "Unable to parse AI response".
                if (($ai_response['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') {
                    throw new \Exception('The AI stopped at its output token limit before finishing the brief. Try a shorter content length or fewer competitor URLs.');
                }
            }
            // Handle direct content field
            elseif (isset($ai_response['content']) && is_string($ai_response['content'])) {
                $ai_text = $ai_response['content'];
            }
            // Handle direct string response
            elseif (is_string($ai_response)) {
                $ai_text = $ai_response;
            }
            // If we still don't have text, log the response structure for debugging
            else {
                // As a last resort, stringify the response for visibility (prevents empty content error)
                $ai_text = is_array($ai_response) ? wp_json_encode($ai_response) : (string) $ai_response;
            }

            // Ensure we have actual text content
            if (empty(trim($ai_text))) {
                throw new \Exception('AI response was empty or contained no text content.');
            }

            // Extract token usage for analytics tracking
            $tokens_used = $this->extract_token_usage($ai_response);

            // Parse and structure the response
            $brief_data = $this->parse_ai_response($ai_text, $params);

            // Extract actual model from response before using it
            $actual_model = $this->extract_model_from_response($ai_response);

            // Add generation metadata (use actual model from response if available)
            $brief_data['generation_meta'] = [
                'provider' => $this->get_current_provider(),
                'model' => $actual_model ?: $this->get_current_model(),
                'generated_at' => current_time('mysql'),
                'version' => '1.0'
            ];

            // Save brief to database
            $brief_id = $this->save_brief($brief_data);
            $brief_data['id'] = $brief_id;

            // Log AI usage for analytics tracking (including raw response and actual model used)
            $usage_id = $this->log_ai_usage(get_current_user_id(), 'Content Brief', $tokens_used, $brief_id, $ai_text, $actual_model);

            // Set raw response for immediate display
            $brief_data['raw_response'] = $ai_text;

            // Apply normalization for React compatibility
            $brief_data = $this->normalize_brief_data($brief_data);

            return $brief_data;

        } catch (\Exception $e) {
            // Provide more specific error messages
            $error_message = $e->getMessage();
            if (strpos($error_message, 'API key') !== false) {
                throw new \Exception('API key configuration error. Please check your AI provider settings.');
            } elseif (strpos($error_message, 'Invalid AI response format') !== false) {
                throw new \Exception('AI service returned an unexpected response format. Please try again.');
            } elseif (strpos($error_message, 'empty') !== false) {
                throw new \Exception('AI service returned empty content. Please try again with different parameters.');
            } else {
                throw new \Exception('Failed to generate content brief: ' . esc_html($error_message));
            }
        }
    }

    /**
     * Validate brief generation parameters
     *
     * @param array $params Parameters to validate
     * @throws \Exception If validation fails
     */
    private function validate_brief_params(array $params): void {
        if (empty($params['target_keywords']) || !is_array($params['target_keywords'])) {
            throw new \Exception('Target keywords are required and must be an array.');
        }

        $valid_content_types = ['blog_post', 'product_page', 'landing_page', 'tutorial'];
        if (!empty($params['content_type']) && !in_array($params['content_type'], $valid_content_types)) {
            throw new \Exception('Invalid content type specified.');
        }

        $valid_lengths = ['short', 'medium', 'long'];
        if (!empty($params['content_length']) && !in_array($params['content_length'], $valid_lengths)) {
            throw new \Exception('Invalid content length specified.');
        }

        $valid_tones = ['professional', 'casual', 'technical', 'friendly'];
        if (!empty($params['tone']) && !in_array($params['tone'], $valid_tones)) {
            throw new \Exception('Invalid tone specified.');
        }
    }

    /**
     * Parse AI response into structured data
     *
     * @param string $ai_response Raw AI response
     * @param array $original_params Original generation parameters
     * @return array Structured brief data
     */
    private function parse_ai_response(string $ai_response, array $original_params): array {
        $json_data = $this->parse_json_response($ai_response);

        if (null === $json_data) {
            // JSON parsing failed - return error structure
            return $this->create_parsing_error_response($ai_response, $original_params);
        }

        return $this->structure_json_data($json_data, $original_params);
    }

    /**
     * Parse JSON response from AI
     *
     * @param string $ai_response Raw AI response
     * @return array|null Parsed JSON data or null if parsing fails
     */
    private function parse_json_response(string $ai_response): ?array {
        // Clean the response - remove any text before/after JSON
        $ai_response = trim($ai_response);

        // Handle markdown code blocks (```json ... ```)
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $ai_response, $matches)) {
            $json_string = trim($matches[1]);
        } else {
            // Find JSON object boundaries
            $start = strpos($ai_response, '{');
            $end = strrpos($ai_response, '}');

            if (false === $start || false === $end || $start >= $end) {
                return null;
            }

            $json_string = substr($ai_response, $start, $end - $start + 1);
        }

        $json_data = json_decode($json_string, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $json_data;
    }

    /**
     * Structure JSON data into expected format
     *
     * @param array $json_data Parsed JSON data
     * @param array $original_params Original generation parameters
     * @return array Structured brief data
     */
    private function structure_json_data(array $json_data, array $original_params): array {
        return [
            'title' => $json_data['title_suggestions'] ?? [],
            'meta_description' => $json_data['meta_descriptions'][0] ?? '',
            'meta_descriptions' => $json_data['meta_descriptions'] ?? [],
            'url_slugs' => $json_data['url_slugs'] ?? [],
            'outline' => $json_data['outline'] ?? [],
            'seo_recommendations' => [
                'title_suggestions' => $json_data['title_suggestions'] ?? [],
                'meta_description' => $json_data['meta_descriptions'][0] ?? '',
                'meta_descriptions' => $json_data['meta_descriptions'] ?? [],
                'url_slugs' => $json_data['url_slugs'] ?? [],
                'focus_keyword_analysis' => $this->normalize_focus_keyword_analysis($json_data['focus_keyword_analysis'] ?? []),
                'internal_links' => $json_data['internal_linking'] ?? [],
                'related_keywords' => $json_data['related_keywords'] ?? [],
                'long_tail_keywords' => []
            ],
            'social_media' => $json_data['social_media'] ?? [
                'open_graph' => ['title' => '', 'description' => ''],
                'twitter_card' => ['title' => '', 'description' => '']
            ],
            'schema_markup' => $json_data['schema_markup'] ?? [
                'recommended_types' => [],
                'key_properties' => [],
                'faq_questions' => []
            ],
            'visual_content' => $json_data['visual_content'] ?? [
                'image_recommendations' => [],
                'alt_text_suggestions' => [],
                'infographic_opportunities' => []
            ],
            'competitor_gaps' => $json_data['competitor_analysis']['content_gaps'] ?? [],
            'call_to_actions' => $json_data['call_to_actions'] ?? [],
            'writing_guidelines' => $json_data['writing_guidelines'] ?? [],
            'content_body' => $json_data['content_body'] ?? '',
            'estimated_word_count' => $this->get_word_count_estimate($original_params['content_length'] ?? 'medium'),
            'raw_response' => '', // Will be retrieved from ai_usage table
            'generation_params' => $original_params,
            'parsing_status' => 'success',
            'created_at' => current_time('mysql')
        ];
    }

    /**
     * Create error response when JSON parsing fails
     *
     * @param string $ai_response Raw AI response
     * @param array $original_params Original generation parameters
     * @return array Error response structure
     */
    private function create_parsing_error_response(string $ai_response, array $original_params): array {
        return [
            'title' => ['Error: Unable to parse AI response'],
            'meta_description' => 'AI response could not be parsed as valid JSON.',
            'meta_descriptions' => ['AI response could not be parsed as valid JSON.'],
            'url_slugs' => ['error-parsing-response'],
            'outline' => [],
            'seo_recommendations' => [
                'title_suggestions' => ['Error: Unable to parse AI response'],
                'meta_description' => 'AI response could not be parsed as valid JSON.',
                'meta_descriptions' => ['AI response could not be parsed as valid JSON.'],
                'url_slugs' => ['error-parsing-response'],
                'focus_keyword_analysis' => [
                    'primary_placement' => [],
                    'secondary_integration' => [],
                    'density_guidelines' => []
                ],
                'internal_links' => [],
                'related_keywords' => [],
                'long_tail_keywords' => []
            ],
            'social_media' => [
                'open_graph' => ['title' => 'Error', 'description' => 'Parsing failed'],
                'twitter_card' => ['title' => 'Error', 'description' => 'Parsing failed']
            ],
            'schema_markup' => [
                'recommended_types' => [],
                'key_properties' => [],
                'faq_questions' => []
            ],
            'visual_content' => [
                'image_recommendations' => [],
                'alt_text_suggestions' => [],
                'infographic_opportunities' => []
            ],
            'competitor_gaps' => [],
            'call_to_actions' => [],
            'writing_guidelines' => [],
            'content_body' => '',
            'estimated_word_count' => $this->get_word_count_estimate($original_params['content_length'] ?? 'medium'),
            'raw_response' => $ai_response, // Store raw response in error case
            'generation_params' => $original_params,
            'parsing_status' => 'failed',
            'created_at' => current_time('mysql')
        ];
    }

    /**
     * Normalize focus keyword analysis to ensure proper array structure
     *
     * @param array $focus_keyword_analysis Raw focus keyword analysis data
     * @return array Normalized focus keyword analysis
     */
    private function normalize_focus_keyword_analysis(array $focus_keyword_analysis): array {
        $normalized = [
            'primary_placement' => [],
            'secondary_integration' => [],
            'density_guidelines' => []
        ];

        // Normalize primary_placement
        if (isset($focus_keyword_analysis['primary_placement'])) {
            if (is_array($focus_keyword_analysis['primary_placement'])) {
                $normalized['primary_placement'] = $focus_keyword_analysis['primary_placement'];
            } elseif (is_string($focus_keyword_analysis['primary_placement'])) {
                // Convert string to array by splitting on common delimiters
                $normalized['primary_placement'] = array_filter(array_map('trim', preg_split('/[,;]/', $focus_keyword_analysis['primary_placement'])));
            }
        }

        // Normalize secondary_integration - this is the problematic field
        if (isset($focus_keyword_analysis['secondary_integration'])) {
            if (is_array($focus_keyword_analysis['secondary_integration'])) {
                $normalized['secondary_integration'] = $focus_keyword_analysis['secondary_integration'];
            } elseif (is_string($focus_keyword_analysis['secondary_integration'])) {
                // Convert string to array - split by sentences or use as single item
                $text = trim($focus_keyword_analysis['secondary_integration']);
                if (!empty($text)) {
                    // Split by sentences if it contains periods, otherwise use as single item
                    if (strpos($text, '.') !== false) {
                        $sentences = array_filter(array_map('trim', explode('.', $text)));
                        $normalized['secondary_integration'] = array_map(function($sentence) {
                            return $sentence . (substr($sentence, -1) !== '.' ? '.' : '');
                        }, $sentences);
                    } else {
                        $normalized['secondary_integration'] = [$text];
                    }
                }
            }
        }

        // Normalize density_guidelines
        if (isset($focus_keyword_analysis['density_guidelines'])) {
            if (is_array($focus_keyword_analysis['density_guidelines'])) {
                $normalized['density_guidelines'] = $focus_keyword_analysis['density_guidelines'];
            } elseif (is_string($focus_keyword_analysis['density_guidelines'])) {
                // Convert string to array by splitting on common delimiters
                $guidelines = array_filter(array_map('trim', preg_split('/[,;]/', $focus_keyword_analysis['density_guidelines'])));
                $normalized['density_guidelines'] = $guidelines ?: [$focus_keyword_analysis['density_guidelines']];
            }
        }

        return $normalized;
    }
    private function analyze_competitor_urls(array $urls): string {
        $analysis_results = [];
        $failed_urls = [];

        // Limit to first 3 URLs to prevent timeout
        $urls = array_slice($urls, 0, 3);

        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                $failed_urls[] = $url . " (invalid URL)";
                continue;
            }

            // SSRF guard: only fetch public http/https hosts. Blocks loopback,
            // link-local (cloud metadata), private and reserved ranges before any
            // request is made.
            if (!$this->is_safe_public_url($url)) {
                $failed_urls[] = $url . " (blocked: non-public host)";
                continue;
            }

            $content_data = $this->scrape_competitor_content($url);
            if ($content_data) {
                $analysis_results[] = $this->format_competitor_analysis($url, $content_data);
            } else {
                $failed_urls[] = $url . " (failed to scrape)";
            }
        }

        $result = "";

        if (!empty($analysis_results)) {
            $result .= implode("\n\n", $analysis_results);
        }

        if (!empty($failed_urls)) {
            $result .= "\n\nNote: The following URLs could not be analyzed:\n";
            $result .= "- " . implode("\n- ", $failed_urls);
        }

        if (empty($analysis_results)) {
            return "No competitor URLs could be successfully analyzed. Please ensure URLs are accessible and valid.";
        }

        return $result;
    }

    /**
     * Whether a competitor URL is safe to fetch: an http/https URL whose host
     * resolves only to public IP addresses.
     *
     * Prevents SSRF — a low-privilege user could otherwise point competitor
     * scraping at loopback, link-local (e.g. 169.254.169.254 cloud metadata),
     * private or reserved addresses to probe internal services.
     *
     * @param string $url URL to validate.
     * @return bool True when safe to fetch.
     */
    private function is_safe_public_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        // Only ever fetch over http/https.
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        // Resolve the host to the IP(s) it points at (literal IPs pass through).
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    } elseif (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
            // Fallback for hosts dns_get_record can't resolve.
            if (empty($ips)) {
                $resolved = gethostbyname($host);
                if ($resolved !== $host) {
                    $ips[] = $resolved;
                }
            }
        }

        // Unresolvable host → don't fetch.
        if (empty($ips)) {
            return false;
        }

        // Reject if any resolved address is private, reserved, loopback or link-local.
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scrape content from a competitor URL
     *
     * @param string $url The URL to scrape
     * @return array|null Content data or null if failed
     */
    private function scrape_competitor_content(string $url): ?array {
        // wp_safe_remote_get() (reject_unsafe_urls) re-validates the URL and, unlike
        // wp_remote_get(), rejects redirects to internal/private hosts — closing the
        // redirect-based SSRF bypass on top of the pre-flight is_safe_public_url() check.
        $response = wp_safe_remote_get($url, [
            'timeout' => 8, // Reduced from 15 to 8 seconds
            'user-agent' => 'Mozilla/5.0 (compatible; ThinkRank SEO Bot)',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }

        return $this->parse_html_content($html, $url);
    }

    /**
     * Parse HTML content and extract key SEO elements
     *
     * @param string $html HTML content
     * @param string $url Original URL for context
     * @return array Parsed content data
     */
    private function parse_html_content(string $html, string $url): array {
        // Create DOMDocument to parse HTML
        $dom = new \DOMDocument();

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Extract title
        $title_nodes = $xpath->query('//title');
        $title = $title_nodes->length > 0 ? trim($title_nodes->item(0)->textContent) : '';

        // Extract meta description
        $meta_desc_nodes = $xpath->query('//meta[@name="description"]/@content');
        $meta_description = $meta_desc_nodes->length > 0 ? trim($meta_desc_nodes->item(0)->textContent) : '';

        // Extract headings (H1-H6)
        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $heading_nodes = $xpath->query("//h{$i}");
            foreach ($heading_nodes as $node) {
                $text = trim($node->textContent);
                if (!empty($text)) {
                    $headings["h{$i}"][] = $text;
                }
            }
        }

        // Extract body text and calculate word count
        $body_nodes = $xpath->query('//body');
        $body_text = '';
        if ($body_nodes->length > 0) {
            $body_text = $this->extract_clean_text($body_nodes->item(0));
        }

        $word_count = str_word_count($body_text);

        // Extract meta keywords if present
        $meta_keywords_nodes = $xpath->query('//meta[@name="keywords"]/@content');
        $meta_keywords = $meta_keywords_nodes->length > 0 ? trim($meta_keywords_nodes->item(0)->textContent) : '';

        // Extract internal links count
        $internal_links = $xpath->query('//a[starts-with(@href, "/") or contains(@href, "' . wp_parse_url($url, PHP_URL_HOST) . '")]');
        $internal_link_count = $internal_links->length;

        // Extract external links count
        $external_links = $xpath->query('//a[starts-with(@href, "http") and not(contains(@href, "' . wp_parse_url($url, PHP_URL_HOST) . '"))]');
        $external_link_count = $external_links->length;

        // Extract images count and alt text analysis
        $images = $xpath->query('//img');
        $image_count = $images->length;
        $images_with_alt = $xpath->query('//img[@alt and @alt!=""]');
        $images_with_alt_count = $images_with_alt->length;

        // Extract schema markup
        $schema_scripts = $xpath->query('//script[@type="application/ld+json"]');
        $has_schema = $schema_scripts->length > 0;

        // Extract last modified date if available
        $last_modified_nodes = $xpath->query('//meta[@name="last-modified"]/@content | //meta[@property="article:modified_time"]/@content');
        $last_modified = $last_modified_nodes->length > 0 ? $last_modified_nodes->item(0)->textContent : '';

        // Calculate readability metrics
        $readability_score = $this->calculate_readability_score($body_text);

        // Extract keyword density for target keywords (if provided)
        $keyword_density = $this->analyze_keyword_density($body_text, $title);

        // Detect content freshness indicators
        $freshness_indicators = $this->detect_freshness_indicators($html, $body_text);

        return [
            'url' => $url,
            'title' => $title,
            'meta_description' => $meta_description,
            'meta_keywords' => $meta_keywords,
            'headings' => $headings,
            'word_count' => $word_count,
            'internal_links' => $internal_link_count,
            'external_links' => $external_link_count,
            'images' => [
                'total' => $image_count,
                'with_alt' => $images_with_alt_count,
                'alt_ratio' => $image_count > 0 ? round(($images_with_alt_count / $image_count) * 100, 1) : 0
            ],
            'seo' => [
                'has_schema' => $has_schema,
                'title_length' => strlen($title),
                'meta_desc_length' => strlen($meta_description),
                'title_score' => $this->score_title_seo($title),
                'meta_desc_score' => $this->score_meta_description($meta_description)
            ],
            'content_quality' => [
                'readability_score' => $readability_score,
                'keyword_density' => $keyword_density,
                'freshness_indicators' => $freshness_indicators,
                'content_depth' => $this->assess_content_depth($headings, $word_count)
            ],
            'last_modified' => $last_modified,
            'content_preview' => substr($body_text, 0, 500) . '...',
            'analysis_timestamp' => current_time('mysql')
        ];
    }

    /**
     * Extract clean text from DOM node, removing scripts and styles
     *
     * @param \DOMNode $node DOM node to extract text from
     * @return string Clean text content
     */
    private function extract_clean_text(\DOMNode $node): string {
        // Remove script and style elements
        $xpath = new \DOMXPath($node->ownerDocument);
        $scripts = $xpath->query('.//script | .//style', $node);

        foreach ($scripts as $script) {
            $script->parentNode->removeChild($script);
        }

        // Get text content and clean it up
        $text = $node->textContent;

        // Remove extra whitespace and normalize
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Format competitor analysis for AI prompt
     *
     * @param string $url Competitor URL
     * @param array $content_data Parsed content data
     * @return string Formatted analysis
     */
    private function format_competitor_analysis(string $url, array $content_data): string {
        $analysis = "=== COMPETITOR ANALYSIS ===\n";
        $analysis .= "URL: {$url}\n";
        $analysis .= "Title: {$content_data['title']} (Length: {$content_data['seo']['title_length']} chars, Score: {$content_data['seo']['title_score']['grade']})\n";

        if (!empty($content_data['meta_description'])) {
            $analysis .= "Meta Description: {$content_data['meta_description']} (Length: {$content_data['seo']['meta_desc_length']} chars, Score: {$content_data['seo']['meta_desc_score']['grade']})\n";
        }

        $analysis .= "\nCONTENT METRICS:\n";
        $analysis .= "- Word Count: {$content_data['word_count']} words\n";
        $analysis .= "- Content Depth: {$content_data['content_quality']['content_depth']['level']} (Score: {$content_data['content_quality']['content_depth']['score']}/100)\n";
        $analysis .= "- Readability: {$content_data['content_quality']['readability_score']['level']} (Score: {$content_data['content_quality']['readability_score']['score']}/100)\n";
        $analysis .= "- Internal Links: {$content_data['internal_links']}\n";
        $analysis .= "- External Links: {$content_data['external_links']}\n";
        $analysis .= "- Images: {$content_data['images']['total']} total, {$content_data['images']['with_alt']} with alt text ({$content_data['images']['alt_ratio']}%)\n";

        // Add heading structure
        if (!empty($content_data['headings'])) {
            $analysis .= "\nCONTENT STRUCTURE:\n";
            foreach ($content_data['headings'] as $level => $headings) {
                $analysis .= "- " . strtoupper($level) . " ({count}): " . implode(', ', array_slice($headings, 0, 3));
                if (count($headings) > 3) {
                    $analysis .= "... (+" . (count($headings) - 3) . " more)";
                }
                $analysis .= "\n";
            }
        }

        // Add SEO features
        $analysis .= "\nSEO FEATURES:\n";
        $analysis .= "- Schema Markup: " . ($content_data['seo']['has_schema'] ? 'Yes' : 'No') . "\n";
        if (!empty($content_data['meta_keywords'])) {
            $analysis .= "- Meta Keywords: {$content_data['meta_keywords']}\n";
        }

        // Add content quality insights
        if (!empty($content_data['content_quality']['keyword_density']['top_keywords'])) {
            $analysis .= "\nTOP KEYWORDS:\n";
            foreach (array_slice($content_data['content_quality']['keyword_density']['top_keywords'], 0, 5) as $kw) {
                $analysis .= "- {$kw['keyword']}: {$kw['count']} times ({$kw['density']}%)\n";
            }
        }

        // Add freshness indicators
        if (!empty($content_data['content_quality']['freshness_indicators'])) {
            $analysis .= "\nCONTENT FRESHNESS:\n";
            foreach ($content_data['content_quality']['freshness_indicators'] as $indicator) {
                $analysis .= "- {$indicator}\n";
            }
        }

        $analysis .= "\n" . str_repeat("=", 50) . "\n";

        return $analysis;
    }

    /**
     * Get word count estimate based on content length
     *
     * @param string $content_length Content length setting
     * @return int Estimated word count
     */
    private function get_word_count_estimate(string $content_length): int {
        $estimates = [
            'short' => 650,
            'medium' => 1250,
            'long' => 2500
        ];

        return $estimates[$content_length] ?? 1250;
    }
    private function save_brief(array $brief_data): int {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_content_briefs';

        // Prepare data for insertion
        $insert_data = [
            'user_id' => get_current_user_id(),
            'title' => $brief_data['title'][0] ?? 'Untitled Brief',
            'target_keywords' => wp_json_encode($brief_data['generation_params']['target_keywords'] ?? []),
            'content_type' => $brief_data['generation_params']['content_type'] ?? 'blog_post',
            'brief_data' => wp_json_encode($brief_data),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $insert_format = [
            '%d', // user_id
            '%s', // title
            '%s', // target_keywords
            '%s', // content_type
            '%s', // brief_data
            '%s', // created_at
            '%s'  // updated_at
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Content brief storage requires direct database access
        $result = $wpdb->insert($table_name, $insert_data, $insert_format);

        if (false === $result) {
            throw new \Exception('Failed to save content brief to database.');
        }

        return $wpdb->insert_id;
    }

    /**
     * Normalize brief data for React compatibility
     *
     * @param array $brief_data Brief data to normalize
     * @return array Normalized brief data
     */
    private function normalize_brief_data(array $brief_data): array {
        // Normalize focus_keyword_analysis
        if (isset($brief_data['seo_recommendations']['focus_keyword_analysis'])) {
            $brief_data['seo_recommendations']['focus_keyword_analysis'] =
                $this->normalize_focus_keyword_analysis($brief_data['seo_recommendations']['focus_keyword_analysis']);
        }

        // Normalize call_to_actions (convert objects to strings)
        if (isset($brief_data['call_to_actions']) && is_array($brief_data['call_to_actions'])) {
            $brief_data['call_to_actions'] = array_map(function($cta) {
                if (is_array($cta) && isset($cta['text'])) {
                    return $cta['text'] . (isset($cta['placement']) ? ' (' . $cta['placement'] . ')' : '');
                }
                return is_string($cta) ? $cta : '';
            }, $brief_data['call_to_actions']);
        }

        // Normalize visual content image_recommendations (convert objects to strings)
        if (isset($brief_data['visual_content']['image_recommendations']) && is_array($brief_data['visual_content']['image_recommendations'])) {
            $brief_data['visual_content']['image_recommendations'] = array_map(function($rec) {
                if (is_array($rec)) {
                    $text = '';
                    if (isset($rec['type'])) $text .= $rec['type'] . ': ';
                    if (isset($rec['description'])) $text .= $rec['description'];
                    if (isset($rec['alt_text'])) $text .= ' (Alt: ' . $rec['alt_text'] . ')';
                    return $text ?: 'Image recommendation';
                }
                return is_string($rec) ? $rec : 'Image recommendation';
            }, $brief_data['visual_content']['image_recommendations']);
        }

        return $brief_data;
    }

    /**
     * Get saved briefs for current user
     *
     * @param int $limit Number of briefs to retrieve
     * @param int $offset Offset for pagination
     * @return array Array of saved briefs
     */
    public function get_user_briefs(int $limit = 10, int $offset = 0): array {
        global $wpdb;

        // Get table name and escape it properly (table names cannot be parameterized)
        $table_name = esc_sql($wpdb->prefix . 'thinkrank_content_briefs');
        $user_id = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Content brief retrieval requires direct database access
        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly escaped using esc_sql()
                "SELECT * FROM `{$table_name}` WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // $wpdb->get_results() returns null on a DB error; this method's return
        // type is : array, so normalize before iterating/returning.
        if (!is_array($results)) {
            return [];
        }

        // Decode JSON data and normalize for React compatibility
        foreach ($results as &$brief) {
            $brief = $this->hydrate_brief_row($brief);
        }
        unset($brief);

        return $results;
    }

    /**
     * Get a single saved brief by id, scoped to the current user.
     *
     * @param int $brief_id Brief ID.
     * @return array|null Hydrated brief, or null if it doesn't exist or does not
     *                    belong to the current user.
     */
    public function get_brief(int $brief_id): ?array {
        global $wpdb;

        // Table names cannot be parameterized; escape it.
        $table_name = esc_sql($wpdb->prefix . 'thinkrank_content_briefs');
        $user_id = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Content brief retrieval requires direct database access
        $brief = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly escaped using esc_sql()
                "SELECT * FROM `{$table_name}` WHERE id = %d AND user_id = %d LIMIT 1",
                $brief_id,
                $user_id
            ),
            ARRAY_A
        );

        if (!$brief) {
            return null;
        }

        return $this->hydrate_brief_row($brief);
    }

    /**
     * Decode + normalize a raw content-brief DB row for API/React consumption.
     *
     * @param array $brief Raw database row.
     * @return array Hydrated brief.
     */
    private function hydrate_brief_row(array $brief): array {
        $brief['target_keywords'] = json_decode($brief['target_keywords'], true);
        $brief['brief_data'] = json_decode($brief['brief_data'], true);

        // Retrieve raw response from ai_usage table
        $brief['brief_data']['raw_response'] = $this->get_raw_response_for_brief($brief['id']);

        // Update model with actual model used (if available in ai_usage table)
        $actual_model = $this->get_actual_model_for_brief($brief['id']);
        if ($actual_model && isset($brief['brief_data']['generation_meta'])) {
            $brief['brief_data']['generation_meta']['model'] = $actual_model;
        }

        // Apply normalization to existing briefs to ensure React compatibility
        $brief['brief_data'] = $this->normalize_brief_data($brief['brief_data']);

        return $brief;
    }

    /**
     * Delete brief
     *
     * @param int $brief_id Brief ID to delete
     * @return bool Success status
     */
    public function delete_brief(int $brief_id): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_content_briefs';
        $user_id = get_current_user_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Content brief deletion requires direct database access
        $result = $wpdb->delete(
            $table_name,
            [
                'id' => $brief_id,
                'user_id' => $user_id
            ],
            ['%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Calculate readability score using Flesch Reading Ease
     *
     * @param string $text Text to analyze
     * @return array Readability metrics
     */
    private function calculate_readability_score(string $text): array {
        if (empty($text)) {
            return ['score' => 0, 'level' => 'Unknown', 'grade' => 'N/A'];
        }

        // Count sentences (approximate)
        $sentences = preg_split('/[.!?]+/', $text);
        $sentence_count = count(array_filter($sentences, function($s) { return trim($s) !== ''; }));

        // Count words
        $word_count = str_word_count($text);

        // Count syllables (approximate)
        $syllable_count = $this->count_syllables($text);

        if ($sentence_count === 0 || $word_count === 0) {
            return ['score' => 0, 'level' => 'Unknown', 'grade' => 'N/A'];
        }

        // Flesch Reading Ease formula
        $avg_sentence_length = $word_count / $sentence_count;
        $avg_syllables_per_word = $syllable_count / $word_count;

        $flesch_score = 206.835 - (1.015 * $avg_sentence_length) - (84.6 * $avg_syllables_per_word);
        $flesch_score = max(0, min(100, $flesch_score)); // Clamp between 0-100

        // Determine reading level
        if ($flesch_score >= 90) {
            $level = 'Very Easy';
            $grade = '5th grade';
        } elseif ($flesch_score >= 80) {
            $level = 'Easy';
            $grade = '6th grade';
        } elseif ($flesch_score >= 70) {
            $level = 'Fairly Easy';
            $grade = '7th grade';
        } elseif ($flesch_score >= 60) {
            $level = 'Standard';
            $grade = '8th-9th grade';
        } elseif ($flesch_score >= 50) {
            $level = 'Fairly Difficult';
            $grade = '10th-12th grade';
        } elseif ($flesch_score >= 30) {
            $level = 'Difficult';
            $grade = 'College level';
        } else {
            $level = 'Very Difficult';
            $grade = 'Graduate level';
        }

        return [
            'score' => round($flesch_score, 1),
            'level' => $level,
            'grade' => $grade
        ];
    }

    /**
     * Count syllables in text (approximate)
     *
     * @param string $text Text to analyze
     * @return int Syllable count
     */
    private function count_syllables(string $text): int {
        $words = str_word_count(strtolower($text), 1);
        $syllable_count = 0;

        foreach ($words as $word) {
            $syllable_count += $this->count_word_syllables($word);
        }

        return max(1, $syllable_count); // At least 1 syllable
    }

    /**
     * Count syllables in a single word
     *
     * @param string $word Word to analyze
     * @return int Syllable count
     */
    private function count_word_syllables(string $word): int {
        $word = strtolower($word);
        $vowels = 'aeiouy';
        $syllable_count = 0;
        $previous_was_vowel = false;

        for ($i = 0; $i < strlen($word); $i++) {
            $is_vowel = strpos($vowels, $word[$i]) !== false;
            if ($is_vowel && !$previous_was_vowel) {
                $syllable_count++;
            }
            $previous_was_vowel = $is_vowel;
        }

        // Handle silent 'e'
        if (substr($word, -1) === 'e' && $syllable_count > 1) {
            $syllable_count--;
        }

        return max(1, $syllable_count);
    }

    /**
     * Analyze keyword density in content
     *
     * @param string $text Content text
     * @param string $title Page title
     * @return array Keyword analysis
     */
    private function analyze_keyword_density(string $text, string $title): array {
        $combined_text = strtolower($title . ' ' . $text);
        $words = str_word_count($combined_text, 1);
        $total_words = count($words);

        if ($total_words === 0) {
            return ['top_keywords' => [], 'total_words' => 0];
        }

        // Count word frequency
        $word_counts = array_count_values($words);

        // Filter out common stop words
        $stop_words = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them'];

        foreach ($stop_words as $stop_word) {
            unset($word_counts[$stop_word]);
        }

        // Filter out single characters and numbers
        $word_counts = array_filter($word_counts, function($count, $word) {
            return strlen($word) > 2 && !is_numeric($word) && $count > 1;
        }, ARRAY_FILTER_USE_BOTH);

        // Sort by frequency
        arsort($word_counts);

        // Calculate density and format results
        $top_keywords = [];
        foreach (array_slice($word_counts, 0, 10, true) as $word => $count) {
            $density = round(($count / $total_words) * 100, 2);
            $top_keywords[] = [
                'keyword' => $word,
                'count' => $count,
                'density' => $density
            ];
        }

        return [
            'top_keywords' => $top_keywords,
            'total_words' => $total_words
        ];
    }

    /**
     * Detect content freshness indicators
     *
     * @param string $html Full HTML content
     * @param string $text Body text
     * @return array Freshness indicators
     */
    private function detect_freshness_indicators(string $html, string $text): array {
        $indicators = [];

        // Check for date patterns in content
        if (preg_match('/\b(updated|revised|modified|published).*?(\d{4}|\d{1,2}\/\d{1,2}\/\d{2,4})/i', $text)) {
            $indicators[] = 'Contains recent update dates';
        }

        // Check for current year references
        $current_year = gmdate('Y');
        if (strpos($text, $current_year) !== false) {
            $indicators[] = "References current year ({$current_year})";
        }

        // Check for "latest", "new", "recent" keywords
        if (preg_match('/\b(latest|newest|recent|updated|current|modern|today)\b/i', $text)) {
            $indicators[] = 'Uses freshness keywords';
        }

        // Check for structured data with dates
        if (preg_match('/"dateModified"|"datePublished"/i', $html)) {
            $indicators[] = 'Has structured date metadata';
        }

        return $indicators;
    }

    /**
     * Score title for SEO effectiveness
     *
     * @param string $title Page title
     * @return array Title scoring
     */
    private function score_title_seo(string $title): array {
        $score = 0;
        $max_score = 100;
        $feedback = [];

        // Length check (optimal: 50-60 characters)
        $length = strlen($title);
        if ($length >= 50 && $length <= 60) {
            $score += 25;
            $feedback[] = 'Good length (50-60 chars)';
        } elseif ($length >= 40 && $length <= 70) {
            $score += 15;
            $feedback[] = 'Acceptable length';
        } else {
            $feedback[] = $length < 40 ? 'Too short (under 40 chars)' : 'Too long (over 70 chars)';
        }

        // Word count (optimal: 5-9 words)
        $word_count = str_word_count($title);
        if ($word_count >= 5 && $word_count <= 9) {
            $score += 20;
            $feedback[] = 'Good word count';
        } elseif ($word_count >= 3 && $word_count <= 12) {
            $score += 10;
            $feedback[] = 'Acceptable word count';
        } else {
            $feedback[] = $word_count < 3 ? 'Too few words' : 'Too many words';
        }

        // Check for power words
        $power_words = ['ultimate', 'complete', 'guide', 'best', 'top', 'essential', 'proven', 'expert', 'advanced', 'beginner'];
        $has_power_words = false;
        foreach ($power_words as $power_word) {
            if (stripos($title, $power_word) !== false) {
                $has_power_words = true;
                break;
            }
        }
        if ($has_power_words) {
            $score += 15;
            $feedback[] = 'Contains power words';
        }

        // Check for numbers
        if (preg_match('/\d+/', $title)) {
            $score += 10;
            $feedback[] = 'Contains numbers';
        }

        // Check for emotional triggers
        $emotional_words = ['amazing', 'incredible', 'shocking', 'secret', 'revealed', 'proven', 'guaranteed'];
        $has_emotional_words = false;
        foreach ($emotional_words as $emotional_word) {
            if (stripos($title, $emotional_word) !== false) {
                $has_emotional_words = true;
                break;
            }
        }
        if ($has_emotional_words) {
            $score += 10;
            $feedback[] = 'Contains emotional triggers';
        }

        // Uniqueness check (avoid generic titles)
        $generic_patterns = ['untitled', 'new page', 'home', 'welcome'];
        $is_generic = false;
        foreach ($generic_patterns as $pattern) {
            if (stripos($title, $pattern) !== false) {
                $is_generic = true;
                break;
            }
        }
        if (!$is_generic) {
            $score += 20;
            $feedback[] = 'Appears unique';
        } else {
            $feedback[] = 'Appears generic';
        }

        return [
            'score' => min($score, $max_score),
            'max_score' => $max_score,
            'grade' => $this->get_grade_from_score($score),
            'feedback' => $feedback
        ];
    }

    /**
     * Score meta description for SEO effectiveness
     *
     * @param string $meta_desc Meta description
     * @return array Meta description scoring
     */
    private function score_meta_description(string $meta_desc): array {
        $score = 0;
        $max_score = 100;
        $feedback = [];

        if (empty($meta_desc)) {
            return [
                'score' => 0,
                'max_score' => $max_score,
                'grade' => 'F',
                'feedback' => ['No meta description found']
            ];
        }

        // Length check (optimal: 150-160 characters)
        $length = strlen($meta_desc);
        if ($length >= 150 && $length <= 160) {
            $score += 30;
            $feedback[] = 'Optimal length (150-160 chars)';
        } elseif ($length >= 120 && $length <= 170) {
            $score += 20;
            $feedback[] = 'Good length';
        } elseif ($length >= 100 && $length <= 180) {
            $score += 10;
            $feedback[] = 'Acceptable length';
        } else {
            $feedback[] = $length < 100 ? 'Too short (under 100 chars)' : 'Too long (over 180 chars)';
        }

        // Check for call-to-action
        $cta_words = ['learn', 'discover', 'find out', 'get', 'download', 'try', 'start', 'join', 'sign up', 'contact', 'buy', 'shop'];
        $has_cta = false;
        foreach ($cta_words as $cta_word) {
            if (stripos($meta_desc, $cta_word) !== false) {
                $has_cta = true;
                break;
            }
        }
        if ($has_cta) {
            $score += 20;
            $feedback[] = 'Contains call-to-action';
        }

        // Check for unique selling proposition
        $usp_words = ['best', 'top', 'leading', 'expert', 'professional', 'trusted', 'proven', 'award-winning'];
        $has_usp = false;
        foreach ($usp_words as $usp_word) {
            if (stripos($meta_desc, $usp_word) !== false) {
                $has_usp = true;
                break;
            }
        }
        if ($has_usp) {
            $score += 15;
            $feedback[] = 'Contains unique selling proposition';
        }

        // Check for benefits/value proposition
        $benefit_words = ['save', 'improve', 'increase', 'boost', 'enhance', 'optimize', 'maximize', 'reduce', 'eliminate'];
        $has_benefits = false;
        foreach ($benefit_words as $benefit_word) {
            if (stripos($meta_desc, $benefit_word) !== false) {
                $has_benefits = true;
                break;
            }
        }
        if ($has_benefits) {
            $score += 15;
            $feedback[] = 'Highlights benefits';
        }

        // Readability check
        $sentences = preg_split('/[.!?]+/', $meta_desc);
        $sentence_count = count(array_filter($sentences, function($s) { return trim($s) !== ''; }));
        if ($sentence_count >= 1 && $sentence_count <= 3) {
            $score += 20;
            $feedback[] = 'Good sentence structure';
        } else {
            $feedback[] = $sentence_count === 0 ? 'No clear sentences' : 'Too many sentences';
        }

        return [
            'score' => min($score, $max_score),
            'max_score' => $max_score,
            'grade' => $this->get_grade_from_score($score),
            'feedback' => $feedback
        ];
    }

    /**
     * Assess content depth based on structure and length
     *
     * @param array $headings Heading structure
     * @param int $word_count Word count
     * @return array Content depth assessment
     */
    private function assess_content_depth(array $headings, int $word_count): array {
        $depth_score = 0;
        $max_score = 100;

        // Word count scoring (more words = more depth)
        if ($word_count >= 2000) {
            $depth_score += 40;
        } elseif ($word_count >= 1000) {
            $depth_score += 30;
        } elseif ($word_count >= 500) {
            $depth_score += 20;
        } elseif ($word_count >= 300) {
            $depth_score += 10;
        }

        // Heading structure scoring
        $total_headings = 0;
        $heading_levels = 0;
        foreach ($headings as $level => $level_headings) {
            $total_headings += count($level_headings);
            $heading_levels++;
        }

        if ($total_headings >= 10) {
            $depth_score += 25;
        } elseif ($total_headings >= 5) {
            $depth_score += 15;
        } elseif ($total_headings >= 3) {
            $depth_score += 10;
        }

        // Heading hierarchy scoring
        if ($heading_levels >= 3) {
            $depth_score += 20;
        } elseif ($heading_levels >= 2) {
            $depth_score += 15;
        }

        // Content structure bonus
        if (isset($headings['h1']) && isset($headings['h2'])) {
            $depth_score += 15;
        }

        // Determine depth level
        if ($depth_score >= 80) {
            $level = 'Comprehensive';
        } elseif ($depth_score >= 60) {
            $level = 'Detailed';
        } elseif ($depth_score >= 40) {
            $level = 'Moderate';
        } elseif ($depth_score >= 20) {
            $level = 'Basic';
        } else {
            $level = 'Shallow';
        }

        return [
            'score' => min($depth_score, $max_score),
            'level' => $level,
            'word_count' => $word_count,
            'total_headings' => $total_headings,
            'heading_levels' => $heading_levels
        ];
    }

    /**
     * Convert numeric score to letter grade
     *
     * @param int $score Numeric score
     * @return string Letter grade
     */
    private function get_grade_from_score(int $score): string {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    /**
     * Log AI usage for analytics
     *
     * @param int $user_id User ID
     * @param string $action Action performed
     * @param int $tokens_used Tokens consumed
     * @param int|null $post_id Related post/brief ID
     * @param string|null $raw_response Raw AI response for debugging
     * @param string|null $actual_model Actual model used (from response)
     * @return int Usage record ID
     */
    private function log_ai_usage(int $user_id, string $action, int $tokens_used, ?int $post_id = null, ?string $raw_response = null, ?string $actual_model = null): int {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_usage';

        $metadata = [];
        if ($raw_response) {
            $metadata['raw_response'] = $raw_response;
        }
        if ($actual_model) {
            $metadata['actual_model'] = $actual_model;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AI usage logging requires direct database access
        $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'action' => $action,
                'tokens_used' => $tokens_used,
                'provider' => $this->settings->get('ai_provider', 'openai'),
                'post_id' => $post_id,
                'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%d', '%s', '%s']
        );

        return $wpdb->insert_id;
    }

    /**
     * Get raw AI response for a brief from ai_usage table
     *
     * @param int $brief_id Brief ID
     * @return string Raw AI response or empty string if not found
     */
    private function get_raw_response_for_brief(int $brief_id): string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_usage';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AI usage retrieval requires direct database access
        $result = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is validated with WordPress prefix
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is validated with WordPress prefix
                "SELECT metadata FROM `{$table_name}` WHERE post_id = %d AND action = 'content_brief' ORDER BY created_at DESC LIMIT 1",
                $brief_id
            )
        );

        if ($result) {
            $metadata = json_decode($result, true);
            return $metadata['raw_response'] ?? '';
        }

        return '';
    }

    /**
     * Get actual model used for a brief from ai_usage table
     *
     * @param int $brief_id Brief ID
     * @return string|null Actual model used or null if not found
     */
    private function get_actual_model_for_brief(int $brief_id): ?string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_ai_usage';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AI usage retrieval requires direct database access
        $result = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is validated with WordPress prefix
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is validated with WordPress prefix
                "SELECT metadata FROM `{$table_name}` WHERE post_id = %d AND action = 'content_brief' ORDER BY created_at DESC LIMIT 1",
                $brief_id
            )
        );

        if ($result) {
            $metadata = json_decode($result, true);
            return $metadata['actual_model'] ?? null;
        }

        return null;
    }

    /**
     * Get Prompt Builder instance
     *
     * @since 1.0.0
     *
     * @return \ThinkRank\AI\Prompt_Builder Prompt Builder instance
     */
    private function get_prompt_builder(): \ThinkRank\AI\Prompt_Builder {
        if (!class_exists('ThinkRank\\AI\\Prompt_Builder')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/ai/class-prompt-builder.php';
        }
        return new \ThinkRank\AI\Prompt_Builder();
    }
}
