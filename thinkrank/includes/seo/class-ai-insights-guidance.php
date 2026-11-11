<?php
/**
 * Guidance card state for AI Insights.
 *
 * @package ThinkRank\SEO
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\SEO;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Insights guidance
 *
 * The cards under the AI Traffic dashboard each state where the site stands
 * on one control that affects AI visibility, and link to it (#734). Every
 * value here is read from the setting the card links to, so a card can never
 * describe a state its own screen does not show.
 *
 * Deliberately factual. Nothing here promises citations or estimates exposure:
 * whether an assistant cites a page is not something a WordPress plugin can
 * measure, and the claims policy keeps it off the screen.
 *
 * The Markdown for AI card is a Pro slot, not a value here — only Pro knows
 * whether that feature is on.
 *
 * @since 2.21.3
 */
final class Ai_Insights_Guidance {

    /**
     * Current state for every card.
     *
     * @return array{
     *     crawlers: array{total:int, allowed:int, blocked:int, blocked_slugs:string[]},
     *     llms_txt: array{published:bool, updated_at:int|null, url:string}
     * }
     */
    public static function state(): array {
        $rules = [];
        if (class_exists(Site_Identity_Manager::class)) {
            $site  = (new Site_Identity_Manager())->get_settings('site');
            $rules = is_array($site['ai_crawler_rules'] ?? null) ? $site['ai_crawler_rules'] : [];
        }

        $published  = false;
        $updated_at = null;
        if (class_exists(LLMs_Txt_Manager::class)) {
            $llms       = new LLMs_Txt_Manager();
            $published  = $llms->is_published();
            $updated_at = $published ? $llms->last_published_at() : null;
        }

        return self::build(
            $rules,
            class_exists(AI_Crawlers::class) ? array_keys(AI_Crawlers::all()) : [],
            $published,
            $updated_at
        );
    }

    /**
     * Shape the card state from already-read values.
     *
     * Kept apart from state() so the rules are testable without WordPress.
     *
     * @param mixed    $rules         Stored `ai_crawler_rules` map.
     * @param string[] $known_slugs   Every crawler the registry knows.
     * @param bool     $published     Whether llms.txt is served.
     * @param int|null $updated_at    When it was last written.
     * @return array<string,array<string,mixed>>
     */
    public static function build($rules, array $known_slugs, bool $published, ?int $updated_at): array {
        $rules = is_array($rules) ? $rules : [];

        // An empty map means every crawler is allowed (the permissive default
        // from #657), and anything that is not the string `block` reads as
        // allow — the same reading AI_Crawlers::normalize_rules() applies to
        // the robots.txt it writes.
        $blocked = [];
        foreach ($known_slugs as $slug) {
            if ('block' === strtolower(trim((string) ($rules[$slug] ?? 'allow')))) {
                $blocked[] = $slug;
            }
        }

        $total = count($known_slugs);

        return [
            'crawlers' => [
                'total'         => $total,
                'allowed'       => $total - count($blocked),
                'blocked'       => count($blocked),
                'blocked_slugs' => $blocked,
            ],
            'llms_txt' => [
                'published'  => $published,
                // A stamp is only meaningful for a live document.
                'updated_at' => $published ? $updated_at : null,
                'url'        => function_exists('home_url') ? home_url('/llms.txt') : '/llms.txt',
            ],
        ];
    }
}
