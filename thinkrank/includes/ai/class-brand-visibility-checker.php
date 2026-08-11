<?php
/**
 * AI brand visibility checks.
 *
 * Answers the question customers actually ask: "when someone asks an AI
 * assistant about my topic, does my brand come up — and does it cite my
 * site?" The check asks the site's own configured AI provider a user-style
 * question, then inspects the answer for the brand name and the site's
 * domain, and stores the verdict as history.
 *
 * BYO-key by design: hosted competitors run these probes through their own
 * infrastructure and bill for it; ThinkRank's provider-key architecture means
 * the site owner's key does the asking, the same way every other AI feature
 * here works. The trade-off is honest and documented in the UI: this measures
 * visibility in the models the user has a key for, not across every AI
 * product on the market.
 *
 * @package ThinkRank\AI
 * @since 1.27.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs brand-visibility probes and persists their history.
 */
class Brand_Visibility_Checker {

    /**
     * Hard cap on queries per run — each is one paid AI call on the user's key.
     */
    public const MAX_QUERIES = 10;

    /**
     * Output-token ceiling per probe.
     *
     * Must be reasoning-safe: GPT-5-family models (the OpenAI client's default
     * is gpt-5-nano) spend output tokens on hidden reasoning BEFORE emitting
     * text. At the previous 800-token cap the reasoning burned the whole
     * budget, the API returned an EMPTY answer with no error, and every check
     * was stored as a clean "not mentioned" — a false negative telling users
     * their brand is invisible when the probe simply never got an answer.
     * A ceiling is not a spend: non-reasoning models answering in 300 tokens
     * cost exactly the same as before. get_safe_token_limit() still clamps
     * this to each model's own maximum.
     */
    public const ANSWER_TOKEN_BUDGET = 8000;

    /**
     * AI manager (provider gateway).
     *
     * @var Manager
     */
    private Manager $ai;

    /**
     * Settings accessor.
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Constructor.
     *
     * @param Manager|null  $ai       AI manager (default: fresh instance).
     * @param Settings|null $settings Settings (default: singleton).
     */
    public function __construct(?Manager $ai = null, ?Settings $settings = null) {
        $this->settings = $settings ?? Settings::instance();

        if (null === $ai) {
            // A fresh Manager has no client until initialize_client() runs —
            // the plugin's singleton does that on `init`, but REST callers
            // construct their own.
            $ai = new Manager();
            $ai->initialize_client();
        }
        $this->ai = $ai;
    }

    /**
     * Inspect an AI answer for the brand and the site domain.
     *
     * Pure so it can be unit-tested without WordPress. "Mentioned" is a
     * case-insensitive brand-name hit; "cited" is the site's host appearing
     * (with or without scheme/www). The excerpt centers on the first brand
     * hit so the UI can show *how* the brand came up, not just that it did.
     *
     * @param string $brand  Brand name to look for.
     * @param string $host   Site host, e.g. example.com.
     * @param string $answer The AI answer text.
     * @return array{mentioned:bool, cited:bool, excerpt:string}
     */
    public static function inspect_answer(string $brand, string $host, string $answer): array {
        $mentioned = '' !== $brand && false !== stripos($answer, $brand);

        $bare_host = strtolower(preg_replace('/^www\./', '', $host));
        $cited     = '' !== $bare_host && false !== stripos($answer, $bare_host);

        $excerpt = '';
        if ($mentioned || $cited) {
            $needle = $mentioned ? $brand : $bare_host;
            $pos    = stripos($answer, $needle);
            $start  = max(0, $pos - 120);
            $excerpt = trim(substr($answer, $start, 300));
            if ($start > 0) {
                $excerpt = '…' . $excerpt;
            }
            if ($start + 300 < strlen($answer)) {
                $excerpt .= '…';
            }
        }

        return [
            'mentioned' => $mentioned,
            'cited'     => $cited,
            'excerpt'   => $excerpt,
        ];
    }

    /**
     * The saved query list, defaulting to one query built from the site name.
     *
     * @return string[]
     */
    public function queries(): array {
        $saved = $this->settings->get('brand_visibility_queries', []);
        $saved = is_array($saved) ? array_values(array_filter(array_map('trim', $saved))) : [];

        if (empty($saved)) {
            $brand = (string) get_bloginfo('name');
            if ('' !== $brand) {
                $saved = [
                    sprintf('What is %s and what does it offer?', $brand),
                ];
            }
        }

        return array_slice($saved, 0, self::query_limit());
    }

    /**
     * Effective number of brand queries the current plan may run.
     *
     * Free allows a couple so the feature is genuinely usable (and its value
     * obvious); Pro lifts the cap. MAX_QUERIES remains the absolute ceiling
     * for every plan — each query is a paid AI call on the user's own key.
     *
     * @since 1.28.0
     *
     * @return int Query limit, always >= 1 and <= MAX_QUERIES.
     */
    public static function query_limit(): int {
        $caps  = \ThinkRank\Core\Plan_Config::ai_visibility();
        $limit = (int) ($caps['brand_max_queries'] ?? 2);

        // 0 means "unlimited" in the capability map — still bounded by the
        // hard ceiling, since every query costs the user an AI call.
        if ($limit <= 0) {
            return self::MAX_QUERIES;
        }

        return min($limit, self::MAX_QUERIES);
    }

    /**
     * Run every saved query against the configured provider and persist rows.
     *
     * Failures on individual queries don't abort the run — each query's error
     * is reported in its result row so one flaky call doesn't waste the rest
     * of the (paid) batch.
     *
     * @return array<int, array<string, mixed>> Per-query results.
     * @throws \Exception When no AI client is configured at all.
     */
    public function run(): array {
        global $wpdb;

        $brand = (string) get_bloginfo('name');
        $host  = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        $provider = (string) $this->settings->get('ai_provider', 'openai');
        $model    = (string) $this->settings->get('ai_model', '');

        $table   = $wpdb->prefix . 'thinkrank_brand_visibility_checks';
        $results = [];

        foreach ($this->queries() as $query) {
            try {
                // A user-style question, not an SEO prompt: the point is to see
                // what an ordinary answer contains.
                $prompt = sprintf(
                    "Answer the following question the way you would answer any user. Be specific and name relevant products, companies or websites where appropriate.\n\nQuestion: %s",
                    $query
                );

                // reasoning_effort=minimal (GPT-5 family): this probe wants a
                // quick consumer-style answer; at default effort gpt-5-nano can
                // burn the ENTIRE completion budget on hidden reasoning and
                // return empty text with a 200.
                $answer      = $this->ai->answer_prompt($prompt, self::ANSWER_TOKEN_BUDGET, [
                    'reasoning_effort' => 'minimal',
                ]);
                $full_answer = (string) $answer['ai_text'];

                // An empty answer is a FAILED probe, never a "not mentioned".
                // Reasoning models that exhaust their token budget (or a
                // provider content filter) return empty text with a 200 — the
                // one shape that must not become a stored false negative.
                if ('' === trim($full_answer)) {
                    $detail = '';
                    if ('' !== (string) ($answer['finish_reason'] ?? '')) {
                        $detail = sprintf(
                            ' [finish_reason: %s, reasoning_tokens: %d, model: %s]',
                            $answer['finish_reason'],
                            (int) ($answer['reasoning_tokens'] ?? 0),
                            '' !== $model ? $model : 'default'
                        );
                    }

                    throw new \Exception(esc_html(__(
                        'The AI returned an empty answer, so this check could not be evaluated. Try again, or pick a different model under Settings → AI Provider.',
                        'thinkrank'
                    ) . $detail));
                }

                $verdict = self::inspect_answer($brand, $host, $full_answer);
                $error   = '';
            } catch (\Exception $e) {
                $verdict     = ['mentioned' => false, 'cited' => false, 'excerpt' => ''];
                $full_answer = '';
                $error       = $e->getMessage();
            }

            if ('' === $error) {
                // Keep the whole answer (capped) so the UI can show it in full,
                // not just the brand-centred excerpt.
                $stored_answer = substr($full_answer, 0, 20000);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- history insert into our own table.
                $wpdb->insert(
                    $table,
                    [
                        'checked_at' => current_time('mysql'),
                        'query_text' => substr($query, 0, 191),
                        'provider'   => substr($provider, 0, 20),
                        'model'      => substr($model, 0, 80),
                        'mentioned'  => $verdict['mentioned'] ? 1 : 0,
                        'cited'      => $verdict['cited'] ? 1 : 0,
                        'excerpt'    => $verdict['excerpt'],
                        'answer'     => $stored_answer,
                    ],
                    ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
                );
            }

            $results[] = [
                'query'     => $query,
                'mentioned' => $verdict['mentioned'],
                'cited'     => $verdict['cited'],
                'excerpt'   => $verdict['excerpt'],
                'error'     => $error,
            ];
        }

        return $results;
    }

    /**
     * Check history, newest first.
     *
     * @param int $limit Max rows.
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit = 50): array {
        global $wpdb;

        $table = $wpdb->prefix . 'thinkrank_brand_visibility_checks';
        $limit = max(1, min(200, $limit));

        // Free plans see a recent slice; Pro keeps the full trend. Rows are
        // never deleted here — a plan change reveals the existing history.
        $caps      = \ThinkRank\Core\Plan_Config::ai_visibility();
        $plan_cap  = (int) ($caps['brand_history_limit'] ?? 10);
        if ($plan_cap > 0) {
            $limit = min($limit, $plan_cap);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read on our own table.
        $rows = $wpdb->get_results(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a literal, and every value is passed as a placeholder replacement.
            $wpdb->prepare("SELECT id, checked_at, query_text, provider, model, mentioned, cited, excerpt, answer FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(
            static fn(array $r): array => [
                'id'         => (int) $r['id'],
                'checked_at' => (string) $r['checked_at'],
                'query'      => (string) $r['query_text'],
                'provider'   => (string) $r['provider'],
                'model'      => (string) $r['model'],
                'mentioned'  => (bool) $r['mentioned'],
                'cited'      => (bool) $r['cited'],
                'excerpt'    => (string) $r['excerpt'],
                'answer'     => (string) ($r['answer'] ?? ''),
            ],
            (array) $rows
        );
    }

    /**
     * Delete a single check-history row.
     *
     * @param int $id Row id.
     * @return bool True when a row was removed.
     */
    public function delete_history(int $id): bool {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $table = $wpdb->prefix . 'thinkrank_brand_visibility_checks';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete on our own table.
        return (bool) $wpdb->delete($table, ['id' => $id], ['%d']);
    }
}
