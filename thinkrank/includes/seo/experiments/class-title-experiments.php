<?php
/**
 * Title Experiments: run, serve, measure and finish an experiment.
 *
 * @package ThinkRank\SEO\Experiments
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Experiments;

use ThinkRank\Core\Page_Cache;
use ThinkRank\Core\Plan_Config;
use ThinkRank\SEO\Content_Type_Settings;
use ThinkRank\SEO\Indexability;
use ThinkRank\SEO\Pattern_Resolver;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Title Experiments
 *
 * Tests a new SEO title and description on one page against the current ones,
 * on real Google clicks (#719). The page's own stored SEO fields are never
 * changed while a test runs: the variant is served through
 * `thinkrank_served_title` / `thinkrank_served_description`, which touch only
 * the `<title>` and meta description. Stopping a test therefore restores the
 * original exactly — there is nothing to put back — and keeping the winner is
 * the one write, made when the owner asks.
 *
 * Lifecycle, driven by a daily event:
 *
 * - `running`   — the variant is served. Its measured window starts after a
 *                 grace period ({@see Experiment_Schedule}).
 * - `measuring` — the window is over, the original is served again, and
 *                 Search Console needs a few days before the dates have data.
 * - `complete`  — measured; the verdict is stored with the numbers.
 * - `stopped`   — the owner ended it early.
 *
 * Stored on the post (`_thinkrank_title_experiment`): the running experiment,
 * or the last finished one. Running ones are also listed in an option so the
 * daily event does not have to search post meta.
 *
 * @since 2.22.0
 */
class Title_Experiments {

    /**
     * Post meta holding the experiment.
     */
    public const META_KEY = '_thinkrank_title_experiment';

    /**
     * Option listing post ids with an experiment running or measuring.
     */
    public const RUNNING_OPTION = 'thinkrank_title_experiments_running';

    /**
     * Option listing post ids of the most recently finished experiments,
     * newest first. Kept by the lifecycle itself, because finishing a test
     * only writes post meta and never moves the post's modified date.
     */
    public const RECENT_OPTION = 'thinkrank_title_experiments_recent';

    /**
     * Finished experiments kept in the recent list.
     */
    public const RECENT_LIMIT = 10;

    /**
     * Daily event. Listed in cleanup-manifest.php.
     */
    public const CRON_HOOK = 'thinkrank_title_experiments_advance';

    /**
     * Days a measurement is retried (once a day) before the experiment is
     * closed as inconclusive — Search Console down for a week is not a result.
     */
    private const MAX_MEASURE_ATTEMPTS = 7;

    /**
     * Search Console requests one run of the daily event may make. A test
     * costs 2 requests for the original window plus 2 per variant, so this
     * is a few tests per run; the rest wait for a follow-up run.
     */
    public const MAX_REQUESTS_PER_RUN = 20;

    /**
     * Seconds before a follow-up run picks up the tests a capped run left.
     */
    public const CONTINUE_AFTER = 5 * MINUTE_IN_SECONDS;

    /**
     * Statuses.
     *
     * @var string[]
     */
    public const STATUSES = ['running', 'measuring', 'complete', 'stopped'];

    /**
     * Why a test the plugin ended on its own was stopped, kept in
     * `stopped_reason`. A test the owner stopped has none.
     *
     * @since 2.22.0
     *
     * @var string[]
     */
    public const STOP_REASONS = ['unpublished', 'url_changed', 'unservable'];

    /**
     * Search Console reader.
     *
     * @var Experiment_Measurer
     */
    private Experiment_Measurer $measurer;

    /**
     * Today, as `Y-m-d` UTC; injectable for tests.
     *
     * @var callable
     */
    private $today;

    /**
     * @param Experiment_Measurer|null $measurer Search Console reader.
     * @param callable|null            $today    () => 'Y-m-d'.
     */
    public function __construct(?Experiment_Measurer $measurer = null, ?callable $today = null) {
        $this->measurer = $measurer ?? new Experiment_Measurer();
        $this->today    = $today ?? static fn(): string => gmdate('Y-m-d');
    }

    /**
     * Serve variants, and advance experiments daily.
     *
     * @return void
     */
    public function init(): void {
        add_filter('thinkrank_served_title', [$this, 'serve_title'], 10, 2);
        add_filter('thinkrank_served_description', [$this, 'serve_description'], 10, 2);
        add_action(self::CRON_HOOK, [$this, 'advance']);
        add_action('transition_post_status', [$this, 'on_status_change'], 10, 3);
        add_action('before_delete_post', [$this, 'forget']);
        add_action('post_updated', [$this, 'on_post_updated'], 10, 1);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    // ─── Reading ──────────────────────────────────────────────────────

    /**
     * The experiment on a post — running, or the last one to finish.
     *
     * @param int $post_id Post id.
     * @return array<string, mixed>|null
     */
    public function get(int $post_id): ?array {
        $record = get_post_meta($post_id, self::META_KEY, true);

        return is_array($record) && isset($record['status']) ? $record : null;
    }

    /**
     * Experiments running or measuring.
     *
     * @return array<int, array<string, mixed>>
     */
    public function active(): array {
        $out = [];
        foreach ($this->running_ids() as $post_id) {
            $record = $this->get($post_id);
            if (null !== $record && in_array($record['status'], ['running', 'measuring'], true)) {
                $out[] = $record;
            }
        }

        return $out;
    }

    /**
     * The most recently finished experiments, newest first.
     *
     * @since 2.22.0
     *
     * @param int $limit Most to return.
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = self::RECENT_LIMIT): array {
        $ids = get_option(self::RECENT_OPTION, []);
        $out = [];

        foreach (is_array($ids) ? $ids : [] as $post_id) {
            $record = $this->get((int) $post_id);
            if (null !== $record && in_array($record['status'], ['complete', 'stopped'], true)) {
                $out[] = $record;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Whether a post's SEO title and description are locked by a test.
     *
     * @param int $post_id Post id.
     * @return bool
     */
    public function is_locked(int $post_id): bool {
        return self::locks($post_id);
    }

    /**
     * Whether a post's SEO title and description are locked by a test, for
     * callers that write those fields without a service instance (the
     * metabox, Quick Edit, the snippets editor).
     *
     * @since 2.22.0
     *
     * @param int $post_id Post id.
     * @return bool
     */
    public static function locks(int $post_id): bool {
        if ($post_id <= 0) {
            return false;
        }

        $record = get_post_meta($post_id, self::META_KEY, true);

        return is_array($record) && in_array($record['status'] ?? '', ['running', 'measuring'], true);
    }

    /**
     * The locked fields a save would change: form field names
     * (`thinkrank_seo_title`, `thinkrank_meta_description`) whose submitted
     * value differs from what is stored, while a test runs. Forms that always
     * post every field (Quick Edit) resend the stored value unchanged, and
     * that is not an edit worth refusing (#1636).
     *
     * @since 2.22.0
     *
     * @param int                  $post_id Post id.
     * @param array<string, mixed> $fields  Form field name => submitted value.
     * @return string[] Field names that would change a locked value.
     */
    public static function locked_changes(int $post_id, array $fields): array {
        if (!self::locks($post_id)) {
            return [];
        }

        $changed = [];
        foreach (['thinkrank_seo_title' => Pattern_Resolver::META_TITLE, 'thinkrank_meta_description' => Pattern_Resolver::META_DESCRIPTION] as $field => $meta_key) {
            if (array_key_exists($field, $fields) && trim((string) $fields[$field]) !== trim((string) get_post_meta($post_id, $meta_key, true))) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Why a locked field was not saved, for every writer that refuses one.
     *
     * @since 2.22.0
     *
     * @return string
     */
    public static function locked_message(): string {
        return __('A title experiment is running on this page, so its SEO title and meta description are locked until it finishes. Stop the experiment in the editor to change them.', 'thinkrank');
    }

    // ─── Starting and ending ──────────────────────────────────────────

    /**
     * Start an experiment: serve the first variant from today.
     *
     * @param int                                   $post_id  Post id.
     * @param array<int, array<string, string>>     $variants Each {title, description ('' keeps the original), source: ai|manual}.
     *                                                        One in the free plugin; Pro allows up to three.
     * @param int                                   $window   Days measured per variant.
     * @return array<string, mixed>|WP_Error The experiment.
     */
    public function create(int $post_id, array $variants, int $window = Experiment_Schedule::DEFAULT_WINDOW) {
        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status || !is_post_type_viewable($post->post_type)) {
            return new WP_Error('thinkrank_experiment_not_public', __('Only a published, public page can be tested: Google has to be able to show it.', 'thinkrank'), ['status' => 400]);
        }

        $unservable = self::unservable($post);
        if (null !== $unservable) {
            return $unservable;
        }

        if (!$this->measurer->available()) {
            return new WP_Error('thinkrank_experiment_needs_gsc', __('Title experiments are measured on Google Search Console clicks. Connect Search Console first (Integrations → Google Services).', 'thinkrank'), ['status' => 400]);
        }

        if ($this->is_locked($post_id)) {
            return new WP_Error('thinkrank_experiment_exists', __('This page already has an experiment running.', 'thinkrank'), ['status' => 409]);
        }

        $too_soon = $this->too_soon($post_id, $window);
        if (null !== $too_soon) {
            return $too_soon;
        }

        $caps  = Plan_Config::capabilities_for('title_experiments');
        $limit = max(1, (int) ($caps['concurrent'] ?? 1));
        if (count($this->active()) >= $limit) {
            return new WP_Error(
                'thinkrank_experiment_limit',
                1 === $limit
                    ? __('One experiment runs at a time. Stop or finish the running one first.', 'thinkrank')
                    : sprintf(
                        /* translators: %d: number of experiments */
                        __('Up to %d experiments run at a time. Stop or finish one first.', 'thinkrank'),
                        $limit
                    ),
                ['status' => 409]
            );
        }

        $original = [
            'title'       => Pattern_Resolver::effective_title($post_id),
            'description' => Pattern_Resolver::effective_description($post_id),
        ];

        $clean = self::clean_variants($variants, $original);
        if (is_wp_error($clean)) {
            return $clean;
        }

        // max_variants counts the original.
        $allowed = max(1, (int) ($caps['max_variants'] ?? 2) - 1);
        if (count($clean) > $allowed) {
            return new WP_Error(
                'thinkrank_experiment_too_many_variants',
                1 === $allowed
                    ? __('Test one new title at a time.', 'thinkrank')
                    : sprintf(
                        /* translators: %d: number of titles */
                        __('Test up to %d new titles at a time.', 'thinkrank'),
                        $allowed
                    ),
                ['status' => 400]
            );
        }

        $record = [
            'post_id'      => $post_id,
            'url'          => (string) get_permalink($post),
            'status'       => 'running',
            'created_at'   => time(),
            'plan'         => Experiment_Schedule::plan(($this->today)(), $window, count($clean)),
            'original'     => $original,
            'variants'     => $clean,
            'result'       => null,
            'applied'      => null,
            'attempts'     => 0,
            'error'        => '',
            'served_index' => 0,
        ];

        $this->save($record);
        $this->set_running($post_id, true);
        Page_Cache::purge_post($post_id);

        return $record;
    }

    /**
     * Refuse a test whose original window would overlap the page's last test.
     *
     * The original's window is the `window` days right before the start, on
     * the assumption that the page served its current title all along. Right
     * after a test that served a variant (stopped or finished), or whose
     * winner was just kept, those days include another title, and the lift
     * would be measured against a mixed baseline (#1640).
     *
     * @since 2.22.0
     *
     * @param int $post_id Post id.
     * @param int $window  Days per window, as asked for.
     * @return WP_Error|null
     */
    private function too_soon(int $post_id, int $window): ?WP_Error {
        $last = $this->get($post_id);
        if (null === $last) {
            return null;
        }

        // UTC dates, the unit the windows are counted in. `finished_on` and
        // `applied_on` arrived with this check; an older record falls back
        // to its finish timestamp.
        $changed = max(
            (string) ($last['finished_on'] ?? (empty($last['finished_at']) ? '' : gmdate('Y-m-d', (int) $last['finished_at']))),
            (string) ($last['applied_on'] ?? '')
        );
        if ('' === $changed) {
            return null;
        }

        // The original window would be [start - window, start - 1]; it has to
        // begin the day after the last change.
        $window   = Experiment_Schedule::clamp_window($window);
        $earliest = gmdate('Y-m-d', (int) strtotime($changed . ' UTC +' . ($window + 1) . ' days'));
        if (($this->today)() >= $earliest) {
            return null;
        }

        // A shorter window is advice only while there is one to choose
        // (#1683).
        $message = $window > Experiment_Schedule::MIN_WINDOW
            /* translators: 1: number of days, 2: date (YYYY-MM-DD) */
            ? __('This page changed its title within the last %1$d days, so those days cannot stand for the current title. Start a %1$d-day test on or after %2$s, or choose a shorter window.', 'thinkrank')
            /* translators: 1: number of days, 2: date (YYYY-MM-DD) */
            : __('This page changed its title within the last %1$d days, so those days cannot stand for the current title. Start a %1$d-day test on or after %2$s.', 'thinkrank');

        return new WP_Error(
            'thinkrank_experiment_too_soon',
            sprintf($message, $window, $earliest),
            ['status' => 409, 'earliest' => $earliest]
        );
    }

    /**
     * Why a published page still cannot be tested, if it cannot.
     *
     * The variant reaches the page only through SEO_Manager's served-title
     * filter, which runs on singular requests with metas on. A page served
     * as an archive (the posts page, the WooCommerce Shop page) or of a type
     * whose metas are off keeps its original title, so both windows would
     * measure the same title; a noindexed page is never shown by Google, so
     * there is nothing to measure (#1637).
     *
     * @since 2.22.0
     *
     * @param \WP_Post|object $post Post.
     * @return WP_Error|null
     */
    private static function unservable($post): ?WP_Error {
        $post_id = (int) $post->ID;

        $archives = [(int) get_option('page_for_posts', 0)];
        if (function_exists('wc_get_page_id')) {
            $archives[] = (int) wc_get_page_id('shop');
        }
        if (in_array($post_id, array_filter($archives), true)) {
            return new WP_Error('thinkrank_experiment_archive_page', __('This page is shown as a list of posts or products, so a test title cannot be served on it. Test a single post or page instead.', 'thinkrank'), ['status' => 400]);
        }

        if (class_exists(Content_Type_Settings::class)
            && !Content_Type_Settings::is_enabled(Content_Type_Settings::FEATURE_META, (string) $post->post_type, true)
        ) {
            return new WP_Error('thinkrank_experiment_metas_off', __('SEO titles are switched off for this content type, so a test title would never be served. Turn them on under Content Types first.', 'thinkrank'), ['status' => 400]);
        }

        if ($post instanceof \WP_Post && class_exists(Indexability::class) && Indexability::is_post_noindexed($post)) {
            return new WP_Error('thinkrank_experiment_noindex', __('This page is set to noindex, so Google does not show it and there are no clicks to measure.', 'thinkrank'), ['status' => 400]);
        }

        return null;
    }

    /**
     * Sanitise variants and refuse ones that would test nothing.
     *
     * @param array                 $variants Raw variants.
     * @param array<string, string> $original The page's current title and description.
     * @return array<int, array{title: string, description: string, source: string}>|WP_Error
     */
    private static function clean_variants(array $variants, array $original) {
        $clean = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $title       = trim(Pattern_Resolver::sanitize_template((string) ($variant['title'] ?? '')));
            $description = trim(Pattern_Resolver::sanitize_template((string) ($variant['description'] ?? '')));
            $source      = (string) ($variant['source'] ?? 'manual');

            if ('' === $title) {
                return new WP_Error('thinkrank_experiment_no_title', __('Enter the title to test.', 'thinkrank'), ['status' => 400]);
            }
            if (0 === strcasecmp($title, $original['title']) && ('' === $description || 0 === strcasecmp($description, $original['description']))) {
                return new WP_Error('thinkrank_experiment_same_title', __('The variant is the same as the current title, so there would be nothing to compare.', 'thinkrank'), ['status' => 400]);
            }
            foreach ($clean as $earlier) {
                if (0 === strcasecmp($earlier['title'], $title) && 0 === strcasecmp($earlier['description'], $description)) {
                    return new WP_Error('thinkrank_experiment_duplicate_variant', __('Two of the new titles are the same.', 'thinkrank'), ['status' => 400]);
                }
            }

            $clean[] = [
                'title'       => $title,
                'description' => $description,
                'source'      => in_array($source, ['ai', 'manual'], true) ? $source : 'manual',
            ];
        }

        if ([] === $clean) {
            return new WP_Error('thinkrank_experiment_no_title', __('Enter the title to test.', 'thinkrank'), ['status' => 400]);
        }

        return $clean;
    }

    /**
     * End an experiment early. Nothing is restored because nothing was
     * changed: the stored title and description were never touched.
     *
     * @param int    $post_id Post id.
     * @param string $reason  Why the test ended early, when it was not the
     *                        owner's choice: one of {@see self::STOP_REASONS}.
     * @return array<string, mixed>|WP_Error
     */
    public function stop(int $post_id, string $reason = '') {
        $record = $this->get($post_id);
        if (null === $record || !in_array($record['status'], ['running', 'measuring'], true)) {
            return new WP_Error('thinkrank_experiment_not_running', __('There is no running experiment on this page.', 'thinkrank'), ['status' => 404]);
        }

        $record['status']      = 'stopped';
        $record['finished_at'] = time();
        $record['finished_on'] = ($this->today)();
        if ('' !== $reason) {
            $record['stopped_reason'] = $reason;
        }
        $this->save($record);
        $this->set_running($post_id, false);
        $this->remember_finished($post_id);
        Page_Cache::purge_post($post_id);

        return $record;
    }

    /**
     * Keep a variant — the winner unless another is named: write it into the
     * page's SEO fields. The one write an experiment makes, and only when the
     * owner asks (or, in Pro, has said to do it automatically).
     *
     * @param int      $post_id Post id.
     * @param int|null $index   Variant to keep; null for the winner.
     * @return array<string, mixed>|WP_Error
     */
    public function apply(int $post_id, ?int $index = null) {
        $record = $this->get($post_id);
        if (null === $record || 'complete' !== $record['status']) {
            return new WP_Error('thinkrank_experiment_not_complete', __('Only a finished experiment can be applied.', 'thinkrank'), ['status' => 409]);
        }

        // Pro applies the winner from the daily event, long after anyone
        // looked at the page; a page taken down since is left as it is.
        if ('publish' !== get_post_status($post_id)) {
            return new WP_Error('thinkrank_experiment_not_public', __('This page is no longer published, so its title was not changed.', 'thinkrank'), ['status' => 409]);
        }

        $index = $index ?? ($record['result']['winner'] ?? null);
        if (null === $index || !isset($record['variants'][$index])) {
            return new WP_Error('thinkrank_experiment_no_winner', __('No new title won, so there is nothing to keep. The original stays.', 'thinkrank'), ['status' => 409]);
        }

        $variant = $record['variants'][$index];
        update_post_meta($post_id, Pattern_Resolver::META_TITLE, wp_slash($variant['title']));
        if ('' !== $variant['description']) {
            update_post_meta($post_id, Pattern_Resolver::META_DESCRIPTION, wp_slash($variant['description']));
        }

        $record['applied'] = (int) $index;
        // The day the page's own title changed: a later test's original
        // window has to start after it (#1640).
        $record['applied_on'] = ($this->today)();
        $this->save($record);
        Page_Cache::purge_post($post_id);

        /**
         * Fires after a variant was written into the page's SEO fields.
         *
         * @since 2.22.0
         *
         * @param array<string, mixed> $record The experiment, with `applied` set.
         */
        do_action('thinkrank_title_experiment_applied', $record);

        return $record;
    }

    /**
     * End the test on a page that stops being public. Google can no longer
     * show it, so there is nothing to measure, and a test left running would
     * hold a slot the owner cannot free from a trashed page's editor (#1635).
     *
     * @since 2.22.0
     *
     * @param string $new_status New status.
     * @param string $old_status Old status.
     * @param mixed  $post       The post.
     * @return void
     */
    public function on_status_change($new_status, $old_status, $post): void {
        if ('publish' === $new_status || 'publish' !== $old_status || !is_object($post) || empty($post->ID)) {
            return;
        }

        if ($this->is_locked((int) $post->ID)) {
            $this->stop((int) $post->ID, 'unpublished');
        }
    }

    /**
     * End the test on a page whose address changed. It is measured on the URL
     * Google indexed when it started; after a slug change the clicks go to a
     * new URL and the variant's window would read an empty old one (#1638).
     * The editor and the abilities refuse a slug change during a test, so this
     * catches the routes they cannot (core's own slug box, Quick Edit, code).
     *
     * @since 2.22.0
     *
     * @param mixed $post_id Post updated.
     * @return void
     */
    public function on_post_updated($post_id): void {
        $post_id = (int) $post_id;
        // Leaving publish is on_status_change()'s to report.
        if (!$this->is_locked($post_id) || 'publish' !== get_post_status($post_id)) {
            return;
        }

        $record = $this->get($post_id);
        if (null !== $record && self::url_moved($record)) {
            $this->stop($post_id, 'url_changed');
        }
    }

    /**
     * Whether the page no longer answers at the URL its test is measured on.
     *
     * A page's address also moves without the page being saved: its parent
     * page is renamed, its categories are set after the save (the REST API
     * does that), a category slug or the permalink structure changes. Those
     * never reach on_post_updated(), so the daily step asks too (#1680).
     *
     * The scheme is not part of the address here. get_permalink() follows
     * is_ssl() when the home URL is http://, so a test started from an admin
     * served over HTTPS stores https:// while the daily step, under WP-CLI or
     * an http cron request, reads http:// for the same page (#1705).
     *
     * @since 2.22.0
     *
     * @param array<string, mixed> $record Experiment.
     * @return bool
     */
    private static function url_moved(array $record): bool {
        $url = get_permalink((int) $record['post_id']);

        return is_string($url) && '' !== (string) ($record['url'] ?? '')
            && self::comparable_url($url) !== self::comparable_url((string) $record['url']);
    }

    /**
     * A URL as url_moved() compares it: one scheme, no trailing slash.
     *
     * @since 2.22.0
     *
     * @param string $url URL.
     * @return string
     */
    private static function comparable_url(string $url): string {
        return untrailingslashit(set_url_scheme($url, 'https'));
    }

    /**
     * Take a deleted page off both lists. Its meta goes with it, so only the
     * ids would be left behind.
     *
     * @since 2.22.0
     *
     * @param mixed $post_id Post being deleted.
     * @return void
     */
    public function forget($post_id): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        if (in_array($post_id, $this->running_ids(), true)) {
            $this->set_running($post_id, false);
        }

        $recent = get_option(self::RECENT_OPTION, []);
        if (is_array($recent) && in_array($post_id, array_map('intval', $recent), true)) {
            update_option(self::RECENT_OPTION, array_values(array_diff(array_map('intval', $recent), [$post_id])), false);
        }
    }

    // ─── Serving ──────────────────────────────────────────────────────

    /**
     * The variant title, while the page is serving it.
     *
     * @param mixed $value   Earlier override.
     * @param mixed $post_id Page being served.
     * @return mixed
     */
    public function serve_title($value, $post_id) {
        $variant = $this->serving((int) $post_id);

        return null === $variant ? $value : Pattern_Resolver::resolve_value($variant['title'], (int) $post_id);
    }

    /**
     * The variant description, while the page is serving it and one was set.
     *
     * @param mixed $value   Earlier override.
     * @param mixed $post_id Page being served.
     * @return mixed
     */
    public function serve_description($value, $post_id) {
        $variant = $this->serving((int) $post_id);

        if (null === $variant || '' === $variant['description']) {
            return $value;
        }

        return Pattern_Resolver::resolve_value($variant['description'], (int) $post_id);
    }

    /**
     * The variant a page is serving today, if any.
     *
     * @param int $post_id Post id.
     * @return array{title: string, description: string}|null
     */
    private function serving(int $post_id): ?array {
        if ($post_id <= 0 || !in_array($post_id, $this->running_ids(), true)) {
            return null;
        }

        $record = $this->get($post_id);
        if (null === $record || 'running' !== $record['status']) {
            return null;
        }

        $index = self::serving_index($record, ($this->today)());

        return null === $index ? null : ($record['variants'][$index] ?? null);
    }

    /**
     * Which variant a running test serves on a day, if any.
     *
     * @since 2.22.0
     *
     * @param array<string, mixed> $record Experiment.
     * @param string               $today  `Y-m-d`.
     * @return int|null
     */
    private static function serving_index(array $record, string $today): ?int {
        foreach ((array) ($record['plan']['variants'] ?? []) as $i => $window) {
            if ($today >= $window['serve_from'] && $today <= $window['to']) {
                return (int) $i;
            }
        }

        return null;
    }

    // ─── The daily step ───────────────────────────────────────────────

    /**
     * Move every experiment along: stop serving a variant whose window is
     * over, and measure the ones whose data has arrived.
     *
     * @return void
     */
    public function advance(): void {
        $today    = ($this->today)();
        $spent    = 0;
        $deferred = false;

        foreach ($this->active() as $record) {
            $post_id = (int) $record['post_id'];

            // A page unpublished by a route that skipped the status hook
            // (a direct database write, an import) is ended here rather
            // than measured and, in Pro, applied to (#1635).
            if ('publish' !== get_post_status($post_id)) {
                $this->stop($post_id, 'unpublished');
                continue;
            }

            // An address that moved without the page being saved is ended
            // here, before its old URL is measured (#1680).
            if (self::url_moved($record)) {
                $this->stop($post_id, 'url_changed');
                continue;
            }

            // A page that can no longer serve the title (made noindex, metas
            // switched off, made the posts or Shop page) would be measured on
            // a title Google never shows, so a running test ends here. Once
            // measuring, the windows are over and their data stands (#1681).
            $post = get_post($post_id);
            if ('running' === $record['status'] && is_object($post) && null !== self::unservable($post)) {
                $this->stop($post_id, 'unservable');
                continue;
            }

            // With several titles each is served in turn, and the page cache
            // still holds the one before. Purge when the served title
            // changes, not only when the last one ends (#1639).
            if ('running' === $record['status']) {
                $index = self::serving_index($record, $today);
                if (null !== $index && $index !== (int) ($record['served_index'] ?? 0)) {
                    $record['served_index'] = $index;
                    $this->save($record);
                    Page_Cache::purge_post($post_id);
                }
            }

            $last = end($record['plan']['variants']);
            if ('running' === $record['status'] && $today > $last['to']) {
                $record['status'] = 'measuring';
                $this->save($record);
                Page_Cache::purge_post($post_id);
            }

            // A failed measurement is retried once a day: attempts count
            // days, and a follow-up run on the same day must reach the
            // tests still waiting instead of re-asking for the ones that
            // just failed (#1641).
            if ('measuring' === $record['status'] && $today >= $record['plan']['decide_on'] && $today !== (string) ($record['last_attempt_on'] ?? '')) {
                // Every measurement is a handful of synchronous Google calls.
                // Past the budget, leave the test measuring for the follow-up
                // run; one test is always measured so a run makes progress.
                $cost = 2 + 2 * count($record['plan']['variants']);
                if ($spent > 0 && $spent + $cost > self::MAX_REQUESTS_PER_RUN) {
                    $deferred = true;
                    continue;
                }
                $spent += $cost;

                $record['last_attempt_on'] = $today;
                $record                    = $this->measure($record);
                $this->save($record);

                if ('complete' === $record['status']) {
                    $record['finished_at'] = time();
                    $record['finished_on'] = $today;
                    $this->save($record);
                    $this->set_running($post_id, false);
                    $this->remember_finished($post_id);

                    /**
                     * Fires when an experiment has its verdict.
                     *
                     * ThinkRank Pro keeps history and, when asked to, applies
                     * the winner. `result.winner` is the winning variant's
                     * index, or null when the original stays.
                     *
                     * @since 2.22.0
                     *
                     * @param array<string, mixed>  $record      The experiment.
                     * @param Title_Experiments     $experiments This service.
                     */
                    do_action('thinkrank_title_experiment_completed', $record, $this);
                }
            }
        }

        if ($deferred) {
            wp_schedule_single_event(time() + self::CONTINUE_AFTER, self::CRON_HOOK);
        }
    }

    /**
     * Measure the original window and each variant's window, and decide.
     *
     * @param array<string, mixed> $record Experiment.
     * @return array<string, mixed> The experiment, complete or with the error.
     */
    private function measure(array $record): array {
        $plan = $record['plan'];
        $url  = (string) $record['url'];

        try {
            $original = $this->measurer->totals($plan['original']['from'], $plan['original']['to'], $url);
            $site_o   = $this->measurer->totals($plan['original']['from'], $plan['original']['to']);
            $control_o = Experiment_Verdict::control_ctr($site_o, $original);

            $per_variant = [];
            foreach ($plan['variants'] as $window) {
                $page      = $this->measurer->totals($window['from'], $window['to'], $url);
                $site      = $this->measurer->totals($window['from'], $window['to']);
                $control_v = Experiment_Verdict::control_ctr($site, $page);

                $per_variant[] = Experiment_Verdict::decide($original, $page, $control_o, $control_v) + [
                    'window'  => $page,
                    'control' => round($control_v, 5),
                ];
            }
        } catch (\Throwable $e) {
            $record['attempts'] = (int) $record['attempts'] + 1;
            $record['error']    = $e->getMessage();

            if ($record['attempts'] >= self::MAX_MEASURE_ATTEMPTS) {
                $record['status'] = 'complete';
                $record['result'] = [
                    'outcome' => 'inconclusive',
                    'reason'  => 'measurement_failed',
                    'lift'    => null,
                    'winner'  => null,
                ];
            }

            return $record;
        }

        $record['status'] = 'complete';
        $record['error']  = '';
        $record['result'] = Experiment_Verdict::overall($per_variant) + [
            'original_window'  => $original,
            'original_control' => round($control_o, 5),
            'variants'         => $per_variant,
            'measured_at'      => time(),
        ];

        return $record;
    }

    // ─── Storage ──────────────────────────────────────────────────────

    /**
     * Store an experiment on its post.
     *
     * @param array<string, mixed> $record Experiment.
     * @return void
     */
    private function save(array $record): void {
        // update_post_meta() unslashes; titles with a backslash would change.
        update_post_meta((int) $record['post_id'], self::META_KEY, wp_slash($record));
    }

    /**
     * Post ids with an experiment running or measuring.
     *
     * @return int[]
     */
    private function running_ids(): array {
        $ids = get_option(self::RUNNING_OPTION, []);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * Put a post at the head of the recently finished list.
     *
     * @param int $post_id Post id.
     * @return void
     */
    private function remember_finished(int $post_id): void {
        $ids = get_option(self::RECENT_OPTION, []);
        $ids = array_values(array_diff(is_array($ids) ? array_map('intval', $ids) : [], [$post_id]));
        array_unshift($ids, $post_id);

        update_option(self::RECENT_OPTION, array_slice($ids, 0, self::RECENT_LIMIT), false);
    }

    /**
     * Add a post to, or take it off, the running list.
     *
     * @param int  $post_id Post id.
     * @param bool $running Running.
     * @return void
     */
    private function set_running(int $post_id, bool $running): void {
        $ids = array_values(array_diff($this->running_ids(), [$post_id]));
        if ($running) {
            $ids[] = $post_id;
        }

        update_option(self::RUNNING_OPTION, $ids, true);
    }
}
