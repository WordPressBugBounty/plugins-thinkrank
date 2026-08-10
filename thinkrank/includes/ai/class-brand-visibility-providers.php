<?php
/**
 * Brand Visibility v2 — per-platform provider resolution.
 *
 * Measuring visibility means asking SEVERAL assistants the same question, so
 * this feature can't be chained to the single site-wide AI provider the rest
 * of ThinkRank uses. Each platform therefore gets its own optional key
 * (`bv_key_<platform>`), falling back to the main provider key when the two
 * happen to be the same vendor. A platform with no usable key is simply not
 * offered in the wizard.
 *
 * Grounding honesty: plain Chat Completions answers reflect the model's
 * training data, while the consumer apps people actually use often browse the
 * live web. Perplexity's API is search-grounded; the others are not, and the
 * UI labels them accordingly rather than implying all four measure the same
 * thing.
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
 * Resolves platform slugs to configured AI clients.
 */
final class Brand_Visibility_Providers {

    /**
     * Supported platforms.
     *
     * `grounded` drives the UI's honesty label. `main_provider` names the
     * site-wide provider whose key this platform can borrow.
     */
    public const PLATFORMS = [
        'chatgpt' => [
            'label'         => 'ChatGPT',
            'vendor'        => 'openai',
            'main_provider' => 'openai',
            'grounded'      => false,
            'default_model' => 'gpt-5-nano',
        ],
        'gemini' => [
            'label'         => 'Gemini',
            'vendor'        => 'gemini',
            'main_provider' => 'gemini',
            'grounded'      => false,
            'default_model' => Settings::DEFAULT_GEMINI_MODEL,
        ],
        'claude' => [
            'label'         => 'Claude',
            'vendor'        => 'claude',
            'main_provider' => 'claude',
            'grounded'      => false,
            'default_model' => Settings::DEFAULT_CLAUDE_MODEL,
        ],
        'perplexity' => [
            'label'         => 'Perplexity',
            'vendor'        => 'perplexity',
            'main_provider' => '',   // no site-wide equivalent — needs its own key
            'grounded'      => true,
            'default_model' => Perplexity_Client::DEFAULT_MODEL,
        ],
    ];

    /**
     * Selectable models per platform.
     *
     * Deliberately a short, curated list rather than the vendor's full catalogue:
     * Brand Visibility fires queries × platforms × samples calls per run, so the
     * cheap/fast tiers are listed first and are the defaults. The whitelist also
     * doubles as save-time validation — an unknown id is rejected rather than
     * stored and failing later inside a queued run.
     *
     * @var array<string, array<int, array{value: string, label: string}>>
     */
    public const MODEL_CHOICES = [
        'chatgpt' => [
            ['value' => 'gpt-5-nano', 'label' => 'GPT-5 nano (fastest, lowest cost)'],
            ['value' => 'gpt-5-mini', 'label' => 'GPT-5 mini (balanced)'],
            ['value' => 'gpt-5', 'label' => 'GPT-5 (highest quality)'],
            ['value' => 'gpt-4o', 'label' => 'GPT-4o (compatibility)'],
        ],
        'gemini' => [
            ['value' => 'gemini-3.5-flash', 'label' => 'Gemini 3.5 Flash (recommended)'],
            ['value' => 'gemini-3.1-flash-lite', 'label' => 'Gemini 3.1 Flash Lite (fastest)'],
            ['value' => 'gemini-3.1-pro', 'label' => 'Gemini 3.1 Pro'],
            ['value' => 'gemini-2.5-pro', 'label' => 'Gemini 2.5 Pro'],
        ],
        'claude' => [
            ['value' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5 (fastest)'],
            ['value' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5 (recommended)'],
            ['value' => 'claude-opus-4-8', 'label' => 'Claude Opus 4.8 (most capable)'],
        ],
        'perplexity' => [
            ['value' => 'sonar', 'label' => 'Sonar (search-grounded)'],
            ['value' => 'sonar-pro', 'label' => 'Sonar Pro (deeper search)'],
            ['value' => 'sonar-reasoning', 'label' => 'Sonar Reasoning'],
        ],
    ];

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
     * Option key holding a platform's dedicated API key.
     *
     * @param string $platform Platform slug.
     * @return string
     */
    public static function key_option(string $platform): string {
        return 'bv_key_' . $platform;
    }

    /**
     * Option key holding a platform's chosen model.
     *
     * @param string $platform Platform slug.
     * @return string
     */
    public static function model_option(string $platform): string {
        return 'bv_model_' . $platform;
    }

    /**
     * Whether a model id is offered for a platform.
     *
     * @param string $platform Platform slug.
     * @param string $model    Model id.
     * @return bool
     */
    public static function is_valid_model(string $platform, string $model): bool {
        foreach (self::MODEL_CHOICES[$platform] ?? [] as $choice) {
            if ($choice['value'] === $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * The model a platform will actually use — its saved choice, else the
     * platform default. A stored id that is no longer offered falls back to the
     * default rather than failing mid-run.
     *
     * @param string $platform Platform slug.
     * @return string Empty when the platform is unknown.
     */
    public function model_for(string $platform): string {
        $config = self::PLATFORMS[$platform] ?? null;
        if (null === $config) {
            return '';
        }

        $saved = trim((string) $this->settings->get(self::model_option($platform), ''));

        return self::is_valid_model($platform, $saved) ? $saved : (string) $config['default_model'];
    }

    /**
     * Effective model for every platform, for the wizard's model pickers.
     *
     * @return array<string, string>
     */
    public function models(): array {
        $out = [];

        foreach (array_keys(self::PLATFORMS) as $slug) {
            $out[$slug] = $this->model_for($slug);
        }

        return $out;
    }

    /**
     * The API key to use for a platform: its own, else the site-wide key when
     * the main provider is the same vendor.
     *
     * @param string $platform Platform slug.
     * @return string Empty when the platform is unusable.
     */
    public function api_key_for(string $platform): string {
        $config = self::PLATFORMS[$platform] ?? null;
        if (null === $config) {
            return '';
        }

        $own = trim((string) $this->settings->get(self::key_option($platform), ''));
        if ('' !== $own) {
            return $own;
        }

        // Borrow the site-wide key only when it belongs to the same vendor —
        // an OpenAI key cannot talk to Anthropic.
        $main_provider = (string) $this->settings->get('ai_provider', '');
        if ('' !== $config['main_provider'] && $main_provider === $config['main_provider']) {
            return trim((string) $this->settings->get('ai_api_key', ''));
        }

        return '';
    }

    /**
     * Platforms with a usable key, for the wizard's platform picker.
     *
     * @return array<int, array{slug: string, label: string, grounded: bool, available: bool, uses_shared_key: bool}>
     */
    public function available_platforms(): array {
        $out = [];

        foreach (self::PLATFORMS as $slug => $config) {
            $own_key = '' !== trim((string) $this->settings->get(self::key_option($slug), ''));

            $out[] = [
                'slug'            => $slug,
                'label'           => $config['label'],
                'grounded'        => (bool) $config['grounded'],
                'available'       => '' !== $this->api_key_for($slug),
                'uses_shared_key' => !$own_key && '' !== $this->api_key_for($slug),
            ];
        }

        return $out;
    }

    /**
     * Build a client for a platform.
     *
     * @param string $platform Platform slug.
     * @return object A client exposing generate_completion(string, array): array.
     * @throws \Exception When the platform is unknown or has no key.
     */
    public function client_for(string $platform) {
        $config = self::PLATFORMS[$platform] ?? null;
        if (null === $config) {
            throw new \Exception(sprintf('Unknown Brand Visibility platform: %s', esc_html($platform)));
        }

        $api_key = $this->api_key_for($platform);
        if ('' === $api_key) {
            throw new \Exception(sprintf(
                /* translators: %s: platform label, e.g. Claude. */
                esc_html__('No API key configured for %s. Add one under Brand Visibility settings.', 'thinkrank'),
                esc_html($config['label'])
            ));
        }

        $model = $this->model_for($platform);

        switch ($config['vendor']) {
            case 'openai':
                return '' !== $model
                    ? new OpenAI_Client($api_key, $model)
                    : new OpenAI_Client($api_key);
            case 'claude':
                return '' !== $model
                    ? new Claude_Client($api_key, $model)
                    : new Claude_Client($api_key);
            case 'gemini':
                return '' !== $model
                    ? new Gemini_Client($api_key, $model)
                    : new Gemini_Client($api_key);
            case 'perplexity':
                return new Perplexity_Client($api_key, $model);
        }

        throw new \Exception(sprintf('Unsupported vendor for platform: %s', esc_html($platform)));
    }
}
