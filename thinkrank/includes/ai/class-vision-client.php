<?php
/**
 * Vision client — describes an image with a multimodal model.
 *
 * Kept separate from the text clients on purpose. Every provider expresses
 * image input differently (OpenAI nests typed content parts, Anthropic wants a
 * base64 `source` block, Gemini wants `inline_data`), and folding three
 * incompatible shapes into `generate_completion()` would make the text path
 * harder to read for no benefit. This class owns that divergence and returns
 * one thing: a sentence describing the picture.
 *
 * Images are sent as base64 rather than by URL because a WordPress upload is
 * frequently unreachable from the provider — local sites, staging behind auth,
 * intranets, or a CDN that blocks bots (see the ChatGPT/User-Agent block in the
 * MCP threads). A URL that works for us often 403s for them.
 *
 * @package ThinkRank\AI
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multimodal image description.
 */
class Vision_Client {

    /**
     * Largest image we will upload, in bytes.
     *
     * Providers cap request size (Anthropic ~5MB per image) and a photo
     * straight off a phone routinely exceeds it. WordPress already generates
     * scaled sizes, so we prefer one of those and only fall back to the
     * original when nothing smaller exists.
     */
    private const MAX_IMAGE_BYTES = 3500000;

    /**
     * Preferred registered image sizes, smallest adequate first. A description
     * does not need a 4000px original — 'medium_large' is plenty and keeps the
     * request (and the bill) small.
     *
     * @var string[]
     */
    private const PREFERRED_SIZES = ['medium_large', 'large', 'medium'];

    /**
     * Fields describe_attachment_fields() can write besides alt text.
     *
     * The caption is the attachment's `post_excerpt`, which themes and
     * galleries print under the image; the description is its `post_content`,
     * shown on the attachment page (#1058).
     *
     * @since 2.21.2
     * @var string[]
     */
    public const FIELDS = ['caption', 'description'];

    /**
     * Longest caption kept, in characters. A caption sits under the image on
     * the page, so it stays one sentence.
     *
     * @since 2.21.2
     */
    public const CAPTION_MAX = 160;

    /**
     * Longest description kept, in characters.
     *
     * @since 2.21.2
     */
    public const DESCRIPTION_MAX = 500;

    /**
     * Output cap for an OpenAI-compatible endpoint answering with several
     * fields. Alt text alone fits in 300 tokens; a description and the JSON
     * around it do not.
     *
     * @since 2.21.2
     */
    private const FIELDS_COMPATIBLE_MAX_TOKENS = 1000;

    /**
     * Settings accessor.
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Constructor.
     *
     * @param Settings|null $settings Settings instance.
     */
    public function __construct(?Settings $settings = null) {
        $this->settings = $settings ?? Settings::instance();
    }

    /**
     * Providers that can accept an image, mapped to the models we send.
     *
     * @return array<string, string> provider => default vision model.
     */
    public static function capable_providers(): array {
        return [
            'openai' => 'gpt-5-mini',
            'claude' => 'claude-sonnet-5',
            'gemini' => Settings::DEFAULT_GEMINI_MODEL,
        ];
    }

    /**
     * Whether the configured provider can describe images.
     *
     * @return bool
     */
    public function is_available(): bool {
        $provider = (string) $this->settings->get('ai_provider', Settings::AI_PROVIDER_NONE);

        if ('openai_compatible' === $provider) {
            return $this->compatible_vision_ready();
        }

        return isset(self::capable_providers()[$provider])
            && '' !== $this->api_key_for($provider);
    }

    /**
     * Can the user's own OpenAI-compatible endpoint describe an image?
     *
     * Only the administrator knows: an Ollama box running llama3.1 cannot,
     * the same box running llava can, and there is no reliable way to ask the
     * server. So it is a declared capability (`openai_compatible_supports_images`,
     * default off) rather than a guess — sending a vision payload to a
     * text-only model returns a confusing 400, and AI alt text stays hidden
     * until the user says the model handles images (#721).
     *
     * @since 2.8.0
     *
     * @return bool
     */
    private function compatible_vision_ready(): bool {
        return (bool) $this->settings->get('openai_compatible_supports_images', false)
            && '' !== (string) $this->settings->get('openai_compatible_base_url', '')
            && '' !== trim((string) $this->settings->get('openai_compatible_model', ''));
    }

    /**
     * The stored key for a provider.
     *
     * Keys are held per provider (`openai_api_key`, `claude_api_key`,
     * `gemini_api_key`) — the same names AI_Manager reads. There is no
     * provider-agnostic `ai_api_key` setting, so asking for one always
     * returned '' and made every vision call fall back to the filename
     * template without ever reaching the provider.
     *
     * @param string $provider Provider slug.
     * @return string
     */
    private function api_key_for(string $provider): string {
        return trim((string) $this->settings->get($provider . '_api_key', ''));
    }

    /**
     * Describe an attachment in one short sentence suitable for alt text.
     *
     * @param int    $attachment_id Attachment to describe.
     * @param string $context       Optional context (post title) to disambiguate.
     * @return string Alt text, never empty.
     * @throws \Exception When the provider is unusable, the call fails, or the answer is empty.
     */
    public function describe_attachment(int $attachment_id, string $context = ''): string {
        return $this->finish($this->ask($attachment_id, $this->build_prompt($context)));
    }

    /**
     * Write alt text plus a caption and/or a description in one request.
     *
     * One vision call per image whatever is asked for, so turning on captions
     * does not double the bill (#1058). The model answers with a JSON object
     * and each field is cleaned and capped on its own: the 125-character cut
     * stays on alt text only.
     *
     * @since 2.21.2
     *
     * @param int      $attachment_id Attachment to describe.
     * @param string   $context       Optional context (post title) to disambiguate.
     * @param string[] $fields        Fields wanted besides alt text: any of FIELDS.
     * @return array{alt: string, caption: string, description: string} Alt text is
     *         never empty; a field not asked for, or one the model left out, is ''.
     * @throws \Exception When the provider is unusable, the call fails, the reply is
     *                    not the JSON object asked for, or it holds no alt text.
     */
    public function describe_attachment_fields(int $attachment_id, string $context, array $fields): array {
        $fields = array_values(array_intersect(self::FIELDS, $fields));

        if ([] === $fields) {
            return ['alt' => $this->describe_attachment($attachment_id, $context), 'caption' => '', 'description' => ''];
        }

        return $this->parse_fields(
            $this->ask($attachment_id, $this->build_fields_prompt($context, $fields), self::FIELDS_COMPATIBLE_MAX_TOKENS),
            $fields
        );
    }

    /**
     * Send one image and an instruction to the configured provider.
     *
     * @since 2.21.2 Split out of describe_attachment().
     *
     * @param int    $attachment_id         Attachment to describe.
     * @param string $prompt                Instruction.
     * @param int    $compatible_max_tokens Output cap for an OpenAI-compatible endpoint.
     * @return string The provider's raw answer.
     * @throws \Exception When the provider is unusable or the call fails.
     */
    private function ask(int $attachment_id, string $prompt, int $compatible_max_tokens = 300): string {
        $provider = (string) $this->settings->get('ai_provider', Settings::AI_PROVIDER_NONE);
        $models   = self::capable_providers();

        // Distinguish "no provider chosen" from "this provider can't do vision":
        // interpolating an empty provider name reads as a broken string (#572).
        if (Settings::AI_PROVIDER_NONE === $provider) {
            throw new \Exception(esc_html__(
                'No AI provider is selected. Choose OpenAI, Anthropic or Gemini in Settings → AI Provider.',
                'thinkrank'
            ));
        }

        if ('openai_compatible' === $provider) {
            if (!$this->compatible_vision_ready()) {
                throw new \Exception(esc_html__(
                    'Your OpenAI-compatible endpoint is not set up for images. Set a base URL and model id, and turn on "This model can describe images" in Settings → AI Provider.',
                    'thinkrank'
                ));
            }

            $image = $this->read_image($attachment_id);
            if (null === $image) {
                throw new \Exception(esc_html__('The image file could not be read, or is larger than the provider allows.', 'thinkrank'));
            }

            return $this->call_openai(
                (string) $this->settings->get('openai_compatible_api_key', ''),
                trim((string) $this->settings->get('openai_compatible_model', '')),
                $prompt,
                $image,
                (string) $this->settings->get('openai_compatible_base_url', ''),
                $compatible_max_tokens
            );
        }

        if (!isset($models[$provider])) {
            throw new \Exception(sprintf(
                /* translators: %s: AI provider name. */
                esc_html__('The %s provider cannot describe images. Switch to OpenAI, Anthropic or Gemini in Settings → AI Provider.', 'thinkrank'),
                esc_html($provider)
            ));
        }

        $api_key = $this->api_key_for($provider);
        if ('' === $api_key) {
            throw new \Exception(esc_html__('No AI API key configured.', 'thinkrank'));
        }

        $image = $this->read_image($attachment_id);
        if (null === $image) {
            throw new \Exception(esc_html__('The image file could not be read, or is larger than the provider allows.', 'thinkrank'));
        }

        $model = (string) $this->settings->get('ai_vision_model', $models[$provider]);

        switch ($provider) {
            case 'openai':
                return $this->call_openai($api_key, $model, $prompt, $image);
            case 'claude':
                return $this->call_claude($api_key, $model, $prompt, $image);
            default:
                return $this->call_gemini($api_key, $model, $prompt, $image);
        }
    }

    /**
     * Clean a provider's answer into alt text, failing when nothing is left.
     *
     * An empty answer is a failure, not a description. Returning '' let the
     * caller write the filename template and count it as a success with
     * nothing to say why (#1039).
     *
     * @since 2.19.0
     *
     * @param string $text Raw provider text.
     * @return string Alt text, never empty.
     * @throws \Exception When the answer holds no usable text.
     */
    private function finish(string $text): string {
        $alt = $this->clean($text);

        if ('' === $alt) {
            throw new \Exception(esc_html__('The AI returned no description for this image.', 'thinkrank'));
        }

        return $alt;
    }

    /**
     * The instruction. Alt text has rules — screen readers already announce
     * "image", length is capped by convention, and a description that opens
     * with "an image of" wastes the listener's time.
     *
     * @param string $context Surrounding context, if any.
     * @return string
     */
    private function build_prompt(string $context): string {
        $prompt = "Write alt text for this image.\n\n"
            . "Rules:\n"
            . "- One sentence, under 125 characters.\n"
            . "- Describe what is visibly in the image, factually.\n"
            . "- Do NOT start with \"image of\", \"picture of\" or \"photo of\".\n"
            . "- No quotes, no trailing period, no markdown.\n"
            . "- If the image contains meaningful text, include it.\n"
            . "- Reply with the alt text only.";

        if ('' !== $context) {
            $prompt .= "\n\nThe image appears in content about: " . $context;
        }

        return $prompt;
    }

    /**
     * The instruction for alt text plus a caption and/or a description.
     *
     * Asks for one JSON object so a single reply carries every field. Alt
     * text keeps the rules of build_prompt(); the caption is written for
     * readers of the page, and the description for the attachment page.
     *
     * @since 2.21.2
     *
     * @param string   $context Surrounding context, if any.
     * @param string[] $fields  Fields wanted besides alt text.
     * @return string
     */
    private function build_fields_prompt(string $context, array $fields): string {
        $keys  = ['"alt"'];
        $lines = [
            '- "alt": alt text for screen readers. One sentence, under 125 characters. '
                . 'Describe what is visibly in the image, factually. Do NOT start with "image of", '
                . '"picture of" or "photo of". No trailing period.',
        ];

        if (in_array('caption', $fields, true)) {
            $keys[]  = '"caption"';
            $lines[] = '- "caption": a caption printed under the image for readers of the page. '
                . 'One sentence, under ' . self::CAPTION_MAX . ' characters. Do not repeat the alt text word for word.';
        }

        if (in_array('description', $fields, true)) {
            $keys[]  = '"description"';
            $lines[] = '- "description": a fuller description of the image for its attachment page. '
                . 'Two or three sentences, under ' . self::DESCRIPTION_MAX . ' characters.';
        }

        $prompt = "Describe this image for a website's media library.\n\n"
            . 'Reply with one JSON object and nothing else, with the keys ' . implode(', ', $keys) . ":\n"
            . implode("\n", $lines) . "\n\n"
            . "Rules for every value:\n"
            . "- Plain text: no markdown, no HTML, no surrounding quotes.\n"
            . "- Describe only what is in the image; do not invent names, places or brands.\n"
            . '- If the image contains meaningful text, include it.';

        if ('' !== $context) {
            $prompt .= "\n\nThe image appears in content about: " . $context;
        }

        return $prompt;
    }

    /**
     * Read the JSON reply of build_fields_prompt() into cleaned fields.
     *
     * A reply that is not the object asked for is a failure rather than alt
     * text: writing a JSON fragment, or a whole paragraph, as alt text would
     * be worse than the template fallback the caller has.
     *
     * @since 2.21.2
     *
     * @param string   $text   Raw provider text.
     * @param string[] $fields Fields asked for besides alt text.
     * @return array{alt: string, caption: string, description: string}
     * @throws \Exception When the reply is not a JSON object, or holds no alt text.
     */
    private function parse_fields(string $text, array $fields): array {
        $decoded = Ai_Json::decode_object($text);

        if (null === $decoded) {
            throw new \Exception(esc_html__('The AI reply was not in the expected format, so nothing was written for this image.', 'thinkrank'));
        }

        $value = static function (string $key) use ($decoded): string {
            return is_string($decoded[$key] ?? null) ? $decoded[$key] : '';
        };

        return [
            'alt'         => $this->finish($value('alt')),
            'caption'     => in_array('caption', $fields, true) ? $this->clean_field($value('caption'), self::CAPTION_MAX) : '',
            'description' => in_array('description', $fields, true) ? $this->clean_field($value('description'), self::DESCRIPTION_MAX) : '',
        ];
    }

    /**
     * Normalize a caption or description, cut on a word boundary at $max.
     *
     * Same clean-up as clean(), minus what is specific to alt text: the
     * closing period stays, since a caption or a description is prose, and
     * the length cap is the field's own.
     *
     * @since 2.21.2
     *
     * @param string $text Raw value.
     * @param int    $max  Longest value kept, in characters.
     * @return string
     */
    private function clean_field(string $text, int $max): string {
        $text = trim(wp_strip_all_tags(Ai_Json::strip_reasoning($text)));
        $text = trim($text, "\"' \t\n\r");
        $text = (string) preg_replace('/^(an?\s+)?(image|picture|photo|photograph|screenshot)\s+(of|showing|depicting)\s+/i', '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ('' === $text) {
            return '';
        }

        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);

            // Prefer ending on a whole sentence, then on a whole word.
            $stop = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));
            if ($stop > (int) ($max / 2)) {
                $cut = mb_substr($cut, 0, $stop + 1);
            } else {
                $space = mb_strrpos($cut, ' ');
                if (false !== $space && $space > (int) ($max / 2)) {
                    $cut = mb_substr($cut, 0, $space);
                }
                $cut = rtrim($cut, ' ,;:-');
            }

            $text = $cut;
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Load an attachment as base64, preferring a scaled size.
     *
     * @param int $attachment_id Attachment ID.
     * @return array{data: string, mime: string}|null
     */
    private function read_image(int $attachment_id): ?array {
        $path = $this->resolve_path($attachment_id);
        if (null === $path) {
            return null;
        }

        if (!is_readable($path)) {
            return null;
        }

        $bytes = filesize($path);
        if (false === $bytes || $bytes > self::MAX_IMAGE_BYTES) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local upload, not a remote fetch.
        $contents = file_get_contents($path);
        if (false === $contents || '' === $contents) {
            return null;
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the multimodal request bodies of OpenAI, Anthropic and Gemini all carry image bytes as base64.
        return ['data' => base64_encode($contents), 'mime' => $mime];
    }

    /**
     * Absolute path to the smallest adequate version of an attachment.
     *
     * @param int $attachment_id Attachment ID.
     * @return string|null
     */
    private function resolve_path(int $attachment_id): ?string {
        $meta     = wp_get_attachment_metadata($attachment_id);
        $original = get_attached_file($attachment_id);

        if (is_array($meta) && !empty($meta['sizes']) && is_string($original)) {
            $dir = dirname($original);
            foreach (self::PREFERRED_SIZES as $size) {
                if (!empty($meta['sizes'][$size]['file'])) {
                    $candidate = $dir . '/' . $meta['sizes'][$size]['file'];
                    if (file_exists($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return (is_string($original) && file_exists($original)) ? $original : null;
    }

    /**
     * OpenAI: typed content parts with a data: URL.
     *
     * @param string $api_key API key.
     * @param string $model   Model id.
     * @param string $prompt  Instruction.
     * @param array  $image    ['data' => base64, 'mime' => string].
     * @param string $base_url Base URL, for an OpenAI-compatible endpoint that is not OpenAI's.
     * @param int    $compatible_max_tokens Output cap for an endpoint that is not OpenAI's.
     * @return string
     * @throws \Exception On API failure.
     */
    private function call_openai(string $api_key, string $model, string $prompt, array $image, string $base_url = OpenAI_Client::API_BASE_URL, int $compatible_max_tokens = 300): string {
        $body = [
            'model'    => $model,
            'messages' => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    [
                        'type'      => 'image_url',
                        'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . $image['data']],
                    ],
                ],
            ]],
            // Reasoning-safe ceiling, and minimal effort: alt text needs
            // observation, not deliberation (see 6094d9d).
            'max_completion_tokens' => 4000,
        ];

        if (0 === strpos($model, 'gpt-5')) {
            $body['reasoning_effort'] = 'minimal';
        }

        $base_url = rtrim(trim($base_url), '/');
        if ('' === $base_url) {
            $base_url = OpenAI_Client::API_BASE_URL;
        }

        // Ollama, LM Studio and vLLM reject max_completion_tokens — it is a
        // parameter OpenAI added for its reasoning models, not part of the
        // Chat Completions shape they implement — so a custom endpoint gets
        // plain max_tokens.
        if (OpenAI_Client::API_BASE_URL !== $base_url) {
            unset($body['max_completion_tokens'], $body['reasoning_effort']);
            $body['max_tokens'] = $compatible_max_tokens;
        }

        $headers = ['Content-Type' => 'application/json'];
        // A local server usually wants no key at all.
        if ('' !== $api_key) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
            $headers['api-key']       = $api_key;
        }

        $response = $this->post(
            Endpoint_URL_Validator::route($base_url, 'chat/completions'),
            $headers,
            $body,
            OpenAI_Client::API_BASE_URL !== $base_url
        );

        return Chat_Content::text($response['choices'][0]['message']['content'] ?? null);
    }

    /**
     * Anthropic: base64 `source` block, and the API version header.
     *
     * @param string $api_key API key.
     * @param string $model   Model id.
     * @param string $prompt  Instruction.
     * @param array  $image   ['data' => base64, 'mime' => string].
     * @return string
     * @throws \Exception On API failure.
     */
    private function call_claude(string $api_key, string $model, string $prompt, array $image): string {
        $response = $this->post('https://api.anthropic.com/v1/messages', [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ], [
            'model'      => $model,
            // The default vision model (Sonnet 5) thinks by default, and its
            // thinking counts against this cap. 300 could all go on thinking
            // and leave no text block (#1039); 2000 matches the Gemini call
            // below, which needed the same room for the same reason. It is a
            // ceiling only: a one-line description costs no more.
            'max_tokens' => 2000,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $image['mime'],
                            'data'       => $image['data'],
                        ],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ]);

        return $this->claude_text($response);
    }

    /**
     * The description in a Claude reply, or an exception that says why there is none.
     *
     * A model that thinks first puts a `thinking` block at content[0] and its
     * answer after it, so reading content[0]['text'] returned '' for a valid
     * reply, and the alt text silently fell back to the filename (#1039). The
     * text blocks are joined in order; every other block type is skipped.
     *
     * @since 2.19.0
     *
     * @param array $response Decoded Messages API response.
     * @return string Description text ('' only when the reply ended normally with none).
     * @throws \Exception On a refusal, or a reply cut off at max_tokens.
     */
    private function claude_text(array $response): string {
        $stop_reason = (string) ($response['stop_reason'] ?? '');

        if ('refusal' === $stop_reason) {
            throw new \Exception(esc_html__('The AI declined to describe this image.', 'thinkrank'));
        }

        $text = '';
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (!is_array($block) || !isset($block['text']) || !is_string($block['text'])) {
                continue;
            }
            if ('text' !== (string) ($block['type'] ?? 'text')) {
                continue;
            }
            $text .= $block['text'];
        }

        if ('' === trim($text) && 'max_tokens' === $stop_reason) {
            throw new \Exception(esc_html__('The AI ran out of output tokens before it described the image.', 'thinkrank'));
        }

        return $text;
    }

    /**
     * Gemini: `inline_data` part, key on the query string.
     *
     * @param string $api_key API key.
     * @param string $model   Model id.
     * @param string $prompt  Instruction.
     * @param array  $image   ['data' => base64, 'mime' => string].
     * @return string
     * @throws \Exception On API failure.
     */
    private function call_gemini(string $api_key, string $model, string $prompt, array $image): string {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model),
            rawurlencode($api_key)
        );

        $response = $this->post($url, ['Content-Type' => 'application/json'], [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['data']]],
                ],
            ]],
            // Gemini 2.5 spends output tokens on reasoning before emitting
            // text, so a tight cap returns an empty candidate.
            'generationConfig' => ['maxOutputTokens' => 2000, 'temperature' => 0.2],
        ]);

        return (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    /**
     * Shared POST + error mapping.
     *
     * @param string $url     Endpoint.
     * @param array  $headers Headers.
     * @param array  $body    Payload.
     * @param bool   $guarded Whether this is a user-named endpoint, which has its
     *                        destination resolved, pinned and its body capped.
     * @return array Decoded response.
     * @throws \Exception On transport or API error.
     */
    private function post(string $url, array $headers, array $body, bool $guarded = false): array {
        // The user's daily ceiling and kill switch are enforced here, at the
        // one place every outbound vision call passes through, so no feature
        // path can bypass them by forgetting to ask first (#448).
        Spend_Guard::guard();
        Spend_Guard::record();

        $args = [
            // Vision calls carry a payload and think for a moment; the 30s
            // default is too tight for a large image on a slow link. A local
            // vision model is slower still, so honour the user's own timeout
            // when they configured one (#721).
            'timeout' => $this->request_timeout(),
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            // The key rides in a header; never let a redirect hand it to
            // another host.
            'redirection' => 0,
        ];

        $response = $guarded
            ? Endpoint_URL_Validator::guarded_request($url, $args + ['method' => 'POST'])
            : wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);
        $data   = json_decode($raw, true);

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ($data['error']['status'] ?? 'Unknown API error');
            throw new \Exception(esc_html(sprintf('Vision API error (%d): %s', $status, (string) $message)));
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Timeout for a vision request.
     *
     * 60s for the hosted providers. A local vision model on CPU is slower than
     * anything hosted, so an OpenAI-compatible endpoint gets the timeout the
     * administrator configured for it, floored at 60 (#721).
     *
     * @since 2.8.0
     *
     * @return int Seconds.
     */
    private function request_timeout(): int {
        if ('openai_compatible' !== (string) $this->settings->get('ai_provider', Settings::AI_PROVIDER_NONE)) {
            return 60;
        }

        return max(60, (int) $this->settings->get('openai_compatible_timeout', Settings::DEFAULT_OPENAI_COMPATIBLE_TIMEOUT));
    }

    /**
     * Normalize a model's reply into usable alt text.
     *
     * Models still add wrappers despite instructions, so strip them here rather
     * than storing "Image of a red bicycle." on the attachment forever.
     *
     * @param string $text Raw model output.
     * @return string
     */
    private function clean(string $text): string {
        // An open reasoning model on a compatible server can put its reasoning
        // inline as <think>...</think>. wp_strip_all_tags() drops the tags but
        // keeps the reasoning, which then became the alt text (#1044).
        $text = trim(wp_strip_all_tags(Ai_Json::strip_reasoning($text)));
        $text = trim($text, "\"' \t\n\r");
        $text = (string) preg_replace('/^(an?\s+)?(image|picture|photo|photograph|screenshot)\s+(of|showing|depicting)\s+/i', '', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = rtrim($text, '.');

        if ('' === $text) {
            return '';
        }

        // Alt text longer than ~125 chars is read as noise by screen readers;
        // cut on a word boundary rather than mid-syllable.
        if (mb_strlen($text) > 125) {
            $text = mb_substr($text, 0, 125);
            $space = mb_strrpos($text, ' ');
            if (false !== $space && $space > 60) {
                $text = mb_substr($text, 0, $space);
            }
        }

        // ucfirst() is not multibyte-safe: on alt text starting with an
        // accented character ("Éclair…") it corrupts the leading byte.
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
