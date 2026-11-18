<?php
/**
 * SEO Goals: collect, rank and remember.
 *
 * @package ThinkRank\SEO\Goals
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals;

use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Goal Registry
 *
 * One prioritised to-do list from what ThinkRank already knows (#728): asks
 * every provider for its goals, ranks the open ones, and applies what the
 * owner said about them.
 *
 * **Done and ignored.** Marking a goal done hides it while its condition
 * still holds at the size it had then — "fix 3 empty descriptions" stays
 * hidden at 3, and comes back if it grows to 5. When a provider stops
 * reporting the goal as open, the condition went away, so the mark is
 * dropped: if it returns later it is a new goal and shows again. Ignoring
 * hides a goal until the owner restores it.
 *
 * **Freshness.** The assembled list is cached for an hour and thrown away
 * whenever a ThinkRank option, a post or the analyzer run changes, so
 * connecting Search Console takes "Connect Search Console" off the list on
 * the next load rather than an hour later. A list built under another set
 * of loaded plugins is not served either, so switching Pro or an SEO plugin
 * on or off shows its goals on the next load.
 *
 * @since 2.22.0
 */
class Goal_Registry {

    /**
     * Transient holding the assembled goals.
     */
    public const CACHE = 'thinkrank_seo_goals';

    /**
     * Transient naming the current generation of the list: a token every
     * flush replaces. A cached list carries the generation its build started
     * in and is only served while that is still the current one.
     *
     * Deleting the cache is not enough on its own. A build that is running
     * while a flush lands stores its list afterwards, over the flush, and
     * that list is then served for the cache's full hour. The Dashboard does
     * exactly that: it asks for the goals and the site audit side by side,
     * and the goals build that began with no audit cached "Run the site SEO
     * audit" after the audit had finished.
     *
     * A transient, not an option: an option named thinkrank_* would flush
     * the list from its own write, through flush_for_option().
     */
    public const GENERATION = 'thinkrank_seo_goals_generation';

    /**
     * Option holding done/ignored marks: id => {status, count, at}.
     */
    public const STATE_OPTION = 'thinkrank_seo_goals_state';

    /**
     * Goals the feed shows before "show all".
     */
    public const FEED_CAP = 12;

    /**
     * What a mark can set.
     *
     * @var string[]
     */
    public const STATUSES = ['done', 'ignored', 'open'];

    /**
     * Providers, when injected (tests); otherwise built from the filter.
     *
     * @var Goal_Provider_Interface[]|null
     */
    private ?array $providers;

    /**
     * @param Goal_Provider_Interface[]|null $providers Providers to use instead of the filtered defaults.
     */
    public function __construct(?array $providers = null) {
        $this->providers = $providers;
    }

    /**
     * Throw the cached list away whenever something it is built from changes.
     *
     * @return void
     */
    public function init(): void {
        add_action('updated_option', [self::class, 'flush_for_option'], 10, 1);
        add_action('added_option', [self::class, 'flush_for_option'], 10, 1);
        add_action('deleted_option', [self::class, 'flush_for_option'], 10, 1);
        add_action('thinkrank_seo_analysis_completed', [self::class, 'flush']);
        add_action('save_post', [self::class, 'flush']);
        add_action('deleted_post', [self::class, 'flush']);
        // Static delivery writes the file without always changing an option.
        add_action('thinkrank_llms_txt_updated', [self::class, 'flush']);
        // Keep the last audit for the analyzer goals, which the analyzer's
        // own cache does not outlive a settings save.
        add_action('thinkrank_seo_analysis_completed', [Providers\Analyzer_Provider::class, 'remember'], 5);
    }

    /**
     * Drop the cached list.
     *
     * @return void
     */
    public static function flush(): void {
        delete_transient(self::CACHE);
        set_transient(self::GENERATION, uniqid('', true));
    }

    /**
     * Drop the cached list when a ThinkRank option, or a core option a
     * setting check reads, changes. The goals' own state is
     * applied on read, so a mark never needs a rebuild.
     *
     * @param mixed $option Option name.
     * @return void
     */
    public static function flush_for_option($option): void {
        $option = (string) $option;

        if (self::STATE_OPTION === $option) {
            return;
        }

        // Core options the setting checks read: search visibility, and the
        // site title and tagline Site Identity writes.
        if (0 === strpos($option, 'thinkrank_') || in_array($option, ['blog_public', 'blogname', 'blogdescription'], true)) {
            self::flush();
        }
    }

    /**
     * A fingerprint of the plugins this request loaded.
     *
     * Pro adds its providers through a filter, and the Setup provider's
     * import goal depends on which SEO plugins are active, so a list built
     * before a plugin was switched on or off is the other state's list.
     * Activating or deactivating a plugin writes no ThinkRank option, and a
     * plugin whose folder was deleted changes no option at all; the
     * plugins actually loaded cover both.
     *
     * @since 2.22.0
     *
     * @return string
     */
    private static function plugins_fingerprint(): string {
        $plugins = function_exists('wp_get_active_and_valid_plugins')
            ? wp_get_active_and_valid_plugins()
            : (array) get_option('active_plugins', []);

        if (function_exists('is_multisite') && is_multisite() && function_exists('wp_get_active_network_plugins')) {
            $plugins = array_merge($plugins, wp_get_active_network_plugins());
        }

        $plugins = array_map('strval', $plugins);
        sort($plugins);

        return md5(implode('|', $plugins));
    }

    /**
     * Every goal from every provider, open or not.
     *
     * @param bool $refresh Rebuild instead of reading the cache.
     * @return array<int, array<string, mixed>>
     */
    public function all(bool $refresh = false): array {
        // Read before the providers run: a flush that lands during the build
        // moves the generation on, and the list stored below is then stale
        // to every later read instead of being served for an hour. The
        // loaded plugins are part of it: they decide which providers run and
        // which import goal Setup builds (#1679).
        $generation = (string) get_transient(self::GENERATION) . ':' . self::plugins_fingerprint();

        if (!$refresh) {
            $cached = get_transient(self::CACHE);
            if (is_array($cached) && isset($cached['goals']) && is_array($cached['goals'])
                && ($cached['generation'] ?? null) === $generation) {
                return $cached['goals'];
            }
        }

        $goals = [];
        $seen  = [];

        foreach ($this->providers() as $provider) {
            try {
                $provided = $provider->goals();
            } catch (\Throwable $e) {
                // One broken source must not empty the list.
                continue;
            }

            foreach ((array) $provided as $goal) {
                if (!$goal instanceof Goal || isset($seen[$goal->id()])) {
                    continue;
                }
                $seen[$goal->id()] = true;
                $goals[]           = $goal->to_array();
            }
        }

        set_transient(self::CACHE, ['generation' => $generation, 'goals' => $goals], HOUR_IN_SECONDS);

        /**
         * Fires after the goal list is rebuilt from the providers.
         *
         * Every goal is passed, open or not, so a listener can tell which
         * goals opened and which were completed since the last build — how
         * ThinkRank Pro keeps goal history. Not fired on a cache hit.
         *
         * @since 2.22.0
         *
         * @param array<int, array<string, mixed>> $goals Every goal, as data.
         */
        do_action('thinkrank_seo_goals_built', $goals);

        return $goals;
    }

    /**
     * The list as the screen, the dashboard card and the ability read it.
     *
     * @param bool $refresh Rebuild instead of reading the cache.
     * @return array{
     *     goals: array<int, array<string, mixed>>,
     *     total: int,
     *     ignored: array<int, array<string, mixed>>,
     *     checklists: array<string, array<int, array{id: string, title: string, done: bool}>>
     * }
     */
    public function feed(bool $refresh = false): array {
        $all   = $this->all($refresh);
        $state = $this->reconcile($all);

        $open    = [];
        $ignored = [];

        foreach ($all as $goal) {
            if (!$goal['open']) {
                continue;
            }

            $mark = $state[$goal['id']] ?? null;
            if (null !== $mark && 'ignored' === $mark['status']) {
                $ignored[] = $goal;
                continue;
            }
            if (self::done_holds($goal, $mark)) {
                continue;
            }

            $open[] = $goal;
        }

        usort($open, static function (array $a, array $b): int {
            return [$b['priority'], $a['id']] <=> [$a['priority'], $b['id']];
        });

        return [
            'goals'      => $open,
            'total'      => count($open),
            'ignored'    => $ignored,
            'checklists' => self::checklists($all, $state),
        ];
    }

    /**
     * Mark a goal done or ignored, or restore it.
     *
     * @param string $id     Goal id.
     * @param string $status One of {@see self::STATUSES}; `open` clears the mark.
     * @return array<string, mixed>|WP_Error The goal as it now stands. Marking a
     *                                      goal its provider reports complete
     *                                      done or ignored is refused.
     */
    public function mark(string $id, string $status) {
        if (!in_array($status, self::STATUSES, true)) {
            return new WP_Error('thinkrank_goal_bad_status', __('Status must be done, ignored or open.', 'thinkrank'), ['status' => 400]);
        }

        $goal = null;
        foreach ($this->all() as $candidate) {
            if ($candidate['id'] === $id) {
                $goal = $candidate;
                break;
            }
        }

        if (null === $goal) {
            return new WP_Error('thinkrank_goal_not_found', __('There is no such goal. It may already be complete.', 'thinkrank'), ['status' => 404]);
        }

        // A goal its provider reports complete has nothing to mark. Accepting
        // it fired thinkrank_seo_goal_marked for work that was already done,
        // which Pro's history counted as a completion (#1643); the mark
        // itself was dropped again on the next read. Restoring stays allowed:
        // it only clears a mark.
        if ('open' !== $status && empty($goal['open'])) {
            return new WP_Error('thinkrank_goal_complete', __('This goal is already complete.', 'thinkrank'), ['status' => 409]);
        }

        $state = $this->state();

        // A mark that changes nothing is not announced. Restoring a goal with
        // no mark, or marking done again a goal its done mark still covers,
        // used to fire thinkrank_seo_goal_marked anyway, and Pro's history
        // logged a "Restored" or a second "Marked done" row for it (#1676).
        if (self::mark_unchanged($goal, $state[$id] ?? null, $status)) {
            return $goal + ['mark' => $state[$id]['status'] ?? 'open'];
        }

        if ('open' === $status) {
            unset($state[$id]);
        } else {
            $state[$id] = ['status' => $status, 'count' => (int) $goal['count'], 'at' => time()];
        }
        update_option(self::STATE_OPTION, $state, false);

        /**
         * Fires after the owner marks a goal done or ignored, or restores it.
         *
         * @since 2.22.0
         *
         * @param array<string, mixed> $goal   The goal as it stood.
         * @param string               $status done, ignored or open.
         */
        do_action('thinkrank_seo_goal_marked', $goal, $status);

        return $goal + ['mark' => $state[$id]['status'] ?? 'open'];
    }

    /**
     * Whether marking a goal with a status leaves its stored mark as it is.
     *
     * A done mark that no longer covers the goal (it grew since) is not
     * unchanged: marking it done again records the new size.
     *
     * @since 2.22.0
     *
     * @param array<string, mixed>                            $goal   Goal.
     * @param array{status: string, count: int, at: int}|null $mark   Its stored mark.
     * @param string                                          $status Requested status.
     * @return bool
     */
    private static function mark_unchanged(array $goal, ?array $mark, string $status): bool {
        if ('open' === $status) {
            return null === $mark;
        }
        if ('done' === $status) {
            return self::done_holds($goal, $mark);
        }

        return null !== $mark && $status === $mark['status'];
    }

    /**
     * The providers: the free ones, plus whatever the filter adds.
     *
     * @return Goal_Provider_Interface[]
     */
    public function providers(): array {
        if (null !== $this->providers) {
            return $this->providers;
        }

        /**
         * Filter the sources SEO Goals are built from.
         *
         * Each entry must implement Goal_Provider_Interface and read only
         * what is already computed — goals are rebuilt on a page load.
         *
         * @since 2.22.0
         *
         * @param Goal_Provider_Interface[] $providers Providers.
         */
        $providers = apply_filters('thinkrank_seo_goal_providers', self::default_providers());

        return array_values(array_filter(
            is_array($providers) ? $providers : [],
            static fn($p): bool => $p instanceof Goal_Provider_Interface
        ));
    }

    /**
     * The free plugin's own providers.
     *
     * @return Goal_Provider_Interface[]
     */
    public static function default_providers(): array {
        return [
            new Providers\Setup_Provider(),
            new Providers\Analyzer_Provider(),
            new Providers\Content_Provider(),
            new Providers\Opportunities_Provider(),
        ];
    }

    /**
     * Stored marks, with every mark on a goal that is no longer open removed:
     * the condition went away, so a return is a new goal.
     *
     * @param array<int, array<string, mixed>> $all Every goal.
     * @return array<string, array{status: string, count: int, at: int}>
     */
    private function reconcile(array $all): array {
        $state  = $this->state();
        $closed = [];
        foreach ($all as $goal) {
            if (!$goal['open']) {
                $closed[$goal['id']] = true;
            }
        }

        // Only a goal its provider reports as closed loses its mark. One
        // missing from this build says nothing about its condition: the
        // audit cache is dropped on every settings save, and a provider can
        // fail for a request. Dropping those marks lost every Ignore on an
        // audit goal the first time a setting was saved.
        $kept = array_diff_key($state, $closed);
        if (count($kept) !== count($state)) {
            update_option(self::STATE_OPTION, $kept, false);
        }

        return $kept;
    }

    /**
     * Stored marks.
     *
     * @return array<string, array{status: string, count: int, at: int}>
     */
    private function state(): array {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    /**
     * Whether a done mark still covers a goal: it is marked done and has not
     * grown since ("3 pages without a description" stays done at 3, not 5).
     *
     * @param array<string, mixed>                              $goal Goal.
     * @param array{status: string, count: int, at: int}|null $mark Its mark.
     * @return bool
     */
    private static function done_holds(array $goal, ?array $mark): bool {
        return null !== $mark && 'done' === $mark['status'] && (int) $goal['count'] <= (int) $mark['count'];
    }

    /**
     * Per-section checklists: every goal that names a section, ticked when it
     * is not open — or the owner marked it done — with the link to the screen
     * where it is set.
     *
     * @param array<int, array<string, mixed>>                        $all   Every goal.
     * @param array<string, array{status: string, count: int, at: int}> $state Marks.
     * @return array<string, array<int, array{id: string, title: string, done: bool, link: array<string, string>}>>
     */
    private static function checklists(array $all, array $state): array {
        $lists = [];

        foreach ($all as $goal) {
            if ('' === $goal['section']) {
                continue;
            }
            // A done mark ticks the item only while the goal is no bigger
            // than when it was marked, the rule feed() uses to hide it.
            $marked = self::done_holds($goal, $state[$goal['id']] ?? null);

            $lists[$goal['section']][] = [
                'id'    => $goal['id'],
                'title' => $goal['title'],
                'done'  => !$goal['open'] || $marked,
                'link'  => $goal['link'],
            ];
        }

        return $lists;
    }
}
