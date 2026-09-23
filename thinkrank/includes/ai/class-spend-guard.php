<?php
/**
 * AI spend ceiling and kill switch.
 *
 * @package ThinkRank\AI
 * @since 2.9.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Governs how much a site may spend on its own AI provider key.
 *
 * ThinkRank's only existing control is `max_requests_per_minute`, which is
 * abuse prevention, not cost governance: it defaults to 0 (no throttle),
 * so nothing intervenes however fast a site sends requests, and
 * the setting was never rendered anywhere so no user could see or change it
 * (#448). This class adds the two controls a user worried about credit drain
 * actually needs — a daily ceiling they can see themselves approaching, and a
 * single switch that stops outbound AI immediately.
 *
 * Both are enforced at the HTTP boundary, inside each provider client, rather
 * than at the feature entry points. Feature code paths multiply (free has a
 * dozen, Pro adds Auto AI, Internal Links and Refresh Radar, some of them
 * cron-driven), and a ceiling that a new caller can forget to consult is not a
 * ceiling. Every outbound provider request in both plugins goes through one of
 * five request methods; those are the only places this has to be called.
 *
 * Neither control changes anything on an existing site until the user sets it:
 * the ceiling defaults to 0, which means unlimited, and the switch defaults to
 * off. The per-minute limiter from #42 is untouched and still runs.
 *
 * @since 2.9.0
 */
final class Spend_Guard {

    /**
     * Option holding today's request count.
     *
     * Shape: ['date' => 'Y-m-d', 'count' => int]. An option rather than a
     * transient because a spend ceiling that an object-cache eviction silently
     * resets is not a ceiling. The write cost is irrelevant here: it happens
     * once per outbound AI request, next to a multi-second HTTPS call to a paid
     * provider, and never on a front-end pageview.
     */
    private const USAGE_OPTION = 'thinkrank_ai_daily_usage';

    /**
     * Blocked because the user turned AI off.
     */
    public const REASON_PAUSED = 'paused';

    /**
     * Blocked because today's ceiling is reached.
     */
    public const REASON_DAILY_LIMIT = 'daily_limit';

    /**
     * Refuse an outbound AI request when the user has turned AI off or used up
     * today's allowance.
     *
     * Throws rather than returning false so a caller cannot proceed by ignoring
     * the return value, and so the reason reaches the user: every AI path in
     * both plugins already catches \Exception and surfaces its message, which
     * is what turns this from a generic "AI request failed" into an explanation.
     *
     * @since 2.9.0
     *
     * @throws \RuntimeException When the request must not be sent.
     * @return void
     */
    public static function guard(): void {
        $reason = self::blocked_reason();

        if ($reason === null) {
            return;
        }

        throw new \RuntimeException(esc_html(self::message_for($reason)));
    }

    /**
     * Why an outbound request would be refused right now, if it would be.
     *
     * @since 2.9.0
     *
     * @return string|null One of the REASON_* constants, or null when allowed.
     */
    public static function blocked_reason(): ?string {
        if (self::is_paused()) {
            return self::REASON_PAUSED;
        }

        $limit = self::daily_limit();

        if ($limit > 0 && self::used_today() >= $limit) {
            return self::REASON_DAILY_LIMIT;
        }

        return null;
    }

    /**
     * Count an outbound AI request against today's allowance.
     *
     * Called immediately before the request is sent, not after it returns. A
     * request that is dispatched and then times out may still have been billed
     * by the provider, and a PHP process killed mid-call would otherwise lose
     * the count entirely. For a spend ceiling, over-counting a failure is the
     * safe direction to be wrong in.
     *
     * @since 2.9.0
     *
     * @return void
     */
    public static function record(): void {
        $today = self::today();
        $usage = self::usage();

        $count = ($usage['date'] === $today) ? (int) $usage['count'] : 0;

        update_option(
            self::USAGE_OPTION,
            [
                'date'  => $today,
                'count' => $count + 1,
            ],
            false
        );
    }

    /**
     * Whether all outbound AI is switched off.
     *
     * @since 2.9.0
     *
     * @return bool
     */
    public static function is_paused(): bool {
        return (bool) self::settings()->get('ai_paused', false);
    }

    /**
     * Today's request ceiling. Zero means no ceiling.
     *
     * @since 2.9.0
     *
     * @return int
     */
    public static function daily_limit(): int {
        return max(0, (int) self::settings()->get('ai_daily_request_limit', 0));
    }

    /**
     * Requests already sent today.
     *
     * Reads as zero once the stored date is no longer today, which is what
     * makes the window reset on the day boundary without a scheduled job.
     *
     * @since 2.9.0
     *
     * @return int
     */
    public static function used_today(): int {
        $usage = self::usage();

        return $usage['date'] === self::today() ? max(0, (int) $usage['count']) : 0;
    }

    /**
     * The full picture, for the settings screen and the MCP abilities.
     *
     * @since 2.9.0
     *
     * @return array<string, mixed>
     */
    public static function status(): array {
        $limit = self::daily_limit();
        $used  = self::used_today();
        $reason = self::blocked_reason();

        return [
            'paused'           => self::is_paused(),
            'daily_limit'      => $limit,
            'used_today'       => $used,
            'remaining_today'  => $limit > 0 ? max(0, $limit - $used) : null,
            'per_minute_limit' => max(0, (int) self::settings()->get('max_requests_per_minute', 0)),
            'resets_at'        => self::next_reset(),
            'blocked'          => $reason !== null,
            'blocked_reason'   => $reason,
            'message'          => $reason !== null ? self::message_for($reason) : '',
        ];
    }

    /**
     * Wording shown to the user when a request is refused.
     *
     * @since 2.9.0
     *
     * @param string $reason One of the REASON_* constants.
     * @return string
     */
    public static function message_for(string $reason): string {
        if ($reason === self::REASON_PAUSED) {
            return __('AI is paused in ThinkRank settings, so no requests are being sent to your AI provider. Turn AI back on to resume.', 'thinkrank');
        }

        return sprintf(
            /* translators: %d: the configured daily AI request limit. */
            __('ThinkRank has reached its daily AI limit of %d requests, so this request was not sent to your AI provider. The count resets at midnight, site time.', 'thinkrank'),
            self::daily_limit()
        );
    }

    /**
     * Clear the stored counter.
     *
     * Exposed for the "reset now" control and for tests. Resetting does not
     * refund anything at the provider; it only moves this site's own window.
     *
     * @since 2.9.0
     *
     * @return void
     */
    public static function reset_usage(): void {
        delete_option(self::USAGE_OPTION);
    }

    /**
     * Today's date in the site's timezone.
     *
     * Site timezone rather than UTC, because "resets at midnight" has to mean
     * the midnight the user lives in, not the server's.
     *
     * @return string Y-m-d
     */
    private static function today(): string {
        return (string) wp_date('Y-m-d');
    }

    /**
     * When the current window ends, as a site-local ISO 8601 string.
     *
     * @return string
     */
    private static function next_reset(): string {
        // Must be computed IN the site timezone, not merely formatted in it.
        // strtotime('tomorrow', $ts) resolves "tomorrow" against PHP's default
        // timezone — UTC under WordPress — so it landed on the next UTC
        // midnight and wp_date() then rendered that same instant with the site
        // offset bolted on: a UTC+6 site was told its allowance resets at
        // 06:00, six hours after the counter in today() had already rolled
        // over. current_datetime() is a DateTimeImmutable already in the site
        // zone, so modify() resolves midnight there.
        return (string) current_datetime()->modify('tomorrow midnight')->format('c');
    }

    /**
     * Stored counter, normalised.
     *
     * @return array{date:string, count:int}
     */
    private static function usage(): array {
        $stored = get_option(self::USAGE_OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'date'  => isset($stored['date']) ? (string) $stored['date'] : '',
            'count' => isset($stored['count']) ? (int) $stored['count'] : 0,
        ];
    }

    /**
     * Settings singleton.
     *
     * Resolved per call rather than cached in a static property: Settings is
     * already a singleton, so caching it here would buy nothing and would hold
     * a stale instance across a test that replaces it.
     *
     * @return Settings
     */
    private static function settings(): Settings {
        return Settings::instance();
    }
}
