<?php
/**
 * What the SEO Timeline records, and what it refuses to.
 *
 * @package ThinkRank
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Timeline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes one row per change that can move search traffic.
 *
 * The question this exists to answer is "what changed?", asked after a drop.
 * Support ticket #171748 is the shape of the problem: a site owner reported
 * losing traffic after installing the plugin, and nobody could say what had
 * changed, when, or on which pages. The ticket closed without an answer.
 *
 * Three rules hold the cost down:
 *
 * **Only on change, where a before is available.** The option, status, slug,
 * version and settings recorders each compare before and after and return
 * without writing when they match; a normal request writes nothing at all. A
 * ThinkRank settings save passes the settings in effect before the write
 * (stored values over the defaults) beside the ones written, so its event names
 * the section and only the keys whose value moved.
 *
 * **One row per request per kind.** Editing 42 posts in bulk is one event
 * saying so, not 42 — the Timeline is for reading, and 42 identical lines are
 * worse than one line with a count. Batching is per request, flushed on
 * `shutdown`, so a bulk edit that spans several requests produces one row each
 * rather than one row per post.
 *
 * **Values never, names always.** A setting event records which section and
 * which keys changed and never what they changed to. That is not only about
 * secrets: a Timeline is read by whoever can see the screen, and the SEO
 * settings include API keys. Recording names sidesteps the question of which
 * values are safe entirely, and SECRET_KEY_FRAGMENTS is a second line of
 * defence for anything a future caller passes in by hand.
 *
 * @since 2.22.0
 */
class Event_Recorder {

    /**
     * Event types this class records.
     *
     * Declared as constants because the REST filter, the ability enum and the
     * UI legend all need the same list, and three hand-maintained copies is how
     * a type ends up unfilterable.
     *
     * @since 2.22.0
     * @var string
     */
    public const SEARCH_VISIBILITY   = 'search_visibility';
    public const PERMALINK_STRUCTURE = 'permalink_structure';
    public const PLUGIN_UPDATED      = 'plugin_updated';
    public const THEME_UPDATED       = 'theme_updated';
    public const CORE_UPDATED        = 'core_updated';
    public const PLUGIN_ACTIVATED    = 'plugin_activated';
    public const PLUGIN_DEACTIVATED  = 'plugin_deactivated';
    public const THEME_SWITCHED      = 'theme_switched';
    public const POST_STATUS         = 'post_status';
    public const POST_SLUG           = 'post_slug';
    public const POST_SEO_FIELDS     = 'post_seo_fields';
    public const SETTINGS_CHANGED    = 'settings_changed';
    public const ROBOTS_TXT          = 'robots_txt';
    public const LLMS_TXT            = 'llms_txt';

    /**
     * Every type, for the filters and the ability enum.
     *
     * @since 2.22.0
     *
     * @return array<int,string>
     */
    public static function types(): array {
        return [
            self::SEARCH_VISIBILITY,
            self::PERMALINK_STRUCTURE,
            self::PLUGIN_UPDATED,
            self::THEME_UPDATED,
            self::CORE_UPDATED,
            self::PLUGIN_ACTIVATED,
            self::PLUGIN_DEACTIVATED,
            self::THEME_SWITCHED,
            self::POST_STATUS,
            self::POST_SLUG,
            self::POST_SEO_FIELDS,
            self::SETTINGS_CHANGED,
            self::ROBOTS_TXT,
            self::LLMS_TXT,
        ];
    }

    /**
     * Post meta keys whose change is an SEO change worth a row.
     *
     * @since 2.22.0
     * @var array<int,string>
     */
    public const WATCHED_POST_META = [
        '_thinkrank_seo_title',
        '_thinkrank_meta_description',
        '_thinkrank_canonical_url',
        // The override switch decides whether the robots blobs apply at all
        // (SEO_Manager::apply_post_robots_override()), so turning it on or off
        // can noindex a page with neither blob moving.
        '_thinkrank_robots_meta_enabled',
        '_thinkrank_robots_meta',
        '_thinkrank_advanced_robots_meta',
        '_thinkrank_focus_keyword',
        '_thinkrank_selected_schema_type',
        '_thinkrank_schema_disabled',
    ];

    /**
     * Watched post meta that is an on/off switch, stored as 1 or 0.
     *
     * A switch written as off for the first time is the same as no row: the
     * editor posts the robots override switch on every save, so a post that
     * never stored it reads as changed on its next Update otherwise.
     *
     * @since 2.22.0
     * @var array<int,string>
     */
    public const SWITCH_POST_META = ['_thinkrank_robots_meta_enabled'];

    /**
     * Fragments that mark a settings key as holding a secret.
     *
     * @since 2.22.0
     * @var array<int,string>
     */
    public const SECRET_KEY_FRAGMENTS = ['key', 'token', 'secret', 'password', 'credential', 'nonce', 'salt'];

    /**
     * Settings keys the code stamps on its own settings, never a person.
     *
     * Saving the Robots.txt screen regenerates the sitemap inside the same
     * admin request, so the stamp arrives with an actor and is_unattended()
     * cannot tell it from a change somebody made. `last_updated` is the SEO
     * Settings store's write time, set by save_settings_by_category() on every
     * Settings Management write for the categories it shares (sitemap), so an
     * unchanged save, an import or a reset read as "1 setting saved in SEO
     * Settings" (#1706). The same store stamps `imported_at` on an import and
     * `reset_at` on a reset; its sanitizer drops both today, and they are
     * listed so that stays true if it ever keeps them.
     *
     * @since 2.22.0
     * @var array<int,string>
     */
    public const GENERATED_SETTING_KEYS = ['last_generated', 'last_updated', 'imported_at', 'reset_at'];

    /**
     * Cron hook that drops events past the retention window.
     *
     * @since 2.22.0
     * @var string
     */
    public const PRUNE_HOOK = 'thinkrank_timeline_prune';

    /**
     * Plugin slugs whose activation state is a warning rather than information.
     *
     * Another SEO plugin and a caching plugin are the two things that most
     * often explain a change nobody made on purpose.
     *
     * @since 2.22.0
     * @var array<int,string>
     */
    public const LOUD_PLUGIN_FRAGMENTS = [
        'seo', 'cache', 'caching', 'rocket', 'optimize', 'speed', 'cdn', 'redirect', 'sitemap',
    ];

    /**
     * Events queued for this request, keyed so identical ones collapse.
     *
     * @since 2.22.0
     * @var array<string,array<string,mixed>>
     */
    private static array $queue = [];

    /**
     * Plugin versions read before an upgrade replaces them.
     *
     * @since 2.22.0
     * @var array<string,string>
     */
    private static array $versions_before = [];

    /**
     * Posts being deleted in this request.
     *
     * wp_delete_post() removes the meta rows one by one after
     * `before_delete_post`, each firing `deleted_post_meta` while the post
     * still reads as published. Those are the delete, not SEO field edits.
     *
     * @since 2.22.0
     * @var array<int,bool>
     */
    private static array $deleting = [];

    /**
     * Register the hooks.
     *
     * @since 2.22.0
     *
     * @return void
     */
    public function init(): void {
        add_action('update_option_blog_public', [self::class, 'on_search_visibility'], 10, 2);
        add_action('update_option_permalink_structure', [self::class, 'on_permalink_structure'], 10, 2);

        add_filter('upgrader_pre_install', [self::class, 'remember_versions'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'on_upgrade'], 10, 2);

        add_action('activated_plugin', [self::class, 'on_plugin_activated'], 10, 1);
        add_action('deactivated_plugin', [self::class, 'on_plugin_deactivated'], 10, 1);
        add_action('switch_theme', [self::class, 'on_theme_switched'], 10, 3);

        add_action('transition_post_status', [self::class, 'on_post_status'], 10, 3);
        add_action('post_updated', [self::class, 'on_post_updated'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_post_deleted'], 10, 2);
        add_action('updated_post_meta', [self::class, 'on_post_meta'], 10, 4);
        add_action('added_post_meta', [self::class, 'on_post_meta_added'], 10, 4);
        // Clearing a field deletes the row (the metabox does this for an
        // emptied canonical), which updated_post_meta never sees.
        add_action('deleted_post_meta', [self::class, 'on_post_meta'], 10, 4);

        add_action('thinkrank_seo_settings_saved', [self::class, 'on_settings_saved'], 10, 5);

        add_action('shutdown', [self::class, 'flush'], 100);

        add_action(self::PRUNE_HOOK, [Event_Repository::class, 'prune']);

        if (!wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    // -----------------------------------------------------------------------
    // Site-wide switches
    // -----------------------------------------------------------------------

    /**
     * "Discourage search engines" was toggled.
     *
     * @since 2.22.0
     *
     * @param mixed $old     Previous value.
     * @param mixed $current New value.
     * @return void
     */
    public static function on_search_visibility($old, $current): void {
        if ((string) $old === (string) $current) {
            return;
        }

        $discouraged = empty($current);

        self::queue([
            'event_type' => self::SEARCH_VISIBILITY,
            // The single most damaging switch on the site, and the one most
            // often flipped by accident on a staging copy that went live.
            'severity'   => $discouraged ? 'critical' : 'warning',
            'summary'    => $discouraged
                ? __('Search engines were discouraged from indexing this site', 'thinkrank')
                : __('Search engines were allowed to index this site again', 'thinkrank'),
            'meta'       => ['blog_public' => (int) $current],
        ]);
    }

    /**
     * The permalink structure changed, so every URL on the site did.
     *
     * @since 2.22.0
     *
     * @param mixed $old     Previous structure.
     * @param mixed $current New structure.
     * @return void
     */
    public static function on_permalink_structure($old, $current): void {
        if ((string) $old === (string) $current) {
            return;
        }

        self::queue([
            'event_type' => self::PERMALINK_STRUCTURE,
            'severity'   => 'critical',
            'summary'    => sprintf(
                /* translators: 1: previous permalink structure, 2: new permalink structure. */
                __('Permalink structure changed from %1$s to %2$s', 'thinkrank'),
                '' === (string) $old ? __('plain', 'thinkrank') : (string) $old,
                '' === (string) $current ? __('plain', 'thinkrank') : (string) $current
            ),
            'meta'       => ['from' => (string) $old, 'to' => (string) $current],
        ]);
    }

    // -----------------------------------------------------------------------
    // Updates
    // -----------------------------------------------------------------------

    /**
     * Read versions before the upgrader overwrites them.
     *
     * `upgrader_process_complete` fires after the files are replaced, so the
     * old version is gone by then: "Plugin updated" without a from/to is most
     * of the value of the row missing.
     *
     * @since 2.22.0
     *
     * @param bool|\WP_Error $response Upgrader response so far.
     * @param array          $hook_extra Upgrade context.
     * @return bool|\WP_Error Unchanged.
     */
    public static function remember_versions($response, $hook_extra = []) {
        $plugin = (string) ($hook_extra['plugin'] ?? '');

        if ('' !== $plugin && function_exists('get_plugin_data')) {
            $file = WP_PLUGIN_DIR . '/' . $plugin;

            if (file_exists($file)) {
                $data = get_plugin_data($file, false, false);
                self::$versions_before[$plugin] = (string) ($data['Version'] ?? '');
            }
        }

        return $response;
    }

    /**
     * Something was updated.
     *
     * @since 2.22.0
     *
     * @param object $upgrader   Upgrader instance.
     * @param array  $hook_extra What was upgraded.
     * @return void
     */
    public static function on_upgrade($upgrader, $hook_extra = []): void {
        $type = (string) ($hook_extra['type'] ?? '');

        if ('core' === $type) {
            self::queue([
                'event_type' => self::CORE_UPDATED,
                'severity'   => 'info',
                'summary'    => sprintf(
                    /* translators: %s: WordPress version. */
                    __('WordPress updated to %s', 'thinkrank'),
                    (string) get_bloginfo('version')
                ),
            ]);

            return;
        }

        $items = (array) ($hook_extra['plugins'] ?? $hook_extra['themes'] ?? []);

        if (empty($items) && !empty($hook_extra['plugin'])) {
            $items = [(string) $hook_extra['plugin']];
        }

        foreach ($items as $item) {
            $item = (string) $item;

            if ('theme' === $type) {
                self::queue([
                    'event_type' => self::THEME_UPDATED,
                    'severity'   => 'info',
                    'summary'    => sprintf(
                        /* translators: %s: theme name. */
                        __('Theme updated: %s', 'thinkrank'),
                        $item
                    ),
                    'meta'       => ['theme' => $item],
                ], 'theme:' . $item);

                continue;
            }

            self::queue([
                'event_type' => self::PLUGIN_UPDATED,
                'severity'   => 'info',
                'summary'    => self::plugin_update_summary($item),
                'meta'       => [
                    'plugin' => $item,
                    'from'   => self::$versions_before[$item] ?? '',
                    'to'     => self::plugin_version($item),
                ],
            ], 'plugin:' . $item);
        }
    }

    /**
     * "Plugin updated: Name 1.2 -> 1.3", or as much of it as is known.
     *
     * @since 2.22.0
     *
     * @param string $plugin Plugin file.
     * @return string
     */
    public static function plugin_update_summary(string $plugin): string {
        $name = self::plugin_name($plugin);
        $from = self::$versions_before[$plugin] ?? '';
        $to   = self::plugin_version($plugin);

        if ('' !== $from && '' !== $to && $from !== $to) {
            return sprintf(
                /* translators: 1: plugin name, 2: previous version, 3: new version. */
                __('Plugin updated: %1$s %2$s to %3$s', 'thinkrank'),
                $name,
                $from,
                $to
            );
        }

        return sprintf(
            /* translators: %s: plugin name. */
            __('Plugin updated: %s', 'thinkrank'),
            $name
        );
    }

    /**
     * A plugin was switched on.
     *
     * @since 2.22.0
     *
     * @param string $plugin Plugin file.
     * @return void
     */
    public static function on_plugin_activated($plugin): void {
        $plugin = (string) $plugin;

        self::queue([
            'event_type' => self::PLUGIN_ACTIVATED,
            'severity'   => self::plugin_severity($plugin),
            'summary'    => sprintf(
                /* translators: %s: plugin name. */
                __('Plugin activated: %s', 'thinkrank'),
                self::plugin_name($plugin)
            ),
            'meta'       => ['plugin' => $plugin],
        ], 'activated:' . $plugin);
    }

    /**
     * A plugin was switched off.
     *
     * @since 2.22.0
     *
     * @param string $plugin Plugin file.
     * @return void
     */
    public static function on_plugin_deactivated($plugin): void {
        $plugin = (string) $plugin;

        self::queue([
            'event_type' => self::PLUGIN_DEACTIVATED,
            'severity'   => self::plugin_severity($plugin),
            'summary'    => sprintf(
                /* translators: %s: plugin name. */
                __('Plugin deactivated: %s', 'thinkrank'),
                self::plugin_name($plugin)
            ),
            'meta'       => ['plugin' => $plugin],
        ], 'deactivated:' . $plugin);
    }

    /**
     * Whether a plugin's activation state is loud enough to be a warning.
     *
     * @since 2.22.0
     *
     * @param string $plugin Plugin file.
     * @return string Severity.
     */
    public static function plugin_severity(string $plugin): string {
        $haystack = strtolower($plugin . ' ' . self::plugin_name($plugin));

        foreach (self::LOUD_PLUGIN_FRAGMENTS as $fragment) {
            if (false !== strpos($haystack, $fragment)) {
                return 'warning';
            }
        }

        return 'info';
    }

    /**
     * The theme was switched.
     *
     * @since 2.22.0
     *
     * @param string $new_name Theme name.
     * @return void
     */
    public static function on_theme_switched($new_name): void {
        self::queue([
            'event_type' => self::THEME_SWITCHED,
            // A theme change rewrites the markup every check reads: headings,
            // schema, image sizes, internal links.
            'severity'   => 'warning',
            'summary'    => sprintf(
                /* translators: %s: theme name. */
                __('Theme switched to %s', 'thinkrank'),
                (string) $new_name
            ),
            'meta'       => ['theme' => (string) $new_name],
        ]);
    }

    // -----------------------------------------------------------------------
    // Content
    // -----------------------------------------------------------------------

    /**
     * A post changed status.
     *
     * @since 2.22.0
     *
     * @param string   $new_status New status.
     * @param string   $old_status Old status.
     * @param \WP_Post $post       The post.
     * @return void
     */
    public static function on_post_status($new_status, $old_status, $post): void {
        if ($new_status === $old_status || !self::records_post($post)) {
            return;
        }

        $was_public  = 'publish' === $old_status;
        $is_public   = 'publish' === $new_status;

        if (!$was_public && !$is_public) {
            // Draft to pending and the like: nothing a crawler ever saw.
            return;
        }

        self::queue([
            'event_type'  => self::POST_STATUS,
            'severity'    => $was_public && !$is_public ? 'warning' : 'info',
            'object_type' => (string) $post->post_type,
            'object_id'   => (int) $post->ID,
            'summary'     => $is_public
                ? sprintf(
                    /* translators: %s: post title. */
                    __('Published: %s', 'thinkrank'),
                    self::title($post)
                )
                : sprintf(
                    /* translators: 1: post title, 2: new status. */
                    __('Unpublished: %1$s (now %2$s)', 'thinkrank'),
                    self::title($post),
                    (string) $new_status
                ),
            'meta'        => ['from' => (string) $old_status, 'to' => (string) $new_status],
        ], 'status:' . $post->ID);
    }

    /**
     * A published post was deleted outright, so its URL now answers 404.
     *
     * Going through the trash is already recorded as an unpublish; this is
     * the path that skips it (a forced REST or WP-CLI delete, or
     * EMPTY_TRASH_DAYS set to 0), where wp_delete_post() fires no status
     * transition. A post deleted from the trash was unpublished then.
     *
     * @since 2.22.0
     *
     * @param int           $post_id Post id.
     * @param \WP_Post|null $post    The post, from WordPress 5.5.
     * @return void
     */
    public static function on_post_deleted($post_id, $post = null): void {
        // Whatever its status, the meta rows that go with it next are not
        // edits to record.
        self::$deleting[(int) $post_id] = true;

        if (!is_object($post)) {
            $post = get_post((int) $post_id);
        }

        if (!self::records_post($post) || 'publish' !== (string) ($post->post_status ?? '')) {
            return;
        }

        self::queue([
            'event_type'  => self::POST_STATUS,
            'severity'    => 'warning',
            'object_type' => (string) $post->post_type,
            'object_id'   => (int) $post->ID,
            'summary'     => sprintf(
                /* translators: %s: post title. */
                __('Deleted while published: %s', 'thinkrank'),
                self::title($post)
            ),
            'meta'        => ['from' => 'publish', 'to' => 'deleted'],
        ], 'status:' . $post->ID);
    }

    /**
     * A published post's slug changed, so its URL did.
     *
     * @since 2.22.0
     *
     * @param int      $post_id Post id.
     * @param \WP_Post $after   Post after the update.
     * @param \WP_Post $before  Post before the update.
     * @return void
     */
    public static function on_post_updated($post_id, $after, $before): void {
        if (!is_object($after) || !is_object($before) || !self::records_post($after)) {
            return;
        }

        // Only a URL that was live before can change. A draft gets its first
        // slug when it is published, and that is a new URL, not a moved one.
        if ($after->post_name === $before->post_name
            || 'publish' !== $after->post_status
            || 'publish' !== $before->post_status
            || '' === (string) $before->post_name) {
            return;
        }

        self::queue([
            'event_type'  => self::POST_SLUG,
            // A changed slug on a published post is a dead URL unless something
            // redirects it.
            'severity'    => 'warning',
            'object_type' => (string) $after->post_type,
            'object_id'   => (int) $post_id,
            'summary'     => sprintf(
                /* translators: 1: post title, 2: old slug, 3: new slug. */
                __('URL changed: %1$s (%2$s to %3$s)', 'thinkrank'),
                self::title($after),
                (string) $before->post_name,
                (string) $after->post_name
            ),
            'meta'        => ['from' => (string) $before->post_name, 'to' => (string) $after->post_name],
        ], 'slug:' . $post_id);
    }

    /**
     * An SEO field on a post changed.
     *
     * Batched into one row per request: a bulk edit over 42 posts fires this
     * hook 42 times or more, and 42 near-identical lines would make the
     * Timeline unreadable exactly when it is most needed.
     *
     * @since 2.22.0
     *
     * @param int|int[] $meta_id    Meta row id, or the ids deleted_post_meta passes.
     * @param int       $post_id    Post id.
     * @param string    $meta_key   Meta key.
     * @param mixed     $meta_value Meta value written or removed.
     * @return void
     */
    public static function on_post_meta($meta_id, $post_id, $meta_key, $meta_value = null): void {
        if (!in_array((string) $meta_key, self::WATCHED_POST_META, true)) {
            return;
        }

        // The post itself is going, and on_post_deleted() has recorded that.
        if (isset(self::$deleting[(int) $post_id])) {
            return;
        }

        $post = get_post((int) $post_id);

        // Only a published post's fields are anything a search engine has
        // seen. A draft saved ten times while it is written is not ten
        // changes, the same rule on_post_status() applies to draft to pending.
        if (!self::records_post($post) || 'publish' !== (string) ($post->post_status ?? '')) {
            return;
        }

        $key = 'seo_fields';
        $existing = self::$queue[$key] ?? null;

        $post_ids = $existing['meta']['post_ids'] ?? [];
        $fields   = $existing['meta']['fields'] ?? [];

        $post_ids[(int) $post_id] = true;
        $fields[(string) $meta_key] = true;

        self::$queue[$key] = [
            'event_type'  => self::POST_SEO_FIELDS,
            'severity'    => 'info',
            'object_type' => count($post_ids) === 1 ? (string) $post->post_type : '',
            'object_id'   => count($post_ids) === 1 ? (int) $post_id : 0,
            'summary'     => self::seo_fields_summary(array_keys($post_ids), array_keys($fields), $post),
            'meta'        => ['post_ids' => $post_ids, 'fields' => $fields],
        ];
    }

    /**
     * An SEO field was added to a post for the first time.
     *
     * The editor writes every field on a post's first save, most of them
     * empty, which read as "3 SEO fields changed" on a post nobody had given
     * an SEO field. A field added with no value changed nothing a search
     * engine sees, so only a first value that holds something is recorded.
     * A switch first written as off is the same as no switch.
     * (Core fires updated_post_meta only when a value actually moves.)
     *
     * @since 2.22.0
     *
     * @param int    $meta_id    Meta row id.
     * @param int    $post_id    Post id.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value written.
     * @return void
     */
    public static function on_post_meta_added($meta_id, $post_id, $meta_key, $meta_value = null): void {
        if (self::is_empty_meta($meta_value)) {
            return;
        }

        if (in_array((string) $meta_key, self::SWITCH_POST_META, true) && !self::switch_is_on($meta_value)) {
            return;
        }

        self::on_post_meta($meta_id, $post_id, $meta_key, $meta_value);
    }

    /**
     * Whether a meta value holds nothing: '', null, an empty array, or an
     * empty array stored as JSON or serialized.
     *
     * @since 2.22.0
     *
     * @param mixed $value Meta value.
     * @return bool
     */
    public static function is_empty_meta($value): bool {
        if (null === $value || [] === $value) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(trim($value), ['', '[]', '{}', 'a:0:{}'], true);
    }

    /**
     * Whether a switch value means on, read the way the frontend reads it:
     * SEO_Manager casts the stored value to bool.
     *
     * @since 2.22.0
     *
     * @param mixed $value Meta value.
     * @return bool
     */
    public static function switch_is_on($value): bool {
        return is_string($value) ? (bool) trim($value) : (bool) $value;
    }

    /**
     * "SEO title changed on 42 posts", or the single-post version.
     *
     * @since 2.22.0
     *
     * @param array<int,int>    $post_ids Posts touched.
     * @param array<int,string> $fields   Meta keys touched.
     * @param \WP_Post|null     $post     The post, when there is only one.
     * @return string
     */
    public static function seo_fields_summary(array $post_ids, array $fields, $post = null): string {
        $count = count($post_ids);

        if (1 === $count && is_object($post)) {
            return sprintf(
                /* translators: 1: number of SEO fields, 2: post title. */
                _n('%1$d SEO field changed on %2$s', '%1$d SEO fields changed on %2$s', count($fields), 'thinkrank'),
                count($fields),
                self::title($post)
            );
        }

        return sprintf(
            /* translators: %d: number of posts. */
            _n('SEO fields changed on %d post', 'SEO fields changed on %d posts', $count, 'thinkrank'),
            $count
        );
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    /**
     * A ThinkRank settings section was saved.
     *
     * @since 2.22.0
     *
     * Only the keys whose stored value moved are recorded. A screen sends its
     * whole section on every save, so recording the payload turned a single
     * Breadcrumbs toggle into "62 settings saved", and typed it robots.txt
     * because `robots_txt_enabled` rides along in every Site Identity save.
     *
     * @param string     $manager_type Settings category.
     * @param array      $settings     The sanitized settings written.
     * @param string     $context_type Context type.
     * @param int|null   $context_id   Context ID.
     * @param array|null $previous     Settings in effect before the write
     *                                 (stored over the defaults), null when
     *                                 the caller did not pass them.
     * @return void
     */
    public static function on_settings_saved($manager_type, $settings = [], $context_type = '', $context_id = null, $previous = null): void {
        // Machinery, not a change anyone made. The sitemap generator rewrites
        // its whole settings blob on its cron run, which showed up on the
        // screen as "42 settings saved in sitemap" at 12:50 AM with no human
        // anywhere near it — the exact noise a Timeline has to stay clear of to
        // be worth reading. A WP-CLI or agent write still has an actor and is
        // still recorded, because somebody did ask for those.
        if (self::is_unattended()) {
            return;
        }

        $keys = self::redact(self::changed_setting_keys((array) $settings, $previous));
        $keys = array_values(array_diff($keys, self::GENERATED_SETTING_KEYS));

        if (empty($keys)) {
            return;
        }

        $type = self::settings_event_type((string) $manager_type, $keys);

        self::queue([
            'event_type' => $type,
            'severity'   => self::ROBOTS_TXT === $type || self::LLMS_TXT === $type ? 'warning' : 'info',
            'summary'    => sprintf(
                /* translators: 1: settings section, 2: number of settings. */
                _n('%2$d setting saved in %1$s', '%2$d settings saved in %1$s', count($keys), 'thinkrank'),
                self::section_label((string) $manager_type),
                count($keys)
            ),
            // Names, never values. See the class docblock.
            'meta'       => ['section' => (string) $manager_type, 'keys' => $keys],
        ], 'settings:' . $manager_type);
    }

    /**
     * A settings section as people read it: `site_identity` is "Site Identity".
     *
     * @since 2.22.0
     *
     * @param string $section Settings category.
     * @return string
     */
    public static function section_label(string $section): string {
        $labels = self::section_labels();

        return $labels[$section] ?? ucwords(str_replace(['_', '-'], ' ', $section));
    }

    /**
     * Every settings category's name, as its screen calls it.
     *
     * The categories are internal names, several of them class names
     * ("schema_management_system"), and spelling them out gave "Llms Txt" and
     * "Image Seo". Keep in sync with SECTION_LABELS in TimelineSection.js,
     * which names the section in a row's details; a test pins the two.
     *
     * @since 2.22.0
     *
     * @return array<string,string>
     */
    public static function section_labels(): array {
        return [
            'site_identity'                  => __('Site Identity', 'thinkrank'),
            'sitemap'                        => __('Sitemap', 'thinkrank'),
            'social_meta'                    => __('Social Media', 'thinkrank'),
            'image_seo'                      => __('Image SEO', 'thinkrank'),
            'external_links'                 => __('External Links', 'thinkrank'),
            'llms_txt'                       => __('llms.txt', 'thinkrank'),
            'schema_management_system'       => __('Schema', 'thinkrank'),
            'seo_settings'                   => __('SEO Settings', 'thinkrank'),
            'ai_content_analyzer'            => __('Content Analysis', 'thinkrank'),
            'content_optimization_manager'   => __('Content Optimization', 'thinkrank'),
            'performance_monitoring_manager' => __('Performance', 'thinkrank'),
        ];
    }

    /**
     * The keys of a settings save whose stored value changed.
     *
     * Compared after comparable_value(), so `false` and the stored `''` are
     * the same value, and so are a default `29` and a sanitized `29.0`.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $settings The settings written.
     * @param array|null          $previous Stored settings before the write, or
     *                                      null to treat every key as changed.
     * @return array<int,string>
     */
    public static function changed_setting_keys(array $settings, $previous): array {
        if (!is_array($previous)) {
            return array_keys($settings);
        }

        $changed = [];

        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $previous)
                || self::comparable_value($previous[$key]) !== self::comparable_value($value)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * A setting value reduced to what it means once stored.
     *
     * A scalar is cast the way the settings table stores it, so `true` and
     * `'1'`, `false` and `''`, `29` and `29.0` compare equal. The same applies
     * inside arrays, which `maybe_serialize()` would otherwise tell apart by
     * type: a default weight of `29` against a sanitized `29.0` read as a
     * change on a save that moved nothing.
     *
     * @since 2.22.0
     *
     * @param mixed $value Setting value.
     * @return string
     */
    public static function comparable_value($value): string {
        $normalize = static function ($item) use (&$normalize) {
            if (is_array($item)) {
                return array_map($normalize, $item);
            }

            if (is_bool($item)) {
                return $item ? '1' : '';
            }

            if (null === $item || is_scalar($item)) {
                return (string) $item;
            }

            return $item;
        };

        $normalized = $normalize($value);

        return is_array($normalized) || is_object($normalized)
            ? (string) maybe_serialize($normalized)
            : (string) $normalized;
    }

    /**
     * Which event a settings save counts as.
     *
     * robots.txt and llms.txt get their own type because they are the two
     * settings whose change is directly a crawl-behaviour change, and the first
     * thing to look at after an indexing drop.
     *
     * @since 2.22.0
     *
     * @param string            $manager_type Settings category.
     * @param array<int,string> $keys         Keys that changed.
     * @return string
     */
    public static function settings_event_type(string $manager_type, array $keys): string {
        foreach ($keys as $key) {
            if (0 === strpos($key, 'robots_txt')) {
                return self::ROBOTS_TXT;
            }

            if (0 === strpos($key, 'llms_txt') || 0 === strpos($key, 'llms')) {
                return self::LLMS_TXT;
            }
        }

        return self::SETTINGS_CHANGED;
    }

    /**
     * Drop any key whose name says it holds a secret.
     *
     * Only names are ever recorded, so this is belt and braces: it exists so
     * that a future caller passing a key list by hand cannot put `openai_api_key`
     * in a log that anyone who can open the screen can read.
     *
     * @since 2.22.0
     *
     * @param array<int,mixed> $keys Setting keys.
     * @return array<int,string>
     */
    public static function redact(array $keys): array {
        $safe = [];

        foreach ($keys as $key) {
            if (!is_string($key) || '' === $key) {
                continue;
            }

            if (self::looks_secret($key)) {
                continue;
            }

            $safe[] = $key;
        }

        return array_values(array_unique($safe));
    }

    /**
     * Whether a setting key's name says it holds a secret.
     *
     * Matched on whole underscore-separated segments, plus a suffix fallback
     * for the run-together spellings, rather than anywhere in the string. A
     * plain substring match redacted `max_tokens`, which is a model setting and
     * not a credential, and losing real keys from the record is a cost with no
     * matching benefit.
     *
     * @since 2.22.0
     *
     * @param string $key Setting key.
     * @return bool
     */
    public static function looks_secret(string $key): bool {
        $lower    = strtolower($key);
        $segments = preg_split('/[^a-z0-9]+/', $lower) ?: [];

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (in_array($fragment, $segments, true)) {
                return true;
            }

            if (substr($lower, -strlen($fragment)) === $fragment) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------------

    /**
     * Queue an event for this request.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $event Event fields.
     * @param string|null         $key   Collapse key; identical keys overwrite.
     * @return void
     */
    public static function queue(array $event, ?string $key = null): void {
        if (!self::enabled()) {
            return;
        }

        $key = $key ?? ($event['event_type'] ?? '') . ':' . count(self::$queue);

        self::$queue[$key] = $event;
    }

    /**
     * Write the queue.
     *
     * @since 2.22.0
     *
     * @return int Rows written.
     */
    public static function flush(): int {
        if (empty(self::$queue)) {
            return 0;
        }

        $queue       = self::$queue;
        self::$queue = [];
        $written     = 0;

        $actor = self::actor();

        foreach ($queue as $event) {
            if ('' === ($event['actor'] ?? '')) {
                $event['actor'] = $actor;
            }

            /**
             * Filters a Timeline event before it is written.
             *
             * Returning an empty array drops it.
             *
             * @since 2.22.0
             *
             * @param array<string,mixed> $event The event.
             */
            $event = (array) apply_filters('thinkrank_timeline_event', $event);

            if (empty($event) || Event_Repository::insert($event) <= 0) {
                continue;
            }

            $written++;
        }

        return $written;
    }

    /**
     * Whether recording is on.
     *
     * @since 2.22.0
     *
     * @return bool
     */
    public static function enabled(): bool {
        /**
         * Filters whether the SEO Timeline records anything.
         *
         * @since 2.22.0
         *
         * @param bool $enabled Whether to record.
         */
        return (bool) apply_filters('thinkrank_timeline_enabled', true);
    }

    /**
     * Whether this request has nobody behind it.
     *
     * @since 2.22.0
     *
     * @return bool
     */
    public static function is_unattended(): bool {
        $actor = self::actor();

        return '' === $actor || 'cron' === $actor;
    }

    /**
     * Who a change is attributed to, as the filters see it.
     *
     * @since 2.22.0
     *
     * @return string
     */
    public static function current_actor(): string {
        return self::actor();
    }

    /**
     * Forget anything queued, for tests.
     *
     * @since 2.22.0
     *
     * @return void
     */
    public static function reset(): void {
        self::$queue           = [];
        self::$versions_before = [];
        self::$deleting        = [];
    }

    /**
     * What is queued, for tests.
     *
     * @since 2.22.0
     *
     * @return array<string,array<string,mixed>>
     */
    public static function queued(): array {
        return self::$queue;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Whether a post is one whose changes matter to search.
     *
     * @since 2.22.0
     *
     * @param mixed $post Post object.
     * @return bool
     */
    private static function records_post($post): bool {
        if (!is_object($post) || empty($post->post_type)) {
            return false;
        }

        // Revisions, autosaves and auto-drafts are editing noise, not changes.
        if (in_array((string) $post->post_type, ['revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache'], true)) {
            return false;
        }

        if ('auto-draft' === ($post->post_status ?? '')) {
            return false;
        }

        $object = get_post_type_object((string) $post->post_type);

        return !$object || !empty($object->public);
    }

    /**
     * A post's title, clipped so one long title cannot fill the summary.
     *
     * @since 2.22.0
     *
     * @param mixed $post Post object.
     * @return string
     */
    private static function title($post): string {
        $title = is_object($post) ? (string) ($post->post_title ?? '') : '';

        if ('' === $title) {
            return __('(no title)', 'thinkrank');
        }

        return Event_Repository::clip($title, 80);
    }

    /**
     * Who made the change.
     *
     * @since 2.22.0
     *
     * @return string
     */
    private static function actor(): string {
        if (defined('WP_CLI') && WP_CLI) {
            return 'wp-cli';
        }

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        if ($user && !empty($user->ID)) {
            return (string) ($user->display_name ?: $user->user_login);
        }

        $actor = defined('DOING_CRON') && DOING_CRON ? 'cron' : '';

        /**
         * Filters who a Timeline event is attributed to.
         *
         * '' and 'cron' both mean "nobody asked for this", and a settings save
         * with either is not recorded.
         *
         * @since 2.22.0
         *
         * @param string $actor Resolved actor.
         */
        return (string) apply_filters('thinkrank_timeline_actor', $actor);
    }

    /**
     * A plugin's display name, falling back to its file.
     *
     * @since 2.22.0
     *
     * @param string $plugin Plugin file.
     * @return string
     */
    private static function plugin_name(string $plugin): string {
        if (!function_exists('get_plugin_data')) {
            return $plugin;
        }

        $file = WP_PLUGIN_DIR . '/' . $plugin;

        if (!file_exists($file)) {
            return $plugin;
        }

        $data = get_plugin_data($file, false, false);

        return (string) ($data['Name'] ?? '') ?: $plugin;
    }

    /**
     * A plugin's current version.
     *
     * @since 2.22.0
     *
     * @param string $plugin Plugin file.
     * @return string
     */
    private static function plugin_version(string $plugin): string {
        if (!function_exists('get_plugin_data')) {
            return '';
        }

        $file = WP_PLUGIN_DIR . '/' . $plugin;

        if (!file_exists($file)) {
            return '';
        }

        $data = get_plugin_data($file, false, false);

        return (string) ($data['Version'] ?? '');
    }
}
