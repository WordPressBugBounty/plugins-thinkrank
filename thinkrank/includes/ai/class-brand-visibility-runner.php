<?php
/**
 * Brand Visibility v2 — queued run engine.
 *
 * A full analysis is queries × platforms × samples, which is routinely 100+
 * AI calls. v1 ran that batch synchronously inside a REST request: on any real
 * configuration it would exhaust max_execution_time, and an interruption lost
 * everything. This engine persists every probe as a task row and drains them
 * off-request in cron ticks with a wall-clock budget, so a run makes forward
 * progress, survives interruption, and reports honest progress while it works.
 *
 * The shape mirrors the standard batch/sweeper/finalizer trio:
 *   start()    — snapshot config, fan out task rows, kick the first tick
 *   tick()     — process what fits in the budget, then reschedule if unfinished
 *   sweep()    — return tasks stuck in `running` (a killed worker) to `pending`
 *   finalize() — aggregate task rows into the run's results, once
 *
 * @package ThinkRank\AI
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

use ThinkRank\Core\Plan_Config;
use ThinkRank\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates and drains Brand Visibility analysis runs.
 */
class Brand_Visibility_Runner {

    /**
     * Cron hook that drains pending tasks.
     */
    public const TICK_HOOK = 'thinkrank_bv_tick';

    /**
     * Recurring safety net that restarts a stalled drain.
     */
    public const WATCHDOG_HOOK = 'thinkrank_bv_watchdog';

    /**
     * Interval slug for the watchdog.
     */
    public const WATCHDOG_INTERVAL = 'thinkrank_bv_five_minutes';

    /**
     * Wall-clock budget for one tick, seconds. Comfortably inside the 30s
     * PHP default while still landing several probes per tick.
     */
    private const TICK_BUDGET = 20;

    /**
     * Hard ceiling on probes per tick, so a fast provider can't run a tick
     * long enough to collide with the next scheduled one.
     */
    private const TICK_MAX_TASKS = 12;

    /**
     * Attempts before a task is abandoned.
     */
    private const MAX_ATTEMPTS = 2;

    /**
     * Minutes after which a `running` task is presumed dead.
     */
    private const STUCK_AFTER_MINUTES = 10;

    /**
     * Output-token budget per probe. Reasoning-safe: see 1942a44/6094d9d —
     * GPT-5-family models spend output tokens on hidden reasoning first and
     * return empty text if the budget is too small.
     */
    private const ANSWER_TOKENS = 8000;

    /**
     * Settings accessor.
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Provider resolver.
     *
     * @var Brand_Visibility_Providers
     */
    private Brand_Visibility_Providers $providers;

    /**
     * Constructor.
     *
     * @param Settings|null                   $settings  Settings.
     * @param Brand_Visibility_Providers|null $providers Provider resolver.
     */
    public function __construct(?Settings $settings = null, ?Brand_Visibility_Providers $providers = null) {
        $this->settings  = $settings ?? Settings::instance();
        $this->providers = $providers ?? new Brand_Visibility_Providers($this->settings);
    }

    /**
     * Register the cron listener.
     *
     * @return void
     */
    public function init(): void {
        add_action(self::TICK_HOOK, [$this, 'tick']);
        add_action(self::WATCHDOG_HOOK, [$this, 'watchdog']);
        add_filter('cron_schedules', [self::class, 'add_cron_interval']); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
    }

    /**
     * Register the watchdog's five-minute interval.
     *
     * @param array $schedules Registered cron schedules.
     * @return array
     */
    public static function add_cron_interval(array $schedules): array {
        $schedules[self::WATCHDOG_INTERVAL] ??= [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every five minutes (ThinkRank Brand Visibility)', 'thinkrank'),
        ];

        return $schedules;
    }

    /**
     * Recover a run whose drain stopped.
     *
     * `tick()` reschedules itself, which is enough while ticks complete — but
     * a tick killed by a fatal, an OOM or a worker timeout never reaches that
     * line, and the run then sits at whatever percentage it reached forever.
     * This recurring hook re-sweeps abandoned claims, closes runs whose tasks
     * all resolved, and re-arms the drain. It unschedules itself once there is
     * no outstanding work, so it costs nothing on an idle site.
     *
     * @return void
     */
    public function watchdog(): void {
        $this->sweep();
        $this->finalize_completed_runs();

        if ($this->has_outstanding_tasks()) {
            $this->schedule_tick();
            return;
        }

        self::unschedule_watchdog();
    }

    /**
     * Arm the watchdog while a run is in flight.
     *
     * @return void
     */
    private function schedule_watchdog(): void {
        if (!wp_next_scheduled(self::WATCHDOG_HOOK)) {
            wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), self::WATCHDOG_INTERVAL, self::WATCHDOG_HOOK);
        }
    }

    /**
     * Disarm the watchdog.
     *
     * @return void
     */
    public static function unschedule_watchdog(): void {
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
    }

    /**
     * Runs table name.
     *
     * @return string
     */
    public static function runs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'thinkrank_bv_runs';
    }

    /**
     * Tasks table name.
     *
     * @return string
     */
    public static function tasks_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'thinkrank_bv_tasks';
    }

    /**
     * Expand a configuration into the flat list of probes it implies.
     *
     * Pure and public so the wizard's cost preview and the runner agree by
     * construction — the number shown before spending is the number of calls
     * actually made.
     *
     * @param array $config queries[{text,type}], platforms[], samples.
     * @return array<int, array{query_text: string, query_type: string, platform: string, sample_index: int}>
     */
    public static function plan_tasks(array $config): array {
        $queries   = $config['queries'] ?? [];
        $platforms = $config['platforms'] ?? [];
        $samples   = max(1, (int) ($config['samples'] ?? 1));

        $tasks = [];

        foreach ($queries as $query) {
            $text = trim((string) ($query['text'] ?? ''));
            if ('' === $text) {
                continue;
            }
            $type = (string) ($query['type'] ?? 'branded');
            if (!in_array($type, Brand_Visibility_Scorer::QUERY_TYPES, true)) {
                $type = 'branded';
            }

            foreach ($platforms as $platform) {
                for ($i = 0; $i < $samples; $i++) {
                    $tasks[] = [
                        'query_text'   => $text,
                        'query_type'   => $type,
                        'platform'     => (string) $platform,
                        'sample_index' => $i,
                    ];
                }
            }
        }

        return $tasks;
    }

    /**
     * Start a run: snapshot the config, fan out tasks, schedule the drain.
     *
     * @param array $config Full run configuration.
     * @return int New run id.
     * @throws \Exception When the config yields no work.
     */
    public function start(array $config): int {
        global $wpdb;

        $planned = self::plan_tasks($config);
        if (empty($planned)) {
            throw new \Exception(esc_html__('Nothing to run: add at least one question and one AI platform.', 'thinkrank'));
        }

        // Pre-flight: repair a missing schema rather than failing with a bare
        // "could not start" (#270). Self-healing beats an error message here —
        // the user can't act on "a table is missing" anyway.
        $this->ensure_schema();

        $wpdb->insert(
            self::runs_table(),
            [
                'status'      => 'running',
                'started_at'  => current_time('mysql'),
                'tasks_total' => count($planned),
                'tasks_done'  => 0,
                'config'      => wp_json_encode($config),
            ],
            ['%s', '%s', '%d', '%d', '%s']
        );

        $run_id = (int) $wpdb->insert_id;
        if ($run_id <= 0) {
            throw new \Exception(esc_html__('Could not start the analysis run.', 'thinkrank'));
        }

        foreach ($planned as $task) {
            $wpdb->insert(
                self::tasks_table(),
                [
                    'run_id'       => $run_id,
                    'query_text'   => $task['query_text'],
                    'query_type'   => $task['query_type'],
                    'platform'     => $task['platform'],
                    'sample_index' => $task['sample_index'],
                    'status'       => 'pending',
                    'updated_at'   => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
            );
        }

        $this->schedule_tick();
        $this->schedule_watchdog();

        return $run_id;
    }

    /**
     * Make sure this feature's tables exist before a run depends on them.
     *
     * Normally the schema upgrade path has already handled it; this is the
     * backstop for an install whose stored db_version matched while a table
     * was absent — the exact state that made Brand Visibility dead on arrival
     * in #270. dbDelta creates only what's missing, so re-running is cheap and
     * safe.
     *
     * @since 1.28.0
     *
     * @return void
     * @throws \Exception When the tables still cannot be created.
     */
    private function ensure_schema(): void {
        global $wpdb;

        $table = self::runs_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off existence probe before a run.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return;
        }

        (new \ThinkRank\Database\Database_Schema())->create_tables();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verify the repair worked.
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            throw new \Exception(esc_html__(
                'Brand Visibility storage is missing and could not be created. Please deactivate and reactivate ThinkRank, then try again.',
                'thinkrank'
            ));
        }
    }

    /**
     * Process a slice of pending work, then reschedule while work remains.
     *
     * @return void
     */
    public function tick(): void {
        $this->sweep();

        $started  = microtime(true);
        $handled  = 0;

        while ($handled < self::TICK_MAX_TASKS && (microtime(true) - $started) < self::TICK_BUDGET) {
            $task = $this->claim_next_task();
            if (null === $task) {
                break;
            }

            $this->process_task($task);
            $handled++;
        }

        // Finalize every run whose tasks are all resolved, then keep ticking
        // only while work is genuinely outstanding.
        $this->finalize_completed_runs();

        if ($this->has_outstanding_tasks()) {
            $this->schedule_tick();
            return;
        }

        // Nothing left to drain — stop the recurring safety net.
        self::unschedule_watchdog();
    }

    /**
     * Return long-`running` tasks to the pool.
     *
     * A worker killed mid-probe would otherwise leave its task claimed
     * forever, stalling the run at 97%.
     *
     * @return void
     */
    public function sweep(): void {
        global $wpdb;

        // `updated_at` is written with current_time('mysql') — site-local, not
        // UTC — so the cutoff must be site-local too. Comparing a UTC cutoff
        // against local timestamps skews the sweep by the gmt_offset: east of
        // UTC nothing is ever swept (a killed worker stalls the run forever),
        // west of it live tasks get yanked back to `pending` mid-probe.
        $cutoff = $this->local_time_ago(self::STUCK_AFTER_MINUTES * MINUTE_IN_SECONDS);
        $table  = self::tasks_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- maintenance sweep on our own table.
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'pending' WHERE status = 'running' AND updated_at < %s",
            $cutoff
        ));
    }

    /**
     * Claim the next pending task (marking it `running` so parallel ticks
     * can't both take it).
     *
     * @return array|null Task row, or null when the queue is drained.
     */
    private function claim_next_task(): ?array {
        global $wpdb;

        $table = self::tasks_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- queue read on our own table.
        $task = $wpdb->get_row("SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT 1", ARRAY_A);

        if (!$task) {
            return null;
        }

        // Conditional UPDATE is the claim: if another tick got there first the
        // affected-row count is 0 and we simply skip this one.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- claim on our own table.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending'",
            current_time('mysql'),
            (int) $task['id']
        ));

        return $claimed ? $task : null;
    }

    /**
     * Run one probe and record its verdict.
     *
     * @param array $task Task row.
     * @return void
     */
    private function process_task(array $task): void {
        global $wpdb;

        $run    = $this->get_run((int) $task['run_id']);
        $config = is_array($run['config'] ?? null) ? $run['config'] : [];

        $brand       = (string) ($config['brand'] ?? get_bloginfo('name'));
        $variants    = (array) ($config['variants'] ?? []);
        $competitors = (array) ($config['competitors'] ?? []);
        $host        = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        try {
            $client = $this->providers->client_for((string) $task['platform']);
            $answer = $this->ask($client, (string) $task['query_text'], (string) $task['platform']);

            if ('' === trim($answer)) {
                throw new \Exception(esc_html__('The AI returned an empty answer.', 'thinkrank'));
            }

            $mentioned = Brand_Visibility_Scorer::is_mentioned($answer, $brand, $variants);
            $sentiment = '';

            // Sentiment costs an extra call, so only ask when the brand was
            // actually named — an answer that ignored you has no opinion.
            if ($mentioned && Plan_Config::can('brand_sentiment', 'ai_visibility')) {
                $sentiment = $this->classify_sentiment($client, $answer, $brand);
            }

            $this->update_task((int) $task['id'], [
                'status'      => 'done',
                'mentioned'   => $mentioned ? 1 : 0,
                'cited'       => Brand_Visibility_Scorer::is_cited($answer, $host) ? 1 : 0,
                'sentiment'   => $sentiment,
                'competitors' => wp_json_encode(Brand_Visibility_Scorer::competitors_in($answer, $competitors)),
                'excerpt'     => Brand_Visibility_Scorer::excerpt($answer, $brand, $variants),
                'answer'      => substr($answer, 0, 20000),
                'error'       => '',
                'updated_at'  => current_time('mysql'),
            ]);

            $this->bump_run((int) $task['run_id'], 'tasks_done');
        } catch (\Throwable $e) {
            $retryable = (int) $task['attempts'] < self::MAX_ATTEMPTS;

            $this->update_task((int) $task['id'], [
                'status'     => $retryable ? 'pending' : 'failed',
                'error'      => substr($e->getMessage(), 0, 500),
                'updated_at' => current_time('mysql'),
            ]);

            if (!$retryable) {
                $this->bump_run((int) $task['run_id'], 'tasks_failed');
            }
        }
    }

    /**
     * Ask one platform one question.
     *
     * @param object $client   Provider client.
     * @param string $question User-style question.
     * @param string $platform Platform slug (for provider-specific options).
     * @return string Answer text.
     */
    private function ask($client, string $question, string $platform): string {
        // A user-style question, not an SEO prompt: the point is to observe an
        // ordinary answer, exactly as a shopper would receive it.
        $prompt = sprintf(
            "Answer the following question the way you would answer any user. Be specific and name relevant products, companies or websites where appropriate.\n\nQuestion: %s",
            $question
        );

        $options = [
            'max_tokens'  => self::ANSWER_TOKENS,
            'temperature' => 0.4,
        ];

        // GPT-5 reasoning models will otherwise spend the whole budget on
        // hidden reasoning and return empty text (see 6094d9d).
        if ('chatgpt' === $platform) {
            $options['reasoning_effort'] = 'minimal';
        }

        $response = $client->generate_completion($prompt, $options);

        return $this->extract_text($response);
    }

    /**
     * One-word sentiment of an answer toward the brand.
     *
     * @param object $client Provider client.
     * @param string $answer Answer text.
     * @param string $brand  Brand name.
     * @return string positive|neutral|negative, or '' when unclear.
     */
    private function classify_sentiment($client, string $answer, string $brand): string {
        try {
            $prompt = sprintf(
                "Classify the sentiment toward \"%s\" in the text below. Reply with exactly one word: positive, neutral, or negative.\n\nText:\n%s",
                $brand,
                substr($answer, 0, 4000)
            );

            $verdict = strtolower(trim($this->extract_text(
                $client->generate_completion($prompt, ['max_tokens' => 2000, 'temperature' => 0])
            )));

            foreach (['positive', 'negative', 'neutral'] as $mood) {
                if (false !== strpos($verdict, $mood)) {
                    return $mood;
                }
            }
        } catch (\Throwable $e) {
            // Sentiment is a nice-to-have; never fail a probe over it.
            return '';
        }

        return '';
    }

    /**
     * Pull assistant text out of any supported provider response shape.
     *
     * @param array $response Decoded provider response.
     * @return string
     */
    private function extract_text(array $response): string {
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
            if (is_array($content)) {
                return implode(' ', array_map(
                    static fn($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part,
                    $content
                ));
            }
            return (string) $content;
        }

        if (isset($response['content'][0]['text'])) {
            return (string) $response['content'][0]['text'];
        }

        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return (string) $response['candidates'][0]['content']['parts'][0]['text'];
        }

        if (isset($response['content']) && is_string($response['content'])) {
            return $response['content'];
        }

        return '';
    }

    /**
     * Aggregate and close any run whose tasks are all resolved.
     *
     * @return void
     */
    public function finalize_completed_runs(): void {
        global $wpdb;

        $runs  = self::runs_table();
        $tasks = self::tasks_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read on our own tables.
        $ids = $wpdb->get_col(
            "SELECT r.id FROM `{$runs}` r
             WHERE r.status = 'running'
               AND NOT EXISTS (
                   SELECT 1 FROM `{$tasks}` t
                   WHERE t.run_id = r.id AND t.status IN ('pending', 'running')
               )"
        );

        foreach ((array) $ids as $id) {
            $this->finalize((int) $id);
        }
    }

    /**
     * Compute and store a run's aggregates.
     *
     * @param int $run_id Run id.
     * @return void
     */
    public function finalize(int $run_id): void {
        global $wpdb;

        $run     = $this->get_run($run_id);
        $config  = is_array($run['config'] ?? null) ? $run['config'] : [];
        $tasks   = $this->get_tasks($run_id);
        $results = Brand_Visibility_Scorer::aggregate($tasks, $config);

        $done   = count(array_filter($tasks, static fn(array $t): bool => 'done' === $t['status']));
        $failed = count(array_filter($tasks, static fn(array $t): bool => 'failed' === $t['status']));

        // A run where every probe failed is a FAILED run, not a run reporting
        // zero visibility — the distinction v1 got wrong.
        $status = (0 === $done && $failed > 0) ? 'failed' : 'complete';

        $wpdb->update(
            self::runs_table(),
            [
                'status'       => $status,
                'finished_at'  => current_time('mysql'),
                'tasks_done'   => $done,
                'tasks_failed' => $failed,
                'results'      => wp_json_encode($results),
                'error'        => 'failed' === $status
                    ? (string) ($tasks[0]['error'] ?? __('Every probe failed.', 'thinkrank'))
                    : '',
            ],
            ['id' => $run_id],
            ['%s', '%s', '%d', '%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * A run with its config/results decoded.
     *
     * @param int $run_id Run id.
     * @return array
     */
    public function get_run(int $run_id): array {
        global $wpdb;

        $table = self::runs_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read on our own table.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $run_id), ARRAY_A);

        if (!$row) {
            return [];
        }

        $row['config']  = json_decode((string) $row['config'], true) ?: [];
        $row['results'] = json_decode((string) $row['results'], true) ?: [];

        return $row;
    }

    /**
     * Task rows for a run.
     *
     * @param int $run_id Run id.
     * @return array
     */
    public function get_tasks(int $run_id): array {
        global $wpdb;

        $table = self::tasks_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read on our own table.
        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE run_id = %d ORDER BY id ASC", $run_id),
            ARRAY_A
        );
    }

    /**
     * Recent runs, newest first (feeds the over-time chart).
     *
     * @param int $limit Max runs.
     * @return array
     */
    public function recent_runs(int $limit = 20): array {
        global $wpdb;

        $table = self::runs_table();
        $limit = max(1, min(100, $limit));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read on our own table.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare("SELECT id, status, started_at, finished_at, tasks_total, tasks_done, tasks_failed, results FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return array_map(static function (array $row): array {
            $row['results'] = json_decode((string) $row['results'], true) ?: [];
            return $row;
        }, $rows);
    }

    /**
     * Whether any run still has work outstanding.
     *
     * @return bool
     */
    private function has_outstanding_tasks(): bool {
        global $wpdb;

        $table = self::tasks_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read on our own table.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status IN ('pending','running')") > 0;
    }

    /**
     * Schedule the next drain if one isn't already due.
     *
     * @return void
     */
    private function schedule_tick(): void {
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_single_event(time() + 5, self::TICK_HOOK);
        }

        // Cron on a low-traffic site only fires on a visit; nudge it so a run
        // started from the dashboard begins immediately.
        spawn_cron();
    }

    /**
     * Format a UTC timestamp as a site-local MySQL datetime.
     *
     * The datetime columns on this feature's tables are written with
     * current_time('mysql'), so every comparison value has to be built in the
     * same frame. Deriving it from current_time() rather than the gmt_offset
     * option keeps it correct under DST — that option is only re-synced when
     * the timezone is saved. WordPress runs PHP in UTC, so parsing the local
     * string with strtotime() and re-formatting with gmdate() round-trips the
     * same wall clock, minus the offset asked for.
     *
     * @param int $offset_seconds Seconds to subtract from "now".
     * @return string Site-local `Y-m-d H:i:s`.
     */
    private function local_time_ago(int $offset_seconds): string {
        return gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $offset_seconds);
    }

    /**
     * Update a task row.
     *
     * @param int   $task_id Task id.
     * @param array $data    Column => value.
     * @return void
     */
    private function update_task(int $task_id, array $data): void {
        global $wpdb;

        $wpdb->update(self::tasks_table(), $data, ['id' => $task_id], null, ['%d']);
    }

    /**
     * Increment a run counter.
     *
     * @param int    $run_id Run id.
     * @param string $column tasks_done|tasks_failed.
     * @return void
     */
    private function bump_run(int $run_id, string $column): void {
        global $wpdb;

        if (!in_array($column, ['tasks_done', 'tasks_failed'], true)) {
            return;
        }

        $table = self::runs_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- counter bump on our own table; column whitelisted above.
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET {$column} = {$column} + 1 WHERE id = %d",
            $run_id
        ));
    }
}
