<?php
/**
 * The AI crawler registry — one list, two consumers.
 *
 * `AI_Traffic_Tracker` classifies an inbound user agent against these tokens
 * to record crawler hits; the Robots.txt panel turns the same list into
 * per-agent allow/block directives. The list lived as a private constant on
 * the tracker with no accessor and no filter, so the robots side had no way to
 * read it — and a second hardcoded copy is how the two drift into disagreeing
 * about which bots exist (#657).
 *
 * @package ThinkRank\SEO
 * @since 2.5.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Known AI crawlers, their vendors, and what each one is for.
 */
class AI_Crawlers {

    /**
     * Vendor slug → display name, in the order the UI groups them.
     *
     * @var array<string, string>
     */
    private const VENDORS = [
        'openai'     => 'OpenAI',
        'anthropic'  => 'Anthropic',
        'google'     => 'Google',
        'perplexity' => 'Perplexity',
        'meta'       => 'Meta',
        'apple'      => 'Apple',
        'other'      => 'Other',
    ];

    /**
     * Crawler slug → definition.
     *
     * ORDER IS SIGNIFICANT. classify_crawler() takes the first token that
     * appears anywhere in the user agent, so a token containing another must
     * come first: `Claude-SearchBot` and `Claude-User` both contain
     * `ClaudeBot`'s prefix, and `ChatGPT-User` would otherwise never be
     * distinguished. The tracker relied on this ordering while the list was
     * its own constant, and moving the list must not quietly lose it.
     *
     * `token` is matched case-insensitively as a substring of the user agent,
     * and is also the exact string written after `User-agent:` in robots.txt —
     * the two have to be the same value or a site would block a bot it is not
     * detecting, and vice versa.
     *
     * @var array<string, array{token: string, label: string, vendor: string, purpose: string}>
     */
    private const AGENTS = [
        'oai-searchbot' => [
            'token'   => 'OAI-SearchBot',
            'label'   => 'OAI-SearchBot',
            'vendor'  => 'openai',
            'purpose' => 'Indexes pages so they can be surfaced and linked in ChatGPT search results.',
        ],
        'chatgpt-user' => [
            'token'   => 'ChatGPT-User',
            'label'   => 'ChatGPT-User',
            'vendor'  => 'openai',
            'purpose' => 'Fetches a page live when a ChatGPT user follows or asks about that specific link.',
        ],
        'gptbot' => [
            'token'   => 'GPTBot',
            'label'   => 'GPTBot',
            'vendor'  => 'openai',
            'purpose' => 'Collects page content to train OpenAI models.',
        ],
        'perplexity-user' => [
            'token'   => 'Perplexity-User',
            'label'   => 'Perplexity-User',
            'vendor'  => 'perplexity',
            'purpose' => 'Fetches a page live in response to a Perplexity user asking about it.',
        ],
        'perplexitybot' => [
            'token'   => 'PerplexityBot',
            'label'   => 'PerplexityBot',
            'vendor'  => 'perplexity',
            'purpose' => 'Indexes pages so Perplexity can cite them in answers.',
        ],
        'claude-searchbot' => [
            'token'   => 'Claude-SearchBot',
            'label'   => 'Claude-SearchBot',
            'vendor'  => 'anthropic',
            'purpose' => 'Indexes pages so Claude can cite them when answering with search.',
        ],
        'claude-user' => [
            'token'   => 'Claude-User',
            'label'   => 'Claude-User',
            'vendor'  => 'anthropic',
            'purpose' => 'Fetches a page live when a Claude user asks about that specific link.',
        ],
        'claudebot' => [
            'token'   => 'ClaudeBot',
            'label'   => 'ClaudeBot',
            'vendor'  => 'anthropic',
            'purpose' => 'Collects page content to train Anthropic models.',
        ],
        'anthropic-ai' => [
            'token'   => 'anthropic-ai',
            'label'   => 'anthropic-ai',
            'vendor'  => 'anthropic',
            'purpose' => 'Anthropic’s earlier crawler token, still seen in the wild.',
        ],
        'google-extended' => [
            'token'   => 'Google-Extended',
            'label'   => 'Google-Extended',
            'vendor'  => 'google',
            'purpose' => 'Controls whether your content trains Gemini and grounds its answers. Blocking it does NOT affect normal Google Search indexing or your rankings.',
        ],
        'applebot-extended' => [
            'token'   => 'Applebot-Extended',
            'label'   => 'Applebot-Extended',
            'vendor'  => 'apple',
            'purpose' => 'Controls whether your content trains Apple Intelligence. Blocking it does not affect Siri or Spotlight search results.',
        ],
        'meta-externalagent' => [
            'token'   => 'meta-externalagent',
            'label'   => 'meta-externalagent',
            'vendor'  => 'meta',
            'purpose' => 'Collects page content to train Meta AI.',
        ],
        'meta-externalfetcher' => [
            'token'   => 'meta-externalfetcher',
            'label'   => 'meta-externalfetcher',
            'vendor'  => 'meta',
            'purpose' => 'Fetches a page live in response to a Meta AI user asking about it.',
        ],
        'bytespider' => [
            'token'   => 'Bytespider',
            'label'   => 'Bytespider',
            'vendor'  => 'other',
            'purpose' => 'ByteDance’s crawler, collecting content to train its models.',
        ],
        'amazonbot' => [
            'token'   => 'Amazonbot',
            'label'   => 'Amazonbot',
            'vendor'  => 'other',
            'purpose' => 'Amazon’s crawler, feeding Alexa answers and Amazon’s AI products.',
        ],
        'ccbot' => [
            'token'   => 'CCBot',
            'label'   => 'CCBot',
            'vendor'  => 'other',
            'purpose' => 'Common Crawl’s crawler. Its public archive is a training source for many AI models, so blocking it reaches more than one company.',
        ],
        'cohere-ai' => [
            'token'   => 'cohere-ai',
            'label'   => 'cohere-ai',
            'vendor'  => 'other',
            'purpose' => 'Cohere’s crawler, collecting content for its models.',
        ],
        'mistral-user' => [
            'token'   => 'MistralAI-User',
            'label'   => 'MistralAI-User',
            'vendor'  => 'other',
            'purpose' => 'Fetches a page live in response to a Le Chat user asking about it.',
        ],
    ];

    /**
     * Every known crawler, keyed by slug.
     *
     * Filterable so a site can add a crawler that shipped after this release
     * without editing the plugin. A filtered entry is normalised and anything
     * without a usable token is dropped: a malformed entry reaching robots.txt
     * would write a `User-agent:` line with no agent on it, which changes the
     * meaning of the group that follows.
     *
     * @since 2.5.0
     *
     * @return array<string, array{token: string, label: string, vendor: string, purpose: string}>
     */
    public static function all(): array {
        /**
         * Filter the known AI crawler registry.
         *
         * Order is significant: classification takes the first token that
         * matches, so a token that contains another must come first.
         *
         * @since 2.5.0
         *
         * @param array<string, array{token: string, label: string, vendor: string, purpose: string}> $agents Registry keyed by slug.
         */
        $agents = apply_filters('thinkrank_ai_crawlers', self::AGENTS);

        if (!is_array($agents)) {
            return self::AGENTS;
        }

        $normalised = [];

        foreach ($agents as $slug => $agent) {
            $slug = sanitize_key((string) $slug);

            if ('' === $slug || !is_array($agent)) {
                continue;
            }

            $token = trim((string) ($agent['token'] ?? ''));

            // A token with whitespace or a colon in it cannot be written after
            // `User-agent:` without breaking the record it opens.
            if ('' === $token || preg_match('/[\s:]/', $token)) {
                continue;
            }

            $vendor = (string) ($agent['vendor'] ?? 'other');

            $normalised[$slug] = [
                'token'   => $token,
                'label'   => trim((string) ($agent['label'] ?? '')) ?: $token,
                'vendor'  => isset(self::VENDORS[$vendor]) ? $vendor : 'other',
                'purpose' => (string) ($agent['purpose'] ?? ''),
            ];
        }

        return $normalised ?: self::AGENTS;
    }

    /**
     * User-agent token → crawler slug, in match order.
     *
     * The shape `AI_Traffic_Tracker::classify_crawler()` walks.
     *
     * @since 2.5.0
     *
     * @return array<string, string>
     */
    public static function token_map(): array {
        $map = [];

        foreach (self::all() as $slug => $agent) {
            $map[$agent['token']] = $slug;
        }

        return $map;
    }

    /**
     * Vendor slug → display name, including any vendor only a filter knows.
     *
     * @since 2.5.0
     *
     * @return array<string, string>
     */
    public static function vendors(): array {
        return self::VENDORS;
    }

    /**
     * Is this a crawler slug the registry knows?
     *
     * @since 2.5.0
     *
     * @param string $slug Crawler slug.
     * @return bool
     */
    public static function exists(string $slug): bool {
        return isset(self::all()[$slug]);
    }

    /**
     * The registry as the admin screen and the REST API consume it: a flat,
     * ordered list carrying the slug, so JSON preserves the grouping order.
     *
     * @since 2.5.0
     *
     * @return array<int, array{slug: string, token: string, label: string, vendor: string, vendor_label: string, purpose: string}>
     */
    public static function for_display(): array {
        $vendors = self::vendors();
        $list    = [];

        foreach (self::all() as $slug => $agent) {
            $list[] = [
                'slug'         => $slug,
                'token'        => $agent['token'],
                'label'        => $agent['label'],
                'vendor'       => $agent['vendor'],
                'vendor_label' => $vendors[$agent['vendor']] ?? $vendors['other'],
                'purpose'      => $agent['purpose'],
            ];
        }

        return $list;
    }

    /**
     * Normalise a stored/posted rule map to `slug => 'allow'|'block'`.
     *
     * Unknown slugs are dropped rather than stored: a slug with no agent
     * behind it can never emit a directive, but it would round-trip through
     * every settings response forever. Anything that is not the string
     * `block` reads as allow, so a half-written payload defaults to the
     * permissive answer rather than silently blocking a crawler.
     *
     * @since 2.5.0
     *
     * @param mixed $rules Raw rule map.
     * @return array<string, string> Normalised rules.
     */
    public static function normalize_rules($rules): array {
        if (!is_array($rules)) {
            return [];
        }

        $known      = self::all();
        $normalised = [];

        foreach ($rules as $slug => $rule) {
            $slug = sanitize_key((string) $slug);

            if (!isset($known[$slug])) {
                continue;
            }

            $normalised[$slug] = ('block' === strtolower(trim((string) $rule))) ? 'block' : 'allow';
        }

        return $normalised;
    }

    /**
     * The slugs currently set to block, in registry order.
     *
     * Registry order rather than the stored map's order, so the robots.txt
     * block is stable between saves and a diff of the served file shows only
     * real changes.
     *
     * @since 2.5.0
     *
     * @param mixed $rules Stored rule map.
     * @return string[] Blocked crawler slugs.
     */
    public static function blocked_slugs($rules): array {
        $rules   = self::normalize_rules($rules);
        $blocked = [];

        foreach (array_keys(self::all()) as $slug) {
            if (($rules[$slug] ?? 'allow') === 'block') {
                $blocked[] = $slug;
            }
        }

        return $blocked;
    }
}
