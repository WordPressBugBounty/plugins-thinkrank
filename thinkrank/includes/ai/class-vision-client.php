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
            'gemini' => 'gemini-2.5-flash',
        ];
    }

    /**
     * Whether the configured provider can describe images.
     *
     * @return bool
     */
    public function is_available(): bool {
        $provider = (string) $this->settings->get('ai_provider', 'openai');

        return isset(self::capable_providers()[$provider])
            && '' !== $this->api_key_for($provider);
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
     * @return string Alt text, or '' when it could not be produced.
     * @throws \Exception When the provider is unusable or the call fails.
     */
    public function describe_attachment(int $attachment_id, string $context = ''): string {
        $provider = (string) $this->settings->get('ai_provider', 'openai');
        $models   = self::capable_providers();

        if (!isset($models[$provider])) {
            throw new \Exception(sprintf(
                /* translators: %s: AI provider name. */
                esc_html__('The %s provider cannot describe images. Switch to OpenAI, Claude or Gemini in Settings → AI Provider.', 'thinkrank'),
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

        $model  = (string) $this->settings->get('ai_vision_model', $models[$provider]);
        $prompt = $this->build_prompt($context);

        switch ($provider) {
            case 'openai':
                $text = $this->call_openai($api_key, $model, $prompt, $image);
                break;
            case 'claude':
                $text = $this->call_claude($api_key, $model, $prompt, $image);
                break;
            default:
                $text = $this->call_gemini($api_key, $model, $prompt, $image);
                break;
        }

        return $this->clean($text);
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
     * @param array  $image   ['data' => base64, 'mime' => string].
     * @return string
     * @throws \Exception On API failure.
     */
    private function call_openai(string $api_key, string $model, string $prompt, array $image): string {
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

        $response = $this->post('https://api.openai.com/v1/chat/completions', [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ], $body);

        return (string) ($response['choices'][0]['message']['content'] ?? '');
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
            'max_tokens' => 300,
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

        return (string) ($response['content'][0]['text'] ?? '');
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
     * @return array Decoded response.
     * @throws \Exception On transport or API error.
     */
    private function post(string $url, array $headers, array $body): array {
        $response = wp_remote_post($url, [
            // Vision calls carry a payload and think for a moment; the 30s
            // default is too tight for a large image on a slow link.
            'timeout' => 60,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);
        $data   = json_decode($raw, true);

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ($data['error']['status'] ?? 'Unknown API error');
            throw new \Exception(sprintf('Vision API error (%d): %s', $status, esc_html((string) $message)));
        }

        return is_array($data) ? $data : [];
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
        $text = trim(wp_strip_all_tags($text));
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
